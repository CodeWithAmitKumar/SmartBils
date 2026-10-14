<?php
// POS finalize — atomic, server-recomputed, stock-locked, idempotent.
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('bill.create');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId    = current_shop_id();
$uid       = (int)current_user()['id'];
$custId    = (int)($_POST['customer_id'] ?? 0) ?: null;
$custMobile= trim((string)($_POST['customer_mobile'] ?? ''));
$custName  = trim((string)($_POST['customer_name'] ?? ''));
$orderDisc = max(0, (float)($_POST['order_discount'] ?? 0));
$taxPct    = max(0, min(100, (float)($_POST['tax_percent'] ?? 0)));
$amtPaid   = max(0, (float)($_POST['amount_paid'] ?? 0));
$method    = in_array($_POST['pay_method'] ?? '', ['cash','upi','other'], true) ? $_POST['pay_method'] : 'cash';
$payRef    = trim((string)($_POST['pay_reference'] ?? '')) ?: null;
$dueDate   = trim((string)($_POST['due_date'] ?? '')) ?: null;
$dueNote   = trim((string)($_POST['due_note'] ?? '')) ?: null;
if ($dueDate && !DateTime::createFromFormat('Y-m-d', $dueDate)) $dueDate = null;
$token     = (string)($_POST['txn_token'] ?? '');
$itemsIn   = json_decode((string)($_POST['items_json'] ?? '[]'), true);

if ($token === '')               { flash('danger', 'Missing idempotency token.'); redirect('client/billing/'); }
if (!is_array($itemsIn) || !$itemsIn) { flash('danger', 'Cart is empty.'); redirect('client/billing/'); }

// Mobile is mandatory. Name is required when the customer doesn't exist yet.
if (strlen($custMobile) < 6)      { flash('danger', 'Customer mobile is required (≥ 6 digits).'); redirect('client/billing/'); }

if ($custId) {
    $cv = db()->prepare('SELECT 1 FROM customers WHERE id = :id AND shop_id = :sid');
    $cv->execute([':id'=>$custId, ':sid'=>$shopId]);
    if (!$cv->fetchColumn()) $custId = null;
}
if (!$custId) {
    // Try to find by mobile first.
    $cx = db()->prepare('SELECT id FROM customers WHERE shop_id = :sid AND mobile = :m ORDER BY id DESC LIMIT 1');
    $cx->execute([':sid'=>$shopId, ':m'=>$custMobile]);
    $custId = (int)$cx->fetchColumn() ?: null;
}
if (!$custId) {
    // Create — name required at this point.
    if ($custName === '') { flash('danger', 'Customer name is required for a new customer.'); redirect('client/billing/'); }
    db()->prepare('INSERT INTO customers (shop_id, name, mobile, is_active) VALUES (:sid, :n, :m, 1)')
        ->execute([':sid'=>$shopId, ':n'=>$custName, ':m'=>$custMobile]);
    $custId = (int)db()->lastInsertId();
    audit('customer.create', 'customer', $custId, ['from'=>'pos'], $shopId);
}

try {
    db()->beginTransaction();

    // 1. Lock product rows + revalidate
    $items = [];
    $subtotal = 0.0;
    $lineDiscSum = 0.0;
    foreach ($itemsIn as $it) {
        $pid   = (int)($it['product_id'] ?? 0);
        $qty   = (float)($it['qty'] ?? 0);
        $price = (float)($it['unit_price'] ?? 0);
        $disc  = max(0, (float)($it['discount'] ?? 0));
        if ($pid <= 0 || $qty <= 0 || $price < 0) throw new RuntimeException('Invalid line item.');

        $pq = db()->prepare('SELECT id, name, sku, unit, selling_price, current_stock, is_available
                             FROM products WHERE id = :id AND shop_id = :sid FOR UPDATE');
        $pq->execute([':id'=>$pid, ':sid'=>$shopId]);
        $prod = $pq->fetch();
        if (!$prod)                              throw new RuntimeException("Product #{$pid} not found.");
        if (!(int)$prod['is_available'])         throw new RuntimeException("“{$prod['name']}” is marked unavailable.");
        if ((float)$prod['current_stock'] < $qty) throw new RuntimeException("Insufficient stock for “{$prod['name']}” — have " . (float)$prod['current_stock'] . ", need {$qty}.");

        $lineGross = round($qty * $price, 2);
        $disc = min($disc, $lineGross);
        $line = max(0, $lineGross - $disc);
        $subtotal   += $lineGross;
        $lineDiscSum += $disc;

        $items[] = [
            'product_id' => $pid, 'name' => $prod['name'], 'sku' => $prod['sku'],
            'unit' => $prod['unit'], 'qty' => $qty,
            'unit_price' => $price, 'discount' => $disc, 'line_total' => $line,
        ];
    }

    $afterLine   = max(0, $subtotal - $lineDiscSum);
    $orderDisc   = min($orderDisc, $afterLine);
    $base        = $afterLine - $orderDisc;
    $tax         = round(($base * $taxPct) / 100, 2);
    $grandTotal  = round($base + $tax, 2);

    // Prevent overpayment at the POS stage (that would be a prepayment).
    if ($amtPaid > $grandTotal + 0.01) throw new RuntimeException('Amount paid cannot exceed grand total.');

    // 2. Insert sale header (unique txn_token stops duplicates)
    $saleDate = date('Y-m-d H:i:s');
    $tmpInv   = 'TMP-' . $token;  // placeholder — replaced right after we know the id
    db()->prepare(
        'INSERT INTO sales (shop_id, customer_id, invoice_no, sale_date,
                            subtotal, discount, tax, grand_total, status, txn_token, created_by)
         VALUES (:sid, :cid, :inv, :sd, :sub, :dis, :tax, :tot, :st, :tk, :by)'
    )->execute([
        ':sid'=>$shopId, ':cid'=>$custId, ':inv'=>$tmpInv, ':sd'=>$saleDate,
        ':sub'=>$subtotal, ':dis'=>$lineDiscSum + $orderDisc, ':tax'=>$tax, ':tot'=>$grandTotal,
        ':st'=>'unpaid', ':tk'=>$token, ':by'=>$uid,
    ]);
    $saleId = (int)db()->lastInsertId();

    $invoiceNo = sprintf('INV-%s-%06d', date('Ymd'), $saleId);
    db()->prepare('UPDATE sales SET invoice_no = :inv WHERE id = :id AND shop_id = :sid')
        ->execute([':inv'=>$invoiceNo, ':id'=>$saleId, ':sid'=>$shopId]);

    // 3. Insert items + stock movement + decrement stock
    $ii = db()->prepare(
        'INSERT INTO sale_items (sale_id, product_id, product_name, sku_snapshot, qty, unit_price, discount, tax, line_total)
         VALUES (:sid, :pid, :pn, :sku, :q, :up, :d, 0, :lt)');
    $mv = db()->prepare(
        "INSERT INTO stock_movements (shop_id, product_id, type, qty_change, ref_type, ref_id, note, created_by)
         VALUES (:sid, :pid, 'sale', :q, 'sale', :rid, :nt, :by)");
    $ps = db()->prepare('UPDATE products SET current_stock = current_stock - :q WHERE id = :id AND shop_id = :sid');

    foreach ($items as $it) {
        $ii->execute([
            ':sid'=>$saleId, ':pid'=>$it['product_id'], ':pn'=>$it['name'], ':sku'=>$it['sku'],
            ':q'=>$it['qty'], ':up'=>$it['unit_price'], ':d'=>$it['discount'], ':lt'=>$it['line_total'],
        ]);
        $mv->execute([
            ':sid'=>$shopId, ':pid'=>$it['product_id'], ':q'=>-$it['qty'],
            ':rid'=>$saleId, ':nt'=>'Sale ' . $invoiceNo, ':by'=>$uid,
        ]);
        $ps->execute([':q'=>$it['qty'], ':id'=>$it['product_id'], ':sid'=>$shopId]);
    }

    // 4. Payment + allocation (if any paid now)
    if ($amtPaid > 0) {
        $payStatus = $method === 'upi' ? 'pending' : 'verified';
        db()->prepare(
            'INSERT INTO payments (shop_id, customer_id, method, amount, reference, status, collected_by, paid_at, txn_token)
             VALUES (:sid, :cid, :m, :amt, :r, :st, :by, NOW(), :tk)'
        )->execute([
            ':sid'=>$shopId, ':cid'=>$custId, ':m'=>$method, ':amt'=>$amtPaid, ':r'=>$payRef,
            ':st'=>$payStatus, ':by'=>$uid, ':tk'=>$token . '-pay',
        ]);
        $payId = (int)db()->lastInsertId();

        db()->prepare('INSERT INTO payment_allocations (payment_id, sale_id, allocated_amount)
                       VALUES (:pid, :sid, :amt)')
            ->execute([':pid'=>$payId, ':sid'=>$saleId, ':amt'=>$amtPaid]);
    }

    // 5. Derive status from allocated verified payments
    $derived = $amtPaid <= 0 ? 'unpaid'
             : ($amtPaid + 0.01 >= $grandTotal
                ? ($method === 'upi' ? 'partial' : 'paid')     // UPI stays partial until verified
                : 'partial');
    // Keep due_date only when there's a balance (partial/unpaid).
    $saveDue  = in_array($derived, ['unpaid', 'partial'], true) ? $dueDate : null;
    $saveNote = $saveDue ? $dueNote : null;
    db()->prepare('UPDATE sales SET status = :st, due_date = :dd, due_note = :dn WHERE id = :id AND shop_id = :sid')
        ->execute([':st'=>$derived, ':dd'=>$saveDue, ':dn'=>$saveNote, ':id'=>$saleId, ':sid'=>$shopId]);

    db()->commit();
    audit('sale.create', 'sale', $saleId, ['total'=>$grandTotal, 'paid'=>$amtPaid, 'status'=>$derived], $shopId);

    // Rotate token for next bill
    unset($_SESSION['pos_token']);

    flash('success', 'Bill saved — ' . $invoiceNo . ' · total ' . money($grandTotal) . '.');
    redirect('receipts/invoice.php?id=' . $saleId);

} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    // Idempotency duplicate → redirect to existing sale
    if (str_contains($e->getMessage(), 'uq_sales_token')) {
        $q = db()->prepare('SELECT id FROM sales WHERE txn_token = :t');
        $q->execute([':t'=>$token]);
        $existing = (int)$q->fetchColumn();
        if ($existing) {
            unset($_SESSION['pos_token']);
            flash('warning', 'This bill was already saved — redirected to the existing record.');
            redirect('receipts/invoice.php?id=' . $existing);
        }
    }
    flash('danger', 'Save failed: ' . $e->getMessage());
    redirect('client/billing/');
}

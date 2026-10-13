<?php
// Save a return. Original sale is NEVER rewritten. We create return + items,
// restock sellable items, credit outstanding first, remainder goes to a refund.
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('return.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId = current_shop_id();
$uid    = (int)current_user()['id'];
$saleId = (int)($_POST['sale_id'] ?? 0);
$token  = (string)($_POST['txn_token'] ?? '');
$reason = trim((string)($_POST['reason'] ?? ''));
$qtyIn  = (array)($_POST['qty']  ?? []);
$condIn = (array)($_POST['cond'] ?? []);
$refundMethod = in_array($_POST['refund_method'] ?? '', ['cash','upi','bank','other'], true) ? $_POST['refund_method'] : 'cash';
$refundRef    = trim((string)($_POST['refund_reference'] ?? '')) ?: null;

if ($token === '')  { flash('danger', 'Missing idempotency token.'); redirect('client/returns/'); }
if ($saleId <= 0)   { flash('danger', 'Invoice required.'); redirect('client/returns/'); }
if ($reason === '') { flash('danger', 'Return reason is required.'); redirect('client/returns/new.php?inv=' . $saleId); }

try {
    db()->beginTransaction();

    // Lock the sale
    $sq = db()->prepare('SELECT * FROM sales WHERE id = :id AND shop_id = :sid FOR UPDATE');
    $sq->execute([':id' => $saleId, ':sid' => $shopId]);
    $sale = $sq->fetch();
    if (!$sale) throw new RuntimeException('Invoice not found.');
    if ($sale['status'] === 'cancelled') throw new RuntimeException('Cannot return against a cancelled invoice.');

    // Collect items + validate
    $pickedItems = []; $returnTotal = 0.0;
    foreach ($qtyIn as $itemId => $qty) {
        $itemId = (int)$itemId;
        $qty    = (float)$qty;
        if ($qty <= 0) continue;

        $iq = db()->prepare('
          SELECT si.*,
            COALESCE((SELECT SUM(qty_returned) FROM sales_return_items WHERE sale_item_id = si.id), 0) AS already_returned
          FROM sale_items si
          WHERE si.id = :id AND si.sale_id = :sid FOR UPDATE');
        $iq->execute([':id' => $itemId, ':sid' => $saleId]);
        $it = $iq->fetch();
        if (!$it) throw new RuntimeException("Item #{$itemId} not on this invoice.");

        $allowed = (float)$it['qty'] - (float)$it['already_returned'];
        if ($qty > $allowed + 0.0009) throw new RuntimeException("“{$it['product_name']}”: return qty {$qty} exceeds returnable {$allowed}.");

        $unit = (float)$it['qty'] > 0 ? ((float)$it['line_total'] / (float)$it['qty']) : 0;
        $amt  = round($qty * $unit, 2);
        $cond = in_array($condIn[$itemId] ?? '', ['sellable','damaged','unavailable'], true) ? $condIn[$itemId] : 'sellable';

        $pickedItems[] = compact('itemId', 'qty', 'unit', 'amt', 'cond') + ['product_id' => (int)$it['product_id'], 'product_name' => $it['product_name']];
        $returnTotal += $amt;
    }
    if (!$pickedItems) throw new RuntimeException('Select at least one item with qty > 0.');

    // Current outstanding (server-side, verified payments only)
    $pq = db()->prepare("SELECT COALESCE(SUM(pa.allocated_amount), 0)
                         FROM payment_allocations pa
                         JOIN payments p ON p.id = pa.payment_id
                         WHERE pa.sale_id = :id AND p.status = 'verified'");
    $pq->execute([':id' => $saleId]);
    $paidVerified = (float)$pq->fetchColumn();
    $outstanding  = max(0, (float)$sale['grand_total'] - $paidVerified);

    $credit = min(round($returnTotal, 2), round($outstanding, 2));
    $refund = round($returnTotal - $credit, 2);

    // Insert return header (invoice_no will be set after we get the id)
    db()->prepare(
      'INSERT INTO sales_returns (shop_id, sale_id, return_no, reason, total_return_amount, credit_to_invoice, cash_refund_amount, created_by, txn_token)
       VALUES (:sid, :sale, :rn, :rsn, :tot, :cr, :rf, :by, :tk)'
    )->execute([
        ':sid'=>$shopId, ':sale'=>$saleId, ':rn'=>'TMP-' . $token, ':rsn'=>$reason,
        ':tot'=>$returnTotal, ':cr'=>$credit, ':rf'=>$refund, ':by'=>$uid, ':tk'=>$token,
    ]);
    $returnId = (int)db()->lastInsertId();
    $returnNo = sprintf('RET-%s-%06d', date('Ymd'), $returnId);
    db()->prepare('UPDATE sales_returns SET return_no = :rn WHERE id = :id AND shop_id = :sid')
        ->execute([':rn'=>$returnNo, ':id'=>$returnId, ':sid'=>$shopId]);

    // Items + stock movements for sellable
    $ii = db()->prepare('INSERT INTO sales_return_items (sales_return_id, sale_item_id, product_id, qty_returned, unit_price, amount, stock_condition)
                         VALUES (:sr, :si, :pid, :q, :up, :amt, :c)');
    $mv = db()->prepare("INSERT INTO stock_movements (shop_id, product_id, type, qty_change, ref_type, ref_id, note, created_by)
                         VALUES (:sid, :pid, 'return', :q, 'sales_return', :rid, :nt, :by)");
    $ps = db()->prepare('UPDATE products SET current_stock = current_stock + :q WHERE id = :id AND shop_id = :sid');
    foreach ($pickedItems as $p) {
        $ii->execute([
            ':sr'=>$returnId, ':si'=>$p['itemId'], ':pid'=>$p['product_id'],
            ':q'=>$p['qty'], ':up'=>$p['unit'], ':amt'=>$p['amt'], ':c'=>$p['cond'],
        ]);
        if ($p['cond'] === 'sellable') {
            $mv->execute([':sid'=>$shopId, ':pid'=>$p['product_id'], ':q'=>$p['qty'],
                          ':rid'=>$returnId, ':nt'=>'Return ' . $returnNo, ':by'=>$uid]);
            $ps->execute([':q'=>$p['qty'], ':id'=>$p['product_id'], ':sid'=>$shopId]);
        }
    }

    // Credit the invoice outstanding via a "payment" with method='other', so derivation queries work unchanged.
    $creditPayId = null;
    if ($credit > 0) {
        db()->prepare(
          "INSERT INTO payments (shop_id, customer_id, method, amount, reference, status, collected_by, paid_at, txn_token, note)
           VALUES (:sid, :cid, 'other', :amt, :r, 'verified', :by, NOW(), :tk, :nt)"
        )->execute([
            ':sid'=>$shopId, ':cid'=>$sale['customer_id'], ':amt'=>$credit,
            ':r'=>$returnNo, ':by'=>$uid, ':tk'=>$token . '-credit',
            ':nt'=>'Return credit ' . $returnNo,
        ]);
        $creditPayId = (int)db()->lastInsertId();
        db()->prepare('INSERT INTO payment_allocations (payment_id, sale_id, allocated_amount)
                       VALUES (:p, :s, :a)')
            ->execute([':p'=>$creditPayId, ':s'=>$saleId, ':a'=>$credit]);
        db()->prepare('UPDATE sales_returns SET credit_payment_id = :p WHERE id = :id')
            ->execute([':p'=>$creditPayId, ':id'=>$returnId]);
    }

    // Cash refund
    if ($refund > 0) {
        db()->prepare('INSERT INTO refunds (shop_id, sales_return_id, method, amount, reference, status, created_by)
                       VALUES (:sid, :sr, :m, :amt, :r, "verified", :by)')
            ->execute([
                ':sid'=>$shopId, ':sr'=>$returnId, ':m'=>$refundMethod,
                ':amt'=>$refund, ':r'=>$refundRef, ':by'=>$uid,
            ]);
    }

    // Recompute invoice status
    $totalReturned = (float)db()->query("SELECT COALESCE(SUM(total_return_amount), 0) FROM sales_returns WHERE sale_id = {$saleId}")->fetchColumn();
    $g = (float)$sale['grand_total'];
    $v = db()->prepare("SELECT COALESCE(SUM(pa.allocated_amount), 0)
                        FROM payment_allocations pa
                        JOIN payments p ON p.id = pa.payment_id
                        WHERE pa.sale_id = :id AND p.status = 'verified'");
    $v->execute([':id'=>$saleId]);
    $vp = (float)$v->fetchColumn();
    if ($totalReturned + 0.01 >= $g) $newStatus = 'returned';
    elseif ($vp + 0.01 >= $g)        $newStatus = 'paid';
    elseif ($vp > 0)                 $newStatus = 'partial';
    else                             $newStatus = 'unpaid';
    db()->prepare('UPDATE sales SET status = :st WHERE id = :id AND shop_id = :sid')
        ->execute([':st'=>$newStatus, ':id'=>$saleId, ':sid'=>$shopId]);

    db()->commit();
    audit('return.create', 'sales_return', $returnId, [
        'sale_id'=>$saleId, 'total'=>$returnTotal, 'credit'=>$credit, 'refund'=>$refund,
    ], $shopId);
    unset($_SESSION['return_token']);

    flash('success', 'Return ' . $returnNo . ' saved — credit ' . money($credit) . ', cash refund ' . money($refund) . '.');
    redirect('client/returns/view.php?id=' . $returnId);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    if (str_contains($e->getMessage(), 'uq_sr_token')) {
        $q = db()->prepare('SELECT id FROM sales_returns WHERE txn_token = :t');
        $q->execute([':t'=>$token]);
        $existing = (int)$q->fetchColumn();
        if ($existing) {
            unset($_SESSION['return_token']);
            flash('warning', 'This return was already saved — redirected to the existing record.');
            redirect('client/returns/view.php?id=' . $existing);
        }
    }
    flash('danger', $e->getMessage());
    redirect('client/returns/new.php?inv=' . $saleId);
}

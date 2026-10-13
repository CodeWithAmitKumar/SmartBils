<?php
// Record an independent due collection — one payment allocated to one or more existing invoices.
// NEVER creates a sale, NEVER touches stock, NEVER overwrites previous payments.
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('due.collect');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId   = current_shop_id();
$uid      = (int)current_user()['id'];
$custId   = (int)($_POST['customer_id'] ?? 0);
$method   = in_array($_POST['method'] ?? '', ['cash','upi','other'], true) ? $_POST['method'] : 'cash';
$ref      = trim((string)($_POST['reference'] ?? '')) ?: null;
$token    = (string)($_POST['txn_token'] ?? '');
$allocsIn = json_decode((string)($_POST['allocs_json'] ?? '[]'), true);

if ($token === '')                       { flash('danger', 'Missing idempotency token.'); redirect('client/dues/'); }
if (!is_array($allocsIn) || !$allocsIn)  { flash('danger', 'Select at least one invoice and enter an amount.'); redirect('client/dues/'); }

$cv = db()->prepare('SELECT id, name FROM customers WHERE id = :id AND shop_id = :sid');
$cv->execute([':id' => $custId, ':sid' => $shopId]);
$cust = $cv->fetch();
if (!$cust) { flash('danger', 'Customer not found.'); redirect('client/dues/'); }

try {
    db()->beginTransaction();

    $total = 0.0;
    $allocations = [];

    foreach ($allocsIn as $a) {
        $saleId = (int)($a['sale_id'] ?? 0);
        $amount = round((float)($a['amount'] ?? 0), 2);
        if ($saleId <= 0 || $amount <= 0) continue;

        // Lock the invoice + verify ownership
        $sq = db()->prepare('SELECT id, grand_total, status FROM sales
                             WHERE id = :id AND shop_id = :sid AND customer_id = :cid FOR UPDATE');
        $sq->execute([':id' => $saleId, ':sid' => $shopId, ':cid' => $custId]);
        $sale = $sq->fetch();
        if (!$sale)                        throw new RuntimeException("Invoice #{$saleId} not found for this customer.");
        if ($sale['status'] === 'cancelled') throw new RuntimeException("Invoice #{$saleId} is cancelled.");

        // Current outstanding — only verified payments count against total
        $vq = db()->prepare("SELECT COALESCE(SUM(pa.allocated_amount), 0)
                             FROM payment_allocations pa
                             JOIN payments p ON p.id = pa.payment_id
                             WHERE pa.sale_id = :id AND p.status = 'verified'");
        $vq->execute([':id' => $saleId]);
        $alreadyPaid = (float)$vq->fetchColumn();
        $outstanding = round((float)$sale['grand_total'] - $alreadyPaid, 2);

        if ($amount > $outstanding + 0.009) {
            throw new RuntimeException("Amount ₹{$amount} exceeds outstanding ₹{$outstanding} on invoice #{$saleId}.");
        }

        $allocations[] = ['sale_id' => $saleId, 'amount' => $amount];
        $total += $amount;
    }
    if ($total <= 0) throw new RuntimeException('No valid allocations.');

    // Create ONE payment covering the collected total
    $payStatus = $method === 'upi' ? 'pending' : 'verified';
    db()->prepare(
        'INSERT INTO payments (shop_id, customer_id, method, amount, reference, status, collected_by, paid_at, txn_token, note)
         VALUES (:sid, :cid, :m, :amt, :r, :st, :by, NOW(), :tk, :nt)'
    )->execute([
        ':sid' => $shopId, ':cid' => $custId, ':m' => $method, ':amt' => $total, ':r' => $ref,
        ':st'  => $payStatus, ':by' => $uid, ':tk' => $token, ':nt' => 'Due collection',
    ]);
    $payId = (int)db()->lastInsertId();

    // Insert allocations + update each sale's derived status
    $ai = db()->prepare('INSERT INTO payment_allocations (payment_id, sale_id, allocated_amount) VALUES (:p, :s, :amt)');
    foreach ($allocations as $al) {
        $ai->execute([':p' => $payId, ':s' => $al['sale_id'], ':amt' => $al['amount']]);
        // Recompute status
        $vq = db()->prepare("SELECT grand_total FROM sales WHERE id = :id");
        $vq->execute([':id' => $al['sale_id']]);
        $gt = (float)$vq->fetchColumn();
        $vq2 = db()->prepare("SELECT COALESCE(SUM(pa.allocated_amount), 0)
                              FROM payment_allocations pa
                              JOIN payments p ON p.id = pa.payment_id
                              WHERE pa.sale_id = :id AND p.status = 'verified'");
        $vq2->execute([':id' => $al['sale_id']]);
        $vp = (float)$vq2->fetchColumn();
        $status = $vp + 0.01 >= $gt ? 'paid' : ($vp > 0 ? 'partial' : 'unpaid');
        db()->prepare('UPDATE sales SET status = :st WHERE id = :id AND shop_id = :sid')
            ->execute([':st' => $status, ':id' => $al['sale_id'], ':sid' => $shopId]);
    }

    db()->commit();
    audit('due.collect', 'payment', $payId, [
        'customer_id' => $custId, 'method' => $method, 'amount' => $total,
        'allocations' => $allocations, 'status' => $payStatus,
    ], $shopId);
    unset($_SESSION['dues_token']);

    flash('success', 'Collected ' . money($total) . ' from ' . $cust['name'] .
        ($payStatus === 'pending' ? ' (UPI — pending verification).' : '.'));
    redirect('receipts/payment-receipt.php?id=' . $payId);
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    if (str_contains($e->getMessage(), 'uq_pay_token')) {
        $q = db()->prepare('SELECT id FROM payments WHERE txn_token = :t');
        $q->execute([':t' => $token]);
        $existing = (int)$q->fetchColumn();
        if ($existing) {
            unset($_SESSION['dues_token']);
            flash('warning', 'This collection was already recorded — redirected to the existing receipt.');
            redirect('receipts/payment-receipt.php?id=' . $existing);
        }
    }
    flash('danger', $e->getMessage());
    redirect('client/dues/');
}

<?php
// Verify / fail / reverse a payment. Updates allocated sales' status after.
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('due.collect');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId = current_shop_id();
$id = (int)($_POST['id'] ?? 0);
$op = $_POST['op'] ?? '';

$pq = db()->prepare('SELECT * FROM payments WHERE id = :id AND shop_id = :sid');
$pq->execute([':id' => $id, ':sid' => $shopId]);
$pay = $pq->fetch();
if (!$pay) { flash('danger', 'Payment not found.'); redirect('client/payments/'); }

$transitions = [
    'verify'  => ['from' => 'pending',  'to' => 'verified'],
    'fail'    => ['from' => 'pending',  'to' => 'failed'],
    'reverse' => ['from' => 'verified', 'to' => 'reversed'],
];
if (!isset($transitions[$op])) { flash('danger', 'Unknown operation.'); redirect('client/payments/'); }

$t = $transitions[$op];
if ($pay['status'] !== $t['from']) {
    flash('danger', "Cannot {$op} a payment that is '{$pay['status']}'.");
    redirect('client/payments/');
}

try {
    db()->beginTransaction();
    db()->prepare('UPDATE payments SET status = :st WHERE id = :id AND shop_id = :sid')
        ->execute([':st' => $t['to'], ':id' => $id, ':sid' => $shopId]);

    // Recompute status for every allocated sale
    $sq = db()->prepare('SELECT sale_id FROM payment_allocations WHERE payment_id = :id');
    $sq->execute([':id' => $id]);
    foreach ($sq->fetchAll() as $r) {
        $saleId = (int)$r['sale_id'];
        $g = db()->prepare('SELECT grand_total FROM sales WHERE id = :id AND shop_id = :sid');
        $g->execute([':id' => $saleId, ':sid' => $shopId]);
        $gt = (float)$g->fetchColumn();
        $v = db()->prepare("SELECT COALESCE(SUM(pa.allocated_amount), 0)
                            FROM payment_allocations pa
                            JOIN payments p ON p.id = pa.payment_id
                            WHERE pa.sale_id = :id AND p.status = 'verified'");
        $v->execute([':id' => $saleId]);
        $vp = (float)$v->fetchColumn();
        $status = $vp + 0.01 >= $gt ? 'paid' : ($vp > 0 ? 'partial' : 'unpaid');
        db()->prepare('UPDATE sales SET status = :st WHERE id = :id AND shop_id = :sid')
            ->execute([':st' => $status, ':id' => $saleId, ':sid' => $shopId]);
    }
    db()->commit();

    audit('payment.' . $op, 'payment', $id, ['from' => $t['from'], 'to' => $t['to']], $shopId);
    flash('success', 'Payment #' . $id . ' is now ' . $t['to'] . '.');
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash('danger', $e->getMessage());
}

redirect('client/payments/');

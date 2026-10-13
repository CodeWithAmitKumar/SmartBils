<?php
require __DIR__ . '/_bootstrap.php';
$shopId = current_shop_id();
$cid = (int)($_GET['customer_id'] ?? 0);
if ($cid <= 0) json_out(['items' => []]);

// Verify customer belongs to this shop
$cv = db()->prepare('SELECT id, name, mobile FROM customers WHERE id = :id AND shop_id = :sid');
$cv->execute([':id' => $cid, ':sid' => $shopId]);
$cust = $cv->fetch();
if (!$cust) json_out(['items' => []]);

// Pull invoices with derived balance using verified payment allocations
$stmt = db()->prepare("
    SELECT s.id, s.invoice_no, s.sale_date, s.grand_total, s.status,
      COALESCE((SELECT SUM(pa.allocated_amount)
                FROM payment_allocations pa
                JOIN payments p ON p.id = pa.payment_id
                WHERE pa.sale_id = s.id AND p.status = 'verified'), 0) AS paid_verified,
      COALESCE((SELECT SUM(pa.allocated_amount)
                FROM payment_allocations pa
                JOIN payments p ON p.id = pa.payment_id
                WHERE pa.sale_id = s.id AND p.status = 'pending'), 0) AS paid_pending
    FROM sales s
    WHERE s.shop_id = :sid AND s.customer_id = :cid AND s.status <> 'cancelled'
    ORDER BY s.id DESC");
$stmt->execute([':sid' => $shopId, ':cid' => $cid]);
$rows = $stmt->fetchAll();

$items = [];
foreach ($rows as $r) {
    $outstanding = max(0, (float)$r['grand_total'] - (float)$r['paid_verified']);
    if ($outstanding <= 0 && (float)$r['paid_pending'] <= 0) continue; // skip settled invoices
    $items[] = [
        'id'          => (int)$r['id'],
        'invoice_no'  => $r['invoice_no'],
        'date'        => $r['sale_date'],
        'grand_total' => (float)$r['grand_total'],
        'paid'        => (float)$r['paid_verified'],
        'pending'     => (float)$r['paid_pending'],
        'outstanding' => $outstanding,
        'status'      => $r['status'],
    ];
}

json_out(['customer' => $cust, 'items' => $items]);

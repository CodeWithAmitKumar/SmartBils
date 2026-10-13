<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('report.view');

$shopId = current_shop_id();

$rows = db()->prepare("
  SELECT c.id, c.name, c.mobile,
    COALESCE((SELECT SUM(grand_total) FROM sales WHERE customer_id = c.id AND status <> 'cancelled'), 0) AS billed,
    COALESCE((SELECT SUM(pa.allocated_amount)
              FROM payment_allocations pa
              JOIN payments p ON p.id = pa.payment_id
              JOIN sales s ON s.id = pa.sale_id
              WHERE s.customer_id = c.id AND p.status = 'verified'), 0) AS paid
  FROM customers c
  WHERE c.shop_id = :sid
  HAVING billed - paid > 0.01
  ORDER BY (billed - paid) DESC");
$rows->execute([':sid'=>$shopId]);
$dues = $rows->fetchAll();

$grandTotal = 0;
foreach ($dues as &$d) { $d['due'] = (float)$d['billed'] - (float)$d['paid']; $grandTotal += $d['due']; }
unset($d);

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="outstanding-dues.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Customer', 'Mobile', 'Billed', 'Paid', 'Outstanding']);
    foreach ($dues as $d) fputcsv($out, [$d['name'], $d['mobile'], $d['billed'], $d['paid'], $d['due']]);
    fputcsv($out, ['Total', '', '', '', $grandTotal]);
    exit;
}

$pageTitle = 'Outstanding Dues';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Outstanding Dues</h1>
    <p class="sb-page-subtitle"><?= count($dues) ?> customer(s) · <strong class="text-danger"><?= money($grandTotal) ?></strong> total</p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="?export=csv"><i class="bi bi-download me-1"></i>Export CSV</a>
    <a href="<?= url('client/reports/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>All reports</a>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[4,"desc"]]' data-nosort="5">
      <thead><tr>
        <th>Customer</th><th>Mobile</th><th class="sb-money">Billed</th><th class="sb-money">Paid</th><th class="sb-money">Outstanding</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($dues as $d): ?>
        <tr>
          <td><strong><?= e($d['name']) ?></strong></td>
          <td><?= e($d['mobile'] ?? '—') ?></td>
          <td class="sb-money" data-order="<?= (float)$d['billed'] ?>"><?= money($d['billed']) ?></td>
          <td class="sb-money" data-order="<?= (float)$d['paid'] ?>"><?= money($d['paid']) ?></td>
          <td class="sb-money fw-semibold text-danger" data-order="<?= (float)$d['due'] ?>"><?= money($d['due']) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= url('client/customers/view.php?id=' . (int)$d['id']) ?>">View</a>
            <a class="btn btn-sm btn-outline-warning" href="<?= url('receipts/outstanding.php?customer=' . (int)$d['id']) ?>" target="_blank">Statement</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('report.view');

$shopId = current_shop_id();
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $from)) $from = date('Y-m-01');
if (!DateTime::createFromFormat('Y-m-d', $to))   $to   = date('Y-m-d');

$rows = db()->prepare("
  SELECT si.product_id, si.product_name, p.sku,
         SUM(si.qty) AS units,
         SUM(si.line_total) AS revenue
  FROM sale_items si
  JOIN sales s ON s.id = si.sale_id
  LEFT JOIN products p ON p.id = si.product_id
  WHERE s.shop_id = :sid AND s.status <> 'cancelled' AND DATE(s.sale_date) BETWEEN :a AND :b
  GROUP BY si.product_id, si.product_name, p.sku
  ORDER BY revenue DESC");
$rows->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$data = $rows->fetchAll();

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="product-sales-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product', 'SKU', 'Units', 'Revenue']);
    foreach ($data as $d) fputcsv($out, [$d['product_name'], $d['sku'], $d['units'], $d['revenue']]);
    exit;
}

$pageTitle = 'Product-wise Sales';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div><h1 class="sb-page-title">Product-wise Sales</h1></div>
  <a href="<?= url('client/reports/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>All reports</a>
</div>

<form method="get" class="card p-3 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-6 d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
  </div>
</form>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[3,"desc"]]'>
      <thead><tr><th>Product</th><th>SKU</th><th>Units</th><th class="sb-money">Revenue</th></tr></thead>
      <tbody>
      <?php foreach ($data as $d): ?>
        <tr>
          <td><strong><?= e($d['product_name']) ?></strong></td>
          <td><?= $d['sku'] ? '<code>' . e($d['sku']) . '</code>' : '—' ?></td>
          <td data-order="<?= (float)$d['units'] ?>"><?= rtrim(rtrim(number_format((float)$d['units'], 3, '.', ''), '0'), '.') ?></td>
          <td class="sb-money" data-order="<?= (float)$d['revenue'] ?>"><?= money($d['revenue']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

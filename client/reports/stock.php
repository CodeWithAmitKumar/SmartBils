<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('report.view');

$shopId = current_shop_id();
$filter = $_GET['filter'] ?? '';

$where = ['p.shop_id = :sid'];
if ($filter === 'low') $where[] = 'p.current_stock <= p.min_stock_alert AND p.min_stock_alert > 0';
if ($filter === 'out') $where[] = 'p.current_stock <= 0';
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = db()->prepare("
  SELECT p.id, p.name, p.sku, p.unit, p.current_stock, p.min_stock_alert,
         p.purchase_cost, p.selling_price,
         (p.current_stock * COALESCE(p.purchase_cost, 0)) AS stock_value
  FROM products p
  $whereSql
  ORDER BY p.current_stock ASC");
$rows->execute([':sid' => $shopId]);
$products = $rows->fetchAll();
$totalValue = array_sum(array_column($products, 'stock_value'));
$lowCount = 0; $outCount = 0;
foreach ($products as $p) {
    if ((float)$p['current_stock'] <= 0) $outCount++;
    elseif ((float)$p['min_stock_alert'] > 0 && (float)$p['current_stock'] <= (float)$p['min_stock_alert']) $lowCount++;
}

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="stock-report.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Product', 'SKU', 'Current', 'Unit', 'Min alert', 'Cost', 'Price', 'Stock value']);
    foreach ($products as $r)
        fputcsv($out, [$r['name'], $r['sku'], $r['current_stock'], $r['unit'], $r['min_stock_alert'], $r['purchase_cost'], $r['selling_price'], $r['stock_value']]);
    fputcsv($out, ['Total value at cost', '', '', '', '', '', '', $totalValue]);
    exit;
}

$pageTitle = 'Stock Report';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Stock Report</h1>
    <p class="sb-page-subtitle">
      Value at cost: <strong><?= money($totalValue) ?></strong> ·
      <span class="text-warning"><?= (int)$lowCount ?> low</span> ·
      <span class="text-danger"><?= (int)$outCount ?> out</span>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-secondary" href="?filter=<?= e($filter) ?>&export=csv"><i class="bi bi-download me-1"></i>Export CSV</a>
    <a href="<?= url('client/reports/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>All reports</a>
  </div>
</div>

<div class="btn-group mb-3" role="group">
  <?php foreach ([['','All'],['low','Low stock'],['out','Out of stock']] as [$f,$lbl]): ?>
    <a class="btn btn-outline-secondary <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[2,"asc"]]'>
      <thead><tr>
        <th>Product</th><th>SKU</th><th>Current</th><th>Min alert</th>
        <th class="sb-money">Cost</th><th class="sb-money">Price</th><th class="sb-money">Stock value</th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p):
          $cur = (float)$p['current_stock']; $min = (float)$p['min_stock_alert'];
          $isOut = $cur <= 0; $isLow = !$isOut && $min > 0 && $cur <= $min;
          $cls = $isOut ? 'text-danger fw-semibold' : ($isLow ? 'text-warning fw-semibold' : '');
      ?>
        <tr>
          <td><strong><?= e($p['name']) ?></strong></td>
          <td><?= $p['sku'] ? '<code>' . e($p['sku']) . '</code>' : '—' ?></td>
          <td class="<?= $cls ?>" data-order="<?= $cur ?>">
            <?= rtrim(rtrim(number_format($cur, 3, '.', ''), '0'), '.') ?> <?= e($p['unit']) ?>
            <?php if ($isOut): ?><span class="badge-pill badge-unpaid ms-1">Out</span>
            <?php elseif ($isLow): ?><span class="badge-pill badge-partial ms-1">Low</span><?php endif; ?>
          </td>
          <td data-order="<?= $min ?>"><?= $min > 0 ? rtrim(rtrim(number_format($min, 3, '.', ''), '0'), '.') : '—' ?></td>
          <td class="sb-money" data-order="<?= (float)$p['purchase_cost'] ?>"><?= money($p['purchase_cost']) ?></td>
          <td class="sb-money" data-order="<?= (float)$p['selling_price'] ?>"><?= money($p['selling_price']) ?></td>
          <td class="sb-money" data-order="<?= (float)$p['stock_value'] ?>"><?= money($p['stock_value']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

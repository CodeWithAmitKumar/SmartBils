<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('report.view');

$pageTitle = 'Reports';
require __DIR__ . '/../../includes/layout-top.php';

$cards = [
  ['sales.php',    'graph-up',       'Sales',         'Daily / monthly sales with chart + CSV'],
  ['products.php', 'box-seam',       'Products',      'Product-wise sales — units & revenue'],
  ['dues.php',     'exclamation-circle', 'Outstanding dues', 'Customers who still owe you money'],
  ['profit.php',   'cash-coin',      'Profit',        'Net sales − COGS − expenses (date range)'],
  ['stock.php',    'archive',        'Stock',         'Current stock + low-stock + out-of-stock'],
];
?>
<div class="sb-page-header">
  <div><h1 class="sb-page-title">Reports</h1><p class="sb-page-subtitle">Pick a report. All values are shop-scoped and server-computed.</p></div>
</div>

<div class="row g-3">
  <?php foreach ($cards as [$path, $icon, $title, $sub]): ?>
    <div class="col-md-6 col-xl-4">
      <a href="<?= url('client/reports/' . $path) ?>" class="text-decoration-none text-reset">
        <div class="sb-stat h-100">
          <div class="sb-stat-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="bi bi-<?= $icon ?>"></i></div>
          <div>
            <div class="sb-stat-value" style="font-size:18px;"><?= e($title) ?></div>
            <div class="sb-stat-label"><?= e($sub) ?></div>
          </div>
        </div>
      </a>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

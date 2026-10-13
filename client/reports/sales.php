<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('report.view');

$shopId = current_shop_id();

$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $from)) $from = date('Y-m-01');
if (!DateTime::createFromFormat('Y-m-d', $to))   $to   = date('Y-m-d');

$byDay = db()->prepare("
  SELECT DATE(sale_date) AS d,
         COUNT(*) AS orders,
         SUM(grand_total) AS gross,
         SUM(discount) AS discount
  FROM sales
  WHERE shop_id = :sid AND status <> 'cancelled' AND DATE(sale_date) BETWEEN :a AND :b
  GROUP BY DATE(sale_date) ORDER BY d");
$byDay->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$daily = $byDay->fetchAll();

$totalGross = array_sum(array_column($daily, 'gross'));
$totalOrders = array_sum(array_column($daily, 'orders'));
$avg = $totalOrders > 0 ? $totalGross / $totalOrders : 0;

// Returns
$ret = db()->prepare('
  SELECT COALESCE(SUM(sr.total_return_amount), 0) AS returned
  FROM sales_returns sr JOIN sales s ON s.id = sr.sale_id
  WHERE s.shop_id = :sid AND DATE(sr.created_at) BETWEEN :a AND :b');
$ret->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$returned = (float)$ret->fetchColumn();
$netSales = $totalGross - $returned;

// Payment method split (verified only)
$pay = db()->prepare("
  SELECT p.method, COALESCE(SUM(pa.allocated_amount), 0) AS total
  FROM payment_allocations pa
  JOIN payments p ON p.id = pa.payment_id
  JOIN sales s    ON s.id = pa.sale_id
  WHERE s.shop_id = :sid AND p.status = 'verified' AND DATE(p.paid_at) BETWEEN :a AND :b
  GROUP BY p.method");
$pay->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$payRows = $pay->fetchAll();
$payTotals = ['cash' => 0, 'upi' => 0, 'other' => 0];
foreach ($payRows as $r) $payTotals[$r['method']] = (float)$r['total'];

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="sales-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Date', 'Orders', 'Gross', 'Discount']);
    foreach ($daily as $d) fputcsv($out, [$d['d'], $d['orders'], $d['gross'], $d['discount']]);
    fputcsv($out, []);
    fputcsv($out, ['Totals', $totalOrders, $totalGross]);
    fputcsv($out, ['Returns in range', '', $returned]);
    fputcsv($out, ['Net sales', '', $netSales]);
    exit;
}

$pageTitle = 'Sales Report';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div><h1 class="sb-page-title">Sales Report</h1><p class="sb-page-subtitle"><?= money($netSales) ?> net in range</p></div>
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

<div class="row g-3">
  <?php $cards = [
    ['Gross sales',  $totalGross,  'cash-coin',  '#EEF2FF', '#4F46E5'],
    ['Returns',      $returned,    'arrow-counterclockwise', '#FEF3C7', '#B45309'],
    ['Net sales',    $netSales,    'cash-stack', '#D1FAE5', '#047857'],
    ['Orders',       $totalOrders, 'receipt',    '#E0E7FF', '#4338CA'],
    ['Cash in',      $payTotals['cash'], 'wallet2', '#D1FAE5', '#047857'],
    ['UPI in',       $payTotals['upi'],  'phone',   '#DBEAFE', '#1D4ED8'],
    ['Other in',     $payTotals['other'], 'bank', '#FFF7ED', '#9A3412'],
    ['Avg order',    $avg,         'bar-chart',  '#F3E8FF', '#6D28D9'],
  ]; foreach ($cards as [$lbl, $v, $icon, $bg, $fg]):
      $isCount = $lbl === 'Orders';
  ?>
    <div class="col-sm-6 col-xl-3">
      <div class="sb-stat">
        <div class="sb-stat-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;"><i class="bi bi-<?= $icon ?>"></i></div>
        <div><div class="sb-stat-value" style="font-size:20px;"><?= $isCount ? (int)$v : money($v) ?></div><div class="sb-stat-label"><?= e($lbl) ?></div></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mt-3">
  <div class="card-header">Daily sales</div>
  <div class="card-body"><canvas id="chart" height="110"></canvas></div>
</div>

<div class="card mt-3">
  <div class="card-header">Breakdown</div>
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[0,"asc"]]'>
      <thead><tr><th>Date</th><th>Orders</th><th class="sb-money">Discount</th><th class="sb-money">Gross</th></tr></thead>
      <tbody>
      <?php foreach ($daily as $d): ?>
        <tr>
          <td data-order="<?= e($d['d']) ?>"><?= e(date('d M Y', strtotime($d['d']))) ?></td>
          <td><?= (int)$d['orders'] ?></td>
          <td class="sb-money"><?= money($d['discount']) ?></td>
          <td class="sb-money"><?= money($d['gross']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  const data = <?= json_encode($daily) ?>;
  new Chart(document.getElementById('chart'), {
    type: 'bar',
    data: { labels: data.map(d => d.d), datasets: [{ label:'Gross', data: data.map(d => +d.gross),
      backgroundColor: 'rgba(99,102,241,.8)', borderRadius: 8, maxBarThickness: 42 }] },
    options: {
      plugins:{legend:{display:false}},
      scales:{y:{beginAtZero:true, ticks:{callback:(v)=>'<?= APP_CURRENCY_SYMBOL ?>'+v.toLocaleString('en-IN')}}, x:{grid:{display:false}}},
      maintainAspectRatio:false
    }
  });
</script>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

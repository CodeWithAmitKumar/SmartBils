<?php
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

$from = $_GET['from'] ?? date('Y-m-01', strtotime('-5 months'));
$to   = $_GET['to']   ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $from)) $from = date('Y-m-01', strtotime('-5 months'));
if (!DateTime::createFromFormat('Y-m-d', $to))   $to   = date('Y-m-d');

$byMonth = db()->prepare("
    SELECT DATE_FORMAT(renewed_at, '%Y-%m') AS ym, SUM(amount) AS total, COUNT(*) AS cnt
    FROM license_renewals
    WHERE DATE(renewed_at) BETWEEN :a AND :b
    GROUP BY ym ORDER BY ym");
$byMonth->execute([':a' => $from, ':b' => $to]);
$monthly = $byMonth->fetchAll();

$byPlan = db()->prepare("
    SELECT p.name AS plan, SUM(lr.amount) AS total, COUNT(*) AS cnt
    FROM license_renewals lr
    JOIN subscription_plans p ON p.id = lr.plan_id
    WHERE DATE(lr.renewed_at) BETWEEN :a AND :b
    GROUP BY p.id, p.name ORDER BY total DESC");
$byPlan->execute([':a' => $from, ':b' => $to]);
$perPlan = $byPlan->fetchAll();

$totalRange   = (float)array_sum(array_column($monthly, 'total'));
$totalCount   = (int)array_sum(array_column($monthly, 'cnt'));

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="money-report-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Month', 'Payments', 'Total']);
    foreach ($monthly as $m) fputcsv($out, [$m['ym'], $m['cnt'], $m['total']]);
    fputcsv($out, []);
    fputcsv($out, ['Plan', 'Payments', 'Total']);
    foreach ($perPlan as $p) fputcsv($out, [$p['plan'], $p['cnt'], $p['total']]);
    exit;
}

$pageTitle = 'Money Report';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Money Report</h1>
    <p class="sb-page-subtitle"><?= money($totalRange) ?> collected in range · <?= (int)$totalCount ?> payments</p>
  </div>
</div>

<form method="get" class="card p-3 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-md-3">
      <label class="form-label">From</label>
      <input type="date" class="form-control" name="from" value="<?= e($from) ?>">
    </div>
    <div class="col-md-3">
      <label class="form-label">To</label>
      <input type="date" class="form-control" name="to" value="<?= e($to) ?>">
    </div>
    <div class="col-md-6 d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv">
        <i class="bi bi-download me-1"></i>Export CSV
      </a>
    </div>
  </div>
</form>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">Revenue by month</div>
      <div class="card-body"><canvas id="moneyBar" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Revenue by plan</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <canvas id="moneyDonut" height="200"></canvas>
      </div>
    </div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">Breakdown</div>
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[0,"asc"]]'>
      <thead><tr><th>Month</th><th>Payments</th><th class="sb-money">Total</th></tr></thead>
      <tbody>
      <?php foreach ($monthly as $m): ?>
        <tr>
          <td data-order="<?= e($m['ym']) ?>"><?= e(date('F Y', strtotime($m['ym'] . '-01'))) ?></td>
          <td data-order="<?= (int)$m['cnt'] ?>"><?= (int)$m['cnt'] ?></td>
          <td class="sb-money" data-order="<?= (float)$m['total'] ?>"><?= money($m['total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php if ($monthly): ?>
  <div class="mt-2 text-end text-muted small">Total · <?= (int)$totalCount ?> payments · <?= money($totalRange) ?></div>
<?php endif; ?>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  (() => {
    const monthly = <?= json_encode($monthly) ?>;
    const perPlan = <?= json_encode($perPlan) ?>;

    const labels = monthly.map(m => m.ym);
    const values = monthly.map(m => Number(m.total));

    const grad = (ctx) => {
      const g = ctx.createLinearGradient(0, 0, 0, 260);
      g.addColorStop(0, 'rgba(99,102,241,.9)');
      g.addColorStop(1, 'rgba(139,92,246,.35)');
      return g;
    };

    const b = document.getElementById('moneyBar');
    if (b) new Chart(b, {
      type: 'bar',
      data: { labels, datasets: [{ data: values, backgroundColor: (c) => grad(c.chart.ctx), borderRadius: 8, maxBarThickness: 42 }] },
      options: {
        plugins: { legend: { display: false } },
        scales: { y: { beginAtZero: true, ticks: { callback: (v) => '<?= APP_CURRENCY_SYMBOL ?>' + v.toLocaleString('en-IN') }, grid: { color: '#EEF0F7' } }, x: { grid: { display: false } } },
        maintainAspectRatio: false,
      }
    });

    const d = document.getElementById('moneyDonut');
    if (d) new Chart(d, {
      type: 'doughnut',
      data: {
        labels: perPlan.map(p => p.plan),
        datasets: [{ data: perPlan.map(p => Number(p.total)), backgroundColor: ['#6366F1','#8B5CF6','#06B6D4','#F59E0B','#10B981','#EF4444'], borderWidth: 0 }],
      },
      options: { cutout: '66%', plugins: { legend: { position: 'bottom', labels: { boxWidth: 10 } } }, maintainAspectRatio: false }
    });
  })();
</script>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

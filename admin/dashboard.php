<?php
require_once __DIR__ . '/../config/app.php';
require_super_admin();

/* ---------- Counters ---------- */
$stats = db()->query("
    SELECT
      (SELECT COUNT(*) FROM shops)                                                   AS total_shops,
      (SELECT COUNT(*) FROM shops WHERE status = 'active')                           AS active_shops,
      (SELECT COUNT(*) FROM shops WHERE status = 'suspended')                        AS suspended_shops,
      (SELECT COUNT(*) FROM shops WHERE status = 'deactivated')                      AS deactivated_shops,
      (SELECT COUNT(*) FROM shop_licenses WHERE expiry_date < CURDATE())             AS expired_licenses,
      (SELECT COUNT(*) FROM shop_licenses WHERE expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY) AS expiring_soon,
      (SELECT COUNT(*) FROM subscription_plans WHERE is_active = 1)                  AS active_plans
")->fetch();

/* ---------- Revenue (from license_renewals — one source of truth) ---------- */
$rev = db()->query("
    SELECT
      COALESCE(SUM(amount), 0)                                                       AS total_revenue,
      COALESCE(SUM(CASE WHEN YEAR(renewed_at)=YEAR(CURDATE()) AND MONTH(renewed_at)=MONTH(CURDATE()) THEN amount END),0) AS month_revenue,
      COALESCE(SUM(CASE WHEN DATE(renewed_at)=CURDATE() THEN amount END),0)          AS today_revenue
    FROM license_renewals
")->fetch();

/* ---------- 12-month revenue series ---------- */
$rows = db()->query("
    SELECT DATE_FORMAT(renewed_at, '%Y-%m') AS ym, SUM(amount) AS total
    FROM license_renewals
    WHERE renewed_at >= DATE_SUB(DATE_FORMAT(CURDATE(), '%Y-%m-01'), INTERVAL 11 MONTH)
    GROUP BY ym ORDER BY ym
")->fetchAll();
$byMonth = array_column($rows, 'total', 'ym');

$labels = []; $values = [];
for ($i = 11; $i >= 0; $i--) {
    $key = date('Y-m', strtotime("-$i months"));
    $labels[] = date('M y', strtotime($key . '-01'));
    $values[] = (float)($byMonth[$key] ?? 0);
}

/* ---------- Status donut ---------- */
$status = [
    'Active'      => (int)$stats['active_shops'],
    'Suspended'   => (int)$stats['suspended_shops'],
    'Deactivated' => (int)$stats['deactivated_shops'],
];

$recent = db()->query("
    SELECT s.id, s.name, s.status, s.created_at, s.logo_path,
           sl.expiry_date, p.name AS plan_name
    FROM shops s
    LEFT JOIN shop_licenses sl ON sl.shop_id = s.id
    LEFT JOIN subscription_plans p ON p.id = sl.plan_id
    ORDER BY s.created_at DESC
    LIMIT 6
")->fetchAll();

$pageTitle = 'Admin Dashboard';
require __DIR__ . '/../includes/layout-top.php';
?>
<?php
$name  = current_user()['name'] ?? '';
$first = trim(strtok($name, ' ')) ?: $name;
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');
?>
<div class="sb-welcome mb-4">
  <div>
    <div class="sb-welcome-eyebrow"><?= e(date('l, d M Y')) ?></div>
    <h1 class="sb-welcome-title"><?= e($greet) ?>, <?= e($first) ?> 👋</h1>
    <p class="sb-welcome-sub mb-0">Here's what's happening across your platform today.</p>
  </div>
  <div class="sb-welcome-art" aria-hidden="true">
    <i class="bi bi-stars"></i>
  </div>
</div>

<!-- Hero revenue strip -->
<div class="sb-hero mb-4">
  <div class="row g-3">
    <div class="col-lg-4">
      <div class="sb-hero-main">
        <div class="sb-hero-eyebrow">Total revenue</div>
        <div class="sb-hero-value"><?= money($rev['total_revenue']) ?></div>
        <div class="sb-hero-sub">All-time licence sales + renewals</div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="sb-hero-sub-card">
        <div class="sb-hero-eyebrow">This month</div>
        <div class="sb-hero-value-sm"><?= money($rev['month_revenue']) ?></div>
        <div class="sb-hero-sub"><?= date('F Y') ?></div>
      </div>
    </div>
    <div class="col-lg-4">
      <div class="sb-hero-sub-card">
        <div class="sb-hero-eyebrow">Today</div>
        <div class="sb-hero-value-sm"><?= money($rev['today_revenue']) ?></div>
        <div class="sb-hero-sub"><?= date('d M Y') ?></div>
      </div>
    </div>
  </div>
</div>

<!-- Stat tiles -->
<div class="row g-3">
  <?php
  $cards = [
    ['Total shops',       $stats['total_shops'],       'shop',          '#EEF2FF', '#4F46E5'],
    ['Active shops',      $stats['active_shops'],      'check2-circle', '#D1FAE5', '#047857'],
    ['Expiring in 7d',    $stats['expiring_soon'],     'clock-history', '#FFF7ED', '#9A3412'],
    ['Expired licences',  $stats['expired_licenses'],  'x-octagon',     '#FEE2E2', '#B91C1C'],
  ];
  foreach ($cards as [$label, $value, $icon, $bg, $fg]): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="sb-stat">
        <div class="sb-stat-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;"><i class="bi bi-<?= $icon ?>"></i></div>
        <div>
          <div class="sb-stat-value"><?= (int)$value ?></div>
          <div class="sb-stat-label"><?= e($label) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<!-- Charts -->
<div class="row g-3 mt-1">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Revenue — last 12 months</span>
        <span class="text-muted small">Sum of licence payments by month</span>
      </div>
      <div class="card-body">
        <canvas id="sbRevenueChart" height="110"></canvas>
      </div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Shops by status</div>
      <div class="card-body d-flex align-items-center justify-content-center">
        <canvas id="sbStatusChart" height="200"></canvas>
      </div>
    </div>
  </div>
</div>

<!-- Recent shops -->
<div class="card mt-4">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Recent shops</span>
    <a href="<?= url('admin/clients/') ?>" class="small text-decoration-none">View all <i class="bi bi-arrow-right"></i></a>
  </div>
  <div class="table-responsive">
    <table class="table">
      <thead><tr><th>Shop</th><th>Plan</th><th>Status</th><th>Licence expiry</th><th>Created</th><th></th></tr></thead>
      <tbody>
      <?php if (!$recent): ?>
        <tr><td colspan="6" class="text-center text-muted py-4">No shops yet. <a href="<?= url('admin/clients/form.php') ?>">Add one</a>.</td></tr>
      <?php else: foreach ($recent as $r):
          $badge = ['active'=>'badge-paid','suspended'=>'badge-partial','deactivated'=>'badge-unpaid'][$r['status']] ?? 'badge-pending';
          $isExpired = !empty($r['expiry_date']) && $r['expiry_date'] < date('Y-m-d');
          $logoUrl = image_url($r['logo_path'] ?? null);
      ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="sb-shop-logo-sm">
                <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="">
                <?php else: ?><span><?= e(mb_strtoupper(mb_substr($r['name'],0,1))) ?></span><?php endif; ?>
              </div>
              <strong><?= e($r['name']) ?></strong>
            </div>
          </td>
          <td><?= e($r['plan_name'] ?? '—') ?></td>
          <td><span class="badge-pill <?= $badge ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td class="<?= $isExpired ? 'text-danger' : '' ?>">
            <?= $r['expiry_date'] ? e(date('d M Y', strtotime($r['expiry_date']))) : '—' ?>
          </td>
          <td class="text-muted"><?= e(date('d M Y', strtotime($r['created_at']))) ?></td>
          <td class="text-end"><a href="<?= url('admin/clients/view.php?id=' . (int)$r['id']) ?>" class="btn btn-sm btn-outline-primary">View</a></td>
        </tr>
      <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  (() => {
    const labels = <?= json_encode($labels) ?>;
    const values = <?= json_encode($values) ?>;
    const status = <?= json_encode($status) ?>;

    const grad = (ctx) => {
      const g = ctx.createLinearGradient(0, 0, 0, 260);
      g.addColorStop(0, 'rgba(99,102,241,.9)');
      g.addColorStop(1, 'rgba(139,92,246,.35)');
      return g;
    };

    // Revenue bar chart
    const r = document.getElementById('sbRevenueChart');
    if (r) {
      new Chart(r, {
        type: 'bar',
        data: { labels, datasets: [{
          label: 'Revenue', data: values,
          backgroundColor: (c) => grad(c.chart.ctx),
          borderRadius: 8, barThickness: 'flex', maxBarThickness: 42,
        }]},
        options: {
          plugins: { legend: { display: false }, tooltip: {
            callbacks: { label: (ctx) => '<?= APP_CURRENCY_SYMBOL ?>' + ctx.parsed.y.toLocaleString('en-IN') }
          }},
          scales: {
            y: { beginAtZero: true, ticks: { callback: (v) => '<?= APP_CURRENCY_SYMBOL ?>' + v.toLocaleString('en-IN') }, grid: { color: '#EEF0F7' } },
            x: { grid: { display: false } },
          },
          maintainAspectRatio: false,
        }
      });
    }

    // Donut
    const s = document.getElementById('sbStatusChart');
    if (s) {
      new Chart(s, {
        type: 'doughnut',
        data: {
          labels: Object.keys(status),
          datasets: [{
            data: Object.values(status),
            backgroundColor: ['#10B981', '#F59E0B', '#EF4444'],
            borderWidth: 0, hoverOffset: 6,
          }]
        },
        options: {
          cutout: '66%',
          plugins: { legend: { position: 'bottom', labels: { boxWidth: 10, boxHeight: 10 } } },
          maintainAspectRatio: false,
        }
      });
    }
  })();
</script>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

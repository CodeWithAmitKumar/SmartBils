<?php
require_once __DIR__ . '/../config/app.php';
require_shop_user();

$shopId = current_shop_id();
$u = current_user();
$name  = $u['name'] ?? '';
$first = trim(strtok($name, ' ')) ?: $name;
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

/* ---------- Today's figures ---------- */
$today = date('Y-m-d');

$q = db()->prepare("SELECT COALESCE(SUM(grand_total), 0) AS v, COUNT(*) AS n
                    FROM sales WHERE shop_id = :sid AND status <> 'cancelled' AND DATE(sale_date) = :d");
$q->execute([':sid'=>$shopId, ':d'=>$today]);
$todaySales = $q->fetch();

$q = db()->prepare("SELECT COALESCE(SUM(amount), 0) FROM payments
                    WHERE shop_id = :sid AND status = 'verified' AND DATE(paid_at) = :d");
$q->execute([':sid'=>$shopId, ':d'=>$today]);
$collectedToday = (float)$q->fetchColumn();

$q = db()->prepare("SELECT
  COALESCE(SUM(s.grand_total), 0) -
  COALESCE((SELECT SUM(pa.allocated_amount)
            FROM payment_allocations pa
            JOIN payments p ON p.id = pa.payment_id
            JOIN sales s2 ON s2.id = pa.sale_id
            WHERE s2.shop_id = :sid2 AND p.status = 'verified'), 0) AS dues
  FROM sales s WHERE s.shop_id = :sid AND s.status <> 'cancelled'");
$q->execute([':sid'=>$shopId, ':sid2'=>$shopId]);
$outstandingAll = max(0, (float)$q->fetchColumn());

$q = db()->prepare("SELECT COUNT(*) FROM products
                    WHERE shop_id = :sid AND is_available = 1
                      AND ((min_stock_alert > 0 AND current_stock <= min_stock_alert) OR current_stock <= 0)");
$q->execute([':sid'=>$shopId]);
$lowStockCount = (int)$q->fetchColumn();

/* ---------- 7-day sales trend ---------- */
$days = db()->prepare("
  SELECT DATE(sale_date) AS d, SUM(grand_total) AS t
  FROM sales
  WHERE shop_id = :sid AND status <> 'cancelled'
    AND DATE(sale_date) >= DATE_SUB(CURDATE(), INTERVAL 6 DAY)
  GROUP BY DATE(sale_date)");
$days->execute([':sid'=>$shopId]);
$map = [];
foreach ($days->fetchAll() as $r) $map[$r['d']] = (float)$r['t'];
$trendLabels = $trendValues = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('Y-m-d', strtotime("-$i days"));
    $trendLabels[] = date('d M', strtotime($d));
    $trendValues[] = $map[$d] ?? 0;
}

/* ---------- Top 5 products (last 30 days) ---------- */
$tp = db()->prepare("
  SELECT si.product_name, SUM(si.qty) AS units, SUM(si.line_total) AS revenue
  FROM sale_items si JOIN sales s ON s.id = si.sale_id
  WHERE s.shop_id = :sid AND s.status <> 'cancelled'
    AND DATE(s.sale_date) >= DATE_SUB(CURDATE(), INTERVAL 30 DAY)
  GROUP BY si.product_name
  ORDER BY revenue DESC LIMIT 5");
$tp->execute([':sid'=>$shopId]);
$topProducts = $tp->fetchAll();

/* ---------- Recent sales ---------- */
$rs = db()->prepare("
  SELECT s.id, s.invoice_no, s.sale_date, s.grand_total, s.status, c.name AS cust_name
  FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
  WHERE s.shop_id = :sid
  ORDER BY s.id DESC LIMIT 6");
$rs->execute([':sid'=>$shopId]);
$recentSales = $rs->fetchAll();

/* ---------- Pending UPI ---------- */
$pu = db()->prepare("SELECT COUNT(*) FROM payments WHERE shop_id = :sid AND status = 'pending'");
$pu->execute([':sid'=>$shopId]);
$pendingUpi = (int)$pu->fetchColumn();

/* ---------- Overdue reminders ---------- */
$od = db()->prepare("SELECT COUNT(*) FROM sales
                     WHERE shop_id = :sid AND status IN ('unpaid','partial')
                       AND due_date IS NOT NULL AND due_date <= CURDATE()");
$od->execute([':sid'=>$shopId]);
$overdueCount = (int)$od->fetchColumn();

$pageTitle = 'Dashboard';
require __DIR__ . '/../includes/layout-top.php';
?>
<div class="sb-welcome mb-4">
  <div>
    <div class="sb-welcome-eyebrow"><?= e(date('l, d M Y')) ?></div>
    <h1 class="sb-welcome-title"><?= e($greet) ?>, <?= e($first) ?> 👋</h1>
    <p class="sb-welcome-sub mb-0"><?= $u['shop_name'] ? 'Welcome back to ' . e($u['shop_name']) . '.' : 'Welcome back to your shop workspace.' ?></p>
  </div>
  <div class="sb-welcome-art"><i class="bi bi-shop"></i></div>
</div>

<!-- Hero strip -->
<div class="row g-3 mb-3">
  <div class="col-lg-5">
    <div class="sb-hero-main">
      <div class="sb-hero-eyebrow">Today's sales</div>
      <div class="sb-hero-value"><?= money($todaySales['v']) ?></div>
      <div class="sb-hero-sub"><?= (int)$todaySales['n'] ?> order<?= (int)$todaySales['n'] === 1 ? '' : 's' ?> · <?= money($collectedToday) ?> collected</div>
    </div>
  </div>
  <div class="col-lg-7">
    <div class="row g-3">
      <div class="col-sm-6">
        <div class="sb-stat h-100">
          <div class="sb-stat-icon" style="background:#FEE2E2;color:#B91C1C;"><i class="bi bi-exclamation-circle"></i></div>
          <div>
            <div class="sb-stat-value"><?= money($outstandingAll) ?></div>
            <div class="sb-stat-label">Outstanding dues</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6">
        <div class="sb-stat h-100">
          <div class="sb-stat-icon" style="background:#FEF3C7;color:#B45309;"><i class="bi bi-box-seam"></i></div>
          <div>
            <div class="sb-stat-value"><?= $lowStockCount ?></div>
            <div class="sb-stat-label">Low / out of stock</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6">
        <div class="sb-stat h-100">
          <div class="sb-stat-icon" style="background:#D1FAE5;color:#047857;"><i class="bi bi-wallet2"></i></div>
          <div>
            <div class="sb-stat-value"><?= money($collectedToday) ?></div>
            <div class="sb-stat-label">Collected today</div>
          </div>
        </div>
      </div>
      <div class="col-sm-6">
        <a href="<?= url('client/reminders/') ?>" class="text-decoration-none text-reset">
          <div class="sb-stat h-100">
            <div class="sb-stat-icon" style="background:#FEE2E2;color:#B91C1C;"><i class="bi bi-bell"></i></div>
            <div>
              <div class="sb-stat-value"><?= $overdueCount ?></div>
              <div class="sb-stat-label">Reminders due today</div>
            </div>
          </div>
        </a>
      </div>
    </div>
  </div>
</div>

<!-- Quick actions -->
<div class="d-flex gap-2 flex-wrap mb-3">
  <?php if (has_permission('bill.create')): ?>
    <a href="<?= url('client/billing/') ?>" class="btn btn-primary"><i class="bi bi-cart-plus me-1"></i>New bill</a>
  <?php endif; ?>
  <?php if (has_permission('due.collect')): ?>
    <a href="<?= url('client/dues/') ?>" class="btn btn-outline-primary"><i class="bi bi-cash-stack me-1"></i>Collect due</a>
  <?php endif; ?>
  <?php if (has_permission('product.manage')): ?>
    <a href="<?= url('client/products/form.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-plus-lg me-1"></i>Add product</a>
  <?php endif; ?>
  <?php if (has_permission('customer.manage')): ?>
    <a href="<?= url('client/customers/form.php') ?>" class="btn btn-outline-secondary"><i class="bi bi-person-plus me-1"></i>Add customer</a>
  <?php endif; ?>
  <?php if (has_permission('report.view')): ?>
    <a href="<?= url('client/reports/') ?>" class="btn btn-outline-secondary"><i class="bi bi-graph-up me-1"></i>Reports</a>
  <?php endif; ?>
</div>

<!-- Chart + Top products -->
<div class="row g-3">
  <div class="col-lg-8">
    <div class="card h-100">
      <div class="card-header">Sales — last 7 days</div>
      <div class="card-body"><canvas id="trendChart" height="120"></canvas></div>
    </div>
  </div>
  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Top products <span class="small text-muted">· 30 days</span></div>
      <div class="card-body">
        <?php if (!$topProducts): ?>
          <p class="text-muted mb-0">No sales yet in the last 30 days.</p>
        <?php else: ?>
          <ul class="list-unstyled mb-0">
            <?php $max = (float)$topProducts[0]['revenue']; foreach ($topProducts as $tp):
                $w = $max > 0 ? max(6, round(((float)$tp['revenue'] / $max) * 100)) : 0; ?>
              <li class="mb-3">
                <div class="d-flex justify-content-between small">
                  <span class="text-truncate" style="max-width:65%;"><strong><?= e($tp['product_name']) ?></strong></span>
                  <span class="sb-money"><?= money($tp['revenue']) ?></span>
                </div>
                <div style="height:6px; background:#EEF0F7; border-radius:999px; margin-top:4px; overflow:hidden;">
                  <div style="width:<?= $w ?>%; height:100%; background:linear-gradient(90deg,#6366F1,#8B5CF6);"></div>
                </div>
                <div class="small text-muted mt-1"><?= rtrim(rtrim(number_format((float)$tp['units'], 3, '.', ''), '0'), '.') ?> units</div>
              </li>
            <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<!-- Recent sales -->
<div class="card mt-3">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Recent sales</span>
    <a class="small text-decoration-none" href="<?= url('client/reports/sales.php') ?>">View report <i class="bi bi-arrow-right"></i></a>
  </div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Invoice</th><th>Customer</th><th>Date</th><th>Status</th><th class="sb-money">Total</th></tr></thead>
      <tbody>
        <?php if (!$recentSales): ?>
          <tr><td colspan="5" class="text-center text-muted py-4">No sales yet. <a href="<?= url('client/billing/') ?>">Create the first bill</a>.</td></tr>
        <?php else: foreach ($recentSales as $s):
            $cls = ['paid'=>'badge-paid','partial'=>'badge-partial','unpaid'=>'badge-unpaid','returned'=>'badge-pending','cancelled'=>'badge-unpaid'][$s['status']] ?? 'badge-pending';
        ?>
          <tr>
            <td><a href="<?= url('receipts/invoice.php?id=' . (int)$s['id']) ?>" target="_blank"><code><?= e($s['invoice_no']) ?></code></a></td>
            <td><?= e($s['cust_name'] ?? 'Walk-in') ?></td>
            <td class="text-muted"><?= e(date('d M Y H:i', strtotime($s['sale_date']))) ?></td>
            <td><span class="badge-pill <?= $cls ?>"><?= e(ucfirst($s['status'])) ?></span></td>
            <td class="sb-money"><?= money($s['grand_total']) ?></td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.4/dist/chart.umd.min.js"></script>
<script>
  const labels = <?= json_encode($trendLabels) ?>;
  const values = <?= json_encode($trendValues) ?>;
  const grad = (ctx) => {
    const g = ctx.createLinearGradient(0, 0, 0, 260);
    g.addColorStop(0, 'rgba(99,102,241,.9)');
    g.addColorStop(1, 'rgba(139,92,246,.35)');
    return g;
  };
  new Chart(document.getElementById('trendChart'), {
    type: 'bar',
    data: { labels, datasets: [{ data: values, backgroundColor: (c) => grad(c.chart.ctx), borderRadius: 8, maxBarThickness: 40 }] },
    options: {
      plugins:{legend:{display:false}, tooltip:{callbacks:{label:(c)=>'<?= APP_CURRENCY_SYMBOL ?>'+c.parsed.y.toLocaleString('en-IN')}}},
      scales:{y:{beginAtZero:true, ticks:{callback:(v)=>'<?= APP_CURRENCY_SYMBOL ?>'+v.toLocaleString('en-IN')}, grid:{color:'#EEF0F7'}}, x:{grid:{display:false}}},
      maintainAspectRatio:false
    }
  });
</script>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

<?php
require_once __DIR__ . '/../config/app.php';
require_shop_user();

$shopId = current_shop_id();
$u = current_user();
$name  = $u['name'] ?? '';
$first = trim(strtok($name, ' ')) ?: $name;
$hour  = (int)date('G');
$greet = $hour < 12 ? 'Good morning' : ($hour < 17 ? 'Good afternoon' : 'Good evening');

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

<div class="row g-3">
  <?php $cards = [
    ["Today's sales",    '—', 'cash-coin',        '#EEF2FF', '#4F46E5'],
    ['Collected today',  '—', 'wallet2',          '#D1FAE5', '#047857'],
    ['Outstanding dues', '—', 'exclamation-circle','#FEE2E2', '#B91C1C'],
    ['Low-stock items',  '—', 'box-seam',         '#FEF3C7', '#B45309'],
  ]; foreach ($cards as [$label, $v, $icon, $bg, $fg]): ?>
    <div class="col-sm-6 col-xl-3">
      <div class="sb-stat">
        <div class="sb-stat-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;"><i class="bi bi-<?= $icon ?>"></i></div>
        <div>
          <div class="sb-stat-value"><?= e($v) ?></div>
          <div class="sb-stat-label"><?= e($label) ?></div>
        </div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<div class="card mt-4">
  <div class="card-body">
    <h5 class="mb-2">Your workspace is ready.</h5>
    <p class="text-muted mb-0">
      Billing, products, customers and reports arrive in the next phases.
      In the meantime, visit <a href="<?= url('client/settings/') ?>">Settings</a> to configure your receipt, UPI and tax,
      or <a href="<?= url('client/staff/') ?>">Staff</a> to add team members.
    </p>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

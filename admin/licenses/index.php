<?php
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

$filter = $_GET['filter'] ?? 'all';
$sql = "
  SELECT s.id AS shop_id, s.name AS shop_name, s.status AS shop_status,
         sl.id AS license_id, sl.start_date, sl.expiry_date, sl.status AS lic_status,
         p.name AS plan_name, p.fee, p.duration_days,
         DATEDIFF(sl.expiry_date, CURDATE()) AS days_left
  FROM shops s
  LEFT JOIN shop_licenses sl ON sl.shop_id = s.id
  LEFT JOIN subscription_plans p ON p.id = sl.plan_id
";
switch ($filter) {
  case 'expired':      $sql .= " WHERE sl.expiry_date < CURDATE()"; break;
  case 'expiring':     $sql .= " WHERE sl.expiry_date BETWEEN CURDATE() AND CURDATE() + INTERVAL 7 DAY"; break;
  case 'none':         $sql .= " WHERE sl.id IS NULL"; break;
  case 'active':       $sql .= " WHERE sl.expiry_date >= CURDATE()"; break;
}
$sql .= " ORDER BY sl.expiry_date IS NULL, sl.expiry_date ASC";
$rows = db()->query($sql)->fetchAll();

$pageTitle = 'Licences';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Licences</h1>
    <p class="sb-page-subtitle">All shop licences across the platform.</p>
  </div>
</div>

<div class="btn-group mb-3" role="group">
  <?php foreach ([['all','All'],['active','Active'],['expiring','Expiring in 7d'],['expired','Expired'],['none','No licence']] as [$f,$label]): ?>
    <a class="btn btn-outline-secondary <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $label ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[3,"asc"]]' data-nosort="6">
      <thead><tr><th>Shop</th><th>Plan</th><th>Start</th><th>Expires</th><th>Days left</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $isExpired = $r['expiry_date'] && $r['expiry_date'] < date('Y-m-d');
          $daysLeft  = $r['days_left'];
      ?>
        <tr>
          <td><strong><?= e($r['shop_name']) ?></strong>
            <?php if ($r['shop_status'] !== 'active'): ?>
              <span class="badge-pill badge-partial ms-1"><?= e(ucfirst($r['shop_status'])) ?></span>
            <?php endif; ?>
          </td>
          <td><?= e($r['plan_name'] ?? '—') ?></td>
          <td data-order="<?= e($r['start_date'] ?? '') ?>"><?= $r['start_date'] ? e(date('d M Y', strtotime($r['start_date']))) : '—' ?></td>
          <td class="<?= $isExpired ? 'text-danger fw-semibold' : '' ?>" data-order="<?= e($r['expiry_date'] ?? '') ?>">
            <?= $r['expiry_date'] ? e(date('d M Y', strtotime($r['expiry_date']))) : '—' ?>
          </td>
          <td data-order="<?= $daysLeft === null ? -99999 : (int)$daysLeft ?>">
            <?php if ($daysLeft === null): ?>—
            <?php elseif ($daysLeft < 0): ?><span class="text-danger"><?= abs($daysLeft) ?>d overdue</span>
            <?php elseif ($daysLeft <= 7): ?><span class="text-warning"><?= $daysLeft ?>d</span>
            <?php else: ?><?= $daysLeft ?>d
            <?php endif; ?>
          </td>
          <td>
            <?php if (!$r['license_id']): ?>
              <span class="badge-pill badge-unpaid">None</span>
            <?php elseif ($isExpired): ?>
              <span class="badge-pill badge-unpaid">Expired</span>
            <?php else: ?>
              <span class="badge-pill badge-paid">Active</span>
            <?php endif; ?>
          </td>
          <td class="text-end"><a href="<?= url('admin/clients/view.php?id=' . (int)$r['shop_id']) ?>" class="btn btn-sm btn-outline-primary">Manage</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

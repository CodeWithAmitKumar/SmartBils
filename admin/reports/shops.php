<?php
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

$rows = db()->query("
    SELECT s.id, s.name, s.status, s.phone, s.logo_path, s.created_at,
           sl.expiry_date, p.name AS plan_name,
           (SELECT COUNT(*) FROM shop_users WHERE shop_id = s.id) AS user_count,
           COALESCE((SELECT SUM(lr.amount) FROM license_renewals lr
                     JOIN shop_licenses sl2 ON sl2.id = lr.shop_license_id
                     WHERE sl2.shop_id = s.id), 0) AS revenue
    FROM shops s
    LEFT JOIN shop_licenses sl ON sl.shop_id = s.id
    LEFT JOIN subscription_plans p ON p.id = sl.plan_id
    ORDER BY revenue DESC, s.created_at DESC
")->fetchAll();

$totalRevenue = array_sum(array_column($rows, 'revenue'));

$pageTitle = 'Shop Report';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Shop Report</h1>
    <p class="sb-page-subtitle"><?= count($rows) ?> shops · <?= money($totalRevenue) ?> total revenue</p>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[5,"desc"]]' data-nosort="6">
      <thead><tr>
        <th>Shop</th><th>Plan</th><th>Users</th><th>Licence expiry</th><th>Status</th><th class="sb-money">Revenue</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($rows as $r):
          $badge = ['active'=>'badge-paid','suspended'=>'badge-partial','deactivated'=>'badge-unpaid'][$r['status']];
          $isExpired = $r['expiry_date'] && $r['expiry_date'] < date('Y-m-d');
          $logoUrl = image_url($r['logo_path'] ?? null);
      ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="sb-shop-logo-sm">
                <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="">
                <?php else: ?><span><?= e(mb_strtoupper(mb_substr($r['name'],0,1))) ?></span><?php endif; ?>
              </div>
              <div><strong><?= e($r['name']) ?></strong>
                <?php if ($r['phone']): ?><div class="small text-muted"><?= e($r['phone']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= e($r['plan_name'] ?? '—') ?></td>
          <td data-order="<?= (int)$r['user_count'] ?>"><?= (int)$r['user_count'] ?></td>
          <td class="<?= $isExpired ? 'text-danger fw-semibold' : '' ?>" data-order="<?= e($r['expiry_date'] ?? '') ?>">
            <?= $r['expiry_date'] ? e(date('d M Y', strtotime($r['expiry_date']))) : '—' ?>
          </td>
          <td data-order="<?= e($r['status']) ?>"><span class="badge-pill <?= $badge ?>"><?= e(ucfirst($r['status'])) ?></span></td>
          <td class="sb-money" data-order="<?= (float)$r['revenue'] ?>"><strong><?= money($r['revenue']) ?></strong></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('admin/clients/view.php?id=' . (int)$r['id']) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

<?php
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

$shops = db()->query("
    SELECT s.*, sl.expiry_date, sl.status AS lic_status, p.name AS plan_name,
           (SELECT COUNT(*) FROM shop_users WHERE shop_id = s.id) AS user_count
    FROM shops s
    LEFT JOIN shop_licenses sl ON sl.shop_id = s.id
    LEFT JOIN subscription_plans p ON p.id = sl.plan_id
    ORDER BY s.created_at DESC
")->fetchAll();

$pageTitle = 'Shops';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Shops</h1>
    <p class="sb-page-subtitle"><?= count($shops) ?> total</p>
  </div>
  <a href="<?= url('admin/clients/form.php') ?>" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i> Add Shop
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[4,"desc"]]' data-nosort="7">
      <thead><tr>
        <th>Shop</th><th>Owner</th><th>Plan</th><th>Expires</th><th>Created</th><th>Status</th><th>Users</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($shops as $s):
          $badge = ['active'=>'badge-paid','suspended'=>'badge-partial','deactivated'=>'badge-unpaid'][$s['status']] ?? 'badge-pending';
          $isExpired = !empty($s['expiry_date']) && $s['expiry_date'] < date('Y-m-d');
          $logoUrl = image_url($s['logo_path'] ?? null);
      ?>
        <tr>
          <td>
            <div class="d-flex align-items-center gap-2">
              <div class="sb-shop-logo-sm">
                <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="">
                <?php else: ?><span><?= e(mb_strtoupper(mb_substr($s['name'], 0, 1))) ?></span><?php endif; ?>
              </div>
              <div>
                <strong><?= e($s['name']) ?></strong>
                <?php if ($s['phone']): ?><div class="small text-muted"><?= e($s['phone']) ?></div><?php endif; ?>
              </div>
            </div>
          </td>
          <td><?= e($s['owner_name'] ?? '—') ?></td>
          <td><?= e($s['plan_name'] ?? '—') ?></td>
          <td class="<?= $isExpired ? 'text-danger fw-semibold' : '' ?>"
              data-order="<?= e($s['expiry_date'] ?? '') ?>">
            <?= $s['expiry_date'] ? e(date('d M Y', strtotime($s['expiry_date']))) : '—' ?>
            <?php if ($isExpired): ?><br><small>expired</small><?php endif; ?>
          </td>
          <td data-order="<?= e($s['created_at']) ?>"><?= e(date('d M Y', strtotime($s['created_at']))) ?></td>
          <td><span class="badge-pill <?= $badge ?>"><?= e(ucfirst($s['status'])) ?></span></td>
          <td><?= (int)$s['user_count'] ?></td>
          <td class="text-end text-nowrap">
            <a href="<?= url('admin/clients/view.php?id=' . (int)$s['id']) ?>" class="btn btn-sm btn-outline-primary">View</a>
            <a href="<?= url('admin/clients/form.php?id=' . (int)$s['id']) ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
            <form method="post" action="<?= url('admin/clients/action.php') ?>" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="shop_id" value="<?= (int)$s['id'] ?>">
              <input type="hidden" name="op" value="delete">
              <button class="btn btn-sm btn-outline-danger"
                      data-confirm="Delete '<?= e($s['name']) ?>' permanently?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

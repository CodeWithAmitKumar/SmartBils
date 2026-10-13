<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('staff.manage');

$shopId = current_shop_id();

$rows = db()->prepare("
    SELECT su.id, su.name, su.username, su.email, su.role, su.is_active, su.last_login_at,
           (SELECT COUNT(*) FROM shop_user_permissions WHERE shop_user_id = su.id) AS perm_count
    FROM shop_users su
    WHERE su.shop_id = :sid
    ORDER BY su.role = 'owner' DESC, su.id ASC
");
$rows->execute([':sid' => $shopId]);
$users = $rows->fetchAll();

$pageTitle = 'Staff';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Staff</h1>
    <p class="sb-page-subtitle"><?= count($users) ?> account<?= count($users) === 1 ? '' : 's' ?></p>
  </div>
  <a href="<?= url('client/staff/form.php') ?>" class="btn btn-primary">
    <i class="bi bi-person-plus me-1"></i>Add staff
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-nosort="6">
      <thead><tr>
        <th>Name</th><th>Username</th><th>Role</th><th>Permissions</th><th>Active</th><th>Last login</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($users as $u):
          $isOwner = $u['role'] === 'owner';
          $isSelf  = (int)current_user()['id'] === (int)$u['id'];
      ?>
        <tr>
          <td>
            <strong><?= e($u['name']) ?></strong>
            <?php if ($isSelf): ?><span class="small text-muted">(you)</span><?php endif; ?>
            <?php if ($u['email']): ?><div class="small text-muted"><?= e($u['email']) ?></div><?php endif; ?>
          </td>
          <td><code><?= e($u['username']) ?></code></td>
          <td data-order="<?= e($u['role']) ?>">
            <span class="badge-pill <?= $isOwner ? 'badge-paid' : 'badge-partial' ?>">
              <?= $isOwner ? 'Owner' : 'Staff' ?>
            </span>
          </td>
          <td data-order="<?= (int)$u['perm_count'] ?>">
            <?= $isOwner ? '<span class="text-muted">All</span>' : (int)$u['perm_count'] . ' granted' ?>
          </td>
          <td data-order="<?= (int)$u['is_active'] ?>">
            <?= $u['is_active'] ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>' ?>
          </td>
          <td class="text-muted" data-order="<?= e($u['last_login_at'] ?? '') ?>">
            <?= $u['last_login_at'] ? e(date('d M Y H:i', strtotime($u['last_login_at']))) : '—' ?>
          </td>
          <td class="text-end text-nowrap">
            <?php if (!$isOwner): ?>
              <a href="<?= url('client/staff/form.php?id=' . (int)$u['id']) ?>" class="btn btn-sm btn-outline-secondary">Edit</a>
              <form method="post" action="<?= url('client/staff/action.php') ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="op" value="reset_password">
                <button class="btn btn-sm btn-outline-primary" data-confirm="Reset this user's password?">
                  <i class="bi bi-key"></i>
                </button>
              </form>
              <form method="post" action="<?= url('client/staff/action.php') ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="op" value="toggle_active">
                <button class="btn btn-sm btn-outline-warning"
                        data-confirm="<?= $u['is_active'] ? 'Deactivate' : 'Activate' ?> this user?">
                  <?= $u['is_active'] ? 'Deactivate' : 'Activate' ?>
                </button>
              </form>
              <form method="post" action="<?= url('client/staff/action.php') ?>" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="op" value="delete">
                <button class="btn btn-sm btn-outline-danger"
                        data-confirm="Delete <?= e($u['name']) ?> permanently?">Delete</button>
              </form>
            <?php else: ?>
              <span class="small text-muted">Owner account</span>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

<?php
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Shop not found.'); }

$stmt = db()->prepare('SELECT * FROM shops WHERE id = :id');
$stmt->execute([':id' => $id]);
$shop = $stmt->fetch();
if (!$shop) { http_response_code(404); exit('Shop not found.'); }

$lic = db()->prepare("
    SELECT sl.*, p.name AS plan_name, p.fee, p.duration_days
    FROM shop_licenses sl
    JOIN subscription_plans p ON p.id = sl.plan_id
    WHERE sl.shop_id = :id ORDER BY sl.id DESC LIMIT 1");
$lic->execute([':id' => $id]);
$license = $lic->fetch();

$renewals = [];
if ($license) {
    $r = db()->prepare("
        SELECT lr.*, p.name AS plan_name, a.name AS admin_name
        FROM license_renewals lr
        JOIN subscription_plans p ON p.id = lr.plan_id
        LEFT JOIN admins a ON a.id = lr.renewed_by
        WHERE lr.shop_license_id = :sl ORDER BY lr.id DESC");
    $r->execute([':sl' => $license['id']]);
    $renewals = $r->fetchAll();
}

$users = db()->prepare('SELECT id, name, username, role, is_active, last_login_at FROM shop_users WHERE shop_id = :id ORDER BY role, id');
$users->execute([':id' => $id]);
$users = $users->fetchAll();

$plans = db()->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY fee ASC")->fetchAll();

$isExpired = $license && $license['expiry_date'] < date('Y-m-d');
$badge = ['active'=>'badge-paid','suspended'=>'badge-partial','deactivated'=>'badge-unpaid'][$shop['status']];

$pageTitle = 'Shop · ' . $shop['name'];
require __DIR__ . '/../../includes/layout-top.php';
?>
<?php $logoUrl = image_url($shop['logo_path'] ?? null); ?>
<div class="sb-page-header">
  <div class="d-flex align-items-center gap-3">
    <div class="sb-shop-logo-lg">
      <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="">
      <?php else: ?><span><?= e(mb_strtoupper(mb_substr($shop['name'], 0, 1))) ?></span><?php endif; ?>
    </div>
    <div>
      <h1 class="sb-page-title mb-1"><?= e($shop['name']) ?></h1>
      <p class="sb-page-subtitle">
        <span class="badge-pill <?= $badge ?>"><?= e(ucfirst($shop['status'])) ?></span>
        · Created <?= e(date('d M Y', strtotime($shop['created_at']))) ?>
      </p>
    </div>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= url('admin/clients/form.php?id=' . $id) ?>" class="btn btn-outline-primary">
      <i class="bi bi-pencil me-1"></i>Edit
    </a>

    <form method="post" action="<?= url('admin/clients/action.php') ?>" class="d-inline">
      <?= csrf_field() ?>
      <input type="hidden" name="shop_id" value="<?= $id ?>">
      <input type="hidden" name="op" value="delete">
      <button class="btn btn-outline-danger"
              data-confirm="Permanently delete '<?= e($shop['name']) ?>' and all its users/licences? This cannot be undone.">
        <i class="bi bi-trash me-1"></i>Delete
      </button>
    </form>

    <?php if ($shop['status'] === 'active'): ?>
      <form method="post" action="<?= url('admin/clients/action.php') ?>" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="shop_id" value="<?= $id ?>">
        <input type="hidden" name="op" value="set_status">
        <input type="hidden" name="status" value="deactivated">
        <button class="btn btn-outline-warning" data-confirm="Deactivate this shop? Users can no longer log in.">
          <i class="bi bi-slash-circle me-1"></i>Deactivate
        </button>
      </form>
    <?php else: ?>
      <form method="post" action="<?= url('admin/clients/action.php') ?>" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="shop_id" value="<?= $id ?>">
        <input type="hidden" name="op" value="set_status">
        <input type="hidden" name="status" value="active">
        <button class="btn btn-outline-success" data-confirm="Activate this shop?">
          <i class="bi bi-check2-circle me-1"></i>Activate
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>

<div class="row g-3">
  <!-- Details -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Details</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4 text-muted">Owner</dt>  <dd class="col-sm-8"><?= e($shop['owner_name'] ?? '—') ?></dd>
          <dt class="col-sm-4 text-muted">Phone</dt>  <dd class="col-sm-8"><?= e($shop['phone'] ?? '—') ?></dd>
          <dt class="col-sm-4 text-muted">Email</dt>  <dd class="col-sm-8"><?= e($shop['email'] ?? '—') ?></dd>
          <dt class="col-sm-4 text-muted">Address</dt><dd class="col-sm-8"><?= nl2br(e($shop['address'] ?? '—')) ?></dd>
        </dl>
      </div>
    </div>
  </div>

  <!-- Licence -->
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Licence</span>
        <?php if ($isExpired): ?><span class="badge-pill badge-unpaid">Expired</span><?php endif; ?>
      </div>
      <div class="card-body">
        <?php if (!$license): ?>
          <p class="text-muted mb-0">No licence yet.</p>
        <?php else: ?>
          <dl class="row mb-0">
            <dt class="col-sm-4 text-muted">Plan</dt>  <dd class="col-sm-8"><?= e($license['plan_name']) ?> (<?= money($license['fee']) ?> / <?= (int)$license['duration_days'] ?>d)</dd>
            <dt class="col-sm-4 text-muted">Start</dt> <dd class="col-sm-8"><?= e(date('d M Y', strtotime($license['start_date']))) ?></dd>
            <dt class="col-sm-4 text-muted">Expires</dt><dd class="col-sm-8 <?= $isExpired ? 'text-danger fw-semibold' : '' ?>"><?= e(date('d M Y', strtotime($license['expiry_date']))) ?></dd>
          </dl>
        <?php endif; ?>
      </div>
      <div class="card-footer">
        <form method="post" action="<?= url('admin/clients/action.php') ?>" class="row g-2 js-once">
          <?= csrf_field() ?>
          <input type="hidden" name="shop_id" value="<?= $id ?>">
          <input type="hidden" name="op" value="renew_license">
          <div class="col-sm-6">
            <select class="form-select form-select-sm" name="plan_id" id="sbRenewPlan" required
                    data-fees='<?= e(json_encode(array_column($plans, 'fee', 'id'))) ?>'>
              <option value="">Plan…</option>
              <?php foreach ($plans as $pl): ?>
                <option value="<?= (int)$pl['id'] ?>"><?= e($pl['name']) ?> (<?= money($pl['fee']) ?>/<?= (int)$pl['duration_days'] ?>d)</option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-sm-6 d-flex gap-2">
            <input type="number" step="0.01" class="form-control form-control-sm"
                   name="amount" id="sbRenewAmount" placeholder="Amount paid" value="0" required>
            <button class="btn btn-sm btn-primary"><?= $license ? 'Renew' : 'Create' ?></button>
          </div>
        </form>
        <script>
          (() => {
            const plan = document.getElementById('sbRenewPlan');
            const amt  = document.getElementById('sbRenewAmount');
            if (!plan || !amt) return;
            const fees = JSON.parse(plan.dataset.fees || '{}');
            plan.addEventListener('change', () => { const f = fees[plan.value]; if (f !== undefined) amt.value = f; });
          })();
        </script>
      </div>
    </div>
  </div>

  <!-- Users -->
  <div class="col-12">
    <div class="card">
      <div class="card-header">Users (<?= count($users) ?>)</div>
      <div class="table-responsive">
        <table class="table sb-datatable" data-nosort="5" data-page="10">
          <thead><tr><th>Name</th><th>Username</th><th>Role</th><th>Active</th><th>Last login</th><th></th></tr></thead>
          <tbody>
          <?php foreach ($users as $u): ?>
            <tr>
              <td><?= e($u['name']) ?></td>
              <td><code><?= e($u['username']) ?></code></td>
              <td><?= e(ucfirst($u['role'])) ?></td>
              <td><?= $u['is_active'] ? '<span class="text-success">Yes</span>' : '<span class="text-muted">No</span>' ?></td>
              <td class="text-muted"><?= $u['last_login_at'] ? e(date('d M Y H:i', strtotime($u['last_login_at']))) : '—' ?></td>
              <td class="text-end">
                <form method="post" action="<?= url('admin/clients/action.php') ?>" class="d-inline">
                  <?= csrf_field() ?>
                  <input type="hidden" name="shop_id" value="<?= $id ?>">
                  <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                  <input type="hidden" name="op" value="reset_password">
                  <button class="btn btn-sm btn-outline-primary" data-confirm="Reset this user's password?">
                    <i class="bi bi-key"></i> Reset password
                  </button>
                </form>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Renewal history -->
  <?php if ($renewals): ?>
  <div class="col-12">
    <div class="card">
      <div class="card-header">Licence renewal history</div>
      <div class="table-responsive">
        <table class="table sb-datatable" data-order='[[0,"desc"]]'>
          <thead><tr><th>Date</th><th>Plan</th><th>Prev expiry</th><th>New expiry</th><th class="sb-money">Amount</th><th>By</th></tr></thead>
          <tbody>
          <?php foreach ($renewals as $r): ?>
            <tr>
              <td data-order="<?= e($r['renewed_at']) ?>"><?= e(date('d M Y H:i', strtotime($r['renewed_at']))) ?></td>
              <td><?= e($r['plan_name']) ?></td>
              <td data-order="<?= e($r['previous_expiry']) ?>"><?= e(date('d M Y', strtotime($r['previous_expiry']))) ?></td>
              <td data-order="<?= e($r['new_expiry']) ?>"><?= e(date('d M Y', strtotime($r['new_expiry']))) ?></td>
              <td class="sb-money" data-order="<?= (float)$r['amount'] ?>"><?= money($r['amount']) ?></td>
              <td class="text-muted"><?= e($r['admin_name'] ?? '—') ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <?php endif; ?>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

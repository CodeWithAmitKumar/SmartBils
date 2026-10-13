<?php
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $op = $_POST['op'] ?? '';
    try {
        if ($op === 'save') {
            $id       = (int)($_POST['id'] ?? 0);
            $name     = trim((string)($_POST['name'] ?? ''));
            $fee      = (float)($_POST['fee'] ?? 0);
            $duration = (int)($_POST['duration_days'] ?? 0);
            $active   = isset($_POST['is_active']) ? 1 : 0;

            if ($name === '')                 throw new RuntimeException('Plan name is required.');
            if ($fee < 0)                     throw new RuntimeException('Fee cannot be negative.');
            if ($duration < 1)                throw new RuntimeException('Duration must be at least 1 day.');

            if ($id > 0) {
                db()->prepare('UPDATE subscription_plans SET name=:n, fee=:f, duration_days=:d, is_active=:a WHERE id=:id')
                    ->execute([':n'=>$name, ':f'=>$fee, ':d'=>$duration, ':a'=>$active, ':id'=>$id]);
                audit('plan.update', 'plan', $id, ['name'=>$name]);
                flash('success', 'Plan updated.');
            } else {
                db()->prepare('INSERT INTO subscription_plans (name, fee, duration_days, is_active) VALUES (:n,:f,:d,:a)')
                    ->execute([':n'=>$name, ':f'=>$fee, ':d'=>$duration, ':a'=>$active]);
                audit('plan.create', 'plan', (int)db()->lastInsertId(), ['name'=>$name]);
                flash('success', 'Plan created.');
            }
        } elseif ($op === 'toggle') {
            $id = (int)($_POST['id'] ?? 0);
            db()->prepare('UPDATE subscription_plans SET is_active = 1 - is_active WHERE id = :id')
                ->execute([':id' => $id]);
            audit('plan.toggle', 'plan', $id);
            flash('success', 'Plan toggled.');
        }
    } catch (Throwable $e) {
        flash('danger', $e->getMessage());
    }
    redirect('admin/plans/');
}

$plans = db()->query("
    SELECT p.*, (SELECT COUNT(*) FROM shop_licenses WHERE plan_id = p.id) AS usage_count
    FROM subscription_plans p
    ORDER BY p.is_active DESC, p.fee ASC")->fetchAll();

$edit = null;
if (!empty($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM subscription_plans WHERE id = :id');
    $s->execute([':id' => (int)$_GET['edit']]);
    $edit = $s->fetch();
}

$pageTitle = 'Subscription Plans';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Subscription Plans</h1>
    <p class="sb-page-subtitle">Define the plans you sell to shops.</p>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><?= $edit ? 'Edit plan' : 'Add plan' ?></div>
  <div class="card-body">
    <form method="post" class="js-once">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" value="<?= e($edit['name'] ?? '') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Fee (<?= APP_CURRENCY_SYMBOL ?>)</label>
          <input class="form-control" type="number" step="0.01" min="0" name="fee" value="<?= e($edit['fee'] ?? '0') ?>" required>
        </div>
        <div class="col-md-3">
          <label class="form-label">Duration (days)</label>
          <input class="form-control" type="number" min="1" name="duration_days" value="<?= e($edit['duration_days'] ?? '30') ?>" required>
        </div>
        <div class="col-md-2 d-flex align-items-end">
          <div class="form-check mb-2">
            <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1" <?= (!$edit || $edit['is_active']) ? 'checked' : '' ?>>
            <label class="form-check-label" for="is_active">Active</label>
          </div>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary"><?= $edit ? 'Save changes' : 'Add plan' ?></button>
        <?php if ($edit): ?><a href="<?= url('admin/plans/') ?>" class="btn btn-outline-secondary">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Plans</div>
  <div class="table-responsive">
    <table class="table sb-datatable" data-nosort="5">
      <thead><tr><th>Name</th><th class="sb-money">Fee</th><th>Duration</th><th>Used by</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($plans as $p): ?>
        <tr>
          <td><strong><?= e($p['name']) ?></strong></td>
          <td class="sb-money" data-order="<?= e($p['fee']) ?>"><?= money($p['fee']) ?></td>
          <td data-order="<?= (int)$p['duration_days'] ?>"><?= (int)$p['duration_days'] ?> days</td>
          <td data-order="<?= (int)$p['usage_count'] ?>"><?= (int)$p['usage_count'] ?> shops</td>
          <td data-order="<?= (int)$p['is_active'] ?>">
            <?php if ($p['is_active']): ?>
              <span class="badge-pill badge-paid">Active</span>
            <?php else: ?>
              <span class="badge-pill badge-unpaid">Inactive</span>
            <?php endif; ?>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$p['id'] ?>">Edit</a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?>
              <input type="hidden" name="op" value="toggle">
              <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
              <button class="btn btn-sm btn-outline-primary">
                <?= $p['is_active'] ? 'Deactivate' : 'Activate' ?>
              </button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

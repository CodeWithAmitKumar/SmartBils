<?php
require_once __DIR__ . '/../config/app.php';
require_super_admin();

$id = (int)current_user()['id'];
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $current = (string)($_POST['current_password'] ?? '');
    $new     = (string)($_POST['new_password'] ?? '');
    $confirm = (string)($_POST['confirm_password'] ?? '');

    $hash = db()->prepare('SELECT password_hash FROM admins WHERE id = :id');
    $hash->execute([':id' => $id]);
    $h = $hash->fetchColumn();

    if (!$h || !password_verify($current, $h)) $errors[] = 'Current password is incorrect.';
    if (strlen($new) < 8)                      $errors[] = 'New password must be at least 8 characters.';
    if ($new !== $confirm)                     $errors[] = 'New passwords do not match.';
    if ($new === $current && !$errors)         $errors[] = 'New password must differ from the current one.';

    if (!$errors) {
        db()->prepare('UPDATE admins SET password_hash = :p WHERE id = :id')
            ->execute([':p' => password_hash($new, PASSWORD_DEFAULT), ':id' => $id]);
        audit('admin.password_change', 'admin', $id);
        flash('success', 'Password changed. Sign in again on other devices if needed.');
        redirect('admin/profile.php');
    }
}

$pageTitle = 'Change Password';
require __DIR__ . '/../includes/layout-top.php';
?>
<div class="sb-page-header">
  <h1 class="sb-page-title">Change Password</h1>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="card">
  <div class="card-body">
    <form method="post" class="js-once" autocomplete="off">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Current password</label>
          <input class="form-control" type="password" name="current_password" required autofocus>
        </div>
        <div class="col-md-4">
          <label class="form-label">New password <small class="text-muted">(≥ 8 chars)</small></label>
          <input class="form-control" type="password" name="new_password" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Confirm new password</label>
          <input class="form-control" type="password" name="confirm_password" required>
        </div>
      </div>
      <div class="mt-3 d-flex gap-2">
        <button class="btn btn-primary"><i class="bi bi-shield-check me-1"></i>Change password</button>
        <a class="btn btn-outline-secondary" href="<?= url('admin/profile.php') ?>">Cancel</a>
      </div>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

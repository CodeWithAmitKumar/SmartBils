<?php
require_once __DIR__ . '/../config/app.php';
require_super_admin();

$u = current_user();
$id = (int)$u['id'];
$errors = [];

$stmt = db()->prepare('SELECT * FROM admins WHERE id = :id');
$stmt->execute([':id' => $id]);
$row = $stmt->fetch();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $name     = trim((string)($_POST['name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    if ($name === '')     $errors[] = 'Name is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

    if (!$errors) {
        $d = db()->prepare('SELECT COUNT(*) FROM admins WHERE username = :u AND id <> :id');
        $d->execute([':u' => $username, ':id' => $id]);
        if ((int)$d->fetchColumn() > 0) $errors[] = 'Username is already taken.';
    }

    if (!$errors) {
        db()->prepare('UPDATE admins SET name=:n, username=:u, email=:e WHERE id=:id')
            ->execute([':n'=>$name, ':u'=>$username, ':e'=>$email ?: null, ':id'=>$id]);
        $_SESSION['user']['name']     = $name;
        $_SESSION['user']['username'] = $username;
        $_SESSION['user']['email']    = $email;
        audit('admin.profile_update', 'admin', $id);
        flash('success', 'Profile updated.');
        redirect('admin/profile.php');
    }
}

$pageTitle = 'My Profile';
require __DIR__ . '/../includes/layout-top.php';
?>
<div class="sb-page-header">
  <h1 class="sb-page-title">My Profile</h1>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Account details</span>
    <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/change-password.php') ?>">
      <i class="bi bi-key me-1"></i>Change password
    </a>
  </div>
  <div class="card-body">
    <form method="post" class="js-once">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Name</label>
          <input class="form-control" name="name" value="<?= e($_POST['name'] ?? $row['name']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Username</label>
          <input class="form-control" name="username" value="<?= e($_POST['username'] ?? $row['username']) ?>" required>
        </div>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" value="<?= e($_POST['email'] ?? $row['email']) ?>">
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Save</button>
    </form>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

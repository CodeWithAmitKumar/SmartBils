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

    // Avatar handling
    $newAvatarPath = null;
    $removeAvatar  = !empty($_POST['avatar_remove']);
    if (!$errors && !empty($_FILES['avatar']['name'])) {
        try { $newAvatarPath = image_store($_FILES['avatar'], 'uploads/admins', $id); }
        catch (Throwable $e) { $errors[] = 'Avatar: ' . $e->getMessage(); }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();
            $oldAvatar = $row['avatar_path'] ?? null;
            $avatarValue = $oldAvatar;
            if ($newAvatarPath)      $avatarValue = $newAvatarPath;
            elseif ($removeAvatar)   $avatarValue = null;

            db()->prepare('UPDATE admins SET name=:n, username=:u, email=:e, avatar_path=:a WHERE id=:id')
                ->execute([':n'=>$name, ':u'=>$username, ':e'=>$email ?: null, ':a'=>$avatarValue, ':id'=>$id]);
            db()->commit();

            if (($newAvatarPath || $removeAvatar) && !empty($oldAvatar)) image_delete($oldAvatar);

            $_SESSION['user']['name']        = $name;
            $_SESSION['user']['username']    = $username;
            $_SESSION['user']['email']       = $email;
            $_SESSION['user']['avatar_path'] = $avatarValue;

            audit('admin.profile_update', 'admin', $id);
            flash('success', 'Profile updated.');
            redirect('admin/profile.php');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            if ($newAvatarPath) image_delete($newAvatarPath);
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    } elseif ($newAvatarPath) {
        image_delete($newAvatarPath);
    }
}

$avatarUrl = image_url($row['avatar_path'] ?? null);

$pageTitle = 'My Profile';
require __DIR__ . '/../includes/layout-top.php';
?>
<div class="sb-page-header">
  <h1 class="sb-page-title">My Profile</h1>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="card">
  <div class="card-header d-flex justify-content-between align-items-center">
    <span>Account details</span>
    <a class="btn btn-sm btn-outline-primary" href="<?= url('admin/change-password.php') ?>">
      <i class="bi bi-key me-1"></i>Change password
    </a>
  </div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="js-once">
      <?= csrf_field() ?>

      <!-- Avatar -->
      <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
        <div class="sb-logo-preview" id="avatarPreview" style="width:88px; height:88px; border-radius:50%;">
          <?php if ($avatarUrl): ?>
            <img src="<?= e($avatarUrl) ?>" alt="Avatar" style="border-radius:50%;">
          <?php else: ?>
            <span style="font-size:34px;"><i class="bi bi-person-circle"></i></span>
          <?php endif; ?>
        </div>
        <div class="flex-grow-1">
          <input type="file" class="form-control" id="avatarInput" name="avatar"
                 accept="image/jpeg,image/png,image/webp">
          <div class="form-text">Max 500 KB — auto-resized. Shown as your avatar in the top navbar.</div>
          <?php if (!empty($row['avatar_path'])): ?>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="avatar_remove" name="avatar_remove" value="1">
              <label class="form-check-label" for="avatar_remove">Remove current avatar</label>
            </div>
          <?php endif; ?>
        </div>
      </div>

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

<script>
  const inp = document.getElementById('avatarInput');
  const prev = document.getElementById('avatarPreview');
  inp?.addEventListener('change', () => {
    const f = inp.files?.[0]; if (!f) return;
    prev.innerHTML = '<img src="' + URL.createObjectURL(f) + '" alt="" style="border-radius:50%;">';
  });
</script>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

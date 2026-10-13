<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('staff.manage');

$shopId = current_shop_id();
$id   = (int)($_GET['id'] ?? 0);
$edit = $id > 0;
$user = null;

if ($edit) {
    $s = db()->prepare("SELECT * FROM shop_users WHERE id = :id AND shop_id = :sid AND role = 'staff'");
    $s->execute([':id' => $id, ':sid' => $shopId]);
    $user = $s->fetch();
    if (!$user) { http_response_code(404); exit('Staff user not found.'); }
}

$perms = db()->query("SELECT * FROM permissions WHERE `key` <> 'staff.manage' ORDER BY `key`")->fetchAll();
$grantedIds = [];
if ($edit) {
    $g = db()->prepare('SELECT permission_id FROM shop_user_permissions WHERE shop_user_id = :uid');
    $g->execute([':uid' => $id]);
    $grantedIds = array_map('intval', array_column($g->fetchAll(), 'permission_id'));
}

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $name     = trim((string)($_POST['name'] ?? ''));
    $username = trim((string)($_POST['username'] ?? ''));
    $email    = trim((string)($_POST['email'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $selected = array_map('intval', $_POST['perms'] ?? []);

    if ($name === '')     $errors[] = 'Name is required.';
    if ($username === '') $errors[] = 'Username is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';
    if (!$edit && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';
    if ($edit && $password !== '' && strlen($password) < 8) $errors[] = 'Password must be at least 8 characters.';

    if (!$errors) {
        $u = db()->prepare('SELECT 1 FROM shop_users WHERE username = :u AND id <> :id');
        $u->execute([':u' => $username, ':id' => $edit ? $id : 0]);
        if ($u->fetchColumn()) $errors[] = 'Username already taken.';
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            if ($edit) {
                $sql = 'UPDATE shop_users SET name=:n, username=:u, email=:e';
                $args = [':n'=>$name, ':u'=>$username, ':e'=>$email ?: null, ':id'=>$id, ':sid'=>$shopId];
                if ($password !== '') { $sql .= ', password_hash=:p'; $args[':p'] = password_hash($password, PASSWORD_DEFAULT); }
                $sql .= ' WHERE id=:id AND shop_id=:sid';
                db()->prepare($sql)->execute($args);
            } else {
                db()->prepare(
                    "INSERT INTO shop_users (shop_id, name, username, email, password_hash, role, is_active)
                     VALUES (:sid, :n, :u, :e, :p, 'staff', 1)"
                )->execute([
                    ':sid' => $shopId, ':n' => $name, ':u' => $username, ':e' => $email ?: null,
                    ':p'   => password_hash($password, PASSWORD_DEFAULT),
                ]);
                $id = (int)db()->lastInsertId();
            }

            // Replace permissions
            db()->prepare('DELETE FROM shop_user_permissions WHERE shop_user_id = :uid')->execute([':uid' => $id]);
            if ($selected) {
                $valid = array_map(fn($p) => (int)$p['id'], $perms);
                $ins = db()->prepare('INSERT IGNORE INTO shop_user_permissions (shop_user_id, permission_id) VALUES (:uid, :pid)');
                foreach ($selected as $pid) {
                    if (in_array($pid, $valid, true)) $ins->execute([':uid' => $id, ':pid' => $pid]);
                }
            }

            db()->commit();
            audit($edit ? 'staff.update' : 'staff.create', 'shop_user', $id, ['name'=>$name], $shopId);
            flash('success', $edit ? 'Staff updated.' : 'Staff created.');
            redirect('client/staff/');
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = $edit ? 'Edit Staff' : 'Add Staff';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title"><?= $edit ? 'Edit Staff' : 'Add Staff' ?></h1>
    <p class="sb-page-subtitle"><?= $edit ? 'Update details and permissions.' : 'Create a staff account for your shop.' ?></p>
  </div>
  <a href="<?= url('client/staff/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="js-once" autocomplete="off">
  <?= csrf_field() ?>

  <div class="card mb-3">
    <div class="card-header">Account details</div>
    <div class="card-body row g-3">
      <div class="col-md-4">
        <label class="form-label">Name *</label>
        <input class="form-control" name="name" value="<?= e($_POST['name'] ?? ($user['name'] ?? '')) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Username *</label>
        <input class="form-control" name="username" value="<?= e($_POST['username'] ?? ($user['username'] ?? '')) ?>" required>
      </div>
      <div class="col-md-4">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" value="<?= e($_POST['email'] ?? ($user['email'] ?? '')) ?>">
      </div>
      <div class="col-md-6">
        <label class="form-label"><?= $edit ? 'New password' : 'Password *' ?>
          <small class="text-muted"><?= $edit ? '(leave blank to keep current)' : '(≥ 8 chars)' ?></small>
        </label>
        <input class="form-control" type="password" name="password" <?= $edit ? '' : 'required' ?>>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Permissions</div>
    <div class="card-body">
      <p class="text-muted small mb-3">Select what this staff member can do. Owner always has all permissions.</p>
      <div class="row g-2">
        <?php foreach ($perms as $p): $checked = in_array((int)$p['id'], $grantedIds, true) || !empty($_POST['perms'][$p['id']]); ?>
          <div class="col-md-6 col-lg-4">
            <div class="form-check">
              <input class="form-check-input" type="checkbox" name="perms[]" value="<?= (int)$p['id'] ?>"
                     id="perm_<?= (int)$p['id'] ?>" <?= $checked ? 'checked' : '' ?>>
              <label class="form-check-label" for="perm_<?= (int)$p['id'] ?>">
                <strong><?= e(ucwords(str_replace(['.', '_'], ' ', $p['key']))) ?></strong>
                <div class="small text-muted"><?= e($p['description']) ?></div>
              </label>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $edit ? 'Save changes' : 'Create staff' ?></button>
    <a href="<?= url('client/staff/') ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

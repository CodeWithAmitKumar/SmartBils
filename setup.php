<?php
/**
 * One-time setup — creates the first Super Admin (and optionally a demo shop).
 * Self-locks after success. DELETE this file + storage/installed.lock before production.
 */
require_once __DIR__ . '/config/app.php';

$lockFile = __DIR__ . '/storage/installed.lock';
$isLocked = is_file($lockFile);

$pageTitle = 'Setup';
$errors = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isLocked) {
    csrf_require();

    $adminName = trim((string)($_POST['admin_name'] ?? ''));
    $adminUser = trim((string)($_POST['admin_username'] ?? ''));
    $adminPass = (string)($_POST['admin_password'] ?? '');
    $adminPass2 = (string)($_POST['admin_password_confirm'] ?? '');

    $makeDemo  = !empty($_POST['make_demo_shop']);
    $shopName  = trim((string)($_POST['shop_name'] ?? ''));
    $ownerName = trim((string)($_POST['owner_name'] ?? ''));
    $ownerUser = trim((string)($_POST['owner_username'] ?? ''));
    $ownerPass = (string)($_POST['owner_password'] ?? '');

    if ($adminName === '' || $adminUser === '' || $adminPass === '') {
        $errors[] = 'Admin name, username and password are required.';
    }
    if (strlen($adminPass) < 8) {
        $errors[] = 'Admin password must be at least 8 characters.';
    }
    if ($adminPass !== $adminPass2) {
        $errors[] = 'Admin passwords do not match.';
    }
    if ($makeDemo) {
        if ($shopName === '' || $ownerName === '' || $ownerUser === '' || strlen($ownerPass) < 8) {
            $errors[] = 'Demo shop fields required (owner password ≥ 8 chars).';
        }
    }

    // Refuse if an admin already exists.
    $existing = (int)db()->query('SELECT COUNT(*) FROM admins')->fetchColumn();
    if ($existing > 0) {
        $errors[] = 'A super admin already exists. Delete setup.php and the lock file to rerun.';
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            db()->prepare(
                'INSERT INTO admins (name, username, password_hash, is_active)
                 VALUES (:n, :u, :p, 1)'
            )->execute([
                ':n' => $adminName,
                ':u' => $adminUser,
                ':p' => password_hash($adminPass, PASSWORD_DEFAULT),
            ]);

            if ($makeDemo) {
                db()->prepare(
                    "INSERT INTO shops (name, owner_name, status) VALUES (:n, :o, 'active')"
                )->execute([':n' => $shopName, ':o' => $ownerName]);
                $shopId = (int)db()->lastInsertId();

                db()->prepare(
                    "INSERT INTO shop_users (shop_id, name, username, password_hash, role, is_active)
                     VALUES (:sid, :n, :u, :p, 'owner', 1)"
                )->execute([
                    ':sid' => $shopId,
                    ':n'   => $ownerName,
                    ':u'   => $ownerUser,
                    ':p'   => password_hash($ownerPass, PASSWORD_DEFAULT),
                ]);
            }

            db()->commit();

            @mkdir(dirname($lockFile), 0775, true);
            file_put_contents($lockFile, date('c') . " installed\n");
            $done = true;
        } catch (Throwable $e) {
            db()->rollBack();
            $errors[] = 'Setup failed: ' . $e->getMessage();
        }
    }
}

require __DIR__ . '/includes/header.php';
?>
<div class="sb-auth-wrap">
  <div class="sb-auth-card" style="max-width:560px;">
    <span class="sb-brand-badge">🛠️</span>
    <h1 class="sb-auth-title"><?= e(APP_NAME) ?> — Setup</h1>
    <p class="sb-auth-sub">One-time initial setup. This page self-locks after success.</p>

    <?php if ($isLocked): ?>
      <div class="alert alert-info">
        <i class="bi bi-lock-fill me-1"></i>
        Setup is locked. Delete <code>storage/installed.lock</code> <strong>and</strong> <code>setup.php</code> to rerun.
      </div>
      <a class="btn btn-primary w-100" href="<?= url('index.php') ?>">Go to login</a>
    <?php elseif ($done): ?>
      <div class="alert alert-success">
        <i class="bi bi-check-circle-fill me-1"></i>
        Setup complete. <strong>Delete this file (<code>setup.php</code>) now</strong> for safety.
      </div>
      <a class="btn btn-dark w-100 mb-2" href="<?= url('admin/') ?>">Super Admin login</a>
      <a class="btn btn-outline-primary w-100" href="<?= url('index.php') ?>">Shop login</a>
    <?php else: ?>
      <?php if ($errors): ?>
        <div class="alert alert-danger">
          <ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul>
        </div>
      <?php endif; ?>

      <form method="post" class="js-once" autocomplete="off">
        <?= csrf_field() ?>
        <h6 class="mt-2 mb-3 text-uppercase text-muted" style="font-size:12px; letter-spacing:.05em;">Super Admin</h6>
        <div class="mb-3">
          <label class="form-label">Name</label>
          <input class="form-control" name="admin_name" value="<?= e($_POST['admin_name'] ?? '') ?>" required>
        </div>
        <div class="row g-2">
          <div class="col-md-6 mb-3">
            <label class="form-label">Username</label>
            <input class="form-control" name="admin_username" value="<?= e($_POST['admin_username'] ?? '') ?>" required>
          </div>
          <div class="col-md-6 mb-3">
            <label class="form-label">Password <small class="text-muted">(≥ 8 chars)</small></label>
            <input type="password" class="form-control" name="admin_password" required>
          </div>
        </div>
        <div class="mb-3">
          <label class="form-label">Confirm password</label>
          <input type="password" class="form-control" name="admin_password_confirm" required>
        </div>

        <div class="form-check mt-3 mb-2">
          <input class="form-check-input" type="checkbox" id="make_demo_shop" name="make_demo_shop" value="1" checked>
          <label class="form-check-label" for="make_demo_shop">Also create a demo shop + owner</label>
        </div>

        <div id="demo-fields" class="border rounded-3 p-3 mb-3 bg-light">
          <div class="mb-2">
            <label class="form-label">Shop name</label>
            <input class="form-control" name="shop_name" value="<?= e($_POST['shop_name'] ?? 'Demo Shop') ?>">
          </div>
          <div class="mb-2">
            <label class="form-label">Owner name</label>
            <input class="form-control" name="owner_name" value="<?= e($_POST['owner_name'] ?? 'Shop Owner') ?>">
          </div>
          <div class="row g-2">
            <div class="col-md-6 mb-2">
              <label class="form-label">Owner username</label>
              <input class="form-control" name="owner_username" value="<?= e($_POST['owner_username'] ?? 'owner') ?>">
            </div>
            <div class="col-md-6 mb-2">
              <label class="form-label">Owner password</label>
              <input type="password" class="form-control" name="owner_password">
            </div>
          </div>
        </div>

        <button class="btn btn-primary w-100 py-2" type="submit">
          <i class="bi bi-rocket-takeoff me-1"></i> Run setup
        </button>
      </form>
    <?php endif; ?>
  </div>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

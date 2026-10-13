<?php
// Create or edit a shop. ?id=X for edit.
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

$id   = (int)($_GET['id'] ?? 0);
$edit = $id > 0;
$shop = null;

if ($edit) {
    $stmt = db()->prepare('SELECT * FROM shops WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $shop = $stmt->fetch();
    if (!$shop) { http_response_code(404); exit('Shop not found.'); }
}

$plans = db()->query("SELECT * FROM subscription_plans WHERE is_active = 1 ORDER BY fee ASC")->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $name       = trim((string)($_POST['name'] ?? ''));
    $ownerName  = trim((string)($_POST['owner_name'] ?? ''));
    $phone      = trim((string)($_POST['phone'] ?? ''));
    $email      = trim((string)($_POST['email'] ?? ''));
    $address    = trim((string)($_POST['address'] ?? ''));
    $status     = $_POST['status'] ?? 'active';

    if ($name === '')                                       $errors[] = 'Shop name is required.';
    if (!in_array($status, ['active','suspended','deactivated'], true)) $errors[] = 'Invalid status.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL))    $errors[] = 'Invalid email.';

    // Owner creation (only on create)
    $ownerUser = $ownerPass = '';
    $planId    = (int)($_POST['plan_id'] ?? 0);
    $startDate = $_POST['start_date'] ?? date('Y-m-d');
    if (!$edit) {
        $ownerUser = trim((string)($_POST['owner_username'] ?? ''));
        $ownerPass = (string)($_POST['owner_password'] ?? '');
        if ($ownerName === '')                               $errors[] = 'Owner name is required.';
        if ($ownerUser === '')                               $errors[] = 'Owner username is required.';
        if (strlen($ownerPass) < 8)                          $errors[] = 'Owner password must be at least 8 characters.';
        if ($planId <= 0)                                    $errors[] = 'Pick a plan.';
        if (!DateTime::createFromFormat('Y-m-d', $startDate)) $errors[] = 'Invalid start date.';

        if (!$errors) {
            $u = db()->prepare('SELECT 1 FROM shop_users WHERE username = :u');
            $u->execute([':u' => $ownerUser]);
            if ($u->fetchColumn()) $errors[] = 'Owner username already taken.';
        }
    }

    // Handle logo upload BEFORE opening the transaction so we don't hold a lock during disk I/O.
    $newLogoPath = null;
    $removeOldLogo = $edit && !empty($_POST['logo_remove']);
    if (!$errors && !empty($_FILES['logo']['name'])) {
        try {
            $newLogoPath = image_store($_FILES['logo'], 'uploads/shops', $edit ? $id : 0);
        } catch (Throwable $e) {
            $errors[] = 'Logo: ' . $e->getMessage();
        }
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            if ($edit) {
                // Decide what logo value to persist, and remember old file for cleanup.
                $oldLogo = $shop['logo_path'];
                $logoValue = $oldLogo;
                if ($newLogoPath)        $logoValue = $newLogoPath;
                elseif ($removeOldLogo)  $logoValue = null;

                db()->prepare(
                    'UPDATE shops SET name=:n, owner_name=:o, phone=:p, email=:e, address=:a, logo_path=:lp, status=:s WHERE id=:id'
                )->execute([
                    ':n'=>$name, ':o'=>$ownerName, ':p'=>$phone, ':e'=>$email, ':a'=>$address,
                    ':lp'=>$logoValue, ':s'=>$status, ':id'=>$id,
                ]);
                audit('shop.update', 'shop', $id, ['name'=>$name, 'status'=>$status], $id);
                $shopId = $id;
                $msg = 'Shop updated.';

                // Delete the previous file only after a successful commit — do it below.
            } else {
                db()->prepare(
                    'INSERT INTO shops (name, owner_name, phone, email, address, logo_path, status) VALUES (:n,:o,:p,:e,:a,:lp,:s)'
                )->execute([
                    ':n'=>$name, ':o'=>$ownerName, ':p'=>$phone, ':e'=>$email, ':a'=>$address,
                    ':lp'=>$newLogoPath, ':s'=>$status,
                ]);
                $shopId = (int)db()->lastInsertId();

                // Owner user — mirror the shop email so they can log in with either.
                db()->prepare(
                    "INSERT INTO shop_users (shop_id, name, username, email, password_hash, role, is_active)
                     VALUES (:sid, :n, :u, :e, :p, 'owner', 1)"
                )->execute([
                    ':sid'=>$shopId, ':n'=>$ownerName, ':u'=>$ownerUser,
                    ':e' => $email ?: null,
                    ':p'=>password_hash($ownerPass, PASSWORD_DEFAULT),
                ]);

                // Licence
                $plan = null;
                foreach ($plans as $pl) if ((int)$pl['id'] === $planId) $plan = $pl;
                $expiry = (new DateTime($startDate))->modify('+' . (int)$plan['duration_days'] . ' days')->format('Y-m-d');
                $amount = isset($_POST['amount']) ? max(0, (float)$_POST['amount']) : (float)$plan['fee'];

                db()->prepare(
                    "INSERT INTO shop_licenses (shop_id, plan_id, start_date, expiry_date, status)
                     VALUES (:sid, :pid, :sd, :ed, 'active')"
                )->execute([':sid'=>$shopId, ':pid'=>$planId, ':sd'=>$startDate, ':ed'=>$expiry]);
                $licenseId = (int)db()->lastInsertId();

                // Record the initial sale as a renewal event so revenue stays single-source.
                db()->prepare(
                    "INSERT INTO license_renewals (shop_license_id, plan_id, previous_expiry, new_expiry, amount, renewed_by)
                     VALUES (:slid, :pid, :pe, :ne, :amt, :by)"
                )->execute([
                    ':slid' => $licenseId, ':pid' => $planId,
                    ':pe' => $startDate, ':ne' => $expiry,
                    ':amt' => $amount, ':by' => (int)current_user()['id'],
                ]);

                audit('shop.create', 'shop', $shopId, ['name'=>$name, 'plan'=>$plan['name'], 'amount'=>$amount], $shopId);
                $msg = 'Shop, owner and licence created.';

                // Stash details so we can email AFTER commit (if email + address available).
                $pendingWelcome = [
                    'to'       => $email,
                    'vars'     => [
                        'owner_name' => $ownerName,
                        'shop_name'  => $name,
                        'username'   => $ownerUser,
                        'password'   => $ownerPass,
                        'login_url'  => (($_SERVER['HTTPS'] ?? '') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('index.php'),
                    ],
                ];
            }

            db()->commit();

            // Post-commit filesystem cleanup — safe to lose old file now.
            if ($edit && ($newLogoPath || $removeOldLogo) && !empty($shop['logo_path'])) {
                image_delete($shop['logo_path']);
            }

            // Welcome email (create only, if configured + address present).
            if (!empty($pendingWelcome) && !empty($pendingWelcome['to']) && mail_configured()) {
                $v = $pendingWelcome['vars'];
                $html = mail_welcome_html($v['owner_name'], $v['shop_name'], $v['username'], $v['password'], $v['login_url']);
                $subject = 'Welcome to ' . setting_get('mail_from_name', APP_NAME) . ' — your login details';
                $res = mail_send($pendingWelcome['to'], $subject, $html);
                if ($res['ok']) {
                    flash('success', $msg . ' Welcome email sent to ' . $pendingWelcome['to'] . '.');
                } else {
                    flash('warning', $msg . ' Email failed: ' . $res['error'] . ' — share credentials manually.');
                }
            } else {
                flash('success', $msg);
            }
            redirect('admin/clients/view.php?id=' . $shopId);
        } catch (Throwable $e) {
            db()->rollBack();
            // If we uploaded a new file but DB failed, don't leave the orphan behind.
            if ($newLogoPath) image_delete($newLogoPath);
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    } elseif ($newLogoPath) {
        // Validation failed after upload — clean up orphan.
        image_delete($newLogoPath);
        $newLogoPath = null;
    }
}

$pageTitle = $edit ? 'Edit Shop' : 'Add Shop';
require __DIR__ . '/../../includes/layout-top.php';

// Helper for repopulating
$v = function (string $key, $fallback = '') use ($shop) {
    return e($_POST[$key] ?? ($shop[$key] ?? $fallback));
};
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title"><?= $edit ? 'Edit Shop' : 'Add Shop' ?></h1>
    <p class="sb-page-subtitle"><?= $edit ? 'Update shop details and status.' : 'Create a shop, its owner, and an initial licence.' ?></p>
  </div>
  <a href="<?= url('admin/clients/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="js-once" autocomplete="off" enctype="multipart/form-data">
  <?= csrf_field() ?>

  <div class="card mb-3">
    <div class="card-header">Logo</div>
    <div class="card-body d-flex align-items-center gap-3 flex-wrap">
      <?php $logoUrl = $edit ? image_url($shop['logo_path'] ?? null) : null; ?>
      <div class="sb-logo-preview" id="sbLogoPreview">
        <?php if ($logoUrl): ?>
          <img src="<?= e($logoUrl) ?>" alt="Shop logo">
        <?php else: ?>
          <span><i class="bi bi-shop"></i></span>
        <?php endif; ?>
      </div>
      <div class="flex-grow-1">
        <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="form-control" id="sbLogoInput">
        <div class="form-text">Max 500 KB. Larger images are auto-resized. JPG / PNG / WEBP.</div>
        <?php if ($edit && $shop['logo_path']): ?>
          <div class="form-check mt-2">
            <input class="form-check-input" type="checkbox" id="logo_remove" name="logo_remove" value="1">
            <label class="form-check-label" for="logo_remove">Remove current logo</label>
          </div>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Shop details</div>
    <div class="card-body">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Shop name *</label>
          <input class="form-control" name="name" value="<?= $v('name') ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Owner name</label>
          <input class="form-control" name="owner_name" value="<?= $v('owner_name') ?>" <?= $edit ? '' : 'required' ?>>
        </div>
        <div class="col-md-6">
          <label class="form-label">Phone</label>
          <input class="form-control" name="phone" value="<?= $v('phone') ?>">
        </div>
        <div class="col-md-6">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" value="<?= $v('email') ?>">
        </div>
        <div class="col-12">
          <label class="form-label">Address</label>
          <textarea class="form-control" name="address" rows="2"><?= $v('address') ?></textarea>
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <select class="form-select" name="status">
            <?php foreach (['active','suspended','deactivated'] as $s):
                $cur = $_POST['status'] ?? ($shop['status'] ?? 'active'); ?>
              <option value="<?= $s ?>" <?= $cur === $s ? 'selected' : '' ?>><?= ucfirst($s) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </div>
  </div>

  <?php if (!$edit): ?>
  <div class="card mb-3">
    <div class="card-header">Owner credentials</div>
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label">Username *</label>
        <input class="form-control" name="owner_username" value="<?= e($_POST['owner_username'] ?? '') ?>" required>
      </div>
      <div class="col-md-6">
        <label class="form-label">Password * <small class="text-muted">(≥ 8 chars)</small></label>
        <input class="form-control" type="password" name="owner_password" required>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Plan &amp; licence</div>
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label">Plan *</label>
        <select class="form-select" name="plan_id" id="sbPlanSelect" required
                data-fees='<?= e(json_encode(array_column($plans, 'fee', 'id'))) ?>'>
          <option value="">— Select —</option>
          <?php foreach ($plans as $pl): ?>
            <option value="<?= (int)$pl['id'] ?>" <?= ($_POST['plan_id'] ?? '') == $pl['id'] ? 'selected' : '' ?>>
              <?= e($pl['name']) ?> — <?= money($pl['fee']) ?> / <?= (int)$pl['duration_days'] ?> days
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Start date *</label>
        <input class="form-control" type="date" name="start_date" value="<?= e($_POST['start_date'] ?? date('Y-m-d')) ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Amount paid (<?= APP_CURRENCY_SYMBOL ?>) *</label>
        <input class="form-control" type="number" step="0.01" min="0" name="amount"
               id="sbPlanAmount" value="<?= e($_POST['amount'] ?? '0') ?>" required>
      </div>
    </div>
  </div>
  <?php endif; ?>

  <div class="d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $edit ? 'Save changes' : 'Create shop' ?></button>
    <a href="<?= url($edit ? 'admin/clients/view.php?id=' . $id : 'admin/clients/') ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<script>
  (() => {
    const i = document.getElementById('sbLogoInput');
    const p = document.getElementById('sbLogoPreview');
    i?.addEventListener('change', () => {
      const f = i.files?.[0];
      if (!f) return;
      p.innerHTML = '<img src="' + URL.createObjectURL(f) + '" alt="preview">';
    });

    const plan = document.getElementById('sbPlanSelect');
    const amt  = document.getElementById('sbPlanAmount');
    if (plan && amt) {
      const fees = JSON.parse(plan.dataset.fees || '{}');
      const sync = () => { const f = fees[plan.value]; if (f !== undefined) amt.value = f; };
      plan.addEventListener('change', sync);
      if (!amt.value || amt.value === '0') sync();
    }
  })();
</script>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

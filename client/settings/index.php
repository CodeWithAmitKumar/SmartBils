<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('settings.manage');

$shopId = current_shop_id();

$shop = db()->prepare('SELECT * FROM shops WHERE id = :id');
$shop->execute([':id' => $shopId]);
$shop = $shop->fetch();

$cfg = db()->prepare('SELECT * FROM shop_settings WHERE shop_id = :id');
$cfg->execute([':id' => $shopId]);
$cfg = $cfg->fetch() ?: [
    'receipt_header' => '', 'receipt_footer' => '',
    'currency' => 'INR', 'tax_percent' => 0,
    'cash_enabled' => 1, 'upi_enabled' => 1,
    'upi_id' => '', 'upi_qr_path' => null,
];

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $op = $_POST['op'] ?? '';

    try {
        if ($op === 'profile') {
            $name       = trim((string)($_POST['name'] ?? ''));
            $ownerName  = trim((string)($_POST['owner_name'] ?? ''));
            $phone      = trim((string)($_POST['phone'] ?? ''));
            $email      = trim((string)($_POST['email'] ?? ''));
            $address    = trim((string)($_POST['address'] ?? ''));
            if ($name === '') $errors[] = 'Shop name is required.';
            if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

            // Logo upload
            $newLogoPath = null; $removeOldLogo = !empty($_POST['logo_remove']);
            if (!$errors && !empty($_FILES['logo']['name'])) {
                $newLogoPath = image_store($_FILES['logo'], 'uploads/shops', $shopId);
            }

            if (!$errors) {
                $logoValue = $shop['logo_path'];
                if ($newLogoPath)       $logoValue = $newLogoPath;
                elseif ($removeOldLogo) $logoValue = null;

                db()->prepare('UPDATE shops SET name=:n, owner_name=:o, phone=:p, email=:e, address=:a, logo_path=:lp WHERE id=:id')
                    ->execute([':n'=>$name, ':o'=>$ownerName, ':p'=>$phone, ':e'=>$email, ':a'=>$address, ':lp'=>$logoValue, ':id'=>$shopId]);

                // Delete old file only after successful commit.
                if (($newLogoPath || $removeOldLogo) && !empty($shop['logo_path'])) {
                    image_delete($shop['logo_path']);
                }

                audit('shop_settings.profile_update', 'shop', $shopId, null, $shopId);
                flash('success', 'Shop profile updated.');
                redirect('client/settings/');
            }
        }

        if ($op === 'receipt') {
            db()->prepare(
                'INSERT INTO shop_settings (shop_id, receipt_header, receipt_footer)
                 VALUES (:sid, :h, :f)
                 ON DUPLICATE KEY UPDATE receipt_header = VALUES(receipt_header), receipt_footer = VALUES(receipt_footer)'
            )->execute([
                ':sid' => $shopId,
                ':h'   => trim((string)($_POST['receipt_header'] ?? '')),
                ':f'   => trim((string)($_POST['receipt_footer'] ?? '')),
            ]);
            audit('shop_settings.receipt_update', 'shop', $shopId, null, $shopId);
            flash('success', 'Receipt updated.');
            redirect('client/settings/');
        }

        if ($op === 'tax') {
            $tax = max(0, (float)($_POST['tax_percent'] ?? 0));
            if ($tax > 100) throw new RuntimeException('Tax must be between 0 and 100.');
            db()->prepare(
                'INSERT INTO shop_settings (shop_id, tax_percent) VALUES (:sid, :t)
                 ON DUPLICATE KEY UPDATE tax_percent = VALUES(tax_percent)'
            )->execute([':sid' => $shopId, ':t' => $tax]);
            audit('shop_settings.tax_update', 'shop', $shopId, ['tax' => $tax], $shopId);
            flash('success', 'Tax updated.');
            redirect('client/settings/');
        }

        if ($op === 'payments') {
            $cash  = !empty($_POST['cash_enabled']) ? 1 : 0;
            $upi   = !empty($_POST['upi_enabled']) ? 1 : 0;
            $upiId = trim((string)($_POST['upi_id'] ?? ''));

            $newQrPath = null;
            $removeQr  = !empty($_POST['qr_remove']);
            if (!empty($_FILES['upi_qr']['name'])) {
                $newQrPath = image_store($_FILES['upi_qr'], 'uploads/upi', $shopId);
            }

            $qrValue = $cfg['upi_qr_path'];
            if ($newQrPath) $qrValue = $newQrPath;
            elseif ($removeQr) $qrValue = null;

            db()->prepare(
                'INSERT INTO shop_settings (shop_id, cash_enabled, upi_enabled, upi_id, upi_qr_path)
                 VALUES (:sid, :c, :u, :uid, :qr)
                 ON DUPLICATE KEY UPDATE cash_enabled=VALUES(cash_enabled), upi_enabled=VALUES(upi_enabled),
                                         upi_id=VALUES(upi_id), upi_qr_path=VALUES(upi_qr_path)'
            )->execute([':sid'=>$shopId, ':c'=>$cash, ':u'=>$upi, ':uid'=>$upiId, ':qr'=>$qrValue]);

            if (($newQrPath || $removeQr) && !empty($cfg['upi_qr_path'])) {
                image_delete($cfg['upi_qr_path']);
            }
            audit('shop_settings.payments_update', 'shop', $shopId, null, $shopId);
            flash('success', 'Payment settings updated.');
            redirect('client/settings/');
        }
    } catch (Throwable $ex) {
        $errors[] = $ex->getMessage();
    }
}

$logoUrl = image_url($shop['logo_path'] ?? null);
$qrUrl   = image_url($cfg['upi_qr_path'] ?? null);

$pageTitle = 'Settings';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Settings</h1>
    <p class="sb-page-subtitle">Configure your shop workspace.</p>
  </div>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<!-- Profile + Logo -->
<div class="card mb-3">
  <div class="card-header">Shop profile</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="js-once">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="profile">
      <div class="d-flex align-items-center gap-3 flex-wrap mb-3">
        <div class="sb-logo-preview" id="sbLogoPreview">
          <?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt="">
          <?php else: ?><span><i class="bi bi-shop"></i></span><?php endif; ?>
        </div>
        <div class="flex-grow-1">
          <input type="file" name="logo" accept="image/jpeg,image/png,image/webp" class="form-control" id="sbLogoInput">
          <div class="form-text">Max 500 KB — auto-resized if larger. JPG / PNG / WEBP.</div>
          <?php if ($shop['logo_path']): ?>
            <div class="form-check mt-2">
              <input class="form-check-input" type="checkbox" id="logo_remove" name="logo_remove" value="1">
              <label class="form-check-label" for="logo_remove">Remove current logo</label>
            </div>
          <?php endif; ?>
        </div>
      </div>
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Shop name</label>
          <input class="form-control" name="name" value="<?= e($shop['name']) ?>" required>
        </div>
        <div class="col-md-6">
          <label class="form-label">Owner name</label>
          <input class="form-control" name="owner_name" value="<?= e($shop['owner_name']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Phone</label>
          <input class="form-control" name="phone" value="<?= e($shop['phone']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Email</label>
          <input class="form-control" type="email" name="email" value="<?= e($shop['email']) ?>">
        </div>
        <div class="col-md-4">
          <label class="form-label">Status</label>
          <input class="form-control" value="<?= e(ucfirst($shop['status'])) ?>" disabled>
        </div>
        <div class="col-12">
          <label class="form-label">Address</label>
          <textarea class="form-control" rows="2" name="address"><?= e($shop['address']) ?></textarea>
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Save profile</button>
    </form>
  </div>
</div>

<!-- Receipt -->
<div class="card mb-3">
  <div class="card-header">Receipt</div>
  <div class="card-body">
    <form method="post" class="js-once">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="receipt">
      <div class="row g-3">
        <div class="col-md-6">
          <label class="form-label">Receipt header</label>
          <textarea class="form-control" rows="3" name="receipt_header"
                    placeholder="Shown at the top of printed receipts"><?= e($cfg['receipt_header']) ?></textarea>
          <div class="form-text">Example: GSTIN, address, "Thanks for shopping with us".</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Receipt footer</label>
          <textarea class="form-control" rows="3" name="receipt_footer"
                    placeholder="Shown at the bottom of printed receipts"><?= e($cfg['receipt_footer']) ?></textarea>
          <div class="form-text">Example: Return policy, support contact.</div>
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Save receipt</button>
    </form>
  </div>
</div>

<!-- Tax -->
<div class="card mb-3">
  <div class="card-header">Tax &amp; Currency</div>
  <div class="card-body">
    <form method="post" class="js-once row g-3 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="tax">
      <div class="col-md-4">
        <label class="form-label">Currency</label>
        <input class="form-control" value="INR (<?= APP_CURRENCY_SYMBOL ?>)" disabled>
        <div class="form-text">Platform currency — change later via platform admin.</div>
      </div>
      <div class="col-md-4">
        <label class="form-label">Default tax %</label>
        <input class="form-control" type="number" step="0.01" min="0" max="100"
               name="tax_percent" value="<?= e($cfg['tax_percent']) ?>">
        <div class="form-text">Applied on sale totals by default — can be overridden per bill.</div>
      </div>
      <div class="col-md-4">
        <button class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i>Save tax</button>
      </div>
    </form>
  </div>
</div>

<!-- Payments -->
<div class="card">
  <div class="card-header">Payment methods</div>
  <div class="card-body">
    <form method="post" enctype="multipart/form-data" class="js-once">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="payments">
      <div class="row g-3">
        <div class="col-md-6">
          <div class="form-check form-switch mb-2">
            <input class="form-check-input" type="checkbox" id="cash_enabled" name="cash_enabled" value="1" <?= $cfg['cash_enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="cash_enabled">Accept cash</label>
          </div>
          <div class="form-check form-switch">
            <input class="form-check-input" type="checkbox" id="upi_enabled" name="upi_enabled" value="1" <?= $cfg['upi_enabled'] ? 'checked' : '' ?>>
            <label class="form-check-label" for="upi_enabled">Accept UPI</label>
          </div>
          <div class="mt-3">
            <label class="form-label">UPI ID</label>
            <input class="form-control" name="upi_id" value="<?= e($cfg['upi_id']) ?>" placeholder="yourname@upi">
            <div class="form-text">Shown on bills and receipts when UPI is active.</div>
          </div>
        </div>
        <div class="col-md-6">
          <label class="form-label">UPI QR image</label>
          <div class="d-flex align-items-center gap-3 flex-wrap">
            <div class="sb-logo-preview" id="sbQrPreview">
              <?php if ($qrUrl): ?><img src="<?= e($qrUrl) ?>" alt="UPI QR">
              <?php else: ?><span><i class="bi bi-qr-code"></i></span><?php endif; ?>
            </div>
            <div class="flex-grow-1">
              <input type="file" name="upi_qr" accept="image/jpeg,image/png,image/webp" class="form-control" id="sbQrInput">
              <div class="form-text">Max 500 KB — auto-resized. QR display is NOT proof of payment; always verify receipt.</div>
              <?php if ($cfg['upi_qr_path']): ?>
                <div class="form-check mt-2">
                  <input class="form-check-input" type="checkbox" id="qr_remove" name="qr_remove" value="1">
                  <label class="form-check-label" for="qr_remove">Remove current QR</label>
                </div>
              <?php endif; ?>
            </div>
          </div>
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Save payment settings</button>
    </form>
  </div>
</div>

<script>
  (() => {
    const bind = (inputId, previewId) => {
      const i = document.getElementById(inputId);
      const p = document.getElementById(previewId);
      i?.addEventListener('change', () => {
        const f = i.files?.[0]; if (!f) return;
        p.innerHTML = '<img src="' + URL.createObjectURL(f) + '" alt="preview">';
      });
    };
    bind('sbLogoInput', 'sbLogoPreview');
    bind('sbQrInput',   'sbQrPreview');
  })();
</script>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

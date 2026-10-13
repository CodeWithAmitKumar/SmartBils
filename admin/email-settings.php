<?php
require_once __DIR__ . '/../config/app.php';
require_super_admin();

$errors = []; $notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $op = $_POST['op'] ?? 'save';

    if ($op === 'save') {
        $name  = trim((string)($_POST['mail_from_name'] ?? ''));
        $email = trim((string)($_POST['mail_from_email'] ?? ''));
        $pass  = trim((string)($_POST['mail_app_password'] ?? ''));

        if ($name === '')                               $errors[] = 'Sender name is required.';
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Enter a valid Gmail address.';
        // Allow blank password = keep existing. Treat 16 chars (gmail app password) as hint, don't enforce.

        if (!$errors) {
            $pairs = ['mail_from_name' => $name, 'mail_from_email' => $email];
            if ($pass !== '') $pairs['mail_app_password'] = str_replace(' ', '', $pass);
            setting_set_many($pairs);
            audit('settings.email_update', 'settings', null);
            flash('success', 'Email settings saved.');
            redirect('admin/email-settings.php');
        }
    } elseif ($op === 'test') {
        $to = trim((string)($_POST['test_to'] ?? ''));
        if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
            $errors[] = 'Enter a valid email address to send the test to.';
        } else {
            $html = mail_welcome_html('Tester', 'Demo Shop', 'demo_user', 'temp-' . substr(bin2hex(random_bytes(3)),0,6),
                     (($_SERVER['HTTPS'] ?? '') ? 'https://' : 'http://') . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('index.php'));
            $r = mail_send($to, APP_NAME . ' — test email', $html);
            $notice = $r['ok'] ? ['success', "Test email sent to {$to}."] : ['danger', 'Test failed: ' . $r['error']];
        }
    }
}

$current = db()->query('SELECT `key`,`value` FROM app_settings')->fetchAll(PDO::FETCH_KEY_PAIR);
$hasPassword = !empty($current['mail_app_password']);

$pageTitle = 'Email Settings';
require __DIR__ . '/../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Email Settings</h1>
    <p class="sb-page-subtitle">Gmail SMTP — sends the welcome email when you create a shop.</p>
  </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= e($notice[0]) ?>"><?= e($notice[1]) ?></div><?php endif; ?>

<div class="card mb-3">
  <div class="card-header">Gmail account</div>
  <div class="card-body">
    <form method="post" class="js-once" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save">
      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label">Name</label>
          <input class="form-control" name="mail_from_name"
                 value="<?= e($_POST['mail_from_name'] ?? ($current['mail_from_name'] ?? '')) ?>"
                 placeholder="Your name or brand" required>
          <div class="form-text">Shown as the "From" name.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Sending email</label>
          <input class="form-control" type="email" name="mail_from_email"
                 value="<?= e($_POST['mail_from_email'] ?? ($current['mail_from_email'] ?? '')) ?>"
                 placeholder="you@gmail.com" required>
          <div class="form-text">Gmail with 2-Step Verification.</div>
        </div>
        <div class="col-md-4">
          <label class="form-label">App password</label>
          <input class="form-control" type="password" name="mail_app_password"
                 placeholder="<?= $hasPassword ? '•••• (saved — leave blank to keep)' : 'xxxx xxxx xxxx xxxx' ?>">
          <div class="form-text">
            From <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">Google App Passwords</a>.
          </div>
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Save</button>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">Send a test email</div>
  <div class="card-body">
    <form method="post" class="js-once row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="test">
      <div class="col-md-8">
        <label class="form-label">Deliver to</label>
        <input class="form-control" type="email" name="test_to" placeholder="you@example.com" required>
      </div>
      <div class="col-md-4">
        <button class="btn btn-outline-primary w-100"><i class="bi bi-send me-1"></i>Send test</button>
      </div>
    </form>
    <hr>
    <div class="small text-muted mb-0">
      <strong>How to get an app password:</strong>
      Turn on 2-Step Verification on your Google account →
      visit <em>myaccount.google.com → Security → App passwords</em> →
      generate a password labelled "<?= e(APP_NAME) ?>" and paste it above.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

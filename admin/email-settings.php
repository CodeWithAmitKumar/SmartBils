<?php
require_once __DIR__ . '/../config/app.php';
require_super_admin();

$errors = [];
$notice = null;

/* ---------- Handle POST ---------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $op = $_POST['op'] ?? 'save';

    try {
        if ($op === 'save') {
            $name  = trim((string)($_POST['name']  ?? ''));
            $email = trim((string)($_POST['email'] ?? ''));
            $pass  = str_replace(' ', '', (string)($_POST['app_password'] ?? ''));

            if ($name === '')                                $errors[] = 'Sender name is required.';
            if (!filter_var($email, FILTER_VALIDATE_EMAIL))  $errors[] = 'Enter a valid Gmail address.';

            if (!$errors) {
                mail_config_save($name, $email, $pass);  // blank password = keep existing
                audit('settings.email_update', 'mail_config', 1);
                flash('success', 'Email settings saved.');
                redirect('admin/email-settings.php');
            }
        } elseif ($op === 'test') {
            $to = trim((string)($_POST['test_to'] ?? ''));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Enter a valid recipient email.';
            } else {
                $html = mail_welcome_html(
                    'Tester', 'Demo Shop', 'demo_user',
                    'temp-' . substr(bin2hex(random_bytes(3)), 0, 6),
                    (($_SERVER['HTTPS'] ?? '') ? 'https://' : 'http://')
                        . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('index.php')
                );
                $r = mail_send($to, APP_NAME . ' — test email', $html);
                $notice = $r['ok']
                    ? ['success', "Test email sent to {$to}."]
                    : ['danger',  'Test failed: ' . $r['error']];
            }
        }
    } catch (Throwable $e) {
        $errors[] = $e->getMessage();
    }
}

/* ---------- Load current + mail-DB status ---------- */
$dbReady = function_exists('mail_db_exists') ? mail_db_exists() : false;
$mailCfg = $dbReady
    ? mail_config_get()
    : ['from_name' => '', 'from_email' => '', 'app_password' => ''];
$hasPassword = !empty($mailCfg['app_password']);

$pageTitle = 'Email Settings';
require __DIR__ . '/../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Email Settings</h1>
    <p class="sb-page-subtitle">
      Gmail SMTP. Credentials stored in <code>mail_config</code> inside your <code>smartbilling</code> database.
    </p>
  </div>
</div>

<?php if (!$dbReady): ?>
  <div class="alert alert-warning">
    <strong><i class="bi bi-exclamation-triangle me-1"></i> Mail database not set up.</strong>
    Import <code>database/migrations/mail_db.sql</code> once in phpMyAdmin — it creates the <code>mail_config</code> table inside <code>smartbilling</code>. Then reload this page.
  </div>
<?php endif; ?>

<?php if ($errors): ?>
  <div class="alert alert-danger">
    <ul class="mb-0">
      <?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?>
    </ul>
  </div>
<?php endif; ?>

<?php if ($notice): ?>
  <div class="alert alert-<?= e($notice[0]) ?>"><?= e($notice[1]) ?></div>
<?php endif; ?>

<!-- Gmail account -->
<div class="card mb-3">
  <div class="card-header">Gmail account</div>
  <div class="card-body">
    <form method="post" class="js-once" autocomplete="off">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save">

      <div class="row g-3">
        <div class="col-md-4">
          <label class="form-label" for="f_name">Name</label>
          <input class="form-control" id="f_name" name="name"
                 value="<?= e($_POST['name'] ?? $mailCfg['from_name']) ?>"
                 placeholder="Your name or brand" required>
          <div class="form-text">Shown as the "From" name.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label" for="f_email">Sending email</label>
          <input class="form-control" id="f_email" type="email" name="email"
                 value="<?= e($_POST['email'] ?? $mailCfg['from_email']) ?>"
                 placeholder="you@gmail.com" required>
          <div class="form-text">Gmail address with 2-Step Verification enabled.</div>
        </div>

        <div class="col-md-4">
          <label class="form-label" for="f_pw">App password</label>
          <input class="form-control" id="f_pw" type="password" name="app_password"
                 placeholder="<?= $hasPassword ? '•••• (saved — leave blank to keep)' : 'xxxx xxxx xxxx xxxx' ?>">
          <div class="form-text">
            From <a href="https://myaccount.google.com/apppasswords" target="_blank" rel="noopener">Google App Passwords</a>.
          </div>
        </div>
      </div>

      <button class="btn btn-primary mt-3" <?= $dbReady ? '' : 'disabled' ?>>
        <i class="bi bi-check2 me-1"></i>Save
      </button>
    </form>
  </div>
</div>

<!-- Test send -->
<div class="card">
  <div class="card-header">Send a test email</div>
  <div class="card-body">
    <form method="post" class="js-once row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="test">
      <div class="col-md-8">
        <label class="form-label" for="f_to">Deliver to</label>
        <input class="form-control" id="f_to" type="email" name="test_to"
               placeholder="you@example.com" required>
      </div>
      <div class="col-md-4">
        <button class="btn btn-outline-primary w-100">
          <i class="bi bi-send me-1"></i>Send test
        </button>
      </div>
    </form>

    <hr>

    <div class="small text-muted mb-0">
      <strong>How to get an app password:</strong>
      Turn on 2-Step Verification on your Google account &rarr;
      <em>myaccount.google.com &rarr; Security &rarr; App passwords</em> &rarr;
      generate a password labelled "<?= e(APP_NAME) ?>" and paste it above.
    </div>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

<?php
// Forgot password — shop user. Enter email → verify in shop_users →
// generate temp password → store hash → email it.
require_once __DIR__ . '/config/app.php';

$pageTitle = 'Forgot Password';
$done   = false;
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $email = trim((string)($_POST['email'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $errors[] = 'Enter a valid email address.';
    } elseif (auth_is_throttled($email, 'shop')) {
        $errors[] = 'Too many attempts. Try again in ' . AUTH_WINDOW_MIN . ' minutes.';
    } else {
        // Find active shop user with this email on an active shop.
        $q = db()->prepare("
          SELECT su.id, su.name, su.username, su.email, s.name AS shop_name
          FROM shop_users su
          JOIN shops s ON s.id = su.shop_id
          WHERE su.email = :e AND su.is_active = 1 AND s.status = 'active'
          LIMIT 1");
        $q->execute([':e' => $email]);
        $user = $q->fetch();

        // Record attempt either way (prevents enumeration via timing/response).
        auth_record_attempt($email, 'shop', (bool)$user);

        if ($user) {
            if (!mail_configured()) {
                $errors[] = 'Mail is not configured yet. Please contact your administrator.';
            } else {
                $tmp = bin2hex(random_bytes(5)); // 10-char password
                try {
                    db()->prepare('UPDATE shop_users SET password_hash = :p WHERE id = :id')
                        ->execute([':p' => password_hash($tmp, PASSWORD_DEFAULT), ':id' => $user['id']]);

                    $loginUrl = (($_SERVER['HTTPS'] ?? '') ? 'https://' : 'http://')
                              . ($_SERVER['HTTP_HOST'] ?? 'localhost') . url('index.php');
                    $html = mail_reset_html($user['name'], $user['shop_name'], $user['username'], $tmp, $loginUrl);
                    $res  = mail_send($user['email'], APP_NAME . ' — password reset', $html);

                    if (!$res['ok']) throw new RuntimeException('Could not send email: ' . $res['error']);

                    audit('shop_user.password_reset', 'shop_user', (int)$user['id'],
                          ['from' => 'forgot-password'], null);
                    $done = true;
                } catch (Throwable $e) {
                    $errors[] = $e->getMessage();
                }
            }
        } else {
            // Still show success to avoid leaking which emails exist (generic UX, security best practice).
            $done = true;
        }
    }
}
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= url('uploads/logo/favicon.png') ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  *,*::before,*::after { box-sizing: border-box; }
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
    font-family:'Inter',sans-serif; color:#15102E;
    background:
      radial-gradient(900px 600px at 20% 10%,  rgba(109,40,217,.18), transparent 60%),
      radial-gradient(700px 500px at 100% 90%, rgba(217,164,65,.18), transparent 55%),
      #FBF7EC; }
  .card { background:#fff; max-width:460px; width:92%; padding:40px 32px; border-radius:18px;
    border:1px solid rgba(217,164,65,.3);
    box-shadow:0 24px 60px rgba(76,29,149,.18); }
  .icon { width:64px; height:64px; margin:0 auto 16px; border-radius:16px;
    background:linear-gradient(135deg,#FDE68A,#D9A441 60%,#B07E22);
    color:#1E1649; display:flex; align-items:center; justify-content:center; font-size:28px;
    box-shadow:0 10px 24px rgba(217,164,65,.4), inset 0 1px 0 rgba(255,255,255,.5); }
  h1 { font-family:'Playfair Display',serif; font-size:26px; margin:0 0 8px; color:#1E1649; text-align:center; }
  .sub { color:#4B4269; font-size:14.5px; line-height:1.6; margin:0 0 20px; text-align:center; }
  label { display:block; font-size:12px; font-weight:600; color:#4B4269; text-transform:uppercase; letter-spacing:.1em; margin:0 0 6px 2px; }
  .input { width:100%; background:#FBF8EE; border:1.5px solid #E8DFC9; color:#15102E; font-size:15px;
    padding:14px 16px 14px 46px; border-radius:10px; outline:none; transition:all .2s; font-family:'Inter'; }
  .input:focus { border-color:#D9A441; box-shadow:0 0 0 4px rgba(217,164,65,.2); background:#fff; }
  .field { position:relative; margin-bottom:14px; }
  .field i { position:absolute; left:16px; top:50%; transform:translateY(-50%); color:#B07E22; font-size:17px; pointer-events:none; }
  .btn { display:block; width:100%; padding:14px 20px; border:0; border-radius:10px;
    background:linear-gradient(135deg,#4B1D95 0%,#1E1649 100%); color:#fff; text-decoration:none;
    font-weight:600; font-size:15px; text-align:center; cursor:pointer; margin-top:10px;
    box-shadow:0 10px 24px rgba(76,29,149,.35); transition:all .18s; }
  .btn:hover { transform:translateY(-2px); box-shadow:0 16px 32px rgba(76,29,149,.45); }
  .back { display:block; margin-top:18px; text-align:center; font-size:13.5px; color:#4B4269; text-decoration:none; }
  .back:hover { color:#4B1D95; }
  .alert { padding:12px 14px; border-radius:10px; margin-bottom:14px; font-size:13.5px; display:flex; gap:10px; align-items:center; }
  .alert-danger  { background:#FEF2F2; border:1px solid #FECACA; color:#B91C1C; }
  .alert-success { background:#F0FDF4; border:1px solid #BBF7D0; color:#166534; }
</style></head>
<body>
  <div class="card">
    <div class="icon"><i class="bi bi-<?= $done ? 'envelope-check-fill' : 'key-fill' ?>"></i></div>

    <?php if ($done): ?>
      <h1>Check your inbox</h1>
      <p class="sub">If an account with that email exists, we've sent a new temporary password. Please sign in and change it immediately from your profile.</p>
      <a class="btn" href="<?= url('index.php') ?>">Back to login</a>
    <?php else: ?>
      <h1>Forgot your password?</h1>
      <p class="sub">Enter your registered email address. We'll send you a new temporary password you can sign in with.</p>

      <?php foreach ($errors as $x): ?>
        <div class="alert alert-danger"><i class="bi bi-x-circle-fill"></i><?= e($x) ?></div>
      <?php endforeach; ?>

      <form method="post" autocomplete="off">
        <?= csrf_field() ?>
        <label for="email">Email</label>
        <div class="field">
          <i class="bi bi-envelope"></i>
          <input class="input" id="email" type="email" name="email"
                 value="<?= e($_POST['email'] ?? '') ?>" required autofocus
                 placeholder="you@example.com">
        </div>
        <button class="btn" type="submit"><i class="bi bi-send me-1"></i> Send new password</button>
      </form>
      <a class="back" href="<?= url('index.php') ?>"><i class="bi bi-arrow-left me-1"></i>Back to login</a>
    <?php endif; ?>
  </div>
</body></html>

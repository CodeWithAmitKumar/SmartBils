<?php
// Password reset stub — Phase 2 shipped the `password_reset_tokens` table;
// token issuance + email flow lands in a later phase. For now, direct user to admin.
require_once __DIR__ . '/config/app.php';
$pageTitle = 'Forgot Password';
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:wght@600&family=Inter:wght@400;500;600&display=swap" rel="stylesheet">
<style>
  body { margin:0; min-height:100vh; display:flex; align-items:center; justify-content:center;
    font-family:'Inter',sans-serif; color:#15102E;
    background: radial-gradient(900px 600px at 20% 10%, rgba(109,40,217,.18), transparent 60%),
                radial-gradient(700px 500px at 100% 90%, rgba(217,164,65,.18), transparent 55%),
                #FBF7EC; }
  .card { background:#fff; max-width:440px; width:92%; padding:40px 32px; border-radius:18px;
    border:1px solid rgba(217,164,65,.3);
    box-shadow:0 24px 60px rgba(76,29,149,.18); text-align:center; }
  .icon { width:64px; height:64px; margin:0 auto 16px; border-radius:16px;
    background:linear-gradient(135deg,#FDE68A,#D9A441 60%,#B07E22);
    color:#1E1649; display:flex; align-items:center; justify-content:center; font-size:28px;
    box-shadow:0 10px 24px rgba(217,164,65,.4), inset 0 1px 0 rgba(255,255,255,.5); }
  h1 { font-family:'Playfair Display',serif; font-size:26px; margin:0 0 8px; color:#1E1649; }
  p  { color:#4B4269; font-size:14.5px; line-height:1.6; margin:0 0 20px; }
  .back { display:inline-block; padding:12px 24px; border-radius:10px;
    background:linear-gradient(135deg,#4B1D95,#1E1649); color:#fff; text-decoration:none;
    font-weight:600; font-size:14px;
    box-shadow:0 10px 24px rgba(76,29,149,.35); transition:transform .15s, box-shadow .2s; }
  .back:hover { transform:translateY(-2px); box-shadow:0 16px 32px rgba(76,29,149,.45); }
</style></head>
<body>
  <div class="card">
    <div class="icon"><i class="bi bi-key-fill"></i></div>
    <h1>Need to reset your password?</h1>
    <p>Please contact your platform administrator — they can reset it from the admin console and share a new temporary password with you securely.</p>
    <a class="back" href="<?= url('index.php') ?>"><i class="bi bi-arrow-left me-1"></i>Back to login</a>
  </div>
</body></html>

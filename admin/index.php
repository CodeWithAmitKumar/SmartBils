<?php
// /admin/ — Super Admin login (premium 3D UI).
require_once __DIR__ . '/../config/app.php';

if (is_logged_in()) {
    $u = current_user();
    if (($u['user_type'] ?? '') === 'super_admin') {
        redirect('admin/dashboard.php');
    }
    redirect('client/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $identifier = trim((string)($_POST['username'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');
    keep_old(['username' => $identifier]);

    if ($identifier === '' || $password === '') {
        flash('danger', 'Please enter your username and password.');
        redirect('admin/');
    }
    if (auth_is_throttled($identifier, 'admin')) {
        flash('danger', 'Too many failed attempts. Try again in ' . AUTH_WINDOW_MIN . ' minutes.');
        redirect('admin/');
    }
    $admin = auth_attempt_admin($identifier, $password);
    auth_record_attempt($identifier, 'admin', (bool)$admin);
    if (!$admin) {
        flash('danger', 'Invalid credentials.');
        redirect('admin/');
    }
    auth_login_admin($admin);
    flash('success', 'Welcome, ' . $admin['name'] . '.');
    redirect('admin/dashboard.php');
}

$pageTitle = 'Super Admin Login';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= url('uploads/logo/favicon.png') ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Space+Grotesk:wght@600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --p1:#6366F1; --p2:#8B5CF6; --p3:#EC4899; --p4:#06B6D4;
    --ink:#0B0F1E;
  }
  *,*::before,*::after { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: #E5E7F0;
    background: #05060D;
    overflow: hidden;
    -webkit-font-smoothing: antialiased;
  }

  /* ===== Backdrop: grid + animated orbs ===== */
  .bg {
    position: fixed; inset: 0; z-index: 0; overflow: hidden;
    background:
      radial-gradient(1200px 700px at 20% 110%, rgba(99,102,241,.35), transparent 60%),
      radial-gradient(900px 600px at 100% 0%,  rgba(236,72,153,.25), transparent 60%),
      radial-gradient(700px 500px at 0% 0%,    rgba(6,182,212,.2),   transparent 60%),
      linear-gradient(180deg, #05060D 0%, #0B0F1E 100%);
  }
  .grid {
    position: fixed; inset: 0; z-index: 1;
    background-image:
      linear-gradient(rgba(255,255,255,.045) 1px, transparent 1px),
      linear-gradient(90deg, rgba(255,255,255,.045) 1px, transparent 1px);
    background-size: 48px 48px;
    mask-image: radial-gradient(ellipse at center, #000 40%, transparent 85%);
            mask-image: radial-gradient(ellipse at center, #000 40%, transparent 85%);
    -webkit-mask-image: radial-gradient(ellipse at center, #000 40%, transparent 85%);
  }
  .orb {
    position: fixed; z-index: 1; border-radius: 50%;
    filter: blur(60px); opacity: .55; pointer-events: none;
    animation: floaty 14s ease-in-out infinite;
    will-change: transform;
  }
  .orb.a { width: 420px; height: 420px; left: -80px; top: -60px;  background: var(--p1); animation-delay: 0s;  }
  .orb.b { width: 500px; height: 500px; right: -100px; bottom: -80px; background: var(--p3); animation-delay: 3s; }
  .orb.c { width: 320px; height: 320px; right: 20%; top: 10%;  background: var(--p4); opacity:.35; animation-delay: 6s; }
  @keyframes floaty {
    0%,100% { transform: translate3d(0,0,0) scale(1); }
    50%     { transform: translate3d(30px,-20px,0) scale(1.08); }
  }

  /* ===== Centered 3D card ===== */
  .wrap {
    position: relative; z-index: 2;
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    padding: 24px; perspective: 1200px;
  }
  .card3d {
    width: 100%; max-width: 440px;
    background: linear-gradient(180deg, rgba(18,22,43,.78), rgba(14,17,36,.78));
    border-radius: 24px;
    padding: 2px; /* gradient border holder */
    position: relative;
    transform-style: preserve-3d;
    transform: rotateX(2deg);
    box-shadow:
      0 40px 80px rgba(99,102,241,.25),
      0 0 0 1px rgba(255,255,255,.05);
    animation: cardIn .6s cubic-bezier(.2,.9,.3,1.2) both;
  }
  @keyframes cardIn { from { opacity: 0; transform: rotateX(10deg) translateY(20px); } to { opacity: 1; transform: rotateX(2deg); } }
  .card3d::before {
    content: ''; position: absolute; inset: 0; border-radius: 24px; padding: 1.5px;
    background: conic-gradient(from 0deg, var(--p1), var(--p2), var(--p3), var(--p4), var(--p1));
    -webkit-mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
            mask: linear-gradient(#000 0 0) content-box, linear-gradient(#000 0 0);
    -webkit-mask-composite: xor;
            mask-composite: exclude;
    animation: spin 10s linear infinite;
    opacity: .9;
  }
  @keyframes spin { to { transform: rotate(360deg); } }

  .inner {
    background: rgba(10,12,25,.78);
    backdrop-filter: blur(16px) saturate(140%);
    -webkit-backdrop-filter: blur(16px) saturate(140%);
    border-radius: 22px;
    padding: 36px 32px 32px;
  }

  .shield {
    width: 72px; height: 72px; border-radius: 20px;
    background: linear-gradient(135deg, var(--p1), var(--p2));
    display: flex; align-items: center; justify-content: center;
    color: #fff; font-size: 32px; margin: 0 auto 18px;
    box-shadow:
      0 20px 40px rgba(99,102,241,.5),
      inset 0 1px 0 rgba(255,255,255,.3);
    position: relative;
  }
  .shield::after {
    content: ''; position: absolute; inset: -8px; border-radius: 24px;
    background: inherit; filter: blur(16px); opacity: .55; z-index: -1;
  }

  h1 {
    font-family: 'Space Grotesk', sans-serif;
    font-size: 28px; font-weight: 700; margin: 0 0 6px; text-align: center;
    letter-spacing: -.02em;
    background: linear-gradient(90deg, #fff 0%, #C7D2FE 60%, #F5D0FE 100%);
    -webkit-background-clip: text;  background-clip: text; color: transparent;
  }
  .sub { text-align: center; color: #94A3B8; font-size: 14px; margin: 0 0 26px; }

  label { display: block; font-size: 12.5px; font-weight: 500; color: #CBD5E1; margin: 0 0 6px 2px; letter-spacing: .02em; }

  .field {
    position: relative; margin-bottom: 14px;
  }
  .input {
    width: 100%;
    background: rgba(255,255,255,.03);
    border: 1px solid rgba(255,255,255,.08);
    color: #F8FAFC; font-size: 15px;
    padding: 13px 14px 13px 44px;
    border-radius: 12px;
    outline: none;
    transition: border-color .15s, box-shadow .15s, background .15s;
  }
  .input::placeholder { color: #64748B; }
  .input:focus {
    border-color: rgba(139,92,246,.6);
    box-shadow: 0 0 0 4px rgba(139,92,246,.18);
    background: rgba(255,255,255,.05);
  }
  .input-wrap { position: relative; }
  .input-wrap > i { position: absolute; left: 14px; top: 50%; transform: translateY(-50%); color: #818CF8; font-size: 17px; pointer-events: none; }

  .btn {
    display: block; width: 100%; margin-top: 10px;
    padding: 14px 20px;
    border: 0; border-radius: 12px;
    background: linear-gradient(135deg, var(--p1), var(--p2));
    color: #fff; font-size: 15px; font-weight: 600; letter-spacing: .01em;
    cursor: pointer; position: relative;
    box-shadow:
      0 14px 28px rgba(99,102,241,.45),
      inset 0 1px 0 rgba(255,255,255,.3);
    transition: transform .15s, box-shadow .18s;
  }
  .btn:hover { transform: translateY(-2px); box-shadow: 0 22px 40px rgba(99,102,241,.55), inset 0 1px 0 rgba(255,255,255,.4); }
  .btn:active { transform: translateY(0); }
  .btn i { margin-right: 8px; }

  .footer-row { margin-top: 22px; display: flex; justify-content: space-between; align-items: center; font-size: 13px; color: #94A3B8; }
  .footer-row a { color: #A5B4FC; text-decoration: none; }
  .footer-row a:hover { color: #C7D2FE; }

  .alert {
    border-radius: 12px; padding: 11px 14px; margin-bottom: 14px; font-size: 13.5px;
    border: 1px solid transparent; display: flex; align-items: center; gap: 10px;
  }
  .alert i { font-size: 16px; }
  .alert-danger  { background: rgba(239,68,68,.1);  border-color: rgba(239,68,68,.35);  color: #FCA5A5; }
  .alert-success { background: rgba(16,185,129,.1); border-color: rgba(16,185,129,.35); color: #6EE7B7; }
  .alert-warning { background: rgba(245,158,11,.1); border-color: rgba(245,158,11,.35); color: #FCD34D; }
  .alert-info    { background: rgba(14,165,233,.1); border-color: rgba(14,165,233,.35); color: #7DD3FC; }

  .tag {
    position: absolute; top: 20px; left: 50%; transform: translateX(-50%);
    font-size: 11.5px; text-transform: uppercase; letter-spacing: .14em;
    color: #A5B4FC; background: rgba(99,102,241,.1);
    border: 1px solid rgba(99,102,241,.3);
    padding: 6px 12px; border-radius: 999px;
    z-index: 3;
  }

  @media (max-width: 480px) {
    .inner { padding: 28px 22px; }
    h1 { font-size: 24px; }
  }
</style>
</head>
<body>
  <div class="bg"></div>
  <div class="grid"></div>
  <div class="orb a"></div>
  <div class="orb b"></div>
  <div class="orb c"></div>

  <main class="wrap">
    <div class="card3d">
      <div class="inner">
        <div class="shield"><i class="bi bi-shield-lock-fill"></i></div>
        <h1>Admin Console</h1>
        <p class="sub">Sign in to manage the platform.</p>

        <?php foreach (flash_pull() as $m):
            $t = in_array($m['type'] ?? '', ['danger','success','warning','info'], true) ? $m['type'] : 'info';
            $icon = ['danger'=>'x-circle-fill','success'=>'check-circle-fill','warning'=>'exclamation-triangle-fill','info'=>'info-circle-fill'][$t];
        ?>
          <div class="alert alert-<?= $t ?>"><i class="bi bi-<?= $icon ?>"></i><?= e($m['msg']) ?></div>
        <?php endforeach; ?>

        <form method="post" action="<?= url('admin/') ?>" autocomplete="off">
          <?= csrf_field() ?>
          <div class="field">
            <label for="u">Username</label>
            <div class="input-wrap">
              <i class="bi bi-person"></i>
              <input class="input" id="u" name="username" value="<?= old('username') ?>" required autofocus placeholder="admin">
            </div>
          </div>
          <div class="field">
            <label for="p">Password</label>
            <div class="input-wrap">
              <i class="bi bi-key"></i>
              <input class="input" id="p" name="password" type="password" required placeholder="••••••••">
            </div>
          </div>
          <button class="btn" type="submit"><i class="bi bi-arrow-right-circle"></i>Enter admin console</button>
        </form>

      </div>
    </div>
  </main>

</body>
</html>

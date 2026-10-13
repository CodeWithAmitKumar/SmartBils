<?php
// Root login — Shop user (owner/staff). Royal futuristic split-screen.
require_once __DIR__ . '/config/app.php';

if (is_logged_in()) {
    $u = current_user();
    redirect(($u['user_type'] ?? '') === 'super_admin' ? 'admin/dashboard.php' : 'client/dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $identifier = trim((string)($_POST['username'] ?? ''));
    $password   = (string)($_POST['password'] ?? '');
    keep_old(['username' => $identifier]);

    if ($identifier === '' || $password === '') {
        flash('danger', 'Please enter your username and password.');
        redirect('index.php');
    }
    if (auth_is_throttled($identifier, 'shop')) {
        flash('danger', 'Too many failed attempts. Try again in ' . AUTH_WINDOW_MIN . ' minutes.');
        redirect('index.php');
    }
    $user = auth_attempt_shop($identifier, $password);
    auth_record_attempt($identifier, 'shop', (bool)$user);
    if (!$user) {
        flash('danger', 'Invalid credentials.');
        redirect('index.php');
    }
    auth_login_shop($user);
    flash('success', 'Welcome back, ' . $user['name'] . '.');
    redirect('client/dashboard.php');
}

$pageTitle = 'Shop Login';
?>
<!doctype html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= e($pageTitle) ?> — <?= e(APP_NAME) ?></title>
<link rel="icon" type="image/png" href="<?= url('uploads/logo/favicon.png') ?>">
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<link href="https://fonts.googleapis.com/css2?family=Playfair+Display:ital,wght@0,500;0,600;0,700;1,500;1,600&family=Cormorant+Garamond:wght@500;600&family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
  :root {
    --royal-950: #0C0820;
    --royal-900: #171039;
    --royal-800: #2A1865;
    --royal-700: #4A1A94;
    --royal-600: #6D28D9;
    --royal-500: #8B5CF6;
    --wine:      #7B0F3A;
    --gold-300:  #FDE68A;
    --gold-400:  #F6CB5B;
    --gold-500:  #D9A441;
    --gold-600:  #B07E22;
    --cream:     #FBF7EC;
    --cream-2:   #F4ECD6;
    --ink:       #15102E;
    --ink-soft:  #4B4269;
  }
  *,*::before,*::after { box-sizing: border-box; }
  html, body { height: 100%; margin: 0; }
  body {
    font-family: 'Inter', system-ui, -apple-system, sans-serif;
    color: var(--ink);
    background: var(--cream);
    -webkit-font-smoothing: antialiased;
    overflow-x: hidden;
  }

  .stage { min-height: 100vh; display: flex; perspective: 1500px; }

  /* ========================================================= */
  /* LEFT — royal visual pane                                   */
  /* ========================================================= */
  .left {
    flex: 1.1;
    position: relative;
    color: #fff;
    padding: 56px 48px;
    display: flex; flex-direction: column; justify-content: space-between;
    overflow: hidden;
    background:
      radial-gradient(circle at 18% 18%, rgba(246,203,91,.18), transparent 55%),
      radial-gradient(circle at 85% 80%, rgba(123,15,58,.4), transparent 60%),
      radial-gradient(circle at 50% 50%, rgba(109,40,217,.45), transparent 70%),
      linear-gradient(135deg, #0C0820 0%, #2A1865 40%, #4A1A94 72%, #171039 100%);
  }
  /* Fine gold dot grid */
  .left::before {
    content:''; position: absolute; inset: 0; pointer-events: none;
    background-image: radial-gradient(circle at 1px 1px, rgba(246,203,91,.18) 1px, transparent 1.5px);
    background-size: 24px 24px;
    mask-image: radial-gradient(ellipse at 30% 40%, #000 20%, transparent 80%);
    -webkit-mask-image: radial-gradient(ellipse at 30% 40%, #000 20%, transparent 80%);
  }
  /* Diagonal shimmering shine */
  .left::after {
    content:''; position: absolute; top: -40%; left: -20%;
    width: 60%; height: 180%;
    background: linear-gradient(100deg, transparent 46%, rgba(246,203,91,.09) 50%, transparent 54%);
    transform: rotate(14deg); pointer-events: none;
    animation: streak 10s ease-in-out infinite;
  }
  @keyframes streak {
    0%,100% { transform: translateX(-30%) rotate(14deg); }
    50%     { transform: translateX(140%) rotate(14deg); }
  }

  .orn { position: absolute; opacity: .22; pointer-events: none; color: var(--gold-400); }
  .orn-tl { top: 24px; left: 24px; font-size: 56px; }
  .orn-br { bottom: 24px; right: 24px; font-size: 56px; transform: rotate(180deg); }

  .ring { position: absolute; border-radius: 50%; border: 1px solid rgba(246,203,91,.2); pointer-events: none; }
  .ring.a { width: 540px; height: 540px; top: -110px; right: -160px; }
  .ring.b { width: 360px; height: 360px; top: -40px;  right: -60px; border-color: rgba(246,203,91,.28); }
  .ring.c { width: 220px; height: 220px; top:  50px;  right: 60px; border-color: rgba(246,203,91,.4); }

  /* Floating gold sparkles */
  .sparkle {
    position: absolute; width: 4px; height: 4px; border-radius: 50%;
    background: radial-gradient(circle, #FDE68A 0%, transparent 70%);
    box-shadow: 0 0 10px rgba(246,203,91,.9);
    opacity: .9; animation: twinkle 3s ease-in-out infinite;
    pointer-events: none;
  }
  @keyframes twinkle { 0%,100% { opacity: .25; transform: scale(.6); } 50% { opacity: .95; transform: scale(1.15); } }
  .s1 { top: 15%; left: 30%; animation-delay: 0s; }
  .s2 { top: 60%; left: 20%; animation-delay: 1.2s; }
  .s3 { top: 35%; left: 65%; animation-delay: .6s; width:6px; height:6px; }
  .s4 { top: 78%; left: 55%; animation-delay: 1.8s; }
  .s5 { top: 20%; left: 80%; animation-delay: 2.4s; width:5px; height:5px; }

  .brand {
    position: relative; z-index: 2;
    display: flex; align-items: center; gap: 14px;
    transition: transform .3s;
  }
  .brand:hover { transform: translateY(-2px); }
  .form-brand { display: flex; justify-content: center; margin: 0 0 10px; }
  .form-brand-logo {
    max-height: 70px; max-width: 220px; object-fit: contain;
    filter: drop-shadow(0 6px 14px rgba(76,29,149,.25));
  }
  .crest {
    width: 52px; height: 52px; border-radius: 14px;
    background: linear-gradient(135deg, var(--gold-300) 0%, var(--gold-500) 60%, var(--gold-600) 100%);
    color: var(--royal-900);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 24px;
    box-shadow:
      0 12px 28px rgba(246,203,91,.4),
      inset 0 1px 0 rgba(255,255,255,.55),
      inset 0 -2px 4px rgba(176,126,34,.4);
    transition: transform .3s;
  }
  .brand:hover .crest { transform: rotate(-8deg) scale(1.08); }
  .brand-text { font-family: 'Playfair Display', serif; font-size: 22px; font-weight: 600; letter-spacing: .02em; }
  .brand-sub  { font-size: 12px; opacity: .72; letter-spacing: .22em; text-transform: uppercase; margin-top: 2px; }

  .pitch { position: relative; z-index: 2; max-width: 480px; }
  .vip-tag {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 14px; border-radius: 999px;
    background: rgba(246,203,91,.1);
    border: 1px solid rgba(246,203,91,.35);
    color: var(--gold-400); font-size: 11.5px; font-weight: 600;
    letter-spacing: .2em; text-transform: uppercase; margin-bottom: 20px;
  }
  .vip-tag i { font-size: 13px; }
  .pitch h2 {
    font-family: 'Playfair Display', serif;
    font-size: 48px; font-weight: 600; line-height: 1.08;
    margin: 0 0 18px; letter-spacing: -.01em;
    text-shadow: 0 2px 24px rgba(0,0,0,.4);
  }
  .pitch h2 em {
    font-style: italic; font-weight: 500;
    background: linear-gradient(90deg, var(--gold-300), var(--gold-500) 50%, var(--gold-300));
    background-size: 200% auto;
    -webkit-background-clip: text; background-clip: text; color: transparent;
    animation: shimmer 4s linear infinite;
  }
  @keyframes shimmer { to { background-position: -200% center; } }
  .pitch p { font-size: 15px; line-height: 1.75; margin: 0; opacity: .9; max-width: 420px; }

  .feats { position: relative; z-index: 2; display: flex; gap: 12px; flex-wrap: wrap; }
  .feat {
    display: flex; align-items: center; gap: 10px;
    font-size: 13px; font-weight: 500;
    padding: 10px 14px; border-radius: 10px;
    background: rgba(255,255,255,.05);
    border: 1px solid rgba(246,203,91,.18);
    backdrop-filter: blur(8px);
    transition: transform .2s, background .2s, border-color .2s, box-shadow .2s;
    cursor: default;
  }
  .feat:hover {
    transform: translateY(-4px);
    background: rgba(246,203,91,.1);
    border-color: rgba(246,203,91,.4);
    box-shadow: 0 10px 24px rgba(0,0,0,.3);
  }
  .feat i { color: var(--gold-400); font-size: 16px; filter: drop-shadow(0 0 6px rgba(246,203,91,.6)); }

  /* ========================================================= */
  /* RIGHT — form pane                                          */
  /* ========================================================= */
  .right {
    flex: 0.9;
    background: linear-gradient(135deg, var(--cream) 0%, var(--cream-2) 100%);
    display: flex; align-items: center; justify-content: center;
    padding: 48px 40px;
    position: relative;
  }
  /* Diagonal pin-stripe + noise texture */
  .right::before {
    content: ''; position: absolute; inset: 0; pointer-events: none;
    background-image:
      linear-gradient(135deg, transparent 49%, rgba(217,164,65,.08) 50%, transparent 51%);
    background-size: 44px 44px;
  }
  .right::after {
    content: ''; position: absolute; inset: 0; pointer-events: none; opacity: .4;
    background-image: url("data:image/svg+xml;utf8,<svg xmlns='http://www.w3.org/2000/svg' width='120' height='120'><filter id='n'><feTurbulence type='fractalNoise' baseFrequency='.9' numOctaves='2' stitchTiles='stitch'/><feColorMatrix values='0 0 0 0 0.5 0 0 0 0 0.4 0 0 0 0 0.2 0 0 0 .07 0'/></filter><rect width='100%' height='100%' filter='url(%23n)'/></svg>");
  }

  /* ===== 3D card ===== */
  .form-card {
    width: 100%; max-width: 440px;
    position: relative; z-index: 2;
    background: #fff;
    border-radius: 22px;
    padding: 44px 36px 32px;
    transform-style: preserve-3d;
    transform: rotateX(2deg);
    box-shadow:
      0 36px 80px rgba(76,29,149,.22),
      0 10px 30px rgba(217,164,65,.15),
      0 0 0 1px rgba(217,164,65,.18);
    animation: cardIn .7s cubic-bezier(.2,.9,.3,1.2) both;
    transition: transform .25s ease, box-shadow .25s ease;
  }
  @keyframes cardIn { from { opacity:0; transform: rotateX(10deg) translateY(24px); } to { opacity:1; transform: rotateX(2deg); } }

  /* VIP ribbon chip above title */
  .micro-tag {
    display: flex; align-items: center; justify-content: center; gap: 8px;
    margin: 0 0 16px;
  }
  .micro-tag span {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 6px 14px; border-radius: 999px;
    background: linear-gradient(135deg, rgba(246,203,91,.14), rgba(217,164,65,.08));
    border: 1px solid rgba(217,164,65,.4);
    color: var(--gold-600);
    font-size: 11px; font-weight: 700; letter-spacing: .22em; text-transform: uppercase;
  }
  .micro-tag i { font-size: 12px; color: var(--gold-500); }

  h1 {
    font-family: 'Playfair Display', serif;
    font-weight: 600; font-size: 36px; letter-spacing: -.01em; line-height: 1.1;
    margin: 0 0 6px; text-align: center;
    background: linear-gradient(90deg, var(--royal-900) 0%, var(--royal-700) 40%, var(--wine) 70%, var(--royal-900) 100%);
    background-size: 200% auto;
    -webkit-background-clip: text; background-clip: text; color: transparent;
    animation: shimmer 6s linear infinite;
  }
  h1 .inline-crown {
    display: inline-block; vertical-align: -6px;
    width: 32px; height: 32px; margin-right: 10px; border-radius: 10px;
    background: linear-gradient(135deg, var(--gold-300), var(--gold-500) 60%, var(--gold-600));
    color: var(--royal-900);
    display: inline-flex; align-items: center; justify-content: center;
    font-size: 16px; vertical-align: middle;
    box-shadow: 0 6px 14px rgba(217,164,65,.4), inset 0 1px 0 rgba(255,255,255,.5);
  }
  .sub { text-align: center; color: var(--ink-soft); font-size: 14.5px; margin: 0; }

  .ornline { display: flex; align-items: center; justify-content: center; gap: 12px; margin: 20px 0 24px; color: var(--gold-500); font-size: 14px; }
  .ornline::before, .ornline::after {
    content:''; flex: 1; height: 1px;
    background: linear-gradient(90deg, transparent, var(--gold-500), transparent);
  }
  .ornline i { filter: drop-shadow(0 0 6px rgba(217,164,65,.5)); }

  label { display: block; font-size: 11.5px; font-weight: 600; color: var(--ink-soft); margin: 0 0 6px 2px; letter-spacing: .12em; text-transform: uppercase; }
  .field { position: relative; margin-bottom: 14px; }
  .label-row { display: flex; justify-content: space-between; align-items: center; margin-bottom: 6px; }
  .forgot-row { display: flex; justify-content: center; margin-top: 10px; }
  .forgot { font-size: 12px; font-weight: 600; letter-spacing: .06em; text-transform: uppercase;
            color: var(--royal-700); text-decoration: none;
            padding: 4px 10px; border-radius: 6px;
            transition: background .18s, color .18s; }
  .forgot:hover { background: rgba(217,164,65,.12); color: var(--wine); }
  .forgot i { margin-left: 4px; font-size: 11px; }

  .input {
    width: 100%;
    background: #FBF8EE;
    border: 1.5px solid #E8DFC9;
    color: var(--ink); font-size: 15px;
    padding: 14px 16px 14px 46px;
    border-radius: 10px; outline: none;
    transition: border-color .2s, box-shadow .2s, background .2s, transform .2s;
    font-family: 'Inter', sans-serif;
  }
  .input::placeholder { color: #A49A80; }
  .input:hover { border-color: #D9C999; background: #fff; }
  .input:focus {
    border-color: var(--gold-500);
    box-shadow: 0 0 0 4px rgba(217,164,65,.2);
    background: #fff;
    transform: translateY(-1px);
  }
  .input-wrap { position: relative; }
  .input-wrap > i { position: absolute; left: 16px; top: 50%; transform: translateY(-50%); color: var(--gold-600); font-size: 17px; pointer-events: none; transition: transform .2s, color .2s; }
  .input-wrap:focus-within > i { color: var(--royal-700); transform: translateY(-50%) scale(1.1); }

  .btn {
    display: block; width: 100%; margin-top: 18px;
    padding: 15px 20px;
    border: 0; border-radius: 12px;
    background: linear-gradient(135deg, var(--royal-700) 0%, var(--royal-900) 55%, var(--royal-950) 100%);
    color: #fff; font-size: 15px; font-weight: 600; letter-spacing: .02em;
    cursor: pointer; position: relative; overflow: hidden;
    box-shadow:
      0 14px 28px rgba(76,29,149,.35),
      0 2px 0 rgba(217,164,65,.3),
      inset 0 1px 0 rgba(255,255,255,.2);
    transition: transform .18s cubic-bezier(.2,.9,.3,1.4), box-shadow .22s;
  }
  .btn:hover {
    transform: translateY(-3px);
    box-shadow:
      0 22px 44px rgba(76,29,149,.5),
      0 2px 0 rgba(217,164,65,.5),
      inset 0 1px 0 rgba(255,255,255,.3);
  }
  .btn:active { transform: translateY(-1px); }
  .btn::before {
    content: ''; position: absolute; inset: 0;
    background: linear-gradient(120deg, transparent 38%, rgba(246,203,91,.3) 50%, transparent 62%);
    transform: translateX(-100%);
    transition: transform .7s cubic-bezier(.2,.9,.3,1);
  }
  .btn:hover::before { transform: translateX(100%); }
  .btn i { margin-right: 8px; }

  .foot { margin-top: 20px; text-align: center; font-size: 13px; color: var(--ink-soft); }
  .foot .dot { color: var(--gold-500); margin: 0 6px; }

  .alert {
    border-radius: 10px; padding: 11px 14px; margin-bottom: 14px; font-size: 13.5px;
    border: 1px solid transparent; display: flex; align-items: center; gap: 10px;
  }
  .alert i { font-size: 16px; }
  .alert-danger  { background: #FEF2F2; border-color: #FECACA; color: #B91C1C; }
  .alert-success { background: #F0FDF4; border-color: #BBF7D0; color: #166534; }
  .alert-warning { background: #FEFCE8; border-color: #FEF08A; color: #854D0E; }
  .alert-info    { background: #EFF6FF; border-color: #BFDBFE; color: #1E40AF; }

  /* ===== Responsive ===== */
  @media (max-width: 900px) {
    .stage { flex-direction: column; perspective: none; }
    .left { padding: 36px 24px 44px; min-height: 280px; }
    .pitch h2 { font-size: 30px; }
    .right { padding: 40px 20px; }
    .form-card { padding: 36px 24px 28px; transform: none !important; }
    .feats { gap: 10px; }
  }
  @media (max-width: 480px) {
    .pitch h2 { font-size: 24px; }
    h1 { font-size: 28px; }
    h1 .inline-crown { width: 26px; height: 26px; font-size: 14px; }
  }
</style>
</head>
<body>
<div class="stage">
  <!-- LEFT -->
  <section class="left">
    <i class="bi bi-flower1 orn orn-tl"></i>
    <i class="bi bi-flower1 orn orn-br"></i>
    <div class="ring a"></div>
    <div class="ring b"></div>
    <div class="ring c"></div>
    <div class="sparkle s1"></div><div class="sparkle s2"></div><div class="sparkle s3"></div>
    <div class="sparkle s4"></div><div class="sparkle s5"></div>

    <div class="brand"></div>

    <div class="pitch">
      <span class="vip-tag"><i class="bi bi-stars"></i> Premium Workspace</span>
      <h2>Run your shop with <em>elegance</em> &amp; precision.</h2>
      <p>Fast billing, accurate stock, trusted dues — crafted for the shopkeeper who values clarity and control.</p>
    </div>

    <div class="feats">
      <div class="feat"><i class="bi bi-lightning-charge-fill"></i> Fast POS</div>
      <div class="feat"><i class="bi bi-shield-check"></i> Secure</div>
      <div class="feat"><i class="bi bi-graph-up-arrow"></i> Clear reports</div>
      <div class="feat"><i class="bi bi-cash-coin"></i> Dues tracked</div>
    </div>
  </section>

  <!-- RIGHT -->
  <section class="right">
    <div class="form-card" id="formCard">
      <div class="form-brand">
        <img src="<?= url('uploads/logo/smartbils_logo_transparent.png') ?>"
             alt="<?= e(APP_NAME) ?>" class="form-brand-logo">
      </div>
      <h1>Welcome back</h1>
      <p class="sub">Sign in to continue to your workspace</p>
      <div class="ornline"><i class="bi bi-gem"></i></div>

      <?php foreach (flash_pull() as $m):
          $t = in_array($m['type'] ?? '', ['danger','success','warning','info'], true) ? $m['type'] : 'info';
          $icon = ['danger'=>'x-circle-fill','success'=>'check-circle-fill','warning'=>'exclamation-triangle-fill','info'=>'info-circle-fill'][$t];
      ?>
        <div class="alert alert-<?= $t ?>"><i class="bi bi-<?= $icon ?>"></i><?= e($m['msg']) ?></div>
      <?php endforeach; ?>

      <form method="post" action="<?= url('index.php') ?>" autocomplete="off">
        <?= csrf_field() ?>
        <div class="field">
          <label for="u">Username or Email</label>
          <div class="input-wrap">
            <i class="bi bi-person-fill"></i>
            <input class="input" id="u" name="username" value="<?= old('username') ?>" required autofocus placeholder="your username">
          </div>
        </div>

        <div class="field">
          <label for="p">Password</label>
          <div class="input-wrap">
            <i class="bi bi-key-fill"></i>
            <input class="input" id="p" name="password" type="password" required placeholder="••••••••">
          </div>
          <div class="forgot-row">
            <a class="forgot" href="<?= url('forgot-password.php') ?>">Forgot password? <i class="bi bi-arrow-right"></i></a>
          </div>
        </div>

        <button class="btn" type="submit"><i class="bi bi-arrow-right-circle-fill"></i>Enter your workspace</button>
      </form>
    </div>
  </section>
</div>

<script>
  // Parallax tilt on the form card (pointer-only, desktop widths).
  const card = document.getElementById('formCard');
  if (card && matchMedia('(pointer:fine)').matches && innerWidth > 900) {
    const pane = card.parentElement;
    pane.addEventListener('mousemove', (e) => {
      const r = pane.getBoundingClientRect();
      const x = ((e.clientX - r.left) / r.width  - .5) * 8;
      const y = ((e.clientY - r.top)  / r.height - .5) * -8;
      card.style.transform = `rotateY(${x}deg) rotateX(${2 + y}deg) translateZ(0)`;
    });
    pane.addEventListener('mouseleave', () => { card.style.transform = 'rotateX(2deg)'; });
  }
</script>
</body>
</html>

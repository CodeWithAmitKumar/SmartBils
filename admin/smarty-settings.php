<?php
require_once __DIR__ . '/../config/app.php';
require_super_admin();

$errors = []; $notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    try {
        $provider  = 'gemini'; // only provider in Phase 1
        $model     = trim((string)($_POST['model']  ?? 'gemini-2.0-flash')) ?: 'gemini-2.0-flash';
        $apiKey    = trim((string)($_POST['api_key'] ?? ''));
        $enabled   = !empty($_POST['enabled']);
        $maxTokens = max(100, min(2000, (int)($_POST['max_tokens'] ?? 600)));

        smarty_config_save($provider, $model, $apiKey, $enabled, $maxTokens);
        audit('settings.smarty_update', 'smarty_config', 1);
        flash('success', 'Smarty settings saved.');
        redirect('admin/smarty-settings.php');
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$cfg = smarty_config();
$hasKey = $cfg['api_key'] !== '';

// Quick usage snapshot
$usage = db()->query("
  SELECT COUNT(*) AS total,
    SUM(ok=1) AS ok,
    SUM(created_at >= NOW() - INTERVAL 1 DAY) AS last24,
    COALESCE(SUM(tokens_in),0)+COALESCE(SUM(tokens_out),0) AS tokens
  FROM smarty_log")->fetch() ?: ['total'=>0,'ok'=>0,'last24'=>0,'tokens'=>0];

$pageTitle = 'Smarty — AI Assistant';
require __DIR__ . '/../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Smarty · AI Assistant</h1>
    <p class="sb-page-subtitle">Platform-wide. Set the API key once; every shop gets Smarty on their panel.</p>
  </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= e($notice[0]) ?>"><?= e($notice[1]) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-sm-6 col-xl-3">
    <div class="sb-stat"><div class="sb-stat-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="bi bi-chat-dots"></i></div>
      <div><div class="sb-stat-value"><?= (int)$usage['total'] ?></div><div class="sb-stat-label">Total messages</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="sb-stat"><div class="sb-stat-icon" style="background:#D1FAE5;color:#047857;"><i class="bi bi-check2-circle"></i></div>
      <div><div class="sb-stat-value"><?= (int)$usage['ok'] ?></div><div class="sb-stat-label">Successful</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="sb-stat"><div class="sb-stat-icon" style="background:#DBEAFE;color:#1D4ED8;"><i class="bi bi-clock-history"></i></div>
      <div><div class="sb-stat-value"><?= (int)$usage['last24'] ?></div><div class="sb-stat-label">Last 24 h</div></div></div>
  </div>
  <div class="col-sm-6 col-xl-3">
    <div class="sb-stat"><div class="sb-stat-icon" style="background:#FEF3C7;color:#B45309;"><i class="bi bi-cpu"></i></div>
      <div><div class="sb-stat-value"><?= (int)$usage['tokens'] ?></div><div class="sb-stat-label">Tokens used</div></div></div>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">Gemini configuration</div>
  <div class="card-body">
    <form method="post" class="js-once">
      <?= csrf_field() ?>
      <div class="row g-3">
        <div class="col-md-3">
          <label class="form-label">Enabled</label>
          <div class="form-check form-switch mt-2">
            <input class="form-check-input" type="checkbox" id="enabled" name="enabled" value="1" <?= (int)$cfg['enabled'] === 1 ? 'checked' : '' ?>>
            <label class="form-check-label" for="enabled">Turn Smarty on for all shops</label>
          </div>
        </div>
        <div class="col-md-4">
          <label class="form-label">Model</label>
          <select class="form-select" name="model">
            <?php
            $models = [
                'gemini-flash-latest',       // tracks newest Flash — recommended
                'gemini-flash-lite-latest',  // tracks newest Flash-Lite (cheapest)
                'gemini-pro-latest',         // tracks newest Pro
                'gemini-3.8-flash',
                'gemini-3.5-flash-lite',
            ];
            // Keep whatever was saved even if not in the preset list.
            if ($cfg['model'] && !in_array($cfg['model'], $models, true)) $models[] = $cfg['model'];
            foreach ($models as $m): ?>
              <option value="<?= e($m) ?>" <?= $cfg['model'] === $m ? 'selected' : '' ?>><?= e($m) ?></option>
            <?php endforeach; ?>
          </select>
          <div class="form-text">Flash is free-tier friendly; Pro is slower + stricter rate limit. <em>-latest</em> aliases auto-upgrade.</div>
        </div>
        <div class="col-md-2">
          <label class="form-label">Max tokens</label>
          <input class="form-control" type="number" min="100" max="2000" name="max_tokens" value="<?= (int)$cfg['max_tokens'] ?>">
          <div class="form-text">Per response (100–2000).</div>
        </div>
        <div class="col-md-3">
          <label class="form-label">API key</label>
          <input class="form-control" type="password" name="api_key"
                 placeholder="<?= $hasKey ? '•••• (saved — leave blank to keep)' : 'AIzaSy…' ?>">
          <div class="form-text">
            From <a href="https://aistudio.google.com/app/apikey" target="_blank" rel="noopener">Google AI Studio</a>.
          </div>
        </div>
      </div>
      <button class="btn btn-primary mt-3"><i class="bi bi-check2 me-1"></i>Save</button>
    </form>
  </div>
</div>

<div class="card mt-3">
  <div class="card-header">Recent messages</div>
  <div class="table-responsive">
    <?php
      $rows = db()->query("SELECT sl.*, s.name AS shop_name
                           FROM smarty_log sl LEFT JOIN shops s ON s.id = sl.shop_id
                           ORDER BY sl.id DESC LIMIT 50")->fetchAll();
    ?>
    <table class="table sb-datatable" data-order='[[0,"desc"]]'>
      <thead><tr><th>When</th><th>Shop</th><th>Question</th><th>Answer</th><th>OK?</th><th>Tokens</th></tr></thead>
      <tbody>
      <?php foreach ($rows as $r): ?>
        <tr>
          <td data-order="<?= e($r['created_at']) ?>" class="small text-muted"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
          <td class="small"><?= e($r['shop_name'] ?? '—') ?></td>
          <td class="small" style="max-width:280px; word-break:break-word;"><?= e(mb_substr($r['question'], 0, 180)) ?></td>
          <td class="small" style="max-width:320px; word-break:break-word;"><?= e(mb_substr((string)$r['answer'], 0, 180)) ?></td>
          <td><?= $r['ok'] ? '<span class="text-success">✓</span>' : '<span class="text-danger" title="' . e($r['error']) . '">✗</span>' ?></td>
          <td class="small text-muted"><?= (int)$r['tokens_in'] + (int)$r['tokens_out'] ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../includes/layout-bottom.php'; ?>

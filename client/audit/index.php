<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
// Only owners (implicit all) or users with settings.manage can view the audit trail.
if ((current_user()['user_type'] ?? '') !== 'shop_owner') require_permission('settings.manage');

$shopId = current_shop_id();

$rows = db()->prepare('
  SELECT al.*,
         CASE al.actor_type
           WHEN "admin" THEN (SELECT name FROM admins     WHERE id = al.actor_id)
           WHEN "shop"  THEN (SELECT name FROM shop_users WHERE id = al.actor_id)
         END AS actor_name
  FROM audit_logs al
  WHERE al.shop_id = :sid
  ORDER BY al.id DESC
  LIMIT 500');
$rows->execute([':sid' => $shopId]);
$events = $rows->fetchAll();

$pageTitle = 'Audit Log';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Audit Log</h1>
    <p class="sb-page-subtitle">Last <?= count($events) ?> events on this shop.</p>
  </div>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[0,"desc"]]'>
      <thead><tr><th>When</th><th>Actor</th><th>Action</th><th>Target</th><th>Context</th><th>IP</th></tr></thead>
      <tbody>
      <?php foreach ($events as $r): ?>
        <tr>
          <td class="text-muted small" data-order="<?= e($r['created_at']) ?>"><?= e(date('d M Y H:i:s', strtotime($r['created_at']))) ?></td>
          <td>
            <?= e($r['actor_name'] ?? '—') ?>
            <div class="small text-muted"><?= e(ucfirst($r['actor_type'])) ?></div>
          </td>
          <td><code><?= e($r['action']) ?></code></td>
          <td><?= $r['target_type'] ? e($r['target_type']) . '#' . (int)$r['target_id'] : '—' ?></td>
          <td class="small text-muted" style="max-width:280px; word-break:break-word;"><?= e($r['context'] ?? '') ?></td>
          <td class="small text-muted"><?= e($r['ip_address'] ?? '') ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

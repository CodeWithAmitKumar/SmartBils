<?php
$messages = flash_pull();
if ($messages):
    $icon = ['success' => 'check-circle', 'danger' => 'x-circle', 'warning' => 'exclamation-triangle', 'info' => 'info-circle'];
?>
  <div class="sb-flash-stack" aria-live="polite">
    <?php foreach ($messages as $m):
        $type = in_array($m['type'] ?? '', ['success','danger','warning','info'], true) ? $m['type'] : 'info';
    ?>
      <div class="alert alert-<?= e($type) ?> alert-dismissible fade show sb-flash" role="alert">
        <i class="bi bi-<?= e($icon[$type] ?? 'info-circle') ?> me-2"></i>
        <?= e($m['msg']) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
      </div>
    <?php endforeach; ?>
  </div>
<?php endif; ?>

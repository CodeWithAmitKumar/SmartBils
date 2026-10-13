<?php
require_once __DIR__ . '/../../config/app.php';
require_super_admin();
require_once __DIR__ . '/../../includes/backup.php';

$backupDir = dirname(__DIR__, 2) . '/storage/backups';

// Direct download
if (($_GET['do'] ?? '') === 'download') {
    audit('backup.download', 'db', null);
    backup_stream_sql();
    exit;
}

$errors = []; $notice = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $op = $_POST['op'] ?? '';

    try {
        if ($op === 'save') {
            [$ok, $pathOrErr] = backup_save_to_file($backupDir);
            if (!$ok) throw new RuntimeException($pathOrErr);
            audit('backup.save', 'db', null, ['path' => basename($pathOrErr)]);
            $notice = ['success', 'Backup saved to storage/backups/ · ' . basename($pathOrErr)];
        } elseif ($op === 'restore') {
            $confirm = trim((string)($_POST['confirm'] ?? ''));
            if ($confirm !== 'RESTORE') throw new RuntimeException('Type RESTORE in the confirm field to proceed.');
            $f = $_FILES['sql'] ?? null;
            if (!$f || $f['error'] !== UPLOAD_ERR_OK) throw new RuntimeException('Upload a .sql file.');
            if ($f['size'] > 50 * 1024 * 1024) throw new RuntimeException('SQL file too large (max 50 MB).');
            if (!is_uploaded_file($f['tmp_name'])) throw new RuntimeException('Invalid upload.');

            $n = backup_restore_sql_file($f['tmp_name']);
            audit('backup.restore', 'db', null, ['statements' => $n, 'file' => $f['name']]);
            $notice = ['success', "Restore completed. {$n} statements executed."];
        } elseif ($op === 'delete_file') {
            $name = basename((string)($_POST['file'] ?? ''));
            if ($name !== '' && preg_match('/^[A-Za-z0-9._-]+\.sql$/', $name)) {
                $target = $backupDir . '/' . $name;
                if (is_file($target)) {
                    @unlink($target);
                    audit('backup.delete', 'db', null, ['file' => $name]);
                    $notice = ['success', "Backup $name deleted."];
                }
            }
        }
    } catch (Throwable $e) { $errors[] = $e->getMessage(); }
}

$files = [];
if (is_dir($backupDir)) {
    foreach (scandir($backupDir) as $f) {
        if (str_ends_with($f, '.sql')) {
            $full = $backupDir . '/' . $f;
            $files[] = ['name' => $f, 'size' => filesize($full), 'time' => filemtime($full)];
        }
    }
    usort($files, fn($a, $b) => $b['time'] <=> $a['time']);
}

$pageTitle = 'Backups';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Database Backups</h1>
    <p class="sb-page-subtitle">Snapshot the entire SmartBils database. Backups land in <code>storage/backups/</code> (restricted via <code>.htaccess</code>).</p>
  </div>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>
<?php if ($notice): ?><div class="alert alert-<?= e($notice[0]) ?>"><?= e($notice[1]) ?></div><?php endif; ?>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Create backup</div>
      <div class="card-body">
        <p class="text-muted">Download now or save a copy on the server.</p>
        <div class="d-flex gap-2">
          <a class="btn btn-primary" href="?do=download"><i class="bi bi-cloud-download me-1"></i>Download .sql</a>
          <form method="post" class="d-inline">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="save">
            <button class="btn btn-outline-primary"><i class="bi bi-hdd me-1"></i>Save on server</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100 border-danger">
      <div class="card-header text-danger">Restore from file</div>
      <div class="card-body">
        <div class="alert alert-warning small mb-3">
          <strong>Destructive.</strong> Restore replaces the current database with the uploaded SQL.
          Always take a fresh backup first.
        </div>
        <form method="post" enctype="multipart/form-data" class="js-once">
          <?= csrf_field() ?>
          <input type="hidden" name="op" value="restore">
          <div class="mb-3">
            <label class="form-label">SQL file</label>
            <input class="form-control" type="file" name="sql" accept=".sql" required>
          </div>
          <div class="mb-3">
            <label class="form-label">Type <code>RESTORE</code> to confirm</label>
            <input class="form-control" name="confirm" placeholder="RESTORE" required>
          </div>
          <button class="btn btn-danger" data-confirm="Restore DB? This overwrites existing data.">
            <i class="bi bi-arrow-counterclockwise me-1"></i>Restore
          </button>
        </form>
      </div>
    </div>
  </div>
</div>

<?php if ($files): ?>
  <div class="card mt-3">
    <div class="card-header">Saved backups</div>
    <div class="table-responsive">
      <table class="table sb-datatable" data-order='[[1,"desc"]]' data-nosort="3">
        <thead><tr><th>File</th><th>Taken</th><th>Size</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($files as $f): ?>
          <tr>
            <td><code><?= e($f['name']) ?></code></td>
            <td data-order="<?= $f['time'] ?>"><?= e(date('d M Y H:i:s', $f['time'])) ?></td>
            <td><?= number_format($f['size'] / 1024, 1) ?> KB</td>
            <td class="text-end">
              <form method="post" class="d-inline">
                <?= csrf_field() ?>
                <input type="hidden" name="op" value="delete_file">
                <input type="hidden" name="file" value="<?= e($f['name']) ?>">
                <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this backup file?">Delete</button>
              </form>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php endif; ?>

<div class="card mt-3">
  <div class="card-body small text-muted">
    <strong>Restore testing procedure:</strong>
    1. On a dev DB, download a backup. 2. Drop a test row. 3. Upload the same .sql here with <code>RESTORE</code> confirm.
    4. Verify the dropped row is back and counts match. Document the result in your handover notes.
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

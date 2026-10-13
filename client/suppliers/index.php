<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('supplier.manage');

$shopId = current_shop_id();

$rows = db()->prepare("
    SELECT s.*,
      COALESCE((SELECT SUM(total) FROM purchases WHERE supplier_id = s.id AND status = 'received'), 0) AS total_billed,
      COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id = s.id), 0) AS total_paid
    FROM suppliers s
    WHERE s.shop_id = :sid
    ORDER BY s.name");
$rows->execute([':sid' => $shopId]);
$suppliers = $rows->fetchAll();

$pageTitle = 'Suppliers';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Suppliers</h1>
    <p class="sb-page-subtitle"><?= count($suppliers) ?> supplier<?= count($suppliers) === 1 ? '' : 's' ?></p>
  </div>
  <a href="<?= url('client/suppliers/form.php') ?>" class="btn btn-primary">
    <i class="bi bi-plus-lg me-1"></i>Add supplier
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-nosort="5">
      <thead><tr>
        <th>Name</th><th>Phone</th><th class="sb-money">Billed</th><th class="sb-money">Paid</th><th class="sb-money">Outstanding</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($suppliers as $s):
          $out = (float)$s['total_billed'] - (float)$s['total_paid'];
      ?>
        <tr>
          <td><strong><?= e($s['name']) ?></strong>
            <?php if (!$s['is_active']): ?><span class="badge-pill badge-unpaid ms-1">Inactive</span><?php endif; ?>
            <?php if ($s['email']): ?><div class="small text-muted"><?= e($s['email']) ?></div><?php endif; ?>
          </td>
          <td><?= e($s['phone'] ?? '—') ?></td>
          <td class="sb-money" data-order="<?= (float)$s['total_billed'] ?>"><?= money($s['total_billed']) ?></td>
          <td class="sb-money" data-order="<?= (float)$s['total_paid'] ?>"><?= money($s['total_paid']) ?></td>
          <td class="sb-money <?= $out > 0 ? 'text-danger fw-semibold' : '' ?>" data-order="<?= $out ?>"><?= money($out) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= url('client/suppliers/view.php?id=' . (int)$s['id']) ?>">View</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= url('client/suppliers/form.php?id=' . (int)$s['id']) ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

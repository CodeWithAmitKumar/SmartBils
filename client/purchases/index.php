<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('purchase.manage');

$shopId = current_shop_id();

$rows = db()->prepare('
    SELECT p.id, p.invoice_no, p.purchase_date, p.total, p.status, p.created_at,
           s.id AS supplier_id, s.name AS supplier_name,
           COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE purchase_id = p.id), 0) AS paid_to_this
    FROM purchases p
    JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.shop_id = :sid
    ORDER BY p.id DESC');
$rows->execute([':sid'=>$shopId]);
$purchases = $rows->fetchAll();

$pageTitle = 'Purchases';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Purchases</h1>
    <p class="sb-page-subtitle"><?= count($purchases) ?> record<?= count($purchases) === 1 ? '' : 's' ?></p>
  </div>
  <a href="<?= url('client/purchases/form.php') ?>" class="btn btn-primary">
    <i class="bi bi-bag-plus me-1"></i>New purchase
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[0,"desc"]]' data-nosort="6">
      <thead><tr>
        <th>#</th><th>Date</th><th>Supplier</th><th>Invoice</th><th>Status</th><th class="sb-money">Total</th><th class="sb-money">Paid</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($purchases as $p):
          $cls = ['received'=>'badge-paid','draft'=>'badge-pending','cancelled'=>'badge-unpaid','reversed'=>'badge-unpaid'][$p['status']] ?? 'badge-pending';
      ?>
        <tr>
          <td data-order="<?= (int)$p['id'] ?>">#<?= (int)$p['id'] ?></td>
          <td data-order="<?= e($p['purchase_date']) ?>"><?= e(date('d M Y', strtotime($p['purchase_date']))) ?></td>
          <td><a href="<?= url('client/suppliers/view.php?id=' . (int)$p['supplier_id']) ?>"><?= e($p['supplier_name']) ?></a></td>
          <td><?= e($p['invoice_no'] ?? '—') ?></td>
          <td data-order="<?= e($p['status']) ?>"><span class="badge-pill <?= $cls ?>"><?= e(ucfirst($p['status'])) ?></span></td>
          <td class="sb-money" data-order="<?= (float)$p['total'] ?>"><?= money($p['total']) ?></td>
          <td class="sb-money" data-order="<?= (float)$p['paid_to_this'] ?>"><?= money($p['paid_to_this']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('client/purchases/view.php?id=' . (int)$p['id']) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

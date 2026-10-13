<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('customer.manage');

$shopId = current_shop_id();

$rows = db()->prepare("
    SELECT c.*,
      COALESCE((SELECT SUM(grand_total) FROM sales WHERE customer_id = c.id AND status <> 'cancelled'), 0) AS billed,
      COALESCE((SELECT SUM(pa.allocated_amount)
                FROM payment_allocations pa
                JOIN sales s ON s.id = pa.sale_id
                JOIN payments p ON p.id = pa.payment_id
                WHERE s.customer_id = c.id AND p.status = 'verified'), 0) AS paid,
      (SELECT COUNT(*) FROM sales WHERE customer_id = c.id) AS invoice_count
    FROM customers c
    WHERE c.shop_id = :sid
    ORDER BY c.name");
$rows->execute([':sid'=>$shopId]);
$customers = $rows->fetchAll();

$pageTitle = 'Customers';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Customers</h1>
    <p class="sb-page-subtitle"><?= count($customers) ?> customer<?= count($customers) === 1 ? '' : 's' ?></p>
  </div>
  <a href="<?= url('client/customers/form.php') ?>" class="btn btn-primary">
    <i class="bi bi-person-plus me-1"></i>Add customer
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-nosort="6">
      <thead><tr>
        <th>Name</th><th>Mobile</th><th>Invoices</th><th class="sb-money">Billed</th><th class="sb-money">Paid</th><th class="sb-money">Dues</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($customers as $c):
          $due = (float)$c['billed'] - (float)$c['paid'];
      ?>
        <tr>
          <td><strong><?= e($c['name']) ?></strong>
            <?php if (!$c['is_active']): ?><span class="badge-pill badge-unpaid ms-1">Inactive</span><?php endif; ?>
            <?php if ($c['email']): ?><div class="small text-muted"><?= e($c['email']) ?></div><?php endif; ?>
          </td>
          <td><?= e($c['mobile'] ?? '—') ?></td>
          <td><?= (int)$c['invoice_count'] ?></td>
          <td class="sb-money" data-order="<?= (float)$c['billed'] ?>"><?= money($c['billed']) ?></td>
          <td class="sb-money" data-order="<?= (float)$c['paid'] ?>"><?= money($c['paid']) ?></td>
          <td class="sb-money <?= $due > 0 ? 'text-danger fw-semibold' : '' ?>" data-order="<?= $due ?>"><?= money($due) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= url('client/customers/view.php?id=' . (int)$c['id']) ?>">View</a>
            <a class="btn btn-sm btn-outline-secondary" href="<?= url('client/customers/form.php?id=' . (int)$c['id']) ?>">Edit</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

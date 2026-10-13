<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('customer.manage');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Customer not found.'); }

$c = db()->prepare('SELECT * FROM customers WHERE id = :id AND shop_id = :sid');
$c->execute([':id'=>$id, ':sid'=>$shopId]);
$cust = $c->fetch();
if (!$cust) { http_response_code(404); exit('Customer not found.'); }

$t = db()->prepare("
  SELECT
    COALESCE((SELECT SUM(grand_total) FROM sales WHERE customer_id = :id AND status <> 'cancelled'), 0) AS billed,
    COALESCE((SELECT SUM(pa.allocated_amount)
              FROM payment_allocations pa
              JOIN sales s ON s.id = pa.sale_id
              JOIN payments p ON p.id = pa.payment_id
              WHERE s.customer_id = :id AND p.status = 'verified'), 0) AS paid");
$t->execute([':id'=>$id]);
$tot = $t->fetch();
$due = (float)$tot['billed'] - (float)$tot['paid'];

$sales = db()->prepare('SELECT * FROM sales WHERE customer_id = :id ORDER BY id DESC LIMIT 100');
$sales->execute([':id'=>$id]);
$sales = $sales->fetchAll();

$pageTitle = $cust['name'];
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title"><?= e($cust['name']) ?></h1>
    <p class="sb-page-subtitle"><?= $cust['mobile'] ? e($cust['mobile']) : '' ?> <?= $cust['email'] ? ' · ' . e($cust['email']) : '' ?></p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="<?= url('client/billing/?customer=' . $id) ?>"><i class="bi bi-cart-plus me-1"></i>New bill</a>
    <?php if ($due > 0): ?>
      <a class="btn btn-outline-warning" href="<?= url('receipts/outstanding.php?customer=' . $id) ?>" target="_blank">
        <i class="bi bi-file-earmark-text me-1"></i>Statement
      </a>
    <?php endif; ?>
    <a class="btn btn-outline-secondary" href="<?= url('client/customers/form.php?id=' . $id) ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
    <a class="btn btn-outline-secondary" href="<?= url('client/customers/') ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="bi bi-receipt"></i></div>
    <div><div class="sb-stat-value"><?= money($tot['billed']) ?></div><div class="sb-stat-label">Billed</div></div>
  </div></div>
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#D1FAE5;color:#047857;"><i class="bi bi-wallet2"></i></div>
    <div><div class="sb-stat-value"><?= money($tot['paid']) ?></div><div class="sb-stat-label">Paid</div></div>
  </div></div>
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#FEE2E2;color:#B91C1C;"><i class="bi bi-exclamation-circle"></i></div>
    <div><div class="sb-stat-value"><?= money($due) ?></div><div class="sb-stat-label">Outstanding</div></div>
  </div></div>
</div>

<div class="card mt-3">
  <div class="card-header">Invoices</div>
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[0,"desc"]]'>
      <thead><tr><th>#</th><th>Date</th><th>Invoice no</th><th>Status</th><th class="sb-money">Total</th></tr></thead>
      <tbody>
      <?php foreach ($sales as $s):
        $cls = ['paid'=>'badge-paid','partial'=>'badge-partial','unpaid'=>'badge-unpaid','returned'=>'badge-pending','cancelled'=>'badge-unpaid'][$s['status']] ?? 'badge-pending';
      ?>
        <tr>
          <td data-order="<?= (int)$s['id'] ?>">#<?= (int)$s['id'] ?></td>
          <td data-order="<?= e($s['sale_date']) ?>"><?= e(date('d M Y H:i', strtotime($s['sale_date']))) ?></td>
          <td><code><?= e($s['invoice_no']) ?></code></td>
          <td data-order="<?= e($s['status']) ?>"><span class="badge-pill <?= $cls ?>"><?= e(ucfirst($s['status'])) ?></span></td>
          <td class="sb-money" data-order="<?= (float)$s['grand_total'] ?>"><?= money($s['grand_total']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

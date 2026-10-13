<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('bill.view');

$shopId = current_shop_id();
$filter = $_GET['filter'] ?? '';
$where = ['p.shop_id = :sid'];
$args  = [':sid' => $shopId];
if (in_array($filter, ['pending','verified','failed','reversed'], true)) {
    $where[] = 'p.status = :st'; $args[':st'] = $filter;
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = db()->prepare("
    SELECT p.*, c.name AS cust_name, c.mobile AS cust_mobile,
           u.name AS collector_name,
           (SELECT GROUP_CONCAT(s.invoice_no SEPARATOR ', ')
            FROM payment_allocations pa
            JOIN sales s ON s.id = pa.sale_id
            WHERE pa.payment_id = p.id) AS invoices
    FROM payments p
    LEFT JOIN customers c   ON c.id = p.customer_id
    LEFT JOIN shop_users u  ON u.id = p.collected_by
    $whereSql
    ORDER BY p.id DESC");
$rows->execute($args);
$payments = $rows->fetchAll();

$pageTitle = 'Payments';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Payments</h1>
    <p class="sb-page-subtitle"><?= count($payments) ?> record<?= count($payments) === 1 ? '' : 's' ?></p>
  </div>
  <?php if (has_permission('due.collect')): ?>
    <a href="<?= url('client/dues/') ?>" class="btn btn-primary">
      <i class="bi bi-cash-stack me-1"></i>Collect outstanding
    </a>
  <?php endif; ?>
</div>

<div class="btn-group mb-3" role="group">
  <?php foreach ([['','All'],['verified','Verified'],['pending','Pending UPI'],['failed','Failed'],['reversed','Reversed']] as [$f,$lbl]): ?>
    <a class="btn btn-outline-secondary <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[0,"desc"]]' data-nosort="7">
      <thead><tr>
        <th>#</th><th>When</th><th>Customer</th><th>Method</th>
        <th>Invoices</th><th>Status</th><th class="sb-money">Amount</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($payments as $p):
          $cls = ['verified'=>'badge-paid','pending'=>'badge-pending','failed'=>'badge-unpaid','reversed'=>'badge-unpaid'][$p['status']] ?? 'badge-pending';
      ?>
        <tr>
          <td data-order="<?= (int)$p['id'] ?>">#<?= (int)$p['id'] ?></td>
          <td data-order="<?= e($p['paid_at']) ?>"><?= e(date('d M Y H:i', strtotime($p['paid_at']))) ?></td>
          <td>
            <?php if ($p['customer_id']): ?>
              <a href="<?= url('client/customers/view.php?id=' . (int)$p['customer_id']) ?>"><?= e($p['cust_name']) ?></a>
              <?php if ($p['cust_mobile']): ?><div class="small text-muted"><?= e($p['cust_mobile']) ?></div><?php endif; ?>
            <?php else: ?><span class="text-muted">Walk-in</span><?php endif; ?>
          </td>
          <td><?= e(strtoupper($p['method'])) ?><?php if ($p['reference']): ?><div class="small text-muted"><?= e($p['reference']) ?></div><?php endif; ?></td>
          <td class="small"><?= e($p['invoices'] ?? '—') ?></td>
          <td data-order="<?= e($p['status']) ?>"><span class="badge-pill <?= $cls ?>"><?= e(ucfirst($p['status'])) ?></span></td>
          <td class="sb-money" data-order="<?= (float)$p['amount'] ?>"><?= money($p['amount']) ?></td>
          <td class="text-end text-nowrap">
            <?php if (has_permission('due.collect')): ?>
              <?php if ($p['status'] === 'pending'): ?>
                <form method="post" action="<?= url('client/payments/action.php') ?>" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="op" value="verify">
                  <button class="btn btn-sm btn-outline-success" data-confirm="Mark this UPI payment as verified?">Verify</button>
                </form>
                <form method="post" action="<?= url('client/payments/action.php') ?>" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="op" value="fail">
                  <button class="btn btn-sm btn-outline-danger" data-confirm="Mark as failed? It won't count toward balance.">Fail</button>
                </form>
              <?php elseif ($p['status'] === 'verified'): ?>
                <form method="post" action="<?= url('client/payments/action.php') ?>" class="d-inline">
                  <?= csrf_field() ?><input type="hidden" name="id" value="<?= (int)$p['id'] ?>"><input type="hidden" name="op" value="reverse">
                  <button class="btn btn-sm btn-outline-warning" data-confirm="Reverse this payment? Outstanding will increase.">Reverse</button>
                </form>
              <?php endif; ?>
            <?php endif; ?>
            <a class="btn btn-sm btn-outline-primary" href="<?= url('receipts/payment-receipt.php?id=' . (int)$p['id']) ?>">Receipt</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

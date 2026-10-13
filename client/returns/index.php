<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('return.manage');

$shopId = current_shop_id();

$rows = db()->prepare('
  SELECT sr.*, s.invoice_no, c.name AS cust_name, u.name AS actor
  FROM sales_returns sr
  JOIN sales s     ON s.id = sr.sale_id
  LEFT JOIN customers c  ON c.id = s.customer_id
  LEFT JOIN shop_users u ON u.id = sr.created_by
  WHERE sr.shop_id = :sid
  ORDER BY sr.id DESC');
$rows->execute([':sid' => $shopId]);
$returns = $rows->fetchAll();

$pageTitle = 'Returns';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Returns</h1>
    <p class="sb-page-subtitle"><?= count($returns) ?> return<?= count($returns) === 1 ? '' : 's' ?></p>
  </div>
  <a href="<?= url('client/returns/new.php') ?>" class="btn btn-primary">
    <i class="bi bi-arrow-counterclockwise me-1"></i>New return
  </a>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[0,"desc"]]'>
      <thead><tr>
        <th>#</th><th>Date</th><th>Return no</th><th>Invoice</th><th>Customer</th>
        <th class="sb-money">Return</th><th class="sb-money">Credited</th><th class="sb-money">Refunded</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($returns as $r): ?>
        <tr>
          <td data-order="<?= (int)$r['id'] ?>">#<?= (int)$r['id'] ?></td>
          <td data-order="<?= e($r['created_at']) ?>"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
          <td><code><?= e($r['return_no']) ?></code></td>
          <td><a href="<?= url('receipts/invoice.php?id=' . (int)$r['sale_id']) ?>" target="_blank"><?= e($r['invoice_no']) ?></a></td>
          <td><?= e($r['cust_name'] ?? '—') ?></td>
          <td class="sb-money" data-order="<?= (float)$r['total_return_amount'] ?>"><?= money($r['total_return_amount']) ?></td>
          <td class="sb-money" data-order="<?= (float)$r['credit_to_invoice'] ?>"><?= money($r['credit_to_invoice']) ?></td>
          <td class="sb-money" data-order="<?= (float)$r['cash_refund_amount'] ?>"><?= money($r['cash_refund_amount']) ?></td>
          <td class="text-end"><a class="btn btn-sm btn-outline-primary" href="<?= url('client/returns/view.php?id=' . (int)$r['id']) ?>">View</a></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

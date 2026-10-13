<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('return.manage');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Return not found.'); }

$r = db()->prepare('
  SELECT sr.*, s.invoice_no, s.sale_date, s.grand_total, c.name AS cust_name, u.name AS actor
  FROM sales_returns sr
  JOIN sales s ON s.id = sr.sale_id
  LEFT JOIN customers c ON c.id = s.customer_id
  LEFT JOIN shop_users u ON u.id = sr.created_by
  WHERE sr.id = :id AND sr.shop_id = :sid');
$r->execute([':id'=>$id, ':sid'=>$shopId]);
$ret = $r->fetch();
if (!$ret) { http_response_code(404); exit('Return not found.'); }

$items = db()->prepare('
  SELECT sri.*, si.product_name
  FROM sales_return_items sri
  JOIN sale_items si ON si.id = sri.sale_item_id
  WHERE sri.sales_return_id = :id');
$items->execute([':id'=>$id]);
$items = $items->fetchAll();

$refunds = db()->prepare('SELECT * FROM refunds WHERE sales_return_id = :id');
$refunds->execute([':id'=>$id]);
$refunds = $refunds->fetchAll();

$pageTitle = 'Return · ' . $ret['return_no'];
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title"><code><?= e($ret['return_no']) ?></code></h1>
    <p class="sb-page-subtitle">
      Against invoice <a href="<?= url('receipts/invoice.php?id=' . (int)$ret['sale_id']) ?>" target="_blank"><?= e($ret['invoice_no']) ?></a>
      · <?= e(date('d M Y H:i', strtotime($ret['created_at']))) ?>
      · by <?= e($ret['actor'] ?? '—') ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <a href="<?= url('receipts/return-receipt.php?id=' . $id) ?>" target="_blank" class="btn btn-outline-primary">
      <i class="bi bi-printer me-1"></i>Receipt
    </a>
    <a href="<?= url('client/returns/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="bi bi-receipt"></i></div>
    <div><div class="sb-stat-value"><?= money($ret['total_return_amount']) ?></div><div class="sb-stat-label">Return amount</div></div>
  </div></div>
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#D1FAE5;color:#047857;"><i class="bi bi-arrow-up-circle"></i></div>
    <div><div class="sb-stat-value"><?= money($ret['credit_to_invoice']) ?></div><div class="sb-stat-label">Credited to invoice</div></div>
  </div></div>
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#FEE2E2;color:#B91C1C;"><i class="bi bi-cash-coin"></i></div>
    <div><div class="sb-stat-value"><?= money($ret['cash_refund_amount']) ?></div><div class="sb-stat-label">Cash refund</div></div>
  </div></div>
</div>

<div class="card mt-3">
  <div class="card-header">Items</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>Product</th><th>Qty</th><th class="sb-money">Unit</th><th class="sb-money">Amount</th><th>Condition</th></tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><strong><?= e($it['product_name']) ?></strong></td>
          <td><?= rtrim(rtrim(number_format((float)$it['qty_returned'], 3, '.', ''), '0'), '.') ?></td>
          <td class="sb-money"><?= money($it['unit_price']) ?></td>
          <td class="sb-money"><?= money($it['amount']) ?></td>
          <td><?= e(ucfirst($it['stock_condition'])) ?><?= $it['stock_condition'] === 'sellable' ? ' <span class="small text-success">(re-stocked)</span>' : '' ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php if ($refunds): ?>
<div class="card mt-3">
  <div class="card-header">Refunds paid out</div>
  <div class="table-responsive">
    <table class="table mb-0">
      <thead><tr><th>When</th><th>Method</th><th>Ref</th><th>Status</th><th class="sb-money">Amount</th></tr></thead>
      <tbody>
      <?php foreach ($refunds as $rf): ?>
        <tr>
          <td><?= e(date('d M Y H:i', strtotime($rf['created_at']))) ?></td>
          <td><?= e(ucfirst($rf['method'])) ?></td>
          <td><?= e($rf['reference'] ?? '—') ?></td>
          <td><?= e(ucfirst($rf['status'])) ?></td>
          <td class="sb-money"><?= money($rf['amount']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>
<?php endif; ?>

<?php if ($ret['reason']): ?>
<div class="card mt-3"><div class="card-body"><strong>Reason:</strong> <?= nl2br(e($ret['reason'])) ?></div></div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

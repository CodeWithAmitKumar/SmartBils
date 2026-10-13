<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('purchase.manage');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Purchase not found.'); }

$p = db()->prepare('
    SELECT p.*, s.name AS supplier_name, s.id AS supplier_id
    FROM purchases p JOIN suppliers s ON s.id = p.supplier_id
    WHERE p.id = :id AND p.shop_id = :sid');
$p->execute([':id'=>$id, ':sid'=>$shopId]);
$pur = $p->fetch();
if (!$pur) { http_response_code(404); exit('Purchase not found.'); }

$items = db()->prepare('SELECT * FROM purchase_items WHERE purchase_id = :id');
$items->execute([':id'=>$id]);
$items = $items->fetchAll();

$payments = db()->prepare('SELECT * FROM supplier_payments WHERE purchase_id = :id ORDER BY id DESC');
$payments->execute([':id'=>$id]);
$payments = $payments->fetchAll();
$paid = array_sum(array_column($payments, 'amount'));
$outstanding = $pur['status'] === 'received' ? max(0, (float)$pur['total'] - $paid) : 0;

$cls = ['received'=>'badge-paid','draft'=>'badge-pending','cancelled'=>'badge-unpaid','reversed'=>'badge-unpaid'][$pur['status']];

$pageTitle = 'Purchase #' . $id;
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Purchase #<?= $id ?></h1>
    <p class="sb-page-subtitle">
      <span class="badge-pill <?= $cls ?>"><?= e(ucfirst($pur['status'])) ?></span>
      · <?= e(date('d M Y', strtotime($pur['purchase_date']))) ?>
      · <a href="<?= url('client/suppliers/view.php?id=' . (int)$pur['supplier_id']) ?>"><?= e($pur['supplier_name']) ?></a>
      <?php if ($pur['invoice_no']): ?>· Invoice <code><?= e($pur['invoice_no']) ?></code><?php endif; ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <?php if ($pur['status'] === 'draft'): ?>
      <form method="post" action="<?= url('client/purchases/action.php') ?>" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="op" value="finalize">
        <button class="btn btn-primary" data-confirm="Finalize this purchase and update stock?">
          <i class="bi bi-check2-circle me-1"></i>Finalize &amp; update stock
        </button>
      </form>
      <form method="post" action="<?= url('client/purchases/action.php') ?>" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="op" value="cancel">
        <button class="btn btn-outline-danger" data-confirm="Cancel this draft purchase?"><i class="bi bi-x-lg me-1"></i>Cancel</button>
      </form>
    <?php elseif ($pur['status'] === 'received'): ?>
      <form method="post" action="<?= url('client/purchases/action.php') ?>" class="d-inline">
        <?= csrf_field() ?>
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="op" value="reverse">
        <button class="btn btn-outline-warning" data-confirm="Reverse this purchase? Stock will decrease by the received quantities.">
          <i class="bi bi-arrow-counterclockwise me-1"></i>Reverse
        </button>
      </form>
    <?php endif; ?>
    <a href="<?= url('client/purchases/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-8">
    <div class="card">
      <div class="card-header">Items</div>
      <div class="table-responsive">
        <table class="table mb-0">
          <thead><tr><th>Product</th><th>Qty</th><th class="sb-money">Cost</th><th class="sb-money">Line total</th></tr></thead>
          <tbody>
          <?php foreach ($items as $it): ?>
            <tr>
              <td><strong><?= e($it['product_name']) ?></strong>
                <?php if ($it['sku_snapshot']): ?><div class="small text-muted"><?= e($it['sku_snapshot']) ?></div><?php endif; ?>
              </td>
              <td><?= rtrim(rtrim(number_format((float)$it['qty'], 3, '.', ''), '0'), '.') ?></td>
              <td class="sb-money"><?= money($it['purchase_cost']) ?></td>
              <td class="sb-money"><?= money($it['line_total']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr><th colspan="3" class="text-end">Subtotal</th><th class="sb-money"><?= money($pur['subtotal']) ?></th></tr>
            <?php if ((float)$pur['charges']): ?><tr><th colspan="3" class="text-end">Charges</th><th class="sb-money"><?= money($pur['charges']) ?></th></tr><?php endif; ?>
            <?php if ((float)$pur['discount']): ?><tr><th colspan="3" class="text-end">Discount</th><th class="sb-money text-danger">- <?= money($pur['discount']) ?></th></tr><?php endif; ?>
            <tr><th colspan="3" class="text-end">Total</th><th class="sb-money"><?= money($pur['total']) ?></th></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <div class="col-lg-4">
    <div class="card h-100">
      <div class="card-header">Payments</div>
      <div class="card-body">
        <dl class="row mb-3">
          <dt class="col-6 text-muted">Total</dt><dd class="col-6 sb-money"><?= money($pur['total']) ?></dd>
          <dt class="col-6 text-muted">Paid</dt><dd class="col-6 sb-money"><?= money($paid) ?></dd>
          <?php if ($pur['status'] === 'received'): ?>
            <dt class="col-6 fw-semibold">Outstanding</dt>
            <dd class="col-6 sb-money fw-semibold <?= $outstanding > 0 ? 'text-danger' : 'text-success' ?>"><?= money($outstanding) ?></dd>
          <?php endif; ?>
        </dl>

        <?php if ($pur['status'] === 'received' && $outstanding > 0): ?>
          <form method="post" action="<?= url('client/suppliers/action.php') ?>" class="js-once">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="pay">
            <input type="hidden" name="supplier_id" value="<?= (int)$pur['supplier_id'] ?>">
            <input type="hidden" name="purchase_id" value="<?= $id ?>">
            <div class="mb-2">
              <label class="form-label">Amount</label>
              <input class="form-control" type="number" step="0.01" min="0.01" max="<?= $outstanding ?>" name="amount" value="<?= $outstanding ?>" required>
            </div>
            <div class="row g-2 mb-2">
              <div class="col-6"><select class="form-select" name="method">
                <?php foreach (['cash'=>'Cash','upi'=>'UPI','bank'=>'Bank','other'=>'Other'] as $k=>$l): ?>
                  <option value="<?= $k ?>"><?= $l ?></option>
                <?php endforeach; ?>
              </select></div>
              <div class="col-6"><input class="form-control" name="reference" placeholder="Ref"></div>
            </div>
            <button class="btn btn-primary w-100"><i class="bi bi-check2 me-1"></i>Record payment</button>
          </form>
        <?php endif; ?>

        <?php if ($payments): ?>
          <hr>
          <ul class="list-unstyled small mb-0">
          <?php foreach ($payments as $pay): ?>
            <li class="d-flex justify-content-between py-1 border-bottom">
              <span><?= e(date('d M', strtotime($pay['paid_at']))) ?> · <?= e(ucfirst($pay['method'])) ?><?php if ($pay['reference']): ?> · <?= e($pay['reference']) ?><?php endif; ?></span>
              <strong><?= money($pay['amount']) ?></strong>
            </li>
          <?php endforeach; ?>
          </ul>
        <?php endif; ?>
      </div>
    </div>
  </div>
</div>

<?php if ($pur['notes']): ?>
  <div class="card mt-3"><div class="card-body"><strong>Notes:</strong> <?= nl2br(e($pur['notes'])) ?></div></div>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

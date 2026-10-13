<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('supplier.manage');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Supplier not found.'); }

$s = db()->prepare('SELECT * FROM suppliers WHERE id = :id AND shop_id = :sid');
$s->execute([':id'=>$id, ':sid'=>$shopId]);
$sup = $s->fetch();
if (!$sup) { http_response_code(404); exit('Supplier not found.'); }

$totals = db()->prepare("
    SELECT
      COALESCE((SELECT SUM(total)  FROM purchases         WHERE supplier_id = :id AND status = 'received'), 0) AS billed,
      COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE supplier_id = :id), 0) AS paid");
$totals->execute([':id' => $id]);
$t = $totals->fetch();
$outstanding = (float)$t['billed'] - (float)$t['paid'];

$purchases = db()->prepare('SELECT id, invoice_no, purchase_date, total, status FROM purchases WHERE supplier_id = :id ORDER BY id DESC LIMIT 100');
$purchases->execute([':id'=>$id]);
$purchases = $purchases->fetchAll();

$payments = db()->prepare('
    SELECT sp.*, p.invoice_no, u.name AS actor
    FROM supplier_payments sp
    LEFT JOIN purchases p   ON p.id = sp.purchase_id
    LEFT JOIN shop_users u  ON u.id = sp.created_by
    WHERE sp.supplier_id = :id ORDER BY sp.id DESC LIMIT 100');
$payments->execute([':id'=>$id]);
$payments = $payments->fetchAll();

$pageTitle = $sup['name'];
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title"><?= e($sup['name']) ?></h1>
    <p class="sb-page-subtitle"><?= $sup['phone'] ? e($sup['phone']) : '' ?> <?= $sup['email'] ? ' · ' . e($sup['email']) : '' ?></p>
  </div>
  <div class="d-flex gap-2">
    <a class="btn btn-outline-primary" href="<?= url('client/purchases/form.php?supplier=' . $id) ?>"><i class="bi bi-bag-plus me-1"></i>New purchase</a>
    <a class="btn btn-outline-secondary" href="<?= url('client/suppliers/form.php?id=' . $id) ?>"><i class="bi bi-pencil me-1"></i>Edit</a>
    <a class="btn btn-outline-secondary" href="<?= url('client/suppliers/') ?>"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#EEF2FF;color:#4F46E5;"><i class="bi bi-receipt"></i></div>
    <div><div class="sb-stat-value"><?= money($t['billed']) ?></div><div class="sb-stat-label">Billed (received)</div></div>
  </div></div>
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#D1FAE5;color:#047857;"><i class="bi bi-wallet2"></i></div>
    <div><div class="sb-stat-value"><?= money($t['paid']) ?></div><div class="sb-stat-label">Paid</div></div>
  </div></div>
  <div class="col-md-4"><div class="sb-stat">
    <div class="sb-stat-icon" style="background:#FEE2E2;color:#B91C1C;"><i class="bi bi-exclamation-circle"></i></div>
    <div><div class="sb-stat-value"><?= money($outstanding) ?></div><div class="sb-stat-label">Outstanding</div></div>
  </div></div>
</div>

<div class="card mt-3">
  <div class="card-header">Record payment</div>
  <div class="card-body">
    <form method="post" action="<?= url('client/suppliers/action.php') ?>" class="js-once row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="pay">
      <input type="hidden" name="supplier_id" value="<?= $id ?>">
      <div class="col-md-3">
        <label class="form-label">Amount (<?= APP_CURRENCY_SYMBOL ?>)</label>
        <input class="form-control" type="number" step="0.01" min="0.01" name="amount" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Method</label>
        <select class="form-select" name="method">
          <?php foreach (['cash'=>'Cash','upi'=>'UPI','bank'=>'Bank','other'=>'Other'] as $k=>$lbl): ?>
            <option value="<?= $k ?>"><?= $lbl ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-3">
        <label class="form-label">Reference</label>
        <input class="form-control" name="reference" placeholder="UPI ref / cheque #">
      </div>
      <div class="col-md-3 d-flex gap-2">
        <select class="form-select" name="purchase_id">
          <option value="">Not linked</option>
          <?php foreach ($purchases as $pr): if ($pr['status'] !== 'received') continue; ?>
            <option value="<?= (int)$pr['id'] ?>">#<?= (int)$pr['id'] ?> · <?= money($pr['total']) ?></option>
          <?php endforeach; ?>
        </select>
        <button class="btn btn-primary flex-fill"><i class="bi bi-check2 me-1"></i>Save</button>
      </div>
    </form>
  </div>
</div>

<div class="row g-3 mt-1">
  <div class="col-lg-7">
    <div class="card">
      <div class="card-header">Purchases <span class="text-muted small">(latest 100)</span></div>
      <div class="table-responsive">
        <table class="table sb-datatable" data-order='[[0,"desc"]]'>
          <thead><tr><th>#</th><th>Date</th><th>Invoice</th><th>Status</th><th class="sb-money">Total</th></tr></thead>
          <tbody>
          <?php foreach ($purchases as $pr):
              $cls = ['received'=>'badge-paid','draft'=>'badge-pending','cancelled'=>'badge-unpaid','reversed'=>'badge-unpaid'][$pr['status']] ?? 'badge-pending';
          ?>
            <tr>
              <td data-order="<?= (int)$pr['id'] ?>"><a href="<?= url('client/purchases/view.php?id=' . (int)$pr['id']) ?>">#<?= (int)$pr['id'] ?></a></td>
              <td data-order="<?= e($pr['purchase_date']) ?>"><?= e(date('d M Y', strtotime($pr['purchase_date']))) ?></td>
              <td><?= e($pr['invoice_no'] ?? '—') ?></td>
              <td data-order="<?= e($pr['status']) ?>"><span class="badge-pill <?= $cls ?>"><?= e(ucfirst($pr['status'])) ?></span></td>
              <td class="sb-money" data-order="<?= (float)$pr['total'] ?>"><?= money($pr['total']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
  <div class="col-lg-5">
    <div class="card">
      <div class="card-header">Payments <span class="text-muted small">(latest 100)</span></div>
      <div class="table-responsive">
        <table class="table sb-datatable" data-order='[[0,"desc"]]'>
          <thead><tr><th>When</th><th>Method</th><th>Ref</th><th>Linked</th><th class="sb-money">Amount</th></tr></thead>
          <tbody>
          <?php foreach ($payments as $pay): ?>
            <tr>
              <td data-order="<?= e($pay['paid_at']) ?>"><?= e(date('d M Y H:i', strtotime($pay['paid_at']))) ?></td>
              <td><?= e(ucfirst($pay['method'])) ?></td>
              <td><?= e($pay['reference'] ?? '—') ?></td>
              <td><?= $pay['purchase_id'] ? '#' . (int)$pay['purchase_id'] : '—' ?></td>
              <td class="sb-money" data-order="<?= (float)$pay['amount'] ?>"><?= money($pay['amount']) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

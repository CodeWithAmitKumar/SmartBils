<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('bill.create');

$shopId = current_shop_id();

// Pre-selected customer (from customer view)
$preCustomer = null;
if (!empty($_GET['customer'])) {
    $s = db()->prepare('SELECT id, name, mobile FROM customers WHERE id = :id AND shop_id = :sid');
    $s->execute([':id'=>(int)$_GET['customer'], ':sid'=>$shopId]);
    $preCustomer = $s->fetch() ?: null;
}

$cfg = db()->prepare('SELECT tax_percent, cash_enabled, upi_enabled, upi_id FROM shop_settings WHERE shop_id = :sid');
$cfg->execute([':sid'=>$shopId]);
$cfg = $cfg->fetch() ?: ['tax_percent'=>0, 'cash_enabled'=>1, 'upi_enabled'=>1, 'upi_id'=>null];

if (empty($_SESSION['pos_token'])) {
    $_SESSION['pos_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['pos_token'];

$pageTitle = 'New Bill (POS)';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">New Bill</h1>
    <p class="sb-page-subtitle">Mobile first → add products → confirm. Server recomputes totals.</p>
  </div>
</div>

<div class="row g-3">
  <!-- LEFT: entry -->
  <div class="col-lg-7">

    <!-- Customer: mobile + name -->
    <div class="card mb-3">
      <div class="card-header">Customer</div>
      <div class="card-body row g-3">
        <div class="col-md-5">
          <label class="form-label">Mobile *</label>
          <input class="form-control form-control-lg" id="custMobile"
                 inputmode="tel" autocomplete="off" autofocus
                 value="<?= e($preCustomer['mobile'] ?? '') ?>" placeholder="e.g. 9876543210" required>
          <div class="form-text" id="custHint">Enter mobile to find or create a customer.</div>
        </div>
        <div class="col-md-7">
          <label class="form-label">Name <span id="nameReq" class="text-danger">*</span></label>
          <input class="form-control form-control-lg" id="custName" value="<?= e($preCustomer['name'] ?? '') ?>"
                 placeholder="Customer name" autocomplete="off">
          <div class="form-text" id="custStatus">
            <?= $preCustomer ? '<span class="text-success"><i class="bi bi-check2-circle me-1"></i>Existing customer selected.</span>' : '' ?>
          </div>
        </div>
      </div>
    </div>

    <!-- Product search -->
    <div class="card mb-3">
      <div class="card-header">Add product</div>
      <div class="card-body">
        <div class="position-relative">
          <input class="form-control form-control-lg" id="prodSearch"
                 placeholder="Type any letter — e.g. 's' lists all products containing s" autocomplete="off">
          <div class="dropdown-menu w-100 shadow-sm" id="prodMenu"
               style="position:absolute; top:100%; left:0; right:0; margin-top:4px; max-height:320px; overflow:auto; z-index:1050;"></div>
        </div>
      </div>
    </div>

    <!-- Cart -->
    <div class="card">
      <div class="card-header">Cart</div>
      <div class="table-responsive">
        <table class="table mb-0" id="cartTable">
          <thead><tr>
            <th>Item</th><th style="width:110px">Qty</th><th style="width:110px">Price</th><th style="width:110px">Disc</th>
            <th class="sb-money" style="width:120px">Line</th><th></th>
          </tr></thead>
          <tbody></tbody>
          <tfoot>
            <tr><td colspan="6" class="text-center text-muted py-3" id="emptyRow">Cart is empty — search and add a product above.</td></tr>
          </tfoot>
        </table>
      </div>
    </div>
  </div>

  <!-- RIGHT: totals + payment -->
  <div class="col-lg-5">
    <form id="posForm" method="post" action="<?= url('client/billing/action.php') ?>" class="js-once">
      <?= csrf_field() ?>
      <input type="hidden" name="txn_token" value="<?= e($token) ?>">
      <input type="hidden" name="customer_id"      id="fCustId"     value="<?= $preCustomer ? (int)$preCustomer['id'] : '' ?>">
      <input type="hidden" name="customer_mobile"  id="fCustMobile" value="<?= e($preCustomer['mobile'] ?? '') ?>">
      <input type="hidden" name="customer_name"    id="fCustName"   value="<?= e($preCustomer['name'] ?? '') ?>">
      <input type="hidden" name="items_json"       id="fItemsJson"  value="[]">

      <div class="card mb-3">
        <div class="card-header">Totals</div>
        <div class="card-body">
          <div class="row g-2">
            <div class="col-6">
              <label class="form-label small">Order discount</label>
              <input class="form-control" type="number" step="0.01" min="0" name="order_discount" id="orderDiscount" value="0">
            </div>
            <div class="col-6">
              <label class="form-label small">Tax %</label>
              <input class="form-control" type="number" step="0.01" min="0" max="100" name="tax_percent" id="taxPercent" value="<?= e($cfg['tax_percent']) ?>">
            </div>
          </div>
          <dl class="row mb-0 mt-2">
            <dt class="col-6 text-muted">Subtotal</dt><dd class="col-6 sb-money" id="dispSub"><?= money(0) ?></dd>
            <dt class="col-6 text-muted">Line discounts</dt><dd class="col-6 sb-money text-danger" id="dispLineDisc">-<?= money(0) ?></dd>
            <dt class="col-6 text-muted">Order discount</dt><dd class="col-6 sb-money text-danger" id="dispOrderDisc">-<?= money(0) ?></dd>
            <dt class="col-6 text-muted">Tax</dt><dd class="col-6 sb-money" id="dispTax"><?= money(0) ?></dd>
            <dt class="col-6 fw-bold">Grand total</dt><dd class="col-6 sb-money fw-bold fs-4" id="dispTotal"><?= money(0) ?></dd>
          </dl>
          <div class="form-text mt-2">Display only — server will recompute.</div>
        </div>
      </div>

      <div class="card mb-3">
        <div class="card-header">Payment</div>
        <div class="card-body">
          <div class="row g-2 mb-2">
            <div class="col-4">
              <select class="form-select" name="pay_method">
                <?php if ($cfg['cash_enabled']): ?><option value="cash">Cash</option><?php endif; ?>
                <?php if ($cfg['upi_enabled']):  ?><option value="upi">UPI</option><?php endif; ?>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-4">
              <input class="form-control" type="number" step="0.01" min="0" name="amount_paid" id="amountPaid" value="0" placeholder="Amount paid">
            </div>
            <div class="col-4">
              <input class="form-control" name="pay_reference" placeholder="Ref (UPI txn)">
            </div>
          </div>
          <div class="d-flex justify-content-between small text-muted">
            <span>Balance to collect</span>
            <strong id="dispBalance" class="text-danger"><?= money(0) ?></strong>
          </div>
          <?php if ($cfg['upi_enabled'] && $cfg['upi_id']): ?>
            <div class="small text-muted mt-2">UPI: <code><?= e($cfg['upi_id']) ?></code> — QR display is NOT proof of payment.</div>
          <?php endif; ?>
        </div>
      </div>

      <button class="btn btn-primary w-100 py-3 fs-5" type="submit" id="btnConfirm" disabled>
        <i class="bi bi-check2-circle me-2"></i>Confirm sale
      </button>
      <div class="small text-muted mt-2 text-center" id="confirmHint">Enter mobile &amp; name and add at least one item.</div>
    </form>
  </div>
</div>

<script>
  window.SB_URL = {
    searchProducts:   '<?= e(url('ajax/search-products.php')) ?>',
    customerByMobile: '<?= e(url('ajax/customer-by-mobile.php')) ?>',
  };
</script>
<script src="<?= url('assets/js/billing.js') ?>?v=4"></script>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

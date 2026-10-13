<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('due.collect');

$shopId = current_shop_id();

$cfg = db()->prepare('SELECT cash_enabled, upi_enabled, upi_id FROM shop_settings WHERE shop_id = :sid');
$cfg->execute([':sid' => $shopId]);
$cfg = $cfg->fetch() ?: ['cash_enabled'=>1, 'upi_enabled'=>1, 'upi_id'=>null];

if (empty($_SESSION['dues_token'])) {
    $_SESSION['dues_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['dues_token'];

$pageTitle = 'Collect Outstanding Payment';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Collect Outstanding Payment</h1>
    <p class="sb-page-subtitle">This records a payment against an existing invoice — <strong>no new sale, no stock change</strong>.</p>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-7">
    <!-- Customer -->
    <div class="card mb-3">
      <div class="card-header">Customer</div>
      <div class="card-body row g-3">
        <div class="col-md-6">
          <label class="form-label">Mobile</label>
          <input class="form-control form-control-lg" id="custMobile" inputmode="tel" autofocus
                 autocomplete="off" placeholder="e.g. 9876543210">
          <div class="form-text" id="custHint">Enter mobile to look up outstanding invoices.</div>
        </div>
        <div class="col-md-6">
          <label class="form-label">Found customer</label>
          <input class="form-control form-control-lg" id="custName" value="" readonly placeholder="—">
        </div>
      </div>
    </div>

    <!-- Invoices -->
    <div class="card">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Outstanding invoices</span>
        <span class="small text-muted" id="selectedCount">Nothing selected</span>
      </div>
      <div class="table-responsive">
        <table class="table mb-0" id="invTable">
          <thead><tr>
            <th style="width:40px"></th>
            <th>Invoice</th><th>Date</th>
            <th class="sb-money">Total</th>
            <th class="sb-money">Paid</th>
            <th class="sb-money">Outstanding</th>
            <th class="sb-money" style="width:140px">Pay this invoice</th>
          </tr></thead>
          <tbody>
            <tr><td colspan="7" class="text-center text-muted py-3" id="invEmpty">Enter a mobile above to list invoices.</td></tr>
          </tbody>
        </table>
      </div>
    </div>
  </div>

  <!-- Collection form -->
  <div class="col-lg-5">
    <form id="duesForm" method="post" action="<?= url('client/dues/action.php') ?>" class="js-once">
      <?= csrf_field() ?>
      <input type="hidden" name="txn_token"   value="<?= e($token) ?>">
      <input type="hidden" name="customer_id" id="fCustId"  value="">
      <input type="hidden" name="allocs_json" id="fAllocs"  value="[]">

      <div class="card mb-3">
        <div class="card-header">Payment</div>
        <div class="card-body">
          <div class="row g-2 mb-3">
            <div class="col-5">
              <label class="form-label small">Method</label>
              <select class="form-select" name="method" id="payMethod">
                <?php if ($cfg['cash_enabled']): ?><option value="cash">Cash</option><?php endif; ?>
                <?php if ($cfg['upi_enabled']):  ?><option value="upi">UPI</option><?php endif; ?>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-7">
              <label class="form-label small">Reference</label>
              <input class="form-control" name="reference" placeholder="UPI txn / cheque">
            </div>
          </div>

          <dl class="row mb-0">
            <dt class="col-7 text-muted">Allocating total</dt>
            <dd class="col-5 sb-money fw-bold fs-5" id="dispTotal"><?= money(0) ?></dd>
          </dl>
          <?php if ($cfg['upi_enabled'] && $cfg['upi_id']): ?>
            <div class="small text-muted mt-2">UPI: <code><?= e($cfg['upi_id']) ?></code> — QR display is NOT proof of payment. UPI payments stay <strong>pending</strong> until you verify them in Payments.</div>
          <?php endif; ?>
        </div>
      </div>

      <button class="btn btn-primary w-100 py-3 fs-5" type="submit" id="btnCollect" disabled>
        <i class="bi bi-cash-stack me-2"></i>Collect payment
      </button>
      <div class="small text-muted mt-2 text-center" id="collectHint">Find a customer and enter amounts to collect.</div>
    </form>
  </div>
</div>

<script>
  window.SB_URL = window.SB_URL || {};
  window.SB_URL.customerByMobile = '<?= e(url('ajax/customer-by-mobile.php')) ?>';
  window.SB_URL.customerInvoices = '<?= e(url('ajax/customer-invoices.php')) ?>';
</script>
<script src="<?= url('assets/js/dues.js') ?>?v=1"></script>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

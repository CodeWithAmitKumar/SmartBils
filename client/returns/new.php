<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('return.manage');

$shopId = current_shop_id();

// Unified search — accepts invoice no/id OR customer mobile OR customer name
$q    = trim((string)($_GET['q']   ?? ''));
$inv  = trim((string)($_GET['inv'] ?? ''));   // chosen invoice from the match list
$sale = null;
$matchedInvoices = [];       // list for picker when searching by customer
$matchedCustomer = null;

/** Load one invoice for the item-entry form */
$loadSale = function (string $invoiceOrId) use ($shopId) {
    $s = db()->prepare('
      SELECT s.*, c.name AS cust_name, c.mobile AS cust_mobile
      FROM sales s LEFT JOIN customers c ON c.id = s.customer_id
      WHERE s.shop_id = :sid AND (s.invoice_no = :inv OR s.id = :id)
      LIMIT 1');
    $s->execute([':sid' => $shopId, ':inv' => $invoiceOrId, ':id' => (int)$invoiceOrId]);
    return $s->fetch() ?: null;
};

if ($inv !== '') {
    $sale = $loadSale($inv);
} elseif ($q !== '') {
    // 1) direct invoice match
    $sale = $loadSale($q);
    if (!$sale) {
        // 2) match customer by mobile exact or name LIKE
        $like = "%{$q}%";
        $cq = db()->prepare('
          SELECT id, name, mobile
          FROM customers
          WHERE shop_id = :sid AND (mobile = :m OR name LIKE :n)
          ORDER BY (mobile = :m2) DESC, name LIMIT 1');
        $cq->execute([':sid' => $shopId, ':m' => $q, ':m2' => $q, ':n' => $like]);
        $matchedCustomer = $cq->fetch() ?: null;

        if ($matchedCustomer) {
            $iq = db()->prepare("
              SELECT id, invoice_no, sale_date, grand_total, status
              FROM sales
              WHERE shop_id = :sid AND customer_id = :cid AND status <> 'cancelled'
              ORDER BY id DESC LIMIT 50");
            $iq->execute([':sid' => $shopId, ':cid' => (int)$matchedCustomer['id']]);
            $matchedInvoices = $iq->fetchAll();
        }
    }
}

$items = [];
if ($sale) {
    $it = db()->prepare('
      SELECT si.*,
        COALESCE((SELECT SUM(qty_returned) FROM sales_return_items WHERE sale_item_id = si.id), 0) AS already_returned
      FROM sale_items si
      WHERE si.sale_id = :id');
    $it->execute([':id' => $sale['id']]);
    $items = $it->fetchAll();
}

// Current invoice outstanding (for credit vs refund split preview)
$outstanding = 0;
if ($sale) {
    $pq = db()->prepare("SELECT COALESCE(SUM(pa.allocated_amount), 0)
                         FROM payment_allocations pa
                         JOIN payments p ON p.id = pa.payment_id
                         WHERE pa.sale_id = :id AND p.status = 'verified'");
    $pq->execute([':id' => $sale['id']]);
    $paid = (float)$pq->fetchColumn();
    $outstanding = max(0, (float)$sale['grand_total'] - $paid);
}

if (empty($_SESSION['return_token'])) $_SESSION['return_token'] = bin2hex(random_bytes(16));
$token = $_SESSION['return_token'];

$pageTitle = 'New Return';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">New Return</h1>
    <p class="sb-page-subtitle">Credit reduces outstanding first; the rest becomes a cash refund.</p>
  </div>
  <a href="<?= url('client/returns/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<form method="get" class="card p-3 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-md-9">
      <label class="form-label">Invoice no · Customer mobile · Customer name</label>
      <input class="form-control" name="q" value="<?= e($q) ?>"
             placeholder="e.g. INV-20260101-000123, 9876543210, or Rahul" required autofocus>
    </div>
    <div class="col-md-3">
      <button class="btn btn-primary w-100"><i class="bi bi-search me-1"></i>Search</button>
    </div>
  </div>
</form>

<?php if ($q !== '' && !$sale && !$matchedCustomer): ?>
  <div class="alert alert-warning">No invoice or customer matched "<?= e($q) ?>".</div>
<?php endif; ?>

<?php if ($matchedCustomer && $matchedInvoices && !$sale): ?>
  <div class="card mb-3">
    <div class="card-header">
      <strong><?= e($matchedCustomer['name']) ?></strong>
      <?php if ($matchedCustomer['mobile']): ?><span class="text-muted">· <?= e($matchedCustomer['mobile']) ?></span><?php endif; ?>
      <span class="text-muted small"> — <?= count($matchedInvoices) ?> invoice(s). Pick one.</span>
    </div>
    <div class="table-responsive">
      <table class="table mb-0">
        <thead><tr><th>Invoice</th><th>Date</th><th>Status</th><th class="sb-money">Total</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($matchedInvoices as $r):
            $cls = ['paid'=>'badge-paid','partial'=>'badge-partial','unpaid'=>'badge-unpaid','returned'=>'badge-pending'][$r['status']] ?? 'badge-pending';
        ?>
          <tr>
            <td><code><?= e($r['invoice_no']) ?></code></td>
            <td class="text-muted"><?= e(date('d M Y H:i', strtotime($r['sale_date']))) ?></td>
            <td><span class="badge-pill <?= $cls ?>"><?= e(ucfirst($r['status'])) ?></span></td>
            <td class="sb-money"><?= money($r['grand_total']) ?></td>
            <td class="text-end">
              <a class="btn btn-sm btn-primary" href="?inv=<?= (int)$r['id'] ?>">Select &amp; return</a>
            </td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
<?php elseif ($matchedCustomer && !$matchedInvoices && !$sale): ?>
  <div class="alert alert-info">
    Found customer <strong><?= e($matchedCustomer['name']) ?></strong>, but no bills have been made for them yet (or all were cancelled) — nothing to return against.
    <br><small class="text-muted">Tip: if the actual bill was made under a different name/mobile, search by that invoice number instead.</small>
  </div>
<?php endif; ?>

<?php if ($sale): ?>
  <div class="card mb-3">
    <div class="card-header">Invoice · <code><?= e($sale['invoice_no']) ?></code></div>
    <div class="card-body row g-3">
      <div class="col-md-4"><strong>Customer:</strong> <?= e($sale['cust_name'] ?? 'Walk-in') ?><?php if ($sale['cust_mobile']): ?> · <?= e($sale['cust_mobile']) ?><?php endif; ?></div>
      <div class="col-md-4"><strong>Sale date:</strong> <?= e(date('d M Y H:i', strtotime($sale['sale_date']))) ?></div>
      <div class="col-md-4"><strong>Grand total:</strong> <?= money($sale['grand_total']) ?> · <strong>Outstanding:</strong> <?= money($outstanding) ?></div>
    </div>
  </div>

  <form method="post" action="<?= url('client/returns/action.php') ?>" class="js-once">
    <?= csrf_field() ?>
    <input type="hidden" name="txn_token" value="<?= e($token) ?>">
    <input type="hidden" name="sale_id"   value="<?= (int)$sale['id'] ?>">
    <input type="hidden" name="outstanding" value="<?= $outstanding ?>">

    <div class="card mb-3">
      <div class="card-header">Items to return</div>
      <div class="table-responsive">
        <table class="table mb-0" id="retTable">
          <thead><tr>
            <th>Item</th>
            <th>Sold</th><th>Already returned</th>
            <th style="width:120px">Return qty</th>
            <th class="sb-money">Unit</th>
            <th class="sb-money">Amount</th>
            <th style="width:160px">Stock condition</th>
          </tr></thead>
          <tbody>
          <?php foreach ($items as $it):
              $max = (float)$it['qty'] - (float)$it['already_returned'];
              $unit = $it['qty'] > 0 ? ((float)$it['line_total'] / (float)$it['qty']) : 0;
          ?>
            <tr data-max="<?= $max ?>" data-unit="<?= $unit ?>">
              <td><strong><?= e($it['product_name']) ?></strong>
                <?php if ($it['sku_snapshot']): ?><div class="small text-muted"><?= e($it['sku_snapshot']) ?></div><?php endif; ?>
              </td>
              <td><?= rtrim(rtrim(number_format((float)$it['qty'], 3, '.', ''), '0'), '.') ?></td>
              <td><?= rtrim(rtrim(number_format((float)$it['already_returned'], 3, '.', ''), '0'), '.') ?></td>
              <td>
                <input class="form-control form-control-sm ret-qty" type="number" step="0.001" min="0"
                       max="<?= $max ?>" value="0" name="qty[<?= (int)$it['id'] ?>]"
                       <?= $max <= 0 ? 'disabled' : '' ?>>
              </td>
              <td class="sb-money"><?= money($unit) ?></td>
              <td class="sb-money ret-line">—</td>
              <td>
                <select class="form-select form-select-sm" name="cond[<?= (int)$it['id'] ?>]">
                  <option value="sellable">Sellable → re-stock</option>
                  <option value="damaged">Damaged → no re-stock</option>
                  <option value="unavailable">Unavailable → no re-stock</option>
                </select>
              </td>
            </tr>
          <?php endforeach; ?>
          </tbody>
          <tfoot>
            <tr>
              <th colspan="5" class="text-end">Return total</th>
              <th class="sb-money" id="dispTotal"><?= money(0) ?></th>
              <th></th>
            </tr>
          </tfoot>
        </table>
      </div>
    </div>

    <div class="row g-3">
      <div class="col-lg-7">
        <div class="card">
          <div class="card-body row g-3">
            <div class="col-12">
              <label class="form-label">Reason *</label>
              <input class="form-control" name="reason" required maxlength="500" placeholder="e.g. wrong item, damaged in transit">
            </div>
            <div class="col-md-6">
              <label class="form-label">Refund method <small class="text-muted">(only if cash refund > 0)</small></label>
              <select class="form-select" name="refund_method">
                <option value="cash">Cash</option>
                <option value="upi">UPI</option>
                <option value="bank">Bank</option>
                <option value="other">Other</option>
              </select>
            </div>
            <div class="col-md-6">
              <label class="form-label">Refund reference</label>
              <input class="form-control" name="refund_reference">
            </div>
          </div>
        </div>
      </div>
      <div class="col-lg-5">
        <div class="card">
          <div class="card-header">Settlement preview</div>
          <div class="card-body">
            <dl class="row mb-0">
              <dt class="col-7 text-muted">Return total</dt><dd class="col-5 sb-money" id="prevTotal"><?= money(0) ?></dd>
              <dt class="col-7 text-muted">Credit to invoice</dt><dd class="col-5 sb-money" id="prevCredit"><?= money(0) ?></dd>
              <dt class="col-7 fw-bold">Cash refund due</dt><dd class="col-5 sb-money fw-bold" id="prevRefund"><?= money(0) ?></dd>
            </dl>
            <div class="form-text">Server will recompute. Max possible credit is the invoice's current outstanding (<?= money($outstanding) ?>).</div>
          </div>
        </div>
      </div>
    </div>

    <div class="d-flex gap-2 mt-3">
      <button class="btn btn-primary" id="btnSave" disabled><i class="bi bi-check2-circle me-1"></i>Save return</button>
      <a href="<?= url('client/returns/') ?>" class="btn btn-outline-secondary">Cancel</a>
    </div>
  </form>

  <script>
    (() => {
      const outstanding = <?= json_encode($outstanding) ?>;
      const CURRENCY = '₹';
      const inr = (n) => CURRENCY + (Math.round(n * 100) / 100).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});
      const rows = document.querySelectorAll('#retTable tbody tr');
      const btn  = document.getElementById('btnSave');

      const recalc = () => {
        let total = 0;
        rows.forEach(tr => {
          const q = parseFloat(tr.querySelector('.ret-qty').value) || 0;
          const u = parseFloat(tr.dataset.unit) || 0;
          const line = Math.round(q * u * 100) / 100;
          tr.querySelector('.ret-line').textContent = line ? inr(line) : '—';
          total += line;
        });
        const credit = Math.min(total, outstanding);
        const refund = Math.max(0, total - credit);
        document.getElementById('dispTotal').textContent  = inr(total);
        document.getElementById('prevTotal').textContent  = inr(total);
        document.getElementById('prevCredit').textContent = inr(credit);
        document.getElementById('prevRefund').textContent = inr(refund);
        btn.disabled = total <= 0;
      };
      rows.forEach(tr => {
        const q = tr.querySelector('.ret-qty');
        q.addEventListener('input', () => {
          const mx = parseFloat(tr.dataset.max);
          if ((parseFloat(q.value) || 0) > mx) q.value = mx;
          recalc();
        });
      });
      recalc();
    })();
  </script>
<?php endif; ?>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

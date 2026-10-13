<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('purchase.manage');

$shopId = current_shop_id();

$suppliers = db()->prepare('SELECT id, name FROM suppliers WHERE shop_id = :sid AND is_active = 1 ORDER BY name');
$suppliers->execute([':sid'=>$shopId]);
$suppliers = $suppliers->fetchAll();

$errors = [];

// Idempotency token (persists across refreshes within session until a successful submit).
if (empty($_SESSION['purchase_token'])) {
    $_SESSION['purchase_token'] = bin2hex(random_bytes(16));
}
$token = $_SESSION['purchase_token'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();

    $supplierId  = (int)($_POST['supplier_id'] ?? 0);
    $invoiceNo   = trim((string)($_POST['invoice_no'] ?? '')) ?: null;
    $pDate       = $_POST['purchase_date'] ?? date('Y-m-d');
    $charges     = max(0, (float)($_POST['charges'] ?? 0));
    $discount    = max(0, (float)($_POST['discount'] ?? 0));
    $notes       = trim((string)($_POST['notes'] ?? '')) ?: null;
    $finalize    = !empty($_POST['finalize']);
    $amountPaid  = max(0, (float)($_POST['amount_paid'] ?? 0));
    $payMethod   = in_array($_POST['pay_method'] ?? '', ['cash','upi','bank','other'], true) ? $_POST['pay_method'] : 'cash';
    $payRef      = trim((string)($_POST['pay_reference'] ?? '')) ?: null;
    $subToken    = (string)($_POST['txn_token'] ?? '');

    $prodIds = $_POST['product_id'] ?? [];
    $qtys    = $_POST['qty'] ?? [];
    $costs   = $_POST['cost'] ?? [];

    // Validate supplier
    $sv = db()->prepare('SELECT id FROM suppliers WHERE id = :id AND shop_id = :sid');
    $sv->execute([':id'=>$supplierId, ':sid'=>$shopId]);
    if (!$sv->fetchColumn()) $errors[] = 'Pick a valid supplier.';
    if (!DateTime::createFromFormat('Y-m-d', $pDate)) $errors[] = 'Invalid purchase date.';
    if ($subToken === '') $errors[] = 'Missing idempotency token — reload the page.';

    // Build line items with server-side revalidation
    $items = []; $subtotal = 0;
    foreach ($prodIds as $i => $pid) {
        $pid   = (int)$pid;
        $qty   = (float)($qtys[$i] ?? 0);
        $cost  = (float)($costs[$i] ?? 0);
        if ($pid <= 0 || $qty <= 0) continue;
        $pq = db()->prepare('SELECT id, name, sku FROM products WHERE id = :id AND shop_id = :sid');
        $pq->execute([':id'=>$pid, ':sid'=>$shopId]);
        $prod = $pq->fetch();
        if (!$prod) { $errors[] = 'Invalid product in line ' . ($i+1); continue; }
        $line = round($qty * $cost, 2);
        $items[] = ['product_id'=>$pid, 'name'=>$prod['name'], 'sku'=>$prod['sku'], 'qty'=>$qty, 'cost'=>$cost, 'line'=>$line];
        $subtotal += $line;
    }
    if (!$items) $errors[] = 'Add at least one line item.';
    $total = max(0, $subtotal + $charges - $discount);

    if (!$errors) {
        try {
            db()->beginTransaction();

            // Idempotency: UNIQUE on txn_token rejects duplicate submits.
            $status = $finalize ? 'received' : 'draft';
            db()->prepare(
                'INSERT INTO purchases (shop_id, supplier_id, invoice_no, purchase_date, subtotal, charges, discount, total,
                                       status, received_at, txn_token, created_by, notes)
                 VALUES (:sid, :supid, :inv, :pd, :sub, :ch, :dis, :tot, :st, :recd, :tk, :by, :nt)'
            )->execute([
                ':sid'=>$shopId, ':supid'=>$supplierId, ':inv'=>$invoiceNo, ':pd'=>$pDate,
                ':sub'=>$subtotal, ':ch'=>$charges, ':dis'=>$discount, ':tot'=>$total,
                ':st'=>$status, ':recd'=>$finalize ? date('Y-m-d H:i:s') : null,
                ':tk'=>$subToken, ':by'=>(int)current_user()['id'], ':nt'=>$notes,
            ]);
            $purchaseId = (int)db()->lastInsertId();

            $ii = db()->prepare('INSERT INTO purchase_items (purchase_id, product_id, product_name, sku_snapshot, qty, purchase_cost, line_total)
                                 VALUES (:pid, :prod, :pn, :sku, :q, :c, :lt)');
            foreach ($items as $it) {
                $ii->execute([
                    ':pid'=>$purchaseId, ':prod'=>$it['product_id'], ':pn'=>$it['name'], ':sku'=>$it['sku'],
                    ':q'=>$it['qty'], ':c'=>$it['cost'], ':lt'=>$it['line'],
                ]);
            }

            // Finalize → stock movement + stock update + maybe weighted-avg cost update
            if ($finalize) {
                $uid = (int)current_user()['id'];
                $mv = db()->prepare(
                    "INSERT INTO stock_movements (shop_id, product_id, type, qty_change, ref_type, ref_id, note, created_by)
                     VALUES (:sid, :pid, 'purchase', :q, 'purchase', :rid, :nt, :by)"
                );
                $upStock = db()->prepare('UPDATE products SET current_stock = current_stock + :q, purchase_cost = :c WHERE id = :id AND shop_id = :sid');
                foreach ($items as $it) {
                    $mv->execute([':sid'=>$shopId, ':pid'=>$it['product_id'], ':q'=>$it['qty'],
                                  ':rid'=>$purchaseId, ':nt'=>'Purchase #' . $purchaseId, ':by'=>$uid]);
                    $upStock->execute([':q'=>$it['qty'], ':c'=>$it['cost'], ':id'=>$it['product_id'], ':sid'=>$shopId]);
                }

                // Optional first payment
                if ($amountPaid > 0) {
                    db()->prepare(
                        'INSERT INTO supplier_payments (shop_id, supplier_id, purchase_id, amount, method, reference, paid_at, created_by)
                         VALUES (:sid, :supid, :pid, :amt, :m, :r, NOW(), :by)'
                    )->execute([
                        ':sid'=>$shopId, ':supid'=>$supplierId, ':pid'=>$purchaseId, ':amt'=>$amountPaid,
                        ':m'=>$payMethod, ':r'=>$payRef, ':by'=>$uid,
                    ]);
                }
            }

            db()->commit();
            audit('purchase.create', 'purchase', $purchaseId,
                  ['status'=>$status, 'total'=>$total, 'paid_now'=>$finalize ? $amountPaid : 0], $shopId);

            // Fresh token for the next purchase.
            unset($_SESSION['purchase_token']);
            flash('success', 'Purchase ' . ($finalize ? 'finalised' : 'saved as draft') . ' — total ' . money($total) . '.');
            redirect('client/purchases/view.php?id=' . $purchaseId);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            // Duplicate token (idempotency hit) — find the existing one and go there.
            if (str_contains($e->getMessage(), 'uq_pur_token')) {
                $q = db()->prepare('SELECT id FROM purchases WHERE txn_token = :t');
                $q->execute([':t'=>$subToken]);
                $existing = (int)$q->fetchColumn();
                if ($existing) {
                    unset($_SESSION['purchase_token']);
                    flash('warning', 'This purchase was already saved. Redirected to the existing record.');
                    redirect('client/purchases/view.php?id=' . $existing);
                }
            }
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$pageTitle = 'New Purchase';
$preSupplier = (int)($_GET['supplier'] ?? 0);
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">New Purchase</h1>
    <p class="sb-page-subtitle">Add line items. Totals re-computed on the server.</p>
  </div>
  <a href="<?= url('client/purchases/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" class="js-once" id="purForm">
  <?= csrf_field() ?>
  <input type="hidden" name="txn_token" value="<?= e($token) ?>">

  <div class="card mb-3">
    <div class="card-header">Header</div>
    <div class="card-body row g-3">
      <div class="col-md-5">
        <label class="form-label">Supplier *</label>
        <select class="form-select" name="supplier_id" required>
          <option value="">— Pick supplier —</option>
          <?php foreach ($suppliers as $s): ?>
            <option value="<?= (int)$s['id'] ?>" <?= $preSupplier === (int)$s['id'] ? 'selected' : '' ?>><?= e($s['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-4">
        <label class="form-label">Invoice no</label>
        <input class="form-control" name="invoice_no" value="<?= e($_POST['invoice_no'] ?? '') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Date</label>
        <input class="form-control" type="date" name="purchase_date" value="<?= e($_POST['purchase_date'] ?? date('Y-m-d')) ?>" required>
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
      <span>Items</span>
      <button type="button" class="btn btn-sm btn-outline-primary" id="addRow"><i class="bi bi-plus-lg"></i> Add row</button>
    </div>
    <div class="card-body">
      <div class="table-responsive">
        <table class="table" id="itemsTable">
          <thead><tr>
            <th style="width:45%">Product</th>
            <th>Qty</th><th>Cost</th><th class="sb-money">Line total</th><th></th>
          </tr></thead>
          <tbody></tbody>
        </table>
      </div>
    </div>
  </div>

  <div class="row g-3">
    <div class="col-lg-7">
      <div class="card">
        <div class="card-header">Payment (optional)</div>
        <div class="card-body row g-3">
          <div class="col-md-4">
            <label class="form-label">Amount paid now</label>
            <input class="form-control" type="number" step="0.01" min="0" name="amount_paid" value="0">
          </div>
          <div class="col-md-4">
            <label class="form-label">Method</label>
            <select class="form-select" name="pay_method">
              <?php foreach (['cash'=>'Cash','upi'=>'UPI','bank'=>'Bank','other'=>'Other'] as $k=>$lbl): ?>
                <option value="<?= $k ?>"><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="col-md-4">
            <label class="form-label">Reference</label>
            <input class="form-control" name="pay_reference">
          </div>
          <div class="col-12">
            <label class="form-label">Notes</label>
            <input class="form-control" name="notes" maxlength="500">
          </div>
        </div>
      </div>
    </div>

    <div class="col-lg-5">
      <div class="card">
        <div class="card-header">Totals</div>
        <div class="card-body">
          <div class="row g-2 mb-2">
            <div class="col-6"><label class="form-label">Other charges</label>
              <input class="form-control" type="number" step="0.01" min="0" name="charges" id="charges" value="0"></div>
            <div class="col-6"><label class="form-label">Discount</label>
              <input class="form-control" type="number" step="0.01" min="0" name="discount" id="discount" value="0"></div>
          </div>
          <dl class="row mb-0 mt-2">
            <dt class="col-6 text-muted">Subtotal</dt><dd class="col-6 sb-money" id="dispSub"><?= money(0) ?></dd>
            <dt class="col-6 text-muted">Charges</dt><dd class="col-6 sb-money" id="dispCh"><?= money(0) ?></dd>
            <dt class="col-6 text-muted">Discount</dt><dd class="col-6 sb-money text-danger" id="dispDis">-<?= money(0) ?></dd>
            <dt class="col-6 fw-bold">Grand total</dt><dd class="col-6 sb-money fw-bold fs-5" id="dispTotal"><?= money(0) ?></dd>
          </dl>
          <div class="form-text mt-2">Server will recompute — these are display only.</div>
        </div>
      </div>
    </div>
  </div>

  <div class="d-flex gap-2 mt-3">
    <button class="btn btn-outline-secondary" type="submit">Save as draft</button>
    <button class="btn btn-primary" type="submit" name="finalize" value="1">
      <i class="bi bi-check2-circle me-1"></i>Finalize &amp; update stock
    </button>
  </div>
</form>

<template id="rowTpl">
  <tr>
    <td>
      <div class="position-relative">
        <input class="form-control prod-search" placeholder="Search product by name/SKU/barcode…" autocomplete="off">
        <input type="hidden" name="product_id[]" class="prod-id">
        <div class="dropdown-menu w-100 shadow-sm" style="max-height:260px;overflow:auto;"></div>
      </div>
    </td>
    <td><input class="form-control qty" type="number" step="0.001" min="0" name="qty[]" value="1"></td>
    <td><input class="form-control cost" type="number" step="0.01" min="0" name="cost[]" value="0"></td>
    <td class="sb-money line">—</td>
    <td class="text-end"><button type="button" class="btn btn-sm btn-outline-danger rem"><i class="bi bi-trash"></i></button></td>
  </tr>
</template>

<script>
(() => {
  const tbody = document.querySelector('#itemsTable tbody');
  const tpl   = document.getElementById('rowTpl');

  const inr = (n) => '<?= APP_CURRENCY_SYMBOL ?>' + (Math.round(n * 100) / 100).toLocaleString('en-IN', {minimumFractionDigits:2, maximumFractionDigits:2});

  const recalc = () => {
    let sub = 0;
    tbody.querySelectorAll('tr').forEach(tr => {
      const q = parseFloat(tr.querySelector('.qty').value)  || 0;
      const c = parseFloat(tr.querySelector('.cost').value) || 0;
      const line = Math.round(q * c * 100) / 100;
      tr.querySelector('.line').textContent = line ? inr(line) : '—';
      sub += line;
    });
    const ch  = parseFloat(document.getElementById('charges').value)  || 0;
    const dis = parseFloat(document.getElementById('discount').value) || 0;
    const total = Math.max(0, sub + ch - dis);
    document.getElementById('dispSub').textContent   = inr(sub);
    document.getElementById('dispCh').textContent    = inr(ch);
    document.getElementById('dispDis').textContent   = '-' + inr(dis);
    document.getElementById('dispTotal').textContent = inr(total);
  };

  const addRow = () => {
    tbody.appendChild(tpl.content.cloneNode(true));
    recalc();
  };

  document.getElementById('addRow').addEventListener('click', addRow);

  // Delegated events
  tbody.addEventListener('input', (e) => {
    if (e.target.matches('.qty, .cost, .charges, .discount')) recalc();
    if (e.target.matches('.prod-search')) {
      const row = e.target.closest('tr');
      const menu = row.querySelector('.dropdown-menu');
      const q = e.target.value.trim();
      if (q.length < 1) { menu.classList.remove('show'); return; }
      fetch('<?= url('ajax/search-products.php') ?>?q=' + encodeURIComponent(q))
        .then(r => r.json()).then(d => {
          menu.innerHTML = (d.items || []).slice(0, 20).map(p =>
            `<a class="dropdown-item" href="#" data-id="${p.id}" data-cost="${p.selling_price}" data-name="${p.name}">
               <strong>${p.name}</strong>${p.sku ? ' <span class="text-muted small">· '+p.sku+'</span>' : ''}
             </a>`
          ).join('') || '<div class="dropdown-item text-muted">No match</div>';
          menu.classList.add('show');
        });
    }
  });

  document.getElementById('charges').addEventListener('input', recalc);
  document.getElementById('discount').addEventListener('input', recalc);

  tbody.addEventListener('click', (e) => {
    const link = e.target.closest('.dropdown-item');
    if (link && link.dataset.id) {
      e.preventDefault();
      const row = link.closest('tr');
      row.querySelector('.prod-id').value = link.dataset.id;
      row.querySelector('.prod-search').value = link.dataset.name;
      row.querySelector('.dropdown-menu').classList.remove('show');
      row.querySelector('.qty').focus();
    }
    const rem = e.target.closest('.rem');
    if (rem) { rem.closest('tr').remove(); recalc(); }
  });

  document.addEventListener('click', (e) => {
    if (!e.target.closest('.position-relative')) {
      tbody.querySelectorAll('.dropdown-menu.show').forEach(m => m.classList.remove('show'));
    }
  });

  // Start with one row.
  addRow();
})();
</script>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

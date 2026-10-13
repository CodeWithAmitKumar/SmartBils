<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('report.view');

$shopId = current_shop_id();
$from = $_GET['from'] ?? date('Y-m-01');
$to   = $_GET['to']   ?? date('Y-m-d');
if (!DateTime::createFromFormat('Y-m-d', $from)) $from = date('Y-m-01');
if (!DateTime::createFromFormat('Y-m-d', $to))   $to   = date('Y-m-d');

// Gross sales (non-cancelled)
$g = db()->prepare("SELECT COALESCE(SUM(grand_total), 0) FROM sales
                    WHERE shop_id = :sid AND status <> 'cancelled' AND DATE(sale_date) BETWEEN :a AND :b");
$g->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$grossSales = (float)$g->fetchColumn();

// Returns
$r = db()->prepare('SELECT COALESCE(SUM(sr.total_return_amount), 0)
                    FROM sales_returns sr JOIN sales s ON s.id = sr.sale_id
                    WHERE s.shop_id = :sid AND DATE(sr.created_at) BETWEEN :a AND :b');
$r->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$returns = (float)$r->fetchColumn();
$netSales = $grossSales - $returns;

// COGS using current purchase_cost on each product (documented below)
$c = db()->prepare("SELECT COALESCE(SUM(si.qty * COALESCE(p.purchase_cost, 0)), 0)
                    FROM sale_items si
                    JOIN sales s     ON s.id = si.sale_id
                    LEFT JOIN products p ON p.id = si.product_id
                    WHERE s.shop_id = :sid AND s.status <> 'cancelled'
                      AND DATE(s.sale_date) BETWEEN :a AND :b");
$c->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$cogsSales = (float)$c->fetchColumn();

// COGS to subtract back for returned items (same current-cost basis)
$cr = db()->prepare("SELECT COALESCE(SUM(sri.qty_returned * COALESCE(p.purchase_cost, 0)), 0)
                     FROM sales_return_items sri
                     JOIN sales_returns sr ON sr.id = sri.sales_return_id
                     LEFT JOIN products p ON p.id = sri.product_id
                     WHERE sr.shop_id = :sid AND DATE(sr.created_at) BETWEEN :a AND :b");
$cr->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$cogsReturned = (float)$cr->fetchColumn();
$cogs = $cogsSales - $cogsReturned;

$grossProfit = $netSales - $cogs;

// Expenses
$e = db()->prepare('SELECT COALESCE(SUM(amount), 0) FROM expenses
                    WHERE shop_id = :sid AND expense_date BETWEEN :a AND :b');
$e->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$expenses = (float)$e->fetchColumn();
$netProfit = $grossProfit - $expenses;

// Cash / UPI collected
$p = db()->prepare("SELECT p.method, COALESCE(SUM(p.amount), 0) AS t FROM payments p
                    WHERE p.shop_id = :sid AND p.status = 'verified' AND DATE(p.paid_at) BETWEEN :a AND :b
                    GROUP BY p.method");
$p->execute([':sid'=>$shopId, ':a'=>$from, ':b'=>$to]);
$cashIn = $upiIn = $otherIn = 0;
foreach ($p->fetchAll() as $pr) {
    if ($pr['method'] === 'cash')      $cashIn  = (float)$pr['t'];
    elseif ($pr['method'] === 'upi')   $upiIn   = (float)$pr['t'];
    else                               $otherIn += (float)$pr['t'];
}

// Receivables (all time)
$recQ = db()->prepare("
  SELECT COALESCE(SUM(s.grand_total), 0) -
         COALESCE((SELECT SUM(pa.allocated_amount)
                   FROM payment_allocations pa
                   JOIN payments p ON p.id = pa.payment_id
                   JOIN sales s2 ON s2.id = pa.sale_id
                   WHERE s2.shop_id = :sid AND p.status = 'verified'), 0) AS recv
  FROM sales s WHERE s.shop_id = :sid AND s.status <> 'cancelled'");
$recQ->execute([':sid'=>$shopId]);
$receivables = max(0, (float)$recQ->fetchColumn());

// Payables
$payablesQ = db()->prepare("
  SELECT COALESCE(SUM(total), 0) - COALESCE((SELECT SUM(amount) FROM supplier_payments WHERE shop_id = :sid), 0) AS pay
  FROM purchases WHERE shop_id = :sid AND status = 'received'");
$payablesQ->execute([':sid'=>$shopId]);
$payables = max(0, (float)$payablesQ->fetchColumn());

if (($_GET['export'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="profit-' . $from . '_' . $to . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Metric', 'Amount']);
    foreach ([
        ['Gross sales', $grossSales], ['Returns', $returns], ['Net sales', $netSales],
        ['COGS (current cost)', $cogs], ['Gross profit', $grossProfit],
        ['Expenses', $expenses], ['Net profit (estimate)', $netProfit],
        ['Cash collected', $cashIn], ['UPI collected', $upiIn], ['Other collected', $otherIn],
        ['Receivables (all time)', $receivables], ['Supplier payables (all time)', $payables],
    ] as $x) fputcsv($out, $x);
    exit;
}

$pageTitle = 'Profit Report';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Profit Report</h1>
    <p class="sb-page-subtitle">Net profit (estimate): <strong class="<?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($netProfit) ?></strong></p>
  </div>
  <a href="<?= url('client/reports/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>All reports</a>
</div>

<form method="get" class="card p-3 mb-3">
  <div class="row g-2 align-items-end">
    <div class="col-md-3"><label class="form-label">From</label><input type="date" class="form-control" name="from" value="<?= e($from) ?>"></div>
    <div class="col-md-3"><label class="form-label">To</label><input type="date" class="form-control" name="to" value="<?= e($to) ?>"></div>
    <div class="col-md-6 d-flex gap-2">
      <button class="btn btn-primary"><i class="bi bi-funnel me-1"></i>Apply</button>
      <a class="btn btn-outline-secondary" href="?from=<?= e($from) ?>&to=<?= e($to) ?>&export=csv"><i class="bi bi-download me-1"></i>Export CSV</a>
    </div>
  </div>
</form>

<div class="card mb-3">
  <div class="card-header">P&amp;L in range</div>
  <div class="card-body">
    <table class="table mb-0">
      <tbody>
        <tr><td>Gross sales (invoices in range, non-cancelled)</td><td class="sb-money"><?= money($grossSales) ?></td></tr>
        <tr><td>Less: Returns in range</td><td class="sb-money text-danger">− <?= money($returns) ?></td></tr>
        <tr class="fw-semibold"><td>Net sales</td><td class="sb-money"><?= money($netSales) ?></td></tr>
        <tr><td>Less: COGS <small class="text-muted">(see note)</small></td><td class="sb-money text-danger">− <?= money($cogs) ?></td></tr>
        <tr class="fw-semibold"><td>Gross profit</td><td class="sb-money"><?= money($grossProfit) ?></td></tr>
        <tr><td>Less: Expenses</td><td class="sb-money text-danger">− <?= money($expenses) ?></td></tr>
        <tr class="fw-bold fs-5" style="border-top:2px solid #0F172A;">
          <td>Net profit (estimate)</td>
          <td class="sb-money <?= $netProfit >= 0 ? 'text-success' : 'text-danger' ?>"><?= money($netProfit) ?></td>
        </tr>
      </tbody>
    </table>
    <div class="alert alert-info mt-3 mb-0 small">
      <strong>Costing method:</strong> COGS multiplies each sold unit by its product's <em>current</em> <code>purchase_cost</code>.
      This is a simplification — a proper weighted-average or FIFO is planned for the inventory engine. Historical profit will
      shift if purchase cost is edited later. Taxes and refunds-paid-out are <strong>not</strong> included in this estimate.
    </div>
  </div>
</div>

<div class="row g-3">
  <?php foreach ([
    ['Cash in',  $cashIn,     'wallet2',       '#D1FAE5','#047857'],
    ['UPI in',   $upiIn,      'phone',         '#DBEAFE','#1D4ED8'],
    ['Other in', $otherIn,    'bank',          '#FFF7ED','#9A3412'],
    ['Receivables', $receivables, 'exclamation-circle', '#FEE2E2','#B91C1C'],
    ['Payables', $payables,  'truck',         '#FEF3C7','#B45309'],
  ] as [$lbl, $v, $icon, $bg, $fg]): ?>
    <div class="col-md-6 col-xl">
      <div class="sb-stat h-100">
        <div class="sb-stat-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;"><i class="bi bi-<?= $icon ?>"></i></div>
        <div><div class="sb-stat-value" style="font-size:18px;"><?= money($v) ?></div><div class="sb-stat-label"><?= e($lbl) ?></div></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

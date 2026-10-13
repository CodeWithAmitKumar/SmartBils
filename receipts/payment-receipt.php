<?php
require_once __DIR__ . '/../config/app.php';
require_shop_user();
require_permission('bill.view');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Payment not found.'); }

$p = db()->prepare('
  SELECT p.*, c.name AS cust_name, c.mobile AS cust_mobile, c.email AS cust_email,
         sh.name AS shop_name, sh.phone AS shop_phone, sh.address AS shop_address, sh.logo_path,
         ss.receipt_header, ss.receipt_footer
  FROM payments p
  JOIN shops sh ON sh.id = p.shop_id
  LEFT JOIN customers c      ON c.id  = p.customer_id
  LEFT JOIN shop_settings ss ON ss.shop_id = p.shop_id
  WHERE p.id = :id AND p.shop_id = :sid');
$p->execute([':id' => $id, ':sid' => $shopId]);
$pay = $p->fetch();
if (!$pay) { http_response_code(404); exit('Payment not found.'); }

$allocs = db()->prepare('
  SELECT pa.allocated_amount, s.id AS sale_id, s.invoice_no, s.sale_date, s.grand_total,
         COALESCE((SELECT SUM(x.allocated_amount) FROM payment_allocations x
                   JOIN payments pp ON pp.id = x.payment_id
                   WHERE x.sale_id = s.id AND pp.status = "verified"), 0) AS paid_verified
  FROM payment_allocations pa
  JOIN sales s ON s.id = pa.sale_id
  WHERE pa.payment_id = :id
  ORDER BY s.id');
$allocs->execute([':id' => $id]);
$allocs = $allocs->fetchAll();

$layout  = $_GET['layout'] ?? 'a4';
$logoUrl = image_url($pay['logo_path'] ?? null);

$statusCls = [
    'verified' => 'paid',
    'pending'  => 'partial',
    'failed'   => 'unpaid',
    'reversed' => 'unpaid',
][$pay['status']] ?? 'partial';
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Payment Receipt · #<?= (int)$pay['id'] ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body { font-family: 'Inter', system-ui, sans-serif; color: #111; background: #F3F4F6; margin: 0; padding: 24px; }
  .toolbar { max-width: 820px; margin: 0 auto 16px; display: flex; gap: 8px; }
  .toolbar .spacer { flex: 1; }
  .toolbar a, .toolbar button { padding: 8px 14px; border-radius: 8px; border: 1px solid #CBD5E1; background: #fff; color: #0F172A; font-size: 14px; cursor: pointer; text-decoration: none; }
  .toolbar .btn-primary { background: #4F46E5; color: #fff; border-color: #4F46E5; }
  .toolbar .btn-wa { background: #25D366; color: #fff; border-color: #25D366; }
  .toolbar .btn-sms { background: #0EA5E9; color: #fff; border-color: #0EA5E9; }

  .sheet { background: #fff; margin: 0 auto; padding: 36px 44px; width: 820px; box-shadow: 0 10px 30px rgba(15,23,42,.1); border-radius: 8px; }
  .sheet.w80 { width: 302px; padding: 16px; font-size: 12px; font-family: 'Courier New', monospace; }
  .sheet.w58 { width: 219px; padding: 10px; font-size: 11px; font-family: 'Courier New', monospace; }

  .brand { display: flex; align-items: center; gap: 14px; }
  .logo { width: 56px; height: 56px; border-radius: 10px; background: #EEF2FF; color: #4F46E5; display: inline-flex; align-items: center; justify-content: center; font-size: 24px; overflow: hidden; }
  .logo img { width: 100%; height: 100%; object-fit: cover; }
  .shop-name { font-size: 22px; font-weight: 700; margin: 0; }
  .muted { color: #64748B; font-size: 12.5px; }
  .divider { border-top: 1px dashed #CBD5E1; margin: 14px 0; }
  table { width: 100%; border-collapse: collapse; margin: 10px 0; }
  th, td { padding: 8px 6px; text-align: left; font-size: 13px; }
  .sheet.w80 th, .sheet.w80 td, .sheet.w58 th, .sheet.w58 td { padding: 4px 2px; font-size: inherit; }
  thead th { border-bottom: 1px solid #0F172A; }
  tbody tr td { border-bottom: 1px solid #E5E7EB; }
  .text-right { text-align: right; font-variant-numeric: tabular-nums; }
  .grand { font-size: 20px; font-weight: 700; }
  .status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
  .status.paid    { background: #D1FAE5; color: #065F46; }
  .status.partial { background: #FEF3C7; color: #92400E; }
  .status.unpaid  { background: #FEE2E2; color: #991B1B; }
  .ribbon {
    display: inline-block; padding: 4px 12px; border-radius: 999px; font-size: 11px; font-weight: 700;
    background: #EEF2FF; color: #4F46E5; letter-spacing: .08em; text-transform: uppercase;
  }
  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none !important; }
    .sheet { box-shadow: none; border-radius: 0; margin: 0; padding: 10mm; }
    .sheet.w80 { width: 80mm !important; } .sheet.w58 { width: 58mm !important; }
    @page { margin: 6mm; }
  }
</style></head>
<body>

<?php
$msg = "Payment received at " . $pay['shop_name']
     . " — ₹" . number_format((float)$pay['amount'], 2)
     . " via " . strtoupper($pay['method'])
     . " on " . date('d M Y H:i', strtotime($pay['paid_at']))
     . ". Receipt #" . $pay['id'] . "."
     . ($pay['status'] === 'pending' ? ' (Pending verification.)' : '');
include __DIR__ . '/_share-bar.php';
?>

<div class="toolbar">
  <a href="<?= url('client/payments/') ?>">← Payments</a>
  <a href="?id=<?= $id ?>&layout=a4" class="<?= $layout==='a4'?'btn-primary':'' ?>">A4</a>
  <a href="?id=<?= $id ?>&layout=80" class="<?= $layout==='80'?'btn-primary':'' ?>">80mm</a>
  <a href="?id=<?= $id ?>&layout=58" class="<?= $layout==='58'?'btn-primary':'' ?>">58mm</a>
  <div class="spacer"></div>
  <button class="btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="sheet <?= $layout === '80' ? 'w80' : ($layout === '58' ? 'w58' : '') ?>">
  <?php if ($layout === 'a4'): ?>
    <div class="brand">
      <div class="logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?>🧾<?php endif; ?></div>
      <div>
        <h1 class="shop-name"><?= e($pay['shop_name']) ?></h1>
        <div class="muted"><?= e($pay['shop_phone'] ?? '') ?></div>
        <div class="muted"><?= nl2br(e($pay['shop_address'] ?? '')) ?></div>
      </div>
    </div>
    <div class="divider"></div>
    <div style="display:flex; justify-content:space-between; align-items:flex-start;">
      <div>
        <span class="ribbon">Payment Receipt</span>
        <h2 style="margin:12px 0 4px; font-size:22px;">Payment #<?= (int)$pay['id'] ?></h2>
        <div class="muted"><?= e(date('d M Y, h:i A', strtotime($pay['paid_at']))) ?></div>
      </div>
      <div class="text-right">
        <div class="muted">From</div>
        <strong><?= e($pay['cust_name'] ?? 'Walk-in') ?></strong>
        <?php if ($pay['cust_mobile']): ?><div class="muted"><?= e($pay['cust_mobile']) ?></div><?php endif; ?>
      </div>
    </div>
  <?php else: ?>
    <div style="text-align:center;">
      <strong style="font-size:14px;"><?= e($pay['shop_name']) ?></strong><br>
      <?php if ($pay['shop_phone']): ?><?= e($pay['shop_phone']) ?><br><?php endif; ?>
      <strong>PAYMENT RECEIPT</strong>
    </div>
    <div class="divider"></div>
    <div>#<?= (int)$pay['id'] ?> · <?= e(date('d M Y H:i', strtotime($pay['paid_at']))) ?></div>
    <div>Customer: <?= e($pay['cust_name'] ?? '—') ?><?php if ($pay['cust_mobile']): ?> · <?= e($pay['cust_mobile']) ?><?php endif; ?></div>
    <div>Method: <?= e(strtoupper($pay['method'])) ?><?php if ($pay['reference']): ?> · <?= e($pay['reference']) ?><?php endif; ?></div>
  <?php endif; ?>

  <table>
    <thead><tr>
      <th>Against invoice</th>
      <?php if ($layout === 'a4'): ?><th>Date</th><th class="text-right">Invoice total</th><th class="text-right">Paid before</th><?php endif; ?>
      <th class="text-right">Allocated now</th>
    </tr></thead>
    <tbody>
    <?php foreach ($allocs as $a):
        $paidBefore = max(0, (float)$a['paid_verified'] - (float)$a['allocated_amount']);
    ?>
      <tr>
        <td><strong><?= e($a['invoice_no']) ?></strong></td>
        <?php if ($layout === 'a4'): ?>
          <td class="muted"><?= e(date('d M Y', strtotime($a['sale_date']))) ?></td>
          <td class="text-right"><?= money($a['grand_total']) ?></td>
          <td class="text-right"><?= money($paidBefore) ?></td>
        <?php endif; ?>
        <td class="text-right"><?= money($a['allocated_amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <table>
    <tr>
      <td><?php if ($layout === 'a4'): ?><strong>Payment method</strong> · <?= e(strtoupper($pay['method'])) ?><?php if ($pay['reference']): ?> · ref <code><?= e($pay['reference']) ?></code><?php endif; ?><?php endif; ?></td>
      <td class="text-right grand"><?= money($pay['amount']) ?></td>
    </tr>
    <tr>
      <td colspan="2" class="text-right">
        <span class="status <?= $statusCls ?>"><?= e($pay['status']) ?></span>
      </td>
    </tr>
  </table>

  <?php if ($pay['status'] === 'pending'): ?>
    <div class="muted" style="padding:12px; border-radius:8px; background:#FFFBEB; border:1px solid #FDE68A; color:#92400E;">
      <i class="bi bi-exclamation-triangle"></i> UPI payment is <strong>pending verification</strong>. Outstanding will update once the shop verifies it.
    </div>
  <?php endif; ?>

  <?php if ($pay['receipt_footer']): ?>
    <div class="muted" style="margin-top:16px; text-align:center;"><?= nl2br(e($pay['receipt_footer'])) ?></div>
  <?php endif; ?>
</div>

</body></html>

<?php
require_once __DIR__ . '/../config/app.php';
require_shop_user();
require_permission('bill.view');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Return not found.'); }

$r = db()->prepare('
  SELECT sr.*, s.invoice_no, s.sale_date,
         c.name AS cust_name, c.mobile AS cust_mobile,
         sh.name AS shop_name, sh.phone AS shop_phone, sh.address AS shop_address, sh.logo_path,
         ss.receipt_header, ss.receipt_footer
  FROM sales_returns sr
  JOIN sales s ON s.id = sr.sale_id
  JOIN shops sh ON sh.id = sr.shop_id
  LEFT JOIN customers c      ON c.id = s.customer_id
  LEFT JOIN shop_settings ss ON ss.shop_id = sr.shop_id
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

$layout  = $_GET['layout'] ?? 'a4';
$logoUrl = image_url($ret['logo_path'] ?? null);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Return Receipt · <?= e($ret['return_no']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body { font-family: 'Inter', system-ui, sans-serif; color: #111; background: #F3F4F6; margin: 0; padding: 24px; }
  .toolbar { max-width: 820px; margin: 0 auto 16px; display: flex; gap: 8px; flex-wrap: wrap; }
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
  thead th { border-bottom: 1px solid #0F172A; }
  tbody tr td { border-bottom: 1px solid #E5E7EB; }
  .text-right { text-align: right; font-variant-numeric: tabular-nums; }
  .grand { font-size: 18px; font-weight: 700; }
  .ribbon { display:inline-block; padding:4px 12px; border-radius:999px; font-size:11px; font-weight:700;
            background:#FEF3C7; color:#92400E; letter-spacing:.08em; text-transform:uppercase; }
  @media print { body { background:#fff; padding:0; } .toolbar { display:none !important; }
                 .sheet { box-shadow:none; border-radius:0; margin:0; padding:10mm; }
                 .sheet.w80 { width: 80mm !important; } .sheet.w58 { width: 58mm !important; }
                 @page { margin: 6mm; } }
</style></head>
<body>

<?php
$msg = "Return " . $ret['return_no'] . " at " . $ret['shop_name']
     . " against invoice " . $ret['invoice_no']
     . " — total ₹" . number_format((float)$ret['total_return_amount'], 2)
     . " (credit ₹" . number_format((float)$ret['credit_to_invoice'], 2)
     . ", cash refund ₹" . number_format((float)$ret['cash_refund_amount'], 2) . ").";
include __DIR__ . '/_share-bar.php';
?>

<div class="toolbar">
  <a href="<?= url('client/returns/view.php?id=' . $id) ?>">← Return</a>
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
        <h1 class="shop-name"><?= e($ret['shop_name']) ?></h1>
        <div class="muted"><?= e($ret['shop_phone'] ?? '') ?></div>
        <div class="muted"><?= nl2br(e($ret['shop_address'] ?? '')) ?></div>
      </div>
    </div>
    <div class="divider"></div>
    <div style="display:flex; justify-content:space-between;">
      <div>
        <span class="ribbon">Return Receipt</span>
        <h2 style="margin:12px 0 4px;"><?= e($ret['return_no']) ?></h2>
        <div class="muted">Against invoice <strong><?= e($ret['invoice_no']) ?></strong></div>
      </div>
      <div class="text-right">
        <div class="muted">Date</div>
        <strong><?= e(date('d M Y H:i', strtotime($ret['created_at']))) ?></strong>
        <div class="muted"><?= e($ret['cust_name'] ?? 'Walk-in') ?><?php if ($ret['cust_mobile']): ?> · <?= e($ret['cust_mobile']) ?><?php endif; ?></div>
      </div>
    </div>
  <?php else: ?>
    <div style="text-align:center;">
      <strong style="font-size:14px;"><?= e($ret['shop_name']) ?></strong><br>
      <strong>RETURN RECEIPT</strong>
    </div>
    <div class="divider"></div>
    <div><strong><?= e($ret['return_no']) ?></strong></div>
    <div>Invoice: <?= e($ret['invoice_no']) ?></div>
    <div><?= e(date('d M Y H:i', strtotime($ret['created_at']))) ?></div>
    <div>Customer: <?= e($ret['cust_name'] ?? '—') ?></div>
  <?php endif; ?>

  <table>
    <thead><tr><th>Item</th><th>Qty</th><?php if ($layout === 'a4'): ?><th>Condition</th><?php endif; ?><th class="text-right">Amount</th></tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['product_name']) ?></td>
        <td><?= rtrim(rtrim(number_format((float)$it['qty_returned'], 3, '.', ''), '0'), '.') ?></td>
        <?php if ($layout === 'a4'): ?><td><?= e(ucfirst($it['stock_condition'])) ?></td><?php endif; ?>
        <td class="text-right"><?= money($it['amount']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <table>
    <tr><td class="muted">Return total</td><td class="text-right"><?= money($ret['total_return_amount']) ?></td></tr>
    <?php if ((float)$ret['credit_to_invoice'] > 0): ?>
      <tr><td class="muted">Credit to invoice</td><td class="text-right"><?= money($ret['credit_to_invoice']) ?></td></tr>
    <?php endif; ?>
    <?php if ((float)$ret['cash_refund_amount'] > 0): ?>
      <tr class="grand"><td>CASH REFUND</td><td class="text-right"><?= money($ret['cash_refund_amount']) ?></td></tr>
    <?php endif; ?>
  </table>

  <?php if ($ret['reason']): ?>
    <div class="muted" style="margin-top:12px;"><strong>Reason:</strong> <?= nl2br(e($ret['reason'])) ?></div>
  <?php endif; ?>

  <?php if ($ret['receipt_footer']): ?>
    <div class="muted" style="margin-top:16px; text-align:center;"><?= nl2br(e($ret['receipt_footer'])) ?></div>
  <?php endif; ?>
</div>

</body></html>

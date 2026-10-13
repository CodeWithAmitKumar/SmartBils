<?php
require_once __DIR__ . '/../config/app.php';
require_shop_user();
require_permission('bill.view');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Invoice not found.'); }

$s = db()->prepare('
  SELECT sa.*, c.name AS cust_name, c.mobile AS cust_mobile, c.email AS cust_email,
         sh.name AS shop_name, sh.phone AS shop_phone, sh.address AS shop_address, sh.email AS shop_email, sh.logo_path,
         ss.receipt_header, ss.receipt_footer, ss.upi_id, ss.upi_qr_path
  FROM sales sa
  JOIN shops sh ON sh.id = sa.shop_id
  LEFT JOIN customers c      ON c.id  = sa.customer_id
  LEFT JOIN shop_settings ss ON ss.shop_id = sa.shop_id
  WHERE sa.id = :id AND sa.shop_id = :sid');
$s->execute([':id'=>$id, ':sid'=>$shopId]);
$sale = $s->fetch();
if (!$sale) { http_response_code(404); exit('Invoice not found.'); }

$items = db()->prepare('SELECT * FROM sale_items WHERE sale_id = :id');
$items->execute([':id'=>$id]);
$items = $items->fetchAll();

$payments = db()->prepare('
  SELECT p.*, pa.allocated_amount
  FROM payment_allocations pa
  JOIN payments p ON p.id = pa.payment_id
  WHERE pa.sale_id = :id
  ORDER BY p.paid_at ASC');
$payments->execute([':id'=>$id]);
$payments = $payments->fetchAll();

$paid = 0;
foreach ($payments as $p) if ($p['status'] === 'verified') $paid += (float)$p['allocated_amount'];
$balance = max(0, (float)$sale['grand_total'] - $paid);

$layout = $_GET['layout'] ?? 'a4';  // 'a4' | '80' | '58'
$logoUrl = image_url($sale['logo_path'] ?? null);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Receipt · <?= e($sale['invoice_no']) ?></title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.3/font/bootstrap-icons.css" rel="stylesheet">
<style>
  body { font-family: 'Inter', system-ui, -apple-system, sans-serif; color: #111; background: #F3F4F6; margin: 0; padding: 24px; }
  .toolbar { max-width: 820px; margin: 0 auto 16px; display: flex; gap: 8px; }
  .toolbar .spacer { flex: 1; }
  .toolbar a, .toolbar button { padding: 8px 14px; border-radius: 8px; border: 1px solid #CBD5E1; background: #fff; color: #0F172A; font-size: 14px; cursor: pointer; text-decoration: none; }
  .toolbar .btn-primary { background: #4F46E5; color: #fff; border-color: #4F46E5; }
  .toolbar .btn-wa { background: #25D366; color: #fff; border-color: #25D366; }
  .toolbar .btn-sms { background: #0EA5E9; color: #fff; border-color: #0EA5E9; }

  .sheet {
    background: #fff; margin: 0 auto; padding: 36px 44px;
    width: 820px; min-height: 1120px;
    box-shadow: 0 10px 30px rgba(15,23,42,.1);
    border-radius: 8px;
  }
  .sheet.w80 { width: 302px; min-height: auto; padding: 16px; font-size: 12px; font-family: 'Courier New', monospace; }
  .sheet.w58 { width: 219px; min-height: auto; padding: 10px; font-size: 11px; font-family: 'Courier New', monospace; }

  .brand { display: flex; align-items: center; gap: 14px; }
  .logo { width: 56px; height: 56px; border-radius: 10px; background: #EEF2FF; color: #4F46E5; display: inline-flex; align-items: center; justify-content: center; font-size: 24px; overflow: hidden; }
  .logo img { width: 100%; height: 100%; object-fit: cover; }
  .shop-name { font-size: 22px; font-weight: 700; margin: 0; }
  .muted { color: #64748B; font-size: 12.5px; }
  .divider { border-top: 1px dashed #CBD5E1; margin: 14px 0; }
  .row { display: flex; gap: 12px; }
  .row > div { flex: 1; }
  table { width: 100%; border-collapse: collapse; margin: 10px 0; }
  th, td { padding: 8px 6px; text-align: left; font-size: 13px; }
  .sheet.w80 th, .sheet.w80 td, .sheet.w58 th, .sheet.w58 td { padding: 4px 2px; font-size: inherit; }
  thead th { border-bottom: 1px solid #0F172A; }
  tbody tr td { border-bottom: 1px solid #E5E7EB; }
  .sb-money, .text-right { text-align: right; font-variant-numeric: tabular-nums; }
  .totals td { border: 0 !important; padding: 3px 6px; }
  .grand { font-size: 18px; font-weight: 700; border-top: 1px solid #0F172A; padding-top: 6px; }
  .status { display: inline-block; padding: 2px 10px; border-radius: 999px; font-size: 11px; font-weight: 600; text-transform: uppercase; }
  .status.paid    { background: #D1FAE5; color: #065F46; }
  .status.partial { background: #FEF3C7; color: #92400E; }
  .status.unpaid  { background: #FEE2E2; color: #991B1B; }
  .foot-note { margin-top: 20px; text-align: center; font-size: 12px; color: #64748B; }

  @media print {
    body { background: #fff; padding: 0; }
    .toolbar { display: none !important; }
    .sheet { box-shadow: none; border-radius: 0; margin: 0; padding: 10mm; }
    .sheet.w80 { width: 80mm !important; }
    .sheet.w58 { width: 58mm !important; }
    @page { margin: 6mm; }
  }
</style></head>
<body>

<?php
$msg = "Hi " . ($sale['cust_name'] ?: 'customer') . ", your bill from " . $sale['shop_name']
     . " — Invoice " . $sale['invoice_no']
     . ", total ₹" . number_format((float)$sale['grand_total'], 2)
     . ", balance ₹" . number_format($balance, 2) . "."
     . ($sale['upi_id'] && $balance > 0 ? ' Pay via UPI: ' . $sale['upi_id'] : '');
include __DIR__ . '/_share-bar.php';
?>

<div class="toolbar">
  <a href="<?= url('client/billing/') ?>">← Back to POS</a>
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
        <h1 class="shop-name"><?= e($sale['shop_name']) ?></h1>
        <div class="muted">
          <?php if ($sale['shop_phone']): ?><?= e($sale['shop_phone']) ?> · <?php endif; ?>
          <?= e($sale['shop_email'] ?? '') ?>
        </div>
        <div class="muted"><?= nl2br(e($sale['shop_address'] ?? '')) ?></div>
      </div>
    </div>
    <?php if ($sale['receipt_header']): ?>
      <div class="muted mt-2"><?= nl2br(e($sale['receipt_header'])) ?></div>
    <?php endif; ?>
    <div class="divider"></div>
    <div class="row">
      <div>
        <div class="muted">Bill to</div>
        <strong><?= e($sale['cust_name'] ?? 'Walk-in customer') ?></strong>
        <?php if ($sale['cust_mobile']): ?><div class="muted"><?= e($sale['cust_mobile']) ?></div><?php endif; ?>
      </div>
      <div class="text-right">
        <div class="muted">Invoice</div>
        <strong><?= e($sale['invoice_no']) ?></strong>
        <div class="muted"><?= e(date('d M Y, h:i A', strtotime($sale['sale_date']))) ?></div>
      </div>
    </div>
  <?php else: ?>
    <div style="text-align:center;">
      <strong style="font-size:14px;"><?= e($sale['shop_name']) ?></strong><br>
      <?php if ($sale['shop_phone']): ?><?= e($sale['shop_phone']) ?><br><?php endif; ?>
      <?php if ($sale['shop_address']): ?><?= e($sale['shop_address']) ?><br><?php endif; ?>
      <?php if ($sale['receipt_header']): ?><?= nl2br(e($sale['receipt_header'])) ?><?php endif; ?>
    </div>
    <div class="divider"></div>
    <div><strong><?= e($sale['invoice_no']) ?></strong></div>
    <div><?= e(date('d M Y H:i', strtotime($sale['sale_date']))) ?></div>
    <div>Customer: <?= e($sale['cust_name'] ?? 'Walk-in') ?><?php if ($sale['cust_mobile']): ?> · <?= e($sale['cust_mobile']) ?><?php endif; ?></div>
  <?php endif; ?>

  <table>
    <thead><tr>
      <th>Item</th><th class="text-right">Qty</th>
      <?php if ($layout === 'a4'): ?><th class="text-right">Price</th><th class="text-right">Disc</th><?php endif; ?>
      <th class="text-right">Amount</th>
    </tr></thead>
    <tbody>
    <?php foreach ($items as $it): ?>
      <tr>
        <td><?= e($it['product_name']) ?><?php if ($layout !== 'a4' && $it['sku_snapshot']): ?><br><small><?= e($it['sku_snapshot']) ?></small><?php endif; ?></td>
        <td class="text-right"><?= rtrim(rtrim(number_format((float)$it['qty'], 3, '.', ''), '0'), '.') ?></td>
        <?php if ($layout === 'a4'): ?>
          <td class="text-right"><?= money($it['unit_price']) ?></td>
          <td class="text-right"><?= $it['discount'] > 0 ? '-' . money($it['discount']) : '—' ?></td>
        <?php endif; ?>
        <td class="text-right"><?= money($it['line_total']) ?></td>
      </tr>
    <?php endforeach; ?>
    </tbody>
  </table>

  <table class="totals">
    <tr><td class="muted">Subtotal</td><td class="text-right"><?= money($sale['subtotal']) ?></td></tr>
    <?php if ((float)$sale['discount']): ?>
      <tr><td class="muted">Discount</td><td class="text-right">- <?= money($sale['discount']) ?></td></tr>
    <?php endif; ?>
    <?php if ((float)$sale['tax']): ?>
      <tr><td class="muted">Tax</td><td class="text-right"><?= money($sale['tax']) ?></td></tr>
    <?php endif; ?>
    <tr class="grand"><td>GRAND TOTAL</td><td class="text-right"><?= money($sale['grand_total']) ?></td></tr>
  </table>

  <?php if ($payments): ?>
    <div class="divider"></div>
    <div class="muted" style="font-size:12px;">Payment history</div>
    <table>
      <tbody>
      <?php foreach ($payments as $p): ?>
        <tr>
          <td><?= e(date('d M', strtotime($p['paid_at']))) ?> · <?= e(ucfirst($p['method'])) ?><?php if ($p['reference']): ?> · <?= e($p['reference']) ?><?php endif; ?></td>
          <td class="text-right"><?= money($p['allocated_amount']) ?>
            <?php if ($p['status'] !== 'verified'): ?><span class="muted">(<?= e($p['status']) ?>)</span><?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  <?php endif; ?>

  <table class="totals">
    <tr>
      <td>Paid</td>
      <td class="text-right"><?= money($paid) ?></td>
    </tr>
    <?php if ($balance > 0): ?>
      <tr>
        <td class="grand">BALANCE DUE</td>
        <td class="text-right grand" style="color:#B91C1C;"><?= money($balance) ?></td>
      </tr>
    <?php endif; ?>
    <tr>
      <td colspan="2" class="text-right">
        <span class="status <?= e($sale['status']) ?>"><?= e($sale['status']) ?></span>
      </td>
    </tr>
  </table>

  <?php if ($layout === 'a4' && !empty($sale['upi_id']) && $balance > 0): ?>
    <div class="divider"></div>
    <div class="muted">Pay via UPI: <strong><?= e($sale['upi_id']) ?></strong></div>
  <?php endif; ?>

  <?php if ($sale['receipt_footer']): ?>
    <div class="foot-note"><?= nl2br(e($sale['receipt_footer'])) ?></div>
  <?php else: ?>
    <div class="foot-note">Thank you for your business!</div>
  <?php endif; ?>
</div>

</body></html>

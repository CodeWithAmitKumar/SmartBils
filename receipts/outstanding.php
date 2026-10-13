<?php
// Customer outstanding statement — all unpaid/partial invoices.
require_once __DIR__ . '/../config/app.php';
require_shop_user();
require_permission('bill.view');

$shopId = current_shop_id();
$cid = (int)($_GET['customer'] ?? 0);
if ($cid <= 0) { http_response_code(404); exit('Customer required.'); }

$c = db()->prepare('
  SELECT c.*, sh.name AS shop_name, sh.phone AS shop_phone, sh.address AS shop_address, sh.logo_path,
         ss.receipt_header, ss.receipt_footer, ss.upi_id
  FROM customers c
  JOIN shops sh ON sh.id = c.shop_id
  LEFT JOIN shop_settings ss ON ss.shop_id = c.shop_id
  WHERE c.id = :id AND c.shop_id = :sid');
$c->execute([':id' => $cid, ':sid' => $shopId]);
$row = $c->fetch();
if (!$row) { http_response_code(404); exit('Customer not found.'); }

$inv = db()->prepare("
    SELECT s.id, s.invoice_no, s.sale_date, s.grand_total,
      COALESCE((SELECT SUM(pa.allocated_amount)
                FROM payment_allocations pa
                JOIN payments p ON p.id = pa.payment_id
                WHERE pa.sale_id = s.id AND p.status = 'verified'), 0) AS paid
    FROM sales s
    WHERE s.shop_id = :sid AND s.customer_id = :cid AND s.status <> 'cancelled'
    ORDER BY s.sale_date ASC");
$inv->execute([':sid' => $shopId, ':cid' => $cid]);
$items = []; $totalBilled = 0; $totalPaid = 0;
foreach ($inv->fetchAll() as $r) {
    $out = max(0, (float)$r['grand_total'] - (float)$r['paid']);
    if ($out <= 0) continue;
    $items[] = $r + ['outstanding' => $out];
    $totalBilled += (float)$r['grand_total'];
    $totalPaid   += (float)$r['paid'];
}
$totalDue = $totalBilled - $totalPaid;

$layout  = $_GET['layout'] ?? 'a4';
$logoUrl = image_url($row['logo_path'] ?? null);
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<title>Statement · <?= e($row['name']) ?></title>
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
  .grand { font-size: 20px; font-weight: 700; border-top: 1px solid #0F172A; padding-top: 6px; }
  .ribbon { display:inline-block; padding:4px 12px; border-radius:999px; font-size:11px; font-weight:700;
            background:#FEE2E2; color:#B91C1C; letter-spacing:.08em; text-transform:uppercase; }
  @media print { body { background:#fff; padding:0; } .toolbar { display:none !important; }
                 .sheet { box-shadow:none; border-radius:0; margin:0; padding:10mm; }
                 .sheet.w80 { width: 80mm !important; } .sheet.w58 { width: 58mm !important; }
                 @page { margin: 6mm; } }
</style></head>
<body>

<?php
$msg = "Hi " . $row['name'] . ", your outstanding at " . $row['shop_name']
     . " is ₹" . number_format($totalDue, 2)
     . " across " . count($items) . " invoice(s). Please settle at your convenience."
     . ($row['upi_id'] ? ' Pay via UPI: ' . $row['upi_id'] : '');
include __DIR__ . '/_share-bar.php';
?>

<div class="toolbar">
  <a href="<?= url('client/customers/view.php?id=' . $cid) ?>">← Customer</a>
  <a href="?customer=<?= $cid ?>&layout=a4" class="<?= $layout==='a4'?'btn-primary':'' ?>">A4</a>
  <a href="?customer=<?= $cid ?>&layout=80" class="<?= $layout==='80'?'btn-primary':'' ?>">80mm</a>
  <a href="?customer=<?= $cid ?>&layout=58" class="<?= $layout==='58'?'btn-primary':'' ?>">58mm</a>
  <div class="spacer"></div>
  <button class="btn-primary" onclick="window.print()"><i class="bi bi-printer"></i> Print</button>
</div>

<div class="sheet <?= $layout === '80' ? 'w80' : ($layout === '58' ? 'w58' : '') ?>">
  <?php if ($layout === 'a4'): ?>
    <div class="brand">
      <div class="logo"><?php if ($logoUrl): ?><img src="<?= e($logoUrl) ?>" alt=""><?php else: ?>🧾<?php endif; ?></div>
      <div>
        <h1 class="shop-name"><?= e($row['shop_name']) ?></h1>
        <div class="muted"><?= e($row['shop_phone'] ?? '') ?></div>
        <div class="muted"><?= nl2br(e($row['shop_address'] ?? '')) ?></div>
      </div>
    </div>
    <div class="divider"></div>
    <div style="display:flex; justify-content:space-between;">
      <div>
        <span class="ribbon">Outstanding Statement</span>
        <h2 style="margin:12px 0 4px;"><?= e($row['name']) ?></h2>
        <?php if ($row['mobile']): ?><div class="muted"><?= e($row['mobile']) ?></div><?php endif; ?>
      </div>
      <div class="text-right">
        <div class="muted">As of</div>
        <strong><?= date('d M Y, h:i A') ?></strong>
      </div>
    </div>
  <?php else: ?>
    <div style="text-align:center;">
      <strong style="font-size:14px;"><?= e($row['shop_name']) ?></strong><br>
      <?php if ($row['shop_phone']): ?><?= e($row['shop_phone']) ?><br><?php endif; ?>
      <strong>OUTSTANDING STATEMENT</strong>
    </div>
    <div class="divider"></div>
    <div><strong><?= e($row['name']) ?></strong><?php if ($row['mobile']): ?> · <?= e($row['mobile']) ?><?php endif; ?></div>
    <div class="muted"><?= date('d M Y H:i') ?></div>
  <?php endif; ?>

  <?php if (!$items): ?>
    <div style="padding:24px; text-align:center; color:#047857;">
      <i class="bi bi-check2-circle" style="font-size:28px;"></i>
      <div style="margin-top:8px;">All invoices settled.</div>
    </div>
  <?php else: ?>
    <table>
      <thead><tr>
        <th>Invoice</th>
        <?php if ($layout === 'a4'): ?><th>Date</th><th class="text-right">Total</th><th class="text-right">Paid</th><?php endif; ?>
        <th class="text-right">Outstanding</th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $it): ?>
        <tr>
          <td><strong><?= e($it['invoice_no']) ?></strong></td>
          <?php if ($layout === 'a4'): ?>
            <td class="muted"><?= e(date('d M Y', strtotime($it['sale_date']))) ?></td>
            <td class="text-right"><?= money($it['grand_total']) ?></td>
            <td class="text-right"><?= money($it['paid']) ?></td>
          <?php endif; ?>
          <td class="text-right"><?= money($it['outstanding']) ?></td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>

    <table>
      <tr class="grand"><td>Total due</td><td class="text-right"><?= money($totalDue) ?></td></tr>
    </table>

    <?php if ($row['upi_id']): ?>
      <div class="muted" style="margin-top:12px;">Pay via UPI: <strong><?= e($row['upi_id']) ?></strong></div>
    <?php endif; ?>
  <?php endif; ?>

  <?php if ($row['receipt_footer']): ?>
    <div class="muted" style="margin-top:16px; text-align:center;"><?= nl2br(e($row['receipt_footer'])) ?></div>
  <?php endif; ?>
</div>

</body></html>

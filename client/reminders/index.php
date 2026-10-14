<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('due.collect');

$shopId = current_shop_id();

$filter = $_GET['filter'] ?? 'due';   // due | upcoming | all

$where = ["s.shop_id = :sid", "s.status IN ('unpaid','partial')"];
if ($filter === 'due')      $where[] = "s.due_date IS NOT NULL AND s.due_date <= CURDATE()";
if ($filter === 'upcoming') $where[] = "s.due_date IS NOT NULL AND s.due_date > CURDATE() AND s.due_date <= CURDATE() + INTERVAL 7 DAY";
if ($filter === 'all')      $where[] = "s.due_date IS NOT NULL";
$whereSql = 'WHERE ' . implode(' AND ', $where);

$rows = db()->prepare("
  SELECT s.id, s.invoice_no, s.sale_date, s.grand_total, s.status, s.due_date, s.due_note,
         c.id AS customer_id, c.name AS cust_name, c.mobile AS cust_mobile,
         COALESCE((SELECT SUM(pa.allocated_amount)
                   FROM payment_allocations pa
                   JOIN payments p ON p.id = pa.payment_id
                   WHERE pa.sale_id = s.id AND p.status = 'verified'), 0) AS paid,
         DATEDIFF(s.due_date, CURDATE()) AS days_until
  FROM sales s
  LEFT JOIN customers c ON c.id = s.customer_id
  $whereSql
  ORDER BY s.due_date ASC");
$rows->execute([':sid' => $shopId]);
$items = $rows->fetchAll();

// Shop name for the message
$sn = db()->prepare('SELECT name FROM shops WHERE id = :id');
$sn->execute([':id'=>$shopId]);
$shopName = $sn->fetchColumn() ?: 'our shop';

$dueCount = $upcomingCount = 0;
$totalDue = 0.0;
foreach ($items as $r) {
    $out = max(0, (float)$r['grand_total'] - (float)$r['paid']);
    if ((int)$r['days_until'] <= 0) { $dueCount++; $totalDue += $out; }
    elseif ((int)$r['days_until'] <= 7) $upcomingCount++;
}

$pageTitle = 'Reminders';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Payment Reminders</h1>
    <p class="sb-page-subtitle"><?= $dueCount ?> due today/overdue · <?= $upcomingCount ?> upcoming (7d) · <?= money($totalDue) ?> expected</p>
  </div>
</div>

<div class="alert alert-info small">
  <i class="bi bi-info-circle"></i>
  Click <strong>WhatsApp</strong> next to a customer to open WhatsApp with the reminder message pre-filled — tap <strong>Send</strong> on your phone.
  No cost, no API setup required.
</div>

<div class="btn-group mb-3" role="group">
  <?php foreach ([['due','Due today / overdue'], ['upcoming','Upcoming (7d)'], ['all','All with dates']] as [$f, $lbl]): ?>
    <a class="btn btn-outline-secondary <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= e($lbl) ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[4,"asc"]]' data-nosort="7">
      <thead><tr>
        <th>Customer</th><th>Invoice</th><th class="sb-money">Total</th><th class="sb-money">Paid</th>
        <th>Promised</th><th class="sb-money">Balance</th><th>Note</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($items as $r):
          $out = max(0, (float)$r['grand_total'] - (float)$r['paid']);
          $days = (int)$r['days_until'];
          $cls = $days < 0 ? 'text-danger fw-semibold' : ($days === 0 ? 'text-warning fw-semibold' : '');
          $when = $days < 0 ? abs($days) . 'd overdue' : ($days === 0 ? 'Today' : 'In ' . $days . 'd');

          $mobile = preg_replace('/\D+/', '', (string)$r['cust_mobile']);
          if ($mobile !== '' && strlen($mobile) === 10) $mobile = '91' . $mobile;
          $msg = "Hi " . ($r['cust_name'] ?: 'Customer') . ", a gentle reminder — balance of ₹"
               . number_format($out, 2) . " on invoice " . $r['invoice_no']
               . " from " . $shopName . " is due. Please settle at your convenience. Thanks!";
          $wa = $mobile ? 'https://wa.me/' . $mobile . '?text=' . rawurlencode($msg) : null;
          $sms = $mobile ? 'sms:' . $mobile . '?body=' . rawurlencode($msg) : null;
      ?>
        <tr>
          <td>
            <?php if ($r['customer_id']): ?>
              <a href="<?= url('client/customers/view.php?id=' . (int)$r['customer_id']) ?>"><strong><?= e($r['cust_name']) ?></strong></a>
            <?php else: ?><strong><?= e($r['cust_name'] ?? 'Walk-in') ?></strong><?php endif; ?>
            <?php if ($r['cust_mobile']): ?><div class="small text-muted"><?= e($r['cust_mobile']) ?></div><?php endif; ?>
          </td>
          <td><a href="<?= url('receipts/invoice.php?id=' . (int)$r['id']) ?>" target="_blank"><code><?= e($r['invoice_no']) ?></code></a></td>
          <td class="sb-money"><?= money($r['grand_total']) ?></td>
          <td class="sb-money"><?= money($r['paid']) ?></td>
          <td data-order="<?= e($r['due_date']) ?>">
            <div class="<?= $cls ?>"><?= e(date('d M Y', strtotime($r['due_date']))) ?></div>
            <div class="small text-muted"><?= $when ?></div>
          </td>
          <td class="sb-money fw-semibold text-danger"><?= money($out) ?></td>
          <td class="small text-muted"><?= e($r['due_note'] ?? '—') ?></td>
          <td class="text-end text-nowrap">
            <?php if ($wa): ?>
              <a target="_blank" rel="noopener" href="<?= e($wa) ?>" class="btn btn-sm" style="background:#25D366;color:#fff;">
                <i class="bi bi-whatsapp"></i> WhatsApp
              </a>
            <?php endif; ?>
            <?php if ($sms): ?>
              <a href="<?= e($sms) ?>" class="btn btn-sm btn-outline-primary"><i class="bi bi-chat-left-text"></i></a>
            <?php endif; ?>
            <a href="<?= url('client/dues/') ?>" class="btn btn-sm btn-outline-secondary">Collect</a>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

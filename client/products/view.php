<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); exit('Product not found.'); }

$p = db()->prepare('
    SELECT p.*, c.name AS category_name
    FROM products p LEFT JOIN categories c ON c.id = p.category_id
    WHERE p.id = :id AND p.shop_id = :sid');
$p->execute([':id' => $id, ':sid' => $shopId]);
$product = $p->fetch();
if (!$product) { http_response_code(404); exit('Product not found.'); }

$m = db()->prepare('
    SELECT m.*,
           CASE m.ref_type
             WHEN "shop_user" THEN (SELECT name FROM shop_users WHERE id = m.created_by)
             ELSE (SELECT name FROM shop_users WHERE id = m.created_by)
           END AS actor
    FROM stock_movements m
    WHERE m.product_id = :pid
    ORDER BY m.id DESC LIMIT 100');
$m->execute([':pid' => $id]);
$movements = $m->fetchAll();

$isLow = $product['min_stock_alert'] > 0 && $product['current_stock'] <= $product['min_stock_alert'];
$isOut = $product['current_stock'] <= 0;

$pageTitle = $product['name'];
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title"><?= e($product['name']) ?></h1>
    <p class="sb-page-subtitle">
      <?= e($product['category_name'] ?? 'Uncategorised') ?>
      <?php if ($product['sku']): ?> · <code><?= e($product['sku']) ?></code><?php endif; ?>
      · <?= $product['is_available'] ? '<span class="badge-pill badge-paid">Available</span>' : '<span class="badge-pill badge-unpaid">Off</span>' ?>
    </p>
  </div>
  <div class="d-flex gap-2">
    <?php if (has_permission('product.manage')): ?>
      <a href="<?= url('client/products/form.php?id=' . $id) ?>" class="btn btn-outline-primary"><i class="bi bi-pencil me-1"></i>Edit</a>
    <?php endif; ?>
    <a href="<?= url('client/products/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
  </div>
</div>

<div class="row g-3">
  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header">Details</div>
      <div class="card-body">
        <dl class="row mb-0">
          <dt class="col-sm-4 text-muted">Barcode</dt>  <dd class="col-sm-8"><?= e($product['barcode'] ?? '—') ?></dd>
          <dt class="col-sm-4 text-muted">Unit</dt>     <dd class="col-sm-8"><?= e($product['unit']) ?></dd>
          <dt class="col-sm-4 text-muted">Cost</dt>     <dd class="col-sm-8"><?= money($product['purchase_cost']) ?></dd>
          <dt class="col-sm-4 text-muted">Price</dt>    <dd class="col-sm-8"><?= money($product['selling_price']) ?></dd>
          <dt class="col-sm-4 text-muted">Low alert at</dt><dd class="col-sm-8"><?= rtrim(rtrim(number_format((float)$product['min_stock_alert'], 3, '.', ''), '0'), '.') ?> <?= e($product['unit']) ?></dd>
          <dt class="col-sm-4 text-muted">Notes</dt>    <dd class="col-sm-8"><?= nl2br(e($product['notes'] ?? '—')) ?></dd>
        </dl>
      </div>
    </div>
  </div>

  <div class="col-lg-6">
    <div class="card h-100">
      <div class="card-header d-flex justify-content-between align-items-center">
        <span>Stock</span>
        <?php if ($isOut): ?><span class="badge-pill badge-unpaid">Out of stock</span>
        <?php elseif ($isLow): ?><span class="badge-pill badge-partial">Low stock</span><?php endif; ?>
      </div>
      <div class="card-body">
        <div class="d-flex align-items-baseline gap-2 mb-3">
          <span style="font-size:36px;font-weight:700;line-height:1;"><?= rtrim(rtrim(number_format((float)$product['current_stock'], 3, '.', ''), '0'), '.') ?></span>
          <span class="text-muted"><?= e($product['unit']) ?></span>
        </div>
        <?php if (has_permission('stock.adjust')): ?>
          <form id="adjust" method="post" action="<?= url('client/products/action.php') ?>" class="js-once row g-2 align-items-end">
            <?= csrf_field() ?>
            <input type="hidden" name="op" value="adjust">
            <input type="hidden" name="product_id" value="<?= $id ?>">
            <div class="col-md-4">
              <label class="form-label">Change by</label>
              <input class="form-control" type="number" step="0.001" name="delta" placeholder="e.g. 5 or -2" required>
              <div class="form-text">Use negative to reduce.</div>
            </div>
            <div class="col-md-6">
              <label class="form-label">Reason *</label>
              <input class="form-control" name="reason" required placeholder="damage, found, recount…">
            </div>
            <div class="col-md-2">
              <button class="btn btn-primary w-100"><i class="bi bi-sliders me-1"></i>Adjust</button>
            </div>
          </form>
        <?php else: ?>
          <p class="text-muted mb-0">You don't have the <code>stock.adjust</code> permission.</p>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <div class="col-12">
    <div class="card">
      <div class="card-header">Movement history <span class="text-muted small">(latest 100)</span></div>
      <div class="table-responsive">
        <table class="table sb-datatable" data-order='[[0,"desc"]]'>
          <thead><tr><th>When</th><th>Type</th><th>Change</th><th>Note</th><th>Ref</th></tr></thead>
          <tbody>
          <?php foreach ($movements as $r):
              $delta = (float)$r['qty_change'];
              $cls = $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : '');
              $sign = $delta > 0 ? '+' : '';
          ?>
            <tr>
              <td data-order="<?= e($r['created_at']) ?>"><?= e(date('d M Y H:i', strtotime($r['created_at']))) ?></td>
              <td><span class="badge-pill badge-pending"><?= e($r['type']) ?></span></td>
              <td class="<?= $cls ?>" data-order="<?= $delta ?>"><?= $sign . rtrim(rtrim(number_format($delta, 3, '.', ''), '0'), '.') ?></td>
              <td><?= e($r['note'] ?? '') ?></td>
              <td class="text-muted"><?= e($r['ref_type'] ?? '—') ?><?= $r['ref_id'] ? '#' . (int)$r['ref_id'] : '' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    </div>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('product.manage');

$shopId = current_shop_id();
$id   = (int)($_GET['id'] ?? 0);
$edit = $id > 0;
$product = null;

if ($edit) {
    $s = db()->prepare('SELECT * FROM products WHERE id = :id AND shop_id = :sid');
    $s->execute([':id'=>$id, ':sid'=>$shopId]);
    $product = $s->fetch();
    if (!$product) { http_response_code(404); exit('Product not found.'); }
}

$cats = db()->prepare('SELECT id, name FROM categories WHERE shop_id = :sid AND is_active = 1 ORDER BY name');
$cats->execute([':sid'=>$shopId]);
$cats = $cats->fetchAll();

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $name       = trim((string)($_POST['name'] ?? ''));
    $sku        = trim((string)($_POST['sku'] ?? '')) ?: null;
    $barcode    = trim((string)($_POST['barcode'] ?? '')) ?: null;
    $catId      = (int)($_POST['category_id'] ?? 0) ?: null;
    $unit       = trim((string)($_POST['unit'] ?? 'pcs')) ?: 'pcs';
    $cost       = max(0, (float)($_POST['purchase_cost'] ?? 0));
    $price      = max(0, (float)($_POST['selling_price'] ?? 0));
    $minAlert   = max(0, (float)($_POST['min_stock_alert'] ?? 0));
    $available  = !empty($_POST['is_available']) ? 1 : 0;
    $notes      = trim((string)($_POST['notes'] ?? '')) ?: null;
    $openingQty = (float)($_POST['opening_stock'] ?? 0);

    if ($name === '') $errors[] = 'Name is required.';
    if ($catId) {
        $v = db()->prepare('SELECT 1 FROM categories WHERE id = :id AND shop_id = :sid');
        $v->execute([':id'=>$catId, ':sid'=>$shopId]);
        if (!$v->fetchColumn()) { $errors[] = 'Invalid category.'; $catId = null; }
    }
    if (!$errors && $sku) {
        $d = db()->prepare('SELECT 1 FROM products WHERE shop_id = :sid AND sku = :sku AND id <> :id');
        $d->execute([':sid'=>$shopId, ':sku'=>$sku, ':id'=>$edit ? $id : 0]);
        if ($d->fetchColumn()) $errors[] = 'SKU already in use in this shop.';
    }

    if (!$errors) {
        try {
            db()->beginTransaction();

            if ($edit) {
                db()->prepare(
                    'UPDATE products SET category_id=:cid, name=:n, sku=:sku, barcode=:bc, unit=:u,
                      purchase_cost=:pc, selling_price=:sp, min_stock_alert=:ma,
                      is_available=:ia, notes=:no
                     WHERE id=:id AND shop_id=:sid'
                )->execute([
                    ':cid'=>$catId, ':n'=>$name, ':sku'=>$sku, ':bc'=>$barcode, ':u'=>$unit,
                    ':pc'=>$cost, ':sp'=>$price, ':ma'=>$minAlert,
                    ':ia'=>$available, ':no'=>$notes, ':id'=>$id, ':sid'=>$shopId,
                ]);
                $productId = $id;
            } else {
                db()->prepare(
                    'INSERT INTO products (shop_id, category_id, name, sku, barcode, unit,
                      purchase_cost, selling_price, current_stock, min_stock_alert, is_available, notes)
                     VALUES (:sid, :cid, :n, :sku, :bc, :u, :pc, :sp, 0, :ma, :ia, :no)'
                )->execute([
                    ':sid'=>$shopId, ':cid'=>$catId, ':n'=>$name, ':sku'=>$sku, ':bc'=>$barcode, ':u'=>$unit,
                    ':pc'=>$cost, ':sp'=>$price, ':ma'=>$minAlert, ':ia'=>$available, ':no'=>$notes,
                ]);
                $productId = (int)db()->lastInsertId();

                // Opening stock as a movement (if entered)
                if ($openingQty > 0) {
                    db()->prepare(
                        "INSERT INTO stock_movements (shop_id, product_id, type, qty_change, note, created_by)
                         VALUES (:sid, :pid, 'opening', :q, 'Opening stock', :by)"
                    )->execute([':sid'=>$shopId, ':pid'=>$productId, ':q'=>$openingQty, ':by'=>(int)current_user()['id']]);
                    db()->prepare('UPDATE products SET current_stock = current_stock + :q WHERE id = :id')
                        ->execute([':q'=>$openingQty, ':id'=>$productId]);
                }
            }

            db()->commit();
            audit($edit ? 'product.update' : 'product.create', 'product', $productId, ['name'=>$name], $shopId);
            flash('success', $edit ? 'Product updated.' : 'Product created.');
            redirect('client/products/view.php?id=' . $productId);
        } catch (Throwable $e) {
            if (db()->inTransaction()) db()->rollBack();
            $errors[] = 'Save failed: ' . $e->getMessage();
        }
    }
}

$v = fn(string $k, $d = '') => e($_POST[$k] ?? ($product[$k] ?? $d));

$pageTitle = $edit ? 'Edit Product' : 'Add Product';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title"><?= $edit ? 'Edit Product' : 'Add Product' ?></h1>
    <p class="sb-page-subtitle"><?= $edit ? 'Update details — stock changes happen in Stock Adjust.' : 'Create a new product. Opening stock is optional.' ?></p>
  </div>
  <a href="<?= url('client/products/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?>
  <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<form method="post" class="js-once">
  <?= csrf_field() ?>
  <div class="card mb-3">
    <div class="card-header">Details</div>
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label">Name *</label>
        <input class="form-control" name="name" value="<?= $v('name') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">SKU</label>
        <input class="form-control" name="sku" value="<?= $v('sku') ?>" placeholder="SKU/code">
      </div>
      <div class="col-md-3">
        <label class="form-label">Barcode</label>
        <input class="form-control" name="barcode" value="<?= $v('barcode') ?>">
      </div>
      <div class="col-md-4">
        <label class="form-label">Category</label>
        <select class="form-select" name="category_id">
          <option value="">— None —</option>
          <?php $curCat = $_POST['category_id'] ?? ($product['category_id'] ?? ''); ?>
          <?php foreach ($cats as $c): ?>
            <option value="<?= (int)$c['id'] ?>" <?= (int)$curCat === (int)$c['id'] ? 'selected' : '' ?>><?= e($c['name']) ?></option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="col-md-2">
        <label class="form-label">Unit</label>
        <input class="form-control" name="unit" value="<?= $v('unit', 'pcs') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Purchase cost (<?= APP_CURRENCY_SYMBOL ?>)</label>
        <input class="form-control" type="number" step="0.01" min="0" name="purchase_cost" value="<?= $v('purchase_cost', '0') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Selling price (<?= APP_CURRENCY_SYMBOL ?>)</label>
        <input class="form-control" type="number" step="0.01" min="0" name="selling_price" value="<?= $v('selling_price', '0') ?>">
      </div>
    </div>
  </div>

  <div class="card mb-3">
    <div class="card-header">Stock</div>
    <div class="card-body row g-3">
      <?php if (!$edit): ?>
        <div class="col-md-3">
          <label class="form-label">Opening stock</label>
          <input class="form-control" type="number" step="0.001" min="0" name="opening_stock" value="<?= e($_POST['opening_stock'] ?? '0') ?>">
          <div class="form-text">Recorded as a movement.</div>
        </div>
      <?php else: ?>
        <div class="col-md-3">
          <label class="form-label">Current stock</label>
          <input class="form-control" value="<?= e($product['current_stock']) ?> <?= e($product['unit']) ?>" disabled>
          <div class="form-text"><a href="<?= url('client/products/view.php?id=' . $id) ?>#adjust">Adjust from product page →</a></div>
        </div>
      <?php endif; ?>
      <div class="col-md-3">
        <label class="form-label">Low-stock alert at</label>
        <input class="form-control" type="number" step="0.001" min="0" name="min_stock_alert" value="<?= $v('min_stock_alert', '0') ?>">
        <div class="form-text">0 = disabled.</div>
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="is_available" name="is_available" value="1"
                 <?= (!$edit || $product['is_available']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_available">Available for sale</label>
        </div>
      </div>
      <div class="col-12">
        <label class="form-label">Notes</label>
        <input class="form-control" name="notes" value="<?= $v('notes') ?>" maxlength="255">
      </div>
    </div>
  </div>

  <div class="d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $edit ? 'Save changes' : 'Create product' ?></button>
    <a href="<?= url($edit ? 'client/products/view.php?id=' . $id : 'client/products/') ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

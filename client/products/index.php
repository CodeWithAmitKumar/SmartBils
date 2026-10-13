<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('bill.view'); // viewing products is read-only; bill.view covers most roles. Owner always ok.

$shopId = current_shop_id();

$filter = $_GET['filter'] ?? '';
$where = ['p.shop_id = :sid'];
$args  = [':sid' => $shopId];
if ($filter === 'low')      $where[] = 'p.current_stock <= p.min_stock_alert AND p.min_stock_alert > 0';
if ($filter === 'out')      $where[] = 'p.current_stock <= 0';
if ($filter === 'off')      $where[] = 'p.is_available = 0';
$whereSql = 'WHERE ' . implode(' AND ', $where);

$products = db()->prepare("
    SELECT p.*, c.name AS category_name
    FROM products p
    LEFT JOIN categories c ON c.id = p.category_id
    $whereSql
    ORDER BY p.updated_at DESC");
$products->execute($args);
$products = $products->fetchAll();

$pageTitle = 'Products';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Products</h1>
    <p class="sb-page-subtitle"><?= count($products) ?> item<?= count($products) === 1 ? '' : 's' ?></p>
  </div>
  <?php if (has_permission('product.manage')): ?>
    <a href="<?= url('client/products/form.php') ?>" class="btn btn-primary">
      <i class="bi bi-plus-lg me-1"></i>Add product
    </a>
  <?php endif; ?>
</div>

<div class="btn-group mb-3" role="group">
  <?php foreach ([['','All'],['low','Low stock'],['out','Out of stock'],['off','Unavailable']] as [$f,$lbl]): ?>
    <a class="btn btn-outline-secondary <?= $filter === $f ? 'active' : '' ?>" href="?filter=<?= $f ?>"><?= $lbl ?></a>
  <?php endforeach; ?>
</div>

<div class="card">
  <div class="table-responsive">
    <table class="table sb-datatable" data-nosort="7">
      <thead><tr>
        <th>Name</th><th>SKU</th><th>Category</th>
        <th class="sb-money">Cost</th><th class="sb-money">Price</th>
        <th>Stock</th><th>Status</th><th></th>
      </tr></thead>
      <tbody>
      <?php foreach ($products as $p):
          $isLow = $p['min_stock_alert'] > 0 && $p['current_stock'] <= $p['min_stock_alert'];
          $isOut = $p['current_stock'] <= 0;
      ?>
        <tr>
          <td>
            <strong><?= e($p['name']) ?></strong>
            <?php if ($p['barcode']): ?><div class="small text-muted">⎯ <?= e($p['barcode']) ?></div><?php endif; ?>
          </td>
          <td><?= $p['sku'] ? '<code>' . e($p['sku']) . '</code>' : '—' ?></td>
          <td><?= e($p['category_name'] ?? '—') ?></td>
          <td class="sb-money" data-order="<?= (float)$p['purchase_cost'] ?>"><?= money($p['purchase_cost']) ?></td>
          <td class="sb-money" data-order="<?= (float)$p['selling_price'] ?>"><?= money($p['selling_price']) ?></td>
          <td data-order="<?= (float)$p['current_stock'] ?>">
            <?= rtrim(rtrim(number_format((float)$p['current_stock'], 3, '.', ''), '0'), '.') ?> <?= e($p['unit']) ?>
            <?php if ($isOut): ?><span class="badge-pill badge-unpaid ms-1">Out</span>
            <?php elseif ($isLow): ?><span class="badge-pill badge-partial ms-1">Low</span><?php endif; ?>
          </td>
          <td data-order="<?= (int)$p['is_available'] ?>">
            <?= $p['is_available'] ? '<span class="badge-pill badge-paid">Available</span>' : '<span class="badge-pill badge-unpaid">Off</span>' ?>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-primary" href="<?= url('client/products/view.php?id=' . (int)$p['id']) ?>">View</a>
            <?php if (has_permission('product.manage')): ?>
              <a class="btn btn-sm btn-outline-secondary" href="<?= url('client/products/form.php?id=' . (int)$p['id']) ?>">Edit</a>
            <?php endif; ?>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

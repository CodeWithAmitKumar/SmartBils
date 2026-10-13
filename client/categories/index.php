<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('product.manage');

$shopId = current_shop_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $op = $_POST['op'] ?? '';
    try {
        if ($op === 'save') {
            $id   = (int)($_POST['id'] ?? 0);
            $name = trim((string)($_POST['name'] ?? ''));
            if ($name === '') throw new RuntimeException('Name is required.');
            if ($id > 0) {
                db()->prepare('UPDATE categories SET name = :n WHERE id = :id AND shop_id = :sid')
                    ->execute([':n'=>$name, ':id'=>$id, ':sid'=>$shopId]);
                audit('category.update', 'category', $id, ['name'=>$name], $shopId);
                flash('success', 'Category updated.');
            } else {
                db()->prepare('INSERT INTO categories (shop_id, name) VALUES (:sid, :n)')
                    ->execute([':sid'=>$shopId, ':n'=>$name]);
                audit('category.create', 'category', (int)db()->lastInsertId(), ['name'=>$name], $shopId);
                flash('success', 'Category added.');
            }
        } elseif ($op === 'toggle') {
            db()->prepare('UPDATE categories SET is_active = 1 - is_active WHERE id = :id AND shop_id = :sid')
                ->execute([':id' => (int)$_POST['id'], ':sid' => $shopId]);
            audit('category.toggle', 'category', (int)$_POST['id'], null, $shopId);
            flash('success', 'Category toggled.');
        } elseif ($op === 'delete') {
            $id = (int)$_POST['id'];
            $used = db()->prepare('SELECT COUNT(*) FROM products WHERE category_id = :id');
            $used->execute([':id'=>$id]);
            if ((int)$used->fetchColumn() > 0) {
                throw new RuntimeException('Category is used by products — reassign them first.');
            }
            db()->prepare('DELETE FROM categories WHERE id = :id AND shop_id = :sid')
                ->execute([':id'=>$id, ':sid'=>$shopId]);
            audit('category.delete', 'category', $id, null, $shopId);
            flash('success', 'Category deleted.');
        }
    } catch (Throwable $e) { flash('danger', $e->getMessage()); }
    redirect('client/categories/');
}

$cats = db()->prepare("
    SELECT c.*, (SELECT COUNT(*) FROM products p WHERE p.category_id = c.id) AS usage_count
    FROM categories c WHERE c.shop_id = :sid ORDER BY c.is_active DESC, c.name ASC");
$cats->execute([':sid'=>$shopId]);
$cats = $cats->fetchAll();

$edit = null;
if (!empty($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM categories WHERE id = :id AND shop_id = :sid');
    $s->execute([':id' => (int)$_GET['edit'], ':sid' => $shopId]);
    $edit = $s->fetch();
}

$pageTitle = 'Categories';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Categories</h1>
    <p class="sb-page-subtitle">Organise your products.</p>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><?= $edit ? 'Edit category' : 'Add category' ?></div>
  <div class="card-body">
    <form method="post" class="js-once row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="col-md-8">
        <label class="form-label">Name</label>
        <input class="form-control" name="name" value="<?= e($edit['name'] ?? '') ?>" required>
      </div>
      <div class="col-md-4 d-flex gap-2">
        <button class="btn btn-primary flex-fill"><?= $edit ? 'Save changes' : 'Add' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= url('client/categories/') ?>">Cancel</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">All categories</div>
  <div class="table-responsive">
    <table class="table sb-datatable" data-nosort="3">
      <thead><tr><th>Name</th><th>Products</th><th>Status</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($cats as $c): ?>
        <tr>
          <td><strong><?= e($c['name']) ?></strong></td>
          <td data-order="<?= (int)$c['usage_count'] ?>"><?= (int)$c['usage_count'] ?></td>
          <td data-order="<?= (int)$c['is_active'] ?>">
            <?= $c['is_active'] ? '<span class="badge-pill badge-paid">Active</span>' : '<span class="badge-pill badge-unpaid">Inactive</span>' ?>
          </td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$c['id'] ?>">Edit</a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="op" value="toggle"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-outline-primary"><?= $c['is_active'] ? 'Deactivate' : 'Activate' ?></button>
            </form>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$c['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" data-confirm="Delete category '<?= e($c['name']) ?>'?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

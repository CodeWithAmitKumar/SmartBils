<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('expense.manage');

$shopId = current_shop_id();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $op = $_POST['op'] ?? '';
    try {
        if ($op === 'save') {
            $id       = (int)($_POST['id'] ?? 0);
            $category = trim((string)($_POST['category'] ?? ''));
            $desc     = trim((string)($_POST['description'] ?? '')) ?: null;
            $amount   = max(0, (float)($_POST['amount'] ?? 0));
            $date     = $_POST['expense_date'] ?? date('Y-m-d');
            if ($category === '') throw new RuntimeException('Category is required.');
            if ($amount <= 0)     throw new RuntimeException('Amount must be positive.');
            if (!DateTime::createFromFormat('Y-m-d', $date)) throw new RuntimeException('Invalid date.');

            if ($id > 0) {
                db()->prepare('UPDATE expenses SET category=:c, description=:d, amount=:a, expense_date=:dt
                               WHERE id=:id AND shop_id=:sid')
                    ->execute([':c'=>$category, ':d'=>$desc, ':a'=>$amount, ':dt'=>$date, ':id'=>$id, ':sid'=>$shopId]);
                audit('expense.update', 'expense', $id, null, $shopId);
                flash('success', 'Expense updated.');
            } else {
                db()->prepare('INSERT INTO expenses (shop_id, category, description, amount, expense_date, created_by)
                               VALUES (:sid, :c, :d, :a, :dt, :by)')
                    ->execute([':sid'=>$shopId, ':c'=>$category, ':d'=>$desc, ':a'=>$amount, ':dt'=>$date,
                               ':by'=>(int)current_user()['id']]);
                audit('expense.create', 'expense', (int)db()->lastInsertId(), null, $shopId);
                flash('success', 'Expense added.');
            }
        } elseif ($op === 'delete') {
            db()->prepare('DELETE FROM expenses WHERE id = :id AND shop_id = :sid')
                ->execute([':id' => (int)$_POST['id'], ':sid' => $shopId]);
            audit('expense.delete', 'expense', (int)$_POST['id'], null, $shopId);
            flash('success', 'Expense deleted.');
        }
    } catch (Throwable $e) { flash('danger', $e->getMessage()); }
    redirect('client/expenses/');
}

$rows = db()->prepare('
  SELECT e.*, u.name AS actor
  FROM expenses e LEFT JOIN shop_users u ON u.id = e.created_by
  WHERE e.shop_id = :sid ORDER BY e.expense_date DESC, e.id DESC');
$rows->execute([':sid' => $shopId]);
$expenses = $rows->fetchAll();

$edit = null;
if (!empty($_GET['edit'])) {
    $s = db()->prepare('SELECT * FROM expenses WHERE id = :id AND shop_id = :sid');
    $s->execute([':id' => (int)$_GET['edit'], ':sid' => $shopId]);
    $edit = $s->fetch();
}

$mt = db()->prepare("SELECT COALESCE(SUM(amount),0) FROM expenses
                     WHERE shop_id = :sid AND YEAR(expense_date) = YEAR(CURDATE()) AND MONTH(expense_date) = MONTH(CURDATE())");
$mt->execute([':sid'=>$shopId]);
$monthTotal = (float)$mt->fetchColumn();

$pageTitle = 'Expenses';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div>
    <h1 class="sb-page-title">Expenses</h1>
    <p class="sb-page-subtitle">This month: <strong><?= money($monthTotal) ?></strong></p>
  </div>
</div>

<div class="card mb-3">
  <div class="card-header"><?= $edit ? 'Edit expense' : 'Add expense' ?></div>
  <div class="card-body">
    <form method="post" class="js-once row g-2 align-items-end">
      <?= csrf_field() ?>
      <input type="hidden" name="op" value="save">
      <input type="hidden" name="id" value="<?= (int)($edit['id'] ?? 0) ?>">
      <div class="col-md-3">
        <label class="form-label">Category *</label>
        <input class="form-control" name="category" list="catList" value="<?= e($edit['category'] ?? '') ?>" required>
        <datalist id="catList">
          <option>Rent</option><option>Salary</option><option>Electricity</option>
          <option>Transport</option><option>Supplies</option><option>Internet</option>
          <option>Maintenance</option><option>Other</option>
        </datalist>
      </div>
      <div class="col-md-4">
        <label class="form-label">Description</label>
        <input class="form-control" name="description" value="<?= e($edit['description'] ?? '') ?>">
      </div>
      <div class="col-md-2">
        <label class="form-label">Amount *</label>
        <input class="form-control" type="number" step="0.01" min="0" name="amount" value="<?= e($edit['amount'] ?? '0') ?>" required>
      </div>
      <div class="col-md-2">
        <label class="form-label">Date *</label>
        <input class="form-control" type="date" name="expense_date" value="<?= e($edit['expense_date'] ?? date('Y-m-d')) ?>" required>
      </div>
      <div class="col-md-1 d-flex gap-1">
        <button class="btn btn-primary flex-fill"><?= $edit ? 'Save' : 'Add' ?></button>
        <?php if ($edit): ?><a class="btn btn-outline-secondary" href="<?= url('client/expenses/') ?>">×</a><?php endif; ?>
      </div>
    </form>
  </div>
</div>

<div class="card">
  <div class="card-header">All expenses</div>
  <div class="table-responsive">
    <table class="table sb-datatable" data-order='[[1,"desc"]]' data-nosort="5">
      <thead><tr><th>#</th><th>Date</th><th>Category</th><th>Description</th><th class="sb-money">Amount</th><th></th></tr></thead>
      <tbody>
      <?php foreach ($expenses as $x): ?>
        <tr>
          <td>#<?= (int)$x['id'] ?></td>
          <td data-order="<?= e($x['expense_date']) ?>"><?= e(date('d M Y', strtotime($x['expense_date']))) ?></td>
          <td><strong><?= e($x['category']) ?></strong></td>
          <td><?= e($x['description'] ?? '—') ?></td>
          <td class="sb-money" data-order="<?= (float)$x['amount'] ?>"><?= money($x['amount']) ?></td>
          <td class="text-end text-nowrap">
            <a class="btn btn-sm btn-outline-secondary" href="?edit=<?= (int)$x['id'] ?>">Edit</a>
            <form method="post" class="d-inline">
              <?= csrf_field() ?><input type="hidden" name="op" value="delete"><input type="hidden" name="id" value="<?= (int)$x['id'] ?>">
              <button class="btn btn-sm btn-outline-danger" data-confirm="Delete this expense?">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

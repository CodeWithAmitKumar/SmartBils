<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('customer.manage');

$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
$edit = $id > 0;
$row = null;

if ($edit) {
    $s = db()->prepare('SELECT * FROM customers WHERE id = :id AND shop_id = :sid');
    $s->execute([':id'=>$id, ':sid'=>$shopId]);
    $row = $s->fetch();
    if (!$row) { http_response_code(404); exit('Customer not found.'); }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $name    = trim((string)($_POST['name'] ?? ''));
    $mobile  = trim((string)($_POST['mobile'] ?? '')) ?: null;
    $email   = trim((string)($_POST['email'] ?? '')) ?: null;
    $address = trim((string)($_POST['address'] ?? '')) ?: null;
    $notes   = trim((string)($_POST['notes'] ?? '')) ?: null;
    $active  = !empty($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';
    if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

    if (!$errors) {
        if ($edit) {
            db()->prepare('UPDATE customers SET name=:n, mobile=:m, email=:e, address=:a, notes=:nt, is_active=:ia WHERE id=:id AND shop_id=:sid')
                ->execute([':n'=>$name, ':m'=>$mobile, ':e'=>$email, ':a'=>$address, ':nt'=>$notes, ':ia'=>$active, ':id'=>$id, ':sid'=>$shopId]);
            audit('customer.update', 'customer', $id, null, $shopId);
            flash('success', 'Customer updated.');
            redirect('client/customers/view.php?id=' . $id);
        } else {
            db()->prepare('INSERT INTO customers (shop_id, name, mobile, email, address, notes, is_active) VALUES (:sid,:n,:m,:e,:a,:nt,:ia)')
                ->execute([':sid'=>$shopId, ':n'=>$name, ':m'=>$mobile, ':e'=>$email, ':a'=>$address, ':nt'=>$notes, ':ia'=>$active]);
            $nid = (int)db()->lastInsertId();
            audit('customer.create', 'customer', $nid, null, $shopId);
            flash('success', 'Customer created.');
            redirect('client/customers/view.php?id=' . $nid);
        }
    }
}

$v = fn(string $k, $d='') => e($_POST[$k] ?? ($row[$k] ?? $d));

$pageTitle = $edit ? 'Edit Customer' : 'Add Customer';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div><h1 class="sb-page-title"><?= $edit ? 'Edit Customer' : 'Add Customer' ?></h1></div>
  <a href="<?= url('client/customers/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" class="js-once">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-body row g-3">
      <div class="col-md-5"><label class="form-label">Name *</label>
        <input class="form-control" name="name" value="<?= $v('name') ?>" required></div>
      <div class="col-md-4"><label class="form-label">Mobile</label>
        <input class="form-control" name="mobile" value="<?= $v('mobile') ?>"></div>
      <div class="col-md-3"><label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" value="<?= $v('email') ?>"></div>
      <div class="col-12"><label class="form-label">Address</label>
        <textarea class="form-control" rows="2" name="address"><?= $v('address') ?></textarea></div>
      <div class="col-md-9"><label class="form-label">Notes</label>
        <input class="form-control" name="notes" value="<?= $v('notes') ?>" maxlength="500"></div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                 <?= (!$edit || $row['is_active']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_active">Active</label>
        </div>
      </div>
    </div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $edit ? 'Save changes' : 'Create customer' ?></button>
    <a href="<?= url($edit ? 'client/customers/view.php?id=' . $id : 'client/customers/') ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

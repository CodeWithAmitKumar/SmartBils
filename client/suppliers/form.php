<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('supplier.manage');

$shopId = current_shop_id();
$id   = (int)($_GET['id'] ?? 0);
$edit = $id > 0;
$sup  = null;

if ($edit) {
    $s = db()->prepare('SELECT * FROM suppliers WHERE id = :id AND shop_id = :sid');
    $s->execute([':id'=>$id, ':sid'=>$shopId]);
    $sup = $s->fetch();
    if (!$sup) { http_response_code(404); exit('Supplier not found.'); }
}

$errors = [];
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require();
    $name    = trim((string)($_POST['name'] ?? ''));
    $phone   = trim((string)($_POST['phone'] ?? ''));
    $email   = trim((string)($_POST['email'] ?? ''));
    $address = trim((string)($_POST['address'] ?? ''));
    $biz     = trim((string)($_POST['business_details'] ?? ''));
    $active  = !empty($_POST['is_active']) ? 1 : 0;

    if ($name === '') $errors[] = 'Name is required.';
    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = 'Invalid email.';

    if (!$errors) {
        if ($edit) {
            db()->prepare('UPDATE suppliers SET name=:n, phone=:p, email=:e, address=:a, business_details=:b, is_active=:ia
                           WHERE id=:id AND shop_id=:sid')
                ->execute([':n'=>$name, ':p'=>$phone, ':e'=>$email ?: null, ':a'=>$address, ':b'=>$biz, ':ia'=>$active, ':id'=>$id, ':sid'=>$shopId]);
            audit('supplier.update', 'supplier', $id, ['name'=>$name], $shopId);
            flash('success', 'Supplier updated.');
            redirect('client/suppliers/view.php?id=' . $id);
        } else {
            db()->prepare('INSERT INTO suppliers (shop_id, name, phone, email, address, business_details, is_active)
                           VALUES (:sid, :n, :p, :e, :a, :b, :ia)')
                ->execute([':sid'=>$shopId, ':n'=>$name, ':p'=>$phone, ':e'=>$email ?: null, ':a'=>$address, ':b'=>$biz, ':ia'=>$active]);
            $nid = (int)db()->lastInsertId();
            audit('supplier.create', 'supplier', $nid, ['name'=>$name], $shopId);
            flash('success', 'Supplier created.');
            redirect('client/suppliers/view.php?id=' . $nid);
        }
    }
}

$v = fn(string $k, $d = '') => e($_POST[$k] ?? ($sup[$k] ?? $d));

$pageTitle = $edit ? 'Edit Supplier' : 'Add Supplier';
require __DIR__ . '/../../includes/layout-top.php';
?>
<div class="sb-page-header">
  <div><h1 class="sb-page-title"><?= $edit ? 'Edit Supplier' : 'Add Supplier' ?></h1></div>
  <a href="<?= url('client/suppliers/') ?>" class="btn btn-outline-secondary"><i class="bi bi-arrow-left me-1"></i>Back</a>
</div>

<?php if ($errors): ?><div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $x): ?><li><?= e($x) ?></li><?php endforeach; ?></ul></div><?php endif; ?>

<form method="post" class="js-once">
  <?= csrf_field() ?>
  <div class="card">
    <div class="card-body row g-3">
      <div class="col-md-6">
        <label class="form-label">Name *</label>
        <input class="form-control" name="name" value="<?= $v('name') ?>" required>
      </div>
      <div class="col-md-3">
        <label class="form-label">Phone</label>
        <input class="form-control" name="phone" value="<?= $v('phone') ?>">
      </div>
      <div class="col-md-3">
        <label class="form-label">Email</label>
        <input class="form-control" type="email" name="email" value="<?= $v('email') ?>">
      </div>
      <div class="col-12">
        <label class="form-label">Address</label>
        <textarea class="form-control" rows="2" name="address"><?= $v('address') ?></textarea>
      </div>
      <div class="col-md-9">
        <label class="form-label">Business details</label>
        <input class="form-control" name="business_details" value="<?= $v('business_details') ?>" placeholder="GSTIN, bank, etc.">
      </div>
      <div class="col-md-3 d-flex align-items-end">
        <div class="form-check form-switch">
          <input class="form-check-input" type="checkbox" id="is_active" name="is_active" value="1"
                 <?= (!$edit || $sup['is_active']) ? 'checked' : '' ?>>
          <label class="form-check-label" for="is_active">Active</label>
        </div>
      </div>
    </div>
  </div>
  <div class="mt-3 d-flex gap-2">
    <button class="btn btn-primary"><i class="bi bi-check2 me-1"></i><?= $edit ? 'Save changes' : 'Create supplier' ?></button>
    <a href="<?= url($edit ? 'client/suppliers/view.php?id=' . $id : 'client/suppliers/') ?>" class="btn btn-outline-secondary">Cancel</a>
  </div>
</form>

<?php require __DIR__ . '/../../includes/layout-bottom.php'; ?>

<?php
require __DIR__ . '/_bootstrap.php';
require_permission('customer.manage');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['error'=>'POST required'], 405);
if (!csrf_verify()) json_out(['error'=>'bad token'], 419);

$shopId = current_shop_id();
$name   = trim((string)($_POST['name']   ?? ''));
$mobile = trim((string)($_POST['mobile'] ?? '')) ?: null;
$email  = trim((string)($_POST['email']  ?? '')) ?: null;

if ($name === '') json_out(['error'=>'Name required'], 422);
if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) json_out(['error'=>'Invalid email'], 422);

db()->prepare('INSERT INTO customers (shop_id, name, mobile, email, is_active) VALUES (:sid, :n, :m, :e, 1)')
    ->execute([':sid'=>$shopId, ':n'=>$name, ':m'=>$mobile, ':e'=>$email]);
$id = (int)db()->lastInsertId();
audit('customer.create', 'customer', $id, ['from'=>'pos'], $shopId);

json_out(['customer' => ['id'=>$id, 'name'=>$name, 'mobile'=>$mobile, 'email'=>$email]]);

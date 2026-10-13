<?php
require __DIR__ . '/_bootstrap.php';
$shopId = current_shop_id();
$id   = (int)($_GET['id'] ?? 0);
$need = (float)($_GET['qty'] ?? 0);
if ($id <= 0) json_out(['error' => 'id required'], 400);

$stmt = db()->prepare('SELECT current_stock, is_available, unit FROM products WHERE id = :id AND shop_id = :sid');
$stmt->execute([':id' => $id, ':sid' => $shopId]);
$row = $stmt->fetch();
if (!$row) json_out(['error' => 'not found'], 404);

json_out([
    'available'    => (float)$row['current_stock'],
    'is_available' => (int)$row['is_available'] === 1,
    'unit'         => $row['unit'],
    'ok'           => (int)$row['is_available'] === 1 && (float)$row['current_stock'] >= $need,
]);

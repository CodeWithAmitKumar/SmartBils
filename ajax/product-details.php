<?php
require __DIR__ . '/_bootstrap.php';
$shopId = current_shop_id();
$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) json_out(['error' => 'id required'], 400);

$stmt = db()->prepare('SELECT id, name, sku, barcode, unit, purchase_cost, selling_price, current_stock, min_stock_alert, is_available
                       FROM products WHERE id = :id AND shop_id = :sid');
$stmt->execute([':id' => $id, ':sid' => $shopId]);
$row = $stmt->fetch();
if (!$row) json_out(['error' => 'not found'], 404);
json_out(['product' => $row]);

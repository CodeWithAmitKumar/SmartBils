<?php
require __DIR__ . '/_bootstrap.php';
$shopId = current_shop_id();
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') json_out(['items' => []]);

$like = "%{$q}%";
$stmt = db()->prepare('
    SELECT id, name, sku, barcode, unit, selling_price, current_stock, is_available
    FROM products
    WHERE shop_id = :sid
      AND (name LIKE :qn OR sku LIKE :qs OR barcode = :exact)
    ORDER BY is_available DESC, name
    LIMIT 20');
$stmt->execute([':sid' => $shopId, ':qn' => $like, ':qs' => $like, ':exact' => $q]);
json_out(['items' => $stmt->fetchAll()]);

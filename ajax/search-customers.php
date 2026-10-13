<?php
require __DIR__ . '/_bootstrap.php';
$shopId = current_shop_id();
$q = trim((string)($_GET['q'] ?? ''));
if ($q === '') json_out(['items' => []]);

$like = "%{$q}%";
$stmt = db()->prepare('
    SELECT id, name, mobile, email
    FROM customers
    WHERE shop_id = :sid AND is_active = 1
      AND (name LIKE :qn OR mobile LIKE :qm OR email LIKE :qe)
    ORDER BY name
    LIMIT 20');
$stmt->execute([':sid'=>$shopId, ':qn'=>$like, ':qm'=>$like, ':qe'=>$like]);
json_out(['items' => $stmt->fetchAll()]);

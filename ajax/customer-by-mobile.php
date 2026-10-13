<?php
require __DIR__ . '/_bootstrap.php';
$shopId = current_shop_id();
$mobile = trim((string)($_GET['mobile'] ?? ''));
if ($mobile === '') json_out(['customer' => null]);

$stmt = db()->prepare('SELECT id, name, mobile, email FROM customers
                       WHERE shop_id = :sid AND mobile = :m AND is_active = 1
                       ORDER BY id DESC LIMIT 1');
$stmt->execute([':sid' => $shopId, ':m' => $mobile]);
json_out(['customer' => $stmt->fetch() ?: null]);

<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId = current_shop_id();
$productId = (int)($_POST['product_id'] ?? 0);
$op = $_POST['op'] ?? '';

$stmt = db()->prepare('SELECT * FROM products WHERE id = :id AND shop_id = :sid');
$stmt->execute([':id'=>$productId, ':sid'=>$shopId]);
$product = $stmt->fetch();
if (!$product) { flash('danger', 'Product not found.'); redirect('client/products/'); }

try {
    switch ($op) {
        case 'adjust':
            require_permission('stock.adjust');
            $delta  = (float)($_POST['delta'] ?? 0);
            $reason = trim((string)($_POST['reason'] ?? ''));
            if ($delta == 0)      throw new RuntimeException('Change cannot be zero.');
            if ($reason === '')   throw new RuntimeException('Reason is required.');

            db()->beginTransaction();
            // Lock the row for stock check.
            $cur = db()->prepare('SELECT current_stock FROM products WHERE id = :id AND shop_id = :sid FOR UPDATE');
            $cur->execute([':id'=>$productId, ':sid'=>$shopId]);
            $currentStock = (float)$cur->fetchColumn();
            $newStock = $currentStock + $delta;
            if ($newStock < 0) throw new RuntimeException('Adjustment would make stock negative (' . $newStock . ').');

            $uid = (int)current_user()['id'];
            db()->prepare(
                'INSERT INTO stock_adjustments (shop_id, product_id, delta, reason, adjusted_by)
                 VALUES (:sid, :pid, :d, :r, :by)'
            )->execute([':sid'=>$shopId, ':pid'=>$productId, ':d'=>$delta, ':r'=>$reason, ':by'=>$uid]);
            $adjustId = (int)db()->lastInsertId();

            db()->prepare(
                "INSERT INTO stock_movements (shop_id, product_id, type, qty_change, ref_type, ref_id, note, created_by)
                 VALUES (:sid, :pid, 'adjustment', :q, 'stock_adjustment', :rid, :note, :by)"
            )->execute([
                ':sid'=>$shopId, ':pid'=>$productId, ':q'=>$delta,
                ':rid'=>$adjustId, ':note'=>$reason, ':by'=>$uid,
            ]);

            db()->prepare('UPDATE products SET current_stock = :s WHERE id = :id AND shop_id = :sid')
                ->execute([':s'=>$newStock, ':id'=>$productId, ':sid'=>$shopId]);

            db()->commit();
            audit('stock.adjust', 'product', $productId, ['delta'=>$delta, 'reason'=>$reason, 'new'=>$newStock], $shopId);
            flash('success', 'Stock adjusted. New stock: ' . rtrim(rtrim(number_format($newStock, 3, '.', ''), '0'), '.') . ' ' . $product['unit']);
            break;

        case 'toggle_available':
            require_permission('product.manage');
            db()->prepare('UPDATE products SET is_available = 1 - is_available WHERE id = :id AND shop_id = :sid')
                ->execute([':id'=>$productId, ':sid'=>$shopId]);
            audit('product.toggle_available', 'product', $productId, null, $shopId);
            flash('success', 'Availability toggled.');
            break;

        case 'delete':
            require_permission('product.manage');
            // Block delete if any movement exists (preserve history).
            $hasMoves = db()->prepare('SELECT 1 FROM stock_movements WHERE product_id = :id LIMIT 1');
            $hasMoves->execute([':id'=>$productId]);
            if ($hasMoves->fetchColumn()) {
                throw new RuntimeException('This product has stock history and cannot be deleted. Mark it unavailable instead.');
            }
            db()->prepare('DELETE FROM products WHERE id = :id AND shop_id = :sid')
                ->execute([':id'=>$productId, ':sid'=>$shopId]);
            audit('product.delete', 'product', $productId, ['name'=>$product['name']], $shopId);
            flash('success', 'Product deleted.');
            redirect('client/products/');

        default:
            throw new RuntimeException('Unknown operation.');
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash('danger', $e->getMessage());
}

redirect('client/products/view.php?id=' . $productId);

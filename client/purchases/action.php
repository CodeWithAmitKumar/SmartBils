<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('purchase.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId = current_shop_id();
$id = (int)($_POST['id'] ?? 0);
$op = $_POST['op'] ?? '';

$stmt = db()->prepare('SELECT * FROM purchases WHERE id = :id AND shop_id = :sid');
$stmt->execute([':id'=>$id, ':sid'=>$shopId]);
$pur = $stmt->fetch();
if (!$pur) { flash('danger', 'Purchase not found.'); redirect('client/purchases/'); }

try {
    switch ($op) {

        case 'finalize':
            if ($pur['status'] !== 'draft') throw new RuntimeException('Only draft purchases can be finalized.');
            db()->beginTransaction();
            $items = db()->prepare('SELECT product_id, qty, purchase_cost FROM purchase_items WHERE purchase_id = :id FOR UPDATE');
            $items->execute([':id'=>$id]);
            $items = $items->fetchAll();
            if (!$items) throw new RuntimeException('Purchase has no items.');

            $uid = (int)current_user()['id'];
            $mv = db()->prepare(
                "INSERT INTO stock_movements (shop_id, product_id, type, qty_change, ref_type, ref_id, note, created_by)
                 VALUES (:sid, :pid, 'purchase', :q, 'purchase', :rid, :nt, :by)");
            $up = db()->prepare('UPDATE products SET current_stock = current_stock + :q, purchase_cost = :c WHERE id = :id AND shop_id = :sid');
            foreach ($items as $it) {
                $mv->execute([':sid'=>$shopId, ':pid'=>$it['product_id'], ':q'=>$it['qty'],
                              ':rid'=>$id, ':nt'=>'Purchase #' . $id, ':by'=>$uid]);
                $up->execute([':q'=>$it['qty'], ':c'=>$it['purchase_cost'], ':id'=>$it['product_id'], ':sid'=>$shopId]);
            }
            db()->prepare("UPDATE purchases SET status = 'received', received_at = NOW() WHERE id = :id AND shop_id = :sid")
                ->execute([':id'=>$id, ':sid'=>$shopId]);
            db()->commit();
            audit('purchase.finalize', 'purchase', $id, null, $shopId);
            flash('success', 'Purchase finalised — stock updated.');
            break;

        case 'cancel':
            if ($pur['status'] !== 'draft') throw new RuntimeException('Only drafts can be cancelled.');
            db()->prepare("UPDATE purchases SET status = 'cancelled' WHERE id = :id AND shop_id = :sid")
                ->execute([':id'=>$id, ':sid'=>$shopId]);
            audit('purchase.cancel', 'purchase', $id, null, $shopId);
            flash('success', 'Draft cancelled.');
            break;

        case 'reverse':
            if ($pur['status'] !== 'received') throw new RuntimeException('Only received purchases can be reversed.');
            db()->beginTransaction();
            $items = db()->prepare('SELECT product_id, qty FROM purchase_items WHERE purchase_id = :id');
            $items->execute([':id'=>$id]);
            $items = $items->fetchAll();

            $uid = (int)current_user()['id'];
            $mv = db()->prepare(
                "INSERT INTO stock_movements (shop_id, product_id, type, qty_change, ref_type, ref_id, note, created_by)
                 VALUES (:sid, :pid, 'adjustment', :q, 'purchase_reversal', :rid, :nt, :by)");
            $up = db()->prepare('UPDATE products SET current_stock = current_stock - :q WHERE id = :id AND shop_id = :sid');
            foreach ($items as $it) {
                $mv->execute([':sid'=>$shopId, ':pid'=>$it['product_id'], ':q'=>-(float)$it['qty'],
                              ':rid'=>$id, ':nt'=>'Reversal of Purchase #' . $id, ':by'=>$uid]);
                $up->execute([':q'=>$it['qty'], ':id'=>$it['product_id'], ':sid'=>$shopId]);
            }
            db()->prepare("UPDATE purchases SET status = 'reversed' WHERE id = :id AND shop_id = :sid")
                ->execute([':id'=>$id, ':sid'=>$shopId]);
            db()->commit();
            audit('purchase.reverse', 'purchase', $id, null, $shopId);
            flash('success', 'Purchase reversed — stock corrected.');
            break;

        default:
            throw new RuntimeException('Unknown operation.');
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash('danger', $e->getMessage());
}

redirect('client/purchases/view.php?id=' . $id);

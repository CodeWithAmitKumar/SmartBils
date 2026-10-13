<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('supplier.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId = current_shop_id();
$supId  = (int)($_POST['supplier_id'] ?? 0);
$op     = $_POST['op'] ?? '';

$s = db()->prepare('SELECT * FROM suppliers WHERE id = :id AND shop_id = :sid');
$s->execute([':id'=>$supId, ':sid'=>$shopId]);
$sup = $s->fetch();
if (!$sup) { flash('danger', 'Supplier not found.'); redirect('client/suppliers/'); }

try {
    if ($op === 'pay') {
        $amount = max(0, (float)($_POST['amount'] ?? 0));
        if ($amount <= 0) throw new RuntimeException('Amount must be positive.');
        $method = in_array($_POST['method'] ?? '', ['cash','upi','bank','other'], true) ? $_POST['method'] : 'cash';
        $ref    = trim((string)($_POST['reference'] ?? '')) ?: null;
        $pid    = (int)($_POST['purchase_id'] ?? 0) ?: null;

        if ($pid) {
            $p = db()->prepare("SELECT 1 FROM purchases WHERE id = :id AND shop_id = :sid AND supplier_id = :supid");
            $p->execute([':id'=>$pid, ':sid'=>$shopId, ':supid'=>$supId]);
            if (!$p->fetchColumn()) throw new RuntimeException('Linked purchase not found.');
        }

        db()->prepare(
            'INSERT INTO supplier_payments (shop_id, supplier_id, purchase_id, amount, method, reference, paid_at, created_by)
             VALUES (:sid, :supid, :pid, :amt, :m, :r, NOW(), :by)'
        )->execute([
            ':sid'=>$shopId, ':supid'=>$supId, ':pid'=>$pid, ':amt'=>$amount,
            ':m'=>$method, ':r'=>$ref, ':by'=>(int)current_user()['id'],
        ]);
        audit('supplier.pay', 'supplier', $supId, ['amount'=>$amount, 'purchase_id'=>$pid], $shopId);
        flash('success', 'Payment of ' . money($amount) . ' recorded.');
    } else {
        throw new RuntimeException('Unknown operation.');
    }
} catch (Throwable $e) { flash('danger', $e->getMessage()); }

redirect('client/suppliers/view.php?id=' . $supId);

<?php
require_once __DIR__ . '/../../config/app.php';
require_shop_user();
require_permission('staff.manage');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId = current_shop_id();
$uid    = (int)($_POST['user_id'] ?? 0);
$op     = $_POST['op'] ?? '';

$stmt = db()->prepare("SELECT * FROM shop_users WHERE id = :id AND shop_id = :sid");
$stmt->execute([':id' => $uid, ':sid' => $shopId]);
$user = $stmt->fetch();
if (!$user) { flash('danger', 'User not found.'); redirect('client/staff/'); }
if ($user['role'] === 'owner') { flash('danger', 'Cannot modify the owner account from here.'); redirect('client/staff/'); }

try {
    switch ($op) {
        case 'delete':
            db()->prepare('DELETE FROM shop_users WHERE id = :id AND shop_id = :sid')
                ->execute([':id' => $uid, ':sid' => $shopId]);
            audit('staff.delete', 'shop_user', $uid, ['name' => $user['name']], $shopId);
            flash('success', 'Staff "' . $user['name'] . '" deleted.');
            break;

        case 'toggle_active':
            db()->prepare('UPDATE shop_users SET is_active = 1 - is_active WHERE id = :id AND shop_id = :sid')
                ->execute([':id' => $uid, ':sid' => $shopId]);
            audit('staff.toggle_active', 'shop_user', $uid, null, $shopId);
            flash('success', 'Staff status toggled.');
            break;

        case 'reset_password':
            $tmp = bin2hex(random_bytes(5)); // 10 chars
            db()->prepare('UPDATE shop_users SET password_hash = :p WHERE id = :id AND shop_id = :sid')
                ->execute([':p' => password_hash($tmp, PASSWORD_DEFAULT), ':id' => $uid, ':sid' => $shopId]);
            audit('staff.password_reset', 'shop_user', $uid, null, $shopId);
            flash('success', "Password reset for {$user['name']}. Temporary password: {$tmp} — share securely, user should change on first login.");
            break;

        default:
            throw new RuntimeException('Unknown operation.');
    }
} catch (Throwable $e) {
    flash('danger', $e->getMessage());
}

redirect('client/staff/');

<?php
// POST handler — status change, licence renewal, password reset.
require_once __DIR__ . '/../../config/app.php';
require_super_admin();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); exit('Method not allowed.'); }
csrf_require();

$shopId = (int)($_POST['shop_id'] ?? 0);
$op     = $_POST['op'] ?? '';
if ($shopId <= 0) { flash('danger', 'Missing shop.'); redirect('admin/clients/'); }

$stmt = db()->prepare('SELECT * FROM shops WHERE id = :id');
$stmt->execute([':id' => $shopId]);
$shop = $stmt->fetch();
if (!$shop) { flash('danger', 'Shop not found.'); redirect('admin/clients/'); }

try {
    switch ($op) {

        case 'set_status':
            $new = $_POST['status'] ?? '';
            if (!in_array($new, ['active','suspended','deactivated'], true)) throw new RuntimeException('Invalid status.');
            db()->prepare('UPDATE shops SET status = :s WHERE id = :id')
                ->execute([':s' => $new, ':id' => $shopId]);
            audit('shop.set_status', 'shop', $shopId, ['from' => $shop['status'], 'to' => $new], $shopId);
            flash('success', 'Shop status set to ' . $new . '.');
            break;

        case 'renew_license':
            $planId = (int)($_POST['plan_id'] ?? 0);
            $amount = (float)($_POST['amount'] ?? 0);
            $plan = null;
            $p = db()->prepare('SELECT * FROM subscription_plans WHERE id = :id AND is_active = 1');
            $p->execute([':id' => $planId]);
            $plan = $p->fetch();
            if (!$plan) throw new RuntimeException('Invalid plan.');

            db()->beginTransaction();
            $cur = db()->prepare('SELECT * FROM shop_licenses WHERE shop_id = :id ORDER BY id DESC LIMIT 1');
            $cur->execute([':id' => $shopId]);
            $current = $cur->fetch();

            $today = date('Y-m-d');
            // Renew from the later of today / current expiry so prepaid time isn't lost.
            $base   = $current && $current['expiry_date'] > $today ? $current['expiry_date'] : $today;
            $newExp = (new DateTime($base))->modify('+' . (int)$plan['duration_days'] . ' days')->format('Y-m-d');

            if ($current) {
                db()->prepare("UPDATE shop_licenses
                               SET plan_id = :pid, start_date = :sd, expiry_date = :ed, status = 'active'
                               WHERE id = :id")
                    ->execute([':pid' => $planId, ':sd' => $today, ':ed' => $newExp, ':id' => $current['id']]);
                db()->prepare("INSERT INTO license_renewals (shop_license_id, plan_id, previous_expiry, new_expiry, amount, renewed_by)
                               VALUES (:slid, :pid, :pe, :ne, :amt, :by)")
                    ->execute([
                        ':slid' => $current['id'], ':pid' => $planId,
                        ':pe' => $current['expiry_date'], ':ne' => $newExp,
                        ':amt' => $amount, ':by' => (int)current_user()['id'],
                    ]);
            } else {
                db()->prepare("INSERT INTO shop_licenses (shop_id, plan_id, start_date, expiry_date, status)
                               VALUES (:sid, :pid, :sd, :ed, 'active')")
                    ->execute([':sid' => $shopId, ':pid' => $planId, ':sd' => $today, ':ed' => $newExp]);
                $newLicId = (int)db()->lastInsertId();
                db()->prepare("INSERT INTO license_renewals (shop_license_id, plan_id, previous_expiry, new_expiry, amount, renewed_by)
                               VALUES (:slid, :pid, :pe, :ne, :amt, :by)")
                    ->execute([
                        ':slid' => $newLicId, ':pid' => $planId,
                        ':pe' => $today, ':ne' => $newExp,
                        ':amt' => $amount, ':by' => (int)current_user()['id'],
                    ]);
            }
            db()->commit();
            audit('shop.license_renew', 'shop', $shopId, ['plan_id'=>$planId, 'amount'=>$amount, 'new_expiry'=>$newExp], $shopId);
            flash('success', 'Licence active until ' . date('d M Y', strtotime($newExp)) . '.');
            break;

        case 'delete':
            db()->prepare('DELETE FROM shops WHERE id = :id')->execute([':id' => $shopId]);
            audit('shop.delete', 'shop', $shopId, ['name' => $shop['name']]);
            flash('success', 'Shop "' . $shop['name'] . '" deleted.');
            redirect('admin/clients/');

        case 'reset_password':
            $userId = (int)($_POST['user_id'] ?? 0);
            $u = db()->prepare('SELECT id, name FROM shop_users WHERE id = :id AND shop_id = :sid');
            $u->execute([':id' => $userId, ':sid' => $shopId]);
            $user = $u->fetch();
            if (!$user) throw new RuntimeException('User not found.');

            $tmp = bin2hex(random_bytes(5)); // 10 chars
            db()->prepare('UPDATE shop_users SET password_hash = :p WHERE id = :id')
                ->execute([':p' => password_hash($tmp, PASSWORD_DEFAULT), ':id' => $userId]);
            audit('shop_user.password_reset', 'shop_user', $userId, null, $shopId);
            flash('success', "Password reset for {$user['name']}. Temporary password: {$tmp} — share securely, user should change on first login.");
            break;

        default:
            throw new RuntimeException('Unknown operation.');
    }
} catch (Throwable $e) {
    if (db()->inTransaction()) db()->rollBack();
    flash('danger', $e->getMessage());
}

redirect('admin/clients/view.php?id=' . $shopId);

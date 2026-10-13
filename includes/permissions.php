<?php
// Permission checks. shop_owner has all shop perms implicitly;
// staff perms are stored in shop_user_permissions (populated in Phase 4).
declare(strict_types=1);

function user_permissions(int $shopUserId): array {
    static $cache = [];
    if (isset($cache[$shopUserId])) return $cache[$shopUserId];

    $stmt = db()->prepare(
        'SELECT p.`key`
         FROM shop_user_permissions sup
         JOIN permissions p ON p.id = sup.permission_id
         WHERE sup.shop_user_id = :uid'
    );
    $stmt->execute([':uid' => $shopUserId]);
    return $cache[$shopUserId] = array_column($stmt->fetchAll(), 'key');
}

function has_permission(string $key): bool {
    $u = current_user();
    if (!$u) return false;
    if ($u['user_type'] === 'super_admin') return true;
    if ($u['user_type'] === 'shop_owner')  return true;
    return in_array($key, user_permissions((int)$u['id']), true);
}

function require_permission(string $key): void {
    if (!has_permission($key)) {
        http_response_code(403);
        exit('Forbidden — missing permission: ' . e($key));
    }
}

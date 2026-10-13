<?php
// Audit logging — one call per action. Never stores passwords or payment credentials.
declare(strict_types=1);

function audit(string $action, ?string $targetType = null, $targetId = null, ?array $context = null, ?int $shopId = null): void {
    $u = current_user();
    try {
        db()->prepare(
            'INSERT INTO audit_logs (shop_id, actor_type, actor_id, action, target_type, target_id, context, ip_address)
             VALUES (:shop, :atype, :aid, :action, :ttype, :tid, :ctx, :ip)'
        )->execute([
            ':shop'   => $shopId ?? ($u['shop_id'] ?? null),
            ':atype'  => ($u && $u['user_type'] === 'super_admin') ? 'admin' : 'shop',
            ':aid'    => $u ? (int)$u['id'] : null,
            ':action' => $action,
            ':ttype'  => $targetType,
            ':tid'    => $targetId !== null ? (int)$targetId : null,
            ':ctx'    => $context !== null ? json_encode($context, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null,
            ':ip'     => $_SERVER['REMOTE_ADDR'] ?? null,
        ]);
    } catch (Throwable $e) {
        error_log('[audit] ' . $e->getMessage());
    }
}

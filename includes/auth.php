<?php
// Authentication — separate tables for admins and shop_users.
declare(strict_types=1);

const AUTH_MAX_ATTEMPTS = 5;    // per identifier within window
const AUTH_WINDOW_MIN   = 15;   // minutes

function auth_client_ip(): string {
    return $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
}

/** $type = 'admin' | 'shop' */
function auth_record_attempt(string $identifier, string $type, bool $success): void {
    db()->prepare(
        'INSERT INTO login_attempts (identifier, ip_address, user_type, success)
         VALUES (:i, :ip, :t, :s)'
    )->execute([
        ':i'  => mb_substr($identifier, 0, 150),
        ':ip' => auth_client_ip(),
        ':t'  => $type,
        ':s'  => $success ? 1 : 0,
    ]);
}

function auth_is_throttled(string $identifier, string $type): bool {
    $stmt = db()->prepare(
        'SELECT COUNT(*) FROM login_attempts
         WHERE identifier = :i AND user_type = :t AND success = 0
           AND attempted_at >= (NOW() - INTERVAL :w MINUTE)'
    );
    $stmt->execute([':i' => $identifier, ':t' => $type, ':w' => AUTH_WINDOW_MIN]);
    return (int)$stmt->fetchColumn() >= AUTH_MAX_ATTEMPTS;
}

/** Try to log in a super admin. Returns admin row or null. */
function auth_attempt_admin(string $identifier, string $password): ?array {
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') return null;

    $stmt = db()->prepare(
        'SELECT * FROM admins WHERE username = :u OR email = :e LIMIT 1'
    );
    $stmt->execute([':u' => $identifier, ':e' => $identifier]);
    $u = $stmt->fetch();
    if (!$u) return null;
    if (!password_verify($password, $u['password_hash'])) return null;
    if ((int)$u['is_active'] !== 1) return null;

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE admins SET password_hash = :p WHERE id = :id')
            ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $u['id']]);
    }
    db()->prepare('UPDATE admins SET last_login_at = NOW() WHERE id = :id')
        ->execute([':id' => $u['id']]);
    return $u;
}

/** Try to log in a shop user (owner or staff). Returns shop_user row (with shop_name/status) or null. */
function auth_attempt_shop(string $identifier, string $password): ?array {
    $identifier = trim($identifier);
    if ($identifier === '' || $password === '') return null;

    $stmt = db()->prepare(
        'SELECT su.*, s.name AS shop_name, s.status AS shop_status
         FROM shop_users su
         JOIN shops s ON s.id = su.shop_id
         WHERE su.username = :u OR su.email = :e LIMIT 1'
    );
    $stmt->execute([':u' => $identifier, ':e' => $identifier]);
    $u = $stmt->fetch();
    if (!$u) return null;
    if (!password_verify($password, $u['password_hash'])) return null;
    if ((int)$u['is_active'] !== 1) return null;
    if ($u['shop_status'] !== 'active') return null;

    if (password_needs_rehash($u['password_hash'], PASSWORD_DEFAULT)) {
        db()->prepare('UPDATE shop_users SET password_hash = :p WHERE id = :id')
            ->execute([':p' => password_hash($password, PASSWORD_DEFAULT), ':id' => $u['id']]);
    }
    db()->prepare('UPDATE shop_users SET last_login_at = NOW() WHERE id = :id')
        ->execute([':id' => $u['id']]);
    return $u;
}

/** Save admin to session. */
function auth_login_admin(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'          => (int)$u['id'],
        'name'        => $u['name'],
        'username'    => $u['username'],
        'email'       => $u['email'],
        'user_type'   => 'super_admin',
        'shop_id'     => null,
        'shop_name'   => null,
        'avatar_path' => $u['avatar_path'] ?? null,
    ];
    $_SESSION['login_time'] = time();
    clear_old();
}

/** Save shop user to session. */
function auth_login_shop(array $u): void {
    session_regenerate_id(true);
    $_SESSION['user'] = [
        'id'        => (int)$u['id'],
        'name'      => $u['name'],
        'username'  => $u['username'],
        'email'     => $u['email'],
        'user_type' => $u['role'] === 'owner' ? 'shop_owner' : 'staff',
        'shop_id'   => (int)$u['shop_id'],
        'shop_name' => $u['shop_name'] ?? null,
    ];
    $_SESSION['login_time'] = time();
    clear_old();
}

// ---------- Guards ----------
function require_login(): void {
    if (!is_logged_in()) {
        flash('warning', 'Please sign in to continue.');
        redirect('index.php');
    }
}

function require_super_admin(): void {
    if (!is_logged_in()) {
        flash('warning', 'Please sign in to continue.');
        redirect('admin/');
    }
    if ((current_user()['user_type'] ?? '') !== 'super_admin') {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function require_shop_user(): void {
    require_login();
    $u = current_user();
    if (!in_array($u['user_type'] ?? '', ['shop_owner','staff'], true) || empty($u['shop_id'])) {
        http_response_code(403);
        exit('Forbidden.');
    }
}

function current_shop_id(): int {
    $u = current_user();
    if (empty($u['shop_id'])) {
        http_response_code(403);
        exit('No shop context.');
    }
    return (int)$u['shop_id'];
}

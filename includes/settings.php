<?php
// Tiny key/value settings store (app_settings table).
declare(strict_types=1);

function setting_get(string $key, ?string $default = null): ?string {
    static $cache = null;
    if ($cache === null) {
        $cache = [];
        try {
            foreach (db()->query('SELECT `key`, `value` FROM app_settings') as $r) {
                $cache[$r['key']] = $r['value'];
            }
        } catch (Throwable $e) { /* table might not exist yet during install */ }
    }
    return array_key_exists($key, $cache) ? $cache[$key] : $default;
}

function setting_set_many(array $pairs): void {
    $stmt = db()->prepare('INSERT INTO app_settings (`key`,`value`) VALUES (:k,:v)
                           ON DUPLICATE KEY UPDATE `value` = VALUES(`value`)');
    foreach ($pairs as $k => $v) $stmt->execute([':k' => $k, ':v' => (string)$v]);
}

function setting_bool(string $key, bool $default = false): bool {
    $v = setting_get($key, $default ? '1' : '0');
    return in_array(strtolower((string)$v), ['1','true','yes','on'], true);
}

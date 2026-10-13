<?php
// Shared helpers
declare(strict_types=1);

/** Escape output — use for every echo of dynamic value. */
function e($v): string {
    return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8');
}

/** Build URL from BASE_URL. */
function url(string $path = ''): string {
    return rtrim(BASE_URL, '/') . '/' . ltrim($path, '/');
}

/** Redirect helper. */
function redirect(string $path): void {
    header('Location: ' . url($path));
    exit;
}

/** Flash message — set now, display on next page. */
function flash(string $type, string $msg): void {
    $_SESSION['_flash'][] = ['type' => $type, 'msg' => $msg];
}

function flash_pull(): array {
    $f = $_SESSION['_flash'] ?? [];
    unset($_SESSION['_flash']);
    return $f;
}

/** Format money (display only — storage uses DECIMAL). */
function money($amount): string {
    return APP_CURRENCY_SYMBOL . number_format((float)$amount, 2);
}

/** Simple old-input helper for form repopulation. */
function old(string $key, string $default = ''): string {
    return e($_SESSION['_old'][$key] ?? $default);
}

function keep_old(array $data): void {
    $_SESSION['_old'] = $data;
}

function clear_old(): void {
    unset($_SESSION['_old']);
}

/** Current logged-in user (session only — real auth lands Phase 2). */
function current_user(): ?array {
    return $_SESSION['user'] ?? null;
}

function is_logged_in(): bool {
    return !empty($_SESSION['user']);
}

<?php
// CSRF helpers
declare(strict_types=1);

function csrf_token(): string {
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_field(): string {
    return '<input type="hidden" name="_csrf" value="' . e(csrf_token()) . '">';
}

function csrf_verify(?string $token = null): bool {
    $t = $token ?? ($_POST['_csrf'] ?? $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '');
    return is_string($t) && !empty($_SESSION['_csrf']) && hash_equals($_SESSION['_csrf'], $t);
}

/** Call at top of every POST handler. Aborts with 419 on bad token. */
function csrf_require(): void {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') return;
    if (!csrf_verify()) {
        http_response_code(419);
        exit('Session expired. Please refresh and try again.');
    }
}

<?php
// Smart Billing — app config
declare(strict_types=1);

define('APP_NAME', 'SmartBils');
define('APP_ENV', 'development'); // 'production' on live
define('APP_DEBUG', APP_ENV === 'development');
define('BASE_URL', '/smartbilling');
define('APP_TIMEZONE', 'Asia/Kolkata');
define('APP_CURRENCY', 'INR');
define('APP_CURRENCY_SYMBOL', '₹');

date_default_timezone_set(APP_TIMEZONE);

// Error display policy
if (APP_DEBUG) {
    error_reporting(E_ALL);
    ini_set('display_errors', '1');
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', '0');
    ini_set('log_errors', '1');
    ini_set('error_log', __DIR__ . '/../storage/logs/php-error.log');
}

// Security headers — only when PHP is serving HTML / JSON (not CLI)
if (PHP_SAPI !== 'cli' && !headers_sent()) {
    header('X-Content-Type-Options: nosniff');
    header('X-Frame-Options: SAMEORIGIN');
    header('Referrer-Policy: strict-origin-when-cross-origin');
    header('Permissions-Policy: geolocation=(), camera=(), microphone=()');
    if (!empty($_SERVER['HTTPS'])) header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

// Secure session cookie settings — must be set BEFORE session_start()
if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => 0,
        'path'     => '/',
        'secure'   => !empty($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_name('SMARTBILL_SESS');
    session_start();
}

// Idle timeout — 2 hours
$timeout = 7200;
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > $timeout) {
    session_unset();
    session_destroy();
}
$_SESSION['last_activity'] = time();

require_once __DIR__ . '/database.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/csrf.php';
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/permissions.php';
require_once __DIR__ . '/../includes/audit.php';
require_once __DIR__ . '/../includes/image.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/mail.php';

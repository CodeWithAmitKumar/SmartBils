<?php
require_once __DIR__ . '/config/app.php';

$wasAdmin = (current_user()['user_type'] ?? '') === 'super_admin';

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $p = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000, $p['path'], $p['domain'], $p['secure'], $p['httponly']);
}
session_destroy();

// Start a fresh session just to hold the goodbye flash.
session_start();
flash('success', 'You have been signed out.');
redirect($wasAdmin ? 'admin/' : 'index.php');

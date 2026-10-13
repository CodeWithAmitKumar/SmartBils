<?php
// Shared setup for AJAX endpoints — auth + shop scope + JSON headers.
require_once __DIR__ . '/../config/app.php';
require_shop_user();
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');

function json_out(array $data, int $code = 200): void {
    http_response_code($code);
    echo json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

<?php
require __DIR__ . '/_bootstrap.php';
if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(['ok'=>false, 'error'=>'POST required'], 405);
if (!csrf_verify()) json_out(['ok'=>false, 'error'=>'Bad token'], 419);

$shopId = current_shop_id();
$userId = (int)current_user()['id'];
$question = trim((string)($_POST['q'] ?? ''));
if ($question === '') json_out(['ok'=>false, 'error'=>'Empty question'], 422);
if (mb_strlen($question) > 1000) json_out(['ok'=>false, 'error'=>'Question too long (max 1000 chars)'], 422);

$r = smarty_ask($shopId, $userId, $question);
json_out($r, $r['ok'] ? 200 : 200);

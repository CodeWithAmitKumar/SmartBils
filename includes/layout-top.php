<?php
// Protected-page layout opener. Caller sets $pageTitle BEFORE requiring this.
if (!defined('APP_NAME')) { require_once __DIR__ . '/../config/app.php'; }
require __DIR__ . '/header.php';
require __DIR__ . '/sidebar.php';
require __DIR__ . '/navbar.php';
?>
<main class="sb-main">
  <?php require __DIR__ . '/flash-messages.php'; ?>

<?php
$u = current_user();
$role = $u['user_type'] ?? 'guest';
$current = basename($_SERVER['PHP_SELF']);
$active = fn(string $f) => $current === $f ? 'active' : '';

// Menus — pages are placeholders in Phase 1; real pages land in later phases.
$adminMenu = [
    ['dashboard.php',       'speedometer2', 'Dashboard'],
    ['clients/',            'shop',         'Shops'],
    ['plans/',              'tags',         'Plans'],
    ['licenses/',           'key',          'Licences'],
    ['reports/shops.php',   'bar-chart',    'Shop Report'],
    ['reports/money.php',   'cash-coin',    'Money Report'],
    ['backups/',            'shield-lock',  'Backups'],
];
$clientMenu = [
    ['dashboard.php',  'speedometer2', 'Dashboard'],
    ['billing/',       'cart-plus',    'New Bill'],
    ['dues/',          'cash-stack',   'Collect Due'],
    ['products/',      'box-seam',     'Products'],
    ['categories/',    'tags',         'Categories'],
    ['customers/',     'people',       'Customers'],
    ['suppliers/',     'truck',        'Suppliers'],
    ['purchases/',     'bag-plus',     'Purchases'],
    ['payments/',      'wallet2',      'Payments'],
    ['returns/',       'arrow-counterclockwise', 'Returns'],
    ['expenses/',      'receipt',      'Expenses'],
    ['reports/',       'bar-chart',    'Reports'],
    ['staff/',         'people-fill',  'Staff'],
    ['audit/',         'journal-text', 'Audit log'],
    ['settings/',      'gear',         'Settings'],
];
$menu = $role === 'super_admin' ? $adminMenu : $clientMenu;
$base = $role === 'super_admin' ? 'admin/' : 'client/';
?>
<aside class="sb-sidebar" id="sbSidebar">
  <div class="sb-sidebar-header">
    <img src="<?= url('uploads/logo/smartbils_logo_transparent.png') ?>"
         alt="<?= e(APP_NAME) ?>" class="sb-sidebar-logo">
    <button class="btn-close ms-auto d-lg-none" id="sbSidebarClose" aria-label="Close menu"></button>
  </div>
  <nav class="sb-nav">
    <?php foreach ($menu as [$path, $icon, $label]):
        // Folder → match on path; .php file → exact filename.
        $isFolder = substr($path, -1) === '/';
        if ($isFolder) {
            $isActive = str_contains($_SERVER['PHP_SELF'], '/' . $base . $path);
        } else {
            $isActive = basename($_SERVER['PHP_SELF']) === basename($path);
        }
    ?>
      <a class="sb-nav-item <?= $isActive ? 'active' : '' ?>" href="<?= url($base . $path) ?>">
        <i class="bi bi-<?= e($icon) ?>"></i>
        <span><?= e($label) ?></span>
      </a>
    <?php endforeach; ?>
  </nav>
</aside>
<div class="sb-sidebar-overlay" id="sbSidebarOverlay"></div>

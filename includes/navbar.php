<?php
$u = current_user();
$initials = $u ? mb_strtoupper(mb_substr($u['name'] ?? '?', 0, 1)) : '?';
$avatarUrl = null;
if ($u) {
    if (($u['user_type'] ?? '') === 'super_admin') {
        // Admin: avatar from session (populated at login or when profile is saved)
        $avatarUrl = image_url($u['avatar_path'] ?? null);
    } elseif (!empty($u['shop_id'])) {
        $r = db()->prepare('SELECT logo_path FROM shops WHERE id = :id');
        $r->execute([':id' => (int)$u['shop_id']]);
        $avatarUrl = image_url($r->fetchColumn() ?: null);
    }
}
?>
<nav class="sb-navbar">
  <button class="btn btn-icon d-lg-none" id="sbSidebarToggle" aria-label="Open menu">
    <i class="bi bi-list"></i>
  </button>
  <div class="sb-navbar-right">
    <?php if ($u): ?>
      <div class="dropdown">
        <button class="sb-avatar-btn" type="button" data-bs-toggle="dropdown" aria-expanded="false">
          <?php if ($avatarUrl): ?>
            <img src="<?= e($avatarUrl) ?>" alt="">
          <?php else: ?>
            <span><?= e($initials) ?></span>
          <?php endif; ?>
        </button>
        <?php
          $isAdmin   = ($u['user_type'] ?? '') === 'super_admin';
          $profile   = $isAdmin ? 'admin/profile.php'        : 'client/profile.php';
          $password  = $isAdmin ? 'admin/change-password.php' : 'client/change-password.php';
          $emailSet  = $isAdmin ? 'admin/email-settings.php' : null;
        ?>
        <ul class="dropdown-menu dropdown-menu-end sb-avatar-menu">
          <li class="px-3 py-2">
            <div class="fw-semibold text-truncate"><?= e($u['name']) ?></div>
            <div class="small text-muted text-truncate">
              <?= $isAdmin ? 'Super Admin' : e($u['shop_name'] ?? 'Shop user') ?>
            </div>
          </li>
          <li><hr class="dropdown-divider my-1"></li>
          <li><a class="dropdown-item" href="<?= url($profile) ?>"><i class="bi bi-person me-2"></i>Update profile</a></li>
          <li><a class="dropdown-item" href="<?= url($password) ?>"><i class="bi bi-key me-2"></i>Change password</a></li>
          <?php if ($emailSet): ?>
            <li><a class="dropdown-item" href="<?= url($emailSet) ?>"><i class="bi bi-envelope me-2"></i>Email settings</a></li>
          <?php endif; ?>
          <li><hr class="dropdown-divider my-1"></li>
          <li>
            <a class="dropdown-item text-danger" href="<?= url('logout.php') ?>">
              <i class="bi bi-box-arrow-right me-2"></i>Sign out
            </a>
          </li>
        </ul>
      </div>
    <?php endif; ?>
  </div>
</nav>

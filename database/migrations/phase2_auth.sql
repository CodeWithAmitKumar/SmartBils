-- =====================================================================
-- Phase 2 — Authentication & Authorization  (REVISED)
--   * Separate `admins` and `shop_users` tables
--   * No `cashier` role (shop_owner only; staff perms come in Phase 4)
-- Run from phpMyAdmin or:  mysql -u root smartbilling < database/migrations/phase2_auth.sql
--
-- If you ran the earlier Phase 2 SQL already, run the drops at the top
-- or `DROP DATABASE smartbilling; CREATE DATABASE smartbilling …;` first.
-- =====================================================================
USE `smartbilling`;

SET FOREIGN_KEY_CHECKS = 0;

-- Clean slate if an earlier Phase 2 ran
DROP TABLE IF EXISTS `password_reset_tokens`;
DROP TABLE IF EXISTS `login_attempts`;
DROP TABLE IF EXISTS `user_roles`;
DROP TABLE IF EXISTS `role_permissions`;
DROP TABLE IF EXISTS `shop_user_permissions`;
DROP TABLE IF EXISTS `permissions`;
DROP TABLE IF EXISTS `roles`;
DROP TABLE IF EXISTS `shop_users`;
DROP TABLE IF EXISTS `admins`;
DROP TABLE IF EXISTS `users`;
DROP TABLE IF EXISTS `shops`;

-- --------------------------------------------------------
-- shops (minimal — expanded in Phase 3)
-- --------------------------------------------------------
CREATE TABLE `shops` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`        VARCHAR(150) NOT NULL,
  `owner_name`  VARCHAR(150) NULL,
  `phone`       VARCHAR(20)  NULL,
  `email`       VARCHAR(150) NULL,
  `address`     TEXT NULL,
  `status`      ENUM('active','suspended','deactivated') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_shops_status` (`status`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- admins — platform users (super admins only)
-- --------------------------------------------------------
CREATE TABLE `admins` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`           VARCHAR(150) NOT NULL,
  `username`       VARCHAR(100) NOT NULL,
  `email`          VARCHAR(150) NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at`  DATETIME NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_admins_username` (`username`),
  KEY `ix_admins_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- shop_users — shop-side accounts (owner + optional staff)
-- --------------------------------------------------------
CREATE TABLE `shop_users` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`        BIGINT UNSIGNED NOT NULL,
  `name`           VARCHAR(150) NOT NULL,
  `username`       VARCHAR(100) NOT NULL,
  `email`          VARCHAR(150) NULL,
  `password_hash`  VARCHAR(255) NOT NULL,
  `role`           ENUM('owner','staff') NOT NULL DEFAULT 'owner',
  `is_active`      TINYINT(1) NOT NULL DEFAULT 1,
  `last_login_at`  DATETIME NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_shop_users_username` (`username`),
  KEY `ix_shop_users_shop` (`shop_id`),
  KEY `ix_shop_users_email` (`email`),
  CONSTRAINT `fk_shop_users_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- permissions catalogue (used in Phase 4 for granular staff perms)
-- --------------------------------------------------------
CREATE TABLE `permissions` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `key`         VARCHAR(100) NOT NULL,
  `description` VARCHAR(255) NULL,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_permissions_key` (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `shop_user_permissions` (
  `shop_user_id`  BIGINT UNSIGNED NOT NULL,
  `permission_id` BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (`shop_user_id`,`permission_id`),
  CONSTRAINT `fk_sup_user` FOREIGN KEY (`shop_user_id`) REFERENCES `shop_users`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sup_perm` FOREIGN KEY (`permission_id`) REFERENCES `permissions`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- login throttling — works for both admins and shop_users
-- --------------------------------------------------------
CREATE TABLE `login_attempts` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `identifier`   VARCHAR(150) NOT NULL,
  `ip_address`   VARCHAR(45)  NOT NULL,
  `user_type`    ENUM('admin','shop') NOT NULL,
  `success`      TINYINT(1)   NOT NULL DEFAULT 0,
  `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_la_ident_time` (`identifier`,`attempted_at`),
  KEY `ix_la_ip_time`    (`ip_address`,`attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- password resets — points at either admins or shop_users
-- --------------------------------------------------------
CREATE TABLE `password_reset_tokens` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `user_type`  ENUM('admin','shop') NOT NULL,
  `user_id`    BIGINT UNSIGNED NOT NULL,
  `token_hash` VARCHAR(255) NOT NULL,
  `expires_at` DATETIME NOT NULL,
  `used_at`    DATETIME NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_prt_user` (`user_type`,`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- seed permissions (used by Phase 4 for granular staff perms)
-- --------------------------------------------------------
-- --------------------------------------------------------
-- seed default Super Admin  ← LOG IN WITH THESE
--   username: admin
--   password: admin@123
--   Change the password immediately after first login.
-- --------------------------------------------------------
INSERT INTO `admins` (`name`, `username`, `email`, `password_hash`, `is_active`) VALUES
  ('Super Admin', 'admin', NULL,
   '$2y$10$9z3oSfs2BV4iHId.Kaic5OquBk3tlwUKFg0SMw5muyCEM9fa4AJMe',
   1);

-- --------------------------------------------------------
INSERT INTO `permissions` (`key`,`description`) VALUES
  ('bill.create',     'Create new sales / POS billing'),
  ('bill.view',       'View sales / invoices'),
  ('due.collect',     'Collect outstanding payments'),
  ('product.manage',  'Manage products and categories'),
  ('stock.adjust',    'Adjust stock with reason'),
  ('customer.manage', 'Manage customers'),
  ('supplier.manage', 'Manage suppliers'),
  ('purchase.manage', 'Manage purchases'),
  ('return.manage',   'Process sales returns and refunds'),
  ('expense.manage',  'Manage expenses'),
  ('report.view',     'View reports'),
  ('settings.manage', 'Manage shop settings'),
  ('staff.manage',    'Manage shop staff');

SET FOREIGN_KEY_CHECKS = 1;

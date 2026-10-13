-- =====================================================================
-- SmartBils — complete install SQL (all phases, in order)
-- Run once on an empty 'smartbilling' database.
-- =====================================================================

-- --- phase2_auth.sql ---
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

-- --- phase3_admin.sql ---
-- =====================================================================
-- Phase 3 — Super Admin: shops, plans, licences, renewals, audit log
-- Run:  mysql -u root smartbilling < database/migrations/phase3_admin.sql
-- =====================================================================
USE `smartbilling`;

SET FOREIGN_KEY_CHECKS = 0;

-- --------------------------------------------------------
-- subscription_plans
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `subscription_plans` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `name`          VARCHAR(100) NOT NULL,
  `fee`           DECIMAL(12,2) NOT NULL DEFAULT 0,
  `duration_days` INT UNSIGNED NOT NULL DEFAULT 30,
  `is_active`     TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_plans_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- shop_licenses — one active row per shop; renewals append rows in license_renewals
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `shop_licenses` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`     BIGINT UNSIGNED NOT NULL,
  `plan_id`     BIGINT UNSIGNED NOT NULL,
  `start_date`  DATE NOT NULL,
  `expiry_date` DATE NOT NULL,
  `status`      ENUM('active','expired','suspended') NOT NULL DEFAULT 'active',
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_sl_shop_expiry` (`shop_id`,`expiry_date`),
  CONSTRAINT `fk_sl_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sl_plan` FOREIGN KEY (`plan_id`) REFERENCES `subscription_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- license_renewals — immutable renewal history
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `license_renewals` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_license_id`  BIGINT UNSIGNED NOT NULL,
  `plan_id`          BIGINT UNSIGNED NOT NULL,
  `previous_expiry`  DATE NOT NULL,
  `new_expiry`       DATE NOT NULL,
  `amount`           DECIMAL(12,2) NOT NULL DEFAULT 0,
  `renewed_by`       BIGINT UNSIGNED NULL,  -- admins.id
  `renewed_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_lr_license` (`shop_license_id`),
  CONSTRAINT `fk_lr_license` FOREIGN KEY (`shop_license_id`) REFERENCES `shop_licenses`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_lr_plan`    FOREIGN KEY (`plan_id`)         REFERENCES `subscription_plans`(`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- audit_logs — admin + shop actions
-- --------------------------------------------------------
CREATE TABLE IF NOT EXISTS `audit_logs` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`     BIGINT UNSIGNED NULL,
  `actor_type`  ENUM('admin','shop') NOT NULL,
  `actor_id`    BIGINT UNSIGNED NULL,
  `action`      VARCHAR(80) NOT NULL,
  `target_type` VARCHAR(60) NULL,
  `target_id`   BIGINT UNSIGNED NULL,
  `context`     JSON NULL,
  `ip_address`  VARCHAR(45) NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_al_shop` (`shop_id`,`created_at`),
  KEY `ix_al_actor` (`actor_type`,`actor_id`),
  KEY `ix_al_action` (`action`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --------------------------------------------------------
-- seed a starter plan
-- --------------------------------------------------------
INSERT IGNORE INTO `subscription_plans` (`name`,`fee`,`duration_days`,`is_active`) VALUES
  ('Starter',  0.00,  30, 1),
  ('Monthly',  499.00, 30, 1),
  ('Yearly',  4999.00, 365, 1);

SET FOREIGN_KEY_CHECKS = 1;

-- --- phase4_admin_extras.sql ---
-- Phase 4 — admin extras: app_settings key/value store
USE `smartbilling`;

CREATE TABLE IF NOT EXISTS `app_settings` (
  `key`         VARCHAR(100) NOT NULL,
  `value`       TEXT NULL,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `app_settings` (`key`, `value`) VALUES
  ('mail_from_name',     'SmartBils'),
  ('mail_from_email',    ''),
  ('mail_app_password',  '');

-- --- phase4_shop.sql ---
-- =====================================================================
-- Phase 4 — Shop settings
-- Run: mysql -u root smartbilling < database/migrations/phase4_shop.sql
-- =====================================================================
USE `smartbilling`;

CREATE TABLE IF NOT EXISTS `shop_settings` (
  `shop_id`          BIGINT UNSIGNED NOT NULL,
  `receipt_header`   VARCHAR(500) NULL,
  `receipt_footer`   VARCHAR(500) NULL,
  `currency`         VARCHAR(8)   NOT NULL DEFAULT 'INR',
  `tax_percent`      DECIMAL(5,2) NOT NULL DEFAULT 0,
  `cash_enabled`     TINYINT(1)   NOT NULL DEFAULT 1,
  `upi_enabled`      TINYINT(1)   NOT NULL DEFAULT 1,
  `upi_id`           VARCHAR(100) NULL,
  `upi_qr_path`      VARCHAR(255) NULL,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`shop_id`),
  CONSTRAINT `fk_ss_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- --- phase4_shop_media.sql ---
-- Phase 4 prep — shop logo
USE `smartbilling`;
ALTER TABLE `shops`
  ADD COLUMN `logo_path` VARCHAR(255) NULL AFTER `address`;

-- --- phase5_products.sql ---
-- =====================================================================
-- Phase 5 — Categories, Products, Stock
-- Run: mysql -u root smartbilling < database/migrations/phase5_products.sql
-- =====================================================================
USE `smartbilling`;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `categories` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`    BIGINT UNSIGNED NOT NULL,
  `name`       VARCHAR(100) NOT NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_cat_shop_name` (`shop_id`,`name`),
  CONSTRAINT `fk_cat_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `products` (
  `id`              BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`         BIGINT UNSIGNED NOT NULL,
  `category_id`     BIGINT UNSIGNED NULL,
  `name`            VARCHAR(150) NOT NULL,
  `sku`             VARCHAR(64)  NULL,
  `barcode`         VARCHAR(64)  NULL,
  `unit`            VARCHAR(20)  NOT NULL DEFAULT 'pcs',
  `purchase_cost`   DECIMAL(12,2) NOT NULL DEFAULT 0,
  `selling_price`   DECIMAL(12,2) NOT NULL DEFAULT 0,
  `current_stock`   DECIMAL(12,3) NOT NULL DEFAULT 0,
  `min_stock_alert` DECIMAL(12,3) NOT NULL DEFAULT 0,
  `is_available`    TINYINT(1)   NOT NULL DEFAULT 1,
  `notes`           VARCHAR(255) NULL,
  `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_prod_shop_sku` (`shop_id`,`sku`),
  KEY `ix_prod_shop_name` (`shop_id`,`name`),
  KEY `ix_prod_barcode` (`barcode`),
  CONSTRAINT `fk_prod_shop` FOREIGN KEY (`shop_id`)    REFERENCES `shops`(`id`)      ON DELETE CASCADE,
  CONSTRAINT `fk_prod_cat`  FOREIGN KEY (`category_id`) REFERENCES `categories`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_movements` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`    BIGINT UNSIGNED NOT NULL,
  `product_id` BIGINT UNSIGNED NOT NULL,
  `type`       ENUM('opening','purchase','sale','return','adjustment') NOT NULL,
  `qty_change` DECIMAL(12,3) NOT NULL,
  `ref_type`   VARCHAR(40)  NULL,
  `ref_id`     BIGINT UNSIGNED NULL,
  `note`       VARCHAR(255) NULL,
  `created_by` BIGINT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_sm_product_time` (`product_id`,`created_at`),
  KEY `ix_sm_shop_type`    (`shop_id`,`type`),
  CONSTRAINT `fk_sm_shop` FOREIGN KEY (`shop_id`)    REFERENCES `shops`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_sm_prod` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `stock_adjustments` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`      BIGINT UNSIGNED NOT NULL,
  `product_id`   BIGINT UNSIGNED NOT NULL,
  `delta`        DECIMAL(12,3) NOT NULL,
  `reason`       VARCHAR(255) NOT NULL,
  `adjusted_by`  BIGINT UNSIGNED NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_sa_product` (`product_id`,`created_at`),
  CONSTRAINT `fk_sa_shop` FOREIGN KEY (`shop_id`)    REFERENCES `shops`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_sa_prod` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- --- phase6_purchases.sql ---
-- =====================================================================
-- Phase 6 — Suppliers & Purchases
-- Run: mysql -u root smartbilling < database/migrations/phase6_purchases.sql
-- =====================================================================
USE `smartbilling`;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `suppliers` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`          BIGINT UNSIGNED NOT NULL,
  `name`             VARCHAR(150) NOT NULL,
  `phone`            VARCHAR(30)  NULL,
  `email`            VARCHAR(150) NULL,
  `address`          TEXT NULL,
  `business_details` VARCHAR(255) NULL,
  `is_active`        TINYINT(1) NOT NULL DEFAULT 1,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_sup_shop_name` (`shop_id`,`name`),
  CONSTRAINT `fk_sup_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchases` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`        BIGINT UNSIGNED NOT NULL,
  `supplier_id`    BIGINT UNSIGNED NOT NULL,
  `invoice_no`     VARCHAR(64)  NULL,
  `purchase_date`  DATE         NOT NULL,
  `subtotal`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `charges`        DECIMAL(12,2) NOT NULL DEFAULT 0,
  `discount`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `total`          DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status`         ENUM('draft','received','cancelled','reversed') NOT NULL DEFAULT 'draft',
  `received_at`    DATETIME NULL,
  `txn_token`      VARCHAR(80)  NOT NULL,
  `created_by`     BIGINT UNSIGNED NULL,
  `notes`          VARCHAR(500) NULL,
  `created_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`     DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pur_token` (`txn_token`),
  KEY `ix_pur_shop_date` (`shop_id`,`purchase_date`),
  KEY `ix_pur_supplier`  (`supplier_id`),
  CONSTRAINT `fk_pur_shop`     FOREIGN KEY (`shop_id`)     REFERENCES `shops`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_pur_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `purchase_items` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `purchase_id`    BIGINT UNSIGNED NOT NULL,
  `product_id`     BIGINT UNSIGNED NOT NULL,
  `product_name`   VARCHAR(150) NOT NULL,
  `sku_snapshot`   VARCHAR(64) NULL,
  `qty`            DECIMAL(12,3) NOT NULL,
  `purchase_cost`  DECIMAL(12,2) NOT NULL,
  `line_total`     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_pi_purchase` (`purchase_id`),
  CONSTRAINT `fk_pi_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pi_product`  FOREIGN KEY (`product_id`)  REFERENCES `products`(`id`)  ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `supplier_payments` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`      BIGINT UNSIGNED NOT NULL,
  `supplier_id`  BIGINT UNSIGNED NOT NULL,
  `purchase_id`  BIGINT UNSIGNED NULL,
  `amount`       DECIMAL(12,2) NOT NULL,
  `method`       ENUM('cash','upi','bank','other') NOT NULL DEFAULT 'cash',
  `reference`    VARCHAR(100) NULL,
  `paid_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `note`         VARCHAR(255) NULL,
  `created_by`   BIGINT UNSIGNED NULL,
  PRIMARY KEY (`id`),
  KEY `ix_sp_supplier` (`supplier_id`,`paid_at`),
  KEY `ix_sp_purchase` (`purchase_id`),
  CONSTRAINT `fk_sp_shop`     FOREIGN KEY (`shop_id`)     REFERENCES `shops`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_sp_supplier` FOREIGN KEY (`supplier_id`) REFERENCES `suppliers`(`id`) ON DELETE RESTRICT,
  CONSTRAINT `fk_sp_purchase` FOREIGN KEY (`purchase_id`) REFERENCES `purchases`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- --- phase7_sales.sql ---
-- =====================================================================
-- Phase 7 — Customers + Sales (POS)
-- Run: mysql -u root smartbilling < database/migrations/phase7_sales.sql
-- =====================================================================
USE `smartbilling`;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `customers` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`    BIGINT UNSIGNED NOT NULL,
  `name`       VARCHAR(150) NOT NULL,
  `mobile`     VARCHAR(20)  NULL,
  `email`      VARCHAR(150) NULL,
  `address`    TEXT NULL,
  `notes`      VARCHAR(500) NULL,
  `is_active`  TINYINT(1) NOT NULL DEFAULT 1,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_cus_shop_mobile` (`shop_id`,`mobile`),
  KEY `ix_cus_shop_name`   (`shop_id`,`name`),
  CONSTRAINT `fk_cus_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales` (
  `id`          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`     BIGINT UNSIGNED NOT NULL,
  `customer_id` BIGINT UNSIGNED NULL,
  `invoice_no`  VARCHAR(64) NOT NULL,
  `sale_date`   DATETIME NOT NULL,
  `subtotal`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `discount`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `tax`         DECIMAL(12,2) NOT NULL DEFAULT 0,
  `grand_total` DECIMAL(12,2) NOT NULL DEFAULT 0,
  `status`      ENUM('unpaid','partial','paid','returned','cancelled') NOT NULL DEFAULT 'unpaid',
  `txn_token`   VARCHAR(80) NOT NULL,
  `notes`       VARCHAR(500) NULL,
  `created_by`  BIGINT UNSIGNED NULL,
  `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sales_token` (`txn_token`),
  UNIQUE KEY `uq_sales_shop_invoice` (`shop_id`,`invoice_no`),
  KEY `ix_sales_shop_date` (`shop_id`,`sale_date`),
  KEY `ix_sales_customer`  (`customer_id`),
  CONSTRAINT `fk_sales_shop`     FOREIGN KEY (`shop_id`)     REFERENCES `shops`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_sales_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sale_items` (
  `id`             BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sale_id`        BIGINT UNSIGNED NOT NULL,
  `product_id`     BIGINT UNSIGNED NOT NULL,
  `product_name`   VARCHAR(150) NOT NULL,
  `sku_snapshot`   VARCHAR(64) NULL,
  `qty`            DECIMAL(12,3) NOT NULL,
  `unit_price`     DECIMAL(12,2) NOT NULL,
  `discount`       DECIMAL(12,2) NOT NULL DEFAULT 0,
  `tax`            DECIMAL(12,2) NOT NULL DEFAULT 0,
  `line_total`     DECIMAL(12,2) NOT NULL,
  PRIMARY KEY (`id`),
  KEY `ix_si_sale` (`sale_id`),
  CONSTRAINT `fk_si_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales`(`id`)    ON DELETE CASCADE,
  CONSTRAINT `fk_si_product` FOREIGN KEY (`product_id`) REFERENCES `products`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payments` (
  `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`      BIGINT UNSIGNED NOT NULL,
  `customer_id`  BIGINT UNSIGNED NULL,
  `method`       ENUM('cash','upi','other') NOT NULL DEFAULT 'cash',
  `amount`       DECIMAL(12,2) NOT NULL,
  `reference`    VARCHAR(100) NULL,
  `status`       ENUM('pending','verified','failed','reversed') NOT NULL DEFAULT 'verified',
  `collected_by` BIGINT UNSIGNED NULL,
  `paid_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `txn_token`    VARCHAR(80) NOT NULL,
  `note`         VARCHAR(255) NULL,
  `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_pay_token` (`txn_token`),
  KEY `ix_pay_shop_date` (`shop_id`,`paid_at`),
  KEY `ix_pay_customer`  (`customer_id`),
  CONSTRAINT `fk_pay_shop`     FOREIGN KEY (`shop_id`)     REFERENCES `shops`(`id`)     ON DELETE CASCADE,
  CONSTRAINT `fk_pay_customer` FOREIGN KEY (`customer_id`) REFERENCES `customers`(`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `payment_allocations` (
  `id`                BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `payment_id`        BIGINT UNSIGNED NOT NULL,
  `sale_id`           BIGINT UNSIGNED NOT NULL,
  `allocated_amount`  DECIMAL(12,2) NOT NULL,
  `created_at`        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_pa_sale`    (`sale_id`),
  KEY `ix_pa_payment` (`payment_id`),
  CONSTRAINT `fk_pa_payment` FOREIGN KEY (`payment_id`) REFERENCES `payments`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_pa_sale`    FOREIGN KEY (`sale_id`)    REFERENCES `sales`(`id`)    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- --- phase10_returns.sql ---
-- =====================================================================
-- Phase 10 — Returns, Refunds
-- Run: mysql -u root smartbilling < database/migrations/phase10_returns.sql
-- =====================================================================
USE `smartbilling`;

SET FOREIGN_KEY_CHECKS = 0;

CREATE TABLE IF NOT EXISTS `sales_returns` (
  `id`                   BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`              BIGINT UNSIGNED NOT NULL,
  `sale_id`              BIGINT UNSIGNED NOT NULL,
  `return_no`            VARCHAR(64) NOT NULL,
  `reason`               VARCHAR(500) NULL,
  `total_return_amount`  DECIMAL(12,2) NOT NULL DEFAULT 0,
  `credit_to_invoice`    DECIMAL(12,2) NOT NULL DEFAULT 0,
  `cash_refund_amount`   DECIMAL(12,2) NOT NULL DEFAULT 0,
  `credit_payment_id`    BIGINT UNSIGNED NULL,
  `created_by`           BIGINT UNSIGNED NULL,
  `txn_token`            VARCHAR(80) NOT NULL,
  `created_at`           DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  UNIQUE KEY `uq_sr_token`         (`txn_token`),
  UNIQUE KEY `uq_sr_shop_returnno` (`shop_id`,`return_no`),
  KEY `ix_sr_sale` (`sale_id`),
  CONSTRAINT `fk_sr_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sr_sale` FOREIGN KEY (`sale_id`) REFERENCES `sales`(`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `sales_return_items` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `sales_return_id`  BIGINT UNSIGNED NOT NULL,
  `sale_item_id`     BIGINT UNSIGNED NOT NULL,
  `product_id`       BIGINT UNSIGNED NOT NULL,
  `qty_returned`     DECIMAL(12,3) NOT NULL,
  `unit_price`       DECIMAL(12,2) NOT NULL,
  `amount`           DECIMAL(12,2) NOT NULL,
  `stock_condition`  ENUM('sellable','damaged','unavailable') NOT NULL DEFAULT 'sellable',
  PRIMARY KEY (`id`),
  KEY `ix_sri_return`  (`sales_return_id`),
  KEY `ix_sri_item`    (`sale_item_id`),
  KEY `ix_sri_product` (`product_id`),
  CONSTRAINT `fk_sri_return` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns`(`id`) ON DELETE CASCADE,
  CONSTRAINT `fk_sri_item`   FOREIGN KEY (`sale_item_id`)    REFERENCES `sale_items`(`id`)   ON DELETE RESTRICT,
  CONSTRAINT `fk_sri_prod`   FOREIGN KEY (`product_id`)      REFERENCES `products`(`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `refunds` (
  `id`               BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`          BIGINT UNSIGNED NOT NULL,
  `sales_return_id`  BIGINT UNSIGNED NOT NULL,
  `method`           ENUM('cash','upi','bank','other') NOT NULL DEFAULT 'cash',
  `amount`           DECIMAL(12,2) NOT NULL,
  `reference`        VARCHAR(100) NULL,
  `status`           ENUM('pending','verified','failed','reversed') NOT NULL DEFAULT 'verified',
  `created_by`       BIGINT UNSIGNED NULL,
  `created_at`       DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_rf_return` (`sales_return_id`),
  CONSTRAINT `fk_rf_shop`   FOREIGN KEY (`shop_id`)         REFERENCES `shops`(`id`)        ON DELETE CASCADE,
  CONSTRAINT `fk_rf_return` FOREIGN KEY (`sales_return_id`) REFERENCES `sales_returns`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- --- phase11_expenses.sql ---
-- =====================================================================
-- Phase 11 — Expenses
-- =====================================================================
USE `smartbilling`;

CREATE TABLE IF NOT EXISTS `expenses` (
  `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`       BIGINT UNSIGNED NOT NULL,
  `category`      VARCHAR(80) NOT NULL,
  `description`   VARCHAR(255) NULL,
  `amount`        DECIMAL(12,2) NOT NULL,
  `expense_date`  DATE NOT NULL,
  `created_by`    BIGINT UNSIGNED NULL,
  `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_exp_shop_date` (`shop_id`,`expense_date`),
  CONSTRAINT `fk_exp_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;


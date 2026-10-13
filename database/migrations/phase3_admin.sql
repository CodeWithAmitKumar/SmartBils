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

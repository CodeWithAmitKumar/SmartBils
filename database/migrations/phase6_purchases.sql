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

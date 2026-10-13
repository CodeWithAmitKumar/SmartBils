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

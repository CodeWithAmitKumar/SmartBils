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

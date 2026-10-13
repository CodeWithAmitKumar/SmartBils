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

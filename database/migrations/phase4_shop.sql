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

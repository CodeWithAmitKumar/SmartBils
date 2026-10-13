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

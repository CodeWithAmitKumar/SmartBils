-- =====================================================================
-- Smarty — AI assistant tables
-- =====================================================================
USE `smartbilling`;

-- Platform-wide config (singleton row id=1)
CREATE TABLE IF NOT EXISTS `smarty_config` (
  `id`         TINYINT UNSIGNED NOT NULL,
  `provider`   VARCHAR(40) NOT NULL DEFAULT 'gemini',
  `model`      VARCHAR(80) NOT NULL DEFAULT 'gemini-flash-latest',
  `api_key`    VARCHAR(255) NOT NULL DEFAULT '',
  `enabled`    TINYINT(1)   NOT NULL DEFAULT 0,
  `max_tokens` INT UNSIGNED NOT NULL DEFAULT 600,
  `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `smarty_config` (`id`, `provider`, `model`, `api_key`, `enabled`, `max_tokens`)
VALUES (1, 'gemini', 'gemini-flash-latest', '', 0, 600);

-- Per-message audit log (one row per Q&A exchange)
CREATE TABLE IF NOT EXISTS `smarty_log` (
  `id`         BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `shop_id`    BIGINT UNSIGNED NOT NULL,
  `user_id`    BIGINT UNSIGNED NULL,
  `question`   TEXT NOT NULL,
  `answer`     MEDIUMTEXT NULL,
  `ok`         TINYINT(1) NOT NULL DEFAULT 1,
  `error`      VARCHAR(255) NULL,
  `tokens_in`  INT UNSIGNED NULL,
  `tokens_out` INT UNSIGNED NULL,
  `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `ix_smarty_shop_time` (`shop_id`, `created_at`),
  CONSTRAINT `fk_smarty_log_shop` FOREIGN KEY (`shop_id`) REFERENCES `shops`(`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

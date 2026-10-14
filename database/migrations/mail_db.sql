-- =====================================================================
-- Mail config — single row holding Gmail SMTP credentials.
-- Lives in the main `smartbilling` database.
-- Run: phpMyAdmin → smartbilling → SQL tab → paste → Go.
-- =====================================================================
USE `smartbilling`;

CREATE TABLE IF NOT EXISTS `mail_config` (
  `id`           TINYINT UNSIGNED NOT NULL,
  `from_name`    VARCHAR(150) NOT NULL DEFAULT 'SmartBils',
  `from_email`   VARCHAR(150) NOT NULL DEFAULT '',
  `app_password` VARCHAR(255) NOT NULL DEFAULT '',
  `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `mail_config` (`id`, `from_name`, `from_email`, `app_password`)
VALUES (1, 'SmartBils', '', '');

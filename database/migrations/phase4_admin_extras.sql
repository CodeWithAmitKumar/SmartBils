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

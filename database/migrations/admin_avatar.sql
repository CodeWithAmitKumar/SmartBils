USE `smartbilling`;
ALTER TABLE `admins`
  ADD COLUMN `avatar_path` VARCHAR(255) NULL AFTER `email`;

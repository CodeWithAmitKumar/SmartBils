-- Phase 4 prep — shop logo
USE `smartbilling`;
ALTER TABLE `shops`
  ADD COLUMN `logo_path` VARCHAR(255) NULL AFTER `address`;

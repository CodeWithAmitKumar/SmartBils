-- Promised-payment date on partial sales
USE `smartbilling`;
ALTER TABLE `sales`
  ADD COLUMN `due_date` DATE          NULL AFTER `status`,
  ADD COLUMN `due_note` VARCHAR(255)  NULL AFTER `due_date`,
  ADD KEY `ix_sales_due_date` (`shop_id`,`due_date`);

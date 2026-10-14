-- =====================================================================
-- Reset ALL shop-module data (transactional + master)
--
-- KEEPS   : admins, shops, shop_users, shop_user_permissions, permissions,
--           subscription_plans, shop_licenses, license_renewals,
--           login_attempts, password_reset_tokens, app_settings, audit_logs,
--           shop_settings (shop config/branding)
--
-- WIPES   : categories, products, stock_movements, stock_adjustments,
--           customers, suppliers, purchases + items, supplier_payments,
--           sales + items, payments, payment_allocations,
--           sales_returns + items, refunds, expenses
--
-- Run from phpMyAdmin → smartbilling → SQL tab → paste → Go.
-- =====================================================================
USE `smartbilling`;

SET FOREIGN_KEY_CHECKS = 0;

-- Returns & refunds first (depend on sales + payments)
TRUNCATE TABLE `sales_return_items`;
TRUNCATE TABLE `refunds`;
TRUNCATE TABLE `sales_returns`;

-- Payments
TRUNCATE TABLE `payment_allocations`;
TRUNCATE TABLE `payments`;

-- Sales
TRUNCATE TABLE `sale_items`;
TRUNCATE TABLE `sales`;

-- Purchases
TRUNCATE TABLE `purchase_items`;
TRUNCATE TABLE `supplier_payments`;
TRUNCATE TABLE `purchases`;

-- Stock
TRUNCATE TABLE `stock_movements`;
TRUNCATE TABLE `stock_adjustments`;

-- Expenses
TRUNCATE TABLE `expenses`;

-- Masters
TRUNCATE TABLE `customers`;
TRUNCATE TABLE `suppliers`;
TRUNCATE TABLE `products`;
TRUNCATE TABLE `categories`;

SET FOREIGN_KEY_CHECKS = 1;

-- Done. All shop-module records cleared. Auto-increment counters are also reset to 1.

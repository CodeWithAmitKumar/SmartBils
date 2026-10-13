-- =====================================================================
-- Smart Billing Management System — Phase 1 base
-- Creates the database only. Business tables ship per phase (2 onwards).
-- Run from phpMyAdmin or:  mysql -u root < database/schema.sql
-- =====================================================================

CREATE DATABASE IF NOT EXISTS `smartbilling`
  DEFAULT CHARACTER SET utf8mb4
  DEFAULT COLLATE utf8mb4_unicode_ci;

USE `smartbilling`;

-- Phase 1 keeps the schema empty on purpose.
-- Phase 2 adds: users, roles, permissions, role_permissions, user_roles,
-- login_attempts, password_reset_tokens, shops (minimal).

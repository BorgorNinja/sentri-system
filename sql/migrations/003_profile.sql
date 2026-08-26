-- ============================================================
-- SenTri Migration: Profile Customization & Avatar Color
-- Run this ONCE on your existing database.
-- Safe to run even if column already exists (IF NOT EXISTS).
-- ============================================================

-- Add avatar_color column to users (if not already present)
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `avatar_color` VARCHAR(7) NOT NULL DEFAULT '#1c57b2'
 COMMENT 'Hex color for user avatar, e.g. #1c57b2';

-- ============================================================
-- If your MySQL version does not support IF NOT EXISTS:
-- Use the block below instead (compatible with MySQL 5.7+):
-- ============================================================
-- SET @dbname = DATABASE();
-- SET @tname = 'users';
-- SET @cname = 'avatar_color';
-- SET @query = IF(
-- (SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
-- WHERE TABLE_SCHEMA=@dbname AND TABLE_NAME=@tname AND COLUMN_NAME=@cname) = 0,
-- CONCAT('ALTER TABLE `users` ADD COLUMN `avatar_color` VARCHAR(7) NOT NULL DEFAULT ''#1c57b2'''),
-- 'SELECT 1'
-- );
-- PREPARE stmt FROM @query;
-- EXECUTE stmt;
-- DEALLOCATE PREPARE stmt;

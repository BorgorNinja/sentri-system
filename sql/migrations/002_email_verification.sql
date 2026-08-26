-- ============================================================
-- SenTri Migration: Email Verification & Password Reset
-- Run this ONCE on your existing database.
-- Safe to run repeatedly (uses IF NOT EXISTS).
-- ============================================================

USE `sentri`;

-- Email verified flag (0 = pending, 1 = verified)
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `email_verified` TINYINT(1) NOT NULL DEFAULT 0
 COMMENT '0 = awaiting verification, 1 = verified'
 AFTER `role`;

-- Email verification token (64-char hex, NULL after verified)
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `verification_token` VARCHAR(64) DEFAULT NULL
 COMMENT 'SHA256 hex token sent in verification email'
 AFTER `email_verified`;

-- Verification token expiry (24 hours from signup)
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `token_expires_at` DATETIME DEFAULT NULL
 COMMENT 'When verification_token expires'
 AFTER `verification_token`;

-- Password reset token (64-char hex, NULL when not active)
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `reset_token` VARCHAR(64) DEFAULT NULL
 COMMENT 'Password reset token'
 AFTER `token_expires_at`;

-- Password reset token expiry (1 hour from request)
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `reset_token_expires` DATETIME DEFAULT NULL
 COMMENT 'When reset_token expires'
 AFTER `reset_token`;

-- Index for fast token lookups
ALTER TABLE `users`
 ADD INDEX IF NOT EXISTS `idx_verification_token` (`verification_token`),
 ADD INDEX IF NOT EXISTS `idx_reset_token` (`reset_token`);

-- ============================================================
-- IMPORTANT: Mark ALL existing users as already verified
-- so their accounts are not suddenly locked out.
-- ============================================================
UPDATE `users` SET `email_verified` = 1
WHERE `email_verified` = 0 AND `created_at` < NOW();

-- ============================================================
-- Verify columns were added:
-- SHOW COLUMNS FROM users;
-- ============================================================

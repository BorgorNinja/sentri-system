-- Migration 006: LGU/Barangay Role Expansion
-- Adds new role types and affiliation columns to users table.
-- Run after 005_emergency_contacts.sql

USE `sentri`;

-- Expand role enum to include all portal types
ALTER TABLE `users`
 MODIFY COLUMN `role` enum('community','barangay','lgu','first_responder','admin')
 NOT NULL DEFAULT 'community';

-- Rename legacy 'user' entries to 'community'
UPDATE `users` SET `role` = 'community' WHERE `role` = 'user';

-- Affiliation columns for official roles
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `org_name` varchar(255) DEFAULT NULL COMMENT 'Office/unit name e.g. Brgy. Malagasang I-A' AFTER `phone_number`,
 ADD COLUMN IF NOT EXISTS `position` varchar(150) DEFAULT NULL COMMENT 'e.g. Barangay Captain, BFP Officer' AFTER `org_name`,
 ADD COLUMN IF NOT EXISTS `barangay_name` varchar(150) DEFAULT NULL COMMENT 'Assigned barangay jurisdiction' AFTER `position`,
 ADD COLUMN IF NOT EXISTS `municipality` varchar(150) DEFAULT NULL COMMENT 'City/municipality jurisdiction' AFTER `barangay_name`,
 ADD COLUMN IF NOT EXISTS `is_approved` tinyint(1) NOT NULL DEFAULT 0 COMMENT 'Admin must approve official accounts' AFTER `municipality`;

-- Community users are auto-approved; existing users stay approved
UPDATE `users` SET `is_approved` = 1 WHERE `role` = 'community';
UPDATE `users` SET `is_approved` = 1 WHERE `role` = 'admin';

-- Add responder_type to first_responder accounts
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `responder_type` varchar(30) DEFAULT NULL AFTER `is_approved`;

-- Index for role-based queries
ALTER TABLE `users`
 ADD INDEX IF NOT EXISTS `idx_role` (`role`),
 ADD INDEX IF NOT EXISTS `idx_barangay` (`barangay_name`);

-- Add assigned_to on reports for dispatch to responders
ALTER TABLE `reports`
 ADD COLUMN IF NOT EXISTS `assigned_to` int(11) DEFAULT NULL COMMENT 'user_id of assigned responder' AFTER `is_archived`,
 ADD COLUMN IF NOT EXISTS `resolved_at` timestamp DEFAULT NULL AFTER `assigned_to`;

ALTER TABLE `reports`
 ADD INDEX IF NOT EXISTS `idx_assigned` (`assigned_to`);

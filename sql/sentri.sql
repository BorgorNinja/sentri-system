-- SenTri Database Schema - Full fresh-install schema
-- Matches install.php as of 2026-08-05
-- Run this in phpMyAdmin or MySQL CLI for a fresh install.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `sentri` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `sentri`;

-- Users
CREATE TABLE `users` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `first_name` VARCHAR(100) NOT NULL,
 `last_name` VARCHAR(100) NOT NULL,
 `email` VARCHAR(191) NOT NULL,
 `phone_number` VARCHAR(30) DEFAULT NULL,
 `password` VARCHAR(255) NOT NULL,
 `role` ENUM('user','community','barangay','lgu','first_responder','admin')
 NOT NULL DEFAULT 'community',
 `org_name` VARCHAR(255) DEFAULT NULL,
 `position` VARCHAR(150) DEFAULT NULL,
 `barangay_name` VARCHAR(150) DEFAULT NULL,
 `municipality` VARCHAR(150) DEFAULT NULL,
 `responder_type` VARCHAR(30) DEFAULT NULL,
 `is_approved` TINYINT(1) NOT NULL DEFAULT 0,
 `email_verified` TINYINT(1) NOT NULL DEFAULT 0,
 `verification_token` VARCHAR(64) DEFAULT NULL,
 `token_expires_at` DATETIME DEFAULT NULL,
 `reset_token` VARCHAR(64) DEFAULT NULL,
 `reset_token_expires` DATETIME DEFAULT NULL,
 `avatar_color` VARCHAR(7) NOT NULL DEFAULT '#1c57b2',
 `gps_lat` DECIMAL(10,7) DEFAULT NULL,
 `gps_lng` DECIMAL(10,7) DEFAULT NULL,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 UNIQUE KEY `uq_email` (`email`),
 KEY `idx_reset_token` (`reset_token`),
 KEY `idx_verification_token` (`verification_token`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Reports (with geolocation and dispatch fields)
CREATE TABLE `reports` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `user_id` INT(11) NOT NULL,
 `title` VARCHAR(255) NOT NULL,
 `description` TEXT NOT NULL,
 `location_name` VARCHAR(255) NOT NULL,
 `barangay` VARCHAR(150) DEFAULT NULL,
 `city` VARCHAR(150) NOT NULL,
 `province` VARCHAR(150) DEFAULT NULL,
 `latitude` DECIMAL(10,7) DEFAULT NULL COMMENT 'Pinned latitude',
 `longitude` DECIMAL(10,7) DEFAULT NULL COMMENT 'Pinned longitude',
 `radius_m` INT(11) DEFAULT 200 COMMENT 'Affected area radius in metres',
 `status` ENUM('dangerous','caution','safe') NOT NULL DEFAULT 'caution',
 `category` ENUM('crime','accident','flooding','fire','health','infrastructure','other') NOT NULL DEFAULT 'other',
 `upvotes` INT(11) NOT NULL DEFAULT 0,
 `downvotes` INT(11) NOT NULL DEFAULT 0,
 `is_archived` TINYINT(1) NOT NULL DEFAULT 0,
 `assigned_to` INT(11) DEFAULT NULL COMMENT 'user_id of assigned responder',
 `accepted_at` DATETIME DEFAULT NULL,
 `responded_at` DATETIME DEFAULT NULL,
 `resolved_at` DATETIME DEFAULT NULL,
 `escalated_to_lgu` TINYINT(1) NOT NULL DEFAULT 0,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 KEY `idx_user_id` (`user_id`),
 KEY `idx_status` (`status`),
 KEY `idx_city` (`city`),
 KEY `idx_is_archived` (`is_archived`),
 KEY `idx_lat_lng` (`latitude`, `longitude`),
 KEY `idx_assigned` (`assigned_to`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Report images
CREATE TABLE `report_images` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `report_id` INT(11) NOT NULL,
 `file_name` VARCHAR(255) NOT NULL,
 `original_name` VARCHAR(255) DEFAULT NULL,
 `mime_type` VARCHAR(100) DEFAULT NULL,
 `file_size` INT(11) DEFAULT NULL,
 `uploaded_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 KEY `report_id` (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Report audit logs
CREATE TABLE `report_audit_logs` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `report_id` INT(11) NOT NULL,
 `report_title` VARCHAR(255) NOT NULL,
 `action` VARCHAR(40) NOT NULL,
 `performed_by` INT(11) DEFAULT NULL,
 `performed_by_name` VARCHAR(150) NOT NULL,
 `performed_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 KEY `report_id` (`report_id`),
 KEY `performed_by` (`performed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Report votes
CREATE TABLE `report_votes` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `report_id` INT(11) NOT NULL,
 `user_id` INT(11) NOT NULL,
 `vote` ENUM('up','down') NOT NULL,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 UNIQUE KEY `unique_vote` (`report_id`, `user_id`),
 KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Emergency contacts
CREATE TABLE `emergency_contacts` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `name` VARCHAR(255) NOT NULL,
 `type` ENUM('lgu','hospital','traffic','barangay','police','fire','other') NOT NULL DEFAULT 'other',
 `barangay` VARCHAR(150) DEFAULT NULL,
 `city` VARCHAR(150) NOT NULL,
 `province` VARCHAR(150) DEFAULT NULL,
 `contact_number` VARCHAR(50) DEFAULT NULL,
 `contact_email` VARCHAR(191) DEFAULT NULL,
 `is_active` TINYINT(1) NOT NULL DEFAULT 1,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `updated_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 KEY `idx_city` (`city`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Contact notification log
CREATE TABLE `contact_notifications` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `report_id` INT(11) NOT NULL,
 `contact_id` INT(11) NOT NULL,
 `method` ENUM('email','sms','auto_call') NOT NULL DEFAULT 'email',
 `status` ENUM('sent','failed','pending') NOT NULL DEFAULT 'pending',
 `sent_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 KEY `report_id` (`report_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Login logs
CREATE TABLE `login_logs` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `user_id` INT(11) DEFAULT NULL,
 `email` VARCHAR(191) NOT NULL,
 `ip_address` VARCHAR(100) DEFAULT NULL,
 `device` TEXT DEFAULT NULL,
 `status` ENUM('Success','Failed','Locked') NOT NULL,
 `created_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 PRIMARY KEY (`id`),
 KEY `idx_email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Flagged accounts
CREATE TABLE `flagged_accounts` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `user_id` INT(11) NOT NULL,
 `risk_level` ENUM('low','medium','high') NOT NULL DEFAULT 'medium',
 `failed_count` INT(11) NOT NULL DEFAULT 0,
 `flagged_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `last_attempt` TIMESTAMP NULL DEFAULT NULL,
 `notes` TEXT DEFAULT NULL,
 `reviewed` TINYINT(1) NOT NULL DEFAULT 0,
 PRIMARY KEY (`id`),
 KEY `user_id` (`user_id`),
 KEY `risk_level` (`risk_level`),
 KEY `flagged_at` (`flagged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Security scans
CREATE TABLE `security_scans` (
 `id` INT(11) NOT NULL AUTO_INCREMENT,
 `scanned_at` TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
 `https_status` ENUM('passed','warning','critical') NOT NULL,
 `session_status` ENUM('passed','warning','critical') NOT NULL,
 `password_hash_status` ENUM('passed','warning','critical') NOT NULL,
 `security_headers_status` ENUM('passed','warning','critical') NOT NULL,
 `upload_restrictions_status` ENUM('passed','warning','critical') NOT NULL,
 `score` INT(11) NOT NULL DEFAULT 0,
 `details` TEXT DEFAULT NULL,
 PRIMARY KEY (`id`),
 KEY `idx_scanned_at` (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Foreign keys
SET FOREIGN_KEY_CHECKS = 0;
ALTER TABLE `reports`
 ADD CONSTRAINT `fk_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `report_images`
 ADD CONSTRAINT `fk_images_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE;
ALTER TABLE `report_votes`
 ADD CONSTRAINT `fk_votes_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
 ADD CONSTRAINT `fk_votes_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `report_audit_logs`
 ADD CONSTRAINT `fk_audit_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
 ADD CONSTRAINT `fk_audit_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
ALTER TABLE `flagged_accounts`
 ADD CONSTRAINT `fk_flagged_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
SET FOREIGN_KEY_CHECKS = 1;

COMMIT;

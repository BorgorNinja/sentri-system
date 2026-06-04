-- SenTri Database Schema (with OSM geolocation support)
-- Run this in phpMyAdmin or MySQL CLI for a fresh install.
-- For existing installs, run map_migration.sql instead.

SET SQL_MODE = "NO_AUTO_VALUE_ON_ZERO";
START TRANSACTION;
SET time_zone = "+00:00";

CREATE DATABASE IF NOT EXISTS `sentri` DEFAULT CHARACTER SET utf8mb4 COLLATE utf8mb4_general_ci;
USE `sentri`;

-- Users
CREATE TABLE `users` (
  `id`         int(11)                       NOT NULL AUTO_INCREMENT,
  `first_name` varchar(100)                  NOT NULL,
  `last_name`  varchar(100)                  NOT NULL,
  `email`      varchar(191)                  NOT NULL,
  `password`   varchar(255)                  NOT NULL,
  `role`       enum('user','admin')          NOT NULL DEFAULT 'user',
  `created_at` timestamp                     NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `email` (`email`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Reports (with geolocation)
CREATE TABLE `reports` (
  `id`            int(11)                                                                  NOT NULL AUTO_INCREMENT,
  `user_id`       int(11)                                                                  NOT NULL,
  `title`         varchar(255)                                                             NOT NULL,
  `description`   text                                                                     NOT NULL,
  `location_name` varchar(255)                                                             NOT NULL,
  `barangay`      varchar(150)                                                             DEFAULT NULL,
  `city`          varchar(150)                                                             NOT NULL,
  `province`      varchar(150)                                                             DEFAULT NULL,
  `latitude`      DECIMAL(10,7)                                                            DEFAULT NULL COMMENT 'Pinned latitude',
  `longitude`     DECIMAL(10,7)                                                            DEFAULT NULL COMMENT 'Pinned longitude',
  `radius_m`      INT(11)                                                                  DEFAULT 200  COMMENT 'Affected area radius in metres',
  `status`        enum('dangerous','caution','safe')                                       NOT NULL DEFAULT 'caution',
  `category`      enum('crime','accident','flooding','fire','health','infrastructure','other') NOT NULL DEFAULT 'other',
  `upvotes`       int(11)                                                                  NOT NULL DEFAULT 0,
  `downvotes`     int(11)                                                                  NOT NULL DEFAULT 0,
  `is_archived`   tinyint(1)                                                               NOT NULL DEFAULT 0,
  `created_at`    timestamp                                                                NOT NULL DEFAULT current_timestamp(),
  `updated_at`    timestamp                                                                NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `user_id`     (`user_id`),
  KEY `status`      (`status`),
  KEY `city`        (`city`),
  KEY `idx_lat_lng` (`latitude`, `longitude`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Report Audit Logs (for archiving/restoring reports)
CREATE TABLE `report_audit_logs` (
  `id`                int(11)                              NOT NULL AUTO_INCREMENT,
  `report_id`         int(11)                              NOT NULL,
  `report_title`      varchar(255)                         NOT NULL,
  `action`            enum('archived', 'restored')         NOT NULL,
  `performed_by`      int(11)                              DEFAULT NULL,
  `performed_by_name` varchar(150)                         NOT NULL,
  `performed_at`      timestamp                            NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  KEY `report_id`     (`report_id`),
  KEY `performed_by`  (`performed_by`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Flagged Account Logs
CREATE TABLE `flagged_accounts` (
  `id`           int(11)       NOT NULL AUTO_INCREMENT,
  `user_id`      int(11)       NOT NULL,
  `risk_level`   enum('low','medium','high') NOT NULL DEFAULT 'medium',
  `failed_count` int(11)       NOT NULL DEFAULT 0,
  `flagged_at`   timestamp     NOT NULL DEFAULT current_timestamp(),
  `last_attempt` timestamp     NULL DEFAULT NULL,
  `notes`        text          DEFAULT NULL,
  `reviewed`     tinyint(1)    NOT NULL DEFAULT 0,
  PRIMARY KEY (`id`),
  KEY `user_id`      (`user_id`),
  KEY `risk_level`   (`risk_level`),
  KEY `flagged_at`   (`flagged_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;


-- Votes
CREATE TABLE `report_votes` (
  `id`        int(11)           NOT NULL AUTO_INCREMENT,
  `report_id` int(11)           NOT NULL,
  `user_id`   int(11)           NOT NULL,
  `vote`      enum('up','down') NOT NULL,
  `created_at` timestamp        NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`),
  UNIQUE KEY `unique_vote` (`report_id`, `user_id`),
  KEY `user_id` (`user_id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Login logs
CREATE TABLE `login_logs` (
  `id`         int(11)                   NOT NULL AUTO_INCREMENT,
  `user_id`    int(11)                   DEFAULT NULL,
  `email`      varchar(191)              NOT NULL,
  `ip_address` varchar(100)              DEFAULT NULL,
  `device`     text                      DEFAULT NULL,
  `status`     enum('Success','Failed')  NOT NULL,
  `created_at` timestamp                 NOT NULL DEFAULT current_timestamp(),
  PRIMARY KEY (`id`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Foreign keys
ALTER TABLE `reports`
  ADD CONSTRAINT `fk_reports_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;
ALTER TABLE `report_votes`
  ADD CONSTRAINT `fk_votes_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_votes_user`   FOREIGN KEY (`user_id`)   REFERENCES `users`   (`id`) ON DELETE CASCADE;
  ALTER TABLE `report_audit_logs`
  ADD CONSTRAINT `fk_audit_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
  ADD CONSTRAINT `fk_audit_performed_by` FOREIGN KEY (`performed_by`) REFERENCES `users` (`id`) ON DELETE SET NULL;
  ALTER TABLE `flagged_accounts`
  ADD CONSTRAINT `fk_flagged_user` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE;

-- Default admin (password: Admin@1234)
INSERT INTO `users` (`first_name`, `last_name`, `email`, `password`, `role`)
VALUES ('SenTri', 'Admin', 'admin@sentri.ph', '$2y$12$wUE6YIEqd5TFkOvFhKHYeOyjjzOfTJxEUxmajftyZW5CQvJXJwzKq', 'admin');

COMMIT;

-- Migration 004: Report Images
-- Adds photo attachment support to incident reports.
-- Run AFTER sentri.sql (fresh install) or on top of any existing install.

USE `sentri`;

CREATE TABLE IF NOT EXISTS `report_images` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `report_id` int(11) NOT NULL,
 `file_name` varchar(255) NOT NULL COMMENT 'Stored filename on disk',
 `original_name` varchar(255) DEFAULT NULL COMMENT 'Original filename from user',
 `mime_type` varchar(100) DEFAULT NULL,
 `file_size` int(11) DEFAULT NULL COMMENT 'Bytes',
 `uploaded_at` timestamp NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `report_id` (`report_id`),
 CONSTRAINT `fk_images_report` FOREIGN KEY (`report_id`)
 REFERENCES `reports` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

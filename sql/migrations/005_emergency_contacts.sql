-- Migration 005: Emergency Contacts & Responder Routing
-- Adds the emergency_contacts directory and phone_number field on users.
-- Implements client requests: LGU, Hospital, Traffic Mgt. Office contacts
-- with barangay-level routing so reports auto-notify the right office.

USE `sentri`;

-- Emergency responder directory (admin-managed)
CREATE TABLE IF NOT EXISTS `emergency_contacts` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `name` varchar(255) NOT NULL COMMENT 'Office/agency name',
 `type` enum('lgu','hospital','traffic','barangay','police','fire','other')
 NOT NULL DEFAULT 'other',
 `barangay` varchar(150) DEFAULT NULL COMMENT 'Leave NULL = city-wide coverage',
 `city` varchar(150) NOT NULL,
 `province` varchar(150) DEFAULT NULL,
 `contact_number` varchar(50) DEFAULT NULL,
 `contact_email` varchar(191) DEFAULT NULL,
 `is_active` tinyint(1) NOT NULL DEFAULT 1,
 `created_at` timestamp NOT NULL DEFAULT current_timestamp(),
 `updated_at` timestamp NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `idx_city_barangay` (`city`, `barangay`),
 KEY `type` (`type`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Notification log: which contacts were notified for which report
CREATE TABLE IF NOT EXISTS `contact_notifications` (
 `id` int(11) NOT NULL AUTO_INCREMENT,
 `report_id` int(11) NOT NULL,
 `contact_id` int(11) NOT NULL,
 `method` enum('email','sms','auto_call') NOT NULL DEFAULT 'email',
 `status` enum('sent','failed','pending') NOT NULL DEFAULT 'pending',
 `sent_at` timestamp NOT NULL DEFAULT current_timestamp(),
 PRIMARY KEY (`id`),
 KEY `report_id` (`report_id`),
 KEY `contact_id` (`contact_id`),
 CONSTRAINT `fk_notif_report` FOREIGN KEY (`report_id`) REFERENCES `reports` (`id`) ON DELETE CASCADE,
 CONSTRAINT `fk_notif_contact` FOREIGN KEY (`contact_id`) REFERENCES `emergency_contacts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

-- Add phone_number column to users (for future auto-call feature)
ALTER TABLE `users`
 ADD COLUMN IF NOT EXISTS `phone_number` varchar(30) DEFAULT NULL AFTER `email`;

-- Sample seed data (Imus, Cavite - matches the university location)
INSERT IGNORE INTO `emergency_contacts` (`name`, `type`, `barangay`, `city`, `province`, `contact_number`, `contact_email`) VALUES
('Imus City Disaster Risk Reduction Office', 'lgu', NULL, 'Imus', 'Cavite', '(046) 471-0100', 'drrmo@imus.gov.ph'),
('Imus City Health Office', 'lgu', NULL, 'Imus', 'Cavite', '(046) 471-0101', 'health@imus.gov.ph'),
('Ospital ng Imus', 'hospital', NULL, 'Imus', 'Cavite', '(046) 471-9999', 'ospital@imus.gov.ph'),
('Imus Traffic Management Office', 'traffic', NULL, 'Imus', 'Cavite', '(046) 471-0200', 'traffic@imus.gov.ph'),
('BFP Imus City Fire Station', 'fire', NULL, 'Imus', 'Cavite', '(046) 471-1166', 'bfp.imus@gmail.com'),
('PNP Imus City Police Station', 'police', NULL, 'Imus', 'Cavite', '(046) 471-0800', 'pnp.imus@gmail.com');

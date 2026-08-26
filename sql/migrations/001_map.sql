-- SenTri OSM Map Migration
-- Run this on your EXISTING database to add geolocation support.
-- If setting up fresh, use sentri.sql instead (already includes these columns).

USE `sentri`;

-- Add geolocation columns if they don't exist yet
ALTER TABLE `reports`
 ADD COLUMN IF NOT EXISTS `latitude` DECIMAL(10,7) DEFAULT NULL COMMENT 'Pinned latitude' AFTER `province`,
 ADD COLUMN IF NOT EXISTS `longitude` DECIMAL(10,7) DEFAULT NULL COMMENT 'Pinned longitude' AFTER `latitude`,
 ADD COLUMN IF NOT EXISTS `radius_m` INT(11) DEFAULT 200 COMMENT 'Affected area radius in metres' AFTER `longitude`;

-- Index for spatial queries
ALTER TABLE `reports`
 ADD INDEX IF NOT EXISTS `idx_lat_lng` (`latitude`, `longitude`);

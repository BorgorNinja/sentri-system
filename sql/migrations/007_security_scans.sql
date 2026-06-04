-- Creates the security scan history table used by the admin vulnerability assessment feature.
CREATE TABLE IF NOT EXISTS `security_scans` (
    `id`                        INT(11)      NOT NULL AUTO_INCREMENT,
    `scanned_at`                TIMESTAMP    NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `https_status`              ENUM('passed','warning','critical') NOT NULL,
    `session_status`            ENUM('passed','warning','critical') NOT NULL,
    `password_hash_status`      ENUM('passed','warning','critical') NOT NULL,
    `security_headers_status`   ENUM('passed','warning','critical') NOT NULL,
    `upload_restrictions_status` ENUM('passed','warning','critical') NOT NULL,
    `score`                     INT(11)      NOT NULL DEFAULT 0,
    `details`                   TEXT         DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_scanned_at`        (`scanned_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci;

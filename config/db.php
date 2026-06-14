<?php
// Bootstrap security headers before any output or redirect.
require_once __DIR__ . '/headers.php';

$servername = "localhost";
$db_user    = "root";
$db_pass    = "";
$dbname     = "sentri";

// Try Unix socket first, fallback to TCP
$conn = @new mysqli(null, $db_user, $db_pass, $dbname, 3306, '/var/run/mysqld/mysqld.sock');
if ($conn->connect_error) {
    $conn = new mysqli($servername, $db_user, $db_pass, $dbname);
}
if ($conn->connect_error) {
    error_log("SenTri DB Error: " . $conn->connect_error);
    die(json_encode(['status'=>'error','message'=>'Database connection failed.']));
}
$conn->set_charset("utf8mb4");

if (!function_exists('sentri_table_has_column')) {
    function sentri_table_has_column(mysqli $conn, string $table, string $column): bool {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }

        $schema = null;
        if ($dbRes = $conn->query("SELECT DATABASE()")) {
            $dbRow = $dbRes->fetch_row();
            $schema = $dbRow[0] ?? null;
            $dbRes->free();
        }
        if (!$schema) {
            return $cache[$key] = false;
        }

        $stmt = $conn->prepare(
            "SELECT 1
             FROM INFORMATION_SCHEMA.COLUMNS
             WHERE TABLE_SCHEMA = ?
               AND TABLE_NAME = ?
               AND COLUMN_NAME = ?
             LIMIT 1"
        );
        if (!$stmt) {
            return $cache[$key] = false;
        }
        $stmt->bind_param('sss', $schema, $table, $column);
        $stmt->execute();
        $stmt->store_result();
        $exists = $stmt->num_rows > 0;
        $stmt->close();

        return $cache[$key] = $exists;
    }
}

if (!function_exists('sentri_ensure_report_dispatch_schema')) {
    function sentri_ensure_report_dispatch_schema(mysqli $conn): void {
        if (!sentri_table_has_column($conn, 'reports', 'assigned_to')) {
            if (!$conn->query("ALTER TABLE `reports`
                ADD COLUMN `assigned_to` INT(11) DEFAULT NULL COMMENT 'user_id of assigned responder' AFTER `is_archived`")) {
                error_log('SenTri DB migration error (reports.assigned_to): ' . $conn->error);
            }
        }

        if (!sentri_table_has_column($conn, 'reports', 'accepted_at')) {
            if (!$conn->query("ALTER TABLE `reports`
                ADD COLUMN `accepted_at` DATETIME DEFAULT NULL AFTER `assigned_to`")) {
                error_log('SenTri DB migration error (reports.accepted_at): ' . $conn->error);
            }
        }

        if (!sentri_table_has_column($conn, 'reports', 'responded_at')) {
            if (!$conn->query("ALTER TABLE `reports`
                ADD COLUMN `responded_at` DATETIME DEFAULT NULL AFTER `accepted_at`")) {
                error_log('SenTri DB migration error (reports.responded_at): ' . $conn->error);
            }
        }

        if (!sentri_table_has_column($conn, 'reports', 'resolved_at')) {
            if (!$conn->query("ALTER TABLE `reports`
                ADD COLUMN `resolved_at` DATETIME DEFAULT NULL AFTER `responded_at`")) {
                error_log('SenTri DB migration error (reports.resolved_at): ' . $conn->error);
            }
        }

        $hasIndex = false;
        if ($idx = $conn->query("SHOW INDEX FROM `reports` WHERE Key_name = 'idx_assigned'")) {
            $hasIndex = $idx->num_rows > 0;
            $idx->free();
        }
        if (!$hasIndex && sentri_table_has_column($conn, 'reports', 'assigned_to')) {
            if (!$conn->query("ALTER TABLE `reports` ADD INDEX `idx_assigned` (`assigned_to`)")) {
                error_log('SenTri DB migration error (reports.idx_assigned): ' . $conn->error);
            }
        }
    }
}

if (!function_exists('sentri_ensure_audit_log_schema')) {
    function sentri_ensure_audit_log_schema(mysqli $conn): void {
        if (sentri_table_has_column($conn, 'report_audit_logs', 'action')) {
            $schema = null;
            if ($dbRes = $conn->query("SELECT DATABASE()")) {
                $dbRow = $dbRes->fetch_row();
                $schema = $dbRow[0] ?? null;
                $dbRes->free();
            }
            if ($schema) {
                $stmt = $conn->prepare(
                    "SELECT DATA_TYPE
                     FROM INFORMATION_SCHEMA.COLUMNS
                     WHERE TABLE_SCHEMA = ?
                       AND TABLE_NAME = 'report_audit_logs'
                       AND COLUMN_NAME = 'action'
                     LIMIT 1"
                );
                if ($stmt) {
                    $stmt->bind_param('s', $schema);
                    $stmt->execute();
                    $stmt->bind_result($dataType);
                    $stmt->fetch();
                    $stmt->close();
                    if (strtolower((string)$dataType) !== 'varchar') {
                        if (!$conn->query("ALTER TABLE `report_audit_logs` MODIFY `action` VARCHAR(40) NOT NULL")) {
                            error_log('SenTri DB migration error (report_audit_logs.action): ' . $conn->error);
                        }
                    }
                }
            }
        }
    }
}

sentri_ensure_report_dispatch_schema($conn);
sentri_ensure_audit_log_schema($conn);

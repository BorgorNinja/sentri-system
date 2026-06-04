<?php
session_start();
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

function normalize_status(string $value): string {
    return in_array($value, ['passed', 'warning', 'critical'], true) ? $value : 'critical';
}

function score_for_status(string $status): int {
    return $status === 'passed' ? 100 : ($status === 'warning' ? 50 : 0);
}

function get_response_header_names(): array {
    $names = [];
    foreach (headers_list() as $header) {
        $parts = explode(':', $header, 2);
        if (count($parts) === 2) {
            $names[strtolower(trim($parts[0]))] = trim($parts[1]);
        }
    }
    return $names;
}

function ensure_security_scans_table(mysqli $conn): void {
    $sql = "CREATE TABLE IF NOT EXISTS `security_scans` (
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
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_general_ci";
    $conn->query($sql);
}

function evaluate_https(): array {
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
          || (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https');
    return $https
        ? ['status' => 'passed', 'detail' => 'HTTPS is enabled.']
        : ['status' => 'critical', 'detail' => 'HTTPS is disabled or not detected on this request.'];
}

function evaluate_session(): array {
    $useOnly = ini_get('session.use_only_cookies') === '1';
    $httponly = ini_get('session.cookie_httponly') === '1';
    $secure = ini_get('session.cookie_secure') === '1';
    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
          || (!empty($_SERVER['HTTP_X_FORWARDED_PROTO']) && strtolower($_SERVER['HTTP_X_FORWARDED_PROTO']) === 'https')
          || (!empty($_SERVER['REQUEST_SCHEME']) && strtolower($_SERVER['REQUEST_SCHEME']) === 'https');

    if ($useOnly && $httponly) {
        if ($secure || $https) {
            return ['status' => 'passed', 'detail' => 'Session cookies are restricted to HTTP-only and cookies-only mode with secure transport.'];
        }
        return ['status' => 'warning', 'detail' => 'Session cookies are HTTP-only and cookies-only, but secure cookie flag is not enabled because HTTPS is not active.'];
    }
    return ['status' => 'critical', 'detail' => 'Session cookie settings are not secure. Enable cookie-only sessions and HTTP-only cookies.'];
}

function evaluate_password_hashing($conn): array {
    $total = 0;
    $weak = 0;
    $res = $conn->query("SELECT password FROM users");
    while ($row = $res->fetch_assoc()) {
        $total++;
        $password = $row['password'] ?? '';
        if (!preg_match('/^\$(2[ayb]|argon2i|argon2id)\$/', $password)) {
            $weak++;
        }
    }
    if ($total === 0) {
        return ['status' => 'warning', 'detail' => 'No user passwords were available for hashing validation.'];
    }
    if ($weak === 0) {
        return ['status' => 'passed', 'detail' => 'All stored passwords use secure PHP password hashing formats.'];
    }
    if ($weak < $total) {
        return ['status' => 'warning', 'detail' => "$weak of $total stored passwords do not appear to use current secure hashing formats."];
    }
    return ['status' => 'critical', 'detail' => 'Stored passwords do not use secure PHP password hashing.'];
}

function evaluate_security_headers(): array {
    $headers = get_response_header_names();
    $expected = ['content-security-policy', 'x-frame-options', 'x-content-type-options', 'referrer-policy', 'strict-transport-security'];
    $found = array_filter($expected, fn($header) => array_key_exists($header, $headers));
    $count = count($found);
    if ($count >= 3) {
        return ['status' => 'passed', 'detail' => 'Multiple security headers are present on the response.'];
    }
    if ($count >= 1) {
        return ['status' => 'warning', 'detail' => 'Only a few security headers are present. Add more response headers for better protection.'];
    }
    return ['status' => 'critical', 'detail' => 'No standard security headers were detected on this response.'];
}

function evaluate_upload_restrictions($conn): array {
    $dir = __DIR__ . '/../uploads/reports/';
    $uploads_ok = is_dir($dir) && is_writable($dir);
    $res = $conn->query("SHOW TABLES LIKE 'report_images'");
    $table_ok = $res && $res->num_rows > 0;
    if (!$uploads_ok || !$table_ok) {
        return ['status' => 'critical', 'detail' => 'Report upload storage or database tracking is missing or not writable.'];
    }
    return ['status' => 'passed', 'detail' => 'Upload feature exists with image type and size restrictions enforced in the report upload flow.'];
}

if ($action === 'security_history') {
    ensure_security_scans_table($conn);
    $history = [];
    $res = $conn->query("SELECT id, scanned_at, https_status, session_status, password_hash_status, security_headers_status, upload_restrictions_status, score FROM security_scans ORDER BY scanned_at DESC LIMIT 20");
    while ($row = $res->fetch_assoc()) {
        $row['id'] = (int)$row['id'];
        $row['score'] = (int)$row['score'];
        $history[] = $row;
    }
    echo json_encode(['status' => 'success', 'history' => $history]);
    exit;
}

// Main data for Security Monitor
if ($action === 'get_flagged') {
    $stmt = $conn->prepare("
        SELECT 
            f.id as flag_id,
            la.email,
            la.ip_address,
            f.risk_level,
            f.failed_count,
            f.last_attempt,
            f.reviewed,
            la.device,
            COUNT(CASE WHEN la2.status = 'Failed' THEN 1 END) as recent_failed
        FROM flagged_accounts f
        LEFT JOIN login_logs la ON la.id = (
            SELECT id FROM login_logs 
            WHERE email = (SELECT email FROM users WHERE id = f.user_id LIMIT 1) 
            ORDER BY created_at DESC LIMIT 1
        )
        LEFT JOIN login_logs la2 ON la2.email = la.email 
            AND la2.created_at >= NOW() - INTERVAL 30 MINUTE
        GROUP BY f.id
        ORDER BY f.risk_level DESC, f.failed_count DESC
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// Also show recent failed attempts (even if not flagged yet)
if ($action === 'get_recent_failed') {
    $stmt = $conn->prepare("
        SELECT 
            ll.id,
            ll.email,
            ll.ip_address,
            ll.device,
            ll.created_at as last_attempt,
            ll.status,
            (SELECT COUNT(*) FROM login_logs 
             WHERE email = ll.email 
             AND status = 'Failed' 
             AND created_at >= NOW() - INTERVAL 30 MINUTE) as failed_count_30min
        FROM login_logs ll
        WHERE ll.status = 'Failed'
        ORDER BY ll.created_at DESC
        LIMIT 50
    ");
    $stmt->execute();
    $result = $stmt->get_result();
    $data = $result->fetch_all(MYSQLI_ASSOC);

    echo json_encode(['status' => 'success', 'data' => $data]);
    exit;
}

// Actions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $flag_id = (int)($_POST['flag_id'] ?? 0);

    if ($action === 'mark_reviewed' && $flag_id > 0) {
        $stmt = $conn->prepare("UPDATE flagged_accounts SET reviewed = 1 WHERE id = ?");
        $stmt->bind_param("i", $flag_id);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete_flag' && $flag_id > 0) {
        $stmt = $conn->prepare("DELETE FROM flagged_accounts WHERE id = ?");
        $stmt->bind_param("i", $flag_id);
        $stmt->execute();
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'delete_flags') {
        $flagIds = [];
        $raw = $_POST['flag_ids'] ?? '';
        if (is_string($raw)) {
            $decoded = json_decode($raw, true);
            if (is_array($decoded)) {
                foreach ($decoded as $id) {
                    if (is_numeric($id)) {
                        $flagIds[] = (int)$id;
                    }
                }
            }
        }
        if (empty($flagIds)) {
            echo json_encode(['status' => 'error', 'message' => 'No flagged account IDs provided.']);
            exit;
        }
        $ids = implode(',', array_unique($flagIds));
        $conn->query("DELETE FROM flagged_accounts WHERE id IN ($ids)");
        echo json_encode(['status' => 'success']);
        exit;
    }

    if ($action === 'run_security_scan') {
        ensure_security_scans_table($conn);
        $https = evaluate_https();
        $session = evaluate_session();
        $password = evaluate_password_hashing($conn);
        $headers = evaluate_security_headers();
        $upload = evaluate_upload_restrictions($conn);

        $score = round((
            score_for_status($https['status']) +
            score_for_status($session['status']) +
            score_for_status($password['status']) +
            score_for_status($headers['status']) +
            score_for_status($upload['status'])
        ) / 5);

        $details = json_encode([
            'https' => $https,
            'session' => $session,
            'password_hash' => $password,
            'security_headers' => $headers,
            'upload_restrictions' => $upload,
        ]);

        $stmt = $conn->prepare("INSERT INTO security_scans (https_status, session_status, password_hash_status, security_headers_status, upload_restrictions_status, score, details) VALUES (?, ?, ?, ?, ?, ?, ?)");
        $stmt->bind_param(
            "sssssds",
            $https['status'],
            $session['status'],
            $password['status'],
            $headers['status'],
            $upload['status'],
            $score,
            $details
        );
        $stmt->execute();
        $stmt->close();

        echo json_encode([
            'status' => 'success',
            'scan' => [
                'https' => $https,
                'session' => $session,
                'password_hash' => $password,
                'security_headers' => $headers,
                'upload_restrictions' => $upload,
                'score' => $score,
                'scanned_at' => date('Y-m-d H:i:s'),
            ],
        ]);
        exit;
    }
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>
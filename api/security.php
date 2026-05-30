<?php
session_start();
require __DIR__ . '/../config/db.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    echo json_encode(['status' => 'error', 'message' => 'Unauthorized']);
    exit;
}

$action = $_GET['action'] ?? $_POST['action'] ?? '';

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
}

echo json_encode(['status' => 'error', 'message' => 'Invalid action']);
?>
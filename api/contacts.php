<?php
// api/contacts.php — Emergency Contacts & Responder Notification API
// Admin: full CRUD on emergency_contacts
// System: auto-notify matching contacts on new Dangerous reports

ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Unauthorized.']); exit;
}

require __DIR__ . '/../config/db.php';

$user_id = (int)$_SESSION['user_id'];
$role    = $_SESSION['role'] ?? 'user';
$action  = trim($_REQUEST['action'] ?? '');

// ── Ensure tables exist ────────────────────────────────────────────────────
function ensureContactsTables($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $conn->query("
        CREATE TABLE IF NOT EXISTS `emergency_contacts` (
          `id`             int(11)      NOT NULL AUTO_INCREMENT,
          `name`           varchar(255) NOT NULL,
          `type`           enum('lgu','hospital','traffic','barangay','police','fire','other') NOT NULL DEFAULT 'other',
          `barangay`       varchar(150) DEFAULT NULL,
          `city`           varchar(150) NOT NULL,
          `province`       varchar(150) DEFAULT NULL,
          `contact_number` varchar(50)  DEFAULT NULL,
          `contact_email`  varchar(191) DEFAULT NULL,
          `is_active`      tinyint(1)   NOT NULL DEFAULT 1,
          `created_at`     timestamp    NOT NULL DEFAULT current_timestamp(),
          `updated_at`     timestamp    NOT NULL DEFAULT current_timestamp() ON UPDATE current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `idx_city` (`city`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");

    $conn->query("
        CREATE TABLE IF NOT EXISTS `contact_notifications` (
          `id`         int(11) NOT NULL AUTO_INCREMENT,
          `report_id`  int(11) NOT NULL,
          `contact_id` int(11) NOT NULL,
          `method`     enum('email','sms','auto_call') NOT NULL DEFAULT 'email',
          `status`     enum('sent','failed','pending')  NOT NULL DEFAULT 'pending',
          `sent_at`    timestamp NOT NULL DEFAULT current_timestamp(),
          PRIMARY KEY (`id`),
          KEY `report_id` (`report_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
    ");
}

switch ($action) {

    // ── PUBLIC (any logged-in user): list active contacts ─────────────────
    case 'list':
        ensureContactsTables($conn);
        $city     = trim($_GET['city']     ?? '');
        $barangay = trim($_GET['barangay'] ?? '');
        $type     = trim($_GET['type']     ?? '');

        $where = ['is_active = 1'];
        $params = []; $types = '';

        if ($city) {
            $where[] = 'city = ?'; $params[] = $city; $types .= 's';
        }
        if ($type) {
            $allowed = ['lgu','hospital','traffic','barangay','police','fire','other'];
            if (in_array($type, $allowed)) { $where[] = 'type = ?'; $params[] = $type; $types .= 's'; }
        }
        // If barangay given: match barangay-specific OR city-wide (NULL barangay)
        if ($barangay) {
            $where[] = '(barangay = ? OR barangay IS NULL)';
            $params[] = $barangay; $types .= 's';
        }

        $sql = 'SELECT id, name, type, barangay, city, province, contact_number, contact_email
                FROM emergency_contacts
                WHERE ' . implode(' AND ', $where) . '
                ORDER BY type, name';
        if ($params) {
            $stmt = $conn->prepare($sql);
            $stmt->bind_param($types, ...$params);
            $stmt->execute();
            $res = $stmt->get_result();
        } else {
            $res = $conn->query($sql);
        }
        $contacts = [];
        while ($row = $res->fetch_assoc()) { $row['id'] = (int)$row['id']; $contacts[] = $row; }
        echo json_encode(['status'=>'success','contacts'=>$contacts]);
        break;

    // ── ADMIN ONLY: full list (including inactive) ────────────────────────
    case 'admin_list':
        if ($role !== 'admin') { echo json_encode(['status'=>'error','message'=>'Admin required.']); exit; }
        ensureContactsTables($conn);
        $res = $conn->query('SELECT * FROM emergency_contacts ORDER BY city, type, name');
        $contacts = [];
        while ($row = $res->fetch_assoc()) {
            $row['id'] = (int)$row['id'];
            $row['is_active'] = (int)$row['is_active'];
            $contacts[] = $row;
        }
        echo json_encode(['status'=>'success','contacts'=>$contacts]);
        break;

    // ── ADMIN: create contact ─────────────────────────────────────────────
    case 'create':
        if (!in_array($role, ['admin','lgu','barangay'])) { echo json_encode(['status'=>'error','message'=>'Insufficient permissions.']); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        ensureContactsTables($conn);

        $name   = trim($_POST['name']   ?? '');
        $type   = trim($_POST['type']   ?? 'other');
        $brgy   = trim($_POST['barangay']       ?? '') ?: null;
        $city   = trim($_POST['city']           ?? '');
        $prov   = trim($_POST['province']       ?? '') ?: null;
        $phone  = trim($_POST['contact_number'] ?? '') ?: null;
        $email  = trim($_POST['contact_email']  ?? '') ?: null;

        $allowed_types = ['lgu','hospital','traffic','barangay','police','fire','other'];
        if (empty($name) || empty($city)) { echo json_encode(['status'=>'error','message'=>'Name and city are required.']); exit; }
        if (!in_array($type, $allowed_types)) { echo json_encode(['status'=>'error','message'=>'Invalid type.']); exit; }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['status'=>'error','message'=>'Invalid email address.']); exit; }

        $ins = $conn->prepare('INSERT INTO emergency_contacts (name, type, barangay, city, province, contact_number, contact_email) VALUES (?,?,?,?,?,?,?)');
        $ins->bind_param('sssssss', $name, $type, $brgy, $city, $prov, $phone, $email);
        if ($ins->execute()) {
            echo json_encode(['status'=>'success','message'=>'Contact added.','id'=>(int)$conn->insert_id]);
        } else {
            echo json_encode(['status'=>'error','message'=>'Save failed: '.$ins->error]);
        }
        $ins->close();
        break;

    // ── ADMIN: update contact ─────────────────────────────────────────────
    case 'update':
        if (!in_array($role, ['admin','lgu','barangay'])) { echo json_encode(['status'=>'error','message'=>'Insufficient permissions.']); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        ensureContactsTables($conn);

        $id     = (int)($_POST['id'] ?? 0);
        $name   = trim($_POST['name']   ?? '');
        $type   = trim($_POST['type']   ?? 'other');
        $brgy   = trim($_POST['barangay']       ?? '') ?: null;
        $city   = trim($_POST['city']           ?? '');
        $prov   = trim($_POST['province']       ?? '') ?: null;
        $phone  = trim($_POST['contact_number'] ?? '') ?: null;
        $email  = trim($_POST['contact_email']  ?? '') ?: null;
        $active = (int)($_POST['is_active'] ?? 1);

        $allowed_types = ['lgu','hospital','traffic','barangay','police','fire','other'];
        if (!$id) { echo json_encode(['status'=>'error','message'=>'Invalid ID.']); exit; }
        if (empty($name) || empty($city)) { echo json_encode(['status'=>'error','message'=>'Name and city are required.']); exit; }
        if (!in_array($type, $allowed_types)) { echo json_encode(['status'=>'error','message'=>'Invalid type.']); exit; }
        if ($email && !filter_var($email, FILTER_VALIDATE_EMAIL)) { echo json_encode(['status'=>'error','message'=>'Invalid email address.']); exit; }

        $upd = $conn->prepare('UPDATE emergency_contacts SET name=?, type=?, barangay=?, city=?, province=?, contact_number=?, contact_email=?, is_active=? WHERE id=?');
        $upd->bind_param('sssssssii', $name, $type, $brgy, $city, $prov, $phone, $email, $active, $id);
        if ($upd->execute()) {
            echo json_encode(['status'=>'success','message'=>'Contact updated.']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Update failed: '.$upd->error]);
        }
        $upd->close();
        break;

    // ── ADMIN: delete contact ─────────────────────────────────────────────
    case 'delete':
        if (!in_array($role, ['admin','lgu','barangay'])) { echo json_encode(['status'=>'error','message'=>'Insufficient permissions.']); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        $id = (int)($_POST['id'] ?? 0);
        if (!$id) { echo json_encode(['status'=>'error','message'=>'Invalid ID.']); exit; }
        $d = $conn->prepare('DELETE FROM emergency_contacts WHERE id=?');
        $d->bind_param('i', $id);
        $d->execute();
        echo json_encode($d->affected_rows > 0
            ? ['status'=>'success','message'=>'Contact removed.']
            : ['status'=>'error','message'=>'Not found.']);
        $d->close();
        break;

    // ── INTERNAL: notify contacts for a report (admin only) ───────────────
    case 'notify_report':
        if ($role !== 'admin') { echo json_encode(['status'=>'error','message'=>'Admin required.']); exit; }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        ensureContactsTables($conn);

        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) { echo json_encode(['status'=>'error','message'=>'Invalid report ID.']); exit; }

        // Fetch report details
        $rs = $conn->prepare('SELECT title, description, status, category, city, barangay, location_name FROM reports WHERE id=? AND is_archived=0 LIMIT 1');
        $rs->bind_param('i', $report_id);
        $rs->execute();
        $rs->bind_result($r_title, $r_desc, $r_status, $r_cat, $r_city, $r_barangay, $r_loc);
        if (!$rs->fetch()) { echo json_encode(['status'=>'error','message'=>'Report not found.']); exit; }
        $rs->close();

        // Only notify for Dangerous reports (avoid spam on Caution/Safe)
        if ($r_status !== 'dangerous') {
            echo json_encode(['status'=>'success','message'=>'Notification skipped (non-dangerous).','notified'=>0]);
            break;
        }

        // Find matching active contacts (barangay-specific + city-wide)
        $cq = $conn->prepare("
            SELECT id, name, contact_email, contact_number, type
            FROM emergency_contacts
            WHERE is_active=1 AND city=?
              AND (barangay=? OR barangay IS NULL)
        ");
        $cq->bind_param('ss', $r_city, $r_barangay);
        $cq->execute();
        $contacts_res = $cq->get_result();

        $notified = 0;
        $failed   = 0;

        // Load mailer
        $mailer_available = file_exists(__DIR__ . '/../core/SenTriMailer.php');
        if ($mailer_available) require_once __DIR__ . '/../core/SenTriMailer.php';

        while ($contact = $contacts_res->fetch_assoc()) {
            $cid = (int)$contact['id'];
            $email_sent = false;

            if (!empty($contact['contact_email']) && $mailer_available) {
                $subject  = "\xF0\x9F\x9A\xA8 DANGEROUS Incident Alert – $r_title";
                $loc_line = $r_loc . ($r_barangay ? ", Brgy. $r_barangay" : '') . ", $r_city";
                $html_body = buildEmailTemplate(
                    'Dangerous Incident Alert',
                    'Dear ' . htmlspecialchars($contact['name']) . ',',
                    "A <strong>DANGEROUS</strong> incident (<strong>" . htmlspecialchars(strtoupper($r_cat)) . "</strong>) has been reported at: <strong>" . htmlspecialchars($loc_line) . "</strong><br><br>" . nl2br(htmlspecialchars($r_desc)),
                    defined('APP_URL') ? APP_URL . '/admin.php' : '#',
                    'Open Admin Panel',
                    'This is an automated safety alert from SenTri. Please respond as appropriate.'
                );
                try {
                    $m = new SenTriMailer();
                    // SenTriMailer::send(toEmail, toName, subject, htmlBody)
                    $m->send($contact['contact_email'], $contact['name'], $subject, $html_body);
                    $email_sent = true;
                } catch (Exception $e) {
                    error_log('SenTri contact notify error: ' . $e->getMessage());
                }
            }

            $method = 'email';
            $status_val = $email_sent ? 'sent' : 'failed';
            $log = $conn->prepare('INSERT INTO contact_notifications (report_id, contact_id, method, status) VALUES (?,?,?,?)');
            $log->bind_param('iiss', $report_id, $cid, $method, $status_val);
            $log->execute();
            $log->close();

            if ($email_sent) $notified++;
            else $failed++;
        }
        $cq->close();

        echo json_encode([
            'status'   => 'success',
            'message'  => "Notified $notified contact(s).",
            'notified' => $notified,
            'failed'   => $failed,
        ]);
        break;

    // ── Get notification log for a report (admin) ─────────────────────────
    case 'get_notifications':
        if ($role !== 'admin') { echo json_encode(['status'=>'error','message'=>'Admin required.']); exit; }
        $report_id = (int)($_GET['report_id'] ?? 0);
        if (!$report_id) { echo json_encode(['status'=>'error','message'=>'Invalid ID.']); exit; }
        $stmt = $conn->prepare("
            SELECT cn.id, ec.name, ec.type, ec.contact_email, cn.method, cn.status, cn.sent_at
            FROM contact_notifications cn
            JOIN emergency_contacts ec ON ec.id = cn.contact_id
            WHERE cn.report_id = ?
            ORDER BY cn.sent_at DESC
        ");
        $stmt->bind_param('i', $report_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $logs = [];
        while ($row = $res->fetch_assoc()) { $row['id'] = (int)$row['id']; $logs[] = $row; }
        $stmt->close();
        echo json_encode(['status'=>'success','notifications'=>$logs]);
        break;

    default:
        echo json_encode(['status'=>'error','message'=>'Unknown action.']);
}
$conn->close();
?>

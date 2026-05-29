<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();
header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['status'=>'error','message'=>'Unauthorized. Please log in.']); exit;
}

require __DIR__ . '/../config/db.php';

$user_id = (int)($_SESSION['user_id'] ?? 0);
$role    = $_SESSION['role'] ?? 'user';
$action  = trim($_REQUEST['action'] ?? '');

// ── Ensure geo columns exist (compatible with all MySQL versions) ─────────
// Uses INFORMATION_SCHEMA instead of "IF NOT EXISTS" which requires MySQL 8+
function ensureGeoColumns($conn) {
    static $checked = false;
    if ($checked) return;
    $checked = true;

    $db = $conn->query("SELECT DATABASE()")->fetch_row()[0];

    $cols = [];
    $res  = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                          WHERE TABLE_SCHEMA = '$db' AND TABLE_NAME = 'reports'");
    while ($r = $res->fetch_row()) $cols[] = $r[0];

    if (!in_array('latitude',  $cols)) $conn->query("ALTER TABLE reports ADD COLUMN latitude  DECIMAL(10,7) DEFAULT NULL");
    if (!in_array('longitude', $cols)) $conn->query("ALTER TABLE reports ADD COLUMN longitude DECIMAL(10,7) DEFAULT NULL");
    if (!in_array('radius_m',  $cols)) $conn->query("ALTER TABLE reports ADD COLUMN radius_m  INT(11) NOT NULL DEFAULT 200");
}
// ─────────────────────────────────────────────────────────────────────────

switch ($action) {

    case 'get_reports':
        ensureGeoColumns($conn);
        $sql = "
            SELECT r.id, r.user_id, r.title, r.description, r.location_name, r.barangay,
                   r.city, r.province, r.latitude, r.longitude, r.radius_m,
                   r.status, r.category, r.upvotes, r.downvotes, r.created_at,
                   CONCAT(u.first_name,' ',u.last_name) AS poster_name,
                   v.vote AS user_vote
            FROM reports r
            INNER JOIN users u ON r.user_id = u.id
            LEFT JOIN report_votes v ON v.report_id = r.id AND v.user_id = ?
            WHERE r.is_archived = 0
            ORDER BY r.created_at DESC LIMIT 200";
        $stmt = $conn->prepare($sql);
        if (!$stmt) { echo json_encode(['status'=>'error','message'=>'DB error: '.$conn->error]); exit; }
        $stmt->bind_param("i", $user_id);
        $stmt->execute();
        $res = $stmt->get_result();
        $reports = [];
        while ($row = $res->fetch_assoc()) {
            $row['id']        = (int)$row['id'];
            $row['user_id']   = (int)$row['user_id'];
            $row['upvotes']   = (int)$row['upvotes'];
            $row['downvotes'] = (int)$row['downvotes'];
            $row['latitude']  = $row['latitude']  !== null ? (float)$row['latitude']  : null;
            $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
            $row['radius_m']  = (int)($row['radius_m'] ?? 200);
            $reports[] = $row;
        }
        $stmt->close();

        // ── Attach images to each report ──────────────────────────────────
        if (!empty($reports)) {
            $images_map = [];
            $chk_tbl = $conn->query("SHOW TABLES LIKE 'report_images'");
            if ($chk_tbl && $chk_tbl->num_rows > 0) {
                $ids_str = implode(',', array_column($reports, 'id'));
                $img_res = $conn->query(
                    "SELECT report_id, file_name FROM report_images
                     WHERE report_id IN ($ids_str) ORDER BY uploaded_at ASC"
                );
                if ($img_res) {
                    while ($img_row = $img_res->fetch_assoc()) {
                        $rid = (int)$img_row['report_id'];
                        if (!isset($images_map[$rid])) $images_map[$rid] = [];
                        $images_map[$rid][] = 'uploads/reports/' . rawurlencode($img_row['file_name']);
                    }
                }
            }
            foreach ($reports as &$report) {
                $report['images'] = $images_map[$report['id']] ?? [];
            }
            unset($report);
        }

        echo json_encode(['status'=>'success','reports'=>$reports]);
        break;

    case 'post_report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        $title         = trim($_POST['title']         ?? '');
        $description   = trim($_POST['description']   ?? '');
        $location_name = trim($_POST['location_name'] ?? '');
        $barangay      = trim($_POST['barangay']      ?? '');
        $city          = trim($_POST['city']          ?? '');
        $province      = trim($_POST['province']      ?? '');
        $status_val    = trim($_POST['status']        ?? '');
        $category      = trim($_POST['category']      ?? '');

        // Lat/lng: keep as string or null — 's' type in bind_param handles nullable
        // decimals correctly on all MySQL versions (null → SQL NULL, "14.35" → DECIMAL)
        $lat = (isset($_POST['latitude'])  && $_POST['latitude']  !== '') ? $_POST['latitude']  : null;
        $lng = (isset($_POST['longitude']) && $_POST['longitude'] !== '') ? $_POST['longitude'] : null;
        $rad = (isset($_POST['radius_m'])  && $_POST['radius_m']  !== '') ? (int)$_POST['radius_m'] : 200;

        $allowed_s = ['dangerous','caution','safe'];
        $allowed_c = ['crime','accident','flooding','fire','health','infrastructure','other'];

        if (empty($title)||empty($description)||empty($location_name)||empty($city)) {
            echo json_encode(['status'=>'error','message'=>'Required fields missing.']); exit;
        }
        if (!in_array($status_val,$allowed_s)) { echo json_encode(['status'=>'error','message'=>'Invalid status.']); exit; }
        if (!in_array($category,$allowed_c))   { echo json_encode(['status'=>'error','message'=>'Invalid category.']); exit; }

        $rate = $conn->prepare("SELECT COUNT(*) FROM reports WHERE user_id=? AND created_at>DATE_SUB(NOW(),INTERVAL 1 HOUR)");
        $rate->bind_param("i",$user_id); $rate->execute(); $rate->bind_result($cnt); $rate->fetch(); $rate->close();
        if ($cnt >= 10) { echo json_encode(['status'=>'error','message'=>'Posting limit reached (10/hr).']); exit; }

        ensureGeoColumns($conn);

        // Type string: i=user_id, s×7=text fields, s=lat, s=lng, i=radius, s=status, s=category
        // Using 's' (not 'd') for lat/lng so PHP null becomes SQL NULL safely
        $ins = $conn->prepare("INSERT INTO reports (user_id,title,description,location_name,barangay,city,province,latitude,longitude,radius_m,status,category) VALUES (?,?,?,?,?,?,?,?,?,?,?,?)");
        if (!$ins) { echo json_encode(['status'=>'error','message'=>'Prepare failed: '.$conn->error]); exit; }
        $ins->bind_param("issssssssiss",
            $user_id,$title,$description,$location_name,$barangay,$city,$province,
            $lat,$lng,$rad,$status_val,$category);
        if ($ins->execute()) {
            $new_id = (int)$conn->insert_id;
            $ins->close();

            // ── Photo upload (optional, up to 3 images) ────────────────────
            $uploaded_images = [];
            if (!empty($_FILES['photos']['name'][0])) {
                $upload_dir = __DIR__ . '/../uploads/reports/';
                if (!is_dir($upload_dir)) @mkdir($upload_dir, 0755, true);

                // Ensure report_images table exists
                $conn->query("
                    CREATE TABLE IF NOT EXISTS `report_images` (
                      `id`            int(11)      NOT NULL AUTO_INCREMENT,
                      `report_id`     int(11)      NOT NULL,
                      `file_name`     varchar(255) NOT NULL,
                      `original_name` varchar(255) DEFAULT NULL,
                      `mime_type`     varchar(100) DEFAULT NULL,
                      `file_size`     int(11)      DEFAULT NULL,
                      `uploaded_at`   timestamp    NOT NULL DEFAULT current_timestamp(),
                      PRIMARY KEY (`id`),
                      KEY `report_id` (`report_id`)
                    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
                ");

                $allowed_mime = ['image/jpeg','image/png','image/webp','image/gif'];
                $max_size     = 5 * 1024 * 1024; // 5 MB per image
                $max_images   = 3;
                $count        = min(count($_FILES['photos']['name']), $max_images);

                for ($i = 0; $i < $count; $i++) {
                    $tmp  = $_FILES['photos']['tmp_name'][$i];
                    $orig = basename($_FILES['photos']['name'][$i]);
                    $mime = mime_content_type($tmp);
                    $size = $_FILES['photos']['size'][$i];

                    if ($_FILES['photos']['error'][$i] !== UPLOAD_ERR_OK) continue;
                    if (!in_array($mime, $allowed_mime))                  continue;
                    if ($size > $max_size)                                 continue;

                    $mime_ext_map = ['image/jpeg'=>'jpg','image/png'=>'png','image/webp'=>'webp','image/gif'=>'gif'];
                    $ext      = $mime_ext_map[$mime] ?? 'jpg';
                    $filename = 'report_' . $new_id . '_' . uniqid() . '.' . $ext;
                    $dest     = $upload_dir . $filename;

                    if (move_uploaded_file($tmp, $dest)) {
                        $si = $conn->prepare("INSERT INTO report_images (report_id, file_name, original_name, mime_type, file_size) VALUES (?,?,?,?,?)");
                        $si->bind_param('isssi', $new_id, $filename, $orig, $mime, $size);
                        $si->execute();
                        $si->close();
                        $uploaded_images[] = $filename;
                    }
                }
            }

            echo json_encode([
                'status'  => 'success',
                'message' => 'Report posted.',
                'id'      => $new_id,
                'images'  => $uploaded_images,
            ]);
        } else {
            echo json_encode(['status'=>'error','message'=>'Save failed: '.$ins->error]);
            $ins->close();
        }
        break;

    case 'vote':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        $report_id=(int)($_POST['report_id']??0); $vote=trim($_POST['vote']??'');
        if(!$report_id||!in_array($vote,['up','down'])){echo json_encode(['status'=>'error','message'=>'Invalid vote.']);exit;}
        $chk=$conn->prepare("SELECT id FROM reports WHERE id=? AND is_archived=0 LIMIT 1");
        $chk->bind_param("i",$report_id); $chk->execute(); $chk->store_result();
        if($chk->num_rows===0){echo json_encode(['status'=>'error','message'=>'Report not found.']);exit;}
        $chk->close();
        $ex=$conn->prepare("SELECT id,vote FROM report_votes WHERE report_id=? AND user_id=? LIMIT 1");
        $ex->bind_param("ii",$report_id,$user_id); $ex->execute(); $ex->store_result();
        $ex->bind_result($vid,$ev); $ex->fetch(); $has=$ex->num_rows>0; $ex->close();
        $new_uv=null;
        if($has){
            if($ev===$vote){$d=$conn->prepare("DELETE FROM report_votes WHERE id=?");$d->bind_param("i",$vid);$d->execute();$d->close();}
            else{$u=$conn->prepare("UPDATE report_votes SET vote=?,created_at=NOW() WHERE id=?");$u->bind_param("si",$vote,$vid);$u->execute();$u->close();$new_uv=$vote;}
        } else {
            $i=$conn->prepare("INSERT INTO report_votes (report_id,user_id,vote) VALUES (?,?,?)");$i->bind_param("iis",$report_id,$user_id,$vote);$i->execute();$i->close();$new_uv=$vote;
        }
        $cnt=$conn->prepare("SELECT SUM(CASE WHEN vote='up' THEN 1 ELSE 0 END),SUM(CASE WHEN vote='down' THEN 1 ELSE 0 END) FROM report_votes WHERE report_id=?");
        $cnt->bind_param("i",$report_id);$cnt->execute();$cnt->bind_result($ups,$downs);$cnt->fetch();$cnt->close();
        $ups=(int)($ups??0);$downs=(int)($downs??0);
        $u=$conn->prepare("UPDATE reports SET upvotes=?,downvotes=? WHERE id=?");$u->bind_param("iii",$ups,$downs,$report_id);$u->execute();$u->close();
        echo json_encode(['status'=>'success','upvotes'=>$ups,'downvotes'=>$downs,'user_vote'=>$new_uv]);
        break;

    case 'delete_report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        $report_id=(int)($_POST['report_id']??0);
        if(!$report_id){echo json_encode(['status'=>'error','message'=>'Invalid ID.']);exit;}
        if($role==='admin'){$d=$conn->prepare("UPDATE reports SET is_archived=1 WHERE id=?");$d->bind_param("i",$report_id);}
        else{$d=$conn->prepare("UPDATE reports SET is_archived=1 WHERE id=? AND user_id=?");$d->bind_param("ii",$report_id,$user_id);}
        $d->execute();
        if($d->affected_rows>0){
            $r=$conn->query("SELECT title FROM reports WHERE id=$report_id")->fetch_assoc();
            $title=$conn->real_escape_string($r['title']??'Unknown');
            $by_id=$_SESSION['user_id'];
            $by_name=$conn->real_escape_string($_SESSION['first_name'].' '.($_SESSION['last_name']??''));
            $conn->query("INSERT INTO report_audit_logs (report_id,report_title,action,performed_by,performed_by_name) VALUES ($report_id,'$title','archived',$by_id,'$by_name')");
            echo json_encode(['status'=>'success','message'=>'Report removed.']);
        } else { echo json_encode(['status'=>'error','message'=>'Could not delete.']); }
        $d->close();
        break;

    case 'restore_report':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        $report_id=(int)($_POST['report_id']??0);
        $u=$conn->prepare("UPDATE reports SET is_archived=0 WHERE id=?");$u->bind_param("i",$report_id);$u->execute();
        if($u->affected_rows>0){
            $r=$conn->query("SELECT title FROM reports WHERE id=$report_id")->fetch_assoc();
            $title=$conn->real_escape_string($r['title']??'Unknown');
            $by_id=$_SESSION['user_id'];
            $by_name=$conn->real_escape_string($_SESSION['first_name'].' '.($_SESSION['last_name']??''));
            $conn->query("INSERT INTO report_audit_logs (report_id,report_title,action,performed_by,performed_by_name) VALUES ($report_id,'$title','restored',$by_id,'$by_name')");
        }
        echo json_encode(['status'=>'success','message'=>'Report restored.']); $u->close();
        break;

    case 'admin_get_reports':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        ensureGeoColumns($conn);
        $sql="SELECT r.id,r.user_id,r.title,r.status,r.category,r.city,r.location_name,r.latitude,r.longitude,r.radius_m,r.is_archived,r.upvotes,r.downvotes,r.created_at,CONCAT(u.first_name,' ',u.last_name) AS poster_name FROM reports r INNER JOIN users u ON r.user_id=u.id ORDER BY r.created_at DESC LIMIT 500";
        $res=$conn->query($sql); $reports=[];
        while($row=$res->fetch_assoc()){
            $row['id']=(int)$row['id'];$row['upvotes']=(int)$row['upvotes'];$row['downvotes']=(int)$row['downvotes'];$row['is_archived']=(int)$row['is_archived'];
            $row['latitude']  = $row['latitude']  !== null ? (float)$row['latitude']  : null;
            $row['longitude'] = $row['longitude'] !== null ? (float)$row['longitude'] : null;
            $row['radius_m']  = (int)($row['radius_m'] ?? 200);
            $reports[]=$row;
        }
        echo json_encode(['status'=>'success','reports'=>$reports]);
        break;

    case 'admin_get_users':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        $sql="SELECT u.id,u.first_name,u.last_name,u.email,u.role,u.org_name,u.position,u.barangay_name,u.municipality,u.is_approved,u.created_at,COUNT(r.id) AS report_count FROM users u LEFT JOIN reports r ON r.user_id=u.id GROUP BY u.id ORDER BY u.is_approved ASC, u.created_at DESC";
        $res=$conn->query($sql); $users=[];
        while($row=$res->fetch_assoc()){$row['id']=(int)$row['id'];$row['report_count']=(int)$row['report_count'];$row['is_approved']=(int)$row['is_approved'];$users[]=$row;}
        echo json_encode(['status'=>'success','users'=>$users]);
        break;

    case 'admin_approve_user':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        $target=(int)($_POST['user_id']??0);
        if(!$target){echo json_encode(['status'=>'error','message'=>'Invalid user ID.']);exit;}
        $s=$conn->prepare("UPDATE users SET is_approved=1 WHERE id=?");
        $s->bind_param("i",$target); $s->execute(); $s->close();
        echo json_encode(['status'=>'success','message'=>'Account approved.']);
        break;

    case 'admin_reject_user':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        $target=(int)($_POST['user_id']??0);
        if(!$target||$target===$user_id){echo json_encode(['status'=>'error','message'=>'Invalid.']);exit;}
        $s=$conn->prepare("DELETE FROM users WHERE id=? AND role!='admin'");
        $s->bind_param("i",$target); $s->execute(); $s->close();
        echo json_encode(['status'=>'success','message'=>'Account rejected and removed.']);
        break;

    case 'admin_delete_user':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        $target=(int)($_POST['user_id']??0);
        if(!$target){echo json_encode(['status'=>'error','message'=>'Invalid user ID.']);exit;}
        if($target===$user_id){echo json_encode(['status'=>'error','message'=>'Cannot delete yourself.']);exit;}
        $conn->query("DELETE FROM report_votes WHERE user_id=$target");
        $conn->query("DELETE FROM report_votes WHERE report_id IN (SELECT id FROM reports WHERE user_id=$target)");
        $conn->query("DELETE FROM reports WHERE user_id=$target");
        $d=$conn->prepare("DELETE FROM users WHERE id=? AND role!='admin'");
        $d->bind_param("i",$target); $d->execute();
        if($d->affected_rows>0){echo json_encode(['status'=>'success','message'=>'User removed.']);}
        else{echo json_encode(['status'=>'error','message'=>'Could not delete user (admin accounts are protected).']);}
        $d->close();
        break;

    case 'admin_get_logs':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        $sql="SELECT id,email,ip_address,device,status,created_at FROM login_logs ORDER BY created_at DESC LIMIT 100";
        $res=$conn->query($sql); $logs=[];
        while($row=$res->fetch_assoc()){$row['id']=(int)$row['id'];$logs[]=$row;}
        echo json_encode(['status'=>'success','logs'=>$logs]);
        break;

    // ─── Save user GPS coordinates ───────────────────────────────────────────
    case 'save_gps':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        $lat = isset($_POST['latitude'])  && $_POST['latitude']  !== '' ? (float)$_POST['latitude']  : null;
        $lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float)$_POST['longitude'] : null;
        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            echo json_encode(['status'=>'error','message'=>'Invalid coordinates.']); exit;
        }
        // Ensure GPS columns exist on users table
        $db2 = $conn->query("SELECT DATABASE()")->fetch_row()[0];
        $colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db2' AND TABLE_NAME='users'");
        $existCols2 = [];
        while($rc2 = $colRes->fetch_row()) $existCols2[] = $rc2[0];
        if (!in_array('gps_lat', $existCols2)) $conn->query("ALTER TABLE users ADD COLUMN gps_lat DECIMAL(10,7) DEFAULT NULL");
        if (!in_array('gps_lng', $existCols2)) $conn->query("ALTER TABLE users ADD COLUMN gps_lng DECIMAL(10,7) DEFAULT NULL");

        $upd = $conn->prepare("UPDATE users SET gps_lat=?, gps_lng=? WHERE id=?");
        $upd->bind_param("ddi", $lat, $lng, $user_id);
        if ($upd->execute()) {
            $_SESSION['gps_lat'] = $lat;
            $_SESSION['gps_lng'] = $lng;
            echo json_encode(['status'=>'success','lat'=>$lat,'lng'=>$lng]);
        } else {
            echo json_encode(['status'=>'error','message'=>'Failed to save GPS: '.$upd->error]);
        }
        $upd->close();
        break;

    // ─── Profile update ──────────────────────────────────────────────────────
    case 'update_profile':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }

        $first_name   = trim($_POST['first_name']   ?? '');
        $last_name    = trim($_POST['last_name']    ?? '');
        $email        = trim($_POST['email']        ?? '');
        $avatar_color = trim($_POST['avatar_color'] ?? '#1c57b2');
        $current_pw   = $_POST['current_password']  ?? '';
        $new_pw       = $_POST['new_password']      ?? '';

        if (empty($first_name) || empty($last_name) || empty($email)) {
            echo json_encode(['status'=>'error','message'=>'First name, last name and email are required.']); exit;
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            echo json_encode(['status'=>'error','message'=>'Invalid email address.']); exit;
        }
        // Validate avatar color (hex)
        if (!preg_match('/^#[0-9a-fA-F]{6}$/', $avatar_color)) $avatar_color = '#1c57b2';

        // Ensure avatar_color column exists
        $db = $conn->query("SELECT DATABASE()")->fetch_row()[0];
        $chkCols = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users'");
        $existCols = [];
        while($rc = $chkCols->fetch_row()) $existCols[] = $rc[0];
        if (!in_array('avatar_color', $existCols)) {
            $conn->query("ALTER TABLE users ADD COLUMN avatar_color VARCHAR(7) NOT NULL DEFAULT '#1c57b2'");
        }

        // Check email uniqueness (exclude current user)
        $ck = $conn->prepare("SELECT id FROM users WHERE email=? AND id!=? LIMIT 1");
        $ck->bind_param("si", $email, $user_id); $ck->execute(); $ck->store_result();
        if ($ck->num_rows > 0) { echo json_encode(['status'=>'error','message'=>'That email is already in use.']); exit; }
        $ck->close();

        // Password change requested?
        if (!empty($new_pw)) {
            if (strlen($new_pw) < 8) { echo json_encode(['status'=>'error','message'=>'New password must be at least 8 characters.']); exit; }
            // Verify current password
            $pw_stmt = $conn->prepare("SELECT password FROM users WHERE id=? LIMIT 1");
            $pw_stmt->bind_param("i", $user_id); $pw_stmt->execute(); $pw_stmt->bind_result($hashed); $pw_stmt->fetch(); $pw_stmt->close();
            if (!password_verify($current_pw, $hashed)) {
                echo json_encode(['status'=>'error','message'=>'Current password is incorrect.']); exit;
            }
            $new_hash = password_hash($new_pw, PASSWORD_DEFAULT);
            $upd = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, avatar_color=?, password=? WHERE id=?");
            $upd->bind_param("sssssi", $first_name, $last_name, $email, $avatar_color, $new_hash, $user_id);
        } else {
            $upd = $conn->prepare("UPDATE users SET first_name=?, last_name=?, email=?, avatar_color=? WHERE id=?");
            $upd->bind_param("ssssi", $first_name, $last_name, $email, $avatar_color, $user_id);
        }

        if ($upd->execute()) {
            // Update session
            $_SESSION['first_name'] = $first_name;
            $_SESSION['last_name']  = $last_name;
            echo json_encode(['status'=>'success','message'=>'Profile updated.']);
        } else {
            echo json_encode(['status'=>'error','message'=>'Update failed: '.$upd->error]);
        }
        $upd->close();
        break;

    // ── Get images for a report ──────────────────────────────────────────
    case 'get_report_images':
        $report_id = (int)($_GET['report_id'] ?? 0);
        if (!$report_id) { echo json_encode(['status'=>'error','message'=>'Invalid ID.']); exit; }
        $res = $conn->query("SELECT id, file_name, original_name, mime_type, file_size, uploaded_at
                             FROM report_images WHERE report_id=$report_id ORDER BY uploaded_at ASC");
        $images = [];
        if ($res) {
            while ($row = $res->fetch_assoc()) {
                $row['id']        = (int)$row['id'];
                $row['file_size'] = (int)$row['file_size'];
                $row['url']       = 'uploads/reports/' . rawurlencode($row['file_name']);
                $images[]         = $row;
            }
        }
        echo json_encode(['status'=>'success','images'=>$images]);
        break;

    case 'assign_report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        if (!in_array($role, ['first_responder','admin'], true)) { echo json_encode(['status'=>'error','message'=>'Unauthorized.']); exit; }
        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) { echo json_encode(['status'=>'error','message'=>'Invalid report ID.']); exit; }
        $stmt = $conn->prepare("UPDATE reports SET assigned_to=? WHERE id=? AND assigned_to IS NULL");
        $stmt->bind_param("ii", $user_id, $report_id);
        $stmt->execute();
        if ($stmt->affected_rows > 0) {
            echo json_encode(['status'=>'success','message'=>'Report assigned to you.']);
        } else {
            // Check if it was already assigned to this user vs someone else
            $chk = $conn->prepare("SELECT assigned_to FROM reports WHERE id=?");
            $chk->bind_param("i",$report_id); $chk->execute();
            $chk->bind_result($cur_assigned); $chk->fetch(); $chk->close();
            if((int)$cur_assigned === $user_id) {
                echo json_encode(['status'=>'error','message'=>'You have already assigned this report to yourself.']);
            } else {
                echo json_encode(['status'=>'error','message'=>'Report not found or already assigned to another responder.']);
            }
        }
        $stmt->close();
        break;

    case 'resolve_report':
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') { echo json_encode(['status'=>'error','message'=>'POST required.']); exit; }
        if (!in_array($role, ['first_responder','barangay','lgu','admin'], true)) { echo json_encode(['status'=>'error','message'=>'Unauthorized.']); exit; }
        $report_id = (int)($_POST['report_id'] ?? 0);
        if (!$report_id) { echo json_encode(['status'=>'error','message'=>'Invalid report ID.']); exit; }
        $stmt = $conn->prepare("UPDATE reports SET status='safe', resolved_at=NOW() WHERE id=?");
        $stmt->bind_param("i", $report_id);
        $stmt->execute();
        echo json_encode(['status'=>'success','message'=>'Report marked as resolved.']);
        $stmt->close();
        break;


    case 'admin_get_audit_logs':
        if($role!=='admin'){echo json_encode(['status'=>'error','message'=>'Admin required.']);exit;}
        $logs=[];
        $result=$conn->query("SELECT * FROM report_audit_logs ORDER BY performed_at DESC LIMIT 200");
        while($row=$result->fetch_assoc()) $logs[]=$row;
        echo json_encode(['status'=>'success','logs'=>$logs]);
        break;

    default:
        echo json_encode(['status'=>'error','message'=>'Unknown action.']);
}
$conn->close();
ob_end_flush();


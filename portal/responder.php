<?php
session_start();
require_once __DIR__ . '/../config/auth.php';
require_role(['first_responder']);
require_once __DIR__ . '/../config/db.php';

$uid   = (int)$_SESSION['user_id'];
$fname = $_SESSION['first_name'];
$view  = $_GET['view'] ?? 'queue';
$has_assigned_to = sentri_table_has_column($conn, 'reports', 'assigned_to');
$has_accepted_at = sentri_table_has_column($conn, 'reports', 'accepted_at');
$has_responded_at = sentri_table_has_column($conn, 'reports', 'responded_at');
$saved_gps_lat = $saved_gps_lng = null;
if (sentri_table_has_column($conn, 'users', 'gps_lat') && sentri_table_has_column($conn, 'users', 'gps_lng')) {
    $gpsRes = $conn->prepare("SELECT gps_lat,gps_lng FROM users WHERE id=? LIMIT 1");
    if ($gpsRes) {
        $gpsRes->bind_param("i", $uid);
        $gpsRes->execute();
        $gpsRes->bind_result($saved_gps_lat, $saved_gps_lng);
        $gpsRes->fetch();
        $gpsRes->close();
    }
}

$stmt = $conn->prepare("SELECT org_name,`position`,responder_type,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i",$uid); $stmt->execute();
$prof = $stmt->get_result()->fetch_assoc(); $stmt->close();
$unit  = $prof['org_name']       ?? 'Responder Unit';
$pos   = $prof['position']       ?? 'First Responder';
$rtype = strtoupper($prof['responder_type'] ?? 'UNIT');
$area  = $prof['municipality']   ?? $prof['barangay_name'] ?? '';

$type_colors = ['bfp'=>'#dc2626','pnp'=>'#1d4ed8','ems'=>'#15803d','drrmo'=>'#d97706','mdrrmo'=>'#d97706','hospital'=>'#0e7490','other'=>'#6b7280'];
$unit_color  = $type_colors[strtolower($prof['responder_type']??'')] ?? '#dc2626';

$cat_icons = ['crime'=>'fa-user-slash','accident'=>'fa-car-burst','flooding'=>'fa-water','fire'=>'fa-fire','health'=>'fa-heart-pulse','infrastructure'=>'fa-road-barrier','other'=>'fa-circle-exclamation'];
$type_icons = ['lgu'=>'fa-landmark','hospital'=>'fa-hospital','traffic'=>'fa-traffic-light','barangay'=>'fa-house-flag','police'=>'fa-shield','fire'=>'fa-fire-extinguisher','other'=>'fa-phone'];

$assigned_col = $has_assigned_to ? "r.assigned_to," : "NULL AS assigned_to,";
$accepted_col = $has_accepted_at ? "r.accepted_at," : "NULL AS accepted_at,";
$responded_col = $has_responded_at ? "r.responded_at," : "NULL AS responded_at,";

function cq($conn,$sql,$t='',$p=[]){
    $s=$conn->prepare($sql);
    if($t && count($p)){
        $refs=[];
        foreach($p as &$v) $refs[]=&$v;
        array_unshift($refs,$t);
        call_user_func_array([$s,'bind_param'],$refs);
    }
    $s->execute();$s->bind_result($n);$s->fetch();$s->close();return(int)$n;
}
$total_active  = cq($conn,"SELECT COUNT(*) FROM reports WHERE status IN('dangerous','caution') AND is_archived=0");
$danger_count  = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0");
$caution_count = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='caution' AND is_archived=0");

// Per-view data
$queue = $assigned = $contacts = $resolved = $community_reports = [];

if ($view === 'queue' || $view === 'overview') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,{$assigned_col}{$accepted_col}{$responded_col}u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 AND r.status IN('dangerous','caution') ORDER BY FIELD(r.status,'dangerous','caution'),r.created_at DESC LIMIT 60");
    if (!$s) { die("DB Error (queue): " . $conn->error); }
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $queue[]=$row;
    $s->close();
}

if ($view === 'assigned') {
    if ($has_assigned_to) {
        $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,{$accepted_col}{$responded_col}u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.assigned_to=? AND r.is_archived=0 ORDER BY r.created_at DESC");
        if (!$s) { die("DB Error (assigned): " . $conn->error); }
        $s->bind_param("i",$uid); $s->execute(); $res=$s->get_result();
        while($row=$res->fetch_assoc()) $assigned[]=$row;
        $s->close();
    }
}

if ($view === 'resolved') {
    if ($has_assigned_to) {
        $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.created_at,r.resolved_at,{$responded_col}u.first_name,u.last_name FROM reports r JOIN users u ON r.user_id=u.id WHERE r.assigned_to=? AND r.status='safe' ORDER BY r.resolved_at DESC LIMIT 50");
        if (!$s) { die("DB Error (resolved): " . $conn->error); }
        $s->bind_param("i",$uid); $s->execute(); $res=$s->get_result();
        while($row=$res->fetch_assoc()) $resolved[]=$row;
        $s->close();
    }
}

if ($view === 'community') {
    $s = $conn->prepare("SELECT r.id,r.user_id,r.title,r.description,r.location_name,r.barangay,r.city,r.province,r.latitude,r.longitude,r.radius_m,r.status,r.category,r.upvotes,r.downvotes,r.created_at,u.first_name,u.last_name,u.role FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 AND u.role IN('community','user') ORDER BY FIELD(r.status,'dangerous','caution','safe'), r.created_at DESC LIMIT 120");
    if (!$s) { die("DB Error (community): " . $conn->error); }
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $community_reports[]=$row;
    $s->close();
}

if ($view === 'contacts') {
    $s = $conn->prepare("SELECT * FROM emergency_contacts WHERE is_active=1 ORDER BY type,name");
    if (!$s) { die("DB Error (contacts): " . $conn->error); }
    $s->execute(); $res=$s->get_result();
    while($r=$res->fetch_assoc()) $contacts[]=$r;
    $s->close();
}

$my_count = $has_assigned_to ? cq($conn,"SELECT COUNT(*) FROM reports WHERE assigned_to=? AND is_archived=0 AND status IN('dangerous','caution')",'i',[$uid]) : 0;
$community_count = cq($conn,"SELECT COUNT(*) FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 AND u.role IN('community','user')");

// ── MAP DATA ────────────────────────────────────────────────────────────────
$map_reports = [];
if ($view === 'map') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 AND r.status IN('dangerous','caution') AND r.latitude IS NOT NULL AND r.longitude IS NOT NULL ORDER BY FIELD(r.status,'dangerous','caution'),r.created_at DESC LIMIT 500");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $map_reports[]=$row;
    $s->close();
}
$map_total_active = cq($conn,"SELECT COUNT(*) FROM reports WHERE status IN('dangerous','caution') AND is_archived=0");

// ── PROFILE POST ─────────────────────────────────────────────────────────────
$profile_msg = '';
if ($view === 'profile' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_profile'])) {
    $p_fname = trim($_POST['first_name']   ?? '');
    $p_lname = trim($_POST['last_name']    ?? '');
    $p_phone = trim($_POST['phone']        ?? '');
    $p_org   = trim($_POST['org_name']     ?? '');
    $p_pos   = trim($_POST['position']     ?? '');
    $p_muni  = trim($_POST['municipality'] ?? '');
    $p_pw    = $_POST['new_password']      ?? '';
    $p_pw2   = $_POST['confirm_password']  ?? '';
    if ($p_pw && $p_pw !== $p_pw2) {
        $profile_msg = 'error:Passwords do not match.';
    } elseif (!$p_fname || !$p_lname) {
        $profile_msg = 'error:Name fields are required.';
    } else {
        if ($p_pw) {
            $h = password_hash($p_pw, PASSWORD_BCRYPT, ['cost'=>10]);
            $su=$conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,org_name=?,`position`=?,municipality=?,password=? WHERE id=?");
            $su->bind_param("sssssssi",$p_fname,$p_lname,$p_phone,$p_org,$p_pos,$p_muni,$h,$uid);
        } else {
            $su=$conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,org_name=?,`position`=?,municipality=? WHERE id=?");
            $su->bind_param("ssssssi",$p_fname,$p_lname,$p_phone,$p_org,$p_pos,$p_muni,$uid);
        }
        $su->execute(); $su->close();
        $_SESSION['first_name'] = htmlspecialchars($p_fname, ENT_QUOTES,'UTF-8');
        $fname=$_SESSION['first_name']; $unit=$p_org?:$unit; $area=$p_muni?:$area; $pos=$p_pos?:$pos;
        $profile_msg = 'success:Profile updated successfully.';
    }
    // Refresh profile vars
    $stmt2=$conn->prepare("SELECT org_name,`position`,responder_type,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
    $stmt2->bind_param("i",$uid); $stmt2->execute();
    $prof=$stmt2->get_result()->fetch_assoc(); $stmt2->close();
}
// Fetch phone for profile form
$p_phone_val = '';
if ($view === 'profile') {
    $ph=$conn->prepare("SELECT phone_number FROM users WHERE id=? LIMIT 1");
    $ph->bind_param("i",$uid); $ph->execute(); $ph->bind_result($p_phone_val); $ph->fetch(); $ph->close();
}

$nav_items = [
    'queue'     => ['icon'=>'fa-siren-on',         'label'=>'Dispatch Queue'],
    'community' => ['icon'=>'fa-users',            'label'=>'Community Reports'],
    'assigned'  => ['icon'=>'fa-clipboard-check',  'label'=>'My Assignments'],
    'map'       => ['icon'=>'fa-map-location-dot', 'label'=>'Incident Map'],
    'resolved'  => ['icon'=>'fa-circle-check',     'label'=>'Resolved by Me'],
    'contacts'  => ['icon'=>'fa-address-book',     'label'=>'Emergency Contacts'],
    'profile'   => ['icon'=>'fa-id-card',          'label'=>'My Profile'],
];
$page_titles = [
    'queue'     => 'Dispatch Queue',
    'community' => 'Community Reports',
    'assigned'  => 'My Assignments',
    'map'       => 'Incident Map',
    'resolved'  => 'Resolved by Me',
    'contacts'  => 'Emergency Contacts',
    'profile'   => 'My Profile',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $page_titles[$view] ?? 'Responder Portal' ?> — SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--red:#b91c1c;--red-dark:#7f1d1d;--red-mid:#991b1b;--red-light:#dc2626;--gold:#f39c12;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--bg:#fafafa;--card:#fff;--sidebar-w:256px;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html,body{height:100%;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;}
/* SIDEBAR */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:linear-gradient(180deg,#450a0a 0%,#7f1d1d 50%,#991b1b 100%);color:#fff;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:200;box-shadow:4px 0 20px rgba(0,0,0,0.3);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);}
.sb-header{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,0.12);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.sb-brand{display:flex;align-items:center;gap:10px;}
.sb-seal{width:40px;height:40px;border-radius:50%;background:rgba(239,68,68,0.2);border:2px solid rgba(239,68,68,0.45);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fca5a5;flex-shrink:0;}
.sb-title{font-size:0.96rem;font-weight:800;line-height:1.2;}
.sb-sub{font-size:0.58rem;color:rgba(255,255,255,0.4);letter-spacing:1.5px;text-transform:uppercase;}
.sb-close{background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.1rem;cursor:pointer;padding:4px 6px;border-radius:6px;display:none;flex-shrink:0;}
.sb-unit{padding:10px 16px;background:rgba(0,0,0,0.25);border-bottom:1px solid rgba(255,255,255,0.08);flex-shrink:0;}
.sb-unit p{font-size:0.65rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.sb-unit strong{font-size:0.82rem;color:#fff;font-weight:700;display:block;word-break:break-word;margin-bottom:4px;}
.unit-badge{display:inline-block;padding:2px 9px;border-radius:4px;font-size:0.68rem;font-weight:800;letter-spacing:1px;color:#fff;}
.sb-nav{padding:12px 8px;flex:1;overflow-y:auto;}
.sb-nav a{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.7);text-decoration:none;font-size:0.84rem;font-weight:500;padding:10px 12px;border-radius:10px;transition:all 0.18s;margin-bottom:2px;white-space:nowrap;}
.sb-nav a:hover{background:rgba(255,255,255,0.1);color:#fff;}
.sb-nav a.active{background:rgba(255,255,255,0.15);color:#fff;font-weight:700;}
.sb-nav a i{width:18px;text-align:center;font-size:0.9rem;flex-shrink:0;}
.sb-badge{background:rgba(255,255,255,0.15);padding:1px 8px;border-radius:10px;font-size:0.7rem;margin-left:auto;flex-shrink:0;}
.sb-section{font-size:0.62rem;color:rgba(255,255,255,0.25);letter-spacing:2px;text-transform:uppercase;padding:14px 12px 5px;font-weight:700;}
.sb-footer{padding:12px 16px;border-top:1px solid rgba(255,255,255,0.1);flex-shrink:0;}
.sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.sb-avatar{width:34px;height:34px;border-radius:50%;flex-shrink:0;background:rgba(239,68,68,0.2);display:flex;align-items:center;justify-content:center;font-size:0.88rem;font-weight:800;color:#fca5a5;}
.sb-uname{font-size:0.82rem;font-weight:700;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-upos{font-size:0.65rem;color:rgba(255,255,255,0.4);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-logout{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.55);text-decoration:none;font-size:0.8rem;font-weight:600;padding:8px 10px;border-radius:8px;transition:all 0.18s;}
.sb-logout:hover{background:rgba(220,38,38,0.2);color:#fca5a5;}
/* MAIN */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-width:0;min-height:100vh;}
/* TOPBAR */
.topbar{background:#fff;border-bottom:4px solid var(--red-light);padding:0 24px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 10px rgba(0,0,0,0.07);}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0;}
.ham-btn{background:none;border:none;font-size:1.15rem;color:var(--muted);cursor:pointer;padding:7px;border-radius:8px;display:none;flex-shrink:0;}
.ham-btn:hover{background:#f3f4f6;}
.page-title{font-size:1rem;font-weight:800;color:var(--red-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.page-sub{font-size:0.72rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.badge-resp{font-size:0.71rem;font-weight:700;padding:5px 12px;border-radius:20px;border:1px solid;white-space:nowrap;flex-shrink:0;}
/* ALERT */
.alert-banner{background:#fef2f2;border-left:4px solid var(--red-light);padding:11px 24px;display:flex;align-items:center;gap:12px;font-size:0.84rem;font-weight:600;color:#991b1b;flex-shrink:0;}
/* CONTENT */
.content{padding:22px 24px;flex:1;}
/* STATS */
.stat-row{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border-radius:14px;padding:18px 16px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);display:flex;align-items:center;gap:14px;}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;}
.stat-num{font-size:1.6rem;font-weight:800;line-height:1;}
.stat-lbl{font-size:0.71rem;color:var(--muted);font-weight:600;margin-top:3px;}
/* CARD */
.card{background:var(--card);border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid var(--border);overflow:hidden;margin-bottom:18px;}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;}
.card-header h3{font-size:0.88rem;font-weight:700;}
.card-meta{font-size:0.72rem;color:var(--muted);white-space:nowrap;}
/* INCIDENT ROWS */
.incident-row{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;transition:background 0.15s;}
.incident-row:last-child{border-bottom:none;}
.incident-row:hover{background:#fafafa;}
.inc-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.inc-body{flex:1;min-width:0;}
.inc-title{font-size:0.88rem;font-weight:700;margin-bottom:3px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.inc-meta{font-size:0.74rem;color:var(--muted);display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:4px;}
.inc-actions{display:flex;flex-direction:column;gap:5px;align-items:flex-end;flex-shrink:0;}
/* PILLS */
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;white-space:nowrap;}
.pill-dangerous{background:#fef2f2;color:#991b1b;}
.pill-caution{background:#fffbeb;color:#92400e;}
.pill-safe{background:#f0fdf4;color:#166534;}
/* BUTTONS */
.btn-dispatch{background:var(--red-light);color:#fff;border:none;padding:6px 13px;border-radius:8px;font-size:0.76rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background 0.18s;font-family:'Inter',sans-serif;white-space:nowrap;}
.btn-dispatch:hover{background:var(--red-mid);}
.btn-dispatch:disabled{opacity:0.5;cursor:not-allowed;}
.btn-assigned{background:#f0fdf4;color:#166534;border:1.5px solid #bbf7d0;padding:5px 12px;border-radius:8px;font-size:0.74rem;font-weight:700;white-space:nowrap;}
.btn-resolve-sm{background:#f0fdf4;color:#166534;border:1.5px solid #bbf7d0;padding:5px 12px;border-radius:8px;font-size:0.74rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:5px;font-family:'Inter',sans-serif;transition:all 0.18s;}
.btn-resolve-sm:hover{background:#dcfce7;}
.map-link{font-size:0.72rem;color:#2563eb;text-decoration:none;font-weight:600;display:inline-flex;align-items:center;gap:4px;}
.map-link:hover{text-decoration:underline;}
/* TABLE */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;border-collapse:collapse;min-width:480px;}
thead tr{background:#f8fafc;}
th{padding:10px 14px;font-size:0.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
td{padding:11px 14px;font-size:0.82rem;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
.cat-chip{display:inline-flex;align-items:center;gap:5px;font-size:0.72rem;font-weight:600;color:#0a3d62;background:#eff6ff;padding:3px 8px;border-radius:6px;white-space:nowrap;}
/* CONTACTS */
.contact-row{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.contact-row:last-child{border-bottom:none;}
.contact-icon{width:40px;height:40px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem;}
.contact-name{font-size:0.88rem;font-weight:700;margin-bottom:3px;}
.contact-meta{font-size:0.75rem;color:var(--muted);line-height:1.6;}
.contact-type-badge{display:inline-block;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;padding:2px 8px;border-radius:4px;margin-bottom:4px;}
/* EMPTY */
.empty{padding:48px 20px;text-align:center;color:var(--muted);}
.empty i{font-size:2.2rem;display:block;margin-bottom:12px;opacity:0.3;}
.empty p{font-size:0.86rem;}
/* COMING SOON */
.coming-soon{padding:60px 24px;text-align:center;}
.coming-soon i{font-size:3rem;color:var(--red-light);opacity:0.2;display:block;margin-bottom:16px;}
.coming-soon h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.coming-soon p{font-size:0.85rem;color:var(--muted);}
/* OVERLAY */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1100;}
.overlay.show{display:block;}
/* RESPONSIVE */
@media(max-width:860px){.sidebar{width:100dvw;max-width:100dvw;transform:translate3d(-100%,0,0);z-index:1200;left:0;right:0;}.sidebar.open{transform:translate3d(0,0,0);}.sb-close{display:flex;}.main{margin-left:0;}.ham-btn{display:flex;}.stat-row{grid-template-columns:1fr 1fr;}.content{padding:16px;}.topbar{padding:0 16px;}}
@media(max-width:860px){body.sidebar-open .main{display:none;}body.sidebar-open .overlay{z-index:1190;}}
@media(max-width:480px){.stat-row{grid-template-columns:1fr;}.badge-resp,.page-sub{display:none;}}

/* In-app navigation modal */
.nav-modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:2000;align-items:center;justify-content:center;}
.nav-modal-overlay.show{display:flex;}
.nav-modal{background:#fff;border-radius:14px;width:min(720px,94vw);max-height:92vh;display:flex;flex-direction:column;overflow:hidden;box-shadow:0 10px 40px rgba(0,0,0,0.3);}
.nav-modal-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);}
.nav-modal-head h3{font-size:0.94rem;font-weight:800;}
.nav-modal-close{background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--muted);}
#navModalMap{height:400px;width:100%;background:#f5e8e8;overflow:hidden;position:relative;}
#navMapInner{position:absolute;top:50%;left:50%;width:145%;height:145%;margin-top:-72.5%;margin-left:-72.5%;transition:transform 0.2s linear;transform-origin:50% 50%;}
.nav-arrow-icon{width:0;height:0;border-left:9px solid transparent;border-right:9px solid transparent;border-bottom:22px solid #2563eb;filter:drop-shadow(0 2px 4px rgba(0,0,0,0.4));transition:transform 0.2s linear;}
.nav-modal-info .nav-arrived{color:#16a34a;font-weight:800;}
.nav-modal-info{padding:10px 16px;font-size:0.8rem;color:var(--muted);border-top:1px solid var(--border);display:flex;gap:16px;flex-wrap:wrap;}
.nav-modal-info b{color:#222;}
</style>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-brand">
      <div class="sb-seal"><i class="fas fa-truck-medical"></i></div>
      <div><div class="sb-title">SenTri</div><div class="sb-sub">Responder Portal</div></div>
    </div>
    <button class="sb-close" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="sb-unit">
    <p>Unit</p>
    <strong><?= htmlspecialchars($unit) ?></strong>
    <div><span class="unit-badge" style="background:<?= $unit_color ?>;"><?= htmlspecialchars($rtype) ?></span></div>
  </div>
  <nav class="sb-nav">
    <?php foreach($nav_items as $key => $item): ?>
      <?php if($key === 'contacts'): ?><div class="sb-section">Reference</div><?php endif; ?>
      <?php if($key === 'profile'): ?><div class="sb-section">Account</div><?php endif; ?>
      <a href="responder.php?view=<?= $key ?>" class="<?= $view===$key?'active':'' ?>">
        <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
        <?php if($key==='assigned' && $my_count > 0): ?><span class="sb-badge"><?= $my_count ?></span><?php endif; ?>
        <?php if($key==='community' && $community_count > 0): ?><span class="sb-badge"><?= $community_count ?></span><?php endif; ?>
      </a>
    <?php endforeach; ?>
  </nav>
  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= strtoupper(substr($fname,0,1)) ?></div>
      <div style="min-width:0;"><div class="sb-uname"><?= htmlspecialchars($fname) ?></div><div class="sb-upos"><?= htmlspecialchars($pos) ?></div></div>
    </div>
    <a href="../logout.php" class="sb-logout"><i class="fas fa-right-from-bracket"></i> Sign Out</a>
  </div>
</aside>

<div class="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <div style="min-width:0;">
        <div class="page-title"><?= htmlspecialchars($page_titles[$view] ?? 'Responder Portal') ?></div>
        <div class="page-sub"><?= htmlspecialchars($unit) ?><?= $area ? ' &mdash; '.htmlspecialchars($area) : '' ?></div>
      </div>
    </div>
    <span class="badge-resp" style="color:<?= $unit_color ?>;border-color:<?= $unit_color ?>;background:<?= $unit_color ?>18;">
      <i class="fas fa-truck-medical"></i>&nbsp; <?= htmlspecialchars($rtype) ?>
    </span>
  </div>

  <?php if($view === 'queue' && $danger_count > 0): ?>
  <div class="alert-banner">
    <i class="fas fa-circle-exclamation"></i>
    <?= $danger_count ?> ACTIVE DANGEROUS INCIDENT<?= $danger_count > 1 ? 'S' : '' ?> — Immediate response required.
  </div>
  <?php endif; ?>

  <div class="content">

  <?php if($view === 'queue'): ?>
    <div class="card" style="margin-bottom:14px;">
      <div class="card-header">
        <h3><i class="fas fa-location-crosshairs" style="color:var(--red-light);margin-right:6px;"></i>My GPS Location</h3>
        <span class="card-meta" id="gpsStatus"><?= $saved_gps_lat !== null && $saved_gps_lng !== null ? 'Saved on profile' : 'Not saved yet' ?></span>
      </div>
      <div style="display:flex;gap:12px;flex-wrap:wrap;align-items:center;justify-content:space-between;">
        <div style="min-width:0;">
          <div id="gpsCoords" style="font-size:0.85rem;font-weight:700;color:var(--text);">
            <?= $saved_gps_lat !== null && $saved_gps_lng !== null ? htmlspecialchars(number_format((float)$saved_gps_lat, 6).', '.number_format((float)$saved_gps_lng, 6)) : 'Tap the button to get and save your current location.' ?>
          </div>
          <div style="font-size:0.76rem;color:var(--muted);margin-top:4px;">Used for navigation to assigned dangerous reports.</div>
        </div>
        <div style="display:flex;gap:8px;flex-wrap:wrap;">
          <button type="button" class="btn-dispatch" onclick="captureMyGps(this)"><i class="fas fa-crosshairs"></i> Get My GPS</button>
        </div>
      </div>
    </div>
    <div class="stat-row">
      <div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-num"><?= $danger_count ?></div><div class="stat-lbl">Dangerous</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="stat-num"><?= $caution_count ?></div><div class="stat-lbl">Caution</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-clipboard-check"></i></div><div><div class="stat-num"><?= $my_count ?></div><div class="stat-lbl">My Assignments</div></div></div>
    </div>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-siren-on" style="color:#dc2626;margin-right:6px;"></i>Active Incidents — Dispatch Queue</h3>
        <span class="card-meta"><?= count($queue) ?> incidents</span>
      </div>
      <?php if(empty($queue)): ?>
        <div class="empty"><i class="fas fa-shield-check" style="color:#16a34a;opacity:0.4;"></i><p>No active incidents. All clear.</p></div>
      <?php else: foreach($queue as $r):
        $is_mine = (int)$r['assigned_to'] === $uid;
        $is_assigned = $r['assigned_to'] !== null;
        $ibg = $r['status']==='dangerous' ? '#fef2f2' : '#fffbeb';
        $iclr = $r['status']==='dangerous' ? '#dc2626' : '#d97706';
      ?>
        <div class="incident-row">
          <div class="inc-icon" style="background:<?= $ibg ?>;color:<?= $iclr ?>;"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i></div>
          <div class="inc-body">
            <div class="inc-title"><?= htmlspecialchars($r['title']) ?></div>
            <div class="inc-meta">
              <span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
              <span><i class="fas fa-location-dot"></i> <?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></span>
              <span><?= date('M j, g:ia', strtotime($r['created_at'])) ?></span>
              <?php if($r['latitude']): ?>
              <a class="map-link" href="javascript:void(0)" onclick="viewOnMap(<?= (float)$r['latitude'] ?>, <?= (float)$r['longitude'] ?>, '<?= htmlspecialchars(addslashes($r['title']), ENT_QUOTES) ?>')"><i class="fas fa-map-pin"></i> View Map</a>
              <?php endif; ?>
            </div>
            <?php if($r['description']): ?><div style="font-size:0.76rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($r['description'],0,100,'…')) ?></div><?php endif; ?>
          </div>
          <div class="inc-actions">
            <?php if($is_mine): ?>
              <span class="btn-assigned"><i class="fas fa-check"></i> LGU Assigned</span>
              <?php if(empty($r['accepted_at'])): ?>
                <button class="btn-dispatch" onclick="acceptAssignment(<?= $r['id'] ?>,this)"><i class="fas fa-hand-pointer"></i> Accept Assignment</button>
              <?php endif; ?>
              <?php if(!empty($r['latitude']) && !empty($r['longitude'])): ?>
                <button class="btn-dispatch" onclick="openNavigation(<?= (float)$r['latitude'] ?>, <?= (float)$r['longitude'] ?>, <?= $r['id'] ?>)"><i class="fas fa-route"></i> Navigate</button>
              <?php endif; ?>
              <?php if(empty($r['responded_at'])): ?>
                <button class="btn-resolve-sm" onclick="markResponded(<?= $r['id'] ?>,this)"><i class="fas fa-bell"></i> Responded to LGU</button>
              <?php endif; ?>
              <button class="btn-resolve-sm" onclick="resolve(<?= $r['id'] ?>,this)"><i class="fas fa-circle-check"></i> Resolve</button>
            <?php elseif($is_assigned): ?>
              <span style="font-size:0.72rem;color:var(--muted);font-weight:600;">Assigned by LGU</span>
            <?php else: ?>
              <span style="font-size:0.72rem;color:var(--muted);font-weight:600;">Awaiting LGU dispatch</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'community'): ?>
    <div class="stat-row">
      <div class="stat-card"><div class="stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-users"></i></div><div><div class="stat-num"><?= $community_count ?></div><div class="stat-lbl">Community Posts</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-num"><?= $danger_count ?></div><div class="stat-lbl">Dangerous</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="stat-num"><?= $caution_count ?></div><div class="stat-lbl">Caution</div></div></div>
    </div>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-users" style="color:#0a3d62;margin-right:6px;"></i>Community Reports</h3>
        <span class="card-meta"><?= count($community_reports) ?> reports</span>
      </div>
      <?php if(empty($community_reports)): ?>
        <div class="empty"><i class="fas fa-people-group" style="color:#0a3d62;opacity:0.35;"></i><p>No community reports yet.</p></div>
      <?php else: foreach($community_reports as $r):
        $is_mine = $has_assigned_to ? ((int)($r['assigned_to'] ?? 0) === $uid) : false;
        $is_assigned = $has_assigned_to && !empty($r['assigned_to']);
        $ibg = $r['status']==='dangerous' ? '#fef2f2' : ($r['status']==='caution' ? '#fffbeb' : '#f0fdf4');
        $iclr = $r['status']==='dangerous' ? '#dc2626' : ($r['status']==='caution' ? '#d97706' : '#16a34a');
        $reporter = trim(($r['first_name'] ?? '').' '.($r['last_name'] ?? ''));
      ?>
        <div class="incident-row">
          <div class="inc-icon" style="background:<?= $ibg ?>;color:<?= $iclr ?>;"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i></div>
          <div class="inc-body">
            <div class="inc-title"><?= htmlspecialchars($r['title']) ?></div>
            <div class="inc-meta">
              <span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
              <span><i class="fas fa-user"></i> <?= htmlspecialchars($reporter ?: 'Community User') ?></span>
              <span><i class="fas fa-location-dot"></i> <?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></span>
              <span><?= date('M j, g:ia', strtotime($r['created_at'])) ?></span>
              <?php if(!empty($r['latitude']) && !empty($r['longitude'])): ?>
              <a class="map-link" href="javascript:void(0)" onclick="viewOnMap(<?= (float)$r['latitude'] ?>, <?= (float)$r['longitude'] ?>, '<?= htmlspecialchars(addslashes($r['title']), ENT_QUOTES) ?>')"><i class="fas fa-map-pin"></i> View Map</a>
              <?php endif; ?>
            </div>
            <?php if(!empty($r['description'])): ?><div style="font-size:0.76rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars(mb_strimwidth($r['description'],0,120,'…')) ?></div><?php endif; ?>
          </div>
          <div class="inc-actions">
            <?php if($is_mine): ?>
              <span class="btn-assigned"><i class="fas fa-check"></i> LGU Assigned</span>
            <?php elseif($is_assigned): ?>
              <span style="font-size:0.72rem;color:var(--muted);font-weight:600;">Assigned by LGU</span>
            <?php else: ?>
              <span style="font-size:0.72rem;color:var(--muted);font-weight:600;">LGU dispatch only</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'assigned'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-clipboard-check" style="color:var(--red-light);margin-right:6px;"></i>My Active Assignments</h3><span class="card-meta"><?= count($assigned) ?> reports</span></div>
      <?php if(empty($assigned)): ?>
        <div class="empty"><i class="fas fa-clipboard"></i><p>No active assignments. Check the dispatch queue.</p></div>
      <?php else: foreach($assigned as $r):
        $ibg = $r['status']==='dangerous' ? '#fef2f2' : ($r['status']==='caution' ? '#fffbeb' : '#f0fdf4');
        $iclr = $r['status']==='dangerous' ? '#dc2626' : ($r['status']==='caution' ? '#d97706' : '#16a34a');
      ?>
        <div class="incident-row">
          <div class="inc-icon" style="background:<?= $ibg ?>;color:<?= $iclr ?>;"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i></div>
          <div class="inc-body">
            <div class="inc-title"><?= htmlspecialchars($r['title']) ?></div>
            <div class="inc-meta">
              <span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span>
              <span><i class="fas fa-location-dot"></i> <?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></span>
              <span><?= date('M j, g:ia', strtotime($r['created_at'])) ?></span>
              <?php if($r['latitude']): ?>
              <a class="map-link" href="javascript:void(0)" onclick="viewOnMap(<?= (float)$r['latitude'] ?>, <?= (float)$r['longitude'] ?>, '<?= htmlspecialchars(addslashes($r['title']), ENT_QUOTES) ?>')"><i class="fas fa-map-pin"></i> View Map</a>
              <?php endif; ?>
            </div>
          </div>
          <div class="inc-actions">
            <?php if(empty($r['accepted_at'])): ?>
              <button class="btn-dispatch" onclick="acceptAssignment(<?= $r['id'] ?>,this)"><i class="fas fa-hand-pointer"></i> Accept Assignment</button>
            <?php endif; ?>
            <?php if(!empty($r['latitude']) && !empty($r['longitude'])): ?>
              <button class="btn-dispatch" onclick="openNavigation(<?= (float)$r['latitude'] ?>, <?= (float)$r['longitude'] ?>, <?= $r['id'] ?>)"><i class="fas fa-route"></i> Navigate</button>
            <?php endif; ?>
            <?php if(empty($r['responded_at'])): ?>
              <button class="btn-resolve-sm" onclick="markResponded(<?= $r['id'] ?>,this)"><i class="fas fa-bell"></i> Responded to LGU</button>
            <?php endif; ?>
            <button class="btn-resolve-sm" onclick="resolve(<?= $r['id'] ?>,this)"><i class="fas fa-circle-check"></i> Resolve</button>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'resolved'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-circle-check" style="color:#16a34a;margin-right:6px;"></i>Resolved by Me</h3><span class="card-meta"><?= count($resolved) ?> reports</span></div>
      <?php if(empty($resolved)): ?>
        <div class="empty"><i class="fas fa-circle-check" style="color:#16a34a;"></i><p>No resolved reports yet.</p></div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Location</th><th>Reported</th><th>Resolved</th></tr></thead>
          <tbody>
          <?php foreach($resolved as $r): ?>
            <tr>
              <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
              <td style="font-weight:600;max-width:200px;"><?= htmlspecialchars(mb_strimwidth($r['title'],0,50,'…')) ?></td>
              <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
              <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city'] ?? '') ?></td>
              <td style="font-size:0.74rem;color:var(--muted);"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
              <td style="font-size:0.74rem;color:#16a34a;font-weight:600;"><?= $r['resolved_at'] ? date('M j, Y', strtotime($r['resolved_at'])) : '—' ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

  <?php elseif($view === 'contacts'): ?>
    <?php
    $ctype_colors = ['lgu'=>['#f0f7ff','#0a3d62'],'hospital'=>['#ecfdf5','#059669'],'traffic'=>['#fffbeb','#d97706'],'barangay'=>['#f0fdf4','#166534'],'police'=>['#eff6ff','#2563eb'],'fire'=>['#fef2f2','#dc2626'],'other'=>['#f5f3ff','#7c3aed']];
    $ctype_icons = ['lgu'=>'fa-landmark','hospital'=>'fa-hospital','traffic'=>'fa-traffic-light','barangay'=>'fa-house-flag','police'=>'fa-shield','fire'=>'fa-fire-extinguisher','other'=>'fa-phone'];
    ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-address-book" style="color:var(--red-light);margin-right:6px;"></i>Emergency Contacts</h3><span class="card-meta"><?= count($contacts) ?> contacts</span></div>
      <?php if(empty($contacts)): ?><div class="empty"><i class="fas fa-address-book"></i><p>No emergency contacts on file.</p></div>
      <?php else: foreach($contacts as $c): $tc=$ctype_colors[$c['type']]??['#f5f3ff','#7c3aed']; $ti=$ctype_icons[$c['type']]??'fa-phone'; ?>
        <div class="contact-row">
          <div class="contact-icon" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><i class="fas <?= $ti ?>"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="contact-type-badge" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><?= strtoupper($c['type']) ?></div>
            <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="contact-meta">
              <?php if($c['contact_number']): ?><span><i class="fas fa-phone" style="color:var(--red-light);margin-right:4px;"></i><?= htmlspecialchars($c['contact_number']) ?></span>&ensp;<?php endif; ?>
              <?php if($c['contact_email']): ?><span><i class="fas fa-envelope" style="margin-right:4px;color:var(--muted);"></i><?= htmlspecialchars($c['contact_email']) ?></span>&ensp;<?php endif; ?>
              <?php if($c['city']): ?><span><i class="fas fa-location-dot" style="margin-right:4px;color:var(--muted);"></i><?= htmlspecialchars($c['barangay'] ? $c['barangay'].', '.$c['city'] : $c['city']) ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'profile'): ?>
    <?php list($msg_type,$msg_text) = $profile_msg ? explode(':',$profile_msg,2) : ['','']; ?>
    <style>
    .resp-profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
    .rpf-group label{display:block;font-size:0.78rem;font-weight:700;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;}
    .rpf-group input{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.9rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;color:var(--text);transition:all 0.18s;}
    .rpf-group input:focus{border-color:#fca5a5;background:#fff;box-shadow:0 0 0 3px rgba(220,38,38,0.08);}
    .rpf-group input[readonly]{background:#f3f4f6;color:var(--muted);cursor:not-allowed;}
    .rpf-divider{margin:20px 0 16px;padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
    .rpf-divider h4{font-size:0.82rem;font-weight:700;color:var(--red-dark);text-transform:uppercase;letter-spacing:1px;}
    .btn-rpf-save{background:linear-gradient(135deg,#991b1b,#7f1d1d);color:#fff;border:none;padding:11px 26px;border-radius:10px;font-size:0.9rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;display:inline-flex;align-items:center;gap:8px;transition:all 0.2s;}
    .btn-rpf-save:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(153,27,27,0.35);}
    .rpf-toast{padding:11px 16px;border-radius:9px;font-size:0.84rem;font-weight:600;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
    .rpf-toast.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .rpf-toast.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    </style>
    <div style="display:grid;grid-template-columns:300px 1fr;gap:18px;align-items:start;">
      <!-- Identity card -->
      <div class="card" style="padding:22px;text-align:center;">
        <div style="width:64px;height:64px;border-radius:50%;background:linear-gradient(135deg,#991b1b,#450a0a);display:flex;align-items:center;justify-content:center;font-size:1.5rem;font-weight:800;color:#fca5a5;margin:0 auto 12px;border:3px solid rgba(239,68,68,0.35);"><?= strtoupper(substr($fname,0,1)) ?></div>
        <div style="font-size:1.05rem;font-weight:800;"><?= htmlspecialchars($fname) ?></div>
        <div style="font-size:0.8rem;color:var(--muted);margin-top:3px;"><?= htmlspecialchars($pos?:'First Responder') ?></div>
        <div style="margin-top:10px;padding:7px 12px;border-radius:8px;font-size:0.78rem;font-weight:800;letter-spacing:1px;color:#fff;background:<?= $unit_color ?>;"><?= htmlspecialchars($rtype) ?></div>
        <div style="margin-top:8px;font-size:0.8rem;color:var(--muted);font-weight:600;"><?= htmlspecialchars($unit?:'Unit not set') ?></div>
        <?php if($area): ?><div style="margin-top:6px;font-size:0.78rem;color:var(--muted);"><i class="fas fa-location-dot" style="margin-right:3px;"></i><?= htmlspecialchars($area) ?></div><?php endif; ?>
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);display:grid;grid-template-columns:1fr 1fr;gap:8px;text-align:center;">
          <div style="background:#fef2f2;border-radius:8px;padding:10px;"><div style="font-size:1.2rem;font-weight:800;color:#dc2626;"><?= $danger_count ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Dangerous</div></div>
          <div style="background:#fffbeb;border-radius:8px;padding:10px;"><div style="font-size:1.2rem;font-weight:800;color:#d97706;"><?= $my_count ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">My Active</div></div>
        </div>
      </div>
      <!-- Edit form -->
      <div class="card" style="padding:22px;">
        <?php if($msg_text): ?><div class="rpf-toast <?= $msg_type ?>"><i class="fas fa-<?= $msg_type==='success'?'circle-check':'circle-xmark' ?>"></i><?= htmlspecialchars($msg_text) ?></div><?php endif; ?>
        <form method="POST" action="responder.php?view=profile">
          <input type="hidden" name="save_profile" value="1">
          <div class="rpf-divider"><h4><i class="fas fa-user" style="margin-right:6px;"></i>Personal Info</h4></div>
          <div class="resp-profile-grid" style="margin-bottom:14px;">
            <div class="rpf-group"><label>First Name</label><input type="text" name="first_name" value="<?= htmlspecialchars($fname) ?>" required></div>
            <div class="rpf-group"><label>Last Name</label><input type="text" name="last_name" value="<?= htmlspecialchars($prof['last_name']??'') ?>" required></div>
          </div>
          <div class="rpf-group" style="margin-bottom:14px;"><label>Phone Number</label><input type="tel" name="phone" value="<?= htmlspecialchars($p_phone_val) ?>" placeholder="+63 9XX XXX XXXX" style="max-width:280px;"></div>
          <div class="rpf-divider"><h4><i class="fas fa-truck-medical" style="margin-right:6px;"></i>Unit Info</h4></div>
          <div class="resp-profile-grid" style="margin-bottom:14px;">
            <div class="rpf-group"><label>Unit / Agency Name</label><input type="text" name="org_name" value="<?= htmlspecialchars($unit) ?>" placeholder="e.g. BFP Imus Station 1"></div>
            <div class="rpf-group"><label>Position / Rank</label><input type="text" name="position" value="<?= htmlspecialchars($pos) ?>" placeholder="e.g. Fire Officer I"></div>
          </div>
          <div class="rpf-group" style="margin-bottom:14px;"><label>Coverage Area</label><input type="text" name="municipality" value="<?= htmlspecialchars($area) ?>" placeholder="City/Municipality" style="max-width:280px;"></div>
          <div class="rpf-divider"><h4><i class="fas fa-lock" style="margin-right:6px;"></i>Change Password <span style="font-weight:400;color:var(--muted);text-transform:none;letter-spacing:0;">(leave blank to keep current)</span></h4></div>
          <div class="resp-profile-grid" style="margin-bottom:20px;">
            <div class="rpf-group"><label>New Password</label><input type="password" name="new_password" placeholder="Min. 8 characters" autocomplete="new-password"></div>
            <div class="rpf-group"><label>Confirm Password</label><input type="password" name="confirm_password" placeholder="Re-enter password" autocomplete="new-password"></div>
          </div>
          <button type="submit" class="btn-rpf-save"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </form>
      </div>
    </div>

  <?php elseif($view === 'map'): ?>
    <style>
    .resp-map-wrap{position:relative;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);border:1px solid var(--border);}
    #respMap{height:510px;width:100%;background:#f5e8e8;}
    .resp-map-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;background:#fff;border-bottom:1px solid var(--border);border-radius:14px 14px 0 0;}
    .resp-map-controls h3{font-size:0.88rem;font-weight:700;margin-right:4px;}
    .resp-filter-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.76rem;font-weight:700;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
    .resp-filter-btn.active-all{background:#991b1b;color:#fff;border-color:#991b1b;}
    .resp-filter-btn.active-dangerous{background:#dc2626;color:#fff;border-color:#dc2626;}
    .resp-filter-btn.active-caution{background:#d97706;color:#fff;border-color:#d97706;}
    .resp-map-legend{position:absolute;bottom:16px;right:12px;background:rgba(255,255,255,0.95);border-radius:10px;padding:11px 14px;font-size:0.76rem;box-shadow:0 2px 12px rgba(0,0,0,0.15);z-index:400;border:1px solid var(--border);}
    .resp-legend-row{display:flex;align-items:center;gap:7px;margin-bottom:5px;font-weight:600;}
    .resp-legend-row:last-child{margin-bottom:0;}
    .resp-legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
    .resp-map-stats{display:grid;grid-template-columns:repeat(3,1fr);gap:10px;margin-bottom:14px;}
    </style>
    <?php
    $rm_danger  = count(array_filter($map_reports, function($r){ return $r['status']==='dangerous'; }));
    $rm_caution = count(array_filter($map_reports, function($r){ return $r['status']==='caution'; }));
    ?>
    <div class="resp-map-stats">
      <div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-map-pin"></i></div><div><div class="stat-num"><?= count($map_reports) ?></div><div class="stat-lbl">Mapped Active</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-num"><?= $rm_danger ?></div><div class="stat-lbl">Dangerous</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="stat-num"><?= $rm_caution ?></div><div class="stat-lbl">Caution</div></div></div>
    </div>
    <div class="resp-map-wrap">
      <div class="resp-map-controls">
        <h3><i class="fas fa-map-location-dot" style="color:var(--red-light);margin-right:6px;"></i>Active Incident Map</h3>
        <button class="resp-filter-btn active-all" onclick="filterRespMap('all',this)">All Active</button>
        <button class="resp-filter-btn" onclick="filterRespMap('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
        <button class="resp-filter-btn" onclick="filterRespMap('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
        <div style="margin-left:auto;font-size:0.74rem;color:var(--muted);">© OpenStreetMap</div>
      </div>
      <div style="position:relative;">
        <div id="respMap"></div>
        <div class="resp-map-legend">
          <div class="resp-legend-row"><div class="resp-legend-dot" style="background:#dc2626;"></div>Dangerous</div>
          <div class="resp-legend-row"><div class="resp-legend-dot" style="background:#d97706;"></div>Caution</div>
        </div>
      </div>
    </div>
    <script>
    var respMapReports = <?= json_encode(array_map(function($r){
      return ['id'=>(int)$r['id'],'title'=>$r['title'],'category'=>$r['category'],
              'status'=>$r['status'],'location'=>($r['barangay']??$r['city']??''),
              'lat'=>(float)$r['latitude'],'lng'=>(float)$r['longitude'],
              'reporter'=>trim($r['first_name'].' '.$r['last_name']),
              'date'=>date('M j, g:ia',strtotime($r['created_at'])),
              'desc'=>$r['description']??''];
    }, $map_reports)) ?>;
    var rmc = {dangerous:'#dc2626',caution:'#d97706'};
    var catL = {crime:'Crime',accident:'Accident',flooding:'Flooding',fire:'Fire',health:'Health',infrastructure:'Infrastructure',other:'Other'};
    function makeRespIcon(color){
      return L.divIcon({className:'',html:'<div style="width:15px;height:15px;border-radius:50%;background:'+color+';border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>',iconSize:[15,15],iconAnchor:[7,7]});
    }
    var rmap = L.map('respMap');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(rmap);
    var rMarkers=[];
    respMapReports.forEach(function(r){
      var c=rmc[r.status]||'#888';
      var m=L.marker([r.lat,r.lng],{icon:makeRespIcon(c)});
      m.rd=r;
      m.bindPopup(
        '<div style="min-width:200px;font-family:Inter,sans-serif;">'+
        '<div style="font-weight:800;font-size:0.9rem;margin-bottom:6px;">'+r.title+'</div>'+
        '<div style="margin-bottom:7px;"><span style="background:'+(r.status==='dangerous'?'#fef2f2':'#fffbeb')+';color:'+(r.status==='dangerous'?'#991b1b':'#92400e')+';padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:700;">'+(r.status.charAt(0).toUpperCase()+r.status.slice(1))+'</span></div>'+
        (r.location?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:3px;"><b>Location:</b> '+r.location+'</div>':'')+
        '<div style="font-size:0.78rem;color:#6b7280;margin-bottom:3px;"><b>Category:</b> '+(catL[r.category]||r.category)+'</div>'+
        '<div style="font-size:0.78rem;color:#6b7280;margin-bottom:6px;"><b>Reported:</b> '+r.date+'</div>'+
        (r.desc?'<div style="font-size:0.78rem;color:#374151;margin-bottom:8px;">'+r.desc.substring(0,100)+(r.desc.length>100?'…':'')+'</div>':'')+
        '<a href="javascript:void(0)" onclick="closeNavModal();openNavigation('+r.lat+','+r.lng+',\''+r.id+'\')" style="font-size:0.8rem;color:#2563eb;font-weight:700;text-decoration:none;display:inline-flex;align-items:center;gap:4px;"><i class="fas fa-directions"></i> Get Directions</a>'+
        '</div>',{maxWidth:240}
      );
      m.addTo(rmap); rMarkers.push(m);
    });
    if(rMarkers.length>0){var rg=L.featureGroup(rMarkers);rmap.fitBounds(rg.getBounds().pad(0.2));}
    else{rmap.setView([14.5995,120.9842],12);}
    function filterRespMap(s,btn){
      document.querySelectorAll('.resp-filter-btn').forEach(function(b){b.className='resp-filter-btn';});
      btn.classList.add('active-'+s);
      rMarkers.forEach(function(m){
        var show=(s==='all'||m.rd.status===s);
        if(show){if(!rmap.hasLayer(m))m.addTo(rmap);}else{if(rmap.hasLayer(m))rmap.removeLayer(m);}
      });
    }
    // Auto-refresh map every 2 minutes
    setTimeout(function(){location.reload();},120000);
    </script>

  <?php else: ?>
    <div class="card"><div class="coming-soon"><i class="fas <?= $nav_items[$view]['icon'] ?? 'fa-gear' ?>"></i><h3><?= htmlspecialchars($page_titles[$view] ?? ucfirst($view)) ?></h3><p>This section is under development.</p></div></div>
  <?php endif; ?>

  </div>
</div>

<script>
function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('overlay').classList.add('show');document.body.classList.add('sidebar-open');document.body.style.overflow='hidden';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('show');document.body.classList.remove('sidebar-open');document.body.style.overflow='';}

var responderGps = { lat: <?= $saved_gps_lat !== null ? json_encode((float)$saved_gps_lat) : 'null' ?>, lng: <?= $saved_gps_lng !== null ? json_encode((float)$saved_gps_lng) : 'null' ?> };

function setGpsText(lat, lng, saved){
  var coords = document.getElementById('gpsCoords');
  var status = document.getElementById('gpsStatus');
  if(coords){ coords.textContent = Number(lat).toFixed(6) + ', ' + Number(lng).toFixed(6); }
  if(status){ status.textContent = saved ? 'Saved on profile' : 'Current location'; }
}

async function captureMyGps(btn){
  if(!navigator.geolocation){ alert('Geolocation is not supported on this device.'); return; }
  var prev = btn.innerHTML; btn.disabled = true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Locating';
  navigator.geolocation.getCurrentPosition(async function(pos){
    var lat = pos.coords.latitude;
    var lng = pos.coords.longitude;
    responderGps.lat = lat; responderGps.lng = lng;
    setGpsText(lat, lng, false);
    try{
      var fd = new FormData(); fd.append('action','save_gps'); fd.append('latitude', lat); fd.append('longitude', lng);
      var res = await fetch('../api/reports.php', {method:'POST', body:fd});
      var data = await res.json();
      if(data.status==='success'){
        responderGps.lat = data.lat; responderGps.lng = data.lng;
        setGpsText(data.lat, data.lng, true);
        alert('GPS location saved.');
      } else {
        alert(data.message || 'Could not save GPS location.');
      }
    } catch(e){
      alert('Could not save GPS location.');
    }
    btn.disabled = false; btn.innerHTML = prev;
  }, function(){
    btn.disabled = false; btn.innerHTML = prev;
    alert('Location access was denied.');
  }, {enableHighAccuracy:true, timeout:12000, maximumAge:0});
}

var navMap=null, navLayer=null, navWatchId=null, navHeading=0, navOrigMarker=null, navArrived=false, navDest=null, navReportId=null;
var ARRIVAL_RADIUS_M = 40;
function ensureNavMap(){
  if(!navMap){
    navMap = L.map('navMapInner',{zoomControl:false,attributionControl:true});
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'\u00a9 OpenStreetMap'}).addTo(navMap);
  }
  if(navLayer){ navMap.removeLayer(navLayer); navLayer=null; }
  document.getElementById('navMapInner').style.transform='rotate(0deg)';
}
function haversine(lat1,lon1,lat2,lon2){
  var R=6371000, toRad=function(d){return d*Math.PI/180;};
  var dLat=toRad(lat2-lat1), dLon=toRad(lon2-lon1);
  var a=Math.sin(dLat/2)*Math.sin(dLat/2)+Math.cos(toRad(lat1))*Math.cos(toRad(lat2))*Math.sin(dLon/2)*Math.sin(dLon/2);
  return R*2*Math.atan2(Math.sqrt(a),Math.sqrt(1-a));
}
function stopNavTracking(){
  if(navWatchId!==null){ navigator.geolocation.clearWatch(navWatchId); navWatchId=null; }
  window.removeEventListener('deviceorientationabsolute',onNavOrientation);
  window.removeEventListener('deviceorientation',onNavOrientation);
  navDest=null; navReportId=null; navArrived=false; navOrigMarker=null;
}
function closeNavModal(){
  document.getElementById('navModalOverlay').classList.remove('show');
  stopNavTracking();
}
function onNavOrientation(e){
  var heading = (typeof e.webkitCompassHeading !== 'undefined') ? e.webkitCompassHeading : (e.alpha!==null ? (360-e.alpha) : null);
  if(heading===null) return;
  navHeading = heading;
  applyNavRotation();
}
function applyNavRotation(){
  document.getElementById('navMapInner').style.transform='rotate('+(-navHeading)+'deg)';
  if(navOrigMarker){
    var el=navOrigMarker.getElement();
    if(el){ var arrow=el.querySelector('.nav-arrow-icon'); if(arrow) arrow.style.transform='rotate('+navHeading+'deg)'; }
  }
}
function viewOnMap(lat, lng, title){
  if(lat===null||lng===null||typeof lat==='undefined'||typeof lng==='undefined'||lat===''||lng===''){
    alert('This report has no GPS coordinates.'); return;
  }
  document.getElementById('navModalTitle').textContent = title || 'Incident Location';
  document.getElementById('navModalInfo').innerHTML = '';
  document.getElementById('navModalOverlay').classList.add('show');
  setTimeout(function(){
    ensureNavMap();
    navMap.setView([lat,lng],16);
    navLayer = L.marker([lat,lng]).addTo(navMap);
    navMap.invalidateSize();
  },50);
}
async function markArrivedSilently(reportId){
  try{
    var fd=new FormData(); fd.append('action','report_responded'); fd.append('report_id',reportId);
    await fetch('../api/reports.php',{method:'POST',body:fd});
  }catch(e){}
}
function drawNavRoute(group){
  var url = 'https://router.project-osrm.org/route/v1/driving/'+responderGps.lng+','+responderGps.lat+';'+navDest.lng+','+navDest.lat+'?overview=full&geometries=geojson';
  fetch(url).then(function(r){return r.json();}).then(function(data){
    if(navLayer) navMap.removeLayer(navLayer);
    if(data.routes && data.routes[0]){
      var route = data.routes[0];
      var coords = route.geometry.coordinates.map(function(c){return [c[1],c[0]];});
      var line = L.polyline(coords,{color:'#2563eb',weight:5,opacity:0.8}).addTo(navMap);
      navLayer = L.featureGroup(group.concat([line]));
      var km = (route.distance/1000).toFixed(1);
      var mins = Math.round(route.duration/60);
      document.getElementById('navModalInfo').innerHTML = '<span><b>Distance:</b> '+km+' km</span><span><b>ETA:</b> '+mins+' min</span>';
    } else {
      navLayer = L.featureGroup(group);
      document.getElementById('navModalInfo').innerHTML = '<span>Route unavailable.</span>';
    }
  }).catch(function(){
    navLayer = L.featureGroup(group);
    document.getElementById('navModalInfo').innerHTML = '<span>Route unavailable.</span>';
  });
}
function onNavPosition(pos){
  var lat=pos.coords.latitude, lng=pos.coords.longitude;
  responderGps.lat=lat; responderGps.lng=lng;
  if(pos.coords.heading!==null && !isNaN(pos.coords.heading)){
    navHeading = pos.coords.heading;
    applyNavRotation();
  }
  var arrowIcon = L.divIcon({className:'',html:'<div class="nav-arrow-icon" style="transform:rotate('+navHeading+'deg);"></div>',iconSize:[18,22],iconAnchor:[9,16]});
  if(!navOrigMarker){
    navOrigMarker = L.marker([lat,lng],{icon:arrowIcon}).addTo(navMap).bindPopup('Your Location');
  } else {
    navOrigMarker.setLatLng([lat,lng]);
    navOrigMarker.setIcon(arrowIcon);
  }
  navMap.setView([lat,lng], navMap.getZoom() < 15 ? 16 : navMap.getZoom());
  if(navDest){
    var destMarker = navOrigMarker._destRef;
    var group=[navOrigMarker]; if(destMarker) group.push(destMarker);
    drawNavRoute(group);
    var dist = haversine(lat,lng,navDest.lat,navDest.lng);
    if(dist <= ARRIVAL_RADIUS_M && !navArrived){
      navArrived = true;
      document.getElementById('navModalInfo').innerHTML = '<span class="nav-arrived"><i class="fas fa-flag-checkered"></i> You have arrived! Marking as responded\u2026</span>';
      var rid = navReportId;
      stopNavTracking();
      markArrivedSilently(rid).then(function(){
        setTimeout(function(){ closeNavModal(); location.reload(); }, 1200);
      });
    }
  }
}
function openNavigation(lat, lng, reportId){
  if(lat === null || lng === null || typeof lat === 'undefined' || typeof lng === 'undefined' || lat === '' || lng === ''){
    alert('This report has no GPS coordinates.');
    return;
  }
  document.getElementById('navModalTitle').textContent = 'Navigate to Incident';
  document.getElementById('navModalInfo').innerHTML = '<span>Loading route\u2026</span>';
  document.getElementById('navModalOverlay').classList.add('show');
  navDest = {lat:lat,lng:lng}; navReportId = reportId; navArrived=false;
  setTimeout(function(){
    ensureNavMap();
    navMap.setView([lat,lng],15);
    var destIcon = L.divIcon({className:'',html:'<div style="width:16px;height:16px;border-radius:50%;background:#dc2626;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>',iconSize:[16,16],iconAnchor:[8,8]});
    var destMarker = L.marker([lat,lng],{icon:destIcon}).addTo(navMap).bindPopup('Incident Location');
    var group = [destMarker];
    navLayer = L.featureGroup(group);
    navMap.fitBounds(navLayer.getBounds().pad(0.25));
    navMap.invalidateSize();

    if(!navigator.geolocation){
      document.getElementById('navModalInfo').innerHTML = '<span>Geolocation not supported on this device.</span>';
      return;
    }
    if(window.DeviceOrientationEvent && typeof DeviceOrientationEvent.requestPermission === 'function'){
      DeviceOrientationEvent.requestPermission().then(function(state){
        if(state==='granted') window.addEventListener('deviceorientation', onNavOrientation, true);
      }).catch(function(){});
    } else {
      window.addEventListener('deviceorientationabsolute', onNavOrientation, true);
      window.addEventListener('deviceorientation', onNavOrientation, true);
    }
    navWatchId = navigator.geolocation.watchPosition(function(pos){
      onNavPosition(pos);
      if(navOrigMarker) navOrigMarker._destRef = destMarker;
    }, function(){
      document.getElementById('navModalInfo').innerHTML = '<span>Location access denied \u2014 showing static route.</span>';
      if(responderGps.lat!==null && responderGps.lng!==null){
        var origIcon = L.divIcon({className:'',html:'<div style="width:16px;height:16px;border-radius:50%;background:#2563eb;border:3px solid #fff;box-shadow:0 2px 8px rgba(0,0,0,0.4);"></div>',iconSize:[16,16],iconAnchor:[8,8]});
        var origMarker = L.marker([responderGps.lat,responderGps.lng],{icon:origIcon}).addTo(navMap).bindPopup('Your Location');
        drawNavRoute([destMarker, origMarker]);
      }
    }, {enableHighAccuracy:true, timeout:15000, maximumAge:5000});
  },50);
}

async function acceptAssignment(id,btn){
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
  try{
    var fd=new FormData(); fd.append('action','accept_assignment'); fd.append('report_id',id);
    var res=await fetch('../api/reports.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success') location.reload();
    else{ alert(data.message||'Error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-hand-pointer"></i> Accept Assignment'; }
  }catch(e){ btn.disabled=false; btn.innerHTML='<i class="fas fa-hand-pointer"></i> Accept Assignment'; }
}

async function markResponded(id,btn){
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
  try{
    var fd=new FormData(); fd.append('action','report_responded'); fd.append('report_id',id);
    var res=await fetch('../api/reports.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success') location.reload();
    else{ alert(data.message||'Error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-bell"></i> Responded to LGU'; }
  }catch(e){ btn.disabled=false; btn.innerHTML='<i class="fas fa-bell"></i> Responded to LGU'; }
}

async function resolve(id,btn){
  if(!confirm('Mark this incident as resolved?')) return;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
  try{
    var fd=new FormData(); fd.append('action','resolve_report'); fd.append('report_id',id);
    var res=await fetch('../api/reports.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success') location.reload();
    else{ alert(data.message||'Error.'); btn.disabled=false; btn.innerHTML='<i class="fas fa-circle-check"></i> Resolve'; }
  }catch(e){ btn.disabled=false; btn.innerHTML='<i class="fas fa-circle-check"></i> Resolve'; }
}

// Auto-refresh queue every 90 seconds
<?php if($view === 'queue'): ?>
setTimeout(function(){location.reload();}, 90000);
<?php endif; ?>
</script>

<div class="nav-modal-overlay" id="navModalOverlay">
  <div class="nav-modal">
    <div class="nav-modal-head">
      <h3 id="navModalTitle"><i class="fas fa-route" style="margin-right:6px;color:var(--red-light);"></i>Navigation</h3>
      <button class="nav-modal-close" onclick="closeNavModal()"><i class="fas fa-times"></i></button>
    </div>
    <div id="navModalMap"><div id="navMapInner"></div></div>
    <div class="nav-modal-info" id="navModalInfo"></div>
  </div>
</div>
</body>
</html>

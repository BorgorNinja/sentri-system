<?php
session_start();
require_once __DIR__ . '/../config/auth.php';
require_role(['lgu']);
require_once __DIR__ . '/../config/db.php';

$uid   = (int)$_SESSION['user_id'];
$fname = $_SESSION['first_name'];
$view  = $_GET['view'] ?? 'overview';

$stmt = $conn->prepare("SELECT email,last_name,org_name,`position`,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i",$uid); $stmt->execute();
$prof = $stmt->get_result()->fetch_assoc(); $stmt->close();
$city = $prof['municipality'] ?? $prof['barangay_name'] ?? '';
$org  = $prof['org_name']     ?? 'LGU Office';
$pos  = $prof['position']     ?? 'LGU Official';

function cq($conn,$sql,$types='',$params=[]){
    $s=$conn->prepare($sql);
    if($types && count($params)){
        $refs=[];
        foreach($params as &$v) $refs[]=&$v;
        array_unshift($refs,$types);
        call_user_func_array([$s,'bind_param'],$refs);
    }
    $s->execute(); $s->bind_result($n); $s->fetch(); $s->close();
    return (int)$n;
}

$total    = cq($conn,"SELECT COUNT(*) FROM reports WHERE is_archived=0");
$danger   = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0");
$caution  = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='caution' AND is_archived=0");
$safe     = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='safe' AND is_archived=0");
$contacts_count = cq($conn,"SELECT COUNT(*) FROM emergency_contacts WHERE is_active=1");

$cat_icons = ['crime'=>'fa-user-slash','accident'=>'fa-car-burst','flooding'=>'fa-water','fire'=>'fa-fire','health'=>'fa-heart-pulse','infrastructure'=>'fa-road-barrier','other'=>'fa-circle-exclamation'];

// ── Per-view data ──────────────────────────────────────────────────────────
$danger_reports = $all_reports = $brgy_stats = $contacts = [];

if ($view === 'overview' || $view === 'all_reports') {
    $limit = ($view === 'overview') ? 15 : 100;
    $status_filter = ($view === 'overview') ? "AND r.status='dangerous'" : "";
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.created_at,u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 $status_filter ORDER BY r.created_at DESC LIMIT $limit");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) {
        if($view==='overview') $danger_reports[]=$row; else $all_reports[]=$row;
    }
    $s->close();
}

if ($view === 'overview' || $view === 'barangays') {
    $bs = $conn->query("SELECT COALESCE(barangay,'Unspecified') as b, COUNT(*) as c, SUM(status='dangerous') as d FROM reports WHERE is_archived=0 GROUP BY b ORDER BY c DESC LIMIT 15");
    if ($bs) while($r=$bs->fetch_assoc()) $brgy_stats[]=$r;
}

if ($view === 'contacts') {
    $cs = $conn->query("SELECT id,name,type,contact_number,contact_email,barangay,city,is_active FROM emergency_contacts ORDER BY type,name");
    if ($cs) while($r=$cs->fetch_assoc()) $contacts[]=$r;
}

// ── MAP ──────────────────────────────────────────────────────────────────
$map_reports = [];
if ($view === 'map') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description FROM reports r WHERE r.is_archived=0 AND r.latitude IS NOT NULL AND r.longitude IS NOT NULL ORDER BY r.created_at DESC LIMIT 500");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $map_reports[]=$row;
    $s->close();
    // fallback: all reports including no-coords for the sidebar count
}
$map_total = cq($conn,"SELECT COUNT(*) FROM reports WHERE is_archived=0");

// ── PROFILE ───────────────────────────────────────────────────────────────
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
        $_SESSION['first_name'] = htmlspecialchars($p_fname,ENT_QUOTES,'UTF-8');
        $fname=$_SESSION['first_name']; $org=$p_org?:$org; $city=$p_muni?:$city; $pos=$p_pos?:$pos;
        $stmt2=$conn->prepare("SELECT email,org_name,`position`,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
        $stmt2->bind_param("i",$uid); $stmt2->execute();
        $prof=$stmt2->get_result()->fetch_assoc(); $stmt2->close();
        $profile_msg = 'success:Profile updated successfully.';
    }
}

// ── RESPONDERS ────────────────────────────────────────────────────────────
$responders = [];
if ($view === 'responders') {
    $s=$conn->prepare("
        SELECT u.id,u.first_name,u.last_name,u.email,u.phone_number,
               u.org_name,u.`position`,u.responder_type,u.municipality,
               u.barangay_name,u.is_approved,
               COUNT(r.id) as active_count
        FROM users u
        LEFT JOIN reports r ON r.assigned_to=u.id AND r.is_archived=0 AND r.status IN('dangerous','caution')
        WHERE u.role='first_responder'
        GROUP BY u.id
        ORDER BY u.is_approved ASC, u.responder_type, u.org_name
    ");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()){ $row['active_count']=(int)$row['active_count']; $responders[]=$row; }
    $s->close();
}

// ── RESPONDER APPROVE/REJECT (POST) ──────────────────────────────────────
if ($view === 'responders' && $_SERVER['REQUEST_METHOD']==='POST') {
    header('Content-Type: application/json');
    $act = $_POST['action'] ?? '';
    $tid = (int)($_POST['user_id'] ?? 0);
    if (!$tid) { echo json_encode(['status'=>'error','message'=>'Invalid user.']); exit; }
    if ($act === 'approve_responder') {
        $s=$conn->prepare("UPDATE users SET is_approved=1 WHERE id=? AND role='first_responder'");
        $s->bind_param("i",$tid); $s->execute(); $s->close();
        echo json_encode(['status'=>'success','message'=>'Responder approved.']); exit;
    }
    if ($act === 'reject_responder') {
        $s=$conn->prepare("DELETE FROM users WHERE id=? AND role='first_responder' AND is_approved=0");
        $s->bind_param("i",$tid); $s->execute(); $s->close();
        echo json_encode(['status'=>'success','message'=>'Responder rejected.']); exit;
    }
}

$map_reports = [];
if ($view === 'map') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description FROM reports r WHERE r.is_archived=0 AND r.latitude IS NOT NULL AND r.longitude IS NOT NULL ORDER BY r.created_at DESC LIMIT 500");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $map_reports[]=$row;
    $s->close();
}

$profile_msg = '';
if ($view === 'profile' && $_SERVER['REQUEST_METHOD']==='POST' && isset($_POST['save_profile'])) {
    $p_fname  = trim($_POST['first_name']   ?? '');
    $p_lname  = trim($_POST['last_name']    ?? '');
    $p_phone  = trim($_POST['phone']        ?? '');
    $p_org    = trim($_POST['org_name']     ?? '');
    $p_pos    = trim($_POST['position']     ?? '');
    $p_muni   = trim($_POST['municipality'] ?? '');
    $p_pw     = $_POST['new_password']      ?? '';
    $p_pw2    = $_POST['confirm_password']  ?? '';
    if ($p_pw && $p_pw !== $p_pw2) {
        $profile_msg = 'error:Passwords do not match.';
    } elseif (!$p_fname || !$p_lname) {
        $profile_msg = 'error:Name fields are required.';
    } else {
        if ($p_pw) {
            $hash = password_hash($p_pw, PASSWORD_BCRYPT, ['cost'=>10]);
            $su = $conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,org_name=?,`position`=?,municipality=?,password=? WHERE id=?");
            $su->bind_param("sssssssi",$p_fname,$p_lname,$p_phone,$p_org,$p_pos,$p_muni,$hash,$uid);
        } else {
            $su = $conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,org_name=?,`position`=?,municipality=? WHERE id=?");
            $su->bind_param("ssssssi",$p_fname,$p_lname,$p_phone,$p_org,$p_pos,$p_muni,$uid);
        }
        $su->execute(); $su->close();
        $_SESSION['first_name'] = htmlspecialchars($p_fname, ENT_QUOTES, 'UTF-8');
        $fname = $_SESSION['first_name'];
        $org   = $p_org ?: $org;
        $city  = $p_muni ?: $city;
        $pos   = $p_pos ?: $pos;
        // Refresh prof
        $stmt2 = $conn->prepare("SELECT email,org_name,`position`,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
        $stmt2->bind_param("i",$uid); $stmt2->execute();
        $prof = $stmt2->get_result()->fetch_assoc(); $stmt2->close();
        $profile_msg = 'success:Profile updated successfully.';
    }
}

$responders = [];
if ($view === 'responders') {
    $s = $conn->prepare("
        SELECT u.id,u.first_name,u.last_name,u.email,u.phone_number,
               u.org_name,u.`position`,u.responder_type,u.municipality,
               u.barangay_name,u.is_approved,
               COUNT(r.id) as active_count
        FROM users u
        LEFT JOIN reports r ON r.assigned_to=u.id AND r.is_archived=0 AND r.status IN('dangerous','caution')
        WHERE u.role='first_responder'
        GROUP BY u.id
        ORDER BY u.is_approved ASC, u.responder_type, u.org_name
    ");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) { $row['active_count']=(int)$row['active_count']; $responders[]=$row; }
    $s->close();
}

$nav_items = [
    'overview'    => ['icon'=>'fa-gauge',            'label'=>'Overview'],
    'all_reports' => ['icon'=>'fa-file-lines',        'label'=>'All Incident Reports'],
    'map'         => ['icon'=>'fa-map-location-dot',  'label'=>'Incident Map'],
    'contacts'    => ['icon'=>'fa-address-book',      'label'=>'Emergency Contacts'],
    'barangays'   => ['icon'=>'fa-house-flag',        'label'=>'Barangay Summaries'],
    'responders'  => ['icon'=>'fa-truck-medical',     'label'=>'Responder Units'],
    'profile'     => ['icon'=>'fa-id-card',           'label'=>'My Profile'],
];
$page_titles = [
    'overview'    => 'LGU Operations Dashboard',
    'all_reports' => 'All Incident Reports',
    'map'         => 'Incident Map',
    'contacts'    => 'Emergency Contacts',
    'barangays'   => 'Barangay Summaries',
    'responders'  => 'Responder Units',
    'profile'     => 'My Profile',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $page_titles[$view] ?? 'LGU Portal' ?> — SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --navy:#0a3d62;--navy-dark:#062444;--navy-light:#1a5276;
  --gold:#f39c12;--text:#111827;--muted:#6b7280;
  --border:#e5e7eb;--bg:#f1f5f9;--card:#fff;--sidebar-w:256px;
}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html,body{height:100%;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sidebar-w);flex-shrink:0;
  background:linear-gradient(180deg,var(--navy-dark) 0%,var(--navy) 55%,var(--navy-light) 100%);
  color:#fff;display:flex;flex-direction:column;
  position:fixed;top:0;left:0;bottom:0;z-index:200;
  box-shadow:4px 0 20px rgba(0,0,0,0.25);
  transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);
}
.sb-header{
  padding:18px 16px;border-bottom:1px solid rgba(255,255,255,0.1);
  display:flex;align-items:center;justify-content:space-between;flex-shrink:0;
}
.sb-brand{display:flex;align-items:center;gap:10px;}
.sb-seal{
  width:40px;height:40px;border-radius:50%;
  background:rgba(243,156,18,0.2);border:2px solid rgba(243,156,18,0.5);
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;color:var(--gold);flex-shrink:0;
}
.sb-title{font-size:0.96rem;font-weight:800;line-height:1.2;}
.sb-sub{font-size:0.58rem;color:rgba(255,255,255,0.45);letter-spacing:1.5px;text-transform:uppercase;}
.sb-close{
  background:none;border:none;color:rgba(255,255,255,0.6);
  font-size:1.1rem;cursor:pointer;padding:4px 6px;border-radius:6px;
  display:none;flex-shrink:0;
}
.sb-office{
  padding:10px 16px;background:rgba(0,0,0,0.2);
  border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;
}
.sb-office p{font-size:0.65rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.sb-office strong{font-size:0.82rem;color:#fff;font-weight:700;display:block;word-break:break-word;}
.sb-nav{padding:12px 8px;flex:1;overflow-y:auto;}
.sb-nav a{
  display:flex;align-items:center;gap:10px;
  color:rgba(255,255,255,0.7);text-decoration:none;
  font-size:0.84rem;font-weight:500;
  padding:10px 12px;border-radius:10px;
  transition:all 0.18s;margin-bottom:2px;
  white-space:nowrap;overflow:hidden;
}
.sb-nav a:hover{background:rgba(255,255,255,0.1);color:#fff;}
.sb-nav a.active{background:rgba(255,255,255,0.15);color:#fff;font-weight:700;}
.sb-nav a i{width:18px;text-align:center;font-size:0.92rem;flex-shrink:0;}
.sb-nav a span{overflow:hidden;text-overflow:ellipsis;}
.sb-section{
  font-size:0.62rem;color:rgba(255,255,255,0.28);
  letter-spacing:2px;text-transform:uppercase;
  padding:14px 12px 5px;font-weight:700;
}
.sb-footer{
  padding:12px 16px;border-top:1px solid rgba(255,255,255,0.1);flex-shrink:0;
}
.sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.sb-avatar{
  width:34px;height:34px;border-radius:50%;flex-shrink:0;
  background:rgba(243,156,18,0.25);
  display:flex;align-items:center;justify-content:center;
  font-size:0.88rem;font-weight:800;color:var(--gold);
}
.sb-uname{font-size:0.82rem;font-weight:700;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-upos{font-size:0.65rem;color:rgba(255,255,255,0.4);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-logout{
  display:flex;align-items:center;gap:8px;
  color:rgba(255,255,255,0.55);text-decoration:none;
  font-size:0.8rem;font-weight:600;padding:8px 10px;
  border-radius:8px;transition:all 0.18s;
}
.sb-logout:hover{background:rgba(220,38,38,0.2);color:#fca5a5;}

/* ── MAIN ── */
.main{
  margin-left:var(--sidebar-w);flex:1;
  display:flex;flex-direction:column;min-width:0;
  min-height:100vh;
}

/* ── TOPBAR ── */
.topbar{
  background:#fff;
  border-bottom:4px solid var(--navy);
  padding:0 24px;height:64px;
  display:flex;align-items:center;justify-content:space-between;
  position:sticky;top:0;z-index:100;
  box-shadow:0 2px 12px rgba(0,0,0,0.08);
  position:relative; /* needed for gov-strip */
}
.topbar{position:sticky;top:0;} /* override to sticky */
.gov-strip{
  position:absolute;top:0;left:0;right:0;height:4px;
  background:linear-gradient(90deg,var(--navy-dark) 0%,var(--navy) 50%,var(--gold) 100%);
}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0;}
.ham-btn{
  background:none;border:none;font-size:1.15rem;
  color:var(--muted);cursor:pointer;
  padding:7px;border-radius:8px;
  display:none;flex-shrink:0;
}
.ham-btn:hover{background:#f3f4f6;}
.page-title{font-size:1rem;font-weight:800;color:var(--navy);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.page-sub{font-size:0.72rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.badge-lgu{
  background:#f0f7ff;color:var(--navy);
  font-size:0.71rem;font-weight:700;
  padding:5px 12px;border-radius:20px;
  border:1px solid #bfdbfe;white-space:nowrap;flex-shrink:0;
}

/* ── CONTENT ── */
.content{padding:22px 24px;flex:1;}

/* ── STATS ── */
.stat-grid{
  display:grid;
  grid-template-columns:repeat(5,1fr);
  gap:14px;margin-bottom:22px;
}
.stat-card{
  background:var(--card);border-radius:14px;
  padding:16px;
  box-shadow:0 2px 8px rgba(0,0,0,0.05);
  border:1px solid var(--border);
}
.stat-icon{
  width:40px;height:40px;border-radius:10px;
  display:flex;align-items:center;justify-content:center;
  font-size:1.1rem;margin-bottom:10px;
}
.stat-num{font-size:1.55rem;font-weight:800;line-height:1;color:var(--text);}
.stat-lbl{font-size:0.71rem;color:var(--muted);font-weight:600;margin-top:3px;}

/* ── TWO COL ── */
.two-col{display:grid;grid-template-columns:3fr 2fr;gap:18px;}

/* ── CARDS ── */
.card{
  background:var(--card);border-radius:14px;
  box-shadow:0 2px 10px rgba(0,0,0,0.06);
  border:1px solid var(--border);overflow:hidden;
}
.card-header{
  padding:14px 18px;border-bottom:1px solid var(--border);
  display:flex;align-items:center;justify-content:space-between;
  gap:10px;
}
.card-header h3{font-size:0.88rem;font-weight:700;min-width:0;}
.card-meta{font-size:0.72rem;color:var(--muted);white-space:nowrap;}

/* ── TABLE ── */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;border-collapse:collapse;min-width:500px;}
thead tr{background:#f8fafc;}
th{
  padding:10px 14px;font-size:0.68rem;font-weight:700;
  color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;
  border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;
}
td{
  padding:11px 14px;font-size:0.82rem;
  border-bottom:1px solid #f3f4f6;vertical-align:middle;
}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
.pill{
  display:inline-flex;align-items:center;gap:4px;
  padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;
  white-space:nowrap;
}
.pill-dangerous{background:#fef2f2;color:#991b1b;}
.pill-caution{background:#fffbeb;color:#92400e;}
.pill-safe{background:#f0fdf4;color:#166534;}
.cat-chip{
  display:inline-flex;align-items:center;gap:5px;
  font-size:0.72rem;font-weight:600;color:var(--navy);
  background:#eff6ff;padding:3px 8px;border-radius:6px;white-space:nowrap;
}

/* ── BAR CHART ── */
.bar-row{
  display:flex;align-items:center;gap:10px;
  padding:9px 16px;border-bottom:1px solid #f3f4f6;
}
.bar-row:last-child{border-bottom:none;}
.bar-brgy{font-size:0.78rem;font-weight:600;width:120px;flex-shrink:0;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.bar-wrap{flex:1;height:7px;background:#f1f5f9;border-radius:4px;overflow:hidden;}
.bar-fill{height:100%;border-radius:4px;background:var(--navy);}
.bar-fill.danger{background:#dc2626;}
.bar-count{font-size:0.74rem;font-weight:700;color:var(--muted);width:28px;text-align:right;flex-shrink:0;}

/* ── EMPTY / PLACEHOLDER ── */
.empty{padding:40px 20px;text-align:center;color:var(--muted);}
.empty i{font-size:2rem;display:block;margin-bottom:10px;opacity:0.3;}
.coming-soon{
  padding:60px 24px;text-align:center;
}
.coming-soon i{font-size:3rem;color:var(--navy);opacity:0.15;display:block;margin-bottom:16px;}
.coming-soon h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.coming-soon p{font-size:0.85rem;color:var(--muted);}

/* ── CONTACTS LIST ── */
.contact-row{
  display:flex;align-items:flex-start;gap:14px;
  padding:14px 18px;border-bottom:1px solid #f3f4f6;
}
.contact-row:last-child{border-bottom:none;}
.contact-icon{
  width:38px;height:38px;border-radius:10px;flex-shrink:0;
  display:flex;align-items:center;justify-content:center;font-size:1rem;
}
.contact-name{font-size:0.88rem;font-weight:700;margin-bottom:2px;}
.contact-meta{font-size:0.76rem;color:var(--muted);}
.contact-type{
  display:inline-block;font-size:0.65rem;font-weight:700;
  text-transform:uppercase;letter-spacing:0.8px;
  padding:2px 8px;border-radius:4px;margin-bottom:4px;
}

/* ── OVERLAY ── */
.overlay{
  display:none;position:fixed;inset:0;
  background:rgba(0,0,0,0.45);z-index:150;
}
.overlay.show{display:block;}

/* ── RESPONSIVE ── */
@media(max-width:1100px){
  .stat-grid{grid-template-columns:repeat(3,1fr);}
  .two-col{grid-template-columns:1fr;}
}
@media(max-width:860px){
  :root{--sidebar-w:256px;}
  .sidebar{transform:translateX(-100%);}
  .sidebar.open{transform:translateX(0);}
  .sb-close{display:flex;}
  .main{margin-left:0;}
  .ham-btn{display:flex;}
  .stat-grid{grid-template-columns:repeat(2,1fr);}
  .content{padding:16px;}
  .topbar{padding:0 16px;}
}
@media(max-width:700px){
  [style*="grid-template-columns:340px"]{grid-template-columns:1fr !important;}
  .map-stats{grid-template-columns:1fr 1fr;}
  .resp-stats{grid-template-columns:1fr 1fr;}
  #incidentMap{height:340px;}
}
@media(max-width:480px){
  .stat-grid{grid-template-columns:1fr 1fr;}
  .badge-lgu{display:none;}
  .page-sub{display:none;}
  .bar-brgy{width:90px;}
  .map-stats{grid-template-columns:1fr 1fr;}
  .resp-stats{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>

<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-brand">
      <div class="sb-seal"><i class="fas fa-landmark"></i></div>
      <div>
        <div class="sb-title">SenTri</div>
        <div class="sb-sub">LGU Portal</div>
      </div>
    </div>
    <button class="sb-close" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
  </div>

  <div class="sb-office">
    <p>Jurisdiction</p>
    <strong><?= htmlspecialchars($city ?: 'City / Municipality') ?></strong>
  </div>

  <nav class="sb-nav">
    <?php foreach($nav_items as $key => $item): ?>
      <?php if($key === 'contacts'): ?>
        <div class="sb-section">Operations</div>
      <?php elseif($key === 'profile'): ?>
        <div class="sb-section">Account</div>
      <?php endif; ?>
      <a href="lgu.php?view=<?= $key ?>" class="<?= $view===$key ? 'active' : '' ?>">
        <i class="fas <?= $item['icon'] ?>"></i>
        <span><?= $item['label'] ?></span>
      </a>
    <?php endforeach; ?>
  </nav>

  <div class="sb-footer">
    <div class="sb-user">
      <div class="sb-avatar"><?= strtoupper(substr($fname,0,1)) ?></div>
      <div style="min-width:0;">
        <div class="sb-uname"><?= htmlspecialchars($fname) ?></div>
        <div class="sb-upos"><?= htmlspecialchars($pos) ?></div>
      </div>
    </div>
    <a href="../logout.php" class="sb-logout">
      <i class="fas fa-right-from-bracket"></i> Sign Out
    </a>
  </div>
</aside>

<div class="main">

  <!-- TOPBAR -->
  <div class="topbar">
    <div class="gov-strip"></div>
    <div class="topbar-left">
      <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <div style="min-width:0;">
        <div class="page-title"><?= htmlspecialchars($page_titles[$view] ?? 'LGU Portal') ?></div>
        <div class="page-sub"><?= htmlspecialchars($org) ?><?= $city ? ' &mdash; '.htmlspecialchars($city) : '' ?></div>
      </div>
    </div>
    <span class="badge-lgu"><i class="fas fa-landmark"></i>&nbsp; LGU Official</span>
  </div>

  <div class="content">

    <?php if($view === 'overview'): ?>
    <!-- ── OVERVIEW ── -->
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-file-lines"></i></div>
        <div class="stat-num"><?= $total ?></div>
        <div class="stat-lbl">Total Active Reports</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div>
        <div class="stat-num"><?= $danger ?></div>
        <div class="stat-lbl">Dangerous</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div>
        <div class="stat-num"><?= $caution ?></div>
        <div class="stat-lbl">Caution</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div>
        <div class="stat-num"><?= $safe ?></div>
        <div class="stat-lbl">Safe / Resolved</div>
      </div>
      <div class="stat-card">
        <div class="stat-icon" style="background:#f5f3ff;color:#7c3aed;"><i class="fas fa-address-book"></i></div>
        <div class="stat-num"><?= $contacts_count ?></div>
        <div class="stat-lbl">Active Contacts</div>
      </div>
    </div>

    <div class="two-col">
      <div class="card">
        <div class="card-header">
          <h3><i class="fas fa-triangle-exclamation" style="color:#dc2626;margin-right:6px;"></i>Active Dangerous Incidents</h3>
          <span class="card-meta"><?= count($danger_reports) ?> records</span>
        </div>
        <?php if(empty($danger_reports)): ?>
          <div class="empty"><i class="fas fa-shield-halved"></i>No dangerous incidents active.</div>
        <?php else: ?>
        <div class="table-wrap">
          <table>
            <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Barangay</th><th>Date</th></tr></thead>
            <tbody>
            <?php foreach($danger_reports as $r): ?>
              <tr>
                <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
                <td style="font-weight:600;max-width:180px;"><?= htmlspecialchars(mb_strimwidth($r['title'],0,50,'…')) ?></td>
                <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
                <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city']) ?></td>
                <td style="font-size:0.74rem;color:var(--muted);"><?= date('M j', strtotime($r['created_at'])) ?></td>
              </tr>
            <?php endforeach; ?>
            </tbody>
          </table>
        </div>
        <?php endif; ?>
      </div>

      <div class="card">
        <div class="card-header">
          <h3><i class="fas fa-house-flag" style="color:#166534;margin-right:6px;"></i>Reports by Barangay</h3>
        </div>
        <?php if(empty($brgy_stats)): ?>
          <div class="empty"><i class="fas fa-chart-bar"></i>No data yet.</div>
        <?php else:
          $max = max(array_column($brgy_stats,'c')) ?: 1;
          foreach($brgy_stats as $b): ?>
          <div class="bar-row">
            <div class="bar-brgy" title="<?= htmlspecialchars($b['b']) ?>"><?= htmlspecialchars($b['b']) ?></div>
            <div class="bar-wrap">
              <div class="bar-fill <?= $b['d']>0 ? 'danger' : '' ?>" style="width:<?= round($b['c']/$max*100) ?>%"></div>
            </div>
            <div class="bar-count"><?= $b['c'] ?></div>
          </div>
        <?php endforeach; endif; ?>
      </div>
    </div>

    <?php elseif($view === 'all_reports'): ?>
    <!-- ── ALL REPORTS ── -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-file-lines" style="color:var(--navy);margin-right:6px;"></i>All Incident Reports</h3>
        <span class="card-meta"><?= count($all_reports) ?> records</span>
      </div>
      <?php if(empty($all_reports)): ?>
        <div class="empty"><i class="fas fa-folder-open"></i>No reports found.</div>
      <?php else: ?>
      <div class="table-wrap">
        <table>
          <thead><tr><th>#</th><th>Title</th><th>Category</th><th>Status</th><th>Barangay</th><th>Reported By</th><th>Date</th></tr></thead>
          <tbody>
          <?php foreach($all_reports as $r): ?>
            <tr>
              <td style="color:var(--muted);font-size:0.74rem;">#<?= $r['id'] ?></td>
              <td style="font-weight:600;max-width:200px;"><?= htmlspecialchars(mb_strimwidth($r['title'],0,55,'…')) ?></td>
              <td><span class="cat-chip"><i class="fas <?= $cat_icons[$r['category']] ?? 'fa-circle-exclamation' ?>"></i> <?= ucfirst($r['category']) ?></span></td>
              <td><span class="pill pill-<?= $r['status'] ?>"><?= ucfirst($r['status']) ?></span></td>
              <td style="font-size:0.78rem;"><?= htmlspecialchars($r['barangay'] ?? $r['city']) ?></td>
              <td style="font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></td>
              <td style="font-size:0.74rem;color:var(--muted);white-space:nowrap;"><?= date('M j, Y', strtotime($r['created_at'])) ?></td>
            </tr>
          <?php endforeach; ?>
          </tbody>
        </table>
      </div>
      <?php endif; ?>
    </div>

    <?php elseif($view === 'contacts'): ?>
    <!-- ── CONTACTS ── -->
    <?php
    $type_colors = ['hospital'=>['#ecfdf5','#059669'],'fire'=>['#fef2f2','#dc2626'],'police'=>['#eff6ff','#2563eb'],'barangay'=>['#f0fdf4','#166534'],'municipal'=>['#f0f7ff','#0a3d62'],'other'=>['#f5f3ff','#7c3aed']];
    $type_icons  = ['hospital'=>'fa-hospital','fire'=>'fa-fire-extinguisher','police'=>'fa-shield','barangay'=>'fa-house-flag','municipal'=>'fa-landmark','other'=>'fa-phone'];
    ?>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-address-book" style="color:var(--navy);margin-right:6px;"></i>Emergency Contacts</h3>
        <span class="card-meta"><?= count($contacts) ?> contacts</span>
      </div>
      <?php if(empty($contacts)): ?>
        <div class="empty"><i class="fas fa-address-book"></i>No emergency contacts found.</div>
      <?php else: foreach($contacts as $c):
        $tc = $type_colors[$c['type']] ?? $type_colors['other'];
        $ti = $type_icons[$c['type']] ?? 'fa-phone';
      ?>
        <div class="contact-row">
          <div class="contact-icon" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><i class="fas <?= $ti ?>"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="contact-meta">
              <?php if($c['contact_number']): ?><span><i class="fas fa-phone" style="margin-right:4px;"></i><?= htmlspecialchars($c['contact_number']) ?></span>&nbsp;&nbsp;<?php endif; ?>
              <?php if($c['contact_email']): ?><span><i class="fas fa-envelope" style="margin-right:4px;"></i><?= htmlspecialchars($c['contact_email']) ?></span>&nbsp;&nbsp;<?php endif; ?>
              <?php if($c['city']): ?><span><i class="fas fa-location-dot" style="margin-right:4px;"></i><?= htmlspecialchars($c['barangay'] ? $c['barangay'].', '.$c['city'] : $c['city']) ?></span><?php endif; ?>
            </div>
          </div>
          <span class="contact-type" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><?= strtoupper($c['type']) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php elseif($view === 'barangays'): ?>
    <!-- ── BARANGAY SUMMARIES ── -->
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-house-flag" style="color:#166534;margin-right:6px;"></i>Barangay Incident Summary</h3>
      </div>
      <?php if(empty($brgy_stats)): ?>
        <div class="empty"><i class="fas fa-chart-bar"></i>No data yet.</div>
      <?php else:
        $max = max(array_column($brgy_stats,'c')) ?: 1;
        foreach($brgy_stats as $b): ?>
        <div class="bar-row" style="padding:12px 18px;">
          <div class="bar-brgy" style="width:160px;font-size:0.84rem;" title="<?= htmlspecialchars($b['b']) ?>"><?= htmlspecialchars($b['b']) ?></div>
          <div class="bar-wrap"><div class="bar-fill <?= $b['d']>0 ? 'danger' : '' ?>" style="width:<?= round($b['c']/$max*100) ?>%"></div></div>
          <div class="bar-count" style="width:40px;font-size:0.8rem;"><?= $b['c'] ?> reports</div>
          <?php if($b['d']>0): ?>
            <span class="pill pill-dangerous" style="margin-left:8px;"><?= $b['d'] ?> dangerous</span>
          <?php endif; ?>
        </div>
      <?php endforeach; endif; ?>
    </div>

    <?php elseif($view === 'map'): ?>
    <!-- ── INCIDENT MAP ── -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <style>
    .map-wrap{position:relative;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);border:1px solid var(--border);}
    #incidentMap{height:520px;width:100%;background:#e8f0f8;}
    .map-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:14px 18px;background:#fff;border-bottom:1px solid var(--border);border-radius:14px 14px 0 0;}
    .map-controls h3{font-size:0.88rem;font-weight:700;margin-right:4px;}
    .filter-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.76rem;font-weight:700;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
    .filter-btn.active-all{background:var(--navy);color:#fff;border-color:var(--navy);}
    .filter-btn.active-dangerous{background:#dc2626;color:#fff;border-color:#dc2626;}
    .filter-btn.active-caution{background:#d97706;color:#fff;border-color:#d97706;}
    .filter-btn.active-safe{background:#16a34a;color:#fff;border-color:#16a34a;}
    .map-legend{position:absolute;bottom:18px;right:14px;background:rgba(255,255,255,0.95);border-radius:10px;padding:11px 14px;font-size:0.76rem;box-shadow:0 2px 12px rgba(0,0,0,0.15);z-index:400;border:1px solid var(--border);}
    .legend-row{display:flex;align-items:center;gap:7px;margin-bottom:5px;font-weight:600;}
    .legend-row:last-child{margin-bottom:0;}
    .legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
    .map-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-top:14px;}
    .map-stat{background:#fff;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);}
    .map-stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
    .map-stat-num{font-size:1.3rem;font-weight:800;line-height:1;}
    .map-stat-lbl{font-size:0.68rem;color:var(--muted);font-weight:600;margin-top:2px;}
    .no-coords-notice{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:11px 15px;font-size:0.82rem;color:#92400e;margin-bottom:14px;display:flex;align-items:center;gap:9px;}
    </style>
    <?php
    $mapped   = count($map_reports);
    $unmapped = $map_total - $mapped;
    $m_danger = count(array_filter($map_reports, function($r){ return $r['status']==='dangerous'; }));
    $m_caution= count(array_filter($map_reports, function($r){ return $r['status']==='caution'; }));
    $m_safe   = count(array_filter($map_reports, function($r){ return $r['status']==='safe'; }));
    ?>
    <?php if($unmapped > 0): ?>
    <div class="no-coords-notice"><i class="fas fa-circle-info"></i><?= $unmapped ?> report<?= $unmapped>1?'s':'' ?> without GPS coordinates are not shown on the map.</div>
    <?php endif; ?>
    <div class="map-stats">
      <div class="map-stat"><div class="map-stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-map-pin"></i></div><div><div class="map-stat-num"><?= $mapped ?></div><div class="map-stat-lbl">Mapped Reports</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_danger ?></div><div class="map-stat-lbl">Dangerous</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_caution ?></div><div class="map-stat-lbl">Caution</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="map-stat-num"><?= $m_safe ?></div><div class="map-stat-lbl">Safe / Resolved</div></div></div>
    </div>
    <div style="margin-top:14px;" class="map-wrap">
      <div class="map-controls">
        <h3><i class="fas fa-map-location-dot" style="color:var(--navy);margin-right:6px;"></i>Incident Map</h3>
        <button class="filter-btn active-all" onclick="filterMap('all',this)">All</button>
        <button class="filter-btn" onclick="filterMap('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
        <button class="filter-btn" onclick="filterMap('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
        <button class="filter-btn" onclick="filterMap('safe',this)"><i class="fas fa-circle" style="color:#16a34a;font-size:0.6rem;"></i> Safe</button>
        <div style="margin-left:auto;font-size:0.75rem;color:var(--muted);">OpenStreetMap</div>
      </div>
      <div style="position:relative;">
        <div id="incidentMap"></div>
        <div class="map-legend">
          <div class="legend-row"><div class="legend-dot" style="background:#dc2626;"></div>Dangerous</div>
          <div class="legend-row"><div class="legend-dot" style="background:#d97706;"></div>Caution</div>
          <div class="legend-row"><div class="legend-dot" style="background:#16a34a;"></div>Safe</div>
        </div>
      </div>
    </div>
    <script>
    var reports = <?= json_encode(array_map(function($r){ return ['id'=>(int)$r['id'],'title'=>$r['title'],'category'=>$r['category'],'status'=>$r['status'],'barangay'=>$r['barangay']??$r['city']??'','lat'=>(float)$r['latitude'],'lng'=>(float)$r['longitude'],'date'=>date('M j, Y',strtotime($r['created_at'])),'desc'=>$r['description']??'']; }, $map_reports)) ?>;
    var markerColors = {dangerous:'#dc2626',caution:'#d97706',safe:'#16a34a'};
    var catLabels = {crime:'Crime',accident:'Accident',flooding:'Flooding',fire:'Fire',health:'Health',infrastructure:'Infrastructure',other:'Other'};

    function makeIcon(color){
      return L.divIcon({
        className:'',
        html:'<div style="width:14px;height:14px;border-radius:50%;background:'+color+';border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.35);"></div>',
        iconSize:[14,14],iconAnchor:[7,7]
      });
    }

    var map = L.map('incidentMap');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(map);

    var allMarkers = [];
    reports.forEach(function(r){
      var color = markerColors[r.status] || '#888';
      var m = L.marker([r.lat,r.lng],{icon:makeIcon(color)});
      m.reportData = r;
      m.bindPopup(
        '<div style="min-width:200px;font-family:Inter,sans-serif;">' +
        '<div style="font-weight:800;font-size:0.9rem;margin-bottom:6px;">'+r.title+'</div>' +
        '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">' +
        '<span style="background:'+(r.status==='dangerous'?'#fef2f2':r.status==='caution'?'#fffbeb':'#f0fdf4')+';color:'+(r.status==='dangerous'?'#991b1b':r.status==='caution'?'#92400e':'#166534')+';padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:700;">'+(r.status.charAt(0).toUpperCase()+r.status.slice(1))+'</span>' +
        '<span style="background:#eff6ff;color:#1e40af;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;">'+(catLabels[r.category]||r.category)+'</span></div>' +
        (r.barangay?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Location:</b> '+r.barangay+'</div>':'')+
        (r.desc?'<div style="font-size:0.78rem;color:#374151;margin-top:6px;">'+r.desc.substring(0,120)+(r.desc.length>120?'…':'')+'</div>':'')+
        '<div style="font-size:0.72rem;color:#9ca3af;margin-top:7px;">'+r.date+' &middot; Report #'+r.id+'</div></div>',
        {maxWidth:260}
      );
      m.addTo(map);
      allMarkers.push(m);
    });

    if(allMarkers.length > 0){
      var group = L.featureGroup(allMarkers);
      map.fitBounds(group.getBounds().pad(0.15));
    } else {
      map.setView([14.5995,120.9842],12);
    }

    var currentFilter = 'all';
    function filterMap(status, btn){
      currentFilter = status;
      document.querySelectorAll('.filter-btn').forEach(function(b){ b.className='filter-btn'; });
      btn.classList.add('active-'+status);
      allMarkers.forEach(function(m){
        var show = (status==='all' || m.reportData.status===status);
        if(show){ if(!map.hasLayer(m)) m.addTo(map); }
        else { if(map.hasLayer(m)) map.removeLayer(m); }
      });
    }
    </script>

    <?php elseif($view === 'profile'): ?>
    <!-- ── MY PROFILE ── -->
    <style>
    .profile-grid{display:grid;grid-template-columns:1fr 1fr;gap:16px;}
    .form-group{margin-bottom:0;}
    .form-group label{display:block;font-size:0.78rem;font-weight:700;color:#374151;margin-bottom:5px;text-transform:uppercase;letter-spacing:0.5px;}
    .form-group input{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.9rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;color:var(--text);transition:all 0.18s;}
    .form-group input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,0.08);}
    .form-group input[readonly]{background:#f3f4f6;color:var(--muted);cursor:not-allowed;}
    .section-divider{margin:22px 0 18px;padding-bottom:10px;border-bottom:1px solid var(--border);display:flex;align-items:center;gap:10px;}
    .section-divider h4{font-size:0.82rem;font-weight:700;color:var(--navy);text-transform:uppercase;letter-spacing:1px;}
    .btn-save{background:linear-gradient(135deg,#1a5276,var(--navy-dark));color:#fff;border:none;padding:11px 28px;border-radius:10px;font-size:0.9rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.2s;display:inline-flex;align-items:center;gap:8px;}
    .btn-save:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(10,61,98,0.35);}
    .profile-toast{padding:11px 16px;border-radius:9px;font-size:0.84rem;font-weight:600;margin-bottom:18px;display:flex;align-items:center;gap:9px;}
    .profile-toast.success{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
    .profile-toast.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
    .avatar-circle{width:68px;height:68px;border-radius:50%;background:linear-gradient(135deg,var(--navy),var(--navy-light));display:flex;align-items:center;justify-content:center;font-size:1.6rem;font-weight:800;color:var(--gold);flex-shrink:0;border:3px solid rgba(243,156,18,0.4);}
    </style>
    <?php
    $p_email = $prof['email'] ?? '';
    $p_phone_v = '';
    $phone_stmt = $conn->prepare("SELECT phone_number FROM users WHERE id=? LIMIT 1");
    $phone_stmt->bind_param("i",$uid); $phone_stmt->execute();
    $phone_stmt->bind_result($p_phone_v); $phone_stmt->fetch(); $phone_stmt->close();
    list($msg_type,$msg_text) = $profile_msg ? explode(':',$profile_msg,2) : ['',''];
    ?>
    <div style="display:grid;grid-template-columns:340px 1fr;gap:18px;align-items:start;">
      <!-- Profile Card -->
      <div class="card" style="padding:24px;text-align:center;">
        <div class="avatar-circle" style="margin:0 auto 14px;"><?= strtoupper(substr($fname,0,1)) ?></div>
        <div style="font-size:1.05rem;font-weight:800;"><?= htmlspecialchars($fname) ?></div>
        <div style="font-size:0.8rem;color:var(--muted);margin-top:3px;"><?= htmlspecialchars($pos ?: 'LGU Official') ?></div>
        <div style="margin-top:10px;padding:8px 14px;background:#f0f7ff;border-radius:8px;font-size:0.78rem;color:var(--navy);font-weight:600;"><i class="fas fa-landmark" style="margin-right:5px;"></i><?= htmlspecialchars($org ?: 'LGU Office') ?></div>
        <?php if($city): ?><div style="margin-top:8px;font-size:0.78rem;color:var(--muted);"><i class="fas fa-location-dot" style="margin-right:4px;"></i><?= htmlspecialchars($city) ?></div><?php endif; ?>
        <div style="margin-top:12px;font-size:0.78rem;color:var(--muted);"><?= htmlspecialchars($p_email) ?></div>
        <div style="margin-top:14px;padding-top:14px;border-top:1px solid var(--border);">
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:8px;text-align:center;">
            <div style="background:#f8fafc;border-radius:8px;padding:10px;"><div style="font-size:1.2rem;font-weight:800;color:var(--navy);"><?= $total ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Reports</div></div>
            <div style="background:#fef2f2;border-radius:8px;padding:10px;"><div style="font-size:1.2rem;font-weight:800;color:#dc2626;"><?= $danger ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Dangerous</div></div>
          </div>
        </div>
      </div>
      <!-- Edit Form -->
      <div class="card" style="padding:24px;">
        <?php if($msg_text): ?>
        <div class="profile-toast <?= $msg_type ?>"><i class="fas fa-<?= $msg_type==='success'?'circle-check':'circle-xmark' ?>"></i><?= htmlspecialchars($msg_text) ?></div>
        <?php endif; ?>
        <form method="POST" action="lgu.php?view=profile">
          <input type="hidden" name="save_profile" value="1">
          <div class="section-divider"><h4><i class="fas fa-user" style="margin-right:6px;"></i>Personal Information</h4></div>
          <div class="profile-grid" style="margin-bottom:16px;">
            <div class="form-group"><label>First Name</label><input type="text" name="first_name" value="<?= htmlspecialchars($fname) ?>" required></div>
            <div class="form-group"><label>Last Name</label><input type="text" name="last_name" value="<?= htmlspecialchars($prof['last_name'] ?? '') ?>" required></div>
          </div>
          <div class="profile-grid" style="margin-bottom:16px;">
            <div class="form-group"><label>Email Address</label><input type="email" value="<?= htmlspecialchars($p_email) ?>" readonly></div>
            <div class="form-group"><label>Phone Number</label><input type="tel" name="phone" value="<?= htmlspecialchars($p_phone_v) ?>" placeholder="+63 9XX XXX XXXX"></div>
          </div>
          <div class="section-divider"><h4><i class="fas fa-landmark" style="margin-right:6px;"></i>Official Information</h4></div>
          <div class="profile-grid" style="margin-bottom:16px;">
            <div class="form-group"><label>Office / Agency Name</label><input type="text" name="org_name" value="<?= htmlspecialchars($org) ?>" placeholder="e.g. Imus City DRRMO"></div>
            <div class="form-group"><label>Position / Title</label><input type="text" name="position" value="<?= htmlspecialchars($pos) ?>" placeholder="e.g. DRRMO Head"></div>
          </div>
          <div class="form-group" style="margin-bottom:16px;"><label>City / Municipality</label><input type="text" name="municipality" value="<?= htmlspecialchars($city) ?>" placeholder="e.g. Imus" style="max-width:320px;"></div>
          <div class="section-divider"><h4><i class="fas fa-lock" style="margin-right:6px;"></i>Change Password <span style="font-weight:400;color:var(--muted);text-transform:none;letter-spacing:0;">(leave blank to keep current)</span></h4></div>
          <div class="profile-grid" style="margin-bottom:22px;">
            <div class="form-group"><label>New Password</label><input type="password" name="new_password" placeholder="Min. 8 characters" autocomplete="new-password"></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" name="confirm_password" placeholder="Re-enter new password" autocomplete="new-password"></div>
          </div>
          <button type="submit" class="btn-save"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </form>
      </div>
    </div>

    <?php elseif($view === 'responders'): ?>
    <!-- ── RESPONDER UNITS ── -->
    <style>
    .rtype-badge{display:inline-block;padding:2px 10px;border-radius:4px;font-size:0.68rem;font-weight:800;letter-spacing:1px;text-transform:uppercase;}
    .rtype-bfp{background:#fef2f2;color:#b91c1c;}
    .rtype-pnp{background:#eff6ff;color:#1d4ed8;}
    .rtype-ems{background:#f0fdf4;color:#15803d;}
    .rtype-drrmo,.rtype-mdrrmo{background:#fffbeb;color:#b45309;}
    .rtype-hospital{background:#ecfdf5;color:#0e7490;}
    .rtype-other{background:#f5f3ff;color:#6d28d9;}
    .resp-row{display:flex;align-items:center;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;transition:background 0.15s;}
    .resp-row:last-child{border-bottom:none;}
    .resp-row:hover{background:#fafafa;}
    .resp-avatar{width:40px;height:40px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:0.9rem;font-weight:800;}
    .resp-name{font-size:0.88rem;font-weight:700;}
    .resp-meta{font-size:0.76rem;color:var(--muted);margin-top:2px;}
    .resp-actions{display:flex;gap:6px;margin-left:auto;flex-shrink:0;}
    .btn-approve{background:#16a34a;color:#fff;border:none;padding:6px 13px;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:background 0.18s;}
    .btn-approve:hover{background:#166534;}
    .btn-reject{background:#fef2f2;color:#dc2626;border:1.5px solid #fecaca;padding:5px 12px;border-radius:7px;font-size:0.75rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;transition:all 0.18s;}
    .btn-reject:hover{background:#fee2e2;}
    .active-badge{background:#f0fdf4;color:#16a34a;border:1px solid #bbf7d0;padding:5px 11px;border-radius:7px;font-size:0.73rem;font-weight:700;}
    .pending-badge{background:#fffbeb;color:#d97706;border:1px solid #fde68a;padding:3px 10px;border-radius:20px;font-size:0.7rem;font-weight:700;}
    .resp-filter-bar{display:flex;gap:6px;flex-wrap:wrap;padding:12px 18px;border-bottom:1px solid var(--border);}
    .rf-btn{padding:5px 12px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.75rem;font-weight:600;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
    .rf-btn.active{background:var(--navy);color:#fff;border-color:var(--navy);}
    .resp-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
    .rs-card{background:#fff;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:10px;border:1px solid var(--border);box-shadow:0 2px 6px rgba(0,0,0,0.04);}
    .rs-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
    </style>
    <?php
    $r_types = ['bfp','pnp','ems','drrmo','hospital','other'];
    $r_labels = ['bfp'=>'BFP','pnp'=>'PNP','ems'=>'EMS','drrmo'=>'DRRMO','mdrrmo'=>'MDRRMO','hospital'=>'Hospital','other'=>'Other'];
    $r_colors = ['bfp'=>['#fef2f2','#b91c1c'],'pnp'=>['#eff6ff','#1d4ed8'],'ems'=>['#f0fdf4','#15803d'],'drrmo'=>['#fffbeb','#b45309'],'mdrrmo'=>['#fffbeb','#b45309'],'hospital'=>['#ecfdf5','#0e7490'],'other'=>['#f5f3ff','#6d28d9']];
    $total_resp   = count($responders);
    $pending_resp = count(array_filter($responders, function($r){ return !(int)$r['is_approved']; }));
    $active_resp  = $total_resp - $pending_resp;
    $on_duty      = count(array_filter($responders, function($r){ return $r['active_count'] > 0; }));
    ?>
    <div class="resp-stats">
      <div class="rs-card"><div class="rs-icon" style="background:#f0f7ff;color:var(--navy);"><i class="fas fa-truck-medical"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $total_resp ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Total Units</div></div></div>
      <div class="rs-card"><div class="rs-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $active_resp ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Approved</div></div></div>
      <div class="rs-card"><div class="rs-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-siren-on"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $on_duty ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">On Active Duty</div></div></div>
      <div class="rs-card"><div class="rs-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-clock"></i></div><div><div style="font-size:1.3rem;font-weight:800;"><?= $pending_resp ?></div><div style="font-size:0.68rem;color:var(--muted);font-weight:600;">Pending Approval</div></div></div>
    </div>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-truck-medical" style="color:var(--navy);margin-right:6px;"></i>Registered Responder Units</h3>
        <span class="card-meta"><?= $total_resp ?> units</span>
      </div>
      <div class="resp-filter-bar">
        <button class="rf-btn active" onclick="filterResp('all',this)">All</button>
        <?php foreach($r_labels as $k=>$l): ?>
        <button class="rf-btn" onclick="filterResp('<?= $k ?>',this)"><?= $l ?></button>
        <?php endforeach; ?>
        <?php if($pending_resp > 0): ?>
        <button class="rf-btn" onclick="filterResp('pending',this)" style="border-color:#fde68a;color:#d97706;">Pending (<?= $pending_resp ?>)</button>
        <?php endif; ?>
      </div>
      <?php if(empty($responders)): ?>
        <div class="empty"><i class="fas fa-truck-medical" style="font-size:2rem;display:block;margin-bottom:10px;opacity:0.3;"></i><p>No responder units registered yet.</p></div>
      <?php else: ?>
      <div id="responderList">
      <?php foreach($responders as $r):
        $rtype = $r['responder_type'] ?? 'other';
        $rc = $r_colors[$rtype] ?? ['#f5f3ff','#6d28d9'];
        $approved = (int)$r['is_approved'];
        $initials = strtoupper(substr($r['first_name'],0,1).substr($r['last_name'],0,1));
      ?>
        <div class="resp-row" data-type="<?= $rtype ?>" data-approved="<?= $approved ?>">
          <div class="resp-avatar" style="background:<?= $rc[0] ?>;color:<?= $rc[1] ?>;"><?= $initials ?></div>
          <div style="flex:1;min-width:0;">
            <div style="display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
              <div class="resp-name"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></div>
              <span class="rtype-badge rtype-<?= $rtype ?>"><?= $r_labels[$rtype] ?? strtoupper($rtype) ?></span>
              <?php if(!$approved): ?><span class="pending-badge"><i class="fas fa-clock" style="margin-right:3px;"></i>Pending</span><?php endif; ?>
              <?php if($r['active_count']>0): ?><span style="background:#fef2f2;color:#dc2626;border:1px solid #fecaca;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;"><i class="fas fa-siren-on" style="margin-right:3px;"></i><?= $r['active_count'] ?> active</span><?php endif; ?>
            </div>
            <div class="resp-meta">
              <?php if($r['org_name']): ?><span><i class="fas fa-building" style="margin-right:3px;"></i><?= htmlspecialchars($r['org_name']) ?></span>&ensp;<?php endif; ?>
              <?php if($r['position']): ?><span><?= htmlspecialchars($r['position']) ?></span>&ensp;<?php endif; ?>
              <?php if($r['municipality']): ?><span><i class="fas fa-location-dot" style="margin-right:3px;color:var(--muted);"></i><?= htmlspecialchars($r['municipality']) ?></span>&ensp;<?php endif; ?>
              <?php if($r['phone_number']): ?><span><i class="fas fa-phone" style="margin-right:3px;color:var(--muted);"></i><?= htmlspecialchars($r['phone_number']) ?></span><?php endif; ?>
            </div>
            <div style="font-size:0.74rem;color:var(--muted);margin-top:2px;"><?= htmlspecialchars($r['email']) ?></div>
          </div>
          <div class="resp-actions">
            <?php if(!$approved): ?>
              <button class="btn-approve" onclick="responderAction(<?= $r['id'] ?>,'approve',this)"><i class="fas fa-check"></i> Approve</button>
              <button class="btn-reject"  onclick="responderAction(<?= $r['id'] ?>,'reject',this)"><i class="fas fa-xmark"></i></button>
            <?php else: ?>
              <span class="active-badge"><i class="fas fa-check" style="margin-right:4px;"></i>Approved</span>
            <?php endif; ?>
          </div>
        </div>
      <?php endforeach; ?>
      </div>
      <?php endif; ?>
    </div>
    <script>
    function filterResp(type, btn) {
      document.querySelectorAll('.rf-btn').forEach(function(b){ b.classList.remove('active'); });
      btn.classList.add('active');
      document.querySelectorAll('.resp-row').forEach(function(row) {
        if(type==='all') { row.style.display=''; return; }
        if(type==='pending') { row.style.display=(row.dataset.approved==='0'?'':'none'); return; }
        row.style.display=(row.dataset.type===type?'':'none');
      });
    }
    async function responderAction(id, action, btn) {
      var label = action==='approve' ? 'Approve this responder?' : 'Reject and remove this account?';
      if(!confirm(label)) return;
      btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
      var fd=new FormData();
      fd.append('action', action+'_responder');
      fd.append('user_id', id);
      try {
        var res=await fetch('lgu.php?view=responders',{method:'POST',body:fd});
        var data=await res.json();
        if(data.status==='success') location.reload();
        else { alert(data.message||'Error.'); btn.disabled=false; btn.innerHTML=action==='approve'?'<i class="fas fa-check"></i> Approve':'<i class="fas fa-xmark"></i>'; }
      } catch(e) { btn.disabled=false; }
    }
    </script>

    <?php else: ?>
    <!-- ── COMING SOON ── -->
    <div class="card">
      <div class="coming-soon">
        <i class="fas <?= $nav_items[$view]['icon'] ?? 'fa-gear' ?>"></i>
        <h3><?= htmlspecialchars($page_titles[$view] ?? ucfirst($view)) ?></h3>
        <p>This section is under development.</p>
      </div>
    </div>
    <?php endif; ?>

  </div><!-- /content -->
</div><!-- /main -->

<script>
function openSidebar(){
  document.getElementById('sidebar').classList.add('open');
  document.getElementById('overlay').classList.add('show');
  document.body.style.overflow='hidden';
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('open');
  document.getElementById('overlay').classList.remove('show');
  document.body.style.overflow='';
}
</script>
</body>
</html>

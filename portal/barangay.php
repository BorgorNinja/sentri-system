<?php
session_start();
require_once __DIR__ . '/../config/auth.php';
require_role(['barangay']);
require_once __DIR__ . '/../config/db.php';

$uid   = (int)$_SESSION['user_id'];
$fname = $_SESSION['first_name'];
$view  = $_GET['view'] ?? 'overview';

$stmt = $conn->prepare("SELECT email,org_name,`position`,barangay_name,municipality FROM users WHERE id=? LIMIT 1");
$stmt->bind_param("i",$uid); $stmt->execute();
$prof = $stmt->get_result()->fetch_assoc(); $stmt->close();
$brgy = $prof['barangay_name'] ?? '';
$city = $prof['municipality']  ?? '';
$org  = $prof['org_name']      ?? 'Barangay Office';
$pos  = $prof['position']      ?? 'Barangay Official';
$juri = $brgy ?: ($city ?: 'All Areas');

function cq($conn,$sql,$types='',$params=[]){
    $s=$conn->prepare($sql);
    if($types && count($params)){
        $refs=[];
        foreach($params as &$v) $refs[]=&$v;
        array_unshift($refs,$types);
        call_user_func_array([$s,'bind_param'],$refs);
    }
    $s->execute();$s->bind_result($n);$s->fetch();$s->close();return(int)$n;
}

// Stats — show all reports (community-wide, no barangay filter)
$total   = cq($conn,"SELECT COUNT(*) FROM reports WHERE is_archived=0");
$danger  = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0");
$caution = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='caution' AND is_archived=0");
$safe    = cq($conn,"SELECT COUNT(*) FROM reports WHERE status='safe' AND is_archived=0");

$cat_icons = ['crime'=>'fa-user-slash','accident'=>'fa-car-burst','flooding'=>'fa-water','fire'=>'fa-fire','health'=>'fa-heart-pulse','infrastructure'=>'fa-road-barrier','other'=>'fa-circle-exclamation'];
$type_icons = ['lgu'=>'fa-landmark','hospital'=>'fa-hospital','traffic'=>'fa-traffic-light','barangay'=>'fa-house-flag','police'=>'fa-shield','fire'=>'fa-fire-extinguisher','other'=>'fa-phone'];
$type_colors = ['lgu'=>['#f0f7ff','#0a3d62'],'hospital'=>['#ecfdf5','#059669'],'traffic'=>['#fffbeb','#d97706'],'barangay'=>['#f0fdf4','#166534'],'police'=>['#eff6ff','#2563eb'],'fire'=>['#fef2f2','#dc2626'],'other'=>['#f5f3ff','#7c3aed']];

// Per-view data
$reports = $contacts = $residents = [];

if (in_array($view, ['overview','reports'])) {
    $limit = ($view === 'overview') ? 20 : 100;
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.created_at,r.description,u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 ORDER BY FIELD(r.status,'dangerous','caution','safe'),r.created_at DESC LIMIT $limit");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $reports[]=$row;
    $s->close();
}

if ($view === 'contacts') {
    $q = $brgy
        ? "SELECT * FROM emergency_contacts WHERE is_active=1 AND (barangay=? OR barangay IS NULL) ORDER BY type,name"
        : "SELECT * FROM emergency_contacts WHERE is_active=1 ORDER BY type,name";
    if ($brgy) {
        $s = $conn->prepare($q); $s->bind_param("s",$brgy); $s->execute();
    } else {
        $s = $conn->prepare($q); $s->execute();
    }
    $res=$s->get_result();
    while($r=$res->fetch_assoc()) $contacts[]=$r;
    $s->close();
}

if ($view === 'residents') {
    $q = $brgy
        ? "SELECT id,first_name,last_name,email,phone_number,created_at FROM users WHERE role IN('community','user') AND barangay_name=? ORDER BY last_name"
        : "SELECT id,first_name,last_name,email,phone_number,created_at FROM users WHERE role IN('community','user') ORDER BY last_name LIMIT 100";
    if ($brgy) {
        $s=$conn->prepare($q); $s->bind_param("s",$brgy); $s->execute();
    } else {
        $s=$conn->prepare($q); $s->execute();
    }
    $res=$s->get_result();
    while($r=$res->fetch_assoc()) $residents[]=$r;
    $s->close();
}

$map_reports = [];
if ($view === 'map') {
    $s = $conn->prepare("SELECT r.id,r.title,r.category,r.status,r.barangay,r.city,r.latitude,r.longitude,r.created_at,r.description,u.first_name,u.last_name FROM reports r JOIN users u ON u.id=r.user_id WHERE r.is_archived=0 AND r.latitude IS NOT NULL AND r.longitude IS NOT NULL ORDER BY r.created_at DESC LIMIT 500");
    $s->execute(); $res=$s->get_result();
    while($row=$res->fetch_assoc()) $map_reports[]=$row;
    $s->close();
}
$map_total_all = cq($conn,"SELECT COUNT(*) FROM reports WHERE is_archived=0");

$nav_items = [
    'overview'  => ['icon'=>'fa-gauge',           'label'=>'Dashboard'],
    'reports'   => ['icon'=>'fa-file-lines',       'label'=>'Incident Reports'],
    'map'       => ['icon'=>'fa-map-location-dot', 'label'=>'Incident Map'],
    'contacts'  => ['icon'=>'fa-address-book',     'label'=>'Emergency Contacts'],
    'residents' => ['icon'=>'fa-users',            'label'=>'Residents'],
    'profile'   => ['icon'=>'fa-id-card',          'label'=>'My Profile'],
];
$page_titles = [
    'overview'  => 'Barangay Dashboard',
    'reports'   => 'Incident Reports',
    'map'       => 'Incident Map',
    'contacts'  => 'Emergency Contacts',
    'residents' => 'Residents',
    'profile'   => 'My Profile',
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= $page_titles[$view] ?? 'Barangay Portal' ?> — SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--green:#166534;--green-dark:#052e16;--green-mid:#14532d;--green-light:#16a34a;--gold:#f39c12;--navy:#0a3d62;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--bg:#f1f5f9;--card:#fff;--sidebar-w:256px;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html,body{height:100%;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;}
/* SIDEBAR */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:linear-gradient(180deg,var(--green-dark) 0%,var(--green-mid) 50%,var(--green) 100%);color:#fff;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:1100;box-shadow:4px 0 20px rgba(0,0,0,0.25);transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);}
.sb-header{padding:18px 16px;border-bottom:1px solid rgba(255,255,255,0.1);display:flex;align-items:center;justify-content:space-between;flex-shrink:0;}
.sb-brand{display:flex;align-items:center;gap:10px;}
.sb-seal{width:40px;height:40px;border-radius:50%;background:rgba(243,156,18,0.2);border:2px solid rgba(243,156,18,0.45);display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);flex-shrink:0;}
.sb-title{font-size:0.96rem;font-weight:800;line-height:1.2;}
.sb-sub{font-size:0.58rem;color:rgba(255,255,255,0.45);letter-spacing:1.5px;text-transform:uppercase;}
.sb-close{background:none;border:none;color:rgba(255,255,255,0.6);font-size:1.1rem;cursor:pointer;padding:4px 6px;border-radius:6px;display:none;flex-shrink:0;}
.sb-juri{padding:10px 16px;background:rgba(0,0,0,0.2);border-bottom:1px solid rgba(255,255,255,0.07);flex-shrink:0;}
.sb-juri p{font-size:0.65rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;margin-bottom:2px;}
.sb-juri strong{font-size:0.82rem;color:#fff;font-weight:700;display:block;word-break:break-word;}
.sb-nav{padding:12px 8px;flex:1;overflow-y:auto;}
.sb-nav a{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,0.7);text-decoration:none;font-size:0.84rem;font-weight:500;padding:10px 12px;border-radius:10px;transition:all 0.18s;margin-bottom:2px;white-space:nowrap;}
.sb-nav a:hover{background:rgba(255,255,255,0.1);color:#fff;}
.sb-nav a.active{background:rgba(255,255,255,0.15);color:#fff;font-weight:700;}
.sb-nav a i{width:18px;text-align:center;font-size:0.9rem;flex-shrink:0;}
.sb-section{font-size:0.62rem;color:rgba(255,255,255,0.28);letter-spacing:2px;text-transform:uppercase;padding:14px 12px 5px;font-weight:700;}
.sb-footer{padding:12px 16px;border-top:1px solid rgba(255,255,255,0.1);flex-shrink:0;}
.sb-user{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.sb-avatar{width:34px;height:34px;border-radius:50%;flex-shrink:0;background:rgba(243,156,18,0.25);display:flex;align-items:center;justify-content:center;font-size:0.88rem;font-weight:800;color:var(--gold);}
.sb-uname{font-size:0.82rem;font-weight:700;color:#fff;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-upos{font-size:0.65rem;color:rgba(255,255,255,0.4);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.sb-logout{display:flex;align-items:center;gap:8px;color:rgba(255,255,255,0.55);text-decoration:none;font-size:0.8rem;font-weight:600;padding:8px 10px;border-radius:8px;transition:all 0.18s;}
.sb-logout:hover{background:rgba(220,38,38,0.2);color:#fca5a5;}
/* MAIN */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-width:0;min-height:100vh;}
/* TOPBAR */
.topbar{background:#fff;border-bottom:4px solid var(--green);padding:0 24px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 10px rgba(0,0,0,0.07);}
.topbar-left{display:flex;align-items:center;gap:12px;min-width:0;}
.ham-btn{background:none;border:none;font-size:1.15rem;color:var(--muted);cursor:pointer;padding:7px;border-radius:8px;display:none;flex-shrink:0;}
.ham-btn:hover{background:#f3f4f6;}
.page-title{font-size:1rem;font-weight:800;color:var(--green-dark);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.page-sub{font-size:0.72rem;color:var(--muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.badge-brgy{background:#f0fdf4;color:var(--green);font-size:0.71rem;font-weight:700;padding:5px 12px;border-radius:20px;border:1px solid #bbf7d0;white-space:nowrap;flex-shrink:0;}
/* CONTENT */
.content{padding:22px 24px;flex:1;}
/* STATS */
.stat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.stat-card{background:var(--card);border-radius:14px;padding:18px 16px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);display:flex;align-items:center;gap:14px;}
.stat-icon{width:46px;height:46px;border-radius:12px;display:flex;align-items:center;justify-content:center;font-size:1.15rem;flex-shrink:0;}
.stat-num{font-size:1.6rem;font-weight:800;line-height:1;color:var(--text);}
.stat-lbl{font-size:0.71rem;color:var(--muted);font-weight:600;margin-top:3px;}
/* CARD */
.card{background:var(--card);border-radius:14px;box-shadow:0 2px 10px rgba(0,0,0,0.06);border:1px solid var(--border);overflow:hidden;margin-bottom:18px;}
.card-header{padding:14px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;gap:10px;}
.card-header h3{font-size:0.88rem;font-weight:700;min-width:0;}
.card-meta{font-size:0.72rem;color:var(--muted);white-space:nowrap;}
/* TABLE */
.table-wrap{overflow-x:auto;-webkit-overflow-scrolling:touch;}
table{width:100%;border-collapse:collapse;min-width:520px;}
thead tr{background:#f8fafc;}
th{padding:10px 14px;font-size:0.68rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;border-bottom:1px solid var(--border);text-align:left;white-space:nowrap;}
td{padding:11px 14px;font-size:0.82rem;border-bottom:1px solid #f3f4f6;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafafa;}
/* PILLS */
.pill{display:inline-flex;align-items:center;gap:4px;padding:3px 9px;border-radius:20px;font-size:0.7rem;font-weight:700;white-space:nowrap;}
.pill-dangerous{background:#fef2f2;color:#991b1b;}
.pill-caution{background:#fffbeb;color:#92400e;}
.pill-safe{background:#f0fdf4;color:#166534;}
.cat-chip{display:inline-flex;align-items:center;gap:5px;font-size:0.72rem;font-weight:600;color:var(--navy);background:#eff6ff;padding:3px 8px;border-radius:6px;white-space:nowrap;}
/* ACTION BTNS */
.btn-icon{width:30px;height:30px;border:none;border-radius:7px;cursor:pointer;font-size:0.8rem;display:inline-flex;align-items:center;justify-content:center;transition:all 0.15s;}
.btn-view{background:#eff6ff;color:#2563eb;} .btn-view:hover{background:#dbeafe;}
.btn-escalate{background:#fffbeb;color:#d97706;} .btn-escalate:hover{background:#fde68a;}
.btn-resolve{background:#f0fdf4;color:#16a34a;} .btn-resolve:hover{background:#dcfce7;}
/* CONTACTS */
.contact-row{display:flex;align-items:flex-start;gap:14px;padding:14px 18px;border-bottom:1px solid #f3f4f6;}
.contact-row:last-child{border-bottom:none;}
.contact-icon{width:40px;height:40px;border-radius:11px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1rem;}
.contact-name{font-size:0.88rem;font-weight:700;margin-bottom:3px;}
.contact-meta{font-size:0.75rem;color:var(--muted);line-height:1.6;}
.contact-type-badge{display:inline-block;font-size:0.63rem;font-weight:700;text-transform:uppercase;letter-spacing:0.8px;padding:2px 8px;border-radius:4px;margin-bottom:4px;}
/* RESIDENT ROW */
.resident-row{display:flex;align-items:center;gap:12px;padding:12px 18px;border-bottom:1px solid #f3f4f6;}
.resident-row:last-child{border-bottom:none;}
.resident-av{width:36px;height:36px;border-radius:50%;background:#eff6ff;color:#2563eb;display:flex;align-items:center;justify-content:center;font-size:0.85rem;font-weight:800;flex-shrink:0;}
.resident-name{font-size:0.86rem;font-weight:700;}
.resident-email{font-size:0.75rem;color:var(--muted);}
/* EMPTY */
.empty{padding:48px 20px;text-align:center;color:var(--muted);}
.empty i{font-size:2.2rem;display:block;margin-bottom:12px;opacity:0.3;}
.empty p{font-size:0.86rem;}
/* COMING SOON */
.coming-soon{padding:60px 24px;text-align:center;}
.coming-soon i{font-size:3rem;color:var(--green);opacity:0.2;display:block;margin-bottom:16px;}
.coming-soon h3{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:6px;}
.coming-soon p{font-size:0.85rem;color:var(--muted);}
/* MAP */
.map-wrap{position:relative;z-index:0;border-radius:14px;overflow:hidden;box-shadow:0 2px 10px rgba(0,0,0,0.1);border:1px solid var(--border);isolation:isolate;}
#incidentMap{height:490px;width:100%;background:#e8f4e8;}
.map-controls{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;background:#fff;border-bottom:1px solid var(--border);border-radius:14px 14px 0 0;}
.map-controls h3{font-size:0.88rem;font-weight:700;margin-right:4px;}
.filter-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.76rem;font-weight:700;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
.filter-btn.active-all{background:var(--green);color:#fff;border-color:var(--green);}
.filter-btn.active-dangerous{background:#dc2626;color:#fff;border-color:#dc2626;}
.filter-btn.active-caution{background:#d97706;color:#fff;border-color:#d97706;}
.filter-btn.active-safe{background:#16a34a;color:#fff;border-color:#16a34a;}
.map-legend{position:absolute;bottom:16px;right:12px;background:rgba(255,255,255,0.95);border-radius:10px;padding:11px 14px;font-size:0.76rem;box-shadow:0 2px 12px rgba(0,0,0,0.15);z-index:400;border:1px solid var(--border);}
.legend-row{display:flex;align-items:center;gap:7px;margin-bottom:5px;font-weight:600;}
.legend-row:last-child{margin-bottom:0;}
.legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
.map-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:14px;}
.map-stat{background:#fff;border-radius:12px;padding:14px 16px;display:flex;align-items:center;gap:12px;box-shadow:0 2px 8px rgba(0,0,0,0.05);border:1px solid var(--border);}
.map-stat-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.map-stat-num{font-size:1.3rem;font-weight:800;line-height:1;}
.map-stat-lbl{font-size:0.68rem;color:var(--muted);font-weight:600;margin-top:2px;}
.no-coords-notice{background:#f0fdf4;border:1px solid #bbf7d0;border-radius:10px;padding:11px 15px;font-size:0.82rem;color:#166534;margin-bottom:14px;display:flex;align-items:center;gap:9px;}
/* REPORTS FILTER */
.reports-filter-bar{display:flex;align-items:center;gap:8px;flex-wrap:wrap;padding:12px 16px;border-bottom:1px solid var(--border);background:#f8fafc;}
.rf-btn{padding:5px 13px;border-radius:20px;border:1.5px solid var(--border);background:#fff;font-size:0.75rem;font-weight:700;cursor:pointer;transition:all 0.18s;font-family:'Inter',sans-serif;}
.rf-btn.rf-active{background:var(--green);color:#fff;border-color:var(--green);}
.rf-search{flex:1;min-width:160px;max-width:260px;padding:6px 12px;border:1.5px solid var(--border);border-radius:20px;font-size:0.8rem;font-family:'Inter',sans-serif;outline:none;}
.rf-search:focus{border-color:#86efac;}
.rpt-stats{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:16px;}
@media(max-width:700px){.map-stats,.rpt-stats{grid-template-columns:1fr 1fr;}#incidentMap{height:340px;}}
/* REPORT DETAIL MODAL */
.modal-bg{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.5);z-index:300;align-items:center;justify-content:center;padding:20px;}
.modal-bg.show{display:flex;}
.modal{background:#fff;border-radius:16px;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);}
.modal-header{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.modal-header h3{font-size:0.95rem;font-weight:800;}
.modal-close{background:none;border:none;font-size:1.2rem;cursor:pointer;color:var(--muted);padding:4px 6px;border-radius:6px;}
.modal-close:hover{background:#f3f4f6;}
.modal-body{padding:20px 22px;}
.detail-row{margin-bottom:14px;}
.detail-lbl{font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;}
.detail-val{font-size:0.88rem;color:var(--text);}
.modal-actions{padding:16px 22px;border-top:1px solid var(--border);display:flex;gap:8px;flex-wrap:wrap;}
.btn-action{padding:8px 16px;border-radius:9px;font-size:0.82rem;font-weight:700;border:none;cursor:pointer;display:inline-flex;align-items:center;gap:7px;font-family:'Inter',sans-serif;transition:all 0.18s;}
.btn-resolve-full{background:#16a34a;color:#fff;} .btn-resolve-full:hover{background:#166534;}
.btn-escalate-full{background:#d97706;color:#fff;} .btn-escalate-full:hover{background:#b45309;}
/* OVERLAY */
.overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.45);z-index:1050;}
.overlay.show{display:block;}
/* RESPONSIVE */
@media(max-width:1000px){.stat-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:860px){.sidebar{transform:translateX(-100%);}.sidebar.open{transform:translateX(0);}.sb-close{display:flex;}.main{margin-left:0;}.ham-btn{display:flex;}.content{padding:16px;}.topbar{padding:0 16px;}}
@media(max-width:480px){.stat-grid{grid-template-columns:1fr 1fr;}.badge-brgy{display:none;}.page-sub{display:none;}}
</style>
</head>
<body>
<div class="overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- REPORT DETAIL MODAL -->
<div class="modal-bg" id="reportModal">
  <div class="modal">
    <div class="modal-header">
      <h3 id="modalTitle">Incident Report</h3>
      <button class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body" id="modalBody"></div>
    <div class="modal-actions">
      <button class="btn-action btn-resolve-full" id="modalResolveBtn"><i class="fas fa-circle-check"></i> Mark Resolved</button>
      <button class="btn-action btn-escalate-full" id="modalEscalateBtn"><i class="fas fa-arrow-up-from-bracket"></i> Escalate to LGU</button>
      <select id="modalStatusSelect" style="padding:8px 12px;border-radius:9px;border:1.5px solid var(--border);font-size:0.82rem;font-weight:600;font-family:'Inter',sans-serif;cursor:pointer;background:#fff;" onchange="changeStatus(this.value)">
        <option value="">— Change Status —</option>
        <option value="dangerous">⛔ Mark Dangerous</option>
        <option value="caution">⚠️ Mark Caution</option>
        <option value="safe">✅ Mark Safe</option>
      </select>
    </div>
  </div>
</div>

<aside class="sidebar" id="sidebar">
  <div class="sb-header">
    <div class="sb-brand">
      <div class="sb-seal"><i class="fas fa-house-flag"></i></div>
      <div><div class="sb-title">SenTri</div><div class="sb-sub">Barangay Portal</div></div>
    </div>
    <button class="sb-close" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
  </div>
  <div class="sb-juri">
    <p>Jurisdiction</p>
    <strong><?= htmlspecialchars($juri) ?><?= $city && $brgy ? ', '.htmlspecialchars($city) : '' ?></strong>
  </div>
  <nav class="sb-nav">
    <?php foreach($nav_items as $key => $item): ?>
      <?php if($key === 'contacts'): ?><div class="sb-section">Management</div><?php endif; ?>
      <?php if($key === 'profile'): ?><div class="sb-section">Account</div><?php endif; ?>
      <a href="barangay.php?view=<?= $key ?>" class="<?= $view===$key?'active':'' ?>">
        <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
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
        <div class="page-title"><?= htmlspecialchars($page_titles[$view] ?? 'Barangay Portal') ?></div>
        <div class="page-sub"><?= htmlspecialchars($org) ?><?= $city ? ' &mdash; '.htmlspecialchars($city) : '' ?></div>
      </div>
    </div>
    <span class="badge-brgy"><i class="fas fa-house-flag"></i>&nbsp; Barangay Official</span>
  </div>

  <div class="content">

  <?php if($view === 'overview'): ?>
    <div class="stat-grid">
      <div class="stat-card"><div class="stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-file-lines"></i></div><div><div class="stat-num"><?= $total ?></div><div class="stat-lbl">Total Reports</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="stat-num"><?= $danger ?></div><div class="stat-lbl">Dangerous</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="stat-num"><?= $caution ?></div><div class="stat-lbl">Caution</div></div></div>
      <div class="stat-card"><div class="stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="stat-num"><?= $safe ?></div><div class="stat-lbl">Resolved</div></div></div>
    </div>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-list" style="color:var(--green);margin-right:6px;"></i>Recent Incidents</h3><span class="card-meta"><?= count($reports) ?> records</span></div>
      <?php if(empty($reports)): ?><div class="empty"><i class="fas fa-folder-open"></i><p>No reports found.</p></div>
      <?php else: ?><?php include_once __DIR__.'/../portal/_report_table.php'; endif; ?>
    </div>

  <?php elseif($view === 'reports'): ?>
    <div class="rpt-stats">
      <div class="map-stat"><div class="map-stat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-file-lines"></i></div><div><div class="map-stat-num"><?= $total ?></div><div class="map-stat-lbl">Total</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="map-stat-num"><?= $danger ?></div><div class="map-stat-lbl">Dangerous</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="map-stat-num"><?= $caution ?></div><div class="map-stat-lbl">Caution</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="map-stat-num"><?= $safe ?></div><div class="map-stat-lbl">Resolved</div></div></div>
    </div>
    <div class="card">
      <div class="card-header">
        <h3><i class="fas fa-file-lines" style="color:var(--green);margin-right:6px;"></i>All Incident Reports</h3>
        <span class="card-meta" id="rptCount"><?= count($reports) ?> records</span>
      </div>
      <div class="reports-filter-bar">
        <button class="rf-btn rf-active" onclick="filterReports('all',this)">All</button>
        <button class="rf-btn" onclick="filterReports('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
        <button class="rf-btn" onclick="filterReports('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
        <button class="rf-btn" onclick="filterReports('safe',this)"><i class="fas fa-circle" style="color:#16a34a;font-size:0.6rem;"></i> Safe</button>
        <input type="search" class="rf-search" id="rptSearch" placeholder="Search reports…" oninput="searchReports(this.value)">
      </div>
      <?php if(empty($reports)): ?><div class="empty"><i class="fas fa-folder-open"></i><p>No reports found.</p></div>
      <?php else: ?><?php include_once __DIR__.'/../portal/_report_table.php'; endif; ?>
    </div>

  <?php elseif($view === 'contacts'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-address-book" style="color:var(--green);margin-right:6px;"></i>Emergency Contacts</h3><span class="card-meta"><?= count($contacts) ?> contacts</span></div>
      <div style="padding:12px 18px;border-bottom:1px solid var(--border);display:flex;justify-content:flex-end;">
        <button onclick="openAddContact()" style="background:var(--green);color:#fff;border:none;padding:7px 15px;border-radius:8px;font-size:0.8rem;font-weight:700;cursor:pointer;display:inline-flex;align-items:center;gap:6px;font-family:'Inter',sans-serif;"><i class="fas fa-plus"></i> Add Contact</button>
      </div>
      <?php if(empty($contacts)): ?><div class="empty"><i class="fas fa-address-book"></i><p>No emergency contacts on file.</p></div>
      <?php else: foreach($contacts as $c): $tc=$type_colors[$c['type']]??['#f5f3ff','#7c3aed']; $ti=$type_icons[$c['type']]??'fa-phone'; ?>
        <div class="contact-row">
          <div class="contact-icon" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><i class="fas <?= $ti ?>"></i></div>
          <div style="flex:1;min-width:0;">
            <div class="contact-type-badge" style="background:<?= $tc[0] ?>;color:<?= $tc[1] ?>;"><?= strtoupper($c['type']) ?></div>
            <div class="contact-name"><?= htmlspecialchars($c['name']) ?></div>
            <div class="contact-meta">
              <?php if($c['contact_number']): ?><span><i class="fas fa-phone" style="margin-right:4px;color:var(--green);"></i><?= htmlspecialchars($c['contact_number']) ?></span>&ensp;<?php endif; ?>
              <?php if($c['contact_email']): ?><span><i class="fas fa-envelope" style="margin-right:4px;color:var(--green);"></i><?= htmlspecialchars($c['contact_email']) ?></span>&ensp;<?php endif; ?>
              <?php if($c['barangay']): ?><span><i class="fas fa-location-dot" style="margin-right:4px;color:var(--muted);"></i><?= htmlspecialchars($c['barangay']) ?><?= $c['city'] ? ', '.htmlspecialchars($c['city']) : '' ?></span><?php endif; ?>
            </div>
          </div>
        </div>
      <?php endforeach; endif; ?>
    </div>

  <?php elseif($view === 'residents'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-users" style="color:var(--green);margin-right:6px;"></i>Registered Residents<?= $brgy ? ' — '.htmlspecialchars($brgy) : '' ?></h3><span class="card-meta"><?= count($residents) ?> residents</span></div>
      <?php if(empty($residents)): ?><div class="empty"><i class="fas fa-users"></i><p>No registered residents found<?= $brgy ? ' in '.htmlspecialchars($brgy) : '' ?>.</p></div>
      <?php else: foreach($residents as $r): ?>
        <div class="resident-row">
          <div class="resident-av"><?= strtoupper(substr($r['first_name'],0,1)) ?></div>
          <div style="flex:1;min-width:0;">
            <div class="resident-name"><?= htmlspecialchars($r['first_name'].' '.$r['last_name']) ?></div>
            <div class="resident-email"><?= htmlspecialchars($r['email']) ?><?= $r['phone_number'] ? ' &nbsp;&middot;&nbsp; '.$r['phone_number'] : '' ?></div>
          </div>
          <span style="font-size:0.73rem;color:var(--muted);"><?= date('M j, Y',strtotime($r['created_at'])) ?></span>
        </div>
      <?php endforeach; endif; ?>
    </div>


  <?php elseif($view === 'map'): ?>
    <!-- ── INCIDENT MAP ── -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.css">
    <script src="https://cdnjs.cloudflare.com/ajax/libs/leaflet/1.9.4/leaflet.min.js"></script>
    <?php
    $mapped    = count($map_reports);
    $unmapped  = $map_total_all - $mapped;
    $m_danger  = count(array_filter($map_reports, function($r){ return $r['status']==='dangerous'; }));
    $m_caution = count(array_filter($map_reports, function($r){ return $r['status']==='caution'; }));
    $m_safe    = count(array_filter($map_reports, function($r){ return $r['status']==='safe'; }));
    ?>
    <?php if($unmapped > 0): ?>
    <div class="no-coords-notice"><i class="fas fa-circle-info"></i><?= $unmapped ?> report<?= $unmapped>1?'s':'' ?> without GPS coordinates are not shown on the map.</div>
    <?php endif; ?>
    <div class="map-stats">
      <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#166534;"><i class="fas fa-map-pin"></i></div><div><div class="map-stat-num"><?= $mapped ?></div><div class="map-stat-lbl">Mapped Reports</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_danger ?></div><div class="map-stat-lbl">Dangerous</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-circle-exclamation"></i></div><div><div class="map-stat-num"><?= $m_caution ?></div><div class="map-stat-lbl">Caution</div></div></div>
      <div class="map-stat"><div class="map-stat-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fas fa-circle-check"></i></div><div><div class="map-stat-num"><?= $m_safe ?></div><div class="map-stat-lbl">Safe / Resolved</div></div></div>
    </div>
    <div class="map-wrap">
      <div class="map-controls">
        <h3><i class="fas fa-map-location-dot" style="color:var(--green);margin-right:6px;"></i>Incident Map<?= $brgy ? ' &mdash; '.htmlspecialchars($brgy) : '' ?></h3>
        <button class="filter-btn active-all" onclick="filterMap('all',this)">All</button>
        <button class="filter-btn" onclick="filterMap('dangerous',this)"><i class="fas fa-circle" style="color:#dc2626;font-size:0.6rem;"></i> Dangerous</button>
        <button class="filter-btn" onclick="filterMap('caution',this)"><i class="fas fa-circle" style="color:#d97706;font-size:0.6rem;"></i> Caution</button>
        <button class="filter-btn" onclick="filterMap('safe',this)"><i class="fas fa-circle" style="color:#16a34a;font-size:0.6rem;"></i> Safe</button>
        <div style="margin-left:auto;font-size:0.74rem;color:var(--muted);">© OpenStreetMap</div>
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
    var mapReports = <?= json_encode(array_map(function($r){
      return ['id'=>(int)$r['id'],'title'=>$r['title'],'category'=>$r['category'],
              'status'=>$r['status'],'barangay'=>$r['barangay']??$r['city']??'',
              'lat'=>(float)$r['latitude'],'lng'=>(float)$r['longitude'],
              'reporter'=>trim($r['first_name'].' '.$r['last_name']),
              'date'=>date('M j, Y g:i A',strtotime($r['created_at'])),'desc'=>$r['description']??''];
    }, $map_reports)) ?>;
    var markerColors = {dangerous:'#dc2626',caution:'#d97706',safe:'#16a34a'};
    var catLabels = {crime:'Crime',accident:'Accident',flooding:'Flooding',fire:'Fire',health:'Health',infrastructure:'Infrastructure',other:'Other'};
    function makeMapIcon(color){
      return L.divIcon({className:'',html:'<div style="width:14px;height:14px;border-radius:50%;background:'+color+';border:2.5px solid #fff;box-shadow:0 2px 6px rgba(0,0,0,0.35);"></div>',iconSize:[14,14],iconAnchor:[7,7]});
    }
    var lmap = L.map('incidentMap');
    L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{maxZoom:19,attribution:'© OpenStreetMap'}).addTo(lmap);
    var allMapMarkers = [];
    mapReports.forEach(function(r){
      var color = markerColors[r.status]||'#888';
      var m = L.marker([r.lat,r.lng],{icon:makeMapIcon(color)});
      m.reportData = r;
      m.bindPopup(
        '<div style="min-width:200px;font-family:Inter,sans-serif;">'+
        '<div style="font-weight:800;font-size:0.9rem;margin-bottom:6px;">'+r.title+'</div>'+
        '<div style="display:flex;gap:6px;flex-wrap:wrap;margin-bottom:8px;">'+
        '<span style="background:'+(r.status==='dangerous'?'#fef2f2':r.status==='caution'?'#fffbeb':'#f0fdf4')+';color:'+(r.status==='dangerous'?'#991b1b':r.status==='caution'?'#92400e':'#166534')+';padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:700;">'+(r.status.charAt(0).toUpperCase()+r.status.slice(1))+'</span>'+
        '<span style="background:#f0fdf4;color:#166534;padding:2px 9px;border-radius:20px;font-size:0.72rem;font-weight:600;">'+(catLabels[r.category]||r.category)+'</span></div>'+
        (r.barangay?'<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Location:</b> '+r.barangay+'</div>':'')+
        '<div style="font-size:0.78rem;color:#6b7280;margin-bottom:4px;"><b>Reported by:</b> '+r.reporter+'</div>'+
        (r.desc?'<div style="font-size:0.78rem;color:#374151;margin-top:6px;">'+r.desc.substring(0,120)+(r.desc.length>120?'…':'')+'</div>':'')+
        '<div style="font-size:0.72rem;color:#9ca3af;margin-top:7px;">'+r.date+' &middot; Report #'+r.id+'</div></div>',
        {maxWidth:260}
      );
      m.addTo(lmap);
      allMapMarkers.push(m);
    });
    if(allMapMarkers.length>0){var g=L.featureGroup(allMapMarkers);lmap.fitBounds(g.getBounds().pad(0.15));}
    else{lmap.setView([14.5995,120.9842],12);}
    function filterMap(status,btn){
      document.querySelectorAll('.filter-btn').forEach(function(b){b.className='filter-btn';});
      btn.classList.add('active-'+status);
      allMapMarkers.forEach(function(m){
        var show=(status==='all'||m.reportData.status===status);
        if(show){if(!lmap.hasLayer(m))m.addTo(lmap);}
        else{if(lmap.hasLayer(m))lmap.removeLayer(m);}
      });
    }
    </script>

  <?php elseif($view === 'profile'): ?>
    <div class="card">
      <div class="card-header"><h3><i class="fas fa-id-card" style="color:var(--green);margin-right:6px;"></i>My Profile</h3></div>
      <div style="padding:24px;">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:16px;">
          <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Name</div><div style="font-size:0.9rem;font-weight:600;"><?= htmlspecialchars($fname) ?></div></div>
          <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Role</div><div style="font-size:0.9rem;">Barangay Official</div></div>
          <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Position</div><div style="font-size:0.9rem;"><?= htmlspecialchars($pos) ?></div></div>
          <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Office</div><div style="font-size:0.9rem;"><?= htmlspecialchars($org) ?></div></div>
          <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Barangay</div><div style="font-size:0.9rem;"><?= htmlspecialchars($brgy ?: 'Not set') ?></div></div>
          <div><div class="detail-lbl" style="font-size:0.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;margin-bottom:4px;">Municipality</div><div style="font-size:0.9rem;"><?= htmlspecialchars($city ?: 'Not set') ?></div></div>
        </div>
      </div>
    </div>

  <?php else: ?>
    <div class="card"><div class="coming-soon"><i class="fas <?= $nav_items[$view]['icon'] ?? 'fa-gear' ?>"></i><h3><?= htmlspecialchars($page_titles[$view] ?? ucfirst($view)) ?></h3><p>This section is under development.</p></div></div>
  <?php endif; ?>

  </div>
</div>

<script>
async function changeStatus(newStatus){
  if(!newStatus || !currentReportId) return;
  if(!confirm('Change report status to ' + newStatus + '?')) { document.getElementById('modalStatusSelect').value=''; return; }
  try {
    var fd=new FormData(); fd.append('action','update_report_status');
    fd.append('report_id',currentReportId); fd.append('status',newStatus);
    var res=await fetch('../api/reports.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success'){ closeModal(); location.reload(); }
    else { alert(data.message||'Error.'); document.getElementById('modalStatusSelect').value=''; }
  } catch(e) { document.getElementById('modalStatusSelect').value=''; }
}

function openSidebar(){document.getElementById('sidebar').classList.add('open');document.getElementById('overlay').classList.add('show');document.body.style.overflow='hidden';}
function closeSidebar(){document.getElementById('sidebar').classList.remove('open');document.getElementById('overlay').classList.remove('show');document.body.style.overflow='';}
function closeModal(){document.getElementById('reportModal').classList.remove('show');}

function quickResolve(id,btn){
  if(!confirm('Mark this report as resolved?'))return;
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i>';
  var fd=new FormData(); fd.append('action','resolve_report'); fd.append('report_id',id);
  fetch('../api/reports.php',{method:'POST',body:fd})
    .then(function(r){return r.json();})
    .then(function(data){if(data.status==='success')location.reload();else{alert(data.message);btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i>';}})
    .catch(function(){btn.disabled=false;btn.innerHTML='<i class="fas fa-check"></i>';});
}
var currentReportId = null;
function viewReport(id,title,category,status,barangay,reporter,date,desc){
  currentReportId=id;
  document.getElementById('modalTitle').textContent=title;
  document.getElementById('modalBody').innerHTML=
    '<div class="detail-row"><div class="detail-lbl">Status</div><div class="detail-val"><span class="pill pill-'+status+'">'+status.charAt(0).toUpperCase()+status.slice(1)+'</span></div></div>'+
    '<div class="detail-row"><div class="detail-lbl">Category</div><div class="detail-val">'+category.charAt(0).toUpperCase()+category.slice(1)+'</div></div>'+
    '<div class="detail-row"><div class="detail-lbl">Location</div><div class="detail-val">'+barangay+'</div></div>'+
    '<div class="detail-row"><div class="detail-lbl">Reported By</div><div class="detail-val">'+reporter+'</div></div>'+
    '<div class="detail-row"><div class="detail-lbl">Date</div><div class="detail-val">'+date+'</div></div>'+
    (desc?'<div class="detail-row"><div class="detail-lbl">Description</div><div class="detail-val" style="line-height:1.6;">'+desc+'</div></div>':'');
  document.getElementById('reportModal').classList.add('show');
}
document.getElementById('modalResolveBtn').onclick=async function(){
  if(!currentReportId)return;
  const fd=new FormData(); fd.append('action','resolve_report'); fd.append('report_id',currentReportId);
  const res=await fetch('../api/reports.php',{method:'POST',body:fd});
  const data=await res.json();
  if(data.status==='success'){closeModal();location.reload();}else alert(data.message);
};
document.getElementById('reportModal').addEventListener('click',function(e){if(e.target===this)closeModal();});

// Escalate to LGU
document.getElementById('modalEscalateBtn').onclick = async function(){
  if(!currentReportId) return;
  if(!confirm('Escalate this report to LGU for urgent attention?')) return;
  var btn = this; btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Escalating…';
  try {
    var fd=new FormData(); fd.append('action','escalate_report'); fd.append('report_id',currentReportId);
    var res=await fetch('../api/reports.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success'){
      btn.innerHTML='<i class="fas fa-check"></i> Escalated to LGU';
      btn.style.background='#d97706';
      setTimeout(function(){ closeModal(); location.reload(); }, 1000);
    } else {
      alert(data.message||'Escalation failed.'); btn.disabled=false;
      btn.innerHTML='<i class="fas fa-arrow-up-from-bracket"></i> Escalate to LGU';
    }
  } catch(e) {
    btn.disabled=false; btn.innerHTML='<i class="fas fa-arrow-up-from-bracket"></i> Escalate to LGU';
  }
};

// Client-side filter for reports table
var activeRptStatus = 'all';
function filterReports(status, btn){
  activeRptStatus = status;
  document.querySelectorAll('.rf-btn').forEach(function(b){ b.classList.remove('rf-active'); });
  btn.classList.add('rf-active');
  applyRptFilters();
}
function searchReports(q){ applyRptFilters(); }
function applyRptFilters(){
  var q = (document.getElementById('rptSearch')||{value:''}).value.toLowerCase();
  var rows = document.querySelectorAll('tbody tr');
  var visible = 0;
  rows.forEach(function(row){
    var statusEl = row.querySelector('.pill');
    var status = statusEl ? statusEl.className.replace('pill pill-','').trim() : '';
    var text = row.textContent.toLowerCase();
    var show = (activeRptStatus==='all' || status===activeRptStatus) && (!q || text.includes(q));
    row.style.display = show ? '' : 'none';
    if(show) visible++;
  });
  var cnt = document.getElementById('rptCount');
  if(cnt) cnt.textContent = visible + ' records';
}
</script>

<!-- ADD CONTACT MODAL -->
<div class="modal-bg" id="contactModal">
  <div class="modal" style="max-width:480px;">
    <div class="modal-header">
      <h3><i class="fas fa-address-book" style="color:var(--green);margin-right:6px;"></i>Add Emergency Contact</h3>
      <button class="modal-close" onclick="closeContactModal()"><i class="fas fa-xmark"></i></button>
    </div>
    <div class="modal-body">
      <div style="display:grid;gap:12px;">
        <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Name *</label>
        <input id="cn_name" type="text" placeholder="e.g. Barangay Health Center" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:10px;">
          <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Type *</label>
          <select id="cn_type" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;background:#fff;">
            <option value="barangay">Barangay</option><option value="hospital">Hospital</option>
            <option value="police">Police</option><option value="fire">Fire</option>
            <option value="lgu">LGU</option><option value="traffic">Traffic</option><option value="other">Other</option>
          </select></div>
          <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">City *</label>
          <input id="cn_city" type="text" placeholder="City/Municipality" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
        </div>
        <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Contact Number</label>
        <input id="cn_phone" type="tel" placeholder="+63 9XX XXX XXXX" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
        <div><label style="font-size:0.75rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:0.8px;display:block;margin-bottom:4px;">Email</label>
        <input id="cn_email" type="email" placeholder="contact@example.com" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;font-size:0.88rem;font-family:'Inter',sans-serif;outline:none;"></div>
        <div id="cn_error" style="display:none;background:#fef2f2;color:#991b1b;border:1px solid #fecaca;border-radius:8px;padding:9px 12px;font-size:0.82rem;font-weight:600;"></div>
      </div>
    </div>
    <div class="modal-actions">
      <button class="btn-action btn-resolve-full" id="cn_saveBtn" onclick="saveContact()"><i class="fas fa-floppy-disk"></i> Save Contact</button>
    </div>
  </div>
</div>

<script>
function openAddContact(){ document.getElementById('contactModal').classList.add('show'); }
function closeContactModal(){ document.getElementById('contactModal').classList.remove('show'); document.getElementById('cn_error').style.display='none'; }
async function saveContact(){
  var name=document.getElementById('cn_name').value.trim();
  var type=document.getElementById('cn_type').value;
  var city=document.getElementById('cn_city').value.trim();
  var phone=document.getElementById('cn_phone').value.trim();
  var email=document.getElementById('cn_email').value.trim();
  var err=document.getElementById('cn_error');
  if(!name||!city){ err.textContent='Name and city are required.'; err.style.display='block'; return; }
  var btn=document.getElementById('cn_saveBtn'); btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving…';
  var fd=new FormData(); fd.append('action','create'); fd.append('name',name); fd.append('type',type);
  fd.append('city',city); fd.append('contact_number',phone); fd.append('contact_email',email);
  try {
    var res=await fetch('../api/contacts.php',{method:'POST',body:fd});
    var data=await res.json();
    if(data.status==='success'){ closeContactModal(); location.reload(); }
    else { err.textContent=data.message||'Save failed.'; err.style.display='block'; btn.disabled=false; btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save Contact'; }
  } catch(e){ err.textContent='Request failed.'; err.style.display='block'; btn.disabled=false; btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save Contact'; }
}
document.getElementById('contactModal').addEventListener('click',function(e){if(e.target===this)closeContactModal();});
</script>
</body>
</html>

<?php
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
require_once __DIR__ . '/../config/auth.php';
require_role(['community','user']);
require_once __DIR__ . '/../config/db.php';

$uid   = (int)$_SESSION['user_id'];
$fname = $_SESSION['first_name'];
$lname = $_SESSION['last_name'];
$view  = $_GET['view'] ?? 'overview';

/* ── Profile ─────────────────────────────────────────────────── */
$avatar_color = '#1c57b2';
$user_email = $user_phone = $user_brgy = $user_muni = '';
$saved_gps_lat = $saved_gps_lng = null;

$s = $conn->prepare("SELECT email, phone_number, barangay_name, municipality FROM users WHERE id=? LIMIT 1");
$s->bind_param("i",$uid);$s->execute();$row=$s->get_result()->fetch_assoc();$s->close();
if($row){$user_email=$row['email']??'';$user_phone=$row['phone_number']??'';$user_brgy=$row['barangay_name']??'';$user_muni=$row['municipality']??'';}
$avRes=$conn->query("SHOW COLUMNS FROM users LIKE 'avatar_color'");
if($avRes&&$avRes->num_rows>0){$as=$conn->prepare("SELECT avatar_color FROM users WHERE id=? LIMIT 1");$as->bind_param("i",$uid);$as->execute();$as->bind_result($av);$as->fetch();$as->close();if($av)$avatar_color=$av;}
$gpsRes=$conn->query("SHOW COLUMNS FROM users LIKE 'gps_lat'");
if($gpsRes&&$gpsRes->num_rows>0){$gs=$conn->prepare("SELECT gps_lat,gps_lng FROM users WHERE id=? LIMIT 1");$gs->bind_param("i",$uid);$gs->execute();$gs->bind_result($saved_gps_lat,$saved_gps_lng);$gs->fetch();$gs->close();}

/* ── Stats ────────────────────────────────────────────────────── */
function cq($conn,$sql,$t='',$p=[]){$s=$conn->prepare($sql);if($t&&$p){$refs=[];foreach($p as &$v)$refs[]=&$v;array_unshift($refs,$t);call_user_func_array([$s,'bind_param'],$refs);}$s->execute();$s->bind_result($n);$s->fetch();$s->close();return(int)$n;}
$total_reports=cq($conn,"SELECT COUNT(*) FROM reports WHERE is_archived=0");
$danger_count =cq($conn,"SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0");
$safe_count   =cq($conn,"SELECT COUNT(*) FROM reports WHERE status='safe' AND is_archived=0");
$my_count     =cq($conn,"SELECT COUNT(*) FROM reports WHERE user_id=? AND is_archived=0",'i',[$uid]);

/* ── Per-view data ────────────────────────────────────────────── */
$contacts = [];
if($view==='contacts'){$cs=$conn->query("SELECT * FROM emergency_contacts WHERE is_active=1 ORDER BY type,name");if($cs)while($r=$cs->fetch_assoc())$contacts[]=$r;}

/* ── Profile POST ─────────────────────────────────────────────── */
$profile_msg = '';
if($view==='profile'&&$_SERVER['REQUEST_METHOD']==='POST'&&isset($_POST['save_profile'])){
    $pf=trim($_POST['first_name']??'');$pl=trim($_POST['last_name']??'');
    $pp=trim($_POST['phone']??'');$pm=trim($_POST['municipality']??'');$pb=trim($_POST['barangay_name']??'');
    $pw=$_POST['new_password']??'';$pw2=$_POST['confirm_password']??'';
    if($pw&&$pw!==$pw2)        {$profile_msg='error:Passwords do not match.';}
    elseif(!$pf||!$pl)          {$profile_msg='error:Name fields are required.';}
    else{
        if($pw){$h=password_hash($pw,PASSWORD_BCRYPT,['cost'=>10]);$su=$conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,municipality=?,barangay_name=?,password=? WHERE id=?");$su->bind_param("ssssssi",$pf,$pl,$pp,$pm,$pb,$h,$uid);}
        else{$su=$conn->prepare("UPDATE users SET first_name=?,last_name=?,phone_number=?,municipality=?,barangay_name=? WHERE id=?");$su->bind_param("sssssi",$pf,$pl,$pp,$pm,$pb,$uid);}
        $su->execute();$su->close();
        $_SESSION['first_name']=htmlspecialchars($pf,ENT_QUOTES,'UTF-8');$_SESSION['last_name']=htmlspecialchars($pl,ENT_QUOTES,'UTF-8');
        $fname=$_SESSION['first_name'];$lname=$_SESSION['last_name'];
        $user_phone=$pp;$user_muni=$pm;$user_brgy=$pb;$profile_msg='success:Profile updated.';
    }
}

/* ── Lookup maps ──────────────────────────────────────────────── */
$type_icons =['lgu'=>'fa-landmark','hospital'=>'fa-hospital','traffic'=>'fa-traffic-light','barangay'=>'fa-house-flag','police'=>'fa-shield','fire'=>'fa-fire-extinguisher','other'=>'fa-phone'];
$type_colors=['lgu'=>['#f0f7ff','#0a3d62'],'hospital'=>['#ecfdf5','#059669'],'traffic'=>['#fffbeb','#d97706'],'barangay'=>['#f0fdf4','#166534'],'police'=>['#eff6ff','#2563eb'],'fire'=>['#fef2f2','#dc2626'],'other'=>['#f5f3ff','#7c3aed']];
$type_labels=['lgu'=>'LGU Offices','hospital'=>'Hospitals','police'=>'Police','fire'=>'Fire Services','traffic'=>'Traffic Management','barangay'=>'Barangay Offices','other'=>'Other Services'];
$nav=['overview'=>['icon'=>'fa-house','label'=>'Community Feed'],'my_reports'=>['icon'=>'fa-file-lines','label'=>'My Reports'],'map'=>['icon'=>'fa-map-location-dot','label'=>'Incidents Map'],'contacts'=>['icon'=>'fa-address-book','label'=>'Emergency Contacts'],'profile'=>['icon'=>'fa-id-card','label'=>'My Profile']];
$page_titles=['overview'=>'Community Feed','my_reports'=>'My Reports','map'=>'Incidents Map','contacts'=>'Emergency Contacts','profile'=>'My Profile'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title><?= htmlspecialchars($page_titles[$view]??'Community Portal') ?> — SenTri</title>
<link rel="stylesheet" href="../assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="../assets/vendor/fontawesome/css/all.min.css">
<link rel="stylesheet" href="../assets/vendor/leaflet/leaflet.css">
<style>
:root{--blue:#0a3d62;--blue-dark:#062444;--blue-light:#1a5276;--blue-accent:#3a8dff;--red:#e53e3e;--green:#38a169;--orange:#dd6b20;--gold:#f39c12;--text:#1a1a2e;--muted:#666;--border:#e5e7eb;--bg:#f0f2f7;--card:#fff;--card-border:#eee;--input-bg:#fafafa;--input-border:#e0e0e0;--input-focus:#fff;--topbar-bg:#fff;--topbar-shadow:0 2px 12px rgba(0,0,0,.07);--sidebar-w:256px;}
body.dark{--bg:#0d1117;--card:#161b22;--card-border:#30363d;--text:#e6edf3;--muted:#8b949e;--border:#30363d;--input-bg:#0d1117;--input-border:#30363d;--input-focus:#161b22;--topbar-bg:#161b22;--topbar-shadow:0 2px 12px rgba(0,0,0,.4);}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif;}
body{background:var(--bg);color:var(--text);display:flex;min-height:100vh;overflow-x:hidden;transition:background .3s,color .3s;}

/* ── Sidebar ── */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:linear-gradient(180deg,var(--blue-dark) 0%,var(--blue) 55%,#0e4d80 100%);color:#fff;display:flex;flex-direction:column;position:fixed;top:0;left:0;bottom:0;z-index:1100;box-shadow:4px 0 20px rgba(0,0,0,.25);transition:transform .3s cubic-bezier(.4,0,.2,1);}
.sidebar.closed{transform:translateX(calc(-1*var(--sidebar-w)));}
.sidebar-header{padding:20px 16px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,.1);}
.brand-logo{display:flex;align-items:center;gap:10px;}
.brand-icon-s{width:36px;height:36px;background:rgba(255,255,255,.15);border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;border:1px solid rgba(255,255,255,.2);}
.brand-name-s{font-size:1.05rem;font-weight:800;}
.brand-sub{font-size:.6rem;color:rgba(255,255,255,.5);letter-spacing:1px;text-transform:uppercase;}
.toggle-btn{background:none;border:none;color:rgba(255,255,255,.7);cursor:pointer;font-size:1rem;padding:6px;border-radius:8px;transition:all .2s;}
.toggle-btn:hover{background:rgba(255,255,255,.1);color:#fff;}
.menu{padding:12px 10px;display:flex;flex-direction:column;gap:2px;}
.menu a{display:flex;align-items:center;gap:11px;padding:11px 13px;text-decoration:none;color:rgba(255,255,255,.75);font-size:.875rem;font-weight:500;border-radius:10px;transition:all .2s;white-space:nowrap;}
.menu a:hover{background:rgba(255,255,255,.12);color:#fff;}
.menu a.active{background:rgba(255,255,255,.2);color:#fff;font-weight:700;border-left:3px solid rgba(255,255,255,.65);}
.menu a i{font-size:1rem;width:18px;text-align:center;flex-shrink:0;}
.menu-badge{margin-left:auto;background:rgba(255,255,255,.2);color:#fff;font-size:.65rem;padding:2px 7px;border-radius:20px;font-weight:700;}
.sidebar-stats{padding:8px 14px 4px;}
.stat-label-s{font-size:.68rem;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.8px;margin-bottom:8px;padding:0 4px;}
.stat-box{background:rgba(255,255,255,.08);border-radius:10px;padding:10px 14px;margin-bottom:7px;border:1px solid rgba(255,255,255,.05);}
.stat-box .num{font-size:1.25rem;font-weight:700;}
.stat-box .lbl{font-size:.72rem;opacity:.7;margin-top:1px;}
.stat-box.danger-box{background:rgba(229,62,62,.18);border-left:3px solid #e53e3e;}
.stat-box.safe-box{background:rgba(56,161,105,.18);border-left:3px solid #38a169;}
.sidebar-footer{margin-top:auto;padding:14px;display:flex;flex-direction:column;gap:2px;}
.sidebar-footer a{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.65);text-decoration:none;font-size:.87rem;padding:10px 13px;border-radius:10px;transition:all .2s;}
.sidebar-footer a:hover{background:rgba(255,255,255,.1);color:#fff;}
.dm-row{display:flex;align-items:center;justify-content:space-between;padding:10px 13px;border-radius:10px;cursor:pointer;transition:background .2s;}
.dm-row:hover{background:rgba(255,255,255,.1);}
.dm-row-label{display:flex;align-items:center;gap:10px;color:rgba(255,255,255,.65);font-size:.87rem;}
.dm-switch{position:relative;width:38px;height:20px;flex-shrink:0;}
.dm-switch input{display:none;}
.dm-slider{position:absolute;inset:0;background:rgba(255,255,255,.2);border-radius:20px;cursor:pointer;transition:background .3s;}
.dm-slider::before{content:'';position:absolute;width:14px;height:14px;left:3px;top:3px;background:#fff;border-radius:50%;transition:transform .3s;}
body.dark .dm-slider{background:var(--blue-accent);}
body.dark .dm-slider::before{transform:translateX(18px);}
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1099;backdrop-filter:blur(2px);}
.sidebar-overlay.show{display:block;}

/* ── Main / Topbar ── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-width:0;transition:margin-left .3s;}
.topbar{background:var(--topbar-bg);padding:14px 28px;display:flex;align-items:center;justify-content:space-between;box-shadow:var(--topbar-shadow);position:sticky;top:0;z-index:50;transition:background .3s;}
.topbar-left{display:flex;align-items:center;gap:12px;}
.ham-btn{background:none;border:none;font-size:1.2rem;color:var(--muted);cursor:pointer;padding:7px;border-radius:9px;transition:all .2s;display:none;}
.ham-btn:hover{background:var(--bg);}
.topbar h1{font-size:1.1rem;font-weight:700;color:var(--text);}
.right-top{display:flex;align-items:center;gap:10px;}
.post-btn{background:linear-gradient(135deg,var(--blue-accent),var(--blue));color:#fff;border:none;padding:10px 20px;border-radius:10px;font-size:.87rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:7px;font-family:'Poppins',sans-serif;transition:all .25s;}
.post-btn:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(58,141,255,.4);}
.gps-chip{display:flex;align-items:center;gap:6px;padding:6px 12px;border-radius:50px;font-size:.76rem;font-weight:600;background:var(--bg);color:var(--muted);border:1.5px solid var(--input-border);transition:all .3s;}
.gps-chip.active{background:#f0fff4;color:#38a169;border-color:#38a169;}
body.dark .gps-chip.active{background:#1a2e24;}
.gps-dot{width:7px;height:7px;border-radius:50%;background:currentColor;flex-shrink:0;}
@keyframes gpsPulse{0%,100%{box-shadow:0 0 0 0 rgba(56,161,105,.5);}50%{box-shadow:0 0 0 5px rgba(56,161,105,0);}}
.gps-chip.active .gps-dot{animation:gpsPulse 1.5s ease infinite;}
.user-info{display:flex;align-items:center;gap:10px;cursor:pointer;padding:5px 10px;border-radius:10px;transition:background .2s;}
.user-info:hover{background:var(--bg);}
.avatar{width:38px;height:38px;border-radius:50%;color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0;}
.user-name{font-size:.88rem;font-weight:600;color:var(--text);}
.content{padding:28px;flex:1;animation:fadeIn .4s ease;}

/* ── Stats Row ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:28px;}
.stat-card{background:var(--card);border-radius:16px;padding:18px 20px;box-shadow:0 2px 12px rgba(0,0,0,.06);display:flex;align-items:center;gap:14px;animation:fadeInUp .5s both;transition:transform .2s,box-shadow .2s,background .3s;position:relative;overflow:hidden;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,.1);}
.stat-card:nth-child(1){animation-delay:.05s;}.stat-card:nth-child(2){animation-delay:.1s;}.stat-card:nth-child(3){animation-delay:.15s;}.stat-card:nth-child(4){animation-delay:.2s;}
.stat-icon{width:48px;height:48px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0;}
.stat-icon.blue{background:#ebf2ff;color:var(--blue);}.stat-icon.red{background:#fff0f0;color:var(--red);}.stat-icon.green{background:#f0fff4;color:var(--green);}.stat-icon.orange{background:#fff8f0;color:var(--orange);}
body.dark .stat-icon.blue{background:#1f3a5f;}body.dark .stat-icon.red{background:#3d1f1f;}body.dark .stat-icon.green{background:#1a2e24;}body.dark .stat-icon.orange{background:#2e2010;}
.stat-card strong{display:block;font-size:1.5rem;font-weight:800;color:var(--text);}
.stat-card span{font-size:.78rem;color:var(--muted);}

/* ── Filters ── */
.filters{background:var(--card);border-radius:14px;padding:14px 18px;box-shadow:0 2px 10px rgba(0,0,0,.06);margin-bottom:22px;display:flex;gap:10px;flex-wrap:wrap;align-items:center;transition:background .3s;}
.filters input,.filters select{padding:9px 13px;border:1.5px solid var(--input-border);border-radius:9px;font-size:.87rem;outline:none;font-family:'Poppins',sans-serif;background:var(--input-bg);color:var(--text);transition:all .2s;}
.filters input{flex:1;min-width:160px;}
.filters input:focus,.filters select:focus{border-color:var(--blue-accent);}
.filters button{padding:9px 18px;border:none;border-radius:9px;font-size:.87rem;font-weight:600;cursor:pointer;background:var(--bg);color:var(--muted);font-family:'Poppins',sans-serif;transition:all .2s;}
.filters button.active,.filters button:hover{background:linear-gradient(135deg,var(--blue-accent),var(--blue));color:#fff;}
body.dark .filters{background:var(--card);}
body.dark .filters input,body.dark .filters select{background:var(--input-bg);border-color:var(--input-border);color:var(--text);}
body.dark .filters button{background:#21262d;color:var(--muted);}
.view-toggle{display:flex;gap:4px;background:var(--bg);border-radius:10px;padding:4px;margin-left:auto;flex-shrink:0;}
.view-btn{display:flex;align-items:center;gap:6px;padding:7px 14px;border:none;border-radius:7px;font-size:.82rem;font-weight:600;cursor:pointer;background:transparent;color:var(--muted);font-family:'Poppins',sans-serif;transition:all .2s;}
.view-btn.active{background:var(--card);color:var(--blue);box-shadow:0 2px 8px rgba(0,0,0,.1);}

/* ── Report Cards ── */
#feed{display:flex;flex-direction:column;gap:16px;}
.report-card{background:var(--card);border-radius:14px;padding:20px 22px;box-shadow:0 2px 10px rgba(0,0,0,.06);border-left:5px solid #ccc;transition:all .25s,background .3s;animation:cardEntrance .45s both;cursor:pointer;}
.report-card:hover{transform:translateY(-2px);box-shadow:0 8px 28px rgba(0,0,0,.1);}
.report-card.dangerous{border-left-color:var(--red);}.report-card.dangerous .card-header{background:linear-gradient(90deg,#fff0f0,transparent);}
.report-card.caution{border-left-color:var(--orange);}.report-card.caution .card-header{background:linear-gradient(90deg,#fff8f0,transparent);}
.report-card.safe{border-left-color:var(--green);}.report-card.safe .card-header{background:linear-gradient(90deg,#f0fff4,transparent);}
body.dark .report-card{background:var(--card);}
body.dark .report-card.dangerous .card-header{background:linear-gradient(90deg,rgba(229,62,62,.08),transparent);}
body.dark .report-card.caution  .card-header{background:linear-gradient(90deg,rgba(221,107,32,.08),transparent);}
body.dark .report-card.safe     .card-header{background:linear-gradient(90deg,rgba(56,161,105,.08),transparent);}
.card-header{display:flex;align-items:flex-start;justify-content:space-between;gap:12px;margin-bottom:10px;padding:4px 0;}
.card-header h3{font-size:1rem;font-weight:700;color:var(--text);line-height:1.4;}
.badge{padding:4px 12px;border-radius:50px;font-size:.73rem;font-weight:700;text-transform:uppercase;letter-spacing:.5px;flex-shrink:0;}
.badge.dangerous{background:#fff0f0;color:var(--red);}.badge.caution{background:#fff8f0;color:var(--orange);}.badge.safe{background:#f0fff4;color:var(--green);}
body.dark .badge.dangerous{background:#3d1f1f;}body.dark .badge.caution{background:#2e2010;}body.dark .badge.safe{background:#1a2e24;}
.card-meta{display:flex;flex-wrap:wrap;gap:10px;font-size:.79rem;color:#777;margin-bottom:10px;}
.card-meta span{display:flex;align-items:center;gap:5px;}
body.dark .card-meta{color:#8b949e;}
.card-body p{font-size:.87rem;color:#444;line-height:1.75;}
body.dark .card-body p{color:#c9d1d9;}
.card-footer{margin-top:14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.vote-btn{display:flex;align-items:center;gap:6px;padding:7px 16px;border:1.5px solid var(--input-border);border-radius:9px;font-size:.82rem;font-weight:600;cursor:pointer;background:var(--card);color:#555;transition:all .2s;font-family:'Poppins',sans-serif;}
.vote-btn:hover{background:#f5f8ff;border-color:var(--blue-accent);color:var(--blue);}
.vote-btn.voted{background:#ebf2ff;border-color:var(--blue);color:var(--blue);}
.vote-btn.down:hover{background:#fff5f5;border-color:var(--red);color:var(--red);}
.vote-btn.down.voted{background:#fff5f5;border-color:var(--red);color:var(--red);}
body.dark .vote-btn{background:#21262d;border-color:#30363d;color:#8b949e;}
body.dark .vote-btn.voted{background:#1f3a5f;border-color:var(--blue);color:var(--blue-accent);}
body.dark .vote-btn.down.voted{background:#3d1f1f;border-color:var(--red);color:#fc8181;}
.pin-chip{display:inline-flex;align-items:center;gap:5px;font-size:.74rem;background:#ebf2ff;color:var(--blue);border-radius:7px;padding:4px 10px;cursor:pointer;border:none;font-family:'Poppins',sans-serif;font-weight:600;transition:all .2s;}
.pin-chip:hover{background:#dbeafe;}
body.dark .pin-chip{background:#1f3a5f;color:var(--blue-accent);}
.category-tag{font-size:.76rem;background:var(--bg);padding:5px 11px;border-radius:8px;color:var(--muted);}
.empty{text-align:center;padding:60px 20px;color:#bbb;animation:fadeIn .4s ease;}
.empty i{font-size:3rem;margin-bottom:14px;display:block;}
.loading{text-align:center;padding:50px;color:#888;font-size:.9rem;}
.loading i{animation:spin 1s linear infinite;margin-right:8px;}

/* ── My-reports header ── */
.my-reports-hdr{background:linear-gradient(135deg,#ebf2ff,#f0f7ff);border:1.5px solid #dbeafe;border-radius:14px;padding:18px 22px;margin-bottom:22px;display:flex;align-items:center;gap:16px;}
body.dark .my-reports-hdr{background:linear-gradient(135deg,#1f3a5f,#162d4a);border-color:#2d4a7a;}
.my-reports-hdr .mh-icon{width:48px;height:48px;border-radius:13px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--blue);flex-shrink:0;box-shadow:0 2px 8px rgba(58,141,255,.15);}
.my-reports-hdr h2{font-size:1rem;font-weight:700;color:var(--text);}
.my-reports-hdr p{font-size:.79rem;color:var(--muted);margin-top:2px;}

/* ── Map ── */
#mapWrap{border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);isolation:isolate;}
#incidentMap{height:calc(100vh - 200px);min-height:520px;background:#dde8f0;}
.map-legend{background:var(--card);padding:14px 20px;display:flex;align-items:center;gap:20px;flex-wrap:wrap;border-top:1px solid var(--card-border);transition:background .3s;}
.legend-item{display:flex;align-items:center;gap:8px;font-size:.79rem;font-weight:600;color:#555;}
body.dark .legend-item{color:#8b949e;}
.legend-dot{width:12px;height:12px;border-radius:50%;flex-shrink:0;}
.legend-dot.dangerous{background:var(--red);}.legend-dot.caution{background:var(--orange);}.legend-dot.safe{background:var(--green);}
.map-count{margin-left:auto;font-size:.79rem;color:var(--muted);font-style:italic;}

/* ── Emergency Contacts ── */
.contacts-hdr{background:linear-gradient(135deg,#fff0f0,#fff8f0);border:1.5px solid #fecaca;border-radius:14px;padding:18px 22px;margin-bottom:24px;display:flex;align-items:center;gap:16px;}
body.dark .contacts-hdr{background:linear-gradient(135deg,#3d1f1f,#2e2010);border-color:#7f1d1d;}
.contacts-hdr .ch-icon{width:48px;height:48px;border-radius:13px;background:#fff;display:flex;align-items:center;justify-content:center;font-size:1.3rem;color:var(--red);flex-shrink:0;box-shadow:0 2px 8px rgba(229,62,62,.15);}
.contacts-hdr h2{font-size:1rem;font-weight:700;color:var(--text);}
.contacts-hdr p{font-size:.79rem;color:var(--muted);margin-top:2px;}
.contacts-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(290px,1fr));gap:16px;}
.contact-card{background:var(--card);border-radius:14px;padding:18px 20px;box-shadow:0 2px 10px rgba(0,0,0,.06);border-left:4px solid #ccc;transition:transform .2s,box-shadow .2s,background .3s;animation:fadeInUp .4s both;}
.contact-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.1);}
.contact-top{display:flex;align-items:flex-start;gap:13px;margin-bottom:12px;}
.contact-icon{width:42px;height:42px;border-radius:11px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.contact-name{font-size:.95rem;font-weight:700;color:var(--text);line-height:1.4;}
.contact-type-lbl{font-size:.72rem;text-transform:uppercase;font-weight:700;letter-spacing:.5px;margin-top:3px;}
.contact-details{display:flex;flex-direction:column;gap:6px;}
.contact-row{display:flex;align-items:center;gap:8px;font-size:.82rem;color:var(--muted);}
.contact-row a{color:var(--blue);text-decoration:none;font-weight:600;}
.contact-row a:hover{text-decoration:underline;}
body.dark .contact-card{background:var(--card);}
body.dark .contact-name{color:var(--text);}
body.dark .contact-row{color:#8b949e;}

/* ── Profile Page ── */
.profile-grid{display:grid;grid-template-columns:300px 1fr;gap:22px;align-items:start;}
.profile-card{background:var(--card);border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,.06);transition:background .3s;}
.profile-avatar-section{display:flex;flex-direction:column;align-items:center;gap:14px;padding-bottom:20px;border-bottom:1px solid var(--card-border);margin-bottom:20px;}
.profile-avatar-big{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:#fff;}
.profile-name-big{font-size:1.05rem;font-weight:700;color:var(--text);text-align:center;}
.role-badge{display:inline-block;font-size:.74rem;background:#ebf2ff;color:var(--blue);padding:4px 12px;border-radius:20px;font-weight:700;}
body.dark .role-badge{background:#1f3a5f;color:var(--blue-accent);}
.color-palette{display:flex;gap:8px;flex-wrap:wrap;justify-content:center;margin-top:4px;}
.color-swatch{width:26px;height:26px;border-radius:50%;cursor:pointer;border:3px solid transparent;transition:all .2s;flex-shrink:0;}
.color-swatch:hover{transform:scale(1.2);}
.color-swatch.selected{border-color:#fff;outline:2px solid var(--blue);outline-offset:2px;}
.profile-info-rows{display:flex;flex-direction:column;gap:10px;}
.profile-info-row{display:flex;align-items:center;gap:10px;font-size:.84rem;color:var(--muted);}
.profile-info-row i{width:18px;text-align:center;color:var(--blue);opacity:.8;}
.profile-info-row span{color:var(--text);font-weight:500;}
.pf-section-label{font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.8px;color:var(--muted);margin:18px 0 10px;}
.pf-divider{border:none;border-top:1px solid var(--card-border);margin:18px 0;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:.82rem;font-weight:600;color:#444;margin-bottom:5px;}
body.dark .form-group label{color:#8b949e;}
.form-group input{width:100%;padding:11px 14px;border:1.5px solid var(--input-border);border-radius:9px;font-size:.9rem;outline:none;background:var(--input-bg);color:var(--text);font-family:'Poppins',sans-serif;transition:all .2s;}
.form-group input:focus{border-color:var(--blue-accent);background:var(--input-focus);box-shadow:0 0 0 3px rgba(58,141,255,.1);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.btn-save{background:linear-gradient(135deg,var(--blue-accent),var(--blue));color:#fff;border:none;padding:12px 28px;border-radius:10px;font-size:.93rem;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .25s;}
.btn-save:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(58,141,255,.4);}
.pf-msg{padding:10px 14px;border-radius:9px;font-size:.84rem;margin-bottom:14px;display:none;}
.pf-msg.success{background:#e8f5e9;color:#2e7d32;}.pf-msg.error{background:#ffebee;color:#c62828;}
.gps-section{border:1.5px solid var(--border);border-radius:12px;padding:16px;background:var(--input-bg);}
body.dark .gps-section{background:#0d1117;}
.gps-section-hdr{display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:700;color:var(--blue);margin-bottom:10px;}
.gps-coords{font-size:.79rem;color:var(--muted);margin-bottom:10px;padding:8px 12px;background:var(--bg);border-radius:8px;display:flex;align-items:center;gap:6px;min-height:34px;}
.gps-coords.has-gps{color:var(--green);font-weight:600;}
.btn-gps{display:inline-flex;align-items:center;gap:7px;padding:9px 16px;border:1.5px solid var(--green);border-radius:9px;font-size:.84rem;font-weight:700;color:var(--green);background:#f0fff4;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
.btn-gps:hover{background:#dcfce7;transform:translateY(-1px);}
.btn-gps:disabled{opacity:.6;cursor:not-allowed;transform:none;}
body.dark .btn-gps{background:#1a2e24;}
.gps-help{font-size:.76rem;color:var(--muted);margin-bottom:10px;line-height:1.6;}

/* ── Modals ── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.55);z-index:1200;justify-content:center;align-items:center;padding:20px;backdrop-filter:blur(3px);}
.modal-overlay.open{display:flex;animation:fadeIn .25s ease;}
.modal{background:var(--card);border-radius:20px;padding:32px;width:100%;max-width:580px;max-height:92vh;overflow-y:auto;position:relative;animation:scaleIn .3s cubic-bezier(.34,1.56,.64,1);transition:background .3s;}
.modal h2{font-size:1.2rem;font-weight:800;color:var(--text);margin-bottom:4px;}
.modal .subtitle{font-size:.84rem;color:var(--muted);margin-bottom:22px;}
.modal-close{position:absolute;top:16px;right:18px;background:var(--bg);border:none;font-size:1rem;color:var(--muted);cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all .2s;}
.modal-close:hover{background:#e0e0e0;color:var(--text);}
.modal .form-group input,.modal .form-group textarea,.modal .form-group select{width:100%;padding:11px 14px;border:1.5px solid var(--input-border);border-radius:9px;font-size:.9rem;outline:none;font-family:'Poppins',sans-serif;resize:vertical;background:var(--input-bg);color:var(--text);transition:.2s;}
.modal .form-group input:focus,.modal .form-group textarea:focus,.modal .form-group select:focus{border-color:var(--blue-accent);background:var(--input-focus);box-shadow:0 0 0 3px rgba(58,141,255,.1);}
.modal-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.status-grid{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.status-opt{border:2px solid var(--input-border);border-radius:12px;padding:14px 8px;text-align:center;cursor:pointer;transition:all .2s;background:var(--input-bg);}
.status-opt:hover{border-color:var(--blue-accent);transform:translateY(-2px);}
.status-opt.selected.dangerous{border-color:var(--red);background:#fff0f0;}.status-opt.selected.caution{border-color:var(--orange);background:#fff8f0;}.status-opt.selected.safe{border-color:var(--green);background:#f0fff4;}
body.dark .status-opt.selected.dangerous{background:#3d1f1f;}body.dark .status-opt.selected.caution{background:#2e2010;}body.dark .status-opt.selected.safe{background:#1a2e24;}
.status-opt i{display:block;font-size:1.4rem;margin-bottom:6px;}
.status-opt.dangerous i{color:var(--red);}.status-opt.caution i{color:var(--orange);}.status-opt.safe i{color:var(--green);}
.status-opt span{font-size:.82rem;font-weight:700;color:var(--text);}
.modal-actions{display:flex;gap:12px;margin-top:22px;}
.btn-submit{flex:1;background:linear-gradient(135deg,var(--blue-accent),var(--blue));color:#fff;border:none;padding:13px;border-radius:10px;font-size:.95rem;font-weight:700;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .25s;}
.btn-submit:hover{transform:translateY(-1px);box-shadow:0 6px 18px rgba(58,141,255,.4);}
.btn-submit:disabled{opacity:.6;cursor:not-allowed;transform:none;}
.btn-cancel{padding:13px 22px;border:1.5px solid var(--input-border);background:var(--card);border-radius:10px;font-size:.95rem;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:500;color:var(--text);transition:all .2s;}
.btn-cancel:hover{background:var(--bg);}
body.dark .btn-cancel{background:#21262d;border-color:#30363d;color:var(--muted);}
.modal-msg{padding:10px 14px;border-radius:9px;font-size:.84rem;margin-bottom:14px;display:none;}
.modal-msg.success{background:#e8f5e9;color:#2e7d32;}.modal-msg.error{background:#ffebee;color:#c62828;}
.picker-section{border:1.5px solid var(--input-border);border-radius:12px;overflow:hidden;margin-bottom:6px;background:#f8faff;}
body.dark .picker-section{background:#0d1117;}
.picker-header{padding:10px 14px;background:linear-gradient(90deg,#ebf2ff,#f8faff);display:flex;align-items:center;gap:8px;font-size:.82rem;font-weight:600;color:var(--blue);border-bottom:1px solid var(--input-border);}
body.dark .picker-header{background:linear-gradient(90deg,#1f3a5f,#0d1117);color:var(--blue-accent);}
#pickerMap{height:220px;background:#dde8f0;}
.picker-toolbar{padding:10px 14px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;background:var(--card);border-top:1px solid var(--card-border);}
.locate-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border:1.5px solid var(--blue-accent);border-radius:8px;font-size:.79rem;font-weight:600;color:var(--blue);background:#f0f6ff;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
.locate-btn:hover{background:#dbeafe;}
.clear-pin-btn{display:inline-flex;align-items:center;gap:5px;padding:7px 12px;border:1.5px solid var(--input-border);border-radius:8px;font-size:.79rem;font-weight:600;color:#888;background:var(--card);cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
.clear-pin-btn:hover{border-color:var(--red);color:var(--red);}
.pin-status{font-size:.76rem;color:var(--muted);margin-left:auto;display:flex;align-items:center;gap:5px;}
.pin-status.set{color:var(--green);font-weight:600;}
.radius-row{padding:10px 14px 12px;background:var(--card);display:flex;align-items:center;gap:10px;border-top:1px solid var(--card-border);}
.radius-row label{font-size:.79rem;font-weight:600;color:#555;white-space:nowrap;flex-shrink:0;}
body.dark .radius-row label{color:#8b949e;}
.radius-row input[type=range]{flex:1;accent-color:var(--blue);height:4px;}
.radius-val{font-size:.82rem;font-weight:700;color:var(--blue);min-width:60px;text-align:right;}
.photo-upload-area{border:2px dashed var(--input-border);border-radius:10px;padding:18px;text-align:center;cursor:pointer;color:var(--muted);font-size:.84rem;transition:border-color .2s;line-height:1.7;}
.photo-upload-area:hover{border-color:var(--blue-accent);}
.photo-thumb{position:relative;width:72px;height:72px;border-radius:8px;overflow:hidden;border:1.5px solid var(--input-border);flex-shrink:0;}
.photo-thumb img{width:100%;height:100%;object-fit:cover;}
.photo-thumb .remove-photo{position:absolute;top:2px;right:2px;background:rgba(0,0,0,.65);border:none;color:#fff;border-radius:50%;width:18px;height:18px;font-size:.65rem;cursor:pointer;display:flex;align-items:center;justify-content:center;}

/* ── Report Detail ── */
.detail-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.62);z-index:1300;justify-content:center;align-items:center;padding:20px;backdrop-filter:blur(4px);}
.detail-overlay.open{display:flex;animation:fadeIn .22s ease;}
.detail-modal{background:var(--card);border-radius:22px;width:100%;max-width:720px;max-height:92vh;overflow-y:auto;position:relative;animation:scaleIn .28s cubic-bezier(.34,1.56,.64,1);display:flex;flex-direction:column;}
.detail-close{position:absolute;top:16px;right:18px;background:var(--bg);border:none;font-size:1rem;color:var(--muted);cursor:pointer;width:32px;height:32px;border-radius:9px;display:flex;align-items:center;justify-content:center;transition:all .2s;z-index:2;}
.detail-close:hover{background:#e0e0e0;}
body.dark .detail-close:hover{background:#30363d;}
.detail-header{padding:22px 24px 0;}
.detail-status-bar{display:flex;align-items:center;gap:10px;margin-bottom:10px;}
.detail-badge{padding:5px 14px;border-radius:50px;font-size:.76rem;font-weight:700;text-transform:uppercase;color:#fff;}
.detail-badge.dangerous{background:var(--red);}.detail-badge.caution{background:var(--orange);}.detail-badge.safe{background:var(--green);}
.detail-cat-tag{font-size:.76rem;background:var(--bg);padding:5px 12px;border-radius:8px;color:var(--muted);font-weight:600;}
.detail-title{font-size:1.18rem;font-weight:800;color:var(--text);line-height:1.4;margin-bottom:4px;padding-right:40px;}
.detail-reporter{font-size:.79rem;color:var(--muted);margin-bottom:18px;}
.detail-reporter span{font-weight:600;color:var(--text);}
.detail-photos{display:flex;gap:10px;overflow-x:auto;padding:0 24px 16px;}
.detail-photo{flex-shrink:0;width:220px;height:155px;border-radius:13px;overflow:hidden;border:2px solid var(--card-border);cursor:pointer;transition:transform .2s;}
.detail-photo:hover{transform:scale(1.03);}
.detail-photo img{width:100%;height:100%;object-fit:cover;display:block;}
.detail-body{padding:4px 24px 24px;display:flex;flex-direction:column;gap:16px;}
.detail-meta-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.detail-meta-item{background:var(--bg);border-radius:11px;padding:12px 14px;}
.detail-meta-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:4px;}
.detail-meta-value{font-size:.87rem;font-weight:600;color:var(--text);display:flex;align-items:center;gap:6px;}
.detail-desc-box{background:var(--bg);border-radius:11px;padding:14px 16px;}
.detail-desc-label{font-size:.7rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:var(--muted);margin-bottom:8px;}
.detail-desc-text{font-size:.88rem;color:var(--text);line-height:1.8;}
.detail-footer{display:flex;align-items:center;gap:10px;flex-wrap:wrap;padding:16px 24px;border-top:1px solid var(--card-border);}
.detail-map-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;border:1.5px solid var(--blue-accent);border-radius:10px;font-size:.84rem;font-weight:700;color:var(--blue);background:#f0f6ff;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
.detail-map-btn:hover{background:#dbeafe;transform:translateY(-1px);}
body.dark .detail-map-btn{background:#1f3a5f;color:var(--blue-accent);border-color:var(--blue);}

/* ── Mini Map ── */
.mini-map-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:1400;justify-content:center;align-items:center;padding:20px;backdrop-filter:blur(3px);}
.mini-map-modal.open{display:flex;animation:fadeIn .2s ease;}
.mini-map-box{background:var(--card);border-radius:18px;width:100%;max-width:640px;overflow:hidden;animation:scaleIn .25s cubic-bezier(.34,1.56,.64,1);}
.mini-map-header{padding:14px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--card-border);}
.mini-map-header h4{font-size:.95rem;font-weight:700;color:var(--text);}
.mini-map-close{background:var(--bg);border:none;font-size:.95rem;color:var(--muted);cursor:pointer;width:30px;height:30px;border-radius:8px;display:flex;align-items:center;justify-content:center;transition:all .2s;}
#miniMap{height:340px;width:100%;}
.mini-map-footer{padding:12px 18px;font-size:.79rem;color:var(--muted);background:var(--bg);display:flex;gap:16px;flex-wrap:wrap;}

/* ── Toast ── */
#toastContainer{position:fixed;bottom:24px;right:24px;z-index:9999;display:flex;flex-direction:column;gap:10px;pointer-events:none;max-width:340px;}
.toast{background:var(--card);border-radius:14px;padding:14px 16px;box-shadow:0 8px 32px rgba(0,0,0,.15);border-left:5px solid #ccc;display:flex;gap:12px;align-items:flex-start;animation:toastSlide .4s cubic-bezier(.34,1.56,.64,1) both;pointer-events:auto;position:relative;overflow:hidden;}
.toast.fade-out{animation:toastFade .4s ease both;}
.toast.dangerous{border-left-color:var(--red);}.toast.caution{border-left-color:var(--orange);}.toast.safe{border-left-color:var(--green);}
.toast-icon{font-size:1.3rem;flex-shrink:0;margin-top:2px;}
.toast.dangerous .toast-icon{color:var(--red);}.toast.caution .toast-icon{color:var(--orange);}.toast.safe .toast-icon{color:var(--green);}
.toast-title{font-size:.83rem;font-weight:700;color:var(--text);margin-bottom:3px;}
.toast-body{font-size:.76rem;color:var(--muted);line-height:1.55;}
.toast-close{position:absolute;top:8px;right:8px;background:none;border:none;cursor:pointer;color:var(--muted);font-size:.78rem;padding:2px 5px;border-radius:5px;}
.toast-progress{position:absolute;bottom:0;left:0;height:3px;background:rgba(0,0,0,.1);animation:toastProgress 5s linear both;}

/* ── Lightbox ── */
.lightbox{display:none;position:fixed;inset:0;background:rgba(0,0,0,.92);z-index:1500;justify-content:center;align-items:center;cursor:zoom-out;}
.lightbox.open{display:flex;animation:fadeIn .18s ease;}
.lightbox img{max-width:90vw;max-height:90vh;border-radius:12px;object-fit:contain;}
.lightbox-close{position:absolute;top:18px;right:22px;background:rgba(255,255,255,.12);border:none;color:#fff;font-size:1.3rem;cursor:pointer;width:42px;height:42px;border-radius:12px;display:flex;align-items:center;justify-content:center;}

/* ── Animations ── */
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
@keyframes scaleIn{from{opacity:0;transform:scale(.97);}to{opacity:1;transform:scale(1);}}
@keyframes cardEntrance{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
@keyframes spin{to{transform:rotate(360deg);}}
@keyframes toastSlide{from{opacity:0;transform:translateX(120%);}to{opacity:1;transform:translateX(0);}}
@keyframes toastFade{from{opacity:1;transform:translateX(0);}to{opacity:0;transform:translateX(120%);}}
@keyframes toastProgress{from{width:100%;}to{width:0%;}}

/* ── Responsive ── */
@media(max-width:900px){
  .sidebar{transform:translateX(calc(-1*var(--sidebar-w)));}.sidebar.mobile-open{transform:translateX(0);}
  .main{margin-left:0;}.ham-btn{display:flex;}.stats-row{grid-template-columns:1fr 1fr;}.content{padding:16px;}
  .user-name{display:none;}.detail-meta-grid{grid-template-columns:1fr;}.profile-grid{grid-template-columns:1fr;}
  #incidentMap{height:420px;}
}
@media(max-width:600px){
  .stats-row{grid-template-columns:1fr 1fr;}.post-btn span{display:none;}.post-btn{padding:10px 13px;}
  .modal-row,.form-row{grid-template-columns:1fr;}.contacts-grid{grid-template-columns:1fr;}.profile-grid{grid-template-columns:1fr;}
}

/* ── Quick Report Form ── */
.qs-label{font-size:.79rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.6px;margin-bottom:10px;display:flex;align-items:center;gap:8px;}
.qs-num{background:var(--blue);color:#fff;width:20px;height:20px;border-radius:50%;display:inline-flex;align-items:center;justify-content:center;font-size:.72rem;font-weight:800;flex-shrink:0;}
.form-section{margin-bottom:16px;padding-bottom:16px;border-bottom:1px solid var(--border);}
.form-section:last-of-type{border-bottom:none;padding-bottom:0;}
.severity-row{display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;}
.sev-btn{border:2.5px solid var(--input-border);border-radius:14px;padding:18px 6px;text-align:center;cursor:pointer;transition:all .2s;background:var(--input-bg);display:flex;flex-direction:column;align-items:center;gap:7px;-webkit-tap-highlight-color:transparent;user-select:none;}
.sev-btn:hover{transform:translateY(-2px);}
.sev-btn i{font-size:1.7rem;}
.sev-btn span{font-size:.8rem;font-weight:700;color:var(--text);}
.sev-btn.dangerous i{color:var(--red);}.sev-btn.caution i{color:var(--orange);}.sev-btn.safe i{color:var(--green);}
.sev-btn.dangerous.selected{border-color:var(--red);background:#fff0f0;box-shadow:0 0 0 3px rgba(229,62,62,.15);}
.sev-btn.caution.selected{border-color:var(--orange);background:#fff8f0;box-shadow:0 0 0 3px rgba(221,107,32,.15);}
.sev-btn.safe.selected{border-color:var(--green);background:#f0fff4;box-shadow:0 0 0 3px rgba(56,161,105,.15);}
body.dark .sev-btn.dangerous.selected{background:#3d1f1f;}body.dark .sev-btn.caution.selected{background:#2e2010;}body.dark .sev-btn.safe.selected{background:#1a2e24;}
.cat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:8px;}
.cat-opt{border:2px solid var(--input-border);border-radius:11px;padding:11px 5px;text-align:center;cursor:pointer;transition:all .2s;background:var(--input-bg);display:flex;flex-direction:column;align-items:center;gap:5px;-webkit-tap-highlight-color:transparent;user-select:none;}
.cat-opt:hover{border-color:var(--blue-accent);transform:translateY(-1px);}
.cat-opt i{font-size:1.2rem;color:var(--blue-accent);}
.cat-opt span{font-size:.7rem;font-weight:600;color:var(--text);}
.cat-opt.selected{border-color:var(--blue);background:#ebf2ff;box-shadow:0 0 0 2px rgba(58,141,255,.15);}
body.dark .cat-opt.selected{background:#1f3a5f;}
.loc-auto-row{display:flex;align-items:center;gap:10px;flex-wrap:wrap;margin-bottom:10px;}
.locate-btn-big{display:inline-flex;align-items:center;gap:8px;padding:10px 16px;border:2px solid var(--blue-accent);border-radius:10px;font-size:.88rem;font-weight:700;color:var(--blue);background:#f0f6ff;cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
.locate-btn-big:hover{background:#dbeafe;transform:translateY(-1px);}
.locate-btn-big:disabled{opacity:.6;cursor:not-allowed;transform:none;}
body.dark .locate-btn-big{background:#1f3a5f;color:var(--blue-accent);}
.loc-details-toggle{margin-top:10px;font-size:.81rem;font-weight:600;color:var(--blue-accent);cursor:pointer;display:flex;align-items:center;gap:6px;user-select:none;}
.loc-details-toggle:hover{color:var(--blue);}
.loc-details-toggle i{transition:transform .25s;}
.loc-details-panel{flex-direction:column;gap:8px;}
.map-pick-btn{margin-top:4px;display:inline-flex;align-items:center;gap:6px;padding:8px 14px;border:1.5px solid var(--input-border);border-radius:9px;font-size:.81rem;font-weight:600;color:var(--muted);background:var(--card);cursor:pointer;font-family:'Poppins',sans-serif;transition:all .2s;}
.map-pick-btn:hover{border-color:var(--blue-accent);color:var(--blue);}
.optional-section{background:var(--bg);border-radius:12px;padding:12px 14px;margin-bottom:16px;}
.opt-toggle{font-size:.81rem;font-weight:600;color:var(--muted);cursor:pointer;display:flex;align-items:center;gap:6px;user-select:none;}
.opt-toggle i{transition:transform .25s;}
.opt-toggle:hover{color:var(--text);}
.opt-panel{flex-direction:column;gap:10px;}
.form-section input[type=text],.form-section textarea{width:100%;padding:11px 14px;border:1.5px solid var(--input-border);border-radius:9px;font-size:.9rem;outline:none;font-family:'Poppins',sans-serif;resize:vertical;background:var(--input-bg);color:var(--text);transition:.2s;box-sizing:border-box;}
.form-section input[type=text]:focus,.form-section textarea:focus{border-color:var(--blue-accent);background:var(--input-focus);box-shadow:0 0 0 3px rgba(58,141,255,.1);}
</style>
</head>
<body>
<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>
<div id="toastContainer"></div>

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="brand-logo">
      <div class="brand-icon-s" style="background:rgba(243,156,18,.2);border-color:rgba(243,156,18,.4);"><i class="fas fa-shield-halved" style="color:#f39c12;"></i></div>
      <div><div class="brand-name-s">SenTri</div><div class="brand-sub">Community Portal</div></div>
    </div>
    <button class="toggle-btn" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
  </div>
  <nav class="menu">
    <?php foreach($nav as $key=>$item): ?>
    <a href="community.php?view=<?= $key ?>" <?= $view===$key?'class="active"':'' ?>>
      <i class="fas <?= $item['icon'] ?>"></i> <?= $item['label'] ?>
      <?php if($key==='my_reports'&&$my_count>0): ?><span class="menu-badge"><?= $my_count ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
  <div class="sidebar-stats">
    <div class="stat-label-s">Community Overview</div>
    <div class="stat-box"><div class="num"><?= $total_reports ?></div><div class="lbl">Active Reports</div></div>
    <div class="stat-box danger-box"><div class="num"><?= $danger_count ?></div><div class="lbl">Dangerous Areas</div></div>
    <div class="stat-box safe-box"><div class="num"><?= $safe_count ?></div><div class="lbl">Safe Areas</div></div>
  </div>
  <div class="sidebar-footer">
    <div class="dm-row" onclick="toggleDark()">
      <span class="dm-row-label"><i class="fas fa-moon"></i> Dark Mode</span>
      <label class="dm-switch" onclick="event.stopPropagation()"><input type="checkbox" id="dmCheck" onchange="toggleDark()"><span class="dm-slider"></span></label>
    </div>
    <a href="../logout.php"><i class="fas fa-right-from-bracket"></i> Log Out</a>
  </div>
</aside>

<!-- ═══ MAIN ═══ -->
<div class="main" id="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <h1><i class="fas <?= $nav[$view]['icon']??'fa-house' ?>" style="color:var(--blue-accent);margin-right:8px;"></i><?= htmlspecialchars($page_titles[$view]??'Community Portal') ?></h1>
    </div>
    <div class="right-top">
      <?php if(in_array($view,['overview','my_reports','map'])): ?>
      <div class="gps-chip" id="gpsChip"><span class="gps-dot" id="gpsDot"></span><span id="gpsLabel">GPS Off</span></div>
      <button class="post-btn" onclick="openModal()"><i class="fas fa-plus"></i> <span>Post Report</span></button>
      <?php endif; ?>
      <div class="user-info" onclick="window.location='community.php?view=profile'" title="My Profile">
        <div class="avatar" id="topAvatar" style="background:<?= htmlspecialchars($avatar_color) ?>"><?= strtoupper(substr($fname,0,1).substr($lname,0,1)) ?></div>
        <span class="user-name"><?= htmlspecialchars($fname) ?></span>
      </div>
    </div>
  </div>

  <div class="content">

  <?php if($view==='overview'): ?>
    <div class="stats-row">
      <div class="stat-card"><div class="stat-icon blue"><i class="fas fa-clipboard-list"></i></div><div><strong><?= $total_reports ?></strong><span>Total Reports</span></div></div>
      <div class="stat-card"><div class="stat-icon red"><i class="fas fa-circle-exclamation"></i></div><div><strong><?= $danger_count ?></strong><span>Dangerous Areas</span></div></div>
      <div class="stat-card"><div class="stat-icon green"><i class="fas fa-circle-check"></i></div><div><strong><?= $safe_count ?></strong><span>Safe Areas</span></div></div>
      <div class="stat-card"><div class="stat-icon orange"><i class="fas fa-pen-to-square"></i></div><div><strong><?= $my_count ?></strong><span>My Reports</span></div></div>
    </div>
    <div class="filters">
      <input type="text" id="searchInput" placeholder="Search location, keyword...">
      <select id="statusFilter"><option value="">All Statuses</option><option value="dangerous">🔴 Dangerous</option><option value="caution">🟠 Caution</option><option value="safe">🟢 Safe</option></select>
      <select id="categoryFilter"><option value="">All Categories</option><option value="crime">Crime</option><option value="accident">Accident</option><option value="flooding">Flooding</option><option value="fire">Fire</option><option value="health">Health</option><option value="infrastructure">Infrastructure</option><option value="other">Other</option></select>
      <button onclick="resetFilters()"><i class="fas fa-rotate"></i> Reset</button>
      <div class="view-toggle">
        <button class="view-btn active" id="btnFeed" onclick="switchView('feed')"><i class="fas fa-list"></i> Feed</button>
        <button class="view-btn" id="btnMap" onclick="switchView('map')"><i class="fas fa-map"></i> Map</button>
      </div>
    </div>
    <div id="inlineMapWrap" style="display:none;border-radius:16px;overflow:hidden;box-shadow:0 4px 20px rgba(0,0,0,.1);margin-bottom:22px;isolation:isolate;">
      <div id="inlineMap" style="height:480px;background:#dde8f0;"></div>
      <div class="map-legend">
        <div class="legend-item"><div class="legend-dot dangerous"></div>Dangerous</div>
        <div class="legend-item"><div class="legend-dot caution"></div>Caution</div>
        <div class="legend-item"><div class="legend-dot safe"></div>Safe</div>
        <span class="map-count" id="inlineMapCount"></span>
      </div>
    </div>
    <div id="feed"><div class="loading"><i class="fas fa-spinner"></i> Loading community reports…</div></div>

  <?php elseif($view==='my_reports'): ?>
    <div class="my-reports-hdr">
      <div class="mh-icon"><i class="fas fa-file-lines"></i></div>
      <div><h2>My Reports</h2><p>All <?= $my_count ?> report<?= $my_count!==1?'s':'' ?> you've submitted</p></div>
      <button class="post-btn" onclick="openModal()" style="margin-left:auto;"><i class="fas fa-plus"></i> <span>New Report</span></button>
    </div>
    <div class="filters">
      <input type="text" id="searchInput" placeholder="Search my reports...">
      <select id="statusFilter"><option value="">All Statuses</option><option value="dangerous">🔴 Dangerous</option><option value="caution">🟠 Caution</option><option value="safe">🟢 Safe</option></select>
      <select id="categoryFilter"><option value="">All Categories</option><option value="crime">Crime</option><option value="accident">Accident</option><option value="flooding">Flooding</option><option value="fire">Fire</option><option value="health">Health</option><option value="infrastructure">Infrastructure</option><option value="other">Other</option></select>
      <button onclick="resetFilters()"><i class="fas fa-rotate"></i> Reset</button>
    </div>
    <div id="feed"><div class="loading"><i class="fas fa-spinner"></i> Loading your reports…</div></div>

  <?php elseif($view==='map'): ?>
    <div id="mapWrap">
      <div id="incidentMap"></div>
      <div class="map-legend">
        <div class="legend-item"><div class="legend-dot dangerous"></div>Dangerous</div>
        <div class="legend-item"><div class="legend-dot caution"></div>Caution</div>
        <div class="legend-item"><div class="legend-dot safe"></div>Safe</div>
        <span class="map-count" id="mapCount"></span>
      </div>
    </div>

  <?php elseif($view==='contacts'): ?>
    <div class="contacts-hdr">
      <div class="ch-icon"><i class="fas fa-phone-volume"></i></div>
      <div><h2>Emergency Contacts</h2><p>Local emergency services, hospitals, and responder hotlines</p></div>
    </div>
    <?php if(empty($contacts)): ?>
      <div class="empty"><i class="fas fa-address-book"></i><p>No emergency contacts set up yet.<br>Contact your local administrator.</p></div>
    <?php else:
      $grouped=[];foreach($contacts as $c)$grouped[$c['type']][]=$c;
      foreach($type_labels as $type=>$label):if(empty($grouped[$type]))continue;[$bg,$fg]=$type_colors[$type]; ?>
      <div style="margin-bottom:24px;">
        <div style="display:flex;align-items:center;gap:10px;margin-bottom:12px;">
          <div style="width:34px;height:34px;border-radius:9px;background:<?= $bg ?>;display:flex;align-items:center;justify-content:center;color:<?= $fg ?>;font-size:.95rem;flex-shrink:0;"><i class="fas <?= $type_icons[$type] ?>"></i></div>
          <span style="font-size:.95rem;font-weight:700;color:var(--text);"><?= $label ?></span>
          <span style="font-size:.78rem;color:var(--muted);">(<?= count($grouped[$type]) ?>)</span>
        </div>
        <div class="contacts-grid">
          <?php foreach($grouped[$type] as $i=>$c): ?>
          <div class="contact-card" style="border-left-color:<?= $fg ?>;animation-delay:<?= $i*.05 ?>s;">
            <div class="contact-top">
              <div class="contact-icon" style="background:<?= $bg ?>;color:<?= $fg ?>;"><i class="fas <?= $type_icons[$c['type']] ?>"></i></div>
              <div><div class="contact-name"><?= htmlspecialchars($c['name']) ?></div><div class="contact-type-lbl" style="color:<?= $fg ?>;"><?= strtoupper(htmlspecialchars($c['type'])) ?></div></div>
            </div>
            <div class="contact-details">
              <div class="contact-row"><i class="fas fa-city"></i><?= htmlspecialchars($c['city']).(isset($c['province'])&&$c['province']?', '.htmlspecialchars($c['province']):'') ?></div>
              <?php if(!empty($c['contact_number'])): ?><div class="contact-row"><i class="fas fa-phone"></i><a href="tel:<?= htmlspecialchars($c['contact_number']) ?>"><?= htmlspecialchars($c['contact_number']) ?></a></div><?php endif; ?>
              <?php if(!empty($c['contact_email'])): ?><div class="contact-row"><i class="fas fa-envelope"></i><a href="mailto:<?= htmlspecialchars($c['contact_email']) ?>"><?= htmlspecialchars($c['contact_email']) ?></a></div><?php endif; ?>
            </div>
          </div>
          <?php endforeach; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>

  <?php elseif($view==='profile'):
    [$pm_type,$pm_text]=$profile_msg?explode(':',$profile_msg,2):['','']; ?>
    <div class="profile-grid">
      <div class="profile-card">
        <div class="profile-avatar-section">
          <div class="profile-avatar-big" id="avatarPreview" style="background:<?= htmlspecialchars($avatar_color) ?>"><?= strtoupper(substr($fname,0,1).substr($lname,0,1)) ?></div>
          <div style="text-align:center;"><div class="profile-name-big"><?= htmlspecialchars($fname.' '.$lname) ?></div><div style="margin-top:5px;"><span class="role-badge"><i class="fas fa-users" style="margin-right:4px;"></i>Community Member</span></div></div>
          <div style="width:100%;"><p style="font-size:.75rem;color:var(--muted);text-align:center;margin-bottom:8px;">Choose avatar color</p><div class="color-palette" id="colorPalette"></div></div>
        </div>
        <div class="profile-info-rows">
          <div class="profile-info-row"><i class="fas fa-envelope"></i><span><?= htmlspecialchars($user_email) ?></span></div>
          <?php if($user_phone): ?><div class="profile-info-row"><i class="fas fa-phone"></i><span><?= htmlspecialchars($user_phone) ?></span></div><?php endif; ?>
          <?php if($user_brgy): ?><div class="profile-info-row"><i class="fas fa-map-pin"></i><span><?= htmlspecialchars($user_brgy) ?></span></div><?php endif; ?>
          <?php if($user_muni): ?><div class="profile-info-row"><i class="fas fa-city"></i><span><?= htmlspecialchars($user_muni) ?></span></div><?php endif; ?>
        </div>
        <div class="pf-divider"></div>
        <div class="pf-section-label"><i class="fas fa-satellite-dish" style="margin-right:5px;color:var(--green);"></i>GPS Location</div>
        <div class="gps-section">
          <div class="gps-section-hdr"><i class="fas fa-crosshairs"></i>Saved Location</div>
          <div class="gps-coords <?= !is_null($saved_gps_lat)?'has-gps':'' ?>" id="gpsCoordsDisplay">
            <?php if(!is_null($saved_gps_lat)): ?><i class="fas fa-circle-check"></i> <?= number_format((float)$saved_gps_lat,6) ?>, <?= number_format((float)$saved_gps_lng,6) ?><?php else: ?><i class="fas fa-circle-info"></i> No GPS saved yet<?php endif; ?>
          </div>
          <p class="gps-help">Unlocks the <strong>Incidents Map</strong> and proximity alerts. Not shared publicly.</p>
          <button class="btn-gps" id="getGpsBtn" onclick="saveGPS()"><i class="fas fa-location-crosshairs"></i> Get GPS Data</button>
        </div>
      </div>
      <div class="profile-card">
        <?php if($pm_text): ?><div class="pf-msg <?= htmlspecialchars($pm_type) ?>" style="display:block;"><?= htmlspecialchars($pm_text) ?></div><?php endif; ?>
        <div id="pfMsg" class="pf-msg"></div>
        <form method="post" action="community.php?view=profile">
          <input type="hidden" name="save_profile" value="1">
          <div class="pf-section-label">Personal Information</div>
          <div class="form-row">
            <div class="form-group"><label>First Name</label><input name="first_name" value="<?= htmlspecialchars($fname) ?>" maxlength="100" required></div>
            <div class="form-group"><label>Last Name</label><input name="last_name" value="<?= htmlspecialchars($lname) ?>" maxlength="100" required></div>
          </div>
          <div class="form-group"><label>Phone Number</label><input name="phone" value="<?= htmlspecialchars($user_phone) ?>" placeholder="e.g. 09XX-XXX-XXXX" maxlength="30"></div>
          <div class="form-row">
            <div class="form-group"><label>Barangay</label><input name="barangay_name" value="<?= htmlspecialchars($user_brgy) ?>" placeholder="Your barangay" maxlength="150"></div>
            <div class="form-group"><label>City / Municipality</label><input name="municipality" value="<?= htmlspecialchars($user_muni) ?>" placeholder="e.g. Imus, Cavite" maxlength="150"></div>
          </div>
          <div class="pf-divider"></div>
          <div class="pf-section-label">Change Password <span style="font-weight:400;text-transform:none;letter-spacing:0;font-size:.72rem;">(leave blank to keep current)</span></div>
          <div class="form-row">
            <div class="form-group"><label>New Password</label><input type="password" name="new_password" placeholder="Min. 8 characters"></div>
            <div class="form-group"><label>Confirm Password</label><input type="password" name="confirm_password" placeholder="Repeat new password"></div>
          </div>
          <button type="submit" class="btn-save"><i class="fas fa-floppy-disk"></i> Save Changes</button>
        </form>
      </div>
    </div>
  <?php endif; ?>

  </div>
</div>

<!-- ═══ POST REPORT MODAL ═══ -->
<div class="modal-overlay" id="modalOverlay" onclick="outsideClose(event)">
  <div class="modal">
    <button class="modal-close" onclick="closeModal()"><i class="fas fa-xmark"></i></button>
    <h2><i class="fas fa-triangle-exclamation" style="color:var(--red);margin-right:8px;"></i>Report an Incident</h2>
    <p class="subtitle">Quick — takes under 30 seconds</p>
    <div id="modalMsg" class="modal-msg"></div>
    <form id="reportForm" novalidate>

      <!-- 1 · Severity -->
      <div class="form-section">
        <div class="qs-label"><span class="qs-num">1</span>How serious is it?</div>
        <div class="severity-row">
          <div class="sev-btn dangerous" onclick="selectStatus('dangerous')" id="opt_dangerous"><i class="fas fa-circle-exclamation"></i><span>Dangerous</span></div>
          <div class="sev-btn caution"   onclick="selectStatus('caution')"   id="opt_caution"><i class="fas fa-triangle-exclamation"></i><span>Caution</span></div>
          <div class="sev-btn safe"      onclick="selectStatus('safe')"      id="opt_safe"><i class="fas fa-circle-check"></i><span>Safe</span></div>
        </div>
        <input type="hidden" id="r_status">
      </div>

      <!-- 2 · Category -->
      <div class="form-section">
        <div class="qs-label"><span class="qs-num">2</span>What type of incident?</div>
        <div class="cat-grid">
          <div class="cat-opt" onclick="selectCategory('crime')"          id="cat_crime"><i class="fas fa-user-shield"></i><span>Crime</span></div>
          <div class="cat-opt" onclick="selectCategory('accident')"       id="cat_accident"><i class="fas fa-car-burst"></i><span>Accident</span></div>
          <div class="cat-opt" onclick="selectCategory('flooding')"       id="cat_flooding"><i class="fas fa-water"></i><span>Flooding</span></div>
          <div class="cat-opt" onclick="selectCategory('fire')"           id="cat_fire"><i class="fas fa-fire"></i><span>Fire</span></div>
          <div class="cat-opt" onclick="selectCategory('health')"         id="cat_health"><i class="fas fa-heart-pulse"></i><span>Health</span></div>
          <div class="cat-opt" onclick="selectCategory('infrastructure')" id="cat_infrastructure"><i class="fas fa-road"></i><span>Road</span></div>
          <div class="cat-opt" onclick="selectCategory('other')"          id="cat_other"><i class="fas fa-circle-info"></i><span>Other</span></div>
        </div>
        <input type="hidden" id="r_category">
      </div>

      <!-- 3 · Description -->
      <div class="form-section">
        <div class="qs-label"><span class="qs-num">3</span>What's happening?</div>
        <textarea id="r_description" placeholder="Briefly describe the situation…" rows="3" maxlength="2000"></textarea>
      </div>

      <!-- 4 · Where -->
      <div class="form-section">
        <div class="qs-label"><span class="qs-num">4</span>Where?</div>
        <div class="loc-auto-row">
          <button type="button" class="locate-btn-big" id="locateBtn" onclick="useMyLocation()"><i class="fas fa-location-crosshairs"></i> Use My Location</button>
          <span class="pin-status" id="pinStatus"><i class="fas fa-circle-info"></i> No pin set</span>
        </div>
        <input type="text" id="r_city" placeholder="City / Municipality *" maxlength="150">
        <div class="loc-details-toggle" onclick="toggleLocDetails()"><i class="fas fa-chevron-right" id="locChevron"></i> Add specific address</div>
        <div id="locDetails" class="loc-details-panel" style="display:none;margin-top:10px;">
          <input type="text" id="r_location" placeholder="Street / Area (e.g. Rizal St. near Market)" maxlength="255">
          <div class="modal-row">
            <input type="text" id="r_barangay" placeholder="Barangay" maxlength="150">
            <input type="text" id="r_province" placeholder="Province" maxlength="150">
          </div>
          <button type="button" class="map-pick-btn" id="mapPickBtn" onclick="toggleMapPicker()"><i class="fas fa-map-pin"></i> Drop a Pin on Map</button>
          <div id="mapPickerSection" style="display:none;" class="picker-section">
            <div class="picker-header"><i class="fas fa-crosshairs"></i> Click to drop a pin · Drag to move · Scroll to zoom</div>
            <div id="pickerMap"></div>
            <div class="picker-toolbar">
              <button type="button" class="clear-pin-btn" id="clearPinBtn" onclick="clearPin()" style="display:none;"><i class="fas fa-xmark"></i> Clear pin</button>
              <span class="pin-status" id="pinStatus2" style="margin-left:auto;"></span>
            </div>
            <div class="radius-row">
              <label><i class="fas fa-circle-dot" style="color:var(--blue-accent);margin-right:4px;"></i>Affected radius:</label>
              <input type="range" id="radiusSlider" min="50" max="3000" step="50" value="200" oninput="onRadiusChange(this.value)">
              <span class="radius-val" id="radiusVal">200 m</span>
            </div>
          </div>
        </div>
        <input type="hidden" id="r_latitude"><input type="hidden" id="r_longitude"><input type="hidden" id="r_radius_m" value="200">
      </div>

      <!-- Optional: Title & Photos -->
      <div class="optional-section">
        <div class="opt-toggle" onclick="toggleOptional()"><i class="fas fa-chevron-right" id="optChevron"></i> Title &amp; Photos (optional)</div>
        <div id="optionalDetails" class="opt-panel" style="display:none;margin-top:12px;">
          <input type="text" id="r_title" placeholder="Report title (auto-generated if blank)" maxlength="255" style="width:100%;padding:11px 14px;border:1.5px solid var(--input-border);border-radius:9px;font-size:.9rem;outline:none;font-family:'Poppins',sans-serif;background:var(--input-bg);color:var(--text);transition:.2s;box-sizing:border-box;">
          <div class="photo-upload-area" onclick="document.getElementById('r_photos').click()"><i class="fas fa-camera" style="font-size:1.3rem;color:var(--blue-accent);display:block;margin-bottom:5px;"></i>Attach Photos &middot; up to 3 &middot; max 5 MB each<br><span style="font-size:.75rem;color:var(--muted);">JPG, PNG, WEBP</span></div>
          <input type="file" id="r_photos" accept="image/jpeg,image/png,image/webp" multiple style="display:none;" onchange="onPhotosChosen(this)">
          <div id="photoPreviewRow" style="display:flex;gap:8px;flex-wrap:wrap;"></div>
        </div>
      </div>

      <div class="modal-actions">
        <button type="button" class="btn-cancel" onclick="closeModal()">Cancel</button>
        <button type="submit" class="btn-submit" id="submitBtn"><i class="fas fa-paper-plane"></i> Submit Report</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ REPORT DETAIL ═══ -->
<div class="detail-overlay" id="detailOverlay" onclick="outsideCloseDetail(event)">
  <div class="detail-modal" id="detailModal">
    <button class="detail-close" onclick="closeDetail()"><i class="fas fa-xmark"></i></button>
    <div class="detail-header">
      <div class="detail-status-bar"><span class="detail-badge" id="d_badge"></span><span class="detail-cat-tag" id="d_cat_tag"></span></div>
      <div class="detail-title" id="d_title"></div>
      <div class="detail-reporter" id="d_reporter"></div>
    </div>
    <div class="detail-photos" id="d_photos" style="display:none;"></div>
    <div class="detail-body">
      <div class="detail-meta-grid" id="d_meta_grid"></div>
      <div class="detail-desc-box" id="d_desc_box" style="display:none;"><div class="detail-desc-label"><i class="fas fa-align-left" style="margin-right:5px;"></i>Description</div><div class="detail-desc-text" id="d_desc"></div></div>
    </div>
    <div class="detail-footer" id="d_footer"></div>
  </div>
</div>

<!-- ═══ MINI MAP ═══ -->
<div class="mini-map-modal" id="miniMapModal" onclick="closeMiniMap(event)">
  <div class="mini-map-box">
    <div class="mini-map-header"><h4 id="miniMapTitle">Location</h4><button class="mini-map-close" onclick="closeMiniMapDirect()"><i class="fas fa-xmark"></i></button></div>
    <div id="miniMap"></div>
    <div class="mini-map-footer" id="miniMapFooter"></div>
  </div>
</div>

<!-- ═══ LIGHTBOX ═══ -->
<div class="lightbox" id="lightbox" onclick="closeLightbox()">
  <button class="lightbox-close" onclick="closeLightbox()"><i class="fas fa-xmark"></i></button>
  <img id="lightboxImg" src="" alt="Photo">
</div>

<script src="../assets/vendor/leaflet/leaflet.js"></script>
<script>
const MY_USER_ID  = <?= $uid ?>;
const CURRENT_VIEW= '<?= htmlspecialchars($view) ?>';
const SAVED_LAT   = <?= is_null($saved_gps_lat)?'null':(float)$saved_gps_lat ?>;
const SAVED_LNG   = <?= is_null($saved_gps_lng)?'null':(float)$saved_gps_lng ?>;
const INIT_COLOR  = '<?= htmlspecialchars($avatar_color) ?>';
const DM_KEY      = `sentri_dm_${MY_USER_ID}`;

const SC = {dangerous:'#e53e3e',caution:'#dd6b20',safe:'#38a169'};
const SF = {dangerous:'rgba(229,62,62,.15)',caution:'rgba(221,107,32,.15)',safe:'rgba(56,161,105,.15)'};
const CI = {crime:'fa-user-shield',accident:'fa-car-burst',flooding:'fa-water',fire:'fa-fire',health:'fa-heart-pulse',infrastructure:'fa-road',other:'fa-circle-info'};
const CL = {crime:'Crime',accident:'Accident',flooding:'Flooding',fire:'Fire',health:'Health',infrastructure:'Infrastructure',other:'Other'};
const SWATCHES = ['#1c57b2','#0e3d8c','#3a8dff','#7c3aed','#db2777','#dc2626','#ea580c','#d97706','#16a34a','#0891b2','#374151','#be185d','#0f766e','#7c2d12','#1d4ed8'];

let allReports=[], curView='feed';
let userLat=SAVED_LAT, userLng=SAVED_LNG, hasGPS=SAVED_LAT!==null;
const alertTimes={};

/* Dark mode */
function applyDark(on){document.body.classList.toggle('dark',on);document.getElementById('dmCheck').checked=on;}
function toggleDark(){const d=document.body.classList.toggle('dark');document.getElementById('dmCheck').checked=d;localStorage.setItem(DM_KEY,d?'1':'0');}
(()=>{if(localStorage.getItem(DM_KEY)==='1')applyDark(true);})();

/* Sidebar */
function isMobile(){return window.innerWidth<=900;}
function openSidebar(){const sb=document.getElementById('sidebar'),ov=document.getElementById('overlay');if(isMobile()){sb.classList.add('mobile-open');ov.classList.add('show');}else{sb.classList.remove('closed');document.querySelector('.main').style.marginLeft='var(--sidebar-w)';}}
function closeSidebar(){const sb=document.getElementById('sidebar'),ov=document.getElementById('overlay');if(isMobile()){sb.classList.remove('mobile-open');ov.classList.remove('show');}else{sb.classList.add('closed');document.querySelector('.main').style.marginLeft='0';}}
window.addEventListener('resize',()=>{if(!isMobile()){document.getElementById('sidebar').classList.remove('mobile-open');document.getElementById('overlay').classList.remove('show');}});

/* GPS */
function updateChip(){const chip=document.getElementById('gpsChip');const lbl=document.getElementById('gpsLabel');if(!chip)return;if(hasGPS){chip.className='gps-chip active';lbl.textContent='GPS Active';}else{chip.className='gps-chip';lbl.textContent='GPS Off';}}
function startGPS(){if(!navigator.geolocation||!document.getElementById('gpsChip'))return;navigator.geolocation.watchPosition(pos=>{userLat=pos.coords.latitude;userLng=pos.coords.longitude;if(!hasGPS){hasGPS=true;updateChip();}checkProximity();},()=>updateChip(),{enableHighAccuracy:true,maximumAge:15000,timeout:20000});}
function haversine(a,b,c,d){const R=6371000,dL=(c-a)*Math.PI/180,dN=(d-b)*Math.PI/180,x=Math.sin(dL/2)**2+Math.cos(a*Math.PI/180)*Math.cos(c*Math.PI/180)*Math.sin(dN/2)**2;return R*2*Math.atan2(Math.sqrt(x),Math.sqrt(1-x));}
function checkProximity(){if(!hasGPS||userLat===null)return;const now=Date.now();allReports.forEach(r=>{if(!r.latitude||!r.longitude)return;const d=haversine(userLat,userLng,r.latitude,r.longitude);if(d<=(r.radius_m||200)){if(now-(alertTimes[r.id]||0)<300000)return;alertTimes[r.id]=now;showToast(r,Math.round(d));}});}
function showToast(r,dm){const d=dm<1000?dm+'m':(dm/1000).toFixed(1)+'km';const icons={dangerous:'fa-circle-exclamation',caution:'fa-triangle-exclamation',safe:'fa-circle-check'};const t=document.createElement('div');t.className=`toast ${r.status}`;t.innerHTML=`<div class="toast-icon"><i class="fas ${icons[r.status]}"></i></div><div><div class="toast-title">Nearby ${ucFirst(r.status)} Report</div><div class="toast-body"><strong>${esc(r.title)}</strong><br>You are ${d} away · ${esc(r.city)}</div></div><button class="toast-close" onclick="dismissToast(this.closest('.toast'))"><i class="fas fa-xmark"></i></button><div class="toast-progress"></div>`;document.getElementById('toastContainer').appendChild(t);setTimeout(()=>dismissToast(t),5200);}
function dismissToast(t){if(!t||!t.parentElement)return;t.classList.add('fade-out');setTimeout(()=>{if(t.parentElement)t.parentElement.removeChild(t);},400);}

/* Fetch reports */
async function fetchReports(){
  const isMine=CURRENT_VIEW==='my_reports';
  try{const res=await fetch('../api/reports.php?action=get_reports');const data=await res.json();
    if(data.status==='success'){allReports=data.reports;renderFeed(isMine);if(CURRENT_VIEW==='map')renderMainMap();checkProximity();}
    else{const f=document.getElementById('feed');if(f)f.innerHTML='<div class="empty"><i class="fas fa-triangle-exclamation"></i><p>Failed to load reports.</p></div>';}
  }catch{const f=document.getElementById('feed');if(f)f.innerHTML='<div class="empty"><i class="fas fa-wifi"></i><p>Network error.</p></div>';}
}

function getFiltered(mineOnly){
  const s=(document.getElementById('searchInput')?.value||'').trim().toLowerCase();
  const st=document.getElementById('statusFilter')?.value||'';
  const ca=document.getElementById('categoryFilter')?.value||'';
  return allReports.filter(r=>{
    if(mineOnly&&r.user_id!=MY_USER_ID)return false;
    if(st&&r.status!==st)return false;if(ca&&r.category!==ca)return false;
    if(s){const h=(r.title+r.location_name+r.city+(r.barangay||'')+(r.description||'')).toLowerCase();if(!h.includes(s))return false;}
    return true;
  });
}

function renderFeed(mineOnly=false){
  const feed=document.getElementById('feed');if(!feed)return;
  const list=getFiltered(mineOnly);
  if(!list.length){
    feed.innerHTML=`<div class="empty"><i class="fas fa-binoculars"></i><p>${mineOnly?"You haven't posted any reports yet.":'No reports match your filters.'}</p>${mineOnly?'<button class="post-btn" onclick="openModal()" style="margin-top:14px;display:inline-flex;"><i class="fas fa-plus"></i>&nbsp;Post First Report</button>':''}</div>`;
    return;
  }
  feed.innerHTML=list.map((r,i)=>{
    const date=new Date(r.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});
    const isMine=r.user_id==MY_USER_ID,upV=r.user_vote==='up',dnV=r.user_vote==='down',hasPin=r.latitude&&r.longitude,hasPh=r.images&&r.images.length>0;
    return `<div class="report-card ${r.status}" style="animation-delay:${i*.04}s;" onclick="openDetail(${r.id})">
      <div class="card-header"><h3>${esc(r.title)}</h3><span class="badge ${r.status}">${ucFirst(r.status)}</span></div>
      <div class="card-meta">
        <span><i class="fas fa-map-location-dot"></i>${esc(r.location_name)}</span>
        <span><i class="fas fa-city"></i>${esc(r.city)}${r.province?', '+esc(r.province):''}</span>
        <span><i class="fas fa-clock"></i>${date}</span>
        <span><i class="fas fa-user"></i>${esc(r.poster_name)}</span>
        ${hasPh?`<span><i class="fas fa-camera" style="color:var(--blue-accent);"></i>${r.images.length} photo${r.images.length>1?'s':''}</span>`:''}
      </div>
      <div class="card-body"><p>${esc((r.description||'').substring(0,200))}${(r.description||'').length>200?'…':''}</p></div>
      <div class="card-footer" onclick="event.stopPropagation()">
        <button class="vote-btn ${upV?'voted':''}" onclick="vote(${r.id},'up')"><i class="fas fa-thumbs-up"></i><span id="up_${r.id}">${r.upvotes}</span></button>
        <button class="vote-btn down ${dnV?'voted':''}" onclick="vote(${r.id},'down')"><i class="fas fa-thumbs-down"></i><span id="dn_${r.id}">${r.downvotes}</span></button>
        ${hasPin?`<button class="pin-chip" onclick="openMiniMap(${r.id})"><i class="fas fa-map-pin"></i> Map</button>`:''}
        ${isMine?`<button class="vote-btn" style="margin-left:auto;border-color:var(--red);color:var(--red);" onclick="deleteReport(${r.id})"><i class="fas fa-trash-can"></i></button>`:`<span class="category-tag" style="margin-left:auto;"><i class="fas ${CI[r.category]||'fa-circle-info'}"></i> ${ucFirst(r.category)}</span>`}
      </div>
    </div>`;
  }).join('');
}

['searchInput','statusFilter','categoryFilter'].forEach(id=>{const el=document.getElementById(id);if(el)el.addEventListener(id==='searchInput'?'input':'change',()=>{clearTimeout(window._ft);window._ft=setTimeout(()=>{renderFeed(CURRENT_VIEW==='my_reports');if(curView==='map')renderInlineMap();},200);});});
function resetFilters(){['searchInput','statusFilter','categoryFilter'].forEach(id=>{const e=document.getElementById(id);if(e)e.value='';});renderFeed(CURRENT_VIEW==='my_reports');if(curView==='map')renderInlineMap();}

async function vote(id,vt){const fd=new FormData();fd.append('action','vote');fd.append('report_id',id);fd.append('vote',vt);try{const res=await fetch('../api/reports.php',{method:'POST',body:fd});const d=await res.json();if(d.status==='success'){const r=allReports.find(x=>x.id==id);if(r){r.upvotes=d.upvotes;r.downvotes=d.downvotes;r.user_vote=d.user_vote;}renderFeed(CURRENT_VIEW==='my_reports');}}catch{}}
async function deleteReport(id){if(!confirm('Delete this report?'))return;const fd=new FormData();fd.append('action','delete_report');fd.append('report_id',id);try{const res=await fetch('../api/reports.php',{method:'POST',body:fd});const d=await res.json();if(d.status==='success'){allReports=allReports.filter(r=>r.id!=id);renderFeed(CURRENT_VIEW==='my_reports');}}catch{}}

/* View toggle (overview only) */
function switchView(v){curView=v;const feedEl=document.getElementById('feed'),mapEl=document.getElementById('inlineMapWrap');if(!feedEl||!mapEl)return;if(v==='map'){feedEl.style.display='none';mapEl.style.display='block';document.getElementById('btnFeed').classList.remove('active');document.getElementById('btnMap').classList.add('active');setTimeout(()=>{initInlineMap();if(inlineMap)inlineMap.invalidateSize();},200);}else{feedEl.style.display='';mapEl.style.display='none';document.getElementById('btnMap').classList.remove('active');document.getElementById('btnFeed').classList.add('active');}}

/* Marker icon */
function markerIcon(status){const c=SC[status]||'#888';const svg=`<svg xmlns="http://www.w3.org/2000/svg" width="30" height="38" viewBox="0 0 30 38"><path d="M15 0C6.716 0 0 6.716 0 15c0 10 15 23 15 23S30 25 30 15 23.284 0 15 0z" fill="${c}" stroke="white" stroke-width="2"/><circle cx="15" cy="15" r="6" fill="white" opacity=".9"/></svg>`;return L.divIcon({html:svg,className:'',iconSize:[30,38],iconAnchor:[15,38],popupAnchor:[0,-38]});}

/* Keep a Leaflet map's tile grid in sync with its container's real size.
   Fixed-delay invalidateSize() calls are a guess; this reacts to the actual
   size change whenever it happens — slow webfont loads, address-bar
   show/hide, orientation change, sidebar toggle, etc. */
function watchMapResize(getMap,el){
  if(!el)return;
  if('ResizeObserver' in window){
    let raf=null;
    new ResizeObserver(()=>{
      const m=getMap();if(!m)return;
      if(raf)cancelAnimationFrame(raf);
      raf=requestAnimationFrame(()=>m.invalidateSize());
    }).observe(el);
  }
  window.addEventListener('orientationchange',()=>{const m=getMap();if(m)setTimeout(()=>m.invalidateSize(),250);});
}

/* Inline map (overview toggle) */
let inlineMap=null,inlineLayers=[];
function initInlineMap(){if(!inlineMap){inlineMap=L.map('inlineMap').setView([14.5995,120.9842],11);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',maxZoom:19}).addTo(inlineMap);watchMapResize(()=>inlineMap,document.getElementById('inlineMap'));setTimeout(()=>inlineMap.invalidateSize(),100);}renderInlineMap();}
function renderInlineMap(){if(!inlineMap)return;inlineLayers.forEach(l=>inlineMap.removeLayer(l));inlineLayers=[];const list=getFiltered(false);const bounds=[];let pins=0;list.forEach(r=>{if(!r.latitude||!r.longitude)return;pins++;const ll=[r.latitude,r.longitude];bounds.push(ll);const ci=L.circle(ll,{radius:r.radius_m||200,color:SC[r.status]||'#888',fillColor:SF[r.status],fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(inlineMap);const mk=L.marker(ll,{icon:markerIcon(r.status)}).addTo(inlineMap);mk.bindPopup(`<div style="min-width:200px;font-family:'Poppins',sans-serif;font-size:.82rem;padding:4px;"><div style="font-weight:800;margin-bottom:6px;">${esc(r.title)}</div><span style="display:inline-block;background:${SC[r.status]};color:#fff;padding:2px 10px;border-radius:50px;font-size:.69rem;font-weight:700;text-transform:uppercase;margin-bottom:8px;">${ucFirst(r.status)}</span><div style="color:#555;">${esc(r.location_name)}, ${esc(r.city)}</div><div style="color:#888;font-size:.74rem;margin-top:3px;">${esc(r.poster_name)}</div><button onclick="openDetail(${r.id})" style="display:block;width:100%;margin-top:10px;padding:7px;background:#1a5276;color:#fff;border:none;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;">Full Details</button></div>`,{maxWidth:280});inlineLayers.push(ci,mk);});if(bounds.length)inlineMap.fitBounds(bounds,{padding:[50,50],maxZoom:15});const cnt=document.getElementById('inlineMapCount');if(cnt)cnt.textContent=`${pins} of ${list.length} reports pinned`;setTimeout(()=>inlineMap.invalidateSize(),300);setTimeout(()=>inlineMap.invalidateSize(),600);}

/* Full incidents map */
let mainMap=null,mainLayers=[];
function renderMainMap(){if(!mainMap){mainMap=L.map('incidentMap').setView([14.5995,120.9842],11);L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© <a href="https://www.openstreetmap.org/copyright">OpenStreetMap</a>',maxZoom:19}).addTo(mainMap);watchMapResize(()=>mainMap,document.getElementById('incidentMap'));}mainLayers.forEach(l=>mainMap.removeLayer(l));mainLayers=[];const bounds=[];let pins=0;allReports.forEach(r=>{if(!r.latitude||!r.longitude)return;pins++;const ll=[r.latitude,r.longitude];bounds.push(ll);const ci=L.circle(ll,{radius:r.radius_m||200,color:SC[r.status]||'#888',fillColor:SF[r.status],fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(mainMap);const mk=L.marker(ll,{icon:markerIcon(r.status)}).addTo(mainMap);const date=new Date(r.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric',hour:'2-digit',minute:'2-digit'});const fp=r.images&&r.images.length>0?r.images[0]:null;const ph=fp?`<img src="${esc(fp)}" style="width:100%;height:120px;object-fit:cover;display:block;" onerror="this.style.display='none'">`:'';const popup=`<div style="min-width:210px;max-width:260px;font-family:'Poppins',sans-serif;font-size:.82rem;overflow:hidden;border-radius:10px;">${fp?`<div style="overflow:hidden;border-radius:10px 10px 0 0;">${ph}</div>`:''}<div style="padding:10px 12px;"><div style="font-weight:800;font-size:.93rem;margin-bottom:6px;">${esc(r.title)}</div><span style="display:inline-block;background:${SC[r.status]};color:#fff;padding:2px 10px;border-radius:50px;font-size:.69rem;font-weight:700;text-transform:uppercase;margin-bottom:8px;">${ucFirst(r.status)}</span><div style="color:#555;">${esc(r.poster_name)} · ${date}</div><div style="color:#888;margin-top:3px;">${esc(r.location_name)}, ${esc(r.city)}</div><button onclick="openDetail(${r.id})" style="display:block;width:100%;margin-top:10px;padding:7px;background:#1a5276;color:#fff;border:none;border-radius:8px;font-size:.78rem;font-weight:700;cursor:pointer;">Full Details</button></div></div>`;mk.bindTooltip(popup,{direction:'top',offset:[0,-38],opacity:1,sticky:false,permanent:false});mk.bindPopup(popup,{maxWidth:280});mainLayers.push(ci,mk);});if(bounds.length)mainMap.fitBounds(bounds,{padding:[50,50],maxZoom:14});const cnt=document.getElementById('mapCount');if(cnt)cnt.textContent=`${pins} pinned report${pins!==1?'s':''}`;setTimeout(()=>mainMap.invalidateSize(),300);setTimeout(()=>mainMap.invalidateSize(),600);}

/* Map picker */
let pickerMap=null,pickerMarker=null,pickerCircle=null,pickerRadius=200;
function initPickerMap(){
  if(pickerMap){setTimeout(()=>pickerMap.invalidateSize(),60);return;}
  const curLat=parseFloat(document.getElementById('r_latitude').value)||null;
  const curLng=parseFloat(document.getElementById('r_longitude').value)||null;
  const lat=curLat!==null?curLat:(SAVED_LAT!==null?SAVED_LAT:14.5995);
  const lng=curLng!==null?curLng:(SAVED_LNG!==null?SAVED_LNG:120.9842);
  const zoom=(curLat!==null||SAVED_LAT!==null)?15:11;
  pickerMap=L.map('pickerMap').setView([lat,lng],zoom);
  L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(pickerMap);
  pickerMap.on('click',e=>placePin(e.latlng.lat,e.latlng.lng,true));
  setTimeout(()=>{
    pickerMap.invalidateSize();
    if(curLat!==null)placePin(curLat,curLng,false);
    else if(SAVED_LAT!==null)placePin(SAVED_LAT,SAVED_LNG,false);
  },150);
}
function placePin(lat,lng,doRG){
  document.getElementById('r_latitude').value=lat;
  document.getElementById('r_longitude').value=lng;
  if(pickerMap){
    if(pickerMarker)pickerMap.removeLayer(pickerMarker);
    if(pickerCircle)pickerMap.removeLayer(pickerCircle);
    pickerCircle=L.circle([lat,lng],{radius:pickerRadius,color:'#3a8dff',fillColor:'rgba(58,141,255,.12)',fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(pickerMap);
    pickerMarker=L.marker([lat,lng],{icon:L.divIcon({html:`<svg xmlns="http://www.w3.org/2000/svg" width="30" height="38" viewBox="0 0 30 38"><path d="M15 0C6.716 0 0 6.716 0 15c0 10 15 23 15 23S30 25 30 15 23.284 0 15 0z" fill="#3a8dff" stroke="white" stroke-width="2"/><circle cx="15" cy="15" r="6" fill="white" opacity=".9"/></svg>`,className:'',iconSize:[30,38],iconAnchor:[15,38]}),draggable:true}).addTo(pickerMap);
    pickerMarker.on('dragend',ev=>{const p=ev.target.getLatLng();placePin(p.lat,p.lng,true);});
    pickerMap.setView([lat,lng],Math.max(pickerMap.getZoom(),15));
  }
  const ps=document.getElementById('pinStatus');
  if(ps){ps.className='pin-status set';ps.innerHTML=`<i class="fas fa-circle-check"></i> ${lat.toFixed(5)}, ${lng.toFixed(5)}`;}
  const ps2=document.getElementById('pinStatus2');
  if(ps2){ps2.className='pin-status set';ps2.innerHTML=`<i class="fas fa-circle-check"></i> Pinned`;}
  document.getElementById('clearPinBtn').style.display='inline-flex';
  if(doRG)reverseGeocode(lat,lng);
}
function clearPin(){
  if(pickerMap){
    if(pickerMarker){pickerMap.removeLayer(pickerMarker);}
    if(pickerCircle){pickerMap.removeLayer(pickerCircle);}
  }
  pickerMarker=null;pickerCircle=null;
  document.getElementById('r_latitude').value='';
  document.getElementById('r_longitude').value='';
  document.getElementById('r_radius_m').value='200';
  const rs=document.getElementById('radiusSlider');if(rs)rs.value=200;
  const rv=document.getElementById('radiusVal');if(rv)rv.textContent='200 m';
  pickerRadius=200;
  const ps=document.getElementById('pinStatus');
  if(ps){ps.className='pin-status';ps.innerHTML='<i class="fas fa-circle-info"></i> No pin set';}
  const ps2=document.getElementById('pinStatus2');
  if(ps2){ps2.className='pin-status';ps2.innerHTML='';}
  document.getElementById('clearPinBtn').style.display='none';
}
function onRadiusChange(val){pickerRadius=parseInt(val);document.getElementById('radiusVal').textContent=pickerRadius>=1000?(pickerRadius/1000).toFixed(1)+' km':pickerRadius+' m';document.getElementById('r_radius_m').value=pickerRadius;if(pickerCircle)pickerCircle.setRadius(pickerRadius);}
async function reverseGeocode(lat,lng){try{const res=await fetch(`../api/geocode_proxy.php?lat=${lat}&lon=${lng}`);const d=await res.json();if(d&&d.address){const a=d.address;if(!document.getElementById('r_location').value)document.getElementById('r_location').value=a.road||a.hamlet||a.suburb||'';if(!document.getElementById('r_barangay').value)document.getElementById('r_barangay').value=a.suburb||a.village||a.quarter||a.neighbourhood||'';if(!document.getElementById('r_city').value)document.getElementById('r_city').value=a.city||a.town||a.municipality||'';if(!document.getElementById('r_province').value)document.getElementById('r_province').value=a.state||a.province||'';}}catch{}}
function useMyLocation(){
  const btn=document.getElementById('locateBtn');
  if(SAVED_LAT!==null&&SAVED_LNG!==null){placePin(SAVED_LAT,SAVED_LNG,true);return;}
  if(!navigator.geolocation){
    const cf=document.getElementById('r_city');if(cf)cf.focus();
    return;
  }
  btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Locating…';
  btn.disabled=true;
  navigator.geolocation.getCurrentPosition(pos=>{
    btn.innerHTML='<i class="fas fa-location-crosshairs"></i> Use My Location';
    btn.disabled=false;
    placePin(pos.coords.latitude,pos.coords.longitude,true);
  },err=>{
    btn.innerHTML='<i class="fas fa-location-crosshairs"></i> Use My Location';
    btn.disabled=false;
    const cf=document.getElementById('r_city');
    if(cf){cf.focus();cf.placeholder='Enter your city / municipality';}
  },{enableHighAccuracy:true,timeout:10000,maximumAge:60000});
}

/* Post report modal */
function openModal(){
  document.getElementById('modalOverlay').classList.add('open');
  if(SAVED_LAT!==null&&SAVED_LNG!==null){setTimeout(()=>placePin(SAVED_LAT,SAVED_LNG,true),250);}
}
function closeModal(){
  document.getElementById('modalOverlay').classList.remove('open');
  document.getElementById('reportForm').reset();
  clearStatusSelection();
  document.querySelectorAll('.cat-opt').forEach(el=>el.classList.remove('selected'));
  document.getElementById('r_category').value='';
  document.getElementById('modalMsg').style.display='none';
  clearPin();
  document.getElementById('photoPreviewRow').innerHTML='';
  const ph=document.getElementById('r_photos');if(ph)ph.value='';
  // Reset collapsibles
  _locOpen=false;_optOpen=false;_mapOpen=false;
  const ld=document.getElementById('locDetails');if(ld)ld.style.display='none';
  const od=document.getElementById('optionalDetails');if(od)od.style.display='none';
  const mp=document.getElementById('mapPickerSection');if(mp)mp.style.display='none';
  const lc=document.getElementById('locChevron');if(lc)lc.style.transform='';
  const oc=document.getElementById('optChevron');if(oc)oc.style.transform='';
  const pb=document.getElementById('mapPickBtn');if(pb)pb.innerHTML='<i class="fas fa-map-pin"></i> Drop a Pin on Map';
}
function outsideClose(e){if(e.target===document.getElementById('modalOverlay'))closeModal();}
function selectStatus(s){clearStatusSelection();document.getElementById('opt_'+s).classList.add('selected');document.getElementById('r_status').value=s;}
function clearStatusSelection(){['dangerous','caution','safe'].forEach(s=>document.getElementById('opt_'+s).classList.remove('selected'));document.getElementById('r_status').value='';}
function selectCategory(c){document.querySelectorAll('.cat-opt').forEach(el=>el.classList.remove('selected'));document.getElementById('cat_'+c).classList.add('selected');document.getElementById('r_category').value=c;}
let _locOpen=false,_optOpen=false,_mapOpen=false;
function toggleLocDetails(){_locOpen=!_locOpen;const ld=document.getElementById('locDetails');ld.style.display=_locOpen?'flex':'none';document.getElementById('locChevron').style.transform=_locOpen?'rotate(90deg)':'rotate(0deg)';if(_locOpen&&pickerMap)setTimeout(()=>pickerMap.invalidateSize(),100);}
function toggleMapPicker(){_mapOpen=!_mapOpen;const mp=document.getElementById('mapPickerSection');mp.style.display=_mapOpen?'block':'none';const btn=document.getElementById('mapPickBtn');btn.innerHTML=_mapOpen?'<i class="fas fa-xmark"></i> Hide Map':'<i class="fas fa-map-pin"></i> Drop a Pin on Map';if(_mapOpen){setTimeout(()=>{initPickerMap();if(pickerMap)pickerMap.invalidateSize();},100);}}
function toggleOptional(){_optOpen=!_optOpen;const od=document.getElementById('optionalDetails');od.style.display=_optOpen?'flex':'none';document.getElementById('optChevron').style.transform=_optOpen?'rotate(90deg)':'rotate(0deg)';}
function onPhotosChosen(input){const row=document.getElementById('photoPreviewRow');row.innerHTML='';Array.from(input.files).slice(0,3).forEach((file,idx)=>{const reader=new FileReader();reader.onload=e=>{const wrap=document.createElement('div');wrap.className='photo-thumb';const img=document.createElement('img');img.src=e.target.result;img.alt='preview';const btn=document.createElement('button');btn.className='remove-photo';btn.innerHTML='<i class="fas fa-xmark"></i>';btn.onclick=ev=>{ev.stopPropagation();removePhoto(idx);};wrap.appendChild(img);wrap.appendChild(btn);row.appendChild(wrap);};reader.readAsDataURL(file);});}
function removePhoto(idx){const input=document.getElementById('r_photos');const dt=new DataTransfer();Array.from(input.files).forEach((f,i)=>{if(i!==idx)dt.items.add(f);});input.files=dt.files;onPhotosChosen(input);}
document.getElementById('reportForm').addEventListener('submit',async function(e){
  e.preventDefault();
  const msgEl=document.getElementById('modalMsg'),btn=document.getElementById('submitBtn');
  const status=document.getElementById('r_status').value;
  const cat=document.getElementById('r_category').value;
  const desc=document.getElementById('r_description').value.trim();
  const city=document.getElementById('r_city').value.trim();
  if(!status){showMsg(msgEl,'error','Choose a severity level — Step 1.');return;}
  if(!cat){showMsg(msgEl,'error','Choose an incident type — Step 2.');return;}
  if(!desc){showMsg(msgEl,'error','Describe what is happening — Step 3.');return;}
  if(!city){showMsg(msgEl,'error','Enter your city or municipality — Step 4.');return;}
  // Auto-generate title
  let title=document.getElementById('r_title').value.trim();
  if(!title)title=`${ucFirst(status)} ${CL[cat]||'Incident'} in ${city}`;
  // Auto-fill location_name
  let loc=document.getElementById('r_location').value.trim();
  if(!loc){const la=document.getElementById('r_latitude').value,ln=document.getElementById('r_longitude').value;loc=la&&ln?`Near ${parseFloat(la).toFixed(5)}, ${parseFloat(ln).toFixed(5)}`:city;}
  btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Submitting…';
  const fd=new FormData();
  fd.append('action','post_report');fd.append('title',title);fd.append('status',status);fd.append('category',cat);
  fd.append('location_name',loc);fd.append('barangay',document.getElementById('r_barangay').value.trim());
  fd.append('city',city);fd.append('province',document.getElementById('r_province').value.trim());
  fd.append('description',desc);fd.append('latitude',document.getElementById('r_latitude').value);
  fd.append('longitude',document.getElementById('r_longitude').value);fd.append('radius_m',document.getElementById('r_radius_m').value);
  const ph=document.getElementById('r_photos');
  if(ph&&ph.files.length)Array.from(ph.files).slice(0,3).forEach(f=>fd.append('photos[]',f));
  try{
    const res=await fetch('../api/reports.php',{method:'POST',body:fd});
    const data=await res.json();
    if(data.status==='success'){
      showMsg(msgEl,'success','Report posted!');
      if(status==='dangerous'&&data.id){const nfd=new FormData();nfd.append('action','notify_report');nfd.append('report_id',data.id);fetch('../api/contacts.php',{method:'POST',body:nfd}).catch(()=>{});}
      setTimeout(()=>{closeModal();fetchReports();},1000);
    }else showMsg(msgEl,'error',data.message||'Failed to submit.');
  }catch{showMsg(msgEl,'error','Network error.');}
  btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Submit Report';
});

/* Report detail */
function openDetail(id){const r=allReports.find(x=>x.id==id);if(!r)return;const badge=document.getElementById('d_badge');badge.textContent=ucFirst(r.status);badge.className='detail-badge '+r.status;document.getElementById('d_cat_tag').innerHTML=`<i class="fas ${CI[r.category]||'fa-circle-info'}" style="margin-right:5px;"></i>${CL[r.category]||ucFirst(r.category)}`;document.getElementById('d_title').textContent=r.title;const date=new Date(r.created_at).toLocaleDateString('en-PH',{weekday:'short',year:'numeric',month:'long',day:'numeric',hour:'2-digit',minute:'2-digit'});document.getElementById('d_reporter').innerHTML=`Posted by <span>${esc(r.poster_name)}</span> — ${date}`;const phEl=document.getElementById('d_photos');if(r.images&&r.images.length>0){phEl.style.display='flex';phEl.innerHTML=r.images.map((u,i)=>`<div class="detail-photo" onclick="openLightbox('${u.replace(/'/g,'%27')}')"><img src="${esc(u)}" alt="Photo ${i+1}" loading="lazy" onerror="this.parentElement.style.display='none'">${r.images.length>1?`<div style="position:absolute;bottom:7px;right:9px;background:rgba(0,0,0,.55);color:#fff;font-size:.68rem;padding:2px 8px;border-radius:20px;">${i+1}/${r.images.length}</div>`:''}</div>`).join('');}else phEl.style.display='none';const loc=[r.location_name,r.barangay,r.city,r.province].filter(Boolean).join(', ');document.getElementById('d_meta_grid').innerHTML=[{icon:'fa-map-location-dot',color:SC[r.status],label:'Location',value:loc||'—'},{icon:'fa-city',color:'#888',label:'City',value:r.city+(r.province?', '+r.province:'')},{icon:'fa-thumbs-up',color:'#38a169',label:'Votes',value:`👍 ${r.upvotes} &nbsp; 👎 ${r.downvotes}`},{icon:'fa-circle-dot',color:SC[r.status],label:'Radius',value:`${r.radius_m||200} meters`}].map(m=>`<div class="detail-meta-item"><div class="detail-meta-label">${m.label}</div><div class="detail-meta-value"><i class="fas ${m.icon}" style="color:${m.color};"></i>${m.value}</div></div>`).join('');const db=document.getElementById('d_desc_box');if(r.description){db.style.display='';document.getElementById('d_desc').textContent=r.description;}else db.style.display='none';const upV=r.user_vote==='up',dnV=r.user_vote==='down',hp=r.latitude&&r.longitude;document.getElementById('d_footer').innerHTML=`<button class="vote-btn ${upV?'voted':''}" onclick="vote(${r.id},'up')"><i class="fas fa-thumbs-up"></i> ${r.upvotes}</button><button class="vote-btn down ${dnV?'voted':''}" onclick="vote(${r.id},'down')"><i class="fas fa-thumbs-down"></i> ${r.downvotes}</button>${hp?`<button class="detail-map-btn" onclick="closeDetail();openMiniMap(${r.id})"><i class="fas fa-map-pin"></i> View on Map</button>`:''}${r.user_id==MY_USER_ID?`<button class="vote-btn" onclick="closeDetail();deleteReport(${r.id})" style="margin-left:auto;border-color:var(--red);color:var(--red);"><i class="fas fa-trash-can"></i> Delete</button>`:''}`;document.getElementById('detailModal').style.borderLeft=`5px solid ${SC[r.status]||'#ccc'}`;document.getElementById('detailOverlay').classList.add('open');document.body.style.overflow='hidden';}
function closeDetail(){document.getElementById('detailOverlay').classList.remove('open');document.body.style.overflow='';}
function outsideCloseDetail(e){if(e.target===document.getElementById('detailOverlay'))closeDetail();}

/* Mini map */
let miniMap=null,miniLayers=[];
function openMiniMap(id){const r=allReports.find(x=>x.id==id);if(!r||!r.latitude||!r.longitude)return;document.getElementById('miniMapModal').classList.add('open');document.getElementById('miniMapTitle').textContent=r.title;document.getElementById('miniMapFooter').innerHTML=`<span><i class="fas fa-map-location-dot"></i> ${esc(r.location_name)}, ${esc(r.city)}</span><span style="color:${SC[r.status]};font-weight:700;">${ucFirst(r.status)}</span>`;setTimeout(()=>{if(!miniMap){miniMap=L.map('miniMap');L.tileLayer('https://{s}.tile.openstreetmap.org/{z}/{x}/{y}.png',{attribution:'© OpenStreetMap',maxZoom:19}).addTo(miniMap);}miniLayers.forEach(l=>miniMap.removeLayer(l));miniLayers=[];const ll=[r.latitude,r.longitude];const ci=L.circle(ll,{radius:r.radius_m||200,color:SC[r.status]||'#888',fillColor:SF[r.status],fillOpacity:1,weight:2,dashArray:'6 4'}).addTo(miniMap);const mk=L.marker(ll,{icon:markerIcon(r.status)}).addTo(miniMap).bindPopup(esc(r.title)).openPopup();miniLayers.push(ci,mk);miniMap.setView(ll,16);miniMap.invalidateSize();},150);}
function closeMiniMap(e){if(e.target===document.getElementById('miniMapModal'))closeMiniMapDirect();}
function closeMiniMapDirect(){document.getElementById('miniMapModal').classList.remove('open');}

/* Lightbox */
function openLightbox(url){document.getElementById('lightboxImg').src=url;document.getElementById('lightbox').classList.add('open');}
function closeLightbox(){document.getElementById('lightbox').classList.remove('open');document.getElementById('lightboxImg').src='';}
document.addEventListener('keydown',e=>{if(e.key==='Escape'){closeLightbox();closeDetail();}});

/* Profile: avatar swatches */
let selColor=INIT_COLOR;
function buildPalette(){const pal=document.getElementById('colorPalette');if(!pal)return;pal.innerHTML=SWATCHES.map(c=>`<div class="color-swatch${c===selColor?' selected':''}" style="background:${c}" onclick="pickColor('${c}')"></div>`).join('');}
function pickColor(c){selColor=c;const prev=document.getElementById('avatarPreview');if(prev)prev.style.background=c;buildPalette();const fn='<?= htmlspecialchars($fname,ENT_QUOTES) ?>';const ln='<?= htmlspecialchars($lname,ENT_QUOTES) ?>';const em='<?= htmlspecialchars($user_email,ENT_QUOTES) ?>';const fd=new FormData();fd.append('action','update_profile');fd.append('avatar_color',c);fd.append('first_name',fn);fd.append('last_name',ln);fd.append('email',em);fetch('../api/reports.php',{method:'POST',body:fd}).catch(()=>{});}

/* Profile: GPS */
async function saveGPS(){if(!navigator.geolocation){alert('Geolocation not supported.');return;}const btn=document.getElementById('getGpsBtn');const disp=document.getElementById('gpsCoordsDisplay');btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Acquiring…';navigator.geolocation.getCurrentPosition(async pos=>{const lat=pos.coords.latitude,lng=pos.coords.longitude;const fd=new FormData();fd.append('action','save_gps');fd.append('latitude',lat);fd.append('longitude',lng);try{const res=await fetch('../api/reports.php',{method:'POST',body:fd});const d=await res.json();if(d.status==='success'){if(disp){disp.className='gps-coords has-gps';disp.innerHTML=`<i class="fas fa-circle-check"></i> ${lat.toFixed(6)}, ${lng.toFixed(6)}`;}const pfMsg=document.getElementById('pfMsg');if(pfMsg)showMsg(pfMsg,'success','GPS saved! Map and alerts unlocked.');userLat=lat;userLng=lng;hasGPS=true;updateChip();checkProximity();}}catch{}btn.disabled=false;btn.innerHTML='<i class="fas fa-location-crosshairs"></i> Get GPS Data';},err=>{alert(err.code===1?'Location access denied.':'Could not get location.');btn.disabled=false;btn.innerHTML='<i class="fas fa-location-crosshairs"></i> Get GPS Data';},{enableHighAccuracy:true,timeout:15000,maximumAge:0});}

/* Helpers */
function esc(s){if(!s)return'';return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');}
function ucFirst(s){return s?s.charAt(0).toUpperCase()+s.slice(1):'';}
function showMsg(el,type,text){el.className='pf-msg '+type;el.textContent=text;el.style.display='block';}

/* Boot */
updateChip();
startGPS();
if(document.getElementById('colorPalette'))buildPalette();
if(['overview','my_reports','map'].includes(CURRENT_VIEW)){fetchReports();setInterval(fetchReports,60000);}
if(CURRENT_VIEW==='map')setTimeout(()=>renderMainMap(),300);
/* On slow connections, self-hosted fonts/icons can keep streaming in and
   reflowing the layout well after the maps first render. Re-sync once
   everything has actually finished loading, on top of the ResizeObserver. */
if(document.fonts&&document.fonts.ready){document.fonts.ready.then(()=>{if(mainMap)mainMap.invalidateSize();if(inlineMap)inlineMap.invalidateSize();});}
window.addEventListener('load',()=>{if(mainMap)mainMap.invalidateSize();if(inlineMap)inlineMap.invalidateSize();});
</script>
</body>
</html>
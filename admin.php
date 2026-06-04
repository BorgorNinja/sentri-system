<?php
session_start();
if (!isset($_SESSION['user_id']) || $_SESSION['role'] !== 'admin') {
    header("Location: login.php"); exit;
}
require __DIR__ . '/config/db.php';

$total_users    = $conn->query("SELECT COUNT(*) FROM users")->fetch_row()[0];
$total_reports  = $conn->query("SELECT COUNT(*) FROM reports WHERE is_archived=0")->fetch_row()[0];
$total_archived = $conn->query("SELECT COUNT(*) FROM reports WHERE is_archived=1")->fetch_row()[0];
$danger_count   = $conn->query("SELECT COUNT(*) FROM reports WHERE status='dangerous' AND is_archived=0")->fetch_row()[0];
$pending_count  = $conn->query("SELECT COUNT(*) FROM users WHERE is_approved=0 AND role NOT IN('community','user','admin')")->fetch_row()[0];
$first_name     = $_SESSION['first_name'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard – SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{
  --blue:#1c57b2;--blue-dark:#0e3d8c;--blue-light:#3a8dff;
  --admin:#7c3aed;--admin-light:#a78bfa;
  --red:#e53e3e;--green:#38a169;--orange:#dd6b20;
  --text:#1a1a2e;--muted:#666;--border:#eee;
  --bg:#f0f2f7;--card:#fff;
  --sidebar-w:260px;
}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif;}
body{background:var(--bg);display:flex;min-height:100vh;color:var(--text);}

/* ── ANIMATIONS ── */
@keyframes fadeInUp{from{opacity:0;transform:translateY(20px);}to{opacity:1;transform:translateY(0);}}
@keyframes fadeIn{from{opacity:0;}to{opacity:1;}}
@keyframes slideDown{from{opacity:0;transform:translateY(-10px);}to{opacity:1;transform:translateY(0);}}
@keyframes scaleIn{from{opacity:0;transform:scale(0.95);}to{opacity:1;transform:scale(1);}}
@keyframes countUp{from{opacity:0;transform:translateY(8px);}to{opacity:1;transform:translateY(0);}}
@keyframes shimmer{0%{background-position:-200% 0;}100%{background-position:200% 0;}}
@keyframes pulse-dot{0%,100%{opacity:1;}50%{opacity:0.4;}}
@keyframes spin{to{transform:rotate(360deg);}}

/* ── SIDEBAR ── */
.sidebar{
  width:var(--sidebar-w);
  background:linear-gradient(180deg,#1a1a2e 0%,#16213e 60%,#0f1629 100%);
  color:#fff;display:flex;flex-direction:column;flex-shrink:0;
  position:fixed;top:0;left:0;bottom:0;z-index:200;
  transition:transform 0.3s cubic-bezier(0.4,0,0.2,1);
  box-shadow:4px 0 24px rgba(0,0,0,0.25);
}
.sidebar.closed{transform:translateX(calc(-1 * var(--sidebar-w)));}
.sidebar-header{padding:22px 18px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid rgba(255,255,255,0.08);}
.brand-row{display:flex;align-items:center;gap:10px;}
.brand-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--blue-light),var(--blue));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;box-shadow:0 4px 14px rgba(58,141,255,0.4);}
.brand-name{font-size:1rem;font-weight:800;}
.admin-badge{font-size:0.6rem;background:var(--admin);color:#fff;padding:2px 8px;border-radius:20px;font-weight:700;margin-left:4px;letter-spacing:0.5px;}
.toggle-btn{background:none;border:none;color:rgba(255,255,255,0.6);cursor:pointer;font-size:1.1rem;padding:4px;transition:color 0.2s;}
.toggle-btn:hover{color:#fff;}

.nav-section{padding:12px 12px 0;font-size:0.68rem;color:rgba(255,255,255,0.4);text-transform:uppercase;letter-spacing:1px;font-weight:600;margin-bottom:6px;}
.menu{padding:0 10px;display:flex;flex-direction:column;gap:2px;}
.menu-item{display:flex;align-items:center;gap:11px;padding:11px 13px;text-decoration:none;color:rgba(255,255,255,0.7);font-size:0.875rem;font-weight:500;border-radius:10px;transition:all 0.2s;cursor:pointer;border:none;background:transparent;width:100%;text-align:left;font-family:'Poppins',sans-serif;}
.menu-item:hover{background:rgba(255,255,255,0.08);color:#fff;}
.menu-item.active{background:linear-gradient(135deg,rgba(58,141,255,0.25),rgba(28,87,178,0.2));color:#fff;border-left:3px solid var(--blue-light);}
.menu-item.admin-active{background:linear-gradient(135deg,rgba(124,58,237,0.25),rgba(91,33,182,0.2));border-left-color:var(--admin-light);}
.menu-item i{font-size:1rem;width:18px;text-align:center;flex-shrink:0;}
.menu-badge{margin-left:auto;background:var(--red);color:#fff;font-size:0.65rem;padding:2px 7px;border-radius:20px;font-weight:700;}

.sidebar-user{padding:14px;margin:auto 0 0;border-top:1px solid rgba(255,255,255,0.08);}
.user-card{background:rgba(255,255,255,0.06);border-radius:10px;padding:12px;display:flex;align-items:center;gap:10px;}
.user-avatar{width:36px;height:36px;border-radius:50%;background:var(--admin);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0;}
.user-info-side{flex:1;min-width:0;}
.user-info-side .name{font-size:0.85rem;font-weight:600;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.user-info-side .role{font-size:0.72rem;color:var(--admin-light);}
.logout-btn{background:none;border:none;color:rgba(255,255,255,0.5);cursor:pointer;font-size:0.9rem;padding:4px;transition:color 0.2s;}
.logout-btn:hover{color:#fff;}

/* ── MAIN ── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-width:0;transition:margin-left 0.3s;}
.main.expanded{margin-left:0;}

/* ── TOPBAR ── */
.topbar{background:#fff;padding:14px 28px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 12px rgba(0,0,0,0.07);position:sticky;top:0;z-index:100;}
.topbar-left{display:flex;align-items:center;gap:14px;}
.ham-btn{background:none;border:none;font-size:1.2rem;color:var(--muted);cursor:pointer;padding:6px;border-radius:8px;transition:all 0.2s;display:none;}
.ham-btn:hover{background:#f0f2f7;color:var(--text);}
.topbar h1{font-size:1.1rem;font-weight:700;color:var(--text);}
.topbar-right{display:flex;align-items:center;gap:12px;}
.topbar-avatar{width:36px;height:36px;border-radius:50%;background:var(--admin);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;}
.topbar-name{font-size:0.88rem;font-weight:600;}
.live-dot{width:8px;height:8px;background:var(--green);border-radius:50%;animation:pulse-dot 2s infinite;}

/* ── CONTENT ── */
.content{padding:28px;animation:fadeIn 0.4s ease;}

/* ── STATS ── */
.stats-row{display:grid;grid-template-columns:repeat(4,1fr);gap:18px;margin-bottom:28px;}
.stat-card{background:#fff;border-radius:16px;padding:20px 22px;box-shadow:0 2px 12px rgba(0,0,0,0.06);display:flex;align-items:center;gap:16px;animation:fadeInUp 0.5s both;transition:transform 0.2s,box-shadow 0.2s;position:relative;overflow:hidden;}
.stat-card:nth-child(1){animation-delay:0.05s;}
.stat-card:nth-child(2){animation-delay:0.1s;}
.stat-card:nth-child(3){animation-delay:0.15s;}
.stat-card:nth-child(4){animation-delay:0.2s;}
.stat-card:hover{transform:translateY(-3px);box-shadow:0 8px 28px rgba(0,0,0,0.1);}
.stat-card::after{content:'';position:absolute;top:0;left:0;right:0;height:3px;}
.stat-card.blue::after{background:linear-gradient(90deg,var(--blue-light),var(--blue));}
.stat-card.red::after{background:linear-gradient(90deg,#ff6b6b,var(--red));}
.stat-card.green::after{background:linear-gradient(90deg,#68d391,var(--green));}
.stat-card.purple::after{background:linear-gradient(90deg,var(--admin-light),var(--admin));}
.stat-icon{width:50px;height:50px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0;}
.stat-icon.blue{background:#ebf2ff;color:var(--blue);}
.stat-icon.red{background:#fff0f0;color:var(--red);}
.stat-icon.green{background:#f0fff4;color:var(--green);}
.stat-icon.purple{background:#f5f3ff;color:var(--admin);}
.stat-num{font-size:1.8rem;font-weight:800;color:var(--text);animation:countUp 0.6s both;}
.stat-label{font-size:0.78rem;color:var(--muted);}

/* ── PANEL ── */
.panel{background:#fff;border-radius:16px;padding:24px;box-shadow:0 2px 12px rgba(0,0,0,0.06);animation:scaleIn 0.4s ease both;margin-bottom:24px;}
.panel-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;flex-wrap:wrap;gap:12px;}
.panel-title{font-size:1rem;font-weight:700;color:var(--text);display:flex;align-items:center;gap:8px;}
.panel-title i{color:var(--blue);}

/* ── TAB SYSTEM ── */
.tab-nav{display:flex;gap:0;background:#f0f2f7;border-radius:12px;padding:4px;margin-bottom:24px;}
.tab-nav-btn{flex:1;padding:10px 18px;border:none;background:transparent;border-radius:9px;font-size:0.87rem;font-weight:600;cursor:pointer;transition:all 0.25s;color:var(--muted);font-family:'Poppins',sans-serif;display:flex;align-items:center;justify-content:center;gap:7px;white-space:nowrap;}
.tab-nav-btn.active{background:#fff;color:var(--blue);box-shadow:0 2px 8px rgba(0,0,0,0.1);}
.tab-nav-btn i{font-size:0.85rem;}
.tab-panel{display:none;animation:fadeInForm 0.3s ease both;}
.tab-panel.active{display:block;}
@keyframes fadeInForm{from{opacity:0;transform:translateY(6px);}to{opacity:1;transform:translateY(0);}}

/* ── FILTERS ── */
.filter-bar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:18px;align-items:center;}
.filter-bar input,.filter-bar select{padding:9px 13px;border:1.5px solid #e0e0e0;border-radius:9px;font-size:0.85rem;outline:none;font-family:'Poppins',sans-serif;transition:0.2s;background:#fafafa;}
.filter-bar input:focus,.filter-bar select:focus{border-color:var(--blue-light);background:#fff;}
.filter-bar input{flex:1;min-width:180px;}
.filter-bar select{min-width:140px;}

/* ── TABLES ── */
.table-wrap{overflow-x:auto;border-radius:10px;border:1px solid var(--border);}
table{width:100%;border-collapse:collapse;font-size:0.83rem;}
th{text-align:left;padding:11px 14px;background:#f8f9fc;color:#555;font-weight:600;border-bottom:2px solid var(--border);font-size:0.76rem;text-transform:uppercase;letter-spacing:0.5px;white-space:nowrap;}
td{padding:12px 14px;border-bottom:1px solid #f5f5f5;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:#fafbff;transition:background 0.15s;}

/* ── BADGES ── */
.badge{padding:3px 10px;border-radius:50px;font-size:0.72rem;font-weight:700;text-transform:uppercase;letter-spacing:0.4px;white-space:nowrap;}
.badge.dangerous{background:#fff0f0;color:var(--red);}
.badge.caution{background:#fff8f0;color:var(--orange);}
.badge.safe{background:#f0fff4;color:var(--green);}
.badge.archived{background:#f5f5f5;color:#999;}
.badge.admin{background:#f5f3ff;color:var(--admin);}
.badge.user{background:#ebf2ff;color:var(--blue);}
.badge.active{background:#f0fff4;color:var(--green);}

/* ── ACTION BUTTONS ── */
.act-btn{border:none;border-radius:7px;padding:5px 12px;font-size:0.77rem;cursor:pointer;font-weight:600;transition:all 0.2s;font-family:'Poppins',sans-serif;display:inline-flex;align-items:center;gap:5px;}
.act-btn.del{background:#fff0f0;color:var(--red);}
.act-btn.del:hover{background:var(--red);color:#fff;transform:scale(1.03);}
.act-btn.restore{background:#f0fff4;color:var(--green);}
.act-btn.restore:hover{background:var(--green);color:#fff;}
.act-btn.warn{background:#fff8f0;color:var(--orange);}
.act-btn.warn:hover{background:var(--orange);color:#fff;}

.empty{text-align:center;padding:50px 20px;color:#bbb;}
.empty i{font-size:2.5rem;display:block;margin-bottom:12px;}
.empty p{font-size:0.9rem;}
.loading{text-align:center;padding:40px;color:#888;}
.loading i{animation:spin 1s linear infinite;}

/* ── USER CARD ROW ── */
.user-avatar-sm{width:34px;height:34px;border-radius:50%;background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:0.9rem;flex-shrink:0;}
.user-avatar-sm.admin-av{background:linear-gradient(135deg,var(--admin-light),var(--admin));}

/* ── OVERLAY FOR MOBILE SIDEBAR ── */
.sidebar-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.4);z-index:150;}
.sidebar-overlay.show{display:block;}
.badge.community{background:#eff6ff;color:#2563eb;}
.badge.barangay{background:#f0fdf4;color:#166534;}
.badge.lgu{background:#f0f7ff;color:#0a3d62;}
.badge.first_responder{background:#fef2f2;color:#b91c1c;}
.badge.user{background:#ebf2ff;color:#2563eb;}

/* ── MOBILE ── */
@media(max-width:900px){
  .sidebar{transform:translateX(calc(-1 * var(--sidebar-w)));}
  .sidebar.mobile-open{transform:translateX(0);}
  .main{margin-left:0;}
  .stats-row{grid-template-columns:1fr 1fr;}
  .ham-btn{display:flex;}
  .tab-nav-btn span{display:none;}
  .tab-nav-btn{flex:none;padding:10px 14px;}
  .topbar{padding:12px 16px;}
  .content{padding:16px;}
  .filter-bar{gap:8px;}
  .filter-bar input{min-width:140px;}
  td,th{padding:10px 10px;}
}
@media(max-width:600px){
  .stats-row{grid-template-columns:1fr;}
  .stat-card{padding:16px 18px;}
  .panel{padding:16px;}
  .tab-nav{overflow-x:auto;}
}
.vuln-row{display:grid;grid-template-columns:repeat(5,minmax(160px,1fr));gap:16px;margin-top:18px;}
.vuln-card{background:#f8fbff;border:1px solid #e6effc;border-radius:16px;padding:18px;min-height:120px;display:flex;flex-direction:column;gap:10px;box-shadow:0 3px 18px rgba(15,23,42,0.05);}
.vuln-card strong{font-size:0.95rem;color:var(--text);}
.vuln-status{font-weight:700;font-size:0.95rem;}
.vuln-status.passed{color:#166534;}
.vuln-status.warning{color:#b45309;}
.vuln-status.critical{color:#b91c1c;}
.vuln-detail{font-size:0.8rem;color:#555;line-height:1.5;}
#vulnerabilityScore{letter-spacing:0.03em;}
.modal-overlay.vuln-modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,0.65);z-index:10000;align-items:center;justify-content:center;padding:18px;}
.modal-overlay.vuln-modal .modal-dialog{width:100%;max-width:1100px;}
.modal-overlay.vuln-modal .panel{border-radius:20px;overflow:hidden;}
.modal-overlay.vuln-modal .panel-header{padding:22px 24px;gap:14px;}
.modal-overlay.vuln-modal .panel .table-wrap{padding:0 24px 24px;}
.modal-overlay.vuln-modal .close-modal-btn{background:none;border:none;color:var(--muted);font-size:1.2rem;cursor:pointer;}
.modal-overlay.vuln-modal .close-modal-btn:hover{color:var(--text);}
@media(max-width:900px){
  .vuln-row{grid-template-columns:1fr 1fr;}
}
@media(max-width:640px){
  .vuln-row{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<div class="sidebar-overlay" id="overlay" onclick="closeSidebar()"></div>

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-header">
    <div class="brand-row">
      <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
      <div>
        <span class="brand-name">SenTri</span>
        <span class="admin-badge">ADMIN</span>
      </div>
    </div>
    <button class="toggle-btn" onclick="closeSidebar()"><i class="fas fa-xmark"></i></button>
  </div>

  <div style="margin-top:12px;">
    <div class="nav-section">Navigation</div>
    <nav class="menu">
      <button class="menu-item active" id="navOverview" onclick="showTab('overview')">
        <i class="fas fa-gauge"></i> Overview
      </button>
      <button class="menu-item" id="navUsers" onclick="showTab('users')">
        <i class="fas fa-users"></i> Manage Users
        <span class="menu-badge" id="userCount">—</span>
      </button>
      <button class="menu-item" id="navPending" onclick="showTab('pending')">
        <i class="fas fa-clock"></i> Pending Approvals
        <?php if($pending_count > 0): ?><span class="menu-badge" id="pendingNavBadge"><?= $pending_count ?></span><?php endif; ?>
      </button>
      <button class="menu-item" id="navPosts" onclick="showTab('posts')">
        <i class="fas fa-clipboard-list"></i> Manage Posts
      </button>
      <button class="menu-item" id="navAudit" onclick="showTab('audit')">
        <i class="fas fa-shield-halved"></i> Reports Audit
      </button>
      <button class="menu-item" id="navLogs" onclick="showTab('logs')">
        <i class="fas fa-scroll"></i> Login Logs
      </button>
      <button class="menu-item" id="navSecurity" onclick="showTab('security')">
        <i class="fas fa-shield-exclamation"></i> Security Monitor
      </button>
      <button class="menu-item" id="navContacts" onclick="showTab('contacts')">
        <i class="fas fa-address-book"></i> Emergency Contacts
      </button>
      <button class="menu-item" id="navVulnerabilities" onclick="openVulnerabilityModal()">
        <i class="fas fa-shield-exclamation"></i> Vulnerability Assessment
      </button>
    </nav>
    <div class="nav-section" style="margin-top:16px;">Quick Links</div>
    <nav class="menu">
      <a href="dashboard.php" class="menu-item">
        <i class="fas fa-house"></i> Community Feed
      </a>
    </nav>
  </div>

  <div class="sidebar-user">
    <div class="user-card">
      <div class="user-avatar">A</div>
      <div class="user-info-side">
        <div class="name"><?= htmlspecialchars($first_name) ?></div>
        <div class="role"><i class="fas fa-circle" style="font-size:0.5rem;color:#a78bfa;margin-right:4px;"></i>Administrator</div>
      </div>
      <a href="logout.php" class="logout-btn" title="Log Out"><i class="fas fa-right-from-bracket"></i></a>
    </div>
  </div>
</aside>

<!-- MAIN -->
<div class="main" id="main">
  <div class="topbar">
    <div class="topbar-left">
      <button class="ham-btn" onclick="openSidebar()"><i class="fas fa-bars"></i></button>
      <h1 id="pageTitle"><i class="fas fa-gauge" style="color:var(--blue);margin-right:8px;"></i>Admin Dashboard</h1>
    </div>
    <div class="topbar-right">
      <div class="live-dot" title="Live"></div>
      <div class="topbar-avatar">A</div>
      <span class="topbar-name"><?= htmlspecialchars($first_name) ?></span>
    </div>
  </div>

  <div class="content">

    <!-- STATS (always visible) -->
    <div class="stats-row" id="statsRow">
      <div class="stat-card blue">
        <div class="stat-icon blue"><i class="fas fa-users"></i></div>
        <div>
          <div class="stat-num"><?= $total_users ?></div>
          <div class="stat-label">Total Users</div>
        </div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon green"><i class="fas fa-clipboard-list"></i></div>
        <div>
          <div class="stat-num"><?= $total_reports ?></div>
          <div class="stat-label">Active Reports</div>
        </div>
      </div>
      <div class="stat-card red">
        <div class="stat-icon red"><i class="fas fa-circle-exclamation"></i></div>
        <div>
          <div class="stat-num"><?= $danger_count ?></div>
          <div class="stat-label">Danger Alerts</div>
        </div>
      </div>
      <div class="stat-card purple">
        <div class="stat-icon purple"><i class="fas fa-archive"></i></div>
        <div>
          <div class="stat-num"><?= $total_archived ?></div>
          <div class="stat-label">Archived Posts</div>
        </div>
      </div>
      <div class="stat-card" style="border-left:4px solid #d97706;">
        <div class="stat-icon" style="background:rgba(251,191,36,0.15);color:#d97706;"><i class="fas fa-clock"></i></div>
        <div>
          <div class="stat-num" id="pendingStatNum"><?= $pending_count ?></div>
          <div class="stat-label">Pending Approvals</div>
        </div>
      </div>
    </div>

    <!-- ── OVERVIEW TAB ── -->
    <div class="tab-panel active" id="tab-overview">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-chart-bar"></i> Recent Activity</div>
          <span style="font-size:0.8rem;color:var(--muted);">Last 10 reports</span>
        </div>
        <div class="table-wrap">
          <div class="loading" id="overviewLoading"><i class="fas fa-spinner"></i> Loading...</div>
          <table id="overviewTable" style="display:none;">
            <thead><tr><th>#</th><th>Title</th><th>Status</th><th>Category</th><th>Location</th><th>Posted By</th><th>Date</th></tr></thead>
            <tbody id="overviewBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── USERS TAB ── -->
    <div class="tab-panel" id="tab-users">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-users"></i> User Accounts</div>
          <span id="userTotal" style="font-size:0.8rem;color:var(--muted);"></span>
        </div>
        <div class="filter-bar">
          <input type="text" id="userSearch" placeholder="Search name or email...">
          <select id="userRoleFilter">
            <option value="">All Roles</option>
            <option value="community">Community</option>
            <option value="user">Legacy Members</option>
            <option value="barangay">Barangay</option>
            <option value="lgu">LGU</option>
            <option value="first_responder">First Responder</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="table-wrap">
          <div class="loading" id="usersLoading"><i class="fas fa-spinner"></i> Loading users...</div>
          <table id="usersTable" style="display:none;">
            <thead><tr><th>#</th><th>User</th><th>Email</th><th>Role</th><th>Joined</th><th>Reports</th><th>Actions</th></tr></thead>
            <tbody id="usersBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── POSTS TAB ── -->
    <div class="tab-panel" id="tab-posts">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-clipboard-list"></i> All Reports</div>
          <span style="font-size:0.8rem;color:var(--muted);">Manage & moderate posts</span>
        </div>
        <div class="filter-bar">
          <input type="text" id="postSearch" placeholder="Search title, city, poster...">
          <select id="postStatus">
            <option value="">All Statuses</option>
            <option value="dangerous">Dangerous</option>
            <option value="caution">Caution</option>
            <option value="safe">Safe</option>
          </select>
          <select id="postArchived">
            <option value="0">Active Only</option>
            <option value="1">Archived Only</option>
            <option value="">All</option>
          </select>
        </div>
        <div class="table-wrap">
          <div class="loading" id="postsLoading"><i class="fas fa-spinner"></i> Loading posts...</div>
          <table id="postsTable" style="display:none;">
            <thead><tr><th>#</th><th>Title</th><th>Status</th><th>Category</th><th>Location</th><th>Posted By</th><th>Votes</th><th>Date</th><th>Actions</th></tr></thead>
            <tbody id="postsBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── LOGS TAB ── -->
    <div class="tab-panel" id="tab-logs">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-scroll"></i> Login Logs</div>
          <span style="font-size:0.8rem;color:var(--muted);">Recent authentication events</span>
        </div>
        <div class="table-wrap">
          <div class="loading" id="logsLoading"><i class="fas fa-spinner"></i> Loading logs...</div>
          <table id="logsTable" style="display:none;">
            <thead><tr><th>#</th><th>Email</th><th>Status</th><th>IP Address</th><th>Device</th><th>Date / Time</th></tr></thead>
            <tbody id="logsBody"></tbody>
          </table>
          <div id="logsPaginationContainer" style="display:none;padding:16px;border-top:1px solid var(--border);background:#fafbfc;"></div>
        </div>
      </div>
    </div>

    <!-- ── SECURITY MONITOR TAB ── -->
<div class="tab-panel" id="tab-security">
  <div class="panel">
    <div class="panel-header">
      <div class="panel-title">
        <i class="fas fa-shield-exclamation"></i> Security Monitor
      </div>
      <span style="font-size:0.8rem;color:var(--muted);">Failed Login Attempts & Flagged Accounts</span>
    </div>

    <!-- Risk Summary -->
    <div class="stats-row" style="margin-bottom:20px; grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));">
      <div class="stat-card red">
        <div class="stat-icon red"><i class="fas fa-exclamation-triangle"></i></div>
        <div>
          <div class="stat-num" id="highRiskCount">0</div>
          <div class="stat-label">High Risk Accounts</div>
        </div>
      </div>
      <div class="stat-card orange">
        <div class="stat-icon" style="background:#fff7ed;color:#dd6b20;"><i class="fas fa-exclamation-circle"></i></div>
        <div>
          <div class="stat-num" id="mediumRiskCount">0</div>
          <div class="stat-label">Medium Risk</div>
        </div>
      </div>
      <div class="stat-card green">
        <div class="stat-icon green"><i class="fas fa-check-circle"></i></div>
        <div>
          <div class="stat-num" id="totalFlagged">0</div>
          <div class="stat-label">Total Flagged</div>
        </div>
      </div>
    </div>

    <div class="filter-bar">
      <input type="text" id="securitySearch" placeholder="Search email or IP..." style="flex:1;">
      <select id="riskFilter">
        <option value="">All Risk Levels</option>
        <option value="high">High Risk Only</option>
        <option value="medium">Medium Risk</option>
        <option value="normal">Normal</option>
      </select>
    </div>
    <div style="display:flex;align-items:center;gap:12px;margin:14px 0 8px;flex-wrap:wrap;">
      <button class="btn-action del" id="deleteSelectedFlagsBtn" onclick="deleteSelectedFlags()" disabled style="padding:10px 14px;"><i class="fas fa-trash"></i> Delete Selected</button>
      <button class="btn-action del" onclick="deleteAllFlagged()" style="padding:10px 14px;background:#f8fafc;color:#111;border:1px solid #cbd5e1;"><i class="fas fa-trash-can"></i> Delete All Flagged</button>
      <div id="securitySelectionSummary" style="color:var(--muted);font-size:0.9rem;">Selected 0 of 0 flagged accounts</div>
    </div>
    <div class="table-wrap">
      <div class="loading" id="securityLoading"><i class="fas fa-spinner"></i> Loading security data...</div>
      <table id="securityTable" style="display:none;">
        <thead>
          <tr>
            <th style="width:42px;"><input type="checkbox" id="securitySelectAll" onchange="toggleSecuritySelectAll(this)"></th>
            <th>#</th>
            <th>Email</th>
            <th>IP Address</th>
            <th>Failed Attempts (30min)</th>
            <th>Risk Level</th>
            <th>Last Attempt</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody id="securityBody"></tbody>
      </table>
      <div id="securityPaginationContainer" style="display:none;margin-top:14px;border-top:1px solid var(--border);padding-top:14px;"></div>
    </div>
  </div>
</div>

    <div class="modal-overlay vuln-modal" id="vulnerabilityModalOverlay" onclick="if(event.target===this) closeVulnerabilityModal()">
      <div class="modal-dialog">
        <div class="panel">
          <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:12px;">
            <div class="panel-title"><i class="fas fa-biohazard"></i> Vulnerability Assessment</div>
            <button class="close-modal-btn" onclick="closeVulnerabilityModal()" aria-label="Close"><i class="fas fa-xmark"></i></button>
          </div>
          <div class="filter-bar" style="align-items:flex-start; gap:16px; padding:0 24px 18px;">
            <div style="flex:1;min-width:220px;">
              <div style="font-size:0.82rem;color:var(--muted);margin-bottom:6px;">Last Scan Date</div>
              <div id="lastScanDate" style="font-weight:700;color:var(--text);">Never</div>
            </div>
            <div style="flex:1;min-width:220px;">
              <div style="font-size:0.82rem;color:var(--muted);margin-bottom:6px;">Overall Security Score</div>
              <div id="vulnerabilityScore" style="font-size:1.8rem;font-weight:800;color:var(--blue);">0/100</div>
            </div>
            <button class="btn-action approve" onclick="runVulnerabilityAssessment()" style="margin-top:8px;"><i class="fas fa-play"></i> Run Assessment</button>
          </div>
          <div class="vuln-row" id="vulnSummaryRow" style="padding:0 24px 18px;">
            <div class="vuln-card" id="vulnCardHttps"><strong>HTTPS</strong><div class="vuln-status">Pending</div><div class="vuln-detail">Check if HTTPS is enabled.</div></div>
            <div class="vuln-card" id="vulnCardSession"><strong>Session Security</strong><div class="vuln-status">Pending</div><div class="vuln-detail">Inspect session cookie settings.</div></div>
            <div class="vuln-card" id="vulnCardPassword"><strong>Password Hashing</strong><div class="vuln-status">Pending</div><div class="vuln-detail">Verify bcrypt/password_hash usage.</div></div>
            <div class="vuln-card" id="vulnCardHeaders"><strong>Security Headers</strong><div class="vuln-status">Pending</div><div class="vuln-detail">Check standard response headers.</div></div>
            <div class="vuln-card" id="vulnCardUpload"><strong>Upload Restrictions</strong><div class="vuln-status">Pending</div><div class="vuln-detail">Confirm file upload restrictions.</div></div>
          </div>
          <div class="table-wrap" style="margin-top:0;padding:0 24px 24px;">
            <table id="vulnHistoryTable" style="display:none; width:100%;">
              <thead>
                <tr><th>#</th><th>Date</th><th>Score</th><th>HTTPS</th><th>Session</th><th>Password</th><th>Headers</th><th>Uploads</th></tr>
              </thead>
              <tbody id="vulnHistoryBody"></tbody>
            </table>
            <div class="loading" id="vulnHistoryLoading"><i class="fas fa-spinner"></i> Loading vulnerability history...</div>
          </div>
        </div>
      </div>
    </div>

    <div class="tab-panel" id="tab-contacts">
      <div class="panel">
        <div class="panel-header" style="display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;">
          <div class="panel-title"><i class="fas fa-address-book"></i> Emergency Contacts</div>
          <button class="btn-action approve" onclick="openContactModal()"><i class="fas fa-plus"></i> Add Contact</button>
        </div>
        <div style="margin-bottom:14px;display:flex;gap:10px;flex-wrap:wrap;">
          <input type="text" id="contactSearch" placeholder="Search by name, city..." onkeyup="renderContacts()" style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.87rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;flex:1;min-width:180px;outline:none;">
          <select id="contactTypeFilter" onchange="renderContacts()" style="padding:9px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.87rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;">
            <option value="">All Types</option>
            <option value="lgu">LGU</option>
            <option value="hospital">Hospital</option>
            <option value="traffic">Traffic Mgt.</option>
            <option value="police">Police</option>
            <option value="fire">Fire</option>
            <option value="barangay">Barangay</option>
            <option value="other">Other</option>
          </select>
        </div>
        <div class="table-wrap">
          <div class="loading" id="contactsLoading"><i class="fas fa-spinner"></i> Loading contacts...</div>
          <table id="contactsTable" style="display:none;">
            <thead><tr><th>#</th><th>Name</th><th>Type</th><th>Barangay</th><th>City</th><th>Phone</th><th>Email</th><th>Status</th><th>Actions</th></tr></thead>
            <tbody id="contactsBody"></tbody>
          </table>
        </div>
      </div>
    </div>


    <!-- ── AUDIT TAB ── -->
    <div class="tab-panel" id="tab-audit">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-shield-halved"></i> Reports Audit Log</div>
          <span style="font-size:0.8rem;color:var(--muted);">All archive / restore actions</span>
        </div>
        <div class="filter-bar">
          <input type="text" id="auditSearch" placeholder="Search title or admin name...">
          <select id="auditAction">
            <option value="">All Actions</option>
            <option value="archived">Archived</option>
            <option value="restored">Restored</option>
          </select>
        </div>
        <div class="table-wrap">
          <div class="loading" id="auditLoading"><i class="fas fa-spinner"></i> Loading audit log...</div>
          <table id="auditTable" style="display:none;">
            <thead>
              <tr>
                <th>#</th>
                <th>Report</th>
                <th>Action</th>
                <th>Performed By</th>
                <th>Date / Time</th>
              </tr>
            </thead>
            <tbody id="auditBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- ── PENDING APPROVALS TAB ── -->
    <div class="tab-panel" id="tab-pending">
      <div class="panel">
        <div class="panel-header">
          <div class="panel-title"><i class="fas fa-clock"></i> Pending Official Account Approvals</div>
          <span style="font-size:0.8rem;color:var(--muted);">Official accounts awaiting verification</span>
        </div>
        <div class="table-wrap">
          <div class="loading" id="pendingLoading"><i class="fas fa-spinner"></i> Loading...</div>
          <div id="pendingEmpty" style="display:none;padding:48px;text-align:center;color:var(--muted);"><i class="fas fa-check-circle" style="font-size:2rem;display:block;margin-bottom:10px;color:#16a34a;opacity:0.5;"></i>No pending approvals.</div>
          <table id="pendingTable" style="display:none;">
            <thead><tr><th>#</th><th>Applicant</th><th>Email</th><th>Role</th><th>Office / Unit</th><th>Position</th><th>Jurisdiction</th><th>Applied</th><th>Actions</th></tr></thead>
            <tbody id="pendingBody"></tbody>
          </table>
        </div>
      </div>
    </div>

    <!-- CONTACT MODAL -->
    <div class="modal-overlay" id="contactModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;display:none;align-items:center;justify-content:center;padding:20px;">
      <div style="background:var(--card);border-radius:16px;padding:28px;width:100%;max-width:520px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
          <h3 id="contactModalTitle" style="font-size:1.05rem;font-weight:700;"><i class="fas fa-address-book" style="color:var(--blue);margin-right:8px;"></i>Add Emergency Contact</h3>
          <button onclick="closeContactModal()" style="background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--muted);"><i class="fas fa-xmark"></i></button>
        </div>
        <div id="contactModalMsg" style="display:none;padding:10px 14px;border-radius:8px;font-size:0.84rem;margin-bottom:14px;"></div>
        <input type="hidden" id="c_id">
        <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;">
          <div style="grid-column:1/-1;">
            <label style="font-size:0.8rem;font-weight:600;display:block;margin-bottom:5px;">Office / Agency Name *</label>
            <input id="c_name" type="text" placeholder="e.g. Imus City Health Office" maxlength="255" style="width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.88rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;outline:none;">
          </div>
          <div>
            <label style="font-size:0.8rem;font-weight:600;display:block;margin-bottom:5px;">Type *</label>
            <select id="c_type" style="width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.88rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;">
              <option value="lgu">LGU</option>
              <option value="hospital">Hospital</option>
              <option value="traffic">Traffic Mgt.</option>
              <option value="police">Police</option>
              <option value="fire">Fire</option>
              <option value="barangay">Barangay</option>
              <option value="other">Other</option>
            </select>
          </div>
          <div>
            <label style="font-size:0.8rem;font-weight:600;display:block;margin-bottom:5px;">Barangay <span style="font-weight:400;color:var(--muted);">(blank = city-wide)</span></label>
            <input id="c_barangay" type="text" placeholder="Optional" maxlength="150" style="width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.88rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;outline:none;">
          </div>
          <div>
            <label style="font-size:0.8rem;font-weight:600;display:block;margin-bottom:5px;">City / Municipality *</label>
            <input id="c_city" type="text" placeholder="e.g. Imus" maxlength="150" style="width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.88rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;outline:none;">
          </div>
          <div>
            <label style="font-size:0.8rem;font-weight:600;display:block;margin-bottom:5px;">Province</label>
            <input id="c_province" type="text" placeholder="e.g. Cavite" maxlength="150" style="width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.88rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;outline:none;">
          </div>
          <div>
            <label style="font-size:0.8rem;font-weight:600;display:block;margin-bottom:5px;">Contact Number</label>
            <input id="c_phone" type="text" placeholder="e.g. (046) 471-0100" maxlength="50" style="width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.88rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;outline:none;">
          </div>
          <div style="grid-column:1/-1;">
            <label style="font-size:0.8rem;font-weight:600;display:block;margin-bottom:5px;">Contact Email</label>
            <input id="c_email" type="email" placeholder="office@example.gov.ph" maxlength="191" style="width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.88rem;background:var(--card);color:var(--text);font-family:'Poppins',sans-serif;outline:none;">
          </div>
          <div style="grid-column:1/-1;display:flex;align-items:center;gap:10px;">
            <input type="checkbox" id="c_active" checked style="width:16px;height:16px;accent-color:var(--blue);">
            <label for="c_active" style="font-size:0.85rem;font-weight:500;cursor:pointer;">Active (receives notifications)</label>
          </div>
        </div>
        <div style="display:flex;gap:10px;justify-content:flex-end;margin-top:4px;">
          <button onclick="closeContactModal()" style="padding:11px 22px;border:1.5px solid var(--border);background:var(--card);border-radius:10px;font-size:0.9rem;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:500;color:var(--text);">Cancel</button>
          <button onclick="saveContact()" id="contactSaveBtn" style="padding:11px 22px;background:linear-gradient(135deg,var(--blue-light,#3a8dff),var(--blue,#1c57b2));color:#fff;border:none;border-radius:10px;font-size:0.9rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;"><i class="fas fa-floppy-disk"></i> Save</button>
        </div>
      </div>
    </div>

    <!-- DELETE CONFIRMATION MODAL -->
    <div class="modal-overlay" id="deleteModalOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.55);z-index:9999;align-items:center;justify-content:center;padding:20px;">
      <div style="background:var(--card);border-radius:16px;padding:28px;width:100%;max-width:420px;box-shadow:0 20px 60px rgba(0,0,0,0.3);">
        <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:18px;">
          <h3 style="font-size:1.05rem;font-weight:700;"><i class="fas fa-trash" style="color:#dc2626;margin-right:8px;"></i>Confirm Delete</h3>
          <button onclick="closeDeleteModal()" style="background:none;border:none;font-size:1.1rem;cursor:pointer;color:var(--muted);"><i class="fas fa-xmark"></i></button>
        </div>
        <div id="deleteModalMessage" style="margin-bottom:18px;color:var(--text);line-height:1.6;">Are you sure you want to delete this flagged account? This action cannot be undone.</div>
        <div style="display:flex;gap:10px;justify-content:flex-end;">
          <button onclick="closeDeleteModal()" style="padding:11px 22px;border:1.5px solid var(--border);background:var(--card);border-radius:10px;font-size:0.9rem;cursor:pointer;font-family:'Poppins',sans-serif;font-weight:500;color:var(--text);">Cancel</button>
          <button onclick="confirmDeleteFlag()" id="confirmDeleteBtn" style="padding:11px 22px;background:#dc2626;color:#fff;border:none;border-radius:10px;font-size:0.9rem;font-weight:600;cursor:pointer;font-family:'Poppins',sans-serif;">Delete</button>
        </div>
      </div>
    </div>

  </div><!-- /content -->
</div><!-- /main -->

<script>
let allReports=[], allUsers=[], allLogs=[], allContacts=[], allSecurityScans=[];
let currentTab='overview';
let currentLogsPage = 1;
const logsItemsPerPage = 10;

// ── Sidebar ──────────────────────────────────────────────────
function openSidebar(){
  document.getElementById('sidebar').classList.add('mobile-open');
  document.getElementById('overlay').classList.add('show');
  document.body.style.overflow='hidden';
}
function closeSidebar(){
  document.getElementById('sidebar').classList.remove('mobile-open');
  document.getElementById('overlay').classList.remove('show');
  document.body.style.overflow='';
}

// ── Tab navigation ────────────────────────────────────────────
const tabTitles = {
  overview:'<i class="fas fa-gauge" style="color:var(--blue);margin-right:8px;"></i>Overview',
  users:'<i class="fas fa-users" style="color:var(--blue);margin-right:8px;"></i>Manage Users',
  pending:'<i class="fas fa-clock" style="color:#d97706;margin-right:8px;"></i>Pending Approvals',
  posts:'<i class="fas fa-clipboard-list" style="color:var(--blue);margin-right:8px;"></i>Manage Posts',
  audit:'<i class="fas fa-shield-halved" style="color:var(--blue);margin-right:8px;"></i>Reports Audit',
  logs:'<i class="fas fa-scroll" style="color:var(--blue);margin-right:8px;"></i>Login Logs',
  security:'<i class="fas fa-shield-exclamation" style="color:var(--red);margin-right:8px;"></i>Security Monitor',
  contacts:'<i class="fas fa-address-book" style="color:var(--blue);margin-right:8px;"></i>Emergency Contacts',
};
let pendingLoaded = false;
function showTab(name){
  currentTab=name;
  document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
  document.querySelectorAll('.menu-item').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+name).classList.add('active');
  document.getElementById('nav'+name.charAt(0).toUpperCase()+name.slice(1))?.classList.add('active');
  document.getElementById('pageTitle').innerHTML=tabTitles[name];
  
  // Lazy load
  if(name==='users'    && allUsers.length===0)    loadUsers();
  if(name==='posts')                              loadReports();
  if(name==='pending'  && !pendingLoaded)          loadPending();
  if(name==='audit')                               loadAuditLogs();
  if(name==='logs'     && allLogs.length===0)     loadLogs();
  if(name==='security')                           loadSecurityMonitor();
  if(name==='vulnerabilities')                    loadVulnerabilities();
  if(name==='contacts' && allContacts.length===0) loadContacts();
  
  // ←←← ADD THIS LINE FOR SECURITY MONITOR
  if(name==='security') loadSecurityMonitor();

  if(window.innerWidth<=900) closeSidebar();
}

// ── OVERVIEW ─────────────────────────────────────────────────
async function loadOverview(){
  const res=await fetch('api/reports.php?action=admin_get_reports');
  const data=await res.json();
  if(data.status==='success'){
    allReports=data.reports;
    const body=document.getElementById('overviewBody');
    const top10=allReports.filter(r=>!r.is_archived).slice(0,10);
    body.innerHTML=top10.map(r=>{
      const date=new Date(r.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
      return `<tr>
        <td style="color:#aaa;">${r.id}</td>
        <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.title)}">${esc(r.title)}</td>
        <td><span class="badge ${r.status}">${r.status}</span></td>
        <td style="color:var(--muted);">${r.category}</td>
        <td>${esc(r.location_name)}<br><small style="color:#aaa;">${esc(r.city)}</small></td>
        <td>${esc(r.poster_name)}</td>
        <td style="color:#888;font-size:0.78rem;">${date}</td>
      </tr>`;
    }).join('');
    document.getElementById('overviewLoading').style.display='none';
    document.getElementById('overviewTable').style.display='table';
  }
}

// ── USERS ─────────────────────────────────────────────────────
async function loadUsers(){
  const res=await fetch('api/reports.php?action=admin_get_users');
  const data=await res.json();
  if(data.status==='success'){
    allUsers=data.users;
    document.getElementById('userCount').textContent=allUsers.length+' accounts';
    document.getElementById('userTotal').textContent=allUsers.length+' total accounts';
    document.getElementById('userCount').textContent=allUsers.length;
    renderUsers();
    document.getElementById('usersLoading').style.display='none';
    document.getElementById('usersTable').style.display='table';
  }
}
function renderUsers(){
  const search=document.getElementById('userSearch').value.toLowerCase();
  const role=document.getElementById('userRoleFilter').value;
  let list=allUsers.filter(u=>{
    if(role && u.role!==role) return false;
    if(search){const hay=(u.first_name+' '+u.last_name+u.email).toLowerCase(); if(!hay.includes(search)) return false;}
    return true;
  });
  const body=document.getElementById('usersBody');
  if(list.length===0){body.innerHTML='<tr><td colspan="7" class="empty"><i class="fas fa-users-slash"></i><p>No users match your filters.</p></td></tr>';return;}
  body.innerHTML=list.map(u=>{
    const date=new Date(u.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
    const initials=(u.first_name[0]||'?').toUpperCase();
    const isPending = u.is_approved===0 && u.role!=='community' && u.role!=='admin';
    const roleLabel = u.role.replace('_',' ');
    const orgInfo = u.org_name ? `<div style="font-size:0.72rem;color:#888;">${esc(u.org_name)}</div>` : '';
    return `<tr id="urow_${u.id}" ${isPending?'style="background:#fffbeb;"':''}>
      <td style="color:#aaa;">${u.id}</td>
      <td><div style="display:flex;align-items:center;gap:10px;">
        <div class="user-avatar-sm ${u.role==='admin'?'admin-av':''}">${initials}</div>
        <div><div style="font-weight:600;">${esc(u.first_name)} ${esc(u.last_name)}</div>${orgInfo}${isPending?'<span style="font-size:0.68rem;background:#fef9ec;color:#92400e;border:1px solid #fde68a;padding:1px 7px;border-radius:10px;font-weight:700;margin-top:3px;display:inline-block;">PENDING APPROVAL</span>':''}</div>
      </div></td>
      <td style="color:var(--muted);">${esc(u.email)}</td>
      <td><span class="badge ${u.role}">${roleLabel}</span></td>
      <td style="color:#888;font-size:0.78rem;">${date}</td>
      <td style="text-align:center;">${u.report_count}</td>
      <td><div style="display:flex;gap:6px;flex-wrap:wrap;">
        ${isPending ? `<button class="act-btn" style="background:#16a34a;color:#fff;border:none;padding:5px 10px;font-size:0.75rem;" onclick="approveUser(${u.id})"><i class="fas fa-check"></i> Approve</button><button class="act-btn del" style="font-size:0.75rem;" onclick="rejectUser(${u.id},'${esc(u.first_name)}')"><i class="fas fa-xmark"></i> Reject</button>` : ''}
        ${u.role!=='admin'&&!isPending ? `<button class="act-btn del" onclick="deleteUser(${u.id},'${esc(u.first_name)} ${esc(u.last_name)}')"><i class="fas fa-trash-can"></i> Remove</button>` : ''}
        ${u.role==='admin' ? '<span style="color:#aaa;font-size:0.78rem;">Protected</span>' : ''}
      </div></td>
    </tr>`;
  }).join('');
}
async function approveUser(uid){
  const fd=new FormData(); fd.append('action','admin_approve_user'); fd.append('user_id',uid);
  const res=await fetch('api/reports.php',{method:'POST',body:fd});
  const data=await res.json();
  if(data.status==='success'){ const u=allUsers.find(x=>x.id==uid); if(u){u.is_approved=1;} renderUsers(); }
  else alert(data.message||'Failed.');
}
async function rejectUser(uid, name){
  if(!confirm(`Reject and remove account for "${name}"? This cannot be undone.`)) return;
  const fd=new FormData(); fd.append('action','admin_reject_user'); fd.append('user_id',uid);
  const res=await fetch('api/reports.php',{method:'POST',body:fd});
  const data=await res.json();
  if(data.status==='success'){ allUsers=allUsers.filter(u=>u.id!=uid); renderUsers(); }
  else alert(data.message||'Failed.');
}
async function deleteUser(uid, name){
  if(!confirm(`Remove user "${name}"?\n\nThis will delete their account and all their reports. This cannot be undone.`)) return;
  const fd=new FormData(); fd.append('action','admin_delete_user'); fd.append('user_id',uid);
  const res=await fetch('api/reports.php',{method:'POST',body:fd});
  const data=await res.json();
  if(data.status==='success'){
    allUsers=allUsers.filter(u=>u.id!=uid);
    renderUsers();
    document.querySelector('.stat-num').textContent=allUsers.length;
  } else alert(data.message||'Failed to delete user.');
}
document.getElementById('userSearch').addEventListener('input',renderUsers);
document.getElementById('userRoleFilter').addEventListener('change',renderUsers);

// ── PENDING APPROVALS ─────────────────────────────────────────
async function loadPending(){
  pendingLoaded=true;
  const res=await fetch('api/reports.php?action=admin_get_users');
  const data=await res.json();
  document.getElementById('pendingLoading').style.display='none';
  if(data.status!=='success') return;
  const pending=data.users.filter(u=>u.is_approved===0 && u.role!=='community' && u.role!=='user' && u.role!=='admin');
  // Update badge
  const badge=document.getElementById('pendingNavBadge');
  const stat=document.getElementById('pendingStatNum');
  if(stat) stat.textContent=pending.length;
  if(badge){ if(pending.length>0){badge.textContent=pending.length;badge.style.display='';}else badge.style.display='none'; }
  if(pending.length===0){ document.getElementById('pendingEmpty').style.display='block'; return; }
  document.getElementById('pendingTable').style.display='table';
  document.getElementById('pendingBody').innerHTML=pending.map(u=>{
    const date=new Date(u.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
    const initials=(u.first_name[0]||'?').toUpperCase();
    const roleLabel=u.role.replace('_',' ');
    const roleBg={'barangay':'#f0fdf4','lgu':'#f0f7ff','first_responder':'#fef2f2'}[u.role]||'#f5f3ff';
    const roleClr={'barangay':'#166534','lgu':'#0a3d62','first_responder':'#b91c1c'}[u.role]||'#7c3aed';
    return `<tr id="prow_${u.id}">
      <td style="color:#aaa;font-size:0.78rem;">${u.id}</td>
      <td><div style="display:flex;align-items:center;gap:9px;">
        <div style="width:32px;height:32px;border-radius:50%;background:#f0f7ff;color:#0a3d62;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:0.82rem;flex-shrink:0;">${initials}</div>
        <div><div style="font-weight:700;font-size:0.85rem;">${esc(u.first_name)} ${esc(u.last_name)}</div></div>
      </div></td>
      <td style="color:var(--muted);font-size:0.82rem;">${esc(u.email)}</td>
      <td><span style="background:${roleBg};color:${roleClr};padding:3px 9px;border-radius:20px;font-size:0.72rem;font-weight:700;text-transform:capitalize;">${roleLabel}</span></td>
      <td style="font-size:0.82rem;">${esc(u.org_name||'—')}</td>
      <td style="font-size:0.82rem;">${esc(u.position||'—')}</td>
      <td style="font-size:0.82rem;">${esc((u.barangay_name||'')+(u.municipality?', '+u.municipality:''))}</td>
      <td style="font-size:0.78rem;color:var(--muted);">${date}</td>
      <td>
        <div style="display:flex;gap:6px;">
          <button class="btn-action approve" onclick="approvePending(${u.id})"><i class="fas fa-check"></i> Approve</button>
          <button class="btn-action" style="background:#fef2f2;color:#b91c1c;border:1px solid #fecaca;" onclick="rejectPending(${u.id},'${esc(u.first_name)}')"><i class="fas fa-xmark"></i> Reject</button>
        </div>
      </td>
    </tr>`;
  }).join('');
}
async function approvePending(uid){
  const fd=new FormData(); fd.append('action','admin_approve_user'); fd.append('user_id',uid);
  const data=await (await fetch('api/reports.php',{method:'POST',body:fd})).json();
  if(data.status==='success'){
    const row=document.getElementById('prow_'+uid); if(row) row.remove();
    const remaining=document.querySelectorAll('[id^="prow_"]').length;
    const stat=document.getElementById('pendingStatNum'); if(stat) stat.textContent=remaining;
    const badge=document.getElementById('pendingNavBadge'); if(badge){ if(remaining>0) badge.textContent=remaining; else badge.style.display='none'; }
    if(remaining===0){ document.getElementById('pendingTable').style.display='none'; document.getElementById('pendingEmpty').style.display='block'; }
    // refresh user list if loaded
    if(allUsers.length>0){ const u=allUsers.find(x=>x.id==uid); if(u) u.is_approved=1; renderUsers(); }
  } else alert(data.message||'Failed.');
}
async function rejectPending(uid,name){
  if(!confirm('Reject and permanently delete the account for "'+name+'"?')) return;
  const fd=new FormData(); fd.append('action','admin_reject_user'); fd.append('user_id',uid);
  const data=await (await fetch('api/reports.php',{method:'POST',body:fd})).json();
  if(data.status==='success'){
    const row=document.getElementById('prow_'+uid); if(row) row.remove();
    const remaining=document.querySelectorAll('[id^="prow_"]').length;
    const stat=document.getElementById('pendingStatNum'); if(stat) stat.textContent=remaining;
    const badge=document.getElementById('pendingNavBadge'); if(badge){ if(remaining>0) badge.textContent=remaining; else badge.style.display='none'; }
    if(remaining===0){ document.getElementById('pendingTable').style.display='none'; document.getElementById('pendingEmpty').style.display='block'; }
    allUsers=allUsers.filter(u=>u.id!=uid); renderUsers();
  } else alert(data.message||'Failed.');
}

// ── POSTS ─────────────────────────────────────────────────────
async function loadReports(){
  document.getElementById('postsLoading').style.display='block';
  document.getElementById('postsTable').style.display='none';
  if(allReports.length>0){
    renderPosts();
    document.getElementById('postsLoading').style.display='none';
    document.getElementById('postsTable').style.display='table';
    return;
  }
  try {
    const res=await fetch('api/reports.php?action=admin_get_reports');
    const data=await res.json();
    if(data.status==='success'){
      allReports=data.reports;
      renderPosts();
      document.getElementById('postsLoading').style.display='none';
      document.getElementById('postsTable').style.display='table';
      return;
    }
  } catch (error) {
    console.error('Failed to load reports:', error);
  }
  document.getElementById('postsLoading').style.display='none';
  document.getElementById('postsTable').style.display='table';
  document.getElementById('postsBody').innerHTML = '<tr><td colspan="9"><div class="empty"><i class="fas fa-exclamation-triangle"></i><p>Unable to load posts. Please refresh the page.</p></div></td></tr>';
}
function renderPosts(){
  const search=document.getElementById('postSearch').value.toLowerCase();
  const status=document.getElementById('postStatus').value;
  const arc=document.getElementById('postArchived').value;
  let list=allReports.filter(r=>{
    if(status && r.status!==status) return false;
    if(arc!=='' && r.is_archived!=parseInt(arc)) return false;
    if(search){const hay=(r.title+r.city+r.location_name+r.poster_name).toLowerCase(); if(!hay.includes(search)) return false;}
    return true;
  });
  const body=document.getElementById('postsBody');
  if(list.length===0){
    body.innerHTML='<tr><td colspan="9"><div class="empty"><i class="fas fa-binoculars"></i><p>No posts match current filters.</p></div></td></tr>';
    document.getElementById('postsLoading').style.display='none';
    document.getElementById('postsTable').style.display='table';
    return;
  }
  body.innerHTML=list.map(r=>{
    const arc=r.is_archived==1;
    const date=new Date(r.created_at).toLocaleDateString('en-PH',{year:'numeric',month:'short',day:'numeric'});
    return `<tr>
      <td style="color:#aaa;">${r.id}</td>
      <td style="max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(r.title)}">${esc(r.title)}</td>
      <td><span class="badge ${arc?'archived':r.status}">${arc?'archived':r.status}</span></td>
      <td style="color:var(--muted);">${r.category}</td>
      <td>${esc(r.location_name)}<br><small style="color:#aaa;">${esc(r.city)}</small></td>
      <td>${esc(r.poster_name)}</td>
      <td><span style="color:var(--green);">▲${r.upvotes}</span> <span style="color:var(--red);">▼${r.downvotes}</span></td>
      <td style="color:#888;font-size:0.78rem;white-space:nowrap;">${date}</td>
      <td style="white-space:nowrap;">
        ${arc
          ? `<button class="act-btn restore" onclick="postAction(${r.id},'restore')"><i class="fas fa-rotate-left"></i> Restore</button>`
          : `<button class="act-btn del" onclick="postAction(${r.id},'delete')"><i class="fas fa-archive"></i> Archive</button>`
        }
      </td>
    </tr>`;
  }).join('');
}
async function postAction(id, type){
  if(type==='delete'&&!confirm('Archive this report?')) return;
  if(type==='restore'&&!confirm('Restore this report?')) return;
  const fd=new FormData();
  fd.append('action',type==='delete'?'delete_report':'restore_report');
  fd.append('report_id',id);
  const res=await fetch('api/reports.php',{method:'POST',body:fd});
  const data=await res.json();
  if(data.status==='success'){
    const r=allReports.find(x=>x.id==id);
    if(r) r.is_archived=type==='delete'?1:0;
    renderPosts();
  } else alert(data.message||'Action failed.');
}
document.getElementById('postSearch').addEventListener('input',renderPosts);
document.getElementById('postStatus').addEventListener('change',renderPosts);
document.getElementById('postArchived').addEventListener('change',renderPosts);

// ── LOGS ─────────────────────────────────────────────────────
async function loadLogs(){
  currentLogsPage = 1;
  document.getElementById('logsLoading').style.display='block';
  document.getElementById('logsTable').style.display='none';
  document.getElementById('logsPaginationContainer').style.display='none';
  
  try{
    const res=await fetch('api/reports.php?action=admin_get_logs');
    const data=await res.json();
    if(data.status==='success'){
      allLogs=data.logs;
      renderLogs();
    }
  }catch(e){
    console.error('Logs load error',e);
  }
  
  document.getElementById('logsLoading').style.display='none';
  document.getElementById('logsTable').style.display='table';
}

function renderLogs(){
  if(allLogs.length===0){
    document.getElementById('logsBody').innerHTML='<tr><td colspan="6" class="empty"><i class="fas fa-inbox"></i><p>No login logs available.</p></td></tr>';
    document.getElementById('logsPaginationContainer').style.display='none';
    return;
  }
  
  const totalPages = Math.ceil(allLogs.length / logsItemsPerPage);
  if(currentLogsPage > totalPages) currentLogsPage = totalPages;
  if(currentLogsPage < 1) currentLogsPage = 1;
  
  const start = (currentLogsPage - 1) * logsItemsPerPage;
  const end = start + logsItemsPerPage;
  const pageData = allLogs.slice(start, end);
  
  const body = document.getElementById('logsBody');
  body.innerHTML = pageData.map((l, i) => {
    const date = new Date(l.created_at).toLocaleString('en-PH');
    const ok = l.status === 'Success';
    const globalIndex = start + i + 1;
    return `<tr>
      <td style="color:#aaa;">${globalIndex}</td>
      <td style="font-weight:500;">${esc(l.email)}</td>
      <td><span class="badge ${ok?'safe':'dangerous'}">${ok?'✓ Success':'✗ Failed'}</span></td>
      <td style="color:var(--muted);font-size:0.8rem;">${esc(l.ip_address||'—')}</td>
      <td style="color:var(--muted);font-size:0.75rem;max-width:180px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;" title="${esc(l.device||'')}">
        ${(l.device||'—').substring(0,40)}${l.device?.length>40?'…':''}
      </td>
      <td style="color:#888;font-size:0.78rem;white-space:nowrap;">${date}</td>
    </tr>`;
  }).join('');
  
  renderLogsPagination(totalPages, allLogs.length);
}

function renderLogsPagination(totalPages, totalItems){
  const container = document.getElementById('logsPaginationContainer');
  if(!container) return;
  
  if(totalPages <= 1){
    container.style.display='none';
    return;
  }
  
  container.style.display='flex';
  let pageLinks = [];
  if(totalPages <= 5){
    pageLinks = Array.from({ length: totalPages }, (_, i) => i + 1);
  } else {
    pageLinks.push(1);
    if(currentLogsPage > 3) pageLinks.push('...');
    for(let i = Math.max(2, currentLogsPage - 1); i <= Math.min(totalPages - 1, currentLogsPage + 1); i++){
      if(!pageLinks.includes(i)) pageLinks.push(i);
    }
    if(currentLogsPage < totalPages - 2) pageLinks.push('...');
    pageLinks.push(totalPages);
  }
  
  const paginationHtml = `
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;">
      <button class="btn-action" onclick="goToLogsPage(1)" title="First page" style="padding:8px 10px;font-size:0.8rem;" ${currentLogsPage === 1 ? 'disabled' : ''}><i class="fas fa-angles-left"></i></button>
      <button class="btn-action" onclick="goToLogsPage(${currentLogsPage - 1})" title="Previous page" style="padding:8px 10px;font-size:0.8rem;" ${currentLogsPage === 1 ? 'disabled' : ''}><i class="fas fa-angle-left"></i></button>
      
      <div style="display:flex;gap:4px;align-items:center;">
        ${pageLinks.map(page => {
          if(page === '...') return `<span style="color:var(--muted);">...</span>`;
          return `<button class="btn-action" onclick="goToLogsPage(${page})" style="padding:6px 10px;font-size:0.8rem;${currentLogsPage === page ? 'background:var(--blue);color:#fff;' : ''}">${page}</button>`;
        }).join('')}
      </div>
      
      <button class="btn-action" onclick="goToLogsPage(${currentLogsPage + 1})" title="Next page" style="padding:8px 10px;font-size:0.8rem;" ${currentLogsPage === totalPages ? 'disabled' : ''}><i class="fas fa-angle-right"></i></button>
      <button class="btn-action" onclick="goToLogsPage(${totalPages})" title="Last page" style="padding:8px 10px;font-size:0.8rem;" ${currentLogsPage === totalPages ? 'disabled' : ''}><i class="fas fa-angles-right"></i></button>
      
      <span style="color:var(--muted);font-size:0.85rem;margin-left:12px;white-space:nowrap;">Page ${currentLogsPage} of ${totalPages} (${totalItems} total)</span>
    </div>
  `;
  container.innerHTML = paginationHtml;
}

function goToLogsPage(page){
  currentLogsPage = page;
  renderLogs();
  const table = document.getElementById('logsTable');
  if(table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function esc(s){if(!s)return'';return s.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');}

// ══════════════════════════════════════════════════════════════
// EMERGENCY CONTACTS
// ══════════════════════════════════════════════════════════════
const TYPE_LABELS = {
  lgu:'LGU', hospital:'Hospital', traffic:'Traffic Mgt.',
  police:'Police', fire:'Fire', barangay:'Barangay', other:'Other'
};
const TYPE_COLORS = {
  lgu:'#1c57b2', hospital:'#e53e3e', traffic:'#dd6b20',
  police:'#2d6a2d', fire:'#c05621', barangay:'#6b46c1', other:'#718096'
};

async function loadContacts(){
  document.getElementById('contactsLoading').style.display='flex';
  document.getElementById('contactsTable').style.display='none';
  try{
    const res  = await fetch('api/contacts.php?action=admin_list');
    const data = await res.json();
    if(data.status==='success'){
      allContacts = data.contacts;
      renderContacts();
    }
  }catch(e){ console.error('Contacts load error',e); }
  document.getElementById('contactsLoading').style.display='none';
  document.getElementById('contactsTable').style.display='';
}

function renderContacts(){
  const search = (document.getElementById('contactSearch')?.value||'').toLowerCase();
  const typeF  = document.getElementById('contactTypeFilter')?.value||'';
  let rows = allContacts.filter(c=>{
    if(typeF && c.type!==typeF) return false;
    if(search){
      const hay=(c.name+c.city+(c.barangay||'')+(c.contact_email||'')+(c.contact_number||'')).toLowerCase();
      if(!hay.includes(search)) return false;
    }
    return true;
  });
  const body = document.getElementById('contactsBody');
  if(!rows.length){
    body.innerHTML=`<tr><td colspan="9" style="text-align:center;color:var(--muted);padding:28px;">No contacts found.</td></tr>`;
    return;
  }
  const col = c => `background:${TYPE_COLORS[c.type]||'#718096'}1a;color:${TYPE_COLORS[c.type]||'#718096'};padding:3px 10px;border-radius:50px;font-size:0.72rem;font-weight:700;`;
  body.innerHTML = rows.map((c,i)=>`
    <tr>
      <td>${i+1}</td>
      <td><strong>${esc(c.name)}</strong></td>
      <td><span style="${col(c)}">${TYPE_LABELS[c.type]||c.type}</span></td>
      <td>${c.barangay ? esc(c.barangay) : '<span style="color:var(--muted);font-size:0.8rem;">City-wide</span>'}</td>
      <td>${esc(c.city)}${c.province?', '+esc(c.province):''}</td>
      <td>${c.contact_number?`<a href="tel:${esc(c.contact_number)}" style="color:var(--blue);text-decoration:none;">${esc(c.contact_number)}</a>`:'<span style="color:var(--muted);">—</span>'}</td>
      <td style="font-size:0.82rem;">${c.contact_email?`<a href="mailto:${esc(c.contact_email)}" style="color:var(--blue);text-decoration:none;">${esc(c.contact_email)}</a>`:'<span style="color:var(--muted);">—</span>'}</td>
      <td><span style="padding:3px 10px;border-radius:50px;font-size:0.72rem;font-weight:700;${c.is_active?'background:#f0fff4;color:#38a169;':'background:#fff5f5;color:#e53e3e;'}">${c.is_active?'Active':'Inactive'}</span></td>
      <td style="white-space:nowrap;">
        <button class="btn-action approve" style="padding:5px 12px;font-size:0.78rem;" onclick="editContact(${c.id})"><i class="fas fa-pencil"></i> Edit</button>
        <button class="btn-action archive" style="padding:5px 12px;font-size:0.78rem;margin-left:4px;" onclick="deleteContact(${c.id},'${esc(c.name).replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i></button>
      </td>
    </tr>
  `).join('');
}

function openContactModal(contact=null){
  document.getElementById('contactModalMsg').style.display='none';
  document.getElementById('c_id').value      = contact?.id    || '';
  document.getElementById('c_name').value    = contact?.name  || '';
  document.getElementById('c_type').value    = contact?.type  || 'lgu';
  document.getElementById('c_barangay').value= contact?.barangay || '';
  document.getElementById('c_city').value    = contact?.city  || '';
  document.getElementById('c_province').value= contact?.province || '';
  document.getElementById('c_phone').value   = contact?.contact_number || '';
  document.getElementById('c_email').value   = contact?.contact_email  || '';
  document.getElementById('c_active').checked= contact ? !!contact.is_active : true;
  document.getElementById('contactModalTitle').innerHTML =
    contact ? '<i class="fas fa-pencil" style="color:var(--blue);margin-right:8px;"></i>Edit Emergency Contact'
            : '<i class="fas fa-address-book" style="color:var(--blue);margin-right:8px;"></i>Add Emergency Contact';
  const ol = document.getElementById('contactModalOverlay');
  ol.style.display='flex';
}

function closeContactModal(){
  document.getElementById('contactModalOverlay').style.display='none';
}

function openDeleteModal(flagId, email) {
  const msg = email ?
    `Are you sure you want to delete the flagged account entry for ${email}? This action cannot be undone.` :
    'Are you sure you want to delete this flagged account entry? This action cannot be undone.';
  document.getElementById('deleteModalMessage').textContent = msg;
  document.getElementById('confirmDeleteBtn').dataset.flagId = flagId;
  document.getElementById('deleteModalOverlay').style.display = 'flex';
}

function closeDeleteModal(){
  document.getElementById('deleteModalOverlay').style.display='none';
  document.getElementById('confirmDeleteBtn').dataset.flagId = '';
}

async function confirmDeleteFlag() {
  const button = document.getElementById('confirmDeleteBtn');
  const flagId = button.dataset.flagId;
  if (!flagId) return;

  button.disabled = true;
  button.textContent = 'Deleting...';

  try {
    const fd = new FormData();
    fd.append('action', 'delete_flag');
    fd.append('flag_id', flagId);

    const res = await fetch('api/security.php', {
      method: 'POST',
      body: fd
    });
    const data = await res.json();

    if (data.status === 'success') {
      closeDeleteModal();
      await loadSecurityMonitor();
    } else {
      alert(data.message || 'Failed to delete the entry.');
    }
  } catch (e) {
    console.error(e);
    alert('Unable to delete entry. Please try again.');
  } finally {
    button.disabled = false;
    button.textContent = 'Delete';
  }
}

function deleteFlag(flagId, email){
  openDeleteModal(flagId, email);
}

function editContact(id){
  const c = allContacts.find(x=>x.id===id);
  if(c) openContactModal(c);
}

async function saveContact(){
  const btn  = document.getElementById('contactSaveBtn');
  const msg  = document.getElementById('contactModalMsg');
  const id   = document.getElementById('c_id').value;
  const name = document.getElementById('c_name').value.trim();
  const city = document.getElementById('c_city').value.trim();
  if(!name||!city){
    msg.style.cssText='display:block;background:#fff5f5;color:#c62828;padding:10px 14px;border-radius:8px;font-size:0.84rem;margin-bottom:14px;';
    msg.textContent='Name and city are required.'; return;
  }
  const fd = new FormData();
  fd.append('action', id ? 'update' : 'create');
  if(id) fd.append('id', id);
  fd.append('name',            name);
  fd.append('type',            document.getElementById('c_type').value);
  fd.append('barangay',        document.getElementById('c_barangay').value.trim());
  fd.append('city',            city);
  fd.append('province',        document.getElementById('c_province').value.trim());
  fd.append('contact_number',  document.getElementById('c_phone').value.trim());
  fd.append('contact_email',   document.getElementById('c_email').value.trim());
  fd.append('is_active',       document.getElementById('c_active').checked ? '1' : '0');
  btn.disabled=true; btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Saving...';
  try{
    const res  = await fetch('api/contacts.php', {method:'POST',body:fd});
    const data = await res.json();
    if(data.status==='success'){
      closeContactModal();
      allContacts=[];   // force reload
      loadContacts();
    } else {
      msg.style.cssText='display:block;background:#fff5f5;color:#c62828;padding:10px 14px;border-radius:8px;font-size:0.84rem;margin-bottom:14px;';
      msg.textContent = data.message || 'Save failed.';
    }
  }catch{
    msg.style.cssText='display:block;background:#fff5f5;color:#c62828;padding:10px 14px;border-radius:8px;font-size:0.84rem;margin-bottom:14px;';
    msg.textContent='Network error. Please try again.';
  }
  btn.disabled=false; btn.innerHTML='<i class="fas fa-floppy-disk"></i> Save';
}

async function deleteContact(id, name){
  if(!confirm(`Remove "${name}" from emergency contacts?`)) return;
  const fd=new FormData(); fd.append('action','delete'); fd.append('id',id);
  const res  = await fetch('api/contacts.php',{method:'POST',body:fd});
  const data = await res.json();
  if(data.status==='success'){ allContacts=[]; loadContacts(); }
  else alert(data.message||'Could not delete.');
}

// Close contacts modal when clicking outside
document.getElementById('contactModalOverlay').addEventListener('click', function(e){
  if(e.target===this) closeContactModal();
});

document.getElementById('deleteModalOverlay').addEventListener('click', function(e){
  if(e.target===this) closeDeleteModal();
});

// ── INIT ─────────────────────────────────────────────────────
loadOverview();
loadPending();

// ── AUDIT LOG ─────────────────────────────────────────────────────────────
let allAuditLogs = [];

async function loadAuditLogs(){
  if(allAuditLogs.length > 0){ renderAudit(); return; }
  document.getElementById('auditLoading').style.display='block';
  document.getElementById('auditTable').style.display='none';
  try {
    const res  = await fetch('api/reports.php?action=admin_get_audit_logs');
    const data = await res.json();
    if(data.status==='success'){
      allAuditLogs = data.logs;
      renderAudit();
      document.getElementById('auditLoading').style.display='none';
      document.getElementById('auditTable').style.display='table';
    }
  } catch(e) {
    document.getElementById('auditLoading').innerHTML='<i class="fas fa-triangle-exclamation"></i> Failed to load audit log.';
  }
}

function renderAudit(){
  const search = document.getElementById('auditSearch').value.toLowerCase();
  const action = document.getElementById('auditAction').value;
  let list = allAuditLogs.filter(l => {
    if(action && l.action !== action) return false;
    if(search){ const hay=(l.report_title+l.performed_by_name).toLowerCase(); if(!hay.includes(search)) return false; }
    return true;
  });
  const body = document.getElementById('auditBody');
  if(!list.length){ body.innerHTML='<tr><td colspan="5"><div class="empty"><i class="fas fa-clipboard-check"></i><p>No audit entries found.</p></div></td></tr>'; return; }
  const actionStyles={archived:'background:#fff0f0;color:var(--red);',restored:'background:#f0fff4;color:var(--green);'};
  const actionIcons={archived:'fa-archive',restored:'fa-rotate-left'};
  body.innerHTML=list.map((l,i)=>{
    const date=new Date(l.performed_at).toLocaleString('en-PH');
    const style=actionStyles[l.action]||'background:#f0f2f7;color:#555;';
    const icon=actionIcons[l.action]||'fa-circle-info';
    return `<tr>
      <td style="color:#aaa;">${l.id}</td>
      <td><div style="font-weight:600;">${esc(l.report_title)}</div><small style="color:#aaa;">Report #${l.report_id}</small></td>
      <td><span style="padding:3px 10px;border-radius:50px;font-size:0.72rem;font-weight:700;${style}"><i class="fas ${icon}" style="margin-right:4px;"></i>${l.action.toUpperCase()}</span></td>
      <td style="font-weight:500;">${esc(l.performed_by_name)}</td>
      <td style="color:#888;font-size:0.78rem;white-space:nowrap;">${date}</td>
    </tr>`;
  }).join('');
}
document.addEventListener('DOMContentLoaded',()=>{
  const as=document.getElementById('auditSearch'); if(as) as.addEventListener('input',renderAudit);
  const aa=document.getElementById('auditAction'); if(aa) aa.addEventListener('change',renderAudit);
  const ss=document.getElementById('securitySearch'); if(ss) ss.addEventListener('input',()=>{ renderSecurityTable(); updateSecuritySelectionSummary(); });
  const rf=document.getElementById('riskFilter'); if(rf) rf.addEventListener('change',()=>{ renderSecurityTable(); updateSecuritySelectionSummary(); });
});

// ── SECURITY MONITOR ─────────────────────────────────────────
let allSecurityData = [];
let selectedSecurityFlags = new Set();
let currentSecurityPage = 1;
const securityItemsPerPage = 10;

async function loadSecurityMonitor() {
  currentSecurityPage = 1;
  document.getElementById('securityLoading').style.display = 'block';
  document.getElementById('securityTable').style.display = 'none';

  try {
    const res = await fetch('api/security.php?action=get_flagged');
    const data = await res.json();

    if (data.status === 'success') {
      allSecurityData = data.data;

      const high = allSecurityData.filter(r => r.risk_level === 'high').length;
      const medium = allSecurityData.filter(r => r.risk_level === 'medium').length;

      document.getElementById('highRiskCount').textContent = high;
      document.getElementById('mediumRiskCount').textContent = medium;
      document.getElementById('totalFlagged').textContent = allSecurityData.length;

      renderSecurityTable();
    }
  } catch (e) {
    console.error(e);
  }

  document.getElementById('securityLoading').style.display = 'none';
  document.getElementById('securityTable').style.display = 'table';
}

function renderSecurityTable() {
  const search = (document.getElementById('securitySearch').value || '').toLowerCase();
  const riskFilter = document.getElementById('riskFilter').value;

  let filtered = allSecurityData.filter(row => {
    if (riskFilter && row.risk_level !== riskFilter) return false;
    if (search) {
      const hay = (row.email + ' ' + row.ip_address).toLowerCase();
      return hay.includes(search);
    }
    return true;
  });

  const body = document.getElementById('securityBody');
  if (filtered.length === 0) {
    body.innerHTML = `<tr><td colspan="9" class="empty"><i class="fas fa-shield-halved"></i><p>No flagged attempts found.</p></td></tr>`;
    document.getElementById('securityPaginationContainer').style.display = 'none';
    updateSecuritySelectionSummary();
    return;
  }

  const totalPages = Math.ceil(filtered.length / securityItemsPerPage);
  if (currentSecurityPage > totalPages) currentSecurityPage = totalPages;
  if (currentSecurityPage < 1) currentSecurityPage = 1;

  const start = (currentSecurityPage - 1) * securityItemsPerPage;
  const end = start + securityItemsPerPage;
  const pageData = filtered.slice(start, end);

  body.innerHTML = pageData.map((row, i) => {
    const isSelected = selectedSecurityFlags.has(row.flag_id);
    const riskClass = row.risk_level === 'high' ? 'badge dangerous' : 
                     (row.risk_level === 'medium' ? 'badge caution' : 'badge safe');
    const globalIndex = start + i + 1;

    return `
      <tr>
        <td><input type="checkbox" class="security-row-checkbox" data-flag-id="${row.flag_id}" onchange="toggleSecuritySelect(${row.flag_id}, this.checked)" ${isSelected ? 'checked' : ''}></td>
        <td>${globalIndex}</td>
        <td><strong>${esc(row.email || 'Unknown')}</strong></td>
        <td style="font-family:monospace;">${esc(row.ip_address)}</td>
        <td><strong>${row.failed_count || row.recent_failed || 0}</strong></td>
        <td><span class="${riskClass}">${(row.risk_level || 'normal').toUpperCase()}</span></td>
        <td style="color:#888;font-size:0.78rem;">${row.last_attempt}</td>
        <td><span class="badge ${row.reviewed ? 'archived' : 'dangerous'}">${row.reviewed ? 'Reviewed' : 'Flagged'}</span></td>
        <td>
          ${!row.reviewed ? `<button class="act-btn" style="background:#16a34a;color:#fff;" onclick="markReviewed(${row.flag_id})"><i class="fas fa-check"></i> Reviewed</button>` : ''}
          <button class="act-btn del" onclick="deleteFlag(${row.flag_id}, '${(row.email||'').replace(/'/g,"\\'")}')"><i class="fas fa-trash"></i></button>
        </td>
      </tr>`;
  }).join('');

  renderSecurityPagination(totalPages, filtered.length);
  updateSecuritySelectionSummary();
}

function renderSecurityPagination(totalPages, totalItems) {
  const container = document.getElementById('securityPaginationContainer');
  if (!container) return;

  if (totalPages <= 1) {
    container.style.display = 'none';
    return;
  }

  container.style.display = 'flex';
  let pageLinks = [];
  if (totalPages <= 5) {
    pageLinks = Array.from({ length: totalPages }, (_, i) => i + 1);
  } else {
    pageLinks.push(1);
    if (currentSecurityPage > 3) pageLinks.push('...');
    for (let i = Math.max(2, currentSecurityPage - 1); i <= Math.min(totalPages - 1, currentSecurityPage + 1); i++) {
      if (!pageLinks.includes(i)) pageLinks.push(i);
    }
    if (currentSecurityPage < totalPages - 2) pageLinks.push('...');
    pageLinks.push(totalPages);
  }

  const paginationHtml = `
    <div style="display:flex;align-items:center;justify-content:center;gap:8px;flex-wrap:wrap;">
      <button class="btn-action" onclick="goToSecurityPage(1)" title="First page" style="padding:8px 10px;font-size:0.8rem;" ${currentSecurityPage === 1 ? 'disabled' : ''}><i class="fas fa-angles-left"></i></button>
      <button class="btn-action" onclick="goToSecurityPage(${currentSecurityPage - 1})" title="Previous page" style="padding:8px 10px;font-size:0.8rem;" ${currentSecurityPage === 1 ? 'disabled' : ''}><i class="fas fa-angle-left"></i></button>
      
      <div style="display:flex;gap:4px;align-items:center;">
        ${pageLinks.map(page => {
          if (page === '...') return `<span style="color:var(--muted);">...</span>`;
          return `<button class="btn-action" onclick="goToSecurityPage(${page})" style="padding:6px 10px;font-size:0.8rem;${currentSecurityPage === page ? 'background:var(--blue);color:#fff;' : ''}">${page}</button>`;
        }).join('')}
      </div>
      
      <button class="btn-action" onclick="goToSecurityPage(${currentSecurityPage + 1})" title="Next page" style="padding:8px 10px;font-size:0.8rem;" ${currentSecurityPage === totalPages ? 'disabled' : ''}><i class="fas fa-angle-right"></i></button>
      <button class="btn-action" onclick="goToSecurityPage(${totalPages})" title="Last page" style="padding:8px 10px;font-size:0.8rem;" ${currentSecurityPage === totalPages ? 'disabled' : ''}><i class="fas fa-angles-right"></i></button>
      
      <span style="color:var(--muted);font-size:0.85rem;margin-left:12px;white-space:nowrap;">Page ${currentSecurityPage} of ${totalPages} (${totalItems} total)</span>
    </div>
  `;
  container.innerHTML = paginationHtml;
}

function goToSecurityPage(page) {
  currentSecurityPage = page;
  renderSecurityTable();
  const table = document.getElementById('securityTable');
  if (table) table.scrollIntoView({ behavior: 'smooth', block: 'start' });
}

function updateSecuritySelectionSummary() {
  const total = allSecurityData.length;
  const selected = selectedSecurityFlags.size;
  const summary = document.getElementById('securitySelectionSummary');
  if (summary) {
    summary.textContent = `Selected ${selected} of ${total} flagged accounts`;
  }
  const deleteBtn = document.getElementById('deleteSelectedFlagsBtn');
  if (deleteBtn) deleteBtn.disabled = selected === 0;
  const selectAll = document.getElementById('securitySelectAll');
  if (selectAll) {
    const visible = Array.from(document.querySelectorAll('#securityBody input.security-row-checkbox'));
    selectAll.checked = visible.length > 0 && visible.every(cb => cb.checked);
  }
}

function toggleSecuritySelectAll(checkbox) {
  const checked = checkbox.checked;
  document.querySelectorAll('#securityBody input.security-row-checkbox').forEach(input => {
    input.checked = checked;
    const id = parseInt(input.dataset.flagId, 10);
    if (checked) selectedSecurityFlags.add(id);
    else selectedSecurityFlags.delete(id);
  });
  updateSecuritySelectionSummary();
}

function toggleSecuritySelect(flagId, checked) {
  if (checked) selectedSecurityFlags.add(flagId);
  else selectedSecurityFlags.delete(flagId);
  updateSecuritySelectionSummary();
}

async function deleteSelectedFlags() {
  const ids = Array.from(selectedSecurityFlags);
  if (!ids.length) return;
  if (!confirm(`Delete ${ids.length} selected flagged account entries? This cannot be undone.`)) return;
  await sendDeleteFlags(ids);
}

async function deleteAllFlagged() {
  const ids = allSecurityData.map(row => row.flag_id);
  if (!ids.length) return;
  if (!confirm(`Delete all ${ids.length} flagged account entries? This cannot be undone.`)) return;
  await sendDeleteFlags(ids);
}

async function sendDeleteFlags(flagIds) {
  const button = document.getElementById('deleteSelectedFlagsBtn');
  if (button) { button.disabled = true; button.textContent = 'Deleting...'; }
  try {
    const fd = new FormData();
    fd.append('action', 'delete_flags');
    fd.append('flag_ids', JSON.stringify(flagIds));

    const res = await fetch('api/security.php', {
      method: 'POST',
      body: fd
    });
    const data = await res.json();

    if (data.status === 'success') {
      selectedSecurityFlags.clear();
      await loadSecurityMonitor();
    } else {
      alert(data.message || 'Failed to delete flagged entries.');
    }
  } catch (e) {
    console.error(e);
    alert('Unable to delete flagged entries. Please try again.');
  } finally {
    if (button) { button.disabled = false; button.innerHTML = '<i class="fas fa-trash"></i> Delete Selected'; }
  }
}

function renderVulnerabilities() {
  const latest = allSecurityScans[0] || null;
  document.getElementById('lastScanDate').textContent = latest ? latest.scanned_at : 'Never';
  document.getElementById('vulnerabilityScore').textContent = latest ? `${latest.score}/100` : '0/100';

  const statusMap = {
    passed: {label:'Passed', cls:'passed'},
    warning: {label:'Warning', cls:'warning'},
    critical: {label:'Critical', cls:'critical'},
  };

  const cardKeyMap = {
    https: 'vulnCardHttps',
    session: 'vulnCardSession',
    password_hash: 'vulnCardPassword',
    security_headers: 'vulnCardHeaders',
    upload_restrictions: 'vulnCardUpload',
  };

  Object.entries(cardKeyMap).forEach(([key, cardId]) => {
    const card = document.getElementById(cardId);
    if (!card) return;
    let result = { status: 'warning', detail: 'No scan result available.' };
    if (latest && latest[key]) result = latest[key];
    const status = statusMap[result.status] || statusMap.warning;
    card.querySelector('.vuln-status').textContent = status.label;
    card.querySelector('.vuln-status').className = 'vuln-status ' + status.cls;
    card.querySelector('.vuln-detail').textContent = result.detail || 'No detail available.';
  });

  const historyBody = document.getElementById('vulnHistoryBody');
  const historyTable = document.getElementById('vulnHistoryTable');
  const historyLoading = document.getElementById('vulnHistoryLoading');

  if (allSecurityScans.length === 0) {
    historyBody.innerHTML = '<tr><td colspan="8" class="empty"><i class="fas fa-search"></i><p>No vulnerability scans have been run yet.</p></td></tr>';
    historyTable.style.display = 'table';
    historyLoading.style.display = 'none';
    return;
  }

  historyBody.innerHTML = allSecurityScans.map((scan, idx) => {
    const statusRow = scan;
    return `
      <tr>
        <td>${idx+1}</td>
        <td>${scan.scanned_at}</td>
        <td><strong>${scan.score}/100</strong></td>
        <td><span class="badge ${statusMap[statusRow.https_status].cls}">${statusMap[statusRow.https_status].label}</span></td>
        <td><span class="badge ${statusMap[statusRow.session_status].cls}">${statusMap[statusRow.session_status].label}</span></td>
        <td><span class="badge ${statusMap[statusRow.password_hash_status].cls}">${statusMap[statusRow.password_hash_status].label}</span></td>
        <td><span class="badge ${statusMap[statusRow.security_headers_status].cls}">${statusMap[statusRow.security_headers_status].label}</span></td>
        <td><span class="badge ${statusMap[statusRow.upload_restrictions_status].cls}">${statusMap[statusRow.upload_restrictions_status].label}</span></td>
      </tr>`;
  }).join('');
  historyTable.style.display = 'table';
  historyLoading.style.display = 'none';
}

async function loadVulnerabilities() {
  document.getElementById('vulnHistoryLoading').style.display = 'block';
  document.getElementById('vulnHistoryTable').style.display = 'none';
  try {
    const res = await fetch('api/security.php?action=security_history');
    const data = await res.json();
    if (data.status === 'success') {
      allSecurityScans = data.history.map(item => ({
        ...item,
        score: parseInt(item.score, 10),
      }));
      renderVulnerabilities();
      return;
    }
  } catch (error) {
    console.error('Vulnerability history load failed:', error);
  }
  document.getElementById('vulnHistoryLoading').style.display = 'none';
  document.getElementById('vulnHistoryTable').style.display = 'table';
}

function openVulnerabilityModal() {
  document.getElementById('vulnerabilityModalOverlay').style.display = 'flex';
  loadVulnerabilities();
}

function closeVulnerabilityModal() {
  document.getElementById('vulnerabilityModalOverlay').style.display = 'none';
}

async function runVulnerabilityAssessment() {
  const button = document.querySelector('#vulnerabilityModalOverlay .btn-action.approve');
  if (button) { button.disabled = true; button.textContent = 'Running...'; }
  try {
    const res = await fetch('api/security.php?action=run_security_scan', { method: 'POST' });
    const data = await res.json();
    if (data.status === 'success' && data.scan) {
      allSecurityScans.unshift(data.scan);
      renderVulnerabilities();
    } else {
      alert(data.message || 'Assessment failed.');
    }
  } catch (error) {
    console.error('Vulnerability assessment failed:', error);
    alert('Unable to run the assessment. Check console for details.');
  }
  if (button) { button.disabled = false; button.innerHTML = '<i class="fas fa-play"></i> Run Assessment'; }
}

</script>
</body>
</html>


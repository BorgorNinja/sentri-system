<?php
ini_set('display_errors', 0);
error_reporting(E_ALL);
ob_start();
session_start();
if (isset($_SESSION['user_id'])) { header("Location: dashboard.php"); exit; }
require __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    ob_clean();
    header('Content-Type: application/json');
    try {

    // ── Auto-migrate: add email verification columns if they don't exist yet ──
    // This means email_verification.sql doesn't need to be run manually.
    $db = $conn->query("SELECT DATABASE()")->fetch_row()[0];
    $existingCols = [];
    $colRes = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS
                            WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users'");
    while ($r = $colRes->fetch_row()) $existingCols[] = $r[0];

    if (!in_array('email_verified', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN email_verified TINYINT(1) NOT NULL DEFAULT 0 AFTER role");
    if (!in_array('verification_token', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN verification_token VARCHAR(64) DEFAULT NULL AFTER email_verified");
    if (!in_array('token_expires_at', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN token_expires_at DATETIME DEFAULT NULL AFTER verification_token");
    if (!in_array('reset_token', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN reset_token VARCHAR(64) DEFAULT NULL AFTER token_expires_at");
    if (!in_array('reset_token_expires', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN reset_token_expires DATETIME DEFAULT NULL AFTER reset_token");

    // Mark any existing users (registered before this migration) as already verified
    if (!in_array('email_verified', $existingCols))
        $conn->query("UPDATE users SET email_verified=1 WHERE created_at < NOW()");

    // Migration 006: role expansion and affiliation columns
    if (!in_array('phone_number', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN phone_number VARCHAR(30) DEFAULT NULL AFTER email");
    if (!in_array('org_name', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN org_name VARCHAR(255) DEFAULT NULL");
    if (!in_array('position', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN `position` VARCHAR(150) DEFAULT NULL");
    if (!in_array('barangay_name', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN barangay_name VARCHAR(150) DEFAULT NULL");
    if (!in_array('municipality', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN municipality VARCHAR(150) DEFAULT NULL");
    if (!in_array('is_approved', $existingCols)) {
        $conn->query("ALTER TABLE users ADD COLUMN is_approved TINYINT(1) NOT NULL DEFAULT 0");
        $conn->query("UPDATE users SET is_approved=1 WHERE role IN('user','community','admin')");
    }
    if (!in_array('responder_type', $existingCols))
        $conn->query("ALTER TABLE users ADD COLUMN responder_type VARCHAR(30) DEFAULT NULL");
    // Only expand enum if new roles aren't already present
    $enumRes = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users' AND COLUMN_NAME='role'");
    if ($enumRes) {
        $enumRow = $enumRes->fetch_row();
        if ($enumRow && strpos($enumRow[0], 'first_responder') === false) {
            $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('user','community','barangay','lgu','first_responder','admin') NOT NULL DEFAULT 'community'");
        }
    }
    // Clear any pending results/errors left by DDL statements
    while ($conn->more_results()) $conn->next_result();
    $conn->query("SELECT 1"); // flush connection state
    // ─────────────────────────────────────────────────────────────────────────

    $first    = trim($_POST['first_name']    ?? '');
    $last     = trim($_POST['last_name']     ?? '');
    $email    = trim($_POST['email']         ?? '');
    $pw       = $_POST['password']           ?? '';
    $pw2      = $_POST['confirm_password']   ?? '';
    $role_req = trim($_POST['role']          ?? 'community');
    $org_name = trim($_POST['org_name']      ?? '');
    $position = trim($_POST['position']      ?? '');
    $brgy     = trim($_POST['barangay_name'] ?? '');
    $muni     = trim($_POST['municipality']  ?? '');
    $rtype    = trim($_POST['responder_type'] ?? '') ?: null;
    $phone    = trim($_POST['phone']         ?? '');

    $allowed_roles = ['community','barangay','lgu','first_responder'];
    if (!in_array($role_req, $allowed_roles, true)) $role_req = 'community';
    // Community auto-approved; officials need admin approval
    $is_approved = ($role_req === 'community') ? 1 : 0;

    if (empty($first)||empty($last)||empty($email)||empty($pw)) {
        echo json_encode(['status'=>'error','message'=>'All fields are required.']); exit;
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error','message'=>'Invalid email address.']); exit;
    }
    if (strlen($pw) < 8) {
        echo json_encode(['status'=>'error','message'=>'Password must be at least 8 characters.']); exit;
    }
    if ($pw !== $pw2) {
        echo json_encode(['status'=>'error','message'=>'Passwords do not match.']); exit;
    }

    $chk = $conn->prepare("SELECT id FROM users WHERE email=? LIMIT 1");
    if (!$chk) { echo json_encode(['status'=>'error','message'=>'Database error. Please try again.']); exit; }
    $chk->bind_param("s", $email); $chk->execute(); $chk->store_result();
    if ($chk->num_rows > 0) {
        echo json_encode(['status'=>'error','message'=>'Email is already registered.']); exit;
    }
    $chk->close();

    $token      = bin2hex(random_bytes(32));
    $expires_at = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $hash       = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);

    // Official roles (barangay, lgu, first_responder) skip email verification —
    // admin approval is their gate. Community accounts still require email verify.
    $official_roles = ['barangay', 'lgu', 'first_responder'];
    $is_official    = in_array($role_req, $official_roles, true);
    $email_verified = $is_official ? 1 : 0;
    $ins_token      = $is_official ? null : $token;
    $ins_expires    = $is_official ? null : $expires_at;

    $ins = $conn->prepare(
        "INSERT INTO users (first_name,last_name,email,password,role,phone_number,org_name,`position`,barangay_name,municipality,responder_type,is_approved,email_verified,verification_token,token_expires_at)
         VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)"
    );
    if (!$ins) { echo json_encode(['status'=>'error','message'=>'Prepare error: '.$conn->error]); exit; }
    if (!$ins->bind_param("ssssssssssssiiss", $first,$last,$email,$hash,$role_req,$phone,$org_name,$position,$brgy,$muni,$rtype,$is_approved,$email_verified,$ins_token,$ins_expires)) {
        echo json_encode(['status'=>'error','message'=>'Bind error: '.$ins->error]); exit;
    }
    if (!$ins->execute()) {
        echo json_encode(['status'=>'error','message'=>'Registration failed: '.$ins->error]); exit;
    }
    $ins->close();

    if ($is_official) {
        $message = 'Account created! Your account is now pending administrator approval. You will be notified once approved.';
        echo json_encode(['status'=>'success','message'=>$message,'redirect'=>'login.php?pending=1']);
        exit;
    }

    $emailSent = false;
    try {
        require_once __DIR__ . '/core/SenTriMailer.php';
        $emailSent = sendVerificationEmail($email, $first, $token);
    } catch (Throwable $e) {
        error_log('SenTri email error: ' . $e->getMessage());
    }

    $message = $emailSent
        ? 'Account created! Please check your inbox and verify your email before signing in.'
        : 'Account created! We had trouble sending the verification email — use the "Resend" option on the login page.';

    echo json_encode(['status'=>'success','message'=>$message,'redirect'=>'login.php?verify_sent=1']);
    exit;
    } catch (Throwable $e) {
        ob_clean();
        error_log('SenTri signup error: ' . $e->getMessage());
        echo json_encode(['status'=>'error','message'=>'Server error: ' . $e->getMessage()]);
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Register — SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--navy:#0a3d62;--navy-dark:#062444;--gold:#f39c12;--green:#166534;--red:#b91c1c;--purple:#5b21b6;--text:#111827;--muted:#6b7280;--border:#e5e7eb;--bg:#f1f5f9;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
body{background:var(--bg);min-height:100vh;display:flex;flex-direction:column;}
.gov-bar{background:#fff;border-bottom:4px solid var(--gold);padding:0 28px;height:62px;display:flex;align-items:center;justify-content:space-between;box-shadow:0 2px 10px rgba(0,0,0,0.1);}
.gov-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.gov-seal{width:42px;height:42px;background:linear-gradient(135deg,var(--navy),#1a5276);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);border:2px solid var(--gold);}
.gov-text h1{font-size:1.1rem;font-weight:800;color:var(--navy);}
.gov-text p{font-size:0.7rem;color:var(--muted);font-weight:500;}
.gov-links a{font-size:0.82rem;color:var(--navy);text-decoration:none;font-weight:600;opacity:0.8;margin-left:20px;}
.gov-links a:hover{opacity:1;}
.page-wrap{max-width:820px;margin:0 auto;padding:32px 20px 60px;width:100%;}
.page-header{text-align:center;margin-bottom:28px;}
.page-header h2{font-size:1.55rem;font-weight:800;color:var(--navy);margin-bottom:6px;}
.page-header p{font-size:0.88rem;color:var(--muted);}
/* Role selector */
.role-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:10px;margin-bottom:24px;}
.role-card{background:#fff;border:2px solid var(--border);border-radius:13px;padding:14px 10px;text-align:center;cursor:pointer;transition:all 0.2s;box-shadow:0 2px 8px rgba(0,0,0,0.05);}
.role-card:hover{transform:translateY(-2px);box-shadow:0 6px 18px rgba(0,0,0,0.1);}
.role-card.selected{box-shadow:0 6px 20px rgba(0,0,0,0.15);}
.r-community.selected{border-color:#2563eb;background:#eff6ff;}
.r-barangay.selected{border-color:#166534;background:#f0fdf4;}
.r-lgu.selected{border-color:#0a3d62;background:#f0f7ff;}
.r-responder.selected{border-color:#b91c1c;background:#fef2f2;}
.role-icon{width:44px;height:44px;border-radius:11px;margin:0 auto 9px;display:flex;align-items:center;justify-content:center;font-size:1.2rem;}
.r-community .role-icon{background:#eff6ff;color:#2563eb;}
.r-barangay .role-icon{background:#f0fdf4;color:#166534;}
.r-lgu .role-icon{background:#f0f7ff;color:#0a3d62;}
.r-responder .role-icon{background:#fef2f2;color:#b91c1c;}
.role-name{font-size:0.76rem;font-weight:700;color:var(--text);}
.role-sub{font-size:0.64rem;color:var(--muted);margin-top:2px;}
/* Card */
.form-card{background:#fff;border-radius:18px;padding:32px 36px;box-shadow:0 4px 24px rgba(0,0,0,0.1);border:1px solid var(--border);}
.card-section{border-top:1px solid var(--border);margin-top:20px;padding-top:18px;}
.section-title{font-size:0.78rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:1.2px;margin-bottom:14px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.form-group{margin-bottom:14px;}
.form-group label{display:block;font-size:0.8rem;font-weight:600;color:#374151;margin-bottom:5px;}
.form-group input,.form-group select{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:9px;font-size:0.9rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;color:var(--text);transition:all 0.18s;}
.form-group input:focus,.form-group select:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,0.08);}
.pw-wrap{position:relative;}
.pw-wrap button{position:absolute;right:11px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;font-size:0.75rem;font-weight:700;color:var(--muted);font-family:'Inter',sans-serif;}
.official-fields{display:none;}
.official-fields.show{display:block;}
.approval-notice{background:#fffbeb;border:1px solid #fde68a;border-radius:10px;padding:12px 15px;font-size:0.81rem;color:#92400e;display:none;align-items:flex-start;gap:9px;margin-bottom:16px;line-height:1.5;}
.approval-notice.show{display:flex;}
.approval-notice i{color:#d97706;flex-shrink:0;margin-top:2px;}
.btn-register{width:100%;padding:12px;border:none;border-radius:11px;font-size:0.95rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;color:#fff;transition:all 0.2s;background:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;gap:8px;margin-top:6px;}
.btn-register:hover:not(:disabled){transform:translateY(-1px);box-shadow:0 8px 22px rgba(37,99,235,0.35);}
.btn-register:disabled{opacity:0.6;cursor:not-allowed;}
.msg{padding:10px 14px;border-radius:9px;font-size:0.83rem;font-weight:500;margin-bottom:14px;display:none;text-align:center;}
.msg.success{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;}
.msg.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.login-row{text-align:center;margin-top:16px;font-size:0.85rem;color:var(--muted);}
.login-row a{color:#2563eb;font-weight:700;text-decoration:none;}
.terms{font-size:0.76rem;color:var(--muted);text-align:center;margin-top:10px;line-height:1.5;}
@media(max-width:640px){.role-grid{grid-template-columns:repeat(2,1fr);}.form-row{grid-template-columns:1fr;}.form-card{padding:22px 18px;}.page-wrap{padding:20px 14px 50px;}}
</style>
</head>
<body>
<header class="gov-bar">
  <a href="index.php" class="gov-brand">
    <div class="gov-seal"><i class="fas fa-shield-halved"></i></div>
    <div class="gov-text"><h1>SenTri</h1><p>Community Safety Incident Reporting System</p></div>
  </a>
  <nav class="gov-links">
    <a href="login.php">Sign In</a>
    <a href="index.php">Home</a>
  </nav>
</header>

<div class="page-wrap">
  <div class="page-header">
    <h2>Create Your Account</h2>
    <p>Select your role then fill in your details. Official accounts require administrator approval.</p>
  </div>

  <!-- Role selector -->
  <div class="role-grid" id="roleGrid">
    <div class="role-card r-community selected" onclick="selectRole('community',this)">
      <div class="role-icon"><i class="fas fa-users"></i></div>
      <div class="role-name">Community</div>
      <div class="role-sub">Citizen / Resident</div>
    </div>
    <div class="role-card r-barangay" onclick="selectRole('barangay',this)">
      <div class="role-icon"><i class="fas fa-house-flag"></i></div>
      <div class="role-name">Barangay</div>
      <div class="role-sub">Barangay Official</div>
    </div>
    <div class="role-card r-lgu" onclick="selectRole('lgu',this)">
      <div class="role-icon"><i class="fas fa-landmark"></i></div>
      <div class="role-name">LGU</div>
      <div class="role-sub">City / Municipal</div>
    </div>
    <div class="role-card r-responder" onclick="selectRole('first_responder',this)">
      <div class="role-icon"><i class="fas fa-truck-medical"></i></div>
      <div class="role-name">First Responder</div>
      <div class="role-sub">BFP / PNP / EMS</div>
    </div>
  </div>

  <div class="form-card">
    <div id="regMsg" class="msg"></div>
    <div class="approval-notice" id="approvalNotice">
      <i class="fas fa-clock"></i>
      <span>Official accounts do not require email verification. Your account will be reviewed and activated by the system administrator. You will be notified once your account is approved.</span>
    </div>

    <form id="regForm" novalidate>
      <input type="hidden" name="role" id="roleField" value="community">

      <div class="section-title">Personal Information</div>
      <div class="form-row">
        <div class="form-group"><label>First Name</label><input type="text" name="first_name" placeholder="Juan" required></div>
        <div class="form-group"><label>Last Name</label><input type="text" name="last_name" placeholder="dela Cruz" required></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Email Address</label><input type="email" name="email" placeholder="you@example.com" required></div>
        <div class="form-group"><label>Phone Number <span style="color:var(--muted);font-weight:400;">(optional)</span></label><input type="tel" name="phone" placeholder="+63 9XX XXX XXXX"></div>
      </div>
      <div class="form-row">
        <div class="form-group"><label>Password</label><div class="pw-wrap"><input type="password" name="password" id="pw1" placeholder="Min. 8 characters" required><button type="button" onclick="togglePw('pw1',this)">SHOW</button></div></div>
        <div class="form-group"><label>Confirm Password</label><div class="pw-wrap"><input type="password" name="confirm_password" id="pw2" placeholder="Re-enter password" required><button type="button" onclick="togglePw('pw2',this)">SHOW</button></div></div>
      </div>

      <!-- Official fields shown for barangay / lgu / responder -->
      <div class="official-fields card-section" id="officialFields">
        <div class="section-title" id="officialSectionTitle">Official Information</div>
        <div class="form-row">
          <div class="form-group"><label id="orgLabel">Office / Unit Name</label><input type="text" name="org_name" id="orgName" placeholder="e.g. Brgy. Malagasang I-A Hall"></div>
          <div class="form-group"><label>Position / Rank</label><input type="text" name="position" placeholder="e.g. Barangay Captain"></div>
        </div>
        <div class="form-row" id="geoFields">
          <div class="form-group"><label id="brgyLabel">Barangay</label><input type="text" name="barangay_name" placeholder="e.g. Malagasang I-A"></div>
          <div class="form-group"><label>City / Municipality</label><input type="text" name="municipality" placeholder="e.g. Imus"></div>
        </div>
        <div class="form-group" id="responderTypeWrap" style="display:none;">
          <label>Responder Type</label>
          <select name="responder_type">
            <option value="">Select type...</option>
            <option value="bfp">BFP — Bureau of Fire Protection</option>
            <option value="pnp">PNP — Philippine National Police</option>
            <option value="ems">EMS — Emergency Medical Services</option>
            <option value="drrmo">DRRMO — Disaster Risk Reduction</option>
            <option value="mdrrmo">MDRRMO — Municipal DRRMO</option>
            <option value="hospital">Hospital / Medical Facility</option>
            <option value="other">Other</option>
          </select>
        </div>
      </div>

      <div style="margin-top:20px;">
        <button type="submit" class="btn-register" id="regBtn"><i class="fas fa-user-plus"></i> <span id="regBtnLabel">Create Community Account</span></button>
      </div>
    </form>

    <div class="login-row">Already have an account? <a href="login.php">Sign in here</a></div>
    <p class="terms">By registering you agree to use this system solely for legitimate community safety reporting. Misuse is subject to legal action under applicable Philippine laws.</p>
  </div>
</div>

<script>
const roleConfig = {
  community:      { label:'Create Community Account',   btn:'linear-gradient(135deg,#3b82f6,#2563eb)',  official:false },
  barangay:       { label:'Create Barangay Account',    btn:'linear-gradient(135deg,#16a34a,#166534)', official:true  },
  lgu:            { label:'Create LGU Account',         btn:'linear-gradient(135deg,#1a5276,#062444)', official:true  },
  first_responder:{ label:'Create Responder Account',   btn:'linear-gradient(135deg,#ef4444,#b91c1c)', official:true  },
};
let currentRole = 'community';
function selectRole(role, el) {
  currentRole = role;
  document.querySelectorAll('.role-card').forEach(c=>c.classList.remove('selected'));
  el.classList.add('selected');
  const cfg = roleConfig[role];
  document.getElementById('roleField').value = role;
  document.getElementById('regBtnLabel').textContent = cfg.label;
  document.getElementById('regBtn').style.background = cfg.btn;
  const of = document.getElementById('officialFields');
  of.classList.toggle('show', cfg.official);
  document.getElementById('approvalNotice').classList.toggle('show', cfg.official);
  document.getElementById('responderTypeWrap').style.display = (role==='first_responder') ? 'block' : 'none';
  if (role==='lgu') {
    document.getElementById('orgLabel').textContent = 'Office / Agency Name';
    document.getElementById('brgyLabel').textContent = 'Coverage Barangay (optional)';
    document.getElementById('orgName').placeholder = 'e.g. Imus City DRRMO';
  } else if (role==='first_responder') {
    document.getElementById('orgLabel').textContent = 'Unit / Station Name';
    document.getElementById('brgyLabel').textContent = 'Coverage Barangay (optional)';
    document.getElementById('orgName').placeholder = 'e.g. BFP Imus City Fire Station';
  } else {
    document.getElementById('orgLabel').textContent = 'Office / Unit Name';
    document.getElementById('brgyLabel').textContent = 'Barangay';
    document.getElementById('orgName').placeholder = 'e.g. Brgy. Malagasang I-A Hall';
  }
}
function togglePw(id,btn){const f=document.getElementById(id);const s=f.type==='password';f.type=s?'text':'password';btn.textContent=s?'HIDE':'SHOW';}
document.getElementById('regForm').addEventListener('submit',async e=>{
  e.preventDefault();
  const btn=document.getElementById('regBtn');const msg=document.getElementById('regMsg');
  const orig=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Creating account...';
  msg.style.display='none';
  try{
    const res=await fetch('signup.php',{method:'POST',body:new FormData(e.target)});
    const data=await res.json();
    if(data.status==='success'){
      showMsg('success',data.message);
      setTimeout(()=>window.location.href=data.redirect,1800);
    }else{showMsg('error',data.message||'Registration failed.');btn.disabled=false;btn.innerHTML=orig;}
  }catch(err){showMsg('error','Error: '+err.message);btn.disabled=false;btn.innerHTML=orig;}
});
function showMsg(type,text){const el=document.getElementById('regMsg');el.className='msg '+type;el.textContent=text;el.style.display='block';}
</script>
</body>
</html>

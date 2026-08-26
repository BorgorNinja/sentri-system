<?php
ini_set('display_errors', 0);
ob_start();
session_start(['cookie_httponly'=>true,'cookie_samesite'=>'Lax','cookie_secure'=>!empty($_SERVER['HTTPS'])]);
if (isset($_SESSION['user_id'])) {
 require_once __DIR__ . '/config/auth.php';
 redirect_to_portal();
}
require __DIR__ . '/config/db.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/config/csrf.php';

function getFailedAttemptsCount($conn, $email, $minutes = 30) {
 $cutoff = date('Y-m-d H:i:s', strtotime("-{$minutes} minutes"));
 $stmt = $conn->prepare("SELECT COUNT(*) FROM login_logs WHERE email = ? AND status = 'Failed' AND created_at >= ?");
 $stmt->bind_param("ss", $email, $cutoff);
 $stmt->execute();
 $stmt->bind_result($count);
 $stmt->fetch();
 $stmt->close();
 return (int)$count;
}

function isLockedOut($conn, $email) {
 $stmt = $conn->prepare("SELECT COUNT(*) FROM login_logs WHERE email = ? AND status = 'Locked' AND created_at >= NOW() - INTERVAL 30 SECOND");
 $stmt->bind_param("s", $email);
 $stmt->execute();
 $stmt->bind_result($count);
 $stmt->fetch();
 $stmt->close();
 return $count > 0;
}

function flagHighRiskAttempt($conn, $email) {
 $failed_count = getFailedAttemptsCount($conn, $email, 30);
 if ($failed_count >= 5) {
 $user_id = 0;
 $stmt = $conn->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
 $stmt->bind_param("s", $email);
 $stmt->execute();
 $stmt->bind_result($user_id);
 $stmt->fetch();
 $stmt->close();

 $stmt = $conn->prepare("INSERT INTO flagged_accounts (user_id, risk_level, failed_count, last_attempt, notes, reviewed) VALUES (?, 'high', ?, NOW(), 'Multiple failed login attempts detected', 0) ON DUPLICATE KEY UPDATE risk_level = 'high', failed_count = ?, last_attempt = NOW(), reviewed = 0");
 $stmt->bind_param("iii", $user_id, $failed_count, $failed_count);
 $stmt->execute();
 $stmt->close();

 $stmt = $conn->prepare("SELECT COUNT(*) FROM login_logs WHERE email = ? AND status = 'Locked' AND created_at >= NOW() - INTERVAL 30 SECOND");
 $stmt->bind_param("s", $email);
 $stmt->execute();
 $stmt->bind_result($lock_count);
 $stmt->fetch();
 $stmt->close();

 if ($lock_count === 0) {
 $stmt = $conn->prepare("INSERT INTO login_logs (user_id, email, ip_address, device, status) VALUES (?, ?, 'Lockout', 'System', 'Locked')");
 $stmt->bind_param("is", $user_id, $email);
 $stmt->execute();
 $stmt->close();
 }

 return true;
 }

 return false;
}

function sendLoginOtpEmail($email, $name, $otp) {
 require_once __DIR__ . '/core/SenTriMailer.php';
 $subject = 'Your SenTri login code';
 $html = '<div style="font-family:Arial,sans-serif;color:#111;line-height:1.6;">'
 . '<h2 style="color:#1a1a2e;">Your SenTri login code</h2>'
 . '<p>Use the code below to complete your Community portal sign in.</p>'
 . '<p style="font-size:2rem;font-weight:700;margin:18px 0;letter-spacing:0.2rem;">' . htmlspecialchars($otp, ENT_QUOTES, 'UTF-8') . '</p>'
 . '<p style="margin-top:10px;color:#555;">This code expires in 10 minutes. Do not share it with anyone.</p>'
 . '<hr style="border:none;border-top:1px solid #eee;margin:22px 0;">'
 . '<p style="font-size:0.9rem;color:#777;">If you did not request this code, ignore this email.</p>'
 . '</div>';
 try {
 $mailer = new SenTriMailer();
 return $mailer->send($email, $name, $subject, $html);
 } catch (Throwable $e) {
 error_log('OTP email error: ' . $e->getMessage());
 return false;
 }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 // Note: Login forms are exempt from CSRF validation since they are
 // the entry point. CSRF protection applies to authenticated actions.
 header('Content-Type: application/json');
 $action = trim($_POST['action'] ?? '');
 $email = trim($_POST['email'] ?? '');
 $password = trim($_POST['password'] ?? '');
 $portal = trim($_POST['portal'] ?? 'community');
 $ip = $_SERVER['HTTP_CLIENT_IP'] ?? $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? 'Unknown';
 $device = $_SERVER['HTTP_USER_AGENT'] ?? 'Unknown';

 if ($action === 'verify_otp') {
 $otp_code = trim($_POST['otp'] ?? '');
 $pending = $_SESSION['otp_login'] ?? null;
 if (!$pending || empty($pending['email']) || empty($pending['code']) || empty($pending['expires_at'])) {
 echo json_encode(['status' => 'error', 'message' => 'No OTP session found. Please sign in again.']);
 exit;
 }
 if ($pending['email'] !== $email) {
 echo json_encode(['status' => 'error', 'message' => 'OTP email mismatch. Please use the same email.']);
 exit;
 }
 if (new DateTime() > new DateTime($pending['expires_at'])) {
 unset($_SESSION['otp_login']);
 echo json_encode(['status' => 'error', 'message' => 'OTP expired. Please sign in again.']);
 exit;
 }
 if ($otp_code !== $pending['code']) {
 echo json_encode(['status' => 'error', 'message' => 'Invalid OTP. Please try again.']);
 exit;
 }

 $stmt = $conn->prepare("SELECT id, first_name, last_name, role, email_verified, is_approved FROM users WHERE id = ? LIMIT 1");
 $stmt->bind_param("i", $pending['user_id']);
 $stmt->execute();
 $user = $stmt->get_result()->fetch_assoc();
 $stmt->close();

 if (!$user) {
 unset($_SESSION['otp_login']);
 echo json_encode(['status' => 'error', 'message' => 'User not found.']);
 exit;
 }

 $stmt = $conn->prepare("INSERT INTO login_logs(user_id,email,ip_address,device,status) VALUES (?,?,?,?,?)");
 $successStatus = 'Success';
 $stmt->bind_param("issss", $user['id'], $email, $ip, $device, $successStatus);
 $stmt->execute();
 $stmt->close();

 session_regenerate_id(true);
 $_SESSION = [
 'user_id' => $user['id'],
 'first_name' => $user['first_name'],
 'last_name' => $user['last_name'],
 'role' => $user['role'],
 'is_approved'=> (bool)$user['is_approved'],
 ];
 unset($_SESSION['otp_login']);

 echo json_encode(['status' => 'success', 'redirect' => portal_url($user['role']), 'message' => 'OTP verified. Signing in...']);
 exit;
 }

 $db = $conn->query("SELECT DATABASE()")->fetch_row()[0];
 $cols = [];
 $cr = $conn->query("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users'");
 while ($r = $cr->fetch_row()) $cols[] = $r[0];
 $migrations = [
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS email_verified TINYINT(1) NOT NULL DEFAULT 0",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS verification_token VARCHAR(64) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS token_expires_at DATETIME DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token VARCHAR(64) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS reset_token_expires DATETIME DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS is_approved TINYINT(1) NOT NULL DEFAULT 0",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS phone_number VARCHAR(30) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS org_name VARCHAR(255) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS `position` VARCHAR(150) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS barangay_name VARCHAR(150) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS municipality VARCHAR(150) DEFAULT NULL",
 "ALTER TABLE users ADD COLUMN IF NOT EXISTS responder_type VARCHAR(30) DEFAULT NULL",
 ];
 foreach ($migrations as $q) $conn->query($q);
 if (!in_array('email_verified',$cols)) $conn->query("UPDATE users SET email_verified=1 WHERE created_at < NOW()");
 if (!in_array('is_approved',$cols)) { $conn->query("UPDATE users SET is_approved=1 WHERE role='community' OR role='user' OR role='admin'"); }
 $enumRes = $conn->query("SELECT COLUMN_TYPE FROM INFORMATION_SCHEMA.COLUMNS WHERE TABLE_SCHEMA='$db' AND TABLE_NAME='users' AND COLUMN_NAME='role'");
 if ($enumRes) { $enumRow=$enumRes->fetch_row(); if($enumRow && strpos($enumRow[0],'first_responder')===false) $conn->query("ALTER TABLE users MODIFY COLUMN role ENUM('user','community','barangay','lgu','first_responder','admin') NOT NULL DEFAULT 'community'"); }

 if ($portal === 'resend') {
 $stmt = $conn->prepare("SELECT id,first_name,email_verified FROM users WHERE email=? LIMIT 1");
 $stmt->bind_param("s",$email); $stmt->execute();
 $user = $stmt->get_result()->fetch_assoc(); $stmt->close();
 if ($user && !$user['email_verified']) {
 $tok = bin2hex(random_bytes(32)); $exp = date('Y-m-d H:i:s', strtotime('+24 hours'));
 $u = $conn->prepare("UPDATE users SET verification_token=?,token_expires_at=? WHERE id=?");
 $u->bind_param("ssi",$tok,$exp,$user['id']); $u->execute(); $u->close();
 try { require_once __DIR__.'/core/SenTriMailer.php'; sendVerificationEmail($email,$user['first_name'],$tok); } catch(Throwable $e){ error_log($e->getMessage()); }
 }
 echo json_encode(['status'=>'success','message'=>'If that account exists and is unverified, a new link has been sent.']); exit;
 }

 if (empty($email)||empty($password)) { echo json_encode(['status'=>'error','message'=>'All fields are required.']); exit; }



 if (isLockedOut($conn, $email)) {
 echo json_encode(['status'=>'error','message'=>'Too many failed attempts. Please wait 30 seconds before trying again.','lockout'=>true]);
 exit;
 }

 $stmt = $conn->prepare("SELECT id,first_name,last_name,password,role,email_verified,is_approved FROM users WHERE email=? LIMIT 1");
 $stmt->bind_param("s",$email); $stmt->execute(); $stmt->store_result();
 $log_status='Failed'; $log_uid=null; $resp=['status'=>'error','message'=>'Invalid credentials.'];
 $is_successful_login = false;

 if ($stmt->num_rows > 0) {
 $stmt->bind_result($id,$fn,$ln,$hash,$role,$verified,$approved);
 $stmt->fetch();
 if (password_verify($password,$hash)) {
 $community_roles = ['community', 'user'];
 if (!$verified && in_array($role, $community_roles, true)) {
 $stmt->close();
 $lg=$conn->prepare("INSERT INTO login_logs(user_id,email,ip_address,device,status)VALUES(?,?,?,?,?)");
 $s='Failed'; $lg->bind_param("issss",$id,$email,$ip,$device,$s); $lg->execute(); $lg->close();
 echo json_encode(['status'=>'error','message'=>'Please verify your email before signing in.','unverified'=>true,'email'=>$email]); exit;
 }
 switch($portal) {
 case 'barangay': $allowed=['barangay']; break;
 case 'lgu': $allowed=['lgu']; break;
 case 'first_responder': $allowed=['first_responder']; break;
 case 'admin': $allowed=['admin']; break;
 default: $allowed=['community','user']; break;
 }
 if (!in_array($role,$allowed,true)) {
 echo json_encode(['status'=>'error','message'=>'This account does not have access to the selected portal. Choose the correct portal for your role.']); exit;
 }
 if (!$approved && !in_array($role,['community','user','admin'])) {
 echo json_encode(['status'=>'error','message'=>'Your account is pending administrator approval. You will be notified once approved.','pending'=>true]); exit;
 }
 // Community users authenticate with email + password (same as other portals).
 // To add optional TOTP-based 2FA later, check a user preference flag here.
 session_regenerate_id(true);
 $_SESSION = ['user_id'=>$id,'first_name'=>$fn,'last_name'=>$ln,'role'=>$role,'is_approved'=>(bool)$approved];
 $log_status='Success'; $log_uid=$id;
 $is_successful_login = true;
 require_once __DIR__.'/config/auth.php';
 $resp=['status'=>'success','redirect'=>portal_url($role),'message'=>'Welcome, '.htmlspecialchars($fn).'!'];
 }
 }
 $stmt->close();

 $lg=$conn->prepare("INSERT INTO login_logs(user_id,email,ip_address,device,status)VALUES(?,?,?,?,?)");
 $lg->bind_param("issss",$log_uid,$email,$ip,$device,$log_status); $lg->execute(); $lg->close();

 if (!$is_successful_login) {
 $lockout = flagHighRiskAttempt($conn, $email);
 if ($lockout) {
 $resp = ['status'=>'error','message'=>'Too many failed attempts. Please wait 30 seconds before trying again.','lockout'=>true];
 }
 }

 echo json_encode($resp); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Sign In - SenTri Incident Reporting System</title>
<link rel="stylesheet" href="assets/vendor/fonts/fonts.css">
<link rel="stylesheet" href="assets/vendor/fontawesome/css/all.min.css">
<style>
:root{--navy:#0a3d62;--navy-dark:#062444;--navy-light:#1a5276;--gold:#f39c12;--gold-dark:#d68910;--green:#166534;--green-light:#16a34a;--red:#b91c1c;--purple:#5b21b6;--purple-light:#7c3aed;--text:#1a1a2e;--muted:#6b7280;--border:#e5e7eb;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
body{min-height:100vh;background:var(--navy-dark);display:flex;flex-direction:column;}
.gov-bar{background:#fff;border-bottom:4px solid var(--gold);padding:0 32px;display:flex;align-items:center;justify-content:space-between;height:60px;box-shadow:0 2px 12px rgba(0,0,0,0.15);}
.gov-brand{display:flex;align-items:center;gap:14px;text-decoration:none;}
.gov-seal{width:40px;height:40px;background:linear-gradient(135deg,var(--navy),var(--navy-light));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);border:2px solid var(--gold);}
.gov-text h1{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:800;color:var(--navy);}
.gov-text p{font-size:0.7rem;color:var(--muted);font-weight:500;}
.gov-links{display:flex;gap:20px;}
.gov-links a{font-size:0.82rem;color:var(--navy);text-decoration:none;font-weight:600;opacity:0.8;}
.gov-links a:hover{opacity:1;color:var(--gold-dark);}
.hero{background:linear-gradient(135deg,var(--navy-dark) 0%,var(--navy) 60%,#1a5276 100%);padding:18px 20px 0;position:relative;overflow:visible;}
.hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");}
.hero-inner{max-width:920px;margin:0 auto;display:flex;align-items:center;gap:20px;position:relative;z-index:1;}
.hero-eyebrow{font-size:0.68rem;font-weight:700;color:var(--gold);letter-spacing:1.5px;text-transform:uppercase;margin-bottom:4px;}
.hero-inner h2{font-family:'Poppins',sans-serif;font-size:1.5rem;font-weight:800;color:#fff;line-height:1.2;margin-bottom:3px;}
.hero-inner h2 span{color:var(--gold);}
.hero-inner p{font-size:0.8rem;color:rgba(255,255,255,0.7);line-height:1.45;max-width:420px;}
.hero-stats{display:flex;gap:18px;margin-top:10px;}
.stat-num{font-family:'Poppins',sans-serif;font-size:1.2rem;font-weight:800;color:var(--gold);}
.stat-lbl{font-size:0.65rem;color:rgba(255,255,255,0.55);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;}
.hero-badge{background:rgba(255,255,255,0.07);border:1px solid rgba(255,255,255,0.12);border-radius:12px;padding:10px 14px;flex-shrink:0;text-align:center;min-width:110px;margin-left:auto;}
.hero-badge i{font-size:1.5rem;color:var(--gold);display:block;margin-bottom:3px;}
.hero-badge span{font-size:0.68rem;color:rgba(255,255,255,0.65);font-weight:600;text-transform:uppercase;letter-spacing:0.8px;}
.portal-section{max-width:920px;margin:0 auto;padding:12px 16px 0;position:relative;z-index:1;}
.portal-lbl{font-size:0.65rem;font-weight:700;color:var(--gold);letter-spacing:1.5px;text-transform:uppercase;text-align:center;margin-bottom:8px;}
.portal-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:8px;transform:translateY(12px);}
.portal-card{background:#fff;border-radius:11px;padding:10px 6px;text-align:center;cursor:pointer;border:2.5px solid transparent;transition:border-color 0.2s,box-shadow 0.2s;box-shadow:0 4px 16px rgba(0,0,0,0.15);}
.portal-card:hover{box-shadow:0 6px 20px rgba(0,0,0,0.22);}
.portal-card.selected{box-shadow:0 6px 20px rgba(243,156,18,0.35);}
.p-community.selected{border-color:#2563eb;}.p-barangay.selected{border-color:#166534;}.p-lgu.selected{border-color:#0a3d62;}.p-responder.selected{border-color:#b91c1c;}.p-admin.selected{border-color:#5b21b6;}
.portal-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;margin:0 auto 5px;font-size:1rem;}
.p-community .portal-icon{background:#eff6ff;color:#2563eb;}.p-barangay .portal-icon{background:#f0fdf4;color:#166534;}.p-lgu .portal-icon{background:#f0f7ff;color:#0a3d62;}.p-responder .portal-icon{background:#fef2f2;color:#b91c1c;}.p-admin .portal-icon{background:#f5f3ff;color:#5b21b6;}
.portal-name{font-size:0.72rem;font-weight:700;color:#1a1a2e;line-height:1.25;}
.portal-sub{font-size:0.62rem;color:#6b7280;margin-top:1px;}
.form-wrap{max-width:480px;width:100%;margin:0 auto;padding:0 16px 28px;position:relative;z-index:2;}
.form-card{background:#fff;border-radius:16px;padding:24px 26px 20px;margin-top:20px;box-shadow:0 8px 32px rgba(0,0,0,0.2);width:100%;}
.portal-header{display:flex;align-items:center;gap:10px;margin-bottom:14px;padding-bottom:12px;border-bottom:2px solid #e5e7eb;}
.ph-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;flex-shrink:0;}
.ph-title{font-size:0.98rem;font-weight:800;color:#1a1a2e;}
.ph-sub{font-size:0.75rem;color:#6b7280;margin-top:2px;}
.official-notice{background:#fef9ec;border:1px solid #fde68a;border-radius:8px;padding:0 11px;font-size:0.75rem;color:#92400e;display:flex;align-items:flex-start;gap:7px;margin-bottom:0;line-height:1.4;max-height:0;opacity:0;overflow:hidden;border-color:transparent;transition:max-height 0.22s ease,opacity 0.18s ease,margin 0.22s ease,padding 0.22s ease;}
.official-notice.show{max-height:110px;opacity:1;margin-bottom:12px;padding:8px 11px;border-color:#fde68a;}
.official-notice i{margin-top:2px;color:#d97706;flex-shrink:0;}
.form-group{margin-bottom:11px;}
.form-group label{display:block;font-size:0.76rem;font-weight:600;color:#374151;margin-bottom:3px;}
.form-group input{width:100%;padding:9px 11px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:0.86rem;font-family:'Inter',sans-serif;outline:none;background:#fafafa;transition:border-color 0.15s,box-shadow 0.15s;color:#1a1a2e;}
.form-group input:focus{border-color:#93c5fd;background:#fff;box-shadow:0 0 0 3px rgba(59,130,246,0.09);}
.pw-wrap{position:relative;}
.pw-wrap .toggle-pw{position:absolute;right:10px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:#6b7280;font-size:0.75rem;font-weight:700;font-family:'Inter',sans-serif;}
.forgot-row{display:flex;justify-content:flex-end;margin-top:-4px;margin-bottom:12px;}
.forgot-row a{font-size:0.75rem;color:#2563eb;text-decoration:none;font-weight:600;}
.btn-login{width:100%;min-height:42px;padding:10px 14px;border:none;border-radius:9px;font-size:0.9rem;font-weight:700;font-family:'Inter',sans-serif;cursor:pointer;color:#fff;background:#2563eb;background-image:linear-gradient(135deg,#3b82f6,#2563eb);display:flex;align-items:center;justify-content:center;gap:8px;box-shadow:0 2px 8px rgba(0,0,0,0.12);transition:filter 0.15s ease,box-shadow 0.15s ease;}
.btn-login:hover:not(:disabled){filter:brightness(1.05);box-shadow:0 4px 14px rgba(0,0,0,0.2);}
.btn-login:active:not(:disabled){transform:scale(0.99);}
.btn-login:disabled{opacity:0.6;cursor:not-allowed;background:#9ca3af!important;background-image:none!important;}
.msg{padding:8px 12px;border-radius:8px;font-size:0.8rem;font-weight:500;margin-bottom:11px;display:none;text-align:center;}
.msg.error{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.msg.success{background:#f0fdf4;color:#14532d;border:1px solid #bbf7d0;}
.signup-row{text-align:center;margin-top:10px;font-size:0.8rem;color:#6b7280;max-height:40px;opacity:1;overflow:hidden;transition:max-height 0.2s ease,opacity 0.18s ease,margin 0.2s ease;}
.signup-row.hide{max-height:0;opacity:0;margin-top:0;pointer-events:none;}
.signup-row a{color:#2563eb;font-weight:700;text-decoration:none;}
.resend-box{background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:11px;margin-top:9px;display:none;}
.resend-box p{font-size:0.78rem;color:#92400e;margin-bottom:8px;line-height:1.45;}
.resend-box input{width:100%;padding:8px 11px;border:1.5px solid #e5e7eb;border-radius:7px;font-size:0.84rem;outline:none;font-family:'Inter',sans-serif;margin-bottom:6px;}
.btn-resend{width:100%;background:#d97706;color:#fff;border:none;padding:8px;border-radius:7px;font-size:0.81rem;font-weight:700;cursor:pointer;font-family:'Inter',sans-serif;}
.page-notice{max-width:920px;margin:12px auto 0;padding:0 24px;}
.notice{padding:10px 14px;border-radius:9px;font-size:0.81rem;font-weight:500;display:flex;align-items:flex-start;gap:8px;line-height:1.45;}
.notice.info{background:#eff6ff;color:#1d4ed8;border:1px solid #bfdbfe;}
.notice.success{background:#f0fdf4;color:#15803d;border:1px solid #bbf7d0;}
.site-footer{background:var(--navy-dark);border-top:1px solid rgba(255,255,255,0.07);padding:16px 24px;text-align:center;margin-top:auto;}
.site-footer p{font-size:0.74rem;color:rgba(255,255,255,0.35);}
@media(max-width:840px){.portal-grid{grid-template-columns:repeat(5,1fr);gap:6px;transform:translateY(10px);}.hero-badge{display:none;}.gov-links{display:none;}.form-card{padding:22px 20px 18px;margin-top:18px;}.form-wrap{max-width:480px;padding:0 16px 24px;}.portal-section{padding:12px 14px 0;}.portal-card{padding:8px 4px;}.portal-name{font-size:0.68rem;}.portal-sub{display:none;}}
@media(max-width:580px){.portal-grid{grid-template-columns:repeat(5,1fr);gap:4px;transform:translateY(8px);}.portal-card{padding:6px 2px;border-radius:8px;}.portal-icon{width:30px;height:30px;font-size:0.85rem;margin-bottom:3px;}.portal-name{font-size:0.62rem;}.portal-sub{display:none;}.hero-inner h2{font-size:1.3rem;}.hero-stats{gap:12px;}.form-card{padding:18px 14px 16px;margin-top:14px;}.form-wrap{max-width:100%;padding:0 12px 20px;}}

/* ── Motion ── */
@keyframes iconSwap{0%{opacity:0;transform:scale(.85);}100%{opacity:1;transform:scale(1);}}
@keyframes msgError{0%{opacity:0;transform:translateY(-4px);}50%{opacity:1;transform:translateY(0);}100%{transform:translateY(0);}}
@keyframes msgSuccess{from{opacity:0;transform:translateY(-4px);}to{opacity:1;transform:translateY(0);}}

.portal-card.justSelected{box-shadow:0 0 0 3px rgba(243,156,18,0.4),0 6px 20px rgba(243,156,18,0.3);}
.portal-card:focus-visible{outline:2.5px solid var(--gold);outline-offset:2px;}
.ph-icon i{animation:iconSwap .2s ease;}
.msg.error{animation:msgError .3s ease;}
.msg.success{animation:msgSuccess .25s ease;}

@media(prefers-reduced-motion:reduce){*,*::before,*::after{animation-duration:.01ms!important;animation-iteration-count:1!important;transition-duration:.01ms!important;}}
</style>
</head>
<body>
<!-- Your HTML remains the same -->
<header class="gov-bar">
 <a href="index.php" class="gov-brand">
 <div class="gov-seal"><i class="fas fa-shield-halved"></i></div>
 <div class="gov-text"><h1>SenTri</h1><p>Community Safety Incident Reporting System</p></div>
 </a>
 <nav class="gov-links">
 <a href="index.php">Home</a>
 <a href="signup.php">Register</a>
 <a href="forgot_password.php">Forgot Password</a>
 </nav>
</header>

<section class="hero">
 <div class="hero-inner">
 <div>
 <p class="hero-eyebrow">Official Portal Access</p>
 <h2>Sign In to <span>SenTri</span></h2>
 <p>Select your portal and enter your credentials. Each portal is restricted to its designated role.</p>
 <div class="hero-stats">
 <div><div class="stat-num">5</div><div class="stat-lbl">Portal Types</div></div>
 <div><div class="stat-num">24/7</div><div class="stat-lbl">Monitoring</div></div>
 </div>
 </div>
 <div class="hero-badge"><i class="fas fa-shield-halved"></i><span>Secure<br>Gov Portal</span></div>
 </div>
 <div class="portal-section">
 <p class="portal-lbl">Choose Your Portal</p>
 <div class="portal-grid">
 <div class="portal-card p-community selected" tabindex="0" onclick="selectPortal('community',this)"><div class="portal-icon"><i class="fas fa-users"></i></div><div class="portal-name">Community</div><div class="portal-sub">Citizens</div></div>
 <div class="portal-card p-barangay" tabindex="0" onclick="selectPortal('barangay',this)"><div class="portal-icon"><i class="fas fa-house-flag"></i></div><div class="portal-name">Barangay</div><div class="portal-sub">Officials</div></div>
 <div class="portal-card p-lgu" tabindex="0" onclick="selectPortal('lgu',this)"><div class="portal-icon"><i class="fas fa-landmark"></i></div><div class="portal-name">LGU</div><div class="portal-sub">City / Municipal</div></div>
 <div class="portal-card p-responder" tabindex="0" onclick="selectPortal('first_responder',this)"><div class="portal-icon"><i class="fas fa-truck-medical"></i></div><div class="portal-name">First Responder</div><div class="portal-sub">BFP / PNP / EMS</div></div>
 <div class="portal-card p-admin" tabindex="0" onclick="selectPortal('admin',this)"><div class="portal-icon"><i class="fas fa-gear"></i></div><div class="portal-name">Admin</div><div class="portal-sub">System Access</div></div>
 </div>
 </div>
</section>

<?php if(isset($_GET['verify_sent'])): ?><div class="page-notice"><div class="notice info"><i class="fas fa-envelope"></i><span>Verification link sent. Check your inbox and spam folder.</span></div></div>
<?php elseif(isset($_GET['reset'])): ?><div class="page-notice"><div class="notice success"><i class="fas fa-circle-check"></i><span>Password reset successfully. You may now sign in.</span></div></div>
<?php elseif(isset($_GET['pending'])): ?><div class="page-notice"><div class="notice info"><i class="fas fa-clock"></i><span>Your official account is awaiting administrator approval.</span></div></div>
<?php endif; ?>

<div class="form-wrap">
 <div class="form-card">
 <div class="portal-header">
 <div class="ph-icon" id="phIcon" style="background:#eff6ff;color:#2563eb;"><i class="fas fa-users"></i></div>
 <div><div class="ph-title" id="phTitle">Community Member Sign In</div><div class="ph-sub" id="phSub">Access the citizen incident reporting dashboard</div></div>
 </div>
 <div class="official-notice" id="officialNotice"><i class="fas fa-triangle-exclamation"></i><span>This portal requires an approved official account. No email verification is needed - your account is activated directly by the system administrator. To register, <a href="signup.php" style="color:#92400e;font-weight:700;">create an account</a> and await administrator approval.</span></div>
 <div id="loginMsg" class="msg"></div>
 <form id="loginForm" novalidate>
 <input type="hidden" name="portal" id="portalField" value="community">
 <div class="form-group"><label>Email Address</label><input type="email" name="email" id="emailField" placeholder="you@example.com" required autocomplete="email"></div>
 <div class="form-group"><label>Password</label><div class="pw-wrap"><input type="password" name="password" id="pwField" placeholder="••••••••" required><button type="button" class="toggle-pw" onclick="togglePw()">SHOW</button></div></div>
 <div class="forgot-row"><a href="forgot_password.php">Forgot Password?</a></div>
 <button type="submit" class="btn-login" id="loginBtn" style="background:linear-gradient(135deg,#3b82f6,#2563eb);"><i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">Sign In to Community Portal</span></button>
 </form>
 <div id="otpSection" style="display:none;">
 <form id="otpForm" novalidate>
 <input type="hidden" name="action" value="verify_otp">
 <input type="hidden" name="email" id="otpEmail" value="">
 <input type="hidden" name="portal" value="community">
 <div class="form-group"><label>One-Time Code</label><input type="text" name="otp" id="otpField" placeholder="123456" maxlength="6" pattern="[0-9]*" inputmode="numeric" required></div>
 <button type="submit" class="btn-login" id="otpSubmitBtn" style="background:linear-gradient(135deg,#3b82f6,#2563eb);"><i class="fas fa-key"></i> Verify OTP</button>
 <button type="button" class="btn-login" id="otpBackBtn" style="background:#f3f4f6;color:#1f2937;margin-top:10px;"><i class="fas fa-arrow-left"></i> Back to Password</button>
 </form>
 </div>
 <div id="resendBox" class="resend-box">
 <p><i class="fas fa-envelope-circle-check" style="color:#d97706;margin-right:5px;"></i>Your email is not verified. Enter your email to resend the verification link.</p>
 <input type="email" id="resendEmail" placeholder="you@example.com">
 <button class="btn-resend" id="resendBtn" onclick="resend()"><i class="fas fa-paper-plane"></i> Resend Verification Email</button>
 </div>
 <div class="signup-row" id="signupRow">Don't have an account? <a href="signup.php">Register here</a></div>
 </div>
</div>
<footer class="site-footer"><p>SenTri Community Safety Incident Reporting System - For official and community use only.</p></footer>
<script>
const portals={
 community: {title:'Community Member Sign In', sub:'Access the citizen incident reporting dashboard', icon:'fa-users', bg:'#eff6ff', color:'#2563eb', btn:'linear-gradient(135deg,#3b82f6,#2563eb)', label:'Sign In to Community Portal', official:false},
 barangay: {title:'Barangay Official Sign In', sub:'Barangay operations and incident management portal', icon:'fa-house-flag', bg:'#f0fdf4', color:'#166534', btn:'linear-gradient(135deg,#16a34a,#166534)', label:'Sign In to Barangay Portal', official:true},
 lgu: {title:'LGU Official Sign In', sub:'City and municipal government incident oversight portal', icon:'fa-landmark', bg:'#f0f7ff', color:'#0a3d62', btn:'linear-gradient(135deg,#1a5276,#062444)', label:'Sign In to LGU Portal', official:true},
 first_responder:{title:'First Responder Sign In', sub:'Emergency dispatch and active incident response portal', icon:'fa-truck-medical', bg:'#fef2f2', color:'#b91c1c', btn:'linear-gradient(135deg,#ef4444,#b91c1c)', label:'Sign In to Responder Portal', official:true},
 admin: {title:'System Administrator Sign In', sub:'Default credentials: username <b>admin</b> / password <b>admin</b>', icon:'fa-gear', bg:'#f5f3ff', color:'#5b21b6', btn:'linear-gradient(135deg,#7c3aed,#5b21b6)', label:'Access Admin Panel', official:true},
};
let current='community';
let timerInterval = null;

document.querySelectorAll('.portal-card').forEach(c=>{
 c.addEventListener('keydown',e=>{if(e.key==='Enter'||e.key===' '){e.preventDefault();c.click();}});
});

function selectPortal(key,el){
 current=key;
 document.querySelectorAll('.portal-card').forEach(c=>c.classList.remove('selected','justSelected'));
 if(el) el.classList.add('selected','justSelected');
 setTimeout(()=>{ if(el) el.classList.remove('justSelected'); }, 250);
 const p=portals[key];
 const pi=document.getElementById('phIcon');
 pi.style.backgroundColor=p.bg;
 pi.style.color=p.color;
 pi.innerHTML=`<i class="fas ${p.icon}"></i>`;
 document.getElementById('phTitle').textContent=p.title;
 document.getElementById('phSub').innerHTML=p.sub;
 document.getElementById('portalField').value=key;
 const btn=document.getElementById('loginBtn');
 btn.style.backgroundColor=p.color;
 btn.style.background=p.btn;
 const lbl=document.getElementById('loginBtnLabel');
 if(lbl){lbl.textContent=p.label;}else{btn.innerHTML=`<i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">${p.label}</span>`;}
 const on=document.getElementById('officialNotice');
 if(p.official){on.classList.add('show');}else{on.classList.remove('show');}
 const signup=document.getElementById('signupRow');
 if(key==='community'){signup.classList.remove('hide');}else{signup.classList.add('hide');}
 if(key==='admin'){ 
 document.getElementById('emailField').value='admin'; 
 document.getElementById('pwField').value='admin'; 
 } else { 
 document.getElementById('emailField').value=''; 
 document.getElementById('pwField').value=''; 
 }
 document.getElementById('loginMsg').style.display='none';
 document.getElementById('resendBox').style.display='none';
}

function togglePw(){const f=document.getElementById('pwField');const b=f.nextElementSibling;const s=f.type==='password';f.type=s?'text':'password';b.textContent=s?'HIDE':'SHOW';}

function showOtpForm(email){
 const loginForm=document.getElementById('loginForm');
 const otpSection=document.getElementById('otpSection');
 const otpEmail=document.getElementById('otpEmail');
 const otpField=document.getElementById('otpField');
 loginForm.style.display='none';
 otpSection.style.display='block';
 otpEmail.value=email;
 otpField.value='';
 otpField.focus();
 document.getElementById('resendBox').style.display='none';
}

function hideOtpForm(){
 document.getElementById('otpSection').style.display='none';
 document.getElementById('loginForm').style.display='block';
 document.getElementById('loginMsg').style.display='none';
}

document.getElementById('otpBackBtn').addEventListener('click',()=>hideOtpForm());

document.getElementById('otpForm').addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('otpSubmitBtn');const msg=document.getElementById('loginMsg');const orig=btn.innerHTML;
 btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Verifying...';msg.style.display='none';
 try{
 const res=await fetch('login.php',{method:'POST',body:new FormData(e.target)});
 const data=await res.json();
 if(data.status==='success'){showMsg('success',data.message||'Redirecting...');setTimeout(()=>window.location.href=data.redirect,700);}
 else{
 showMsg('error',data.message||'Invalid OTP.');
 btn.disabled=false;btn.innerHTML=orig;
 if(data.lockout){startLockTimer(30);} 
 }
 }catch{showMsg('error','Connection error. Please try again.');btn.disabled=false;btn.innerHTML=orig;}
});

function startLockTimer(seconds) {
 const btn = document.getElementById('loginBtn');
 const msg = document.getElementById('loginMsg');
 btn.disabled = true;
 let timeLeft = seconds;

 if (timerInterval) clearInterval(timerInterval);

 showMsg('error', `Too many failed attempts. Please wait ${timeLeft} seconds before trying again.`);

 timerInterval = setInterval(() => {
 timeLeft--;
 btn.innerHTML = `<i class="fas fa-lock"></i> Wait ${timeLeft}s`;
 showMsg('error', `Too many failed attempts. Please wait ${timeLeft} seconds before trying again.`);
 if (timeLeft <= 0) {
 clearInterval(timerInterval);
 btn.disabled = false;
 const p = portals[current];
 btn.style.backgroundColor=p.color;
 btn.style.backgroundImage=p.btn;
 btn.innerHTML = `<i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">${p.label}</span>`;
 msg.style.display = 'none';
 }
 }, 1000);
}

document.getElementById('loginForm').addEventListener('submit',async e=>{
 e.preventDefault();
 const btn=document.getElementById('loginBtn');const msg=document.getElementById('loginMsg');const orig=btn.innerHTML;
 btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Signing in...';msg.style.display='none';
 try{
 const res=await fetch('login.php',{method:'POST',body:new FormData(e.target)});
 const data=await res.json();
 if(data.status==='success'){showMsg('success',data.message||'Redirecting...');setTimeout(()=>window.location.href=data.redirect,700);}
 else if(data.status==='otp_required'){
 showMsg('success',data.message||'Enter the 6-digit code from your email.');
 showOtpForm(data.email || document.getElementById('emailField').value);
 btn.disabled=false;
 btn.innerHTML=orig;
 }
 else{
 showMsg('error',data.message||'Sign in failed.');
 if(data.lockout) {
 startLockTimer(30);
 } else {
 btn.disabled=false;
 const p = portals[current];
 btn.style.backgroundColor=p.color;
 btn.style.backgroundImage=p.btn;
 btn.innerHTML = `<i class="fas fa-right-to-bracket" id="loginBtnIcon"></i> <span id="loginBtnLabel">${p.label}</span>`;
 }
 if(data.unverified){document.getElementById('resendBox').style.display='block';document.getElementById('resendEmail').value=document.getElementById('emailField').value;}
 }
 }catch{showMsg('error','Connection error. Please try again.');btn.disabled=false;btn.innerHTML=orig;}
});

async function resend(){
 const email=document.getElementById('resendEmail').value.trim();const btn=document.getElementById('resendBtn');
 if(!email)return;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending...';
 const fd=new FormData();fd.append('portal','resend');fd.append('email',email);fd.append('password','__resend__');
 try{const res=await fetch('login.php',{method:'POST',body:fd});const data=await res.json();showMsg('success',data.message);btn.innerHTML='<i class="fas fa-check"></i> Sent!';}
 catch{btn.disabled=false;btn.innerHTML='<i class="fas fa-paper-plane"></i> Resend';}
}
function showMsg(type,text){const el=document.getElementById('loginMsg');el.className='msg '+type;el.textContent=text;el.style.display='block';}
</script>
</body>
</html>
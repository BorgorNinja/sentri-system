<?php
session_start();
require __DIR__ . '/config/db.php';

$token  = trim($_GET['token'] ?? '');
$status = 'invalid';

if (!empty($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = $conn->prepare(
        "SELECT id, first_name, email_verified, token_expires_at FROM users WHERE verification_token = ? LIMIT 1"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user) {
        $status = 'invalid';
    } elseif ($user['email_verified']) {
        $status = 'already';
    } elseif (strtotime($user['token_expires_at']) < time()) {
        $status = 'expired';
    } else {
        $upd = $conn->prepare(
            "UPDATE users SET email_verified=1, verification_token=NULL, token_expires_at=NULL WHERE id=?"
        );
        $upd->bind_param("i", $user['id']);
        $upd->execute();
        $upd->close();
        $status    = 'success';
        $firstname = htmlspecialchars($user['first_name']);
    }
}

// Handle resend POST
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend') {
    header('Content-Type: application/json');
    $resendEmail = trim($_POST['email'] ?? '');
    if (!filter_var($resendEmail, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error','message'=>'Invalid email.']); exit;
    }
    $stmt = $conn->prepare("SELECT id, first_name, email_verified FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $resendEmail);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if (!$user || $user['email_verified']) {
        echo json_encode(['status'=>'success','message'=>'If that email is pending verification, a new link has been sent.']); exit;
    }
    $newToken  = bin2hex(random_bytes(32));
    $expiresAt = date('Y-m-d H:i:s', strtotime('+24 hours'));
    $upd = $conn->prepare("UPDATE users SET verification_token=?, token_expires_at=? WHERE id=?");
    $upd->bind_param("ssi", $newToken, $expiresAt, $user['id']);
    $upd->execute(); $upd->close();
    try {
        require_once __DIR__ . '/core/SenTriMailer.php';
        sendVerificationEmail($resendEmail, $user['first_name'], $newToken);
    } catch (Throwable $e) { error_log('SenTri resend: ' . $e->getMessage()); }
    echo json_encode(['status'=>'success','message'=>'Verification email resent. Please check your inbox.']); exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Email Verification – SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--blue:#1c57b2;--blue-light:#3a8dff;--green:#38a169;--amber:#f59e0b;--red:#c62828;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif;}
body{background:#0a0f1e;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.bg-canvas{position:fixed;inset:0;z-index:0;overflow:hidden;}
.bg-canvas::before{content:'';position:absolute;width:600px;height:600px;background:radial-gradient(circle,rgba(58,141,255,0.12) 0%,transparent 70%);top:-200px;left:-100px;animation:drift1 9s ease-in-out infinite alternate;}
.bg-canvas::after{content:'';position:absolute;width:400px;height:400px;background:radial-gradient(circle,rgba(56,161,105,0.1) 0%,transparent 70%);bottom:-100px;right:-100px;animation:drift2 11s ease-in-out infinite alternate;}
.particle{position:absolute;border-radius:50%;animation:float linear infinite;}
@keyframes drift1{from{transform:translate(0,0);}to{transform:translate(-30px,30px);}}
@keyframes drift2{from{transform:translate(0,0);}to{transform:translate(30px,-30px);}}
@keyframes float{0%{transform:translateY(100vh);opacity:0;}10%{opacity:1;}90%{opacity:1;}100%{transform:translateY(-100px);opacity:0;}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
@keyframes pop{0%{transform:scale(0.7);opacity:0;}70%{transform:scale(1.1);}100%{transform:scale(1);opacity:1;}}
.card{background:#fff;border-radius:20px;padding:48px 44px;max-width:480px;width:100%;text-align:center;position:relative;z-index:1;animation:fadeInUp 0.55s cubic-bezier(0.22,1,0.36,1) both;box-shadow:0 20px 60px rgba(0,0,0,0.35);}
.icon-wrap{width:80px;height:80px;border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 24px;font-size:2.2rem;animation:pop 0.5s cubic-bezier(0.22,1,0.36,1) 0.2s both;}
.icon-wrap.success{background:#e8f5e9;color:var(--green);}
.icon-wrap.expired{background:#fff8e1;color:var(--amber);}
.icon-wrap.invalid{background:#ffebee;color:var(--red);}
.icon-wrap.already{background:#e3f2fd;color:var(--blue);}
.brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:32px;text-decoration:none;}
.brand-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--blue-light),var(--blue));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;}
.brand-name{font-size:1.3rem;font-weight:800;color:#1a1a2e;}
h1{font-size:1.5rem;font-weight:800;color:#1a1a2e;margin-bottom:10px;letter-spacing:-0.4px;}
p{font-size:0.92rem;color:#555;line-height:1.7;margin-bottom:24px;}
p strong{color:#1a1a2e;}
.btn{display:inline-block;padding:12px 28px;border-radius:10px;font-size:0.93rem;font-weight:700;text-decoration:none;cursor:pointer;border:none;font-family:'Poppins',sans-serif;transition:all 0.25s;}
.btn-primary{background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;width:100%;margin-bottom:10px;display:block;}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(28,87,178,0.4);}
.btn-primary:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
.btn-ghost{color:var(--blue);font-size:0.85rem;font-weight:600;background:none;border:none;cursor:pointer;font-family:'Poppins',sans-serif;text-decoration:underline;}
.form-group{margin-bottom:14px;text-align:left;}
.form-group label{display:block;font-size:0.81rem;font-weight:600;color:#444;margin-bottom:5px;}
.form-group input{width:100%;padding:11px 15px;border:1.5px solid #e0e0e0;border-radius:10px;font-size:0.92rem;outline:none;font-family:'Poppins',sans-serif;background:#fafafa;transition:all 0.2s;}
.form-group input:focus{border-color:var(--blue-light);background:#fff;box-shadow:0 0 0 4px rgba(58,141,255,0.1);}
.feedback{padding:11px 16px;border-radius:9px;font-size:0.85rem;margin-bottom:14px;display:none;font-weight:500;}
.feedback.success{background:#e8f5e9;color:#2e7d32;}
.feedback.error{background:#ffebee;color:#c62828;}
.divider{border:none;border-top:1px solid #eee;margin:20px 0;}
</style>
</head>
<body>
<div class="bg-canvas"><div id="particles" style="position:absolute;inset:0;overflow:hidden;"></div></div>
<div class="card">
  <a href="index.php" class="brand">
    <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
    <span class="brand-name">SenTri</span>
  </a>

  <?php if ($status === 'success'): ?>
    <div class="icon-wrap success"><i class="fas fa-circle-check"></i></div>
    <h1>Email Verified!</h1>
    <p>Welcome to SenTri, <strong><?= $firstname ?></strong>! Your account is now active. You can sign in and start contributing to your community's safety.</p>
    <a href="login.php" class="btn btn-primary"><i class="fas fa-right-to-bracket"></i> Sign In Now</a>

  <?php elseif ($status === 'already'): ?>
    <div class="icon-wrap already"><i class="fas fa-envelope-open-text"></i></div>
    <h1>Already Verified</h1>
    <p>This email address has already been verified. You can sign in to your account.</p>
    <a href="login.php" class="btn btn-primary"><i class="fas fa-right-to-bracket"></i> Go to Sign In</a>

  <?php elseif ($status === 'expired'): ?>
    <div class="icon-wrap expired"><i class="fas fa-clock"></i></div>
    <h1>Link Expired</h1>
    <p>Your verification link has expired (links are valid for 24 hours). Enter your email below and we'll send a fresh one.</p>
    <div id="resendFeedback" class="feedback"></div>
    <div class="form-group"><label>Email Address</label><input type="email" id="resendEmail" placeholder="you@example.com"></div>
    <button class="btn btn-primary" id="resendBtn" onclick="resend()"><i class="fas fa-paper-plane"></i> Resend Verification Email</button>
    <hr class="divider">
    <a href="login.php" class="btn-ghost">Back to Sign In</a>

  <?php else: ?>
    <div class="icon-wrap invalid"><i class="fas fa-triangle-exclamation"></i></div>
    <h1>Invalid Link</h1>
    <p>This verification link is not valid or has already been used. If you need a new link, enter your email below.</p>
    <div id="resendFeedback" class="feedback"></div>
    <div class="form-group"><label>Email Address</label><input type="email" id="resendEmail" placeholder="you@example.com"></div>
    <button class="btn btn-primary" id="resendBtn" onclick="resend()"><i class="fas fa-paper-plane"></i> Request New Verification Email</button>
    <hr class="divider">
    <a href="login.php" class="btn-ghost">Back to Sign In</a>
  <?php endif; ?>
</div>

<script>
(function(){
  const c=document.getElementById('particles');
  for(let i=0;i<30;i++){const p=document.createElement('div');p.className='particle';p.style.cssText=`left:${Math.random()*100}%;animation-duration:${7+Math.random()*10}s;animation-delay:${-Math.random()*17}s;width:${1+Math.random()*2}px;height:${1+Math.random()*2}px;background:rgba(255,255,255,${0.2+Math.random()*0.4});`;c.appendChild(p);}
})();
async function resend(){
  const email=document.getElementById('resendEmail')?.value?.trim();
  const btn=document.getElementById('resendBtn');
  if(!email){showFb('error','Please enter your email address.');return;}
  const orig=btn.innerHTML;btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending...';
  try{
    const fd=new FormData();fd.append('action','resend');fd.append('email',email);
    const res=await fetch('verify_email.php',{method:'POST',body:fd});
    const data=await res.json();
    showFb(data.status,data.message);
    if(data.status==='success')btn.innerHTML='<i class="fas fa-check"></i> Sent!';
    else{btn.disabled=false;btn.innerHTML=orig;}
  }catch{showFb('error','Something went wrong.');btn.disabled=false;btn.innerHTML=orig;}
}
function showFb(type,msg){const fb=document.getElementById('resendFeedback');fb.className='feedback '+type;fb.textContent=msg;fb.style.display='block';}
</script>
</body>
</html>

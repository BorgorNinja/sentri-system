<?php
session_start();
if (isset($_SESSION['user_id'])) { require_once __DIR__ . '/config/auth.php'; redirect_to_portal(); }
require __DIR__ . '/config/db.php';

$token     = trim($_GET['token'] ?? '');
$tokenOk   = false;
$tokenUser = null;

// Validate token on every GET/POST
if (!empty($token) && preg_match('/^[a-f0-9]{64}$/', $token)) {
    $stmt = $conn->prepare(
        "SELECT id, first_name, reset_token_expires FROM users
         WHERE reset_token = ? AND reset_token_expires > NOW() LIMIT 1"
    );
    $stmt->bind_param("s", $token);
    $stmt->execute();
    $res  = $stmt->get_result();
    $tokenUser = $res->fetch_assoc();
    $stmt->close();
    if ($tokenUser) $tokenOk = true;
}

// Handle POST — set new password
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');

    if (!$tokenOk) {
        echo json_encode(['status'=>'error','message'=>'This reset link is invalid or has expired. Please request a new one.']); exit;
    }

    $pw  = $_POST['password']         ?? '';
    $pw2 = $_POST['confirm_password'] ?? '';

    if (strlen($pw) < 8) {
        echo json_encode(['status'=>'error','message'=>'Password must be at least 8 characters.']); exit;
    }
    if ($pw !== $pw2) {
        echo json_encode(['status'=>'error','message'=>'Passwords do not match.']); exit;
    }

    $hash = password_hash($pw, PASSWORD_BCRYPT, ['cost' => 12]);

    // Update password + clear reset token + ensure verified
    $upd = $conn->prepare(
        "UPDATE users SET password=?, reset_token=NULL, reset_token_expires=NULL, email_verified=1
         WHERE id=?"
    );
    $upd->bind_param("si", $hash, $tokenUser['id']);
    $upd->execute(); $upd->close();

    echo json_encode([
        'status'   => 'success',
        'message'  => 'Password reset successfully! Redirecting to sign in...',
        'redirect' => 'login.php?reset=success',
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Reset Password – SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--blue:#1c57b2;--blue-light:#3a8dff;--text:#1a1a2e;--muted:#666;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif;}
body{background:#0a0f1e;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.bg-canvas{position:fixed;inset:0;z-index:0;overflow:hidden;}
.bg-canvas::before{content:'';position:absolute;width:600px;height:600px;background:radial-gradient(circle,rgba(58,141,255,0.12) 0%,transparent 70%);top:-200px;left:-100px;animation:drift1 9s ease-in-out infinite alternate;}
.bg-canvas::after{content:'';position:absolute;width:400px;height:400px;background:radial-gradient(circle,rgba(56,161,105,0.08) 0%,transparent 70%);bottom:-100px;right:-100px;animation:drift2 11s ease-in-out infinite alternate;}
.particle{position:absolute;border-radius:50%;animation:float linear infinite;}
@keyframes drift1{from{transform:translate(0,0);}to{transform:translate(-30px,30px);}}
@keyframes drift2{from{transform:translate(0,0);}to{transform:translate(30px,-30px);}}
@keyframes float{0%{transform:translateY(100vh);opacity:0;}10%{opacity:1;}90%{opacity:1;}100%{transform:translateY(-100px);opacity:0;}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
.card{background:#fff;border-radius:20px;padding:48px 44px;max-width:460px;width:100%;position:relative;z-index:1;animation:fadeInUp 0.55s cubic-bezier(0.22,1,0.36,1) both;box-shadow:0 20px 60px rgba(0,0,0,0.35);}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:28px;text-decoration:none;}
.brand-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--blue-light),var(--blue));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;}
.brand-name{font-size:1.3rem;font-weight:800;color:var(--text);}
.icon-wrap{width:64px;height:64px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;margin-bottom:20px;}
.icon-wrap.ok{background:#e8f5e9;color:#38a169;}
.icon-wrap.err{background:#ffebee;color:#c62828;}
h1{font-size:1.45rem;font-weight:800;color:var(--text);margin-bottom:8px;letter-spacing:-0.4px;}
p.sub{font-size:0.9rem;color:var(--muted);line-height:1.7;margin-bottom:24px;}
.form-group{margin-bottom:16px;position:relative;}
.form-group label{display:block;font-size:0.82rem;font-weight:600;color:#444;margin-bottom:6px;}
.form-group input{width:100%;padding:12px 15px;border:1.5px solid #e0e0e0;border-radius:10px;font-size:0.93rem;outline:none;font-family:'Poppins',sans-serif;background:#fafafa;transition:all 0.2s;}
.form-group input:focus{border-color:var(--blue-light);background:#fff;box-shadow:0 0 0 4px rgba(58,141,255,0.1);}
.show-btn{position:absolute;right:13px;bottom:12px;font-size:0.72rem;color:var(--blue);cursor:pointer;font-weight:700;user-select:none;}
.btn-primary{width:100%;background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;border:none;padding:13px;border-radius:10px;font-size:0.96rem;font-weight:700;cursor:pointer;transition:all 0.25s;font-family:'Poppins',sans-serif;}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(28,87,178,0.4);}
.btn-primary:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
.btn-ghost-link{display:block;text-align:center;margin-top:16px;font-size:0.85rem;color:var(--blue);font-weight:600;text-decoration:none;}
.btn-ghost-link:hover{text-decoration:underline;}
.msg{padding:12px 16px;border-radius:9px;font-size:0.85rem;margin-bottom:16px;display:none;text-align:center;font-weight:500;}
.msg.success{background:#e8f5e9;color:#2e7d32;}
.msg.error{background:#ffebee;color:#c62828;}
.pw-strength{height:4px;border-radius:4px;margin-top:6px;background:#eee;overflow:hidden;}
.pw-strength-bar{height:100%;border-radius:4px;transition:all 0.3s;}
.pw-rules{font-size:0.76rem;color:#aaa;margin-top:4px;}
</style>
</head>
<body>
<div class="bg-canvas"><div id="particles" style="position:absolute;inset:0;overflow:hidden;"></div></div>
<div class="card">
  <a href="index.php" class="brand">
    <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
    <span class="brand-name">SenTri</span>
  </a>

  <?php if ($tokenOk): ?>
    <div class="icon-wrap ok"><i class="fas fa-lock-open"></i></div>
    <h1>Set New Password</h1>
    <p class="sub">Hi <strong><?= htmlspecialchars($tokenUser['first_name']) ?></strong>, enter and confirm your new password below.</p>
    <div id="msg" class="msg"></div>
    <form id="resetForm" novalidate>
      <input type="hidden" name="token" value="<?= htmlspecialchars($token) ?>">
      <div class="form-group">
        <label>New Password</label>
        <input type="password" id="password" name="password" placeholder="Min. 8 characters" required oninput="checkStrength(this.value)">
        <span class="show-btn" onclick="togglePw('password',this)">SHOW</span>
        <div class="pw-strength"><div class="pw-strength-bar" id="pwBar"></div></div>
        <div class="pw-rules">Use at least 8 characters with letters and numbers.</div>
      </div>
      <div class="form-group">
        <label>Confirm New Password</label>
        <input type="password" id="confirm_password" name="confirm_password" placeholder="Repeat your password" required>
        <span class="show-btn" onclick="togglePw('confirm_password',this)">SHOW</span>
      </div>
      <button class="btn-primary" type="submit" id="submitBtn"><i class="fas fa-key"></i> Reset Password</button>
    </form>

  <?php else: ?>
    <div class="icon-wrap err"><i class="fas fa-triangle-exclamation"></i></div>
    <h1>Link Expired or Invalid</h1>
    <p class="sub">This password reset link is no longer valid. Links expire after 1 hour. Please request a new one.</p>
    <a href="forgot_password.php" class="btn-primary" style="display:block;text-align:center;text-decoration:none;padding:13px;border-radius:10px;color:#fff;">
      <i class="fas fa-paper-plane"></i> Request New Reset Link
    </a>
  <?php endif; ?>
  <a href="login.php" class="btn-ghost-link"><i class="fas fa-arrow-left"></i> Back to Sign In</a>
</div>

<script>
(function(){
  const c=document.getElementById('particles');
  for(let i=0;i<30;i++){const p=document.createElement('div');p.className='particle';p.style.cssText=`left:${Math.random()*100}%;animation-duration:${7+Math.random()*10}s;animation-delay:${-Math.random()*17}s;width:${1+Math.random()*2}px;height:${1+Math.random()*2}px;background:rgba(255,255,255,${0.2+Math.random()*0.4});`;c.appendChild(p);}
})();
function togglePw(id,btn){const el=document.getElementById(id);const s=el.type==='text';el.type=s?'password':'text';btn.textContent=s?'SHOW':'HIDE';}
function checkStrength(val){
  const bar=document.getElementById('pwBar');
  let score=0;
  if(val.length>=8)score++;if(/[A-Z]/.test(val))score++;if(/[0-9]/.test(val))score++;if(/[^A-Za-z0-9]/.test(val))score++;
  bar.style.width=[0,30,55,80,100][score]+'%';
  bar.style.background=['','#e53e3e','#dd6b20','#3a8dff','#38a169'][score]||'#eee';
}
const form=document.getElementById('resetForm');
if(form){
  form.addEventListener('submit',async function(e){
    e.preventDefault();
    const btn=document.getElementById('submitBtn');
    const msgEl=document.getElementById('msg');
    const fd=new FormData(this);
    const orig=btn.innerHTML;
    btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Resetting...';
    try{
      const res=await fetch('reset_password.php?token='+encodeURIComponent(fd.get('token')),{method:'POST',body:fd});
      const data=await res.json();
      if(data.status==='success'){
        msgEl.className='msg success';msgEl.textContent=data.message;msgEl.style.display='block';
        btn.innerHTML='<i class="fas fa-check"></i> Done!';
        setTimeout(()=>{window.location.href=data.redirect;},1500);
      }else{
        msgEl.className='msg error';msgEl.textContent=data.message;msgEl.style.display='block';
        btn.disabled=false;btn.innerHTML=orig;
      }
    }catch{
      msgEl.className='msg error';msgEl.textContent='Something went wrong.';msgEl.style.display='block';
      btn.disabled=false;btn.innerHTML=orig;
    }
  });
}
</script>
</body>
</html>

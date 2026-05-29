<?php
session_start();
if (isset($_SESSION['user_id'])) { require_once __DIR__ . '/config/auth.php'; redirect_to_portal(); }
require __DIR__ . '/config/db.php';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json');
    $email = trim($_POST['email'] ?? '');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['status'=>'error','message'=>'Please enter a valid email address.']); exit;
    }
    // Look up user
    $stmt = $conn->prepare("SELECT id, first_name FROM users WHERE email=? LIMIT 1");
    $stmt->bind_param("s", $email);
    $stmt->execute();
    $res  = $stmt->get_result();
    $user = $res->fetch_assoc();
    $stmt->close();

    if ($user) {
        $token = bin2hex(random_bytes(32));
        // Use DATE_ADD(NOW(), INTERVAL 1 HOUR) so both the write and the later
        // WHERE reset_token_expires > NOW() check use MySQL's clock — no PHP/MySQL
        // timezone mismatch possible.
        $upd = $conn->prepare(
            "UPDATE users SET reset_token=?, reset_token_expires=DATE_ADD(NOW(), INTERVAL 1 HOUR) WHERE id=?"
        );
        if (!$upd) {
            error_log('SenTri reset prepare error: ' . $conn->error . ' — ensure migration 002 has been run (reset_token column may be missing).');
        } else {
            $upd->bind_param("si", $token, $user['id']);
            $upd->execute();
            $upd->close();
        }
        try {
            require_once __DIR__ . '/core/SenTriMailer.php';
            sendPasswordResetEmail($email, $user['first_name'], $token);
        } catch (Throwable $e) {
            error_log('SenTri reset email error: ' . $e->getMessage());
        }
    }
    // Always show the same message (don't reveal if email exists)
    echo json_encode([
        'status'  => 'success',
        'message' => 'If that email is registered, you\'ll receive a password reset link shortly. Check your inbox and spam folder.',
    ]);
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password – SenTri</title>
<link href="https://fonts.googleapis.com/css2?family=Poppins:wght@400;500;600;700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--blue:#1c57b2;--blue-light:#3a8dff;--text:#1a1a2e;--muted:#666;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Poppins',sans-serif;}
body{background:#0a0f1e;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px;}
.bg-canvas{position:fixed;inset:0;z-index:0;overflow:hidden;}
.bg-canvas::before{content:'';position:absolute;width:600px;height:600px;background:radial-gradient(circle,rgba(58,141,255,0.12) 0%,transparent 70%);top:-200px;left:-100px;animation:drift1 9s ease-in-out infinite alternate;}
.bg-canvas::after{content:'';position:absolute;width:400px;height:400px;background:radial-gradient(circle,rgba(124,58,237,0.1) 0%,transparent 70%);bottom:-100px;right:-100px;animation:drift2 11s ease-in-out infinite alternate;}
.particle{position:absolute;border-radius:50%;animation:float linear infinite;}
@keyframes drift1{from{transform:translate(0,0);}to{transform:translate(-30px,30px);}}
@keyframes drift2{from{transform:translate(0,0);}to{transform:translate(30px,-30px);}}
@keyframes float{0%{transform:translateY(100vh);opacity:0;}10%{opacity:1;}90%{opacity:1;}100%{transform:translateY(-100px);opacity:0;}}
@keyframes fadeInUp{from{opacity:0;transform:translateY(24px);}to{opacity:1;transform:translateY(0);}}
.card{background:#fff;border-radius:20px;padding:48px 44px;max-width:460px;width:100%;position:relative;z-index:1;animation:fadeInUp 0.55s cubic-bezier(0.22,1,0.36,1) both;box-shadow:0 20px 60px rgba(0,0,0,0.35);}
.brand{display:flex;align-items:center;gap:10px;margin-bottom:32px;text-decoration:none;}
.brand-icon{width:38px;height:38px;background:linear-gradient(135deg,var(--blue-light),var(--blue));border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:#fff;}
.brand-name{font-size:1.3rem;font-weight:800;color:var(--text);}
.icon-wrap{width:64px;height:64px;background:#e3f2fd;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;color:var(--blue);margin-bottom:20px;}
h1{font-size:1.45rem;font-weight:800;color:var(--text);margin-bottom:8px;letter-spacing:-0.4px;}
p.sub{font-size:0.9rem;color:var(--muted);line-height:1.7;margin-bottom:24px;}
.form-group{margin-bottom:16px;}
.form-group label{display:block;font-size:0.82rem;font-weight:600;color:#444;margin-bottom:6px;}
.form-group input{width:100%;padding:12px 15px;border:1.5px solid #e0e0e0;border-radius:10px;font-size:0.93rem;outline:none;font-family:'Poppins',sans-serif;background:#fafafa;transition:all 0.2s;}
.form-group input:focus{border-color:var(--blue-light);background:#fff;box-shadow:0 0 0 4px rgba(58,141,255,0.1);}
.btn-primary{width:100%;background:linear-gradient(135deg,var(--blue-light),var(--blue));color:#fff;border:none;padding:13px;border-radius:10px;font-size:0.96rem;font-weight:700;cursor:pointer;transition:all 0.25s;font-family:'Poppins',sans-serif;}
.btn-primary:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(28,87,178,0.4);}
.btn-primary:disabled{opacity:0.6;cursor:not-allowed;transform:none;}
.back-link{display:flex;align-items:center;gap:6px;margin-top:18px;font-size:0.85rem;color:var(--blue);font-weight:600;text-decoration:none;justify-content:center;}
.back-link:hover{text-decoration:underline;}
.msg{padding:12px 16px;border-radius:9px;font-size:0.85rem;margin-bottom:16px;display:none;text-align:center;font-weight:500;}
.msg.success{background:#e8f5e9;color:#2e7d32;}
.msg.error{background:#ffebee;color:#c62828;}
</style>
</head>
<body>
<div class="bg-canvas"><div id="particles" style="position:absolute;inset:0;overflow:hidden;"></div></div>
<div class="card">
  <a href="index.php" class="brand">
    <div class="brand-icon"><i class="fas fa-shield-halved"></i></div>
    <span class="brand-name">SenTri</span>
  </a>
  <div class="icon-wrap"><i class="fas fa-key"></i></div>
  <h1>Forgot Your Password?</h1>
  <p class="sub">Enter your account email and we'll send you a secure link to reset your password. The link expires in 1 hour.</p>

  <div id="msg" class="msg"></div>
  <form id="forgotForm" novalidate>
    <div class="form-group">
      <label>Email Address</label>
      <input type="email" id="email" name="email" placeholder="you@example.com" required autocomplete="email">
    </div>
    <button class="btn-primary" type="submit" id="submitBtn"><i class="fas fa-paper-plane"></i> Send Reset Link</button>
  </form>
  <a href="login.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Sign In</a>
</div>

<script>
(function(){
  const c=document.getElementById('particles');
  for(let i=0;i<30;i++){const p=document.createElement('div');p.className='particle';p.style.cssText=`left:${Math.random()*100}%;animation-duration:${7+Math.random()*10}s;animation-delay:${-Math.random()*17}s;width:${1+Math.random()*2}px;height:${1+Math.random()*2}px;background:rgba(255,255,255,${0.2+Math.random()*0.4});`;c.appendChild(p);}
})();

document.getElementById('forgotForm').addEventListener('submit', async function(e){
  e.preventDefault();
  const btn=document.getElementById('submitBtn');
  const msgEl=document.getElementById('msg');
  const fd=new FormData(this);
  if(!fd.get('email')?.trim()){
    msgEl.className='msg error';msgEl.textContent='Please enter your email address.';msgEl.style.display='block';return;
  }
  const orig=btn.innerHTML;
  btn.disabled=true;btn.innerHTML='<i class="fas fa-spinner fa-spin"></i> Sending...';
  try{
    const res=await fetch('forgot_password.php',{method:'POST',body:fd});
    const data=await res.json();
    msgEl.className='msg '+(data.status==='success'?'success':'error');
    msgEl.textContent=data.message;
    msgEl.style.display='block';
    if(data.status==='success'){
      btn.innerHTML='<i class="fas fa-check"></i> Link Sent';
      // Disable form so user doesn't spam
      document.getElementById('email').disabled=true;
    }else{
      btn.disabled=false;btn.innerHTML=orig;
    }
  }catch{
    msgEl.className='msg error';msgEl.textContent='Something went wrong. Please try again.';msgEl.style.display='block';
    btn.disabled=false;btn.innerHTML=orig;
  }
});
</script>
</body>
</html>

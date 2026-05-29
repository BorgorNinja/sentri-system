<?php
session_start();
if (isset($_SESSION['user_id'])) {
    require_once __DIR__ . '/config/auth.php';
    redirect_to_portal();
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>SenTri — Community Safety Incident Reporting System</title>
<link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&family=Poppins:wght@700;800&display=swap" rel="stylesheet">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<style>
:root{--navy:#0a3d62;--navy-dark:#062444;--navy-light:#1a5276;--gold:#f39c12;--gold-light:#f5b942;--green:#166534;--text:#111827;--muted:#6b7280;--border:#e5e7eb;}
*{box-sizing:border-box;margin:0;padding:0;font-family:'Inter',sans-serif;}
html{scroll-behavior:smooth;}
body{background:#fff;min-height:100vh;display:flex;flex-direction:column;overflow-x:hidden;}

/* NAV */
nav{background:#fff;border-bottom:3px solid var(--gold);padding:0 36px;height:64px;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:100;box-shadow:0 2px 12px rgba(0,0,0,0.08);}
.nav-brand{display:flex;align-items:center;gap:12px;text-decoration:none;}
.nav-seal{width:42px;height:42px;background:linear-gradient(135deg,var(--navy),var(--navy-light));border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.1rem;color:var(--gold);border:2px solid var(--gold);}
.nav-brand-text h1{font-family:'Poppins',sans-serif;font-size:1.1rem;font-weight:800;color:var(--navy);}
.nav-brand-text p{font-size:0.65rem;color:var(--muted);font-weight:500;}
.nav-links{display:flex;gap:10px;align-items:center;}
.btn-outline{color:var(--navy);border:2px solid var(--navy);padding:8px 20px;border-radius:9px;text-decoration:none;font-weight:700;font-size:0.86rem;transition:all 0.2s;}
.btn-outline:hover{background:var(--navy);color:#fff;}
.btn-solid{background:linear-gradient(135deg,var(--navy-light),var(--navy-dark));color:#fff;padding:9px 22px;border-radius:9px;text-decoration:none;font-weight:700;font-size:0.86rem;box-shadow:0 4px 14px rgba(10,61,98,0.35);transition:all 0.2s;}
.btn-solid:hover{transform:translateY(-1px);box-shadow:0 6px 20px rgba(10,61,98,0.45);}
.ham-nav{display:none;background:none;border:none;font-size:1.3rem;color:var(--navy);cursor:pointer;}

/* HERO */
.hero{background:linear-gradient(135deg,var(--navy-dark) 0%,var(--navy) 55%,var(--navy-light) 100%);padding:80px 40px;display:flex;align-items:center;justify-content:center;min-height:82vh;position:relative;overflow:hidden;}
.hero::before{content:'';position:absolute;inset:0;background:url("data:image/svg+xml,%3Csvg width='60' height='60' viewBox='0 0 60 60' xmlns='http://www.w3.org/2000/svg'%3E%3Cg fill='%23ffffff' fill-opacity='0.03'%3E%3Cpath d='M36 34v-4h-2v4h-4v2h4v4h2v-4h4v-2h-4zM6 4V0H4v4H0v2h4v4h2V6h4V4H6z'/%3E%3C/g%3E%3C/svg%3E");}
.hero-inner{position:relative;z-index:1;text-align:center;max-width:780px;}
.hero-eyebrow{display:inline-flex;align-items:center;gap:8px;background:rgba(243,156,18,0.15);border:1px solid rgba(243,156,18,0.35);border-radius:50px;padding:7px 18px;font-size:0.78rem;color:var(--gold);margin-bottom:26px;font-weight:600;letter-spacing:1px;text-transform:uppercase;}
.hero-eyebrow .dot{width:7px;height:7px;background:var(--gold);border-radius:50%;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1;}50%{opacity:0.4;}}
.hero h1{font-family:'Poppins',sans-serif;font-size:3.2rem;font-weight:800;color:#fff;line-height:1.15;margin-bottom:20px;letter-spacing:-1px;}
.hero h1 span{color:var(--gold);}
.hero p{font-size:1rem;color:rgba(255,255,255,0.72);line-height:1.8;max-width:560px;margin:0 auto 36px;}
.hero-ctas{display:flex;gap:14px;justify-content:center;flex-wrap:wrap;}
.cta-primary{background:var(--gold);color:var(--navy-dark);padding:14px 32px;border-radius:11px;text-decoration:none;font-weight:800;font-size:0.96rem;transition:all 0.22s;box-shadow:0 6px 24px rgba(243,156,18,0.4);display:inline-flex;align-items:center;gap:9px;}
.cta-primary:hover{background:var(--gold-light);transform:translateY(-2px);box-shadow:0 10px 32px rgba(243,156,18,0.5);}
.cta-secondary{background:rgba(255,255,255,0.1);color:#fff;padding:14px 32px;border-radius:11px;text-decoration:none;font-weight:700;font-size:0.96rem;transition:all 0.22s;border:1.5px solid rgba(255,255,255,0.25);display:inline-flex;align-items:center;gap:9px;}
.cta-secondary:hover{background:rgba(255,255,255,0.18);transform:translateY(-2px);}
.hero-stats{display:flex;justify-content:center;gap:40px;margin-top:52px;padding-top:36px;border-top:1px solid rgba(255,255,255,0.1);}
.stat-num{font-family:'Poppins',sans-serif;font-size:1.9rem;font-weight:800;color:var(--gold);}
.stat-lbl{font-size:0.7rem;color:rgba(255,255,255,0.5);font-weight:600;text-transform:uppercase;letter-spacing:1px;margin-top:2px;}

/* PORTALS SECTION */
.portals-section{padding:80px 40px;background:#f8fafc;}
.section-header{text-align:center;margin-bottom:48px;}
.section-eyebrow{font-size:0.72rem;font-weight:700;color:var(--gold);letter-spacing:2px;text-transform:uppercase;margin-bottom:10px;}
.section-header h2{font-family:'Poppins',sans-serif;font-size:2rem;font-weight:800;color:var(--navy);margin-bottom:10px;}
.section-header p{font-size:0.9rem;color:var(--muted);max-width:500px;margin:0 auto;}
.portals-grid{display:grid;grid-template-columns:repeat(5,1fr);gap:16px;max-width:1080px;margin:0 auto;}
.portal-card{background:#fff;border-radius:16px;padding:28px 18px;text-align:center;border:2px solid var(--border);transition:all 0.22s;box-shadow:0 2px 10px rgba(0,0,0,0.05);}
.portal-card:hover{transform:translateY(-5px);box-shadow:0 12px 32px rgba(0,0,0,0.12);}
.p-community:hover{border-color:#2563eb;}
.p-barangay:hover{border-color:var(--green);}
.p-lgu:hover{border-color:var(--navy);}
.p-responder:hover{border-color:#b91c1c;}
.p-admin:hover{border-color:#7c3aed;}
.portal-icon{width:56px;height:56px;border-radius:14px;display:flex;align-items:center;justify-content:center;margin:0 auto 14px;font-size:1.4rem;}
.p-community .portal-icon{background:#eff6ff;color:#2563eb;}
.p-barangay .portal-icon{background:#f0fdf4;color:var(--green);}
.p-lgu .portal-icon{background:#f0f7ff;color:var(--navy);}
.p-responder .portal-icon{background:#fef2f2;color:#b91c1c;}
.p-admin .portal-icon{background:#f5f3ff;color:#7c3aed;}
.portal-name{font-size:0.9rem;font-weight:800;color:var(--text);margin-bottom:4px;}
.portal-desc{font-size:0.73rem;color:var(--muted);line-height:1.5;}

/* FEATURES */
.features{padding:80px 40px;background:#fff;}
.features-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:22px;max-width:1080px;margin:0 auto;}
.feat-card{background:#f8fafc;border-radius:16px;padding:28px;border:1px solid var(--border);transition:all 0.22s;}
.feat-card:hover{transform:translateY(-4px);box-shadow:0 12px 32px rgba(0,0,0,0.08);border-color:rgba(10,61,98,0.2);}
.feat-icon{width:50px;height:50px;border-radius:13px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin-bottom:16px;}
.feat-card h3{font-size:0.98rem;font-weight:700;color:var(--text);margin-bottom:8px;}
.feat-card p{font-size:0.84rem;color:var(--muted);line-height:1.7;}

/* CTA BAND */
.cta-band{background:linear-gradient(135deg,var(--navy-dark),var(--navy),var(--navy-light));padding:72px 40px;text-align:center;}
.cta-band h2{font-family:'Poppins',sans-serif;font-size:2rem;font-weight:800;color:#fff;margin-bottom:12px;}
.cta-band p{font-size:0.92rem;color:rgba(255,255,255,0.7);max-width:480px;margin:0 auto 28px;}
.cta-band a{display:inline-flex;align-items:center;gap:9px;background:var(--gold);color:var(--navy-dark);padding:14px 34px;border-radius:11px;text-decoration:none;font-weight:800;font-size:0.95rem;transition:all 0.2s;box-shadow:0 6px 24px rgba(243,156,18,0.35);}
.cta-band a:hover{background:var(--gold-light);transform:translateY(-2px);}

/* FOOTER */
footer{background:var(--navy-dark);color:rgba(255,255,255,0.45);padding:28px 40px;text-align:center;font-size:0.78rem;margin-top:auto;}
.footer-brand{display:flex;align-items:center;justify-content:center;gap:10px;margin-bottom:10px;}
.footer-seal{width:32px;height:32px;background:rgba(243,156,18,0.15);border:1.5px solid rgba(243,156,18,0.3);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:0.9rem;color:var(--gold);}
.footer-brand span{color:#fff;font-weight:700;font-size:0.95rem;}

/* MOBILE NAV */
.mobile-nav{display:none;flex-direction:column;gap:10px;position:absolute;top:64px;left:0;right:0;background:#fff;padding:18px;box-shadow:0 8px 24px rgba(0,0,0,0.1);z-index:200;border-top:2px solid var(--gold);}
.mobile-nav.open{display:flex;}
.mobile-nav a{text-decoration:none;padding:11px 16px;border-radius:9px;font-weight:600;color:var(--navy);font-size:0.92rem;border:1.5px solid var(--border);text-align:center;}
.mobile-nav a.solid{background:var(--navy);color:#fff;border-color:var(--navy);}

@media(max-width:960px){
  nav{padding:0 20px;}.ham-nav{display:block;}.nav-links{display:none;}
  .hero{padding:60px 24px;min-height:auto;}.hero h1{font-size:2.2rem;}
  .portals-grid{grid-template-columns:repeat(3,1fr);}
  .features-grid{grid-template-columns:1fr 1fr;}
  .portals-section,.features,.cta-band{padding:56px 24px;}
}
@media(max-width:600px){
  .hero h1{font-size:1.8rem;}.hero-stats{gap:22px;}
  .portals-grid{grid-template-columns:repeat(2,1fr);}
  .features-grid{grid-template-columns:1fr;}
  footer{padding:20px;}
}
</style>
</head>
<body>

<nav id="mainNav" style="position:relative;">
  <a href="#" class="nav-brand">
    <div class="nav-seal"><i class="fas fa-shield-halved"></i></div>
    <div class="nav-brand-text"><h1>SenTri</h1><p>Community Safety Incident Reporting System</p></div>
  </a>
  <div class="nav-links">
    <a href="login.php" class="btn-outline">Sign In</a>
    <a href="signup.php" class="btn-solid">Register</a>
  </div>
  <button class="ham-nav" onclick="toggleNav()"><i class="fas fa-bars" id="hamIcon"></i></button>
  <div class="mobile-nav" id="mobileNav">
    <a href="login.php">Sign In</a>
    <a href="signup.php" class="solid">Register</a>
  </div>
</nav>

<section class="hero">
  <div class="hero-inner">
    <div class="hero-eyebrow"><span class="dot"></span> Official Government Safety Platform</div>
    <h1>Protecting Communities<br>Across the <span>Philippines</span></h1>
    <p>SenTri connects citizens, barangay officials, LGU offices, and first responders in a unified incident reporting and response system.</p>
    <div class="hero-ctas">
      <a href="signup.php" class="cta-primary"><i class="fas fa-user-plus"></i> Register Now</a>
      <a href="login.php" class="cta-secondary"><i class="fas fa-right-to-bracket"></i> Sign In</a>
    </div>
    <div class="hero-stats">
      <div><div class="stat-num">5</div><div class="stat-lbl">Portal Types</div></div>
      <div><div class="stat-num">24/7</div><div class="stat-lbl">Monitoring</div></div>
      <div><div class="stat-num">PH</div><div class="stat-lbl">Nationwide</div></div>
    </div>
  </div>
</section>

<section class="portals-section">
  <div class="section-header">
    <p class="section-eyebrow">Who Is It For</p>
    <h2>Five Dedicated Portals</h2>
    <p>Each role has its own tailored interface designed for their specific responsibilities.</p>
  </div>
  <div class="portals-grid">
    <div class="portal-card p-community">
      <div class="portal-icon"><i class="fas fa-users"></i></div>
      <div class="portal-name">Community</div>
      <div class="portal-desc">Citizens report incidents and monitor safety in their area</div>
    </div>
    <div class="portal-card p-barangay">
      <div class="portal-icon"><i class="fas fa-house-flag"></i></div>
      <div class="portal-name">Barangay</div>
      <div class="portal-desc">Barangay officials manage and escalate local incidents</div>
    </div>
    <div class="portal-card p-lgu">
      <div class="portal-icon"><i class="fas fa-landmark"></i></div>
      <div class="portal-name">LGU</div>
      <div class="portal-desc">City and municipal government incident oversight and analytics</div>
    </div>
    <div class="portal-card p-responder">
      <div class="portal-icon"><i class="fas fa-truck-medical"></i></div>
      <div class="portal-name">First Responder</div>
      <div class="portal-desc">BFP, PNP, EMS dispatch queue and assignment tracking</div>
    </div>
    <div class="portal-card p-admin">
      <div class="portal-icon"><i class="fas fa-gear"></i></div>
      <div class="portal-name">Admin</div>
      <div class="portal-desc">System administration and official account approvals</div>
    </div>
  </div>
</section>

<section class="features">
  <div class="section-header">
    <p class="section-eyebrow">Key Features</p>
    <h2>Built for Local Government</h2>
    <p>Purpose-built tools for the Philippine barangay and LGU system.</p>
  </div>
  <div class="features-grid">
    <div class="feat-card">
      <div class="feat-icon" style="background:#fef2f2;color:#dc2626;"><i class="fas fa-triangle-exclamation"></i></div>
      <h3>Real-Time Incident Reporting</h3>
      <p>Citizens submit geotagged incident reports with photos. Officials see them instantly on their portal.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon" style="background:#f0f7ff;color:#0a3d62;"><i class="fas fa-map-location-dot"></i></div>
      <h3>Barangay-Level Routing</h3>
      <p>Reports are automatically routed to the correct barangay and LGU based on location data.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon" style="background:#f0fdf4;color:#166534;"><i class="fas fa-truck-medical"></i></div>
      <h3>Responder Dispatch Queue</h3>
      <p>First responders see a prioritized dispatch queue and can self-assign to active incidents.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon" style="background:#fffbeb;color:#d97706;"><i class="fas fa-address-book"></i></div>
      <h3>Emergency Contact Directory</h3>
      <p>Centralized directory of LGU offices, hospitals, BFP stations, and PNP units with auto-notify.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon" style="background:#f5f3ff;color:#7c3aed;"><i class="fas fa-shield-halved"></i></div>
      <h3>Role-Based Access Control</h3>
      <p>Every portal is locked to its designated role. Official accounts require administrator approval.</p>
    </div>
    <div class="feat-card">
      <div class="feat-icon" style="background:#f0fdf4;color:#0a3d62;"><i class="fas fa-chart-bar"></i></div>
      <h3>LGU Analytics Dashboard</h3>
      <p>Barangay-level incident breakdown, dangerous zone mapping, and historical trend data.</p>
    </div>
  </div>
</section>

<section class="cta-band">
  <h2>Ready to Get Started?</h2>
  <p>Join your community or sign in to your official government portal today.</p>
  <a href="signup.php"><i class="fas fa-shield-halved"></i> Create Your Account</a>
</section>

<footer>
  <div class="footer-brand">
    <div class="footer-seal"><i class="fas fa-shield-halved"></i></div>
    <span>SenTri</span>
  </div>
  <p>Community Safety Incident Reporting System &copy; <?= date('Y') ?> &mdash; For official and community use only.</p>
</footer>

<script>
function toggleNav(){
  const nav=document.getElementById('mobileNav');
  const icon=document.getElementById('hamIcon');
  const open=nav.classList.toggle('open');
  icon.className=open?'fas fa-xmark':'fas fa-bars';
}
document.addEventListener('click',function(e){
  if(!e.target.closest('#mainNav')) document.getElementById('mobileNav').classList.remove('open');
});
</script>
</body>
</html>

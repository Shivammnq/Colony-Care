<?php
require_once __DIR__ . '/config.php';
session_start();
if (isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }

// ── DB ─────────────────────────────────────────────────────
try {
    $pdo = get_db_connection();
} catch(Exception $e) { die("DB Error: ".$e->getMessage()); }

// Fetch all societies for dropdown
$societies = $pdo->query("SELECT id, society_name, city, state FROM societies ORDER BY society_name")->fetchAll();

$errors  = $_SESSION['reg_errors'] ?? [];
$old     = $_SESSION['reg_old']    ?? [];
unset($_SESSION['reg_errors'], $_SESSION['reg_old']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Register as a Resident - ColonyCare</title>
<meta name="description" content="Create your free ColonyCare resident account to book visitor entry, pay bills, raise complaints, and stay updated on society events.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://www.example.com/register.php">
<meta property="og:type" content="website">
<meta property="og:title" content="Register as a Resident - ColonyCare">
<meta property="og:description" content="Create your free ColonyCare resident account to book visitor entry, pay bills, raise complaints, and stay updated on society events.">
<meta property="og:url" content="https://www.example.com/register.php">
<meta property="og:site_name" content="ColonyCare">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;background:#f0f5f2;}

.rs-left{flex:1;background:linear-gradient(135deg,#0f8f6f 0%,#2d7a60 60%,#3a9467 100%);display:flex;flex-direction:column;justify-content:center;padding:60px 56px;color:#fff;position:relative;overflow:hidden;}
.rs-left::before{content:'';position:absolute;width:400px;height:400px;border-radius:50%;background:rgba(255,255,255,.05);top:-100px;right:-100px;}
.rs-left::after{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.04);bottom:-80px;left:-80px;}
.rs-brand{display:flex;align-items:center;gap:12px;margin-bottom:48px;position:relative;z-index:1;}
.brand-icon{width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.4rem;}
.rs-brand h2{font-size:1.5rem;font-weight:700;margin:0;}
.rs-left h1{font-family:'DM Serif Display',serif;font-size:2.1rem;font-weight:400;line-height:1.3;margin-bottom:14px;position:relative;z-index:1;}
.rs-left>p{font-size:.93rem;opacity:.85;line-height:1.7;margin-bottom:36px;position:relative;z-index:1;}
.rs-features{list-style:none;padding:0;margin:0;position:relative;z-index:1;}
.rs-features li{display:flex;align-items:center;gap:12px;font-size:.87rem;opacity:.9;margin-bottom:13px;}
.rs-features li i{width:30px;height:30px;border-radius:8px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;}
.back-home{display:inline-flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);text-decoration:none;font-size:.83rem;margin-bottom:30px;position:relative;z-index:1;transition:color .2s;}
.back-home:hover{color:#fff;}

.rs-right{width:580px;flex-shrink:0;display:flex;align-items:flex-start;justify-content:center;padding:28px 24px;overflow-y:auto;max-height:100vh;}
.rs-card{width:100%;background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(30,80,50,.12);padding:30px 30px 26px;margin:auto 0;}
.rs-card-logo{width:52px;height:52px;border-radius:14px;background:#d4eddf;display:flex;align-items:center;justify-content:center;margin:0 auto 12px;font-size:1.35rem;color:#0f8f6f;}
.rs-card h2{text-align:center;font-size:1.25rem;font-weight:700;color:#1a2e22;margin-bottom:3px;}
.rs-card .sub{text-align:center;font-size:.8rem;color:#8fa898;margin-bottom:18px;}

.progress-bar{display:flex;align-items:center;margin-bottom:22px;}
.prog-step{display:flex;flex-direction:column;align-items:center;flex:1;}
.prog-circle{width:30px;height:30px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;border:2px solid #e5ece8;background:#fff;color:#8fa898;transition:all .3s;position:relative;z-index:1;}
.prog-circle.active{background:#0f8f6f;border-color:#0f8f6f;color:#fff;}
.prog-circle.done{background:#d4eddf;border-color:#0f8f6f;color:#0f8f6f;}
.prog-label{font-size:.66rem;color:#8fa898;margin-top:4px;font-weight:500;text-align:center;}
.prog-label.active{color:#0f8f6f;font-weight:700;}
.prog-line{flex:1;height:2px;background:#e5ece8;margin-top:-16px;transition:background .3s;}
.prog-line.done{background:#0f8f6f;}

.section-label{font-size:.74rem;font-weight:700;color:#0f8f6f;text-transform:uppercase;letter-spacing:.06em;margin:14px 0 10px;display:flex;align-items:center;gap:8px;}
.section-label::after{content:'';flex:1;height:1px;background:#e5ece8;}

.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.form-row.col-1{grid-template-columns:1fr;}
.field{display:flex;flex-direction:column;gap:4px;margin-bottom:8px;}
.field label{font-size:.77rem;font-weight:600;color:#1a2e22;}
.field label .req{color:#ef4444;margin-left:2px;}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-icon{position:absolute;left:11px;color:#8fa898;font-size:.8rem;display:flex;pointer-events:none;}
.input-wrap input,.input-wrap select{width:100%;padding:9px 12px 9px 33px;border:1.5px solid #dce8e1;border-radius:9px;font-family:inherit;font-size:.85rem;color:#1a2e22;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;}
.input-wrap input:focus,.input-wrap select:focus{border-color:#2d7a52;box-shadow:0 0 0 3px rgba(45,122,82,.1);}
.input-wrap input.err{border-color:#ef4444;background:#fef2f2;}
.toggle-pass{position:absolute;right:11px;background:none;border:none;cursor:pointer;color:#8fa898;font-size:.88rem;padding:4px;transition:color .2s;}
.toggle-pass:hover{color:#2d7a52;}

.role-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:8px;margin-bottom:14px;}
.role-pill-btn{padding:10px 8px;border:1.5px solid #dce8e1;border-radius:9px;background:#fff;cursor:pointer;text-align:center;font-size:.78rem;font-weight:600;color:#5a7060;transition:all .2s;}
.role-pill-btn:hover{border-color:#0f8f6f;}
.role-pill-btn.selected{background:#e8f5ee;border-color:#0f8f6f;color:#0f5c46;}
.role-pill-btn i{display:block;font-size:1.1rem;margin-bottom:4px;}

.alert{padding:10px 14px;border-radius:9px;font-size:.83rem;margin-bottom:14px;display:flex;align-items:flex-start;gap:9px;border:1px solid;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.alert ul{padding-left:16px;margin:4px 0 0;}

.btn-submit{width:100%;padding:12px;background:linear-gradient(135deg,#0f8f6f,#22c1a1);color:#fff;font-family:inherit;font-size:.93rem;font-weight:600;border:none;border-radius:10px;cursor:pointer;margin-top:10px;letter-spacing:.2px;transition:box-shadow .2s,transform .15s;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-submit:hover{box-shadow:0 4px 20px rgba(45,122,82,.3);transform:translateY(-1px);}
.btn-submit:disabled{background:#a0c4b8;cursor:not-allowed;transform:none;box-shadow:none;}

.form-section{display:none;}
.form-section.active{display:block;animation:fadeIn .3s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

.otp-box{background:#f8fffe;border:1px solid #d4eddf;border-radius:12px;padding:14px 16px;margin-bottom:10px;}
.otp-box-title{font-size:.8rem;font-weight:700;color:#1a2e22;margin-bottom:4px;display:flex;align-items:center;gap:6px;}
.otp-box-sub{font-size:.74rem;color:#5a7060;margin-bottom:10px;}
.otp-send-row{display:flex;align-items:center;gap:8px;margin-bottom:8px;}
.btn-send-otp{padding:8px 14px;background:#0f8f6f;color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;white-space:nowrap;transition:background .2s;display:flex;align-items:center;gap:5px;}
.btn-send-otp:hover{background:#0a7055;}
.btn-send-otp:disabled{background:#a0c4b8;cursor:not-allowed;}
.otp-input-row{display:flex;gap:8px;align-items:center;margin-bottom:5px;}
.otp-input-row input{flex:1;padding:9px 12px;border:1.5px solid #dce8e1;border-radius:9px;font-family:inherit;font-size:.95rem;letter-spacing:.2em;text-align:center;outline:none;transition:border-color .2s;}
.btn-verify-otp{padding:9px 14px;background:#1a2e22;color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;white-space:nowrap;display:flex;align-items:center;gap:5px;}
.btn-verify-otp:hover{background:#0f8f6f;}
.otp-status{font-size:.74rem;font-weight:600;display:flex;align-items:center;gap:5px;min-height:18px;margin-bottom:4px;}
.otp-status.success{color:#16a34a;}
.otp-status.error{color:#dc2626;}
.otp-status.info{color:#0369a1;}
.countdown{font-size:.7rem;color:#8fa898;margin-left:4px;}
.verified-badge{display:inline-flex;align-items:center;gap:5px;background:#f0fdf4;border:1px solid #bbf7d0;border-radius:99px;padding:3px 10px;font-size:.72rem;color:#16a34a;font-weight:600;margin-right:6px;}

.login-row{text-align:center;margin-top:14px;font-size:.82rem;color:#8fa898;}
.login-row a{color:#2d7a52;font-weight:700;text-decoration:none;}

@media(max-width:960px){
    .rs-left{display:none;}
    .rs-right{width:100%;max-width:580px;margin:0 auto;padding:20px 16px;}
}
@media(max-width:560px){
    .form-row{grid-template-columns:1fr;}
    .role-grid{grid-template-columns:repeat(2,1fr);}
    .rs-card{padding:20px 14px 16px;}
    .rs-right{padding:14px 10px;}
    .otp-send-row{flex-wrap:wrap;}
    .otp-send-row span{width:100%;}
    .otp-input-row{flex-wrap:wrap;}
    .otp-input-row input{width:100%;}
    .btn-verify-otp{width:100%;justify-content:center;}
    .progress-bar{gap:4px;}
    .prog-label{font-size:.6rem;}
}
@media(max-width:380px){
    .role-grid{grid-template-columns:1fr 1fr;}
    .rs-card h2{font-size:1.1rem;}
    .btn-submit{font-size:.85rem;}
}
</style>
</head>
<body>

<div class="rs-left">
    <a href="/index.php" class="back-home"><i class="fa fa-arrow-left"></i> Back to Home</a>
    <div class="rs-brand"><div class="brand-icon"><i class="fa fa-building"></i></div><h2>ColonyCare</h2></div>
    <h1>Join Your Society Today</h1>
    <p>Select your society, choose your role, and get connected with everything your community has to offer.</p>
    <ul class="rs-features">
        <li><i class="fa fa-users"></i> Connect with your community</li>
        <li><i class="fa fa-file-invoice-dollar"></i> Pay maintenance online</li>
        <li><i class="fa fa-comments"></i> Raise &amp; track complaints</li>
        <li><i class="fa fa-bell"></i> Get instant notices</li>
        <li><i class="fa fa-calendar"></i> Join community events</li>
    </ul>
</div>

<div class="rs-right">
<div class="rs-card">
    <div class="rs-card-logo"><i class="fa fa-user-plus"></i></div>
    <h2>Create Your Account</h2>
    <p class="sub">Select society → Fill details → Verify OTP</p>

    <!-- Progress -->
    <div class="progress-bar">
        <div class="prog-step"><div class="prog-circle active" id="pc1">1</div><div class="prog-label active" id="pl1">Details</div></div>
        <div class="prog-line" id="pline1"></div>
        <div class="prog-step"><div class="prog-circle" id="pc2">2</div><div class="prog-label" id="pl2">Verify OTP</div></div>
        <div class="prog-line" id="pline2"></div>
        <div class="prog-step"><div class="prog-circle" id="pc3">3</div><div class="prog-label" id="pl3">Done</div></div>
    </div>

    <?php if(!empty($errors)): ?>
    <div class="alert alert-error"><i class="fa fa-circle-exclamation" style="flex-shrink:0;margin-top:2px"></i>
    <div>Please fix:<ul><?php foreach($errors as $e): ?><li><?=htmlspecialchars($e)?></li><?php endforeach; ?></ul></div></div>
    <?php endif; ?>

    <form method="POST" action="/process_register_resident.php" id="regForm">

        <!-- STEP 1: DETAILS -->
        <div class="form-section active" id="step1">

            <div class="section-label"><i class="fa fa-building"></i> Select Your Society</div>
            <div class="form-row col-1">
                <div class="field">
                    <label>Society <span class="req">*</span></label>
                    <div class="input-wrap">
                        <span class="input-icon"><i class="fa fa-building"></i></span>
                        <select name="society_id" id="f_society" required>
                            <option value="">— Select your society —</option>
                            <?php foreach($societies as $s): ?>
                            <option value="<?= $s['id'] ?>" data-name="<?= htmlspecialchars($s['society_name']) ?>">
                                <?= htmlspecialchars($s['society_name']) ?> — <?= htmlspecialchars($s['city']) ?>, <?= htmlspecialchars($s['state']) ?>
                            </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <?php if(empty($societies)): ?>
                    <span style="font-size:.74rem;color:#dc2626">No societies registered yet. <a href="/register-society.php">Register one first</a>.</span>
                    <?php endif; ?>
                </div>
            </div>

            <div class="section-label"><i class="fa fa-user-tag"></i> Select Your Role</div>
            <div class="role-grid">
                <div class="role-pill-btn selected" data-role="resident" onclick="selectRole(this)"><i class="fa fa-house"></i> Resident</div>
                <div class="role-pill-btn" data-role="staff" onclick="selectRole(this)"><i class="fa fa-person-walking"></i> Gate Staff</div>
                <div class="role-pill-btn" data-role="accountant" onclick="selectRole(this)"><i class="fa fa-calculator"></i> Accountant</div>
                <div class="role-pill-btn" data-role="vendor" onclick="selectRole(this)"><i class="fa fa-store"></i> Vendor</div>
                <div class="role-pill-btn" data-role="society_member" onclick="selectRole(this)"><i class="fa fa-handshake"></i> Society Member</div>
            </div>
            <input type="hidden" name="role" id="f_role" value="resident">

            <div class="section-label"><i class="fa fa-id-card"></i> Your Details</div>
            <div class="form-row">
                <div class="field"><label>Full Name <span class="req">*</span></label>
                    <div class="input-wrap"><span class="input-icon"><i class="fa fa-user"></i></span>
                    <input type="text" name="full_name" id="f_name" placeholder="Rahul Sharma" required></div></div>
                <div class="field"><label>Phone <span class="req">*</span></label>
                    <div class="input-wrap"><span class="input-icon"><i class="fa fa-phone"></i></span>
                    <input type="tel" name="phone" id="f_phone" placeholder="9876543210" required maxlength="10"></div></div>
            </div>
            <div class="form-row">
                <div class="field"><label>Email <span class="req">*</span></label>
                    <div class="input-wrap"><span class="input-icon"><i class="fa fa-envelope"></i></span>
                    <input type="email" name="email" id="f_email" placeholder="you@example.com" required></div></div>
                <div class="field"><label>Password <span class="req">*</span></label>
                    <div class="input-wrap"><span class="input-icon"><i class="fa fa-lock"></i></span>
                    <input type="password" name="password" id="passField" placeholder="Min. 8 characters" required minlength="8">
                    <button type="button" class="toggle-pass" onclick="togglePass()"><i class="fa fa-eye" id="eyeIcon"></i></button></div></div>
            </div>
            <div class="form-row">
                <div class="field"><label>Block</label>
                    <div class="input-wrap"><span class="input-icon"><i class="fa fa-layer-group"></i></span>
                    <select name="block"><option value="">Select</option><?php foreach(['A','B','C','D','E','F'] as $bl): ?><option><?= $bl ?></option><?php endforeach; ?></select></div></div>
                <div class="field"><label>Unit / Flat No</label>
                    <div class="input-wrap"><span class="input-icon"><i class="fa fa-door-open"></i></span>
                    <input type="text" name="unit" placeholder="101"></div></div>
            </div>

            <button type="button" class="btn-submit" onclick="goToStep2()">
                <i class="fa fa-arrow-right"></i> Next: Verify OTP
            </button>
        </div>

        <!-- STEP 2: OTP -->
        <div class="form-section" id="step2">

            <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:10px;padding:12px 14px;margin-bottom:16px;font-size:.82rem;color:#0369a1;">
                <i class="fa fa-circle-info"></i>&nbsp; OTP will be sent to your <strong>email</strong> and <strong>phone</strong>. Both must be verified.
            </div>

            <div class="otp-box">
                <div class="otp-box-title"><i class="fa fa-envelope" style="color:#0f8f6f"></i> Email Verification</div>
                <div class="otp-box-sub">Sending to: <strong id="displayEmail"></strong></div>
                <div class="otp-send-row">
                    <button type="button" class="btn-send-otp" id="btnEmailOtp" onclick="sendOTP('email')"><i class="fa fa-paper-plane"></i> Send OTP</button>
                    <span class="countdown" id="emailCountdown"></span>
                </div>
                <div class="otp-input-row" id="emailOtpRow" style="display:none">
                    <input type="text" id="emailOtpInput" placeholder="Enter 6-digit OTP" maxlength="6">
                    <button type="button" class="btn-verify-otp" onclick="verifyOTP('email')"><i class="fa fa-check"></i> Verify</button>
                </div>
                <div class="otp-status" id="emailOtpStatus"></div>
            </div>

            <div class="otp-box">
                <div class="otp-box-title"><i class="fa fa-phone" style="color:#0f8f6f"></i> Phone Verification</div>
                <div class="otp-box-sub">Sending to: <strong id="displayPhone"></strong></div>
                <div class="otp-send-row">
                    <button type="button" class="btn-send-otp" id="btnPhoneOtp" onclick="sendOTP('phone')"><i class="fa fa-sms"></i> Send OTP</button>
                    <span class="countdown" id="phoneCountdown"></span>
                </div>
                <div class="otp-input-row" id="phoneOtpRow" style="display:none">
                    <input type="text" id="phoneOtpInput" placeholder="Enter 6-digit OTP" maxlength="6">
                    <button type="button" class="btn-verify-otp" onclick="verifyOTP('phone')"><i class="fa fa-check"></i> Verify</button>
                </div>
                <div class="otp-status" id="phoneOtpStatus"></div>
            </div>

            <div id="verifySummary" style="display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px;min-height:26px;"></div>

            <input type="hidden" name="email_verified" id="emailVerified" value="0">
            <input type="hidden" name="phone_verified" id="phoneVerified" value="0">

            <div style="display:flex;gap:10px;margin-top:8px;">
                <button type="button" onclick="goToStep1()" style="flex:0 0 auto;padding:11px 18px;background:#f0f5f2;color:#1a2e22;border:1.5px solid #dce8e1;border-radius:10px;font-family:inherit;font-size:.88rem;font-weight:600;cursor:pointer;">
                    <i class="fa fa-arrow-left"></i> Back
                </button>
                <button type="submit" class="btn-submit" id="btnRegister" style="flex:1;margin-top:0;" disabled>
                    <i class="fa fa-check-circle"></i> Register &amp; Continue
                </button>
            </div>
        </div>

    </form>
    <div class="login-row">Already have an account? <a href="/login.php">Login</a></div>
</div>
</div>

<script>
function togglePass(){const f=document.getElementById('passField'),e=document.getElementById('eyeIcon');f.type=f.type==='password'?'text':'password';e.className=f.type==='password'?'fa fa-eye':'fa fa-eye-slash';}
document.getElementById('f_phone').addEventListener('input',function(){this.value=this.value.replace(/\D/g,'').slice(0,10);});

function selectRole(el){
    document.querySelectorAll('.role-pill-btn').forEach(b=>b.classList.remove('selected'));
    el.classList.add('selected');
    document.getElementById('f_role').value = el.dataset.role;
}

const otpState={email:{sent:false,verified:false,timer:null},phone:{sent:false,verified:false,timer:null}};

function updateProgress(step){
    [1,2,3].forEach(i=>{document.getElementById('pc'+i).className='prog-circle';document.getElementById('pl'+i).className='prog-label';});
    [1,2].forEach(i=>document.getElementById('pline'+i).className='prog-line');
    for(let i=1;i<=3;i++){
        if(i<step){document.getElementById('pc'+i).classList.add('done');document.getElementById('pl'+i).classList.add('active');}
        else if(i===step){document.getElementById('pc'+i).classList.add('active');document.getElementById('pl'+i).classList.add('active');}
    }
    for(let i=1;i<step;i++) document.getElementById('pline'+i).classList.add('done');
}

function goToStep2(){
    const society = document.getElementById('f_society').value;
    if(!society){ alert('Please select your society.'); return; }
    const required=[...document.querySelectorAll('#step1 input[required]')];
    let valid=true;
    required.forEach(f=>{if(!f.value.trim()){f.classList.add('err');valid=false;}else f.classList.remove('err');});
    if(!valid){alert('Please fill in all required fields.');return;}
    const email=document.getElementById('f_email').value.trim();
    const phone=document.getElementById('f_phone').value.trim();
    if(!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)){alert('Please enter a valid email address.');return;}
    if(phone.length!==10){alert('Please enter a valid 10-digit phone number.');return;}
    document.getElementById('displayEmail').textContent=email;
    document.getElementById('displayPhone').textContent='+91 '+phone;
    document.getElementById('step1').classList.remove('active');
    document.getElementById('step2').classList.add('active');
    updateProgress(2);
}
function goToStep1(){
    document.getElementById('step2').classList.remove('active');
    document.getElementById('step1').classList.add('active');
    updateProgress(1);
}

async function sendOTP(type){
    const value=type==='email'?document.getElementById('f_email').value.trim():document.getElementById('f_phone').value.trim();
    const btn=document.getElementById(type==='email'?'btnEmailOtp':'btnPhoneOtp');
    const status=document.getElementById(type+'OtpStatus');
    btn.disabled=true; btn.innerHTML='<i class="fa fa-spinner fa-spin"></i> Sending...';
    status.className='otp-status info'; status.innerHTML='<i class="fa fa-spinner fa-spin"></i> Sending OTP...';
    try{
        const res=await fetch('/otp_resident_handler.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'send',type,value})});
        const data=await res.json();
        if(data.success){
            status.className='otp-status success';
            if(data.demo_otp){
                status.innerHTML='<i class="fa fa-circle-check"></i> <strong>DEMO OTP: <span style="font-size:1.1rem;letter-spacing:.15em;color:#0f8f6f;background:#f0fdf4;padding:2px 8px;border-radius:6px;border:1px solid #bbf7d0;">'+data.demo_otp+'</span></strong>';
                document.getElementById(type+'OtpInput').value = data.demo_otp;
            } else {
                status.innerHTML='<i class="fa fa-check-circle"></i> OTP sent! Check your '+(type==='email'?'inbox':'SMS')+'.';
            }
            document.getElementById(type+'OtpRow').style.display='flex';
            otpState[type].sent=true;
            startCountdown(type,btn);
        }else{
            status.className='otp-status error';
            status.innerHTML='<i class="fa fa-circle-xmark"></i> '+(data.message||'Failed to send OTP.');
            btn.disabled=false; btn.innerHTML=type==='email'?'<i class="fa fa-paper-plane"></i> Send OTP':'<i class="fa fa-sms"></i> Send OTP';
        }
    }catch(e){
        status.className='otp-status error'; status.textContent='Network error.';
        btn.disabled=false; btn.innerHTML=type==='email'?'<i class="fa fa-paper-plane"></i> Send OTP':'<i class="fa fa-sms"></i> Send OTP';
    }
}

function startCountdown(type,btn){
    let sec=60; const el=document.getElementById(type+'Countdown');
    if(otpState[type].timer) clearInterval(otpState[type].timer);
    otpState[type].timer=setInterval(()=>{
        sec--; el.textContent='Resend in '+sec+'s';
        if(sec<=0){clearInterval(otpState[type].timer);el.textContent='';btn.disabled=false;btn.innerHTML='<i class="fa fa-rotate-right"></i> Resend';}
    },1000);
}

async function verifyOTP(type){
    const otp=document.getElementById(type+'OtpInput').value.trim();
    const status=document.getElementById(type+'OtpStatus');
    const value=type==='email'?document.getElementById('f_email').value.trim():document.getElementById('f_phone').value.trim();
    if(otp.length!==6){status.className='otp-status error';status.textContent='Enter a 6-digit OTP.';return;}
    status.className='otp-status info'; status.innerHTML='<i class="fa fa-spinner fa-spin"></i> Verifying...';
    try{
        const res=await fetch('/otp_resident_handler.php',{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({action:'verify',type,value,otp})});
        const data=await res.json();
        if(data.success){
            status.className='otp-status success';
            status.innerHTML='<i class="fa fa-circle-check"></i> '+(type==='email'?'Email':'Phone')+' verified!';
            document.getElementById(type+'OtpInput').disabled=true;
            document.getElementById(type+'Verified').value='1';
            otpState[type].verified=true;
            updateBadges(); checkBothVerified();
        }else{
            status.className='otp-status error';
            status.innerHTML='<i class="fa fa-circle-xmark"></i> '+(data.message||'Invalid OTP.');
        }
    }catch(e){status.className='otp-status error';status.textContent='Network error.';}
}

function updateBadges(){
    const s=document.getElementById('verifySummary');s.innerHTML='';
    if(otpState.email.verified) s.innerHTML+='<span class="verified-badge"><i class="fa fa-envelope"></i> Email Verified</span>';
    if(otpState.phone.verified) s.innerHTML+='<span class="verified-badge"><i class="fa fa-phone"></i> Phone Verified</span>';
}
function checkBothVerified(){
    if(otpState.email.verified&&otpState.phone.verified){ document.getElementById('btnRegister').disabled=false; updateProgress(3); }
}
</script>
</body>
</html>
<?php
session_start();
$step  = (int)($_GET['step'] ?? 1);
$error = $_SESSION['fp_error'] ?? '';
unset($_SESSION['fp_error']);

// If OTP already verified, force step 3
if (!empty($_SESSION['forgot_otp_verified']) && $step < 3) $step = 3;
// If OTP sent but not verified, force step 2
if (!empty($_SESSION['forgot_otp']) && $step < 2) $step = 2;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Forgot Password - ColonyCare</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --green:#2d7a52;--green-dark:#1a5c3a;--green-btn:#1e7a50;--green-hover:#145f3f;
    --green-light:#e8f5ee;--green-soft:#f0f9f4;
    --text:#1a2e22;--sub:#5a7060;--muted:#8fa898;
    --border:#e5ece8;--bg:#f5f7f6;
    --radius:14px;
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);min-height:100vh;display:flex;align-items:center;justify-content:center;padding:20px;}

.fp-card{background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(0,0,0,.1);width:100%;max-width:440px;padding:36px 32px 32px;position:relative;}

/* Progress steps */
.steps{display:flex;align-items:center;justify-content:center;gap:0;margin-bottom:28px;}
.step{display:flex;flex-direction:column;align-items:center;gap:5px;flex:1;}
.step-circle{width:32px;height:32px;border-radius:50%;border:2px solid var(--border);background:#fff;display:flex;align-items:center;justify-content:center;font-size:.78rem;font-weight:700;color:var(--muted);transition:.3s;position:relative;z-index:1;}
.step.done .step-circle{background:var(--green);border-color:var(--green);color:#fff;}
.step.active .step-circle{background:var(--green-dark);border-color:var(--green-dark);color:#fff;}
.step-label{font-size:.65rem;font-weight:600;color:var(--muted);text-align:center;}
.step.active .step-label,.step.done .step-label{color:var(--green);}
.step-line{flex:1;height:2px;background:var(--border);margin:0 -1px;position:relative;top:-14px;z-index:0;}
.step-line.done{background:var(--green);}

.fp-icon{width:52px;height:52px;border-radius:14px;background:var(--green-soft);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:1.3rem;margin:0 auto 16px;}
.fp-title{font-size:1.3rem;font-weight:700;color:var(--text);text-align:center;margin-bottom:6px;}
.fp-sub{font-size:.85rem;color:var(--muted);text-align:center;margin-bottom:24px;line-height:1.5;}

.ff{margin-bottom:14px;}
.ff label{font-size:.8rem;font-weight:600;display:block;margin-bottom:5px;color:var(--text);}
.ff input{width:100%;padding:11px 14px;border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:.9rem;color:var(--text);outline:none;background:#fff;transition:border-color .2s;}
.ff input:focus{border-color:var(--green);}

.btn-primary{width:100%;padding:12px;background:var(--green-btn);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:.92rem;font-weight:700;cursor:pointer;transition:background .2s;display:flex;align-items:center;justify-content:center;gap:8px;}
.btn-primary:hover{background:var(--green-hover);}
.btn-primary:disabled{opacity:.6;cursor:not-allowed;}

.otp-row{display:flex;gap:8px;align-items:flex-end;margin-bottom:14px;}
.otp-row .ff{flex:1;margin-bottom:0;}
.btn-send-otp{padding:11px 16px;background:var(--green-soft);color:var(--green);border:1.5px solid var(--green-light);border-radius:10px;font-family:inherit;font-size:.82rem;font-weight:700;cursor:pointer;white-space:nowrap;transition:all .2s;flex-shrink:0;}
.btn-send-otp:hover{background:var(--green-light);}
.btn-send-otp:disabled{opacity:.5;cursor:not-allowed;}

.otp-status{font-size:.78rem;margin-bottom:12px;min-height:20px;display:flex;align-items:center;gap:6px;}
.otp-status.success{color:var(--green);}
.otp-status.error{color:#dc2626;}

.demo-box{background:#fffbeb;border:1px solid #fde68a;border-radius:9px;padding:10px 14px;font-size:.8rem;color:#92400e;margin-bottom:16px;display:none;align-items:center;gap:8px;}
.demo-box.show{display:flex;}
.demo-otp{font-size:1.1rem;font-weight:700;letter-spacing:.15em;color:var(--green-dark);font-family:monospace;}

.pass-wrap{position:relative;}
.pass-wrap input{padding-right:44px;}
.toggle-pass{position:absolute;right:12px;top:50%;transform:translateY(-50%);background:none;border:none;cursor:pointer;color:var(--muted);font-size:.9rem;padding:4px;}

.alert{padding:10px 14px;border-radius:9px;font-size:.83rem;margin-bottom:16px;display:flex;align-items:center;gap:8px;border:1px solid;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}

.back-link{display:flex;align-items:center;justify-content:center;gap:6px;margin-top:18px;font-size:.83rem;color:var(--muted);text-decoration:none;transition:color .2s;}
.back-link:hover{color:var(--green);}

.pass-strength{margin-top:6px;}
.strength-bar{height:4px;border-radius:99px;background:var(--border);overflow:hidden;margin-bottom:4px;}
.strength-fill{height:100%;border-radius:99px;width:0;transition:width .3s,background .3s;}
.strength-label{font-size:.72rem;color:var(--muted);}

@media(max-width:480px){
    .fp-card{padding:24px 18px 20px;}
    .fp-title{font-size:1.1rem;}
}
</style>
</head>
<body>

<div class="fp-card">

    <!-- Progress Steps -->
    <div class="steps">
        <div class="step <?= $step>=1?'done':'' ?> <?= $step===1?'active':'' ?>">
            <div class="step-circle"><?= $step>1?'<i class="fa fa-check"></i>':'1' ?></div>
            <div class="step-label">Email</div>
        </div>
        <div class="step-line <?= $step>1?'done':'' ?>"></div>
        <div class="step <?= $step>=2?($step>2?'done':'active'):'' ?>">
            <div class="step-circle"><?= $step>2?'<i class="fa fa-check"></i>':'2' ?></div>
            <div class="step-label">Verify OTP</div>
        </div>
        <div class="step-line <?= $step>2?'done':'' ?>"></div>
        <div class="step <?= $step>=3?'active':'' ?>">
            <div class="step-circle">3</div>
            <div class="step-label">New Password</div>
        </div>
    </div>

    <?php if($error): ?>
    <div class="alert alert-error"><i class="fa fa-circle-exclamation"></i><?= htmlspecialchars($error) ?></div>
    <?php endif; ?>

    <!-- ── STEP 1: Enter Email ── -->
    <?php if($step===1): ?>
    <div class="fp-icon"><i class="fa fa-lock"></i></div>
    <div class="fp-title">Forgot Password?</div>
    <div class="fp-sub">Enter your registered email address and we'll send you a verification code.</div>

    <div class="ff">
        <label>Email Address</label>
        <input type="email" id="fpEmail" placeholder="you@example.com" autofocus>
    </div>
    <div class="otp-status" id="emailStatus"></div>
    <div class="demo-box" id="demoBox">
        <i class="fa fa-circle-info"></i>
        <span>Demo OTP: <span class="demo-otp" id="demoOtp"></span></span>
    </div>

    <div class="otp-row" style="margin-bottom:14px;">
        <div class="ff" style="margin-bottom:0;">
            <label>Verification Code</label>
            <input type="text" id="fpOtp" placeholder="Enter 6-digit OTP" maxlength="6" disabled>
        </div>
        <button class="btn-send-otp" id="btnSendOtp" onclick="sendForgotOtp()">Send OTP</button>
    </div>

    <button class="btn-primary" id="btnVerifyOtp" onclick="verifyForgotOtp()" disabled>
        <i class="fa fa-arrow-right"></i> Verify & Continue
    </button>

    <!-- ── STEP 2: Already sent (page reload fallback) ── -->
    <?php elseif($step===2): ?>
    <div class="fp-icon"><i class="fa fa-envelope-open-text"></i></div>
    <div class="fp-title">Check Your Email</div>
    <div class="fp-sub">A 6-digit OTP was sent to <strong><?= htmlspecialchars($_SESSION['forgot_otp']['email'] ?? '') ?></strong>. Enter it below.</div>

    <div class="otp-row">
        <div class="ff" style="margin-bottom:0;">
            <label>Verification Code</label>
            <input type="text" id="fpOtp2" placeholder="Enter 6-digit OTP" maxlength="6" autofocus>
        </div>
        <button class="btn-send-otp" onclick="resendOtp()">Resend</button>
    </div>
    <div class="otp-status" id="emailStatus2"></div>

    <button class="btn-primary" onclick="verifyForgotOtp2()">
        <i class="fa fa-arrow-right"></i> Verify & Continue
    </button>

    <!-- ── STEP 3: New Password ── -->
    <?php elseif($step===3): ?>
    <div class="fp-icon" style="background:#f0fdf4;color:#16a34a;"><i class="fa fa-shield-check"></i></div>
    <div class="fp-title">Set New Password</div>
    <div class="fp-sub">Create a strong password for your account.</div>

    <form method="POST" action="/shivam/process_forgot_password.php">
        <div class="ff">
            <label>New Password</label>
            <div class="pass-wrap">
                <input type="password" name="new_password" id="newPass" placeholder="Min 8 characters" autofocus oninput="checkStrength(this.value)">
                <button type="button" class="toggle-pass" onclick="toggleVis('newPass',this)"><i class="fa fa-eye"></i></button>
            </div>
            <div class="pass-strength">
                <div class="strength-bar"><div class="strength-fill" id="strengthFill"></div></div>
                <div class="strength-label" id="strengthLabel"></div>
            </div>
        </div>
        <div class="ff">
            <label>Confirm Password</label>
            <div class="pass-wrap">
                <input type="password" name="confirm_password" id="confPass" placeholder="Repeat password">
                <button type="button" class="toggle-pass" onclick="toggleVis('confPass',this)"><i class="fa fa-eye"></i></button>
            </div>
        </div>
        <button type="submit" class="btn-primary"><i class="fa fa-check"></i> Reset Password</button>
    </form>

    <?php endif; ?>

    <a href="/shivam/login.php" class="back-link"><i class="fa fa-arrow-left"></i> Back to Login</a>
</div>

<script>
// ── STEP 1 JS ────────────────────────────────────────────
let otpSent = false;
let resendTimer = null;

function sendForgotOtp(){
    const email = document.getElementById('fpEmail').value.trim();
    if(!email){setStatus('emailStatus','Please enter your email.','error');return;}
    const btn = document.getElementById('btnSendOtp');
    btn.disabled = true; btn.textContent = 'Sending...';

    fetch('/shivam/otp_forgot_handler.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'send',email})
    }).then(r=>r.json()).then(data=>{
        if(data.success){
            otpSent = true;
            setStatus('emailStatus','✅ OTP sent! Check the demo box below.','success');
            document.getElementById('fpOtp').disabled = false;
            document.getElementById('fpOtp').focus();
            document.getElementById('btnVerifyOtp').disabled = false;
            if(data.demo_otp){
                document.getElementById('demoBox').classList.add('show');
                document.getElementById('demoOtp').textContent = data.demo_otp;
            }
            startResendTimer(btn);
        } else {
            setStatus('emailStatus', data.message || 'Failed to send OTP.','error');
            btn.disabled = false; btn.textContent = 'Send OTP';
        }
    }).catch(()=>{
        setStatus('emailStatus','Network error. Please try again.','error');
        btn.disabled = false; btn.textContent = 'Send OTP';
    });
}

function verifyForgotOtp(){
    const email = document.getElementById('fpEmail').value.trim();
    const otp   = document.getElementById('fpOtp').value.trim();
    if(!otp){setStatus('emailStatus','Please enter the OTP.','error');return;}

    const btn = document.getElementById('btnVerifyOtp');
    btn.disabled = true; btn.innerHTML = '<i class="fa fa-spinner fa-spin"></i> Verifying...';

    fetch('/shivam/otp_forgot_handler.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'verify',email,otp})
    }).then(r=>r.json()).then(data=>{
        if(data.success){
            setStatus('emailStatus','✅ Verified! Redirecting...','success');
            setTimeout(()=>window.location.href='/shivam/forgot-password.php?step=3',800);
        } else {
            setStatus('emailStatus', data.message || 'Incorrect OTP.','error');
            btn.disabled = false; btn.innerHTML = '<i class="fa fa-arrow-right"></i> Verify & Continue';
        }
    }).catch(()=>{
        setStatus('emailStatus','Network error.','error');
        btn.disabled = false; btn.innerHTML = '<i class="fa fa-arrow-right"></i> Verify & Continue';
    });
}

// ── STEP 2 JS (page-reload fallback) ─────────────────────
function verifyForgotOtp2(){
    const email = '<?= addslashes($_SESSION['forgot_otp']['email'] ?? '') ?>';
    const otp   = document.getElementById('fpOtp2')?.value.trim();
    if(!otp){setStatus('emailStatus2','Please enter the OTP.','error');return;}

    fetch('/shivam/otp_forgot_handler.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'verify',email,otp})
    }).then(r=>r.json()).then(data=>{
        if(data.success){
            setStatus('emailStatus2','✅ Verified!','success');
            setTimeout(()=>window.location.href='/shivam/forgot-password.php?step=3',800);
        } else {
            setStatus('emailStatus2', data.message || 'Incorrect OTP.','error');
        }
    }).catch(()=>setStatus('emailStatus2','Network error.','error'));
}

function resendOtp(){
    const email = '<?= addslashes($_SESSION['forgot_otp']['email'] ?? '') ?>';
    fetch('/shivam/otp_forgot_handler.php',{
        method:'POST',
        headers:{'Content-Type':'application/json'},
        body:JSON.stringify({action:'send',email})
    }).then(r=>r.json()).then(data=>{
        if(data.success){
            setStatus('emailStatus2','✅ New OTP sent!','success');
        } else {
            setStatus('emailStatus2', data.message,'error');
        }
    });
}

// ── STEP 3 JS ────────────────────────────────────────────
function checkStrength(val){
    const fill  = document.getElementById('strengthFill');
    const label = document.getElementById('strengthLabel');
    if(!fill) return;
    let score = 0;
    if(val.length >= 8)  score++;
    if(/[A-Z]/.test(val)) score++;
    if(/[0-9]/.test(val)) score++;
    if(/[^A-Za-z0-9]/.test(val)) score++;
    const levels = [
        {w:'0%',   c:'#e5ece8', t:''},
        {w:'25%',  c:'#ef4444', t:'Weak'},
        {w:'50%',  c:'#f59e0b', t:'Fair'},
        {w:'75%',  c:'#3b82f6', t:'Good'},
        {w:'100%', c:'#22c55e', t:'Strong'},
    ];
    const l = levels[score] || levels[0];
    fill.style.width = l.w; fill.style.background = l.c;
    label.textContent = l.t; label.style.color = l.c;
}

function toggleVis(id, btn){
    const inp = document.getElementById(id);
    if(!inp) return;
    inp.type = inp.type==='password' ? 'text' : 'password';
    btn.innerHTML = inp.type==='password' ? '<i class="fa fa-eye"></i>' : '<i class="fa fa-eye-slash"></i>';
}

// ── HELPERS ───────────────────────────────────────────────
function setStatus(id, msg, type){
    const el = document.getElementById(id);
    if(!el) return;
    el.textContent = msg;
    el.className = 'otp-status ' + type;
}

function startResendTimer(btn){
    let secs = 30;
    clearInterval(resendTimer);
    resendTimer = setInterval(()=>{
        secs--;
        btn.textContent = 'Resend in ' + secs + 's';
        if(secs <= 0){
            clearInterval(resendTimer);
            btn.textContent = 'Resend OTP';
            btn.disabled = false;
        }
    }, 1000);
}

// Allow Enter key on OTP input
document.addEventListener('DOMContentLoaded', function(){
    const otpInp = document.getElementById('fpOtp');
    if(otpInp) otpInp.addEventListener('keydown', e=>{ if(e.key==='Enter') verifyForgotOtp(); });
    const otpInp2 = document.getElementById('fpOtp2');
    if(otpInp2) otpInp2.addEventListener('keydown', e=>{ if(e.key==='Enter') verifyForgotOtp2(); });
});
</script>
</body>
</html>
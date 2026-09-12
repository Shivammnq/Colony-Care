<?php
session_start();

// DB constants loaded via config.php

$error = '';

// Already logged in → redirect away
if (isset($_SESSION['user_id'])) {
    header('Location: /listings.php'); exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name     = trim($_POST['name']     ?? '');
    $email    = trim($_POST['email']    ?? '');
    $phone    = trim($_POST['phone']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($name) || empty($email) || empty($phone) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } else {
        try {
            $pdo = get_db_connection();

            // ── Migration: society_id must be nullable so buyer accounts
            //    (which have no society) can satisfy the foreign key ──
            $colInfo = $pdo->query("SHOW COLUMNS FROM users LIKE 'society_id'")->fetch();
            if ($colInfo && stripos($colInfo['Null'], 'NO') === 0) {
                $pdo->exec("ALTER TABLE users MODIFY COLUMN society_id {$colInfo['Type']} NULL");
            }

            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $chk->execute([$email]);
            if ($chk->fetch()) {
                $error = 'An account with this email already exists. Please login instead.';
            } else {
                $hashed = password_hash($password, PASSWORD_BCRYPT);
                $pdo->prepare("
                    INSERT INTO users (name, email, phone, password, role, society, society_id, unit, block, is_active, created_at)
                    VALUES (?, ?, ?, ?, 'buyer', '', NULL, '', '', 1, NOW())
                ")->execute([$name, $email, $phone, $hashed]);

                $_SESSION['login_success'] = 'Account created! You can now log in to view contact details and message flat owners.';
                header('Location: /login.php'); exit;
            }
        } catch (PDOException $e) {
            $error = 'Database error: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Looking to Buy or Rent a Flat? - ColonyCare</title>
<meta name="description" content="Create a free ColonyCare account to browse verified flat listings and message owners directly — no brokerage, no society membership required.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://www.example.com/register-buyer.php">
<meta property="og:type" content="website">
<meta property="og:title" content="Looking to Buy or Rent a Flat? - ColonyCare">
<meta property="og:description" content="Create a free ColonyCare account to browse verified flat listings and message owners directly — no brokerage, no society membership required.">
<meta property="og:url" content="https://www.example.com/register-buyer.php">
<meta property="og:site_name" content="ColonyCare">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;min-height:100vh;display:flex;}
.login-page{min-height:100vh;display:flex;width:100%;background:radial-gradient(ellipse 70% 60% at 15% 20%,#c8e6d5,transparent 60%),radial-gradient(ellipse 60% 50% at 85% 80%,#d4ebe0,transparent 55%),#f0f5f2;}
.login-left{flex:1;background:linear-gradient(135deg,#0f8f6f 0%,#2d7a60 60%,#3a9467 100%);display:flex;flex-direction:column;justify-content:center;padding:60px 56px;color:#fff;position:relative;overflow:hidden;}
.login-left::before{content:'';position:absolute;width:400px;height:400px;border-radius:50%;background:rgba(255,255,255,.05);top:-100px;right:-100px;}
.login-left::after{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.04);bottom:-80px;left:-80px;}
.login-brand{display:flex;align-items:center;gap:12px;margin-bottom:56px;position:relative;z-index:1;}
.brand-icon{width:48px;height:48px;border-radius:14px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:1.4rem;}
.login-brand h2{font-size:1.5rem;font-weight:700;margin:0;}
.login-left h1{font-family:'DM Serif Display',serif;font-size:2.2rem;font-weight:400;line-height:1.25;margin-bottom:16px;position:relative;z-index:1;}
.login-left > p{font-size:.98rem;opacity:.85;line-height:1.7;margin-bottom:48px;position:relative;z-index:1;}
.login-features{list-style:none;padding:0;margin:0;position:relative;z-index:1;}
.login-features li{display:flex;align-items:center;gap:12px;font-size:.9rem;opacity:.9;margin-bottom:16px;}
.login-features li i{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
.back-home{display:inline-flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);text-decoration:none;font-size:.85rem;margin-bottom:32px;position:relative;z-index:1;transition:color .2s;}
.back-home:hover{color:#fff;}
.login-right{width:480px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:40px 32px;}
.login-card{width:100%;background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(30,80,50,.12);padding:44px 40px 36px;}
.login-card-logo{width:64px;height:64px;border-radius:18px;background:#d4eddf;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:1.6rem;color:#0f8f6f;}
.login-card h2{text-align:center;font-size:1.4rem;font-weight:700;color:#1a2e22;margin-bottom:4px;}
.login-card .sub{text-align:center;font-size:.875rem;color:#8fa898;margin-bottom:28px;}
.alert{padding:11px 14px;border-radius:10px;font-size:.875rem;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;border:1px solid;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.field{margin-bottom:18px;}
.field label{font-size:.85rem;font-weight:600;color:#1a2e22;display:block;margin-bottom:8px;}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-icon{position:absolute;left:13px;color:#8fa898;display:flex;pointer-events:none;font-size:.9rem;}
.input-wrap input{width:100%;padding:12px 14px 12px 40px;border:1.5px solid #dce8e1;border-radius:10px;font-family:inherit;font-size:.95rem;color:#1a2e22;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;}
.input-wrap input:focus{border-color:#2d7a52;box-shadow:0 0 0 3px rgba(45,122,82,.12);}
.btn-login{width:100%;padding:13px;background:linear-gradient(135deg,#0f8f6f,#22c1a1);color:#fff;font-family:inherit;font-size:1rem;font-weight:600;border:none;border-radius:10px;cursor:pointer;margin-top:6px;letter-spacing:.2px;transition:box-shadow .2s,transform .15s;}
.btn-login:hover{box-shadow:0 4px 20px rgba(45,122,82,.3);transform:translateY(-1px);}
.register-row{text-align:center;font-size:.875rem;color:#8fa898;margin-top:16px;}
.register-row a{color:#2d7a52;font-weight:700;text-decoration:none;}
.register-row a:hover{color:#1a5c3a;}
.btn-google{width:100%;padding:12px;background:#fff;color:#3c4043;font-family:inherit;font-size:.92rem;font-weight:600;border:1.5px solid #dce8e1;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;text-decoration:none;transition:background .2s,box-shadow .2s;margin-top:14px;}
.btn-google:hover{background:#f8f9fa;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.divider{display:flex;align-items:center;gap:10px;margin:18px 0 0;}
.divider span{font-size:.78rem;color:#c5d5cc;white-space:nowrap;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#e5ece8;}
@media(max-width:900px){.login-left{display:none;}.login-right{width:100%;}.login-page{justify-content:center;}}
@media(max-width:500px){.login-card{padding:32px 24px 28px;}.login-right{padding:24px 16px;}}
</style>
</head>
<body>
<div class="login-page">

    <div class="login-left">
        <a href="/index.php" class="back-home"><i class="fa fa-arrow-left"></i> Back to Home</a>
        <div class="login-brand">
            <div class="brand-icon"><i class="fa fa-building"></i></div>
            <h2>ColonyCare</h2>
        </div>
        <h1>Looking for a Flat to Buy or Rent?</h1>
        <p>Create a free account to view contact details and message flat owners directly across every ColonyCare community.</p>
        <ul class="login-features">
            <li><i class="fa fa-house"></i> Browse verified resident listings</li>
            <li><i class="fa fa-comments"></i> Message owners directly, no number needed</li>
            <li><i class="fa fa-shield"></i> No society membership required</li>
        </ul>
    </div>

    <div class="login-right">
    <div class="login-card">

        <div class="login-card-logo"><i class="fa fa-user-plus"></i></div>
        <h2>Create a Free Account</h2>
        <p class="sub">For buyers &amp; renters only — takes less than a minute</p>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa fa-circle-exclamation" style="margin-top:2px;flex-shrink:0"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="field">
                <label for="name">Full Name</label>
                <div class="input-wrap">
                    <span class="input-icon"><i class="fa fa-user"></i></span>
                    <input type="text" id="name" name="name" placeholder="Your name"
                           value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                </div>
            </div>
            <div class="field">
                <label for="email">Email Address</label>
                <div class="input-wrap">
                    <span class="input-icon"><i class="fa fa-envelope"></i></span>
                    <input type="email" id="email" name="email" placeholder="you@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>" required>
                </div>
            </div>
            <div class="field">
                <label for="phone">Phone Number</label>
                <div class="input-wrap">
                    <span class="input-icon"><i class="fa fa-phone"></i></span>
                    <input type="tel" id="phone" name="phone" placeholder="10-digit mobile"
                           value="<?= htmlspecialchars($_POST['phone'] ?? '') ?>" required>
                </div>
            </div>
            <div class="field">
                <label for="password">Password</label>
                <div class="input-wrap">
                    <span class="input-icon"><i class="fa fa-lock"></i></span>
                    <input type="password" id="password" name="password" placeholder="At least 8 characters"
                           minlength="8" required>
                </div>
            </div>
            <button type="submit" class="btn-login"><i class="fa fa-user-plus"></i> Create Account</button>
        </form>

        <div class="divider"><span>or</span></div>

        <a href="/google_callback.php" class="btn-google">
            <svg width="18" height="18" viewBox="0 0 48 48" xmlns="http://www.w3.org/2000/svg">
                <path fill="#FFC107" d="M43.6 20.5H42V20H24v8h11.3C33.9 32.9 29.4 36 24 36c-6.6 0-12-5.4-12-12s5.4-12 12-12c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.1 29.5 4 24 4 12.9 4 4 12.9 4 24s8.9 20 20 20 20-8.9 20-20c0-1.3-.1-2.7-.4-3.5z"/>
                <path fill="#FF3D00" d="M6.3 14.7l6.6 4.8C14.6 15.9 18.9 13 24 13c3.1 0 5.9 1.2 8 3.1l5.7-5.7C34.5 6.1 29.5 4 24 4 16.3 4 9.7 8.3 6.3 14.7z"/>
                <path fill="#4CAF50" d="M24 44c5.4 0 10.3-2.1 14-5.5l-6.5-5.5C29.4 34.7 26.9 36 24 36c-5.4 0-9.9-3.1-11.3-7.9l-6.6 5.1C9.5 39.6 16.2 44 24 44z"/>
                <path fill="#1976D2" d="M43.6 20.5H42V20H24v8h11.3c-.7 2.1-2 3.9-3.8 5.2l6.5 5.5C41.4 36.2 44 30.6 44 24c0-1.3-.1-2.7-.4-3.5z"/>
            </svg>
            Continue with Google
        </a>

        <div class="register-row">
            Already have an account? <a href="/login.php">Login</a>
        </div>
        <div class="register-row">
            Are you a resident? <a href="/register.php">Register your unit instead</a>
        </div>

    </div>
    </div>

</div>
</body>
</html>
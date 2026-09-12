<?php
require_once __DIR__ . '/config.php';

session_start();

// ── Safe redirect helper: only allow same-app local paths,
//    never an external URL, to avoid open-redirect vulnerabilities ──
function safe_redirect_target($url) {
    if (!$url) return null;
    $url = trim($url);
    if ((str_starts_with($url, '/') || str_starts_with($url, '/')) && !str_contains($url, '://') && !str_starts_with($url, '//')) {
        if (str_starts_with($url, '/')) {
            $url = substr($url, 7);
        }
        return '/' . ltrim($url, '/');
    }
    return null;
}

// Capture ?redirect= from the URL (GET) and remember it across the POST,
// so it survives the form submission that follows.
if (isset($_GET['redirect'])) {
    $_SESSION['post_login_redirect'] = safe_redirect_target($_GET['redirect']);
}
$pendingRedirect = $_SESSION['post_login_redirect'] ?? null;

// ── Already logged in → redirect ──────────────────────────
if (isset($_SESSION['user_id'])) {
    if ($pendingRedirect) {
        unset($_SESSION['post_login_redirect']);
        header('Location: ' . $pendingRedirect); exit;
    }

    $is_owner = false;
    $society_status = null;
    if (($_SESSION['user_role'] ?? '') === 'admin' && !empty($_SESSION['user_society_id'])) {
        $pdoChk = get_db_connection();
        $hasStatusCol = $pdoChk->query("SHOW COLUMNS FROM societies LIKE 'status'")->fetch();
        if (!$hasStatusCol) {
            $pdoChk->exec("ALTER TABLE societies ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'approved'");
        }
        $own = $pdoChk->prepare("SELECT owner_id, status FROM societies WHERE id=? LIMIT 1");
        $own->execute([$_SESSION['user_society_id']]);
        $ownRow = $own->fetch();
        if ($ownRow) {
            $is_owner = ((int)$ownRow['owner_id'] === (int)$_SESSION['user_id']);
            $society_status = $ownRow['status'] ?? 'approved';
        }
    }

    if ($is_owner && $society_status !== 'approved') {
        $_SESSION = [];
        session_destroy();
        session_start();
        $error = $society_status === 'rejected'
            ? 'Your society registration was not approved. Please contact support.'
            : "Your society is still awaiting Super Admin approval. You'll be notified once it's live.";
    } else {
        if ($is_owner) { header('Location: /society.php'); exit; }

        $redirect = match($_SESSION['user_role'] ?? 'resident') {
            'admin'          => '/admin_dashboard.php',
            'staff'          => '/gate_staff.php',
            'resident'       => '/resident.php',
            'accountant'     => '/accountant.php',
            'vendor'         => '/vendor.php',
            'society_member' => '/society-member.php',
            'buyer'          => '/listings.php',
            'super_admin'    => '/super_admin.php',
            default          => '/resident.php',
        };
        header('Location: ' . $redirect); exit;
    }
}

$error   = $_SESSION['login_error'] ?? '';
unset($_SESSION['login_error']);
$success = $_SESSION['login_success'] ?? '';
unset($_SESSION['login_success']);

// ── Handle POST ────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $email    = trim($_POST['email']    ?? '');
    $password = trim($_POST['password'] ?? '');

    if (empty($email) || empty($password)) {
        $error = 'Please fill in all fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Invalid email address.';
    } else {
        try {
            $pdo = get_db_connection();

            // ── Ensure token_version column exists (session revocation) ──
            $hasTokenCol = $pdo->query("SHOW COLUMNS FROM users LIKE 'token_version'")->fetch();
            if (!$hasTokenCol) {
                $pdo->exec("ALTER TABLE users ADD COLUMN token_version INT NOT NULL DEFAULT 0");
            }

            // ── Fetch user including society column ────────
            $stmt = $pdo->prepare("
                SELECT id, name, email, password, role, is_active,
                    COALESCE(society, '') as society,
                    society_id, token_version
                FROM users WHERE email = ? LIMIT 1
            ");
            $stmt->execute([$email]);
            $user = $stmt->fetch();

            if (!$user || !password_verify($password, $user['password'])) {
                $error = 'Invalid email or password.';
            } elseif (!$user['is_active']) {
                $error = 'Your account is pending admin approval. Please contact admin.';
            } else {
                // Update last login
                try {
                    $pdo->prepare("UPDATE users SET last_login = NOW() WHERE id = ?")
                        ->execute([$user['id']]);
                } catch(Exception $e){}

                session_regenerate_id(true);

                $_SESSION['user_id']         = $user['id'];
                $_SESSION['user_name']       = $user['name'];
                $_SESSION['user_email']      = $user['email'];
                $_SESSION['user_role']       = $user['role'];
                $_SESSION['user_society']    = $user['society'];
                $_SESSION['user_society_id'] = $user['society_id'];
                $_SESSION['is_logged_in']    = true;
                $_SESSION['token_version']   = (int)$user['token_version'];

                if ($pendingRedirect) {
                    unset($_SESSION['post_login_redirect']);
                    header('Location: ' . $pendingRedirect); exit;
                }

                $is_owner = false;
                $society_status = null;
                if ($user['role'] === 'admin' && !empty($user['society_id'])) {
                    $hasStatusCol = $pdo->query("SHOW COLUMNS FROM societies LIKE 'status'")->fetch();
                    if (!$hasStatusCol) {
                        $pdo->exec("ALTER TABLE societies ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'approved'");
                    }
                    $own = $pdo->prepare("SELECT owner_id, status FROM societies WHERE id=? LIMIT 1");
                    $own->execute([$user['society_id']]);
                    $ownRow = $own->fetch();
                    if ($ownRow) {
                        $is_owner = ((int)$ownRow['owner_id'] === (int)$user['id']);
                        $society_status = $ownRow['status'] ?? 'approved';
                    }
                }

                if ($is_owner && $society_status !== 'approved') {
                    // Log them back out — their society isn't approved yet, so there's nowhere for them to go
                    $_SESSION = [];
                    session_destroy();
                    session_start();
                    $error = $society_status === 'rejected'
                        ? 'Your society registration was not approved. Please contact support.'
                        : "Your society is still awaiting Super Admin approval. You'll be notified once it's live.";
                } else {
                    if ($is_owner) { header('Location: /society.php'); exit; }

                    $redirect = match($user['role']) {
                        'admin'          => '/admin_dashboard.php',
                        'staff'          => '/gate_staff.php',
                        'resident'       => '/resident.php',
                        'accountant'     => '/accountant.php',
                        'vendor'         => '/vendor.php',
                        'society_member' => '/society-member.php',
                        'buyer'          => '/listings.php',
                        'super_admin'    => '/super_admin.php',
                        default          => '/resident.php',
                    };
                    header('Location: ' . $redirect); exit;
                }
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
<title>Login - ColonyCare | Society Management Portal</title>
<meta name="description" content="Sign in to your ColonyCare account to manage visitor entry, billing, complaints, and events for your residential society.">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://www.example.com/login.php">
<meta property="og:type" content="website">
<meta property="og:title" content="Login - ColonyCare">
<meta property="og:description" content="Sign in to your ColonyCare account to manage visitor entry, billing, complaints, and events for your residential society.">
<meta property="og:url" content="https://www.example.com/login.php">
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
.login-left h1{font-family:'DM Serif Display',serif;font-size:2.4rem;font-weight:400;line-height:1.25;margin-bottom:16px;position:relative;z-index:1;}
.login-left > p{font-size:.98rem;opacity:.85;line-height:1.7;margin-bottom:48px;position:relative;z-index:1;}
.login-features{list-style:none;padding:0;margin:0;position:relative;z-index:1;}
.login-features li{display:flex;align-items:center;gap:12px;font-size:.9rem;opacity:.9;margin-bottom:16px;}
.login-features li i{width:32px;height:32px;border-radius:8px;background:rgba(255,255,255,.15);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
.login-stats{display:flex;gap:32px;margin-top:48px;position:relative;z-index:1;padding-top:32px;border-top:1px solid rgba(255,255,255,.15);}
.login-stats div h3{font-size:1.4rem;font-weight:700;margin:0 0 2px;}
.login-stats div p{font-size:.78rem;opacity:.75;margin:0;}
.back-home{display:inline-flex;align-items:center;gap:8px;color:rgba(255,255,255,.7);text-decoration:none;font-size:.85rem;margin-bottom:32px;position:relative;z-index:1;transition:color .2s;}
.back-home:hover{color:#fff;}
.login-right{width:480px;flex-shrink:0;display:flex;align-items:center;justify-content:center;padding:40px 32px;}
.login-card{width:100%;background:#fff;border-radius:20px;box-shadow:0 8px 40px rgba(30,80,50,.12);padding:44px 40px 36px;}
.login-card-logo{width:64px;height:64px;border-radius:18px;background:#d4eddf;display:flex;align-items:center;justify-content:center;margin:0 auto 20px;font-size:1.6rem;color:#0f8f6f;}
.login-card h2{text-align:center;font-size:1.4rem;font-weight:700;color:#1a2e22;margin-bottom:4px;}
.login-card .sub{text-align:center;font-size:.875rem;color:#8fa898;margin-bottom:28px;}
.alert{padding:11px 14px;border-radius:10px;font-size:.875rem;margin-bottom:20px;display:flex;align-items:flex-start;gap:10px;border:1px solid;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.alert-success{background:#f0fdf4;color:#0f8f6f;border-color:#bbf7d0;}
.field{margin-bottom:18px;}
.field-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;}
.field label{font-size:.85rem;font-weight:600;color:#1a2e22;}
.forgot{font-size:.8rem;color:#0f8f6f;text-decoration:none;font-weight:500;}
.input-wrap{position:relative;display:flex;align-items:center;}
.input-icon{position:absolute;left:13px;color:#8fa898;display:flex;pointer-events:none;font-size:.9rem;}
.input-wrap input{width:100%;padding:12px 42px 12px 40px;border:1.5px solid #dce8e1;border-radius:10px;font-family:inherit;font-size:.95rem;color:#1a2e22;background:#fff;outline:none;transition:border-color .2s,box-shadow .2s;}
.input-wrap input:focus{border-color:#2d7a52;box-shadow:0 0 0 3px rgba(45,122,82,.12);}
.toggle-pass{position:absolute;right:13px;background:none;border:none;cursor:pointer;color:#8fa898;font-size:.95rem;padding:4px;transition:color .2s;}
.toggle-pass:hover{color:#2d7a52;}
.btn-login{width:100%;padding:13px;background:linear-gradient(135deg,#0f8f6f,#22c1a1);color:#fff;font-family:inherit;font-size:1rem;font-weight:600;border:none;border-radius:10px;cursor:pointer;margin-top:6px;letter-spacing:.2px;transition:box-shadow .2s,transform .15s;}
.btn-login:hover{box-shadow:0 4px 20px rgba(45,122,82,.3);transform:translateY(-1px);}
.btn-login:active{transform:translateY(0);}
.divider{display:flex;align-items:center;gap:10px;margin:20px 0;}
.divider span{font-size:.78rem;color:#c5d5cc;white-space:nowrap;}
.divider::before,.divider::after{content:'';flex:1;height:1px;background:#e5ece8;}
.btn-google{width:100%;padding:12px;background:#fff;color:#3c4043;font-family:inherit;font-size:.92rem;font-weight:600;border:1.5px solid #dce8e1;border-radius:10px;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:10px;text-decoration:none;transition:background .2s,box-shadow .2s;margin-bottom:20px;}
.btn-google:hover{background:#f8f9fa;box-shadow:0 2px 8px rgba(0,0,0,.08);}
.register-row{text-align:center;font-size:.875rem;color:#8fa898;margin-bottom:8px;}
.register-row a{color:#2d7a52;font-weight:700;text-decoration:none;}
.register-row a:hover{color:#1a5c3a;}
.secure-note{text-align:center;margin-top:16px;font-size:.75rem;color:#b0c4b8;display:flex;align-items:center;justify-content:center;gap:6px;}
@media(max-width:900px){.login-left{display:none;}.login-right{width:100%;}.login-page{justify-content:center;}}
@media(max-width:500px){.login-card{padding:32px 24px 28px;}.login-right{padding:24px 16px;}}
</style>
</head>
<body>
<div class="login-page">

    <!-- LEFT PANEL -->
    <div class="login-left">
        <a href="/index.php" class="back-home"><i class="fa fa-arrow-left"></i> Back to Home</a>
        <div class="login-brand">
            <div class="brand-icon"><i class="fa fa-building"></i></div>
            <h2>ColonyCare</h2>
        </div>
        <h1>India's Smartest Colony Management Platform</h1>
        <p>From visitor management to billing, complaints to community events — everything your RWA needs in one powerful platform.</p>
        <ul class="login-features">
            <li><i class="fa fa-shield"></i> Secure &amp; encrypted login</li>
            <li><i class="fa fa-users"></i> Role-based access for all stakeholders</li>
            <li><i class="fa fa-chart-line"></i> Real-time reports &amp; dashboards</li>
            <li><i class="fa fa-bell"></i> Instant alerts &amp; notifications</li>
        </ul>
        <div class="login-stats">
            <div><h3>500+</h3><p>Communities</p></div>
            <div><h3>50K+</h3><p>Residents</p></div>
            <div><h3>4.8★</h3><p>Rating</p></div>
        </div>
    </div>

    <!-- RIGHT PANEL -->
    <div class="login-right">
    <div class="login-card">

        <div class="login-card-logo"><i class="fa fa-building"></i></div>
        <h2>Welcome Back</h2>
        <p class="sub">Sign in to your society portal</p>

        <?php if ($error): ?>
        <div class="alert alert-error">
            <i class="fa fa-circle-exclamation" style="margin-top:2px;flex-shrink:0"></i>
            <?= htmlspecialchars($error) ?>
        </div>
        <?php endif; ?>

        <?php if ($success): ?>
        <div class="alert alert-success">
            <i class="fa fa-circle-check" style="margin-top:2px;flex-shrink:0"></i>
            <?= htmlspecialchars($success) ?>
        </div>
        <?php endif; ?>

        <form method="POST" action="">
            <div class="field">
                <div class="field-header"><label for="email">Email Address</label></div>
                <div class="input-wrap">
                    <span class="input-icon"><i class="fa fa-envelope"></i></span>
                    <input type="email" id="email" name="email" placeholder="you@example.com"
                           value="<?= htmlspecialchars($_POST['email'] ?? '') ?>"
                           autocomplete="email" required>
                </div>
            </div>
            <div class="field">
                <div class="field-header">
                    <label for="password">Password</label>
                    <a href="forgot-password.php" class="forgot">Forgot password?</a>
                </div>
                <div class="input-wrap">
                    <span class="input-icon"><i class="fa fa-lock"></i></span>
                    <input type="password" id="password" name="password"
                           placeholder="••••••••"
                           autocomplete="current-password" required>
                    <button type="button" class="toggle-pass" id="togglePass">
                        <i class="fa fa-eye" id="eyeIcon"></i>
                    </button>
                </div>
            </div>
            <button type="submit" class="btn-login">
                <i class="fa fa-right-to-bracket"></i> Sign In
            </button>
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
            New society? <a href="/register-society.php">Register Your Society</a>
        </div>
        <div class="register-row">
            New resident? <a href="/register.php">Register as Resident</a>
        </div>
        <div class="register-row">
            Looking to buy/rent a flat? <a href="/register-buyer.php">Create a Free Account</a>
        </div>

        <div class="secure-note">
            <i class="fa fa-lock"></i> Secure login powered by encrypted authentication
        </div>

    </div>
    </div>

</div>
<script>
document.getElementById('togglePass').addEventListener('click',function(){
    const p=document.getElementById('password'),e=document.getElementById('eyeIcon');
    p.type=p.type==='password'?'text':'password';
    e.className=p.type==='password'?'fa fa-eye':'fa fa-eye-slash';
});
</script>
</body>
</html>
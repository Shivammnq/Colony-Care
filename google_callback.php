<?php
session_start();

// ═══════════════════════════════════════════════════════════════
// GOOGLE OAUTH CONFIG — fill these in from Google Cloud Console
// (APIs & Services → Credentials → your OAuth 2.0 Client ID)
// ═══════════════════════════════════════════════════════════════
require_once __DIR__ . '/config/google_credentials.php';

// DB constants loaded via config.php

function curl_post($url, $fields) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($fields));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    $err = curl_error($ch);
    curl_close($ch);
    if ($err) { return null; }
    return json_decode($response, true);
}

function curl_get($url, $headers = []) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
    $response = curl_exec($ch);
    curl_close($ch);
    return json_decode($response, true);
}

// ── Step 1: no code yet → redirect to Google's consent screen ──
if (!isset($_GET['code'])) {
    if (GOOGLE_CLIENT_ID === 'YOUR_GOOGLE_CLIENT_ID_HERE') {
        $_SESSION['login_error'] = 'Google login is not configured yet. Please add your Client ID and Secret in google_callback.php.';
        header('Location: /login.php'); exit;
    }
    $params = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'redirect_uri'  => GOOGLE_REDIRECT_URI,
        'response_type' => 'code',
        'scope'         => 'openid email profile',
        'prompt'        => 'select_account',
    ]);
    header('Location: https://accounts.google.com/o/oauth2/v2/auth?' . $params);
    exit;
}

// ── Step 2: Google redirected back with a code → exchange for token ──
$tokenData = curl_post('https://oauth2.googleapis.com/token', [
    'code'          => $_GET['code'],
    'client_id'     => GOOGLE_CLIENT_ID,
    'client_secret' => GOOGLE_CLIENT_SECRET,
    'redirect_uri'  => GOOGLE_REDIRECT_URI,
    'grant_type'    => 'authorization_code',
]);

if (empty($tokenData['access_token'])) {
    $_SESSION['login_error'] = 'Google login failed. Please try again or use your email and password.';
    header('Location: /login.php'); exit;
}

// ── Step 3: fetch the person's Google profile ──
$profile = curl_get('https://www.googleapis.com/oauth2/v3/userinfo', [
    'Authorization: Bearer ' . $tokenData['access_token']
]);

if (empty($profile['email'])) {
    $_SESSION['login_error'] = 'Could not retrieve your Google account details. Please try again.';
    header('Location: /login.php'); exit;
}

$g_email = trim($profile['email']);
$g_name  = trim($profile['name'] ?? explode('@', $g_email)[0]);

// ── Step 4: look up or create the user ──
try {
    $pdo = get_db_connection();

    $stmt = $pdo->prepare("
        SELECT id, name, email, role, is_active,
            COALESCE(society, '') as society,
            society_id
        FROM users WHERE email = ? LIMIT 1
    ");
    $stmt->execute([$g_email]);
    $user = $stmt->fetch();

    if (!$user) {
        // ── Migration: society_id must be nullable so buyer accounts
        //    (which have no society) can satisfy the foreign key ──
        $colInfo = $pdo->query("SHOW COLUMNS FROM users LIKE 'society_id'")->fetch();
        if ($colInfo && stripos($colInfo['Null'], 'NO') === 0) {
            $pdo->exec("ALTER TABLE users MODIFY COLUMN society_id {$colInfo['Type']} NULL");
        }

        // No existing account with this email → create a lightweight Buyer account
        $randomPassword = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);
        $pdo->prepare("
            INSERT INTO users (name, email, phone, password, role, society, society_id, unit, block, is_active, created_at)
            VALUES (?, ?, '', ?, 'buyer', '', NULL, '', '', 1, NOW())
        ")->execute([$g_name, $g_email, $randomPassword]);

        $newId = $pdo->lastInsertId();
        $stmt->execute([$g_email]);
        $user = $stmt->fetch();
    }

    if (!$user['is_active']) {
        $_SESSION['login_error'] = 'Your account is pending admin approval. Please contact your society admin.';
        header('Location: /login.php'); exit;
    }

    // ── Log them in (same session shape as the normal password login) ──
    session_regenerate_id(true);
    $_SESSION['user_id']         = $user['id'];
    $_SESSION['user_name']       = $user['name'];
    $_SESSION['user_email']      = $user['email'];
    $_SESSION['user_role']       = $user['role'];
    $_SESSION['user_society']    = $user['society'];
    $_SESSION['user_society_id'] = $user['society_id'];
    $_SESSION['is_logged_in']    = true;

    // Honor a pending "return here after login" target set by login.php
    // (e.g. someone clicked "Login to view contact" on listings.php)
    $pendingRedirect = $_SESSION['post_login_redirect'] ?? null;
    if ($pendingRedirect && str_starts_with($pendingRedirect, '/') && !str_contains($pendingRedirect, '://')) {
        unset($_SESSION['post_login_redirect']);
        header('Location: ' . $pendingRedirect); exit;
    }
    unset($_SESSION['post_login_redirect']);

    $is_owner = false;
    if ($user['role'] === 'admin' && !empty($user['society_id'])) {
        $own = $pdo->prepare("SELECT 1 FROM societies WHERE id=? AND owner_id=? LIMIT 1");
        $own->execute([$user['society_id'], $user['id']]);
        $is_owner = (bool)$own->fetchColumn();
    }

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

} catch (PDOException $e) {
    $_SESSION['login_error'] = 'Database error during Google login: ' . $e->getMessage();
    header('Location: /login.php'); exit;
}
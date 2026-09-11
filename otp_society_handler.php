<?php
session_start();
header('Content-Type: application/json');

define('OTP_EXPIRY_SECONDS', 300); // 5 minutes

// ── Read JSON body ─────────────────────────────────────────
$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$type   = $body['type']   ?? '';
$value  = trim($body['value'] ?? '');
$otp    = trim($body['otp']   ?? '');

if (!in_array($action, ['send','verify']) || !in_array($type, ['email','phone'])) {
    echo json_encode(['success'=>false,'message'=>'Invalid request.']);
    exit;
}

// ── SEND OTP ───────────────────────────────────────────────
if ($action === 'send') {

    if ($type === 'email' && !filter_var($value, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success'=>false,'message'=>'Invalid email address.']);
        exit;
    }
    if ($type === 'phone' && !preg_match('/^[6-9][0-9]{9}$/', $value)) {
        echo json_encode(['success'=>false,'message'=>'Invalid phone number.']);
        exit;
    }

    // Generate 6-digit OTP
    $generatedOtp = str_pad(random_int(100000, 999999), 6, '0', STR_PAD_LEFT);

    // Store in session
    $_SESSION['otp_'.$type] = [
        'otp'      => $generatedOtp,
        'value'    => $value,
        'expires'  => time() + OTP_EXPIRY_SECONDS,
        'verified' => false,
    ];

    // ── DEMO MODE: return OTP directly in response ─────────
    echo json_encode([
        'success' => true,
        'message' => 'OTP generated successfully.',
        'demo_otp' => $generatedOtp, // shown on screen in demo
    ]);
    exit;
}

// ── VERIFY OTP ─────────────────────────────────────────────
if ($action === 'verify') {
    $stored = $_SESSION['otp_'.$type] ?? null;

    if (!$stored) {
        echo json_encode(['success'=>false,'message'=>'No OTP found. Please send OTP first.']);
        exit;
    }
    if (time() > $stored['expires']) {
        unset($_SESSION['otp_'.$type]);
        echo json_encode(['success'=>false,'message'=>'OTP expired. Please request a new one.']);
        exit;
    }
    if ($stored['value'] !== $value) {
        echo json_encode(['success'=>false,'message'=>'OTP mismatch. Please resend.']);
        exit;
    }
    if ($stored['otp'] !== $otp) {
        echo json_encode(['success'=>false,'message'=>'Incorrect OTP. Please try again.']);
        exit;
    }

    // ✅ Verified
    $_SESSION['otp_'.$type]['verified'] = true;
    $_SESSION[$type.'_otp_verified']    = true;

    echo json_encode(['success'=>true,'message'=>ucfirst($type).' verified successfully!']);
    exit;
}
?>
<?php
require_once __DIR__ . '/config.php';
session_start();
header('Content-Type: application/json');

define('OTP_EXPIRY_SECONDS', 300);

$body   = json_decode(file_get_contents('php://input'), true);
$action = $body['action'] ?? '';
$email  = trim($body['email'] ?? '');
$otp    = trim($body['otp']   ?? '');

if (!in_array($action, ['send','verify'])) {
    echo json_encode(['success'=>false,'message'=>'Invalid request.']); exit;
}

if ($action === 'send') {
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        echo json_encode(['success'=>false,'message'=>'Invalid email address.']); exit;
    }

    try {
        $pdo = get_db_connection();
        $chk = $pdo->prepare("SELECT id,name FROM users WHERE email=? LIMIT 1");
        $chk->execute([$email]);
        $user = $chk->fetch();
        if (!$user) {
            echo json_encode(['success'=>false,'message'=>'No account found with this email.']); exit;
        }
    } catch(Exception $e) {
        echo json_encode(['success'=>false,'message'=>'Database error.']); exit;
    }

    $generatedOtp = str_pad(random_int(100000,999999), 6, '0', STR_PAD_LEFT);
    $_SESSION['forgot_otp'] = [
        'otp'     => $generatedOtp,
        'email'   => $email,
        'expires' => time() + OTP_EXPIRY_SECONDS,
    ];

    // DEMO MODE — swap for real email later
    echo json_encode([
        'success'  => true,
        'message'  => 'OTP sent to your email.',
        'demo_otp' => $generatedOtp,
    ]);
    exit;
}

if ($action === 'verify') {
    $stored = $_SESSION['forgot_otp'] ?? null;
    if (!$stored) {
        echo json_encode(['success'=>false,'message'=>'No OTP found. Please request a new one.']); exit;
    }
    if (time() > $stored['expires']) {
        unset($_SESSION['forgot_otp']);
        echo json_encode(['success'=>false,'message'=>'OTP expired. Please request a new one.']); exit;
    }
    if ($stored['email'] !== $email) {
        echo json_encode(['success'=>false,'message'=>'Email mismatch.']); exit;
    }
    if ($stored['otp'] !== $otp) {
        echo json_encode(['success'=>false,'message'=>'Incorrect OTP. Please try again.']); exit;
    }

    $_SESSION['forgot_otp_verified'] = true;
    $_SESSION['forgot_email']        = $email;
    echo json_encode(['success'=>true,'message'=>'OTP verified!']);
    exit;
}
?>
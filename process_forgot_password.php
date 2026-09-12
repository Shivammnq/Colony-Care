<?php
require_once __DIR__ . '/config.php';
session_start();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: /forgot-password.php'); exit;
}

if (empty($_SESSION['forgot_otp_verified']) || empty($_SESSION['forgot_email'])) {
    $_SESSION['fp_error'] = 'Session expired. Please start over.';
    header('Location: /forgot-password.php'); exit;
}

$email    = $_SESSION['forgot_email'];
$password = $_POST['new_password']     ?? '';
$confirm  = $_POST['confirm_password'] ?? '';

if (strlen($password) < 8) {
    $_SESSION['fp_error'] = 'Password must be at least 8 characters.';
    header('Location: /forgot-password.php?step=3'); exit;
}
if ($password !== $confirm) {
    $_SESSION['fp_error'] = 'Passwords do not match.';
    header('Location: /forgot-password.php?step=3'); exit;
}

try {
    $pdo = get_db_connection();
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $stmt = $pdo->prepare("UPDATE users SET password=? WHERE email=?");
    $stmt->execute([$hash, $email]);

    if ($stmt->rowCount() === 0) {
        $_SESSION['fp_error'] = 'Account not found. Please try again.';
        header('Location: /forgot-password.php'); exit;
    }

    unset($_SESSION['forgot_otp'], $_SESSION['forgot_otp_verified'], $_SESSION['forgot_email']);
    $_SESSION['login_success'] = '✅ Password reset successfully! Please login with your new password.';
    header('Location: /login.php'); exit;

} catch(Exception $e) {
    $_SESSION['fp_error'] = 'Database error. Please try again.';
    header('Location: /forgot-password.php?step=3'); exit;
}
?>
<?php
require_once __DIR__ . '/config.php';
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { header("Location: /register.php"); exit; }

// ── Check OTP verification ──────────────────────────────────
if (empty($_SESSION['res_email_otp_verified']) || empty($_SESSION['res_phone_otp_verified'])) {
    $_SESSION['reg_errors'] = ["Please verify both your email and phone OTP before registering."];
    $_SESSION['reg_old']    = $_POST;
    header("Location: /register.php"); exit;
}
if (($_POST['email_verified'] ?? '0') !== '1' || ($_POST['phone_verified'] ?? '0') !== '1') {
    $_SESSION['reg_errors'] = ["OTP verification incomplete."];
    $_SESSION['reg_old']    = $_POST;
    header("Location: /register.php"); exit;
}

function clean($v){ return htmlspecialchars(strip_tags(trim($v))); }

$society_id = intval($_POST['society_id'] ?? 0);
$role       = clean($_POST['role']      ?? 'resident');
$full_name  = clean($_POST['full_name'] ?? '');
$phone      = clean($_POST['phone']     ?? '');
$email      = clean($_POST['email']     ?? '');
$password   = $_POST['password']        ?? '';
$block      = clean($_POST['block']     ?? '');
$unit       = clean($_POST['unit']      ?? '');

$allowed_roles = ['resident','staff','accountant','vendor','society_member'];
if (!in_array($role, $allowed_roles)) $role = 'resident';

// ── Validate ───────────────────────────────────────────────
$errors = [];
if (!$society_id)          $errors[] = "Please select a society.";
if (empty($full_name))     $errors[] = "Full name is required.";
if (empty($phone))         $errors[] = "Phone is required.";
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email.";
if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";

if (!empty($errors)) {
    $_SESSION['reg_errors'] = $errors;
    $_SESSION['reg_old']    = $_POST;
    header("Location: /register.php"); exit;
}

try {
    $pdo = get_db_connection();
} catch (PDOException $e) {
    $_SESSION['reg_errors'] = ["Database connection failed: " . $e->getMessage()];
    header("Location: /register.php"); exit;
}

// ── Auto-add approval columns if missing ───────────────────
foreach ([
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS society_id INT UNSIGNED DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS approved_by   VARCHAR(150) DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS approved_role VARCHAR(20)  DEFAULT NULL",
    "ALTER TABLE users ADD COLUMN IF NOT EXISTS approved_at   DATETIME     DEFAULT NULL",
] as $sql) { try { $pdo->exec($sql); } catch(Exception $e){} }

// ── Get society name from id ────────────────────────────────
$socStmt = $pdo->prepare("SELECT society_name FROM societies WHERE id = ? LIMIT 1");
$socStmt->execute([$society_id]);
$soc = $socStmt->fetch();
if (!$soc) {
    $_SESSION['reg_errors'] = ["Selected society not found."];
    $_SESSION['reg_old']    = $_POST;
    header("Location: /register.php"); exit;
}
$society_name = $soc['society_name'];

// ── Check duplicate email ──────────────────────────────────
$chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$chk->execute([$email]);
if ($chk->fetch()) {
    $_SESSION['reg_errors'] = ["An account with this email already exists. Please login."];
    $_SESSION['reg_old']    = $_POST;
    header("Location: /register.php"); exit;
}

$hashed = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo->prepare("
        INSERT INTO users
            (name, email, phone, password, role, society, society_id, unit, block, is_active, created_at)
        VALUES
            (?, ?, ?, ?, ?, ?, ?, ?, ?, 0, NOW())
    ")->execute([
        $full_name, $email, $phone, $hashed, $role,
        $society_name, $society_id, $unit, $block
    ]);

    // Clear OTP sessions
    unset($_SESSION['res_otp_email'], $_SESSION['res_otp_phone'],
          $_SESSION['res_email_otp_verified'], $_SESSION['res_phone_otp_verified'],
          $_SESSION['reg_errors'], $_SESSION['reg_old']);

    $_SESSION['login_success'] = "✅ Registration submitted! Your request is pending approval from the society owner/admin. You'll be able to login once approved.";
    header("Location: /login.php"); exit;

} catch (Exception $e) {
    $_SESSION['reg_errors'] = ["Registration failed: " . $e->getMessage()];
    $_SESSION['reg_old']    = $_POST;
    header("Location: /Shivam/register.php"); exit;
}
?>
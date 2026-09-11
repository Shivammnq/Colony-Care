<?php
session_start();
ini_set('display_errors', 1);
error_reporting(E_ALL);

// ── Only POST allowed ──────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /shivam/register-society.php"); exit;
}

// ── Check both OTPs are verified in session ────────────────
$email_ok = !empty($_SESSION['email_otp_verified']) || !empty($_SESSION['res_email_otp_verified']);
$phone_ok = !empty($_SESSION['phone_otp_verified']) || !empty($_SESSION['res_phone_otp_verified']);
if (!$email_ok || !$phone_ok) {
    $_SESSION['rs_errors'] = ["Please verify both your email and phone OTP before registering."];
    $_SESSION['rs_old']    = $_POST;
    header("Location: /shivam/register-society.php"); exit;
}

if (($_POST['email_verified'] ?? '0') !== '1' || ($_POST['phone_verified'] ?? '0') !== '1') {
    $_SESSION['rs_errors'] = ["OTP verification incomplete. Please verify both email and phone."];
    $_SESSION['rs_old']    = $_POST;
    header("Location: /shivam/register-society.php"); exit;
}

function clean($v){ return htmlspecialchars(strip_tags(trim($v))); }

$full_name        = clean($_POST['full_name']        ?? '');
$phone            = clean($_POST['phone']            ?? '');
$email            = clean($_POST['email']            ?? '');
$password         = $_POST['password']               ?? '';
$society_name     = clean($_POST['society_name']     ?? '');
$address          = clean($_POST['address']          ?? '');
$city             = clean($_POST['city']             ?? '');
$state            = clean($_POST['state']            ?? '');
$pincode          = clean($_POST['pincode']          ?? '');
$total_flats      = intval($_POST['total_flats']     ?? 0);
$established_year = intval($_POST['established_year']?? 0);
$description      = clean($_POST['description']      ?? '');

$errors = [];
if (empty($full_name))    $errors[] = "Full name is required.";
if (empty($phone))        $errors[] = "Phone is required.";
if (!filter_var($email, FILTER_VALIDATE_EMAIL)) $errors[] = "Invalid email.";
if (strlen($password) < 8) $errors[] = "Password must be at least 8 characters.";
if (empty($society_name)) $errors[] = "Society name is required.";
if (empty($address))      $errors[] = "Address is required.";
if (empty($city))         $errors[] = "City is required.";
if (empty($state))        $errors[] = "State is required.";
if (!preg_match('/^[0-9]{6}$/', $pincode)) $errors[] = "Pincode must be 6 digits.";

if (!empty($errors)) {
    $_SESSION['rs_errors'] = $errors;
    $_SESSION['rs_old']    = $_POST;
    header("Location: /shivam/register-society.php"); exit;
}

try {
    $pdo = new PDO("mysql:host=localhost;dbname=cc;charset=utf8mb4", "root", "", [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
} catch (PDOException $e) {
    $_SESSION['rs_errors'] = ["Database connection failed: " . $e->getMessage()];
    header("Location: /shivam/register-society.php"); exit;
}

$chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
$chk->execute([$email]);
if ($chk->fetch()) {
    $_SESSION['rs_errors'] = ["An account with this email already exists. Please login."];
    $_SESSION['rs_old']    = $_POST;
    header("Location: /shivam/register-society.php"); exit;
}

$chkS = $pdo->prepare("SELECT id FROM societies WHERE society_name = ? LIMIT 1");
$chkS->execute([$society_name]);
if ($chkS->fetch()) {
    $_SESSION['rs_errors'] = ["A society with this name is already registered."];
    $_SESSION['rs_old']    = $_POST;
    header("Location: /shivam/register-society.php"); exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);

try {
    $pdo->beginTransaction();

    // ── Ensure the status column exists (new societies always start pending) ──
    $hasStatusCol = $pdo->query("SHOW COLUMNS FROM societies LIKE 'status'")->fetch();
    if (!$hasStatusCol) {
        $pdo->exec("ALTER TABLE societies ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'approved'");
    }

    $pdo->prepare("INSERT INTO societies (society_name,address,city,state,pincode,total_flats,established_year,description,status,created_at) VALUES (?,?,?,?,?,?,?,?,'pending',NOW())")
        ->execute([$society_name, $address, $city, $state, $pincode, $total_flats?:null, $established_year?:null, $description]);
    $society_id = $pdo->lastInsertId();

    $pdo->prepare("INSERT INTO users (name,email,phone,password,role,society,society_id,is_active,created_at) VALUES (?,?,?,?,'admin',?,?,1,NOW())")
        ->execute([$full_name, $email, $phone, $hash, $society_name, $society_id]);
    $owner_user_id = $pdo->lastInsertId();

    $pdo->prepare("UPDATE societies SET owner_id = ? WHERE id = ?")
        ->execute([$owner_user_id, $society_id]);

    $pdo->commit();

    // ── Notify every Super Admin that a new society is awaiting approval ──
    try {
        require_once __DIR__ . '/notify_helper.php';
        $superAdminIds = $pdo->query("SELECT id FROM users WHERE role='super_admin'")->fetchAll(PDO::FETCH_COLUMN);
        if ($superAdminIds) {
            notify($pdo, $society_id, $superAdminIds, $owner_user_id, 'approval',
                'New society registered and awaiting approval: "' . $society_name . '" (' . $city . ', ' . $state . ')',
                '/shivam/super_admin_societies.php'
            );
        }
    } catch (Exception $notifyEx) { /* notification failure shouldn't block registration */ }

    unset($_SESSION['otp_email'], $_SESSION['otp_phone'],
          $_SESSION['email_otp_verified'], $_SESSION['phone_otp_verified'],
          $_SESSION['rs_errors'], $_SESSION['rs_old']);

    $_SESSION['login_success'] = "✅ Society '{$society_name}' submitted! It's now awaiting Super Admin approval — you'll be notified once it's live.";
    header("Location: /shivam/login.php"); exit;

} catch (Exception $e) {
    $pdo->rollBack();
    $_SESSION['rs_errors'] = ["Registration failed: " . $e->getMessage()];
    $_SESSION['rs_old']    = $_POST;
    header("Location: /shivam/register-society.php"); exit;
}
?>
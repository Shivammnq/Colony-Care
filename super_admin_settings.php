<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /shivam/login.php?redirect=' . urlencode('/shivam/super_admin_settings.php')); exit;
}

require __DIR__ . '/session_guard.php';

define('DB_HOST', 'localhost');
define('DB_NAME', 'cc');
define('DB_USER', 'root');
define('DB_PASS', '');

function detectDeviceLabel($ua) {
    $ua = $ua ?: '';
    if (preg_match('/Windows/i', $ua)) $os = 'Windows';
    elseif (preg_match('/Mac OS/i', $ua)) $os = 'macOS';
    elseif (preg_match('/Android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iPhone|iPad/i', $ua)) $os = 'iOS';
    elseif (preg_match('/Linux/i', $ua)) $os = 'Linux';
    else $os = 'Unknown OS';

    if (preg_match('/Edg\//i', $ua)) $browser = 'Edge';
    elseif (preg_match('/Chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/Firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/Safari/i', $ua)) $browser = 'Safari';
    else $browser = 'Unknown browser';

    return "$browser on $os";
}

$msg = $_SESSION['sa_flash_msg'] ?? '';
$err = $_SESSION['sa_flash_err'] ?? '';
unset($_SESSION['sa_flash_msg'], $_SESSION['sa_flash_err']);
$pageTitle = 'Settings';
$activeNav = 'settings';
$settings = ['company_name'=>'', 'support_email'=>'', 'support_phone'=>'', 'website'=>''];
$user = ['name'=>'', 'email'=>'', 'phone'=>''];
$devices = [];
$userId = $_SESSION['user_id'];

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // ── Single-row platform settings table ────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS platform_settings (
        id INT PRIMARY KEY DEFAULT 1,
        company_name VARCHAR(150) DEFAULT '',
        support_email VARCHAR(150) DEFAULT '',
        support_phone VARCHAR(30) DEFAULT '',
        website VARCHAR(200) DEFAULT '',
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $rowExists = $pdo->query("SELECT COUNT(*) FROM platform_settings WHERE id=1")->fetchColumn();
    if (!$rowExists) {
        $pdo->exec("INSERT INTO platform_settings (id, company_name) VALUES (1, 'ColonyCare')");
    }

    // ── Device / session tracking table ──────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS user_devices (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        session_id VARCHAR(128) NOT NULL,
        device_label VARCHAR(150) DEFAULT '',
        ip_address VARCHAR(45) DEFAULT '',
        user_agent VARCHAR(255) DEFAULT '',
        last_active DATETIME DEFAULT CURRENT_TIMESTAMP,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uniq_user_session (user_id, session_id),
        KEY idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Register/refresh the CURRENT device on every page load
    $currentSid = session_id();
    $ip = $_SERVER['REMOTE_ADDR'] ?? '';
    $ua = $_SERVER['HTTP_USER_AGENT'] ?? '';
    $label = detectDeviceLabel($ua);

    $pdo->prepare("INSERT INTO user_devices (user_id, session_id, device_label, ip_address, user_agent, last_active)
                    VALUES (?, ?, ?, ?, ?, NOW())
                    ON DUPLICATE KEY UPDATE last_active = NOW(), ip_address = VALUES(ip_address), user_agent = VALUES(user_agent)")
        ->execute([$userId, $currentSid, $label, $ip, $ua]);

    // ── POST handlers ────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // 1. Personal details
        if (isset($_POST['save_personal_details'])) {
            $name  = trim($_POST['name']  ?? '');
            $phone = trim($_POST['phone'] ?? '');

            if (empty($name)) {
                $err = 'Name is required.';
            } else {
                $pdo->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?")
                    ->execute([$name, $phone, $userId]);
                $_SESSION['user_name'] = $name;
                $msg = 'Personal details updated.';
            }
        }

        // 2. Email
        elseif (isset($_POST['save_email'])) {
            $newEmail = trim($_POST['email'] ?? '');

            if (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
                $err = 'Please enter a valid email address.';
            } else {
                $dupe = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                $dupe->execute([$newEmail, $userId]);
                if ($dupe->fetchColumn() > 0) {
                    $err = 'That email is already in use by another account.';
                } else {
                    $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$newEmail, $userId]);
                    $msg = 'Email updated.';
                }
            }
        }

        // 3. Password change (current password required)
        elseif (isset($_POST['change_password'])) {
            $current = $_POST['current_password'] ?? '';
            $new     = $_POST['new_password'] ?? '';
            $confirm = $_POST['confirm_password'] ?? '';

            $stmt = $pdo->prepare("SELECT password FROM users WHERE id = ?");
            $stmt->execute([$userId]);
            $hash = $stmt->fetchColumn();

            if (!$hash || !password_verify($current, $hash)) {
                $err = 'Current password is incorrect.';
            } elseif (strlen($new) < 8) {
                $err = 'New password must be at least 8 characters.';
            } elseif ($new !== $confirm) {
                $err = 'New password and confirmation do not match.';
            } else {
                $newHash = password_hash($new, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $userId]);
                $msg = 'Password changed successfully.';
            }
        }

        // 4. Log out of all other devices (bumps token_version)
        elseif (isset($_POST['logout_other_devices'])) {
            $pdo->prepare("UPDATE users SET token_version = token_version + 1 WHERE id = ?")
                ->execute([$userId]);

            $verStmt = $pdo->prepare("SELECT token_version FROM users WHERE id = ?");
            $verStmt->execute([$userId]);
            $_SESSION['token_version'] = (int)$verStmt->fetchColumn();

            // Other tracked sessions are now dead — clear them from the visible list
            $pdo->prepare("DELETE FROM user_devices WHERE user_id = ? AND session_id != ?")
                ->execute([$userId, $currentSid]);

            $msg = 'Logged out of all other devices.';
        }

        // 5. Logout (current session)
        elseif (isset($_POST['logout'])) {
            $pdo->prepare("DELETE FROM user_devices WHERE user_id = ? AND session_id = ?")
                ->execute([$userId, $currentSid]);
            $_SESSION = [];
            session_destroy();
            header('Location: /shivam/login.php'); exit;
        }

        if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
        if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
        header('Location: /shivam/super_admin_settings.php'); exit;
    }

    $settings = $pdo->query("SELECT * FROM platform_settings WHERE id=1")->fetch();

    $stmt = $pdo->prepare("SELECT name, email, phone FROM users WHERE id = ?");
    $stmt->execute([$userId]);
    $user = $stmt->fetch() ?: $user;

    $stmt = $pdo->prepare("SELECT * FROM user_devices WHERE user_id = ? ORDER BY last_active DESC");
    $stmt->execute([$userId]);
    $devices = $stmt->fetchAll();

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
}

include __DIR__ . '/super_admin_header.php';
?>

<h1 style="font-size:1.4rem;font-weight:700;">Settings</h1>
<p style="color:var(--text-muted);font-size:.87rem;margin-bottom:22px;">Personal details, security and devices</p>

<?php if ($msg): ?>
<div style="max-width:600px;background:#e8f8ee;color:#1a7f4a;border:1px solid #b6ecc9;border-radius:9px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div style="max-width:600px;background:#fdecea;color:#b3261e;border:1px solid #f6c6c2;border-radius:9px;padding:10px 14px;margin-bottom:16px;font-size:.85rem;"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<!-- Personal details -->
<div style="max-width:600px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
        <h3 style="font-size:.95rem;font-weight:700;">Personal details</h3>
    </div>
    <form method="POST" style="padding:20px;">
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Full name</label>
            <input type="text" name="name" value="<?= htmlspecialchars($user['name'] ?? '') ?>" required
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
        </div>
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Phone</label>
            <input type="text" name="phone" value="<?= htmlspecialchars($user['phone'] ?? '') ?>" placeholder="+91 98200 00000"
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
        </div>
        <button type="submit" name="save_personal_details" value="1"
            style="background:var(--green-btn);color:#fff;border:none;padding:11px 22px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;">
            Save changes
        </button>
    </form>
</div>

<!-- Email -->
<div style="max-width:600px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
        <h3 style="font-size:.95rem;font-weight:700;">Email</h3>
    </div>
    <form method="POST" style="padding:20px;">
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Login email</label>
            <input type="email" name="email" value="<?= htmlspecialchars($user['email'] ?? '') ?>" required
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
        </div>
        <button type="submit" name="save_email" value="1"
            style="background:var(--green-btn);color:#fff;border:none;padding:11px 22px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;">
            Update email
        </button>
    </form>
</div>

<!-- Password management -->
<div style="max-width:600px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
        <h3 style="font-size:.95rem;font-weight:700;">Password management</h3>
    </div>
    <form method="POST" style="padding:20px;">
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Current password</label>
            <input type="password" name="current_password" required
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
        </div>
        <div style="margin-bottom:16px;">
            <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">New password</label>
            <input type="password" name="new_password" required minlength="8"
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
        </div>
        <div style="margin-bottom:20px;">
            <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Confirm new password</label>
            <input type="password" name="confirm_password" required minlength="8"
                style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
        </div>
        <button type="submit" name="change_password" value="1"
            style="background:var(--green-btn);color:#fff;border:none;padding:11px 22px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;">
            Change password
        </button>
    </form>
</div>

<!-- Device list -->
<div style="max-width:600px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;">
    <div style="padding:16px 20px;border-bottom:1px solid var(--border);">
        <h3 style="font-size:.95rem;font-weight:700;">Device list</h3>
        <p style="color:var(--text-muted);font-size:.78rem;margin-top:4px;">Only tracks devices that have opened this Settings page while logged in.</p>
    </div>
    <div style="padding:0 20px 4px;">
        <?php foreach ($devices as $d): ?>
        <div style="padding:12px 0;border-bottom:1px solid var(--border);">
            <div style="font-size:.85rem;font-weight:600;">
                <?= htmlspecialchars($d['device_label']) ?>
                <?php if ($d['session_id'] === $currentSid): ?>
                    <span style="color:#1a7f4a;font-weight:700;font-size:.72rem;"> · this device</span>
                <?php endif; ?>
            </div>
            <div style="color:var(--text-muted);font-size:.75rem;margin-top:2px;">
                <?= htmlspecialchars($d['ip_address']) ?> · last active <?= htmlspecialchars($d['last_active']) ?>
            </div>
        </div>
        <?php endforeach; ?>
        <?php if (empty($devices)): ?>
        <p style="color:var(--text-muted);font-size:.83rem;padding:12px 0;">No devices recorded yet.</p>
        <?php endif; ?>
    </div>
    <?php if (count($devices) > 1): ?>
    <div style="padding:12px 20px 20px;">
        <form method="POST" onsubmit="return confirm('This immediately logs out every device except this one. Continue?');">
            <button type="submit" name="logout_other_devices" value="1"
                style="background:none;border:1.5px solid #f6c6c2;color:#b3261e;padding:9px 16px;border-radius:9px;font-family:inherit;font-size:.83rem;font-weight:700;cursor:pointer;">
                Log out of all other devices
            </button>
        </form>
    </div>
    <?php endif; ?>
</div>

<!-- Logout -->
<div style="max-width:600px;background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;margin-bottom:20px;">
    <div style="padding:16px 20px;">
        <h3 style="font-size:.95rem;font-weight:700;margin-bottom:12px;">Logout</h3>
        <form method="POST" onsubmit="return confirm('Log out of this session?');">
            <button type="submit" name="logout" value="1"
                style="background:#b3261e;color:#fff;border:none;padding:11px 22px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;">
                Log out
            </button>
        </form>
    </div>
</div>

<?php include __DIR__ . '/super_admin_footer.php'; ?>
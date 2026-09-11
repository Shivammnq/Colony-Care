<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /shivam/login.php?redirect=' . urlencode('/shivam/super_admin_owners.php')); exit;
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'cc');
define('DB_USER', 'root');
define('DB_PASS', '');

$msg = $_SESSION['sa_flash_msg'] ?? '';
$err = $_SESSION['sa_flash_err'] ?? '';
unset($_SESSION['sa_flash_msg'], $_SESSION['sa_flash_err']);
$pageTitle = 'Society Owners';
$activeNav = 'owners';
$owners_list = [];

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_owner'])) {
        $uid   = (int)$_POST['user_id'];
        $name  = trim($_POST['name']  ?? '');
        $email = trim($_POST['email'] ?? '');
        $phone = trim($_POST['phone'] ?? '');

        if (empty($name) || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $err = 'A valid name and email are required.';
        } else {
            $chk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id!=?");
            $chk->execute([$email, $uid]);
            if ($chk->fetch()) {
                $err = 'Another account already uses this email.';
            } else {
                $pdo->prepare("UPDATE users SET name=?, email=?, phone=? WHERE id=?")->execute([$name, $email, $phone, $uid]);
                $msg = 'Owner details updated.';
            }
        }
        if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
        if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
        header('Location: /shivam/super_admin_owners.php'); exit;
    }

    // Deleting an owner means deleting their society (owner + society are tightly coupled;
    // users.society_id has ON DELETE CASCADE, so removing the society removes the owner's account too).
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_owner_society'])) {
        $sid = (int)$_POST['society_id'];
        $pdo->beginTransaction();
        try {
            $listingIds = $pdo->prepare("SELECT id FROM listings WHERE society_id=?");
            $listingIds->execute([$sid]);
            $listingIds = $listingIds->fetchAll(PDO::FETCH_COLUMN);
            if ($listingIds) {
                $ph = implode(',', array_fill(0, count($listingIds), '?'));
                $pdo->prepare("DELETE FROM listing_photos WHERE listing_id IN ($ph)")->execute($listingIds);
                $pdo->prepare("DELETE FROM listing_messages WHERE listing_id IN ($ph)")->execute($listingIds);
                $pdo->prepare("DELETE FROM listing_reports WHERE listing_id IN ($ph)")->execute($listingIds);
            }
            $pdo->prepare("DELETE FROM listings WHERE society_id=?")->execute([$sid]);
            foreach (['complaints','billing','facility_bookings','amenities','saved_listings'] as $tbl) {
                try { $pdo->prepare("DELETE FROM $tbl WHERE society_id=?")->execute([$sid]); } catch (PDOException $e) {}
            }
            try { $pdo->prepare("DELETE FROM notifications WHERE society_id=?")->execute([$sid]); } catch (PDOException $e) {}
            $pdo->prepare("DELETE FROM societies WHERE id=?")->execute([$sid]);
            $pdo->commit();
            $msg = 'Owner, their society, and all related data permanently deleted.';
        } catch (Exception $delEx) {
            $pdo->rollBack();
            $err = 'Could not delete: ' . $delEx->getMessage();
        }
        if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
        if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
        header('Location: /shivam/super_admin_owners.php'); exit;
    }

    // An "owner" is any user referenced by societies.owner_id
    $owners_list = $pdo->query("
        SELECT u.id, u.name, u.email, u.phone, u.is_active, u.created_at,
            s.id AS society_id, s.society_name, s.status AS society_status, s.city, s.state
        FROM societies s
        JOIN users u ON u.id = s.owner_id
        ORDER BY u.name ASC
    ")->fetchAll();

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
}

include __DIR__ . '/super_admin_header.php';
?>

<h1 style="font-size:1.4rem;font-weight:700;margin-bottom:20px;">Society Owners</h1>

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;">
<?php if (empty($owners_list)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--text-muted);"><i class="fa fa-user-shield" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--border);"></i>No society owners yet.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;">
<tr style="border-bottom:1px solid var(--border);">
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Owner</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Contact</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Owns</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Society Status</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Account</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Actions</th>
</tr>
<?php foreach ($owners_list as $o):
    $badgeColors = ['approved'=>'#dcfce7;color:#166534','pending'=>'#fef9c3;color:#92400e','rejected'=>'#fee2e2;color:#dc2626'];
?>
<tr style="border-bottom:1px solid var(--border);">
    <td style="padding:12px 16px;font-size:.85rem;font-weight:600;"><?= htmlspecialchars($o['name']) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars($o['email']) ?><br><span style="color:var(--text-muted);font-size:.76rem;"><?= htmlspecialchars($o['phone'] ?? '') ?></span></td>
    <td style="padding:12px 16px;font-size:.85rem;">
        <a href="/shivam/super_admin_society.php?id=<?= $o['society_id'] ?>" style="color:var(--green-dark);text-decoration:none;font-weight:600;"><?= htmlspecialchars($o['society_name']) ?></a><br>
        <span style="color:var(--text-muted);font-size:.76rem;"><?= htmlspecialchars(($o['city']??'').', '.($o['state']??'')) ?></span>
    </td>
    <td style="padding:12px 16px;"><span style="background:<?= $badgeColors[$o['society_status']] ?? '#e5e7eb;color:#374151' ?>;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;text-transform:capitalize;"><?= ucfirst($o['society_status']) ?></span></td>
    <td style="padding:12px 16px;"><span style="background:<?= $o['is_active'] ? '#dcfce7;color:#166534' : '#fee2e2;color:#dc2626' ?>;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;"><?= $o['is_active'] ? 'Active' : 'Inactive' ?></span></td>
    <td style="padding:12px 16px;">
        <button onclick='openEditOwner(<?= json_encode(["id"=>$o["id"],"name"=>$o["name"],"email"=>$o["email"],"phone"=>$o["phone"]]) ?>)' style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;">Edit</button>
        <form method="POST" style="display:inline;" onsubmit="return confirm('PERMANENTLY delete <?= htmlspecialchars(addslashes($o['name'])) ?> and their society \'<?= htmlspecialchars(addslashes($o['society_name'])) ?>\'? This removes the society, the owner\'s account, all residents/admins, listings, bills, and complaints. Cannot be undone.');">
            <input type="hidden" name="society_id" value="<?= $o['society_id'] ?>">
            <input type="hidden" name="delete_owner_society" value="1">
            <button type="submit" style="background:#7f1d1d;color:#fff;border:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;"><i class="fa fa-trash"></i> Delete</button>
        </form>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<!-- ══ EDIT OWNER MODAL ══ -->
<div id="editOwnerOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;align-items:center;justify-content:center;padding:20px;">
    <div style="background:#fff;border-radius:16px;max-width:440px;width:100%;padding:26px;">
        <h2 style="font-size:1.1rem;font-weight:700;margin-bottom:16px;">Edit Owner</h2>
        <form method="POST">
            <input type="hidden" name="user_id" id="eo_id" value="">
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Full Name *</label>
                <input type="text" name="name" id="eo_name" required style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
            </div>
            <div style="margin-bottom:14px;">
                <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Email *</label>
                <input type="email" name="email" id="eo_email" required style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
            </div>
            <div style="margin-bottom:18px;">
                <label style="display:block;font-size:.83rem;font-weight:600;margin-bottom:6px;">Phone</label>
                <input type="text" name="phone" id="eo_phone" style="width:100%;padding:10px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.87rem;outline:none;">
            </div>
            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeEditOwner()" style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:10px 18px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" name="edit_owner" value="1" style="background:var(--green-btn);color:#fff;border:none;padding:10px 20px;border-radius:9px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function openEditOwner(o){
    document.getElementById('eo_id').value = o.id;
    document.getElementById('eo_name').value = o.name;
    document.getElementById('eo_email').value = o.email;
    document.getElementById('eo_phone').value = o.phone || '';
    document.getElementById('editOwnerOverlay').style.display = 'flex';
}
function closeEditOwner(){ document.getElementById('editOwnerOverlay').style.display = 'none'; }
</script>

<?php include __DIR__ . '/super_admin_footer.php'; ?>
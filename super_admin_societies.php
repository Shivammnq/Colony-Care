<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /shivam/login.php?redirect=' . urlencode('/shivam/super_admin_societies.php')); exit;
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'cc');
define('DB_USER', 'root');
define('DB_PASS', '');

$user_id = $_SESSION['user_id'];
$msg = $_SESSION['sa_flash_msg'] ?? '';
$err = $_SESSION['sa_flash_err'] ?? '';
unset($_SESSION['sa_flash_msg'], $_SESSION['sa_flash_err']);
$pageTitle = 'Societies';
$activeNav = 'societies';
$societies_list = [];

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    require_once __DIR__ . '/notify_helper.php';

    $hasStatusCol = $pdo->query("SHOW COLUMNS FROM societies LIKE 'status'")->fetch();
    if (!$hasStatusCol) {
        $pdo->exec("ALTER TABLE societies ADD COLUMN status ENUM('pending','approved','rejected') DEFAULT 'approved'");
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['society_action'])) {
        $sid = (int)$_POST['society_id'];
        $action = $_POST['society_action'];

        if ($action === 'delete_permanently') {
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
                // Deleting the society cascades to its users automatically (FK: users.society_id ON DELETE CASCADE)
                $pdo->prepare("DELETE FROM societies WHERE id=?")->execute([$sid]);
                $pdo->commit();
                $msg = 'Society and all its data permanently deleted.';
            } catch (Exception $delEx) {
                $pdo->rollBack();
                $err = 'Could not delete society: ' . $delEx->getMessage();
            }
            if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
            if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
            header('Location: /shivam/super_admin_societies.php'); exit;
        }

        if (in_array($action, ['approve','reject','suspend'])) {
            $newStatus = ['approve'=>'approved','reject'=>'rejected','suspend'=>'rejected'][$action];
            $pdo->prepare("UPDATE societies SET status=? WHERE id=?")->execute([$newStatus, $sid]);
            $ownerStmt = $pdo->prepare("SELECT owner_id, society_name FROM societies WHERE id=?");
            $ownerStmt->execute([$sid]);
            $srow = $ownerStmt->fetch();
            if ($srow && $srow['owner_id']) {
                $label = $newStatus === 'approved' ? 'has been approved! You now have full access.' : 'was not approved. Please contact support.';
                notify($pdo, $sid, (int)$srow['owner_id'], $user_id, 'approval', 'Your society "' . $srow['society_name'] . '" ' . $label, '/shivam/society.php');
            }
            $msg = 'Society status updated.';
            if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
            if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
            header('Location: /shivam/super_admin_societies.php'); exit;
        }
    }

    // ---- NEW: Edit Society Details ----
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['edit_society'])) {
        $sid = (int)$_POST['society_id'];
        $societyName = trim($_POST['society_name'] ?? '');
        $address = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $pincode = trim($_POST['pincode'] ?? '');
        $totalFlats = (int)($_POST['total_flats'] ?? 0);
        $establishedYear = trim($_POST['established_year'] ?? '');

        if ($societyName === '') {
            $err = 'Society name cannot be empty.';
        } else {
            $pdo->prepare("
                UPDATE societies
                SET society_name=?, address=?, city=?, state=?, pincode=?, total_flats=?, established_year=?
                WHERE id=?
            ")->execute([$societyName, $address, $city, $state, $pincode, $totalFlats, $establishedYear, $sid]);
            $msg = 'Society details updated.';
        }

        if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
        if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
        header('Location: /shivam/super_admin_societies.php'); exit;
    }

    $societies_list = $pdo->query("
        SELECT s.*, u.name AS owner_name, u.email AS owner_email,
            (SELECT COUNT(*) FROM users WHERE society_id = s.id) AS member_count
        FROM societies s LEFT JOIN users u ON u.id = s.owner_id
        ORDER BY FIELD(s.status,'pending','approved','rejected'), s.id DESC
    ")->fetchAll();

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
}

include __DIR__ . '/super_admin_header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:20px;flex-wrap:wrap;gap:12px;">
    <h1 style="font-size:1.4rem;font-weight:700;">Societies</h1>
</div>

<?php if ($msg): ?>
<div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:9px;margin-bottom:16px;font-size:.85rem;"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div style="background:#fee2e2;color:#dc2626;padding:12px 16px;border-radius:9px;margin-bottom:16px;font-size:.85rem;"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;">
<?php if (empty($societies_list)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--text-muted);"><i class="fa fa-city" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--border);"></i>No societies registered yet.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;">
<tr style="border-bottom:1px solid var(--border);">
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Society</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Owner</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Location</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Members</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Status</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Actions</th>
</tr>
<?php foreach ($societies_list as $s):
    $badgeColors = ['approved'=>'#dcfce7;color:#166534','pending'=>'#fef9c3;color:#92400e','rejected'=>'#fee2e2;color:#dc2626'];
?>
<tr style="border-bottom:1px solid var(--border);">
    <td style="padding:12px 16px;font-size:.85rem;">
        <a href="/shivam/super_admin_society.php?id=<?= $s['id'] ?>" style="color:var(--green-dark);font-weight:700;text-decoration:none;"><?= htmlspecialchars($s['society_name']) ?></a><br>
        <span style="color:var(--text-muted);font-size:.76rem;">ID #<?= $s['id'] ?></span>
    </td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars($s['owner_name'] ?? '—') ?><br><span style="color:var(--text-muted);font-size:.76rem;"><?= htmlspecialchars($s['owner_email'] ?? '') ?></span></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars(($s['city']??'').', '.($s['state']??'')) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= (int)$s['member_count'] ?></td>
    <td style="padding:12px 16px;"><span style="background:<?= $badgeColors[$s['status']] ?>;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;text-transform:capitalize;"><?= ucfirst($s['status']) ?></span></td>
    <td style="padding:12px 16px;white-space:nowrap;">
        <a href="/shivam/super_admin_society.php?id=<?= $s['id'] ?>" style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);text-decoration:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;display:inline-block;"><i class="fa fa-gear"></i> Manage</a>

        <button type="button" class="edit-society-btn"
            data-id="<?= (int)$s['id'] ?>"
            data-name="<?= htmlspecialchars($s['society_name'] ?? '', ENT_QUOTES) ?>"
            data-address="<?= htmlspecialchars($s['address'] ?? '', ENT_QUOTES) ?>"
            data-city="<?= htmlspecialchars($s['city'] ?? '', ENT_QUOTES) ?>"
            data-state="<?= htmlspecialchars($s['state'] ?? '', ENT_QUOTES) ?>"
            data-pincode="<?= htmlspecialchars($s['pincode'] ?? '', ENT_QUOTES) ?>"
            data-flats="<?= (int)($s['total_flats'] ?? 0) ?>"
            data-year="<?= htmlspecialchars($s['established_year'] ?? '', ENT_QUOTES) ?>"
            style="background:#eff6ff;color:#2563eb;border:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;">
            <i class="fa fa-pen"></i> Edit
        </button>

        <?php if ($s['status'] !== 'approved'): ?>
        <form method="POST" style="display:inline;"><input type="hidden" name="society_id" value="<?= $s['id'] ?>"><input type="hidden" name="society_action" value="approve"><button style="background:#dcfce7;color:#166534;border:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;" type="submit">Approve</button></form>
        <?php endif; ?>
        <?php if ($s['status'] !== 'rejected'): ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Suspend this society?');"><input type="hidden" name="society_id" value="<?= $s['id'] ?>"><input type="hidden" name="society_action" value="suspend"><button style="background:#fee2e2;color:#dc2626;border:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;" type="submit">Suspend</button></form>
        <?php endif; ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('PERMANENTLY delete &quot;<?= htmlspecialchars(addslashes($s['society_name'])) ?>&quot;? This removes the society, all its residents/admins, listings, bills, and complaints. This cannot be undone.');"><input type="hidden" name="society_id" value="<?= $s['id'] ?>"><input type="hidden" name="society_action" value="delete_permanently"><button style="background:#7f1d1d;color:#fff;border:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;" type="submit"><i class="fa fa-trash"></i> Delete</button></form>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<!-- ---- Edit Society Modal ---- -->
<div id="editSocietyOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:16px;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:480px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.2);">
        <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:16px;">Edit Society Details</h2>
        <form method="POST">
            <input type="hidden" name="edit_society" value="1">
            <input type="hidden" name="society_id" id="edit_society_id">

            <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Society Name</label>
            <input type="text" name="society_name" id="edit_society_name" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">

            <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Address</label>
            <input type="text" name="address" id="edit_address" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">

            <div style="display:flex;gap:10px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">City</label>
                    <input type="text" name="city" id="edit_city" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">State</label>
                    <input type="text" name="state" id="edit_state" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">
                </div>
            </div>

            <div style="display:flex;gap:10px;">
                <div style="flex:1;">
                    <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Pincode</label>
                    <input type="text" name="pincode" id="edit_pincode" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">
                </div>
                <div style="flex:1;">
                    <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Total Flats</label>
                    <input type="number" name="total_flats" id="edit_total_flats" min="0" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">
                </div>
            </div>

            <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Established Year</label>
            <input type="text" name="established_year" id="edit_established_year" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:20px;font-family:inherit;font-size:.85rem;outline:none;">

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" id="cancelEditSocietyBtn" style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:9px 16px;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
var editSocietyOverlay = document.getElementById('editSocietyOverlay');

document.querySelectorAll('.edit-society-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        document.getElementById('edit_society_id').value = this.dataset.id;
        document.getElementById('edit_society_name').value = this.dataset.name;
        document.getElementById('edit_address').value = this.dataset.address;
        document.getElementById('edit_city').value = this.dataset.city;
        document.getElementById('edit_state').value = this.dataset.state;
        document.getElementById('edit_pincode').value = this.dataset.pincode;
        document.getElementById('edit_total_flats').value = this.dataset.flats;
        document.getElementById('edit_established_year').value = this.dataset.year;
        editSocietyOverlay.style.display = 'flex';
    });
});

document.getElementById('cancelEditSocietyBtn').addEventListener('click', function () {
    editSocietyOverlay.style.display = 'none';
});
editSocietyOverlay.addEventListener('click', function (e) {
    if (e.target === editSocietyOverlay) editSocietyOverlay.style.display = 'none';
});
</script>

<?php include __DIR__ . '/super_admin_footer.php'; ?>
<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /login.php?redirect=' . urlencode('/super_admin_residents.php')); exit;
}

// DB constants loaded via config.php

$msg = $_SESSION['sa_flash_msg'] ?? '';
$err = $_SESSION['sa_flash_err'] ?? '';
unset($_SESSION['sa_flash_msg'], $_SESSION['sa_flash_err']);
$pageTitle = 'Residents';
$activeNav = 'residents';
$users_list = [];
$societies_list = [];
$validRoles = ['admin','staff','resident','accountant','vendor','society_member','buyer','super_admin'];

try {
    $pdo = get_db_connection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['toggle_user'])) {
            $uid = (int)$_POST['user_id'];
            $pdo->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id=?")->execute([$uid]);
            $msg = 'User status updated.';
        }

        if (isset($_POST['change_role'])) {
            $uid = (int)$_POST['user_id'];
            $newRole = $_POST['new_role'] ?? '';
            if (in_array($newRole, $validRoles)) {
                $pdo->prepare("UPDATE users SET role=? WHERE id=?")->execute([$newRole, $uid]);
                $msg = 'Role updated.';
            }
        }

        // ---- NEW: Edit resident details ----
        if (isset($_POST['edit_user'])) {
            $uid   = (int)$_POST['user_id'];
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $newRole = $_POST['role'] ?? '';
            $societyId = ($_POST['society_id'] !== '' ) ? (int)$_POST['society_id'] : null;

            if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err = 'Please provide a valid name and email address.';
            } elseif (!in_array($newRole, $validRoles)) {
                $err = 'Invalid role selected.';
            } else {
                $dupChk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>?");
                $dupChk->execute([$email, $uid]);
                if ($dupChk->fetch()) {
                    $err = 'Another user already uses that email address.';
                } else {
                    $pdo->prepare("UPDATE users SET name=?, email=?, role=?, society_id=? WHERE id=?")
                        ->execute([$name, $email, $newRole, $societyId, $uid]);
                    $msg = 'Resident details updated.';
                }
            }
        }

        if (isset($_POST['delete_user'])) {
            $uid = (int)$_POST['user_id'];
            $ownsChk = $pdo->prepare("SELECT id, society_name FROM societies WHERE owner_id=?");
            $ownsChk->execute([$uid]);
            $ownedSociety = $ownsChk->fetch();
            if ($ownedSociety) {
                $err = 'This person owns "' . $ownedSociety['society_name'] . '" — delete that society instead (from the Societies page) to remove them.';
            } else {
                $lids = $pdo->prepare("SELECT id FROM listings WHERE user_id=?");
                $lids->execute([$uid]);
                $lids = $lids->fetchAll(PDO::FETCH_COLUMN);
                if ($lids) {
                    $ph = implode(',', array_fill(0, count($lids), '?'));
                    $pdo->prepare("DELETE FROM listing_photos WHERE listing_id IN ($ph)")->execute($lids);
                    $pdo->prepare("DELETE FROM listing_messages WHERE listing_id IN ($ph)")->execute($lids);
                    $pdo->prepare("DELETE FROM listing_reports WHERE listing_id IN ($ph)")->execute($lids);
                    $pdo->prepare("DELETE FROM listings WHERE user_id=?")->execute([$uid]);
                }
                $pdo->prepare("DELETE FROM users WHERE id=?")->execute([$uid]);
                $msg = 'User deleted.';
            }
        }

        if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
        if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
        header('Location: /super_admin_residents.php'); exit;
    }

    $users_list = $pdo->query("
        SELECT u.*, s.society_name
        FROM users u LEFT JOIN societies s ON s.id = u.society_id
        ORDER BY u.created_at DESC
    ")->fetchAll();

    $societies_list = $pdo->query("SELECT id, society_name FROM societies ORDER BY society_name")->fetchAll();

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
}

include __DIR__ . '/super_admin_header.php';
?>

<h1 style="font-size:1.4rem;font-weight:700;margin-bottom:20px;">Residents</h1>

<?php if ($msg): ?>
<div style="background:#dcfce7;color:#166534;padding:12px 16px;border-radius:9px;margin-bottom:16px;font-size:.85rem;"><?= htmlspecialchars($msg) ?></div>
<?php endif; ?>
<?php if ($err): ?>
<div style="background:#fee2e2;color:#dc2626;padding:12px 16px;border-radius:9px;margin-bottom:16px;font-size:.85rem;"><?= htmlspecialchars($err) ?></div>
<?php endif; ?>

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;">
    <div style="padding:14px 16px;border-bottom:1px solid var(--border);">
        <input type="text" id="userSearch" onkeyup="filterUsers()" placeholder="Search by name, email, or society..." style="width:100%;max-width:320px;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;outline:none;">
    </div>
<?php if (empty($users_list)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--text-muted);"><i class="fa fa-users" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--border);"></i>No users found.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table id="usersTable" style="width:100%;border-collapse:collapse;">
<tr style="border-bottom:1px solid var(--border);">
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Name</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Email</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Role</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Society</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Status</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Actions</th>
</tr>
<?php foreach ($users_list as $u): ?>
<tr style="border-bottom:1px solid var(--border);">
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars($u['name']) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars($u['email']) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;text-transform:capitalize;"><?= htmlspecialchars(str_replace('_',' ',$u['role'])) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars($u['society_name'] ?? '—') ?></td>
    <td style="padding:12px 16px;"><span style="background:<?= $u['is_active'] ? '#dcfce7;color:#166534' : '#fee2e2;color:#dc2626' ?>;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;"><?= $u['is_active']?'Active':'Inactive' ?></span></td>
    <td style="padding:12px 16px;white-space:nowrap;">
        <button type="button"
            onclick="openEditModal(<?= (int)$u['id'] ?>, <?= htmlspecialchars(json_encode($u['name']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['email']), ENT_QUOTES) ?>, <?= htmlspecialchars(json_encode($u['role']), ENT_QUOTES) ?>, <?= (int)($u['society_id'] ?? 0) ?>)"
            style="background:#eff6ff;color:#2563eb;border:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;">
            <i class="fa fa-pen"></i> Edit
        </button>
        <form method="POST" style="display:inline;"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="toggle_user" value="1"><button type="submit" style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;"><?= $u['is_active']?'Deactivate':'Activate' ?></button></form>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete <?= htmlspecialchars(addslashes($u['name'])) ?>? This also removes their listings. Cannot be undone.');"><input type="hidden" name="user_id" value="<?= $u['id'] ?>"><input type="hidden" name="delete_user" value="1"><button type="submit" style="background:#fee2e2;color:#dc2626;border:none;padding:6px 12px;border-radius:7px;font-size:.76rem;font-weight:600;cursor:pointer;"><i class="fa fa-trash"></i></button></form>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<!-- ---- Edit Resident Modal ---- -->
<div id="editUserOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;">
    <div style="background:#fff;border-radius:12px;width:100%;max-width:420px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.2);">
        <h2 style="font-size:1.05rem;font-weight:700;margin-bottom:16px;">Edit Resident</h2>
        <form method="POST">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="user_id" id="edit_user_id">

            <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Name</label>
            <input type="text" name="name" id="edit_name" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">

            <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Email</label>
            <input type="email" name="email" id="edit_email" required style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">

            <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Role</label>
            <select name="role" id="edit_role" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;">
                <?php foreach ($validRoles as $r): ?>
                <option value="<?= $r ?>"><?= ucwords(str_replace('_',' ',$r)) ?></option>
                <?php endforeach; ?>
            </select>

            <label style="display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;">Society</label>
            <select name="society_id" id="edit_society_id" style="width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:20px;font-family:inherit;font-size:.85rem;outline:none;">
                <option value="">— None —</option>
                <?php foreach ($societies_list as $s): ?>
                <option value="<?= (int)$s['id'] ?>"><?= htmlspecialchars($s['society_name']) ?></option>
                <?php endforeach; ?>
            </select>

            <div style="display:flex;gap:10px;justify-content:flex-end;">
                <button type="button" onclick="closeEditModal()" style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:9px 16px;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Cancel</button>
                <button type="submit" style="background:#2563eb;color:#fff;border:none;padding:9px 16px;border-radius:8px;font-size:.85rem;font-weight:600;cursor:pointer;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
function filterUsers(){
    const q = document.getElementById('userSearch').value.toLowerCase();
    document.querySelectorAll('#usersTable tr').forEach((row, i) => {
        if (i === 0) return;
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
}

function openEditModal(id, name, email, role, societyId){
    document.getElementById('edit_user_id').value = id;
    document.getElementById('edit_name').value = name;
    document.getElementById('edit_email').value = email;
    document.getElementById('edit_role').value = role;
    document.getElementById('edit_society_id').value = societyId ? societyId : '';
    document.getElementById('editUserOverlay').style.display = 'flex';
}
function closeEditModal(){
    document.getElementById('editUserOverlay').style.display = 'none';
}
document.getElementById('editUserOverlay').addEventListener('click', function(e){
    if (e.target === this) closeEditModal();
});
</script>

<?php include __DIR__ . '/super_admin_footer.php'; ?>
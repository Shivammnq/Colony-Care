<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /login.php?redirect=' . urlencode($_SERVER['REQUEST_URI'])); exit;
}

// DB constants loaded via config.php

$sid = (int)($_GET['id'] ?? 0);
$user_id = $_SESSION['user_id'];
$msg = $err = '';
$validRoles = ['admin','staff','resident','accountant','vendor','society_member'];

if (!$sid) { header('Location: /super_admin.php'); exit; }

try {
    $pdo = get_db_connection();
    require_once __DIR__ . '/notify_helper.php';

    // ── POST handlers (all scoped to this one society) ──────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['toggle_user'])) {
            $uid = (int)$_POST['user_id'];
            $pdo->prepare("UPDATE users SET is_active = IF(is_active=1,0,1) WHERE id=? AND society_id=?")->execute([$uid, $sid]);
            $msg = 'User status updated.';
        }

        if (isset($_POST['change_role'])) {
            $uid = (int)$_POST['user_id'];
            $newRole = $_POST['new_role'] ?? '';
            if (in_array($newRole, $validRoles)) {
                $pdo->prepare("UPDATE users SET role=? WHERE id=? AND society_id=?")->execute([$newRole, $uid, $sid]);
                $msg = 'Role updated.';
            }
        }

        // ---- Edit resident details (scoped to this society) ----
        if (isset($_POST['edit_user'])) {
            $uid   = (int)$_POST['user_id'];
            $name  = trim($_POST['name'] ?? '');
            $email = trim($_POST['email'] ?? '');
            $phone = trim($_POST['phone'] ?? '');
            $block = trim($_POST['block'] ?? '');
            $unit  = trim($_POST['unit'] ?? '');
            $newRole = $_POST['role'] ?? '';

            if ($name === '' || $email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $err = 'Please provide a valid name and email address.';
            } elseif (!in_array($newRole, $validRoles)) {
                $err = 'Invalid role selected.';
            } else {
                $chk = $pdo->prepare("SELECT id FROM users WHERE id=? AND society_id=?");
                $chk->execute([$uid, $sid]);
                if (!$chk->fetch()) {
                    $err = 'User not found in this society.';
                } else {
                    $dupChk = $pdo->prepare("SELECT id FROM users WHERE email=? AND id<>?");
                    $dupChk->execute([$email, $uid]);
                    if ($dupChk->fetch()) {
                        $err = 'Another user already uses that email address.';
                    } else {
                        $pdo->prepare("UPDATE users SET name=?, email=?, phone=?, block=?, unit=?, role=? WHERE id=? AND society_id=?")
                            ->execute([$name, $email, $phone, $block, $unit, $newRole, $uid, $sid]);
                        $msg = 'Resident details updated.';
                    }
                }
            }
        }

        if (isset($_POST['delete_bill'])) {
            $bid = (int)$_POST['bill_id'];
            $pdo->prepare("DELETE FROM billing WHERE id=? AND society_id=?")->execute([$bid, $sid]);
            $msg = 'Bill deleted.';
        }

        if (isset($_POST['mark_bill_paid'])) {
            $bid = (int)$_POST['bill_id'];
            $pdo->prepare("UPDATE billing SET status='paid', paid_at=NOW(), payment_mode='Super Admin Override' WHERE id=? AND society_id=?")
                ->execute([$bid, $sid]);
            $msg = 'Bill marked as paid.';
        }

        if (isset($_POST['delete_listing'])) {
            $lid = (int)$_POST['listing_id'];
            $pdo->prepare("DELETE FROM listing_photos WHERE listing_id=?")->execute([$lid]);
            $pdo->prepare("DELETE FROM listing_messages WHERE listing_id=?")->execute([$lid]);
            $pdo->prepare("DELETE FROM listing_reports WHERE listing_id=?")->execute([$lid]);
            $pdo->prepare("DELETE FROM listings WHERE id=? AND society_id=?")->execute([$lid, $sid]);
            $msg = 'Listing removed.';
        }

        if (isset($_POST['respond_complaint'])) {
            $cid = (int)$_POST['complaint_id'];
            $newStatus = $_POST['complaint_status'] ?? 'open';
            $response = trim($_POST['admin_response'] ?? '');
            if (in_array($newStatus, ['open','in_progress','resolved','closed'])) {
                $chk = $pdo->prepare("SELECT user_id, subject FROM complaints WHERE id=? AND society_id=?");
                $chk->execute([$cid, $sid]);
                $crow = $chk->fetch();
                if ($crow) {
                    $resolvedAt = in_array($newStatus, ['resolved','closed']) ? date('Y-m-d H:i:s') : null;
                    $pdo->prepare("UPDATE complaints SET status=?, admin_response=?, resolved_at=? WHERE id=?")
                        ->execute([$newStatus, $response, $resolvedAt, $cid]);
                    notify($pdo, $sid, $crow['user_id'], $user_id, 'complaint',
                        'Your complaint "' . $crow['subject'] . '" was updated by platform support' . ($response ? ': ' . $response : '.'), null);
                    $msg = 'Complaint updated.';
                }
            }
        }

        if (isset($_POST['society_action'])) {
            $action = $_POST['society_action'];
            if (in_array($action, ['approve','suspend'])) {
                $newStatus = $action === 'approve' ? 'approved' : 'rejected';
                $pdo->prepare("UPDATE societies SET status=? WHERE id=?")->execute([$newStatus, $sid]);
                $msg = 'Society status updated.';
            }
        }

        if (!empty($msg)) $_SESSION['sa_flash_msg'] = $msg;
        if (!empty($err)) $_SESSION['sa_flash_err'] = $err;
        header('Location: /super_admin_society.php?id=' . $sid); exit;
    }

    $msg = $_SESSION['sa_flash_msg'] ?? '';
    $err = $_SESSION['sa_flash_err'] ?? '';
    unset($_SESSION['sa_flash_msg'], $_SESSION['sa_flash_err']);

    // ── Society header info ──────────────────────────────────────
    $society = $pdo->prepare("
        SELECT s.*, u.name AS owner_name, u.email AS owner_email, u.phone AS owner_phone
        FROM societies s LEFT JOIN users u ON u.id = s.owner_id
        WHERE s.id = ?
    ");
    $society->execute([$sid]);
    $society = $society->fetch();
    if (!$society) { header('Location: /super_admin.php'); exit; }

    // ── Residents / all users in this society ──────────────────
    $residents = $pdo->prepare("SELECT * FROM users WHERE society_id=? ORDER BY role, name");
    $residents->execute([$sid]);
    $residents = $residents->fetchAll();

    // ── Billing summary + list ──
    $billing_stats = ['total'=>0,'collected'=>0,'pending'=>0,'overdue'=>0];
    $billing_list = [];
    try {
        $st = $pdo->prepare("SELECT COUNT(*) FROM billing WHERE society_id=?"); $st->execute([$sid]); $billing_stats['total'] = (int)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid' AND society_id=?"); $st->execute([$sid]); $billing_stats['collected'] = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status IN ('pending','overdue','pending_verification') AND society_id=?"); $st->execute([$sid]); $billing_stats['pending'] = (float)$st->fetchColumn();
        $st = $pdo->prepare("SELECT COUNT(*) FROM billing WHERE status='overdue' AND society_id=?"); $st->execute([$sid]); $billing_stats['overdue'] = (int)$st->fetchColumn();

        $bl = $pdo->prepare("
            SELECT b.*, u.name AS resident_name
            FROM billing b LEFT JOIN users u ON u.id = b.user_id
            WHERE b.society_id=? ORDER BY b.created_at DESC LIMIT 100
        ");
        $bl->execute([$sid]);
        $billing_list = $bl->fetchAll();
    } catch (PDOException $e) { /* billing table shape differs — show empty state instead of crashing */ }

    // ── Sale/Rent listings for this society ──────────────────────
    $listings_list = $pdo->prepare("
        SELECT l.*, u.name AS poster_name,
            (SELECT COUNT(*) FROM listing_reports r WHERE r.listing_id=l.id AND r.status='pending') AS report_count
        FROM listings l LEFT JOIN users u ON u.id = l.user_id
        WHERE l.society_id=? ORDER BY l.created_at DESC
    ");
    $listings_list->execute([$sid]);
    $listings_list = $listings_list->fetchAll();

    // ── Complaints for this society ───────────────────────────────
    $complaints_list = $pdo->prepare("
        SELECT c.*, u.name AS raiser_name, u.role AS raiser_role
        FROM complaints c LEFT JOIN users u ON u.id = c.user_id
        WHERE c.society_id=? ORDER BY FIELD(c.status,'open','in_progress','resolved','closed'), c.created_at DESC
    ");
    $complaints_list->execute([$sid]);
    $complaints_list = $complaints_list->fetchAll();

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
    $society = $society ?? null;
    $residents = $billing_list = $listings_list = $complaints_list = [];
    $billing_stats = ['total'=>0,'collected'=>0,'pending'=>0,'overdue'=>0];
}

if (!$society) { header('Location: /super_admin.php'); exit; }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($society['society_name']) ?> - Super Admin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--green:#0f8f6f;--green-dark:#1a5c3a;--green-btn:#2d7a52;--green-hover:#145f3f;--green-light:#e8f5ee;
--text-primary:#1a2e22;--text-sub:#5a7060;--text-muted:#8fa898;--border:#e5ece8;--bg:#f5f7f6;--white:#fff;--radius:14px;}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text-primary);}
.topbar{background:linear-gradient(135deg,#1a2e22 0%,#2d3f34 60%,#3a4d40 100%);padding:16px 32px;display:flex;align-items:center;gap:14px;color:#fff;flex-wrap:wrap;}
.tb-brand{display:flex;align-items:center;gap:9px;font-weight:700;font-size:1.05rem;}
.tb-right{margin-left:auto;display:flex;gap:10px;}
.tb-btn{padding:8px 16px;border-radius:9px;font-size:.85rem;font-weight:600;text-decoration:none;color:#fff;background:rgba(255,255,255,.12);display:inline-flex;align-items:center;gap:6px;border:none;cursor:pointer;font-family:inherit;}
.tb-btn:hover{background:rgba(255,255,255,.22);}
.wrap{max-width:1300px;margin:0 auto;padding:24px;}
.alert{padding:12px 16px;border-radius:10px;font-size:.87rem;margin-bottom:20px;display:flex;align-items:center;gap:10px;border:1px solid;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.alert-success{background:#f0fdf4;color:#0f8f6f;border-color:#bbf7d0;}
.soc-header{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:22px 26px;margin-bottom:20px;display:flex;justify-content:space-between;align-items:flex-start;flex-wrap:wrap;gap:16px;}
.soc-header h1{font-size:1.3rem;font-weight:700;margin-bottom:6px;}
.soc-meta{font-size:.84rem;color:var(--text-sub);line-height:1.7;}
.badge{font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;white-space:nowrap;text-transform:capitalize;}
.b-approved,.b-active,.b-paid,.b-resolved{background:#dcfce7;color:#166534;}
.b-pending,.b-open,.b-pending_verification{background:#fef9c3;color:#92400e;}
.b-rejected,.b-inactive,.b-overdue,.b-failed{background:#fee2e2;color:#dc2626;}
.b-in_progress{background:#dbeafe;color:#1d4ed8;}
.b-closed{background:#e5e7eb;color:#374151;}
.btn-sm{padding:6px 12px;border:none;border-radius:7px;font-family:inherit;font-size:.76rem;font-weight:600;cursor:pointer;}
.btn-approve{background:#dcfce7;color:#166534;}
.btn-reject{background:#fee2e2;color:#dc2626;}
.btn-neutral{background:var(--bg);color:var(--text-primary);border:1px solid var(--border);}
.btn-edit{background:#eff6ff;color:#2563eb;}
.stats-row{display:flex;gap:14px;flex-wrap:wrap;margin-bottom:20px;}
.stat-mini{background:var(--white);border:1px solid var(--border);border-radius:12px;padding:14px 18px;min-width:130px;}
.stat-mini h3{font-size:1.3rem;font-weight:700;}
.stat-mini p{font-size:.74rem;color:var(--text-muted);}
.tabs{display:flex;gap:8px;margin-bottom:18px;flex-wrap:wrap;}
.tab-btn{background:var(--white);border:1.5px solid var(--border);border-radius:9px;padding:9px 16px;font-family:inherit;font-size:.83rem;font-weight:600;color:var(--text-sub);cursor:pointer;}
.tab-btn.active{background:var(--green-btn);color:#fff;border-color:var(--green-btn);}
.tab-panel{display:none;}
.tab-panel.active{display:block;}
.card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
table{width:100%;border-collapse:collapse;}
th{text-align:left;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);padding:12px 16px;border-bottom:1px solid var(--border);}
td{padding:12px 16px;border-bottom:1px solid var(--border);font-size:.85rem;}
tr:last-child td{border-bottom:none;}
.empty-state{text-align:center;padding:50px 20px;color:var(--text-muted);}
.empty-state i{font-size:2rem;display:block;margin-bottom:10px;color:var(--border);}
.complaint-card{padding:16px 20px;border-bottom:1px solid var(--border);}
.complaint-card:last-child{border-bottom:none;}
.cc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:6px;}
.cc-subject{font-weight:700;font-size:.9rem;}
.cc-meta{font-size:.75rem;color:var(--text-muted);margin-top:2px;}
.cc-desc{font-size:.84rem;margin:8px 0;line-height:1.5;}
.cc-response{background:var(--green-light);border-radius:9px;padding:9px 12px;font-size:.8rem;color:var(--green-dark);margin-bottom:10px;}
.cc-form{display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:10px;}
.cc-form select,.cc-form input{font-family:inherit;font-size:.8rem;border:1.5px solid var(--border);border-radius:8px;padding:6px 10px;}
.cc-form input{flex:1;min-width:160px;}
.cc-form button{background:var(--green-btn);color:#fff;border:none;border-radius:8px;padding:6px 14px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;}
@media(max-width:700px){table{display:block;overflow-x:auto;white-space:nowrap;}}

/* ---- Edit Modal ---- */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:1000;align-items:center;justify-content:center;padding:16px;}
.modal-overlay.show{display:flex;}
.modal-box{background:#fff;border-radius:12px;width:100%;max-width:440px;padding:24px;box-shadow:0 10px 40px rgba(0,0,0,.2);}
.modal-box h2{font-size:1.05rem;font-weight:700;margin-bottom:16px;}
.modal-box label{display:block;font-size:.75rem;font-weight:600;color:var(--text-muted);margin-bottom:4px;}
.modal-box input,.modal-box select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:8px;margin-bottom:14px;font-family:inherit;font-size:.85rem;outline:none;}
.modal-row{display:flex;gap:10px;}
.modal-row > div{flex:1;}
.modal-actions{display:flex;gap:10px;justify-content:flex-end;margin-top:6px;}
</style>
</head>
<body>

<header class="topbar">
  <div class="tb-brand"><i class="fa fa-shield-halved"></i> ColonyCare Super Admin</div>
  <div class="tb-right">
    <a href="/super_admin_societies.php" class="tb-btn"><i class="fa fa-arrow-left"></i> All Societies</a>
    <a href="/logout.php" class="tb-btn"><i class="fa fa-right-from-bracket"></i> Logout</a>
  </div>
</header>

<div class="wrap">

    <?php if ($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success"><i class="fa fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <div class="soc-header">
        <div>
            <h1><?= htmlspecialchars($society['society_name']) ?> <span class="badge b-<?= $society['status'] ?>"><?= ucfirst($society['status']) ?></span></h1>
            <div class="soc-meta">
                <?= htmlspecialchars(($society['address']??'').', '.($society['city']??'').', '.($society['state']??'').' '.($society['pincode']??'')) ?><br>
                Owner: <?= htmlspecialchars($society['owner_name'] ?? '—') ?> · <?= htmlspecialchars($society['owner_email'] ?? '') ?> · <?= htmlspecialchars($society['owner_phone'] ?? '') ?><br>
                <?= (int)($society['total_flats'] ?? 0) ?> flats · Est. <?= htmlspecialchars($society['established_year'] ?? '—') ?>
            </div>
        </div>
        <div>
            <?php if ($society['status'] !== 'approved'): ?>
            <form method="POST" style="display:inline;"><input type="hidden" name="society_action" value="approve"><button class="btn-sm btn-approve" type="submit">Approve</button></form>
            <?php endif; ?>
            <?php if ($society['status'] !== 'rejected'): ?>
            <form method="POST" style="display:inline;" onsubmit="return confirm('Suspend this society?');"><input type="hidden" name="society_action" value="suspend"><button class="btn-sm btn-reject" type="submit">Suspend</button></form>
            <?php endif; ?>
        </div>
    </div>

    <div class="stats-row">
        <div class="stat-mini"><h3><?= count($residents) ?></h3><p>Residents/Staff</p></div>
        <div class="stat-mini"><h3>₹<?= number_format($billing_stats['collected'],0) ?></h3><p>Collected</p></div>
        <div class="stat-mini"><h3>₹<?= number_format($billing_stats['pending'],0) ?></h3><p>Pending Dues</p></div>
        <div class="stat-mini"><h3><?= count($listings_list) ?></h3><p>Sale/Rent Listings</p></div>
        <div class="stat-mini"><h3><?= count(array_filter($complaints_list, fn($c)=>in_array($c['status'],['open','in_progress']))) ?></h3><p>Open Complaints</p></div>
    </div>

    <div class="tabs">
        <button class="tab-btn active" data-tab="residents"><i class="fa fa-users"></i> Residents</button>
        <button class="tab-btn" data-tab="billing"><i class="fa fa-file-invoice-dollar"></i> Billing</button>
        <button class="tab-btn" data-tab="listings"><i class="fa fa-house"></i> Sale/Rent Listings</button>
        <button class="tab-btn" data-tab="complaints"><i class="fa fa-comments"></i> Complaints</button>
    </div>

    <!-- RESIDENTS -->
    <div class="tab-panel active" id="tab-residents">
        <div class="card">
            <?php if (empty($residents)): ?>
            <div class="empty-state"><i class="fa fa-users"></i>No members in this society yet.</div>
            <?php else: ?>
            <table>
                <tr><th>Name</th><th>Email</th><th>Role</th><th>Unit</th><th>Status</th><th>Actions</th></tr>
                <?php foreach ($residents as $r): ?>
                <tr>
                    <td><?= htmlspecialchars($r['name']) ?></td>
                    <td><?= htmlspecialchars($r['email']) ?></td>
                    <td>
                        <form method="POST" style="display:flex;gap:6px;">
                            <input type="hidden" name="user_id" value="<?= $r['id'] ?>">
                            <select name="new_role" onchange="this.form.submit()" style="font-family:inherit;font-size:.78rem;border:1px solid var(--border);border-radius:6px;padding:4px 6px;">
                                <?php foreach ($validRoles as $rl): ?>
                                <option value="<?= $rl ?>" <?= $r['role']===$rl?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$rl)) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="change_role" value="1">
                        </form>
                    </td>
                    <td><?= htmlspecialchars(($r['block']?$r['block'].'-':'').($r['unit']??'')) ?></td>
                    <td><span class="badge <?= $r['is_active']?'b-active':'b-inactive' ?>"><?= $r['is_active']?'Active':'Inactive' ?></span></td>
                    <td style="white-space:nowrap;">
                        <button type="button" class="btn-sm btn-edit edit-user-btn"
                            data-id="<?= (int)$r['id'] ?>"
                            data-name="<?= htmlspecialchars($r['name'], ENT_QUOTES) ?>"
                            data-email="<?= htmlspecialchars($r['email'], ENT_QUOTES) ?>"
                            data-phone="<?= htmlspecialchars($r['phone'] ?? '', ENT_QUOTES) ?>"
                            data-block="<?= htmlspecialchars($r['block'] ?? '', ENT_QUOTES) ?>"
                            data-unit="<?= htmlspecialchars($r['unit'] ?? '', ENT_QUOTES) ?>"
                            data-role="<?= htmlspecialchars($r['role'], ENT_QUOTES) ?>">
                            <i class="fa fa-pen"></i> Edit
                        </button>
                        <form method="POST" style="display:inline;"><input type="hidden" name="user_id" value="<?= $r['id'] ?>"><input type="hidden" name="toggle_user" value="1"><button class="btn-sm btn-neutral" type="submit"><?= $r['is_active']?'Deactivate':'Activate' ?></button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- BILLING -->
    <div class="tab-panel" id="tab-billing">
        <div class="card">
            <?php if (empty($billing_list)): ?>
            <div class="empty-state"><i class="fa fa-file-invoice-dollar"></i>No billing records for this society.</div>
            <?php else: ?>
            <table>
                <tr><th>Resident</th><th>Description</th><th>Month</th><th>Amount</th><th>Due Date</th><th>Status</th><th>Actions</th></tr>
                <?php foreach ($billing_list as $b): ?>
                <tr>
                    <td><?= htmlspecialchars($b['resident_name'] ?? 'Unknown') ?></td>
                    <td><?= htmlspecialchars($b['description'] ?? '') ?></td>
                    <td><?= htmlspecialchars($b['month'] ?? '') ?></td>
                    <td>₹<?= number_format($b['amount'],0) ?></td>
                    <td><?= $b['due_date'] ? date('d M Y', strtotime($b['due_date'])) : '—' ?></td>
                    <td><span class="badge b-<?= $b['status'] ?>"><?= ucwords(str_replace('_',' ',$b['status'])) ?></span></td>
                    <td>
                        <?php if ($b['status'] !== 'paid'): ?>
                        <form method="POST" style="display:inline;"><input type="hidden" name="bill_id" value="<?= $b['id'] ?>"><input type="hidden" name="mark_bill_paid" value="1"><button class="btn-sm btn-approve" type="submit">Mark Paid</button></form>
                        <?php endif; ?>
                        <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this bill?');"><input type="hidden" name="bill_id" value="<?= $b['id'] ?>"><input type="hidden" name="delete_bill" value="1"><button class="btn-sm btn-reject" type="submit">Delete</button></form>
                    </td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- LISTINGS -->
    <div class="tab-panel" id="tab-listings">
        <div class="card">
            <?php if (empty($listings_list)): ?>
            <div class="empty-state"><i class="fa fa-house"></i>No sale/rent listings from this society.</div>
            <?php else: ?>
            <table>
                <tr><th>Unit</th><th>Type</th><th>Price</th><th>Posted By</th><th>Status</th><th>Reports</th><th>Actions</th></tr>
                <?php foreach ($listings_list as $l): ?>
                <tr>
                    <td><?= htmlspecialchars(($l['block']?$l['block'].'-':'').$l['unit']) ?></td>
                    <td><?= ucfirst($l['listing_type']) ?></td>
                    <td>₹<?= number_format($l['price'],0) ?></td>
                    <td><?= htmlspecialchars($l['poster_name'] ?? 'Unknown') ?></td>
                    <td><span class="badge b-<?= $l['status']==='active'?'active':'inactive' ?>"><?= ucfirst($l['status']) ?></span></td>
                    <td><?= $l['report_count'] > 0 ? '<span class="badge b-overdue">'.$l['report_count'].' pending</span>' : '—' ?></td>
                    <td><form method="POST" onsubmit="return confirm('Delete this listing permanently?');"><input type="hidden" name="listing_id" value="<?= $l['id'] ?>"><input type="hidden" name="delete_listing" value="1"><button class="btn-sm btn-reject" type="submit">Delete</button></form></td>
                </tr>
                <?php endforeach; ?>
            </table>
            <?php endif; ?>
        </div>
    </div>

    <!-- COMPLAINTS -->
    <div class="tab-panel" id="tab-complaints">
        <div class="card">
            <?php if (empty($complaints_list)): ?>
            <div class="empty-state"><i class="fa fa-comments"></i>No complaints from this society.</div>
            <?php else: ?>
            <?php foreach ($complaints_list as $c): ?>
            <div class="complaint-card">
                <div class="cc-head">
                    <div>
                        <div class="cc-subject"><?= htmlspecialchars($c['subject']) ?></div>
                        <div class="cc-meta"><?= htmlspecialchars($c['raiser_name'] ?? 'Unknown') ?> (<?= htmlspecialchars(ucfirst($c['raiser_role']??'')) ?>) · <?= date('d M Y', strtotime($c['created_at'])) ?></div>
                    </div>
                    <span class="badge b-<?= $c['status'] ?>"><?= ucwords(str_replace('_',' ',$c['status'])) ?></span>
                </div>
                <div class="cc-desc"><?= nl2br(htmlspecialchars($c['description'])) ?></div>
                <?php if ($c['admin_response']): ?><div class="cc-response"><strong>Response:</strong> <?= nl2br(htmlspecialchars($c['admin_response'])) ?></div><?php endif; ?>
                <form method="POST" class="cc-form">
                    <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                    <select name="complaint_status">
                        <option value="open" <?= $c['status']==='open'?'selected':'' ?>>Open</option>
                        <option value="in_progress" <?= $c['status']==='in_progress'?'selected':'' ?>>In Progress</option>
                        <option value="resolved" <?= $c['status']==='resolved'?'selected':'' ?>>Resolved</option>
                        <option value="closed" <?= $c['status']==='closed'?'selected':'' ?>>Closed</option>
                    </select>
                    <input type="text" name="admin_response" placeholder="Response (optional)" value="<?= htmlspecialchars($c['admin_response'] ?? '') ?>">
                    <button type="submit" name="respond_complaint" value="1">Update</button>
                </form>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>

</div>

<!-- ---- Edit Resident Modal ---- -->
<div id="editUserOverlay" class="modal-overlay">
    <div class="modal-box">
        <h2>Edit Resident</h2>
        <form method="POST">
            <input type="hidden" name="edit_user" value="1">
            <input type="hidden" name="user_id" id="edit_user_id">

            <label>Name</label>
            <input type="text" name="name" id="edit_name" required>

            <label>Email</label>
            <input type="email" name="email" id="edit_email" required>

            <label>Phone</label>
            <input type="text" name="phone" id="edit_phone">

            <div class="modal-row">
                <div>
                    <label>Block</label>
                    <input type="text" name="block" id="edit_block">
                </div>
                <div>
                    <label>Unit</label>
                    <input type="text" name="unit" id="edit_unit">
                </div>
            </div>

            <label>Role</label>
            <select name="role" id="edit_role">
                <?php foreach ($validRoles as $r): ?>
                <option value="<?= $r ?>"><?= ucwords(str_replace('_',' ',$r)) ?></option>
                <?php endforeach; ?>
            </select>

            <div class="modal-actions">
                <button type="button" class="btn-sm btn-neutral" id="cancelEditBtn" style="padding:9px 16px;">Cancel</button>
                <button type="submit" class="btn-sm" style="background:var(--green-btn);color:#fff;padding:9px 16px;">Save Changes</button>
            </div>
        </form>
    </div>
</div>

<script>
// ---- Tabs ----
document.querySelectorAll('.tab-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = this.dataset.tab;
        document.querySelectorAll('.tab-panel').forEach(function (p) { p.classList.remove('active'); });
        document.querySelectorAll('.tab-btn').forEach(function (b) { b.classList.remove('active'); });
        document.getElementById('tab-' + id).classList.add('active');
        this.classList.add('active');
    });
});

// ---- Edit Resident Modal ----
var editOverlay = document.getElementById('editUserOverlay');

function openEditModal(data) {
    document.getElementById('edit_user_id').value = data.id;
    document.getElementById('edit_name').value = data.name;
    document.getElementById('edit_email').value = data.email;
    document.getElementById('edit_phone').value = data.phone;
    document.getElementById('edit_block').value = data.block;
    document.getElementById('edit_unit').value = data.unit;
    document.getElementById('edit_role').value = data.role;
    editOverlay.classList.add('show');
}
function closeEditModal() {
    editOverlay.classList.remove('show');
}

document.querySelectorAll('.edit-user-btn').forEach(function (btn) {
    btn.addEventListener('click', function () {
        openEditModal({
            id: this.dataset.id,
            name: this.dataset.name,
            email: this.dataset.email,
            phone: this.dataset.phone,
            block: this.dataset.block,
            unit: this.dataset.unit,
            role: this.dataset.role
        });
    });
});

document.getElementById('cancelEditBtn').addEventListener('click', closeEditModal);
editOverlay.addEventListener('click', function (e) {
    if (e.target === editOverlay) closeEditModal();
});
</script>
</body>
</html>
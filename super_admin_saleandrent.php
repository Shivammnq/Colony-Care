<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /login.php?redirect=' . urlencode('/super_admin_saleandrent.php')); exit;
}

// DB constants loaded via config.php

$msg = $err = '';
$pageTitle = 'Sale & Rent';
$activeNav = 'saleandrent';
$reports_list = [];

try {
    $pdo = get_db_connection();

    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_reports (
        id INT AUTO_INCREMENT PRIMARY KEY, listing_id INT NOT NULL, reporter_user_id INT DEFAULT NULL,
        reason VARCHAR(50) NOT NULL, details TEXT, status ENUM('pending','reviewed') DEFAULT 'pending',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP, INDEX idx_listing (listing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        if (isset($_POST['delete_listing'])) {
            $lid = (int)$_POST['listing_id'];
            $pdo->prepare("DELETE FROM listing_photos WHERE listing_id=?")->execute([$lid]);
            $pdo->prepare("DELETE FROM listing_messages WHERE listing_id=?")->execute([$lid]);
            $pdo->prepare("DELETE FROM listing_reports WHERE listing_id=?")->execute([$lid]);
            $pdo->prepare("DELETE FROM listings WHERE id=?")->execute([$lid]);
            $msg = 'Listing removed.';
        }
        if (isset($_POST['dismiss_report'])) {
            $lid = (int)$_POST['listing_id'];
            $pdo->prepare("UPDATE listing_reports SET status='reviewed' WHERE listing_id=? AND status='pending'")->execute([$lid]);
            $msg = 'Reports dismissed for this listing.';
        }
        header('Location: /super_admin_saleandrent.php'); exit;
    }

    // All active listings, plus a flag for whether they have pending reports
    $all_listings = $pdo->query("
        SELECT l.*, u.name AS poster_name, s.society_name,
            (SELECT COUNT(*) FROM listing_reports r WHERE r.listing_id=l.id AND r.status='pending') AS report_count
        FROM listings l
        LEFT JOIN users u ON u.id = l.user_id
        LEFT JOIN societies s ON s.id = l.society_id
        ORDER BY report_count DESC, l.created_at DESC
    ")->fetchAll();

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
    $all_listings = [];
}

include __DIR__ . '/super_admin_header.php';
?>

<h1 style="font-size:1.4rem;font-weight:700;margin-bottom:20px;">Sale &amp; Rent Listings</h1>

<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;">
<?php if (empty($all_listings)): ?>
<div style="text-align:center;padding:60px 20px;color:var(--text-muted);"><i class="fa fa-house" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--border);"></i>No listings on the platform yet.</div>
<?php else: ?>
<div style="overflow-x:auto;">
<table style="width:100%;border-collapse:collapse;">
<tr style="border-bottom:1px solid var(--border);">
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Unit</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Society</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Type</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Price</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Posted By</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Status</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Reports</th>
    <th style="text-align:left;padding:12px 16px;font-size:.72rem;text-transform:uppercase;color:var(--text-muted);">Actions</th>
</tr>
<?php foreach ($all_listings as $l): ?>
<tr style="border-bottom:1px solid var(--border);<?= $l['report_count']>0?'background:#fef9f9;':'' ?>">
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars(($l['block']?$l['block'].'-':'').$l['unit']) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars($l['society_name'] ?? '—') ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= ucfirst($l['listing_type']) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;">₹<?= number_format($l['price'],0) ?></td>
    <td style="padding:12px 16px;font-size:.85rem;"><?= htmlspecialchars($l['poster_name'] ?? 'Unknown') ?></td>
    <td style="padding:12px 16px;"><span style="background:<?= $l['status']==='active'?'#dcfce7;color:#166534':'#e5e7eb;color:#374151' ?>;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;text-transform:capitalize;"><?= $l['status'] ?></span></td>
    <td style="padding:12px 16px;"><?= $l['report_count']>0 ? '<span style="background:#fee2e2;color:#dc2626;font-size:.68rem;font-weight:700;padding:3px 10px;border-radius:99px;">'.$l['report_count'].' pending</span>' : '<span style="color:var(--text-muted);font-size:.8rem;">—</span>' ?></td>
    <td style="padding:12px 16px;">
        <?php if ($l['report_count']>0): ?>
        <form method="POST" style="display:inline;"><input type="hidden" name="listing_id" value="<?= $l['id'] ?>"><input type="hidden" name="dismiss_report" value="1"><button type="submit" style="background:var(--bg);color:var(--text-primary);border:1px solid var(--border);padding:6px 10px;border-radius:7px;font-size:.75rem;font-weight:600;cursor:pointer;">Dismiss</button></form>
        <?php endif; ?>
        <form method="POST" style="display:inline;" onsubmit="return confirm('Permanently delete this listing?');"><input type="hidden" name="listing_id" value="<?= $l['id'] ?>"><input type="hidden" name="delete_listing" value="1"><button type="submit" style="background:#fee2e2;color:#dc2626;border:none;padding:6px 10px;border-radius:7px;font-size:.75rem;font-weight:600;cursor:pointer;">Delete</button></form>
    </td>
</tr>
<?php endforeach; ?>
</table>
</div>
<?php endif; ?>
</div>

<?php include __DIR__ . '/super_admin_footer.php'; ?>
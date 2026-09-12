<?php
session_start();
if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /login.php?redirect=' . urlencode('/super_admin_coming_soon.php')); exit;
}
$feature = trim($_GET['f'] ?? 'This section');
$pageTitle = $feature;
$activeNav = '';
$msg = $err = '';
include __DIR__ . '/super_admin_header.php';
?>
<div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:70px 30px;text-align:center;">
    <i class="fa fa-hammer" style="font-size:2.6rem;color:var(--border);display:block;margin-bottom:18px;"></i>
    <h2 style="font-size:1.2rem;font-weight:700;margin-bottom:8px;"><?= htmlspecialchars($feature) ?></h2>
    <p style="color:var(--text-muted);font-size:.9rem;">This section is planned for a later phase and isn't built yet.</p>
</div>
<?php include __DIR__ . '/super_admin_footer.php'; ?>
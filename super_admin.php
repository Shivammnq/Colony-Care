<?php
session_start();

if (!isset($_SESSION['user_id']) || ($_SESSION['user_role'] ?? '') !== 'super_admin') {
    header('Location: /shivam/login.php?redirect=' . urlencode('/shivam/super_admin.php')); exit;
}

define('DB_HOST', 'localhost');
define('DB_NAME', 'cc');
define('DB_USER', 'root');
define('DB_PASS', '');

$msg = $err = '';
$pageTitle = 'Dashboard';
$activeNav = 'dashboard';

$stats = ['societies'=>0,'societies_new'=>0,'residents'=>0,'residents_new'=>0,'open_enquiries'=>0,'collection_month'=>0,'collection_pct'=>0];
$chart_months = []; $chart_values = [];
$activity = [];

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // ── Top stat cards ──────────────────────────────────────────
    $stats['societies']     = (int)$pdo->query("SELECT COUNT(*) FROM societies")->fetchColumn();
    $stats['societies_new'] = (int)$pdo->query("SELECT COUNT(*) FROM societies WHERE created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

    $stats['residents']     = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin')")->fetchColumn();
    $stats['residents_new'] = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE role NOT IN ('super_admin') AND created_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)")->fetchColumn();

    try {
        $stats['open_enquiries'] = (int)$pdo->query("SELECT COUNT(*) FROM complaints WHERE status IN ('open','in_progress')")->fetchColumn();
    } catch (PDOException $e) { /* complaints table not present yet */ }

    try {
        $st = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid' AND MONTH(COALESCE(paid_at,created_at))=MONTH(CURDATE()) AND YEAR(COALESCE(paid_at,created_at))=YEAR(CURDATE())");
        $stats['collection_month'] = (float)$st->fetchColumn();

        $totalDue = (float)$pdo->query("SELECT COALESCE(SUM(amount),0) FROM billing WHERE MONTH(created_at)=MONTH(CURDATE()) AND YEAR(created_at)=YEAR(CURDATE())")->fetchColumn();
        $stats['collection_pct'] = $totalDue > 0 ? round(($stats['collection_month'] / $totalDue) * 100) : 0;

        // ── Last 6 months collection for the chart ──
        $chStmt = $pdo->query("
            SELECT DATE_FORMAT(COALESCE(paid_at,created_at), '%b') AS mon, DATE_FORMAT(COALESCE(paid_at,created_at), '%Y-%m') AS ykey,
                   COALESCE(SUM(amount),0) AS total
            FROM billing WHERE status='paid' AND COALESCE(paid_at,created_at) >= DATE_SUB(CURDATE(), INTERVAL 6 MONTH)
            GROUP BY ykey, mon ORDER BY ykey ASC
        ");
        foreach ($chStmt->fetchAll() as $row) {
            $chart_months[] = $row['mon'];
            $chart_values[] = round($row['total'] / 100000, 2); // in Lakhs
        }
    } catch (PDOException $e) { /* billing table not present yet */ }

    // ── Recent activity feed, built from real recent rows across tables ──
    $feed = [];
    try {
        foreach ($pdo->query("SELECT society_name, created_at FROM societies ORDER BY created_at DESC LIMIT 5") as $r) {
            $feed[] = ['text' => 'New society registered: "' . $r['society_name'] . '"', 'time' => $r['created_at']];
        }
    } catch (PDOException $e) {}
    try {
        foreach ($pdo->query("SELECT l.unit, l.block, l.listing_type, l.created_at, u.name FROM listings l LEFT JOIN users u ON u.id=l.user_id ORDER BY l.created_at DESC LIMIT 5") as $r) {
            $feed[] = ['text' => htmlspecialchars($r['name'] ?? 'Someone') . ' listed a flat for ' . $r['listing_type'] . ' (' . ($r['block']?$r['block'].'-':'') . $r['unit'] . ')', 'time' => $r['created_at']];
        }
    } catch (PDOException $e) {}
    try {
        foreach ($pdo->query("SELECT c.subject, c.created_at, u.name FROM complaints c LEFT JOIN users u ON u.id=c.user_id ORDER BY c.created_at DESC LIMIT 5") as $r) {
            $feed[] = ['text' => htmlspecialchars($r['name'] ?? 'Someone') . ' raised a complaint: "' . $r['subject'] . '"', 'time' => $r['created_at']];
        }
    } catch (PDOException $e) {}
    try {
        foreach ($pdo->query("SELECT name, role, created_at FROM users WHERE role NOT IN ('super_admin') ORDER BY created_at DESC LIMIT 5") as $r) {
            $feed[] = ['text' => htmlspecialchars($r['name']) . ' joined as ' . str_replace('_',' ',$r['role']), 'time' => $r['created_at']];
        }
    } catch (PDOException $e) {}

    usort($feed, fn($a,$b) => strtotime($b['time']) <=> strtotime($a['time']));
    $activity = array_slice($feed, 0, 8);

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
}

function time_ago($datetime) {
    $diff = time() - strtotime($datetime);
    if ($diff < 60) return 'Just now';
    if ($diff < 3600) return floor($diff/60) . ' min ago';
    if ($diff < 86400) return floor($diff/3600) . ' hour' . (floor($diff/3600)>1?'s':'') . ' ago';
    if ($diff < 172800) return 'Yesterday';
    return floor($diff/86400) . ' days ago';
}

include __DIR__ . '/super_admin_header.php';
?>

<div style="display:flex;justify-content:space-between;align-items:flex-end;margin-bottom:22px;flex-wrap:wrap;gap:12px;">
    <div>
        <h1 style="font-size:1.5rem;font-weight:700;">Dashboard</h1>
        <p style="color:var(--text-muted);font-size:.85rem;margin-top:4px;"><?= date('l, j F Y') ?> · everything at a glance</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:22px;">
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;">
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:8px;">Total Societies</p>
        <h2 style="font-size:2rem;font-weight:700;"><?= $stats['societies'] ?></h2>
        <p style="font-size:.78rem;color:var(--green);margin-top:6px;"><i class="fa fa-arrow-up"></i> +<?= $stats['societies_new'] ?> this month</p>
    </div>
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;">
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:8px;">Residents</p>
        <h2 style="font-size:2rem;font-weight:700;"><?= number_format($stats['residents']) ?></h2>
        <p style="font-size:.78rem;color:var(--green);margin-top:6px;"><i class="fa fa-arrow-up"></i> +<?= $stats['residents_new'] ?> this month</p>
    </div>
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;">
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:8px;">Open Enquiries</p>
        <h2 style="font-size:2rem;font-weight:700;"><?= $stats['open_enquiries'] ?></h2>
        <p style="font-size:.78rem;color:var(--text-muted);margin-top:6px;">Open complaints across all societies</p>
    </div>
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;">
        <p style="font-size:.82rem;color:var(--text-muted);margin-bottom:8px;">Collection (<?= date('M') ?>)</p>
        <h2 style="font-size:2rem;font-weight:700;">₹<?= $stats['collection_month'] >= 100000 ? number_format($stats['collection_month']/100000,1).'L' : number_format($stats['collection_month'],0) ?></h2>
        <p style="font-size:.78rem;color:var(--green);margin-top:6px;"><i class="fa fa-arrow-up"></i> <?= $stats['collection_pct'] ?>% collected</p>
    </div>
</div>

<div style="display:grid;grid-template-columns:1.6fr 1fr;gap:20px;">
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:22px;">
        <h3 style="font-size:.95rem;font-weight:700;margin-bottom:16px;">Monthly collection (₹ lakh)</h3>
        <?php if (empty($chart_values)): ?>
        <div style="text-align:center;padding:60px 20px;color:var(--text-muted);font-size:.85rem;">No billing data yet.</div>
        <?php else: ?>
        <canvas id="collectionChart" height="90"></canvas>
        <?php endif; ?>
    </div>
    <div style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:22px;">
        <h3 style="font-size:.95rem;font-weight:700;margin-bottom:14px;">Recent activity</h3>
        <?php if (empty($activity)): ?>
        <p style="color:var(--text-muted);font-size:.85rem;">Nothing yet.</p>
        <?php else: ?>
        <?php foreach ($activity as $a): ?>
        <div style="padding:11px 0;border-bottom:1px solid var(--border);">
            <div style="font-size:.85rem;line-height:1.4;"><?= $a['text'] ?></div>
            <div style="font-size:.74rem;color:var(--text-muted);margin-top:3px;"><?= time_ago($a['time']) ?></div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
    </div>
</div>

<?php if (!empty($chart_values)): ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.0/chart.umd.min.js"></script>
<script>
new Chart(document.getElementById('collectionChart'), {
    type: 'bar',
    data: {
        labels: <?= json_encode($chart_months) ?>,
        datasets: [{
            data: <?= json_encode($chart_values) ?>,
            backgroundColor: '#0f8f6f',
            borderRadius: 6,
            maxBarThickness: 48
        }]
    },
    options: {
        responsive: true,
        plugins: { legend: { display: false } },
        scales: {
            y: { beginAtZero: true, grid: { color: '#f0f0f0' } },
            x: { grid: { display: false } }
        }
    }
});
</script>
<?php endif; ?>

<?php include __DIR__ . '/super_admin_footer.php'; ?>
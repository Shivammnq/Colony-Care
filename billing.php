<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$role    = $_SESSION['user_role'] ?? 'resident';
$user_id = $_SESSION['user_id'];

define('DB_HOST', 'localhost');
define('DB_NAME', 'cc');
define('DB_USER', 'root');
define('DB_PASS', '');

$msg = $err = '';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);

    // Create billing table
    $pdo->exec("CREATE TABLE IF NOT EXISTS billing (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        unit        VARCHAR(20)  NOT NULL,
        description VARCHAR(200) NOT NULL,
        amount      DECIMAL(10,2) NOT NULL,
        month       VARCHAR(20)  NOT NULL,
        due_date    DATE         NOT NULL,
        status      ENUM('pending','paid','overdue') DEFAULT 'pending',
        paid_at     DATETIME     NULL,
        created_at  DATETIME     DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Generate bill (admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'admin' && ($_POST['action']??'') === 'generate') {
        $uid  = (int)$_POST['user_id'];
        $unit = trim($_POST['unit']        ?? '');
        $desc = trim($_POST['description'] ?? 'Monthly Maintenance');
        $amt  = (float)$_POST['amount'];
        $mon  = trim($_POST['month']       ?? date('F Y'));
        $due  = trim($_POST['due_date']    ?? '');

        if ($uid && $unit && $amt && $due) {
            $pdo->prepare("INSERT INTO billing (user_id, unit, description, amount, month, due_date) VALUES (?,?,?,?,?,?)")
                ->execute([$uid, $unit, $desc, $amt, $mon, $due]);
            $msg = "Bill generated for Unit {$unit}!";
        } else { $err = 'All fields are required.'; }
    }

    // Mark as paid (admin)
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role === 'admin' && ($_POST['action']??'') === 'mark_paid') {
        $bid = (int)$_POST['bill_id'];
        $pdo->prepare("UPDATE billing SET status='paid', paid_at=NOW() WHERE id=?")->execute([$bid]);
        $msg = 'Bill marked as paid!';
    }

    // Resident pays
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && $role !== 'admin' && ($_POST['action']??'') === 'pay') {
        $bid = (int)$_POST['bill_id'];
        $pdo->prepare("UPDATE billing SET status='paid', paid_at=NOW() WHERE id=? AND user_id=?")->execute([$bid, $user_id]);
        $msg = 'Payment successful! Thank you.';
    }

    // Mark overdue (bills past due date)
    $pdo->exec("UPDATE billing SET status='overdue' WHERE status='pending' AND due_date < CURDATE()");

    // Fetch bills
    $filter = $_GET['filter'] ?? 'all';
    if ($role === 'admin') {
        $sql = "SELECT b.*, u.name as resident_name FROM billing b LEFT JOIN users u ON b.user_id = u.id WHERE 1=1";
        $params = [];
    } else {
        $sql = "SELECT b.*, u.name as resident_name FROM billing b LEFT JOIN users u ON b.user_id = u.id WHERE b.user_id = ?";
        $params = [$user_id];
    }
    if ($filter !== 'all') { $sql .= " AND b.status = ?"; $params[] = $filter; }
    $sql .= " ORDER BY b.created_at DESC";

    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $bills = $stmt->fetchAll();

    // Summary totals
    if ($role === 'admin') {
        $total_billed  = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM billing")->fetchColumn();
        $total_paid    = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid'")->fetchColumn();
        $total_pending = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='pending'")->fetchColumn();
        $total_overdue = $pdo->query("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='overdue'")->fetchColumn();
    } else {
        $total_billed  = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE user_id=?"); $total_billed->execute([$user_id]); $total_billed = $total_billed->fetchColumn();
        $total_paid    = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE user_id=? AND status='paid'"); $total_paid->execute([$user_id]); $total_paid=$total_paid->fetchColumn();
        $total_pending = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE user_id=? AND status='pending'"); $total_pending->execute([$user_id]); $total_pending=$total_pending->fetchColumn();
        $total_overdue = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE user_id=? AND status='overdue'"); $total_overdue->execute([$user_id]); $total_overdue=$total_overdue->fetchColumn();
    }

    // Counts for tabs
    $base   = $role==='admin' ? "SELECT status, COUNT(*) as c FROM billing GROUP BY status" : "SELECT status, COUNT(*) as c FROM billing WHERE user_id={$user_id} GROUP BY status";
    $cstmt  = $pdo->query($base);
    $counts = ['all'=>count($bills)];
    foreach ($cstmt->fetchAll() as $row) $counts[$row['status']] = $row['c'];

    // Users for admin dropdown
    $users = $role==='admin' ? $pdo->query("SELECT id, name, unit FROM users ORDER BY name")->fetchAll() : [];

} catch (PDOException $e) {
    $err = 'DB Error: '.$e->getMessage();
    $bills = []; $counts = [];
    $total_billed=$total_paid=$total_pending=$total_overdue=0;
    $users=[];
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Billing — ColonyCare</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard.css">
<style>
.top-bar { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.btn-primary { padding:10px 20px; background:var(--green-btn); color:#fff; border:none; border-radius:10px; font-family:inherit; font-size:.88rem; font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex; align-items:center; gap:8px; transition:background .2s; white-space:nowrap; }
.btn-primary:hover { background:var(--green-hover); }

.summary-cards { display:grid; grid-template-columns:repeat(4,1fr); gap:16px; margin-bottom:24px; }
.summary-card { background:var(--white); border:1px solid var(--border); border-radius:var(--radius); padding:20px 22px; }
.summary-card .label { font-size:.78rem; color:var(--text-muted); margin-bottom:6px; }
.summary-card .amount { font-size:1.5rem; font-weight:700; color:var(--text-primary); margin-bottom:4px; }
.summary-card .sub { font-size:.75rem; color:var(--text-muted); }
.summary-card.paid    .amount { color:#16a34a; }
.summary-card.overdue .amount { color:#dc2626; }
.summary-card.pending .amount { color:#c2410c; }

.page-tabs { display:flex; gap:6px; margin-bottom:22px; flex-wrap:wrap; }
.page-tab { padding:7px 16px; border-radius:99px; font-size:.82rem; font-weight:600; border:1.5px solid var(--border); background:var(--white); color:var(--text-sub); cursor:pointer; text-decoration:none; transition:all .2s; }
.page-tab:hover,.page-tab.active { background:var(--green-main); color:#fff; border-color:var(--green-main); }
.page-tab .count { background:rgba(255,255,255,.25); border-radius:99px; padding:1px 6px; margin-left:5px; font-size:.72rem; }
.page-tab:not(.active) .count { background:var(--bg); color:var(--text-muted); }

/* Bill cards */
.bill-card {
  background:var(--white); border:1px solid var(--border); border-radius:var(--radius);
  padding:20px 24px; margin-bottom:12px;
  display:flex; align-items:center; gap:18px; transition:box-shadow .2s;
}
.bill-card:hover { box-shadow:var(--shadow-md); }
.bill-icon {
  width:48px; height:48px; border-radius:12px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:1.1rem;
}
.bill-icon.pending { background:#fff7ed; color:#c2410c; }
.bill-icon.paid    { background:var(--green-light); color:var(--green-main); }
.bill-icon.overdue { background:#fef2f2; color:#dc2626; }
.bill-info { flex:1; min-width:0; }
.bill-info .bill-title { font-size:.92rem; font-weight:700; color:var(--text-primary); margin-bottom:4px; display:flex; align-items:center; gap:10px; flex-wrap:wrap; }
.bill-info .bill-meta { font-size:.78rem; color:var(--text-muted); }
.bill-info .bill-meta span { margin-right:14px; }
.bill-right { text-align:right; flex-shrink:0; }
.bill-amount { font-size:1.2rem; font-weight:700; color:var(--text-primary); display:block; margin-bottom:8px; }
.bill-amount.overdue { color:#dc2626; }
.bill-amount.paid    { color:#16a34a; }

.btn-pay {
  padding:7px 16px; background:var(--green-btn); color:#fff; border:none;
  border-radius:8px; font-family:inherit; font-size:.8rem; font-weight:600;
  cursor:pointer; transition:background .2s;
}
.btn-pay:hover { background:var(--green-hover); }
.btn-mark-paid {
  padding:7px 16px; background:#eff6ff; color:#1d4ed8; border:1.5px solid #bfdbfe;
  border-radius:8px; font-family:inherit; font-size:.8rem; font-weight:600; cursor:pointer;
}

/* Modal */
.modal-overlay { position:fixed; inset:0; background:rgba(0,0,0,.45); z-index:200; display:none; align-items:center; justify-content:center; padding:20px; }
.modal-overlay.open { display:flex; }
.modal { background:#fff; border-radius:16px; padding:36px 32px; width:100%; max-width:500px; max-height:90vh; overflow-y:auto; box-shadow:0 20px 60px rgba(0,0,0,.2); animation:slideUp .25s ease; }
@keyframes slideUp { from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal h3 { font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:4px; }
.modal p  { font-size:.85rem; color:var(--text-muted); margin-bottom:24px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-field { margin-bottom:16px; }
.form-field label { font-size:.82rem; font-weight:600; color:var(--text-primary); display:block; margin-bottom:6px; }
.form-field input,.form-field select { width:100%; padding:10px 14px; border:1.5px solid var(--border); border-radius:9px; font-family:inherit; font-size:.88rem; color:var(--text-primary); outline:none; background:#fff; transition:border-color .2s; }
.form-field input:focus,.form-field select:focus { border-color:var(--green-main); }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:8px; }
.btn-cancel { padding:10px 20px; background:var(--bg); color:var(--text-sub); border:1.5px solid var(--border); border-radius:10px; font-family:inherit; font-size:.88rem; font-weight:600; cursor:pointer; }

.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; padding:11px 16px; border-radius:10px; margin-bottom:18px; font-size:.88rem; }
.alert-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:11px 16px; border-radius:10px; margin-bottom:18px; font-size:.88rem; }
.empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
.empty-state i { font-size:2.5rem; margin-bottom:12px; display:block; color:var(--border); }

@media(max-width:900px){ .summary-cards{grid-template-columns:1fr 1fr;} }
@media(max-width:500px){ .form-row{grid-template-columns:1fr;} .bill-card{flex-direction:column;align-items:flex-start;} .bill-right{text-align:left;} }
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main">
<?php include 'includes/navbar.php'; ?>
<div class="page-content">

  <?php if($msg): ?><div class="alert-success"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert-error"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Summary Cards -->
  <div class="summary-cards">
    <div class="summary-card">
      <div class="label"><i class="fa fa-file-invoice"></i> Total Billed</div>
      <div class="amount">₹<?= number_format($total_billed, 2) ?></div>
      <div class="sub"><?= $counts['all'] ?? 0 ?> invoices total</div>
    </div>
    <div class="summary-card paid">
      <div class="label"><i class="fa fa-circle-check"></i> Collected</div>
      <div class="amount">₹<?= number_format($total_paid, 2) ?></div>
      <div class="sub"><?= $counts['paid'] ?? 0 ?> bills paid</div>
    </div>
    <div class="summary-card pending">
      <div class="label"><i class="fa fa-clock"></i> Pending</div>
      <div class="amount">₹<?= number_format($total_pending, 2) ?></div>
      <div class="sub"><?= $counts['pending'] ?? 0 ?> awaiting payment</div>
    </div>
    <div class="summary-card overdue">
      <div class="label"><i class="fa fa-triangle-exclamation"></i> Overdue</div>
      <div class="amount">₹<?= number_format($total_overdue, 2) ?></div>
      <div class="sub"><?= $counts['overdue'] ?? 0 ?> past due date</div>
    </div>
  </div>

  <!-- Tabs + Action -->
  <div class="top-bar">
    <div class="page-tabs" style="margin-bottom:0">
      <?php foreach(['all'=>'All','pending'=>'Pending','paid'=>'Paid','overdue'=>'Overdue'] as $k=>$label): ?>
      <a href="?filter=<?= $k ?>" class="page-tab <?= $filter===$k?'active':'' ?>">
        <?= $label ?><span class="count"><?= $counts[$k] ?? 0 ?></span>
      </a>
      <?php endforeach; ?>
    </div>
    <?php if($role==='admin'): ?>
    <button class="btn-primary" style="margin-left:auto" onclick="document.getElementById('billModal').classList.add('open')">
      <i class="fa fa-plus"></i> Generate Bill
    </button>
    <?php endif; ?>
  </div>

  <!-- Bills List -->
  <?php if(empty($bills)): ?>
  <div class="empty-state"><i class="fa fa-file-invoice"></i><h3>No bills found</h3><p><?= $role==='admin'?'Click "Generate Bill" to create one.':'You have no bills yet.' ?></p></div>
  <?php else: ?>
  <?php foreach($bills as $b): ?>
  <div class="bill-card">
    <div class="bill-icon <?= $b['status'] ?>">
      <i class="fa <?= $b['status']==='paid' ? 'fa-circle-check' : ($b['status']==='overdue' ? 'fa-triangle-exclamation' : 'fa-file-invoice') ?>"></i>
    </div>
    <div class="bill-info">
      <div class="bill-title">
        <?= htmlspecialchars($b['description']) ?>
        <span class="status-badge status-<?= $b['status']==='in_progress'?'progress':$b['status'] ?>">
          <?= ucfirst($b['status']) ?>
        </span>
      </div>
      <div class="bill-meta">
        <span><i class="fa fa-location-dot"></i> Unit <?= htmlspecialchars($b['unit']) ?></span>
        <span><i class="fa fa-calendar"></i> <?= htmlspecialchars($b['month']) ?></span>
        <span><i class="fa fa-clock"></i> Due: <?= date('d M Y', strtotime($b['due_date'])) ?></span>
        <?php if($role==='admin' && $b['resident_name']): ?><span><i class="fa fa-user"></i> <?= htmlspecialchars($b['resident_name']) ?></span><?php endif; ?>
        <?php if($b['paid_at']): ?><span><i class="fa fa-check"></i> Paid: <?= date('d M Y', strtotime($b['paid_at'])) ?></span><?php endif; ?>
      </div>
    </div>
    <div class="bill-right">
      <span class="bill-amount <?= $b['status'] ?>">₹<?= number_format($b['amount'], 2) ?></span>
      <?php if($b['status'] !== 'paid'): ?>
        <?php if($role === 'admin'): ?>
        <form method="POST">
          <input type="hidden" name="action" value="mark_paid">
          <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn-mark-paid"><i class="fa fa-check"></i> Mark Paid</button>
        </form>
        <?php else: ?>
        <form method="POST">
          <input type="hidden" name="action" value="pay">
          <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
          <button type="submit" class="btn-pay"><i class="fa fa-credit-card"></i> Pay Now</button>
        </form>
        <?php endif; ?>
      <?php else: ?>
        <span style="font-size:.78rem;color:#16a34a;font-weight:600"><i class="fa fa-circle-check"></i> Paid</span>
      <?php endif; ?>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

</div>
</div>

<!-- Generate Bill Modal (Admin only) -->
<?php if($role==='admin'): ?>
<div class="modal-overlay" id="billModal">
  <div class="modal">
    <h3>Generate Bill</h3>
    <p>Create a new billing invoice for a resident.</p>
    <form method="POST">
      <input type="hidden" name="action" value="generate">
      <div class="form-field">
        <label>Resident *</label>
        <select name="user_id" required onchange="fillUnit(this)">
          <option value="">Select Resident</option>
          <?php foreach($users as $u): ?>
          <option value="<?= $u['id'] ?>" data-unit="<?= htmlspecialchars($u['unit']??'') ?>">
            <?= htmlspecialchars($u['name']) ?> <?= $u['unit']?'('.$u['unit'].')':'' ?>
          </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="form-field"><label>Unit *</label><input type="text" name="unit" id="unitField" placeholder="A-201" required></div>
        <div class="form-field"><label>Amount (₹) *</label><input type="number" name="amount" placeholder="2500" min="1" step="0.01" required></div>
      </div>
      <div class="form-field">
        <label>Description</label>
        <input type="text" name="description" value="Monthly Maintenance" placeholder="e.g. Monthly Maintenance">
      </div>
      <div class="form-row">
        <div class="form-field">
          <label>Month</label>
          <select name="month">
            <?php
            for($i=0;$i<12;$i++){
              $d = new DateTime("first day of -$i month");
              $val = $d->format('F Y');
              $sel = $i===0?'selected':'';
              echo "<option value='{$val}' {$sel}>{$val}</option>";
            }
            ?>
          </select>
        </div>
        <div class="form-field"><label>Due Date *</label><input type="date" name="due_date" value="<?= date('Y-m-d', strtotime('+15 days')) ?>" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="document.getElementById('billModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-file-invoice"></i> Generate Bill</button>
      </div>
    </form>
  </div>
</div>
<?php endif; ?>

<script>
const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay'),toggleBtn=document.getElementById('navbarToggle');
if(toggleBtn){toggleBtn.addEventListener('click',()=>{sidebar.classList.toggle('open');overlay.classList.toggle('open');});}
if(overlay){overlay.addEventListener('click',()=>{sidebar.classList.remove('open');overlay.classList.remove('open');});}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');}));

function fillUnit(sel) {
  const unit = sel.options[sel.selectedIndex].dataset.unit;
  const field = document.getElementById('unitField');
  if(field && unit) field.value = unit;
}
</script>
</body>
</html>
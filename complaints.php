<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: login.php'); exit; }

$role    = $_SESSION['user_role'] ?? 'resident';
$user_id = $_SESSION['user_id'];

// DB constants loaded via config.php

$msg = $err = '';

try {
    $pdo = get_db_connection();

    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaints (
        id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
        user_id     INT UNSIGNED NOT NULL,
        unit        VARCHAR(20)  NOT NULL,
        category    VARCHAR(50)  NOT NULL,
        subject     VARCHAR(150) NOT NULL,
        description TEXT         NOT NULL,
        priority    ENUM('low','medium','high') DEFAULT 'medium',
        status      ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        created_at  DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at  DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // Handle new complaint submission
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'submit') {
        $unit     = trim($_POST['unit']        ?? '');
        $category = trim($_POST['category']    ?? '');
        $subject  = trim($_POST['subject']     ?? '');
        $desc     = trim($_POST['description'] ?? '');
        $priority = trim($_POST['priority']    ?? 'medium');

        if ($unit && $category && $subject && $desc) {
            $stmt = $pdo->prepare("INSERT INTO complaints (user_id, unit, category, subject, description, priority) VALUES (?,?,?,?,?,?)");
            $stmt->execute([$user_id, $unit, $category, $subject, $desc, $priority]);
            $msg = 'Complaint submitted successfully!';
        } else {
            $err = 'Please fill in all fields.';
        }
    }

    // Admin: update status
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'update_status' && $role === 'admin') {
        $cid    = (int)$_POST['complaint_id'];
        $status = $_POST['status'];
        $pdo->prepare("UPDATE complaints SET status = ? WHERE id = ?")->execute([$status, $cid]);
        $msg = 'Status updated!';
    }

    // Fetch complaints
    $filter = $_GET['filter'] ?? 'all';
    $search = trim($_GET['search'] ?? '');

    if ($role === 'admin') {
        $sql = "SELECT c.*, u.name as resident_name FROM complaints c LEFT JOIN users u ON c.user_id = u.id WHERE 1=1";
    } else {
        $sql = "SELECT c.*, u.name as resident_name FROM complaints c LEFT JOIN users u ON c.user_id = u.id WHERE c.user_id = ?";
    }

    $params = $role === 'admin' ? [] : [$user_id];

    if ($filter !== 'all') { $sql .= " AND c.status = ?"; $params[] = $filter; }
    if ($search)           { $sql .= " AND (c.subject LIKE ? OR c.unit LIKE ?)"; $params[] = "%$search%"; $params[] = "%$search%"; }
    $sql .= " ORDER BY c.created_at DESC";

    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $complaints = $stmt->fetchAll();

    // Counts for tabs
    $counts = [];
    $cstmt  = $role === 'admin'
        ? $pdo->query("SELECT status, COUNT(*) as c FROM complaints GROUP BY status")
        : $pdo->prepare("SELECT status, COUNT(*) as c FROM complaints WHERE user_id = ? GROUP BY status");
    if ($role !== 'admin') $cstmt->execute([$user_id]); else $cstmt->execute();
    foreach ($cstmt->fetchAll() as $row) $counts[$row['status']] = $row['c'];
    $counts['all'] = array_sum($counts);

} catch (PDOException $e) {
    $err = 'DB Error: ' . $e->getMessage();
    $complaints = [];
    $counts = [];
}

$status_labels = ['open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'];
$priority_labels = ['low'=>'Low','medium'=>'Medium','high'=>'High'];
$categories = ['Plumbing','Electrical','Lift/Elevator','Parking','Security','Housekeeping','Noise','Water Supply','Common Area','Other'];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Complaints — ColonyCare</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<link rel="stylesheet" href="assets/css/dashboard.css">
<style>
.page-tabs { display:flex; gap:6px; margin-bottom:22px; flex-wrap:wrap; }
.page-tab {
  padding:7px 16px; border-radius:99px; font-size:.82rem; font-weight:600;
  border:1.5px solid var(--border); background:var(--white); color:var(--text-sub);
  cursor:pointer; text-decoration:none; transition:all .2s;
}
.page-tab:hover,.page-tab.active { background:var(--green-main); color:#fff; border-color:var(--green-main); }
.page-tab .count { background:rgba(255,255,255,.25); border-radius:99px; padding:1px 6px; margin-left:5px; font-size:.72rem; }
.page-tab:not(.active) .count { background:var(--bg); color:var(--text-muted); }

.top-bar { display:flex; align-items:center; gap:12px; margin-bottom:22px; flex-wrap:wrap; }
.search-box {
  display:flex; align-items:center; gap:10px; flex:1; min-width:200px;
  background:var(--white); border:1.5px solid var(--border); border-radius:10px;
  padding:9px 14px;
}
.search-box input { border:none; outline:none; font-family:inherit; font-size:.88rem; color:var(--text-primary); width:100%; background:transparent; }
.search-box i { color:var(--text-muted); }

.btn-primary {
  padding:10px 20px; background:var(--green-btn); color:#fff;
  border:none; border-radius:10px; font-family:inherit; font-size:.88rem;
  font-weight:600; cursor:pointer; text-decoration:none; display:inline-flex;
  align-items:center; gap:8px; transition:background .2s;
  white-space:nowrap;
}
.btn-primary:hover { background:var(--green-hover); }

/* Modal */
.modal-overlay {
  position:fixed; inset:0; background:rgba(0,0,0,.45);
  z-index:200; display:none; align-items:center; justify-content:center; padding:20px;
}
.modal-overlay.open { display:flex; }
.modal {
  background:#fff; border-radius:16px; padding:36px 32px;
  width:100%; max-width:540px; max-height:90vh; overflow-y:auto;
  box-shadow:0 20px 60px rgba(0,0,0,.2);
  animation:slideUp .25s ease;
}
@keyframes slideUp { from{transform:translateY(30px);opacity:0} to{transform:translateY(0);opacity:1} }
.modal h3 { font-size:1.1rem; font-weight:700; color:var(--text-primary); margin-bottom:4px; }
.modal p  { font-size:.85rem; color:var(--text-muted); margin-bottom:24px; }
.form-row { display:grid; grid-template-columns:1fr 1fr; gap:14px; }
.form-field { margin-bottom:16px; }
.form-field label { font-size:.82rem; font-weight:600; color:var(--text-primary); display:block; margin-bottom:6px; }
.form-field input,
.form-field select,
.form-field textarea {
  width:100%; padding:10px 14px; border:1.5px solid var(--border);
  border-radius:9px; font-family:inherit; font-size:.88rem;
  color:var(--text-primary); outline:none; transition:border-color .2s;
  background:#fff;
}
.form-field input:focus,
.form-field select:focus,
.form-field textarea:focus { border-color:var(--green-main); }
.form-field textarea { resize:vertical; min-height:90px; }
.modal-footer { display:flex; gap:10px; justify-content:flex-end; margin-top:8px; }
.btn-cancel {
  padding:10px 20px; background:var(--bg); color:var(--text-sub);
  border:1.5px solid var(--border); border-radius:10px;
  font-family:inherit; font-size:.88rem; font-weight:600; cursor:pointer;
}

/* Complaints list */
.complaint-card {
  background:var(--white); border:1px solid var(--border);
  border-radius:var(--radius); padding:20px 22px;
  margin-bottom:12px; transition:box-shadow .2s;
  display:flex; align-items:flex-start; gap:16px;
}
.complaint-card:hover { box-shadow:var(--shadow-md); }
.complaint-icon {
  width:42px; height:42px; border-radius:11px; flex-shrink:0;
  display:flex; align-items:center; justify-content:center; font-size:.95rem;
}
.complaint-icon.plumbing  { background:#eff6ff; color:#1d4ed8; }
.complaint-icon.electrical{ background:#fff7ed; color:#c2410c; }
.complaint-icon.other     { background:var(--green-light); color:var(--green-main); }
.complaint-meta { flex:1; min-width:0; }
.complaint-meta .complaint-title {
  font-size:.92rem; font-weight:700; color:var(--text-primary);
  margin-bottom:4px; display:flex; align-items:center; gap:10px; flex-wrap:wrap;
}
.complaint-meta .complaint-sub { font-size:.78rem; color:var(--text-muted); margin-bottom:10px; }
.complaint-meta .complaint-desc { font-size:.83rem; color:var(--text-sub); line-height:1.5; }
.complaint-right { display:flex; flex-direction:column; align-items:flex-end; gap:8px; flex-shrink:0; }

.priority-badge { padding:2px 9px; border-radius:99px; font-size:.68rem; font-weight:700; }
.priority-high   { background:#fef2f2; color:#dc2626; }
.priority-medium { background:#fff7ed; color:#c2410c; }
.priority-low    { background:var(--green-light); color:var(--green-dark); }

.select-status {
  font-family:inherit; font-size:.75rem; font-weight:600;
  border:1.5px solid var(--border); border-radius:8px;
  padding:4px 8px; background:#fff; color:var(--text-primary);
  cursor:pointer; outline:none;
}

.empty-state { text-align:center; padding:60px 20px; color:var(--text-muted); }
.empty-state i { font-size:2.5rem; margin-bottom:12px; display:block; color:var(--border); }
.empty-state h3 { font-size:1rem; font-weight:600; color:var(--text-sub); margin-bottom:4px; }

.alert-success { background:#f0fdf4; color:#166534; border:1px solid #bbf7d0; padding:11px 16px; border-radius:10px; margin-bottom:18px; font-size:.88rem; }
.alert-error   { background:#fef2f2; color:#991b1b; border:1px solid #fecaca; padding:11px 16px; border-radius:10px; margin-bottom:18px; font-size:.88rem; }
</style>
</head>
<body>
<?php include 'includes/sidebar.php'; ?>
<div class="main">
<?php include 'includes/navbar.php'; ?>
<div class="page-content">

  <?php if($msg): ?><div class="alert-success"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert-error"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- Tabs -->
  <div class="page-tabs">
    <?php foreach(['all'=>'All','open'=>'Open','in_progress'=>'In Progress','resolved'=>'Resolved','closed'=>'Closed'] as $key=>$label): ?>
    <a href="?filter=<?= $key ?>" class="page-tab <?= $filter===$key?'active':'' ?>">
      <?= $label ?>
      <span class="count"><?= $counts[$key] ?? 0 ?></span>
    </a>
    <?php endforeach; ?>
  </div>

  <!-- Top Bar -->
  <div class="top-bar">
    <form method="GET" style="flex:1;display:flex;gap:10px;align-items:center">
      <input type="hidden" name="filter" value="<?= htmlspecialchars($filter) ?>">
      <div class="search-box">
        <i class="fa fa-search"></i>
        <input type="text" name="search" placeholder="Search complaints..." value="<?= htmlspecialchars($search) ?>">
      </div>
      <button type="submit" class="btn-primary" style="background:var(--text-sub)"><i class="fa fa-search"></i> Search</button>
    </form>
    <button class="btn-primary" onclick="document.getElementById('complaintModal').classList.add('open')">
      <i class="fa fa-plus"></i> Raise Complaint
    </button>
  </div>

  <!-- Complaints List -->
  <?php if(empty($complaints)): ?>
  <div class="empty-state">
    <i class="fa fa-comments"></i>
    <h3>No complaints found</h3>
    <p>Click "Raise Complaint" to submit a new one.</p>
  </div>
  <?php else: ?>
  <?php foreach($complaints as $c):
    $icon_class = str_contains(strtolower($c['category']), 'plumb') ? 'plumbing' : (str_contains(strtolower($c['category']), 'elect') ? 'electrical' : 'other');
    $icon = match(true) {
      str_contains(strtolower($c['category']), 'plumb')  => 'fa-faucet-drip',
      str_contains(strtolower($c['category']), 'elect')  => 'fa-bolt',
      str_contains(strtolower($c['category']), 'lift')   => 'fa-elevator',
      str_contains(strtolower($c['category']), 'park')   => 'fa-car',
      str_contains(strtolower($c['category']), 'secur')  => 'fa-shield',
      str_contains(strtolower($c['category']), 'noise')  => 'fa-volume-high',
      str_contains(strtolower($c['category']), 'water')  => 'fa-droplet',
      default => 'fa-circle-exclamation',
    };
  ?>
  <div class="complaint-card">
    <div class="complaint-icon <?= $icon_class ?>"><i class="fa <?= $icon ?>"></i></div>
    <div class="complaint-meta">
      <div class="complaint-title">
        #C-<?= str_pad($c['id'], 3, '0', STR_PAD_LEFT) ?> — <?= htmlspecialchars($c['subject']) ?>
        <span class="status-badge status-<?= str_replace('_','-',$c['status']) ?>"><?= $status_labels[$c['status']] ?></span>
        <span class="priority-badge priority-<?= $c['priority'] ?>"><?= $priority_labels[$c['priority']] ?> Priority</span>
      </div>
      <div class="complaint-sub">
        <i class="fa fa-location-dot"></i> Unit <?= htmlspecialchars($c['unit']) ?>
        &nbsp;·&nbsp; <i class="fa fa-tag"></i> <?= htmlspecialchars($c['category']) ?>
        <?php if($role==='admin' && $c['resident_name']): ?>
        &nbsp;·&nbsp; <i class="fa fa-user"></i> <?= htmlspecialchars($c['resident_name']) ?>
        <?php endif; ?>
        &nbsp;·&nbsp; <i class="fa fa-clock"></i> <?= date('d M Y, h:i A', strtotime($c['created_at'])) ?>
      </div>
      <div class="complaint-desc"><?= nl2br(htmlspecialchars(substr($c['description'],0,160))) ?><?= strlen($c['description'])>160?'…':'' ?></div>
    </div>
    <div class="complaint-right">
      <?php if($role==='admin'): ?>
      <form method="POST">
        <input type="hidden" name="action" value="update_status">
        <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
        <select name="status" class="select-status" onchange="this.form.submit()">
          <?php foreach($status_labels as $k=>$v): ?>
          <option value="<?= $k ?>" <?= $c['status']===$k?'selected':'' ?>><?= $v ?></option>
          <?php endforeach; ?>
        </select>
      </form>
      <?php endif; ?>
      <span style="font-size:.72rem;color:var(--text-muted)"><?= date('d M', strtotime($c['created_at'])) ?></span>
    </div>
  </div>
  <?php endforeach; ?>
  <?php endif; ?>

</div>
</div>

<!-- Raise Complaint Modal -->
<div class="modal-overlay" id="complaintModal">
  <div class="modal">
    <h3>Raise a Complaint</h3>
    <p>Describe your issue and we'll look into it right away.</p>
    <form method="POST">
      <input type="hidden" name="action" value="submit">
      <div class="form-row">
        <div class="form-field">
          <label>Unit Number *</label>
          <input type="text" name="unit" placeholder="e.g. A-201" required>
        </div>
        <div class="form-field">
          <label>Category *</label>
          <select name="category" required>
            <option value="">Select category</option>
            <?php foreach($categories as $cat): ?><option><?= $cat ?></option><?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="form-field">
        <label>Subject *</label>
        <input type="text" name="subject" placeholder="Brief title of your complaint" required>
      </div>
      <div class="form-field">
        <label>Description *</label>
        <textarea name="description" placeholder="Describe the issue in detail..." required></textarea>
      </div>
      <div class="form-field">
        <label>Priority</label>
        <select name="priority">
          <option value="low">Low</option>
          <option value="medium" selected>Medium</option>
          <option value="high">High</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="document.getElementById('complaintModal').classList.remove('open')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-paper-plane"></i> Submit Complaint</button>
      </div>
    </form>
  </div>
</div>

<script>
const sidebar=document.getElementById('sidebar'),overlay=document.getElementById('sidebarOverlay'),toggleBtn=document.getElementById('navbarToggle');
if(toggleBtn){toggleBtn.addEventListener('click',()=>{sidebar.classList.toggle('open');overlay.classList.toggle('open');});}
if(overlay){overlay.addEventListener('click',()=>{sidebar.classList.remove('open');overlay.classList.remove('open');});}
// Close modal on overlay click
document.getElementById('complaintModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
</script>
</body>
</html>
<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: /shivam/login.php'); exit; }
$role = $_SESSION['user_role'] ?? 'resident';
if ($role === 'admin')      { header('Location: /shivam/admin_dashboard.php'); exit; }
if ($role === 'resident')   { header('Location: /shivam/resident.php'); exit; }
if ($role === 'staff')      { header('Location: /shivam/gate_staff.php'); exit; }
if ($role === 'accountant') { header('Location: /shivam/accountant.php'); exit; }
if ($role === 'vendor')     { header('Location: /shivam/vendor.php'); exit; }
if ($role !== 'society_member') { header('Location: /shivam/login.php'); exit; }

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Member';
$society_id = $_SESSION['user_society_id'] ?? 0;
if (!$society_id) { header('Location: /shivam/login.php'); exit; }

define('DB_HOST','localhost'); define('DB_NAME','cc'); define('DB_USER','root'); define('DB_PASS','');
$msg = $err = '';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);

    require_once __DIR__ . '/notify_helper.php';

    $stmtSocLookup = $pdo->prepare("SELECT society_name FROM societies WHERE id=? LIMIT 1");
    $stmtSocLookup->execute([$society_id]);
    $page_society_name = $stmtSocLookup->fetchColumn() ?: ($_SESSION['user_society'] ?? '');

    // ── Ensure complaints table exists ──────────────────────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS complaints (
        id INT AUTO_INCREMENT PRIMARY KEY,
        society_id INT NOT NULL,
        user_id INT NOT NULL,
        unit VARCHAR(50) DEFAULT NULL,
        category VARCHAR(50) DEFAULT 'other',
        subject VARCHAR(150) NOT NULL,
        description TEXT NOT NULL,
        priority ENUM('low','medium','high') DEFAULT 'medium',
        status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
        admin_response TEXT,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        resolved_at DATETIME DEFAULT NULL,
        INDEX idx_society (society_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Migration: reconcile columns in case the table was created
    //    by a different page first with a different column set ──
    $complaintCols = ['unit'=>"VARCHAR(50) DEFAULT NULL", 'priority'=>"ENUM('low','medium','high') DEFAULT 'medium'",
                       'admin_response'=>"TEXT", 'resolved_at'=>"DATETIME DEFAULT NULL",
                       'updated_at'=>"DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"];
    foreach ($complaintCols as $colName => $colDef) {
        $hasCol = $pdo->query("SHOW COLUMNS FROM complaints LIKE '$colName'")->fetch();
        if (!$hasCol) { $pdo->exec("ALTER TABLE complaints ADD COLUMN $colName $colDef"); }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['raise_complaint'])) {
            $unitVal  = trim($_POST['c_unit'] ?? '');
            $category = trim($_POST['c_category'] ?? 'Other');
            $subject  = trim($_POST['c_subject']  ?? '');
            $desc     = trim($_POST['c_description'] ?? '');
            $priority = trim($_POST['c_priority'] ?? 'medium');
            if (!in_array($priority, ['low','medium','high'])) $priority = 'medium';

            if ($subject && $category) {
                $pdo->prepare("INSERT INTO complaints (society_id,user_id,unit,category,subject,description,priority) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$society_id, $user_id, $unitVal, $category, $subject, $desc, $priority]);

                $admins = get_owner_and_admins($pdo, $society_id);
                notify($pdo, $society_id, $admins, $user_id, 'complaint',
                    $user_name . ' raised a new complaint: ' . $subject,
                    '/shivam/admin_dashboard.php#tab-complaints'
                );

                $msg = 'Your complaint has been submitted. The admin will review it shortly.';
            } else {
                $err = 'Please fill in the subject and select a category.';
            }
        }

        if (isset($_POST['add_member'])) {
            $name     = trim($_POST['m_name']     ?? '');
            $relation = trim($_POST['m_relation'] ?? '');
            $age      = (int)($_POST['m_age']     ?? 0);
            $proof    = trim($_POST['m_proof']    ?? '');
            $access   = trim($_POST['m_access']   ?? 'view_only');
            $qr       = 'QR-' . strtoupper(substr(md5($user_id . $name . time()), 0, 16));
            if ($name && $relation) {
                $pdo->prepare("INSERT INTO household_members (society_id,user_id,name,relation,age,id_proof,access_level,qr_code) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$society_id, $user_id, $name, $relation, $age?:null, $proof, $access, $qr]);
                $msg = 'Household member added!';
            } else { $err = 'Name and relation are required.'; }
        }

        if (isset($_POST['del_member'])) {
            $mid = (int)$_POST['member_id'];
            $pdo->prepare("DELETE FROM household_members WHERE id=? AND user_id=? AND society_id=?")
                ->execute([$mid, $user_id, $society_id]);
            $msg = 'Member removed.';
        }

        if (isset($_POST['upd_access'])) {
            $mid        = (int)$_POST['member_id'];
            $new_access = trim($_POST['new_access'] ?? 'view_only');
            $allowed    = ['full','limited','view_only'];
            if (in_array($new_access, $allowed)) {
                $pdo->prepare("UPDATE household_members SET access_level=? WHERE id=? AND user_id=? AND society_id=?")
                    ->execute([$new_access, $mid, $user_id, $society_id]);
                $msg = 'Access level updated.';
            }
        }

        if (isset($_POST['update_profile'])) {
            $phone = trim($_POST['phone'] ?? '');
            $unit  = trim($_POST['unit']  ?? '');
            $block = trim($_POST['block'] ?? '');
            $pdo->prepare("UPDATE users SET phone=?, unit=?, block=? WHERE id=? AND society_id=?")
                ->execute([$phone, $unit, $block, $user_id, $society_id]);
            $msg = 'Profile updated!';
        }

        header('Location: /shivam/society-member.php'); exit;
    }

    // ── Fetch member's own data ────────────────────────────────────────
    $profile = $pdo->prepare("SELECT * FROM users WHERE id=? AND society_id=? LIMIT 1");
    $profile->execute([$user_id, $society_id]); $profile = $profile->fetch();

    $members = $pdo->prepare("SELECT * FROM household_members WHERE user_id=? AND society_id=? ORDER BY created_at DESC");
    $members->execute([$user_id, $society_id]); $members = $members->fetchAll();

    $notices = $pdo->prepare("
        SELECT n.*, IF(nr.notice_id IS NULL,1,0) as is_unread
        FROM notices n
        LEFT JOIN notice_reads nr ON nr.notice_id=n.id AND nr.user_id=?
        WHERE n.society_id=?
        ORDER BY n.created_at DESC
    ");
    $notices->execute([$user_id, $society_id]); $notices = $notices->fetchAll();
    $unread_count = count(array_filter($notices, fn($n)=>$n['is_unread']));

    $bills = $pdo->prepare("SELECT * FROM billing WHERE user_id=? AND society_id=? ORDER BY created_at DESC");
    $bills->execute([$user_id, $society_id]); $bills = $bills->fetchAll();
    $pending_dues = array_sum(array_map(fn($b)=>in_array($b['status'],['pending','overdue'])?$b['amount']:0, $bills));

    $events = $pdo->prepare("SELECT * FROM events WHERE society_id=? AND event_date>=CURDATE() ORDER BY event_date ASC LIMIT 5");
    $events->execute([$society_id]); $events = $events->fetchAll();

    $directory = $pdo->prepare("SELECT name,unit,block,phone,role FROM users WHERE is_active=1 AND society_id=? ORDER BY name ASC");
    $directory->execute([$society_id]); $directory = $directory->fetchAll();

    $my_complaints = $pdo->prepare("SELECT * FROM complaints WHERE user_id=? AND society_id=? ORDER BY created_at DESC");
    $my_complaints->execute([$user_id, $society_id]); $my_complaints = $my_complaints->fetchAll();

    $member_count    = count($members);
    $upcoming_events = count($events);

} catch (PDOException $e) {
    $profile=$members=$notices=$bills=$events=$directory=$my_complaints=[];
    $member_count=$unread_count=$pending_dues=$upcoming_events=0;
    $err = 'DB Error: '.$e->getMessage();
    $page_society_name = $page_society_name ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Society Member - ColonyCare</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --green:#2d7a52;--green-dark:#1a5c3a;--green-btn:#1e7a50;--green-hover:#145f3f;
  --green-light:#e8f5ee;--green-soft:#f0f9f4;
  --text-primary:#1a2e22;--text-sub:#5a7060;--text-muted:#8fa898;
  --border:#e5ece8;--bg:#f5f7f6;--white:#fff;--radius:14px;
}
body{font-family:'DM Sans',sans-serif;background:var(--white);color:var(--text-primary);min-height:100vh;}

/* TOPBAR */
.topbar{background:var(--green-dark);height:54px;display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;}
.tb-brand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:700;font-size:.98rem;}
.tb-div{width:1px;height:20px;background:rgba(255,255,255,.2);}
.tb-portal{font-size:.8rem;color:rgba(255,255,255,.6);}
.tb-right{margin-left:auto;display:flex;gap:6px;}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .2s;}
.tb-back{background:rgba(255,255,255,.1);color:#fff;}.tb-back:hover{background:rgba(255,255,255,.2);}
.tb-logout{background:rgba(255,255,255,.1);color:#fff;}.tb-logout:hover{background:rgba(220,38,38,.3);}

/* CONTENT */
.content{max-width:1280px;margin:0 auto;padding:28px 28px 40px;}

/* STAT CARDS */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:32px;}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;text-align:center;}
.stat-card .si{font-size:1.4rem;color:var(--green);margin-bottom:10px;}
.stat-card h3{font-size:1.7rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;}
.stat-card p{font-size:.78rem;color:var(--text-muted);}

/* SECTION HEADER */
.sec-header{display:flex;align-items:center;justify-content:space-between;margin-bottom:20px;}
.sec-header h2{font-size:1.2rem;font-weight:700;color:var(--text-primary);}
.btn-add{padding:10px 20px;background:var(--green-btn);color:#fff;border:none;border-radius:99px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:background .2s;}
.btn-add:hover{background:var(--green-hover);}

/* MEMBER GRID */
.members-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}

/* MEMBER CARD */
.member-card{border:1px solid var(--border);border-radius:var(--radius);padding:20px;background:var(--white);transition:box-shadow .2s;}
.member-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.08);}

.mc-top{display:flex;align-items:flex-start;gap:14px;margin-bottom:12px;position:relative;}
.mc-avatar{width:50px;height:50px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;color:#fff;flex-shrink:0;}
.mc-info{flex:1;min-width:0;}
.mc-name{font-size:.97rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:7px;margin-bottom:3px;}
.admin-dot{width:8px;height:8px;border-radius:50%;background:var(--green);flex-shrink:0;}
.mc-sub{font-size:.78rem;color:var(--text-muted);}
.mc-delete{position:absolute;top:0;right:0;background:none;border:none;cursor:pointer;color:#fca5a5;font-size:.95rem;padding:2px;transition:color .2s;}
.mc-delete:hover{color:#dc2626;}

/* ACCESS + TIME ROW */
.mc-mid{display:flex;align-items:center;justify-content:space-between;margin-bottom:12px;}
.access-badge{padding:3px 11px;border-radius:99px;font-size:.72rem;font-weight:600;cursor:pointer;}
.ab-full{background:var(--green-light);color:var(--green-dark);}
.ab-limited{background:#fef9c3;color:#92400e;}
.ab-view_only{background:var(--bg);color:var(--text-muted);border:1px solid var(--border);}
.mc-time{font-size:.74rem;color:var(--text-muted);}

/* QR SECTION */
.mc-qr{background:var(--bg);border-radius:10px;padding:11px 14px;display:flex;align-items:center;gap:12px;cursor:pointer;transition:background .2s;}
.mc-qr:hover{background:var(--green-soft);}
.qr-img{width:42px;height:42px;flex-shrink:0;}
.qr-info{min-width:0;}
.qr-title{font-size:.78rem;font-weight:600;color:var(--text-primary);margin-bottom:2px;}
.qr-code{font-size:.72rem;color:var(--text-muted);white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}

/* ACCESS EDIT DROPDOWN */
.access-select{font-family:inherit;font-size:.72rem;border:1.5px solid var(--border);border-radius:8px;padding:3px 8px;background:#fff;cursor:pointer;outline:none;color:var(--text-primary);}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:18px;padding:30px 28px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .25s ease;}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal h3{font-size:1.05rem;font-weight:700;margin-bottom:3px;color:var(--text-primary);}
.modal p{font-size:.82rem;color:var(--text-muted);margin-bottom:18px;}
.ff{margin-bottom:14px;}.ff label{font-size:.8rem;font-weight:600;display:block;margin-bottom:5px;color:var(--text-primary);}
.ff input,.ff select{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:.87rem;color:var(--text-primary);outline:none;background:#fff;transition:border-color .2s;}
.ff input:focus,.ff select:focus{border-color:var(--green);}
.ff textarea{width:100%;padding:10px 13px;border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:.87rem;color:var(--text-primary);outline:none;background:#fff;transition:border-color .2s;resize:vertical;}
.ff textarea:focus{border-color:var(--green);}

/* ── COMPLAINTS ── */
.complaints-list{display:flex;flex-direction:column;gap:14px;margin-bottom:30px;}
.complaint-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.cc-head{display:flex;justify-content:space-between;align-items:flex-start;gap:12px;margin-bottom:8px;}
.cc-subject{font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;}
.cc-meta{font-size:.76rem;color:var(--text-muted);}
.cc-status{font-size:.68rem;font-weight:700;padding:4px 10px;border-radius:99px;white-space:nowrap;text-transform:capitalize;}
.cc-open{background:#fef2f2;color:#991b1b;}
.cc-in_progress{background:#fef9c3;color:#92400e;}
.cc-resolved{background:#dcfce7;color:#166534;}
.cc-closed{background:#e5e7eb;color:#374151;}
.cc-priority{font-size:.66rem;font-weight:700;padding:3px 9px;border-radius:99px;white-space:nowrap;}
.cc-pri-low{background:#e5e7eb;color:#374151;}
.cc-pri-medium{background:#fef9c3;color:#92400e;}
.cc-pri-high{background:#fee2e2;color:#dc2626;}
.cc-desc{font-size:.85rem;color:var(--text-primary);line-height:1.5;margin-bottom:10px;}
.cc-response{background:var(--green-light);border-radius:9px;padding:10px 12px;font-size:.82rem;color:var(--green-dark);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:12px;}
.modal-footer{display:flex;gap:9px;justify-content:flex-end;margin-top:8px;}
.btn-cancel{padding:10px 18px;background:var(--bg);color:var(--text-sub);border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;}
.btn-submit{padding:10px 18px;background:var(--green-btn);color:#fff;border:none;border-radius:10px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
.btn-submit:hover{background:var(--green-hover);}

/* QR MODAL */
.qr-modal-code{background:var(--bg);border-radius:12px;padding:20px;text-align:center;margin-bottom:14px;}
.qr-modal-code .qr-big{width:140px;height:140px;margin:0 auto 12px;}
.qr-modal-code .qr-str{font-size:.78rem;font-family:monospace;color:var(--text-sub);word-break:break-all;}
.qr-modal-name{font-size:1rem;font-weight:700;text-align:center;margin-bottom:4px;}
.qr-modal-sub{font-size:.82rem;color:var(--text-muted);text-align:center;margin-bottom:16px;}

/* ALERT */
.alert{padding:10px 14px;border-radius:9px;font-size:.83rem;margin-bottom:16px;display:flex;align-items:center;gap:8px;border:1px solid;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}

/* EMPTY */
.empty-state{text-align:center;padding:50px 20px;color:var(--text-muted);}
.empty-state i{font-size:2rem;display:block;margin-bottom:10px;color:var(--border);}

.ham-btn{display:none;background:rgba(255,255,255,.1);border:none;cursor:pointer;color:#fff;font-size:1.2rem;padding:6px 10px;border-radius:8px;margin-left:6px;}
.ham-btn:hover{background:rgba(255,255,255,.2);}
.mob-nav-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);}
.mob-nav-overlay.open{display:block;}
.mob-nav-panel{position:absolute;top:0;right:0;width:250px;height:100%;background:linear-gradient(160deg,#0b835b,#1a5c3a);display:flex;flex-direction:column;animation:slideRight .25s ease;overflow-y:auto;}
@keyframes slideRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
.mob-nav-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.12);}
.mob-nav-head span{color:#fff;font-weight:700;font-size:.92rem;}
.mob-close-btn{background:none;border:none;color:rgba(255,255,255,.7);font-size:1.1rem;cursor:pointer;padding:4px 8px;border-radius:6px;}
.mob-nav-body{flex:1;padding:8px 0;}
.mob-nav-item{display:flex;align-items:center;gap:12px;padding:13px 18px;color:rgba(255,255,255,.85);font-size:.88rem;font-weight:500;cursor:pointer;transition:background .15s;border:none;background:none;width:100%;text-align:left;font-family:inherit;}
.mob-nav-item:hover{background:rgba(255,255,255,.1);color:#fff;}
.mob-nav-item i{width:16px;text-align:center;}
.mob-nav-divider{height:1px;background:rgba(255,255,255,.1);margin:6px 18px;}
.mob-nav-foot{padding:14px 18px;border-top:1px solid rgba(255,255,255,.12);display:flex;flex-direction:column;gap:8px;}
.mob-nav-foot a{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border-radius:9px;font-size:.85rem;font-weight:600;text-decoration:none;transition:.2s;}
.mob-back-btn{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);}
.mob-logout-btn{background:#dc2626;color:#fff;}
@media(max-width:1000px){.members-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){
    .tb-portal{display:none;}.tb-div{display:none;}.tb-back{display:none;}.tb-logout{display:none;}
    .ham-btn{display:flex;align-items:center;}
    .topbar{padding:0 14px;}
}
@media(max-width:700px){
    .stat-row{grid-template-columns:1fr 1fr;}
    .members-grid{grid-template-columns:1fr;}
    .content{padding:14px;}
    .form-row{grid-template-columns:1fr;}
    .modal{padding:20px 14px;}
    .mc-bottom{flex-wrap:wrap;gap:8px;}
}
@media(max-width:480px){
    .stat-row{grid-template-columns:1fr 1fr;}
    .stat-card{padding:14px 16px;}
    .stat-card h3{font-size:1.3rem;}
    .sec-header{flex-wrap:wrap;gap:10px;}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <div class="tb-brand"><i class="fa fa-building"></i> ColonyCare</div>
  <div class="tb-div"></div>
  <span class="tb-portal">Society Member - Household Management</span>
  <div class="tb-right">
    <a href="/shivam/index.php" class="tb-btn tb-back"><i class="fa fa-arrow-left"></i> Back</a>
    <a href="/shivam/logout.php" class="tb-btn tb-logout"><i class="fa fa-right-from-bracket"></i> Logout</a>
    <button class="ham-btn" onclick="openMemMobNav()" aria-label="Menu"><i class="fa fa-bars"></i></button>
  </div>
</header>

<div class="mob-nav-overlay" id="memMobNav" onclick="if(event.target===this)closeMemMobNav()">
  <div class="mob-nav-panel">
    <div class="mob-nav-head"><span>Society Member</span><button class="mob-close-btn" onclick="closeMemMobNav()"><i class="fa fa-xmark"></i></button></div>
    <div class="mob-nav-body">
      <div style="padding:10px 18px 6px;font-size:.7rem;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;"><?= htmlspecialchars($user_name) ?></div>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="openModal('addModal');closeMemMobNav()"><i class="fa fa-user-plus"></i> Add Member</button>
      <button class="mob-nav-item" onclick="openModal('complaintModal');closeMemMobNav()"><i class="fa fa-comments"></i> Raise Complaint</button>
    </div>
    <div class="mob-nav-foot">
      <a href="/shivam/index.php" class="mob-back-btn"><i class="fa fa-arrow-left"></i> Back to Home</a>
      <a href="/shivam/logout.php" class="mob-logout-btn"><i class="fa fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</div>

<div class="content">

  <?php if($msg): ?><div class="alert alert-success"><i class="fa fa-check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- STAT CARDS -->
  <div class="stat-row">
    <div class="stat-card">
      <div class="si"><i class="fa fa-users"></i></div>
      <h3><?= $total_members ?></h3>
      <p>Family Members</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-key"></i></div>
      <h3><?= $full_access ?></h3>
      <p>Full Access</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-eye"></i></div>
      <h3><?= $view_only ?></h3>
      <p>View Only</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-qrcode"></i></div>
      <h3><?= $qr_active ?></h3>
      <p>QR Codes Active</p>
    </div>
  </div>

  <!-- SECTION HEADER -->
  <div class="sec-header">
    <h2>Household Members</h2>
    <button class="btn-add" onclick="openModal('addModal')">
      <i class="fa fa-user-plus"></i> Add Member
    </button>
  </div>

  <!-- MEMBERS GRID -->
  <?php if(empty($members)): ?>
  <div class="empty-state">
    <i class="fa fa-users"></i>
    <h3>No members yet</h3>
    <p>Click "Add Member" to add your family members.</p>
  </div>
  <?php else: ?>
  <div class="members-grid">
    <?php foreach($members as $i=>$m):
      $color  = $colors[$i % count($colors)];
      $init = implode('', array_map(fn($w)=>strtoupper($w[0]), array_slice(explode(' ', trim($m['name'])), 0, 2)));
      $ab     = 'ab-'.$m['access_level'];
      $alabel = match($m['access_level']){'full'=>'Full Access','limited'=>'Limited',default=>'View Only'};
      $lseen = 'Added ' . date('d M Y', strtotime($m['created_at']));
    ?>
    <div class="member-card">

      <!-- Top: avatar + info + delete -->
      <div class="mc-top">
        <div class="mc-avatar" style="background:<?= $color ?>"><?= htmlspecialchars($init) ?></div>
        <div class="mc-info">
          <div class="mc-name">
            <?= htmlspecialchars($m['name']) ?>
            <?php if($m['is_admin']): ?><span class="admin-dot" title="House Admin"></span><?php endif; ?>
          </div>
          <div class="mc-sub"><?= htmlspecialchars($m['relation']) ?><?= $m['age'] ? ' • Age '.$m['age'] : '' ?></div>
        </div>
        <?php if(!$m['is_admin']): ?>
        <form method="POST" style="display:contents" onsubmit="return confirm('Remove <?= htmlspecialchars($m['name']) ?> from household?')">
          <input type="hidden" name="del_member" value="1">
          <input type="hidden" name="member_id"  value="<?= $m['id'] ?>">
          <button type="submit" class="mc-delete" title="Remove member"><i class="fa fa-trash"></i></button>
        </form>
        <?php endif; ?>
      </div>

      <!-- Mid: access badge + last seen -->
      <div class="mc-mid">
        <?php if($m['is_admin']): ?>
        <span class="access-badge ab-full">Full Access</span>
        <?php else: ?>
        <form method="POST" style="display:inline">
          <input type="hidden" name="upd_access" value="1">
          <input type="hidden" name="member_id"  value="<?= $m['id'] ?>">
          <select name="new_access" class="access-select" onchange="this.form.submit()">
            <option value="full"      <?= $m['access_level']==='full'?'selected':'' ?>>Full Access</option>
            <option value="limited"   <?= $m['access_level']==='limited'?'selected':'' ?>>Limited</option>
            <option value="view_only" <?= $m['access_level']==='view_only'?'selected':'' ?>>View Only</option>
          </select>
        </form>
        <?php endif; ?>
        <span class="mc-time"><?= $lseen ?></span>
      </div>

      <!-- QR Code section -->
      <div class="mc-qr" onclick="showQR('<?= htmlspecialchars($m['qr_code']) ?>','<?= htmlspecialchars($m['name']) ?>','<?= htmlspecialchars($m['relation']) ?>')">
        <!-- Mini QR SVG placeholder -->
        <svg class="qr-img" viewBox="0 0 42 42" xmlns="http://www.w3.org/2000/svg">
          <rect width="42" height="42" fill="#f5f7f6" rx="4"/>
          <!-- QR pattern simulation -->
          <rect x="4" y="4" width="14" height="14" fill="none" stroke="#1a2e22" stroke-width="1.5" rx="1"/>
          <rect x="7" y="7" width="8" height="8" fill="#1a2e22" rx="0.5"/>
          <rect x="24" y="4" width="14" height="14" fill="none" stroke="#1a2e22" stroke-width="1.5" rx="1"/>
          <rect x="27" y="7" width="8" height="8" fill="#1a2e22" rx="0.5"/>
          <rect x="4" y="24" width="14" height="14" fill="none" stroke="#1a2e22" stroke-width="1.5" rx="1"/>
          <rect x="7" y="27" width="8" height="8" fill="#1a2e22" rx="0.5"/>
          <!-- dots -->
          <rect x="24" y="24" width="3" height="3" fill="#1a2e22"/>
          <rect x="29" y="24" width="3" height="3" fill="#1a2e22"/>
          <rect x="34" y="24" width="3" height="3" fill="#1a2e22"/>
          <rect x="24" y="29" width="3" height="3" fill="#1a2e22"/>
          <rect x="34" y="29" width="3" height="3" fill="#1a2e22"/>
          <rect x="24" y="34" width="3" height="3" fill="#1a2e22"/>
          <rect x="29" y="34" width="3" height="3" fill="#1a2e22"/>
          <rect x="34" y="34" width="3" height="3" fill="#1a2e22"/>
        </svg>
        <div class="qr-info">
          <div class="qr-title">Gate Access QR</div>
          <div class="qr-code"><?= htmlspecialchars(substr($m['qr_code'],0,22)) ?>...</div>
        </div>
      </div>

    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- SECTION HEADER -->
  <div class="sec-header">
    <h2>My Complaints</h2>
    <button class="btn-add" onclick="openModal('complaintModal')">
      <i class="fa fa-comment-medical"></i> Raise Complaint
    </button>
  </div>

  <?php if (empty($my_complaints)): ?>
  <div class="empty-state">
    <i class="fa fa-comments"></i>
    <h3>No complaints raised yet</h3>
    <p>Click "Raise Complaint" if you have an issue to report.</p>
  </div>
  <?php else: ?>
  <div class="complaints-list">
    <?php foreach ($my_complaints as $c): $priority = $c['priority'] ?? 'medium'; ?>
    <div class="complaint-card">
      <div class="cc-head">
        <div>
          <div class="cc-subject"><?= htmlspecialchars($c['subject']) ?></div>
          <div class="cc-meta">
            <?= htmlspecialchars($c['category']) ?>
            <?php if ($c['unit']): ?> · Unit <?= htmlspecialchars($c['unit']) ?><?php endif; ?>
            · <?= date('d M Y, h:i A', strtotime($c['created_at'])) ?>
          </div>
        </div>
        <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
          <span class="cc-status cc-<?= $c['status'] ?>"><?= ucwords(str_replace('_',' ',$c['status'])) ?></span>
          <span class="cc-priority cc-pri-<?= $priority ?>"><?= ucfirst($priority) ?></span>
        </div>
      </div>
      <div class="cc-desc"><?= nl2br(htmlspecialchars($c['description'])) ?></div>
      <?php if ($c['admin_response']): ?>
      <div class="cc-response"><strong>Admin response:</strong> <?= nl2br(htmlspecialchars($c['admin_response'])) ?></div>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

</div><!-- /content -->

<!-- ══ ADD MEMBER MODAL ══ -->
<div class="modal-overlay" id="addModal">
  <div class="modal">
    <h3>Add Family Member</h3>
    <p>Register a household member and assign their access level.</p>
    <form method="POST">
      <input type="hidden" name="add_member" value="1">
      <div class="form-row">
        <div class="ff">
          <label>Full Name *</label>
          <input type="text" name="m_name" placeholder="Member name" required>
        </div>
        <div class="ff">
          <label>Relation *</label>
          <select name="m_relation" required>
            <option value="">Select</option>
            <option>Spouse</option>
            <option>Son</option>
            <option>Daughter</option>
            <option>Father</option>
            <option>Mother</option>
            <option>Brother</option>
            <option>Sister</option>
            <option>Grandfather</option>
            <option>Grandmother</option>
            <option>Uncle</option>
            <option>Aunt</option>
            <option>Other</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="ff">
          <label>Age</label>
          <input type="number" name="m_age" placeholder="Age" min="1" max="120">
        </div>
        <div class="ff">
          <label>Access Level</label>
          <select name="m_access">
            <option value="full">Full Access</option>
            <option value="limited">Limited</option>
            <option value="view_only" selected>View Only</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('addModal')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fa fa-user-plus"></i> Add Member</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ RAISE COMPLAINT MODAL ══ -->
<div class="modal-overlay" id="complaintModal">
  <div class="modal">
    <h3>Raise a Complaint</h3>
    <p>Let the society admin know about an issue — you'll be notified once it's addressed.</p>
    <form method="POST">
      <input type="hidden" name="raise_complaint" value="1">
      <div class="form-row">
        <div class="ff">
          <label>Unit Number</label>
          <input type="text" name="c_unit" placeholder="e.g. A-201" value="<?= htmlspecialchars((($profile['block']??'')?$profile['block'].'-':'').($profile['unit']??'')) ?>">
        </div>
        <div class="ff">
          <label>Category *</label>
          <select name="c_category" required>
            <option value="">Select</option>
            <?php foreach (['Plumbing','Electrical','Lift/Elevator','Parking','Security','Housekeeping','Noise','Water Supply','Common Area','Other'] as $cat): ?>
            <option value="<?= $cat ?>"><?= $cat ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
      <div class="ff">
        <label>Subject *</label>
        <input type="text" name="c_subject" placeholder="Brief summary of the issue" required>
      </div>
      <div class="ff">
        <label>Description</label>
        <textarea name="c_description" rows="4" placeholder="Describe the issue in detail"></textarea>
      </div>
      <div class="ff">
        <label>Priority</label>
        <select name="c_priority">
          <option value="low">Low</option>
          <option value="medium" selected>Medium</option>
          <option value="high">High</option>
        </select>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('complaintModal')">Cancel</button>
        <button type="submit" class="btn-submit"><i class="fa fa-paper-plane"></i> Submit Complaint</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ QR MODAL ══ -->
<div class="modal-overlay" id="qrModal">
  <div class="modal" style="max-width:360px;text-align:center">
    <h3 style="text-align:center;margin-bottom:3px" id="qrModalName">Gate Access QR</h3>
    <p id="qrModalSub" style="text-align:center;margin-bottom:16px"></p>
    <div class="qr-modal-code">
      <!-- Large QR SVG -->
      <svg class="qr-big" viewBox="0 0 140 140" xmlns="http://www.w3.org/2000/svg">
        <rect width="140" height="140" fill="#f5f7f6" rx="8"/>
        <rect x="10" y="10" width="50" height="50" fill="none" stroke="#1a2e22" stroke-width="5" rx="3"/>
        <rect x="22" y="22" width="26" height="26" fill="#1a2e22" rx="1.5"/>
        <rect x="80" y="10" width="50" height="50" fill="none" stroke="#1a2e22" stroke-width="5" rx="3"/>
        <rect x="92" y="22" width="26" height="26" fill="#1a2e22" rx="1.5"/>
        <rect x="10" y="80" width="50" height="50" fill="none" stroke="#1a2e22" stroke-width="5" rx="3"/>
        <rect x="22" y="92" width="26" height="26" fill="#1a2e22" rx="1.5"/>
        <!-- Data dots -->
        <rect x="80" y="80" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="95" y="80" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="110" y="80" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="80" y="95" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="110" y="95" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="95" y="110" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="110" y="110" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="125" y="80" width="9" height="9" fill="#1a2e22" rx="1"/>
        <rect x="125" y="110" width="9" height="9" fill="#1a2e22" rx="1"/>
      </svg>
      <div class="qr-str" id="qrCodeStr"></div>
    </div>
    <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:18px">Show this QR code at the gate for entry</p>
    <div class="modal-footer" style="justify-content:center">
      <button type="button" class="btn-cancel" onclick="closeModal('qrModal')">Close</button>
      <button type="button" class="btn-submit" onclick="window.print()"><i class="fa fa-print"></i> Print QR</button>
    </div>
  </div>
</div>

<script>
// Modals
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m =>
  m.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); })
);

// Show QR modal
function showQR(code, name, relation) {
  document.getElementById('qrModalName').textContent = name;
  document.getElementById('qrModalSub').textContent  = relation + ' — Gate Access QR Code';
  document.getElementById('qrCodeStr').textContent   = code;
  openModal('qrModal');
}

function openMemMobNav(){document.getElementById('memMobNav').classList.add('open');document.body.style.overflow='hidden';}
function closeMemMobNav(){document.getElementById('memMobNav').classList.remove('open');document.body.style.overflow='';}

</script>

</body>
</html>
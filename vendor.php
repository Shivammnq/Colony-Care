<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: /shivam/login.php'); exit; }
$role = $_SESSION['user_role'] ?? 'resident';
if ($role === 'admin')          { header('Location: /shivam/admin_dashboard.php'); exit; }
if ($role === 'resident')       { header('Location: /shivam/resident.php'); exit; }
if ($role === 'staff')          { header('Location: /shivam/gate_staff.php'); exit; }
if ($role === 'accountant')     { header('Location: /shivam/accountant.php'); exit; }
if ($role === 'society_member') { header('Location: /shivam/society-member.php'); exit; }
if ($role !== 'vendor')         { header('Location: /shivam/login.php'); exit; }

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Vendor';
$society_id = $_SESSION['user_society_id'] ?? 0;
if (!$society_id) { header('Location: /shivam/login.php'); exit; }

define('DB_HOST','localhost'); define('DB_NAME','cc'); define('DB_USER','root'); define('DB_PASS','');
$msg = $err = '';

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);

    $stmtSocLookup = $pdo->prepare("SELECT society_name FROM societies WHERE id=? LIMIT 1");
    $stmtSocLookup->execute([$society_id]);
    $page_society_name = $stmtSocLookup->fetchColumn() ?: ($_SESSION['user_society'] ?? '');

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['add_ticket'])) {
            $title    = trim($_POST['ticket_title']    ?? '');
            $cat      = trim($_POST['ticket_category'] ?? '');
            $priority = trim($_POST['ticket_priority'] ?? 'medium');
            $desc     = trim($_POST['ticket_desc']     ?? '');
            if ($title) {
                $count = $pdo->prepare("SELECT COUNT(*) FROM vendor_tickets WHERE society_id=?");
                $count->execute([$society_id]); $tno = 'TKT-'.str_pad($count->fetchColumn()+1, 4, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO vendor_tickets (society_id,vendor_id,ticket_no,title,category,priority,description) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$society_id, $user_id, $tno, $title, $cat, $priority, $desc]);
                $msg = 'Service ticket raised!';
            } else { $err = 'Ticket title is required.'; }
        }

        if (isset($_POST['update_ticket'])) {
            $tid    = (int)$_POST['ticket_id'];
            $status = trim($_POST['ticket_status'] ?? 'open');
            $allowed = ['open','in_progress','resolved','closed'];
            if (in_array($status, $allowed)) {
                $pdo->prepare("UPDATE vendor_tickets SET status=? WHERE id=? AND vendor_id=? AND society_id=?")
                    ->execute([$status, $tid, $user_id, $society_id]);
                $msg = 'Ticket status updated.';
            }
        }

        if (isset($_POST['add_payment'])) {
            $amt    = (float)($_POST['pay_amount']  ?? 0);
            $date   = trim($_POST['pay_date']       ?? date('Y-m-d'));
            $mode   = trim($_POST['pay_mode']       ?? 'Bank Transfer');
            $txn    = trim($_POST['pay_txn']        ?? '');
            $tid    = (int)($_POST['pay_ticket_id'] ?? 0);
            if ($amt) {
                $pdo->prepare("INSERT INTO vendor_payments (society_id,vendor_id,ticket_id,amount,payment_date,mode,txn_ref) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$society_id, $user_id, $tid?:null, $amt, $date, $mode, $txn]);
                $msg = 'Payment recorded!';
            } else { $err = 'Amount is required.'; }
        }

        header('Location: /shivam/vendor.php'); exit;
    }

    // ── Fetch vendor's own data, all scoped to society_id + vendor_id ──
    $tickets = $pdo->prepare("SELECT * FROM vendor_tickets WHERE vendor_id=? AND society_id=? ORDER BY created_at DESC");
    $tickets->execute([$user_id, $society_id]); $tickets = $tickets->fetchAll();

    $contracts = $pdo->prepare("SELECT * FROM vendor_contracts WHERE vendor_id=? AND society_id=? ORDER BY start_date DESC");
    $contracts->execute([$user_id, $society_id]); $contracts = $contracts->fetchAll();

    $payments = $pdo->prepare("SELECT * FROM vendor_payments WHERE vendor_id=? AND society_id=? ORDER BY payment_date DESC");
    $payments->execute([$user_id, $society_id]); $payments = $payments->fetchAll();

    // ── Stats ──────────────────────────────────────────────────────────
    $open_tickets    = count(array_filter($tickets,   fn($t)=>$t['status']==='open'));
    $active_contracts= count(array_filter($contracts, fn($c)=>$c['status']==='active'));
    $total_earned    = array_sum(array_map(fn($p)=>$p['amount'], $payments));
    $pending_payments= count(array_filter($payments,  fn($p)=>$p['status']==='pending'));

    // ── Vendor's own profile from users table ──────────────────────────
    $vendor_profile = $pdo->prepare("SELECT * FROM users WHERE id=? AND society_id=? LIMIT 1");
    $vendor_profile->execute([$user_id, $society_id]); $vendor_profile = $vendor_profile->fetch();

} catch (PDOException $e) {
    $tickets=$contracts=$payments=[];
    $open_tickets=$active_contracts=$total_earned=$pending_payments=0;
    $vendor_profile=[];
    $err = 'DB Error: '.$e->getMessage();
    $page_society_name = $page_society_name ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Vendor Portal - ColonyCare</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --green:#2d7a52;--green-dark:#1a5c3a;--green-btn:#1e7a50;--green-hover:#145f3f;
  --green-light:#e8f5ee;--green-soft:#f0f9f4;
  --text-primary:#1a2e22;--text-sub:#5a7060;--text-muted:#8fa898;
  --border:#e5ece8;--bg:#f5f7f6;--white:#fff;
  --radius:12px;
}
body{font-family:'DM Sans',sans-serif;background:var(--white);color:var(--text-primary);min-height:100vh;}

/* TOPBAR */
.topbar{background:var(--green-dark);height:54px;display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;}
.tb-brand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:700;font-size:.98rem;}
.tb-div{width:1px;height:20px;background:rgba(255,255,255,.2);}
.tb-portal{font-size:.8rem;color:rgba(255,255,255,.6);}
.tb-right{margin-left:auto;display:flex;gap:6px;}

/* ── NOTIFICATION BELL ─────────────────────────────── */
.notif-wrap{position:relative;display:inline-flex;}
.notif-bell{background:rgba(255,255,255,.1);border:none;cursor:pointer;color:#fff;width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:background .2s;position:relative;}
.notif-bell:hover{background:rgba(255,255,255,.2);}
.notif-badge{position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;min-width:16px;height:16px;border-radius:99px;display:flex;align-items:center;justify-content:center;padding:0 3px;display:none;}
.notif-badge.show{display:flex;}
.notif-dropdown{position:absolute;top:calc(100% + 8px);right:0;width:320px;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:999;display:none;overflow:hidden;}
.notif-dropdown.open{display:block;}
.notif-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);}
.notif-head span{font-weight:700;font-size:.9rem;color:var(--text-primary);}
.notif-mark-all{font-size:.75rem;color:var(--green);cursor:pointer;border:none;background:none;font-family:inherit;font-weight:600;}
.notif-list{max-height:340px;overflow-y:auto;}
.notif-item{display:flex;gap:10px;padding:11px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;text-decoration:none;}
.notif-item:hover{background:#f9fafb;}
.notif-item.unread{background:#f0f9f4;}
.notif-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;}
.ni-payment{background:#dbeafe;color:#1d4ed8;}
.ni-complaint{background:#fef9c3;color:#92400e;}
.ni-approval{background:#dcfce7;color:#15803d;}
.ni-rejection{background:#fee2e2;color:#dc2626;}
.ni-verification{background:#f3e8ff;color:#7e22ce;}
.notif-text{flex:1;min-width:0;}
.notif-msg{font-size:.8rem;color:#1a2e22;font-weight:500;line-height:1.4;margin-bottom:2px;}
.notif-time{font-size:.7rem;color:#8fa898;}
.notif-empty{text-align:center;padding:28px 16px;color:#8fa898;font-size:.82rem;}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .2s;}
.tb-back{background:rgba(255,255,255,.1);color:#fff;}.tb-back:hover{background:rgba(255,255,255,.2);}
.tb-logout{background:rgba(255,255,255,.1);color:#fff;}.tb-logout:hover{background:rgba(220,38,38,.3);}

/* CONTENT */
.content{max-width:1200px;margin:0 auto;padding:28px 28px 40px;}

/* HERO */
.hero-name{font-size:1.65rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:10px;margin-bottom:5px;}
.hero-sub{font-size:.83rem;color:var(--text-muted);margin-bottom:24px;}

/* STAT CARDS */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px;}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;}
.stat-card .si{font-size:1.05rem;color:var(--green);margin-bottom:10px;}
.stat-card h3{font-size:1.5rem;font-weight:700;color:var(--text-primary);margin-bottom:3px;display:flex;align-items:center;gap:8px;}
.star-icon{color:#f59e0b;font-size:1rem;}
.stat-card p{font-size:.77rem;color:var(--text-muted);}

/* TABS */
.tabs-bar{background:var(--bg);border:1px solid var(--border);border-radius:99px;padding:5px;display:flex;gap:3px;margin-bottom:26px;width:fit-content;}
.tab-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;font-family:inherit;font-size:.82rem;font-weight:500;color:var(--text-muted);border:none;background:none;cursor:pointer;border-radius:99px;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:var(--text-primary);}
.tab-btn.active{background:var(--white);color:var(--text-primary);font-weight:600;box-shadow:0 1px 6px rgba(0,0,0,.09);}

/* TAB SECTIONS */
.tab-section{display:none;}.tab-section.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* TICKET CARD */
.ticket-wrap{border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.tc-head{padding:18px 22px;border-bottom:1px solid var(--border);}
.tc-head h3{font-size:1rem;font-weight:700;color:var(--text-primary);}
.ticket-item{padding:20px 22px;border-bottom:1px solid var(--border);}
.ticket-item:last-child{border-bottom:none;}
.ticket-meta{display:flex;align-items:center;gap:8px;margin-bottom:8px;flex-wrap:wrap;}
.tkt-no{font-size:.78rem;color:var(--text-muted);}
.pri-badge{padding:2px 9px;border-radius:99px;font-size:.72rem;font-weight:600;}
.pb-high{background:#fef2f2;color:#dc2626;}
.pb-medium{background:#fff7ed;color:#c2410c;}
.pb-low{background:var(--green-light);color:var(--green-dark);}
.community-tag{background:var(--bg);color:var(--text-sub);font-size:.75rem;font-weight:500;padding:2px 9px;border-radius:5px;border:1px solid var(--border);}
.ticket-title{font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:5px;}
.ticket-dates{font-size:.78rem;color:var(--text-muted);margin-bottom:8px;}
.ticket-contact{display:inline-flex;align-items:center;gap:8px;background:var(--bg);border:1px solid var(--border);border-radius:99px;padding:5px 12px;font-size:.78rem;color:var(--text-sub);margin-bottom:12px;}
.ticket-contact .ph{color:var(--green);font-size:.75rem;}
.ticket-actions{display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.status-pill{padding:4px 12px;border-radius:99px;font-size:.73rem;font-weight:600;}
.sp-open{background:#fef2f2;color:#dc2626;}
.sp-in_progress{background:#fff7ed;color:#c2410c;}
.sp-completed{background:var(--green-light);color:var(--green-dark);}
.btn-accept{padding:8px 18px;background:var(--green-btn);color:#fff;border:none;border-radius:99px;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .2s;}
.btn-accept:hover{background:var(--green-hover);}
.btn-complete{padding:8px 18px;background:var(--white);color:var(--text-sub);border:1.5px solid var(--border);border-radius:99px;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:all .2s;}
.btn-complete:hover{border-color:var(--green);color:var(--green);}

/* PAYMENTS TABLE */
.table-wrap{border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.tw-head{padding:18px 22px;border-bottom:1px solid var(--border);}
.tw-head h3{font-size:1rem;font-weight:700;}
.data-table{width:100%;border-collapse:collapse;}
.data-table th{font-size:.74rem;color:var(--text-muted);padding:12px 22px;text-align:left;border-bottom:1px solid var(--border);font-weight:500;}
.data-table td{padding:16px 22px;font-size:.87rem;border-bottom:1px solid var(--border);color:var(--text-primary);}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:var(--bg);}
.pay-id{color:var(--text-muted);font-size:.83rem;}
.pay-amount{font-weight:700;font-size:.95rem;}
.pay-date{color:var(--text-sub);}
.pay-inv{color:var(--text-muted);font-size:.82rem;}
.pay-badge{padding:3px 10px;border-radius:99px;font-size:.7rem;font-weight:600;}
.pb-received{background:var(--green-light);color:var(--green-dark);}
.pb-pending{background:#fff7ed;color:#c2410c;}

/* CONTRACTS */
.contract-item{border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;margin-bottom:14px;position:relative;}
.contract-item:last-child{margin-bottom:0;}
.contract-top{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:6px;}
.contract-name{font-size:1rem;font-weight:700;color:var(--text-primary);}
.contract-type{font-size:.82rem;color:var(--text-muted);margin-bottom:14px;}
.contract-details{display:grid;grid-template-columns:1fr 1fr 1fr;gap:16px;align-items:center;}
.cd-label{font-size:.74rem;color:var(--text-muted);margin-bottom:3px;}
.cd-value{font-size:.87rem;font-weight:600;color:var(--text-primary);}
.contract-badge{padding:3px 10px;border-radius:99px;font-size:.72rem;font-weight:600;}
.cb-active{background:var(--green-light);color:var(--green-dark);}
.cb-renewal_due{background:#fff7ed;color:#c2410c;}
.cb-expired{background:#fef2f2;color:#dc2626;}
.btn-view-contract{padding:7px 16px;background:var(--white);color:var(--text-primary);border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .2s;}
.btn-view-contract:hover{border-color:var(--green);color:var(--green);}

/* PERFORMANCE */
.perf-card{border:1px solid var(--border);border-radius:var(--radius);padding:28px 24px;}
.perf-card h3{font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:28px;}
.donut-row{display:grid;grid-template-columns:repeat(3,1fr);gap:20px;margin-bottom:28px;}
.donut-wrap{display:flex;flex-direction:column;align-items:center;gap:14px;}
.donut-label{font-size:.87rem;font-weight:600;color:var(--text-primary);}
.donut-svg{width:120px;height:120px;}
.donut-circle{fill:none;stroke:#e5ece8;stroke-width:10;}
.donut-progress{fill:none;stroke:var(--green);stroke-width:10;stroke-linecap:round;transform:rotate(-90deg);transform-origin:60px 60px;transition:stroke-dasharray 1s ease;}
.donut-text{font-size:1.1rem;font-weight:700;fill:var(--text-primary);dominant-baseline:central;text-anchor:middle;}
.perf-stats{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.perf-stat{background:var(--bg);border-radius:10px;padding:18px 20px;}
.perf-stat .ps-label{font-size:.77rem;color:var(--text-muted);margin-bottom:6px;}
.perf-stat .ps-val{font-size:1.5rem;font-weight:700;color:var(--text-primary);margin-bottom:2px;}
.perf-stat .ps-sub{font-size:.75rem;color:var(--green);font-weight:600;}

/* ALERT */
.alert{padding:10px 14px;border-radius:9px;font-size:.83rem;margin-bottom:14px;display:flex;align-items:center;gap:8px;border:1px solid;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}

/* EMPTY */
.empty-state{text-align:center;padding:40px 20px;color:var(--text-muted);}
.empty-state i{font-size:1.8rem;display:block;margin-bottom:8px;color:var(--border);}

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
@media(max-width:900px){
    .stat-row{grid-template-columns:1fr 1fr;}
    .donut-row{grid-template-columns:1fr 1fr;}
    .contract-details{grid-template-columns:1fr 1fr;}
    .content{padding:16px;}
    .tb-portal{display:none;}.tb-div{display:none;}.tb-back{display:none;}.tb-logout{display:none;}
    .ham-btn{display:flex;align-items:center;}
    .tabs-bar{overflow-x:auto;flex-wrap:nowrap;border-radius:12px;width:100%;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
    .tabs-bar::-webkit-scrollbar{display:none;}
    .tab-btn{flex-shrink:0;}
}
@media(max-width:600px){
    .stat-row{grid-template-columns:1fr 1fr;}
    .perf-stats{grid-template-columns:1fr;}
    .donut-row{grid-template-columns:1fr;}
    .contract-details{grid-template-columns:1fr;}
    .data-table{display:block;overflow-x:auto;}
    .modal{padding:20px 14px;}
    .form-row{grid-template-columns:1fr;}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <div class="tb-brand"><i class="fa fa-building"></i> ColonyCare</div>
  <div class="tb-div"></div>
  <span class="tb-portal">Vendor Portal</span>
  <div class="tb-right">
    <div class="notif-wrap">
        <button class="notif-bell" id="notifBell" onclick="toggleNotif(event)" title="Notifications">
            <i class="fa fa-bell"></i>
            <span class="notif-badge" id="notifBadge">0</span>
        </button>
        <div class="notif-dropdown" id="notifDropdown">
            <div class="notif-head">
                <span>Notifications</span>
                <button class="notif-mark-all" onclick="markAllRead()">Mark all read</button>
            </div>
            <div class="notif-list" id="notifList">
                <div class="notif-empty">Loading...</div>
            </div>
        </div>
    </div>
    <a href="/shivam/index.php" class="tb-btn tb-back"><i class="fa fa-arrow-left"></i> Back</a>
    <a href="/shivam/logout.php" class="tb-btn tb-logout"><i class="fa fa-right-from-bracket"></i> Logout</a>
    <button class="ham-btn" onclick="openVenMobNav()" aria-label="Menu"><i class="fa fa-bars"></i></button>
  </div>
</header>

<div class="mob-nav-overlay" id="venMobNav" onclick="if(event.target===this)closeVenMobNav()">
  <div class="mob-nav-panel">
    <div class="mob-nav-head"><span>Vendor Portal</span><button class="mob-close-btn" onclick="closeVenMobNav()"><i class="fa fa-xmark"></i></button></div>
    <div class="mob-nav-body">
      <div style="padding:10px 18px 6px;font-size:.7rem;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;"><?= htmlspecialchars($user_name) ?></div>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="switchTab('tickets',null);closeVenMobNav()"><i class="fa fa-ticket"></i> Tickets</button>
      <button class="mob-nav-item" onclick="switchTab('contracts',null);closeVenMobNav()"><i class="fa fa-file-contract"></i> Contracts</button>
      <button class="mob-nav-item" onclick="switchTab('payments',null);closeVenMobNav()"><i class="fa fa-credit-card"></i> Payments</button>
      <button class="mob-nav-item" onclick="switchTab('performance',null);closeVenMobNav()"><i class="fa fa-chart-line"></i> Performance</button>
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

  <!-- HERO -->
  <div class="hero-name">Vendor 🔧</div>
  <div class="hero-sub"><?= htmlspecialchars($user_name) ?> &bull; Vendor Portal</div>

  <!-- STAT CARDS -->
  <div class="stat-row">
    <div class="stat-card">
      <div class="si"><i class="fa fa-star"></i></div>
      <h3><?= $avg_rating ?> <span class="star-icon">★</span></h3>
      <p>Avg Rating</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-comments"></i></div>
      <h3><?= $total_tickets ?></h3>
      <p>Total Tickets</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-clock"></i></div>
      <h3><?= $avg_resolution ?> days</h3>
      <p>Avg Resolution</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-circle-check"></i></div>
      <h3><?= $ontime_rate ?>%</h3>
      <p>On-Time Rate</p>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs-bar">
    <button class="tab-btn active" onclick="switchTab('tickets',this)">
      <i class="fa fa-screwdriver-wrench"></i> Tickets
    </button>
    <button class="tab-btn" onclick="switchTab('payments',this)">
      <i class="fa fa-credit-card"></i> Payments
    </button>
    <button class="tab-btn" onclick="switchTab('contracts',this)">
      <i class="fa fa-file-contract"></i> Contracts
    </button>
    <button class="tab-btn" onclick="switchTab('performance',this)">
      <i class="fa fa-star"></i> Performance
    </button>
  </div>

  <!-- ══ TICKETS TAB ══ -->
  <div class="tab-section active" id="tab-tickets">
    <div class="ticket-wrap">
      <div class="tc-head"><h3>Service Tickets</h3></div>

      <?php if(empty($tickets)): ?>
      <div class="empty-state"><i class="fa fa-screwdriver-wrench"></i><p>No tickets assigned yet</p></div>
      <?php else: foreach($tickets as $t): 
        $sc = match($t['status']){
            'open'        => 'status-open',
            'in_progress' => 'status-progress',
            'resolved'    => 'status-resolved',
            'closed'      => 'status-closed',
            default       => 'status-open'
        };

        ?>
      <div class="ticket-item">
        <div class="ticket-meta">
          <span class="tkt-no"><?= htmlspecialchars($t['ticket_no']) ?></span>
          <span class="pri-badge pb-<?= $t['priority'] ?>"><?= ucfirst($t['priority']) ?></span>
          <span class="community-tag"><?= htmlspecialchars($t['community']) ?></span>
        </div>
        <div class="ticket-title"><?= htmlspecialchars($t['title']) ?></div>
        <div class="ticket-dates">
          Created: <?= $t['created_date'] ? date('M d', strtotime($t['created_date'])) : '—' ?>
          &nbsp;&bull;&nbsp;
          Deadline: <?= $t['deadline'] ? date('M d', strtotime($t['deadline'])) : '—' ?>
        </div>

        <?php if($t['contact_name']): ?>
        <div class="ticket-contact">
          <span>🧑 <?= htmlspecialchars($t['contact_name']) ?></span>
          <?php if($t['contact_phone']): ?>
          &bull;
          <span class="ph"><i class="fa fa-phone"></i> <?= htmlspecialchars($t['contact_phone']) ?></span>
          <?php endif; ?>
        </div>
        <?php endif; ?>

        <div class="ticket-actions">
          <span class="status-pill <?= $sc ?>">
            <?= $t['status'] === 'in_progress' ? 'In Progress' : ucfirst($t['status']) ?>
          </span>

          <?php if($t['status'] === 'open'): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="accept_ticket" value="1">
            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
            <button type="submit" class="btn-accept">
              <i class="fa fa-user-check"></i> Accept &amp; Assign
            </button>
          </form>

          <?php elseif($t['status'] === 'in_progress'): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="complete_ticket" value="1">
            <input type="hidden" name="ticket_id" value="<?= $t['id'] ?>">
            <button type="submit" class="btn-complete">Mark Complete</button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ══ PAYMENTS TAB ══ -->
  <div class="tab-section" id="tab-payments">
    <div class="table-wrap">
      <div class="tw-head"><h3>Payment History</h3></div>

      <?php if(empty($payments)): ?>
      <div class="empty-state"><i class="fa fa-credit-card"></i><p>No payments yet</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>ID</th>
            <th>Community</th>
            <th>Amount</th>
            <th>Date</th>
            <th>Invoice</th>
            <th>Status</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($payments as $p): ?>
        <tr>
          <td><span class="pay-id"><?= htmlspecialchars($p['pay_no']) ?></span></td>
          <td><?= htmlspecialchars($p['community']) ?></td>
          <td><span class="pay-amount">₹<?= number_format($p['amount'],0) ?></span></td>
          <td><span class="pay-date"><?= $p['pay_date'] ? date('M d, Y', strtotime($p['pay_date'])) : '—' ?></span></td>
          <td><span class="pay-inv"><?= htmlspecialchars($p['invoice_no']) ?></span></td>
          <td><span class="pay-badge pb-<?= $p['status'] ?>"><?= ucfirst($p['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ CONTRACTS TAB ══ -->
  <div class="tab-section" id="tab-contracts">
    <?php if(empty($contracts)): ?>
    <div class="empty-state"><i class="fa fa-file-contract"></i><p>No contracts found</p></div>
    <?php else: foreach($contracts as $c): ?>
    <div class="contract-item">
      <div class="contract-top">
        <div>
          <div class="contract-name"><?= htmlspecialchars($c['community']) ?></div>
          <div class="contract-type"><?= htmlspecialchars($c['service_type']) ?></div>
        </div>
        <span class="contract-badge cb-<?= $c['status'] ?>">
          <?= $c['status'] === 'renewal_due' ? 'Renewal Due' : ucfirst($c['status']) ?>
        </span>
      </div>
      <div class="contract-details">
        <div>
          <div class="cd-label">Period</div>
          <div class="cd-value">
            <?= $c['period_start'] ? date('M Y', strtotime($c['period_start'])) : '—' ?>
            —
            <?= $c['period_end'] ? date('M Y', strtotime($c['period_end'])) : '—' ?>
          </div>
        </div>
        <div>
          <div class="cd-label">Contract Value</div>
          <div class="cd-value">₹<?= number_format($c['contract_value'],0) ?>/yr</div>
        </div>
        <div>
          <button class="btn-view-contract" onclick="alert('Contract: <?= htmlspecialchars($c['community']) ?>\nService: <?= htmlspecialchars($c['service_type']) ?>\nValue: ₹<?= number_format($c['contract_value'],0) ?>/yr\nStatus: <?= ucfirst($c['status']) ?>')">
            View Contract
          </button>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- ══ PERFORMANCE TAB ══ -->
  <div class="tab-section" id="tab-performance">
    <div class="perf-card">
      <h3>Performance Dashboard</h3>

      <!-- Donut charts -->
      <div class="donut-row">
        <?php
        $metrics = [
          ['label'=>'Satisfaction', 'value'=>$satisfaction],
          ['label'=>'On-Time',      'value'=>$ontime_rate],
          ['label'=>'Resolution',   'value'=>$resolution_pct],
        ];
        $r = 50; $circ = 2 * M_PI * $r;
        foreach($metrics as $m):
          $dash = ($m['value'] / 100) * $circ;
          $gap  = $circ - $dash;
        ?>
        <div class="donut-wrap">
          <svg class="donut-svg" viewBox="0 0 120 120">
            <circle class="donut-circle" cx="60" cy="60" r="<?= $r ?>"/>
            <circle class="donut-progress"
              cx="60" cy="60" r="<?= $r ?>"
              stroke-dasharray="<?= round($dash,2) ?> <?= round($gap,2) ?>"/>
            <text class="donut-text" x="60" y="60"><?= $m['value'] ?>%</text>
          </svg>
          <div class="donut-label"><?= $m['label'] ?></div>
        </div>
        <?php endforeach; ?>
      </div>

      <!-- Stats below -->
      <div class="perf-stats">
        <div class="perf-stat">
          <div class="ps-label">Total Tickets</div>
          <div class="ps-val"><?= $total_tickets ?></div>
          <div class="ps-sub"><?= $completed ?> resolved</div>
        </div>
        <div class="perf-stat">
          <div class="ps-label">Avg Resolution Time</div>
          <div class="ps-val"><?= $avg_resolution ?> days</div>
          <div class="ps-sub">Within SLA targets</div>
        </div>
        <div class="perf-stat">
          <div class="ps-label">Open Tickets</div>
          <div class="ps-val"><?= $open_tickets ?></div>
          <div class="ps-sub"><?= $in_progress ?> in progress</div>
        </div>
        <div class="perf-stat">
          <div class="ps-label">Avg Rating</div>
          <div class="ps-val"><?= $avg_rating ?> ★</div>
          <div class="ps-sub">Out of 5.0</div>
        </div>
      </div>
    </div>
  </div>

</div><!-- /content -->

<script>
// Tab switching
function switchTab(id, btn) {
  document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  if(btn) btn.classList.add('active');
}

// Animate donut charts on load
window.addEventListener('load', () => {
  document.querySelectorAll('.donut-progress').forEach(circle => {
    const final = circle.getAttribute('stroke-dasharray');
    circle.setAttribute('stroke-dasharray', '0 314.16');
    setTimeout(() => { circle.setAttribute('stroke-dasharray', final); }, 400);
  });
});

function openVenMobNav(){document.getElementById('venMobNav').classList.add('open');document.body.style.overflow='hidden';}
function closeVenMobNav(){document.getElementById('venMobNav').classList.remove('open');document.body.style.overflow='';}

// ── NOTIFICATIONS ──────────────────────────────────────
let notifOpen = false;
function toggleNotif(e) {
    e.stopPropagation();
    notifOpen = !notifOpen;
    document.getElementById('notifDropdown').classList.toggle('open', notifOpen);
    if (notifOpen) fetchNotifications();
}
document.addEventListener('click', function(e) {
    const nd = document.getElementById('notifDropdown');
    if (nd && !nd.contains(e.target) && e.target.id !== 'notifBell') {
        notifOpen = false;
        nd.classList.remove('open');
    }
});
const typeIcon = {
    payment:['fa fa-credit-card','ni-payment'],
    complaint:['fa fa-triangle-exclamation','ni-complaint'],
    approval:['fa fa-circle-check','ni-approval'],
    rejection:['fa fa-circle-xmark','ni-rejection'],
    verification:['fa fa-shield-check','ni-verification'],
};
function timeAgo(d){const s=Math.floor((Date.now()-new Date(d))/1000);if(s<60)return'Just now';if(s<3600)return Math.floor(s/60)+'m ago';if(s<86400)return Math.floor(s/3600)+'h ago';return Math.floor(s/86400)+'d ago';}
function fetchNotifications(){
    fetch('/shivam/notification_handler.php?action=fetch')
    .then(r=>r.json()).then(data=>{
        const badge=document.getElementById('notifBadge');
        const list=document.getElementById('notifList');
        if(data.count>0){badge.textContent=data.count>99?'99+':data.count;badge.classList.add('show');}
        else badge.classList.remove('show');
        if(!data.items||!data.items.length){list.innerHTML='<div class="notif-empty"><i class="fa fa-bell-slash" style="display:block;font-size:1.4rem;margin-bottom:6px;opacity:.4"></i>No notifications yet</div>';return;}
        list.innerHTML=data.items.map(n=>{
            const[ico,cls]=typeIcon[n.type]||['fa fa-bell','ni-approval'];
            return`<a class="notif-item ${n.is_read==0?'unread':''}" href="${n.link||'#'}" onclick="markRead(${n.id},event,'${n.link||'#'}')"><div class="notif-icon ${cls}"><i class="${ico}"></i></div><div class="notif-text"><div class="notif-msg">${n.message}</div><div class="notif-time">${timeAgo(n.created_at)}</div></div></a>`;
        }).join('');
    }).catch(()=>{});
}
function markRead(id,e,link){
    e.preventDefault();
    fetch('/shivam/notification_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=mark_read&id=${id}`})
    .then(()=>{if(link&&link!=='#')window.location.href=link;else fetchNotifications();});
}
function markAllRead(){
    fetch('/shivam/notification_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=mark_read&id=0'})
    .then(()=>fetchNotifications());
}
fetchNotifications();
setInterval(fetchNotifications,30000);

</script>

</body>
</html>
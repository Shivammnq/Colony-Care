<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$role = $_SESSION['user_role'] ?? 'resident';
if ($role === 'admin') { header('Location: /admin_dashboard.php'); exit; }
if ($role !== 'accountant') { header('Location: /login.php'); exit; }

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Accountant';
$society_id = $_SESSION['user_society_id'] ?? 0;
if (!$society_id) { header('Location: /login.php'); exit; }

// DB constants loaded via config.php
$msg = $err = '';

try {
    $pdo = get_db_connection();

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['add_expense'])) {
            $cat    = trim($_POST['exp_cat']    ?? '');
            $vendor = trim($_POST['exp_vendor'] ?? '');
            $amt    = (float)($_POST['exp_amount'] ?? 0);
            $date   = trim($_POST['exp_date']   ?? date('Y-m-d'));
            $bills  = trim($_POST['exp_bill']   ?? 'attached');
            $stat   = trim($_POST['exp_status'] ?? 'paid');
            if ($cat && $amt) {
                $count = $pdo->prepare("SELECT COUNT(*) FROM expenses WHERE society_id=?");
                $count->execute([$society_id]); $count = $count->fetchColumn() + 401;
                $eno = 'EXP-' . $count;
                $pdo->prepare("INSERT INTO expenses (society_id,exp_no,category,vendor,amount,exp_date,bill_status,status) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$society_id,$eno,$cat,$vendor,$amt,$date,$bills,$stat]);
                $msg = 'Expense recorded!';
            } else { $err = 'Category and amount are required.'; }
        }

        if (isset($_POST['gen_bill'])) {
            $mon  = trim($_POST['bill_month'] ?? date('F Y'));
            $amt  = (float)($_POST['bill_amount'] ?? 0);
            $desc = trim($_POST['bill_desc'] ?? 'Monthly Maintenance');
            $due  = trim($_POST['bill_due']  ?? '');
            if ($amt && $due) {
                $yr  = date('Y'); $mo = date('m');
                $ino = 'INV-'.$yr.'-'.str_pad($mo,2,'0',STR_PAD_LEFT);
                // NOTE: billing requires a real user_id (resident being billed),
                // not the accountant. This generates a placeholder society-wide
                // invoice tied to the accountant's own account for record-keeping;
                // for per-resident billing use society.php's Generate Bill / Bulk tools.
                $pdo->prepare("INSERT INTO billing (invoice_no,user_id,society_id,unit,description,amount,month,due_date) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$ino, $user_id, $society_id, 'All', $desc, $amt, $mon, $due]);
                $msg = 'Invoice generated!';
            }
        }

        header('Location: /accountant.php'); exit;
    }

    $pdo->prepare("UPDATE billing SET status='overdue' WHERE status='pending' AND due_date < CURDATE() AND society_id=?")->execute([$society_id]);

    $bills = $pdo->prepare("SELECT * FROM billing WHERE society_id=? ORDER BY created_at DESC");
    $bills->execute([$society_id]); $bills = $bills->fetchAll();

    $expenses = $pdo->prepare("SELECT * FROM expenses WHERE society_id=? ORDER BY exp_date DESC");
    $expenses->execute([$society_id]); $expenses = $expenses->fetchAll();

    $budgets = $pdo->prepare("SELECT * FROM budget WHERE society_id=? ORDER BY id ASC");
    $budgets->execute([$society_id]); $budgets = $budgets->fetchAll();

    $total_income  = array_sum(array_map(fn($b)=>$b['amount'], $bills));
    $total_expense = array_sum(array_map(fn($e)=>$e['amount'], $expenses));
    $net_surplus   = $total_income - $total_expense;
    $gst_collected = round($total_income * 0.18);

    $billing_stats = [];
    foreach ($bills as $b) {
        $paid    = ($b['status'] === 'paid') ? $b['amount'] : round($b['amount'] * 0.82);
        $pending = $b['amount'] - $paid;
        $rate    = $b['amount'] > 0 ? round(($paid / $b['amount']) * 100) : 0;
        $billing_stats[$b['id']] = ['paid'=>$paid,'pending'=>$pending,'rate'=>$rate];
    }

} catch (PDOException $e) {
    $bills=$expenses=$budgets=$billing_stats=[];
    $total_income=$total_expense=$net_surplus=$gst_collected=0;
    $err='DB Error: '.$e->getMessage();
}

$fy = date('Y') - 1 . '-' . substr(date('Y'),2);
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Accountant Portal - ColonyCare</title>
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
  --shadow:0 1px 6px rgba(0,0,0,.07);--radius:12px;
}
body{font-family:'DM Sans',sans-serif;background:var(--white);color:var(--text-primary);min-height:100vh;}

/* TOPBAR */
.topbar{background:var(--green-dark);height:54px;display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;}
.tb-brand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:700;font-size:.98rem;}
.tb-divider{width:1px;height:20px;background:rgba(255,255,255,.2);}
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
.content{max-width:1300px;margin:0 auto;padding:28px 28px 40px;}

/* HERO */
.hero-name{font-size:1.65rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:10px;margin-bottom:5px;}
.hero-sub{font-size:.83rem;color:var(--text-muted);margin-bottom:24px;}

/* STAT CARDS */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:26px;}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:20px 22px;}
.stat-card .si{font-size:1.1rem;margin-bottom:10px;}
.si-green{color:var(--green);}.si-red{color:#dc2626;}.si-blue{color:#1d4ed8;}.si-teal{color:#0369a1;}
.stat-card h3{font-size:1.4rem;font-weight:700;color:var(--text-primary);margin-bottom:3px;letter-spacing:-.3px;}
.stat-card p{font-size:.77rem;color:var(--text-muted);}

/* TABS BAR */
.tabs-wrap{background:var(--bg);border:1px solid var(--border);border-radius:99px;padding:5px;display:flex;gap:3px;margin-bottom:26px;width:fit-content;}
.tab-btn{display:inline-flex;align-items:center;gap:7px;padding:9px 20px;font-family:inherit;font-size:.82rem;font-weight:500;color:var(--text-muted);border:none;background:none;cursor:pointer;border-radius:99px;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:var(--text-primary);}
.tab-btn.active{background:var(--white);color:var(--text-primary);font-weight:600;box-shadow:0 1px 6px rgba(0,0,0,.09);}

/* TAB SECTIONS */
.tab-section{display:none;}.tab-section.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}

/* TABLE CARD */
.table-card{border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.tc-head{padding:18px 22px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);}
.tc-head h3{font-size:1rem;font-weight:700;color:var(--text-primary);}
.btn-generate{padding:9px 18px;background:var(--green-btn);color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.btn-generate:hover{background:var(--green-hover);}

/* DATA TABLE */
.data-table{width:100%;border-collapse:collapse;}
.data-table th{font-size:.75rem;color:var(--text-muted);padding:12px 22px;text-align:left;border-bottom:1px solid var(--border);font-weight:500;background:var(--white);}
.data-table td{padding:16px 22px;font-size:.87rem;border-bottom:1px solid var(--border);color:var(--text-primary);vertical-align:middle;}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:var(--bg);}

/* BILLING SPECIFIC */
.inv-no{font-size:.85rem;color:var(--text-sub);}
.month-bold{font-weight:700;}
.amt-collected{color:var(--green);font-weight:600;}
.amt-pending{color:#dc2626;font-weight:600;}

/* PROGRESS BAR */
.prog-wrap{display:flex;align-items:center;gap:10px;}
.prog-bar{width:80px;height:6px;background:#e5ece8;border-radius:99px;overflow:hidden;}
.prog-fill{height:100%;background:var(--green);border-radius:99px;transition:width .4s;}
.prog-pct{font-size:.78rem;color:var(--text-sub);}

/* EXPENSE BADGES */
.bill-badge{padding:3px 9px;border-radius:99px;font-size:.7rem;font-weight:600;}
.bb-attached{background:var(--green-light);color:var(--green-dark);}
.bb-missing{background:#f3f4f6;color:var(--text-muted);}
.stat-badge{padding:3px 9px;border-radius:99px;font-size:.7rem;font-weight:600;}
.sb-paid{background:var(--green-light);color:var(--green-dark);}
.sb-pending{background:#fff7ed;color:#c2410c;}

/* BUDGET */
.var-under{color:var(--green);font-weight:600;}
.var-over{color:#dc2626;font-weight:600;}
.bst-under{background:var(--green-light);color:var(--green-dark);padding:3px 10px;border-radius:99px;font-size:.7rem;font-weight:600;}
.bst-over{background:#fef2f2;color:#dc2626;padding:3px 10px;border-radius:99px;font-size:.7rem;font-weight:600;}

/* EXPORT GRID */
.export-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.export-card{border:1px solid var(--border);border-radius:var(--radius);padding:24px;}
.export-card .ec-icon{font-size:1.6rem;color:var(--green);margin-bottom:14px;}
.export-card h4{font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:5px;}
.export-card .ec-desc{font-size:.8rem;color:var(--text-sub);margin-bottom:3px;}
.export-card .ec-fmt{font-size:.74rem;color:var(--text-muted);margin-bottom:16px;}
.btn-export-full{width:100%;padding:11px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:8px;transition:background .2s;}
.btn-export-full:hover{background:var(--green-hover);}

/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px 26px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .25s ease;}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal h3{font-size:1rem;font-weight:700;margin-bottom:3px;color:var(--text-primary);}
.modal p{font-size:.8rem;color:var(--text-muted);margin-bottom:16px;}
.ff{margin-bottom:12px;}.ff label{font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;color:var(--text-primary);}
.ff input,.ff select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;color:var(--text-primary);outline:none;background:#fff;transition:border-color .2s;}
.ff input:focus,.ff select:focus{border-color:var(--green);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:10px;}
.btn-cancel{padding:9px 18px;background:var(--bg);color:var(--text-sub);border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;}
.btn-primary{padding:9px 18px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
.btn-primary:hover{background:var(--green-hover);}

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
@media(max-width:1000px){.stat-row{grid-template-columns:1fr 1fr;}.export-grid{grid-template-columns:1fr 1fr;}}
@media(max-width:900px){
    .tb-portal{display:none;}.tb-divider{display:none;}.tb-back{display:none;}.tb-logout{display:none;}
    .ham-btn{display:flex;align-items:center;}
    .tabs-wrap{overflow-x:auto;flex-wrap:nowrap;border-radius:12px;width:100%;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
    .tabs-wrap::-webkit-scrollbar{display:none;}
    .tab-btn{flex-shrink:0;}
}
@media(max-width:700px){
    .stat-row{grid-template-columns:1fr 1fr;}
    .export-grid{grid-template-columns:1fr;}
    .content{padding:14px;}
    .form-row{grid-template-columns:1fr;}
    .data-table{display:block;overflow-x:auto;}
    .table-card{overflow-x:auto;}
    .modal{padding:20px 14px;}
}
@media(max-width:480px){
    .stat-row{grid-template-columns:1fr 1fr;}
    .stat-card{padding:14px 16px;}
    .stat-card h3{font-size:1.2rem;}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
  <div class="tb-brand"><i class="fa fa-building"></i> ColonyCare</div>
  <div class="tb-divider"></div>
  <span class="tb-portal">Accountant Portal</span>
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
    <a href="/index.php" class="tb-btn tb-back"><i class="fa fa-arrow-left"></i> Back</a>
    <a href="/auth/logout.php" class="tb-btn tb-logout"><i class="fa fa-right-from-bracket"></i> Logout</a>
    <button class="ham-btn" onclick="openAccMobNav()" aria-label="Menu"><i class="fa fa-bars"></i></button>
  </div>
</header>

<div class="mob-nav-overlay" id="accMobNav" onclick="if(event.target===this)closeAccMobNav()">
  <div class="mob-nav-panel">
    <div class="mob-nav-head"><span>Accountant Portal</span><button class="mob-close-btn" onclick="closeAccMobNav()"><i class="fa fa-xmark"></i></button></div>
    <div class="mob-nav-body">
      <div style="padding:10px 18px 6px;font-size:.7rem;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;"><?= htmlspecialchars($user_name) ?></div>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="switchTab('billing',null);closeAccMobNav()"><i class="fa fa-file-invoice"></i> Billing</button>
      <button class="mob-nav-item" onclick="switchTab('expenses',null);closeAccMobNav()"><i class="fa fa-clock-rotate-left"></i> Expenses</button>
      <button class="mob-nav-item" onclick="switchTab('budget',null);closeAccMobNav()"><i class="fa fa-chart-bar"></i> Budget</button>
      <button class="mob-nav-item" onclick="switchTab('export',null);closeAccMobNav()"><i class="fa fa-file-export"></i> Export</button>
    </div>
    <div class="mob-nav-foot">
      <a href="/index.php" class="mob-back-btn"><i class="fa fa-arrow-left"></i> Back to Home</a>
      <a href="/auth/logout.php" class="mob-logout-btn"><i class="fa fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</div>

<div class="content">

  <?php if($msg): ?><div class="alert alert-success"><i class="fa fa-check-circle"></i><?= htmlspecialchars($msg) ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- HERO -->
  <div class="hero-name">Accountant Portal 🗂️</div>
  <div class="hero-sub">Financial Year <?= $fy ?> &bull; <?= htmlspecialchars($user_name) ?></div>

  <!-- STAT CARDS -->
  <div class="stat-row">
    <div class="stat-card">
      <div class="si si-green"><i class="fa fa-arrow-trend-up"></i></div>
      <h3>₹<?= number_format($total_income,0) ?></h3>
      <p>Total Income (YTD)</p>
    </div>
    <div class="stat-card">
      <div class="si si-red"><i class="fa fa-arrow-trend-down"></i></div>
      <h3>₹<?= number_format($total_expense,0) ?></h3>
      <p>Total Expense (YTD)</p>
    </div>
    <div class="stat-card">
      <div class="si si-blue"><i class="fa fa-indian-rupee-sign"></i></div>
      <h3>₹<?= number_format($net_surplus,0) ?></h3>
      <p>Net Surplus</p>
    </div>
    <div class="stat-card">
      <div class="si si-teal"><i class="fa fa-receipt"></i></div>
      <h3>₹<?= number_format($gst_collected,0) ?></h3>
      <p>GST Collected</p>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs-wrap">
    <button class="tab-btn active" onclick="switchTab('billing',this)">
      <i class="fa fa-file-invoice"></i> Billing
    </button>
    <button class="tab-btn" onclick="switchTab('expenses',this)">
      <i class="fa fa-clock-rotate-left"></i> Expenses
    </button>
    <button class="tab-btn" onclick="switchTab('budget',this)">
      <i class="fa fa-chart-bar"></i> Budget
    </button>
    <button class="tab-btn" onclick="switchTab('export',this)">
      <i class="fa fa-file-export"></i> Export
    </button>
  </div>

  <!-- ══ BILLING TAB ══ -->
  <div class="tab-section active" id="tab-billing">
    <div class="table-card">
      <div class="tc-head">
        <h3>Billing &amp; Invoices</h3>
        <button class="btn-generate" onclick="openModal('billModal')">Generate Bills</button>
      </div>

      <?php if(empty($bills)): ?>
      <div class="empty-state"><i class="fa fa-file-invoice"></i><p>No invoices yet</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead>
          <tr>
            <th>Invoice</th>
            <th>Month</th>
            <th>Total</th>
            <th>Collected</th>
            <th>Pending</th>
            <th>Rate</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($bills as $b):
          $st   = $billing_stats[$b['id']] ?? ['paid'=>$b['amount'],'pending'=>0,'rate'=>100];
          $paid_amt    = $b['status']==='paid' ? $b['amount'] : $st['paid'];
          $pending_amt = $b['amount'] - $paid_amt;
          $rate        = $b['status']==='paid' ? 100 : $st['rate'];
        ?>
        <tr>
          <td><span class="inv-no"><?= htmlspecialchars($b['invoice_no'] ?: 'INV-'.$b['id']) ?></span></td>
          <td><span class="month-bold"><?= htmlspecialchars($b['month'] ?: date('M Y',strtotime($b['created_at']))) ?></span></td>
          <td>₹<?= number_format($b['amount'],0) ?></td>
          <td><span class="amt-collected">₹<?= number_format($paid_amt,0) ?></span></td>
          <td><span class="amt-pending">₹<?= number_format($pending_amt,0) ?></span></td>
          <td>
            <div class="prog-wrap">
              <div class="prog-bar"><div class="prog-fill" style="width:<?= $rate ?>%"></div></div>
              <span class="prog-pct"><?= $rate ?>%</span>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ EXPENSES TAB ══ -->
  <div class="tab-section" id="tab-expenses">
    <div class="table-card">
      <div class="tc-head">
        <h3>Expense Tracking</h3>
        <button class="btn-generate" onclick="openModal('expModal')">+ Record</button>
      </div>

      <?php if(empty($expenses)): ?>
      <div class="empty-state"><i class="fa fa-clock-rotate-left"></i><p>No expenses recorded yet</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead>
          <tr><th>ID</th><th>Category</th><th>Vendor</th><th>Amount</th><th>Date</th><th>Bill</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach($expenses as $e): ?>
        <tr>
          <td style="color:var(--text-muted);font-size:.8rem"><?= htmlspecialchars($e['exp_no']) ?></td>
          <td><?= htmlspecialchars($e['category']) ?></td>
          <td><?= htmlspecialchars($e['vendor'] ?: '—') ?></td>
          <td style="font-weight:600">₹<?= number_format($e['amount'],0) ?></td>
          <td><?= date('M d', strtotime($e['exp_date'])) ?></td>
          <td><span class="bill-badge bb-<?= $e['bill_status'] ?>"><?= ucfirst($e['bill_status']) ?></span></td>
          <td><span class="stat-badge sb-<?= $e['status'] ?>"><?= ucfirst($e['status']) ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ BUDGET TAB ══ -->
  <div class="tab-section" id="tab-budget">
    <div class="table-card">
      <div class="tc-head">
        <h3>Budget vs Actual — <?= date('M Y') ?></h3>
      </div>

      <?php if(empty($budgets)): ?>
      <div class="empty-state"><i class="fa fa-chart-bar"></i><p>No budget data yet</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead>
          <tr><th>Category</th><th>Budget</th><th>Actual</th><th>Variance</th><th>Status</th></tr>
        </thead>
        <tbody>
        <?php foreach($budgets as $b):
          $variance = (($b['actual'] - $b['budgeted']) / $b['budgeted']) * 100;
          $over     = $variance > 0;
          $varStr   = ($over ? '+' : '') . round($variance, 1) . '%';
        ?>
        <tr>
          <td style="font-weight:600"><?= htmlspecialchars($b['category']) ?></td>
          <td>₹<?= number_format($b['budgeted'],0) ?></td>
          <td>₹<?= number_format($b['actual'],0) ?></td>
          <td><span class="<?= $over?'var-over':'var-under' ?>"><?= $varStr ?></span></td>
          <td>
            <?php if($over): ?>
            <span class="bst-over">Over Budget</span>
            <?php else: ?>
            <span class="bst-under">Under Budget</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ EXPORT TAB ══ -->
  <div class="tab-section" id="tab-export">
    <div class="export-grid">

      <div class="export-card">
        <div class="ec-icon"><i class="fa fa-file-lines"></i></div>
        <h4>Income Statement</h4>
        <div class="ec-desc">Complete income breakdown</div>
        <div class="ec-fmt">Formats: PDF / Excel</div>
        <button class="btn-export-full" onclick="exportReport('income')"><i class="fa fa-download"></i> Export</button>
      </div>

      <div class="export-card">
        <div class="ec-icon"><i class="fa fa-file-lines"></i></div>
        <h4>Expense Report</h4>
        <div class="ec-desc">Category-wise expense details</div>
        <div class="ec-fmt">Formats: PDF / Excel / CSV</div>
        <button class="btn-export-full" onclick="exportReport('expense')"><i class="fa fa-download"></i> Export</button>
      </div>

      <div class="export-card">
        <div class="ec-icon"><i class="fa fa-file-lines"></i></div>
        <h4>Balance Sheet</h4>
        <div class="ec-desc">Assets, liabilities &amp; fund balance</div>
        <div class="ec-fmt">Formats: PDF</div>
        <button class="btn-export-full" onclick="exportReport('balance')"><i class="fa fa-download"></i> Export</button>
      </div>

      <div class="export-card">
        <div class="ec-icon"><i class="fa fa-file-lines"></i></div>
        <h4>Defaulter Report</h4>
        <div class="ec-desc">Residents with overdue payments</div>
        <div class="ec-fmt">Formats: PDF / Excel</div>
        <button class="btn-export-full" onclick="exportReport('defaulter')"><i class="fa fa-download"></i> Export</button>
      </div>

      <div class="export-card">
        <div class="ec-icon"><i class="fa fa-file-lines"></i></div>
        <h4>Tax Summary (GST)</h4>
        <div class="ec-desc">GST collected, TDS deducted</div>
        <div class="ec-fmt">Formats: PDF</div>
        <button class="btn-export-full" onclick="exportReport('gst')"><i class="fa fa-download"></i> Export</button>
      </div>

      <div class="export-card">
        <div class="ec-icon"><i class="fa fa-file-lines"></i></div>
        <h4>Vendor Ledger</h4>
        <div class="ec-desc">Complete vendor payment history</div>
        <div class="ec-fmt">Formats: PDF / Excel / CSV</div>
        <button class="btn-export-full" onclick="exportReport('vendor')"><i class="fa fa-download"></i> Export</button>
      </div>

    </div>
  </div>

</div><!-- /content -->

<!-- ══ MODALS ══ -->

<!-- Generate Bill Modal -->
<div class="modal-overlay" id="billModal">
  <div class="modal">
    <h3>Generate Invoice</h3>
    <p>Create a new monthly billing invoice.</p>
    <form method="POST">
      <input type="hidden" name="gen_bill" value="1">
      <div class="form-row">
        <div class="ff"><label>Month</label>
          <select name="bill_month">
            <?php for($i=0;$i<12;$i++){$d=new DateTime("first day of -$i month");echo "<option value='{$d->format('F Y')}' ".($i===0?'selected':'').">{$d->format('F Y')}</option>";}?>
          </select>
        </div>
        <div class="ff"><label>Total Amount (₹) *</label>
          <input type="number" name="bill_amount" placeholder="1242000" min="1" step="1" required>
        </div>
      </div>
      <div class="ff"><label>Description</label>
        <input type="text" name="bill_desc" value="Monthly Maintenance">
      </div>
      <div class="ff"><label>Due Date *</label>
        <input type="date" name="bill_due" value="<?= date('Y-m-t') ?>" required>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('billModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-file-invoice"></i> Generate</button>
      </div>
    </form>
  </div>
</div>

<!-- Record Expense Modal -->
<div class="modal-overlay" id="expModal">
  <div class="modal">
    <h3>Record Expense</h3>
    <p>Log a new expense entry.</p>
    <form method="POST">
      <input type="hidden" name="add_expense" value="1">
      <div class="form-row">
        <div class="ff"><label>Category *</label>
          <select name="exp_cat" required>
            <option value="">Select</option>
            <?php foreach(['Security','Gardening','Electrical','Water Supply','Lift Maintenance','Housekeeping','Repairs','Admin','Other'] as $c): ?>
            <option><?= $c ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="ff"><label>Amount (₹) *</label>
          <input type="number" name="exp_amount" placeholder="50000" min="1" required>
        </div>
      </div>
      <div class="form-row">
        <div class="ff"><label>Vendor</label>
          <input type="text" name="exp_vendor" placeholder="Vendor name">
        </div>
        <div class="ff"><label>Date *</label>
          <input type="date" name="exp_date" value="<?= date('Y-m-d') ?>" required>
        </div>
      </div>
      <div class="form-row">
        <div class="ff"><label>Bill Status</label>
          <select name="exp_bill">
            <option value="attached">Attached</option>
            <option value="missing">Missing</option>
          </select>
        </div>
        <div class="ff"><label>Payment Status</label>
          <select name="exp_status">
            <option value="paid">Paid</option>
            <option value="pending">Pending</option>
          </select>
        </div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('expModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-plus"></i> Record</button>
      </div>
    </form>
  </div>
</div>

<script>
// Tab switching
function switchTab(id, btn) {
  document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  if(btn) btn.classList.add('active');
}

// Modals
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m =>
  m.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); })
);

// Export function — generates CSV from visible data
function exportReport(type) {
  const reports = {
    income:    { title:'Income Statement',  data:'Invoice,Month,Total,Collected,Pending\n<?php foreach($bills as $b): ?><?= addslashes($b['invoice_no']??'INV-'.$b['id']) ?>,<?= addslashes($b['month']??'') ?>,<?= $b['amount'] ?>,<?= $b['status']==='paid'?$b['amount']:round($b['amount']*.82) ?>,<?= $b['status']==='paid'?0:round($b['amount']*.18) ?>\n<?php endforeach; ?>' },
    expense:   { title:'Expense Report',    data:'ID,Category,Vendor,Amount,Date,Bill,Status\n<?php foreach($expenses as $e): ?><?= $e['exp_no'] ?>,<?= $e['category'] ?>,<?= addslashes($e['vendor']??'') ?>,<?= $e['amount'] ?>,<?= $e['exp_date'] ?>,<?= $e['bill_status'] ?>,<?= $e['status'] ?>\n<?php endforeach; ?>' },
    balance:   { title:'Balance Sheet',     data:'Income,Expense,Surplus,GST\n<?= $total_income ?>,<?= $total_expense ?>,<?= $net_surplus ?>,<?= $gst_collected ?>' },
    defaulter: { title:'Defaulter Report',  data:'Invoice,Month,Pending Amount\n<?php foreach($bills as $b): ?><?php if($b['status']!=='paid'): ?><?= $b['invoice_no']??'INV-'.$b['id'] ?>,<?= $b['month'] ?>,<?= $b['amount'] ?>\n<?php endif; endforeach; ?>' },
    gst:       { title:'GST Summary',       data:'Financial Year,Total Income,GST Rate,GST Collected\n<?= $fy ?>,<?= $total_income ?>,18%,<?= $gst_collected ?>' },
    vendor:    { title:'Vendor Ledger',     data:'ID,Vendor,Category,Amount,Date,Status\n<?php foreach($expenses as $e): ?><?= $e['exp_no'] ?>,<?= addslashes($e['vendor']??'') ?>,<?= $e['category'] ?>,<?= $e['amount'] ?>,<?= $e['exp_date'] ?>,<?= $e['status'] ?>\n<?php endforeach; ?>' },
  };
  const r = reports[type];
  if (!r) return;
  const blob = new Blob([r.data], {type:'text/csv;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url; a.download = r.title.replace(/ /g,'_') + '_<?= date('Y-m-d') ?>.csv';
  document.body.appendChild(a); a.click(); document.body.removeChild(a);
}

// Animate progress bars on page load
window.addEventListener('load', () => {
  document.querySelectorAll('.prog-fill').forEach(bar => {
    const w = bar.style.width; bar.style.width = '0';
    setTimeout(() => { bar.style.width = w; }, 300);
  });
});

function openAccMobNav(){document.getElementById('accMobNav').classList.add('open');document.body.style.overflow='hidden';}
function closeAccMobNav(){document.getElementById('accMobNav').classList.remove('open');document.body.style.overflow='';}

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
    fetch('/notification_handler.php?action=fetch')
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
    fetch('/notification_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:`action=mark_read&id=${id}`})
    .then(()=>{if(link&&link!=='#')window.location.href=link;else fetchNotifications();});
}
function markAllRead(){
    fetch('/notification_handler.php',{method:'POST',headers:{'Content-Type':'application/x-www-form-urlencoded'},body:'action=mark_read&id=0'})
    .then(()=>fetchNotifications());
}
fetchNotifications();
setInterval(fetchNotifications,30000);

</script>

</body>
</html>
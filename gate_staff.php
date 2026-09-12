<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$role = $_SESSION['user_role'] ?? 'resident';
if ($role === 'admin')    { header('Location: /admin.php'); exit; }
if ($role === 'resident') { header('Location: /resident.php'); exit; }
if ($role === 'accountant') { header('Location: /accountant.php'); exit; }
if ($role === 'vendor') { header('Location: /vendor.php'); exit; }
if ($role === 'society_member') { header('Location: /society-member.php'); exit; }
if ($role !== 'staff') { header('Location: /login.php'); exit; }

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Gate Staff';
$society_id = $_SESSION['user_society_id'] ?? 0;
if (!$society_id) { header('Location: /login.php'); exit; }

// DB constants loaded via config.php
$msg = $err = '';

try {
    $pdo = get_db_connection();

    $stmtSocLookup = $pdo->prepare("SELECT society_name FROM societies WHERE id = ? LIMIT 1");
    $stmtSocLookup->execute([$society_id]);
    $page_society_name = $stmtSocLookup->fetchColumn() ?: ($_SESSION['user_society'] ?? '');

    require_once __DIR__ . '/notify_helper.php'; // ← add this

    // ── Handle POST actions
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['visitor_action']) && $_POST['visitor_action'] === 'allow') {
            $vid         = (int)$_POST['visitor_id'];
            $entered_otp = strtoupper(trim($_POST['entered_otp'] ?? ''));

            if (!$entered_otp) {
                $err = 'Please enter the OTP to allow this visitor.';
            } else {
                $vrow = $pdo->prepare("SELECT * FROM gate_visitors WHERE id = ? AND society_id = ?");
                $vrow->execute([$vid, $society_id]);
                $vrow = $vrow->fetch();

                if (!$vrow) {
                    $err = 'Visitor record not found.';
                } elseif (strtoupper(trim($vrow['otp'])) !== $entered_otp) {
                    $err = 'Incorrect OTP. Visitor entry denied. Please ask the visitor to share the correct OTP received from the resident.';
                } else {
                    $pdo->prepare("UPDATE gate_visitors SET status='approved' WHERE id=? AND society_id=?")->execute([$vid, $society_id]);
                    $pdo->prepare("UPDATE visitors SET status='approved' WHERE otp=? AND society_id=? AND status='preapproved'")->execute([$vrow['otp'], $society_id]);
                    $msg = 'OTP verified! Visitor <strong>' . htmlspecialchars($vrow['name']) . '</strong> allowed entry to Flat ' . htmlspecialchars($vrow['flat']) . '.';
                }
            }
        }

        if (isset($_POST['visitor_action']) && $_POST['visitor_action'] === 'deny') {
            $vid = (int)$_POST['visitor_id'];
            $vrow = $pdo->prepare("SELECT otp FROM gate_visitors WHERE id=? AND society_id=?");
            $vrow->execute([$vid, $society_id]);
            $vrow = $vrow->fetch();
            $pdo->prepare("UPDATE gate_visitors SET status='denied' WHERE id=? AND society_id=?")->execute([$vid, $society_id]);
            if ($vrow) {
                $pdo->prepare("UPDATE visitors SET status='denied' WHERE otp=? AND society_id=?")->execute([$vrow['otp'], $society_id]);
            }
            $msg = 'Visitor denied entry.';
        }

        if (isset($_POST['log_visitor'])) {
            $name  = trim($_POST['v_name'] ?? '');
            $type  = trim($_POST['v_type'] ?? 'Guest');
            $flat  = trim($_POST['v_flat'] ?? '');
            $phone = trim($_POST['v_phone'] ?? '');
            $pre   = isset($_POST['v_preapproved']) ? 1 : 0;
            $otp   = str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);
            if ($name && $flat) {
                $count = $pdo->prepare("SELECT COUNT(*) FROM gate_visitors WHERE society_id=?");
                $count->execute([$society_id]); $count = $count->fetchColumn() + 1;
                $vno = 'V-' . str_pad($count + 300, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO gate_visitors (society_id,visitor_no,name,type,flat,phone,otp,status,pre_approved,logged_by) VALUES (?,?,?,?,?,?,?,'waiting',?,?)")
                    ->execute([$society_id, $vno, $name, $type, $flat, $phone, $otp, $pre, $user_id]);
                $msg = "Visitor logged! OTP: <strong>$otp</strong>";
            } else { $err = 'Name and flat are required.'; }
        }

        if (isset($_POST['log_delivery'])) {
            $service   = trim($_POST['d_service'] ?? '');
            $recipient = trim($_POST['d_recipient'] ?? '');
            $otp       = str_pad(rand(1000,9999), 4, '0', STR_PAD_LEFT);
            if ($service && $recipient) {
                $count = $pdo->prepare("SELECT COUNT(*) FROM gate_deliveries WHERE society_id=?");
                $count->execute([$society_id]); $count = $count->fetchColumn() + 1;
                $dno = 'D-' . str_pad($count + 500, 3, '0', STR_PAD_LEFT);
                $pdo->prepare("INSERT INTO gate_deliveries (society_id,delivery_no,service,recipient,otp,logged_by) VALUES (?,?,?,?,?,?)")
                    ->execute([$society_id, $dno, $service, $recipient, $otp, $user_id]);
                $msg = "Delivery logged! OTP: <strong>$otp</strong>";
            }
        }

        if (isset($_POST['mark_received'])) {
            $did = (int)$_POST['delivery_id'];
            $pdo->prepare("UPDATE gate_deliveries SET status='delivered', received_at=NOW() WHERE id=? AND society_id=?")->execute([$did, $society_id]);
            $msg = 'Delivery marked as received.';
        }

        if (isset($_POST['log_vehicle'])) {
            $vno   = trim($_POST['veh_no'] ?? '');
            $owner = trim($_POST['veh_owner'] ?? '');
            $flat  = trim($_POST['veh_flat'] ?? '');
            if ($vno && $owner) {
                $pdo->prepare("INSERT INTO gate_vehicles (society_id,vehicle_no,owner,flat,logged_by) VALUES (?,?,?,?,?)")
                    ->execute([$society_id, $vno, $owner, $flat, $user_id]);
                $msg = 'Vehicle entry logged.';
            }
        }

        if (isset($_POST['vehicle_exit'])) {
            $vid = (int)$_POST['vehicle_id'];
            $pdo->prepare("UPDATE gate_vehicles SET status='exited', exit_time=NOW() WHERE id=? AND society_id=?")->execute([$vid, $society_id]);
            $msg = 'Vehicle exit logged.';
        }

        if (isset($_POST['verify_otp'])) {
            $otp = strtoupper(trim($_POST['otp_input'] ?? ''));
            if ($otp) {
                $found = $pdo->prepare("SELECT * FROM gate_visitors WHERE UPPER(otp)=? AND society_id=? ORDER BY entry_time DESC LIMIT 1");
                $found->execute([$otp, $society_id]);
                $found = $found->fetch();

                if (!$found) {
                    $found2 = $pdo->prepare("SELECT * FROM visitors WHERE UPPER(otp)=? AND society_id=? ORDER BY entry_time DESC LIMIT 1");
                    $found2->execute([$otp, $society_id]);
                    $found2 = $found2->fetch();
                    if ($found2) {
                        $_SESSION['otp_result'] = [
                            'found'=>true,'name'=>$found2['name'],'flat'=>$found2['flat_no'],
                            'type'=>$found2['type'] ?? 'Guest','status'=>$found2['status'],
                            'source'=>'resident','otp'=>$found2['otp'],
                        ];
                    } else {
                        $_SESSION['otp_result'] = ['found' => false];
                    }
                } else {
                    $_SESSION['otp_result'] = [
                        'found'=>true,'name'=>$found['name'],'flat'=>$found['flat'],
                        'type'=>$found['type'],'status'=>$found['status'],
                        'source'=>'gate','otp'=>$found['otp'],
                    ];
                }
            }
            header('Location: /gate_staff.php'); exit;
        }

        if (!$err) { header('Location: /gate_staff.php'); exit; }
    }

    $visitors   = $pdo->prepare("SELECT * FROM gate_visitors WHERE society_id=? ORDER BY entry_time DESC");
    $visitors->execute([$society_id]); $visitors = $visitors->fetchAll();

    $deliveries = $pdo->prepare("SELECT * FROM gate_deliveries WHERE society_id=? ORDER BY logged_at DESC");
    $deliveries->execute([$society_id]); $deliveries = $deliveries->fetchAll();

    $vehicles   = $pdo->prepare("SELECT * FROM gate_vehicles WHERE society_id=? ORDER BY entry_time DESC");
    $vehicles->execute([$society_id]); $vehicles = $vehicles->fetchAll();

    $visitors_today   = count(array_filter($visitors, fn($v)=>date('Y-m-d',strtotime($v['entry_time']))==date('Y-m-d')));
    $deliveries_count = count($deliveries);
    $vehicles_in      = count(array_filter($vehicles, fn($v)=>$v['status']==='inside'));
    $pending_count    = count(array_filter($visitors, fn($v)=>$v['status']==='waiting'));

    $otp_result = $_SESSION['otp_result'] ?? null;
    unset($_SESSION['otp_result']);

} catch (PDOException $e) {
    $visitors=$deliveries=$vehicles=[];
    $visitors_today=$deliveries_count=$vehicles_in=$pending_count=0;
    $err='DB Error: '.$e->getMessage();
    $otp_result=null;
    $page_society_name = $page_society_name ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Gate Staff Portal - ColonyCare</title>
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
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text-primary);min-height:100vh;}
.topbar{background:var(--green-dark);height:54px;display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;}
.tb-brand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:700;font-size:.98rem;}
.tb-divider{width:1px;height:20px;background:rgba(255,255,255,.2);}
.tb-portal{font-size:.8rem;color:rgba(255,255,255,.6);}
.tb-right{margin-left:auto;display:flex;gap:6px;}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .2s;}
.tb-back{background:rgba(255,255,255,.1);color:#fff;}.tb-back:hover{background:rgba(255,255,255,.2);}
.tb-logout{background:rgba(255,255,255,.1);color:#fff;}.tb-logout:hover{background:rgba(220,38,38,.3);}
.hero{background:var(--white);border-bottom:1px solid var(--border);padding:24px 28px 0;}
.hero-name{font-size:1.6rem;font-weight:700;color:var(--text-primary);margin-bottom:3px;display:flex;align-items:center;gap:10px;}
.hero-society{font-size:.83rem;color:var(--green);font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.hero-sub{font-size:.83rem;color:var(--text-muted);margin-bottom:20px;}
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--border);}
.stat-card{padding:20px 22px;border-right:1px solid var(--border);}
.stat-card:last-child{border-right:none;}
.stat-card .si{font-size:1rem;color:var(--green);margin-bottom:9px;}
.stat-card h3{font-size:1.65rem;font-weight:700;color:var(--text-primary);margin-bottom:2px;}
.stat-card p{font-size:.77rem;color:var(--text-muted);}
.content{max-width:1200px;margin:0 auto;padding:22px 28px;}
.tool-row{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}
.tool-card{background:var(--white);border-radius:var(--radius);border:1px solid var(--border);padding:22px 24px;}
.tool-title{font-size:.92rem;font-weight:700;color:var(--text-primary);margin-bottom:14px;display:flex;align-items:center;gap:8px;}
.tool-title i{color:var(--green);}
.otp-row{display:flex;gap:10px;align-items:center;}
.otp-input{flex:1;padding:11px 18px;border:1.5px solid var(--border);border-radius:99px;font-family:inherit;font-size:.95rem;color:var(--text-primary);outline:none;background:#f8faf9;letter-spacing:3px;transition:border-color .2s;}
.otp-input:focus{border-color:var(--green);}
.otp-input::placeholder{letter-spacing:0;color:var(--text-muted);}
.btn-verify{padding:11px 22px;background:var(--green-btn);color:#fff;border:none;border-radius:99px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:7px;transition:background .2s;}
.btn-verify:hover{background:var(--green-hover);}
.otp-result{margin-top:12px;padding:12px 14px;border-radius:9px;font-size:.83rem;line-height:1.6;}
.otp-found{background:var(--green-light);color:var(--green-dark);border:1px solid #a7d7b8;}
.otp-notfound{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.btn-scan{width:100%;padding:13px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.92rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:9px;transition:background .2s;margin-top:8px;}
.btn-scan:hover{background:var(--green-hover);}
.qr-sub{font-size:.8rem;color:var(--text-muted);margin-bottom:12px;}
.tabs-wrap{background:var(--bg);border-radius:99px;padding:6px;display:flex;gap:4px;margin-bottom:20px;border:1px solid var(--border);}
.tab-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:7px;padding:10px 16px;font-family:inherit;font-size:.82rem;font-weight:500;color:var(--text-muted);border:none;background:none;cursor:pointer;border-radius:99px;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:var(--text-primary);}
.tab-btn.active{background:var(--white);color:var(--text-primary);font-weight:600;box-shadow:0 1px 6px rgba(0,0,0,.08);}
.tab-section{display:none;}.tab-section.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.card{background:var(--white);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;}
.card-head{padding:18px 22px;display:flex;align-items:center;justify-content:space-between;}
.card-head h3{font-size:1rem;font-weight:700;color:var(--text-primary);}
.search-bar{margin:0 22px 16px;display:flex;align-items:center;gap:10px;background:var(--bg);border:1px solid var(--border);border-radius:99px;padding:9px 16px;}
.search-bar i{color:var(--text-muted);font-size:.85rem;}
.search-bar input{border:none;outline:none;font-family:inherit;font-size:.85rem;background:transparent;color:var(--text-primary);width:100%;}
.search-bar input::placeholder{color:var(--text-muted);}
.visitor-card{padding:18px 22px;border-top:1px solid var(--border);display:flex;align-items:flex-start;gap:16px;}
.vis-av{width:44px;height:44px;border-radius:50%;background:var(--green-light);color:var(--green-dark);display:flex;align-items:center;justify-content:center;font-weight:700;font-size:1rem;flex-shrink:0;}
.vis-body{flex:1;min-width:0;}
.vis-meta{display:flex;align-items:center;gap:8px;margin-bottom:4px;flex-wrap:wrap;}
.vis-id{font-size:.75rem;color:var(--text-muted);}
.vis-type{background:var(--bg);color:var(--text-sub);font-size:.72rem;font-weight:600;padding:2px 8px;border-radius:4px;border:1px solid var(--border);}
.vis-preapproved{background:#f0fdf4;color:#166534;font-size:.72rem;font-weight:600;padding:2px 8px;border-radius:4px;border:1px solid #bbf7d0;}
.vis-name{font-size:1rem;font-weight:700;color:var(--text-primary);margin-bottom:3px;}
.vis-info{font-size:.78rem;color:var(--text-muted);margin-bottom:2px;display:flex;align-items:center;gap:5px;}
.vis-otp{font-size:.85rem;font-weight:700;color:var(--green);margin-top:6px;letter-spacing:1px;}
.vis-actions{display:flex;gap:8px;margin-top:10px;flex-wrap:wrap;align-items:center;}

/* OTP input inline on the card */
.inline-otp-wrap{display:flex;align-items:center;gap:8px;background:var(--bg);border:1.5px solid var(--border);border-radius:9px;padding:6px 10px;}
.inline-otp-wrap input{border:none;outline:none;font-family:inherit;font-size:.88rem;background:transparent;color:var(--text-primary);width:110px;letter-spacing:2px;}
.inline-otp-wrap input::placeholder{letter-spacing:0;color:var(--text-muted);}
.inline-otp-wrap label{font-size:.72rem;font-weight:600;color:var(--text-muted);white-space:nowrap;}

.btn-allow{padding:7px 16px;background:var(--green-btn);color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;transition:background .2s;}
.btn-allow:hover{background:var(--green-hover);}
.btn-deny{padding:7px 16px;background:var(--white);color:#dc2626;border:1.5px solid #fecaca;border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;transition:all .2s;}
.btn-deny:hover{background:#fef2f2;}
.btn-qr{padding:7px 14px;background:var(--white);color:var(--text-sub);border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;}
.vis-right{display:flex;flex-direction:column;align-items:flex-end;gap:6px;flex-shrink:0;}
.status-pill{padding:4px 12px;border-radius:99px;font-size:.72rem;font-weight:600;}
.sp-waiting{background:#fef9c3;color:#854d0e;}
.sp-preapproved{background:var(--green-light);color:var(--green-dark);}
.sp-allowed{background:var(--green-light);color:var(--green-dark);}
.sp-denied{background:#fef2f2;color:#dc2626;}
.data-table{width:100%;border-collapse:collapse;}
.data-table th{font-size:.72rem;font-weight:600;text-transform:uppercase;letter-spacing:.5px;color:var(--text-muted);padding:12px 16px;text-align:left;border-bottom:1px solid var(--border);background:var(--bg);}
.data-table td{padding:14px 16px;font-size:.85rem;border-bottom:1px solid var(--border);color:var(--text-primary);}
.data-table tr:last-child td{border-bottom:none;}
.data-table tr:hover td{background:var(--green-soft);}
.otp-val{font-size:.88rem;font-weight:700;color:var(--green);}
.sbadge{padding:3px 10px;border-radius:99px;font-size:.7rem;font-weight:600;}
.sb-delivered{background:var(--green-light);color:var(--green-dark);}
.sb-pending{background:#fef9c3;color:#854d0e;}
.sb-inside{background:var(--green-light);color:var(--green-dark);}
.sb-exited{background:var(--bg);color:var(--text-muted);border:1px solid var(--border);}
.btn-received{padding:6px 14px;background:var(--green-btn);color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;}
.btn-exit{padding:6px 14px;background:var(--white);color:var(--text-sub);border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;}
.btn-exit:hover{border-color:#dc2626;color:#dc2626;}
.report-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:20px;}
.report-card{background:var(--bg);border-radius:10px;padding:18px 20px;border:1px solid var(--border);}
.report-card h3{font-size:1.5rem;font-weight:700;color:var(--text-primary);margin-bottom:3px;}
.report-card .rl{font-size:.78rem;font-weight:600;color:var(--text-sub);margin-bottom:4px;}
.report-card .rs{font-size:.72rem;color:var(--text-muted);}
.btn-export{padding:10px 20px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
.btn-primary{padding:9px 18px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.btn-primary:hover{background:var(--green-hover);}
.btn-log{padding:9px 18px;background:var(--green-btn);color:#fff;border:none;border-radius:99px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;}
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
.checkbox-row{display:flex;align-items:center;gap:8px;font-size:.83rem;color:var(--text-sub);}
.checkbox-row input{width:auto;}
.alert{padding:10px 14px;border-radius:9px;font-size:.83rem;margin-bottom:14px;display:flex;align-items:center;gap:8px;border:1px solid;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.empty-state{text-align:center;padding:36px 20px;color:var(--text-muted);}
.empty-state i{font-size:1.8rem;margin-bottom:8px;display:block;color:var(--border);}
/* OTP hint box on visitor card */
.otp-hint{font-size:.73rem;color:var(--text-muted);margin-top:4px;}
.otp-hint strong{color:#854d0e;}
@media(max-width:900px){.stat-row{grid-template-columns:1fr 1fr;}.stat-card{border-right:none;border-bottom:1px solid var(--border);}.tool-row{grid-template-columns:1fr;}.report-grid{grid-template-columns:1fr 1fr;}.content{padding:14px 16px;}.tabs-wrap{border-radius:12px;flex-wrap:wrap;}.hero{padding:16px 16px 0;}}
@media(max-width:600px){.stat-row{grid-template-columns:1fr 1fr;}.form-row{grid-template-columns:1fr;}.topbar{padding:0 14px;}.vis-actions{flex-direction:column;align-items:flex-start;}}
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
</style>
</head>
<body>

<header class="topbar">
  <div class="tb-brand"><i class="fa fa-building"></i> ColonyCare</div>
  <div class="tb-divider"></div>
  <span class="tb-portal">Gate Staff Portal</span>
  <div class="tb-right">
    <a href="/index.php" class="tb-btn tb-back"><i class="fa fa-arrow-left"></i> Back</a>
    <!-- NOTIFICATION BELL -->
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
    <a href="/auth/logout.php" class="tb-btn tb-logout"><i class="fa fa-right-from-bracket"></i> Logout</a>
  </div>
</header>

<div class="hero">
  <div class="hero-name">Gate Management 🚧</div>
  <?php if($page_society_name): ?>
  <div class="hero-society"><i class="fa fa-building"></i> <?= htmlspecialchars($page_society_name) ?></div>
  <?php endif; ?>
  <div class="hero-sub"><?= date('l, d F Y') ?> &bull; Gate Staff &bull; <?= htmlspecialchars($user_name) ?></div>
  <div class="stat-row">
    <div class="stat-card"><div class="si"><i class="fa fa-users"></i></div><h3><?= $visitors_today ?></h3><p>Visitors Today</p></div>
    <div class="stat-card"><div class="si"><i class="fa fa-truck"></i></div><h3><?= $deliveries_count ?></h3><p>Deliveries</p></div>
    <div class="stat-card"><div class="si"><i class="fa fa-car"></i></div><h3><?= $vehicles_in ?></h3><p>Vehicles In</p></div>
    <div class="stat-card"><div class="si"><i class="fa fa-clock"></i></div><h3><?= $pending_count ?></h3><p>Pending</p></div>
  </div>
</div>

<div class="content">

  <?php if($msg): ?><div class="alert alert-success"><i class="fa fa-check-circle"></i><?= $msg ?></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <!-- OTP + QR ROW -->
  <div class="tool-row">
    <div class="tool-card">
      <div class="tool-title"><i class="fa fa-key"></i> Quick OTP Lookup</div>
      <form method="POST">
        <input type="hidden" name="verify_otp" value="1">
        <div class="otp-row">
          <input class="otp-input" type="text" name="otp_input" maxlength="6" placeholder="Enter OTP to look up..." required>
          <button type="submit" class="btn-verify"><i class="fa fa-magnifying-glass"></i> Lookup</button>
        </div>
      </form>
      <?php if($otp_result !== null): ?>
      <div class="otp-result <?= $otp_result['found']?'otp-found':'otp-notfound' ?>">
        <?php if($otp_result['found']): ?>
          <div><i class="fa fa-circle-check"></i> <strong><?= htmlspecialchars($otp_result['name']) ?></strong></div>
          <div style="margin-top:4px;font-size:.78rem;">
            <?= htmlspecialchars($otp_result['type']) ?> &nbsp;→&nbsp; Flat <strong><?= htmlspecialchars($otp_result['flat']) ?></strong>
            &nbsp;·&nbsp; Status: <strong><?= ucfirst($otp_result['status']) ?></strong>
            &nbsp;·&nbsp; Source: <?= $otp_result['source']==='resident'?'<span style="color:#166534;font-weight:600;">Resident Pre-registered</span>':'Gate logged' ?>
          </div>
        <?php else: ?>
          <i class="fa fa-circle-xmark"></i> No visitor found with this OTP.
        <?php endif; ?>
      </div>
      <?php endif; ?>
    </div>
    <div class="tool-card">
      <div class="tool-title"><i class="fa fa-qrcode"></i> QR Scanner</div>
      <p class="qr-sub">Scan visitor or resident QR code at the gate</p>
      <button class="btn-scan" onclick="alert('QR Scanner requires camera access. Connect a QR scanner device.')">
        <i class="fa fa-qrcode"></i> Scan QR Code
      </button>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs-wrap">
    <button class="tab-btn active" onclick="switchTab('visitors',this)"><i class="fa fa-users"></i> Visitors</button>
    <button class="tab-btn"        onclick="switchTab('deliveries',this)"><i class="fa fa-truck"></i> Deliveries</button>
    <button class="tab-btn"        onclick="switchTab('vehicles',this)"><i class="fa fa-car"></i> Vehicles</button>
    <button class="tab-btn"        onclick="switchTab('report',this)"><i class="fa fa-circle-dot"></i> Report</button>
  </div>

  <!-- ══ VISITORS TAB ══ -->
  <div class="tab-section active" id="tab-visitors">
    <div class="card">
      <div class="card-head">
        <h3>Visitor Queue</h3>
        <button class="btn-log" onclick="openModal('visModal')"><i class="fa fa-plus"></i> Log Visitor</button>
      </div>
      <div class="search-bar">
        <i class="fa fa-search"></i>
        <input type="text" id="visSearch" placeholder="Search by name, flat, or ID..." oninput="filterVisitors()">
      </div>

      <?php if(empty($visitors)): ?>
      <div class="empty-state"><i class="fa fa-users"></i><p>No visitors logged yet</p></div>
      <?php else: foreach($visitors as $v):
        $init = strtoupper(substr($v['name'],0,1)).strtoupper(substr(strstr($v['name'].' ',' '),0,1));
        $sp   = match($v['status']){'approved'=>'sp-allowed','denied'=>'sp-denied','waiting'=>($v['pre_approved']?'sp-preapproved':'sp-waiting'), default=>'sp-waiting'};
        $slabel = $v['status']==='waiting' ? ($v['pre_approved']?'Pre-Approved':'Waiting') : ucfirst($v['status']);
      ?>
      <div class="visitor-card" data-name="<?= strtolower($v['name']) ?>" data-flat="<?= strtolower($v['flat']) ?>" data-id="<?= strtolower($v['visitor_no']) ?>">
        <div class="vis-av"><?= htmlspecialchars($init) ?></div>
        <div class="vis-body">
          <div class="vis-meta">
            <span class="vis-id"><?= htmlspecialchars($v['visitor_no']) ?></span>
            <span class="vis-type"><?= htmlspecialchars($v['type']) ?></span>
            <?php if($v['pre_approved']): ?><span class="vis-preapproved"><i class="fa fa-shield-check"></i> Pre-approved</span><?php endif; ?>
          </div>
          <div class="vis-name"><?= htmlspecialchars($v['name']) ?></div>
          <div class="vis-info"><i class="fa fa-location-dot"></i> To: <?= htmlspecialchars($v['flat']) ?> &bull; <?= date('h:i A', strtotime($v['entry_time'])) ?></div>
          <?php if($v['phone']): ?><div class="vis-info"><i class="fa fa-phone"></i> <?= htmlspecialchars($v['phone']) ?></div><?php endif; ?>
          <div class="vis-otp"><i class="fa fa-key" style="font-size:.75rem;"></i> OTP: <?= htmlspecialchars($v['otp']) ?></div>

          <?php if($v['status']==='waiting'): ?>
          <div class="vis-actions">
            <!-- Allow requires OTP entry -->
            <form method="POST" style="display:contents" onsubmit="return checkOtpFilled(this)">
              <input type="hidden" name="visitor_id" value="<?= $v['id'] ?>">
              <input type="hidden" name="visitor_action" value="allow">
              <div class="inline-otp-wrap">
                <label>Visitor's OTP</label>
                <input type="text" name="entered_otp" maxlength="6" placeholder="Enter OTP" autocomplete="off">
              </div>
              <button type="submit" class="btn-allow"><i class="fa fa-right-to-bracket"></i> Allow</button>
            </form>
            <!-- Deny does not need OTP -->
            <form method="POST" style="display:contents">
              <input type="hidden" name="visitor_id" value="<?= $v['id'] ?>">
              <input type="hidden" name="visitor_action" value="deny">
              <button type="submit" class="btn-deny" onclick="return confirm('Deny entry for <?= htmlspecialchars(addslashes($v['name'])) ?>?')">
                <i class="fa fa-circle-xmark"></i> Deny
              </button>
            </form>
          </div>
          <div class="otp-hint">Ask the visitor for their OTP received from the resident. Enter it above and click <strong>Allow</strong>.</div>
          <?php endif; ?>
        </div>
        <div class="vis-right">
          <span class="status-pill <?= $sp ?>"><?= $slabel ?></span>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ══ DELIVERIES TAB ══ -->
  <div class="tab-section" id="tab-deliveries">
    <div class="card">
      <div class="card-head">
        <h3>Delivery Logs</h3>
        <button class="btn-log" onclick="openModal('delModal')"><i class="fa fa-plus"></i> Log Delivery</button>
      </div>
      <?php if(empty($deliveries)): ?>
      <div class="empty-state"><i class="fa fa-truck"></i><p>No deliveries logged yet</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>ID</th><th>Service</th><th>Recipient</th><th>Time</th><th>OTP</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($deliveries as $d): ?>
        <tr>
          <td><?= htmlspecialchars($d['delivery_no']) ?></td>
          <td><?= htmlspecialchars($d['service']) ?></td>
          <td><?= htmlspecialchars($d['recipient']) ?></td>
          <td><?= date('h:i A', strtotime($d['logged_at'])) ?></td>
          <td><span class="otp-val"><?= htmlspecialchars($d['otp']) ?></span></td>
          <td><span class="sbadge sb-<?= $d['status'] ?>"><?= ucfirst($d['status']) ?></span></td>
          <td>
            <?php if($d['status']==='pending'): ?>
            <form method="POST">
              <input type="hidden" name="mark_received" value="1">
              <input type="hidden" name="delivery_id" value="<?= $d['id'] ?>">
              <button type="submit" class="btn-received">Received</button>
            </form>
            <?php else: ?><span style="color:var(--text-muted);font-size:.78rem">—</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ VEHICLES TAB ══ -->
  <div class="tab-section" id="tab-vehicles">
    <div class="card">
      <div class="card-head">
        <h3>Vehicle Tracking</h3>
        <button class="btn-log" onclick="openModal('vehModal')"><i class="fa fa-plus"></i> Log Vehicle</button>
      </div>
      <?php if(empty($vehicles)): ?>
      <div class="empty-state"><i class="fa fa-car"></i><p>No vehicles logged yet</p></div>
      <?php else: ?>
      <table class="data-table">
        <thead><tr><th>Vehicle No.</th><th>Owner</th><th>Flat</th><th>Entry</th><th>Exit</th><th>Status</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($vehicles as $v): ?>
        <tr>
          <td><strong><?= htmlspecialchars($v['vehicle_no']) ?></strong></td>
          <td><?= htmlspecialchars($v['owner']) ?></td>
          <td><?= htmlspecialchars($v['flat']??'—') ?></td>
          <td><?= date('h:i A', strtotime($v['entry_time'])) ?></td>
          <td><?= $v['exit_time'] ? date('h:i A', strtotime($v['exit_time'])) : '—' ?></td>
          <td><span class="sbadge sb-<?= $v['status'] ?>"><?= ucfirst($v['status']) ?></span></td>
          <td>
            <?php if($v['status']==='inside'): ?>
            <form method="POST">
              <input type="hidden" name="vehicle_exit" value="1">
              <input type="hidden" name="vehicle_id" value="<?= $v['id'] ?>">
              <button type="submit" class="btn-exit"><i class="fa fa-right-from-bracket"></i> Exit</button>
            </form>
            <?php else: ?><span style="color:var(--text-muted);font-size:.78rem">Exited</span><?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

  <!-- ══ REPORT TAB ══ -->
  <div class="tab-section" id="tab-report">
    <div class="card" style="padding:22px 24px">
      <h3 style="font-size:1rem;font-weight:700;margin-bottom:18px">Daily Entry Report — <?= date('M d, Y') ?></h3>
      <div class="report-grid">
        <div class="report-card">
          <h3><?= $visitors_today ?></h3>
          <div class="rl">Total Visitors</div>
          <div class="rs"><?= count(array_filter($visitors,fn($v)=>$v['status']==='allowed')) ?> approved, <?= count(array_filter($visitors,fn($v)=>$v['status']==='denied')) ?> denied</div>
        </div>
        <div class="report-card">
          <h3><?= $deliveries_count ?></h3>
          <div class="rl">Deliveries</div>
          <div class="rs"><?= implode(', ', array_unique(array_slice(array_column($deliveries,'service'),0,3))) ?>...</div>
        </div>
        <div class="report-card">
          <h3><?= count($vehicles) ?></h3>
          <div class="rl">Vehicles Entered</div>
          <div class="rs"><?= count(array_filter($vehicles,fn($v)=>$v['status']==='exited')) ?> exited</div>
        </div>
        <div class="report-card">
          <h3><?= $pending_count ?></h3>
          <div class="rl">Pending Actions</div>
          <div class="rs">Awaiting approval</div>
        </div>
      </div>
      <button class="btn-export" onclick="window.print()"><i class="fa fa-download"></i> Export Report</button>
    </div>
  </div>

</div>

<!-- ══ MODALS ══ -->
<div class="modal-overlay" id="visModal">
  <div class="modal">
    <h3>Log New Visitor</h3>
    <p>Enter visitor details. OTP will be auto-generated.</p>
    <form method="POST">
      <input type="hidden" name="log_visitor" value="1">
      <div class="form-row">
        <div class="ff"><label>Visitor Name *</label><input type="text" name="v_name" placeholder="Full name" required></div>
        <div class="ff"><label>Type</label>
          <select name="v_type"><option>Guest</option><option>Delivery</option><option>Service</option><option>Cab</option><option>Other</option></select>
        </div>
      </div>
      <div class="form-row">
        <div class="ff"><label>Flat / Unit *</label><input type="text" name="v_flat" placeholder="e.g. 201-A" required></div>
        <div class="ff"><label>Phone</label><input type="text" name="v_phone" placeholder="98765 43210"></div>
      </div>
      <div class="ff"><label class="checkbox-row"><input type="checkbox" name="v_preapproved"> Pre-approved by resident</label></div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('visModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-right-to-bracket"></i> Log Visitor</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="delModal">
  <div class="modal">
    <h3>Log Delivery</h3>
    <p>Enter delivery details. OTP will be auto-generated.</p>
    <form method="POST">
      <input type="hidden" name="log_delivery" value="1">
      <div class="form-row">
        <div class="ff"><label>Service *</label>
          <select name="d_service"><option>Amazon</option><option>Flipkart</option><option>Swiggy</option><option>Zomato</option><option>BigBasket</option><option>Blinkit</option><option>Meesho</option><option>Other</option></select>
        </div>
        <div class="ff"><label>Recipient Flat *</label><input type="text" name="d_recipient" placeholder="e.g. Flat 201-A" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('delModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-truck"></i> Log Delivery</button>
      </div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="vehModal">
  <div class="modal">
    <h3>Log Vehicle Entry</h3>
    <p>Record vehicle entering the society.</p>
    <form method="POST">
      <input type="hidden" name="log_vehicle" value="1">
      <div class="form-row">
        <div class="ff"><label>Vehicle No. *</label><input type="text" name="veh_no" placeholder="e.g. DL-4C-1234" required></div>
        <div class="ff"><label>Owner Name *</label><input type="text" name="veh_owner" placeholder="Owner / Guest" required></div>
      </div>
      <div class="ff"><label>Flat (if resident)</label><input type="text" name="veh_flat" placeholder="e.g. 101-A"></div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('vehModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-car"></i> Log Entry</button>
      </div>
    </form>
  </div>
</div>

<script>
function switchTab(id, btn) {
  document.querySelectorAll('.tab-section').forEach(s => s.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b => b.classList.remove('active'));
  document.getElementById('tab-' + id).classList.add('active');
  if(btn) btn.classList.add('active');
}
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-overlay').forEach(m => m.addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); }));

function filterVisitors() {
  const q = document.getElementById('visSearch').value.toLowerCase();
  document.querySelectorAll('.visitor-card').forEach(card => {
    const match = card.dataset.name.includes(q) || card.dataset.flat.includes(q) || card.dataset.id.includes(q);
    card.style.display = match ? '' : 'none';
  });
}

// Prevent Allow submission if OTP field is empty
function checkOtpFilled(form) {
  const otpInput = form.querySelector('input[name="entered_otp"]');
  if (!otpInput.value.trim()) {
    otpInput.style.borderColor = '#dc2626';
    otpInput.focus();
    otpInput.placeholder = 'OTP required!';
    setTimeout(() => { otpInput.style.borderColor = ''; otpInput.placeholder = 'Enter OTP'; }, 2000);
    return false;
  }
  return true;
}

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
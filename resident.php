<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: /shivam/login.php'); exit; }
$role = $_SESSION['user_role'] ?? 'resident';
if ($role === 'admin') { header('Location: /shivam/login.php'); exit; }
if ($role !== 'resident') { session_destroy(); header('Location: /shivam/login.php'); exit; }

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Resident';
if (!($_SESSION['user_society_id'] ?? 0)) { header('Location: /shivam/login.php'); exit; }

define('DB_HOST','localhost'); define('DB_NAME','cc'); define('DB_USER','root'); define('DB_PASS','');
$msg = $err = '';
if (!empty($_SESSION['res_flash_msg'])) { $msg = $_SESSION['res_flash_msg']; unset($_SESSION['res_flash_msg']); }
$redirectTab = null;

try {
    $pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,[
        PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
    ]);

    require_once __DIR__ . '/notify_helper.php';

    // ── Ensure amenities & facility_bookings tables exist ────────
    $pdo->exec("CREATE TABLE IF NOT EXISTS amenities (
        id INT AUTO_INCREMENT PRIMARY KEY,
        society_id INT NOT NULL,
        name VARCHAR(100) NOT NULL,
        rate DECIMAL(10,2) DEFAULT 0,
        rate_unit VARCHAR(20) DEFAULT 'Free',
        capacity INT DEFAULT NULL,
        features VARCHAR(255) DEFAULT NULL,
        status ENUM('available','maintenance') DEFAULT 'available',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_society (society_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS facility_bookings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        society_id INT NOT NULL,
        amenity_id INT NOT NULL,
        user_id INT NOT NULL,
        booking_date DATE NOT NULL,
        start_time TIME NOT NULL,
        end_time TIME NOT NULL,
        status ENUM('confirmed','cancelled') DEFAULT 'confirmed',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_amenity (amenity_id),
        INDEX idx_society (society_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Fetch primary unit from users table ─────────────────────────
    $userRow = $pdo->prepare("SELECT * FROM users WHERE id=? LIMIT 1");
    $userRow->execute([$user_id]); $userRow = $userRow->fetch();

    // ── Fetch all approved extra units ───────────────────────────────
    $extraUnits = $pdo->prepare("
        SELECT uu.*, s.society_name
        FROM user_units uu
        JOIN societies s ON s.id = uu.society_id
        WHERE uu.user_id=? AND uu.is_active=1
        ORDER BY uu.requested_at ASC
    ");
    $extraUnits->execute([$user_id]); $extraUnits = $extraUnits->fetchAll();

    // ── Fetch pending unit requests ──────────────────────────────────
    $pendingUnits = $pdo->prepare("
        SELECT uu.*, s.society_name
        FROM user_units uu
        JOIN societies s ON s.id = uu.society_id
        WHERE uu.user_id=? AND uu.is_active=0
        ORDER BY uu.requested_at ASC
    ");
    $pendingUnits->execute([$user_id]); $pendingUnits = $pendingUnits->fetchAll();

    // ── Resolve active unit context ──────────────────────────────────
    $active_unit_id = $_SESSION['active_unit_id'] ?? 'primary';

    if ($active_unit_id === 'primary') {
        $active_society_id   = $userRow['society_id'];
        $active_unit         = $userRow['unit'];
        $active_block        = $userRow['block'];
        $active_society_name = $userRow['society'];
    } else {
        $activeRow = $pdo->prepare("
            SELECT uu.*, s.society_name
            FROM user_units uu
            JOIN societies s ON s.id = uu.society_id
            WHERE uu.id=? AND uu.user_id=? AND uu.is_active=1
        ");
        $activeRow->execute([$active_unit_id, $user_id]);
        $activeRow = $activeRow->fetch();

        if (!$activeRow) {
            // Unit no longer valid — fall back to primary
            $_SESSION['active_unit_id'] = 'primary';
            $active_society_id   = $userRow['society_id'];
            $active_unit         = $userRow['unit'];
            $active_block        = $userRow['block'];
            $active_society_name = $userRow['society'];
        } else {
            $active_society_id   = $activeRow['society_id'];
            $active_unit         = $activeRow['unit'];
            $active_block        = $activeRow['block'];
            $active_society_name = $activeRow['society_name'];
        }
    }

    // ── All societies list for Add Unit dropdown ─────────────────────
    $allSocieties = $pdo->query("SELECT id, society_name FROM societies ORDER BY society_name ASC")->fetchAll();

    // ── Handle POST ──────────────────────────────────────────────────
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Switch active unit
        if (isset($_POST['switch_unit'])) {
            $switch_to = trim($_POST['unit_id'] ?? 'primary');
            if ($switch_to === 'primary') {
                $_SESSION['active_unit_id'] = 'primary';
            } else {
                $chk = $pdo->prepare("SELECT id FROM user_units WHERE id=? AND user_id=? AND is_active=1");
                $chk->execute([(int)$switch_to, $user_id]);
                if ($chk->fetch()) {
                    $_SESSION['active_unit_id'] = (int)$switch_to;
                }
            }
            header('Location: /shivam/resident.php'); exit;
        }

        // Add new unit request
        if (isset($_POST['add_unit'])) {
            $req_soc  = (int)($_POST['req_society_id'] ?? 0);
            $req_unit = trim($_POST['req_unit']  ?? '');
            $req_blk  = trim($_POST['req_block'] ?? '');

            if ($req_soc && $req_unit) {
                // Can't request primary society again
                if ($req_soc === (int)$userRow['society_id'] && $req_unit === $userRow['unit']) {
                    $err = 'This is already your primary unit.';
                } else {
                    try {
                        $pdo->prepare("INSERT INTO user_units (user_id,society_id,unit,block,is_active) VALUES (?,?,?,?,0)")
                            ->execute([$user_id, $req_soc, $req_unit, $req_blk]);
                        // Notify that society's owner + admins
                        $admins = get_owner_and_admins($pdo, $req_soc);
                        notify($pdo, $req_soc, $admins, $user_id, 'approval',
                            $user_name . ' requested to link Flat ' . $req_unit . ' in your society.',
                            '/shivam/society.php#tab-approvals'
                        );
                        $msg = 'Unit request submitted! Waiting for that society\'s approval.';
                    } catch (PDOException $ex) {
                        $err = 'You have already requested this unit.';
                    }
                }
            } else { $err = 'Society and unit number are required.'; }
        }

        // Remove a linked unit
        if (isset($_POST['remove_unit'])) {
            $rid = (int)$_POST['unit_row_id'];
            $pdo->prepare("DELETE FROM user_units WHERE id=? AND user_id=?")->execute([$rid, $user_id]);
            // If removing the currently active unit, fall back to primary
            if ($_SESSION['active_unit_id'] == $rid) {
                $_SESSION['active_unit_id'] = 'primary';
            }
            $msg = 'Unit removed.';
        }

        if (isset($_POST['add_complaint'])) {
            $subj = trim($_POST['subject']    ?? '');
            $cat  = trim($_POST['category']   ?? '');
            $desc = trim($_POST['description']?? '');
            $pri  = trim($_POST['priority']   ?? 'medium');
            $unit = trim($_POST['unit']       ?? $active_unit);
            if ($subj && $cat) {
                $pdo->prepare("INSERT INTO complaints (society_id,user_id,unit,category,subject,description,priority) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$active_society_id, $user_id, $unit, $cat, $subj, $desc, $pri]);
                $admins = get_owner_and_admins($pdo, $active_society_id);
                notify($pdo, $active_society_id, $admins, $user_id, 'complaint',
                    $user_name . ' filed a complaint: ' . mb_substr($subj, 0, 60),
                    '/shivam/society.php#tab-complaints'
                );
                $msg = 'Complaint submitted!';
            } else { $err = 'Subject and category required.'; }
        }

        if (isset($_POST['mark_read'])) {
            $nid = (int)$_POST['notice_id'];
            $pdo->prepare("INSERT IGNORE INTO notice_reads (user_id,notice_id) VALUES (?,?)")->execute([$user_id, $nid]);
        }

        if (isset($_POST['pay_bill'])) {
            $bid     = (int)$_POST['bill_id'];
            $hasFile = !empty($_FILES['payment_screenshot']['name']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK;
            if (!$hasFile) {
                $err = 'A payment screenshot is required.';
            } else {
                $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
                $ftype   = mime_content_type($_FILES['payment_screenshot']['tmp_name']);
                $fsize   = $_FILES['payment_screenshot']['size'];
                if (!in_array($ftype, $allowed)) {
                    $err = 'Invalid file type. Please upload a JPG, PNG, WEBP or GIF image.';
                } elseif ($fsize > 5*1024*1024) {
                    $err = 'Screenshot too large. Maximum 5 MB.';
                } else {
                    $upDir = __DIR__.'/uploads/payment_proofs/';
                    if (!is_dir($upDir)) mkdir($upDir, 0755, true);
                    $ext   = pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION);
                    $fname = 'pay_'.$bid.'_'.$user_id.'_'.time().'.'.$ext;
                    if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $upDir.$fname)) {
                        $txnRef  = trim($_POST['txn_ref'] ?? '');
                        $billRow = $pdo->prepare("SELECT amount FROM billing WHERE id=? AND society_id=?");
                        $billRow->execute([$bid, $active_society_id]);
                        $b_amount = $billRow->fetchColumn() ?: 0;
                        $pdo->prepare("UPDATE billing SET status='pending_verification', payment_proof=?, txn_ref=? WHERE id=? AND user_id=? AND society_id=?")
                            ->execute([$fname, $txnRef, $bid, $user_id, $active_society_id]);
                        $admins = get_owner_and_admins($pdo, $active_society_id);
                        notify($pdo, $active_society_id, $admins, $user_id, 'payment',
                            $user_name . ' submitted payment proof for ₹' . number_format($b_amount, 0),
                            '/shivam/society.php#tab-maintenance'
                        );
                        $msg = 'Payment submitted! The society owner/admin will verify shortly.';
                    } else { $err = 'Failed to save screenshot. Please try again.'; }
                }
            }
        }

        if (isset($_POST['add_visitor'])) {
            $vn  = trim($_POST['visitor_name']    ?? '');
            $vp  = trim($_POST['visitor_purpose'] ?? '');
            $vph = trim($_POST['visitor_phone']   ?? '');
            $vf  = trim($_POST['visitor_flat']    ?? $active_unit);
            $vt  = trim($_POST['visitor_type']    ?? 'Guest');
            $otp = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 6));
            if ($vn && $vf) {
                $pdo->prepare("INSERT INTO visitors (society_id,user_id,name,purpose,phone,flat_no,type,otp,status) VALUES (?,?,?,?,?,?,?,?,'preapproved')")
                    ->execute([$active_society_id, $user_id, $vn, $vp, $vph, $vf, $vt, $otp]);
                $visitor_no = 'VIS-' . date('Ymd') . '-' . strtoupper(substr($otp, 0, 4));
                $pdo->prepare("INSERT INTO gate_visitors (society_id,visitor_no,name,type,flat,phone,otp,status,pre_approved,entry_time,logged_by) VALUES (?,?,?,?,?,?,?,'waiting',1,NOW(),?)")
                    ->execute([$active_society_id, $visitor_no, $vn, $vt, $vf, $vph, $otp, $user_id]);
                $msg = "Visitor pre-registered! OTP: <strong>$otp</strong>";
            }
        }

        if (isset($_POST['book_facility'])) {
            $amenityId = (int)($_POST['amenity_id'] ?? 0);
            $bDate     = trim($_POST['booking_date'] ?? '');
            $bStart    = trim($_POST['start_time'] ?? '');
            $bEnd      = trim($_POST['end_time'] ?? '');

            $today = date('Y-m-d');
            if (!$amenityId || !$bDate || !$bStart || !$bEnd) {
                $err = 'Please fill in all booking fields.';
            } elseif ($bDate < $today) {
                $err = 'You cannot book a facility in the past.';
            } elseif ($bStart >= $bEnd) {
                $err = 'End time must be after start time.';
            } else {
                $amChk = $pdo->prepare("SELECT * FROM amenities WHERE id=? AND society_id=?");
                $amChk->execute([$amenityId, $active_society_id]);
                $amenity = $amChk->fetch();

                if (!$amenity) {
                    $err = 'Facility not found.';
                } elseif ($amenity['status'] === 'maintenance') {
                    $err = 'This facility is currently under maintenance and cannot be booked.';
                } else {
                    // Overlap check: any confirmed booking on the same date whose time range intersects
                    $overlapChk = $pdo->prepare("
                        SELECT id FROM facility_bookings
                        WHERE amenity_id=? AND booking_date=? AND status='confirmed'
                          AND start_time < ? AND end_time > ?
                        LIMIT 1
                    ");
                    $overlapChk->execute([$amenityId, $bDate, $bEnd, $bStart]);

                    if ($overlapChk->fetch()) {
                        $err = 'That time slot overlaps with an existing booking. Please choose a different time.';
                    } else {
                        $pdo->prepare("INSERT INTO facility_bookings (society_id,amenity_id,user_id,booking_date,start_time,end_time) VALUES (?,?,?,?,?,?)")
                            ->execute([$active_society_id, $amenityId, $user_id, $bDate, $bStart, $bEnd]);

                        $admins = get_owner_and_admins($pdo, $active_society_id);
                        notify($pdo, $active_society_id, $admins, $user_id, 'approval',
                            $user_name . ' booked ' . $amenity['name'] . ' on ' . date('d M', strtotime($bDate)) . ' (' . substr($bStart,0,5) . '-' . substr($bEnd,0,5) . ').',
                            '/shivam/admin_dashboard.php#tab-facilities'
                        );
                        $msg = 'Facility booked successfully!';
                    }
                }
            }
        }

        if (isset($_POST['cancel_my_booking'])) {
            $bid = (int)($_POST['booking_id'] ?? 0);
            $pdo->prepare("UPDATE facility_bookings SET status='cancelled' WHERE id=? AND user_id=? AND society_id=?")
                ->execute([$bid, $user_id, $active_society_id]);
            $msg = 'Booking cancelled.';
        }

        // ── Profile: personal details ─────────────────────────────
        if (isset($_POST['save_personal_details'])) {
            $p_name  = trim($_POST['name']  ?? '');
            $p_phone = trim($_POST['phone'] ?? '');

            if (empty($p_name)) {
                $err = 'Name is required.';
            } else {
                $pdo->prepare("UPDATE users SET name = ?, phone = ? WHERE id = ?")
                    ->execute([$p_name, $p_phone, $user_id]);
                $_SESSION['user_name'] = $p_name;
                $redirectTab = 'profile';
                $_SESSION['res_flash_msg'] = 'Personal details updated.';
            }
        }

        // ── Profile: email ─────────────────────────────────────────
        if (isset($_POST['save_email'])) {
            $p_email = trim($_POST['email'] ?? '');

            if (!filter_var($p_email, FILTER_VALIDATE_EMAIL)) {
                $err = 'Please enter a valid email address.';
            } else {
                $dupe = $pdo->prepare("SELECT COUNT(*) FROM users WHERE email = ? AND id != ?");
                $dupe->execute([$p_email, $user_id]);
                if ($dupe->fetchColumn() > 0) {
                    $err = 'That email is already in use by another account.';
                } else {
                    $pdo->prepare("UPDATE users SET email = ? WHERE id = ?")->execute([$p_email, $user_id]);
                    $redirectTab = 'profile';
                    $_SESSION['res_flash_msg'] = 'Email updated.';
                }
            }
        }

        // ── Profile: password change (current password required) ───
        if (isset($_POST['change_password'])) {
            $p_current = $_POST['current_password'] ?? '';
            $p_new     = $_POST['new_password'] ?? '';
            $p_confirm = $_POST['confirm_password'] ?? '';

            if (!$userRow['password'] || !password_verify($p_current, $userRow['password'])) {
                $err = 'Current password is incorrect.';
            } elseif (strlen($p_new) < 8) {
                $err = 'New password must be at least 8 characters.';
            } elseif ($p_new !== $p_confirm) {
                $err = 'New password and confirmation do not match.';
            } else {
                $newHash = password_hash($p_new, PASSWORD_DEFAULT);
                $pdo->prepare("UPDATE users SET password = ? WHERE id = ?")->execute([$newHash, $user_id]);
                $redirectTab = 'profile';
                $_SESSION['res_flash_msg'] = 'Password changed successfully.';
            }
        }

        if (!$err) {
            $loc = '/shivam/resident.php' . ($redirectTab ? ('?tab=' . urlencode($redirectTab)) : '');
            header('Location: ' . $loc); exit;
        }
    }

    // ── Fetch data for active unit context ───────────────────────────
    $pdo->prepare("UPDATE billing SET status='overdue' WHERE status='pending' AND due_date < CURDATE() AND society_id=?")
        ->execute([$active_society_id]);

    $ns = $pdo->prepare("SELECT n.*, IF(nr.notice_id IS NULL,1,0) as is_unread FROM notices n LEFT JOIN notice_reads nr ON nr.notice_id=n.id AND nr.user_id=? WHERE n.society_id=? ORDER BY n.created_at DESC");
    $ns->execute([$user_id, $active_society_id]); $notices = $ns->fetchAll();
    $unread_count = count(array_filter($notices, fn($n)=>$n['is_unread']));

    $cs = $pdo->prepare("SELECT * FROM complaints WHERE user_id=? AND society_id=? ORDER BY created_at DESC");
    $cs->execute([$user_id, $active_society_id]); $complaints = $cs->fetchAll();
    $open_complaints = count(array_filter($complaints, fn($c)=>$c['status']==='open'));

    $bs = $pdo->prepare("SELECT * FROM billing WHERE user_id=? AND society_id=? ORDER BY created_at DESC");
    $bs->execute([$user_id, $active_society_id]); $bills = $bs->fetchAll();
    $pending_dues = array_sum(array_map(fn($b)=>in_array($b['status'],['pending','overdue'])?$b['amount']:0, $bills));

    $events = $pdo->prepare("SELECT * FROM society_events WHERE society_id=? AND event_date>=CURDATE() ORDER BY event_date ASC LIMIT 5");
    $events->execute([$active_society_id]); $events = $events->fetchAll();
    $upcoming_events = count($events);

    $vs = $pdo->prepare("SELECT id,name,purpose,phone,flat_no,type,otp,status,entry_time FROM visitors WHERE user_id=? AND society_id=? ORDER BY entry_time DESC LIMIT 20");
    $vs->execute([$user_id, $active_society_id]); $visitors = $vs->fetchAll();

    // ── Amenities + upcoming bookings (for live status) + my own bookings ──
    $amenities_list = $pdo->prepare("SELECT * FROM amenities WHERE society_id=? ORDER BY name ASC");
    $amenities_list->execute([$active_society_id]); $amenities_list = $amenities_list->fetchAll();

    $bookings_by_amenity = [];
    if ($amenities_list) {
        $bkStmt = $pdo->prepare("
            SELECT * FROM facility_bookings
            WHERE society_id=? AND status='confirmed'
              AND (booking_date > CURDATE() OR (booking_date = CURDATE() AND end_time >= CURTIME()))
            ORDER BY booking_date ASC, start_time ASC
        ");
        $bkStmt->execute([$active_society_id]);
        foreach ($bkStmt->fetchAll() as $b) { $bookings_by_amenity[$b['amenity_id']][] = $b; }
    }

    $my_bookings = $pdo->prepare("
        SELECT fb.*, a.name AS amenity_name
        FROM facility_bookings fb
        JOIN amenities a ON a.id = fb.amenity_id
        WHERE fb.user_id=? AND fb.society_id=? AND fb.status='confirmed'
          AND (fb.booking_date > CURDATE() OR (fb.booking_date = CURDATE() AND fb.end_time >= CURTIME()))
        ORDER BY fb.booking_date ASC, fb.start_time ASC
    ");
    $my_bookings->execute([$user_id, $active_society_id]); $my_bookings = $my_bookings->fetchAll();

    $directory = $pdo->prepare("SELECT name,unit,block,phone,role FROM users WHERE is_active=1 AND society_id=? ORDER BY name ASC");
    $directory->execute([$active_society_id]); $directory = $directory->fetchAll();

} catch(PDOException $e) {
    $notices=$complaints=$bills=$events=$visitors=$directory=$extraUnits=$pendingUnits=$allSocieties=$amenities_list=$bookings_by_amenity=$my_bookings=[];
    $unread_count=$open_complaints=$pending_dues=$upcoming_events=0;
    $err='DB: '.$e->getMessage();
    $active_society_id = $_SESSION['user_society_id'] ?? 0;
    $active_unit = $active_block = $active_society_name = '';
    $page_society_name = '';
    $userRow = [];
}
$page_society_name = $page_society_name ?? $active_society_name ?? '';
$categories=['Plumbing','Electrical','Lift/Elevator','Parking','Security','Housekeeping','Noise','Water Supply','Common Area','Other'];
$colors=['#2d7a52','#1d4ed8','#6d28d9','#c2410c','#0369a1','#b45309','#be123c'];
?>

<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Resident Portal - ColonyCare</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--green:#2d7a52;--green-dark:#1a5c3a;--green-btn:#1a7a52;--green-hover:#145f3f;--green-light:#e8f5ee;--green-soft:#f0f9f4;--text-primary:#1a2e22;--text-sub:#5a7060;--text-muted:#8fa898;--border:#e5ece8;--bg:#f5f7f6;--white:#fff;--shadow:0 1px 6px rgba(0,0,0,.07);--shadow-md:0 4px 20px rgba(0,0,0,.10);--radius:12px;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text-primary);min-height:100vh;}
/* TOPBAR */
.topbar{background:linear-gradient(135deg,#0b835b,#248f7d,#267373);height:56px;display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;}
.topbar-brand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:700;font-size:.98rem;}
.tb-divider{width:1px;height:20px;background:rgba(255,255,255,.2);}
.tb-portal{font-size:.8rem;color:rgba(255,255,255,.6);}
.tb-right{margin-left:auto;display:flex;gap:6px;}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .2s;}
.tb-back{background:rgba(255,255,255,.1);color:#fff;}.tb-back:hover{background:rgba(255,255,255,.2);}
.tb-logout{background:rgba(255,255,255,.1);color:#fff;}.tb-logout:hover{background:rgba(220,38,38,.3);}
/* HERO */
.hero{background:var(--white);border-bottom:1px solid var(--border);padding:22px 28px 0;}
.hero-name{font-size:1.55rem;font-weight:700;color:var(--text-primary);margin-bottom:3px;}
.hero-society{font-size:.83rem;color:var(--green);font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.hero-sub{font-size:.83rem;color:var(--text-muted);margin-bottom:18px;}
/* STAT ROW */
.stat-row{display:grid;grid-template-columns:repeat(4,1fr);border-top:1px solid var(--border);}
.stat-card{padding:17px 20px;border-right:1px solid var(--border);position:relative;}
.stat-card:last-child{border-right:none;}
.stat-card .si{font-size:.9rem;color:var(--green);margin-bottom:7px;}
.stat-card h3{font-size:1.5rem;font-weight:700;color:var(--text-primary);margin-bottom:2px;}
.stat-card p{font-size:.76rem;color:var(--text-muted);}
.all-clear{position:absolute;top:13px;right:13px;background:var(--green-light);color:var(--green);font-size:.62rem;font-weight:700;padding:2px 8px;border-radius:99px;}
/* TABS */
.tabs-bar{background:var(--white);border-bottom:1px solid var(--border);padding:0 200px;display:flex;overflow-x:auto;scrollbar-width:none;}
.tabs-bar::-webkit-scrollbar{display:none;}
.tab-btn{display:inline-flex;align-items:center;gap:6px;padding:13px 15px;font-family:inherit;font-size:.8rem;font-weight:500;color:var(--text-muted);border:none;background:none;cursor:pointer;border-bottom:2px solid transparent;white-space:nowrap;transition:all .2s;}
.tab-btn:hover{color:var(--text-primary);}
.tab-btn.active{color:var(--green);border-bottom-color:var(--green);font-weight:600;}
/* CONTENT */
.content{max-width:1100px;margin:0 auto;padding:22px 28px;}
.tab-section{display:none;}.tab-section.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
/* CARD */
.card{background:var(--white);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;margin-bottom:16px;}
.card-head{padding:15px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.card-head h3{font-size:.92rem;font-weight:700;color:var(--text-primary);}
.badge-cnt{background:var(--green-light);color:var(--green);font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:99px;}

/* NOTICE */
.notice-item{display:flex;align-items:center;padding:14px 20px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;gap:12px;}
.notice-item:last-child{border-bottom:none;}.notice-item:hover{background:var(--bg);}
.n-dot{width:7px;height:7px;border-radius:50%;background:var(--green);flex-shrink:0;opacity:0;}.n-dot.unread{opacity:1;}
.n-info{flex:1;min-width:0;}
.n-title{font-size:.86rem;font-weight:600;color:var(--text-primary);margin-bottom:2px;}.n-title.read{font-weight:400;color:var(--text-sub);}
.n-date{font-size:.73rem;color:var(--text-muted);}
.pri-pill{padding:2px 9px;border-radius:99px;font-size:.68rem;font-weight:600;flex-shrink:0;}
.pp-high{background:#fef2f2;color:#dc2626;}.pp-medium{background:#fff7ed;color:#c2410c;}.pp-low{background:var(--bg);color:var(--text-muted);border:1px solid var(--border);}

/* COMPLAINT */
.comp-item{display:flex;align-items:flex-start;padding:15px 20px;border-bottom:1px solid var(--border);gap:12px;}.comp-item:last-child{border-bottom:none;}
.comp-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
.ci-open{background:#fef2f2;color:#dc2626;}.ci-progress{background:#fff7ed;color:#c2410c;}.ci-resolved{background:var(--green-light);color:var(--green);}
.comp-body{flex:1;min-width:0;}
.comp-title{font-size:.86rem;font-weight:600;color:var(--text-primary);margin-bottom:3px;display:flex;align-items:center;gap:7px;flex-wrap:wrap;}
.comp-meta{font-size:.73rem;color:var(--text-muted);}
.sbadge{padding:2px 8px;border-radius:99px;font-size:.67rem;font-weight:600;}
.sb-open{background:#fef2f2;color:#dc2626;}.sb-in_progress{background:#fff7ed;color:#c2410c;}.sb-resolved{background:var(--green-light);color:var(--green-dark);}.sb-closed{background:var(--bg);color:var(--text-muted);}

/* BILL */
.bill-item{display:flex;align-items:center;padding:15px 20px;border-bottom:1px solid var(--border);gap:12px;}.bill-item:last-child{border-bottom:none;}
.bill-ico{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
.bi-pending{background:#fff7ed;color:#c2410c;}.bi-paid{background:var(--green-light);color:var(--green);}.bi-overdue{background:#fef2f2;color:#dc2626;}
.bill-body{flex:1;min-width:0;}
.bill-title{font-size:.86rem;font-weight:600;color:var(--text-primary);margin-bottom:2px;}
.bill-meta{font-size:.73rem;color:var(--text-muted);}
.bill-right{text-align:right;flex-shrink:0;}
.bill-amt{font-size:1rem;font-weight:700;display:block;margin-bottom:6px;}
.ba-pending{color:#c2410c;}.ba-overdue{color:#dc2626;}.ba-paid{color:#16a34a;}

/* ── NOTIFICATION BELL ─────────────────────────────── */
.notif-wrap{position:relative;display:inline-flex;}
.notif-bell{background:rgba(255,255,255,.1);border:none;cursor:pointer;color:#fff;width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:background .2s;position:relative;}
.notif-bell:hover{background:rgba(255,255,255,.2);}
.notif-badge{position:absolute;top:-4px;right:-4px;background:#ef4444;color:#fff;font-size:.6rem;font-weight:700;min-width:16px;height:16px;border-radius:99px;display:flex;align-items:center;justify-content:center;padding:0 3px;display:none;}
.notif-badge.show{display:flex;}
.notif-dropdown{position:absolute;top:calc(100% + 8px);right:0;width:320px;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:999;display:none;overflow:hidden;}
.notif-dropdown.open{display:block;}
.notif-head{display:flex;align-items:center;justify-content:space-between;padding:12px 16px;border-bottom:1px solid var(--border);}
.notif-head span{font-weight:700;font-size:.9rem;color:var(--text);}
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
.notif-footer{padding:10px 16px;text-align:center;border-top:1px solid var(--border);}

/* EVENT */
.event-item{display:flex;align-items:center;gap:13px;padding:13px 20px;border-bottom:1px solid var(--border);}.event-item:last-child{border-bottom:none;}
.ev-date{width:44px;height:44px;border-radius:10px;background:var(--green-light);color:var(--green-dark);display:flex;flex-direction:column;align-items:center;justify-content:center;flex-shrink:0;font-weight:700;line-height:1.1;}
.ev-date .day{font-size:1rem;}.ev-date .mon{font-size:.56rem;text-transform:uppercase;}
.ev-info h4{font-size:.86rem;font-weight:600;margin-bottom:2px;}.ev-info span{font-size:.73rem;color:var(--text-muted);}
/* VISITOR */
.vis-item{display:flex;align-items:center;gap:12px;padding:13px 20px;border-bottom:1px solid var(--border);}.vis-item:last-child{border-bottom:none;}
.vis-av{width:36px;height:36px;border-radius:9px;background:var(--green-light);color:var(--green);display:flex;align-items:center;justify-content:center;font-weight:700;flex-shrink:0;}
.vis-body h4{font-size:.86rem;font-weight:600;margin-bottom:2px;}.vis-body span{font-size:.73rem;color:var(--text-muted);}
.vis-status{margin-left:auto;}
/* DIR */
.dir-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(190px,1fr));gap:12px;padding:16px;}
.dir-card{border:1px solid var(--border);border-radius:10px;padding:15px;text-align:center;}
.dir-av{width:44px;height:44px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:1.05rem;margin:0 auto 9px;}
.dir-name{font-size:.83rem;font-weight:700;color:var(--text-primary);margin-bottom:2px;}
.dir-unit{font-size:.7rem;color:var(--text-muted);}
/* BUTTONS */
.btn-primary{padding:8px 16px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:6px;transition:background .2s;}
.btn-primary:hover{background:var(--green-hover);}
.btn-pay{padding:5px 12px;background:var(--green-btn);color:#fff;border:none;border-radius:8px;font-family:inherit;font-size:.76rem;font-weight:600;cursor:pointer;transition:background .2s;}
.btn-pay:hover{background:var(--green-hover);}
/* MODAL */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px 26px;width:100%;max-width:460px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .25s ease;}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal h3{font-size:1rem;font-weight:700;margin-bottom:3px;}.modal p{font-size:.8rem;color:var(--text-muted);margin-bottom:16px;}
.ff{margin-bottom:12px;}.ff label{font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;}
.ff input,.ff select,.ff textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;color:var(--text-primary);outline:none;background:#fff;transition:border-color .2s;}
.ff input:focus,.ff select:focus,.ff textarea:focus{border-color:var(--green);}
.ff textarea{resize:vertical;min-height:70px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:6px;}
.btn-cancel{padding:8px 16px;background:var(--bg);color:var(--text-sub);border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.83rem;font-weight:600;cursor:pointer;}
/* ALERT */
.alert{padding:10px 13px;border-radius:9px;font-size:.82rem;margin-bottom:14px;display:flex;align-items:center;gap:8px;border:1px solid;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
/* EMPTY */
.empty-state{text-align:center;padding:38px 20px;color:var(--text-muted);}
.empty-state i{font-size:1.9rem;margin-bottom:9px;display:block;color:var(--border);}
.empty-state h4{font-size:.88rem;font-weight:600;color:var(--text-sub);}

/* ── FACILITIES / AMENITIES ── */
.amenity-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(250px,1fr));gap:16px;}
.amenity-card{background:var(--white,#fff);border:1px solid var(--border);border-radius:14px;padding:18px 20px;}
.am-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
.am-head h4{font-size:1rem;font-weight:700;color:var(--text-primary);}
.am-badge{font-size:.68rem;font-weight:700;padding:4px 10px;border-radius:99px;white-space:nowrap;}
.am-available{background:#dcfce7;color:#166534;}
.am-booked{background:#fef9c3;color:#92400e;}
.am-maintenance{background:#fee2e2;color:#dc2626;}
.am-row{display:flex;justify-content:space-between;font-size:.82rem;color:var(--text-sub);padding:4px 0;}
.am-row strong{color:var(--text-primary);}
.am-features{font-size:.76rem;color:var(--text-muted);margin:6px 0 14px;}
.am-btn-book,.am-btn-view,.am-btn-unavailable{width:100%;padding:11px;border:none;border-radius:10px;font-family:inherit;font-size:.87rem;font-weight:700;cursor:pointer;}
.am-btn-book{background:var(--green-btn);color:#fff;}
.am-btn-view{background:#a7d7c5;color:#fff;}
.am-btn-unavailable{background:#a7d7c5;color:#fff;opacity:.6;cursor:not-allowed;}
/* Top action bar */
.tab-action{display:flex;justify-content:flex-end;margin-bottom:12px;}
/* PAYMENT MODAL EXTRAS */
.pay-info-box{background:var(--green-soft);border:1px solid #c3e6d4;border-radius:10px;padding:12px 14px;margin-bottom:14px;font-size:.8rem;color:var(--text-sub);}
.pay-info-box .pay-info-title{font-weight:700;color:var(--green);margin-bottom:6px;font-size:.82rem;}
.pay-info-box .pay-row{display:flex;justify-content:space-between;align-items:center;padding:3px 0;}
.pay-info-box .pay-row strong{color:var(--text-primary);}
.drop-zone{border:2px dashed var(--border);border-radius:12px;padding:28px 16px;text-align:center;cursor:pointer;transition:border-color .2s,background .2s;background:var(--bg);position:relative;}
.drop-zone:hover{border-color:var(--green);background:var(--green-soft);}
.drop-zone.drag-over{border-color:var(--green);background:var(--green-soft);}
.drop-zone.has-file{border-color:var(--green);background:var(--green-soft);border-style:solid;}
.drop-zone .drop-icon{font-size:2rem;color:var(--text-muted);display:block;margin-bottom:8px;}
.drop-zone .drop-label{font-size:.84rem;font-weight:600;color:var(--text-sub);}
.drop-zone .drop-hint{font-size:.73rem;color:var(--text-muted);margin-top:3px;}
.preview-wrap{display:none;}
.preview-wrap img{max-width:100%;max-height:180px;border-radius:8px;object-fit:contain;border:1px solid var(--border);}
.preview-name{font-size:.75rem;color:var(--text-muted);margin-top:6px;}
.btn-remove-file{margin-top:7px;background:#fef2f2;color:#dc2626;border:none;border-radius:7px;padding:5px 13px;font-size:.75rem;font-weight:600;cursor:pointer;font-family:inherit;display:inline-flex;align-items:center;gap:5px;}
.file-error{color:#dc2626;font-size:.75rem;margin-top:5px;display:none;}
.file-error.show{display:flex;align-items:center;gap:5px;}
.req-star{color:#dc2626;}
.pay-amount-badge{display:inline-block;background:var(--green-light);color:var(--green-dark);font-weight:700;padding:3px 10px;border-radius:8px;font-size:.85rem;margin-left:6px;}
/* ── HAMBURGER ───────────────────────────────────────── */
.ham-btn{display:none;background:none;border:none;cursor:pointer;color:#fff;font-size:1.25rem;padding:6px 8px;border-radius:8px;margin-left:8px;flex-shrink:0;}
.ham-btn:hover{background:rgba(255,255,255,.15);}
.mob-nav-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);}
.mob-nav-overlay.open{display:block;}
.mob-nav-panel{position:absolute;top:0;right:0;width:260px;height:100%;background:linear-gradient(160deg,#0b835b,#1a5c3a);display:flex;flex-direction:column;animation:slideRight .25s ease;overflow-y:auto;}
@keyframes slideRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
.mob-nav-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid rgba(255,255,255,.12);}
.mob-nav-head span{color:#fff;font-weight:700;font-size:.92rem;}
.mob-close-btn{background:none;border:none;color:rgba(255,255,255,.7);font-size:1.1rem;cursor:pointer;padding:4px 8px;border-radius:6px;}
.mob-close-btn:hover{background:rgba(255,255,255,.1);}
.mob-nav-body{flex:1;padding:8px 0;}
.mob-nav-item{display:flex;align-items:center;gap:12px;padding:13px 18px;color:rgba(255,255,255,.85);font-size:.88rem;font-weight:500;cursor:pointer;transition:background .15s;border:none;background:none;width:100%;text-align:left;font-family:inherit;text-decoration:none;}
.mob-nav-item:hover{background:rgba(255,255,255,.1);color:#fff;}
.mob-nav-item i{width:16px;text-align:center;}
.mob-nav-divider{height:1px;background:rgba(255,255,255,.1);margin:6px 18px;}
.mob-nav-foot{padding:14px 18px;border-top:1px solid rgba(255,255,255,.12);display:flex;flex-direction:column;gap:8px;}
.mob-nav-foot a{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border-radius:9px;font-size:.85rem;font-weight:600;text-decoration:none;transition:.2s;}
.mob-back-btn{background:rgba(255,255,255,.12);color:#fff;border:1px solid rgba(255,255,255,.2);}
.mob-back-btn:hover{background:rgba(255,255,255,.2);}
.mob-logout-btn{background:#dc2626;color:#fff;}
.mob-logout-btn:hover{background:#b91c1c;}
@media(max-width:1024px){.content{padding:16px 20px;}.tabs-bar{padding:0 20px;}}
@media(max-width:768px){
.topbar{padding:0 14px;gap:8px;}
.tb-portal{display:none;}.tb-divider{display:none;}.tb-back{display:none;}.tb-logout{display:none;}
.ham-btn{display:flex;align-items:center;}
#unitSwitcherWrap .notif-bell span{max-width:80px;}
.hero{padding:14px 14px 0;}.hero-name{font-size:1.2rem;}
.stat-row{grid-template-columns:1fr 1fr;}
.stat-card{border-right:none;border-bottom:1px solid var(--border);}
.stat-card:nth-child(odd){border-right:1px solid var(--border);}
.stat-card:last-child{border-bottom:none;}
.stat-card h3{font-size:1.3rem;}
.tabs-bar{padding:0 8px;overflow-x:auto;flex-wrap:nowrap;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
.tabs-bar::-webkit-scrollbar{display:none;}
.tab-btn{padding:11px 10px;font-size:.75rem;flex-shrink:0;}
.content{padding:12px 12px;}
.card-head{padding:12px 14px;flex-wrap:wrap;gap:8px;}
.notice-item{padding:12px 14px;}
.comp-item{padding:12px 14px;}
.bill-item{padding:12px 14px;flex-wrap:wrap;gap:8px;}
.bill-right{width:100%;display:flex;align-items:center;justify-content:space-between;text-align:left;}
.bill-amt{font-size:.9rem;display:inline;}
.event-item{padding:11px 14px;}
.vis-item{padding:11px 14px;}
.dir-grid{grid-template-columns:repeat(2,1fr);padding:12px;}
.form-row{grid-template-columns:1fr;}
.modal{padding:20px 16px;max-width:100%;}
.modal-overlay{padding:12px;}
.notif-dropdown{width:280px;right:-60px;}
#unitDropdown{width:240px;}
}
@media(max-width:480px){
.topbar-brand{font-size:.85rem;}
.hero-name{font-size:1.05rem;}
.stat-card{padding:13px 14px;}.stat-card h3{font-size:1.1rem;}
.tab-btn{padding:10px 8px;font-size:.72rem;}
.dir-grid{grid-template-columns:1fr;}
.notif-dropdown{right:-80px;width:260px;}
#unitDropdown{width:220px;right:0;}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="topbar-brand"><i class="fa fa-building"></i> ColonyCare</div>
  <div class="tb-divider"></div>
  <span class="tb-portal">Resident Portal</span>
  <div class="tb-right">

    <!-- UNIT SWITCHER -->
    <div class="notif-wrap" id="unitSwitcherWrap">
        <button class="notif-bell" onclick="toggleUnitSwitcher(event)" title="Switch Unit" style="width:auto;padding:0 12px;gap:6px;font-size:.78rem;">
            <i class="fa fa-building"></i>
            <span style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                <?= htmlspecialchars($active_unit ? $active_unit . ' · ' . $active_society_name : $active_society_name) ?>
            </span>
            <i class="fa fa-chevron-down" style="font-size:.6rem;opacity:.7"></i>
        </button>
        <div class="notif-dropdown" id="unitDropdown" style="width:280px;">
            <div class="notif-head">
                <span>My Units</span>
                <button class="notif-mark-all" onclick="openModal('addUnitModal')">+ Add Unit</button>
            </div>
            <div class="notif-list">
                <!-- Primary unit -->
                <form method="POST">
                    <input type="hidden" name="switch_unit" value="1">
                    <input type="hidden" name="unit_id" value="primary">
                    <button type="submit" class="notif-item <?= ($_SESSION['active_unit_id']??'primary')==='primary'?'unread':'' ?>" style="width:100%;text-align:left;border:none;background:none;cursor:pointer;">
                        <div class="notif-icon ni-approval"><i class="fa fa-house"></i></div>
                        <div class="notif-text">
                            <div class="notif-msg"><?= htmlspecialchars($userRow['unit']??'—') ?> <?= $userRow['block']?'· '.htmlspecialchars($userRow['block']):'' ?></div>
                            <div class="notif-time"><?= htmlspecialchars($userRow['society']??'Primary') ?> · Primary</div>
                        </div>
                        <?php if(($_SESSION['active_unit_id']??'primary')==='primary'): ?>
                        <i class="fa fa-circle-check" style="color:var(--green);font-size:.85rem;margin-left:auto;align-self:center;"></i>
                        <?php endif; ?>
                    </button>
                </form>

                <!-- Extra approved units -->
                <?php foreach($extraUnits as $eu): ?>
                <form method="POST" style="display:contents">
                    <input type="hidden" name="switch_unit" value="1">
                    <input type="hidden" name="unit_id" value="<?= $eu['id'] ?>">
                    <button type="submit" class="notif-item <?= ($_SESSION['active_unit_id']??'primary')==$eu['id']?'unread':'' ?>" style="width:100%;text-align:left;border:none;background:none;cursor:pointer;">
                        <div class="notif-icon ni-payment"><i class="fa fa-building"></i></div>
                        <div class="notif-text">
                            <div class="notif-msg"><?= htmlspecialchars($eu['unit']) ?> <?= $eu['block']?'· '.htmlspecialchars($eu['block']):'' ?></div>
                            <div class="notif-time"><?= htmlspecialchars($eu['society_name']) ?></div>
                        </div>
                        <?php if(($_SESSION['active_unit_id']??'primary')==$eu['id']): ?>
                        <i class="fa fa-circle-check" style="color:var(--green);font-size:.85rem;margin-left:auto;align-self:center;"></i>
                        <?php endif; ?>
                    </button>
                </form>
                <?php endforeach; ?>

                <!-- Pending requests -->
                <?php foreach($pendingUnits as $pu): ?>
                <div class="notif-item" style="opacity:.6;cursor:default;">
                    <div class="notif-icon ni-complaint"><i class="fa fa-hourglass-half"></i></div>
                    <div class="notif-text">
                        <div class="notif-msg"><?= htmlspecialchars($pu['unit']) ?> · <?= htmlspecialchars($pu['society_name']) ?></div>
                        <div class="notif-time">Pending approval</div>
                    </div>
                    <form method="POST" style="margin-left:auto;align-self:center;">
                        <input type="hidden" name="remove_unit" value="1">
                        <input type="hidden" name="unit_row_id" value="<?= $pu['id'] ?>">
                        <button type="submit" style="border:none;background:none;color:#dc2626;cursor:pointer;font-size:.75rem;" title="Cancel request"><i class="fa fa-xmark"></i></button>
                    </form>
                </div>
                <?php endforeach; ?>

                <?php if(empty($extraUnits) && empty($pendingUnits)): ?>
                <div class="notif-empty" style="padding:16px;">No extra units linked yet.<br><small>Click "+ Add Unit" to link another flat.</small></div>
                <?php endif; ?>
            </div>
            <!-- Remove approved units -->
            <?php if(!empty($extraUnits)): ?>
            <div style="padding:10px 16px;border-top:1px solid var(--border);">
                <?php foreach($extraUnits as $eu): ?>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="remove_unit" value="1">
                    <input type="hidden" name="unit_row_id" value="<?= $eu['id'] ?>">
                    <button type="submit" style="font-size:.72rem;color:#dc2626;background:none;border:none;cursor:pointer;padding:2px 6px;" onclick="return confirm('Remove <?= htmlspecialchars($eu['unit']) ?> from your account?')">
                        <i class="fa fa-trash"></i> Remove <?= htmlspecialchars($eu['unit']) ?>
                    </button>
                </form>
                <?php endforeach; ?>
            </div>
            <?php endif; ?>
        </div>
    </div>


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
    <a href="/shivam/index.php" class="tb-btn tb-back"><i class="fa fa-arrow-left"></i> Back</a>
    <a href="/shivam/auth/logout.php" class="tb-btn tb-logout"><i class="fa fa-right-from-bracket"></i> Logout</a>
    <button class="ham-btn" onclick="openResidentMobNav()" aria-label="Menu"><i class="fa fa-bars"></i></button>
  </div>
</header>

<!-- MOBILE NAV -->
<div class="mob-nav-overlay" id="resMobNav" onclick="closeResidentMobNavBg(event)">
  <div class="mob-nav-panel">
    <div class="mob-nav-head">
      <span>Resident Portal</span>
      <button class="mob-close-btn" onclick="closeResidentMobNav()"><i class="fa fa-xmark"></i></button>
    </div>
    <div class="mob-nav-body">
      <div style="padding:10px 18px 6px;font-size:.7rem;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;"><?= htmlspecialchars($user_name) ?></div>
      <div style="padding:2px 18px 10px;font-size:.75rem;color:rgba(255,255,255,.5);"><?= htmlspecialchars($active_unit?$active_unit.' · '.$active_society_name:$active_society_name) ?></div>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="switchTab('notices',null);closeResidentMobNav()"><i class="fa fa-bell"></i> Notices</button>
      <button class="mob-nav-item" onclick="switchTab('complaints',null);closeResidentMobNav()"><i class="fa fa-comments"></i> Complaints</button>
      <button class="mob-nav-item" onclick="switchTab('payments',null);closeResidentMobNav()"><i class="fa fa-credit-card"></i> Payments</button>
      <button class="mob-nav-item" onclick="switchTab('visitors',null);closeResidentMobNav()"><i class="fa fa-user-check"></i> Visitors</button>
      <button class="mob-nav-item" onclick="switchTab('directory',null);closeResidentMobNav()"><i class="fa fa-users"></i> Directory</button>
      <button class="mob-nav-item" onclick="switchTab('events',null);closeResidentMobNav()"><i class="fa fa-calendar"></i> Events</button>
      <button class="mob-nav-item" onclick="switchTab('profile',null);closeResidentMobNav()"><i class="fa fa-user-gear"></i> Profile</button>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="openModal('addUnitModal');closeResidentMobNav()"><i class="fa fa-building"></i> Add Unit</button>
    </div>
    <div class="mob-nav-foot">
      <a href="/shivam/index.php" class="mob-back-btn"><i class="fa fa-arrow-left"></i> Back to Home</a>
      <a href="/shivam/auth/logout.php" class="mob-logout-btn"><i class="fa fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</div>

<div class="hero">
  <div class="hero-name">Welcome back, <?= htmlspecialchars(explode(' ',$user_name)[0]) ?> 👋</div>
  <?php if($page_society_name): ?>
  <div class="hero-society"><i class="fa fa-building"></i> <?= htmlspecialchars($page_society_name) ?></div>
  <?php endif; ?>
  <div class="hero-sub"><?= date('l, d F Y') ?> &bull; <?= ucfirst($role) ?></div>
  <div class="stat-row">
    <div class="stat-card">
      <div class="si"><i class="fa fa-credit-card"></i></div>
      <?php if($pending_dues==0): ?><span class="all-clear">All Clear</span><?php endif; ?>
      <h3>₹<?= number_format($pending_dues,0) ?></h3><p>Pending Dues</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-comments"></i></div>
      <h3><?= $open_complaints ?></h3><p>Open Complaints</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-calendar"></i></div>
      <h3><?= $upcoming_events ?></h3><p>Upcoming Events</p>
    </div>
    <div class="stat-card">
      <div class="si"><i class="fa fa-bell"></i></div>
      <h3><?= $unread_count ?></h3><p>Unread Notices</p>
    </div>
  </div>
</div>

<div class="tabs-bar" id="tabsBar">
  <button class="tab-btn active" data-tab="notices"    onclick="switchTab('notices',this)"><i class="fa fa-bell"></i> Notices</button>
  <button class="tab-btn"        data-tab="complaints" onclick="switchTab('complaints',this)"><i class="fa fa-comments"></i> Complaints</button>
  <button class="tab-btn"        data-tab="directory"  onclick="switchTab('directory',this)"><i class="fa fa-users"></i> Directory</button>
  <button class="tab-btn"        data-tab="facilities" onclick="switchTab('facilities',this)"><i class="fa fa-building"></i> Facilities</button>
  <button class="tab-btn"        data-tab="payments"   onclick="switchTab('payments',this)"><i class="fa fa-credit-card"></i> Payments</button>
  <button class="tab-btn"        data-tab="visitors"   onclick="switchTab('visitors',this)"><i class="fa fa-id-badge"></i> Visitors</button>
  <button class="tab-btn"        data-tab="access"     onclick="switchTab('access',this)"><i class="fa fa-key"></i> Access</button>
  <button class="tab-btn"        data-tab="polls"      onclick="switchTab('polls',this)"><i class="fa fa-square-poll-horizontal"></i> Polls</button>
  <button class="tab-btn"        data-tab="events"     onclick="switchTab('events',this)"><i class="fa fa-calendar-days"></i> Events</button>
  <button class="tab-btn"        data-tab="market"     onclick="switchTab('market',this)"><i class="fa fa-store"></i> Market</button>
  <button class="tab-btn"        data-tab="role"       onclick="switchTab('role',this)"><i class="fa fa-user-tag"></i> Role Request</button>
  <button class="tab-btn"        data-tab="profile"    onclick="switchTab('profile',this)"><i class="fa fa-user-gear"></i> Profile</button>
</div>

<div class="content">
<?php if($msg): ?><div class="alert alert-success"><i class="fa fa-check-circle"></i><?= $msg /* may contain safe HTML like OTP */ ?></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- NOTICES -->
<div class="tab-section active" id="tab-notices">
  <div class="card">
    <div class="card-head"><h3>Announcements &amp; Notices</h3><?php if($unread_count>0): ?><span class="badge-cnt"><?= $unread_count ?> unread</span><?php endif; ?></div>
    <?php if(empty($notices)): ?><div class="empty-state"><i class="fa fa-bell"></i><h4>No notices yet</h4></div>
    <?php else: foreach($notices as $n): ?>
    <form method="POST" style="display:contents">
      <input type="hidden" name="mark_read" value="1"><input type="hidden" name="notice_id" value="<?= $n['id'] ?>">
      <div class="notice-item" onclick="this.closest('form').submit()">
        <span class="n-dot <?= $n['is_unread']?'unread':'' ?>"></span>
        <div class="n-info">
          <div class="n-title <?= !$n['is_unread']?'read':'' ?>"><?= htmlspecialchars($n['title']) ?></div>
          <div class="n-date"><?= date('M d, Y',strtotime($n['created_at'])) ?></div>
        </div>
        <span class="pri-pill pp-<?= $n['priority'] ?>"><?= $n['priority'] ?></span>
      </div>
    </form>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- ADD UNIT MODAL -->
<div class="modal-overlay" id="addUnitModal">
<div class="modal" style="max-width:440px;">
    <h3><i class="fa fa-building" style="color:var(--green)"></i> Link Another Unit</h3>
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:16px;">Select the society and enter your flat number. The society owner will need to approve your request before you can switch to it.</p>
    <form method="POST">
        <input type="hidden" name="add_unit" value="1">
        <div class="ff">
            <label>Society *</label>
            <select name="req_society_id" required>
                <option value="">Select Society</option>
                <?php foreach($allSocieties as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['society_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row-2">
            <div class="ff">
                <label>Flat / Unit No. *</label>
                <input type="text" name="req_unit" placeholder="e.g. 203" required>
            </div>
            <div class="ff">
                <label>Block / Tower</label>
                <input type="text" name="req_block" placeholder="e.g. B">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addUnitModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-paper-plane"></i> Send Request</button>
        </div>
    </form>
</div>
</div>

<!-- COMPLAINTS -->
<div class="tab-section" id="tab-complaints">
  <div class="tab-action"><button class="btn-primary" onclick="openModal('compModal')"><i class="fa fa-plus"></i> Raise Complaint</button></div>
  <div class="card">
    <div class="card-head"><h3>My Complaints</h3><span class="badge-cnt"><?= count($complaints) ?> total</span></div>
    <?php if(empty($complaints)): ?><div class="empty-state"><i class="fa fa-comments"></i><h4>No complaints raised yet</h4></div>
    <?php else: foreach($complaints as $c):
      $ci=match($c['status']){'open'=>'ci-open','in_progress'=>'ci-progress',default=>'ci-resolved'};
      $icon=match(true){str_contains(strtolower($c['category']??''),'plumb')=>'fa-faucet-drip',str_contains(strtolower($c['category']??''),'elect')=>'fa-bolt',str_contains(strtolower($c['category']??''),'park')=>'fa-car',str_contains(strtolower($c['category']??''),'secur')=>'fa-shield',default=>'fa-circle-exclamation'};
    ?>
    <div class="comp-item">
      <div class="comp-ico <?= $ci ?>"><i class="fa <?= $icon ?>"></i></div>
      <div class="comp-body">
        <div class="comp-title">#C-<?= str_pad($c['id'],3,'0',STR_PAD_LEFT) ?> — <?= htmlspecialchars($c['subject']) ?> <span class="sbadge sb-<?= $c['status'] ?>"><?= ucfirst(str_replace('_',' ',$c['status'])) ?></span></div>
        <div class="comp-meta"><i class="fa fa-tag"></i> <?= htmlspecialchars($c['category']??'General') ?> &nbsp;·&nbsp; <i class="fa fa-clock"></i> <?= date('d M Y',strtotime($c['created_at'])) ?></div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- DIRECTORY -->
<div class="tab-section" id="tab-directory">
  <div class="card">
    <div class="card-head"><h3>Resident Directory</h3><span class="badge-cnt"><?= count($directory) ?> members</span></div>
    <?php if(empty($directory)): ?><div class="empty-state"><i class="fa fa-users"></i><h4>No residents found</h4></div>
    <?php else: ?>
    <div class="dir-grid">
      <?php foreach($directory as $i=>$d): $c=$colors[$i%count($colors)];$init=strtoupper(substr($d['name'],0,1)); ?>
      <div class="dir-card">
        <div class="dir-av" style="background:<?= $c ?>"><?= $init ?></div>
        <div class="dir-name"><?= htmlspecialchars($d['name']) ?></div>
        <div class="dir-unit"><?= htmlspecialchars(($d['block']??'').($d['unit']?' - '.$d['unit']:'')) ?></div>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>
</div>

<!-- PAYMENTS -->
<div class="tab-section" id="tab-payments">
  <div class="card">
    <div class="card-head"><h3>My Bills &amp; Payments</h3><span class="badge-cnt"><?= count($bills) ?> bills</span></div>
    <?php if(empty($bills)): ?><div class="empty-state"><i class="fa fa-credit-card"></i><h4>No bills yet</h4></div>
    <?php else: foreach($bills as $b): ?>
    <div class="bill-item">
      <div class="bill-ico bi-<?= $b['status'] ?>"><i class="fa <?= $b['status']==='paid'?'fa-circle-check':($b['status']==='overdue'?'fa-triangle-exclamation':'fa-file-invoice') ?>"></i></div>
      <div class="bill-body">
        <div class="bill-title"><?= htmlspecialchars($b['description']) ?></div>
        <div class="bill-meta">
          <i class="fa fa-calendar"></i> <?= htmlspecialchars($b['month']??'') ?>
          &nbsp;·&nbsp; Due: <?= $b['due_date']?date('d M Y',strtotime($b['due_date'])):'-' ?>
          <?php if($b['paid_at']): ?> &nbsp;·&nbsp; Paid: <?= date('d M Y',strtotime($b['paid_at'])) ?><?php endif; ?>
        </div>
      </div>
      <div class="bill-right">
        <span class="bill-amt ba-<?= $b['status'] ?>">₹<?= number_format($b['amount'],2) ?></span>
        <?php if($b['status']!=='paid'): ?>
          <!-- Triggers the payment screenshot modal instead of directly submitting -->
          <button type="button" class="btn-pay"
            onclick="openPayModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['description'])) ?>', <?= $b['amount'] ?>)">
            <i class="fa fa-credit-card"></i> Pay Now
          </button>
        <?php else: ?>
          <span style="font-size:.73rem;color:#16a34a;font-weight:600"><i class="fa fa-circle-check"></i> Paid</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- VISITORS -->
<div class="tab-section" id="tab-visitors">
  <div class="tab-action"><button class="btn-primary" onclick="openModal('visModal')"><i class="fa fa-plus"></i> Pre-register Visitor</button></div>
  <div class="card">
    <div class="card-head">
      <h3>My Pre-registered Visitors</h3>
      <span class="badge-cnt"><?= count($visitors) ?> entries</span>
    </div>
    <?php if(empty($visitors)): ?>
      <div class="empty-state"><i class="fa fa-id-badge"></i><h4>No visitors pre-registered yet</h4></div>
    <?php else: foreach($visitors as $v):
      $stMap=['waiting'=>'sb-open','approved'=>'sb-resolved','denied'=>'sb-closed','preapproved'=>'sb-in_progress'];
      $stClass=$stMap[$v['status']]??'sb-open';
      $typeIcon=match($v['type']??'Guest'){'Delivery'=>'fa-box','Service'=>'fa-screwdriver-wrench',default=>'fa-user'};
    ?>
    <div class="vis-item" style="flex-wrap:wrap;gap:8px;">
      <div class="vis-av" style="background:var(--green-light);color:var(--green);font-size:.85rem;">
        <i class="fa <?= $typeIcon ?>"></i>
      </div>
      <div class="vis-body" style="flex:1;min-width:160px;">
        <h4 style="margin-bottom:2px;"><?= htmlspecialchars($v['name']) ?></h4>
        <span>
          <i class="fa fa-home" style="font-size:.7rem;"></i> <?= htmlspecialchars($v['flat_no']??'-') ?>
          &nbsp;&middot;&nbsp;
          <?= htmlspecialchars($v['type']??'Guest') ?>
          <?php if($v['purpose']): ?>&nbsp;&middot;&nbsp;<?= htmlspecialchars($v['purpose']) ?><?php endif; ?>
          &nbsp;&middot;&nbsp;
          <i class="fa fa-clock" style="font-size:.7rem;"></i> <?= date('d M, h:i A',strtotime($v['entry_time'])) ?>
        </span>
      </div>
      <div style="display:flex;flex-direction:column;align-items:flex-end;gap:5px;flex-shrink:0;">
        <span class="sbadge <?= $stClass ?>"><?= ucfirst($v['status']) ?></span>
        <?php if($v['otp']): ?>
        <span style="font-size:.7rem;background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;border-radius:6px;padding:2px 8px;font-weight:700;letter-spacing:1px;">
          OTP: <?= htmlspecialchars($v['otp']) ?>
        </span>
        <?php endif; ?>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- EVENTS -->
<div class="tab-section" id="tab-events">
  <div class="card">
    <div class="card-head"><h3>Upcoming Events</h3><span class="badge-cnt"><?= count($events) ?> events</span></div>
    <?php if(empty($events)): ?><div class="empty-state"><i class="fa fa-calendar"></i><h4>No upcoming events</h4></div>
    <?php else: foreach($events as $e): ?>
    <div class="event-item">
      <div class="ev-date"><span class="day"><?= date('d',strtotime($e['event_date'])) ?></span><span class="mon"><?= date('M',strtotime($e['event_date'])) ?></span></div>
      <div class="ev-info"><h4><?= htmlspecialchars($e['title']) ?></h4><span><?= htmlspecialchars($e['event_time']??'') ?><?= $e['location']?' &middot; '.htmlspecialchars($e['location']):'' ?></span></div>
    </div>
    <?php endforeach; endif; ?>
  </div>
</div>

<!-- FACILITIES / FACILITY BOOKING -->
<div class="tab-section" id="tab-facilities">
  <?php if (!empty($my_bookings)): ?>
  <div class="card" style="margin-bottom:16px;">
    <div class="card-head"><h3>My Upcoming Bookings</h3><span class="badge-cnt"><?= count($my_bookings) ?></span></div>
    <?php foreach ($my_bookings as $mb): ?>
    <div style="display:flex;justify-content:space-between;align-items:center;padding:10px 20px;border-bottom:1px solid var(--border);">
      <div>
        <div style="font-weight:600;font-size:.88rem;"><?= htmlspecialchars($mb['amenity_name']) ?></div>
        <div style="font-size:.78rem;color:var(--text-muted);"><?= date('d M Y', strtotime($mb['booking_date'])) ?>, <?= date('h:i A', strtotime($mb['start_time'])) ?> - <?= date('h:i A', strtotime($mb['end_time'])) ?></div>
      </div>
      <form method="POST" onsubmit="return confirm('Cancel this booking?');">
        <input type="hidden" name="booking_id" value="<?= $mb['id'] ?>">
        <button type="submit" name="cancel_my_booking" value="1" style="background:#fee2e2;color:#dc2626;border:none;border-radius:7px;padding:6px 14px;font-size:.78rem;font-weight:600;cursor:pointer;">Cancel</button>
      </form>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <?php if (empty($amenities_list)): ?>
  <div class="card"><div class="empty-state"><i class="fa fa-building"></i><h4>No facilities have been added yet</h4></div></div>
  <?php else: ?>
  <div class="amenity-grid">
    <?php foreach ($amenities_list as $a):
        $upcoming = $bookings_by_amenity[$a['id']] ?? [];
        $now = new DateTime();
        $activeBooking = null; $nextFree = null;
        foreach ($upcoming as $b) {
            $start = DateTime::createFromFormat('Y-m-d H:i:s', $b['booking_date'].' '.$b['start_time']);
            $end   = DateTime::createFromFormat('Y-m-d H:i:s', $b['booking_date'].' '.$b['end_time']);
            if ($now >= $start && $now <= $end) { $activeBooking = $b; $nextFree = $end; break; }
        }
        if ($a['status'] === 'maintenance') { $badge='maintenance'; $badgeLabel='Maintenance'; }
        elseif ($activeBooking) { $badge='booked'; $badgeLabel='Booked'; }
        else { $badge='available'; $badgeLabel='Available'; }
        $nextAvailText = $a['status']==='maintenance' ? '—' : ($activeBooking ? date('d M, h:i A', $nextFree->getTimestamp()) : 'Now');
        $rateText = $a['rate']>0 ? '₹'.number_format($a['rate'],0).'/'.htmlspecialchars($a['rate_unit']) : htmlspecialchars($a['rate_unit']);
    ?>
    <div class="amenity-card">
      <div class="am-head">
        <h4><?= htmlspecialchars($a['name']) ?></h4>
        <span class="am-badge am-<?= $badge ?>"><?= $badgeLabel ?></span>
      </div>
      <div class="am-row"><span>Next Available</span><strong><?= $nextAvailText ?></strong></div>
      <div class="am-row"><span>Rate</span><strong><?= $rateText ?></strong></div>
      <?php if ($a['capacity']): ?><div class="am-row"><span>Capacity</span><strong><?= (int)$a['capacity'] ?> people</strong></div><?php endif; ?>
      <?php if ($a['features']): ?><div class="am-features"><?= htmlspecialchars($a['features']) ?></div><?php endif; ?>

      <?php if ($badge === 'maintenance'): ?>
      <button class="am-btn-unavailable" disabled>Unavailable</button>
      <?php elseif ($badge === 'booked'): ?>
      <button class="am-btn-view" onclick='openBookingsModal(<?= json_encode($a['name']) ?>, <?= json_encode($upcoming) ?>)'>View Bookings</button>
      <?php else: ?>
      <button class="am-btn-book" onclick='openBookModal(<?= $a['id'] ?>, <?= json_encode($a['name']) ?>)'>Book Now</button>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>
</div>

<!-- ══ BOOK FACILITY MODAL ══ -->
<div class="modal-overlay" id="bookFacilityModal">
  <div class="modal">
    <h3 id="bookModalTitle">Book Facility</h3>
    <form method="POST">
      <input type="hidden" name="amenity_id" id="bk_amenity_id" value="">
      <div class="ff"><label>Date *</label><input type="date" name="booking_date" id="bk_date" min="<?= date('Y-m-d') ?>" required></div>
      <div class="form-row">
        <div class="ff"><label>Start Time *</label><input type="time" name="start_time" id="bk_start" required></div>
        <div class="ff"><label>End Time *</label><input type="time" name="end_time" id="bk_end" required></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('bookFacilityModal')">Cancel</button>
        <button type="submit" name="book_facility" value="1" class="btn-primary"><i class="fa fa-calendar-check"></i> Confirm Booking</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ VIEW BOOKINGS MODAL (read-only) ══ -->
<div class="modal-overlay" id="viewBookingsModal">
  <div class="modal" style="max-width:460px;">
    <h3 id="viewBookingsTitle">Upcoming Bookings</h3>
    <div id="viewBookingsList" style="max-height:340px;overflow-y:auto;"></div>
    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal('viewBookingsModal')">Close</button>
    </div>
  </div>
</div>

<!-- PLACEHOLDER TABS -->
<?php foreach(['access'=>['fa-key','Access & QR'],'polls'=>['fa-square-poll-horizontal','Polls & Voting'],'market'=>['fa-store','Community Market']] as $tid=>[$ico,$tlabel]): ?>
<div class="tab-section" id="tab-<?= $tid ?>">
  <div class="card"><div class="empty-state"><i class="fa <?= $ico ?>"></i><h4><?= $tlabel ?> - Coming Soon</h4></div></div>
</div>
<?php endforeach; ?>

<!-- Role Request tab (kept separate from the placeholder loop above) -->
<div class="tab-section" id="tab-role">
  <div class="card"><div class="empty-state"><i class="fa fa-user-tag"></i><h4>Role Request - Coming Soon</h4></div></div>
</div>

<!-- ══ PROFILE TAB ══ -->
<div class="tab-section" id="tab-profile">
  <div class="card" style="padding:20px; max-width:520px;">
    <h3 style="font-size:.95rem;font-weight:700;margin-bottom:16px;">Personal details</h3>
    <form method="POST">
      <div class="ff">
        <label>Full name</label>
        <input type="text" name="name" value="<?= htmlspecialchars($userRow['name'] ?? '') ?>" required>
      </div>
      <div class="ff">
        <label>Phone</label>
        <input type="text" name="phone" value="<?= htmlspecialchars($userRow['phone'] ?? '') ?>" placeholder="+91 98200 00000">
      </div>
      <button type="submit" name="save_personal_details" value="1" class="btn-primary"><i class="fa fa-check"></i> Save changes</button>
    </form>
  </div>

  <div class="card" style="padding:20px; max-width:520px;">
    <h3 style="font-size:.95rem;font-weight:700;margin-bottom:16px;">Email</h3>
    <form method="POST">
      <div class="ff">
        <label>Login email</label>
        <input type="email" name="email" value="<?= htmlspecialchars($userRow['email'] ?? '') ?>" required>
      </div>
      <button type="submit" name="save_email" value="1" class="btn-primary"><i class="fa fa-check"></i> Update email</button>
    </form>
  </div>

  <div class="card" style="padding:20px; max-width:520px;">
    <h3 style="font-size:.95rem;font-weight:700;margin-bottom:16px;">Password management</h3>
    <form method="POST">
      <div class="ff">
        <label>Current password</label>
        <input type="password" name="current_password" required>
      </div>
      <div class="ff">
        <label>New password</label>
        <input type="password" name="new_password" minlength="8" required>
      </div>
      <div class="ff">
        <label>Confirm new password</label>
        <input type="password" name="confirm_password" minlength="8" required>
      </div>
      <button type="submit" name="change_password" value="1" class="btn-primary"><i class="fa fa-check"></i> Change password</button>
    </form>
  </div>
</div>

</div><!-- /content -->

<!-- ============================================================
     COMPLAINT MODAL
     ============================================================ -->
<div class="modal-overlay" id="compModal">
  <div class="modal">
    <h3>Raise a Complaint</h3><p>Submit your issue and we'll look into it promptly.</p>
    <form method="POST">
      <input type="hidden" name="add_complaint" value="1">
      <div class="form-row">
        <div class="ff"><label>Unit Number</label><input type="text" name="unit" placeholder="e.g. A-201"></div>
        <div class="ff"><label>Category *</label><select name="category" required><option value="">Select</option><?php foreach($categories as $cat): ?><option><?= $cat ?></option><?php endforeach; ?></select></div>
      </div>
      <div class="ff"><label>Subject *</label><input type="text" name="subject" placeholder="Brief title of the issue" required></div>
      <div class="ff"><label>Description</label><textarea name="description" placeholder="Describe the issue in detail..."></textarea></div>
      <div class="ff"><label>Priority</label><select name="priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></div>
      <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('compModal')">Cancel</button><button type="submit" class="btn-primary"><i class="fa fa-paper-plane"></i> Submit</button></div>
    </form>
  </div>
</div>

<!-- ============================================================
     VISITOR MODAL
     ============================================================ -->
<div class="modal-overlay" id="visModal">
  <div class="modal">
    <div style="display:flex;align-items:center;gap:10px;margin-bottom:4px;">
      <div style="width:36px;height:36px;background:var(--green-light);border-radius:9px;display:flex;align-items:center;justify-content:center;color:var(--green);flex-shrink:0;"><i class="fa fa-id-badge"></i></div>
      <div><h3 style="margin:0;">Pre-register Visitor</h3><p style="margin:0;font-size:.75rem;color:var(--text-muted);">Gate staff will see this entry for verification</p></div>
    </div>
    <form method="POST" style="margin-top:14px;">
      <input type="hidden" name="add_visitor" value="1">
      <div class="form-row">
        <div class="ff"><label>Visitor Name <span style="color:#dc2626;">*</span></label><input type="text" name="visitor_name" placeholder="Full name" required></div>
        <div class="ff"><label>Visitor Type</label>
          <select name="visitor_type">
            <option value="Guest">Guest</option>
            <option value="Delivery">Delivery</option>
            <option value="Service">Service</option>
          </select>
        </div>
      </div>
      <div class="form-row">
        <div class="ff"><label>Your Flat No. <span style="color:#dc2626;">*</span></label><input type="text" name="visitor_flat" placeholder="e.g. A-201" required></div>
        <div class="ff"><label>Visitor Phone</label><input type="text" name="visitor_phone" placeholder="+91 98765 43210"></div>
      </div>
      <div class="ff"><label>Purpose / Note</label><input type="text" name="visitor_purpose" placeholder="e.g. Birthday party, AC repair, Zomato delivery"></div>
      <div style="background:var(--green-soft);border:1px solid #c3e6d4;border-radius:9px;padding:10px 13px;font-size:.78rem;color:var(--text-sub);margin-bottom:12px;">
        <i class="fa fa-circle-info" style="color:var(--green);"></i> &nbsp;An OTP will be auto-generated and shown after submission. Share it with your visitor — gate staff will verify it at entry.
      </div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('visModal')">Cancel</button>
        <button type="submit" class="btn-primary"><i class="fa fa-paper-plane"></i> Pre-register</button>
      </div>
    </form>
  </div>
</div>

<!-- ============================================================
     PAYMENT SCREENSHOT MODAL  ← NEW
     ============================================================ -->
<div class="modal-overlay" id="payModal">
  <div class="modal" style="max-width:500px;">

    <!-- Header -->
    <div style="display:flex;align-items:center;gap:11px;margin-bottom:5px;">
      <div style="width:38px;height:38px;background:var(--green-light);border-radius:10px;display:flex;align-items:center;justify-content:center;color:var(--green);font-size:1rem;flex-shrink:0;">
        <i class="fa fa-credit-card"></i>
      </div>
      <div>
        <h3 style="margin:0;font-size:1rem;">Confirm Payment</h3>
        <p style="margin:0;font-size:.75rem;color:var(--text-muted);">Upload proof after completing the transfer</p>
      </div>
    </div>

    <!-- Bill summary line (filled by JS) -->
    <div id="payBillSummary" style="background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:10px 14px;margin:14px 0;font-size:.82rem;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap;">
      <span id="payBillDesc" style="color:var(--text-sub);font-weight:600;"></span>
      <span id="payBillAmt" style="font-weight:700;color:#c2410c;font-size:.95rem;"></span>
    </div>

    <!-- UPI info box -->
    <div class="pay-info-box">
      <div class="pay-info-title"><i class="fa fa-qrcode"></i> &nbsp;Pay via UPI or Bank Transfer</div>
      <div class="pay-row"><span>UPI ID</span><strong>colonycare@upi</strong></div>
      <div class="pay-row"><span>Account Name</span><strong>ColonyCare Society</strong></div>
      <div class="pay-row"><span>Bank / IFSC</span><strong>SBI / SBIN0001234</strong></div>
      <div style="margin-top:8px;font-size:.75rem;color:var(--text-muted);border-top:1px solid #d1ead9;padding-top:7px;">
        <i class="fa fa-circle-info"></i> &nbsp;After paying, upload the payment screenshot below. Your bill will be updated once verified by admin.
      </div>
    </div>

    <!-- The actual form — multipart for file upload -->
    <form method="POST" enctype="multipart/form-data" id="payForm" onsubmit="return validatePayForm()">
      <input type="hidden" name="pay_bill" value="1">
      <input type="hidden" name="bill_id" id="payBillId" value="">

      <!-- Screenshot drop zone -->
      <div class="ff">
        <label>Payment Screenshot <span class="req-star">*</span></label>

        <div class="drop-zone" id="dropZone" onclick="document.getElementById('payScreenshot').click()">
          <!-- Default state -->
          <div id="dropContent">
            <i class="fa fa-cloud-arrow-up drop-icon"></i>
            <div class="drop-label">Click or drag &amp; drop screenshot here</div>
            <div class="drop-hint">PNG, JPG, JPEG, WEBP &nbsp;·&nbsp; Max 5 MB</div>
          </div>
          <!-- Preview state (hidden until file chosen) -->
          <div class="preview-wrap" id="previewWrap">
            <img id="previewImg" src="" alt="Payment screenshot preview">
            <div class="preview-name" id="previewName"></div>
            <button type="button" class="btn-remove-file" onclick="clearPayFile(event)">
              <i class="fa fa-trash-can"></i> Remove &amp; choose another
            </button>
          </div>
        </div>

        <!-- Hidden real file input -->
        <input type="file" id="payScreenshot" name="payment_screenshot"
               accept="image/jpeg,image/jpg,image/png,image/webp,image/gif"
               style="display:none;" onchange="handlePayFile(this)">

        <!-- Validation error (shown by JS) -->
        <div class="file-error" id="fileError">
          <i class="fa fa-circle-exclamation"></i> A payment screenshot is required to proceed.
        </div>
      </div>

      <!-- Optional UTR reference -->
      <div class="ff">
        <label>Transaction / UTR Reference &nbsp;<span style="color:var(--text-muted);font-weight:400;">(optional)</span></label>
        <input type="text" name="txn_ref" id="payTxnRef" placeholder="e.g. UPI Ref No. 123456789012">
      </div>

      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closePayModal()">Cancel</button>
        <button type="submit" class="btn-primary" id="paySubmitBtn">
          <i class="fa fa-paper-plane"></i> Submit Payment
        </button>
      </div>
    </form>
  </div>
</div>
<!-- ADD UNIT MODAL -->
<div class="modal-overlay" id="addUnitModal">
<div class="modal" style="max-width:440px;">
    <h3><i class="fa fa-building" style="color:var(--green)"></i> Link Another Unit</h3>
    <p style="font-size:.8rem;color:var(--text-muted);margin-bottom:16px;">Select the society and enter your flat number. The society owner will need to approve your request before you can switch to it.</p>
    <form method="POST">
        <input type="hidden" name="add_unit" value="1">
        <div class="ff">
            <label>Society *</label>
            <select name="req_society_id" required>
                <option value="">Select Society</option>
                <?php foreach($allSocieties as $s): ?>
                <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['society_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-row">
            <div class="ff">
                <label>Flat / Unit No. *</label>
                <input type="text" name="req_unit" placeholder="e.g. 203" required>
            </div>
            <div class="ff">
                <label>Block / Tower</label>
                <input type="text" name="req_block" placeholder="e.g. B">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addUnitModal')">Cancel</button>
            <button type="submit" class="btn-save btn-primary"><i class="fa fa-paper-plane"></i> Send Request</button>
        </div>
    </form>
</div>
</div>

<script>
/* ---- General tab / modal helpers ---- */
function switchTab(id,btn){
  document.querySelectorAll('.tab-section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  document.getElementById('tab-'+id).classList.add('active');
  if(btn) btn.classList.add('active');
}
<?php if (($_GET['tab'] ?? '') === 'profile'): ?>
document.addEventListener('DOMContentLoaded', function(){
    switchTab('profile', document.querySelector('.tab-btn[data-tab="profile"]'));
});
<?php endif; ?>
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}

function openBookModal(amenityId, name){
    document.getElementById('bookModalTitle').textContent = 'Book ' + name;
    document.getElementById('bk_amenity_id').value = amenityId;
    document.getElementById('bk_date').value = '';
    document.getElementById('bk_start').value = '';
    document.getElementById('bk_end').value = '';
    openModal('bookFacilityModal');
}

function openBookingsModal(name, bookings){
    document.getElementById('viewBookingsTitle').textContent = 'Upcoming Bookings — ' + name;
    const list = document.getElementById('viewBookingsList');
    list.innerHTML = bookings.map(b => {
        const d = new Date(b.booking_date + 'T00:00:00');
        const dateStr = d.toLocaleDateString('en-IN', {day:'numeric', month:'short', year:'numeric'});
        return `<div style="padding:10px 0;border-bottom:1px solid var(--border);font-size:.85rem;">
            <div style="font-weight:600;">${dateStr}</div>
            <div style="color:var(--text-muted);font-size:.8rem;">${b.start_time.slice(0,5)} - ${b.end_time.slice(0,5)}</div>
        </div>`;
    }).join('') || '<p style="color:var(--text-muted);font-size:.85rem;">No upcoming bookings.</p>';
    openModal('viewBookingsModal');
}
document.querySelectorAll('.modal-overlay').forEach(m=>{
  m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
});

/* ---- Payment modal ---- */
function openPayModal(billId, desc, amount){
  // Populate hidden fields and summary bar
  document.getElementById('payBillId').value = billId;
  document.getElementById('payBillDesc').textContent = desc;
  document.getElementById('payBillAmt').textContent = '₹' + parseFloat(amount).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
  // Reset file state
  _clearPayFileState();
  // Open modal
  document.getElementById('payModal').classList.add('open');
}

function closePayModal(){
  document.getElementById('payModal').classList.remove('open');
}

function handlePayFile(input){
  const file = input.files[0];
  if(!file) return;

  // Size guard (5 MB)
  if(file.size > 5 * 1024 * 1024){
    alert('File is too large. Maximum allowed size is 5 MB.');
    input.value = '';
    return;
  }
  // Type guard
  const allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
  if(!allowed.includes(file.type)){
    alert('Invalid file type. Please upload a JPG, PNG, WEBP or GIF image.');
    input.value = '';
    return;
  }

  // Show preview
  const reader = new FileReader();
  reader.onload = function(e){
    document.getElementById('previewImg').src = e.target.result;
    document.getElementById('previewName').textContent =
      file.name + ' (' + (file.size/1024).toFixed(1) + ' KB)';
    document.getElementById('dropContent').style.display = 'none';
    document.getElementById('previewWrap').style.display = 'block';
    const dz = document.getElementById('dropZone');
    dz.classList.add('has-file');
    dz.style.cursor = 'default';
    document.getElementById('fileError').classList.remove('show');
  };
  reader.readAsDataURL(file);
}

function clearPayFile(e){
  e.stopPropagation(); // don't re-open file picker
  _clearPayFileState();
}

function _clearPayFileState(){
  document.getElementById('payScreenshot').value = '';
  document.getElementById('previewImg').src = '';
  document.getElementById('previewName').textContent = '';
  document.getElementById('previewWrap').style.display = 'none';
  document.getElementById('dropContent').style.display = 'block';
  const dz = document.getElementById('dropZone');
  dz.classList.remove('has-file','drag-over');
  dz.style.cursor = 'pointer';
  document.getElementById('fileError').classList.remove('show');
  document.getElementById('payTxnRef').value = '';
}

function validatePayForm(){
  const file = document.getElementById('payScreenshot').files[0];
  if(!file){
    const errEl = document.getElementById('fileError');
    errEl.classList.add('show');
    const dz = document.getElementById('dropZone');
    dz.style.borderColor = '#dc2626';
    dz.scrollIntoView({behavior:'smooth', block:'center'});
    return false;
  }
  return true;
}

// ── NOTIFICATIONS ────────────────────────────────────────
let notifOpen = false;

function toggleNotif(e) {
    e.stopPropagation();
    notifOpen = !notifOpen;
    document.getElementById('notifDropdown').classList.toggle('open', notifOpen);
    if (notifOpen) fetchNotifications();
}

document.addEventListener('click', function(e) {
    if (!document.getElementById('notifDropdown').contains(e.target) &&
        e.target !== document.getElementById('notifBell')) {
        notifOpen = false;
        document.getElementById('notifDropdown').classList.remove('open');
    }
});

const typeIcon = {
    payment:      ['fa fa-credit-card', 'ni-payment'],
    complaint:    ['fa fa-triangle-exclamation', 'ni-complaint'],
    approval:     ['fa fa-circle-check', 'ni-approval'],
    rejection:    ['fa fa-circle-xmark', 'ni-rejection'],
    verification: ['fa fa-shield-check', 'ni-verification'],
};

function timeAgo(dateStr) {
    const diff = Math.floor((Date.now() - new Date(dateStr)) / 1000);
    if (diff < 60)   return 'Just now';
    if (diff < 3600) return Math.floor(diff/60) + 'm ago';
    if (diff < 86400) return Math.floor(diff/3600) + 'h ago';
    return Math.floor(diff/86400) + 'd ago';
}

function fetchNotifications() {
    fetch('/shivam/notification_handler.php?action=fetch')
        .then(r=>r.json())
        .then(data=>{
            const badge = document.getElementById('notifBadge');
            const list  = document.getElementById('notifList');

            if (data.count > 0) {
                badge.textContent = data.count > 99 ? '99+' : data.count;
                badge.classList.add('show');
            } else {
                badge.classList.remove('show');
            }

            if (!data.items || data.items.length === 0) {
                list.innerHTML = '<div class="notif-empty"><i class="fa fa-bell-slash" style="display:block;font-size:1.4rem;margin-bottom:6px;opacity:.4"></i>No notifications yet</div>';
                return;
            }

            list.innerHTML = data.items.map(n => {
                const [ico, cls] = typeIcon[n.type] || ['fa fa-bell','ni-approval'];
                const unreadClass = n.is_read == 0 ? 'unread' : '';
                const link = n.link || '#';
                return `<a class="notif-item ${unreadClass}" href="${link}" onclick="markRead(${n.id}, event, '${link}')">
                    <div class="notif-icon ${cls}"><i class="${ico}"></i></div>
                    <div class="notif-text">
                        <div class="notif-msg">${n.message}</div>
                        <div class="notif-time">${timeAgo(n.created_at)}</div>
                    </div>
                </a>`;
            }).join('');
        })
        .catch(()=>{});
}

function markRead(id, e, link) {
    e.preventDefault();
    fetch('/shivam/notification_handler.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=mark_read&id=${id}`
    }).then(()=>{
        if (link && link !== '#') window.location.href = link;
        else fetchNotifications();
    });
}

function markAllRead() {
    fetch('/shivam/notification_handler.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=mark_read&id=0'
    }).then(()=>fetchNotifications());
}

// Poll every 30 seconds + fetch on load
fetchNotifications();
setInterval(fetchNotifications, 30000);

function toggleUnitSwitcher(e) {
    e.stopPropagation();
    const d = document.getElementById('unitDropdown');
    d.classList.toggle('open');
}
document.addEventListener('click', function(e) {
    const wrap = document.getElementById('unitSwitcherWrap');
    if (wrap && !wrap.contains(e.target)) {
        document.getElementById('unitDropdown').classList.remove('open');
    }
});

/* ---- Drag & drop ---- */
document.addEventListener('DOMContentLoaded', function(){
  const zone = document.getElementById('dropZone');
  if(!zone) return;

  zone.addEventListener('dragover', function(e){
    e.preventDefault();
    if(!zone.classList.contains('has-file')) zone.classList.add('drag-over');
  });
  zone.addEventListener('dragleave', function(){
    zone.classList.remove('drag-over');
  });
  zone.addEventListener('drop', function(e){
    e.preventDefault();
    zone.classList.remove('drag-over');
    if(zone.classList.contains('has-file')) return; // already has a file
    const file = e.dataTransfer.files[0];
    if(file && file.type.startsWith('image/')){
      // Inject into the hidden input via DataTransfer
      try {
        const dt = new DataTransfer();
        dt.items.add(file);
        const inp = document.getElementById('payScreenshot');
        inp.files = dt.files;
        handlePayFile(inp);
      } catch(ex){
        // Fallback for browsers without DataTransfer constructor
        alert('Drag & drop not supported in this browser. Please click to choose a file.');
      }
    } else if(file){
      alert('Please drop an image file (JPG, PNG, WEBP, GIF).');
    }
  });
});

// ── MOBILE NAV ───────────────────────────────────────
function openResidentMobNav(){document.getElementById('resMobNav').classList.add('open');document.body.style.overflow='hidden';}
function closeResidentMobNav(){document.getElementById('resMobNav').classList.remove('open');document.body.style.overflow='';}
function closeResidentMobNavBg(e){if(e.target===document.getElementById('resMobNav'))closeResidentMobNav();}

</script>
</body>
</html>
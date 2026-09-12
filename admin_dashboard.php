<?php
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
$role = $_SESSION['user_role'] ?? 'resident';
if ($role !== 'admin') { header('Location: /login.php'); exit; }

$user_id    = $_SESSION['user_id'];
$user_name  = $_SESSION['user_name'] ?? 'Admin';
$society_id = $_SESSION['user_society_id'] ?? 0;
if (!$society_id) { header('Location: /login.php'); exit; }

// DB constants loaded via config.php
$msg = $err = '';

try {
    $pdo = get_db_connection();

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

    // ── If this admin is actually the owner, send them to society.php ──
    $ownChk = $pdo->prepare("SELECT owner_id, society_name FROM societies WHERE id = ? LIMIT 1");
    $ownChk->execute([$society_id]);
    $socRow = $ownChk->fetch();
    if (!$socRow) { header('Location: /login.php'); exit; }
    if ((int)$socRow['owner_id'] === (int)$user_id) { header('Location: /society.php'); exit; }

    $owner_id           = (int)$socRow['owner_id'];
    $page_society_name  = $socRow['society_name'];

    // ── Fetch society name fresh from DB (always accurate, even if renamed) ──
    $page_society_name = '';
    if (!empty($_SESSION['user_society_id'])) {
        $stmtSocLookup = $pdo->prepare("SELECT society_name FROM societies WHERE id = ? LIMIT 1");
        $stmtSocLookup->execute([$_SESSION['user_society_id']]);
        $page_society_name = $stmtSocLookup->fetchColumn() ?: '';
    }
    if (!$page_society_name && !empty($_SESSION['user_society'])) {
        // Fallback: session only has the text name (society_id not linked yet)
        $page_society_name = $_SESSION['user_society'];
    }

    // ── Handle POST
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        // Send Notice
        if (isset($_POST['change_role'])) {
            $rid = (int)$_POST['role_uid'];
            if ($rid === $owner_id) { $err = 'Cannot modify the society owner.'; }
            else {
                $pdo->prepare("UPDATE users SET role=? WHERE id=? AND society_id=?")->execute([$_POST['new_role'], $rid, $society_id]);
                $msg = 'Role updated.';
            }
        }

        if (isset($_POST['toggle_user'])) {
            $tid = (int)$_POST['toggle_id'];
            if ($tid === $owner_id) { $err = 'Cannot modify the society owner.'; }
            else {
                $active = (int)$_POST['toggle_active'];
                $pdo->prepare("UPDATE users SET is_active=? WHERE id=? AND society_id=?")->execute([$active, $tid, $society_id]);
                $msg = $active ? 'User activated.' : 'User deactivated.';
            }
        }

        if (isset($_POST['del_user'])) {
            $did = (int)$_POST['del_id'];
            if ($did === $owner_id || $did === $user_id) { $err = 'Cannot delete this account.'; }
            else {
                $pdo->prepare("DELETE FROM users WHERE id=? AND society_id=?")->execute([$did, $society_id]);
                $msg = 'User removed.';
            }
        }

        if (isset($_POST['approve_user'])) {
            $uid = (int)$_POST['approve_id'];
            $newRole = trim($_POST['approve_role'] ?? '');
            $allowed = ['resident','admin','staff','accountant','vendor','society_member'];
            $chk = $pdo->prepare("SELECT name,email FROM users WHERE id=? AND society_id=? AND is_active=0");
            $chk->execute([$uid, $society_id]);
            $u = $chk->fetch();
            if ($u) {
                if ($newRole && in_array($newRole, $allowed)) {
                    $pdo->prepare("UPDATE users SET is_active=1, role=? WHERE id=? AND society_id=?")->execute([$newRole, $uid, $society_id]);
                } else {
                    $pdo->prepare("UPDATE users SET is_active=1 WHERE id=? AND society_id=?")->execute([$uid, $society_id]);
                }

                notify($pdo, $society_id, $uid, $user_id, 'approval',
                    'Your registration has been approved! Welcome to ' . $page_society_name . '.',
                    '/resident.php'
                );

                $msg = '✅ Access granted to ' . htmlspecialchars($u['name']) . ' (' . htmlspecialchars($u['email']) . ').';
            } else { $err = 'User not found in your society.'; }
        }

        if (isset($_POST['reject_user'])) {
            $uid = (int)$_POST['reject_id'];
            if ($uid !== $owner_id) {
                $chk = $pdo->prepare("SELECT name FROM users WHERE id=? AND society_id=? AND is_active=0");
                $chk->execute([$uid, $society_id]);
                $u = $chk->fetch();
                if ($u) {
                    // notify BEFORE delete so the user row still exists for FK
                    notify($pdo, $society_id, $uid, $user_id, 'rejection',
                        'Your registration request was not approved. Please contact the society admin.',
                        null
                    );
                    $pdo->prepare("DELETE FROM users WHERE id=? AND society_id=? AND is_active=0")->execute([$uid, $society_id]);
                    $msg = '❌ Registration rejected for ' . htmlspecialchars($u['name']) . '.';
                } else { $err = 'User not found or already active.'; }
            }
        }

        if (isset($_POST['gen_bill'])) {
            $uid  = (int)$_POST['bill_uid'];
            $unit = trim($_POST['bill_unit'] ?? '');
            $desc = trim($_POST['bill_desc'] ?? 'Monthly Maintenance');
            $amt  = (float)$_POST['bill_amount'];
            $mon  = trim($_POST['bill_month'] ?? date('F Y'));
            $due  = trim($_POST['bill_due']   ?? '');
            if ($uid && $amt && $due) {
                $chkU = $pdo->prepare("SELECT id FROM users WHERE id=? AND society_id=?");
                $chkU->execute([$uid, $society_id]);
                if ($chkU->fetch()) {
                    $pdo->prepare("INSERT INTO billing (user_id,society_id,unit,description,amount,month,due_date) VALUES (?,?,?,?,?,?,?)")
                        ->execute([$uid,$society_id,$unit,$desc,$amt,$mon,$due]);
                    $msg = 'Bill generated!';
                } else { $err = 'Resident not found in your society.'; }
            }
        }

        if (isset($_POST['mark_paid'])) {
            $bid = (int)$_POST['bill_id'];
            $pdo->prepare("UPDATE billing SET status='paid', paid_at=NOW(), payment_mode='Admin Override', txn_id=CONCAT('ADM-',LPAD(?,6,'0')) WHERE id=? AND society_id=?")
                ->execute([$bid, $bid, $society_id]);
            $msg = 'Payment marked as paid.';
        }

        if (isset($_POST['verify_payment'])) {
            $bid = (int)$_POST['bill_id'];
            $chkRole = $pdo->prepare("SELECT u.role FROM billing b JOIN users u ON b.user_id=u.id WHERE b.id=? AND b.society_id=?");
            $chkRole->execute([$bid, $society_id]);
            $billRole = $chkRole->fetchColumn();
            if ($billRole === 'admin') {
                $err = 'Only the society owner can verify an admin\'s payment.';
            } else {
                $pdo->prepare("UPDATE billing SET status='paid', paid_at=NOW(), payment_mode='UPI/Bank (Verified)', txn_id = COALESCE(txn_ref, CONCAT('VER-',LPAD(?,6,'0'))) WHERE id=? AND society_id=?")
                    ->execute([$bid, $bid, $society_id]);

                $billOwner = $pdo->prepare("SELECT user_id, amount FROM billing WHERE id=? AND society_id=?");
                $billOwner->execute([$bid, $society_id]);
                $billRow = $billOwner->fetch();
                if ($billRow) {
                    notify($pdo, $society_id, $billRow['user_id'], $user_id, 'verification',
                        'Your payment of ₹' . number_format($billRow['amount'], 0) . ' has been verified and confirmed.',
                        '/resident.php#tab-payments'
                    );
                }

                $msg = 'Payment verified and marked as Paid!';
            }
        }

        if (isset($_POST['reject_payment'])) {
            $bid = (int)$_POST['bill_id'];
            $chkRole = $pdo->prepare("SELECT u.role FROM billing b JOIN users u ON b.user_id=u.id WHERE b.id=? AND b.society_id=?");
            $chkRole->execute([$bid, $society_id]);
            $billRole = $chkRole->fetchColumn();
            if ($billRole === 'admin') {
                $err = 'Only the society owner can act on an admin\'s payment.';
            } else {
                $billOwner = $pdo->prepare("SELECT user_id, amount FROM billing WHERE id=? AND society_id=?");
                $billOwner->execute([$bid, $society_id]);
                $billRow = $billOwner->fetch();

                $pdo->prepare("UPDATE billing SET status='pending', payment_proof=NULL, txn_ref=NULL WHERE id=? AND society_id=?")->execute([$bid, $society_id]);

                if ($billRow) {
                    notify($pdo, $society_id, $billRow['user_id'], $user_id, 'rejection',
                        'Your payment proof was rejected. Please re-upload a valid screenshot.',
                        '/resident.php#tab-payments'
                    );
                }

                $msg = 'Payment proof rejected.';
            }
        }

        if (isset($_POST['mark_overdue'])) {
            $bid = (int)$_POST['bill_id'];
            $pdo->prepare("UPDATE billing SET status='overdue' WHERE id=? AND society_id=?")->execute([$bid, $society_id]);
            $msg = 'Bill marked as overdue.';
        }

        if (isset($_POST['del_bill'])) {
            $bid = (int)$_POST['bill_id'];
            $pdo->prepare("DELETE FROM billing WHERE id=? AND society_id=?")->execute([$bid, $society_id]);
            $msg = 'Bill deleted.';
        }

        if (isset($_POST['send_notice'])) {
            $title = trim($_POST['notice_title'] ?? '');
            $body  = trim($_POST['notice_body']  ?? '');
            $pri   = trim($_POST['notice_priority'] ?? 'medium');
            if ($title) {
                $pdo->prepare("INSERT INTO notices (society_id,title,body,priority,created_by) VALUES (?,?,?,?,?)")
                    ->execute([$society_id, $title, $body, $pri, $user_id]);
                $msg = 'Notice sent to all residents!';
            }
        }

        if (isset($_POST['pay_bill'])) {
            $bid = (int)$_POST['bill_id'];
            $hasFile = !empty($_FILES['payment_screenshot']['name']) && $_FILES['payment_screenshot']['error'] === UPLOAD_ERR_OK;
            if (!$hasFile) {
                $err = 'A payment screenshot is required to confirm your payment.';
            } else {
                $allowed = ['image/jpeg','image/jpg','image/png','image/webp','image/gif'];
                $ftype = mime_content_type($_FILES['payment_screenshot']['tmp_name']);
                $fsize = $_FILES['payment_screenshot']['size'];
                if (!in_array($ftype, $allowed)) {
                    $err = 'Invalid file type. Please upload a JPG, PNG, WEBP or GIF image.';
                } elseif ($fsize > 5*1024*1024) {
                    $err = 'Screenshot too large. Maximum allowed size is 5 MB.';
                } else {
                    $upDir = __DIR__.'/uploads/payment_proofs/';
                    if (!is_dir($upDir)) mkdir($upDir, 0755, true);
                    $ext = pathinfo($_FILES['payment_screenshot']['name'], PATHINFO_EXTENSION);
                    $fname = 'pay_'.$bid.'_'.$user_id.'_'.time().'.'.$ext;
                    if (move_uploaded_file($_FILES['payment_screenshot']['tmp_name'], $upDir.$fname)) {
                        $txnRef = trim($_POST['txn_ref'] ?? '');
                        $pdo->prepare("
                            UPDATE billing
                            SET status='pending_verification', payment_proof=?, txn_ref=?
                            WHERE id=? AND user_id=? AND society_id=?
                        ")->execute([$fname, $txnRef, $bid, $user_id, $society_id]);

                        $billAmtRow = $pdo->prepare("SELECT amount FROM billing WHERE id=? AND society_id=?");
                        $billAmtRow->execute([$bid, $society_id]);
                        $b_amount = $billAmtRow->fetchColumn() ?: 0;
                        $admins = get_owner_and_admins($pdo, $society_id);
                        notify($pdo, $society_id, $admins, $user_id, 'payment',
                            $_SESSION['user_name'] . ' submitted payment proof for ₹' . number_format($b_amount, 0),
                            '/society.php#tab-maintenance'
                        );

                        $msg = 'Payment submitted! The society owner will verify and confirm shortly.';
                    } else {
                        $err = 'Failed to save screenshot. Please try again.';
                    }
                }
            }
        }

        if (isset($_POST['add_resident'])) {
            $n  = trim($_POST['r_name']  ?? '');
            $e  = trim($_POST['r_email'] ?? '');
            $p  = trim($_POST['r_phone'] ?? '');
            $u  = trim($_POST['r_unit']  ?? '');
            $b  = trim($_POST['r_block'] ?? '');
            $rl = trim($_POST['r_role']  ?? 'resident');
            $pw = password_hash(trim($_POST['r_password'] ?? 'admin123'), PASSWORD_BCRYPT);
            if ($n && $e) {
                try {
                    $pdo->prepare("INSERT INTO users (name,email,phone,unit,block,role,password,society,society_id,is_active) VALUES (?,?,?,?,?,?,?,?,?,1)")
                        ->execute([$n,$e,$p,$u,$b,$rl,$pw,$page_society_name,$society_id]);
                    $msg = "Resident {$n} added successfully!";
                } catch(PDOException $ex) { $err = 'Email already exists.'; }
            } else { $err = 'Name and email required.'; }
        }

        if (isset($_POST['respond_complaint'])) {
            $cid       = (int)$_POST['complaint_id'];
            $newStatus = $_POST['complaint_status'] ?? 'open';
            $response  = trim($_POST['admin_response'] ?? '');
            $validStatuses = ['open','in_progress','resolved','closed'];

            if (in_array($newStatus, $validStatuses)) {
                $chk = $pdo->prepare("SELECT user_id, subject FROM complaints WHERE id=? AND society_id=?");
                $chk->execute([$cid, $society_id]);
                $crow = $chk->fetch();

                if ($crow) {
                    $resolvedAt = in_array($newStatus, ['resolved','closed']) ? date('Y-m-d H:i:s') : null;
                    $pdo->prepare("UPDATE complaints SET status=?, admin_response=?, resolved_at=? WHERE id=? AND society_id=?")
                        ->execute([$newStatus, $response, $resolvedAt, $cid, $society_id]);

                    // Notify the resident/society member who raised it
                    $statusLabel = ['open'=>'reopened','in_progress'=>'is being worked on','resolved'=>'has been resolved','closed'=>'has been closed'][$newStatus];
                    notify($pdo, $society_id, $crow['user_id'], $user_id, 'complaint',
                        'Your complaint "' . $crow['subject'] . '" ' . $statusLabel . ($response ? ': ' . $response : '') . '.',
                        null
                    );
                    $msg = 'Complaint updated and the resident has been notified.';
                } else {
                    $err = 'Complaint not found.';
                }
            }
        }

        if (isset($_POST['add_amenity'])) {
            $name     = trim($_POST['a_name'] ?? '');
            $rate     = (float)($_POST['a_rate'] ?? 0);
            $rateUnit = trim($_POST['a_rate_unit'] ?? 'Free');
            $capacity = (int)($_POST['a_capacity'] ?? 0) ?: null;
            $features = trim($_POST['a_features'] ?? '');
            if ($name) {
                $pdo->prepare("INSERT INTO amenities (society_id,name,rate,rate_unit,capacity,features) VALUES (?,?,?,?,?,?)")
                    ->execute([$society_id, $name, $rate, $rateUnit, $capacity, $features]);
                $msg = "Facility \"{$name}\" added.";
            } else {
                $err = 'Facility name is required.';
            }
        }

        if (isset($_POST['edit_amenity'])) {
            $aid      = (int)$_POST['amenity_id'];
            $name     = trim($_POST['a_name'] ?? '');
            $rate     = (float)($_POST['a_rate'] ?? 0);
            $rateUnit = trim($_POST['a_rate_unit'] ?? 'Free');
            $capacity = (int)($_POST['a_capacity'] ?? 0) ?: null;
            $features = trim($_POST['a_features'] ?? '');
            if ($name) {
                $pdo->prepare("UPDATE amenities SET name=?,rate=?,rate_unit=?,capacity=?,features=? WHERE id=? AND society_id=?")
                    ->execute([$name, $rate, $rateUnit, $capacity, $features, $aid, $society_id]);
                $msg = 'Facility updated.';
            }
        }

        if (isset($_POST['toggle_amenity_status'])) {
            $aid = (int)$_POST['amenity_id'];
            $newStatus = $_POST['new_amenity_status'] ?? 'available';
            if (in_array($newStatus, ['available','maintenance'])) {
                $pdo->prepare("UPDATE amenities SET status=? WHERE id=? AND society_id=?")
                    ->execute([$newStatus, $aid, $society_id]);
                $msg = 'Facility status updated.';
            }
        }

        if (isset($_POST['delete_amenity'])) {
            $aid = (int)$_POST['amenity_id'];
            $pdo->prepare("DELETE FROM amenities WHERE id=? AND society_id=?")->execute([$aid, $society_id]);
            $pdo->prepare("DELETE FROM facility_bookings WHERE amenity_id=? AND society_id=?")->execute([$aid, $society_id]);
            $msg = 'Facility removed.';
        }

        if (isset($_POST['cancel_booking'])) {
            $bid = (int)$_POST['booking_id'];
            $bStmt = $pdo->prepare("SELECT fb.user_id, a.name FROM facility_bookings fb JOIN amenities a ON a.id=fb.amenity_id WHERE fb.id=? AND fb.society_id=?");
            $bStmt->execute([$bid, $society_id]);
            $brow = $bStmt->fetch();
            if ($brow) {
                $pdo->prepare("UPDATE facility_bookings SET status='cancelled' WHERE id=? AND society_id=?")->execute([$bid, $society_id]);
                notify($pdo, $society_id, $brow['user_id'], $user_id, 'rejection',
                    'Your booking for ' . $brow['name'] . ' was cancelled by the admin.', null);
                $msg = 'Booking cancelled and the resident notified.';
            }
        }

        header('Location: /admin_dashboard.php'); exit;
    }

    // ── Fetch stats
    $total_users     = $pdo->prepare("SELECT COUNT(*) FROM users WHERE society_id=?");
    $total_users->execute([$society_id]); $total_users = $total_users->fetchColumn();

    $total_admins    = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='admin' AND society_id=?");
    $total_admins->execute([$society_id]); $total_admins = $total_admins->fetchColumn();

    $total_residents = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='resident' AND society_id=?");
    $total_residents->execute([$society_id]); $total_residents = $total_residents->fetchColumn();

    $total_staff     = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='staff' AND society_id=?");
    $total_staff->execute([$society_id]); $total_staff = $total_staff->fetchColumn();

    $pending_users   = $pdo->prepare("SELECT COUNT(*) FROM users WHERE is_active=0 AND society_id=?");
    $pending_users->execute([$society_id]); $pending_users = $pending_users->fetchColumn();

    $users = $pdo->prepare("SELECT * FROM users WHERE society_id=? AND id != ? ORDER BY is_active ASC, created_at DESC");
    $users->execute([$society_id, $owner_id]); $users = $users->fetchAll();

    $users_list = $pdo->prepare("SELECT id,name,unit FROM users WHERE is_active=1 AND society_id=? AND id != ? ORDER BY name");
    $users_list->execute([$society_id, $owner_id]); $users_list = $users_list->fetchAll();

    $total_complaints = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE society_id=?");
    $total_complaints->execute([$society_id]); $total_complaints = $total_complaints->fetchColumn();

    $open_complaints = $pdo->prepare("SELECT COUNT(*) FROM complaints WHERE status='open' AND society_id=?");
    $open_complaints->execute([$society_id]); $open_complaints = $open_complaints->fetchColumn();

    $complaints_list = $pdo->prepare("
        SELECT c.*, u.name AS raiser_name, u.unit AS raiser_unit, u.block AS raiser_block, u.role AS raiser_role
        FROM complaints c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.society_id = ?
        ORDER BY FIELD(c.status,'open','in_progress','resolved','closed'), c.created_at DESC
    ");
    $complaints_list->execute([$society_id]); $complaints_list = $complaints_list->fetchAll();

    // ── Amenities + their upcoming/active bookings ────────────────
    $amenities_list = $pdo->prepare("SELECT * FROM amenities WHERE society_id=? ORDER BY name ASC");
    $amenities_list->execute([$society_id]); $amenities_list = $amenities_list->fetchAll();

    $bookings_by_amenity = [];
    if ($amenities_list) {
        $bkStmt = $pdo->prepare("
            SELECT fb.*, u.name AS booker_name, u.unit AS booker_unit
            FROM facility_bookings fb
            LEFT JOIN users u ON u.id = fb.user_id
            WHERE fb.society_id=? AND fb.status='confirmed'
              AND (fb.booking_date > CURDATE() OR (fb.booking_date = CURDATE() AND fb.end_time >= CURTIME()))
            ORDER BY fb.booking_date ASC, fb.start_time ASC
        ");
        $bkStmt->execute([$society_id]);
        foreach ($bkStmt->fetchAll() as $b) { $bookings_by_amenity[$b['amenity_id']][] = $b; }
    }

    $total_bills = $pdo->prepare("SELECT COUNT(*) FROM billing WHERE society_id=?");
    $total_bills->execute([$society_id]); $total_bills = $total_bills->fetchColumn();

    $total_collected = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid' AND society_id=?");
    $total_collected->execute([$society_id]); $total_collected = $total_collected->fetchColumn();

    $total_pending_amt = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status IN ('pending','overdue','pending_verification') AND society_id=?");
    $total_pending_amt->execute([$society_id]); $total_pending_amt = $total_pending_amt->fetchColumn();

    $total_failed = $pdo->prepare("SELECT COUNT(*) FROM billing WHERE status='failed' AND society_id=?");
    $total_failed->execute([$society_id]); $total_failed = $total_failed->fetchColumn();

    $total_overdue = $pdo->prepare("SELECT COUNT(*) FROM billing WHERE status='overdue' AND society_id=?");
    $total_overdue->execute([$society_id]); $total_overdue = $total_overdue->fetchColumn();

    $total_unverified = $pdo->prepare("SELECT COUNT(*) FROM billing WHERE status='pending_verification' AND society_id=?");
    $total_unverified->execute([$society_id]); $total_unverified = $total_unverified->fetchColumn();

    $payments = $pdo->prepare("
        SELECT b.*, u.name as resident_name, u.email as resident_email, u.phone as resident_phone, u.role as bill_role
        FROM billing b LEFT JOIN users u ON b.user_id = u.id
        WHERE b.society_id = ? AND u.role != 'admin'
        ORDER BY b.created_at DESC
    ");
    $payments->execute([$society_id]); $payments = $payments->fetchAll();

    $pending_list = array_filter($users, fn($u) => !$u['is_active']);

    $my_bills = $pdo->prepare("SELECT * FROM billing WHERE user_id=? AND society_id=? ORDER BY created_at DESC");
    $my_bills->execute([$user_id, $society_id]); $my_bills = $my_bills->fetchAll();
    $my_pending_dues = array_sum(array_map(fn($b)=>in_array($b['status'],['pending','overdue'])?$b['amount']:0, $my_bills));

} catch (PDOException $e) {
    $users = $users_list = $pending_list = $payments = $complaints_list = $amenities_list = $bookings_by_amenity = [];
    $total_users=$total_admins=$total_residents=$total_staff=$pending_users=$active_society=0;
    $total_complaints=$open_complaints=$total_bills=$total_collected=$total_pending_amt=$total_failed=$total_overdue=$total_unverified=0;
    $err = 'DB Error: '.$e->getMessage();
    $page_society_name = $page_society_name ?? '';
}

$colors = ['#2d7a52','#1d4ed8','#6d28d9','#c2410c','#0369a1','#b45309','#be123c'];

$live_updates = [
    ['icon'=>'fa-circle-check','color'=>'#16a34a','text'=>'Water pump restarted – Block A','time'=>'Just now'],
    ['icon'=>'fa-wave-square','color'=>'#6d28d9','text'=>'Visitor OTP verified – Gate 1','time'=>'2 min ago'],
    ['icon'=>'fa-circle-check','color'=>'#16a34a','text'=>'Payment received ₹4,500 – Flat 102-A','time'=>'5 min ago'],
    ['icon'=>'fa-triangle-exclamation','color'=>'#c2410c','text'=>'Fire alarm test – Block C','time'=>'12 min ago'],
    ['icon'=>'fa-circle-xmark','color'=>'#dc2626','text'=>'New complaint filed – Elevator noise','time'=>'18 min ago'],
    ['icon'=>'fa-wave-square','color'=>'#6d28d9','text'=>'Guard shift change completed – Gate 2','time'=>'25 min ago'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Admin Dashboard - ColonyCare</title>
<meta name="robots" content="noindex, nofollow">
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{--green:#2d7a52;--green-dark:#1a5c3a;--green-btn:#1e7a50;--green-hover:#145f3f;--green-light:#e8f5ee;--green-soft:#f0f9f4;--text-primary:#1a2e22;--text-sub:#5a7060;--text-muted:#8fa898;--border:#e5ece8;--bg:#f5f7f6;--white:#fff;--shadow:0 1px 6px rgba(0,0,0,.07);--radius:12px;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text-primary);min-height:100vh;}
.topbar{background:var(--green-dark);height:54px;display:flex;align-items:center;padding:0 28px;gap:14px;position:sticky;top:0;z-index:100;}
.tb-brand{display:flex;align-items:center;gap:9px;color:#fff;font-weight:700;font-size:.98rem;}
.tb-divider{width:1px;height:20px;background:rgba(255,255,255,.2);}
.tb-portal{font-size:.8rem;color:rgba(255,255,255,.6);}
.tb-right{margin-left:auto;display:flex;gap:6px;}
.tb-btn{display:inline-flex;align-items:center;gap:6px;padding:7px 13px;border-radius:8px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;text-decoration:none;border:none;transition:all .2s;}
.tb-back{background:rgba(255,255,255,.1);color:#fff;}.tb-back:hover{background:rgba(255,255,255,.2);}
.tb-logout{background:rgba(255,255,255,.1);color:#fff;}.tb-logout:hover{background:rgba(220,38,38,.3);}
.content{max-width:1300px;margin:0 auto;padding:24px 28px;}
.hero-row{display:flex;align-items:flex-start;justify-content:space-between;margin-bottom:22px;gap:16px;flex-wrap:wrap;}
.hero-left h1{font-size:1.6rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:10px;margin-bottom:4px;}
.hero-society{font-size:.85rem;color:var(--green);font-weight:600;margin-bottom:6px;display:flex;align-items:center;gap:6px;}
.hero-left p{font-size:.83rem;color:var(--text-muted);}
.hero-right{display:flex;align-items:center;gap:10px;}
.clock{background:var(--white);border:1px solid var(--border);border-radius:8px;padding:7px 14px;font-size:.85rem;font-weight:600;color:var(--text-primary);font-variant-numeric:tabular-nums;}
.btn-refresh{background:var(--white);border:1px solid var(--border);border-radius:8px;padding:7px 14px;font-family:inherit;font-size:.82rem;font-weight:600;cursor:pointer;display:flex;align-items:center;gap:6px;color:var(--text-sub);transition:all .2s;}
.btn-refresh:hover{border-color:var(--green);color:var(--green);}
.quick-row{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;margin-bottom:22px;}
.qa-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;display:flex;align-items:center;gap:13px;cursor:pointer;transition:all .2s;}
.qa-card:hover{border-color:var(--green);box-shadow:0 4px 16px rgba(45,122,82,.12);transform:translateY(-1px);}
.qa-icon{width:40px;height:40px;border-radius:10px;display:flex;align-items:center;justify-content:center;font-size:.95rem;flex-shrink:0;}
.qi-green{background:var(--green-light);color:var(--green);}
.qi-blue{background:#eff6ff;color:#1d4ed8;}
.qi-teal{background:#e0f7fa;color:#0369a1;}
.qi-red{background:#fef2f2;color:#dc2626;}
.qa-card span{font-size:.88rem;font-weight:600;color:var(--text-primary);}
.main-grid{display:grid;grid-template-columns:1fr 1fr 320px;gap:16px;margin-bottom:22px;}
.stat-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:22px 24px;position:relative;}
.stat-card .trend{position:absolute;top:18px;right:18px;color:var(--green);font-size:.85rem;}
.stat-card .si{font-size:1rem;color:var(--green);margin-bottom:10px;}
.stat-card h3{font-size:1.7rem;font-weight:700;color:var(--text-primary);margin-bottom:3px;}
.stat-card p{font-size:.77rem;color:var(--text-muted);}
.stat-card .sub{font-size:.72rem;color:var(--text-muted);margin-top:3px;}
.stat-grid-2{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.live-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.live-head{padding:16px 18px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;}
.live-head h3{font-size:.92rem;font-weight:700;color:var(--text-primary);display:flex;align-items:center;gap:8px;}
.live-dot{width:8px;height:8px;border-radius:50%;background:#16a34a;animation:pulse 2s infinite;}
@keyframes pulse{0%,100%{opacity:1}50%{opacity:.4}}
.live-item{padding:13px 18px;border-bottom:1px solid var(--border);display:flex;align-items:flex-start;gap:11px;}
.live-item:last-child{border-bottom:none;}
.live-icon{width:26px;height:26px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.7rem;flex-shrink:0;margin-top:1px;}
.live-text strong{font-size:.82rem;font-weight:600;color:var(--text-primary);display:block;margin-bottom:2px;}
.live-text span{font-size:.72rem;color:var(--text-muted);}

/* ── TABS ── */
.tabs-wrap{background:var(--bg);border-radius:99px;padding:5px;display:flex;gap:3px;margin-bottom:20px;border:1px solid var(--border);max-width:680px;}
.tab-btn{flex:1;display:flex;align-items:center;justify-content:center;gap:6px;padding:9px 14px;font-family:inherit;font-size:.82rem;font-weight:500;color:var(--text-muted);border:none;background:none;cursor:pointer;border-radius:99px;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:var(--text-primary);}
.tab-btn.active{background:var(--white);color:var(--text-primary);font-weight:600;box-shadow:0 1px 6px rgba(0,0,0,.08);}
.tab-btn .cnt{background:var(--green-light);color:var(--green);font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:99px;margin-left:3px;}
.tab-btn .cnt-warn{background:#fef9c3;color:#92400e;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:99px;margin-left:3px;}
.tab-section{display:none;}.tab-section.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(8px)}to{opacity:1;transform:translateY(0)}}
.card{background:var(--white);border-radius:var(--radius);border:1px solid var(--border);overflow:hidden;}
.card-head{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;}
.card-head h3{font-size:.95rem;font-weight:700;color:var(--text-primary);}
.badge-cnt{background:var(--green-light);color:var(--green);font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:99px;}
.badge-pending{background:#fef9c3;color:#854d0e;font-size:.7rem;font-weight:700;padding:3px 10px;border-radius:99px;}
.search-bar{margin:0 18px 14px;display:flex;align-items:center;gap:9px;background:var(--bg);border:1px solid var(--border);border-radius:99px;padding:9px 16px;}
.search-bar i{color:var(--text-muted);font-size:.82rem;}
.search-bar input{border:none;outline:none;font-family:inherit;font-size:.84rem;background:transparent;color:var(--text-primary);width:100%;}
.user-row{display:flex;align-items:center;padding:14px 20px;border-top:1px solid var(--border);gap:13px;transition:background .15s;}
.user-row:hover{background:var(--green-soft);}
.u-avatar{width:38px;height:38px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.88rem;flex-shrink:0;}
.u-info{flex:1;min-width:0;}
.u-name{font-size:.86rem;font-weight:600;color:var(--text-primary);margin-bottom:2px;}
.u-meta{font-size:.74rem;color:var(--text-muted);}
.u-actions{display:flex;align-items:center;gap:6px;flex-shrink:0;flex-wrap:wrap;}
.role-badge{font-size:.65rem;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:99px;}
.rb-admin{background:#fef3c7;color:#92400e;}
.rb-resident{background:var(--green-light);color:var(--green-dark);}
.rb-staff{background:#ede9fe;color:#5b21b6;}
.rb-accountant{background:#d1fae5;color:#065f46;}
.rb-vendor{background:#ffedd5;color:#9a3412;}
.rb-society{background:#e0f2fe;color:#0c4a6e;}
.u-status{font-size:.7rem;font-weight:600;padding:2px 8px;border-radius:99px;}
.us-active{background:var(--green-light);color:var(--green-dark);}
.us-inactive{background:#fef9c3;color:#92400e;}
.act-btn{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:var(--white);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.72rem;color:var(--text-muted);transition:all .2s;}
.act-btn:hover.activate{border-color:#16a34a;color:#16a34a;background:#f0fdf4;}
.act-btn:hover.deactivate{border-color:#c2410c;color:#c2410c;background:#fff7ed;}
.act-btn:hover.delete{border-color:#dc2626;color:#dc2626;background:#fef2f2;}
.role-select{font-family:inherit;font-size:.72rem;border:1.5px solid var(--border);border-radius:8px;padding:3px 6px;background:#fff;cursor:pointer;outline:none;color:var(--text-primary);}
.reports-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:16px;}
.report-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:24px;}
.report-card .ri{font-size:1.6rem;color:var(--green);margin-bottom:14px;}
.report-card h4{font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:6px;}
.report-card p{font-size:.78rem;color:var(--text-muted);margin-bottom:16px;line-height:1.5;}
.report-actions{display:flex;gap:8px;}
.btn-view,.btn-export{padding:6px 14px;background:var(--white);color:var(--text-primary);border:1.5px solid var(--border);border-radius:8px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;transition:all .2s;}
.btn-view:hover,.btn-export:hover{border-color:var(--green);color:var(--green);}
.rr-empty{text-align:center;padding:50px 20px;color:var(--text-muted);}
.rr-empty i{font-size:2.5rem;display:block;margin-bottom:12px;}

/* ── COMPLAINTS TAB ── */
.empty-state{text-align:center;padding:50px 20px;color:var(--text-muted);}
.complaints-list{display:flex;flex-direction:column;gap:14px;}
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

/* ── FACILITIES / AMENITIES ── */
.amenity-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:16px;}
.amenity-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.am-head{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:10px;}
.am-head h4{font-size:1rem;font-weight:700;color:var(--text-primary);}
.am-badge{font-size:.68rem;font-weight:700;padding:4px 10px;border-radius:99px;white-space:nowrap;}
.am-available{background:#dcfce7;color:#166534;}
.am-booked{background:#fef9c3;color:#92400e;}
.am-maintenance{background:#fee2e2;color:#dc2626;}
.am-row{display:flex;justify-content:space-between;font-size:.82rem;color:var(--text-sub);padding:4px 0;}
.am-row strong{color:var(--text-primary);}
.am-features{font-size:.76rem;color:var(--text-muted);margin:6px 0 12px;}
.am-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px;border-top:1px solid var(--border);padding-top:12px;}
.am-btn-edit,.am-btn-toggle{background:var(--bg);border:1px solid var(--border);border-radius:7px;padding:6px 12px;font-family:inherit;font-size:.76rem;font-weight:600;color:var(--text-primary);cursor:pointer;}
.am-btn-delete{background:#fee2e2;color:#dc2626;border:none;border-radius:7px;padding:6px 10px;cursor:pointer;}
.am-view-bookings{width:100%;margin-top:10px;background:none;border:1px dashed var(--border);border-radius:8px;padding:8px;font-family:inherit;font-size:.78rem;color:var(--green);cursor:pointer;font-weight:600;}
.cc-category{font-size:.75rem;color:var(--text-muted);margin-bottom:8px;display:flex;align-items:center;gap:5px;}
.cc-desc{font-size:.85rem;color:var(--text-primary);line-height:1.5;margin-bottom:10px;}
.cc-response{background:var(--green-light,#e8f5ee);border-radius:9px;padding:10px 12px;font-size:.82rem;color:#1a5c3a;margin-bottom:12px;}
.cc-form{display:flex;gap:8px;flex-wrap:wrap;border-top:1px solid var(--border);padding-top:12px;}
.cc-form select{font-family:inherit;font-size:.8rem;border:1.5px solid var(--border);border-radius:8px;padding:7px 10px;background:#fff;}
.cc-form input[type="text"]{flex:1;min-width:180px;font-family:inherit;font-size:.8rem;border:1.5px solid var(--border);border-radius:8px;padding:7px 10px;}
.cc-form button{background:var(--green-btn);color:#fff;border:none;border-radius:8px;padding:7px 16px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;white-space:nowrap;}
.cc-form button:hover{background:var(--green-hover);}
.btn-primary{padding:9px 18px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.btn-primary:hover{background:var(--green-hover);}
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px 26px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .25s ease;}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal h3{font-size:1rem;font-weight:700;margin-bottom:3px;color:var(--text-primary);}
.modal p{font-size:.8rem;color:var(--text-muted);margin-bottom:16px;}
.ff{margin-bottom:12px;}.ff label{font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;color:var(--text-primary);}
.ff input,.ff select,.ff textarea{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;color:var(--text-primary);outline:none;background:#fff;transition:border-color .2s;}
.ff input:focus,.ff select:focus,.ff textarea:focus{border-color:var(--green);}
.ff textarea{resize:vertical;min-height:70px;}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:10px;}
.btn-cancel{padding:9px 18px;background:var(--bg);color:var(--text-sub);border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;}
.alert{padding:11px 14px;border-radius:9px;font-size:.84rem;margin-bottom:16px;display:flex;align-items:flex-start;gap:9px;border:1px solid;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.sec-alert{background:#fef2f2;border:1px solid #fecaca;border-radius:var(--radius);padding:16px 20px;align-items:center;gap:12px;margin-bottom:16px;display:none;}
.sec-alert.show{display:flex;}

/* Permission card */
.perm-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;overflow:hidden;transition:box-shadow .2s;}
.perm-card:hover{box-shadow:0 4px 20px rgba(0,0,0,.09);}
.perm-card-top{padding:18px 20px;display:flex;align-items:flex-start;gap:14px;}
.perm-avatar{width:48px;height:48px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:1.1rem;flex-shrink:0;}
.perm-info{flex:1;min-width:0;}
.perm-name{font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;}
.perm-meta{display:flex;flex-wrap:wrap;gap:10px;font-size:.76rem;color:var(--text-muted);}
.perm-meta span{display:flex;align-items:center;gap:4px;}
.perm-card-bottom{border-top:1px solid var(--border);padding:14px 20px;background:var(--bg);display:flex;align-items:center;gap:10px;flex-wrap:wrap;}
.perm-label{font-size:.78rem;font-weight:600;color:var(--text-sub);margin-right:4px;}
.role-pill{padding:4px 12px;border-radius:99px;font-size:.75rem;font-weight:600;cursor:pointer;border:1.5px solid var(--border);background:var(--white);color:var(--text-sub);transition:all .2s;}
.role-pill:hover{border-color:var(--green);color:var(--green);}
.role-pill.sel-resident{background:#e8f5ee;color:#1a5c3a;border-color:#2d7a52;}
.role-pill.sel-admin{background:#fef3c7;color:#92400e;border-color:#d97706;}
.role-pill.sel-staff{background:#ede9fe;color:#5b21b6;border-color:#7c3aed;}
.role-pill.sel-accountant{background:#ecfdf5;color:#065f46;border-color:#059669;}
.role-pill.sel-vendor{background:#fff7ed;color:#9a3412;border-color:#ea580c;}
.role-pill.sel-society_member{background:#f0f9ff;color:#0c4a6e;border-color:#0284c7;}
.btn-approve{padding:9px 20px;background:var(--green-btn);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.btn-approve:hover{background:var(--green-hover);}
.btn-reject{padding:9px 18px;background:#fff;color:#dc2626;border:1.5px solid #fecaca;border-radius:9px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:all .2s;}
.btn-reject:hover{background:#fef2f2;}

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

/* ── PAYMENTS TAB ── */
.pay-stats{display:grid;grid-template-columns:repeat(5,1fr);gap:14px;margin-bottom:20px;}
.pay-stat{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;}
.pay-stat .ps-icon{width:36px;height:36px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:.85rem;margin-bottom:10px;}
.ps-green{background:var(--green-light);color:var(--green);}
.ps-yellow{background:#fef9c3;color:#92400e;}
.ps-red{background:#fef2f2;color:#dc2626;}
.ps-blue{background:#eff6ff;color:#1d4ed8;}
.pay-stat .ps-val{font-size:1.4rem;font-weight:700;color:var(--text-primary);margin-bottom:2px;}
.pay-stat .ps-lbl{font-size:.73rem;color:var(--text-muted);}
.pay-filters{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;margin-bottom:16px;}
.pay-filters-head{font-size:.82rem;font-weight:700;color:var(--text-primary);margin-bottom:12px;display:flex;align-items:center;gap:7px;}
.pay-filters-row{display:flex;flex-wrap:wrap;gap:10px;align-items:center;}
.pay-filters-row input,.pay-filters-row select{font-family:inherit;font-size:.82rem;border:1.5px solid var(--border);border-radius:9px;padding:8px 12px;background:#fff;outline:none;color:var(--text-primary);transition:border-color .2s;}
.pay-filters-row input:focus,.pay-filters-row select:focus{border-color:var(--green);}
.pay-search{flex:1;min-width:180px;}
.filter-select{min-width:120px;}
.date-input{min-width:130px;}
.btn-clear-filter{background:none;border:1.5px solid var(--border);border-radius:9px;padding:8px 14px;font-family:inherit;font-size:.8rem;color:var(--text-muted);cursor:pointer;transition:all .2s;display:flex;align-items:center;gap:5px;}
.btn-clear-filter:hover{border-color:#dc2626;color:#dc2626;}
.pay-table-wrap{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;}
.pay-table-head{padding:16px 20px;display:flex;align-items:center;justify-content:space-between;border-bottom:1px solid var(--border);}
.pay-table-head h3{font-size:.92rem;font-weight:700;color:var(--text-primary);}
.btn-export-csv{background:var(--white);border:1.5px solid var(--border);border-radius:9px;padding:7px 14px;font-family:inherit;font-size:.8rem;font-weight:600;cursor:pointer;color:var(--text-sub);display:flex;align-items:center;gap:6px;transition:all .2s;}
.btn-export-csv:hover{border-color:var(--green);color:var(--green);}
.pay-table{width:100%;border-collapse:collapse;}
.pay-table thead tr{background:var(--bg);}
.pay-table th{padding:10px 16px;text-align:left;font-size:.73rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;border-bottom:1px solid var(--border);white-space:nowrap;}
.pay-table tbody tr{border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;}
.pay-table tbody tr:last-child{border-bottom:none;}
.pay-table tbody tr:hover{background:var(--green-soft);}
.pay-table td{padding:13px 16px;font-size:.83rem;color:var(--text-primary);vertical-align:middle;}
.txn-id{font-size:.75rem;font-weight:700;color:var(--text-muted);font-family:monospace;}
.p-resident{font-weight:600;color:var(--text-primary);}
.p-email{font-size:.72rem;color:var(--text-muted);}
.pay-badge{font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:99px;text-transform:capitalize;}
.pb-paid{background:#dcfce7;color:#166534;}
.pb-pending{background:#fef9c3;color:#854d0e;}
.pb-overdue{background:#fee2e2;color:#991b1b;}
.pb-failed{background:#f3f4f6;color:#374151;}
.pb-pending_verification{background:#dbeafe;color:#1d4ed8;}
.pay-amount{font-weight:700;font-size:.9rem;}
.pay-actions-cell{display:flex;gap:5px;}
.pa-btn{width:28px;height:28px;border-radius:7px;border:1.5px solid var(--border);background:var(--white);display:flex;align-items:center;justify-content:center;cursor:pointer;font-size:.7rem;color:var(--text-muted);transition:all .2s;}
.pa-btn:hover.pa-pay{border-color:#16a34a;color:#16a34a;background:#f0fdf4;}
.pa-btn:hover.pa-overdue{border-color:#c2410c;color:#c2410c;background:#fff7ed;}
.pa-btn:hover.pa-del{border-color:#dc2626;color:#dc2626;background:#fef2f2;}
.pa-btn:hover.pa-view{border-color:#1d4ed8;color:#1d4ed8;background:#eff6ff;}
.mode-chip{font-size:.72rem;color:var(--text-sub);background:var(--bg);border:1px solid var(--border);padding:2px 8px;border-radius:6px;}
/* Row detail expand */
.pay-detail-row{display:none;background:var(--green-soft);}
.pay-detail-row.open{display:table-row;}
.pay-detail-inner{padding:14px 20px;font-size:.8rem;color:var(--text-sub);display:flex;flex-wrap:wrap;gap:18px;}
.pd-item{display:flex;flex-direction:column;gap:2px;}
.pd-label{font-size:.68rem;font-weight:700;text-transform:uppercase;letter-spacing:.04em;color:var(--text-muted);}
.pd-val{font-size:.82rem;font-weight:600;color:var(--text-primary);}
.no-pay{text-align:center;padding:48px 20px;color:var(--text-muted);}
.no-pay i{font-size:2.2rem;display:block;margin-bottom:12px;color:var(--border);}

/* ── Verification banner row (highlighted for pending_verification bills) ── */
.verify-banner{background:#eff6ff;border:1px solid #bfdbfe;border-radius:var(--radius);padding:14px 18px;margin-bottom:16px;display:flex;align-items:center;gap:12px;}
.verify-banner i{color:#1d4ed8;font-size:1.1rem;}
.verify-banner-text{font-size:.85rem;color:#1e3a8a;font-weight:600;}

/* ── Screenshot proof modal ── */
.proof-modal-img{width:100%;max-height:420px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:var(--bg);margin-bottom:14px;}
.proof-info-row{display:flex;justify-content:space-between;font-size:.83rem;padding:6px 0;border-bottom:1px solid var(--border);}
.proof-info-row:last-child{border-bottom:none;}
.proof-info-row span:first-child{color:var(--text-muted);}
.proof-info-row span:last-child{font-weight:600;color:var(--text-primary);}

/* ── HAMBURGER ─────────────────────────────────────── */
.ham-btn{display:none;background:rgba(255,255,255,.1);border:none;cursor:pointer;color:#fff;font-size:1.2rem;padding:6px 10px;border-radius:8px;margin-left:6px;}
.ham-btn:hover{background:rgba(255,255,255,.2);}
.mob-nav-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);}
.mob-nav-overlay.open{display:block;}
.mob-nav-panel{position:absolute;top:0;right:0;width:260px;height:100%;background:linear-gradient(160deg,#0b835b,#1a5c3a);display:flex;flex-direction:column;animation:slideRight .25s ease;overflow-y:auto;}
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

@media(max-width:1100px){
    .main-grid{grid-template-columns:1fr 1fr;}
    .live-card{grid-column:1/-1;}
}
@media(max-width:900px){
    .topbar{padding:0 14px;}
    .tb-portal{display:none;}
    .tb-divider{display:none;}
    .tb-back{display:none;}
    .tb-logout{display:none;}
    .ham-btn{display:flex;align-items:center;}
    .quick-row{grid-template-columns:1fr 1fr;}
    .main-grid{grid-template-columns:1fr;}
    .reports-grid{grid-template-columns:1fr;}
    .content{padding:16px;}
    .pay-stats{grid-template-columns:1fr 1fr;}
    .tabs-wrap{max-width:100%;overflow-x:auto;flex-wrap:nowrap;border-radius:12px;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
    .tabs-wrap::-webkit-scrollbar{display:none;}
    .tab-btn{flex-shrink:0;}
    .data-table{display:block;overflow-x:auto;}
    .pay-table{display:block;overflow-x:auto;}
    .user-row{flex-wrap:wrap;gap:10px;}
    .u-actions{width:100%;justify-content:flex-end;}
}
@media(max-width:600px){
    .quick-row{grid-template-columns:1fr 1fr;}
    .form-row{grid-template-columns:1fr;}
    .pay-stats{grid-template-columns:1fr 1fr;}
    .stat-grid-2{grid-template-columns:1fr;}
    .modal{padding:20px 14px;}
    .modal-overlay{padding:10px;}
    .pay-filters-row{flex-direction:column;}
    .pay-search,.filter-select,.date-input{width:100%;}
}
@media(max-width:480px){
    .quick-row{grid-template-columns:1fr 1fr;}
    .pay-stats{grid-template-columns:1fr 1fr;}
    .hero-row{flex-direction:column;gap:12px;}
    .hero-right{width:100%;}
    .tb-brand{font-size:.85rem;}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="tb-brand"><i class="fa fa-building"></i> ColonyCare</div>
  <div class="tb-divider"></div>
  <span class="tb-portal">Admin / RWA Dashboard</span>
  <div class="tb-right">
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
    <a href="/index.php" class="tb-btn tb-back"><i class="fa fa-arrow-left"></i> Back</a>
    <a href="/auth/logout.php" class="tb-btn tb-logout"><i class="fa fa-right-from-bracket"></i> Logout</a>
    <button class="ham-btn" onclick="openAdminMobNav()" aria-label="Menu"><i class="fa fa-bars"></i></button>
  </div>
</header>

<!-- MOBILE NAV -->
<div class="mob-nav-overlay" id="adminMobNav" onclick="closeAdminMobNavBg(event)">
  <div class="mob-nav-panel">
    <div class="mob-nav-head">
      <span>Admin Dashboard</span>
      <button class="mob-close-btn" onclick="closeAdminMobNav()"><i class="fa fa-xmark"></i></button>
    </div>
    <div class="mob-nav-body">
      <div style="padding:8px 18px 4px;font-size:.7rem;font-weight:700;color:rgba(255,255,255,.4);text-transform:uppercase;letter-spacing:.06em;"><?= htmlspecialchars($user_name) ?></div>
      <div style="padding:2px 18px 10px;font-size:.75rem;color:rgba(255,255,255,.5);"><?= htmlspecialchars($page_society_name) ?></div>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="switchTab('users',null);closeAdminMobNav()"><i class="fa fa-users"></i> Users</button>
      <button class="mob-nav-item" onclick="switchTab('requests',null);closeAdminMobNav()"><i class="fa fa-user-clock"></i> Approvals</button>
      <button class="mob-nav-item" onclick="switchTab('payments',null);closeAdminMobNav()"><i class="fa fa-credit-card"></i> Payments</button>
      <button class="mob-nav-item" onclick="switchTab('mybills',null);closeAdminMobNav()"><i class="fa fa-wallet"></i> My Bills</button>
      <button class="mob-nav-item" onclick="switchTab('complaints',null);closeAdminMobNav()"><i class="fa fa-comments"></i> Complaints</button>
      <button class="mob-nav-item" onclick="switchTab('facilities',null);closeAdminMobNav()"><i class="fa fa-building"></i> Facilities</button>
      <button class="mob-nav-item" onclick="switchTab('reports',null);closeAdminMobNav()"><i class="fa fa-chart-bar"></i> Reports</button>
    </div>
    <div class="mob-nav-foot">
      <a href="/index.php" class="mob-back-btn"><i class="fa fa-arrow-left"></i> Back to Home</a>
      <a href="/auth/logout.php" class="mob-logout-btn"><i class="fa fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</div>

<div class="content">

  <?php if($msg): ?><div class="alert alert-success"><i class="fa fa-check-circle" style="flex-shrink:0;margin-top:1px"></i><span><?= htmlspecialchars($msg) ?></span></div><?php endif; ?>
  <?php if($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation" style="flex-shrink:0"></i><?= htmlspecialchars($err) ?></div><?php endif; ?>

  <div class="sec-alert" id="secAlert">
    <i class="fa fa-shield-halved" style="color:#dc2626;font-size:1.1rem"></i>
    <span style="font-size:.85rem;color:#991b1b;font-weight:600">🚨 Security Alert sent to all residents and gate staff!</span>
    <button onclick="document.getElementById('secAlert').classList.remove('show')" style="margin-left:auto;background:none;border:none;cursor:pointer;color:#991b1b"><i class="fa fa-xmark"></i></button>
  </div>

  <!-- HERO -->
  <div class="hero-row">
    <div class="hero-left">
      <h1>Admin Dashboard 🏢</h1>
      <?php if($page_society_name): ?>
      <div class="hero-society"><i class="fa fa-building"></i> <?= htmlspecialchars($page_society_name) ?></div>
      <?php endif; ?>
      <p><?= date('l, d F Y') ?> &bull; <?= $total_users ?> Users &bull; <?= $pending_users ?> Pending</p>
    </div>
    <div class="hero-right">
      <div class="clock" id="clock">--:-- --</div>
      <script>
      (function(){
          function tick(){
              var now=new Date(),h=now.getHours(),m=now.getMinutes(),s=now.getSeconds(),ap=h>=12?'PM':'AM';
              h=h%12||12;
              document.getElementById('clock').textContent=(h<10?'0':'')+h+':'+(m<10?'0':'')+m+':'+(s<10?'0':'')+s+' '+ap;
          }
          tick();
          setInterval(tick,1000);
      })();
      </script>
      <button class="btn-refresh" onclick="location.reload()"><i class="fa fa-rotate-right"></i> Refresh</button>
    </div>
  </div>

  <!-- QUICK ACTIONS -->
  <div class="quick-row">
    <div class="qa-card" onclick="openModal('noticeModal')"><div class="qa-icon qi-green"><i class="fa fa-paper-plane"></i></div><span>Send Notice</span></div>
    <div class="qa-card" onclick="openModal('addResModal')"><div class="qa-icon qi-blue"><i class="fa fa-user-plus"></i></div><span>Add Resident</span></div>
    <div class="qa-card" onclick="openModal('billModal')"><div class="qa-icon qi-teal"><i class="fa fa-file-invoice-dollar"></i></div><span>Generate Bills</span></div>
    <div class="qa-card" onclick="triggerSecAlert()"><div class="qa-icon qi-red"><i class="fa fa-shield-halved"></i></div><span>Security Alert</span></div>
  </div>

  <!-- MAIN GRID -->
  <div class="main-grid">
    <div class="stat-grid-2">
      <div class="stat-card"><i class="fa fa-arrow-trend-up trend"></i><div class="si"><i class="fa fa-users"></i></div><h3><?= $total_users ?></h3><p>Total Users</p><div class="sub"><?= $total_admins ?> admins</div></div>
      <div class="stat-card" style="border:1px solid <?= $pending_users>0?'#fcd34d':'var(--border)' ?>">
        <div class="si"><i class="fa fa-clock" style="color:<?= $pending_users>0?'#c2410c':'var(--green)' ?>"></i></div>
        <h3 style="color:<?= $pending_users>0?'#c2410c':'var(--text-primary)' ?>"><?= $pending_users ?></h3>
        <p>Pending Approval</p>
        <div class="sub"><?= $pending_users>0?'<span style="color:#c2410c;font-weight:600">Action needed!</span>':'All approved ✓' ?></div>
      </div>
      <div class="stat-card"><i class="fa fa-arrow-trend-up trend"></i><div class="si"><i class="fa fa-indian-rupee-sign" style="color:var(--green)"></i></div><h3>₹<?= number_format($total_collected,0) ?></h3><p>Total Collected</p><div class="sub">from <?= $total_bills ?> bills</div></div>
      <div class="stat-card" style="border:1px solid <?= $total_unverified>0?'#93c5fd':'var(--border)' ?>"><div class="si"><i class="fa fa-hourglass-half" style="color:<?= $total_unverified>0?'#1d4ed8':'var(--green)' ?>"></i></div><h3 style="color:<?= $total_unverified>0?'#1d4ed8':'var(--text-primary)' ?>"><?= $total_unverified ?></h3><p>Awaiting Verification</p><div class="sub"><?= $total_unverified>0?'<span style="color:#1d4ed8;font-weight:600">Review screenshots</span>':'All verified ✓' ?></div></div>
    </div>
    <div></div>
    <div class="live-card">
      <div class="live-head"><h3><span class="live-dot"></span> Live Updates</h3><i class="fa fa-bell" style="color:var(--text-muted);font-size:.9rem"></i></div>
      <?php foreach($live_updates as $lu): ?>
      <div class="live-item">
        <div class="live-icon" style="background:<?= $lu['color'] ?>22;color:<?= $lu['color'] ?>"><i class="fa <?= $lu['icon'] ?>"></i></div>
        <div class="live-text"><strong><?= htmlspecialchars($lu['text']) ?></strong><span><?= $lu['time'] ?></span></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>

  <!-- TABS -->
  <div class="tabs-wrap">
    <button class="tab-btn active" onclick="switchTab('users',this)"><i class="fa fa-users"></i> Users <span class="cnt"><?= $total_users ?></span></button>
    <button class="tab-btn" onclick="switchTab('requests',this)" id="reqTab">
      <i class="fa fa-user-clock"></i> Approvals
      <?php if($pending_users>0): ?><span class="cnt-warn"><?= $pending_users ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('payments',this)">
      <i class="fa fa-credit-card"></i> Payments
      <?php if($total_bills>0): ?><span class="cnt"><?= $total_bills ?></span><?php endif; ?>
      <?php if($total_unverified>0): ?><span class="cnt-warn"><?= $total_unverified ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('mybills',this)">
        <i class="fa fa-wallet"></i> My Bills
        <?php if($my_pending_dues>0): ?><span class="cnt-warn">₹<?= number_format($my_pending_dues,0) ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('complaints',this)">
        <i class="fa fa-comments"></i> Complaints
        <?php if($open_complaints>0): ?><span class="cnt-warn"><?= $open_complaints ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('facilities',this)"><i class="fa fa-building"></i> Facilities</button>
    <button class="tab-btn" onclick="switchTab('reports',this)"><i class="fa fa-chart-bar"></i> Reports</button>
  </div>

  <!-- ══ USERS TAB ══ -->
  <div class="tab-section active" id="tab-users">
    <div class="card">
      <div class="card-head"><h3>User Management</h3><span class="badge-cnt"><?= $total_users ?> users</span></div>
      <div class="search-bar"><i class="fa fa-search"></i><input type="text" id="userSearch" placeholder="Search by name or email..." oninput="filterUsers()"></div>
      <?php if(empty($users)): ?>
      <div style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fa fa-users" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--border)"></i><p>No users yet</p></div>
      <?php else: foreach($users as $u):
        $init  = strtoupper(substr($u['name'],0,1));
        $color = $colors[abs(crc32($u['name']))%count($colors)];
        $rb    = match($u['role']){'admin'=>'rb-admin','staff'=>'rb-staff',default=>'rb-resident'};
      ?>
      <div class="user-row" data-name="<?= strtolower($u['name']) ?>" data-email="<?= strtolower($u['email']) ?>">
        <div class="u-avatar" style="background:<?= $color ?>"><?= $init ?></div>
        <div class="u-info">
          <div class="u-name"><?= htmlspecialchars($u['name']) ?></div>
          <div class="u-meta"><?= htmlspecialchars($u['email']) ?><?php if(!empty($u['unit'])): ?> &nbsp;·&nbsp; <?= htmlspecialchars(($u['block']??'').' '.$u['unit']) ?><?php endif; ?><?php if(!empty($u['society'])): ?> &nbsp;·&nbsp; <?= htmlspecialchars($u['society']) ?><?php endif; ?></div>
        </div>
        <div class="u-actions">
          <span class="role-badge <?= $rb ?>"><?= ucfirst($u['role']) ?></span>
          <span class="u-status <?= $u['is_active']?'us-active':'us-inactive' ?>"><?= $u['is_active']?'Active':'Pending' ?></span>
          <form method="POST" style="display:inline">
            <input type="hidden" name="change_role" value="1">
            <input type="hidden" name="role_uid" value="<?= $u['id'] ?>">
            <select name="new_role" class="role-select" onchange="this.form.submit()" <?= $u['id']==$user_id?'disabled':'' ?>>
              <option value="resident"       <?= $u['role']==='resident'?'selected':'' ?>>Resident</option>
              <option value="admin"          <?= $u['role']==='admin'?'selected':'' ?>>Admin</option>
              <option value="staff"          <?= $u['role']==='staff'?'selected':'' ?>>Staff</option>
              <option value="accountant"     <?= $u['role']==='accountant'?'selected':'' ?>>Accountant</option>
              <option value="vendor"         <?= $u['role']==='vendor'?'selected':'' ?>>Vendor</option>
              <option value="society_member" <?= $u['role']==='society_member'?'selected':'' ?>>Society Member</option>
            </select>
          </form>
          <?php if($u['id'] != $user_id): ?>
          <form method="POST" style="display:inline">
            <input type="hidden" name="toggle_user" value="1">
            <input type="hidden" name="toggle_id" value="<?= $u['id'] ?>">
            <input type="hidden" name="toggle_active" value="<?= $u['is_active']?0:1 ?>">
            <button type="submit" class="act-btn <?= $u['is_active']?'deactivate':'activate' ?>" title="<?= $u['is_active']?'Deactivate':'Activate' ?>"><i class="fa <?= $u['is_active']?'fa-ban':'fa-circle-check' ?>"></i></button>
          </form>
          <form method="POST" style="display:inline" onsubmit="return confirm('Remove this user permanently?')">
            <input type="hidden" name="del_user" value="1">
            <input type="hidden" name="del_id" value="<?= $u['id'] ?>">
            <button type="submit" class="act-btn delete" title="Delete"><i class="fa fa-trash"></i></button>
          </form>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <!-- ══ APPROVALS TAB ══ -->
  <div class="tab-section" id="tab-requests">
    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px">
      <div class="stat-card" style="text-align:center;padding:16px">
        <div style="font-size:2rem;font-weight:700;color:<?= $pending_users>0?'#c2410c':'var(--green)' ?>"><?= $pending_users ?></div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:3px">Awaiting Approval</div>
      </div>
      <div class="stat-card" style="text-align:center;padding:16px">
        <div style="font-size:2rem;font-weight:700;color:var(--green)"><?= count(array_filter($users,fn($u)=>$u['is_active'])) ?></div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:3px">Active Users</div>
      </div>
      <div class="stat-card" style="text-align:center;padding:16px">
        <div style="font-size:2rem;font-weight:700;color:var(--text-primary)"><?= $total_users ?></div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:3px">Total Registered</div>
      </div>
    </div>

    <?php if(empty($pending_list)): ?>
    <div class="card">
      <div class="rr-empty">
        <i class="fa fa-circle-check" style="color:var(--green)"></i>
        <p style="font-size:1rem;font-weight:700;color:var(--green);margin-bottom:6px">All caught up!</p>
        <p style="font-size:.83rem;color:var(--text-muted)">No pending registration requests right now.</p>
      </div>
    </div>
    <?php else: ?>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <h3 style="font-size:.95rem;font-weight:700;color:var(--text-primary)">
        <i class="fa fa-user-clock" style="color:#c2410c"></i>
        &nbsp;<?= count($pending_list) ?> Registration Request<?= count($pending_list)>1?'s':'' ?> Pending
      </h3>
    </div>
    <?php foreach($pending_list as $u):
      $init  = strtoupper(substr($u['name'],0,1));
      $color = $colors[abs(crc32($u['name']))%count($colors)];
      $reg_time = isset($u['created_at']) ? date('d M Y, h:i A', strtotime($u['created_at'])) : 'Unknown';
      $rb = match($u['role']){'admin'=>'rb-admin','staff'=>'rb-staff','accountant'=>'rb-accountant','vendor'=>'rb-vendor','society_member'=>'rb-society',default=>'rb-resident'};
    ?>
    <div class="perm-card" id="pcard-<?= $u['id'] ?>">
      <div class="perm-card-top">
        <div class="perm-avatar" style="background:<?= $color ?>"><?= $init ?></div>
        <div class="perm-info">
          <div class="perm-name"><?= htmlspecialchars($u['name']) ?><span class="role-badge <?= $rb ?>"><?= ucfirst($u['role']) ?></span></div>
          <div class="perm-meta">
            <span><i class="fa fa-envelope"></i> <?= htmlspecialchars($u['email']) ?></span>
            <?php if(!empty($u['phone'])): ?><span><i class="fa fa-phone"></i> <?= htmlspecialchars($u['phone']) ?></span><?php endif; ?>
            <?php if(!empty($u['society'])): ?><span><i class="fa fa-building"></i> <?= htmlspecialchars($u['society']) ?></span><?php endif; ?>
            <?php if(!empty($u['unit'])): ?><span><i class="fa fa-location-dot"></i> <?= htmlspecialchars(($u['block']??'').' '.$u['unit']) ?></span><?php endif; ?>
          </div>
        </div>
        <div style="font-size:.72rem;color:var(--text-muted);flex-shrink:0;text-align:right"><i class="fa fa-clock"></i> Registered<br><?= $reg_time ?></div>
      </div>
      <div class="perm-card-bottom">
        <form method="POST" id="form-<?= $u['id'] ?>" style="display:flex;align-items:center;gap:8px;flex:1;flex-wrap:wrap">
          <input type="hidden" name="approve_user" value="1">
          <input type="hidden" name="approve_id"   value="<?= $u['id'] ?>">
          <input type="hidden" name="approve_role"  id="role-<?= $u['id'] ?>" value="<?= $u['role'] ?>">
          <span class="perm-label">Grant as:</span>
          <button type="button" class="role-pill <?= $u['role']==='resident'?'sel-resident':'' ?>" onclick="setRole(<?= $u['id'] ?>,'resident',this)">🏠 Resident</button>
          <button type="button" class="role-pill <?= $u['role']==='admin'?'sel-admin':'' ?>"    onclick="setRole(<?= $u['id'] ?>,'admin',this)">👑 Admin</button>
          <button type="button" class="role-pill <?= $u['role']==='staff'?'sel-staff':'' ?>"    onclick="setRole(<?= $u['id'] ?>,'staff',this)">👷 Staff</button>
          <button type="button" class="role-pill <?= $u['role']==='accountant'?'sel-accountant':'' ?>"       onclick="setRole(<?= $u['id'] ?>,'accountant',this)">🧾 Accountant</button>
          <button type="button" class="role-pill <?= $u['role']==='vendor'?'sel-vendor':'' ?>"               onclick="setRole(<?= $u['id'] ?>,'vendor',this)">🏪 Vendor</button>
          <button type="button" class="role-pill <?= $u['role']==='society_member'?'sel-society_member':'' ?>" onclick="setRole(<?= $u['id'] ?>,'society_member',this)">🤝 Society Member</button>
        </form>
        <div style="display:flex;gap:8px;flex-shrink:0">
          <button type="button" class="btn-approve" onclick="document.getElementById('form-<?= $u['id'] ?>').submit()"><i class="fa fa-circle-check"></i> Approve Access</button>
          <form method="POST" onsubmit="return confirm('Reject and delete registration for <?= htmlspecialchars($u['name']) ?>?\n\nThis cannot be undone.')">
            <input type="hidden" name="reject_user" value="1">
            <input type="hidden" name="reject_id"   value="<?= $u['id'] ?>">
            <button type="submit" class="btn-reject"><i class="fa fa-circle-xmark"></i> Reject</button>
          </form>
        </div>
      </div>
    </div>
    <?php endforeach; endif; ?>
  </div>

  <!-- ══ PAYMENTS TAB ══ -->
  <div class="tab-section" id="tab-payments">

    <?php if($total_unverified > 0): ?>
    <div class="verify-banner">
      <i class="fa fa-hourglass-half"></i>
      <span class="verify-banner-text"><?= $total_unverified ?> payment screenshot<?= $total_unverified>1?'s':'' ?> waiting for your verification — click a "Verification Pending" row below to review the proof.</span>
    </div>
    <?php endif; ?>

    <!-- Payment Stats -->
    <div class="pay-stats">
      <div class="pay-stat">
        <div class="ps-icon ps-green"><i class="fa fa-arrow-trend-up"></i></div>
        <div class="ps-val">₹<?= number_format($total_collected,0) ?></div>
        <div class="ps-lbl">Collected</div>
      </div>
      <div class="pay-stat">
        <div class="ps-icon ps-yellow"><i class="fa fa-clock"></i></div>
        <div class="ps-val">₹<?= number_format($total_pending_amt,0) ?></div>
        <div class="ps-lbl">Pending</div>
      </div>
      <div class="pay-stat">
        <div class="ps-icon ps-blue"><i class="fa fa-hourglass-half"></i></div>
        <div class="ps-val"><?= $total_unverified ?></div>
        <div class="ps-lbl">Unverified</div>
      </div>
      <div class="pay-stat">
        <div class="ps-icon ps-red"><i class="fa fa-circle-xmark"></i></div>
        <div class="ps-val"><?= $total_failed ?></div>
        <div class="ps-lbl">Failed</div>
      </div>
      <div class="pay-stat">
        <div class="ps-icon ps-blue"><i class="fa fa-list"></i></div>
        <div class="ps-val"><?= $total_bills ?> / <?= $total_bills ?></div>
        <div class="ps-lbl">Showing</div>
      </div>
    </div>

    <!-- Filters -->
    <div class="pay-filters">
      <div class="pay-filters-head"><i class="fa fa-filter"></i> Filters <span style="margin-left:auto;font-size:.72rem;color:var(--text-muted);font-weight:400" id="filterCount"></span></div>
      <div class="pay-filters-row">
        <input type="text" class="pay-search" id="paySearch" placeholder="Search by resident, flat, txn id, email…" oninput="filterPayments()">
        <select class="filter-select" id="filterStatus" onchange="filterPayments()">
          <option value="">All status</option>
          <option value="paid">Paid</option>
          <option value="pending">Pending</option>
          <option value="pending_verification">Verification Pending</option>
          <option value="overdue">Overdue</option>
          <option value="failed">Failed</option>
        </select>
        <select class="filter-select" id="filterCategory" onchange="filterPayments()">
          <option value="">All types</option>
          <option value="maintenance">Maintenance</option>
          <option value="parking">Parking Fee</option>
          <option value="water">Water Charges</option>
          <option value="electricity">Electricity</option>
          <option value="other">Other</option>
        </select>
        <select class="filter-select" id="filterMode" onchange="filterPayments()">
          <option value="">All modes</option>
          <option value="UPI">UPI</option>
          <option value="Net Banking">Net Banking</option>
          <option value="Cash">Cash</option>
          <option value="Card">Card</option>
          <option value="Admin Override">Admin Override</option>
        </select>
        <input type="date" class="date-input" id="filterFrom" onchange="filterPayments()" title="From date">
        <input type="date" class="date-input" id="filterTo" onchange="filterPayments()" title="To date">
        <button class="btn-clear-filter" onclick="clearFilters()"><i class="fa fa-xmark"></i> Clear</button>
      </div>
    </div>

    <!-- Payment Table -->
    <div class="pay-table-wrap">
      <div class="pay-table-head">
        <h3>Payment History <span style="font-size:.78rem;font-weight:400;color:var(--text-muted)">— tap a row to view full breakdown</span></h3>
        <button class="btn-export-csv" onclick="exportPayCSV()"><i class="fa fa-download"></i> Export CSV</button>
      </div>

      <?php if(empty($payments)): ?>
      <div class="no-pay">
        <i class="fa fa-receipt"></i>
        <p style="font-size:.95rem;font-weight:700;color:var(--text-primary);margin-bottom:6px">No payment records yet</p>
        <p style="font-size:.82rem">Bills generated from residents will appear here.</p>
      </div>
      <?php else: ?>
      <table class="pay-table" id="payTable">
        <thead>
          <tr>
            <th>Txn ID</th>
            <th>Resident</th>
            <th>Flat</th>
            <th>Category</th>
            <th>Mode</th>
            <th>Amount</th>
            <th>Date</th>
            <th>Status</th>
            <th>Actions</th>
          </tr>
        </thead>
        <tbody>
        <?php foreach($payments as $i => $p):
          $txnId  = $p['txn_id'] ?: 'TXN-'.str_pad($p['id'],5,'0',STR_PAD_LEFT);
          $statusClass = match($p['status']){'paid'=>'pb-paid','pending'=>'pb-pending','overdue'=>'pb-overdue','pending_verification'=>'pb-pending_verification',default=>'pb-failed'};
          $payDate = $p['paid_at'] ? date('Y-m-d', strtotime($p['paid_at'])) : ($p['created_at'] ? date('Y-m-d', strtotime($p['created_at'])) : '—');
          $cat = strtolower($p['description'] ?? '');
          $hasProof = !empty($p['payment_proof']);
        ?>
        <tr class="pay-row"
            data-name="<?= strtolower($p['resident_name'] ?? '') ?>"
            data-email="<?= strtolower($p['resident_email'] ?? '') ?>"
            data-txn="<?= strtolower($txnId) ?>"
            data-flat="<?= strtolower($p['unit'] ?? '') ?>"
            data-status="<?= $p['status'] ?>"
            data-mode="<?= htmlspecialchars($p['payment_mode'] ?? '') ?>"
            data-cat="<?= $cat ?>"
            data-date="<?= $payDate ?>"
            onclick="toggleDetail(<?= $p['id'] ?>)">
          <td><span class="txn-id"><?= htmlspecialchars($txnId) ?></span></td>
          <td>
            <div class="p-resident"><?= htmlspecialchars($p['resident_name'] ?? 'Unknown') ?></div>
            <div class="p-email"><?= htmlspecialchars($p['resident_email'] ?? '') ?></div>
          </td>
          <td><?= htmlspecialchars($p['unit'] ?? '—') ?></td>
          <td><?= htmlspecialchars($p['description'] ?? '—') ?></td>
          <td>
            <?php if($p['payment_mode']): ?>
              <span class="mode-chip"><?= htmlspecialchars($p['payment_mode']) ?></span>
            <?php else: ?>
              <span style="color:var(--text-muted);font-size:.78rem">—</span>
            <?php endif; ?>
          </td>
          <td><span class="pay-amount">₹<?= number_format($p['amount'],0) ?></span></td>
          <td style="font-size:.78rem;color:var(--text-muted)"><?= $payDate ?></td>
          <td><span class="pay-badge <?= $statusClass ?>"><?= $p['status']==='pending_verification' ? 'Verification Pending' : ucfirst($p['status']) ?></span></td>
          <td onclick="event.stopPropagation()">
            <div class="pay-actions-cell">
              <?php if($hasProof): ?>
              <button type="button" class="pa-btn pa-view" title="View Payment Screenshot"
                onclick="openProofModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['payment_proof'])) ?>', '<?= htmlspecialchars(addslashes($p['resident_name']??'Unknown')) ?>', '<?= htmlspecialchars(addslashes($p['description'])) ?>', <?= $p['amount'] ?>, '<?= htmlspecialchars(addslashes($p['txn_ref']??'')) ?>', '<?= $p['status'] ?>')">
                <i class="fa fa-image"></i>
              </button>
              <?php endif; ?>
              <?php if($p['status'] !== 'paid' && $p['status'] !== 'pending_verification'): ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Mark this bill as paid?')">
                <input type="hidden" name="mark_paid" value="1">
                <input type="hidden" name="bill_id" value="<?= $p['id'] ?>">
                <button type="submit" class="pa-btn pa-pay" title="Mark as Paid"><i class="fa fa-check"></i></button>
              </form>
              <?php endif; ?>
              <?php if($p['status'] === 'pending'): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="mark_overdue" value="1">
                <input type="hidden" name="bill_id" value="<?= $p['id'] ?>">
                <button type="submit" class="pa-btn pa-overdue" title="Mark Overdue"><i class="fa fa-clock"></i></button>
              </form>
              <?php endif; ?>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete this bill permanently?')">
                <input type="hidden" name="del_bill" value="1">
                <input type="hidden" name="bill_id" value="<?= $p['id'] ?>">
                <button type="submit" class="pa-btn pa-del" title="Delete"><i class="fa fa-trash"></i></button>
              </form>
            </div>
          </td>
        </tr>
        <!-- Detail expand row -->
        <tr class="pay-detail-row" id="detail-<?= $p['id'] ?>">
          <td colspan="9">
            <div class="pay-detail-inner">
              <div class="pd-item"><span class="pd-label">Transaction ID</span><span class="pd-val"><?= htmlspecialchars($txnId) ?></span></div>
              <div class="pd-item"><span class="pd-label">Resident</span><span class="pd-val"><?= htmlspecialchars($p['resident_name'] ?? '—') ?></span></div>
              <div class="pd-item"><span class="pd-label">Email</span><span class="pd-val"><?= htmlspecialchars($p['resident_email'] ?? '—') ?></span></div>
              <div class="pd-item"><span class="pd-label">Phone</span><span class="pd-val"><?= htmlspecialchars($p['resident_phone'] ?? '—') ?></span></div>
              <div class="pd-item"><span class="pd-label">Unit / Flat</span><span class="pd-val"><?= htmlspecialchars($p['unit'] ?? '—') ?></span></div>
              <div class="pd-item"><span class="pd-label">Description</span><span class="pd-val"><?= htmlspecialchars($p['description'] ?? '—') ?></span></div>
              <div class="pd-item"><span class="pd-label">Month</span><span class="pd-val"><?= htmlspecialchars($p['month'] ?? '—') ?></span></div>
              <div class="pd-item"><span class="pd-label">Amount</span><span class="pd-val">₹<?= number_format($p['amount'],2) ?></span></div>
              <div class="pd-item"><span class="pd-label">Due Date</span><span class="pd-val"><?= $p['due_date'] ? date('d M Y', strtotime($p['due_date'])) : '—' ?></span></div>
              <div class="pd-item"><span class="pd-label">Paid At</span><span class="pd-val"><?= $p['paid_at'] ? date('d M Y, h:i A', strtotime($p['paid_at'])) : '—' ?></span></div>
              <div class="pd-item"><span class="pd-label">Payment Mode</span><span class="pd-val"><?= htmlspecialchars($p['payment_mode'] ?? '—') ?></span></div>
              <div class="pd-item"><span class="pd-label">Status</span><span class="pd-val"><span class="pay-badge <?= $statusClass ?>"><?= $p['status']==='pending_verification' ? 'Verification Pending' : ucfirst($p['status']) ?></span></span></div>
              <div class="pd-item"><span class="pd-label">Bill Created</span><span class="pd-val"><?= $p['created_at'] ? date('d M Y, h:i A', strtotime($p['created_at'])) : '—' ?></span></div>
              <?php if($hasProof): ?>
              <div class="pd-item"><span class="pd-label">Payment Proof</span><span class="pd-val"><a href="javascript:void(0)" onclick="event.stopPropagation();openProofModal(<?= $p['id'] ?>, '<?= htmlspecialchars(addslashes($p['payment_proof'])) ?>', '<?= htmlspecialchars(addslashes($p['resident_name']??'Unknown')) ?>', '<?= htmlspecialchars(addslashes($p['description'])) ?>', <?= $p['amount'] ?>, '<?= htmlspecialchars(addslashes($p['txn_ref']??'')) ?>', '<?= $p['status'] ?>')" style="color:#1d4ed8;text-decoration:underline">View Screenshot</a></span></div>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
      <!-- No results row -->
      <div id="payNoResults" style="display:none;text-align:center;padding:36px 20px;color:var(--text-muted)">
        <i class="fa fa-magnifying-glass" style="font-size:1.6rem;display:block;margin-bottom:10px;color:var(--border)"></i>
        <p>No payments match your filters.</p>
      </div>
      <?php endif; ?>
    </div>

  </div><!-- /payments tab -->

  <!-- ══ MY BILLS TAB ══ -->
  <div class="tab-section" id="tab-mybills">
    <div class="card">
      <div class="card-head"><h3>My Bills &amp; Payments</h3><span class="badge-cnt"><?= count($my_bills) ?> bills</span></div>
      <?php if(empty($my_bills)): ?>
      <div style="text-align:center;padding:40px;color:var(--text-muted)"><i class="fa fa-wallet" style="font-size:2rem;display:block;margin-bottom:10px;color:var(--border)"></i><p>No bills yet</p></div>
      <?php else: foreach($my_bills as $b): ?>
      <div class="user-row" style="align-items:center">
        <div class="u-info">
          <div class="u-name"><?= htmlspecialchars($b['description']) ?></div>
          <div class="u-meta"><?= htmlspecialchars($b['month']??'') ?> &nbsp;·&nbsp; Due: <?= $b['due_date']?date('d M Y',strtotime($b['due_date'])):'-' ?></div>
        </div>
        <div style="text-align:right;flex-shrink:0">
          <div style="font-weight:700;margin-bottom:6px;<?= $b['status']==='paid'?'color:#16a34a':'color:#c2410c' ?>">₹<?= number_format($b['amount'],2) ?></div>
          <?php if($b['status']==='paid'): ?>
          <span style="font-size:.73rem;color:#16a34a;font-weight:600"><i class="fa fa-circle-check"></i> Paid</span>
          <?php elseif($b['status']==='pending_verification'): ?>
          <span style="font-size:.73rem;color:#1d4ed8;font-weight:600"><i class="fa fa-hourglass-half"></i> Verification Pending</span>
          <?php else: ?>
          <button type="button" class="btn-primary" style="padding:6px 14px;font-size:.78rem"
            onclick="openPayModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['description'])) ?>', <?= $b['amount'] ?>)">
            <i class="fa fa-credit-card"></i> Pay Now
          </button>
          <?php endif; ?>
        </div>
      </div>
      <?php endforeach; endif; ?>
    </div>
  </div>

  <div class="modal-overlay" id="payModal">
    <div class="modal" style="max-width:480px;">
      <h3><i class="fa fa-credit-card" style="color:var(--green)"></i> Confirm Payment</h3>
      <p>Upload proof after completing the transfer.</p>
      <div style="background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:10px 14px;margin-bottom:14px;display:flex;justify-content:space-between;">
        <span id="payBillDesc" style="font-weight:600"></span>
        <span id="payBillAmt" style="font-weight:700;color:#c2410c"></span>
      </div>
      <form method="POST" enctype="multipart/form-data" onsubmit="return document.getElementById('payScreenshot').files.length>0 || alert('Please attach a screenshot.')">
        <input type="hidden" name="pay_bill" value="1">
        <input type="hidden" name="bill_id" id="payBillId" value="">
        <div class="ff"><label>Payment Screenshot *</label><input type="file" id="payScreenshot" name="payment_screenshot" accept="image/*" required></div>
        <div class="ff"><label>Transaction / UTR Ref (optional)</label><input type="text" name="txn_ref"></div>
        <div class="modal-footer">
          <button type="button" class="btn-cancel" onclick="closeModal('payModal')">Cancel</button>
          <button type="submit" class="btn-primary"><i class="fa fa-paper-plane"></i> Submit Payment</button>
        </div>
      </form>
    </div>
  </div>

  <!-- ══ COMPLAINTS TAB ══ -->
  <div class="tab-section" id="tab-complaints">
    <?php if (empty($complaints_list)): ?>
    <div class="empty-state">
      <i class="fa fa-comments" style="font-size:2rem;color:var(--border);display:block;margin-bottom:10px;"></i>
      No complaints have been raised yet.
    </div>
    <?php else: ?>
    <div class="complaints-list">
      <?php foreach ($complaints_list as $c):
        $displayUnit = $c['unit'] ?: (($c['raiser_block']?$c['raiser_block'].'-':'').$c['raiser_unit']);
        $priority = $c['priority'] ?? 'medium';
      ?>
      <div class="complaint-card">
        <div class="cc-head">
          <div>
            <div class="cc-subject"><?= htmlspecialchars($c['subject']) ?></div>
            <div class="cc-meta">
              <?= htmlspecialchars($c['raiser_name'] ?? 'Unknown') ?>
              <?php if ($displayUnit): ?> · Unit <?= htmlspecialchars($displayUnit) ?><?php endif; ?>
              · <?= htmlspecialchars(ucfirst($c['raiser_role'] ?? '')) ?>
              · <?= date('d M Y, h:i A', strtotime($c['created_at'])) ?>
            </div>
          </div>
          <div style="display:flex;flex-direction:column;gap:6px;align-items:flex-end;">
            <span class="cc-status cc-<?= $c['status'] ?>"><?= ucwords(str_replace('_',' ',$c['status'])) ?></span>
            <span class="cc-priority cc-pri-<?= $priority ?>"><?= ucfirst($priority) ?> priority</span>
          </div>
        </div>
        <div class="cc-category"><i class="fa fa-tag"></i> <?= htmlspecialchars(ucfirst($c['category'])) ?></div>
        <div class="cc-desc"><?= nl2br(htmlspecialchars($c['description'])) ?></div>
        <?php if ($c['admin_response']): ?>
        <div class="cc-response"><strong>Admin response:</strong> <?= nl2br(htmlspecialchars($c['admin_response'])) ?></div>
        <?php endif; ?>
        <form method="POST" class="cc-form">
          <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
          <select name="complaint_status">
            <option value="open" <?= $c['status']==='open'?'selected':'' ?>>Open</option>
            <option value="in_progress" <?= $c['status']==='in_progress'?'selected':'' ?>>In Progress</option>
            <option value="resolved" <?= $c['status']==='resolved'?'selected':'' ?>>Resolved</option>
            <option value="closed" <?= $c['status']==='closed'?'selected':'' ?>>Closed</option>
          </select>
          <input type="text" name="admin_response" placeholder="Response to resident (optional)" value="<?= htmlspecialchars($c['admin_response'] ?? '') ?>">
          <button type="submit" name="respond_complaint" value="1"><i class="fa fa-check"></i> Update</button>
        </form>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══ FACILITIES TAB ══ -->
  <div class="tab-section" id="tab-facilities">
    <div class="card" style="margin-bottom:16px;">
      <div class="card-head">
        <h3>Manage Facilities</h3>
        <button class="btn-primary" onclick="openAddAmenityModal()"><i class="fa fa-plus"></i> Add Facility</button>
      </div>
    </div>

    <?php if (empty($amenities_list)): ?>
    <div class="empty-state">
      <i class="fa fa-building" style="font-size:2rem;color:var(--border);display:block;margin-bottom:10px;"></i>
      No facilities added yet.
    </div>
    <?php else: ?>
    <div class="amenity-grid">
      <?php foreach ($amenities_list as $a):
          $upcoming = $bookings_by_amenity[$a['id']] ?? [];
          $now = new DateTime();
          $activeBooking = null;
          foreach ($upcoming as $b) {
              $start = DateTime::createFromFormat('Y-m-d H:i:s', $b['booking_date'].' '.$b['start_time']);
              $end   = DateTime::createFromFormat('Y-m-d H:i:s', $b['booking_date'].' '.$b['end_time']);
              if ($now >= $start && $now <= $end) { $activeBooking = $b; break; }
          }
          if ($a['status'] === 'maintenance') { $badge = 'maintenance'; $badgeLabel = 'Maintenance'; }
          elseif ($activeBooking) { $badge = 'booked'; $badgeLabel = 'Booked'; }
          else { $badge = 'available'; $badgeLabel = 'Available'; }
      ?>
      <div class="amenity-card">
        <div class="am-head">
          <h4><?= htmlspecialchars($a['name']) ?></h4>
          <span class="am-badge am-<?= $badge ?>"><?= $badgeLabel ?></span>
        </div>
        <div class="am-row"><span>Rate</span><strong><?= $a['rate']>0 ? '₹'.number_format($a['rate'],0).'/'.htmlspecialchars($a['rate_unit']) : htmlspecialchars($a['rate_unit']) ?></strong></div>
        <?php if ($a['capacity']): ?><div class="am-row"><span>Capacity</span><strong><?= (int)$a['capacity'] ?> people</strong></div><?php endif; ?>
        <?php if ($a['features']): ?><div class="am-features"><?= htmlspecialchars($a['features']) ?></div><?php endif; ?>
        <div class="am-actions">
          <button class="am-btn-edit" onclick='openEditAmenity(<?= json_encode($a) ?>)'><i class="fa fa-pen"></i> Edit</button>
          <form method="POST" style="display:inline;">
            <input type="hidden" name="amenity_id" value="<?= $a['id'] ?>">
            <input type="hidden" name="new_amenity_status" value="<?= $a['status']==='available'?'maintenance':'available' ?>">
            <button type="submit" name="toggle_amenity_status" value="1" class="am-btn-toggle"><i class="fa fa-wrench"></i> <?= $a['status']==='available'?'Set Maintenance':'Set Available' ?></button>
          </form>
          <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this facility? This also removes its bookings.');">
            <input type="hidden" name="amenity_id" value="<?= $a['id'] ?>">
            <button type="submit" name="delete_amenity" value="1" class="am-btn-delete"><i class="fa fa-trash"></i></button>
          </form>
        </div>
        <?php if ($upcoming): ?>
        <button class="am-view-bookings" onclick='openBookingsModal(<?= json_encode($a['name']) ?>, <?= json_encode($upcoming) ?>)'>
          <i class="fa fa-calendar"></i> <?= count($upcoming) ?> upcoming booking<?= count($upcoming)>1?'s':'' ?>
        </button>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
    <?php endif; ?>
  </div>

  <!-- ══ REPORTS TAB ══ -->
  <div class="tab-section" id="tab-reports">
    <div class="reports-grid">
      <div class="report-card"><div class="ri"><i class="fa fa-users"></i></div><h4>User Overview</h4><p>Total: <?= $total_users ?> users, <?= $total_admins ?> admins, <?= $total_residents ?> residents.</p><div class="report-actions"><button class="btn-view" onclick="switchTab('users',null)">View</button><button class="btn-export" onclick="exportCSV()"><i class="fa fa-download"></i> Export</button></div></div>
      <div class="report-card"><div class="ri"><i class="fa fa-user-tag"></i></div><h4>Role Distribution</h4><p><?= $total_admins ?> Admin, <?= $total_residents ?> Resident, <?= $total_staff ?> Staff. <?= $pending_users ?> pending.</p><div class="report-actions"><button class="btn-view" onclick="switchTab('requests',null)">View</button><button class="btn-export" onclick="window.print()"><i class="fa fa-download"></i> Export</button></div></div>
      <div class="report-card"><div class="ri"><i class="fa fa-clipboard-list"></i></div><h4>Access Requests</h4><p><?= $pending_users ?> pending, <?= $total_users - $pending_users ?> approved out of <?= $total_users ?> total.</p><div class="report-actions"><button class="btn-view" onclick="switchTab('requests',null)">View</button><button class="btn-export" onclick="window.print()"><i class="fa fa-download"></i> Export</button></div></div>
      <div class="report-card"><div class="ri"><i class="fa fa-credit-card"></i></div><h4>Payment History</h4><p>Collected: ₹<?= number_format($total_collected,0) ?>. Pending: ₹<?= number_format($total_pending_amt,0) ?>.</p><div class="report-actions"><button class="btn-view" onclick="switchTab('payments',null)">View</button><button class="btn-export" onclick="exportPayCSV()"><i class="fa fa-download"></i> Export</button></div></div>
      <div class="report-card"><div class="ri"><i class="fa fa-comments"></i></div><h4>Complaint Analytics</h4><p>Total: <?= $total_complaints ?>. Open: <?= $open_complaints ?>.</p><div class="report-actions"><button class="btn-view" onclick="switchTab('complaints',null)">View</button><button class="btn-export" onclick="window.print()"><i class="fa fa-download"></i> Export</button></div></div>
      <div class="report-card"><div class="ri"><i class="fa fa-building"></i></div><h4>Society Overview</h4><p><?= $total_users ?> members, <?= $active_society ?> active society. ColonyCare Portal.</p><div class="report-actions"><button class="btn-view" onclick="alert('Members: <?= $total_users ?>\nBills: <?= $total_bills ?>')">View</button><button class="btn-export" onclick="window.print()"><i class="fa fa-download"></i> Export</button></div></div>
    </div>
  </div>

</div><!-- /content -->

<!-- MODALS -->
<div class="modal-overlay" id="noticeModal">
  <div class="modal">
    <h3>Send Notice</h3><p>Broadcast a notice to all residents.</p>
    <form method="POST">
      <input type="hidden" name="send_notice" value="1">
      <div class="ff"><label>Title *</label><input type="text" name="notice_title" placeholder="Notice title" required></div>
      <div class="ff"><label>Message</label><textarea name="notice_body" placeholder="Notice details..."></textarea></div>
      <div class="ff"><label>Priority</label><select name="notice_priority"><option value="low">Low</option><option value="medium" selected>Medium</option><option value="high">High</option></select></div>
      <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('noticeModal')">Cancel</button><button type="submit" class="btn-primary"><i class="fa fa-paper-plane"></i> Send Notice</button></div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="addResModal">
  <div class="modal">
    <h3>Add New Resident</h3><p>Register a new member directly.</p>
    <form method="POST">
      <input type="hidden" name="add_resident" value="1">
      <div class="form-row">
        <div class="ff"><label>Full Name *</label><input type="text" name="r_name" placeholder="Ramesh Kumar" required></div>
        <div class="ff"><label>Email *</label><input type="email" name="r_email" placeholder="ramesh@example.com" required></div>
      </div>
      <div class="form-row">
        <div class="ff"><label>Phone</label><input type="text" name="r_phone" placeholder="+91 98765 43210"></div>
        <div class="ff"><label>Unit</label><input type="text" name="r_unit" placeholder="201"></div>
      </div>
      <div class="form-row">
        <div class="ff"><label>Block</label><select name="r_block"><option value="">Select</option><?php foreach(['A','B','C','D','E'] as $bl): ?><option><?= $bl ?></option><?php endforeach; ?></select></div>
        <div class="ff"><label>Role</label><select name="r_role"><option value="resident">Resident</option><option value="admin">Admin</option><option value="staff">Staff</option></select></div>
      </div>
      <div class="ff"><label>Password (default: admin123)</label><input type="text" name="r_password" placeholder="Leave blank for default"></div>
      <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('addResModal')">Cancel</button><button type="submit" class="btn-primary"><i class="fa fa-user-plus"></i> Add Resident</button></div>
    </form>
  </div>
</div>

<div class="modal-overlay" id="billModal">
  <div class="modal">
    <h3>Generate Bill</h3><p>Create a billing invoice for a resident.</p>
    <form method="POST">
      <input type="hidden" name="gen_bill" value="1">
      <div class="ff"><label>Resident *</label>
        <select name="bill_uid" required onchange="fillBillUnit(this)">
          <option value="">Select Resident</option>
          <?php foreach($users_list as $u): ?><option value="<?= $u['id'] ?>" data-unit="<?= htmlspecialchars($u['unit']??'') ?>"><?= htmlspecialchars($u['name']) ?><?= $u['unit']?' ('.$u['unit'].')':'' ?></option><?php endforeach; ?>
        </select>
      </div>
      <div class="form-row">
        <div class="ff"><label>Unit *</label><input type="text" name="bill_unit" id="billUnit" placeholder="A-201" required></div>
        <div class="ff"><label>Amount (₹) *</label><input type="number" name="bill_amount" placeholder="2500" min="1" step="0.01" required></div>
      </div>
      <div class="ff"><label>Description</label><input type="text" name="bill_desc" value="Monthly Maintenance"></div>
      <div class="form-row">
        <div class="ff"><label>Month</label><select name="bill_month"><?php for($i=0;$i<12;$i++){$d=new DateTime("first day of -$i month");echo "<option value='{$d->format('F Y')}' ".($i===0?'selected':'').">{$d->format('F Y')}</option>";}?></select></div>
        <div class="ff"><label>Due Date *</label><input type="date" name="bill_due" value="<?= date('Y-m-d',strtotime('+15 days')) ?>" required></div>
      </div>
      <div class="modal-footer"><button type="button" class="btn-cancel" onclick="closeModal('billModal')">Cancel</button><button type="submit" class="btn-primary"><i class="fa fa-file-invoice"></i> Generate Bill</button></div>
    </form>
  </div>
</div>

<!-- ══ ADD/EDIT AMENITY MODAL ══ -->
<div class="modal-overlay" id="addAmenityModal">
  <div class="modal">
    <h3 id="amenityModalTitle">Add Facility</h3>
    <form method="POST" id="amenityForm">
      <input type="hidden" name="amenity_id" id="am_id" value="">
      <div class="ff"><label>Facility Name *</label><input type="text" name="a_name" id="am_name" placeholder="e.g. Clubhouse" required></div>
      <div class="form-row">
        <div class="ff"><label>Rate (₹)</label><input type="number" name="a_rate" id="am_rate" placeholder="0 for Free" min="0" step="0.01"></div>
        <div class="ff"><label>Rate Unit</label>
          <select name="a_rate_unit" id="am_rate_unit">
            <option value="Free">Free</option>
            <option value="hr">Per Hour</option>
            <option value="session">Per Session</option>
            <option value="entry">Per Entry</option>
            <option value="day">Per Day</option>
          </select>
        </div>
      </div>
      <div class="ff"><label>Capacity (people)</label><input type="number" name="a_capacity" id="am_capacity" placeholder="e.g. 50" min="1"></div>
      <div class="ff"><label>Features</label><input type="text" name="a_features" id="am_features" placeholder="e.g. AC, Sound System, Kitchen (comma separated)"></div>
      <div class="modal-footer">
        <button type="button" class="btn-cancel" onclick="closeModal('addAmenityModal')">Cancel</button>
        <button type="submit" name="add_amenity" value="1" id="amenitySubmitBtn" class="btn-primary"><i class="fa fa-plus"></i> Add Facility</button>
      </div>
    </form>
  </div>
</div>

<!-- ══ BOOKINGS VIEWER MODAL ══ -->
<div class="modal-overlay" id="bookingsModal">
  <div class="modal" style="max-width:480px;">
    <h3 id="bookingsModalTitle">Upcoming Bookings</h3>
    <div id="bookingsModalList" style="max-height:360px;overflow-y:auto;"></div>
    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal('bookingsModal')">Close</button>
    </div>
  </div>
</div>

<!-- ══ PAYMENT PROOF VIEWER MODAL ══ -->
<div class="modal-overlay" id="proofModal">
  <div class="modal" style="max-width:540px;">
    <h3><i class="fa fa-receipt" style="color:var(--green)"></i> Payment Proof</h3>
    <p>Review the screenshot uploaded by the resident, then verify or reject.</p>

    <img id="proofImg" class="proof-modal-img" src="" alt="Payment screenshot">

    <div style="background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:10px 14px;margin-bottom:16px;">
      <div class="proof-info-row"><span>Resident</span><span id="proofResident"></span></div>
      <div class="proof-info-row"><span>Bill</span><span id="proofDesc"></span></div>
      <div class="proof-info-row"><span>Amount</span><span id="proofAmount"></span></div>
      <div class="proof-info-row" id="proofTxnRow" style="display:none"><span>Txn / UTR Ref</span><span id="proofTxn"></span></div>
      <div class="proof-info-row"><span>Status</span><span id="proofStatus"></span></div>
    </div>

    <div id="proofActions" class="modal-footer" style="justify-content:flex-end;gap:8px;">
      <button type="button" class="btn-cancel" onclick="closeModal('proofModal')">Close</button>
      <form method="POST" id="rejectProofForm" style="display:inline">
        <input type="hidden" name="reject_payment" value="1">
        <input type="hidden" name="bill_id" id="rejectProofBillId" value="">
        <button type="submit" class="btn-reject" onclick="return confirm('Reject this payment proof? The resident will need to re-submit.')">
          <i class="fa fa-circle-xmark"></i> Reject
        </button>
      </form>
      <form method="POST" id="verifyProofForm" style="display:inline">
        <input type="hidden" name="verify_payment" value="1">
        <input type="hidden" name="bill_id" id="verifyProofBillId" value="">
        <button type="submit" class="btn-approve">
          <i class="fa fa-circle-check"></i> Verify &amp; Mark Paid
        </button>
      </form>
    </div>
  </div>
</div>

<script>
// Clock
function updateClock(){const n=new Date();let h=n.getHours(),m=n.getMinutes(),s=n.getSeconds();const a=h>=12?'pm':'am';h=h%12||12;document.getElementById('clock').textContent=String(h).padStart(2,'0')+':'+String(m).padStart(2,'0')+':'+String(s).padStart(2,'0')+' '+a;}
updateClock();setInterval(updateClock,1000);

// Tabs
function switchTab(id,btn){
  const target = document.getElementById('tab-'+id);
  if(!target) return; // no matching tab-section for this id — nothing to switch to
  document.querySelectorAll('.tab-section').forEach(s=>s.classList.remove('active'));
  document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
  target.classList.add('active');
  if(btn) btn.classList.add('active');
  else document.querySelector('[onclick*="\''+id+'\'"]')?.classList.add('active');
}

// Modals
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');}));

// User search
function filterUsers(){
  const q=document.getElementById('userSearch').value.toLowerCase();
  document.querySelectorAll('.user-row').forEach(r=>{r.style.display=(r.dataset.name?.includes(q)||r.dataset.email?.includes(q))?'':'none';});
}

// Bill unit autofill
function fillBillUnit(sel){const u=sel.options[sel.selectedIndex].dataset.unit;const f=document.getElementById('billUnit');if(f&&u)f.value=u;}

// Security alert
function triggerSecAlert(){document.getElementById('secAlert').classList.add('show');setTimeout(()=>document.getElementById('secAlert').classList.remove('show'),5000);}

// Role pill selector for approvals
function setRole(uid, role, btn) {
  document.getElementById('role-'+uid).value = role;
  const card = document.getElementById('pcard-'+uid);
  card.querySelectorAll('.role-pill').forEach(p => { p.className = 'role-pill'; });
  const classes = {resident:'sel-resident', admin:'sel-admin', staff:'sel-staff', accountant:'sel-accountant', vendor:'sel-vendor', society_member:'sel-society_member'};
  btn.classList.add(classes[role] || '');
}

// Payment row detail toggle
function toggleDetail(id){
  const row = document.getElementById('detail-'+id);
  if(!row) return;
  const isOpen = row.classList.contains('open');
  document.querySelectorAll('.pay-detail-row.open').forEach(r=>r.classList.remove('open'));
  if(!isOpen) row.classList.add('open');
}

function openPayModal(billId, desc, amount){
  document.getElementById('payBillId').value = billId;
  document.getElementById('payBillDesc').textContent = desc;
  document.getElementById('payBillAmt').textContent = '₹' + parseFloat(amount).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});
  openModal('payModal');
}

// ── Payment proof viewer ──
function openProofModal(billId, filename, residentName, desc, amount, txnRef, status){
  document.getElementById('proofImg').src = '/uploads/payment_proofs/' + filename;
  document.getElementById('proofResident').textContent = residentName;
  document.getElementById('proofDesc').textContent = desc;
  document.getElementById('proofAmount').textContent = '₹' + parseFloat(amount).toLocaleString('en-IN',{minimumFractionDigits:2,maximumFractionDigits:2});

  if(txnRef && txnRef.trim() !== ''){
    document.getElementById('proofTxnRow').style.display = 'flex';
    document.getElementById('proofTxn').textContent = txnRef;
  } else {
    document.getElementById('proofTxnRow').style.display = 'none';
  }

  const statusLabel = status === 'pending_verification' ? 'Verification Pending' : status.charAt(0).toUpperCase()+status.slice(1);
  document.getElementById('proofStatus').textContent = statusLabel;

  document.getElementById('verifyProofBillId').value = billId;
  document.getElementById('rejectProofBillId').value = billId;

  // Hide verify/reject actions if already paid
  const actionsWrap = document.getElementById('proofActions');
  const verifyForm  = document.getElementById('verifyProofForm');
  const rejectForm  = document.getElementById('rejectProofForm');
  if(status === 'paid'){
    verifyForm.style.display = 'none';
    rejectForm.style.display = 'none';
  } else {
    verifyForm.style.display = 'inline';
    rejectForm.style.display = 'inline';
  }

  openModal('proofModal');
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
    fetch('/notification_handler.php?action=fetch')
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
    fetch('/notification_handler.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:`action=mark_read&id=${id}`
    }).then(()=>{
        if (link && link !== '#') window.location.href = link;
        else fetchNotifications();
    });
}

function markAllRead() {
    fetch('/notification_handler.php', {
        method:'POST',
        headers:{'Content-Type':'application/x-www-form-urlencoded'},
        body:'action=mark_read&id=0'
    }).then(()=>fetchNotifications());
}

// Poll every 30 seconds + fetch on load
fetchNotifications();
setInterval(fetchNotifications, 30000);

// Payment filter
function filterPayments(){
  const q   = (document.getElementById('paySearch')?.value||'').toLowerCase();
  const st  = (document.getElementById('filterStatus')?.value||'').toLowerCase();
  const cat = (document.getElementById('filterCategory')?.value||'').toLowerCase();
  const mod = (document.getElementById('filterMode')?.value||'').toLowerCase();
  const fd  = document.getElementById('filterFrom')?.value||'';
  const td  = document.getElementById('filterTo')?.value||'';

  let visible=0;
  document.querySelectorAll('.pay-row').forEach(r=>{
    const nm  = r.dataset.name||'';
    const em  = r.dataset.email||'';
    const tx  = r.dataset.txn||'';
    const fl  = r.dataset.flat||'';
    const rs  = r.dataset.status||'';
    const rm  = (r.dataset.mode||'').toLowerCase();
    const rc  = r.dataset.cat||'';
    const dt  = r.dataset.date||'';

    let show = true;
    if(q && !nm.includes(q) && !em.includes(q) && !tx.includes(q) && !fl.includes(q)) show=false;
    if(st  && rs !== st) show=false;
    if(cat && !rc.includes(cat)) show=false;
    if(mod && rm !== mod.toLowerCase()) show=false;
    if(fd  && dt < fd) show=false;
    if(td  && dt > td) show=false;

    r.style.display = show ? '' : 'none';
    // also hide detail row
    const dr = document.getElementById('detail-'+(r.querySelector('[onclick]')?.dataset?.id||''));
    if(dr && !show) dr.classList.remove('open');
    if(show) visible++;
  });

  const nr = document.getElementById('payNoResults');
  if(nr) nr.style.display = visible===0 ? 'block' : 'none';

  // update showing stat
  const allRows = document.querySelectorAll('.pay-row').length;
  const showStat = document.querySelector('.pay-stat:last-child .ps-val');
  if(showStat) showStat.textContent = visible+' / '+allRows;

  // filter count hint
  const fc = document.getElementById('filterCount');
  if(fc){
    let active=0;
    if(q) active++;if(st) active++;if(cat) active++;if(mod) active++;if(fd||td) active++;
    fc.textContent = active ? active+' filter'+(active>1?'s':'')+' active' : '';
  }
}

function clearFilters(){
  ['paySearch','filterStatus','filterCategory','filterMode','filterFrom','filterTo'].forEach(id=>{
    const el=document.getElementById(id);
    if(el){el.tagName==='INPUT'?el.value='':el.selectedIndex=0;}
  });
  filterPayments();
}

// Export CSV for users
function exportCSV(){
  let d='Name,Email,Role,Status\n';
  document.querySelectorAll('.user-row').forEach(r=>{
    const n=r.querySelector('.u-name')?.textContent.trim()||'';
    const m=r.querySelector('.u-meta')?.textContent.trim()||'';
    const rl=r.querySelector('.role-badge')?.textContent.trim()||'';
    const st=r.querySelector('.u-status')?.textContent.trim()||'';
    d+=`"${n}","${m}","${rl}","${st}"\n`;
  });
  const b=new Blob([d],{type:'text/csv'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(b);a.download='users_export.csv';a.click();
}

// Export CSV for payments
function exportPayCSV(){
  let d='Txn ID,Resident,Email,Flat,Category,Mode,Amount,Date,Status\n';
  document.querySelectorAll('.pay-row').forEach(r=>{
    if(r.style.display==='none') return;
    const txn = r.querySelector('.txn-id')?.textContent.trim()||'';
    const res = r.querySelector('.p-resident')?.textContent.trim()||'';
    const em  = r.querySelector('.p-email')?.textContent.trim()||'';
    const tds = r.querySelectorAll('td');
    const flat= tds[2]?.textContent.trim()||'';
    const cat = tds[3]?.textContent.trim()||'';
    const mod = tds[4]?.textContent.trim()||'';
    const amt = tds[5]?.textContent.trim()||'';
    const dt  = tds[6]?.textContent.trim()||'';
    const st  = tds[7]?.textContent.trim()||'';
    d+=`"${txn}","${res}","${em}","${flat}","${cat}","${mod}","${amt}","${dt}","${st}"\n`;
  });
  const b=new Blob([d],{type:'text/csv'});
  const a=document.createElement('a');
  a.href=URL.createObjectURL(b);a.download='payments_export.csv';a.click();
}

<?php if($pending_users > 0): ?>
document.getElementById('reqTab').style.fontWeight = '700';
<?php endif; ?>

function openEditAmenity(a){
    document.getElementById('amenityModalTitle').textContent = 'Edit Facility';
    document.getElementById('am_id').value = a.id;
    document.getElementById('am_name').value = a.name;
    document.getElementById('am_rate').value = a.rate;
    document.getElementById('am_rate_unit').value = a.rate_unit;
    document.getElementById('am_capacity').value = a.capacity || '';
    document.getElementById('am_features').value = a.features || '';
    const btn = document.getElementById('amenitySubmitBtn');
    btn.name = 'edit_amenity';
    btn.innerHTML = '<i class="fa fa-check"></i> Save Changes';
    openModal('addAmenityModal');
}
function openAddAmenityModal(){
    document.getElementById('amenityModalTitle').textContent = 'Add Facility';
    document.getElementById('amenityForm').reset();
    document.getElementById('am_id').value = '';
    const btn = document.getElementById('amenitySubmitBtn');
    btn.name = 'add_amenity';
    btn.innerHTML = '<i class="fa fa-plus"></i> Add Facility';
    openModal('addAmenityModal');
}
function openBookingsModal(name, bookings){
    document.getElementById('bookingsModalTitle').textContent = 'Upcoming Bookings — ' + name;
    const list = document.getElementById('bookingsModalList');
    list.innerHTML = bookings.map(b => {
        const d = new Date(b.booking_date + 'T00:00:00');
        const dateStr = d.toLocaleDateString('en-IN', {day:'numeric', month:'short', year:'numeric'});
        return `<div style="display:flex;justify-content:space-between;align-items:center;padding:10px 0;border-bottom:1px solid var(--border);">
            <div>
                <div style="font-weight:600;font-size:.85rem;">${b.booker_name || 'Unknown'} ${b.booker_unit ? '('+b.booker_unit+')' : ''}</div>
                <div style="font-size:.78rem;color:var(--text-muted);">${dateStr}, ${b.start_time.slice(0,5)} - ${b.end_time.slice(0,5)}</div>
            </div>
            <form method="POST" onsubmit="return confirm('Cancel this booking?');">
                <input type="hidden" name="booking_id" value="${b.id}">
                <button type="submit" name="cancel_booking" value="1" style="background:#fee2e2;color:#dc2626;border:none;border-radius:7px;padding:6px 12px;font-size:.75rem;font-weight:600;cursor:pointer;">Cancel</button>
            </form>
        </div>`;
    }).join('') || '<p style="color:var(--text-muted);font-size:.85rem;">No upcoming bookings.</p>';
    openModal('bookingsModal');
}

function openAdminMobNav(){document.getElementById('adminMobNav').classList.add('open');document.body.style.overflow='hidden';}
function closeAdminMobNav(){document.getElementById('adminMobNav').classList.remove('open');document.body.style.overflow='';}
function closeAdminMobNavBg(e){if(e.target===document.getElementById('adminMobNav'))closeAdminMobNav();}

</script>
</body>
</html>
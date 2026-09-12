<?php
require_once __DIR__ . '/config.php';
session_start();
if (!isset($_SESSION['user_id'])) { header('Location: /login.php'); exit; }
if ($_SESSION['user_role'] !== 'admin' || empty($_SESSION['user_society_id'])) {
    header('Location: /login.php'); exit;
}

$user_id   = $_SESSION['user_id'];
$user_name = $_SESSION['user_name'] ?? 'Owner';

try {
    $pdo = get_db_connection();
} catch(Exception $e) { die("DB Error: ".$e->getMessage()); }

require_once __DIR__ . '/notify_helper.php';

// ── Confirm this user is the actual owner, not just an admin in this society ──
$ownChk = $pdo->prepare("SELECT * FROM societies WHERE id = ? AND owner_id = ? LIMIT 1");
$ownChk->execute([$_SESSION['user_society_id'], $user_id]);
$society = $ownChk->fetch();
if (!$society) { header('Location: /admin_dashboard.php'); exit; }

$society_id   = $society['id'];
$society_name = $society['society_name'];


// ── Handle POST actions ────────────────────────────────────
$msg = $err = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $society_id) {

    // Add Flat
    if (isset($_POST['add_flat'])) {
        $flat_no  = trim($_POST['flat_no']  ?? '');
        $tower    = trim($_POST['tower']    ?? '');
        $floor    = intval($_POST['floor']  ?? 1);
        $bhk      = trim($_POST['bhk']      ?? '2BHK');
        $area     = intval($_POST['area']   ?? 0);
        $maint    = floatval($_POST['maintenance'] ?? 0);
        $status   = trim($_POST['status']   ?? 'available');
        if ($flat_no) {
            try {
                $pdo->prepare("INSERT INTO flats (society_id,flat_no,tower,floor,bhk,area_sqft,maintenance,status) VALUES (?,?,?,?,?,?,?,?)")
                    ->execute([$society_id,$flat_no,$tower,$floor,$bhk,$area,$maint,$status]);
                $msg = "Flat {$flat_no} added!";
            } catch(Exception $e) { $err = "Flat already exists."; }
        }
    }

    // ── Update Public Contact Info ──────────────────────────
    if (isset($_POST['update_contact'])) {
        $cp = trim($_POST['contact_phone'] ?? '');
        $ce = trim($_POST['contact_email'] ?? '');
        $addr = trim($_POST['address'] ?? '');
        $city = trim($_POST['city'] ?? '');
        $state = trim($_POST['state'] ?? '');
        $pin = trim($_POST['pincode'] ?? '');
        if ($addr && $city && $state && preg_match('/^[0-9]{6}$/', $pin)) {
            $pdo->prepare("UPDATE societies SET contact_phone=?, contact_email=?, address=?, city=?, state=?, pincode=? WHERE id=? AND owner_id=?")
                ->execute([$cp, $ce, $addr, $city, $state, $pin, $society_id, $user_id]);
            $msg = 'Society details updated.';
        } else {
            $err = 'Address, city, state, and a valid 6-digit pincode are required.';
        }
    }

    // Delete Flat
    if (isset($_POST['del_flat'])) {
        $pdo->prepare("DELETE FROM flats WHERE id=? AND society_id=?")->execute([(int)$_POST['flat_id'],$society_id]);
        $msg = "Flat removed.";
    }

    // Update flat status
    if (isset($_POST['update_flat_status'])) {
        $pdo->prepare("UPDATE flats SET status=? WHERE id=? AND society_id=?")
            ->execute([trim($_POST['flat_status']), (int)$_POST['flat_id'], $society_id]);
        $msg = "Flat status updated.";
    }

    // Add Vendor
    if (isset($_POST['add_vendor'])) {
        $vname = trim($_POST['v_name'] ?? '');
        $vcat  = trim($_POST['v_cat']  ?? '');
        $vph   = trim($_POST['v_phone']?? '');
        if ($vname) {
            $pdo->prepare("INSERT INTO vendors (society_id,name,category,phone) VALUES (?,?,?,?)")
                ->execute([$society_id,$vname,$vcat,$vph]);
            $msg = "Vendor added!";
        }
    }

    // ── Add Resident ───────────────────────────────────────
    if (isset($_POST['add_resident'])) {
        $r_name  = trim($_POST['r_name']  ?? '');
        $r_email = trim($_POST['r_email'] ?? '');
        $r_phone = trim($_POST['r_phone'] ?? '');
        $r_unit  = trim($_POST['r_unit']  ?? '');
        $r_block = trim($_POST['r_block'] ?? '');
        $r_role  = trim($_POST['r_role']  ?? 'resident');
        $r_pass  = trim($_POST['r_password'] ?? '');
        $r_pass  = $r_pass ? $r_pass : 'colony@123'; // default password

        // Validate
        if (empty($r_name) || empty($r_email)) {
            $err = "Name and email are required.";
        } elseif (!filter_var($r_email, FILTER_VALIDATE_EMAIL)) {
            $err = "Invalid email address.";
        } else {
            // Check duplicate email
            $chk = $pdo->prepare("SELECT id FROM users WHERE email = ? LIMIT 1");
            $chk->execute([$r_email]);
            if ($chk->fetch()) {
                $err = "A user with this email already exists.";
            } else {
                $hashed = password_hash($r_pass, PASSWORD_BCRYPT);
                try {
                    $pdo->prepare("
                        INSERT INTO users
                            (name, email, phone, password, role, society, society_id, unit, block, is_active, created_at)
                        VALUES
                            (?, ?, ?, ?, ?, ?, ?, ?, ?, 1, NOW())
                    ")->execute([
                        $r_name, $r_email, $r_phone, $hashed,
                        $r_role, $society_name, $society_id, $r_unit, $r_block
                    ]);
                    $msg = "✅ Resident '{$r_name}' added successfully! They can login with email: {$r_email} and password: {$r_pass}";
                } catch(Exception $e) {
                    $err = "Failed to add resident: " . $e->getMessage();
                }
            }
        }
    }

    
    // ── Delete Resident ────────────────────────────────────
    if (isset($_POST['del_resident'])) {
        $del_id = (int)$_POST['resident_id'];
        if ($del_id !== $user_id) {
            $pdo->prepare("DELETE FROM users WHERE id=? AND society_id=?")
                ->execute([$del_id, $society_id]);
            $msg = "Resident removed.";
        }
    }

    // ── Add Event ────────────────────────────────────────────
    if (isset($_POST['add_event'])) {
        $ev_title = trim($_POST['event_title'] ?? '');
        $ev_date  = trim($_POST['event_date']  ?? '');
        if ($ev_title && $ev_date) {
            $pdo->prepare("INSERT INTO society_events (society_id,title,event_date) VALUES (?,?,?)")
                ->execute([$society_id, $ev_title, $ev_date]);
            $msg = "Event '{$ev_title}' added!";
        } else { $err = "Event title and date are required."; }
    }

    // Delete Event
    if (isset($_POST['del_event'])) {
        $pdo->prepare("DELETE FROM society_events WHERE id=? AND society_id=?")
            ->execute([(int)$_POST['event_id'], $society_id]);
        $msg = "Event removed.";
    }

    // ── Add Committee Member ─────────────────────────────────
    if (isset($_POST['add_committee'])) {
        $c_name  = trim($_POST['c_name']  ?? '');
        $c_pos   = trim($_POST['c_pos']   ?? '');
        $c_phone = trim($_POST['c_phone'] ?? '');
        if ($c_name) {
            $pdo->prepare("INSERT INTO society_committee (society_id,name,position,phone) VALUES (?,?,?,?)")
                ->execute([$society_id, $c_name, $c_pos, $c_phone]);
            $msg = "Committee member '{$c_name}' added!";
        } else { $err = "Name is required."; }
    }

    // Delete Committee Member
    if (isset($_POST['del_committee'])) {
        $pdo->prepare("DELETE FROM society_committee WHERE id=? AND society_id=?")
            ->execute([(int)$_POST['committee_id'], $society_id]);
        $msg = "Committee member removed.";
    }

    // ── Add Gallery Photo (by URL) ───────────────────────────
    if (isset($_POST['add_gallery'])) {
        $g_url  = trim($_POST['g_url']     ?? '');
        $g_cap  = trim($_POST['g_caption'] ?? '');
        if ($g_url) {
            $pdo->prepare("INSERT INTO society_gallery (society_id,image_url,caption) VALUES (?,?,?)")
                ->execute([$society_id, $g_url, $g_cap]);
            $msg = "Photo added to gallery!";
        } else { $err = "Image URL is required."; }
    }

    // Delete Gallery Photo
    if (isset($_POST['del_gallery'])) {
        $pdo->prepare("DELETE FROM society_gallery WHERE id=? AND society_id=?")
            ->execute([(int)$_POST['gallery_id'], $society_id]);
        $msg = "Photo removed.";
    }

    // ── Add Review ────────────────────────────────────────────
    if (isset($_POST['add_review'])) {
        $rv_name    = trim($_POST['rv_name']    ?? '');
        $rv_rating  = floatval($_POST['rv_rating'] ?? 5);
        $rv_comment = trim($_POST['rv_comment'] ?? '');
        if ($rv_name && $rv_comment) {
            $pdo->prepare("INSERT INTO society_reviews (society_id,reviewer_name,rating,comment) VALUES (?,?,?,?)")
                ->execute([$society_id, $rv_name, $rv_rating, $rv_comment]);
            $msg = "Review added!";
        } else { $err = "Reviewer name and comment are required."; }
    }

    // Delete Review
    if (isset($_POST['del_review'])) {
        $pdo->prepare("DELETE FROM society_reviews WHERE id=? AND society_id=?")
            ->execute([(int)$_POST['review_id'], $society_id]);
        $msg = "Review removed.";
    }

    // ── Add Amenity ───────────────────────────────────────────
    if (isset($_POST['add_amenity'])) {
        $am_name = trim($_POST['amenity_name'] ?? '');
        if ($am_name) {
            $pdo->prepare("INSERT INTO society_amenities (society_id,name) VALUES (?,?)")
                ->execute([$society_id, $am_name]);
            $msg = "Amenity '{$am_name}' added!";
        }
    }

    // Delete Amenity
    if (isset($_POST['del_amenity'])) {
        $pdo->prepare("DELETE FROM society_amenities WHERE id=? AND society_id=?")
            ->execute([(int)$_POST['amenity_id'], $society_id]);
        $msg = "Amenity removed.";
    }

    // ── Add Nearby Place ──────────────────────────────────────
    if (isset($_POST['add_nearby'])) {
        $n_name = trim($_POST['n_name'] ?? '');
        $n_cat  = trim($_POST['n_cat']  ?? '');
        $n_dist = floatval($_POST['n_dist'] ?? 0);
        if ($n_name) {
            $pdo->prepare("INSERT INTO society_nearby (society_id,name,category,distance_km) VALUES (?,?,?,?)")
                ->execute([$society_id, $n_name, $n_cat, $n_dist]);
            $msg = "Nearby place '{$n_name}' added!";
        }
    }

    // Delete Nearby Place
    if (isset($_POST['del_nearby'])) {
        $pdo->prepare("DELETE FROM society_nearby WHERE id=? AND society_id=?")
            ->execute([(int)$_POST['nearby_id'], $society_id]);
        $msg = "Nearby place removed.";
    }

    // ── Mark Bill as Paid (admin/owner override) ────────────
    if (isset($_POST['mark_paid'])) {
        $bid = (int)$_POST['bill_id'];
        $pdo->prepare("
            UPDATE billing
            SET status='paid', paid_at=NOW(), payment_mode='Owner Override',
                txn_id = COALESCE(txn_id, CONCAT('OWN-',LPAD(?,6,'0')))
            WHERE id=? AND society_id=?
        ")->execute([$bid, $bid, $society_id]);
        $msg = "Bill marked as Paid.";
    }

    // ── Mark Bill as Overdue ──────────────────────────────────
    if (isset($_POST['mark_overdue'])) {
        $bid = (int)$_POST['bill_id'];
        $pdo->prepare("UPDATE billing SET status='overdue', paid_at=NULL WHERE id=? AND society_id=?")
            ->execute([$bid, $society_id]);
        $msg = "Bill marked as Overdue.";
    }

    // ── Mark Bill as Pending ──────────────────────────────────
    if (isset($_POST['mark_pending'])) {
        $bid = (int)$_POST['bill_id'];
        $pdo->prepare("UPDATE billing SET status='pending', paid_at=NULL WHERE id=? AND society_id=?")
            ->execute([$bid, $society_id]);
        $msg = "Bill marked as Pending.";
    }

    // ── Generate Bill (single) ──────────────────────────────────
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
            } else { $err = 'User not found in your society.'; }
        } else { $err = 'Resident, amount, and due date are required.'; }
    }

    // ── Generate Bills for All (bulk) ───────────────────────────
    if (isset($_POST['gen_bulk_bills'])) {
        $desc = trim($_POST['bulk_desc'] ?? 'Monthly Maintenance');
        $amt  = (float)$_POST['bulk_amount'];
        $mon  = trim($_POST['bulk_month'] ?? date('F Y'));
        $due  = trim($_POST['bulk_due'] ?? '');
        if ($amt && $due) {
            $allUsers = $pdo->prepare("SELECT id, unit, block FROM users WHERE society_id=? AND is_active=1");
            $allUsers->execute([$society_id]);
            $count = 0;
            foreach ($allUsers->fetchAll() as $u) {
                $chkDup = $pdo->prepare("SELECT id FROM billing WHERE user_id=? AND society_id=? AND month=? AND description=?");
                $chkDup->execute([$u['id'], $society_id, $mon, $desc]);
                if ($chkDup->fetch()) continue;
                $unit = trim(($u['block']??'').($u['unit']?'-'.$u['unit']:''));
                $pdo->prepare("INSERT INTO billing (user_id,society_id,unit,description,amount,month,due_date) VALUES (?,?,?,?,?,?,?)")
                    ->execute([$u['id'], $society_id, $unit, $desc, $amt, $mon, $due]);
                $count++;
            }
            $msg = "Generated {$count} bill(s) for {$mon}.";
        } else { $err = 'Amount and due date are required.'; }
    }

    // ── Verify payment proof (resident uploaded screenshot) ────
    if (isset($_POST['verify_payment'])) {
        $bid = (int)$_POST['bill_id'];
        $billOwner = $pdo->prepare("SELECT user_id, amount FROM billing WHERE id=? AND society_id=?");
        $billOwner->execute([$bid, $society_id]);
        $billRow = $billOwner->fetch();

        $pdo->prepare("
            UPDATE billing
            SET status='paid', paid_at=NOW(), payment_mode='UPI/Bank (Verified)',
                txn_id = COALESCE(txn_ref, CONCAT('VER-',LPAD(?,6,'0')))
            WHERE id=? AND society_id=?
        ")->execute([$bid, $bid, $society_id]);

        if ($billRow) {
            notify($pdo, $society_id, $billRow['user_id'], $user_id, 'verification',
                'Your payment of ₹' . number_format($billRow['amount'], 0) . ' has been verified and confirmed.',
                '/resident.php#tab-payments'
            );
        }
        $msg = 'Payment verified and marked as Paid!';
    }

    // ── Reject payment proof ────────────────────────────────────
    if (isset($_POST['reject_payment'])) {
        $bid = (int)$_POST['bill_id'];
        $billOwner2 = $pdo->prepare("SELECT user_id, amount FROM billing WHERE id=? AND society_id=?");
        $billOwner2->execute([$bid, $society_id]);
        $billRow2 = $billOwner2->fetch();

        $pdo->prepare("UPDATE billing SET status='pending', payment_proof=NULL, txn_ref=NULL WHERE id=? AND society_id=?")
            ->execute([$bid, $society_id]);

        if ($billRow2) {
            notify($pdo, $society_id, $billRow2['user_id'], $user_id, 'rejection',
                'Your payment proof was rejected. Please re-upload a valid screenshot.',
                '/resident.php#tab-payments'
            );
        }
        $msg = 'Payment proof rejected. Resident will need to re-submit.';
    }
    
    // ── Delete Bill ────────────────────────────────────────────
    if (isset($_POST['del_bill'])) {
        $bid = (int)$_POST['bill_id'];
        $pdo->prepare("DELETE FROM billing WHERE id=? AND society_id=?")->execute([$bid, $society_id]);
        $msg = "Bill deleted.";
    }

    // Complaints for this society
    $complaints_list = [];
    try {
        $stmtComp = $pdo->prepare("
            SELECT c.*, u.name as resident_name
            FROM complaints c LEFT JOIN users u ON c.user_id = u.id
            WHERE c.society_id = ? ORDER BY c.created_at DESC
        ");
        $stmtComp->execute([$society_id]);
        $complaints_list = $stmtComp->fetchAll();
    } catch(Exception $e){}
    $open_complaints_count = count(array_filter($complaints_list, fn($c)=>$c['status']==='open'));

    // ── Update complaint status ─────────────────────────────────
    if (isset($_POST['update_complaint_status'])) {
        $cid = (int)$_POST['complaint_id'];
        $newStatus = trim($_POST['complaint_status'] ?? 'open');
        if (in_array($newStatus, ['open','in_progress','resolved','closed'])) {
            $pdo->prepare("UPDATE complaints SET status=? WHERE id=? AND society_id=?")
                ->execute([$newStatus, $cid, $society_id]);
            $msg = 'Complaint status updated.';
        }
    }   

    // ── Approve Pending Registration (Owner) ───────────────────
    if (isset($_POST['approve_user'])) {
        $approve_id = (int)$_POST['approve_id'];

        // Confirm this user belongs to this society and is still pending
        $chkU = $pdo->prepare("SELECT * FROM users WHERE id=? AND society_id=? AND is_active=0 LIMIT 1");
        $chkU->execute([$approve_id, $society_id]);
        $targetUser = $chkU->fetch();

        if ($targetUser) {
            // Rule: Admin registrations can ONLY be approved by the Owner.
            // (society.php IS the owner's page, so owner can approve any role,
            //  including admin — that's enforced here.)
            $pdo->prepare("
                UPDATE users
                SET is_active = 1,
                    approved_by   = ?,
                    approved_role = 'owner',
                    approved_at   = NOW()
                WHERE id = ? AND society_id = ?
            ")->execute([$user_name, $approve_id, $society_id]);

            notify($pdo, $society_id, $approve_id, $user_id, 'approval',
                'Your registration has been approved! Welcome to ' . $society_name . '.',
                '/resident.php'
            );

            $msg = "✅ Access granted to " . htmlspecialchars($targetUser['name']) . " (" . htmlspecialchars($targetUser['role']) . "). They can now login.";
        } else {
            $err = "This request has already been handled or does not belong to your society.";
        }
    }

    // ── Reject Pending Registration (Owner) ─────────────────────
    if (isset($_POST['reject_user'])) {
        $reject_id = (int)$_POST['reject_id'];

        $chkR = $pdo->prepare("SELECT name FROM users WHERE id=? AND society_id=? AND is_active=0 LIMIT 1");
        $chkR->execute([$reject_id, $society_id]);
        $rejectedUser = $chkR->fetch();

        if ($rejectedUser) {
            notify($pdo, $society_id, $reject_id, $user_id, 'rejection',
                'Your registration request was not approved. Please contact the society.',
                null
            );
            $pdo->prepare("DELETE FROM users WHERE id=? AND society_id=? AND is_active=0")
                ->execute([$reject_id, $society_id]);
            $msg = "❌ Registration rejected for " . htmlspecialchars($rejectedUser['name']) . ".";
        } else {
            $err = "This request has already been handled.";
        }
    }

    // ── Approve unit link request ───────────────────────────────
    if (isset($_POST['approve_unit'])) {
        $uuid = (int)$_POST['unit_request_id'];
        $pdo->prepare("UPDATE user_units SET is_active=1, approved_at=NOW() WHERE id=? AND society_id=?")
            ->execute([$uuid, $society_id]);
        // Fetch requester to notify
        $req = $pdo->prepare("SELECT user_id, unit FROM user_units WHERE id=?");
        $req->execute([$uuid]); $req = $req->fetch();
        if ($req) {
            notify($pdo, $society_id, $req['user_id'], $user_id, 'approval',
                'Your request to link Flat ' . $req['unit'] . ' in ' . $society_name . ' has been approved!',
                '/resident.php'
            );
        }
        $msg = 'Unit link approved.';
    }

    // ── Reject unit link request ────────────────────────────────
    if (isset($_POST['reject_unit'])) {
        $uuid = (int)$_POST['unit_request_id'];
        $req  = $pdo->prepare("SELECT user_id, unit FROM user_units WHERE id=?");
        $req->execute([$uuid]); $req = $req->fetch();
        if ($req) {
            notify($pdo, $society_id, $req['user_id'], $user_id, 'rejection',
                'Your request to link Flat ' . $req['unit'] . ' in ' . $society_name . ' was not approved.',
                '/resident.php'
            );
        }
        $pdo->prepare("DELETE FROM user_units WHERE id=? AND society_id=?")->execute([$uuid, $society_id]);
        $msg = 'Unit link request rejected.';
    }

    header('Location: /society.php'); exit;
}

// ── Stats ──────────────────────────────────────────────────
$total_flats    = $society_id ? $pdo->query("SELECT COUNT(*) FROM flats WHERE society_id=$society_id")->fetchColumn() : 0;
$occupied       = $society_id ? $pdo->query("SELECT COUNT(*) FROM flats WHERE society_id=$society_id AND status='occupied'")->fetchColumn() : 0;
$for_sale       = $society_id ? $pdo->query("SELECT COUNT(*) FROM flats WHERE society_id=$society_id AND status='for_sale'")->fetchColumn() : 0;
$for_rent       = $society_id ? $pdo->query("SELECT COUNT(*) FROM flats WHERE society_id=$society_id AND status='for_rent'")->fetchColumn() : 0;
$resale         = $society_id ? $pdo->query("SELECT COUNT(*) FROM flats WHERE society_id=$society_id AND status='resale'")->fetchColumn() : 0;
$available      = $society_id ? $pdo->query("SELECT COUNT(*) FROM flats WHERE society_id=$society_id AND status='available'")->fetchColumn() : 0;
$active_vendors = $society_id ? $pdo->query("SELECT COUNT(*) FROM vendors WHERE society_id=$society_id AND status='active'")->fetchColumn() : 0;

// Billing stats (scoped to this society only)
$collected   = 0; $pending_dues = 0;
try {
    $collected    = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status='paid' AND society_id=?");
    $collected->execute([$society_id]); $collected = $collected->fetchColumn();

    $pending_dues = $pdo->prepare("SELECT COALESCE(SUM(amount),0) FROM billing WHERE status IN ('pending','overdue') AND society_id=?");
    $pending_dues->execute([$society_id]); $pending_dues = $pending_dues->fetchColumn();
} catch(Exception $e){}

// Residents (users in this society)
$residents = [];
try {
    $residents = $pdo->prepare("SELECT * FROM users WHERE society_id=? ORDER BY created_at DESC");
    $residents->execute([$society_id]);
    $residents = $residents->fetchAll();
} catch(Exception $e){ $residents = []; }
$families = count($residents);

// Staff (scoped to this society only)
$staff_count = 0;
try {
    $staff_count = $pdo->prepare("SELECT COUNT(*) FROM users WHERE role='staff' AND society_id=?");
    $staff_count->execute([$society_id]); $staff_count = $staff_count->fetchColumn();
} catch(Exception $e){}

// Flats list
$flats = $society_id ? $pdo->query("SELECT * FROM flats WHERE society_id=$society_id ORDER BY tower,flat_no")->fetchAll() : [];

// Vendors list
$vendors = $society_id ? $pdo->query("SELECT * FROM vendors WHERE society_id=$society_id ORDER BY name")->fetchAll() : [];

// Events list
$events = $society_id ? $pdo->query("SELECT * FROM society_events WHERE society_id=$society_id ORDER BY event_date DESC")->fetchAll() : [];

// Committee list
$committee = $society_id ? $pdo->query("SELECT * FROM society_committee WHERE society_id=$society_id ORDER BY id")->fetchAll() : [];

// Gallery list
$gallery = $society_id ? $pdo->query("SELECT * FROM society_gallery WHERE society_id=$society_id ORDER BY id DESC")->fetchAll() : [];

// Reviews list
$reviews = $society_id ? $pdo->query("SELECT * FROM society_reviews WHERE society_id=$society_id ORDER BY created_at DESC")->fetchAll() : [];
$avg_rating = $reviews ? round(array_sum(array_column($reviews,'rating'))/count($reviews),1) : 0;

// Amenities list
$amenities_list = $society_id ? $pdo->query("SELECT * FROM society_amenities WHERE society_id=$society_id ORDER BY id")->fetchAll() : [];

// Nearby places list
$nearby_list = $society_id ? $pdo->query("SELECT * FROM society_nearby WHERE society_id=$society_id ORDER BY distance_km")->fetchAll() : [];

// Pending registrations for this society (Approvals tab)
$pending_list = [];
try {
    $stmtPending = $pdo->prepare("SELECT * FROM users WHERE society_id = ? AND is_active = 0 ORDER BY created_at DESC");
    $stmtPending->execute([$society_id]);
    $pending_list = $stmtPending->fetchAll();
} catch(Exception $e){}
$pending_count = count($pending_list);

$pending_unit_requests = $pdo->prepare("
    SELECT uu.*, u.name as resident_name, u.email as resident_email
    FROM user_units uu
    JOIN users u ON u.id = uu.user_id
    WHERE uu.society_id=? AND uu.is_active=0
    ORDER BY uu.requested_at ASC
");
$pending_unit_requests->execute([$society_id]);
$pending_unit_requests = $pending_unit_requests->fetchAll();

// Maintenance (billing) — scoped to this society only
$bills = [];
try {
    $stmtBills = $pdo->prepare("
        SELECT b.*, u.name as resident_name, u.unit
        FROM billing b
        LEFT JOIN users u ON b.user_id = u.id
        WHERE b.society_id = ?
        ORDER BY b.created_at DESC
        LIMIT 50
    ");
    $stmtBills->execute([$society_id]);
    $bills = $stmtBills->fetchAll();
} catch(Exception $e){}

// Complaints for this society
$complaints_list = [];
try {
    $stmtComp = $pdo->prepare("
        SELECT c.*, u.name as resident_name
        FROM complaints c LEFT JOIN users u ON c.user_id = u.id
        WHERE c.society_id = ? ORDER BY c.created_at DESC
    ");
    $stmtComp->execute([$society_id]);
    $complaints_list = $stmtComp->fetchAll();
} catch(Exception $e){}
$open_complaints_count = count(array_filter($complaints_list, fn($c)=>$c['status']==='open'));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?= htmlspecialchars($society_name) ?> - Owner Dashboard</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
    --green:#1a7a5e;--green-dark:#0f5c46;--green-light:#e8f5ee;--green-soft:#f0f9f4;
    --text:#1a2e22;--sub:#5a7060;--muted:#8fa898;--border:#e5ece8;--bg:#f5f7f6;--white:#fff;
    --radius:12px;--shadow:0 1px 6px rgba(0,0,0,.07);
}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text);min-height:100vh;}

/* ── TOPBAR ── */
.topbar{height:52px;background:#fff;border-bottom:1px solid var(--border);display:flex;align-items:center;padding:0 24px;gap:12px;position:sticky;top:0;z-index:100;}
.tb-home{display:flex;align-items:center;gap:6px;color:var(--sub);text-decoration:none;font-size:.84rem;font-weight:500;}
.tb-home:hover{color:var(--green);}
.tb-divider{color:var(--border);font-size:.8rem;}
.tb-brand{display:flex;align-items:center;gap:8px;}
.tb-brand-icon{width:32px;height:32px;background:var(--green);border-radius:8px;display:flex;align-items:center;justify-content:center;color:#fff;font-size:.85rem;}
.tb-brand-name{font-weight:700;font-size:.95rem;color:var(--text);}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:10px;}
.tb-public{display:inline-flex;align-items:center;gap:6px;padding:7px 14px;border:1.5px solid var(--border);border-radius:8px;font-size:.8rem;font-weight:600;color:var(--text);text-decoration:none;transition:all .2s;}
.tb-public:hover{border-color:var(--green);color:var(--green);}
.tb-logout{width:34px;height:34px;border-radius:8px;border:1.5px solid var(--border);background:#fff;display:flex;align-items:center;justify-content:center;cursor:pointer;color:var(--sub);text-decoration:none;font-size:.9rem;transition:all .2s;}
.tb-logout:hover{border-color:#dc2626;color:#dc2626;}

/* ── HERO BANNER ── */
.hero-banner{background:linear-gradient(135deg,#0a4a35 0%,#1a7a5e 60%,#2d9970 100%);padding:40px 32px 36px;position:relative;overflow:hidden;}
.hero-banner::before{content:'';position:absolute;width:500px;height:500px;border-radius:50%;background:rgba(255,255,255,.04);top:-200px;right:-100px;}
.hero-banner::after{content:'';position:absolute;width:300px;height:300px;border-radius:50%;background:rgba(255,255,255,.03);bottom:-100px;left:200px;}
.hb-loc{display:flex;align-items:center;gap:5px;color:rgba(255,255,255,.7);font-size:.82rem;margin-bottom:10px;position:relative;z-index:1;}
.hb-title{font-size:2.2rem;font-weight:700;color:#fff;margin-bottom:14px;position:relative;z-index:1;}
.hb-tags{display:flex;gap:8px;flex-wrap:wrap;position:relative;z-index:1;}
.hb-tag{background:rgba(255,255,255,.15);color:#fff;border:1px solid rgba(255,255,255,.2);border-radius:99px;padding:4px 14px;font-size:.78rem;font-weight:600;}

/* ── CONTENT ── */
.content{max-width:1300px;margin:0 auto;padding:24px 28px;}

/* ── STAT CARDS ── */
.stats-grid{display:grid;grid-template-columns:repeat(6,1fr);gap:12px;margin-bottom:12px;}
.stats-grid.row2{margin-bottom:24px;}
.stat-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px 18px;}
.stat-card .sc-label{font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.06em;color:var(--muted);display:flex;align-items:center;gap:5px;margin-bottom:8px;}
.stat-card .sc-label i{font-size:.72rem;}
.stat-card .sc-val{font-size:1.6rem;font-weight:700;color:var(--text);}
.sc-val.green{color:#16a34a;}
.sc-val.red{color:#dc2626;}
.sc-val.orange{color:#c2410c;}
.sc-val.blue{color:#1d4ed8;}
.sc-val.purple{color:#6d28d9;}

/* ── ALERT ── */
.alert{padding:10px 14px;border-radius:9px;font-size:.84rem;margin-bottom:16px;display:flex;align-items:center;gap:9px;border:1px solid;}
.alert-success{background:#f0fdf4;color:#166534;border-color:#bbf7d0;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}

/* ── TABS ── */
.tabs-bar{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:4px;display:flex;gap:2px;margin:0 auto 20px;width:fit-content;flex-wrap:wrap;justify-content:center;}
.tab-btn{padding:8px 20px;border:none;background:none;font-family:inherit;font-size:.85rem;font-weight:500;color:var(--muted);cursor:pointer;border-radius:9px;transition:all .2s;white-space:nowrap;}
.tab-btn:hover{color:var(--text);}
.tab-btn.active{background:var(--green-soft);color:var(--green);font-weight:700;}
.tab-section{display:none;}.tab-section.active{display:block;animation:fadeIn .25s ease;}
@keyframes fadeIn{from{opacity:0;transform:translateY(6px)}to{opacity:1;transform:translateY(0)}}

/* ── FILTERS ── */
.filters-row{display:flex;align-items:center;gap:10px;margin-bottom:16px;flex-wrap:wrap;}
.filter-input{padding:8px 14px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;outline:none;color:var(--text);background:#fff;transition:border-color .2s;}
.filter-input:focus{border-color:var(--green);}
.filter-select{padding:8px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.84rem;outline:none;background:#fff;color:var(--text);cursor:pointer;}
.filter-count{margin-left:auto;font-size:.82rem;color:var(--muted);}

/* ── FLAT GRID ── */
.flat-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:14px;}
.flat-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px;position:relative;transition:box-shadow .2s;}
.flat-card:hover{box-shadow:0 4px 16px rgba(0,0,0,.08);}
.flat-badge{position:absolute;top:12px;right:12px;font-size:.65rem;font-weight:700;text-transform:uppercase;padding:3px 9px;border-radius:99px;}
.fb-occupied{background:#dcfce7;color:#166534;}
.fb-available{background:#f0f9f4;color:#166534;}
.fb-for_sale{background:#fef3c7;color:#92400e;}
.fb-for_rent{background:#eff6ff;color:#1d4ed8;}
.fb-resale{background:#ffedd5;color:#c2410c;}
.flat-no{font-size:1rem;font-weight:700;color:var(--text);margin-bottom:4px;}
.flat-sub{font-size:.75rem;color:var(--muted);margin-bottom:10px;}
.flat-details{display:flex;justify-content:space-between;font-size:.78rem;color:var(--sub);margin-bottom:8px;}
.flat-maint{font-size:.75rem;color:var(--sub);display:flex;align-items:center;gap:5px;}
.flat-maint i{color:var(--green);font-size:.7rem;}
.flat-actions{display:flex;gap:6px;margin-top:10px;}
.fa-btn{flex:1;padding:5px 8px;border:1.5px solid var(--border);background:#fff;border-radius:7px;font-family:inherit;font-size:.72rem;font-weight:600;cursor:pointer;color:var(--sub);transition:all .2s;text-align:center;}
.fa-btn:hover{border-color:var(--green);color:var(--green);}
.fa-btn.danger:hover{border-color:#dc2626;color:#dc2626;}

/* ── ADD BTN ── */
.btn-add{display:inline-flex;align-items:center;gap:7px;padding:9px 18px;background:var(--green);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;transition:background .2s;}
.btn-add:hover{background:var(--green-dark);}

/* ── RESIDENTS TABLE ── */
.data-table{width:100%;border-collapse:collapse;background:#fff;border-radius:var(--radius);overflow:hidden;border:1px solid var(--border);}
.data-table th{padding:10px 16px;text-align:left;font-size:.72rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.04em;background:var(--bg);border-bottom:1px solid var(--border);}
.data-table td{padding:13px 16px;font-size:.84rem;color:var(--text);border-bottom:1px solid var(--border);}
.data-table tbody tr:last-child td{border-bottom:none;}
.data-table tbody tr:hover{background:var(--green-soft);}
.avatar{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-weight:700;color:#fff;font-size:.82rem;flex-shrink:0;}
.role-badge{font-size:.65rem;font-weight:700;text-transform:uppercase;padding:2px 8px;border-radius:99px;background:var(--green-light);color:var(--green-dark);}

/* ── VENDOR CARDS ── */
.vendor-grid{display:grid;grid-template-columns:repeat(3,1fr);gap:14px;}
.vendor-card{background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:18px 20px;display:flex;align-items:center;gap:14px;}
.vendor-icon{width:42px;height:42px;border-radius:10px;background:var(--green-light);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:1rem;flex-shrink:0;}
.vendor-info{flex:1;min-width:0;}
.vendor-name{font-size:.9rem;font-weight:700;color:var(--text);margin-bottom:2px;}
.vendor-cat{font-size:.75rem;color:var(--muted);}
.vendor-ph{font-size:.75rem;color:var(--sub);margin-top:2px;}
.v-active{width:8px;height:8px;border-radius:50%;background:#16a34a;flex-shrink:0;}

/* ── NOTIFICATION BELL ─────────────────────────────── */
.notif-wrap{position:relative;display:inline-flex;}
.notif-bell{background:rgba(255,255,255,.1);border:none;cursor:pointer;color:black;width:36px;height:36px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-size:1rem;transition:background .2s;position:relative;}
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

/* ── MODAL ── */
.modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.45);z-index:200;display:none;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal{background:#fff;border-radius:16px;padding:28px 26px;width:100%;max-width:480px;max-height:90vh;overflow-y:auto;box-shadow:0 20px 60px rgba(0,0,0,.2);animation:slideUp .25s ease;}
@keyframes slideUp{from{transform:translateY(30px);opacity:0}to{transform:translateY(0);opacity:1}}
.modal h3{font-size:1rem;font-weight:700;margin-bottom:16px;color:var(--text);display:flex;align-items:center;gap:8px;}
.ff{margin-bottom:12px;}
.ff label{font-size:.78rem;font-weight:600;display:block;margin-bottom:4px;color:var(--text);}
.ff input,.ff select{width:100%;padding:9px 12px;border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;color:var(--text);outline:none;background:#fff;transition:border-color .2s;}
.ff input:focus,.ff select:focus{border-color:var(--green);}
.form-row-2{display:grid;grid-template-columns:1fr 1fr;gap:10px;}
.modal-footer{display:flex;gap:8px;justify-content:flex-end;margin-top:16px;}
.btn-cancel{padding:9px 18px;background:var(--bg);color:var(--sub);border:1.5px solid var(--border);border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;}
.btn-save{padding:9px 20px;background:var(--green);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;}
.btn-save:hover{background:var(--green-dark);}

/* ── EMPTY STATE ── */
.empty-state{text-align:center;padding:48px 20px;color:var(--muted);}
.empty-state i{font-size:2.5rem;display:block;margin-bottom:12px;color:var(--border);}
.empty-state p{font-size:.85rem;}

/* ── BILLING TABLE ── */
.pay-badge{font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:99px;text-transform:capitalize;}
.pb-paid{background:#dcfce7;color:#166534;}
.pb-pending{background:#fef9c3;color:#854d0e;}
.pb-pending_verification{background:#dbeafe;color:#1d4ed8;}
.pb-overdue{background:#fee2e2;color:#991b1b;}

/* ── HAMBURGER ─────────────────────────────────────── */
.ham-btn{display:none;background:none;border:none;cursor:pointer;color:var(--text);font-size:1.2rem;padding:6px 8px;border-radius:8px;margin-left:8px;}
.ham-btn:hover{background:var(--green-soft);}
.mob-nav-overlay{display:none;position:fixed;inset:0;z-index:500;background:rgba(0,0,0,.45);}
.mob-nav-overlay.open{display:block;}
.mob-nav-panel{position:absolute;top:0;right:0;width:260px;height:100%;background:#fff;border-left:1px solid var(--border);display:flex;flex-direction:column;animation:slideRight .25s ease;overflow-y:auto;}
@keyframes slideRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
.mob-nav-head{display:flex;align-items:center;justify-content:space-between;padding:16px 18px;border-bottom:1px solid var(--border);}
.mob-nav-head span{font-weight:700;font-size:.92rem;color:var(--text);}
.mob-close-btn{background:none;border:none;color:var(--muted);font-size:1.1rem;cursor:pointer;padding:4px 8px;border-radius:6px;}
.mob-nav-body{flex:1;padding:8px 0;}
.mob-nav-item{display:flex;align-items:center;gap:12px;padding:13px 18px;color:var(--sub);font-size:.88rem;font-weight:500;cursor:pointer;transition:background .15s;border:none;background:none;width:100%;text-align:left;font-family:inherit;}
.mob-nav-item:hover{background:var(--green-soft);color:var(--green);}
.mob-nav-item i{width:16px;text-align:center;color:var(--green);}
.mob-nav-divider{height:1px;background:var(--border);margin:6px 18px;}
.mob-nav-foot{padding:14px 18px;border-top:1px solid var(--border);display:flex;flex-direction:column;gap:8px;}
.mob-nav-foot a{display:flex;align-items:center;justify-content:center;gap:8px;padding:10px;border-radius:9px;font-size:.85rem;font-weight:600;text-decoration:none;transition:.2s;}
.mob-public-btn{background:var(--green-soft);color:var(--green);border:1px solid var(--green-light);}
.mob-logout-btn{background:#fef2f2;color:#dc2626;border:1px solid #fecaca;}

@media(max-width:1100px){
    .stats-grid{grid-template-columns:repeat(3,1fr);}
    .flat-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:900px){
    .topbar{padding:0 16px;}
    .tb-home{display:none;}
    .tb-divider{display:none;}
    .tb-public{display:none;}
    .ham-btn{display:flex;align-items:center;}
    .hero-banner{padding:24px 18px 20px;}
    .hb-title{font-size:1.6rem;}
    .content{padding:16px;}
    .stats-grid{grid-template-columns:repeat(3,1fr);}
    .flat-grid{grid-template-columns:repeat(2,1fr);}
    .vendor-grid{grid-template-columns:1fr 1fr;}
    .tabs-bar{width:100%;overflow-x:auto;flex-wrap:nowrap;justify-content:flex-start;-webkit-overflow-scrolling:touch;scrollbar-width:none;}
    .tabs-bar::-webkit-scrollbar{display:none;}
    .tab-btn{flex-shrink:0;}
    .form-row-2{grid-template-columns:1fr;}
    .data-table{display:block;overflow-x:auto;}
}
@media(max-width:600px){
    .stats-grid{grid-template-columns:repeat(2,1fr);}
    .stats-grid.row2{grid-template-columns:repeat(2,1fr);}
    .flat-grid{grid-template-columns:1fr 1fr;}
    .vendor-grid{grid-template-columns:1fr;}
    .hb-title{font-size:1.3rem;}
    .modal{padding:20px 14px;}
    .modal-overlay{padding:10px;}
    .filters-row{flex-direction:column;align-items:stretch;}
    .filter-input,.filter-select{width:100%;}
}
@media(max-width:480px){
    .stats-grid{grid-template-columns:1fr 1fr;}
    .flat-grid{grid-template-columns:1fr;}
    .vendor-grid{grid-template-columns:1fr;}
    .hb-tags{gap:5px;}
    .hb-tag{font-size:.7rem;padding:3px 10px;}
    .tb-brand-name{max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
}
</style>
</head>
<body>

<!-- TOPBAR -->
<header class="topbar">
    <a href="/index.php" class="tb-home"><i class="fa fa-arrow-left"></i> Home</a>
    <span class="tb-divider">›</span>
    <div class="tb-brand">
        <div class="tb-brand-icon"><i class="fa fa-key"></i></div>
        <div>
            <div style="font-size:.68rem;color:var(--muted);font-weight:500;">Owner Dashboard</div>
            <div class="tb-brand-name"><?= htmlspecialchars($society_name) ?></div>
        </div>
    </div>
    <div class="tb-right">
        <a href="/society-profile.php?id=<?= $society_id ?>" class="tb-public" target="_blank">
            <i class="fa fa-arrow-up-right-from-square"></i> Public page
        </a>
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

        <a href="/auth/logout.php" class="tb-logout" title="Logout"><i class="fa fa-right-from-bracket"></i></a>
        <button class="ham-btn" onclick="openSocMobNav()" aria-label="Menu"><i class="fa fa-bars"></i></button>
    </div>
</header>

<!-- MOBILE NAV -->
<div class="mob-nav-overlay" id="socMobNav" onclick="closeSocMobNavBg(event)">
  <div class="mob-nav-panel">
    <div class="mob-nav-head">
      <span><?= htmlspecialchars($society_name) ?></span>
      <button class="mob-close-btn" onclick="closeSocMobNav()"><i class="fa fa-xmark"></i></button>
    </div>
    <div class="mob-nav-body">
      <div style="padding:8px 18px 4px;font-size:.7rem;font-weight:700;color:var(--muted);text-transform:uppercase;letter-spacing:.06em;">Owner Dashboard</div>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="switchTab('flats',null);closeSocMobNav()"><i class="fa fa-building"></i> Flats & Towers</button>
      <button class="mob-nav-item" onclick="switchTab('approvals',null);closeSocMobNav()"><i class="fa fa-user-check"></i> Approvals</button>
      <button class="mob-nav-item" onclick="switchTab('residents',null);closeSocMobNav()"><i class="fa fa-users"></i> Residents</button>
      <button class="mob-nav-item" onclick="switchTab('maintenance',null);closeSocMobNav()"><i class="fa fa-credit-card"></i> Maintenance</button>
      <button class="mob-nav-item" onclick="switchTab('complaints',null);closeSocMobNav()"><i class="fa fa-comments"></i> Complaints</button>
      <button class="mob-nav-item" onclick="switchTab('vendors',null);closeSocMobNav()"><i class="fa fa-store"></i> Vendors</button>
      <button class="mob-nav-item" onclick="switchTab('events',null);closeSocMobNav()"><i class="fa fa-calendar"></i> Events</button>
      <button class="mob-nav-item" onclick="switchTab('notices',null);closeSocMobNav()"><i class="fa fa-bell"></i> Notices</button>
      <div class="mob-nav-divider"></div>
      <button class="mob-nav-item" onclick="switchTab('gallery',null);closeSocMobNav()"><i class="fa fa-images"></i> Gallery</button>
      <button class="mob-nav-item" onclick="switchTab('amenities',null);closeSocMobNav()"><i class="fa fa-star"></i> Amenities</button>
      <button class="mob-nav-item" onclick="switchTab('nearby',null);closeSocMobNav()"><i class="fa fa-map-pin"></i> Nearby</button>
    </div>
    <div class="mob-nav-foot">
      <a href="/society-profile.php?id=<?= $society_id ?>" class="mob-public-btn" target="_blank"><i class="fa fa-arrow-up-right-from-square"></i> Public Page</a>
      <a href="/auth/logout.php" class="mob-logout-btn"><i class="fa fa-right-from-bracket"></i> Logout</a>
    </div>
  </div>
</div>

<!-- HERO BANNER -->
<div class="hero-banner">
    <div class="hb-loc"><i class="fa fa-location-dot"></i>
        <?= htmlspecialchars(trim(($society['city']??'').', '.($society['state']??''), ', ')) ?>
    </div>
    <div class="hb-title"><?= htmlspecialchars($society_name) ?></div>
    <div class="hb-tags">
        <?php if(!empty($society['total_flats'])): ?>
        <span class="hb-tag"><?= $society['total_flats'] ?> Flats</span>
        <?php endif; ?>
        <?php if(!empty($society['established_year'])): ?>
        <span class="hb-tag">Est. <?= $society['established_year'] ?></span>
        <?php endif; ?>
        <?php if(!empty($society['city'])): ?>
        <span class="hb-tag"><?= htmlspecialchars($society['city']) ?></span>
        <?php endif; ?>
        <span class="hb-tag">Active</span>
    </div>
</div>

<!-- CONTENT -->
<div class="content">

<?php if($msg): ?><div class="alert alert-success"><i class="fa fa-check-circle"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>

<!-- STATS ROW 1 -->
<div class="stats-grid">
    <div class="stat-card"><div class="sc-label"><i class="fa fa-building"></i> Total Flats</div><div class="sc-val"><?= $total_flats ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-users"></i> Families</div><div class="sc-val"><?= $families ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-circle-check"></i> Occupied</div><div class="sc-val green"><?= $occupied ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-tag"></i> For Sale</div><div class="sc-val orange"><?= $for_sale ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-rotate"></i> Resale</div><div class="sc-val orange"><?= $resale ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-key"></i> For Rent</div><div class="sc-val blue"><?= $for_rent ?></div></div>
</div>

<!-- STATS ROW 2 -->
<div class="stats-grid row2">
    <div class="stat-card"><div class="sc-label"><i class="fa fa-indian-rupee-sign"></i> Collected</div><div class="sc-val green">₹<?= number_format($collected,0) ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-clock"></i> Pending Dues</div><div class="sc-val red">₹<?= number_format($pending_dues,0) ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-store"></i> Active Vendors</div><div class="sc-val purple"><?= $active_vendors ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-list"></i> Active Listings</div><div class="sc-val purple"><?= $for_sale + $for_rent ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-star"></i> Amenities</div><div class="sc-val"><?= count($amenities_list) ?></div></div>
    <div class="stat-card"><div class="sc-label"><i class="fa fa-user-tie"></i> Staff</div><div class="sc-val"><?= $staff_count ?></div></div>
</div>

<!-- TABS -->
<div class="tabs-bar">
    <button class="tab-btn active" onclick="switchTab('flats',this)">Flats &amp; Towers</button>
    <button class="tab-btn" onclick="switchTab('approvals',this)" id="approvalsTabBtn">
        Approvals
        <?php if($pending_count>0): ?><span class="cnt-warn" style="background:#fef9c3;color:#92400e;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:99px;margin-left:4px;"><?= $pending_count ?></span><?php endif; ?>
    </button>
    <button class="tab-btn" onclick="switchTab('residents',this)">Residents</button>
    <button class="tab-btn" onclick="switchTab('maintenance',this)">Maintenance</button>
    <button class="tab-btn" onclick="switchTab('complaints',this)">
        Complaints
        <?php if($open_complaints_count>0): ?><span class="cnt-warn" style="background:#fef9c3;color:#92400e;font-size:.65rem;font-weight:700;padding:1px 6px;border-radius:99px;margin-left:4px;"><?= $open_complaints_count ?></span><?php endif; ?>
    </button>

    <button class="tab-btn" onclick="switchTab('saletab',this)">Sale / Rent</button>
    <button class="tab-btn" onclick="switchTab('vendors',this)">Vendors</button>
    <button class="tab-btn" onclick="switchTab('events',this)">Events</button>
    <button class="tab-btn" onclick="switchTab('committee',this)">Committee</button>
    <button class="tab-btn" onclick="switchTab('gallery',this)">Gallery</button>
    <button class="tab-btn" onclick="switchTab('reviews',this)">Reviews</button>
    <button class="tab-btn" onclick="switchTab('amenities',this)">Amenities</button>
    <button class="tab-btn" onclick="switchTab('nearby',this)">Nearby</button>
</div>

<!-- ══ FLATS TAB ══ -->
<div class="tab-section active" id="tab-flats">

    <form method="POST" style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);padding:16px 20px;margin-bottom:20px;">
        <input type="hidden" name="update_contact" value="1">
        <div class="form-row-2" style="margin-bottom:10px">
            <div class="ff" style="margin-bottom:0">
                <label>Address</label>
                <input type="text" name="address" value="<?= htmlspecialchars($society['address']??'') ?>" placeholder="Sector 15, Vasundhara" required>
            </div>
            <div class="ff" style="margin-bottom:0">
                <label>Pincode</label>
                <input type="text" name="pincode" value="<?= htmlspecialchars($society['pincode']??'') ?>" maxlength="6" required>
            </div>
        </div>
        <div class="form-row-2" style="margin-bottom:10px">
            <div class="ff" style="margin-bottom:0">
                <label>City</label>
                <input type="text" name="city" value="<?= htmlspecialchars($society['city']??'') ?>" required>
            </div>
            <div class="ff" style="margin-bottom:0">
                <label>State</label>
                <input type="text" name="state" value="<?= htmlspecialchars($society['state']??'') ?>" required>
            </div>
        </div>
        <div class="form-row-2" style="margin-bottom:10px">
            <div class="ff" style="margin-bottom:0">
                <label>Public Contact Phone</label>
                <input type="tel" name="contact_phone" value="<?= htmlspecialchars($society['contact_phone']??'') ?>" placeholder="+91 98765 43210">
            </div>
            <div class="ff" style="margin-bottom:0">
                <label>Public Contact Email</label>
                <input type="email" name="contact_email" value="<?= htmlspecialchars($society['contact_email']??'') ?>" placeholder="office@yoursociety.in">
            </div>
        </div>
        <button type="submit" class="btn-save">Save Society Details</button>
    </form>


    <div class="filters-row">
        <input type="text" class="filter-input" id="flatSearch" placeholder="Search flat/tower..." oninput="filterFlats()" style="width:200px">
        <select class="filter-select" id="filterTower" onchange="filterFlats()">
            <option value="">All towers</option>
            <?php
            $towers = array_unique(array_column($flats, 'tower'));
            sort($towers);
            foreach($towers as $t): if($t): ?>
            <option>Tower <?= htmlspecialchars($t) ?></option>
            <?php endif; endforeach; ?>
        </select>
        <select class="filter-select" id="filterStatus" onchange="filterFlats()">
            <option value="">All statuses</option>
            <option value="occupied">Occupied</option>
            <option value="available">Available</option>
            <option value="for_sale">For Sale</option>
            <option value="for_rent">For Rent</option>
            <option value="resale">Resale</option>
        </select>
        <span class="filter-count" id="flatCount"><?= count($flats) ?> of <?= count($flats) ?></span>
        <button class="btn-add" style="margin-left:auto" onclick="openModal('addFlatModal')">
            <i class="fa fa-plus"></i> Add Flat
        </button>
    </div>

    <?php if(empty($flats)): ?>
    <div class="empty-state">
        <i class="fa fa-building"></i>
        <p style="font-weight:700;font-size:1rem;margin-bottom:6px">No flats added yet</p>
        <p>Click "Add Flat" to start adding flats/towers to your society.</p>
    </div>
    <?php else: ?>
    <div class="flat-grid" id="flatGrid">
        <?php foreach($flats as $f):
            $st = $f['status'];
            $badgeClass = 'fb-'.$st;
            $badgeLabel = strtoupper(str_replace('_',' ',$st));
        ?>
        <div class="flat-card" data-flat="<?= strtolower($f['flat_no']) ?>" data-tower="<?= strtolower('tower '.($f['tower']??'')) ?>" data-status="<?= $st ?>">
            <span class="flat-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
            <div class="flat-no"><?= htmlspecialchars($f['flat_no']) ?></div>
            <div class="flat-sub">Tower <?= htmlspecialchars($f['tower']??'—') ?> · Floor <?= $f['floor'] ?></div>
            <div class="flat-details">
                <span><?= htmlspecialchars($f['bhk']) ?></span>
                <span><?= $f['area_sqft'] ? number_format($f['area_sqft']).' sqft' : '' ?></span>
            </div>
            <?php if($f['maintenance']): ?>
            <div class="flat-maint"><i class="fa fa-building"></i> Maint. ₹<?= number_format($f['maintenance'],0) ?>/mo</div>
            <?php endif; ?>
            <div class="flat-actions">
                <form method="POST" style="flex:1">
                    <input type="hidden" name="update_flat_status" value="1">
                    <input type="hidden" name="flat_id" value="<?= $f['id'] ?>">
                    <select name="flat_status" class="fa-btn" style="width:100%;cursor:pointer" onchange="this.form.submit()">
                        <?php foreach(['occupied','available','for_sale','for_rent','resale'] as $s): ?>
                        <option value="<?= $s ?>" <?= $st===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
                <form method="POST" onsubmit="return confirm('Delete this flat?')">
                    <input type="hidden" name="del_flat" value="1">
                    <input type="hidden" name="flat_id" value="<?= $f['id'] ?>">
                    <button type="submit" class="fa-btn danger"><i class="fa fa-trash"></i></button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ APPROVALS TAB ══ -->
<div class="tab-section" id="tab-approvals">

    <div style="display:grid;grid-template-columns:repeat(3,1fr);gap:14px;margin-bottom:20px">
        <div class="stat-card" style="text-align:center;padding:16px">
            <div style="font-size:2rem;font-weight:700;color:<?= $pending_count>0?'#c2410c':'var(--green)' ?>"><?= $pending_count ?></div>
            <div style="font-size:.78rem;color:var(--muted);margin-top:3px">Awaiting Approval</div>
        </div>
        <div class="stat-card" style="text-align:center;padding:16px">
            <div style="font-size:2rem;font-weight:700;color:var(--green)"><?= $families ?></div>
            <div style="font-size:.78rem;color:var(--muted);margin-top:3px">Active Members</div>
        </div>
        <div class="stat-card" style="text-align:center;padding:16px">
            <div style="font-size:2rem;font-weight:700;color:var(--text)"><?= $families + $pending_count ?></div>
            <div style="font-size:.78rem;color:var(--muted);margin-top:3px">Total Registered</div>
        </div>
    </div>

    <?php if(empty($pending_list)): ?>
    <div class="empty-state">
        <i class="fa fa-circle-check" style="color:var(--green)"></i>
        <p style="font-weight:700;font-size:1rem;margin-bottom:6px;color:var(--green)">All caught up!</p>
        <p>No pending registration requests right now.</p>
    </div>
    <?php else: ?>

    <div style="font-size:.85rem;color:var(--sub);margin-bottom:14px;display:flex;align-items:center;gap:8px">
        <i class="fa fa-circle-info" style="color:var(--green)"></i>
        As society owner, you can approve or reject <strong>any</strong> role — including Admin requests, which only you can approve.
    </div>

    <?php
    $rcolors=['#2d7a52','#1d4ed8','#6d28d9','#c2410c','#0369a1','#b45309'];
    foreach($pending_list as $p):
        $init = strtoupper(substr($p['name'],0,1));
        $col  = $rcolors[abs(crc32($p['name']))%count($rcolors)];
        $reg_time = date('d M Y, h:i A', strtotime($p['created_at']));
        $roleBadge = match($p['role']){
            'admin'=>'rb-admin','staff'=>'rb-staff','accountant'=>'rb-accountant',
            'vendor'=>'rb-vendor','society_member'=>'rb-society',default=>'rb-resident'
        };
    ?>
    <div class="perm-card-society" style="background:#fff;border:1px solid var(--border);border-radius:var(--radius);margin-bottom:14px;overflow:hidden;">
        <div style="padding:18px 20px;display:flex;align-items:flex-start;gap:14px;">
            <div class="avatar" style="background:<?= $col ?>;width:48px;height:48px;font-size:1.1rem;flex-shrink:0;"><?= $init ?></div>
            <div style="flex:1;min-width:0">
                <div style="font-size:.95rem;font-weight:700;color:var(--text);margin-bottom:4px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;">
                    <?= htmlspecialchars($p['name']) ?>
                    <span class="role-badge <?= $roleBadge ?>"><?= ucfirst(str_replace('_',' ',$p['role'])) ?></span>
                    <?php if($p['role']==='admin'): ?>
                    <span style="font-size:.65rem;font-weight:700;background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:99px;"><i class="fa fa-crown"></i> Owner approval required</span>
                    <?php endif; ?>
                </div>
                <div style="display:flex;flex-wrap:wrap;gap:10px;font-size:.76rem;color:var(--muted);">
                    <span><i class="fa fa-envelope"></i> <?= htmlspecialchars($p['email']) ?></span>
                    <?php if(!empty($p['phone'])): ?><span><i class="fa fa-phone"></i> <?= htmlspecialchars($p['phone']) ?></span><?php endif; ?>
                    <?php if(!empty($p['unit']) || !empty($p['block'])): ?><span><i class="fa fa-location-dot"></i> <?= htmlspecialchars(($p['block']??'').'-'.($p['unit']??'')) ?></span><?php endif; ?>
                </div>
            </div>
            <div style="font-size:.72rem;color:var(--muted);flex-shrink:0;text-align:right"><i class="fa fa-clock"></i> Registered<br><?= $reg_time ?></div>
        </div>
        <div style="border-top:1px solid var(--border);padding:14px 20px;background:var(--bg);display:flex;gap:10px;justify-content:flex-end;">
            <form method="POST" onsubmit="return confirm('Reject and delete registration for <?= htmlspecialchars($p['name']) ?>?\n\nThis cannot be undone.')">
                <input type="hidden" name="reject_user" value="1">
                <input type="hidden" name="reject_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn-reject" style="padding:9px 18px;background:#fff;color:#dc2626;border:1.5px solid #fecaca;border-radius:9px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                    <i class="fa fa-circle-xmark"></i> Reject
                </button>
            </form>
            <form method="POST">
                <input type="hidden" name="approve_user" value="1">
                <input type="hidden" name="approve_id" value="<?= $p['id'] ?>">
                <button type="submit" class="btn-approve" style="padding:9px 20px;background:var(--green);color:#fff;border:none;border-radius:9px;font-family:inherit;font-size:.84rem;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:7px;">
                    <i class="fa fa-circle-check"></i> Approve Access
                </button>
            </form>
        </div>
    </div>
    <?php endforeach; endif; ?>

    <?php if(!empty($pending_unit_requests)): ?>
    <div style="margin-top:20px;">
        <h4 style="font-size:.85rem;color:var(--muted);margin-bottom:12px;text-transform:uppercase;letter-spacing:.05em;">Unit Link Requests</h4>
        <?php foreach($pending_unit_requests as $ur): ?>
        <div class="user-row">
            <div class="u-avatar" style="background:#dbeafe;color:#1d4ed8"><i class="fa fa-building"></i></div>
            <div class="u-info">
                <div class="u-name"><?= htmlspecialchars($ur['resident_name']) ?></div>
                <div class="u-meta">Requesting Flat <?= htmlspecialchars($ur['unit']) ?><?= $ur['block']?' · Block '.htmlspecialchars($ur['block']):'' ?> · <?= date('d M Y', strtotime($ur['requested_at'])) ?></div>
                <div class="u-meta"><?= htmlspecialchars($ur['resident_email']) ?></div>
            </div>
            <div style="display:flex;gap:8px;margin-left:auto;">
                <form method="POST">
                    <input type="hidden" name="approve_unit" value="1">
                    <input type="hidden" name="unit_request_id" value="<?= $ur['id'] ?>">
                    <button type="submit" class="btn-save" style="padding:6px 14px;font-size:.78rem"><i class="fa fa-check"></i> Approve</button>
                </form>
                <form method="POST" onsubmit="return confirm('Reject this unit request?')">
                    <input type="hidden" name="reject_unit" value="1">
                    <input type="hidden" name="unit_request_id" value="<?= $ur['id'] ?>">
                    <button type="submit" class="btn-cancel" style="padding:6px 14px;font-size:.78rem;color:#dc2626;border-color:#fecaca"><i class="fa fa-xmark"></i> Reject</button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ RESIDENTS TAB ══ -->
<div class="tab-section" id="tab-residents">


    <!-- Header row with search + Add button -->
    <div class="filters-row" style="margin-bottom:16px">
        <input type="text" class="filter-input" id="resSearch" placeholder="Search resident..." oninput="filterResidents()" style="width:220px">
        <select class="filter-select" id="resRoleFilter" onchange="filterResidents()">
            <option value="">All roles</option>
            <option value="resident">Resident</option>
            <option value="admin">Admin</option>
            <option value="staff">Staff</option>
            <option value="accountant">Accountant</option>
            <option value="vendor">Vendor</option>
        </select>
        <span class="filter-count" id="resCount"><?= count($residents) ?> residents</span>
        <button class="btn-add" style="margin-left:auto" onclick="openModal('addResidentModal')">
            <i class="fa fa-user-plus"></i> Add Resident
        </button>
    </div>

    <?php if(empty($residents)): ?>
    <div class="empty-state">
        <i class="fa fa-users"></i>
        <p style="font-weight:700;font-size:1rem;margin-bottom:6px">No residents yet</p>
        <p>Click "Add Resident" to add residents to your society.</p>
        <button class="btn-add" style="margin-top:16px;display:inline-flex" onclick="openModal('addResidentModal')">
            <i class="fa fa-user-plus"></i> Add First Resident
        </button>
    </div>
    <?php else: ?>
    <table class="data-table" id="resTable">
        <thead>
            <tr>
                <th>Resident</th>
                <th>Email</th>
                <th>Phone</th>
                <th>Unit / Block</th>
                <th>Role</th>
                <th>Status</th>
                <th>Action</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $colors=['#2d7a52','#1d4ed8','#6d28d9','#c2410c','#0369a1'];
        foreach($residents as $r):
            $init = strtoupper(substr($r['name'],0,1));
            $col  = $colors[abs(crc32($r['name']))%count($colors)];
            $unit = trim(($r['block']??'').($r['unit']??' '.$r['unit']??''));
            $unit = trim($r['block']??'','').($r['unit'] ? '-'.$r['unit'] : '');
        ?>
        <tr data-name="<?= strtolower($r['name']) ?>"
            data-email="<?= strtolower($r['email']) ?>"
            data-role="<?= $r['role'] ?>">
            <td>
                <div style="display:flex;align-items:center;gap:10px">
                    <div class="avatar" style="background:<?= $col ?>"><?= $init ?></div>
                    <div>
                        <div style="font-weight:600"><?= htmlspecialchars($r['name']) ?></div>
                        <div style="font-size:.72rem;color:var(--muted)">Added <?= date('d M Y', strtotime($r['created_at'])) ?></div>
                    </div>
                </div>
            </td>
            <td style="color:var(--sub)"><?= htmlspecialchars($r['email']) ?></td>
            <td style="color:var(--sub)"><?= htmlspecialchars($r['phone']??'—') ?></td>
            <td><?= $unit ?: '—' ?></td>
            <td><span class="role-badge"><?= ucfirst($r['role']) ?></span></td>
            <td>
                <span style="font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:99px;
                    background:<?= $r['is_active']?'#dcfce7':'#fef9c3' ?>;
                    color:<?= $r['is_active']?'#166534':'#854d0e' ?>">
                    <?= $r['is_active']?'Active':'Pending' ?>
                </span>
            </td>
            <td>
                <?php if($r['id'] != $user_id): ?>
                <form method="POST" onsubmit="return confirm('Remove <?= htmlspecialchars($r['name']) ?> from this society?')">
                    <input type="hidden" name="del_resident" value="1">
                    <input type="hidden" name="resident_id" value="<?= $r['id'] ?>">
                    <button type="submit" class="fa-btn danger" style="width:auto;padding:5px 10px">
                        <i class="fa fa-trash"></i>
                    </button>
                </form>
                <?php else: ?>
                <span style="font-size:.72rem;color:var(--muted)">(you)</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ══ MAINTENANCE TAB ══ -->
<div class="tab-section" id="tab-maintenance">

    <?php
    $bill_collected = 0; $bill_pending = 0; $bill_overdue_amt = 0;
    $bill_paid_count = 0; $bill_pending_count = 0; $bill_overdue_count = 0;
    foreach($bills as $b){
        if($b['status']==='paid'){ $bill_collected += $b['amount']; $bill_paid_count++; }
        elseif($b['status']==='overdue'){ $bill_overdue_amt += $b['amount']; $bill_overdue_count++; }
        else { $bill_pending += $b['amount']; $bill_pending_count++; }
    }
    ?>

    <!-- Quick stats -->
    <div class="stats-grid" style="margin-bottom:20px">
        <div class="stat-card"><div class="sc-label"><i class="fa fa-circle-check"></i> Paid</div><div class="sc-val green"><?= $bill_paid_count ?></div></div>
        <div class="stat-card"><div class="sc-label"><i class="fa fa-clock"></i> Pending</div><div class="sc-val orange"><?= $bill_pending_count ?></div></div>
        <div class="stat-card"><div class="sc-label"><i class="fa fa-triangle-exclamation"></i> Overdue</div><div class="sc-val red"><?= $bill_overdue_count ?></div></div>
        <div class="stat-card"><div class="sc-label"><i class="fa fa-indian-rupee-sign"></i> Collected</div><div class="sc-val green">₹<?= number_format($bill_collected,0) ?></div></div>
        <div class="stat-card"><div class="sc-label"><i class="fa fa-hourglass-half"></i> Pending Amt</div><div class="sc-val orange">₹<?= number_format($bill_pending+$bill_overdue_amt,0) ?></div></div>
        <div class="stat-card"><div class="sc-label"><i class="fa fa-list"></i> Total Bills</div><div class="sc-val"><?= count($bills) ?></div></div>
    </div>

    <div style="display:flex;justify-content:flex-end;gap:10px;margin-bottom:16px">
        <button class="btn-add" onclick="openModal('genBillModal')"><i class="fa fa-file-invoice"></i> Generate Bill</button>
        <button class="btn-add" style="background:var(--green-dark)" onclick="openModal('bulkBillModal')"><i class="fa fa-layer-group"></i> Generate for All</button>
    </div>

    <!-- Filters -->
    <div class="filters-row">
        <input type="text" class="filter-input" id="billSearch" placeholder="Search resident, unit..." oninput="filterBills()" style="width:220px">
        <select class="filter-select" id="billStatusFilter" onchange="filterBills()">
            <option value="">All statuses</option>
            <option value="paid">Paid</option>
            <option value="pending">Pending</option>
            <option value="overdue">Overdue</option>
        </select>
        <span class="filter-count" id="billCount"><?= count($bills) ?> bills</span>
    </div>

    <?php if(empty($bills)): ?>
    <div class="empty-state"><i class="fa fa-file-invoice-dollar"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No bills yet</p><p>Bills generated from the admin dashboard will appear here.</p></div>
    <?php else: ?>
    <table class="data-table" id="billTable">
        <thead><tr><th>Resident</th><th>Unit</th><th>Description</th><th>Amount</th><th>Month</th><th>Due Date</th><th>Status</th><th>Proof</th><th>Action</th></tr></thead>
        <tbody>
        <?php foreach($bills as $b):
            $sc=match($b['status']){'paid'=>'pb-paid','pending'=>'pb-pending','overdue'=>'pb-overdue','pending_verification'=>'pb-pending_verification',default=>'pb-pending'};
        ?>
        <tr data-name="<?= strtolower($b['resident_name']??'') ?>" data-unit="<?= strtolower($b['unit']??'') ?>" data-status="<?= $b['status'] ?>">
            <td style="font-weight:600"><?= htmlspecialchars($b['resident_name']??'—') ?></td>
            <td><?= htmlspecialchars($b['unit']??'—') ?></td>
            <td><?= htmlspecialchars($b['description']) ?></td>
            <td style="font-weight:700">₹<?= number_format($b['amount'],0) ?></td>
            <td style="color:var(--sub)"><?= htmlspecialchars($b['month']??'—') ?></td>
            <td style="color:var(--sub)"><?= $b['due_date']?date('d M Y',strtotime($b['due_date'])):'—' ?></td>
            <td><span class="pay-badge <?= $sc ?>"><?= $b['status']==='pending_verification' ? 'Verification Pending' : ucfirst($b['status']) ?></span></td>
            
            <td>
                <?php if(!empty($b['payment_proof'])): ?>
                <button type="button" class="fa-btn" style="width:auto;padding:5px 9px;border-color:#1d4ed8;color:#1d4ed8" title="View Screenshot"
                    onclick="openProofModal(<?= $b['id'] ?>, '<?= htmlspecialchars(addslashes($b['payment_proof'])) ?>', '<?= htmlspecialchars(addslashes($b['resident_name']??'Unknown')) ?>', '<?= htmlspecialchars(addslashes($b['description'])) ?>', <?= $b['amount'] ?>, '<?= htmlspecialchars(addslashes($b['txn_ref']??'')) ?>', '<?= $b['status'] ?>')">
                    <i class="fa fa-image"></i>
                </button>
                <?php else: ?>
                <span style="color:var(--muted);font-size:.78rem">—</span>
                <?php endif; ?>
            </td>

            <td>
                <div style="display:flex;gap:5px">
                    <?php if($b['status'] !== 'paid'): ?>
                    <form method="POST" onsubmit="return confirm('Mark this bill as PAID?')">
                        <input type="hidden" name="mark_paid" value="1">
                        <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="fa-btn" style="width:auto;padding:5px 9px;border-color:#16a34a;color:#16a34a" title="Mark as Paid">
                            <i class="fa fa-check"></i>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if($b['status'] !== 'overdue'): ?>
                    <form method="POST" onsubmit="return confirm('Mark this bill as OVERDUE?')">
                        <input type="hidden" name="mark_overdue" value="1">
                        <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="fa-btn" style="width:auto;padding:5px 9px;border-color:#c2410c;color:#c2410c" title="Mark as Overdue">
                            <i class="fa fa-triangle-exclamation"></i>
                        </button>
                    </form>
                    <?php endif; ?>

                    <?php if($b['status'] !== 'pending'): ?>
                    <form method="POST" onsubmit="return confirm('Mark this bill as PENDING?')">
                        <input type="hidden" name="mark_pending" value="1">
                        <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="fa-btn" style="width:auto;padding:5px 9px;border-color:#92400e;color:#92400e" title="Mark as Pending">
                            <i class="fa fa-clock"></i>
                        </button>
                    </form>
                    <?php endif; ?>

                    <form method="POST" onsubmit="return confirm('Delete this bill permanently?')">
                        <input type="hidden" name="del_bill" value="1">
                        <input type="hidden" name="bill_id" value="<?= $b['id'] ?>">
                        <button type="submit" class="fa-btn danger" style="width:auto;padding:5px 9px" title="Delete">
                            <i class="fa fa-trash"></i>
                        </button>
                    </form>
                </div>
            </td>

        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ══ COMPLAINTS TAB ══ -->
<div class="tab-section" id="tab-complaints">
    <?php if(empty($complaints_list)): ?>
    <div class="empty-state"><i class="fa fa-comments"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No complaints yet</p><p>Resident complaints will appear here.</p></div>
    <?php else: ?>
    <table class="data-table">
        <thead><tr><th>Resident</th><th>Category</th><th>Subject</th><th>Priority</th><th>Filed</th><th>Status</th></tr></thead>
        <tbody>
        <?php foreach($complaints_list as $c):
            $sc = match($c['status']){'open'=>'pb-overdue','in_progress'=>'pb-pending','resolved'=>'pb-paid',default=>'pb-pending'};
        ?>
        <tr>
            <td style="font-weight:600"><?= htmlspecialchars($c['resident_name']??'—') ?></td>
            <td><?= htmlspecialchars($c['category']??'—') ?></td>
            <td><?= htmlspecialchars($c['subject']) ?></td>
            <td><?= ucfirst($c['priority']) ?></td>
            <td style="color:var(--sub)"><?= date('d M Y',strtotime($c['created_at'])) ?></td>
            <td>
                <form method="POST" style="display:inline">
                    <input type="hidden" name="update_complaint_status" value="1">
                    <input type="hidden" name="complaint_id" value="<?= $c['id'] ?>">
                    <select name="complaint_status" class="pay-badge <?= $sc ?>" style="border:none;cursor:pointer" onchange="this.form.submit()">
                        <?php foreach(['open','in_progress','resolved','closed'] as $s): ?>
                        <option value="<?= $s ?>" <?= $c['status']===$s?'selected':'' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
                        <?php endforeach; ?>
                    </select>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ══ SALE/RENT TAB ══ -->
<div class="tab-section" id="tab-saletab">
    <?php
    $listings = array_filter($flats, fn($f)=>in_array($f['status'],['for_sale','for_rent','resale']));
    if(empty($listings)):
    ?>
    <div class="empty-state"><i class="fa fa-tag"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No listings yet</p><p>Mark flats as "For Sale", "For Rent", or "Resale" to see them here.</p></div>
    <?php else: ?>
    <div class="flat-grid">
        <?php foreach($listings as $f):
            $st=$f['status']; $badgeClass='fb-'.$st; $badgeLabel=strtoupper(str_replace('_',' ',$st));
        ?>
        <div class="flat-card">
            <span class="flat-badge <?= $badgeClass ?>"><?= $badgeLabel ?></span>
            <div class="flat-no"><?= htmlspecialchars($f['flat_no']) ?></div>
            <div class="flat-sub">Tower <?= htmlspecialchars($f['tower']??'—') ?> · Floor <?= $f['floor'] ?></div>
            <div class="flat-details"><span><?= htmlspecialchars($f['bhk']) ?></span><span><?= $f['area_sqft']?number_format($f['area_sqft']).' sqft':'' ?></span></div>
            <?php if($f['maintenance']): ?><div class="flat-maint"><i class="fa fa-building"></i> ₹<?= number_format($f['maintenance'],0) ?>/mo</div><?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ VENDORS TAB ══ -->
<div class="tab-section" id="tab-vendors">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="btn-add" onclick="openModal('addVendorModal')"><i class="fa fa-plus"></i> Add Vendor</button>
    </div>
    <?php if(empty($vendors)): ?>
    <div class="empty-state"><i class="fa fa-store"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No vendors yet</p><p>Add plumbers, electricians, housekeeping vendors here.</p></div>
    <?php else: ?>
    <div class="vendor-grid">
        <?php foreach($vendors as $v): ?>
        <div class="vendor-card">
            <div class="vendor-icon"><i class="fa fa-store"></i></div>
            <div class="vendor-info">
                <div class="vendor-name"><?= htmlspecialchars($v['name']) ?></div>
                <div class="vendor-cat"><?= htmlspecialchars($v['category']??'General') ?></div>
                <?php if($v['phone']): ?><div class="vendor-ph"><i class="fa fa-phone" style="color:var(--green);font-size:.7rem"></i> <?= htmlspecialchars($v['phone']) ?></div><?php endif; ?>
            </div>
            <div class="v-active" title="Active"></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ EVENTS TAB ══ -->
<div class="tab-section" id="tab-events">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="btn-add" onclick="openModal('addEventModal')"><i class="fa fa-plus"></i> Add Event</button>
    </div>
    <?php if(empty($events)): ?>
    <div class="empty-state"><i class="fa fa-calendar"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No events yet</p><p>Add society events like Diwali Mela, AGM, etc.</p></div>
    <?php else: ?>
    <div class="flat-grid">
        <?php foreach($events as $ev): ?>
        <div class="flat-card">
            <div style="font-size:.68rem;font-weight:700;background:var(--green);color:#fff;display:inline-block;padding:3px 10px;border-radius:99px;margin-bottom:10px;">EVENT</div>
            <div class="flat-no"><?= htmlspecialchars($ev['title']) ?></div>
            <div class="flat-sub"><?= date('d M Y, h:i A', strtotime($ev['event_date'])) ?></div>
            <form method="POST" onsubmit="return confirm('Delete this event?')" style="margin-top:10px">
                <input type="hidden" name="del_event" value="1">
                <input type="hidden" name="event_id" value="<?= $ev['id'] ?>">
                <button type="submit" class="fa-btn danger" style="width:100%"><i class="fa fa-trash"></i> Remove</button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ COMMITTEE TAB ══ -->
<div class="tab-section" id="tab-committee">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="btn-add" onclick="openModal('addCommitteeModal')"><i class="fa fa-plus"></i> Add Member</button>
    </div>
    <?php if(empty($committee)): ?>
    <div class="empty-state"><i class="fa fa-users"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No committee members yet</p><p>Add RWA president, secretary, treasurer, etc.</p></div>
    <?php else: ?>
    <div class="vendor-grid">
        <?php
        $ccolors=['#2d7a52','#1d4ed8','#6d28d9','#c2410c','#0369a1'];
        foreach($committee as $c):
            $init=strtoupper(substr($c['name'],0,1));
            $col=$ccolors[abs(crc32($c['name']))%count($ccolors)];
        ?>
        <div class="vendor-card">
            <div class="vendor-icon" style="background:<?= $col ?>22;color:<?= $col ?>;border-radius:50%;font-weight:700"><?= $init ?></div>
            <div class="vendor-info">
                <div class="vendor-name"><?= htmlspecialchars($c['name']) ?></div>
                <div class="vendor-cat"><?= htmlspecialchars($c['position']) ?></div>
                <?php if($c['phone']): ?><div class="vendor-ph"><i class="fa fa-phone" style="color:var(--green);font-size:.7rem"></i> <?= htmlspecialchars($c['phone']) ?></div><?php endif; ?>
            </div>
            <form method="POST" onsubmit="return confirm('Remove this committee member?')">
                <input type="hidden" name="del_committee" value="1">
                <input type="hidden" name="committee_id" value="<?= $c['id'] ?>">
                <button type="submit" class="fa-btn danger" style="width:auto;padding:5px 10px"><i class="fa fa-trash"></i></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ GALLERY TAB ══ -->
<div class="tab-section" id="tab-gallery">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="btn-add" onclick="openModal('addGalleryModal')"><i class="fa fa-plus"></i> Add Photo</button>
    </div>
    <?php if(empty($gallery)): ?>
    <div class="empty-state"><i class="fa fa-images"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No photos yet</p><p>Add image URLs to showcase your society.</p></div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(4,1fr);gap:14px;">
        <?php foreach($gallery as $g): ?>
        <div style="position:relative;border-radius:var(--radius);overflow:hidden;aspect-ratio:1;border:1px solid var(--border)">
            <img src="<?= htmlspecialchars($g['image_url']) ?>" alt="<?= htmlspecialchars($g['caption']??'') ?>" style="width:100%;height:100%;object-fit:cover" loading="lazy">
            <form method="POST" onsubmit="return confirm('Remove this photo?')" style="position:absolute;top:8px;right:8px">
                <input type="hidden" name="del_gallery" value="1">
                <input type="hidden" name="gallery_id" value="<?= $g['id'] ?>">
                <button type="submit" style="width:28px;height:28px;border-radius:7px;border:none;background:rgba(0,0,0,.55);color:#fff;cursor:pointer"><i class="fa fa-trash" style="font-size:.7rem"></i></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ REVIEWS TAB ══ -->
<div class="tab-section" id="tab-reviews">
    <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:16px">
        <span style="font-size:.9rem;color:var(--sub)"><?= count($reviews) ?> reviews · <?= $avg_rating ?> ★ average</span>
        <button class="btn-add" onclick="openModal('addReviewModal')"><i class="fa fa-plus"></i> Add Review</button>
    </div>
    <?php if(empty($reviews)): ?>
    <div class="empty-state"><i class="fa fa-star"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No reviews yet</p><p>Add resident testimonials and feedback.</p></div>
    <?php else: ?>
    <?php foreach($reviews as $r): ?>
    <div class="flat-card" style="margin-bottom:12px;width:100%">
        <div style="color:#f59e0b;margin-bottom:8px"><?= str_repeat('★',round($r['rating'])) ?><?= str_repeat('☆',5-round($r['rating'])) ?></div>
        <p style="font-size:.85rem;color:var(--sub);line-height:1.6;margin-bottom:10px">"<?= htmlspecialchars($r['comment']) ?>"</p>
        <div style="display:flex;justify-content:space-between;align-items:center">
            <span style="font-size:.82rem;font-weight:700;color:var(--text)"><?= htmlspecialchars($r['reviewer_name']) ?></span>
            <form method="POST" onsubmit="return confirm('Delete this review?')">
                <input type="hidden" name="del_review" value="1">
                <input type="hidden" name="review_id" value="<?= $r['id'] ?>">
                <button type="submit" class="fa-btn danger" style="width:auto;padding:5px 10px"><i class="fa fa-trash"></i></button>
            </form>
        </div>
    </div>
    <?php endforeach; ?>
    <?php endif; ?>
</div>

<!-- ══ AMENITIES TAB ══ -->
<div class="tab-section" id="tab-amenities">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="btn-add" onclick="openModal('addAmenityModal')"><i class="fa fa-plus"></i> Add Amenity</button>
    </div>
    <?php if(empty($amenities_list)): ?>
    <div class="empty-state"><i class="fa fa-star"></i><p style="font-weight:700;font-size:1rem;margin-bottom:6px">No amenities yet</p><p>Add Swimming Pool, Gym, Clubhouse, etc.</p></div>
    <?php else: ?>
    <div style="display:flex;flex-wrap:wrap;gap:10px;">
        <?php foreach($amenities_list as $a): ?>
        <div style="display:inline-flex;align-items:center;gap:8px;background:var(--green-soft);border:1px solid #d4eddf;border-radius:99px;padding:8px 16px;font-size:.85rem;font-weight:600;color:var(--green-dark)">
            <i class="fa fa-check-circle" style="color:var(--green)"></i> <?= htmlspecialchars($a['name']) ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Remove this amenity?')">
                <input type="hidden" name="del_amenity" value="1">
                <input type="hidden" name="amenity_id" value="<?= $a['id'] ?>">
                <button type="submit" style="border:none;background:none;cursor:pointer;color:#dc2626;padding:0;margin-left:4px"><i class="fa fa-xmark"></i></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- ══ NEARBY TAB ══ -->
<div class="tab-section" id="tab-nearby">
    <div style="display:flex;justify-content:flex-end;margin-bottom:16px">
        <button class="btn-add" onclick="openModal('addNearbyModal')"><i class="fa fa-plus"></i> Add Place</button>
    </div>
    <?php if(empty($nearby_list)): ?>
    <div class="empty-state">
        <i class="fa fa-map-location-dot"></i>
        <p style="font-weight:700;font-size:1rem;margin-bottom:6px">No nearby places yet</p>
        <p>Add schools, hospitals, malls, metro stations near <?= htmlspecialchars($society_name) ?>.</p>
    </div>
    <?php else: ?>
    <div style="display:grid;grid-template-columns:repeat(2,1fr);gap:14px;">
        <?php foreach($nearby_list as $n): ?>
        <div class="vendor-card">
            <div class="vendor-icon"><i class="fa fa-location-dot"></i></div>
            <div class="vendor-info">
                <div class="vendor-name"><?= htmlspecialchars($n['name']) ?></div>
                <div class="vendor-cat"><?= htmlspecialchars($n['category']) ?> · <?= $n['distance_km'] ?> km</div>
            </div>
            <form method="POST" onsubmit="return confirm('Remove this place?')">
                <input type="hidden" name="del_nearby" value="1">
                <input type="hidden" name="nearby_id" value="<?= $n['id'] ?>">
                <button type="submit" class="fa-btn danger" style="width:auto;padding:5px 10px"><i class="fa fa-trash"></i></button>
            </form>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

</div><!-- /content -->

<!-- ══ ADD FLAT MODAL ══ -->
<div class="modal-overlay" id="addFlatModal">
<div class="modal">
    <h3><i class="fa fa-building" style="color:var(--green)"></i> Add New Flat</h3>
    <form method="POST">
        <input type="hidden" name="add_flat" value="1">
        <div class="form-row-2">
            <div class="ff"><label>Flat No *</label><input type="text" name="flat_no" placeholder="A-101" required></div>
            <div class="ff"><label>Tower</label><input type="text" name="tower" placeholder="A"></div>
        </div>
        <div class="form-row-2">
            <div class="ff"><label>Floor</label><input type="number" name="floor" value="1" min="1"></div>
            <div class="ff"><label>BHK</label>
                <select name="bhk">
                    <option>1BHK</option><option selected>2BHK</option><option>3BHK</option><option>4BHK</option><option>Studio</option>
                </select>
            </div>
        </div>
        <div class="form-row-2">
            <div class="ff"><label>Area (sqft)</label><input type="number" name="area" placeholder="1100" min="0"></div>
            <div class="ff"><label>Maintenance (₹/mo)</label><input type="number" name="maintenance" placeholder="3500" min="0" step="0.01"></div>
        </div>
        <div class="ff"><label>Status</label>
            <select name="status">
                <option value="available">Available</option>
                <option value="occupied">Occupied</option>
                <option value="for_sale">For Sale</option>
                <option value="for_rent">For Rent</option>
                <option value="resale">Resale</option>
            </select>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addFlatModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Flat</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD RESIDENT MODAL ══ -->
<div class="modal-overlay" id="addResidentModal">
<div class="modal">
    <h3><i class="fa fa-user-plus" style="color:var(--green)"></i> Add New Resident</h3>
    <form method="POST">
        <input type="hidden" name="add_resident" value="1">

        <div class="form-row-2">
            <div class="ff"><label>Full Name *</label><input type="text" name="r_name" placeholder="Rahul Sharma" required></div>
            <div class="ff"><label>Email *</label><input type="email" name="r_email" placeholder="rahul@example.com" required></div>
        </div>

        <div class="form-row-2">
            <div class="ff"><label>Phone</label><input type="tel" name="r_phone" placeholder="9876543210" maxlength="10"></div>
            <div class="ff"><label>Role</label>
                <select name="r_role">
                    <option value="resident" selected>🏠 Resident</option>
                    <option value="staff">👷 Staff</option>
                    <option value="accountant">🧾 Accountant</option>
                    <option value="vendor">🏪 Vendor</option>
                    <option value="admin">👑 Admin</option>
                </select>
            </div>
        </div>

        <div class="form-row-2">
            <div class="ff"><label>Block</label>
                <select name="r_block">
                    <option value="">Select Block</option>
                    <?php foreach(['A','B','C','D','E','F'] as $bl): ?>
                    <option value="<?= $bl ?>">Block <?= $bl ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="ff"><label>Unit / Flat No</label><input type="text" name="r_unit" placeholder="101"></div>
        </div>

        <div class="ff">
            <label>Password <span style="font-size:.72rem;color:var(--muted)">(default: colony@123)</span></label>
            <input type="text" name="r_password" placeholder="Leave blank for default: colony@123">
        </div>

        <div style="background:#f0f9f4;border:1px solid #d4eddf;border-radius:9px;padding:10px 14px;font-size:.78rem;color:#0f5c46;margin-bottom:4px;">
            <i class="fa fa-circle-info"></i>
            The resident will be able to login immediately using their email and the password you set.
            Society: <strong><?= htmlspecialchars($society_name) ?></strong>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addResidentModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-user-plus"></i> Add Resident</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD VENDOR MODAL ══ -->
<div class="modal-overlay" id="addVendorModal">
<div class="modal">
    <h3><i class="fa fa-store" style="color:var(--green)"></i> Add Vendor</h3>
    <form method="POST">
        <input type="hidden" name="add_vendor" value="1">
        <div class="ff"><label>Vendor Name *</label><input type="text" name="v_name" placeholder="Raju Plumbing Services" required></div>
        <div class="ff"><label>Category</label>
            <select name="v_cat">
                <option>Plumbing</option><option>Electrician</option><option>Housekeeping</option>
                <option>Security</option><option>Lift AMC</option><option>Pest Control</option><option>Other</option>
            </select>
        </div>
        <div class="ff"><label>Phone</label><input type="tel" name="v_phone" placeholder="9876543210" maxlength="10"></div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addVendorModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Vendor</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD EVENT MODAL ══ -->
<div class="modal-overlay" id="addEventModal">
<div class="modal">
    <h3><i class="fa fa-calendar" style="color:var(--green)"></i> Add Event</h3>
    <form method="POST">
        <input type="hidden" name="add_event" value="1">
        <div class="ff"><label>Event Title *</label><input type="text" name="event_title" placeholder="Diwali Mela" required></div>
        <div class="ff"><label>Date &amp; Time *</label><input type="datetime-local" name="event_date" required></div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addEventModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Event</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD COMMITTEE MODAL ══ -->
<div class="modal-overlay" id="addCommitteeModal">
<div class="modal">
    <h3><i class="fa fa-users" style="color:var(--green)"></i> Add Committee Member</h3>
    <form method="POST">
        <input type="hidden" name="add_committee" value="1">
        <div class="ff"><label>Full Name *</label><input type="text" name="c_name" placeholder="Rajesh Sharma" required></div>
        <div class="ff"><label>Position</label>
            <select name="c_pos">
                <option>President</option><option>Secretary</option><option>Treasurer</option>
                <option>Vice President</option><option>Joint Secretary</option><option>Member</option>
            </select>
        </div>
        <div class="ff"><label>Phone</label><input type="tel" name="c_phone" placeholder="9876543210" maxlength="10"></div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addCommitteeModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Member</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD GALLERY MODAL ══ -->
<div class="modal-overlay" id="addGalleryModal">
<div class="modal">
    <h3><i class="fa fa-images" style="color:var(--green)"></i> Add Photo</h3>
    <form method="POST">
        <input type="hidden" name="add_gallery" value="1">
        <div class="ff"><label>Image URL *</label><input type="url" name="g_url" placeholder="https://images.unsplash.com/..." required></div>
        <div class="ff"><label>Caption</label><input type="text" name="g_caption" placeholder="Clubhouse exterior"></div>
        <div style="background:#f0f9ff;border:1px solid #bae6fd;border-radius:9px;padding:10px 14px;font-size:.78rem;color:#0369a1;margin-bottom:4px;">
            <i class="fa fa-circle-info"></i> Paste a direct image URL (e.g. from Unsplash, Imgur, or your own hosting).
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addGalleryModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Photo</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD REVIEW MODAL ══ -->
<div class="modal-overlay" id="addReviewModal">
<div class="modal">
    <h3><i class="fa fa-star" style="color:var(--green)"></i> Add Review</h3>
    <form method="POST">
        <input type="hidden" name="add_review" value="1">
        <div class="ff"><label>Reviewer Name *</label><input type="text" name="rv_name" placeholder="Priya Mehta" required></div>
        <div class="ff"><label>Rating</label>
            <select name="rv_rating">
                <option value="5">★★★★★ (5)</option>
                <option value="4">★★★★☆ (4)</option>
                <option value="3">★★★☆☆ (3)</option>
                <option value="2">★★☆☆☆ (2)</option>
                <option value="1">★☆☆☆☆ (1)</option>
            </select>
        </div>
        <div class="ff"><label>Comment *</label><input type="text" name="rv_comment" placeholder="Great community, amazing amenities..." required></div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addReviewModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Review</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD AMENITY MODAL ══ -->
<div class="modal-overlay" id="addAmenityModal">
<div class="modal">
    <h3><i class="fa fa-star" style="color:var(--green)"></i> Add Amenity</h3>
    <form method="POST">
        <input type="hidden" name="add_amenity" value="1">
        <div class="ff"><label>Amenity Name *</label>
            <input type="text" name="amenity_name" placeholder="Swimming Pool" required list="amenitySuggestions">
            <datalist id="amenitySuggestions">
                <option>24/7 Security</option><option>Parking</option><option>Swimming Pool</option>
                <option>Gymnasium</option><option>Children Play Area</option><option>Garden &amp; Landscaping</option>
                <option>Power Backup</option><option>Clubhouse</option><option>Lift</option><option>Community Wi-Fi</option>
            </datalist>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addAmenityModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Amenity</button>
        </div>
    </form>
</div>
</div>

<!-- ══ ADD NEARBY MODAL ══ -->
<div class="modal-overlay" id="addNearbyModal">
<div class="modal">
    <h3><i class="fa fa-location-dot" style="color:var(--green)"></i> Add Nearby Place</h3>
    <form method="POST">
        <input type="hidden" name="add_nearby" value="1">
        <div class="ff"><label>Place Name *</label><input type="text" name="n_name" placeholder="City Mall" required></div>
        <div class="form-row-2">
            <div class="ff"><label>Category</label>
                <select name="n_cat">
                    <option>Shopping</option><option>School</option><option>Hospital</option>
                    <option>Transport</option><option>Park</option><option>Restaurant</option><option>Other</option>
                </select>
            </div>
            <div class="ff"><label>Distance (km)</label><input type="number" name="n_dist" placeholder="1.2" step="0.1" min="0"></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('addNearbyModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Add Place</button>
        </div>
    </form>
</div>
</div>

<!-- ══ GENERATE BILL MODAL ══ -->
<div class="modal-overlay" id="genBillModal">
<div class="modal">
    <h3><i class="fa fa-file-invoice" style="color:var(--green)"></i> Generate Bill</h3>
    <form method="POST">
        <input type="hidden" name="gen_bill" value="1">
        <div class="ff"><label>Member *</label>
            <select name="bill_uid" required onchange="fillBillUnit(this)">
                <option value="">Select Member</option>
                <?php foreach($residents as $r): if(in_array($r['role'],['resident','admin']) && $r['is_active']): ?>
                <option value="<?= $r['id'] ?>" data-unit="<?= htmlspecialchars(($r['block']??'').($r['unit']?'-'.$r['unit']:'')) ?>">
                    <?= htmlspecialchars($r['name']) ?> (<?= ucfirst($r['role']) ?>)<?= $r['unit']?' — '.htmlspecialchars($r['unit']):'' ?>
                </option>
                <?php endif; endforeach; ?>
            </select>
        </div>
        <div class="form-row-2">
            <div class="ff"><label>Unit *</label><input type="text" name="bill_unit" id="billUnit" placeholder="A-201" required></div>
            <div class="ff"><label>Amount (₹) *</label><input type="number" name="bill_amount" placeholder="2500" min="1" step="0.01" required></div>
        </div>
        <div class="ff"><label>Description</label><input type="text" name="bill_desc" value="Monthly Maintenance"></div>
        <div class="form-row-2">
            <div class="ff"><label>Month</label>
                <select name="bill_month">
                    <?php for($i=0;$i<12;$i++){$d=new DateTime("first day of -$i month");echo "<option value='{$d->format('F Y')}' ".($i===0?'selected':'').">{$d->format('F Y')}</option>";} ?>
                </select>
            </div>
            <div class="ff"><label>Due Date *</label><input type="date" name="bill_due" value="<?= date('Y-m-d',strtotime('+15 days')) ?>" required></div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('genBillModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Generate Bill</button>
        </div>
    </form>
</div>
</div>

<!-- ══ GENERATE BILLS FOR ALL MODAL ══ -->
<div class="modal-overlay" id="bulkBillModal">
<div class="modal">
    <h3><i class="fa fa-layer-group" style="color:var(--green)"></i> Generate Bills for All</h3>
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:14px;">Creates one bill for every active member in this society (residents, admin, staff, etc.). Skips anyone already billed for the selected month + description.</p>
    <form method="POST">
        <input type="hidden" name="gen_bulk_bills" value="1">
        <div class="ff"><label>Description</label><input type="text" name="bulk_desc" value="Monthly Maintenance"></div>
        <div class="form-row-2">
            <div class="ff"><label>Amount (₹) *</label><input type="number" name="bulk_amount" min="1" step="0.01" required></div>
            <div class="ff"><label>Month</label>
                <select name="bulk_month">
                    <?php for($i=0;$i<12;$i++){$d=new DateTime("first day of -$i month");echo "<option value='{$d->format('F Y')}' ".($i===0?'selected':'').">{$d->format('F Y')}</option>";} ?>
                </select>
            </div>
        </div>
        <div class="ff"><label>Due Date *</label><input type="date" name="bulk_due" value="<?= date('Y-m-d',strtotime('+15 days')) ?>" required></div>
        <div class="modal-footer">
            <button type="button" class="btn-cancel" onclick="closeModal('bulkBillModal')">Cancel</button>
            <button type="submit" class="btn-save"><i class="fa fa-check"></i> Generate for Everyone</button>
        </div>
    </form>
</div>
</div>

<!-- ══ PAYMENT PROOF VIEWER MODAL ══ -->
<div class="modal-overlay" id="proofModal">
  <div class="modal" style="max-width:540px;">
    <h3><i class="fa fa-receipt" style="color:var(--green)"></i> Payment Proof</h3>
    <p style="font-size:.8rem;color:var(--muted);margin-bottom:14px;">Review the screenshot uploaded by the resident, then verify or reject.</p>

    <img id="proofImg" src="" alt="Payment screenshot" style="width:100%;max-height:420px;object-fit:contain;border-radius:10px;border:1px solid var(--border);background:var(--bg);margin-bottom:14px;">

    <div style="background:var(--bg);border:1px solid var(--border);border-radius:9px;padding:10px 14px;margin-bottom:16px;">
      <div style="display:flex;justify-content:space-between;font-size:.83rem;padding:6px 0;border-bottom:1px solid var(--border);"><span style="color:var(--muted)">Resident</span><span id="proofResident" style="font-weight:600;color:var(--text)"></span></div>
      <div style="display:flex;justify-content:space-between;font-size:.83rem;padding:6px 0;border-bottom:1px solid var(--border);"><span style="color:var(--muted)">Bill</span><span id="proofDesc" style="font-weight:600;color:var(--text)"></span></div>
      <div style="display:flex;justify-content:space-between;font-size:.83rem;padding:6px 0;border-bottom:1px solid var(--border);"><span style="color:var(--muted)">Amount</span><span id="proofAmount" style="font-weight:600;color:var(--text)"></span></div>
      <div style="display:flex;justify-content:space-between;font-size:.83rem;padding:6px 0;border-bottom:1px solid var(--border);" id="proofTxnRow"><span style="color:var(--muted)">Txn / UTR Ref</span><span id="proofTxn" style="font-weight:600;color:var(--text)"></span></div>
      <div style="display:flex;justify-content:space-between;font-size:.83rem;padding:6px 0;"><span style="color:var(--muted)">Status</span><span id="proofStatus" style="font-weight:600;color:var(--text)"></span></div>
    </div>

    <div class="modal-footer">
      <button type="button" class="btn-cancel" onclick="closeModal('proofModal')">Close</button>
      <form method="POST" id="rejectProofForm" style="display:inline">
        <input type="hidden" name="reject_payment" value="1">
        <input type="hidden" name="bill_id" id="rejectProofBillId" value="">
        <button type="submit" class="btn-cancel" style="color:#dc2626;border-color:#fecaca" onclick="return confirm('Reject this payment proof? The resident will need to re-submit.')">
          <i class="fa fa-circle-xmark"></i> Reject
        </button>
      </form>
      <form method="POST" id="verifyProofForm" style="display:inline">
        <input type="hidden" name="verify_payment" value="1">
        <input type="hidden" name="bill_id" id="verifyProofBillId" value="">
        <button type="submit" class="btn-save">
          <i class="fa fa-circle-check"></i> Verify &amp; Mark Paid
        </button>
      </form>
    </div>
  </div>
</div>



<script>
// Tabs
function switchTab(id,btn){
    document.querySelectorAll('.tab-section').forEach(s=>s.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.getElementById('tab-'+id).classList.add('active');
    if(btn) btn.classList.add('active');
}

// Modals
function openModal(id){document.getElementById(id).classList.add('open');}
function closeModal(id){document.getElementById(id).classList.remove('open');}
document.querySelectorAll('.modal-overlay').forEach(m=>m.addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');}));

// Flat filter
function filterFlats(){
    const q   = document.getElementById('flatSearch').value.toLowerCase();
    const tw  = document.getElementById('filterTower').value.toLowerCase();
    const st  = document.getElementById('filterStatus').value.toLowerCase();
    const cards = document.querySelectorAll('.flat-card');
    let visible = 0;
    cards.forEach(c=>{
        const fn = c.dataset.flat||'';
        const ft = c.dataset.tower||'';
        const fs = c.dataset.status||'';
        let show = true;
        if(q  && !fn.includes(q) && !ft.includes(q)) show=false;
        if(tw && ft !== tw) show=false;
        if(st && fs !== st) show=false;
        c.style.display=show?'':'none';
        if(show) visible++;
    });
    const el=document.getElementById('flatCount');
    if(el) el.textContent=visible+' of '+cards.length;
}

// Resident filter
function filterResidents(){
    const q    = (document.getElementById('resSearch')?.value||'').toLowerCase();
    const role = (document.getElementById('resRoleFilter')?.value||'').toLowerCase();
    const rows = document.querySelectorAll('#resTable tbody tr');
    let visible = 0;
    rows.forEach(r=>{
        const name  = r.dataset.name  || '';
        const email = r.dataset.email || '';
        const rl    = r.dataset.role  || '';
        let show = true;
        if(q    && !name.includes(q) && !email.includes(q)) show = false;
        if(role && rl !== role) show = false;
        r.style.display = show ? '' : 'none';
        if(show) visible++;
    });
    const el = document.getElementById('resCount');
    if(el) el.textContent = visible + ' residents';
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

// Bill filter
function filterBills(){
    const q  = (document.getElementById('billSearch')?.value||'').toLowerCase();
    const st = (document.getElementById('billStatusFilter')?.value||'').toLowerCase();
    const rows = document.querySelectorAll('#billTable tbody tr');
    let visible = 0;
    rows.forEach(r=>{
        const name = r.dataset.name   || '';
        const unit = r.dataset.unit   || '';
        const rs   = r.dataset.status || '';
        let show = true;
        if(q  && !name.includes(q) && !unit.includes(q)) show = false;
        if(st && rs !== st) show = false;
        r.style.display = show ? '' : 'none';
        if(show) visible++;
    });
    const el = document.getElementById('billCount');
    if(el) el.textContent = visible + ' bills';
}

function fillBillUnit(sel){
    const u = sel.options[sel.selectedIndex].dataset.unit;
    const f = document.getElementById('billUnit');
    if(f && u) f.value = u;
}

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

  const verifyForm = document.getElementById('verifyProofForm');
  const rejectForm = document.getElementById('rejectProofForm');
  if(status === 'paid'){
    verifyForm.style.display = 'none';
    rejectForm.style.display = 'none';
  } else {
    verifyForm.style.display = 'inline';
    rejectForm.style.display = 'inline';
  }

  openModal('proofModal');
}

function openSocMobNav(){document.getElementById('socMobNav').classList.add('open');document.body.style.overflow='hidden';}
function closeSocMobNav(){document.getElementById('socMobNav').classList.remove('open');document.body.style.overflow='';}
function closeSocMobNavBg(e){if(e.target===document.getElementById('socMobNav'))closeSocMobNav();}

</script>
</body>
</html>
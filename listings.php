<?php
session_start();
require_once __DIR__ . '/notify_helper.php';

define('DB_HOST','localhost'); define('DB_NAME','cc'); define('DB_USER','root'); define('DB_PASS','');
$msg = $err = '';
$openTab = '';        // which tab to auto-open after a POST (e.g. back to profile/mine after saving)
$editErrorId = 0;     // listing id whose edit modal should reopen after a validation error

$logged_in   = isset($_SESSION['user_id']);
$user_id     = $_SESSION['user_id'] ?? 0;
$user_role   = $_SESSION['user_role'] ?? '';
$society_id  = $_SESSION['user_society_id'] ?? 0;
$user_name   = $_SESSION['user_name'] ?? '';

// A notification link (e.g. "?tab=mine") lands here and auto-opens My Listings
if ($logged_in && ($_GET['tab'] ?? '') === 'mine') {
    $openTab = 'mine';
}

// Roles allowed to post a listing (buyers too — they may be reselling a flat they own).
// If the user has no society in their session (typical for buyers), the form asks them
// to pick the society the flat is located in.
$can_post = $logged_in && in_array($user_role, ['resident','society_member','admin','buyer']);

// Login link that returns the person to this exact page (with filters) afterward
$loginUrl = '/shivam/login.php?redirect=' . urlencode('/shivam/listings.php' . (isset($_SERVER['QUERY_STRING']) && $_SERVER['QUERY_STRING'] ? '?'.$_SERVER['QUERY_STRING'] : ''));

// Masks a phone number for display when the owner hasn't consented to show it publicly
// e.g. "8077123456" -> "807712XXX"
function mask_phone($phone) {
    $digits = preg_replace('/\D/', '', $phone);
    if (strlen($digits) <= 6) return str_repeat('X', strlen($digits));
    return substr($digits, 0, 6) . 'XXX';
}

// Human-friendly label, icon, and one-line description per role (used by the profile card)
$role_meta = [
    'resident'       => ['label' => 'Resident',        'icon' => 'fa-house-user',      'desc' => 'You live in this community and can post sale/rent listings for your own flat.'],
    'society_member' => ['label' => 'Society Member',  'icon' => 'fa-user-tie',        'desc' => 'You are part of the society committee and can post listings for flats you own.'],
    'admin'          => ['label' => 'Administrator',   'icon' => 'fa-user-shield',     'desc' => 'You manage this society and can moderate all of its listings.'],
    'buyer'          => ['label' => 'Buyer',           'icon' => 'fa-basket-shopping', 'desc' => 'You are looking for a home — and you can also list a flat you own for sale or rent.'],
];

// Builds 1–2 letter initials from a name, for the avatar circle
function user_initials($name) {
    $parts = preg_split('/\s+/u', trim((string)$name));
    if (!$parts || $parts[0] === '') return '?';
    $ini = mb_strtoupper(mb_substr($parts[0], 0, 1));
    if (count($parts) > 1) $ini .= mb_strtoupper(mb_substr(end($parts), 0, 1));
    return $ini;
}

// ── Filter values (set up front so they always exist, even on DB error) ──
$f_type    = $_GET['type']    ?? '';
$f_society = (int)($_GET['society'] ?? 0);
$f_bhk     = $_GET['bhk']     ?? '';
$f_min     = $_GET['min_price'] ?? '';
$f_max     = $_GET['max_price'] ?? '';
$f_q       = trim($_GET['q'] ?? '');
$f_sort    = $_GET['sort'] ?? 'newest';

$listings = $societies = $my_listings = [];
$seoTitle = 'Flats for Sale & Rent | ColonyCare';
$seoDescription = 'Browse verified flat listings for sale and rent across ColonyCare societies. Connect directly with owners and agents — no brokerage.';
$photosByListing = $photoIdsByListing = $myListingsEdit = [];
$stats_total = $stats_active = $stats_unread = 0;

try {
    $pdo = new PDO(
        "mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4",
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    // ── Ensure the listings table exists ────────────────────────
    // No enforced FOREIGN KEY constraints here — society_id/user_id are validated
    // in app code (from the session) rather than at the DB level, since the
    // exact column types on societies.id / users.id aren't guaranteed to match.
    $pdo->exec("CREATE TABLE IF NOT EXISTS listings (
        id INT AUTO_INCREMENT PRIMARY KEY,
        society_id INT NOT NULL,
        user_id INT NOT NULL,
        listing_type ENUM('sale','rent') NOT NULL,
        unit VARCHAR(50) NOT NULL,
        block VARCHAR(50) DEFAULT NULL,
        bhk VARCHAR(20) DEFAULT NULL,
        area_sqft INT DEFAULT NULL,
        price DECIMAL(12,2) NOT NULL,
        description TEXT,
        contact_name VARCHAR(100) NOT NULL,
        contact_phone VARCHAR(20) NOT NULL,
        status ENUM('active','sold','rented','withdrawn') DEFAULT 'active',
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_society (society_id),
        INDEX idx_user (user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Ensure the listing_photos table exists (multiple photos per listing) ──
    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_photos (
        id INT AUTO_INCREMENT PRIMARY KEY,
        listing_id INT NOT NULL,
        photo VARCHAR(255) NOT NULL,
        sort_order INT DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_listing (listing_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── One-time cleanup: drop old single-photo column if it exists from a previous version ──
    $hasOldPhotoCol = $pdo->query("SHOW COLUMNS FROM listings LIKE 'photo'")->fetch();
    if ($hasOldPhotoCol) {
        $pdo->exec("ALTER TABLE listings DROP COLUMN photo");
    }

    // ── Migration: add show_contact column (public consent flag) if missing ──
    $hasShowContactCol = $pdo->query("SHOW COLUMNS FROM listings LIKE 'show_contact'")->fetch();
    if (!$hasShowContactCol) {
        $pdo->exec("ALTER TABLE listings ADD COLUMN show_contact TINYINT(1) DEFAULT 0");
    }

    // ── Migration: agent contact + which contact(s) to display publicly ──
    $hasAgentNameCol = $pdo->query("SHOW COLUMNS FROM listings LIKE 'agent_name'")->fetch();
    if (!$hasAgentNameCol) {
        $pdo->exec("ALTER TABLE listings ADD COLUMN agent_name VARCHAR(100) DEFAULT NULL");
    }
    $hasAgentPhoneCol = $pdo->query("SHOW COLUMNS FROM listings LIKE 'agent_phone'")->fetch();
    if (!$hasAgentPhoneCol) {
        $pdo->exec("ALTER TABLE listings ADD COLUMN agent_phone VARCHAR(20) DEFAULT NULL");
    }
    $hasContactDisplayCol = $pdo->query("SHOW COLUMNS FROM listings LIKE 'contact_display'")->fetch();
    if (!$hasContactDisplayCol) {
        $pdo->exec("ALTER TABLE listings ADD COLUMN contact_display ENUM('owner','agent','both') DEFAULT 'owner'");
    }

    // ── In-app messages between a buyer and a listing owner ──────
    $pdo->exec("CREATE TABLE IF NOT EXISTS listing_messages (
        id INT AUTO_INCREMENT PRIMARY KEY,
        listing_id INT NOT NULL,
        owner_user_id INT NOT NULL,
        sender_user_id INT NOT NULL,
        sender_name VARCHAR(100) NOT NULL,
        sender_contact VARCHAR(100) NOT NULL,
        message TEXT NOT NULL,
        is_read TINYINT(1) DEFAULT 0,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_listing (listing_id),
        INDEX idx_owner (owner_user_id),
        INDEX idx_sender (sender_user_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    // ── Fetch logged-in user's profile, to auto-fill the listing form and profile card ──
    $profile = ['unit' => '', 'block' => '', 'name' => $user_name, 'phone' => '', 'society_name' => ''];
    if ($logged_in) {
        $pStmt = $pdo->prepare("SELECT unit, block, name, phone FROM users WHERE id=?");
        $pStmt->execute([$user_id]);
        if ($p = $pStmt->fetch()) {
            $profile['unit']  = $p['unit']  ?? '';
            $profile['block'] = $p['block'] ?? '';
            $profile['name']  = $p['name']  ?? $user_name;
            $profile['phone'] = $p['phone'] ?? '';
        }
        // Society name comes from the session's society id (used on the profile card)
        if ($society_id) {
            $sStmt = $pdo->prepare("SELECT society_name FROM societies WHERE id=?");
            $sStmt->execute([$society_id]);
            if ($sRow = $sStmt->fetch()) $profile['society_name'] = $sRow['society_name'];
        }
    }

    // ── Handle POST: create / edit / update-status / withdraw / profile ──
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {

        if (isset($_POST['add_listing'])) {
            if (!$can_post) {
                $err = 'You must be logged in to post a listing.';
            } else {
                $type   = $_POST['listing_type'] ?? '';
                $unit   = trim($_POST['unit'] ?? '');
                $block  = trim($_POST['block'] ?? '');
                $bhk    = trim($_POST['bhk'] ?? '');
                $area   = (int)($_POST['area_sqft'] ?? 0) ?: null;
                $price  = (float)($_POST['price'] ?? 0);
                $desc   = trim($_POST['description'] ?? '');
                $cname  = trim($_POST['contact_name'] ?? '');
                $cphone = trim($_POST['contact_phone'] ?? '');
                $showContactRaw = $_POST['show_contact'] ?? '';
                $agentName  = trim($_POST['agent_name'] ?? '');
                $agentPhone = trim($_POST['agent_phone'] ?? '');
                $contactDisplay = $_POST['contact_display'] ?? 'owner';
                $photoPaths = [];

                // Society comes from the session when set; otherwise (e.g. a buyer) the
                // poster picks the society the flat is located in from a dropdown.
                $listingSocietyId = (int)$society_id;
                if (!$listingSocietyId) {
                    $listingSocietyId = (int)($_POST['society_id'] ?? 0);
                }
                $socOk = false;
                if ($listingSocietyId) {
                    $socChk = $pdo->prepare("SELECT id FROM societies WHERE id=?");
                    $socChk->execute([$listingSocietyId]);
                    $socOk = (bool)$socChk->fetch();
                }

                if (!in_array($type, ['sale','rent']) || !$unit || !$price || !$cname || !$cphone) {
                    $err = 'Please fill in all required fields.';
                } elseif (!$socOk) {
                    $err = 'Please select the society where the flat is located.';
                } elseif (!in_array($showContactRaw, ['1','0'], true)) {
                    $err = 'Please choose whether your contact number can be shown publicly.';
                } elseif ($showContactRaw === '1' && !in_array($contactDisplay, ['owner','agent','both'], true)) {
                    $err = 'Please choose whose contact number to show.';
                } elseif ($showContactRaw === '1' && in_array($contactDisplay, ['agent','both'], true) && (!$agentName || !$agentPhone)) {
                    $err = "Please provide the agent's name and phone number.";
                } elseif (!empty($_FILES['photos']['name'][0])) {
                    // ── Validate & store up to 5 photos ──
                    $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                    $fileCount = count(array_filter($_FILES['photos']['name']));
                    if ($fileCount > 5) {
                        $err = 'You can upload a maximum of 5 photos.';
                    } else {
                        $uploadDir = __DIR__ . '/uploads/listings/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                        foreach ($_FILES['photos']['name'] as $i => $fname) {
                            if (empty($fname)) continue;
                            $tmp   = $_FILES['photos']['tmp_name'][$i];
                            $size  = $_FILES['photos']['size'][$i];
                            $error = $_FILES['photos']['error'][$i];

                            if ($error !== UPLOAD_ERR_OK) { $err = 'One of the photos failed to upload. Please try again.'; break; }
                            $mime = mime_content_type($tmp);
                            if (!isset($allowed[$mime])) { $err = 'Photos must be JPG, PNG, or WEBP images.'; break; }
                            if ($size > 5 * 1024 * 1024) { $err = 'Each photo must be under 5MB.'; break; }

                            $newName = 'listing_' . uniqid() . '.' . $allowed[$mime];
                            if (move_uploaded_file($tmp, $uploadDir . $newName)) {
                                $photoPaths[] = 'uploads/listings/' . $newName;
                            } else {
                                $err = 'Could not save one of the uploaded photos.'; break;
                            }
                        }
                    }
                }

                if (!$err) {
                    $showContact = (int)$showContactRaw;
                    $pdo->prepare("INSERT INTO listings
                        (society_id,user_id,listing_type,unit,block,bhk,area_sqft,price,description,contact_name,contact_phone,show_contact,agent_name,agent_phone,contact_display)
                        VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)")
                        ->execute([$listingSocietyId,$user_id,$type,$unit,$block,$bhk,$area,$price,$desc,$cname,$cphone,$showContact,
                            $agentName ?: null, $agentPhone ?: null, $contactDisplay]);
                    $newListingId = $pdo->lastInsertId();

                    if ($photoPaths) {
                        $photoStmt = $pdo->prepare("INSERT INTO listing_photos (listing_id, photo, sort_order) VALUES (?,?,?)");
                        foreach ($photoPaths as $idx => $ppath) {
                            $photoStmt->execute([$newListingId, $ppath, $idx]);
                        }
                    }
                    $msg = 'Your listing has been posted!';
                }
            }
        }

        // ── Edit an existing listing's details (+ remove/add photos) ──
        if (isset($_POST['update_listing'])) {
            $openTab = 'mine';
            $lid = (int)($_POST['listing_id'] ?? 0);

            // Only the listing owner, or an admin of the same society, can edit
            $chk = $pdo->prepare("SELECT user_id, society_id FROM listings WHERE id=?");
            $chk->execute([$lid]);
            $lEdit = $chk->fetch();
            $canManage = $lEdit && ((int)$lEdit['user_id'] === (int)$user_id ||
                ($user_role === 'admin' && (int)$lEdit['society_id'] === (int)$society_id));

            if (!$logged_in || !$canManage) {
                $err = 'You can only edit your own listings.';
            } else {
                $type   = $_POST['listing_type'] ?? '';
                $unit   = trim($_POST['unit'] ?? '');
                $block  = trim($_POST['block'] ?? '');
                $bhk    = trim($_POST['bhk'] ?? '');
                $area   = (int)($_POST['area_sqft'] ?? 0) ?: null;
                $price  = (float)($_POST['price'] ?? 0);
                $desc   = trim($_POST['description'] ?? '');
                $cname  = trim($_POST['contact_name'] ?? '');
                $cphone = trim($_POST['contact_phone'] ?? '');
                $showContactRaw = $_POST['show_contact'] ?? '';
                $agentName  = trim($_POST['agent_name'] ?? '');
                $agentPhone = trim($_POST['agent_phone'] ?? '');
                $contactDisplay = $_POST['contact_display'] ?? 'owner';
                $delIds = array_filter(array_map('intval', (array)($_POST['delete_photos'] ?? [])));
                $photoPaths = [];

                // Users with a session society keep the listing's existing society;
                // society-less posters (buyers) may correct it via the dropdown.
                $listingSocietyId = (int)$lEdit['society_id'];
                if (!$society_id) {
                    $newSoc = (int)($_POST['society_id'] ?? 0);
                    if ($newSoc) {
                        $socChk = $pdo->prepare("SELECT id FROM societies WHERE id=?");
                        $socChk->execute([$newSoc]);
                        if ($socChk->fetch()) $listingSocietyId = $newSoc;
                    }
                }

                if (!in_array($type, ['sale','rent']) || !$unit || !$price || !$cname || !$cphone) {
                    $err = 'Please fill in all required fields.';
                } elseif (!in_array($showContactRaw, ['1','0'], true)) {
                    $err = 'Please choose whether your contact number can be shown publicly.';
                } elseif ($showContactRaw === '1' && !in_array($contactDisplay, ['owner','agent','both'], true)) {
                    $err = 'Please choose whose contact number to show.';
                } elseif ($showContactRaw === '1' && in_array($contactDisplay, ['agent','both'], true) && (!$agentName || !$agentPhone)) {
                    $err = "Please provide the agent's name and phone number.";
                } else {
                    // Existing photo count, so new uploads can't push the total past 5
                    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM listing_photos WHERE listing_id=?");
                    $cntStmt->execute([$lid]);
                    $existingCount = (int)$cntStmt->fetchColumn();

                    // Photos the user ticked for removal — verified to actually belong to THIS listing
                    $validDel = [];
                    if ($delIds) {
                        $ph = implode(',', array_fill(0, count($delIds), '?'));
                        $dq = $pdo->prepare("SELECT id, photo FROM listing_photos WHERE listing_id=? AND id IN ($ph)");
                        $dq->execute(array_merge([$lid], array_values($delIds)));
                        foreach ($dq->fetchAll() as $dr) $validDel[$dr['id']] = $dr['photo'];
                    }

                    $newCount = count(array_filter($_FILES['new_photos']['name'] ?? []));
                    if ($existingCount - count($validDel) + $newCount > 5) {
                        $err = 'A listing can have at most 5 photos in total.';
                    } elseif ($newCount) {
                        // ── Validate & store the newly added photos ──
                        $allowed  = ['image/jpeg' => 'jpg', 'image/png' => 'png', 'image/webp' => 'webp'];
                        $uploadDir = __DIR__ . '/uploads/listings/';
                        if (!is_dir($uploadDir)) mkdir($uploadDir, 0755, true);

                        foreach ($_FILES['new_photos']['name'] as $i => $fname) {
                            if (empty($fname)) continue;
                            $tmp   = $_FILES['new_photos']['tmp_name'][$i];
                            $size  = $_FILES['new_photos']['size'][$i];
                            $error = $_FILES['new_photos']['error'][$i];

                            if ($error !== UPLOAD_ERR_OK) { $err = 'One of the photos failed to upload. Please try again.'; break; }
                            $mime = mime_content_type($tmp);
                            if (!isset($allowed[$mime])) { $err = 'Photos must be JPG, PNG, or WEBP images.'; break; }
                            if ($size > 5 * 1024 * 1024) { $err = 'Each photo must be under 5MB.'; break; }

                            $newName = 'listing_' . uniqid() . '.' . $allowed[$mime];
                            if (move_uploaded_file($tmp, $uploadDir . $newName)) {
                                $photoPaths[] = 'uploads/listings/' . $newName;
                            } else {
                                $err = 'Could not save one of the uploaded photos.'; break;
                            }
                        }
                    }

                    if (!$err) {
                        // Remove ticked photos: delete the files from disk, then the DB rows
                        if ($validDel) {
                            foreach ($validDel as $dPath) {
                                $filePath = __DIR__ . '/' . $dPath;
                                if (is_file($filePath)) { @unlink($filePath); }
                            }
                            $ph = implode(',', array_fill(0, count($validDel), '?'));
                            $pdo->prepare("DELETE FROM listing_photos WHERE listing_id=? AND id IN ($ph)")
                                ->execute(array_merge([$lid], array_keys($validDel)));
                        }

                        // Append newly uploaded photos after the remaining ones
                        if ($photoPaths) {
                            $sortStmt = $pdo->prepare("SELECT COALESCE(MAX(sort_order), -1) FROM listing_photos WHERE listing_id=?");
                            $sortStmt->execute([$lid]);
                            $nextSort = (int)$sortStmt->fetchColumn() + 1;
                            $photoStmt = $pdo->prepare("INSERT INTO listing_photos (listing_id, photo, sort_order) VALUES (?,?,?)");
                            foreach ($photoPaths as $ppath) {
                                $photoStmt->execute([$lid, $ppath, $nextSort++]);
                            }
                        }

                        // Update the listing row itself
                        $pdo->prepare("UPDATE listings SET
                            society_id=?, listing_type=?, unit=?, block=?, bhk=?, area_sqft=?, price=?,
                            description=?, contact_name=?, contact_phone=?, show_contact=?,
                            agent_name=?, agent_phone=?, contact_display=?
                            WHERE id=?")
                            ->execute([$listingSocietyId,$type,$unit,$block,$bhk,$area,$price,$desc,$cname,$cphone,(int)$showContactRaw,
                                $agentName ?: null, $agentPhone ?: null, $contactDisplay, $lid]);
                        $msg = 'Listing updated.';
                    }
                }

                // Reopen this listing's edit modal if validation failed
                if ($err) $editErrorId = $lid;
            }
        }

        if (isset($_POST['update_status'])) {
            $openTab = 'mine';
            $lid       = (int)$_POST['listing_id'];
            $newStatus = $_POST['new_status'] ?? '';
            if ($logged_in && in_array($newStatus, ['active','sold','rented','withdrawn'])) {
                // Only the listing owner, or an admin of the same society, can change status
                $chk = $pdo->prepare("SELECT user_id, society_id FROM listings WHERE id=?");
                $chk->execute([$lid]);
                $row = $chk->fetch();
                if ($row && ((int)$row['user_id'] === (int)$user_id ||
                    ($user_role === 'admin' && (int)$row['society_id'] === (int)$society_id))) {
                    $pdo->prepare("UPDATE listings SET status=? WHERE id=?")->execute([$newStatus, $lid]);
                    $msg = 'Listing updated.';
                } else {
                    $err = 'You can only manage your own listings.';
                }
            }
        }

        if (isset($_POST['delete_listing'])) {
            $openTab = 'mine';
            $lid = (int)$_POST['listing_id'];
            if ($logged_in) {
                // Only the listing owner, or an admin of the same society, can delete
                $chk = $pdo->prepare("SELECT user_id, society_id FROM listings WHERE id=?");
                $chk->execute([$lid]);
                $row = $chk->fetch();
                if ($row && ((int)$row['user_id'] === (int)$user_id ||
                    ($user_role === 'admin' && (int)$row['society_id'] === (int)$society_id))) {

                    // Remove uploaded photo files from disk before deleting DB rows
                    $photoRows = $pdo->prepare("SELECT photo FROM listing_photos WHERE listing_id=?");
                    $photoRows->execute([$lid]);
                    foreach ($photoRows->fetchAll() as $pr) {
                        $filePath = __DIR__ . '/' . $pr['photo'];
                        if (is_file($filePath)) { @unlink($filePath); }
                    }

                    $pdo->prepare("DELETE FROM listing_photos WHERE listing_id=?")->execute([$lid]);
                    $pdo->prepare("DELETE FROM listing_messages WHERE listing_id=?")->execute([$lid]);
                    $pdo->prepare("DELETE FROM listings WHERE id=?")->execute([$lid]);
                    $msg = 'Listing deleted.';
                } else {
                    $err = 'You can only delete your own listings.';
                }
            }
        }

        // ── Update own profile details (phone / unit / block) from the profile tab ──
        if (isset($_POST['update_profile'])) {
            $openTab = 'profile';   // return to the profile tab after saving
            if (!$logged_in) {
                $err = 'Please log in to update your profile.';
            } else {
                $newPhone = trim($_POST['profile_phone'] ?? '');
                $newUnit  = trim($_POST['profile_unit'] ?? '');
                $newBlock = trim($_POST['profile_block'] ?? '');

                $digits = preg_replace('/\D/', '', $newPhone);
                if ($newPhone === '' || strlen($digits) < 10 || strlen($digits) > 13) {
                    $err = 'Please enter a valid phone number (10 digits).';
                } else {
                    $pdo->prepare("UPDATE users SET phone=?, unit=?, block=? WHERE id=?")
                        ->execute([$newPhone, $newUnit, $newBlock, $user_id]);
                    $profile['phone'] = $newPhone;
                    $profile['unit']  = $newUnit;
                    $profile['block'] = $newBlock;
                    $msg = 'Profile updated.';
                }
            }
        }

        if (isset($_POST['send_message'])) {
            $lid     = (int)$_POST['listing_id'];
            $mtext   = trim($_POST['message_text'] ?? '');

            if (!$logged_in) {
                $err = 'Please log in to message the owner.';
            } elseif (!$mtext) {
                $err = 'Please enter a message.';
            } else {
                $lChk = $pdo->prepare("SELECT user_id, unit, block FROM listings WHERE id=?");
                $lChk->execute([$lid]);
                $lrow = $lChk->fetch();

                if (!$lrow) {
                    $err = 'Listing not found.';
                } elseif ((int)$lrow['user_id'] === (int)$user_id) {
                    $err = "You can't message yourself about your own listing.";
                } else {
                    $senderContact = $profile['phone'] ?: $_SESSION['user_email'] ?? 'Not provided';
                    $pdo->prepare("INSERT INTO listing_messages (listing_id, owner_user_id, sender_user_id, sender_name, sender_contact, message) VALUES (?,?,?,?,?,?)")
                        ->execute([$lid, $lrow['user_id'], $user_id, $user_name, $senderContact, $mtext]);
                    $msg = 'Your message has been sent to the owner!';
                }
            }
        }

        if (isset($_POST['mark_messages_read'])) {
            $lid = (int)$_POST['listing_id'];
            if ($logged_in) {
                $pdo->prepare("UPDATE listing_messages SET is_read=1 WHERE listing_id=? AND owner_user_id=?")
                    ->execute([$lid, $user_id]);
            }
        }
    }

    // ── Fetch filter dropdown data ───────────────────────────────
    $societies = $pdo->query("SELECT id, society_name FROM societies ORDER BY society_name")->fetchAll();

    // ── Build filtered listings query ────────────────────────────
    $where  = ["l.status = 'active'"];
    $params = [];

    if (in_array($f_type, ['sale','rent'])) { $where[] = 'l.listing_type = ?'; $params[] = $f_type; }
    if ($f_society)                          { $where[] = 'l.society_id = ?';  $params[] = $f_society; }
    if ($f_bhk !== '')                       { $where[] = 'l.bhk = ?';         $params[] = $f_bhk; }
    if ($f_min !== '')                       { $where[] = 'l.price >= ?';      $params[] = (float)$f_min; }
    if ($f_max !== '')                       { $where[] = 'l.price <= ?';      $params[] = (float)$f_max; }
    if ($f_q !== '')                         { $where[] = '(l.unit LIKE ? OR l.description LIKE ?)'; $params[] = "%$f_q%"; $params[] = "%$f_q%"; }

    $sortOptions = [
        'newest'     => 'l.created_at DESC',
        'oldest'     => 'l.created_at ASC',
        'price_low'  => 'l.price ASC',
        'price_high' => 'l.price DESC',
    ];
    $orderBy = $sortOptions[$f_sort] ?? $sortOptions['newest'];

    $whereSql = implode(' AND ', $where);
    $stmt = $pdo->prepare("
        SELECT l.*, s.society_name
        FROM listings l
        JOIN societies s ON s.id = l.society_id
        WHERE $whereSql
        ORDER BY $orderBy
    ");
    $stmt->execute($params);
    $listings = $stmt->fetchAll();

    // ── SEO: dynamic title/description reflecting the active filters ──
    $seoSocietyName = '';
    foreach ($societies as $s) {
        if ($f_society && (int)$s['id'] === $f_society) { $seoSocietyName = $s['society_name']; break; }
    }
    $seoTypeLabel = $f_type === 'sale' ? 'for Sale' : ($f_type === 'rent' ? 'for Rent' : 'for Sale & Rent');
    $seoBhkLabel  = $f_bhk !== '' ? $f_bhk . ' BHK ' : '';
    $seoTitle = trim($seoBhkLabel . 'Flats ' . $seoTypeLabel . ($seoSocietyName ? ' in ' . $seoSocietyName : '')) . ' | ColonyCare';
    $seoDescription = 'Browse ' . count($listings) . ' verified ' . strtolower($seoBhkLabel . 'flat ' . $seoTypeLabel)
        . ($seoSocietyName ? ' in ' . $seoSocietyName : ' across ColonyCare societies')
        . '. Connect directly with owners and agents — no brokerage.';

    // ── My own listings (if logged in) ───────────────────────────
    if ($logged_in) {
        $mine = $pdo->prepare("SELECT l.*, s.society_name FROM listings l JOIN societies s ON s.id=l.society_id WHERE l.user_id=? ORDER BY l.created_at DESC");
        $mine->execute([$user_id]);
        $my_listings = $mine->fetchAll();
    }

    // ── Attach photos to each listing (browse + mine) ────────────
    $allIds = array_merge(array_column($listings, 'id'), array_column($my_listings, 'id'));
    if ($allIds) {
        $uniqueIds = array_unique($allIds);
        $placeholders = implode(',', array_fill(0, count($uniqueIds), '?'));
        $pStmt2 = $pdo->prepare("SELECT id, listing_id, photo FROM listing_photos WHERE listing_id IN ($placeholders) ORDER BY sort_order ASC, id ASC");
        $pStmt2->execute(array_values($uniqueIds));
        foreach ($pStmt2->fetchAll() as $row) {
            $photosByListing[$row['listing_id']][] = $row['photo'];
            $photoIdsByListing[$row['listing_id']][$row['id']] = $row['photo'];   // id → path, used by the edit modal
        }
    }
    foreach ($listings as &$l)    { $l['photos'] = $photosByListing[$l['id']] ?? []; }
    foreach ($my_listings as &$l) { $l['photos'] = $photosByListing[$l['id']] ?? []; }
    unset($l);

    // ── Attach received messages to each of my own listings ──────
    if ($logged_in && $my_listings) {
        $msgStmt = $pdo->prepare("SELECT * FROM listing_messages WHERE listing_id=? ORDER BY created_at DESC");
        foreach ($my_listings as &$l) {
            $msgStmt->execute([$l['id']]);
            $l['messages'] = $msgStmt->fetchAll();
            $l['unread_count'] = count(array_filter($l['messages'], fn($m) => !$m['is_read']));
        }
        unset($l);
    }

    // ── Stats for the profile card ───────────────────────────────
    if ($logged_in) {
        $stats_total  = count($my_listings);
        $stats_active = count(array_filter($my_listings, fn($x) => $x['status'] === 'active'));
        $stats_unread = array_sum(array_map(fn($x) => $x['unread_count'] ?? 0, $my_listings));
    }

    // ── JSON data for the edit-listing modal (fields + photos, keyed by listing id) ──
    if ($logged_in) {
        foreach ($my_listings as $ml) {
            $photosArr = [];
            foreach (($photoIdsByListing[$ml['id']] ?? []) as $pid => $ppath) {
                $photosArr[] = ['id' => (int)$pid, 'path' => $ppath];
            }
            $myListingsEdit[$ml['id']] = [
                'listing_type'  => $ml['listing_type'],
                'society_id'    => (int)$ml['society_id'],
                'price'         => (float)$ml['price'],
                'unit'          => $ml['unit'],
                'block'         => $ml['block'] ?? '',
                'bhk'           => $ml['bhk'] ?? '',
                'area_sqft'     => $ml['area_sqft'] ?? '',
                'description'   => $ml['description'] ?? '',
                'contact_name'  => $ml['contact_name'],
                'contact_phone' => $ml['contact_phone'],
                'show_contact'  => (int)($ml['show_contact'] ?? 0),
                'agent_name'      => $ml['agent_name'] ?? '',
                'agent_phone'     => $ml['agent_phone'] ?? '',
                'contact_display' => $ml['contact_display'] ?? 'owner',
                'photos'        => $photosArr,
            ];
        }
    }

} catch (PDOException $e) {
    $err = 'Database error: ' . $e->getMessage();
}

// ── Data for the profile card (safe defaults even if the DB failed above) ──
$roleMeta     = $role_meta[$user_role] ?? ['label' => ucfirst($user_role ?: 'Member'), 'icon' => 'fa-user', 'desc' => ''];
$profileEmail = $_SESSION['user_email'] ?? '';
$profile      = $profile ?? ['unit' => '', 'block' => '', 'name' => $user_name, 'phone' => '', 'society_name' => ''];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($seoTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($seoDescription) ?>">
<meta name="robots" content="index, follow">
<link rel="canonical" href="https://www.example.com/shivam/listings.php<?= ($f_type||$f_society||$f_bhk||$f_min||$f_max||$f_q) ? '?'.htmlspecialchars($_SERVER['QUERY_STRING'] ?? '') : '' ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($seoTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($seoDescription) ?>">
<meta property="og:url" content="https://www.example.com/shivam/listings.php">
<meta property="og:site_name" content="ColonyCare">
<?php if (!empty($listings) && !empty($listings[0]['photos'][0])): ?>
<meta property="og:image" content="https://www.example.com/shivam/<?= htmlspecialchars($listings[0]['photos'][0]) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">

<?php if (!empty($listings)): ?>
<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'ItemList',
    'itemListElement' => array_map(function($l, $i) {
        return [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'item' => [
                '@type' => 'Product',
                'name' => ($l['bhk'] ? $l['bhk'].' BHK ' : '') . ucfirst($l['listing_type']) . ' - ' . $l['society_name'],
                'description' => mb_substr(strip_tags($l['description'] ?? ''), 0, 200),
                'offers' => [
                    '@type' => 'Offer',
                    'price' => (string)$l['price'],
                    'priceCurrency' => 'INR',
                    'availability' => 'https://schema.org/InStock',
                ],
            ],
        ];
    }, $listings, array_keys($listings)),
], JSON_UNESCAPED_SLASHES) ?>
</script>
<?php endif; ?>

<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&family=DM+Serif+Display&display=swap" rel="stylesheet">
<style>
:root{
  --green:#0f8f6f;--green-dark:#1a5c3a;--green-btn:#2d7a52;--green-hover:#145f3f;
  --green-light:#e8f5ee;--green-soft:#f0f9f4;
  --text-primary:#1a2e22;--text-sub:#5a7060;--text-muted:#8fa898;
  --border:#e5ece8;--bg:#f5f7f6;--white:#fff;
  --radius:14px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text-primary);}

/* ── TOP BAR ── */
.topbar{background:linear-gradient(135deg,#0f8f6f 0%,#2d7a60 60%,#3a9467 100%);padding:16px 32px;display:flex;align-items:center;gap:16px;color:#fff;flex-wrap:wrap;}
.tb-brand{display:flex;align-items:center;gap:9px;font-weight:700;font-size:1.05rem;}
.tb-right{margin-left:auto;display:flex;gap:10px;align-items:center;}
.tb-btn{padding:8px 16px;border-radius:9px;font-size:.85rem;font-weight:600;text-decoration:none;color:#fff;background:rgba(255,255,255,.12);transition:background .2s;display:inline-flex;align-items:center;gap:6px;}
.tb-btn:hover{background:rgba(255,255,255,.22);}
.tb-btn-primary{background:#fff;color:var(--green-dark);}
.tb-btn-primary:hover{background:#f0fdf4;}

/* ── TOPBAR USER CHIP (logged-in profile shortcut) ── */
.tb-user{display:flex;align-items:center;gap:10px;background:rgba(255,255,255,.12);border:2px solid transparent;border-radius:12px;padding:5px 14px 5px 5px;cursor:pointer;color:#fff;font-family:inherit;transition:background .2s;}
.tb-user:hover{background:rgba(255,255,255,.22);}
.tb-avatar{width:34px;height:34px;border-radius:50%;background:#fff;color:var(--green-dark);font-weight:700;font-size:.78rem;display:flex;align-items:center;justify-content:center;flex-shrink:0;}
.tb-user-meta{display:flex;flex-direction:column;align-items:flex-start;line-height:1.2;}
.tb-user-name{font-size:.85rem;font-weight:700;max-width:140px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;}
.tb-user-role{font-size:.68rem;opacity:.85;}

/* ── HERO ── */
.hero{background:linear-gradient(135deg,#0f8f6f 0%,#2d7a60 60%,#3a9467 100%);color:#fff;padding:44px 32px 60px;text-align:center;}
.hero h1{font-family:'DM Serif Display',serif;font-weight:400;font-size:2.1rem;margin-bottom:10px;}
.hero p{opacity:.9;font-size:.98rem;max-width:560px;margin:0 auto;}

/* ── CONTAINER / FILTERS ── */
.wrap{max-width:1180px;margin:-32px auto 60px;padding:0 24px;}
.filters{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);box-shadow:0 8px 32px rgba(15,80,60,.1);padding:20px 22px;margin-bottom:28px;}
.filters-row{display:flex;flex-wrap:wrap;gap:12px;align-items:flex-end;}
.f-field{display:flex;flex-direction:column;gap:6px;min-width:140px;flex:1;}
.f-field label{font-size:.74rem;font-weight:700;color:var(--text-sub);text-transform:uppercase;letter-spacing:.03em;}
.f-field input,.f-field select{font-family:inherit;font-size:.87rem;border:1.5px solid var(--border);border-radius:9px;padding:9px 12px;outline:none;background:#fff;color:var(--text-primary);transition:border-color .2s;}
.f-field input:focus,.f-field select:focus{border-color:var(--green);}
.f-search{flex:2;min-width:200px;}
.btn-filter{background:var(--green-btn);color:#fff;border:none;border-radius:9px;padding:10px 22px;font-family:inherit;font-weight:600;font-size:.87rem;cursor:pointer;transition:background .2s;white-space:nowrap;}
.btn-filter:hover{background:var(--green-hover);}
.btn-clear{background:none;border:1.5px solid var(--border);border-radius:9px;padding:10px 16px;font-family:inherit;font-size:.85rem;color:var(--text-muted);cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;}

/* ── TABS (browse / my listings / profile) ── */
.tabs{display:flex;gap:8px;margin-bottom:20px;flex-wrap:wrap;}
.tab-btn{background:var(--white);border:1.5px solid var(--border);border-radius:9px;padding:9px 18px;font-family:inherit;font-size:.85rem;font-weight:600;color:var(--text-sub);cursor:pointer;transition:all .2s;display:inline-flex;align-items:center;gap:7px;}
.tab-btn.active{background:var(--green-btn);color:#fff;border-color:var(--green-btn);}
.tab-panel{display:none;}
.tab-panel.active{display:block;}

/* ── ALERTS ── */
.alert{padding:12px 16px;border-radius:10px;font-size:.87rem;margin-bottom:20px;display:flex;align-items:center;gap:10px;border:1px solid;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.alert-success{background:#f0fdf4;color:#0f8f6f;border-color:#bbf7d0;}

/* ── PROFILE CARD ── */
.profile-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;max-width:760px;}
.pc-header{background:linear-gradient(135deg,#0f8f6f 0%,#2d7a60 60%,#3a9467 100%);padding:28px;display:flex;gap:20px;align-items:center;color:#fff;flex-wrap:wrap;}
.pc-avatar{width:72px;height:72px;border-radius:50%;background:rgba(255,255,255,.18);border:2px solid rgba(255,255,255,.55);display:flex;align-items:center;justify-content:center;font-size:1.45rem;font-weight:700;flex-shrink:0;}
.pc-head-text h2{font-size:1.3rem;font-weight:700;margin-bottom:7px;}
.pc-role-badge{display:inline-flex;align-items:center;gap:7px;background:rgba(255,255,255,.18);padding:4px 13px;border-radius:99px;font-size:.72rem;font-weight:700;text-transform:uppercase;letter-spacing:.05em;}
.pc-role-desc{margin-top:9px;font-size:.82rem;opacity:.92;max-width:440px;line-height:1.5;}
.pc-stats{display:flex;border-bottom:1px solid var(--border);}
.pc-stat{flex:1;padding:16px 10px;text-align:center;border-right:1px solid var(--border);}
.pc-stat:last-child{border-right:none;}
.pc-stat-num{font-size:1.3rem;font-weight:700;color:var(--green-dark);}
.pc-stat-label{font-size:.7rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;font-weight:600;margin-top:2px;}
.pc-body{padding:22px 24px;display:grid;grid-template-columns:1fr 1fr;gap:18px 24px;}
.pc-detail{display:flex;gap:12px;align-items:flex-start;}
.pc-detail>i{width:34px;height:34px;border-radius:9px;background:var(--green-light);color:var(--green);display:flex;align-items:center;justify-content:center;font-size:.85rem;flex-shrink:0;}
.pc-label{display:block;font-size:.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.05em;}
.pc-value{display:block;font-size:.9rem;font-weight:600;color:var(--text-primary);margin-top:2px;word-break:break-word;}
.pc-edit{margin:0 24px 24px;border:1px solid var(--border);border-radius:10px;overflow:hidden;}
.pc-edit summary{list-style:none;cursor:pointer;padding:13px 16px;font-size:.85rem;font-weight:700;color:var(--green-btn);display:flex;align-items:center;gap:8px;user-select:none;transition:background .2s;}
.pc-edit summary::-webkit-details-marker{display:none;}
.pc-edit summary:hover{background:var(--green-soft);}
.pc-edit form{padding:4px 16px 16px;}
.pc-edit-note{font-size:.76rem;color:var(--text-muted);margin-bottom:12px;line-height:1.5;}
.btn-save-profile{background:var(--green-btn);color:#fff;border:none;border-radius:9px;padding:10px 20px;font-family:inherit;font-weight:600;font-size:.85rem;cursor:pointer;display:inline-flex;align-items:center;gap:7px;transition:background .2s;}
.btn-save-profile:hover{background:var(--green-hover);}

/* ── LISTING GRID ── */
.listing-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:18px;}
.listing-card{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);overflow:hidden;transition:box-shadow .2s,transform .15s;}
.listing-card:hover{box-shadow:0 8px 24px rgba(15,80,60,.12);transform:translateY(-2px);}
.lc-photo{width:100%;height:160px;background:var(--green-soft);overflow:hidden;position:relative;}
.lc-photo img{width:100%;height:100%;object-fit:cover;display:block;}
.lc-photo-placeholder{display:flex;align-items:center;justify-content:center;color:var(--border);font-size:2.2rem;}
.lc-photo-count{position:absolute;bottom:8px;right:8px;background:rgba(0,0,0,.6);color:#fff;font-size:.7rem;font-weight:700;padding:3px 9px;border-radius:99px;display:flex;align-items:center;gap:4px;}
.lc-head{padding:16px 18px 0;display:flex;justify-content:space-between;align-items:flex-start;}
.lc-badge{font-size:.68rem;font-weight:700;padding:4px 10px;border-radius:99px;text-transform:uppercase;letter-spacing:.03em;}
.lc-badge.sale{background:#dbeafe;color:#1d4ed8;}
.lc-badge.rent{background:#dcfce7;color:#15803d;}
.lc-price{font-size:1.15rem;font-weight:700;color:var(--text-primary);}
.lc-body{padding:12px 18px 16px;}
.lc-unit{font-size:.95rem;font-weight:700;margin-bottom:4px;}
.lc-society{font-size:.78rem;color:var(--text-muted);margin-bottom:10px;display:flex;align-items:center;gap:5px;}
.lc-meta{display:flex;gap:14px;font-size:.78rem;color:var(--text-sub);margin-bottom:10px;flex-wrap:wrap;}
.lc-meta span{display:flex;align-items:center;gap:5px;}
.lc-desc{font-size:.82rem;color:var(--text-sub);line-height:1.5;margin-bottom:12px;max-height:60px;overflow:hidden;}
.lc-contact{border-top:1px solid var(--border);padding-top:12px;display:flex;flex-direction:column;gap:7px;}
.lc-contact-name{font-size:.8rem;font-weight:600;color:var(--text-primary);}
.lc-contact-row{display:flex;align-items:center;justify-content:space-between;gap:8px;}
.lc-contact-phone{font-size:.8rem;color:var(--green);font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis;}
.lc-contact-hidden{color:var(--text-muted);cursor:default;}
.lc-contact-locked{font-size:.8rem;color:var(--green);font-weight:700;text-decoration:none;display:flex;align-items:center;gap:5px;}
.lc-contact-locked:hover{text-decoration:underline;}
.lc-contact-role{font-size:.65rem;font-weight:700;color:var(--green);background:var(--green-light,#e8f5ee);padding:3px 9px;border-radius:99px;text-transform:uppercase;letter-spacing:.03em;white-space:nowrap;flex-shrink:0;}
.lc-btn-message{width:100%;margin-top:10px;background:var(--green-light,#e8f5ee);color:var(--green);border:none;border-radius:8px;padding:9px;font-family:inherit;font-size:.8rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .2s;}
.lc-btn-message:hover{background:#d4ebe0;}
.lc-btn-inbox{width:calc(100% - 36px);margin:0 18px 16px;background:#fff;border:1px solid var(--border);border-radius:8px;padding:8px;font-family:inherit;font-size:.78rem;font-weight:600;color:var(--text-primary);cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;}
.lc-btn-inbox:hover{background:var(--bg);}
.lc-unread-badge{background:#ef4444;color:#fff;font-size:.66rem;font-weight:700;padding:2px 7px;border-radius:99px;}
.lc-status-row{padding:0 18px 16px;display:flex;gap:8px;}
.lc-status-row select{flex:1;font-family:inherit;font-size:.78rem;border:1.5px solid var(--border);border-radius:7px;padding:6px 8px;}
.lc-status-row button{background:var(--green-btn);color:#fff;border:none;border-radius:7px;padding:6px 12px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;}
.lc-edit-row{padding:0 18px 12px;}
.lc-btn-edit{width:100%;background:var(--green-light);color:var(--green-btn);border:1px solid #d4ebe0;border-radius:7px;padding:7px;font-family:inherit;font-size:.78rem;font-weight:700;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;transition:background .2s;}
.lc-btn-edit:hover{background:#d4ebe0;}
.lc-delete-row{padding:0 18px 12px;}
.lc-delete-row button{width:100%;background:#fef2f2;color:#dc2626;border:1px solid #fecaca;border-radius:7px;padding:7px;font-family:inherit;font-size:.78rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:6px;}
.lc-delete-row button:hover{background:#fee2e2;}
.status-tag{font-size:.68rem;font-weight:700;padding:3px 9px;border-radius:99px;text-transform:capitalize;}
.st-active{background:#dcfce7;color:#166534;}
.st-sold{background:#e5e7eb;color:#374151;}
.st-rented{background:#e5e7eb;color:#374151;}
.st-withdrawn{background:#fee2e2;color:#991b1b;}

/* ── EDIT MODAL: current-photo thumbnails with remove checkboxes ── */
.edit-photos-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(92px,1fr));gap:10px;margin-top:4px;}
.edit-photo-item{display:flex;flex-direction:column;gap:4px;cursor:pointer;}
.edit-photo-item img{width:100%;height:70px;object-fit:cover;border-radius:8px;border:2px solid var(--border);transition:opacity .2s,border-color .2s;display:block;}
.edit-photo-del{display:flex;align-items:center;gap:5px;font-size:.72rem;color:var(--text-muted);font-weight:600;}
.edit-photo-del input{width:auto;accent-color:#dc2626;}
.edit-photo-item:has(input:checked) img{border-color:#dc2626;opacity:.5;}
.edit-photos-empty{font-size:.78rem;color:var(--text-muted);margin-top:4px;}

.no-results{text-align:center;padding:60px 20px;color:var(--text-muted);}
.no-results i{font-size:2.4rem;display:block;margin-bottom:14px;color:var(--border);}

/* ── ADD LISTING CTA / MODAL ── */
.cta-bar{background:var(--white);border:1px solid var(--border);border-radius:var(--radius);padding:20px 24px;margin-bottom:28px;display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:12px;}
.cta-text h3{font-size:1rem;font-weight:700;margin-bottom:2px;}
.cta-text p{font-size:.82rem;color:var(--text-muted);}
.btn-add{background:var(--green-btn);color:#fff;border:none;border-radius:9px;padding:11px 20px;font-family:inherit;font-weight:600;font-size:.87rem;cursor:pointer;display:inline-flex;align-items:center;gap:8px;transition:background .2s;}
.btn-add:hover{background:var(--green-hover);}

.modal-overlay{display:none;position:fixed;inset:0;background:rgba(20,40,30,.5);z-index:1000;align-items:center;justify-content:center;padding:20px;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;border-radius:16px;max-width:520px;width:100%;max-height:90vh;overflow-y:auto;padding:28px 26px;}
.modal-head{display:flex;justify-content:space-between;align-items:center;margin-bottom:18px;}
.modal-head h2{font-size:1.15rem;font-weight:700;}
.modal-close{background:none;border:none;font-size:1.1rem;color:var(--text-muted);cursor:pointer;}
.form-row{display:flex;gap:12px;margin-bottom:14px;}
.form-field{flex:1;display:flex;flex-direction:column;gap:6px;}
.form-field label{font-size:.8rem;font-weight:600;color:var(--text-primary);}
.form-field input,.form-field select,.form-field textarea{font-family:inherit;font-size:.87rem;border:1.5px solid var(--border);border-radius:9px;padding:10px 12px;outline:none;transition:border-color .2s;}
.form-field input:focus,.form-field select:focus,.form-field textarea:focus{border-color:var(--green);}
.form-field textarea{resize:vertical;min-height:70px;}
.consent-options{display:flex;flex-direction:column;gap:8px;margin-top:6px;}
.consent-radio{display:flex;align-items:center;gap:8px;font-size:.85rem;font-weight:500;color:var(--text-primary);cursor:pointer;}
.consent-radio input{width:auto;accent-color:var(--green);}
.consent-hint{display:block;font-size:.76rem;color:var(--text-muted);margin-top:6px;}
.btn-submit{width:100%;background:var(--green-btn);color:#fff;border:none;border-radius:10px;padding:12px;font-family:inherit;font-weight:700;font-size:.95rem;cursor:pointer;margin-top:6px;transition:background .2s;}
.btn-submit:hover{background:var(--green-hover);}
.login-prompt{text-align:center;padding:20px;color:var(--text-sub);font-size:.88rem;}
.login-prompt a{color:var(--green);font-weight:700;text-decoration:none;}

@media(max-width:640px){
  .topbar{padding:14px 18px;}
  .hero{padding:34px 18px 48px;}
  .wrap{padding:0 14px;}
  .form-row{flex-direction:column;gap:14px;}
  .pc-body{grid-template-columns:1fr;}
  .pc-header{padding:22px 20px;}
  .tb-user-meta{display:none;}
}
</style>
</head>
<body>

<header class="topbar">
  <div class="tb-brand"><i class="fa fa-building"></i> ColonyCare</div>
  <div class="tb-right">
    <a href="/shivam/index.php" class="tb-btn"><i class="fa fa-arrow-left"></i> Home</a>
    <?php if ($logged_in): ?>
      <button type="button" class="tb-user" onclick="goToProfile()" title="View my profile">
        <span class="tb-avatar"><?= htmlspecialchars(user_initials($profile['name'])) ?></span>
        <span class="tb-user-meta">
          <span class="tb-user-name"><?= htmlspecialchars($profile['name']) ?></span>
          <span class="tb-user-role"><?= htmlspecialchars($roleMeta['label']) ?></span>
        </span>
      </button>
      <a href="/shivam/auth/logout.php" class="tb-btn"><i class="fa fa-right-from-bracket"></i> Logout</a>
    <?php else: ?>
      <a href="<?= htmlspecialchars($loginUrl) ?>" class="tb-btn tb-btn-primary"><i class="fa fa-right-to-bracket"></i> Login</a>
    <?php endif; ?>
  </div>
</header>

<section class="hero">
  <h1>Flats for Sale &amp; Rent</h1>
  <p>Browse listings posted by residents, owners, and buyers across ColonyCare communities — no login required to browse.</p>
</section>

<div class="wrap">

    <?php if ($err): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>
    <?php if ($msg): ?><div class="alert alert-success"><i class="fa fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>

    <!-- FILTERS -->
    <form class="filters" method="GET" action="">
        <div class="filters-row">
            <div class="f-field f-search">
                <label>Search</label>
                <input type="text" name="q" placeholder="Unit, description..." value="<?= htmlspecialchars($f_q) ?>">
            </div>
            <div class="f-field">
                <label>Type</label>
                <select name="type">
                    <option value="">All</option>
                    <option value="sale" <?= $f_type==='sale'?'selected':'' ?>>For Sale</option>
                    <option value="rent" <?= $f_type==='rent'?'selected':'' ?>>For Rent</option>
                </select>
            </div>
            <div class="f-field">
                <label>Society</label>
                <select name="society">
                    <option value="">All Societies</option>
                    <?php foreach ($societies as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $f_society==$s['id']?'selected':'' ?>><?= htmlspecialchars($s['society_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="f-field">
                <label>BHK</label>
                <select name="bhk">
                    <option value="">Any</option>
                    <?php foreach (['1 BHK','2 BHK','3 BHK','4 BHK','4+ BHK'] as $b): ?>
                    <option value="<?= $b ?>" <?= $f_bhk===$b?'selected':'' ?>><?= $b ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="f-field">
                <label>Sort By</label>
                <select name="sort">
                    <option value="newest" <?= $f_sort==='newest'?'selected':'' ?>>Newest First</option>
                    <option value="oldest" <?= $f_sort==='oldest'?'selected':'' ?>>Oldest First</option>
                    <option value="price_low" <?= $f_sort==='price_low'?'selected':'' ?>>Price: Low to High</option>
                    <option value="price_high" <?= $f_sort==='price_high'?'selected':'' ?>>Price: High to Low</option>
                </select>
            </div>
            <div class="f-field">
                <label>Min Price</label>
                <input type="number" name="min_price" placeholder="0" value="<?= htmlspecialchars($f_min) ?>">
            </div>
            <div class="f-field">
                <label>Max Price</label>
                <input type="number" name="max_price" placeholder="Any" value="<?= htmlspecialchars($f_max) ?>">
            </div>
            <button type="submit" class="btn-filter"><i class="fa fa-filter"></i> Filter</button>
            <a href="listings.php" class="btn-clear">Clear</a>
        </div>
    </form>

    <!-- ADD LISTING CTA -->
    <div class="cta-bar">
        <div class="cta-text">
            <h3>Have a flat to sell or rent?</h3>
            <p><?= $can_post ? 'Post your listing so other residents and buyers can find it.' : 'Log in to post a listing — open to residents, society members, admins, and buyers.' ?></p>
        </div>
        <?php if ($can_post): ?>
        <button class="btn-add" onclick="openModal('addModal')"><i class="fa fa-plus"></i> Add Listing</button>
        <?php else: ?>
        <a href="<?= htmlspecialchars($loginUrl) ?>" class="btn-add" style="text-decoration:none;"><i class="fa fa-right-to-bracket"></i> Login to Post</a>
        <?php endif; ?>
    </div>

    <!-- TABS -->
    <div class="tabs">
        <button class="tab-btn active" id="tabbtn-browse" onclick="switchTab('browse', this)"><i class="fa fa-magnifying-glass"></i> Browse All</button>
        <?php if ($logged_in): ?>
        <button class="tab-btn" id="tabbtn-mine" onclick="switchTab('mine', this)"><i class="fa fa-sign-hanging"></i> My Listings (<?= count($my_listings) ?>)</button>
        <button class="tab-btn" id="tabbtn-profile" onclick="switchTab('profile', this)"><i class="fa fa-user"></i> My Profile</button>
        <?php endif; ?>
    </div>

    <!-- BROWSE TAB -->
    <div class="tab-panel active" id="tab-browse">
        <?php if (empty($listings)): ?>
        <div class="no-results">
            <i class="fa fa-house-circle-xmark"></i>
            No listings match your filters right now.
        </div>
        <?php else: ?>
        <div class="listing-grid">
            <?php foreach ($listings as $l): ?>
            <div class="listing-card">
                <?php if (!empty($l['photos'])): ?>
                <div class="lc-photo" onclick='openGallery(<?= json_encode($l['photos']) ?>)' style="cursor:pointer;">
                    <img src="/shivam/<?= htmlspecialchars($l['photos'][0]) ?>" alt="Flat photo" loading="lazy">
                    <?php if (count($l['photos']) > 1): ?>
                    <span class="lc-photo-count"><i class="fa fa-images"></i> <?= count($l['photos']) ?></span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="lc-photo lc-photo-placeholder"><i class="fa fa-image"></i></div>
                <?php endif; ?>
                <div class="lc-head">
                    <span class="lc-badge <?= $l['listing_type'] ?>"><?= $l['listing_type']==='sale'?'For Sale':'For Rent' ?></span>
                    <span class="lc-price">₹<?= number_format($l['price'],0) ?><?= $l['listing_type']==='rent'?'/mo':'' ?></span>
                </div>
                <div class="lc-body">
                    <div class="lc-unit"><?= htmlspecialchars(($l['block']?$l['block'].' - ':'').$l['unit']) ?></div>
                    <div class="lc-society"><i class="fa fa-building"></i> <?= htmlspecialchars($l['society_name']) ?></div>
                    <div class="lc-meta">
                        <?php if ($l['bhk']): ?><span><i class="fa fa-bed"></i> <?= htmlspecialchars($l['bhk']) ?></span><?php endif; ?>
                        <?php if ($l['area_sqft']): ?><span><i class="fa fa-ruler-combined"></i> <?= (int)$l['area_sqft'] ?> sqft</span><?php endif; ?>
                    </div>
                    <?php if ($l['description']): ?><div class="lc-desc"><?= htmlspecialchars($l['description']) ?></div><?php endif; ?>
                    <div class="lc-contact">
                        <span class="lc-contact-name"><?= htmlspecialchars($l['contact_name']) ?></span>
                        <?php
                            $cDisplay = $l['contact_display'] ?? 'owner';
                            $showOwner = in_array($cDisplay, ['owner','both'], true);
                            $showAgent = in_array($cDisplay, ['agent','both'], true) && !empty($l['agent_phone']);
                        ?>
                        <?php if (!$logged_in): ?>
                        <a href="<?= htmlspecialchars($loginUrl) ?>" class="lc-contact-locked"><i class="fa fa-lock"></i> Login to view</a>
                        <?php elseif (!empty($l['show_contact'])): ?>
                            <?php if ($showOwner): ?>
                            <div class="lc-contact-row">
                                <span class="lc-contact-role">Owner</span>
                                <a href="tel:<?= htmlspecialchars($l['contact_phone']) ?>" class="lc-contact-phone"><i class="fa fa-phone"></i> <?= htmlspecialchars($l['contact_phone']) ?></a>
                            </div>
                            <?php endif; ?>
                            <?php if ($showAgent): ?>
                            <div class="lc-contact-row">
                                <span class="lc-contact-role">Agent<?= $l['agent_name'] ? ' · '.htmlspecialchars($l['agent_name']) : '' ?></span>
                                <a href="tel:<?= htmlspecialchars($l['agent_phone']) ?>" class="lc-contact-phone"><i class="fa fa-phone"></i> <?= htmlspecialchars($l['agent_phone']) ?></a>
                            </div>
                            <?php endif; ?>
                        <?php else: ?>
                        <span class="lc-contact-phone lc-contact-hidden"><i class="fa fa-lock"></i> <?= htmlspecialchars(mask_phone($l['contact_phone'])) ?></span>
                        <?php endif; ?>
                    </div>
                    <?php if ($logged_in && (int)$l['user_id'] !== (int)$user_id): ?>
                    <button class="lc-btn-message" onclick='openMessageModal(<?= $l['id'] ?>, <?= json_encode($l['contact_name']) ?>)'><i class="fa fa-comment"></i> Message Owner</button>
                    <?php endif; ?>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- MY LISTINGS TAB -->
    <?php if ($logged_in): ?>
    <div class="tab-panel" id="tab-mine">
        <?php if (empty($my_listings)): ?>
        <div class="no-results">
            <i class="fa fa-house-circle-xmark"></i>
            You haven't posted any listings yet.
        </div>
        <?php else: ?>
        <div class="listing-grid">
            <?php foreach ($my_listings as $l): ?>
            <div class="listing-card">
                <?php if (!empty($l['photos'])): ?>
                <div class="lc-photo" onclick='openGallery(<?= json_encode($l['photos']) ?>)' style="cursor:pointer;">
                    <img src="/shivam/<?= htmlspecialchars($l['photos'][0]) ?>" alt="Flat photo" loading="lazy">
                    <?php if (count($l['photos']) > 1): ?>
                    <span class="lc-photo-count"><i class="fa fa-images"></i> <?= count($l['photos']) ?></span>
                    <?php endif; ?>
                </div>
                <?php else: ?>
                <div class="lc-photo lc-photo-placeholder"><i class="fa fa-image"></i></div>
                <?php endif; ?>
                <div class="lc-head">
                    <span class="lc-badge <?= $l['listing_type'] ?>"><?= $l['listing_type']==='sale'?'For Sale':'For Rent' ?></span>
                    <span class="status-tag st-<?= $l['status'] ?>"><?= $l['status'] ?></span>
                </div>
                <div class="lc-body">
                    <div class="lc-unit"><?= htmlspecialchars(($l['block']?$l['block'].' - ':'').$l['unit']) ?></div>
                    <div class="lc-society"><i class="fa fa-building"></i> <?= htmlspecialchars($l['society_name']) ?></div>
                    <div class="lc-price" style="margin-bottom:8px;">₹<?= number_format($l['price'],0) ?><?= $l['listing_type']==='rent'?'/mo':'' ?></div>
                </div>
                <div class="lc-edit-row">
                    <button type="button" class="lc-btn-edit" onclick="openEditModal(<?= $l['id'] ?>)"><i class="fa fa-pen"></i> Edit Details</button>
                </div>
                <form method="POST" class="lc-status-row">
                    <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                    <select name="new_status">
                        <option value="active" <?= $l['status']==='active'?'selected':'' ?>>Active</option>
                        <option value="sold" <?= $l['status']==='sold'?'selected':'' ?>>Sold</option>
                        <option value="rented" <?= $l['status']==='rented'?'selected':'' ?>>Rented</option>
                        <option value="withdrawn" <?= $l['status']==='withdrawn'?'selected':'' ?>>Withdraw</option>
                    </select>
                    <button type="submit" name="update_status" value="1">Update</button>
                </form>
                <form method="POST" class="lc-delete-row" onsubmit="return confirm('Delete this listing permanently? This also removes its photos and messages.');">
                    <input type="hidden" name="listing_id" value="<?= $l['id'] ?>">
                    <button type="submit" name="delete_listing" value="1"><i class="fa fa-trash"></i> Delete Listing</button>
                </form>
                <?php if (!empty($l['messages'])): ?>
                <button class="lc-btn-inbox" onclick='openInboxModal(<?= $l['id'] ?>, <?= json_encode(($l['block']?$l['block'].'-':'').$l['unit']) ?>, <?= json_encode($l['messages']) ?>)'>
                    <i class="fa fa-envelope"></i> <?= count($l['messages']) ?> message<?= count($l['messages'])>1?'s':'' ?>
                    <?php if ($l['unread_count'] > 0): ?><span class="lc-unread-badge"><?= $l['unread_count'] ?> new</span><?php endif; ?>
                </button>
                <?php endif; ?>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>

    <!-- MY PROFILE TAB -->
    <div class="tab-panel" id="tab-profile">
        <div class="profile-card">

            <!-- Header: avatar, name, role badge -->
            <div class="pc-header">
                <div class="pc-avatar"><?= htmlspecialchars(user_initials($profile['name'])) ?></div>
                <div class="pc-head-text">
                    <h2><?= htmlspecialchars($profile['name']) ?></h2>
                    <span class="pc-role-badge"><i class="fa <?= $roleMeta['icon'] ?>"></i> <?= htmlspecialchars($roleMeta['label']) ?></span>
                    <?php if ($roleMeta['desc']): ?>
                    <p class="pc-role-desc"><?= htmlspecialchars($roleMeta['desc']) ?></p>
                    <?php endif; ?>
                </div>
            </div>

            <!-- Quick stats -->
            <div class="pc-stats">
                <div class="pc-stat">
                    <div class="pc-stat-num"><?= $stats_total ?></div>
                    <div class="pc-stat-label">Listings Posted</div>
                </div>
                <div class="pc-stat">
                    <div class="pc-stat-num"><?= $stats_active ?></div>
                    <div class="pc-stat-label">Active Now</div>
                </div>
                <div class="pc-stat">
                    <div class="pc-stat-num"><?= $stats_unread ?></div>
                    <div class="pc-stat-label">Unread Messages</div>
                </div>
            </div>

            <!-- Profile details (role-aware) -->
            <div class="pc-body">
                <div class="pc-detail">
                    <i class="fa fa-envelope"></i>
                    <div>
                        <span class="pc-label">Email</span>
                        <span class="pc-value"><?= $profileEmail !== '' ? htmlspecialchars($profileEmail) : 'Not set' ?></span>
                    </div>
                </div>
                <div class="pc-detail">
                    <i class="fa fa-phone"></i>
                    <div>
                        <span class="pc-label">Phone</span>
                        <span class="pc-value"><?= $profile['phone'] !== '' ? htmlspecialchars($profile['phone']) : 'Not set' ?></span>
                    </div>
                </div>
                <?php if ($user_role === 'admin'): ?>
                <div class="pc-detail">
                    <i class="fa fa-city"></i>
                    <div>
                        <span class="pc-label">Managing Society</span>
                        <span class="pc-value"><?= $profile['society_name'] !== '' ? htmlspecialchars($profile['society_name']) : 'Not assigned' ?></span>
                    </div>
                </div>
                <div class="pc-detail">
                    <i class="fa fa-user-shield"></i>
                    <div>
                        <span class="pc-label">Access Level</span>
                        <span class="pc-value">Full society administration</span>
                    </div>
                </div>
                <?php else: ?>
                <div class="pc-detail">
                    <i class="fa fa-house"></i>
                    <div>
                        <span class="pc-label">Flat / Unit</span>
                        <span class="pc-value"><?= $profile['unit'] !== '' ? htmlspecialchars($profile['unit']) : 'Not set' ?></span>
                    </div>
                </div>
                <div class="pc-detail">
                    <i class="fa fa-building"></i>
                    <div>
                        <span class="pc-label">Block / Tower</span>
                        <span class="pc-value"><?= $profile['block'] !== '' ? htmlspecialchars($profile['block']) : 'Not set' ?></span>
                    </div>
                </div>
                <div class="pc-detail">
                    <i class="fa fa-city"></i>
                    <div>
                        <span class="pc-label">Society</span>
                        <span class="pc-value"><?= $profile['society_name'] !== '' ? htmlspecialchars($profile['society_name']) : 'Not assigned' ?></span>
                    </div>
                </div>
                <div class="pc-detail">
                    <i class="fa fa-circle-check"></i>
                    <div>
                        <span class="pc-label">Can Post Listings</span>
                        <span class="pc-value"><?= $can_post ? 'Yes' : 'No' ?></span>
                    </div>
                </div>
                <?php endif; ?>
            </div>

            <!-- Edit own contact details -->
            <details class="pc-edit" <?= ($err && isset($_POST['update_profile'])) ? 'open' : '' ?>>
                <summary><i class="fa fa-pen"></i> Edit contact details</summary>
                <form method="POST">
                    <p class="pc-edit-note">
                        Your phone number auto-fills new listings and is shared with owners when you message them.
                        Flat and block details auto-fill the listing form.
                    </p>
                    <div class="form-row">
                        <div class="form-field">
                            <label>Phone *</label>
                            <input type="tel" name="profile_phone" placeholder="10-digit mobile" value="<?= htmlspecialchars($profile['phone']) ?>" required>
                        </div>
                        <?php if ($user_role !== 'admin'): ?>
                        <div class="form-field">
                            <label>Flat / Unit</label>
                            <input type="text" name="profile_unit" placeholder="e.g. 201" value="<?= htmlspecialchars($profile['unit']) ?>">
                        </div>
                        <div class="form-field">
                            <label>Block / Tower</label>
                            <input type="text" name="profile_block" placeholder="e.g. B" value="<?= htmlspecialchars($profile['block']) ?>">
                        </div>
                        <?php endif; ?>
                    </div>
                    <button type="submit" name="update_profile" value="1" class="btn-save-profile"><i class="fa fa-check"></i> Save Changes</button>
                </form>
            </details>

        </div>
    </div>
    <?php endif; ?>

</div>

<!-- ADD LISTING MODAL -->
<div class="modal-overlay" id="addModal" onclick="closeModalBg(event)">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Post a Listing</h2>
            <button class="modal-close" onclick="closeModal('addModal')"><i class="fa fa-xmark"></i></button>
        </div>
        <?php if ($can_post): ?>
        <form method="POST" enctype="multipart/form-data" id="addListingForm">
            <?php if ($society_id): ?>
            <!-- Poster's own society (from their account) is used automatically -->
            <p class="consent-hint" style="margin-bottom:14px;"><i class="fa fa-building"></i> Posting in: <strong><?= htmlspecialchars($profile['society_name'] ?: 'Your society') ?></strong></p>
            <?php else: ?>
            <!-- No society on the account (e.g. a buyer) — let them pick where the flat is -->
            <div class="form-field" style="margin-bottom:14px;">
                <label>Society *</label>
                <select name="society_id" required>
                    <option value="">Select the society where the flat is located</option>
                    <?php foreach ($societies as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['society_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-row">
                <div class="form-field">
                    <label>Listing Type *</label>
                    <select name="listing_type" required>
                        <option value="sale">For Sale</option>
                        <option value="rent">For Rent</option>
                    </select>
                </div>
                <div class="form-field">
                    <label>Price (₹) *</label>
                    <input type="number" name="price" placeholder="e.g. 4500000" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>Unit / Flat No *</label>
                    <input type="text" name="unit" placeholder="e.g. 201" value="<?= htmlspecialchars($profile['unit']) ?>" required>
                </div>
                <div class="form-field">
                    <label>Block / Tower</label>
                    <input type="text" name="block" placeholder="e.g. B" value="<?= htmlspecialchars($profile['block']) ?>">
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>BHK</label>
                    <select name="bhk">
                        <option value="">Select</option>
                        <?php foreach (['1 BHK','2 BHK','3 BHK','4 BHK','4+ BHK'] as $b): ?>
                        <option value="<?= $b ?>"><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label>Area (sqft)</label>
                    <input type="number" name="area_sqft" placeholder="e.g. 1200">
                </div>
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label>Description</label>
                <textarea name="description" placeholder="Facing, floor, amenities, etc."></textarea>
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label>Photos (optional, up to 5)</label>
                <input type="file" name="photos[]" accept="image/jpeg,image/png,image/webp" multiple id="photoInput">
                <span id="photoCountMsg" style="font-size:.75rem;color:var(--text-muted);"></span>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>Contact Name *</label>
                    <input type="text" name="contact_name" value="<?= htmlspecialchars($profile['name']) ?>" required>
                </div>
                <div class="form-field">
                    <label>Contact Phone *</label>
                    <input type="tel" name="contact_phone" placeholder="10-digit mobile" value="<?= htmlspecialchars($profile['phone']) ?>" required>
                </div>
            </div>
            <div class="form-field consent-field" style="margin-bottom:14px;">
                <label>Can we show a contact number publicly? *</label>
                <div class="consent-options">
                    <label class="consent-radio"><input type="radio" name="show_contact" value="1" required> Yes, show a number to everyone</label>
                    <label class="consent-radio"><input type="radio" name="show_contact" value="0" required> No, hide it (show as 807712XXX)</label>
                </div>
                <span class="consent-hint">If hidden, your name will still be shown so interested buyers know who to ask about.</span>
            </div>
            <div class="form-field consent-field" id="addContactDisplayWrap" style="margin-bottom:14px;display:none;">
                <label>Which number should buyers see? *</label>
                <div class="consent-options">
                    <label class="consent-radio"><input type="radio" name="contact_display" value="owner" checked> Owner's number</label>
                    <label class="consent-radio"><input type="radio" name="contact_display" value="agent"> Agent's number</label>
                    <label class="consent-radio"><input type="radio" name="contact_display" value="both"> Both</label>
                </div>
            </div>
            <div class="form-row" id="addAgentFieldsWrap" style="display:none;">
                <div class="form-field">
                    <label>Agent Name *</label>
                    <input type="text" name="agent_name">
                </div>
                <div class="form-field">
                    <label>Agent Phone *</label>
                    <input type="tel" name="agent_phone" placeholder="10-digit mobile">
                </div>
            </div>
            <button type="submit" name="add_listing" value="1" class="btn-submit"><i class="fa fa-check"></i> Post Listing</button>
        </form>
        <?php else: ?>
        <div class="login-prompt">
            You need to be logged in to post a listing. Residents, society members, admins, and buyers can all post.


            <a href="<?= htmlspecialchars($loginUrl) ?>">Login now →</a>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php if ($logged_in): ?>
<!-- ══ EDIT LISTING MODAL (pre-filled via JS from MY_LISTINGS) ══ -->
<div class="modal-overlay" id="editModal" onclick="closeModalBg(event)">
    <div class="modal-box">
        <div class="modal-head">
            <h2>Edit Listing</h2>
            <button class="modal-close" onclick="closeModal('editModal')"><i class="fa fa-xmark"></i></button>
        </div>
        <form method="POST" enctype="multipart/form-data" id="editListingForm">
            <input type="hidden" name="listing_id" value="">
            <?php if (!$society_id): ?>
            <!-- Buyers (no society on account) may correct which society the flat belongs to -->
            <div class="form-field" style="margin-bottom:14px;">
                <label>Society *</label>
                <select name="society_id" required>
                    <option value="">Select the society where the flat is located</option>
                    <?php foreach ($societies as $s): ?>
                    <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['society_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="form-row">
                <div class="form-field">
                    <label>Listing Type *</label>
                    <select name="listing_type" required>
                        <option value="sale">For Sale</option>
                        <option value="rent">For Rent</option>
                    </select>
                </div>
                <div class="form-field">
                    <label>Price (₹) *</label>
                    <input type="number" name="price" min="1" required>
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>Unit / Flat No *</label>
                    <input type="text" name="unit" placeholder="e.g. 201" required>
                </div>
                <div class="form-field">
                    <label>Block / Tower</label>
                    <input type="text" name="block" placeholder="e.g. B">
                </div>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>BHK</label>
                    <select name="bhk">
                        <option value="">Select</option>
                        <?php foreach (['1 BHK','2 BHK','3 BHK','4 BHK','4+ BHK'] as $b): ?>
                        <option value="<?= $b ?>"><?= $b ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-field">
                    <label>Area (sqft)</label>
                    <input type="number" name="area_sqft" placeholder="e.g. 1200">
                </div>
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label>Description</label>
                <textarea name="description" placeholder="Facing, floor, amenities, etc."></textarea>
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label>Current Photos (tick to remove)</label>
                <div class="edit-photos-grid" id="editCurrentPhotos"></div>
            </div>
            <div class="form-field" style="margin-bottom:14px;">
                <label>Add More Photos (5 total max)</label>
                <input type="file" name="new_photos[]" accept="image/jpeg,image/png,image/webp" multiple id="editPhotoInput">
                <span id="editPhotoCountMsg" style="font-size:.75rem;color:var(--text-muted);"></span>
            </div>
            <div class="form-row">
                <div class="form-field">
                    <label>Contact Name *</label>
                    <input type="text" name="contact_name" required>
                </div>
                <div class="form-field">
                    <label>Contact Phone *</label>
                    <input type="tel" name="contact_phone" placeholder="10-digit mobile" required>
                </div>
            </div>
            <div class="form-field consent-field" style="margin-bottom:14px;">
                <label>Can we show a contact number publicly? *</label>
                <div class="consent-options">
                    <label class="consent-radio"><input type="radio" name="show_contact" value="1" required> Yes, show a number to everyone</label>
                    <label class="consent-radio"><input type="radio" name="show_contact" value="0" required> No, hide it (show as 807712XXX)</label>
                </div>
            </div>
            <div class="form-field consent-field" id="editContactDisplayWrap" style="margin-bottom:14px;display:none;">
                <label>Which number should buyers see? *</label>
                <div class="consent-options">
                    <label class="consent-radio"><input type="radio" name="contact_display" value="owner"> Owner's number</label>
                    <label class="consent-radio"><input type="radio" name="contact_display" value="agent"> Agent's number</label>
                    <label class="consent-radio"><input type="radio" name="contact_display" value="both"> Both</label>
                </div>
            </div>
            <div class="form-row" id="editAgentFieldsWrap" style="display:none;">
                <div class="form-field">
                    <label>Agent Name *</label>
                    <input type="text" name="agent_name">
                </div>
                <div class="form-field">
                    <label>Agent Phone *</label>
                    <input type="tel" name="agent_phone" placeholder="10-digit mobile">
                </div>
            </div>
            <button type="submit" name="update_listing" value="1" class="btn-submit"><i class="fa fa-check"></i> Save Changes</button>
        </form>
    </div>
</div>
<?php endif; ?>

<!-- ══ MESSAGE OWNER MODAL ══ -->
<div class="modal-overlay" id="messageModal" onclick="closeModalBg(event)">
    <div class="modal-box" style="max-width:440px;">
        <div class="modal-head">
            <h2 id="messageModalTitle">Message Owner</h2>
            <button class="modal-close" onclick="closeModal('messageModal')"><i class="fa fa-xmark"></i></button>
        </div>
        <form method="POST">
            <input type="hidden" name="listing_id" id="msg_listing_id" value="">
            <div class="form-field" style="margin-bottom:10px;">
                <label>Your Message</label>
                <textarea name="message_text" rows="4" placeholder="Hi, I'm interested in this flat. Is it still available?" required></textarea>
            </div>
            <p style="font-size:.78rem;color:var(--text-muted);margin-bottom:14px;">
                The owner will see your name and contact info from your profile, and can reply to you directly.
            </p>
            <button type="submit" name="send_message" value="1" class="btn-submit"><i class="fa fa-paper-plane"></i> Send Message</button>
        </form>
    </div>
</div>

<?php if ($logged_in): ?>
<!-- Listing data for the edit modal (HEX flags make it safe to embed in a script tag) -->
<script>
const MY_LISTINGS = <?= json_encode($myListingsEdit, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP) ?>;
</script>
<?php endif; ?>

<script>
function openModal(id){ document.getElementById(id).classList.add('open'); document.body.style.overflow='hidden'; }
function closeModal(id){ document.getElementById(id).classList.remove('open'); document.body.style.overflow=''; }
function closeModalBg(e){ if(e.target.classList.contains('modal-overlay')) closeModal(e.target.id); }

function openMessageModal(listingId, ownerName){
    document.getElementById('messageModalTitle').textContent = 'Message ' + ownerName;
    document.getElementById('msg_listing_id').value = listingId;
    openModal('messageModal');
}

// Opens the edit modal and fills every field from the listing's current data
function openEditModal(id){
    const d = (typeof MY_LISTINGS !== 'undefined') ? MY_LISTINGS[id] : null;
    if (!d) return;
    const f = document.getElementById('editListingForm');
    f.elements['listing_id'].value    = id;
    f.elements['listing_type'].value  = d.listing_type;
    f.elements['price'].value         = d.price;
    f.elements['unit'].value          = d.unit;
    f.elements['block'].value         = d.block || '';
    f.elements['bhk'].value           = d.bhk || '';
    f.elements['area_sqft'].value     = d.area_sqft || '';
    f.elements['description'].value   = d.description || '';
    f.elements['contact_name'].value  = d.contact_name;
    f.elements['contact_phone'].value = d.contact_phone;
    f.querySelectorAll('input[name="show_contact"]').forEach(r => {
        r.checked = (String(d.show_contact) === r.value);
    });
    f.elements['agent_name'].value  = d.agent_name || '';
    f.elements['agent_phone'].value = d.agent_phone || '';
    f.querySelectorAll('input[name="contact_display"]').forEach(r => {
        r.checked = (String(d.contact_display || 'owner') === r.value);
    });
    updateContactToggle('editListingForm');
    // Society dropdown only exists for posters with no society on their account (e.g. buyers)
    const socSel = f.querySelector('select[name="society_id"]');
    if (socSel) socSel.value = String(d.society_id);

    // Render existing photos with a "remove" checkbox under each
    const grid = document.getElementById('editCurrentPhotos');
    if (d.photos && d.photos.length) {
        grid.innerHTML = d.photos.map(p =>
            '<label class="edit-photo-item">' +
                '<img src="/shivam/' + p.path + '" alt="Listing photo" loading="lazy">' +
                '<span class="edit-photo-del"><input type="checkbox" name="delete_photos[]" value="' + p.id + '"> Remove</span>' +
            '</label>'
        ).join('');
    } else {
        grid.innerHTML = '<p class="edit-photos-empty">No photos yet — you can add some below.</p>';
    }

    const fi = document.getElementById('editPhotoInput');
    if (fi) fi.value = '';
    const fm = document.getElementById('editPhotoCountMsg');
    if (fm) fm.textContent = '';

    openModal('editModal');
}

function openInboxModal(listingId, unitLabel, messages){
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;';
    const rows = messages.map(m => {
        const d = new Date(m.created_at.replace(' ', 'T'));
        const dateStr = d.toLocaleDateString('en-IN', {day:'numeric', month:'short', hour:'2-digit', minute:'2-digit'});
        return `<div style="padding:12px 0;border-bottom:1px solid var(--border);">
            <div style="display:flex;justify-content:space-between;align-items:baseline;margin-bottom:4px;">
                <strong style="font-size:.85rem;">${m.sender_name}</strong>
                <span style="font-size:.72rem;color:var(--text-muted);">${dateStr}</span>
            </div>
            <div style="font-size:.75rem;color:var(--green);margin-bottom:6px;"><i class="fa fa-phone" style="margin-right:4px;"></i>${m.sender_contact}</div>
            <div style="font-size:.85rem;color:var(--text-primary);line-height:1.5;">${m.message}</div>
        </div>`;
    }).join('');
    overlay.innerHTML = `
        <div style="background:#fff;border-radius:16px;max-width:460px;width:100%;max-height:80vh;overflow-y:auto;padding:24px;">
            <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:14px;">
                <h2 style="font-size:1.1rem;font-weight:700;">Messages — ${unitLabel}</h2>
                <button id="inboxCloseBtn" style="background:none;border:none;font-size:1.1rem;color:var(--text-muted);cursor:pointer;"><i class="fa fa-xmark"></i></button>
            </div>
            ${rows}
        </div>
    `;
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';
    function close(){ overlay.remove(); document.body.style.overflow = ''; }
    overlay.querySelector('#inboxCloseBtn').onclick = close;
    overlay.addEventListener('click', e => { if (e.target === overlay) close(); });

    // Mark as read in the background
    fetch(window.location.pathname, {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: 'mark_messages_read=1&listing_id=' + listingId
    });
}

// Switches tabs. Accepts the clicked button directly (no reliance on the global event object).
function switchTab(name, btn){
    document.querySelectorAll('.tab-btn').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.tab-panel').forEach(p=>p.classList.remove('active'));
    const tabBtn = btn || document.getElementById('tabbtn-'+name);
    if (tabBtn) tabBtn.classList.add('active');
    const panel = document.getElementById('tab-'+name);
    if (panel) panel.classList.add('active');
}

// Jump to the profile tab (used by the topbar user chip)
function goToProfile(){
    switchTab('profile');
    document.querySelector('.wrap').scrollIntoView({behavior:'smooth', block:'start'});
}

// ── Photo count check (shared by the add-listing and edit-listing forms) ──
function attachPhotoCheck(inputId, msgId){
    const input = document.getElementById(inputId);
    if (!input) return;
    input.addEventListener('change', function(){
        const msg = document.getElementById(msgId);
        if (this.files.length > 5) {
            msg.textContent = 'Please select at most 5 photos.';
            msg.style.color = '#dc2626';
            this.value = '';
        } else if (this.files.length > 0) {
            msg.textContent = this.files.length + ' photo(s) selected.';
            msg.style.color = 'var(--text-muted)';
        } else {
            msg.textContent = '';
        }
    });
}
attachPhotoCheck('photoInput', 'photoCountMsg');
attachPhotoCheck('editPhotoInput', 'editPhotoCountMsg');

// ── Contact visibility toggles (shared by add-listing and edit-listing forms) ──
// Shows "which number to display" once "Yes, show a number" is picked,
// and shows the Agent Name/Phone fields once Agent or Both is picked.
function updateContactToggle(formId){
    const form = document.getElementById(formId);
    if (!form) return;
    const prefix = formId === 'addListingForm' ? 'add' : 'edit';
    const dispWrap  = document.getElementById(prefix + 'ContactDisplayWrap');
    const agentWrap = document.getElementById(prefix + 'AgentFieldsWrap');
    const agentName  = form.elements['agent_name'];
    const agentPhone = form.elements['agent_phone'];

    const shown = form.querySelector('input[name="show_contact"]:checked');
    const showingContact = !!shown && shown.value === '1';
    if (dispWrap) dispWrap.style.display = showingContact ? '' : 'none';

    const disp = form.querySelector('input[name="contact_display"]:checked');
    const needsAgent = showingContact && !!disp && (disp.value === 'agent' || disp.value === 'both');
    if (agentWrap) agentWrap.style.display = needsAgent ? '' : 'none';
    if (agentName)  agentName.required  = needsAgent;
    if (agentPhone) agentPhone.required = needsAgent;
}
function attachContactToggle(formId){
    const form = document.getElementById(formId);
    if (!form) return;
    form.querySelectorAll('input[name="show_contact"], input[name="contact_display"]')
        .forEach(r => r.addEventListener('change', () => updateContactToggle(formId)));
    updateContactToggle(formId);
}
attachContactToggle('addListingForm');
attachContactToggle('editListingForm');

// ── Photo gallery lightbox ─────────────────────────────────
function openGallery(photos){
    let idx = 0;
    const overlay = document.createElement('div');
    overlay.style.cssText = 'position:fixed;inset:0;background:rgba(0,0,0,.85);z-index:2000;display:flex;align-items:center;justify-content:center;padding:20px;';
    overlay.innerHTML = `
        <button id="galClose" style="position:absolute;top:20px;right:24px;background:none;border:none;color:#fff;font-size:1.6rem;cursor:pointer;">&times;</button>
        <button id="galPrev" style="position:absolute;left:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:1.3rem;width:44px;height:44px;border-radius:50%;cursor:pointer;">&#8249;</button>
        <img id="galImg" src="/shivam/${photos[0]}" style="max-width:88vw;max-height:85vh;border-radius:10px;object-fit:contain;">
        <button id="galNext" style="position:absolute;right:16px;top:50%;transform:translateY(-50%);background:rgba(255,255,255,.15);border:none;color:#fff;font-size:1.3rem;width:44px;height:44px;border-radius:50%;cursor:pointer;">&#8250;</button>
        <span id="galCount" style="position:absolute;bottom:24px;left:50%;transform:translateX(-50%);color:#fff;font-size:.85rem;background:rgba(0,0,0,.5);padding:4px 12px;border-radius:99px;"></span>
    `;
    document.body.appendChild(overlay);
    document.body.style.overflow = 'hidden';

    const img = overlay.querySelector('#galImg');
    const count = overlay.querySelector('#galCount');
    function render(){ img.src = '/shivam/' + photos[idx]; count.textContent = (idx+1) + ' / ' + photos.length; }
    render();

    overlay.querySelector('#galPrev').onclick = (e) => { e.stopPropagation(); idx = (idx - 1 + photos.length) % photos.length; render(); };
    overlay.querySelector('#galNext').onclick = (e) => { e.stopPropagation(); idx = (idx + 1) % photos.length; render(); };
    overlay.querySelector('#galClose').onclick = close;
    overlay.addEventListener('click', function(e){ if (e.target === overlay) close(); });
    function close(){ overlay.remove(); document.body.style.overflow = ''; }
}

<?php if ($err && isset($_POST['add_listing'])): ?>
document.addEventListener('DOMContentLoaded', function(){ openModal('addModal'); });
<?php endif; ?>
<?php if ($editErrorId): ?>
document.addEventListener('DOMContentLoaded', function(){ openEditModal(<?= (int)$editErrorId ?>); });
<?php endif; ?>
<?php if ($openTab): ?>
document.addEventListener('DOMContentLoaded', function(){ switchTab('<?= $openTab === 'mine' ? 'mine' : 'profile' ?>'); });
<?php endif; ?>
</script>

</body>
</html>
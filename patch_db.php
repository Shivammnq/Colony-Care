<?php
// patch_db.php — Emergency fix for missing columns
// Visit: http://localhost/SHIVAM/patch_db.php

define('DB_HOST','localhost');
define('DB_NAME','cc');
define('DB_USER','root');
define('DB_PASS','');

$log = [];
function ok($m){global $log;$log[]=['ok',$m];}
function er($m){global $log;$log[]=['er',$m];}

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
ok("Connected to <b>".DB_NAME."</b>");

// ── Show current billing columns
$billing_cols = $pdo->query("DESCRIBE billing")->fetchAll(PDO::FETCH_COLUMN);
ok("Current billing columns: <b>".implode(', ',$billing_cols)."</b>");

// ── Step 1: Backup existing billing data
$existing_bills = $pdo->query("SELECT * FROM billing")->fetchAll();
ok("Found ".count($existing_bills)." existing billing rows — backing up");

// ── Step 2: Drop and recreate billing with correct structure
$pdo->exec("DROP TABLE IF EXISTS billing");
ok("Dropped old billing table");

$pdo->exec("CREATE TABLE billing (
    id          INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no  VARCHAR(20)   DEFAULT '',
    user_id     INT UNSIGNED  NOT NULL DEFAULT 1,
    unit        VARCHAR(20)   DEFAULT '',
    description VARCHAR(200)  NOT NULL DEFAULT 'Monthly Maintenance',
    amount      DECIMAL(10,2) NOT NULL DEFAULT 0,
    month       VARCHAR(20)   DEFAULT '',
    due_date    DATE          NULL,
    status      ENUM('pending','paid','overdue') DEFAULT 'pending',
    paid_at     DATETIME      NULL,
    created_at  DATETIME      DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("Created new billing table with correct structure");

// ── Step 3: Restore old data if any
if (!empty($existing_bills)) {
    $stmt = $pdo->prepare("INSERT INTO billing (invoice_no,user_id,unit,description,amount,month,due_date,status,paid_at,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($existing_bills as $b) {
        try {
            $stmt->execute([
                $b['invoice_no'] ?? '',
                $b['user_id']    ?? 1,
                $b['unit']       ?? '',
                $b['description']?? 'Monthly Maintenance',
                $b['amount']     ?? 0,
                $b['month']      ?? '',
                $b['due_date']   ?? null,
                $b['status']     ?? 'pending',
                $b['paid_at']    ?? null,
                $b['created_at'] ?? date('Y-m-d H:i:s'),
            ]);
        } catch(Exception $e) { er("Row restore error: ".$e->getMessage()); }
    }
    ok("Restored ".count($existing_bills)." billing rows");
}

// ── Step 4: Fix ALL other tables - add missing columns safely
$fixes = [
    'users'      => ["unit VARCHAR(20) DEFAULT ''", "block VARCHAR(10) DEFAULT ''", "phone VARCHAR(15) DEFAULT ''", "society VARCHAR(100) DEFAULT ''", "is_active TINYINT(1) NOT NULL DEFAULT 1", "last_login DATETIME NULL"],
    'complaints' => ["unit VARCHAR(20) DEFAULT NULL", "category VARCHAR(50) DEFAULT NULL", "description TEXT NULL", "updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP"],
    'events'     => ["description TEXT NULL", "event_time VARCHAR(20) DEFAULT NULL", "location VARCHAR(100) DEFAULT NULL"],
    'notices'    => ["body TEXT NULL", "created_by INT UNSIGNED DEFAULT NULL"],
];

foreach ($fixes as $table => $cols) {
    $existing = $pdo->query("DESCRIBE `$table`")->fetchAll(PDO::FETCH_COLUMN);
    foreach ($cols as $col_def) {
        $col_name = explode(' ', trim($col_def))[0];
        if (!in_array($col_name, $existing)) {
            try {
                $pdo->exec("ALTER TABLE `$table` ADD COLUMN $col_def");
                ok("Added column <b>$col_name</b> to $table");
            } catch(Exception $e) { er("Could not add $col_name to $table: ".$e->getMessage()); }
        }
    }
}

// ── Step 5: Get admin user id for seeding
$admin_id = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
if (!$admin_id) $admin_id = $pdo->query("SELECT id FROM users LIMIT 1")->fetchColumn();
$all_users = $pdo->query("SELECT id,name,block,unit FROM users ORDER BY id")->fetchAll();
ok("Admin user ID: <b>$admin_id</b> · Total users: ".count($all_users));

// ── Step 6: Seed notices if empty
if ($pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO notices (title,body,priority,created_by,created_at) VALUES
        ('🚰 Water Supply Disruption – Block C','No water on 28th May 10am–2pm for tank cleaning.','high',$admin_id,DATE_SUB(NOW(),INTERVAL 1 DAY)),
        ('📅 Annual General Meeting – June 10','AGM on 10th June 6PM Clubhouse Hall. All residents must attend.','medium',$admin_id,DATE_SUB(NOW(),INTERVAL 3 DAY)),
        ('🚗 New Parking Rules Effective June 1','Each unit gets 1 parking spot. Violations towed.','low',$admin_id,DATE_SUB(NOW(),INTERVAL 5 DAY)),
        ('🎉 Diwali Celebration – May 30','Community Diwali night music, food, fireworks at 7PM Open Ground.','low',$admin_id,DATE_SUB(NOW(),INTERVAL 7 DAY)),
        ('💰 Maintenance Charges Revised FY26-27','Monthly maintenance increased to 2500 from June 2026.','high',$admin_id,DATE_SUB(NOW(),INTERVAL 10 DAY)),
        ('🔧 Lift Maintenance A & B Block','Lift maintenance 25th May 9AM–1PM. Use stairs.','medium',$admin_id,DATE_SUB(NOW(),INTERVAL 12 DAY))
    ");
    ok("Seeded <b>6 notices</b>");
} else { ok("Notices: already has ".($pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn())." rows"); }

// ── Step 7: Seed events if empty
if ($pdo->query("SELECT COUNT(*) FROM events")->fetchColumn() == 0) {
    $pdo->exec("INSERT INTO events (title,description,event_date,event_time,location) VALUES
        ('Annual General Meeting','Mandatory meeting. Agenda: budget, maintenance, elections.',DATE_ADD(CURDATE(),INTERVAL 14 DAY),'6:00 PM','Clubhouse Hall'),
        ('Community Diwali Night','Celebrate with music, food stalls and fireworks.',DATE_ADD(CURDATE(),INTERVAL 16 DAY),'7:00 PM','Open Ground'),
        ('Quarterly Maintenance Drive','Plumbing, electrical and civil inspection of all blocks.',DATE_ADD(CURDATE(),INTERVAL 22 DAY),'9:00 AM','All Blocks'),
        ('Morning Yoga Camp','Free yoga sessions. Open for all ages.',DATE_ADD(CURDATE(),INTERVAL 26 DAY),'7:00 AM','Terrace Garden'),
        ('Kids Painting Competition','For children aged 5-15. Theme: My Dream Home.',DATE_ADD(CURDATE(),INTERVAL 32 DAY),'10:00 AM','Community Hall'),
        ('Safety & First Aid Workshop','Fire safety and emergency response workshop.',DATE_ADD(CURDATE(),INTERVAL 44 DAY),'4:00 PM','Clubhouse Hall'),
        ('Independence Day Celebration','Flag hoisting, cultural programs and community lunch.',DATE_ADD(CURDATE(),INTERVAL 81 DAY),'8:00 AM','Main Entrance')
    ");
    ok("Seeded <b>7 events</b> (future dates from today)");
} else { ok("Events: already has ".($pdo->query("SELECT COUNT(*) FROM events")->fetchColumn())." rows"); }

// ── Step 8: Seed complaints if empty
if ($pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn() == 0 && !empty($all_users)) {
    $uids = array_column($all_users,'id');
    $stmt = $pdo->prepare("INSERT INTO complaints (user_id,unit,category,subject,description,priority,status,created_at) VALUES (?,?,?,?,?,?,?,?)");
    $complaints_data = [
        [$uids[0]??$admin_id,'A-101','Plumbing','Water leakage in bathroom','Constant leak from pipe under washbasin.','high','open','-1 day'],
        [$uids[1]??$admin_id,'A-102','Electrical','Power fluctuation in flat','Frequent voltage fluctuations damaging appliances.','high','in_progress','-2 days'],
        [$uids[2]??$admin_id,'A-201','Lift/Elevator','Lift making loud noise','Grinding noise on floor 4. Feels unsafe.','medium','in_progress','-3 days'],
        [$uids[3]??$admin_id,'B-101','Parking','Unauthorized vehicle in my spot','White Maruti Swift in my spot B-45.','low','resolved','-4 days'],
        [$uids[4]??$admin_id,'B-202','Security','Gate camera not working','CCTV at Gate 2 broken for 5 days.','high','open','-5 days'],
        [$uids[0]??$admin_id,'B-301','Noise','Loud music from flat B-302','Loud music past 11PM regularly.','medium','open','-6 days'],
        [$uids[1]??$admin_id,'C-101','Water Supply','No hot water in Block C','Hot water not working 3 days.','medium','in_progress','-7 days'],
        [$uids[2]??$admin_id,'C-201','Housekeeping','Common area not cleaned','Lobby dirty for 4 days.','low','resolved','-8 days'],
        [$uids[3]??$admin_id,'D-101','Plumbing','Drain blockage in kitchen','Kitchen drain completely blocked.','high','open','-9 days'],
        [$uids[4]??$admin_id,'E-101','Common Area','Garden lights not working','All garden lights near park off.','medium','resolved','-10 days'],
    ];
    foreach ($complaints_data as $cd) {
        $stmt->execute([$cd[0],$cd[1],$cd[2],$cd[3],$cd[4],$cd[5],$cd[6],date('Y-m-d H:i:s',strtotime($cd[7]))]);
    }
    ok("Seeded <b>".count($complaints_data)." complaints</b>");
} else { ok("Complaints: already has ".($pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn())." rows"); }

// ── Step 9: Seed billing for all users
if ($pdo->query("SELECT COUNT(*) FROM billing")->fetchColumn() == 0 && !empty($all_users)) {
    $months = [
        ['May 2026', date('Y-m-t'),                                      'pending', null],
        ['Apr 2026', date('Y-m-d', strtotime('last day of -1 month')),   'paid',    date('Y-m-d', strtotime('-35 days'))],
        ['Mar 2026', date('Y-m-d', strtotime('last day of -2 month')),   'paid',    date('Y-m-d', strtotime('-65 days'))],
        ['Feb 2026', date('Y-m-d', strtotime('last day of -3 month')),   'paid',    date('Y-m-d', strtotime('-95 days'))],
        ['Jan 2026', date('Y-m-d', strtotime('last day of -4 month')),   'overdue', null],
    ];
    $stmt = $pdo->prepare("INSERT INTO billing (invoice_no,user_id,unit,description,amount,month,due_date,status,paid_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $inv = 1;
    foreach ($all_users as $u) {
        foreach ($months as $m) {
            $stmt->execute([
                'INV-'.date('Y').'-'.str_pad($inv++,3,'0',STR_PAD_LEFT),
                $u['id'],
                ($u['block']??'A').'-'.($u['unit']??'101'),
                'Monthly Maintenance',
                2500,
                $m[0], $m[1], $m[2], $m[3]
            ]);
        }
    }
    ok("Seeded billing: ".count($all_users)." users × ".count($months)." months = <b>".$pdo->query("SELECT COUNT(*) FROM billing")->fetchColumn()." records</b>");
} else { ok("Billing: already has ".($pdo->query("SELECT COUNT(*) FROM billing")->fetchColumn())." rows"); }

// ── Step 10: Final check
ok("<br><b>✅ FINAL TABLE STATUS:</b>");
$tables = ['users','notices','complaints','billing','events','gate_visitors','gate_deliveries','gate_vehicles'];
foreach ($tables as $t) {
    try { ok("&nbsp;&nbsp; $t → ".$pdo->query("SELECT COUNT(*) FROM `$t`")->fetchColumn()." rows"); }
    catch(Exception $e) { er("&nbsp;&nbsp; $t → MISSING"); }
}
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Patch DB</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#f5f7f6;padding:24px;}
.wrap{max-width:800px;margin:0 auto;}
h1{font-size:1.4rem;font-weight:700;color:#1a2e22;margin-bottom:16px;}
.log{padding:9px 14px;border-radius:8px;font-size:.84rem;margin-bottom:5px;display:flex;align-items:flex-start;gap:8px;}
.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.actions{display:flex;gap:10px;margin-top:20px;flex-wrap:wrap;}
.btn{padding:10px 20px;border-radius:9px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;border:none;}
.g{background:#1e7a50;color:#fff;}.b{background:#1d4ed8;color:#fff;}.w{background:#fff;color:#5a7060;border:1.5px solid #e0ece6;}
.creds{background:#1a2e22;color:#a7f3d0;border-radius:10px;padding:16px;margin-top:16px;font-size:.83rem;line-height:1.9;}
.warn{background:#fff7ed;border:1px solid #fed7aa;color:#92400e;padding:11px 14px;border-radius:9px;font-size:.8rem;margin-top:12px;}
</style>
</head>
<body>
<div class="wrap">
<h1>🔧 Emergency DB Patch — ColonyCare</h1>
<?php foreach($log as [$t,$m]): ?>
<div class="log <?= $t ?>"><span><?= $t==='ok'?'✅':'❌' ?></span><span><?= $m ?></span></div>
<?php endforeach; ?>

<div class="creds">
  <b>🔑 Login Credentials (password: admin123)</b><br>
  👑 Admin &nbsp;&nbsp;→ rajesh@colony.com<br>
  🏠 Resident → sunita@colony.com · amit@colony.com · priya@colony.com<br>
  👷 Staff &nbsp;&nbsp;→ ravi@colony.com
</div>

<div class="actions">
  <a href="/SHIVAM/login.php"              class="btn g">→ Login Page</a>
  <a href="/SHIVAM/resident_dashboard.php" class="btn g">→ Resident Dashboard</a>
  <a href="/SHIVAM/dashboard.php"          class="btn b">→ Admin Dashboard</a>
  <a href="/SHIVAM/db_inspect.php"         class="btn w">🔍 Inspect Tables</a>
  <a href="/SHIVAM/patch_db.php"           class="btn w">↺ Run Again</a>
</div>
<div class="warn">⚠️ Delete <b>patch_db.php</b> from your server after fixing!</div>
</div>
</body>
</html>
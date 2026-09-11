<?php


define('DB_HOST', 'localhost');
define('DB_NAME', 'cc');
define('DB_USER', 'root');
define('DB_PASS', '');

$log = [];

function ok($msg)  { global $log; $log[] = ['ok',  $msg]; }
function err($msg) { global $log; $log[] = ['err', $msg]; }
function hd($msg)  { global $log; $log[] = ['hd',  $msg]; }

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS, [
    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
]);
ok("Connected to <strong>".DB_NAME."</strong>");

// ════════════════════════════════════════
// OPTION: Set to true to wipe & re-seed
// ════════════════════════════════════════
$FRESH = isset($_GET['fresh']) && $_GET['fresh'] == '1';

if ($FRESH) {
    $tables = ['household_members','notice_reads','notice_reads','gate_visitors','gate_deliveries','gate_vehicles','vendor_tickets','vendor_payments','vendor_contracts','expenses','budget','billing','complaints','events','notices'];
    foreach ($tables as $t) {
        try { $pdo->exec("DELETE FROM $t"); } catch(Exception $e){}
    }
    // Don't delete users — keep existing logins
    ok("🧹 Cleared all trial data tables (users kept)");
}

// ════════════════════════════════════════
// 1. USERS
// ════════════════════════════════════════
hd("👥 USERS");

$existing_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();

$sample_users = [
    ['Rajesh Kumar',   'rajesh@colony.com',  'admin123', 'admin',    'A', '101', '+91 98765 11111', 'Green Valley Township', 1],
    ['Sunita Mehta',   'sunita@colony.com',  'admin123', 'resident', 'A', '102', '+91 98765 22222', 'Green Valley Township', 1],
    ['Amit Sharma',    'amit@colony.com',    'admin123', 'resident', 'A', '201', '+91 98765 33333', 'Green Valley Township', 1],
    ['Priya Singh',    'priya@colony.com',   'admin123', 'resident', 'B', '101', '+91 98765 44444', 'Green Valley Township', 1],
    ['Vikram Patel',   'vikram@colony.com',  'admin123', 'resident', 'B', '202', '+91 98765 55555', 'Sunrise Towers',        1],
    ['Anita Verma',    'anita@colony.com',   'admin123', 'resident', 'B', '301', '+91 98765 66666', 'Sunrise Towers',        1],
    ['Ravi Kumar',     'ravi@colony.com',    'admin123', 'staff',    'C', '101', '+91 98765 77777', 'Green Valley Township', 1],
    ['Deepak Joshi',   'deepak@colony.com',  'admin123', 'resident', 'C', '201', '+91 98765 88888', 'Green Valley Township', 1],
    ['Kavita Rao',     'kavita@colony.com',  'admin123', 'resident', 'C', '302', '+91 98765 99999', 'Lakeside Villas',       1],
    ['Mohit Agarwal',  'mohit@colony.com',   'admin123', 'resident', 'D', '101', '+91 87654 11111', 'Lakeside Villas',       1],
    ['Neha Gupta',     'neha@colony.com',    'admin123', 'resident', 'D', '202', '+91 87654 22222', 'Sunrise Towers',        1],
    ['Suresh Iyer',    'suresh@colony.com',  'admin123', 'staff',    'D', '301', '+91 87654 33333', 'Green Valley Township', 1],
    ['Pooja Nair',     'pooja@colony.com',   'admin123', 'resident', 'E', '101', '+91 87654 44444', 'Green Valley Township', 1],
    ['Arjun Reddy',    'arjun@colony.com',   'admin123', 'resident', 'E', '201', '+91 87654 55555', 'Lakeside Villas',       1],
    ['Meena Desai',    'meena@colony.com',   'admin123', 'resident', 'E', '302', '+91 87654 66666', 'Sunrise Towers',        0], // pending
];

$added_users = 0;
foreach ($sample_users as $u) {
    $check = $pdo->prepare("SELECT id FROM users WHERE email=?"); $check->execute([$u[1]]);
    if (!$check->fetch()) {
        $hash = password_hash($u[2], PASSWORD_BCRYPT);
        $pdo->prepare("INSERT INTO users (name,email,password,role,block,unit,phone,society,is_active) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$u[0],$u[1],$hash,$u[3],$u[4],$u[5],$u[6],$u[7],$u[8]]);
        $added_users++;
    }
}
$total_users = $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
ok("Users: $added_users new added · Total: $total_users users in DB");
ok("📝 Login with any email above + password: <strong>admin123</strong>");

// Get user IDs for foreign keys
$all_users   = $pdo->query("SELECT id,name,unit,block,role FROM users")->fetchAll();
$admin_user  = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetch();
$admin_id    = $admin_user['id'] ?? $all_users[0]['id'];
$resident_ids= array_column(array_filter($all_users, fn($u)=>$u['role']==='resident'), 'id');
if (empty($resident_ids)) $resident_ids = array_column($all_users, 'id');

// ════════════════════════════════════════
// 2. NOTICES
// ════════════════════════════════════════
hd("📢 NOTICES");
if ($pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn() < 3) {
    $notices = [
        ['🚰 Water Supply Disruption – Block C', 'No water supply on 28th May, 10am–2pm for overhead tank cleaning. Please store water in advance. We apologize for the inconvenience.', 'high', '-1 day'],
        ['📅 Annual General Meeting – June 10',  'AGM scheduled on 10th June at 6:00 PM in Clubhouse Hall. Agenda: Budget review, maintenance charges, new committee elections. All residents must attend.', 'medium', '-3 days'],
        ['🚗 New Parking Rules Effective June 1', 'Each unit is allocated 1 parking spot. Visitor parking is limited to 4 hours in designated zones only. Violating vehicles will be towed.', 'low', '-5 days'],
        ['🎉 Diwali Celebration – May 30',        'Community Diwali night with music, food stalls, and fireworks. Families are requested to join at Open Ground at 7:00 PM. Entry free!', 'low', '-7 days'],
        ['💰 Maintenance Charges Revised FY26-27','Monthly maintenance increased from ₹2,000 to ₹2,500 effective June 2026. This covers enhanced security, landscaping, and lift AMC.', 'high', '-10 days'],
        ['🔧 Lift Maintenance – A & B Block',     'Lift in A and B block will be under maintenance on 25th May from 9AM to 1PM. Please use stairs. Emergency contact: 98765-00000.', 'medium', '-12 days'],
        ['🌱 Tree Plantation Drive',               'Join us on 1st June for a tree plantation drive in the society garden. Saplings will be provided. Volunteers needed!', 'low', '-15 days'],
    ];
    $stmt = $pdo->prepare("INSERT INTO notices (title,body,priority,created_by,created_at) VALUES (?,?,?,?,?)");
    foreach ($notices as $n) {
        $stmt->execute([$n[0],$n[1],$n[2],$admin_id,date('Y-m-d H:i:s',strtotime($n[3]))]);
    }
    ok("Inserted " . count($notices) . " notices");
} else { ok("Notices already have data — skipped"); }

// ════════════════════════════════════════
// 3. COMPLAINTS
// ════════════════════════════════════════
hd("💬 COMPLAINTS");
if ($pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn() < 3) {
    $complaints = [
        [$resident_ids[0]??$admin_id, 'A-101', 'Plumbing',      'Water leakage in bathroom',         'There is a constant water leak from the pipe under the washbasin. Water is dripping on the floor continuously.',  'high',   'open'],
        [$resident_ids[1]??$admin_id, 'A-102', 'Electrical',    'Power fluctuation in flat',          'Frequent voltage fluctuations are damaging our appliances. This has been happening for the past 2 weeks.',          'high',   'in_progress'],
        [$resident_ids[2]??$admin_id, 'A-201', 'Lift/Elevator', 'Lift making loud noise',             'The lift in A block makes a grinding noise when going to floor 4. It feels unsafe. Please check immediately.',      'medium', 'in_progress'],
        [$resident_ids[3]??$admin_id, 'B-101', 'Parking',       'Unauthorized vehicle in my spot',    'A white Maruti Swift is regularly parked in my designated parking spot (B-45). Request removal.',                   'low',    'resolved'],
        [$resident_ids[4]??$admin_id, 'B-202', 'Security',      'Gate camera not working',            'The CCTV camera at Gate 2 has been non-functional for 5 days. This is a security concern.',                        'high',   'open'],
        [$resident_ids[5]??$admin_id, 'B-301', 'Noise',         'Loud music from flat B-302',         'Flat B-302 plays loud music late at night (past 11 PM) regularly. Multiple complaints already raised verbally.',    'medium', 'open'],
        [$resident_ids[6]??$admin_id, 'C-101', 'Water Supply',  'No hot water in Block C',            'Hot water supply has not been working in Block C for 3 days. Please fix the geyser in the overhead supply line.',   'medium', 'in_progress'],
        [$resident_ids[7]??$admin_id, 'C-201', 'Housekeeping',  'Common area not cleaned regularly',  'The lobby and staircase of C block have not been cleaned for 4 days. There is garbage accumulated near floor 2.',   'low',    'resolved'],
        [$resident_ids[8]??$admin_id, 'C-302', 'Common Area',   'Garden lights not working',          'All garden lights near the children park are not working. This makes it unsafe for evening walks.',                 'medium', 'resolved'],
        [$resident_ids[9]??$admin_id, 'D-101', 'Plumbing',      'Drain blockage in kitchen',          'Kitchen drain is completely blocked. Water is not draining at all. Plumber visit needed urgently.',                 'high',   'open'],
    ];
    $stmt = $pdo->prepare("INSERT INTO complaints (user_id,unit,category,subject,description,priority,status,created_at) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($complaints as $i => $c) {
        $stmt->execute([$c[0],$c[1],$c[2],$c[3],$c[4],$c[5],$c[6], date('Y-m-d H:i:s',strtotime('-'.($i*2+1).' days'))]);
    }
    ok("Inserted " . count($complaints) . " complaints");
} else { ok("Complaints already have data — skipped"); }

// ════════════════════════════════════════
// 4. BILLING
// ════════════════════════════════════════
hd("💰 BILLING");

// Fix billing table — add missing columns
try { $pdo->exec("ALTER TABLE billing ADD COLUMN IF NOT EXISTS invoice_no VARCHAR(20) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE billing ADD COLUMN IF NOT EXISTS month VARCHAR(20) DEFAULT ''"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE billing ADD COLUMN IF NOT EXISTS due_date DATE NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE billing ADD COLUMN IF NOT EXISTS paid_at DATETIME NULL"); } catch(Exception $e){}
try { $pdo->exec("ALTER TABLE billing ADD COLUMN IF NOT EXISTS unit VARCHAR(20) DEFAULT ''"); } catch(Exception $e){}

if ($pdo->query("SELECT COUNT(*) FROM billing")->fetchColumn() < 3) {
    $months = [
        ['May 2026', '2026-05-31', 'pending'],
        ['Apr 2026', '2026-04-30', 'paid'],
        ['Mar 2026', '2026-03-31', 'paid'],
        ['Feb 2026', '2026-02-28', 'paid'],
        ['Jan 2026', '2026-01-31', 'overdue'],
    ];
    $stmt = $pdo->prepare("INSERT INTO billing (invoice_no,user_id,unit,description,amount,month,due_date,status,paid_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $inv = 1;
    foreach ($all_users as $u) {
        foreach ($months as $m) {
            $paid_at = $m[2]==='paid' ? date('Y-m-d', strtotime($m[1].' -5 days')) : null;
            $stmt->execute([
                'INV-'.date('Y').'-'.str_pad($inv++,3,'0',STR_PAD_LEFT),
                $u['id'], ($u['block']??'A').'-'.($u['unit']??'101'),
                'Monthly Maintenance', 2500,
                $m[0], $m[1], $m[2], $paid_at
            ]);
        }
    }
    ok("Inserted billing records for " . count($all_users) . " users × " . count($months) . " months");
} else { ok("Billing already has data — skipped"); }

// ════════════════════════════════════════
// 5. EVENTS
// ════════════════════════════════════════
hd("📅 EVENTS");
if ($pdo->query("SELECT COUNT(*) FROM events")->fetchColumn() < 3) {
    $events = [
        ['Annual General Meeting',          'Mandatory meeting for all residents. Agenda: annual budget, maintenance charges, security upgrade, new RWA committee elections.',         '2026-06-10', '6:00 PM',  'Clubhouse Hall'],
        ['Community Diwali Night',          'Celebrate Diwali together with music, dance, food stalls, and fireworks. Bring your family!',                                           '2026-06-12', '7:00 PM',  'Open Ground'],
        ['Quarterly Maintenance Drive',     'Plumbing, electrical, and civil inspection of all blocks. Residents must be present between 9AM and 12PM.',                             '2026-06-18', '9:00 AM',  'All Blocks'],
        ['Morning Yoga Camp',               'Free yoga and meditation sessions every Sunday morning. Open for all ages. Yoga mats provided.',                                        '2026-06-22', '7:00 AM',  'Terrace Garden'],
        ['Kids Painting Competition',       'Art competition for children aged 5–15. Theme: My Dream Home. Cash prizes for top 3 winners. Registration required.',                  '2026-06-28', '10:00 AM', 'Community Hall'],
        ['Resident Cricket Tournament',     'Inter-block cricket tournament. Teams of 8 players each. Register your block team with the RWA office by June 5.',                     '2026-07-04', '8:00 AM',  'Society Ground'],
        ['Safety & First Aid Workshop',     'Free workshop on fire safety, first aid, and emergency response conducted by certified instructors. Attendance is encouraged.',          '2026-07-10', '4:00 PM',  'Clubhouse Hall'],
        ['Independence Day Celebration',    'Flag hoisting at 8AM followed by cultural programs, games for kids, and community lunch. All residents are invited.',                   '2026-08-15', '8:00 AM',  'Main Entrance'],
    ];
    $stmt = $pdo->prepare("INSERT INTO events (title,description,event_date,event_time,location) VALUES (?,?,?,?,?)");
    foreach ($events as $ev) $stmt->execute($ev);
    ok("Inserted " . count($events) . " events");
} else { ok("Events already have data — skipped"); }

// ════════════════════════════════════════
// 6. GATE VISITORS
// ════════════════════════════════════════
hd("🚪 GATE VISITORS");
if ($pdo->query("SELECT COUNT(*) FROM gate_visitors")->fetchColumn() < 3) {
    $visitors = [
        ['V-301', 'Amit Verma',         'Guest',    '201-A', '98765-43210', '4829', 'waiting',  0, '-2 hours'],
        ['V-302', 'Swiggy Delivery',    'Delivery', '102-B', '',            '7234', 'allowed',  1, '-3 hours'],
        ['V-303', 'Ravi Plumber',       'Service',  '305-A', '97654-32109', '5512', 'waiting',  0, '-4 hours'],
        ['V-304', 'Amazon Delivery',    'Delivery', '101-A', '',            '3341', 'allowed',  1, '-5 hours'],
        ['V-305', 'Sunita Guest',       'Guest',    'A-201', '91234-56789', '6623', 'allowed',  0, '-6 hours'],
        ['V-306', 'Flipkart Courier',   'Delivery', 'B-101', '',            '8891', 'denied',   0, '-1 day'],
        ['V-307', 'AC Technician',      'Service',  'C-302', '99887-65432', '2245', 'allowed',  1, '-1 day'],
        ['V-308', 'Meera Sharma',       'Guest',    'D-101', '98712-34567', '9934', 'waiting',  0, '-2 days'],
        ['V-309', 'Ola Cab Driver',     'Cab',      'A-102', '',            '1122', 'allowed',  0, '-2 days'],
        ['V-310', 'Zomato Delivery',    'Delivery', 'E-201', '',            '4477', 'allowed',  1, '-3 days'],
    ];
    $stmt = $pdo->prepare("INSERT INTO gate_visitors (visitor_no,name,type,flat,phone,otp,status,pre_approved,entry_time,logged_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach ($visitors as $v) {
        $stmt->execute([$v[0],$v[1],$v[2],$v[3],$v[4],$v[5],$v[6],$v[7], date('Y-m-d H:i:s',strtotime($v[8])), $admin_id]);
    }
    ok("Inserted " . count($visitors) . " gate visitors");
} else { ok("Gate visitors already have data — skipped"); }

// ════════════════════════════════════════
// 7. GATE DELIVERIES
// ════════════════════════════════════════
hd("📦 GATE DELIVERIES");
if ($pdo->query("SELECT COUNT(*) FROM gate_deliveries")->fetchColumn() < 3) {
    $deliveries = [
        ['D-501', 'Amazon',     'Flat 101-A', '4521', 'delivered', '-5 hours'],
        ['D-502', 'Swiggy',     'Flat 302-B', '7832', 'pending',   '-4 hours'],
        ['D-503', 'Flipkart',   'Flat 201-A', '1298', 'delivered', '-3 hours'],
        ['D-504', 'BigBasket',  'Flat 405-A', '5647', 'pending',   '-2 hours'],
        ['D-505', 'Zomato',     'Flat 102-B', '3391', 'delivered', '-1 day'],
        ['D-506', 'Meesho',     'Flat 301-C', '8823', 'delivered', '-1 day'],
        ['D-507', 'Blinkit',    'Flat 202-D', '6614', 'pending',   '-2 days'],
        ['D-508', 'DTDC',       'Flat 101-E', '9977', 'delivered', '-2 days'],
    ];
    $stmt = $pdo->prepare("INSERT INTO gate_deliveries (delivery_no,service,recipient,otp,status,logged_at,logged_by) VALUES (?,?,?,?,?,?,?)");
    foreach ($deliveries as $d) {
        $stmt->execute([$d[0],$d[1],$d[2],$d[3],$d[4], date('Y-m-d H:i:s',strtotime($d[5])), $admin_id]);
    }
    ok("Inserted " . count($deliveries) . " deliveries");
} else { ok("Deliveries already have data — skipped"); }

// ════════════════════════════════════════
// 8. GATE VEHICLES
// ════════════════════════════════════════
hd("🚗 GATE VEHICLES");
if ($pdo->query("SELECT COUNT(*) FROM gate_vehicles")->fetchColumn() < 3) {
    $vehicles = [
        ['DL-4C-1234', 'Rajesh Kumar',   '101-A', 'inside',  null,       '-8 hours'],
        ['UP-14-5678', 'Guest Visitor',  '201-A', 'inside',  null,       '-6 hours'],
        ['HR-26-9012', 'Priya Singh',    '102-A', 'exited',  '-4 hours', '-8 hours'],
        ['DL-1R-3456', 'Delivery Van',   null,    'exited',  '-5 hours', '-6 hours'],
        ['MH-12-4567', 'Amit Sharma',    '201-A', 'inside',  null,       '-3 hours'],
        ['KA-09-8765', 'Suresh Iyer',    '301-D', 'exited',  '-1 hour',  '-5 hours'],
        ['TN-22-3344', 'Anita Verma',    '301-B', 'inside',  null,       '-2 hours'],
        ['RJ-45-6789', 'Mohit Agarwal',  '101-D', 'exited',  '-3 hours', '-7 hours'],
    ];
    $stmt = $pdo->prepare("INSERT INTO gate_vehicles (vehicle_no,owner,flat,status,exit_time,entry_time,logged_by) VALUES (?,?,?,?,?,?,?)");
    foreach ($vehicles as $v) {
        $exit = $v[4] ? date('Y-m-d H:i:s',strtotime($v[4])) : null;
        $stmt->execute([$v[0],$v[1],$v[2],$v[3],$exit, date('Y-m-d H:i:s',strtotime($v[5])), $admin_id]);
    }
    ok("Inserted " . count($vehicles) . " vehicles");
} else { ok("Vehicles already have data — skipped"); }

// ════════════════════════════════════════
// 9. VENDOR TICKETS
// ════════════════════════════════════════
hd("🔧 VENDOR TICKETS");
if ($pdo->query("SELECT COUNT(*) FROM vendor_tickets")->fetchColumn() < 3) {
    $tickets = [
        ['TKT-201','high',  'Green Valley',  'Fix water pump in Block B',            '2026-05-10','2026-05-22','Ravi Sharma',  '+91 99887 22222','in_progress'],
        ['TKT-198','medium','Green Valley',  'Replace garden sprinkler heads',        '2026-05-08','2026-05-24',null,           null,             'open'],
        ['TKT-195','low',   'Sunrise Towers','AC servicing – Clubhouse',             '2026-05-05','2026-05-18','Anil Verma',   '+91 99887 44444','completed'],
        ['TKT-190','high',  'Green Valley',  'Plumbing repair in Flat 303-A',         '2026-05-03','2026-05-15',null,           null,             'open'],
        ['TKT-185','medium','Lakeside Villas','Drainage blockage – B Wing',          '2026-05-01','2026-05-13','Suresh Kumar', '+91 99887 55555','completed'],
        ['TKT-180','high',  'Green Valley',  'Elevator cable inspection – A Block',  '2026-04-28','2026-05-10','Meena Desai',  '+91 99887 66666','completed'],
        ['TKT-175','medium','Sunrise Towers','Broken floor tile – Lobby',           '2026-04-25','2026-05-05',null,           null,             'open'],
        ['TKT-170','low',   'Lakeside Villas','Garden wall painting',               '2026-04-20','2026-04-30','Deepak Joshi', '+91 99887 77777','completed'],
    ];
    $stmt = $pdo->prepare("INSERT INTO vendor_tickets (ticket_no,priority,community,title,created_date,deadline,contact_name,contact_phone,status) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach ($tickets as $t) $stmt->execute($t);
    ok("Inserted " . count($tickets) . " vendor tickets");
} else { ok("Vendor tickets already have data - skipped"); }

// ════════════════════════════════════════
// 10. VENDOR PAYMENTS
// ════════════════════════════════════════
hd("💳 VENDOR PAYMENTS");
if ($pdo->query("SELECT COUNT(*) FROM vendor_payments")->fetchColumn() < 3) {
    $payments = [
        ['PAY-801','Green Valley Township', 240000,'2026-05-15','INV-V-301','received'],
        ['PAY-795','Sunrise Towers',         85000,'2026-05-10','INV-V-298','received'],
        ['PAY-790','Green Valley Township', 125000,'2026-04-28','INV-V-295','pending'],
        ['PAY-785','Lakeside Villas',        60000,'2026-04-20','INV-V-290','received'],
        ['PAY-780','Sunrise Towers',        175000,'2026-04-15','INV-V-285','received'],
        ['PAY-775','Green Valley Township',  45000,'2026-04-10','INV-V-280','pending'],
        ['PAY-770','Lakeside Villas',        95000,'2026-03-30','INV-V-275','received'],
    ];
    $stmt = $pdo->prepare("INSERT INTO vendor_payments (pay_no,community,amount,pay_date,invoice_no,status) VALUES (?,?,?,?,?,?)");
    foreach ($payments as $p) $stmt->execute($p);
    ok("Inserted " . count($payments) . " vendor payments");
} else { ok("Vendor payments already have data — skipped"); }

// ════════════════════════════════════════
// 11. VENDOR CONTRACTS
// ════════════════════════════════════════
hd("📄 VENDOR CONTRACTS");
if ($pdo->query("SELECT COUNT(*) FROM vendor_contracts")->fetchColumn() < 2) {
    $contracts = [
        ['Green Valley Township', 'Plumbing & Water Supply',  '2026-01-01','2026-12-31', 2880000, 'active'],
        ['Sunrise Towers',        'Plumbing Maintenance',     '2025-03-01','2026-02-28', 1020000, 'renewal_due'],
        ['Lakeside Villas',       'Emergency Plumbing',       '2025-06-01','2026-05-31',  720000, 'active'],
        ['Green Valley Township', 'Electrical Maintenance',   '2026-01-01','2026-12-31', 1440000, 'active'],
        ['Sunrise Towers',        'Lift AMC',                 '2025-04-01','2026-03-31',  960000, 'renewal_due'],
    ];
    $stmt = $pdo->prepare("INSERT INTO vendor_contracts (community,service_type,period_start,period_end,contract_value,status) VALUES (?,?,?,?,?,?)");
    foreach ($contracts as $c) $stmt->execute($c);
    ok("Inserted " . count($contracts) . " vendor contracts");
} else { ok("Vendor contracts already have data — skipped"); }

// ════════════════════════════════════════
// 12. EXPENSES
// ════════════════════════════════════════
hd("📊 EXPENSES");
if ($pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn() < 3) {
    $expenses = [
        ['EXP-401','Security',         'SafeGuard Security',  240000,'2026-05-15','attached','paid'],
        ['EXP-402','Gardening',        'GreenCare Pvt Ltd',    85000,'2026-05-14','attached','paid'],
        ['EXP-403','Electrical',       'ElectroPro Services', 125000,'2026-05-12','attached','pending'],
        ['EXP-404','Water Supply',     'Municipal Corp',       45000,'2026-05-10','missing', 'paid'],
        ['EXP-405','Lift Maintenance', 'Otis Elevators',       35000,'2026-05-08','attached','paid'],
        ['EXP-406','Housekeeping',     'CleanMax Services',    55000,'2026-05-05','attached','paid'],
        ['EXP-407','Painting',         'ColorTech Painters',   90000,'2026-04-28','attached','paid'],
        ['EXP-408','Security',         'SafeGuard Security',  240000,'2026-04-15','attached','paid'],
        ['EXP-409','Electrical',       'ElectroPro Services',  78000,'2026-04-10','missing', 'pending'],
        ['EXP-410','Plumbing',         'AquaFix Plumbers',     32000,'2026-04-05','attached','paid'],
    ];
    $stmt = $pdo->prepare("INSERT INTO expenses (exp_no,category,vendor,amount,exp_date,bill_status,status) VALUES (?,?,?,?,?,?,?)");
    foreach ($expenses as $e) $stmt->execute($e);
    ok("Inserted " . count($expenses) . " expenses");
} else { ok("Expenses already have data — skipped"); }

// ════════════════════════════════════════
// 13. BUDGET
// ════════════════════════════════════════
hd("📈 BUDGET");
if ($pdo->query("SELECT COUNT(*) FROM budget")->fetchColumn() < 3) {
    $month = date('M Y');
    $budgets = [
        ['Security',       250000, 240000, $month],
        ['Landscaping',    100000,  85000, $month],
        ['Electrical',     100000, 125000, $month],
        ['Water Supply',    50000,  45000, $month],
        ['Maintenance',     40000,  35000, $month],
        ['Housekeeping',    60000,  55000, $month],
        ['Painting',       100000,  90000, $month],
        ['Lift AMC',        80000,  35000, $month],
    ];
    $stmt = $pdo->prepare("INSERT INTO budget (category,budgeted,actual,month) VALUES (?,?,?,?)");
    foreach ($budgets as $b) $stmt->execute($b);
    ok("Inserted " . count($budgets) . " budget rows");
} else { ok("Budget already has data — skipped"); }

// ════════════════════════════════════════
// 14. HOUSEHOLD MEMBERS
// ════════════════════════════════════════
hd("👨‍👩‍👧 HOUSEHOLD MEMBERS");
if ($pdo->query("SELECT COUNT(*) FROM household_members")->fetchColumn() < 3) {
    $households = [
        [$admin_id, 'Rajesh Kumar',  'Self (House Admin)', 45, 'full',      'COLONY-A101-RAJESH-001', 1, date('Y-m-d H:i:s')],
        [$admin_id, 'Sunita Kumar',  'Spouse',             42, 'full',      'COLONY-A101-SUNITA-002', 0, date('Y-m-d H:i:s', strtotime('-2 hours'))],
        [$admin_id, 'Arjun Kumar',   'Son',                19, 'limited',   'COLONY-A101-ARJUN-003',  0, date('Y-m-d H:i:s', strtotime('-1 day'))],
        [$admin_id, 'Priya Kumar',   'Daughter',           16, 'view_only', 'COLONY-A101-PRIYA-004',  0, date('Y-m-d H:i:s', strtotime('-3 days'))],
        [$admin_id, 'Kamla Devi',    'Mother',             70, 'view_only', 'COLONY-A101-KAMLA-005',  0, date('Y-m-d H:i:s', strtotime('-1 week'))],
    ];
    $stmt = $pdo->prepare("INSERT INTO household_members (house_admin,name,relation,age,access_level,qr_code,is_admin,last_seen) VALUES (?,?,?,?,?,?,?,?)");
    foreach ($households as $h) $stmt->execute($h);
    ok("Inserted " . count($households) . " household members");
} else { ok("Household members already have data — skipped"); }

// ════════════════════════════════════════
// SUMMARY
// ════════════════════════════════════════
hd("✅ FINAL SUMMARY");
$tables_check = ['users','notices','complaints','billing','events','gate_visitors','gate_deliveries','gate_vehicles','vendor_tickets','vendor_payments','vendor_contracts','expenses','budget','household_members'];
foreach ($tables_check as $t) {
    try { $cnt = $pdo->query("SELECT COUNT(*) FROM $t")->fetchColumn(); ok("<strong>$t</strong>: $cnt records"); }
    catch(Exception $e) { err("<strong>$t</strong>: table missing!"); }
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Seed Trial Data - ColonyCare</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#f5f7f6;min-height:100vh;padding:28px;}
.wrap{max-width:900px;margin:0 auto;}
h1{font-size:1.5rem;font-weight:700;color:#1a2e22;margin-bottom:5px;}
.sub{font-size:.85rem;color:#8fa898;margin-bottom:24px;}
.section-hd{font-size:.75rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#8fa898;margin:20px 0 8px;padding-bottom:4px;border-bottom:1px solid #e0ece6;}
.log-list{display:flex;flex-direction:column;gap:6px;}
.log{padding:10px 14px;border-radius:9px;font-size:.84rem;display:flex;align-items:center;gap:9px;}
.log.ok {background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.log.err{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.log.hd {background:transparent;padding:0;margin-top:16px;font-size:.72rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#5a7060;border:none;}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:24px;}
.btn{padding:10px 20px;border-radius:10px;font-family:inherit;font-size:.86rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;border:none;}
.btn-green{background:#1e7a50;color:#fff;}.btn-green:hover{background:#145f3f;}
.btn-red{background:#dc2626;color:#fff;}.btn-red:hover{background:#b91c1c;}
.btn-gray{background:#fff;color:#5a7060;border:1.5px solid #e0ece6;}
.warn{background:#fff7ed;border:1px solid #fed7aa;color:#92400e;padding:12px 16px;border-radius:9px;font-size:.82rem;margin-top:16px;}
.creds{background:#1a2e22;color:#a7f3d0;border-radius:10px;padding:16px 20px;margin-top:16px;font-size:.85rem;line-height:1.8;}
.creds strong{color:#6ee7b7;}
</style>
</head>
<body>
<div class="wrap">
  <h1>🌱 ColonyCare - Trial Data Seeder</h1>
  <div class="sub">Database: <strong><?= DB_NAME ?></strong> · <?= date('d M Y, h:i A') ?></div>

  <div class="log-list">
    <?php foreach($log as [$type,$msg]): ?>
    <?php if($type==='hd'): ?>
    <div class="log hd"><?= $msg ?></div>
    <?php else: ?>
    <div class="log <?= $type ?>">
      <?= $type==='ok'?'✅':'❌' ?> <span><?= $msg ?></span>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>
  </div>

  <div class="creds">
    <strong>🔑 Test Login Credentials (all use password: admin123)</strong><br>
    👑 Admin &nbsp;&nbsp;&nbsp;→ rajesh@colony.com / admin123<br>
    🏠 Resident → sunita@colony.com / admin123<br>
    🏠 Resident → amit@colony.com &nbsp;/ admin123<br>
    👷 Staff &nbsp;&nbsp;&nbsp;→ ravi@colony.com &nbsp;&nbsp;/ admin123<br>
    ⏳ Pending &nbsp;→ meena@colony.com &nbsp;/ admin123 (needs activation)
  </div>

  <div class="actions">
    <a href="/shivam/dashboard.php"          class="btn btn-green">→ Admin Dashboard</a>
    <a href="/shivam/login.php"              class="btn btn-green">→ Login Page</a>
    <a href="/shivam/seed_data.php?fresh=1"  class="btn btn-red"  onclick="return confirm('This will DELETE all trial data and re-seed. Continue?')">🔄 Fresh Re-seed</a>
    <a href="/shivam/db_setup.php"           class="btn btn-gray">🗄️ DB Setup</a>
  </div>

  <div class="warn">⚠️ <strong>Delete seed_data.php from your server</strong> once you are done testing!</div>
</div>
</body>
</html>
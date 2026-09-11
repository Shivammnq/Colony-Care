<?php

define('DB_HOST','localhost');
define('DB_NAME','cc');
define('DB_USER','root');
define('DB_PASS','');

$log = [];
function ok($m){global $log;$log[]=['ok',$m];}
function er($m){global $log;$log[]=['er',$m];}
function hd($m){global $log;$log[]=['hd',$m];}

$pdo = new PDO("mysql:host=".DB_HOST.";dbname=".DB_NAME.";charset=utf8mb4", DB_USER, DB_PASS,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
]);
ok("Connected to <strong>".DB_NAME."</strong>");

// Helper: add column if not exists
function addCol($pdo,$table,$col,$def){
    $cols = array_column($pdo->query("DESCRIBE `$table`")->fetchAll(),'Field');
    if(!in_array($col,$cols)){
        $pdo->exec("ALTER TABLE `$table` ADD COLUMN `$col` $def");
        return true;
    }
    return false;
}

// ════════════════════════════════════════
hd("🔧 FIXING TABLE STRUCTURES");
// ════════════════════════════════════════

// ── USERS
$pdo->exec("CREATE TABLE IF NOT EXISTS users (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    name       VARCHAR(100) NOT NULL,
    email      VARCHAR(180) NOT NULL UNIQUE,
    password   VARCHAR(255) NOT NULL,
    role       ENUM('resident','admin','staff') NOT NULL DEFAULT 'resident',
    is_active  TINYINT(1) NOT NULL DEFAULT 1,
    created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
addCol($pdo,'users','unit',     "VARCHAR(20) DEFAULT ''");
addCol($pdo,'users','block',    "VARCHAR(10) DEFAULT ''");
addCol($pdo,'users','phone',    "VARCHAR(15) DEFAULT ''");
addCol($pdo,'users','society',  "VARCHAR(100) DEFAULT ''");
addCol($pdo,'users','last_login',"DATETIME NULL");
ok("users ✔ — " . $pdo->query("SELECT COUNT(*) FROM users")->fetchColumn() . " rows");

// ── NOTICES
$pdo->exec("CREATE TABLE IF NOT EXISTS notices (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    body TEXT,
    priority ENUM('low','medium','high') DEFAULT 'medium',
    created_by INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("notices ✔ — " . $pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn() . " rows");

// ── NOTICE READS
$pdo->exec("CREATE TABLE IF NOT EXISTS notice_reads (
    user_id INT UNSIGNED NOT NULL,
    notice_id INT UNSIGNED NOT NULL,
    read_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY(user_id,notice_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("notice_reads ✔");

// ── COMPLAINTS
$pdo->exec("CREATE TABLE IF NOT EXISTS complaints (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    user_id INT UNSIGNED NOT NULL,
    unit VARCHAR(20),
    category VARCHAR(50),
    subject VARCHAR(150) NOT NULL,
    description TEXT,
    priority ENUM('low','medium','high') DEFAULT 'medium',
    status ENUM('open','in_progress','resolved','closed') DEFAULT 'open',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    updated_at DATETIME DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("complaints ✔ — " . $pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn() . " rows");

// ── BILLING — drop and recreate if structure is wrong
$billing_cols = array_column($pdo->query("DESCRIBE billing")->fetchAll(),'Field');
$needs_rebuild = !in_array('user_id',$billing_cols) || !in_array('invoice_no',$billing_cols);
if($needs_rebuild){
    $pdo->exec("DROP TABLE IF EXISTS billing");
    ok("Dropped old billing table (wrong structure)");
}
$pdo->exec("CREATE TABLE IF NOT EXISTS billing (
    id         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    invoice_no VARCHAR(20) DEFAULT '',
    user_id    INT UNSIGNED NOT NULL,
    unit       VARCHAR(20) DEFAULT '',
    description VARCHAR(200) NOT NULL,
    amount     DECIMAL(10,2) NOT NULL,
    month      VARCHAR(20) DEFAULT '',
    due_date   DATE,
    status     ENUM('pending','paid','overdue') DEFAULT 'pending',
    paid_at    DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("billing ✔ — " . $pdo->query("SELECT COUNT(*) FROM billing")->fetchColumn() . " rows");

// ── EVENTS
$pdo->exec("CREATE TABLE IF NOT EXISTS events (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    title VARCHAR(200) NOT NULL,
    description TEXT,
    event_date DATE NOT NULL,
    event_time VARCHAR(20),
    location VARCHAR(100),
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("events ✔ — " . $pdo->query("SELECT COUNT(*) FROM events")->fetchColumn() . " rows");

// ── GATE VISITORS
$pdo->exec("CREATE TABLE IF NOT EXISTS gate_visitors (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    visitor_no VARCHAR(10),
    name VARCHAR(100) NOT NULL,
    type ENUM('Guest','Delivery','Service','Cab','Other') DEFAULT 'Guest',
    flat VARCHAR(20),
    phone VARCHAR(15),
    otp VARCHAR(6),
    status ENUM('waiting','allowed','denied') DEFAULT 'waiting',
    pre_approved TINYINT(1) DEFAULT 0,
    entry_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    exit_time DATETIME NULL,
    logged_by INT UNSIGNED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("gate_visitors ✔ — " . $pdo->query("SELECT COUNT(*) FROM gate_visitors")->fetchColumn() . " rows");

// ── GATE DELIVERIES
$pdo->exec("CREATE TABLE IF NOT EXISTS gate_deliveries (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    delivery_no VARCHAR(10),
    service VARCHAR(50) NOT NULL,
    recipient VARCHAR(50) NOT NULL,
    otp VARCHAR(6) NOT NULL,
    status ENUM('pending','delivered') DEFAULT 'pending',
    logged_at DATETIME DEFAULT CURRENT_TIMESTAMP,
    received_at DATETIME NULL,
    logged_by INT UNSIGNED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("gate_deliveries ✔ — " . $pdo->query("SELECT COUNT(*) FROM gate_deliveries")->fetchColumn() . " rows");

// ── GATE VEHICLES
$pdo->exec("CREATE TABLE IF NOT EXISTS gate_vehicles (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    vehicle_no VARCHAR(20) NOT NULL,
    owner VARCHAR(100) NOT NULL,
    flat VARCHAR(20),
    entry_time DATETIME DEFAULT CURRENT_TIMESTAMP,
    exit_time DATETIME NULL,
    status ENUM('inside','exited') DEFAULT 'inside',
    logged_by INT UNSIGNED
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("gate_vehicles ✔ — " . $pdo->query("SELECT COUNT(*) FROM gate_vehicles")->fetchColumn() . " rows");

// ── VENDOR TICKETS
$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_tickets (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ticket_no VARCHAR(15),
    priority ENUM('low','medium','high') DEFAULT 'medium',
    community VARCHAR(100),
    title VARCHAR(200) NOT NULL,
    created_date DATE,
    deadline DATE,
    contact_name VARCHAR(100),
    contact_phone VARCHAR(20),
    status ENUM('open','in_progress','completed') DEFAULT 'open',
    vendor_id INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("vendor_tickets ✔ — " . $pdo->query("SELECT COUNT(*) FROM vendor_tickets")->fetchColumn() . " rows");

// ── VENDOR PAYMENTS
$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_payments (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    pay_no VARCHAR(15),
    community VARCHAR(100),
    amount DECIMAL(10,2) NOT NULL,
    pay_date DATE,
    invoice_no VARCHAR(20),
    status ENUM('received','pending') DEFAULT 'received',
    vendor_id INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("vendor_payments ✔ — " . $pdo->query("SELECT COUNT(*) FROM vendor_payments")->fetchColumn() . " rows");

// ── VENDOR CONTRACTS
$pdo->exec("CREATE TABLE IF NOT EXISTS vendor_contracts (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    community VARCHAR(100) NOT NULL,
    service_type VARCHAR(100),
    period_start DATE,
    period_end DATE,
    contract_value DECIMAL(10,2),
    status ENUM('active','renewal_due','expired') DEFAULT 'active',
    vendor_id INT UNSIGNED,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("vendor_contracts ✔ — " . $pdo->query("SELECT COUNT(*) FROM vendor_contracts")->fetchColumn() . " rows");

// ── EXPENSES
$pdo->exec("CREATE TABLE IF NOT EXISTS expenses (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    exp_no VARCHAR(20),
    category VARCHAR(50) NOT NULL,
    vendor VARCHAR(100),
    amount DECIMAL(10,2) NOT NULL,
    exp_date DATE NOT NULL,
    bill_status ENUM('attached','missing') DEFAULT 'attached',
    status ENUM('paid','pending') DEFAULT 'paid',
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("expenses ✔ — " . $pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn() . " rows");

// ── BUDGET
$pdo->exec("CREATE TABLE IF NOT EXISTS budget (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    category VARCHAR(50) NOT NULL,
    budgeted DECIMAL(10,2) NOT NULL,
    actual DECIMAL(10,2) NOT NULL,
    month VARCHAR(20) NOT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("budget ✔ — " . $pdo->query("SELECT COUNT(*) FROM budget")->fetchColumn() . " rows");

// ── HOUSEHOLD MEMBERS
$pdo->exec("CREATE TABLE IF NOT EXISTS household_members (
    id INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    house_admin INT UNSIGNED NOT NULL,
    name VARCHAR(100) NOT NULL,
    relation VARCHAR(50) NOT NULL,
    age INT UNSIGNED,
    access_level ENUM('full','limited','view_only') DEFAULT 'view_only',
    qr_code VARCHAR(100),
    is_admin TINYINT(1) DEFAULT 0,
    last_seen DATETIME NULL,
    created_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
ok("household_members ✔ — " . $pdo->query("SELECT COUNT(*) FROM household_members")->fetchColumn() . " rows");

// ════════════════════════════════════════
hd("🌱 SEEDING DATA");
// ════════════════════════════════════════

// ── USERS
$sample_users = [
    ['Rajesh Kumar',  'rajesh@colony.com',  'admin123', 'admin',    'A','101','+91 98765 11111','Green Valley Township',1],
    ['Sunita Mehta',  'sunita@colony.com',  'admin123', 'resident', 'A','102','+91 98765 22222','Green Valley Township',1],
    ['Amit Sharma',   'amit@colony.com',    'admin123', 'resident', 'A','201','+91 98765 33333','Green Valley Township',1],
    ['Priya Singh',   'priya@colony.com',   'admin123', 'resident', 'B','101','+91 98765 44444','Green Valley Township',1],
    ['Vikram Patel',  'vikram@colony.com',  'admin123', 'resident', 'B','202','+91 98765 55555','Sunrise Towers',1],
    ['Anita Verma',   'anita@colony.com',   'admin123', 'resident', 'B','301','+91 98765 66666','Sunrise Towers',1],
    ['Ravi Kumar',    'ravi@colony.com',    'admin123', 'staff',    'C','101','+91 98765 77777','Green Valley Township',1],
    ['Deepak Joshi',  'deepak@colony.com',  'admin123', 'resident', 'C','201','+91 98765 88888','Green Valley Township',1],
    ['Kavita Rao',    'kavita@colony.com',  'admin123', 'resident', 'C','302','+91 98765 99999','Lakeside Villas',1],
    ['Mohit Agarwal', 'mohit@colony.com',   'admin123', 'resident', 'D','101','+91 87654 11111','Lakeside Villas',1],
    ['Neha Gupta',    'neha@colony.com',    'admin123', 'resident', 'D','202','+91 87654 22222','Sunrise Towers',1],
    ['Suresh Iyer',   'suresh@colony.com',  'admin123', 'staff',    'D','301','+91 87654 33333','Green Valley Township',1],
    ['Pooja Nair',    'pooja@colony.com',   'admin123', 'resident', 'E','101','+91 87654 44444','Green Valley Township',1],
    ['Arjun Reddy',   'arjun@colony.com',   'admin123', 'resident', 'E','201','+91 87654 55555','Lakeside Villas',1],
    ['Meena Desai',   'meena@colony.com',   'admin123', 'resident', 'E','302','+91 87654 66666','Sunrise Towers',0],
];
$added=0;
foreach($sample_users as $u){
    $chk=$pdo->prepare("SELECT id FROM users WHERE email=?");$chk->execute([$u[1]]);
    if(!$chk->fetch()){
        $pdo->prepare("INSERT INTO users (name,email,password,role,block,unit,phone,society,is_active) VALUES (?,?,?,?,?,?,?,?,?)")
            ->execute([$u[0],$u[1],password_hash($u[2],PASSWORD_BCRYPT),$u[3],$u[4],$u[5],$u[6],$u[7],$u[8]]);
        $added++;
    }
}
ok("Users: $added new added · Total: ".$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn());

$all_users   = $pdo->query("SELECT id,name,unit,block,role FROM users ORDER BY id")->fetchAll();
$admin_id    = $pdo->query("SELECT id FROM users WHERE role='admin' LIMIT 1")->fetchColumn();
$resident_ids= array_column(array_filter($all_users,fn($u)=>$u['role']==='resident'),'id');

// ── NOTICES
if($pdo->query("SELECT COUNT(*) FROM notices")->fetchColumn()==0){
    $rows=[
        ['🚰 Water Supply Disruption – Block C','No water supply on 28th May 10am–2pm for tank cleaning. Please store water in advance.','high','-1 day'],
        ['📅 Annual General Meeting – June 10','AGM on 10th June at 6PM in Clubhouse. Agenda: Budget review, maintenance, elections.','medium','-3 days'],
        ['🚗 New Parking Rules Effective June 1','Each unit gets 1 spot. Visitor parking max 4 hours. Violations will be towed.','low','-5 days'],
        ['🎉 Diwali Celebration – May 30','Community Diwali night with music, food stalls and fireworks at Open Ground 7PM.','low','-7 days'],
        ['💰 Maintenance Charges Revised FY26-27','Monthly maintenance increased to ₹2,500 from June 2026 for enhanced services.','high','-10 days'],
        ['🔧 Lift Maintenance – A & B Block','Lift under maintenance 25th May 9AM–1PM. Please use stairs. Emergency: 98765-00000.','medium','-12 days'],
        ['🌱 Tree Plantation Drive','Join us 1st June for tree plantation in the garden. Saplings provided. Volunteers needed!','low','-15 days'],
    ];
    $stmt=$pdo->prepare("INSERT INTO notices (title,body,priority,created_by,created_at) VALUES (?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute([$r[0],$r[1],$r[2],$admin_id,date('Y-m-d H:i:s',strtotime($r[3]))]);
    ok("Seeded ".count($rows)." notices");
}else{ok("Notices: already has data — skipped");}

// ── COMPLAINTS
if($pdo->query("SELECT COUNT(*) FROM complaints")->fetchColumn()==0){
    $rows=[
        [$resident_ids[0],'A-101','Plumbing','Water leakage in bathroom','Constant leak from pipe under washbasin.','high','open','-1 day'],
        [$resident_ids[1],'A-102','Electrical','Power fluctuation in flat','Frequent voltage fluctuations damaging appliances.','high','in_progress','-2 days'],
        [$resident_ids[2],'A-201','Lift/Elevator','Lift making loud noise','Grinding noise when going to floor 4. Feels unsafe.','medium','in_progress','-3 days'],
        [$resident_ids[3],'B-101','Parking','Unauthorized vehicle in my spot','White Maruti Swift parked in my spot B-45 regularly.','low','resolved','-4 days'],
        [$resident_ids[4],'B-202','Security','Gate camera not working','CCTV at Gate 2 non-functional for 5 days.','high','open','-5 days'],
        [$resident_ids[5],'B-301','Noise','Loud music from flat B-302','Loud music past 11PM regularly.','medium','open','-6 days'],
        [$resident_ids[6],'C-101','Water Supply','No hot water in Block C','Hot water not working for 3 days in Block C.','medium','in_progress','-7 days'],
        [$resident_ids[7],'C-201','Housekeeping','Common area not cleaned','Lobby and staircase dirty for 4 days.','low','resolved','-8 days'],
        [$resident_ids[8],'C-302','Common Area','Garden lights not working','All garden lights near park not working.','medium','resolved','-9 days'],
        [$resident_ids[9],'D-101','Plumbing','Drain blockage in kitchen','Kitchen drain completely blocked. Urgent!','high','open','-10 days'],
    ];
    $stmt=$pdo->prepare("INSERT INTO complaints (user_id,unit,category,subject,description,priority,status,created_at) VALUES (?,?,?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute([$r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],date('Y-m-d H:i:s',strtotime($r[7]))]);
    ok("Seeded ".count($rows)." complaints");
}else{ok("Complaints: already has data — skipped");}

// ── BILLING
if($pdo->query("SELECT COUNT(*) FROM billing")->fetchColumn()==0){
    $months=[
        ['May 2026','2026-05-31','pending',null],
        ['Apr 2026','2026-04-30','paid','2026-04-25'],
        ['Mar 2026','2026-03-31','paid','2026-03-26'],
        ['Feb 2026','2026-02-28','paid','2026-02-23'],
        ['Jan 2026','2026-01-31','overdue',null],
    ];
    $stmt=$pdo->prepare("INSERT INTO billing (invoice_no,user_id,unit,description,amount,month,due_date,status,paid_at) VALUES (?,?,?,?,?,?,?,?,?)");
    $inv=1;
    foreach($all_users as $u){
        foreach($months as $m){
            $stmt->execute([
                'INV-'.date('Y').'-'.str_pad($inv++,3,'0',STR_PAD_LEFT),
                $u['id'],($u['block']??'A').'-'.($u['unit']??'101'),
                'Monthly Maintenance',2500,$m[0],$m[1],$m[2],$m[3]
            ]);
        }
    }
    ok("Seeded billing: ".count($all_users)." users × ".count($months)." months = ".$pdo->query("SELECT COUNT(*) FROM billing")->fetchColumn()." records");
}else{ok("Billing: already has data — skipped");}

// ── EVENTS
if($pdo->query("SELECT COUNT(*) FROM events")->fetchColumn()==0){
    $rows=[
        ['Annual General Meeting','Mandatory meeting. Agenda: annual budget, maintenance, elections.','2026-06-10','6:00 PM','Clubhouse Hall'],
        ['Community Diwali Night','Celebrate with music, food stalls and fireworks.','2026-06-12','7:00 PM','Open Ground'],
        ['Quarterly Maintenance Drive','Plumbing, electrical and civil inspection of all blocks.','2026-06-18','9:00 AM','All Blocks'],
        ['Morning Yoga Camp','Free yoga sessions every Sunday. Open for all ages.','2026-06-22','7:00 AM','Terrace Garden'],
        ['Kids Painting Competition','For children aged 5–15. Theme: My Dream Home. Prizes!','2026-06-28','10:00 AM','Community Hall'],
        ['Resident Cricket Tournament','Inter-block tournament. Teams of 8 players.','2026-07-04','8:00 AM','Society Ground'],
        ['Safety & First Aid Workshop','Fire safety and emergency response workshop.','2026-07-10','4:00 PM','Clubhouse Hall'],
        ['Independence Day Celebration','Flag hoisting, cultural programs and community lunch.','2026-08-15','8:00 AM','Main Entrance'],
    ];
    $stmt=$pdo->prepare("INSERT INTO events (title,description,event_date,event_time,location) VALUES (?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute($r);
    ok("Seeded ".count($rows)." events");
}else{ok("Events: already has data — skipped");}

// ── GATE VISITORS
if($pdo->query("SELECT COUNT(*) FROM gate_visitors")->fetchColumn()==0){
    $rows=[
        ['V-301','Amit Verma','Guest','201-A','98765-43210','4829','waiting',0,'-2 hours'],
        ['V-302','Swiggy Delivery','Delivery','102-B','','7234','allowed',1,'-3 hours'],
        ['V-303','Ravi Plumber','Service','305-A','97654-32109','5512','waiting',0,'-4 hours'],
        ['V-304','Amazon Delivery','Delivery','101-A','','3341','allowed',1,'-5 hours'],
        ['V-305','Sunita Guest','Guest','A-201','91234-56789','6623','allowed',0,'-6 hours'],
        ['V-306','Flipkart Courier','Delivery','B-101','','8891','denied',0,'-1 day'],
        ['V-307','AC Technician','Service','C-302','99887-65432','2245','allowed',1,'-1 day'],
        ['V-308','Meera Sharma','Guest','D-101','98712-34567','9934','waiting',0,'-2 days'],
        ['V-309','Ola Cab Driver','Cab','A-102','','1122','allowed',0,'-2 days'],
        ['V-310','Zomato Delivery','Delivery','E-201','','4477','allowed',1,'-3 days'],
    ];
    $stmt=$pdo->prepare("INSERT INTO gate_visitors (visitor_no,name,type,flat,phone,otp,status,pre_approved,entry_time,logged_by) VALUES (?,?,?,?,?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute([$r[0],$r[1],$r[2],$r[3],$r[4],$r[5],$r[6],$r[7],date('Y-m-d H:i:s',strtotime($r[8])),$admin_id]);
    ok("Seeded ".count($rows)." gate visitors");
}else{ok("Gate visitors: already has data — skipped");}

// ── GATE DELIVERIES
if($pdo->query("SELECT COUNT(*) FROM gate_deliveries")->fetchColumn()==0){
    $rows=[
        ['D-501','Amazon','Flat 101-A','4521','delivered','-5 hours'],
        ['D-502','Swiggy','Flat 302-B','7832','pending','-4 hours'],
        ['D-503','Flipkart','Flat 201-A','1298','delivered','-3 hours'],
        ['D-504','BigBasket','Flat 405-A','5647','pending','-2 hours'],
        ['D-505','Zomato','Flat 102-B','3391','delivered','-1 day'],
        ['D-506','Meesho','Flat 301-C','8823','delivered','-1 day'],
        ['D-507','Blinkit','Flat 202-D','6614','pending','-2 days'],
        ['D-508','DTDC','Flat 101-E','9977','delivered','-2 days'],
    ];
    $stmt=$pdo->prepare("INSERT INTO gate_deliveries (delivery_no,service,recipient,otp,status,logged_at,logged_by) VALUES (?,?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute([$r[0],$r[1],$r[2],$r[3],$r[4],date('Y-m-d H:i:s',strtotime($r[5])),$admin_id]);
    ok("Seeded ".count($rows)." deliveries");
}else{ok("Deliveries: already has data — skipped");}

// ── GATE VEHICLES
if($pdo->query("SELECT COUNT(*) FROM gate_vehicles")->fetchColumn()==0){
    $rows=[
        ['DL-4C-1234','Rajesh Kumar','101-A','inside',null,'-8 hours'],
        ['UP-14-5678','Guest Visitor','201-A','inside',null,'-6 hours'],
        ['HR-26-9012','Priya Singh','102-A','exited','-4 hours','-8 hours'],
        ['DL-1R-3456','Delivery Van',null,'exited','-5 hours','-6 hours'],
        ['MH-12-4567','Amit Sharma','201-A','inside',null,'-3 hours'],
        ['KA-09-8765','Suresh Iyer','301-D','exited','-1 hour','-5 hours'],
        ['TN-22-3344','Anita Verma','301-B','inside',null,'-2 hours'],
        ['RJ-45-6789','Mohit Agarwal','101-D','exited','-3 hours','-7 hours'],
    ];
    $stmt=$pdo->prepare("INSERT INTO gate_vehicles (vehicle_no,owner,flat,status,exit_time,entry_time,logged_by) VALUES (?,?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute([$r[0],$r[1],$r[2],$r[3],$r[4]?date('Y-m-d H:i:s',strtotime($r[4])):null,date('Y-m-d H:i:s',strtotime($r[5])),$admin_id]);
    ok("Seeded ".count($rows)." vehicles");
}else{ok("Vehicles: already has data — skipped");}

// ── VENDOR TICKETS
if($pdo->query("SELECT COUNT(*) FROM vendor_tickets")->fetchColumn()==0){
    $rows=[
        ['TKT-201','high','Green Valley','Fix water pump in Block B','2026-05-10','2026-05-22','Ravi Sharma','+91 99887 22222','in_progress'],
        ['TKT-198','medium','Green Valley','Replace garden sprinkler heads','2026-05-08','2026-05-24',null,null,'open'],
        ['TKT-195','low','Sunrise Towers','AC servicing – Clubhouse','2026-05-05','2026-05-18','Anil Verma','+91 99887 44444','completed'],
        ['TKT-190','high','Green Valley','Plumbing repair in Flat 303-A','2026-05-03','2026-05-15',null,null,'open'],
        ['TKT-185','medium','Lakeside Villas','Drainage blockage – B Wing','2026-05-01','2026-05-13','Suresh Kumar','+91 99887 55555','completed'],
        ['TKT-180','high','Green Valley','Elevator cable inspection','2026-04-28','2026-05-10','Meena Desai','+91 99887 66666','completed'],
        ['TKT-175','medium','Sunrise Towers','Broken floor tile – Lobby','2026-04-25','2026-05-05',null,null,'open'],
        ['TKT-170','low','Lakeside Villas','Garden wall painting','2026-04-20','2026-04-30','Deepak Joshi','+91 99887 77777','completed'],
    ];
    $stmt=$pdo->prepare("INSERT INTO vendor_tickets (ticket_no,priority,community,title,created_date,deadline,contact_name,contact_phone,status) VALUES (?,?,?,?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute($r);
    ok("Seeded ".count($rows)." vendor tickets");
}else{ok("Vendor tickets: already has data — skipped");}

// ── VENDOR PAYMENTS
if($pdo->query("SELECT COUNT(*) FROM vendor_payments")->fetchColumn()==0){
    $rows=[
        ['PAY-801','Green Valley Township',240000,'2026-05-15','INV-V-301','received'],
        ['PAY-795','Sunrise Towers',85000,'2026-05-10','INV-V-298','received'],
        ['PAY-790','Green Valley Township',125000,'2026-04-28','INV-V-295','pending'],
        ['PAY-785','Lakeside Villas',60000,'2026-04-20','INV-V-290','received'],
        ['PAY-780','Sunrise Towers',175000,'2026-04-15','INV-V-285','received'],
        ['PAY-775','Green Valley Township',45000,'2026-04-10','INV-V-280','pending'],
    ];
    $stmt=$pdo->prepare("INSERT INTO vendor_payments (pay_no,community,amount,pay_date,invoice_no,status) VALUES (?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute($r);
    ok("Seeded ".count($rows)." vendor payments");
}else{ok("Vendor payments: already has data — skipped");}

// ── VENDOR CONTRACTS
if($pdo->query("SELECT COUNT(*) FROM vendor_contracts")->fetchColumn()==0){
    $rows=[
        ['Green Valley Township','Plumbing & Water Supply','2026-01-01','2026-12-31',2880000,'active'],
        ['Sunrise Towers','Plumbing Maintenance','2025-03-01','2026-02-28',1020000,'renewal_due'],
        ['Lakeside Villas','Emergency Plumbing','2025-06-01','2026-05-31',720000,'active'],
        ['Green Valley Township','Electrical Maintenance','2026-01-01','2026-12-31',1440000,'active'],
        ['Sunrise Towers','Lift AMC','2025-04-01','2026-03-31',960000,'renewal_due'],
    ];
    $stmt=$pdo->prepare("INSERT INTO vendor_contracts (community,service_type,period_start,period_end,contract_value,status) VALUES (?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute($r);
    ok("Seeded ".count($rows)." vendor contracts");
}else{ok("Vendor contracts: already has data — skipped");}

// ── EXPENSES
if($pdo->query("SELECT COUNT(*) FROM expenses")->fetchColumn()==0){
    $rows=[
        ['EXP-401','Security','SafeGuard Security',240000,'2026-05-15','attached','paid'],
        ['EXP-402','Gardening','GreenCare Pvt Ltd',85000,'2026-05-14','attached','paid'],
        ['EXP-403','Electrical','ElectroPro Services',125000,'2026-05-12','attached','pending'],
        ['EXP-404','Water Supply','Municipal Corp',45000,'2026-05-10','missing','paid'],
        ['EXP-405','Lift Maintenance','Otis Elevators',35000,'2026-05-08','attached','paid'],
        ['EXP-406','Housekeeping','CleanMax Services',55000,'2026-05-05','attached','paid'],
        ['EXP-407','Painting','ColorTech Painters',90000,'2026-04-28','attached','paid'],
        ['EXP-408','Security','SafeGuard Security',240000,'2026-04-15','attached','paid'],
        ['EXP-409','Electrical','ElectroPro Services',78000,'2026-04-10','missing','pending'],
        ['EXP-410','Plumbing','AquaFix Plumbers',32000,'2026-04-05','attached','paid'],
    ];
    $stmt=$pdo->prepare("INSERT INTO expenses (exp_no,category,vendor,amount,exp_date,bill_status,status) VALUES (?,?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute($r);
    ok("Seeded ".count($rows)." expenses");
}else{ok("Expenses: already has data — skipped");}

// ── BUDGET
if($pdo->query("SELECT COUNT(*) FROM budget")->fetchColumn()==0){
    $mon=date('M Y');
    $rows=[
        ['Security',250000,240000,$mon],['Landscaping',100000,85000,$mon],
        ['Electrical',100000,125000,$mon],['Water Supply',50000,45000,$mon],
        ['Maintenance',40000,35000,$mon],['Housekeeping',60000,55000,$mon],
        ['Painting',100000,90000,$mon],['Lift AMC',80000,35000,$mon],
    ];
    $stmt=$pdo->prepare("INSERT INTO budget (category,budgeted,actual,month) VALUES (?,?,?,?)");
    foreach($rows as $r) $stmt->execute($r);
    ok("Seeded ".count($rows)." budget rows");
}else{ok("Budget: already has data — skipped");}

// ── HOUSEHOLD MEMBERS
if($pdo->query("SELECT COUNT(*) FROM household_members")->fetchColumn()==0){
    $rows=[
        [$admin_id,'Rajesh Kumar','Self (House Admin)',45,'full','COLONY-A101-RAJESH-001',1,date('Y-m-d H:i:s')],
        [$admin_id,'Sunita Kumar','Spouse',42,'full','COLONY-A101-SUNITA-002',0,date('Y-m-d H:i:s',strtotime('-2 hours'))],
        [$admin_id,'Arjun Kumar','Son',19,'limited','COLONY-A101-ARJUN-003',0,date('Y-m-d H:i:s',strtotime('-1 day'))],
        [$admin_id,'Priya Kumar','Daughter',16,'view_only','COLONY-A101-PRIYA-004',0,date('Y-m-d H:i:s',strtotime('-3 days'))],
        [$admin_id,'Kamla Devi','Mother',70,'view_only','COLONY-A101-KAMLA-005',0,date('Y-m-d H:i:s',strtotime('-1 week'))],
    ];
    $stmt=$pdo->prepare("INSERT INTO household_members (house_admin,name,relation,age,access_level,qr_code,is_admin,last_seen) VALUES (?,?,?,?,?,?,?,?)");
    foreach($rows as $r) $stmt->execute($r);
    ok("Seeded ".count($rows)." household members");
}else{ok("Household members: already has data — skipped");}

hd("✅ ALL DONE");
ok("Total tables: ".$pdo->query("SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='".DB_NAME."'")->fetchColumn());
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Fix DB - ColonyCare</title>
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:#f5f7f6;padding:24px;}
.wrap{max-width:860px;margin:0 auto;}
h1{font-size:1.4rem;font-weight:700;color:#1a2e22;margin-bottom:5px;}
.sub{font-size:.83rem;color:#8fa898;margin-bottom:22px;}
.log{padding:9px 14px;border-radius:9px;font-size:.83rem;display:flex;align-items:center;gap:9px;margin-bottom:6px;}
.log.ok{background:#f0fdf4;color:#166534;border:1px solid #bbf7d0;}
.log.er{background:#fef2f2;color:#991b1b;border:1px solid #fecaca;}
.log.hd{background:none;border:none;padding:14px 0 4px;font-size:.72rem;font-weight:700;letter-spacing:1px;text-transform:uppercase;color:#5a7060;margin-bottom:0;}
.creds{background:#1a2e22;color:#a7f3d0;border-radius:10px;padding:16px 20px;margin:20px 0;font-size:.84rem;line-height:1.9;}
.creds strong{color:#6ee7b7;}
.actions{display:flex;gap:10px;flex-wrap:wrap;margin-top:20px;}
.btn{padding:10px 20px;border-radius:10px;font-family:inherit;font-size:.85rem;font-weight:600;cursor:pointer;text-decoration:none;display:inline-flex;align-items:center;gap:7px;border:none;}
.g{background:#1e7a50;color:#fff;}.g:hover{background:#145f3f;}
.w{background:#fff;color:#5a7060;border:1.5px solid #e0ece6;}
.warn{background:#fff7ed;border:1px solid #fed7aa;color:#92400e;padding:11px 15px;border-radius:9px;font-size:.81rem;margin-top:14px;}
</style>
</head>
<body>
<div class="wrap">
  <h1>🔧 ColonyCare - DB Fix &amp; Seed</h1>
  <div class="sub">Database: <strong><?= DB_NAME ?></strong> · <?= date('d M Y, h:i A') ?></div>

  <?php foreach($log as [$t,$m]): ?>
  <div class="log <?= $t ?>">
    <?= $t==='ok'?'✅':($t==='er'?'❌':'') ?> <span><?= $m ?></span>
  </div>
  <?php endforeach; ?>

  <div class="creds">
    <strong>🔑 Test Login Credentials (password: admin123)</strong><br>
    👑 Admin &nbsp;&nbsp;→ rajesh@colony.com<br>
    🏠 Resident → sunita@colony.com &nbsp;·&nbsp; amit@colony.com &nbsp;·&nbsp; priya@colony.com<br>
    👷 Staff &nbsp;&nbsp;→ ravi@colony.com<br>
    ⏳ Pending → meena@colony.com (needs admin activation)
  </div>

  <div class="actions">
    <a href="/shivam/login.php"       class="btn g">→ Go to Login</a>
    <a href="/shivam/dashboard.php"   class="btn g">→ Admin Dashboard</a>
    <a href="/shivam/db_inspect.php"  class="btn w">🔍 Inspect Tables</a>
    <a href="/shivam/fix_db.php"      class="btn w">↺ Run Again</a>
  </div>
  <div class="warn">⚠️ Delete <strong>fix_db.php</strong>, <strong>seed_data.php</strong> and <strong>db_inspect.php</strong> from your server after testing!</div>
</div>
</body>
</html>
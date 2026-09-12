<?php
// ═══════════════════════════════════════════════════════════════
// Shared Super Admin sidebar + topbar.
// Include this AFTER session_start() + auth guard + your PDO block,
// and set $activeNav to one of the nav keys below before including.
// ═══════════════════════════════════════════════════════════════
$activeNav = $activeNav ?? '';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= isset($pageTitle) ? htmlspecialchars($pageTitle).' - ' : '' ?>Colony Care Superadmin</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">
<link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{
  --green:#0f8f6f;--green-dark:#0d2818;--green-btn:#2d7a52;--green-hover:#145f3f;--green-light:#e8f5ee;
  --text-primary:#1a2e22;--text-sub:#5a7060;--text-muted:#8fa898;--border:#e5ece8;--bg:#f5f7f6;--white:#fff;--radius:14px;
  --sidebar-w:260px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
body{font-family:'DM Sans',sans-serif;background:var(--bg);color:var(--text-primary);display:flex;min-height:100vh;}

/* ── SIDEBAR ── */
.sidebar{width:var(--sidebar-w);flex-shrink:0;background:linear-gradient(180deg,#0d2818,#122f1d);color:#fff;height:100vh;position:sticky;top:0;overflow-y:auto;display:flex;flex-direction:column;}
.sb-brand{display:flex;align-items:center;gap:10px;padding:20px 22px;}
.sb-brand-icon{width:36px;height:36px;border-radius:9px;background:var(--green);display:flex;align-items:center;justify-content:center;font-size:.95rem;}
.sb-brand-text{line-height:1.25;}
.sb-brand-text strong{display:block;font-size:1rem;font-weight:700;}
.sb-brand-text span{font-size:.7rem;color:rgba(255,255,255,.55);text-transform:uppercase;letter-spacing:.04em;}
.sb-group{padding:14px 14px 4px;}
.sb-group-label{font-size:.68rem;font-weight:700;color:rgba(255,255,255,.35);text-transform:uppercase;letter-spacing:.06em;padding:6px 10px;}
.sb-link{display:flex;align-items:center;gap:11px;padding:9px 12px;border-radius:9px;color:rgba(255,255,255,.75);text-decoration:none;font-size:.87rem;font-weight:500;margin-bottom:2px;transition:background .15s;}
.sb-link i{width:16px;text-align:center;font-size:.85rem;}
.sb-link:hover{background:rgba(255,255,255,.06);color:#fff;}
.sb-link.active{background:var(--green);color:#fff;font-weight:600;}
.sb-footer{margin-top:auto;padding:16px 22px;font-size:.7rem;color:rgba(255,255,255,.35);}

/* ── MAIN ── */
.main{flex:1;min-width:0;}
.topbar{background:var(--white);border-bottom:1px solid var(--border);padding:14px 28px;display:flex;align-items:center;gap:16px;position:sticky;top:0;z-index:10;}
.tb-search{flex:1;max-width:420px;position:relative;}
.tb-search input{width:100%;padding:10px 14px 10px 38px;border:1.5px solid var(--border);border-radius:10px;font-family:inherit;font-size:.85rem;outline:none;background:var(--bg);}
.tb-search i{position:absolute;left:14px;top:50%;transform:translateY(-50%);color:var(--text-muted);font-size:.85rem;}
.search-results{position:absolute;top:calc(100% + 8px);left:0;width:100%;min-width:380px;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:999;display:none;max-height:420px;overflow-y:auto;}
.search-results.open{display:block;}
.sr-group-label{font-size:.68rem;font-weight:700;color:var(--text-muted);text-transform:uppercase;letter-spacing:.04em;padding:10px 16px 6px;}
.sr-item{display:block;padding:9px 16px;text-decoration:none;color:var(--text-primary);font-size:.85rem;border-bottom:1px solid var(--border);}
.sr-item:hover{background:var(--bg);}
.sr-item .sr-sub{font-size:.74rem;color:var(--text-muted);margin-top:1px;}
.sr-empty{padding:24px 16px;text-align:center;color:var(--text-muted);font-size:.83rem;}
.tb-right{margin-left:auto;display:flex;align-items:center;gap:16px;}
.tb-notif{position:relative;width:38px;height:38px;border-radius:9px;background:var(--bg);display:flex;align-items:center;justify-content:center;color:var(--text-sub);cursor:pointer;border:none;font-size:1rem;}
.tb-notif .dot{position:absolute;top:8px;right:9px;width:7px;height:7px;border-radius:50%;background:#ef4444;display:none;}
.tb-notif .dot.show{display:block;}
.notif-wrap{position:relative;}
.notif-dropdown{position:absolute;top:calc(100% + 10px);right:0;width:340px;background:#fff;border:1px solid var(--border);border-radius:14px;box-shadow:0 8px 32px rgba(0,0,0,.12);z-index:999;display:none;overflow:hidden;}
.notif-dropdown.open{display:block;}
.notif-head{display:flex;align-items:center;justify-content:space-between;padding:14px 16px;border-bottom:1px solid var(--border);}
.notif-head span{font-weight:700;font-size:.9rem;color:var(--text-primary);}
.notif-mark-all{font-size:.75rem;color:var(--green);cursor:pointer;border:none;background:none;font-family:inherit;font-weight:600;}
.notif-list{max-height:380px;overflow-y:auto;}
.notif-item{display:flex;gap:10px;padding:12px 16px;border-bottom:1px solid var(--border);cursor:pointer;transition:background .15s;text-decoration:none;}
.notif-item:hover{background:var(--bg);}
.notif-item.unread{background:var(--green-light);}
.notif-icon{width:34px;height:34px;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:.8rem;flex-shrink:0;}
.ni-payment{background:#dbeafe;color:#1d4ed8;}
.ni-complaint{background:#fef9c3;color:#92400e;}
.ni-approval{background:#dcfce7;color:#15803d;}
.ni-rejection{background:#fee2e2;color:#dc2626;}
.ni-verification{background:#f3e8ff;color:#7e22ce;}
.notif-text{flex:1;min-width:0;}
.notif-msg{font-size:.8rem;color:var(--text-primary);font-weight:500;line-height:1.4;margin-bottom:2px;}
.notif-time{font-size:.7rem;color:var(--text-muted);}
.notif-empty{text-align:center;padding:28px 16px;color:var(--text-muted);font-size:.82rem;}
.tb-user{display:flex;align-items:center;gap:10px;}
.tb-avatar{width:38px;height:38px;border-radius:50%;background:var(--green);color:#fff;display:flex;align-items:center;justify-content:center;font-weight:700;font-size:.85rem;}
.tb-user-info{line-height:1.25;}
.tb-user-info strong{display:block;font-size:.85rem;}
.tb-user-info span{font-size:.72rem;color:var(--text-muted);}
.tb-logout{color:var(--text-muted);text-decoration:none;font-size:.8rem;margin-left:8px;}
.tb-logout:hover{color:#dc2626;}

.content{padding:26px 28px;}
.alert{padding:12px 16px;border-radius:10px;font-size:.87rem;margin-bottom:20px;display:flex;align-items:center;gap:10px;border:1px solid;}
.alert-error{background:#fef2f2;color:#991b1b;border-color:#fecaca;}
.alert-success{background:#f0fdf4;color:#0f8f6f;border-color:#bbf7d0;}

.tb-mobile-toggle{display:none;}
@media(max-width:900px){
  .sidebar{position:fixed;left:-260px;z-index:100;transition:left .2s;}
  .sidebar.open{left:0;}
  .tb-search{display:none;}
  .tb-mobile-toggle{display:flex;}
}
</style>
</head>
<body>

<nav class="sidebar" id="sbNav">
    <div class="sb-brand">
        <div class="sb-brand-icon"><i class="fa fa-border-all"></i></div>
        <div class="sb-brand-text"><strong>Colony Care</strong><span>Superadmin</span></div>
    </div>

    <div class="sb-group">
        <div class="sb-group-label">Overview</div>
        <a href="/super_admin.php" class="sb-link <?= $activeNav==='dashboard'?'active':'' ?>"><i class="fa fa-house"></i> Dashboard</a>
    </div>

    <div class="sb-group">
        <div class="sb-group-label">Operations</div>
        <a href="/super_admin_coming_soon.php?f=<?= urlencode('Services & Pricing') ?>" class="sb-link <?= $activeNav==='services'?'active':'' ?>"><i class="fa fa-screwdriver-wrench"></i> Services &amp; Pricing</a>
        <a href="/super_admin_coming_soon.php?f=<?= urlencode('Bookings / Enquiries') ?>" class="sb-link <?= $activeNav==='bookings'?'active':'' ?>"><i class="fa fa-calendar-check"></i> Bookings / Enquiries</a>
        <a href="/super_admin_coming_soon.php?f=<?= urlencode('Staff & Technicians') ?>" class="sb-link <?= $activeNav==='staff'?'active':'' ?>"><i class="fa fa-user-gear"></i> Staff &amp; Technicians</a>
    </div>

    <div class="sb-group">
        <div class="sb-group-label">Society</div>
        <a href="/super_admin_societies.php" class="sb-link <?= $activeNav==='societies'?'active':'' ?>"><i class="fa fa-city"></i> Societies</a>
        <a href="/super_admin_owners.php" class="sb-link <?= $activeNav==='owners'?'active':'' ?>"><i class="fa fa-user-shield"></i> Society Owners</a>
        <a href="/super_admin_residents.php" class="sb-link <?= $activeNav==='residents'?'active':'' ?>"><i class="fa fa-people-roof"></i> Residents</a>
        <a href="/super_admin_saleandrent.php" class="sb-link <?= $activeNav==='saleandrent'?'active':'' ?>"><i class="fa fa-key"></i> Sale &amp; Rent</a>
    </div>

    <div class="sb-group">
        <div class="sb-group-label">Business</div>
        <a href="/super_admin_coming_soon.php?f=<?= urlencode('Customers') ?>" class="sb-link <?= $activeNav==='customers'?'active':'' ?>"><i class="fa fa-user-group"></i> Customers</a>
        <a href="/super_admin_coming_soon.php?f=<?= urlencode('Accounts & Invoices') ?>" class="sb-link <?= $activeNav==='accounts'?'active':'' ?>"><i class="fa fa-file-invoice"></i> Accounts &amp; Invoices</a>
    </div>

    <div class="sb-group">
        <div class="sb-group-label">Website</div>
        <a href="/super_admin_coming_soon.php?f=<?= urlencode('Banners & Reviews') ?>" class="sb-link <?= $activeNav==='banners'?'active':'' ?>"><i class="fa fa-images"></i> Banners &amp; Reviews</a>
        <a href="/super_admin_pages.php" class="sb-link <?= $activeNav==='pages'?'active':'' ?>"><i class="fa fa-file-lines"></i> Pages</a>
        <a href="/super_admin_settings.php" class="sb-link <?= $activeNav==='settings'?'active':'' ?>"><i class="fa fa-gear"></i> Settings</a>
    </div>

    <div class="sb-footer">ColonyCare v1.0</div>
</nav>

<div class="main">
    <header class="topbar">
        <button class="tb-notif tb-mobile-toggle" id="sbToggle" onclick="document.getElementById('sbNav').classList.toggle('open')"><i class="fa fa-bars"></i></button>
        <div class="tb-search">
            <i class="fa fa-search"></i>
            <input type="text" id="sbSearchInput" placeholder="Search societies, residents, listings..." autocomplete="off">
            <div class="search-results" id="sbSearchResults"></div>
        </div>
        <div class="tb-right">
            <div class="notif-wrap">
                <button class="tb-notif" id="notifBell" onclick="toggleNotif(event)" title="Notifications">
                    <i class="fa fa-bell"></i><span class="dot" id="notifDot"></span>
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
            <div class="tb-user">
                <div class="tb-avatar"><?= strtoupper(substr($_SESSION['user_name'] ?? 'S', 0, 2)) ?></div>
                <div class="tb-user-info"><strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'Superadmin') ?></strong><span><?= htmlspecialchars($_SESSION['user_email'] ?? '') ?></span></div>
            </div>
            <a href="/auth/logout.php" class="tb-logout" title="Logout"><i class="fa fa-right-from-bracket"></i></a>
        </div>
    </header>

    <div class="content">
        <?php if (!empty($err)): ?><div class="alert alert-error"><i class="fa fa-circle-exclamation"></i> <?= htmlspecialchars($err) ?></div><?php endif; ?>
        <?php if (!empty($msg)): ?><div class="alert alert-success"><i class="fa fa-circle-check"></i> <?= htmlspecialchars($msg) ?></div><?php endif; ?>
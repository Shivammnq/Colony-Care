<?php 
$base_url = "http://localhost/shivam/";

// ── SEO: pages can set these before include('header.php') to override the defaults ──
// e.g. $pageTitle = '...'; $pageDescription = '...'; $pageCanonical = '...'; $pageImage = '...'; $pageRobots = 'noindex, nofollow'; $extraSchema = [...];
$pageTitle       = $pageTitle       ?? "ColonyCare - India's Smartest Colony Management Platform";
$pageDescription = $pageDescription ?? "Manage your residential society with ColonyCare — visitor management, billing, complaints, event calendar, facility booking, and online payments, all in one platform. Trusted by 500+ communities.";
$pageCanonical   = $pageCanonical   ?? ('https://www.example.com/shivam/' . basename($_SERVER['PHP_SELF'] ?? 'index.php'));
$pageRobots      = $pageRobots      ?? 'index, follow';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($pageTitle) ?></title>
<meta name="description" content="<?= htmlspecialchars($pageDescription) ?>">
<meta name="robots" content="<?= htmlspecialchars($pageRobots) ?>">
<link rel="canonical" href="<?= htmlspecialchars($pageCanonical) ?>">

<meta property="og:type" content="website">
<meta property="og:title" content="<?= htmlspecialchars($pageTitle) ?>">
<meta property="og:description" content="<?= htmlspecialchars($pageDescription) ?>">
<meta property="og:url" content="<?= htmlspecialchars($pageCanonical) ?>">
<meta property="og:site_name" content="ColonyCare">
<?php if (!empty($pageImage)): ?>
<meta property="og:image" content="<?= htmlspecialchars($pageImage) ?>">
<?php endif; ?>
<meta name="twitter:card" content="summary_large_image">

<script type="application/ld+json">
<?= json_encode([
    '@context' => 'https://schema.org',
    '@type' => 'Organization',
    'name' => 'ColonyCare',
    'url' => 'https://www.example.com/shivam/',
    'description' => "India's Smartest Colony Management Platform for visitor management, billing, complaints, and community events.",
], JSON_UNESCAPED_SLASHES) ?>
</script>
<?php if (!empty($extraSchema)): ?>
<script type="application/ld+json">
<?= json_encode($extraSchema, JSON_UNESCAPED_SLASHES) ?>
</script>
<?php endif; ?>

    <!-- Google Font -->
    <link href="https://fonts.googleapis.com/css2?family=Poppins:wght@300;400;500;600;700&display=swap" rel="stylesheet">

    <!-- Icons -->
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.0/css/all.min.css">

    <link rel="stylesheet" href="style.css">
<style>
.dots-btn{background:none;border:none;color:#fff;font-size:1.1rem;cursor:pointer;padding:6px 10px;}
.dropdown{display:none;position:absolute;top:100%;right:0;background:#fff;border-radius:10px;box-shadow:0 8px 28px rgba(0,0,0,.18);min-width:170px;padding:6px;z-index:9999;margin-top:8px;}
.dropdown.active{display:block;}
.dropdown a{display:flex;align-items:center;gap:8px;padding:9px 12px;color:#1a2e22;text-decoration:none;font-size:.85rem;font-weight:600;border-radius:7px;white-space:nowrap;}
.dropdown a:hover{background:#f0f9f4;color:#0f8f6f;}
</style>
</head>
<body>

<header class="navbar">

<div class="container nav-flex">

    <!-- LEFT: LOGO -->
    <a class="logo" href="index.php"><i class="fa-solid fa-building"></i>
      ColonyCare
    </a>

    <!-- CENTER NAV -->
    <nav class="nav-links">
        <a href="resident.php"><i class="fa fa-user"></i> Resident</a>
        <a href="gate_staff.php"><i class="fa fa-door-open"></i> Gate Staff</a>
        <a href="admin_dashboard.php"><i class="fa fa-shield"></i> Admin / RWA</a>
        <a href="accountant.php"><i class="fa fa-calculator"></i> Accountant</a>
        <a href="vendor.php"><i class="fa fa-store"></i> Vendor</a>
        <a href="listings.php"><i class="fa fa-house"></i> Flats for Sale/Rent</a>

      <!-- 3 DOT MENU -->
      <div style="position:relative;display:inline-block;">
      <button id="menuDots" class="dots-btn">
        <i class="fa fa-ellipsis-vertical"></i>
      </button>

      <div id="dropdownMenu" class="dropdown">
        <a href="society-member.php"><i class="fa fa-users" style="width:16px;"></i> Society Member</a>
        <a href="#">About</a>
        <a href="#">Contact</a>
        <a href="#">Help</a>
        <a href="#">Settings</a>
      </div>
      </div>

    </nav>

    <!-- RIGHT SIDE -->
    <div class="nav-buttons">
        <a href="login.php" class="login-btn">Login</a>
        <a href="register-society.php" class="register-society-btn">
            <i class="fa fa-city"></i> Register Society
        </a>
        <a href="register.php" class="get-started-btn">Get Started</a>
    </div>

    <!-- HAMBURGER - visible on mobile only -->
    <button class="ham-btn" onclick="openMobNav()" aria-label="Open menu">
        <i class="fa fa-bars"></i>
    </button>

</header>

<!-- MOBILE NAV OVERLAY -->
<div class="mob-nav-overlay" id="mobNavOverlay" onclick="closeMobNavOnBg(event)">
    <div class="mob-nav-panel">

        <div class="mob-nav-head">
            <div class="mob-logo"><i class="fa-solid fa-building"></i> ColonyCare</div>
            <button class="mob-nav-close" onclick="closeMobNav()"><i class="fa fa-xmark"></i></button>
        </div>

        <div class="mob-nav-links">
            <a href="resident.php" onclick="closeMobNav()"><i class="fa fa-user"></i> Resident</a>
            <a href="gate_staff.php" onclick="closeMobNav()"><i class="fa fa-door-open"></i> Gate Staff</a>
            <a href="admin_dashboard.php" onclick="closeMobNav()"><i class="fa fa-shield"></i> Admin / RWA</a>
            <a href="accountant.php" onclick="closeMobNav()"><i class="fa fa-calculator"></i> Accountant</a>
            <a href="vendor.php" onclick="closeMobNav()"><i class="fa fa-store"></i> Vendor</a>
            <a href="society-member.php" onclick="closeMobNav()"><i class="fa fa-users"></i> Society Member</a>
            <a href="listings.php" onclick="closeMobNav()"><i class="fa fa-house"></i> Flats for Sale/Rent</a>
            <div class="mob-nav-divider"></div>
            <a href="#" onclick="closeMobNav()"><i class="fa fa-circle-info"></i> About</a>
            <a href="#" onclick="closeMobNav()"><i class="fa fa-headset"></i> Contact</a>
            <a href="#" onclick="closeMobNav()"><i class="fa fa-circle-question"></i> Help</a>
        </div>

        <div class="mob-nav-actions">
            <a href="login.php" class="mob-login-btn"><i class="fa fa-right-to-bracket"></i> Login</a>
            <a href="register-society.php" class="mob-register-btn"><i class="fa fa-city"></i> Register Society</a>
            <a href="register.php" class="mob-register-btn" style="background:#0f8f6f;color:#fff;"><i class="fa fa-user-plus"></i> Get Started</a>
        </div>

    </div>
</div>

<script>
function openMobNav(){
    document.getElementById('mobNavOverlay').classList.add('open');
    document.body.style.overflow='hidden';
}
function closeMobNav(){
    document.getElementById('mobNavOverlay').classList.remove('open');
    document.body.style.overflow='';
}
function closeMobNavOnBg(e){
    if(e.target===document.getElementById('mobNavOverlay')) closeMobNav();
}
// Existing 3-dot dropdown
document.addEventListener('DOMContentLoaded',function(){
    const dots=document.getElementById('menuDots');
    const menu=document.getElementById('dropdownMenu');
    if(dots&&menu){
        dots.addEventListener('click',function(e){
            e.stopPropagation();
            menu.classList.toggle('active');
        });
        document.addEventListener('click',function(){
            menu.classList.remove('active');
        });
    }
});
</script>
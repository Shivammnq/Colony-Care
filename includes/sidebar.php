<?php
// includes/sidebar.php
$role = $_SESSION['user_role'] ?? 'resident';
?>

<aside class="sidebar" id="sidebar">

  <div class="sidebar-brand">
    <div class="brand-icon"><i class="fa fa-building"></i></div>
    <div>
      <h2>ColonyCare</h2>
      <span>Society Portal</span>
    </div>
  </div>

  <nav class="sidebar-nav">

    <div class="nav-section-label">Main</div>
    <button class="nav-item active" id="nav-home" onclick="showSection('home',this)">
      <i class="fa fa-gauge"></i> Dashboard
    </button>

    <div class="nav-section-label">Community</div>
    <button class="nav-item" id="nav-residents"     onclick="showSection('residents',this)"><i class="fa fa-users"></i> Residents</button>
    <button class="nav-item" id="nav-announcements" onclick="showSection('announcements',this)"><i class="fa fa-bullhorn"></i> Announcements</button>
    <button class="nav-item" id="nav-events"        onclick="showSection('events',this)"><i class="fa fa-calendar"></i> Events</button>
    <button class="nav-item" id="nav-complaints"    onclick="showSection('complaints',this)">
      <i class="fa fa-comments"></i> Complaints
      <?php $open = 0; try { global $pdo; $open = $pdo->query("SELECT COUNT(*) FROM complaints WHERE status='open'")->fetchColumn(); } catch(Exception $e){} ?>
      <?php if($open > 0): ?><span class="badge-count"><?= $open ?></span><?php endif; ?>
    </button>

    <div class="nav-section-label">Facilities</div>
    <button class="nav-item" id="nav-facility" onclick="showSection('facility',this)"><i class="fa fa-building"></i> Facility Booking</button>
    <button class="nav-item" id="nav-visitors" onclick="showSection('visitors',this)"><i class="fa fa-id-badge"></i> Visitor Log</button>

    <?php if ($role === 'admin'): ?>
    <div class="nav-section-label">Finance</div>
    <button class="nav-item" id="nav-billing"  onclick="showSection('billing',this)"><i class="fa fa-file-invoice"></i> Billing</button>
    <button class="nav-item" id="nav-payments" onclick="showSection('payments',this)"><i class="fa fa-credit-card"></i> Payments</button>
    <button class="nav-item" id="nav-reports"  onclick="showSection('reports',this)"><i class="fa fa-chart-line"></i> Reports</button>

    <div class="nav-section-label">Admin</div>
    <button class="nav-item" id="nav-vendors"  onclick="showSection('vendors',this)"><i class="fa fa-store"></i> Vendors</button>
    <button class="nav-item" id="nav-settings" onclick="showSection('settings',this)"><i class="fa fa-cog"></i> Settings</button>
    <?php endif; ?>

  </nav>

  <div class="sidebar-footer">
    <!-- Back to Homepage -->
    <a href="/SHIVAM/index.php" class="back-home-btn">
      <i class="fa fa-house"></i> Back to Homepage
    </a>
    <div class="sidebar-user">
      <div class="avatar"><i class="fa fa-user"></i></div>
      <div class="info">
        <strong><?= htmlspecialchars($_SESSION['user_name'] ?? 'User') ?></strong>
        <span><?= ucfirst($role) ?></span>
      </div>
      <a href="/SHIVAM/auth/logout.php" class="logout-btn" title="Logout"><i class="fa fa-right-from-bracket"></i></a>
    </div>
  </div>

</aside>

<div class="sidebar-overlay" id="sidebarOverlay"></div>
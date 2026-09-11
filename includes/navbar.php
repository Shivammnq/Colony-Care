<?php
// includes/navbar.php
$role = $_SESSION['user_role'] ?? 'resident';
$name = $_SESSION['user_name'] ?? 'User';
$initials = strtoupper(substr($name, 0, 1));

$role_class = match($role) {
    'admin'    => 'role-admin',
    'staff'    => 'role-staff',
    default    => 'role-resident',
};

$page_titles = [
    'dashboard.php'     => ['Dashboard',       'Overview'],
    'residents.php'     => ['Residents',        'Directory'],
    'announcements.php' => ['Announcements',    'Notices & Updates'],
    'events.php'        => ['Events',           'Community Calendar'],
    'complaints.php'    => ['Complaints',       'Raise & Track'],
    'facility.php'      => ['Facility Booking', 'Book Common Areas'],
    'visitor.php'       => ['Visitor Log',      'Gate Management'],
    'payments.php'      => ['Payments',         'Online Transactions'],
    'billing.php'       => ['Billing',          'Invoices & Dues'],
    'reports.php'       => ['Reports',          'Analytics'],
    'vendors.php'       => ['Vendors',          'Service Providers'],
    'settings.php'      => ['Settings',         'Configuration'],
];

$current   = basename($_SERVER['PHP_SELF']);
$page_info = $page_titles[$current] ?? ['Dashboard', 'Overview'];
?>

<nav class="navbar">
  <button class="navbar-toggle" id="navbarToggle"><i class="fa fa-bars"></i></button>

  <div class="navbar-title">
    <?= $page_info[0] ?>
    <span>/ <?= $page_info[1] ?></span>
  </div>

  <div class="navbar-right">

    <a href="announcements.php" class="navbar-icon-btn" title="Notifications">
      <i class="fa fa-bell"></i>
      <span class="notif-dot"></span>
    </a>

    <a href="complaints.php" class="navbar-icon-btn" title="Complaints">
      <i class="fa fa-comments"></i>
    </a>

    <div class="navbar-user">
      <div class="nav-avatar"><?= $initials ?></div>
      <span class="nav-name"><?= htmlspecialchars($name) ?></span>
      <span class="role-badge <?= $role_class ?>"><?= ucfirst($role) ?></span>
    </div>

  </div>
</nav>
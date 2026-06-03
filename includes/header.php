<?php
$base_url = '/deno2';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/bootstrap.php';

function getUrl($path=''){
    global $base_url;
    return $base_url.'/'.ltrim($path,'/');
}

$current_page = basename($_SERVER['PHP_SELF']);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Janak Production Management System - Dashboard</title>
    
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/deno2/assets/css/jpms.css">
</head>
<body>


<!-- ================= HEADER ================= -->
<div class="header">
    <div class="header-container">
        <h1><i class="bi bi-building"></i> Janak <span class="highlight">Production</span> System</h1>
        <button class="mobile-menu-btn d-lg-none" id="openMobileMenu">
            <i class="bi bi-list"></i>
        </button>
    </div>
</div>

<!-- ================= MOBILE OVERLAY ================= -->
<div class="navbar-mobile-overlay" id="mobileOverlay"></div>

<!-- ================= MOBILE SIDEBAR ================= -->
<aside class="navbar-custom-mobile" id="mobileSidebar">

<div class="mobile-nav-header">
    <span class="mobile-nav-title">JPMS Menu</span>
    <button class="mobile-close-btn" id="closeMobileMenu">
        <i class="bi bi-x-lg"></i>
    </button>
</div>

<ul class="navbar-nav p-3">

<li class="nav-item">
    <a class="nav-link" href="<?= getUrl('index.php') ?>">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>
</li>

<li class="nav-item">
    <a class="nav-link" href="<?= getUrl('entries/index.php') ?>">
        <i class="bi bi-plus-circle"></i> Add DENO
    </a>
</li>

<!-- MODULES -->
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mModules" role="button" aria-expanded="false">
        <i class="bi bi-grid-3x3-gap"></i> Modules <i class="bi bi-chevron-down ms-auto"></i>
    </a>
    <div class="collapse" id="mModules">
        <a class="dropdown-item" href="<?= getUrl('d2m/index.php') ?>">D2M</a>
        <a class="dropdown-item" href="<?= getUrl('jobticket/index.php') ?>">Job Ticket</a>
        <a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>">Forma</a>
        <a class="dropdown-item" href="<?= getUrl('bookpacking/index.php') ?>">Pack & Stitch</a>
        <a class="dropdown-item" href="<?= getUrl('formaprinting/index.php') ?>">Forma Printing</a>
    </div>
</li>

<!-- SETUP -->
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mSetup" role="button" aria-expanded="false">
        <i class="bi bi-gear"></i> Setup <i class="bi bi-chevron-down ms-auto"></i>
    </a>
    <div class="collapse" id="mSetup">
        <a class="dropdown-item" href="<?= getUrl('book/index.php') ?>">Books</a>
        <a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>">Forma</a>
    </div>
</li>

<!-- VEHICLES -->
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mVehicles" role="button" aria-expanded="false">
        <i class="bi bi-truck"></i> Vehicles <i class="bi bi-chevron-down ms-auto"></i>
    </a>
    <div class="collapse" id="mVehicles">
 <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_index.php') ?>">Vehicle List</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_assignments.php') ?>">Assignments</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/driver_index.php') ?>">Drivers</a></li>
      
  
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/fuel_price_history.php') ?>">Fuel Prices</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_maintenance.php') ?>">Maintenance</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/monthly_summary.php') ?>">Monthly Summary</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_reports_nepali.php') ?>">Nepali Reports</a></li>
              <a class="dropdown-item" href="<?= getUrl('vehicle/fuel_coupons_v2.php') ?>">Fuel Coupons</a>

                    <a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_daily_log_v2.php') ?>">Daily Log</a>

    </div>
</li>

<!-- REPORTS -->
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mReports" role="button" aria-expanded="false">
        <i class="bi bi-file-earmark-bar-graph"></i> Reports <i class="bi bi-chevron-down ms-auto"></i>
    </a>
    <div class="collapse" id="mReports">
        <a class="dropdown-item" href="<?= getUrl('reports.php') ?>">All Reports</a>
        <a class="dropdown-item" href="<?= getUrl('denoreports/daily.php') ?>">Deno Daily</a>
        <a class="dropdown-item" href="<?= getUrl('report/index.php') ?>">Compare Marketing</a>
    </div>
</li>

<li class="nav-item">
    <a class="nav-link text-danger" href="<?= getUrl('logout.php') ?>">
        <i class="bi bi-box-arrow-right"></i> Logout
    </a>
</li>

</ul>
</aside>

<!-- ================= DESKTOP NAV ================= -->
<nav class="navbar navbar-expand-lg navbar-custom navbar-custom-desktop">
<div class="container-fluid">
<ul class="navbar-nav mx-auto">

<li class="nav-item">
    <a class="nav-link" href="<?= getUrl('index.php') ?>">
        <i class="bi bi-speedometer2"></i> Dashboard
    </a>
</li>

<li class="nav-item">
    <a class="nav-link" href="<?= getUrl('entries/index.php') ?>">
        <i class="bi bi-plus-circle"></i> Add DENO
    </a>
</li>

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-grid-3x3-gap"></i> Modules
    </a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('d2m/index.php') ?>">D2M</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('jobticket/index.php') ?>">Job Ticket</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>">Forma</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('bookpacking/index.php') ?>">Pack & Stitch</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('formaprinting/index.php') ?>">Forma Printing</a></li>
    </ul>
</li>

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-gear"></i> Setup
    </a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('book/index.php') ?>">Books</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>">Forma</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('config/users.php') ?>">Users</a></li>

    </ul>
</li>

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-truck"></i> Vehicles
    </a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_index.php') ?>">Vehicle List</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_assignments.php') ?>">Assignments</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/driver_index.php') ?>">Drivers</a></li>
      
  
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/fuel_price_history.php') ?>">Fuel Prices</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_maintenance.php') ?>">Maintenance</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/monthly_summary.php') ?>">Monthly Summary</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_reports_nepali.php') ?>">Nepali Reports</a></li>
              <a class="dropdown-item" href="<?= getUrl('vehicle/fuel_coupons_v2.php') ?>">Fuel Coupons</a>

                    <a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_daily_log_v2.php') ?>">Daily Log</a>
                     
    </ul>
</li>


<?php if (can_access_module('hr')): ?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-people"></i> HR
    </a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('hr/employee/index.php') ?>">Employee List</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/employee/create_enhanced.php') ?>">Add Employee</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/employee/department/index.php') ?>">Departments</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/modules/attendance/mark.php') ?>">Mark Attendance</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/modules/leaves/apply.php') ?>">Leave Applications</a></li>
        <?php if (can_access_module('payroll')): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/modules/payroll/process.php') ?>">Payroll</a></li>
        <?php endif; ?>
    </ul>
</li>
<?php endif; ?>


<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
        <i class="bi bi-file-earmark-bar-graph"></i> Reports
    </a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('reports.php') ?>">All Reports</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('denoreports/daily.php') ?>">Deno Daily</a></li>
           <a class="dropdown-item" href="<?= getUrl('report/index.php') ?>">Compare Marketing</a>
    </ul>
</li>

<li class="nav-item">
    <a class="nav-link text-danger" href="<?= getUrl('logout.php') ?>">
        <i class="bi bi-box-arrow-right"></i> Logout
    </a>
</li>

</ul>
</div>
</nav>

<!-- ================= JS ================= -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    const openBtn = document.getElementById('openMobileMenu');
    const closeBtn = document.getElementById('closeMobileMenu');
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileOverlay');

    if (openBtn) {
        openBtn.onclick = function() {
            sidebar.classList.add('active');
            overlay.classList.add('active');
            document.body.style.overflow = 'hidden';
        };
    }

    function closeMenu() {
        sidebar.classList.remove('active');
        overlay.classList.remove('active');
        document.body.style.overflow = '';
    }

    if (closeBtn) {
        closeBtn.onclick = closeMenu;
    }
    
    if (overlay) {
        overlay.onclick = closeMenu;
    }

    // Close mobile menu when clicking on a link
    const mobileLinks = sidebar.querySelectorAll('a.dropdown-item, a.nav-link');
    mobileLinks.forEach(link => {
        link.addEventListener('click', function(e) {
            // Don't close if it's a collapse toggle
            if (!this.getAttribute('data-bs-toggle')) {
                closeMenu();
            }
        });
    });
});
</script>
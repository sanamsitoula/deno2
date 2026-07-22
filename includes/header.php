<?php
$base_url = '/jemc';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/bootstrap.php';

function getUrl($path = '') {
    global $base_url;
    return $base_url . '/' . ltrim($path, '/');
}

?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Janak Production Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <link rel="stylesheet" href="/jemc/assets/css/jpms.css">
    <!-- Nepali Date Picker (Sajanmaharjan v5) -->
    <link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
          rel="stylesheet" type="text/css"/>
    <style>
    /* ── Nepali datepicker global overrides ── */
    .bs-date, .ndp-input {
        background:#fff url("data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' width='16' height='16' fill='%236c5ce7' viewBox='0 0 16 16'%3E%3Cpath d='M3.5 0a.5.5 0 0 1 .5.5V1h8V.5a.5.5 0 0 1 1 0V1h1a2 2 0 0 1 2 2v11a2 2 0 0 1-2 2H2a2 2 0 0 1-2-2V3a2 2 0 0 1 2-2h1V.5a.5.5 0 0 1 .5-.5zM2 2a1 1 0 0 0-1 1v11a1 1 0 0 0 1 1h12a1 1 0 0 0 1-1V3a1 1 0 0 0-1-1H2z'/%3E%3C/svg%3E") no-repeat right 10px center;
        background-size: 14px;
        padding-right: 32px !important;
        cursor: pointer;
    }
    .bs-date:focus, .ndp-input:focus {
        border-color: #6c5ce7;
        box-shadow: 0 0 0 2px rgba(108,92,231,.2);
        outline: none;
    }
    .dual-date { display:flex; gap:6px; align-items:center; flex-wrap:wrap; }
    .dual-date .bs-date, .dual-date .ad-date { flex:1; min-width:130px; }
    .bs-date-label {
        display: inline-block;
        font-size: .72rem;
        color: #6c5ce7;
        font-weight: 600;
        margin-top: 2px;
    }
    .ad-date-label {
        display: inline-block;
        font-size: .72rem;
        color: #0984e3;
        font-weight: 600;
        margin-top: 2px;
    }
    /* Calendar popup z-index */
    .nepali-date-picker { z-index: 9999 !important; }
    </style>
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

<div class="navbar-mobile-overlay" id="mobileOverlay"></div>

<aside class="navbar-custom-mobile" id="mobileSidebar">
<div class="mobile-nav-header">
    <span class="mobile-nav-title">JPMS Menu</span>
    <button class="mobile-close-btn" id="closeMobileMenu"><i class="bi bi-x-lg"></i></button>
</div>
<ul class="navbar-nav p-3">
<li class="nav-item"><a class="nav-link" href="<?= getUrl('index.php') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a></li>
<li class="nav-item"><a class="nav-link" href="<?= getUrl('entries/index.php') ?>"><i class="bi bi-plus-circle"></i> Add DENO</a></li>
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mModules" role="button"><i class="bi bi-grid-3x3-gap"></i> Modules <i class="bi bi-chevron-down ms-auto"></i></a>
    <div class="collapse" id="mModules">
        <a class="dropdown-item" href="<?= getUrl('d2m/index.php') ?>">D2M</a>
        <a class="dropdown-item" href="<?= getUrl('jobticket/index.php') ?>">Job Ticket</a>
        <a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>">Forma</a>
        <a class="dropdown-item" href="<?= getUrl('bookpacking/index.php') ?>">Pack &amp; Stitch</a>
        <a class="dropdown-item" href="<?= getUrl('formaprinting/index.php') ?>">Forma Printing</a>
    </div>
</li>
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mVehicles" role="button"><i class="bi bi-truck"></i> Vehicles <i class="bi bi-chevron-down ms-auto"></i></a>
    <div class="collapse" id="mVehicles">
        <a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_index.php') ?>">Vehicle List</a>
        <a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_assignments.php') ?>">Assignments</a>
        <a class="dropdown-item" href="<?= getUrl('vehicle/driver_index.php') ?>">Drivers</a>
        <a class="dropdown-item" href="<?= getUrl('vehicle/fuel_price_history.php') ?>">Fuel Prices</a>
        <a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_maintenance.php') ?>">Maintenance</a>
        <a class="dropdown-item" href="<?= getUrl('vehicle/fuel_coupons_v2.php') ?>">Fuel Coupons</a>
        <a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_daily_log_v2.php') ?>">Daily Log</a>
    </div>
</li>
<?php if (can_access_module('hr')): ?>
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mHR" role="button"><i class="bi bi-people"></i> HR <i class="bi bi-chevron-down ms-auto"></i></a>
    <div class="collapse" id="mHR">
        <a class="dropdown-item" href="<?= getUrl('hr/employee/index.php') ?>">Employee List</a>
        <a class="dropdown-item" href="<?= getUrl('hr/employee/create_enhanced.php') ?>">Add Employee</a>
        <a class="dropdown-item" href="<?= getUrl('attendance_device/zkteco_live.php') ?>">ZKTeco Live</a>
        <a class="dropdown-item" href="<?= getUrl('attendance_device/attendance_report.php') ?>">Attendance Report</a>
        <a class="dropdown-item" href="<?= getUrl('hr/modules/attendance/mark.php') ?>">Mark Attendance</a>
        <a class="dropdown-item" href="<?= getUrl('hr/modules/payroll/process.php') ?>">Payroll</a>
    </div>
</li>
<?php endif; ?>
<li class="nav-item">
    <a class="nav-link" data-bs-toggle="collapse" href="#mReports" role="button"><i class="bi bi-file-earmark-bar-graph"></i> Reports <i class="bi bi-chevron-down ms-auto"></i></a>
    <div class="collapse" id="mReports">
        <a class="dropdown-item" href="<?= getUrl('reports.php') ?>">All Reports</a>
        <a class="dropdown-item" href="<?= getUrl('denoreports/daily.php') ?>">Deno Daily</a>
        <a class="dropdown-item" href="<?= getUrl('report/index.php') ?>">Compare Marketing</a>
        <a class="dropdown-item" href="<?= getUrl('attendance_device/attendance_report.php') ?>">Attendance PDF</a>
    </div>
</li>
<li class="nav-item"><a class="nav-link text-danger" href="<?= getUrl('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
</ul>
</aside>

<!-- ================= DESKTOP NAV ================= -->
<nav class="navbar navbar-expand-lg navbar-custom navbar-custom-desktop">
<div class="container-fluid">
<ul class="navbar-nav mx-auto">

<li class="nav-item">
    <a class="nav-link" href="<?= getUrl('index.php') ?>"><i class="bi bi-speedometer2"></i> Dashboard</a>
</li>
<li class="nav-item">
    <a class="nav-link" href="<?= getUrl('entries/index.php') ?>"><i class="bi bi-plus-circle"></i> Add DENO</a>
</li>

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-grid-3x3-gap"></i> Modules</a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('d2m/index.php') ?>">D2M</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('jobticket/index.php') ?>">Job Ticket</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>">Forma</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('bookpacking/index.php') ?>">Pack &amp; Stitch</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('formaprinting/index.php') ?>">Forma Printing</a></li>
    </ul>
</li>

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-gear"></i> Setup</a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('book/index.php') ?>">Books</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>">Forma</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('config/users.php') ?>">Users</a></li>
    </ul>
</li>

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-truck"></i> Vehicles</a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_index.php') ?>">Vehicle List</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_assignments.php') ?>">Assignments</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/driver_index.php') ?>">Drivers</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/fuel_price_history.php') ?>">Fuel Prices</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_maintenance.php') ?>">Maintenance</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/monthly_summary.php') ?>">Monthly Summary</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_reports_nepali.php') ?>">Nepali Reports</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/fuel_coupons_v2.php') ?>">Fuel Coupons</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('vehicle/vehicle_daily_log_v2.php') ?>">Daily Log</a></li>
    </ul>
</li>

<?php if (can_access_module('hr')): ?>
<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-people"></i> HR</a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item fw-bold" href="<?= getUrl('hr/index.php') ?>" style="color:#2c3e8c"><i class="bi bi-grid-1x2 me-1"></i>HR Dashboard</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/employee/index.php') ?>"><i class="bi bi-people-fill me-1"></i>Employee List</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/employee/create_enhanced.php') ?>"><i class="bi bi-person-plus me-1"></i>Add Employee</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/employee/department/index.php') ?>"><i class="bi bi-diagram-3 me-1"></i>Departments</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= getUrl('attendance_device/zkteco_live.php') ?>"><i class="bi bi-fingerprint me-1"></i>ZKTeco Live</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('attendance_device/device_users.php') ?>"><i class="bi bi-hdd-network me-1"></i>Device Users</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('attendance_device/attendance_report.php') ?>"><i class="bi bi-calendar-check me-1"></i>Attendance Report</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/reports/hajiri_vivaran.php') ?>"><i class="bi bi-table me-1"></i>हाजिरी विवरण</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/reports/talab_report.php') ?>"><i class="bi bi-cash me-1"></i>तलब विवरण</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/modules/attendance/mark.php') ?>"><i class="bi bi-check2-square me-1"></i>Mark Attendance</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/modules/leaves/apply.php') ?>"><i class="bi bi-envelope me-1"></i>Leave Applications</a></li>
        <?php if (can_access_module('payroll')): ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/modules/payroll/process.php') ?>"><i class="bi bi-cash-stack me-1"></i>Payroll</a></li>
        <?php endif; ?>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/setup/index.php') ?>"><i class="bi bi-gear-fill me-1"></i>Payroll Setup</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('hr/setup/salary.php') ?>"><i class="bi bi-sliders me-1"></i>Salary & Grades</a></li>
    </ul>
</li>
<?php endif; ?>

<li class="nav-item dropdown">
    <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"><i class="bi bi-file-earmark-bar-graph"></i> Reports</a>
    <ul class="dropdown-menu">
        <li><a class="dropdown-item" href="<?= getUrl('reports.php') ?>">All Reports</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('denoreports/daily.php') ?>">Deno Daily</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('report/index.php') ?>">Compare Marketing</a></li>
        <li><hr class="dropdown-divider"></li>
        <li><a class="dropdown-item" href="<?= getUrl('attendance_device/attendance_report.php') ?>"><i class="bi bi-file-pdf me-1"></i>Attendance PDF</a></li>
        <li><a class="dropdown-item" href="<?= getUrl('attendance_device/attendance_report.php?type=monthly') ?>">Monthly Attendance</a></li>
    </ul>
</li>

<li class="nav-item">
    <a class="nav-link text-danger" href="<?= getUrl('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Logout</a>
</li>

</ul>
</div>
</nav>

<!-- jQuery (required by Sajanmaharjan picker) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<!-- Nepali Date Picker JS -->
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>
<!-- Our BS↔AD converter -->
<script src="/jemc/assets/js/nepal-date.js"></script>
<!-- Global BS Date Picker initializer -->
<script src="/jemc/assets/js/bs-datepicker-global.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const openBtn = document.getElementById('openMobileMenu');
    const closeBtn = document.getElementById('closeMobileMenu');
    const sidebar = document.getElementById('mobileSidebar');
    const overlay = document.getElementById('mobileOverlay');
    function closeMenu() { sidebar.classList.remove('active'); overlay.classList.remove('active'); document.body.style.overflow=''; }
    if (openBtn) openBtn.onclick = function() { sidebar.classList.add('active'); overlay.classList.add('active'); document.body.style.overflow='hidden'; };
    if (closeBtn) closeBtn.onclick = closeMenu;
    if (overlay) overlay.onclick = closeMenu;
    sidebar.querySelectorAll('a.dropdown-item,a.nav-link').forEach(function(l) {
        l.addEventListener('click', function(e) { if (!this.getAttribute('data-bs-toggle')) closeMenu(); });
    });
});
</script>

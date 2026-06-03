<?php
// Make sure there's NO whitespace or characters before this <?php tag

// Define base URL - adjust this path according to your setup
$base_url = '/deno2';

// Include auth functions if not already included
if (!function_exists('is_logged_in')) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!is_logged_in()) {
    header('Location: ' . $base_url . '/login.php');
    exit;
}

// Function to generate correct URLs
function getUrl($path = '') {
    global $base_url;
    return $base_url . '/' . ltrim($path, '/');
}

// Function to recursively scan directory
function scanDirectory($dir, $base_path = '') {
    $result = array();
    
    if (!is_dir($dir)) {
        return $result;
    }
    
    $files = scandir($dir);
    
    foreach ($files as $file) {
        if ($file === '.' || $file === '..') {
            continue;
        }
        
        $path = $dir . '/' . $file;
        $relative_path = $base_path . '/' . $file;
        
        // Skip certain directories
        $skip_dirs = array('.git', 'node_modules', 'vendor', '.vscode', '.idea');
        if (in_array($file, $skip_dirs)) {
            continue;
        }
        
        if (is_dir($path)) {
            $result[] = array(
                'name' => $file,
                'type' => 'directory',
                'path' => $relative_path,
                'children' => scanDirectory($path, $relative_path)
            );
        } else {
            $result[] = array(
                'name' => $file,
                'type' => 'file',
                'path' => $relative_path,
                'size' => filesize($path),
                'modified' => filemtime($path)
            );
        }
    }
    
    return $result;
}

// Get directory structure if admin
$directory_structure = null;
if ($_SESSION['user_role'] === 'admin') {
    $project_root = $_SERVER['DOCUMENT_ROOT'] . '/deno2';
    $directory_structure = scanDirectory($project_root, '/deno2');
}

?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta http-equiv="Content-Security-Policy" content="default-src 'self' 'unsafe-inline' 'unsafe-eval' https: data: blob:; font-src 'self' https: data:; img-src 'self' https: data: blob:;">
    <title>Reports - Janak Production Management System</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #f5f7fa 0%, #c3cfe2 100%);
            min-height: 100vh;
        }

        /* Header Styles */
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 1rem 0;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            position: relative;
            z-index: 1000;
        }

        .header-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }

        .header h1 {
            font-size: 1.8rem;
            font-weight: 700;
            margin: 0;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.3);
        }

        .header h1 .highlight {
            color: #ffd700;
        }

        /* Mobile Menu Button */
        .mobile-menu-btn {
            display: none;
            background: rgba(255,255,255,0.2);
            border: 2px solid rgba(255,255,255,0.3);
            color: white;
            width: 45px;
            height: 45px;
            border-radius: 8px;
            font-size: 1.2rem;
            cursor: pointer;
            transition: all 0.3s ease;
        }

        .mobile-menu-btn:hover {
            background: rgba(255,255,255,0.3);
            transform: scale(1.05);
        }

        /* Navigation Styles */
        .navbar-custom {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            margin: 15px 0;
            box-shadow: 0 8px 32px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
        }

        .navbar-nav {
            gap: 10px;
        }

        .nav-link {
            color: #333 !important;
            font-weight: 500;
            padding: 12px 20px !important;
            border-radius: 8px;
            transition: all 0.3s ease;
            position: relative;
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .nav-link:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
            transform: translateY(-2px);
        }

        .nav-link.active {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white !important;
        }

        .dropdown-menu {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 12px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.15);
            padding: 10px;
            margin-top: 5px;
        }

        .dropdown-item {
            color: #333;
            padding: 10px 15px;
            border-radius: 8px;
            transition: all 0.3s ease;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .dropdown-item:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
        }

        /* Dashboard Container */
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 0 1rem;
        }

        /* Main Container */
        .main-container {
            max-width: 1200px;
            margin: 1rem auto;
            padding: 0 1rem;
        }


        /* Section Headers */
        .section-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px 20px;
            border-radius: 10px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
            display: flex;
            align-items: center;
            gap: 12px;
        }

        .section-header i {
            font-size: 1.4rem;
        }

        .section-header h2 {
            margin: 0;
            font-size: 1.3rem;
            font-weight: 600;
        }

        /* Reports Grid */
        .reports-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .report-category {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            transition: all 0.3s ease;
        }

        .report-category:hover {
            transform: translateY(-5px);
            box-shadow: 0 10px 30px rgba(0,0,0,0.15);
        }

        .category-header {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 15px;
            padding-bottom: 12px;
            border-bottom: 2px solid #f0f0f0;
        }

        .category-icon {
            width: 40px;
            height: 40px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2rem;
            color: white;
            flex-shrink: 0;
        }

        .category-icon.deno {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }

        .category-icon.ps {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
        }

        .category-icon.job {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .category-icon.forma {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .category-icon.hr {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }

        .category-icon.general {
            background: linear-gradient(135deg, #30cfd0 0%, #330867 100%);
        }

        .category-header h3 {
            margin: 0;
            font-size: 1.1rem;
            font-weight: 600;
            color: #333;
        }

        .report-links {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .report-link {
            display: flex;
            align-items: center;
            gap: 8px;
            padding: 10px 12px;
            background: #f8f9fa;
            border-radius: 8px;
            text-decoration: none;
            color: #333;
            transition: all 0.3s ease;
            border-left: 3px solid transparent;
            font-size: 0.9rem;
        }

        .report-link:hover {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-left-color: #ffd700;
            transform: translateX(5px);
        }

        .report-link i {
            font-size: 1rem;
        }


        /* Directory Explorer */
        .directory-explorer {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 6px 20px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            margin-bottom: 30px;
        }

        .directory-tree {
            font-family: 'Courier New', monospace;
            font-size: 0.85rem;
            max-height: 500px;
            overflow-y: auto;
            background: #f8f9fa;
            padding: 15px;
            border-radius: 8px;
            border: 1px solid #e0e0e0;
        }

        .tree-item {
            padding: 4px 0;
            cursor: pointer;
            user-select: none;
        }

        .tree-item:hover {
            background: rgba(102, 126, 234, 0.1);
        }

        .tree-folder {
            color: #667eea;
            font-weight: 600;
        }

        .tree-file {
            color: #555;
        }

        .tree-toggle {
            display: inline-block;
            width: 18px;
            text-align: center;
            cursor: pointer;
            color: #667eea;
            font-weight: bold;
            font-size: 0.8rem;
        }

        .tree-children {
            margin-left: 20px;
            display: none;
        }

        .tree-children.expanded {
            display: block;
        }

        .file-info {
            font-size: 0.75rem;
            color: #999;
            margin-left: 8px;
        }

        /* Quick Stats */
        .quick-stats {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
            gap: 15px;
            margin-bottom: 25px;
        }

        .stat-box {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 15px;
            border-radius: 10px;
            box-shadow: 0 4px 12px rgba(102, 126, 234, 0.3);
            text-align: center;
        }

        .stat-box.green {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
        }

        .stat-box.orange {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }

        .stat-box.blue {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }

        .stat-value {
            font-size: 1.8rem;
            font-weight: 700;
            margin-bottom: 5px;
        }

        .stat-label {
            font-size: 0.85rem;
            opacity: 0.9;
        }


        /* Responsive */
        @media (max-width: 768px) {
            .mobile-menu-btn {
                display: block;
            }

            .header h1 {
                font-size: 1.4rem;
            }

            .navbar-custom {
                position: fixed;
                top: 0;
                left: -100%;
                width: 280px;
                height: 100vh;
                background: rgba(255,255,255,0.98);
                backdrop-filter: blur(15px);
                transition: left 0.3s ease;
                z-index: 999;
                border-radius: 0 15px 15px 0;
                padding-top: 80px;
                overflow-y: auto;
            }

            .navbar-custom.active {
                left: 0;
            }

            .navbar-nav {
                flex-direction: column;
                padding: 20px;
            }

            .nav-link {
                width: 100%;
                margin-bottom: 5px;
            }

            .dropdown-menu {
                position: static;
                float: none;
                width: 100%;
                margin: 5px 0;
                box-shadow: inset 0 2px 10px rgba(0,0,0,0.1);
            }

            .mobile-backdrop {
                display: none;
                position: fixed;
                top: 0;
                left: 0;
                width: 100%;
                height: 100%;
                background: rgba(0,0,0,0.5);
                z-index: 998;
            }

            .mobile-backdrop.active {
                display: block;
            }

            .main-container {
                padding: 0 1rem;
            }

            .reports-grid {
                grid-template-columns: 1fr;
            }

            .section-header h2 {
                font-size: 1.1rem;
            }

            .section-header i {
                font-size: 1.2rem;
            }

            .quick-stats {
                grid-template-columns: 1fr 1fr;
            }

            .stat-value {
                font-size: 1.5rem;
            }
        }

        /* Footer */
        footer {
            background: linear-gradient(135deg, #2c3e50 0%, #34495e 100%);
            color: white;
            text-align: center;
            padding: 30px 20px;
            margin-top: 50px;
        }

        footer p {
            margin: 5px 0;
            font-size: 0.95rem;
        }

        .footer-brand {
            font-weight: 600;
            color: #ffd700;
        }
    </style>
</head>
<body>
    <!-- Header -->
    <div class="header">
        <div class="header-container">
            <h1>Janak <span class="highlight">Production</span> Management System</h1>
            <button class="mobile-menu-btn" id="mobileMenuBtn">
                <i class="bi bi-list"></i>
            </button>
        </div>
    </div>

    <!-- Mobile Backdrop -->
    <div class="mobile-backdrop" id="mobileBackdrop"></div>

    <!-- Navigation -->
    <div class="dashboard-container">
        <nav class="navbar navbar-expand-lg navbar-custom" id="mainNavbar">
            <div class="container-fluid">
                <ul class="navbar-nav mx-auto">
                    <li class="nav-item">
                        <a class="nav-link" href="<?= getUrl('index.php') ?>">
                            <i class="bi bi-speedometer2"></i> Dashboard
                        </a>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link" href="<?= getUrl('entries/index.php') ?>">
                            <i class="bi bi-plus-circle"></i> Add Deno
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-grid-3x3-gap"></i> HR
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= getUrl('hr/department/index.php') ?>"><i class="bi bi-diagram-3"></i> Department</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('hr/employee/index.php') ?>"><i class="bi bi-people"></i> Employee</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('hr/Designation/index.php') ?>"><i class="bi bi-award"></i> Designation</a></li>
                        </ul>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-grid-3x3-gap"></i> Modules
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= getUrl('jobticket/index.php') ?>"><i class="bi bi-ticket"></i> Job Ticket</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('formaprinting/index.php') ?>"><i class="bi bi-printer"></i> Forma Printing</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('forma/index.php') ?>"><i class="bi bi-file-text"></i> Forma</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('bookpacking/index.php') ?>"><i class="bi bi-box-seam"></i> Pack & Stitch</a></li>
                        </ul>
                    </li>
                    <li class="nav-item">
                        <a class="nav-link active" href="<?= getUrl('reports.php') ?>">
                            <i class="bi bi-file-earmark-bar-graph"></i> All Reports
                        </a>
                    </li>
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> 
                            <span>Welcome, <?= htmlspecialchars($_SESSION['username']) ?> (<?= $_SESSION['user_role'] ?>)</span>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#user-management"><i class="bi bi-people"></i> User Management</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="<?= getUrl('logout.php') ?>"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>

    <!-- Main Container -->
    <div class="main-container">
        <!-- Quick Stats -->
        <div class="quick-stats">
            <div class="stat-box">
                <div class="stat-value">5</div>
                <div class="stat-label">Deno Reports</div>
            </div>
            <div class="stat-box green">
                <div class="stat-value">5</div>
                <div class="stat-label">P&S Reports</div>
            </div>
            <div class="stat-box orange">
                <div class="stat-value">3</div>
                <div class="stat-label">Job Ticket Reports</div>
            </div>
            <div class="stat-box blue">
                <div class="stat-value">3</div>
                <div class="stat-label">Forma Reports</div>
            </div>
        </div>


        <!-- Deno Reports Section -->
        <div class="section-header">
            <i class="bi bi-clipboard-data"></i>
            <h2>Deno Production Reports</h2>
        </div>

        <div class="reports-grid">
            <div class="report-category">
                <div class="category-header">
                    <div class="category-icon deno">
                        <i class="bi bi-file-earmark-text"></i>
                    </div>
                    <h3>Deno Reports</h3>
                </div>
                <div class="report-links">
                    <a href="<?= getUrl('denoreports/daily.php') ?>" class="report-link">
                        <i class="bi bi-calendar-day"></i>
                        <span>Daily Report</span>
                    </a>
                    <a href="<?= getUrl('denoreports/daywisemonth.php') ?>" class="report-link">
                        <i class="bi bi-calendar-range"></i>
                        <span>Day Wise Month</span>
                    </a>
                    <a href="<?= getUrl('denoreports/monthly.php') ?>" class="report-link">
                        <i class="bi bi-calendar-month"></i>
                        <span>Monthly Report</span>
                    </a>
                    <a href="<?= getUrl('denoreports/books.php') ?>" class="report-link">
                        <i class="bi bi-book"></i>
                        <span>Books Report</span>
                    </a>
                    <a href="<?= getUrl('denoreports/translated.php') ?>" class="report-link">
                        <i class="bi bi-translate"></i>
                        <span>Translated Report</span>
                    </a>
                      <a href="<?= getUrl('report/production_process_control.php') ?>" class="report-link">
                        <i class="bi bi-translate"></i>
                        <span>Process Control Report</span>
                    </a>
                         <a href="<?= getUrl('report/production_process_control2.php') ?>" class="report-link">
                        <i class="bi bi-translate"></i>
                        <span>Process Control Report 2</span>
                    </a>
                     <a href="<?= getUrl('report/trend.php') ?>" class="report-link">
                        <i class="bi bi-translate"></i>
                        <span>Trend Report</span>
                    </a>
                        <a href="<?= getUrl('report/trend.php') ?>" class="report-link">
                        <i class="bi bi-translate"></i>
                        <span>Trend Report</span>
                    </a>
                </div>
            </div>
        </div>


           <!-- FOrma Printing Reports Section -->
        <div class="section-header">
            <i class="bi bi-box-seam"></i>
            <h2>Forma Printing Reports</h2>
        </div>

 <div class="reports-grid">
            <div class="report-category">
                <div class="category-header">
                    <div class="category-icon ps">
                        <i class="bi bi-file-earmark-spreadsheet"></i>
                    </div>
                    <h3>FP Reports</h3>
                </div>
                <div class="report-links">
                    <a href="<?= getUrl('formaprinting/reports/reportdetails.php') ?>" class="report-link">
                        <i class="bi bi-calendar-day"></i>
                        <span> Report Detail</span>
                    </a>
                       <a href="<?= getUrl('formaprinting/reports/production_report.php') ?>" class="report-link">
                        <i class="bi bi-calendar-day"></i>
                        <span> Production Report</span>
                    </a>
              
              
                </div>
            </div>
        </div>
        <!-- Pack & Stitch Reports Section -->
        <div class="section-header">
            <i class="bi bi-box-seam"></i>
            <h2>Pack & Stitch Reports</h2>
        </div>

        <div class="reports-grid">
            <div class="report-category">
                <div class="category-header">
                    <div class="category-icon ps">
                        <i class="bi bi-file-earmark-spreadsheet"></i>
                    </div>
                    <h3>P&S Reports</h3>
                </div>
                <div class="report-links">
                    <a href="<?= getUrl('bookpacking/ps_reports/report.php') ?>" class="report-link">
                        <i class="bi bi-calendar-day"></i>
                        <span>Daily Report</span>
                    </a>
                    <a href="<?= getUrl('bookpacking/ps_reports/report2.php') ?>" class="report-link">
                        <i class="bi bi-calendar-range"></i>
                        <span>Day Wise Month</span>
                    </a>
                    <a href="<?= getUrl('bookpacking/ps_reports/report3.php') ?>" class="report-link">
                        <i class="bi bi-calendar-month"></i>
                        <span>Monthly Report</span>
                    </a>
                    <a href="<?= getUrl('bookpacking/ps_reports/books.php') ?>" class="report-link">
                        <i class="bi bi-book"></i>
                        <span>Books Report</span>
                    </a>
                    <a href="<?= getUrl('bookpacking/ps_reports/translated.php') ?>" class="report-link">
                        <i class="bi bi-translate"></i>
                        <span>Translated Report</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- Job Ticket & Forma Reports Section -->
        <div class="section-header">
            <i class="bi bi-ticket-perforated"></i>
            <h2>Job Ticket & Forma Reports</h2>
        </div>

        <div class="reports-grid">
            <div class="report-category">
                <div class="category-header">
                    <div class="category-icon job">
                        <i class="bi bi-ticket"></i>
                    </div>
                    <h3>Job Ticket Reports</h3>
                </div>
                <div class="report-links">
                    <a href="<?= getUrl('jobticket/reports/daily.php') ?>" class="report-link">
                        <i class="bi bi-calendar-day"></i>
                        <span>Daily Report</span>
                    </a>
                    <a href="<?= getUrl('jobticket/reports/monthly.php') ?>" class="report-link">
                        <i class="bi bi-calendar-month"></i>
                        <span>Monthly Report</span>
                    </a>
                    <a href="<?= getUrl('jobticket/reports/status.php') ?>" class="report-link">
                        <i class="bi bi-graph-up"></i>
                        <span>Status Report</span>
                    </a>
                </div>
            </div>

            <div class="report-category">
                <div class="category-header">
                    <div class="category-icon forma">
                        <i class="bi bi-printer"></i>
                    </div>
                    <h3>Forma Reports</h3>
                </div>
                <div class="report-links">
                    <a href="<?= getUrl('forma/reports/daily.php') ?>" class="report-link">
                        <i class="bi bi-calendar-day"></i>
                        <span>Daily Report</span>
                    </a>
                    <a href="<?= getUrl('forma/reports/monthly.php') ?>" class="report-link">
                        <i class="bi bi-calendar-month"></i>
                        <span>Monthly Report</span>
                    </a>
                    <a href="<?= getUrl('forma/reports/printing_status.php') ?>" class="report-link">
                        <i class="bi bi-printer-fill"></i>
                        <span>Printing Status</span>
                    </a>
                </div>
            </div>
        </div>

        <!-- HR & General Reports Section -->
        <div class="section-header">
            <i class="bi bi-people-fill"></i>
            <h2>HR & Administrative Reports</h2>
        </div>

        <div class="reports-grid">
            <div class="report-category">
                <div class="category-header">
                    <div class="category-icon hr">
                        <i class="bi bi-person-badge"></i>
                    </div>
                    <h3>HR Reports</h3>
                </div>
                <div class="report-links">
                    <a href="<?= getUrl('hr/reports/employee_list.php') ?>" class="report-link">
                        <i class="bi bi-people"></i>
                        <span>Employee List</span>
                    </a>
                    <a href="<?= getUrl('hr/reports/department_wise.php') ?>" class="report-link">
                        <i class="bi bi-diagram-3"></i>
                        <span>Department Wise</span>
                    </a>
                    <a href="<?= getUrl('hr/reports/attendance.php') ?>" class="report-link">
                        <i class="bi bi-calendar-check"></i>
                        <span>Attendance Report</span>
                    </a>
                </div>
            </div>

            <div class="report-category">
                <div class="category-header">
                    <div class="category-icon general">
                        <i class="bi bi-bar-chart-line"></i>
                    </div>
                    <h3>General Reports</h3>
                </div>
                <div class="report-links">
                    <a href="<?= getUrl('reports/overall_production.php') ?>" class="report-link">
                        <i class="bi bi-graph-up-arrow"></i>
                        <span>Overall Production</span>
                    </a>
                    <a href="<?= getUrl('reports/efficiency.php') ?>" class="report-link">
                        <i class="bi bi-speedometer2"></i>
                        <span>Efficiency Report</span>
                    </a>
                    <a href="<?= getUrl('reports/analytics.php') ?>" class="report-link">
                        <i class="bi bi-clipboard-data"></i>
                        <span>Analytics Dashboard</span>
                    </a>
                </div>
            </div>
        </div>


        <?php if ($_SESSION['user_role'] === 'admin'): ?>
        <!-- Admin Directory Explorer -->
        <div class="section-header">
            <i class="bi bi-folder2-open"></i>
            <h2>Project Directory Structure (Admin Only)</h2>
        </div>

        <div class="directory-explorer">
            <div class="directory-tree" id="directoryTree">
                <div class="tree-item tree-folder" onclick="toggleFolder(this)">
                    <span class="tree-toggle">▼</span>
                    <i class="bi bi-folder-fill"></i> deno2 (Project Root)
                </div>
                <div class="tree-children expanded">
                    <?php 
                    function renderTree($items, $level = 0) {
                        foreach ($items as $item) {
                            if ($item['type'] === 'directory') {
                                echo '<div class="tree-item tree-folder" onclick="toggleFolder(this)" style="padding-left: ' . ($level * 20) . 'px;">';
                                echo '<span class="tree-toggle">►</span>';
                                echo '<i class="bi bi-folder"></i> ' . htmlspecialchars($item['name']);
                                echo '</div>';
                                if (!empty($item['children'])) {
                                    echo '<div class="tree-children">';
                                    renderTree($item['children'], $level + 1);
                                    echo '</div>';
                                }
                            } else {
                                $size = round($item['size'] / 1024, 2);
                                $modified = date('Y-m-d H:i', $item['modified']);
                                echo '<div class="tree-item tree-file" style="padding-left: ' . ($level * 20 + 20) . 'px;">';
                                echo '<i class="bi bi-file-earmark"></i> ' . htmlspecialchars($item['name']);
                                echo '<span class="file-info">(' . $size . ' KB - ' . $modified . ')</span>';
                                echo '</div>';
                            }
                        }
                    }
                    
                    if ($directory_structure) {
                        renderTree($directory_structure);
                    }
                    ?>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
    </div>

    <!-- Footer -->
    <footer>
        <p>&copy; <?= date('Y') ?> <span class="footer-brand">Janak Production Management System</span></p>
        <p>All Rights Reserved | Powered by Modern Technology</p>
    </footer>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Mobile Menu Toggle
        const mobileMenuBtn = document.getElementById('mobileMenuBtn');
        const mainNavbar = document.getElementById('mainNavbar');
        const mobileBackdrop = document.getElementById('mobileBackdrop');

        if (mobileMenuBtn) {
            mobileMenuBtn.addEventListener('click', function() {
                mainNavbar.classList.toggle('active');
                mobileBackdrop.classList.toggle('active');
            });
        }

        if (mobileBackdrop) {
            mobileBackdrop.addEventListener('click', function() {
                mainNavbar.classList.remove('active');
                mobileBackdrop.classList.remove('active');
            });
        }

        // Directory Tree Toggle
        function toggleFolder(element) {
            const children = element.nextElementSibling;
            const toggle = element.querySelector('.tree-toggle');
            
            if (children && children.classList.contains('tree-children')) {
                children.classList.toggle('expanded');
                toggle.textContent = children.classList.contains('expanded') ? '▼' : '►';
            }
        }

        // Add smooth scroll behavior
        document.querySelectorAll('a[href^="#"]').forEach(anchor => {
            anchor.addEventListener('click', function (e) {
                e.preventDefault();
                const target = document.querySelector(this.getAttribute('href'));
                if (target) {
                    target.scrollIntoView({
                        behavior: 'smooth'
                    });
                }
            });
        });
    </script>
</body>
</html>
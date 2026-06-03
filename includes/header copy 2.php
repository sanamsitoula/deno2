<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Janak Production Management System - Dashboard</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css">
    <style>
        /* Global Styles */
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

        /* Dashboard Content */
        .dashboard-container {
            max-width: 1200px;
            margin: 0 auto;
            padding: 2rem 1rem;
        }

        .status-bar {
            background: rgba(255,255,255,0.9);
            padding: 15px 20px;
            border-radius: 12px;
            margin-bottom: 25px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 15px;
        }

        .db-status {
            display: flex;
            align-items: center;
            gap: 10px;
            font-weight: 500;
        }

        .fiscal-year-badge {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 8px 16px;
            border-radius: 20px;
            font-weight: 500;
            box-shadow: 0 4px 15px rgba(102, 126, 234, 0.3);
        }

        /* Stats Cards */
        .stats-row {
            margin-bottom: 30px;
        }

        .stat-card {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            border-radius: 15px;
            padding: 25px;
            margin-bottom: 20px;
            box-shadow: 0 10px 30px rgba(102, 126, 234, 0.3);
            transition: transform 0.3s ease;
            border: none;
        }

        .stat-card:hover {
            transform: translateY(-5px);
        }

        .stat-card.bg-success {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
        }

        .stat-card.bg-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #feca57 100%);
        }

        .stat-value {
            font-size: 2.5rem;
            font-weight: 700;
            margin-bottom: 8px;
            text-shadow: 2px 2px 4px rgba(0,0,0,0.2);
        }

        .stat-label {
            font-size: 1rem;
            opacity: 0.9;
            font-weight: 500;
        }

        /* Production Cards */
        .production-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 25px;
            margin-bottom: 40px;
        }

        .production-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            padding: 25px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            transition: transform 0.3s ease;
        }

        .production-card:hover {
            transform: translateY(-5px);
        }

        .production-card h3 {
            color: #333;
            font-size: 1.3rem;
            font-weight: 600;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .production-stats {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
            margin-bottom: 20px;
        }

        .production-stat {
            text-align: center;
            padding: 15px;
            border-radius: 10px;
        }

        .total-production {
            background: linear-gradient(135deg, #56ab2f 0%, #a8e6cf 100%);
            color: white;
        }

        .openpcs {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
            color: white;
        }

        .production-stat .stat-value {
            font-size: 1.8rem;
            margin-bottom: 5px;
        }

        .production-stat .stat-label {
            font-size: 0.9rem;
        }

        .btn-custom {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            border: none;
            color: white;
            padding: 10px 20px;
            border-radius: 8px;
            font-weight: 500;
            transition: all 0.3s ease;
            text-decoration: none;
            display: inline-block;
        }

        .btn-custom:hover {
            color: white;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(102, 126, 234, 0.4);
        }

        /* Charts Section */
        .charts-section {
            margin-top: 40px;
        }

        .chart-card {
            background: rgba(255,255,255,0.95);
            backdrop-filter: blur(10px);
            border-radius: 15px;
            margin-bottom: 30px;
            box-shadow: 0 10px 30px rgba(0,0,0,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            overflow: hidden;
        }

        .chart-header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 20px;
            font-weight: 600;
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .chart-body {
            padding: 20px;
        }

        .chart-container {
            position: relative;
            height: 400px;
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

        /* Mobile Responsive */
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

            .status-bar {
                flex-direction: column;
                text-align: center;
            }

            .production-stats {
                grid-template-columns: 1fr;
            }

            .stat-value {
                font-size: 2rem;
            }
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
                        <a class="nav-link active" href="<?= getUrl('index.php') ?>">
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
                            <i class="bi bi-file-earmark-bar-graph"></i> Reports
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="<?= getUrl('reports/daily.php') ?>"><i class="bi bi-calendar-day"></i> Daily Report</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('reports/daywisemonth.php') ?>"><i class="bi bi-calendar-range"></i> Day Wise Month</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('reports/monthly.php') ?>"><i class="bi bi-calendar-month"></i> Monthly Report</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('reports/books.php') ?>"><i class="bi bi-book"></i> Books Report</a></li>
                            <li><a class="dropdown-item" href="<?= getUrl('reports/translated.php') ?>"><i class="bi bi-translate"></i> Translated Report</a></li>
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
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown">
                            <i class="bi bi-person-circle"></i> User (Admin)
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item" href="#user-management"><i class="bi bi-people"></i> User Management</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li><a class="dropdown-item" href="#logout"><i class="bi bi-box-arrow-right"></i> Logout</a></li>
                        </ul>
                    </li>
                </ul>
            </div>
        </nav>
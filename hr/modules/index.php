<?php
ob_start();
require_once 'config/database.php';
require_once 'includes/header.php';

// Permission check
if (!has_role('admin') && !has_role('hr') && !has_role('finance')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Get dashboard statistics
$stats = [];

// Employee Statistics
$emp_stats = $conn->query("
    SELECT 
        COUNT(*) as total,
        COUNT(*) FILTER (WHERE emp_status = 'ACTIVE') as active,
        COUNT(*) FILTER (WHERE emp_status = 'DRAFT') as draft,
        COUNT(*) FILTER (WHERE emp_type = 'PERMANENT') as permanent,
        COUNT(*) FILTER (WHERE emp_type = 'CONTRACT') as contract,
        COUNT(*) FILTER (WHERE emp_type = 'DAILY_WAGES') as daily_wages
    FROM employee 
    WHERE deleted_date IS NULL
")->fetch(PDO::FETCH_ASSOC);

// Attendance Statistics - Current Month
$current_month = date('Y-m');
$att_stats = $conn->query("
    SELECT 
        COUNT(DISTINCT employee_id) as total_employees,
        COUNT(*) FILTER (WHERE attendance_status = 'PRESENT') as present_count,
        COUNT(*) FILTER (WHERE attendance_status = 'ABSENT') as absent_count,
        COUNT(*) FILTER (WHERE attendance_status = 'LEAVE') as leave_count,
        ROUND(AVG(worked_minutes)/60, 2) as avg_hours
    FROM attendance 
    WHERE DATE_TRUNC('month', attendance_date) = DATE_TRUNC('month', CURRENT_DATE)
")->fetch(PDO::FETCH_ASSOC);

// Payroll Statistics - Current Month
$payroll_stats = $conn->query("
    SELECT 
        COUNT(*) as total_payrolls,
        SUM(total_gross) as total_gross,
        SUM(total_deductions) as total_deductions,
        SUM(total_net_payable) as total_net_payable
    FROM payroll_processing 
    WHERE payroll_month = EXTRACT(MONTH FROM CURRENT_DATE)
    AND payroll_year = EXTRACT(YEAR FROM CURRENT_DATE)
")->fetch(PDO::FETCH_ASSOC);

// Leave Statistics
$leave_stats = $conn->query("
    SELECT 
        COUNT(*) FILTER (WHERE status = 'PENDING') as pending_leaves,
        COUNT(*) FILTER (WHERE status = 'APPROVED') as approved_leaves,
        COUNT(*) FILTER (WHERE status = 'REJECTED') as rejected_leaves
    FROM leave_application
    WHERE EXTRACT(MONTH FROM from_date) = EXTRACT(MONTH FROM CURRENT_DATE)
    AND EXTRACT(YEAR FROM from_date) = EXTRACT(YEAR FROM CURRENT_DATE)
")->fetch(PDO::FETCH_ASSOC);

// Recent Activities
$recent_employees = $conn->query("
    SELECT id, code, name, emp_status, created_date
    FROM employee
    WHERE deleted_date IS NULL
    ORDER BY created_date DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Pending Leave Requests
$pending_leaves = $conn->query("
    SELECT 
        la.id, la.application_number, la.from_date, la.to_date, la.total_days,
        e.name as employee_name, e.code as employee_code,
        lt.leave_name
    FROM leave_application la
    JOIN employee e ON la.employee_id = e.id
    JOIN leave_types lt ON la.leave_type_id = lt.id
    WHERE la.status = 'PENDING'
    ORDER BY la.created_date DESC
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// Upcoming Birthdays
$upcoming_birthdays = $conn->query("
    SELECT id, code, name, dob, email
    FROM employee
    WHERE deleted_date IS NULL
    AND EXTRACT(MONTH FROM dob) = EXTRACT(MONTH FROM CURRENT_DATE)
    AND EXTRACT(DAY FROM dob) >= EXTRACT(DAY FROM CURRENT_DATE)
    ORDER BY EXTRACT(DAY FROM dob)
    LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>HR Dashboard - JEMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.min.css" rel="stylesheet">
    <style>
        .dashboard-card {
            border-radius: 10px;
            border: none;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s, box-shadow 0.2s;
        }
        .dashboard-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        }
        .stat-icon {
            width: 60px;
            height: 60px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 24px;
        }
        .bg-gradient-primary {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        }
        .bg-gradient-success {
            background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        }
        .bg-gradient-info {
            background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
        }
        .bg-gradient-warning {
            background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);
        }
        .bg-gradient-danger {
            background: linear-gradient(135deg, #ff6b6b 0%, #ee5a6f 100%);
        }
        .quick-action {
            padding: 15px;
            border-radius: 8px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #f8f9fa;
        }
        .quick-action:hover {
            background: #e9ecef;
            transform: scale(1.05);
        }
        .recent-activity-item {
            padding: 10px;
            border-left: 3px solid #667eea;
            margin-bottom: 10px;
            background: #f8f9fa;
        }
        .chart-container {
            position: relative;
            height: 300px;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="row mb-4">
            <div class="col-12">
                <h2 class="mb-0"><i class="fas fa-tachometer-alt text-primary"></i> HR Management Dashboard</h2>
                <p class="text-muted">Welcome back! Here's what's happening with your organization today.</p>
            </div>
        </div>

        <!-- Main Statistics Cards -->
        <div class="row mb-4">
            <!-- Total Employees -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-gradient-primary text-white me-3">
                                <i class="fas fa-users"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-1">Total Employees</h6>
                                <h3 class="mb-0"><?= number_format($emp_stats['total']) ?></h3>
                                <small class="text-success">
                                    <i class="fas fa-check-circle"></i> <?= number_format($emp_stats['active']) ?> Active
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Attendance Today -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-gradient-success text-white me-3">
                                <i class="fas fa-calendar-check"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-1">Present Today</h6>
                                <h3 class="mb-0"><?= number_format($att_stats['present_count'] ?? 0) ?></h3>
                                <small class="text-muted">
                                    <i class="fas fa-clock"></i> Avg: <?= $att_stats['avg_hours'] ?? 0 ?> hrs
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Monthly Payroll -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-gradient-info text-white me-3">
                                <i class="fas fa-money-bill-wave"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-1">Monthly Payroll</h6>
                                <h3 class="mb-0">₹<?= number_format($payroll_stats['total_net_payable'] ?? 0, 0) ?></h3>
                                <small class="text-muted">
                                    Net Payable
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Pending Leaves -->
            <div class="col-md-3 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <div class="d-flex align-items-center">
                            <div class="stat-icon bg-gradient-warning text-white me-3">
                                <i class="fas fa-envelope-open-text"></i>
                            </div>
                            <div class="flex-grow-1">
                                <h6 class="text-muted mb-1">Pending Leaves</h6>
                                <h3 class="mb-0"><?= number_format($leave_stats['pending_leaves'] ?? 0) ?></h3>
                                <small class="text-success">
                                    <i class="fas fa-check"></i> <?= number_format($leave_stats['approved_leaves'] ?? 0) ?> Approved
                                </small>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Employee Type Distribution -->
        <div class="row mb-4">
            <div class="col-md-4 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="fas fa-user-tie"></i> Permanent Staff</h6>
                        <h2 class="text-primary"><?= number_format($emp_stats['permanent']) ?></h2>
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-primary" style="width: <?= ($emp_stats['permanent'] / $emp_stats['total'] * 100) ?>%"></div>
                        </div>
                        <small class="text-muted"><?= number_format($emp_stats['permanent'] / $emp_stats['total'] * 100, 1) ?>% of total</small>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="fas fa-file-contract"></i> Contract Staff</h6>
                        <h2 class="text-success"><?= number_format($emp_stats['contract']) ?></h2>
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-success" style="width: <?= ($emp_stats['contract'] / $emp_stats['total'] * 100) ?>%"></div>
                        </div>
                        <small class="text-muted"><?= number_format($emp_stats['contract'] / $emp_stats['total'] * 100, 1) ?>% of total</small>
                    </div>
                </div>
            </div>

            <div class="col-md-4 mb-3">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="fas fa-coins"></i> Daily Wages</h6>
                        <h2 class="text-info"><?= number_format($emp_stats['daily_wages']) ?></h2>
                        <div class="progress" style="height: 5px;">
                            <div class="progress-bar bg-info" style="width: <?= ($emp_stats['daily_wages'] / $emp_stats['total'] * 100) ?>%"></div>
                        </div>
                        <small class="text-muted"><?= number_format($emp_stats['daily_wages'] / $emp_stats['total'] * 100, 1) ?>% of total</small>
                    </div>
                </div>
            </div>
        </div>

        <!-- Quick Actions -->
        <div class="row mb-4">
            <div class="col-12">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="fas fa-bolt"></i> Quick Actions</h6>
                        <div class="row">
                            <div class="col-md-2 col-6 mb-3">
                                <a href="modules/employees/create.php" class="text-decoration-none">
                                    <div class="quick-action">
                                        <i class="fas fa-user-plus fa-2x text-primary mb-2"></i>
                                        <p class="mb-0 small">Add Employee</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="modules/attendance/mark.php" class="text-decoration-none">
                                    <div class="quick-action">
                                        <i class="fas fa-calendar-check fa-2x text-success mb-2"></i>
                                        <p class="mb-0 small">Mark Attendance</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="modules/payroll/process.php" class="text-decoration-none">
                                    <div class="quick-action">
                                        <i class="fas fa-money-check-alt fa-2x text-info mb-2"></i>
                                        <p class="mb-0 small">Process Payroll</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="modules/leaves/apply.php" class="text-decoration-none">
                                    <div class="quick-action">
                                        <i class="fas fa-envelope fa-2x text-warning mb-2"></i>
                                        <p class="mb-0 small">Apply Leave</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="modules/reports/index.php" class="text-decoration-none">
                                    <div class="quick-action">
                                        <i class="fas fa-chart-bar fa-2x text-danger mb-2"></i>
                                        <p class="mb-0 small">Reports</p>
                                    </div>
                                </a>
                            </div>
                            <div class="col-md-2 col-6 mb-3">
                                <a href="modules/settings/index.php" class="text-decoration-none">
                                    <div class="quick-action">
                                        <i class="fas fa-cog fa-2x text-secondary mb-2"></i>
                                        <p class="mb-0 small">Settings</p>
                                    </div>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Recent Activities and Pending Actions -->
        <div class="row">
            <!-- Recent Employees -->
            <div class="col-md-4 mb-4">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="fas fa-history"></i> Recent Employees</h6>
                        <?php if (empty($recent_employees)): ?>
                            <p class="text-muted text-center py-3">No recent employees</p>
                        <?php else: ?>
                            <?php foreach ($recent_employees as $emp): ?>
                                <div class="recent-activity-item">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= htmlspecialchars($emp['name']) ?></strong>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($emp['code']) ?></small>
                                        </div>
                                        <span class="badge bg-<?= $emp['emp_status'] == 'ACTIVE' ? 'success' : 'warning' ?>">
                                            <?= $emp['emp_status'] ?>
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <i class="fas fa-clock"></i> <?= date('M d, Y', strtotime($emp['created_date'])) ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a href="modules/employees/index.php" class="btn btn-sm btn-outline-primary w-100 mt-2">
                            View All Employees
                        </a>
                    </div>
                </div>
            </div>

            <!-- Pending Leave Requests -->
            <div class="col-md-4 mb-4">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="fas fa-tasks"></i> Pending Leave Requests</h6>
                        <?php if (empty($pending_leaves)): ?>
                            <p class="text-muted text-center py-3">No pending requests</p>
                        <?php else: ?>
                            <?php foreach ($pending_leaves as $leave): ?>
                                <div class="recent-activity-item border-warning">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= htmlspecialchars($leave['employee_name']) ?></strong>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($leave['leave_name']) ?></small>
                                        </div>
                                        <span class="badge bg-warning text-dark">
                                            <?= $leave['total_days'] ?> days
                                        </span>
                                    </div>
                                    <small class="text-muted">
                                        <?= date('M d', strtotime($leave['from_date'])) ?> - <?= date('M d, Y', strtotime($leave['to_date'])) ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                        <a href="modules/leaves/pending.php" class="btn btn-sm btn-outline-warning w-100 mt-2">
                            View All Requests
                        </a>
                    </div>
                </div>
            </div>

            <!-- Upcoming Birthdays -->
            <div class="col-md-4 mb-4">
                <div class="card dashboard-card">
                    <div class="card-body">
                        <h6 class="card-title mb-3"><i class="fas fa-birthday-cake"></i> Upcoming Birthdays</h6>
                        <?php if (empty($upcoming_birthdays)): ?>
                            <p class="text-muted text-center py-3">No upcoming birthdays</p>
                        <?php else: ?>
                            <?php foreach ($upcoming_birthdays as $birthday): ?>
                                <div class="recent-activity-item border-info">
                                    <div class="d-flex justify-content-between align-items-start">
                                        <div>
                                            <strong><?= htmlspecialchars($birthday['name']) ?></strong>
                                            <br>
                                            <small class="text-muted"><?= htmlspecialchars($birthday['code']) ?></small>
                                        </div>
                                        <span class="badge bg-info">
                                            <?= date('M d', strtotime($birthday['dob'])) ?>
                                        </span>
                                    </div>
                                </div>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
</body>
</html>

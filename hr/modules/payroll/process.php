<?php
ob_start();
require_once '../../config/database.php';
require_once '../../includes/header.php';

if (!has_role('admin') && !has_role('finance')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;

// Handle payroll generation
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll'])) {
    $conn->beginTransaction();
    try {
        $month = (int)$_POST['payroll_month'];
        $year = (int)$_POST['payroll_year'];
        $fiscal_year_id = $_POST['fiscal_year_id'];
        
        // Check if payroll already exists
        $check = $conn->prepare("
            SELECT id FROM payroll_processing 
            WHERE payroll_month = :month AND payroll_year = :year
        ");
        $check->execute([':month' => $month, ':year' => $year]);
        
        if ($check->fetch()) {
            throw new Exception("Payroll for this month already exists!");
        }
        
        // Get first and last day of month
        $from_date = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $to_date = date('Y-m-t', strtotime($from_date));
        
        // Create payroll processing record
        $payroll_code = "PAY-$year-" . str_pad($month, 2, '0', STR_PAD_LEFT);
        
        $stmt = $conn->prepare("
            INSERT INTO payroll_processing (
                payroll_code, payroll_month, payroll_year, fiscal_year_id,
                from_date, to_date, status, created_by
            ) VALUES (
                :code, :month, :year, :fiscal_year_id,
                :from_date, :to_date, 'DRAFT', :created_by
            ) RETURNING id
        ");
        
        $stmt->execute([
            ':code' => $payroll_code,
            ':month' => $month,
            ':year' => $year,
            ':fiscal_year_id' => $fiscal_year_id,
            ':from_date' => $from_date,
            ':to_date' => $to_date,
            ':created_by' => $current_user_id
        ]);
        
        $payroll_id = $stmt->fetch()['id'];
        
        // Get all active employees
        $employees = $conn->query("
            SELECT 
                e.id, e.emp_type, e.salary_mode,
                es.basic_salary, es.daily_wage_rate, es.id as salary_id
            FROM employee e
            LEFT JOIN employee_salary es ON e.id = es.employee_id AND es.is_current = true
            WHERE e.emp_status = 'ACTIVE' AND e.deleted_date IS NULL
        ")->fetchAll(PDO::FETCH_ASSOC);
        
        $total_gross = 0;
        $total_deductions = 0;
        $total_net = 0;
        
        foreach ($employees as $emp) {
            // Get attendance summary
            $att_summary = $conn->prepare("
                SELECT 
                    COUNT(*) FILTER (WHERE attendance_status = 'PRESENT') as present_days,
                    COUNT(*) FILTER (WHERE attendance_status = 'ABSENT') as absent_days,
                    COUNT(*) FILTER (WHERE attendance_status = 'LEAVE') as leave_days,
                    COUNT(*) FILTER (WHERE attendance_status = 'HOLIDAY') as holiday_days,
                    COALESCE(SUM(worked_minutes), 0) as total_minutes,
                    COALESCE(SUM(overtime_minutes), 0) as overtime_minutes
                FROM attendance
                WHERE employee_id = :emp_id
                AND attendance_date BETWEEN :from_date AND :to_date
            ");
            $att_summary->execute([
                ':emp_id' => $emp['id'],
                ':from_date' => $from_date,
                ':to_date' => $to_date
            ]);
            $att = $att_summary->fetch(PDO::FETCH_ASSOC);
            
            // Calculate salary based on type
            $basic_salary = $emp['basic_salary'] ?? 0;
            $gross_salary = $basic_salary;
            
            // For daily wages, calculate based on attendance
            if ($emp['emp_type'] === 'DAILY_WAGES' && $emp['daily_wage_rate']) {
                $gross_salary = $emp['daily_wage_rate'] * $att['present_days'];
            }
            
            // Get salary components
            if ($emp['salary_id']) {
                $components = $conn->prepare("
                    SELECT 
                        sc.component_type,
                        sc.component_code,
                        esc.calculated_amount
                    FROM employee_salary_components esc
                    JOIN salary_components sc ON esc.component_id = sc.id
                    WHERE esc.employee_salary_id = :salary_id
                    AND esc.is_active = true
                ");
                $components->execute([':salary_id' => $emp['salary_id']]);
                
                $earnings = 0;
                $deductions = 0;
                
                while ($comp = $components->fetch(PDO::FETCH_ASSOC)) {
                    if ($comp['component_type'] === 'EARNING') {
                        $earnings += $comp['calculated_amount'];
                    } elseif ($comp['component_type'] === 'DEDUCTION') {
                        $deductions += $comp['calculated_amount'];
                    }
                }
                
                $gross_salary += $earnings;
                $total_deductions_emp = $deductions;
            } else {
                $total_deductions_emp = 0;
            }
            
            // Calculate overtime
            $overtime_hours = $att['overtime_minutes'] / 60;
            $overtime_amount = 0;
            
            if ($overtime_hours > 0 && $basic_salary > 0) {
                $hourly_rate = $basic_salary / (30 * 8); // Assuming 30 days, 8 hours per day
                $overtime_amount = $overtime_hours * $hourly_rate * 1.5; // 1.5x overtime rate
            }
            
            $gross_salary += $overtime_amount;
            $net_payable = $gross_salary - $total_deductions_emp;
            
            // Insert payroll detail
            $stmt = $conn->prepare("
                INSERT INTO payroll_details (
                    payroll_processing_id, employee_id,
                    total_working_days, total_present_days, total_absent_days,
                    total_leaves, total_holidays, total_paid_days,
                    overtime_hours, overtime_amount,
                    basic_salary, gross_salary, total_earnings,
                    total_deductions, net_payable,
                    daily_wage_rate, created_by
                ) VALUES (
                    :payroll_id, :emp_id,
                    30, :present, :absent,
                    :leaves, :holidays, :present,
                    :ot_hours, :ot_amount,
                    :basic, :gross, :gross,
                    :deductions, :net,
                    :daily_rate, :created_by
                )
            ");
            
            $stmt->execute([
                ':payroll_id' => $payroll_id,
                ':emp_id' => $emp['id'],
                ':present' => $att['present_days'],
                ':absent' => $att['absent_days'],
                ':leaves' => $att['leave_days'],
                ':holidays' => $att['holiday_days'],
                ':ot_hours' => $overtime_hours,
                ':ot_amount' => $overtime_amount,
                ':basic' => $basic_salary,
                ':gross' => $gross_salary,
                ':deductions' => $total_deductions_emp,
                ':net' => $net_payable,
                ':daily_rate' => $emp['daily_wage_rate'],
                ':created_by' => $current_user_id
            ]);
            
            $total_gross += $gross_salary;
            $total_deductions += $total_deductions_emp;
            $total_net += $net_payable;
        }
        
        // Update payroll processing totals
        $stmt = $conn->prepare("
            UPDATE payroll_processing SET
                total_employees = :count,
                total_gross = :gross,
                total_deductions = :deductions,
                total_net_payable = :net,
                status = 'CALCULATED',
                processed_by = :user_id,
                processed_date = NOW()
            WHERE id = :id
        ");
        
        $stmt->execute([
            ':count' => count($employees),
            ':gross' => $total_gross,
            ':deductions' => $total_deductions,
            ':net' => $total_net,
            ':user_id' => $current_user_id,
            ':id' => $payroll_id
        ]);
        
        $conn->commit();
        $_SESSION['success_message'] = "Payroll generated successfully for " . date('F Y', strtotime($from_date));
        header("Location: view.php?id=$payroll_id");
        exit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// Get fiscal years
$fiscal_years = $conn->query("
    SELECT id, fiscal_code, start_date, end_date, is_active 
    FROM fiscal_years 
    ORDER BY start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get recent payrolls
$recent_payrolls = $conn->query("
    SELECT 
        id, payroll_code, payroll_month, payroll_year,
        total_employees, total_net_payable, status,
        created_date
    FROM payroll_processing
    ORDER BY payroll_year DESC, payroll_month DESC
    LIMIT 10
")->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payroll</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .status-DRAFT { background-color: #ffc107; color: black; }
        .status-PROCESSING { background-color: #17a2b8; color: white; }
        .status-CALCULATED { background-color: #28a745; color: white; }
        .status-APPROVED { background-color: #007bff; color: white; }
        .status-PAID { background-color: #6c757d; color: white; }
        .status-LOCKED { background-color: #dc3545; color: white; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="fas fa-money-check-alt"></i> Process Payroll</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Payroll</a></li>
                        <li class="breadcrumb-item active">Process</li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Generate New Payroll -->
            <div class="col-md-5 mb-4">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> Generate New Payroll</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST" id="payrollForm">
                            <div class="mb-3">
                                <label class="form-label">Select Month & Year <span class="text-danger">*</span></label>
                                <div class="row">
                                    <div class="col-md-6">
                                        <select name="payroll_month" class="form-select" required>
                                            <option value="">Select Month</option>
                                            <option value="1">January</option>
                                            <option value="2">February</option>
                                            <option value="3">March</option>
                                            <option value="4">April</option>
                                            <option value="5">May</option>
                                            <option value="6">June</option>
                                            <option value="7">July</option>
                                            <option value="8">August</option>
                                            <option value="9">September</option>
                                            <option value="10">October</option>
                                            <option value="11">November</option>
                                            <option value="12">December</option>
                                        </select>
                                    </div>
                                    <div class="col-md-6">
                                        <select name="payroll_year" class="form-select" required>
                                            <option value="">Select Year</option>
                                            <?php for ($y = date('Y'); $y >= date('Y') - 5; $y--): ?>
                                                <option value="<?= $y ?>" <?= $y == date('Y') ? 'selected' : '' ?>><?= $y ?></option>
                                            <?php endfor; ?>
                                        </select>
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Fiscal Year <span class="text-danger">*</span></label>
                                <select name="fiscal_year_id" class="form-select" required>
                                    <option value="">Select Fiscal Year</option>
                                    <?php foreach ($fiscal_years as $fy): ?>
                                        <option value="<?= $fy['id'] ?>" <?= $fy['is_active'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($fy['fiscal_code']) ?>
                                            <?= $fy['is_active'] ? ' (Active)' : '' ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="alert alert-info">
                                <i class="fas fa-info-circle"></i> 
                                <strong>Note:</strong> This will process payroll for all active employees based on their:
                                <ul class="mb-0 mt-2">
                                    <li>Current salary structure</li>
                                    <li>Attendance for the selected month</li>
                                    <li>Applicable deductions and allowances</li>
                                    <li>Overtime hours worked</li>
                                </ul>
                            </div>

                            <button type="submit" name="generate_payroll" class="btn btn-primary w-100">
                                <i class="fas fa-cogs"></i> Generate Payroll
                            </button>
                        </form>
                    </div>
                </div>
            </div>

            <!-- Recent Payrolls -->
            <div class="col-md-7 mb-4">
                <div class="card shadow">
                    <div class="card-header bg-light">
                        <h5 class="mb-0"><i class="fas fa-history"></i> Recent Payrolls</h5>
                    </div>
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th>Code</th>
                                        <th>Period</th>
                                        <th>Employees</th>
                                        <th>Net Payable</th>
                                        <th>Status</th>
                                        <th>Actions</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($recent_payrolls)): ?>
                                        <tr>
                                            <td colspan="6" class="text-center py-4">
                                                <i class="fas fa-info-circle"></i> No payroll records found
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($recent_payrolls as $payroll): ?>
                                            <tr>
                                                <td><strong><?= htmlspecialchars($payroll['payroll_code']) ?></strong></td>
                                                <td>
                                                    <?= date('F Y', strtotime($payroll['payroll_year'] . '-' . str_pad($payroll['payroll_month'], 2, '0', STR_PAD_LEFT) . '-01')) ?>
                                                </td>
                                                <td><?= number_format($payroll['total_employees']) ?></td>
                                                <td>₹<?= number_format($payroll['total_net_payable'], 2) ?></td>
                                                <td>
                                                    <span class="badge status-<?= $payroll['status'] ?>">
                                                        <?= $payroll['status'] ?>
                                                    </span>
                                                </td>
                                                <td>
                                                    <a href="view.php?id=<?= $payroll['id'] ?>" class="btn btn-sm btn-outline-primary">
                                                        <i class="fas fa-eye"></i> View
                                                    </a>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Form validation
        document.getElementById('payrollForm').addEventListener('submit', function(e) {
            const month = this.payroll_month.value;
            const year = this.payroll_year.value;
            const fiscalYear = this.fiscal_year_id.value;
            
            if (!month || !year || !fiscalYear) {
                e.preventDefault();
                alert('Please fill in all required fields');
                return false;
            }
            
            // Confirm before processing
            const monthName = this.payroll_month.options[this.payroll_month.selectedIndex].text;
            if (!confirm(`Are you sure you want to generate payroll for ${monthName} ${year}?`)) {
                e.preventDefault();
                return false;
            }
            
            // Disable submit button to prevent double submission
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
        });
    </script>
</body>
</html>

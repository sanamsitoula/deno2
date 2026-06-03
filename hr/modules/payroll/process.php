<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!has_role('admin') && !has_role('finance') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? 0;

// ── Handle payroll generation ──────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['generate_payroll'])) {
    $conn->beginTransaction();
    try {
        $month          = (int)$_POST['payroll_month'];
        $year           = (int)$_POST['payroll_year'];
        $fiscal_year_id = (int)$_POST['fiscal_year_id'];

        if ($month < 1 || $month > 12 || $year < 2000) {
            throw new Exception("Invalid month or year.");
        }

        // Guard: no duplicate
        $check = $conn->prepare("SELECT id FROM payroll_processing WHERE payroll_month=:m AND payroll_year=:y");
        $check->execute([':m' => $month, ':y' => $year]);
        if ($check->fetch()) {
            throw new Exception("Payroll for this month/year already exists!");
        }

        $from_date    = "$year-" . str_pad($month, 2, '0', STR_PAD_LEFT) . "-01";
        $to_date      = date('Y-m-t', strtotime($from_date));
        $payroll_code = "PAY-$year-" . str_pad($month, 2, '0', STR_PAD_LEFT);

        // Create header
        $stmt = $conn->prepare("
            INSERT INTO payroll_processing (
                payroll_code, payroll_month, payroll_year, fiscal_year_id,
                from_date, to_date, status, created_by, created_at
            ) VALUES (
                :code, :month, :year, :fy,
                :from_date, :to_date, 'DRAFT', :created_by, NOW()
            ) RETURNING id
        ");
        $stmt->execute([
            ':code'       => $payroll_code,
            ':month'      => $month,
            ':year'       => $year,
            ':fy'         => $fiscal_year_id,
            ':from_date'  => $from_date,
            ':to_date'    => $to_date,
            ':created_by' => $current_user_id,
        ]);
        $payroll_id = (int)$stmt->fetchColumn();

        // Get active employees with salary
        $employees = $conn->query("
            SELECT e.id, e.emp_type,
                   COALESCE(es.basic_salary, 0) AS basic_salary
            FROM employee e
            LEFT JOIN employee_salary es ON es.employee_id = e.id AND es.is_current = true
            WHERE e.emp_status = 'ACTIVE' AND e.deleted_date IS NULL
        ")->fetchAll(PDO::FETCH_ASSOC);

        $total_gross = 0; $total_ded = 0; $total_net = 0;
        $working_days = 26; // standard working days per month

        foreach ($employees as $emp) {
            $basic = (float)$emp['basic_salary'];

            // Attendance summary — uses attendance_date_eng and ot_hours
            $attStmt = $conn->prepare("
                SELECT
                    COUNT(*) FILTER (WHERE ast.status_code = 'P')  AS present_days,
                    COUNT(*) FILTER (WHERE ast.status_code = 'A')  AS absent_days,
                    COUNT(*) FILTER (WHERE ast.status_code = 'L')  AS leave_days,
                    COUNT(*) FILTER (WHERE ast.status_code = 'H')  AS holiday_days,
                    COALESCE(SUM(a.ot_hours), 0)                   AS ot_hours
                FROM attendance a
                LEFT JOIN attendance_status ast ON a.status_id = ast.id
                WHERE a.employee_id = :emp_id
                  AND a.attendance_date_eng BETWEEN :from_date AND :to_date
            ");
            $attStmt->execute([
                ':emp_id'    => $emp['id'],
                ':from_date' => $from_date,
                ':to_date'   => $to_date,
            ]);
            $att = $attStmt->fetch(PDO::FETCH_ASSOC);

            $present  = (int)($att['present_days'] ?? 0);
            $absent   = (int)($att['absent_days']  ?? 0);
            $leaves   = (int)($att['leave_days']   ?? 0);
            $holidays = (int)($att['holiday_days'] ?? 0);
            $otHours  = (float)($att['ot_hours']   ?? 0);

            // Proportional basic (deduct unpaid absences)
            $paidDays    = max(0, $working_days - $absent);
            $perDay      = $working_days > 0 ? $basic / $working_days : 0;
            $effectBasic = round($perDay * $paidDays, 2);

            // OT: 1.5× hourly rate
            $hourlyRate  = $working_days > 0 ? ($basic / ($working_days * 8)) : 0;
            $otAmount    = round($otHours * $hourlyRate * 1.5, 2);

            $grossSalary    = $effectBasic + $otAmount;

            // Simple deduction estimate (SSF 11% if applicable — no SSF flag yet)
            $totalDedEmp = 0;
            $netPayable  = round($grossSalary - $totalDedEmp, 2);

            // Insert payroll detail
            $ins = $conn->prepare("
                INSERT INTO payroll_details (
                    payroll_processing_id, employee_id,
                    total_working_days, total_present_days, total_absent_days,
                    total_leaves, total_holidays, total_paid_days,
                    overtime_hours, overtime_amount,
                    basic_salary, total_earnings, gross_salary,
                    ssf_employee, ssf_employer, pf_employee, pf_employer,
                    income_tax, other_deductions, total_deductions,
                    net_payable, status, created_by, created_at
                ) VALUES (
                    :pp_id, :emp_id,
                    :working, :present, :absent,
                    :leaves, :holidays, :paid,
                    :ot_h, :ot_amt,
                    :basic, :earnings, :gross,
                    0, 0, 0, 0,
                    0, 0, :total_ded,
                    :net, 'CALCULATED', :created_by, NOW()
                )
            ");
            $ins->execute([
                ':pp_id'      => $payroll_id,
                ':emp_id'     => $emp['id'],
                ':working'    => $working_days,
                ':present'    => $present,
                ':absent'     => $absent,
                ':leaves'     => $leaves,
                ':holidays'   => $holidays,
                ':paid'       => $paidDays,
                ':ot_h'       => $otHours,
                ':ot_amt'     => $otAmount,
                ':basic'      => $effectBasic,
                ':earnings'   => $grossSalary,
                ':gross'      => $grossSalary,
                ':total_ded'  => $totalDedEmp,
                ':net'        => $netPayable,
                ':created_by' => $current_user_id,
            ]);

            $total_gross += $grossSalary;
            $total_ded   += $totalDedEmp;
            $total_net   += $netPayable;
        }

        // Update header totals — use correct column: processed_at (not processed_date)
        $upd = $conn->prepare("
            UPDATE payroll_processing SET
                total_employees   = :cnt,
                total_gross       = :gross,
                total_deductions  = :ded,
                total_net_payable = :net,
                status            = 'CALCULATED',
                processed_by      = :user_id,
                processed_at      = NOW()
            WHERE id = :id
        ");
        $upd->execute([
            ':cnt'     => count($employees),
            ':gross'   => $total_gross,
            ':ded'     => $total_ded,
            ':net'     => $total_net,
            ':user_id' => $current_user_id,
            ':id'      => $payroll_id,
        ]);

        $conn->commit();
        $_SESSION['success_message'] = "Payroll generated: $payroll_code (" . count($employees) . " employees, Net: NPR " . number_format($total_net, 2) . ")";
        header("Location: process.php");
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error generating payroll: " . $e->getMessage();
    }
}

// ── Fetch data for display ─────────────────────────────────────
$fiscal_years = $conn->query("
    SELECT id, fiscal_code, start_date, end_date, is_active
    FROM fiscal_years ORDER BY start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Recent payrolls — correct column: created_at (not created_date)
$recent_payrolls = $conn->query("
    SELECT id, payroll_code, payroll_month, payroll_year,
           total_employees, total_gross, total_net_payable, status, created_at
    FROM payroll_processing
    ORDER BY payroll_year DESC, payroll_month DESC
    LIMIT 12
")->fetchAll(PDO::FETCH_ASSOC);

// Quick stats
$empWithSalary  = $conn->query("SELECT COUNT(DISTINCT employee_id) FROM employee_salary WHERE is_current=true")->fetchColumn();
$activeEmpCount = $conn->query("SELECT COUNT(*) FROM employee WHERE emp_status='ACTIVE' AND deleted_date IS NULL")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Process Payroll — JEMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background: #f0f2f8; }
        .status-DRAFT      { background:#ffc107;color:#000; }
        .status-CALCULATED { background:#28a745;color:#fff; }
        .status-APPROVED   { background:#007bff;color:#fff; }
        .status-PAID       { background:#6c757d;color:#fff; }
        .status-LOCKED     { background:#dc3545;color:#fff; }
    </style>
</head>
<body>
<div class="container-fluid mt-4" style="max-width:1200px">

    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h3 class="mb-0"><i class="fas fa-money-check-alt me-2"></i>Process Payroll</h3>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size:.8rem">
                    <li class="breadcrumb-item"><a href="/deno2/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Payroll</li>
                </ol>
            </nav>
        </div>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Quick stats -->
    <div class="row g-3 mb-4">
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-primary"><?= $activeEmpCount ?></div>
                <div class="text-muted small">Active Employees</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-success"><?= $empWithSalary ?></div>
                <div class="text-muted small">With Salary Record</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-warning"><?= count($recent_payrolls) ?></div>
                <div class="text-muted small">Payroll Runs</div>
            </div>
        </div>
        <div class="col-md-3">
            <div class="card border-0 shadow-sm text-center p-3">
                <div class="fs-2 fw-bold text-info">
                    <?= $recent_payrolls ? 'NPR ' . number_format($recent_payrolls[0]['total_net_payable'] / 1000, 0) . 'K' : '—' ?>
                </div>
                <div class="text-muted small">Last Net Payable</div>
            </div>
        </div>
    </div>

    <div class="row g-4">
        <!-- Generate Form -->
        <div class="col-md-5">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-plus-circle me-2"></i>Generate New Payroll</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="payrollForm">
                        <div class="mb-3">
                            <label class="form-label fw-semibold">BS Month (Nepali)</label>
                            <select name="payroll_month" class="form-select" required>
                                <option value="">Select Month</option>
                                <?php
                                $bsMonths = ['1'=>'Baisakh','2'=>'Jestha','3'=>'Ashadh','4'=>'Shrawan',
                                             '5'=>'Bhadra','6'=>'Ashwin','7'=>'Kartik','8'=>'Mangsir',
                                             '9'=>'Poush','10'=>'Magh','11'=>'Falgun','12'=>'Chaitra'];
                                foreach ($bsMonths as $num => $name):
                                ?>
                                <option value="<?= $num ?>"><?= $num ?> — <?= $name ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">BS Year</label>
                            <select name="payroll_year" class="form-select" required>
                                <option value="">Select Year</option>
                                <?php for ($y = 2082; $y >= 2078; $y--): ?>
                                <option value="<?= $y ?>" <?= $y == 2082 ? 'selected' : '' ?>><?= $y ?></option>
                                <?php endfor; ?>
                            </select>
                        </div>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Fiscal Year</label>
                            <select name="fiscal_year_id" class="form-select" required>
                                <option value="">Select Fiscal Year</option>
                                <?php foreach ($fiscal_years as $fy): ?>
                                <option value="<?= $fy['id'] ?>" <?= $fy['is_active'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fy['fiscal_code']) ?><?= $fy['is_active'] ? ' (Active)' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <div class="alert alert-info py-2 small">
                            <i class="fas fa-info-circle me-1"></i>
                            Processes <strong><?= $activeEmpCount ?> active employees</strong> — calculates basic salary, OT, and deductions based on attendance.
                        </div>
                        <button type="submit" name="generate_payroll" class="btn btn-primary w-100">
                            <i class="fas fa-cogs me-2"></i>Generate Payroll
                        </button>
                    </form>
                </div>
            </div>
        </div>

        <!-- Recent Payrolls -->
        <div class="col-md-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-light">
                    <h5 class="mb-0"><i class="fas fa-history me-2"></i>Recent Payroll Runs</h5>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-hover mb-0" style="font-size:.85rem">
                            <thead class="table-light">
                                <tr>
                                    <th>Code</th>
                                    <th>Period (BS)</th>
                                    <th class="text-center">Emp</th>
                                    <th class="text-end">Gross</th>
                                    <th class="text-end">Net Payable</th>
                                    <th>Status</th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                            <?php if (empty($recent_payrolls)): ?>
                                <tr>
                                    <td colspan="7" class="text-center py-5 text-muted">
                                        <i class="fas fa-inbox fa-2x mb-2 d-block"></i>
                                        No payroll runs yet.<br>
                                        <small>Generate your first payroll using the form.</small>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php
                                $bsM = ['1'=>'Baisakh','2'=>'Jestha','3'=>'Ashadh','4'=>'Shrawan',
                                        '5'=>'Bhadra','6'=>'Ashwin','7'=>'Kartik','8'=>'Mangsir',
                                        '9'=>'Poush','10'=>'Magh','11'=>'Falgun','12'=>'Chaitra'];
                                foreach ($recent_payrolls as $p):
                                ?>
                                <tr>
                                    <td><strong><?= htmlspecialchars($p['payroll_code']) ?></strong></td>
                                    <td><?= $bsM[$p['payroll_month']] ?? $p['payroll_month'] ?> <?= $p['payroll_year'] ?></td>
                                    <td class="text-center"><?= number_format($p['total_employees']) ?></td>
                                    <td class="text-end">NPR <?= number_format($p['total_gross'], 0) ?></td>
                                    <td class="text-end fw-semibold text-success">NPR <?= number_format($p['total_net_payable'], 0) ?></td>
                                    <td>
                                        <span class="badge status-<?= $p['status'] ?>"><?= $p['status'] ?></span>
                                    </td>
                                    <td>
                                        <a href="view.php?id=<?= $p['id'] ?>" class="btn btn-xs btn-outline-primary" style="font-size:.72rem;padding:2px 8px">
                                            <i class="fas fa-eye"></i>
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
document.getElementById('payrollForm').addEventListener('submit', function(e) {
    const month = this.payroll_month.value;
    const year  = this.payroll_year.value;
    const fy    = this.fiscal_year_id.value;
    if (!month || !year || !fy) {
        e.preventDefault();
        alert('Please fill in all required fields');
        return;
    }
    const mName = this.payroll_month.options[this.payroll_month.selectedIndex].text;
    if (!confirm('Generate payroll for ' + mName + ' ' + year + '?\n\nThis will process all active employees.')) {
        e.preventDefault();
        return;
    }
    const btn = this.querySelector('button[type="submit"]');
    btn.disabled = true;
    btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Processing...';
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>

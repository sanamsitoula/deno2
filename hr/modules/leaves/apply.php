<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$current_user_id = $_SESSION['user_id'] ?? null;
$current_employee_id = $_SESSION['employee_id'] ?? null;

// Handle leave application
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->beginTransaction();
    try {
        $employee_id = has_role('admin') || has_role('hr') ? $_POST['employee_id'] : $current_employee_id;
        $leave_type_id = $_POST['leave_type_id'];
        $from_date = $_POST['from_date'];
        $to_date = $_POST['to_date'];
        $is_half_day = isset($_POST['is_half_day']) ? true : false;
        $reason = $_POST['reason'];
        
        // Calculate total days
        $start = new DateTime($from_date);
        $end = new DateTime($to_date);
        $total_days = $end->diff($start)->days + 1;
        
        if ($is_half_day) {
            $total_days = 0.5;
        }
        
        // Check leave balance
        $balance_check = $conn->prepare("
            SELECT closing_balance 
            FROM leave_balance 
            WHERE employee_id = :emp_id 
            AND leave_type_id = :type_id
            AND fiscal_year_id = (SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1)
        ");
        $balance_check->execute([
            ':emp_id' => $employee_id,
            ':type_id' => $leave_type_id
        ]);
        $balance = $balance_check->fetch();
        
        if (!$balance || $balance['closing_balance'] < $total_days) {
            throw new Exception("Insufficient leave balance");
        }
        
        // Insert leave application
        $stmt = $conn->prepare("
            INSERT INTO leave_application (
                employee_id, leave_type_id, from_date, to_date,
                is_half_day, total_days, reason, status, created_by
            ) VALUES (
                :emp_id, :type_id, :from_date, :to_date,
                :is_half_day, :total_days, :reason, 'PENDING', :created_by
            )
        ");
        
        $stmt->execute([
            ':emp_id' => $employee_id,
            ':type_id' => $leave_type_id,
            ':from_date' => $from_date,
            ':to_date' => $to_date,
            ':is_half_day' => $is_half_day,
            ':total_days' => $total_days,
            ':reason' => $reason,
            ':created_by' => $current_user_id
        ]);
        
        $conn->commit();
        $_SESSION['success_message'] = "Leave application submitted successfully!";
        header("Location: my_leaves.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// Get leave types
$leave_types = $conn->query("
    SELECT id, leave_name, max_days_per_year 
    FROM leave_types 
    WHERE status = true
    ORDER BY leave_name
")->fetchAll(PDO::FETCH_ASSOC);

// Get employee leave balances (if logged in as employee)
$leave_balances = [];
if ($current_employee_id) {
    $leave_balances = $conn->prepare("
        SELECT 
            lt.leave_name,
            lb.closing_balance,
            lb.accrued,
            lb.taken
        FROM leave_balance lb
        JOIN leave_types lt ON lb.leave_type_id = lt.id
        WHERE lb.employee_id = :emp_id
        AND lb.fiscal_year_id = (SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1)
    ");
    $leave_balances->execute([':emp_id' => $current_employee_id]);
    $leave_balances = $leave_balances->fetchAll(PDO::FETCH_ASSOC);
}

// Get all employees (if admin/hr)
$employees = [];
if (has_role('admin') || has_role('hr')) {
    $employees = $conn->query("
        SELECT id, code, name 
        FROM employee 
        WHERE emp_status = 'ACTIVE' AND deleted_date IS NULL
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply Leave</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="fas fa-envelope"></i> Apply for Leave</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Leaves</a></li>
                        <li class="breadcrumb-item active">Apply</li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="row">
            <!-- Leave Balance Summary -->
            <?php if (!empty($leave_balances)): ?>
                <div class="col-md-4 mb-4">
                    <div class="card shadow">
                        <div class="card-header bg-info text-white">
                            <h6 class="mb-0"><i class="fas fa-chart-pie"></i> Your Leave Balance</h6>
                        </div>
                        <div class="card-body">
                            <?php foreach ($leave_balances as $balance): ?>
                                <div class="mb-3">
                                    <div class="d-flex justify-content-between align-items-center mb-1">
                                        <strong><?= htmlspecialchars($balance['leave_name']) ?></strong>
                                        <span class="badge bg-primary"><?= $balance['closing_balance'] ?> days</span>
                                    </div>
                                    <div class="progress" style="height: 5px;">
                                        <div class="progress-bar bg-success" style="width: <?= ($balance['closing_balance'] / ($balance['accrued'] ?: 1)) * 100 ?>%"></div>
                                    </div>
                                    <small class="text-muted">
                                        Accrued: <?= $balance['accrued'] ?> | Taken: <?= $balance['taken'] ?>
                                    </small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <!-- Leave Application Form -->
            <div class="col-md-<?= !empty($leave_balances) ? '8' : '12' ?> mb-4">
                <div class="card shadow">
                    <div class="card-header bg-primary text-white">
                        <h5 class="mb-0"><i class="fas fa-plus-circle"></i> New Leave Application</h5>
                    </div>
                    <div class="card-body">
                        <form method="POST">
                            <?php if (has_role('admin') || has_role('hr')): ?>
                                <div class="mb-3">
                                    <label class="form-label">Select Employee <span class="text-danger">*</span></label>
                                    <select name="employee_id" class="form-select" required>
                                        <option value="">Choose Employee</option>
                                        <?php foreach ($employees as $emp): ?>
                                            <option value="<?= $emp['id'] ?>">
                                                <?= htmlspecialchars($emp['code']) ?> - <?= htmlspecialchars($emp['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>

                            <div class="mb-3">
                                <label class="form-label">Leave Type <span class="text-danger">*</span></label>
                                <select name="leave_type_id" class="form-select" required>
                                    <option value="">Select Leave Type</option>
                                    <?php foreach ($leave_types as $type): ?>
                                        <option value="<?= $type['id'] ?>">
                                            <?= htmlspecialchars($type['leave_name']) ?>
                                            (Max: <?= $type['max_days_per_year'] ?> days/year)
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>

                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">From Date <span class="text-danger">*</span></label>
                                        <input type="date" name="from_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label class="form-label">To Date <span class="text-danger">*</span></label>
                                        <input type="date" name="to_date" class="form-control" required min="<?= date('Y-m-d') ?>">
                                    </div>
                                </div>
                            </div>

                            <div class="mb-3">
                                <div class="form-check">
                                    <input type="checkbox" name="is_half_day" class="form-check-input" id="half_day">
                                    <label class="form-check-label" for="half_day">
                                        This is a half-day leave
                                    </label>
                                </div>
                            </div>

                            <div class="mb-3">
                                <label class="form-label">Reason <span class="text-danger">*</span></label>
                                <textarea name="reason" class="form-control" rows="4" required placeholder="Please provide reason for leave..."></textarea>
                            </div>

                            <div class="d-flex justify-content-between">
                                <a href="my_leaves.php" class="btn btn-secondary">
                                    <i class="fas fa-times"></i> Cancel
                                </a>
                                <button type="submit" class="btn btn-primary">
                                    <i class="fas fa-paper-plane"></i> Submit Application
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-set to_date when from_date changes
        document.querySelector('input[name="from_date"]').addEventListener('change', function() {
            const toDate = document.querySelector('input[name="to_date"]');
            if (!toDate.value) {
                toDate.value = this.value;
                toDate.min = this.value;
            }
        });

        // Disable to_date selection for half-day leave
        document.getElementById('half_day').addEventListener('change', function() {
            const fromDate = document.querySelector('input[name="from_date"]');
            const toDate = document.querySelector('input[name="to_date"]');
            
            if (this.checked) {
                toDate.value = fromDate.value;
                toDate.disabled = true;
            } else {
                toDate.disabled = false;
            }
        });
    </script>
</body>
</html>

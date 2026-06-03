<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$current_user_id     = $_SESSION['user_id'] ?? 0;
$current_employee_id = $_SESSION['employee_id'] ?? null;

// ── Handle submission ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->beginTransaction();
    try {
        $employee_id = (has_role('admin') || has_role('hr'))
            ? (int)$_POST['employee_id']
            : (int)$current_employee_id;

        $leave_type = trim($_POST['leave_type']);   // varchar stored in leave_balance.leave_type
        $from_date  = $_POST['from_date'];
        $to_date    = $_POST['to_date'];
        $is_half    = isset($_POST['is_half_day']);
        $reason     = trim($_POST['reason'] ?? '');

        if (!$employee_id) throw new Exception("No employee selected.");
        if (!$leave_type)  throw new Exception("Please select a leave type.");

        $total_days = $is_half ? 0.5 : ((new DateTime($from_date))->diff(new DateTime($to_date))->days + 1);

        // Check balance using actual schema: leave_balance(employee_id, fiscal_year, leave_type, balance_leaves)
        $bal = $conn->prepare("
            SELECT balance_leaves FROM leave_balance
            WHERE employee_id = :e AND leave_type = :lt
              AND fiscal_year = (SELECT fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1)
        ");
        $bal->execute([':e' => $employee_id, ':lt' => $leave_type]);
        $row = $bal->fetch(PDO::FETCH_ASSOC);

        if (!$row || (float)$row['balance_leaves'] < $total_days) {
            throw new Exception("Insufficient leave balance. Available: " . ($row['balance_leaves'] ?? 0) . " days.");
        }

        // Deduct from balance
        $conn->prepare("
            UPDATE leave_balance
            SET used_leaves    = used_leaves + :days,
                balance_leaves = balance_leaves - :days,
                updated_at     = NOW()
            WHERE employee_id = :e AND leave_type = :lt
              AND fiscal_year = (SELECT fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1)
        ")->execute([':days' => $total_days, ':e' => $employee_id, ':lt' => $leave_type]);

        // Mark attendance as Leave for each day in range
        $current = new DateTime($from_date);
        $end     = new DateTime($to_date);
        $leaveStatusId = $conn->query("SELECT id FROM attendance_status WHERE status_code='L' LIMIT 1")->fetchColumn();

        while ($current <= $end) {
            $dateStr = $current->format('Y-m-d');
            $chk = $conn->prepare("SELECT id FROM attendance WHERE employee_id=:e AND attendance_date_eng=:d");
            $chk->execute([':e'=>$employee_id,':d'=>$dateStr]);
            if ($chk->fetch()) {
                $conn->prepare("UPDATE attendance SET status_id=:s, data_source='MANUAL', updated_at=NOW() WHERE employee_id=:e AND attendance_date_eng=:d")
                     ->execute([':s'=>$leaveStatusId,':e'=>$employee_id,':d'=>$dateStr]);
            } else {
                $conn->prepare("INSERT INTO attendance (employee_id,attendance_date_eng,status_id,marked_by,created_at,data_source) VALUES (:e,:d,:s,:m,NOW(),'MANUAL')")
                     ->execute([':e'=>$employee_id,':d'=>$dateStr,':s'=>$leaveStatusId,':m'=>$current_user_id]);
            }
            $current->modify('+1 day');
        }

        $conn->commit();
        $_SESSION['success_message'] = "Leave applied: $leave_type for $total_days day(s) from $from_date to $to_date.";
        header("Location: apply.php");
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// ── Get distinct leave types from leave_balance ───────────────
// leave_types table does not exist; use leave_balance.leave_type directly
$leaveTypeRows = $conn->query("
    SELECT DISTINCT leave_type FROM leave_balance WHERE leave_type IS NOT NULL ORDER BY leave_type
")->fetchAll(PDO::FETCH_COLUMN);

// Current employee's leave balance
$leaveBalances = [];
if ($current_employee_id) {
    $lb = $conn->prepare("
        SELECT leave_type, total_allocated, used_leaves, balance_leaves
        FROM leave_balance
        WHERE employee_id = :e
          AND fiscal_year = (SELECT fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1)
        ORDER BY leave_type
    ");
    $lb->execute([':e' => $current_employee_id]);
    $leaveBalances = $lb->fetchAll(PDO::FETCH_ASSOC);
}

// All active employees (for admin/hr)
$allEmployees = [];
if (has_role('admin') || has_role('hr')) {
    $allEmployees = $conn->query("
        SELECT id, code, name FROM employee
        WHERE emp_status='ACTIVE' AND deleted_date IS NULL ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Apply Leave — JEMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>body{background:#f0f2f8}</style>
</head>
<body>
<div class="container-fluid mt-4" style="max-width:1000px">

    <div class="mb-3">
        <h4><i class="fas fa-envelope me-2"></i>Apply for Leave</h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:.8rem">
                <li class="breadcrumb-item"><a href="/deno2/index.php">Dashboard</a></li>
                <li class="breadcrumb-item active">Leave Application</li>
            </ol>
        </nav>
    </div>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($_SESSION['error_message']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <div class="row g-4">

        <!-- Leave Balance Panel -->
        <?php if (!empty($leaveBalances)): ?>
        <div class="col-md-4">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-info text-white">
                    <h6 class="mb-0"><i class="fas fa-chart-pie me-1"></i>Your Leave Balance</h6>
                </div>
                <div class="card-body">
                    <?php foreach ($leaveBalances as $b): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <strong style="font-size:.85rem"><?= htmlspecialchars($b['leave_type']) ?></strong>
                            <span class="badge bg-primary"><?= $b['balance_leaves'] ?> days</span>
                        </div>
                        <div class="progress mt-1" style="height:5px">
                            <div class="progress-bar bg-success"
                                 style="width:<?= $b['total_allocated']>0 ? round($b['balance_leaves']/$b['total_allocated']*100) : 0 ?>%">
                            </div>
                        </div>
                        <small class="text-muted">Allocated: <?= $b['total_allocated'] ?> | Used: <?= $b['used_leaves'] ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Application Form -->
        <div class="col-md-<?= !empty($leaveBalances) ? '8' : '12' ?>">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-plus-circle me-1"></i>New Leave Application</h5>
                </div>
                <div class="card-body">
                    <form method="POST" id="leaveForm">

                        <?php if (has_role('admin') || has_role('hr')): ?>
                        <div class="mb-3">
                            <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                            <select name="employee_id" class="form-select" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($allEmployees as $emp): ?>
                                <option value="<?= $emp['id'] ?>"><?= htmlspecialchars($emp['code'].' — '.$emp['name']) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        <?php endif; ?>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Leave Type <span class="text-danger">*</span></label>
                            <select name="leave_type" class="form-select" required>
                                <option value="">Select Leave Type</option>
                                <?php if (!empty($leaveTypeRows)): ?>
                                    <?php foreach ($leaveTypeRows as $lt): ?>
                                    <option value="<?= htmlspecialchars($lt) ?>"><?= htmlspecialchars($lt) ?></option>
                                    <?php endforeach; ?>
                                <?php else: ?>
                                    <!-- Default leave types if none configured -->
                                    <option value="Annual Leave">Annual Leave</option>
                                    <option value="Sick Leave">Sick Leave</option>
                                    <option value="Casual Leave">Casual Leave</option>
                                    <option value="Maternity Leave">Maternity Leave</option>
                                    <option value="Paternity Leave">Paternity Leave</option>
                                <?php endif; ?>
                            </select>
                            <?php if (empty($leaveTypeRows)): ?>
                            <small class="text-muted">No leave balances configured yet. Contact HR to set up leave balances.</small>
                            <?php endif; ?>
                        </div>

                        <div class="row g-3 mb-3">
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">From Date <span class="text-danger">*</span></label>
                                <input type="date" name="from_date" class="form-control" required id="fromDate">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label fw-semibold">To Date <span class="text-danger">*</span></label>
                                <input type="date" name="to_date" class="form-control" required id="toDate">
                            </div>
                        </div>

                        <div class="mb-3">
                            <div class="form-check">
                                <input type="checkbox" name="is_half_day" class="form-check-input" id="halfDay">
                                <label class="form-check-label" for="halfDay">Half-day leave</label>
                            </div>
                        </div>

                        <div class="mb-3 p-2 bg-light rounded" id="daysInfo" style="font-size:.85rem;display:none">
                            <i class="fas fa-info-circle text-info me-1"></i>
                            Total: <strong id="totalDays">0</strong> day(s)
                        </div>

                        <div class="mb-3">
                            <label class="form-label fw-semibold">Reason <span class="text-danger">*</span></label>
                            <textarea name="reason" class="form-control" rows="3" required
                                      placeholder="Reason for leave..."></textarea>
                        </div>

                        <div class="d-flex justify-content-between">
                            <a href="/deno2/index.php" class="btn btn-secondary btn-sm">
                                <i class="fas fa-arrow-left me-1"></i>Cancel
                            </a>
                            <button type="submit" class="btn btn-primary btn-sm">
                                <i class="fas fa-paper-plane me-1"></i>Submit Application
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
const fromD = document.getElementById('fromDate');
const toD   = document.getElementById('toDate');
const halfD = document.getElementById('halfDay');
const info  = document.getElementById('daysInfo');
const total = document.getElementById('totalDays');

function updateDays() {
    if (halfD.checked) { total.textContent = '0.5'; info.style.display='block'; return; }
    if (fromD.value && toD.value) {
        const diff = (new Date(toD.value) - new Date(fromD.value)) / 86400000 + 1;
        if (diff > 0) { total.textContent = diff; info.style.display='block'; }
    }
}
fromD.addEventListener('change', function() {
    if (!toD.value) toD.value = this.value;
    toD.min = this.value;
    updateDays();
});
toD.addEventListener('change', updateDays);
halfD.addEventListener('change', function() {
    toD.disabled = this.checked;
    if (this.checked) toD.value = fromD.value;
    updateDays();
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>

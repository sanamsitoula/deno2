<?php
ob_start();
require_once '../../config/database.php';
require_once '../../includes/header.php';

if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;
$attendance_date = $_GET['date'] ?? date('Y-m-d');
$department_filter = $_GET['department_id'] ?? '';
$shift_filter = $_GET['shift_id'] ?? '';
$search = trim($_GET['search'] ?? '');

// Handle bulk attendance submission
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $conn->beginTransaction();
    try {
        $date = $_POST['attendance_date'];
        $employees = $_POST['employees'] ?? [];
        
        foreach ($employees as $emp_id => $data) {
            if (!isset($data['status'])) continue;
            
            $status = $data['status'];
            $in_time = !empty($data['in_time']) ? $data['in_time'] : null;
            $out_time = !empty($data['out_time']) ? $data['out_time'] : null;
            
            // Calculate worked minutes
            $worked_minutes = 0;
            if ($in_time && $out_time) {
                $in = new DateTime($date . ' ' . $in_time);
                $out = new DateTime($date . ' ' . $out_time);
                $worked_minutes = ($out->getTimestamp() - $in->getTimestamp()) / 60;
            }
            
            // Check if attendance already exists
            $check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = :emp_id AND attendance_date = :date");
            $check->execute([':emp_id' => $emp_id, ':date' => $date]);
            
            if ($check->fetch()) {
                // Update existing
                $stmt = $conn->prepare("
                    UPDATE attendance SET
                        attendance_status = :status,
                        in_time = :in_time,
                        out_time = :out_time,
                        worked_minutes = :worked_minutes,
                        is_manual_entry = true,
                        updated_by = :updated_by,
                        updated_date = NOW()
                    WHERE employee_id = :emp_id AND attendance_date = :date
                ");
            } else {
                // Insert new
                $stmt = $conn->prepare("
                    INSERT INTO attendance (
                        employee_id, attendance_date, attendance_status,
                        in_time, out_time, worked_minutes,
                        is_manual_entry, created_by
                    ) VALUES (
                        :emp_id, :date, :status,
                        :in_time, :out_time, :worked_minutes,
                        true, :updated_by
                    )
                ");
            }
            
            $stmt->execute([
                ':emp_id' => $emp_id,
                ':date' => $date,
                ':status' => $status,
                ':in_time' => $in_time,
                ':out_time' => $out_time,
                ':worked_minutes' => $worked_minutes,
                ':updated_by' => $current_user_id
            ]);
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "Attendance marked successfully!";
        header("Location: mark.php?date=$date");
        exit();
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// Fetch employees with attendance status
$sql = "
    SELECT 
        e.id, e.code, e.name, e.department_id, e.shift_id,
        d.name as designation_name,
        dept.name as department_name,
        s.shift_name, s.start_time, s.end_time,
        a.id as attendance_id,
        a.attendance_status,
        a.in_time,
        a.out_time,
        a.worked_minutes
    FROM employee e
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN department dept ON e.department_id = dept.id
    LEFT JOIN shift_master s ON e.shift_id = s.id
    LEFT JOIN attendance a ON e.id = a.employee_id AND a.attendance_date = :date
    WHERE e.emp_status = 'ACTIVE' AND e.deleted_date IS NULL
";

$params = [':date' => $attendance_date];

if ($department_filter) {
    $sql .= " AND e.department_id = :dept_id";
    $params[':dept_id'] = $department_filter;
}

if ($shift_filter) {
    $sql .= " AND e.shift_id = :shift_id";
    $params[':shift_id'] = $shift_filter;
}

if ($search) {
    $sql .= " AND (e.code ILIKE :search OR e.name ILIKE :search)";
    $params[':search'] = "%$search%";
}

$sql .= " ORDER BY dept.name, e.name";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get departments and shifts for filters
$departments = $conn->query("SELECT id, name FROM department WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$shifts = $conn->query("SELECT id, shift_name FROM shift_master WHERE status = true ORDER BY shift_name")->fetchAll(PDO::FETCH_ASSOC);

// Attendance summary
$summary = [
    'total' => count($employees),
    'present' => 0,
    'absent' => 0,
    'leave' => 0,
    'not_marked' => 0
];

foreach ($employees as $emp) {
    if (!$emp['attendance_status']) {
        $summary['not_marked']++;
    } elseif ($emp['attendance_status'] === 'PRESENT') {
        $summary['present']++;
    } elseif ($emp['attendance_status'] === 'ABSENT') {
        $summary['absent']++;
    } elseif ($emp['attendance_status'] === 'LEAVE') {
        $summary['leave']++;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .attendance-row {
            transition: background-color 0.3s;
        }
        .attendance-row:hover {
            background-color: #f8f9fa;
        }
        .status-badge {
            min-width: 80px;
        }
        .quick-mark-btn {
            padding: 5px 10px;
            font-size: 12px;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="fas fa-calendar-check"></i> Mark Attendance</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="index.php">Attendance</a></li>
                        <li class="breadcrumb-item active">Mark Attendance</li>
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

        <!-- Summary Cards -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card bg-primary text-white">
                    <div class="card-body">
                        <h6 class="mb-1">Total Employees</h6>
                        <h3 class="mb-0"><?= $summary['total'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-success text-white">
                    <div class="card-body">
                        <h6 class="mb-1">Present</h6>
                        <h3 class="mb-0"><?= $summary['present'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-danger text-white">
                    <div class="card-body">
                        <h6 class="mb-1">Absent</h6>
                        <h3 class="mb-0"><?= $summary['absent'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card bg-warning text-white">
                    <div class="card-body">
                        <h6 class="mb-1">On Leave</h6>
                        <h3 class="mb-0"><?= $summary['leave'] ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card bg-secondary text-white">
                    <div class="card-body">
                        <h6 class="mb-1">Not Marked</h6>
                        <h3 class="mb-0"><?= $summary['not_marked'] ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mb-4">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label">Date</label>
                        <input type="date" name="date" class="form-control" value="<?= $attendance_date ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Department</label>
                        <select name="department_id" class="form-select">
                            <option value="">All Departments</option>
                            <?php foreach ($departments as $dept): ?>
                                <option value="<?= $dept['id'] ?>" <?= $department_filter == $dept['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($dept['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label">Shift</label>
                        <select name="shift_id" class="form-select">
                            <option value="">All Shifts</option>
                            <?php foreach ($shifts as $shift): ?>
                                <option value="<?= $shift['id'] ?>" <?= $shift_filter == $shift['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($shift['shift_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-3">
                        <label class="form-label">Search</label>
                        <input type="text" name="search" class="form-control" placeholder="Code or Name" value="<?= htmlspecialchars($search) ?>">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label">&nbsp;</label>
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Attendance Form -->
        <form method="POST" id="attendanceForm">
            <input type="hidden" name="attendance_date" value="<?= $attendance_date ?>">
            
            <div class="card">
                <div class="card-header bg-light">
                    <div class="d-flex justify-content-between align-items-center">
                        <h5 class="mb-0">Attendance for <?= date('F d, Y', strtotime($attendance_date)) ?></h5>
                        <div>
                            <button type="button" class="btn btn-sm btn-success" onclick="markAllPresent()">
                                <i class="fas fa-check"></i> Mark All Present
                            </button>
                            <button type="button" class="btn btn-sm btn-danger" onclick="markAllAbsent()">
                                <i class="fas fa-times"></i> Mark All Absent
                            </button>
                        </div>
                    </div>
                </div>
                <div class="card-body p-0">
                    <div class="table-responsive">
                        <table class="table table-sm table-hover mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th width="80">Code</th>
                                    <th>Name</th>
                                    <th>Department</th>
                                    <th>Shift</th>
                                    <th width="120">Status</th>
                                    <th width="100">In Time</th>
                                    <th width="100">Out Time</th>
                                    <th width="80">Hours</th>
                                    <th width="120">Quick Mark</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php if (empty($employees)): ?>
                                    <tr>
                                        <td colspan="9" class="text-center py-4">
                                            <i class="fas fa-info-circle"></i> No employees found
                                        </td>
                                    </tr>
                                <?php else: ?>
                                    <?php foreach ($employees as $emp): ?>
                                        <tr class="attendance-row">
                                            <td><strong><?= htmlspecialchars($emp['code']) ?></strong></td>
                                            <td><?= htmlspecialchars($emp['name']) ?></td>
                                            <td><?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?></td>
                                            <td>
                                                <?php if ($emp['shift_name']): ?>
                                                    <small>
                                                        <?= htmlspecialchars($emp['shift_name']) ?><br>
                                                        <span class="text-muted">
                                                            <?= date('H:i', strtotime($emp['start_time'])) ?> - 
                                                            <?= date('H:i', strtotime($emp['end_time'])) ?>
                                                        </span>
                                                    </small>
                                                <?php else: ?>
                                                    <span class="text-muted">No Shift</span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <select name="employees[<?= $emp['id'] ?>][status]" 
                                                        class="form-select form-select-sm status-select"
                                                        data-emp-id="<?= $emp['id'] ?>">
                                                    <option value="">Not Marked</option>
                                                    <option value="PRESENT" <?= $emp['attendance_status'] === 'PRESENT' ? 'selected' : '' ?>>Present</option>
                                                    <option value="ABSENT" <?= $emp['attendance_status'] === 'ABSENT' ? 'selected' : '' ?>>Absent</option>
                                                    <option value="HALF_DAY" <?= $emp['attendance_status'] === 'HALF_DAY' ? 'selected' : '' ?>>Half Day</option>
                                                    <option value="LEAVE" <?= $emp['attendance_status'] === 'LEAVE' ? 'selected' : '' ?>>Leave</option>
                                                    <option value="HOLIDAY" <?= $emp['attendance_status'] === 'HOLIDAY' ? 'selected' : '' ?>>Holiday</option>
                                                </select>
                                            </td>
                                            <td>
                                                <input type="time" 
                                                       name="employees[<?= $emp['id'] ?>][in_time]" 
                                                       class="form-control form-control-sm in-time"
                                                       value="<?= $emp['in_time'] ? date('H:i', strtotime($emp['in_time'])) : '' ?>"
                                                       data-emp-id="<?= $emp['id'] ?>">
                                            </td>
                                            <td>
                                                <input type="time" 
                                                       name="employees[<?= $emp['id'] ?>][out_time]" 
                                                       class="form-control form-control-sm out-time"
                                                       value="<?= $emp['out_time'] ? date('H:i', strtotime($emp['out_time'])) : '' ?>"
                                                       data-emp-id="<?= $emp['id'] ?>">
                                            </td>
                                            <td class="hours-display" data-emp-id="<?= $emp['id'] ?>">
                                                <?php if ($emp['worked_minutes']): ?>
                                                    <span class="badge bg-info">
                                                        <?= number_format($emp['worked_minutes'] / 60, 1) ?> hrs
                                                    </span>
                                                <?php endif; ?>
                                            </td>
                                            <td>
                                                <button type="button" class="btn btn-success btn-sm quick-mark-btn" 
                                                        onclick="quickMark(<?= $emp['id'] ?>, 'PRESENT', '<?= $emp['start_time'] ?>', '<?= $emp['end_time'] ?>')">
                                                    Present
                                                </button>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
                <div class="card-footer">
                    <div class="d-flex justify-content-between">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back
                        </a>
                        <button type="submit" name="submit_attendance" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save Attendance
                        </button>
                    </div>
                </div>
            </div>
        </form>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        function markAllPresent() {
            document.querySelectorAll('.status-select').forEach(select => {
                select.value = 'PRESENT';
            });
        }

        function markAllAbsent() {
            document.querySelectorAll('.status-select').forEach(select => {
                select.value = 'ABSENT';
            });
        }

        function quickMark(empId, status, startTime, endTime) {
            const statusSelect = document.querySelector(`.status-select[data-emp-id="${empId}"]`);
            const inTimeInput = document.querySelector(`.in-time[data-emp-id="${empId}"]`);
            const outTimeInput = document.querySelector(`.out-time[data-emp-id="${empId}"]`);
            
            statusSelect.value = status;
            if (status === 'PRESENT') {
                inTimeInput.value = startTime.substring(0, 5);
                outTimeInput.value = endTime.substring(0, 5);
                calculateHours(empId);
            }
        }

        function calculateHours(empId) {
            const inTime = document.querySelector(`.in-time[data-emp-id="${empId}"]`).value;
            const outTime = document.querySelector(`.out-time[data-emp-id="${empId}"]`).value;
            const hoursDisplay = document.querySelector(`.hours-display[data-emp-id="${empId}"]`);
            
            if (inTime && outTime) {
                const inDate = new Date(`2000-01-01 ${inTime}`);
                const outDate = new Date(`2000-01-01 ${outTime}`);
                const hours = (outDate - inDate) / (1000 * 60 * 60);
                
                if (hours > 0) {
                    hoursDisplay.innerHTML = `<span class="badge bg-info">${hours.toFixed(1)} hrs</span>`;
                } else {
                    hoursDisplay.innerHTML = '';
                }
            }
        }

        // Attach event listeners
        document.querySelectorAll('.in-time, .out-time').forEach(input => {
            input.addEventListener('change', function() {
                const empId = this.dataset.empId;
                calculateHours(empId);
            });
        });

        // Auto-fill time when status changes to PRESENT
        document.querySelectorAll('.status-select').forEach(select => {
            select.addEventListener('change', function() {
                const empId = this.dataset.empId;
                const row = this.closest('tr');
                const shiftTime = row.querySelector('td:nth-child(4) small');
                
                if (this.value === 'PRESENT' && shiftTime) {
                    const times = shiftTime.textContent.match(/\d{2}:\d{2}/g);
                    if (times && times.length === 2) {
                        document.querySelector(`.in-time[data-emp-id="${empId}"]`).value = times[0];
                        document.querySelector(`.out-time[data-emp-id="${empId}"]`).value = times[1];
                        calculateHours(empId);
                    }
                }
            });
        });
    </script>
</body>
</html>

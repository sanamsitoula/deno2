<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? 0;
$attendance_date  = $_GET['date'] ?? date('Y-m-d');
$dept_filter      = $_GET['department_id'] ?? '';
$search           = trim($_GET['search'] ?? '');

// ── Handle bulk attendance submission ─────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['submit_attendance'])) {
    $conn->beginTransaction();
    try {
        $date      = $_POST['attendance_date'];
        $employees = $_POST['employees'] ?? [];

        // Get status_id map from attendance_status table
        $statusRows = $conn->query("SELECT id, status_code FROM attendance_status")->fetchAll(PDO::FETCH_ASSOC);
        $statusMap  = array_column($statusRows, 'id', 'status_code');
        // status_code → P=Present, A=Absent, HD=Half Day, L=Leave, H=Holiday
        $codeToDb = ['PRESENT'=>'P','ABSENT'=>'A','HALF_DAY'=>'HD','LEAVE'=>'L','HOLIDAY'=>'H'];

        foreach ($employees as $emp_id => $data) {
            if (empty($data['status'])) continue;

            $statusCode = $data['status'];
            $dbCode     = $codeToDb[$statusCode] ?? 'P';
            $statusId   = $statusMap[$dbCode] ?? null;
            $checkIn    = !empty($data['in_time'])  ? $data['in_time']  : null;
            $checkOut   = !empty($data['out_time']) ? $data['out_time'] : null;

            // Check existing
            $chk = $conn->prepare("SELECT id FROM attendance WHERE employee_id=:e AND attendance_date_eng=:d");
            $chk->execute([':e' => $emp_id, ':d' => $date]);

            if ($chk->fetch()) {
                $stmt = $conn->prepare("
                    UPDATE attendance SET
                        status_id       = :status_id,
                        check_in_time   = :in_time,
                        check_out_time  = :out_time,
                        marked_by       = :marked_by,
                        updated_at      = NOW(),
                        data_source     = 'MANUAL'
                    WHERE employee_id = :e AND attendance_date_eng = :d
                ");
            } else {
                $stmt = $conn->prepare("
                    INSERT INTO attendance (
                        employee_id, attendance_date_eng,
                        status_id, check_in_time, check_out_time,
                        marked_by, created_at, data_source
                    ) VALUES (
                        :e, :d,
                        :status_id, :in_time, :out_time,
                        :marked_by, NOW(), 'MANUAL'
                    )
                ");
            }
            $stmt->execute([
                ':e'         => $emp_id,
                ':d'         => $date,
                ':status_id' => $statusId,
                ':in_time'   => $checkIn,
                ':out_time'  => $checkOut,
                ':marked_by' => $current_user_id,
            ]);
        }

        $conn->commit();
        $_SESSION['success_message'] = "Attendance saved for " . date('d M Y', strtotime($date));
        header("Location: mark.php?date=$date" . ($dept_filter ? "&department_id=$dept_filter" : ''));
        exit();
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// ── Fetch employees with today's attendance ───────────────────
// shifts table has: id, name, remarks, status (no shift_id on employee)
$sql = "
    SELECT
        e.id, e.code, e.name,
        d.name  AS designation_name,
        dep.name AS department_name,
        a.id    AS attendance_id,
        ast.status_code AS att_status,
        a.check_in_time,
        a.check_out_time,
        a.ot_hours
    FROM employee e
    LEFT JOIN designation  d   ON e.designation_id  = d.id
    LEFT JOIN department   dep ON e.department_id   = dep.id
    LEFT JOIN attendance   a   ON e.id = a.employee_id AND a.attendance_date_eng = :date
    LEFT JOIN attendance_status ast ON a.status_id = ast.id
    WHERE e.emp_status = 'ACTIVE' AND e.deleted_date IS NULL
";
$params = [':date' => $attendance_date];

if ($dept_filter) {
    $sql .= " AND e.department_id = :dept_id";
    $params[':dept_id'] = $dept_filter;
}
if ($search) {
    $sql .= " AND (e.code ILIKE :search OR e.name ILIKE :search)";
    $params[':search'] = "%$search%";
}
$sql .= " ORDER BY dep.name, e.name";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

$departments = $conn->query("SELECT id, name FROM department WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Summary
$summary = ['total' => count($employees), 'present' => 0, 'absent' => 0, 'leave' => 0, 'not_marked' => 0];
foreach ($employees as $emp) {
    $s = $emp['att_status'] ?? '';
    if (!$s)          $summary['not_marked']++;
    elseif ($s==='P') $summary['present']++;
    elseif ($s==='A') $summary['absent']++;
    elseif ($s==='L') $summary['leave']++;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Mark Attendance — JEMC</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        body { background:#f0f2f8; }
        .att-row:hover { background:#f8f9fa; }
    </style>
</head>
<body>
<div class="container-fluid mt-3" style="max-width:1400px">

    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0"><i class="fas fa-calendar-check me-2"></i>Mark Attendance</h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size:.8rem">
                    <li class="breadcrumb-item"><a href="/deno2/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item active">Attendance</li>
                </ol>
            </nav>
        </div>
        <a href="/deno2/attendance_device/zkteco_index.php" class="btn btn-sm btn-outline-primary">
            <i class="bi bi-fingerprint"></i> ZKTeco Dashboard
        </a>
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

    <!-- Summary -->
    <div class="row g-3 mb-3">
        <?php foreach ([
            ['Total',      $summary['total'],       'primary'],
            ['Present',    $summary['present'],      'success'],
            ['Absent',     $summary['absent'],       'danger'],
            ['On Leave',   $summary['leave'],        'warning'],
            ['Not Marked', $summary['not_marked'],   'secondary'],
        ] as [$label, $val, $color]): ?>
        <div class="col">
            <div class="card border-0 shadow-sm text-center py-2">
                <div class="fs-3 fw-bold text-<?= $color ?>"><?= $val ?></div>
                <div class="text-muted small"><?= $label ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="card shadow-sm border-0 mb-3">
        <div class="card-body py-2">
            <form method="GET" class="row g-2 align-items-end">
                <div class="col-md-2">
                    <label class="form-label small mb-1">Date</label>
                    <input type="date" name="date" class="form-control form-control-sm"
                           value="<?= $attendance_date ?>" max="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Department</label>
                    <select name="department_id" class="form-select form-select-sm">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $dept_filter==$dept['id']?'selected':'' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label small mb-1">Search</label>
                    <input type="text" name="search" class="form-control form-control-sm"
                           placeholder="Code or Name" value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-search me-1"></i>Filter
                    </button>
                </div>
                <div class="col-md-2">
                    <a href="mark.php?date=<?= $attendance_date ?>" class="btn btn-outline-secondary btn-sm w-100">Reset</a>
                </div>
            </form>
        </div>
    </div>

    <!-- Attendance Form -->
    <form method="POST" id="attForm">
        <input type="hidden" name="attendance_date" value="<?= $attendance_date ?>">
        <div class="card shadow-sm border-0">
            <div class="card-header bg-white d-flex justify-content-between align-items-center">
                <h6 class="mb-0"><i class="fas fa-list me-1"></i>
                    Attendance — <?= date('l, d F Y', strtotime($attendance_date)) ?>
                    <span class="badge bg-secondary ms-2"><?= count($employees) ?> employees</span>
                </h6>
                <div class="d-flex gap-2">
                    <button type="button" class="btn btn-sm btn-success" onclick="markAll('PRESENT')">
                        <i class="fas fa-check"></i> All Present
                    </button>
                    <button type="button" class="btn btn-sm btn-danger" onclick="markAll('ABSENT')">
                        <i class="fas fa-times"></i> All Absent
                    </button>
                </div>
            </div>
            <div class="card-body p-0">
                <div class="table-responsive">
                    <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
                        <thead class="table-light sticky-top">
                            <tr>
                                <th>Code</th><th>Name</th><th>Department</th>
                                <th width="130">Status</th>
                                <th width="90">In Time</th>
                                <th width="90">Out Time</th>
                                <th width="70">OT Hrs</th>
                                <th width="80">Quick</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php if (empty($employees)): ?>
                            <tr><td colspan="8" class="text-center py-4 text-muted">No employees found</td></tr>
                        <?php else:
                            $statusMap2 = ['P'=>'PRESENT','A'=>'ABSENT','HD'=>'HALF_DAY','L'=>'LEAVE','H'=>'HOLIDAY'];
                            foreach ($employees as $emp):
                                $curStatus = $statusMap2[$emp['att_status'] ?? ''] ?? '';
                        ?>
                        <tr class="att-row">
                            <td><strong><?= htmlspecialchars($emp['code']) ?></strong></td>
                            <td><?= htmlspecialchars($emp['name']) ?></td>
                            <td><small><?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?></small></td>
                            <td>
                                <select name="employees[<?= $emp['id'] ?>][status]"
                                        class="form-select form-select-sm status-sel"
                                        data-id="<?= $emp['id'] ?>">
                                    <option value="">Not Marked</option>
                                    <?php foreach (['PRESENT'=>'Present','ABSENT'=>'Absent','HALF_DAY'=>'Half Day','LEAVE'=>'Leave','HOLIDAY'=>'Holiday'] as $val=>$lbl): ?>
                                    <option value="<?= $val ?>" <?= $curStatus===$val?'selected':'' ?>><?= $lbl ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td>
                                <input type="time" name="employees[<?= $emp['id'] ?>][in_time]"
                                       class="form-control form-control-sm in-time" data-id="<?= $emp['id'] ?>"
                                       value="<?= $emp['check_in_time'] ? substr($emp['check_in_time'],0,5) : '' ?>">
                            </td>
                            <td>
                                <input type="time" name="employees[<?= $emp['id'] ?>][out_time]"
                                       class="form-control form-control-sm out-time" data-id="<?= $emp['id'] ?>"
                                       value="<?= $emp['check_out_time'] ? substr($emp['check_out_time'],0,5) : '' ?>">
                            </td>
                            <td class="text-center">
                                <?php if ($emp['ot_hours'] > 0): ?>
                                <span class="badge bg-info"><?= number_format($emp['ot_hours'],1) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <button type="button" class="btn btn-success btn-sm"
                                        style="font-size:.7rem;padding:2px 8px"
                                        onclick="quickPresent(<?= $emp['id'] ?>)">✓ Present</button>
                            </td>
                        </tr>
                        <?php endforeach; endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <div class="card-footer d-flex justify-content-between">
                <a href="/deno2/index.php" class="btn btn-secondary btn-sm">
                    <i class="fas fa-arrow-left me-1"></i>Dashboard
                </a>
                <button type="submit" name="submit_attendance" class="btn btn-primary btn-sm">
                    <i class="fas fa-save me-1"></i>Save Attendance
                </button>
            </div>
        </div>
    </form>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function markAll(status) {
    document.querySelectorAll('.status-sel').forEach(s => s.value = status);
    if (status === 'PRESENT') {
        document.querySelectorAll('.in-time').forEach(i => { if (!i.value) i.value = '10:00'; });
        document.querySelectorAll('.out-time').forEach(i => { if (!i.value) i.value = '17:00'; });
    }
}
function quickPresent(id) {
    document.querySelector(`.status-sel[data-id="${id}"]`).value = 'PRESENT';
    const inT = document.querySelector(`.in-time[data-id="${id}"]`);
    const outT = document.querySelector(`.out-time[data-id="${id}"]`);
    if (!inT.value) inT.value = '10:00';
    if (!outT.value) outT.value = '17:00';
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>

<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get filter parameters
$date_from = $_GET['date_from'] ?? date('Y-m-d');
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$employee_id = $_GET['employee_id'] ?? '';
$department_id = $_GET['department_id'] ?? '';
$data_source = $_GET['data_source'] ?? 'ZKTECO';

// Build WHERE clause
$where = ["a.attendance_date_eng BETWEEN :date_from AND :date_to"];
$params = [
    ':date_from' => $date_from,
    ':date_to' => $date_to
];

if ($data_source) {
    $where[] = "a.data_source = :data_source";
    $params[':data_source'] = $data_source;
}

if ($employee_id) {
    $where[] = "a.employee_id = :employee_id";
    $params[':employee_id'] = $employee_id;
}

if ($department_id) {
    $where[] = "e.department_id = :department_id";
    $params[':department_id'] = $department_id;
}

// Get attendance records
$stmt = $conn->prepare("
    SELECT 
        a.*,
        e.code as employee_code,
        e.name as employee_name,
        e.emp_type,
        d.name as department_name,
        des.name as designation_name,
        ast.status_name,
        ast.status_code,
        zd.device_name
    FROM attendance a
    JOIN employee e ON a.employee_id = e.id
    LEFT JOIN department d ON e.department_id = d.id
    LEFT JOIN designation des ON e.designation_id = des.id
    LEFT JOIN attendance_status ast ON a.status_id = ast.id
    LEFT JOIN zkteco_devices zd ON a.device_id = zd.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY a.attendance_date_eng DESC, e.code
    LIMIT 500
");
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(DISTINCT a.employee_id) as total_employees,
        COUNT(*) as total_records,
        COUNT(CASE WHEN ast.status_code = 'P' THEN 1 END) as present_days,
        COUNT(CASE WHEN ast.status_code = 'A' THEN 1 END) as absent_days,
        COUNT(CASE WHEN a.check_in_time IS NOT NULL THEN 1 END) as checked_in,
        COUNT(CASE WHEN a.check_out_time IS NOT NULL THEN 1 END) as checked_out,
        SUM(a.actual_working_hours) as total_working_hours,
        SUM(a.ot_hours) as total_ot_hours,
        AVG(a.actual_working_hours) as avg_working_hours
    FROM attendance a
    JOIN employee e ON a.employee_id = e.id
    LEFT JOIN attendance_status ast ON a.status_id = ast.id
    WHERE " . implode(' AND ', $where)
);
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

// Get employees for filter
$employees = $conn->query("
    SELECT id, code, name 
    FROM employee 
    WHERE deleted_date IS NULL 
    ORDER BY code
")->fetchAll(PDO::FETCH_ASSOC);

// Get departments for filter
$departments = $conn->query("
    SELECT id, name 
    FROM department 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
}

.container {
    max-width: 1800px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 10px 0;
}

.filter-section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 13px;
    color: #333;
}

.form-control {
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-info { background: #17a2b8; color: white; }

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    text-align: center;
}

.summary-value {
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.summary-label {
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
}

.table-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    background: #f8f9fa;
    padding: 12px 8px;
    text-align: left;
    font-weight: 600;
    font-size: 12px;
    border-bottom: 2px solid #e9ecef;
    white-space: nowrap;
}

.table td {
    padding: 10px 8px;
    border-bottom: 1px solid #e9ecef;
    font-size: 13px;
}

.table tbody tr:hover {
    background: #f8f9fa;
}

.status-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
    display: inline-block;
}

.status-P { background: #d4edda; color: #155724; }
.status-A { background: #f8d7da; color: #721c24; }
.status-HD { background: #fff3cd; color: #856404; }
.status-L { background: #cce5ff; color: #004085; }
.status-WO { background: #e2e3e5; color: #383d41; }

.source-badge {
    padding: 3px 8px;
    border-radius: 10px;
    font-size: 10px;
    font-weight: 600;
}

.source-ZKTECO { background: #667eea; color: white; }
.source-MANUAL { background: #6c757d; color: white; }
.source-PDF { background: #17a2b8; color: white; }
.source-EXCEL { background: #28a745; color: white; }

.time-display {
    font-family: 'Courier New', monospace;
    font-size: 12px;
    font-weight: 600;
}

.action-bar {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📊 Attendance Report (ZKTeco)</h1>
        <p>View and analyze attendance data from ZKTeco devices</p>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET">
            <div class="filter-grid">
                <div class="form-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                </div>
                <div class="form-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                </div>
                <div class="form-group">
                    <label>Employee</label>
                    <select name="employee_id" class="form-control">
                        <option value="">All Employees</option>
                        <?php foreach ($employees as $emp): ?>
                        <option value="<?= $emp['id'] ?>" <?= $employee_id == $emp['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($emp['code']) ?> - <?= htmlspecialchars($emp['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Department</label>
                    <select name="department_id" class="form-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                        <option value="<?= $dept['id'] ?>" <?= $department_id == $dept['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dept['name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Data Source</label>
                    <select name="data_source" class="form-control">
                        <option value="">All Sources</option>
                        <option value="ZKTECO" <?= $data_source == 'ZKTECO' ? 'selected' : '' ?>>ZKTeco Device</option>
                        <option value="MANUAL" <?= $data_source == 'MANUAL' ? 'selected' : '' ?>>Manual Entry</option>
                        <option value="PDF" <?= $data_source == 'PDF' ? 'selected' : '' ?>>PDF Upload</option>
                        <option value="EXCEL" <?= $data_source == 'EXCEL' ? 'selected' : '' ?>>Excel Upload</option>
                    </select>
                </div>
            </div>
            <div class="action-bar">
                <button type="submit" class="btn btn-primary">🔍 Filter</button>
                <a href="?" class="btn btn-secondary">🔄 Reset</a>
                <button type="button" onclick="exportToExcel()" class="btn btn-success">📊 Export Excel</button>
                <button type="button" onclick="window.print()" class="btn btn-info">🖨️ Print</button>
            </div>
        </form>
    </div>

    <!-- Summary Statistics -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-value"><?= $summary['total_employees'] ?></div>
            <div class="summary-label">Employees</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= $summary['total_records'] ?></div>
            <div class="summary-label">Total Records</div>
        </div>
        <div class="summary-card">
            <div class="summary-value" style="color: #28a745"><?= $summary['present_days'] ?></div>
            <div class="summary-label">Present Days</div>
        </div>
        <div class="summary-card">
            <div class="summary-value" style="color: #dc3545"><?= $summary['absent_days'] ?></div>
            <div class="summary-label">Absent Days</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= number_format($summary['total_working_hours'], 1) ?></div>
            <div class="summary-label">Total Hours</div>
        </div>
        <div class="summary-card">
            <div class="summary-value" style="color: #ffc107"><?= number_format($summary['total_ot_hours'], 1) ?></div>
            <div class="summary-label">OT Hours</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= number_format($summary['avg_working_hours'], 1) ?></div>
            <div class="summary-label">Avg Hours/Day</div>
        </div>
    </div>

    <!-- Attendance Records Table -->
    <div class="table-container">
        <h3 style="margin-top: 0;">Attendance Records (<?= count($records) ?> of 500 max)</h3>
        
        <?php if (empty($records)): ?>
        <p>No attendance records found for the selected filters.</p>
        <?php else: ?>
        
        <table class="table" id="attendanceTable">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Employee</th>
                    <th>Department</th>
                    <th>Status</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Work Hours</th>
                    <th>Break</th>
                    <th>OT Hours</th>
                    <th>Source</th>
                    <th>Device</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($records as $rec): ?>
                <tr>
                    <td>
                        <strong><?= date('M d, Y', strtotime($rec['attendance_date_eng'])) ?></strong><br>
                        <small style="color: #6c757d"><?= htmlspecialchars($rec['attendance_date_nep']) ?></small>
                    </td>
                    <td>
                        <strong><?= htmlspecialchars($rec['employee_code']) ?></strong><br>
                        <small><?= htmlspecialchars($rec['employee_name']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($rec['department_name'] ?? '-') ?></td>
                    <td>
                        <span class="status-badge status-<?= $rec['status_code'] ?>">
                            <?= $rec['status_name'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($rec['check_in_time']): ?>
                            <span class="time-display"><?= date('h:i A', strtotime($rec['check_in_time'])) ?></span>
                            <?php if ($rec['late_minutes'] > 0): ?>
                                <br><small style="color: #dc3545;">Late: <?= $rec['late_minutes'] ?>m</small>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color: #6c757d;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($rec['check_out_time']): ?>
                            <span class="time-display"><?= date('h:i A', strtotime($rec['check_out_time'])) ?></span>
                        <?php else: ?>
                            <span style="color: #6c757d;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($rec['actual_working_hours']): ?>
                            <strong><?= number_format($rec['actual_working_hours'], 2) ?></strong>
                        <?php else: ?>
                            <span style="color: #6c757d;">-</span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format($rec['break_hours'] ?? 0, 1) ?></td>
                    <td>
                        <?php if ($rec['ot_hours'] > 0): ?>
                            <strong style="color: #ffc107;"><?= number_format($rec['ot_hours'], 2) ?></strong>
                        <?php else: ?>
                            <span style="color: #6c757d;">-</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <span class="source-badge source-<?= $rec['data_source'] ?>">
                            <?= $rec['data_source'] ?>
                        </span>
                    </td>
                    <td>
                        <?php if ($rec['device_name']): ?>
                            <small><?= htmlspecialchars($rec['device_name']) ?></small>
                        <?php else: ?>
                            <span style="color: #6c757d;">-</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <?php if (count($records) >= 500): ?>
        <p style="margin-top: 15px; color: #856404; background: #fff3cd; padding: 10px; border-radius: 6px;">
            <strong>Note:</strong> Showing maximum 500 records. Please use filters to narrow your search.
        </p>
        <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
function exportToExcel() {
    const table = document.getElementById('attendanceTable');
    const wb = XLSX.utils.table_to_book(table, {sheet: "Attendance"});
    XLSX.writeFile(wb, 'attendance_report_' + new Date().toISOString().split('T')[0] + '.xlsx');
}

// Print styling
window.onbeforeprint = function() {
    document.body.style.fontSize = '10px';
};

window.onafterprint = function() {
    document.body.style.fontSize = '';
};
</script>

<!-- Include SheetJS for Excel export -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/xlsx/0.18.5/xlsx.full.min.js"></script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

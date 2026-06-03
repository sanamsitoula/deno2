<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get today's Nepali date (you should use a proper Nepali date converter)
$today_nep = '2082.10.30';
$today_eng = date('Y-m-d');

// Pagination
$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Get search parameters
$search_params = [
    'date_nep' => $_GET['date_nep'] ?? $today_nep,
    'department_id' => $_GET['department_id'] ?? '',
    'designation_id' => $_GET['designation_id'] ?? '',
    'level_id' => $_GET['level_id'] ?? '',
    'status_id' => $_GET['status_id'] ?? '',
    'employee_code' => $_GET['employee_code'] ?? ''
];

// Build query
$where_conditions = ["e.deleted_date IS NULL"];
$bind_params = [];

if (!empty($search_params['date_nep'])) {
    $where_conditions[] = "a.attendance_date_nep = :date_nep";
    $bind_params[':date_nep'] = $search_params['date_nep'];
}

if (!empty($search_params['department_id'])) {
    $where_conditions[] = "e.department_id = :department_id";
    $bind_params[':department_id'] = $search_params['department_id'];
}

if (!empty($search_params['designation_id'])) {
    $where_conditions[] = "e.designation_id = :designation_id";
    $bind_params[':designation_id'] = $search_params['designation_id'];
}

if (!empty($search_params['level_id'])) {
    $where_conditions[] = "e.level_id = :level_id";
    $bind_params[':level_id'] = $search_params['level_id'];
}

if (!empty($search_params['status_id'])) {
    $where_conditions[] = "a.status_id = :status_id";
    $bind_params[':status_id'] = $search_params['status_id'];
}

if (!empty($search_params['employee_code'])) {
    $where_conditions[] = "e.code LIKE :employee_code";
    $bind_params[':employee_code'] = '%' . $search_params['employee_code'] . '%';
}

$where_clause = implode(' AND ', $where_conditions);

// Count query
$count_query = "
    SELECT COUNT(*) as total
    FROM attendance a
    JOIN employee e ON a.employee_id = e.id
    WHERE {$where_clause}
";

$count_stmt = $conn->prepare($count_query);
foreach ($bind_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Main query
$query = "
    SELECT 
        a.*,
        e.code as employee_code,
        e.name as employee_name,
        e.name_nep as employee_name_nep,
        d.name as designation,
        l.name as level,
        dept.name as department,
        s.name as shift_name,
        ast.status_code,
        ast.status_name,
        ast.color_code
    FROM attendance a
    JOIN employee e ON a.employee_id = e.id
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN level l ON e.level_id = l.id
    LEFT JOIN department dept ON e.department_id = dept.id
    LEFT JOIN shifts s ON a.shift_id = s.id
    JOIN attendance_status ast ON a.status_id = ast.id
    WHERE {$where_clause}
    ORDER BY e.code, a.attendance_date_eng DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($query);
foreach ($bind_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$attendance_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get dropdown data
$departments = $conn->query("SELECT * FROM department ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$designations = $conn->query("SELECT * FROM designation ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $conn->query("SELECT * FROM level ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$statuses = $conn->query("SELECT * FROM attendance_status ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Calculate summary statistics
$summary_query = "
    SELECT 
        COUNT(*) as total_records,
        COUNT(DISTINCT a.employee_id) as total_employees,
        COUNT(CASE WHEN ast.status_code = 'P' THEN 1 END) as total_present,
        COUNT(CASE WHEN ast.status_code = 'A' THEN 1 END) as total_absent,
        COUNT(CASE WHEN ast.status_code = 'HD' THEN 1 END) as total_half_day,
        COUNT(CASE WHEN ast.status_code IN ('L', 'CL', 'SL', 'PL') THEN 1 END) as total_leave,
        COUNT(CASE WHEN ast.status_code = 'WO' THEN 1 END) as total_weekly_off,
        COUNT(CASE WHEN ast.status_code = 'PH' THEN 1 END) as total_public_holiday,
        SUM(a.actual_working_hours) as total_working_hours,
        SUM(a.ot_hours) as total_ot_hours,
        SUM(a.late_arrival_minutes) as total_late_minutes
    FROM attendance a
    JOIN employee e ON a.employee_id = e.id
    JOIN attendance_status ast ON a.status_id = ast.id
    WHERE {$where_clause}
";

$summary_stmt = $conn->prepare($summary_query);
foreach ($bind_params as $key => $value) {
    $summary_stmt->bindValue($key, $value);
}
$summary_stmt->execute();
$summary = $summary_stmt->fetch(PDO::FETCH_ASSOC);

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="daily_attendance_' . $search_params['date_nep'] . '.xls"');
    header('Cache-Control: max-age=0');
    
    $export_query = str_replace(" LIMIT :limit OFFSET :offset", "", $query);
    $export_stmt = $conn->prepare($export_query);
    foreach ($bind_params as $key => $value) {
        $export_stmt->bindValue($key, $value);
    }
    $export_stmt->execute();
    $export_records = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr style='background: #667eea; color: white;'>";
    echo "<th>Employee Code</th><th>Employee Name</th><th>Designation</th><th>Level</th><th>Department</th>";
    echo "<th>Date (Nep)</th><th>Date (Eng)</th><th>Shift</th><th>Status</th>";
    echo "<th>Check-In</th><th>Check-Out</th><th>Working Hours</th><th>OT Hours</th>";
    echo "<th>Late Minutes</th><th>Remarks</th></tr>";
    
    foreach ($export_records as $record) {
        echo "<tr>";
        echo "<td>" . $record['employee_code'] . "</td>";
        echo "<td>" . htmlspecialchars($record['employee_name']) . "</td>";
        echo "<td>" . htmlspecialchars($record['designation']) . "</td>";
        echo "<td>" . htmlspecialchars($record['level']) . "</td>";
        echo "<td>" . htmlspecialchars($record['department']) . "</td>";
        echo "<td>" . $record['attendance_date_nep'] . "</td>";
        echo "<td>" . $record['attendance_date_eng'] . "</td>";
        echo "<td>" . htmlspecialchars($record['shift_name']) . "</td>";
        echo "<td>" . $record['status_code'] . "</td>";
        echo "<td>" . ($record['check_in_time'] ?: '-') . "</td>";
        echo "<td>" . ($record['check_out_time'] ?: '-') . "</td>";
        echo "<td>" . number_format($record['actual_working_hours'], 2) . "</td>";
        echo "<td>" . number_format($record['ot_hours'], 2) . "</td>";
        echo "<td>" . $record['late_arrival_minutes'] . "</td>";
        echo "<td>" . htmlspecialchars($record['remarks']) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit();
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
    margin: 0;
    padding: 20px;
}

.container {
    max-width: 1800px;
    margin: 0 auto;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 10px 0;
}

.page-subtitle {
    font-size: 16px;
    opacity: 0.9;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #667eea;
}

.summary-card.present { border-left-color: #28a745; }
.summary-card.absent { border-left-color: #dc3545; }
.summary-card.leave { border-left-color: #17a2b8; }
.summary-card.ot { border-left-color: #ffc107; }

.summary-value {
    font-size: 28px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.summary-label {
    font-size: 13px;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.search-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.search-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.search-group {
    display: flex;
    flex-direction: column;
}

.search-group label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
    font-size: 13px;
}

.search-control {
    padding: 10px 12px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
}

.search-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-info { background: #17a2b8; color: white; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.action-buttons {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.table-container {
    background: white;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1400px;
}

.data-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.data-table th {
    padding: 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    color: white;
    text-transform: uppercase;
    position: sticky;
    top: 0;
    z-index: 10;
}

.data-table td {
    padding: 12px;
    font-size: 13px;
    border-bottom: 1px solid #f0f0f0;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.data-table tbody tr:nth-child(even) {
    background: #fafafa;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    color: white;
}

.pagination {
    display: flex;
    justify-content: center;
    align-items: center;
    gap: 10px;
    margin-top: 30px;
}

.page-link {
    padding: 8px 12px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    text-decoration: none;
    color: #667eea;
    font-weight: 600;
    transition: all 0.2s ease;
}

.page-link:hover {
    background: #667eea;
    color: white;
}

.page-link.active {
    background: #667eea;
    color: white;
    border-color: #667eea;
}

@media print {
    .search-container, .action-buttons, .pagination { display: none !important; }
    .page-header { background: none; color: #333; }
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📊 Daily Attendance Report</h1>
        <p class="page-subtitle">Detailed attendance tracking and analysis</p>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-value"><?= number_format($summary['total_employees']) ?></div>
            <div class="summary-label">Total Employees</div>
        </div>
        
        <div class="summary-card present">
            <div class="summary-value"><?= number_format($summary['total_present']) ?></div>
            <div class="summary-label">Present</div>
        </div>
        
        <div class="summary-card absent">
            <div class="summary-value"><?= number_format($summary['total_absent']) ?></div>
            <div class="summary-label">Absent</div>
        </div>
        
        <div class="summary-card">
            <div class="summary-value"><?= number_format($summary['total_half_day']) ?></div>
            <div class="summary-label">Half Day</div>
        </div>
        
        <div class="summary-card leave">
            <div class="summary-value"><?= number_format($summary['total_leave']) ?></div>
            <div class="summary-label">On Leave</div>
        </div>
        
        <div class="summary-card ot">
            <div class="summary-value"><?= number_format($summary['total_ot_hours'], 1) ?></div>
            <div class="summary-label">Total OT Hours</div>
        </div>
    </div>

    <!-- Search/Filter Section -->
    <div class="search-container">
        <form method="get" id="searchForm">
            <input type="hidden" name="page" value="1">
            
            <div class="search-grid">
                <div class="search-group">
                    <label for="date_nep">📅 Date (Nepali)</label>
                    <input type="text" 
                           name="date_nep" 
                           id="date_nep" 
                           class="search-control"
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="YYYY.MM.DD"
                           value="<?= htmlspecialchars($search_params['date_nep']) ?>">
                </div>
                
                <div class="search-group">
                    <label for="department_id">🏢 Department</label>
                    <select name="department_id" id="department_id" class="search-control">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" 
                                    <?= $search_params['department_id'] == $dept['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="designation_id">👔 Designation</label>
                    <select name="designation_id" id="designation_id" class="search-control">
                        <option value="">All Designations</option>
                        <?php foreach ($designations as $desig): ?>
                            <option value="<?= $desig['id'] ?>" 
                                    <?= $search_params['designation_id'] == $desig['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($desig['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="level_id">📊 Level</label>
                    <select name="level_id" id="level_id" class="search-control">
                        <option value="">All Levels</option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= $level['id'] ?>" 
                                    <?= $search_params['level_id'] == $level['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="status_id">📝 Status</label>
                    <select name="status_id" id="status_id" class="search-control">
                        <option value="">All Statuses</option>
                        <?php foreach ($statuses as $status): ?>
                            <option value="<?= $status['id'] ?>" 
                                    <?= $search_params['status_id'] == $status['id'] ? 'selected' : '' ?>>
                                <?= $status['status_code'] ?> - <?= htmlspecialchars($status['status_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="employee_code">🔍 Employee Code</label>
                    <input type="text" 
                           name="employee_code" 
                           id="employee_code" 
                           class="search-control"
                           placeholder="Search by code..."
                           value="<?= htmlspecialchars($search_params['employee_code']) ?>">
                </div>
            </div>
            
            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">🔍 Search</button>
                <a href="?" class="btn btn-secondary">🔄 Reset</a>
            </div>
        </form>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <div>
            <a href="attendance_entry.php" class="btn btn-primary">➕ Mark Attendance</a>
        </div>
        <div style="display: flex; gap: 10px;">
            <button onclick="window.print()" class="btn btn-info">🖨️ Print</button>
            <a href="?export=excel&<?= http_build_query($_GET) ?>" class="btn btn-success">📊 Export Excel</a>
        </div>
    </div>

    <!-- Data Table -->
    <div class="table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Emp Code</th>
                    <th>Employee Name</th>
                    <th>Designation</th>
                    <th>Level</th>
                    <th>Department</th>
                    <th>Date (Nep)</th>
                    <th>Date (Eng)</th>
                    <th>Shift</th>
                    <th>Status</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Working Hrs</th>
                    <th>OT Hrs</th>
                    <th>Late (min)</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($attendance_records)): ?>
                    <tr>
                        <td colspan="15" style="text-align: center; padding: 40px;">
                            No attendance records found for the selected criteria
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($attendance_records as $record): ?>
                        <tr>
                            <td><strong><?= $record['employee_code'] ?></strong></td>
                            <td>
                                <?= htmlspecialchars($record['employee_name']) ?><br>
                                <small style="color: #6c757d;"><?= htmlspecialchars($record['employee_name_nep']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($record['designation']) ?></td>
                            <td><?= htmlspecialchars($record['level']) ?></td>
                            <td><?= htmlspecialchars($record['department']) ?></td>
                            <td><?= $record['attendance_date_nep'] ?></td>
                            <td><?= date('Y-m-d', strtotime($record['attendance_date_eng'])) ?></td>
                            <td><?= htmlspecialchars($record['shift_name'] ?: '-') ?></td>
                            <td>
                                <span class="status-badge" style="background: <?= $record['color_code'] ?>">
                                    <?= $record['status_code'] ?>
                                </span>
                            </td>
                            <td><?= $record['check_in_time'] ?: '-' ?></td>
                            <td><?= $record['check_out_time'] ?: '-' ?></td>
                            <td><strong><?= number_format($record['actual_working_hours'], 2) ?></strong></td>
                            <td><strong><?= number_format($record['ot_hours'], 2) ?></strong></td>
                            <td><?= $record['late_arrival_minutes'] ?: '0' ?></td>
                            <td><small><?= htmlspecialchars($record['remarks']) ?></small></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="page-link">First</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-link">‹ Prev</a>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                   class="page-link <?= $i === $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>
            
            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-link">Next ›</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" class="page-link">Last</a>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Quick date shortcuts
    const dateInput = document.getElementById('date_nep');
    
    // You can add buttons for quick date selection
    const quickDates = document.createElement('div');
    quickDates.style.marginTop = '10px';
    quickDates.innerHTML = `
        <small style="color: #6c757d;">Quick: </small>
        <button type="button" class="btn btn-sm" style="padding: 4px 8px; background: #e9ecef; color: #495057; margin-right: 5px;" onclick="document.getElementById('date_nep').value = '<?= $today_nep ?>'; document.getElementById('searchForm').submit();">Today</button>
        <button type="button" class="btn btn-sm" style="padding: 4px 8px; background: #e9ecef; color: #495057;" onclick="alert('Yesterday date calculator needed');">Yesterday</button>
    `;
    dateInput.parentNode.appendChild(quickDates);
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

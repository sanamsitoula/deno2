<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get current Nepali month
$current_year_month = '2082.10';
$fiscal_year = '2082';

// Get search parameters
$search_params = [
    'year_month_nep' => $_GET['year_month_nep'] ?? $current_year_month,
    'department_id' => $_GET['department_id'] ?? '',
    'designation_id' => $_GET['designation_id'] ?? '',
    'level_id' => $_GET['level_id'] ?? ''
];

// Pagination
$records_per_page = 30;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Build query
$where_conditions = ["e.deleted_date IS NULL", "e.emp_status = 'ACTIVE'"];
$bind_params = [];

if (!empty($search_params['year_month_nep'])) {
    $where_conditions[] = "ams.year_month_nep = :year_month_nep";
    $bind_params[':year_month_nep'] = $search_params['year_month_nep'];
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

$where_clause = implode(' AND ', $where_conditions);

// Count query
$count_query = "
    SELECT COUNT(*) as total
    FROM employee e
    LEFT JOIN attendance_monthly_summary ams ON e.id = ams.employee_id 
        AND ams.year_month_nep = :year_month_nep
    WHERE {$where_clause}
";

$count_stmt = $conn->prepare($count_query);
$count_stmt->bindValue(':year_month_nep', $search_params['year_month_nep']);
foreach ($bind_params as $key => $value) {
    if ($key !== ':year_month_nep') {
        $count_stmt->bindValue($key, $value);
    }
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Main query with COALESCE for employees without attendance
$query = "
    SELECT 
        e.id as employee_id,
        e.code as employee_code,
        e.name as employee_name,
        e.name_nep as employee_name_nep,
        d.name as designation,
        l.name as level,
        dept.name as department,
        COALESCE(ams.total_working_days, 0) as total_working_days,
        COALESCE(ams.present_days, 0) as present_days,
        COALESCE(ams.absent_days, 0) as absent_days,
        COALESCE(ams.half_days, 0) as half_days,
        COALESCE(ams.leave_days, 0) as leave_days,
        COALESCE(ams.weekly_offs, 0) as weekly_offs,
        COALESCE(ams.public_holidays, 0) as public_holidays,
        COALESCE(ams.total_working_hours, 0) as total_working_hours,
        COALESCE(ams.total_ot_hours, 0) as total_ot_hours,
        COALESCE(ams.total_late_minutes, 0) as total_late_minutes,
        COALESCE(ams.lwp_days, 0) as lwp_days,
        COALESCE(ams.payable_days, 0) as payable_days,
        ams.is_locked,
        ams.id as summary_id
    FROM employee e
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN level l ON e.level_id = l.id
    LEFT JOIN department dept ON e.department_id = dept.id
    LEFT JOIN attendance_monthly_summary ams ON e.id = ams.employee_id 
        AND ams.year_month_nep = :year_month_nep
    WHERE {$where_clause}
    ORDER BY e.code
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($query);
$stmt->bindValue(':year_month_nep', $search_params['year_month_nep']);
foreach ($bind_params as $key => $value) {
    if ($key !== ':year_month_nep') {
        $stmt->bindValue($key, $value);
    }
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$monthly_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get dropdown data
$departments = $conn->query("SELECT * FROM department ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$designations = $conn->query("SELECT * FROM designation ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $conn->query("SELECT * FROM level ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$totals = [
    'total_employees' => count($monthly_records),
    'total_present_days' => array_sum(array_column($monthly_records, 'present_days')),
    'total_absent_days' => array_sum(array_column($monthly_records, 'absent_days')),
    'total_leave_days' => array_sum(array_column($monthly_records, 'leave_days')),
    'total_ot_hours' => array_sum(array_column($monthly_records, 'total_ot_hours')),
    'total_payable_days' => array_sum(array_column($monthly_records, 'payable_days'))
];

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="monthly_attendance_' . $search_params['year_month_nep'] . '.xls"');
    header('Cache-Control: max-age=0');
    
    $export_query = str_replace(" LIMIT :limit OFFSET :offset", "", $query);
    $export_stmt = $conn->prepare($export_query);
    $export_stmt->bindValue(':year_month_nep', $search_params['year_month_nep']);
    foreach ($bind_params as $key => $value) {
        if ($key !== ':year_month_nep') {
            $export_stmt->bindValue($key, $value);
        }
    }
    $export_stmt->execute();
    $export_records = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr style='background: #667eea; color: white;'>";
    echo "<th>Employee Code</th><th>Employee Name</th><th>Designation</th><th>Level</th><th>Department</th>";
    echo "<th>Working Days</th><th>Present Days</th><th>Absent Days</th><th>Half Days</th>";
    echo "<th>Leave Days</th><th>Weekly Offs</th><th>Public Holidays</th>";
    echo "<th>Working Hours</th><th>OT Hours</th><th>LWP Days</th><th>Payable Days</th></tr>";
    
    foreach ($export_records as $record) {
        echo "<tr>";
        echo "<td>" . $record['employee_code'] . "</td>";
        echo "<td>" . htmlspecialchars($record['employee_name']) . "</td>";
        echo "<td>" . htmlspecialchars($record['designation']) . "</td>";
        echo "<td>" . htmlspecialchars($record['level']) . "</td>";
        echo "<td>" . htmlspecialchars($record['department']) . "</td>";
        echo "<td>" . number_format($record['total_working_days']) . "</td>";
        echo "<td>" . number_format($record['present_days'], 2) . "</td>";
        echo "<td>" . number_format($record['absent_days'], 2) . "</td>";
        echo "<td>" . number_format($record['half_days'], 2) . "</td>";
        echo "<td>" . number_format($record['leave_days'], 2) . "</td>";
        echo "<td>" . number_format($record['weekly_offs']) . "</td>";
        echo "<td>" . number_format($record['public_holidays']) . "</td>";
        echo "<td>" . number_format($record['total_working_hours'], 2) . "</td>";
        echo "<td>" . number_format($record['total_ot_hours'], 2) . "</td>";
        echo "<td>" . number_format($record['lwp_days'], 2) . "</td>";
        echo "<td>" . number_format($record['payable_days'], 2) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit();
}

// Generate month options (last 12 months)
$month_options = [];
for ($i = 0; $i < 12; $i++) {
    $month_num = 10 - $i; // Starting from Magh (10)
    $year = 2082;
    if ($month_num <= 0) {
        $month_num += 12;
        $year = 2081;
    }
    $month_options[] = sprintf("%04d.%02d", $year, $month_num);
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
    max-width: 1900px;
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
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
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
.summary-card.payable { border-left-color: #ffc107; }

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
    min-width: 1600px;
}

.data-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.data-table th {
    padding: 12px 8px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: white;
    text-transform: uppercase;
    position: sticky;
    top: 0;
    z-index: 10;
}

.data-table td {
    padding: 10px 8px;
    font-size: 12px;
    border-bottom: 1px solid #f0f0f0;
    text-align: center;
}

.data-table td:first-child,
.data-table td:nth-child(2) {
    text-align: left;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.data-table tbody tr:nth-child(even) {
    background: #fafafa;
}

.totals-row {
    font-weight: bold;
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%) !important;
    border-top: 2px solid #667eea;
}

.locked-badge {
    display: inline-block;
    padding: 3px 8px;
    background: #dc3545;
    color: white;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
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
        <h1 class="page-title">📈 Monthly Attendance Summary Report</h1>
        <p class="page-subtitle">Comprehensive payroll-ready attendance analysis</p>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-value"><?= number_format($totals['total_employees']) ?></div>
            <div class="summary-label">Total Employees</div>
        </div>
        
        <div class="summary-card present">
            <div class="summary-value"><?= number_format($totals['total_present_days'], 1) ?></div>
            <div class="summary-label">Total Present Days</div>
        </div>
        
        <div class="summary-card absent">
            <div class="summary-value"><?= number_format($totals['total_absent_days'], 1) ?></div>
            <div class="summary-label">Total Absent Days</div>
        </div>
        
        <div class="summary-card">
            <div class="summary-value"><?= number_format($totals['total_leave_days'], 1) ?></div>
            <div class="summary-label">Total Leave Days</div>
        </div>
        
        <div class="summary-card">
            <div class="summary-value"><?= number_format($totals['total_ot_hours'], 1) ?></div>
            <div class="summary-label">Total OT Hours</div>
        </div>
        
        <div class="summary-card payable">
            <div class="summary-value"><?= number_format($totals['total_payable_days'], 1) ?></div>
            <div class="summary-label">Total Payable Days</div>
        </div>
    </div>

    <!-- Search/Filter Section -->
    <div class="search-container">
        <form method="get" id="searchForm">
            <input type="hidden" name="page" value="1">
            
            <div class="search-grid">
                <div class="search-group">
                    <label for="year_month_nep">📅 Month (YYYY.MM)</label>
                    <select name="year_month_nep" id="year_month_nep" class="search-control">
                        <?php foreach ($month_options as $month): ?>
                            <option value="<?= $month ?>" 
                                    <?= $search_params['year_month_nep'] === $month ? 'selected' : '' ?>>
                                <?= $month ?> (<?= getNepaliMonthName($month) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
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
            <span style="font-size: 14px; color: #6c757d;">
                Showing <?= count($monthly_records) ?> of <?= number_format($total_records) ?> employees
            </span>
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
                    <th>Dept</th>
                    <th>Work<br>Days</th>
                    <th>Present<br>Days</th>
                    <th>Absent<br>Days</th>
                    <th>Half<br>Days</th>
                    <th>Leave<br>Days</th>
                    <th>Weekly<br>Offs</th>
                    <th>Public<br>Holidays</th>
                    <th>Working<br>Hours</th>
                    <th>OT<br>Hours</th>
                    <th>Late<br>(min)</th>
                    <th>LWP<br>Days</th>
                    <th>Payable<br>Days</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($monthly_records)): ?>
                    <tr>
                        <td colspan="18" style="text-align: center; padding: 40px;">
                            No records found for the selected month
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($monthly_records as $record): ?>
                        <tr>
                            <td><strong><?= $record['employee_code'] ?></strong></td>
                            <td style="text-align: left;">
                                <?= htmlspecialchars($record['employee_name']) ?><br>
                                <small style="color: #6c757d;"><?= htmlspecialchars($record['employee_name_nep']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($record['designation']) ?></td>
                            <td><?= htmlspecialchars($record['level']) ?></td>
                            <td><?= htmlspecialchars($record['department']) ?></td>
                            <td><?= number_format($record['total_working_days']) ?></td>
                            <td style="background: #d4edda;"><strong><?= number_format($record['present_days'], 1) ?></strong></td>
                            <td style="background: #f8d7da;"><strong><?= number_format($record['absent_days'], 1) ?></strong></td>
                            <td><?= number_format($record['half_days'], 1) ?></td>
                            <td><?= number_format($record['leave_days'], 1) ?></td>
                            <td><?= number_format($record['weekly_offs']) ?></td>
                            <td><?= number_format($record['public_holidays']) ?></td>
                            <td><?= number_format($record['total_working_hours'], 1) ?></td>
                            <td style="background: #fff3cd;"><strong><?= number_format($record['total_ot_hours'], 1) ?></strong></td>
                            <td><?= number_format($record['total_late_minutes']) ?></td>
                            <td><?= number_format($record['lwp_days'], 1) ?></td>
                            <td style="background: #d1ecf1;"><strong><?= number_format($record['payable_days'], 2) ?></strong></td>
                            <td>
                                <?php if ($record['is_locked']): ?>
                                    <span class="locked-badge">🔒 Locked</span>
                                <?php else: ?>
                                    <span style="color: #28a745; font-size: 11px;">✓ Open</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    
                    <!-- Totals Row -->
                    <tr class="totals-row">
                        <td colspan="6"><strong>📊 TOTALS:</strong></td>
                        <td><strong><?= number_format($totals['total_present_days'], 1) ?></strong></td>
                        <td><strong><?= number_format($totals['total_absent_days'], 1) ?></strong></td>
                        <td>-</td>
                        <td><strong><?= number_format($totals['total_leave_days'], 1) ?></strong></td>
                        <td>-</td>
                        <td>-</td>
                        <td>-</td>
                        <td><strong><?= number_format($totals['total_ot_hours'], 1) ?></strong></td>
                        <td>-</td>
                        <td>-</td>
                        <td><strong><?= number_format($totals['total_payable_days'], 1) ?></strong></td>
                        <td>-</td>
                    </tr>
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

<?php
function getNepaliMonthName($yearMonth) {
    $months = [
        '01' => 'Baishakh', '02' => 'Jestha', '03' => 'Ashadh',
        '04' => 'Shrawan', '05' => 'Bhadra', '06' => 'Ashwin',
        '07' => 'Kartik', '08' => 'Mangsir', '09' => 'Poush',
        '10' => 'Magh', '11' => 'Falgun', '12' => 'Chaitra'
    ];
    
    $parts = explode('.', $yearMonth);
    return $months[$parts[1]] ?? '';
}
?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

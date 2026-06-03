<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('viewer') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Get filter parameters
$fiscal_year_filter = $_GET['fiscal_year'] ?? '';
$job_code_filter = $_GET['job_code'] ?? '';
$book_code_filter = $_GET['book_code'] ?? '';
$class_filter = $_GET['class'] ?? '';
$status_filter = $_GET['status'] ?? '';
$date_from_nep = $_GET['date_from_nep'] ?? '';
$date_to_nep = $_GET['date_to_nep'] ?? '';
$date_from_eng = $_GET['date_from_eng'] ?? '';
$date_to_eng = $_GET['date_to_eng'] ?? '';
$operator_filter = $_GET['operator'] ?? '';
$supervisor_filter = $_GET['supervisor'] ?? '';
$incharge_filter = $_GET['incharge'] ?? '';
$machine_filter = $_GET['machine'] ?? '';
$shift_filter = $_GET['shift'] ?? '';
$completion_min = $_GET['completion_min'] ?? '';
$completion_max = $_GET['completion_max'] ?? '';

// Get dropdown data
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$books = $conn->query("SELECT DISTINCT book_code, book_name FROM books ORDER BY book_code")->fetchAll(PDO::FETCH_ASSOC);
$users = $conn->query("SELECT id, username FROM users ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name")->fetchAll(PDO::FETCH_ASSOC);
$shifts = $conn->query("SELECT id, name FROM shifts WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Build the main query with filters
$where_conditions = ["1=1"];
$params = [];

if ($fiscal_year_filter) {
    $where_conditions[] = "jt.fiscal_year_id = :fiscal_year";
    $params[':fiscal_year'] = $fiscal_year_filter;
}
if ($job_code_filter) {
    $where_conditions[] = "jt.job_ticket_code LIKE :job_code";
    $params[':job_code'] = "%$job_code_filter%";
}
if ($book_code_filter) {
    $where_conditions[] = "b.book_code LIKE :book_code";
    $params[':book_code'] = "%$book_code_filter%";
}
if ($class_filter) {
    $where_conditions[] = "b.class_level = :class";
    $params[':class'] = $class_filter;
}
if ($status_filter) {
    $where_conditions[] = "jt.status = :status";
    $params[':status'] = $status_filter;
}

$where_clause = implode(" AND ", $where_conditions);

// Main query to get all job tickets with their progress
$sql = "
SELECT 
    jt.id as jt_id,
    jt.job_ticket_code,
    jt.lot,
    jt.print_qty as jt_print_qty,
    jt.page_qty,
    jt.status as jt_status,
    jt.created_date as jt_created_date,
    jt.date_nep,
    jt.date_eng,
    b.book_code,
    b.book_name,
    b.class_level,
    fy.fiscal_code,
    u_created.username as created_by_name,
    
    -- Forma printing progress
    (SELECT COUNT(*) FROM job_ticket_details jtd WHERE jtd.job_ticket_id = jt.id) as total_formas,
    
   (
    SELECT COUNT(*)
    FROM (
        SELECT 
            jtd2.id,
            jtd2.print_qty,
            SUM(COALESCE(fp2.fp_printqty, 0)) AS printed_qty
        FROM job_ticket_details jtd2
        LEFT JOIN forma_printing fp2
               ON fp2.jtd_id = jtd2.id
              AND fp2.status = true
        WHERE jtd2.job_ticket_id = jt.id
        GROUP BY jtd2.id, jtd2.print_qty
        HAVING SUM(COALESCE(fp2.fp_printqty, 0)) >= jtd2.print_qty
    ) completed
) AS completed_formas,
 
    (SELECT SUM(jtd3.print_qty) FROM job_ticket_details jtd3 WHERE jtd3.job_ticket_id = jt.id) as total_forma_target,
    
    (SELECT SUM(fp3.fp_printqty) 
     FROM forma_printing fp3
     WHERE fp3.jt_id = jt.id AND fp3.status = true
    ) as total_printed,
    
    -- Book packing progress
    (SELECT SUM(bp.p_qty) 
     FROM book_packing bp 
     WHERE bp.jt_id = jt.id AND bp.status = true
    ) as total_packed,
    
    -- Time tracking
    (SELECT MIN(fp4.created_date) 
     FROM forma_printing fp4 
     WHERE fp4.jt_id = jt.id AND fp4.status = true
    ) as fp_start_date,
    
    (SELECT MAX(fp5.created_date) 
     FROM forma_printing fp5 
     WHERE fp5.jt_id = jt.id AND fp5.status = true
    ) as fp_last_date,
    
    (SELECT MIN(bp2.created_date) 
     FROM book_packing bp2 
     WHERE bp2.jt_id = jt.id AND bp2.status = true
    ) as bp_start_date,
    
    (SELECT MAX(bp3.created_date) 
     FROM book_packing bp3 
     WHERE bp3.jt_id = jt.id AND bp3.status = true
    ) as bp_last_date
    
FROM job_ticket jt
LEFT JOIN books b ON jt.book_id = b.book_id
LEFT JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
LEFT JOIN users u_created ON jt.created_by = u_created.id
WHERE $where_clause
ORDER BY jt.created_date DESC
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$job_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate additional metrics for each job ticket
foreach ($job_tickets as &$jt) {
    $jt['total_formas'] = (int)$jt['total_formas'];
    $jt['completed_formas'] = (int)($jt['completed_formas'] ?? 0);
    $jt['total_forma_target'] = (int)($jt['total_forma_target'] ?? 0);
    $jt['total_printed'] = (int)($jt['total_printed'] ?? 0);
    $jt['total_packed'] = (int)($jt['total_packed'] ?? 0);
    
    // Calculate completion percentages
    $jt['forma_completion_pct'] = $jt['total_formas'] > 0 
        ? round(($jt['completed_formas'] / $jt['total_formas']) * 100, 2)
        : 0;
        
    $jt['printing_qty_pct'] = $jt['total_forma_target'] > 0
        ? round(($jt['total_printed'] / $jt['total_forma_target']) * 100, 2)
        : 0;
        
    $jt['packing_pct'] = $jt['jt_print_qty'] > 0
        ? round(($jt['total_packed'] / $jt['jt_print_qty']) * 100, 2)
        : 0;
    
    // Overall progress (weighted average: 50% printing, 50% packing)
    $jt['overall_progress'] = round(($jt['printing_qty_pct'] * 0.5) + ($jt['packing_pct'] * 0.5), 2);
    
    // Calculate time spent
    if ($jt['fp_start_date'] && $jt['fp_last_date']) {
        $start = new DateTime($jt['fp_start_date']);
        $end = new DateTime($jt['fp_last_date']);
        $jt['fp_duration_days'] = $start->diff($end)->days;
    } else {
        $jt['fp_duration_days'] = 0;
    }
    
    if ($jt['bp_start_date'] && $jt['bp_last_date']) {
        $start = new DateTime($jt['bp_start_date']);
        $end = new DateTime($jt['bp_last_date']);
        $jt['bp_duration_days'] = $start->diff($end)->days;
    } else {
        $jt['bp_duration_days'] = 0;
    }
    
    // Check for over-printing
    $jt['is_overprinted'] = $jt['total_printed'] > $jt['total_forma_target'];
    $jt['overprint_qty'] = $jt['is_overprinted'] ? ($jt['total_printed'] - $jt['total_forma_target']) : 0;
}
unset($jt);

// Apply additional filters (completion percentage, dates, personnel)
$filtered_tickets = array_filter($job_tickets, function($jt) use (
    $completion_min, $completion_max, $date_from_eng, $date_to_eng,
    $operator_filter, $supervisor_filter, $incharge_filter, $machine_filter, $shift_filter,
    $conn
) {
    // Completion percentage filter
    if ($completion_min !== '' && $jt['overall_progress'] < (float)$completion_min) return false;
    if ($completion_max !== '' && $jt['overall_progress'] > (float)$completion_max) return false;
    
    // Date range filter
    if ($date_from_eng && $jt['date_eng'] < $date_from_eng) return false;
    if ($date_to_eng && $jt['date_eng'] > $date_to_eng) return false;
    
    // Personnel/Machine/Shift filters - check if any records match
    if ($operator_filter || $supervisor_filter || $incharge_filter || $machine_filter || $shift_filter) {
        $personnel_where = ["fp.jt_id = :jt_id", "fp.status = true"];
        $personnel_params = [':jt_id' => $jt['jt_id']];
        
        if ($operator_filter) {
            $personnel_where[] = "fp.operator_id = :operator";
            $personnel_params[':operator'] = $operator_filter;
        }
        if ($supervisor_filter) {
            $personnel_where[] = "fp.supervisor_id = :supervisor";
            $personnel_params[':supervisor'] = $supervisor_filter;
        }
        if ($incharge_filter) {
            $personnel_where[] = "fp.incharge_id = :incharge";
            $personnel_params[':incharge'] = $incharge_filter;
        }
        if ($machine_filter) {
            $personnel_where[] = "fp.machine_id = :machine";
            $personnel_params[':machine'] = $machine_filter;
        }
        if ($shift_filter) {
            $personnel_where[] = "fp.shift_id = :shift";
            $personnel_params[':shift'] = $shift_filter;
        }
        
        $check_sql = "SELECT COUNT(*) as cnt FROM forma_printing fp WHERE " . implode(" AND ", $personnel_where);
        $check_stmt = $GLOBALS['conn']->prepare($check_sql);
        $check_stmt->execute($personnel_params);
        $result = $check_stmt->fetch();
        
        if ($result['cnt'] == 0) return false;
    }
    
    return true;
});

// Calculate summary statistics
$total_jobs = count($filtered_tickets);
$completed_jobs = count(array_filter($filtered_tickets, fn($jt) => $jt['jt_status'] === 'completed'));
$in_progress_jobs = count(array_filter($filtered_tickets, fn($jt) => in_array($jt['jt_status'], ['processing', 'fp_completed', 'bp_completed'])));
$pending_jobs = count(array_filter($filtered_tickets, fn($jt) => in_array($jt['jt_status'], ['pending', 'active'])));
$avg_completion = $total_jobs > 0 ? round(array_sum(array_column($filtered_tickets, 'overall_progress')) / $total_jobs, 2) : 0;

?>

<style>
.report-container {
    max-width: 100%;
    padding: 20px;
}

.page-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 2px solid #e9ecef;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.header-actions {
    display: flex;
    gap: 10px;
}

/* Summary Cards */
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
    transition: transform 0.2s ease;
}

.summary-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.summary-card.completed { border-left-color: #28a745; }
.summary-card.in-progress { border-left-color: #ffc107; }
.summary-card.pending { border-left-color: #dc3545; }

.summary-card-value {
    font-size: 32px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.summary-card-label {
    font-size: 14px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}

/* Filters */
.filters-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.filters-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.filter-group label {
    display: block;
    font-size: 13px;
    font-weight: 600;
    margin-bottom: 5px;
    color: #495057;
}

.filter-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
}

.filter-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 15px;
}

/* Data Table */
.data-table-container {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #f8f9fa;
}

.data-table th {
    padding: 15px 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    border-bottom: 2px solid #dee2e6;
}

.data-table td {
    padding: 12px;
    font-size: 14px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

/* Status Badges */
.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.3px;
}

.status-pending { background: #fff3cd; color: #856404; }
.status-active { background: #cce5ff; color: #004085; }
.status-processing { background: #d1ecf1; color: #0c5460; }
.status-fp_completed { background: #d4edda; color: #155724; }
.status-bp_completed { background: #d4edda; color: #155724; }
.status-completed { background: #28a745; color: white; }
.status-cancelled { background: #f8d7da; color: #721c24; }

/* Progress Bars */
.progress-container {
    width: 100%;
    height: 20px;
    background: #e9ecef;
    border-radius: 10px;
    overflow: hidden;
    position: relative;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    border-radius: 10px;
    transition: width 0.3s ease;
    position: relative;
}

.progress-bar.low { background: linear-gradient(90deg, #dc3545 0%, #c82333 100%); }
.progress-bar.medium { background: linear-gradient(90deg, #ffc107 0%, #e0a800 100%); }
.progress-bar.high { background: linear-gradient(90deg, #28a745 0%, #218838 100%); }

.progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 11px;
    font-weight: 700;
    color: #333;
    z-index: 1;
}

/* Buttons */
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

.btn-primary { background: #007bff; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.btn:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.15); }

/* Alert boxes */
.alert-warning {
    background: #fff3cd;
    border: 1px solid #ffeeba;
    color: #856404;
    padding: 8px 12px;
    border-radius: 4px;
    font-size: 12px;
    display: inline-flex;
    align-items: center;
    gap: 5px;
}

.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
    font-size: 16px;
}

@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
    
    .data-table {
        font-size: 12px;
    }
    
    .data-table th,
    .data-table td {
        padding: 8px;
    }
}
</style>

<div class="report-container">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">📊 Production Tracking Report</h1>
        <div class="header-actions">
            <button class="btn btn-success" onclick="exportReport('excel')">
                📥 Export Excel
            </button>
            <button class="btn btn-primary" onclick="exportReport('pdf')">
                📄 Export PDF
            </button>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-card-value"><?= $total_jobs ?></div>
            <div class="summary-card-label">Total Job Tickets</div>
        </div>
        <div class="summary-card completed">
            <div class="summary-card-value"><?= $completed_jobs ?></div>
            <div class="summary-card-label">Completed</div>
        </div>
        <div class="summary-card in-progress">
            <div class="summary-card-value"><?= $in_progress_jobs ?></div>
            <div class="summary-card-label">In Progress</div>
        </div>
        <div class="summary-card pending">
            <div class="summary-card-value"><?= $pending_jobs ?></div>
            <div class="summary-card-label">Pending</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= $avg_completion ?>%</div>
            <div class="summary-card-label">Avg Completion</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filters-container">
        <div class="filters-title">
            🔍 Search & Filter Options
        </div>
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Fiscal Year</label>
                    <select name="fiscal_year" class="filter-control">
                        <option value="">All Fiscal Years</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Job Ticket Code</label>
                    <input type="text" name="job_code" class="filter-control" 
                           placeholder="JT code..." value="<?= htmlspecialchars($job_code_filter) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Book Code</label>
                    <input type="text" name="book_code" class="filter-control" 
                           placeholder="Book code..." value="<?= htmlspecialchars($book_code_filter) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Class Level</label>
                    <input type="number" name="class" class="filter-control" 
                           placeholder="Class..." value="<?= htmlspecialchars($class_filter) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="filter-control">
                        <option value="">All Statuses</option>
                        <option value="pending" <?= $status_filter == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="active" <?= $status_filter == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="processing" <?= $status_filter == 'processing' ? 'selected' : '' ?>>Processing</option>
                        <option value="fp_completed" <?= $status_filter == 'fp_completed' ? 'selected' : '' ?>>FP Completed</option>
                        <option value="bp_completed" <?= $status_filter == 'bp_completed' ? 'selected' : '' ?>>BP Completed</option>
                        <option value="completed" <?= $status_filter == 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="cancelled" <?= $status_filter == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Date From (Eng)</label>
                    <input type="date" name="date_from_eng" class="filter-control" 
                           value="<?= htmlspecialchars($date_from_eng) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Date To (Eng)</label>
                    <input type="date" name="date_to_eng" class="filter-control" 
                           value="<?= htmlspecialchars($date_to_eng) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Operator</label>
                    <select name="operator" class="filter-control">
                        <option value="">All Operators</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= $operator_filter == $user['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Supervisor</label>
                    <select name="supervisor" class="filter-control">
                        <option value="">All Supervisors</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= $supervisor_filter == $user['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Incharge</label>
                    <select name="incharge" class="filter-control">
                        <option value="">All Incharges</option>
                        <?php foreach ($users as $user): ?>
                            <option value="<?= $user['id'] ?>" <?= $incharge_filter == $user['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($user['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Machine</label>
                    <select name="machine" class="filter-control">
                        <option value="">All Machines</option>
                        <?php foreach ($machines as $machine): ?>
                            <option value="<?= $machine['id'] ?>" <?= $machine_filter == $machine['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($machine['machine_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Shift</label>
                    <select name="shift" class="filter-control">
                        <option value="">All Shifts</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?= $shift['id'] ?>" <?= $shift_filter == $shift['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($shift['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Completion Min %</label>
                    <input type="number" name="completion_min" class="filter-control" 
                           placeholder="0" min="0" max="100" value="<?= htmlspecialchars($completion_min) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Completion Max %</label>
                    <input type="number" name="completion_max" class="filter-control" 
                           placeholder="100" min="0" max="100" value="<?= htmlspecialchars($completion_max) ?>">
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="button" class="btn btn-secondary" onclick="resetFilters()">
                    🔄 Reset
                </button>
                <button type="submit" class="btn btn-primary">
                    🔍 Apply Filters
                </button>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="data-table-container">
        <?php if (empty($filtered_tickets)): ?>
            <div class="no-data">
                <p>📭 No job tickets found matching your filters.</p>
            </div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Job Code</th>
                        <th>Book</th>
                        <th>FY</th>
                        <th>Status</th>
                        <th>Formas</th>
                        <th>Printing Progress</th>
                        <th>Packing Progress</th>
                        <th>Overall</th>
                        <th>Duration (FP)</th>
                        <th>Duration (BP)</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($filtered_tickets as $ticket): 
                        $progress_class = $ticket['overall_progress'] < 30 ? 'low' : 
                                        ($ticket['overall_progress'] < 70 ? 'medium' : 'high');
                    ?>
                    <tr>
                        <td>
                            <strong><?= htmlspecialchars($ticket['job_ticket_code']) ?></strong>
                            <br>
                            <small style="color: #6c757d;">Lot: <?= htmlspecialchars($ticket['lot']) ?></small>
                        </td>
                        <td>
                            <div><?= htmlspecialchars($ticket['book_code']) ?></div>
                            <small style="color: #6c757d;"><?= htmlspecialchars($ticket['book_name']) ?></small>
                            <br>
                            <small>Class: <?= $ticket['class_level'] ?></small>
                        </td>
                        <td><?= htmlspecialchars($ticket['fiscal_code']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $ticket['jt_status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $ticket['jt_status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div>
                                <strong><?= $ticket['completed_formas'] ?></strong> / <?= $ticket['total_formas'] ?>
                            </div>
                            <small style="color: #6c757d;">
                                <?= $ticket['forma_completion_pct'] ?>% complete
                            </small>
                        </td>
                        <td>
                            <div class="progress-container">
                                <div class="progress-bar <?= $progress_class ?>" 
                                     style="width: <?= $ticket['printing_qty_pct'] ?>%"></div>
                                <div class="progress-text"><?= $ticket['printing_qty_pct'] ?>%</div>
                            </div>
                            <small style="color: #6c757d;">
                                <?= number_format($ticket['total_printed']) ?> / <?= number_format($ticket['total_forma_target']) ?>
                            </small>
                            <?php if ($ticket['is_overprinted']): ?>
                                <div class="alert-warning" style="margin-top: 4px;">
                                    ⚠️ Over: +<?= number_format($ticket['overprint_qty']) ?>
                                </div>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress-container">
                                <div class="progress-bar <?= $progress_class ?>" 
                                     style="width: <?= $ticket['packing_pct'] ?>%"></div>
                                <div class="progress-text"><?= $ticket['packing_pct'] ?>%</div>
                            </div>
                            <small style="color: #6c757d;">
                                <?= number_format($ticket['total_packed']) ?> / <?= number_format($ticket['jt_print_qty']) ?>
                            </small>
                        </td>
                        <td>
                            <div class="progress-container">
                                <div class="progress-bar <?= $progress_class ?>" 
                                     style="width: <?= $ticket['overall_progress'] ?>%"></div>
                                <div class="progress-text"><?= $ticket['overall_progress'] ?>%</div>
                            </div>
                        </td>
                        <td>
                            <?= $ticket['fp_duration_days'] ?> days
                        </td>
                        <td>
                            <?= $ticket['bp_duration_days'] ?> days
                        </td>
                        <td>
                            <a href="report_detail.php?jt_id=<?= $ticket['jt_id'] ?>" 
                               class="btn btn-primary btn-sm">
                                📋 Details
                            </a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<script>
function resetFilters() {
    window.location.href = window.location.pathname;
}

function exportReport(type) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', type);
    window.location.href = 'report_export.php?' + params.toString();
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
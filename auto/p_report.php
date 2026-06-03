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

// Pagination settings
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 25;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $records_per_page;

// Get filter parameters
$filters = [
    'fiscal_year' => $_GET['fiscal_year'] ?? '',
    'job_code' => $_GET['job_code'] ?? '',
    'book_code' => $_GET['book_code'] ?? '',
    'class' => $_GET['class'] ?? '',
    'status' => $_GET['status'] ?? '',
    'date_from_eng' => $_GET['date_from_eng'] ?? '',
    'date_to_eng' => $_GET['date_to_eng'] ?? '',
    'completion_min' => $_GET['completion_min'] ?? '',
    'completion_max' => $_GET['completion_max'] ?? '',
    'has_deno' => $_GET['has_deno'] ?? '',
    'has_d2m' => $_GET['has_d2m'] ?? '',
];

// Get dropdown data
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);

// Build WHERE clause for main query
$where_conditions = ["1=1"];
$params = [];

if ($filters['fiscal_year']) {
    $where_conditions[] = "jt.fiscal_year_id = :fiscal_year";
    $params[':fiscal_year'] = $filters['fiscal_year'];
}
if ($filters['job_code']) {
    $where_conditions[] = "jt.job_ticket_code LIKE :job_code";
    $params[':job_code'] = "%{$filters['job_code']}%";
}
if ($filters['book_code']) {
    $where_conditions[] = "b.book_code LIKE :book_code";
    $params[':book_code'] = "%{$filters['book_code']}%";
}
if ($filters['class']) {
    $where_conditions[] = "b.class_level = :class";
    $params[':class'] = $filters['class'];
}
if ($filters['status']) {
    $where_conditions[] = "jt.status = :status";
    $params[':status'] = $filters['status'];
}
if ($filters['date_from_eng']) {
    $where_conditions[] = "jt.date_eng >= :date_from";
    $params[':date_from'] = $filters['date_from_eng'];
}
if ($filters['date_to_eng']) {
    $where_conditions[] = "jt.date_eng <= :date_to";
    $params[':date_to'] = $filters['date_to_eng'];
}

$where_clause = implode(" AND ", $where_conditions);

// Main comprehensive query
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
    
    (SELECT COUNT(*)
     FROM (
         SELECT jtd2.id, jtd2.print_qty, SUM(COALESCE(fp2.fp_printqty, 0)) AS printed_qty
         FROM job_ticket_details jtd2
         LEFT JOIN forma_printing fp2 ON fp2.jtd_id = jtd2.id AND fp2.status = true
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
    (SELECT COUNT(*) FROM book_packing bp WHERE bp.jt_id = jt.id AND bp.status = true) as packing_count,
    
    (SELECT SUM(bp.p_qty) 
     FROM book_packing bp 
     WHERE bp.jt_id = jt.id AND bp.status = true
    ) as total_packed,
    
    -- Deno tracking
    (SELECT COUNT(DISTINCT d.id) 
     FROM deno d 
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL
    ) as deno_count,
    
    (SELECT SUM(d.total_qty) 
     FROM deno d 
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL
    ) as deno_total_qty,
    
    (SELECT COUNT(DISTINCT d.id)
     FROM deno d
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL AND d.verify_by IS NOT NULL
    ) as deno_verified_count,
    
    (SELECT string_agg(d.ref_no, ', ')
     FROM deno d
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL
    ) as deno_ref_nos,
    
    -- D2M tracking
    (SELECT COUNT(DISTINCT d2m.id)
     FROM deno d
     JOIN d2m_items di ON d.id = ANY(string_to_array(di.associated_deno_ids, ',')::int[])
     JOIN d2m ON di.d2m_id = d2m.id
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL
    ) as d2m_count,
    
    (SELECT string_agg(DISTINCT d2m.d2m_no, ', ')
     FROM deno d
     JOIN d2m_items di ON d.id = ANY(string_to_array(di.associated_deno_ids, ',')::int[])
     JOIN d2m ON di.d2m_id = d2m.id
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL
    ) as d2m_numbers,
    
    (SELECT COUNT(DISTINCT d2m.id)
     FROM deno d
     JOIN d2m_items di ON d.id = ANY(string_to_array(di.associated_deno_ids, ',')::int[])
     JOIN d2m ON di.d2m_id = d2m.id
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL AND d2m.status = 'VERIFIED'
    ) as d2m_verified_count,
    
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
    ) as bp_last_date,
    
    (SELECT MIN(d.created_at)
     FROM deno d
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL
    ) as deno_start_date,
    
    (SELECT MAX(d.created_at)
     FROM deno d
     WHERE d.jt_id = jt.id AND d.deleted_at IS NULL
    ) as deno_last_date
    
FROM job_ticket jt
LEFT JOIN books b ON jt.book_id = b.book_id
LEFT JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
LEFT JOIN users u_created ON jt.created_by = u_created.id
WHERE $where_clause
ORDER BY jt.created_date DESC
";

// Get total count for pagination
$count_sql = "
SELECT COUNT(*) as total
FROM job_ticket jt
LEFT JOIN books b ON jt.book_id = b.book_id
WHERE $where_clause
";

$count_stmt = $conn->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add pagination to main query
$sql .= " LIMIT :limit OFFSET :offset";
$params[':limit'] = $records_per_page;
$params[':offset'] = $offset;

// Execute main query
$stmt = $conn->prepare($sql);
$stmt->execute($params);
$job_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate metrics for each job ticket
foreach ($job_tickets as &$jt) {
    $jt['total_formas'] = (int)$jt['total_formas'];
    $jt['completed_formas'] = (int)($jt['completed_formas'] ?? 0);
    $jt['total_forma_target'] = (int)($jt['total_forma_target'] ?? 0);
    $jt['total_printed'] = (int)($jt['total_printed'] ?? 0);
    $jt['total_packed'] = (int)($jt['total_packed'] ?? 0);
    $jt['deno_count'] = (int)($jt['deno_count'] ?? 0);
    $jt['deno_total_qty'] = (int)($jt['deno_total_qty'] ?? 0);
    $jt['deno_verified_count'] = (int)($jt['deno_verified_count'] ?? 0);
    $jt['d2m_count'] = (int)($jt['d2m_count'] ?? 0);
    $jt['d2m_verified_count'] = (int)($jt['d2m_verified_count'] ?? 0);
    
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
    
    $jt['deno_pct'] = $jt['jt_print_qty'] > 0
        ? round(($jt['deno_total_qty'] / $jt['jt_print_qty']) * 100, 2)
        : 0;
    
    // Overall progress (weighted: 30% printing, 30% packing, 25% deno, 15% d2m)
    $d2m_completion = $jt['deno_count'] > 0 && $jt['d2m_verified_count'] > 0 ? 100 : 0;
    $jt['overall_progress'] = round(
        ($jt['printing_qty_pct'] * 0.30) + 
        ($jt['packing_pct'] * 0.30) + 
        ($jt['deno_pct'] * 0.25) +
        ($d2m_completion * 0.15),
        2
    );
    
    // Determine completion status
    if ($jt['d2m_verified_count'] > 0 && $jt['overall_progress'] >= 95) {
        $jt['completion_status'] = 'complete';
    } elseif ($jt['deno_count'] > 0) {
        $jt['completion_status'] = 'deno_stage';
    } elseif ($jt['total_packed'] > 0) {
        $jt['completion_status'] = 'packing_stage';
    } elseif ($jt['total_printed'] > 0) {
        $jt['completion_status'] = 'printing_stage';
    } else {
        $jt['completion_status'] = 'pending';
    }
    
    // Calculate duration
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

// Apply additional filters
$filtered_tickets = $job_tickets;

if ($filters['has_deno'] !== '') {
    $filtered_tickets = array_filter($filtered_tickets, function($jt) use ($filters) {
        return $filters['has_deno'] === 'yes' ? $jt['deno_count'] > 0 : $jt['deno_count'] == 0;
    });
}

if ($filters['has_d2m'] !== '') {
    $filtered_tickets = array_filter($filtered_tickets, function($jt) use ($filters) {
        return $filters['has_d2m'] === 'yes' ? $jt['d2m_count'] > 0 : $jt['d2m_count'] == 0;
    });
}

if ($filters['completion_min'] !== '') {
    $filtered_tickets = array_filter($filtered_tickets, function($jt) use ($filters) {
        return $jt['overall_progress'] >= (float)$filters['completion_min'];
    });
}

if ($filters['completion_max'] !== '') {
    $filtered_tickets = array_filter($filtered_tickets, function($jt) use ($filters) {
        return $jt['overall_progress'] <= (float)$filters['completion_max'];
    });
}

// Calculate summary statistics
$total_jobs = count($filtered_tickets);
$completed_jobs = count(array_filter($filtered_tickets, fn($jt) => $jt['completion_status'] === 'complete'));
$deno_stage_jobs = count(array_filter($filtered_tickets, fn($jt) => $jt['completion_status'] === 'deno_stage'));
$packing_stage_jobs = count(array_filter($filtered_tickets, fn($jt) => $jt['completion_status'] === 'packing_stage'));
$printing_stage_jobs = count(array_filter($filtered_tickets, fn($jt) => $jt['completion_status'] === 'printing_stage'));
$pending_jobs = count(array_filter($filtered_tickets, fn($jt) => $jt['completion_status'] === 'pending'));
$avg_completion = $total_jobs > 0 ? round(array_sum(array_column($filtered_tickets, 'overall_progress')) / $total_jobs, 2) : 0;

$total_deno_entries = array_sum(array_column($filtered_tickets, 'deno_count'));
$total_d2m_docs = array_sum(array_column($filtered_tickets, 'd2m_count'));

?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
    font-size: 14px;
}

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

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    border-radius: 10px;
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
.summary-card.deno-stage { border-left-color: #17a2b8; }
.summary-card.packing-stage { border-left-color: #ffc107; }
.summary-card.printing-stage { border-left-color: #6f42c1; }
.summary-card.pending { border-left-color: #dc3545; }

.summary-card-value {
    font-size: 32px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.summary-card-label {
    font-size: 11px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    letter-spacing: 0.5px;
}

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
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.filter-group label {
    display: block;
    font-size: 12px;
    font-weight: 600;
    margin-bottom: 5px;
    color: #495057;
}

.filter-control {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 13px;
}

.filter-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,0.1);
}

.filter-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 15px;
}

.data-table-container {
    background: white;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
}

.data-table thead {
    background: #f8f9fa;
    position: sticky;
    top: 0;
    z-index: 10;
}

.data-table th {
    padding: 12px 8px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.3px;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}

.data-table td {
    padding: 10px 8px;
    font-size: 12px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 10px;
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

.progress-container {
    width: 100%;
    height: 16px;
    background: #e9ecef;
    border-radius: 8px;
    overflow: hidden;
    position: relative;
    margin-bottom: 2px;
}

.progress-bar {
    height: 100%;
    background: linear-gradient(90deg, #667eea 0%, #764ba2 100%);
    border-radius: 8px;
    transition: width 0.3s ease;
}

.progress-bar.low { background: linear-gradient(90deg, #dc3545 0%, #c82333 100%); }
.progress-bar.medium { background: linear-gradient(90deg, #ffc107 0%, #e0a800 100%); }
.progress-bar.high { background: linear-gradient(90deg, #28a745 0%, #218838 100%); }

.progress-text {
    position: absolute;
    top: 50%;
    left: 50%;
    transform: translate(-50%, -50%);
    font-size: 9px;
    font-weight: 700;
    color: #333;
    z-index: 1;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s ease;
}

.btn-primary { background: #007bff; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-sm { padding: 5px 10px; font-size: 11px; }

.btn:hover { 
    transform: translateY(-1px); 
    box-shadow: 0 4px 8px rgba(0,0,0,0.15); 
}

.stage-indicators {
    display: flex;
    gap: 6px;
    align-items: center;
}

.stage-icon {
    width: 22px;
    height: 22px;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 10px;
    font-weight: 700;
    position: relative;
    cursor: help;
}

.stage-icon.completed {
    background: #28a745;
    color: white;
}

.stage-icon.in-progress {
    background: #ffc107;
    color: #333;
}

.stage-icon.pending {
    background: #e9ecef;
    color: #6c757d;
}

.stage-icon::after {
    content: attr(data-tooltip);
    position: absolute;
    bottom: 120%;
    left: 50%;
    transform: translateX(-50%);
    background: #333;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    white-space: nowrap;
    opacity: 0;
    pointer-events: none;
    transition: opacity 0.2s;
    z-index: 100;
}

.stage-icon:hover::after {
    opacity: 1;
}

.pagination {
    display: flex;
    justify-content: space-between;
    align-items: center;
    gap: 10px;
    padding: 20px;
    background: white;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.pagination-info {
    font-size: 13px;
    color: #6c757d;
}

.pagination-controls {
    display: flex;
    gap: 5px;
}

.pagination a,
.pagination span {
    padding: 6px 12px;
    border: 1px solid #dee2e6;
    border-radius: 4px;
    text-decoration: none;
    color: #495057;
    font-size: 12px;
    font-weight: 600;
}

.pagination a:hover {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

.pagination .active {
    background: #007bff;
    color: white;
    border-color: #007bff;
}

.pagination .disabled {
    opacity: 0.5;
    pointer-events: none;
}

.no-data {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
    font-size: 16px;
}

@media print {
    .filters-container,
    .header-actions,
    .pagination,
    .btn {
        display: none !important;
    }
}

@media (max-width: 768px) {
    .filter-grid {
        grid-template-columns: 1fr;
    }
    
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<div class="report-container">
    <div class="page-header">
        <h1 class="page-title">📊 Comprehensive Production Tracking Report</h1>
        <div class="header-actions">
            <button class="btn btn-info" onclick="window.print()">🖨️ Print</button>
        </div>
    </div>

    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-card-value"><?= $total_records ?></div>
            <div class="summary-card-label">Total Jobs</div>
        </div>
        <div class="summary-card completed">
            <div class="summary-card-value"><?= $completed_jobs ?></div>
            <div class="summary-card-label">Completed</div>
        </div>
        <div class="summary-card deno-stage">
            <div class="summary-card-value"><?= $deno_stage_jobs ?></div>
            <div class="summary-card-label">Deno Stage</div>
        </div>
        <div class="summary-card packing-stage">
            <div class="summary-card-value"><?= $packing_stage_jobs ?></div>
            <div class="summary-card-label">Packing</div>
        </div>
        <div class="summary-card printing-stage">
            <div class="summary-card-value"><?= $printing_stage_jobs ?></div>
            <div class="summary-card-label">Printing</div>
        </div>
        <div class="summary-card pending">
            <div class="summary-card-value"><?= $pending_jobs ?></div>
            <div class="summary-card-label">Pending</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= $avg_completion ?>%</div>
            <div class="summary-card-label">Avg Progress</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= $total_deno_entries ?></div>
            <div class="summary-card-label">Deno Entries</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= $total_d2m_docs ?></div>
            <div class="summary-card-label">D2M Docs</div>
        </div>
    </div>

    <div class="filters-container">
        <div class="filters-title">🔍 Search & Filter Options</div>
        <form method="GET" id="filterForm">
            <input type="hidden" name="page" value="1">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Fiscal Year</label>
                    <select name="fiscal_year" class="filter-control">
                        <option value="">All</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $filters['fiscal_year'] == $fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Job Ticket Code</label>
                    <input type="text" name="job_code" class="filter-control" 
                           placeholder="JT code..." value="<?= htmlspecialchars($filters['job_code']) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Book Code</label>
                    <input type="text" name="book_code" class="filter-control" 
                           placeholder="Book code..." value="<?= htmlspecialchars($filters['book_code']) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Class Level</label>
                    <input type="number" name="class" class="filter-control" 
                           placeholder="Class..." value="<?= htmlspecialchars($filters['class']) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Status</label>
                    <select name="status" class="filter-control">
                        <option value="">All</option>
                        <option value="pending" <?= $filters['status'] == 'pending' ? 'selected' : '' ?>>Pending</option>
                        <option value="active" <?= $filters['status'] == 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="processing" <?= $filters['status'] == 'processing' ? 'selected' : '' ?>>Processing</option>
                        <option value="fp_completed" <?= $filters['status'] == 'fp_completed' ? 'selected' : '' ?>>FP Completed</option>
                        <option value="bp_completed" <?= $filters['status'] == 'bp_completed' ? 'selected' : '' ?>>BP Completed</option>
                        <option value="completed" <?= $filters['status'] == 'completed' ? 'selected' : '' ?>>Completed</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Date From</label>
                    <input type="date" name="date_from_eng" class="filter-control" 
                           value="<?= htmlspecialchars($filters['date_from_eng']) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Date To</label>
                    <input type="date" name="date_to_eng" class="filter-control" 
                           value="<?= htmlspecialchars($filters['date_to_eng']) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Has Deno?</label>
                    <select name="has_deno" class="filter-control">
                        <option value="">All</option>
                        <option value="yes" <?= $filters['has_deno'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                        <option value="no" <?= $filters['has_deno'] == 'no' ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Has D2M?</label>
                    <select name="has_d2m" class="filter-control">
                        <option value="">All</option>
                        <option value="yes" <?= $filters['has_d2m'] == 'yes' ? 'selected' : '' ?>>Yes</option>
                        <option value="no" <?= $filters['has_d2m'] == 'no' ? 'selected' : '' ?>>No</option>
                    </select>
                </div>
                
                <div class="filter-group">
                    <label>Min Progress %</label>
                    <input type="number" name="completion_min" class="filter-control" 
                           placeholder="0" min="0" max="100" value="<?= htmlspecialchars($filters['completion_min']) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Max Progress %</label>
                    <input type="number" name="completion_max" class="filter-control" 
                           placeholder="100" min="0" max="100" value="<?= htmlspecialchars($filters['completion_max']) ?>">
                </div>
                
                <div class="filter-group">
                    <label>Per Page</label>
                    <select name="per_page" class="filter-control">
                        <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                        <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                        <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
                        <option value="200" <?= $records_per_page == 200 ? 'selected' : '' ?>>200</option>
                    </select>
                </div>
            </div>
            
            <div class="filter-actions">
                <button type="button" class="btn btn-secondary" onclick="resetFilters()">🔄 Reset</button>
                <button type="submit" class="btn btn-primary">🔍 Apply Filters</button>
            </div>
        </form>
    </div>

    <div class="data-table-container">
        <?php if (empty($filtered_tickets)): ?>
            <div class="no-data">📭 No job tickets found matching your filters.</div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Job Code</th>
                        <th>Book</th>
                        <th>FY</th>
                        <th>Status</th>
                        <th>Stages</th>
                        <th>Printing</th>
                        <th>Packing</th>
                        <th>Deno</th>
                        <th>D2M</th>
                        <th>Overall</th>
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
                            <br><small style="color: #6c757d;">Lot: <?= htmlspecialchars($ticket['lot']) ?></small>
                        </td>
                        <td>
                            <div><strong><?= htmlspecialchars($ticket['book_code']) ?></strong></div>
                            <small style="color: #6c757d;"><?= htmlspecialchars($ticket['book_name']) ?></small>
                            <br><small>Class: <?= $ticket['class_level'] ?></small>
                        </td>
                        <td><?= htmlspecialchars($ticket['fiscal_code']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $ticket['jt_status'] ?>">
                                <?= ucfirst(str_replace('_', ' ', $ticket['jt_status'])) ?>
                            </span>
                        </td>
                        <td>
                            <div class="stage-indicators">
                                <div class="stage-icon <?= $ticket['total_printed'] > 0 ? ($ticket['printing_qty_pct'] >= 100 ? 'completed' : 'in-progress') : 'pending' ?>"
                                     data-tooltip="Printing">P</div>
                                <div class="stage-icon <?= $ticket['total_packed'] > 0 ? ($ticket['packing_pct'] >= 100 ? 'completed' : 'in-progress') : 'pending' ?>"
                                     data-tooltip="Packing">B</div>
                                <div class="stage-icon <?= $ticket['deno_count'] > 0 ? 'completed' : 'pending' ?>"
                                     data-tooltip="Deno">D</div>
                                <div class="stage-icon <?= $ticket['d2m_verified_count'] > 0 ? 'completed' : ($ticket['d2m_count'] > 0 ? 'in-progress' : 'pending') ?>"
                                     data-tooltip="D2M">M</div>
                            </div>
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
                            <div><strong><?= $ticket['deno_count'] ?></strong> entries</div>
                            <small style="color: #6c757d;">
                                Qty: <?= number_format($ticket['deno_total_qty']) ?>
                            </small>
                            <?php if ($ticket['deno_verified_count'] > 0): ?>
                                <br><small style="color: #28a745;">✓ <?= $ticket['deno_verified_count'] ?> verified</small>
                            <?php endif; ?>
                            <?php if ($ticket['deno_ref_nos']): ?>
                                <br><small style="color: #007bff;" title="<?= htmlspecialchars($ticket['deno_ref_nos']) ?>">
                                    <?= substr($ticket['deno_ref_nos'], 0, 20) ?><?= strlen($ticket['deno_ref_nos']) > 20 ? '...' : '' ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div><strong><?= $ticket['d2m_count'] ?></strong> docs</div>
                            <?php if ($ticket['d2m_verified_count'] > 0): ?>
                                <small style="color: #28a745;">✓ <?= $ticket['d2m_verified_count'] ?> verified</small>
                            <?php endif; ?>
                            <?php if ($ticket['d2m_numbers']): ?>
                                <br><small style="color: #007bff;" title="<?= htmlspecialchars($ticket['d2m_numbers']) ?>">
                                    <?= substr($ticket['d2m_numbers'], 0, 15) ?><?= strlen($ticket['d2m_numbers']) > 15 ? '...' : '' ?>
                                </small>
                            <?php endif; ?>
                        </td>
                        <td>
                            <div class="progress-container">
                                <div class="progress-bar <?= $progress_class ?>" 
                                     style="width: <?= $ticket['overall_progress'] ?>%"></div>
                                <div class="progress-text"><?= $ticket['overall_progress'] ?>%</div>
                            </div>
                        </td>
                        <td>
                            <a href="report_detail.php?jt_id=<?= $ticket['jt_id'] ?>" 
                               class="btn btn-primary btn-sm">📋 Details</a>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination">
        <div class="pagination-info">
            Showing <?= $offset + 1 ?> to <?= min($offset + $records_per_page, $total_records) ?> of <?= $total_records ?> records
        </div>
        <div class="pagination-controls">
            <?php if ($current_page > 1): ?>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => 1, 'per_page' => $records_per_page])) ?>">First</a>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => $current_page - 1, 'per_page' => $records_per_page])) ?>">Previous</a>
            <?php else: ?>
                <span class="disabled">First</span>
                <span class="disabled">Previous</span>
            <?php endif; ?>
            
            <?php 
            $start_page = max(1, $current_page - 2);
            $end_page = min($total_pages, $current_page + 2);
            for ($i = $start_page; $i <= $end_page; $i++): 
            ?>
                <?php if ($i == $current_page): ?>
                    <span class="active"><?= $i ?></span>
                <?php else: ?>
                    <a href="?<?= http_build_query(array_merge($filters, ['page' => $i, 'per_page' => $records_per_page])) ?>"><?= $i ?></a>
                <?php endif; ?>
            <?php endfor; ?>
            
            <?php if ($current_page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => $current_page + 1, 'per_page' => $records_per_page])) ?>">Next</a>
                <a href="?<?= http_build_query(array_merge($filters, ['page' => $total_pages, 'per_page' => $records_per_page])) ?>">Last</a>
            <?php else: ?>
                <span class="disabled">Next</span>
                <span class="disabled">Last</span>
            <?php endif; ?>
        </div>
    </div>
    <?php endif; ?>
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
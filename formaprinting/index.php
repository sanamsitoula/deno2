<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('incharge') && !has_role('supervisor') && !has_role('operator') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Set default to load latest 50 records
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $records_per_page;

// Get current fiscal year
$current_fiscal_year = $conn->query("
    SELECT id, fiscal_code 
    FROM fiscal_years 
    WHERE is_active = true 
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Get search parameters
$search_params = [
    'name' => $_GET['name'] ?? '',
    'jt_code' => $_GET['jt_code'] ?? '',
    'book_code' => $_GET['book_code'] ?? '',
    'class_level' => $_GET['class_level'] ?? '',
    'machine_id' => $_GET['machine_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'forma_status' => $_GET['forma_status'] ?? '',
    'jtd_status' => $_GET['jtd_status'] ?? '',
    'fiscal_year_id' => $_GET['fiscal_year_id'] ?? $current_fiscal_year['id'],
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? '',
    'supervisor_id' => $_GET['supervisor_id'] ?? '',
    'operator_id' => $_GET['operator_id'] ?? '',
    'incharge_id' => $_GET['incharge_id'] ?? '',
    'forma_name' => $_GET['forma_name'] ?? '',
    'lot' => $_GET['lot'] ?? ''
];

// Build base query with all required fields
$base_query = "
    FROM forma_printing fp
    LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
    LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN machines m ON fp.machine_id = m.id
    LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
    LEFT JOIN users supervisor ON fp.supervisor_id = supervisor.id
    LEFT JOIN users operator ON fp.operator_id = operator.id
    LEFT JOIN users incharge ON fp.incharge_id = incharge.id
     LEFT JOIN users createdby ON fp.created_by = createdby.id
    LEFT JOIN forma f ON jtd.forma_id = f.id
    WHERE 1=1
";

// Add search conditions
$conditions = "";
$bind_params = [];

if (!empty($search_params['name'])) {
    $conditions .= " AND fp.name LIKE :name";
    $bind_params[':name'] = '%' . $search_params['name'] . '%';
}

if (!empty($search_params['jt_code'])) {
    $conditions .= " AND jt.job_ticket_code LIKE :jt_code";
    $bind_params[':jt_code'] = '%' . $search_params['jt_code'] . '%';
}

if (!empty($search_params['book_code'])) {
    $conditions .= " AND b.book_code LIKE :book_code";
    $bind_params[':book_code'] = '%' . $search_params['book_code'] . '%';
}

if (!empty($search_params['class_level'])) {
    $conditions .= " AND b.class_level = :class_level";
    $bind_params[':class_level'] = $search_params['class_level'];
}

if (!empty($search_params['machine_id'])) {
    $conditions .= " AND fp.machine_id = :machine_id";
    $bind_params[':machine_id'] = $search_params['machine_id'];
}

if ($search_params['status'] !== '') {
    $conditions .= " AND fp.status = :status";
    $bind_params[':status'] = ($search_params['status'] === '1');
}

if (!empty($search_params['forma_status'])) {
    $conditions .= " AND f.status = :forma_status";
    $bind_params[':forma_status'] = $search_params['forma_status'];
}

if (!empty($search_params['jtd_status'])) {
    $conditions .= " AND jtd.status = :jtd_status";
    $bind_params[':jtd_status'] = $search_params['jtd_status'];
}

if (!empty($search_params['fiscal_year_id'])) {
    $conditions .= " AND fp.fiscal_year_id = :fiscal_year_id";
    $bind_params[':fiscal_year_id'] = $search_params['fiscal_year_id'];
}

if (!empty($search_params['supervisor_id'])) {
    $conditions .= " AND fp.supervisor_id = :supervisor_id";
    $bind_params[':supervisor_id'] = $search_params['supervisor_id'];
}

if (!empty($search_params['operator_id'])) {
    $conditions .= " AND fp.operator_id = :operator_id";
    $bind_params[':operator_id'] = $search_params['operator_id'];
}

if (!empty($search_params['incharge_id'])) {
    $conditions .= " AND fp.incharge_id = :incharge_id";
    $bind_params[':incharge_id'] = $search_params['incharge_id'];
}

if (!empty($search_params['forma_name'])) {
    $conditions .= " AND f.name LIKE :forma_name";
    $bind_params[':forma_name'] = '%' . $search_params['forma_name'] . '%';
}

if (!empty($search_params['lot'])) {
    $conditions .= " AND jt.lot LIKE :lot";
    $bind_params[':lot'] = '%' . $search_params['lot'] . '%';
}

if (!empty($search_params['start_date']) && !empty($search_params['end_date'])) {
    $conditions .= " AND fp.date_nep BETWEEN :start_date AND :end_date";
    $bind_params[':start_date'] = $search_params['start_date'];
    $bind_params[':end_date'] = $search_params['end_date'];
}

// Final queries
$count_query = "SELECT COUNT(*) as total " . $base_query . $conditions;
$query = "
    SELECT 
        ROW_NUMBER() OVER (ORDER BY fp.created_date DESC) + :offset as sn,
        fp.*,
        jt.job_ticket_code,
        jt.lot,
        b.book_code,
        b.class_level,
        m.machine_name,
        fy.fiscal_code,
        createdby.username as createdby_name,
        supervisor.username as supervisor_name,
        operator.username as operator_name,
        incharge.username as incharge_name,
        f.name as forma_name,
        f.status as forma_status,
        jtd.print_qty as jtd_targetqty, 
        jtd.page as jtd_page,
        jtd.status as jtd_status,
        jtd.old_forma_qty as forma_done_qty,
        fp.fp_printqty,
        fp.fp_remainqty
    " . $base_query . $conditions . "
    ORDER BY fp.created_date DESC 
    LIMIT :limit OFFSET :offset
";

// Get total records
$count_stmt = $conn->prepare($count_query);
foreach ($bind_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Fetch records
$stmt = $conn->prepare($query);
foreach ($bind_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$forma_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_target = array_sum(array_column($forma_records, 'jtd_targetqty'));
$total_printed = array_sum(array_column($forma_records, 'fp_printqty'));
$total_remaining = array_sum(array_column($forma_records, 'fp_remainqty'));
$total_forma_done = array_sum(array_column($forma_records, 'forma_done_qty'));

// Fetch dropdown data
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code")->fetchAll(PDO::FETCH_ASSOC);
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name")->fetchAll(PDO::FETCH_ASSOC);

$supervisors = $conn->query("SELECT id, username FROM users WHERE role IN ('supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$operators = $conn->query("SELECT id, username FROM users WHERE role = 'operator' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$incharges = $conn->query("SELECT id, username FROM users WHERE role IN ('incharge', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$class_levels = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);
$books = $conn->query("SELECT book_id, book_code FROM books WHERE book_code IS NOT NULL ORDER BY book_code")->fetchAll(PDO::FETCH_ASSOC);
$job_tickets = $conn->query("SELECT id, job_ticket_code FROM job_ticket ORDER BY job_ticket_code")->fetchAll(PDO::FETCH_ASSOC);
$forma_names = $conn->query("SELECT DISTINCT name FROM forma WHERE name IS NOT NULL ORDER BY name")->fetchAll(PDO::FETCH_COLUMN);

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    // Use same query without LIMIT/OFFSET for export
    $export_query = str_replace("LIMIT :limit OFFSET :offset", "", $query);
    $export_stmt = $conn->prepare($export_query);
    foreach ($bind_params as $key => $value) {
        $export_stmt->bindValue($key, $value);
    }
    $export_stmt->execute();
    $export_records = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="forma_printing_detailed_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<table border="1">';
    echo '<tr style="background-color:#4CAF50;color:white;">
        <th>S.N</th>
        <th>Name</th>
        <th>Job Ticket Code</th>
        <th>Book Code</th>
        <th>Class</th>
        <th>Fiscal Year</th>
        <th>Machine</th>
        <th>Forma Name</th>
        <th>Target Qty (JTD)</th>
        <th>Printed Qty</th>
        <th>Remaining Qty</th>
        <th>Page</th>
        <th>Forma Done Qty</th>
        <th>Lot</th>
        <th>Supervisor</th>
        <th>Operator</th>
        <th>Incharge</th>
        <th>FP Status</th>
        <th>Forma Status</th>
        <th>JTD Status</th>
        <th>Date (Nepali)</th>
        <th>Created Date</th>
    </tr>';

    foreach ($export_records as $record) {
        echo '<tr>';
        echo '<td>' . $record['sn'] . '</td>';
        echo '<td>' . htmlspecialchars($record['name']) . '</td>';
        echo '<td>' . htmlspecialchars($record['job_ticket_code']) . '</td>';
        echo '<td>' . htmlspecialchars($record['book_code']) . '</td>';
        echo '<td>' . htmlspecialchars($record['class_level']) . '</td>';
        echo '<td>' . htmlspecialchars($record['fiscal_code']) . '</td>';
        echo '<td>' . htmlspecialchars($record['machine_name']) . '</td>';
        echo '<td>' . htmlspecialchars($record['forma_name']) . '</td>';
        echo '<td>' . number_format($record['jtd_targetqty']) . '</td>';
        echo '<td>' . number_format($record['fp_printqty']) . '</td>';
        echo '<td>' . number_format($record['fp_remainqty']) . '</td>';
        echo '<td>' . htmlspecialchars($record['jtd_page']) . '</td>';
        echo '<td>' . number_format($record['forma_done_qty']) . '</td>';
        echo '<td>' . htmlspecialchars($record['lot']) . '</td>';
        echo '<td>' . htmlspecialchars($record['supervisor_name']) . '</td>';
        echo '<td>' . htmlspecialchars($record['operator_name']) . '</td>';
        echo '<td>' . htmlspecialchars($record['incharge_name']) . '</td>';
        echo '<td>' . ($record['status'] ? 'Active' : 'Inactive') . '</td>';
        echo '<td>' . htmlspecialchars($record['forma_status']) . '</td>';
        echo '<td>' . htmlspecialchars($record['jtd_status']) . '</td>';
        echo '<td>' . htmlspecialchars($record['date_nep']) . '</td>';
        echo '<td>' . date('Y-m-d H:i', strtotime($record['created_date'])) . '</td>';
        echo '</tr>';
    }

    // Calculate totals for export
    $exp_total_target = array_sum(array_column($export_records, 'jtd_targetqty'));
    $exp_total_printed = array_sum(array_column($export_records, 'fp_printqty'));
    $exp_total_remaining = array_sum(array_column($export_records, 'fp_remainqty'));
    $exp_total_forma_done = array_sum(array_column($export_records, 'forma_done_qty'));

    echo '<tr style="font-weight:bold;background-color:#f8f9fa;">
        <td colspan="8"><strong>GRAND TOTALS</strong></td>
        <td><strong>' . number_format($exp_total_target) . '</strong></td>
        <td><strong>' . number_format($exp_total_printed) . '</strong></td>
        <td><strong>' . number_format($exp_total_remaining) . '</strong></td>
        <td></td>
        <td><strong>' . number_format($exp_total_forma_done) . '</strong></td>
        <td colspan="9"></td>
    </tr>';

    echo '</table>';
    exit();
}
?>

<style>
 body {
    font-size: 14px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 20px;
    background-color: #f8f9fa;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    background: white;
    padding: 20px;
    border-radius: 8px;
}

    h2 {
        margin: 20px 15px;
        color: #333;
        font-size: 28px;
        font-weight: 600;
    }

    .action-buttons {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 0 15px 20px;
        flex-wrap: wrap;
        gap: 10px;
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
    }

    .pagination-info {
        color: #666;
        font-size: 0.95em;
        font-weight: 500;
    }

    .records-per-page {
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .records-per-page select {
        padding: 5px 10px;
        border: 1px solid #ddd;
        border-radius: 4px;
        font-size: 14px;
    }

    .search-container {
        background: #f8f9fa;
        padding: 25px;
        border-radius: 10px;
        margin: 0 15px 25px;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .search-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .search-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .search-group label {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 6px;
        display: block;
        color: #495057;
    }

    .search-control {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 14px;
        box-sizing: border-box;
        transition: border-color 0.3s ease;
    }

    .search-control:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,.1);
    }

    .btn {
        padding: 10px 18px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
        transition: all 0.3s ease;
        text-align: center;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .btn-primary { background: #007bff; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-info { background: #17a2b8; color: white; }
    .btn-warning { background: #ffc107; color: #212529; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }

    .table-responsive {
        margin: 0 15px;
        overflow-x: auto;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }

    .table {
        width: 100%;
        border-collapse: collapse;
        font-size: 13px;
        min-width: 1400px;
    }

    .table th, .table td {
        padding: 12px 8px;
        text-align: left;
        border-bottom: 1px solid #dee2e6;
        vertical-align: middle;
    }

    .table th {
        background-color: #343a40;
        color: white;
        font-weight: 600;
        position: sticky;
        top: 0;
        z-index: 10;
        text-align: center;
        font-size: 12px;
        white-space: nowrap;
    }

    .table td {
        font-size: 12px;
    }

    .table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
        text-transform: uppercase;
    }

    .badge-active { background: #28a745; color: white; }
    .badge-inactive { background: #dc3545; color: white; }
    .badge-completed { background: #007bff; color: white; }
    .badge-pending { background: #ffc107; color: #212529; }
    .badge-processing { background: #17a2b8; color: white; }

    .totals-row {
        font-weight: bold;
        background-color: #e9ecef !important;
        border-top: 3px solid #007bff;
    }

    .totals-row td {
        padding: 15px 8px;
        font-size: 14px;
        font-weight: 700;
    }

    .pagination-container {
        margin: 25px 15px;
        text-align: center;
    }

    .pagination {
        display: inline-flex;
        list-style: none;
        padding: 0;
        margin: 0;
        border-radius: 6px;
        overflow: hidden;
        box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    }

    .pagination .page-item.disabled .page-link {
        color: #6c757d;
        pointer-events: none;
        background-color: #f8f9fa;
    }

    .pagination .page-link {
        padding: 12px 16px;
        margin: 0;
        border: 1px solid #dee2e6;
        color: #007bff;
        text-decoration: none;
        background-color: white;
        font-weight: 500;
    }

    .pagination .page-link:hover {
        background-color: #e9ecef;
        border-color: #adb5bd;
    }

    .pagination .page-item.active .page-link {
        background: #007bff;
        color: white;
        border-color: #007bff;
    }

    .search-stats {
        background: white;
        padding: 15px;
        border-radius: 8px;
        margin: 0 15px 20px;
        border-left: 4px solid #007bff;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .stats-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
    }

    .stat-item {
        text-align: center;
    }

    .stat-number {
        font-size: 24px;
        font-weight: 700;
        color: #007bff;
        display: block;
    }

    .stat-label {
        font-size: 12px;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 500;
    }

    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }

        .search-row {
            grid-template-columns: 1fr;
        }

        .action-buttons {
            flex-direction: column;
            align-items: stretch;
        }

        .stats-grid {
            grid-template-columns: repeat(2, 1fr);
            gap: 10px;
        }

        .table th, .table td {
            padding: 8px 4px;
            font-size: 11px;
        }
    }

    @media print {
        .no-print, .pagination-container, .search-container, .action-buttons {
            display: none !important;
        }
        .container {
            padding: 0;
        }
        .table th, .table td {
            padding: 4px 2px;
            font-size: 9pt;
            border: 1px solid #000;
        }
        .table {
            font-size: 8pt;
            min-width: auto;
        }
        body {
            font-size: 9pt;
        }
        .totals-row {
            background-color: #f0f0f0 !important;
        }
    }

    .numeric {
        text-align: right;
        font-weight: 500;
    }

    .center {
        text-align: center;
    }
</style>

<div class="container">
    <h2>📋 Forma Printing Records - Comprehensive View</h2>

    <div class="action-buttons">
        <div>
            <?php if (has_role('supervisor') || has_role('incharge')  || has_role('operator') || has_role('admin')): ?>
                <a href="create.php" class="btn btn-primary">
                    <i class="fas fa-plus-circle"></i> Create New
                </a>
            <?php endif; ?>
        </div>
        
        <div class="records-per-page">
            <label for="per_page">Records per page:</label>
            <select name="per_page" id="per_page" onchange="changePerPage(this.value)">
                <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
                <option value="200" <?= $records_per_page == 200 ? 'selected' : '' ?>>200</option>
            </select>
        </div>
        
        <div class="pagination-info">
            Showing <?= count($forma_records) ?> of <?= number_format($total_records) ?> records
            <?php if ($total_pages > 1): ?>
                (Page <?= $page ?> of <?= $total_pages ?>)
            <?php endif; ?>
        </div>
    </div>

    <!-- Summary Stats -->
    <?php if (count($forma_records) > 0): ?>
    <div class="search-stats">
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-number"><?= number_format($total_target) ?></span>
                <span class="stat-label">Page Target Total</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= number_format($total_printed) ?></span>
                <span class="stat-label">Page Printed Total</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= number_format($total_remaining) ?></span>
                <span class="stat-label">Page Remaining Total</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= number_format($total_forma_done) ?></span>
                <span class="stat-label">Page Forma Done Total</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Enhanced Search Form -->
    <div class="search-container">
        <div class="search-title">
            <i class="fas fa-search"></i> Advanced Search Filters
        </div>
        <form method="get" id="searchForm">
            <input type="hidden" name="page" value="1">
            <input type="hidden" name="per_page" value="<?= $records_per_page ?>">

            <!-- Row 1: Basic Info -->
            <div class="search-row">
                <div class="search-group">
                    <label for="name">FP Name</label>
                    <input type="text" name="name" id="name" class="search-control"
                           value="<?= htmlspecialchars($search_params['name']) ?>"
                           placeholder="Search by FP name...">
                </div>

                <div class="search-group">
                    <label for="jt_code">Job Ticket Code</label>
                    <input type="text" name="jt_code" id="jt_code" class="search-control"
                           value="<?= htmlspecialchars($search_params['jt_code']) ?>"
                           placeholder="Search job ticket code...">
                </div>

                <div class="search-group">
                    <label for="book_code">Book Code</label>
                    <input type="text" name="book_code" id="book_code" class="search-control"
                           value="<?= htmlspecialchars($search_params['book_code']) ?>"
                           placeholder="Search book code...">
                </div>

                <div class="search-group">
                    <label for="forma_name">Forma Name</label>
                    <input type="text" name="forma_name" id="forma_name" class="search-control"
                           value="<?= htmlspecialchars($search_params['forma_name']) ?>"
                           placeholder="Search forma name...">
                </div>
            </div>

            <!-- Row 2: Classification -->
            <div class="search-row">
                <div class="search-group">
                    <label for="class_level">Class Level</label>
                    <select name="class_level" id="class_level" class="search-control">
                        <option value="">All Classes</option>
                        <?php foreach ($class_levels as $class): ?>
                            <option value="<?= htmlspecialchars($class) ?>" <?= $search_params['class_level'] == $class ? 'selected' : '' ?>>
                                Class <?= htmlspecialchars($class) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="search-group">
                    <label for="machine_id">Machine</label>
                    <select name="machine_id" id="machine_id" class="search-control">
                        <option value="">All Machines</option>
                        <?php foreach ($machines as $machine): ?>
                            <option value="<?= $machine['id'] ?>" <?= $search_params['machine_id'] == $machine['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($machine['machine_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-group">
                <label for="lot">Lot Number</label>
                <input type="text" name="lot" id="lot" class="search-control"
                       value="<?= htmlspecialchars($search_params['lot']) ?>"
                       placeholder="Search lot number...">
            </div>

            <div class="search-group">
                <label for="fiscal_year_id">Fiscal Year</label>
                <select name="fiscal_year_id" id="fiscal_year_id" class="search-control">
                    <?php foreach ($fiscal_years as $fy): ?>
                        <option value="<?= $fy['id'] ?>" <?= $search_params['fiscal_year_id'] == $fy['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fy['fiscal_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Row 3: Personnel -->
        <div class="search-row">
            <div class="search-group">
                <label for="supervisor_id">Supervisor</label>
                <select name="supervisor_id" id="supervisor_id" class="search-control">
                    <option value="">All Supervisors</option>
                    <?php foreach ($supervisors as $supervisor): ?>
                        <option value="<?= $supervisor['id'] ?>" <?= $search_params['supervisor_id'] == $supervisor['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($supervisor['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-group">
                <label for="operator_id">Operator</label>
                <select name="operator_id" id="operator_id" class="search-control">
                    <option value="">All Operators</option>
                    <?php foreach ($operators as $operator): ?>
                        <option value="<?= $operator['id'] ?>" <?= $search_params['operator_id'] == $operator['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($operator['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-group">
                <label for="incharge_id">Incharge</label>
                <select name="incharge_id" id="incharge_id" class="search-control">
                    <option value="">All Incharges</option>
                    <?php foreach ($incharges as $incharge): ?>
                        <option value="<?= $incharge['id'] ?>" <?= $search_params['incharge_id'] == $incharge['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($incharge['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <div class="search-group">
                <label for="status">FP Status</label>
                <select name="status" id="status" class="search-control">
                    <option value="">All FP Status</option>
                    <option value="1" <?= $search_params['status'] === '1' ? 'selected' : '' ?>>Active</option>
                    <option value="0" <?= $search_params['status'] === '0' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
        </div>

        <!-- Row 4: Status & Dates -->
        <div class="search-row">
            <div class="search-group">
                <label for="forma_status">Forma Status</label>
                <select name="forma_status" id="forma_status" class="search-control">
                    <option value="">All Forma Status</option>
                    <option value="active" <?= $search_params['forma_status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $search_params['forma_status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="pending" <?= $search_params['forma_status'] === 'pending' ? 'selected' : '' ?>>Pending</option>
                </select>
            </div>

            <div class="search-group">
                <label for="jtd_status">JTD Status</label>
                <select name="jtd_status" id="jtd_status" class="search-control">
                    <option value="">All JTD Status</option>
                    <option value="active" <?= $search_params['jtd_status'] === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="completed" <?= $search_params['jtd_status'] === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="processing" <?= $search_params['jtd_status'] === 'processing' ? 'selected' : '' ?>>Processing</option>
                </select>
            </div>

            <div class="search-group">
                <label for="start_date">Date From (Nepali)</label>
                <input type="text" name="start_date" id="start_date" class="search-control"
                       value="<?= htmlspecialchars($search_params['start_date']) ?>"
                       placeholder="2080.04.01">
            </div>

            <div class="search-group">
                <label for="end_date">Date To (Nepali)</label>
                <input type="text" name="end_date" id="end_date" class="search-control"
                       value="<?= htmlspecialchars($search_params['end_date']) ?>"
                       placeholder="2081.03.32">
            </div>
        </div>

        <!-- Action Buttons -->
        <div class="search-row">
            <div class="search-group" style="align-self: flex-end;">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Search
                </button>
                <a href="?" class="btn btn-secondary">
                    <i class="fas fa-sync-alt"></i> Reset
                </a>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-success">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <button type="button" onclick="window.print()" class="btn btn-info">
                    <i class="fas fa-print"></i> Print
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Enhanced Table with All Required Fields -->
<div class="table-responsive">
    <table class="table">
        <thead>
        <tr>
            <th width="40">S.N</th>
            <th width="120">FP Name</th>
            <th width="100">Job Ticket</th>
            <th width="100">Book Code</th>
            <th width="60">Class</th>
            <th width="80">Fiscal Year</th>
            <th width="100">Machine</th>
            <th width="120">Forma Name</th>
            <th width="80">Target Qty</th>
            <th width="80">Printed Qty</th>
            <th width="80">Remaining</th>
            <th width="50">Page</th>
            <th width="80">Forma Done</th>
            <th width="80">Lot</th>
              <th width="100">CreatedBy</th>
            <th width="100">Supervisor</th>
            <th width="100">Operator</th>
            <th width="100">Incharge</th>
            <th width="70">FP Status</th>
            <th width="80">Forma Status</th>
            <th width="80">JTD Status</th>
            <th width="100">Date (Nepali)</th>
            <th width="120" class="no-print">Actions</th>
        </tr>
        </thead>
        <tbody>
        <?php if (count($forma_records) > 0): ?>
            <?php foreach ($forma_records as $record): ?>
                <tr>
                    <td class="center"><?= $record['sn'] ?></td>
                    <td><?= htmlspecialchars($record['name']) ?></td>
                    <td><?= htmlspecialchars($record['job_ticket_code']) ?></td>
                    <td><?= htmlspecialchars($record['book_code']) ?></td>
                    <td class="center"><?= htmlspecialchars($record['class_level']) ?></td>
                    <td><?= htmlspecialchars($record['fiscal_code']) ?></td>
                    <td><?= htmlspecialchars($record['machine_name']) ?></td>
                    <td><?= htmlspecialchars($record['forma_name']) ?></td>
                    <td class="numeric"><?= number_format($record['jtd_targetqty']) ?></td>
                    <td class="numeric"><?= number_format($record['fp_printqty']) ?></td>
                    <td class="numeric"><?= number_format($record['fp_remainqty']) ?></td>
                    <td class="center"><?= htmlspecialchars($record['jtd_page']) ?></td>
                    <td class="numeric"><?= number_format($record['forma_done_qty']) ?></td>
                    <td><?= htmlspecialchars($record['lot']) ?></td>
                        <td><?= htmlspecialchars($record['createdby_name']) ?></td>
                    <td><?= htmlspecialchars($record['supervisor_name']) ?></td>
                    <td><?= htmlspecialchars($record['operator_name']) ?></td>
                    <td><?= htmlspecialchars($record['incharge_name']) ?></td>
                    <td class="center">
                        <span class="badge <?= $record['status'] ? 'badge-active' : 'badge-inactive' ?>">
                            <?= $record['status'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td class="center">
                        <span class="badge 
                            <?= $record['forma_status'] == 'completed' ? 'badge-completed' : 
                               ($record['forma_status'] == 'processing' ? 'badge-processing' : 'badge-pending') ?>">
                            <?= htmlspecialchars($record['forma_status']) ?>
                        </span>
                    </td>
                    <td class="center">
                        <span class="badge 
                            <?= $record['jtd_status'] == 'completed' ? 'badge-completed' : 
                               ($record['jtd_status'] == 'processing' ? 'badge-processing' : 'badge-pending') ?>">
                            <?= htmlspecialchars($record['jtd_status']) ?>
                        </span>
                    </td>
                    <td class="center"><?= htmlspecialchars($record['date_nep']) ?></td>
                    <td class="no-print center">
                        <a href="view.php?id=<?= $record['id'] ?>" class="btn btn-primary btn-sm" title="View Details">
                           view
                        </a>
                          <a href="print.php?id=<?= $record['id'] ?>" class="btn btn-secondary btn-sm" title="View Details">
                           print
                        </a>
                        <?php if (has_role('supervisor') ||has_role('incharge')  || has_role('admin')): ?>
                            <a href="edit.php?id=<?= $record['id'] ?>" class="btn btn-warning btn-sm" title="Edit Record">
                          edit
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
            <?php endforeach; ?>

            <!-- Enhanced Totals Row -->
            <tr class="totals-row">
                <td colspan="8"><strong>PAGE TOTALS</strong></td>
                <td class="numeric"><strong><?= number_format($total_target) ?></strong></td>
                <td class="numeric"><strong><?= number_format($total_printed) ?></strong></td>
                <td class="numeric"><strong><?= number_format($total_remaining) ?></strong></td>
                <td></td>
                <td class="numeric"><strong><?= number_format($total_forma_done) ?></strong></td>
                <td colspan="9"></td>
            </tr>
        <?php else: ?>
            <tr>
                <td colspan="22" class="center" style="padding: 50px; color: #666;">
                    <i class="fas fa-search" style="font-size: 48px; margin-bottom: 20px; display: block;"></i>
                    No records found matching your search criteria.<br>
                    <small>Try adjusting your filters or search terms.</small>
                </td>
            </tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>

<!-- Enhanced Pagination -->
<?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <ul class="pagination">
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">
                    <i class="fas fa-angle-double-left"></i> First
                </a>
            </li>
            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                    <i class="fas fa-angle-left"></i> Previous
                </a>
            </li>

            <?php
            $start = max(1, $page - 2);
            $end = min($total_pages, $page + 2);
            
            // Show first page if we're not close to it
            if ($start > 3) {
                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => 1])) . '">1</a></li>';
                if ($start > 4) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
            }
            
            for ($i = $start; $i <= $end; $i++):
            ?>
                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; 
            
            // Show last page if we're not close to it
            if ($end < $total_pages - 2) {
                if ($end < $total_pages - 3) {
                    echo '<li class="page-item disabled"><span class="page-link">...</span></li>';
                }
                echo '<li class="page-item"><a class="page-link" href="?' . http_build_query(array_merge($_GET, ['page' => $total_pages])) . '">' . $total_pages . '</a></li>';
            }
            ?>

            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                    Next <i class="fas fa-angle-right"></i>
                </a>
            </li>
            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                    Last <i class="fas fa-angle-double-right"></i>
                </a>
            </li>
        </ul>
    </div>
<?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    // Enhanced Date formatting helper
    ['start_date', 'end_date'].forEach(id => {
        const el = document.getElementById(id);
        if (el) {
            el.addEventListener('input', function (e) {
                let v = e.target.value.replace(/\D/g, '');
                if (v.length > 4) v = v.slice(0, 4) + '.' + v.slice(4);
                if (v.length > 7) v = v.slice(0, 7) + '.' + v.slice(7, 9);
                e.target.value = v.slice(0, 10);
            });
            
            // Add placeholder behavior
            el.addEventListener('focus', function() {
                if (this.value === '') this.placeholder = 'YYYY.MM.DD';
            });
        }
    });

    // Quick date buttons with enhanced functionality
    const dateEnd = document.getElementById('end_date');
    if (dateEnd && !document.querySelector('.quick-date-container')) {
        const quickDiv = document.createElement('div');
        quickDiv.className = 'quick-date-container';
        quickDiv.style.cssText = 'margin-top: 10px; display: flex; gap: 5px; flex-wrap: wrap;';
        quickDiv.innerHTML = `
            <small style="color:#6c757d; align-self: center; margin-right: 10px;">Quick Select:</small>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setDateRange('2081.04.01','2082.03.32')">Current FY</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setDateRange('2080.04.01','2081.03.32')">Previous FY</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setDateRange('${getCurrentNepaliMonth()}.01','${getCurrentNepaliMonth()}.32')">This Month</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setDateRange('${getLastWeekStart()}','${getLastWeekEnd()}')">Last 7 Days</button>
        `;
        dateEnd.parentNode.appendChild(quickDiv);
    }

    // Enhanced search with auto-submit on certain fields
    const autoSubmitFields = ['fiscal_year_id', 'machine_id', 'supervisor_id', 'operator_id', 'incharge_id', 'status', 'forma_status', 'jtd_status'];
    autoSubmitFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.addEventListener('change', function() {
                // Small delay to allow user to see the change
                setTimeout(() => {
                    document.getElementById('searchForm').submit();
                }, 100);
            });
        }
    });

    // Enhanced search functionality with debouncing
    const searchFields = ['name', 'jt_code', 'book_code', 'forma_name', 'lot'];
    searchFields.forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            let timeout;
            field.addEventListener('input', function() {
                clearTimeout(timeout);
                timeout = setTimeout(() => {
                    // Auto-search after 1 second of no typing
                    if (this.value.length >= 3 || this.value.length === 0) {
                        document.getElementById('searchForm').submit();
                    }
                }, 1000);
            });
        }
    });

    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+F to focus on first search field
        if (e.ctrlKey && e.key === 'f') {
            e.preventDefault();
            document.getElementById('name').focus();
        }
        // Ctrl+R to reset form
        if (e.ctrlKey && e.key === 'r') {
            e.preventDefault();
            window.location.href = '?';
        }
        // Ctrl+P to print
        if (e.ctrlKey && e.key === 'p') {
            e.preventDefault();
            window.print();
        }
    });
});

// Helper functions
function getCurrentNepaliMonth() {
    // This should be replaced with actual Nepali date calculation
    return '2081.05'; // Example current month
}

function getLastWeekStart() {
    return '2081.05.15'; // Example
}

function getLastWeekEnd() {
    return '2081.05.22'; // Example
}

function setDateRange(start, end) {
    document.getElementById('start_date').value = start;
    document.getElementById('end_date').value = end;
    document.getElementById('searchForm').submit();
}

function changePerPage(value) {
    const url = new URL(window.location);
    url.searchParams.set('per_page', value);
    url.searchParams.set('page', '1'); // Reset to first page
    window.location.href = url.toString();
}

// Export functions
function exportToExcel() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'excel');
    window.location.href = '?' + params.toString();
}

// Print optimization
function optimizedPrint() {
    // Hide non-essential columns for better print layout
    const table = document.querySelector('.table');
    const actionColumns = table.querySelectorAll('.no-print');
    
    window.print();
}

// Form validation
function validateForm() {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    if (startDate && endDate) {
        if (startDate > endDate) {
            alert('Start date cannot be greater than end date');
            return false;
        }
    }
    return true;
}

// Initialize form validation
document.getElementById('searchForm').addEventListener('submit', function(e) {
    if (!validateForm()) {
        e.preventDefault();
    }
});

// Status filter quick buttons
function addStatusQuickFilters() {
    const statusField = document.getElementById('status');
    if (statusField && !statusField.parentNode.querySelector('.status-quick-filters')) {
        const quickDiv = document.createElement('div');
        quickDiv.className = 'status-quick-filters';
        quickDiv.style.cssText = 'margin-top: 5px; display: flex; gap: 5px;';
        quickDiv.innerHTML = `
            <button type="button" class="btn btn-sm btn-outline-success" onclick="setStatus('1')">Active Only</button>
            <button type="button" class="btn btn-sm btn-outline-danger" onclick="setStatus('0')">Inactive Only</button>
            <button type="button" class="btn btn-sm btn-outline-secondary" onclick="setStatus('')">All Status</button>
        `;
        statusField.parentNode.appendChild(quickDiv);
    }
}

function setStatus(status) {
    document.getElementById('status').value = status;
    document.getElementById('searchForm').submit();
}

// Initialize quick filters
setTimeout(addStatusQuickFilters, 100);

// Enhanced table interactions
document.querySelectorAll('.table tbody tr').forEach(row => {
    if (!row.classList.contains('totals-row')) {
        row.addEventListener('dblclick', function() {
            const viewLink = this.querySelector('a[href*="view"]');
            if (viewLink) {
                window.location.href = viewLink.href;
            }
        });
        
        // Add hover effect with row highlighting
        row.addEventListener('mouseenter', function() {
            this.style.backgroundColor = '#f8f9fa';
        });
        
        row.addEventListener('mouseleave', function() {
            this.style.backgroundColor = '';
        });
    }
});

// Add tooltips for truncated text
document.querySelectorAll('.table td').forEach(td => {
    if (td.scrollWidth > td.clientWidth) {
        td.title = td.textContent.trim();
    }
});

console.log('Enhanced Forma Printing Index loaded successfully');
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
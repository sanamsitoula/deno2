<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Permission check
if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// ================== Export Handling ==================
if (isset($_GET['export'])) {
    require_once 'export_handler.php';
    handleEmployeeExport($conn, $_GET);
    exit();
}

// ================== Filters ==================
$search = trim($_GET['search'] ?? '');
$dept_filter = $_GET['department_id'] ?? '';
$desig_filter = $_GET['designation_id'] ?? '';
$level_filter = $_GET['level_id'] ?? '';
$status_filter = $_GET['emp_status'] ?? '';
$type_filter = $_GET['emp_type'] ?? '';
$technical_filter = $_GET['is_technical'] ?? '';
$state_filter = $_GET['state'] ?? '';
$fiscal_year_filter = $_GET['fiscal_year_id'] ?? '';

// Pagination
$page = max(1, (int)($_GET['page'] ?? 1));
$limit = 50;
$offset = ($page - 1) * $limit;

// ================== Build Query ==================
$sql = "
    SELECT 
        e.id, e.code, e.name, e.name_eng, e.name_nep,
        e.mobile_number, e.email, e.emp_status, e.emp_type,
        e.is_technical, e.state, e.local_body, e.ward_no,
        e.pan_no, e.bank_name, e.card_id,
        d.name AS designation_name,
        l.name AS level_name,
        dep.name AS department_name,
        dep.sub_department_name,
        fy.fiscal_code,
        e.picture, e.join_date, e.join_date_nep
    FROM employee e
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN level l ON e.level_id = l.id
    LEFT JOIN department dep ON e.department_id = dep.id
    LEFT JOIN fiscal_years fy ON e.fiscal_year_id = fy.id
    WHERE e.deleted_date IS NULL
";

$params = [];

if ($search) {
    $sql .= " AND (
        e.code ILIKE :search OR 
        e.name ILIKE :search OR 
        e.name_eng ILIKE :search OR 
        e.name_nep ILIKE :search OR 
        e.email ILIKE :search OR 
        e.mobile_number ILIKE :search OR
        e.pan_no ILIKE :search OR
        e.card_id ILIKE :search
    )";
    $params[':search'] = "%$search%";
}

if ($dept_filter !== '' && is_numeric($dept_filter)) {
    $sql .= " AND e.department_id = :department_id";
    $params[':department_id'] = (int)$dept_filter;
}

if ($desig_filter !== '' && is_numeric($desig_filter)) {
    $sql .= " AND e.designation_id = :designation_id";
    $params[':designation_id'] = (int)$desig_filter;
}

if ($level_filter !== '' && is_numeric($level_filter)) {
    $sql .= " AND e.level_id = :level_id";
    $params[':level_id'] = (int)$level_filter;
}

if ($status_filter !== '') {
    $sql .= " AND e.emp_status = :emp_status";
    $params[':emp_status'] = $status_filter;
}

if ($type_filter !== '') {
    $sql .= " AND e.emp_type = :emp_type";
    $params[':emp_type'] = $type_filter;
}

if ($technical_filter !== '') {
    $sql .= " AND e.is_technical = :is_technical";
    $params[':is_technical'] = $technical_filter === '1' ? true : false;
}

if ($state_filter !== '') {
    $sql .= " AND e.state ILIKE :state";
    $params[':state'] = "%$state_filter%";
}

if ($fiscal_year_filter !== '' && is_numeric($fiscal_year_filter)) {
    $sql .= " AND e.fiscal_year_id = :fiscal_year_id";
    $params[':fiscal_year_id'] = (int)$fiscal_year_filter;
}

$sql .= " ORDER BY e.code, e.name LIMIT :limit OFFSET :offset";

// ================== Count Total Records ==================
$count_sql = str_replace('SELECT', 'SELECT COUNT(*) as total FROM (SELECT', 
    str_replace('ORDER BY e.code, e.name LIMIT :limit OFFSET :offset', '', $sql)) . ') as count_table';

$count_stmt = $conn->prepare($count_sql);
foreach ($params as $key => $val) {
    $count_stmt->bindValue($key, $val);
}
$count_stmt->execute();
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// ================== Fetch Employees ==================
$stmt = $conn->prepare($sql);
foreach ($params as $key => $val) {
    $stmt->bindValue($key, $val);
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================== Fetch Filter Options ==================
$departments = $conn->query("
    SELECT id, CONCAT(COALESCE(sub_department_name, ''), 
           CASE WHEN sub_department_name IS NOT NULL THEN ' / ' ELSE '' END, 
           name) AS name 
    FROM department 
    WHERE status = true 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$designations = $conn->query("
    SELECT id, name 
    FROM designation 
    WHERE status = true 
    ORDER BY name
")->fetchAll(PDO::FETCH_ASSOC);

$levels = $conn->query("
    SELECT id, name 
    FROM level 
    WHERE status = true 
    ORDER BY display_order DESC
")->fetchAll(PDO::FETCH_ASSOC);

$fiscal_years = $conn->query("
    SELECT id, fiscal_code 
    FROM fiscal_years 
    ORDER BY start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$states = $conn->query("
    SELECT DISTINCT state 
    FROM employee 
    WHERE state IS NOT NULL AND state != '' AND deleted_date IS NULL
    ORDER BY state
")->fetchAll(PDO::FETCH_COLUMN);

// ================== Dashboard Stats ==================
$stats_query = "
    SELECT 
        COUNT(*) FILTER (WHERE emp_status = 'ACTIVE') as active,
        COUNT(*) FILTER (WHERE emp_status = 'INACTIVE') as inactive,
        COUNT(*) FILTER (WHERE emp_status = 'RETIRED') as retired,
        COUNT(*) FILTER (WHERE emp_status = 'DRAFT') as draft,
        COUNT(*) FILTER (WHERE emp_type = 'PERMANENT') as permanent,
        COUNT(*) FILTER (WHERE emp_type = 'CONTRACT') as contract,
        COUNT(*) FILTER (WHERE emp_type = 'DAILY_WAGES') as daily_wages,
        COUNT(*) FILTER (WHERE is_technical = TRUE) as technical,
        COUNT(*) as total
    FROM employee 
    WHERE deleted_date IS NULL
";
$stats = $conn->query($stats_query)->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Directory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .stats-card {
            border-left: 4px solid #0d6efd;
            transition: transform 0.2s;
        }
        .stats-card:hover {
            transform: translateY(-5px);
            box-shadow: 0 4px 8px rgba(0,0,0,0.1);
        }
        .status-ACTIVE { background-color: #28a745; color: white; }
        .status-INACTIVE { background-color: #6c757d; color: white; }
        .status-RETIRED { background-color: #17a2b8; color: white; }
        .status-DRAFT { background-color: #ffc107; color: black; }
        .status-TERMINATED { background-color: #dc3545; color: white; }
        .filter-section {
            background: #f8f9fa;
            padding: 1rem;
            border-radius: 0.5rem;
            margin-bottom: 1rem;
        }
        .table img {
            width: 40px;
            height: 40px;
            object-fit: cover;
        }
        .tech-badge {
            background: #17a2b8;
            color: white;
            padding: 0.25rem 0.5rem;
            border-radius: 0.25rem;
            font-size: 0.75rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <!-- Header -->
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-users"></i> Employee Directory</h2>
                <p class="text-muted mb-0">Manage and view employee records</p>
            </div>
            <div>
                <a href="import_enhanced.php" class="btn btn-info">
                    <i class="fas fa-file-import"></i> Bulk Import
                </a>
                <a href="create_enhanced.php" class="btn btn-primary">
                    <i class="fas fa-plus"></i> Add Employee
                </a>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <!-- Statistics Dashboard -->
        <div class="row mb-4">
            <div class="col-md-3">
                <div class="card stats-card">
                    <div class="card-body">
                        <h6 class="text-muted">Total Employees</h6>
                        <h3 class="mb-0"><?= number_format($stats['total']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stats-card" style="border-left-color: #28a745;">
                    <div class="card-body">
                        <h6 class="text-muted">Active</h6>
                        <h3 class="mb-0 text-success"><?= number_format($stats['active']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stats-card" style="border-left-color: #ffc107;">
                    <div class="card-body">
                        <h6 class="text-muted">Draft</h6>
                        <h3 class="mb-0 text-warning"><?= number_format($stats['draft']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-2">
                <div class="card stats-card" style="border-left-color: #17a2b8;">
                    <div class="card-body">
                        <h6 class="text-muted">Permanent</h6>
                        <h3 class="mb-0 text-info"><?= number_format($stats['permanent']) ?></h3>
                    </div>
                </div>
            </div>
            <div class="col-md-3">
                <div class="card stats-card" style="border-left-color: #6f42c1;">
                    <div class="card-body">
                        <h6 class="text-muted">Technical Staff</h6>
                        <h3 class="mb-0" style="color: #6f42c1;"><?= number_format($stats['technical']) ?></h3>
                    </div>
                </div>
            </div>
        </div>

        <!-- Advanced Filters -->
        <div class="filter-section">
            <form method="GET" class="row g-2">
                <div class="col-md-3">
                    <input type="text" name="search" class="form-control" 
                           placeholder="Search (code, name, email, PAN, card)" 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                <div class="col-md-2">
                    <select name="department_id" class="form-select">
                        <option value="">All Departments</option>
                        <?php foreach ($departments as $dept): ?>
                            <option value="<?= $dept['id'] ?>" <?= $dept_filter == $dept['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($dept['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="designation_id" class="form-select">
                        <option value="">All Designations</option>
                        <?php foreach ($designations as $desig): ?>
                            <option value="<?= $desig['id'] ?>" <?= $desig_filter == $desig['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($desig['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <select name="level_id" class="form-select">
                        <option value="">Level</option>
                        <?php foreach ($levels as $level): ?>
                            <option value="<?= $level['id'] ?>" <?= $level_filter == $level['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($level['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <select name="emp_status" class="form-select">
                        <option value="">Status</option>
                        <option value="ACTIVE" <?= $status_filter == 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                        <option value="INACTIVE" <?= $status_filter == 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                        <option value="RETIRED" <?= $status_filter == 'RETIRED' ? 'selected' : '' ?>>Retired</option>
                        <option value="DRAFT" <?= $status_filter == 'DRAFT' ? 'selected' : '' ?>>Draft</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <select name="emp_type" class="form-select">
                        <option value="">Type</option>
                        <option value="PERMANENT" <?= $type_filter == 'PERMANENT' ? 'selected' : '' ?>>Permanent</option>
                        <option value="CONTRACT" <?= $type_filter == 'CONTRACT' ? 'selected' : '' ?>>Contract</option>
                        <option value="DAILY_WAGES" <?= $type_filter == 'DAILY_WAGES' ? 'selected' : '' ?>>Daily Wages</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <select name="is_technical" class="form-select">
                        <option value="">All Staff</option>
                        <option value="1" <?= $technical_filter === '1' ? 'selected' : '' ?>>Technical</option>
                        <option value="0" <?= $technical_filter === '0' ? 'selected' : '' ?>>Non-Tech</option>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-filter"></i> Filter
                    </button>
                </div>
            </form>
            
            <!-- Second row of filters -->
            <form method="GET" class="row g-2 mt-2">
                <?php foreach ($_GET as $key => $value): ?>
                    <?php if (!in_array($key, ['state', 'fiscal_year_id'])): ?>
                        <input type="hidden" name="<?= htmlspecialchars($key) ?>" value="<?= htmlspecialchars($value) ?>">
                    <?php endif; ?>
                <?php endforeach; ?>
                
                <div class="col-md-2">
                    <select name="state" class="form-select">
                        <option value="">All States</option>
                        <?php foreach ($states as $state): ?>
                            <option value="<?= htmlspecialchars($state) ?>" <?= $state_filter == $state ? 'selected' : '' ?>>
                                <?= htmlspecialchars($state) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <select name="fiscal_year_id" class="form-select">
                        <option value="">All Fiscal Years</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-1">
                    <button type="submit" class="btn btn-secondary w-100">
                        <i class="fas fa-search"></i>
                    </button>
                </div>
                <div class="col-md-1">
                    <a href="index_enhanced.php" class="btn btn-outline-secondary w-100">
                        <i class="fas fa-redo"></i> Reset
                    </a>
                </div>
            </form>
        </div>

        <!-- Export & Actions -->
        <div class="d-flex justify-content-between mb-3">
            <div>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => '1', 'format' => 'excel'])) ?>" class="btn btn-success btn-sm">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => '1', 'format' => 'pdf'])) ?>" class="btn btn-danger btn-sm" target="_blank">
                    <i class="fas fa-file-pdf"></i> Export PDF
                </a>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => '1', 'format' => 'print'])) ?>" class="btn btn-secondary btn-sm" target="_blank">
                    <i class="fas fa-print"></i> Print
                </a>
            </div>
            <div>
                <small class="text-muted">
                    Showing <?= min($offset + 1, $total_records) ?> to <?= min($offset + $limit, $total_records) ?> 
                    of <?= number_format($total_records) ?> employees
                </small>
            </div>
        </div>

        <!-- Employee Table -->
        <div class="card shadow">
            <div class="card-body p-0">
                <?php if (empty($employees)): ?>
                    <div class="alert alert-info m-3">
                        <i class="fas fa-info-circle"></i> No employees found matching your criteria.
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover table-sm align-middle mb-0">
                            <thead class="table-light sticky-top">
                                <tr>
                                    <th width="50">Photo</th>
                                    <th>Code</th>
                                    <th>Name (Eng / Nep)</th>
                                    <th>Designation</th>
                                    <th>Department</th>
                                    <th>Level</th>
                                    <th>Contact</th>
                                    <th>Location</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>FY</th>
                                    <th width="100">Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $emp): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($emp['picture'])): ?>
                                                <img src="/deno2/<?= htmlspecialchars($emp['picture']) ?>" 
                                                     alt="Profile" class="rounded-circle">
                                            <?php else: ?>
                                                <img src="https://ui-avatars.com/api/?name=<?= urlencode($emp['name']) ?>&size=40" 
                                                     alt="Avatar" class="rounded-circle">
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <strong><?= htmlspecialchars($emp['code']) ?></strong>
                                            <?php if ($emp['is_technical']): ?>
                                                <br><span class="tech-badge"><i class="fas fa-cog"></i> Tech</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?= htmlspecialchars($emp['name_eng'] ?? $emp['name']) ?>
                                            <?php if ($emp['name_nep']): ?>
                                                <br><small class="text-muted"><?= htmlspecialchars($emp['name_nep']) ?></small>
                                            <?php endif; ?>
                                            <?php if ($emp['card_id']): ?>
                                                <br><small><i class="fas fa-id-card"></i> <?= htmlspecialchars($emp['card_id']) ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td><?= htmlspecialchars($emp['designation_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <?php if ($emp['sub_department_name']): ?>
                                                <small><?= htmlspecialchars($emp['sub_department_name']) ?> /</small><br>
                                            <?php endif; ?>
                                            <?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?>
                                        </td>
                                        <td><?= htmlspecialchars($emp['level_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <small>
                                                <?php if ($emp['mobile_number']): ?>
                                                    <i class="fas fa-phone"></i> <?= htmlspecialchars($emp['mobile_number']) ?><br>
                                                <?php endif; ?>
                                                <?php if ($emp['email']): ?>
                                                    <i class="fas fa-envelope"></i> <?= htmlspecialchars($emp['email']) ?>
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small>
                                                <?php if ($emp['state']): ?>
                                                    <?= htmlspecialchars($emp['state']) ?>
                                                    <?php if ($emp['local_body']): ?>
                                                        , <?= htmlspecialchars($emp['local_body']) ?>
                                                    <?php endif; ?>
                                                    <?php if ($emp['ward_no']): ?>
                                                        <br>Ward: <?= htmlspecialchars($emp['ward_no']) ?>
                                                    <?php endif; ?>
                                                <?php else: ?>
                                                    N/A
                                                <?php endif; ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge status-<?= $emp['emp_status'] ?>">
                                                <?= htmlspecialchars($emp['emp_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <small class="badge bg-secondary">
                                                <?= $emp['emp_type'] == 'DAILY_WAGES' ? 'DW' : substr($emp['emp_type'], 0, 4) ?>
                                            </small>
                                        </td>
                                        <td>
                                            <small><?= htmlspecialchars($emp['fiscal_code'] ?? 'N/A') ?></small>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm">
                                                <a href="view.php?id=<?= $emp['id'] ?>" 
                                                   class="btn btn-outline-info" title="View">
                                                    <i class="fas fa-eye"></i>
                                                </a>
                                                <a href="edit.php?id=<?= $emp['id'] ?>" 
                                                   class="btn btn-outline-primary" title="Edit">
                                                    <i class="fas fa-edit"></i>
                                                </a>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <?php if ($total_pages > 1): ?>
                        <nav class="mt-3 mb-3">
                            <ul class="pagination pagination-sm justify-content-center mb-0">
                                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">
                                        <i class="fas fa-chevron-left"></i> Previous
                                    </a>
                                </li>
                                
                                <?php
                                $start_page = max(1, $page - 2);
                                $end_page = min($total_pages, $page + 2);
                                
                                if ($start_page > 1): ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">1</a>
                                    </li>
                                    <?php if ($start_page > 2): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                <?php endif; ?>
                                
                                <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                                    <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>">
                                            <?= $i ?>
                                        </a>
                                    </li>
                                <?php endfor; ?>
                                
                                <?php if ($end_page < $total_pages): ?>
                                    <?php if ($end_page < $total_pages - 1): ?>
                                        <li class="page-item disabled"><span class="page-link">...</span></li>
                                    <?php endif; ?>
                                    <li class="page-item">
                                        <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">
                                            <?= $total_pages ?>
                                        </a>
                                    </li>
                                <?php endif; ?>
                                
                                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">
                                        Next <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            </ul>
                        </nav>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

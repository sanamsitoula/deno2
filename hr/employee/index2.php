<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/functions.php'; // Contains handleExport()
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Permission check
if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// ================== Export Handling ==================
if (isset($_GET['export'])) {
    handleExport($conn);
    exit();
}

// ================== Filters ==================
$search = trim($_GET['search'] ?? '');
$dept_filter = $_GET['department_id'] ?? '';
$desig_filter = $_GET['designation_id'] ?? '';
$level_filter = $_GET['level_id'] ?? '';
$status_filter = $_GET['emp_status'] ?? '';
$type_filter = $_GET['emp_type'] ?? '';

// Pagination
$page = (int)($_GET['page'] ?? 1);
$page = $page < 1 ? 1 : $page;
$limit = 50;
$offset = ($page - 1) * $limit;

// ================== Build Query ==================
$sql = "
    SELECT 
        e.id, e.code, e.name, e.mobile_number, e.email, e.emp_status, e.emp_type,
        d.name AS designation_name,
        l.name AS level_name,
        dep.name AS department_name,
        e.picture
    FROM employee e
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN level l ON e.level_id = l.id
    LEFT JOIN department dep ON e.department_id = dep.id
    WHERE 1=1
";

$params = [];

if ($search) {
    $sql .= " AND (e.code ILIKE :search OR e.name ILIKE :search OR e.email ILIKE :search OR e.mobile_number ILIKE :search)";
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

$sql .= " ORDER BY e.name LIMIT :limit OFFSET :offset";

// ================== Count Total Records ==================
$count_sql = "
    SELECT COUNT(*) FROM employee e
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN level l ON e.level_id = l.id
    LEFT JOIN department dep ON e.department_id = dep.id
    WHERE 1=1
";

$count_params = [];

if ($search) {
    $count_sql .= " AND (e.code ILIKE :search OR e.name ILIKE :search OR e.email ILIKE :search OR e.mobile_number ILIKE :search)";
    $count_params[':search'] = "%$search%";
}
if ($dept_filter !== '' && is_numeric($dept_filter)) {
    $count_sql .= " AND e.department_id = :department_id";
    $count_params[':department_id'] = (int)$dept_filter;
}
if ($desig_filter !== '' && is_numeric($desig_filter)) {
    $count_sql .= " AND e.designation_id = :designation_id";
    $count_params[':designation_id'] = (int)$desig_filter;
}
if ($level_filter !== '' && is_numeric($level_filter)) {
    $count_sql .= " AND e.level_id = :level_id";
    $count_params[':level_id'] = (int)$level_filter;
}
if ($status_filter !== '') {
    $count_sql .= " AND e.emp_status = :emp_status";
    $count_params[':emp_status'] = $status_filter;
}
if ($type_filter !== '') {
    $count_sql .= " AND e.emp_type = :emp_type";
    $count_params[':emp_type'] = $type_filter;
}

$total_stmt = $conn->prepare($count_sql);
$total_stmt->execute($count_params);
$total_records = $total_stmt->fetchColumn();
$total_pages = ceil($total_records / $limit);

// ================== Fetch Employees ==================
$stmt = $conn->prepare($sql);
foreach ($params as $key => $val) {
    if ($key === ':limit' || $key === ':offset') {
        $stmt->bindValue($key, $val, PDO::PARAM_INT);
    } else {
        $stmt->bindValue($key, $val);
    }
}
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ================== Fetch Filter Options ==================
$departments = $conn->query("SELECT id, CONCAT(COALESCE(sub_department_name, ''), '/', name) AS name FROM department WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$designations = $conn->query("SELECT id, name FROM designation WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $conn->query("SELECT id, name FROM level WHERE status = true ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);

// ================== Dashboard Stats ==================
$stats = [
    'active'      => $conn->query("SELECT COUNT(*) FROM employee WHERE emp_status = 'ACTIVE'")->fetchColumn(),
    'inactive'    => $conn->query("SELECT COUNT(*) FROM employee WHERE emp_status = 'INACTIVE'")->fetchColumn(),
    'retired'     => $conn->query("SELECT COUNT(*) FROM employee WHERE emp_status = 'RETIRED'")->fetchColumn(),
    'permanent'   => $conn->query("SELECT COUNT(*) FROM employee WHERE emp_type = 'PERMANENT'")->fetchColumn(),
    'contract'    => $conn->query("SELECT COUNT(*) FROM employee WHERE emp_type = 'CONTRACT'")->fetchColumn(),
    'temporary'   => $conn->query("SELECT COUNT(*) FROM employee WHERE emp_type = 'TEMPORARY'")->fetchColumn(),
    'departments' => count($departments),
    'total'       => $total_records
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Employee Directory</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .dashboard-card {
            border-radius: 12px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            transition: transform 0.2s;
        }
        .dashboard-card:hover { transform: translateY(-3px); }
        .card-icon {
            font-size: 1.8rem;
            opacity: 0.2;
        }
        .status-badge {
            font-size: 0.8em;
            padding: 0.3em 0.6em;
            border-radius: 50px;
        }
        .status-ACTIVE   { background: #d4edda; color: #155724; }
        .status-INACTIVE { background: #f8d7da; color: #721c24; }
        .status-RETIRED  { background: #d1ecf1; color: #0c5460; }
        .type-PERMANENT  { background: #fff3cd; color: #856404; }
        .type-CONTRACT   { background: #d1ecf1; color: #0c5460; }
        .type-TEMPORARY  { background: #f8d7da; color: #721c24; }
        .table img {
            width: 40px;
            height: 40px;
            border-radius: 50%;
            object-fit: cover;
        }
        .pagination .page-link {
            color: #0d6efd;
        }
        .pagination .active .page-link {
            background-color: #0d6efd;
            border-color: #0d6efd;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <h2><i class="fas fa-users"></i> Employee Directory</h2>

        <!-- Dashboard -->
        <div class="row mt-4 g-3">
            <div class="col-md-2 col-sm-6">
                <div class="card bg-primary text-white dashboard-card">
                    <div class="card-body d-flex align-items-center">
                        <i class="fas fa-user-check card-icon me-3"></i>
                        <div>
                            <h5 class="mb-0"><?= $stats['active'] ?></h5>
                            <small>Active</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card bg-danger text-white dashboard-card">
                    <div class="card-body d-flex align-items-center">
                        <i class="fas fa-user-slash card-icon me-3"></i>
                        <div>
                            <h5 class="mb-0"><?= $stats['inactive'] ?></h5>
                            <small>Inactive</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card bg-info text-white dashboard-card">
                    <div class="card-body d-flex align-items-center">
                        <i class="fas fa-umbrella-beach card-icon me-3"></i>
                        <div>
                            <h5 class="mb-0"><?= $stats['retired'] ?></h5>
                            <small>Retired</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card bg-success text-white dashboard-card">
                    <div class="card-body d-flex align-items-center">
                        <i class="fas fa-suitcase card-icon me-3"></i>
                        <div>
                            <h5 class="mb-0"><?= $stats['permanent'] ?></h5>
                            <small>Permanent</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card bg-warning text-dark dashboard-card">
                    <div class="card-body d-flex align-items-center">
                        <i class="fas fa-handshake card-icon me-3"></i>
                        <div>
                            <h5 class="mb-0"><?= $stats['contract'] ?></h5>
                            <small>Contract</small>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-2 col-sm-6">
                <div class="card bg-secondary text-white dashboard-card">
                    <div class="card-body d-flex align-items-center">
                        <i class="fas fa-building card-icon me-3"></i>
                        <div>
                            <h5 class="mb-0"><?= $stats['departments'] ?></h5>
                            <small>Departments</small>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Filters -->
        <div class="card mt-4 shadow-sm">
            <div class="card-body">
                <form method="GET" class="row g-3">
                    <div class="col-md-3">
                        <input type="text" name="search" class="form-control" placeholder="Search by name, code, email..." value="<?= htmlspecialchars($search) ?>">
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
                    <div class="col-md-2">
                        <select name="level_id" class="form-select">
                            <option value="">All Levels</option>
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
                        </select>
                    </div>
                    <div class="col-md-1">
                        <select name="emp_type" class="form-select">
                            <option value="">Type</option>
                            <option value="PERMANENT" <?= $type_filter == 'PERMANENT' ? 'selected' : '' ?>>Permanent</option>
                            <option value="CONTRACT" <?= $type_filter == 'CONTRACT' ? 'selected' : '' ?>>Contract</option>
                            <option value="TEMPORARY" <?= $type_filter == 'TEMPORARY' ? 'selected' : '' ?>>Temporary</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-grid">
                        <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i></button>
                    </div>
                </form>
            </div>
        </div>

        <!-- Export & Add -->
        <div class="d-flex justify-content-between mt-3 mb-3">
            <div>
                <a href="?export=1&format=excel" class="btn btn-success btn-sm me-2">
                    <i class="fas fa-file-excel"></i> Export Excel
                </a>
                <a href="?export=1&format=pdf" class="btn btn-danger btn-sm" target="_blank">
                    <i class="fas fa-file-pdf"></i> Print / PDF
                </a>
            </div>
            <a href="create.php" class="btn btn-primary btn-sm">
                <i class="fas fa-plus"></i> Add Employee
            </a>
        </div>

        <!-- Employee Table -->
        <div class="card shadow">
            <div class="card-body">
                <?php if (empty($employees)): ?>
                    <div class="alert alert-info">No employees found.</div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead class="table-light">
                                <tr>
                                    <th>Photo</th>
                                    <th>Code</th>
                                    <th>Name</th>
                                    <th>Designation</th>
                                    <th>Department</th>
                                    <th>Level</th>
                                    <th>Contact</th>
                                    <th>Status</th>
                                    <th>Type</th>
                                    <th>Actions</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($employees as $emp): ?>
                                    <tr>
                                        <td>
                                            <?php if (!empty($emp['picture'])): ?>
                                                <img src="/deno2/<?= htmlspecialchars($emp['picture']) ?>" alt="Profile" class="rounded-circle">
                                            <?php else: ?>
                                                <img src="https://via.placeholder.com/40" alt="No Image" class="rounded-circle">
                                            <?php endif; ?>
                                        </td>
                                        <td><strong><?= htmlspecialchars($emp['code']) ?></strong></td>
                                        <td><?= htmlspecialchars($emp['name']) ?></td>
                                        <td><?= htmlspecialchars($emp['designation_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?></td>
                                        <td><?= htmlspecialchars($emp['level_name'] ?? 'N/A') ?></td>
                                        <td>
                                            <small>
                                                <?= htmlspecialchars($emp['mobile_number'] ?? 'N/A') ?><br>
                                                <?= htmlspecialchars($emp['email'] ?? 'N/A') ?>
                                            </small>
                                        </td>
                                        <td>
                                            <span class="badge status-<?= $emp['emp_status'] ?>">
                                                <?= htmlspecialchars($emp['emp_status']) ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="badge bg-secondary"><?= htmlspecialchars($emp['emp_type']) ?></span>
                                        </td>
                                        <td>
                                            <a href="view.php?id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-info" title="View">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                            <a href="create.php?id=<?= $emp['id'] ?>" class="btn btn-sm btn-outline-primary" title="Edit">
                                                <i class="fas fa-edit"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <!-- Pagination -->
                    <nav aria-label="Employee pagination">
                        <ul class="pagination justify-content-center">
                            <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page - 1 ?>&search=<?= urlencode($search) ?>&department_id=<?= $dept_filter ?>&designation_id=<?= $desig_filter ?>&level_id=<?= $level_filter ?>&emp_status=<?= $status_filter ?>&emp_type=<?= $type_filter ?>">
                                    Previous
                                </a>
                            </li>
                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?= $i == $page ? 'active' : '' ?>">
                                    <a class="page-link" href="?page=<?= $i ?>&search=<?= urlencode($search) ?>&department_id=<?= $dept_filter ?>&designation_id=<?= $desig_filter ?>&level_id=<?= $level_filter ?>&emp_status=<?= $status_filter ?>&emp_type=<?= $type_filter ?>">
                                        <?= $i ?>
                                    </a>
                                </li>
                            <?php endfor; ?>
                            <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="?page=<?= $page + 1 ?>&search=<?= urlencode($search) ?>&department_id=<?= $dept_filter ?>&designation_id=<?= $desig_filter ?>&level_id=<?= $level_filter ?>&emp_status=<?= $status_filter ?>&emp_type=<?= $type_filter ?>">
                                    Next
                                </a>
                            </li>
                        </ul>
                    </nav>

                    <!-- Record Info -->
                    <div class="text-center text-muted">
                        Showing <?= min($offset + 1, $total_records) ?> to <?= min($offset + $limit, $total_records) ?> of <?= $total_records ?> employees
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
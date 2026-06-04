<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

if (!has_role('admin') && !has_role('hr')) {
    header('Location: /jemc/unauthorized.php'); exit();
}

$msg = ''; $err = '';

// ── CREATE ────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'create') {
    try {
        $stmt = $conn->prepare("
            INSERT INTO department (name, sub_department_name, status, remarks, display_order, is_technical)
            VALUES (:name, :sub, :status, :remarks, :order, :tech)
        ");
        $stmt->execute([
            ':name'    => trim($_POST['name']),
            ':sub'     => trim($_POST['sub_department_name']) ?: null,
            ':status'  => isset($_POST['status']) ? 'true' : 'false',
            ':remarks' => trim($_POST['remarks']) ?: null,
            ':order'   => (int)($_POST['display_order'] ?? 0),
            ':tech'    => isset($_POST['is_technical']) ? 'true' : 'false',
        ]);
        $msg = "Department \"" . htmlspecialchars($_POST['name']) . "\" created successfully.";
    } catch (Exception $e) { $err = $e->getMessage(); }
}

// ── UPDATE ────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'update') {
    try {
        $stmt = $conn->prepare("
            UPDATE department SET
                name                = :name,
                sub_department_name = :sub,
                status              = :status,
                remarks             = :remarks,
                display_order       = :order,
                is_technical        = :tech
            WHERE id = :id
        ");
        $stmt->execute([
            ':id'      => (int)$_POST['id'],
            ':name'    => trim($_POST['name']),
            ':sub'     => trim($_POST['sub_department_name']) ?: null,
            ':status'  => isset($_POST['status']) ? 'true' : 'false',
            ':remarks' => trim($_POST['remarks']) ?: null,
            ':order'   => (int)($_POST['display_order'] ?? 0),
            ':tech'    => isset($_POST['is_technical']) ? 'true' : 'false',
        ]);
        $msg = "Department updated successfully.";
    } catch (Exception $e) { $err = $e->getMessage(); }
}

// ── DELETE ────────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'delete') {
    $dId = (int)$_POST['id'];
    // Check if any employees are assigned
    $empCount = $conn->prepare("SELECT COUNT(*) FROM employee WHERE department_id=:id AND deleted_date IS NULL");
    $empCount->execute([':id' => $dId]);
    $count = (int)$empCount->fetchColumn();
    if ($count > 0) {
        $err = "Cannot delete: $count employee(s) are assigned to this department.";
    } else {
        $conn->prepare("DELETE FROM department WHERE id=:id")->execute([':id' => $dId]);
        $msg = "Department deleted.";
    }
}

// ── TOGGLE STATUS ─────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'toggle') {
    $conn->prepare("UPDATE department SET status = NOT status WHERE id=:id")->execute([':id' => (int)$_POST['id']]);
    $msg = "Status updated.";
}

// ── LOAD DATA ─────────────────────────────────────────────────
$search   = trim($_GET['search'] ?? '');
$techFilter = $_GET['tech'] ?? '';

$sql = "
    SELECT d.*,
           COUNT(e.id) AS emp_count
    FROM department d
    LEFT JOIN employee e ON e.department_id = d.id AND e.emp_status='ACTIVE' AND e.deleted_date IS NULL
    WHERE 1=1
";
$params = [];
if ($search) {
    $sql .= " AND (d.name ILIKE :s OR d.sub_department_name ILIKE :s OR d.remarks ILIKE :s)";
    $params[':s'] = "%$search%";
}
if ($techFilter !== '') {
    $sql .= " AND d.is_technical = :tech";
    $params[':tech'] = $techFilter === '1' ? 'true' : 'false';
}
$sql .= " GROUP BY d.id ORDER BY d.display_order, d.name";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Stats
$stats = $conn->query("
    SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE status=true) AS active,
           COUNT(*) FILTER (WHERE status=false) AS inactive,
           COUNT(*) FILTER (WHERE is_technical=true) AS technical
    FROM department
")->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Department Management — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body { background: #f0f2f8; }
.dept-row:hover { background: #f8f9fa; }
.emp-count-badge { font-size: .65rem; padding: 2px 7px; border-radius: 10px; }
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1300px">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <div>
            <h4 class="fw-bold mb-0" style="color:#2c3e8c">
                <i class="bi bi-diagram-3-fill me-2"></i>Department Management
            </h4>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0" style="font-size:.78rem">
                    <li class="breadcrumb-item"><a href="/jemc/index.php">Dashboard</a></li>
                    <li class="breadcrumb-item"><a href="/jemc/hr/employee/index.php">Employees</a></li>
                    <li class="breadcrumb-item active">Departments</li>
                </ol>
            </nav>
        </div>
        <button class="btn btn-primary" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="resetForm()">
            <i class="fas fa-plus me-1"></i>Add Department
        </button>
    </div>

    <!-- Alerts -->
    <?php if ($msg): ?>
    <div class="alert alert-success alert-dismissible fade show py-2">
        <i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($msg) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>
    <?php if ($err): ?>
    <div class="alert alert-danger alert-dismissible fade show py-2">
        <i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
    </div>
    <?php endif; ?>

    <!-- Stats Row -->
    <div class="row g-3 mb-3">
        <?php foreach ([
            ['Total Departments', $stats['total'],    'primary', 'diagram-3'],
            ['Active',           $stats['active'],   'success', 'check-circle'],
            ['Inactive',         $stats['inactive'], 'secondary','dash-circle'],
            ['Technical',        $stats['technical'],'info',    'cpu'],
        ] as [$label, $val, $color, $icon]): ?>
        <div class="col-6 col-md-3">
            <div class="bg-white rounded-3 shadow-sm p-3 text-center" style="border-top:3px solid var(--bs-<?= $color ?>)">
                <i class="bi bi-<?= $icon ?>" style="font-size:1.4rem;color:var(--bs-<?= $color ?>)"></i>
                <div class="fw-bold fs-4 mt-1" style="color:#2d3436"><?= $val ?></div>
                <div class="text-muted" style="font-size:.72rem"><?= $label ?></div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Filters -->
    <div class="bg-white rounded-3 shadow-sm p-3 mb-3">
        <form method="GET" class="row g-2 align-items-end">
            <div class="col-md-5">
                <label class="form-label small fw-semibold mb-1">Search</label>
                <input type="text" name="search" class="form-control form-control-sm"
                       placeholder="Department name, sub-department, remarks..."
                       value="<?= htmlspecialchars($search) ?>">
            </div>
            <div class="col-md-3">
                <label class="form-label small fw-semibold mb-1">Staff Type</label>
                <select name="tech" class="form-select form-select-sm">
                    <option value="">All Departments</option>
                    <option value="1" <?= $techFilter==='1'?'selected':'' ?>>Technical Only</option>
                    <option value="0" <?= $techFilter==='0'?'selected':'' ?>>Non-Technical Only</option>
                </select>
            </div>
            <div class="col-auto">
                <button type="submit" class="btn btn-primary btn-sm">
                    <i class="fas fa-search me-1"></i>Filter
                </button>
                <a href="index.php" class="btn btn-outline-secondary btn-sm ms-1">Reset</a>
            </div>
            <div class="col-auto ms-auto">
                <small class="text-muted"><?= count($departments) ?> department(s) found</small>
            </div>
        </form>
    </div>

    <!-- Department Table -->
    <div class="bg-white rounded-3 shadow-sm">
        <div class="table-responsive">
            <table class="table table-hover mb-0" style="font-size:.83rem">
                <thead class="table-dark">
                    <tr>
                        <th width="45">#</th>
                        <th>Department Name</th>
                        <th>Sub-Department / Unit</th>
                        <th class="text-center">Employees</th>
                        <th class="text-center">Technical</th>
                        <th class="text-center">Order</th>
                        <th class="text-center">Status</th>
                        <th>Remarks</th>
                        <th width="120" class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($departments)): ?>
                <tr>
                    <td colspan="9" class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                        No departments found.
                        <?php if ($search || $techFilter !== ''): ?>
                        <a href="index.php">Clear filters</a>
                        <?php else: ?>
                        <button class="btn btn-link p-0" data-bs-toggle="modal" data-bs-target="#deptModal" onclick="resetForm()">
                            Add the first department →
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php else: $i = 1; foreach ($departments as $d): ?>
                <tr class="dept-row">
                    <td class="text-muted"><?= $i++ ?></td>
                    <td>
                        <strong><?= htmlspecialchars($d['name']) ?></strong>
                    </td>
                    <td>
                        <?php if ($d['sub_department_name'] && $d['sub_department_name'] !== $d['name']): ?>
                        <span class="text-muted"><?= htmlspecialchars($d['sub_department_name']) ?></span>
                        <?php else: ?>
                        <span class="text-muted">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?php if ($d['emp_count'] > 0): ?>
                        <a href="/jemc/hr/employee/index.php?department_id=<?= $d['id'] ?>"
                           class="badge bg-primary emp-count-badge text-decoration-none">
                            <?= $d['emp_count'] ?> staff
                        </a>
                        <?php else: ?>
                        <span class="badge bg-light text-muted emp-count-badge">0</span>
                        <?php endif; ?>
                    </td>
                    <td class="text-center">
                        <?= $d['is_technical']
                            ? '<span class="badge bg-info text-dark" style="font-size:.62rem"><i class="fas fa-cpu me-1"></i>Technical</span>'
                            : '<span class="text-muted" style="font-size:.78rem">—</span>' ?>
                    </td>
                    <td class="text-center">
                        <span class="badge bg-light text-dark border" style="font-size:.7rem"><?= $d['display_order'] ?></span>
                    </td>
                    <td class="text-center">
                        <form method="POST" style="display:inline">
                            <input type="hidden" name="action" value="toggle">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="badge border-0 <?= $d['status'] ? 'bg-success' : 'bg-secondary' ?>"
                                    style="font-size:.68rem;cursor:pointer"
                                    title="Click to toggle">
                                <?= $d['status'] ? '● Active' : '● Inactive' ?>
                            </button>
                        </form>
                    </td>
                    <td>
                        <small class="text-muted"><?= htmlspecialchars($d['remarks'] ?? '') ?></small>
                    </td>
                    <td class="text-center">
                        <button class="btn btn-xs btn-outline-primary me-1"
                                style="font-size:.7rem;padding:2px 8px"
                                onclick='openEdit(<?= json_encode([
                                    "id"                  => $d["id"],
                                    "name"                => $d["name"],
                                    "sub_department_name" => $d["sub_department_name"] ?? "",
                                    "status"              => $d["status"],
                                    "remarks"             => $d["remarks"] ?? "",
                                    "display_order"       => $d["display_order"],
                                    "is_technical"        => $d["is_technical"],
                                ]) ?>)'>
                            <i class="fas fa-edit"></i> Edit
                        </button>
                        <?php if ((int)$d['emp_count'] === 0): ?>
                        <form method="POST" style="display:inline"
                              onsubmit="return confirm('Delete \"<?= htmlspecialchars(addslashes($d['name'])) ?>\"?')">
                            <input type="hidden" name="action" value="delete">
                            <input type="hidden" name="id" value="<?= $d['id'] ?>">
                            <button type="submit" class="btn btn-xs btn-outline-danger"
                                    style="font-size:.7rem;padding:2px 8px">
                                <i class="fas fa-trash"></i>
                            </button>
                        </form>
                        <?php else: ?>
                        <button class="btn btn-xs btn-outline-secondary" disabled
                                style="font-size:.7rem;padding:2px 8px"
                                title="Cannot delete: has <?= $d['emp_count'] ?> employee(s)">
                            <i class="fas fa-trash"></i>
                        </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; endif; ?>
                </tbody>
            </table>
        </div>
        <!-- Footer -->
        <div class="px-3 py-2 border-top bg-light d-flex justify-content-between align-items-center"
             style="font-size:.76rem">
            <span class="text-muted">
                <?= count($departments) ?> department(s) shown
                <?php if ($search || $techFilter !== ''): ?>
                — <a href="index.php">Clear filters</a>
                <?php endif; ?>
            </span>
            <div class="d-flex gap-2">
                <span class="text-muted"><i class="fas fa-info-circle me-1"></i>
                Click status badge to toggle Active/Inactive. Departments with employees cannot be deleted.
                </span>
            </div>
        </div>
    </div>

</div><!-- /container -->

<!-- ══ ADD/EDIT MODAL ═══════════════════════════════════════════ -->
<div class="modal fade" id="deptModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="modalTitle">
            <i class="fas fa-building me-2"></i>Add Department
        </h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST" id="deptForm">
        <input type="hidden" name="action" id="formAction" value="create">
        <input type="hidden" name="id" id="deptId" value="0">

        <div class="modal-body">
            <div class="row g-3">

                <!-- Department Name -->
                <div class="col-md-12">
                    <label class="form-label fw-semibold">
                        Department Name <span class="text-danger">*</span>
                        <small class="text-muted fw-normal">(Nepali or English)</small>
                    </label>
                    <input type="text" name="name" id="deptName" class="form-control" required
                           placeholder="e.g. उत्पादन विभाग  or  Production Department">
                </div>

                <!-- Sub Department -->
                <div class="col-md-12">
                    <label class="form-label fw-semibold">
                        Sub-Department / Unit Name
                        <small class="text-muted fw-normal">(optional — leave blank if same as above)</small>
                    </label>
                    <input type="text" name="sub_department_name" id="deptSub" class="form-control"
                           placeholder="e.g. प्रमूख, उत्पादन विभाग  or  Head, Production Dept">
                </div>

                <!-- Display Order & Technical -->
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Display Order</label>
                    <input type="number" name="display_order" id="deptOrder" class="form-control"
                           min="0" value="0" placeholder="0">
                    <small class="text-muted">Lower = shown first in lists</small>
                </div>

                <div class="col-md-4 d-flex flex-column justify-content-center">
                    <div class="form-check mt-3">
                        <input type="checkbox" name="is_technical" id="deptTech" class="form-check-input" value="1">
                        <label class="form-check-label fw-semibold" for="deptTech">
                            <i class="fas fa-cpu me-1 text-info"></i>Technical Department
                        </label>
                    </div>
                    <small class="text-muted ms-4">Technical staff flag for payroll</small>
                </div>

                <div class="col-md-4 d-flex flex-column justify-content-center">
                    <div class="form-check mt-3">
                        <input type="checkbox" name="status" id="deptStatus" class="form-check-input" value="1" checked>
                        <label class="form-check-label fw-semibold" for="deptStatus">
                            <i class="fas fa-toggle-on me-1 text-success"></i>Active
                        </label>
                    </div>
                    <small class="text-muted ms-4">Inactive departments hidden from dropdowns</small>
                </div>

                <!-- Remarks -->
                <div class="col-md-12">
                    <label class="form-label fw-semibold">Remarks</label>
                    <input type="text" name="remarks" id="deptRemarks" class="form-control"
                           placeholder="Optional notes about this department">
                </div>

            </div>
        </div>

        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary" id="submitBtn">
                <i class="fas fa-save me-1"></i>Save Department
            </button>
        </div>
    </form>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function resetForm() {
    document.getElementById('formAction').value   = 'create';
    document.getElementById('deptId').value       = '0';
    document.getElementById('deptName').value     = '';
    document.getElementById('deptSub').value      = '';
    document.getElementById('deptOrder').value    = '0';
    document.getElementById('deptRemarks').value  = '';
    document.getElementById('deptTech').checked   = false;
    document.getElementById('deptStatus').checked = true;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-building me-2"></i>Add Department';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Save Department';
}

function openEdit(d) {
    document.getElementById('formAction').value   = 'update';
    document.getElementById('deptId').value       = d.id;
    document.getElementById('deptName').value     = d.name;
    document.getElementById('deptSub').value      = d.sub_department_name || '';
    document.getElementById('deptOrder').value    = d.display_order;
    document.getElementById('deptRemarks').value  = d.remarks || '';
    document.getElementById('deptTech').checked   = !!d.is_technical;
    document.getElementById('deptStatus').checked = !!d.status;
    document.getElementById('modalTitle').innerHTML = '<i class="fas fa-edit me-2"></i>Edit Department';
    document.getElementById('submitBtn').innerHTML = '<i class="fas fa-save me-1"></i>Update Department';
    new bootstrap.Modal(document.getElementById('deptModal')).show();
}

// Auto-fill sub_department_name from name if sub is empty
document.getElementById('deptName').addEventListener('input', function() {
    const sub = document.getElementById('deptSub');
    if (!sub.value || sub.value === sub.dataset.auto) {
        sub.value = this.value;
        sub.dataset.auto = this.value;
    }
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>

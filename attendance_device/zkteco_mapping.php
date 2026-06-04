<?php
/**
 * ZKTeco ↔ JEMC Employee Mapping
 * Match press_jemc employees to ZKTecePuller user_ids.
 * Allows manual mapping where name matching fails.
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/zkteco_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

$msg = ''; $err = '';

// ── Save manual mapping ───────────────────────────────────────
if ($_POST['action'] ?? '' === 'map') {
    try {
        $conn->prepare("UPDATE employee SET attendance_id=:aid WHERE id=:id")
             ->execute([':aid' => trim($_POST['zk_user_id']), ':id' => (int)$_POST['emp_id']]);
        $msg = "Mapping saved.";
    } catch (Exception $e) { $err = $e->getMessage(); }
}

// ── Auto-map by name ─────────────────────────────────────────
if (($_POST['action'] ?? '') === 'auto_map' && $zk_conn) {
    // Get distinct ZKTeco users (latest name per user_id)
    $zkUsers = $zk_conn->query("
        SELECT user_id, MAX(name) AS name FROM employees
        WHERE name IS NOT NULL AND name NOT REGEXP_LIKE(name, '^[0-9]+$')
        GROUP BY user_id
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Fallback if REGEXP_LIKE not available
    if (empty($zkUsers)) {
        $zkUsers = $zk_conn->query("
            SELECT user_id, MAX(name) AS name FROM employees
            WHERE name IS NOT NULL AND name != ''
            GROUP BY user_id
        ")->fetchAll(PDO::FETCH_ASSOC);
    }

    $mapped = 0;
    foreach ($zkUsers as $zk) {
        $normalZk = strtolower(preg_replace('/\s+/', ' ', trim($zk['name'])));
        if (empty($normalZk) || is_numeric($normalZk)) continue;

        // Find matching JEMC employee
        $stmt = $conn->prepare("
            SELECT id FROM employee
            WHERE LOWER(REGEXP_REPLACE(name, '\s+', ' ', 'g')) = :n
              AND deleted_date IS NULL
              AND (attendance_id IS NULL OR attendance_id = '')
            LIMIT 1
        ");
        $stmt->execute([':n' => $normalZk]);
        $emp = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($emp) {
            $conn->prepare("UPDATE employee SET attendance_id=:aid WHERE id=:id")
                 ->execute([':aid' => $zk['user_id'], ':id' => $emp['id']]);
            $mapped++;
        }
    }
    $msg = "Auto-mapped $mapped employees by name.";
}

// ── Clear mapping ─────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'clear') {
    $conn->prepare("UPDATE employee SET attendance_id=NULL WHERE id=:id")
         ->execute([':id' => (int)$_POST['emp_id']]);
    $msg = "Mapping cleared.";
}

// ── Load data ─────────────────────────────────────────────────
// JEMC employees with their mapping status
$jemc = $conn->query("
    SELECT e.id, e.code, e.name, e.emp_type, e.attendance_id, e.card_id,
           dep.name AS dept
    FROM employee e
    LEFT JOIN department dep ON e.department_id=dep.id
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL
    ORDER BY e.name
")->fetchAll(PDO::FETCH_ASSOC);

$mapped_count   = count(array_filter($jemc, fn($e) => !empty($e['attendance_id'])));
$unmapped_count = count($jemc) - $mapped_count;

// ZKTeco unique users
$zkUsers = [];
if ($zk_conn) {
    $zkUsers = $zk_conn->query("
        SELECT user_id, MAX(name) AS name, COUNT(DISTINCT id) AS device_count
        FROM employees
        GROUP BY user_id
        ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Filter
$showFilter = $_GET['filter'] ?? 'all'; // all | mapped | unmapped
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ZKTeco Employee Mapping — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.mapped-row td:first-child { border-left: 3px solid #1a9e5f; }
.unmapped-row td:first-child { border-left: 3px solid #e8a020; }
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1300px">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0" style="color:#2c3e8c">
            <i class="bi bi-fingerprint me-2"></i>ZKTeco ↔ JEMC Employee Mapping
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:.78rem">
                <li class="breadcrumb-item"><a href="/jemc/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="/jemc/attendance_device/zkteco_live.php">ZKTeco Live</a></li>
                <li class="breadcrumb-item active">Employee Mapping</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <?php if ($zk_conn): ?>
        <form method="POST" onsubmit="return confirm('Auto-map all employees by name match?')">
            <input type="hidden" name="action" value="auto_map">
            <button type="submit" class="btn btn-success btn-sm">
                <i class="fas fa-magic me-1"></i>Auto-Map by Name
            </button>
        </form>
        <?php endif; ?>
        <a href="zkteco_live.php" class="btn btn-outline-primary btn-sm">
            <i class="fas fa-arrow-left me-1"></i>Back to Live
        </a>
    </div>
</div>

<?php if ($msg): ?><div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if ($err): ?><div class="alert alert-danger alert-dismissible fade show py-2"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- Stats -->
<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="bg-white rounded-3 shadow-sm p-3 text-center" style="border-top:3px solid #1a9e5f">
            <div class="fw-bold fs-3 text-success"><?= $mapped_count ?></div>
            <div class="text-muted small">Mapped (ZKTeco ID set)</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="bg-white rounded-3 shadow-sm p-3 text-center" style="border-top:3px solid #e8a020">
            <div class="fw-bold fs-3 text-warning"><?= $unmapped_count ?></div>
            <div class="text-muted small">Unmapped (need manual link)</div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="bg-white rounded-3 shadow-sm p-3 text-center" style="border-top:3px solid #2c3e8c">
            <div class="fw-bold fs-3 text-primary"><?= count($zkUsers) ?></div>
            <div class="text-muted small">ZKTeco Users (unique)</div>
        </div>
    </div>
</div>

<div class="alert alert-info py-2" style="font-size:.82rem">
    <i class="fas fa-info-circle me-1"></i>
    <strong>How linking works:</strong> Set the <strong>ZKTeco User ID</strong> on each JEMC employee.
    The sync uses this ID to match punch records. Click <strong>"Auto-Map by Name"</strong> to automatically
    match employees with the same name in both systems. Use manual mapping for any remaining unmatched employees.
</div>

<!-- Filter tabs -->
<div class="d-flex gap-2 mb-2">
    <a href="?filter=all"     class="btn btn-sm <?= $showFilter==='all'?'btn-primary':'btn-outline-secondary' ?>">All (<?= count($jemc) ?>)</a>
    <a href="?filter=mapped"  class="btn btn-sm <?= $showFilter==='mapped'?'btn-success':'btn-outline-success' ?>">Mapped (<?= $mapped_count ?>)</a>
    <a href="?filter=unmapped"class="btn btn-sm <?= $showFilter==='unmapped'?'btn-warning':'btn-outline-warning' ?>">Unmapped (<?= $unmapped_count ?>)</a>
</div>

<!-- Mapping Table -->
<div class="bg-white rounded-3 shadow-sm">
<div class="table-responsive">
<table class="table table-sm table-hover mb-0" style="font-size:.82rem">
    <thead class="table-dark">
        <tr>
            <th>Code</th>
            <th>JEMC Name</th>
            <th>Department</th>
            <th>Type</th>
            <th class="text-center">ZKTeco User ID<br><small class="fw-normal">(attendance_id)</small></th>
            <th>ZKTeco Name (in device)</th>
            <th>Status</th>
            <th width="120">Action</th>
        </tr>
    </thead>
    <tbody>
    <?php foreach ($jemc as $e):
        $isMapped = !empty($e['attendance_id']);
        if ($showFilter === 'mapped'   && !$isMapped) continue;
        if ($showFilter === 'unmapped' && $isMapped)  continue;

        // Find ZKTeco name for this attendance_id
        $zkName = '';
        if ($isMapped) {
            foreach ($zkUsers as $zk) {
                if ($zk['user_id'] == $e['attendance_id']) { $zkName = $zk['name']; break; }
            }
        }
    ?>
    <tr class="<?= $isMapped ? 'mapped-row' : 'unmapped-row' ?>">
        <td><code style="font-size:.72rem"><?= htmlspecialchars($e['code']) ?></code></td>
        <td><strong><?= htmlspecialchars($e['name']) ?></strong></td>
        <td><small class="text-muted"><?= htmlspecialchars($e['dept'] ?? '') ?></small></td>
        <td><span class="badge bg-secondary" style="font-size:.6rem"><?= $e['emp_type'] ?></span></td>
        <td class="text-center">
            <?php if ($isMapped): ?>
                <span class="badge bg-success"><?= htmlspecialchars($e['attendance_id']) ?></span>
            <?php else: ?>
                <span class="text-muted">—</span>
            <?php endif; ?>
        </td>
        <td>
            <?php if ($zkName): ?>
                <span class="text-success"><i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($zkName) ?></span>
            <?php elseif ($isMapped): ?>
                <span class="text-warning"><i class="fas fa-exclamation-circle me-1"></i>ID set but not found in ZKTeco</span>
            <?php else: ?>
                <span class="text-muted">Not linked</span>
            <?php endif; ?>
        </td>
        <td>
            <?= $isMapped
                ? '<span class="badge bg-success" style="font-size:.62rem">✓ Mapped</span>'
                : '<span class="badge bg-warning text-dark" style="font-size:.62rem">⚠ Unmapped</span>' ?>
        </td>
        <td>
            <button class="btn btn-xs btn-outline-primary me-1" style="font-size:.68rem;padding:2px 7px"
                    onclick="openMapModal(<?= $e['id'] ?>, '<?= htmlspecialchars(addslashes($e['name'])) ?>', '<?= htmlspecialchars($e['attendance_id'] ?? '') ?>')">
                <i class="fas fa-link"></i> <?= $isMapped ? 'Change' : 'Map' ?>
            </button>
            <?php if ($isMapped): ?>
            <form method="POST" style="display:inline" onsubmit="return confirm('Clear mapping?')">
                <input type="hidden" name="action" value="clear">
                <input type="hidden" name="emp_id" value="<?= $e['id'] ?>">
                <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.68rem;padding:2px 7px">
                    <i class="fas fa-unlink"></i>
                </button>
            </form>
            <?php endif; ?>
        </td>
    </tr>
    <?php endforeach; ?>
    </tbody>
</table>
</div>
</div>

</div><!-- /container -->

<!-- Map Modal -->
<div class="modal fade" id="mapModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-link me-2"></i>Link ZKTeco User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="map">
        <input type="hidden" name="emp_id" id="mapEmpId">
        <div class="modal-body">
            <p class="mb-1"><strong>JEMC Employee: </strong><span id="mapEmpName" class="text-primary fw-bold"></span></p>
            <hr>
            <div class="mb-3">
                <label class="form-label fw-semibold">Select ZKTeco User <span class="text-danger">*</span></label>
                <input type="text" id="zkSearch" class="form-control form-control-sm mb-2" placeholder="Search by name or user_id...">
                <select name="zk_user_id" id="zkSelect" class="form-select" size="10" required style="font-family:monospace;font-size:.78rem">
                    <?php foreach ($zkUsers as $zk): ?>
                    <option value="<?= htmlspecialchars($zk['user_id']) ?>">
                        ID: <?= str_pad($zk['user_id'],6,' ',STR_PAD_LEFT) ?> | <?= htmlspecialchars($zk['name'] ?: '(no name)') ?>
                        <?= $zk['device_count'] > 1 ? ' ['.$zk['device_count'].' devices]' : '' ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <small class="text-muted"><?= count($zkUsers) ?> ZKTeco users — select one and click Save.</small>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Or enter User ID manually</label>
                <input type="text" id="manualId" class="form-control" placeholder="ZKTeco user_id number"
                       oninput="document.getElementById('zkSelect').value=this.value; this.form.elements['zk_user_id'].value=this.value">
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Mapping</button>
        </div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openMapModal(empId, empName, currentId) {
    document.getElementById('mapEmpId').value   = empId;
    document.getElementById('mapEmpName').textContent = empName;
    document.getElementById('manualId').value   = currentId;
    if (currentId) document.getElementById('zkSelect').value = currentId;
    new bootstrap.Modal(document.getElementById('mapModal')).show();
}

// Search filter for ZKTeco user list
document.getElementById('zkSearch').addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('#zkSelect option').forEach(opt => {
        opt.style.display = opt.text.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Sync select → manual input
document.getElementById('zkSelect').addEventListener('change', function() {
    document.getElementById('manualId').value = this.value;
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>

<?php
/**
 * ZKTeco Device Users Management
 * - View all users enrolled per device
 * - Push new user to device
 * - Delete user from device
 * - Create JEMC employee from ZKTeco user (push to JEMC)
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/zkteco_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

// ── Config ────────────────────────────────────────────────────
define('PYTHON_EXE',    'D:/claude_project/ZKTecePuller/.venv/Scripts/python.exe');
define('DEVICE_MGR',    'D:/claude_project/ZKTecePuller/device_manager.py');
define('ZKTECO_PULLER_DIR', 'D:/claude_project/ZKTecePuller');

$msg = ''; $err = ''; $cmdOutput = '';

// ── Helper: run device_manager.py ─────────────────────────────
function runDeviceCmd(string $cmd): array {
    $fullCmd = '"'.PYTHON_EXE.'" "'.DEVICE_MGR.'" ' . $cmd . ' 2>&1';
    exec($fullCmd, $out, $code);
    $json = implode('', $out);
    $result = json_decode($json, true);
    return $result ?? ['success' => false, 'error' => 'No response: '.$json];
}

// ── Devices from ZKTeco DB ────────────────────────────────────
$devices = [];
if ($zk_conn) {
    $devices = $zk_conn->query("SELECT * FROM devices ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
}

$selectedDeviceId = (int)($_GET['device_id'] ?? ($devices[0]['id'] ?? 0));
$selectedDevice   = null;
foreach ($devices as $d) { if ($d['id'] == $selectedDeviceId) { $selectedDevice = $d; break; } }

// ── POST: Delete user from device ────────────────────────────
if (($_POST['action'] ?? '') === 'delete_from_device' && $selectedDevice) {
    $uid     = (int)$_POST['uid'];
    $userId  = trim($_POST['user_id']);
    $alsoDb  = isset($_POST['also_delete_db']);

    $result = runDeviceCmd(
        "delete_user --device_ip ".escapeshellarg($selectedDevice['ip_address'])
        ." --port ".escapeshellarg($selectedDevice['port'])
        ." --uid ".escapeshellarg($uid)
        ." --user_id ".escapeshellarg($userId)
    );

    if ($result['success']) {
        // Remove from zkteco DB too
        if ($alsoDb && $zk_conn) {
            $zk_conn->prepare("DELETE FROM employees WHERE device_id=:d AND uid=:u")
                    ->execute([':d'=>$selectedDeviceId,':u'=>$uid]);
            $zk_conn->prepare("DELETE FROM attendance_logs WHERE device_id=:d AND uid=:u")
                    ->execute([':d'=>$selectedDeviceId,':u'=>$uid]);
        }
        $msg = "✓ User UID:$uid (ID:$userId) deleted from {$selectedDevice['name']}."
              .($alsoDb ? ' Also removed from ZKTeco DB.' : '');
    } else {
        $err = "Delete failed: " . ($result['error'] ?? 'Unknown error');
    }
}

// ── POST: Create JEMC employee from ZKTeco user ───────────────
if (($_POST['action'] ?? '') === 'create_jemc_employee') {
    try {
        $conn->beginTransaction();

        $zkUserId = trim($_POST['zk_user_id']);
        $name     = trim($_POST['emp_name']);
        $nameNep  = trim($_POST['name_nep'] ?? '');
        $deptId   = (int)($_POST['dept_id'] ?? 0) ?: null;
        $desigId  = (int)($_POST['designation_id'] ?? 0) ?: null;
        $levelId  = (int)($_POST['level_id'] ?? 0) ?: null;
        $empType  = $_POST['emp_type'] ?? 'CONTRACT';
        $fyId     = (int)($_POST['fiscal_year_id'] ?? 0) ?: null;

        // Generate employee code
        $prefix = $empType === 'PERMANENT' ? 'EMP-P' : ($empType === 'DAILY_WAGES' ? 'EMP-DW' : 'EMP-C');
        $lastCode = $conn->prepare("SELECT code FROM employee WHERE code LIKE :p ORDER BY id DESC LIMIT 1");
        $lastCode->execute([':p' => $prefix.'-%']);
        $last = $lastCode->fetchColumn();
        $nextNum = $last ? (int)preg_replace('/\D+/','',$last) + 1 : 1;
        $code = $prefix . '-' . str_pad($nextNum, 4, '0', STR_PAD_LEFT);

        // Insert employee
        $stmt = $conn->prepare("
            INSERT INTO employee (code, name, name_nep, emp_type, emp_status,
                department_id, designation_id, level_id, fiscal_year_id,
                attendance_id, created_by, created_at)
            VALUES (:code,:name,:name_nep,:emp_type,'DRAFT',
                :dept,:desig,:level,:fy,
                :att_id,:user,NOW())
            RETURNING id
        ");
        $stmt->execute([
            ':code'     => $code,
            ':name'     => $name,
            ':name_nep' => $nameNep ?: null,
            ':emp_type' => $empType,
            ':dept'     => $deptId,
            ':desig'    => $desigId,
            ':level'    => $levelId,
            ':fy'       => $fyId,
            ':att_id'   => $zkUserId,
            ':user'     => $_SESSION['user_id'] ?? 0,
        ]);
        $newEmpId = (int)$stmt->fetchColumn();

        $conn->commit();
        $msg = "✓ Employee created: <strong>$code — $name</strong> (ID: $newEmpId). attendance_id set to ZKTeco user_id $zkUserId.";
        $msg .= " <a href='/jemc/hr/employee/view.php?id=$newEmpId' class='alert-link'>View Employee →</a>";
    } catch(Exception $e) {
        $conn->rollBack();
        $err = "Create employee failed: " . $e->getMessage();
    }
}

// ── POST: Push JEMC employee to device ────────────────────────
if (($_POST['action'] ?? '') === 'push_to_device' && $selectedDevice) {
    $empId  = (int)$_POST['emp_id'];
    $emp    = $conn->prepare("SELECT id,code,name,attendance_id FROM employee WHERE id=:id");
    $emp->execute([':id'=>$empId]);
    $emp    = $emp->fetch(PDO::FETCH_ASSOC);

    if (!$emp) { $err = "Employee not found."; }
    elseif (!$emp['attendance_id']) { $err = "Employee has no attendance_id. Set it first."; }
    else {
        // Get next available UID on the device
        $nextUid = $zk_conn->prepare("SELECT COALESCE(MAX(uid),0)+1 FROM employees WHERE device_id=:d");
        $nextUid->execute([':d'=>$selectedDeviceId]);
        $uid = (int)$nextUid->fetchColumn();

        $result = runDeviceCmd(
            "set_user --device_ip ".escapeshellarg($selectedDevice['ip_address'])
            ." --port ".escapeshellarg($selectedDevice['port'])
            ." --uid ".escapeshellarg($uid)
            ." --user_id ".escapeshellarg($emp['attendance_id'])
            ." --name ".escapeshellarg(substr($emp['name'], 0, 24))
        );

        if ($result['success']) {
            // Add to ZKTeco DB
            $zk_conn->prepare("INSERT INTO employees (device_id,uid,user_id,name) VALUES (:d,:u,:ui,:n) ON CONFLICT(device_id,uid) DO NOTHING")
                    ->execute([':d'=>$selectedDeviceId,':u'=>$uid,':ui'=>$emp['attendance_id'],':n'=>$emp['name']]);
            $msg = "✓ Employee {$emp['name']} (UID:$uid) pushed to {$selectedDevice['name']}.";
        } else {
            $err = "Push failed: " . ($result['error'] ?? 'Check device connectivity');
        }
    }
}

// ── Load device users from ZKTeco DB ─────────────────────────
$deviceUsers = [];
$search = trim($_GET['search'] ?? '');
if ($zk_conn && $selectedDevice) {
    $uSql = "
        SELECT e.uid, e.user_id, e.name, e.privilege, e.card,
               e.created_at,
               MAX(al.timestamp) AS last_punch,
               COUNT(al.id)      AS punch_count,
               je.id AS jemc_id, je.code AS jemc_code,
               je.name AS jemc_name,
               d2.name AS jemc_designation
        FROM employees e
        LEFT JOIN attendance_logs al ON al.device_id=e.device_id AND al.uid=e.uid
        LEFT JOIN dblink('dbname=press_jemc user=postgres password=Nepal@123',
            'SELECT id, code, name, attendance_id, designation_id FROM employee WHERE deleted_date IS NULL')
            AS je(id int, code varchar, name varchar, att_id varchar, desig_id int) ON je.att_id = e.user_id
        LEFT JOIN dblink('dbname=press_jemc user=postgres password=Nepal@123',
            'SELECT id, name FROM designation')
            AS d2(did int, dname varchar) ON d2.did = je.desig_id
        WHERE e.device_id = :d
    ";

    // Simpler approach without dblink (use PHP to join)
    $uSql = "
        SELECT e.uid, e.user_id, e.name, e.privilege, e.card, e.created_at,
               MAX(al.timestamp) AS last_punch,
               COUNT(al.id)      AS punch_count
        FROM employees e
        LEFT JOIN attendance_logs al ON al.device_id=e.device_id AND al.uid=e.uid
        WHERE e.device_id = :d
    ";
    $uParams = [':d' => $selectedDeviceId];
    if ($search) {
        $uSql .= " AND (LOWER(e.name) LIKE :s OR e.user_id LIKE :s2)";
        $uParams[':s']  = '%'.strtolower($search).'%';
        $uParams[':s2'] = '%'.$search.'%';
    }
    $uSql .= " GROUP BY e.uid,e.user_id,e.name,e.privilege,e.card,e.created_at ORDER BY e.name";
    $uStmt = $zk_conn->prepare($uSql);
    $uStmt->execute($uParams);
    $deviceUsers = $uStmt->fetchAll(PDO::FETCH_ASSOC);
}

// Build JEMC employee lookup map: attendance_id → employee
$jemcByAttId = [];
$jemcAll = $conn->query("
    SELECT e.id, e.code, e.name, e.attendance_id, e.emp_type,
           d.name AS designation, dep.name AS dept
    FROM employee e
    LEFT JOIN designation d ON e.designation_id=d.id
    LEFT JOIN department dep ON e.department_id=dep.id
    WHERE e.deleted_date IS NULL AND e.attendance_id IS NOT NULL AND e.attendance_id != ''
")->fetchAll(PDO::FETCH_ASSOC);
foreach ($jemcAll as $j) { $jemcByAttId[$j['attendance_id']] = $j; }

// Fetch dropdowns
$departments   = $conn->query("SELECT id,name FROM department WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$designations  = $conn->query("SELECT id,name FROM designation WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels        = $conn->query("SELECT id,name,remarks FROM level WHERE status=true ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);
$fiscalYears   = $conn->query("SELECT id,fiscal_code,is_active FROM fiscal_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// JEMC employees without attendance_id (for push to device)
$jemcUnpushed = $conn->query("
    SELECT e.id, e.code, e.name, e.attendance_id
    FROM employee e
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL
      AND e.attendance_id IS NOT NULL AND e.attendance_id != ''
    ORDER BY e.code
")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ZKTeco Device Users — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8;font-size:.83rem}
.device-tab{cursor:pointer;border-radius:8px!important;margin-bottom:4px;padding:.5rem .8rem;border:1px solid #dee2e6;background:#fff}
.device-tab.active{background:#2c3e8c;color:#fff;border-color:#2c3e8c}
.device-tab.active small{color:#adc8ff!important}
.linked{background:#e8f5e9}
.unlinked{background:#fff8e1}
.no-name{background:#fff5f5;color:#d63031;font-style:italic}
.del-btn{font-size:.68rem;padding:2px 6px}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1600px">

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0" style="color:#2c3e8c">
            <i class="bi bi-hdd-network me-2"></i>ZKTeco Device User Management
        </h4>
        <small class="text-muted">View enrolled users, push to device, delete from device, create JEMC employee</small>
    </div>
    <div class="d-flex gap-2">
        <a href="zkteco_mapping.php" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-diagram-2 me-1"></i>Mapping
        </a>
        <a href="zkteco_live.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-fingerprint me-1"></i>Live Dashboard
        </a>
    </div>
</div>

<?php if($msg): ?><div class="alert alert-success alert-dismissible fade show py-2"><?= $msg ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-danger alert-dismissible fade show py-2"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<?php if(!$zk_conn): ?>
<div class="alert alert-warning">ZKTeco database not connected. Start ZKTecePuller first.</div>
<?php else: ?>

<div class="row g-3">
<!-- Device Selector -->
<div class="col-md-2">
    <div class="bg-white rounded-3 shadow-sm p-3 mb-3">
        <h6 class="fw-bold mb-2"><i class="bi bi-hdd-network me-1"></i>Devices</h6>
        <?php foreach($devices as $d): ?>
        <a href="?device_id=<?= $d['id'] ?>" class="d-block text-decoration-none device-tab <?= $d['id']==$selectedDeviceId?'active':'' ?>">
            <div class="fw-semibold" style="font-size:.82rem"><?= htmlspecialchars($d['name']) ?></div>
            <small style="color:#8492a6"><?= $d['ip_address'].':'.$d['port'] ?></small>
        </a>
        <?php endforeach; ?>
    </div>

    <!-- Push employee to device -->
    <div class="bg-white rounded-3 shadow-sm p-3">
        <h6 class="fw-bold mb-2"><i class="fas fa-upload me-1 text-primary"></i>Push JEMC → Device</h6>
        <p class="text-muted" style="font-size:.72rem">Push a JEMC employee's attendance_id to the selected device.</p>
        <form method="POST">
            <input type="hidden" name="action" value="push_to_device">
            <select name="emp_id" class="form-select form-select-sm mb-2" required>
                <option value="">Select employee...</option>
                <?php foreach($jemcUnpushed as $je): ?>
                <option value="<?= $je['id'] ?>"><?= htmlspecialchars($je['code'].' — '.$je['name']) ?> (ID:<?= $je['attendance_id'] ?>)</option>
                <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm w-100">
                <i class="fas fa-upload me-1"></i>Push to <?= htmlspecialchars($selectedDevice['name'] ?? '') ?>
            </button>
        </form>
    </div>
</div>

<!-- Main: Device Users Table -->
<div class="col-md-10">
    <div class="bg-white rounded-3 shadow-sm">
        <div class="px-3 py-2 border-bottom">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <div>
                    <h6 class="mb-0 fw-bold">
                        <span class="badge bg-primary me-1"><?= htmlspecialchars($selectedDevice['name'] ?? '') ?></span>
                        <?= htmlspecialchars($selectedDevice['ip_address'] ?? '') ?>:<?= $selectedDevice['port'] ?? '' ?>
                        <span class="badge bg-secondary ms-1"><?= count($deviceUsers) ?> users enrolled</span>
                    </h6>
                </div>
                <form method="GET" class="d-flex gap-2">
                    <input type="hidden" name="device_id" value="<?= $selectedDeviceId ?>">
                    <input type="text" name="search" class="form-control form-control-sm"
                           value="<?= htmlspecialchars($search) ?>"
                           placeholder="Search name or user_id...">
                    <button type="submit" class="btn btn-primary btn-sm">Search</button>
                    <?php if($search): ?><a href="?device_id=<?= $selectedDeviceId ?>" class="btn btn-outline-secondary btn-sm">Clear</a><?php endif; ?>
                </form>
            </div>
            <!-- Legend -->
            <div class="d-flex gap-3 mt-1" style="font-size:.7rem">
                <span style="background:#e8f5e9;padding:1px 6px;border-radius:3px">✓ Linked to JEMC</span>
                <span style="background:#fff8e1;padding:1px 6px;border-radius:3px">⚠ Not in JEMC</span>
                <span style="background:#fff5f5;padding:1px 6px;border-radius:3px">⊘ No name</span>
            </div>
        </div>

        <div style="max-height:620px;overflow-y:auto">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
            <thead class="table-dark sticky-top">
                <tr>
                    <th width="50">UID</th>
                    <th width="70">User ID</th>
                    <th>Name in Device</th>
                    <th>JEMC Employee</th>
                    <th>Designation</th>
                    <th>Last Punch</th>
                    <th class="text-center">Punches</th>
                    <th width="180">Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach($deviceUsers as $u):
                $linked = $jemcByAttId[$u['user_id']] ?? null;
                $rowCls = $linked ? 'linked' : (empty(trim($u['name']??'')) || is_numeric(trim($u['name']??'')) ? 'no-name' : 'unlinked');
            ?>
            <tr class="<?= $rowCls ?>">
                <td class="text-center text-muted"><small><?= $u['uid'] ?></small></td>
                <td class="text-center">
                    <code style="font-size:.7rem;color:#d63031"><?= htmlspecialchars($u['user_id']) ?></code>
                </td>
                <td>
                    <?php if(empty(trim($u['name']??'')) || is_numeric(trim($u['name']??''))): ?>
                    <span class="text-danger"><i class="fas fa-exclamation-circle me-1"></i><?= htmlspecialchars($u['name']??'(blank)') ?></span>
                    <?php else: ?>
                    <strong><?= htmlspecialchars($u['name']) ?></strong>
                    <?php endif; ?>
                    <?php if($u['privilege'] > 0): ?>
                    <span class="badge bg-warning text-dark ms-1" style="font-size:.58rem">Admin</span>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($linked): ?>
                    <span class="text-success"><i class="fas fa-check-circle me-1"></i></span>
                    <a href="/jemc/hr/employee/view.php?id=<?= $linked['id'] ?>" class="text-decoration-none" style="font-size:.78rem">
                        <code style="font-size:.7rem"><?= htmlspecialchars($linked['code']) ?></code>
                        <?= htmlspecialchars($linked['name']) ?>
                    </a>
                    <small class="badge bg-secondary ms-1" style="font-size:.58rem"><?= $linked['emp_type'] ?></small>
                    <?php else: ?>
                    <button class="btn btn-xs btn-outline-success" style="font-size:.68rem;padding:1px 7px"
                            onclick="openCreateEmp('<?= htmlspecialchars(addslashes($u['user_id'])) ?>', '<?= htmlspecialchars(addslashes($u['name']??'')) ?>')">
                        <i class="fas fa-user-plus me-1"></i>Create JEMC Employee
                    </button>
                    <?php endif; ?>
                </td>
                <td><small class="text-muted"><?= htmlspecialchars($linked['designation'] ?? '') ?></small></td>
                <td><small class="text-muted"><?= $u['last_punch'] ? date('d M H:i', strtotime($u['last_punch'])) : '—' ?></small></td>
                <td class="text-center"><?= number_format($u['punch_count']) ?></td>
                <td>
                    <button class="btn del-btn btn-outline-danger me-1"
                            onclick="openDeleteModal(<?= $u['uid'] ?>, '<?= htmlspecialchars(addslashes($u['user_id'])) ?>', '<?= htmlspecialchars(addslashes($u['name']??'')) ?>')">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                    <?php if(!$linked): ?>
                    <button class="btn del-btn btn-outline-secondary"
                            onclick="openCreateEmp('<?= htmlspecialchars(addslashes($u['user_id'])) ?>', '<?= htmlspecialchars(addslashes($u['name']??'')) ?>')">
                        <i class="fas fa-plus me-1"></i>Employee
                    </button>
                    <?php endif; ?>
                </td>
            </tr>
            <?php endforeach; ?>
            <?php if(empty($deviceUsers)): ?>
            <tr><td colspan="8" class="text-center py-5 text-muted">No users found<?= $search?" for '$search'":''. ' on this device.' ?></td></tr>
            <?php endif; ?>
            </tbody>
        </table>
        </div>
    </div>
</div>
</div><!-- /row -->
<?php endif; ?>
</div><!-- /container -->

<!-- ══ Delete from Device Modal ═════════════════════════════════ -->
<div class="modal fade" id="deleteModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-danger text-white">
        <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Delete from Device</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="delete_from_device">
        <input type="hidden" name="uid" id="deleteUid">
        <input type="hidden" name="user_id" id="deleteUserId">
        <div class="modal-body">
            <div class="alert alert-warning py-2 mb-3">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong>This will permanently delete the user from the biometric device.</strong><br>
                <small>The user will no longer be able to punch at <strong><?= htmlspecialchars($selectedDevice['name'] ?? '') ?></strong>.</small>
            </div>
            <p><strong>Device:</strong> <?= htmlspecialchars($selectedDevice['name'] ?? '') ?> (<?= $selectedDevice['ip_address'] ?? '' ?>)</p>
            <p><strong>User:</strong> <span id="deleteUserDisplay"></span></p>
            <p><strong>UID:</strong> <span id="deleteUidDisplay"></span> | <strong>User ID:</strong> <span id="deleteUserIdDisplay"></span></p>

            <div class="form-check mt-3">
                <input type="checkbox" name="also_delete_db" id="alsoDeleteDb" class="form-check-input" checked>
                <label class="form-check-label" for="alsoDeleteDb">
                    Also remove from ZKTecePuller database (employees + attendance_logs)
                </label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger">
                <i class="fas fa-trash me-1"></i>Delete from Device
            </button>
        </div>
    </form>
</div></div></div>

<!-- ══ Create JEMC Employee Modal ════════════════════════════════ -->
<div class="modal fade" id="createEmpModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
    <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-user-plus me-2"></i>Create JEMC Employee from ZKTeco User</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="create_jemc_employee">
        <input type="hidden" name="zk_user_id" id="createZkUserId">
        <div class="modal-body">
            <div class="alert alert-info py-2 mb-3">
                <i class="fas fa-info-circle me-1"></i>
                ZKTeco User ID: <code id="createZkUserIdDisplay"></code> — will be set as <code>attendance_id</code>.
                Employee is created with status <strong>DRAFT</strong> — edit to complete the profile.
            </div>
            <div class="row g-3">
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Full Name (English) <span class="text-danger">*</span></label>
                    <input type="text" name="emp_name" id="createEmpName" class="form-control" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Name (Nepali)</label>
                    <input type="text" name="name_nep" class="form-control" placeholder="Optional">
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Employee Type <span class="text-danger">*</span></label>
                    <select name="emp_type" class="form-select" required>
                        <option value="CONTRACT">Contract</option>
                        <option value="PERMANENT">Permanent</option>
                        <option value="DAILY_WAGES">Daily Wages</option>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Department</label>
                    <select name="dept_id" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach($departments as $dep): ?>
                        <option value="<?= $dep['id'] ?>"><?= htmlspecialchars($dep['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Designation</label>
                    <select name="designation_id" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach($designations as $des): ?>
                        <option value="<?= $des['id'] ?>"><?= htmlspecialchars($des['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Level / Grade</label>
                    <select name="level_id" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach($levels as $lv): ?>
                        <option value="<?= $lv['id'] ?>">L<?= $lv['name'] ?> — <?= htmlspecialchars($lv['remarks'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Fiscal Year</label>
                    <select name="fiscal_year_id" class="form-select">
                        <option value="">— Select —</option>
                        <?php foreach($fiscalYears as $fy): ?>
                        <option value="<?= $fy['id'] ?>" <?= $fy['is_active']?'selected':'' ?>><?= $fy['fiscal_code'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success">
                <i class="fas fa-user-plus me-1"></i>Create Employee (DRAFT)
            </button>
        </div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
function openDeleteModal(uid, userId, userName) {
    document.getElementById('deleteUid').value         = uid;
    document.getElementById('deleteUserId').value      = userId;
    document.getElementById('deleteUidDisplay').textContent     = uid;
    document.getElementById('deleteUserIdDisplay').textContent  = userId;
    document.getElementById('deleteUserDisplay').textContent    = userName || '(no name)';
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function openCreateEmp(zkUserId, zkName) {
    document.getElementById('createZkUserId').value         = zkUserId;
    document.getElementById('createZkUserIdDisplay').textContent = zkUserId;
    document.getElementById('createEmpName').value          = zkName;
    new bootstrap.Modal(document.getElementById('createEmpModal')).show();
    setTimeout(() => document.getElementById('createEmpName').select(), 400);
}
</script>
</body>
</html>
<?php ob_end_flush(); ?>

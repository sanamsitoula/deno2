<?php
/**
 * ZKTeco Live Dashboard
 * Reads from ZKTecePuller database (zkteco) AND press_jemc attendance table.
 * Shows pull sessions, raw punch logs, and syncs into press_jemc.attendance.
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/zkteco_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

// ── Sync: ZKTecePuller → press_jemc ──────────────────────────
$syncMsg = ''; $syncDetails = [];
if (isset($_POST['sync']) && $zk_conn) {
    $syncDate = $_POST['sync_date'] ?? date('Y-m-d');
    $synced = $matched_by_id = $matched_by_name = $no_match = $errors = 0;

    $presentStatusId = $conn->query("SELECT id FROM attendance_status WHERE status_code='P' LIMIT 1")->fetchColumn();

    // Get per-person daily summary: first check-in + last check-out across ALL devices
    $punchStmt = $zk_conn->prepare("
        SELECT user_id, MAX(name) AS zk_name,
               MIN(timestamp AT TIME ZONE 'Asia/Kathmandu') AS first_in,
               MAX(timestamp AT TIME ZONE 'Asia/Kathmandu') AS last_out,
               COUNT(*) AS punch_count
        FROM attendance_logs
        WHERE timestamp::date = :d
        GROUP BY user_id
        ORDER BY user_id
    ");
    $punchStmt->execute([':d' => $syncDate]);
    $punches = $punchStmt->fetchAll(PDO::FETCH_ASSOC);

    // Build a name→employee map from press_jemc for fast lookup
    $jemc_emp_rows = $conn->query("
        SELECT id, name, attendance_id, card_id
        FROM employee WHERE deleted_date IS NULL AND emp_status='ACTIVE'
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Index by: attendance_id, card_id, and normalised name
    $byAttId   = []; // attendance_id → jemc employee id
    $byCardId  = []; // card_id → jemc employee id
    $byName    = []; // normalised name → jemc employee id
    foreach ($jemc_emp_rows as $je) {
        if (!empty($je['attendance_id'])) $byAttId[trim($je['attendance_id'])]  = $je['id'];
        if (!empty($je['card_id']))       $byCardId[trim($je['card_id'])]        = $je['id'];
        $normalName = strtolower(preg_replace('/\s+/', ' ', trim($je['name'])));
        $byName[$normalName] = $je['id'];
    }

    foreach ($punches as $punch) {
        $zkUserId  = trim($punch['user_id']);
        $zkName    = trim($punch['zk_name'] ?? '');
        $empId     = null;
        $matchHow  = '';

        // 1. Match by attendance_id (previously mapped)
        if (isset($byAttId[$zkUserId])) {
            $empId    = $byAttId[$zkUserId];
            $matchHow = 'attendance_id';
            $matched_by_id++;
        }
        // 2. Match by card_id
        elseif (isset($byCardId[$zkUserId])) {
            $empId    = $byCardId[$zkUserId];
            $matchHow = 'card_id';
            $matched_by_id++;
        }
        // 3. Match by name (normalised)
        else {
            $normalZkName = strtolower(preg_replace('/\s+/', ' ', $zkName));
            if (!empty($normalZkName) && isset($byName[$normalZkName])) {
                $empId    = $byName[$normalZkName];
                $matchHow = 'name';
                $matched_by_name++;
                // Auto-save attendance_id to avoid re-matching by name next time
                $conn->prepare("UPDATE employee SET attendance_id=:aid WHERE id=:id")
                     ->execute([':aid' => $zkUserId, ':id' => $empId]);
                $byAttId[$zkUserId] = $empId; // update local index
            }
        }

        if (!$empId) { $no_match++; continue; }

        $checkIn  = $punch['first_in']  ? date('H:i:s', strtotime($punch['first_in']))  : null;
        $checkOut = $punch['last_out']   ? date('H:i:s', strtotime($punch['last_out']))  : null;

        try {
            $chk = $conn->prepare("SELECT id FROM attendance WHERE employee_id=:e AND attendance_date_eng=:d");
            $chk->execute([':e' => $empId, ':d' => $syncDate]);
            if ($chk->fetch()) {
                $conn->prepare("UPDATE attendance SET status_id=:s,check_in_time=:i,check_out_time=:o,data_source='ZKTECO',updated_at=NOW() WHERE employee_id=:e AND attendance_date_eng=:d")
                     ->execute([':s'=>$presentStatusId,':i'=>$checkIn,':o'=>$checkOut,':e'=>$empId,':d'=>$syncDate]);
            } else {
                $conn->prepare("INSERT INTO attendance (employee_id,attendance_date_eng,status_id,check_in_time,check_out_time,data_source,created_at) VALUES (:e,:d,:s,:i,:o,'ZKTECO',NOW())")
                     ->execute([':e'=>$empId,':d'=>$syncDate,':s'=>$presentStatusId,':i'=>$checkIn,':o'=>$checkOut]);
            }
            $synced++;
            $syncDetails[] = ['name'=>$zkName,'user_id'=>$zkUserId,'match'=>$matchHow,'in'=>$checkIn,'out'=>$checkOut];
        } catch (Exception $ex) { $errors++; }
    }

    $total = count($punches);
    $syncMsg = "Synced $synced/$total employees for $syncDate — by ID: $matched_by_id, by name: $matched_by_name, no match: $no_match" . ($errors ? ", errors: $errors" : '');
    $_SESSION['success_message'] = $syncMsg;
    $_SESSION['sync_details']    = $syncDetails;
}

// ── Load ZKTecePuller data ────────────────────────────────────
$zkDevices     = [];
$pullSessions  = [];
$todayPunches  = [];
$zkConnected   = false;

if ($zk_conn) {
    $zkConnected = true;

    // Devices from ZKTecePuller
    $zkDevices = $zk_conn->query("SELECT * FROM devices ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

    // Recent pull sessions (last 100)
    $pullSessions = $zk_conn->query("
        SELECT ps.*, d.name AS device_name, d.ip_address, d.model
        FROM pull_sessions ps
        JOIN devices d ON ps.device_id = d.id
        ORDER BY ps.started_at DESC LIMIT 100
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Today's attendance logs from ZKTecePuller
    $viewDate    = $_GET['date'] ?? date('Y-m-d');
    $todayPunches= $zk_conn->prepare("
        SELECT al.uid, al.user_id, al.name AS device_name,
               al.timestamp AT TIME ZONE 'Asia/Kathmandu' AS punch_time_npt,
               al.punch, al.punch_label, al.status,
               d.name AS device
        FROM attendance_logs al
        JOIN devices d ON al.device_id = d.id
        WHERE al.timestamp::date = :d
        ORDER BY al.timestamp
    ");
    $todayPunches->execute([':d' => $viewDate]);
    $todayPunches = $todayPunches->fetchAll(PDO::FETCH_ASSOC);

    // Daily summary: first-in / last-out per employee
    $dailySummary = $zk_conn->prepare("
        SELECT al.user_id, al.name,
               MIN(al.timestamp AT TIME ZONE 'Asia/Kathmandu') AS first_in,
               MAX(al.timestamp AT TIME ZONE 'Asia/Kathmandu') AS last_out,
               COUNT(*) AS punch_count,
               MAX(d.name) AS device
        FROM attendance_logs al
        JOIN devices d ON al.device_id = d.id
        WHERE al.timestamp::date = :d
        GROUP BY al.user_id, al.name
        ORDER BY al.name
    ");
    $dailySummary->execute([':d' => $viewDate]);
    $dailySummary = $dailySummary->fetchAll(PDO::FETCH_ASSOC);
} else {
    $viewDate = $_GET['date'] ?? date('Y-m-d');
    $dailySummary = [];
}

// Pull session stats
$totalSessions  = count($pullSessions);
$successCount   = count(array_filter($pullSessions, fn($s) => $s['status'] === 'success'));
$totalRecords   = array_sum(array_column($pullSessions, 'records_pulled'));
$todaySessionCount = count(array_filter($pullSessions, fn($s) => strpos($s['started_at'] ?? '', date('Y-m-d')) === 0));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>ZKTecePuller Dashboard — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.punch-in{color:#1a9e5f;font-weight:600}
.punch-out{color:#d63031;font-weight:600}
.punch-other{color:#6c5ce7}
.log-row{font-size:.76rem}
.session-success{border-left:3px solid #1a9e5f}
.session-failed{border-left:3px solid #d63031}
.session-running{border-left:3px solid #e8a020}
.db-badge-zk{background:#e3f2fd;color:#0d47a1;padding:2px 7px;border-radius:4px;font-size:.65rem;font-weight:600}
.db-badge-jemc{background:#e8f5e9;color:#1b5e20;padding:2px 7px;border-radius:4px;font-size:.65rem;font-weight:600}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1600px">

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0" style="color:#2c3e8c">
            <i class="bi bi-fingerprint me-2"></i>ZKTecePuller — Live Attendance
        </h4>
        <div class="d-flex gap-2 mt-1">
            <span class="db-badge-zk"><i class="fas fa-database me-1"></i>zkteco DB <?= $zkConnected?'✓ Connected':'✗ Not connected' ?></span>
            <span class="db-badge-jemc"><i class="fas fa-database me-1"></i>press_jemc ✓ Connected</span>
        </div>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <a href="zkteco_mapping.php" class="btn btn-sm btn-outline-warning">
            <i class="fas fa-link me-1"></i>Employee Mapping
        </a>
        <a href="attendance_report.php?type=daily&date=<?= $viewDate ?>" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-calendar-day me-1"></i>Daily Report
        </a>
        <a href="attendance_report.php?type=monthly" class="btn btn-sm btn-outline-success">
            <i class="fas fa-calendar-month me-1"></i>Monthly Report
        </a>
        <button class="btn btn-sm btn-warning" data-bs-toggle="modal" data-bs-target="#syncModal">
            <i class="fas fa-sync me-1"></i>Sync to JEMC
        </button>
    </div>
</div>

<?php if(!$zkConnected): ?>
<div class="alert alert-warning">
    <h5><i class="fas fa-exclamation-triangle me-2"></i>ZKTecePuller database not connected</h5>
    <p class="mb-2">Cannot connect to <code>zkteco</code> database. Make sure:</p>
    <ol>
        <li>ZKTecePuller Python service is running: <code>python D:\claude_project\ZKTecePuller\main.py</code></li>
        <li>The <code>zkteco</code> PostgreSQL database exists (created on first run)</li>
        <li>Credentials match: <code>localhost:5432 / postgres / Nepal@123</code></li>
    </ol>
    <p class="mb-0">
        <strong>To start:</strong>
        <code>cd D:\claude_project\ZKTecePuller && python main.py --run-now</code>
    </p>
</div>
<?php endif; ?>

<?php if (isset($_SESSION['success_message'])): ?>
<div class="alert alert-success alert-dismissible fade show">
    <i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($_SESSION['success_message']) ?>
    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
</div>
<?php unset($_SESSION['success_message']); ?>
<?php endif; ?>

<!-- KPI Cards -->
<div class="row g-3 mb-3">
    <?php $kpis=[
        ['bi-hdd-network','ZKTeco Devices',count($zkDevices),'configured','#2c3e8c'],
        ['bi-arrow-down-circle','Total Pull Sessions',$totalSessions,$successCount.' success','#1a9e5f'],
        ['bi-database','Records Pulled',number_format($totalRecords),'all time','#6c5ce7'],
        ['bi-calendar-check','Pulls Today',$todaySessionCount,'sessions','#e8a020'],
        ['bi-person-check','Punches Today',count($todayPunches ?? []),count($dailySummary ?? []).' employees','#0984e3'],
    ];
    foreach($kpis as [$icon,$label,$val,$sub,$color]): ?>
    <div class="col-md col-6">
        <div class="bg-white rounded-3 shadow-sm p-3 h-100" style="border-left:4px solid <?= $color ?>">
            <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8492a6"><?= $label ?></div>
            <div style="font-size:1.6rem;font-weight:800;color:#2d3436"><?= $val ?></div>
            <div style="font-size:.68rem;color:#b2bec3"><?= $sub ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Main Grid -->
<div class="row g-3">

    <!-- LEFT: Devices + Date Selector -->
    <div class="col-md-3">

        <!-- Device Status -->
        <div class="bg-white rounded-3 shadow-sm mb-3">
            <div class="px-3 py-2 border-bottom"><h6 class="mb-0 fw-bold"><i class="bi bi-hdd-network me-1"></i>Devices (ZKTecePuller)</h6></div>
            <div class="p-3">
            <?php if(empty($zkDevices)): ?>
                <div class="text-muted text-center py-3" style="font-size:.8rem">
                    No devices configured.<br>
                    <small>Edit <code>D:\claude_project\ZKTecePuller\devices.json</code></small>
                </div>
            <?php else: foreach($zkDevices as $d): ?>
            <div class="border rounded p-2 mb-2">
                <div class="d-flex justify-content-between">
                    <div>
                        <span class="<?= $d['is_active']?'text-success':'text-secondary' ?>">●</span>
                        <strong style="font-size:.82rem"><?= htmlspecialchars($d['name']) ?></strong><br>
                        <small class="text-muted ms-3"><?= $d['ip_address'].':'.$d['port'] ?></small><br>
                        <small class="text-muted ms-3"><?= htmlspecialchars($d['model'] ?? '') ?></small>
                    </div>
                    <span class="badge <?= $d['is_active']?'bg-success':'bg-secondary' ?>" style="font-size:.6rem;height:fit-content">
                        <?= $d['is_active']?'Active':'Inactive' ?>
                    </span>
                </div>
            </div>
            <?php endforeach; endif; ?>
            </div>
        </div>

        <!-- Date Picker -->
        <div class="bg-white rounded-3 shadow-sm mb-3">
            <div class="px-3 py-2 border-bottom"><h6 class="mb-0 fw-bold"><i class="bi bi-calendar me-1"></i>View Date</h6></div>
            <div class="p-3">
                <form method="GET">
                    <input type="date" name="date" class="form-control form-control-sm mb-2"
                           value="<?= $viewDate ?>" max="<?= date('Y-m-d') ?>">
                    <button type="submit" class="btn btn-primary btn-sm w-100">
                        <i class="fas fa-search me-1"></i>Load Data
                    </button>
                </form>
                <div class="mt-2 d-flex gap-1 flex-wrap">
                    <?php for($i=0;$i<5;$i++): $d=date('Y-m-d',strtotime("-$i days")); ?>
                    <a href="?date=<?= $d ?>" class="btn btn-xs btn-outline-secondary <?= $d===$viewDate?'active':'' ?>"
                       style="font-size:.65rem;padding:2px 6px"><?= $i===0?'Today':date('d M',strtotime($d)) ?></a>
                    <?php endfor; ?>
                </div>
            </div>
        </div>

        <!-- Start ZKTecePuller instructions -->
        <div class="bg-white rounded-3 shadow-sm">
            <div class="px-3 py-2 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-terminal me-1"></i>Run ZKTecePuller</h6></div>
            <div class="p-3">
                <div style="font-size:.75rem;font-weight:600;color:#2d3436;margin-bottom:4px">Pull now (one-time):</div>
                <code style="font-size:.7rem;display:block;background:#f8f9fa;padding:6px;border-radius:4px;word-break:break-all">
                    cd D:\claude_project\ZKTecePuller<br>
                    python main.py --run-now
                </code>
                <div style="font-size:.75rem;font-weight:600;color:#2d3436;margin:8px 0 4px">Continuous (5×/day):</div>
                <code style="font-size:.7rem;display:block;background:#f8f9fa;padding:6px;border-radius:4px">
                    python main.py
                </code>
                <div style="font-size:.75rem;font-weight:600;color:#2d3436;margin:8px 0 4px">Generate report image:</div>
                <code style="font-size:.7rem;display:block;background:#f8f9fa;padding:6px;border-radius:4px">
                    python main.py --report <?= date('Y-m-d') ?>
                </code>
            </div>
        </div>
    </div>

    <!-- CENTER: Daily Summary -->
    <div class="col-md-5">
        <div class="bg-white rounded-3 shadow-sm h-100">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold">
                    <i class="bi bi-people me-1"></i>
                    Daily Summary — <?= date('d M Y', strtotime($viewDate)) ?>
                    <span class="badge bg-secondary ms-1"><?= count($dailySummary) ?></span>
                </h6>
                <div class="d-flex gap-1">
                    <a href="attendance_report.php?type=daily&date=<?= $viewDate ?>&export=pdf"
                       target="_blank" class="btn btn-danger btn-sm" style="font-size:.7rem;padding:2px 8px">
                        <i class="fas fa-file-pdf"></i> PDF
                    </a>
                    <a href="attendance_report.php?type=daily&date=<?= $viewDate ?>&export=excel"
                       class="btn btn-success btn-sm" style="font-size:.7rem;padding:2px 8px">
                        <i class="fas fa-file-excel"></i> Excel
                    </a>
                </div>
            </div>
            <div style="max-height:550px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0" style="font-size:.77rem">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>ID / Name</th>
                            <th>Device</th>
                            <th class="punch-in">First In</th>
                            <th class="punch-out">Last Out</th>
                            <th>Punches</th>
                            <th>Hrs</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($dailySummary)): ?>
                    <tr><td colspan="6" class="text-center py-5 text-muted">
                        <i class="fas fa-inbox fa-2x mb-2 d-block opacity-25"></i>
                        No ZKTecePuller data for <?= date('d M Y', strtotime($viewDate)) ?><br>
                        <small>Run <code>python main.py --run-now</code> to pull from devices.</small>
                    </td></tr>
                    <?php else: foreach($dailySummary as $emp):
                        $inTime  = $emp['first_in']  ? date('H:i', strtotime($emp['first_in']))  : '—';
                        $outTime = $emp['last_out']  ? date('H:i', strtotime($emp['last_out']))  : '—';
                        $hrs = ($emp['first_in'] && $emp['last_out'])
                            ? number_format((strtotime($emp['last_out']) - strtotime($emp['first_in'])) / 3600, 1)
                            : '—';
                    ?>
                    <tr>
                        <td>
                            <code style="font-size:.7rem"><?= htmlspecialchars($emp['user_id']) ?></code><br>
                            <small><?= htmlspecialchars($emp['name'] ?? '') ?></small>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($emp['device']) ?></small></td>
                        <td class="punch-in"><?= $inTime ?></td>
                        <td class="punch-out"><?= $outTime ?></td>
                        <td class="text-center">
                            <span class="badge bg-light text-dark border"><?= $emp['punch_count'] ?></span>
                        </td>
                        <td><?= $hrs !== '—' ? '<span class="badge bg-info">'.$hrs.'h</span>' : '—' ?></td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT: Pull Session Log -->
    <div class="col-md-4">
        <div class="bg-white rounded-3 shadow-sm h-100">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between">
                <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-1"></i>Pull Sessions Log</h6>
                <span class="badge bg-secondary"><?= $totalSessions ?></span>
            </div>
            <div style="max-height:580px;overflow-y:auto">
            <?php if(empty($pullSessions)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-history fa-2x mb-2 d-block opacity-25"></i>
                    No pull sessions yet.<br>
                    <small>Sessions appear after <code>python main.py --run-now</code></small>
                </div>
            <?php else: foreach($pullSessions as $s):
                switch($s['status']) {
                    case 'success': $cls='session-success'; break;
                    case 'failed':  $cls='session-failed';  break;
                    default:        $cls='session-running';
                }
            ?>
            <div class="px-3 py-2 border-bottom <?= $cls ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="badge <?= $s['status']==='success'?'bg-success':($s['status']==='failed'?'bg-danger':'bg-warning text-dark') ?>"
                              style="font-size:.6rem"><?= strtoupper($s['status']) ?></span>
                        <strong style="font-size:.76rem;margin-left:4px">
                            <?= htmlspecialchars($s['device_name'] ?? 'Device '.$s['device_id']) ?>
                        </strong>
                        <small class="text-muted ms-1">(<?= htmlspecialchars($s['model'] ?? '') ?>)</small>
                    </div>
                    <small class="text-muted" style="font-size:.65rem">
                        <?= $s['started_at'] ? date('d M H:i', strtotime($s['started_at'])) : '' ?>
                    </small>
                </div>
                <div style="font-size:.7rem;color:#636e72;margin-top:3px">
                    <span class="text-success">↓ <?= number_format($s['records_pulled'] ?? 0) ?> pulled</span> ·
                    <span class="text-primary">+<?= number_format($s['new_inserts'] ?? 0) ?> new</span>
                    <?php if($s['error_message']): ?>
                    <span class="text-danger ms-1">· ⚠ <?= htmlspecialchars(substr($s['error_message'],0,50)) ?></span>
                    <?php endif; ?>
                </div>
                <?php if($s['started_at'] && $s['completed_at']): ?>
                <div style="font-size:.65rem;color:#b2bec3">
                    Duration: <?= number_format((strtotime($s['completed_at'])-strtotime($s['started_at'])),1) ?>s
                    · <?= htmlspecialchars($s['ip_address'] ?? '') ?>
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Raw Punch Log (collapsible) -->
<?php if (!empty($todayPunches)): ?>
<div class="mt-3">
    <div class="bg-white rounded-3 shadow-sm">
        <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center"
             data-bs-toggle="collapse" data-bs-target="#rawLog" style="cursor:pointer">
            <h6 class="mb-0 fw-bold">
                <i class="bi bi-list-ul me-1"></i>Raw Punch Log — <?= date('d M Y', strtotime($viewDate)) ?>
                <span class="badge bg-secondary ms-1"><?= count($todayPunches) ?> punches</span>
            </h6>
            <i class="bi bi-chevron-down"></i>
        </div>
        <div class="collapse" id="rawLog">
            <div class="table-responsive" style="max-height:300px;overflow-y:auto">
                <table class="table table-sm mb-0" style="font-size:.74rem">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Time (NPT)</th><th>User ID</th><th>Name</th>
                            <th>Punch Type</th><th>Device</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach($todayPunches as $p):
                        switch((int)$p['punch']) {
                            case 0: case 255: $cls='punch-in';    break;
                            case 1:           $cls='punch-out';   break;
                            default:          $cls='punch-other';
                        }
                    ?>
                    <tr>
                        <td class="<?= $cls ?> fw-semibold">
                            <?= date('H:i:s', strtotime($p['punch_time_npt'])) ?>
                        </td>
                        <td><code style="font-size:.7rem"><?= htmlspecialchars($p['user_id']) ?></code></td>
                        <td><?= htmlspecialchars($p['device_name'] ?? '') ?></td>
                        <td>
                            <span class="badge <?= in_array($p['punch'],[0,255])?'bg-success':($p['punch']===1?'bg-danger':'bg-secondary') ?>"
                                  style="font-size:.62rem">
                                <?= htmlspecialchars($p['punch_label'] ?? 'Unknown') ?>
                            </span>
                        </td>
                        <td><small class="text-muted"><?= htmlspecialchars($p['device']) ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- Sync Modal -->
<div class="modal fade" id="syncModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-warning">
                <h5 class="modal-title"><i class="fas fa-sync me-2"></i>Sync ZKTecePuller → JEMC</h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST">
                <div class="modal-body">
                    <p style="font-size:.85rem">
                        This reads attendance from the <code>zkteco</code> database (ZKTecePuller)
                        and writes it to <code>press_jemc.attendance</code> so it appears in payroll
                        and all JEMC reports.
                    </p>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Sync Date</label>
                        <input type="date" name="sync_date" class="form-control"
                               value="<?= $viewDate ?>" max="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Matches ZKTecePuller employees by <strong>Card ID</strong> or <strong>Attendance ID</strong>
                        to JEMC employee records. Unmatched employees are skipped.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="sync" class="btn btn-warning">
                        <i class="fas fa-sync me-1"></i>Sync Now
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-refresh every 60s
setTimeout(() => location.reload(), 60000);
</script>
</body>
</html>
<?php ob_end_flush(); ?>

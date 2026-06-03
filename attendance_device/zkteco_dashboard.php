<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

// ── Stats ────────────────────────────────────────────────────
$devices = $conn->query("
    SELECT d.*,
           COUNT(DISTINCT zum.employee_id) AS mapped_employees,
           (SELECT COUNT(*) FROM zkteco_pull_log WHERE device_id=d.id AND pull_date=CURRENT_DATE) AS pulls_today
    FROM zkteco_devices d
    LEFT JOIN zkteco_user_mapping zum ON d.id=zum.device_id AND zum.is_active=true
    GROUP BY d.id ORDER BY d.priority, d.device_name
")->fetchAll(PDO::FETCH_ASSOC);

$todayStats = $conn->query("
    SELECT COUNT(*) AS total_pulls,
           COALESCE(SUM(inserted_records),0) AS inserted,
           COALESCE(SUM(updated_records),0)  AS updated,
           COALESCE(SUM(total_records),0)    AS total_records,
           COUNT(*) FILTER (WHERE status='SUCCESS') AS success_count
    FROM zkteco_pull_log WHERE pull_date=CURRENT_DATE
")->fetch(PDO::FETCH_ASSOC);

$attToday = $conn->query("
    SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE ast.status_code='P')  AS present,
           COUNT(*) FILTER (WHERE ast.status_code='A')  AS absent,
           COUNT(*) FILTER (WHERE data_source='ZKTECO') AS from_device
    FROM attendance a
    LEFT JOIN attendance_status ast ON a.status_id=ast.id
    WHERE a.attendance_date_eng=CURRENT_DATE
")->fetch(PDO::FETCH_ASSOC);

// Pull log — last 50
$pullLogs = $conn->query("
    SELECT pl.*, d.device_name, d.ip_address
    FROM zkteco_pull_log pl
    LEFT JOIN zkteco_devices d ON pl.device_id=d.id
    ORDER BY pl.id DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// ── Attendance for selected date ──────────────────────────────
$viewDate = $_GET['date'] ?? date('Y-m-d');
$deptFilter = $_GET['dept'] ?? '';

$attSql = "
    SELECT e.code, e.name,
           dep.name AS dept,
           ast.status_name, ast.status_code,
           a.check_in_time, a.check_out_time,
           a.ot_hours, a.data_source,
           a.attendance_date_eng, a.attendance_date_nep
    FROM attendance a
    JOIN employee e ON a.employee_id=e.id
    LEFT JOIN department dep ON e.department_id=dep.id
    LEFT JOIN attendance_status ast ON a.status_id=ast.id
    WHERE a.attendance_date_eng=:d
";
$attParams = [':d' => $viewDate];
if ($deptFilter) { $attSql .= " AND e.department_id=:dept"; $attParams[':dept']=$deptFilter; }
$attSql .= " ORDER BY dep.name, e.code";
$attStmt = $conn->prepare($attSql);
$attStmt->execute($attParams);
$attRows = $attStmt->fetchAll(PDO::FETCH_ASSOC);

$departments = $conn->query("SELECT id,name FROM department WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Schedules defined in puller
$schedules = [
    'morning'    => ['07:35','Morning check-in'],
    'midmorning' => ['10:45','Mid-morning update'],
    'afternoon'  => ['13:25','After lunch check'],
    'evening'    => ['17:25','Evening check-out'],
    'night'      => ['19:15','Late shift / OT'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>ZKTeco Attendance — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.pull-log-row{font-size:.76rem}
.source-zkteco{background:#e3f2fd;color:#0d47a1;padding:2px 6px;border-radius:4px;font-size:.68rem}
.source-manual{background:#f3e5f5;color:#4a148c;padding:2px 6px;border-radius:4px;font-size:.68rem}
.status-P{color:#1a9e5f;font-weight:700}
.status-A{color:#d63031;font-weight:700}
.status-HD{color:#e8a020;font-weight:700}
.status-L{color:#6c5ce7}
.dot-g{display:inline-block;width:8px;height:8px;border-radius:50%;background:#1a9e5f}
.dot-r{display:inline-block;width:8px;height:8px;border-radius:50%;background:#d63031}
.dot-x{display:inline-block;width:8px;height:8px;border-radius:50%;background:#b2bec3}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1500px">

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="mb-0 fw-bold" style="color:#2c3e8c">
            <i class="bi bi-fingerprint me-2"></i>ZKTeco Attendance Dashboard
        </h4>
        <small class="text-muted"><?= date('l, d F Y') ?> — Live attendance from biometric devices</small>
    </div>
    <div class="d-flex gap-2">
        <a href="attendance_report.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-file-pdf me-1"></i>Attendance Report & PDF
        </a>
        <button class="btn btn-sm btn-primary" data-bs-toggle="modal" data-bs-target="#pullModal">
            <i class="fas fa-sync me-1"></i>Manual Pull
        </button>
    </div>
</div>

<!-- KPI Row -->
<div class="row g-3 mb-3">
    <?php $kpis = [
        ['bi-hdd-network','Devices',count($devices).' total',array_sum(array_column($devices,'is_active')).' active','blue'],
        ['bi-people','Mapped Employees',$conn->query("SELECT COUNT(DISTINCT employee_id) FROM zkteco_user_mapping WHERE is_active=true")->fetchColumn(),'linked to devices','green'],
        ['bi-arrow-down-circle','Pulls Today',$todayStats['total_pulls'],$todayStats['success_count'].' success','purple'],
        ['bi-database','Records Today',$todayStats['total_records'],$todayStats['inserted'].' new · '.$todayStats['updated'].' updated','teal'],
        ['bi-calendar-check','Attendance Today',$attToday['total'],$attToday['present'].' present · '.$attToday['from_device'].' from device','orange'],
    ]; foreach($kpis as [$icon,$label,$val,$sub,$c]): ?>
    <div class="col-md col-6">
        <div class="bg-white rounded-3 shadow-sm p-3 d-flex align-items-center gap-3 h-100" style="border-left:4px solid var(--<?= $c==='blue'?'bs-primary':($c==='green'?'bs-success':($c==='orange'?'bs-warning':($c==='teal'?'bs-info':'bs-purple'))) ?>)">
            <div style="width:42px;height:42px;border-radius:9px;background:#f0f2f8;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0">
                <i class="bi <?= $icon ?>"></i>
            </div>
            <div>
                <div style="font-size:.67rem;font-weight:700;text-transform:uppercase;letter-spacing:.6px;color:#8492a6"><?= $label ?></div>
                <div style="font-size:1.5rem;font-weight:800;line-height:1;color:#2d3436"><?= $val ?></div>
                <div style="font-size:.68rem;color:#b2bec3"><?= $sub ?></div>
            </div>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<!-- Main Grid -->
<div class="row g-3">

    <!-- LEFT: Devices + Schedule -->
    <div class="col-md-4 col-xl-3">

        <!-- Devices -->
        <div class="bg-white rounded-3 shadow-sm mb-3">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="bi bi-hdd-network me-1"></i>Devices</h6>
                <a href="zkteco_device_management.php" style="font-size:.72rem">Manage</a>
            </div>
            <div class="p-3">
            <?php foreach($devices as $dev): ?>
            <div class="border rounded p-2 mb-2">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="<?= $dev['is_active'] ? 'dot-g' : 'dot-x' ?> me-1"></span>
                        <strong style="font-size:.82rem"><?= htmlspecialchars($dev['device_name']) ?></strong><br>
                        <small class="text-muted ms-3"><?= htmlspecialchars($dev['ip_address'].':'.$dev['port']) ?></small>
                    </div>
                    <div class="text-end">
                        <span class="badge <?= $dev['last_pull_status']==='SUCCESS'?'bg-success':'bg-secondary' ?>" style="font-size:.6rem">
                            <?= $dev['last_pull_status'] ?: 'Never' ?>
                        </span>
                        <div style="font-size:.65rem;color:#8492a6"><?= number_format($dev['last_pull_records']) ?> rec</div>
                    </div>
                </div>
                <?php if($dev['last_pull_at']): ?>
                <small class="text-muted ms-3" style="font-size:.66rem">
                    <?= date('d M H:i', strtotime($dev['last_pull_at'])) ?>
                </small>
                <?php endif; ?>
                <div style="font-size:.68rem;color:#636e72;margin-left:14px">
                    <?= $dev['mapped_employees'] ?> employees mapped · <?= $dev['pulls_today'] ?> pulls today
                </div>
            </div>
            <?php endforeach; ?>
            </div>
        </div>

        <!-- Pull Schedule -->
        <div class="bg-white rounded-3 shadow-sm">
            <div class="px-3 py-2 border-bottom">
                <h6 class="mb-0 fw-bold"><i class="bi bi-clock me-1"></i>Auto-Pull Schedule</h6>
            </div>
            <div class="p-3">
            <?php foreach($schedules as $key=>[$time,$desc]): ?>
            <div class="d-flex justify-content-between align-items-center mb-2 pb-2 border-bottom">
                <div>
                    <div style="font-size:.8rem;font-weight:600"><?= $time ?></div>
                    <div style="font-size:.68rem;color:#8492a6"><?= $desc ?></div>
                </div>
                <form method="POST" action="zkteco_ajax.php">
                    <input type="hidden" name="schedule" value="<?= $key ?>">
                    <button type="submit" class="btn btn-xs btn-outline-primary"
                            style="font-size:.65rem;padding:2px 8px">
                        Pull Now
                    </button>
                </form>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- CENTER: Attendance Data -->
    <div class="col-md-8 col-xl-6">
        <div class="bg-white rounded-3 shadow-sm">
            <div class="px-3 py-2 border-bottom">
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <h6 class="mb-0 fw-bold"><i class="bi bi-calendar-check me-1"></i>
                        Attendance — <?= date('d M Y', strtotime($viewDate)) ?>
                        <span class="badge bg-secondary ms-1"><?= count($attRows) ?></span>
                    </h6>
                    <div class="d-flex gap-2">
                        <a href="attendance_report.php?date=<?= $viewDate ?>&export=pdf"
                           target="_blank" class="btn btn-sm btn-danger" style="font-size:.72rem">
                            <i class="fas fa-file-pdf me-1"></i>PDF
                        </a>
                        <a href="attendance_report.php?date=<?= $viewDate ?>&export=excel"
                           class="btn btn-sm btn-success" style="font-size:.72rem">
                            <i class="fas fa-file-excel me-1"></i>Excel
                        </a>
                    </div>
                </div>
                <!-- Date & Dept filter -->
                <form method="GET" class="d-flex gap-2 mt-2">
                    <input type="date" name="date" class="form-control form-control-sm"
                           value="<?= $viewDate ?>" max="<?= date('Y-m-d') ?>" style="width:160px">
                    <select name="dept" class="form-select form-select-sm" style="width:180px">
                        <option value="">All Departments</option>
                        <?php foreach($departments as $d): ?>
                        <option value="<?= $d['id'] ?>" <?= $deptFilter==$d['id']?'selected':'' ?>><?= htmlspecialchars($d['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-primary btn-sm">Go</button>
                </form>
            </div>
            <div style="max-height:480px;overflow-y:auto">
                <table class="table table-sm table-hover mb-0" style="font-size:.76rem">
                    <thead class="table-light sticky-top">
                        <tr>
                            <th>Code</th><th>Name</th><th>Dept</th>
                            <th>Status</th><th>In</th><th>Out</th>
                            <th>OT</th><th>Source</th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php if(empty($attRows)): ?>
                        <tr><td colspan="8" class="text-center py-4 text-muted">
                            <i class="fas fa-info-circle mb-1 d-block"></i>
                            No attendance records for <?= date('d M Y', strtotime($viewDate)) ?><br>
                            <small>Pull data from ZKTeco device or mark manually.</small>
                        </td></tr>
                    <?php else: foreach($attRows as $r): ?>
                    <tr>
                        <td><code style="font-size:.7rem"><?= htmlspecialchars($r['code']) ?></code></td>
                        <td><?= htmlspecialchars($r['name']) ?></td>
                        <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                            <small><?= htmlspecialchars($r['dept'] ?? '') ?></small>
                        </td>
                        <td><span class="status-<?= $r['status_code'] ?>">
                            <?php $labels=['P'=>'Present','A'=>'Absent','HD'=>'Half Day','L'=>'Leave','H'=>'Holiday'];
                            echo $labels[$r['status_code']] ?? ($r['status_name'] ?? '—'); ?>
                        </span></td>
                        <td><?= $r['check_in_time'] ? substr($r['check_in_time'],0,5) : '—' ?></td>
                        <td><?= $r['check_out_time'] ? substr($r['check_out_time'],0,5) : '—' ?></td>
                        <td><?= $r['ot_hours'] > 0 ? number_format($r['ot_hours'],1).'h' : '' ?></td>
                        <td>
                            <span class="source-<?= strtolower($r['data_source'] ?? 'manual') ?>">
                                <?= $r['data_source'] ?? 'MANUAL' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <!-- RIGHT: Pull Log -->
    <div class="col-md-12 col-xl-3">
        <div class="bg-white rounded-3 shadow-sm h-100">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between">
                <h6 class="mb-0 fw-bold"><i class="bi bi-journal-text me-1"></i>Pull Log</h6>
                <a href="zkteco_pull_history.php" style="font-size:.72rem">Full History</a>
            </div>
            <div style="max-height:600px;overflow-y:auto">
            <?php if(empty($pullLogs)): ?>
                <div class="text-center py-5 text-muted">
                    <i class="fas fa-history fa-2x mb-2 d-block opacity-25"></i>
                    <div style="font-size:.8rem">No pull logs yet</div>
                    <small>Logs appear after device pulls</small>
                </div>
            <?php else: foreach($pullLogs as $log): ?>
            <div class="pull-log-row px-3 py-2 border-bottom">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <span class="badge <?= $log['status']==='SUCCESS'?'bg-success':($log['status']==='FAILED'?'bg-danger':'bg-warning text-dark') ?>"
                              style="font-size:.6rem"><?= $log['status'] ?></span>
                        <strong style="font-size:.74rem;margin-left:4px"><?= htmlspecialchars($log['device_name'] ?? 'Device '.$log['device_id']) ?></strong>
                    </div>
                    <small class="text-muted" style="font-size:.65rem">
                        <?= $log['pull_date'] ? date('d M', strtotime($log['pull_date'])) : '' ?>
                        <?= $log['pull_time'] ? substr($log['pull_time'],0,5) : '' ?>
                    </small>
                </div>
                <div style="font-size:.7rem;color:#636e72;margin-top:2px">
                    <?= ucfirst($log['schedule_type'] ?? 'manual') ?> pull ·
                    <?= $log['total_records'] ?> total ·
                    <span class="text-success">+<?= $log['inserted_records'] ?></span> new ·
                    <span class="text-info">~<?= $log['updated_records'] ?></span> updated
                    <?php if($log['error_records']>0): ?>
                    · <span class="text-danger"><?= $log['error_records'] ?> err</span>
                    <?php endif; ?>
                </div>
                <?php if($log['duration_seconds']): ?>
                <div style="font-size:.65rem;color:#b2bec3"><?= number_format($log['duration_seconds'],1) ?>s · <?= $log['employees_processed'] ?> employees</div>
                <?php endif; ?>
                <?php if($log['error_message']): ?>
                <div style="font-size:.65rem;color:#d63031;margin-top:2px" title="<?= htmlspecialchars($log['error_message']) ?>">
                    ⚠ <?= htmlspecialchars(substr($log['error_message'],0,60)) ?>...
                </div>
                <?php endif; ?>
            </div>
            <?php endforeach; endif; ?>
            </div>
        </div>
    </div>
</div>

</div><!-- /container -->

<!-- Manual Pull Modal -->
<div class="modal fade" id="pullModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title"><i class="fas fa-sync me-2"></i>Trigger Manual Pull</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <form method="POST" action="zkteco_ajax.php">
                <div class="modal-body">
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Device</label>
                        <select name="device_id" class="form-select">
                            <option value="">All Active Devices</option>
                            <?php foreach($devices as $dev): ?>
                            <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['device_name']) ?> (<?= $dev['ip_address'] ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Schedule Type</label>
                        <select name="schedule" class="form-select">
                            <option value="manual">Manual (no schedule tag)</option>
                            <?php foreach($schedules as $k=>[$t,$d]): ?>
                            <option value="<?= $k ?>"><?= ucfirst($k) ?> (<?= $t ?> — <?= $d ?>)</option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label fw-semibold">Date</label>
                        <input type="date" name="date" class="form-control" value="<?= date('Y-m-d') ?>">
                    </div>
                    <div class="alert alert-info py-2 small mb-0">
                        <i class="fas fa-info-circle me-1"></i>
                        Pull runs in the background. Refresh this page after 30 seconds to see results in the log.
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                    <button type="submit" name="trigger_pull" class="btn btn-primary">
                        <i class="fas fa-play me-1"></i>Start Pull
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Auto-refresh pull log every 30s
setTimeout(() => location.reload(), 30000);
</script>
</body>
</html>
<?php ob_end_flush(); ?>

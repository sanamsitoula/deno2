<?php
/**
 * ZKTeco ↔ JEMC Employee Mapping
 * Shows exact mismatches, fuzzy suggestions, error details.
 * Allows manual linking + auto-map by fuzzy name.
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/zkteco_db.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

$msg = ''; $err = ''; $mapResults = [];

// ── Normalise name for fuzzy matching ────────────────────────
function normName(string $n): string {
    return strtolower(preg_replace('/\s+/', ' ', trim($n)));
}
function similarity(string $a, string $b): int {
    similar_text($a, $b, $pct);
    return (int)round($pct);
}

// ── Save manual map ──────────────────────────────────────────
if (($_POST['action'] ?? '') === 'map') {
    $empId = (int)$_POST['emp_id'];
    $zkId  = trim($_POST['zk_user_id']);
    if ($empId && $zkId !== '') {
        try {
            $conn->prepare("UPDATE employee SET attendance_id=:aid WHERE id=:id")
                 ->execute([':aid' => $zkId, ':id' => $empId]);
            $msg = "✓ Mapped employee ID $empId → ZKTeco user_id $zkId";
        } catch(Exception $e) { $err = $e->getMessage(); }
    }
}

// ── Clear map ────────────────────────────────────────────────
if (($_POST['action'] ?? '') === 'clear') {
    $conn->prepare("UPDATE employee SET attendance_id=NULL WHERE id=:id")
         ->execute([':id' => (int)$_POST['emp_id']]);
    $msg = "Mapping cleared.";
}

// ── Fuzzy auto-map ───────────────────────────────────────────
if (($_POST['action'] ?? '') === 'fuzzy_map' && $zk_conn) {
    $threshold = (int)($_POST['threshold'] ?? 85);
    $mapped = 0; $skipped = 0;

    // Get ZKTeco distinct users
    $zkUsers = $zk_conn->query("
        SELECT user_id, MAX(name) AS name FROM employees
        WHERE name IS NOT NULL AND name != ''
        GROUP BY user_id ORDER BY name
    ")->fetchAll(PDO::FETCH_ASSOC);

    // Get unmapped JEMC employees
    $jemcEmp = $conn->query("
        SELECT id, name FROM employee
        WHERE emp_status='ACTIVE' AND deleted_date IS NULL
          AND (attendance_id IS NULL OR attendance_id = '')
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($jemcEmp as $je) {
        $normJemc = normName($je['name']);
        $bestScore = 0; $bestZkId = null;

        foreach ($zkUsers as $zk) {
            $normZk = normName($zk['name']);
            if (empty($normZk) || is_numeric(str_replace(' ','',$normZk))) continue;
            $score = similarity($normJemc, $normZk);
            if ($score > $bestScore) {
                $bestScore = $score;
                $bestZkId  = $zk['user_id'];
            }
        }
        if ($bestScore >= $threshold && $bestZkId) {
            $conn->prepare("UPDATE employee SET attendance_id=:aid WHERE id=:id")
                 ->execute([':aid' => $bestZkId, ':id' => $je['id']]);
            $mapped++;
        } else {
            $skipped++;
        }
    }
    $msg = "Fuzzy auto-map complete (threshold {$threshold}%): {$mapped} mapped, {$skipped} skipped (below threshold).";
}

// ── Load all data ────────────────────────────────────────────
// JEMC employees
$jemcAll = $conn->query("
    SELECT e.id, e.code, e.name, e.emp_type, e.attendance_id, e.card_id,
           dep.name AS dept, d.name AS designation, l.name AS level_name
    FROM employee e
    LEFT JOIN department dep ON e.department_id=dep.id
    LEFT JOIN designation d  ON e.designation_id=d.id
    LEFT JOIN level       l  ON e.level_id=l.id
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL
    ORDER BY e.name
")->fetchAll(PDO::FETCH_ASSOC);

// ZKTeco unique users with punch count
$zkAll = [];
$zkByUserId = [];
if ($zk_conn) {
    // employees table: id, device_id, uid, user_id, name, created_at, updated_at
    // attendance_logs table: user_id, timestamp, punch_count — join for stats
    $zkRaw = $zk_conn->query("
        SELECT e.user_id,
               MAX(e.name)              AS name,
               COUNT(DISTINCT e.device_id) AS device_count,
               MAX(al.timestamp)        AS last_seen,
               COUNT(al.id)             AS punch_count
        FROM employees e
        LEFT JOIN attendance_logs al ON al.user_id = e.user_id
        WHERE e.name IS NOT NULL
        GROUP BY e.user_id
        ORDER BY MAX(e.name)
    ")->fetchAll(PDO::FETCH_ASSOC);

    foreach ($zkRaw as $zk) {
        $zkAll[] = $zk;
        $zkByUserId[$zk['user_id']] = $zk;
    }
}

// ── Build mapping status with fuzzy suggestions ───────────────
$mapped   = [];
$unmapped = [];
$zkUsedIds = [];

foreach ($jemcAll as $je) {
    $zkId   = trim($je['attendance_id'] ?? '');
    $zkData = $zkId ? ($zkByUserId[$zkId] ?? null) : null;

    // Build fuzzy suggestions for unmapped
    $suggestions = [];
    if (!$zkId || !$zkData) {
        $normJemc = normName($je['name']);
        $scored = [];
        foreach ($zkAll as $zk) {
            $normZk = normName($zk['name']);
            if (empty($normZk) || is_numeric(str_replace(' ','',$normZk))) continue;
            $score = similarity($normJemc, $normZk);
            if ($score >= 60) {
                $scored[] = ['zk' => $zk, 'score' => $score];
            }
        }
        usort($scored, fn($a,$b) => $b['score'] <=> $a['score']);
        $suggestions = array_slice($scored, 0, 5);
    }

    $status = [
        'jemc'        => $je,
        'zk_data'     => $zkData,
        'zk_id_set'   => $zkId !== '',
        'zk_found'    => $zkData !== null,
        'suggestions' => $suggestions,
    ];

    if ($zkId && $zkData) {
        $mapped[] = $status;
        $zkUsedIds[$zkId] = true;
    } else {
        $unmapped[] = $status;
    }
}

// ZKTeco users not linked to any JEMC employee
$zkOrphans = array_filter($zkAll, fn($z) => !isset($zkUsedIds[$z['user_id']]));

// Stats
$totalJemc  = count($jemcAll);
$totalMapped = count($mapped);
$totalUnmapped = count($unmapped);
$totalZk    = count($zkAll);

// Last sync result from session
$lastSync = $_SESSION['last_sync_result'] ?? null;

// Active tab
$activeTab = $_GET['tab'] ?? 'unmapped';
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>ZKTeco Employee Mapping — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8;font-size:.84rem}
.score-high{color:#1a9e5f;font-weight:700}
.score-med{color:#e8a020;font-weight:700}
.score-low{color:#d63031}
.score-bar{height:6px;border-radius:3px;background:#f0f2f8;overflow:hidden;margin-top:2px}
.score-bar-fill{height:100%;border-radius:3px}
.jemc-name{font-weight:700;color:#2c3e8c}
.zk-name{color:#1a9e5f}
.no-match{color:#d63031;font-style:italic}
.suggestion-row{background:#fff8ec;border-left:3px solid #e8a020;padding:4px 8px;border-radius:0 4px 4px 0;margin-bottom:3px;cursor:pointer}
.suggestion-row:hover{background:#fff3cd}
.mapped-row{background:#e8f5e9}
.error-row{background:#ffebee}
.orphan-row{background:#fff8e1}
.diff-highlight{background:#fff59d;padding:0 2px;border-radius:2px}
.tab-count{font-size:.65rem;background:rgba(0,0,0,.15);border-radius:10px;padding:1px 6px;margin-left:4px}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1600px">

<!-- Header -->
<div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
    <div>
        <h4 class="fw-bold mb-0" style="color:#2c3e8c">
            <i class="bi bi-diagram-2 me-2"></i>ZKTeco ↔ JEMC Employee Mapping
        </h4>
        <small class="text-muted">Link biometric device users to JEMC employee records for attendance sync</small>
    </div>
    <div class="d-flex gap-2 flex-wrap">
        <button class="btn btn-sm btn-outline-warning" data-bs-toggle="modal" data-bs-target="#fuzzyModal">
            <i class="fas fa-magic me-1"></i>Fuzzy Auto-Map
        </button>
        <a href="zkteco_live.php" class="btn btn-sm btn-outline-primary">
            <i class="fas fa-arrow-left me-1"></i>Back to Live
        </a>
    </div>
</div>

<!-- Alerts -->
<?php if($msg): ?><div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-danger alert-dismissible fade show py-2"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<!-- Stats Row -->
<div class="row g-3 mb-3">
    <div class="col-md-3">
        <div class="bg-white rounded-3 shadow-sm p-3 d-flex gap-3 align-items-center" style="border-left:4px solid #2c3e8c">
            <i class="fas fa-users" style="font-size:1.5rem;color:#2c3e8c"></i>
            <div>
                <div class="fw-bold fs-4"><?= $totalJemc ?></div>
                <div class="text-muted" style="font-size:.72rem">JEMC Active Employees</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="bg-white rounded-3 shadow-sm p-3 d-flex gap-3 align-items-center" style="border-left:4px solid #1a9e5f">
            <i class="fas fa-link" style="font-size:1.5rem;color:#1a9e5f"></i>
            <div>
                <div class="fw-bold fs-4 text-success"><?= $totalMapped ?></div>
                <div class="text-muted" style="font-size:.72rem">Mapped (<?= $totalJemc>0?round($totalMapped/$totalJemc*100).'%':'0%' ?>)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="bg-white rounded-3 shadow-sm p-3 d-flex gap-3 align-items-center" style="border-left:4px solid #e8a020">
            <i class="fas fa-unlink" style="font-size:1.5rem;color:#e8a020"></i>
            <div>
                <div class="fw-bold fs-4 text-warning"><?= $totalUnmapped ?></div>
                <div class="text-muted" style="font-size:.72rem">Unmapped (need linking)</div>
            </div>
        </div>
    </div>
    <div class="col-md-3">
        <div class="bg-white rounded-3 shadow-sm p-3 d-flex gap-3 align-items-center" style="border-left:4px solid #6c5ce7">
            <i class="bi bi-fingerprint" style="font-size:1.5rem;color:#6c5ce7"></i>
            <div>
                <div class="fw-bold fs-4"><?= $totalZk ?></div>
                <div class="text-muted" style="font-size:.72rem">ZKTeco Unique Users</div>
            </div>
        </div>
    </div>
</div>

<!-- Why names don't match — info box -->
<?php if($totalUnmapped > 0): ?>
<div class="alert alert-warning py-2 mb-3" style="font-size:.8rem">
    <i class="fas fa-exclamation-triangle me-1"></i>
    <strong>Why names don't match automatically:</strong>
    Spelling differences (e.g. <code>Akshya</code> vs <code>Akshay</code>),
    missing spaces (<code>AmitRegmi</code> vs <code>Amit Regmi</code>),
    extra spaces, case differences, or different name formats in the biometric device.
    Use <strong>Fuzzy Auto-Map</strong> (≥85% similarity) or map manually below.
</div>
<?php endif; ?>

<!-- Tabs -->
<ul class="nav nav-tabs mb-3">
    <li class="nav-item">
        <a class="nav-link <?= $activeTab==='unmapped'?'active':'' ?>" href="?tab=unmapped">
            <i class="fas fa-unlink me-1"></i>Unmapped
            <span class="tab-count"><?= $totalUnmapped ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab==='mapped'?'active':'' ?>" href="?tab=mapped">
            <i class="fas fa-link me-1"></i>Mapped
            <span class="tab-count"><?= $totalMapped ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab==='orphans'?'active':'' ?>" href="?tab=orphans">
            <i class="fas fa-user-slash me-1"></i>ZKTeco Only (not in JEMC)
            <span class="tab-count"><?= count($zkOrphans) ?></span>
        </a>
    </li>
    <li class="nav-item">
        <a class="nav-link <?= $activeTab==='all_zk'?'active':'' ?>" href="?tab=all_zk">
            <i class="bi bi-fingerprint me-1"></i>All ZKTeco Users
            <span class="tab-count"><?= $totalZk ?></span>
        </a>
    </li>
</ul>

<!-- ══ UNMAPPED TAB ══════════════════════════════════════════ -->
<?php if($activeTab === 'unmapped'): ?>
<div class="bg-white rounded-3 shadow-sm">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-unlink me-1 text-warning"></i><?= $totalUnmapped ?> unmapped JEMC employees</h6>
        <input type="text" id="searchUnmapped" class="form-control form-control-sm" style="width:220px" placeholder="Search employee...">
    </div>

    <?php foreach($unmapped as $row):
        $je = $row['jemc'];
        $sugg = $row['suggestions'];
    ?>
    <div class="border-bottom p-3 unmapped-emp-row" style="<?= empty($sugg)?'background:#fff5f5':'' ?>">
        <div class="row g-2 align-items-start">

            <!-- JEMC Employee -->
            <div class="col-md-4">
                <div class="d-flex gap-2 align-items-start">
                    <div style="width:32px;height:32px;border-radius:50%;background:#e8a020;color:#fff;display:flex;align-items:center;justify-content:center;flex-shrink:0;font-size:.75rem;font-weight:700">
                        <?= strtoupper(substr($je['name'],0,1)) ?>
                    </div>
                    <div>
                        <div class="jemc-name"><?= htmlspecialchars($je['name']) ?></div>
                        <div style="font-size:.72rem;color:#636e72">
                            <code><?= $je['code'] ?></code> ·
                            <?= htmlspecialchars($je['designation'] ?? '') ?> ·
                            <?= $je['emp_type'] ?>
                        </div>
                        <div style="font-size:.7rem;color:#8492a6"><?= htmlspecialchars($je['dept'] ?? '') ?></div>
                        <?php if($row['zk_id_set'] && !$row['zk_found']): ?>
                        <span class="badge bg-danger mt-1" style="font-size:.62rem">
                            ⚠ attendance_id=<?= htmlspecialchars($je['attendance_id']) ?> set but NOT FOUND in ZKTeco
                        </span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <!-- Suggestions -->
            <div class="col-md-5">
                <?php if(empty($sugg)): ?>
                <div class="no-match">
                    <i class="fas fa-times-circle me-1"></i>No similar name found in ZKTeco device
                    <div style="font-size:.7rem;color:#8492a6;margin-top:2px">
                        This employee may not be enrolled in the biometric device, or name is very different.
                    </div>
                </div>
                <?php else: ?>
                <div style="font-size:.72rem;color:#636e72;margin-bottom:4px">
                    <i class="fas fa-robot me-1"></i>Suggested matches (click to select):
                </div>
                <?php foreach($sugg as $s):
                    $score = $s['score'];
                    $cls   = $score>=85?'score-high':($score>=70?'score-med':'score-low');
                    $color = $score>=85?'#1a9e5f':($score>=70?'#e8a020':'#d63031');
                ?>
                <div class="suggestion-row" onclick="selectMapping(<?= $je['id'] ?>, '<?= $s['zk']['user_id'] ?>', '<?= htmlspecialchars(addslashes($s['zk']['name'])) ?>')">
                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <span class="zk-name">ID:<?= $s['zk']['user_id'] ?></span>
                            — <strong><?= htmlspecialchars($s['zk']['name']) ?></strong>
                            <small class="text-muted">(<?= $s['zk']['device_count'] ?> device<?= $s['zk']['device_count']>1?'s':'' ?> · <?= $s['zk']['punch_count'] ?> punches)</small>
                        </div>
                        <div class="text-end">
                            <span class="<?= $cls ?>"><?= $score ?>%</span>
                            <div class="score-bar" style="width:60px">
                                <div class="score-bar-fill" style="width:<?= $score ?>%;background:<?= $color ?>"></div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Actions -->
            <div class="col-md-3">
                <form method="POST" class="map-form" id="form-<?= $je['id'] ?>">
                    <input type="hidden" name="action" value="map">
                    <input type="hidden" name="emp_id" value="<?= $je['id'] ?>">
                    <div class="input-group input-group-sm mb-1">
                        <input type="text" name="zk_user_id" id="zk-input-<?= $je['id'] ?>"
                               class="form-control" placeholder="ZKTeco user_id"
                               value="<?= htmlspecialchars($je['attendance_id'] ?? '') ?>">
                        <button type="submit" class="btn btn-success btn-sm">
                            <i class="fas fa-link"></i>
                        </button>
                    </div>
                    <small class="text-muted">Enter user_id or click a suggestion above</small>
                </form>

                <!-- Manual search ZK user -->
                <button class="btn btn-xs btn-outline-secondary mt-1 w-100" style="font-size:.7rem"
                        onclick="openZkSearch(<?= $je['id'] ?>, '<?= htmlspecialchars(addslashes($je['name'])) ?>')">
                    <i class="fas fa-search me-1"></i>Search All ZKTeco Users
                </button>
            </div>

        </div>
    </div>
    <?php endforeach; ?>

    <?php if(empty($unmapped)): ?>
    <div class="text-center py-5 text-success">
        <i class="fas fa-check-circle fa-3x mb-3 d-block"></i>
        All employees are mapped! ✓
    </div>
    <?php endif; ?>
</div>

<!-- ══ MAPPED TAB ═══════════════════════════════════════════ -->
<?php elseif($activeTab === 'mapped'): ?>
<div class="bg-white rounded-3 shadow-sm">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-link me-1 text-success"></i><?= $totalMapped ?> mapped employees</h6>
        <input type="text" id="searchMapped" class="form-control form-control-sm" style="width:220px" placeholder="Search...">
    </div>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
        <thead class="table-light">
            <tr>
                <th>#</th><th>JEMC Employee</th><th>Designation</th><th>ZKTeco ID</th>
                <th>ZKTeco Name</th><th>Similarity</th><th>Devices</th><th>Last Punch</th><th>Action</th>
            </tr>
        </thead>
        <tbody id="mappedTbody">
        <?php $i=1; foreach($mapped as $row):
            $je = $row['jemc'];
            $zk = $row['zk_data'];
            $sim = 0;
            if ($zk) similar_text(normName($je['name']), normName($zk['name']), $sim);
            $simCls = $sim>=85?'score-high':($sim>=70?'score-med':'score-low');
        ?>
        <tr class="mapped-row mapped-search-row">
            <td><?= $i++ ?></td>
            <td>
                <strong><?= htmlspecialchars($je['name']) ?></strong>
                <small class="text-muted d-block"><code><?= $je['code'] ?></code> · <?= $je['emp_type'] ?></small>
            </td>
            <td><small><?= htmlspecialchars($je['designation'] ?? '') ?></small></td>
            <td><span class="badge bg-primary" style="font-size:.68rem"><?= htmlspecialchars($je['attendance_id']) ?></span></td>
            <td>
                <?php if($zk): ?>
                <span class="<?= normName($je['name'])===normName($zk['name'])?'text-success':'text-warning' ?>">
                    <?= htmlspecialchars($zk['name'] ?? '') ?>
                </span>
                <?php else: ?>
                <span class="text-danger"><i class="fas fa-exclamation-triangle me-1"></i>Not found in ZKTeco DB!</span>
                <?php endif; ?>
            </td>
            <td>
                <span class="<?= $simCls ?>"><?= round($sim) ?>%</span>
                <div class="score-bar" style="width:50px">
                    <div class="score-bar-fill" style="width:<?= $sim ?>%;background:<?= $sim>=85?'#1a9e5f':($sim>=70?'#e8a020':'#d63031') ?>"></div>
                </div>
            </td>
            <td class="text-center"><?= $zk ? $zk['device_count'] : '—' ?></td>
            <td><small class="text-muted"><?= $zk && $zk['last_seen'] ? date('d M H:i', strtotime($zk['last_seen'])) : '—' ?></small></td>
            <td>
                <form method="POST" style="display:inline" onsubmit="return confirm('Clear mapping for <?= htmlspecialchars(addslashes($je['name'])) ?>?')">
                    <input type="hidden" name="action" value="clear">
                    <input type="hidden" name="emp_id" value="<?= $je['id'] ?>">
                    <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.68rem;padding:1px 6px">
                        <i class="fas fa-unlink"></i>
                    </button>
                </form>
                <button class="btn btn-xs btn-outline-secondary ms-1" style="font-size:.68rem;padding:1px 6px"
                        onclick="openZkSearch(<?= $je['id'] ?>, '<?= htmlspecialchars(addslashes($je['name'])) ?>')">
                    <i class="fas fa-edit"></i>
                </button>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ══ ORPHAN ZKTeco USERS ═══════════════════════════════════ -->
<?php elseif($activeTab === 'orphans'): ?>
<div class="bg-white rounded-3 shadow-sm">
    <div class="px-3 py-2 border-bottom">
        <h6 class="mb-0 fw-bold"><i class="fas fa-user-slash me-1 text-warning"></i><?= count($zkOrphans) ?> ZKTeco users not linked to any JEMC employee</h6>
        <small class="text-muted">These people punch in at the device but have no JEMC employee record</small>
    </div>
    <div class="table-responsive">
    <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
        <thead class="table-light">
            <tr><th>#</th><th>ZKTeco User ID</th><th>Name in Device</th><th>Devices</th><th>Last Punch</th><th>Total Punches</th><th>Map to JEMC</th></tr>
        </thead>
        <tbody>
        <?php $i=1; foreach($zkOrphans as $zk): ?>
        <tr class="orphan-row">
            <td><?= $i++ ?></td>
            <td><code><?= htmlspecialchars($zk['user_id']) ?></code></td>
            <td><strong><?= htmlspecialchars($zk['name'] ?? '(no name)') ?></strong></td>
            <td class="text-center"><?= $zk['device_count'] ?></td>
            <td><small><?= $zk['last_seen'] ? date('d M Y H:i', strtotime($zk['last_seen'])) : '—' ?></small></td>
            <td class="text-center"><?= number_format($zk['punch_count']) ?></td>
            <td>
                <form method="POST" class="d-flex gap-1">
                    <input type="hidden" name="action" value="map">
                    <input type="hidden" name="zk_user_id" value="<?= htmlspecialchars($zk['user_id']) ?>">
                    <select name="emp_id" class="form-select form-select-sm" style="max-width:200px" required>
                        <option value="">Select JEMC Employee</option>
                        <?php foreach($jemcAll as $je): ?>
                        <option value="<?= $je['id'] ?>"><?= htmlspecialchars($je['code'].' — '.$je['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                    <button type="submit" class="btn btn-success btn-sm"><i class="fas fa-link"></i></button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- ══ ALL ZKTeco USERS ══════════════════════════════════════ -->
<?php elseif($activeTab === 'all_zk'): ?>
<div class="bg-white rounded-3 shadow-sm">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="bi bi-fingerprint me-1"></i><?= $totalZk ?> ZKTeco unique users</h6>
        <input type="text" id="searchZk" class="form-control form-control-sm" style="width:220px" placeholder="Search by name or ID...">
    </div>
    <div class="table-responsive" style="max-height:600px;overflow-y:auto">
    <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
        <thead class="table-dark sticky-top">
            <tr><th>#</th><th>User ID</th><th>Name in Device</th><th>Devices</th><th>Last Seen</th><th>Punches</th><th>Linked to</th></tr>
        </thead>
        <tbody>
        <?php
        // Build reverse map: zk_user_id → jemc employee name
        $zkToJemc = [];
        foreach($jemcAll as $je) {
            $aid = trim($je['attendance_id'] ?? '');
            if ($aid) $zkToJemc[$aid] = $je;
        }
        $i=1; foreach($zkAll as $zk):
            $linked = $zkToJemc[$zk['user_id']] ?? null;
        ?>
        <tr class="<?= $linked?'mapped-row':'orphan-row' ?> zk-search-row">
            <td><?= $i++ ?></td>
            <td><code style="font-size:.7rem"><?= htmlspecialchars($zk['user_id']) ?></code></td>
            <td><strong><?= htmlspecialchars($zk['name'] ?? '(no name)') ?></strong></td>
            <td class="text-center"><?= $zk['device_count'] ?></td>
            <td><small class="text-muted"><?= $zk['last_seen'] ? date('d M H:i', strtotime($zk['last_seen'])) : '—' ?></small></td>
            <td class="text-center"><?= number_format($zk['punch_count']) ?></td>
            <td>
                <?php if($linked): ?>
                <span class="text-success"><i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($linked['code'].' — '.$linked['name']) ?></span>
                <?php else: ?>
                <span class="text-muted" style="font-size:.72rem"><i class="fas fa-question-circle me-1"></i>Not linked</span>
                <?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endif; ?>

</div><!-- /container -->

<!-- ZKTeco Search Modal (for manual mapping) -->
<div class="modal fade" id="zkSearchModal" tabindex="-1">
<div class="modal-dialog modal-xl">
<div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-search me-2"></i>Search ZKTeco User — <span id="modalEmpName" class="fw-normal"></span></h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <div class="modal-body">
        <input type="text" id="zkModalSearch" class="form-control mb-3" placeholder="Type name or user_id to filter...">
        <div style="max-height:450px;overflow-y:auto">
        <table class="table table-sm table-hover" style="font-size:.8rem">
            <thead class="table-dark sticky-top">
                <tr><th>User ID</th><th>Name in Device</th><th>Devices</th><th>Last Seen</th><th>Punches</th><th>Already Mapped To</th><th>Select</th></tr>
            </thead>
            <tbody id="zkModalBody">
            <?php foreach($zkAll as $zk):
                $linked = $zkToJemc[$zk['user_id']] ?? null;
            ?>
            <tr class="zk-modal-row" data-name="<?= strtolower($zk['name']??'') ?>" data-id="<?= $zk['user_id'] ?>">
                <td><code><?= htmlspecialchars($zk['user_id']) ?></code></td>
                <td><strong><?= htmlspecialchars($zk['name'] ?? '') ?></strong></td>
                <td><?= $zk['device_count'] ?></td>
                <td><small><?= $zk['last_seen'] ? date('d M H:i', strtotime($zk['last_seen'])) : '—' ?></small></td>
                <td><?= number_format($zk['punch_count']) ?></td>
                <td>
                    <?php if($linked): ?>
                    <small class="text-success"><?= htmlspecialchars($linked['name']) ?></small>
                    <?php else: ?>
                    <small class="text-muted">—</small>
                    <?php endif; ?>
                </td>
                <td>
                    <button type="button" class="btn btn-xs btn-primary" style="font-size:.7rem;padding:2px 8px"
                            onclick="confirmMap('<?= $zk['user_id'] ?>', '<?= htmlspecialchars(addslashes($zk['name']??'')) ?>')">
                        <i class="fas fa-link"></i> Select
                    </button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </div>
    <div class="modal-footer">
        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
    </div>
</div>
</div>
</div>

<!-- Fuzzy Auto-Map Modal -->
<div class="modal fade" id="fuzzyModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-warning">
        <h5 class="modal-title"><i class="fas fa-magic me-2"></i>Fuzzy Auto-Map</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="fuzzy_map">
        <div class="modal-body">
            <p style="font-size:.85rem">
                Automatically maps employees by name similarity.
                Set a higher threshold for more accurate (but fewer) matches,
                lower threshold for more matches (but risk of wrong links).
            </p>
            <div class="mb-3">
                <label class="form-label fw-semibold">Similarity Threshold</label>
                <input type="range" name="threshold" id="thresholdRange"
                       class="form-range" min="60" max="95" value="82"
                       oninput="document.getElementById('thresholdVal').textContent=this.value+'%'">
                <div class="text-center fw-bold" id="thresholdVal">82%</div>
                <div class="d-flex justify-content-between" style="font-size:.72rem;color:#8492a6">
                    <span>60% — more matches, less accurate</span>
                    <span>95% — fewer matches, very accurate</span>
                </div>
            </div>
            <div class="alert alert-info py-2 small mb-0">
                <i class="fas fa-info-circle me-1"></i>
                Examples at 82%: "Akshya chaudhary" ↔ "Akshay Chaudhary" ✓
                | "AmitRegmi" ↔ "Amit Regmi" ✓
                | Only unmapped employees are processed.
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning">
                <i class="fas fa-magic me-1"></i>Run Fuzzy Auto-Map
            </button>
        </div>
    </form>
</div>
</div>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
let currentEmpId = null;

// Select a suggestion from the unmapped list
function selectMapping(empId, zkUserId, zkName) {
    const inp = document.getElementById('zk-input-' + empId);
    if (inp) {
        inp.value = zkUserId;
        inp.closest('.map-form').querySelector('button[type=submit]').classList.add('btn-pulse');
        inp.style.borderColor = '#1a9e5f';
        // Auto-submit after brief delay so user sees the selection
        setTimeout(() => inp.closest('.map-form').submit(), 400);
    }
}

// Open ZKTeco user search modal
function openZkSearch(empId, empName) {
    currentEmpId = empId;
    document.getElementById('modalEmpName').textContent = empName;
    document.getElementById('zkModalSearch').value = '';
    filterZkModal('');
    new bootstrap.Modal(document.getElementById('zkSearchModal')).show();
}

// Confirm mapping from modal
function confirmMap(zkUserId, zkName) {
    if (!currentEmpId) return;
    const form = document.createElement('form');
    form.method = 'POST';
    form.innerHTML = `
        <input name="action" value="map">
        <input name="emp_id" value="${currentEmpId}">
        <input name="zk_user_id" value="${zkUserId}">
    `;
    document.body.appendChild(form);
    form.submit();
}

// Filter ZKTeco modal search
document.getElementById('zkModalSearch')?.addEventListener('input', function() {
    filterZkModal(this.value.toLowerCase());
});
function filterZkModal(q) {
    document.querySelectorAll('.zk-modal-row').forEach(row => {
        const n = row.dataset.name + ' ' + row.dataset.id;
        row.style.display = !q || n.includes(q) ? '' : 'none';
    });
}

// Search unmapped
document.getElementById('searchUnmapped')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.unmapped-emp-row').forEach(row => {
        row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Search mapped
document.getElementById('searchMapped')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.mapped-search-row').forEach(row => {
        row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});

// Search all ZK
document.getElementById('searchZk')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.zk-search-row').forEach(row => {
        row.style.display = !q || row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>

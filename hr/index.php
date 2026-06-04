<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

if (!has_role('admin') && !has_role('hr') && !has_role('finance')) {
    header('Location: /jemc/unauthorized.php'); exit();
}

// ── BS Date ───────────────────────────────────────────────────
use Administrator\Deno2\Shared\DateConverter;
$bsToday   = DateConverter::todayBs();               // '2083-02-17'
$bsParts   = explode('-', $bsToday);
$bsMonthNp = ['1'=>'बैशाख','2'=>'जेठ','3'=>'असार','4'=>'साउन','5'=>'भाद्र','6'=>'असोज','7'=>'कार्तिक','8'=>'मंसिर','9'=>'पुष','10'=>'माघ','11'=>'फाल्गुण','12'=>'चैत'];
$bsDayNp   = ['0'=>'आइतबार','1'=>'सोमबार','2'=>'मंगलबार','3'=>'बुधबार','4'=>'बिहिबार','5'=>'शुक्रबार','6'=>'शनिबार'];
$bsDateStr = (int)$bsParts[2] . ' ' . ($bsMonthNp[(string)(int)$bsParts[1]] ?? '') . ' ' . $bsParts[0]; // '17 जेठ 2083'
$bsDayName = $bsDayNp[date('w')]; // 'बिहिबार'
$adDateStr = date('l, d F Y');    // 'Thursday, 04 June 2026'

// ── Stats ─────────────────────────────────────────────────────
$empStats = $conn->query("
    SELECT
        COUNT(*)                                                      AS total,
        COUNT(*) FILTER (WHERE emp_status='ACTIVE')                   AS active,
        COUNT(*) FILTER (WHERE emp_status='DRAFT')                    AS draft,
        COUNT(*) FILTER (WHERE emp_status='RETIRED')                  AS retired,
        COUNT(*) FILTER (WHERE emp_type='PERMANENT' AND emp_status='ACTIVE') AS permanent,
        COUNT(*) FILTER (WHERE emp_type='CONTRACT'  AND emp_status='ACTIVE') AS contract,
        COUNT(*) FILTER (WHERE emp_type='DAILY_WAGES' AND emp_status='ACTIVE') AS daily_wages,
        COUNT(*) FILTER (WHERE is_technical=true AND emp_status='ACTIVE')     AS technical,
        COUNT(*) FILTER (WHERE is_ssf_enrolled=true AND emp_status='ACTIVE')  AS ssf_enrolled
    FROM employee WHERE deleted_date IS NULL
")->fetch(PDO::FETCH_ASSOC);

$attToday = $conn->prepare("
    SELECT
        COUNT(*)                                              AS marked,
        COUNT(*) FILTER (WHERE ast.status_code='P')          AS present,
        COUNT(*) FILTER (WHERE ast.status_code='A')          AS absent,
        COUNT(*) FILTER (WHERE ast.status_code IN ('SL','L')) AS sick,
        COUNT(*) FILTER (WHERE ast.status_code='HL')         AS home_leave,
        COUNT(*) FILTER (WHERE a.data_source='ZKTECO')       AS from_device
    FROM attendance a
    LEFT JOIN attendance_status ast ON a.status_id=ast.id
    WHERE a.attendance_date_eng=:d
");
$attToday->execute([':d' => date('Y-m-d')]);
$attStats = $attToday->fetch(PDO::FETCH_ASSOC);

$payrollStats = $conn->query("
    SELECT COUNT(*) AS runs,
           COALESCE(SUM(total_gross),0)       AS total_gross,
           COALESCE(SUM(total_net_payable),0) AS total_net,
           MAX(payroll_code)                  AS last_run
    FROM payroll_processing
")->fetch(PDO::FETCH_ASSOC);

$setupStats = [
    'departments'       => $conn->query("SELECT COUNT(*) FROM department WHERE status=true")->fetchColumn(),
    'designations'      => $conn->query("SELECT COUNT(*) FROM designation WHERE status=true")->fetchColumn(),
    'salary_components' => $conn->query("SELECT COUNT(*) FROM salary_components WHERE is_active=true")->fetchColumn(),
    'emp_with_salary'   => $conn->query("SELECT COUNT(*) FROM employee_salary WHERE is_current=true")->fetchColumn(),
    'salary_grades'     => $conn->query("SELECT COUNT(*) FROM salary_grades WHERE is_active=true")->fetchColumn(),
    'holidays'          => $conn->query("SELECT COUNT(*) FROM holidays WHERE fiscal_year=(SELECT fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1)")->fetchColumn(),
    'tax_slabs'         => $conn->query("SELECT COUNT(*) FROM tax_slabs WHERE is_active=true")->fetchColumn(),
    'ot_rules'          => $conn->query("SELECT COUNT(*) FROM ot_rules WHERE is_active=true")->fetchColumn(),
];

$zkStats = $conn->query("SELECT COUNT(*) FROM employee WHERE attendance_id IS NOT NULL AND attendance_id!='' AND deleted_date IS NULL")->fetchColumn();

// Recent employees
$recentEmp = $conn->query("
    SELECT e.id, e.code, e.name, e.emp_type, e.emp_status, e.created_date,
           d.name AS designation_name, dep.name AS dept_name
    FROM employee e
    LEFT JOIN designation d ON e.designation_id=d.id
    LEFT JOIN department dep ON e.department_id=dep.id
    WHERE e.deleted_date IS NULL
    ORDER BY e.created_date DESC NULLS LAST LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// Department headcount
$deptBreakdown = $conn->query("
    SELECT dep.name, COUNT(e.id) AS cnt
    FROM department dep
    LEFT JOIN employee e ON e.department_id=dep.id AND e.emp_status='ACTIVE' AND e.deleted_date IS NULL
    WHERE dep.status=true
    GROUP BY dep.id,dep.name HAVING COUNT(e.id)>0
    ORDER BY cnt DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);
$maxDept = $deptBreakdown ? max(array_column($deptBreakdown,'cnt')) : 1;

// Active fiscal year
$activeFY = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1")->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>HR Dashboard — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.hr-hero{background:linear-gradient(135deg,#1a5276 0%,#2c3e8c 50%,#6c5ce7 100%);color:#fff;padding:1.5rem 2rem;border-radius:0 0 20px 20px;margin-bottom:1.5rem}
.kpi{background:#fff;border-radius:10px;padding:1rem 1.2rem;display:flex;align-items:center;gap:.9rem;box-shadow:0 2px 8px rgba(0,0,0,.06);height:100%;border-left:4px solid #2c3e8c;transition:transform .15s,box-shadow .15s}
.kpi:hover{transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.1)}
.kpi-icon{width:44px;height:44px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.25rem;flex-shrink:0}
.kpi-val{font-size:1.6rem;font-weight:800;line-height:1;color:#2d3436}
.kpi-lbl{font-size:.67rem;font-weight:700;color:#8492a6;text-transform:uppercase;letter-spacing:.6px}
.kpi-sub{font-size:.68rem;color:#b2bec3}
.kpi.blue{border-left-color:#2c3e8c}.kpi.blue .kpi-icon{background:#eef2ff;color:#2c3e8c}
.kpi.green{border-left-color:#1a9e5f}.kpi.green .kpi-icon{background:#e6f9f1;color:#1a9e5f}
.kpi.orange{border-left-color:#e8a020}.kpi.orange .kpi-icon{background:#fff8ec;color:#e8a020}
.kpi.red{border-left-color:#d63031}.kpi.red .kpi-icon{background:#fff0f0;color:#d63031}
.kpi.purple{border-left-color:#6c5ce7}.kpi.purple .kpi-icon{background:#f1f0ff;color:#6c5ce7}
.kpi.teal{border-left-color:#00b894}.kpi.teal .kpi-icon{background:#e8fff9;color:#00b894}
.kpi.info{border-left-color:#0984e3}.kpi.info .kpi-icon{background:#e8f4ff;color:#0984e3}
.panel{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden}
.panel-hdr{padding:.65rem 1rem;border-bottom:1px solid #f0f2f8;display:flex;align-items:center;justify-content:space-between}
.panel-hdr h6{margin:0;font-weight:800;font-size:.82rem}
.menu-card{background:#fff;border-radius:10px;padding:1rem;display:flex;flex-direction:column;align-items:center;justify-content:center;text-align:center;border:1px solid #f0f2f8;text-decoration:none;transition:all .15s;height:100%;min-height:90px;box-shadow:0 2px 6px rgba(0,0,0,.04)}
.menu-card:hover{transform:translateY(-3px);box-shadow:0 6px 18px rgba(0,0,0,.09);border-color:#2c3e8c}
.menu-card i{font-size:1.6rem;margin-bottom:.4rem}
.menu-card span{font-size:.75rem;font-weight:700;color:#2d3436}
.menu-card small{font-size:.65rem;color:#8492a6;margin-top:1px}
.hbar{height:6px;background:#f0f2f8;border-radius:3px;overflow:hidden;margin-top:3px}
.hbar-fill{height:100%;border-radius:3px;background:linear-gradient(90deg,#2c3e8c,#6c5ce7);opacity:.8}
.sl{font-size:.68rem}
.status-ACTIVE{color:#1a9e5f;font-weight:700}
.status-DRAFT{color:#e8a020}
.status-RETIRED{color:#0984e3}
.status-INACTIVE{color:#636e72}
</style>
</head>
<body>

<!-- HR Hero Header -->
<div class="hr-hero">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h3 class="mb-0 fw-bold"><i class="fas fa-users me-2"></i>HR Dashboard</h3>
            <div style="opacity:.85;font-size:.85rem;margin-top:3px">
                जनक शिक्षा सामग्री केन्द्र · आ.व. <strong><?= htmlspecialchars($activeFY ?? 'N/A') ?></strong>
            </div>
            <div class="d-flex gap-3 mt-1 flex-wrap" style="font-size:.8rem;opacity:.8">
                <span><i class="fas fa-calendar-alt me-1"></i>
                    <strong><?= $bsDayName ?></strong>, <?= $bsDateStr ?>
                    <span style="opacity:.6;margin:0 4px">|</span>
                    <?= $adDateStr ?>
                </span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/jemc/hr/employee/create_enhanced.php" class="btn btn-sm btn-light fw-semibold">
                <i class="fas fa-user-plus me-1"></i>Add Employee
            </a>
            <a href="/jemc/hr/modules/payroll/process.php" class="btn btn-sm btn-warning fw-semibold">
                <i class="fas fa-play me-1"></i>Run Payroll
            </a>
        </div>
    </div>
</div>

<div class="container-fluid px-4" style="max-width:1500px">

<!-- ══ WORKFORCE KPIs ══════════════════════════════════════════ -->
<p class="text-muted fw-bold mb-2" style="font-size:.68rem;text-transform:uppercase;letter-spacing:1px">
    <i class="fas fa-users me-1"></i>Workforce Overview
</p>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi blue">
            <div class="kpi-icon"><i class="fas fa-users"></i></div>
            <div><div class="kpi-lbl">Total</div><div class="kpi-val"><?= $empStats['total'] ?></div><div class="kpi-sub"><?= $empStats['active'] ?> active</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi green">
            <div class="kpi-icon"><i class="fas fa-id-badge"></i></div>
            <div><div class="kpi-lbl">Permanent</div><div class="kpi-val"><?= $empStats['permanent'] ?></div><div class="kpi-sub"><?= $empStats['contract'] ?> contract</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi purple">
            <div class="kpi-icon"><i class="fas fa-cpu"></i></div>
            <div><div class="kpi-lbl">Technical</div><div class="kpi-val"><?= $empStats['technical'] ?></div><div class="kpi-sub">skilled staff</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi teal">
            <div class="kpi-icon"><i class="fas fa-shield-alt"></i></div>
            <div><div class="kpi-lbl">SSF Enrolled</div><div class="kpi-val"><?= $empStats['ssf_enrolled'] ?></div><div class="kpi-sub">social security</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi orange">
            <div class="kpi-icon"><i class="fas fa-pencil-alt"></i></div>
            <div><div class="kpi-lbl">Draft</div><div class="kpi-val"><?= $empStats['draft'] ?></div><div class="kpi-sub">pending activation</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi info">
            <div class="kpi-icon"><i class="fas fa-fingerprint"></i></div>
            <div><div class="kpi-lbl">ZKTeco Linked</div><div class="kpi-val"><?= $zkStats ?></div><div class="kpi-sub">of <?= $empStats['active'] ?> active</div></div>
        </div>
    </div>
</div>

<!-- ══ ATTENDANCE + PAYROLL KPIs ═══════════════════════════════ -->
<p class="text-muted fw-bold mb-2" style="font-size:.68rem;text-transform:uppercase;letter-spacing:1px">
    <i class="fas fa-clock me-1"></i>Today's Attendance & Payroll
</p>
<div class="row g-3 mb-4">
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi green">
            <div class="kpi-icon"><i class="fas fa-user-check"></i></div>
            <div><div class="kpi-lbl">Present Today</div><div class="kpi-val"><?= $attStats['present'] ?></div><div class="kpi-sub"><?= $attStats['from_device'] ?> via ZKTeco</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi red">
            <div class="kpi-icon"><i class="fas fa-user-times"></i></div>
            <div><div class="kpi-lbl">Absent</div><div class="kpi-val"><?= $attStats['absent'] ?></div><div class="kpi-sub"><?= $attStats['sick'] ?> sick, <?= $attStats['home_leave'] ?> home leave</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi blue">
            <div class="kpi-icon"><i class="fas fa-calendar-check"></i></div>
            <div><div class="kpi-lbl">Marked Total</div><div class="kpi-val"><?= $attStats['marked'] ?></div><div class="kpi-sub">of <?= $empStats['active'] ?> active</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi orange">
            <div class="kpi-icon"><i class="fas fa-money-check-alt"></i></div>
            <div><div class="kpi-lbl">Payroll Runs</div><div class="kpi-val"><?= $payrollStats['runs'] ?></div><div class="kpi-sub"><?= $payrollStats['last_run'] ? htmlspecialchars($payrollStats['last_run']) : 'None yet' ?></div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi teal">
            <div class="kpi-icon"><i class="fas fa-cash-register"></i></div>
            <div><div class="kpi-lbl">Total Net Paid</div><div class="kpi-val"><?= $payrollStats['total_net']>0?'NPR '.number_format($payrollStats['total_net']/1000,0).'K':'—' ?></div><div class="kpi-sub">all runs combined</div></div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi purple">
            <div class="kpi-icon"><i class="fas fa-cogs"></i></div>
            <div><div class="kpi-lbl">Salary Records</div><div class="kpi-val"><?= $setupStats['emp_with_salary'] ?></div><div class="kpi-sub">of <?= $empStats['active'] ?> need salary</div></div>
        </div>
    </div>
</div>

<!-- ══ MAIN MENU GRID ═══════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- EMPLOYEE MANAGEMENT -->
    <div class="col-md-6 col-xl-3">
        <div class="panel h-100">
            <div class="panel-hdr" style="background:#eef2ff">
                <h6 style="color:#2c3e8c"><i class="fas fa-users me-1"></i>Employee Management</h6>
                <span class="badge bg-primary" style="font-size:.65rem"><?= $empStats['active'] ?> active</span>
            </div>
            <div class="p-3">
                <div class="row g-2">
                    <?php $empMenus=[
                        ['/jemc/hr/employee/index.php',         'fas fa-list',        '#2c3e8c', 'Employee List',    $empStats['active'].' active'],
                        ['/jemc/hr/employee/create_enhanced.php','fas fa-user-plus',  '#1a9e5f', 'Add Employee',     'Create new'],
                        ['/jemc/hr/employee/department/index.php','fas fa-diagram-3', '#6c5ce7', 'Departments',      $setupStats['departments'].' depts'],
                        ['/jemc/hr/setup/salary.php?tab=grades', 'fas fa-layer-group','#e8a020', 'Salary Grades',    $setupStats['salary_grades'].' grades'],
                        ['/jemc/hr/setup/salary.php',           'fas fa-sliders-h',  '#0984e3', 'Salary Setup',     $setupStats['emp_with_salary'].' set'],
                        ['/jemc/hr/setup/index.php',            'fas fa-cogs',       '#636e72', 'HR Setup',         'All settings'],
                    ];
                    foreach($empMenus as [$url,$icon,$color,$label,$sub]): ?>
                    <div class="col-6">
                        <a href="<?= $url ?>" class="menu-card">
                            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i>
                            <span><?= $label ?></span>
                            <small><?= $sub ?></small>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- ATTENDANCE -->
    <div class="col-md-6 col-xl-3">
        <div class="panel h-100">
            <div class="panel-hdr" style="background:#e6f9f1">
                <h6 style="color:#1a9e5f"><i class="fas fa-fingerprint me-1"></i>Attendance</h6>
                <span class="badge bg-success" style="font-size:.65rem"><?= $attStats['present'] ?> present</span>
            </div>
            <div class="p-3">
                <div class="row g-2">
                    <?php $attMenus=[
                        ['/jemc/attendance_device/zkteco_live.php',  'fas fa-fingerprint',  '#6c5ce7', 'ZKTeco Live',     'Live data'],
                        ['/jemc/attendance_device/zkteco_mapping.php','fas fa-link',        '#2c3e8c', 'Device Mapping',  $zkStats.' linked'],
                        ['/jemc/attendance_device/device_users.php', 'bi bi-hdd-network',   '#0984e3', 'Device Users',    'Enroll/Delete'],
                        ['/jemc/hr/modules/attendance/mark.php',     'fas fa-check-square', '#1a9e5f', 'Mark Attendance', 'Manual entry'],
                        ['/jemc/hr/reports/hajiri_vivaran.php',      'fas fa-table',        '#e8a020', 'हाजिरी विवरण',     'Monthly sheet'],
                        ['/jemc/attendance_device/attendance_report.php','fas fa-file-pdf', '#d63031', 'Att. Report PDF', 'Daily/Monthly'],
                    ];
                    foreach($attMenus as [$url,$icon,$color,$label,$sub]): ?>
                    <div class="col-6">
                        <a href="<?= $url ?>" class="menu-card">
                            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i>
                            <span><?= $label ?></span>
                            <small><?= $sub ?></small>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- PAYROLL & TAX -->
    <div class="col-md-6 col-xl-3">
        <div class="panel h-100">
            <div class="panel-hdr" style="background:#fff8ec">
                <h6 style="color:#e8a020"><i class="fas fa-money-check-alt me-1"></i>Payroll & Tax</h6>
                <span class="badge bg-warning text-dark" style="font-size:.65rem"><?= $payrollStats['runs'] ?> runs</span>
            </div>
            <div class="p-3">
                <div class="row g-2">
                    <?php $payMenus=[
                        ['/jemc/hr/modules/payroll/process.php',  'fas fa-play-circle',   '#1a9e5f', 'Run Payroll',      'Generate'],
                        ['/jemc/hr/reports/talab_report.php',     'fas fa-file-invoice',  '#2c3e8c', 'Talab Report',     'Salary sheet'],
                        ['/jemc/hr/setup/index.php?tab=tax',      'fas fa-percent',       '#6c5ce7', 'Tax Slabs',        $setupStats['tax_slabs'].' slabs'],
                        ['/jemc/hr/setup/index.php?tab=statutory','fas fa-shield-alt',    '#0984e3', 'SSF / PF Rates',   'Statutory'],
                        ['/jemc/hr/setup/index.php?tab=salaries', 'fas fa-wallet',        '#e8a020', 'Employee Salaries','Setup pay'],
                        ['/jemc/hr/setup/index.php?tab=components','fas fa-list-ul',      '#d63031', 'Salary Components',$setupStats['salary_components'].' comps'],
                    ];
                    foreach($payMenus as [$url,$icon,$color,$label,$sub]): ?>
                    <div class="col-6">
                        <a href="<?= $url ?>" class="menu-card">
                            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i>
                            <span><?= $label ?></span>
                            <small><?= $sub ?></small>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

    <!-- LEAVE & HOLIDAYS -->
    <div class="col-md-6 col-xl-3">
        <div class="panel h-100">
            <div class="panel-hdr" style="background:#fff0f0">
                <h6 style="color:#d63031"><i class="fas fa-calendar-times me-1"></i>Leave & Holidays</h6>
                <span class="badge bg-danger" style="font-size:.65rem"><?= $setupStats['holidays'] ?> holidays</span>
            </div>
            <div class="p-3">
                <div class="row g-2">
                    <?php $leaveMenus=[
                        ['/jemc/hr/modules/leaves/apply.php',          'fas fa-envelope',     '#2c3e8c', 'Apply Leave',     'Submit request'],
                        ['/jemc/hr/setup/index.php?tab=holidays',      'fas fa-calendar-times','#d63031', 'Holidays',        $setupStats['holidays'].' this FY'],
                        ['/jemc/hr/setup/index.php?tab=holiday_types', 'fas fa-tags',         '#6c5ce7', 'Holiday Types',   'Configure'],
                        ['/jemc/hr/setup/index.php?tab=ot_rules',      'fas fa-clock',        '#e8a020', 'OT Rules',        $setupStats['ot_rules'].' rules'],
                        ['/jemc/hr/setup/index.php?tab=flow',          'fas fa-project-diagram','#1a9e5f','Payroll Flow',   'Setup guide'],
                        ['/jemc/hr/setup/index.php?tab=overview',      'fas fa-th-large',     '#0984e3', 'Setup Overview',  'All configs'],
                    ];
                    foreach($leaveMenus as [$url,$icon,$color,$label,$sub]): ?>
                    <div class="col-6">
                        <a href="<?= $url ?>" class="menu-card">
                            <i class="<?= $icon ?>" style="color:<?= $color ?>"></i>
                            <span><?= $label ?></span>
                            <small><?= $sub ?></small>
                        </a>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>

</div><!-- /menu grid -->

<!-- ══ DETAIL PANELS ════════════════════════════════════════════ -->
<div class="row g-3 mb-4">

    <!-- Department Breakdown -->
    <div class="col-md-4">
        <div class="panel h-100">
            <div class="panel-hdr">
                <h6><i class="fas fa-diagram-3 me-1" style="color:#6c5ce7"></i>Department Headcount</h6>
                <a href="/jemc/hr/employee/department/index.php" style="font-size:.72rem">Manage →</a>
            </div>
            <div class="p-3">
                <?php foreach($deptBreakdown as $d): $pct=round($d['cnt']/$maxDept*100); ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between sl">
                        <span style="max-width:78%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($d['name']) ?></span>
                        <strong><?= $d['cnt'] ?></strong>
                    </div>
                    <div class="hbar"><div class="hbar-fill" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Setup Checklist -->
    <div class="col-md-4">
        <div class="panel h-100">
            <div class="panel-hdr">
                <h6><i class="fas fa-tasks me-1" style="color:#1a9e5f"></i>Payroll Readiness</h6>
                <a href="/jemc/hr/setup/index.php" style="font-size:.72rem">Setup →</a>
            </div>
            <div class="p-3">
            <?php
            $payrollChecks = [
                [$empStats['active'] > 0,                           $empStats['active'].' active employees',            '/jemc/hr/employee/index.php'],
                [$setupStats['emp_with_salary'] > 0,                $setupStats['emp_with_salary'].' employees have salary set', '/jemc/hr/setup/index.php?tab=salaries'],
                [$setupStats['tax_slabs'] > 0,                      $setupStats['tax_slabs'].' tax slabs configured',   '/jemc/hr/setup/index.php?tab=tax'],
                [$setupStats['salary_grades'] > 0,                  $setupStats['salary_grades'].' salary grades defined', '/jemc/hr/setup/salary.php'],
                [$setupStats['holidays'] > 0,                       $setupStats['holidays'].' holidays this FY',        '/jemc/hr/setup/index.php?tab=holidays'],
                [$setupStats['ot_rules'] > 0,                       $setupStats['ot_rules'].' OT rules active',         '/jemc/hr/setup/index.php?tab=ot_rules'],
                [$zkStats >= 10,                                     $zkStats.' employees linked to ZKTeco',             '/jemc/attendance_device/zkteco_mapping.php'],
                [$payrollStats['runs'] > 0,                         $payrollStats['runs'].' payroll runs completed',    '/jemc/hr/modules/payroll/process.php'],
            ];
            $done = count(array_filter($payrollChecks, fn($c)=>$c[0]));
            ?>
            <div class="d-flex justify-content-between align-items-center mb-2">
                <span class="fw-semibold" style="font-size:.8rem"><?= $done ?>/<?= count($payrollChecks) ?> steps complete</span>
                <span class="badge <?= $done===count($payrollChecks)?'bg-success':($done>=5?'bg-warning text-dark':'bg-danger') ?>">
                    <?= $done===count($payrollChecks)?'Ready':($done>=5?'Almost':'Incomplete') ?>
                </span>
            </div>
            <div class="hbar mb-3"><div class="hbar-fill" style="width:<?= round($done/count($payrollChecks)*100) ?>%;background:<?= $done===count($payrollChecks)?'#1a9e5f':($done>=5?'#e8a020':'#d63031') ?>!important;opacity:1"></div></div>
            <?php foreach($payrollChecks as [$ok,$label,$link]): ?>
            <div class="d-flex align-items-center gap-2 mb-1" style="font-size:.76rem">
                <?= $ok ? '<i class="fas fa-check-circle text-success"></i>' : '<i class="fas fa-times-circle text-danger"></i>' ?>
                <a href="<?= $link ?>" class="text-decoration-none <?= $ok?'text-muted':'text-danger fw-semibold' ?>">
                    <?= htmlspecialchars($label) ?>
                </a>
            </div>
            <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Recent Employees -->
    <div class="col-md-4">
        <div class="panel h-100">
            <div class="panel-hdr">
                <h6><i class="fas fa-user-clock me-1" style="color:#0984e3"></i>Recently Added</h6>
                <a href="/jemc/hr/employee/index.php" style="font-size:.72rem">All →</a>
            </div>
            <div style="max-height:320px;overflow-y:auto">
            <table class="table table-sm table-hover mb-0" style="font-size:.76rem">
                <tbody>
                <?php foreach($recentEmp as $r): ?>
                <tr>
                    <td>
                        <a href="/jemc/hr/employee/profile.php?id=<?= $r['id'] ?>" class="text-decoration-none">
                            <div class="fw-semibold"><?= htmlspecialchars($r['name']) ?></div>
                            <div class="text-muted" style="font-size:.68rem"><code><?= $r['code'] ?></code> · <?= htmlspecialchars($r['designation_name'] ?? '') ?></div>
                        </a>
                    </td>
                    <td class="text-end">
                        <span class="badge bg-light text-dark border" style="font-size:.6rem"><?= $r['emp_type'] ?></span><br>
                        <small class="text-muted" style="font-size:.65rem"><?= $r['created_date'] ? date('d M Y', strtotime($r['created_date'])) : '' ?></small>
                    </td>
                </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
            </div>
            <div class="p-2 border-top text-center">
                <a href="/jemc/hr/employee/create_enhanced.php" class="btn btn-primary btn-sm w-100">
                    <i class="fas fa-user-plus me-1"></i>Add New Employee
                </a>
            </div>
        </div>
    </div>

</div>

</div><!-- /container -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>

<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

if (!has_role('admin') && !has_role('hr') && !has_role('finance')) {
    header('Location: /jemc/unauthorized.php'); exit();
}

$tab = $_GET['tab'] ?? 'overview';
$msg = '';
$err = '';

// ── Shared lookups ────────────────────────────────────────────
$fiscalYears   = $conn->query("SELECT id, fiscal_code, is_active FROM fiscal_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$activeFY      = $conn->query("SELECT id, fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$holidayTypes  = $conn->query("SELECT * FROM holiday_types ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// ══ POST HANDLERS ═════════════════════════════════════════════

// ── 1. Save Employee Salary ───────────────────────────────────
if ($_POST['action'] ?? '' === 'save_salary') {
    try {
        $empId  = (int)$_POST['employee_id'];
        $basic  = (float)$_POST['basic_salary'];
        $mode   = $_POST['salary_mode'] ?? 'MONTHLY';
        $from   = $_POST['effective_from'];
        // Mark old as not current
        $conn->prepare("UPDATE employee_salary SET is_current=false, effective_to=:d WHERE employee_id=:e AND is_current=true")
             ->execute([':d'=>$from, ':e'=>$empId]);
        // Insert new
        $conn->prepare("INSERT INTO employee_salary (employee_id,basic_salary,salary_mode,effective_from,is_current,created_by,created_at)
                        VALUES (:e,:b,:m,:f,true,:u,NOW())")
             ->execute([':e'=>$empId,':b'=>$basic,':m'=>$mode,':f'=>$from,':u'=>$_SESSION['user_id']??0]);
        // Also update SSF/taxpayer flags on employee
        $conn->prepare("UPDATE employee SET is_ssf_enrolled=:ssf, taxpayer_type=:tt WHERE id=:e")
             ->execute([':ssf'=>isset($_POST['is_ssf_enrolled'])?'true':'false',':tt'=>$_POST['taxpayer_type']??'SINGLE',':e'=>$empId]);
        $msg = "Salary saved for employee.";
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// ── 2. Save Holiday ───────────────────────────────────────────
if ($_POST['action'] ?? '' === 'save_holiday') {
    try {
        $hId = (int)($_POST['holiday_id'] ?? 0);
        if ($hId) {
            $conn->prepare("UPDATE holidays SET holiday_name=:n, holiday_date_nep=:nep, holiday_date_eng=:eng,
                holiday_type_id=:t, fiscal_year=:fy, is_active=:a, remarks=:r, updated_at=NOW()
                WHERE id=:id")
                ->execute([':n'=>$_POST['holiday_name'],':nep'=>$_POST['holiday_date_nep'],':eng'=>$_POST['holiday_date_eng'],
                           ':t'=>$_POST['holiday_type_id'],':fy'=>$_POST['fiscal_year'],':a'=>isset($_POST['is_active'])? 'true':'false',
                           ':r'=>$_POST['remarks']??'',':id'=>$hId]);
            $msg = "Holiday updated.";
        } else {
            $conn->prepare("INSERT INTO holidays (holiday_name,holiday_date_nep,holiday_date_eng,holiday_type_id,fiscal_year,is_active,remarks,created_by,created_at,updated_at)
                VALUES (:n,:nep,:eng,:t,:fy,:a,:r,:u,NOW(),NOW())")
                ->execute([':n'=>$_POST['holiday_name'],':nep'=>$_POST['holiday_date_nep'],':eng'=>$_POST['holiday_date_eng'],
                           ':t'=>$_POST['holiday_type_id'],':fy'=>$_POST['fiscal_year'],':a'=>isset($_POST['is_active'])? 'true':'false',
                           ':r'=>$_POST['remarks']??'',':u'=>$_SESSION['user_id']??0]);
            $msg = "Holiday added.";
        }
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// ── 3. Delete Holiday ─────────────────────────────────────────
if (($_POST['action'] ?? '') === 'delete_holiday') {
    $conn->prepare("DELETE FROM holidays WHERE id=:id")->execute([':id'=>(int)$_POST['holiday_id']]);
    $msg = "Holiday deleted.";
}

// ── 4. Save OT Rule ───────────────────────────────────────────
if ($_POST['action'] ?? '' === 'save_ot_rule') {
    try {
        $rId = (int)($_POST['rule_id'] ?? 0);
        if ($rId) {
            $conn->prepare("UPDATE ot_rules SET rule_name=:n,day_type=:d,min_hours_for_ot=:min,ot_rate=:r,
                max_ot_hours_per_day=:max,requires_approval=:a,is_active=:ia WHERE id=:id")
                ->execute([':n'=>$_POST['rule_name'],':d'=>$_POST['day_type'],':min'=>$_POST['min_hours'],
                           ':r'=>$_POST['ot_rate'],':max'=>$_POST['max_hours'],':a'=>isset($_POST['requires_approval'])?'true':'false',
                           ':ia'=>isset($_POST['is_active'])?'true':'false',':id'=>$rId]);
        } else {
            $conn->prepare("INSERT INTO ot_rules (rule_name,day_type,min_hours_for_ot,ot_rate,max_ot_hours_per_day,requires_approval,is_active,effective_from)
                VALUES (:n,:d,:min,:r,:max,:a,:ia,:ef)")
                ->execute([':n'=>$_POST['rule_name'],':d'=>$_POST['day_type'],':min'=>$_POST['min_hours'],
                           ':r'=>$_POST['ot_rate'],':max'=>$_POST['max_hours'],':a'=>isset($_POST['requires_approval'])?'true':'false',
                           ':ia'=>isset($_POST['is_active'])?'true':'false',':ef'=>date('Y-m-d')]);
        }
        $msg = "OT Rule saved.";
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// ── 5. Save Salary Component ──────────────────────────────────
if ($_POST['action'] ?? '' === 'save_component') {
    try {
        $cId = (int)($_POST['component_id'] ?? 0);
        if ($cId) {
            $conn->prepare("UPDATE salary_components SET component_name=:n,component_type=:ct,
                calculation_type=:calc,default_value=:val,is_taxable=:tx,is_active=:ia WHERE id=:id")
                ->execute([':n'=>$_POST['component_name'],':ct'=>$_POST['component_type'],':calc'=>$_POST['calculation_type'],
                           ':val'=>$_POST['default_value']??0,':tx'=>isset($_POST['is_taxable'])?'true':'false',
                           ':ia'=>isset($_POST['is_active'])?'true':'false',':id'=>$cId]);
        } else {
            $conn->prepare("INSERT INTO salary_components (component_code,component_name,component_type,calculation_type,default_value,is_taxable,is_active)
                VALUES (:code,:n,:ct,:calc,:val,:tx,:ia)")
                ->execute([':code'=>strtoupper(preg_replace('/[^A-Z0-9]/','',$_POST['component_code']??'')),
                           ':n'=>$_POST['component_name'],':ct'=>$_POST['component_type'],':calc'=>$_POST['calculation_type'],
                           ':val'=>$_POST['default_value']??0,':tx'=>isset($_POST['is_taxable'])?'true':'false',
                           ':ia'=>isset($_POST['is_active'])?'true':'false']);
        }
        $msg = "Salary component saved.";
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// ── Load tab data ─────────────────────────────────────────────
$holidays   = $conn->query("SELECT h.*,ht.type_name,ht.color_code FROM holidays h LEFT JOIN holiday_types ht ON h.holiday_type_id=ht.id ORDER BY h.holiday_date_eng DESC")->fetchAll(PDO::FETCH_ASSOC);
$otRules    = $conn->query("SELECT * FROM ot_rules ORDER BY day_type")->fetchAll(PDO::FETCH_ASSOC);
$components = $conn->query("SELECT * FROM salary_components ORDER BY component_type, component_code")->fetchAll(PDO::FETCH_ASSOC);
$taxSlabs   = $conn->query("SELECT ts.*,fy.fiscal_code FROM tax_slabs ts JOIN fiscal_years fy ON ts.fiscal_year_id=fy.id ORDER BY taxpayer_type,slab_order")->fetchAll(PDO::FETCH_ASSOC);
$statRates  = $conn->query("SELECT sr.*,fy.fiscal_code FROM statutory_rates sr JOIN fiscal_years fy ON sr.fiscal_year_id=fy.id ORDER BY rate_type")->fetchAll(PDO::FETCH_ASSOC);

// Employee salaries
$empSalaries= $conn->query("
    SELECT es.*,e.code,e.name,e.emp_type,e.is_ssf_enrolled,e.taxpayer_type,
           dep.name AS dept
    FROM employee_salary es
    JOIN employee e ON es.employee_id=e.id
    LEFT JOIN department dep ON e.department_id=dep.id
    WHERE es.is_current=true
    ORDER BY e.code
")->fetchAll(PDO::FETCH_ASSOC);

$empWithoutSalary = $conn->query("
    SELECT e.id,e.code,e.name,e.emp_type,dep.name AS dept
    FROM employee e
    LEFT JOIN department dep ON e.department_id=dep.id
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL
      AND e.id NOT IN (SELECT employee_id FROM employee_salary WHERE is_current=true)
    ORDER BY e.code
")->fetchAll(PDO::FETCH_ASSOC);

// All active employees for salary assignment
$allActiveEmp = $conn->query("SELECT id,code,name,emp_type FROM employee WHERE emp_status='ACTIVE' AND deleted_date IS NULL ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

// Payroll-Attendance flow stats
$flowStats = [
    'active_employees' => $conn->query("SELECT COUNT(*) FROM employee WHERE emp_status='ACTIVE' AND deleted_date IS NULL")->fetchColumn(),
    'with_salary'      => $conn->query("SELECT COUNT(*) FROM employee_salary WHERE is_current=true")->fetchColumn(),
    'ssf_enrolled'     => $conn->query("SELECT COUNT(*) FROM employee WHERE is_ssf_enrolled=true AND deleted_date IS NULL")->fetchColumn(),
    'tax_slabs'        => $conn->query("SELECT COUNT(*) FROM tax_slabs WHERE is_active=true")->fetchColumn(),
    'holidays_fy'      => $conn->query("SELECT COUNT(*) FROM holidays WHERE fiscal_year=(SELECT fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1)")->fetchColumn(),
    'ot_rules'         => $conn->query("SELECT COUNT(*) FROM ot_rules WHERE is_active=true")->fetchColumn(),
    'payroll_runs'     => $conn->query("SELECT COUNT(*) FROM payroll_processing")->fetchColumn(),
    'last_payroll'     => $conn->query("SELECT payroll_code FROM payroll_processing ORDER BY id DESC LIMIT 1")->fetchColumn(),
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Payroll & HR Setup — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.setup-nav .nav-link{color:#495057;border-radius:8px;margin-bottom:4px;padding:.5rem 1rem;font-size:.85rem}
.setup-nav .nav-link.active{background:#2c3e8c;color:#fff}
.setup-nav .nav-link i{width:20px}
.panel{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06)}
.flow-step{background:#fff;border-radius:10px;padding:1rem;border-left:4px solid #2c3e8c;margin-bottom:.75rem}
.flow-step.done{border-left-color:#1a9e5f}
.flow-step.warn{border-left-color:#e8a020}
.badge-ok{background:#e8f5e9;color:#1b5e20;padding:2px 8px;border-radius:4px;font-size:.7rem}
.badge-warn{background:#fff3e0;color:#e65100;padding:2px 8px;border-radius:4px;font-size:.7rem}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1400px">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0" style="color:#2c3e8c"><i class="fas fa-cogs me-2"></i>Payroll & HR Setup</h4>
        <small class="text-muted">Configure salary, holidays, OT rules, and tax settings</small>
    </div>
    <a href="/jemc/hr/modules/payroll/process.php" class="btn btn-primary btn-sm">
        <i class="fas fa-play me-1"></i>Run Payroll
    </a>
</div>

<?php if($msg): ?><div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-danger alert-dismissible fade show py-2"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-3">

<!-- LEFT NAV -->
<div class="col-md-2">
    <div class="panel p-3">
        <nav class="setup-nav nav flex-column">
            <a class="nav-link <?= $tab==='overview'?'active':'' ?>" href="?tab=overview"><i class="fas fa-th-large"></i> Overview</a>
            <hr class="my-2">
            <div style="font-size:.65rem;font-weight:700;color:#8492a6;text-transform:uppercase;padding:0 1rem .3rem">Payroll Setup</div>
            <a class="nav-link <?= $tab==='salaries'?'active':'' ?>" href="?tab=salaries"><i class="fas fa-money-bill-wave"></i> Employee Salaries</a>
            <a class="nav-link <?= $tab==='components'?'active':'' ?>" href="?tab=components"><i class="fas fa-list"></i> Salary Components</a>
            <a class="nav-link <?= $tab==='tax'?'active':'' ?>" href="?tab=tax"><i class="fas fa-percent"></i> Tax Slabs</a>
            <a class="nav-link <?= $tab==='statutory'?'active':'' ?>" href="?tab=statutory"><i class="fas fa-shield-alt"></i> SSF / PF Rates</a>
            <a class="nav-link <?= $tab==='ot_rules'?'active':'' ?>" href="?tab=ot_rules"><i class="fas fa-clock"></i> OT Rules</a>
            <hr class="my-2">
            <div style="font-size:.65rem;font-weight:700;color:#8492a6;text-transform:uppercase;padding:0 1rem .3rem">Attendance</div>
            <a class="nav-link <?= $tab==='holidays'?'active':'' ?>" href="?tab=holidays"><i class="fas fa-calendar-times"></i> Holidays</a>
            <a class="nav-link <?= $tab==='holiday_types'?'active':'' ?>" href="?tab=holiday_types"><i class="fas fa-tags"></i> Holiday Types</a>
            <a class="nav-link <?= $tab==='flow'?'active':'' ?>" href="?tab=flow"><i class="fas fa-project-diagram"></i> Att → Payroll Flow</a>
        </nav>
    </div>
</div>

<!-- MAIN CONTENT -->
<div class="col-md-10">

<?php if ($tab === 'overview'): ?>
<!-- ══ OVERVIEW ══════════════════════════════════════════════ -->
<div class="row g-3 mb-3">
    <?php $cards=[
        ['Employee Salaries Set', $flowStats['with_salary'].'/'.$flowStats['active_employees'], $flowStats['with_salary']==$flowStats['active_employees']?'ok':'warn', 'fas fa-money-bill-wave','salaries'],
        ['SSF Enrolled', $flowStats['ssf_enrolled'].' employees', 'ok','fas fa-shield-alt','salaries'],
        ['Tax Slabs (Active)', $flowStats['tax_slabs'].' slabs', $flowStats['tax_slabs']>0?'ok':'warn','fas fa-percent','tax'],
        ['Holidays (This FY)', $flowStats['holidays_fy'].' days', $flowStats['holidays_fy']>0?'ok':'warn','fas fa-calendar-times','holidays'],
        ['OT Rules', $flowStats['ot_rules'].' active', 'ok','fas fa-clock','ot_rules'],
        ['Payroll Runs', $flowStats['payroll_runs'].' total', 'ok','fas fa-receipt',''],
    ];
    foreach($cards as [$label,$val,$status,$icon,$link]): ?>
    <div class="col-md-4 col-xl-2">
        <a href="<?= $link?"?tab=$link":'#' ?>" class="text-decoration-none">
        <div class="panel p-3 text-center h-100" style="border-top:3px solid <?= $status==='ok'?'#1a9e5f':'#e8a020' ?>">
            <i class="<?= $icon ?>" style="font-size:1.5rem;color:<?= $status==='ok'?'#1a9e5f':'#e8a020' ?>"></i>
            <div class="fw-bold mt-1" style="font-size:1rem;color:#2d3436"><?= $val ?></div>
            <div style="font-size:.72rem;color:#8492a6"><?= $label ?></div>
        </div>
        </a>
    </div>
    <?php endforeach; ?>
</div>

<!-- Payroll generation flow -->
<div class="panel p-4">
    <h5 class="fw-bold mb-3"><i class="fas fa-project-diagram me-2"></i>Payroll Generation Flow</h5>
    <div class="row g-3">
        <?php $steps=[
            [1,'Employee Setup','Set emp_type, is_ssf_enrolled, taxpayer_type',$flowStats['active_employees'].' active','salaries','ok'],
            [2,'Salary Setup','Enter basic salary for each employee',$flowStats['with_salary'].'/'.$flowStats['active_employees'].' done','salaries',$flowStats['with_salary']>0?'ok':'warn'],
            [3,'Tax & SSF Rates','Verify tax slabs & statutory rates',$flowStats['tax_slabs'].' slabs configured','tax','ok'],
            [4,'Holiday Calendar','Add public holidays (excluded from working days)',$flowStats['holidays_fy'].' holidays this FY','holidays',$flowStats['holidays_fy']>0?'ok':'warn'],
            [5,'OT Rules','Define overtime multipliers per day type',$flowStats['ot_rules'].' rules active','ot_rules','ok'],
            [6,'Pull Attendance','ZKTecePuller pulls daily punch records','Runs 5× daily automatically','flow','ok'],
            [7,'Generate Payroll','Process payroll for selected BS month',$flowStats['payroll_runs'].' runs done','','ok'],
        ];
        foreach($steps as [$num,$title,$desc,$stat,$link,$status]): ?>
        <div class="col-md-6">
            <div class="flow-step <?= $status ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div class="d-flex gap-2">
                        <div style="width:28px;height:28px;border-radius:50%;background:<?= $status==='ok'?'#1a9e5f':'#e8a020' ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.8rem;font-weight:700;flex-shrink:0"><?= $num ?></div>
                        <div>
                            <div class="fw-bold" style="font-size:.88rem"><?= $title ?></div>
                            <div style="font-size:.75rem;color:#636e72"><?= $desc ?></div>
                        </div>
                    </div>
                    <div class="text-end">
                        <span class="badge-<?= $status ?>"><?= $stat ?></span>
                        <?php if($link): ?>
                        <br><a href="?tab=<?= $link ?>" style="font-size:.68rem">Setup →</a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<?php elseif ($tab === 'salaries'): ?>
<!-- ══ EMPLOYEE SALARIES ════════════════════════════════════ -->
<div class="row g-3">
    <div class="col-md-8">
        <div class="panel">
            <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
                <h6 class="mb-0 fw-bold"><i class="fas fa-money-bill-wave me-1"></i>Employee Salaries (<?= count($empSalaries) ?> set)</h6>
                <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#salaryModal">
                    <i class="fas fa-plus me-1"></i>Add/Update Salary
                </button>
            </div>
            <?php if(!empty($empWithoutSalary)): ?>
            <div class="alert alert-warning py-2 m-2 mb-0" style="font-size:.8rem">
                <i class="fas fa-exclamation-triangle me-1"></i>
                <strong><?= count($empWithoutSalary) ?> employees</strong> have no salary set and will be excluded from payroll.
            </div>
            <?php endif; ?>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
                    <thead class="table-light">
                        <tr><th>Code</th><th>Name</th><th>Type</th><th>Dept</th><th class="text-end">Basic Salary</th><th>Mode</th><th>SSF</th><th>Tax Type</th><th>From</th></tr>
                    </thead>
                    <tbody>
                    <?php foreach($empSalaries as $s): ?>
                    <tr>
                        <td><code style="font-size:.72rem"><?= htmlspecialchars($s['code']) ?></code></td>
                        <td><?= htmlspecialchars($s['name']) ?></td>
                        <td><span class="badge bg-secondary" style="font-size:.62rem"><?= $s['emp_type'] ?></span></td>
                        <td><small class="text-muted"><?= htmlspecialchars($s['dept'] ?? '') ?></small></td>
                        <td class="text-end fw-semibold text-success">NPR <?= number_format($s['basic_salary'],2) ?></td>
                        <td><?= $s['salary_mode'] ?></td>
                        <td><?= $s['is_ssf_enrolled'] ? '<span class="badge bg-success" style="font-size:.6rem">Yes</span>' : '<span class="badge bg-secondary" style="font-size:.6rem">No</span>' ?></td>
                        <td><?= $s['taxpayer_type'] ?></td>
                        <td><small><?= $s['effective_from'] ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                    <?php if(empty($empSalaries)): ?>
                    <tr><td colspan="9" class="text-center py-4 text-muted">No salary records yet. Click "Add/Update Salary" to begin.</td></tr>
                    <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="panel p-3">
            <h6 class="fw-bold mb-3 text-warning"><i class="fas fa-exclamation-triangle me-1"></i>No Salary Set (<?= count($empWithoutSalary) ?>)</h6>
            <div style="max-height:400px;overflow-y:auto">
            <?php foreach($empWithoutSalary as $e): ?>
            <div class="d-flex justify-content-between align-items-center py-1 border-bottom">
                <div style="font-size:.78rem">
                    <code style="font-size:.7rem"><?= htmlspecialchars($e['code']) ?></code>
                    <?= htmlspecialchars($e['name']) ?><br>
                    <small class="text-muted"><?= htmlspecialchars($e['dept'] ?? '') ?> · <?= $e['emp_type'] ?></small>
                </div>
                <button class="btn btn-xs btn-outline-primary" style="font-size:.65rem;padding:2px 8px"
                        onclick="openSalaryModal(<?= $e['id'] ?>, '<?= htmlspecialchars($e['name']) ?>')">
                    Set Salary
                </button>
            </div>
            <?php endforeach; ?>
            <?php if(empty($empWithoutSalary)): ?>
            <div class="text-center py-3 text-success"><i class="fas fa-check-circle me-1"></i>All employees have salary records!</div>
            <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php elseif ($tab === 'holidays'): ?>
<!-- ══ HOLIDAYS ══════════════════════════════════════════════ -->
<div class="panel">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold"><i class="fas fa-calendar-times me-1"></i>Holidays (<?= count($holidays) ?> total)</h6>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#holidayModal">
            <i class="fas fa-plus me-1"></i>Add Holiday
        </button>
    </div>
    <!-- Filter by FY -->
    <div class="px-3 py-2 border-bottom bg-light">
        <form method="GET" class="d-flex gap-2 align-items-center">
            <input type="hidden" name="tab" value="holidays">
            <label class="small fw-semibold mb-0">Fiscal Year:</label>
            <select name="fy_filter" class="form-select form-select-sm" style="width:160px" onchange="this.form.submit()">
                <option value="">All Years</option>
                <?php foreach($fiscalYears as $fy): ?>
                <option value="<?= $fy['fiscal_code'] ?>" <?= ($_GET['fy_filter']??'')===$fy['fiscal_code']?'selected':'' ?>>
                    <?= $fy['fiscal_code'] ?><?= $fy['is_active']?' (Active)':'' ?>
                </option>
                <?php endforeach; ?>
            </select>
            <span class="text-muted small"><?= count($holidays) ?> holidays</span>
        </form>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
            <thead class="table-light">
                <tr><th>BS Date</th><th>AD Date</th><th>Holiday Name</th><th>Type</th><th>FY</th><th>Status</th><th width="80">Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach($holidays as $h):
                if (($_GET['fy_filter']??'') && $h['fiscal_year']!==($_GET['fy_filter']??'')) continue;
            ?>
            <tr>
                <td><strong><?= htmlspecialchars($h['holiday_date_nep']) ?></strong></td>
                <td><?= date('d M Y', strtotime($h['holiday_date_eng'])) ?></td>
                <td><?= htmlspecialchars($h['holiday_name']) ?></td>
                <td>
                    <?php if($h['color_code']): ?>
                    <span style="background:<?= htmlspecialchars($h['color_code']) ?>;color:#fff;padding:2px 7px;border-radius:4px;font-size:.68rem">
                        <?= htmlspecialchars($h['type_name'] ?? '') ?>
                    </span>
                    <?php endif; ?>
                </td>
                <td><?= $h['fiscal_year'] ?></td>
                <td><?= $h['is_active'] ? '<span class="badge bg-success" style="font-size:.62rem">Active</span>' : '<span class="badge bg-secondary" style="font-size:.62rem">Inactive</span>' ?></td>
                <td>
                    <button class="btn btn-xs btn-outline-primary me-1" style="font-size:.65rem"
                            onclick='editHoliday(<?= json_encode($h) ?>)'>Edit</button>
                    <form method="POST" style="display:inline" onsubmit="return confirm('Delete this holiday?')">
                        <input type="hidden" name="action" value="delete_holiday">
                        <input type="hidden" name="holiday_id" value="<?= $h['id'] ?>">
                        <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.65rem">Del</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'holiday_types'): ?>
<!-- ══ HOLIDAY TYPES ════════════════════════════════════════ -->
<div class="panel">
    <div class="px-3 py-2 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-tags me-1"></i>Holiday Types</h6></div>
    <div class="table-responsive">
        <table class="table table-sm mb-0" style="font-size:.82rem">
            <thead class="table-light">
                <tr><th>Type</th><th>Description</th><th>Paid?</th><th>Color</th></tr>
            </thead>
            <tbody>
            <?php foreach($holidayTypes as $t): ?>
            <tr>
                <td><strong><?= htmlspecialchars($t['type_name']) ?></strong></td>
                <td><small class="text-muted"><?= htmlspecialchars($t['description'] ?? '') ?></small></td>
                <td><?= $t['is_paid'] ? '<span class="badge bg-success" style="font-size:.62rem">Paid</span>' : '<span class="badge bg-secondary" style="font-size:.62rem">Unpaid</span>' ?></td>
                <td><span style="background:<?= $t['color_code'] ?>;color:#fff;padding:2px 12px;border-radius:4px;font-size:.7rem"><?= $t['color_code'] ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'ot_rules'): ?>
<!-- ══ OT RULES ═════════════════════════════════════════════ -->
<div class="panel">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-clock me-1"></i>Overtime Rules</h6>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#otModal">
            <i class="fas fa-plus me-1"></i>Add Rule
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
            <thead class="table-light">
                <tr><th>Rule Name</th><th>Day Type</th><th>Min Hours for OT</th><th>OT Rate</th><th>Max OT/Day</th><th>Approval Req?</th><th>Active</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach($otRules as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['rule_name']) ?></strong></td>
                <td><span class="badge bg-info text-dark" style="font-size:.65rem"><?= $r['day_type'] ?></span></td>
                <td><?= $r['min_hours_for_ot'] ?>h</td>
                <td><strong class="text-success"><?= $r['ot_rate'] ?>×</strong> hourly rate</td>
                <td><?= $r['max_ot_hours_per_day'] ?>h</td>
                <td><?= $r['requires_approval'] ? '✓ Yes' : '✗ No' ?></td>
                <td><?= $r['is_active'] ? '<span class="badge bg-success" style="font-size:.6rem">Active</span>' : '<span class="badge bg-secondary" style="font-size:.6rem">Off</span>' ?></td>
                <td>
                    <button class="btn btn-xs btn-outline-primary" style="font-size:.65rem"
                            onclick='editOTRule(<?= json_encode($r) ?>)'>Edit</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <div class="p-3 bg-light border-top" style="font-size:.78rem">
        <strong>How OT is calculated in payroll:</strong><br>
        Hourly Rate = Basic Salary ÷ (Working Days × 8 hours)<br>
        OT Amount = OT Hours × Hourly Rate × OT Rate multiplier
    </div>
</div>

<?php elseif ($tab === 'components'): ?>
<!-- ══ SALARY COMPONENTS ═════════════════════════════════════ -->
<div class="panel">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between">
        <h6 class="mb-0 fw-bold"><i class="fas fa-list me-1"></i>Salary Components</h6>
        <button class="btn btn-primary btn-sm" data-bs-toggle="modal" data-bs-target="#componentModal">
            <i class="fas fa-plus me-1"></i>Add Component
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.82rem">
            <thead class="table-light">
                <tr><th>Code</th><th>Name</th><th>Type</th><th>Calculation</th><th>Default Value</th><th>Taxable?</th><th>Active</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach($components as $c): ?>
            <tr>
                <td><code style="font-size:.72rem"><?= htmlspecialchars($c['component_code']) ?></code></td>
                <td><?= htmlspecialchars($c['component_name']) ?></td>
                <td>
                    <span class="badge" style="font-size:.62rem;background:<?= $c['component_type']==='EARNING'?'#e8f5e9;color:#1b5e20':($c['component_type']==='DEDUCTION'?'#fff3e0;color:#e65100':'#f3e5f5;color:#4a148c') ?>">
                        <?= $c['component_type'] ?>
                    </span>
                </td>
                <td><?= $c['calculation_type'] ?></td>
                <td><?= $c['default_value'] > 0 ? 'NPR '.number_format($c['default_value'],2) : '—' ?></td>
                <td><?= $c['is_taxable'] ? '<span class="text-danger">✓ Taxable</span>' : '<span class="text-muted">✗ Exempt</span>' ?></td>
                <td><?= $c['is_active'] ? '<span class="badge bg-success" style="font-size:.6rem">Active</span>' : '<span class="badge bg-secondary" style="font-size:.6rem">Off</span>' ?></td>
                <td>
                    <button class="btn btn-xs btn-outline-primary" style="font-size:.65rem"
                            onclick='editComponent(<?= json_encode($c) ?>)'>Edit</button>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'tax'): ?>
<!-- ══ TAX SLABS ════════════════════════════════════════════ -->
<div class="panel">
    <div class="px-3 py-2 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-percent me-1"></i>Income Tax Slabs (Nepal FY 2081/82)</h6></div>
    <?php foreach(['SINGLE','COUPLE'] as $type): ?>
    <div class="px-3 pt-3">
        <h6 class="fw-semibold"><?= $type ?> Taxpayer</h6>
        <table class="table table-sm table-bordered mb-3" style="font-size:.82rem">
            <thead class="table-light">
                <tr><th>#</th><th>Income From (NPR)</th><th>Income To (NPR)</th><th>Tax Rate</th><th>FY</th></tr>
            </thead>
            <tbody>
            <?php foreach($taxSlabs as $s):
                if($s['taxpayer_type']!==$type) continue; ?>
            <tr>
                <td><?= $s['slab_order'] ?></td>
                <td class="text-end"><?= number_format($s['income_from']) ?></td>
                <td class="text-end"><?= $s['income_to'] ? number_format($s['income_to']) : 'No limit' ?></td>
                <td class="text-center fw-bold text-primary"><?= ($s['tax_rate']*100) ?>%</td>
                <td><?= $s['fiscal_code'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    <div class="px-3 pb-3 text-muted" style="font-size:.78rem">
        <i class="fas fa-info-circle me-1"></i>
        Tax slabs are applied annually. Monthly TDS = Annual Tax ÷ 12.
        Taxable Income = Gross − SSF Employee − PF Employee.
        Verify with IRD each fiscal year.
    </div>
</div>

<?php elseif ($tab === 'statutory'): ?>
<!-- ══ SSF / PF RATES ═══════════════════════════════════════ -->
<div class="panel">
    <div class="px-3 py-2 border-bottom"><h6 class="mb-0 fw-bold"><i class="fas fa-shield-alt me-1"></i>SSF & PF Statutory Rates</h6></div>
    <div class="p-3">
        <table class="table table-sm table-bordered" style="font-size:.82rem">
            <thead class="table-light">
                <tr><th>Rate Type</th><th class="text-end">Rate</th><th>Effective From</th><th>FY</th></tr>
            </thead>
            <tbody>
            <?php foreach($statRates as $r): ?>
            <tr>
                <td>
                    <strong><?= str_replace('_',' ',$r['rate_type']) ?></strong><br>
                    <small class="text-muted">
                        <?php
                        $rateDesc = ['SSF_EMPLOYEE'=>'Employee pays 11% of basic','SSF_EMPLOYER'=>'Employer pays 20% of basic','PF_EMPLOYEE'=>'Employee pays 10% of basic (PERMANENT only)','PF_EMPLOYER'=>'Employer pays 10% of basic (PERMANENT only)'];
                        echo $rateDesc[$r['rate_type']] ?? '';
                    ?>
                    </small>
                </td>
                <td class="text-end fw-bold text-primary"><?= ($r['rate_value']*100) ?>%</td>
                <td><?= $r['effective_from'] ?></td>
                <td><?= $r['fiscal_code'] ?></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="alert alert-info py-2" style="font-size:.78rem">
            <i class="fas fa-info-circle me-1"></i>
            <strong>SSF</strong> applies to employees with <code>is_ssf_enrolled = true</code>.
            <strong>PF</strong> applies to PERMANENT employees only.
            Both deductions reduce taxable income before TDS calculation.
        </div>
    </div>
</div>

<?php elseif ($tab === 'flow'): ?>
<!-- ══ ATTENDANCE → PAYROLL FLOW ════════════════════════════ -->
<div class="panel p-4">
    <h5 class="fw-bold mb-4"><i class="fas fa-project-diagram me-2"></i>Attendance → Payroll Generation Flow</h5>

    <div class="row g-3">
        <div class="col-md-6">
            <h6 class="fw-semibold text-primary">Step-by-Step Process</h6>
            <?php $steps2=[
                ['1','ZKTecePuller pulls attendance','Python service connects to biometric device via TCP. Runs 5× daily (06:20, 07:20, 09:20, 13:20, 17:10 NPT). Stores in zkteco database.','fas fa-fingerprint','#6c5ce7'],
                ['2','Sync to JEMC attendance','ZKTeco Live page syncs zkteco.attendance_logs → press_jemc.attendance. Matches by Card ID / Attendance ID.','fas fa-sync','#2c3e8c'],
                ['3','Mark/verify attendance','HR manually marks any missing entries. ZKTeco data shows as "ZKTECO" source, manual as "MANUAL".','fas fa-check-square','#1a9e5f'],
                ['4','Payroll generation','Select BS Month + Fiscal Year → click Generate. System calculates per employee: attendance summary → paid days → basic → OT → SSF/PF → TDS → net.','fas fa-calculator','#e8a020'],
                ['5','Review & approve','View payroll_details per employee. Approve the run to lock it.','fas fa-eye','#0984e3'],
                ['6','Generate payslips','Download individual or bulk PDF payslips via Payroll module.','fas fa-file-pdf','#d63031'],
            ];
            foreach($steps2 as [$n,$t,$d,$ic,$col]): ?>
            <div class="d-flex gap-3 mb-3">
                <div style="width:32px;height:32px;border-radius:50%;background:<?= $col ?>;color:#fff;display:flex;align-items:center;justify-content:center;font-size:.85rem;font-weight:700;flex-shrink:0"><?= $n ?></div>
                <div>
                    <div class="fw-semibold" style="font-size:.88rem"><i class="<?= $ic ?> me-1" style="color:<?= $col ?>"></i><?= $t ?></div>
                    <div class="text-muted" style="font-size:.76rem"><?= $d ?></div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div class="col-md-6">
            <h6 class="fw-semibold text-primary">Payroll Calculation Formula</h6>
            <div style="background:#f8f9fa;border-radius:8px;padding:1rem;font-size:.8rem;font-family:monospace">
                <div class="text-muted mb-2">// Per employee, per month:</div>
                Working Days = 26 (standard)<br>
                Per Day Rate = Basic ÷ Working Days<br>
                Paid Days = Working Days − Absent Days<br>
                Effective Basic = Per Day Rate × Paid Days<br><br>
                Hourly Rate = Per Day Rate ÷ 8<br>
                OT Amount = OT Hours × Hourly Rate × <?= $otRules[0]['ot_rate'] ?? '1.5' ?><br><br>
                Gross = Effective Basic + OT Amount<br><br>
                SSF Employee = Basic × 11%  (if enrolled)<br>
                PF Employee  = Basic × 10%  (if PERMANENT)<br>
                Taxable Income = Gross − SSF − PF<br>
                Annual Tax = Apply slab rates to Taxable × 12<br>
                Monthly TDS = Annual Tax ÷ 12<br><br>
                Total Deductions = SSF + PF + TDS<br>
                <strong>Net Payable = Gross − Total Deductions</strong>
            </div>
            <div class="mt-3">
                <h6 class="fw-semibold">Quick Links</h6>
                <a href="/jemc/attendance_device/zkteco_live.php" class="btn btn-sm btn-outline-purple mb-1 me-1">ZKTeco Live</a>
                <a href="/jemc/attendance_device/attendance_report.php" class="btn btn-sm btn-outline-primary mb-1 me-1">Attendance Report</a>
                <a href="/jemc/hr/modules/payroll/process.php" class="btn btn-sm btn-primary mb-1">Run Payroll</a>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</div><!-- /col-md-10 -->
</div><!-- /row -->
</div><!-- /container -->

<!-- ══ MODALS ═════════════════════════════════════════════════ -->

<!-- Salary Modal -->
<div class="modal fade" id="salaryModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <h5 class="modal-title"><i class="fas fa-money-bill-wave me-2"></i>Set Employee Salary</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_salary">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-md-12">
                    <label class="form-label fw-semibold">Employee <span class="text-danger">*</span></label>
                    <select name="employee_id" class="form-select" id="salaryEmpSelect" required>
                        <option value="">Select Employee</option>
                        <?php foreach($allActiveEmp as $e): ?>
                        <option value="<?= $e['id'] ?>"><?= htmlspecialchars($e['code'].' — '.$e['name'].' ('.$e['emp_type'].')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Basic Salary (NPR) <span class="text-danger">*</span></label>
                    <input type="number" name="basic_salary" class="form-control" step="0.01" min="0" required placeholder="e.g. 35000">
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Salary Mode</label>
                    <select name="salary_mode" class="form-select">
                        <option value="MONTHLY">Monthly</option>
                        <option value="DAILY">Daily Wages</option>
                    </select>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Effective From <span class="text-danger">*</span></label>
                    <input type="date" name="effective_from" class="form-control" value="<?= date('Y-m-d') ?>" required>
                </div>
                <div class="col-md-6">
                    <label class="form-label fw-semibold">Taxpayer Type</label>
                    <select name="taxpayer_type" class="form-select">
                        <option value="SINGLE">Single</option>
                        <option value="COUPLE">Couple (married)</option>
                    </select>
                </div>
                <div class="col-md-12">
                    <div class="form-check">
                        <input type="checkbox" name="is_ssf_enrolled" class="form-check-input" id="ssfCheck" value="1">
                        <label class="form-check-label" for="ssfCheck">
                            <strong>SSF Enrolled</strong> — Employee pays 11%, Employer pays 20% of basic salary
                        </label>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Salary</button>
        </div>
    </form>
</div></div></div>

<!-- Holiday Modal -->
<div class="modal fade" id="holidayModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-danger text-white">
        <h5 class="modal-title" id="holidayModalTitle"><i class="fas fa-calendar-times me-2"></i>Add Holiday</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_holiday">
        <input type="hidden" name="holiday_id" id="hId" value="0">
        <div class="modal-body">
            <div class="mb-3">
                <label class="form-label fw-semibold">Holiday Name <span class="text-danger">*</span></label>
                <input type="text" name="holiday_name" id="hName" class="form-control" required placeholder="e.g. Nepali New Year">
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">BS Date (YYYY.MM.DD) <span class="text-danger">*</span></label>
                    <input type="text" name="holiday_date_nep" id="hNep" class="form-control bs-date"
                           placeholder="2082.01.01" data-ad-pair="hEng" required>
                    <small class="bs-date-label"></small>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">AD Date</label>
                    <input type="date" name="holiday_date_eng" id="hEng" class="form-control ad-date" data-bs-pair="hNep" required>
                    <small class="text-muted">Auto-filled from BS date ↑</small>
                </div>
            </div>
            <div class="row g-2 mb-3">
                <div class="col-6">
                    <label class="form-label fw-semibold">Holiday Type</label>
                    <select name="holiday_type_id" id="hType" class="form-select">
                        <?php foreach($holidayTypes as $t): ?>
                        <option value="<?= $t['id'] ?>"><?= htmlspecialchars($t['type_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-6">
                    <label class="form-label fw-semibold">Fiscal Year</label>
                    <select name="fiscal_year" id="hFY" class="form-select">
                        <?php foreach($fiscalYears as $fy): ?>
                        <option value="<?= $fy['fiscal_code'] ?>" <?= $fy['is_active']?'selected':'' ?>><?= $fy['fiscal_code'] ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="mb-3">
                <label class="form-label fw-semibold">Remarks</label>
                <input type="text" name="remarks" id="hRemarks" class="form-control" placeholder="Optional notes">
            </div>
            <div class="form-check">
                <input type="checkbox" name="is_active" id="hActive" class="form-check-input" value="1" checked>
                <label class="form-check-label" for="hActive">Active</label>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-danger"><i class="fas fa-save me-1"></i>Save Holiday</button>
        </div>
    </form>
</div></div></div>

<!-- OT Rule Modal -->
<div class="modal fade" id="otModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-warning">
        <h5 class="modal-title"><i class="fas fa-clock me-2"></i>OT Rule</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_ot_rule">
        <input type="hidden" name="rule_id" id="otId" value="0">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-6"><label class="form-label fw-semibold">Rule Name</label>
                    <input type="text" name="rule_name" id="otName" class="form-control" required></div>
                <div class="col-6"><label class="form-label fw-semibold">Day Type</label>
                    <select name="day_type" id="otDay" class="form-select">
                        <option value="WEEKDAY">Weekday</option>
                        <option value="WEEKEND">Weekend</option>
                        <option value="HOLIDAY">Holiday</option>
                    </select></div>
                <div class="col-4"><label class="form-label fw-semibold">Min Hours for OT</label>
                    <input type="number" name="min_hours" id="otMin" class="form-control" step="0.5" value="8"></div>
                <div class="col-4"><label class="form-label fw-semibold">OT Rate (×)</label>
                    <input type="number" name="ot_rate" id="otRate" class="form-control" step="0.25" value="1.5"></div>
                <div class="col-4"><label class="form-label fw-semibold">Max OT/Day (hrs)</label>
                    <input type="number" name="max_hours" id="otMax" class="form-control" step="0.5" value="4"></div>
                <div class="col-6">
                    <div class="form-check mt-2">
                        <input type="checkbox" name="requires_approval" id="otApproval" class="form-check-input" checked>
                        <label class="form-check-label" for="otApproval">Requires Approval</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check mt-2">
                        <input type="checkbox" name="is_active" id="otActive" class="form-check-input" checked>
                        <label class="form-check-label" for="otActive">Active</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Save</button>
        </div>
    </form>
</div></div></div>

<!-- Salary Component Modal -->
<div class="modal fade" id="componentModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-success text-white">
        <h5 class="modal-title"><i class="fas fa-list me-2"></i>Salary Component</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_component">
        <input type="hidden" name="component_id" id="compId" value="0">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-6"><label class="form-label fw-semibold">Code</label>
                    <input type="text" name="component_code" id="compCode" class="form-control" placeholder="e.g. HRA" style="text-transform:uppercase"></div>
                <div class="col-6"><label class="form-label fw-semibold">Name</label>
                    <input type="text" name="component_name" id="compName" class="form-control" required></div>
                <div class="col-6"><label class="form-label fw-semibold">Type</label>
                    <select name="component_type" id="compType" class="form-select">
                        <option value="EARNING">Earning</option>
                        <option value="DEDUCTION">Deduction</option>
                        <option value="STATUTORY">Statutory</option>
                    </select></div>
                <div class="col-6"><label class="form-label fw-semibold">Calculation</label>
                    <select name="calculation_type" id="compCalc" class="form-select">
                        <option value="FIXED">Fixed Amount</option>
                        <option value="PERCENTAGE">% of Basic</option>
                        <option value="FORMULA">Formula</option>
                    </select></div>
                <div class="col-6"><label class="form-label fw-semibold">Default Value</label>
                    <input type="number" name="default_value" id="compVal" class="form-control" step="0.01" value="0"></div>
                <div class="col-6">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="is_taxable" id="compTax" class="form-check-input" checked>
                        <label class="form-check-label" for="compTax">Taxable</label>
                    </div>
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="compActive" class="form-check-input" checked>
                        <label class="form-check-label" for="compActive">Active</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save</button>
        </div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Holiday modal edit
function editHoliday(h) {
    document.getElementById('hId').value     = h.id;
    document.getElementById('hName').value   = h.holiday_name;
    document.getElementById('hNep').value    = h.holiday_date_nep;
    document.getElementById('hEng').value    = h.holiday_date_eng;
    document.getElementById('hType').value   = h.holiday_type_id;
    document.getElementById('hFY').value     = h.fiscal_year;
    document.getElementById('hRemarks').value= h.remarks || '';
    document.getElementById('hActive').checked = h.is_active;
    document.getElementById('holidayModalTitle').textContent = 'Edit Holiday';
    new bootstrap.Modal(document.getElementById('holidayModal')).show();
}

// BS↔AD sync for holiday modal
document.addEventListener('DOMContentLoaded', function() {
    const bsIn = document.getElementById('hNep');
    const adIn = document.getElementById('hEng');
    if (bsIn && adIn && typeof NepalDate !== 'undefined') {
        bsIn.addEventListener('change', function() {
            // BS date stored as YYYY.MM.DD — convert dots to dashes for NepalDate
            const v = this.value.replace(/\./g, '-');
            if (/^\d{4}-\d{2}-\d{2}$/.test(v)) {
                try { adIn.value = NepalDate.bsToAd(v); } catch(e) {}
            }
        });
        adIn.addEventListener('change', function() {
            if (/^\d{4}-\d{2}-\d{2}$/.test(this.value)) {
                try { bsIn.value = NepalDate.adToBs(this.value).replace(/-/g,'.'); } catch(e) {}
            }
        });
    }
});

// OT Rule edit
function editOTRule(r) {
    document.getElementById('otId').value       = r.id;
    document.getElementById('otName').value     = r.rule_name;
    document.getElementById('otDay').value      = r.day_type;
    document.getElementById('otMin').value      = r.min_hours_for_ot;
    document.getElementById('otRate').value     = r.ot_rate;
    document.getElementById('otMax').value      = r.max_ot_hours_per_day;
    document.getElementById('otApproval').checked = r.requires_approval;
    document.getElementById('otActive').checked   = r.is_active;
    new bootstrap.Modal(document.getElementById('otModal')).show();
}

// Component edit
function editComponent(c) {
    document.getElementById('compId').value    = c.id;
    document.getElementById('compCode').value  = c.component_code;
    document.getElementById('compCode').readOnly = true;
    document.getElementById('compName').value  = c.component_name;
    document.getElementById('compType').value  = c.component_type;
    document.getElementById('compCalc').value  = c.calculation_type;
    document.getElementById('compVal').value   = c.default_value;
    document.getElementById('compTax').checked = c.is_taxable;
    document.getElementById('compActive').checked = c.is_active;
    new bootstrap.Modal(document.getElementById('componentModal')).show();
}

// Open salary modal pre-filled with employee
function openSalaryModal(empId, empName) {
    const sel = document.getElementById('salaryEmpSelect');
    sel.value = empId;
    new bootstrap.Modal(document.getElementById('salaryModal')).show();
}
</script>
</body></html>
<?php ob_end_flush(); ?>

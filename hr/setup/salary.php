<?php
/**
 * Dynamic Salary Setup
 * - Grade/Scale management (opening → max basic with increments)
 * - Salary component library (global)
 * - Per-employee component overrides
 * - Grade-component defaults
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

if (!has_role('admin') && !has_role('hr') && !has_role('finance')) {
    header('Location: /jemc/unauthorized.php'); exit();
}

$tab = $_GET['tab'] ?? 'grades';
$msg = ''; $err = '';

// ════ POST HANDLERS ════════════════════════════════════════════

// Save Grade
if (($_POST['action'] ?? '') === 'save_grade') {
    try {
        $d = [
            ':code'     => strtoupper(trim($_POST['grade_code'])),
            ':name'     => trim($_POST['grade_name']),
            ':level_id' => $_POST['level_id'] ?: null,
            ':emp_type' => $_POST['emp_type'] ?: null,
            ':opening'  => (float)$_POST['opening_basic'],
            ':mid'      => (float)($_POST['mid_basic'] ?? 0),
            ':max'      => (float)($_POST['max_basic'] ?? 0),
            ':incr'     => (float)($_POST['increment_amount'] ?? 0),
            ':fy'       => $_POST['fiscal_year_id'] ?: null,
            ':active'   => isset($_POST['is_active']) ? 'true' : 'false',
        ];
        $id = (int)($_POST['grade_id'] ?? 0);
        if ($id) {
            $conn->prepare("UPDATE salary_grades SET grade_code=:code,grade_name=:name,level_id=:level_id,emp_type=:emp_type,
                opening_basic=:opening,mid_basic=:mid,max_basic=:max,increment_amount=:incr,fiscal_year_id=:fy,is_active=:active WHERE id=:id")
                ->execute(array_merge($d, [':id' => $id]));
        } else {
            $conn->prepare("INSERT INTO salary_grades (grade_code,grade_name,level_id,emp_type,opening_basic,mid_basic,max_basic,increment_amount,fiscal_year_id,is_active)
                VALUES (:code,:name,:level_id,:emp_type,:opening,:mid,:max,:incr,:fy,:active)")
                ->execute($d);
        }
        $msg = "Grade saved.";
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// Save Component
if (($_POST['action'] ?? '') === 'save_component') {
    try {
        $d = [
            ':code'   => strtoupper(trim($_POST['component_code'])),
            ':name'   => trim($_POST['component_name']),
            ':ctype'  => $_POST['component_type'],
            ':calc'   => $_POST['calculation_type'],
            ':base'   => $_POST['percentage_base'] ?? 'BASIC',
            ':val'    => (float)($_POST['default_value'] ?? 0),
            ':tax'    => isset($_POST['is_taxable'])  ? 'true' : 'false',
            ':active' => isset($_POST['is_active'])   ? 'true' : 'false',
            ':applies'=> $_POST['applies_to'] ?? 'ALL',
            ':ord'    => (int)($_POST['component_order'] ?? 0),
            ':desc'   => trim($_POST['description'] ?? ''),
        ];
        $id = (int)($_POST['component_id'] ?? 0);
        if ($id) {
            $conn->prepare("UPDATE salary_components SET component_code=:code,component_name=:name,component_type=:ctype,
                calculation_type=:calc,percentage_base=:base,default_value=:val,is_taxable=:tax,is_active=:active,
                applies_to=:applies,component_order=:ord,description=:desc WHERE id=:id")
                ->execute(array_merge($d, [':id' => $id]));
        } else {
            $conn->prepare("INSERT INTO salary_components (component_code,component_name,component_type,calculation_type,
                percentage_base,default_value,is_taxable,is_active,applies_to,component_order,description)
                VALUES (:code,:name,:ctype,:calc,:base,:val,:tax,:active,:applies,:ord,:desc)")
                ->execute($d);
        }
        $msg = "Component saved.";
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// Save Grade-Component default
if (($_POST['action'] ?? '') === 'save_grade_component') {
    try {
        $conn->prepare("INSERT INTO grade_salary_components (grade_id,component_id,calculation_type,fixed_amount,percentage_value,is_mandatory,is_active)
            VALUES (:g,:c,:calc,:fixed,:pct,:mand,:active)
            ON CONFLICT(grade_id,component_id) DO UPDATE SET
                calculation_type=EXCLUDED.calculation_type,fixed_amount=EXCLUDED.fixed_amount,
                percentage_value=EXCLUDED.percentage_value,is_mandatory=EXCLUDED.is_mandatory,is_active=EXCLUDED.is_active")
            ->execute([
                ':g'      => (int)$_POST['grade_id'],
                ':c'      => (int)$_POST['component_id'],
                ':calc'   => $_POST['calc_type'],
                ':fixed'  => (float)($_POST['fixed_amount'] ?? 0),
                ':pct'    => (float)($_POST['pct_value'] ?? 0),
                ':mand'   => isset($_POST['is_mandatory']) ? 'true' : 'false',
                ':active' => isset($_POST['is_active'])   ? 'true' : 'false',
            ]);
        $msg = "Grade component saved.";
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// Delete grade component
if (($_POST['action'] ?? '') === 'delete_grade_component') {
    $conn->prepare("DELETE FROM grade_salary_components WHERE grade_id=:g AND component_id=:c")
         ->execute([':g' => (int)$_POST['grade_id'], ':c' => (int)$_POST['component_id']]);
    $msg = "Component removed from grade.";
}

// Save Employee Component Override
if (($_POST['action'] ?? '') === 'save_emp_component') {
    try {
        $conn->prepare("INSERT INTO employee_salary_components
            (employee_id,component_id,calculation_type,fixed_amount,percentage_value,is_active,effective_from,remarks,created_by,created_at)
            VALUES (:e,:c,:calc,:fixed,:pct,:active,:from,:rem,:u,NOW())
            ON CONFLICT(employee_id,component_id,effective_from) DO UPDATE SET
                calculation_type=EXCLUDED.calculation_type,fixed_amount=EXCLUDED.fixed_amount,
                percentage_value=EXCLUDED.percentage_value,is_active=EXCLUDED.is_active,remarks=EXCLUDED.remarks")
            ->execute([
                ':e'      => (int)$_POST['employee_id'],
                ':c'      => (int)$_POST['component_id'],
                ':calc'   => $_POST['calc_type'],
                ':fixed'  => (float)($_POST['fixed_amount'] ?? 0),
                ':pct'    => (float)($_POST['pct_value'] ?? 0),
                ':active' => isset($_POST['is_active']) ? 'true' : 'false',
                ':from'   => $_POST['effective_from'] ?? date('Y-m-d'),
                ':rem'    => trim($_POST['remarks'] ?? ''),
                ':u'      => $_SESSION['user_id'] ?? 0,
            ]);
        $msg = "Employee component saved.";
    } catch(Exception $e) { $err = $e->getMessage(); }
}

// Delete employee component
if (($_POST['action'] ?? '') === 'delete_emp_component') {
    $conn->prepare("DELETE FROM employee_salary_components WHERE id=:id")->execute([':id' => (int)$_POST['id']]);
    $msg = "Employee component removed.";
}

// Assign grade to employees
if (($_POST['action'] ?? '') === 'assign_grade') {
    $empIds = $_POST['emp_ids'] ?? [];
    $gradeId = (int)$_POST['grade_id_assign'];
    $basic = (float)$_POST['auto_basic'];
    $from  = $_POST['effective_from_assign'] ?? date('Y-m-d');
    $assigned = 0;
    foreach ($empIds as $eId) {
        $eId = (int)$eId;
        $conn->prepare("UPDATE employee_salary SET is_current=false, effective_to=:d WHERE employee_id=:e AND is_current=true")
             ->execute([':d'=>$from,':e'=>$eId]);
        $conn->prepare("INSERT INTO employee_salary (employee_id,basic_salary,salary_mode,effective_from,grade_id,is_current,created_by,created_at)
            VALUES (:e,:b,'MONTHLY',:f,:g,true,:u,NOW())")
            ->execute([':e'=>$eId,':b'=>$basic,':f'=>$from,':g'=>$gradeId,':u'=>$_SESSION['user_id']??0]);
        // Auto-add grade components to employee
        $gradeComps = $conn->prepare("SELECT * FROM grade_salary_components WHERE grade_id=:g AND is_active=true");
        $gradeComps->execute([':g'=>$gradeId]);
        foreach ($gradeComps->fetchAll(PDO::FETCH_ASSOC) as $gc) {
            $conn->prepare("INSERT INTO employee_salary_components (employee_id,component_id,calculation_type,fixed_amount,percentage_value,is_active,effective_from,created_by,created_at)
                VALUES (:e,:c,:calc,:f,:p,true,:from,:u,NOW())
                ON CONFLICT(employee_id,component_id,effective_from) DO NOTHING")
                ->execute([':e'=>$eId,':c'=>$gc['component_id'],':calc'=>$gc['calculation_type'],
                           ':f'=>$gc['fixed_amount'],':p'=>$gc['percentage_value'],':from'=>$from,':u'=>$_SESSION['user_id']??0]);
        }
        $assigned++;
    }
    $msg = "Grade assigned to $assigned employee(s) with components auto-applied.";
}

// ════ LOAD DATA ════════════════════════════════════════════════

$grades     = $conn->query("SELECT sg.*,l.name AS level_name,l.remarks AS level_title,fy.fiscal_code,
    COUNT(DISTINCT es.employee_id) AS emp_count
    FROM salary_grades sg
    LEFT JOIN level l ON sg.level_id=l.id
    LEFT JOIN fiscal_years fy ON sg.fiscal_year_id=fy.id
    LEFT JOIN employee_salary es ON es.grade_id=sg.id AND es.is_current=true
    GROUP BY sg.id,l.name,l.remarks,fy.fiscal_code ORDER BY sg.level_id, sg.grade_code")->fetchAll(PDO::FETCH_ASSOC);

$components = $conn->query("SELECT * FROM salary_components ORDER BY component_order,component_type,component_code")->fetchAll(PDO::FETCH_ASSOC);
$levels     = $conn->query("SELECT * FROM level WHERE status=true ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);
$fiscalYears= $conn->query("SELECT id,fiscal_code,is_active FROM fiscal_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);

// For employee component tab
$empFilter  = (int)($_GET['emp_id'] ?? 0);
$empList    = $conn->query("SELECT e.id,e.code,e.name,e.emp_type,dep.name AS dept,
    es.basic_salary,sg.grade_name,sg.grade_code
    FROM employee e
    LEFT JOIN department dep ON e.department_id=dep.id
    LEFT JOIN employee_salary es ON es.employee_id=e.id AND es.is_current=true
    LEFT JOIN salary_grades sg ON es.grade_id=sg.id
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL ORDER BY e.code")->fetchAll(PDO::FETCH_ASSOC);

$empComponents = [];
if ($empFilter) {
    $empComponents = $conn->prepare("SELECT esc.*,sc.component_name,sc.component_code,sc.component_type
        FROM employee_salary_components esc
        JOIN salary_components sc ON esc.component_id=sc.id
        WHERE esc.employee_id=:e ORDER BY sc.component_order")->execute([':e'=>$empFilter]) ? [] : [];
    $stmt = $conn->prepare("SELECT esc.*,sc.component_name,sc.component_code,sc.component_type
        FROM employee_salary_components esc
        JOIN salary_components sc ON esc.component_id=sc.id
        WHERE esc.employee_id=:e ORDER BY sc.component_order");
    $stmt->execute([':e'=>$empFilter]);
    $empComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Grade components for selected grade
$gradeCompFilter = (int)($_GET['grade_id'] ?? ($grades[0]['id'] ?? 0));
$gradeComponents = [];
if ($gradeCompFilter) {
    $stmt = $conn->prepare("SELECT gsc.*,sc.component_name,sc.component_code,sc.component_type,sc.applies_to
        FROM grade_salary_components gsc JOIN salary_components sc ON gsc.component_id=sc.id
        WHERE gsc.grade_id=:g ORDER BY sc.component_order");
    $stmt->execute([':g'=>$gradeCompFilter]);
    $gradeComponents = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Employees without grade (for bulk assign)
$ungraded = $conn->query("SELECT e.id,e.code,e.name,e.emp_type,dep.name AS dept
    FROM employee e LEFT JOIN department dep ON e.department_id=dep.id
    LEFT JOIN employee_salary es ON es.employee_id=e.id AND es.is_current=true
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL AND (es.id IS NULL OR es.grade_id IS NULL)
    ORDER BY e.code")->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Dynamic Salary Setup — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.setup-nav .nav-link{color:#495057;border-radius:8px;margin-bottom:3px;padding:.45rem .9rem;font-size:.82rem}
.setup-nav .nav-link.active{background:#2c3e8c;color:#fff}
.panel{background:#fff;border-radius:10px;box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden}
.panel-hdr{padding:.65rem 1rem;border-bottom:1px solid #f0f2f8;display:flex;align-items:center;justify-content:space-between}
.panel-hdr h6{margin:0;font-weight:800;font-size:.82rem}
.comp-badge-EARNING{background:#e8f5e9;color:#1b5e20}
.comp-badge-DEDUCTION{background:#fff3e0;color:#e65100}
.comp-badge-STATUTORY{background:#f3e5f5;color:#4a148c}
.grade-bar{height:8px;border-radius:4px;background:#f0f2f8;overflow:hidden;margin-top:4px}
.grade-bar-fill{height:100%;border-radius:4px;background:linear-gradient(90deg,#2c3e8c,#1a9e5f)}
.emp-row:hover{background:#f8f9fa;cursor:pointer}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1500px">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0" style="color:#2c3e8c">
            <i class="fas fa-sliders-h me-2"></i>Dynamic Salary Setup
        </h4>
        <small class="text-muted">Grade scales · Component library · Per-employee overrides</small>
    </div>
    <div class="d-flex gap-2">
        <a href="/jemc/hr/setup/index.php" class="btn btn-outline-secondary btn-sm">
            <i class="fas fa-cogs me-1"></i>Setup Overview
        </a>
        <a href="/jemc/hr/modules/payroll/process.php" class="btn btn-primary btn-sm">
            <i class="fas fa-play me-1"></i>Run Payroll
        </a>
    </div>
</div>

<?php if($msg): ?><div class="alert alert-success alert-dismissible fade show py-2"><i class="fas fa-check-circle me-1"></i><?= htmlspecialchars($msg) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>
<?php if($err): ?><div class="alert alert-danger alert-dismissible fade show py-2"><i class="fas fa-exclamation-triangle me-1"></i><?= htmlspecialchars($err) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div><?php endif; ?>

<div class="row g-3">
<!-- LEFT NAV -->
<div class="col-md-2">
    <div class="panel p-3">
        <nav class="setup-nav nav flex-column">
            <a class="nav-link <?= $tab==='grades'?'active':'' ?>" href="?tab=grades"><i class="fas fa-layer-group me-2"></i>Salary Grades</a>
            <a class="nav-link <?= $tab==='grade_components'?'active':'' ?>" href="?tab=grade_components&grade_id=<?= $gradeCompFilter ?>"><i class="fas fa-sitemap me-2"></i>Grade Components</a>
            <a class="nav-link <?= $tab==='components'?'active':'' ?>" href="?tab=components"><i class="fas fa-list me-2"></i>Component Library</a>
            <hr class="my-2">
            <a class="nav-link <?= $tab==='emp_salary'?'active':'' ?>" href="?tab=emp_salary"><i class="fas fa-users me-2"></i>Employee Salaries</a>
            <a class="nav-link <?= $tab==='emp_components'?'active':'' ?>" href="?tab=emp_components<?= $empFilter?"&emp_id=$empFilter":'' ?>"><i class="fas fa-user-cog me-2"></i>Employee Overrides</a>
            <hr class="my-2">
            <a class="nav-link <?= $tab==='bulk_assign'?'active':'' ?>" href="?tab=bulk_assign"><i class="fas fa-bolt me-2"></i>Bulk Assign Grade</a>
        </nav>
    </div>
</div>

<!-- MAIN -->
<div class="col-md-10">

<?php if ($tab === 'grades'): ?>
<!-- ══ SALARY GRADES ══════════════════════════════════════════ -->
<div class="panel">
    <div class="panel-hdr">
        <h6><i class="fas fa-layer-group me-1"></i>Salary Grade Scales (<?= count($grades) ?> grades)</h6>
        <button class="btn btn-primary btn-sm" onclick="openGradeModal()">
            <i class="fas fa-plus me-1"></i>Add Grade
        </button>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.81rem">
            <thead class="table-dark">
                <tr>
                    <th>Grade Code</th><th>Grade Name</th><th>Level</th><th>Emp Type</th>
                    <th class="text-end">Opening Basic</th><th class="text-end">Mid</th><th class="text-end">Maximum</th>
                    <th class="text-end">Annual Increment</th><th class="text-center">Employees</th>
                    <th class="text-center">Status</th><th>Actions</th>
                </tr>
            </thead>
            <tbody>
            <?php foreach ($grades as $g): ?>
            <tr>
                <td><code style="font-size:.72rem"><?= htmlspecialchars($g['grade_code']) ?></code></td>
                <td>
                    <strong><?= htmlspecialchars($g['grade_name']) ?></strong>
                    <?php if($g['fiscal_code']): ?>
                    <small class="text-muted d-block">FY: <?= $g['fiscal_code'] ?></small>
                    <?php endif; ?>
                </td>
                <td>
                    <?php if($g['level_name']): ?>
                    <span class="badge bg-light text-dark border" style="font-size:.65rem">L<?= $g['level_name'] ?></span>
                    <small class="text-muted d-block" style="font-size:.67rem"><?= htmlspecialchars($g['level_title'] ?? '') ?></small>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td><?= $g['emp_type'] ? '<span class="badge bg-secondary" style="font-size:.62rem">'.$g['emp_type'].'</span>' : '<small class="text-muted">All</small>' ?></td>
                <td class="text-end fw-semibold text-primary">NPR <?= number_format($g['opening_basic'],0) ?></td>
                <td class="text-end text-muted"><?= $g['mid_basic'] > 0 ? number_format($g['mid_basic'],0) : '—' ?></td>
                <td class="text-end fw-semibold text-success">NPR <?= number_format($g['max_basic'],0) ?></td>
                <td class="text-end">+<?= number_format($g['increment_amount'],0) ?>/yr</td>
                <td class="text-center">
                    <?php if($g['emp_count'] > 0): ?>
                    <span class="badge bg-primary" style="font-size:.65rem"><?= $g['emp_count'] ?> staff</span>
                    <?php else: ?><span class="text-muted">—</span><?php endif; ?>
                </td>
                <td class="text-center"><?= $g['is_active']?'<span class="badge bg-success" style="font-size:.62rem">Active</span>':'<span class="badge bg-secondary" style="font-size:.62rem">Off</span>' ?></td>
                <td>
                    <button class="btn btn-xs btn-outline-primary me-1" style="font-size:.68rem;padding:2px 7px"
                            onclick='openGradeModal(<?= json_encode($g) ?>)'>Edit</button>
                    <a href="?tab=grade_components&grade_id=<?= $g['id'] ?>" class="btn btn-xs btn-outline-info" style="font-size:.68rem;padding:2px 7px">
                        Components
                    </a>
                </td>
            </tr>
            <!-- Grade salary range bar -->
            <tr style="background:#fafbff">
                <td colspan="4" class="py-1 ps-3" style="font-size:.68rem;color:#8492a6">
                    Salary range: NPR <?= number_format($g['opening_basic'],0) ?> → <?= number_format($g['max_basic'],0) ?>
                </td>
                <td colspan="7" class="py-1 pe-3">
                    <div class="grade-bar">
                        <div class="grade-bar-fill" style="width:<?= $g['max_basic']>0?min(100,round($g['opening_basic']/$g['max_basic']*100)):0 ?>%"></div>
                    </div>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'grade_components'): ?>
<!-- ══ GRADE COMPONENTS ═══════════════════════════════════════ -->
<div class="row g-3">
    <!-- Grade selector -->
    <div class="col-md-3">
        <div class="panel">
            <div class="panel-hdr"><h6><i class="fas fa-layer-group me-1"></i>Select Grade</h6></div>
            <div class="p-2">
            <?php foreach ($grades as $g): ?>
            <a href="?tab=grade_components&grade_id=<?= $g['id'] ?>"
               class="d-block px-2 py-1 rounded text-decoration-none mb-1 <?= $g['id']==$gradeCompFilter?'bg-primary text-white':'text-dark' ?>"
               style="font-size:.8rem">
                <code style="font-size:.72rem;<?= $g['id']==$gradeCompFilter?'color:#fff':'' ?>"><?= $g['grade_code'] ?></code>
                <?= htmlspecialchars($g['grade_name']) ?>
                <span class="badge <?= $g['id']==$gradeCompFilter?'bg-light text-dark':'bg-secondary' ?> ms-1" style="font-size:.6rem">
                    L<?= $g['level_name'] ?? '?' ?>
                </span>
            </a>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <!-- Components for selected grade -->
    <div class="col-md-9">
        <?php $selGrade = null;
        foreach($grades as $g) { if($g['id']==$gradeCompFilter) { $selGrade=$g; break; } } ?>
        <?php if($selGrade): ?>
        <div class="panel mb-3">
            <div class="panel-hdr">
                <h6>
                    <i class="fas fa-sitemap me-1"></i>
                    Components for: <?= htmlspecialchars($selGrade['grade_name']) ?>
                    <small class="text-muted fw-normal"> — Opening: NPR <?= number_format($selGrade['opening_basic']) ?> → Max: NPR <?= number_format($selGrade['max_basic']) ?></small>
                </h6>
                <button class="btn btn-primary btn-sm" onclick="openGradeCompModal(<?= $gradeCompFilter ?>)">
                    <i class="fas fa-plus me-1"></i>Add Component
                </button>
            </div>
            <div class="table-responsive">
                <table class="table table-sm table-hover mb-0" style="font-size:.81rem">
                    <thead class="table-light">
                        <tr><th>Component</th><th>Type</th><th>Calculation</th><th>Amount/Rate</th><th>Mandatory</th><th>Active</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php if(empty($gradeComponents)): ?>
                    <tr><td colspan="7" class="text-center py-3 text-muted">No components defined for this grade yet.</td></tr>
                    <?php else: foreach($gradeComponents as $gc): ?>
                    <tr>
                        <td>
                            <code style="font-size:.7rem"><?= htmlspecialchars($gc['component_code']) ?></code>
                            <?= htmlspecialchars($gc['component_name']) ?>
                        </td>
                        <td><span class="badge comp-badge-<?= $gc['component_type'] ?>" style="font-size:.62rem"><?= $gc['component_type'] ?></span></td>
                        <td><?= $gc['calculation_type'] ?></td>
                        <td class="fw-semibold">
                            <?php if($gc['calculation_type']==='FIXED'): ?>
                                NPR <?= number_format($gc['fixed_amount'],0) ?>
                            <?php elseif($gc['calculation_type']==='PERCENTAGE'): ?>
                                <?= $gc['percentage_value'] ?>% of basic
                            <?php else: ?>
                                Formula
                            <?php endif; ?>
                        </td>
                        <td><?= $gc['is_mandatory']?'<span class="text-success">✓ Yes</span>':'<span class="text-muted">Optional</span>' ?></td>
                        <td><?= $gc['is_active']?'<span class="badge bg-success" style="font-size:.6rem">Active</span>':'<span class="badge bg-secondary" style="font-size:.6rem">Off</span>' ?></td>
                        <td>
                            <button class="btn btn-xs btn-outline-primary me-1" style="font-size:.68rem"
                                    onclick='openGradeCompModal(<?= $gradeCompFilter ?>, <?= json_encode($gc) ?>)'>Edit</button>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Remove?')">
                                <input type="hidden" name="action" value="delete_grade_component">
                                <input type="hidden" name="grade_id" value="<?= $gradeCompFilter ?>">
                                <input type="hidden" name="component_id" value="<?= $gc['component_id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.68rem">Del</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; endif; ?>
                    </tbody>
                    <?php if(!empty($gradeComponents)): ?>
                    <!-- Estimated salary summary -->
                    <?php
                        $estBasic = $selGrade['opening_basic'];
                        $estTotal = $estBasic;
                        $estDed   = 0;
                        foreach($gradeComponents as $gc) {
                            if(!$gc['is_active']) continue;
                            $amt = $gc['calculation_type']==='FIXED'
                                ? $gc['fixed_amount']
                                : ($estBasic * $gc['percentage_value'] / 100);
                            if($gc['component_type']==='EARNING') $estTotal += $amt;
                            elseif($gc['component_type']==='DEDUCTION') $estDed += $amt;
                        }
                    ?>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="3" class="text-end fw-semibold" style="font-size:.78rem">Estimated (at opening basic):</td>
                            <td colspan="2" style="font-size:.78rem">
                                Gross: <strong class="text-success">NPR <?= number_format($estTotal,0) ?></strong>
                            </td>
                            <td colspan="2"></td>
                        </tr>
                    </tfoot>
                    <?php endif; ?>
                </table>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'components'): ?>
<!-- ══ COMPONENT LIBRARY ═════════════════════════════════════ -->
<div class="panel">
    <div class="panel-hdr">
        <h6><i class="fas fa-list me-1"></i>Salary Component Library (<?= count($components) ?> components)</h6>
        <button class="btn btn-primary btn-sm" onclick="openCompModal()"><i class="fas fa-plus me-1"></i>Add Component</button>
    </div>
    <?php foreach(['EARNING','DEDUCTION','STATUTORY'] as $ctype): ?>
    <div class="px-3 pt-3">
        <div class="d-flex align-items-center mb-2">
            <span class="badge comp-badge-<?= $ctype ?> me-2"><?= $ctype ?></span>
            <small class="text-muted"><?= count(array_filter($components,fn($c)=>$c['component_type']===$ctype)) ?> components</small>
        </div>
        <div class="row g-2 mb-3">
        <?php foreach($components as $c): if($c['component_type']!==$ctype) continue; ?>
        <div class="col-md-6 col-xl-4">
            <div class="border rounded p-2 h-100" style="<?= !$c['is_active']?'opacity:.5':'' ?>">
                <div class="d-flex justify-content-between align-items-start">
                    <div>
                        <code style="font-size:.72rem;color:#6c5ce7"><?= htmlspecialchars($c['component_code']) ?></code>
                        <strong style="font-size:.83rem;display:block"><?= htmlspecialchars($c['component_name']) ?></strong>
                        <small class="text-muted"><?= htmlspecialchars($c['description'] ?? '') ?></small>
                    </div>
                    <button class="btn btn-xs btn-outline-secondary" style="font-size:.65rem;padding:1px 6px;flex-shrink:0"
                            onclick='openCompModal(<?= json_encode($c) ?>)'>Edit</button>
                </div>
                <div class="mt-2 d-flex gap-1 flex-wrap">
                    <span class="badge bg-light text-dark border" style="font-size:.62rem">
                        <?= $c['calculation_type'] ?>
                        <?php if($c['calculation_type']==='PERCENTAGE'): ?>
                        (% of <?= $c['percentage_base'] ?? 'BASIC' ?>)
                        <?php elseif($c['default_value']>0): ?>
                        — NPR <?= number_format($c['default_value'],0) ?>
                        <?php endif; ?>
                    </span>
                    <?php if($c['applies_to'] && $c['applies_to']!=='ALL'): ?>
                    <span class="badge bg-info text-dark" style="font-size:.6rem"><?= $c['applies_to'] ?> only</span>
                    <?php endif; ?>
                    <?= $c['is_taxable']?'<span class="badge bg-warning text-dark" style="font-size:.6rem">Taxable</span>':'<span class="badge bg-light text-muted border" style="font-size:.6rem">Tax-exempt</span>' ?>
                    <?= $c['is_active']?'<span class="badge bg-success" style="font-size:.6rem">Active</span>':'<span class="badge bg-secondary" style="font-size:.6rem">Inactive</span>' ?>
                </div>
            </div>
        </div>
        <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>

<?php elseif ($tab === 'emp_salary'): ?>
<!-- ══ EMPLOYEE SALARIES ══════════════════════════════════════ -->
<div class="panel">
    <div class="panel-hdr">
        <h6><i class="fas fa-users me-1"></i>Employee Salary Overview (<?= count($empList) ?> active)</h6>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
            <thead class="table-dark">
                <tr><th>Code</th><th>Name</th><th>Dept</th><th>Type</th><th>Grade</th><th class="text-end">Basic Salary</th><th>Actions</th></tr>
            </thead>
            <tbody>
            <?php foreach($empList as $e): ?>
            <tr class="emp-row">
                <td><code style="font-size:.72rem"><?= htmlspecialchars($e['code']) ?></code></td>
                <td><?= htmlspecialchars($e['name']) ?></td>
                <td><small class="text-muted"><?= htmlspecialchars($e['dept'] ?? '') ?></small></td>
                <td><span class="badge bg-secondary" style="font-size:.6rem"><?= $e['emp_type'] ?></span></td>
                <td>
                    <?php if($e['grade_code']): ?>
                    <span class="badge bg-primary" style="font-size:.62rem"><?= $e['grade_code'] ?></span>
                    <small class="text-muted"><?= htmlspecialchars($e['grade_name'] ?? '') ?></small>
                    <?php else: ?>
                    <span class="text-warning" style="font-size:.75rem">⚠ No grade</span>
                    <?php endif; ?>
                </td>
                <td class="text-end fw-semibold <?= $e['basic_salary']?'text-success':'text-muted' ?>">
                    <?= $e['basic_salary'] ? 'NPR '.number_format($e['basic_salary'],0) : '—' ?>
                </td>
                <td>
                    <a href="?tab=emp_components&emp_id=<?= $e['id'] ?>"
                       class="btn btn-xs btn-outline-primary" style="font-size:.68rem;padding:2px 7px">
                        <i class="fas fa-sliders-h"></i> Components
                    </a>
                </td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php elseif ($tab === 'emp_components'): ?>
<!-- ══ PER-EMPLOYEE COMPONENT OVERRIDES ═══════════════════════ -->
<div class="row g-3">
    <div class="col-md-3">
        <div class="panel" style="max-height:600px;overflow-y:auto">
            <div class="panel-hdr"><h6>Select Employee</h6></div>
            <div class="p-1">
            <?php foreach($empList as $e): ?>
            <a href="?tab=emp_components&emp_id=<?= $e['id'] ?>"
               class="d-block px-2 py-1 rounded text-decoration-none mb-1 <?= $e['id']==$empFilter?'bg-primary text-white':'text-dark' ?>"
               style="font-size:.78rem">
                <code style="font-size:.68rem;<?= $e['id']==$empFilter?'color:#fff':'' ?>"><?= $e['code'] ?></code>
                <?= htmlspecialchars($e['name']) ?>
                <?php if(!$e['basic_salary']): ?><span class="badge bg-warning" style="font-size:.55rem">No salary</span><?php endif; ?>
            </a>
            <?php endforeach; ?>
            </div>
        </div>
    </div>
    <div class="col-md-9">
        <?php if($empFilter):
            $selEmp = null;
            foreach($empList as $e) { if($e['id']==$empFilter){$selEmp=$e;break;} }
        ?>
        <div class="panel mb-3">
            <div class="panel-hdr">
                <div>
                    <h6 class="mb-0">
                        <i class="fas fa-user-cog me-1"></i>
                        <?= htmlspecialchars($selEmp['name'] ?? '') ?>
                        <small class="text-muted fw-normal"><?= $selEmp['code'] ?? '' ?> · <?= $selEmp['emp_type'] ?? '' ?></small>
                    </h6>
                    <?php if($selEmp && $selEmp['grade_code']): ?>
                    <small class="text-muted">Grade: <?= $selEmp['grade_code'] ?> — Basic: NPR <?= number_format($selEmp['basic_salary'],0) ?></small>
                    <?php endif; ?>
                </div>
                <button class="btn btn-primary btn-sm" onclick="openEmpCompModal(<?= $empFilter ?>)">
                    <i class="fas fa-plus me-1"></i>Add Override
                </button>
            </div>

            <?php if(empty($empComponents)): ?>
            <div class="text-center py-5 text-muted">
                <i class="fas fa-user-cog fa-2x mb-2 d-block opacity-25"></i>
                No custom components for this employee.<br>
                <small>Components from their grade apply by default. Add overrides here for exceptions like petrol allowance, advance recovery, etc.</small>
            </div>
            <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm mb-0" style="font-size:.81rem">
                    <thead class="table-light">
                        <tr><th>Component</th><th>Type</th><th>Calculation</th><th>Amount</th><th>Effective</th><th>Active</th><th>Remarks</th><th>Action</th></tr>
                    </thead>
                    <tbody>
                    <?php
                    $grossEst = (float)($selEmp['basic_salary'] ?? 0);
                    $dedEst   = 0;
                    foreach($empComponents as $ec):
                        $amt = $ec['calculation_type']==='FIXED'
                            ? $ec['fixed_amount']
                            : ((float)($selEmp['basic_salary']??0) * $ec['percentage_value'] / 100);
                        if($ec['component_type']==='EARNING') $grossEst += $amt;
                        else $dedEst += $amt;
                    ?>
                    <tr>
                        <td>
                            <code style="font-size:.7rem"><?= htmlspecialchars($ec['component_code']) ?></code>
                            <?= htmlspecialchars($ec['component_name']) ?>
                        </td>
                        <td><span class="badge comp-badge-<?= $ec['component_type'] ?>" style="font-size:.62rem"><?= $ec['component_type'] ?></span></td>
                        <td><?= $ec['calculation_type'] ?></td>
                        <td class="fw-semibold <?= $ec['component_type']==='EARNING'?'text-success':'text-danger' ?>">
                            <?php if($ec['calculation_type']==='FIXED'): ?>
                            NPR <?= number_format($ec['fixed_amount'],0) ?>
                            <?php else: ?>
                            <?= $ec['percentage_value'] ?>%
                            <?php endif; ?>
                        </td>
                        <td><small><?= $ec['effective_from'] ?></small></td>
                        <td><?= $ec['is_active']?'<span class="badge bg-success" style="font-size:.6rem">Active</span>':'<span class="badge bg-secondary" style="font-size:.6rem">Off</span>' ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars($ec['remarks'] ?? '') ?></small></td>
                        <td>
                            <form method="POST" style="display:inline" onsubmit="return confirm('Remove?')">
                                <input type="hidden" name="action" value="delete_emp_component">
                                <input type="hidden" name="id" value="<?= $ec['id'] ?>">
                                <button type="submit" class="btn btn-xs btn-outline-danger" style="font-size:.68rem">Del</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                    <tfoot class="table-light">
                        <tr>
                            <td colspan="3" class="text-end fw-semibold" style="font-size:.78rem">Estimated this month:</td>
                            <td colspan="5" style="font-size:.78rem">
                                Basic: <strong>NPR <?= number_format($selEmp['basic_salary'],0) ?></strong> +
                                Overrides: Gross ≈ <strong class="text-success">NPR <?= number_format($grossEst,0) ?></strong>,
                                Deductions: <strong class="text-danger">NPR <?= number_format($dedEst,0) ?></strong>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="text-center py-5 text-muted">
            <i class="fas fa-user-cog fa-3x mb-3 d-block opacity-25"></i>
            Select an employee from the left to manage their salary components.
        </div>
        <?php endif; ?>
    </div>
</div>

<?php elseif ($tab === 'bulk_assign'): ?>
<!-- ══ BULK GRADE ASSIGNMENT ══════════════════════════════════ -->
<div class="panel">
    <div class="panel-hdr">
        <h6><i class="fas fa-bolt me-1"></i>Bulk Assign Grade to Employees (<?= count($ungraded) ?> without grade)</h6>
    </div>
    <div class="p-3">
        <div class="alert alert-info py-2 small mb-3">
            <i class="fas fa-info-circle me-1"></i>
            Select employees, choose a grade, set the basic salary, and click Assign.
            Grade default components will automatically be added to each employee's profile.
        </div>
        <form method="POST">
            <input type="hidden" name="action" value="assign_grade">
            <div class="row g-3 mb-3">
                <div class="col-md-4">
                    <label class="form-label fw-semibold">Grade <span class="text-danger">*</span></label>
                    <select name="grade_id_assign" class="form-select" required id="gradeSelect"
                            onchange="updateBasicRange(this)">
                        <option value="">Select Grade</option>
                        <?php foreach($grades as $g): ?>
                        <option value="<?= $g['id'] ?>"
                                data-opening="<?= $g['opening_basic'] ?>"
                                data-mid="<?= $g['mid_basic'] ?>"
                                data-max="<?= $g['max_basic'] ?>">
                            <?= htmlspecialchars($g['grade_code'].' — '.$g['grade_name']) ?>
                            (NPR <?= number_format($g['opening_basic'],0) ?> → <?= number_format($g['max_basic'],0) ?>)
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Basic Salary (NPR) <span class="text-danger">*</span></label>
                    <input type="number" name="auto_basic" id="autoBasic" class="form-control" required
                           placeholder="Enter basic salary" min="0" step="100">
                    <small class="text-muted" id="basicRange"></small>
                </div>
                <div class="col-md-3">
                    <label class="form-label fw-semibold">Effective From</label>
                    <input type="date" name="effective_from_assign" class="form-control" value="<?= date('Y-m-d') ?>">
                </div>
                <div class="col-md-2 d-flex align-items-end">
                    <button type="submit" class="btn btn-primary w-100">
                        <i class="fas fa-bolt me-1"></i>Assign Grade
                    </button>
                </div>
            </div>

            <!-- Employee selection -->
            <div class="d-flex justify-content-between mb-2">
                <div>
                    <button type="button" class="btn btn-sm btn-outline-secondary me-1" onclick="toggleAll(true)">Select All</button>
                    <button type="button" class="btn btn-sm btn-outline-secondary" onclick="toggleAll(false)">Deselect All</button>
                </div>
                <div class="d-flex gap-2">
                    <input type="text" id="empSearch" class="form-control form-control-sm" placeholder="Search employee..." style="width:200px">
                </div>
            </div>
            <div style="max-height:400px;overflow-y:auto;border:1px solid #dee2e6;border-radius:8px">
                <table class="table table-sm table-hover mb-0" style="font-size:.8rem">
                    <thead class="table-light sticky-top">
                        <tr><th width="40"><input type="checkbox" id="checkAll" onchange="toggleAll(this.checked)"></th>
                        <th>Code</th><th>Name</th><th>Dept</th><th>Type</th><th>Current Grade</th></tr>
                    </thead>
                    <tbody id="empTableBody">
                    <?php foreach($empList as $e): ?>
                    <tr class="emp-search-row">
                        <td><input type="checkbox" name="emp_ids[]" value="<?= $e['id'] ?>" class="emp-check"></td>
                        <td><code style="font-size:.7rem"><?= htmlspecialchars($e['code']) ?></code></td>
                        <td><?= htmlspecialchars($e['name']) ?></td>
                        <td><small class="text-muted"><?= htmlspecialchars($e['dept'] ?? '') ?></small></td>
                        <td><span class="badge bg-secondary" style="font-size:.6rem"><?= $e['emp_type'] ?></span></td>
                        <td>
                            <?= $e['grade_code']
                                ? '<span class="badge bg-primary" style="font-size:.62rem">'.$e['grade_code'].'</span>'
                                : '<span class="text-warning" style="font-size:.75rem">⚠ No grade</span>' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </form>
    </div>
</div>
<?php endif; ?>

</div><!-- /col-10 -->
</div><!-- /row -->
</div><!-- /container -->

<!-- ══ MODALS ═══════════════════════════════════════════════════ -->

<!-- Grade Modal -->
<div class="modal fade" id="gradeModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
    <div class="modal-header bg-primary text-white">
        <h5 class="modal-title" id="gradeMTitle"><i class="fas fa-layer-group me-2"></i>Add Salary Grade</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_grade">
        <input type="hidden" name="grade_id" id="gradeId" value="0">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-4"><label class="form-label fw-semibold">Grade Code <span class="text-danger">*</span></label>
                    <input type="text" name="grade_code" id="gradeCode" class="form-control" required placeholder="GRADE-6" style="text-transform:uppercase"></div>
                <div class="col-8"><label class="form-label fw-semibold">Grade Name <span class="text-danger">*</span></label>
                    <input type="text" name="grade_name" id="gradeName" class="form-control" required placeholder="Level 6 — शाखा अधिकृत"></div>
                <div class="col-4"><label class="form-label fw-semibold">Level</label>
                    <select name="level_id" id="gradeLevel" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach($levels as $l): ?>
                        <option value="<?= $l['id'] ?>">L<?= $l['name'] ?> — <?= htmlspecialchars($l['remarks'] ?? '') ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-4"><label class="form-label fw-semibold">Employee Type</label>
                    <select name="emp_type" id="gradeEmpType" class="form-select">
                        <option value="">All Types</option>
                        <option value="PERMANENT">Permanent</option>
                        <option value="CONTRACT">Contract</option>
                        <option value="DAILY_WAGES">Daily Wages</option>
                    </select></div>
                <div class="col-4"><label class="form-label fw-semibold">Fiscal Year</label>
                    <select name="fiscal_year_id" id="gradeFY" class="form-select">
                        <option value="">— None —</option>
                        <?php foreach($fiscalYears as $fy): ?>
                        <option value="<?= $fy['id'] ?>" <?= $fy['is_active']?'selected':'' ?>><?= $fy['fiscal_code'] ?></option>
                        <?php endforeach; ?>
                    </select></div>
                <div class="col-4"><label class="form-label fw-semibold">Opening Basic (NPR) <span class="text-danger">*</span></label>
                    <input type="number" name="opening_basic" id="gradeOpening" class="form-control" required step="100" min="0" placeholder="Minimum salary"></div>
                <div class="col-4"><label class="form-label fw-semibold">Mid-Point (NPR)</label>
                    <input type="number" name="mid_basic" id="gradeMid" class="form-control" step="100" min="0"></div>
                <div class="col-4"><label class="form-label fw-semibold">Maximum (NPR)</label>
                    <input type="number" name="max_basic" id="gradeMax" class="form-control" step="100" min="0"></div>
                <div class="col-4"><label class="form-label fw-semibold">Annual Increment (NPR)</label>
                    <input type="number" name="increment_amount" id="gradeIncr" class="form-control" step="100" min="0" value="0"></div>
                <div class="col-4">
                    <div class="form-check mt-4">
                        <input type="checkbox" name="is_active" id="gradeActive" class="form-check-input" checked>
                        <label class="form-check-label fw-semibold" for="gradeActive">Active</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-primary"><i class="fas fa-save me-1"></i>Save Grade</button>
        </div>
    </form>
</div></div></div>

<!-- Grade Component Modal -->
<div class="modal fade" id="gradeCompModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-info text-white">
        <h5 class="modal-title"><i class="fas fa-sitemap me-2"></i>Grade Component</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_grade_component">
        <input type="hidden" name="grade_id" id="gcGradeId">
        <input type="hidden" name="component_id" id="gcCompId">
        <div class="modal-body">
            <div class="mb-3"><label class="form-label fw-semibold">Component <span class="text-danger">*</span></label>
                <select name="component_id" id="gcCompSelect" class="form-select" required
                        onchange="document.getElementById('gcCompId').value=this.value">
                    <option value="">Select Component</option>
                    <?php foreach($components as $c): ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['component_code'].' — '.$c['component_name']) ?> (<?= $c['component_type'] ?>)</option>
                    <?php endforeach; ?>
                </select></div>
            <div class="row g-2 mb-3">
                <div class="col-4"><label class="form-label fw-semibold">Calculation</label>
                    <select name="calc_type" id="gcCalc" class="form-select" onchange="toggleGCAmount(this.value)">
                        <option value="FIXED">Fixed Amount</option>
                        <option value="PERCENTAGE">% of Basic</option>
                        <option value="FORMULA">Formula</option>
                    </select></div>
                <div class="col-4" id="gcFixedDiv"><label class="form-label fw-semibold">Fixed Amount (NPR)</label>
                    <input type="number" name="fixed_amount" id="gcFixed" class="form-control" step="100" min="0" value="0"></div>
                <div class="col-4" id="gcPctDiv" style="display:none"><label class="form-label fw-semibold">Percentage (%)</label>
                    <input type="number" name="pct_value" id="gcPct" class="form-control" step="0.01" min="0" value="0"></div>
            </div>
            <div class="row g-2">
                <div class="col-6">
                    <div class="form-check">
                        <input type="checkbox" name="is_mandatory" id="gcMand" class="form-check-input" checked>
                        <label class="form-check-label" for="gcMand">Mandatory (all employees in grade)</label>
                    </div>
                </div>
                <div class="col-6">
                    <div class="form-check">
                        <input type="checkbox" name="is_active" id="gcActive" class="form-check-input" checked>
                        <label class="form-check-label" for="gcActive">Active</label>
                    </div>
                </div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-info text-white"><i class="fas fa-save me-1"></i>Save</button>
        </div>
    </form>
</div></div></div>

<!-- Component Library Modal -->
<div class="modal fade" id="compModal" tabindex="-1">
<div class="modal-dialog modal-lg">
<div class="modal-content">
    <div class="modal-header bg-success text-white">
        <h5 class="modal-title" id="compMTitle"><i class="fas fa-list me-2"></i>Salary Component</h5>
        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_component">
        <input type="hidden" name="component_id" id="compId" value="0">
        <div class="modal-body">
            <div class="row g-3">
                <div class="col-3"><label class="form-label fw-semibold">Code <span class="text-danger">*</span></label>
                    <input type="text" name="component_code" id="compCode" class="form-control" required placeholder="PETROL" style="text-transform:uppercase"></div>
                <div class="col-5"><label class="form-label fw-semibold">Name <span class="text-danger">*</span></label>
                    <input type="text" name="component_name" id="compName" class="form-control" required placeholder="Petrol Allowance"></div>
                <div class="col-4"><label class="form-label fw-semibold">Order</label>
                    <input type="number" name="component_order" id="compOrd" class="form-control" value="0"></div>
                <div class="col-4"><label class="form-label fw-semibold">Type <span class="text-danger">*</span></label>
                    <select name="component_type" id="compType" class="form-select">
                        <option value="EARNING">Earning</option>
                        <option value="DEDUCTION">Deduction</option>
                        <option value="STATUTORY">Statutory</option>
                    </select></div>
                <div class="col-4"><label class="form-label fw-semibold">Calculation</label>
                    <select name="calculation_type" id="compCalc" class="form-select" onchange="toggleCompAmount(this.value)">
                        <option value="FIXED">Fixed Amount</option>
                        <option value="PERCENTAGE">% of Salary</option>
                        <option value="FORMULA">Formula</option>
                    </select></div>
                <div class="col-4"><label class="form-label fw-semibold">% Base</label>
                    <select name="percentage_base" id="compBase" class="form-select">
                        <option value="BASIC">Basic Salary</option>
                        <option value="GROSS">Gross Salary</option>
                    </select></div>
                <div class="col-4"><label class="form-label fw-semibold">Default Value</label>
                    <input type="number" name="default_value" id="compVal" class="form-control" step="100" value="0"></div>
                <div class="col-4"><label class="form-label fw-semibold">Applies To</label>
                    <select name="applies_to" id="compApplies" class="form-select">
                        <option value="ALL">All Employees</option>
                        <option value="PERMANENT">Permanent Only</option>
                        <option value="CONTRACT">Contract Only</option>
                        <option value="TECHNICAL">Technical Staff</option>
                        <option value="DAILY_WAGES">Daily Wages</option>
                    </select></div>
                <div class="col-4">
                    <div class="form-check mt-2"><input type="checkbox" name="is_taxable" id="compTax" class="form-check-input" checked><label class="form-check-label" for="compTax">Taxable</label></div>
                    <div class="form-check"><input type="checkbox" name="is_active" id="compActive" class="form-check-input" checked><label class="form-check-label" for="compActive">Active</label></div>
                </div>
                <div class="col-8"><label class="form-label fw-semibold">Description</label>
                    <input type="text" name="description" id="compDesc" class="form-control" placeholder="Brief description of this component"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-success"><i class="fas fa-save me-1"></i>Save Component</button>
        </div>
    </form>
</div></div></div>

<!-- Employee Component Override Modal -->
<div class="modal fade" id="empCompModal" tabindex="-1">
<div class="modal-dialog">
<div class="modal-content">
    <div class="modal-header bg-warning">
        <h5 class="modal-title"><i class="fas fa-user-cog me-2"></i>Add Employee Component Override</h5>
        <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
    </div>
    <form method="POST">
        <input type="hidden" name="action" value="save_emp_component">
        <input type="hidden" name="employee_id" id="ecEmpId">
        <div class="modal-body">
            <div class="mb-3"><label class="form-label fw-semibold">Component <span class="text-danger">*</span></label>
                <select name="component_id" class="form-select" required>
                    <option value="">Select Component</option>
                    <?php foreach(['EARNING','DEDUCTION','STATUTORY'] as $ct): ?>
                    <optgroup label="<?= $ct ?>">
                    <?php foreach($components as $c): if($c['component_type']!==$ct) continue; ?>
                    <option value="<?= $c['id'] ?>"><?= htmlspecialchars($c['component_code'].' — '.$c['component_name']) ?></option>
                    <?php endforeach; ?>
                    </optgroup>
                    <?php endforeach; ?>
                </select></div>
            <div class="row g-2 mb-3">
                <div class="col-5"><label class="form-label fw-semibold">Calculation</label>
                    <select name="calc_type" class="form-select" onchange="toggleECAmount(this.value)">
                        <option value="FIXED">Fixed Amount</option>
                        <option value="PERCENTAGE">% of Basic</option>
                        <option value="FORMULA">Formula</option>
                    </select></div>
                <div class="col-4" id="ecFixedDiv"><label class="form-label fw-semibold">Amount (NPR)</label>
                    <input type="number" name="fixed_amount" class="form-control" step="50" min="0" value="0"></div>
                <div class="col-3" id="ecPctDiv" style="display:none"><label class="form-label fw-semibold">%</label>
                    <input type="number" name="pct_value" class="form-control" step="0.1" min="0" value="0"></div>
            </div>
            <div class="row g-2">
                <div class="col-6"><label class="form-label fw-semibold">Effective From</label>
                    <input type="date" name="effective_from" class="form-control" value="<?= date('Y-m-d') ?>"></div>
                <div class="col-6 d-flex align-items-end">
                    <div class="form-check mb-1">
                        <input type="checkbox" name="is_active" id="ecActive" class="form-check-input" checked>
                        <label class="form-check-label" for="ecActive">Active</label>
                    </div>
                </div>
                <div class="col-12"><label class="form-label fw-semibold">Remarks</label>
                    <input type="text" name="remarks" class="form-control" placeholder="e.g. Petrol for field visits, Advance recovery for loan taken Jan 2082"></div>
            </div>
        </div>
        <div class="modal-footer">
            <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
            <button type="submit" class="btn btn-warning"><i class="fas fa-save me-1"></i>Save Override</button>
        </div>
    </form>
</div></div></div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Grade modal
function openGradeModal(g) {
    const isEdit = !!g;
    document.getElementById('gradeId').value      = g?.id || 0;
    document.getElementById('gradeCode').value    = g?.grade_code || '';
    document.getElementById('gradeCode').readOnly = isEdit;
    document.getElementById('gradeName').value    = g?.grade_name || '';
    document.getElementById('gradeLevel').value   = g?.level_id || '';
    document.getElementById('gradeEmpType').value = g?.emp_type || '';
    document.getElementById('gradeFY').value      = g?.fiscal_year_id || '';
    document.getElementById('gradeOpening').value = g?.opening_basic || '';
    document.getElementById('gradeMid').value     = g?.mid_basic || '';
    document.getElementById('gradeMax').value     = g?.max_basic || '';
    document.getElementById('gradeIncr').value    = g?.increment_amount || 0;
    document.getElementById('gradeActive').checked = g ? !!g.is_active : true;
    document.getElementById('gradeMTitle').textContent = isEdit ? '✎ Edit Grade' : '+ Add Salary Grade';
    new bootstrap.Modal(document.getElementById('gradeModal')).show();
}

// Grade component modal
function openGradeCompModal(gradeId, gc) {
    document.getElementById('gcGradeId').value  = gradeId;
    document.getElementById('gcCompId').value   = gc?.component_id || '';
    document.getElementById('gcCompSelect').value = gc?.component_id || '';
    document.getElementById('gcCalc').value     = gc?.calculation_type || 'FIXED';
    document.getElementById('gcFixed').value    = gc?.fixed_amount || 0;
    document.getElementById('gcPct').value      = gc?.percentage_value || 0;
    document.getElementById('gcMand').checked   = gc ? !!gc.is_mandatory : true;
    document.getElementById('gcActive').checked = gc ? !!gc.is_active : true;
    toggleGCAmount(gc?.calculation_type || 'FIXED');
    new bootstrap.Modal(document.getElementById('gradeCompModal')).show();
}
function toggleGCAmount(v) {
    document.getElementById('gcFixedDiv').style.display = v==='PERCENTAGE'?'none':'block';
    document.getElementById('gcPctDiv').style.display   = v==='PERCENTAGE'?'block':'none';
}

// Component library modal
function openCompModal(c) {
    document.getElementById('compId').value      = c?.id || 0;
    document.getElementById('compCode').value    = c?.component_code || '';
    document.getElementById('compCode').readOnly = !!c?.id;
    document.getElementById('compName').value    = c?.component_name || '';
    document.getElementById('compType').value    = c?.component_type || 'EARNING';
    document.getElementById('compCalc').value    = c?.calculation_type || 'FIXED';
    document.getElementById('compBase').value    = c?.percentage_base || 'BASIC';
    document.getElementById('compVal').value     = c?.default_value || 0;
    document.getElementById('compApplies').value = c?.applies_to || 'ALL';
    document.getElementById('compOrd').value     = c?.component_order || 0;
    document.getElementById('compDesc').value    = c?.description || '';
    document.getElementById('compTax').checked   = c ? !!c.is_taxable : true;
    document.getElementById('compActive').checked= c ? !!c.is_active  : true;
    document.getElementById('compMTitle').textContent = c?.id ? '✎ Edit Component' : '+ Add Component';
    new bootstrap.Modal(document.getElementById('compModal')).show();
}
function toggleCompAmount(v) {
    // placeholder for future enhancement
}

// Employee component modal
function openEmpCompModal(empId) {
    document.getElementById('ecEmpId').value = empId;
    new bootstrap.Modal(document.getElementById('empCompModal')).show();
}
function toggleECAmount(v) {
    document.getElementById('ecFixedDiv').style.display = v==='PERCENTAGE'?'none':'block';
    document.getElementById('ecPctDiv').style.display   = v==='PERCENTAGE'?'block':'none';
}

// Bulk assign: update basic range hint
function updateBasicRange(sel) {
    const opt = sel.options[sel.selectedIndex];
    const opening = opt.dataset.opening;
    const max     = opt.dataset.max;
    document.getElementById('autoBasic').value = opening || '';
    document.getElementById('autoBasic').min   = opening || 0;
    document.getElementById('autoBasic').max   = max || '';
    document.getElementById('basicRange').textContent =
        opening ? `Range: NPR ${parseInt(opening).toLocaleString()} → ${parseInt(max||0).toLocaleString()}` : '';
}

// Bulk assign: select all/none
function toggleAll(state) {
    document.querySelectorAll('.emp-check').forEach(c => c.checked = state);
    document.getElementById('checkAll').checked = state;
}

// Employee search
document.getElementById('empSearch')?.addEventListener('input', function() {
    const q = this.value.toLowerCase();
    document.querySelectorAll('.emp-search-row').forEach(row => {
        row.style.display = row.textContent.toLowerCase().includes(q) ? '' : 'none';
    });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>

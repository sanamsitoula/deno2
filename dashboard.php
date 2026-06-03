<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/bootstrap.php';

use Administrator\Deno2\Core\Database;

redirect_if_not_logged_in();

$db = Database::getConnection();

// ── Employee Stats ────────────────────────────────────────────
$empStats = $db->query("
    SELECT
        COUNT(*)                                              AS total,
        COUNT(*) FILTER (WHERE emp_status = 'ACTIVE')        AS active,
        COUNT(*) FILTER (WHERE emp_status = 'DRAFT')         AS draft,
        COUNT(*) FILTER (WHERE emp_type  = 'PERMANENT')      AS permanent,
        COUNT(*) FILTER (WHERE emp_type  = 'CONTRACT')       AS contract,
        COUNT(*) FILTER (WHERE is_technical = TRUE)          AS technical,
        COUNT(*) FILTER (WHERE is_ssf_enrolled = TRUE)       AS ssf_enrolled
    FROM employee WHERE deleted_date IS NULL
")->fetch(PDO::FETCH_ASSOC);

// ── Attendance – today ────────────────────────────────────────
$todayEng = date('Y-m-d');
$attToday = $db->prepare("
    SELECT
        COUNT(*)                                                      AS marked,
        COUNT(*) FILTER (WHERE ast.status_code = 'P')                AS present,
        COUNT(*) FILTER (WHERE ast.status_code = 'A')                AS absent,
        COUNT(*) FILTER (WHERE ast.status_code = 'HD')               AS half_day,
        COUNT(*) FILTER (WHERE ast.status_code = 'L')                AS on_leave,
        COUNT(*) FILTER (WHERE a.check_in_time  IS NOT NULL)          AS checked_in,
        COUNT(*) FILTER (WHERE a.check_out_time IS NOT NULL)          AS checked_out
    FROM attendance a
    LEFT JOIN attendance_status ast ON a.status_id = ast.id
    WHERE a.attendance_date_eng = :today
");
$attToday->execute([':today' => $todayEng]);
$attStats = $attToday->fetch(PDO::FETCH_ASSOC);

// ── ZKTeco device status ──────────────────────────────────────
$devices = $db->query("
    SELECT device_name, ip_address, last_pull_at, last_pull_status, last_pull_records, is_active
    FROM zkteco_devices ORDER BY priority
")->fetchAll(PDO::FETCH_ASSOC);

$lastPull = $db->query("
    SELECT pull_date, schedule_type, inserted_records, updated_records, status, completed_at
    FROM zkteco_pull_log ORDER BY completed_at DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// ── Job Tickets ───────────────────────────────────────────────
$jobStats = $db->query("
    SELECT
        COUNT(*)                                                      AS total,
        COUNT(*) FILTER (WHERE status = 'pending')                    AS pending,
        COUNT(*) FILTER (WHERE status = 'bp_completed')               AS completed,
        SUM(print_qty)                                                AS total_print_qty
    FROM job_ticket
")->fetch(PDO::FETCH_ASSOC);

$recentJobs = $db->query("
    SELECT jt.job_ticket_code, jt.status, jt.print_qty, jt.date_nep,
           b.title AS book_title
    FROM job_ticket jt
    LEFT JOIN books b ON jt.book_id = b.id
    ORDER BY jt.id DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);

// ── Payroll ───────────────────────────────────────────────────
$payrollStats = $db->query("
    SELECT
        COUNT(*)                   AS total_runs,
        COALESCE(SUM(total_gross), 0)       AS total_gross,
        COALESCE(SUM(total_net_payable), 0) AS total_net
    FROM payroll_processing
")->fetch(PDO::FETCH_ASSOC);

$lastPayroll = $db->query("
    SELECT payroll_code, payroll_month, payroll_year, status,
           total_employees, total_gross, total_net_payable, processed_at
    FROM payroll_processing ORDER BY id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// ── Vehicle ───────────────────────────────────────────────────
$vehicleStats = $db->query("
    SELECT
        COUNT(*)                                                         AS total,
        COUNT(*) FILTER (WHERE status = 'ACTIVE')                        AS active,
        COALESCE(SUM(CASE WHEN EXTRACT(MONTH FROM log_date_eng)=EXTRACT(MONTH FROM CURRENT_DATE)
                           AND EXTRACT(YEAR  FROM log_date_eng)=EXTRACT(YEAR  FROM CURRENT_DATE)
                     THEN total_km END), 0)                              AS km_this_month
    FROM vehicles v
    LEFT JOIN vehicle_daily_logs vdl ON vdl.vehicle_id = v.id
    GROUP BY 1,2 LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Simpler vehicle count
$vehicleCount = $db->query("SELECT COUNT(*) AS total, COUNT(*) FILTER (WHERE status='ACTIVE') AS active FROM vehicles")->fetch(PDO::FETCH_ASSOC);
$kmMonth = $db->query("SELECT COALESCE(SUM(total_km),0) AS km FROM vehicle_daily_logs WHERE EXTRACT(MONTH FROM log_date_eng)=EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM log_date_eng)=EXTRACT(YEAR FROM CURRENT_DATE)")->fetchColumn();
$fuelMonth = $db->query("SELECT COALESCE(SUM(quantity),0) AS litres FROM fuel_coupons WHERE EXTRACT(MONTH FROM created_at)=EXTRACT(MONTH FROM CURRENT_DATE) AND EXTRACT(YEAR FROM created_at)=EXTRACT(YEAR FROM CURRENT_DATE)")->fetchColumn();

// ── Production (Deno) ─────────────────────────────────────────
$denoStats = $db->query("
    SELECT
        COUNT(*) AS total_entries,
        COALESCE(SUM(quantity), 0) AS total_qty
    FROM deno
    WHERE EXTRACT(MONTH FROM created_at) = EXTRACT(MONTH FROM CURRENT_DATE)
      AND EXTRACT(YEAR  FROM created_at) = EXTRACT(YEAR  FROM CURRENT_DATE)
")->fetch(PDO::FETCH_ASSOC);

// ── Department breakdown ──────────────────────────────────────
$deptBreakdown = $db->query("
    SELECT dep.name, COUNT(e.id) AS emp_count
    FROM department dep
    LEFT JOIN employee e ON e.department_id = dep.id AND e.emp_status='ACTIVE' AND e.deleted_date IS NULL
    WHERE dep.status = true
    GROUP BY dep.id, dep.name
    HAVING COUNT(e.id) > 0
    ORDER BY emp_count DESC
    LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Salary component overview ─────────────────────────────────
$salaryComponents = $db->query("
    SELECT component_code, component_name, component_type, is_active
    FROM salary_components ORDER BY component_type, component_code
")->fetchAll(PDO::FETCH_ASSOC);

// ── Tax slabs ─────────────────────────────────────────────────
$taxSlabCount = $db->query("SELECT COUNT(*) FROM tax_slabs WHERE is_active=true")->fetchColumn();

// ── Fiscal year ───────────────────────────────────────────────
$currentFY = $db->query("SELECT fiscal_code, start_date, end_date FROM fiscal_years ORDER BY start_date DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);

include $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
:root {
    --primary: #2c3e8c;
    --success: #1a9e5f;
    --warning: #e8a020;
    --danger:  #d63031;
    --info:    #0984e3;
    --purple:  #6c5ce7;
    --teal:    #00b894;
    --card-radius: 12px;
}
body { background: #f0f2f8; }

.dash-section-title {
    font-size: 0.72rem;
    font-weight: 700;
    letter-spacing: 1.2px;
    text-transform: uppercase;
    color: #8492a6;
    margin: 1.6rem 0 0.6rem;
}

/* KPI Cards */
.kpi-card {
    background: #fff;
    border-radius: var(--card-radius);
    padding: 1.1rem 1.2rem;
    display: flex;
    align-items: center;
    gap: 0.9rem;
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    height: 100%;
    border-left: 4px solid var(--primary);
    transition: transform .18s, box-shadow .18s;
}
.kpi-card:hover { transform: translateY(-3px); box-shadow: 0 6px 18px rgba(0,0,0,.09); }
.kpi-icon {
    width: 50px; height: 50px;
    border-radius: 10px;
    display: flex; align-items: center; justify-content: center;
    font-size: 1.4rem; flex-shrink: 0;
}
.kpi-body .kpi-label { font-size: .72rem; color: #8492a6; font-weight: 600; text-transform: uppercase; letter-spacing:.6px; }
.kpi-body .kpi-value { font-size: 1.6rem; font-weight: 700; line-height: 1.1; color: #2d3436; }
.kpi-body .kpi-sub   { font-size: .72rem; color: #8492a6; margin-top:2px; }

.kpi-card.c-blue   { border-left-color: var(--primary); }  .kpi-card.c-blue   .kpi-icon { background: #eef2ff; color: var(--primary); }
.kpi-card.c-green  { border-left-color: var(--success); }  .kpi-card.c-green  .kpi-icon { background: #e6f9f1; color: var(--success); }
.kpi-card.c-orange { border-left-color: var(--warning); }  .kpi-card.c-orange .kpi-icon { background: #fff8ec; color: var(--warning); }
.kpi-card.c-red    { border-left-color: var(--danger);  }  .kpi-card.c-red    .kpi-icon { background: #fff0f0; color: var(--danger);  }
.kpi-card.c-purple { border-left-color: var(--purple);  }  .kpi-card.c-purple .kpi-icon { background: #f1f0ff; color: var(--purple);  }
.kpi-card.c-teal   { border-left-color: var(--teal);    }  .kpi-card.c-teal   .kpi-icon { background: #e8fff9; color: var(--teal);    }
.kpi-card.c-info   { border-left-color: var(--info);    }  .kpi-card.c-info   .kpi-icon { background: #e8f4ff; color: var(--info);    }

/* Panel cards */
.panel {
    background: #fff;
    border-radius: var(--card-radius);
    box-shadow: 0 2px 8px rgba(0,0,0,.06);
    overflow: hidden;
}
.panel-header {
    padding: .75rem 1.1rem;
    border-bottom: 1px solid #f0f2f8;
    display: flex; align-items: center; justify-content: space-between;
}
.panel-header h6 { margin: 0; font-weight: 700; font-size: .82rem; color: #2d3436; }
.panel-body { padding: 1rem 1.1rem; }

/* Status dots */
.dot { width:9px;height:9px;border-radius:50%;display:inline-block;margin-right:5px; }
.dot-green  { background:#1a9e5f; }
.dot-orange { background:#e8a020; }
.dot-red    { background:#d63031; }
.dot-grey   { background:#b2bec3; }

/* Horizontal bar */
.h-bar-wrap { margin-bottom:.55rem; }
.h-bar-label { display:flex;justify-content:space-between;font-size:.75rem;margin-bottom:2px; }
.h-bar { height:7px;border-radius:4px;background:#f0f2f8;overflow:hidden; }
.h-bar-fill { height:100%;border-radius:4px;transition:width .6s; }

/* Status badges */
.badge-pending    { background:#fff3e0;color:#e65100; }
.badge-completed  { background:#e8f5e9;color:#1b5e20; }
.badge-active     { background:#e3f2fd;color:#0d47a1; }

/* Device card */
.device-card { border: 1px solid #f0f2f8; border-radius:8px; padding:.7rem 1rem; margin-bottom:.5rem; }
.device-card:last-child { margin-bottom:0; }
</style>

<div class="container-fluid px-4 py-3" style="max-width:1400px;">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-3">
        <div>
            <h4 class="mb-0 fw-bold" style="color:var(--primary)">
                <i class="bi bi-grid-1x2-fill me-2"></i>Management Dashboard
            </h4>
            <small class="text-muted">
                <?= date('l, d F Y') ?> &nbsp;|&nbsp;
                FY: <strong><?= htmlspecialchars($currentFY['fiscal_code'] ?? 'N/A') ?></strong>
            </small>
        </div>
        <div class="d-flex gap-2">
            <a href="/deno2/hr/employee/index.php" class="btn btn-sm btn-outline-primary">
                <i class="bi bi-people"></i> Employees
            </a>
            <a href="/deno2/hr/modules/payroll/process.php" class="btn btn-sm btn-primary">
                <i class="bi bi-cash-stack"></i> Run Payroll
            </a>
        </div>
    </div>

    <!-- ══ SECTION 1: WORKFORCE KPIs ══ -->
    <p class="dash-section-title"><i class="bi bi-people-fill me-1"></i>Workforce Overview</p>
    <div class="row g-3 mb-1">
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-blue">
                <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Employees</div>
                    <div class="kpi-value"><?= number_format($empStats['total']) ?></div>
                    <div class="kpi-sub"><?= $empStats['active'] ?> active</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-green">
                <div class="kpi-icon"><i class="bi bi-person-check-fill"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Permanent</div>
                    <div class="kpi-value"><?= number_format($empStats['permanent']) ?></div>
                    <div class="kpi-sub"><?= $empStats['contract'] ?> contract</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-purple">
                <div class="kpi-icon"><i class="bi bi-cpu"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Technical Staff</div>
                    <div class="kpi-value"><?= number_format($empStats['technical']) ?></div>
                    <div class="kpi-sub">skilled workers</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-teal">
                <div class="kpi-icon"><i class="bi bi-shield-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">SSF Enrolled</div>
                    <div class="kpi-value"><?= number_format($empStats['ssf_enrolled']) ?></div>
                    <div class="kpi-sub">social security</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-orange">
                <div class="kpi-icon"><i class="bi bi-pencil-square"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Draft Employees</div>
                    <div class="kpi-value"><?= number_format($empStats['draft']) ?></div>
                    <div class="kpi-sub">pending activation</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ SECTION 2: ATTENDANCE KPIs ══ -->
    <p class="dash-section-title"><i class="bi bi-clock-fill me-1"></i>Attendance — Today (<?= date('d M Y') ?>)</p>
    <div class="row g-3 mb-1">
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-blue">
                <div class="kpi-icon"><i class="bi bi-calendar-check-fill"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Records Marked</div>
                    <div class="kpi-value"><?= number_format($attStats['marked']) ?></div>
                    <div class="kpi-sub">of <?= $empStats['active'] ?> active</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-green">
                <div class="kpi-icon"><i class="bi bi-person-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Present</div>
                    <div class="kpi-value"><?= number_format($attStats['present']) ?></div>
                    <div class="kpi-sub"><?= $attStats['checked_in'] ?> checked in</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-red">
                <div class="kpi-icon"><i class="bi bi-person-x"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Absent</div>
                    <div class="kpi-value"><?= number_format($attStats['absent']) ?></div>
                    <div class="kpi-sub"><?= $attStats['half_day'] ?> half day</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-orange">
                <div class="kpi-icon"><i class="bi bi-briefcase"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">On Leave</div>
                    <div class="kpi-value"><?= number_format($attStats['on_leave']) ?></div>
                    <div class="kpi-sub"><?= $attStats['checked_out'] ?> checked out</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-info">
                <div class="kpi-icon"><i class="bi bi-fingerprint"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">ZKTeco Pulls Today</div>
                    <div class="kpi-value"><?= count(array_filter($devices, fn($d) => !empty($d['last_pull_at']) && date('Y-m-d', strtotime($d['last_pull_at'])) === $todayEng)) ?></div>
                    <div class="kpi-sub"><?= count($devices) ?> device(s) configured</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ SECTION 3: PAYROLL & TAX KPIs ══ -->
    <p class="dash-section-title"><i class="bi bi-cash-coin me-1"></i>Payroll & Tax</p>
    <div class="row g-3 mb-1">
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-green">
                <div class="kpi-icon"><i class="bi bi-receipt-cutoff"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Payroll Runs</div>
                    <div class="kpi-value"><?= number_format($payrollStats['total_runs']) ?></div>
                    <div class="kpi-sub">total processed</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-blue">
                <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Net Paid</div>
                    <div class="kpi-value">
                        <?= $payrollStats['total_net'] > 0
                            ? 'NPR ' . number_format($payrollStats['total_net'] / 1000, 0) . 'K'
                            : '—' ?>
                    </div>
                    <div class="kpi-sub">all runs combined</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-teal">
                <div class="kpi-icon"><i class="bi bi-shield-fill-check"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Tax Slabs Active</div>
                    <div class="kpi-value"><?= $taxSlabCount ?></div>
                    <div class="kpi-sub">FY <?= htmlspecialchars($currentFY['fiscal_code'] ?? '') ?></div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-purple">
                <div class="kpi-icon"><i class="bi bi-file-earmark-text"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Salary Components</div>
                    <div class="kpi-value"><?= count($salaryComponents) ?></div>
                    <div class="kpi-sub">earning + deduction</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-orange">
                <div class="kpi-icon"><i class="bi bi-bank2"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Employee Salary Records</div>
                    <div class="kpi-value"><?= $db->query("SELECT COUNT(*) FROM employee_salary WHERE is_current=true")->fetchColumn() ?></div>
                    <div class="kpi-sub">current records</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ SECTION 4: VEHICLE & PRODUCTION KPIs ══ -->
    <p class="dash-section-title"><i class="bi bi-truck-fill me-1"></i>Fleet & Production</p>
    <div class="row g-3 mb-3">
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-blue">
                <div class="kpi-icon"><i class="bi bi-truck"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Total Vehicles</div>
                    <div class="kpi-value"><?= $vehicleCount['total'] ?></div>
                    <div class="kpi-sub"><?= $vehicleCount['active'] ?> active</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-teal">
                <div class="kpi-icon"><i class="bi bi-speedometer2"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">KM This Month</div>
                    <div class="kpi-value"><?= number_format($kmMonth) ?></div>
                    <div class="kpi-sub">km driven</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-orange">
                <div class="kpi-icon"><i class="bi bi-fuel-pump"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Fuel This Month</div>
                    <div class="kpi-value"><?= number_format($fuelMonth, 1) ?> L</div>
                    <div class="kpi-sub">litres issued</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-purple">
                <div class="kpi-icon"><i class="bi bi-ticket-perforated"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Job Tickets</div>
                    <div class="kpi-value"><?= number_format($jobStats['total']) ?></div>
                    <div class="kpi-sub"><?= $jobStats['pending'] ?> pending</div>
                </div>
            </div>
        </div>
        <div class="col-6 col-md-3 col-xl">
            <div class="kpi-card c-green">
                <div class="kpi-icon"><i class="bi bi-layers"></i></div>
                <div class="kpi-body">
                    <div class="kpi-label">Production (This Month)</div>
                    <div class="kpi-value"><?= number_format($denoStats['total_entries']) ?></div>
                    <div class="kpi-sub"><?= number_format($denoStats['total_qty']) ?> qty</div>
                </div>
            </div>
        </div>
    </div>

    <!-- ══ DETAIL PANELS ROW ══ -->
    <div class="row g-3">

        <!-- Department Breakdown -->
        <div class="col-md-6 col-xl-4">
            <div class="panel h-100">
                <div class="panel-header">
                    <h6><i class="bi bi-diagram-3 me-1"></i>Department Headcount</h6>
                    <a href="/deno2/hr/employee/index.php" class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:2px 8px;">View All</a>
                </div>
                <div class="panel-body">
                    <?php
                    $maxEmp = $deptBreakdown ? max(array_column($deptBreakdown, 'emp_count')) : 1;
                    foreach ($deptBreakdown as $dept):
                        $pct = $maxEmp > 0 ? round($dept['emp_count'] / $maxEmp * 100) : 0;
                    ?>
                    <div class="h-bar-wrap">
                        <div class="h-bar-label">
                            <span style="font-size:.75rem;max-width:70%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                <?= htmlspecialchars($dept['name']) ?>
                            </span>
                            <strong style="font-size:.75rem;"><?= $dept['emp_count'] ?></strong>
                        </div>
                        <div class="h-bar">
                            <div class="h-bar-fill" style="width:<?= $pct ?>%;background:var(--primary);opacity:.75;"></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ZKTeco Device Status -->
        <div class="col-md-6 col-xl-4">
            <div class="panel h-100">
                <div class="panel-header">
                    <h6><i class="bi bi-fingerprint me-1"></i>ZKTeco Devices</h6>
                    <a href="/deno2/attendance_device/zkteco_index.php" class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:2px 8px;">Manage</a>
                </div>
                <div class="panel-body">
                    <?php foreach ($devices as $dev): ?>
                    <div class="device-card">
                        <div class="d-flex justify-content-between align-items-center">
                            <div>
                                <span class="dot <?= $dev['is_active'] ? 'dot-green' : 'dot-grey' ?>"></span>
                                <strong style="font-size:.82rem;"><?= htmlspecialchars($dev['device_name']) ?></strong>
                                <br>
                                <small class="text-muted ms-3"><?= htmlspecialchars($dev['ip_address']) ?></small>
                            </div>
                            <div class="text-end">
                                <?php if ($dev['last_pull_status']): ?>
                                    <span class="badge <?= $dev['last_pull_status']==='SUCCESS' ? 'bg-success' : 'bg-warning text-dark' ?>" style="font-size:.65rem;">
                                        <?= $dev['last_pull_status'] ?>
                                    </span>
                                <?php else: ?>
                                    <span class="badge bg-secondary" style="font-size:.65rem;">Never pulled</span>
                                <?php endif; ?>
                                <br>
                                <small class="text-muted" style="font-size:.65rem;">
                                    <?= $dev['last_pull_records'] ?> records
                                </small>
                            </div>
                        </div>
                        <?php if ($dev['last_pull_at']): ?>
                        <small class="text-muted" style="font-size:.66rem;margin-left:14px;">
                            Last pull: <?= date('d M Y H:i', strtotime($dev['last_pull_at'])) ?>
                        </small>
                        <?php endif; ?>
                    </div>
                    <?php endforeach; ?>

                    <?php if ($lastPull): ?>
                    <div class="mt-3 p-2 rounded" style="background:#f8f9fa;font-size:.74rem;">
                        <strong>Last Pull Log:</strong>
                        <?= htmlspecialchars($lastPull['pull_date'] ?? '') ?> —
                        <?= htmlspecialchars($lastPull['schedule_type'] ?? '') ?> |
                        +<?= $lastPull['inserted_records'] ?> inserted,
                        ~<?= $lastPull['updated_records'] ?> updated
                        <span class="badge <?= $lastPull['status']==='SUCCESS' ? 'bg-success' : 'bg-warning text-dark' ?> ms-1" style="font-size:.62rem;">
                            <?= $lastPull['status'] ?>
                        </span>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <!-- Job Tickets -->
        <div class="col-md-6 col-xl-4">
            <div class="panel h-100">
                <div class="panel-header">
                    <h6><i class="bi bi-ticket-perforated me-1"></i>Recent Job Tickets</h6>
                    <a href="/deno2/jobticket/index.php" class="btn btn-xs btn-outline-secondary" style="font-size:.72rem;padding:2px 8px;">View All</a>
                </div>
                <div class="panel-body p-0">
                    <table class="table table-sm table-hover mb-0" style="font-size:.77rem;">
                        <thead class="table-light">
                            <tr>
                                <th>Code</th>
                                <th>Book</th>
                                <th class="text-end">Qty</th>
                                <th>Status</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recentJobs as $job): ?>
                            <tr>
                                <td><code style="font-size:.72rem;"><?= htmlspecialchars($job['job_ticket_code']) ?></code></td>
                                <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;">
                                    <?= htmlspecialchars($job['book_title'] ?? 'N/A') ?>
                                </td>
                                <td class="text-end"><?= number_format($job['print_qty']) ?></td>
                                <td>
                                    <span class="badge badge-<?= $job['status'] === 'bp_completed' ? 'completed' : 'pending' ?>" style="font-size:.65rem;">
                                        <?= $job['status'] === 'bp_completed' ? 'Done' : ucfirst($job['status']) ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                    <div class="px-3 py-2 border-top" style="font-size:.75rem;background:#f8f9fa;">
                        <strong><?= $jobStats['total'] ?></strong> total tickets &nbsp;|&nbsp;
                        <strong><?= $jobStats['pending'] ?></strong> pending &nbsp;|&nbsp;
                        Total print qty: <strong><?= number_format($jobStats['total_print_qty']) ?></strong>
                    </div>
                </div>
            </div>
        </div>

        <!-- Payroll Last Run -->
        <div class="col-md-6 col-xl-4">
            <div class="panel h-100">
                <div class="panel-header">
                    <h6><i class="bi bi-cash-stack me-1"></i>Payroll Status</h6>
                    <a href="/deno2/hr/modules/payroll/process.php" class="btn btn-xs btn-outline-primary" style="font-size:.72rem;padding:2px 8px;">
                        <i class="bi bi-plus"></i> New Run
                    </a>
                </div>
                <div class="panel-body">
                    <?php if ($lastPayroll): ?>
                    <div class="mb-3">
                        <div class="d-flex justify-content-between">
                            <span style="font-size:.78rem;color:#8492a6;">Last Run</span>
                            <span class="badge <?= $lastPayroll['status']==='PAID' ? 'bg-success' : ($lastPayroll['status']==='CALCULATED' ? 'bg-info' : 'bg-secondary') ?>">
                                <?= $lastPayroll['status'] ?>
                            </span>
                        </div>
                        <h5 class="mb-0"><?= htmlspecialchars($lastPayroll['payroll_code']) ?></h5>
                        <small class="text-muted">
                            <?php
                            $months = ['','Baisakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin','Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
                            echo ($months[$lastPayroll['payroll_month']] ?? '') . ' ' . $lastPayroll['payroll_year'];
                            ?>
                        </small>
                    </div>
                    <div class="row g-2 text-center mb-2">
                        <div class="col-4">
                            <div style="background:#f0f2f8;border-radius:8px;padding:.5rem;">
                                <div style="font-size:1rem;font-weight:700;"><?= $lastPayroll['total_employees'] ?></div>
                                <div style="font-size:.66rem;color:#8492a6;">Employees</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div style="background:#e8f5e9;border-radius:8px;padding:.5rem;">
                                <div style="font-size:1rem;font-weight:700;color:#1a9e5f;">
                                    <?= number_format($lastPayroll['total_gross'] / 1000, 0) ?>K
                                </div>
                                <div style="font-size:.66rem;color:#8492a6;">Gross (NPR)</div>
                            </div>
                        </div>
                        <div class="col-4">
                            <div style="background:#e3f2fd;border-radius:8px;padding:.5rem;">
                                <div style="font-size:1rem;font-weight:700;color:#0d47a1;">
                                    <?= number_format($lastPayroll['total_net_payable'] / 1000, 0) ?>K
                                </div>
                                <div style="font-size:.66rem;color:#8492a6;">Net Pay (NPR)</div>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="text-center py-4 text-muted">
                        <i class="bi bi-cash-stack" style="font-size:2rem;opacity:.3;"></i>
                        <p class="mt-2 mb-0" style="font-size:.8rem;">No payroll runs yet</p>
                        <a href="/deno2/hr/modules/payroll/process.php" class="btn btn-sm btn-primary mt-2">Generate First Payroll</a>
                    </div>
                    <?php endif; ?>

                    <hr class="my-2">
                    <div style="font-size:.76rem;">
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Tax Slabs Configured</span>
                            <strong><?= $taxSlabCount ?> slabs</strong>
                        </div>
                        <div class="d-flex justify-content-between mb-1">
                            <span class="text-muted">Salary Components</span>
                            <strong><?= count($salaryComponents) ?></strong>
                        </div>
                        <div class="d-flex justify-content-between">
                            <span class="text-muted">SSF Enrolled Employees</span>
                            <strong><?= $empStats['ssf_enrolled'] ?></strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Salary Components -->
        <div class="col-md-6 col-xl-4">
            <div class="panel h-100">
                <div class="panel-header">
                    <h6><i class="bi bi-list-check me-1"></i>Salary Components</h6>
                </div>
                <div class="panel-body p-0">
                    <table class="table table-sm mb-0" style="font-size:.77rem;">
                        <thead class="table-light">
                            <tr><th>Code</th><th>Name</th><th>Type</th><th>Status</th></tr>
                        </thead>
                        <tbody>
                        <?php foreach ($salaryComponents as $sc): ?>
                            <tr>
                                <td><code style="font-size:.72rem;"><?= htmlspecialchars($sc['component_code']) ?></code></td>
                                <td><?= htmlspecialchars($sc['component_name']) ?></td>
                                <td>
                                    <span class="badge" style="font-size:.62rem;background:<?=
                                        $sc['component_type']==='EARNING'    ? '#e8f5e9;color:#1b5e20' :
                                        ($sc['component_type']==='DEDUCTION' ? '#fff3e0;color:#e65100' :
                                                                               '#f3e5f5;color:#4a148c')
                                    ?>;">
                                        <?= $sc['component_type'] ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="dot <?= $sc['is_active'] ? 'dot-green' : 'dot-grey' ?>"></span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <!-- Quick Links / Module Navigation -->
        <div class="col-md-6 col-xl-4">
            <div class="panel h-100">
                <div class="panel-header">
                    <h6><i class="bi bi-grid-fill me-1"></i>Quick Access</h6>
                </div>
                <div class="panel-body">
                    <?php
                    $links = [
                        ['icon'=>'bi-people-fill',        'label'=>'Employee List',       'url'=>'/deno2/hr/employee/index.php',                  'color'=>'#2c3e8c'],
                        ['icon'=>'bi-person-plus-fill',   'label'=>'Add Employee',        'url'=>'/deno2/hr/employee/create_enhanced.php',         'color'=>'#1a9e5f'],
                        ['icon'=>'bi-calendar-check-fill','label'=>'Mark Attendance',     'url'=>'/deno2/hr/modules/attendance/mark.php',          'color'=>'#0984e3'],
                        ['icon'=>'bi-fingerprint',        'label'=>'ZKTeco Dashboard',    'url'=>'/deno2/attendance_device/zkteco_index.php',      'color'=>'#6c5ce7'],
                        ['icon'=>'bi-cash-stack',         'label'=>'Process Payroll',     'url'=>'/deno2/hr/modules/payroll/process.php',          'color'=>'#00b894'],
                        ['icon'=>'bi-ticket-perforated',  'label'=>'Job Tickets',         'url'=>'/deno2/jobticket/index.php',                     'color'=>'#e8a020'],
                        ['icon'=>'bi-truck',              'label'=>'Vehicle Fleet',       'url'=>'/deno2/vehicle/vehicle_index.php',               'color'=>'#d63031'],
                        ['icon'=>'bi-fuel-pump',          'label'=>'Fuel Coupons',        'url'=>'/deno2/vehicle/fuel_coupons_v2.php',             'color'=>'#636e72'],
                        ['icon'=>'bi-bar-chart-fill',     'label'=>'Reports',             'url'=>'/deno2/reports.php',                             'color'=>'#2d3436'],
                        ['icon'=>'bi-diagram-3-fill',     'label'=>'Departments',         'url'=>'/deno2/hr/employee/department/index.php',        'color'=>'#74b9ff'],
                    ];
                    ?>
                    <div class="row g-2">
                        <?php foreach ($links as $link): ?>
                        <div class="col-6">
                            <a href="<?= $link['url'] ?>" class="d-flex align-items-center gap-2 text-decoration-none p-2 rounded"
                               style="background:#f8f9fa;transition:background .15s;"
                               onmouseover="this.style.background='#f0f2f8'" onmouseout="this.style.background='#f8f9fa'">
                                <i class="bi <?= $link['icon'] ?>" style="color:<?= $link['color'] ?>;font-size:1.1rem;width:24px;text-align:center;"></i>
                                <span style="font-size:.76rem;font-weight:600;color:#2d3436;"><?= $link['label'] ?></span>
                            </a>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

    </div><!-- /row detail panels -->

    <p class="text-center text-muted mt-4" style="font-size:.72rem;">
        JEMC Production Management System &nbsp;|&nbsp; Dashboard generated <?= date('d M Y H:i:s') ?>
    </p>

</div><!-- /container -->

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<?php ob_end_flush(); ?>

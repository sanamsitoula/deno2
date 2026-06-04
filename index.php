<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/bootstrap.php';
redirect_if_not_logged_in();

// ── Active fiscal year ────────────────────────────────────────
$stmt = $conn->prepare("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
$stmt->execute();
$activeFY = $stmt->fetch(PDO::FETCH_ASSOC);
$fyCode   = $activeFY['fiscal_code'] ?? 'N/A';
$fyId     = $activeFY['id'] ?? 0;

$today     = date('Y-m-d');
$monthNum  = date('m');
$nepMonths = ['01'=>'Baisakh','02'=>'Jestha','03'=>'Ashad','04'=>'Shrawan',
              '05'=>'Bhadra','06'=>'Ashoj','07'=>'Kartik','08'=>'Mangsir',
              '09'=>'Poush','10'=>'Magh','11'=>'Falgun','12'=>'Chaitra'];
$monthName = $nepMonths[$monthNum] ?? '';

// ── BS Date ────────────────────────────────────────────────────
use Administrator\Deno2\Shared\DateConverter;
$bsToday   = DateConverter::todayBs();
$bsParts   = explode('-', $bsToday);
$bsMonthNp = ['1'=>'बैशाख','2'=>'जेठ','3'=>'असार','4'=>'साउन','5'=>'भाद्र','6'=>'असोज','7'=>'कार्तिक','8'=>'मंसिर','9'=>'पुष','10'=>'माघ','11'=>'फाल्गुण','12'=>'चैत'];
$bsDayNp   = ['0'=>'आइतबार','1'=>'सोमबार','2'=>'मंगलबार','3'=>'बुधबार','4'=>'बिहिबार','5'=>'शुक्रबार','6'=>'शनिबार'];
$bsDateFull = $bsDayNp[date('w')] . ', ' . (int)$bsParts[2] . ' ' . ($bsMonthNp[(string)(int)$bsParts[1]] ?? '') . ' ' . $bsParts[0];

// ── PRODUCTION (Deno) ─────────────────────────────────────────
$prodToday = $conn->prepare("
    SELECT COALESCE(SUM(total_qty),0) AS qty, COALESCE(SUM(quantity_openpcs),0) AS openpcs
    FROM deno WHERE deno_date_eng = :d
");
$prodToday->execute([':d' => $today]);
$todayProd = $prodToday->fetch(PDO::FETCH_ASSOC);

$prodMonth = $conn->prepare("
    SELECT COALESCE(SUM(total_qty),0) AS qty, COALESCE(SUM(quantity_openpcs),0) AS openpcs
    FROM deno WHERE deno_month = :m AND deno_year = :y
");
$prodMonth->execute([':m' => $monthName, ':y' => $fyCode]);
$monthProd = $prodMonth->fetch(PDO::FETCH_ASSOC);

$prodTotal = $conn->query("SELECT COALESCE(SUM(total_qty),0) AS qty, COALESCE(SUM(quantity_openpcs),0) AS openpcs FROM deno")->fetch(PDO::FETCH_ASSOC);

// Last 14 days trend
$trend = $conn->query("
    SELECT deno_date_eng AS d, SUM(total_qty) AS qty
    FROM deno
    WHERE CAST(deno_date_eng AS DATE) >= CURRENT_DATE - INTERVAL '14 days'
    GROUP BY deno_date_eng ORDER BY deno_date_eng
")->fetchAll(PDO::FETCH_ASSOC);

// ── BOOKS ─────────────────────────────────────────────────────
$books = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(CASE WHEN is_translated=TRUE THEN 1 ELSE 0 END) AS translated
    FROM books
")->fetch(PDO::FETCH_ASSOC);

$booksThisFY = $conn->prepare("SELECT COUNT(*) FROM books WHERE fiscal_year=:fy");
$booksThisFY->execute([':fy'=>$fyCode]);
$booksThisFYCount = $booksThisFY->fetchColumn();

// ── JOB TICKETS ───────────────────────────────────────────────
$jobs = $conn->query("
    SELECT COUNT(*) AS total,
           SUM(print_qty) AS total_qty,
           SUM(COALESCE(print_done_qty,0)) AS done_qty,
           COUNT(*) FILTER (WHERE status='pending') AS pending,
           COUNT(*) FILTER (WHERE status='bp_completed') AS completed
    FROM job_ticket
")->fetch(PDO::FETCH_ASSOC);

// ── EMPLOYEES ─────────────────────────────────────────────────
$emp = $conn->query("
    SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE emp_status='ACTIVE')    AS active,
           COUNT(*) FILTER (WHERE emp_type='PERMANENT')   AS permanent,
           COUNT(*) FILTER (WHERE emp_type='CONTRACT')    AS contract,
           COUNT(*) FILTER (WHERE is_technical=TRUE)      AS technical
    FROM employee WHERE deleted_date IS NULL
")->fetch(PDO::FETCH_ASSOC);

// ── ATTENDANCE TODAY ──────────────────────────────────────────
$att = $conn->prepare("
    SELECT COUNT(*) AS marked,
           COUNT(*) FILTER (WHERE ast.status_code='P')  AS present,
           COUNT(*) FILTER (WHERE ast.status_code='A')  AS absent,
           COUNT(*) FILTER (WHERE ast.status_code='L')  AS on_leave,
           COUNT(*) FILTER (WHERE a.check_in_time IS NOT NULL)  AS checked_in,
           COUNT(*) FILTER (WHERE a.check_out_time IS NOT NULL) AS checked_out
    FROM attendance a
    LEFT JOIN attendance_status ast ON a.status_id=ast.id
    WHERE a.attendance_date_eng = :d
");
$att->execute([':d'=>$today]);
$attStats = $att->fetch(PDO::FETCH_ASSOC);

// Attendance last 7 days for mini-chart
$attTrend = $conn->query("
    SELECT attendance_date_eng AS d,
           COUNT(*) FILTER (WHERE ast.status_code='P') AS present,
           COUNT(*) FILTER (WHERE ast.status_code='A') AS absent
    FROM attendance a
    LEFT JOIN attendance_status ast ON a.status_id=ast.id
    WHERE a.attendance_date_eng >= CURRENT_DATE - INTERVAL '7 days'
    GROUP BY attendance_date_eng ORDER BY attendance_date_eng
")->fetchAll(PDO::FETCH_ASSOC);

// ── ZKTeco ────────────────────────────────────────────────────
$zk = $conn->query("
    SELECT device_name, is_active, last_pull_at, last_pull_status, last_pull_records
    FROM zkteco_devices ORDER BY priority LIMIT 5
")->fetchAll(PDO::FETCH_ASSOC);

// ── VEHICLES ──────────────────────────────────────────────────
// vehicles.status is BOOLEAN (true=active, false=inactive)
$veh = $conn->query("
    SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE status = true AND deleted_at IS NULL) AS active
    FROM vehicles
")->fetch(PDO::FETCH_ASSOC);

$kmMonth = $conn->query("
    SELECT COALESCE(SUM(total_km), 0)
    FROM vehicle_daily_logs
    WHERE EXTRACT(MONTH FROM log_date_eng) = EXTRACT(MONTH FROM CURRENT_DATE)
      AND EXTRACT(YEAR  FROM log_date_eng) = EXTRACT(YEAR  FROM CURRENT_DATE)
")->fetchColumn();

// fuel_coupons uses allocated_qty and issued_date_eng
$fuelMonth = $conn->query("
    SELECT COALESCE(SUM(allocated_qty), 0)
    FROM fuel_coupons
    WHERE EXTRACT(MONTH FROM issued_date_eng) = EXTRACT(MONTH FROM CURRENT_DATE)
      AND EXTRACT(YEAR  FROM issued_date_eng) = EXTRACT(YEAR  FROM CURRENT_DATE)
")->fetchColumn();

// ── PAYROLL ───────────────────────────────────────────────────
$payroll = $conn->query("
    SELECT payroll_code, payroll_month, payroll_year, status,
           total_employees, total_gross, total_net_payable
    FROM payroll_processing ORDER BY id DESC LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// ── FORMA / CTP / D2M ─────────────────────────────────────────
$d2mStats = $conn->query("
    SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE status='DRAFT')    AS draft,
           COUNT(*) FILTER (WHERE status='CHECKED')  AS checked,
           COUNT(*) FILTER (WHERE status='VERIFIED') AS verified,
           COUNT(*) FILTER (WHERE status='CLOSED')   AS closed
    FROM d2m
")->fetch(PDO::FETCH_ASSOC);

// ── Department headcount (top 6) ──────────────────────────────
$depts = $conn->query("
    SELECT dep.name, COUNT(e.id) AS cnt
    FROM department dep
    LEFT JOIN employee e ON e.department_id=dep.id AND e.emp_status='ACTIVE' AND e.deleted_date IS NULL
    WHERE dep.status=true
    GROUP BY dep.id, dep.name HAVING COUNT(e.id)>0
    ORDER BY cnt DESC LIMIT 6
")->fetchAll(PDO::FETCH_ASSOC);
$maxDept = $depts ? max(array_column($depts,'cnt')) : 1;

// ── Recent Deno entries ───────────────────────────────────────
$recentDeno = $conn->query("
    SELECT d.deno_date_eng, d.deno_month, d.total_qty, d.quantity_openpcs,
           b.book_name
    FROM deno d LEFT JOIN books b ON d.book_code=b.book_code
    ORDER BY d.id DESC LIMIT 8
")->fetchAll(PDO::FETCH_ASSOC);

// ── Reports quick list ────────────────────────────────────────
$reportLinks = [
    ['icon'=>'bi-graph-up-arrow',       'label'=>'Daily Production',      'url'=>'/deno2/denoreports/daily.php',              'color'=>'#2c3e8c'],
    ['icon'=>'bi-calendar-month',       'label'=>'Monthly Production',    'url'=>'/deno2/denoreports/monthly.php',            'color'=>'#1a9e5f'],
    ['icon'=>'bi-book-fill',            'label'=>'Books Report',          'url'=>'/deno2/denoreports/books.php',              'color'=>'#6c5ce7'],
    ['icon'=>'bi-translate',            'label'=>'Translated Books',      'url'=>'/deno2/denoreports/translated.php',         'color'=>'#00b894'],
    ['icon'=>'bi-clipboard2-pulse',     'label'=>'Job Ticket vs Printed', 'url'=>'/deno2/denoreports/jobticket_fp.php',       'color'=>'#e8a020'],
    ['icon'=>'bi-bar-chart-steps',      'label'=>'Process Control',       'url'=>'/deno2/report/production_process_control.php','color'=>'#d63031'],
    ['icon'=>'bi-graph-up',             'label'=>'Trend Report',          'url'=>'/deno2/report/trend.php',                  'color'=>'#0984e3'],
    ['icon'=>'bi-file-earmark-bar-graph','label'=>'Reconciliation',       'url'=>'/deno2/report/index.php',                  'color'=>'#636e72'],
    ['icon'=>'bi-printer',              'label'=>'Forma Printing',        'url'=>'/deno2/formaprinting/index.php',            'color'=>'#74b9ff'],
    ['icon'=>'bi-box-seam',             'label'=>'Pack & Stitch',         'url'=>'/deno2/bookpacking/index.php',              'color'=>'#fd79a8'],
];

// JSON for charts
$trendDates = json_encode(array_column($trend, 'd'));
$trendQty   = json_encode(array_map(fn($r)=>(int)$r['qty'], $trend));
$attDates   = json_encode(array_column($attTrend, 'd'));
$attPresent = json_encode(array_map(fn($r)=>(int)$r['present'], $attTrend));
$attAbsent  = json_encode(array_map(fn($r)=>(int)$r['absent'],  $attTrend));

include $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
:root{--blue:#2c3e8c;--green:#1a9e5f;--orange:#e8a020;--red:#d63031;--purple:#6c5ce7;--teal:#00b894;--info:#0984e3;--r:10px}
body{background:#f0f2f8}
.page-header{background:linear-gradient(135deg,#2c3e8c 0%,#3a52b0 100%);color:#fff;padding:1.2rem 1.5rem;margin-bottom:1.5rem;border-radius:0 0 16px 16px}
.section-label{font-size:.68rem;font-weight:800;letter-spacing:1.4px;text-transform:uppercase;color:#8492a6;margin:1.4rem 0 .55rem}

/* KPI */
.kpi{background:#fff;border-radius:var(--r);padding:1rem 1.1rem;display:flex;align-items:center;gap:.8rem;box-shadow:0 2px 8px rgba(0,0,0,.06);height:100%;border-left:4px solid var(--blue);transition:transform .15s,box-shadow .15s}
.kpi:hover{transform:translateY(-3px);box-shadow:0 6px 20px rgba(0,0,0,.1)}
.kpi-icon{width:46px;height:46px;border-radius:9px;display:flex;align-items:center;justify-content:center;font-size:1.3rem;flex-shrink:0}
.kpi-val{font-size:1.55rem;font-weight:800;line-height:1;color:#2d3436}
.kpi-lbl{font-size:.68rem;font-weight:700;color:#8492a6;text-transform:uppercase;letter-spacing:.6px}
.kpi-sub{font-size:.68rem;color:#b2bec3;margin-top:2px}
.kpi.c-blue{border-left-color:var(--blue)}.kpi.c-blue .kpi-icon{background:#eef2ff;color:var(--blue)}
.kpi.c-green{border-left-color:var(--green)}.kpi.c-green .kpi-icon{background:#e6f9f1;color:var(--green)}
.kpi.c-orange{border-left-color:var(--orange)}.kpi.c-orange .kpi-icon{background:#fff8ec;color:var(--orange)}
.kpi.c-red{border-left-color:var(--red)}.kpi.c-red .kpi-icon{background:#fff0f0;color:var(--red)}
.kpi.c-purple{border-left-color:var(--purple)}.kpi.c-purple .kpi-icon{background:#f1f0ff;color:var(--purple)}
.kpi.c-teal{border-left-color:var(--teal)}.kpi.c-teal .kpi-icon{background:#e8fff9;color:var(--teal)}
.kpi.c-info{border-left-color:var(--info)}.kpi.c-info .kpi-icon{background:#e8f4ff;color:var(--info)}

/* Panels */
.panel{background:#fff;border-radius:var(--r);box-shadow:0 2px 8px rgba(0,0,0,.06);overflow:hidden;height:100%}
.panel-hdr{padding:.65rem 1rem;border-bottom:1px solid #f0f2f8;display:flex;align-items:center;justify-content:space-between}
.panel-hdr h6{margin:0;font-weight:800;font-size:.8rem;color:#2d3436}
.panel-body{padding:.9rem 1rem}

/* H-Bar */
.hb{margin-bottom:.5rem}.hb-row{display:flex;justify-content:space-between;font-size:.72rem;margin-bottom:2px}
.hb-track{height:6px;background:#f0f2f8;border-radius:3px;overflow:hidden}
.hb-fill{height:100%;border-radius:3px;background:var(--blue);opacity:.7}

/* Report quick link */
.rlink{display:flex;align-items:center;gap:.6rem;padding:.45rem .6rem;border-radius:7px;background:#f8f9fa;text-decoration:none;transition:background .14s,transform .14s;margin-bottom:.35rem}
.rlink:hover{background:#eef2ff;transform:translateX(3px)}
.rlink-icon{width:30px;height:30px;border-radius:6px;display:flex;align-items:center;justify-content:center;font-size:.9rem;flex-shrink:0}
.rlink-text{font-size:.76rem;font-weight:600;color:#2d3436}

/* Device dot */
.dot{width:8px;height:8px;border-radius:50%;display:inline-block;margin-right:4px}
.dot-g{background:#1a9e5f}.dot-o{background:#e8a020}.dot-r{background:#d63031}.dot-x{background:#b2bec3}

/* Attendance bar inline */
.att-bar{height:5px;border-radius:3px;background:#f0f2f8;overflow:hidden;margin-top:3px}
.att-bar-p{height:100%;border-radius:3px;background:#1a9e5f}
</style>

<!-- PAGE HEADER -->
<div class="page-header">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h4 class="mb-0 fw-bold"><i class="bi bi-grid-1x2-fill me-2"></i>JEMC Management Dashboard</h4>
            <div class="d-flex gap-2 align-items-center flex-wrap mt-1" style="font-size:.82rem">
                <span class="badge" style="background:#2c3e8c;font-size:.75rem;padding:4px 10px">
                    <i class="bi bi-calendar3 me-1"></i><?= htmlspecialchars($bsDateFull) ?>
                </span>
                <span class="text-muted"><?= date('l, d F Y') ?></span>
                <span class="text-muted">·</span>
                <span class="text-muted">आ.व. <strong><?= htmlspecialchars($fyCode) ?></strong></span>
            </div>
        </div>
        <div class="d-flex gap-2 flex-wrap">
            <a href="/deno2/entries/deno.php" class="btn btn-sm btn-light fw-semibold"><i class="bi bi-plus-circle me-1"></i>Add Deno</a>
            <?php if(can_access_module('hr')): ?>
            <a href="/deno2/hr/modules/payroll/process.php" class="btn btn-sm btn-warning fw-semibold"><i class="bi bi-cash-stack me-1"></i>Run Payroll</a>
            <?php endif; ?>
            <a href="/deno2/reports.php" class="btn btn-sm btn-outline-light fw-semibold"><i class="bi bi-bar-chart me-1"></i>All Reports</a>
        </div>
    </div>
</div>

<div class="container-fluid px-3 px-md-4" style="max-width:1440px">

<!-- ══ PRODUCTION KPIs ══ -->
<p class="section-label"><i class="bi bi-layers-fill me-1"></i>Production Overview</p>
<div class="row g-3 mb-1">
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-blue">
            <div class="kpi-icon"><i class="bi bi-stack"></i></div>
            <div>
                <div class="kpi-lbl">Today's Production</div>
                <div class="kpi-val"><?= number_format($todayProd['qty']) ?></div>
                <div class="kpi-sub"><?= number_format($todayProd['openpcs']) ?> open pcs</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-green">
            <div class="kpi-icon"><i class="bi bi-calendar-month"></i></div>
            <div>
                <div class="kpi-lbl"><?= $monthName ?> Production</div>
                <div class="kpi-val"><?= number_format($monthProd['qty']) ?></div>
                <div class="kpi-sub"><?= number_format($monthProd['openpcs']) ?> open pcs</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-purple">
            <div class="kpi-icon"><i class="bi bi-infinity"></i></div>
            <div>
                <div class="kpi-lbl">Total All-Time</div>
                <div class="kpi-val"><?= number_format($prodTotal['qty']) ?></div>
                <div class="kpi-sub"><?= number_format($prodTotal['openpcs']) ?> open pcs</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-teal">
            <div class="kpi-icon"><i class="bi bi-book-fill"></i></div>
            <div>
                <div class="kpi-lbl">Books (FY)</div>
                <div class="kpi-val"><?= number_format($booksThisFYCount) ?></div>
                <div class="kpi-sub"><?= $books['translated'] ?> translated</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-orange">
            <div class="kpi-icon"><i class="bi bi-ticket-perforated"></i></div>
            <div>
                <div class="kpi-lbl">Job Tickets</div>
                <div class="kpi-val"><?= number_format($jobs['total']) ?></div>
                <div class="kpi-sub"><?= $jobs['pending'] ?> pending</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-info">
            <div class="kpi-icon"><i class="bi bi-clipboard2-check"></i></div>
            <div>
                <div class="kpi-lbl">D2M Records</div>
                <div class="kpi-val"><?= number_format($d2mStats['total']) ?></div>
                <div class="kpi-sub"><?= $d2mStats['verified'] ?> verified</div>
            </div>
        </div>
    </div>
</div>

<!-- ══ HR & ATTENDANCE KPIs ══ -->
<p class="section-label"><i class="bi bi-people-fill me-1"></i>HR & Attendance — <?= date('d M Y') ?></p>
<div class="row g-3 mb-1">
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-blue">
            <div class="kpi-icon"><i class="bi bi-people-fill"></i></div>
            <div>
                <div class="kpi-lbl">Employees</div>
                <div class="kpi-val"><?= number_format($emp['active']) ?></div>
                <div class="kpi-sub"><?= $emp['permanent'] ?> perm · <?= $emp['contract'] ?> contract</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-green">
            <div class="kpi-icon"><i class="bi bi-person-check"></i></div>
            <div>
                <div class="kpi-lbl">Present Today</div>
                <div class="kpi-val"><?= number_format($attStats['present']) ?></div>
                <div class="kpi-sub"><?= $attStats['checked_in'] ?> in · <?= $attStats['checked_out'] ?> out</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-red">
            <div class="kpi-icon"><i class="bi bi-person-x"></i></div>
            <div>
                <div class="kpi-lbl">Absent Today</div>
                <div class="kpi-val"><?= number_format($attStats['absent']) ?></div>
                <div class="kpi-sub"><?= $attStats['on_leave'] ?> on leave</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-purple">
            <div class="kpi-icon"><i class="bi bi-cpu"></i></div>
            <div>
                <div class="kpi-lbl">Technical Staff</div>
                <div class="kpi-val"><?= number_format($emp['technical']) ?></div>
                <div class="kpi-sub">skilled workers</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-teal">
            <div class="kpi-icon"><i class="bi bi-truck"></i></div>
            <div>
                <div class="kpi-lbl">Fleet</div>
                <div class="kpi-val"><?= $veh['active'] ?></div>
                <div class="kpi-sub"><?= number_format($kmMonth) ?> km this month</div>
            </div>
        </div>
    </div>
    <div class="col-6 col-md-3 col-xl">
        <div class="kpi c-orange">
            <div class="kpi-icon"><i class="bi bi-cash-stack"></i></div>
            <div>
                <div class="kpi-lbl">Last Payroll</div>
                <div class="kpi-val"><?= $payroll ? 'NPR '.number_format($payroll['total_net_payable']/1000,0).'K' : '—' ?></div>
                <div class="kpi-sub"><?= $payroll ? htmlspecialchars($payroll['payroll_code']) : 'No run yet' ?></div>
            </div>
        </div>
    </div>
</div>

<!-- ══ DETAIL ROWS ══ -->
<div class="row g-3 mt-1">

    <!-- Production Trend Chart -->
    <div class="col-md-8 col-xl-6">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-graph-up-arrow me-1" style="color:var(--blue)"></i>Production Trend — Last 14 Days</h6>
                <a href="/deno2/denoreports/daily.php" class="btn btn-xs btn-outline-secondary" style="font-size:.7rem;padding:2px 8px">Daily Report</a>
            </div>
            <div class="panel-body" style="padding-bottom:.5rem">
                <canvas id="prodChart" height="90"></canvas>
            </div>
        </div>
    </div>

    <!-- Attendance Trend Chart -->
    <div class="col-md-4 col-xl-3">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-calendar-check me-1" style="color:var(--green)"></i>Attendance (7 Days)</h6>
                <a href="/deno2/attendance_device/zkteco_index.php" class="btn btn-xs btn-outline-secondary" style="font-size:.7rem;padding:2px 8px">ZKTeco</a>
            </div>
            <div class="panel-body" style="padding-bottom:.5rem">
                <canvas id="attChart" height="105"></canvas>
                <div class="d-flex justify-content-center gap-3 mt-2" style="font-size:.68rem">
                    <span><span class="dot dot-g" style="display:inline-block"></span>Present</span>
                    <span><span class="dot dot-r" style="display:inline-block"></span>Absent</span>
                </div>
            </div>
        </div>
    </div>

    <!-- ZKTeco Devices -->
    <div class="col-md-4 col-xl-3">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-fingerprint me-1" style="color:var(--purple)"></i>Biometric Devices</h6>
            </div>
            <div class="panel-body">
                <?php foreach($zk as $dev): ?>
                <div class="d-flex justify-content-between align-items-start mb-2 pb-2 border-bottom">
                    <div>
                        <span class="dot <?= $dev['is_active'] ? 'dot-g' : 'dot-x' ?>" style="display:inline-block"></span>
                        <strong style="font-size:.78rem"><?= htmlspecialchars($dev['device_name']) ?></strong><br>
                        <small class="text-muted ms-3" style="font-size:.68rem">
                            <?= $dev['last_pull_at'] ? date('d M H:i', strtotime($dev['last_pull_at'])) : 'Never pulled' ?>
                        </small>
                    </div>
                    <div class="text-end">
                        <span class="badge <?= $dev['last_pull_status']==='SUCCESS' ? 'bg-success' : 'bg-secondary' ?>" style="font-size:.6rem">
                            <?= $dev['last_pull_status'] ?: 'N/A' ?>
                        </span><br>
                        <small class="text-muted" style="font-size:.65rem"><?= number_format($dev['last_pull_records']) ?> rec</small>
                    </div>
                </div>
                <?php endforeach; ?>
                <a href="/deno2/attendance_device/zkteco_index.php" class="btn btn-sm btn-outline-primary w-100 mt-1" style="font-size:.74rem">
                    <i class="bi bi-arrow-repeat"></i> Manage Devices
                </a>
            </div>
        </div>
    </div>

    <!-- Department Headcount -->
    <div class="col-md-4 col-xl-3">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-diagram-3 me-1" style="color:var(--teal)"></i>Department Headcount</h6>
                <a href="/deno2/hr/employee/index.php" class="btn btn-xs btn-outline-secondary" style="font-size:.7rem;padding:2px 8px">View All</a>
            </div>
            <div class="panel-body">
                <?php foreach($depts as $d): $pct = round($d['cnt']/$maxDept*100); ?>
                <div class="hb">
                    <div class="hb-row">
                        <span style="max-width:75%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= htmlspecialchars($d['name']) ?></span>
                        <strong><?= $d['cnt'] ?></strong>
                    </div>
                    <div class="hb-track"><div class="hb-fill" style="width:<?= $pct ?>%"></div></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Job Ticket summary -->
    <div class="col-md-4 col-xl-3">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-ticket-perforated me-1" style="color:var(--orange)"></i>Job Tickets</h6>
                <a href="/deno2/jobticket/index.php" class="btn btn-xs btn-outline-secondary" style="font-size:.7rem;padding:2px 8px">All Tickets</a>
            </div>
            <div class="panel-body">
                <!-- Progress ring replaced with simple bar -->
                <div class="row g-2 mb-3">
                    <?php
                    $pct = $jobs['total_qty']>0 ? round($jobs['done_qty']/$jobs['total_qty']*100) : 0;
                    $cards2 = [
                        ['label'=>'Total Tickets',  'val'=>$jobs['total'],    'color'=>'var(--blue)'],
                        ['label'=>'Pending',         'val'=>$jobs['pending'],  'color'=>'var(--orange)'],
                        ['label'=>'Completed',       'val'=>$jobs['completed'],'color'=>'var(--green)'],
                        ['label'=>'Print Qty',       'val'=>number_format($jobs['total_qty']),'color'=>'var(--purple)'],
                    ];
                    foreach($cards2 as $c): ?>
                    <div class="col-6">
                        <div style="background:#f8f9fa;border-radius:8px;padding:.5rem;text-align:center">
                            <div style="font-size:1.1rem;font-weight:800;color:<?= $c['color'] ?>"><?= $c['val'] ?></div>
                            <div style="font-size:.65rem;color:#8492a6"><?= $c['label'] ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <div style="font-size:.72rem;color:#8492a6;margin-bottom:3px">Print completion: <?= $pct ?>%</div>
                <div class="hb-track"><div class="hb-fill" style="width:<?= $pct ?>%;background:var(--green);opacity:1"></div></div>
            </div>
        </div>
    </div>

    <!-- D2M Pipeline -->
    <div class="col-md-4 col-xl-3">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-arrow-right-square me-1" style="color:var(--info)"></i>D2M Pipeline</h6>
                <a href="/deno2/d2m/index.php" class="btn btn-xs btn-outline-secondary" style="font-size:.7rem;padding:2px 8px">Open D2M</a>
            </div>
            <div class="panel-body">
                <?php
                $stages = [
                    ['label'=>'Draft',    'val'=>$d2mStats['draft'],    'color'=>'#b2bec3'],
                    ['label'=>'Checked',  'val'=>$d2mStats['checked'],  'color'=>'#fdcb6e'],
                    ['label'=>'Verified', 'val'=>$d2mStats['verified'], 'color'=>'#0984e3'],
                    ['label'=>'Closed',   'val'=>$d2mStats['closed'],   'color'=>'#1a9e5f'],
                ];
                foreach($stages as $s): $p = $d2mStats['total']>0?round($s['val']/$d2mStats['total']*100):0; ?>
                <div class="mb-2">
                    <div class="d-flex justify-content-between" style="font-size:.74rem">
                        <span><?= $s['label'] ?></span>
                        <strong><?= $s['val'] ?></strong>
                    </div>
                    <div class="hb-track"><div class="hb-fill" style="width:<?= $p ?>%;background:<?= $s['color'] ?>;opacity:1"></div></div>
                </div>
                <?php endforeach; ?>
                <div class="mt-2 text-center" style="font-size:.72rem;color:#8492a6">Total: <?= $d2mStats['total'] ?> records</div>
            </div>
        </div>
    </div>

    <!-- Recent Deno Entries -->
    <div class="col-md-8 col-xl-6">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-table me-1" style="color:var(--blue)"></i>Recent Production Entries</h6>
                <a href="/deno2/entries/deno.php" class="btn btn-xs btn-outline-primary" style="font-size:.7rem;padding:2px 8px"><i class="bi bi-plus"></i> Add Deno</a>
            </div>
            <div class="panel-body p-0">
                <table class="table table-sm table-hover mb-0" style="font-size:.76rem">
                    <thead class="table-light">
                        <tr>
                            <th>Date</th><th>Book</th><th>Month</th>
                            <th class="text-end">Qty</th><th class="text-end">Open Pcs</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach($recentDeno as $r): ?>
                        <tr>
                            <td><?= htmlspecialchars($r['deno_date_eng']) ?></td>
                            <td style="max-width:150px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">
                                <?= htmlspecialchars($r['book_name'] ?? 'N/A') ?>
                            </td>
                            <td><?= htmlspecialchars($r['deno_month']) ?></td>
                            <td class="text-end fw-semibold"><?= number_format($r['total_qty']) ?></td>
                            <td class="text-end text-muted"><?= number_format($r['quantity_openpcs']) ?></td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
                <div class="px-3 py-2" style="background:#f8f9fa;font-size:.72rem;border-top:1px solid #f0f2f8">
                    <a href="/deno2/denoreports/daily.php" class="text-decoration-none">View full daily report →</a>
                </div>
            </div>
        </div>
    </div>

    <!-- Quick Reports -->
    <div class="col-md-6 col-xl-3">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-file-earmark-bar-graph me-1" style="color:var(--info)"></i>Quick Reports</h6>
                <a href="/deno2/reports.php" class="btn btn-xs btn-outline-secondary" style="font-size:.7rem;padding:2px 8px">All Reports</a>
            </div>
            <div class="panel-body">
                <?php foreach($reportLinks as $rl): ?>
                <a href="<?= $rl['url'] ?>" class="rlink">
                    <div class="rlink-icon" style="background:<?= $rl['color'] ?>22;color:<?= $rl['color'] ?>">
                        <i class="bi <?= $rl['icon'] ?>"></i>
                    </div>
                    <span class="rlink-text"><?= $rl['label'] ?></span>
                    <i class="bi bi-chevron-right ms-auto text-muted" style="font-size:.65rem"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

    <!-- Module Quick Actions -->
    <div class="col-md-6 col-xl-3">
        <div class="panel">
            <div class="panel-hdr">
                <h6><i class="bi bi-lightning-charge-fill me-1" style="color:var(--orange)"></i>Quick Actions</h6>
            </div>
            <div class="panel-body">
                <?php
                $actions = [
                    ['icon'=>'bi-plus-circle-fill',   'label'=>'Add Deno Entry',       'url'=>'/deno2/entries/deno.php',                    'color'=>'#2c3e8c','btn'=>'btn-primary'],
                    ['icon'=>'bi-ticket-perforated-fill','label'=>'New Job Ticket',     'url'=>'/deno2/jobticket/index.php',                 'color'=>'#e8a020','btn'=>'btn-warning'],
                    ['icon'=>'bi-people-fill',         'label'=>'Employee Directory',   'url'=>'/deno2/hr/employee/index.php',               'color'=>'#1a9e5f','btn'=>'btn-success'],
                    ['icon'=>'bi-calendar-check',      'label'=>'Mark Attendance',      'url'=>'/deno2/hr/modules/attendance/mark.php',      'color'=>'#0984e3','btn'=>'btn-info'],
                    ['icon'=>'bi-truck-flatbed',       'label'=>'Vehicle Daily Log',    'url'=>'/deno2/vehicle/vehicle_daily_log_v2.php',    'color'=>'#d63031','btn'=>'btn-danger'],
                    ['icon'=>'bi-fuel-pump-fill',      'label'=>'Issue Fuel Coupon',    'url'=>'/deno2/vehicle/fuel_coupons_v2.php',         'color'=>'#6c5ce7','btn'=>'btn-secondary'],
                    ['icon'=>'bi-arrow-right-square',  'label'=>'D2M Verification',     'url'=>'/deno2/d2m/index.php',                       'color'=>'#00b894','btn'=>'btn-outline-success'],
                    ['icon'=>'bi-printer-fill',        'label'=>'Forma Printing',       'url'=>'/deno2/formaprinting/index.php',             'color'=>'#636e72','btn'=>'btn-outline-secondary'],
                ];
                foreach($actions as $a): ?>
                <a href="<?= $a['url'] ?>" class="rlink">
                    <div class="rlink-icon" style="background:<?= $a['color'] ?>22;color:<?= $a['color'] ?>">
                        <i class="bi <?= $a['icon'] ?>"></i>
                    </div>
                    <span class="rlink-text"><?= $a['label'] ?></span>
                    <i class="bi bi-chevron-right ms-auto text-muted" style="font-size:.65rem"></i>
                </a>
                <?php endforeach; ?>
            </div>
        </div>
    </div>

</div><!-- /row -->

<p class="text-center text-muted mt-4 mb-3" style="font-size:.7rem">
    JEMC Production Management System &nbsp;·&nbsp; Dashboard &nbsp;·&nbsp; <?= date('d M Y H:i:s') ?>
</p>
</div>

<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
// ── Production Trend ──────────────────────────────────────────
new Chart(document.getElementById('prodChart'), {
    type: 'bar',
    data: {
        labels: <?= $trendDates ?>,
        datasets: [{
            label: 'Production Qty',
            data:  <?= $trendQty ?>,
            backgroundColor: 'rgba(44,62,140,.7)',
            borderRadius: 4,
        }]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 10 } } },
            y: { grid: { color: '#f0f2f8' }, ticks: { font: { size: 10 } } }
        }
    }
});

// ── Attendance Trend ──────────────────────────────────────────
new Chart(document.getElementById('attChart'), {
    type: 'line',
    data: {
        labels: <?= $attDates ?>,
        datasets: [
            {
                label: 'Present',
                data: <?= $attPresent ?>,
                borderColor: '#1a9e5f', backgroundColor: 'rgba(26,158,95,.12)',
                fill: true, tension: .35, pointRadius: 3,
            },
            {
                label: 'Absent',
                data: <?= $attAbsent ?>,
                borderColor: '#d63031', backgroundColor: 'rgba(214,48,49,.08)',
                fill: true, tension: .35, pointRadius: 3,
            }
        ]
    },
    options: {
        responsive: true, maintainAspectRatio: true,
        plugins: { legend: { display: false } },
        scales: {
            x: { grid: { display: false }, ticks: { font: { size: 9 } } },
            y: { grid: { color: '#f0f2f8' }, ticks: { font: { size: 9 } } }
        }
    }
});
</script>
<?php ob_end_flush(); ?>

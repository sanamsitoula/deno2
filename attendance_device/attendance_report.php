<?php
/**
 * Attendance Report — Daily & Monthly
 * Supports: HTML view | PDF (TCPDF) | Excel (CSV)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$export    = $_GET['export'] ?? '';      // '' | 'pdf' | 'excel'
$reportType= $_GET['type'] ?? 'daily';  // daily | monthly
$viewDate  = $_GET['date'] ?? date('Y-m-d');
$yearMonth = $_GET['month'] ?? '';       // e.g. 2082.03 for monthly
$deptFilter= $_GET['dept'] ?? '';
$empFilter = $_GET['emp'] ?? '';

// ── Helpers ───────────────────────────────────────────────────
$bsMonths = ['1'=>'Baisakh','2'=>'Jestha','3'=>'Ashadh','4'=>'Shrawan',
             '5'=>'Bhadra', '6'=>'Ashwin','7'=>'Kartik','8'=>'Mangsir',
             '9'=>'Poush',  '10'=>'Magh', '11'=>'Falgun','12'=>'Chaitra'];

// ── DAILY DATA ────────────────────────────────────────────────
function getDailyData($conn, $date, $dept='', $emp='') {
    $sql = "
        SELECT e.code, e.name, e.name_nep,
               dep.name AS department,
               des.name AS designation,
               ast.status_name, ast.status_code,
               a.check_in_time, a.check_out_time,
               a.ot_hours, a.data_source,
               a.remarks,
               a.attendance_date_nep,
               CASE WHEN a.check_in_time IS NOT NULL AND a.check_out_time IS NOT NULL
                    THEN ROUND(EXTRACT(EPOCH FROM (a.check_out_time::time - a.check_in_time::time))/3600.0, 2)
                    ELSE 0 END AS worked_hours
        FROM attendance a
        JOIN employee e ON a.employee_id=e.id
        LEFT JOIN department dep ON e.department_id=dep.id
        LEFT JOIN designation des ON e.designation_id=des.id
        LEFT JOIN attendance_status ast ON a.status_id=ast.id
        WHERE a.attendance_date_eng=:d AND e.deleted_date IS NULL
    ";
    $p = [':d'=>$date];
    if ($dept) { $sql .= " AND e.department_id=:dept"; $p[':dept']=$dept; }
    if ($emp)  { $sql .= " AND (e.code ILIKE :emp OR e.name ILIKE :emp)"; $p[':emp']="%$emp%"; }
    $sql .= " ORDER BY dep.name, e.code";
    $s = $conn->prepare($sql); $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── MONTHLY DATA ──────────────────────────────────────────────
function getMonthlyData($conn, $yearMonth, $dept='', $emp='') {
    $sql = "
        SELECT e.code, e.name, e.name_nep,
               dep.name AS department,
               des.name AS designation,
               COALESCE(ams.present_days,0)        AS present_days,
               COALESCE(ams.absent_days,0)         AS absent_days,
               COALESCE(ams.half_days,0)           AS half_days,
               COALESCE(ams.leave_days,0)          AS leave_days,
               COALESCE(ams.weekly_offs,0)         AS weekly_offs,
               COALESCE(ams.public_holidays,0)     AS holidays,
               COALESCE(ams.total_working_hours,0) AS total_hours,
               COALESCE(ams.total_ot_hours,0)      AS ot_hours,
               COALESCE(ams.lwp_days,0)            AS lwp_days,
               COALESCE(ams.payable_days,0)        AS payable_days,
               ams.is_locked
        FROM employee e
        LEFT JOIN department dep ON e.department_id=dep.id
        LEFT JOIN designation des ON e.designation_id=des.id
        LEFT JOIN attendance_monthly_summary ams
               ON ams.employee_id=e.id AND ams.year_month_nep=:ym
        WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL
    ";
    $p=[':ym'=>$yearMonth];
    if ($dept) { $sql .= " AND e.department_id=:dept"; $p[':dept']=$dept; }
    if ($emp)  { $sql .= " AND (e.code ILIKE :emp OR e.name ILIKE :emp)"; $p[':emp']="%$emp%"; }
    $sql .= " ORDER BY dep.name, e.code";
    $s=$conn->prepare($sql); $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Fallback monthly from raw attendance ──────────────────────
function getMonthlyFromRaw($conn, $yearMonth, $dept='') {
    // yearMonth format: 2082.03
    $sql = "
        SELECT e.code, e.name, e.name_nep,
               dep.name AS department,
               des.name AS designation,
               COUNT(*) FILTER (WHERE ast.status_code='P')  AS present_days,
               COUNT(*) FILTER (WHERE ast.status_code='A')  AS absent_days,
               COUNT(*) FILTER (WHERE ast.status_code='HD') AS half_days,
               COUNT(*) FILTER (WHERE ast.status_code='L')  AS leave_days,
               COUNT(*) FILTER (WHERE a.is_holiday=true)    AS holidays,
               COALESCE(SUM(a.ot_hours),0)                  AS ot_hours,
               ROUND(COALESCE(SUM(
                   CASE WHEN a.check_in_time IS NOT NULL AND a.check_out_time IS NOT NULL
                        THEN EXTRACT(EPOCH FROM (a.check_out_time::time - a.check_in_time::time))/3600.0
                        ELSE 0 END
               ),0),1) AS total_hours,
               COUNT(*) AS total_marked
        FROM attendance a
        JOIN employee e ON a.employee_id=e.id
        LEFT JOIN department dep ON e.department_id=dep.id
        LEFT JOIN designation des ON e.designation_id=des.id
        LEFT JOIN attendance_status ast ON a.status_id=ast.id
        WHERE a.attendance_date_nep LIKE :ym
          AND e.deleted_date IS NULL
    ";
    $p=[':ym'=>$yearMonth.'%'];
    if ($dept) { $sql .= " AND e.department_id=:dept"; $p[':dept']=$dept; }
    $sql .= " GROUP BY e.id, e.code, e.name, e.name_nep, dep.name, des.name ORDER BY dep.name, e.code";
    $s=$conn->prepare($sql); $s->execute($p);
    return $s->fetchAll(PDO::FETCH_ASSOC);
}

// ── Load data ─────────────────────────────────────────────────
if ($reportType === 'monthly') {
    if (!$yearMonth) $yearMonth = date('Y') . '.' . sprintf('%02d', (date('n')-1 ?: 12));
    $rows = getMonthlyFromRaw($conn, $yearMonth, $deptFilter);
    $title = "Monthly Attendance — $yearMonth";
} else {
    $rows = getDailyData($conn, $viewDate, $deptFilter, $empFilter);
    $title = "Daily Attendance — " . date('d F Y', strtotime($viewDate));
}

$departments = $conn->query("SELECT id,name FROM department WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$orgName     = "Janak Education Materials Center (JEMC)";
$printed     = date('d M Y H:i');

// ── EXCEL EXPORT ──────────────────────────────────────────────
if ($export === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    $fn = $reportType==='monthly' ? "attendance_monthly_$yearMonth.csv" : "attendance_daily_$viewDate.csv";
    header("Content-Disposition: attachment; filename=\"$fn\"");
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for Excel UTF-8
    fputcsv($out, [$orgName]);
    fputcsv($out, [$title]);
    fputcsv($out, ['Generated: '.$printed]);
    fputcsv($out, []);
    if ($reportType==='monthly') {
        fputcsv($out, ['Code','Name','Department','Designation','Present','Absent','Half Day','Leave','Holiday','OT Hrs','Total Hrs']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['code'],$r['name'],$r['department'],$r['designation'],
                $r['present_days'],$r['absent_days'],$r['half_days'],$r['leave_days'],
                $r['holidays'],$r['ot_hours'],$r['total_hours']]);
        }
    } else {
        fputcsv($out, ['Code','Name','Department','Designation','Status','Check In','Check Out','Worked Hrs','OT Hrs','Source','Remarks']);
        foreach ($rows as $r) {
            fputcsv($out, [$r['code'],$r['name'],$r['department'],$r['designation'],
                $r['status_name']??$r['status_code'],
                $r['check_in_time'] ? substr($r['check_in_time'],0,5):'',
                $r['check_out_time']? substr($r['check_out_time'],0,5):'',
                $r['worked_hours'],$r['ot_hours'],$r['data_source'],$r['remarks']]);
        }
    }
    fclose($out); exit;
}

// ── PDF EXPORT ────────────────────────────────────────────────
if ($export === 'pdf') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';

    class AttendancePDF extends \TCPDF {
        public string $rptTitle = '';
        public string $orgName  = '';
        public string $printed  = '';

        public function Header() {
            $this->SetFont('helvetica','B',13);
            $this->Cell(0,7,$this->orgName,0,1,'C');
            $this->SetFont('helvetica','B',10);
            $this->Cell(0,6,$this->rptTitle,0,1,'C');
            $this->SetFont('helvetica','',7);
            $this->Cell(0,5,'Generated: '.$this->printed.' | Page '.$this->getPage(),0,1,'C');
            $this->Line($this->getX(), $this->getY(), $this->getPageWidth()-$this->getRMargin(), $this->getY());
            $this->Ln(2);
        }
        public function Footer() {
            $this->SetY(-12);
            $this->SetFont('helvetica','I',7);
            $this->Cell(0,10,'JEMC Attendance System — Confidential',0,0,'C');
        }
    }

    $pdf = new AttendancePDF('L','mm','A4',true,'UTF-8');
    $pdf->orgName  = $orgName;
    $pdf->rptTitle = $title;
    $pdf->printed  = $printed;
    $pdf->SetCreator('JEMC Attendance System');
    $pdf->SetAuthor('JEMC');
    $pdf->SetTitle($title);
    $pdf->SetMargins(8,22,8);
    $pdf->setHeaderMargin(5);
    $pdf->setFooterMargin(8);
    $pdf->SetAutoPageBreak(true,12);
    $pdf->AddPage();
    $pdf->SetFont('helvetica','',8);

    // Summary box
    $present = 0; $absent = 0; $leave = 0;
    if ($reportType==='daily') {
        foreach ($rows as $r) {
            if ($r['status_code']==='P') $present++;
            elseif ($r['status_code']==='A') $absent++;
            elseif ($r['status_code']==='L') $leave++;
        }
        $pdf->SetFillColor(240,244,250);
        $pdf->SetFont('helvetica','B',8);
        $summaryW = ($pdf->getPageWidth()-16)/4;
        foreach ([['Total',$count=count($rows)],['Present',$present],['Absent',$absent],['On Leave',$leave]] as [$l,$v]) {
            $pdf->Cell($summaryW,10,"$l: $v",1,0,'C',true);
        }
        $pdf->Ln(12);
        $pdf->SetFont('helvetica','',8);

        // Table header
        $cols=[['Code',18],['Name',40],['Department',35],['Designation',30],
               ['Status',18],['In',14],['Out',14],['Hrs',10],['OT',10],['Source',16],['Remarks',30]];
        $pdf->SetFillColor(44,62,140);
        $pdf->SetTextColor(255,255,255);
        $pdf->SetFont('helvetica','B',7);
        foreach ($cols as [$h,$w]) $pdf->Cell($w,7,$h,1,0,'C',true);
        $pdf->Ln();
        $pdf->SetTextColor(0,0,0);

        $statusLabels=['P'=>'Present','A'=>'Absent','HD'=>'Half Day','L'=>'Leave','H'=>'Holiday'];
        $i=0;
        foreach ($rows as $r) {
            $fill = ($i++%2===0);
            $pdf->SetFillColor($fill?248:255,$fill?250:255,$fill?252:255);
            $pdf->Cell(18,6,htmlspecialchars($r['code']),1,0,'C',$fill);
            $pdf->Cell(40,6,htmlspecialchars($r['name']),1,0,'L',$fill);
            $pdf->Cell(35,6,htmlspecialchars($r['department']??''),1,0,'L',$fill);
            $pdf->Cell(30,6,htmlspecialchars($r['designation']??''),1,0,'L',$fill);
            $pdf->Cell(18,6,$statusLabels[$r['status_code']]??($r['status_name']??'—'),1,0,'C',$fill);
            $pdf->Cell(14,6,$r['check_in_time'] ?substr($r['check_in_time'],0,5):'—',1,0,'C',$fill);
            $pdf->Cell(14,6,$r['check_out_time']?substr($r['check_out_time'],0,5):'—',1,0,'C',$fill);
            $pdf->Cell(10,6,number_format($r['worked_hours'],1),1,0,'R',$fill);
            $pdf->Cell(10,6,$r['ot_hours']>0?number_format($r['ot_hours'],1):'',1,0,'R',$fill);
            $pdf->Cell(16,6,htmlspecialchars($r['data_source']??''),1,0,'C',$fill);
            $pdf->Cell(30,6,htmlspecialchars($r['remarks']??''),1,1,'L',$fill);
        }

        // Footer totals
        $pdf->SetFont('helvetica','B',8);
        $pdf->SetFillColor(220,230,242);
        $totalW = 18+40+35+30+18+14+14;
        $totalHrs = array_sum(array_column($rows,'worked_hours'));
        $totalOT  = array_sum(array_column($rows,'ot_hours'));
        $pdf->Cell($totalW,7,'TOTAL: '.count($rows).' employees',1,0,'R',true);
        $pdf->Cell(10,7,number_format($totalHrs,1),1,0,'R',true);
        $pdf->Cell(10,7,number_format($totalOT,1),1,0,'R',true);
        $pdf->Cell(46,7,'',1,1,'C',true);

    } else {
        // Monthly
        $cols=[['Code',15],['Name',38],['Department',35],['Designation',28],
               ['Present',14],['Absent',12],['Half',10],['Leave',12],
               ['Holiday',14],['OT Hrs',13],['Total Hrs',14],['Marked',12]];
        $pdf->SetFillColor(44,62,140);
        $pdf->SetTextColor(255,255,255);
        $pdf->SetFont('helvetica','B',7);
        foreach ($cols as [$h,$w]) $pdf->Cell($w,7,$h,1,0,'C',true);
        $pdf->Ln();
        $pdf->SetTextColor(0,0,0);
        $pdf->SetFont('helvetica','',7);

        $i=0;
        foreach ($rows as $r) {
            $fill=($i++%2===0);
            $pdf->SetFillColor($fill?248:255,$fill?250:255,$fill?252:255);
            $pdf->Cell(15,6,htmlspecialchars($r['code']),1,0,'C',$fill);
            $pdf->Cell(38,6,htmlspecialchars($r['name']),1,0,'L',$fill);
            $pdf->Cell(35,6,htmlspecialchars($r['department']??''),1,0,'L',$fill);
            $pdf->Cell(28,6,htmlspecialchars($r['designation']??''),1,0,'L',$fill);
            $pdf->Cell(14,6,$r['present_days'],1,0,'C',$fill);
            $pdf->Cell(12,6,$r['absent_days'],1,0,'C',$fill);
            $pdf->Cell(10,6,$r['half_days'],1,0,'C',$fill);
            $pdf->Cell(12,6,$r['leave_days'],1,0,'C',$fill);
            $pdf->Cell(14,6,$r['holidays'],1,0,'C',$fill);
            $pdf->Cell(13,6,number_format($r['ot_hours'],1),1,0,'R',$fill);
            $pdf->Cell(14,6,number_format($r['total_hours'],1),1,0,'R',$fill);
            $pdf->Cell(12,6,$r['total_marked']??'',1,1,'C',$fill);
        }

        // Totals
        $pdf->SetFont('helvetica','B',7);
        $pdf->SetFillColor(220,230,242);
        $pdf->Cell(116,7,'TOTAL: '.count($rows).' employees',1,0,'R',true);
        $pdf->Cell(14,7,array_sum(array_column($rows,'present_days')),1,0,'C',true);
        $pdf->Cell(12,7,array_sum(array_column($rows,'absent_days')),1,0,'C',true);
        $pdf->Cell(10,7,array_sum(array_column($rows,'half_days')),1,0,'C',true);
        $pdf->Cell(12,7,array_sum(array_column($rows,'leave_days')),1,0,'C',true);
        $pdf->Cell(14,7,'',1,0,'C',true);
        $pdf->Cell(13,7,number_format(array_sum(array_column($rows,'ot_hours')),1),1,0,'R',true);
        $pdf->Cell(14,7,number_format(array_sum(array_column($rows,'total_hours')),1),1,0,'R',true);
        $pdf->Cell(12,7,'',1,1,'C',true);
    }

    // Signature row
    $pdf->Ln(8);
    $pdf->SetFont('helvetica','',8);
    $pdf->Cell(70,5,'Prepared by: ___________________',0,0,'L');
    $pdf->Cell(70,5,'Checked by: ___________________',0,0,'C');
    $pdf->Cell(70,5,'Approved by: ___________________',0,1,'R');

    $fn = $reportType==='monthly'
        ? "JEMC_Attendance_Monthly_{$yearMonth}.pdf"
        : "JEMC_Attendance_Daily_{$viewDate}.pdf";
    $pdf->Output($fn,'I');
    exit;
}

// ── HTML VIEW ─────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Available months from attendance data
$availMonths = $conn->query("
    SELECT DISTINCT LEFT(attendance_date_nep,7) AS ym
    FROM attendance WHERE attendance_date_nep IS NOT NULL
    ORDER BY ym DESC LIMIT 24
")->fetchAll(PDO::FETCH_COLUMN);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Attendance Report — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.status-P{color:#1a9e5f;font-weight:700}
.status-A{color:#d63031;font-weight:700}
.status-HD{color:#e8a020;font-weight:700}
.status-L{color:#6c5ce7}
.status-H{color:#0984e3}
.src-badge{font-size:.62rem;padding:1px 5px;border-radius:3px}
.src-ZKTECO{background:#e3f2fd;color:#0d47a1}
.src-MANUAL{background:#f3e5f5;color:#4a148c}
</style>
</head>
<body>
<div class="container-fluid px-4 py-3" style="max-width:1400px">

<div class="d-flex justify-content-between align-items-center mb-3">
    <div>
        <h4 class="fw-bold mb-0" style="color:#2c3e8c">
            <i class="fas fa-calendar-alt me-2"></i>Attendance Report
        </h4>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb mb-0" style="font-size:.78rem">
                <li class="breadcrumb-item"><a href="/deno2/index.php">Dashboard</a></li>
                <li class="breadcrumb-item"><a href="zkteco_dashboard.php">ZKTeco</a></li>
                <li class="breadcrumb-item active">Attendance Report</li>
            </ol>
        </nav>
    </div>
    <div class="d-flex gap-2">
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'pdf'])) ?>"
           target="_blank" class="btn btn-danger btn-sm">
            <i class="fas fa-file-pdf me-1"></i>Download PDF
        </a>
        <a href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>"
           class="btn btn-success btn-sm">
            <i class="fas fa-file-excel me-1"></i>Download Excel
        </a>
        <button onclick="window.print()" class="btn btn-secondary btn-sm">
            <i class="fas fa-print me-1"></i>Print
        </button>
    </div>
</div>

<!-- Filter Bar -->
<div class="bg-white rounded-3 shadow-sm p-3 mb-3">
    <form method="GET" class="row g-2 align-items-end">
        <!-- Report type toggle -->
        <div class="col-auto">
            <label class="form-label small mb-1 fw-semibold">Report Type</label>
            <div class="btn-group btn-group-sm">
                <a href="?type=daily&date=<?= $viewDate ?>&dept=<?= $deptFilter ?>"
                   class="btn <?= $reportType==='daily'?'btn-primary':'btn-outline-primary' ?>">
                   <i class="fas fa-calendar-day me-1"></i>Daily
                </a>
                <a href="?type=monthly&month=<?= $yearMonth ?>&dept=<?= $deptFilter ?>"
                   class="btn <?= $reportType==='monthly'?'btn-primary':'btn-outline-primary' ?>">
                   <i class="fas fa-calendar-month me-1"></i>Monthly
                </a>
            </div>
        </div>
        <input type="hidden" name="type" value="<?= $reportType ?>">

        <?php if ($reportType==='daily'): ?>
        <div class="col-md-2">
            <label class="form-label small mb-1">Date</label>
            <input type="date" name="date" class="form-control form-control-sm"
                   value="<?= $viewDate ?>" max="<?= date('Y-m-d') ?>">
        </div>
        <div class="col-md-3">
            <label class="form-label small mb-1">Employee</label>
            <input type="text" name="emp" class="form-control form-control-sm"
                   placeholder="Code or Name" value="<?= htmlspecialchars($empFilter) ?>">
        </div>
        <?php else: ?>
        <div class="col-md-2">
            <label class="form-label small mb-1">BS Month</label>
            <select name="month" class="form-select form-select-sm">
                <?php foreach($availMonths as $m): ?>
                <option value="<?= $m ?>" <?= $m===$yearMonth?'selected':'' ?>>
                    <?php [$y,$mo]=explode('.',$m);
                    echo $bsMonths[(int)$mo]." $y ($m)"; ?>
                </option>
                <?php endforeach; ?>
                <?php if(empty($availMonths)): ?>
                <option value="<?= $yearMonth ?: date('Y').'.'.sprintf('%02d',date('n')) ?>">
                    <?= $yearMonth ?: date('Y').'.'.sprintf('%02d',date('n')) ?>
                </option>
                <?php endif; ?>
            </select>
        </div>
        <?php endif; ?>

        <div class="col-md-3">
            <label class="form-label small mb-1">Department</label>
            <select name="dept" class="form-select form-select-sm">
                <option value="">All Departments</option>
                <?php foreach($departments as $d): ?>
                <option value="<?= $d['id'] ?>" <?= $deptFilter==$d['id']?'selected':'' ?>>
                    <?= htmlspecialchars($d['name']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-auto">
            <label class="form-label small mb-1">&nbsp;</label>
            <button type="submit" class="btn btn-primary btn-sm d-block">
                <i class="fas fa-search me-1"></i>Filter
            </button>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<?php if ($reportType==='daily'): ?>
<?php
$p=0;$a=0;$hd=0;$l=0;$z=0;
foreach($rows as $r){
    if($r['status_code']==='P')$p++;
    elseif($r['status_code']==='A')$a++;
    elseif($r['status_code']==='HD')$hd++;
    elseif($r['status_code']==='L')$l++;
    if($r['data_source']==='ZKTECO')$z++;
}
?>
<div class="row g-2 mb-3">
    <?php foreach([
        ['Total',count($rows),'secondary','users'],
        ['Present',$p,'success','user-check'],
        ['Absent',$a,'danger','user-times'],
        ['Half Day',$hd,'warning','user-clock'],
        ['On Leave',$l,'info','user-slash'],
        ['From ZKTeco',$z,'primary','fingerprint'],
    ] as [$lbl,$val,$c,$ico]): ?>
    <div class="col">
        <div class="bg-white rounded-3 shadow-sm text-center py-2 px-1">
            <div class="fw-bold fs-4 text-<?= $c ?>"><?= $val ?></div>
            <div class="text-muted" style="font-size:.68rem"><?= $lbl ?></div>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Data Table -->
<div class="bg-white rounded-3 shadow-sm">
    <div class="px-3 py-2 border-bottom d-flex justify-content-between align-items-center">
        <h6 class="mb-0 fw-bold">
            <?= $reportType==='daily'
                ? date('l, d F Y', strtotime($viewDate))
                : ($yearMonth ? ($bsMonths[(int)explode('.',$yearMonth)[1]]??'').' '.explode('.',$yearMonth)[0] : $yearMonth) ?>
            <span class="badge bg-secondary ms-1"><?= count($rows) ?> employees</span>
        </h6>
    </div>
    <div class="table-responsive">
        <table class="table table-sm table-hover mb-0" style="font-size:.78rem">
            <?php if ($reportType==='daily'): ?>
            <thead class="table-dark">
                <tr>
                    <th>#</th><th>Code</th><th>Name</th>
                    <th>Department</th><th>Designation</th>
                    <th>Status</th><th>Check In</th><th>Check Out</th>
                    <th>Hrs Worked</th><th>OT Hrs</th><th>Source</th><th>Remarks</th>
                </tr>
            </thead>
            <tbody>
            <?php $i=1; foreach($rows as $r): ?>
            <tr>
                <td class="text-muted"><?= $i++ ?></td>
                <td><code style="font-size:.72rem"><?= htmlspecialchars($r['code']) ?></code></td>
                <td>
                    <?= htmlspecialchars($r['name']) ?>
                    <?php if($r['name_nep']): ?>
                    <br><small class="text-muted"><?= htmlspecialchars($r['name_nep']) ?></small>
                    <?php endif; ?>
                </td>
                <td><small><?= htmlspecialchars($r['department']??'') ?></small></td>
                <td><small><?= htmlspecialchars($r['designation']??'') ?></small></td>
                <td>
                    <span class="status-<?= $r['status_code'] ?>">
                    <?php $sl=['P'=>'● Present','A'=>'● Absent','HD'=>'◑ Half Day','L'=>'○ Leave','H'=>'★ Holiday'];
                    echo $sl[$r['status_code']] ?? ($r['status_name']??'—'); ?>
                    </span>
                </td>
                <td class="fw-semibold text-success"><?= $r['check_in_time'] ?substr($r['check_in_time'],0,5):'—' ?></td>
                <td class="text-danger"><?= $r['check_out_time']?substr($r['check_out_time'],0,5):'—' ?></td>
                <td class="text-end"><?= $r['worked_hours']>0?number_format($r['worked_hours'],1).'h':'' ?></td>
                <td class="text-end"><?= $r['ot_hours']>0?'<span class="badge bg-info">'.number_format($r['ot_hours'],1).'h</span>':'' ?></td>
                <td><span class="src-badge src-<?= $r['data_source']??'MANUAL' ?>"><?= $r['data_source']??'MANUAL' ?></span></td>
                <td><small class="text-muted"><?= htmlspecialchars($r['remarks']??'') ?></small></td>
            </tr>
            <?php endforeach; ?>
            <!-- Totals row -->
            <tr class="table-light fw-semibold">
                <td colspan="8" class="text-end">Totals:</td>
                <td class="text-end"><?= number_format(array_sum(array_column($rows,'worked_hours')),1) ?>h</td>
                <td class="text-end"><?= number_format(array_sum(array_column($rows,'ot_hours')),1) ?>h</td>
                <td colspan="2"></td>
            </tr>
            <?php else: /* Monthly */ ?>
            <thead class="table-dark">
                <tr>
                    <th>#</th><th>Code</th><th>Name</th><th>Department</th>
                    <th class="text-center">Present</th>
                    <th class="text-center">Absent</th>
                    <th class="text-center">Half Day</th>
                    <th class="text-center">Leave</th>
                    <th class="text-center">Holiday</th>
                    <th class="text-center">OT Hrs</th>
                    <th class="text-center">Total Hrs</th>
                    <th class="text-center">Days Marked</th>
                </tr>
            </thead>
            <tbody>
            <?php $i=1; foreach($rows as $r): ?>
            <tr>
                <td class="text-muted"><?= $i++ ?></td>
                <td><code style="font-size:.72rem"><?= htmlspecialchars($r['code']) ?></code></td>
                <td><?= htmlspecialchars($r['name']) ?></td>
                <td><small><?= htmlspecialchars($r['department']??'') ?></small></td>
                <td class="text-center status-P"><?= $r['present_days'] ?></td>
                <td class="text-center status-A"><?= $r['absent_days'] ?></td>
                <td class="text-center status-HD"><?= $r['half_days'] ?></td>
                <td class="text-center status-L"><?= $r['leave_days'] ?></td>
                <td class="text-center text-info"><?= $r['holidays'] ?></td>
                <td class="text-center"><?= $r['ot_hours']>0?'<span class="badge bg-info">'.number_format($r['ot_hours'],1).'</span>':'' ?></td>
                <td class="text-center"><?= number_format($r['total_hours'],1) ?>h</td>
                <td class="text-center"><?= $r['total_marked']??'' ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="table-light fw-semibold">
                <td colspan="4" class="text-end">Totals:</td>
                <td class="text-center"><?= array_sum(array_column($rows,'present_days')) ?></td>
                <td class="text-center"><?= array_sum(array_column($rows,'absent_days')) ?></td>
                <td class="text-center"><?= array_sum(array_column($rows,'half_days')) ?></td>
                <td class="text-center"><?= array_sum(array_column($rows,'leave_days')) ?></td>
                <td class="text-center"><?= array_sum(array_column($rows,'holidays')) ?></td>
                <td class="text-center"><?= number_format(array_sum(array_column($rows,'ot_hours')),1) ?></td>
                <td class="text-center"><?= number_format(array_sum(array_column($rows,'total_hours')),1) ?>h</td>
                <td></td>
            </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>
    <?php if(empty($rows)): ?>
    <div class="text-center py-5 text-muted">
        <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
        No attendance records found for the selected period.<br>
        <small>Pull data from ZKTeco device or mark attendance manually.</small>
    </div>
    <?php endif; ?>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php /* ob_end_flush(); is not needed since we included header.php */ ?>

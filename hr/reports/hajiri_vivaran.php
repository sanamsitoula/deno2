<?php
/**
 * हाजिरी विवरण (Hajiri Vivaran)
 * Monthly Attendance Report — JEMC
 * Formats: (1) Daily Grid + (2) Summary Table + (3) PDF
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

// Default BS year follows whichever fiscal year is currently active
$active_fy_row = getActiveFiscalYear($conn);
$active_fy_bs_year = (int)($active_fy_row['fiscal_code'] ?? 2082);

// ── Nepali month names & BS calendar helper ────────────────────
$bsMonths = [
    1=>'बैशाख',2=>'जेठ',3=>'असार',4=>'साउन',
    5=>'भाद्र',6=>'असोज',7=>'कार्तिक',8=>'मंसिर',
    9=>'पुष',10=>'माघ',11=>'फाल्गुण',12=>'चैत'
];
$bsMonthsEn = [
    1=>'Baisakh',2=>'Jestha',3=>'Ashadh',4=>'Shrawan',
    5=>'Bhadra',6=>'Ashwin',7=>'Kartik',8=>'Mangsir',
    9=>'Poush',10=>'Magh',11=>'Falgun',12=>'Chaitra'
];
// Days per BS month (approximate — use DateConverter for exact)
$bsDays = [1=>31,2=>32,3=>31,4=>32,5=>31,6=>30,7=>30,8=>29,9=>30,10=>29,11=>30,12=>30];

// ── Parameters ────────────────────────────────────────────────
$selYear    = (int)($_GET['bs_year']  ?? $active_fy_bs_year);
$selMonth   = (int)($_GET['bs_month'] ?? 10);   // default Magh
$empType    = $_GET['emp_type'] ?? '';
$deptFilter = (int)($_GET['dept_id'] ?? 0);
$viewMode   = $_GET['view'] ?? 'summary';        // summary | daily | pdf
$export     = $_GET['export'] ?? '';

$monthName   = $bsMonths[$selMonth] ?? '';
$monthNameEn = $bsMonthsEn[$selMonth] ?? '';
$daysInMonth = $bsDays[$selMonth] ?? 30;

// Build year_month_nep like "2082.10" for DB queries
$yearMonthNep = sprintf('%04d.%02d', $selYear, $selMonth);

// ── Load attendance data ──────────────────────────────────────
$sql = "
    SELECT
        e.id AS emp_id, e.code, e.name, e.name_nep, e.emp_type,
        d.name  AS designation_name,
        dep.name AS dept_name,
        l.name   AS level_name,

        -- Attendance aggregates from attendance table
        COUNT(a.id)                                               AS total_marked,
        COUNT(*) FILTER (WHERE ast.status_code IN ('P'))          AS present_days,
        COUNT(*) FILTER (WHERE ast.status_code = 'SAT')          AS saturday_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('HD','HALF'))  AS half_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('SL','L'))     AS sick_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('HL'))         AS home_days,
        COUNT(*) FILTER (WHERE ast.status_code = 'DD')           AS duty_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('NH','NAT'))   AS national_holiday_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('OL','OPTL'))  AS optional_holiday_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('PH','PUB'))   AS public_holiday_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('UL','A'))     AS absent_days,
        COALESCE(SUM(a.ot_hours), 0)                              AS ot_hours,

        -- Total working days = present + saturday + half + duty + national + optional + public
        (COUNT(*) FILTER (WHERE ast.status_code IN ('P')) +
         COUNT(*) FILTER (WHERE ast.status_code = 'SAT') +
         ROUND(COUNT(*) FILTER (WHERE ast.status_code IN ('HD','HALF'))::numeric / 2, 1) +
         COUNT(*) FILTER (WHERE ast.status_code = 'DD') +
         COUNT(*) FILTER (WHERE ast.status_code IN ('NH','NAT','OL','OPTL','PH','PUB')))
                                                                  AS total_work_days,

        -- Leave balance
        lb_home.balance_leaves  AS home_leave_balance,
        lb_sick.balance_leaves  AS sick_leave_balance,
        lb_home.carried_forward AS home_carry_forward

    FROM employee e
    LEFT JOIN designation  d   ON e.designation_id  = d.id
    LEFT JOIN department   dep ON e.department_id   = dep.id
    LEFT JOIN level        l   ON e.level_id        = l.id
    LEFT JOIN attendance   a   ON a.employee_id = e.id
                               AND a.attendance_date_nep LIKE :ym
    LEFT JOIN attendance_status ast ON a.status_id = ast.id
    LEFT JOIN leave_balance lb_home ON lb_home.employee_id=e.id
                                   AND lb_home.leave_type IN ('घर बिदा','HOME')
                                   AND lb_home.fiscal_year = :fy
    LEFT JOIN leave_balance lb_sick ON lb_sick.employee_id=e.id
                                   AND lb_sick.leave_type IN ('बिरामी बिदा','SICK')
                                   AND lb_sick.fiscal_year = :fy
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL
";
$params = [':ym' => $yearMonthNep.'%', ':fy' => $selYear.'/'.($selYear%100+1)];

if ($empType)    { $sql .= " AND e.emp_type = :et";   $params[':et'] = $empType; }
if ($deptFilter) { $sql .= " AND e.department_id = :d"; $params[':d'] = $deptFilter; }

$sql .= " GROUP BY e.id, e.code, e.name, e.name_nep, e.emp_type,
          d.name, dep.name, l.id, l.name, l.display_order,
          lb_home.balance_leaves, lb_sick.balance_leaves, lb_home.carried_forward
          ORDER BY l.display_order DESC NULLS LAST, e.code";

$stmt = $conn->prepare($sql); $stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Load daily attendance for grid view ───────────────────────
$dailyAtt = [];
if ($viewMode === 'daily' || $export === 'pdf') {
    $attStmt = $conn->prepare("
        SELECT a.employee_id,
               CAST(SPLIT_PART(a.attendance_date_nep, '.', 3) AS INTEGER) AS day_num,
               ast.status_code, ast.display_code,
               a.check_in_time, a.check_out_time, a.ot_hours
        FROM attendance a
        LEFT JOIN attendance_status ast ON a.status_id = ast.id
        WHERE a.attendance_date_nep LIKE :ym
        ORDER BY a.employee_id, day_num
    ");
    $attStmt->execute([':ym' => $yearMonthNep.'%']);
    foreach ($attStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $dailyAtt[$row['employee_id']][$row['day_num']] = $row;
    }
}

// Holidays for this month (shown as special columns)
$holidays = $conn->prepare("
    SELECT holiday_date_nep, holiday_name, ht.type_code
    FROM holidays h LEFT JOIN holiday_types ht ON h.holiday_type_id=ht.id
    WHERE h.holiday_date_nep LIKE :ym AND h.is_active=true
");
$holidays->execute([':ym' => $yearMonthNep.'%']);
$holidayDays = []; // day_num => type_code
foreach ($holidays->fetchAll(PDO::FETCH_ASSOC) as $h) {
    $day = (int)explode('.', $h['holiday_date_nep'])[2];
    $holidayDays[$day] = $h['type_code'];
}

// Filter dropdowns
$depts     = $conn->query("SELECT id,name FROM department WHERE status=true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$fiscalYrs = $conn->query("SELECT fiscal_code FROM fiscal_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_COLUMN);

// ── PDF EXPORT ────────────────────────────────────────────────
if ($export === 'pdf') {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';

    class HajiriPDF extends \TCPDF {
        public string $orgName  = 'जनक शिक्षा सामग्री केन्द्र लि.';
        public string $rptTitle = '';
        public string $rptSub   = '';

        public function Header() {
            $this->SetFont('dejavusans', 'B', 11);
            $this->Cell(0, 6, $this->orgName, 0, 1, 'C');
            $this->SetFont('dejavusans', 'B', 9);
            $this->Cell(0, 5, $this->rptTitle, 0, 1, 'C');
            if ($this->rptSub) {
                $this->SetFont('dejavusans', '', 8);
                $this->Cell(0, 4, $this->rptSub, 0, 1, 'C');
            }
            $margins = $this->getMargins();
            $this->Line(8, $this->getY(), $this->getPageWidth()-8, $this->getY());
            $this->Ln(2);
        }
        public function Footer() {
            $this->SetY(-10);
            $this->SetFont('dejavusans', 'I', 6);
            $this->Cell(0, 5, 'पृष्ठ '.$this->getPage().' | JEMC Payroll System', 0, 0, 'C');
        }
    }

    $pdf = new HajiriPDF('L', 'mm', 'A3', true, 'UTF-8');
    $pdf->rptTitle = 'हाजिरी विवरण — '.$monthName.' '.$selYear;
    $pdf->rptSub   = $empType ? "कर्मचारी प्रकार: $empType" : '';
    $pdf->SetCreator('JEMC'); $pdf->SetTitle('Hajiri Vivaran');
    $pdf->SetMargins(6, 22, 6);
    $pdf->setHeaderMargin(5); $pdf->setFooterMargin(8);
    $pdf->SetAutoPageBreak(true, 12);
    $pdf->AddPage();
    $pdf->SetFont('dejavusans', '', 7);

    // Column widths
    $snoW = 7; $nameW = 40; $desigW = 28; $lvlW = 8;
    $dayW = 6.5;
    $sumW = 10;

    // Header row 1 — fixed cols
    $pdf->SetFillColor(44, 62, 140); $pdf->SetTextColor(255,255,255);
    $pdf->SetFont('dejavusans', 'B', 6.5);
    $pdf->Cell($snoW,  8, 'क्र.सं.', 1, 0, 'C', true);
    $pdf->Cell($nameW, 8, 'पूर्ण नाम', 1, 0, 'C', true);
    $pdf->Cell($desigW,8, 'पद', 1, 0, 'C', true);
    $pdf->Cell($lvlW,  8, 'दर्जा', 1, 0, 'C', true);

    // Day columns
    for ($d = 1; $d <= $daysInMonth; $d++) {
        $isHol = isset($holidayDays[$d]);
        if ($isHol) $pdf->SetFillColor(255, 200, 200);
        else $pdf->SetFillColor(44, 62, 140);
        $pdf->Cell($dayW, 8, (string)$d, 1, 0, 'C', true);
        $pdf->SetFillColor(44, 62, 140);
    }

    // Summary cols
    $sumCols = [
        'उप.दिन'=>$sumW,'शनि'=>$sumW,'भैपरी'=>$sumW,'बि.बि'=>$sumW,
        'घ.बि'=>$sumW,'काज'=>$sumW,'रा.बि'=>$sumW,'वै.बि'=>$sumW,
        'सा.बि'=>$sumW,'अनु.'=>$sumW,'जम्मा'=>$sumW,'OT'=>$sumW,'कैफियत'=>18
    ];
    foreach ($sumCols as $h => $w) $pdf->Cell($w, 8, $h, 1, 0, 'C', true);
    $pdf->Ln();

    // Data rows
    $pdf->SetTextColor(0, 0, 0);
    $i = 1;
    foreach ($employees as $emp) {
        $fill = ($i % 2 === 0);
        $pdf->SetFillColor($fill ? 248 : 255, $fill ? 250 : 255, $fill ? 252 : 255);
        $pdf->SetFont('dejavusans', '', 6);

        $pdf->Cell($snoW,  6, $i++, 1, 0, 'C', $fill);
        $pdf->Cell($nameW, 6, $emp['name'], 1, 0, 'L', $fill);
        $pdf->Cell($desigW,6, $emp['designation_name'] ?? '', 1, 0, 'L', $fill);
        $pdf->Cell($lvlW,  6, $emp['level_name'] ?? '', 1, 0, 'C', $fill);

        // Daily cells
        for ($d = 1; $d <= $daysInMonth; $d++) {
            $dayData = $dailyAtt[$emp['emp_id']][$d] ?? null;
            $code    = $dayData ? ($dayData['display_code'] ?? '') : '';
            $isHol   = isset($holidayDays[$d]);
            if ($isHol && !$code) {
                $pdf->SetFillColor(255, 230, 230);
                $code = 'र';
            }
            $pdf->Cell($dayW, 6, $code, 1, 0, 'C', ($isHol && !$dayData) || $fill);
            if ($isHol && !$dayData) $pdf->SetFillColor($fill?248:255,$fill?250:255,$fill?252:255);
        }

        // Summary cols
        $pdf->Cell($sumW, 6, $emp['present_days'], 1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['saturday_days'], 1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['half_days'],     1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['sick_days'],     1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['home_days'],     1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['duty_days'],     1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['national_holiday_days'],  1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['optional_holiday_days'],  1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['public_holiday_days'],   1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, $emp['absent_days'],   1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, number_format($emp['total_work_days'],1), 1, 0, 'C', $fill);
        $pdf->Cell($sumW, 6, number_format($emp['ot_hours'],1), 1, 0, 'C', $fill);
        $pdf->Cell(18,    6, '', 1, 1, 'C', $fill);
    }

    // Totals
    $pdf->SetFont('dejavusans', 'B', 6.5);
    $pdf->SetFillColor(220, 230, 242);
    $totalW = $snoW + $nameW + $desigW + $lvlW + ($dayW * $daysInMonth);
    $pdf->Cell($totalW, 6, 'जम्मा:', 1, 0, 'R', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'present_days')),  1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'saturday_days')), 1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'half_days')),     1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'sick_days')),     1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'home_days')),     1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'duty_days')),     1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'national_holiday_days')),  1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'optional_holiday_days')),  1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'public_holiday_days')),   1, 0, 'C', true);
    $pdf->Cell($sumW, 6, array_sum(array_column($employees,'absent_days')),   1, 0, 'C', true);
    $pdf->Cell($sumW, 6, '', 1, 0, 'C', true);
    $pdf->Cell($sumW, 6, number_format(array_sum(array_column($employees,'ot_hours')),1), 1, 0, 'C', true);
    $pdf->Cell(18,    6, '', 1, 1, 'C', true);

    // Signature row
    $pdf->Ln(6);
    $pdf->SetFont('dejavusans', '', 8);
    $pdf->Cell(100, 5, 'तयार गर्ने: ___________________', 0, 0, 'L');
    $pdf->Cell(100, 5, 'जाँच गर्ने: ___________________', 0, 0, 'C');
    $pdf->Cell(100, 5, 'स्वीकृत गर्ने: ___________________', 0, 1, 'R');
    $pdf->Ln(3);
    $pdf->SetFont('dejavusans', 'I', 7);
    $pdf->Cell(0, 5, 'मिति: '.date('Y-m-d').' | JEMC Payroll Management System', 0, 1, 'C');

    $fn = 'JEMC_Hajiri_'.$monthNameEn.'_'.$selYear.'.pdf';
    $pdf->Output($fn, 'I');
    exit;
}

// ── EXCEL/CSV EXPORT ──────────────────────────────────────────
if ($export === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Hajiri_'.$monthNameEn.'_'.$selYear.'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['जनक शिक्षा सामग्री केन्द्र लि.']);
    fputcsv($out, ['हाजिरी विवरण — '.$monthName.' '.$selYear]);
    fputcsv($out, []);
    fputcsv($out, ['क्र.सं.','कोड','पूर्ण नाम','पद','दर्जा','प्रकार','उप.दिन','शनि','भैपरी','बिरामी बिदा','घर बिदा','काज','रा.बि','वै.बि','सा.बि','अनुपस्थित','जम्मा','OT घण्टा','घ.बि. ब्यालेन्स','बि.बि. ब्यालेन्स']);
    $i=1;
    foreach ($employees as $e) {
        fputcsv($out, [
            $i++, $e['code'], $e['name'], $e['designation_name']??'', $e['level_name']??'', $e['emp_type'],
            $e['present_days'], $e['saturday_days'], $e['half_days'],
            $e['sick_days'], $e['home_days'], $e['duty_days'],
            $e['national_holiday_days'], $e['optional_holiday_days'], $e['public_holiday_days'],
            $e['absent_days'], number_format($e['total_work_days'],1), number_format($e['ot_hours'],1),
            $e['home_leave_balance']??'', $e['sick_leave_balance']??''
        ]);
    }
    fclose($out); exit;
}
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>हाजिरी विवरण — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8;font-family:'Segoe UI',sans-serif}
.hajiri-table{font-size:.72rem;border-collapse:collapse;width:100%}
.hajiri-table th,.hajiri-table td{border:1px solid #c8d0d8;padding:3px 4px;text-align:center;white-space:nowrap}
.hajiri-table thead th{background:#2c3e8c;color:#fff;font-weight:700}
.hajiri-table .emp-name{text-align:left;font-weight:600;min-width:140px}
.hajiri-table .day-holiday{background:#ffecec}
.hajiri-table .day-saturday{background:#e8f5e9}
.day-P{color:#1a9e5f;font-weight:700}
.day-X,.day-A,.day-UL{color:#d63031;font-weight:700}
.day-SL,.day-L{color:#e8a020}
.day-HL{color:#2196F3;font-weight:700}
.day-SAT{color:#4CAF50}
.day-DD{color:#00BCD4}
.day-HD,.day-HALF{color:#9C27B0}
.day-NH,.day-NAT,.day-PH,.day-PUB,.day-OL,.day-OPTL{color:#F44336}
.summary-col{background:#f8f9fc;font-weight:600}
.total-row{background:#dce4f0;font-weight:700}
.legend-dot{display:inline-block;width:10px;height:10px;border-radius:50%;margin-right:4px}
@media print{body{background:#fff}.no-print{display:none!important}}
</style>
</head>
<body>
<div class="container-fluid px-3 py-3" style="max-width:1800px">

<!-- Page Header -->
<div class="text-center mb-2 no-print">
    <h4 class="fw-bold" style="color:#2c3e8c">
        <i class="fas fa-calendar-check me-2"></i>हाजिरी विवरण (Hajiri Vivaran)
    </h4>
    <p class="text-muted mb-0">जनक शिक्षा सामग्री केन्द्र लि.</p>
</div>

<!-- Filter Bar -->
<div class="bg-white rounded-3 shadow-sm p-3 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <input type="hidden" name="view" value="<?= $viewMode ?>">
        <!-- BS Year/Month (using Nepali calendar picker) -->
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">BS वर्ष</label>
            <select name="bs_year" class="form-select form-select-sm" id="sel_bs_year">
                <?php for($y=2083;$y>=2079;$y--): ?>
                <option value="<?= $y ?>" <?= $y==$selYear?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">BS महिना</label>
            <select name="bs_month" class="form-select form-select-sm" id="sel_bs_month">
                <?php foreach($bsMonths as $n=>$nm): ?>
                <option value="<?= $n ?>" <?= $n==$selMonth?'selected':'' ?>>
                    <?= $nm ?> (<?= $bsMonthsEn[$n] ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <!-- Quick BS date picker shortcut -->
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">वा BS मिति छान्नुस्</label>
            <input type="text" class="form-control form-control-sm bs-date"
                   id="quickBsPicker"
                   placeholder="<?= $selYear ?>.<?= sprintf('%02d',$selMonth) ?>.01"
                   title="BS date pick गर्नुस् — year+month auto-fills above">
            <small class="text-muted" style="font-size:.65rem">मिति छान्दा माथि auto-fill हुन्छ</small>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">कर्मचारी प्रकार</label>
            <select name="emp_type" class="form-select form-select-sm">
                <option value="">सबै</option>
                <option value="PERMANENT" <?= $empType==='PERMANENT'?'selected':'' ?>>स्थायी (Permanent)</option>
                <option value="CONTRACT"  <?= $empType==='CONTRACT'?'selected':'' ?>>करार (Contract)</option>
                <option value="DAILY_WAGES" <?= $empType==='DAILY_WAGES'?'selected':'' ?>>दैनिक ज्याला</option>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">विभाग</label>
            <select name="dept_id" class="form-select form-select-sm">
                <option value="0">सबै विभाग</option>
                <?php foreach($depts as $dep): ?>
                <option value="<?= $dep['id'] ?>" <?= $deptFilter==$dep['id']?'selected':'' ?>><?= htmlspecialchars($dep['name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">दृश्य</label>
            <div class="btn-group btn-group-sm w-100">
                <a href="?bs_year=<?= $selYear ?>&bs_month=<?= $selMonth ?>&emp_type=<?= $empType ?>&dept_id=<?= $deptFilter ?>&view=summary"
                   class="btn <?= $viewMode==='summary'?'btn-primary':'btn-outline-primary' ?>">सारांश</a>
                <a href="?bs_year=<?= $selYear ?>&bs_month=<?= $selMonth ?>&emp_type=<?= $empType ?>&dept_id=<?= $deptFilter ?>&view=daily"
                   class="btn <?= $viewMode==='daily'?'btn-primary':'btn-outline-primary' ?>">दैनिक</a>
            </div>
        </div>
        <div class="col-md-2">
            <label class="form-label small fw-semibold mb-1">&nbsp;</label>
            <div class="d-flex gap-1">
                <button type="submit" class="btn btn-primary btn-sm flex-fill">
                    <i class="fas fa-search"></i>
                </button>
                <a href="?bs_year=<?= $selYear ?>&bs_month=<?= $selMonth ?>&emp_type=<?= $empType ?>&dept_id=<?= $deptFilter ?>&view=<?= $viewMode ?>&export=pdf"
                   target="_blank" class="btn btn-danger btn-sm" title="PDF">
                    <i class="fas fa-file-pdf"></i>
                </a>
                <a href="?bs_year=<?= $selYear ?>&bs_month=<?= $selMonth ?>&emp_type=<?= $empType ?>&dept_id=<?= $deptFilter ?>&view=<?= $viewMode ?>&export=excel"
                   class="btn btn-success btn-sm" title="Excel">
                    <i class="fas fa-file-excel"></i>
                </a>
                <button type="button" class="btn btn-secondary btn-sm" onclick="window.print()">
                    <i class="fas fa-print"></i>
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Report Title (print header) -->
<div class="text-center mb-2">
    <div class="fw-bold" style="font-size:1.1rem">जनक शिक्षा सामग्री केन्द्र लि.</div>
    <div class="fw-bold" style="font-size:.95rem">
        हाजिरी विवरण — <?= $monthName ?> <?= $selYear ?>
        <?= $empType ? " | $empType" : "" ?>
        <?= $deptFilter ? " | ".$depts[array_search($deptFilter,array_column($depts,'id'))]['name'] : "" ?>
    </div>
    <div class="text-muted" style="font-size:.78rem">
        महिना: <?= $monthName ?> (<?= $monthNameEn ?>) | दिन: <?= $daysInMonth ?> |
        कुल कर्मचारी: <?= count($employees) ?>
    </div>
</div>

<!-- Legend -->
<div class="d-flex gap-3 flex-wrap mb-2 no-print" style="font-size:.72rem">
    <?php $legend = [
        ['√','day-P','उपस्थित'],['X','day-X','अनुपस्थित'],
        ['घ','day-HL','घर बिदा'],['बि','day-SL','बिरामी'],
        ['शनि','day-SAT','शनिवार'],['½','day-HD','भैपरी'],
        ['का','day-DD','काज'],['रा','day-NH','राष्ट्रिय बिदा'],
        ['सा','day-PH','सार्वजनिक'],['वै','day-OL','वैकल्पिक'],
    ]; foreach($legend as [$code,$cls,$label]): ?>
    <span>
        <span class="<?= $cls ?>" style="font-weight:700;padding:1px 4px;border:1px solid #ddd;border-radius:3px"><?= $code ?></span>
        <?= $label ?>
    </span>
    <?php endforeach; ?>
    <?php if(!empty($holidayDays)): ?>
    <span><span style="background:#ffecec;padding:1px 4px;border:1px solid #fcc;border-radius:3px">रंग</span> = सार्वजनिक बिदा दिन</span>
    <?php endif; ?>
</div>

<!-- ══ DAILY GRID VIEW ═══════════════════════════════════════ -->
<?php if ($viewMode === 'daily'): ?>
<div style="overflow-x:auto">
<table class="hajiri-table">
    <thead>
        <tr>
            <th rowspan="2" style="min-width:30px">क्र.सं.</th>
            <th rowspan="2" class="emp-name" style="min-width:140px">पूर्ण नाम</th>
            <th rowspan="2" style="min-width:80px">पद</th>
            <th rowspan="2" style="min-width:25px">दर्जा</th>
            <!-- Day columns -->
            <?php for($d=1;$d<=$daysInMonth;$d++): ?>
            <th class="<?= isset($holidayDays[$d])?'day-holiday':'' ?>"><?= $d ?></th>
            <?php endfor; ?>
            <!-- Summary -->
            <th colspan="12" style="background:#1a5276;color:#fff">सारांश</th>
        </tr>
        <tr>
            <?php for($d=1;$d<=$daysInMonth;$d++): ?>
            <th class="<?= isset($holidayDays[$d])?'day-holiday':'' ?>" style="font-size:.6rem;color:#aaa;font-weight:400">
                <?= ['आ','सो','मं','बु','बि','शु','श'][ ($d+2) % 7 ] ?>
            </th>
            <?php endfor; ?>
            <th class="summary-col">उप.</th>
            <th class="summary-col">शनि</th>
            <th class="summary-col">भैपरी</th>
            <th class="summary-col">बि.बि.</th>
            <th class="summary-col">घ.बि.</th>
            <th class="summary-col">काज</th>
            <th class="summary-col">रा.बि.</th>
            <th class="summary-col">वै.बि.</th>
            <th class="summary-col">सा.बि.</th>
            <th class="summary-col">अनु.</th>
            <th class="summary-col">जम्मा</th>
            <th class="summary-col">OT</th>
        </tr>
    </thead>
    <tbody>
    <?php $i=1; foreach($employees as $emp): ?>
    <tr>
        <td><?= $i++ ?></td>
        <td class="emp-name">
            <?= htmlspecialchars($emp['name']) ?>
            <?php if($emp['emp_type']!==($empType?:'')): ?>
            <small class="text-muted">(<?= $emp['emp_type'] ?>)</small>
            <?php endif; ?>
        </td>
        <td style="text-align:left;font-size:.68rem"><?= htmlspecialchars($emp['designation_name'] ?? '') ?></td>
        <td><?= $emp['level_name'] ?? '' ?></td>
        <?php for($d=1;$d<=$daysInMonth;$d++):
            $dayRow  = $dailyAtt[$emp['emp_id']][$d] ?? null;
            $isHol   = isset($holidayDays[$d]);
            $code    = $dayRow ? $dayRow['status_code'] : '';
            $display = $dayRow ? ($dayRow['display_code'] ?? $code) : ($isHol ? 'बि' : '');
        ?>
        <td class="<?= $isHol&&!$dayRow?'day-holiday':'' ?> day-<?= $code ?>">
            <?= htmlspecialchars($display) ?>
            <?php if($dayRow && $dayRow['ot_hours'] > 0): ?>
            <sup style="font-size:.55rem;color:#e8a020"><?= number_format($dayRow['ot_hours'],0) ?></sup>
            <?php endif; ?>
        </td>
        <?php endfor; ?>
        <td class="summary-col day-P"><?= $emp['present_days'] ?></td>
        <td class="summary-col day-SAT"><?= $emp['saturday_days'] ?></td>
        <td class="summary-col day-HD"><?= $emp['half_days'] ?></td>
        <td class="summary-col day-SL"><?= $emp['sick_days'] ?></td>
        <td class="summary-col day-HL"><?= $emp['home_days'] ?></td>
        <td class="summary-col day-DD"><?= $emp['duty_days'] ?></td>
        <td class="summary-col"><?= $emp['national_holiday_days'] ?></td>
        <td class="summary-col"><?= $emp['optional_holiday_days'] ?></td>
        <td class="summary-col"><?= $emp['public_holiday_days'] ?></td>
        <td class="summary-col day-X"><?= $emp['absent_days'] ?></td>
        <td class="summary-col"><?= number_format($emp['total_work_days'],1) ?></td>
        <td class="summary-col"><?= $emp['ot_hours'] > 0 ? number_format($emp['ot_hours'],1) : '' ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="4">जम्मा</td>
        <?php for($d=1;$d<=$daysInMonth;$d++): ?><td></td><?php endfor; ?>
        <td><?= array_sum(array_column($employees,'present_days')) ?></td>
        <td><?= array_sum(array_column($employees,'saturday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'half_days')) ?></td>
        <td><?= array_sum(array_column($employees,'sick_days')) ?></td>
        <td><?= array_sum(array_column($employees,'home_days')) ?></td>
        <td><?= array_sum(array_column($employees,'duty_days')) ?></td>
        <td><?= array_sum(array_column($employees,'national_holiday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'optional_holiday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'public_holiday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'absent_days')) ?></td>
        <td></td>
        <td><?= number_format(array_sum(array_column($employees,'ot_hours')),1) ?></td>
    </tr>
    </tbody>
</table>
</div>

<?php else: /* SUMMARY VIEW */ ?>
<!-- ══ SUMMARY VIEW ══════════════════════════════════════════ -->
<div style="overflow-x:auto">
<table class="hajiri-table">
    <thead>
        <tr>
            <th>क्र.सं.</th>
            <th class="emp-name">पूर्ण नाम</th>
            <th>पद</th>
            <th>दर्जा</th>
            <th>प्रकार</th>
            <th class="day-P">उपस्थित दिन</th>
            <th class="day-SAT">शनि बार</th>
            <th class="day-HD">भैपरी</th>
            <th class="day-SL">बिरामी बिदा</th>
            <th class="day-HL">घर बिदा</th>
            <th class="day-DD">काज</th>
            <th>राष्ट्रिय बिदा</th>
            <th>वैकल्पिक बिदा</th>
            <th>सार्वजनिक बिदा</th>
            <th class="day-X">अनुपस्थित</th>
            <th>जम्मा कार्य दिन</th>
            <th>OT घण्टा</th>
            <th style="background:#e3f2fd;color:#0d47a1">घ.बि. ब्यालेन्स</th>
            <th style="background:#fff3e0;color:#e65100">बि.बि. ब्यालेन्स</th>
        </tr>
    </thead>
    <tbody>
    <?php $i=1; foreach($employees as $emp): ?>
    <tr>
        <td><?= $i++ ?></td>
        <td class="emp-name"><?= htmlspecialchars($emp['name']) ?></td>
        <td style="text-align:left;font-size:.7rem"><?= htmlspecialchars($emp['designation_name'] ?? '') ?></td>
        <td><?= $emp['level_name'] ?? '' ?></td>
        <td><span class="badge bg-secondary" style="font-size:.6rem"><?= $emp['emp_type'] ?></span></td>
        <td class="day-P"><?= $emp['present_days'] ?></td>
        <td class="day-SAT"><?= $emp['saturday_days'] ?></td>
        <td class="day-HD"><?= $emp['half_days'] ?></td>
        <td class="day-SL"><?= $emp['sick_days'] ?></td>
        <td class="day-HL fw-bold"><?= $emp['home_days'] ?></td>
        <td class="day-DD"><?= $emp['duty_days'] ?></td>
        <td><?= $emp['national_holiday_days'] ?></td>
        <td><?= $emp['optional_holiday_days'] ?></td>
        <td><?= $emp['public_holiday_days'] ?></td>
        <td class="day-X"><?= $emp['absent_days'] ?></td>
        <td class="fw-bold"><?= number_format($emp['total_work_days'],1) ?></td>
        <td><?= $emp['ot_hours'] > 0 ? number_format($emp['ot_hours'],1) : '—' ?></td>
        <td style="background:#e3f2fd;color:#0d47a1;font-weight:700"><?= $emp['home_leave_balance'] ?? '—' ?></td>
        <td style="background:#fff3e0;color:#e65100;font-weight:700"><?= $emp['sick_leave_balance'] ?? '—' ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="5">जम्मा (<?= count($employees) ?> कर्मचारी)</td>
        <td><?= array_sum(array_column($employees,'present_days')) ?></td>
        <td><?= array_sum(array_column($employees,'saturday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'half_days')) ?></td>
        <td><?= array_sum(array_column($employees,'sick_days')) ?></td>
        <td><?= array_sum(array_column($employees,'home_days')) ?></td>
        <td><?= array_sum(array_column($employees,'duty_days')) ?></td>
        <td><?= array_sum(array_column($employees,'national_holiday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'optional_holiday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'public_holiday_days')) ?></td>
        <td><?= array_sum(array_column($employees,'absent_days')) ?></td>
        <td><?= number_format(array_sum(array_column($employees,'total_work_days')),1) ?></td>
        <td><?= number_format(array_sum(array_column($employees,'ot_hours')),1) ?></td>
        <td colspan="2"></td>
    </tr>
    </tbody>
</table>
</div>
<?php endif; ?>

<!-- Signature block -->
<div class="row mt-4 no-print">
    <div class="col-4 text-center">
        <div class="border-top mt-4 pt-1 mx-4" style="font-size:.8rem">तयार गर्ने</div>
    </div>
    <div class="col-4 text-center">
        <div class="border-top mt-4 pt-1 mx-4" style="font-size:.8rem">जाँच गर्ने</div>
    </div>
    <div class="col-4 text-center">
        <div class="border-top mt-4 pt-1 mx-4" style="font-size:.8rem">स्वीकृत गर्ने</div>
    </div>
</div>

<?php if(empty($employees)): ?>
<div class="text-center py-5 text-muted">
    <i class="fas fa-inbox fa-3x mb-3 d-block opacity-25"></i>
    <?= $monthName ?> <?= $selYear ?> को लागि हाजिरी विवरण उपलब्ध छैन।<br>
    <small>ZKTeco Live page बाट attendance sync गर्नुहोस् वा Mark Attendance बाट manually थप्नुहोस्।</small>
</div>
<?php endif; ?>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script>
// Sync quick BS date picker → year + month selects → auto-submit
document.addEventListener('DOMContentLoaded', function() {
    var picker = document.getElementById('quickBsPicker');
    if (!picker) return;
    picker.addEventListener('change', function() {
        var val = this.value.replace(/[-/]/g, '.');
        var parts = val.split('.');
        if (parts.length < 2) return;
        var yr = parseInt(parts[0], 10);
        var mo = parseInt(parts[1], 10);
        if (!yr || !mo) return;
        var yrSel = document.getElementById('sel_bs_year');
        var moSel = document.getElementById('sel_bs_month');
        if (yrSel) yrSel.value = yr;
        if (moSel) moSel.value = mo;
        // Auto-submit the filter form
        this.closest('form').submit();
    });
});
</script>
</body>
</html>
<?php ob_end_flush(); ?>

<?php
/**
 * तलब विवरण (Talab Vivaran) — Salary Attendance Report
 * Matches: Talab Permanent 2082-083.xlsx and Contract Talab OT 2082-083.xlsx
 * Columns: Bank Code | Name | Rank | हा.दि. | शनि.वि. | भैपरी | बिरामी | घर बिदा | काज | जम्मा दिन | OT घण्टा | कैफियत
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

$bsMonths = [1=>'बैशाख',2=>'जेठ',3=>'असार',4=>'साउन',5=>'भाद्र',6=>'असोज',7=>'कार्तिक',8=>'मंसिर',9=>'पुष',10=>'माघ',11=>'फाल्गुण',12=>'चैत'];
$bsMonthsEn = [1=>'Baisakh',2=>'Jestha',3=>'Ashadh',4=>'Shrawan',5=>'Bhadra',6=>'Ashwin',7=>'Kartik',8=>'Mangsir',9=>'Poush',10=>'Magh',11=>'Falgun',12=>'Chaitra'];

$selYear  = (int)($_GET['bs_year']  ?? 2082);
$selMonth = (int)($_GET['bs_month'] ?? 10);
$empType  = $_GET['emp_type'] ?? '';
$export   = $_GET['export'] ?? '';

$yearMonthNep = sprintf('%04d.%02d', $selYear, $selMonth);
$monthName    = $bsMonths[$selMonth] ?? '';
$monthNameEn  = $bsMonthsEn[$selMonth] ?? '';

// ── Query ─────────────────────────────────────────────────────
$sql = "
    SELECT
        e.id, e.code AS bank_code, e.name, e.card_id,
        l.name   AS level_name,   l.display_order,
        d.name   AS designation_name,
        dep.name AS dept_name,
        e.emp_type,

        -- Attendance breakdown (matching Excel format)
        COUNT(*) FILTER (WHERE ast.status_code = 'P')             AS ha_din,       -- हा.दि.
        COUNT(*) FILTER (WHERE ast.status_code = 'SAT')           AS sani_vi,      -- शनि.वि.
        COUNT(*) FILTER (WHERE ast.status_code IN ('HD','HALF'))   AS bhaipari,     -- भैपरी
        COUNT(*) FILTER (WHERE ast.status_code IN ('SL','L'))      AS birami,       -- बिरामी
        COUNT(*) FILTER (WHERE ast.status_code = 'HL')             AS ghar_bida,    -- घर बिदा
        COUNT(*) FILTER (WHERE ast.status_code = 'DD')             AS kaaj,         -- काज
        COUNT(*) FILTER (WHERE ast.status_code IN ('UL','A'))      AS anupasthit,   -- अनुपस्थित
        COUNT(*) FILTER (WHERE ast.status_code IN ('NH','NAT','OL','OPTL','PH','PUB')) AS bidhaa, -- बिदा दिन

        -- Total = ha_din + sani + bhair + birami + ghar + kaaj
        (COUNT(*) FILTER (WHERE ast.status_code='P') +
         COUNT(*) FILTER (WHERE ast.status_code='SAT') +
         ROUND(COUNT(*) FILTER (WHERE ast.status_code IN ('HD','HALF'))::numeric/2,1) +
         COUNT(*) FILTER (WHERE ast.status_code IN ('SL','L')) +
         COUNT(*) FILTER (WHERE ast.status_code='HL') +
         COUNT(*) FILTER (WHERE ast.status_code='DD') +
         COUNT(*) FILTER (WHERE ast.status_code IN ('NH','NAT','OL','OPTL','PH','PUB')))
                                                                   AS jamma_din,

        -- OT
        COALESCE(SUM(a.ot_hours), 0)                               AS ot_ghanta,

        -- Employee salary
        es.basic_salary,
        sg.grade_code, sg.grade_name
    FROM employee e
    LEFT JOIN level        l   ON e.level_id        = l.id
    LEFT JOIN designation  d   ON e.designation_id  = d.id
    LEFT JOIN department   dep ON e.department_id   = dep.id
    LEFT JOIN attendance   a   ON a.employee_id = e.id AND a.attendance_date_nep LIKE :ym
    LEFT JOIN attendance_status ast ON a.status_id = ast.id
    LEFT JOIN employee_salary es ON es.employee_id=e.id AND es.is_current=true
    LEFT JOIN salary_grades sg  ON es.grade_id = sg.id
    WHERE e.emp_status='ACTIVE' AND e.deleted_date IS NULL
";
$params = [':ym' => $yearMonthNep.'%'];
if ($empType) { $sql .= " AND e.emp_type=:et"; $params[':et']=$empType; }
$sql .= " GROUP BY e.id,e.code,e.name,e.card_id,l.name,l.display_order,d.name,dep.name,e.emp_type,es.basic_salary,sg.grade_code,sg.grade_name
          ORDER BY e.emp_type, l.display_order DESC NULLS LAST, e.code";

$stmt = $conn->prepare($sql); $stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Excel export ──────────────────────────────────────────────
if ($export === 'excel') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="Talab_'.$monthNameEn.'_'.$selYear.'.csv"');
    $out = fopen('php://output','w');
    fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
    fputcsv($out, ['जनक शिक्षा सामग्री केन्द्र लि.']);
    fputcsv($out, ['तलब विवरण — '.$monthName.' '.$selYear.($empType?" | $empType":'')]);
    fputcsv($out, []);
    fputcsv($out, ['क्र.सं.','बैंक/कोड','पूर्ण नाम','दर्जा','पद','प्रकार','हा.दि.','शनि.वि.','भैपरी','बिरामी','घर बिदा','काज','बिदा','अनुपस्थित','जम्मा दिन','OT घण्टा','आधारभूत तलब','ग्रेड','कैफियत']);
    $i=1;
    foreach ($rows as $r) {
        fputcsv($out, [
            $i++, $r['bank_code'], $r['name'], $r['level_name']??'', $r['designation_name']??'', $r['emp_type'],
            $r['ha_din'], $r['sani_vi'], $r['bhaipari'], $r['birami'],
            $r['ghar_bida'], $r['kaaj'], $r['bidhaa'], $r['anupasthit'],
            number_format($r['jamma_din'],1), number_format($r['ot_ghanta'],1),
            $r['basic_salary'] ? number_format($r['basic_salary'],2) : '',
            $r['grade_code']??'', ''
        ]);
    }
    // Totals
    fputcsv($out, ['','','जम्मा','','','',
        array_sum(array_column($rows,'ha_din')), array_sum(array_column($rows,'sani_vi')),
        array_sum(array_column($rows,'bhaipari')), array_sum(array_column($rows,'birami')),
        array_sum(array_column($rows,'ghar_bida')), array_sum(array_column($rows,'kaaj')),
        array_sum(array_column($rows,'bidhaa')), array_sum(array_column($rows,'anupasthit')),
        number_format(array_sum(array_column($rows,'jamma_din')),1),
        number_format(array_sum(array_column($rows,'ot_ghanta')),1), '', '', ''
    ]);
    fclose($out); exit;
}
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>तलब विवरण — JEMC</title>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
<style>
body{background:#f0f2f8}
.talab-table{font-size:.78rem;border-collapse:collapse;width:100%}
.talab-table th,.talab-table td{border:1px solid #c8d0d8;padding:3px 6px;white-space:nowrap}
.talab-table thead th{background:#1a5276;color:#fff;text-align:center;font-weight:700}
.talab-table .emp-name{text-align:left}
.talab-table .num{text-align:right;font-weight:600}
.talab-table .total-row{background:#dce4f0;font-weight:700}
.talab-table tr:nth-child(even){background:#f8fafc}
@media print{body{background:#fff}.no-print{display:none!important}}
</style>
</head>
<body>
<div class="container-fluid px-3 py-3" style="max-width:1600px">

<div class="text-center mb-2">
    <div class="fw-bold" style="font-size:1.1rem">जनक शिक्षा सामग्री केन्द्र लि.</div>
    <div class="fw-bold" style="font-size:.95rem">
        तलब विवरण — <?= $monthName ?> <?= $selYear ?>
        <?= $empType ? " | ".($empType==='PERMANENT'?'स्थायी':($empType==='CONTRACT'?'करार':'')) : " | सबै" ?>
    </div>
</div>

<!-- Filter -->
<div class="bg-white rounded-3 shadow-sm p-2 mb-3 no-print">
    <form method="GET" class="row g-2 align-items-end">
        <div class="col-auto"><label class="small fw-semibold">BS वर्ष</label>
            <select name="bs_year" class="form-select form-select-sm">
                <?php for($y=2082;$y>=2079;$y--): ?>
                <option value="<?= $y ?>" <?= $y==$selYear?'selected':'' ?>><?= $y ?></option>
                <?php endfor; ?>
            </select></div>
        <div class="col-auto"><label class="small fw-semibold">BS महिना</label>
            <select name="bs_month" class="form-select form-select-sm">
                <?php foreach($bsMonths as $n=>$nm): ?>
                <option value="<?= $n ?>" <?= $n==$selMonth?'selected':'' ?>><?= $nm ?></option>
                <?php endforeach; ?>
            </select></div>
        <div class="col-auto"><label class="small fw-semibold">प्रकार</label>
            <select name="emp_type" class="form-select form-select-sm">
                <option value="">सबै</option>
                <option value="PERMANENT" <?= $empType==='PERMANENT'?'selected':'' ?>>स्थायी</option>
                <option value="CONTRACT"  <?= $empType==='CONTRACT'?'selected':'' ?>>करार</option>
                <option value="DAILY_WAGES" <?= $empType==='DAILY_WAGES'?'selected':'' ?>>दैनिक ज्याला</option>
            </select></div>
        <div class="col-auto">
            <button type="submit" class="btn btn-primary btn-sm">खोज्नुहोस्</button>
            <a href="?bs_year=<?= $selYear ?>&bs_month=<?= $selMonth ?>&emp_type=<?= $empType ?>&export=excel"
               class="btn btn-success btn-sm ms-1"><i class="fas fa-file-excel"></i> Excel</a>
            <button onclick="window.print()" class="btn btn-secondary btn-sm ms-1"><i class="fas fa-print"></i></button>
        </div>
        <div class="col-auto ms-auto"><small class="text-muted"><?= count($rows) ?> कर्मचारी</small></div>
    </form>
</div>

<div style="overflow-x:auto">
<table class="talab-table">
    <thead>
        <tr>
            <th>क्र.सं.</th>
            <th>बैंक/कोड</th>
            <th>पूर्ण नाम</th>
            <th>दर्जा</th>
            <th>पद</th>
            <th>प्रकार</th>
            <th title="हाजिरी दिन — Regular working days">हा.दि.</th>
            <th title="शनि बार — Saturdays worked">शनि.वि.</th>
            <th title="भैपरी — Half days">भैपरी</th>
            <th title="बिरामी बिदा — Sick leave">बि.बि.</th>
            <th title="घर बिदा — Home leave">घ.बि.</th>
            <th title="काज — On duty">काज</th>
            <th title="सार्वजनिक/राष्ट्रिय बिदा">बिदा</th>
            <th title="अनुपस्थित — Absent">अनु.</th>
            <th title="जम्मा कार्य दिन">जम्मा दिन</th>
            <th title="Overtime घण्टा">OT घण्टा</th>
            <th title="आधारभूत तलब">तलब (NPR)</th>
            <th>ग्रेड</th>
            <th>कैफियत</th>
        </tr>
    </thead>
    <tbody>
    <?php
    $prevType = '';
    $i = 1;
    foreach ($rows as $r):
        // Group header by emp type
        if ($r['emp_type'] !== $prevType && !$empType):
            $prevType = $r['emp_type'];
    ?>
    <tr><td colspan="19" style="background:#e8eaf6;font-weight:700;color:#2c3e8c;padding:4px 8px">
        <?= ['PERMANENT'=>'स्थायी कर्मचारी','CONTRACT'=>'करार कर्मचारी','DAILY_WAGES'=>'दैनिक ज्याला'][$r['emp_type']] ?? $r['emp_type'] ?>
    </td></tr>
    <?php endif; ?>
    <tr>
        <td style="text-align:center"><?= $i++ ?></td>
        <td style="text-align:center"><code style="font-size:.7rem"><?= htmlspecialchars($r['bank_code']) ?></code></td>
        <td class="emp-name fw-semibold"><?= htmlspecialchars($r['name']) ?></td>
        <td style="text-align:center"><?= $r['level_name'] ?? '' ?></td>
        <td style="font-size:.7rem"><?= htmlspecialchars($r['designation_name'] ?? '') ?></td>
        <td style="text-align:center;font-size:.68rem"><span class="badge bg-secondary"><?= $r['emp_type'] ?></span></td>
        <td class="num text-success"><?= $r['ha_din'] ?></td>
        <td class="num" style="color:#4CAF50"><?= $r['sani_vi'] ?></td>
        <td class="num" style="color:#9C27B0"><?= $r['bhaipari'] ?></td>
        <td class="num" style="color:#FF9800"><?= $r['birami'] ?></td>
        <td class="num" style="color:#2196F3;font-weight:700"><?= $r['ghar_bida'] ?></td>
        <td class="num" style="color:#00BCD4"><?= $r['kaaj'] ?></td>
        <td class="num" style="color:#F44336"><?= $r['bidhaa'] ?></td>
        <td class="num text-danger"><?= $r['anupasthit'] ?: '' ?></td>
        <td class="num fw-bold"><?= number_format($r['jamma_din'],1) ?></td>
        <td class="num" style="color:#e8a020"><?= $r['ot_ghanta'] > 0 ? number_format($r['ot_ghanta'],1) : '' ?></td>
        <td class="num text-primary"><?= $r['basic_salary'] ? 'NPR '.number_format($r['basic_salary'],0) : '—' ?></td>
        <td style="text-align:center;font-size:.68rem"><?= $r['grade_code'] ?? '' ?></td>
        <td></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row">
        <td colspan="6">जम्मा (<?= count($rows) ?> कर्मचारी)</td>
        <td class="num"><?= array_sum(array_column($rows,'ha_din')) ?></td>
        <td class="num"><?= array_sum(array_column($rows,'sani_vi')) ?></td>
        <td class="num"><?= array_sum(array_column($rows,'bhaipari')) ?></td>
        <td class="num"><?= array_sum(array_column($rows,'birami')) ?></td>
        <td class="num"><?= array_sum(array_column($rows,'ghar_bida')) ?></td>
        <td class="num"><?= array_sum(array_column($rows,'kaaj')) ?></td>
        <td class="num"><?= array_sum(array_column($rows,'bidhaa')) ?></td>
        <td class="num"><?= array_sum(array_column($rows,'anupasthit')) ?></td>
        <td class="num"><?= number_format(array_sum(array_column($rows,'jamma_din')),1) ?></td>
        <td class="num"><?= number_format(array_sum(array_column($rows,'ot_ghanta')),1) ?></td>
        <td colspan="3"></td>
    </tr>
    </tbody>
</table>
</div>

<div class="row mt-4 no-print">
    <div class="col-4 text-center"><div class="border-top mt-5 mx-5" style="font-size:.8rem">तयार गर्ने</div></div>
    <div class="col-4 text-center"><div class="border-top mt-5 mx-5" style="font-size:.8rem">जाँच गर्ने</div></div>
    <div class="col-4 text-center"><div class="border-top mt-5 mx-5" style="font-size:.8rem">स्वीकृत गर्ने</div></div>
</div>

</div>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php ob_end_flush(); ?>

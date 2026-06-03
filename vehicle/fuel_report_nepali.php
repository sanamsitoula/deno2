<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

$nepali_months = [
    'Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
    'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'
];
$nep_month_dev = [
    'Baishakh'=>'वैशाख','Jestha'=>'जेठ','Ashadh'=>'असार','Shrawan'=>'साउन',
    'Bhadra'=>'भदौ','Ashwin'=>'असोज','Kartik'=>'कार्तिक','Mangsir'=>'मंसिर',
    'Poush'=>'पुष','Magh'=>'माघ','Falgun'=>'फाल्गुन','Chaitra'=>'चैत'
];
$fuel_dev = ['petrol'=>'पेट्रोल','diesel'=>'डिजेल','mobil'=>'मोबिल'];
$fuel_expense_types = [
    ''                 => '—',
    'internalfacility' => 'Internal Facility',
    'nepalpolice'      => 'Nepal Police',
    'gon'              => 'GON',
    'media'            => 'Media',
    'others'           => 'Others'
];

// Filters
$f_fiscal        = $_GET['f_fiscal']        ?? '2082/83';
$f_month         = $_GET['f_month']         ?? '';
$f_vehicle       = $_GET['f_vehicle']       ?? '';
$f_driver        = $_GET['f_driver']        ?? '';
$f_fuel_type     = $_GET['f_fuel_type']     ?? '';
$f_exp_type      = $_GET['f_exp_type']      ?? '';
$f_date_from     = $_GET['f_date_from']     ?? '';
$f_date_to       = $_GET['f_date_to']       ?? '';
$f_date_eng_from = $_GET['f_date_eng_from'] ?? '';
$f_date_eng_to   = $_GET['f_date_eng_to']   ?? '';

// Vehicles dropdown
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type FROM vehicles
    WHERE status=TRUE AND deleted_at IS NULL ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

// Drivers from vehicle_driver_assignments
$drivers = [];
try {
    $drivers = $conn->query("
        SELECT DISTINCT d.driver_id, d.driver_name
        FROM drivers d
        JOIN vehicle_driver_assignments vda ON vda.driver_id = d.driver_id
        WHERE vda.deleted_at IS NULL AND vda.active_flag = TRUE
        ORDER BY d.driver_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) { $drivers = []; }

// Build WHERE
$where  = "WHERE fcd.deleted_at IS NULL AND fc.deleted_at IS NULL AND v.deleted_at IS NULL";
$params = [];
if ($f_fiscal)       { $where .= " AND fc.fiscal_year = :ff";         $params[':ff']  = $f_fiscal; }
if ($f_month)        { $where .= " AND fc.month_nep = :fm";           $params[':fm']  = $f_month; }
if ($f_vehicle)      { $where .= " AND fc.vehicle_id = :fv";          $params[':fv']  = $f_vehicle; }
if ($f_fuel_type)    { $where .= " AND fc.fuel_type = :ft";           $params[':ft']  = $f_fuel_type; }
if ($f_exp_type)     { $where .= " AND fc.fuel_expense_type = :fe";   $params[':fe']  = $f_exp_type; }
if ($f_driver)       { $where .= " AND vda.driver_id = :fd";          $params[':fd']  = $f_driver; }
if ($f_date_eng_from){ $where .= " AND fcd.disburse_date_eng >= :dff";$params[':dff'] = $f_date_eng_from; }
if ($f_date_eng_to)  { $where .= " AND fcd.disburse_date_eng <= :dft";$params[':dft'] = $f_date_eng_to; }

$stmt = $conn->prepare("
    SELECT
        fcd.distribution_id,
        fcd.disburse_date_nep,
        fcd.disburse_date_eng,
        fcd.disburse_qty,
        fcd.rate_per_liter,
        (fcd.disburse_qty * fcd.rate_per_liter) AS total_amount,
        fcd.verified_flag,
        fcd.remarks AS dist_remarks,
        fc.coupon_id,
        fc.fiscal_year,
        fc.month_nep,
        fc.fuel_type,
        fc.fuel_expense_type,
        fc.pump_name,
        fc.allocated_qty,
        fc.carry_forward_qty,
        (fc.allocated_qty + COALESCE(fc.carry_forward_qty,0)) AS total_available,
        v.vehicle_id,
        v.vehicle_no,
        v.vehicle_type,
        COALESCE(d.driver_name, '') AS driver_name
    FROM fuel_coupon_distributions fcd
    JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
    JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda ON vda.vehicle_id = v.vehicle_id
        AND vda.active_flag = TRUE AND vda.deleted_at IS NULL
    LEFT JOIN drivers d ON d.driver_id = vda.driver_id
    $where
    ORDER BY fc.fiscal_year DESC, fc.month_nep, v.vehicle_no, fcd.disburse_date_nep
");
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Group by coupon (vehicle+month+fuel_type+coupon_id)
$by_vehicle = [];
foreach ($records as $r) {
    $k = $r['vehicle_id'].'_'.$r['fiscal_year'].'_'.$r['month_nep'].'_'.$r['fuel_type'].'_'.$r['coupon_id'];
    if (!isset($by_vehicle[$k])) {
        $by_vehicle[$k] = [
            'vehicle_no'   => $r['vehicle_no'],
            'vehicle_type' => $r['vehicle_type'],
            'driver'       => $r['driver_name'],
            'fuel_type'    => $r['fuel_type'],
            'expense_type' => $r['fuel_expense_type'] ?? '',
            'pump_name'    => $r['pump_name'],
            'month_nep'    => $r['month_nep'],
            'fiscal_year'  => $r['fiscal_year'],
            'total_alloc'  => (float)$r['total_available'],
            'total_qty'    => 0,
            'total_amt'    => 0,
            'rows'         => []
        ];
    }
    $by_vehicle[$k]['total_qty'] += (float)$r['disburse_qty'];
    $by_vehicle[$k]['total_amt'] += (float)$r['total_amount'];
    $by_vehicle[$k]['rows'][]    = $r;
}

$grand_qty = array_sum(array_column($records, 'disburse_qty'));
$grand_amt = array_sum(array_column($records, 'total_amount'));
?>
<meta charset="UTF-8">
<!-- Noto Sans Devanagari: correct Unicode Devanagari rendering (NOT legacy Kalimati/Preeti) -->
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
/* ══ FONT FIX: Force Noto Sans Devanagari everywhere ══ */
*, body, input, select, button, textarea, th, td {
    font-family: 'Noto Sans Devanagari', 'Noto Sans', Arial, sans-serif !important;
    box-sizing: border-box;
}
body { background:#f4f6f8; font-size:14px; color:#111; margin:0; }
.wrap { max-width:1540px; margin:0 auto; padding:14px; }

/* Page header */
.ph { display:flex; justify-content:space-between; align-items:center; margin-bottom:10px; }
.ph h1 { font-size:17px; font-weight:700; color:#1a1a2e; margin:0; }
.ph-btns { display:flex; gap:6px; }

/* Buttons */
.btn { padding:6px 14px; border:none; border-radius:4px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:4px; text-decoration:none; }
.btn-p { background:#1a73e8; color:#fff; }
.btn-s { background:#5f6368; color:#fff; }
.btn-g { background:#137333; color:#fff; }

/* Filter bar */
.fbar { background:#fff; border:1px solid #ddd; border-radius:5px; padding:10px 12px; margin-bottom:10px; }
.fgrid { display:grid; grid-template-columns:repeat(auto-fit,minmax(130px,1fr)); gap:7px; align-items:end; }
.fg { display:flex; flex-direction:column; gap:2px; }
.fg label { font-size:11px; font-weight:700; color:#444; }
.fg input, .fg select { padding:5px 7px; border:1px solid #ccc; border-radius:3px; font-size:13px; width:100%; }
.fg input:focus, .fg select:focus { outline:none; border-color:#1a73e8; }

/* Stats */
.sbar { display:flex; gap:8px; flex-wrap:wrap; margin-bottom:10px; }
.sc { background:#fff; border:1px solid #ddd; border-radius:5px; padding:8px 14px; min-width:100px; }
.sc .sl { font-size:10px; color:#666; }
.sc .sv { font-size:17px; font-weight:700; color:#1a1a2e; }

/* Vehicle block */
.vblock { background:#fff; border:1px solid #ccc; border-radius:5px; margin-bottom:10px; overflow:hidden; }

/* Header info table */
.vh { background:#e8f0fe; border-bottom:2px solid #1a73e8; }
.vh table { width:100%; border-collapse:collapse; font-size:12px; }
.vh td { padding:5px 9px; border-right:1px solid #c5d5f5; vertical-align:top; }
.vh td:last-child { border-right:none; }
.lbl { font-size:10px; color:#555; display:block; margin-bottom:1px; }
.val { font-weight:700; font-size:13px; color:#111; }
.vg { color:#137333; } .vr { color:#c0392b; } .vb { color:#1a73e8; }

/* Detail table */
table.dt { width:100%; border-collapse:collapse; font-size:12px; }
table.dt th { background:#f1f3f4; padding:6px 7px; text-align:center; font-weight:700; font-size:11px; border-bottom:2px solid #bbb; border-right:1px solid #ddd; white-space:nowrap; }
table.dt th:last-child { border-right:none; }
table.dt td { padding:5px 7px; border-bottom:1px solid #eee; border-right:1px solid #f0f0f0; text-align:center; vertical-align:middle; }
table.dt td:last-child { border-right:none; }
table.dt .tl { text-align:left; }
table.dt .tr { text-align:right; }
table.dt .sub td { background:#e8f5e9; font-weight:700; border-top:2px solid #999; }

/* Grand summary */
.gsect { background:#fff; border:1px solid #ccc; border-radius:5px; margin-top:12px; overflow:hidden; }
.gsect-hd { background:#1a1a2e; color:#fff; padding:7px 12px; font-weight:700; font-size:13px; }
table.gt { width:100%; border-collapse:collapse; font-size:12px; }
table.gt th { background:#2d3748; color:#fff; padding:7px 7px; font-size:11px; text-align:center; border-right:1px solid #4a5568; white-space:nowrap; }
table.gt th:last-child { border-right:none; }
table.gt td { padding:6px 7px; border-bottom:1px solid #eee; border-right:1px solid #f0f0f0; text-align:center; }
table.gt td:last-child { border-right:none; }
table.gt .gtt td { background:#fff3cd; font-weight:700; border-top:2px solid #888; }

/* Print only elements hidden on screen */
.print-only { display:none; }

/* ══ PRINT ══ */
@media print {
    @page { size:A4 landscape; margin:0.35in 0.3in 0.3in 0.3in; }
    .no-print, .fbar, .ph-btns, .sbar { display:none !important; }
    body { background:#fff; font-size:8pt; color:#000; }
    .wrap { padding:0; max-width:100%; }

    /* Letterhead */
    .print-only { display:block !important; }
    .lhead { text-align:center; border-bottom:2pt solid #000; padding-bottom:5pt; margin-bottom:7pt; }
    .lhead .org { font-size:13pt; font-weight:700; }
    .lhead .rpt { font-size:10pt; font-weight:700; margin:2pt 0; }
    .lhead .meta { font-size:8pt; color:#333; }

    .ph h1 { display:none; }

    /* Blocks */
    .vblock { border:1pt solid #888; margin-bottom:7pt; page-break-inside:avoid; border-radius:0; box-shadow:none; }
    .vh { background:#e0e8f8 !important; -webkit-print-color-adjust:exact; print-color-adjust:exact; border-bottom:1.5pt solid #333; }
    .vh td { padding:3pt 5pt; border-right:0.5pt solid #bbb; }
    .lbl { font-size:7pt; }
    .val { font-size:8.5pt; }
    table.dt { font-size:7.5pt; }
    table.dt th { background:#e0e0e0 !important; font-size:7pt; padding:2pt 3pt; -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.dt td { padding:2pt 3pt; }
    table.dt .sub td { background:#d4edda !important; }

    /* Grand summary */
    .gsect { border:1pt solid #888; margin-top:7pt; border-radius:0; }
    .gsect-hd { background:#333 !important; font-size:8.5pt; padding:3pt 5pt; }
    table.gt th { background:#444 !important; font-size:7pt; padding:2.5pt 3pt; }
    table.gt td { padding:2.5pt 3pt; font-size:7.5pt; }
    table.gt .gtt td { background:#ffeeba !important; }

    /* Remarks & signatures */
    .rmk { display:table !important; width:100%; border-collapse:collapse; margin-top:10pt; font-size:8pt; }
    .rmk td { border:0.5pt solid #888; padding:4pt 6pt; }
    .sigs { display:table !important; width:100%; border-collapse:collapse; margin-top:18pt; font-size:8pt; }
    .sigs td { text-align:center; padding:0 8pt; vertical-align:bottom; }
    .sigln { border-top:0.5pt solid #000; margin-top:26pt; padding-top:3pt; }
}
</style>

<div class="wrap">

<!-- PRINT LETTERHEAD (hidden on screen) -->
<div class="print-only">
<div class="lhead">
    <div class="org">Janak Education Materials Centre Ltd.</div>
    <div class="rpt">सवारी साधन ईन्धन वितरण विवरण — Vehicle Fuel Distribution Report</div>
    <div class="meta">
        <?php
        $m = [];
        if($f_fiscal)   $m[]='आ.व.: '.$f_fiscal;
        if($f_month)    $m[]='महिना: '.($nep_month_dev[$f_month]??$f_month).' ('.$f_month.')';
        if($f_fuel_type)$m[]='ईन्धन: '.strtoupper($f_fuel_type);
        if($f_exp_type) $m[]='खर्च: '.($fuel_expense_types[$f_exp_type]??$f_exp_type);
        echo implode('  |  ', $m);
        ?> &nbsp;&nbsp; मुद्रण मिति: <?= date('Y-m-d') ?>
    </div>
</div>
</div>

<!-- SCREEN HEADER -->
<div class="ph no-print">
    <h1>&#128203; सवारी ईन्धन विवरण — Vehicle Fuel Report</h1>
    <div class="ph-btns">
        <button class="btn btn-g" onclick="window.print()">&#128424; Print</button>
    </div>
</div>

<!-- FILTERS -->
<form method="GET" class="fbar no-print">
    <div class="fgrid">
        <div class="fg">
            <label>आ.व. / Fiscal Year</label>
            <select name="f_fiscal">
                <option value="">सबै</option>
                <?php foreach(['2082/83','2083/84','2084/85'] as $fy): ?>
                <option value="<?= $fy ?>" <?= $f_fiscal===$fy?'selected':'' ?>><?= $fy ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>महिना / Month</label>
            <select name="f_month">
                <option value="">सबै महिना</option>
                <?php foreach($nepali_months as $m): ?>
                <option value="<?= $m ?>" <?= $f_month===$m?'selected':'' ?>><?= $m ?> (<?= $nep_month_dev[$m] ?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>सवारी / Vehicle</label>
            <select name="f_vehicle">
                <option value="">सबै सवारी</option>
                <?php foreach($vehicles as $v): ?>
                <option value="<?= $v['vehicle_id'] ?>" <?= $f_vehicle==$v['vehicle_id']?'selected':'' ?>><?= htmlspecialchars($v['vehicle_no']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>चालक / Driver</label>
            <?php if(!empty($drivers)): ?>
            <select name="f_driver">
                <option value="">सबै चालक</option>
                <?php foreach($drivers as $dr): ?>
                <option value="<?= $dr['driver_id'] ?>" <?= $f_driver==$dr['driver_id']?'selected':'' ?>><?= htmlspecialchars($dr['driver_name']) ?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" name="f_driver" value="<?= htmlspecialchars($f_driver) ?>" placeholder="Driver search...">
            <?php endif; ?>
        </div>
        <div class="fg">
            <label>ईन्धन / Fuel Type</label>
            <select name="f_fuel_type">
                <option value="">सबै</option>
                <option value="petrol" <?= $f_fuel_type==='petrol'?'selected':'' ?>>Petrol (पेट्रोल)</option>
                <option value="diesel" <?= $f_fuel_type==='diesel'?'selected':'' ?>>Diesel (डिजेल)</option>
                <option value="mobil"  <?= $f_fuel_type==='mobil' ?'selected':'' ?>>Mobil (मोबिल)</option>
            </select>
        </div>
        <div class="fg">
            <label>खर्च प्रकार</label>
            <select name="f_exp_type">
                <option value="">सबै प्रकार</option>
                <?php foreach($fuel_expense_types as $k=>$l): if(!$k) continue; ?>
                <option value="<?= $k ?>" <?= $f_exp_type===$k?'selected':'' ?>><?= $l ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>मिति देखि (BS)</label>
            <input type="text" id="nd_from" name="f_date_from" value="<?= htmlspecialchars($f_date_from) ?>" placeholder="2082.01.01" autocomplete="off">
            <input type="hidden" id="nd_from_e" name="f_date_eng_from" value="<?= htmlspecialchars($f_date_eng_from) ?>">
        </div>
        <div class="fg">
            <label>मिति सम्म (BS)</label>
            <input type="text" id="nd_to" name="f_date_to" value="<?= htmlspecialchars($f_date_to) ?>" placeholder="2082.12.30" autocomplete="off">
            <input type="hidden" id="nd_to_e" name="f_date_eng_to" value="<?= htmlspecialchars($f_date_eng_to) ?>">
        </div>
        <div class="fg" style="flex-direction:row;gap:5px;">
            <button type="submit" class="btn btn-p">&#128269; खोज</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>?f_fiscal=2082/83" class="btn btn-s">&#8635;</a>
        </div>
    </div>
</form>

<!-- STATS -->
<div class="sbar no-print">
    <div class="sc"><div class="sl">Records</div><div class="sv"><?= count($records) ?></div></div>
    <div class="sc"><div class="sl">Total Qty</div><div class="sv"><?= number_format($grand_qty,2) ?> L</div></div>
    <div class="sc"><div class="sl">Total Amt</div><div class="sv" style="color:#1a73e8">रू <?= number_format($grand_amt,2) ?></div></div>
    <div class="sc"><div class="sl">Vehicles</div><div class="sv"><?= count(array_unique(array_column($records,'vehicle_id'))) ?></div></div>
    <div class="sc"><div class="sl">Groups</div><div class="sv"><?= count($by_vehicle) ?></div></div>
</div>

<?php if(empty($records)): ?>
<div style="background:#fff;padding:25px;text-align:center;color:#666;border:1px solid #ddd;border-radius:5px;">
    कुनै विवरण फेला परेन। फिल्टर परिवर्तन गर्नुहोस्। / No records found. Please adjust filters.
</div>
<?php else: ?>

<?php foreach($by_vehicle as $grp):
    $bal     = $grp['total_alloc'] - $grp['total_qty'];
    $bal_cls = $bal < 0 ? 'vr' : '';
    $exp_lbl = $grp['expense_type'] ? ($fuel_expense_types[$grp['expense_type']] ?? $grp['expense_type']) : '—';
?>
<div class="vblock">
    <div class="vh">
        <table>
            <tr>
                <td style="width:17%">
                    <span class="lbl">सवारी नं. / Vehicle No.</span>
                    <span class="val"><?= htmlspecialchars($grp['vehicle_no']) ?> <span style="font-weight:400;font-size:11px">(<?= ucfirst($grp['vehicle_type']) ?>)</span></span>
                </td>
                <td style="width:12%">
                    <span class="lbl">चालक / Driver</span>
                    <span class="val"><?= htmlspecialchars($grp['driver'] ?: '—') ?></span>
                </td>
                <td style="width:13%">
                    <span class="lbl">महिना / Month</span>
                    <span class="val"><?= $grp['month_nep'] ?> (<?= $nep_month_dev[$grp['month_nep']] ?? '' ?>)</span>
                </td>
                <td style="width:8%">
                    <span class="lbl">आ.व.</span>
                    <span class="val"><?= $grp['fiscal_year'] ?></span>
                </td>
                <td style="width:10%">
                    <span class="lbl">ईन्धन / Fuel</span>
                    <span class="val"><?= strtoupper($grp['fuel_type']) ?> (<?= $fuel_dev[$grp['fuel_type']] ?? '' ?>)</span>
                </td>
                <td style="width:10%">
                    <span class="lbl">खर्च प्रकार</span>
                    <span class="val"><?= $exp_lbl ?></span>
                </td>
                <td style="width:11%">
                    <span class="lbl">पम्प</span>
                    <span class="val" style="font-size:11px"><?= htmlspecialchars($grp['pump_name'] ?: '—') ?></span>
                </td>
                <td style="width:7%">
                    <span class="lbl">आवंटन (L)</span>
                    <span class="val"><?= number_format($grp['total_alloc'],2) ?></span>
                </td>
                <td style="width:7%">
                    <span class="lbl">वितरण (L)</span>
                    <span class="val vg"><?= number_format($grp['total_qty'],2) ?></span>
                </td>
                <td style="width:6%">
                    <span class="lbl">बाँकी (L)</span>
                    <span class="val <?= $bal_cls ?>"><?= number_format($bal,2) ?></span>
                </td>
                <td style="width:9%">
                    <span class="lbl">कुल रकम (रू)</span>
                    <span class="val vb">रू <?= number_format($grp['total_amt'],2) ?></span>
                </td>
            </tr>
        </table>
    </div>
    <table class="dt">
        <thead>
            <tr>
                <th style="width:4%">क्र.सं.</th>
                <th style="width:13%">मिति (BS)</th>
                <th style="width:11%">मिति (AD)</th>
                <th style="width:9%">परिमाण (L)</th>
                <th style="width:11%">दर/लिटर (रू)</th>
                <th style="width:13%">कुल रकम (रू)</th>
                <th style="width:9%">प्रमाणित</th>
                <th>टिप्पणी</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($grp['rows'] as $ri => $r): ?>
        <tr>
            <td><?= $ri+1 ?></td>
            <td><?= htmlspecialchars($r['disburse_date_nep']) ?></td>
            <td><?= htmlspecialchars($r['disburse_date_eng']) ?></td>
            <td><strong><?= number_format($r['disburse_qty'],2) ?></strong></td>
            <td class="tr"><?= number_format($r['rate_per_liter'],2) ?></td>
            <td class="tr"><strong>रू <?= number_format($r['total_amount'],2) ?></strong></td>
            <td style="color:<?= $r['verified_flag']?'#137333':'#c0392b' ?>">
                <?= $r['verified_flag'] ? '&#10003; भएको' : '&#8212; भएन' ?>
            </td>
            <td class="tl"><?= htmlspecialchars($r['dist_remarks'] ?: '—') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="sub">
            <td colspan="3" class="tr">जम्मा / Sub Total</td>
            <td><?= number_format($grp['total_qty'],2) ?> L</td>
            <td>—</td>
            <td class="tr">रू <?= number_format($grp['total_amt'],2) ?></td>
            <td colspan="2"></td>
        </tr>
        </tbody>
    </table>
</div>
<?php endforeach; ?>

<!-- GRAND SUMMARY -->
<div class="gsect">
    <div class="gsect-hd">&#128202; सारांश विवरण | Grand Summary</div>
    <table class="gt">
        <thead>
            <tr>
                <th>क्र.</th><th>सवारी नं.</th><th>चालक</th><th>महिना</th><th>आ.व.</th>
                <th>ईन्धन</th><th>खर्च प्रकार</th>
                <th>आवंटन (L)</th><th>वितरण (L)</th><th>बाँकी (L)</th><th>कुल रकम (रू)</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $g_qty = $g_amt = $g_alloc = 0; $sn = 1;
        foreach($by_vehicle as $grp):
            $bal = $grp['total_alloc'] - $grp['total_qty'];
            $g_qty   += $grp['total_qty'];
            $g_amt   += $grp['total_amt'];
            $g_alloc += $grp['total_alloc'];
            $xl = $grp['expense_type'] ? ($fuel_expense_types[$grp['expense_type']] ?? $grp['expense_type']) : '—';
        ?>
        <tr>
            <td><?= $sn++ ?></td>
            <td class="tl"><strong><?= htmlspecialchars($grp['vehicle_no']) ?></strong></td>
            <td class="tl"><?= htmlspecialchars($grp['driver'] ?: '—') ?></td>
            <td><?= $grp['month_nep'] ?> (<?= $nep_month_dev[$grp['month_nep']] ?? '' ?>)</td>
            <td><?= $grp['fiscal_year'] ?></td>
            <td><?= strtoupper($grp['fuel_type']) ?></td>
            <td><?= $xl ?></td>
            <td><?= number_format($grp['total_alloc'],2) ?></td>
            <td><strong><?= number_format($grp['total_qty'],2) ?></strong></td>
            <td style="color:<?= $bal<0?'#c0392b':'#137333' ?>;font-weight:600"><?= number_format($bal,2) ?></td>
            <td class="tr"><strong>रू <?= number_format($grp['total_amt'],2) ?></strong></td>
        </tr>
        <?php endforeach; ?>
        <tr class="gtt">
            <td colspan="7" class="tr">जम्मा कुल / Grand Total</td>
            <td><?= number_format($g_alloc,2) ?></td>
            <td><?= number_format($g_qty,2) ?></td>
            <td style="color:<?= ($g_alloc-$g_qty)<0?'#c0392b':'#137333' ?>"><?= number_format($g_alloc-$g_qty,2) ?></td>
            <td class="tr">रू <?= number_format($g_amt,2) ?></td>
        </tr>
        </tbody>
    </table>
</div>
<?php endif; ?>

<!-- REMARKS (print only) -->
<table class="rmk">
    <tr>
        <td style="width:15%;font-weight:700;background:#f5f5f5;vertical-align:top">टिप्पणी / Remarks</td>
        <td>&nbsp;<br>&nbsp;<br>&nbsp;</td>
    </tr>
</table>

<!-- SIGNATURES (print only) -->
<table class="sigs">
    <tr>
        <td><div class="sigln">तयार गर्ने<br>Prepared By</div></td>
        <td><div class="sigln">जाँच गर्ने<br>Checked By</div></td>
        <td><div class="sigln">लेखा अधिकृत<br>Accounts Officer</div></td>
        <td><div class="sigln">स्वीकृत गर्ने<br>Approved By</div></td>
    </tr>
</table>

</div><!-- /wrap -->

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function(){
    function ndp(nid, eid){
        var n=document.getElementById(nid), e=document.getElementById(eid);
        if(!n||!e) return;
        n.NepaliDatePicker({ dateFormat:'YYYY.MM.DD',
            onDateSelect: function(){ try{ var a=NepaliFunctions.BS2AD(n.value.trim(),'YYYY.MM.DD','YYYY.MM.DD'); if(a) e.value=a.replace(/\./g,'-'); }catch(x){} }
        });
        n.addEventListener('blur', function(){ try{ var a=NepaliFunctions.BS2AD(n.value.trim(),'YYYY.MM.DD','YYYY.MM.DD'); if(a) e.value=a.replace(/\./g,'-'); }catch(x){} });
    }
    ndp('nd_from','nd_from_e');
    ndp('nd_to',  'nd_to_e');
});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
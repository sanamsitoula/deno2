<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
redirect_if_not_logged_in();

$error_message   = null;
$success_message = null;

$nepali_months = [
    'Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
    'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'
];
$nep_month_dev = [
    'Baishakh'=>'वैशाख','Jestha'=>'जेठ','Ashadh'=>'असार','Shrawan'=>'साउन',
    'Bhadra'=>'भदौ','Ashwin'=>'असोज','Kartik'=>'कार्तिक','Mangsir'=>'मंसिर',
    'Poush'=>'पुष','Magh'=>'माघ','Falgun'=>'फाल्गुन','Chaitra'=>'चैत'
];
$month_order = array_flip($nepali_months);
$fuel_expense_types = [
    'internalfacility' => 'Internal Facility',
    'nepalpolice'      => 'Nepal Police',
    'gon'              => 'GON',
    'media'            => 'Media',
    'others'           => 'Others'
];

/* ══════════════════════════════════════════════════
   POST — Generate summary
══════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        $action = $_POST['action'] ?? '';
        if ($action === 'generate_summary') {
            if (empty($_POST['vehicle_id']) || empty($_POST['fiscal_year']) || empty($_POST['month_nep']))
                throw new Exception("Vehicle, Fiscal Year, and Month are required.");
            $stmt = $conn->prepare("SELECT calculate_monthly_vehicle_summary(:vid, :fy, :mn)");
            $stmt->execute([':vid'=>$_POST['vehicle_id'],':fy'=>$_POST['fiscal_year'],':mn'=>$_POST['month_nep']]);
            $success_message = "Summary generated for ".$_POST['month_nep']." ".$_POST['fiscal_year'];
        } elseif ($action === 'generate_all') {
            if (empty($_POST['fiscal_year']) || empty($_POST['month_nep']))
                throw new Exception("Fiscal Year and Month are required.");
            $vids = $conn->query("SELECT vehicle_id FROM vehicles WHERE status=TRUE AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
            foreach ($vids as $vid) {
                $s = $conn->prepare("SELECT calculate_monthly_vehicle_summary(:vid,:fy,:mn)");
                $s->execute([':vid'=>$vid,':fy'=>$_POST['fiscal_year'],':mn'=>$_POST['month_nep']]);
            }
            $success_message = "Generated for ".count($vids)." vehicles — ".$_POST['month_nep']." ".$_POST['fiscal_year'];
        }
        $conn->commit();
    } catch (Exception $e) { $conn->rollBack(); $error_message = $e->getMessage(); }
}

/* ══════════════════════════════════════════════════
   FILTERS
══════════════════════════════════════════════════ */
$f_fiscal    = $_GET['f_fiscal']    ?? '2082/83';
$f_month     = $_GET['f_month']     ?? '';
$f_vehicle   = $_GET['f_vehicle']   ?? '';
$f_driver    = $_GET['f_driver']    ?? '';
$f_fuel_type = $_GET['f_fuel_type'] ?? '';
$f_exp_type  = $_GET['f_exp_type']  ?? '';

/* ══════════════════════════════════════════════════
   DROPDOWN DATA
══════════════════════════════════════════════════ */
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type, fuel_type, fuel_per_liter_standard
    FROM vehicles WHERE status=TRUE AND deleted_at IS NULL ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

$drivers = [];
try {
    $drivers = $conn->query("
        SELECT DISTINCT d.driver_id, d.driver_name
        FROM drivers d
        JOIN vehicle_driver_assignments vda ON vda.driver_id = d.driver_id
        WHERE vda.deleted_at IS NULL AND vda.active_flag = TRUE
        ORDER BY d.driver_name
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (Exception $e) {}

/* ══════════════════════════════════════════════════
   MAIN DISTRIBUTION QUERY
   Groups by vehicle + distribution-month (from disburse_date_nep)
══════════════════════════════════════════════════ */
$raw_sql = "
    SELECT
        fcd.distribution_id,
        fcd.coupon_id,
        fcd.disburse_date_nep,
        fcd.disburse_date_eng,
        fcd.disburse_qty,
        fcd.rate_per_liter,
        (fcd.disburse_qty * fcd.rate_per_liter)  AS line_amount,
        fcd.verified_flag,
        fcd.remarks                               AS dist_remarks,
        fcd.fiscal_year                           AS dist_fiscal_year,
        fc.coupon_no,
        fc.month_nep                              AS coupon_month,
        fc.fiscal_year                            AS coupon_fiscal_year,
        fc.fuel_type,
        fc.fuel_expense_type,
        fc.pump_name,
        fc.allocated_qty,
        fc.carry_forward_qty,
        (fc.allocated_qty + COALESCE(fc.carry_forward_qty,0)) AS total_coupon_avail,
        v.vehicle_id,
        v.vehicle_no,
        v.vehicle_type,
        v.fuel_per_liter_standard,
        COALESCE(d.driver_name, '')               AS driver_name,
        COALESCE(d.driver_id, 0)                  AS driver_id,
        SUBSTRING(fcd.disburse_date_nep FROM 6 FOR 2)::int AS dist_month_num
    FROM fuel_coupon_distributions fcd
    JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
    JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda
           ON vda.vehicle_id = v.vehicle_id
          AND vda.active_flag = TRUE
          AND vda.deleted_at IS NULL
    LEFT JOIN drivers d ON d.driver_id = vda.driver_id
    WHERE fcd.deleted_at IS NULL
      AND fc.deleted_at  IS NULL
      AND v.deleted_at   IS NULL";

$raw_params = [];
if ($f_fiscal)    { $raw_sql .= " AND fcd.fiscal_year   = :rff"; $raw_params[':rff'] = $f_fiscal; }
if ($f_vehicle)   { $raw_sql .= " AND fc.vehicle_id     = :rfv"; $raw_params[':rfv'] = $f_vehicle; }
if ($f_fuel_type) { $raw_sql .= " AND fc.fuel_type      = :rft"; $raw_params[':rft'] = $f_fuel_type; }
if ($f_exp_type)  { $raw_sql .= " AND fc.fuel_expense_type = :rfe"; $raw_params[':rfe'] = $f_exp_type; }
if ($f_driver)    { $raw_sql .= " AND vda.driver_id     = :rfd"; $raw_params[':rfd'] = $f_driver; }
$raw_sql .= " ORDER BY v.vehicle_no, fcd.disburse_date_nep";

$raw_stmt = $conn->prepare($raw_sql);
$raw_stmt->execute($raw_params);
$raw_rows = $raw_stmt->fetchAll(PDO::FETCH_ASSOC);

$num_to_month = [
    1=>'Baishakh',2=>'Jestha',3=>'Ashadh',4=>'Shrawan',5=>'Bhadra',6=>'Ashwin',
    7=>'Kartik',8=>'Mangsir',9=>'Poush',10=>'Magh',11=>'Falgun',12=>'Chaitra'
];
foreach ($raw_rows as &$r) {
    $r['dist_month'] = $num_to_month[$r['dist_month_num']] ?? $r['coupon_month'];
}
unset($r);

if ($f_month) {
    $raw_rows = array_values(array_filter($raw_rows, fn($r) => $r['dist_month'] === $f_month));
}

/* ══════════════════════════════════════════════════
   GROUP by vehicle + dist_month
══════════════════════════════════════════════════ */
$summary = [];
foreach ($raw_rows as $r) {
    $vid   = $r['vehicle_id'];
    $month = $r['dist_month'];
    $fuel  = $r['fuel_type'];

    if (!isset($summary[$vid][$month])) {
        $summary[$vid][$month] = [
            'vehicle_id'    => $vid,
            'vehicle_no'    => $r['vehicle_no'],
            'vehicle_type'  => $r['vehicle_type'],
            'driver'        => $r['driver_name'],
            'driver_id'     => $r['driver_id'],
            'dist_month'    => $month,
            'fiscal_year'   => $r['dist_fiscal_year'] ?: $r['coupon_fiscal_year'],
            'fuel_std'      => $r['fuel_per_liter_standard'],
            'opening_meter' => null,
            'closing_meter' => null,
            'total_km'      => 0,
            'petrol_qty'    => 0, 'petrol_amt' => 0,
            'diesel_qty'    => 0, 'diesel_amt' => 0,
            'mobil_qty'     => 0, 'mobil_amt'  => 0,
            'total_qty'     => 0, 'total_amt'  => 0,
            'coupon_details'=> [],
        ];
    }

    $qty = (float)$r['disburse_qty'];
    $amt = (float)$r['line_amount'];
    $summary[$vid][$month]['total_qty'] += $qty;
    $summary[$vid][$month]['total_amt'] += $amt;
    if ($fuel==='petrol') { $summary[$vid][$month]['petrol_qty']+=$qty; $summary[$vid][$month]['petrol_amt']+=$amt; }
    if ($fuel==='diesel') { $summary[$vid][$month]['diesel_qty']+=$qty; $summary[$vid][$month]['diesel_amt']+=$amt; }
    if ($fuel==='mobil')  { $summary[$vid][$month]['mobil_qty'] +=$qty; $summary[$vid][$month]['mobil_amt'] +=$amt; }

    $cid = $r['coupon_id'];
    if (!isset($summary[$vid][$month]['coupon_details'][$cid])) {
        $carry_note = '';
        if ($r['coupon_month'] !== $month)
            $carry_note = 'Coupon issued in '.$r['coupon_month'];
        if ((float)($r['carry_forward_qty'] ?? 0) > 0)
            $carry_note .= ($carry_note?'; ':'').'Carry fwd: '.number_format($r['carry_forward_qty'],2).' L';
        $summary[$vid][$month]['coupon_details'][$cid] = [
            'coupon_no'    => $r['coupon_no'] ?: 'C-'.$cid,
            'coupon_month' => $r['coupon_month'],
            'fuel_type'    => $fuel,
            'fuel_exp'     => $r['fuel_expense_type'],
            'pump_name'    => $r['pump_name'],
            'allocated'    => (float)$r['total_coupon_avail'],
            'carry_note'   => $carry_note,
            'rows'         => [],
            'sub_qty'      => 0,
            'sub_amt'      => 0,
        ];
    }
    $summary[$vid][$month]['coupon_details'][$cid]['rows'][]     = $r;
    $summary[$vid][$month]['coupon_details'][$cid]['sub_qty']   += $qty;
    $summary[$vid][$month]['coupon_details'][$cid]['sub_amt']   += $amt;
}

/* ══════════════════════════════════════════════════
   METER READINGS from vehicle_daily_logs
   Use MIN(start_meter) as opening, MAX(end_meter) as closing
   per vehicle per month — more accurate than monthly_vehicle_summary
══════════════════════════════════════════════════ */
if (!empty($summary)) {
    $vid_in = implode(',', array_map('intval', array_keys($summary)));

    // Build month filter for the logs query
    $log_where = "WHERE vdl.deleted_at IS NULL AND vdl.vehicle_id IN ($vid_in)";
    $log_params = [];
    if ($f_fiscal) { $log_where .= " AND vdl.fiscal_year = :lffy"; $log_params[':lffy'] = $f_fiscal; }
    if ($f_month)  { $log_where .= " AND vdl.month_nep   = :lfmn"; $log_params[':lfmn'] = $f_month; }

    $log_sql = "
        SELECT
            vdl.vehicle_id,
            vdl.month_nep,
            MIN(vdl.start_meter) AS opening_meter,
            MAX(vdl.end_meter)   AS closing_meter,
            SUM(vdl.total_km)    AS total_km_logs
        FROM vehicle_daily_logs vdl
        $log_where
        GROUP BY vdl.vehicle_id, vdl.month_nep";
    $log_stmt = $conn->prepare($log_sql);
    $log_stmt->execute($log_params);
    $log_rows = $log_stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($log_rows as $lr) {
        $vid = $lr['vehicle_id'];
        $mon = $lr['month_nep'];
        if (isset($summary[$vid][$mon])) {
            $summary[$vid][$mon]['opening_meter'] = $lr['opening_meter'];
            $summary[$vid][$mon]['closing_meter']  = $lr['closing_meter'];
            $summary[$vid][$mon]['total_km']       = (int)$lr['total_km_logs'];
        }
    }

    // Also try monthly_vehicle_summary for any missing meter data
    try {
        $mvs_sql = "SELECT vehicle_id,month_nep,opening_meter,closing_meter,total_km
                    FROM monthly_vehicle_summary WHERE vehicle_id IN ($vid_in) AND deleted_at IS NULL";
        if ($f_fiscal) $mvs_sql .= " AND fiscal_year = ".$conn->quote($f_fiscal);
        $mvs_rows = $conn->query($mvs_sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($mvs_rows as $mr) {
            $vid = $mr['vehicle_id']; $mon = $mr['month_nep'];
            if (isset($summary[$vid][$mon]) && $summary[$vid][$mon]['opening_meter'] === null) {
                $summary[$vid][$mon]['opening_meter'] = $mr['opening_meter'];
                $summary[$vid][$mon]['closing_meter']  = $mr['closing_meter'];
                $summary[$vid][$mon]['total_km']       = $mr['total_km'];
            }
        }
    } catch (Exception $e) {}
}

/* ══════════════════════════════════════════════════
   MAINTENANCE RECORDS for displayed vehicles+months
══════════════════════════════════════════════════ */
$maintenance_map = []; // [vehicle_id][month] = [records]
if (!empty($summary)) {
    $vid_in = implode(',', array_map('intval', array_keys($summary)));
    $maint_sql = "
        SELECT
            vmr.maintenance_id,
            vmr.vehicle_id,
            vmr.maintenance_date_nep,
            vmr.maintenance_date_eng,
            vmr.meter_reading,
            vmr.work_description,
            vmr.parts_replaced,
            vmr.service_provider,
            vmr.labor_cost,
            vmr.parts_cost,
            vmr.total_cost,
            vmr.bill_no,
            vmr.downtime_days,
            vmr.status,
            vmr.remarks,
            mt.type_name,
            mt.is_scheduled,
            SUBSTRING(vmr.maintenance_date_nep FROM 6 FOR 2)::int AS maint_month_num
        FROM vehicle_maintenance_records vmr
        JOIN maintenance_types mt ON mt.maintenance_type_id = vmr.maintenance_type_id
        WHERE vmr.vehicle_id IN ($vid_in)
          AND vmr.deleted_at IS NULL";
    if ($f_fiscal) $maint_sql .= " AND vmr.fiscal_year = ".$conn->quote($f_fiscal);
    $maint_sql .= " ORDER BY vmr.maintenance_date_nep";

    try {
        $maint_rows = $conn->query($maint_sql)->fetchAll(PDO::FETCH_ASSOC);
        foreach ($maint_rows as $mr) {
            $mon_name = $num_to_month[$mr['maint_month_num']] ?? '';
            if ($mon_name) {
                $maintenance_map[$mr['vehicle_id']][$mon_name][] = $mr;
            }
        }
    } catch (Exception $e) {}
}

/* ══════════════════════════════════════════════════
   FLATTEN & SORT
══════════════════════════════════════════════════ */
$flat = [];
foreach ($summary as $vid => $months) {
    foreach ($months as $mon => $data) { $flat[] = $data; }
}
usort($flat, function($a,$b) use ($month_order) {
    $vc = strcmp($a['vehicle_no'], $b['vehicle_no']);
    if ($vc !== 0) return $vc;
    return ($month_order[$a['dist_month']]??99) - ($month_order[$b['dist_month']]??99);
});

/* Grand totals */
$gt_km   = array_sum(array_column($flat,'total_km'));
$gt_qty  = array_sum(array_column($flat,'total_qty'));
$gt_amt  = array_sum(array_column($flat,'total_amt'));
$gt_p_q  = array_sum(array_column($flat,'petrol_qty'));
$gt_p_a  = array_sum(array_column($flat,'petrol_amt'));
$gt_d_q  = array_sum(array_column($flat,'diesel_qty'));
$gt_d_a  = array_sum(array_column($flat,'diesel_amt'));
$gt_m_q  = array_sum(array_column($flat,'mobil_qty'));
$gt_m_a  = array_sum(array_column($flat,'mobil_amt'));

// Meter totals across all rows (sum of individual opening/closing for "total" row)
$gt_opening = 0; $gt_closing = 0;
foreach ($flat as $s) {
    if ($s['opening_meter']) $gt_opening += $s['opening_meter'];
    if ($s['closing_meter'])  $gt_closing  += $s['closing_meter'];
}
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">
<style>
*, body, input, select, button, textarea, th, td {
    font-family: 'Noto Sans Devanagari','Noto Sans',Arial,sans-serif !important;
    box-sizing: border-box;
}
body { background:#f5f7fa; font-size:14px; color:#111; }
.wrap { max-width:1900px; margin:0 auto; padding:16px; }

.ph { display:flex; justify-content:space-between; align-items:center; margin-bottom:12px; }
.ph h1 { font-size:20px; font-weight:700; margin:0; }
.ph .btns { display:flex; gap:7px; }
.btn { padding:7px 14px; border:none; border-radius:4px; font-size:13px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px; text-decoration:none; }
.btn-s { background:#28a745; color:#fff; } .btn-p { background:#007bff; color:#fff; }
.btn-sc{ background:#6c757d; color:#fff; } .btn-g { background:#137333; color:#fff; }

.alert { padding:9px 14px; border-radius:4px; margin-bottom:10px; font-size:13px; }
.a-ok  { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.a-err { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* Stats */
.sbar { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
.sc { background:#fff; border:1px solid #ddd; border-radius:5px; padding:8px 14px; min-width:110px; }
.sc .sl { font-size:11px; color:#666; }
.sc .sv { font-size:18px; font-weight:700; }

/* Filters */
.fbar { background:#fff; border:1px solid #ddd; border-radius:5px; padding:10px 14px; margin-bottom:10px; }
.fgrid { display:grid; grid-template-columns:repeat(auto-fit,minmax(140px,1fr)); gap:8px; align-items:end; }
.fg { display:flex; flex-direction:column; gap:2px; }
.fg label { font-size:11px; font-weight:700; color:#444; }
.fg input,.fg select { padding:5px 7px; border:1px solid #ccc; border-radius:3px; font-size:13px; width:100%; }

/* Main table */
.tbl-wrap { background:#fff; border-radius:5px; overflow-x:auto; box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; }
table.main { width:100%; border-collapse:collapse; font-size:12px; }
table.main th { background:#f0f0f0; padding:7px 5px; text-align:center; font-weight:700; font-size:11px; border:1px solid #bbb; white-space:nowrap; }
table.main td { padding:6px 5px; border:1px solid #ddd; text-align:center; vertical-align:middle; }
table.main .tl { text-align:left; }
table.main .tr { text-align:right; }
table.main .tot td { background:#e0e0e0 !important; font-weight:700; border-top:2px solid #888; }
th.pet { background:#fff9e6!important; } th.die { background:#e6f7ff!important; } th.mob { background:#ede6ff!important; }
td.pet { background:#fffdf0; } td.die { background:#f0f9ff; } td.mob { background:#f5f0ff; }

/* Kaifiyat column — wider for notes */
td.kaif { text-align:left; min-width:90px; font-size:11px; }
/* Driver signature column */
td.sig-col { min-width:70px; }

/* Coupon detail expandable */
.coupon-detail { display:none; }
.coupon-detail.open { display:table-row-group; }
table.cdtbl { width:100%; border-collapse:collapse; font-size:11px; }
table.cdtbl th { background:#e8e8e8; padding:4px 5px; border:1px solid #ccc; font-size:10px; text-align:center; }
table.cdtbl td { padding:3px 5px; border:1px solid #eee; text-align:center; }
table.cdtbl .cdsub td { background:#f0f8e8; font-weight:700; border-top:1px solid #bbb; }
.expand-btn { cursor:pointer; background:#e8f0fe; border:none; border-radius:3px; padding:2px 7px; font-size:11px; color:#1a73e8; font-weight:600; }

/* Maintenance section (screen) */
.maint-section { background:#fff; border-radius:5px; box-shadow:0 1px 3px rgba(0,0,0,.1); margin-bottom:20px; overflow-x:auto; }
.maint-section h3 { font-size:14px; font-weight:700; padding:10px 14px; margin:0; border-bottom:2px solid #dee2e6; background:#f8f9fa; }
table.maint { width:100%; border-collapse:collapse; font-size:12px; }
table.maint th { background:#f0f0f0; padding:6px 6px; text-align:center; font-weight:700; font-size:11px; border:1px solid #bbb; white-space:nowrap; }
table.maint td { padding:5px 6px; border:1px solid #ddd; text-align:center; vertical-align:middle; }
table.maint .tl { text-align:left; }
.maint-tot td { background:#e8e8e8!important; font-weight:700; border-top:2px solid #888; }
.st-pend { color:#856404; } .st-comp { color:#155724; } .st-ip { color:#004085; } .st-canc { color:#721c24; }

/* Modal */
.modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; align-items:center; justify-content:center; }
.modal.open { display:flex; }
.mbox { background:#fff; border-radius:7px; padding:22px; max-width:480px; width:90%; }
.mtitle { font-size:16px; font-weight:700; margin-bottom:14px; border-bottom:2px solid #eee; padding-bottom:10px; }
.fg2 { display:grid; grid-template-columns:1fr 1fr; gap:12px; margin-bottom:12px; }

/* ═══════════════════════════════
   PRINT STYLES — efficient, A3 landscape
═══════════════════════════════ */
@media print {
    @page { size:A3 landscape; margin:0.4in 0.3in; }
    .no-print,.fbar,.ph .btns,.sbar,.expand-btn,.modal { display:none !important; }
    body { background:#fff; font-size:8pt; color:#000; }
    .wrap { padding:0; max-width:100%; }

    /* Letterhead */
    .print-head { display:block !important; text-align:center; border-bottom:2pt solid #000; margin-bottom:6pt; padding-bottom:4pt; }
    .print-head .org { font-size:12pt; font-weight:700; }
    .print-head .rpt { font-size:9.5pt; font-weight:700; margin:2pt 0; }
    .print-head .meta { font-size:8pt; }
    .ph h1 { display:none; }

    /* Main table */
    .tbl-wrap { box-shadow:none; overflow:visible; margin-bottom:10pt; }
    table.main { font-size:7pt; page-break-inside:auto; }
    table.main th { background:#e0e0e0 !important; font-size:6.5pt; padding:2pt 2.5pt;
        -webkit-print-color-adjust:exact; print-color-adjust:exact; }
    table.main td { padding:2pt 2.5pt; border:0.5pt solid #999; }
    table.main .tot td { background:#c8c8c8 !important; }
    th.pet { background:#ffe !important; } th.die { background:#e0f0ff !important; } th.mob { background:#e8e0ff !important; }
    td.pet { background:#fffef0 !important; } td.die { background:#f0f8ff !important; } td.mob { background:#f5f0ff !important; }

    /* Always show coupon details in print */
    .coupon-detail { display:table-row-group !important; }
    table.cdtbl { font-size:6.5pt; }
    table.cdtbl th { background:#e8e8e8 !important; padding:1.5pt 2.5pt; font-size:6pt; }
    table.cdtbl td { padding:1.5pt 2.5pt; border:0.5pt solid #ccc; }
    table.cdtbl .cdsub td { background:#e0f0e0 !important; }

    /* Maintenance table */
    .maint-section { box-shadow:none; page-break-before:always; }
    .maint-section h3 { font-size:9pt; padding:4pt 6pt; background:#e0e0e0 !important; print-color-adjust:exact; }
    table.maint { font-size:7pt; }
    table.maint th { background:#e0e0e0 !important; font-size:6.5pt; padding:2pt 2.5pt; print-color-adjust:exact; }
    table.maint td { padding:2pt 2.5pt; border:0.5pt solid #999; }
    .maint-tot td { background:#c8c8c8 !important; }

    /* Print-only elements */
    .remarks-box { display:block !important; margin-top:10pt; }
    .sig-row      { display:block !important; margin-top:14pt; }

    .rmk-tbl { width:100%; border-collapse:collapse; font-size:8pt; }
    .rmk-tbl td { border:0.5pt solid #888; padding:4pt 6pt; vertical-align:top; }

    /* 3-column signature — no accounts officer */
    .sig-tbl { width:80%; margin:0 auto; border-collapse:collapse; font-size:8pt; }
    .sig-tbl td { text-align:center; padding:0 12pt; }
    .sigln { border-top:0.5pt solid #000; margin-top:24pt; padding-top:3pt; }
}

/* Screen-only: hide print elements */
.print-head  { display:none; }
.remarks-box { display:none; }
.sig-row      { display:none; }
</style>

<div class="wrap">

<!-- ═══ PRINT LETTERHEAD ═══ -->
<div class="print-head">
    <div class="org">Janak Education Materials Centre Ltd.</div>
    <div class="rpt">मासिक सवारी साधन ईन्धन विवरण — Monthly Vehicle Fuel Summary</div>
    <div class="meta">
        <?php $meta=[];
        if($f_fiscal)    $meta[]='आ.व.: '.$f_fiscal;
        if($f_month)     $meta[]='महिना: '.($nep_month_dev[$f_month]??$f_month);
        if($f_fuel_type) $meta[]='ईन्धन: '.strtoupper($f_fuel_type);
        if($f_exp_type)  $meta[]='खर्च: '.($fuel_expense_types[$f_exp_type]??$f_exp_type);
        echo implode('  |  ', $meta); ?>
        &nbsp;&nbsp; मुद्रण मिति: <?= date('Y-m-d') ?>
    </div>
</div>

<!-- ═══ SCREEN HEADER ═══ -->
<div class="ph">
    <h1>&#128202; Monthly Vehicle Fuel Summary</h1>
    <div class="btns no-print">
        <button class="btn btn-s" onclick="document.getElementById('genModal').classList.add('open')">&#9889; Generate</button>
        <button class="btn btn-g" onclick="window.print()">&#128424; Print</button>
    </div>
</div>

<?php if($success_message): ?><div class="alert a-ok no-print">&#10003; <?= htmlspecialchars($success_message) ?></div><?php endif; ?>
<?php if($error_message):   ?><div class="alert a-err no-print">&#10007; <?= htmlspecialchars($error_message) ?></div><?php endif; ?>

<!-- ═══ STATS ═══ -->
<div class="sbar no-print">
    <div class="sc"><div class="sl">Records</div><div class="sv"><?= count($flat) ?></div></div>
    <div class="sc"><div class="sl">Total Fuel Used</div><div class="sv"><?= number_format($gt_qty,2) ?> L</div></div>
    <div class="sc"><div class="sl">Total Fuel Cost</div><div class="sv" style="color:#007bff">रू <?= number_format($gt_amt,2) ?></div></div>
    <div class="sc"><div class="sl">Total KM</div><div class="sv"><?= number_format($gt_km) ?></div></div>
    <div class="sc"><div class="sl">Overuse</div><div class="sv" style="color:#dc3545">
        <?= count(array_filter($flat, fn($r)=>$r['total_km']>0&&$r['total_qty']>0&&($r['total_km']/$r['total_qty'])<($r['fuel_std']??11.5))) ?>
    </div></div>
</div>

<!-- ═══ FILTERS ═══ -->
<form method="GET" class="fbar no-print">
    <div class="fgrid">
        <div class="fg"><label>Fiscal Year</label>
            <select name="f_fiscal" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach(['2082/83','2083/84','2084/85'] as $fy): ?>
                <option value="<?=$fy?>" <?=$f_fiscal===$fy?'selected':''?>><?=$fy?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Month (Nepali)</label>
            <select name="f_month" onchange="this.form.submit()">
                <option value="">All Months</option>
                <?php foreach($nepali_months as $m): ?>
                <option value="<?=$m?>" <?=$f_month===$m?'selected':''?>><?=$m?> (<?=$nep_month_dev[$m]?>)</option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Vehicle</label>
            <select name="f_vehicle" onchange="this.form.submit()">
                <option value="">All Vehicles</option>
                <?php foreach($vehicles as $v): ?>
                <option value="<?=$v['vehicle_id']?>" <?=$f_vehicle==$v['vehicle_id']?'selected':''?>><?=htmlspecialchars($v['vehicle_no'])?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg"><label>Driver</label>
            <?php if(!empty($drivers)): ?>
            <select name="f_driver" onchange="this.form.submit()">
                <option value="">All Drivers</option>
                <?php foreach($drivers as $dr): ?>
                <option value="<?=$dr['driver_id']?>" <?=$f_driver==$dr['driver_id']?'selected':''?>><?=htmlspecialchars($dr['driver_name'])?></option>
                <?php endforeach; ?>
            </select>
            <?php else: ?>
            <input type="text" name="f_driver" value="<?=htmlspecialchars($f_driver)?>" placeholder="Driver name...">
            <?php endif; ?>
        </div>
        <div class="fg"><label>Fuel Type</label>
            <select name="f_fuel_type" onchange="this.form.submit()">
                <option value="">All Fuel</option>
                <option value="petrol" <?=$f_fuel_type==='petrol'?'selected':''?>>Petrol</option>
                <option value="diesel" <?=$f_fuel_type==='diesel'?'selected':''?>>Diesel</option>
                <option value="mobil"  <?=$f_fuel_type==='mobil' ?'selected':''?>>Mobil</option>
            </select>
        </div>
        <div class="fg"><label>Expense Type</label>
            <select name="f_exp_type" onchange="this.form.submit()">
                <option value="">All Types</option>
                <?php foreach($fuel_expense_types as $k=>$l): ?>
                <option value="<?=$k?>" <?=$f_exp_type===$k?'selected':''?>><?=$l?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg" style="flex-direction:row;gap:5px;">
            <button type="submit" class="btn btn-p">&#128269; Search</button>
            <a href="<?=$_SERVER['PHP_SELF']?>?f_fiscal=2082/83" class="btn btn-sc">&#8635; Reset</a>
        </div>
    </div>
</form>

<!-- ═══════════════════════════════════════════════════════
     FUEL SUMMARY TABLE
═══════════════════════════════════════════════════════ -->
<div class="tbl-wrap">
<table class="main">
    <thead>
        <tr>
            <th rowspan="2">क्र.<br>SN</th>
            <th rowspan="2">सवारी नं.<br>Vehicle No.</th>
            <th rowspan="2">चालक<br>Driver</th>
            <th rowspan="2">महिना<br>Month</th>
            <th rowspan="2">सुरु मिटर<br>Opening<br>Meter</th>
            <th rowspan="2">अन्त्य मिटर<br>Closing<br>Meter</th>
            <th rowspan="2">जम्मा कि.मि.<br>Total KM</th>
            <th colspan="2" class="pet">पेट्रोल / Petrol</th>
            <th colspan="2" class="die">डिजेल / Diesel</th>
            <th colspan="2" class="mob">मोबिल / Mobil</th>
            <th rowspan="2">जम्मा रकम<br>Total Cost (रू)</th>
            <th rowspan="2">जम्मा लिटर<br>Total (L)</th>
            <th rowspan="2">औसत<br>Avg<br>km/L</th>
            <th rowspan="2">मानक<br>Std<br>km/L</th>
            <th rowspan="2">प्रदर्शन<br>Performance</th>
            <!-- NEW: Kaifiyat + Driver Signature -->
            <th rowspan="2">कैफियत<br>Remarks</th>
            <th rowspan="2">सम्बद्धीय सवारी<br>चालकको दस्तखत<br>Driver Signature</th>
            <th rowspan="2" class="no-print">Detail</th>
        </tr>
        <tr>
            <th class="pet">परि.(L)</th><th class="pet">रकम(रू)</th>
            <th class="die">परि.(L)</th><th class="die">रकम(रू)</th>
            <th class="mob">परि.(L)</th><th class="mob">रकम(रू)</th>
        </tr>
    </thead>
    <tbody>
    <?php if(empty($flat)): ?>
        <tr><td colspan="22" style="padding:25px;text-align:center;color:#666">
            No distribution records found. Adjust filters or use ⚡ Generate.
        </td></tr>
    <?php else: ?>
    <?php foreach($flat as $i => $s):
        $avg     = ($s['total_km']>0 && $s['total_qty']>0) ? $s['total_km']/$s['total_qty'] : 0;
        $std     = (float)($s['fuel_std'] ?? 11.5);
        $perf    = $avg==0 ? '—' : ($avg>=$std ? 'On/Above Std' : 'Below Std');
        $pc      = $avg==0 ? '#888' : ($avg>=$std ? '#137333' : '#c0392b');
        $pb      = $avg==0 ? '' : ($avg>=$std ? '#d4edda' : '#f8d7da');
        $rowid   = 'cd-'.$i;
        $maint_count = count($maintenance_map[$s['vehicle_id']][$s['dist_month']] ?? []);
    ?>
    <tr style="<?= $i%2==1?'background:#fafafa':'' ?>">
        <td><?= $i+1 ?></td>
        <td class="tl"><strong><?= htmlspecialchars($s['vehicle_no']) ?></strong><br><small style="color:#888"><?= ucfirst($s['vehicle_type']) ?></small></td>
        <td class="tl" style="font-size:11px"><?= htmlspecialchars($s['driver'] ?: '—') ?></td>
        <td><?= $s['dist_month'] ?><br><small style="color:#666"><?= $nep_month_dev[$s['dist_month']]??'' ?> <?= $s['fiscal_year'] ?></small></td>
        <!-- Opening/closing from daily logs -->
        <td><?= $s['opening_meter'] ? number_format($s['opening_meter']) : '—' ?></td>
        <td><?= $s['closing_meter']  ? number_format($s['closing_meter'])  : '—' ?></td>
        <td><strong><?= $s['total_km'] ? number_format($s['total_km']) : '—' ?></strong></td>
        <td class="pet"><?= $s['petrol_qty']>0 ? number_format($s['petrol_qty'],2) : '—' ?></td>
        <td class="pet tr"><?= $s['petrol_amt']>0 ? number_format($s['petrol_amt'],2) : '—' ?></td>
        <td class="die"><?= $s['diesel_qty']>0 ? number_format($s['diesel_qty'],2) : '—' ?></td>
        <td class="die tr"><?= $s['diesel_amt']>0 ? number_format($s['diesel_amt'],2) : '—' ?></td>
        <td class="mob"><?= $s['mobil_qty']>0  ? number_format($s['mobil_qty'],2)  : '—' ?></td>
        <td class="mob tr"><?= $s['mobil_amt']>0  ? number_format($s['mobil_amt'],2)  : '—' ?></td>
        <td class="tr"><strong style="color:#007bff">रू <?= number_format($s['total_amt'],2) ?></strong></td>
        <td><?= number_format($s['total_qty'],2) ?></td>
        <td><strong style="color:<?=$pc?>"><?= $avg>0?number_format($avg,2):'—' ?></strong></td>
        <td><?= number_format($std,2) ?></td>
        <td><?php if($pb): ?><span style="padding:2px 6px;border-radius:3px;font-size:10px;font-weight:700;background:<?=$pb?>;color:<?=$pc?>"><?=$perf?></span><?php else: ?>—<?php endif; ?></td>
        <!-- कैफियत — maintenance count note + open space for remarks -->
        <td class="kaif">
            <?php if($maint_count>0): ?>
            <span style="color:#856404;font-size:10px">⚙ <?=$maint_count?> maintenance</span>
            <?php else: ?>&nbsp;<?php endif; ?>
        </td>
        <!-- Driver signature — blank line for print -->
        <td class="sig-col" style="min-height:30px;">&nbsp;</td>
        <td class="no-print">
            <button class="expand-btn" onclick="toggleDetail('<?=$rowid?>')">&#9660; Detail</button>
        </td>
    </tr>

    <!-- COUPON DETAIL (expandable screen / always print) -->
    <tr><td colspan="22" style="padding:0;border:0">
    <div class="coupon-detail" id="<?=$rowid?>">
    <?php foreach($s['coupon_details'] as $cid=>$cd):
        $fuel_label = strtoupper($cd['fuel_type']);
        $exp_label  = $fuel_expense_types[$cd['fuel_exp']??''] ?? ($cd['fuel_exp']?:'—');
    ?>
    <div style="padding:4px 14px 6px 28px;background:#f8f9fa;border-top:1px solid #ddd">
        <div style="font-size:11px;font-weight:700;color:#333;margin-bottom:2px">
            Coupon: <strong><?= htmlspecialchars($cd['coupon_no']) ?></strong>
            &nbsp;|&nbsp; Fuel: <?=$fuel_label?>
            &nbsp;|&nbsp; Expense: <?=$exp_label?>
            &nbsp;|&nbsp; Pump: <?= htmlspecialchars($cd['pump_name']?:'—') ?>
            &nbsp;|&nbsp; Allocated: <?= number_format($cd['allocated'],2) ?> L
            <?php if($cd['carry_note']): ?>&nbsp;|&nbsp; <em style="color:#666;font-size:10px"><?= htmlspecialchars($cd['carry_note']) ?></em><?php endif; ?>
        </div>
        <table class="cdtbl">
            <thead><tr>
                <th>क्र.</th><th>मिति BS</th><th>मिति AD</th>
                <th>परिमाण (L)</th><th>दर/L (रू)</th><th>रकम (रू)</th><th>प्रमाणित</th><th>टिप्पणी</th>
            </tr></thead>
            <tbody>
            <?php foreach($cd['rows'] as $ri=>$rr): ?>
            <tr>
                <td><?=$ri+1?></td>
                <td><?= htmlspecialchars($rr['disburse_date_nep']) ?></td>
                <td><?= htmlspecialchars($rr['disburse_date_eng']) ?></td>
                <td><strong><?= number_format($rr['disburse_qty'],2) ?></strong></td>
                <td><?= number_format($rr['rate_per_liter'],2) ?></td>
                <td class="tr"><strong>रू <?= number_format($rr['line_amount'],2) ?></strong></td>
                <td style="color:<?=$rr['verified_flag']?'#137333':'#c0392b'?>"><?=$rr['verified_flag']?'✓':'—'?></td>
                <td class="tl" style="font-size:10px"><?= htmlspecialchars($rr['dist_remarks']?:'—') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="cdsub">
                <td colspan="3" class="tr">उप-जम्मा</td>
                <td><?= number_format($cd['sub_qty'],2) ?> L</td>
                <td>—</td>
                <td class="tr">रू <?= number_format($cd['sub_amt'],2) ?></td>
                <td colspan="2"></td>
            </tr>
            </tbody>
        </table>
    </div>
    <?php endforeach; ?>
    </div>
    </td></tr>

    <?php endforeach; // end flat loop ?>

    <!-- ═══ GRAND TOTAL ROW (with meter totals) ═══ -->
    <tr class="tot">
        <td colspan="4" class="tr">जम्मा (Grand Total)</td>
        <td><?= $gt_opening>0 ? number_format($gt_opening) : '—' ?></td>
        <td><?= $gt_closing>0  ? number_format($gt_closing)  : '—' ?></td>
        <td><?= number_format($gt_km) ?></td>
        <td class="pet"><?= number_format($gt_p_q,2) ?></td>
        <td class="pet tr">रू <?= number_format($gt_p_a,2) ?></td>
        <td class="die"><?= number_format($gt_d_q,2) ?></td>
        <td class="die tr">रू <?= number_format($gt_d_a,2) ?></td>
        <td class="mob"><?= number_format($gt_m_q,2) ?></td>
        <td class="mob tr">रू <?= number_format($gt_m_a,2) ?></td>
        <td class="tr">रू <?= number_format($gt_amt,2) ?></td>
        <td><?= number_format($gt_qty,2) ?></td>
        <td colspan="6"></td>
    </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>

<!-- ═══════════════════════════════════════════════════════
     MAINTENANCE DETAILS TABLE
═══════════════════════════════════════════════════════ -->
<?php
// Flatten maintenance map to all records matching current filter
$all_maint = [];
foreach ($maintenance_map as $vid => $months) {
    foreach ($months as $mon => $recs) {
        // Only include months that appear in current flat display
        $in_flat = false;
        foreach ($flat as $fs) {
            if ($fs['vehicle_id']==$vid && $fs['dist_month']===$mon) { $in_flat=true; break; }
        }
        if ($in_flat) foreach ($recs as $rec) { $all_maint[] = $rec + ['_vid'=>$vid,'_mon'=>$mon]; }
    }
}
// Get vehicle names for lookup
$vno_map = array_column($vehicles,'vehicle_no','vehicle_id');

$maint_total_cost   = array_sum(array_column($all_maint,'total_cost'));
$maint_total_labor  = array_sum(array_column($all_maint,'labor_cost'));
$maint_total_parts  = array_sum(array_column($all_maint,'parts_cost'));
$maint_total_down   = array_sum(array_column($all_maint,'downtime_days'));
?>
<div class="maint-section">
    <h3>🔧 Maintenance Records / मर्मत सम्भार विवरण
        <span style="font-size:12px;font-weight:400;color:#555;margin-left:12px">
            <?= count($all_maint) ?> records — Total Cost: रू <?= number_format($maint_total_cost,2) ?>
        </span>
    </h3>
    <?php if(empty($all_maint)): ?>
    <div style="padding:20px;text-align:center;color:#666;font-size:13px">No maintenance records for the selected period.</div>
    <?php else: ?>
    <table class="maint">
        <thead>
            <tr>
                <th>क्र.</th>
                <th>सवारी नं.<br>Vehicle</th>
                <th>महिना<br>Month</th>
                <th>मर्मत प्रकार<br>Type</th>
                <th>मिति (BS)<br>Date</th>
                <th>मिटर<br>Meter</th>
                <th>कार्यको विवरण<br>Work Description</th>
                <th>सेवा प्रदायक<br>Service Provider</th>
                <th>बिल नं.<br>Bill No</th>
                <th>श्रम खर्च<br>Labor (रू)</th>
                <th>पार्ट्स खर्च<br>Parts (रू)</th>
                <th>जम्मा खर्च<br>Total (रू)</th>
                <th>डाउनटाइम<br>Downtime (days)</th>
                <th>स्थिति<br>Status</th>
                <th>टिप्पणी<br>Remarks</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach($all_maint as $mi=>$mr): ?>
        <?php
        $mrStatus = $mr['status']??'completed';
        $stcls = ['pending'=>'st-pend','in_progress'=>'st-ip','cancelled'=>'st-canc'][$mrStatus] ?? 'st-comp';
        $stlbl = ['pending'=>'Pending','in_progress'=>'In Progress','cancelled'=>'Cancelled'][$mrStatus] ?? 'Completed';
        ?>
        <tr style="<?=$mi%2==1?'background:#fafafa':''?>">
            <td><?=$mi+1?></td>
            <td class="tl"><strong><?= htmlspecialchars($vno_map[$mr['_vid']]??$mr['_vid']) ?></strong></td>
            <td><?=$mr['_mon']?><br><small><?=$nep_month_dev[$mr['_mon']]??''?></small></td>
            <td class="tl">
                <?= htmlspecialchars($mr['type_name']) ?>
                <?php if($mr['is_scheduled']): ?><br><small style="color:#137333">(Scheduled)</small><?php endif; ?>
            </td>
            <td><?= htmlspecialchars($mr['maintenance_date_nep']) ?><br><small><?= $mr['maintenance_date_eng'] ?></small></td>
            <td><?= number_format($mr['meter_reading']) ?></td>
            <td class="tl" style="font-size:11px;max-width:150px"><?= htmlspecialchars($mr['work_description']?:'—') ?></td>
            <td class="tl" style="font-size:11px"><?= htmlspecialchars($mr['service_provider']?:'—') ?></td>
            <td><?= htmlspecialchars($mr['bill_no']?:'—') ?></td>
            <td class="tr"><?= number_format($mr['labor_cost'],2) ?></td>
            <td class="tr"><?= number_format($mr['parts_cost'],2) ?></td>
            <td class="tr"><strong>रू <?= number_format($mr['total_cost'],2) ?></strong></td>
            <td><?= (int)$mr['downtime_days'] ?> दिन</td>
            <td><span class="<?=$stcls?>"><?=$stlbl?></span></td>
            <td class="tl" style="font-size:11px"><?= htmlspecialchars($mr['remarks']?:'—') ?></td>
        </tr>
        <?php endforeach; ?>
        <!-- Maintenance Grand Total -->
        <tr class="maint-tot">
            <td colspan="9" class="tr">जम्मा (Total)</td>
            <td class="tr"><?= number_format($maint_total_labor,2) ?></td>
            <td class="tr"><?= number_format($maint_total_parts,2) ?></td>
            <td class="tr">रू <?= number_format($maint_total_cost,2) ?></td>
            <td><?= (int)$maint_total_down ?> दिन</td>
            <td colspan="2"></td>
        </tr>
        </tbody>
    </table>
    <?php endif; ?>
</div>

<!-- ═══ REMARKS (print only) ═══ -->
<div class="remarks-box">
    <table class="rmk-tbl">
        <tr>
            <td style="width:16%;font-weight:700;background:#f0f0f0;vertical-align:top">टिप्पणी<br>Remarks</td>
            <td>&nbsp;<br>&nbsp;<br>&nbsp;</td>
        </tr>
    </table>
</div>

<!-- ═══ SIGNATURES — 3 columns, no Accounts Officer ═══ -->
<div class="sig-row">
    <table class="sig-tbl">
        <tr>
            <td><div class="sigln"> प्रमुख <br>सवारी इकाई  </div></td>
            <td><div class="sigln"> प्रमुख<br>कर्मचारी प्रशासन शाखा </div></td>
            <td><div class="sigln">प्रमुख<br>प्रशासन विभाग </div></td>
            <td><div class="sigln">प्रमुख<br>मानव संसाधन तथा वित्त निर्देशनालय  </div></td>
            <td><div class="sigln"> प्रबन्ध सञ्चालक <br><br></div></td>
        </tr>
    </table>
</div>

</div><!-- /wrap -->

<!-- ═══ GENERATE MODAL ═══ -->
<div id="genModal" class="modal no-print">
    <div class="mbox">
        <div class="mtitle">&#9889; Generate Monthly Summary</div>
        <form method="POST">
            <div style="margin-bottom:10px">
                <label style="display:flex;align-items:center;gap:7px;font-size:13px">
                    <input type="radio" name="action" value="generate_summary" checked> Single Vehicle
                </label>
            </div>
            <div id="svFields" style="margin-bottom:10px">
                <div class="fg"><label style="font-size:12px;font-weight:700;margin-bottom:3px">Vehicle</label>
                    <select name="vehicle_id" id="gen_v">
                        <option value="">Select Vehicle</option>
                        <?php foreach($vehicles as $v): ?>
                        <option value="<?=$v['vehicle_id']?>"><?=htmlspecialchars($v['vehicle_no'])?> (<?=ucfirst($v['vehicle_type'])?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="margin-bottom:10px">
                <label style="display:flex;align-items:center;gap:7px;font-size:13px">
                    <input type="radio" name="action" value="generate_all"> All Vehicles
                </label>
            </div>
            <div class="fg2">
                <div class="fg"><label style="font-size:12px;font-weight:700;margin-bottom:3px">Fiscal Year</label>
                    <select name="fiscal_year" required>
                        <option value="2082/83">2082/83</option>
                        <option value="2083/84">2083/84</option>
                        <option value="2084/85">2084/85</option>
                    </select>
                </div>
                <div class="fg"><label style="font-size:12px;font-weight:700;margin-bottom:3px">Nepali Month</label>
                    <select name="month_nep" required>
                        <option value="">Select Month</option>
                        <?php foreach($nepali_months as $m): ?>
                        <option value="<?=$m?>"><?=$m?> (<?=$nep_month_dev[$m]?>)</option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;justify-content:flex-end;gap:7px;margin-top:14px;padding-top:12px;border-top:1px solid #eee">
                <button type="button" class="btn btn-sc" onclick="document.getElementById('genModal').classList.remove('open')">Cancel</button>
                <button type="submit" class="btn btn-s">&#9889; Generate</button>
            </div>
        </form>
    </div>
</div>

<script>
document.getElementById('genModal').addEventListener('click', function(e){ if(e.target===this) this.classList.remove('open'); });
document.querySelectorAll('input[name="action"]').forEach(r => {
    r.addEventListener('change', function(){
        var sf=document.getElementById('svFields'), gv=document.getElementById('gen_v');
        sf.style.display = this.value==='generate_summary'?'block':'none';
        gv.required      = this.value==='generate_summary';
    });
});
function toggleDetail(id){
    var el=document.getElementById(id);
    if(el) el.classList.toggle('open');
}
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

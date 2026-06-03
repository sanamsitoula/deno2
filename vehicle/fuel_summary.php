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
══════════════════════════════════════════════════ */
$raw_sql = "
    SELECT
        fcd.distribution_id, fcd.coupon_id,
        fcd.disburse_date_nep, fcd.disburse_date_eng,
        fcd.disburse_qty, fcd.rate_per_liter,
        (fcd.disburse_qty * fcd.rate_per_liter) AS line_amount,
        fcd.verified_flag,
        fcd.remarks    AS dist_remarks,
        fcd.fiscal_year AS dist_fiscal_year,
        fc.coupon_no,
        fc.month_nep   AS coupon_month,
        fc.fiscal_year AS coupon_fiscal_year,
        fc.fuel_type, fc.fuel_expense_type, fc.pump_name,
        fc.allocated_qty, fc.carry_forward_qty,
        (fc.allocated_qty + COALESCE(fc.carry_forward_qty,0)) AS total_coupon_avail,
        v.vehicle_id, v.vehicle_no, v.vehicle_type, v.fuel_per_liter_standard,
        COALESCE(d.driver_name,'') AS driver_name,
        COALESCE(d.driver_id, 0)   AS driver_id,
        SUBSTRING(fcd.disburse_date_nep FROM 6 FOR 2)::int AS dist_month_num
    FROM fuel_coupon_distributions fcd
    JOIN fuel_coupons fc       ON fcd.coupon_id  = fc.coupon_id
    JOIN vehicles v            ON fc.vehicle_id   = v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda
           ON vda.vehicle_id = v.vehicle_id AND vda.active_flag=TRUE AND vda.deleted_at IS NULL
    LEFT JOIN drivers d ON d.driver_id = vda.driver_id
    WHERE fcd.deleted_at IS NULL AND fc.deleted_at IS NULL AND v.deleted_at IS NULL";

$raw_params = [];
if ($f_fiscal)    { $raw_sql .= " AND fcd.fiscal_year       = :rff"; $raw_params[':rff']=$f_fiscal; }
if ($f_vehicle)   { $raw_sql .= " AND fc.vehicle_id         = :rfv"; $raw_params[':rfv']=$f_vehicle; }
if ($f_fuel_type) { $raw_sql .= " AND fc.fuel_type          = :rft"; $raw_params[':rft']=$f_fuel_type; }
if ($f_exp_type)  { $raw_sql .= " AND fc.fuel_expense_type  = :rfe"; $raw_params[':rfe']=$f_exp_type; }
if ($f_driver)    { $raw_sql .= " AND vda.driver_id         = :rfd"; $raw_params[':rfd']=$f_driver; }
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
    $raw_rows = array_values(array_filter($raw_rows, fn($r)=>$r['dist_month']===$f_month));
}

/* ══════════════════════════════════════════════════
   GROUP by vehicle + dist_month
══════════════════════════════════════════════════ */
$summary = [];
foreach ($raw_rows as $r) {
    $vid=$r['vehicle_id']; $month=$r['dist_month']; $fuel=$r['fuel_type'];
    if (!isset($summary[$vid][$month])) {
        $summary[$vid][$month] = [
            'vehicle_id'=>$vid,'vehicle_no'=>$r['vehicle_no'],'vehicle_type'=>$r['vehicle_type'],
            'driver'=>$r['driver_name'],'driver_id'=>$r['driver_id'],
            'dist_month'=>$month,'fiscal_year'=>$r['dist_fiscal_year']?:$r['coupon_fiscal_year'],
            'fuel_std'=>$r['fuel_per_liter_standard'],
            'opening_meter'=>null,'closing_meter'=>null,'total_km'=>0,
            'petrol_qty'=>0,'petrol_amt'=>0,'diesel_qty'=>0,'diesel_amt'=>0,
            'mobil_qty'=>0,'mobil_amt'=>0,'total_qty'=>0,'total_amt'=>0,
            'coupon_details'=>[],
        ];
    }
    $qty=(float)$r['disburse_qty']; $amt=(float)$r['line_amount'];
    $summary[$vid][$month]['total_qty']+=$qty;
    $summary[$vid][$month]['total_amt']+=$amt;
    if ($fuel==='petrol'){$summary[$vid][$month]['petrol_qty']+=$qty;$summary[$vid][$month]['petrol_amt']+=$amt;}
    if ($fuel==='diesel'){$summary[$vid][$month]['diesel_qty']+=$qty;$summary[$vid][$month]['diesel_amt']+=$amt;}
    if ($fuel==='mobil') {$summary[$vid][$month]['mobil_qty'] +=$qty;$summary[$vid][$month]['mobil_amt'] +=$amt;}
    $cid=$r['coupon_id'];
    if (!isset($summary[$vid][$month]['coupon_details'][$cid])) {
        $cn='';
        if($r['coupon_month']!==$month) $cn='Coupon issued in '.$r['coupon_month'];
        if((float)($r['carry_forward_qty']??0)>0)
            $cn.=($cn?'; ':'').'Carry fwd: '.number_format($r['carry_forward_qty'],2).' L';
        $summary[$vid][$month]['coupon_details'][$cid]=[
            'coupon_no'=>$r['coupon_no']?:'C-'.$cid,'coupon_month'=>$r['coupon_month'],
            'fuel_type'=>$fuel,'fuel_exp'=>$r['fuel_expense_type'],'pump_name'=>$r['pump_name'],
            'allocated'=>(float)$r['total_coupon_avail'],'carry_note'=>$cn,
            'rows'=>[],'sub_qty'=>0,'sub_amt'=>0,
        ];
    }
    $summary[$vid][$month]['coupon_details'][$cid]['rows'][]  =$r;
    $summary[$vid][$month]['coupon_details'][$cid]['sub_qty']+=$qty;
    $summary[$vid][$month]['coupon_details'][$cid]['sub_amt']+=$amt;
}

/* ══════════════════════════════════════════════════
   METER READINGS — from vehicle_daily_logs
══════════════════════════════════════════════════ */
if (!empty($summary)) {
    $vid_in = implode(',', array_map('intval', array_keys($summary)));
    $lw='WHERE vdl.deleted_at IS NULL AND vdl.vehicle_id IN ('.$vid_in.')';
    $lp=[];
    if($f_fiscal){$lw.=" AND vdl.fiscal_year=:lffy";$lp[':lffy']=$f_fiscal;}
    if($f_month) {$lw.=" AND vdl.month_nep=:lfmn"; $lp[':lfmn']=$f_month;}

    $ls="SELECT vdl.vehicle_id,vdl.month_nep,MIN(vdl.start_meter) AS opening_meter,
               MAX(vdl.end_meter) AS closing_meter,
               SUM(vdl.end_meter - vdl.start_meter) AS total_km_logs
        FROM vehicle_daily_logs vdl $lw
          AND vdl.month_nep IS NOT NULL AND TRIM(vdl.month_nep)<>''
        GROUP BY vdl.vehicle_id,vdl.month_nep";
    $lst=$conn->prepare($ls); $lst->execute($lp);
    foreach($lst->fetchAll(PDO::FETCH_ASSOC) as $lr){
        $vid=$lr['vehicle_id'];$mon=$lr['month_nep'];
        if(isset($summary[$vid][$mon])){
            $summary[$vid][$mon]['opening_meter']=(int)$lr['opening_meter'];
            $summary[$vid][$mon]['closing_meter'] =(int)$lr['closing_meter'];
            $summary[$vid][$mon]['total_km']      =(int)$lr['total_km_logs'];
        }
    }
    // fallback: derive from log_date_nep for rows with null month_nep
    $ls2="SELECT vdl.vehicle_id,
               CASE SPLIT_PART(REPLACE(REPLACE(TRIM(vdl.log_date_nep),'-','.'),'/','.'),'.',2)::INT
                   WHEN 1 THEN 'Baishakh' WHEN 2 THEN 'Jestha' WHEN 3 THEN 'Ashadh'
                   WHEN 4 THEN 'Shrawan'  WHEN 5 THEN 'Bhadra' WHEN 6 THEN 'Ashwin'
                   WHEN 7 THEN 'Kartik'   WHEN 8 THEN 'Mangsir' WHEN 9 THEN 'Poush'
                   WHEN 10 THEN 'Magh' WHEN 11 THEN 'Falgun' WHEN 12 THEN 'Chaitra' ELSE NULL END AS dm,
               MIN(vdl.start_meter) AS opening_meter, MAX(vdl.end_meter) AS closing_meter,
               SUM(vdl.end_meter - vdl.start_meter) AS total_km_logs
          FROM vehicle_daily_logs vdl
          WHERE vdl.deleted_at IS NULL AND vdl.vehicle_id IN ($vid_in)
            AND (vdl.month_nep IS NULL OR TRIM(vdl.month_nep)='')";
    if($f_fiscal) $ls2.=" AND vdl.fiscal_year=".$conn->quote($f_fiscal);
    $ls2.=" GROUP BY vdl.vehicle_id,dm HAVING CASE SPLIT_PART(REPLACE(REPLACE(TRIM(vdl.log_date_nep),'-','.'),'/','.'),'.',2)::INT WHEN 1 THEN 'Baishakh' WHEN 2 THEN 'Jestha' WHEN 3 THEN 'Ashadh' WHEN 4 THEN 'Shrawan' WHEN 5 THEN 'Bhadra' WHEN 6 THEN 'Ashwin' WHEN 7 THEN 'Kartik' WHEN 8 THEN 'Mangsir' WHEN 9 THEN 'Poush' WHEN 10 THEN 'Magh' WHEN 11 THEN 'Falgun' WHEN 12 THEN 'Chaitra' ELSE NULL END IS NOT NULL";
    try{
        foreach($conn->query($ls2)->fetchAll(PDO::FETCH_ASSOC) as $lr){
            $vid=$lr['vehicle_id'];$mon=$lr['dm'];
            if($mon&&isset($summary[$vid][$mon])&&$summary[$vid][$mon]['opening_meter']===null){
                $summary[$vid][$mon]['opening_meter']=(int)$lr['opening_meter'];
                $summary[$vid][$mon]['closing_meter'] =(int)$lr['closing_meter'];
                $summary[$vid][$mon]['total_km']      =(int)$lr['total_km_logs'];
            }
        }
    }catch(Exception $e){}
    // secondary fallback: monthly_vehicle_summary table
    try{
        $mvs="SELECT vehicle_id,month_nep,opening_meter,closing_meter,total_km
              FROM monthly_vehicle_summary WHERE vehicle_id IN ($vid_in) AND deleted_at IS NULL";
        if($f_fiscal) $mvs.=" AND fiscal_year=".$conn->quote($f_fiscal);
        foreach($conn->query($mvs)->fetchAll(PDO::FETCH_ASSOC) as $mr){
            $vid=$mr['vehicle_id'];$mon=$mr['month_nep'];
            if(isset($summary[$vid][$mon])&&($summary[$vid][$mon]['opening_meter']===null||$summary[$vid][$mon]['opening_meter']==0)){
                $summary[$vid][$mon]['opening_meter']=(int)$mr['opening_meter'];
                $summary[$vid][$mon]['closing_meter'] =(int)$mr['closing_meter'];
                $summary[$vid][$mon]['total_km']      =(int)$mr['total_km'];
            }
        }
    }catch(Exception $e){}
}

/* ══════════════════════════════════════════════════
   MAINTENANCE RECORDS
══════════════════════════════════════════════════ */
$maintenance_map=[];
if(!empty($summary)){
    $vid_in=implode(',',array_map('intval',array_keys($summary)));
    $ms="SELECT vmr.maintenance_id,vmr.vehicle_id,vmr.maintenance_date_nep,vmr.maintenance_date_eng,
               vmr.meter_reading,vmr.work_description,vmr.parts_replaced,vmr.service_provider,
               vmr.labor_cost,vmr.parts_cost,vmr.total_cost,vmr.bill_no,vmr.downtime_days,
               vmr.status,vmr.remarks,mt.type_name,mt.is_scheduled,
               SUBSTRING(vmr.maintenance_date_nep FROM 6 FOR 2)::int AS maint_month_num
          FROM vehicle_maintenance_records vmr
          JOIN maintenance_types mt ON mt.maintenance_type_id=vmr.maintenance_type_id
          WHERE vmr.vehicle_id IN ($vid_in) AND vmr.deleted_at IS NULL";
    if($f_fiscal) $ms.=" AND vmr.fiscal_year=".$conn->quote($f_fiscal);
    $ms.=" ORDER BY vmr.maintenance_date_nep";
    try{
        foreach($conn->query($ms)->fetchAll(PDO::FETCH_ASSOC) as $mr){
            $mn=$num_to_month[$mr['maint_month_num']]??'';
            if($mn) $maintenance_map[$mr['vehicle_id']][$mn][]=$mr;
        }
    }catch(Exception $e){}
}

/* ══════════════════════════════════════════════════
   CARRY-OVER METER
══════════════════════════════════════════════════ */
foreach($summary as $vid=>&$months){
    uksort($months,fn($a,$b)=>($month_order[$a]??99)-($month_order[$b]??99));
    $prev=null;
    foreach($months as $mon=>&$data){
        if($prev!==null&&($data['opening_meter']===null||$data['opening_meter']==0))
            $data['opening_meter']=$prev;
        if($data['closing_meter']!==null&&$data['closing_meter']>0)
            $prev=$data['closing_meter'];
    }unset($data);
}unset($months);

/* ══════════════════════════════════════════════════
   FLATTEN & SORT
══════════════════════════════════════════════════ */
$flat=[];
foreach($summary as $vid=>$months)
    foreach($months as $mon=>$data) $flat[]=$data;
usort($flat,function($a,$b)use($month_order){
    $vc=strcmp($a['vehicle_no'],$b['vehicle_no']);
    return $vc!==0?$vc:($month_order[$a['dist_month']]??99)-($month_order[$b['dist_month']]??99);
});

$gt_km=array_sum(array_column($flat,'total_km'));
$gt_qty=array_sum(array_column($flat,'total_qty'));
$gt_amt=array_sum(array_column($flat,'total_amt'));
$gt_p_q=array_sum(array_column($flat,'petrol_qty')); $gt_p_a=array_sum(array_column($flat,'petrol_amt'));
$gt_d_q=array_sum(array_column($flat,'diesel_qty')); $gt_d_a=array_sum(array_column($flat,'diesel_amt'));
$gt_m_q=array_sum(array_column($flat,'mobil_qty'));  $gt_m_a=array_sum(array_column($flat,'mobil_amt'));

/* maintenance flat */
$vno_map=array_column($vehicles,'vehicle_no','vehicle_id');
$all_maint=[];
foreach($maintenance_map as $vid=>$months){
    foreach($months as $mon=>$recs){
        $in=false;
        foreach($flat as $fs){if($fs['vehicle_id']==$vid&&$fs['dist_month']===$mon){$in=true;break;}}
        if($in) foreach($recs as $rec) $all_maint[]=$rec+['_vid'=>$vid,'_mon'=>$mon];
    }
}
$has_maintenance=!empty($all_maint);
$maint_total_cost =array_sum(array_column($all_maint,'total_cost'));
$maint_total_labor=array_sum(array_column($all_maint,'labor_cost'));
$maint_total_parts=array_sum(array_column($all_maint,'parts_cost'));
$maint_total_down =array_sum(array_column($all_maint,'downtime_days'));
?>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;600;700&family=Noto+Sans:wght@400;600;700&display=swap" rel="stylesheet">

<style>
*,body,input,select,button,textarea,th,td{font-family:'Noto Sans Devanagari','Noto Sans',Arial,sans-serif!important;box-sizing:border-box;}
body{background:#f5f7fa;font-size:14px;color:#111;}
.wrap{max-width:1900px;margin:0 auto;padding:16px;}
.ph{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px;}
.ph h1{font-size:20px;font-weight:700;margin:0;}
.ph .btns{display:flex;gap:7px;}
.btn{padding:7px 14px;border:none;border-radius:4px;font-size:13px;font-weight:600;cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;}
.btn-s{background:#28a745;color:#fff;} .btn-p{background:#007bff;color:#fff;}
.btn-sc{background:#6c757d;color:#fff;} .btn-g{background:#137333;color:#fff;}
.btn-xl{background:#1d6f42;color:#fff;}
.alert{padding:9px 14px;border-radius:4px;margin-bottom:10px;font-size:13px;}
.a-ok{background:#d4edda;color:#155724;border:1px solid #c3e6cb;}
.a-err{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb;}
.sbar{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px;}
.sc{background:#fff;border:1px solid #ddd;border-radius:5px;padding:8px 14px;min-width:110px;}
.sc .sl{font-size:11px;color:#666;}.sc .sv{font-size:18px;font-weight:700;}
.fbar{background:#fff;border:1px solid #ddd;border-radius:5px;padding:10px 14px;margin-bottom:10px;}
.fgrid{display:grid;grid-template-columns:repeat(auto-fit,minmax(140px,1fr));gap:8px;align-items:end;}
.fg{display:flex;flex-direction:column;gap:2px;}
.fg label{font-size:11px;font-weight:700;color:#444;}
.fg input,.fg select{padding:5px 7px;border:1px solid #ccc;border-radius:3px;font-size:13px;width:100%;}
.tbl-wrap{background:#fff;border-radius:5px;overflow-x:auto;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:20px;}
table.main{width:100%;border-collapse:collapse;font-size:12px;}
table.main th{background:#f0f0f0;padding:7px 5px;text-align:center;font-weight:700;font-size:11px;border:1px solid #bbb;white-space:nowrap;}
table.main td{padding:6px 5px;border:1px solid #ddd;text-align:center;vertical-align:middle;}
table.main .tl{text-align:left;} table.main .tr{text-align:right;}
table.main .tot td{background:#e0e0e0!important;font-weight:700;border-top:2px solid #888;}
th.pet{background:#fff9e6!important;} th.die{background:#e6f7ff!important;} th.mob{background:#ede6ff!important;}
td.pet{background:#fffdf0;} td.die{background:#f0f9ff;} td.mob{background:#f5f0ff;}
td.kaif{text-align:left;min-width:90px;font-size:11px;}
td.sig-col{min-width:70px;}
.coupon-detail{display:none;}
.coupon-detail.open{display:table-row-group;}
table.cdtbl{width:100%;border-collapse:collapse;font-size:11px;}
table.cdtbl th{background:#e8e8e8;padding:4px 5px;border:1px solid #ccc;font-size:10px;text-align:center;}
table.cdtbl td{padding:3px 5px;border:1px solid #eee;text-align:center;}
table.cdtbl .cdsub td{background:#f0f8e8;font-weight:700;border-top:1px solid #bbb;}
.expand-btn{cursor:pointer;background:#e8f0fe;border:none;border-radius:3px;padding:2px 7px;font-size:11px;color:#1a73e8;font-weight:600;}
.maint-section{background:#fff;border-radius:5px;box-shadow:0 1px 3px rgba(0,0,0,.1);margin-bottom:20px;overflow-x:auto;}
.maint-section h3{font-size:14px;font-weight:700;padding:10px 14px;margin:0;border-bottom:2px solid #dee2e6;background:#f8f9fa;}
table.maint{width:100%;border-collapse:collapse;font-size:12px;}
table.maint th{background:#f0f0f0;padding:6px;text-align:center;font-weight:700;font-size:11px;border:1px solid #bbb;white-space:nowrap;}
table.maint td{padding:5px 6px;border:1px solid #ddd;text-align:center;vertical-align:middle;}
table.maint .tl{text-align:left;}
.maint-tot td{background:#e8e8e8!important;font-weight:700;border-top:2px solid #888;}
.st-pend{color:#856404;}.st-comp{color:#155724;}.st-ip{color:#004085;}.st-canc{color:#721c24;}
.modal{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:1000;align-items:center;justify-content:center;}
.modal.open{display:flex;}
.mbox{background:#fff;border-radius:7px;padding:22px;max-width:480px;width:90%;}
.mtitle{font-size:16px;font-weight:700;margin-bottom:14px;border-bottom:2px solid #eee;padding-bottom:10px;}
.fg2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px;}

/* ══════════════════════════════════════════════
   SIGNATURE BLOCK — screen always shown at bottom,
   print: inline flow, NO position:fixed (prevents overlap)
══════════════════════════════════════════════ */
.sig-block{
    width:100%;margin-top:18px;padding-top:8px;
    border-top:2px solid #444;
    page-break-inside:avoid;
}
.sig-tbl{width:94%;margin:0 auto;border-collapse:collapse;font-size:13px;}
.sig-tbl td{text-align:center;padding:0 10px;vertical-align:bottom;}
.sigln{border-top:1px solid #000;margin-top:32px;padding-top:4px;font-size:12px;line-height:1.5;}

/* Screen-only: letterhead hidden */
.print-head{display:none;}

/* ══════════════════════════════════════════════
   PRINT STYLES
   - bottom margin reserves space for signature
   - signature is position:relative (inline flow)
   - NO position:fixed — avoids overlap
══════════════════════════════════════════════ */
@media print{
    /* Give enough bottom margin for the sig block on each page */
    @page{size:A4 landscape;margin:0.35in 0.3in 0.35in 0.3in;}

    .no-print,.fbar,.ph .btns,.sbar,.expand-btn,.modal{display:none!important;}
    body{background:#fff;font-size:10pt;color:#000;}
    .wrap{padding:0;max-width:100%;}

    /* Letterhead */
    .print-head{display:block!important;text-align:center;border-bottom:2pt solid #000;margin-bottom:6pt;padding-bottom:4pt;}
    .print-head .org{font-size:14pt;font-weight:700;}
    .print-head .rpt{font-size:11pt;font-weight:700;margin:2pt 0;}
    .print-head .meta{font-size:9pt;}
    .ph h1{display:none;}

    /* Main table */
    .tbl-wrap{box-shadow:none;overflow:visible;margin-bottom:6pt;}
    table.main{font-size:8pt;page-break-inside:auto;}
    table.main th{background:#e0e0e0!important;font-size:7.5pt;padding:3pt 2pt;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    table.main td{padding:2.5pt 2pt;border:0.5pt solid #999;font-size:8pt;}
    table.main .tot td{background:#c8c8c8!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    th.pet{background:#ffe!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    th.die{background:#e0f0ff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    th.mob{background:#e8e0ff!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    td.pet{background:#fffef0!important;} td.die{background:#f0f8ff!important;} td.mob{background:#f5f0ff!important;}

    /* Always show coupon details */
    .coupon-detail{display:table-row-group!important;}
    table.cdtbl{font-size:7.5pt;}
    table.cdtbl th{background:#e8e8e8!important;padding:2pt;font-size:7pt;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    table.cdtbl td{padding:2pt;border:0.5pt solid #ccc;}
    table.cdtbl .cdsub td{background:#e0f0e0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}

    /* Maintenance */
    .maint-section{box-shadow:none;}
    .maint-section h3{font-size:10pt;padding:4pt 6pt;background:#e0e0e0!important;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    table.maint{font-size:8pt;}
    table.maint th{background:#e0e0e0!important;font-size:7.5pt;padding:2.5pt 3pt;-webkit-print-color-adjust:exact;print-color-adjust:exact;}
    table.maint td{padding:2.5pt 3pt;border:0.5pt solid #999;}
    .maint-tot td{background:#c8c8c8!important;}

    /* ── Signature: inline flow, no fixed position ── */
    .sig-block{
        margin-top:14pt;
        padding-top:6pt;
        border-top:1.5pt solid #333;
        page-break-inside:avoid;
        /* Keep the sig block together and at the bottom of the last content page */
    }
    .sig-tbl{width:94%;margin:0 auto;font-size:8.5pt;}
    .sig-tbl td{padding:0 6pt;}
    .sigln{border-top:0.5pt solid #000;margin-top:20pt;padding-top:3pt;font-size:8pt;line-height:1.4;}
}
</style>

<div class="wrap">

<!-- ══════════ PRINT LETTERHEAD ══════════ -->
<div class="print-head">
    <div class="org">Janak Education Materials Centre Ltd.</div>
    <div class="rpt">मासिक सवारी साधन ईन्धन विवरण — Monthly Vehicle Fuel Summary</div>
    <div class="meta">
        <?php $meta=[];
        if($f_fiscal)    $meta[]='आ.व.: '.$f_fiscal;
        if($f_month)     $meta[]='महिना: '.($nep_month_dev[$f_month]??$f_month);
        if($f_fuel_type) $meta[]='ईन्धन: '.strtoupper($f_fuel_type);
        if($f_exp_type)  $meta[]='खर्च: '.($fuel_expense_types[$f_exp_type]??$f_exp_type);
        echo implode('  |  ',$meta); ?>
        &nbsp;&nbsp; मुद्रण मिति: <?=date('Y-m-d')?>
    </div>
</div>

<!-- ══════════ SCREEN HEADER ══════════ -->
<div class="ph">
    <h1>&#128202; Monthly Vehicle Fuel Summary</h1>
    <div class="btns no-print">
        <button class="btn btn-s" onclick="document.getElementById('genModal').classList.add('open')">&#9889; Generate</button>
        <?php
        // Build Excel export URL from current filters
        $xl_params = http_build_query([
            'export'=>'xlsx','f_fiscal'=>$f_fiscal,'f_month'=>$f_month,
            'f_vehicle'=>$f_vehicle,'f_driver'=>$f_driver,
            'f_fuel_type'=>$f_fuel_type,'f_exp_type'=>$f_exp_type
        ]);
        ?>
        <a href="?<?=$xl_params?>" class="btn btn-xl">&#128190; Export Excel</a>
        <button class="btn btn-g" onclick="window.print()">&#128424; Print</button>
    </div>
</div>

<?php
/* ══════════════════════════════════════════════════
   EXCEL EXPORT — triggered by ?export=xlsx
   Outputs .xlsx and exits — must be AFTER $flat is built
══════════════════════════════════════════════════ */
if (($_GET['export'] ?? '') === 'xlsx') {
    // Use PhpSpreadsheet if available, otherwise fall back to openpyxl via python
    // We use a pure-PHP CSV-to-xlsx workaround via a temp file and Python
    $rows = [];
    // Header row
    $rows[] = [
        'SN','Vehicle No','Vehicle Type','Driver','Month','Fiscal Year',
        'Opening Meter','Closing Meter','Total KM',
        'Petrol (L)','Petrol Amt (Rs)','Diesel (L)','Diesel Amt (Rs)',
        'Mobil (L)','Mobil Amt (Rs)',
        'Total Fuel (L)','Total Cost (Rs)',
        'Avg km/L','Std km/L','Performance'
    ];
    foreach ($flat as $i => $s) {
        $avg = ($s['total_km']>0&&$s['total_qty']>0) ? round($s['total_km']/$s['total_qty'],2) : '';
        $std = $s['fuel_std'] ?? 11.5;
        $perf = $avg==='' ? '' : ($avg>=$std ? 'On/Above Std' : 'Below Std');
        $rows[] = [
            $i+1, $s['vehicle_no'], $s['vehicle_type'], $s['driver'], $s['dist_month'], $s['fiscal_year'],
            $s['opening_meter']?:0, $s['closing_meter']?:0, $s['total_km']?:0,
            $s['petrol_qty'], $s['petrol_amt'], $s['diesel_qty'], $s['diesel_amt'],
            $s['mobil_qty'], $s['mobil_amt'],
            $s['total_qty'], $s['total_amt'],
            $avg, $std, $perf
        ];
    }
    // Grand total
    $rows[] = [
        '','Grand Total','','','','','','',
        $gt_km, $gt_p_q, $gt_p_a, $gt_d_q, $gt_d_a, $gt_m_q, $gt_m_a,
        $gt_qty, $gt_amt, '', '', ''
    ];

    // Write JSON data to temp file, call Python to build XLSX
    $json_tmp = tempnam(sys_get_temp_dir(), 'fuelrpt_');
    $maint_rows = [];
    if ($has_maintenance) {
        $maint_rows[] = ['SN','Vehicle','Month','Maintenance Type','Date (BS)','Date (AD)',
                         'Meter','Work Description','Service Provider','Bill No',
                         'Labor Cost (Rs)','Parts Cost (Rs)','Total Cost (Rs)','Downtime (days)','Status','Remarks'];
        foreach ($all_maint as $mi => $mr) {
            $maint_rows[] = [
                $mi+1, $vno_map[$mr['_vid']]??$mr['_vid'], $mr['_mon'],
                $mr['type_name'].($mr['is_scheduled']?' (Scheduled)':''),
                $mr['maintenance_date_nep'], $mr['maintenance_date_eng'],
                $mr['meter_reading'], $mr['work_description']??'',
                $mr['service_provider']??'', $mr['bill_no']??'',
                $mr['labor_cost'], $mr['parts_cost'], $mr['total_cost'],
                (int)$mr['downtime_days'], $mr['status']??'completed', $mr['remarks']??''
            ];
        }
    }

    file_put_contents($json_tmp, json_encode([
        'title'       => 'Janak Education Materials Centre Ltd. - Monthly Vehicle Fuel Summary',
        'fiscal_year' => $f_fiscal,
        'month'       => $f_month,
        'print_date'  => date('Y-m-d'),
        'fuel_rows'   => $rows,
        'maint_rows'  => $maint_rows,
    ]));

    $xlsx_out = tempnam(sys_get_temp_dir(), 'fuelrpt_').'.xlsx';
    $py_script = <<<'PYEOF'
import sys, json
from openpyxl import Workbook
from openpyxl.styles import (Font, PatternFill, Alignment, Border, Side,
                              GradientFill)
from openpyxl.utils import get_column_letter

data   = json.loads(open(sys.argv[1]).read())
outfile= sys.argv[2]

wb = Workbook()
wb.remove(wb.active)  # remove default sheet

# ── colour palette
C_HEADER_BG  = '1F4E79'   # dark navy
C_HEADER_FG  = 'FFFFFF'
C_TITLE_BG   = '2E75B6'
C_ALT_ROW    = 'EBF3FB'
C_TOTAL_BG   = 'BDD7EE'
C_PETROL_H   = 'FFF2CC'
C_DIESEL_H   = 'DDEEFF'
C_MOBIL_H    = 'EDE4FF'
C_MAINT_H    = 'E2EFDA'
C_MAINT_TOT  = 'C6EFCE'
C_GOOD_PERF  = 'C6EFCE'
C_BAD_PERF   = 'FFC7CE'

thin  = Side(style='thin',  color='AAAAAA')
thick = Side(style='medium', color='555555')
bdr   = Border(left=thin, right=thin, top=thin, bottom=thin)
bdr_h = Border(left=thick,right=thick,top=thick,bottom=thick)

def hdr_font(sz=10, bold=True, color=C_HEADER_FG):
    return Font(name='Arial', bold=bold, size=sz, color=color)
def cell_font(sz=9, bold=False, color='000000'):
    return Font(name='Arial', bold=bold, size=sz, color=color)
def fill(hex_color):
    return PatternFill('solid', start_color=hex_color, fgColor=hex_color)
def center(wrap=False):
    return Alignment(horizontal='center', vertical='center', wrap_text=wrap)
def left(wrap=True):
    return Alignment(horizontal='left', vertical='center', wrap_text=wrap)

# ═══════════════════════════════════════════
#  SHEET 1 — FUEL SUMMARY
# ═══════════════════════════════════════════
ws = wb.create_sheet('Fuel Summary')
ws.freeze_panes = 'A5'
ws.sheet_view.showGridLines = False

# Title rows
ws.merge_cells('A1:T1')
ws['A1'] = data['title']
ws['A1'].font      = Font(name='Arial', bold=True, size=14, color='FFFFFF')
ws['A1'].fill      = fill(C_TITLE_BG)
ws['A1'].alignment = center(False)
ws['A1'].border    = bdr_h
ws.row_dimensions[1].height = 22

ws.merge_cells('A2:T2')
meta = []
if data.get('fiscal_year'): meta.append('Fiscal Year: ' + data['fiscal_year'])
if data.get('month'):       meta.append('Month: ' + data['month'])
meta.append('Print Date: ' + data['print_date'])
ws['A2'] = '  |  '.join(meta)
ws['A2'].font      = Font(name='Arial', bold=False, size=10, color='FFFFFF')
ws['A2'].fill      = fill('2E75B6')
ws['A2'].alignment = center()
ws.row_dimensions[2].height = 16

ws.row_dimensions[3].height = 5  # spacer

# Column headers (row 4) — matches PHP output
headers = data['fuel_rows'][0]
fuel_rows_data = data['fuel_rows'][1:]

# Colour header groups
petrol_cols = [10, 11]  # 1-indexed; Petrol L, Petrol Amt
diesel_cols = [12, 13]
mobil_cols  = [14, 15]
tot_cols    = [16, 17]
meter_cols  = [7, 8, 9]

col_fill_map = {}
for c in petrol_cols: col_fill_map[c] = C_PETROL_H
for c in diesel_cols: col_fill_map[c] = C_DIESEL_H
for c in mobil_cols:  col_fill_map[c] = C_MOBIL_H

for ci, h in enumerate(headers, start=1):
    cell = ws.cell(row=4, column=ci, value=h)
    bg   = col_fill_map.get(ci, C_HEADER_BG)
    fg   = '000000' if bg!=C_HEADER_BG else C_HEADER_FG
    cell.font      = Font(name='Arial', bold=True, size=9, color=fg)
    cell.fill      = fill(bg)
    cell.alignment = center(wrap=True)
    cell.border    = bdr_h
ws.row_dimensions[4].height = 30

# Data rows
for ri, row in enumerate(fuel_rows_data):
    excel_row = ri + 5
    is_total  = (str(row[1])=='Grand Total')
    alt       = ri % 2 == 1 and not is_total
    for ci, val in enumerate(row, start=1):
        cell = ws.cell(row=excel_row, column=ci, value=val)
        if is_total:
            cell.font = Font(name='Arial', bold=True, size=9)
            cell.fill = fill(C_TOTAL_BG)
        elif alt:
            cell.fill = fill(C_ALT_ROW)
        cell.border    = bdr
        cell.alignment = center() if ci not in [2,3,4,19] else left(wrap=False)
        # Performance colour
        if ci == 20 and not is_total:
            if val == 'On/Above Std':
                cell.fill = fill(C_GOOD_PERF)
                cell.font = Font(name='Arial',size=9,color='375623',bold=True)
            elif val == 'Below Std':
                cell.fill = fill(C_BAD_PERF)
                cell.font = Font(name='Arial',size=9,color='9C0006',bold=True)
        # Number format
        if ci in [7,8,9]: cell.number_format = '#,##0'
        if ci in [10,12,14,16,18,19]: cell.number_format = '#,##0.00'
        if ci in [11,13,15,17]: cell.number_format = '#,##0.00'

# Column widths
col_widths = [4,14,12,14,12,10,12,12,10,10,14,10,14,10,12,12,14,10,8,14]
for ci, w in enumerate(col_widths, start=1):
    ws.column_dimensions[get_column_letter(ci)].width = w

# ═══════════════════════════════════════════
#  SHEET 2 — MAINTENANCE (if any)
# ═══════════════════════════════════════════
if data['maint_rows']:
    wm = wb.create_sheet('Maintenance')
    wm.freeze_panes = 'A3'
    wm.sheet_view.showGridLines = False

    wm.merge_cells('A1:P1')
    wm['A1'] = data['title'] + ' — Maintenance Records'
    wm['A1'].font      = Font(name='Arial', bold=True, size=12, color='FFFFFF')
    wm['A1'].fill      = fill('375623')
    wm['A1'].alignment = center()
    wm.row_dimensions[1].height = 20

    maint_hdrs = data['maint_rows'][0]
    maint_data = data['maint_rows'][1:]
    for ci, h in enumerate(maint_hdrs, start=1):
        cell = wm.cell(row=2, column=ci, value=h)
        cell.font      = Font(name='Arial', bold=True, size=9, color='000000')
        cell.fill      = fill(C_MAINT_H)
        cell.alignment = center(wrap=True)
        cell.border    = bdr_h
    wm.row_dimensions[2].height = 26

    for ri, row in enumerate(maint_data):
        er = ri + 3
        is_tot = (ri == len(maint_data)-1)
        for ci, val in enumerate(row, start=1):
            cell = wm.cell(row=er, column=ci, value=val)
            cell.border    = bdr
            cell.alignment = center() if ci not in [7,8,9,15,16] else left()
            if ri%2==1: cell.fill = fill('F0FFF0')
            if is_tot:
                cell.fill = fill(C_MAINT_TOT)
                cell.font = Font(name='Arial',bold=True,size=9)
            if ci in [11,12,13]: cell.number_format = '#,##0.00'

    mw=[4,12,12,18,14,14,12,22,18,12,14,14,14,10,12,18]
    for ci,w in enumerate(mw,start=1):
        wm.column_dimensions[get_column_letter(ci)].width=w

wb.save(outfile)
print('OK')
PYEOF;

    $py_file = tempnam(sys_get_temp_dir(), 'fuelrpt_').'.py';
    file_put_contents($py_file, $py_script);
    $out = shell_exec("python3 ".escapeshellarg($py_file)." ".escapeshellarg($json_tmp)." ".escapeshellarg($xlsx_out)." 2>&1");

    if (file_exists($xlsx_out) && filesize($xlsx_out) > 0) {
        $fn = 'Vehicle_Fuel_Summary_'.($f_fiscal ? str_replace('/','-',$f_fiscal) : 'All').
              ($f_month ? '_'.$f_month : '').'_'.date('Ymd').'.xlsx';
        header('Content-Type: application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
        header('Content-Disposition: attachment; filename="'.$fn.'"');
        header('Content-Length: '.filesize($xlsx_out));
        header('Cache-Control: max-age=0');
        ob_end_clean();
        readfile($xlsx_out);
        @unlink($json_tmp); @unlink($py_file); @unlink($xlsx_out);
        exit;
    } else {
        $error_message = "Excel export failed. Error: ".htmlspecialchars($out);
        @unlink($json_tmp); @unlink($py_file);
    }
}
?>

<?php if($success_message): ?><div class="alert a-ok no-print">&#10003; <?=htmlspecialchars($success_message)?></div><?php endif; ?>
<?php if($error_message):   ?><div class="alert a-err no-print">&#10007; <?=htmlspecialchars($error_message)?></div><?php endif; ?>

<!-- ══════════ STATS ══════════ -->
<div class="sbar no-print">
    <div class="sc"><div class="sl">Records</div><div class="sv"><?=count($flat)?></div></div>
    <div class="sc"><div class="sl">Total Fuel</div><div class="sv"><?=number_format($gt_qty,2)?> L</div></div>
    <div class="sc"><div class="sl">Total Cost</div><div class="sv" style="color:#007bff">रू <?=number_format($gt_amt,2)?></div></div>
    <div class="sc"><div class="sl">Total KM</div><div class="sv"><?=number_format($gt_km)?></div></div>
    <div class="sc"><div class="sl">Below Std</div><div class="sv" style="color:#dc3545">
        <?=count(array_filter($flat,fn($r)=>$r['total_km']>0&&$r['total_qty']>0&&($r['total_km']/$r['total_qty'])<($r['fuel_std']??11.5)))?>
    </div></div>
</div>

<!-- ══════════ FILTERS ══════════ -->
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
        <div class="fg"><label>Month</label>
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
        <div class="fg" style="flex-direction:row;gap:5px">
            <button type="submit" class="btn btn-p">&#128269; Filter</button>
            <a href="<?=$_SERVER['PHP_SELF']?>?f_fiscal=2082/83" class="btn btn-sc">&#8635; Reset</a>
        </div>
    </div>
</form>

<!-- ══════════════════════════════════════════════
     FUEL SUMMARY TABLE
══════════════════════════════════════════════ -->
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
            <th rowspan="2">औसत<br>Avg km/L</th>
            <th rowspan="2">मानक<br>Std km/L</th>
            <th rowspan="2">प्रदर्शन<br>Performance</th>
            <th rowspan="2">कैफियत<br>Remarks</th>
            <th rowspan="2">सम्बद्धीय सवारी चालकको<br>दस्तखत / Driver Signature</th>
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
    <?php foreach($flat as $i=>$s):
        $avg = ($s['total_km']>0&&$s['total_qty']>0) ? $s['total_km']/$s['total_qty'] : 0;
        $std = (float)($s['fuel_std']??11.5);
        $perf= $avg==0?'—':($avg>=$std?'On/Above Std':'Below Std');
        $pc  = $avg==0?'#888':($avg>=$std?'#137333':'#c0392b');
        $pb  = $avg==0?'':($avg>=$std?'#d4edda':'#f8d7da');
        $rowid='cd-'.$i;
        $mc=count($maintenance_map[$s['vehicle_id']][$s['dist_month']]??[]);
        $om=($s['opening_meter']!==null&&$s['opening_meter']>0)?number_format($s['opening_meter']):'—';
        $cm=($s['closing_meter'] !==null&&$s['closing_meter'] >0)?number_format($s['closing_meter']) :'—';
        $km=($s['total_km']>0)?number_format($s['total_km']):'—';
    ?>
    <tr style="<?=$i%2==1?'background:#fafafa':''?>">
        <td><?=$i+1?></td>
        <td class="tl"><strong><?=htmlspecialchars($s['vehicle_no'])?></strong><br>
            <small style="color:#888"><?=ucfirst($s['vehicle_type'])?></small></td>
        <td class="tl" style="font-size:11px"><?=htmlspecialchars($s['driver']?:'—')?></td>
        <td><?=$s['dist_month']?><br><small style="color:#666"><?=$nep_month_dev[$s['dist_month']]??''?> <?=$s['fiscal_year']?></small></td>
        <td><?=$om?></td>
        <td><?=$cm?></td>
        <td><strong><?=$km?></strong></td>
        <td class="pet"><?=$s['petrol_qty']>0?number_format($s['petrol_qty'],2):'—'?></td>
        <td class="pet tr"><?=$s['petrol_amt']>0?number_format($s['petrol_amt'],2):'—'?></td>
        <td class="die"><?=$s['diesel_qty']>0?number_format($s['diesel_qty'],2):'—'?></td>
        <td class="die tr"><?=$s['diesel_amt']>0?number_format($s['diesel_amt'],2):'—'?></td>
        <td class="mob"><?=$s['mobil_qty']>0 ?number_format($s['mobil_qty'],2) :'—'?></td>
        <td class="mob tr"><?=$s['mobil_amt']>0 ?number_format($s['mobil_amt'],2) :'—'?></td>
        <td class="tr"><strong style="color:#007bff">रू <?=number_format($s['total_amt'],2)?></strong></td>
        <td><?=number_format($s['total_qty'],2)?></td>
        <td><strong style="color:<?=$pc?>"><?=$avg>0?number_format($avg,2):'—'?></strong></td>
        <td><?=number_format($std,2)?></td>
        <td><?php if($pb):?><span style="padding:2px 6px;border-radius:3px;font-size:10px;font-weight:700;background:<?=$pb?>;color:<?=$pc?>"><?=$perf?></span><?php else:?>—<?php endif;?></td>
        <td class="kaif"><?php if($mc>0):?><span style="color:#856404;font-size:10px">&#9881; <?=$mc?> maint.</span><?php else:?>&nbsp;<?php endif;?></td>
        <td class="sig-col">&nbsp;</td>
        <td class="no-print"><button class="expand-btn" onclick="toggleDetail('<?=$rowid?>')">&#9660; Detail</button></td>
    </tr>
    <!-- coupon detail -->
    <tr><td colspan="22" style="padding:0;border:0">
    <div class="coupon-detail" id="<?=$rowid?>">
    <?php foreach($s['coupon_details'] as $cid=>$cd):
        $fl=strtoupper($cd['fuel_type']);
        $el=$fuel_expense_types[$cd['fuel_exp']??'']??($cd['fuel_exp']?:'—');
    ?>
    <div style="padding:4px 14px 6px 28px;background:#f8f9fa;border-top:1px solid #ddd">
        <div style="font-size:11px;font-weight:700;color:#333;margin-bottom:2px">
            Coupon: <strong><?=htmlspecialchars($cd['coupon_no'])?></strong>
            &nbsp;|&nbsp;<?=$fl?> &nbsp;|&nbsp;<?=$el?>
            &nbsp;|&nbsp; Pump: <?=htmlspecialchars($cd['pump_name']?:'—')?>
            &nbsp;|&nbsp; Allocated: <?=number_format($cd['allocated'],2)?> L
            <?php if($cd['carry_note']):?>&nbsp;|&nbsp;<em style="color:#666;font-size:10px"><?=htmlspecialchars($cd['carry_note'])?></em><?php endif;?>
        </div>
        <table class="cdtbl"><thead><tr>
            <th>क्र.</th><th>मिति BS</th><th>मिति AD</th>
            <th>परिमाण (L)</th><th>दर/L (रू)</th><th>रकम (रू)</th><th>प्रमाणित</th><th>टिप्पणी</th>
        </tr></thead><tbody>
        <?php foreach($cd['rows'] as $ri=>$rr):?>
        <tr>
            <td><?=$ri+1?></td>
            <td><?=htmlspecialchars($rr['disburse_date_nep'])?></td>
            <td><?=htmlspecialchars($rr['disburse_date_eng'])?></td>
            <td><strong><?=number_format($rr['disburse_qty'],2)?></strong></td>
            <td><?=number_format($rr['rate_per_liter'],2)?></td>
            <td class="tr"><strong>रू <?=number_format($rr['line_amount'],2)?></strong></td>
            <td style="color:<?=$rr['verified_flag']?'#137333':'#c0392b'?>"><?=$rr['verified_flag']?'✓':'—'?></td>
            <td class="tl" style="font-size:10px"><?=htmlspecialchars($rr['dist_remarks']?:'—')?></td>
        </tr>
        <?php endforeach;?>
        <tr class="cdsub">
            <td colspan="3" class="tr">उप-जम्मा</td>
            <td><?=number_format($cd['sub_qty'],2)?> L</td><td>—</td>
            <td class="tr">रू <?=number_format($cd['sub_amt'],2)?></td>
            <td colspan="2"></td>
        </tr>
        </tbody></table>
    </div>
    <?php endforeach;?>
    </div></td></tr>
    <?php endforeach;?>

    <!-- grand total -->
    <tr class="tot">
        <td colspan="4" class="tr">जम्मा (Grand Total)</td>
        <td>—</td><td>—</td><td><?=number_format($gt_km)?></td>
        <td class="pet"><?=number_format($gt_p_q,2)?></td>
        <td class="pet tr">रू <?=number_format($gt_p_a,2)?></td>
        <td class="die"><?=number_format($gt_d_q,2)?></td>
        <td class="die tr">रू <?=number_format($gt_d_a,2)?></td>
        <td class="mob"><?=number_format($gt_m_q,2)?></td>
        <td class="mob tr">रू <?=number_format($gt_m_a,2)?></td>
        <td class="tr">रू <?=number_format($gt_amt,2)?></td>
        <td><?=number_format($gt_qty,2)?></td>
        <td colspan="6"></td>
    </tr>
    <?php endif;?>
    </tbody>
</table>
</div>

<!-- ══════════ MAINTENANCE (hidden when empty) ══════════ -->
<?php if($has_maintenance):?>
<div class="maint-section">
    <h3>&#128295; Maintenance Records / मर्मत सम्भार विवरण
        <span style="font-size:12px;font-weight:400;color:#555;margin-left:12px">
            <?=count($all_maint)?> records — Total: रू <?=number_format($maint_total_cost,2)?>
        </span>
    </h3>
    <table class="maint"><thead><tr>
        <th>क्र.</th><th>सवारी नं.</th><th>महिना</th><th>मर्मत प्रकार</th>
        <th>मिति (BS)</th><th>मिटर</th><th>कार्यको विवरण</th><th>सेवा प्रदायक</th>
        <th>बिल नं.</th><th>श्रम खर्च(रू)</th><th>पार्ट्स(रू)</th><th>जम्मा(रू)</th>
        <th>डाउनटाइम</th><th>स्थिति</th><th>टिप्पणी</th>
    </tr></thead><tbody>
    <?php foreach($all_maint as $mi=>$mr):
        $stcls=match($mr['status']??'completed'){'pending'=>'st-pend','in_progress'=>'st-ip','cancelled'=>'st-canc',default=>'st-comp'};
        $stlbl=match($mr['status']??'completed'){'pending'=>'Pending','in_progress'=>'In Progress','cancelled'=>'Cancelled',default=>'Completed'};
    ?>
    <tr style="<?=$mi%2==1?'background:#fafafa':''?>">
        <td><?=$mi+1?></td>
        <td class="tl"><strong><?=htmlspecialchars($vno_map[$mr['_vid']]??$mr['_vid'])?></strong></td>
        <td><?=$mr['_mon']?></td>
        <td class="tl"><?=htmlspecialchars($mr['type_name'])?>
            <?php if($mr['is_scheduled']):?><br><small style="color:#137333">(Scheduled)</small><?php endif;?></td>
        <td><?=htmlspecialchars($mr['maintenance_date_nep'])?><br><small><?=$mr['maintenance_date_eng']?></small></td>
        <td><?=number_format($mr['meter_reading'])?></td>
        <td class="tl" style="font-size:11px;max-width:150px"><?=htmlspecialchars($mr['work_description']?:'—')?></td>
        <td class="tl" style="font-size:11px"><?=htmlspecialchars($mr['service_provider']?:'—')?></td>
        <td><?=htmlspecialchars($mr['bill_no']?:'—')?></td>
        <td class="tr"><?=number_format($mr['labor_cost'],2)?></td>
        <td class="tr"><?=number_format($mr['parts_cost'],2)?></td>
        <td class="tr"><strong>रू <?=number_format($mr['total_cost'],2)?></strong></td>
        <td><?=(int)$mr['downtime_days']?> दिन</td>
        <td><span class="<?=$stcls?>"><?=$stlbl?></span></td>
        <td class="tl" style="font-size:11px"><?=htmlspecialchars($mr['remarks']?:'—')?></td>
    </tr>
    <?php endforeach;?>
    <tr class="maint-tot">
        <td colspan="9" class="tr">जम्मा (Total)</td>
        <td class="tr"><?=number_format($maint_total_labor,2)?></td>
        <td class="tr"><?=number_format($maint_total_parts,2)?></td>
        <td class="tr">रू <?=number_format($maint_total_cost,2)?></td>
        <td><?=(int)$maint_total_down?> दिन</td>
        <td colspan="2"></td>
    </tr>
    </tbody></table>
</div>
<?php endif;?>

<!-- ══════════════════════════════════════════════════════
     SIGNATURE BLOCK
     Inline flow — appears after all content.
     In print: page-break-inside:avoid keeps the 5 lines together.
     NO position:fixed → NO overlap.
══════════════════════════════════════════════════════ -->
<div class="sig-block">
    <table class="sig-tbl">
        <tr>
            <td><div class="sigln">प्रमुख<br>सवारी इकाई</div></td>
            <td><div class="sigln">प्रमुख<br>कर्मचारी प्रशासन शाखा</div></td>
            <td><div class="sigln">प्रमुख<br>प्रशासन विभाग</div></td>
            <td><div class="sigln">प्रमुख<br>मानव संसाधन तथा वित्त निर्देशनालय</div></td>
            <td><div class="sigln">प्रबन्ध सञ्चालक</div></td>
        </tr>
    </table>
</div>

</div><!-- /wrap -->

<!-- ══════════ GENERATE MODAL ══════════ -->
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
                        <?php foreach($vehicles as $v):?>
                        <option value="<?=$v['vehicle_id']?>"><?=htmlspecialchars($v['vehicle_no'])?> (<?=ucfirst($v['vehicle_type'])?>)</option>
                        <?php endforeach;?>
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
                <div class="fg"><label style="font-size:12px;font-weight:700;margin-bottom:3px">Month</label>
                    <select name="month_nep" required>
                        <option value="">Select Month</option>
                        <?php foreach($nepali_months as $m):?>
                        <option value="<?=$m?>"><?=$m?> (<?=$nep_month_dev[$m]?>)</option>
                        <?php endforeach;?>
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
document.getElementById('genModal').addEventListener('click',function(e){if(e.target===this)this.classList.remove('open');});
document.querySelectorAll('input[name="action"]').forEach(r=>{
    r.addEventListener('change',function(){
        var sf=document.getElementById('svFields'),gv=document.getElementById('gen_v');
        sf.style.display=this.value==='generate_summary'?'block':'none';
        gv.required=this.value==='generate_summary';
    });
});
function toggleDetail(id){var el=document.getElementById(id);if(el)el.classList.toggle('open');}
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
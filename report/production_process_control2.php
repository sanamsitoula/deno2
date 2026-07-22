<?php
/**
 * Production Reporting Suite — v5 Complete
 * ═══════════════════════════════════════════════════════════
 * Tabs:
 *  1. Daily Production (FP per date/machine, JT forma qty, target-actual-balance, shift name+time, operator/incharge/supervisor, search filter, default 30-day range)
 *  2. Machine Calendar (Nepali BS calendar, all machines at once, date filter, search)
 *  3. Operator Work Report (shift name+time, per-hour outcome per shift)
 *  4. Supervisor Work Report
 *  5. Manpower & Shift (per-hour outcome)
 *  6. Machine Trend & Analysis (Nepali dates, calendar view, personnel rankings, suggestions)
 *  7. JT vs FP + Book Packing Comparison (JT vs BP above, forma-level FP vs JTD below, search filter)
 *
 * DB: forma_printing, job_ticket, job_ticket_details, books, machines, shifts(name col), users, book_packing, deno, d2m, fiscal_years
 * Shifts table: id, name (varchar), start_time, end_time, duration_hours
 * forma_printing date filter: created_date::date or date_eng
 */

// ─── DB CONFIG ────────────────────────────────────────────
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ─── Permissions ─────────────────────────────────────────────────────────
if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// ─── HELPERS ──────────────────────────────────────────────
function fmtQty($n){if($n===null)return'0';$n=(int)$n;if($n>=100000)return round($n/1000).'k';if($n>=10000)return number_format($n/1000,1).'k';return number_format($n);}
function h($s){return htmlspecialchars($s??'',ENT_QUOTES,'UTF-8');}

// ─── NEPALI DATE ENGINE ───────────────────────────────────
$bsMonthDays=[
    2070=>[31,31,32,31,31,31,30,29,30,29,30,30],
    2071=>[31,31,32,31,32,30,30,29,30,29,30,30],
    2072=>[31,32,31,32,31,30,30,30,29,29,30,31],
    2073=>[31,31,32,31,31,31,30,29,30,29,30,30],
    2074=>[31,31,32,32,31,30,30,29,30,29,30,30],
    2075=>[31,32,31,32,31,30,30,30,29,29,30,31],
    2076=>[31,32,31,32,31,30,30,30,29,30,29,31],
    2077=>[31,31,32,31,31,31,30,29,30,29,30,30],
    2078=>[31,31,32,31,32,30,30,29,30,29,30,30],
    2079=>[31,32,31,32,31,30,30,30,29,29,30,31],
    2080=>[31,32,31,32,31,30,30,30,29,30,29,31],
    2081=>[31,31,32,31,31,31,30,29,30,29,30,30],
    2082=>[31,31,32,32,31,30,30,29,30,29,30,30],
    2083=>[31,32,31,32,31,30,30,30,29,29,30,31],
    2084=>[31,31,32,31,31,31,30,29,30,29,30,30],
    2085=>[31,31,32,32,31,30,30,29,30,29,30,30],
    2086=>[31,32,31,32,31,30,30,30,29,29,30,31],
    2087=>[31,31,32,31,31,31,30,29,30,29,30,30],
    2088=>[31,31,32,32,31,30,30,29,30,29,30,30],
    2089=>[31,32,31,32,31,30,30,30,29,29,30,31],
    2090=>[31,31,32,31,31,31,30,29,30,29,30,30],
];
$bsMonthNep=['बैशाख','जेष्ठ','आषाढ','श्रावण','भाद्र','आश्विन','कार्तिक','मंसिर','पौष','माघ','फाल्गुन','चैत्र'];
$bsMonthEn=['Baisakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin','Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
$bsDayNep=['आइत','सोम','मंगल','बुध','बिही','शुक्र','शनि'];
$bsDayEn=['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// BS 2080-01-01 = AD 2023-04-14 (Friday=5)
function bsToAd($bY,$bM,$bD){
    global $bsMonthDays;
    $ref=mktime(0,0,0,4,14,2023); $td=0;
    for($y=2080;$y<$bY;$y++){if(isset($bsMonthDays[$y]))$td+=array_sum($bsMonthDays[$y]);else $td+=365;}
    if(isset($bsMonthDays[$bY]))for($m=1;$m<$bM;$m++)$td+=$bsMonthDays[$bY][$m-1];
    $td+=($bD-1);
    return date('Y-m-d',$ref+($td*86400));
}
function adToBs($ad){
    global $bsMonthDays;
    $ref=mktime(0,0,0,4,14,2023);$t=strtotime($ad);if(!$t)return['y'=>2082,'m'=>1,'d'=>1];
    $diff=(int)round(($t-$ref)/86400);$bY=2080;$bM=1;$bD=1;
    if($diff>=0){while($diff>0){$dim=isset($bsMonthDays[$bY])?$bsMonthDays[$bY][$bM-1]:30;$dl=$dim-$bD;
    if($diff<=$dl){$bD+=$diff;$diff=0;}else{$diff-=($dl+1);$bM++;$bD=1;if($bM>12){$bM=1;$bY++;}}}}
    return['y'=>$bY,'m'=>$bM,'d'=>$bD];
}
function fmtBs($y,$m,$d){return sprintf('%04d.%02d.%02d',$y,$m,$d);}
function getBsDim($y,$m){global $bsMonthDays;return isset($bsMonthDays[$y])?($bsMonthDays[$y][$m-1]??30):30;}
function dow($ad){return(int)date('w',strtotime($ad));}// 0=Sun

// ─── PARSE NEPALI DATE INPUT (supports YYYY.MM.DD or YYYY-MM-DD) ──
function parseNepDate($s){
    if(preg_match('/^(\d{4})[.\-](\d{1,2})[.\-](\d{1,2})$/',$s,$m))return[(int)$m[1],(int)$m[2],(int)$m[3]];
    return null;
}

// ─── FILTERS ──────────────────────────────────────────────
$activeTab   = $_GET['tab']           ?? 'daily';
$fiscalYearId= $_GET['fiscal_year_id']?? '';
$filterMachine=$_GET['machine_id']    ?? 'all';
$trendView   = $_GET['trend_view']    ?? 'daily';
$searchQuery = $_GET['search']        ?? '';

// Default date range: today - 30 days to today
$defaultFrom = date('Y-m-d', strtotime('-30 days'));
$defaultTo   = date('Y-m-d');

$filterDateFrom = $_GET['date_from'] ?? $defaultFrom;
$filterDateTo   = $_GET['date_to']   ?? $defaultTo;
$nepFrom        = $_GET['nep_from']  ?? '';
$nepTo          = $_GET['nep_to']    ?? '';

// Calendar tab BS year/month
$calBsYear  = (int)($_GET['cal_bs_year']  ?? 0);
$calBsMonth = (int)($_GET['cal_bs_month'] ?? 0);

// Active fiscal year
if(!$fiscalYearId){$stmt=$conn->query("SELECT id FROM fiscal_years WHERE is_active=true LIMIT 1");$f=$stmt->fetch();$fiscalYearId=$f?$f['id']:1;}

// Default BS calendar to current month
if(!$calBsYear||!$calBsMonth){$tb=adToBs(date('Y-m-d'));$calBsYear=$calBsYear?:$tb['y'];$calBsMonth=$calBsMonth?:$tb['m'];}

// Convert nepali inputs to AD
if($nepFrom){$p=parseNepDate($nepFrom);if($p)$filterDateFrom=bsToAd($p[0],$p[1],$p[2]);}
if($nepTo){$p=parseNepDate($nepTo);if($p)$filterDateTo=bsToAd($p[0],$p[1],$p[2]);}

// ─── LOAD LOOKUPS ─────────────────────────────────────────
$machines=$conn->query("SELECT id,machine_name FROM machines WHERE status='active' ORDER BY id")->fetchAll();
$shifts=[];
try{$shifts=$conn->query("SELECT * FROM shifts ORDER BY id")->fetchAll();}catch(Exception $e){}
$shiftMap=[];foreach($shifts as $s)$shiftMap[$s['id']]=$s;
$fiscalYears=$conn->query("SELECT id,fiscal_code,fiscal_name,is_active FROM fiscal_years ORDER BY id DESC")->fetchAll();
$mcColors=['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488','#be185d','#4f46e5','#ca8a04','#059669'];

function shiftName($sid){global $shiftMap;if(!$sid||!isset($shiftMap[$sid]))return'-';$s=$shiftMap[$sid];return $s['name']??$s['shift_name']??('Shift '.$sid);}
function shiftTime($sid){global $shiftMap;if(!$sid||!isset($shiftMap[$sid]))return'';$s=$shiftMap[$sid];
    if(isset($s['start_time'])&&isset($s['end_time']))return $s['start_time'].' to '.$s['end_time'];return $s['shift_time']??'';}
function shiftHours($sid){global $shiftMap;if(!$sid||!isset($shiftMap[$sid]))return 8;$s=$shiftMap[$sid];return (float)($s['duration_hours']??$s['hours']??8);}

// BS display strings for filters
$fromBs=adToBs($filterDateFrom);$toBs=adToBs($filterDateTo);
$fromBsStr=fmtBs($fromBs['y'],$fromBs['m'],$fromBs['d']);
$toBsStr=fmtBs($toBs['y'],$toBs['m'],$toBs['d']);

// URL builder
function buildUrl($tab,$extra=[]){
    global $fiscalYearId,$filterDateFrom,$filterDateTo,$filterMachine,$calBsYear,$calBsMonth,$trendView,$searchQuery;
    $p=array_merge(['tab'=>$tab,'fiscal_year_id'=>$fiscalYearId,'date_from'=>$filterDateFrom,'date_to'=>$filterDateTo,
        'machine_id'=>$filterMachine,'cal_bs_year'=>$calBsYear,'cal_bs_month'=>$calBsMonth,'trend_view'=>$trendView,'search'=>$searchQuery],$extra);
    return'?'.http_build_query($p);
}

// ═══════════════════════════════════════════════════════════
// DATA QUERIES — ALL TABS
// ═══════════════════════════════════════════════════════════

// TAB 1: DAILY PRODUCTION
function getDailyData($conn,$df,$dt,$mid,$fy,$search){
    $sql="SELECT fp.id,fp.name AS fp_name,fp.date_nep,fp.date_eng,fp.fp_printqty,fp.fp_remainqty,fp.jtd_targetqty,fp.remarks,
        m.id AS machine_id,m.machine_name,s.id AS shift_id,
        jt.id AS jt_id,jt.job_ticket_code,jt.print_qty AS jt_print_qty,jt.lot,
        jtd.id AS jtd_id,jtd.order_no,jtd.page AS forma_page,jtd.print_qty AS jtd_print_qty,
        b.book_code,b.book_name,b.class_level,b.book_type,
        u_op.username AS operator_name,u_sv.username AS supervisor_name,u_ic.username AS incharge_name
        FROM forma_printing fp
        LEFT JOIN machines m ON fp.machine_id=m.id LEFT JOIN shifts s ON fp.shift_id=s.id
        LEFT JOIN job_ticket jt ON fp.jt_id=jt.id LEFT JOIN job_ticket_details jtd ON fp.jtd_id=jtd.id
        LEFT JOIN books b ON jt.book_id=b.book_id
        LEFT JOIN users u_op ON fp.operator_id=u_op.id LEFT JOIN users u_sv ON fp.supervisor_id=u_sv.id
        LEFT JOIN users u_ic ON fp.incharge_id=u_ic.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt";
    $p=[':fy'=>$fy,':df'=>$df,':dt'=>$dt];
    if($mid!=='all'){$sql.=" AND fp.machine_id=:mid";$p[':mid']=$mid;}
    if($search){$sql.=" AND (b.book_code ILIKE :s OR b.book_name ILIKE :s OR jt.job_ticket_code ILIKE :s OR u_op.username ILIKE :s OR u_sv.username ILIKE :s OR m.machine_name ILIKE :s)";$p[':s']="%$search%";}
    $sql.=" ORDER BY fp.date_eng DESC,m.machine_name,jt.job_ticket_code";
    $st=$conn->prepare($sql);$st->execute($p);return $st->fetchAll();
}

// TAB 2: MACHINE CALENDAR (all machines, BS month)
function getCalData($conn,$bsY,$bsM,$fy,$search){
    $dim=getBsDim($bsY,$bsM);$sAd=bsToAd($bsY,$bsM,1);$eAd=bsToAd($bsY,$bsM,$dim);
    $sql="SELECT fp.date_eng,fp.date_nep,m.id AS machine_id,m.machine_name,
        SUM(fp.fp_printqty) AS total_fp,COUNT(fp.id) AS entry_count,COUNT(DISTINCT fp.jt_id) AS job_count,
        STRING_AGG(DISTINCT b.book_code,', ') AS book_codes
        FROM forma_printing fp JOIN machines m ON fp.machine_id=m.id
        LEFT JOIN job_ticket jt ON fp.jt_id=jt.id LEFT JOIN books b ON jt.book_id=b.book_id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:s AND fp.date_eng<=:e";
    $p=[':fy'=>$fy,':s'=>$sAd,':e'=>$eAd];
    if($search){$sql.=" AND (m.machine_name ILIKE :sq OR b.book_code ILIKE :sq)";$p[':sq']="%$search%";}
    $sql.=" GROUP BY fp.date_eng,fp.date_nep,m.id,m.machine_name ORDER BY fp.date_eng,m.machine_name";
    $st=$conn->prepare($sql);$st->execute($p);return $st->fetchAll();
}

// TAB 3: OPERATOR REPORT
function getOperatorData($conn,$df,$dt,$fy){
    $sql="SELECT u.id AS operator_id,u.username AS operator_name,
        s.id AS shift_id,COUNT(fp.id) AS total_entries,SUM(fp.fp_printqty) AS total_fp,
        SUM(fp.jtd_targetqty) AS total_target,COUNT(DISTINCT fp.jt_id) AS total_jobs,
        COUNT(DISTINCT fp.date_eng) AS working_days,COUNT(DISTINCT fp.machine_id) AS machine_count,
        STRING_AGG(DISTINCT m.machine_name,', ') AS machine_names
        FROM forma_printing fp JOIN users u ON fp.operator_id=u.id
        LEFT JOIN machines m ON fp.machine_id=m.id LEFT JOIN shifts s ON fp.shift_id=s.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt
        GROUP BY u.id,u.username,s.id ORDER BY total_fp DESC";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy,':df'=>$df,':dt'=>$dt]);return $st->fetchAll();
}

// TAB 4: SUPERVISOR REPORT
function getSupervisorData($conn,$df,$dt,$fy){
    $sql="SELECT u.id AS supervisor_id,u.username AS supervisor_name,
        COUNT(DISTINCT fp.operator_id) AS team_size,COUNT(DISTINCT fp.machine_id) AS machines_count,
        STRING_AGG(DISTINCT m.machine_name,', ') AS machine_names,
        SUM(fp.fp_printqty) AS total_fp,SUM(fp.jtd_targetqty) AS total_target,
        COUNT(DISTINCT fp.jt_id) AS total_jobs,COUNT(DISTINCT fp.date_eng) AS working_days,COUNT(fp.id) AS total_entries
        FROM forma_printing fp JOIN users u ON fp.supervisor_id=u.id LEFT JOIN machines m ON fp.machine_id=m.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt
        GROUP BY u.id,u.username ORDER BY total_fp DESC";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy,':df'=>$df,':dt'=>$dt]);return $st->fetchAll();
}

// TAB 5: SHIFT + MANPOWER
function getShiftData($conn,$df,$dt,$fy){
    $sql="SELECT s.id AS shift_id,SUM(fp.fp_printqty) AS total_fp,
        COUNT(DISTINCT fp.operator_id) AS operator_count,COUNT(DISTINCT fp.machine_id) AS machine_count,
        COUNT(DISTINCT fp.jt_id) AS job_count,COUNT(fp.id) AS entry_count,
        COUNT(DISTINCT fp.date_eng) AS shift_days
        FROM forma_printing fp LEFT JOIN shifts s ON fp.shift_id=s.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt
        GROUP BY s.id ORDER BY total_fp DESC";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy,':df'=>$df,':dt'=>$dt]);return $st->fetchAll();
}
function getManpowerData($conn,$df,$dt,$fy){
    $sql="SELECT u.id AS user_id,u.username,u.role,s.id AS shift_id,m.machine_name,
        SUM(fp.fp_printqty) AS total_fp,COUNT(fp.id) AS entries,COUNT(DISTINCT fp.date_eng) AS working_days
        FROM forma_printing fp JOIN users u ON fp.operator_id=u.id
        LEFT JOIN shifts s ON fp.shift_id=s.id LEFT JOIN machines m ON fp.machine_id=m.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt
        GROUP BY u.id,u.username,u.role,s.id,m.machine_name ORDER BY s.id,total_fp DESC";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy,':df'=>$df,':dt'=>$dt]);return $st->fetchAll();
}

// TAB 6: TREND + ANALYSIS
function getTrendDaily($conn,$df,$dt,$fy){
    $sql="SELECT fp.date_eng,fp.date_nep,m.id AS machine_id,m.machine_name,SUM(fp.fp_printqty) AS total_fp
        FROM forma_printing fp JOIN machines m ON fp.machine_id=m.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt
        GROUP BY fp.date_eng,fp.date_nep,m.id,m.machine_name ORDER BY fp.date_eng,m.machine_name";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy,':df'=>$df,':dt'=>$dt]);return $st->fetchAll();
}
function getTrendMonthly($conn,$fy){
    $sql="SELECT SUBSTRING(fp.date_eng FROM 1 FOR 7) AS month,m.id AS machine_id,m.machine_name,
        SUM(fp.fp_printqty) AS total_fp,COUNT(DISTINCT fp.date_eng) AS active_days,COUNT(DISTINCT fp.jt_id) AS job_count
        FROM forma_printing fp JOIN machines m ON fp.machine_id=m.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy GROUP BY month,m.id,m.machine_name ORDER BY month,m.machine_name";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy]);return $st->fetchAll();
}
function getMachineRanking($conn,$df,$dt,$fy){
    $sql="SELECT m.id AS machine_id,m.machine_name,SUM(fp.fp_printqty) AS total_fp,
        SUM(fp.jtd_targetqty) AS total_target,COUNT(DISTINCT fp.jt_id) AS total_jobs,
        COUNT(DISTINCT fp.date_eng) AS active_days,COUNT(DISTINCT fp.operator_id) AS operators
        FROM forma_printing fp JOIN machines m ON fp.machine_id=m.id
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt
        GROUP BY m.id,m.machine_name ORDER BY total_fp DESC";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy,':df'=>$df,':dt'=>$dt]);return $st->fetchAll();
}
function getPersonnelRanking($conn,$fy,$role='operator'){
    $col=$role.'_id';
    $sql="SELECT u.id,u.username,SUM(fp.fp_printqty) AS total_fp,SUM(fp.jtd_targetqty) AS total_target,
        COUNT(fp.id) AS entries,COUNT(DISTINCT fp.date_eng) AS days,COUNT(DISTINCT fp.jt_id) AS jobs,
        CASE WHEN SUM(fp.jtd_targetqty)>0 THEN ROUND(SUM(fp.fp_printqty)::numeric/SUM(fp.jtd_targetqty)*100,1) ELSE 0 END AS efficiency
        FROM forma_printing fp JOIN users u ON fp.{$col}=u.id WHERE fp.status=true AND fp.fiscal_year_id=:fy
        GROUP BY u.id,u.username ORDER BY total_fp DESC";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy]);return $st->fetchAll();
}
// Daily production calendar data for trend tab
function getTrendCalData($conn,$df,$dt,$fy){
    $sql="SELECT fp.date_eng,fp.date_nep,SUM(fp.fp_printqty) AS total_fp,COUNT(DISTINCT fp.machine_id) AS machines,
        COUNT(DISTINCT fp.jt_id) AS jobs FROM forma_printing fp
        WHERE fp.status=true AND fp.fiscal_year_id=:fy AND fp.date_eng>=:df AND fp.date_eng<=:dt
        GROUP BY fp.date_eng,fp.date_nep ORDER BY fp.date_eng";
    $st=$conn->prepare($sql);$st->execute([':fy'=>$fy,':df'=>$df,':dt'=>$dt]);return $st->fetchAll();
}

// TAB 7: JT VS FP + BOOK PACKING
function getJtVsBpData($conn,$df,$dt,$fy,$search){
    $sql="SELECT jt.id AS jt_id,jt.job_ticket_code,jt.print_qty AS jt_total_qty,jt.page_qty,jt.lot,jt.status AS jt_status,
        jt.date_nep AS jt_date_nep,jt.date_eng AS jt_date_eng,
        b.book_code,b.book_name,b.class_level,b.book_type,
        COALESCE((SELECT SUM(bp.p_qty) FROM book_packing bp WHERE bp.jt_id=jt.id AND bp.status=true),0) AS bp_total,
        COALESCE((SELECT COUNT(bp.id) FROM book_packing bp WHERE bp.jt_id=jt.id AND bp.status=true),0) AS bp_entries,
        COALESCE((SELECT SUM(fp2.fp_printqty) FROM forma_printing fp2 WHERE fp2.jt_id=jt.id AND fp2.status=true),0) AS fp_total,
        COALESCE((SELECT COUNT(fp2.id) FROM forma_printing fp2 WHERE fp2.jt_id=jt.id AND fp2.status=true),0) AS fp_entries,
        COALESCE((SELECT COUNT(DISTINCT fp2.machine_id) FROM forma_printing fp2 WHERE fp2.jt_id=jt.id AND fp2.status=true),0) AS machines_used,
        COALESCE((SELECT STRING_AGG(DISTINCT m2.machine_name,', ') FROM forma_printing fp2 JOIN machines m2 ON m2.id=fp2.machine_id WHERE fp2.jt_id=jt.id AND fp2.status=true),'') AS machine_names
        FROM job_ticket jt LEFT JOIN books b ON jt.book_id=b.book_id
        WHERE jt.fiscal_year_id=:fy AND jt.date_eng>=:df AND jt.date_eng<=:dt";
    $p=[':fy'=>$fy,':df'=>$df,':dt'=>$dt];
    if($search){$sql.=" AND (jt.job_ticket_code ILIKE :s OR b.book_code ILIKE :s OR b.book_name ILIKE :s)";$p[':s']="%$search%";}
    $sql.=" ORDER BY jt.date_eng DESC,jt.job_ticket_code";
    $st=$conn->prepare($sql);$st->execute($p);return $st->fetchAll();
}
function getFormaComparison($conn,$df,$dt,$fy,$search){
    $sql="SELECT jt.job_ticket_code,jtd.id AS jtd_id,jtd.order_no,jtd.page AS forma_page,
        jtd.print_qty AS jtd_target_qty,jtd.status AS jtd_status,jtd.machine AS jtd_machine,
        b.book_code,b.book_name,
        COALESCE(SUM(fp.fp_printqty),0) AS fp_actual,COUNT(fp.id) AS fp_count,
        STRING_AGG(DISTINCT m.machine_name,', ') AS fp_machines
        FROM job_ticket jt JOIN job_ticket_details jtd ON jtd.job_ticket_id=jt.id
        LEFT JOIN books b ON jt.book_id=b.book_id
        LEFT JOIN forma_printing fp ON fp.jtd_id=jtd.id AND fp.status=true
        LEFT JOIN machines m ON fp.machine_id=m.id
        WHERE jt.fiscal_year_id=:fy AND jt.date_eng>=:df AND jt.date_eng<=:dt";
    $p=[':fy'=>$fy,':df'=>$df,':dt'=>$dt];
    if($search){$sql.=" AND (jt.job_ticket_code ILIKE :s OR b.book_code ILIKE :s)";$p[':s']="%$search%";}
    $sql.=" GROUP BY jt.job_ticket_code,jtd.id,jtd.order_no,jtd.page,jtd.print_qty,jtd.status,jtd.machine,b.book_code,b.book_name
        ORDER BY jt.job_ticket_code,jtd.order_no";
    $st=$conn->prepare($sql);$st->execute($p);return $st->fetchAll();
}

// ─── FETCH DATA ────────────────────────────────────────────
$dailyData=$calData=$operatorData=$supervisorData=$shiftRD=$manpowerData=[];
$trendDD=$trendMD=$machRank=$opRank=$svRank=$icRank=$trendCalData=[];
$jtBpData=$formaComp=[];

switch($activeTab){
    case'daily':$dailyData=getDailyData($conn,$filterDateFrom,$filterDateTo,$filterMachine,$fiscalYearId,$searchQuery);break;
    case'machine-cal':$calData=getCalData($conn,$calBsYear,$calBsMonth,$fiscalYearId,$searchQuery);break;
    case'operator':$operatorData=getOperatorData($conn,$filterDateFrom,$filterDateTo,$fiscalYearId);break;
    case'supervisor':$supervisorData=getSupervisorData($conn,$filterDateFrom,$filterDateTo,$fiscalYearId);break;
    case'shift':$shiftRD=getShiftData($conn,$filterDateFrom,$filterDateTo,$fiscalYearId);$manpowerData=getManpowerData($conn,$filterDateFrom,$filterDateTo,$fiscalYearId);break;
    case'trend':$trendDD=getTrendDaily($conn,$filterDateFrom,$filterDateTo,$fiscalYearId);$trendMD=getTrendMonthly($conn,$fiscalYearId);
        $machRank=getMachineRanking($conn,$filterDateFrom,$filterDateTo,$fiscalYearId);
        $opRank=getPersonnelRanking($conn,$fiscalYearId,'operator');$svRank=getPersonnelRanking($conn,$fiscalYearId,'supervisor');
        $icRank=getPersonnelRanking($conn,$fiscalYearId,'incharge');
        $trendCalData=getTrendCalData($conn,$filterDateFrom,$filterDateTo,$fiscalYearId);break;
    case'jt-vs-fp':$jtBpData=getJtVsBpData($conn,$filterDateFrom,$filterDateTo,$fiscalYearId,$searchQuery);
        $formaComp=getFormaComparison($conn,$filterDateFrom,$filterDateTo,$fiscalYearId,$searchQuery);break;
}

// Group daily by date→machine
$dailyG=[];foreach($dailyData as $r){$d=$r['date_eng'];$mid=$r['machine_id']??'x';$dailyG[$d][$mid]['mn']=$r['machine_name']??'Unknown';$dailyG[$d][$mid]['rows'][]=$r;}
// Calendar index by BS day→machine
$calDM=[];foreach($calData as $r){$bs=adToBs($r['date_eng']);if($bs['y']==$calBsYear&&$bs['m']==$calBsMonth)$calDM[$bs['d']][$r['machine_id']]=$r;}
// Trend pivots
$trL=[];$trS=[];foreach($trendDD as $r){if(!in_array($r['date_eng'],$trL))$trL[]=$r['date_eng'];$trS[$r['machine_name']][$r['date_eng']]=(int)$r['total_fp'];}
$moL=[];$moS=[];foreach($trendMD as $r){if(!in_array($r['month'],$moL))$moL[]=$r['month'];$moS[$r['machine_name']][$r['month']]=(int)$r['total_fp'];}
// Nepali labels for trend
$trNepL=[];foreach($trL as $d){$bs=adToBs($d);$trNepL[]=fmtBs($bs['y'],$bs['m'],$bs['d']);}
// Calendar data for trend
$tCalMap=[];foreach($trendCalData as $r)$tCalMap[$r['date_eng']]=$r;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1.0">
<title>Production Reports</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<style>
:root{--bg:#fff;--bg2:#f8f9fa;--bg3:#f1f3f5;--tx:#1a1a2e;--tx2:#64748b;--tx3:#adb5bd;--bd:#e2e8f0;--bd2:#dee2e6;
--blue:#2563eb;--blue-bg:#eff6ff;--blue-dk:#1e40af;--green:#16a34a;--green-bg:#f0fdf4;--green-dk:#166534;
--amber:#d97706;--amber-bg:#fffbeb;--amber-dk:#92400e;--coral:#dc2626;--coral-bg:#fef2f2;--coral-dk:#991b1b;
--purple:#7c3aed;--purple-bg:#f5f3ff;--purple-dk:#5b21b6;--teal:#0d9488;--teal-bg:#f0fdfa;--teal-dk:#115e59;
--r:8px;--rl:10px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;font-size:13px;color:var(--tx);background:var(--bg2);line-height:1.5}
.wrap{max-width:1300px;margin:0 auto;padding:14px}
.hdr{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:8px;margin-bottom:14px}
.hdr h1{font-size:17px;font-weight:700}.hdr .sub{font-size:11px;color:var(--tx2)}
.btn{display:inline-flex;align-items:center;gap:4px;padding:5px 12px;font-size:11px;border:1px solid var(--bd2);border-radius:var(--r);cursor:pointer;background:var(--bg);color:var(--tx);text-decoration:none;transition:.15s}
.btn:hover{background:var(--bg3)}.btn-p{background:var(--blue);color:#fff;border-color:var(--blue)}.btn-p:hover{background:var(--blue-dk)}
.tabs{display:flex;gap:1px;border-bottom:2px solid var(--bd);margin-bottom:16px;flex-wrap:wrap;overflow-x:auto}
.tabs a{padding:7px 11px;font-size:11px;color:var(--tx2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:.15s}
.tabs a:hover{color:var(--tx);background:var(--bg3)}.tabs a.act{color:var(--blue);border-bottom-color:var(--blue);font-weight:700}
.frow{display:flex;gap:6px;flex-wrap:wrap;align-items:center;margin-bottom:14px;background:var(--bg);padding:8px 12px;border:1px solid var(--bd);border-radius:var(--rl)}
.frow label{font-size:10px;color:var(--tx2);font-weight:700;text-transform:uppercase;letter-spacing:.3px}
.frow select,.frow input{padding:5px 8px;border:1px solid var(--bd2);border-radius:6px;font-size:11px;background:var(--bg);color:var(--tx)}
.frow .sep{width:1px;height:20px;background:var(--bd);margin:0 2px}
.mrow{display:grid;grid-template-columns:repeat(auto-fit,minmax(120px,1fr));gap:8px;margin-bottom:14px}
.met{background:var(--bg);border:1px solid var(--bd);border-radius:var(--rl);padding:10px 12px}
.met .l{font-size:9px;color:var(--tx2);margin-bottom:2px;text-transform:uppercase;letter-spacing:.5px;font-weight:700}
.met .v{font-size:18px;font-weight:800}.met .vs{font-size:13px;font-weight:700}
.card{background:var(--bg);border:1px solid var(--bd);border-radius:var(--rl);margin-bottom:12px;overflow:hidden}
.card-h{padding:8px 14px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap}
.card-h h3{font-size:12px;font-weight:700}.card-b{padding:12px 14px}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:11px}
th{padding:6px 8px;text-align:left;background:var(--bg3);color:var(--tx2);font-weight:700;font-size:10px;text-transform:uppercase;letter-spacing:.3px;border-bottom:1px solid var(--bd);white-space:nowrap}
td{padding:6px 8px;border-bottom:1px solid var(--bd)}tr:last-child td{border-bottom:none}tr:hover td{background:var(--bg2)}
.b{display:inline-block;padding:1px 7px;border-radius:var(--r);font-size:9px;font-weight:700}
.b-bl{background:var(--blue-bg);color:var(--blue-dk)}.b-gr{background:var(--green-bg);color:var(--green-dk)}
.b-am{background:var(--amber-bg);color:var(--amber-dk)}.b-co{background:var(--coral-bg);color:var(--coral-dk)}
.b-pu{background:var(--purple-bg);color:var(--purple-dk)}.b-te{background:var(--teal-bg);color:var(--teal-dk)}
.mblk{border:1px solid var(--bd);border-radius:var(--rl);margin-bottom:8px;overflow:hidden}
.mblk-h{background:var(--bg3);padding:6px 12px;display:flex;align-items:center;justify-content:space-between;gap:6px;flex-wrap:wrap}
.mblk-h .mn{font-weight:700;font-size:12px;display:flex;align-items:center;gap:5px}
.mblk-h .mf{font-size:11px;color:var(--tx2)}.mblk-h .mf strong{color:var(--tx)}
.dh{background:var(--bg3);border:1px solid var(--bd);border-radius:var(--r);padding:7px 12px;margin-bottom:10px;display:flex;align-items:center;gap:8px;flex-wrap:wrap;font-size:12px}
.dh .da{font-weight:700}.dh .db{color:var(--tx2);font-size:11px}
.cg{display:grid;grid-template-columns:repeat(7,1fr);gap:2px}
.chc{text-align:center;font-size:10px;font-weight:700;color:var(--tx2);padding:3px}
.cc{border:1px solid var(--bd);border-radius:6px;padding:3px;min-height:46px;font-size:9px;overflow:hidden}
.cc .dn{font-weight:700;font-size:10px;margin-bottom:1px;display:flex;justify-content:space-between;align-items:center}
.cc .dn .nep{font-size:8px;color:var(--green-dk);font-weight:800}
.cc.hi{border-color:var(--blue);background:var(--blue-bg)}.cc.md{border-color:var(--green);background:var(--green-bg)}
.cc.lo{border-color:var(--amber);background:var(--amber-bg)}.cc.idle{border-color:var(--bd);background:var(--bg2)}
.cc.today{outline:2px solid var(--amber);outline-offset:-1px;background:#fffbeb}
.mc-tag{display:inline-block;padding:0 3px;border-radius:3px;font-size:7px;font-weight:700;margin:1px;line-height:1.4}
.ins{background:var(--bg);border-left:3px solid var(--teal);border-radius:0 var(--r) var(--r) 0;padding:8px 12px;margin-bottom:7px;font-size:11px;border-top:1px solid var(--bd);border-right:1px solid var(--bd);border-bottom:1px solid var(--bd)}
.ins strong{font-weight:700}.ins.warn{border-left-color:var(--amber)}.ins.bad{border-left-color:var(--coral)}.ins.good{border-left-color:var(--green)}
.sbar{display:flex;align-items:center;gap:8px;margin-bottom:7px}
.sbar-l{font-size:11px;width:90px;color:var(--tx2);flex-shrink:0;font-weight:600}
.sbar-t{flex:1;background:var(--bg3);border-radius:4px;height:22px;overflow:hidden}
.sbar-f{height:100%;border-radius:4px;display:flex;align-items:center;padding-left:7px;font-size:10px;font-weight:700;color:#fff}
.lgd{display:flex;flex-wrap:wrap;gap:10px;font-size:10px;color:var(--tx2);margin-bottom:10px}
.lgd span{display:flex;align-items:center;gap:3px}.ld{width:9px;height:9px;border-radius:3px}
.chw{position:relative;width:100%}
.r2{display:grid;grid-template-columns:1fr 1fr;gap:12px;margin-bottom:12px}
.r3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:12px;margin-bottom:12px}
@media(max-width:768px){.r2,.r3{grid-template-columns:1fr}}
.empty{padding:24px;text-align:center;color:var(--tx2)}.empty i{font-size:24px;display:block;margin-bottom:6px;color:var(--tx3)}
.perf-bar{display:flex;align-items:center;gap:5px}.perf-track{width:70px;height:5px;background:var(--bg3);border-radius:3px;overflow:hidden}
.perf-fill{height:100%;border-radius:3px}
.nep-lbl{font-size:9px;color:var(--teal-dk);font-weight:700}
.comp-bar{height:16px;border-radius:3px;display:flex;overflow:hidden;background:var(--bg3)}
.comp-bar .done{background:var(--green);transition:.3s}
.pct-t{font-size:10px;font-weight:700;min-width:36px;text-align:right}
.shift-info{display:flex;flex-direction:column;gap:1px}
.shift-nm{font-weight:700;font-size:10px}.shift-tm{font-size:9px;color:var(--tx2)}
.per-hr{font-size:9px;color:var(--purple-dk);font-weight:700;background:var(--purple-bg);padding:0 4px;border-radius:3px;display:inline-block}
@media print{.tabs,.frow,.btn,.hdr .btn{display:none!important}.card,.mblk,.met{break-inside:avoid}.wrap{max-width:100%;padding:6px}body{background:#fff}}
</style>
</head>
<body>
<div class="wrap">

<!-- HEADER -->
<div class="hdr">
    <div><h1><i class="fa-solid fa-industry" style="color:var(--blue);margin-right:5px"></i>Production reporting suite</h1>
    <div class="sub"><?php $aFy=array_filter($fiscalYears,fn($f)=>$f['id']==$fiscalYearId);$aFy=reset($aFy);echo h($aFy['fiscal_name']??$aFy['fiscal_code']??'');?> — <?= h($fromBsStr)?> to <?= h($toBsStr)?></div></div>
    <div style="display:flex;gap:5px;flex-wrap:wrap;align-items:center">
        <select onchange="location='<?=buildUrl($activeTab)?>&fiscal_year_id='+this.value" style="padding:4px 7px;border:1px solid var(--bd2);border-radius:6px;font-size:11px">
            <?php foreach($fiscalYears as $fy):?><option value="<?=$fy['id']?>" <?=$fy['id']==$fiscalYearId?'selected':''?>><?=h($fy['fiscal_code'])?></option><?php endforeach;?>
        </select>
        <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
</div>

<!-- TABS -->
<div class="tabs">
<?php $tbs=['daily'=>['Daily production','fa-calendar-day'],'machine-cal'=>['Machine calendar','fa-calendar'],'operator'=>['Operator report','fa-user-gear'],'supervisor'=>['Supervisor report','fa-user-tie'],'shift'=>['Manpower & shift','fa-people-group'],'trend'=>['Trend & analysis','fa-chart-line'],'jt-vs-fp'=>['JT vs FP & BP','fa-scale-balanced']];
foreach($tbs as $k=>[$lbl,$ico]):?><a href="<?=buildUrl($k)?>" class="<?=$activeTab===$k?'act':''?>"><i class="fa-solid <?=$ico?>"></i> <?=$lbl?></a><?php endforeach;?>
</div>

<?php // ═══ COMMON DATE+SEARCH FILTER ═══
$showDF=in_array($activeTab,['daily','operator','supervisor','shift','trend','jt-vs-fp']);
if($showDF):?>
<form method="get" class="frow">
    <input type="hidden" name="tab" value="<?=h($activeTab)?>"><input type="hidden" name="fiscal_year_id" value="<?=$fiscalYearId?>">
    <label>From AD</label><input type="date" name="date_from" value="<?=h($filterDateFrom)?>">
    <label>To AD</label><input type="date" name="date_to" value="<?=h($filterDateTo)?>">
    <div class="sep"></div>
    <label>From BS</label><input type="text" name="nep_from" value="<?=h($nepFrom)?>" placeholder="2082.01.15" style="width:90px">
    <label>To BS</label><input type="text" name="nep_to" value="<?=h($nepTo)?>" placeholder="2082.02.15" style="width:90px">
    <div class="sep"></div>
    <?php if($activeTab==='daily'):?><label>Machine</label><select name="machine_id"><option value="all">All</option><?php foreach($machines as $m):?><option value="<?=$m['id']?>" <?=$filterMachine==$m['id']?'selected':''?>><?=h($m['machine_name'])?></option><?php endforeach;?></select><?php endif;?>
    <?php if(in_array($activeTab,['daily','jt-vs-fp'])):?><label>Search</label><input type="text" name="search" value="<?=h($searchQuery)?>" placeholder="Book, JT, operator..." style="width:140px"><?php endif;?>
    <?php if($activeTab==='trend'):?><label>View</label><select name="trend_view"><option value="daily" <?=$trendView==='daily'?'selected':''?>>Day-wise</option><option value="monthly" <?=$trendView==='monthly'?'selected':''?>>Month-wise</option></select><?php endif;?>
    <button type="submit" class="btn btn-p"><i class="fa-solid fa-filter"></i> Filter</button>
</form>
<?php endif;?>

<?php if($activeTab==='daily'):?>
<!-- ═══ TAB 1: DAILY PRODUCTION ═══ -->
<?php
$tFP=array_sum(array_column($dailyData,'fp_printqty'));$tTgt=array_sum(array_column($dailyData,'jtd_targetqty'));$tBal=$tTgt-$tFP;
$tJobs=count(array_unique(array_filter(array_column($dailyData,'jt_id'))));$mUsed=count(array_unique(array_filter(array_column($dailyData,'machine_id'))));
?>
<div class="mrow">
    <div class="met"><div class="l">Actual FP</div><div class="v"><?=fmtQty($tFP)?></div></div>
    <div class="met"><div class="l">Target qty</div><div class="v"><?=fmtQty($tTgt)?></div></div>
    <div class="met"><div class="l">Balance</div><div class="v" style="color:<?=$tBal>0?'var(--coral)':'var(--green)'?>"><?=fmtQty(abs($tBal))?> <?=$tBal>0?'behind':'ahead'?></div></div>
    <div class="met"><div class="l">Machines</div><div class="v"><?=$mUsed?></div></div>
    <div class="met"><div class="l">Job tickets</div><div class="v"><?=$tJobs?></div></div>
    <div class="met"><div class="l">Entries</div><div class="v"><?=count($dailyData)?></div></div>
</div>
<?php if(empty($dailyG)):?><div class="card"><div class="empty"><i class="fa-solid fa-inbox"></i>No data for selected range.</div></div>
<?php else:foreach($dailyG as $de=>$mds):$dBs=adToBs($de);$dBsS=fmtBs($dBs['y'],$dBs['m'],$dBs['d']);$nm=$bsMonthEn[$dBs['m']-1]??'';?>
<div class="dh"><i class="fa-solid fa-calendar" style="color:var(--tx2)"></i><span class="da"><?=h($de)?></span><span style="color:var(--tx3)">/</span><span class="db"><?=h($dBsS)?> (<?=$bsMonthNep[$dBs['m']-1]??''?> <?=$dBs['d']?>, <?=$nm?>)</span></div>
<?php foreach($mds as $mid=>$md):$mFP=array_sum(array_column($md['rows'],'fp_printqty'));$mTgt=array_sum(array_column($md['rows'],'jtd_targetqty'));$mBal=$mTgt-$mFP;$mJ=count(array_unique(array_filter(array_column($md['rows'],'jt_id'))));?>
<div class="mblk"><div class="mblk-h"><div class="mn"><i class="fa-solid fa-gear" style="color:var(--tx2)"></i> <?=h($md['mn'])?></div>
<div class="mf">FP: <strong><?=fmtQty($mFP)?></strong> / Target: <strong><?=fmtQty($mTgt)?></strong> / Balance: <strong style="color:<?=$mBal>0?'var(--coral)':'var(--green)'?>"><?=fmtQty(abs($mBal))?></strong> <span class="b <?=$mFP>=$mTgt&&$mTgt>0?'b-gr':'b-am'?>"><?=$mJ?> job<?=$mJ>1?'s':''?></span></div></div>
<div class="tw"><table><thead><tr><th>#</th><th>FP actual</th><th>Forma target</th><th>Balance</th><th>Job ticket</th><th>Forma page</th><th>Book code</th><th>Book name</th><th>Operator</th><th>Incharge</th><th>Supervisor</th><th>Shift</th></tr></thead><tbody>
<?php foreach($md['rows'] as $i=>$r):$rb=((int)($r['jtd_targetqty']??0))-((int)($r['fp_printqty']??0));?>
<tr><td style="color:var(--tx2)"><?=$i+1?></td>
<td><strong><?=fmtQty((int)$r['fp_printqty'])?></strong></td>
<td><?=fmtQty((int)($r['jtd_targetqty']??0))?></td>
<td style="color:<?=$rb>0?'var(--coral)':'var(--green)'?>"><?=$rb>0?'-':'+'?><?=fmtQty(abs($rb))?></td>
<td><span class="b b-bl"><?=h($r['job_ticket_code']??'-')?></span></td>
<td><?=h($r['forma_page']??'')?><?php if($r['order_no']??null):?> <span style="color:var(--tx3)">(#<?=h($r['order_no'])?>)</span><?php endif;?></td>
<td><span class="b b-am"><?=h($r['book_code']??'-')?></span></td>
<td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=h($r['book_name']??'')?>"><?=h($r['book_name']??'-')?></td>
<td><?=h($r['operator_name']??'-')?></td><td><?=h($r['incharge_name']??'-')?></td><td><?=h($r['supervisor_name']??'-')?></td>
<td><div class="shift-info"><span class="shift-nm"><?=h(shiftName($r['shift_id']))?></span><?php $st=shiftTime($r['shift_id']);if($st):?><span class="shift-tm"><?=h($st)?></span><?php endif;?></div></td>
</tr><?php endforeach;?></tbody></table></div></div>
<?php endforeach;endforeach;endif;?>


<?php elseif($activeTab==='machine-cal'):?>
<!-- ═══ TAB 2: MACHINE CALENDAR (Nepali BS, all machines) ═══ -->
<form method="get" class="frow">
    <input type="hidden" name="tab" value="machine-cal"><input type="hidden" name="fiscal_year_id" value="<?=$fiscalYearId?>">
    <label>BS year</label><select name="cal_bs_year"><?php for($y=2075;$y<=2090;$y++):?><option value="<?=$y?>" <?=$calBsYear==$y?'selected':''?>><?=$y?></option><?php endfor;?></select>
    <label>BS month</label><select name="cal_bs_month"><?php for($m=1;$m<=12;$m++):?><option value="<?=$m?>" <?=$calBsMonth==$m?'selected':''?>><?=$bsMonthEn[$m-1]?> (<?=$bsMonthNep[$m-1]?>)</option><?php endfor;?></select>
    <label>Search</label><input type="text" name="search" value="<?=h($searchQuery)?>" placeholder="Machine or book..." style="width:120px">
    <button type="submit" class="btn btn-p"><i class="fa-solid fa-filter"></i> Show</button>
    <?php $pM=$calBsMonth-1;$pY=$calBsYear;if($pM<1){$pM=12;$pY--;}$nM=$calBsMonth+1;$nY=$calBsYear;if($nM>12){$nM=1;$nY++;}?>
    <a href="<?=buildUrl('machine-cal',['cal_bs_year'=>$pY,'cal_bs_month'=>$pM])?>" class="btn"><i class="fa-solid fa-chevron-left"></i> Prev</a>
    <a href="<?=buildUrl('machine-cal',['cal_bs_year'=>$nY,'cal_bs_month'=>$nM])?>" class="btn">Next <i class="fa-solid fa-chevron-right"></i></a>
</form>

<div style="font-size:15px;font-weight:800;margin-bottom:10px"><?=$bsMonthNep[$calBsMonth-1]?> <?=$calBsYear?> <span style="color:var(--tx2);font-weight:600;font-size:13px">(<?=$bsMonthEn[$calBsMonth-1]?>)</span></div>

<div class="lgd">
    <span><span class="ld" style="background:var(--blue)"></span>High ≥15k</span>
    <span><span class="ld" style="background:var(--green)"></span>Mid 8-14k</span>
    <span><span class="ld" style="background:var(--amber)"></span>Low &lt;8k</span>
    <span><span class="ld" style="background:var(--bd)"></span>Idle</span>
    <?php foreach($machines as $mi=>$mc):?><span><span class="ld" style="background:<?=$mcColors[$mi%count($mcColors)]?>"></span><?=h($mc['machine_name'])?></span><?php endforeach;?>
</div>

<div class="card"><div class="card-b" style="overflow-x:auto">
<?php $dim=getBsDim($calBsYear,$calBsMonth);$fAd=bsToAd($calBsYear,$calBsMonth,1);$fDow=dow($fAd);$todayAd=date('Y-m-d');?>
<div class="cg"><?php foreach($bsDayEn as $dn):?><div class="chc"><?=$dn?></div><?php endforeach;?></div>
<div style="height:3px"></div>
<div class="cg">
<?php for($i=0;$i<$fDow;$i++):?><div></div><?php endfor;?>
<?php for($d=1;$d<=$dim;$d++):
    $dMs=$calDM[$d]??[];$dFP=0;foreach($dMs as $dm)$dFP+=(int)$dm['total_fp'];
    $cls=$dFP>=15000?'hi':($dFP>=8000?'md':($dFP>0?'lo':'idle'));
    $aD=bsToAd($calBsYear,$calBsMonth,$d);$isT=$aD===$todayAd?'today':'';
?>
<div class="cc <?=$cls?> <?=$isT?>" title="<?=$aD?> / <?=fmtBs($calBsYear,$calBsMonth,$d)?>">
    <div class="dn"><span><?=$d?></span><span class="nep" style="font-size:8px"><?=date('M j',strtotime($aD))?></span></div>
    <?php if($dFP>0):?><div style="font-weight:800;font-size:9px;margin-bottom:1px"><?=fmtQty($dFP)?></div>
    <?php foreach($dMs as $dmId=>$dm):$mI=array_search($dmId,array_column($machines,'id'));$mC=$mcColors[$mI!==false?$mI:0];?>
    <span class="mc-tag" style="background:<?=$mC?>18;color:<?=$mC?>"><?=substr(h($dm['machine_name']),0,6)?> <?=fmtQty((int)$dm['total_fp'])?></span>
    <?php endforeach;else:?><div style="font-size:9px;color:var(--tx3)">—</div><?php endif;?>
</div>
<?php endfor;?>
</div>
</div></div>

<!-- Month summary -->
<div class="card"><div class="card-h"><h3>Month summary — <?=$bsMonthEn[$calBsMonth-1]?> <?=$calBsYear?></h3></div>
<div class="tw"><table><thead><tr><th>Machine</th><th>Total FP</th><th>Active days</th><th>Avg/day</th><th>Jobs</th><th>Books</th></tr></thead><tbody>
<?php $mS=[];foreach($calData as $cd){$mid=$cd['machine_id'];if(!isset($mS[$mid]))$mS[$mid]=['n'=>$cd['machine_name'],'fp'=>0,'days'=>0,'jobs'=>0,'bk'=>[]];$mS[$mid]['fp']+=(int)$cd['total_fp'];$mS[$mid]['days']++;$mS[$mid]['jobs']+=(int)$cd['job_count'];if($cd['book_codes'])foreach(explode(', ',$cd['book_codes'])as $bc)$mS[$mid]['bk'][$bc]=1;}usort($mS,fn($a,$b)=>$b['fp']-$a['fp']);foreach($mS as $ms):$av=$ms['days']>0?round($ms['fp']/$ms['days']):0;?>
<tr><td><strong><?=h($ms['n'])?></strong></td><td><strong><?=fmtQty($ms['fp'])?></strong></td><td><?=$ms['days']?></td><td><?=fmtQty($av)?></td><td><?=$ms['jobs']?></td><td style="font-size:10px"><?=h(implode(', ',array_keys($ms['bk'])))?></td></tr>
<?php endforeach;?></tbody></table></div></div>


<?php elseif($activeTab==='operator'):?>
<!-- ═══ TAB 3: OPERATOR REPORT (with shift name/time, per-hour) ═══ -->
<?php $opTFP=array_sum(array_column($operatorData,'total_fp'));$opC=count(array_unique(array_column($operatorData,'operator_id')));$maxFP=$operatorData?(int)$operatorData[0]['total_fp']:1;?>
<div class="mrow">
    <div class="met"><div class="l">Operators</div><div class="v"><?=$opC?></div></div>
    <div class="met"><div class="l">Total FP</div><div class="v"><?=fmtQty($opTFP)?></div></div>
    <div class="met"><div class="l">Avg/operator</div><div class="v"><?=$opC?fmtQty(round($opTFP/$opC)):'0'?></div></div>
    <div class="met"><div class="l">Jobs</div><div class="v"><?=array_sum(array_column($operatorData,'total_jobs'))?></div></div>
</div>
<div class="r2">
    <div class="card"><div class="card-h"><h3>Output by operator</h3></div><div class="card-b"><div class="chw" style="height:<?=max(180,$opC*32+50)?>px"><canvas id="opC"></canvas></div></div></div>
    <div class="card"><div class="card-h"><h3>Shift distribution</h3></div><div class="card-b"><div class="chw" style="height:220px"><canvas id="opSC"></canvas></div></div></div>
</div>
<div class="card"><div class="card-h"><h3>Operator details — shift & per-hour</h3></div><div class="tw"><table>
<thead><tr><th>Operator</th><th>Shift</th><th>Time</th><th>Machines</th><th>Total FP</th><th>Target</th><th>Jobs</th><th>Days</th><th>Avg/day</th><th>Per hour</th><th>Efficiency</th></tr></thead><tbody>
<?php foreach($operatorData as $op):
    $tgt=(int)($op['total_target']??0);$eff=$tgt>0?round((int)$op['total_fp']/$tgt*100):0;
    $avg=(int)$op['working_days']>0?round((int)$op['total_fp']/(int)$op['working_days']):0;
    $hrs=shiftHours($op['shift_id']);$totalHrs=$hrs*(int)$op['working_days'];$perHr=$totalHrs>0?round((int)$op['total_fp']/$totalHrs):0;
    $badge=$eff>=90?'b-gr':($eff>=70?'b-bl':($eff>=50?'b-am':'b-co'));$pct=$maxFP>0?round((int)$op['total_fp']/$maxFP*100):0;
?>
<tr><td><strong><?=h($op['operator_name'])?></strong></td>
<td><span class="b b-pu"><?=h(shiftName($op['shift_id']))?></span></td>
<td><span class="shift-tm"><?=h(shiftTime($op['shift_id']))?></span></td>
<td style="font-size:10px"><?=h($op['machine_names']??'-')?></td>
<td><strong><?=fmtQty((int)$op['total_fp'])?></strong></td><td><?=fmtQty($tgt)?></td><td><?=$op['total_jobs']?></td><td><?=$op['working_days']?></td>
<td><?=fmtQty($avg)?></td>
<td><span class="per-hr"><?=fmtQty($perHr)?>/hr</span></td>
<td><div class="perf-bar"><div class="perf-track"><div class="perf-fill" style="width:<?=min($pct,100)?>%;background:<?=$pct>=80?'var(--green)':($pct>=50?'var(--amber)':'var(--coral)')?>"></div></div><span class="b <?=$badge?>"><?=$eff?>%</span></div></td>
</tr><?php endforeach;?></tbody></table></div></div>

<!-- Per-shift hourly breakdown -->
<div class="card"><div class="card-h"><h3>Per-shift hourly outcome</h3></div><div class="tw"><table>
<thead><tr><th>Shift</th><th>Time</th><th>Hours</th><th>Total FP</th><th>Operators</th><th>Total shift-hours</th><th>FP per hour</th></tr></thead><tbody>
<?php $shiftAgg=[];foreach($operatorData as $op){$sid=$op['shift_id']??0;if(!isset($shiftAgg[$sid]))$shiftAgg[$sid]=['fp'=>0,'ops'=>[],'days'=>0];$shiftAgg[$sid]['fp']+=(int)$op['total_fp'];$shiftAgg[$sid]['ops'][$op['operator_id']]=1;$shiftAgg[$sid]['days']+=(int)$op['working_days'];}
foreach($shiftAgg as $sid=>$sa):$hrs=shiftHours($sid);$thrs=$hrs*$sa['days'];$ph=$thrs>0?round($sa['fp']/$thrs):0;?>
<tr><td><span class="b b-pu"><?=h(shiftName($sid))?></span></td><td><?=h(shiftTime($sid))?></td><td><?=$hrs?>h</td>
<td><strong><?=fmtQty($sa['fp'])?></strong></td><td><?=count($sa['ops'])?></td><td><?=number_format($thrs)?></td>
<td><span class="per-hr"><?=fmtQty($ph)?>/hr</span></td></tr>
<?php endforeach;?></tbody></table></div></div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    new Chart(document.getElementById('opC'),{type:'bar',data:{labels:<?=json_encode(array_map(fn($o)=>$o['operator_name'],$operatorData))?>,datasets:[{label:'FP',data:<?=json_encode(array_map(fn($o)=>(int)$o['total_fp'],$operatorData))?>,backgroundColor:'#2563eb',borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
    <?php $sFP=[];foreach($operatorData as $op){$sid=$op['shift_id']??0;$sFP[$sid]=($sFP[$sid]??0)+(int)$op['total_fp'];}$sL=[];$sV=[];foreach($sFP as $sid=>$fp){$sL[]=shiftName($sid);$sV[]=$fp;}?>
    new Chart(document.getElementById('opSC'),{type:'doughnut',data:{labels:<?=json_encode($sL)?>,datasets:[{data:<?=json_encode($sV)?>,backgroundColor:['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
});
</script>


<?php elseif($activeTab==='supervisor'):?>
<!-- ═══ TAB 4: SUPERVISOR REPORT ═══ -->
<?php $svT=array_sum(array_column($supervisorData,'total_fp'));$svC=count($supervisorData);$svTm=array_sum(array_column($supervisorData,'team_size'));?>
<div class="mrow">
    <div class="met"><div class="l">Supervisors</div><div class="v"><?=$svC?></div></div>
    <div class="met"><div class="l">Total team</div><div class="v"><?=$svTm?></div></div>
    <div class="met"><div class="l">Total FP</div><div class="v"><?=fmtQty($svT)?></div></div>
    <div class="met"><div class="l">Jobs</div><div class="v"><?=array_sum(array_column($supervisorData,'total_jobs'))?></div></div>
</div>
<div class="card"><div class="card-h"><h3>Team output</h3></div><div class="card-b"><div class="chw" style="height:200px"><canvas id="svC"></canvas></div></div></div>
<div class="card"><div class="card-h"><h3>Supervisor details</h3></div><div class="tw"><table>
<thead><tr><th>Supervisor</th><th>Team</th><th>Machines</th><th>Total FP</th><th>Target</th><th>Jobs</th><th>Days</th><th>Entries</th></tr></thead><tbody>
<?php foreach($supervisorData as $sv):?>
<tr><td><strong><?=h($sv['supervisor_name'])?></strong></td><td><?=$sv['team_size']?></td>
<td><?php foreach(explode(', ',$sv['machine_names']??'')as $mn):?><span class="b b-pu" style="margin:1px"><?=h($mn)?></span><?php endforeach;?></td>
<td><strong><?=fmtQty((int)$sv['total_fp'])?></strong></td><td><?=fmtQty((int)($sv['total_target']??0))?></td>
<td><?=$sv['total_jobs']?></td><td><?=$sv['working_days']?></td><td><?=$sv['total_entries']?></td></tr>
<?php endforeach;?></tbody></table></div></div>
<script>
document.addEventListener('DOMContentLoaded',function(){
    new Chart(document.getElementById('svC'),{type:'bar',data:{labels:<?=json_encode(array_map(fn($s)=>$s['supervisor_name'],$supervisorData))?>,datasets:[{label:'FP',data:<?=json_encode(array_map(fn($s)=>(int)$s['total_fp'],$supervisorData))?>,backgroundColor:['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488'],borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
});
</script>


<?php elseif($activeTab==='shift'):?>
<!-- ═══ TAB 5: MANPOWER & SHIFT (with per-hour outcome) ═══ -->
<?php $shTFP=array_sum(array_column($shiftRD,'total_fp'));$bSh=$shiftRD[0]??null;$mxSh=$bSh?(int)$bSh['total_fp']:1;$shC=['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488'];?>
<div class="mrow">
    <div class="met"><div class="l">Best shift</div><div class="vs"><?=$bSh?h(shiftName($bSh['shift_id'])):'N/A'?></div></div>
    <?php foreach($shiftRD as $sd):?><div class="met"><div class="l"><?=h(shiftName($sd['shift_id']))?></div><div class="v"><?=fmtQty((int)$sd['total_fp'])?></div></div><?php endforeach;?>
</div>
<?php if($bSh):?><div class="ins good"><strong>Top shift:</strong> <?=h(shiftName($bSh['shift_id']))?> (<?=h(shiftTime($bSh['shift_id']))?>) delivers <?=fmtQty((int)$bSh['total_fp'])?> FP — highest output.</div><?php endif;?>
<?php if(count($shiftRD)>1):$w=end($shiftRD);$wP=$mxSh>0?round((int)$w['total_fp']/$mxSh*100):0;?>
<div class="ins warn"><strong>Lowest:</strong> <?=h(shiftName($w['shift_id']))?> output (<?=fmtQty((int)$w['total_fp'])?>) is <?=$wP?>% of top. Review staffing.</div><?php endif;?>

<div class="r2">
    <div class="card"><div class="card-h"><h3>Production by shift</h3></div><div class="card-b"><div class="chw" style="height:200px"><canvas id="shC"></canvas></div></div></div>
    <div class="card"><div class="card-h"><h3>Shift bars</h3></div><div class="card-b">
        <?php foreach($shiftRD as $si=>$sd):$pct=$mxSh>0?round((int)$sd['total_fp']/$mxSh*100):0;?>
        <div class="sbar"><div class="sbar-l"><?=h(shiftName($sd['shift_id']))?></div><div class="sbar-t"><div class="sbar-f" style="width:<?=$pct?>%;background:<?=$shC[$si%count($shC)]?>"><?=fmtQty((int)$sd['total_fp'])?></div></div></div>
        <?php endforeach;?>
    </div></div>
</div>

<!-- Per-hour per shift -->
<div class="card"><div class="card-h"><h3>Per-shift hourly outcome</h3></div><div class="tw"><table>
<thead><tr><th>Shift</th><th>Time</th><th>Hours/shift</th><th>Total FP</th><th>Shift-days</th><th>Total hours</th><th>FP per hour</th><th>Operators</th><th>Machines</th></tr></thead><tbody>
<?php foreach($shiftRD as $sd):$hrs=shiftHours($sd['shift_id']);$tHrs=$hrs*(int)($sd['shift_days']??$sd['entry_count']);$ph=$tHrs>0?round((int)$sd['total_fp']/$tHrs):0;?>
<tr><td><span class="b b-pu"><?=h(shiftName($sd['shift_id']))?></span></td><td><?=h(shiftTime($sd['shift_id']))?></td><td><?=$hrs?>h</td>
<td><strong><?=fmtQty((int)$sd['total_fp'])?></strong></td><td><?=$sd['shift_days']??'-'?></td><td><?=number_format($tHrs)?></td>
<td><span class="per-hr"><?=fmtQty($ph)?>/hr</span></td><td><?=$sd['operator_count']?></td><td><?=$sd['machine_count']?></td></tr>
<?php endforeach;?></tbody></table></div></div>

<div class="card"><div class="card-h"><h3>Manpower allocation</h3></div><div class="tw"><table>
<thead><tr><th>Name</th><th>Role</th><th>Shift</th><th>Machine</th><th>FP</th><th>Entries</th><th>Days</th></tr></thead><tbody>
<?php foreach($manpowerData as $mp):?><tr><td><?=h($mp['username'])?></td><td><span class="b b-te"><?=h($mp['role']??'operator')?></span></td>
<td><span class="b b-pu"><?=h(shiftName($mp['shift_id']))?></span></td><td><span class="b b-bl"><?=h($mp['machine_name']??'-')?></span></td>
<td><strong><?=fmtQty((int)$mp['total_fp'])?></strong></td><td><?=$mp['entries']?></td><td><?=$mp['working_days']?></td></tr>
<?php endforeach;?></tbody></table></div></div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    new Chart(document.getElementById('shC'),{type:'bar',data:{labels:<?=json_encode(array_map(function($sd){return shiftName($sd['shift_id']);},$shiftRD))?>,datasets:[{data:<?=json_encode(array_map(fn($sd)=>(int)$sd['total_fp'],$shiftRD))?>,backgroundColor:<?=json_encode(array_slice($shC,0,count($shiftRD)))?>,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
});
</script>


<?php elseif($activeTab==='trend'):?>
<!-- ═══ TAB 6: MACHINE TREND + ANALYSIS + CALENDAR + SUGGESTIONS ═══ -->
<?php
$rkT=array_sum(array_column($machRank,'total_fp'));$topM=$machRank[0]??null;
$tC=['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488','#be185d','#4f46e5','#ca8a04','#059669'];
$chL=$trendView==='daily'?$trL:$moL;$chS=$trendView==='daily'?$trS:$moS;$legM=array_keys($chS);
// Nepali labels for x-axis
$chNepL=[];if($trendView==='daily'){foreach($chL as $d){$bs=adToBs($d);$chNepL[]=fmtBs($bs['y'],$bs['m'],$bs['d']);}}else{$chNepL=$chL;}
?>
<div class="mrow">
    <div class="met"><div class="l">Top machine</div><div class="vs"><?=h($topM['machine_name']??'N/A')?></div></div>
    <div class="met"><div class="l">Period FP</div><div class="v"><?=fmtQty($rkT)?></div></div>
    <div class="met"><div class="l">Best avg/day</div><div class="v"><?=$topM&&$topM['active_days']>0?fmtQty(round((int)$topM['total_fp']/(int)$topM['active_days'])):'0'?></div></div>
    <div class="met"><div class="l">Machines</div><div class="v"><?=count($machRank)?></div></div>
</div>

<div class="lgd"><?php foreach($legM as $li=>$mn):?><span><span class="ld" style="background:<?=$tC[$li%count($tC)]?>"></span><?=h($mn)?></span><?php endforeach;?></div>

<div class="card"><div class="card-h"><h3>Output comparison — <?=$trendView==='daily'?'day-wise (Nepali date)':'month-wise'?></h3></div>
<div class="card-b"><div class="chw" style="height:300px"><canvas id="trC"></canvas></div></div></div>

<!-- Day-wise production table with Nepali dates -->
<?php if($trendView==='daily'&&count($chL)>0):?>
<div class="card"><div class="card-h"><h3>Date-wise production breakdown</h3></div><div class="tw"><table>
<thead><tr><th>AD date</th><th>BS date</th><th>BS month</th><?php foreach($legM as $mn):?><th><?=h($mn)?></th><?php endforeach;?><th>Day total</th></tr></thead><tbody>
<?php foreach($chL as $di=>$dt):$bs=adToBs($dt);$bsS=fmtBs($bs['y'],$bs['m'],$bs['d']);$dayT=0;?>
<tr><td><?=h($dt)?></td><td class="nep-lbl"><?=$bsS?></td><td class="nep-lbl"><?=$bsMonthNep[$bs['m']-1]??''?> <?=$bs['d']?></td>
<?php foreach($legM as $mn):$v=$chS[$mn][$dt]??0;$dayT+=$v;?><td<?=$v>0?' style="font-weight:700"':' style="color:var(--tx3)"'?>><?=fmtQty($v)?></td><?php endforeach;?>
<td><strong><?=fmtQty($dayT)?></strong></td></tr>
<?php endforeach;?></tbody></table></div></div>
<?php endif;?>

<!-- Production Calendar (mini calendar in trend tab) -->
<?php if(!empty($trendCalData)):
    // Determine BS month range from first/last date
    $fBs=adToBs($trendCalData[0]['date_eng']);$lBs=adToBs(end($trendCalData)['date_eng']);
    $calBsYT=$fBs['y'];$calBsMT=$fBs['m'];$dimT=getBsDim($calBsYT,$calBsMT);
    $fAdT=bsToAd($calBsYT,$calBsMT,1);$fDowT=dow($fAdT);$todayAd=date('Y-m-d');
?>
<div class="card"><div class="card-h"><h3>Production calendar — <?=$bsMonthNep[$calBsMT-1]??''?> <?=$calBsYT?></h3></div>
<div class="card-b">
<div class="cg"><?php foreach($bsDayEn as $dn):?><div class="chc"><?=$dn?></div><?php endforeach;?></div>
<div style="height:3px"></div>
<div class="cg">
<?php for($i=0;$i<$fDowT;$i++):?><div></div><?php endfor;?>
<?php for($d=1;$d<=$dimT;$d++):$aD=bsToAd($calBsYT,$calBsMT,$d);$cd=$tCalMap[$aD]??null;$fp=$cd?(int)$cd['total_fp']:0;
    $cls=$fp>=15000?'hi':($fp>=8000?'md':($fp>0?'lo':'idle'));$isT=$aD===$todayAd?'today':'';
?>
<div class="cc <?=$cls?> <?=$isT?>" title="<?=$aD?>">
    <div class="dn"><span><?=$d?></span><span class="nep"><?=date('M j',strtotime($aD))?></span></div>
    <?php if($fp>0):?><div style="font-weight:800;font-size:9px"><?=fmtQty($fp)?></div>
    <div style="font-size:8px;color:var(--tx2)"><?=$cd['machines']??0?>m <?=$cd['jobs']??0?>j</div>
    <?php else:?><div style="font-size:9px;color:var(--tx3)">—</div><?php endif;?>
</div>
<?php endfor;?>
</div></div></div>
<?php endif;?>

<div class="card"><div class="card-h"><h3>Machine ranking</h3></div><div class="card-b"><div class="chw" style="height:<?=max(160,count($machRank)*38+50)?>px"><canvas id="rkC"></canvas></div></div></div>

<!-- Machine performance table -->
<div class="card"><div class="card-h"><h3>Machine details</h3></div><div class="tw"><table>
<thead><tr><th>#</th><th>Machine</th><th>Total FP</th><th>Target</th><th>Efficiency</th><th>Jobs</th><th>Days</th><th>Operators</th><th>Avg/day</th></tr></thead><tbody>
<?php foreach($machRank as $ri=>$mr):$eff=(int)($mr['total_target']??0)>0?round((int)$mr['total_fp']/(int)$mr['total_target']*100):0;$av=(int)$mr['active_days']>0?round((int)$mr['total_fp']/(int)$mr['active_days']):0;$eB=$eff>=90?'b-gr':($eff>=70?'b-bl':($eff>=50?'b-am':'b-co'));?>
<tr><td style="color:var(--tx2)"><?=$ri+1?></td><td><span class="b b-bl"><?=h($mr['machine_name'])?></span></td>
<td><strong><?=fmtQty((int)$mr['total_fp'])?></strong></td><td><?=fmtQty((int)($mr['total_target']??0))?></td>
<td><span class="b <?=$eB?>"><?=$eff?>%</span></td><td><?=$mr['total_jobs']?></td><td><?=$mr['active_days']?></td><td><?=$mr['operators']?></td><td><?=fmtQty($av)?></td></tr>
<?php endforeach;?></tbody></table></div></div>

<!-- MANAGEMENT SUGGESTIONS -->
<div style="margin-top:14px"><div style="font-size:13px;font-weight:800;margin-bottom:8px"><i class="fa-solid fa-lightbulb" style="color:var(--amber)"></i> Management insights</div>
<?php if(count($machRank)>=2):$tM=$machRank[0];$bM=end($machRank);$tA=(int)$tM['active_days']>0?round((int)$tM['total_fp']/(int)$tM['active_days']):0;$bA=(int)$bM['active_days']>0?round((int)$bM['total_fp']/(int)$bM['active_days']):0;?>
<div class="ins good"><strong>Best:</strong> <?=h($tM['machine_name'])?> — <?=fmtQty((int)$tM['total_fp'])?> FP (avg <?=fmtQty($tA)?>/day). Allocate high-priority jobs here.</div>
<div class="ins bad"><strong>Improve:</strong> <?=h($bM['machine_name'])?> — <?=fmtQty((int)$bM['total_fp'])?> FP (avg <?=fmtQty($bA)?>/day). Check maintenance/downtime.</div>
<?php $avgD=count($machRank)>0?round(array_sum(array_column($machRank,'active_days'))/count($machRank)):0;
foreach($machRank as $mr){if((int)$mr['active_days']<$avgD*0.6&&(int)$mr['active_days']>0)echo'<div class="ins warn"><strong>Underused:</strong> '.h($mr['machine_name']).' active '.$mr['active_days'].' days (avg '.$avgD.'). Increase allocation.</div>';}
endif;?>

<?php // Personnel ranking tables
foreach([['Operator','fa-user-gear','b-bl',$opRank],['Supervisor','fa-user-tie','b-pu',$svRank],['Incharge','fa-user-shield','b-te',$icRank]] as [$rlbl,$rico,$rbdg,$rdata]):if(empty($rdata))continue;?>
<div class="card" style="margin-top:10px"><div class="card-h"><h3><i class="fa-solid <?=$rico?>" style="margin-right:4px"></i><?=$rlbl?> ranking</h3></div>
<div class="tw"><table><thead><tr><th>#</th><th><?=$rlbl?></th><th>FP</th><th>Target</th><th>Eff%</th><th>Jobs</th><th>Days</th><th>Status</th></tr></thead><tbody>
<?php foreach($rdata as $ri=>$pr):$e=(float)($pr['efficiency']??0);$st=$e>=90?'Excellent':($e>=75?'Good':($e>=60?'Average':'Needs improvement'));$sb=$e>=90?'b-gr':($e>=75?'b-bl':($e>=60?'b-am':'b-co'));?>
<tr><td style="color:var(--tx2)"><?=$ri+1?></td><td><strong><?=h($pr['username'])?></strong></td>
<td><strong><?=fmtQty((int)$pr['total_fp'])?></strong></td><td><?=fmtQty((int)($pr['total_target']??0))?></td>
<td><span class="b <?=$sb?>"><?=$e?>%</span></td><td><?=$pr['jobs']?></td><td><?=$pr['days']?></td><td><span class="b <?=$sb?>"><?=$st?></span></td></tr>
<?php endforeach;?></tbody></table></div></div>
<?php $top=$rdata[0]??null;$bot=end($rdata);
if($top):?><div class="ins good"><strong>Best <?=strtolower($rlbl)?>:</strong> <?=h($top['username'])?> — <?=fmtQty((int)$top['total_fp'])?> FP, <?=$top['efficiency']?>% eff.</div><?php endif;
if($bot&&count($rdata)>1&&(float)$bot['efficiency']<70):?><div class="ins bad"><strong><?=$rlbl?> needs support:</strong> <?=h($bot['username'])?> — <?=$bot['efficiency']?>% eff. Consider training/mentoring.</div><?php endif;
endforeach;?>
</div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    const tL=<?=json_encode($chNepL)?>;const tC=<?=json_encode($tC)?>;const ds=[];
    <?php foreach($legM as $li=>$mn):$vs=[];foreach($chL as $l)$vs[]=$chS[$mn][$l]??0;?>
    ds.push({label:<?=json_encode($mn)?>,data:<?=json_encode($vs)?>,borderColor:tC[<?=$li?>%tC.length],backgroundColor:tC[<?=$li?>%tC.length]+'22',tension:.3,fill:false,pointRadius:3});
    <?php endforeach;?>
    new Chart(document.getElementById('trC'),{type:'line',data:{labels:tL,datasets:ds},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
    new Chart(document.getElementById('rkC'),{type:'bar',data:{labels:<?=json_encode(array_map(fn($r)=>$r['machine_name'],$machRank))?>,datasets:[{data:<?=json_encode(array_map(fn($r)=>(int)$r['total_fp'],$machRank))?>,backgroundColor:tC.slice(0,<?=count($machRank)?>),borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
});
</script>


<?php elseif($activeTab==='jt-vs-fp'):?>
<!-- ═══ TAB 7: JT vs FP & BOOK PACKING COMPARISON ═══ -->
<?php
// Summary calculations
$totalJtQty=0;$totalFpAct=0;$totalBpQty=0;$completed=0;$onTrack=0;$behind=0;$notStarted=0;
foreach($jtBpData as $jt){
    $totalJtQty+=(int)$jt['jt_total_qty'];$totalFpAct+=(int)$jt['fp_total'];$totalBpQty+=(int)$jt['bp_total'];
    $pct=(int)$jt['jt_total_qty']>0?round((int)$jt['fp_total']/(int)$jt['jt_total_qty']*100):0;
    if($pct>=100)$completed++;elseif($pct>=50)$onTrack++;elseif($pct>0)$behind++;else $notStarted++;
}
$overallPct=$totalJtQty>0?round($totalFpAct/$totalJtQty*100,1):0;
$bpPct=$totalJtQty>0?round($totalBpQty/$totalJtQty*100,1):0;
$totalJt=count($jtBpData);
?>

<!-- Metrics -->
<div class="mrow">
    <div class="met"><div class="l">Job tickets</div><div class="v"><?=$totalJt?></div></div>
    <div class="met"><div class="l">JT total qty</div><div class="v"><?=fmtQty($totalJtQty)?></div></div>
    <div class="met"><div class="l">FP actual</div><div class="v" style="color:var(--blue)"><?=fmtQty($totalFpAct)?></div></div>
    <div class="met"><div class="l">Book packed</div><div class="v" style="color:var(--green)"><?=fmtQty($totalBpQty)?></div></div>
    <div class="met"><div class="l">FP completion</div><div class="v"><?=$overallPct?>%</div></div>
    <div class="met"><div class="l">BP completion</div><div class="v"><?=$bpPct?>%</div></div>
</div>

<!-- Status cards -->
<div class="mrow" style="grid-template-columns:repeat(4,1fr)">
    <div class="met" style="border-left:3px solid var(--green)"><div class="l">Completed ≥100%</div><div class="v"><?=$completed?></div></div>
    <div class="met" style="border-left:3px solid var(--blue)"><div class="l">On track 50-99%</div><div class="v"><?=$onTrack?></div></div>
    <div class="met" style="border-left:3px solid var(--amber)"><div class="l">Behind &lt;50%</div><div class="v"><?=$behind?></div></div>
    <div class="met" style="border-left:3px solid var(--coral)"><div class="l">Not started</div><div class="v"><?=$notStarted?></div></div>
</div>

<!-- Insights -->
<?php if($completed>0):?><div class="ins good"><strong><?=$completed?> JT<?=$completed>1?'s':''?> completed</strong> — <?=$totalJt>0?round($completed/$totalJt*100):0?>% of all job tickets have met or exceeded print targets.</div><?php endif;?>
<?php if($behind>0):?><div class="ins warn"><strong><?=$behind?> JT<?=$behind>1?'s':''?> behind</strong> — less than 50% of target. Review machine allocation and scheduling.</div><?php endif;?>
<?php if($notStarted>0):?><div class="ins bad"><strong><?=$notStarted?> JT<?=$notStarted>1?'s':''?> not started</strong> — no FP entries yet. Check job readiness and scheduling.</div><?php endif;?>

<!-- Charts -->
<div class="r2">
    <div class="card"><div class="card-h"><h3>Completion status</h3></div><div class="card-b"><div class="chw" style="height:230px"><canvas id="jtStC"></canvas></div></div></div>
    <div class="card"><div class="card-h"><h3>Target vs FP vs BP (top JTs)</h3></div><div class="card-b"><div class="chw" style="height:230px"><canvas id="jtBarC"></canvas></div></div></div>
</div>

<!-- ═══ ABOVE SECTION: JOB TICKET vs BOOK PACKING ═══ -->
<div class="card">
    <div class="card-h"><h3><i class="fa-solid fa-scale-balanced" style="color:var(--blue);margin-right:4px"></i> Job ticket vs Book packing comparison</h3>
    <span style="font-size:10px;color:var(--tx2)"><?=$totalJt?> job tickets</span></div>
    <div class="tw"><table>
        <thead><tr>
            <th>JT code</th><th>Nep date</th><th>Book code</th><th>Book name</th><th>Type</th><th>Lot</th>
            <th>JT target</th><th>FP actual</th><th>FP %</th><th>BP packed</th><th>BP %</th>
            <th>FP balance</th><th>BP balance</th><th>Machines</th><th>JT status</th>
        </tr></thead>
        <tbody>
        <?php foreach($jtBpData as $jt):
            $target=(int)$jt['jt_total_qty'];$fpAct=(int)$jt['fp_total'];$bpAct=(int)$jt['bp_total'];
            $fpBal=$target-$fpAct;$bpBal=$target-$bpAct;
            $fpPct=$target>0?round($fpAct/$target*100,1):0;$bpPctR=$target>0?round($bpAct/$target*100,1):0;
            $fpBar=min($fpPct,100);$bpBar=min($bpPctR,100);
            // Status
            if($fpPct>=100){$stL='Completed';$stB='b-gr';}elseif($fpPct>=75){$stL='Almost';$stB='b-bl';}
            elseif($fpPct>=50){$stL='On track';$stB='b-bl';}elseif($fpPct>0){$stL='Behind';$stB='b-am';}
            else{$stL='Not started';$stB='b-co';}
        ?>
        <tr>
            <td><span class="b b-bl"><?=h($jt['job_ticket_code'])?></span></td>
            <td><span class="nep-lbl"><?=h($jt['jt_date_nep']??'-')?></span></td>
            <td><span class="b b-am"><?=h($jt['book_code']??'-')?></span></td>
            <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=h($jt['book_name']??'')?>"><?=h($jt['book_name']??'-')?></td>
            <td><span class="b b-te"><?=h($jt['book_type']??'-')?></span></td>
            <td><?=h($jt['lot']??'-')?></td>
            <td><strong><?=fmtQty($target)?></strong></td>
            <td><strong style="color:var(--blue)"><?=fmtQty($fpAct)?></strong></td>
            <td style="min-width:100px"><div style="display:flex;align-items:center;gap:4px">
                <div class="comp-bar" style="flex:1;height:12px"><div class="done" style="width:<?=$fpBar?>%"></div></div>
                <span class="pct-t" style="color:var(--blue)"><?=$fpPct?>%</span></div></td>
            <td><strong style="color:var(--green)"><?=fmtQty($bpAct)?></strong></td>
            <td style="min-width:100px"><div style="display:flex;align-items:center;gap:4px">
                <div class="comp-bar" style="flex:1;height:12px"><div style="width:<?=$bpBar?>%;height:100%;border-radius:3px;background:var(--green)"></div></div>
                <span class="pct-t" style="color:var(--green)"><?=$bpPctR?>%</span></div></td>
            <td style="color:<?=$fpBal>0?'var(--coral)':'var(--green)'?>;font-weight:700"><?=$fpBal>0?'-':'+'?><?=fmtQty(abs($fpBal))?></td>
            <td style="color:<?=$bpBal>0?'var(--coral)':'var(--green)'?>;font-weight:700"><?=$bpBal>0?'-':'+'?><?=fmtQty(abs($bpBal))?></td>
            <td style="font-size:10px"><?=h($jt['machine_names']??'-')?></td>
            <td><span class="b <?=$stB?>"><?=$stL?></span></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
</div>

<!-- ═══ BELOW SECTION: FORMA-LEVEL JTD vs FP DETAIL ═══ -->
<div class="card">
    <div class="card-h"><h3><i class="fa-solid fa-layer-group" style="color:var(--purple);margin-right:4px"></i> Forma-level detail — Job ticket details (JTD) vs Forma printing (FP)</h3>
    <span style="font-size:10px;color:var(--tx2)"><?=count($formaComp)?> forma entries</span></div>
    <div class="tw"><table>
        <thead><tr>
            <th>JT code</th><th>Order #</th><th>Forma page</th><th>Book code</th><th>Book name</th>
            <th>JTD target</th><th>FP actual</th><th>Balance</th><th>Completion</th>
            <th>JTD machine</th><th>FP machines</th><th>FP entries</th><th>JTD status</th>
        </tr></thead>
        <tbody>
        <?php
        $prevJt='';
        foreach($formaComp as $fc):
            $target=(int)$fc['jtd_target_qty'];$actual=(int)$fc['fp_actual'];$bal=$target-$actual;
            $pct=$target>0?round($actual/$target*100,1):0;$barPct=min($pct,100);
            $barCol=$pct>=100?'var(--green)':($pct>=50?'var(--blue)':($pct>0?'var(--amber)':'var(--coral)'));
            $isNew=$fc['job_ticket_code']!==$prevJt;$prevJt=$fc['job_ticket_code'];
            // JTD status badge
            $jtdSt=$fc['jtd_status']??'scheduled';
            if (str_contains($jtdSt,'complet')) { $jtdB='b-gr'; }
            elseif (str_contains($jtdSt,'progress') || str_contains($jtdSt,'print')) { $jtdB='b-bl'; }
            elseif (str_contains($jtdSt,'schedul')) { $jtdB='b-pu'; }
            else { $jtdB='b-am'; }
        ?>
        <tr<?=$isNew?' style="border-top:2px solid var(--bd2)"':''?>>
            <td><?php if($isNew):?><span class="b b-bl"><?=h($fc['job_ticket_code'])?></span><?php endif;?></td>
            <td style="color:var(--tx2)"><?=h($fc['order_no'])?></td>
            <td style="font-weight:700"><?=h($fc['forma_page'])?></td>
            <td><span class="b b-am"><?=h($fc['book_code']??'-')?></span></td>
            <td style="max-width:90px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?=h($fc['book_name']??'')?>"><?=h($fc['book_name']??'-')?></td>
            <td><strong><?=fmtQty($target)?></strong></td>
            <td><strong style="color:<?=$actual>0?'var(--blue)':'var(--tx3)' ?>"><?=fmtQty($actual)?></strong></td>
            <td style="color:<?=$bal>0?'var(--coral)':'var(--green)'?>;font-weight:700"><?=$bal>0?'-':'+'?><?=fmtQty(abs($bal))?></td>
            <td style="min-width:110px"><div style="display:flex;align-items:center;gap:4px">
                <div class="comp-bar" style="flex:1;height:11px"><div style="width:<?=$barPct?>%;height:100%;border-radius:3px;background:<?=$barCol?>"></div></div>
                <span class="pct-t"><?=$pct?>%</span></div></td>
            <td style="font-size:10px"><?=h($fc['jtd_machine']??'-')?></td>
            <td style="font-size:10px"><?=h($fc['fp_machines']??'-')?></td>
            <td><?=$fc['fp_count']?></td>
            <td><span class="b <?=$jtdB?>"><?=h($jtdSt)?></span></td>
        </tr>
        <?php endforeach;?>
        </tbody>
    </table></div>
</div>

<!-- Completion by book chart -->
<?php
$bkC=[];foreach($jtBpData as $jt){$bc=$jt['book_code']??'?';if(!isset($bkC[$bc]))$bkC[$bc]=['t'=>0,'fp'=>0,'bp'=>0,'n'=>$jt['book_name']??$bc];
    $bkC[$bc]['t']+=(int)$jt['jt_total_qty'];$bkC[$bc]['fp']+=(int)$jt['fp_total'];$bkC[$bc]['bp']+=(int)$jt['bp_total'];}
uasort($bkC,fn($a,$b)=>$b['t']-$a['t']);$bkC=array_slice($bkC,0,10,true);
?>
<div class="card"><div class="card-h"><h3>Completion by book — Target vs FP vs BP (top 10)</h3></div>
<div class="card-b"><div class="chw" style="height:280px"><canvas id="bkCompC"></canvas></div></div></div>

<script>
document.addEventListener('DOMContentLoaded',function(){
    // Status doughnut
    new Chart(document.getElementById('jtStC'),{type:'doughnut',
        data:{labels:['Completed','On track','Behind','Not started'],
            datasets:[{data:[<?=$completed?>,<?=$onTrack?>,<?=$behind?>,<?=$notStarted?>],
            backgroundColor:['#16a34a','#2563eb','#d97706','#dc2626']}]},
        options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom',labels:{font:{size:10}}}}}
    });

    // Target vs FP vs BP bar
    <?php $bJ=array_slice($jtBpData,0,8);$bL=array_map(fn($j)=>$j['job_ticket_code'],$bJ);
    $bT=array_map(fn($j)=>(int)$j['jt_total_qty'],$bJ);$bF=array_map(fn($j)=>(int)$j['fp_total'],$bJ);$bB=array_map(fn($j)=>(int)$j['bp_total'],$bJ);?>
    new Chart(document.getElementById('jtBarC'),{type:'bar',
        data:{labels:<?=json_encode($bL)?>,datasets:[
            {label:'JT target',data:<?=json_encode($bT)?>,backgroundColor:'#2563eb33',borderColor:'#2563eb',borderWidth:1,borderRadius:4},
            {label:'FP actual',data:<?=json_encode($bF)?>,backgroundColor:'#2563eb',borderRadius:4},
            {label:'BP packed',data:<?=json_encode($bB)?>,backgroundColor:'#16a34a',borderRadius:4}
        ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{font:{size:10}}}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}
    });

    // Book completion
    <?php $bl=[];$bt=[];$bf=[];$bb=[];foreach($bkC as $bc=>$bk){$bl[]=$bc;$bt[]=$bk['t'];$bf[]=$bk['fp'];$bb[]=$bk['bp'];}?>
    new Chart(document.getElementById('bkCompC'),{type:'bar',
        data:{labels:<?=json_encode($bl)?>,datasets:[
            {label:'Target',data:<?=json_encode($bt)?>,backgroundColor:'#94a3b844',borderColor:'#64748b',borderWidth:1,borderRadius:4},
            {label:'FP actual',data:<?=json_encode($bf)?>,backgroundColor:'#2563eb',borderRadius:4},
            {label:'BP packed',data:<?=json_encode($bb)?>,backgroundColor:'#16a34a',borderRadius:4}
        ]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'top',labels:{font:{size:10}}}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}
    });
});
</script>

<?php endif;?>

<!-- ═══ AUTO-FILL NEPALI DATES JS ═══ -->
<script>
// Minimal BS↔AD converter for filter auto-fill
(function(){
const bsMD={2080:[31,32,31,32,31,30,30,30,29,30,29,31],2081:[31,31,32,31,31,31,30,29,30,29,30,30],2082:[31,31,32,32,31,30,30,29,30,29,30,30],2083:[31,32,31,32,31,30,30,30,29,29,30,31],2084:[31,31,32,31,31,31,30,29,30,29,30,30],2085:[31,31,32,32,31,30,30,29,30,29,30,30],2086:[31,32,31,32,31,30,30,30,29,29,30,31],2087:[31,31,32,31,31,31,30,29,30,29,30,30],2088:[31,31,32,32,31,30,30,29,30,29,30,30],2089:[31,32,31,32,31,30,30,30,29,29,30,31],2090:[31,31,32,31,31,31,30,29,30,29,30,30]};
const refAd=new Date(2023,3,14);// 2080-01-01 BS
function adToBs(ad){const t=new Date(ad);let diff=Math.round((t-refAd)/864e5);let y=2080,m=0,d=1;
    if(diff>=0){while(diff>0){const dm=bsMD[y]?bsMD[y][m]:30;const dl=dm-d;
    if(diff<=dl){d+=diff;diff=0;}else{diff-=(dl+1);m++;d=1;if(m>11){m=0;y++;}}}};
    return{y,m:m+1,d};}
function bsToAd(by,bm,bd){let td=0;for(let y=2080;y<by;y++){const md=bsMD[y];td+=md?md.reduce((a,b)=>a+b,0):365;}
    const md=bsMD[by];if(md)for(let i=0;i<bm-1;i++)td+=md[i];td+=(bd-1);return new Date(refAd.getTime()+td*864e5);}
function pad(n){return n<10?'0'+n:''+n;}
function fmtBs(o){return o.y+'.'+pad(o.m)+'.'+pad(o.d);}

// Auto-fill nepali dates when AD dates exist on page load
const dfEl=document.querySelector('[name="date_from"]');const dtEl=document.querySelector('[name="date_to"]');
const nfEl=document.querySelector('[name="nep_from"]');const ntEl=document.querySelector('[name="nep_to"]');
if(dfEl&&nfEl&&!nfEl.value&&dfEl.value){try{const bs=adToBs(dfEl.value);nfEl.value=fmtBs(bs);}catch(e){}}
if(dtEl&&ntEl&&!ntEl.value&&dtEl.value){try{const bs=adToBs(dtEl.value);ntEl.value=fmtBs(bs);}catch(e){}}

// Sync: when AD changes, auto-fill BS
if(dfEl)dfEl.addEventListener('change',function(){try{const bs=adToBs(this.value);if(nfEl)nfEl.value=fmtBs(bs);}catch(e){}});
if(dtEl)dtEl.addEventListener('change',function(){try{const bs=adToBs(this.value);if(ntEl)ntEl.value=fmtBs(bs);}catch(e){}});
// Sync: when BS changes, auto-fill AD
if(nfEl)nfEl.addEventListener('change',function(){try{const p=this.value.match(/^(\d{4})[.\-](\d{1,2})[.\-](\d{1,2})$/);
    if(p&&dfEl){const ad=bsToAd(+p[1],+p[2],+p[3]);dfEl.value=ad.getFullYear()+'-'+pad(ad.getMonth()+1)+'-'+pad(ad.getDate());}}catch(e){}});
if(ntEl)ntEl.addEventListener('change',function(){try{const p=this.value.match(/^(\d{4})[.\-](\d{1,2})[.\-](\d{1,2})$/);
    if(p&&dtEl){const ad=bsToAd(+p[1],+p[2],+p[3]);dtEl.value=ad.getFullYear()+'-'+pad(ad.getMonth()+1)+'-'+pad(ad.getDate());}}catch(e){}});
})();
</script>

</div>
</body>
</html>

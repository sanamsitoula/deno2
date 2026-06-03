<?php
/**
 * Production Reporting Suite — Complete
 * ═════════════════════════════════════════════════════════════
 * Tabs:
 *  1. Daily Production Report (FP per date/machine, JT forma qty, actual-target-balance, shift time, operator/incharge/supervisor)
 *  2. Machine Calendar Report (all machines on Nepali calendar, date-wise)
 *  3. Operator Work Report
 *  4. Supervisor Work Report
 *  5. Manpower & Shift Report
 *  6. Machine Trend Report (day/month/year comparison, suggestions)
 *  7. Job Ticket vs FP Comparison Report
 *
 * Tables: forma_printing, job_ticket, job_ticket_details, books, machines, shifts, users, book_packing, deno, d2m, fiscal_years
 */

// ─── DB CONFIG ─────────────────────────────────────────────
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') &&
    !has_role('supervisor') && !has_role('admin') && !has_role('press') && !has_role('marketing')) {
    ob_end_clean(); header('Location: /deno2/unauthorized.php'); exit();
}

// ─── HELPERS ───────────────────────────────────────────────
function fmtQty($n) {
    if ($n === null) return '0';
    $n = (int)$n;
    if ($n >= 100000) return round($n / 1000) . 'k';
    if ($n >= 10000)  return number_format($n / 1000, 1) . 'k';
    return number_format($n);
}

function h($s) { return htmlspecialchars($s ?? '', ENT_QUOTES, 'UTF-8'); }

// ─── NEPALI DATE UTILITIES ─────────────────────────────────
// BS month days for years 2080-2090
$bsMonthDays = [
    2080 => [31,32,31,32,31,30,30,30,29,30,29,31],
    2081 => [31,31,32,31,31,31,30,29,30,29,30,30],
    2082 => [31,31,32,32,31,30,30,29,30,29,30,30],
    2083 => [31,32,31,32,31,30,30,30,29,29,30,31],
    2084 => [31,31,32,31,31,31,30,29,30,29,30,30],
    2085 => [31,31,32,32,31,30,30,29,30,29,30,30],
    2086 => [31,32,31,32,31,30,30,30,29,29,30,31],
    2087 => [31,31,32,31,31,31,30,29,30,29,30,30],
    2088 => [31,31,32,32,31,30,30,29,30,29,30,30],
    2089 => [31,32,31,32,31,30,30,30,29,29,30,31],
    2090 => [31,31,32,31,31,31,30,29,30,29,30,30],
];

$bsMonthNames = ['बैशाख','जेष्ठ','आषाढ','श्रावण','भाद्र','आश्विन','कार्तिक','मंसिर','पौष','माघ','फाल्गुन','चैत्र'];
$bsMonthNamesEn = ['Baisakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin','Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
$bsDayNames = ['आइत','सोम','मंगल','बुध','बिही','शुक्र','शनि'];
$bsDayNamesEn = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];

// Reference: 2080-01-01 BS = 2023-04-14 AD (Friday = day 5)
function bsToAd($bsY, $bsM, $bsD) {
    global $bsMonthDays;
    // Reference point: BS 2080-01-01 = AD 2023-04-14
    $refBsY = 2080; $refBsM = 1; $refBsD = 1;
    $refAd = mktime(0,0,0,4,14,2023);
    
    $totalDays = 0;
    // Count days from reference to target
    for ($y = $refBsY; $y < $bsY; $y++) {
        if (isset($bsMonthDays[$y])) {
            $totalDays += array_sum($bsMonthDays[$y]);
        } else {
            $totalDays += 365; // fallback
        }
    }
    if (isset($bsMonthDays[$bsY])) {
        for ($m = 1; $m < $bsM; $m++) {
            $totalDays += $bsMonthDays[$bsY][$m - 1];
        }
    }
    $totalDays += ($bsD - 1);
    
    return date('Y-m-d', $refAd + ($totalDays * 86400));
}

function adToBs($adDate) {
    global $bsMonthDays;
    $refAd = mktime(0,0,0,4,14,2023);
    $targetAd = strtotime($adDate);
    if (!$targetAd) return ['y'=>2082,'m'=>1,'d'=>1];
    
    $daysDiff = (int)round(($targetAd - $refAd) / 86400);
    $bsY = 2080; $bsM = 1; $bsD = 1;
    
    if ($daysDiff >= 0) {
        while ($daysDiff > 0) {
            $daysInMonth = isset($bsMonthDays[$bsY]) ? $bsMonthDays[$bsY][$bsM - 1] : 30;
            $daysLeft = $daysInMonth - $bsD;
            if ($daysDiff <= $daysLeft) {
                $bsD += $daysDiff;
                $daysDiff = 0;
            } else {
                $daysDiff -= ($daysLeft + 1);
                $bsM++;
                $bsD = 1;
                if ($bsM > 12) { $bsM = 1; $bsY++; }
            }
        }
    }
    return ['y' => $bsY, 'm' => $bsM, 'd' => $bsD];
}

function formatBsDate($bsY, $bsM, $bsD) {
    return sprintf('%04d.%02d.%02d', $bsY, $bsM, $bsD);
}

function getBsDaysInMonth($bsY, $bsM) {
    global $bsMonthDays;
    return isset($bsMonthDays[$bsY]) ? ($bsMonthDays[$bsY][$bsM - 1] ?? 30) : 30;
}

function getDayOfWeek($adDate) {
    return (int)date('w', strtotime($adDate)); // 0=Sun
}


// ─── CURRENT FILTERS ─────────────────────────────────────
$activeTab     = $_GET['tab']           ?? 'daily';
$filterDateFrom= $_GET['date_from']     ?? date('Y-m-d');
$filterDateTo  = $_GET['date_to']       ?? date('Y-m-d');
$nepDateFrom   = $_GET['nep_from']      ?? '';
$nepDateTo     = $_GET['nep_to']        ?? '';
$filterMachine = $_GET['machine_id']    ?? 'all';
$calBsYear     = $_GET['cal_bs_year']   ?? '';
$calBsMonth    = $_GET['cal_bs_month']  ?? '';
$trendView     = $_GET['trend_view']    ?? 'daily';
$fiscalYearId  = $_GET['fiscal_year_id'] ?? '';

// Get active fiscal year
if (!$fiscalYearId) {
    $stmt = $conn->query("SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1");
    $fy = $stmt->fetch();
    $fiscalYearId = $fy ? $fy['id'] : 1;
}

// Set default BS year/month from today
if (!$calBsYear || !$calBsMonth) {
    $todayBs = adToBs(date('Y-m-d'));
    $calBsYear = $calBsYear ?: $todayBs['y'];
    $calBsMonth = $calBsMonth ?: $todayBs['m'];
}

// Convert nep dates to AD if provided
if ($nepDateFrom && preg_match('/^(\d{4})\.(\d{2})\.(\d{2})$/', $nepDateFrom, $m)) {
    $filterDateFrom = bsToAd((int)$m[1], (int)$m[2], (int)$m[3]);
}
if ($nepDateTo && preg_match('/^(\d{4})\.(\d{2})\.(\d{2})$/', $nepDateTo, $m)) {
    $filterDateTo = bsToAd((int)$m[1], (int)$m[2], (int)$m[3]);
}

// ─── LOAD LOOKUP DATA ────────────────────────────────────
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY id")->fetchAll();

$shifts = [];
try {
    $shifts = $conn->query("SELECT * FROM shifts ORDER BY id")->fetchAll();
} catch(Exception $e) { /* shifts table might not exist */ }
$shiftMap = [];
foreach ($shifts as $s) { $shiftMap[$s['id']] = $s; }

$fiscalYears = $conn->query("SELECT id, fiscal_code, fiscal_name, is_active FROM fiscal_years ORDER BY id DESC")->fetchAll();

// Machine color map
$machineColors = ['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488','#be185d','#4f46e5','#ca8a04','#059669'];

// Helper to get shift display name
function getShiftName($shiftId, $shiftMap) {
    if (!$shiftId || !isset($shiftMap[$shiftId])) return '-';
    $s = $shiftMap[$shiftId];
    // Try shift_name, shift_time, or build from fields
    return $s['shift_name'] ?? $s['name'] ?? ('Shift ' . $shiftId);
}

function getShiftTime($shiftId, $shiftMap) {
    if (!$shiftId || !isset($shiftMap[$shiftId])) return '';
    $s = $shiftMap[$shiftId];
    if (isset($s['start_time']) && isset($s['end_time'])) {
        return $s['start_time'] . ' to ' . $s['end_time'];
    }
    return $s['shift_time'] ?? '';
}


// ═══════════════════════════════════════════════════════════
// DATA QUERIES
// ═══════════════════════════════════════════════════════════

// --- DAILY PRODUCTION ---
function getDailyData($conn, $dateFrom, $dateTo, $machineId, $fyId) {
    $sql = "
        SELECT 
            fp.id, fp.name AS fp_name, fp.date_nep, fp.date_eng,
            fp.fp_printqty, fp.fp_remainqty,
            fp.jtd_targetqty,
            fp.remarks,
            m.id AS machine_id, m.machine_name,
            s.id AS shift_id,
            jt.id AS jt_id, jt.job_ticket_code, jt.print_qty AS jt_print_qty, jt.lot,
            jt.print_done_qty AS jt_done_qty,
            jtd.id AS jtd_id, jtd.order_no, jtd.page AS forma_page, jtd.print_qty AS jtd_print_qty,
            b.book_code, b.book_name, b.class_level, b.book_type,
            u_op.username AS operator_name,
            u_sv.username AS supervisor_name,
            u_ic.username AS incharge_name
        FROM forma_printing fp
        LEFT JOIN machines m         ON fp.machine_id = m.id
        LEFT JOIN shifts s           ON fp.shift_id = s.id
        LEFT JOIN job_ticket jt      ON fp.jt_id = jt.id
        LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
        LEFT JOIN books b            ON jt.book_id = b.book_id
        LEFT JOIN users u_op         ON fp.operator_id = u_op.id
        LEFT JOIN users u_sv         ON fp.supervisor_id = u_sv.id
        LEFT JOIN users u_ic         ON fp.incharge_id = u_ic.id
        WHERE fp.status = true
          AND fp.fiscal_year_id = :fy
          AND (fp.date_eng >= :df AND fp.date_eng <= :dt)
    ";
    $params = [':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo];
    if ($machineId !== 'all') {
        $sql .= " AND fp.machine_id = :mid";
        $params[':mid'] = $machineId;
    }
    $sql .= " ORDER BY fp.date_eng, m.machine_name, jt.job_ticket_code";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    return $stmt->fetchAll();
}

// --- MACHINE CALENDAR (all machines, BS month) ---
function getMachineCalData($conn, $bsYear, $bsMonth, $fyId) {
    global $bsMonthDays;
    $daysInMonth = getBsDaysInMonth($bsYear, $bsMonth);
    $startAd = bsToAd($bsYear, $bsMonth, 1);
    $endAd   = bsToAd($bsYear, $bsMonth, $daysInMonth);

    $sql = "
        SELECT 
            fp.date_eng, fp.date_nep,
            m.id AS machine_id, m.machine_name,
            SUM(fp.fp_printqty) AS total_fp,
            COUNT(fp.id) AS entry_count,
            COUNT(DISTINCT fp.jt_id) AS job_count
        FROM forma_printing fp
        JOIN machines m ON fp.machine_id = m.id
        WHERE fp.status = true
          AND fp.fiscal_year_id = :fy
          AND fp.date_eng >= :start AND fp.date_eng <= :end
        GROUP BY fp.date_eng, fp.date_nep, m.id, m.machine_name
        ORDER BY fp.date_eng, m.machine_name
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':start' => $startAd, ':end' => $endAd]);
    return $stmt->fetchAll();
}

// --- OPERATOR REPORT ---
function getOperatorData($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT 
            u.id AS operator_id, u.username AS operator_name,
            m.machine_name,
            s.id AS shift_id,
            COUNT(fp.id) AS total_entries,
            SUM(fp.fp_printqty) AS total_fp,
            SUM(fp.jtd_targetqty) AS total_target,
            COUNT(DISTINCT fp.jt_id) AS total_jobs,
            COUNT(DISTINCT fp.date_eng) AS working_days
        FROM forma_printing fp
        JOIN users u         ON fp.operator_id = u.id
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN shifts s   ON fp.shift_id = s.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
          AND fp.date_eng >= :df AND fp.date_eng <= :dt
        GROUP BY u.id, u.username, m.machine_name, s.id
        ORDER BY total_fp DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}

// --- SUPERVISOR REPORT ---
function getSupervisorData($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT 
            u.id AS supervisor_id, u.username AS supervisor_name,
            COUNT(DISTINCT fp.operator_id) AS team_size,
            COUNT(DISTINCT fp.machine_id) AS machines_count,
            STRING_AGG(DISTINCT m.machine_name, ', ') AS machine_names,
            SUM(fp.fp_printqty) AS total_fp,
            SUM(fp.jtd_targetqty) AS total_target,
            COUNT(DISTINCT fp.jt_id) AS total_jobs,
            COUNT(DISTINCT fp.date_eng) AS working_days,
            COUNT(fp.id) AS total_entries
        FROM forma_printing fp
        JOIN users u         ON fp.supervisor_id = u.id
        LEFT JOIN machines m ON fp.machine_id = m.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
          AND fp.date_eng >= :df AND fp.date_eng <= :dt
        GROUP BY u.id, u.username
        ORDER BY total_fp DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}

// --- SHIFT REPORT ---
function getShiftData($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT 
            s.id AS shift_id,
            SUM(fp.fp_printqty) AS total_fp,
            COUNT(DISTINCT fp.operator_id) AS operator_count,
            COUNT(DISTINCT fp.machine_id) AS machine_count,
            COUNT(DISTINCT fp.jt_id) AS job_count,
            COUNT(fp.id) AS entry_count
        FROM forma_printing fp
        LEFT JOIN shifts s ON fp.shift_id = s.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
          AND fp.date_eng >= :df AND fp.date_eng <= :dt
        GROUP BY s.id ORDER BY total_fp DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}

function getManpowerData($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT 
            u.id AS user_id, u.username, u.role,
            s.id AS shift_id,
            m.machine_name,
            SUM(fp.fp_printqty) AS total_fp,
            COUNT(fp.id) AS entries,
            COUNT(DISTINCT fp.date_eng) AS working_days
        FROM forma_printing fp
        JOIN users u         ON fp.operator_id = u.id
        LEFT JOIN shifts s   ON fp.shift_id = s.id
        LEFT JOIN machines m ON fp.machine_id = m.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
          AND fp.date_eng >= :df AND fp.date_eng <= :dt
        GROUP BY u.id, u.username, u.role, s.id, m.machine_name
        ORDER BY s.id, total_fp DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}

// --- MACHINE TREND ---
function getTrendDaily($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT fp.date_eng, fp.date_nep, m.id AS machine_id, m.machine_name,
            SUM(fp.fp_printqty) AS total_fp
        FROM forma_printing fp
        JOIN machines m ON fp.machine_id = m.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
          AND fp.date_eng >= :df AND fp.date_eng <= :dt
        GROUP BY fp.date_eng, fp.date_nep, m.id, m.machine_name
        ORDER BY fp.date_eng, m.machine_name
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}

function getTrendMonthly($conn, $fyId) {
    $sql = "
        SELECT SUBSTRING(fp.date_eng FROM 1 FOR 7) AS month,
            m.id AS machine_id, m.machine_name,
            SUM(fp.fp_printqty) AS total_fp,
            COUNT(DISTINCT fp.date_eng) AS active_days,
            COUNT(DISTINCT fp.jt_id) AS job_count
        FROM forma_printing fp
        JOIN machines m ON fp.machine_id = m.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
        GROUP BY month, m.id, m.machine_name
        ORDER BY month, m.machine_name
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId]);
    return $stmt->fetchAll();
}

function getMachineRanking($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT m.id AS machine_id, m.machine_name,
            SUM(fp.fp_printqty) AS total_fp,
            SUM(fp.jtd_targetqty) AS total_target,
            COUNT(DISTINCT fp.jt_id) AS total_jobs,
            COUNT(DISTINCT fp.date_eng) AS active_days,
            COUNT(DISTINCT fp.operator_id) AS operators
        FROM forma_printing fp
        JOIN machines m ON fp.machine_id = m.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
          AND fp.date_eng >= :df AND fp.date_eng <= :dt
        GROUP BY m.id, m.machine_name ORDER BY total_fp DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}

// --- OPERATOR/SUPERVISOR/INCHARGE RANKING for suggestions ---
function getPersonnelRanking($conn, $fyId, $role = 'operator') {
    $col = $role . '_id';
    $sql = "
        SELECT u.id, u.username,
            SUM(fp.fp_printqty) AS total_fp,
            SUM(fp.jtd_targetqty) AS total_target,
            COUNT(fp.id) AS entries,
            COUNT(DISTINCT fp.date_eng) AS days,
            COUNT(DISTINCT fp.jt_id) AS jobs,
            CASE WHEN SUM(fp.jtd_targetqty) > 0 
                 THEN ROUND(SUM(fp.fp_printqty)::numeric / SUM(fp.jtd_targetqty) * 100, 1) 
                 ELSE 0 END AS efficiency
        FROM forma_printing fp
        JOIN users u ON fp.{$col} = u.id
        WHERE fp.status = true AND fp.fiscal_year_id = :fy
        GROUP BY u.id, u.username ORDER BY total_fp DESC
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId]);
    return $stmt->fetchAll();
}

// --- JOB TICKET VS FP COMPARISON ---
function getJtVsFpData($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT 
            jt.id AS jt_id, jt.job_ticket_code, jt.print_qty AS jt_total_qty,
            jt.print_done_qty AS jt_done_qty, jt.page_qty, jt.lot, jt.status AS jt_status,
            jt.date_nep AS jt_date_nep, jt.date_eng AS jt_date_eng,
            b.book_code, b.book_name, b.class_level, b.book_type,
            COALESCE(SUM(fp.fp_printqty), 0) AS fp_total,
            COUNT(fp.id) AS fp_entries,
            COUNT(DISTINCT fp.machine_id) AS machines_used,
            COUNT(DISTINCT fp.operator_id) AS operators_used,
            COUNT(DISTINCT fp.date_eng) AS fp_days,
            STRING_AGG(DISTINCT m.machine_name, ', ') AS machine_names
        FROM job_ticket jt
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN forma_printing fp ON fp.jt_id = jt.id AND fp.status = true
        LEFT JOIN machines m ON fp.machine_id = m.id
        WHERE jt.fiscal_year_id = :fy
          AND (jt.date_eng >= :df AND jt.date_eng <= :dt)
        GROUP BY jt.id, jt.job_ticket_code, jt.print_qty, jt.print_done_qty, 
                 jt.page_qty, jt.lot, jt.status, jt.date_nep, jt.date_eng,
                 b.book_code, b.book_name, b.class_level, b.book_type
        ORDER BY jt.date_eng DESC, jt.job_ticket_code
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}

function getJtDetailComparison($conn, $dateFrom, $dateTo, $fyId) {
    $sql = "
        SELECT 
            jt.job_ticket_code,
            jtd.id AS jtd_id, jtd.order_no, jtd.page AS forma_page,
            jtd.print_qty AS jtd_target_qty, jtd.status AS jtd_status, jtd.machine,
            b.book_code, b.book_name,
            COALESCE(SUM(fp.fp_printqty), 0) AS fp_actual,
            COUNT(fp.id) AS fp_count
        FROM job_ticket jt
        JOIN job_ticket_details jtd ON jtd.job_ticket_id = jt.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = true
        WHERE jt.fiscal_year_id = :fy
          AND (jt.date_eng >= :df AND jt.date_eng <= :dt)
        GROUP BY jt.job_ticket_code, jtd.id, jtd.order_no, jtd.page, jtd.print_qty, 
                 jtd.status, jtd.machine, b.book_code, b.book_name
        ORDER BY jt.job_ticket_code, jtd.order_no
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute([':fy' => $fyId, ':df' => $dateFrom, ':dt' => $dateTo]);
    return $stmt->fetchAll();
}


// ─── FETCH DATA FOR ACTIVE TAB ──────────────────────────
$dailyData = $calData = $operatorData = $supervisorData = [];
$shiftReportData = $manpowerData = $trendDData = $trendMData = [];
$machineRankData = $jtVsFpData = $jtDetailData = [];
$opRank = $svRank = $icRank = [];

switch ($activeTab) {
    case 'daily':
        $dailyData = getDailyData($conn, $filterDateFrom, $filterDateTo, $filterMachine, $fiscalYearId);
        break;
    case 'machine-cal':
        $calData = getMachineCalData($conn, $calBsYear, $calBsMonth, $fiscalYearId);
        break;
    case 'operator':
        $operatorData = getOperatorData($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        break;
    case 'supervisor':
        $supervisorData = getSupervisorData($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        break;
    case 'shift':
        $shiftReportData = getShiftData($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        $manpowerData    = getManpowerData($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        break;
    case 'trend':
        $trendDData      = getTrendDaily($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        $trendMData      = getTrendMonthly($conn, $fiscalYearId);
        $machineRankData = getMachineRanking($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        $opRank  = getPersonnelRanking($conn, $fiscalYearId, 'operator');
        $svRank  = getPersonnelRanking($conn, $fiscalYearId, 'supervisor');
        $icRank  = getPersonnelRanking($conn, $fiscalYearId, 'incharge');
        break;
    case 'jt-vs-fp':
        $jtVsFpData  = getJtVsFpData($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        $jtDetailData= getJtDetailComparison($conn, $filterDateFrom, $filterDateTo, $fiscalYearId);
        break;
}

// Group daily data by date then machine
$dailyGrouped = [];
foreach ($dailyData as $row) {
    $d = $row['date_eng'];
    $mid = $row['machine_id'] ?? 'none';
    $dailyGrouped[$d][$mid]['machine_name'] = $row['machine_name'] ?? 'Unknown';
    $dailyGrouped[$d][$mid]['rows'][] = $row;
}

// Calendar: index by BS day and machine
$calByDayMachine = []; // [dayNum][machineId] => data
foreach ($calData as $row) {
    // Convert eng date to BS to get day number
    $bs = adToBs($row['date_eng']);
    if ($bs['y'] == $calBsYear && $bs['m'] == $calBsMonth) {
        $calByDayMachine[$bs['d']][$row['machine_id']] = $row;
    }
}

// Trend pivot
$trendLabels = []; $trendSeries = [];
foreach ($trendDData as $r) {
    if (!in_array($r['date_eng'], $trendLabels)) $trendLabels[] = $r['date_eng'];
    $trendSeries[$r['machine_name']][$r['date_eng']] = (int)$r['total_fp'];
}
$monthLabels = []; $monthSeries = [];
foreach ($trendMData as $r) {
    if (!in_array($r['month'], $monthLabels)) $monthLabels[] = $r['month'];
    $monthSeries[$r['machine_name']][$r['month']] = (int)$r['total_fp'];
}

// Get BS dates for display
$fromBs = adToBs($filterDateFrom);
$toBs   = adToBs($filterDateTo);
$fromBsStr = formatBsDate($fromBs['y'], $fromBs['m'], $fromBs['d']);
$toBsStr   = formatBsDate($toBs['y'], $toBs['m'], $toBs['d']);

// Build URL helper
function buildUrl($tab, $extra = []) {
    global $fiscalYearId, $filterDateFrom, $filterDateTo, $filterMachine, $calBsYear, $calBsMonth, $trendView;
    $params = array_merge([
        'tab'            => $tab,
        'fiscal_year_id' => $fiscalYearId,
        'date_from'      => $filterDateFrom,
        'date_to'        => $filterDateTo,
        'machine_id'     => $filterMachine,
        'cal_bs_year'    => $calBsYear,
        'cal_bs_month'   => $calBsMonth,
        'trend_view'     => $trendView,
    ], $extra);
    return '?' . http_build_query($params);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Production Reports</title>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.5.1/css/all.min.css">
<script src="https://cdnjs.cloudflare.com/ajax/libs/Chart.js/4.4.1/chart.umd.js"></script>
<style>
:root{--bg:#fff;--bg2:#f8f9fa;--bg3:#f1f3f5;--tx:#1a1a2e;--tx2:#6c757d;--tx3:#adb5bd;--bd:#e9ecef;--bd2:#dee2e6;--blue:#2563eb;--blue-bg:#eff6ff;--blue-dk:#1e40af;--green:#16a34a;--green-bg:#f0fdf4;--green-dk:#166534;--amber:#d97706;--amber-bg:#fffbeb;--amber-dk:#92400e;--coral:#dc2626;--coral-bg:#fef2f2;--coral-dk:#991b1b;--purple:#7c3aed;--purple-bg:#f5f3ff;--purple-dk:#5b21b6;--teal:#0d9488;--teal-bg:#f0fdfa;--teal-dk:#115e59;--r:8px;--rl:12px}
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:'Segoe UI',system-ui,sans-serif;font-size:14px;color:var(--tx);background:var(--bg2);line-height:1.5}
.wrap{max-width:1280px;margin:0 auto;padding:16px}
.hdr{display:flex;align-items:center;justify-content:space-between;flex-wrap:wrap;gap:10px;margin-bottom:16px}
.hdr h1{font-size:18px;font-weight:700}.hdr .sub{font-size:12px;color:var(--tx2)}
.btn{display:inline-flex;align-items:center;gap:5px;padding:6px 14px;font-size:12px;border:1px solid var(--bd2);border-radius:var(--r);cursor:pointer;background:var(--bg);color:var(--tx);text-decoration:none;transition:.15s}
.btn:hover{background:var(--bg3)}.btn-p{background:var(--blue);color:#fff;border-color:var(--blue)}.btn-p:hover{background:var(--blue-dk)}
.tabs{display:flex;gap:2px;border-bottom:2px solid var(--bd);margin-bottom:20px;flex-wrap:wrap;overflow-x:auto}
.tabs a{padding:8px 13px;font-size:12px;color:var(--tx2);text-decoration:none;border-bottom:2px solid transparent;margin-bottom:-2px;white-space:nowrap;transition:.15s}
.tabs a:hover{color:var(--tx);background:var(--bg3)}
.tabs a.act{color:var(--blue);border-bottom-color:var(--blue);font-weight:600}
.frow{display:flex;gap:8px;flex-wrap:wrap;align-items:center;margin-bottom:16px}
.frow label{font-size:12px;color:var(--tx2);font-weight:600}
.frow select,.frow input{padding:6px 10px;border:1px solid var(--bd2);border-radius:var(--r);font-size:12px;background:var(--bg);color:var(--tx)}
.mrow{display:grid;grid-template-columns:repeat(auto-fit,minmax(130px,1fr));gap:10px;margin-bottom:16px}
.met{background:var(--bg);border:1px solid var(--bd);border-radius:var(--rl);padding:12px 14px}
.met .l{font-size:11px;color:var(--tx2);margin-bottom:3px;text-transform:uppercase;letter-spacing:.5px}
.met .v{font-size:20px;font-weight:700}.met .vs{font-size:14px;font-weight:600}
.card{background:var(--bg);border:1px solid var(--bd);border-radius:var(--rl);margin-bottom:14px;overflow:hidden}
.card-h{padding:10px 16px;border-bottom:1px solid var(--bd);display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.card-h h3{font-size:13px;font-weight:600}.card-b{padding:14px 16px}
.tw{overflow-x:auto}
table{width:100%;border-collapse:collapse;font-size:12px}
th{padding:8px 10px;text-align:left;background:var(--bg3);color:var(--tx2);font-weight:600;font-size:11px;text-transform:uppercase;letter-spacing:.4px;border-bottom:1px solid var(--bd)}
td{padding:8px 10px;border-bottom:1px solid var(--bd)}tr:last-child td{border-bottom:none}tr:hover td{background:var(--bg2)}
.b{display:inline-block;padding:2px 8px;border-radius:var(--r);font-size:10px;font-weight:600}
.b-bl{background:var(--blue-bg);color:var(--blue-dk)}.b-gr{background:var(--green-bg);color:var(--green-dk)}
.b-am{background:var(--amber-bg);color:var(--amber-dk)}.b-co{background:var(--coral-bg);color:var(--coral-dk)}
.b-pu{background:var(--purple-bg);color:var(--purple-dk)}.b-te{background:var(--teal-bg);color:var(--teal-dk)}
.mblk{border:1px solid var(--bd);border-radius:var(--rl);margin-bottom:10px;overflow:hidden}
.mblk-h{background:var(--bg3);padding:8px 14px;display:flex;align-items:center;justify-content:space-between;gap:8px;flex-wrap:wrap}
.mblk-h .mn{font-weight:600;font-size:13px;display:flex;align-items:center;gap:6px}
.mblk-h .mf{font-size:12px;color:var(--tx2)}.mblk-h .mf strong{color:var(--tx)}
.dh{background:var(--bg3);border:1px solid var(--bd);border-radius:var(--r);padding:8px 14px;margin-bottom:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap;font-size:13px}
.dh .da{font-weight:600}.dh .db{color:var(--tx2);font-size:12px}
/* Calendar */
.cg{display:grid;grid-template-columns:repeat(7,1fr);gap:3px}
.chc{text-align:center;font-size:11px;font-weight:600;color:var(--tx2);padding:4px}
.cc{border:1px solid var(--bd);border-radius:var(--r);padding:4px;min-height:40px;font-size:10px}
.cc .dn{font-weight:600;font-size:11px;margin-bottom:2px}
.cc.hi{border-color:var(--blue);background:var(--blue-bg)}.cc.md{border-color:var(--green);background:var(--green-bg)}
.cc.lo{border-color:var(--amber);background:var(--amber-bg)}.cc.idle{border-color:var(--bd);background:var(--bg2)}
.mc-tag{display:inline-block;padding:1px 4px;border-radius:3px;font-size:8px;font-weight:600;margin:1px;line-height:1.3}
.ins{background:var(--bg);border-left:3px solid var(--teal);border-radius:0 var(--r) var(--r) 0;padding:10px 14px;margin-bottom:8px;font-size:12px;border-top:1px solid var(--bd);border-right:1px solid var(--bd);border-bottom:1px solid var(--bd)}
.ins strong{font-weight:600}
.ins.warn{border-left-color:var(--amber)}.ins.bad{border-left-color:var(--coral)}.ins.good{border-left-color:var(--green)}
.sbar{display:flex;align-items:center;gap:10px;margin-bottom:8px}
.sbar-l{font-size:12px;width:100px;color:var(--tx2);flex-shrink:0}
.sbar-t{flex:1;background:var(--bg3);border-radius:4px;height:24px;overflow:hidden}
.sbar-f{height:100%;border-radius:4px;display:flex;align-items:center;padding-left:8px;font-size:11px;font-weight:600;color:#fff}
.lgd{display:flex;flex-wrap:wrap;gap:12px;font-size:11px;color:var(--tx2);margin-bottom:12px}
.lgd span{display:flex;align-items:center;gap:4px}.ld{width:10px;height:10px;border-radius:3px}
.chw{position:relative;width:100%}
.r2{display:grid;grid-template-columns:1fr 1fr;gap:14px;margin-bottom:14px}
@media(max-width:768px){.r2{grid-template-columns:1fr}}
.r3{display:grid;grid-template-columns:1fr 1fr 1fr;gap:14px;margin-bottom:14px}
@media(max-width:900px){.r3{grid-template-columns:1fr}}
.empty{padding:30px;text-align:center;color:var(--tx2)}.empty i{font-size:28px;display:block;margin-bottom:8px;color:var(--tx3)}
.perf-bar{display:flex;align-items:center;gap:6px}.perf-track{width:80px;height:6px;background:var(--bg3);border-radius:3px;overflow:hidden}
.perf-fill{height:100%;border-radius:3px}
.nep-label{font-size:11px;color:var(--tx2);font-style:italic}
/* JT vs FP comparison specific */
.comp-bar{height:20px;border-radius:3px;display:flex;overflow:hidden;background:var(--bg3)}
.comp-bar .done{background:var(--green);transition:.3s}.comp-bar .rem{background:var(--amber);transition:.3s}
.pct-text{font-size:11px;font-weight:600;min-width:40px;text-align:right}
@media print{.tabs,.frow,.btn,.hdr .btn{display:none!important}.card,.mblk,.met{break-inside:avoid}.wrap{max-width:100%;padding:8px}body{background:#fff}}
</style>
</head>
<body>
<div class="wrap">

<!-- PAGE HEADER -->
<div class="hdr">
    <div>
        <h1><i class="fa-solid fa-industry" style="color:var(--blue);margin-right:6px"></i>Production reporting suite</h1>
        <div class="sub">
            <?php $aFy = array_filter($fiscalYears, fn($f) => $f['id'] == $fiscalYearId); $aFy = reset($aFy); echo h($aFy['fiscal_name'] ?? $aFy['fiscal_code'] ?? ''); ?>
            &mdash; <?= h($fromBsStr) ?> to <?= h($toBsStr) ?>
        </div>
    </div>
    <div style="display:flex;gap:6px;flex-wrap:wrap;align-items:center">
        <select onchange="location='<?= buildUrl($activeTab) ?>&fiscal_year_id='+this.value" style="padding:5px 8px;border:1px solid var(--bd2);border-radius:var(--r);font-size:12px">
            <?php foreach ($fiscalYears as $fy): ?>
            <option value="<?= $fy['id'] ?>" <?= $fy['id']==$fiscalYearId?'selected':'' ?>><?= h($fy['fiscal_code']) ?></option>
            <?php endforeach; ?>
        </select>
        <button class="btn" onclick="window.print()"><i class="fa-solid fa-print"></i> Print</button>
    </div>
</div>

<!-- TABS -->
<div class="tabs">
<?php
$tabList = [
    'daily'=>['Daily production','fa-calendar-day'],
    'machine-cal'=>['Machine calendar','fa-calendar'],
    'operator'=>['Operator report','fa-user-gear'],
    'supervisor'=>['Supervisor report','fa-user-tie'],
    'shift'=>['Manpower & shift','fa-people-group'],
    'trend'=>['Machine trend & analysis','fa-chart-line'],
    'jt-vs-fp'=>['JT vs FP comparison','fa-scale-balanced'],
];
foreach($tabList as $k=>[$lbl,$ico]):
?>
<a href="<?= buildUrl($k) ?>" class="<?= $activeTab===$k?'act':'' ?>"><i class="fa-solid <?= $ico ?>"></i> <?= $lbl ?></a>
<?php endforeach; ?>
</div>


<?php // ═══════════════════════════════════════════════════════
// COMMON DATE FILTER (appears on most tabs)
$showDateFilter = in_array($activeTab, ['daily','operator','supervisor','shift','trend','jt-vs-fp']);
if ($showDateFilter):
?>
<form method="get" class="frow">
    <input type="hidden" name="tab" value="<?= h($activeTab) ?>">
    <input type="hidden" name="fiscal_year_id" value="<?= $fiscalYearId ?>">
    <label>From (AD)</label>
    <input type="date" name="date_from" value="<?= h($filterDateFrom) ?>">
    <label>To (AD)</label>
    <input type="date" name="date_to" value="<?= h($filterDateTo) ?>">
    <label>or Nep from</label>
    <input type="text" name="nep_from" value="<?= h($nepDateFrom) ?>" placeholder="2082.01.15" style="width:100px">
    <label>to</label>
    <input type="text" name="nep_to" value="<?= h($nepDateTo) ?>" placeholder="2082.01.30" style="width:100px">
    <?php if ($activeTab === 'daily'): ?>
    <label>Machine</label>
    <select name="machine_id">
        <option value="all">All</option>
        <?php foreach ($machines as $m): ?>
        <option value="<?= $m['id'] ?>" <?= $filterMachine==$m['id']?'selected':'' ?>><?= h($m['machine_name']) ?></option>
        <?php endforeach; ?>
    </select>
    <?php endif; ?>
    <?php if ($activeTab === 'trend'): ?>
    <label>View</label>
    <select name="trend_view">
        <option value="daily" <?= $trendView==='daily'?'selected':'' ?>>Day-wise</option>
        <option value="monthly" <?= $trendView==='monthly'?'selected':'' ?>>Month-wise</option>
    </select>
    <?php endif; ?>
    <button type="submit" class="btn btn-p"><i class="fa-solid fa-filter"></i> Filter</button>
</form>
<?php endif; ?>


<?php if ($activeTab === 'daily'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- TAB 1: DAILY PRODUCTION                                -->
<!-- ═══════════════════════════════════════════════════════ -->
<?php
    $totalFP = array_sum(array_column($dailyData, 'fp_printqty'));
    $totalTarget = array_sum(array_column($dailyData, 'jtd_targetqty'));
    $totalBalance = $totalTarget - $totalFP;
    $totalJobs = count(array_unique(array_filter(array_column($dailyData, 'jt_id'))));
    $machUsed  = count(array_unique(array_filter(array_column($dailyData, 'machine_id'))));
?>
<div class="mrow">
    <div class="met"><div class="l">Total FP (actual)</div><div class="v"><?= fmtQty($totalFP) ?></div></div>
    <div class="met"><div class="l">Target qty</div><div class="v"><?= fmtQty($totalTarget) ?></div></div>
    <div class="met"><div class="l">Balance</div><div class="v" style="color:<?= $totalBalance>0?'var(--coral)':'var(--green)' ?>"><?= fmtQty(abs($totalBalance)) ?> <?= $totalBalance>0?'behind':'ahead' ?></div></div>
    <div class="met"><div class="l">Machines</div><div class="v"><?= $machUsed ?></div></div>
    <div class="met"><div class="l">Job tickets</div><div class="v"><?= $totalJobs ?></div></div>
    <div class="met"><div class="l">Entries</div><div class="v"><?= count($dailyData) ?></div></div>
</div>

<?php if (empty($dailyGrouped)): ?>
<div class="card"><div class="empty"><i class="fa-solid fa-inbox"></i>No production data for selected dates.</div></div>
<?php else: ?>
<?php foreach ($dailyGrouped as $dateEng => $machinesData):
    $dBs = adToBs($dateEng);
    $dBsStr = formatBsDate($dBs['y'], $dBs['m'], $dBs['d']);
    $nepMonth = $bsMonthNamesEn[$dBs['m']-1] ?? '';
?>
<div class="dh">
    <i class="fa-solid fa-calendar" style="color:var(--tx2)"></i>
    <span class="da"><?= h($dateEng) ?></span>
    <span style="color:var(--tx3)">/</span>
    <span class="db"><?= h($dBsStr) ?> (<?= $nepMonth ?> <?= $dBs['d'] ?>)</span>
</div>

<?php foreach ($machinesData as $mid => $mData):
    $mFP = array_sum(array_column($mData['rows'], 'fp_printqty'));
    $mTarget = array_sum(array_column($mData['rows'], 'jtd_targetqty'));
    $mBalance = $mTarget - $mFP;
    $mJobs = count(array_unique(array_filter(array_column($mData['rows'], 'jt_id'))));
    $fpBadge = $mFP >= 15000 ? 'b-gr' : ($mFP >= 8000 ? 'b-bl' : 'b-am');
?>
<div class="mblk">
    <div class="mblk-h">
        <div class="mn"><i class="fa-solid fa-gear" style="color:var(--tx2)"></i> <?= h($mData['machine_name']) ?></div>
        <div class="mf">
            FP: <strong><?= fmtQty($mFP) ?></strong>
            / Target: <strong><?= fmtQty($mTarget) ?></strong>
            / Balance: <strong style="color:<?= $mBalance>0?'var(--coral)':'var(--green)' ?>"><?= fmtQty(abs($mBalance)) ?></strong>
            <span class="b <?= $fpBadge ?>" style="margin-left:6px"><?= $mJobs ?> job<?= $mJobs>1?'s':'' ?></span>
        </div>
    </div>
    <div class="tw">
    <table>
        <thead><tr>
            <th>#</th><th>FP qty (actual)</th><th>Forma target</th><th>Balance</th>
            <th>Job ticket</th><th>Forma</th><th>Book code</th><th>Book name</th>
            <th>Operator</th><th>Incharge</th><th>Supervisor</th>
            <th>Shift</th>
        </tr></thead>
        <tbody>
        <?php foreach ($mData['rows'] as $i => $r):
            $rowBal = ((int)($r['jtd_targetqty'] ?? 0)) - ((int)($r['fp_printqty'] ?? 0));
        ?>
        <tr>
            <td style="color:var(--tx2)"><?= $i+1 ?></td>
            <td><strong><?= fmtQty((int)$r['fp_printqty']) ?></strong></td>
            <td><?= fmtQty((int)($r['jtd_targetqty'] ?? 0)) ?></td>
            <td style="color:<?= $rowBal>0?'var(--coral)':'var(--green)' ?>">
                <?= $rowBal > 0 ? '-' : '+' ?><?= fmtQty(abs($rowBal)) ?>
            </td>
            <td><span class="b b-bl"><?= h($r['job_ticket_code'] ?? '-') ?></span></td>
            <td><?= h($r['forma_page'] ?? '') ?> (<?= h($r['order_no'] ?? '') ?>)</td>
            <td><span class="b b-am"><?= h($r['book_code'] ?? '-') ?></span></td>
            <td style="max-width:120px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($r['book_name'] ?? '') ?>"><?= h($r['book_name'] ?? '-') ?></td>
            <td><?= h($r['operator_name'] ?? '-') ?></td>
            <td><?= h($r['incharge_name'] ?? '-') ?></td>
            <td><?= h($r['supervisor_name'] ?? '-') ?></td>
            <td>
                <?php $sn = getShiftName($r['shift_id'], $shiftMap); $st = getShiftTime($r['shift_id'], $shiftMap); ?>
                <span class="b b-pu"><?= h($sn) ?></span>
                <?php if ($st): ?><br><span class="nep-label"><?= h($st) ?></span><?php endif; ?>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>
<?php endforeach; ?>
<?php endforeach; ?>
<?php endif; ?>


<?php elseif ($activeTab === 'machine-cal'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- TAB 2: MACHINE CALENDAR (Nepali calendar, all machines)-->
<!-- ═══════════════════════════════════════════════════════ -->
<form method="get" class="frow">
    <input type="hidden" name="tab" value="machine-cal">
    <input type="hidden" name="fiscal_year_id" value="<?= $fiscalYearId ?>">
    <label>BS Year</label>
    <select name="cal_bs_year">
        <?php for ($y=2080; $y<=2090; $y++): ?>
        <option value="<?= $y ?>" <?= $calBsYear==$y?'selected':'' ?>><?= $y ?></option>
        <?php endfor; ?>
    </select>
    <label>BS Month</label>
    <select name="cal_bs_month">
        <?php for ($m=1; $m<=12; $m++): ?>
        <option value="<?= $m ?>" <?= $calBsMonth==$m?'selected':'' ?>><?= $bsMonthNamesEn[$m-1] ?> (<?= $bsMonthNames[$m-1] ?>)</option>
        <?php endfor; ?>
    </select>
    <button type="submit" class="btn btn-p"><i class="fa-solid fa-filter"></i> Show</button>
    <!-- Prev / Next month -->
    <?php
    $prevM = $calBsMonth - 1; $prevY = $calBsYear;
    if ($prevM < 1) { $prevM = 12; $prevY--; }
    $nextM = $calBsMonth + 1; $nextY = $calBsYear;
    if ($nextM > 12) { $nextM = 1; $nextY++; }
    ?>
    <a href="<?= buildUrl('machine-cal', ['cal_bs_year'=>$prevY, 'cal_bs_month'=>$prevM]) ?>" class="btn"><i class="fa-solid fa-chevron-left"></i> Prev</a>
    <a href="<?= buildUrl('machine-cal', ['cal_bs_year'=>$nextY, 'cal_bs_month'=>$nextM]) ?>" class="btn">Next <i class="fa-solid fa-chevron-right"></i></a>
</form>

<div style="font-size:16px;font-weight:700;margin-bottom:12px">
    <?= $bsMonthNames[$calBsMonth-1] ?> <?= $calBsYear ?> (<?= $bsMonthNamesEn[$calBsMonth-1] ?>)
</div>

<div class="lgd">
    <span><span class="ld" style="background:var(--blue)"></span>High (&ge;15k)</span>
    <span><span class="ld" style="background:var(--green)"></span>Medium (8-14k)</span>
    <span><span class="ld" style="background:var(--amber)"></span>Low (&lt;8k)</span>
    <span><span class="ld" style="background:var(--bd)"></span>Idle</span>
    <?php foreach ($machines as $mi => $mc): ?>
    <span><span class="ld" style="background:<?= $machineColors[$mi % count($machineColors)] ?>"></span><?= h($mc['machine_name']) ?></span>
    <?php endforeach; ?>
</div>

<div class="card">
<div class="card-b" style="overflow-x:auto">
<?php
    $daysInBsMonth = getBsDaysInMonth($calBsYear, $calBsMonth);
    $firstDayAd = bsToAd($calBsYear, $calBsMonth, 1);
    $firstDow = getDayOfWeek($firstDayAd);
?>
<div class="cg">
    <?php foreach ($bsDayNamesEn as $dn): ?>
    <div class="chc"><?= $dn ?></div>
    <?php endforeach; ?>
</div>
<div style="height:4px"></div>
<div class="cg">
    <?php for ($i = 0; $i < $firstDow; $i++): ?><div></div><?php endfor; ?>
    <?php for ($d = 1; $d <= $daysInBsMonth; $d++):
        $dayMachines = $calByDayMachine[$d] ?? [];
        $dayTotalFP = 0;
        foreach ($dayMachines as $dm) $dayTotalFP += (int)$dm['total_fp'];
        $cls = $dayTotalFP >= 15000 ? 'hi' : ($dayTotalFP >= 8000 ? 'md' : ($dayTotalFP > 0 ? 'lo' : 'idle'));
        $adDate = bsToAd($calBsYear, $calBsMonth, $d);
    ?>
    <div class="cc <?= $cls ?>" title="<?= $adDate ?>">
        <div class="dn"><?= $d ?></div>
        <?php if ($dayTotalFP > 0): ?>
        <div style="font-weight:600;font-size:10px;margin-bottom:2px"><?= fmtQty($dayTotalFP) ?></div>
        <?php foreach ($dayMachines as $dmId => $dm):
            $mIdx = array_search($dmId, array_column($machines, 'id'));
            $mColor = $machineColors[$mIdx !== false ? $mIdx : 0];
        ?>
        <span class="mc-tag" style="background:<?= $mColor ?>20;color:<?= $mColor ?>"><?= substr(h($dm['machine_name']),0,5) ?> <?= fmtQty((int)$dm['total_fp']) ?></span>
        <?php endforeach; ?>
        <?php else: ?>
        <div style="font-size:10px;color:var(--tx3)">&ndash;</div>
        <?php endif; ?>
    </div>
    <?php endfor; ?>
</div>
</div>
</div>

<!-- Month summary table -->
<div class="card">
    <div class="card-h"><h3>Monthly summary &mdash; <?= $bsMonthNamesEn[$calBsMonth-1] ?> <?= $calBsYear ?></h3></div>
    <div class="tw">
    <table>
        <thead><tr><th>Machine</th><th>Total FP</th><th>Active days</th><th>Avg / day</th><th>Jobs</th></tr></thead>
        <tbody>
        <?php
        $mSummary = [];
        foreach ($calData as $cd) {
            $mid = $cd['machine_id'];
            if (!isset($mSummary[$mid])) $mSummary[$mid] = ['name'=>$cd['machine_name'],'fp'=>0,'days'=>0,'jobs'=>0];
            $mSummary[$mid]['fp'] += (int)$cd['total_fp'];
            $mSummary[$mid]['days']++;
            $mSummary[$mid]['jobs'] += (int)$cd['job_count'];
        }
        usort($mSummary, fn($a,$b) => $b['fp'] - $a['fp']);
        foreach ($mSummary as $ms):
            $avg = $ms['days'] > 0 ? round($ms['fp'] / $ms['days']) : 0;
        ?>
        <tr>
            <td><strong><?= h($ms['name']) ?></strong></td>
            <td><strong><?= fmtQty($ms['fp']) ?></strong></td>
            <td><?= $ms['days'] ?></td>
            <td><?= fmtQty($avg) ?></td>
            <td><?= $ms['jobs'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>


<?php elseif ($activeTab === 'operator'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- TAB 3: OPERATOR WORK REPORT                            -->
<!-- ═══════════════════════════════════════════════════════ -->
<?php
    $opTotalFP = array_sum(array_column($operatorData, 'total_fp'));
    $opCount = count(array_unique(array_column($operatorData, 'operator_id')));
    $maxFP = $operatorData ? (int)$operatorData[0]['total_fp'] : 1;
?>
<div class="mrow">
    <div class="met"><div class="l">Operators</div><div class="v"><?= $opCount ?></div></div>
    <div class="met"><div class="l">Total FP</div><div class="v"><?= fmtQty($opTotalFP) ?></div></div>
    <div class="met"><div class="l">Avg / operator</div><div class="v"><?= $opCount?fmtQty(round($opTotalFP/$opCount)):'0' ?></div></div>
    <div class="met"><div class="l">Total jobs</div><div class="v"><?= array_sum(array_column($operatorData, 'total_jobs')) ?></div></div>
</div>

<div class="r2">
    <div class="card"><div class="card-h"><h3>Output by operator</h3></div><div class="card-b"><div class="chw" style="height:<?= max(200,$opCount*35+60) ?>px"><canvas id="opC"></canvas></div></div></div>
    <div class="card"><div class="card-h"><h3>Shift distribution</h3></div><div class="card-b"><div class="chw" style="height:240px"><canvas id="opSC"></canvas></div></div></div>
</div>

<div class="card">
    <div class="card-h"><h3>Operator details</h3></div>
    <div class="tw"><table>
        <thead><tr><th>Operator</th><th>Machine</th><th>Shift</th><th>Total FP</th><th>Target</th><th>Jobs</th><th>Days</th><th>Avg/day</th><th>Performance</th></tr></thead>
        <tbody>
        <?php foreach ($operatorData as $op):
            $pct = $maxFP>0?round((int)$op['total_fp']/$maxFP*100):0;
            $tgt = (int)($op['total_target'] ?? 0);
            $eff = $tgt>0?round((int)$op['total_fp']/$tgt*100):0;
            $badge = $eff>=90?'b-gr':($eff>=70?'b-bl':'b-am');
            $avg = (int)$op['working_days']>0?round((int)$op['total_fp']/(int)$op['working_days']):0;
        ?>
        <tr>
            <td><strong><?= h($op['operator_name']) ?></strong></td>
            <td><span class="b b-bl"><?= h($op['machine_name'] ?? '-') ?></span></td>
            <td><span class="b b-pu"><?= h(getShiftName($op['shift_id'],$shiftMap)) ?></span></td>
            <td><strong><?= fmtQty((int)$op['total_fp']) ?></strong></td>
            <td><?= fmtQty($tgt) ?></td>
            <td><?= $op['total_jobs'] ?></td>
            <td><?= $op['working_days'] ?></td>
            <td><?= fmtQty($avg) ?></td>
            <td>
                <div class="perf-bar">
                    <div class="perf-track"><div class="perf-fill" style="width:<?= min($pct,100) ?>%;background:<?= $pct>=80?'var(--green)':($pct>=60?'var(--amber)':'var(--coral)') ?>"></div></div>
                    <span class="b <?= $badge ?>"><?= $eff ?>%</span>
                </div>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    new Chart(document.getElementById('opC'),{type:'bar',data:{labels:<?= json_encode(array_map(fn($o)=>$o['operator_name'],$operatorData)) ?>,datasets:[{label:'FP',data:<?= json_encode(array_map(fn($o)=>(int)$o['total_fp'],$operatorData)) ?>,backgroundColor:'#2563eb',borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
    <?php $sFP=[];foreach($operatorData as $op){$sid=$op['shift_id']??0;$sFP[$sid]=($sFP[$sid]??0)+(int)$op['total_fp'];}$sL=[];$sV=[];foreach($sFP as $sid=>$fp){$sL[]=getShiftName($sid,$shiftMap);$sV[]=$fp;} ?>
    new Chart(document.getElementById('opSC'),{type:'doughnut',data:{labels:<?= json_encode($sL) ?>,datasets:[{data:<?= json_encode($sV) ?>,backgroundColor:['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626']}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{position:'bottom'}}}});
});
</script>


<?php elseif ($activeTab === 'supervisor'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- TAB 4: SUPERVISOR WORK REPORT                          -->
<!-- ═══════════════════════════════════════════════════════ -->
<?php
    $svTotal = array_sum(array_column($supervisorData, 'total_fp'));
    $svCount = count($supervisorData);
    $svTeam  = array_sum(array_column($supervisorData, 'team_size'));
?>
<div class="mrow">
    <div class="met"><div class="l">Supervisors</div><div class="v"><?= $svCount ?></div></div>
    <div class="met"><div class="l">Total team</div><div class="v"><?= $svTeam ?></div></div>
    <div class="met"><div class="l">Total FP</div><div class="v"><?= fmtQty($svTotal) ?></div></div>
    <div class="met"><div class="l">Total jobs</div><div class="v"><?= array_sum(array_column($supervisorData, 'total_jobs')) ?></div></div>
</div>

<div class="card"><div class="card-h"><h3>Team output by supervisor</h3></div><div class="card-b"><div class="chw" style="height:220px"><canvas id="svC"></canvas></div></div></div>

<div class="card">
    <div class="card-h"><h3>Supervisor details</h3></div>
    <div class="tw"><table>
        <thead><tr><th>Supervisor</th><th>Team</th><th>Machines</th><th>Total FP</th><th>Target</th><th>Jobs</th><th>Days</th><th>Entries</th></tr></thead>
        <tbody>
        <?php foreach ($supervisorData as $sv):
            $tgt = (int)($sv['total_target'] ?? 0);
        ?>
        <tr>
            <td><strong><?= h($sv['supervisor_name']) ?></strong></td>
            <td><?= $sv['team_size'] ?></td>
            <td><?php foreach(explode(', ',$sv['machine_names']??'') as $mn): ?><span class="b b-pu" style="margin:1px"><?= h($mn) ?></span><?php endforeach; ?></td>
            <td><strong><?= fmtQty((int)$sv['total_fp']) ?></strong></td>
            <td><?= fmtQty($tgt) ?></td>
            <td><?= $sv['total_jobs'] ?></td>
            <td><?= $sv['working_days'] ?></td>
            <td><?= $sv['total_entries'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    new Chart(document.getElementById('svC'),{type:'bar',data:{labels:<?= json_encode(array_map(fn($s)=>$s['supervisor_name'],$supervisorData)) ?>,datasets:[{label:'FP',data:<?= json_encode(array_map(fn($s)=>(int)$s['total_fp'],$supervisorData)) ?>,backgroundColor:['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488'],borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
});
</script>


<?php elseif ($activeTab === 'shift'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- TAB 5: MANPOWER & SHIFT                                -->
<!-- ═══════════════════════════════════════════════════════ -->
<?php
    $shTotalFP = array_sum(array_column($shiftReportData, 'total_fp'));
    $bestShift = $shiftReportData[0] ?? null;
    $maxShFP = $bestShift?(int)$bestShift['total_fp']:1;
    $shColors = ['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488'];
?>
<div class="mrow">
    <div class="met"><div class="l">Best shift</div><div class="vs"><?= $bestShift?h(getShiftName($bestShift['shift_id'],$shiftMap)):'N/A' ?></div></div>
    <?php foreach ($shiftReportData as $sd): ?>
    <div class="met"><div class="l"><?= h(getShiftName($sd['shift_id'],$shiftMap)) ?></div><div class="v"><?= fmtQty((int)$sd['total_fp']) ?></div></div>
    <?php endforeach; ?>
</div>

<?php if ($bestShift): ?>
<div class="ins good"><strong>Top shift:</strong> <?= h(getShiftName($bestShift['shift_id'],$shiftMap)) ?> delivers <?= fmtQty((int)$bestShift['total_fp']) ?> FP &mdash; highest output. Consider allocating complex jobs here.</div>
<?php endif; ?>
<?php if (count($shiftReportData) > 1):
    $worst = end($shiftReportData);
    $wPct = $maxShFP>0?round((int)$worst['total_fp']/$maxShFP*100):0;
?>
<div class="ins warn"><strong>Lowest shift:</strong> <?= h(getShiftName($worst['shift_id'],$shiftMap)) ?> output (<?= fmtQty((int)$worst['total_fp']) ?>) is <?= $wPct ?>% of top shift. Review staffing or machine downtime.</div>
<?php endif; ?>

<div class="r2">
    <div class="card"><div class="card-h"><h3>Production by shift</h3></div><div class="card-b"><div class="chw" style="height:220px"><canvas id="shC"></canvas></div></div></div>
    <div class="card"><div class="card-h"><h3>Shift output bars</h3></div><div class="card-b">
        <?php foreach ($shiftReportData as $si=>$sd): $pct=$maxShFP>0?round((int)$sd['total_fp']/$maxShFP*100):0; ?>
        <div class="sbar">
            <div class="sbar-l"><?= h(getShiftName($sd['shift_id'],$shiftMap)) ?></div>
            <div class="sbar-t"><div class="sbar-f" style="width:<?= $pct ?>%;background:<?= $shColors[$si%count($shColors)] ?>"><?= fmtQty((int)$sd['total_fp']) ?></div></div>
        </div>
        <?php endforeach; ?>
    </div></div>
</div>

<div class="card">
    <div class="card-h"><h3>Manpower allocation</h3></div>
    <div class="tw"><table>
        <thead><tr><th>Name</th><th>Role</th><th>Shift</th><th>Machine</th><th>FP output</th><th>Entries</th><th>Days</th></tr></thead>
        <tbody>
        <?php foreach ($manpowerData as $mp): ?>
        <tr>
            <td><?= h($mp['username']) ?></td>
            <td><span class="b b-te"><?= h($mp['role'] ?? 'operator') ?></span></td>
            <td><span class="b b-pu"><?= h(getShiftName($mp['shift_id'],$shiftMap)) ?></span></td>
            <td><span class="b b-bl"><?= h($mp['machine_name'] ?? '-') ?></span></td>
            <td><strong><?= fmtQty((int)$mp['total_fp']) ?></strong></td>
            <td><?= $mp['entries'] ?></td>
            <td><?= $mp['working_days'] ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    new Chart(document.getElementById('shC'),{type:'bar',data:{labels:<?= json_encode(array_map(fn($sd)=>getShiftName($sd['shift_id'],$GLOBALS['shiftMap']),$shiftReportData)) ?>,datasets:[{data:<?= json_encode(array_map(fn($sd)=>(int)$sd['total_fp'],$shiftReportData)) ?>,backgroundColor:<?= json_encode(array_slice($shColors,0,count($shiftReportData))) ?>,borderRadius:6}]},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
});
</script>


<?php elseif ($activeTab === 'trend'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- TAB 6: MACHINE TREND & ANALYSIS + SUGGESTIONS          -->
<!-- ═══════════════════════════════════════════════════════ -->
<?php
    $rankTotal = array_sum(array_column($machineRankData, 'total_fp'));
    $topMach = $machineRankData[0] ?? null;
    $tColors = ['#2563eb','#16a34a','#7c3aed','#d97706','#dc2626','#0d9488','#be185d','#4f46e5','#ca8a04','#059669'];
    $tDash = [[],[5,5],[10,5],[5,10],[8,3],[3,8]];
    $chartLabels = $trendView === 'daily' ? $trendLabels : $monthLabels;
    $chartSeries = $trendView === 'daily' ? $trendSeries : $monthSeries;
    $legendMachines = array_keys($chartSeries);
?>
<div class="mrow">
    <div class="met"><div class="l">Top machine</div><div class="vs"><?= h($topMach['machine_name'] ?? 'N/A') ?></div></div>
    <div class="met"><div class="l">Period total FP</div><div class="v"><?= fmtQty($rankTotal) ?></div></div>
    <div class="met"><div class="l">Best daily avg</div><div class="v"><?= $topMach && $topMach['active_days']>0 ? fmtQty(round((int)$topMach['total_fp']/(int)$topMach['active_days'])) : '0' ?></div></div>
    <div class="met"><div class="l">Machines</div><div class="v"><?= count($machineRankData) ?></div></div>
</div>

<div class="lgd">
    <?php foreach ($legendMachines as $li=>$mn): ?>
    <span><span class="ld" style="background:<?= $tColors[$li%count($tColors)] ?>"></span><?= h($mn) ?></span>
    <?php endforeach; ?>
</div>

<div class="card"><div class="card-h"><h3>Output comparison &mdash; <?= $trendView==='daily'?'day-wise':'month-wise' ?></h3></div>
<div class="card-b"><div class="chw" style="height:320px"><canvas id="trC"></canvas></div></div></div>

<div class="card"><div class="card-h"><h3>Machine ranking</h3></div>
<div class="card-b"><div class="chw" style="height:<?= max(200,count($machineRankData)*42+60) ?>px"><canvas id="rkC"></canvas></div></div></div>

<!-- Machine performance table -->
<div class="card">
    <div class="card-h"><h3>Machine performance details</h3></div>
    <div class="tw"><table>
        <thead><tr><th>#</th><th>Machine</th><th>Total FP</th><th>Target</th><th>Efficiency</th><th>Jobs</th><th>Active days</th><th>Operators</th><th>Avg/day</th></tr></thead>
        <tbody>
        <?php foreach ($machineRankData as $ri=>$mr):
            $eff = (int)($mr['total_target']??0)>0 ? round((int)$mr['total_fp']/(int)$mr['total_target']*100) : 0;
            $avg = (int)$mr['active_days']>0 ? round((int)$mr['total_fp']/(int)$mr['active_days']) : 0;
            $effBadge = $eff>=90?'b-gr':($eff>=70?'b-bl':($eff>=50?'b-am':'b-co'));
        ?>
        <tr>
            <td style="color:var(--tx2)"><?= $ri+1 ?></td>
            <td><span class="b b-bl"><?= h($mr['machine_name']) ?></span></td>
            <td><strong><?= fmtQty((int)$mr['total_fp']) ?></strong></td>
            <td><?= fmtQty((int)($mr['total_target']??0)) ?></td>
            <td><span class="b <?= $effBadge ?>"><?= $eff ?>%</span></td>
            <td><?= $mr['total_jobs'] ?></td>
            <td><?= $mr['active_days'] ?></td>
            <td><?= $mr['operators'] ?></td>
            <td><?= fmtQty($avg) ?></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table></div>
</div>

<!-- ─── MANAGEMENT INSIGHTS & SUGGESTIONS ─── -->
<div style="margin-top:16px">
    <div style="font-size:14px;font-weight:700;margin-bottom:10px"><i class="fa-solid fa-lightbulb" style="color:var(--amber)"></i> Management insights & suggestions</div>

    <?php // Machine suggestions
    if (count($machineRankData) >= 2):
        $topM = $machineRankData[0]; $botM = end($machineRankData);
        $topAvg = (int)$topM['active_days']>0?round((int)$topM['total_fp']/(int)$topM['active_days']):0;
        $botAvg = (int)$botM['active_days']>0?round((int)$botM['total_fp']/(int)$botM['active_days']):0;
    ?>
    <div class="ins good"><strong>Best machine:</strong> <?= h($topM['machine_name']) ?> produces <?= fmtQty((int)$topM['total_fp']) ?> FP total (avg <?= fmtQty($topAvg) ?>/day). Allocate high-priority jobs to this machine for maximum throughput.</div>
    <div class="ins bad"><strong>Needs improvement:</strong> <?= h($botM['machine_name']) ?> produces only <?= fmtQty((int)$botM['total_fp']) ?> FP (avg <?= fmtQty($botAvg) ?>/day). Check for maintenance issues, downtime, or operator skill gaps. Consider increasing its usage or scheduling preventive maintenance.</div>
    <?php
        // Underutilized machines
        $avgDays = count($machineRankData)>0 ? round(array_sum(array_column($machineRankData,'active_days'))/count($machineRankData)) : 0;
        foreach ($machineRankData as $mr) {
            if ((int)$mr['active_days'] < $avgDays * 0.6 && (int)$mr['active_days'] > 0) {
                echo '<div class="ins warn"><strong>Underutilized:</strong> '.h($mr['machine_name']).' was active only '.$mr['active_days'].' days (avg is '.$avgDays.'). Increase job allocation to this machine or investigate why it\'s idle.</div>';
            }
        }
    endif; ?>

    <?php // Operator suggestions
    if (!empty($opRank)):
        $topOp = $opRank[0]; $botOp = end($opRank);
    ?>
    <div class="card" style="margin-top:12px">
        <div class="card-h"><h3><i class="fa-solid fa-user-gear" style="color:var(--blue);margin-right:6px"></i>Operator performance ranking</h3></div>
        <div class="tw"><table>
            <thead><tr><th>#</th><th>Operator</th><th>Total FP</th><th>Target</th><th>Efficiency</th><th>Jobs</th><th>Days</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($opRank as $ri=>$or):
                $eff = (float)($or['efficiency'] ?? 0);
                $status = $eff>=90?'Excellent':($eff>=75?'Good':($eff>=60?'Average':'Needs improvement'));
                $stBadge = $eff>=90?'b-gr':($eff>=75?'b-bl':($eff>=60?'b-am':'b-co'));
            ?>
            <tr>
                <td style="color:var(--tx2)"><?= $ri+1 ?></td>
                <td><strong><?= h($or['username']) ?></strong></td>
                <td><strong><?= fmtQty((int)$or['total_fp']) ?></strong></td>
                <td><?= fmtQty((int)($or['total_target']??0)) ?></td>
                <td><span class="b <?= $stBadge ?>"><?= $eff ?>%</span></td>
                <td><?= $or['jobs'] ?></td>
                <td><?= $or['days'] ?></td>
                <td><span class="b <?= $stBadge ?>"><?= $status ?></span></td>
            </tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php if ($topOp): ?><div class="ins good"><strong>Best operator:</strong> <?= h($topOp['username']) ?> with <?= fmtQty((int)$topOp['total_fp']) ?> FP and <?= $topOp['efficiency'] ?>% efficiency. Consider as team lead or trainer.</div><?php endif; ?>
    <?php if ($botOp && count($opRank)>1 && (float)$botOp['efficiency']<70): ?><div class="ins bad"><strong>Operator needs support:</strong> <?= h($botOp['username']) ?> at <?= $botOp['efficiency'] ?>% efficiency. Consider additional training, machine reassignment, or mentoring from top performers.</div><?php endif; ?>
    <?php endif; ?>

    <?php // Supervisor suggestions
    if (!empty($svRank)):
    ?>
    <div class="card" style="margin-top:12px">
        <div class="card-h"><h3><i class="fa-solid fa-user-tie" style="color:var(--purple);margin-right:6px"></i>Supervisor performance ranking</h3></div>
        <div class="tw"><table>
            <thead><tr><th>#</th><th>Supervisor</th><th>Total FP</th><th>Target</th><th>Efficiency</th><th>Jobs</th><th>Days</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($svRank as $ri=>$sr):
                $eff=(float)($sr['efficiency']??0);$status=$eff>=90?'Excellent':($eff>=75?'Good':($eff>=60?'Average':'Needs improvement'));$stB=$eff>=90?'b-gr':($eff>=75?'b-bl':($eff>=60?'b-am':'b-co'));
            ?>
            <tr><td style="color:var(--tx2)"><?= $ri+1 ?></td><td><strong><?= h($sr['username']) ?></strong></td><td><strong><?= fmtQty((int)$sr['total_fp']) ?></strong></td><td><?= fmtQty((int)($sr['total_target']??0)) ?></td><td><span class="b <?= $stB ?>"><?= $eff ?>%</span></td><td><?= $sr['jobs'] ?></td><td><?= $sr['days'] ?></td><td><span class="b <?= $stB ?>"><?= $status ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>

    <?php // Incharge suggestions
    if (!empty($icRank)):
    ?>
    <div class="card" style="margin-top:12px">
        <div class="card-h"><h3><i class="fa-solid fa-user-shield" style="color:var(--teal);margin-right:6px"></i>Incharge performance ranking</h3></div>
        <div class="tw"><table>
            <thead><tr><th>#</th><th>Incharge</th><th>Total FP</th><th>Target</th><th>Efficiency</th><th>Jobs</th><th>Days</th><th>Status</th></tr></thead>
            <tbody>
            <?php foreach ($icRank as $ri=>$ir):
                $eff=(float)($ir['efficiency']??0);$status=$eff>=90?'Excellent':($eff>=75?'Good':($eff>=60?'Average':'Needs improvement'));$stB=$eff>=90?'b-gr':($eff>=75?'b-bl':($eff>=60?'b-am':'b-co'));
            ?>
            <tr><td style="color:var(--tx2)"><?= $ri+1 ?></td><td><strong><?= h($ir['username']) ?></strong></td><td><strong><?= fmtQty((int)$ir['total_fp']) ?></strong></td><td><?= fmtQty((int)($ir['total_target']??0)) ?></td><td><span class="b <?= $stB ?>"><?= $eff ?>%</span></td><td><?= $ir['jobs'] ?></td><td><?= $ir['days'] ?></td><td><span class="b <?= $stB ?>"><?= $status ?></span></td></tr>
            <?php endforeach; ?>
            </tbody>
        </table></div>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    const tLabels=<?= json_encode($chartLabels) ?>;
    const tColors=<?= json_encode($tColors) ?>;
    const tDash=<?= json_encode($tDash) ?>;
    const ds=[];
    <?php foreach ($legendMachines as $li=>$mn):
        $vals=[];foreach($chartLabels as $lbl){$vals[]=$chartSeries[$mn][$lbl]??0;}
    ?>
    ds.push({label:<?= json_encode($mn) ?>,data:<?= json_encode($vals) ?>,borderColor:tColors[<?= $li ?>%tColors.length],backgroundColor:tColors[<?= $li ?>%tColors.length]+'22',tension:.3,fill:false,borderDash:tDash[<?= $li ?>%tDash.length],pointRadius:3});
    <?php endforeach; ?>
    new Chart(document.getElementById('trC'),{type:'line',data:{labels:tLabels,datasets:ds},options:{responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{y:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
    
    new Chart(document.getElementById('rkC'),{type:'bar',data:{labels:<?= json_encode(array_map(fn($r)=>$r['machine_name'],$machineRankData)) ?>,datasets:[{data:<?= json_encode(array_map(fn($r)=>(int)$r['total_fp'],$machineRankData)) ?>,backgroundColor:tColors.slice(0,<?= count($machineRankData) ?>),borderRadius:4}]},options:{indexAxis:'y',responsive:true,maintainAspectRatio:false,plugins:{legend:{display:false}},scales:{x:{ticks:{callback:v=>v>=1000?(v/1000)+'k':v}}}}});
});
</script>


<?php elseif ($activeTab === 'jt-vs-fp'): ?>
<!-- ═══════════════════════════════════════════════════════ -->
<!-- TAB 7: JOB TICKET vs FORMA PRINTING COMPARISON         -->
<!-- ═══════════════════════════════════════════════════════ -->
<?php
    $totalJtQty    = array_sum(array_column($jtVsFpData, 'jt_total_qty'));
    $totalFpActual = array_sum(array_column($jtVsFpData, 'fp_total'));
    $totalJtCount  = count($jtVsFpData);
    $overallPct    = $totalJtQty > 0 ? round($totalFpActual / $totalJtQty * 100, 1) : 0;

    // Categorize JTs
    $completed = $onTrack = $behind = $notStarted = 0;
    foreach ($jtVsFpData as $jt) {
        $pct = (int)$jt['jt_total_qty'] > 0 ? round((int)$jt['fp_total'] / (int)$jt['jt_total_qty'] * 100) : 0;
        if ($pct >= 100)     $completed++;
        elseif ($pct >= 50)  $onTrack++;
        elseif ($pct > 0)    $behind++;
        else                 $notStarted++;
    }
?>

<div class="mrow">
    <div class="met"><div class="l">Total job tickets</div><div class="v"><?= $totalJtCount ?></div></div>
    <div class="met"><div class="l">JT total qty</div><div class="v"><?= fmtQty($totalJtQty) ?></div></div>
    <div class="met"><div class="l">FP actual qty</div><div class="v"><?= fmtQty($totalFpActual) ?></div></div>
    <div class="met"><div class="l">Overall completion</div><div class="v"><?= $overallPct ?>%</div></div>
    <div class="met"><div class="l">Balance (remaining)</div><div class="v" style="color:<?= ($totalJtQty-$totalFpActual)>0?'var(--coral)':'var(--green)' ?>"><?= fmtQty(abs($totalJtQty - $totalFpActual)) ?></div></div>
</div>

<!-- Status summary cards -->
<div class="mrow" style="grid-template-columns:repeat(4,1fr)">
    <div class="met" style="border-left:3px solid var(--green)"><div class="l">Completed (≥100%)</div><div class="v"><?= $completed ?></div></div>
    <div class="met" style="border-left:3px solid var(--blue)"><div class="l">On track (50-99%)</div><div class="v"><?= $onTrack ?></div></div>
    <div class="met" style="border-left:3px solid var(--amber)"><div class="l">Behind (&lt;50%)</div><div class="v"><?= $behind ?></div></div>
    <div class="met" style="border-left:3px solid var(--coral)"><div class="l">Not started (0%)</div><div class="v"><?= $notStarted ?></div></div>
</div>

<!-- Insights -->
<?php if ($completed > 0): ?>
<div class="ins good"><strong><?= $completed ?> job ticket<?= $completed>1?'s':'' ?> completed</strong> &mdash; <?= $totalJtCount>0?round($completed/$totalJtCount*100):0 ?>% of all JTs in this period have met or exceeded their print targets.</div>
<?php endif; ?>
<?php if ($behind > 0): ?>
<div class="ins warn"><strong><?= $behind ?> job ticket<?= $behind>1?'s are':' is' ?> behind schedule</strong> &mdash; less than 50% of target completed. Review machine allocation and shift assignments for these jobs.</div>
<?php endif; ?>
<?php if ($notStarted > 0): ?>
<div class="ins bad"><strong><?= $notStarted ?> job ticket<?= $notStarted>1?'s have':' has' ?> no FP entries</strong> &mdash; these jobs haven't started printing yet. Verify scheduling and job readiness.</div>
<?php endif; ?>

<div class="r2">
    <!-- Completion chart -->
    <div class="card">
        <div class="card-h"><h3>Completion status</h3></div>
        <div class="card-b"><div class="chw" style="height:250px"><canvas id="jtStatusC"></canvas></div></div>
    </div>
    <!-- Top/Bottom JTs -->
    <div class="card">
        <div class="card-h"><h3>Target vs actual (top job tickets)</h3></div>
        <div class="card-b"><div class="chw" style="height:250px"><canvas id="jtBarC"></canvas></div></div>
    </div>
</div>

<!-- JT Summary Table -->
<div class="card">
    <div class="card-h">
        <h3>Job ticket vs forma printing — summary</h3>
        <span style="font-size:11px;color:var(--tx2)"><?= $totalJtCount ?> job tickets</span>
    </div>
    <div class="tw">
    <table>
        <thead><tr>
            <th>JT code</th><th>Book</th><th>Book name</th><th>Type</th><th>Lot</th>
            <th>JT target</th><th>FP actual</th><th>Balance</th><th>Completion</th>
            <th>Machines</th><th>Operators</th><th>FP days</th><th>Status</th>
        </tr></thead>
        <tbody>
        <?php foreach ($jtVsFpData as $jt):
            $target  = (int)$jt['jt_total_qty'];
            $actual  = (int)$jt['fp_total'];
            $balance = $target - $actual;
            $pct     = $target > 0 ? round($actual / $target * 100, 1) : 0;
            $barPct  = min($pct, 100);
            
            // Status badge
            if ($pct >= 100)     { $stLabel = 'Completed'; $stBadge = 'b-gr'; }
            elseif ($pct >= 75)  { $stLabel = 'Almost done'; $stBadge = 'b-bl'; }
            elseif ($pct >= 50)  { $stLabel = 'On track'; $stBadge = 'b-bl'; }
            elseif ($pct > 0)    { $stLabel = 'Behind'; $stBadge = 'b-am'; }
            else                 { $stLabel = 'Not started'; $stBadge = 'b-co'; }
            
            // JT nepali date
            $jtBs = '';
            if (!empty($jt['jt_date_nep'])) $jtBs = $jt['jt_date_nep'];
        ?>
        <tr>
            <td>
                <span class="b b-bl"><?= h($jt['job_ticket_code']) ?></span>
                <?php if ($jtBs): ?><br><span class="nep-label"><?= h($jtBs) ?></span><?php endif; ?>
            </td>
            <td><span class="b b-am"><?= h($jt['book_code'] ?? '-') ?></span></td>
            <td style="max-width:110px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($jt['book_name'] ?? '') ?>"><?= h($jt['book_name'] ?? '-') ?></td>
            <td><span class="b b-te"><?= h($jt['book_type'] ?? '-') ?></span></td>
            <td><?= h($jt['lot'] ?? '-') ?></td>
            <td><strong><?= fmtQty($target) ?></strong></td>
            <td><strong><?= fmtQty($actual) ?></strong></td>
            <td style="color:<?= $balance>0?'var(--coral)':'var(--green)' ?>;font-weight:600">
                <?= $balance > 0 ? '-' : '+' ?><?= fmtQty(abs($balance)) ?>
            </td>
            <td style="min-width:130px">
                <div style="display:flex;align-items:center;gap:6px">
                    <div class="comp-bar" style="flex:1;height:14px">
                        <div class="done" style="width:<?= $barPct ?>%"></div>
                    </div>
                    <span class="pct-text"><?= $pct ?>%</span>
                </div>
            </td>
            <td style="font-size:11px"><?= h($jt['machine_names'] ?? '-') ?></td>
            <td><?= $jt['operators_used'] ?></td>
            <td><?= $jt['fp_days'] ?></td>
            <td><span class="b <?= $stBadge ?>"><?= $stLabel ?></span></td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- JT Detail Comparison (per forma/JTD) -->
<div class="card">
    <div class="card-h">
        <h3>Forma-level detail &mdash; JT details vs FP actual</h3>
        <span style="font-size:11px;color:var(--tx2)"><?= count($jtDetailData) ?> forma entries</span>
    </div>
    <div class="tw">
    <table>
        <thead><tr>
            <th>JT code</th><th>Order #</th><th>Book</th><th>Book name</th><th>Forma page</th>
            <th>JTD target</th><th>FP actual</th><th>Balance</th><th>Completion</th><th>JTD status</th>
        </tr></thead>
        <tbody>
        <?php
        $prevJt = '';
        foreach ($jtDetailData as $jtd):
            $target  = (int)$jtd['jtd_target_qty'];
            $actual  = (int)$jtd['fp_actual'];
            $balance = $target - $actual;
            $pct     = $target > 0 ? round($actual / $target * 100, 1) : 0;
            $barPct  = min($pct, 100);
            $barColor = $pct >= 100 ? 'var(--green)' : ($pct >= 50 ? 'var(--blue)' : ($pct > 0 ? 'var(--amber)' : 'var(--coral)'));
            $isNewJt = $jtd['job_ticket_code'] !== $prevJt;
            $prevJt  = $jtd['job_ticket_code'];
        ?>
        <tr<?= $isNewJt ? ' style="border-top:2px solid var(--bd2)"' : '' ?>>
            <td><?php if ($isNewJt): ?><span class="b b-bl"><?= h($jtd['job_ticket_code']) ?></span><?php endif; ?></td>
            <td style="color:var(--tx2)"><?= h($jtd['order_no']) ?></td>
            <td><span class="b b-am"><?= h($jtd['book_code'] ?? '-') ?></span></td>
            <td style="max-width:100px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= h($jtd['book_name'] ?? '') ?>"><?= h($jtd['book_name'] ?? '-') ?></td>
            <td style="font-weight:600"><?= h($jtd['forma_page']) ?></td>
            <td><strong><?= fmtQty($target) ?></strong></td>
            <td><strong><?= fmtQty($actual) ?></strong></td>
            <td style="color:<?= $balance>0?'var(--coral)':'var(--green)' ?>;font-weight:600">
                <?= $balance > 0 ? '-' : '+' ?><?= fmtQty(abs($balance)) ?>
            </td>
            <td style="min-width:120px">
                <div style="display:flex;align-items:center;gap:6px">
                    <div class="comp-bar" style="flex:1;height:12px">
                        <div style="width:<?= $barPct ?>%;height:100%;border-radius:3px;background:<?= $barColor ?>"></div>
                    </div>
                    <span class="pct-text"><?= $pct ?>%</span>
                </div>
            </td>
            <td>
                <?php
                    $jtdSt = $jtd['jtd_status'] ?? 'scheduled';
                    $jtdBadge = match($jtdSt) {
                        'completed' => 'b-gr', 'in_progress','printing' => 'b-bl',
                        'scheduled' => 'b-pu', default => 'b-am'
                    };
                ?>
                <span class="b <?= $jtdBadge ?>"><?= h($jtdSt) ?></span>
            </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
</div>

<!-- Overall completion chart by book -->
<?php
    $bookCompletion = [];
    foreach ($jtVsFpData as $jt) {
        $bc = $jt['book_code'] ?? 'Unknown';
        if (!isset($bookCompletion[$bc])) $bookCompletion[$bc] = ['target'=>0, 'actual'=>0, 'name'=>$jt['book_name']??$bc];
        $bookCompletion[$bc]['target'] += (int)$jt['jt_total_qty'];
        $bookCompletion[$bc]['actual'] += (int)$jt['fp_total'];
    }
    // Sort by target desc, take top 10
    uasort($bookCompletion, fn($a,$b) => $b['target'] - $a['target']);
    $bookCompletion = array_slice($bookCompletion, 0, 10, true);
?>
<div class="card">
    <div class="card-h"><h3>Completion by book (top 10)</h3></div>
    <div class="card-b"><div class="chw" style="height:300px"><canvas id="bookCompC"></canvas></div></div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function(){
    // Status doughnut
    new Chart(document.getElementById('jtStatusC'), {
        type: 'doughnut',
        data: {
            labels: ['Completed','On track','Behind','Not started'],
            datasets: [{
                data: [<?= $completed ?>, <?= $onTrack ?>, <?= $behind ?>, <?= $notStarted ?>],
                backgroundColor: ['#16a34a','#2563eb','#d97706','#dc2626']
            }]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'bottom', labels: { font: { size: 11 } } } }
        }
    });

    // Top JTs bar chart
    <?php
        $barJts = array_slice($jtVsFpData, 0, 8);
        $barLabels = array_map(fn($j) => $j['job_ticket_code'], $barJts);
        $barTargets = array_map(fn($j) => (int)$j['jt_total_qty'], $barJts);
        $barActuals = array_map(fn($j) => (int)$j['fp_total'], $barJts);
    ?>
    new Chart(document.getElementById('jtBarC'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($barLabels) ?>,
            datasets: [
                { label: 'JT target', data: <?= json_encode($barTargets) ?>, backgroundColor: '#2563eb44', borderColor: '#2563eb', borderWidth: 1, borderRadius: 4 },
                { label: 'FP actual', data: <?= json_encode($barActuals) ?>, backgroundColor: '#16a34a', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } },
            scales: { y: { ticks: { callback: v => v>=1000?(v/1000)+'k':v } } }
        }
    });

    // Book completion chart
    <?php
        $bkLabels = []; $bkTarget = []; $bkActual = [];
        foreach ($bookCompletion as $bc => $bk) {
            $bkLabels[] = $bc;
            $bkTarget[] = $bk['target'];
            $bkActual[] = $bk['actual'];
        }
    ?>
    new Chart(document.getElementById('bookCompC'), {
        type: 'bar',
        data: {
            labels: <?= json_encode($bkLabels) ?>,
            datasets: [
                { label: 'Target', data: <?= json_encode($bkTarget) ?>, backgroundColor: '#2563eb33', borderColor: '#2563eb', borderWidth: 1, borderRadius: 4 },
                { label: 'Actual (FP)', data: <?= json_encode($bkActual) ?>, backgroundColor: '#16a34a', borderRadius: 4 }
            ]
        },
        options: {
            responsive: true, maintainAspectRatio: false,
            plugins: { legend: { position: 'top', labels: { font: { size: 11 } } } },
            scales: { y: { ticks: { callback: v => v>=1000?(v/1000)+'k':v } } }
        }
    });
});
</script>

<?php endif; ?>

</div>
</body>
</html>
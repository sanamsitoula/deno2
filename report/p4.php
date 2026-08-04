<?php
// ═══════════════════════════════════════════════════════════════════════════
// CALENDAR BASED PRODUCTION REPORT  –  Full-Page Month View
// Extends: Production Process Control Dashboard
// Path: /deno2/reports/calendar_production_report.php
// ═══════════════════════════════════════════════════════════════════════════

ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ─── Permissions ─────────────────────────────────────────────────────────
if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: Day-Wise Detail
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'day_detail') {
    header('Content-Type: application/json');
    $date      = $_GET['date']           ?? '';
    $fy_id     = (int)($_GET['fiscal_year'] ?? 0);
    $book_code = trim($_GET['book_code'] ?? '');

    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['success' => false, 'message' => 'Invalid date']); exit();
    }
    try {
        // ── Forma Printing ────────────────────────────────────────────────
        $fp_w = ["fp.created_date::date = :date", "fp.status = true"];
        $fp_p = [':date' => $date];
        if ($fy_id)     { $fp_w[] = "jt.fiscal_year_id = :fy"; $fp_p[':fy'] = $fy_id; }
        if ($book_code) { $fp_w[] = "b.book_code = :bc";        $fp_p[':bc'] = $book_code; }
        $st = $conn->prepare("
            SELECT jt.job_ticket_code, b.book_code, b.book_name,
                   jt.class AS class_level,
                   COALESCE(SUM(fp.fp_printqty),0) AS fp_qty,
                   fp.operator_name, fp.machine_name, fp.shift,
                   fp.created_date AS entry_time, fp.status
            FROM forma_printing fp
            JOIN job_ticket jt ON jt.id = fp.jt_id
            JOIN books b ON b.book_id = jt.book_id
            WHERE " . implode(' AND ', $fp_w) . "
            GROUP BY jt.job_ticket_code,b.book_code,b.book_name,jt.class,
                     fp.operator_name,fp.machine_name,fp.shift,fp.created_date,fp.status
            ORDER BY fp.created_date DESC
        ");
        foreach ($fp_p as $k=>$v) $st->bindValue($k,$v);
        $st->execute(); $fp_rows = $st->fetchAll(PDO::FETCH_ASSOC);

        // ── Book Packing ──────────────────────────────────────────────────
        $bp_w = ["bp.created_date::date = :date", "bp.status = true"];
        $bp_p = [':date' => $date];
        if ($fy_id)     { $bp_w[] = "jt.fiscal_year_id = :fy"; $bp_p[':fy'] = $fy_id; }
        if ($book_code) { $bp_w[] = "b.book_code = :bc";        $bp_p[':bc'] = $book_code; }
        $st2 = $conn->prepare("
            SELECT jt.job_ticket_code, b.book_code, COALESCE(SUM(bp.p_qty),0) AS bp_qty
            FROM book_packing bp
            JOIN job_ticket jt ON jt.id = bp.jt_id
            JOIN books b ON b.book_id = jt.book_id
            WHERE " . implode(' AND ', $bp_w) . "
            GROUP BY jt.job_ticket_code, b.book_code
        ");
        foreach ($bp_p as $k=>$v) $st2->bindValue($k,$v);
        $st2->execute();
        $bp_map = array_column($st2->fetchAll(PDO::FETCH_ASSOC), null, 'job_ticket_code');

        // ── Deno ─────────────────────────────────────────────────────────
        $dn_w = ["d.deno_date_eng::date = :date", "d.deleted_at IS NULL"];
        $dn_p = [':date' => $date];
        if ($book_code) { $dn_w[] = "d.book_code = :bc"; $dn_p[':bc'] = $book_code; }
        $st3 = $conn->prepare("
            SELECT d.book_code, COALESCE(SUM(d.total_qty),0) AS deno_qty, d.ref_no
            FROM deno d WHERE " . implode(' AND ', $dn_w) . " GROUP BY d.book_code, d.ref_no
        ");
        foreach ($dn_p as $k=>$v) $st3->bindValue($k,$v);
        $st3->execute(); $deno_rows = $st3->fetchAll(PDO::FETCH_ASSOC);

        // ── D2M ──────────────────────────────────────────────────────────
        $d2_w = ["dm.eng_date::date = :date", "dm.deleted_at IS NULL", "dm.status <> 'CANCELLED'"];
        $d2_p = [':date' => $date];
        if ($book_code) { $d2_w[] = "di.book_code = :bc"; $d2_p[':bc'] = $book_code; }
        $st4 = $conn->prepare("
            SELECT di.book_code, dm.d2m_no, COALESCE(SUM(di.total_qty),0) AS d2m_qty, dm.status
            FROM d2m_items di JOIN d2m dm ON dm.id = di.d2m_id
            WHERE " . implode(' AND ', $d2_w) . " GROUP BY di.book_code, dm.d2m_no, dm.status
        ");
        foreach ($d2_p as $k=>$v) $st4->bindValue($k,$v);
        $st4->execute(); $d2m_rows = $st4->fetchAll(PDO::FETCH_ASSOC);

        // ── Machine/Operator Summary ──────────────────────────────────────
        $st5 = $conn->prepare("
            SELECT fp.machine_name, fp.operator_name, fp.shift,
                   COALESCE(SUM(fp.fp_printqty),0) AS total_qty, COUNT(fp.id) AS entry_count
            FROM forma_printing fp
            JOIN job_ticket jt ON jt.id = fp.jt_id
            WHERE fp.created_date::date = :date AND fp.status = true
            GROUP BY fp.machine_name, fp.operator_name, fp.shift ORDER BY total_qty DESC
        ");
        $st5->bindValue(':date', $date); $st5->execute();
        $machine_summary = $st5->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode(['success'=>true,'date'=>$date,'fp_rows'=>$fp_rows,
            'bp_map'=>$bp_map,'deno_rows'=>$deno_rows,'d2m_rows'=>$d2m_rows,
            'machine_summary'=>$machine_summary]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════
// AJAX: Monthly Calendar Totals
// ═══════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'month_data') {
    header('Content-Type: application/json');
    $year      = (int)($_GET['year']        ?? date('Y'));
    $month     = (int)($_GET['month']       ?? date('n'));
    $fy_id     = (int)($_GET['fiscal_year'] ?? 0);
    $book_code = trim($_GET['book_code']    ?? '');

    $df = sprintf('%04d-%02d-01', $year, $month);
    $dt = date('Y-m-t', strtotime($df));

    try {
        // FP
        $fp_w = ["fp.created_date::date BETWEEN :df AND :dt","fp.status = true"];
        $fp_p = [':df'=>$df,':dt'=>$dt];
        if ($fy_id)     { $fp_w[] = "jt.fiscal_year_id = :fy"; $fp_p[':fy'] = $fy_id; }
        if ($book_code) { $fp_w[] = "b.book_code = :bc";        $fp_p[':bc'] = $book_code; }
        $st = $conn->prepare("
            SELECT fp.created_date::date AS day,
                   COALESCE(SUM(fp.fp_printqty),0) AS fp_total,
                   COUNT(DISTINCT jt.id) AS jt_count
            FROM forma_printing fp
            JOIN job_ticket jt ON jt.id = fp.jt_id
            JOIN books b ON b.book_id = jt.book_id
            WHERE ".implode(' AND ',$fp_w)." GROUP BY fp.created_date::date
        ");
        foreach($fp_p as $k=>$v) $st->bindValue($k,$v); $st->execute();
        $fp_data = array_column($st->fetchAll(PDO::FETCH_ASSOC),null,'day');

        // BP
        $bp_w = ["bp.created_date::date BETWEEN :df AND :dt","bp.status = true"];
        $bp_p = [':df'=>$df,':dt'=>$dt];
        if ($fy_id)     { $bp_w[] = "jt.fiscal_year_id = :fy"; $bp_p[':fy'] = $fy_id; }
        if ($book_code) { $bp_w[] = "b.book_code = :bc";        $bp_p[':bc'] = $book_code; }
        $st2 = $conn->prepare("
            SELECT bp.created_date::date AS day, COALESCE(SUM(bp.p_qty),0) AS bp_total
            FROM book_packing bp
            JOIN job_ticket jt ON jt.id = bp.jt_id
            JOIN books b ON b.book_id = jt.book_id
            WHERE ".implode(' AND ',$bp_w)." GROUP BY bp.created_date::date
        ");
        foreach($bp_p as $k=>$v) $st2->bindValue($k,$v); $st2->execute();
        $bp_data = array_column($st2->fetchAll(PDO::FETCH_ASSOC),null,'day');

        // Deno
        $dn_w = ["d.deno_date_eng::date BETWEEN :df AND :dt","d.deleted_at IS NULL"];
        $dn_p = [':df'=>$df,':dt'=>$dt];
        if ($book_code) { $dn_w[] = "d.book_code = :bc"; $dn_p[':bc'] = $book_code; }
        $st3 = $conn->prepare("
            SELECT d.deno_date_eng::date AS day, COALESCE(SUM(d.total_qty),0) AS deno_total
            FROM deno d WHERE ".implode(' AND ',$dn_w)." GROUP BY d.deno_date_eng::date
        ");
        foreach($dn_p as $k=>$v) $st3->bindValue($k,$v); $st3->execute();
        $dn_data = array_column($st3->fetchAll(PDO::FETCH_ASSOC),null,'day');

        // D2M
        $d2_w = ["dm.eng_date::date BETWEEN :df AND :dt","dm.deleted_at IS NULL","dm.status <> 'CANCELLED'"];
        $d2_p = [':df'=>$df,':dt'=>$dt];
        if ($book_code) { $d2_w[] = "di.book_code = :bc"; $d2_p[':bc'] = $book_code; }
        $st4 = $conn->prepare("
            SELECT dm.eng_date::date AS day, COALESCE(SUM(di.total_qty),0) AS d2m_total
            FROM d2m_items di JOIN d2m dm ON dm.id = di.d2m_id
            WHERE ".implode(' AND ',$d2_w)." GROUP BY dm.eng_date::date
        ");
        foreach($d2_p as $k=>$v) $st4->bindValue($k,$v); $st4->execute();
        $d2_data = array_column($st4->fetchAll(PDO::FETCH_ASSOC),null,'day');

        // Assemble per-day
        $days = [];
        $cur = new DateTime($df); $end = new DateTime($dt);
        while ($cur <= $end) {
            $d = $cur->format('Y-m-d');
            $days[$d] = [
                'fp'  => (int)($fp_data[$d]['fp_total']   ?? 0),
                'bp'  => (int)($bp_data[$d]['bp_total']   ?? 0),
                'deno'=> (int)($dn_data[$d]['deno_total'] ?? 0),
                'd2m' => (int)($d2_data[$d]['d2m_total']  ?? 0),
                'jt'  => (int)($fp_data[$d]['jt_count']   ?? 0),
            ];
            $cur->modify('+1 day');
        }
        $summary = [
            'fp_total'  => array_sum(array_column($fp_data,'fp_total')),
            'bp_total'  => array_sum(array_column($bp_data,'bp_total')),
            'deno_total'=> array_sum(array_column($dn_data,'deno_total')),
            'd2m_total' => array_sum(array_column($d2_data,'d2m_total')),
            'jt_total'  => array_sum(array_column($fp_data,'jt_count')),
        ];
        echo json_encode(['success'=>true,'days'=>$days,'summary'=>$summary]);
    } catch (Exception $e) {
        echo json_encode(['success'=>false,'message'=>$e->getMessage()]);
    }
    exit();
}

// ═══════════════════════════════════════════════════════════════════════════
// PAGE RENDER
// ═══════════════════════════════════════════════════════════════════════════
$fiscal_years   = $conn->query("SELECT id,fiscal_code,fiscal_name FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_fy      = $conn->query("SELECT id FROM fiscal_years WHERE is_active=true LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$books_dropdown = $conn->query("SELECT DISTINCT book_code,book_name FROM books ORDER BY book_code ASC")->fetchAll(PDO::FETCH_ASSOC);

$fiscal_year_filter = $_GET['fiscal_year'] ?? ($active_fy['id'] ?? '');
$book_code_filter   = $_GET['book_code']   ?? '';
$cal_year           = (int)($_GET['cal_year']  ?? date('Y'));
$cal_month          = (int)($_GET['cal_month'] ?? date('n'));
$cal_month = max(1, min(12, $cal_month));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Calendar Production Report</title>
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet"/>
<style>
/* ══ Root variables — matches existing PPC file ═══════════════════════ */
:root {
  --bg:         #ffffff;
  --surface:    #f8fafc;
  --surface2:   #f1f5f9;
  --border:     #e2e8f0;
  --accent:     #4f46e5;
  --text:       #1e293b;
  --text-muted: #64748b;
  --success:    #10b981;
  --warning:    #f59e0b;
  --danger:     #ef4444;
  --radius:     10px;
  --fp-color:   #3b82f6;
  --bp-color:   #10b981;
  --dn-color:   #f59e0b;
  --d2m-color:  #8b5cf6;
  --today-bg:   #fffbeb;
  --today-bdr:  #f59e0b;
  --hdr-h:      44px;  /* top bar height */
  --nav-h:      52px;  /* cal nav height  */
  --sum-h:      80px;  /* summary row     */
  --wkd-h:      32px;  /* weekday labels  */
}
@media (prefers-color-scheme: dark) {
  :root {
    --bg:#0f172a; --surface:#1e293b; --surface2:#273448;
    --border:#334155; --text:#f1f5f9; --text-muted:#94a3b8;
    --today-bg:#2d2500; --today-bdr:#d97706;
  }
}

/* ── Reset / base ─────────────────────────────────────────────────── */
*, *::before, *::after { box-sizing: border-box; margin:0; padding:0; }
html, body { height:100%; overflow:hidden; }  /* full viewport, no scroll */
body { background:var(--bg); color:var(--text);
       font-family:'Segoe UI',system-ui,sans-serif; font-size:13px; display:flex; flex-direction:column; }

/* ── Top bar ──────────────────────────────────────────────────────── */
.top-bar {
  height: var(--hdr-h);
  display: flex; align-items: center; gap:10px; flex-shrink:0;
  padding: 0 16px;
  background: var(--surface); border-bottom: 1px solid var(--border);
}
.top-bar .page-title { font-size:15px; font-weight:700; margin-right:auto; }
.top-bar .page-subtitle { font-size:11px; color:var(--text-muted); }

/* ── Summary strip ────────────────────────────────────────────────── */
.sum-strip {
  height: var(--sum-h); flex-shrink:0;
  display: flex; gap:2px;
  background: var(--border);
  border-bottom: 1px solid var(--border);
}
.sum-card {
  flex:1; display:flex; flex-direction:column; justify-content:center;
  padding: 0 14px; background:var(--bg); position:relative; overflow:hidden;
}
.sum-card::before {
  content:''; position:absolute; top:0; left:0; right:0; height:3px;
  background:var(--ac, var(--accent));
}
.sum-card .sv { font-size:18px; font-weight:800; line-height:1.1; }
.sum-card .sl { font-size:10px; font-weight:600; color:var(--text-muted); text-transform:uppercase; letter-spacing:.4px; margin-top:3px; }

/* ── Calendar navigation ──────────────────────────────────────────── */
.cal-nav {
  height: var(--nav-h); flex-shrink:0;
  display: flex; align-items:center; gap:8px; padding:0 14px;
  background: var(--surface); border-bottom:1px solid var(--border);
}
.cal-nav-title { font-size:16px; font-weight:700; }
.nep-badge {
  background:var(--surface2); border:1px solid var(--border);
  border-radius:20px; padding:3px 10px; font-size:11px; font-weight:600; color:var(--text-muted);
}
.nav-btn {
  background:var(--bg); border:1px solid var(--border); border-radius:6px;
  padding:5px 11px; font-size:12px; font-weight:700; cursor:pointer;
  color:var(--text); transition:.15s; white-space:nowrap;
}
.nav-btn:hover     { background:var(--accent); color:#fff; border-color:var(--accent); }
.nav-btn.active    { background:var(--accent); color:#fff; border-color:var(--accent); }
.filter-inline {
  display:flex; gap:6px; align-items:center; margin-left:auto; flex-shrink:0;
}
.fc { background:var(--bg); border:1px solid var(--border); border-radius:6px;
       color:var(--text); padding:5px 8px; font-size:12px; outline:none; }
.fc:focus { border-color:var(--accent); }

/* ── Calendar body wrapper — fills remaining height ───────────────── */
.cal-wrap {
  flex:1; display:flex; flex-direction:column; overflow:hidden; position:relative;
}

/* ── Weekday header row ───────────────────────────────────────────── */
.wk-row {
  display:grid; grid-template-columns:repeat(7,1fr);
  height: var(--wkd-h); flex-shrink:0;
  gap:2px; background:var(--border);
}
.wk-cell {
  background:var(--surface2); display:flex; align-items:center; justify-content:center;
  font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase; letter-spacing:.5px;
}
/* Saturday = blue, Sunday = red */
.wk-cell.sat { color:var(--fp-color); }
.wk-cell.sun { color:var(--danger); }

/* ── Day grid — fills ALL remaining height ────────────────────────── */
.day-grid {
  flex:1; display:grid;
  grid-template-columns: repeat(7, 1fr);
  /* rows set dynamically by JS: repeat(N, 1fr) */
  gap:2px; background:var(--border); overflow:hidden;
}

/* ── Individual day cell ──────────────────────────────────────────── */
.day-cell {
  background:var(--bg); overflow:hidden; display:flex; flex-direction:column;
  cursor:pointer; transition:background .12s; position:relative;
  min-height:0; /* important: allow flex shrink */
}
.day-cell:hover { background:rgba(79,70,229,.06); }
.day-cell.is-today  { background:var(--today-bg); }
.day-cell.is-today  { outline:2px solid var(--today-bdr); outline-offset:-2px; }
.day-cell.is-sat    { background: color-mix(in srgb, var(--fp-color) 4%, var(--bg)); }
.day-cell.is-sun    { background: color-mix(in srgb, var(--danger) 5%, var(--bg)); }
.day-cell.other-mo  { background:var(--surface); opacity:.45; cursor:default; pointer-events:none; }
.day-cell.has-data  { border-top:2px solid var(--accent); }

/* ── Cell: date header ────────────────────────────────────────────── */
.dc-head {
  display:flex; justify-content:space-between; align-items:flex-start;
  padding:5px 7px 3px; flex-shrink:0; border-bottom:1px solid var(--border);
}
.dc-eng { font-size:15px; font-weight:800; line-height:1; }
.dc-nep { font-size:9px; font-weight:600; color:var(--text-muted); text-align:right; line-height:1.3; }
.is-today .dc-eng { color:var(--accent); }
.is-sat   .dc-eng { color:var(--fp-color); }
.is-sun   .dc-eng { color:var(--danger); }

/* ── Cell: metrics area ───────────────────────────────────────────── */
.dc-metrics {
  flex:1; display:flex; flex-direction:column; justify-content:space-evenly;
  padding:4px 7px; gap:2px; min-height:0;
}
.dc-row {
  display:flex; align-items:center; gap:4px;
  font-size:10px; font-weight:700; line-height:1; white-space:nowrap;
}
.dc-dot   { width:7px; height:7px; border-radius:50%; flex-shrink:0; }
.dc-label { color:var(--text-muted); font-weight:600; min-width:26px; }
.dc-val   { font-size:11px; font-weight:800; }
.dc-val.zero { color:var(--text-muted); font-weight:500; opacity:.6; }

/* JT row */
.dc-jt {
  margin-top:auto; padding:2px 7px; font-size:9px; font-weight:600;
  color:var(--text-muted); border-top:1px solid var(--border); flex-shrink:0;
  display:flex; gap:4px; align-items:center;
}

/* ── Loading overlay ──────────────────────────────────────────────── */
.cal-overlay {
  position:absolute; inset:0; background:rgba(255,255,255,.65);
  display:none; align-items:center; justify-content:center; z-index:20;
  backdrop-filter:blur(3px);
}
@media(prefers-color-scheme:dark){ .cal-overlay{ background:rgba(15,23,42,.7); } }
.cal-overlay.show { display:flex; }
.spinner {
  width:36px; height:36px; border:3px solid var(--border);
  border-top-color:var(--accent); border-radius:50%;
  animation:spin .7s linear infinite;
}
@keyframes spin{ to{transform:rotate(360deg)} }

/* ── Legend bar ───────────────────────────────────────────────────── */
.legend {
  display:flex; gap:14px; flex-wrap:wrap; align-items:center;
  font-size:10px; font-weight:600; color:var(--text-muted); margin-left:10px;
}
.li { display:flex; align-items:center; gap:4px; }
.ld { width:9px; height:9px; border-radius:50%; }

/* ── Buttons ──────────────────────────────────────────────────────── */
.btn-x {
  display:inline-flex; align-items:center; gap:4px; padding:5px 11px;
  border:none; border-radius:6px; font-size:11px; font-weight:700;
  cursor:pointer; transition:.15s; text-decoration:none; white-space:nowrap;
}
.btn-x:hover { transform:translateY(-1px); box-shadow:0 3px 8px rgba(0,0,0,.1); }
.btn-pri  { background:var(--accent);  color:#fff; }
.btn-sec  { background:var(--surface2); color:var(--text); border:1px solid var(--border); }
.btn-grn  { background:var(--success); color:#fff; }

/* ── Modal ────────────────────────────────────────────────────────── */
.modal-content { background:var(--bg); border:1px solid var(--border); border-radius:var(--radius); }
.modal-header  { border-bottom:1px solid var(--border); padding:14px 18px; }
.modal-body    { padding:18px; max-height:72vh; overflow-y:auto; }
.modal-footer  { border-top:1px solid var(--border); padding:10px 18px; }

.ds { margin-bottom:18px; }
.ds-title {
  font-size:11px; font-weight:700; color:var(--text-muted); text-transform:uppercase;
  letter-spacing:.5px; margin-bottom:7px; display:flex; align-items:center; gap:6px;
}
.ds-title::after { content:''; flex:1; height:1px; background:var(--border); }

.dt { width:100%; border-collapse:collapse; font-size:12px; }
.dt th { background:var(--surface2); padding:7px 9px; text-align:left;
          font-size:10px; font-weight:700; text-transform:uppercase; letter-spacing:.4px;
          color:var(--text-muted); border-bottom:1px solid var(--border); }
.dt td { padding:7px 9px; border-bottom:1px solid var(--border); }
.dt tr:hover td { background:var(--surface); }
.dt .r { text-align:right; font-weight:700; }

.sb { display:inline-block; padding:2px 7px; border-radius:20px;
       font-size:9px; font-weight:700; text-transform:uppercase; }
.sb-a { background:#d1fae5; color:#065f46; }
.sb-p { background:#fef3c7; color:#92400e; }
.sb-d { background:#e0e7ff; color:#3730a3; }
.sb-c { background:#fee2e2; color:#991b1b; }

/* ── Responsive: shrink text on small screens ─────────────────────── */
@media(max-width:900px){
  .dc-val { font-size:10px; }
  .dc-eng { font-size:13px; }
  .dc-label { min-width:22px; }
}
@media(max-width:640px){
  .dc-nep, .dc-label, .dc-jt { display:none; }
  .dc-val { font-size:9px; }
  .dc-eng { font-size:12px; }
}
</style>
</head>
<body>

<!-- ══ TOP BAR ════════════════════════════════════════════════════════════ -->
<div class="top-bar">
  <div>
    <div class="page-title">📅 Calendar Production Report</div>
  </div>
  <div class="legend">
    <span class="li"><span class="ld" style="background:var(--fp-color)"></span>Forma Print</span>
    <span class="li"><span class="ld" style="background:var(--bp-color)"></span>Book Pack</span>
    <span class="li"><span class="ld" style="background:var(--dn-color)"></span>Deno</span>
    <span class="li"><span class="ld" style="background:var(--d2m-color)"></span>D2M</span>
    <span class="li"><span class="ld" style="background:var(--today-bdr);border:1px solid #92400e"></span>Today</span>
  </div>
  <a href="index.php" class="btn-x btn-sec" style="margin-left:auto">← Dashboard</a>
  <button class="btn-x btn-pri" id="exportMonthBtn">⬇ Export</button>
</div>

<!-- ══ SUMMARY STRIP ══════════════════════════════════════════════════════ -->
<div class="sum-strip">
  <?php
  $sc = [
    ['id'=>'s-fp',  'lbl'=>'🖨 Forma Printed', 'ac'=>'#3b82f6'],
    ['id'=>'s-bp',  'lbl'=>'📦 Book Packed',   'ac'=>'#10b981'],
    ['id'=>'s-dn',  'lbl'=>'🚚 Deno Qty',      'ac'=>'#f59e0b'],
    ['id'=>'s-d2m', 'lbl'=>'🏭 D2M Qty',       'ac'=>'#8b5cf6'],
    ['id'=>'s-jt',  'lbl'=>'📋 Job Tickets',   'ac'=>'#6366f1'],
  ];
  foreach($sc as $c): ?>
  <div class="sum-card" style="--ac:<?= $c['ac'] ?>">
    <div class="sv" id="<?= $c['id'] ?>">—</div>
    <div class="sl"><?= $c['lbl'] ?></div>
  </div>
  <?php endforeach; ?>
</div>

<!-- ══ CALENDAR NAV ═══════════════════════════════════════════════════════ -->
<div class="cal-nav">
  <button class="nav-btn" id="prevBtn">‹</button>
  <button class="nav-btn active" id="todayBtn">Today</button>
  <button class="nav-btn" id="nextBtn">›</button>
  <span class="cal-nav-title" id="calTitle">—</span>
  <span class="nep-badge" id="nepBadge">BS —</span>

  <!-- Inline filters -->
  <div class="filter-inline">
    <select id="filterFY" class="fc">
      <option value="">All FY</option>
      <?php foreach($fiscal_years as $fy): ?>
      <option value="<?= $fy['id'] ?>" <?= ($fiscal_year_filter==$fy['id'])?'selected':'' ?>>
        <?= htmlspecialchars($fy['fiscal_code'].' – '.$fy['fiscal_name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <select id="filterBook" class="fc">
      <option value="">All Books</option>
      <?php foreach($books_dropdown as $bk): ?>
      <option value="<?= htmlspecialchars($bk['book_code']) ?>" <?= ($book_code_filter===$bk['book_code'])?'selected':'' ?>>
        <?= htmlspecialchars($bk['book_code'].' – '.$bk['book_name']) ?>
      </option>
      <?php endforeach; ?>
    </select>
    <input type="month" id="jumpMonth" class="fc"
           value="<?= sprintf('%04d-%02d',$cal_year,$cal_month) ?>">
    <button class="btn-x btn-pri" id="applyBtn">Apply</button>
    <button class="btn-x btn-sec" id="resetBtn">Reset</button>
  </div>
</div>

<!-- ══ CALENDAR ═══════════════════════════════════════════════════════════ -->
<div class="cal-wrap">
  <!-- Loading overlay -->
  <div class="cal-overlay" id="calOverlay"><div class="spinner"></div></div>

  <!-- Weekday row -->
  <div class="wk-row">
    <div class="wk-cell sun">Sun</div>
    <div class="wk-cell">Mon</div>
    <div class="wk-cell">Tue</div>
    <div class="wk-cell">Wed</div>
    <div class="wk-cell">Thu</div>
    <div class="wk-cell">Fri</div>
    <div class="wk-cell sat">Sat</div>
  </div>

  <!-- Day cells injected by JS -->
  <div class="day-grid" id="dayGrid"></div>
</div>

<!-- ══ DAY DETAIL MODAL ═══════════════════════════════════════════════════ -->
<div class="modal fade" id="detailModal" tabindex="-1">
  <div class="modal-dialog modal-xl modal-dialog-scrollable">
    <div class="modal-content">
      <div class="modal-header">
        <div>
          <h5 class="modal-title fw-bold" id="modalTitle">—</h5>
          <small class="text-muted" id="modalSub"></small>
        </div>
        <div style="display:flex;gap:6px;margin-left:auto;align-items:center">
          <button class="btn-x btn-grn" id="xlBtn">⬇ Excel</button>
          <button class="btn-x btn-sec" id="pdfBtn">📄 PDF</button>
          <button class="btn-x btn-sec" onclick="window.print()">🖨 Print</button>
          <button type="button" class="btn-close ms-2" data-bs-dismiss="modal"></button>
        </div>
      </div>
      <div class="modal-body" id="modalBody">
        <div style="text-align:center;padding:40px;color:var(--text-muted)">
          <div class="spinner" style="margin:0 auto 14px"></div>Loading…
        </div>
      </div>
      <div class="modal-footer">
        <span id="modalTotals" style="font-size:11px;color:var(--text-muted);margin-right:auto"></span>
        <button type="button" class="btn-x btn-sec" data-bs-dismiss="modal">Close</button>
      </div>
    </div>
  </div>
</div>

<!-- ══ SCRIPTS ════════════════════════════════════════════════════════════ -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/xlsx@0.18.5/dist/xlsx.full.min.js"></script>

<script>
/* ════════════════════════════════════════════════════════════════════════
   CALENDAR PRODUCTION REPORT — JS
   Full-page: each row stretches to fill 100% viewport height evenly.
   All 4 metrics (FP/BP/DN/D2M) always rendered in every current-month cell.
   ════════════════════════════════════════════════════════════════════════ */

// ── State ──────────────────────────────────────────────────────────────
let CY = <?= $cal_year ?>, CM = <?= $cal_month ?>; // current year/month (1-based)
let calData = {};       // { 'YYYY-MM-DD': {fp,bp,deno,d2m,jt}, … }
let activeDate = null;  // date string of open modal

const MN = ['January','February','March','April','May','June',
            'July','August','September','October','November','December'];
const NEP_M = ['Baisakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
               'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];

// ── Helpers ────────────────────────────────────────────────────────────
const pad = n => String(n).padStart(2,'0');

function fmt(n) {          // quantity formatter: 125000 → 125K
    n = parseInt(n)||0;
    if(n>=1e6) return (n/1e6).toFixed(1).replace(/\.0$/,'')+'M';
    if(n>=1000) return (n/1000).toFixed(1).replace(/\.0$/,'')+'K';
    return n.toLocaleString();
}

function engToNep(y,m,d) {
    try {
        if(typeof NepaliFunctions==='undefined') return '';
        const r = NepaliFunctions.AD2BS(NepaliFunctions.ParseDate(`${y}-${pad(m)}-${pad(d)}`));
        return `${r.year}-${pad(r.month)}-${pad(r.date)}`;
    } catch(e){ return ''; }
}
function nepMonthLabel(y,m) {
    try {
        if(typeof NepaliFunctions==='undefined') return '—';
        const r = NepaliFunctions.AD2BS(NepaliFunctions.ParseDate(`${y}-${pad(m)}-15`));
        return NEP_M[r.month-1]+' '+r.year;
    } catch(e){ return '—'; }
}
function esc(s){ return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;'); }
function fmtDT(s){
    if(!s) return '—';
    try{ const d=new Date(s); return d.toLocaleDateString()+' '+d.toLocaleTimeString([],{hour:'2-digit',minute:'2-digit'}); }
    catch(e){ return s; }
}

// ── Build & render calendar ────────────────────────────────────────────
function buildCalendar() {
    const grid = document.getElementById('dayGrid');
    grid.innerHTML = '';

    // Update title / badge
    document.getElementById('calTitle').textContent = MN[CM-1]+' '+CY;
    document.getElementById('nepBadge').textContent = 'BS '+nepMonthLabel(CY,CM);
    document.getElementById('jumpMonth').value = `${CY}-${pad(CM)}`;

    const today      = new Date();
    const todayStr   = `${today.getFullYear()}-${pad(today.getMonth()+1)}-${pad(today.getDate())}`;
    const firstDow   = new Date(CY, CM-1, 1).getDay();   // 0=Sun
    const daysInMo   = new Date(CY, CM, 0).getDate();    // 28-31
    const prevDays   = new Date(CY, CM-1, 0).getDate();  // days in prev month
    const totalCells = firstDow + daysInMo;
    const rows       = Math.ceil(totalCells / 7);         // 4 or 5 or 6

    // Tell CSS how many equal rows to create
    grid.style.gridTemplateRows = `repeat(${rows}, 1fr)`;

    // ── Previous-month ghost cells ──────────────────────────────────────
    for(let i=0; i<firstDow; i++){
        const dn = prevDays - firstDow + i + 1;
        grid.appendChild(makeCell(null, dn, true, false, false, false, null));
    }

    // ── Current-month cells ─────────────────────────────────────────────
    for(let d=1; d<=daysInMo; d++){
        const ds  = `${CY}-${pad(CM)}-${pad(d)}`;
        const dow = new Date(CY, CM-1, d).getDay();
        grid.appendChild(makeCell(
            ds, d, false,
            ds===todayStr,
            dow===6,   // Saturday
            dow===0,   // Sunday
            calData[ds] || {fp:0,bp:0,deno:0,d2m:0,jt:0}
        ));
    }

    // ── Next-month ghost cells ──────────────────────────────────────────
    const trailing = rows*7 - totalCells;
    for(let i=1; i<=trailing; i++){
        grid.appendChild(makeCell(null, i, true, false, false, false, null));
    }
}

// ── Create one day cell ────────────────────────────────────────────────
function makeCell(ds, dn, ghost, isToday, isSat, isSun, data){
    const el = document.createElement('div');
    el.className = 'day-cell'
        + (ghost   ? ' other-mo' : '')
        + (isToday ? ' is-today' : '')
        + (isSat   ? ' is-sat'   : '')
        + (isSun   ? ' is-sun'   : '')
        + (!ghost && data && (data.fp||data.bp||data.deno||data.d2m) ? ' has-data' : '');

    // ── Date header ─────────────────────────────────────────────────────
    const head = document.createElement('div');
    head.className = 'dc-head';
    const nepStr = (!ghost && ds) ? engToNep(...ds.split('-').map(Number)) : '';
    head.innerHTML =
        `<span class="dc-eng">${dn}</span>` +
        (nepStr ? `<span class="dc-nep">${nepStr}<br><span style="font-size:8px;opacity:.7">BS</span></span>` : '');
    el.appendChild(head);

    // ── Metrics (always render all 4 rows for current-month cells) ──────
    if(!ghost){
        const metrics = document.createElement('div');
        metrics.className = 'dc-metrics';

        const rows = [
            {key:'fp',  lbl:'FP',  col:'var(--fp-color)' },
            {key:'bp',  lbl:'BP',  col:'var(--bp-color)' },
            {key:'deno',lbl:'DN',  col:'var(--dn-color)'  },
            {key:'d2m', lbl:'D2M', col:'var(--d2m-color)'},
        ];

        rows.forEach(({key,lbl,col})=>{
            const val = data ? (data[key]||0) : 0;
            const row = document.createElement('div');
            row.className = 'dc-row';
            row.innerHTML =
                `<span class="dc-dot" style="background:${col}"></span>` +
                `<span class="dc-label">${lbl}</span>` +
                `<span class="dc-val${val===0?' zero':''}">${val===0?'—':fmt(val)}</span>`;
            metrics.appendChild(row);
        });

        el.appendChild(metrics);

        // JT count footer
        const jt = document.createElement('div');
        jt.className = 'dc-jt';
        const jtVal = data ? (data.jt||0) : 0;
        jt.innerHTML = `<span style="color:var(--accent)">●</span> ${jtVal} JT`;
        el.appendChild(jt);

        // Click → modal
        if(ds){
            el.addEventListener('click', ()=> openModal(ds, nepStr));
        }
    }

    return el;
}

// ── Load month data via AJAX ───────────────────────────────────────────
function loadMonth(){
    document.getElementById('calOverlay').classList.add('show');
    const fy   = document.getElementById('filterFY').value;
    const book = document.getElementById('filterBook').value;
    const p    = new URLSearchParams({ajax:'month_data',year:CY,month:CM,fiscal_year:fy,book_code:book});

    fetch('?'+p)
        .then(r=>r.json())
        .then(j=>{
            calData = j.success ? (j.days||{}) : {};
            buildCalendar();
            if(j.success) updateSummary(j.summary||{});
        })
        .catch(()=>{ calData={}; buildCalendar(); })
        .finally(()=> document.getElementById('calOverlay').classList.remove('show'));
}

function updateSummary(s){
    document.getElementById('s-fp') .textContent = fmt(s.fp_total  ||0);
    document.getElementById('s-bp') .textContent = fmt(s.bp_total  ||0);
    document.getElementById('s-dn') .textContent = fmt(s.deno_total||0);
    document.getElementById('s-d2m').textContent  = fmt(s.d2m_total ||0);
    document.getElementById('s-jt') .textContent  = fmt(s.jt_total  ||0);
}

// ── Day detail modal ───────────────────────────────────────────────────
const modalBS = new bootstrap.Modal(document.getElementById('detailModal'));

function openModal(ds, nepStr){
    activeDate = ds;
    document.getElementById('modalTitle').textContent = 'Production Detail — '+ds;
    document.getElementById('modalSub').textContent   = nepStr ? nepStr+' (BS)' : '';
    document.getElementById('modalBody').innerHTML    =
        '<div style="text-align:center;padding:40px;color:var(--text-muted)"><div class="spinner" style="margin:0 auto 14px"></div>Loading…</div>';
    document.getElementById('modalTotals').textContent = '';
    modalBS.show();

    const fy   = document.getElementById('filterFY').value;
    const book = document.getElementById('filterBook').value;
    const p    = new URLSearchParams({ajax:'day_detail',date:ds,fiscal_year:fy,book_code:book});

    fetch('?'+p)
        .then(r=>r.json())
        .then(j=>{
            if(!j.success){ document.getElementById('modalBody').innerHTML=`<div class="alert alert-danger">${esc(j.message)}</div>`; return; }
            renderDetail(j);
        })
        .catch(e=>{ document.getElementById('modalBody').innerHTML=`<div class="alert alert-danger">Network error: ${esc(e.message)}</div>`; });
}

function renderDetail(data){
    const fp  = data.fp_rows        || [];
    const bpm = data.bp_map         || {};
    const dn  = data.deno_rows      || [];
    const d2m = data.d2m_rows       || [];
    const mc  = data.machine_summary|| [];
    let html  = '';

    // ── FP ────────────────────────────────────────────────────────────
    let fpTot=0;
    html += `<div class="ds"><div class="ds-title" style="color:var(--fp-color)">🖨 Forma Printing`;
    html += `</div>`;
    if(fp.length){
        html += `<table class="dt" id="t-fp"><thead><tr>
            <th>JT Code</th><th>Book Code</th><th>Book Name</th><th>Class</th>
            <th class="r">FP Qty</th><th class="r">BP Qty</th>
            <th>Operator</th><th>Machine</th><th>Shift</th><th>Entry Time</th><th>Status</th>
        </tr></thead><tbody>`;
        fp.forEach(r=>{
            const fq=parseInt(r.fp_qty)||0, bq=parseInt((bpm[r.job_ticket_code]||{}).bp_qty)||0;
            fpTot+=fq;
            const sc=r.status?'sb-a':'sb-p';
            html+=`<tr>
              <td><strong>${esc(r.job_ticket_code)}</strong></td>
              <td>${esc(r.book_code)}</td><td>${esc(r.book_name)}</td>
              <td>${r.class_level||'—'}</td>
              <td class="r" style="color:var(--fp-color)">${fq.toLocaleString()}</td>
              <td class="r" style="color:var(--bp-color)">${bq?bq.toLocaleString():'—'}</td>
              <td>${esc(r.operator_name||'—')}</td><td>${esc(r.machine_name||'—')}</td>
              <td>${esc(r.shift||'—')}</td>
              <td style="font-size:10px">${fmtDT(r.entry_time)}</td>
              <td><span class="sb ${sc}">${r.status?'Active':'Pending'}</span></td>
            </tr>`;
        });
        html+=`</tbody><tfoot><tr><td colspan="4" style="font-weight:700;text-align:right">Total</td>
          <td class="r" style="font-weight:800;color:var(--fp-color)">${fpTot.toLocaleString()}</td>
          <td colspan="6"></td></tr></tfoot></table>`;
    } else {
        html += `<p style="color:var(--text-muted);font-size:12px;padding:6px">No Forma Printing records.</p>`;
    }
    html += `</div>`;

    // ── Deno ──────────────────────────────────────────────────────────
    if(dn.length){
        let tot=0;
        html+=`<div class="ds"><div class="ds-title" style="color:var(--dn-color)">🚚 Deno</div>
          <table class="dt" id="t-dn"><thead><tr><th>Book Code</th><th>Ref No</th><th class="r">Qty</th></tr></thead><tbody>`;
        dn.forEach(r=>{ const q=parseInt(r.deno_qty)||0; tot+=q;
            html+=`<tr><td>${esc(r.book_code)}</td><td>${esc(r.ref_no||'—')}</td>
                   <td class="r" style="color:var(--dn-color)">${q.toLocaleString()}</td></tr>`; });
        html+=`</tbody><tfoot><tr><td colspan="2" style="font-weight:700;text-align:right">Total</td>
          <td class="r" style="font-weight:800;color:var(--dn-color)">${tot.toLocaleString()}</td></tr></tfoot></table></div>`;
    }

    // ── D2M ───────────────────────────────────────────────────────────
    if(d2m.length){
        let tot=0;
        html+=`<div class="ds"><div class="ds-title" style="color:var(--d2m-color)">🏭 D2M</div>
          <table class="dt" id="t-d2m"><thead><tr><th>Book Code</th><th>D2M No</th><th class="r">Qty</th><th>Status</th></tr></thead><tbody>`;
        d2m.forEach(r=>{ const q=parseInt(r.d2m_qty)||0; tot+=q;
            const sc={'CLOSE':'sb-d','CANCELLED':'sb-c','DRAFT':'sb-p'}[r.status]||'sb-a';
            html+=`<tr><td>${esc(r.book_code)}</td><td>${esc(r.d2m_no||'—')}</td>
                   <td class="r" style="color:var(--d2m-color)">${q.toLocaleString()}</td>
                   <td><span class="sb ${sc}">${esc(r.status||'—')}</span></td></tr>`; });
        html+=`</tbody><tfoot><tr><td colspan="2" style="font-weight:700;text-align:right">Total</td>
          <td class="r" style="font-weight:800;color:var(--d2m-color)">${tot.toLocaleString()}</td><td></td></tr></tfoot></table></div>`;
    }

    // ── Machine / Operator summary ─────────────────────────────────────
    if(mc.length){
        html+=`<div class="ds"><div class="ds-title">🔧 Machine · Operator Summary</div>
          <table class="dt" id="t-mc"><thead><tr><th>Machine</th><th>Operator</th><th>Shift</th>
          <th class="r">Qty</th><th class="r">Entries</th></tr></thead><tbody>`;
        mc.forEach(r=>{ html+=`<tr>
          <td>${esc(r.machine_name||'—')}</td><td>${esc(r.operator_name||'—')}</td>
          <td>${esc(r.shift||'—')}</td>
          <td class="r">${parseInt(r.total_qty).toLocaleString()}</td>
          <td class="r">${r.entry_count}</td></tr>`; });
        html+=`</tbody></table></div>`;
    }

    document.getElementById('modalBody').innerHTML = html;
    document.getElementById('modalTotals').textContent =
        fp.length ? `FP Total: ${fpTot.toLocaleString()}` : '';
}

// ── Excel export ───────────────────────────────────────────────────────
function exportDayExcel(){
    const wb=XLSX.utils.book_new();
    ['t-fp','t-dn','t-d2m','t-mc'].forEach(id=>{
        const t=document.getElementById(id);
        if(t){ XLSX.utils.book_append_sheet(wb, XLSX.utils.table_to_sheet(t), id.replace('t-','')); }
    });
    XLSX.writeFile(wb, 'production_'+activeDate+'.xlsx');
}

function exportDayPdf(){
    const w=window.open('','_blank');
    w.document.write(`<!DOCTYPE html><html><head><title>Production ${activeDate}</title>
    <style>body{font-family:sans-serif;font-size:12px}table{width:100%;border-collapse:collapse;margin-bottom:16px}
    th,td{border:1px solid #ccc;padding:5px;text-align:left}th{background:#f1f5f9;font-weight:700}
    .r{text-align:right}.ds-title{font-weight:700;font-size:13px;margin:14px 0 6px}</style></head><body>
    <h2>Production Report — ${activeDate}</h2>${document.getElementById('modalBody').innerHTML}</body></html>`);
    w.document.close(); w.focus(); w.print();
}

function exportMonthExcel(){
    const rows=[['Date (AD)','Date (BS)','FP Qty','BP Qty','Deno Qty','D2M Qty','JT Count']];
    Object.entries(calData).sort().forEach(([d,v])=>{
        const pts=d.split('-');
        rows.push([d, engToNep(+pts[0],+pts[1],+pts[2]), v.fp, v.bp, v.deno, v.d2m, v.jt]);
    });
    const wb=XLSX.utils.book_new();
    XLSX.utils.book_append_sheet(wb, XLSX.utils.aoa_to_sheet(rows), 'Monthly');
    XLSX.writeFile(wb, `calendar_${CY}_${pad(CM)}.xlsx`);
}

// ── Navigation ─────────────────────────────────────────────────────────
function prevM(){ CM--; if(CM<1){CM=12;CY--;} loadMonth(); }
function nextM(){ CM++; if(CM>12){CM=1;CY++;} loadMonth(); }
function goToday(){ const n=new Date(); CY=n.getFullYear(); CM=n.getMonth()+1; loadMonth(); }

document.getElementById('prevBtn').addEventListener('click', prevM);
document.getElementById('nextBtn').addEventListener('click', nextM);
document.getElementById('todayBtn').addEventListener('click', goToday);
document.getElementById('applyBtn').addEventListener('click', loadMonth);
document.getElementById('resetBtn').addEventListener('click',()=>{
    document.getElementById('filterFY').value='';
    document.getElementById('filterBook').value='';
    goToday();
});
document.getElementById('jumpMonth').addEventListener('change',function(){
    const p=this.value.split('-');
    if(p.length===2){CY=+p[0];CM=+p[1];loadMonth();}
});
document.getElementById('exportMonthBtn').addEventListener('click', exportMonthExcel);
document.getElementById('xlBtn').addEventListener('click', exportDayExcel);
document.getElementById('pdfBtn').addEventListener('click', exportDayPdf);

document.addEventListener('keydown',e=>{
    if(e.target.tagName==='INPUT'||e.target.tagName==='SELECT') return;
    if(e.key==='ArrowLeft')  prevM();
    if(e.key==='ArrowRight') nextM();
    if(e.key==='t'||e.key==='T') goToday();
});

// ── Init ───────────────────────────────────────────────────────────────
loadMonth();
</script>
</body>
</html>
<?php ob_end_flush(); ?>
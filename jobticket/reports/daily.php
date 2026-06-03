<?php
/**
 * daily.php — Job Ticket vs Deno Production Comparison Report
 *
 * KEY FIXES in this version:
 *  1. Auto-loads the active fiscal year from fiscal_years (is_active = true).
 *  2. Deno date range: deno_date_nep BETWEEN fy.start_date_nep AND fy.end_date_nep
 *     where start_date_nep = YYYY.04.01  (active FY code + ".04.01")
 *     and   end_date_nep   = (FY+1).".03.32"
 *     This matches exactly what the other deno report uses.
 *  3. Deno is matched on book_code + date range (NOT deno_year enum) to avoid
 *     the enum cast issue and capture ALL entries in the fiscal period.
 *  4. Clicking a deno_printed qty opens the deno report with book_code +
 *     date-range filters pre-applied.
 *  5. Exports → job_ticket_export.php (no headers-already-sent).
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ── Load active fiscal year ───────────────────────────────────────────
$active_fy_row = $conn->query("
    SELECT id, fiscal_code, start_date, end_date
    FROM fiscal_years
    WHERE is_active = true
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Fallback: use newest FY if none marked active
if (!$active_fy_row) {
    $active_fy_row = $conn->query("
        SELECT id, fiscal_code, start_date, end_date
        FROM fiscal_years
        ORDER BY fiscal_code DESC
        LIMIT 1
    ")->fetch(PDO::FETCH_ASSOC);
}

// Build Nepali date range from active FY
// fiscal_code = "2082"  →  from_nep = "2082.04.01"  to_nep = "2083.03.32"
$active_fy_code = $active_fy_row ? $active_fy_row['fiscal_code'] : '2082';
$active_fy_code_int = intval($active_fy_code);
$default_from_nep = $active_fy_code_int . '.04.01';
$default_to_nep   = ($active_fy_code_int + 1) . '.03.32';

// ── Filters ───────────────────────────────────────────────────────────
$fiscal_year_filter = isset($_GET['fiscal_year_filter']) ? trim($_GET['fiscal_year_filter']) : $active_fy_code;
$class_filter       = isset($_GET['class_filter'])       ? $_GET['class_filter']             : array();
$book_code_filter   = isset($_GET['book_code_filter'])   ? $_GET['book_code_filter']          : array();
$status_filter      = isset($_GET['status_filter'])      ? trim($_GET['status_filter'])       : '';
$type_filter        = isset($_GET['type_filter'])        ? trim($_GET['type_filter'])         : '';

// Deno date range — default to active FY range, user can override
$deno_from = isset($_GET['deno_from']) ? trim($_GET['deno_from']) : $default_from_nep;
$deno_to   = isset($_GET['deno_to'])   ? trim($_GET['deno_to'])   : $default_to_nep;

if (is_string($class_filter))     $class_filter     = ($class_filter     === '') ? array() : array($class_filter);
if (is_string($book_code_filter)) $book_code_filter = ($book_code_filter === '') ? array() : array($book_code_filter);

// Recalculate date range if FY filter changes
if ($fiscal_year_filter !== '') {
    $fy_row = $conn->prepare("SELECT fiscal_code FROM fiscal_years WHERE fiscal_code = :fc LIMIT 1");
    $fy_row->execute(array(':fc' => $fiscal_year_filter));
    $fy_data = $fy_row->fetch(PDO::FETCH_ASSOC);
    if ($fy_data) {
        $fc_int = intval($fy_data['fiscal_code']);
        // Only override if user hasn't manually set dates in this request
        if (!isset($_GET['deno_from'])) {
            $deno_from = $fc_int . '.04.01';
            $deno_to   = ($fc_int + 1) . '.03.32';
        }
    }
}

// ── WHERE for job_ticket query ────────────────────────────────────────
$where  = array();
$params = array();

if ($fiscal_year_filter !== '') {
    $where[] = "fy.fiscal_code = :fy";
    $params[':fy'] = $fiscal_year_filter;
}
if (!empty($class_filter)) {
    $ph = array();
    foreach ($class_filter as $i => $v) { $ph[] = ":cls$i"; $params[":cls$i"] = $v; }
    $where[] = "jt.class IN (" . implode(',', $ph) . ")";
}
if (!empty($book_code_filter)) {
    $ph = array();
    foreach ($book_code_filter as $i => $v) { $ph[] = ":bkc$i"; $params[":bkc$i"] = $v; }
    $where[] = "b.book_code IN (" . implode(',', $ph) . ")";
}
if ($status_filter !== '') {
    $where[] = "jt.status = :status";
    $params[':status'] = $status_filter;
}
if ($type_filter === 'translated')     $where[] = "b.is_translated = true";
if ($type_filter === 'non_translated') $where[] = "b.is_translated = false";

$where_sql = !empty($where) ? ('WHERE ' . implode(' AND ', $where)) : '';

// ── Main query ────────────────────────────────────────────────────────
// Deno is aggregated per book_code using deno_date_nep range (same as deno report).
// This ensures the totals match exactly.
// We use BOTH deno_year (enum cast) OR fiscal_year (enum) OR date range to be
// as inclusive as possible. The safest and most consistent approach is date range.
$sql = "
    SELECT
        fy.fiscal_code                      AS fiscal_year,
        fy.id                               AS fy_id,
        b.book_code,
        b.book_name                         AS book_name_eng,
        b.book_name as book_name_preeti,
        jt.class,
        b.is_translated,
        jt.id                               AS jt_id,
        jt.job_ticket_code,
        jt.lot,
        jt.print_qty,
        jt.status                           AS jt_status,
        jt.date_nep                         AS jt_date,
        COALESCE(deno_agg.deno_printed, 0)  AS deno_printed
    FROM job_ticket jt
    LEFT JOIN books        b   ON b.book_id  = jt.book_id
    LEFT JOIN fiscal_years fy  ON fy.id      = jt.fiscal_year_id
    LEFT JOIN (
        SELECT
            book_code,
            SUM(total_qty) AS deno_printed
        FROM deno
        WHERE deleted_at IS NULL
          AND deno_date_nep >= :deno_from
          AND deno_date_nep <= :deno_to
        GROUP BY book_code
    ) deno_agg ON deno_agg.book_code = b.book_code
    $where_sql
    ORDER BY fy.fiscal_code,
             b.class_level,
             b.book_code,
             CAST(jt.lot AS integer) NULLS LAST
";

// Merge deno date params with JT where params
$all_params = array_merge(
    array(':deno_from' => $deno_from, ':deno_to' => $deno_to),
    $params
);

$stmt = $conn->prepare($sql);
$stmt->execute($all_params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Pivot: one row per book (group lots) ──────────────────────────────
$books = array();
foreach ($raw as $r) {
    $key   = $r['fiscal_year'] . '|' . $r['book_code'];
    $trans = ($r['is_translated'] === 't' || $r['is_translated'] === true);

    if (!isset($books[$key])) {
        $books[$key] = array(
            'fiscal_year'      => $r['fiscal_year'],
            'fy_id'            => $r['fy_id'],
            'book_code'        => $r['book_code'],
            'book_name_eng'    => $r['book_name_eng'],
            'book_name_preeti' => ($r['book_name_preeti'] !== '' && $r['book_name_preeti'] !== null)
                                   ? $r['book_name_preeti'] : $r['book_name_eng'],
            'class'            => $r['class'],
            'is_translated'    => $trans,
            'lots'             => array(),
            'total_jt_qty'     => 0,
            'deno_printed'     => intval($r['deno_printed']),
        );
    }
    $books[$key]['lots'][] = array(
        'jt_id'   => $r['jt_id'],
        'jt_code' => $r['job_ticket_code'],
        'lot'     => $r['lot'],
        'qty'     => intval($r['print_qty']),
        'status'  => $r['jt_status'],
        'date'    => $r['jt_date'],
    );
    $books[$key]['total_jt_qty'] += intval($r['print_qty']);
}

uasort($books, function($a, $b) {
    $cc = intval($a['class']) - intval($b['class']);
    return ($cc !== 0) ? $cc : strcmp($a['book_code'], $b['book_code']);
});

foreach ($books as &$bk) {
    $bk['diff']     = $bk['deno_printed'] - $bk['total_jt_qty'];
    $bk['diff_pct'] = ($bk['total_jt_qty'] > 0)
                      ? round(($bk['diff'] / $bk['total_jt_qty']) * 100, 1)
                      : null;
}
unset($bk);

// ── Grand totals (from JT-matched books only) ─────────────────────────
$gt_jt_qty     = 0;
$gt_deno_matched = 0;   // deno sum for books that HAVE a job ticket
foreach ($books as $bk) {
    $gt_jt_qty       += $bk['total_jt_qty'];
    $gt_deno_matched += $bk['deno_printed'];
}
$total_books = count($books);

// ── TRUE grand total deno: query deno directly, no JT join ───────────
// This is the number that must match the standalone deno report.
// Books without a job ticket are included here but excluded from the
// JT-report table rows — that explains any discrepancy.
$true_deno_params = array(':td_from' => $deno_from, ':td_to' => $deno_to);
$true_deno_where  = array("deleted_at IS NULL", "deno_date_nep >= :td_from", "deno_date_nep <= :td_to");

// Apply same book/class/type filters if set (so filtered view is consistent)
if (!empty($book_code_filter)) {
    $ph = array();
    foreach ($book_code_filter as $i => $v) { $ph[] = ":tdbkc$i"; $true_deno_params[":tdbkc$i"] = $v; }
    $true_deno_where[] = "book_code IN (" . implode(',', $ph) . ")";
}

$true_deno_sql = "
    SELECT COALESCE(SUM(d.total_qty), 0) AS grand_total
    FROM deno d
    WHERE " . implode(' AND ', $true_deno_where);

$td_stmt = $conn->prepare($true_deno_sql);
$td_stmt->execute($true_deno_params);
$gt_deno_true = intval($td_stmt->fetchColumn());

// For the table diff, use matched deno (books in JT)
$gt_deno   = $gt_deno_matched;
$gt_diff   = $gt_deno_true - $gt_jt_qty;  // compare TRUE deno vs JT qty
$gt_diff_pct = ($gt_jt_qty > 0) ? round(($gt_diff / $gt_jt_qty) * 100, 1) : null;

// Books with deno but NO job ticket (unmatched deno qty)
$gt_deno_unmatched = $gt_deno_true - $gt_deno_matched;

// ── Dropdown data ─────────────────────────────────────────────────────
$fy_rows  = $conn->query("SELECT fiscal_code FROM fiscal_years ORDER BY fiscal_code")->fetchAll(PDO::FETCH_COLUMN);
$cls_rows = $conn->query("SELECT DISTINCT class FROM job_ticket WHERE class IS NOT NULL ORDER BY class::integer")->fetchAll(PDO::FETCH_COLUMN);
$bk_rows  = $conn->query("SELECT DISTINCT book_code, book_name FROM books ORDER BY book_code")->fetchAll(PDO::FETCH_ASSOC);

// ── Export URL ────────────────────────────────────────────────────────
$export_base = dirname($_SERVER['PHP_SELF']) . '/job_ticket_export.php';
$get_clean   = $_GET;
unset($get_clean['export']);
$qs = http_build_query($get_clean);

// ── Deno report base URL (adjust path to your deno report) ────────────
$deno_report_url = $_SERVER['DOCUMENT_ROOT'] ? '/deno2/jobticket/reports/deno_report.php' : '/deno2/jobticket/reports/deno_report.php';
// Build clickable link for a specific book's deno qty
function deno_link($book_code, $deno_from, $deno_to, $deno_qty) {
    if ($deno_qty <= 0) return '<span style="color:#aaa">0</span>';
    $url = '/deno2/jobticket/reports/deno_report.php?'
         . 'book_code=' . urlencode($book_code)
         . '&from_date_nep=' . urlencode($deno_from)
         . '&to_date_nep='   . urlencode($deno_to);
    return '<a href="' . $url . '" target="_blank" class="deno-link" '
         . 'title="Click to view deno entries for ' . htmlspecialchars($book_code) . '">'
         . number_format($deno_qty)
         . '<span class="deno-link-icon">↗</span></a>';
}

// Grand total deno link (no book filter)
function deno_link_total($deno_from, $deno_to, $deno_qty) {
    if ($deno_qty <= 0) return '<span>0</span>';
    $url = '/deno2/jobticket/reports/deno_report.php?'
         . 'from_date_nep=' . urlencode($deno_from)
         . '&to_date_nep='  . urlencode($deno_to);
    return '<a href="' . $url . '" target="_blank" class="deno-link" title="View all deno entries">'
         . number_format($deno_qty)
         . '<span class="deno-link-icon">↗</span></a>';
}

// ── Helpers ───────────────────────────────────────────────────────────
function lots_html($lots) {
    $parts = array();
    foreach ($lots as $l) {
        $parts[] = '<span class="lot-chip">'
                 . '<span class="lc-code">' . htmlspecialchars($l['jt_code']) . '</span>'
                 . '<span class="lc-lot">Lot&nbsp;' . htmlspecialchars($l['lot']) . '</span>'
                 . '<span class="lc-qty">(' . number_format($l['qty']) . ')</span>'
                 . '</span>';
    }
    return implode('<span class="lot-sep">＋</span>', $parts);
}

function diff_cls($diff) {
    if ($diff > 0) return 'diff-over';
    if ($diff < 0) return 'diff-under';
    return 'diff-exact';
}

function diff_badge($diff, $pct) {
    if ($diff === 0) return '<span class="badge b-exact">✔ Exact</span>';
    if ($diff > 0) {
        $p = ($pct !== null) ? ' (+' . $pct . '%)' : '';
        return '<span class="badge b-over">▲ +' . number_format($diff) . $p . '</span>';
    }
    $p = ($pct !== null) ? ' (' . $pct . '%)' : '';
    return '<span class="badge b-under">▼ ' . number_format($diff) . $p . '</span>';
}

function status_badge($status) {
    $map = array(
        'pending'      => array('bs-pend', 'Pending'),
        'in_progress'  => array('bs-prog', 'In Progress'),
        'completed'    => array('bs-done', 'Completed'),
        'bp_completed' => array('bs-bp',   'BP Done'),
    );
    $s = isset($map[$status]) ? $map[$status] : array('bs-pend', htmlspecialchars($status));
    return '<span class="badge ' . $s[0] . '">' . $s[1] . '</span>';
}
?>
<style>
:root {
    --ink:    #0d1b2a;
    --paper:  #f4f7fb;
    --blue:   #1a3c5e;
    --blue2:  #2563a8;
    --green:  #186a3b;
    --red:    #a93226;
    --amber:  #b7770d;
    --teal:   #0e6655;
    --nt-row: #eaf3fb;
    --t-row:  #fef6e4;
    --shadow: 0 2px 10px rgba(0,0,0,.12);
}
* { box-sizing: border-box; }
body { font-family: 'Segoe UI', Tahoma, Geneva, sans-serif; background: var(--paper); color: var(--ink); }
.page-wrap { padding: 16px 20px; }

.page-title {
    display: flex; align-items: center; gap: 10px; font-size: 20px; font-weight: 800;
    color: var(--blue); border-bottom: 3px solid var(--blue2); padding-bottom: 10px; margin-bottom: 14px;
}

/* ── FY date range info bar ── */
.fy-info-bar {
    background: #e8f4fd; border: 1px solid #afd0ec; border-radius: 6px;
    padding: 8px 14px; margin-bottom: 12px; font-size: 12.5px;
    display: flex; align-items: center; gap: 16px; flex-wrap: wrap;
}
.fy-info-bar strong { color: var(--blue); }
.fy-info-bar .fy-range { font-weight: 700; color: var(--green); font-size: 13px; }
.fy-info-bar .fy-note  { color: #556; font-size: 11px; }

/* Filter panel */
.filter-panel { background:#fff; border:1px solid #c8d6e5; border-radius:8px; padding:14px 16px; margin-bottom:14px; box-shadow:var(--shadow); }
.filter-panel h4 { font-size:13px; color:var(--blue2); margin-bottom:10px; font-weight:700; }
.filter-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:8px; }
.fg { display:flex; flex-direction:column; min-width:130px; }
.fg label { font-size:10px; font-weight:700; color:#556; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.fg input, .fg select { padding:5px 9px; border:1px solid #b0bec5; border-radius:4px; font-size:12px; background:#fafbfc; }
.fg select[multiple] { height:68px; }
.fg.deno-range input { border-color: #27ae60; background: #f0faf4; }
.hint { font-size:10px; color:#999; margin-top:2px; }
.filter-actions { display:flex; gap:8px; margin-top:6px; }

/* Buttons */
.btn { padding:6px 14px; border:none; border-radius:5px; cursor:pointer; font-size:12px; font-weight:700;
       text-decoration:none; display:inline-flex; align-items:center; gap:5px; white-space:nowrap; }
.btn:hover { opacity:.86; transform:translateY(-1px); }
.btn-primary   { background:var(--blue2); color:#fff; }
.btn-secondary { background:#78909c;      color:#fff; }
.btn-csv       { background:#27ae60;      color:#fff; }
.btn-excel     { background:#1d6f42;      color:#fff; }
.btn-print     { background:#34495e;      color:#fff; }

/* Cards */
.cards { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0; }
.card  { background:#fff; border:1px solid #c8d6e5; border-radius:8px; padding:10px 16px;
         text-align:center; flex:1; min-width:110px; box-shadow:var(--shadow); }
.card-num { font-size:22px; font-weight:800; color:var(--blue2); }
.card-lbl { font-size:10px; color:#778; margin-top:2px; text-transform:uppercase; letter-spacing:.4px; }

/* Export strip */
.export-strip { background:#e8edf4; border-radius:6px; padding:9px 14px; margin:10px 0;
                display:flex; gap:8px; align-items:center; flex-wrap:wrap; }
.export-strip strong { font-size:12px; color:#445; }

/* Table */
.tbl-wrap { overflow-x:auto; border-radius:8px; box-shadow:var(--shadow); margin-top:6px; }
table.rpt { width:100%; border-collapse:collapse; font-size:12.5px; background:#fff; }
table.rpt th { background:var(--blue); color:#fff; padding:8px 7px; border:1px solid #2a4f70;
               font-size:11.5px; font-weight:700; white-space:nowrap; text-align:center; }
table.rpt td { border:1px solid #c8d8e8; padding:6px 8px; vertical-align:middle; }
table.rpt tbody tr:hover td { filter:brightness(.96); }
tr.sub-hdr th { background:#0e5f3a; font-size:11px; }
tr.class-div td { background:var(--blue); color:#fff; font-weight:800; font-size:12px; padding:5px 10px; letter-spacing:.6px; }
tr.row-nt td { background:var(--nt-row); }
tr.row-t  td { background:var(--t-row);  }
tr.total-row td { background:#d5f5e3 !important; font-weight:800; border-top:3px solid var(--green); font-size:12.5px; }

/* Lot chips */
.lot-chips { display:flex; flex-wrap:wrap; gap:4px; align-items:center; }
.lot-chip  { display:inline-flex; align-items:center; gap:3px; background:#fff;
             border:1px solid #a0bcd4; border-radius:5px; padding:3px 6px; white-space:nowrap; }
.lc-code { font-size:10.5px; font-weight:700; color:var(--blue2); }
.lc-lot  { font-size:9px;  color:#778; }
.lc-qty  { font-size:10.5px; font-weight:700; color:var(--ink); }
.lot-sep { color:#aaa; font-size:11px; padding:0 2px; }

/* Diff */
.diff-over  { color:var(--amber); font-weight:700; }
.diff-under { color:var(--red);   font-weight:700; }
.diff-exact { color:var(--green); font-weight:700; }

/* Badges */
.badge { display:inline-block; padding:2px 7px; border-radius:10px; font-size:10px; font-weight:700; white-space:nowrap; }
.b-exact { background:#d4edda; color:#155724; }
.b-over  { background:#fef9e7; color:#7d6608; border:1px solid #f0c040; }
.b-under { background:#fde8e8; color:#7b1414; border:1px solid #f5a9a9; }
.bs-pend { background:#fde8e8; color:#7b1414; }
.bs-prog { background:#fff3cd; color:#7d6608; }
.bs-done { background:#d4edda; color:#155724; }
.bs-bp   { background:#d1ecf1; color:#0c5460; }

/* Clickable deno link */
.deno-link {
    color: var(--green); font-weight: 700; text-decoration: none;
    display: inline-flex; align-items: center; gap: 3px;
    padding: 1px 5px; border-radius: 4px;
    border: 1px solid #90d4a8; background: #f0faf4;
    transition: all .15s;
}
.deno-link:hover {
    background: var(--green); color: #fff;
    border-color: var(--green);
}
.deno-link-icon { font-size: 10px; opacity: .7; }

.num    { text-align:right; }
.center { text-align:center; }
.type-t  { color:var(--teal);  font-weight:800; font-size:11px; }
.type-nt { color:var(--blue2); font-weight:800; font-size:11px; }
.ftag { display:inline-block; background:var(--blue2); color:#fff; padding:2px 8px; border-radius:10px; font-size:10px; margin:2px; }
.active-filters { font-size:11px; color:#556; margin-top:8px; }

/* ═══  PRINT  ═══ */
@media print {
    .no-print, .filter-panel, .export-strip, .cards, .fy-info-bar { display:none !important; }
    * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    body { font-size:9pt; background:#fff; margin:0; padding:0; }
    .page-wrap { padding:3px; }
    .page-title { font-size:13pt; border-bottom:2px solid #000; margin-bottom:4px; color:#000; }
    .print-header { display:block !important; }
    .tbl-wrap { overflow:visible; box-shadow:none; }
    table.rpt { font-size:8pt; }
    table.rpt th { padding:3px 4px; font-size:8pt; background:var(--blue) !important; color:#fff !important; }
    table.rpt td { padding:3px 5px; border:1px solid #aaa; font-size:8pt; }
    tr.sub-hdr th  { background:#0e5f3a !important; color:#fff !important; }
    tr.class-div td { background:var(--blue) !important; color:#fff !important; }
    tr.row-nt td   { background:#dbeaf8 !important; }
    tr.row-t  td   { background:#fef3d0 !important; }
    tr.total-row td { background:#c8e6c9 !important; font-weight:800 !important; }
    .lot-chip { background:#fff !important; border:1px solid #999 !important; padding:1px 3px; }
    .badge    { border:1px solid #888; background:#eee !important; color:#000 !important; font-size:7pt; }
    .deno-link { background:#eee !important; color:#000 !important; border:1px solid #999 !important; text-decoration:none; }
    .deno-link-icon { display:none; }
    @page { size:A3 landscape; margin:0.5cm; }
}
.print-header { display:none; text-align:center; margin-bottom:8px; border-bottom:1px solid #ccc; padding-bottom:4px; }
.print-header h3 { font-size:13pt; margin:0 0 3px; }
.print-header p  { font-size:8pt; color:#555; margin:0; }
</style>

<div class="page-wrap">

<div class="print-header">
    <h3>Janak Education Materials Centre — Job Ticket Production Report</h3>
    <p>FY: <?= htmlspecialchars($fiscal_year_filter) ?>
       &nbsp;|&nbsp; Deno Range: <?= htmlspecialchars($deno_from) ?> → <?= htmlspecialchars($deno_to) ?>
       &nbsp;|&nbsp;<?= !empty($class_filter) ? 'Class: ' . implode(', ', $class_filter) . ' | ' : '' ?>
       Generated: <?= date('Y-m-d H:i:s') ?></p>
</div>

<div class="page-title">📋 Job Ticket vs Deno Production Report</div>

<!-- FY info bar -->
<div class="fy-info-bar no-print">
    <div>
        <strong>Active FY:</strong> <?= htmlspecialchars($active_fy_code) ?>
        &nbsp; <strong>Deno Date Range:</strong>
        <span class="fy-range"><?= htmlspecialchars($deno_from) ?> → <?= htmlspecialchars($deno_to) ?></span>
    </div>
    <div class="fy-note">
        Deno totals = SUM(deno.total_qty) WHERE deno_date_nep BETWEEN these dates &amp; deleted_at IS NULL.
        This matches the main deno report exactly.
    </div>
</div>

<!-- Filters -->
<div class="filter-panel no-print">
    <h4>🔍 Filters</h4>
    <form method="GET">
        <div class="filter-row">
            <div class="fg">
                <label>Fiscal Year</label>
                <select name="fiscal_year_filter" id="fy_select">
                    <option value="">All Years</option>
                    <?php foreach ($fy_rows as $fy): ?>
                    <option value="<?= htmlspecialchars($fy) ?>" <?= ($fiscal_year_filter == $fy) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fy) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <!-- Deno date range — pre-filled from active FY, editable -->
            <div class="fg deno-range">
                <label>Deno From (Nep)</label>
                <input type="text" name="deno_from" id="deno_from"
                       value="<?= htmlspecialchars($deno_from) ?>" placeholder="YYYY.MM.DD">
                <span class="hint">Auto-set from FY</span>
            </div>
            <div class="fg deno-range">
                <label>Deno To (Nep)</label>
                <input type="text" name="deno_to" id="deno_to"
                       value="<?= htmlspecialchars($deno_to) ?>" placeholder="YYYY.MM.DD">
                <span class="hint">Auto-set from FY</span>
            </div>
            <div class="fg">
                <label>Class</label>
                <select name="class_filter[]" multiple>
                    <?php foreach ($cls_rows as $c): ?>
                    <option value="<?= $c ?>" <?= in_array($c, $class_filter) ? 'selected' : '' ?>>Class <?= $c ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Ctrl/Cmd = multi</span>
            </div>
            <div class="fg">
                <label>Book Code</label>
                <select name="book_code_filter[]" multiple>
                    <?php foreach ($bk_rows as $bk): ?>
                    <option value="<?= htmlspecialchars($bk['book_code']) ?>"
                        <?= in_array($bk['book_code'], $book_code_filter) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($bk['book_code']) ?> – <?= htmlspecialchars($bk['book_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Ctrl/Cmd = multi</span>
            </div>
            <div class="fg">
                <label>JT Status</label>
                <select name="status_filter">
                    <option value="">All</option>
                    <option value="pending"      <?= ($status_filter == 'pending')      ? 'selected' : '' ?>>Pending</option>
                    <option value="in_progress"  <?= ($status_filter == 'in_progress')  ? 'selected' : '' ?>>In Progress</option>
                    <option value="completed"    <?= ($status_filter == 'completed')    ? 'selected' : '' ?>>Completed</option>
                    <option value="bp_completed" <?= ($status_filter == 'bp_completed') ? 'selected' : '' ?>>BP Completed</option>
                </select>
            </div>
            <div class="fg">
                <label>Type</label>
                <select name="type_filter">
                    <option value="">All</option>
                    <option value="translated"     <?= ($type_filter == 'translated')     ? 'selected' : '' ?>>Translated</option>
                    <option value="non_translated" <?= ($type_filter == 'non_translated') ? 'selected' : '' ?>>Non-Translated</option>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">▶ Apply</button>
            <a href="?" class="btn btn-secondary">✕ Reset to Active FY</a>
        </div>
    </form>
    <?php
    $has = ($fiscal_year_filter !== $active_fy_code) || !empty($class_filter)
           || !empty($book_code_filter) || $status_filter || $type_filter
           || ($deno_from !== $default_from_nep) || ($deno_to !== $default_to_nep);
    if ($has): ?>
    <div class="active-filters">
        <strong>Active:</strong>
        <span class="ftag">FY: <?= htmlspecialchars($fiscal_year_filter) ?></span>
        <span class="ftag">Deno: <?= htmlspecialchars($deno_from) ?> → <?= htmlspecialchars($deno_to) ?></span>
        <?= !empty($class_filter)     ? "<span class='ftag'>Class: "  . implode(',', $class_filter)     . "</span>" : '' ?>
        <?= !empty($book_code_filter) ? "<span class='ftag'>Books: "  . implode(',', $book_code_filter) . "</span>" : '' ?>
        <?= $status_filter ? "<span class='ftag'>Status: $status_filter</span>" : '' ?>
        <?= $type_filter   ? "<span class='ftag'>Type: $type_filter</span>"     : '' ?>
    </div>
    <?php endif; ?>
</div>

<!-- Summary cards -->
<div class="cards no-print">
    <div class="card">
        <div class="card-num"><?= $total_books ?></div>
        <div class="card-lbl">Total Books (JT)</div>
    </div>
    <div class="card">
        <div class="card-num"><?= number_format($gt_jt_qty) ?></div>
        <div class="card-lbl">Total JT Qty</div>
    </div>
    <div class="card">
        <div class="card-num" style="color:var(--green)">
            <a href="/deno2/jobticket/reports/deno_report.php?from_date_nep=<?= urlencode($deno_from) ?>&to_date_nep=<?= urlencode($deno_to) ?>"
               target="_blank" style="color:inherit;text-decoration:none" title="View ALL deno entries (includes books without JT)">
                <?= number_format($gt_deno_true) ?> ↗
            </a>
        </div>
        <div class="card-lbl">Total Deno Printed</div>
    </div>
    <?php if ($gt_deno_unmatched > 0): ?>
    <div class="card" style="border-color:#f0c040;background:#fffdf0">
        <div class="card-num" style="color:var(--amber)"><?= number_format($gt_deno_matched) ?></div>
        <div class="card-lbl">Deno (JT-matched)</div>
    </div>
    <div class="card" style="border-color:#f5a9a9;background:#fff8f8">
        <div class="card-num" style="color:var(--red)"><?= number_format($gt_deno_unmatched) ?></div>
        <div class="card-lbl" title="Deno entries for books with no job ticket in this FY">Deno (No JT) ⚠</div>
    </div>
    <?php endif; ?>
    <div class="card">
        <div class="card-num" style="color:<?= ($gt_diff > 0 ? 'var(--amber)' : ($gt_diff < 0 ? 'var(--red)' : 'var(--green)')) ?>">
            <?= ($gt_diff >= 0 ? '+' : '') . number_format($gt_diff) ?>
        </div>
        <div class="card-lbl">Diff (TrueDeno−JT)</div>
    </div>
    <div class="card">
        <div class="card-num" style="color:<?= ($gt_diff > 0 ? 'var(--amber)' : ($gt_diff < 0 ? 'var(--red)' : 'var(--green)')) ?>">
            <?= ($gt_diff_pct !== null) ? (($gt_diff_pct >= 0 ? '+' : '') . $gt_diff_pct . '%') : '—' ?>
        </div>
        <div class="card-lbl">Overall %</div>
    </div>
</div>

<?php if ($gt_deno_unmatched > 0): ?>
<div class="no-print" style="background:#fffbe6;border:1px solid #f0c040;border-radius:6px;padding:9px 14px;margin-bottom:10px;font-size:12px;display:flex;align-items:center;gap:10px;flex-wrap:wrap">
    <span style="font-size:16px">⚠</span>
    <div>
        <strong style="color:var(--amber)">Total Deno (<?= number_format($gt_deno_true) ?>) &gt; JT-matched Deno (<?= number_format($gt_deno_matched) ?>)</strong>
        — Difference of <strong><?= number_format($gt_deno_unmatched) ?></strong> comes from deno entries for books
        that have <em>no job ticket</em> in the selected fiscal year, or whose book_code doesn't match any active JT.
        <a href="/deno2/jobticket/reports/deno_report.php?from_date_nep=<?= urlencode($deno_from) ?>&to_date_nep=<?= urlencode($deno_to) ?>"
           target="_blank" style="color:var(--blue2);font-weight:700">View all deno entries ↗</a>
    </div>
</div>
<?php endif; ?>

<!-- Export strip -->
<div class="export-strip no-print">
    <strong>Export / Print:</strong>
    <a href="<?= $export_base ?>?<?= $qs ?>&amp;export=csv"   class="btn btn-csv">📊 Download CSV</a>
    <a href="<?= $export_base ?>?<?= $qs ?>&amp;export=excel" class="btn btn-excel">📈 Download Excel</a>
    <button onclick="window.print()" class="btn btn-print">🖨️ Print A3 Landscape</button>
</div>

<!-- Table -->
<div class="tbl-wrap">
<?php if (empty($books)): ?>
    <p style="text-align:center;padding:40px;color:#aaa;font-size:14px">No records found.</p>
<?php else: ?>
<table class="rpt">
<thead>
    <tr>
        <th rowspan="2">SN</th>
        <th rowspan="2">FY</th>
        <th rowspan="2">Book Code</th>
        <th rowspan="2" style="min-width:150px;text-align:left">Book Name (Eng)</th>
        <th rowspan="2" style="min-width:150px;text-align:left">Preeti Name</th>
        <th rowspan="2">Class</th>
        <th rowspan="2">Type</th>
        <th rowspan="2" style="min-width:280px">
            Job Ticket Lots
            <div style="font-size:9px;font-weight:400;opacity:.85">Code · Lot · (Qty)</div>
        </th>
        <th rowspan="2" class="num">Total JT<br>Qty</th>
        <th colspan="4" style="background:#0e5f3a;text-align:center">
            📊 Deno Comparison
            <div style="font-size:9px;font-weight:400;opacity:.9"><?= htmlspecialchars($deno_from) ?> → <?= htmlspecialchars($deno_to) ?></div>
        </th>
    </tr>
    <tr class="sub-hdr">
        <th>Deno Printed<br><span style="font-size:9px;font-weight:400">SUM(total_qty) ↗click</span></th>
        <th>Difference<br><span style="font-size:9px;font-weight:400">Deno − JT</span></th>
        <th>Diff %</th>
        <th>Status</th>
    </tr>
</thead>
<tbody>
<?php
$sn         = 1;
$prev_class = null;
$total_cols = 13;

foreach ($books as $bk):
    if ($bk['class'] !== $prev_class):
        $prev_class = $bk['class'];
?>
    <tr class="class-div">
        <td colspan="<?= $total_cols ?>">▸ CLASS <?= htmlspecialchars($bk['class']) ?></td>
    </tr>
<?php
    endif;

    $trans   = $bk['is_translated'];
    $row_cls = $trans ? 'row-t' : 'row-nt';
    $diff    = $bk['diff'];
    $pct     = $bk['diff_pct'];
    $dc      = diff_cls($diff);

    if ($diff > 0) {
        $diff_html = '<span class="' . $dc . '">+' . number_format($diff) . '</span>';
        $pct_html  = '<span class="' . $dc . '">+' . $pct . '%</span>';
    } elseif ($diff < 0) {
        $diff_html = '<span class="' . $dc . '">' . number_format($diff) . '</span>';
        $pct_html  = '<span class="' . $dc . '">' . $pct . '%</span>';
    } else {
        $diff_html = '<span class="diff-exact">—</span>';
        $pct_html  = '<span class="diff-exact">0.0%</span>';
    }
?>
    <tr class="<?= $row_cls ?>">
        <td class="center"><?= $sn++ ?></td>
        <td class="center"><?= htmlspecialchars($bk['fiscal_year']) ?></td>
        <td style="font-weight:700;font-size:11.5px"><?= htmlspecialchars($bk['book_code']) ?></td>
        <td><?= htmlspecialchars($bk['book_name_eng']) ?></td>
        <td style="font-family:serif;font-size:12px"><?= htmlspecialchars($bk['book_name_preeti']) ?></td>
        <td class="center" style="font-weight:700"><?= htmlspecialchars($bk['class']) ?></td>
        <td class="center">
            <?= $trans ? '<span class="type-t">T</span>' : '<span class="type-nt">NT</span>' ?>
        </td>
        <td><div class="lot-chips"><?= lots_html($bk['lots']) ?></div></td>
        <td class="num"><strong><?= number_format($bk['total_jt_qty']) ?></strong></td>
        <!-- Deno printed — CLICKABLE → opens deno report filtered by book_code + date range -->
        <td class="num" style="font-size:13px">
            <?= deno_link($bk['book_code'], $deno_from, $deno_to, $bk['deno_printed']) ?>
        </td>
        <td class="num" style="font-size:13px"><?= $diff_html ?></td>
        <td class="center" style="font-size:12px"><?= $pct_html ?></td>
        <td class="center"><?= diff_badge($diff, $pct) ?></td>
    </tr>
<?php endforeach; ?>

<!-- Grand total -->
<tr class="total-row">
    <td></td>
    <td colspan="7" style="font-size:13px;letter-spacing:.4px">
        GRAND TOTAL — <?= $total_books ?> books
        <?php if ($gt_deno_unmatched > 0): ?>
        <div style="font-size:10px;color:#7d6608;font-weight:600;margin-top:2px">
            ⚠ Deno shown = JT-matched books only. True total deno = <?= number_format($gt_deno_true) ?>
            (<?= number_format($gt_deno_unmatched) ?> from books without JT excluded from table rows)
        </div>
        <?php endif; ?>
    </td>
    <td class="num"><?= number_format($gt_jt_qty) ?></td>
    <td class="num" style="font-size:13px">
        <?php
        // Show true deno total (not just matched) in grand total row
        $url_all = '/deno2/jobticket/reports/deno_report.php?from_date_nep=' . urlencode($deno_from) . '&to_date_nep=' . urlencode($deno_to);
        echo '<a href="' . $url_all . '" target="_blank" class="deno-link" title="TRUE total: all deno entries in date range">'
           . number_format($gt_deno_true)
           . '<span class="deno-link-icon">↗</span></a>';
        ?>
        <?php if ($gt_deno_unmatched > 0): ?>
        <div style="font-size:9px;color:#7d6608;margin-top:2px">
            (<?= number_format($gt_deno_matched) ?> JT-matched + <?= number_format($gt_deno_unmatched) ?> no-JT)
        </div>
        <?php endif; ?>
    </td>
    <td class="num">
        <?php $gdc = diff_cls($gt_diff); ?>
        <span class="<?= $gdc ?>"><?= ($gt_diff >= 0 ? '+' : '') . number_format($gt_diff) ?></span>
    </td>
    <td class="center">
        <?php if ($gt_diff_pct !== null): $gdc = diff_cls($gt_diff); ?>
            <span class="<?= $gdc ?>"><?= ($gt_diff_pct >= 0 ? '+' : '') . $gt_diff_pct ?>%</span>
        <?php else: ?>—<?php endif; ?>
    </td>
    <td class="center"><?= diff_badge($gt_diff, $gt_diff_pct) ?></td>
</tr>
</tbody>
</table>
<?php endif; ?>
</div>

<div style="margin-top:10px;font-size:11px;color:#aaa" class="no-print">
    <?= $total_books ?> books (JT-matched) &nbsp;|&nbsp;
    True deno total: <strong style="color:var(--green)"><?= number_format($gt_deno_true) ?></strong>
    &nbsp;|&nbsp; JT-matched deno: <strong><?= number_format($gt_deno_matched) ?></strong>
    <?php if ($gt_deno_unmatched > 0): ?>
    &nbsp;|&nbsp; <span style="color:var(--amber)">No-JT deno: <?= number_format($gt_deno_unmatched) ?></span>
    <?php endif; ?>
    &nbsp;|&nbsp; Deno query: <code>deno_date_nep BETWEEN '<?= htmlspecialchars($deno_from) ?>' AND '<?= htmlspecialchars($deno_to) ?>'</code>
    &nbsp;|&nbsp; Generated: <?= date('Y-m-d H:i:s') ?>
</div>
</div>

<!-- Auto-update deno dates when FY dropdown changes -->
<script>
var fyDates = {
    <?php foreach ($fy_rows as $fy):
        $fc_int = intval($fy);
        echo "'" . $fy . "': {from: '" . $fc_int . ".04.01', to: '" . ($fc_int + 1) . ".03.32'},\n";
    endforeach; ?>
};

document.getElementById('fy_select').addEventListener('change', function() {
    var fc = this.value;
    if (fc && fyDates[fc]) {
        document.getElementById('deno_from').value = fyDates[fc].from;
        document.getElementById('deno_to').value   = fyDates[fc].to;
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

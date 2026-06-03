<?php
/**
 * deno_report.php — Deno Production Detail Report
 *
 * URL params accepted (all optional):
 *   book_code       = single book code (from JT report link)
 *   book_code[]     = multiple book codes (from filter form)
 *   from_date_nep   = YYYY.MM.DD
 *   to_date_nep     = YYYY.MM.DD
 *   fiscal_year     = e.g. 2082
 *   class_filter[]  = class levels
 *   type_filter     = translated | non_translated
 *   entry_type      = direct | from_jt | from_bp
 *
 * Export: ?export=csv or ?export=excel  (handled inline with ob_start)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

// ── Filters ───────────────────────────────────────────────────────────
// Support both single ?book_code=X (from JT link) and array ?book_code[]=X
if (isset($_GET['book_code']) && !is_array($_GET['book_code']) && trim($_GET['book_code']) !== '') {
    $book_code_filter = array(trim($_GET['book_code']));
} elseif (isset($_GET['book_code']) && is_array($_GET['book_code'])) {
    $book_code_filter = array_filter(array_map('trim', $_GET['book_code']));
} else {
    $book_code_filter = array();
}

$from_date_nep  = isset($_GET['from_date_nep'])  ? trim($_GET['from_date_nep'])  : '';
$to_date_nep    = isset($_GET['to_date_nep'])    ? trim($_GET['to_date_nep'])    : '';
$fiscal_year_f  = isset($_GET['fiscal_year'])    ? trim($_GET['fiscal_year'])    : '';
$class_filter   = isset($_GET['class_filter'])   ? $_GET['class_filter']         : array();
$type_filter    = isset($_GET['type_filter'])    ? trim($_GET['type_filter'])    : '';
$entry_type_f   = isset($_GET['entry_type'])     ? trim($_GET['entry_type'])     : '';

if (is_string($class_filter)) $class_filter = ($class_filter === '') ? array() : array($class_filter);
$book_code_filter = array_values(array_filter($book_code_filter));

// ── Active FY for defaults ────────────────────────────────────────────
$active_fy = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE is_active=true LIMIT 1")->fetchColumn();
if (!$active_fy) $active_fy = $conn->query("SELECT fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC LIMIT 1")->fetchColumn();
$active_fy = $active_fy ?: '2082';
$afi = intval($active_fy);
$default_from = $afi . '.04.01';
$default_to   = ($afi + 1) . '.03.32';

if ($from_date_nep === '' && $to_date_nep === '' && empty($book_code_filter) && $fiscal_year_f === '') {
    $from_date_nep = $default_from;
    $to_date_nep   = $default_to;
}

// ── WHERE ─────────────────────────────────────────────────────────────
$where  = array("d.deleted_at IS NULL");
$params = array();

if ($from_date_nep !== '') { $where[] = "d.deno_date_nep >= :from"; $params[':from'] = $from_date_nep; }
if ($to_date_nep   !== '') { $where[] = "d.deno_date_nep <= :to";   $params[':to']   = $to_date_nep;   }

if (!empty($book_code_filter)) {
    $ph = array();
    foreach ($book_code_filter as $i => $v) { $ph[] = ":bkc$i"; $params[":bkc$i"] = $v; }
    $where[] = "d.book_code IN (" . implode(',', $ph) . ")";
}
if ($fiscal_year_f !== '') {
    $where[] = "d.deno_year::text = :fy";
    $params[':fy'] = $fiscal_year_f;
}
if (!empty($class_filter)) {
    $ph = array();
    foreach ($class_filter as $i => $v) { $ph[] = ":cls$i"; $params[":cls$i"] = $v; }
    $where[] = "b.class_level IN (" . implode(',', $ph) . ")";
}
if ($type_filter === 'translated')     $where[] = "b.is_translated = true";
if ($type_filter === 'non_translated') $where[] = "b.is_translated = false";
if ($entry_type_f !== '') { $where[] = "d.entry_type = :et"; $params[':et'] = $entry_type_f; }

$where_sql = 'WHERE ' . implode(' AND ', $where);

// ── Main query ────────────────────────────────────────────────────────
$sql = "
    SELECT
        d.id,
        d.ref_no,
        d.book_code,
        b.book_name,
        book_name As book_name_preeti,
        b.class_level,
        b.is_translated,
        b.book_type,
        d.deno_date_nep,
        d.deno_date_eng,
        d.deno_month,
        d.deno_year::text           AS deno_year,
        d.fiscal_year::text         AS fiscal_year,
        d.per_poka_qty,
        d.poka_qty,
        d.total_qty,
        d.quantity_openpcs,
        d.entry_type,
        d.notes,
        d.created_at,
        jt.job_ticket_code,
        uc.username                 AS created_by_name
    FROM deno d
    LEFT JOIN books        b   ON b.book_code = d.book_code
    LEFT JOIN job_ticket   jt  ON jt.id       = d.jt_id
    LEFT JOIN users        uc  ON uc.id        = d.created_by
    $where_sql
    ORDER BY b.class_level, d.book_code, d.deno_date_nep, d.id
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Summary aggregates ────────────────────────────────────────────────
$total_total_qty   = 0;
$total_openpcs     = 0;
$total_poka        = 0;
$book_summaries    = array();  // keyed by book_code

foreach ($rows as $r) {
    $total_total_qty += intval($r['total_qty']);
    $total_openpcs   += intval($r['quantity_openpcs']);
    $total_poka      += intval($r['poka_qty']);

    $bc = $r['book_code'];
    if (!isset($book_summaries[$bc])) {
        $book_summaries[$bc] = array(
            'book_name'    => $r['book_name'],
            'class_level'  => $r['class_level'],
            'is_translated'=> ($r['is_translated'] === 't' || $r['is_translated'] === true),
            'total_qty'    => 0,
            'openpcs'      => 0,
            'entries'      => 0,
        );
    }
    $book_summaries[$bc]['total_qty'] += intval($r['total_qty']);
    $book_summaries[$bc]['openpcs']   += intval($r['quantity_openpcs']);
    $book_summaries[$bc]['entries']++;
}
$net_production = $total_total_qty - $total_openpcs;

// ── Dropdown data ─────────────────────────────────────────────────────
$fy_list  = $conn->query("SELECT fiscal_code FROM fiscal_years ORDER BY fiscal_code")->fetchAll(PDO::FETCH_COLUMN);
$cls_list = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);
$bk_list  = $conn->query("SELECT book_code, book_name, class_level FROM books ORDER BY class_level, book_code")->fetchAll(PDO::FETCH_ASSOC);

// ── Export (handled here, before any HTML) ────────────────────────────
$export = isset($_GET['export']) ? trim($_GET['export']) : '';

if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="deno_report_' . date('Y-m-d_H-i-s') . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");

    fputcsv($out, array('Janak Education Materials Centre — Deno Production Report'));
    fputcsv($out, array(
        'Date Range: ' . ($from_date_nep ?: 'All') . ' to ' . ($to_date_nep ?: 'All'),
        'Books: ' . (empty($book_code_filter) ? 'All' : implode(', ', $book_code_filter)),
        'Generated: ' . date('Y-m-d H:i:s')
    ));
    fputcsv($out, array());

    fputcsv($out, array(
        'SN','Ref No','Book Code','Book Name','Class','Type','Date (Nep)','Date (Eng)',
        'Month','FY','Per Poka Qty','Poka Qty','Total Qty','Open Pcs','Net',
        'Entry Type','JT Code','Notes','Created By','Created At'
    ));

    $sn = 1;
    foreach ($rows as $r) {
        $trans = ($r['is_translated'] === 't' || $r['is_translated'] === true);
        fputcsv($out, array(
            $sn++, $r['ref_no'], $r['book_code'], $r['book_name'],
            $r['class_level'], $trans ? 'T' : 'NT',
            $r['deno_date_nep'], $r['deno_date_eng'],
            $r['deno_month'], $r['deno_year'],
            $r['per_poka_qty'], $r['poka_qty'], $r['total_qty'],
            $r['quantity_openpcs'], intval($r['total_qty']) - intval($r['quantity_openpcs']),
            $r['entry_type'], $r['job_ticket_code'],
            $r['notes'], $r['created_by_name'], $r['created_at']
        ));
    }
    fputcsv($out, array('', 'TOTALS', '', '', '', '', '', '', '', '', '',
        $total_poka, $total_total_qty, $total_openpcs, $net_production, '', '', '', '', ''));
    fclose($out);
    exit;
}

if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="deno_report_' . date('Y-m-d_H-i-s') . '.xls"');
    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
               xmlns:x="urn:schemas-microsoft-com:office:excel"
               xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta charset="UTF-8"><style>
    body{font-family:Calibri,Arial;font-size:10pt;}
    table{border-collapse:collapse;width:100%;}
    th,td{border:1px solid #aaa;padding:3px 5px;font-size:9pt;}
    .hdr{background:#1a3c5e;color:#fff;font-weight:bold;text-align:center;}
    .bk{background:#1a3c5e;color:#fff;font-weight:bold;}
    .nt{background:#eaf3fb;}.tr{background:#fef6e4;}
    .tot{background:#d5f5e3;font-weight:bold;border-top:2px solid #186a3b;}
    .num{text-align:right;}.ctr{text-align:center;}
    </style></head><body>';
    echo '<p style="font-size:12pt;font-weight:bold">Janak Education Materials Centre — Deno Production Report</p>';
    echo '<p style="font-size:9pt;color:#555">Range: ' . htmlspecialchars($from_date_nep ?: 'All') . ' → ' . htmlspecialchars($to_date_nep ?: 'All');
    if (!empty($book_code_filter)) echo ' | Books: ' . implode(', ', array_map('htmlspecialchars', $book_code_filter));
    echo ' | Generated: ' . date('Y-m-d H:i:s') . '</p>';
    echo '<table><tr>';
    foreach (array('SN','Ref No','Book Code','Book Name','Class','T/NT','Date (Nep)','Date (Eng)','Month','FY','Per Poka','Poka Qty','Total Qty','Open Pcs','Net','Entry','JT Code','Notes','Created By') as $h)
        echo '<th class="hdr">' . $h . '</th>';
    echo '</tr>';
    $sn = 1; $prev_bk = null;
    foreach ($rows as $r) {
        $trans = ($r['is_translated'] === 't' || $r['is_translated'] === true);
        $cls = $trans ? 'tr' : 'nt';
        if ($r['book_code'] !== $prev_bk) {
            $prev_bk = $r['book_code'];
            echo '<tr><td class="bk" colspan="19">▸ ' . htmlspecialchars($r['book_code']) . ' — ' . htmlspecialchars($r['book_name']) . ' (Class ' . $r['class_level'] . ')</td></tr>';
        }
        $net = intval($r['total_qty']) - intval($r['quantity_openpcs']);
        echo '<tr class="' . $cls . '">';
        echo '<td class="ctr">' . $sn++ . '</td>';
        echo '<td>' . htmlspecialchars($r['ref_no']) . '</td>';
        echo '<td style="font-weight:bold">' . htmlspecialchars($r['book_code']) . '</td>';
        echo '<td>' . htmlspecialchars($r['book_name']) . '</td>';
        echo '<td class="ctr">' . $r['class_level'] . '</td>';
        echo '<td class="ctr">' . ($trans?'T':'NT') . '</td>';
        echo '<td class="ctr">' . htmlspecialchars($r['deno_date_nep']) . '</td>';
        echo '<td class="ctr">' . htmlspecialchars($r['deno_date_eng']) . '</td>';
        echo '<td class="ctr">' . htmlspecialchars($r['deno_month']) . '</td>';
        echo '<td class="ctr">' . htmlspecialchars($r['deno_year']) . '</td>';
        echo '<td class="num">' . number_format($r['per_poka_qty']) . '</td>';
        echo '<td class="num">' . number_format($r['poka_qty']) . '</td>';
        echo '<td class="num" style="font-weight:bold">' . number_format($r['total_qty']) . '</td>';
        echo '<td class="num" style="color:#a93226">' . number_format($r['quantity_openpcs']) . '</td>';
        echo '<td class="num" style="color:#186a3b;font-weight:bold">' . number_format($net) . '</td>';
        echo '<td class="ctr">' . htmlspecialchars($r['entry_type']) . '</td>';
        echo '<td>' . htmlspecialchars($r['job_ticket_code'] ?: '—') . '</td>';
        echo '<td>' . htmlspecialchars($r['notes'] ?: '') . '</td>';
        echo '<td>' . htmlspecialchars($r['created_by_name'] ?: '') . '</td>';
        echo '</tr>';
    }
    echo '<tr class="tot"><td></td><td colspan="10" style="font-size:10pt">GRAND TOTAL (' . count($rows) . ' entries)</td>';
    echo '<td class="num">' . number_format($total_poka) . '</td>';
    echo '<td class="num">' . number_format($total_total_qty) . '</td>';
    echo '<td class="num">' . number_format($total_openpcs) . '</td>';
    echo '<td class="num">' . number_format($net_production) . '</td>';
    echo '<td colspan="4"></td></tr>';
    echo '</table></body></html>';
    exit;
}

// ── Now safe to include header (no export path reached here) ──────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ── Export URL helper ─────────────────────────────────────────────────
$get_clean = $_GET; unset($get_clean['export']);
$qs = http_build_query($get_clean);
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

/* Active book banner (when opened from JT report link) */
.book-banner {
    background: linear-gradient(135deg, #1a3c5e 0%, #2563a8 100%);
    color: #fff; border-radius: 8px; padding: 10px 16px; margin-bottom: 14px;
    display: flex; align-items: center; gap: 12px; flex-wrap: wrap;
}
.book-banner .bb-code { font-size: 18px; font-weight: 800; letter-spacing: .5px; }
.book-banner .bb-name { font-size: 13px; opacity: .9; }
.book-banner .bb-range { font-size: 11px; background: rgba(255,255,255,.2); padding: 3px 10px; border-radius: 10px; }
.book-banner a { color: #afd3ff; font-size: 11px; text-decoration: none; }
.book-banner a:hover { color: #fff; }

/* Filter panel */
.filter-panel { background:#fff; border:1px solid #c8d6e5; border-radius:8px; padding:14px 16px; margin-bottom:14px; box-shadow:var(--shadow); }
.filter-panel h4 { font-size:13px; color:var(--blue2); margin-bottom:10px; font-weight:700; }
.filter-row { display:flex; flex-wrap:wrap; gap:10px; align-items:flex-end; margin-bottom:8px; }
.fg { display:flex; flex-direction:column; min-width:130px; }
.fg label { font-size:10px; font-weight:700; color:#556; text-transform:uppercase; letter-spacing:.5px; margin-bottom:3px; }
.fg input, .fg select { padding:5px 9px; border:1px solid #b0bec5; border-radius:4px; font-size:12px; background:#fafbfc; }
.fg select[multiple] { height:70px; }
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
.btn-back      { background:#e8edf4; color:var(--blue); border:1px solid #b8c8d8; }

/* Summary cards */
.cards { display:flex; gap:10px; flex-wrap:wrap; margin:12px 0; }
.card  { background:#fff; border:1px solid #c8d6e5; border-radius:8px; padding:10px 16px;
         text-align:center; flex:1; min-width:110px; box-shadow:var(--shadow); }
.card-num { font-size:22px; font-weight:800; color:var(--blue2); }
.card-lbl { font-size:10px; color:#778; margin-top:2px; text-transform:uppercase; letter-spacing:.4px; }

/* Book summary strip */
.book-summary { display:flex; flex-wrap:wrap; gap:8px; margin:10px 0; }
.bk-card {
    background:#fff; border:1px solid #c8d6e5; border-radius:6px;
    padding:8px 12px; font-size:12px; box-shadow:var(--shadow); min-width:180px;
    display:flex; flex-direction:column; gap:2px;
}
.bk-card .bk-code { font-weight:800; color:var(--blue2); font-size:12.5px; }
.bk-card .bk-qty  { font-weight:700; color:var(--green); font-size:13px; }
.bk-card .bk-meta { font-size:10px; color:#778; }

/* Export strip */
.export-strip { background:#e8edf4; border-radius:6px; padding:9px 14px; margin:10px 0;
                display:flex; gap:8px; align-items:center; flex-wrap:wrap; }

/* Table */
.tbl-wrap { overflow-x:auto; border-radius:8px; box-shadow:var(--shadow); margin-top:6px; }
table.rpt { width:100%; border-collapse:collapse; font-size:12px; background:#fff; }
table.rpt th { background:var(--blue); color:#fff; padding:7px 7px; border:1px solid #2a4f70;
               font-size:11px; font-weight:700; white-space:nowrap; text-align:center; }
table.rpt td { border:1px solid #c8d8e8; padding:5px 7px; vertical-align:middle; }
table.rpt tbody tr:hover td { filter:brightness(.96); }

/* Book group header */
tr.bk-hdr td { background:#2563a8; color:#fff; font-weight:800; font-size:12px; padding:5px 10px; }

/* NT/T tinting */
tr.row-nt td { background:var(--nt-row); }
tr.row-t  td { background:var(--t-row);  }

/* Total row */
tr.total-row td { background:#d5f5e3 !important; font-weight:800; border-top:3px solid var(--green); }
tr.sub-total td { background:#e8f4fd !important; font-weight:700; font-style:italic; }

/* Entry type badges */
.et-direct { background:#e8f4fd; color:#1a4f8a; border:1px solid #a8d0ec; }
.et-jt     { background:#fff3cd; color:#7d6608; border:1px solid #f0c040; }
.et-bp     { background:#d4edda; color:#155724; border:1px solid #80c694; }
.et-badge  { display:inline-block; padding:1px 7px; border-radius:10px; font-size:10px; font-weight:700; }

.num    { text-align:right; }
.center { text-align:center; }
.type-t  { color:#0e6655; font-weight:800; font-size:11px; }
.type-nt { color:var(--blue2); font-weight:800; font-size:11px; }

.ftag { display:inline-block; background:var(--blue2); color:#fff; padding:2px 8px; border-radius:10px; font-size:10px; margin:2px; }

/* ═══  PRINT  ═══ */
@media print {
    .no-print, .filter-panel, .export-strip, .cards, .book-summary { display:none !important; }
    * { -webkit-print-color-adjust:exact !important; print-color-adjust:exact !important; }
    body { font-size:8.5pt; background:#fff; margin:0; padding:0; }
    .page-wrap { padding:2px; }
    .page-title { font-size:12pt; border-bottom:2px solid #000; margin-bottom:4px; color:#000; }
    .book-banner { background:#1a3c5e !important; -webkit-print-color-adjust:exact; margin-bottom:6px; padding:6px 10px; }
    .print-header { display:block !important; }
    .tbl-wrap { overflow:visible; box-shadow:none; }
    table.rpt { font-size:7.5pt; }
    table.rpt th { padding:2px 3px; font-size:7.5pt; background:var(--blue) !important; color:#fff !important; }
    table.rpt td { padding:2px 4px; border:1px solid #aaa; font-size:7.5pt; }
    tr.bk-hdr td  { background:#2563a8 !important; color:#fff !important; }
    tr.row-nt td  { background:#dbeaf8 !important; }
    tr.row-t  td  { background:#fef3d0 !important; }
    tr.total-row td { background:#c8e6c9 !important; font-weight:800 !important; }
    tr.sub-total td { background:#d6eaf8 !important; }
    .et-badge { border:1px solid #888 !important; background:#eee !important; color:#000 !important; }
    @page { size:A3 landscape; margin:0.5cm; }
}
.print-header { display:none; text-align:center; margin-bottom:6px; border-bottom:1px solid #ccc; padding-bottom:3px; }
.print-header h3 { font-size:12pt; margin:0 0 2px; }
.print-header p  { font-size:7.5pt; color:#555; margin:0; }
</style>

<div class="page-wrap">

<!-- Print header -->
<div class="print-header">
    <h3>Janak Education Materials Centre — Deno Production Report</h3>
    <p>
        <?= !empty($book_code_filter) ? 'Books: ' . implode(', ', array_map('htmlspecialchars', $book_code_filter)) . ' | ' : 'All Books | ' ?>
        Range: <?= htmlspecialchars($from_date_nep ?: 'All') ?> → <?= htmlspecialchars($to_date_nep ?: 'All') ?>
        | Generated: <?= date('Y-m-d H:i:s') ?>
    </p>
</div>

<div class="page-title">📄 Deno Production Report</div>

<!-- Book banner (when filtered from JT report) -->
<?php if (count($book_code_filter) === 1 && !empty($book_summaries)): 
    $bk = reset($book_summaries);
    $bc = key($book_summaries); ?>
<div class="book-banner no-print">
    <div>
        <div class="bb-code"><?= htmlspecialchars($bc) ?></div>
        <div class="bb-name"><?= htmlspecialchars($bk['book_name']) ?> &nbsp;·&nbsp; Class <?= $bk['class_level'] ?> &nbsp;·&nbsp; <?= $bk['is_translated'] ? 'Translated' : 'Non-Translated' ?></div>
    </div>
    <?php if ($from_date_nep || $to_date_nep): ?>
    <div class="bb-range">
        📅 <?= htmlspecialchars($from_date_nep ?: '?') ?> → <?= htmlspecialchars($to_date_nep ?: '?') ?>
    </div>
    <?php endif; ?>
    <div style="margin-left:auto">
        <a href="javascript:history.back()">← Back to JT Report</a>
    </div>
</div>
<?php endif; ?>

<!-- Filter panel -->
<div class="filter-panel no-print">
    <h4>🔍 Filters</h4>
    <form method="GET">
        <div class="filter-row">
            <div class="fg">
                <label>From Date (Nep)</label>
                <input type="text" name="from_date_nep" value="<?= htmlspecialchars($from_date_nep) ?>" placeholder="YYYY.MM.DD">
                <span class="hint">e.g. 2082.04.01</span>
            </div>
            <div class="fg">
                <label>To Date (Nep)</label>
                <input type="text" name="to_date_nep" value="<?= htmlspecialchars($to_date_nep) ?>" placeholder="YYYY.MM.DD">
                <span class="hint">e.g. 2083.03.32</span>
            </div>
            <div class="fg">
                <label>Fiscal Year</label>
                <select name="fiscal_year">
                    <option value="">All</option>
                    <?php foreach ($fy_list as $fy): ?>
                    <option value="<?= htmlspecialchars($fy) ?>" <?= ($fiscal_year_f == $fy) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fy) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Book Code</label>
                <select name="book_code[]" multiple>
                    <?php foreach ($bk_list as $bk): ?>
                    <option value="<?= htmlspecialchars($bk['book_code']) ?>"
                        <?= in_array($bk['book_code'], $book_code_filter) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($bk['book_code']) ?> (Cl.<?= $bk['class_level'] ?>)
                    </option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Ctrl/Cmd = multi</span>
            </div>
            <div class="fg">
                <label>Class</label>
                <select name="class_filter[]" multiple>
                    <?php foreach ($cls_list as $c): ?>
                    <option value="<?= $c ?>" <?= in_array($c, $class_filter) ? 'selected' : '' ?>>Class <?= $c ?></option>
                    <?php endforeach; ?>
                </select>
                <span class="hint">Ctrl/Cmd = multi</span>
            </div>
            <div class="fg">
                <label>Type</label>
                <select name="type_filter">
                    <option value="">All</option>
                    <option value="translated"     <?= ($type_filter == 'translated')     ? 'selected' : '' ?>>Translated</option>
                    <option value="non_translated" <?= ($type_filter == 'non_translated') ? 'selected' : '' ?>>Non-Translated</option>
                </select>
            </div>
            <div class="fg">
                <label>Entry Type</label>
                <select name="entry_type">
                    <option value="">All</option>
                    <option value="direct"  <?= ($entry_type_f == 'direct')  ? 'selected' : '' ?>>Direct</option>
                    <option value="from_jt" <?= ($entry_type_f == 'from_jt') ? 'selected' : '' ?>>From JT</option>
                    <option value="from_bp" <?= ($entry_type_f == 'from_bp') ? 'selected' : '' ?>>From BP</option>
                </select>
            </div>
        </div>
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">▶ Apply</button>
            <a href="?" class="btn btn-secondary">✕ Clear</a>
            <?php if (count($book_code_filter) === 1): ?>
            <a href="javascript:history.back()" class="btn btn-back">← Back to JT Report</a>
            <?php endif; ?>
        </div>
    </form>
</div>

<!-- Summary cards -->
<div class="cards no-print">
    <div class="card">
        <div class="card-num"><?= count($rows) ?></div>
        <div class="card-lbl">Deno Entries</div>
    </div>
    <div class="card">
        <div class="card-num"><?= count($book_summaries) ?></div>
        <div class="card-lbl">Books</div>
    </div>
    <div class="card">
        <div class="card-num"><?= number_format($total_total_qty) ?></div>
        <div class="card-lbl">Total Produced</div>
    </div>
    <div class="card">
        <div class="card-num" style="color:var(--red)"><?= number_format($total_openpcs) ?></div>
        <div class="card-lbl">Open Pcs (Defective)</div>
    </div>
    <div class="card">
        <div class="card-num" style="color:var(--green)"><?= number_format($net_production) ?></div>
        <div class="card-lbl">Net Production</div>
    </div>
</div>

<!-- Per-book summary chips -->
<?php if (count($book_summaries) > 1): ?>
<div class="book-summary no-print">
    <?php foreach ($book_summaries as $bc => $bk): ?>
    <div class="bk-card">
        <div class="bk-code"><?= htmlspecialchars($bc) ?> <span style="font-size:10px;color:#aaa">(Cl.<?= $bk['class_level'] ?>)</span></div>
        <div class="bk-qty"><?= number_format($bk['total_qty']) ?></div>
        <div class="bk-meta"><?= $bk['entries'] ?> entries · <?= $bk['is_translated'] ? 'T' : 'NT' ?></div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- Export strip -->
<div class="export-strip no-print">
    <strong>Export / Print:</strong>
    <a href="?<?= $qs ?>&amp;export=csv"   class="btn btn-csv">📊 Download CSV</a>
    <a href="?<?= $qs ?>&amp;export=excel" class="btn btn-excel">📈 Download Excel</a>
    <button onclick="window.print()" class="btn btn-print">🖨️ Print A3 Landscape</button>
</div>

<!-- Table -->
<div class="tbl-wrap">
<?php if (empty($rows)): ?>
    <p style="text-align:center;padding:40px;color:#aaa;font-size:14px">No deno entries found for the selected filters.</p>
<?php else: ?>
<table class="rpt">
<thead>
    <tr>
        <th>SN</th>
        <th>Ref No</th>
        <th>Book Code</th>
        <th style="min-width:140px;text-align:left">Book Name</th>
        <th>Class</th>
        <th>T/NT</th>
        <th>Date (Nep)</th>
        <th>Month</th>
        <th>FY</th>
        <th class="num">Per Poka</th>
        <th class="num">Poka Qty</th>
        <th class="num" style="color:#a0d0ff">Total Qty</th>
        <th class="num" style="color:#ffb3b3">Open Pcs</th>
        <th class="num" style="color:#90e4a8">Net</th>
        <th>Entry</th>
        <th>JT Code</th>
        <th style="min-width:120px">Notes</th>
        <th>Created By</th>
    </tr>
</thead>
<tbody>
<?php
$sn       = 1;
$prev_bk  = null;
$bk_tot_qty = 0;
$bk_tot_opn = 0;

foreach ($rows as $idx => $r):
    $trans   = ($r['is_translated'] === 't' || $r['is_translated'] === true);
    $row_cls = $trans ? 'row-t' : 'row-nt';
    $net     = intval($r['total_qty']) - intval($r['quantity_openpcs']);

    // Book group header + sub-total for previous book
    if ($r['book_code'] !== $prev_bk):
        // Print sub-total for previous book (if any)
        if ($prev_bk !== null): ?>
    <tr class="sub-total">
        <td></td>
        <td colspan="9" style="font-size:11px;text-align:right">Subtotal <?= htmlspecialchars($prev_bk) ?> :</td>
        <td></td>
        <td class="num"><?= number_format($bk_tot_qty) ?></td>
        <td class="num"><?= number_format($bk_tot_opn) ?></td>
        <td class="num"><?= number_format($bk_tot_qty - $bk_tot_opn) ?></td>
        <td colspan="4"></td>
    </tr>
<?php      endif;
        $prev_bk    = $r['book_code'];
        $bk_tot_qty = 0;
        $bk_tot_opn = 0;
?>
    <tr class="bk-hdr">
        <td colspan="18">
            ▸ <?= htmlspecialchars($r['book_code']) ?>
            &nbsp;&nbsp;<?= htmlspecialchars($r['book_name']) ?>
            &nbsp;&nbsp;<span style="font-size:10px;opacity:.8">(Class <?= $r['class_level'] ?> · <?= $trans ? 'Translated' : 'Non-Translated' ?>)</span>
        </td>
    </tr>
<?php
    endif;

    $bk_tot_qty += intval($r['total_qty']);
    $bk_tot_opn += intval($r['quantity_openpcs']);

    // Entry type badge
    $et_map = array('direct' => 'et-direct', 'from_jt' => 'et-jt', 'from_bp' => 'et-bp');
    $et_cls = isset($et_map[$r['entry_type']]) ? $et_map[$r['entry_type']] : 'et-direct';
    $et_lbl = array('direct' => 'Direct', 'from_jt' => 'JT', 'from_bp' => 'BP');
    $et_txt = isset($et_lbl[$r['entry_type']]) ? $et_lbl[$r['entry_type']] : htmlspecialchars($r['entry_type']);
?>
    <tr class="<?= $row_cls ?>">
        <td class="center"><?= $sn++ ?></td>
        <td style="font-size:11px;color:#556"><?= htmlspecialchars($r['ref_no']) ?></td>
        <td style="font-weight:700;font-size:11px"><?= htmlspecialchars($r['book_code']) ?></td>
        <td style="font-size:11.5px"><?= htmlspecialchars($r['book_name']) ?></td>
        <td class="center" style="font-weight:700"><?= $r['class_level'] ?></td>
        <td class="center">
            <?= $trans ? '<span class="type-t">T</span>' : '<span class="type-nt">NT</span>' ?>
        </td>
        <td class="center" style="font-size:11px;font-weight:600"><?= htmlspecialchars($r['deno_date_nep']) ?></td>
        <td class="center" style="font-size:10px;color:#556"><?= htmlspecialchars($r['deno_month']) ?></td>
        <td class="center" style="font-size:10px"><?= htmlspecialchars($r['deno_year']) ?></td>
        <td class="num" style="font-size:11px"><?= number_format($r['per_poka_qty']) ?></td>
        <td class="num" style="font-size:11px"><?= number_format($r['poka_qty']) ?></td>
        <td class="num" style="font-weight:700;font-size:12.5px"><?= number_format($r['total_qty']) ?></td>
        <td class="num" style="color:var(--red);font-size:11.5px"><?= number_format($r['quantity_openpcs']) ?></td>
        <td class="num" style="color:var(--green);font-weight:700;font-size:12.5px"><?= number_format($net) ?></td>
        <td class="center"><span class="et-badge <?= $et_cls ?>"><?= $et_txt ?></span></td>
        <td style="font-size:10.5px;color:#1a55a0"><?= htmlspecialchars($r['job_ticket_code'] ?: '—') ?></td>
        <td style="font-size:10px;color:#667"><?= htmlspecialchars($r['notes'] ?: '') ?></td>
        <td style="font-size:10px;color:#667"><?= htmlspecialchars($r['created_by_name'] ?: '') ?></td>
    </tr>
<?php endforeach;

// Last book sub-total
if ($prev_bk !== null): ?>
    <tr class="sub-total">
        <td></td>
        <td colspan="9" style="font-size:11px;text-align:right">Subtotal <?= htmlspecialchars($prev_bk) ?> :</td>
        <td></td>
        <td class="num"><?= number_format($bk_tot_qty) ?></td>
        <td class="num"><?= number_format($bk_tot_opn) ?></td>
        <td class="num"><?= number_format($bk_tot_qty - $bk_tot_opn) ?></td>
        <td colspan="4"></td>
    </tr>
<?php endif; ?>

<!-- Grand total -->
<tr class="total-row">
    <td></td>
    <td colspan="9" style="font-size:13px;letter-spacing:.4px">GRAND TOTAL — <?= count($rows) ?> entries · <?= count($book_summaries) ?> books</td>
    <td></td>
    <td class="num" style="font-size:13px"><?= number_format($total_total_qty) ?></td>
    <td class="num" style="font-size:13px;color:var(--red)"><?= number_format($total_openpcs) ?></td>
    <td class="num" style="font-size:13px;color:var(--green)"><?= number_format($net_production) ?></td>
    <td colspan="4"></td>
</tr>
</tbody>
</table>
<?php endif; ?>
</div>

<div style="margin-top:10px;font-size:11px;color:#aaa" class="no-print">
    <?= count($rows) ?> entries | Generated: <?= date('Y-m-d H:i:s') ?>
    <?php if ($from_date_nep || $to_date_nep): ?>
    | Range: <code><?= htmlspecialchars($from_date_nep) ?> → <?= htmlspecialchars($to_date_nep) ?></code>
    <?php endif; ?>
</div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

<?php
/**
 * job_ticket_export.php — CSV / Excel export
 * Mirrors daily.php exactly:
 *  - One row per book, lots merged as chips
 *  - Deno matched via deno_date_nep range (not enum cast)
 *  - TRUE grand total deno fetched independently (includes books without JT)
 *  - Unmatched deno (no-JT books) shown separately in grand total
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

// ── Filters ───────────────────────────────────────────────────────────
$fiscal_year_filter = isset($_GET['fiscal_year_filter']) ? trim($_GET['fiscal_year_filter']) : '';
$class_filter       = isset($_GET['class_filter'])       ? $_GET['class_filter']             : array();
$book_code_filter   = isset($_GET['book_code_filter'])   ? $_GET['book_code_filter']          : array();
$status_filter      = isset($_GET['status_filter'])      ? trim($_GET['status_filter'])       : '';
$type_filter        = isset($_GET['type_filter'])        ? trim($_GET['type_filter'])         : '';
$export             = isset($_GET['export'])             ? trim($_GET['export'])              : 'csv';

if (is_string($class_filter))     $class_filter     = ($class_filter     === '') ? array() : array($class_filter);
if (is_string($book_code_filter)) $book_code_filter = ($book_code_filter === '') ? array() : array($book_code_filter);

// ── Active FY for default date range ─────────────────────────────────
$active_fy = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE is_active = true LIMIT 1")->fetchColumn();
if (!$active_fy) {
    $active_fy = $conn->query("SELECT fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC LIMIT 1")->fetchColumn();
}
$active_fy  = $active_fy ?: '2082';
$active_int = intval($fiscal_year_filter ? $fiscal_year_filter : $active_fy);

$deno_from = (isset($_GET['deno_from']) && trim($_GET['deno_from']) !== '')
             ? trim($_GET['deno_from'])
             : ($active_int . '.04.01');
$deno_to   = (isset($_GET['deno_to']) && trim($_GET['deno_to']) !== '')
             ? trim($_GET['deno_to'])
             : (($active_int + 1) . '.03.32');

// ── WHERE for JT query ────────────────────────────────────────────────
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
$sql = "
    SELECT
        fy.fiscal_code                      AS fiscal_year,
        b.book_code,
        b.book_name                         AS book_name_eng,
        b.book_name_preeti,
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
        SELECT book_code, SUM(total_qty) AS deno_printed
        FROM deno
        WHERE deleted_at IS NULL
          AND deno_date_nep >= :deno_from
          AND deno_date_nep <= :deno_to
        GROUP BY book_code
    ) deno_agg ON deno_agg.book_code = b.book_code
    $where_sql
    ORDER BY fy.fiscal_code, b.class_level, b.book_code, CAST(jt.lot AS integer) NULLS LAST
";

$all_params = array_merge(
    array(':deno_from' => $deno_from, ':deno_to' => $deno_to),
    $params
);
$stmt = $conn->prepare($sql);
$stmt->execute($all_params);
$raw = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Pivot: one row per book ───────────────────────────────────────────
$books = array();
foreach ($raw as $r) {
    $key   = $r['fiscal_year'] . '|' . $r['book_code'];
    $trans = ($r['is_translated'] === 't' || $r['is_translated'] === true);
    if (!isset($books[$key])) {
        $preeti = (isset($r['book_name_preeti']) && $r['book_name_preeti'] !== '' && $r['book_name_preeti'] !== null)
                  ? $r['book_name_preeti'] : $r['book_name_eng'];
        $books[$key] = array(
            'fiscal_year'      => $r['fiscal_year'],
            'book_code'        => $r['book_code'],
            'book_name_eng'    => $r['book_name_eng'],
            'book_name_preeti' => $preeti,
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
                      ? round(($bk['diff'] / $bk['total_jt_qty']) * 100, 1) : null;
}
unset($bk);

// ── JT-level totals ───────────────────────────────────────────────────
$gt_jt           = 0;
$gt_deno_matched = 0;   // deno for books that have a JT
foreach ($books as $bk) {
    $gt_jt           += $bk['total_jt_qty'];
    $gt_deno_matched += $bk['deno_printed'];
}

// ── TRUE grand total deno (independent query, same as daily.php) ──────
$true_deno_params = array(':td_from' => $deno_from, ':td_to' => $deno_to);
$true_deno_where  = array("deleted_at IS NULL", "deno_date_nep >= :td_from", "deno_date_nep <= :td_to");

if (!empty($book_code_filter)) {
    $ph = array();
    foreach ($book_code_filter as $i => $v) { $ph[] = ":tdbkc$i"; $true_deno_params[":tdbkc$i"] = $v; }
    $true_deno_where[] = "book_code IN (" . implode(',', $ph) . ")";
}

$true_deno_sql = "SELECT COALESCE(SUM(total_qty), 0) FROM deno WHERE " . implode(' AND ', $true_deno_where);
$td_stmt = $conn->prepare($true_deno_sql);
$td_stmt->execute($true_deno_params);
$gt_deno_true      = intval($td_stmt->fetchColumn());
$gt_deno_unmatched = $gt_deno_true - $gt_deno_matched;

// Diff uses true deno vs JT qty (mirrors daily.php)
$gt_diff     = $gt_deno_true - $gt_jt;
$gt_diff_pct = ($gt_jt > 0) ? round(($gt_diff / $gt_jt) * 100, 1) : null;

$max_lots = 0;
foreach ($books as $bk) $max_lots = max($max_lots, count($bk['lots']));

$ts = date('Y-m-d_H-i-s');

// ── Diff helpers ──────────────────────────────────────────────────────
function xls_diff($diff, $pct) {
    if ($diff > 0)    return array('over',  '+' . number_format($diff), ($pct !== null ? '+' . $pct . '%' : 'N/A'), 'Over-produced');
    if ($diff < 0)    return array('under', number_format($diff),       ($pct !== null ? $pct . '%'      : 'N/A'), 'Under-produced');
    return             array('exact', '—',  '0.0%', 'Exact');
}

// ══════════════════════════════════════════════════════════════════════
// CSV
// ══════════════════════════════════════════════════════════════════════
if ($export === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="jt_deno_report_' . $ts . '.csv"');
    $out = fopen('php://output', 'w');
    fputs($out, "\xEF\xBB\xBF");   // UTF-8 BOM for Excel

    // ── Title block ───────────────────────────────────────────────────
    fputcsv($out, array('Janak Education Materials Centre — Job Ticket vs Deno Production Report'));
    fputcsv($out, array(
        'FY: '          . ($fiscal_year_filter ?: 'All'),
        'Deno Range: '  . $deno_from . ' to ' . $deno_to,
        'Generated: '   . date('Y-m-d H:i:s'),
    ));
    fputcsv($out, array(
        'True Total Deno: ' . $gt_deno_true,
        'JT-matched Deno: ' . $gt_deno_matched,
        ($gt_deno_unmatched > 0
            ? 'No-JT Deno (excluded from table): ' . $gt_deno_unmatched
            : 'All deno entries matched to a JT'),
    ));
    fputcsv($out, array());   // blank separator

    // ── Column headers ────────────────────────────────────────────────
    $hdr = array('SN', 'Fiscal Year', 'Book Code', 'Book Name (Eng)', 'Book Name (Preeti)',
                 'Class', 'Type', 'Total JT Qty');
    for ($i = 1; $i <= $max_lots; $i++) {
        $hdr[] = "Lot $i JT Code";
        $hdr[] = "Lot $i Lot#";
        $hdr[] = "Lot $i Qty";
        $hdr[] = "Lot $i Status";
    }
    $hdr[] = 'Deno Printed (JT-matched)';
    $hdr[] = 'Difference (Deno − JT)';
    $hdr[] = 'Diff %';
    $hdr[] = 'Match';
    fputcsv($out, $hdr);

    // ── Data rows ─────────────────────────────────────────────────────
    $sn = 1;
    foreach ($books as $bk) {
        $type  = $bk['is_translated'] ? 'Translated' : 'Non-Translated';
        $diff  = $bk['diff'];
        $pct   = ($bk['diff_pct'] !== null) ? $bk['diff_pct'] . '%' : 'N/A';
        $match = ($diff > 0) ? 'Over-produced' : (($diff < 0) ? 'Under-produced' : 'Exact');

        $row = array(
            $sn++,
            $bk['fiscal_year'],
            $bk['book_code'],
            $bk['book_name_eng'],
            $bk['book_name_preeti'],
            $bk['class'],
            $type,
            $bk['total_jt_qty'],
        );

        for ($i = 0; $i < $max_lots; $i++) {
            if (isset($bk['lots'][$i])) {
                $l = $bk['lots'][$i];
                $row[] = $l['jt_code'];
                $row[] = 'Lot ' . $l['lot'];
                $row[] = $l['qty'];
                $row[] = $l['status'];
            } else {
                $row[] = ''; $row[] = ''; $row[] = ''; $row[] = '';
            }
        }

        $row[] = $bk['deno_printed'];
        $row[] = ($diff >= 0 ? '+' : '') . $diff;
        $row[] = ($diff >= 0 ? '+' : '') . $pct;
        $row[] = $match;
        fputcsv($out, $row);
    }

    // ── Grand total rows ──────────────────────────────────────────────
    fputcsv($out, array());   // spacer

    // Row A: JT-matched totals
    $gt_d = $gt_deno_matched;
    $gt_d_diff = $gt_deno_matched - $gt_jt;
    $gt_d_pct  = ($gt_jt > 0) ? round(($gt_d_diff / $gt_jt) * 100, 1) . '%' : 'N/A';
    $gt_match_a = ($gt_d_diff > 0) ? 'Over-produced' : (($gt_d_diff < 0) ? 'Under-produced' : 'Exact');
    $row_a = array('', 'SUBTOTAL (JT-matched books only)', '', '', '', '', '', $gt_jt);
    for ($i = 0; $i < $max_lots * 4; $i++) $row_a[] = '';
    $row_a[] = $gt_deno_matched;
    $row_a[] = ($gt_d_diff >= 0 ? '+' : '') . $gt_d_diff;
    $row_a[] = ($gt_d_diff >= 0 ? '+' : '') . $gt_d_pct;
    $row_a[] = $gt_match_a;
    fputcsv($out, $row_a);

    // Row B: unmatched (no-JT books), only if non-zero
    if ($gt_deno_unmatched > 0) {
        $row_b = array('', 'DENO from books with NO Job Ticket (not in table above)', '', '', '', '', '', 0);
        for ($i = 0; $i < $max_lots * 4; $i++) $row_b[] = '';
        $row_b[] = $gt_deno_unmatched;
        $row_b[] = '';
        $row_b[] = '';
        $row_b[] = 'No JT';
        fputcsv($out, $row_b);
    }

    // Row C: TRUE grand total
    $gtp_str  = ($gt_diff_pct !== null) ? ($gt_diff_pct >= 0 ? '+' : '') . $gt_diff_pct . '%' : 'N/A';
    $gt_matchc = ($gt_diff > 0) ? 'Over-produced' : (($gt_diff < 0) ? 'Under-produced' : 'Exact');
    $row_c = array('', 'GRAND TOTAL (True Deno vs JT)', '', '', '', '', '', $gt_jt);
    for ($i = 0; $i < $max_lots * 4; $i++) $row_c[] = '';
    $row_c[] = $gt_deno_true;
    $row_c[] = ($gt_diff >= 0 ? '+' : '') . $gt_diff;
    $row_c[] = $gtp_str;
    $row_c[] = $gt_matchc;
    fputcsv($out, $row_c);

    fclose($out);
    exit;
}

// ══════════════════════════════════════════════════════════════════════
// Excel (.xls XML)
// ══════════════════════════════════════════════════════════════════════
if ($export === 'excel') {
    header('Content-Type: application/vnd.ms-excel; charset=utf-8');
    header('Content-Disposition: attachment; filename="jt_deno_report_' . $ts . '.xls"');

    echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
    echo '<html xmlns:o="urn:schemas-microsoft-com:office:office"
               xmlns:x="urn:schemas-microsoft-com:office:excel"
               xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta charset="UTF-8"><style>
    body  { font-family:Calibri,Arial; font-size:10pt; }
    table { border-collapse:collapse; width:100%; }
    th,td { border:1px solid #aaa; padding:4px 6px; font-size:9pt; }
    .hdr  { background:#1a3c5e; color:#fff; font-weight:bold; text-align:center; }
    .hdr2 { background:#0e5f3a; color:#fff; font-weight:bold; text-align:center; }
    .hdrl { background:#1a5276; color:#fff; font-weight:bold; text-align:center; font-size:8pt; }
    .ttl  { background:#1a3c5e; color:#fff; font-weight:bold; }
    .nt   { background:#eaf3fb; }
    .tr   { background:#fef6e4; }
    .tot-match   { background:#d5f5e3; font-weight:bold; border-top:2px solid #186a3b; }
    .tot-nomatch { background:#fff3cd; font-weight:bold; border-top:1px solid #f0c040; }
    .tot-true    { background:#1a3c5e; color:#fff; font-weight:bold; font-size:11pt; border-top:3px solid #0d2a45; }
    .num  { text-align:right; }
    .ctr  { text-align:center; }
    .over  { color:#b7770d; font-weight:bold; }
    .under { color:#a93226; font-weight:bold; }
    .exact { color:#186a3b; font-weight:bold; }
    .warn  { color:#7d6608; font-weight:bold; }
    .note  { background:#fffbe6; font-size:8pt; color:#555; }
    </style></head><body>';

    // ── Report title ──────────────────────────────────────────────────
    echo '<p style="font-size:13pt;font-weight:bold;margin:0 0 3px">Janak Education Materials Centre — Job Ticket vs Deno Production Report</p>';
    echo '<p style="font-size:9pt;color:#555;margin:0 0 4px">';
    echo 'FY: ' . htmlspecialchars($fiscal_year_filter ?: 'All');
    echo ' &nbsp;|&nbsp; Deno Range: ' . htmlspecialchars($deno_from) . ' → ' . htmlspecialchars($deno_to);
    if (!empty($class_filter)) echo ' &nbsp;|&nbsp; Class: ' . implode(', ', $class_filter);
    echo ' &nbsp;|&nbsp; Generated: ' . date('Y-m-d H:i:s') . '</p>';

    // ── Totals summary box ────────────────────────────────────────────
    echo '<table style="width:auto;margin-bottom:10px;font-size:9pt">';
    echo '<tr>';
    echo '<td style="background:#1a3c5e;color:#fff;font-weight:bold;padding:4px 10px">Total JT Qty</td>';
    echo '<td style="background:#d5f5e3;font-weight:bold;padding:4px 10px;text-align:right">' . number_format($gt_jt) . '</td>';
    echo '<td style="background:#1a3c5e;color:#fff;font-weight:bold;padding:4px 10px">True Total Deno</td>';
    echo '<td style="background:#d5f5e3;font-weight:bold;padding:4px 10px;text-align:right;color:#186a3b">' . number_format($gt_deno_true) . '</td>';
    echo '<td style="background:#1a3c5e;color:#fff;font-weight:bold;padding:4px 10px">JT-matched Deno</td>';
    echo '<td style="background:#eaf3fb;font-weight:bold;padding:4px 10px;text-align:right">' . number_format($gt_deno_matched) . '</td>';
    if ($gt_deno_unmatched > 0) {
        echo '<td style="background:#fff3cd;font-weight:bold;color:#7d6608;padding:4px 10px">No-JT Deno ⚠</td>';
        echo '<td style="background:#fff3cd;font-weight:bold;color:#7d6608;padding:4px 10px;text-align:right">' . number_format($gt_deno_unmatched) . '</td>';
    }
    echo '</tr></table>';

    if ($gt_deno_unmatched > 0) {
        echo '<p class="note" style="background:#fffbe6;border:1px solid #f0c040;padding:5px 10px;border-radius:4px;margin-bottom:8px">';
        echo '⚠ <strong>Note:</strong> True Total Deno (' . number_format($gt_deno_true) . ') is higher than JT-matched Deno ('
            . number_format($gt_deno_matched) . ') by <strong>' . number_format($gt_deno_unmatched)
            . '</strong>. This difference comes from deno entries for books that have no job ticket in the selected period.';
        echo '</p>';
    }

    // ── Column widths / lot colspan ───────────────────────────────────
    $lot_colspan = $max_lots * 3;   // jt_code + lot# + qty per lot
    $colspan_all = 8 + $lot_colspan + 4;

    echo '<table>';

    // ── Header row 1 ──────────────────────────────────────────────────
    echo '<tr>';
    foreach (array('SN','FY','Book Code','Book Name (Eng)','Preeti Name','Class','Type','Total JT Qty') as $h)
        echo '<th class="hdr" rowspan="2">' . $h . '</th>';
    if ($max_lots > 0)
        echo '<th class="hdrl" colspan="' . $lot_colspan . '">Job Ticket Lots</th>';
    echo '<th class="hdr2" colspan="4">Deno Comparison (' . htmlspecialchars($deno_from) . ' → ' . htmlspecialchars($deno_to) . ')</th>';
    echo '</tr>';

    // ── Header row 2 ──────────────────────────────────────────────────
    echo '<tr>';
    for ($i = 1; $i <= $max_lots; $i++) {
        echo '<th class="hdrl">Lot ' . $i . ' JT Code</th>';
        echo '<th class="hdrl">Lot ' . $i . ' #</th>';
        echo '<th class="hdrl">Lot ' . $i . ' Qty</th>';
    }
    echo '<th class="hdr2">Deno Printed<br><span style="font-size:7pt;font-weight:400">(JT-matched)</span></th>';
    echo '<th class="hdr2">Difference<br><span style="font-size:7pt;font-weight:400">(Deno − JT)</span></th>';
    echo '<th class="hdr2">Diff %</th>';
    echo '<th class="hdr2">Match</th>';
    echo '</tr>';

    // ── Data rows ─────────────────────────────────────────────────────
    $sn         = 1;
    $prev_class = null;

    foreach ($books as $bk) {
        $trans = $bk['is_translated'];
        $cls   = $trans ? 'tr' : 'nt';
        $type  = $trans ? 'Translated' : 'Non-Translated';
        $diff  = $bk['diff'];
        $pct   = $bk['diff_pct'];

        // Class divider
        if ($bk['class'] !== $prev_class) {
            $prev_class = $bk['class'];
            echo '<tr><td class="ttl" colspan="' . $colspan_all . '">CLASS ' . htmlspecialchars($bk['class']) . '</td></tr>';
        }

        list($dc, $ds, $ps, $ms) = xls_diff($diff, $pct);

        echo '<tr class="' . $cls . '">';
        echo '<td class="ctr">'  . $sn++ . '</td>';
        echo '<td class="ctr">'  . htmlspecialchars($bk['fiscal_year'])  . '</td>';
        echo '<td style="font-weight:bold">' . htmlspecialchars($bk['book_code'])   . '</td>';
        echo '<td>'              . htmlspecialchars($bk['book_name_eng'])  . '</td>';
        echo '<td style="font-family:serif">' . htmlspecialchars($bk['book_name_preeti']) . '</td>';
        echo '<td class="ctr" style="font-weight:bold">' . htmlspecialchars($bk['class']) . '</td>';
        echo '<td class="ctr">'  . $type . '</td>';
        echo '<td class="num"><strong>' . number_format($bk['total_jt_qty']) . '</strong></td>';

        // Lot columns
        for ($i = 0; $i < $max_lots; $i++) {
            if (isset($bk['lots'][$i])) {
                $l = $bk['lots'][$i];
                echo '<td style="font-weight:bold;color:#1a55a0;font-size:8pt">' . htmlspecialchars($l['jt_code']) . '</td>';
                echo '<td class="ctr" style="color:#778;font-size:8pt">Lot ' . htmlspecialchars($l['lot']) . '</td>';
                echo '<td class="num" style="font-size:8pt">' . number_format($l['qty']) . '</td>';
            } else {
                echo '<td></td><td></td><td></td>';
            }
        }

        // Comparison
        echo '<td class="num" style="color:' . ($bk['deno_printed'] > 0 ? '#186a3b' : '#aaa') . ';font-weight:bold">'
             . number_format($bk['deno_printed']) . '</td>';
        echo '<td class="num ' . $dc . '">' . $ds . '</td>';
        echo '<td class="ctr ' . $dc . '">' . $ps . '</td>';
        echo '<td class="ctr ' . $dc . '">' . $ms . '</td>';
        echo '</tr>';
    }

    // ── Grand total rows (3 rows like daily.php) ──────────────────────

    // Row A: JT-matched subtotal
    $d_diff_a = $gt_deno_matched - $gt_jt;
    $d_pct_a  = ($gt_jt > 0) ? round(($d_diff_a / $gt_jt) * 100, 1) : null;
    list($gdc_a, $gds_a, $gps_a, $gms_a) = xls_diff($d_diff_a, $d_pct_a);

    echo '<tr class="tot-match">';
    echo '<td></td>';
    echo '<td colspan="6" style="font-size:10pt">SUBTOTAL — ' . count($books) . ' books (JT-matched only)</td>';
    echo '<td class="num">' . number_format($gt_jt) . '</td>';
    for ($i = 0; $i < $lot_colspan; $i++) echo '<td></td>';
    echo '<td class="num" style="color:#186a3b">' . number_format($gt_deno_matched) . '</td>';
    echo '<td class="num ' . $gdc_a . '">' . $gds_a . '</td>';
    echo '<td class="ctr ' . $gdc_a . '">' . $gps_a . '</td>';
    echo '<td class="ctr ' . $gdc_a . '">' . $gms_a . '</td>';
    echo '</tr>';

    // Row B: No-JT unmatched (only if non-zero)
    if ($gt_deno_unmatched > 0) {
        echo '<tr class="tot-nomatch">';
        echo '<td></td>';
        echo '<td colspan="6" style="font-size:9pt;color:#7d6608">⚠ Deno from books with NO Job Ticket (excluded from table rows)</td>';
        echo '<td class="num" style="color:#aaa">—</td>';
        for ($i = 0; $i < $lot_colspan; $i++) echo '<td></td>';
        echo '<td class="num warn">' . number_format($gt_deno_unmatched) . '</td>';
        echo '<td class="num warn">+' . number_format($gt_deno_unmatched) . '</td>';
        echo '<td class="ctr warn">No JT</td>';
        echo '<td class="ctr warn">No JT</td>';
        echo '</tr>';
    }

    // Row C: TRUE grand total
    list($gdc_c, $gds_c, $gps_c, $gms_c) = xls_diff($gt_diff, $gt_diff_pct);

    echo '<tr class="tot-true">';
    echo '<td></td>';
    echo '<td colspan="6" style="font-size:11pt">GRAND TOTAL — True Deno vs JT</td>';
    echo '<td class="num" style="font-size:11pt">' . number_format($gt_jt) . '</td>';
    for ($i = 0; $i < $lot_colspan; $i++) echo '<td></td>';
    echo '<td class="num" style="font-size:11pt;color:#90e4b0">' . number_format($gt_deno_true) . '</td>';
    echo '<td class="num" style="font-size:11pt;color:' . ($gt_diff >= 0 ? '#f0c040' : '#f5a9a9') . '">' . $gds_c . '</td>';
    echo '<td class="ctr" style="font-size:11pt;color:' . ($gt_diff >= 0 ? '#f0c040' : '#f5a9a9') . '">' . $gps_c . '</td>';
    echo '<td class="ctr" style="font-size:11pt;color:' . ($gt_diff >= 0 ? '#f0c040' : '#f5a9a9') . '">' . $gms_c . '</td>';
    echo '</tr>';

    echo '</table></body></html>';
    exit;
}

// Fallback
header('Location: ' . dirname($_SERVER['PHP_SELF']) . '/daily.php');
exit;
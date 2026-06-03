<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ─── Permissions ─────────────────────────────────────────────────────────────
if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// ─── Filter Parameters ───────────────────────────────────────────────────────
$fiscal_year_filter = $_GET['fiscal_year'] ?? '';
$book_code_filter   = $_GET['book_code'] ?? '';
$class_filter       = $_GET['class'] ?? '';
$status_filter      = $_GET['status'] ?? '';
$stage_filter       = $_GET['stage'] ?? '';
$d2m_status_filter  = $_GET['d2m_status'] ?? '';
$date_from_nep      = $_GET['date_from_nep'] ?? '';
$date_to_nep        = $_GET['date_to_nep'] ?? '';
$date_from_eng      = $_GET['date_from_eng'] ?? '';
$date_to_eng        = $_GET['date_to_eng'] ?? '';

// ─── Dropdown: Fiscal Years (id + fiscal_name) ───────────────────────────────
$fiscal_years = $conn->query("
    SELECT id, fiscal_name, fiscal_code 
    FROM fiscal_years 
    ORDER BY fiscal_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Dropdown: Books ─────────────────────────────────────────────────────────
$books_list = $conn->query("
    SELECT DISTINCT book_code, book_name, class_level 
    FROM books 
    ORDER BY class_level ASC NULLS LAST, book_code ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ─── Build Book Filter ───────────────────────────────────────────────────────
$book_where = ["1=1"];
$book_params = [];
if ($book_code_filter) {
    $book_where[] = "book_code LIKE :bc";
    $book_params[':bc'] = "%$book_code_filter%";
}
if ($class_filter) {
    $book_where[] = "class_level = :cl";
    $book_params[':cl'] = $class_filter;
}
$book_where_clause = implode(" AND ", $book_where);

// ─── Main Query: Aggregate per Book (Modular Approach) ───────────────────────
$sql = "
WITH book_base AS (
    SELECT book_code, book_name, class_level, book_type, is_translated, is_optional
    FROM books WHERE $book_where_clause
),
jt_agg AS (
    SELECT b.book_code,
           COUNT(DISTINCT jt.id) AS jt_count,
           COALESCE(SUM(jt.print_qty), 0) AS jt_total_print_qty,
           MAX(jt.status) AS jt_latest_status,
           STRING_AGG(DISTINCT jt.job_ticket_code, ', ' ORDER BY jt.job_ticket_code) AS jt_codes
    FROM book_base b
    LEFT JOIN job_ticket jt ON jt.book_id = (SELECT book_id FROM books WHERE book_code = b.book_code)
        " . ($fiscal_year_filter ? "AND jt.fiscal_year_id = :fy_jt" : "") . "
        " . ($status_filter ? "AND jt.status = :jt_status" : "") . "
    GROUP BY b.book_code
),
fp_agg AS (
    SELECT b.book_code,
           COALESCE(SUM(fp.fp_printqty), 0) AS fp_total_printed,
           COUNT(fp.id) AS fp_entry_count,
           MIN(fp.created_date) AS fp_first_date,
           MAX(fp.created_date) AS fp_last_date
    FROM book_base b
    LEFT JOIN job_ticket jt ON jt.book_id = (SELECT book_id FROM books WHERE book_code = b.book_code)
        " . ($fiscal_year_filter ? "AND jt.fiscal_year_id = :fy_fp" : "") . "
    LEFT JOIN forma_printing fp ON fp.jt_id = jt.id AND fp.status = true
    GROUP BY b.book_code
),
bp_agg AS (
    SELECT b.book_code,
           COALESCE(SUM(bp.p_qty), 0) AS bp_total_packed,
           COUNT(bp.id) AS bp_entry_count,
           MIN(bp.created_date) AS bp_first_date,
           MAX(bp.created_date) AS bp_last_date
    FROM book_base b
    LEFT JOIN job_ticket jt ON jt.book_id = (SELECT book_id FROM books WHERE book_code = b.book_code)
        " . ($fiscal_year_filter ? "AND jt.fiscal_year_id = :fy_bp" : "") . "
    LEFT JOIN book_packing bp ON bp.jt_id = jt.id AND bp.status = true
    GROUP BY b.book_code
),
deno_agg AS (
    SELECT d.book_code,
           COUNT(d.id) AS deno_count,
           COALESCE(SUM(d.total_qty), 0) AS deno_total_qty,
           MIN(d.deno_date_eng) AS deno_first_date,
           MAX(d.deno_date_eng) AS deno_last_date,
           STRING_AGG(d.ref_no, ', ' ORDER BY d.ref_no) AS deno_ref_nos
    FROM book_base b
    JOIN deno d ON d.book_code = b.book_code AND d.deleted_at IS NULL
        " . ($fiscal_year_filter ? "AND d.fiscal_year = (SELECT fiscal_code FROM fiscal_years WHERE id = :fy_deno)" : "") . "
    GROUP BY d.book_code
),
d2m_agg AS (
    SELECT di.book_code,
           COUNT(DISTINCT dm.id) AS d2m_count,
           COALESCE(SUM(di.total_qty), 0) AS d2m_total_qty,
           MAX(dm.status) AS d2m_latest_status,
           STRING_AGG(DISTINCT dm.d2m_no, ', ' ORDER BY dm.d2m_no) AS d2m_nos,
           MIN(dm.eng_date) AS d2m_first_date,
           MAX(dm.eng_date) AS d2m_last_date
    FROM book_base b
    JOIN d2m_items di ON di.book_code = b.book_code
    JOIN d2m dm ON dm.id = di.d2m_id AND dm.deleted_at IS NULL
        " . ($d2m_status_filter ? "AND dm.status = :d2m_status" : "") . "
    GROUP BY di.book_code
)
SELECT 
    bb.*,
    COALESCE(jt.jt_count, 0) AS jt_count,
    COALESCE(jt.jt_total_print_qty, 0) AS jt_total_print_qty,
    jt.jt_latest_status,
    jt.jt_codes,
    COALESCE(fp.fp_total_printed, 0) AS fp_total_printed,
    COALESCE(fp.fp_entry_count, 0) AS fp_entry_count,
    fp.fp_first_date,
    fp.fp_last_date,
    COALESCE(bp.bp_total_packed, 0) AS bp_total_packed,
    COALESCE(bp.bp_entry_count, 0) AS bp_entry_count,
    bp.bp_first_date,
    bp.bp_last_date,
    COALESCE(dn.deno_count, 0) AS deno_count,
    COALESCE(dn.deno_total_qty, 0) AS deno_total_qty,
    dn.deno_first_date,
    dn.deno_last_date,
    dn.deno_ref_nos,
    COALESCE(d2m.d2m_count, 0) AS d2m_count,
    COALESCE(d2m.d2m_total_qty, 0) AS d2m_total_qty,
    d2m.d2m_latest_status,
    d2m.d2m_nos,
    d2m.d2m_first_date,
    d2m.d2m_last_date
FROM book_base bb
LEFT JOIN jt_agg jt ON jt.book_code = bb.book_code
LEFT JOIN fp_agg fp ON fp.book_code = bb.book_code
LEFT JOIN bp_agg bp ON bp.book_code = bb.book_code
LEFT JOIN deno_agg dn ON dn.book_code = bb.book_code
LEFT JOIN d2m_agg d2m ON d2m.book_code = bb.book_code
ORDER BY bb.class_level ASC NULLS LAST, bb.book_code ASC
";

$stmt = $conn->prepare($sql);
foreach ($book_params as $k => $v) $stmt->bindValue($k, $v);
if ($fiscal_year_filter) {
    $stmt->bindValue(':fy_jt', $fiscal_year_filter);
    $stmt->bindValue(':fy_fp', $fiscal_year_filter);
    $stmt->bindValue(':fy_bp', $fiscal_year_filter);
    $stmt->bindValue(':fy_deno', $fiscal_year_filter);
}
if ($status_filter) $stmt->bindValue(':jt_status', $status_filter);
if ($d2m_status_filter) $stmt->bindValue(':d2m_status', $d2m_status_filter);
$stmt->execute();
$books_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ─── PHP Enrichment: Percentages & Pipeline Stage ───────────────────────────
foreach ($books_data as &$row) {
    $jt_qty = (int)($row['jt_total_print_qty'] ?? 0);
    
    $row['printing_pct'] = $jt_qty > 0 ? min(100, round(($row['fp_total_printed'] ?? 0) / $jt_qty * 100, 1)) : 0;
    $row['packing_pct']  = $jt_qty > 0 ? min(100, round(($row['bp_total_packed'] ?? 0) / $jt_qty * 100, 1)) : 0;
    $row['deno_pct']     = $jt_qty > 0 ? min(100, round(($row['deno_total_qty'] ?? 0) / $jt_qty * 100, 1)) : 0;
    $row['d2m_pct']      = $jt_qty > 0 ? min(100, round(($row['d2m_total_qty'] ?? 0) / $jt_qty * 100, 1)) : 0;

    // Pipeline stage logic
    if (($row['d2m_total_qty'] ?? 0) > 0 && $row['d2m_pct'] >= 100) $row['pipeline_stage'] = 'delivered';
    elseif (($row['d2m_total_qty'] ?? 0) > 0) $row['pipeline_stage'] = 'd2m_partial';
    elseif (($row['deno_total_qty'] ?? 0) > 0 && $row['deno_pct'] >= 100) $row['pipeline_stage'] = 'dispatched';
    elseif (($row['deno_total_qty'] ?? 0) > 0) $row['pipeline_stage'] = 'deno_partial';
    elseif (($row['bp_total_packed'] ?? 0) > 0 && $row['packing_pct'] >= 100) $row['pipeline_stage'] = 'packed';
    elseif (($row['bp_total_packed'] ?? 0) > 0) $row['pipeline_stage'] = 'packing';
    elseif (($row['fp_total_printed'] ?? 0) > 0 && $row['printing_pct'] >= 100) $row['pipeline_stage'] = 'printed';
    elseif (($row['fp_total_printed'] ?? 0) > 0) $row['pipeline_stage'] = 'printing';
    elseif (($row['jt_count'] ?? 0) > 0) $row['pipeline_stage'] = 'jt_issued';
    else $row['pipeline_stage'] = 'not_started';
}
unset($row);

// ─── Apply Date Filters (English dates only for now) ─────────────────────────
$filtered = array_filter($books_data, function($row) use ($stage_filter, $date_from_eng, $date_to_eng) {
    if ($stage_filter && $row['pipeline_stage'] !== $stage_filter) return false;
    if ($date_from_eng && $row['fp_first_date'] && $row['fp_first_date'] < $date_from_eng) return false;
    if ($date_to_eng && $row['fp_last_date'] && $row['fp_last_date'] > $date_to_eng) return false;
    return true;
});
$filtered = array_values($filtered);

// ─── Summary Stats ───────────────────────────────────────────────────────────
$total_books = count($filtered);
$stage_counts = array_count_values(array_column($filtered, 'pipeline_stage'));
$avg_print = $total_books > 0 ? round(array_sum(array_column($filtered, 'printing_pct')) / $total_books, 1) : 0;
$avg_pack  = $total_books > 0 ? round(array_sum(array_column($filtered, 'packing_pct')) / $total_books, 1) : 0;
$avg_deno  = $total_books > 0 ? round(array_sum(array_column($filtered, 'deno_pct')) / $total_books, 1) : 0;
$avg_d2m   = $total_books > 0 ? round(array_sum(array_column($filtered, 'd2m_pct')) / $total_books, 1) : 0;

// ─── Stage Metadata ──────────────────────────────────────────────────────────
$stage_meta = [
    'not_started'  => ['label' => 'Not Started',  'color' => '#94a3b8', 'icon' => '⬜'],
    'jt_issued'    => ['label' => 'JT Issued',    'color' => '#818cf8', 'icon' => '📋'],
    'printing'     => ['label' => 'Printing',     'color' => '#60a5fa', 'icon' => '🖨️'],
    'printed'      => ['label' => 'Print Done',   'color' => '#34d399', 'icon' => '✅'],
    'packing'      => ['label' => 'Packing',      'color' => '#fbbf24', 'icon' => '📦'],
    'packed'       => ['label' => 'Packed',       'color' => '#f59e0b', 'icon' => '📦'],
    'deno_partial' => ['label' => 'DENO Partial', 'color' => '#fb923c', 'icon' => '🚚'],
    'dispatched'   => ['label' => 'Dispatched',   'color' => '#ef4444', 'icon' => '🚚'],
    'd2m_partial'  => ['label' => 'D2M Partial',  'color' => '#a78bfa', 'icon' => '🏭'],
    'delivered'    => ['label' => 'Delivered',    'color' => '#10b981', 'icon' => '🏁'],
];
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>🏭 Production Process Control</title>
    
    <!-- Nepali Datepicker CSS -->
    <link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
    
    <style>
        /* ===== DESIGN TOKENS ===== */
        :root {
            --bg: #0f1117; --surface: #1a1d27; --surface-2: #222636;
            --border: #2e3348; --accent: #6366f1; --accent-2: #818cf8;
            --text: #e2e8f0; --text-muted: #64748b;
            --success: #10b981; --warning: #f59e0b; --danger: #ef4444;
            --radius: 12px; --shadow: 0 8px 30px rgba(0,0,0,0.5);
        }
        *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
        body {
            background: var(--bg); color: var(--text);
            font-family: 'Inter', system-ui, -apple-system, sans-serif;
            font-size: 14px; line-height: 1.6;
        }
        a { color: var(--accent-2); text-decoration: none; transition: color 0.2s; }
        a:hover { color: #fff; }

        /* ===== LAYOUT ===== */
        .wrap { max-width: 1600px; margin: 0 auto; padding: 24px 20px; }
        
        /* ===== HEADER ===== */
        .page-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 20px 0; margin-bottom: 24px;
            border-bottom: 1px solid var(--border);
        }
        .page-title { font-size: 22px; font-weight: 700; color: #fff; }
        .page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 4px; }
        .header-actions { display: flex; gap: 10px; }

        /* ===== PIPELINE TRACKER ===== */
        .pipeline-track {
            display: flex; align-items: center; gap: 4px;
            background: var(--surface); border: 1px solid var(--border);
            border-radius: 50px; padding: 6px 16px;
            margin-bottom: 24px; overflow-x: auto;
        }
        .pipeline-step {
            display: flex; align-items: center; gap: 6px;
            padding: 5px 12px; border-radius: 50px;
            font-size: 11px; font-weight: 600; color: var(--text-muted);
            cursor: pointer; transition: all 0.2s; white-space: nowrap;
        }
        .pipeline-step:hover, .pipeline-step.active {
            background: rgba(99,102,241,0.15); color: var(--accent-2);
        }
        .pipeline-arrow { color: var(--border); font-size: 14px; }
        .stage-count {
            background: var(--accent); color: #fff;
            border-radius: 50%; width: 18px; height: 18px;
            display: inline-flex; align-items: center; justify-content: center;
            font-size: 10px; font-weight: 700;
        }

        /* ===== SUMMARY CARDS ===== */
        .summary-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
            gap: 14px; margin-bottom: 24px;
        }
        .summary-card {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 18px 16px;
            position: relative; overflow: hidden;
        }
        .summary-card::before {
            content: ''; position: absolute; top: 0; left: 0; right: 0;
            height: 3px; background: var(--card-accent, var(--accent));
        }
        .summary-value { font-size: 26px; font-weight: 800; color: #fff; line-height: 1; }
        .summary-label {
            font-size: 11px; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.5px; margin-top: 6px;
        }

        /* ===== FILTER PANEL ===== */
        .filter-panel {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); padding: 20px; margin-bottom: 24px;
        }
        .filter-title {
            font-size: 12px; font-weight: 700; color: var(--text-muted);
            text-transform: uppercase; letter-spacing: 0.6px; margin-bottom: 16px;
        }
        .filter-grid {
            display: grid; grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
            gap: 14px;
        }
        .filter-group label {
            display: block; font-size: 10px; font-weight: 600;
            color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.4px; margin-bottom: 6px;
        }
        .filter-input, .filter-select {
            width: 100%; background: var(--surface-2); border: 1px solid var(--border);
            border-radius: 8px; color: var(--text); padding: 8px 12px;
            font-size: 13px; outline: none; transition: border-color 0.2s;
        }
        .filter-input:focus, .filter-select:focus { border-color: var(--accent); }
        .filter-actions {
            display: flex; gap: 10px; justify-content: flex-end; margin-top: 18px;
        }

        /* ===== BUTTONS ===== */
        .btn {
            display: inline-flex; align-items: center; gap: 6px;
            padding: 8px 16px; border: none; border-radius: 8px;
            font-size: 13px; font-weight: 600; cursor: pointer;
            transition: all 0.2s; text-decoration: none;
        }
        .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 14px rgba(0,0,0,0.3); }
        .btn-primary { background: var(--accent); color: #fff; }
        .btn-secondary { background: var(--surface-2); color: var(--text); border: 1px solid var(--border); }
        .btn-success { background: var(--success); color: #fff; }
        .btn-sm { padding: 5px 10px; font-size: 12px; }
        .btn-print { background: #64748b; color: #fff; }

        /* ===== TABLE ===== */
        .table-container {
            background: var(--surface); border: 1px solid var(--border);
            border-radius: var(--radius); overflow: hidden;
        }
        .table-header {
            display: flex; justify-content: space-between; align-items: center;
            padding: 14px 18px; border-bottom: 1px solid var(--border);
        }
        .table-title { font-weight: 700; font-size: 15px; }
        .table-count { color: var(--text-muted); font-size: 13px; }
        
        table { width: 100%; border-collapse: collapse; }
        th {
            padding: 11px 12px; text-align: left; font-size: 11px;
            font-weight: 700; color: var(--text-muted); text-transform: uppercase;
            letter-spacing: 0.4px; background: var(--surface-2);
            border-bottom: 1px solid var(--border); white-space: nowrap;
        }
        td {
            padding: 11px 12px; border-bottom: 1px solid var(--border);
            vertical-align: middle; font-size: 13px;
        }
        tr:last-child td { border-bottom: none; }
        tr.data-row:hover { background: rgba(99,102,241,0.06); cursor: pointer; }
        
        /* ===== PROGRESS BARS ===== */
        .progress-group { display: flex; flex-direction: column; gap: 5px; min-width: 170px; }
        .progress-row { display: flex; align-items: center; gap: 7px; font-size: 11px; }
        .progress-label { width: 48px; color: var(--text-muted); flex-shrink: 0; }
        .progress-track {
            flex: 1; height: 7px; background: var(--surface-2);
            border-radius: 4px; overflow: hidden;
        }
        .progress-fill { height: 100%; border-radius: 4px; transition: width 0.5s ease; }
        .progress-pct { width: 38px; text-align: right; font-weight: 600; font-size: 11px; }
        .fill-print { background: #60a5fa; }
        .fill-pack { background: #fbbf24; }
        .fill-deno { background: #f97316; }
        .fill-d2m { background: #a78bfa; }

        /* ===== STAGE BADGE ===== */
        .stage-badge {
            display: inline-flex; align-items: center; gap: 4px;
            padding: 3px 9px; border-radius: 50px;
            font-size: 11px; font-weight: 700; white-space: nowrap;
        }

        /* ===== EXPANDABLE ROW ===== */
        .expand-row { display: none; }
        .expand-row.open { display: table-row; }
        .expand-cell {
            background: var(--surface-2); padding: 18px 20px;
            border-radius: 0 0 10px 10px;
        }
        .expand-grid {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(210px, 1fr));
            gap: 18px;
        }
        .expand-section h4 {
            font-size: 11px; text-transform: uppercase; letter-spacing: 0.5px;
            color: var(--text-muted); margin-bottom: 10px; font-weight: 700;
        }
        .detail-item {
            display: flex; justify-content: space-between; font-size: 12px;
            padding: 4px 0; border-bottom: 1px solid rgba(255,255,255,0.05);
        }
        .detail-item:last-child { border-bottom: none; }
        .detail-value { font-weight: 600; color: var(--text); text-align: right; max-width: 60%; word-break: break-word; }

        /* ===== NO DATA ===== */
        .no-data { text-align: center; padding: 50px 20px; color: var(--text-muted); }

        /* ===== PRINT STYLES ===== */
        @media print {
            body { background: #fff; color: #000; font-size: 11px; }
            .no-print { display: none !important; }
            .wrap { padding: 0; max-width: none; }
            .page-header { border-bottom: 2px solid #000; padding: 10px 0; }
            .filter-panel, .pipeline-track, .summary-grid, .stage-pills, .header-actions { display: none; }
            .table-container { border: none; border-radius: 0; }
            table { font-size: 10px; }
            th, td { padding: 5px 6px; border-bottom: 1px solid #ccc; }
            .progress-track { background: #eee; }
            .stage-badge { border: 1px solid #000; background: #fff !important; color: #000 !important; }
        }

        /* ===== RESPONSIVE ===== */
        @media (max-width: 768px) {
            .filter-grid { grid-template-columns: 1fr; }
            .page-header { flex-direction: column; align-items: flex-start; gap: 12px; }
            th, td { padding: 8px; font-size: 12px; }
            .progress-group { min-width: 140px; }
        }
    </style>
</head>
<body>
<div class="wrap">

    <!-- Page Header -->
    <div class="page-header">
        <div>
            <h1 class="page-title">🏭 Production Process Control</h1>
            <div class="page-subtitle">Track books: JT → Printing → Packing → DENO → D2M</div>
        </div>
        <div class="header-actions no-print">
            <button class="btn btn-print" onclick="window.print()">🖨️ Print</button>
            <button class="btn btn-success" onclick="exportReport('excel')">📥 Excel</button>
            <button class="btn btn-primary" onclick="exportReport('pdf')">📄 PDF</button>
        </div>
    </div>

    <!-- Pipeline Tracker -->
    <div class="pipeline-track no-print">
        <?php
        $stage_order = array_keys($stage_meta);
        foreach ($stage_order as $i => $key):
            $meta = $stage_meta[$key];
            $count = $stage_counts[$key] ?? 0;
            $qs = http_build_query(array_merge($_GET, ['stage' => $key]));
        ?>
            <?php if ($i > 0): ?><span class="pipeline-arrow">›</span><?php endif; ?>
            <a href="?<?= $qs ?>" class="pipeline-step <?= $stage_filter === $key ? 'active' : '' ?>">
                <?= $meta['icon'] ?> <?= $meta['label'] ?>
                <span class="stage-count"><?= $count ?></span>
            </a>
        <?php endforeach; ?>
        <?php if ($stage_filter): ?>
            <a href="?<?= http_build_query(array_diff_key($_GET, ['stage'=>''])) ?>" 
               class="pipeline-step" style="color:var(--danger);margin-left:8px;">✕ Clear</a>
        <?php endif; ?>
    </div>

    <!-- Summary Cards -->
    <div class="summary-grid">
        <div class="summary-card" style="--card-accent:#818cf8">
            <div class="summary-value"><?= $total_books ?></div>
            <div class="summary-label">Total Books</div>
        </div>
        <div class="summary-card" style="--card-accent:#60a5fa">
            <div class="summary-value"><?= $avg_print ?>%</div>
            <div class="summary-label">Avg Printing</div>
        </div>
        <div class="summary-card" style="--card-accent:#fbbf24">
            <div class="summary-value"><?= $avg_pack ?>%</div>
            <div class="summary-label">Avg Packing</div>
        </div>
        <div class="summary-card" style="--card-accent:#f97316">
            <div class="summary-value"><?= $avg_deno ?>%</div>
            <div class="summary-label">Avg DENO</div>
        </div>
        <div class="summary-card" style="--card-accent:#a78bfa">
            <div class="summary-value"><?= $avg_d2m ?>%</div>
            <div class="summary-label">Avg D2M</div>
        </div>
        <div class="summary-card" style="--card-accent:#10b981">
            <div class="summary-value"><?= $stage_counts['delivered'] ?? 0 ?></div>
            <div class="summary-label">Delivered</div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-panel no-print">
        <div class="filter-title">🔍 Search & Filter</div>
        <form method="GET" id="filterForm">
            <div class="filter-grid">
                <!-- Fiscal Year (using fiscal_name) -->
                <div class="filter-group">
                    <label>Fiscal Year</label>
                    <select name="fiscal_year" class="filter-select">
                        <option value="">All Fiscal Years</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_name']) ?> (<?= htmlspecialchars($fy['fiscal_code']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Book Code -->
                <div class="filter-group">
                    <label>Book Code</label>
                    <input type="text" name="book_code" class="filter-input" 
                           placeholder="Search code..." value="<?= htmlspecialchars($book_code_filter) ?>">
                </div>

                <!-- Class Level -->
                <div class="filter-group">
                    <label>Class Level</label>
                    <input type="number" name="class" class="filter-input" 
                           placeholder="e.g., 5" value="<?= htmlspecialchars($class_filter) ?>">
                </div>

                <!-- Pipeline Stage -->
                <div class="filter-group">
                    <label>Pipeline Stage</label>
                    <select name="stage" class="filter-select">
                        <option value="">All Stages</option>
                        <?php foreach ($stage_meta as $key => $meta): ?>
                            <option value="<?= $key ?>" <?= $stage_filter === $key ? 'selected' : '' ?>>
                                <?= $meta['icon'] ?> <?= $meta['label'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- JT Status -->
                <div class="filter-group">
                    <label>JT Status</label>
                    <select name="status" class="filter-select">
                        <option value="">All Statuses</option>
                        <?php foreach (['pending','active','processing','fp_completed','bp_completed','completed','cancelled'] as $s): ?>
                            <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>>
                                <?= ucfirst(str_replace('_', ' ', $s)) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- D2M Status -->
                <div class="filter-group">
                    <label>D2M Status</label>
                    <select name="d2m_status" class="filter-select">
                        <option value="">All Statuses</option>
                        <?php foreach (['DRAFT','CHECKED','VERIFIED','CLOSE','CANCELLED'] as $s): ?>
                            <option value="<?= $s ?>" <?= $d2m_status_filter === $s ? 'selected' : '' ?>><?= $s ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Nepali Date From -->
                <div class="filter-group">
                    <label>From (Nepali)</label>
                    <input type="text" id="date_from_nep" name="date_from_nep" class="filter-input nepali-date"
                           placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($date_from_nep) ?>" autocomplete="off">
                </div>

                <!-- Nepali Date To -->
                <div class="filter-group">
                    <label>To (Nepali)</label>
                    <input type="text" id="date_to_nep" name="date_to_nep" class="filter-input nepali-date"
                           placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($date_to_nep) ?>" autocomplete="off">
                </div>

                <!-- English Date From -->
                <div class="filter-group">
                    <label>From (English)</label>
                    <input type="date" name="date_from_eng" class="filter-input" value="<?= htmlspecialchars($date_from_eng) ?>">
                </div>

                <!-- English Date To -->
                <div class="filter-group">
                    <label>To (English)</label>
                    <input type="date" name="date_to_eng" class="filter-input" value="<?= htmlspecialchars($date_to_eng) ?>">
                </div>
            </div>

            <div class="filter-actions">
                <button type="button" class="btn btn-secondary" onclick="resetFilters()">🔄 Reset</button>
                <button type="submit" class="btn btn-primary">🔍 Apply Filters</button>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="table-container">
        <div class="table-header">
            <span class="table-title">📚 Book Production Status</span>
            <span class="table-count"><?= $total_books ?> book(s) found</span>
        </div>

        <?php if (empty($filtered)): ?>
            <div class="no-data">📭 No books match your filters. Try adjusting your search.</div>
        <?php else: ?>
        <table>
            <thead>
                <tr>
                    <th style="width:28px"></th>
                    <th>Book Details</th>
                    <th>Class</th>
                    <th>Stage</th>
                    <th>Job Tickets</th>
                    <th>Progress</th>
                    <th>DENO</th>
                    <th>D2M</th>
                    <th class="no-print">Action</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($filtered as $idx => $row):
                    $meta = $stage_meta[$row['pipeline_stage']] ?? $stage_meta['not_started'];
                    $rowId = 'row-' . $idx;
                ?>
                <tr class="data-row" onclick="toggleRow('<?= $rowId ?>')">
                    <td style="text-align:center;color:var(--text-muted);">▸</td>
                    <td>
                        <div style="font-weight:700;color:#fff"><?= htmlspecialchars($row['book_code']) ?></div>
                        <div style="font-size:12px;color:var(--text-muted);max-width:200px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($row['book_name']) ?>">
                            <?= htmlspecialchars($row['book_name']) ?>
                        </div>
                        <div style="margin-top:3px;display:flex;gap:4px;flex-wrap:wrap">
                            <?php if (!empty($row['is_translated'])): ?>
                                <span style="background:#1e3a5f;color:#60a5fa;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:600">TR</span>
                            <?php endif; ?>
                            <?php if (!empty($row['is_optional'])): ?>
                                <span style="background:#3f2a1e;color:#fbbf24;padding:2px 6px;border-radius:4px;font-size:9px;font-weight:600">OPT</span>
                            <?php endif; ?>
                            <span style="color:var(--text-muted);font-size:10px"><?= htmlspecialchars($row['book_type'] ?? '') ?></span>
                        </div>
                    </td>
                    <td style="font-weight:700;font-size:15px;color:var(--text-muted);text-align:center">
                        <?= $row['class_level'] ?? '—' ?>
                    </td>
                    <td>
                        <span class="stage-badge" style="background:<?= $meta['color'] ?>22;color:<?= $meta['color'] ?>">
                            <?= $meta['icon'] ?> <?= $meta['label'] ?>
                        </span>
                    </td>
                    <td>
                        <?php $jtCount = (int)($row['jt_count'] ?? 0); ?>
                        <?php if ($jtCount > 0): ?>
                            <div style="font-weight:700;color:#fff"><?= $jtCount ?> JT</div>
                            <div style="font-size:10px;color:var(--text-muted);max-width:130px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($row['jt_codes'] ?? '') ?>">
                                <?= htmlspecialchars($row['jt_codes'] ?? '') ?>
                            </div>
                            <?php if (!empty($row['jt_latest_status'])): ?>
                                <span style="font-size:9px;padding:1px 5px;border-radius:3px;background:rgba(99,102,241,0.15);color:var(--accent-2)">
                                    <?= ucfirst(str_replace('_',' ',$row['jt_latest_status'])) ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted);font-size:11px">No JT</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <div class="progress-group">
                            <?php foreach ([
                                ['Print', $row['printing_pct'], 'fill-print', '#60a5fa'],
                                ['Pack', $row['packing_pct'], 'fill-pack', '#fbbf24'],
                                ['Deno', $row['deno_pct'], 'fill-deno', '#f97316'],
                                ['D2M', $row['d2m_pct'], 'fill-d2m', '#a78bfa']
                            ] as [$label, $pct, $class, $color]): ?>
                            <div class="progress-row">
                                <span class="progress-label"><?= $label ?></span>
                                <div class="progress-track">
                                    <div class="progress-fill <?= $class ?>" style="width:<?= $pct ?>%"></div>
                                </div>
                                <span class="progress-pct" style="color:<?= $color ?>"><?= $pct ?>%</span>
                            </div>
                            <?php endforeach; ?>
                        </div>
                    </td>
                    <td>
                        <?php if ((int)($row['deno_count'] ?? 0) > 0): ?>
                            <div style="font-weight:700;color:#f97316"><?= number_format($row['deno_total_qty']) ?></div>
                            <div style="font-size:10px;color:var(--text-muted)"><?= $row['deno_count'] ?> entries</div>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ((int)($row['d2m_count'] ?? 0) > 0): ?>
                            <div style="font-weight:700;color:#a78bfa"><?= number_format($row['d2m_total_qty']) ?></div>
                            <div style="font-size:10px;color:var(--text-muted)"><?= $row['d2m_count'] ?> D2M</div>
                            <?php if (!empty($row['d2m_latest_status'])): ?>
                                <span style="font-size:9px;padding:1px 5px;border-radius:3px;background:rgba(167,139,250,0.15);color:#a78bfa">
                                    <?= $row['d2m_latest_status'] ?>
                                </span>
                            <?php endif; ?>
                        <?php else: ?>
                            <span style="color:var(--text-muted)">—</span>
                        <?php endif; ?>
                    </td>
                    <td class="no-print" onclick="event.stopPropagation()">
                        <a href="report_detail.php?book_code=<?= urlencode($row['book_code']) ?>&<?= http_build_query(array_intersect_key($_GET, array_flip(['fiscal_year','stage']))) ?>" 
                           class="btn btn-primary btn-sm">📋 View</a>
                    </td>
                </tr>

                <!-- Expandable Detail Row -->
                <tr class="expand-row" id="<?= $rowId ?>">
                    <td colspan="9" style="padding:0">
                        <div class="expand-cell">
                            <div class="expand-grid">
                                <!-- Job Ticket -->
                                <div class="expand-section">
                                    <h4>📋 Job Ticket</h4>
                                    <div class="detail-item"><span>Count</span><span class="detail-value"><?= (int)($row['jt_count'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>Target Qty</span><span class="detail-value"><?= number_format($row['jt_total_print_qty'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>Codes</span><span class="detail-value" style="font-size:10px"><?= htmlspecialchars($row['jt_codes'] ?? '—') ?></span></div>
                                    <div class="detail-item"><span>Status</span><span class="detail-value"><?= ucfirst(str_replace('_',' ',$row['jt_latest_status'] ?? '—')) ?></span></div>
                                </div>

                                <!-- Printing -->
                                <div class="expand-section">
                                    <h4>🖨️ Forma Printing</h4>
                                    <div class="detail-item"><span>Printed</span><span class="detail-value" style="color:#60a5fa"><?= number_format($row['fp_total_printed'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>Entries</span><span class="detail-value"><?= (int)($row['fp_entry_count'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>Started</span><span class="detail-value"><?= $row['fp_first_date'] ? date('Y-m-d', strtotime($row['fp_first_date'])) : '—' ?></span></div>
                                    <div class="detail-item"><span>Completed</span><span class="detail-value"><?= $row['fp_last_date'] ? date('Y-m-d', strtotime($row['fp_last_date'])) : '—' ?></span></div>
                                    <div class="detail-item"><span>Progress</span><span class="detail-value" style="color:#60a5fa"><?= $row['printing_pct'] ?>%</span></div>
                                </div>

                                <!-- Packing -->
                                <div class="expand-section">
                                    <h4>📦 Book Packing</h4>
                                    <div class="detail-item"><span>Packed</span><span class="detail-value" style="color:#fbbf24"><?= number_format($row['bp_total_packed'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>Entries</span><span class="detail-value"><?= (int)($row['bp_entry_count'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>Started</span><span class="detail-value"><?= $row['bp_first_date'] ? date('Y-m-d', strtotime($row['bp_first_date'])) : '—' ?></span></div>
                                    <div class="detail-item"><span>Completed</span><span class="detail-value"><?= $row['bp_last_date'] ? date('Y-m-d', strtotime($row['bp_last_date'])) : '—' ?></span></div>
                                    <div class="detail-item"><span>Progress</span><span class="detail-value" style="color:#fbbf24"><?= $row['packing_pct'] ?>%</span></div>
                                </div>

                                <!-- DENO -->
                                <div class="expand-section">
                                    <h4>🚚 DENO Dispatch</h4>
                                    <div class="detail-item"><span>Qty Dispatched</span><span class="detail-value" style="color:#f97316"><?= number_format($row['deno_total_qty'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>Entries</span><span class="detail-value"><?= (int)($row['deno_count'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>First Date</span><span class="detail-value"><?= $row['deno_first_date'] ?? '—' ?></span></div>
                                    <div class="detail-item"><span>Last Date</span><span class="detail-value"><?= $row['deno_last_date'] ?? '—' ?></span></div>
                                    <div class="detail-item"><span>Progress</span><span class="detail-value" style="color:#f97316"><?= $row['deno_pct'] ?>%</span></div>
                                    <?php if (!empty($row['deno_ref_nos'])): ?>
                                    <div class="detail-item"><span>Ref Nos</span><span class="detail-value" style="font-size:10px"><?= htmlspecialchars($row['deno_ref_nos']) ?></span></div>
                                    <?php endif; ?>
                                </div>

                                <!-- D2M -->
                                <div class="expand-section">
                                    <h4>🏭 D2M Delivery</h4>
                                    <div class="detail-item"><span>Qty Delivered</span><span class="detail-value" style="color:#a78bfa"><?= number_format($row['d2m_total_qty'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>D2M Count</span><span class="detail-value"><?= (int)($row['d2m_count'] ?? 0) ?></span></div>
                                    <div class="detail-item"><span>First Date</span><span class="detail-value"><?= $row['d2m_first_date'] ?? '—' ?></span></div>
                                    <div class="detail-item"><span>Last Date</span><span class="detail-value"><?= $row['d2m_last_date'] ?? '—' ?></span></div>
                                    <div class="detail-item"><span>Status</span><span class="detail-value" style="color:#a78bfa"><?= $row['d2m_latest_status'] ?? '—' ?></span></div>
                                    <div class="detail-item"><span>Progress</span><span class="detail-value" style="color:#a78bfa"><?= $row['d2m_pct'] ?>%</span></div>
                                    <?php if (!empty($row['d2m_nos'])): ?>
                                    <div class="detail-item"><span>D2M Nos</span><span class="detail-value" style="font-size:10px"><?= htmlspecialchars($row['d2m_nos']) ?></span></div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>

</div>

<!-- Nepali Datepicker JS -->
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Nepali Datepickers (Vanilla JS fallback)
    if (typeof NepaliFunctions !== 'undefined') {
        const fromEl = document.getElementById('date_from_nep');
        const toEl = document.getElementById('date_to_nep');
        
        if (fromEl) new NepaliFunctions.DatePicker(fromEl, { dateFormat: 'YYYY-MM-DD', closeOnDateSelect: true });
        if (toEl) new NepaliFunctions.DatePicker(toEl, { dateFormat: 'YYYY-MM-DD', closeOnDateSelect: true });
    }
});

// Toggle expandable row
function toggleRow(id) {
    const row = document.getElementById(id);
    const arrow = row?.previousElementSibling?.querySelector('td:first-child');
    if (!row) return;
    
    row.classList.toggle('open');
    if (arrow) arrow.textContent = row.classList.contains('open') ? '▾' : '▸';
}

// Reset filters
function resetFilters() {
    window.location.href = window.location.pathname;
}

// Export handler
function exportReport(type) {
    const params = new URLSearchParams(window.location.search);
    params.set('export', type);
    window.location.href = 'report_export_ppc.php?' + params.toString();
}

// Keyboard accessibility for rows
document.querySelectorAll('.data-row').forEach(row => {
    row.setAttribute('tabindex', '0');
    row.addEventListener('keydown', function(e) {
        if (e.key === 'Enter' || e.key === ' ') {
            e.preventDefault();
            toggleRow(this.nextElementSibling?.id);
        }
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
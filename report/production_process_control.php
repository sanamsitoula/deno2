<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ─── Permissions ─────────────────────────────────────────────────────────
if (!has_role('viewer') && !has_role('operator') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// ─── Filter Parameters ───────────────────────────────────────────────────
// Defaults to the active fiscal year on first load; isset() so an explicit
// "All Fiscal Years" choice (empty string) sticks.
$active_fy_for_filter = getActiveFiscalYear($conn);
$fiscal_year_filter = isset($_GET['fiscal_year']) ? $_GET['fiscal_year'] : ($active_fy_for_filter['id'] ?? '');
$book_code_filter   = $_GET['book_code']     ?? '';
$class_filter       = $_GET['class']         ?? '';
$status_filter      = $_GET['status']        ?? '';
$stage_filter       = $_GET['stage']         ?? '';
$d2m_status_filter  = $_GET['d2m_status']    ?? '';
$date_from_nep      = $_GET['date_from_nep'] ?? '';
$date_to_nep        = $_GET['date_to_nep']   ?? '';
$date_from_eng      = $_GET['date_from_eng'] ?? '';
$date_to_eng        = $_GET['date_to_eng']   ?? '';
$view               = $_GET['view']          ?? 'card'; // 'card' or 'table'

// ─── Dropdowns ───────────────────────────────────────────────────────────
$fiscal_years = $conn->query("SELECT id, fiscal_code, fiscal_name FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);

// ─── QUERY 1: Books (no joins, instant) ──────────────────────────────────
$book_where  = ["1=1"];
$book_params = [];
if ($book_code_filter) { $book_where[] = "book_code LIKE :bc"; $book_params[':bc'] = "%$book_code_filter%"; }
if ($class_filter)     { $book_where[] = "class_level = :cl"; $book_params[':cl'] = $class_filter; }

$stmt = $conn->prepare("SELECT book_code, book_name, class_level, book_type, is_translated, is_optional
    FROM books WHERE " . implode(" AND ", $book_where) . " ORDER BY class_level ASC NULLS LAST, book_code ASC");
foreach ($book_params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$books_list = $stmt->fetchAll(PDO::FETCH_ASSOC);

$books_data = [];

if (!empty($books_list)) {
    $book_codes = array_column($books_list, 'book_code');
    $in_list    = implode(',', array_map(fn($c) => $conn->quote($c), $book_codes));

    $fy_code = '';
    if ($fiscal_year_filter) {
        $r = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE id = " . (int)$fiscal_year_filter)->fetch();
        $fy_code = $r['fiscal_code'] ?? '';
    }

    $fy_jt  = $fiscal_year_filter ? "AND jt.fiscal_year_id = " . (int)$fiscal_year_filter : "";
    $st_jt  = $status_filter      ? "AND jt.status = " . $conn->quote($status_filter)     : "";

    // JT summary
    $jt_rows = $conn->query("
        SELECT b.book_code,
               COUNT(DISTINCT jt.id) AS jt_count,
               COALESCE(SUM(jt.print_qty),0) AS jt_total_print_qty,
               MAX(jt.status) AS jt_latest_status,
               STRING_AGG(DISTINCT jt.job_ticket_code, ', ' ORDER BY jt.job_ticket_code) AS jt_codes
        FROM books b
        LEFT JOIN job_ticket jt ON jt.book_id = b.book_id $fy_jt $st_jt
        WHERE b.book_code IN ($in_list)
        GROUP BY b.book_code
    ")->fetchAll(PDO::FETCH_ASSOC);
    $jt_map = array_column($jt_rows, null, 'book_code');

    $jt_id_rows = $conn->query("
        SELECT jt.id, b.book_code FROM job_ticket jt
        JOIN books b ON jt.book_id = b.book_id
        WHERE b.book_code IN ($in_list) $fy_jt
    ")->fetchAll(PDO::FETCH_ASSOC);

    $all_jt_ids = array_unique(array_column($jt_id_rows, 'id'));
    $fp_map = []; $bp_map = [];

    if (!empty($all_jt_ids)) {
        $jt_in = implode(',', array_map('intval', $all_jt_ids));

        $fp_rows = $conn->query("
            SELECT b.book_code,
                   COALESCE(SUM(fp.fp_printqty),0) AS fp_total_printed,
                   COUNT(fp.id) AS fp_entry_count,
                   MIN(fp.created_date) AS fp_first_date,
                   MAX(fp.created_date) AS fp_last_date
            FROM forma_printing fp
            JOIN job_ticket jt ON jt.id = fp.jt_id
            JOIN books b ON b.book_id = jt.book_id
            WHERE fp.jt_id IN ($jt_in) AND fp.status = true
            GROUP BY b.book_code
        ")->fetchAll(PDO::FETCH_ASSOC);
        $fp_map = array_column($fp_rows, null, 'book_code');

        $bp_rows = $conn->query("
            SELECT b.book_code,
                   COALESCE(SUM(bp.p_qty),0) AS bp_total_packed,
                   COUNT(bp.id) AS bp_entry_count,
                   MIN(bp.created_date) AS bp_first_date,
                   MAX(bp.created_date) AS bp_last_date
            FROM book_packing bp
            JOIN job_ticket jt ON jt.id = bp.jt_id
            JOIN books b ON b.book_id = jt.book_id
            WHERE bp.jt_id IN ($jt_in) AND bp.status = true
            GROUP BY b.book_code
        ")->fetchAll(PDO::FETCH_ASSOC);
        $bp_map = array_column($bp_rows, null, 'book_code');
    }

    $deno_fy = $fy_code ? "AND d.fiscal_year = " . $conn->quote($fy_code) : "";
    $deno_rows = $conn->query("
        SELECT d.book_code,
               COUNT(d.id) AS deno_count,
               COALESCE(SUM(d.total_qty),0) AS deno_total_qty,
               MIN(d.deno_date_eng) AS deno_first_date,
               MAX(d.deno_date_eng) AS deno_last_date,
               STRING_AGG(d.ref_no, ', ' ORDER BY d.ref_no) AS deno_ref_nos
        FROM deno d
        WHERE d.book_code IN ($in_list) AND d.deleted_at IS NULL $deno_fy
        GROUP BY d.book_code
    ")->fetchAll(PDO::FETCH_ASSOC);
    $deno_map = array_column($deno_rows, null, 'book_code');

    $d2m_st = $d2m_status_filter ? "AND dm.status = " . $conn->quote($d2m_status_filter) : "";
    $d2m_rows = $conn->query("
        SELECT di.book_code,
               COUNT(DISTINCT dm.id) AS d2m_count,
               COALESCE(SUM(di.total_qty),0) AS d2m_total_qty,
               MAX(dm.status) AS d2m_latest_status,
               STRING_AGG(DISTINCT dm.d2m_no, ', ' ORDER BY dm.d2m_no) AS d2m_nos,
               MIN(dm.eng_date) AS d2m_first_date,
               MAX(dm.eng_date) AS d2m_last_date
        FROM d2m_items di
        JOIN d2m dm ON dm.id = di.d2m_id $d2m_st
        WHERE di.book_code IN ($in_list) AND dm.deleted_at IS NULL AND dm.status <> 'CANCELLED'
        GROUP BY di.book_code
    ")->fetchAll(PDO::FETCH_ASSOC);
    $d2m_map = array_column($d2m_rows, null, 'book_code');

    foreach ($books_list as $book) {
        $bc  = $book['book_code'];
        $jt  = $jt_map[$bc]   ?? [];
        $fp  = $fp_map[$bc]   ?? [];
        $bp  = $bp_map[$bc]   ?? [];
        $dn  = $deno_map[$bc] ?? [];
        $d2m = $d2m_map[$bc]  ?? [];

        $jt_qty  = (int)($jt['jt_total_print_qty'] ?? 0);
        $fp_tot  = (int)($fp['fp_total_printed']   ?? 0);
        $bp_tot  = (int)($bp['bp_total_packed']    ?? 0);
        $dn_tot  = (int)($dn['deno_total_qty']     ?? 0);
        $d2m_tot = (int)($d2m['d2m_total_qty']     ?? 0);

        $print_pct = $jt_qty > 0 ? min(100, round($fp_tot  / $jt_qty * 100, 1)) : 0;
        $pack_pct  = $jt_qty > 0 ? min(100, round($bp_tot  / $jt_qty * 100, 1)) : 0;
        $deno_pct  = $jt_qty > 0 ? min(100, round($dn_tot  / $jt_qty * 100, 1)) : 0;
        $d2m_pct   = $jt_qty > 0 ? min(100, round($d2m_tot / $jt_qty * 100, 1)) : 0;

        if ($d2m_tot > 0 && $d2m_pct >= 100)         $stage = 'delivered';
        elseif ($d2m_tot > 0)                         $stage = 'd2m_partial';
        elseif ($dn_tot  > 0 && $deno_pct >= 100)    $stage = 'dispatched';
        elseif ($dn_tot  > 0)                         $stage = 'deno_partial';
        elseif ($bp_tot  > 0 && $pack_pct >= 100)    $stage = 'packed';
        elseif ($bp_tot  > 0)                         $stage = 'packing';
        elseif ($fp_tot  > 0 && $print_pct >= 100)   $stage = 'printed';
        elseif ($fp_tot  > 0)                         $stage = 'printing';
        elseif (!empty($jt['jt_count']) && (int)$jt['jt_count'] > 0) $stage = 'jt_issued';
        else                                           $stage = 'not_started';

        $books_data[] = array_merge($book, $jt, $fp, $bp, $dn, $d2m, [
            'jt_total_print_qty' => $jt_qty,
            'fp_total_printed'   => $fp_tot,
            'bp_total_packed'    => $bp_tot,
            'deno_total_qty'     => $dn_tot,
            'd2m_total_qty'      => $d2m_tot,
            'printing_pct'       => $print_pct,
            'packing_pct'        => $pack_pct,
            'deno_pct'           => $deno_pct,
            'd2m_pct'            => $d2m_pct,
            'pipeline_stage'     => $stage,
        ]);
    }
}

// ─── PHP‑side filters ────────────────────────────────────────────────────
$filtered = array_filter($books_data, function($r) use ($stage_filter, $date_from_eng, $date_to_eng) {
    if ($stage_filter  && $r['pipeline_stage'] !== $stage_filter)          return false;
    if ($date_from_eng && ($r['fp_first_date'] ?? '9999') < $date_from_eng) return false;
    if ($date_to_eng   && ($r['fp_last_date']  ?? '0000') > $date_to_eng)   return false;
    return true;
});
$filtered = array_values($filtered);

$total = count($filtered);
$sc    = array_count_values(array_column($filtered, 'pipeline_stage'));
$avg   = fn($key) => $total > 0 ? round(array_sum(array_column($filtered, $key)) / $total, 1) : 0;

$stage_meta = [
    'not_started'  => ['Not Started',   '#94a3b8', '⬜'],
    'jt_issued'    => ['JT Issued',      '#6366f1', '📋'],
    'printing'     => ['Printing',       '#3b82f6', '🖨️'],
    'printed'      => ['Print Done',     '#10b981', '✅'],
    'packing'      => ['Packing',        '#f59e0b', '📦'],
    'packed'       => ['Packed',         '#f97316', '📦'],
    'deno_partial' => ['Deno Partial',   '#fb923c', '🚚'],
    'dispatched'   => ['Dispatched',     '#ef4444', '🚚'],
    'd2m_partial'  => ['D2M Partial',    '#8b5cf6', '🏭'],
    'delivered'    => ['Delivered',      '#10b981', '🏁'],
];

// View toggle query params
$view_toggle_card  = http_build_query(array_merge($_GET, ['view' => 'card']));
$view_toggle_table = http_build_query(array_merge($_GET, ['view' => 'table']));
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Production Process Control</title>
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
/* ── White Theme ─────────────────────────── */
:root {
  --bg:          #ffffff;
  --surface:     #f8fafc;
  --surface2:    #f1f5f9;
  --border:      #e2e8f0;
  --accent:      #4f46e5;
  --accent2:     #6366f1;
  --text:        #1e293b;
  --text-muted:  #64748b;
  --success:     #10b981;
  --warning:     #f59e0b;
  --danger:      #ef4444;
  --radius:      12px;
}
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
body { background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; font-size: 14px; line-height: 1.5; }
a { color: var(--accent2); text-decoration: none; }
.wrap { max-width: 1400px; margin: 0 auto; padding: 20px; }

/* Header */
.page-header {
  display: flex; justify-content: space-between; align-items: flex-start;
  margin-bottom: 20px; padding-bottom: 15px; border-bottom: 1px solid var(--border);
}
.page-title { font-size: 22px; font-weight: 700; color: var(--text); }
.page-subtitle { font-size: 13px; color: var(--text-muted); margin-top: 4px; }

/* Pipeline Track */
.pipeline-track {
  display: flex; align-items: center; flex-wrap: wrap;
  background: var(--surface); border: 1px solid var(--border); border-radius: 50px;
  padding: 4px 12px; margin-bottom: 20px; gap: 0;
}
.pipeline-step {
  display: inline-flex; align-items: center; gap: 4px; font-size: 11px; font-weight: 600;
  color: var(--text-muted); padding: 5px 10px; border-radius: 50px; text-decoration: none; transition: .2s;
}
.pipeline-step:hover, .pipeline-step.active { background: rgba(79,70,229,0.1); color: var(--accent); }
.pipeline-arrow { color: var(--border); margin: 0 2px; flex-shrink: 0; }
.pcnt { border-radius: 50%; width: 18px; height: 18px; display: inline-flex; align-items: center; justify-content: center; font-size: 10px; font-weight: 700; color: #fff; }

/* Summary */
.summary-grid {
  display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
  gap: 12px; margin-bottom: 20px;
}
.scard {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 15px; position: relative; overflow: hidden;
}
.scard::before { content: ''; position: absolute; top: 0; left: 0; right: 0; height: 3px; background: var(--accent-line, var(--accent)); }
.scard-val { font-size: 24px; font-weight: 800; color: var(--text); }
.scard-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: .5px; margin-top: 6px; }

/* Pills */
.stage-pills { display: flex; flex-wrap: wrap; gap: 6px; margin-bottom: 20px; }
.stage-pill {
  display: inline-flex; align-items: center; gap: 4px; padding: 4px 12px; border-radius: 50px;
  font-size: 11px; font-weight: 600; border: 1px solid transparent; text-decoration: none; transition: .2s;
}
.stage-pill:hover { transform: translateY(-1px); box-shadow: 0 2px 6px rgba(0,0,0,0.06); }

/* Filters */
.filter-panel {
  background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
  padding: 16px; margin-bottom: 20px;
}
.filter-title { font-size: 13px; font-weight: 700; color: var(--text-muted); text-transform: uppercase; margin-bottom: 12px; }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 10px; }
.filter-group label { display: block; font-size: 11px; font-weight: 600; color: var(--text-muted); text-transform: uppercase; letter-spacing: .4px; margin-bottom: 4px; }
.filter-ctrl {
  width: 100%; background: #fff; border: 1px solid var(--border); border-radius: 6px;
  color: var(--text); padding: 7px 9px; font-size: 13px; outline: none; transition: border-color .2s;
}
.filter-ctrl:focus { border-color: var(--accent); box-shadow: 0 0 0 2px rgba(79,70,229,0.1); }
.filter-ctrl option { background: #fff; }
.filter-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 12px; }

/* Buttons */
.btn {
  display: inline-flex; align-items: center; gap: 5px; padding: 7px 14px; border: none;
  border-radius: 6px; font-size: 12px; font-weight: 600; cursor: pointer; transition: .2s; text-decoration: none;
}
.btn:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,0.08); }
.btn-primary { background: var(--accent); color: #fff; }
.btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
.btn-success { background: var(--success); color: #fff; }
.btn-sm { padding: 4px 10px; font-size: 11px; }

/* View Toggle */
.view-toggle { display: flex; gap: 8px; margin-bottom: 15px; }
.view-btn { padding: 6px 12px; border-radius: 6px; font-size: 12px; border: 1px solid var(--border); background: #fff; cursor: pointer; font-weight: 600; color: var(--text-muted); }
.view-btn.active { background: var(--accent); color: #fff; border-color: var(--accent); }

/* ─── Card Layout ───────────────────────── */
#cardView { display: none; }
#cardView.active { display: grid; grid-template-columns: repeat(auto-fill, minmax(280px, 1fr)); gap: 14px; }
.card {
  background: #fff; border: 1px solid var(--border); border-radius: var(--radius);
  padding: 16px; transition: box-shadow .2s;
}
.card:hover { box-shadow: 0 4px 12px rgba(0,0,0,0.05); }
.card-header {
  display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 10px;
}
.card-title { font-weight: 700; font-size: 16px; color: var(--text); }
.card-subtitle { font-size: 12px; color: var(--text-muted); }
.card-badges { display: flex; gap: 4px; margin-top: 4px; flex-wrap: wrap; }
.badge-tr { background: #e0f2fe; color: #0369a1; padding: 1px 5px; border-radius: 3px; font-size: 10px; font-weight: 600; }
.badge-opt { background: #fef3c7; color: #92400e; padding: 1px 5px; border-radius: 3px; font-size: 10px; font-weight: 600; }
.card-stage { margin-bottom: 10px; }
.card-bars { display: flex; flex-direction: column; gap: 4px; }
.card-bar-row { display: flex; align-items: center; gap: 6px; font-size: 11px; }
.card-bar-label { width: 42px; color: var(--text-muted); }
.card-bar-track { flex: 1; height: 6px; background: var(--surface2); border-radius: 3px; overflow: hidden; }
.card-bar-fill { height: 100%; border-radius: 3px; }
.card-bar-pct { width: 32px; text-align: right; font-weight: 600; }
.card-footer { margin-top: 12px; display: flex; justify-content: space-between; align-items: center; }

/* ─── Table Layout ──────────────────────── */
#tableView { display: none; }
#tableView.active { display: block; }
.table-wrap {
  background: #fff; border: 1px solid var(--border); border-radius: var(--radius); overflow: hidden;
}
.table-toolbar {
  display: flex; justify-content: space-between; align-items: center; padding: 12px 16px;
  border-bottom: 1px solid var(--border);
}
.table-title { font-weight: 700; font-size: 14px; }
.table-count { color: var(--text-muted); font-size: 12px; }
table { width: 100%; border-collapse: collapse; }
th {
  padding: 9px 10px; text-align: left; font-size: 10px; font-weight: 700; color: var(--text-muted);
  text-transform: uppercase; background: var(--surface2); border-bottom: 1px solid var(--border); white-space: nowrap;
}
td { padding: 9px 10px; border-bottom: 1px solid var(--border); vertical-align: middle; }
tr:last-child td { border-bottom: none; }
.data-row:hover td { background: rgba(79,70,229,0.02); cursor: pointer; }

/* Mini bars in table */
.pipe-bars { display: flex; flex-direction: column; gap: 3px; min-width: 140px; }
.pipe-row { display: flex; align-items: center; gap: 4px; font-size: 10px; }
.pipe-label { width: 40px; color: var(--text-muted); }
.pipe-track { flex: 1; height: 6px; background: var(--surface2); border-radius: 3px; overflow: hidden; }
.pipe-fill { height: 100%; border-radius: 3px; }
.pipe-pct { width: 30px; text-align: right; font-weight: 600; }
.fill-print { background: #3b82f6; } .fill-pack { background: #f59e0b; } .fill-deno { background: #f97316; } .fill-d2m { background: #8b5cf6; }
.stage-badge { display: inline-flex; align-items: center; gap: 3px; padding: 2px 6px; border-radius: 50px; font-size: 10px; font-weight: 700; }
.no-data { text-align: center; padding: 60px 20px; color: var(--text-muted); }

/* Print */
@media print {
  body { background: #fff; color: #000; }
  .wrap { padding: 0; max-width: 100%; }
  .page-header .btn, .filter-panel, .pipeline-track, .stage-pills, .view-toggle, .table-toolbar, .btn { display: none !important; }
  #tableView { display: block !important; }
  #cardView { display: none !important; }
  table { border: 1px solid #ccc; }
  th, td { padding: 5px; border: 1px solid #ddd; color: #000; }
}

@media (max-width: 768px) {
  .filter-grid { grid-template-columns: 1fr; }
}
</style>
</head>
<body>
<div class="wrap">

  <!-- Header -->
  <div class="page-header">
    <div>
      <h1 class="page-title">🏭 Production Process Control</h1>
      <div class="page-subtitle">End‑to‑end tracking: JT → Printing → Packing → DENO → D2M</div>
    </div>
    <div style="display:flex; gap:8px;">
      <button class="btn btn-success" onclick="exportReport('excel')">📥 Excel</button>
      <button class="btn btn-primary" onclick="exportReport('pdf')">📄 PDF</button>
    </div>
  </div>

  <!-- Pipeline -->
  <div class="pipeline-track">
    <?php foreach (array_keys($stage_meta) as $i => $key):
      [$lbl, $col, $ico] = $stage_meta[$key];
      $cnt = $sc[$key] ?? 0;
      $qs = http_build_query(array_merge($_GET, ['stage' => $key]));
    ?>
      <?php if ($i > 0): ?><span class="pipeline-arrow">›</span><?php endif; ?>
      <a href="?<?= $qs ?>" class="pipeline-step <?= $stage_filter === $key ? 'active' : '' ?>"
         style="<?= $stage_filter === $key ? "color:{$col}; background:rgba(79,70,229,0.08)" : '' ?>">
        <?= $ico ?> <?= $lbl ?>
        <span class="pcnt" style="background:<?= $col ?>"><?= $cnt ?></span>
      </a>
    <?php endforeach; ?>
    <?php if ($stage_filter): ?>
      <a href="?<?= http_build_query(array_diff_key($_GET, ['stage' => ''])) ?>" class="pipeline-step" style="color:var(--danger); margin-left:6px;">✕ Clear</a>
    <?php endif; ?>
  </div>

  <!-- Summary -->
  <div class="summary-grid">
    <div class="scard" style="--accent-line:#6366f1"><div class="scard-val"><?= $total ?></div><div class="scard-label">Books</div></div>
    <div class="scard" style="--accent-line:#3b82f6"><div class="scard-val"><?= $avg('printing_pct') ?>%</div><div class="scard-label">Avg Printing</div></div>
    <div class="scard" style="--accent-line:#f59e0b"><div class="scard-val"><?= $avg('packing_pct') ?>%</div><div class="scard-label">Avg Packing</div></div>
    <div class="scard" style="--accent-line:#f97316"><div class="scard-val"><?= $avg('deno_pct') ?>%</div><div class="scard-label">Avg DENO</div></div>
    <div class="scard" style="--accent-line:#8b5cf6"><div class="scard-val"><?= $avg('d2m_pct') ?>%</div><div class="scard-label">Avg D2M</div></div>
    <div class="scard" style="--accent-line:#10b981"><div class="scard-val"><?= $sc['delivered'] ?? 0 ?></div><div class="scard-label">Delivered</div></div>
  </div>

  <!-- Stage Pills -->
  <div class="stage-pills">
    <?php foreach ($stage_meta as $k => [$lbl,$col,$ico]): $cnt = $sc[$k]??0; if (!$cnt) continue; ?>
      <a href="?<?= http_build_query(array_merge($_GET,['stage'=>$k])) ?>" class="stage-pill"
         style="background:<?= $col ?>18; color:<?= $col ?>; border-color:<?= $col ?>33;">
        <?= $ico ?> <?= $lbl ?> <strong><?= $cnt ?></strong>
      </a>
    <?php endforeach; ?>
  </div>

  <!-- Filters -->
  <div class="filter-panel">
    <div class="filter-title">🔍 Filters</div>
    <form method="GET" id="filterForm">
      <input type="hidden" name="view" value="<?= $view ?>">
      <div class="filter-grid">
        <div class="filter-group">
          <label>Fiscal Year</label>
          <select name="fiscal_year" class="filter-ctrl">
            <option value="">All</option>
            <?php foreach ($fiscal_years as $fy): ?>
              <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($fy['fiscal_code'] . ' – ' . $fy['fiscal_name']) ?>
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Book Code</label>
          <input type="text" name="book_code" class="filter-ctrl" placeholder="e.g. BK001" value="<?= htmlspecialchars($book_code_filter) ?>">
        </div>
        <div class="filter-group">
          <label>Class</label>
          <input type="number" name="class" class="filter-ctrl" placeholder="e.g. 5" value="<?= htmlspecialchars($class_filter) ?>">
        </div>
        <div class="filter-group">
          <label>JT Status</label>
          <select name="status" class="filter-ctrl">
            <option value="">All</option>
            <?php foreach (['pending','active','processing','fp_completed','bp_completed','completed','cancelled'] as $s): ?>
              <option value="<?= $s ?>" <?= $status_filter === $s ? 'selected' : '' ?>><?= ucfirst(str_replace('_',' ',$s)) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>Stage</label>
          <select name="stage" class="filter-ctrl">
            <option value="">All</option>
            <?php foreach ($stage_meta as $k => [$lbl]): ?>
              <option value="<?= $k ?>" <?= $stage_filter === $k ? 'selected' : '' ?>><?= $lbl ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>D2M Status</label>
          <select name="d2m_status" class="filter-ctrl">
            <option value="">All</option>
            <?php foreach (['DRAFT','CHECKED','VERIFIED','CLOSE','CANCELLED'] as $s): ?>
              <option value="<?= $s ?>" <?= $d2m_status_filter === $s ? 'selected' : '' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="filter-group">
          <label>From (Nepali)</label>
          <input type="text" id="dfn" name="date_from_nep" class="filter-ctrl" placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($date_from_nep) ?>" autocomplete="off" readonly>
        </div>
        <div class="filter-group">
          <label>To (Nepali)</label>
          <input type="text" id="dtn" name="date_to_nep" class="filter-ctrl" placeholder="YYYY-MM-DD" value="<?= htmlspecialchars($date_to_nep) ?>" autocomplete="off" readonly>
        </div>
        <div class="filter-group">
          <label>From (Eng)</label>
          <input type="date" name="date_from_eng" class="filter-ctrl" value="<?= htmlspecialchars($date_from_eng) ?>">
        </div>
        <div class="filter-group">
          <label>To (Eng)</label>
          <input type="date" name="date_to_eng" class="filter-ctrl" value="<?= htmlspecialchars($date_to_eng) ?>">
        </div>
      </div>
      <div class="filter-actions">
        <button type="button" class="btn btn-secondary" onclick="window.location.href='?'">🔄 Reset</button>
        <button type="submit" class="btn btn-primary">🔍 Apply</button>
      </div>
    </form>
  </div>

  <!-- View Toggle -->
  <div class="view-toggle">
    <button class="view-btn <?= $view === 'card' ? 'active' : '' ?>" onclick="switchView('card')">📇 Cards</button>
    <button class="view-btn <?= $view === 'table' ? 'active' : '' ?>" onclick="switchView('table')">📋 Table</button>
  </div>

  <!-- Card View -->
  <div id="cardView" class="<?= $view === 'card' ? 'active' : '' ?>">
    <?php if (empty($filtered)): ?>
      <div class="no-data" style="grid-column:1/-1">📭 No books found.</div>
    <?php else: ?>
      <?php foreach ($filtered as $row):
        [$lbl, $col, $ico] = $stage_meta[$row['pipeline_stage']] ?? $stage_meta['not_started'];
      ?>
      <div class="card">
        <div class="card-header">
          <div>
            <div class="card-title"><?= htmlspecialchars($row['book_code']) ?></div>
            <div class="card-subtitle"><?= htmlspecialchars($row['book_name']) ?></div>
          </div>
          <span class="stage-badge" style="background:<?= $col ?>18; color:<?= $col ?>;"><?= $ico ?> <?= $lbl ?></span>
        </div>
        <div class="card-badges">
          <?php if (!empty($row['is_translated'])): ?><span class="badge-tr">TR</span><?php endif; ?>
          <?php if (!empty($row['is_optional'])): ?><span class="badge-opt">OPT</span><?php endif; ?>
          <span style="font-size:10px; color:var(--text-muted)">Class <?= $row['class_level'] ?? '—' ?></span>
        </div>
        <div class="card-bars" style="margin-top:10px">
          <?php foreach ([['Print',$row['printing_pct'],'#3b82f6'],['Pack',$row['packing_pct'],'#f59e0b'],['Deno',$row['deno_pct'],'#f97316'],['D2M',$row['d2m_pct'],'#8b5cf6']] as [$n,$p,$c]): ?>
          <div class="card-bar-row">
            <span class="card-bar-label"><?= $n ?></span>
            <div class="card-bar-track"><div class="card-bar-fill" style="width:<?= $p ?>%; background:<?= $c ?>"></div></div>
            <span class="card-bar-pct" style="color:<?= $c ?>"><?= $p ?>%</span>
          </div>
          <?php endforeach; ?>
        </div>
        <div class="card-footer">
          <div style="font-size:11px; color:var(--text-muted)">
            <?= (int)($row['jt_count']??0) ?> JT · <?= number_format($row['deno_total_qty']) ?> deno
          </div>
          <a href="report_detail.php?book_code=<?= urlencode($row['book_code']) ?>&fiscal_year=<?= $fiscal_year_filter ?>" class="btn btn-primary btn-sm">Detail</a>
        </div>
      </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

  <!-- Table View -->
  <div id="tableView" class="<?= $view === 'table' ? 'active' : '' ?>">
    <div class="table-wrap">
      <div class="table-toolbar">
        <span class="table-title">📚 Book Production Status</span>
        <span class="table-count"><?= $total ?> book(s)</span>
      </div>
      <?php if (empty($filtered)): ?>
        <div class="no-data">📭 No books found.</div>
      <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Book</th><th>Cls</th><th>Stage</th><th>Job Tickets</th><th>Pipeline</th><th>DENO</th><th>D2M</th><th style="width:80px">Action</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($filtered as $row):
            [$lbl,$col,$ico] = $stage_meta[$row['pipeline_stage']] ?? $stage_meta['not_started'];
          ?>
          <tr class="data-row" onclick="window.location='report_detail.php?book_code=<?= urlencode($row['book_code']) ?>&fiscal_year=<?= $fiscal_year_filter ?>'">
            <td>
              <div style="font-weight:700"><?= htmlspecialchars($row['book_code']) ?></div>
              <div style="font-size:11px;color:var(--text-muted)"><?= htmlspecialchars($row['book_name']) ?></div>
              <div style="font-size:10px"><?= $row['is_translated']?'<span class="badge-tr">TR</span> ':'' ?><?= $row['is_optional']?'<span class="badge-opt">OPT</span> ':'' ?><?= htmlspecialchars($row['book_type']??'') ?></div>
            </td>
            <td style="font-weight:700;color:var(--text-muted)"><?= $row['class_level']??'—' ?></td>
            <td><span class="stage-badge" style="background:<?= $col ?>18;color:<?= $col ?>"><?= $ico ?> <?= $lbl ?></span></td>
            <td>
              <?php if ((int)($row['jt_count']??0)>0): ?>
                <div style="font-weight:700"><?= $row['jt_count'] ?> JT(s)</div>
                <div style="font-size:10px;color:var(--text-muted)"><?= htmlspecialchars($row['jt_codes']??'') ?></div>
              <?php else: ?><span style="color:var(--text-muted)">—</span><?php endif; ?>
            </td>
            <td>
              <div class="pipe-bars">
                <?php foreach ([['Print',$row['printing_pct'],'fill-print','#3b82f6'],['Pack',$row['packing_pct'],'fill-pack','#f59e0b'],['Deno',$row['deno_pct'],'fill-deno','#f97316'],['D2M',$row['d2m_pct'],'fill-d2m','#8b5cf6']] as [$l,$p,$c,$cl]): ?>
                <div class="pipe-row">
                  <span class="pipe-label"><?= $l ?></span>
                  <div class="pipe-track"><div class="pipe-fill <?= $c ?>" style="width:<?= $p ?>%"></div></div>
                  <span class="pipe-pct" style="color:<?= $cl ?>"><?= $p ?>%</span>
                </div>
                <?php endforeach; ?>
              </div>
            </td>
            <td><?= (int)($row['deno_count']??0) ? number_format($row['deno_total_qty']).'<br><small>'.$row['deno_count'].' entries</small>' : '—' ?></td>
            <td><?= (int)($row['d2m_count']??0) ? number_format($row['d2m_total_qty']).'<br><small>'.$row['d2m_count'].' D2M(s)</small>' : '—' ?></td>
            <td><a href="report_detail.php?book_code=<?= urlencode($row['book_code']) ?>&fiscal_year=<?= $fiscal_year_filter ?>" class="btn btn-primary btn-sm">Detail</a></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
      <?php endif; ?>
    </div>
  </div>

</div>

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', () => {
  if (typeof NepaliFunctions !== 'undefined') {
    $('#dfn').nepaliDatePicker({ dateFormat: 'YYYY-MM-DD', closeOnDateSelect: true });
    $('#dtn').nepaliDatePicker({ dateFormat: 'YYYY-MM-DD', closeOnDateSelect: true });
  }
});
function switchView(view) {
  const url = new URL(window.location);
  url.searchParams.set('view', view);
  window.location = url.toString();
}
function exportReport(type) {
  const p = new URLSearchParams(window.location.search);
  p.set('export', type);
  window.location = 'report_export_ppc.php?' + p.toString();
}
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

/* ================= FETCH FISCAL YEARS ================= */
$fy_stmt = $conn->query("SELECT id, fiscal_code, fiscal_name, is_active FROM fiscal_years ORDER BY id ASC");
$fiscal_years = $fy_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_fy = null;
foreach ($fiscal_years as $fy) {
    if ($fy['is_active']) { $active_fy = $fy; break; }
}

/* ================= PARAMETERS ================= */
$selected_fy_id     = $_GET['fy_id']              ?? ($active_fy ? $active_fy['id'] : '');
$translation_filter = $_GET['translation_filter'] ?? 'all';
$selected_classes   = isset($_GET['classes']) && is_array($_GET['classes']) ? array_map('intval', $_GET['classes']) : [];
$search_text        = trim($_GET['search_text'] ?? '');

$selected_fy_name = '';
foreach ($fiscal_years as $fy) {
    if ((string)$fy['id'] === (string)$selected_fy_id) {
        $selected_fy_name = $fy['fiscal_name'] ?? $fy['fiscal_code'];
        break;
    }
}

$all_classes = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL AND class_level > 0 ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);

/* ================= QUERY ================= */
$where_conditions = [];
$params = [];

if ($translation_filter === 'translated')     $where_conditions[] = "b.is_translated = TRUE";
if ($translation_filter === 'non_translated') $where_conditions[] = "b.is_translated = FALSE";

if (!empty($selected_classes)) {
    $phs = [];
    foreach ($selected_classes as $i => $cl) { $phs[] = ":cl$i"; $params[":cl$i"] = $cl; }
    $where_conditions[] = "b.class_level IN (" . implode(',', $phs) . ")";
}
if (!empty($search_text)) {
    $where_conditions[] = "(b.book_name ILIKE :search OR b.book_code ILIKE :search)";
    $params[':search'] = '%' . $search_text . '%';
}

$extra_where  = $where_conditions ? 'AND ' . implode(' AND ', $where_conditions) : '';
$fy_condition = !empty($selected_fy_id) ? 'AND d.fiscal_year_id = :fy_id' : '';

if (!empty($selected_fy_id)) {
    $params[':fy_id'] = $selected_fy_id;
}

$sql = "
    SELECT
        b.book_name,
        b.book_code,
        b.class_level,
        b.is_translated,
        t.title_code,
        COALESCE(SUM(sub.total_poka_qty), 0) AS total_poka,
        COALESCE(SUM(sub.total_qty), 0)      AS total_books,
        COALESCE(SUM(sub.open_pcs), 0)       AS total_open_pcs
    FROM books b
    LEFT JOIN book_titles t ON b.title_id = t.id
    LEFT JOIN (
        SELECT
            di_inner.book_code,
            di_inner.total_poka_qty,
            di_inner.total_qty,
            di_inner.open_pcs
        FROM d2m_items di_inner
        JOIN d2m d ON di_inner.d2m_id = d.id
        WHERE d.deleted_at IS NULL
          AND d.status <> 'CANCELLED'
          $fy_condition
    ) sub ON b.book_code = sub.book_code
    WHERE 1=1 $extra_where
    GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, t.title_code
    ORDER BY b.is_translated DESC, b.class_level, b.book_name
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= PROCESS ================= */
$translated_books     = [];
$non_translated_books = [];

foreach ($raw_data as $row) {
    $entry = [
        'book_name'     => $row['book_name'],
        'book_code'     => $row['book_code'],
        'title_code'    => $row['title_code'] ?? null,
        'class_level'   => $row['class_level'],
        'is_translated' => $row['is_translated'],
        'total_poka'    => (int)$row['total_poka'],
        'total_books'   => (int)$row['total_books'],
        'total_open'    => (int)$row['total_open_pcs'],
    ];

    /* Only include books with actual dispatched data */
    if ($entry['total_books'] > 0 || $entry['total_poka'] > 0 || $entry['total_open'] > 0) {
        if ($row['is_translated']) {
            $translated_books[] = $entry;
        } else {
            $non_translated_books[] = $entry;
        }
    }
}

function yr_section_totals($books) {
    $poka = $books_n = $open = 0;
    foreach ($books as $b) {
        $poka    += $b['total_poka'];
        $books_n += $b['total_books'];
        $open    += $b['total_open'];
    }
    return ['poka' => $poka, 'books' => $books_n, 'open' => $open];
}
$t_totals  = yr_section_totals($translated_books);
$nt_totals = yr_section_totals($non_translated_books);

$grand_poka  = $t_totals['poka']  + $nt_totals['poka'];
$grand_books = $t_totals['books'] + $nt_totals['books'];
$grand_open  = $t_totals['open']  + $nt_totals['open'];

/* ================= EXPORT ================= */
if (isset($_GET['export']) && in_array($_GET['export'], ['csv','excel'])) {
    $filename = 'd2m_yearly_report_' . ($selected_fy_name ?: 'all') . '_' . date('Y-m-d_H-i-s');
    $col_headers = ['SN','Book Name','Code','Class','Type','Total Poka Qty','Total Open Pcs','Total Books'];

    $rows = [];
    foreach (array_merge($translated_books, $non_translated_books) as $book) {
        $rows[] = [
            '', $book['book_name'], $book['book_code'], $book['class_level'],
            $book['is_translated'] ? 'T' : 'NT',
            $book['total_poka'], $book['total_open'], $book['total_books']
        ];
    }
    $sn = 1;
    foreach ($rows as &$r) { $r[0] = $sn++; }
    unset($r);

    $total_row = ['', 'GRAND TOTAL', '', '', '', $grand_poka, $grand_open, $grand_books];

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($out, $col_headers);
        foreach ($rows as $r) fputcsv($out, $r);
        fputcsv($out, $total_row);
        fclose($out);
        exit;
    }
    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<?xml version="1.0" encoding="UTF-8"?><html><head><meta charset="UTF-8"><style>';
        echo 'body{font-family:Arial;font-size:10pt}table{border-collapse:collapse;width:100%}';
        echo 'th,td{border:1px solid #000;padding:4px 8px;text-align:center}th{background:#6f42c1;color:#fff;font-weight:bold}.left{text-align:left}.bold{font-weight:bold}.total-row{background:#d1c4e9}';
        echo '</style></head><body><table><tr>';
        foreach ($col_headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $i => $v) { $cls = ($i === 1) ? ' class="left"' : ''; echo '<td' . $cls . '>' . htmlspecialchars((string)$v) . '</td>'; }
            echo '</tr>';
        }
        echo '<tr class="total-row">';
        foreach ($total_row as $i => $v) { $cls = ($i === 1) ? ' class="left bold"' : ' class="bold"'; echo '<td' . $cls . '>' . htmlspecialchars((string)$v) . '</td>'; }
        echo '</tr></table></body></html>';
        exit;
    }
}
$total_books_count = count($translated_books) + count($non_translated_books);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>D2M Yearly Dispatch Report</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;margin:0;padding:0;font-size:9pt}

.no-print{padding:6px 10px;background:#f0f0f0;border-bottom:2px solid #bbb;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.no-print label{font-size:8pt;font-weight:600;margin-right:2px}
.no-print select,.no-print input{font-size:8pt;padding:2px 4px;border:1px solid #aaa;border-radius:3px;height:24px}
.no-print select[multiple]{height:80px}
.no-print button{font-size:8pt;padding:2px 10px;height:24px;cursor:pointer;border-radius:3px;border:1px solid #888;background:#fff}
.no-print button.btn-apply{background:#6f42c1;color:#fff;border-color:#6f42c1}
.no-print button.btn-clear{background:#a00;color:#fff;border-color:#a00}
.no-print a.btn-dl{font-size:8pt;padding:2px 10px;height:24px;cursor:pointer;border-radius:3px;border:1px solid #888;background:#1a7a1a;color:#fff;text-decoration:none;display:inline-flex;align-items:center}
.no-print a.btn-dl-xl{background:#17527a}

.report-header{text-align:center;border:2px solid #333;padding:5px 10px;margin:6px 10px 4px;background:#fff}
.report-header h1{margin:2px 0;font-size:14pt;letter-spacing:1px}
.report-header h2{margin:2px 0;font-size:10pt;font-weight:600}
.report-header p{margin:2px 0;font-size:8pt}

.table-container{overflow-x:auto;margin:0 10px;max-height:calc(100vh - 180px);overflow-y:auto}
.report-table{width:100%;border-collapse:collapse;font-size:9pt}
.report-table thead{position:sticky;top:-1px;z-index:80;background:#6f42c1}
.report-table th{background:#6f42c1;color:#fff;border:1px solid #555;padding:4px 4px;text-align:center;font-weight:bold;font-size:9pt}
.report-table tfoot{position:sticky;bottom:0;z-index:80}
.report-table tfoot th{background:#5a32a3;color:#fff;border:1px solid #555;padding:4px 4px;font-weight:bold;font-size:9pt}
.report-table td{border:1px solid #aaa;padding:3px 4px;text-align:center;background:#fff;font-size:9.5pt;font-weight:500}
.report-table td.qty-val{font-size:10pt;font-weight:700;color:#111}
.book-name{text-align:left;max-width:280px;width:280px;word-wrap:break-word;word-break:break-word;font-size:16pt;padding:1px 4px;white-space:normal;line-height:1.3}
.section-header{background:#e2d5f0;font-weight:bold;text-align:left;font-size:9pt;padding:4px 8px;color:#6f42c1}
.section-total td{background:#e8def5;font-weight:bold;font-size:9.5pt}
.grand-total td{background:#d1c4e9;font-size:10pt;font-weight:bold}

@media print {
  @page{size:A4 portrait;margin:8mm 6mm}
  body{padding:2;margin:2;font-size:8pt}
  .no-print{display:none!important}
  .table-container{margin:0;overflow:visible;max-height:none}
  .report-header{margin:1mm 2mm;padding:1mm 2mm;border:1.5px solid #000}
  .report-header h1{font-size:12pt;margin:1mm 0}
  .report-header h2{font-size:9pt;margin:.5mm 0}
  .report-header p{font-size:7pt;margin:.5mm 0}
  .report-table thead,.report-table tfoot{position:static}
  .report-table thead{display:table-header-group}
  .report-table tfoot{display:none}
  .report-table{font-size:7pt;border-collapse:collapse}
  .report-table th{font-size:7pt;background:#ddd!important;color:#000!important;border:.4px solid #555;padding:.5mm 1mm}
  .report-table td{border:.4px solid #999;padding:.3mm 1mm;font-size:8pt;font-weight:600}
  .report-table td.qty-val{font-size:8.5pt;font-weight:700}
  .book-name{font-size:10pt!important;width:60mm!important;max-width:60mm!important;word-break:break-word!important;line-height:1.2!important}
  .section-header{background:#ccc!important;font-size:7.5pt;padding:.5mm 1mm}
  .section-total td,.grand-total td{background:#ddd!important;font-size:7.5pt}
}
</style>
</head>
<body>

<!-- ===== FILTER BAR ===== -->
<div class="no-print">
  <form method="GET" action="" style="display:flex;flex-wrap:wrap;gap:6px;align-items:flex-start">

    <div style="display:flex;flex-direction:column;gap:2px">
      <label>Fiscal Year</label>
      <select name="fy_id" onchange="this.form.submit()">
        <option value="">All Fiscal Years</option>
        <?php foreach ($fiscal_years as $fy): ?>
          <option value="<?= htmlspecialchars($fy['id']) ?>"
            <?= (string)$fy['id'] === (string)$selected_fy_id ? 'selected' : '' ?>>
            <?= htmlspecialchars($fy['fiscal_name'] ?? $fy['fiscal_code']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;flex-direction:column;gap:2px">
      <label>Translation</label>
      <select name="translation_filter">
        <option value="all"           <?= $translation_filter==='all'           ?'selected':'' ?>>All Books</option>
        <option value="translated"    <?= $translation_filter==='translated'    ?'selected':'' ?>>Translated</option>
        <option value="non_translated"<?= $translation_filter==='non_translated'?'selected':'' ?>>Non-Translated</option>
      </select>
    </div>

    <div style="display:flex;flex-direction:column;gap:2px">
      <label>Class Levels</label>
      <select name="classes[]" multiple>
        <?php foreach ($all_classes as $cl): ?>
          <option value="<?= $cl ?>" <?= in_array((int)$cl, $selected_classes) ?'selected':'' ?>>Class <?= $cl ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;flex-direction:column;gap:2px">
      <label>Search</label>
      <input type="text" name="search_text" value="<?= htmlspecialchars($search_text) ?>" placeholder="Name or Code...">
    </div>

    <div style="display:flex;align-items:flex-end;gap:4px;padding-bottom:1px">
      <button type="submit" class="btn-apply">Apply</button>
      <button type="button" class="btn-clear" onclick="location.href='<?= $_SERVER['PHP_SELF']?>'">Clear</button>
      <button type="button" onclick="window.print()">🖨 Print</button>
      <a class="btn-dl"     href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>">⬇ CSV</a>
      <a class="btn-dl btn-dl-xl" href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>">⬇ Excel</a>
    </div>

  </form>
</div>

<!-- ===== REPORT HEADER ===== -->
<div class="report-header">
  <h1>JANAK EDUCATION MATERIALS CENTER</h1>
  <h2>Yearly D2M Dispatch Summary Report</h2>
  <p>आ.व. <?= htmlspecialchars($selected_fy_name ?: 'All') ?> — वर्षभरि बजार व्यवस्था विभागमा बुझाएको पुस्तकहरुको विवरण
    <?php if ($translation_filter!=='all') echo ' &nbsp;|&nbsp; ' . ucwords(str_replace('_',' ',$translation_filter)); ?>
    <?php if (!empty($search_text))        echo ' &nbsp;|&nbsp; Search: "' . htmlspecialchars($search_text) . '"'; ?>
  </p>
  <p><strong>Books Dispatched:</strong> <?= $total_books_count ?>
     | <strong>Total Poka:</strong> <?= number_format($grand_poka) ?>
     | <strong>Total Books:</strong> <?= number_format($grand_books) ?>
     | <strong>Total Open Pcs:</strong> <?= number_format($grand_open) ?></p>
</div>

<!-- ===== TABLE ===== -->
<div class="table-container">
<table class="report-table">
<thead>
  <tr>
    <th style="width:30px">SN</th>
    <th style="width:280px;text-align:left">Book Name</th>
    <th style="width:80px">Code</th>
    <th style="width:30px">Cl.</th>
    <th style="width:30px">Type</th>
    <th style="width:100px">Total Poka Qty</th>
    <th style="width:100px">Total Open Pcs</th>
    <th style="width:100px">Total Books</th>
  </tr>
</thead>
<tbody>

<?php if ($total_books_count === 0): ?>
<tr><td colspan="8" style="text-align:center;padding:30px;color:#999">
    No D2M dispatch data found for the selected filters.</td></tr>
<?php else: ?>

<?php /* ---------- TRANSLATED ---------- */ ?>
<?php if (!empty($translated_books)): ?>
<tr><td colspan="8" class="section-header">TRANSLATED BOOKS</td></tr>
<?php $sn = 1; foreach ($translated_books as $book): ?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($book['book_name']) ?></td>
  <td style="font-size:8pt"><?= htmlspecialchars($book['book_code']) ?><?php if (!empty($book['title_code'])): ?><br><span style="color:#6f42c1;font-size:7pt">Title: <?= htmlspecialchars($book['title_code']) ?></span><?php endif; ?></td>
  <td><?= $book['class_level'] ?></td>
  <td>T</td>
  <td class="qty-val"><?= $book['total_poka'] ? number_format($book['total_poka']) : '-' ?></td>
  <td class="qty-val"><?= $book['total_open'] ? number_format($book['total_open']) : '-' ?></td>
  <td class="qty-val"><strong><?= $book['total_books'] ? number_format($book['total_books']) : '-' ?></strong></td>
</tr>
<?php endforeach; ?>
<tr class="section-total">
  <td colspan="5" style="text-align:right;padding-right:8px">Translated Total</td>
  <td><strong><?= number_format($t_totals['poka']) ?></strong></td>
  <td><strong><?= number_format($t_totals['open']) ?></strong></td>
  <td><strong><?= number_format($t_totals['books']) ?></strong></td>
</tr>
<?php endif; ?>

<?php /* ---------- NON-TRANSLATED ---------- */ ?>
<?php if (!empty($non_translated_books)): ?>
<tr><td colspan="8" class="section-header">NON-TRANSLATED BOOKS</td></tr>
<?php $sn = 1; foreach ($non_translated_books as $book): ?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($book['book_name']) ?></td>
  <td style="font-size:8pt"><?= htmlspecialchars($book['book_code']) ?><?php if (!empty($book['title_code'])): ?><br><span style="color:#6f42c1;font-size:7pt">Title: <?= htmlspecialchars($book['title_code']) ?></span><?php endif; ?></td>
  <td><?= $book['class_level'] ?></td>
  <td>NT</td>
  <td class="qty-val"><?= $book['total_poka'] ? number_format($book['total_poka']) : '-' ?></td>
  <td class="qty-val"><?= $book['total_open'] ? number_format($book['total_open']) : '-' ?></td>
  <td class="qty-val"><strong><?= $book['total_books'] ? number_format($book['total_books']) : '-' ?></strong></td>
</tr>
<?php endforeach; ?>
<tr class="section-total">
  <td colspan="5" style="text-align:right;padding-right:8px">Non-Translated Total</td>
  <td><strong><?= number_format($nt_totals['poka']) ?></strong></td>
  <td><strong><?= number_format($nt_totals['open']) ?></strong></td>
  <td><strong><?= number_format($nt_totals['books']) ?></strong></td>
</tr>
<?php endif; ?>

<?php /* ---------- GRAND TOTAL ---------- */ ?>
<tr class="grand-total">
  <td colspan="5" style="text-align:right;padding-right:8px"><strong>GRAND TOTAL</strong></td>
  <td><strong><?= number_format($grand_poka) ?></strong></td>
  <td><strong><?= number_format($grand_open) ?></strong></td>
  <td><strong><?= number_format($grand_books) ?></strong></td>
</tr>

<?php endif; ?>
</tbody>
<tfoot>
<tr>
  <th colspan="5">Grand Totals</th>
  <th><?= number_format($grand_poka) ?></th>
  <th><?= number_format($grand_open) ?></th>
  <th><?= number_format($grand_books) ?></th>
</tr>
</tfoot>
</table>
</div>

<?php ob_end_flush(); ?>
</body>
</html>

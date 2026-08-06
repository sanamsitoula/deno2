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
$selected_fy_id      = $_GET['fy_id']              ?? ($active_fy ? $active_fy['id'] : '');
$translation_filter  = $_GET['translation_filter'] ?? 'all';
$selected_classes    = isset($_GET['classes']) && is_array($_GET['classes']) ? array_map('intval', $_GET['classes']) : [];
$search_text         = trim($_GET['search_text'] ?? '');

/* Resolve fiscal_name for display */
$selected_fy_name = '';
foreach ($fiscal_years as $fy) {
    if ((string)$fy['id'] === (string)$selected_fy_id) {
        $selected_fy_name = $fy['fiscal_name'] ?? $fy['fiscal_code'];
        break;
    }
}

/*
 * Nepali fiscal year month order (Shrawan first):
 *  04=Shr, 05=Bha, 06=Ash, 07=Kar, 08=Man, 09=Pou
 *  10=Mag, 11=Fal, 12=Cha, 01=Bai, 02=Jes, 03=Asa
 */
$fiscal_month_order = ['04','05','06','07','08','09','10','11','12','01','02','03'];
$fiscal_month_abbr  = [
    '04'=>'Shr','05'=>'Bha','06'=>'Ash','07'=>'Kar',
    '08'=>'Man','09'=>'Pou','10'=>'Mag','11'=>'Fal',
    '12'=>'Cha','01'=>'Bai','02'=>'Jes','03'=>'Asa'
];

/* ================= BOOKS / CLASSES ================= */
$all_classes = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL AND class_level > 0 ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);

/* ================= QUERY ================= */
$where_conditions = [];
$params = [];

if (!empty($selected_fy_id)) {
    $params[':fy_id'] = $selected_fy_id;
}

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

$extra_where = $where_conditions ? 'AND ' . implode(' AND ', $where_conditions) : '';
$fy_condition = !empty($selected_fy_id) ? 'AND d.fiscal_year_id = :fy_id' : '';

$sql = "
    SELECT
        b.book_name,
        b.book_code,
        b.class_level,
        b.is_translated,
        t.title_code,
        sub.month_num,
        COALESCE(SUM(sub.total_qty), 0) AS total_dispatched
    FROM books b
    LEFT JOIN book_titles t ON b.title_id = t.id
    LEFT JOIN (
        SELECT
            di.book_code,
            di.total_qty,
            LPAD(SPLIT_PART(d.nep_date, '.', 2), 2, '0') AS month_num
        FROM d2m_items di
        JOIN d2m d ON di.d2m_id = d.id
        WHERE d.deleted_at IS NULL
          AND d.status <> 'CANCELLED'
          $fy_condition
    ) sub ON b.book_code = sub.book_code
    WHERE 1=1 $extra_where
    GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, t.title_code, sub.month_num
    ORDER BY b.is_translated DESC, b.class_level, b.book_name
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= PROCESS ================= */
$books_data     = [];
$slot_totals    = array_fill_keys($fiscal_month_order, 0);
$grand_dispatched = 0;

foreach ($raw_data as $row) {
    $bk = $row['book_code'];
    if (!isset($books_data[$bk])) {
        $books_data[$bk] = [
            'book_name'       => $row['book_name'],
            'book_code'       => $bk,
            'title_code'      => $row['title_code'] ?? null,
            'class_level'     => $row['class_level'],
            'is_translated'   => $row['is_translated'],
            'slots'           => array_fill_keys($fiscal_month_order, 0),
            'total_dispatched'=> 0,
        ];
    }

    if (!empty($row['month_num']) && in_array($row['month_num'], $fiscal_month_order)) {
        $qty = (int)$row['total_dispatched'];
        $books_data[$bk]['slots'][$row['month_num']]   += $qty;
        $books_data[$bk]['total_dispatched']           += $qty;
        $slot_totals[$row['month_num']]                += $qty;
        $grand_dispatched                               += $qty;
    }
}

/* Only keep books that actually have dispatched data */
$books_with_data = array_filter($books_data, fn($b) => $b['total_dispatched'] > 0);

$translated_books     = array_filter($books_with_data, fn($b) => $b['is_translated']);
$non_translated_books = array_filter($books_with_data, fn($b) => !$b['is_translated']);

function section_totals($books, $months) {
    $t   = array_fill_keys($months, 0);
    $tot = 0;
    foreach ($books as $b) {
        foreach ($months as $m) { $t[$m] += $b['slots'][$m]; }
        $tot += $b['total_dispatched'];
    }
    return ['slots' => $t, 'total' => $tot];
}
$t_totals  = section_totals($translated_books,     $fiscal_month_order);
$nt_totals = section_totals($non_translated_books, $fiscal_month_order);

/* ================= EXPORT ================= */
if (isset($_GET['export']) && in_array($_GET['export'], ['csv','excel'])) {
    $filename = 'd2m_monthly_report_' . ($selected_fy_name ?: 'all') . '_' . date('Y-m-d_H-i-s');
    $col_headers = ['SN','Book Name','Code','Class','Type'];
    foreach ($fiscal_month_order as $m) { $col_headers[] = $fiscal_month_abbr[$m]; }
    $col_headers[] = 'Total';

    $rows = [];
    foreach ($books_with_data as $book) {
        $row = ['', $book['book_name'], $book['book_code'], $book['class_level'], $book['is_translated'] ? 'T' : 'NT'];
        foreach ($fiscal_month_order as $m) { $row[] = $book['slots'][$m]; }
        $row[] = $book['total_dispatched'];
        $rows[] = $row;
    }
    $sn = 1;
    foreach ($rows as &$r) { $r[0] = $sn++; }
    unset($r);

    $total_row = ['', 'GRAND TOTAL', '', '', ''];
    foreach ($fiscal_month_order as $m) { $total_row[] = $slot_totals[$m]; }
    $total_row[] = $grand_dispatched;

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
        echo 'body{font-family:Arial;font-size:8pt}table{border-collapse:collapse;width:100%}';
        echo 'th,td{border:1px solid #000;padding:2px 4px;text-align:center}th{background:#6f42c1;color:#fff;font-weight:bold}.left{text-align:left}.bold{font-weight:bold}.total-row{background:#e2d5f0}';
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
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>D2M Monthly Dispatch Report</title>
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

.table-container{overflow-x:auto;margin:0 4px;max-height:calc(100vh - 180px);overflow-y:auto}
.report-table{width:100%;border-collapse:collapse;font-size:8pt}
.report-table thead{position:sticky;top:-1px;z-index:80;background:#6f42c1}
.report-table th{background:#6f42c1;color:#fff;border:1px solid #555;padding:3px 2px;text-align:center;font-weight:bold;font-size:8pt}
.report-table tfoot{position:sticky;bottom:0;z-index:80}
.report-table tfoot th{background:#5a32a3;color:#fff;border:1px solid #555;padding:3px 2px;font-weight:bold;font-size:8pt}
.report-table td{border:1px solid #aaa;padding:2px 2px;text-align:center;background:#fff;font-size:8.5pt;font-weight:500}
.report-table td.qty-val{font-size:9pt;font-weight:700;color:#111}
.book-name{text-align:left;max-width:200px;width:200px;word-wrap:break-word;word-break:break-word;font-size:16pt;padding:1px 2px;white-space:normal;line-height:1.3}
.section-header{background:#e2d5f0;font-weight:bold;text-align:left;font-size:8pt;padding:3px 6px;color:#6f42c1}
.section-total td{background:#e8def5;font-weight:bold;font-size:8.5pt}
.grand-total td{background:#d1c4e9;font-size:9pt;font-weight:bold}

@media print {
  @page{size:A4 landscape;margin:6mm 4mm}
  body{padding:2;margin:2;font-size:7pt}
  .no-print{display:none!important}
  .table-container{margin:0;overflow:visible;max-height:none}
  .report-header{margin:1mm 2mm;padding:1mm 2mm;border:1.5px solid #000}
  .report-header h1{font-size:11pt;margin:1mm 0}
  .report-header h2{font-size:8pt;margin:.5mm 0}
  .report-header p{font-size:6.5pt;margin:.5mm 0}
  .report-table thead,.report-table tfoot{position:static}
  .report-table thead{display:table-header-group}
  .report-table tfoot{display:none}
  .report-table{font-size:6pt;border-collapse:collapse}
  .report-table th{font-size:6pt;background:#ddd!important;color:#000!important;border:.4px solid #555;padding:.5mm .2mm}
  .report-table td{border:.4px solid #999;padding:.3mm .1mm;font-size:7pt;font-weight:600}
  .report-table td.qty-val{font-size:7.5pt;font-weight:700}
  .book-name{font-size:9pt!important;width:40mm!important;max-width:40mm!important;word-break:break-word!important;line-height:1.2!important}
  .section-header{background:#ccc!important;font-size:6.5pt;padding:.5mm 1mm}
  .section-total td,.grand-total td{background:#ddd!important;font-size:6.5pt}
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
  <h2>Monthly D2M Dispatch Summary Report</h2>
  <h1>आ.व. <?= htmlspecialchars($selected_fy_name ?: 'All') ?> मा बजार व्यवस्था विभागमा बुझाएको पुस्तकहरुको विवरण
    <?php if ($translation_filter!=='all') echo ' &nbsp;|&nbsp; ' . ucwords(str_replace('_',' ',$translation_filter)); ?>
    <?php if (!empty($search_text))        echo ' &nbsp;|&nbsp; Search: "' . htmlspecialchars($search_text) . '"'; ?>
  </h1>
</div>

<!-- ===== TABLE ===== -->
<div class="table-container">
<table class="report-table">
<thead>
  <tr>
    <th rowspan="2" style="width:24px">SN</th>
    <th rowspan="2" style="width:120px;text-align:left">Book Name</th>
    <th rowspan="2" style="width:70px">Code</th>
    <th rowspan="2" style="width:26px">Cl.</th>
    <th colspan="12">Monthly D2M Dispatch &nbsp;–&nbsp; <?= htmlspecialchars($selected_fy_name ?: 'All') ?></th>
    <th rowspan="2" style="width:40px">Total</th>
  </tr>
  <tr>
    <?php foreach ($fiscal_month_order as $m): ?>
      <th style="width:30px"><?= $fiscal_month_abbr[$m] ?></th>
    <?php endforeach; ?>
  </tr>
</thead>
<tbody>

<?php if (empty($books_with_data)): ?>
<tr><td colspan="<?= 4 + count($fiscal_month_order) + 1 ?>" style="text-align:center;padding:30px;color:#999">
    No D2M dispatch data found for the selected filters.</td></tr>
<?php else: ?>

<?php /* ---------- TRANSLATED ---------- */ ?>
<?php if (!empty($translated_books)): ?>
<tr><td colspan="<?= 4 + count($fiscal_month_order) + 1 ?>" class="section-header">TRANSLATED BOOKS</td></tr>
<?php $sn = 1; foreach ($translated_books as $book): ?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($book['book_name']) ?></td>
  <td style="font-size:7pt"><?= htmlspecialchars($book['book_code']) ?><?php if (!empty($book['title_code'])): ?><br><span style="color:#6f42c1;font-size:6pt">Title: <?= htmlspecialchars($book['title_code']) ?></span><?php endif; ?></td>
  <td><?= $book['class_level'] ?></td>
  <?php foreach ($fiscal_month_order as $m): $v = $book['slots'][$m]; ?>
    <td class="qty-val"><?= $v ? $v : '-' ?></td>
  <?php endforeach; ?>
  <td class="qty-val"><strong><?= $book['total_dispatched'] ?></strong></td>
</tr>
<?php endforeach; ?>
<tr class="section-total">
  <td colspan="4" style="text-align:right;padding-right:6px">Translated Total</td>
  <?php foreach ($fiscal_month_order as $m): $v = $t_totals['slots'][$m]; ?>
    <td><strong><?= $v ?: '-' ?></strong></td>
  <?php endforeach; ?>
  <td><strong><?= $t_totals['total'] ?></strong></td>
</tr>
<?php endif; ?>

<?php /* ---------- NON-TRANSLATED ---------- */ ?>
<?php if (!empty($non_translated_books)): ?>
<tr><td colspan="<?= 4 + count($fiscal_month_order) + 1 ?>" class="section-header">NON-TRANSLATED BOOKS</td></tr>
<?php $sn = 1; foreach ($non_translated_books as $book): ?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($book['book_name']) ?></td>
  <td style="font-size:7pt"><?= htmlspecialchars($book['book_code']) ?><?php if (!empty($book['title_code'])): ?><br><span style="color:#6f42c1;font-size:6pt">Title: <?= htmlspecialchars($book['title_code']) ?></span><?php endif; ?></td>
  <td><?= $book['class_level'] ?></td>
  <?php foreach ($fiscal_month_order as $m): $v = $book['slots'][$m]; ?>
    <td class="qty-val"><?= $v ? $v : '-' ?></td>
  <?php endforeach; ?>
  <td class="qty-val"><strong><?= $book['total_dispatched'] ?></strong></td>
</tr>
<?php endforeach; ?>
<tr class="section-total">
  <td colspan="4" style="text-align:right;padding-right:6px">Non-Translated Total</td>
  <?php foreach ($fiscal_month_order as $m): $v = $nt_totals['slots'][$m]; ?>
    <td><strong><?= $v ?: '-' ?></strong></td>
  <?php endforeach; ?>
  <td><strong><?= $nt_totals['total'] ?></strong></td>
</tr>
<?php endif; ?>

<?php /* ---------- GRAND TOTAL ---------- */ ?>
<tr class="grand-total">
  <td colspan="4" style="text-align:right;padding-right:6px"><strong>GRAND TOTAL</strong></td>
  <?php foreach ($fiscal_month_order as $m): $v = $slot_totals[$m]; ?>
    <td><strong><?= $v ?: '-' ?></strong></td>
  <?php endforeach; ?>
  <td><strong><?= $grand_dispatched ?></strong></td>
</tr>

<?php endif; ?>
</tbody>
<tfoot>
<tr>
  <th colspan="4">Monthly Totals</th>
  <?php foreach ($fiscal_month_order as $m): $v = $slot_totals[$m]; ?>
    <th><?= $v ?: '-' ?></th>
  <?php endforeach; ?>
  <th><?= $grand_dispatched ?></th>
</tr>
</tfoot>
</table>
</div>

<?php ob_end_flush(); ?>
</body>
</html>

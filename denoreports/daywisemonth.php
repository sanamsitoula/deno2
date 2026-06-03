<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

/* ================= FETCH FISCAL YEARS ================= */
$fy_stmt = $conn->query("SELECT fiscal_code, fiscal_name, is_active FROM fiscal_years ORDER BY id ASC");
$fiscal_years = $fy_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_fy = null;
foreach ($fiscal_years as $fy) {
    if ($fy['is_active']) { $active_fy = $fy; break; }
}

/* ================= FETCH BOOKS FOR DROPDOWN ================= */
$books_stmt = $conn->query("SELECT book_code, book_name, class_level FROM books ORDER BY class_level, book_name");
$all_books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= PARAMETERS ================= */
$year        = $_GET['year']        ?? ($active_fy ? $active_fy['fiscal_code'] : '2082');
$month       = $_GET['month']       ?? '04'; // default Shrawan (fiscal start)
$translation = $_GET['translation'] ?? 'all';
$search      = trim($_GET['search'] ?? '');
$book_code   = trim($_GET['book_code'] ?? '');
$class_level = trim($_GET['class_level'] ?? '');

// report_type always production — filter removed
$report_type = 'production';

/* ================= FISCAL YEAR RESOLUTION ================= */
// deno.fiscal_year stores fiscal_name e.g. '2082-83', NOT fiscal_code '2082'
$fiscal_name_for_query = $year;
$selected_fy_name      = $year;
foreach ($fiscal_years as $fy) {
    if ($fy['fiscal_code'] === $year) {
        $fiscal_name_for_query = $fy['fiscal_name'] ?? $year;
        $selected_fy_name      = $fy['fiscal_name'] ?? $year;
        break;
    }
}

/*
 * Nepali fiscal year month order (Shrawan first):
 *  04=Shrawan, 05=Bhadra, 06=Ashoj, 07=Kartik, 08=Mangsir, 09=Poush
 *  10=Magh,    11=Falgun, 12=Chaitra, 01=Baisakh, 02=Jestha, 03=Asar
 */
$nepali_months = [
    '04' => 'Shrawan',  '05' => 'Bhadra',  '06' => 'Ashoj',
    '07' => 'Kartik',   '08' => 'Mangsir', '09' => 'Poush',
    '10' => 'Magh',     '11' => 'Falgun',  '12' => 'Chaitra',
    '01' => 'Baisakh',  '02' => 'Jestha',  '03' => 'Asar',
];

/* ================= QUERY ================= */
$where  = [];
$params = [
    ':fiscal_year' => $fiscal_name_for_query,
    ':month'       => $month,
];

if ($translation === 'translated')     $where[] = 'b.is_translated = TRUE';
if ($translation === 'non_translated') $where[] = 'b.is_translated = FALSE';

if ($search !== '') {
    $where[] = '(b.book_name ILIKE :search OR b.book_code ILIKE :search)';
    $params[':search'] = "%$search%";
}
if ($book_code !== '') {
    $where[] = 'b.book_code ILIKE :book_code';
    $params[':book_code'] = "%$book_code%";
}
if ($class_level !== '') {
    $where[] = 'b.class_level = :class_level';
    $params[':class_level'] = (int)$class_level;
}

$where_sql = $where ? 'AND ' . implode(' AND ', $where) : '';

/*
 * Filter by fiscal_year::varchar (stores '2082-83') and month from deno_date_nep (YYYY.MM.DD).
 * Day extracted from part 3. Only total_qty — openpcs removed entirely.
 */
$sql = "
SELECT
    b.book_name, b.book_code, b.class_level, b.is_translated,
    LPAD(SPLIT_PART(d.deno_date_nep, '.', 3), 2, '0') AS day,
    COALESCE(SUM(d.total_qty), 0) AS total_produced
FROM Books b
LEFT JOIN Deno d ON b.book_code = d.book_code
    AND d.deleted_at IS NULL
    AND d.fiscal_year::varchar = :fiscal_year
    AND LPAD(SPLIT_PART(d.deno_date_nep, '.', 2), 2, '0') = :month
$where_sql
GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, day
ORDER BY b.is_translated DESC, b.class_level, b.book_name, day
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= PROCESS ================= */
$translated           = [];
$non_translated       = [];
$daily_totals         = [];
$translated_total     = 0;
$non_translated_total = 0;

for ($i = 1; $i <= 32; $i++) {
    $k = sprintf('%02d', $i);
    $daily_totals[$k] = ['translated' => 0, 'non_translated' => 0, 'total' => 0];
}

foreach ($data as $r) {
    if ($r['is_translated']) {
        $arr = &$translated;
    } else {
        $arr = &$non_translated;
    }

    if (!isset($arr[$r['book_code']])) {
        $days = [];
        for ($i = 1; $i <= 32; $i++) $days[sprintf('%02d', $i)] = 0;
        $arr[$r['book_code']] = [
            'name'  => $r['book_name'],
            'code'  => $r['book_code'],
            'class' => $r['class_level'],
            'days'  => $days,
            'total' => 0,
        ];
    }

    if (!empty($r['day'])) {
        $val = (int)$r['total_produced'];
        $arr[$r['book_code']]['days'][$r['day']]  = $val;
        $arr[$r['book_code']]['total']            += $val;

        if ($r['is_translated']) {
            $daily_totals[$r['day']]['translated'] += $val;
            $translated_total += $val;
        } else {
            $daily_totals[$r['day']]['non_translated'] += $val;
            $non_translated_total += $val;
        }
        $daily_totals[$r['day']]['total'] += $val;
    }
}

$grand_total = $translated_total + $non_translated_total;
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Daily Production Report</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;margin:0;padding:0;font-size:9pt}

.no-print{text-align:left;padding:6px 10px;background:#f0f0f0;border-bottom:2px solid #bbb;display:flex;flex-wrap:wrap;gap:4px;align-items:center}
.no-print label{font-size:8pt;font-weight:600;margin-right:2px}
.no-print select,.no-print input{font-size:8pt;padding:2px 4px;border:1px solid #aaa;border-radius:3px;height:24px}
.no-print button{font-size:8pt;padding:2px 10px;height:24px;cursor:pointer;border-radius:3px;border:1px solid #888;background:#fff}
.no-print button.btn-apply{background:#336;color:#fff;border-color:#336}

.report-header{text-align:center;border:2px solid #333;padding:5px 10px;margin:6px 10px 4px;background:#fff}
.report-header h1{margin:2px 0;font-size:14pt;letter-spacing:1px}
.report-header h2{margin:2px 0;font-size:10pt;font-weight:600}
.report-header p{margin:2px 0;font-size:8pt}

.table-container{overflow-x:auto;margin:0 4px;max-height:calc(100vh - 160px);overflow-y:auto}
.report-table{width:100%;border-collapse:collapse;font-size:7.5pt}
.report-table thead{position:sticky;top:-1px;z-index:80;background:#333}
.report-table th{background:#333;color:#fff;border:1px solid #555;padding:3px 1px;text-align:center;font-weight:bold;font-size:7.5pt}
.report-table tfoot{position:sticky;bottom:0;z-index:80}
.report-table tfoot th{background:#4a4a4a;color:#fff;border:1px solid #555;padding:3px 1px;font-weight:bold;font-size:8pt}
.daily-totals{background:#4a4a4a;color:#fff;font-weight:bold}
.report-table td{border:1px solid #aaa;padding:1px 1px;text-align:center;background:#fff;font-size:8.5pt;font-weight:500}
.report-table td.qty-val{font-size:9pt;font-weight:700;color:#111}

/* book-name: 2× font (original ~8pt → 16pt) */
.book-name{text-align:left;max-width:150px;width:150px;word-wrap:break-word;word-break:break-word;font-size:16pt;padding:0 2px;white-space:normal;overflow:visible;text-overflow:clip;line-height:1.3}

.section-header{background:#d0d0d0;font-weight:bold;text-align:left;font-size:8pt;padding:3px 4px}
.section-total td{background:#e8e8e8;font-weight:bold;font-size:8.5pt}
.grand-total td{background:#ccc;font-size:9pt;font-weight:bold}
.day-col{width:18px;min-width:16px}

@media print {
  @page{size:A4 landscape;margin:4mm 3mm}
  body{padding:0;margin:0;font-size:7pt}
  .no-print{display:none!important}
  .table-container{margin:0;overflow:visible;max-height:none}
  .report-header{margin:1mm 2mm;padding:1mm 2mm;border:1.5px solid #000}
  .report-header h1{font-size:11pt;margin:1mm 0}
  .report-header h2{font-size:8pt;margin:.5mm 0}
  .report-header p{font-size:6.5pt;margin:.5mm 0}
  .report-table thead,.report-table tfoot{position:static}
  .report-table{font-size:6pt;border-collapse:collapse}
  .report-table th{font-size:6pt;background:#ddd!important;color:#000!important;border:.4px solid #555;padding:.5mm .2mm}
  .report-table td{border:.4px solid #999;padding:.3mm .1mm;font-size:7pt;font-weight:600}
  .report-table td.qty-val{font-size:7.5pt;font-weight:700}
  .report-table tfoot th{background:#ddd!important;color:#000!important;border:.4px solid #555;padding:.5mm .2mm;font-size:6.5pt}
  /* book-name print: 2× was 5.5pt → 11pt */
  .book-name{font-size:9pt!important;width:38mm!important;max-width:38mm!important;white-space:normal!important;word-wrap:break-word!important;word-break:break-word!important;overflow:visible!important;padding:0 1px!important;line-height:1.3!important}
  .day-col{width:3.8mm;min-width:3.5mm}
  .section-header{background:#ccc!important;font-size:6.5pt;padding:.5mm 1mm}
  .section-total td,.grand-total td{background:#ddd!important;font-size:6.5pt}
}
</style>
</head>
<body>

<!-- ===== FILTER BAR ===== -->
<div class="no-print">
<form method="get" style="display:flex;flex-wrap:wrap;gap:6px;align-items:center">

  <label>Fiscal Year</label>
  <select name="year" onchange="this.form.submit()">
    <?php foreach ($fiscal_years as $fy): ?>
      <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>"
        <?= $fy['fiscal_code'] === $year ? 'selected' : '' ?>>
        <?= htmlspecialchars($fy['fiscal_name'] ?? $fy['fiscal_code']) ?>
      </option>
    <?php endforeach; ?>
  </select>

  <label>Month</label>
  <select name="month" onchange="this.form.submit()">
    <?php foreach ($nepali_months as $k => $v): ?>
      <option value="<?= $k ?>" <?= $k === $month ? 'selected' : '' ?>><?= $v ?></option>
    <?php endforeach; ?>
  </select>

  <label>Book</label>
  <select name="book_code" style="min-width:160px">
    <option value="">-- All Books --</option>
    <?php foreach ($all_books as $bk): ?>
      <option value="<?= htmlspecialchars($bk['book_code']) ?>"
        <?= $bk['book_code'] === $book_code ? 'selected' : '' ?>>
        <?= htmlspecialchars($bk['book_code']) ?> - <?= htmlspecialchars($bk['book_name']) ?> (Cl.<?= (int)$bk['class_level'] ?>)
      </option>
    <?php endforeach; ?>
  </select>

  <label>Class</label>
  <select name="class_level" style="width:70px">
    <option value="">All</option>
    <?php for ($c = 1; $c <= 12; $c++): ?>
      <option value="<?= $c ?>" <?= $class_level == $c ? 'selected' : '' ?>>Class <?= $c ?></option>
    <?php endfor; ?>
  </select>

  <label>Translation</label>
  <select name="translation">
    <option value="all"            <?= $translation === 'all'            ? 'selected' : '' ?>>All</option>
    <option value="translated"     <?= $translation === 'translated'     ? 'selected' : '' ?>>Translated</option>
    <option value="non_translated" <?= $translation === 'non_translated' ? 'selected' : '' ?>>Non-Translated</option>
  </select>

  <label>Search</label>
  <input name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Name/Code..." style="width:110px">

  <button type="submit" class="btn-apply">Apply</button>
  <button type="button" onclick="window.print()">🖨 Print</button>

</form>
</div>

<!-- ===== REPORT HEADER ===== -->
<div class="report-header">
  <h1>JANAK EDUCATION MATERIALS CENTER</h1>
  <h2>Daily Production Report</h2> 
  <h1> उत्पादन विभागबाट बजार व्यवस्था विभागमा बुझाएको पुस्तकहरुको
विवरण,  <?= $nepali_months[$month] ?> &nbsp;|&nbsp; <?= htmlspecialchars($selected_fy_name) ?></h1>
</div>

<!-- ===== TABLE ===== -->
<div class="table-container">
<table class="report-table">
<thead>
<tr>
  <th style="width:22px">SN</th>
  <th style="width:150px;max-width:150px;text-align:left;padding:3px 2px">Book</th>
  <th style="width:60px">Code</th>
  <th style="width:28px">Cl.</th>
  <?php for ($i = 1; $i <= 32; $i++) echo "<th class='day-col'>$i</th>"; ?>
  <th style="width:36px">Total</th>
</tr>
</thead>
<tbody>

<!-- TRANSLATED BOOKS -->
<tr><td colspan="37" class="section-header">TRANSLATED BOOKS</td></tr>
<?php $sn = 1; foreach ($translated as $b): ?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($b['name']) ?></td>
  <td style="font-size:7pt"><?= htmlspecialchars($b['code']) ?></td>
  <td><?= $b['class'] ?></td>
  <?php foreach ($b['days'] as $v) echo '<td class="qty-val">' . ($v ?: '-') . '</td>'; ?>
  <td><strong><?= $b['total'] ?></strong></td>
</tr>
<?php endforeach; ?>
<tr class="section-total">
  <td colspan="4" style="text-align:right;padding-right:4px">Translated Total</td>
  <?php for ($i = 1; $i <= 32; $i++) {
    $k = sprintf('%02d', $i);
    echo '<td><strong>' . ($daily_totals[$k]['translated'] ?: '-') . '</strong></td>';
  } ?>
  <td><strong><?= $translated_total ?></strong></td>
</tr>

<!-- NON-TRANSLATED BOOKS -->
<tr><td colspan="37" class="section-header">NON-TRANSLATED BOOKS</td></tr>
<?php $sn = 1; foreach ($non_translated as $b): ?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($b['name']) ?></td>
  <td style="font-size:7pt"><?= htmlspecialchars($b['code']) ?></td>
  <td><?= $b['class'] ?></td>
  <?php foreach ($b['days'] as $v) echo '<td class="qty-val">' . ($v ?: '-') . '</td>'; ?>
  <td><strong><?= $b['total'] ?></strong></td>
</tr>
<?php endforeach; ?>
<tr class="section-total">
  <td colspan="4" style="text-align:right;padding-right:4px">Non-Translated Total</td>
  <?php for ($i = 1; $i <= 32; $i++) {
    $k = sprintf('%02d', $i);
    echo '<td><strong>' . ($daily_totals[$k]['non_translated'] ?: '-') . '</strong></td>';
  } ?>
  <td><strong><?= $non_translated_total ?></strong></td>
</tr>

<!-- GRAND TOTAL -->
<tr class="grand-total">
  <td colspan="4" style="text-align:right;padding-right:4px"><strong>GRAND TOTAL</strong></td>
  <?php for ($i = 1; $i <= 32; $i++) {
    $k = sprintf('%02d', $i);
    echo '<td><strong>' . ($daily_totals[$k]['total'] ?: '-') . '</strong></td>';
  } ?>
  <td><strong><?= $grand_total ?></strong></td>
</tr>

</tbody>
<tfoot>
<tr class="daily-totals">
  <th colspan="4">Daily Totals</th>
  <?php for ($i = 1; $i <= 32; $i++):
    $k = sprintf('%02d', $i);
    $v = $daily_totals[$k]['total'];
  ?>
    <th><?= $v ?: '-' ?></th>
  <?php endfor; ?>
  <th><?= $grand_total ?></th>
</tr>
</tfoot>
</table>
</div>

<?php ob_end_flush(); ?>
</body>
</html>
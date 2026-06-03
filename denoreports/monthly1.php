<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

/* ================= FETCH FISCAL YEARS ================= */
$fy_stmt = $conn->query("SELECT fiscal_code, fiscal_name, is_active FROM fiscal_years ORDER BY id ASC");
$fiscal_years = $fy_stmt->fetchAll(PDO::FETCH_ASSOC);

$active_fy = null;
foreach ($fiscal_years as $fy) {
    if ($fy['is_active']) { $active_fy = $fy; break; }
}

/* ================= PARAMETERS ================= */
$year            = $_GET['year']             ?? ($active_fy ? $active_fy['fiscal_code'] : '2082');
$report_type     = $_GET['report_type']      ?? 'production';
$translation_filter = $_GET['translation_filter'] ?? 'all';
$selected_books  = isset($_GET['books'])  && is_array($_GET['books'])  ? $_GET['books']                        : [];
$selected_classes= isset($_GET['classes'])&& is_array($_GET['classes'])? array_map('intval',$_GET['classes'])  : [];
$search_text     = trim($_GET['search_text'] ?? '');

/*
 * Nepali fiscal year runs Shrawan(04) of year X  →  Asar(03) of year X+1.
 * So fiscal_code "2082" means 2082-Shr … 2082-Chai, 2083-Bai … 2083-Asa.
 * We need two numeric years: base year and base+1.
 */
$base_year  = (int)$year;          // e.g. 2082
$next_year  = $base_year + 1;      // e.g. 2083

/*
 * Month order for a Nepali fiscal year:
 *  Slot 1 = Shrawan   (04) of base_year
 *  Slot 2 = Bhadra    (05) of base_year
 *  ...
 *  Slot 9  = Poush    (09) of base_year    -- wait, Poush is 09? No:
 *
 * Nepali calendar month numbers:
 *  01-Baisakh 02-Jestha 03-Asar 04-Shrawan 05-Bhadra 06-Ashoj
 *  07-Kartik  08-Mangsir 09-Poush 10-Magh 11-Falgun 12-Chaitra
 *
 * Fiscal year order (Shrawan first):
 *  04-Shr, 05-Bha, 06-Ash, 07-Kar, 08-Man, 09-Pou  → base_year
 *  10-Mag, 11-Fal, 12-Cha, 01-Bai, 02-Jes, 03-Asa  → next_year (for months 01-03)
 */
$fiscal_months = [
    // [ display_label, deno_year, month_num_string ]
    ['Shr', $base_year, '04'],
    ['Bha', $base_year, '05'],
    ['Ash', $base_year, '06'],
    ['Kar', $base_year, '07'],
    ['Man', $base_year, '08'],
    ['Pou', $base_year, '09'],
    ['Mag', $base_year, '10'],
    ['Fal', $base_year, '11'],
    ['Cha', $base_year, '12'],
    ['Bai', $next_year, '01'],
    ['Jes', $next_year, '02'],
    ['Asa', $next_year, '03'],
];

$fiscal_month_full = [
    '04'=>'Shrawan','05'=>'Bhadra','06'=>'Ashoj','07'=>'Kartik',
    '08'=>'Mangsir','09'=>'Poush','10'=>'Magh','11'=>'Falgun',
    '12'=>'Chaitra','01'=>'Baisakh','02'=>'Jestha','03'=>'Asar'
];

/* ================= BOOKS FOR DROPDOWN ================= */
$books_sql  = "SELECT book_code, book_name, class_level, is_translated FROM Books ORDER BY class_level, book_name";
$books_stmt = $conn->prepare($books_sql);
$books_stmt->execute();
$all_books  = $books_stmt->fetchAll(PDO::FETCH_ASSOC);

$classes_sql  = "SELECT DISTINCT class_level FROM Books ORDER BY class_level";
$classes_stmt = $conn->prepare($classes_sql);
$classes_stmt->execute();
$all_classes  = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);

/* ================= QUERY ================= */
$where_conditions = [];
$params = [':fiscal_year' => (string)$year]; // fiscal_year column matches e.g. '2082' for all 12 months of that FY

if ($translation_filter === 'translated')     $where_conditions[] = "b.is_translated = TRUE";
if ($translation_filter === 'non_translated') $where_conditions[] = "b.is_translated = FALSE";

if (!empty($selected_books)) {
    $phs = [];
    foreach ($selected_books as $i => $bc) { $phs[] = ":bk$i"; $params[":bk$i"] = $bc; }
    $where_conditions[] = "b.book_code IN (" . implode(',', $phs) . ")";
}
if (!empty($selected_classes)) {
    $phs = [];
    foreach ($selected_classes as $i => $cl) { $phs[] = ":cl$i"; $params[":cl$i"] = (int)$cl; }
    $where_conditions[] = "b.class_level IN (" . implode(',', $phs) . ")";
}
if (!empty($search_text)) {
    $where_conditions[] = "(b.book_name ILIKE :search OR b.book_code ILIKE :search)";
    $params[':search'] = '%' . $search_text . '%';
}

$extra_where = $where_conditions ? 'AND ' . implode(' AND ', $where_conditions) : '';

/*
 * KEY INSIGHT from schema:
 *   - fiscal_year (varchar/enum) stores the fiscal year e.g. '2082' for ALL records in that FY
 *   - deno_year (varchar) stores the Nepali calendar year of the actual date e.g. '2082' or '2083'
 *   - deno_date_nep format: 'YYYY.MM.DD'
 *
 * So for fiscal year 2082:
 *   - ALL 12 months have fiscal_year = '2082'
 *   - Shrawan-Chaitra months have deno_year='2082', month 04-12
 *   - Baisakh-Asar months have deno_year='2083', month 01-03
 *
 * We filter by fiscal_year = :fiscal_year and extract month from deno_date_nep.
 * Then we build slot key as: deno_year + '-' + month_num (e.g. '2082-04', '2083-01')
 */

$sql = "
SELECT
    b.book_name,
    b.book_code,
    b.class_level,
    b.is_translated,
    sub.deno_year,
    sub.month_num,
    COALESCE(SUM(sub.total_qty), 0)        AS total_produced,
    COALESCE(SUM(sub.quantity_openpcs), 0) AS total_openpcs
FROM Books b
LEFT JOIN (
    SELECT
        d.book_code,
        d.total_qty,
        d.quantity_openpcs,
        d.deno_year,
        LPAD(SPLIT_PART(d.deno_date_nep, '.', 2), 2, '0') AS month_num
    FROM Deno d
    WHERE d.deleted_at IS NULL
      AND d.fiscal_year::varchar = :fiscal_year
) sub ON b.book_code = sub.book_code
WHERE 1=1 $extra_where
GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, sub.deno_year, sub.month_num
ORDER BY b.is_translated DESC, b.class_level, b.book_name
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

/* ================= PROCESS ================= */
// Key for each slot: "YYYY-MM"
$slot_keys = [];
foreach ($fiscal_months as $fm) {
    $slot_keys[] = $fm[1] . '-' . $fm[2]; // e.g. "2082-04"
}

$books_data      = [];
$slot_totals     = array_fill_keys($slot_keys, ['produced'=>0,'openpcs'=>0]);
$grand_produced  = 0;
$grand_openpcs   = 0;

foreach ($raw_data as $row) {
    $bk = $row['book_code'];
    if (!isset($books_data[$bk])) {
        $books_data[$bk] = [
            'book_name'    => $row['book_name'],
            'book_code'    => $bk,
            'class_level'  => $row['class_level'],
            'is_translated'=> $row['is_translated'],
            'slots'        => array_fill_keys($slot_keys, ['produced'=>0,'openpcs'=>0]),
            'total_produced'=> 0,
            'total_openpcs' => 0,
        ];
    }

    if (!empty($row['month_num']) && !empty($row['deno_year'])) {
        $slot = $row['deno_year'] . '-' . $row['month_num'];
        if (isset($books_data[$bk]['slots'][$slot])) {
            $prod = (int)$row['total_produced'];
            $open = (int)$row['total_openpcs'];
            $books_data[$bk]['slots'][$slot]['produced'] += $prod;
            $books_data[$bk]['slots'][$slot]['openpcs']  += $open;
            $books_data[$bk]['total_produced'] += $prod;
            $books_data[$bk]['total_openpcs']  += $open;
            $slot_totals[$slot]['produced'] += $prod;
            $slot_totals[$slot]['openpcs']  += $open;
            $grand_produced += $prod;
            $grand_openpcs  += $open;
        }
    }
}

$grand_net = $grand_produced - $grand_openpcs;

// Separate translated / non-translated
$translated_books     = array_filter($books_data, fn($b) => $b['is_translated']);
$non_translated_books = array_filter($books_data, fn($b) => !$b['is_translated']);

// Section totals
function section_totals($books, $slot_keys) {
    $t = array_fill_keys($slot_keys, ['produced'=>0,'openpcs'=>0]);
    $tot = ['produced'=>0,'openpcs'=>0];
    foreach ($books as $b) {
        foreach ($slot_keys as $sk) {
            $t[$sk]['produced'] += $b['slots'][$sk]['produced'];
            $t[$sk]['openpcs']  += $b['slots'][$sk]['openpcs'];
        }
        $tot['produced'] += $b['total_produced'];
        $tot['openpcs']  += $b['total_openpcs'];
    }
    return ['slots'=>$t, 'total'=>$tot];
}
$t_totals  = section_totals($translated_books,     $slot_keys);
$nt_totals = section_totals($non_translated_books, $slot_keys);

// Fiscal name for display
$selected_fy_name = $year;
foreach ($fiscal_years as $fy) {
    if ($fy['fiscal_code'] === $year) { $selected_fy_name = $fy['fiscal_name'] ?? $year; break; }
}

/* ================= HELPER: get display value ================= */
function cell_val($book_or_slot, $report_type, $is_slot = true) {
    if ($is_slot) {
        return $report_type === 'openpcs' ? $book_or_slot['openpcs'] : $book_or_slot['produced'];
    } else {
        return $report_type === 'openpcs' ? $book_or_slot['total_openpcs'] : $book_or_slot['total_produced'];
    }
}

/* ================= EXPORT ================= */
if (isset($_GET['export']) && in_array($_GET['export'], ['csv','excel'])) {
    $filename = 'monthly_report_' . $year . '_' . date('Y-m-d_H-i-s');

    // Build header row
    $col_headers = ['SN','Book Name','Code','Class','Type'];
    foreach ($fiscal_months as $fm) {
        $col_headers[] = $fm[0]; // abbrev label e.g. Shr
    }
    $col_headers[] = 'Total';

    // Build data rows
    $rows = [];
    foreach ($books_data as $book) {
        $row = [
            '', // SN filled below
            $book['book_name'],
            $book['book_code'],
            $book['class_level'],
            $book['is_translated'] ? 'T' : 'NT',
        ];
        foreach ($fiscal_months as $fm) {
            $sk = $fm[1] . '-' . $fm[2];
            $row[] = cell_val($book['slots'][$sk], $report_type);
        }
        $row[] = cell_val($book, $report_type, false);
        $rows[] = $row;
    }
    // Numbering
    $sn = 1;
    foreach ($rows as &$r) { $r[0] = $sn++; }
    unset($r);

    // Totals row
    $total_row = ['', 'GRAND TOTAL', '', '', ''];
    foreach ($fiscal_months as $fm) {
        $sk = $fm[1] . '-' . $fm[2];
        $total_row[] = $report_type === 'openpcs' ? $slot_totals[$sk]['openpcs'] : $slot_totals[$sk]['produced'];
    }
    $total_row[] = $report_type === 'openpcs' ? $grand_openpcs : $grand_produced;

    if ($_GET['export'] === 'csv') {
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        $out = fopen('php://output', 'w');
        fprintf($out, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM
        fputcsv($out, $col_headers);
        foreach ($rows as $r) fputcsv($out, $r);
        fputcsv($out, $total_row);
        fclose($out);
        exit;
    }

    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        echo '<?xml version="1.0" encoding="UTF-8"?>';
        echo '<html><head><meta charset="UTF-8"><style>';
        echo 'body{font-family:Arial;font-size:8pt}';
        echo 'table{border-collapse:collapse;width:100%}';
        echo 'th,td{border:1px solid #000;padding:2px 4px;text-align:center}';
        echo 'th{background:#d0d0d0;font-weight:bold}';
        echo '.left{text-align:left}.bold{font-weight:bold}.total-row{background:#c0c0c0}';
        echo '</style></head><body><table>';
        echo '<tr>';
        foreach ($col_headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr>';
        foreach ($rows as $r) {
            echo '<tr>';
            foreach ($r as $i => $v) {
                $cls = ($i === 1) ? ' class="left"' : '';
                echo '<td' . $cls . '>' . htmlspecialchars((string)$v) . '</td>';
            }
            echo '</tr>';
        }
        echo '<tr class="total-row">';
        foreach ($total_row as $i => $v) {
            $cls = ($i === 1) ? ' class="left bold"' : ' class="bold"';
            echo '<td' . $cls . '>' . htmlspecialchars((string)$v) . '</td>';
        }
        echo '</tr>';
        echo '</table></body></html>';
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Monthly Production Report</title>
<style>
*{box-sizing:border-box}
body{font-family:'Segoe UI',Arial,sans-serif;background:#fff;margin:0;padding:0;font-size:9pt}

/* ---- FILTER BAR ---- */
.no-print{padding:6px 10px;background:#f0f0f0;border-bottom:2px solid #bbb;display:flex;flex-wrap:wrap;gap:6px;align-items:center}
.no-print label{font-size:8pt;font-weight:600;margin-right:2px}
.no-print select,.no-print input{font-size:8pt;padding:2px 4px;border:1px solid #aaa;border-radius:3px;height:24px}
.no-print select[multiple]{height:80px}
.no-print button{font-size:8pt;padding:2px 10px;height:24px;cursor:pointer;border-radius:3px;border:1px solid #888;background:#fff}
.no-print button.btn-apply{background:#336;color:#fff;border-color:#336}
.no-print button.btn-clear{background:#a00;color:#fff;border-color:#a00}
.no-print a.btn-dl{font-size:8pt;padding:2px 10px;height:24px;cursor:pointer;border-radius:3px;border:1px solid #888;background:#1a7a1a;color:#fff;text-decoration:none;display:inline-flex;align-items:center}
.no-print a.btn-dl-xl{background:#17527a}

/* ---- REPORT HEADER ---- */
.report-header{text-align:center;border:2px solid #333;padding:5px 10px;margin:6px 10px 4px;background:#fff}
.report-header h1{margin:2px 0;font-size:14pt;letter-spacing:1px}
.report-header h2{margin:2px 0;font-size:10pt;font-weight:600}
.report-header p{margin:2px 0;font-size:8pt}

/* ---- TABLE ---- */
.table-container{overflow-x:auto;margin:0 4px;max-height:calc(100vh - 180px);overflow-y:auto}
.report-table{width:100%;border-collapse:collapse;font-size:8pt}
.report-table thead{position:sticky;top:-1px;z-index:80;background:#333}
.report-table th{background:#333;color:#fff;border:1px solid #555;padding:3px 2px;text-align:center;font-weight:bold;font-size:8pt}
.report-table tfoot{position:sticky;bottom:0;z-index:80}
.report-table tfoot th{background:#4a4a4a;color:#fff;border:1px solid #555;padding:3px 2px;font-weight:bold;font-size:8pt}
.report-table td{border:1px solid #aaa;padding:2px 2px;text-align:center;background:#fff;font-size:8.5pt;font-weight:500}
.report-table td.qty-val{font-size:9pt;font-weight:700;color:#111}
.book-name{text-align:left;max-width:120px;width:120px;word-wrap:break-word;word-break:break-word;font-size:8pt;padding:1px 2px;white-space:normal;line-height:1.3}
.section-header{background:#d0d0d0;font-weight:bold;text-align:left;font-size:8pt;padding:3px 6px}
.section-total td{background:#e0e0e0;font-weight:bold;font-size:8.5pt}
.grand-total td{background:#b8b8b8;font-size:9pt;font-weight:bold}

/* ---- PRINT ---- */
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
  .book-name{font-size:5.5pt!important;width:22mm!important;max-width:22mm!important;word-break:break-word!important;line-height:1.2!important}
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
      <select name="year" onchange="this.form.submit()">
        <?php foreach ($fiscal_years as $fy): ?>
          <option value="<?= htmlspecialchars($fy['fiscal_code']) ?>"
            <?= $fy['fiscal_code'] === $year ? 'selected' : '' ?>>
            <?= htmlspecialchars($fy['fiscal_name'] ?? $fy['fiscal_code']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;flex-direction:column;gap:2px">
      <label>Report Type</label>
      <select name="report_type" onchange="this.form.submit()">
        <option value="production" <?= $report_type==='production'?'selected':'' ?>>Total Production</option>
        <option value="openpcs"    <?= $report_type==='openpcs'   ?'selected':'' ?>>Open Pcs (Defective)</option>
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
          <option value="<?= $cl ?>" <?= in_array($cl,$selected_classes)?'selected':'' ?>>Class <?= $cl ?></option>
        <?php endforeach; ?>
      </select>
    </div>

    <div style="display:flex;flex-direction:column;gap:2px">
      <label>Search</label>
      <input type="text" name="search_text" value="<?= htmlspecialchars($search_text) ?>" placeholder="Name or Code...">
    </div>

    <div style="display:flex;align-items:flex-end;gap:4px;padding-bottom:1px">
      <button type="submit" class="btn-apply">Apply</button>
      <button type="button" class="btn-clear" onclick="location.href='<?= $_SERVER['PHP_SELF']?>?year=<?= $year ?>'">Clear</button>
      <button type="button" onclick="window.print()">🖨 Print</button>
      <a class="btn-dl"
         href="?<?= http_build_query(array_merge($_GET,['export'=>'csv'])) ?>">⬇ CSV</a>
      <a class="btn-dl btn-dl-xl"
         href="?<?= http_build_query(array_merge($_GET,['export'=>'excel'])) ?>">⬇ Excel</a>
    </div>

  </form>
</div>

<!-- ===== REPORT HEADER ===== -->
<div class="report-header">
  <h1>JANAK EDUCATION MATERIALS CENTER</h1>
  <h2>Monthly Production Summary Report</h2>
  <p>Fiscal Year: <?= htmlspecialchars($selected_fy_name) ?> &nbsp;|&nbsp;
     Type: <?= ucfirst($report_type) ?>
     <?php if ($translation_filter!=='all') echo ' &nbsp;|&nbsp; ' . ucwords(str_replace('_',' ',$translation_filter)); ?>
     <?php if (!empty($search_text))        echo ' &nbsp;|&nbsp; Search: "' . htmlspecialchars($search_text) . '"'; ?>
  </p>
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
    <?php
    // Year-group headers: base_year months, then next_year months
    $base_count = 0; $next_count = 0;
    foreach ($fiscal_months as $fm) {
        if ($fm[1] == $base_year) $base_count++;
        else                      $next_count++;
    }
    ?>
    <th colspan="<?= $base_count ?>">
      <?= $base_year ?>
    </th>
    <th colspan="<?= $next_count ?>">
      <?= $next_year ?>
    </th>
    <th rowspan="2" style="width:40px">Total</th>
  </tr>
  <tr>
    <?php foreach ($fiscal_months as $fm): ?>
      <th style="width:30px"><?= $fm[0] ?></th>
    <?php endforeach; ?>
  </tr>
</thead>
<tbody>

<?php
/* ---------- TRANSLATED ---------- */
?>
<tr><td colspan="<?= 4 + count($fiscal_months) + 1 ?>" class="section-header">TRANSLATED BOOKS</td></tr>
<?php
$sn = 1;
foreach ($translated_books as $book):
?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($book['book_name']) ?></td>
  <td style="font-size:7pt"><?= htmlspecialchars($book['book_code']) ?></td>
  <td><?= $book['class_level'] ?></td>
  <?php foreach ($fiscal_months as $fm):
    $sk = $fm[1] . '-' . $fm[2];
    $v  = cell_val($book['slots'][$sk], $report_type);
  ?>
    <td class="qty-val"><?= $v ? $v : '-' ?></td>
  <?php endforeach; ?>
  <td class="qty-val"><strong><?= cell_val($book, $report_type, false) ?></strong></td>
</tr>
<?php endforeach; ?>

<tr class="section-total">
  <td colspan="4" style="text-align:right;padding-right:6px">Translated Total</td>
  <?php foreach ($fiscal_months as $fm):
    $sk = $fm[1] . '-' . $fm[2];
    $v  = $report_type==='openpcs' ? $t_totals['slots'][$sk]['openpcs'] : $t_totals['slots'][$sk]['produced'];
  ?>
    <td><strong><?= $v ?: '-' ?></strong></td>
  <?php endforeach; ?>
  <td><strong><?= $report_type==='openpcs' ? $t_totals['total']['openpcs'] : $t_totals['total']['produced'] ?></strong></td>
</tr>

<?php
/* ---------- NON-TRANSLATED ---------- */
?>
<tr><td colspan="<?= 4 + count($fiscal_months) + 1 ?>" class="section-header">NON-TRANSLATED BOOKS</td></tr>
<?php
$sn = 1;
foreach ($non_translated_books as $book):
?>
<tr>
  <td><?= $sn++ ?></td>
  <td class="book-name"><?= htmlspecialchars($book['book_name']) ?></td>
  <td style="font-size:7pt"><?= htmlspecialchars($book['book_code']) ?></td>
  <td><?= $book['class_level'] ?></td>
  <?php foreach ($fiscal_months as $fm):
    $sk = $fm[1] . '-' . $fm[2];
    $v  = cell_val($book['slots'][$sk], $report_type);
  ?>
    <td class="qty-val"><?= $v ? $v : '-' ?></td>
  <?php endforeach; ?>
  <td class="qty-val"><strong><?= cell_val($book, $report_type, false) ?></strong></td>
</tr>
<?php endforeach; ?>

<tr class="section-total">
  <td colspan="4" style="text-align:right;padding-right:6px">Non-Translated Total</td>
  <?php foreach ($fiscal_months as $fm):
    $sk = $fm[1] . '-' . $fm[2];
    $v  = $report_type==='openpcs' ? $nt_totals['slots'][$sk]['openpcs'] : $nt_totals['slots'][$sk]['produced'];
  ?>
    <td><strong><?= $v ?: '-' ?></strong></td>
  <?php endforeach; ?>
  <td><strong><?= $report_type==='openpcs' ? $nt_totals['total']['openpcs'] : $nt_totals['total']['produced'] ?></strong></td>
</tr>

<?php /* ---------- GRAND TOTAL ---------- */ ?>
<tr class="grand-total">
  <td colspan="4" style="text-align:right;padding-right:6px"><strong>GRAND TOTAL</strong></td>
  <?php foreach ($fiscal_months as $fm):
    $sk = $fm[1] . '-' . $fm[2];
    $v  = $report_type==='openpcs' ? $slot_totals[$sk]['openpcs'] : $slot_totals[$sk]['produced'];
  ?>
    <td><strong><?= $v ?: '-' ?></strong></td>
  <?php endforeach; ?>
  <td><strong><?= $report_type==='openpcs' ? $grand_openpcs : $grand_produced ?></strong></td>
</tr>

</tbody>
<tfoot>
<tr>
  <th colspan="4">Monthly Totals</th>
  <?php foreach ($fiscal_months as $fm):
    $sk = $fm[1] . '-' . $fm[2];
    $v  = $report_type==='openpcs' ? $slot_totals[$sk]['openpcs'] : $slot_totals[$sk]['produced'];
  ?>
    <th><?= $v ?: '-' ?></th>
  <?php endforeach; ?>
  <th><?= $report_type==='openpcs' ? $grand_openpcs : $grand_produced ?></th>
</tr>
</tfoot>
</table>
</div>

<?php ob_end_flush(); ?>
</body>
</html>
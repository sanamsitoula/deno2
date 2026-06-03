<?php
// Start output buffering early to allow clean CSV/Excel export
ob_start();

// Handle export requests first (before any HTML output)
if (isset($_GET['export'])) {
    if (ob_get_length()) ob_clean();

    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

    // Input sanitization and defaults
    $year = $_GET['year'] ?? '2082';
    $month = $_GET['month'] ?? '05';
    $report_type = $_GET['report_type'] ?? 'production';
    $translation = $_GET['translation'] ?? 'all';
    $search = trim($_GET['search'] ?? '');
    $book_code = trim($_GET['book_code'] ?? '');
    $class_level = trim($_GET['class_level'] ?? '');
    $selected_books = isset($_GET['books']) && is_array($_GET['books']) ? $_GET['books'] : [];
    $selected_classes = isset($_GET['classes']) && is_array($_GET['classes']) ? array_map('intval', $_GET['classes']) : [];

    // Nepali months mapping
    $nepali_months = [
        '01' => 'Baisakh', '02' => 'Jestha', '03' => 'Ashad', '04' => 'Shrawan',
        '05' => 'Bhadra', '06' => 'Ashoj', '07' => 'Kartik', '08' => 'Mangsir',
        '09' => 'Poush', '10' => 'Magh', '11' => 'Falgun', '12' => 'Chaitra'
    ];

    // Build dynamic WHERE clause
    $where = [];
    $params = [':year' => $year, ':month' => $month];

    if ($translation === 'translated') {
        $where[] = 'b.is_translated = TRUE';
    } elseif ($translation === 'non_translated') {
        $where[] = 'b.is_translated = FALSE';
    }

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

    if (!empty($selected_books)) {
        $book_placeholders = [];
        foreach ($selected_books as $index => $code) {
            $ph = ":book_code_$index";
            $book_placeholders[] = $ph;
            $params[$ph] = $code;
        }
        $where[] = "b.book_code IN (" . implode(',', $book_placeholders) . ")";
    }

    if (!empty($selected_classes)) {
        $class_placeholders = [];
        foreach ($selected_classes as $index => $level) {
            $ph = ":class_$index";
            $class_placeholders[] = $ph;
            $params[$ph] = $level;
        }
        $where[] = "b.class_level IN (" . implode(',', $class_placeholders) . ")";
    }

    $where_sql = $where ? 'AND ' . implode(' AND ', $where) : '';

    // Main query: LEFT JOIN to include books even with no production
    $sql = "
        SELECT 
            b.book_name,
            b.book_code,
            b.class_level,
            b.is_translated,
            SUBSTRING(d.deno_date_nep, 9, 2) AS day,
            COALESCE(SUM(d.total_qty), 0) AS total_produced,
            COALESCE(SUM(d.quantity_openpcs), 0) AS total_openpcs
        FROM Books b
        LEFT JOIN Deno d 
            ON b.book_code = d.book_code 
            AND d.deno_year = :year 
            AND SUBSTRING(d.deno_date_nep, 6, 2) = :month
        $where_sql
        GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, day
        ORDER BY b.is_translated DESC, b.class_level, b.book_name, day
    ";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Reorganize data by book and day
    $translated = [];
    $non_translated = [];



    foreach ($data as $r) {
    if ($r['is_translated']) {
        $target = &$translated;
    } else {
        $target = &$non_translated;
    }
    $code = $r['book_code'];

    if (!isset($target[$code])) {
        $days = [];
        for ($i = 1; $i <= 32; $i++) {
            $days[sprintf('%02d', $i)] = 0;
        }
        $target[$code] = [
            'name' => $r['book_name'],
            'code' => $code,
            'class' => $r['class_level'],
            'days' => $days,
            'total' => 0
        ];
    }

    if (!empty($r['day'])) {
        $val = ($report_type === 'openpcs') ? (int)$r['total_openpcs'] : (int)$r['total_produced'];
        $target[$code]['days'][$r['day']] = $val;
        $target[$code]['total'] += $val;

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


    // Generate filename
    $filename = "daily_report_{$nepali_months[$month]}_{$year}_" . date('Ymd_His');

    if ($_GET['export'] === 'csv') {
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$filename.csv\"");

        $output = fopen('php://output', 'w');
        // BOM for Excel UTF-8 compatibility
        fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

        fputcsv($output, ['Janak Education Materials Center']);
        fputcsv($output, ['Daily Production Report']);
        fputcsv($output, ["{$nepali_months[$month]} {$year}"]);
        fputcsv($output, []);

        // Headers
        $headers = ['SN', 'Book Name', 'Code', 'Class'];
        for ($i = 1; $i <= 32; $i++) {
            $headers[] = "Day $i";
        }
        $headers[] = 'Total';
        fputcsv($output, $headers);

        // Translated books
        if ($translated) {
            fputcsv($output, ['TRANSLATED BOOKS']);
            $sn = 1;
            foreach ($translated as $book) {
                $row = [$sn++, $book['name'], $book['code'], $book['class']];
                for ($i = 1; $i <= 32; $i++) {
                    $row[] = $book['days'][sprintf('%02d', $i)] ?: 0;
                }
                $row[] = $book['total'];
                fputcsv($output, $row);
            }
        }

        // Non-translated books
        if ($non_translated) {
            fputcsv($output, ['NON-TRANSLATED BOOKS']);
            $sn = 1;
            foreach ($non_translated as $book) {
                $row = [$sn++, $book['name'], $book['code'], $book['class']];
                for ($i = 1; $i <= 32; $i++) {
                    $row[] = $book['days'][sprintf('%02d', $i)] ?: 0;
                }
                $row[] = $book['total'];
                fputcsv($output, $row);
            }
        }

        fclose($output);
    } else {
        // Excel (.xls) - HTML-based
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$filename.xls\"");

        echo '<html><head><meta charset="utf-8"></head><body><table border="1">';
        echo '<tr><td colspan="37" style="text-align:center;font-weight:bold;">Janak Education Materials Center</td></tr>';
        echo '<tr><td colspan="37" style="text-align:center;font-weight:bold;">Daily Production Report</td></tr>';
        echo '<tr><td colspan="37" style="text-align:center;">' . htmlspecialchars("{$nepali_months[$month]} {$year}") . '</td></tr>';
        echo '<tr><th>SN</th><th>Book Name</th><th>Code</th><th>Class</th>';
        for ($i = 1; $i <= 32; $i++) echo "<th>$i</th>";
        echo '<th>Total</th></tr>';

        if ($translated) {
            echo '<tr><td colspan="37" style="background:#4CAF50;color:white;font-weight:bold;">TRANSLATED BOOKS</td></tr>';
            $sn = 1;
            foreach ($translated as $b) {
                echo '<tr><td>' . $sn++ . '</td><td>' . htmlspecialchars($b['name']) . '</td><td>' . $b['code'] . '</td><td>' . $b['class'] . '</td>';
                for ($i = 1; $i <= 32; $i++) {
                    echo '<td>' . ($b['days'][sprintf('%02d', $i)] ?: '-') . '</td>';
                }
                echo '<td><b>' . $b['total'] . '</b></td></tr>';
            }
        }

        if ($non_translated) {
            echo '<tr><td colspan="37" style="background:#f44336;color:white;font-weight:bold;">NON-TRANSLATED BOOKS</td></tr>';
            $sn = 1;
            foreach ($non_translated as $b) {
                echo '<tr><td>' . $sn++ . '</td><td>' . htmlspecialchars($b['name']) . '</td><td>' . $b['code'] . '</td><td>' . $b['class'] . '</td>';
                for ($i = 1; $i <= 32; $i++) {
                    echo '<td>' . ($b['days'][sprintf('%02d', $i)] ?: '-') . '</td>';
                }
                echo '<td><b>' . $b['total'] . '</b></td></tr>';
            }
        }

        echo '</table></body></html>';
    }

    exit;
}

// If not exporting, clean buffer and proceed to render HTML
if (ob_get_length()) ob_end_clean();

// Regular page load
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Same input handling as above (you could refactor into a function)
$year = $_GET['year'] ?? '2082';
$month = $_GET['month'] ?? '05';
$report_type = $_GET['report_type'] ?? 'production';
$translation = $_GET['translation'] ?? 'all';
$search = trim($_GET['search'] ?? '');
$book_code = trim($_GET['book_code'] ?? '');
$class_level = trim($_GET['class_level'] ?? '');
$selected_books = isset($_GET['books']) && is_array($_GET['books']) ? $_GET['books'] : [];
$selected_classes = isset($_GET['classes']) && is_array($_GET['classes']) ? array_map('intval', $_GET['classes']) : [];

$nepali_months = [
    '01' => 'Baisakh', '02' => 'Jestha', '03' => 'Ashad', '04' => 'Shrawan',
    '05' => 'Bhadra', '06' => 'Ashoj', '07' => 'Kartik', '08' => 'Mangsir',
    '09' => 'Poush', '10' => 'Magh', '11' => 'Falgun', '12' => 'Chaitra'
];

$where = [];
$params = [':year' => $year, ':month' => $month];

if ($translation === 'translated') $where[] = 'b.is_translated = TRUE';
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
if (!empty($selected_books)) {
    $book_placeholders = [];
    foreach ($selected_books as $index => $code) {
        $ph = ":book_code_$index";
        $book_placeholders[] = $ph;
        $params[$ph] = $code;
    }
    $where[] = "b.book_code IN (" . implode(',', $book_placeholders) . ")";
}
if (!empty($selected_classes)) {
    $class_placeholders = [];
    foreach ($selected_classes as $index => $level) {
        $ph = ":class_$index";
        $class_placeholders[] = $ph;
        $params[$ph] = $level;
    }
    $where[] = "b.class_level IN (" . implode(',', $class_placeholders) . ")";
}

$where_sql = $where ? 'AND ' . implode(' AND ', $where) : '';

$sql = "
    SELECT 
        b.book_name,
        b.book_code,
        b.class_level,
        b.is_translated,
        SUBSTRING(d.deno_date_nep, 9, 2) AS day,
        COALESCE(SUM(d.total_qty), 0) AS total_produced,
        COALESCE(SUM(d.quantity_openpcs), 0) AS total_openpcs
    FROM Books b
    LEFT JOIN Deno d 
        ON b.book_code = d.book_code 
        AND d.deno_year = :year 
        AND SUBSTRING(d.deno_date_nep, 6, 2) = :month
    $where_sql
    GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, day
    ORDER BY b.is_translated DESC, b.class_level, b.book_name, day
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch books and classes for filters
$books_stmt = $conn->prepare("SELECT book_code, book_name, class_level, is_translated FROM Books ORDER BY is_translated DESC, class_level, book_name");
$books_stmt->execute();
$all_books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);

$classes_stmt = $conn->prepare("SELECT DISTINCT class_level FROM Books ORDER BY class_level");
$classes_stmt->execute();
$all_classes = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);

// Reorganize data with totals
$translated = [];
$non_translated = [];
$daily_totals = [];
$translated_total = 0;
$non_translated_total = 0;

for ($i = 1; $i <= 32; $i++) {
    $k = sprintf('%02d', $i);
    $daily_totals[$k] = ['translated' => 0, 'non_translated' => 0, 'total' => 0];
}

foreach ($data as $r) {
    $is_translated = (bool) $r['is_translated'];

        if ($r['is_translated']) {
        $target = &$translated;
    } else {
        $target = &$non_translated;
    }

  
    $code = $r['book_code'];

    if (!isset($target[$code])) {
        $days = [];
        for ($i = 1; $i <= 32; $i++) {
            $days[sprintf('%02d', $i)] = 0;
        }
        $target[$code] = [
            'name' => $r['book_name'],
            'code' => $code,
            'class' => $r['class_level'],
            'days' => $days,
            'total' => 0
        ];
    }

    if (!empty($r['day'])) {
        $val = ($report_type === 'openpcs') ? (int)$r['total_openpcs'] : (int)$r['total_produced'];
        $target[$code]['days'][$r['day']] = $val;
        $target[$code]['total'] += $val;

        if ($is_translated) {
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
<html>
<head>
    <meta charset="UTF-8">
    <title>Daily Production Report</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet"/>
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet"/>
    <style>
        body { font-family: Arial; background: #fff; padding: 10px; }
        .no-print { display: block; }
        .print-only { display: none; }
        .report-header { text-align: center; border: 2px solid #333; padding: 10px; margin-bottom: 15px; }
        .report-table { width: 100%; border-collapse: collapse; font-size: 8pt; }
        .report-table th, .report-table td { border: 1px solid #333; padding: 4px 2px; text-align: center; }
        .report-table th { background: #e0e0e0; }
        .book-name { text-align: left; max-width: 120px; word-wrap: break-word; }
        .section-header { background: #d0d0d0; font-weight: bold; text-align: left; }
        .section-total, .grand-total { background: #e8e8e8; font-weight: bold; }
        .page-container { max-width: 100%; margin: 20px auto; background: #fff; padding: 20px; }
        .filter-section { background: #ecf0f1; padding: 20px; border-radius: 8px; margin-bottom: 20px; }
        .filter-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin-bottom: 15px; }
        .filter-group label { display: block; font-weight: 600; color: #2c3e50; margin-bottom: 5px; font-size: 13px; }
        .filter-group select, .filter-group input { width: 100%; padding: 10px; border: 1px solid #bdc3c7; border-radius: 6px; background: #fff; font-size: 14px; }
        .filter-actions { display: flex; gap: 10px; flex-wrap: wrap; }
        .btn { padding: 10px 20px; border: none; border-radius: 6px; font-weight: 600; cursor: pointer; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; font-size: 14px; }
        .btn-primary { background: #3498db; color: #fff; }
        .btn-secondary { background: #95a5a6; color: #fff; }
        .btn-success { background: #27ae60; color: #fff; }
        .search-container { position: relative; }
        .search-dropdown { position: absolute; top: 100%; left: 0; right: 0; background: #fff; border: 1px solid #bdc3c7; border-top: none; border-radius: 0 0 6px 6px; max-height: 200px; overflow-y: auto; z-index: 1000; display: none; }
        .search-dropdown.show { display: block; }
        .search-option { padding: 10px; cursor: pointer; border-bottom: 1px solid #ecf0f1; }
        .search-option:hover { background: #ecf0f1; }
        .active-filters { background: #fff3cd; padding: 15px; border-radius: 6px; margin-bottom: 15px; display: flex; flex-wrap: wrap; gap: 10px; align-items: center; }
        .filter-tag { background: #ffc107; padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: 500; }
        .summary-cards { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 15px; margin: 20px 0; }
        .summary-card { padding: 15px; background: #fff; border-radius: 8px; box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center; }
        .summary-card .label { font-size: 13px; color: #7f8c8d; margin-bottom: 5px; }
        .summary-card .value { font-size: 28px; font-weight: bold; color: #3498db; }
        .export-section { background: #e8f5ff; padding: 15px; border-radius: 8px; margin: 15px 0; display: flex; gap: 10px; flex-wrap: wrap; }

        @media print {
            @page { size: A4 landscape; margin: 3mm; }
            body { padding: 0; margin: 0; }
            .no-print { display: none !important; }
            .print-only { display: block; }
            .report-header { margin-bottom: 2mm; padding: 1.5mm; }
            .report-header h1 { font-size: 13pt; }
            .report-header h2 { font-size: 10pt; }
            .report-header p { font-size: 7pt; }
            .report-table { font-size: 5.5pt; }
            .report-table th, .report-table td { padding: .3mm .2mm; font-size: 5.5pt; border: .5px solid #333; }
            .report-table th { font-size: 6pt; }
            .book-name { font-size: 5pt; max-width: 20mm; }
            .day-col { width: 4mm; font-size: 5.5pt; }
                /* Remove padding/margin only from first 4 columns in print */
.report-table th:nth-child(-n+4),
.report-table td:nth-child(-n+4) {
    padding: 0 !important;
    margin: 0 !important;
    border: 0.5pt solid #333 !important;
    font-size: 5.5pt !important;
}

        }
    </style>
</head>
<body>
<div class="page-container">
    <div class="no-print">
        <h2 style="text-align:center; margin-bottom:20px">Daily Production Report - <?= htmlspecialchars($nepali_months[$month]) ?> <?= htmlspecialchars($year) ?></h2>

        <form method="GET" class="filter-section">
            <div class="filter-row">
                <div class="filter-group">
                    <label>Year:</label>
                    <select name="year">
                        <?php for ($y = 2080; $y <= 2085; $y++): ?>
                            <option value="<?= $y ?>" <?= $y == $year ? 'selected' : '' ?>><?= $y ?></option>
                        <?php endfor; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Month:</label>
                    <select name="month">
                        <?php foreach ($nepali_months as $k => $v): ?>
                            <option value="<?= $k ?>" <?= $k == $month ? 'selected' : '' ?>><?= $v ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Report Type:</label>
                    <select name="report_type">
                        <option value="production" <?= $report_type === 'production' ? 'selected' : '' ?>>Production</option>
                        <option value="openpcs" <?= $report_type === 'openpcs' ? 'selected' : '' ?>>Open Pcs</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Translation:</label>
                    <select name="translation">
                        <option value="all" <?= $translation === 'all' ? 'selected' : '' ?>>All Books</option>
                        <option value="translated" <?= $translation === 'translated' ? 'selected' : '' ?>>Translated</option>
                        <option value="non_translated" <?= $translation === 'non_translated' ? 'selected' : '' ?>>Non-Translated</option>
                    </select>
                </div>
            </div>

            <div class="filter-row">
                <div class="filter-group">
                    <label>Search Book:</label>
                    <div class="search-container">
                        <input type="text" name="search" id="searchInput" value="<?= htmlspecialchars($search) ?>" placeholder="Type to search..." autocomplete="off">
                        <div class="search-dropdown" id="searchDropdown"></div>
                    </div>
                </div>
                <div class="filter-group">
                    <label>Book Code:</label>
                    <input name="book_code" value="<?= htmlspecialchars($book_code) ?>">
                </div>
                <div class="filter-group">
                    <label>Class Level:</label>
                    <input name="class_level" value="<?= htmlspecialchars($class_level) ?>">
                </div>
            </div>

            <div class="filter-row">
                <div class="filter-group">
                    <label>Classes:</label>
                    <select name="classes[]" multiple class="select2-classes">
                        <?php foreach ($all_classes as $c): ?>
                            <option value="<?= $c ?>" <?= in_array($c, $selected_classes) ? 'selected' : '' ?>>Class <?= $c ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Books:</label>
                    <select name="books[]" multiple class="select2-books">
                        <?php foreach ($all_books as $b): ?>
                            <option value="<?= $b['book_code'] ?>" <?= in_array($b['book_code'], $selected_books) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($b['book_name']) ?> (<?= $b['book_code'] ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary"><i class="fas fa-filter"></i> Apply</button>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary"><i class="fas fa-redo"></i> Reset</a>
            </div>
        </form>

        <?php if ($translation !== 'all' || $search || $book_code || $class_level || $selected_books || $selected_classes): ?>
            <div class="active-filters">
                <strong>Active Filters:</strong>
                <span class="filter-tag"><?= htmlspecialchars($nepali_months[$month]) ?> <?= htmlspecialchars($year) ?></span>
                <?php if ($translation !== 'all'): ?>
                    <span class="filter-tag"><?= ucfirst(str_replace('_', ' ', $translation)) ?></span>
                <?php endif; ?>
                <?php if ($search): ?>
                    <span class="filter-tag">Search: "<?= htmlspecialchars($search) ?>"</span>
                <?php endif; ?>
                <?php if ($book_code): ?>
                    <span class="filter-tag">Code: <?= htmlspecialchars($book_code) ?></span>
                <?php endif; ?>
                <?php if ($class_level): ?>
                    <span class="filter-tag">Class: <?= htmlspecialchars($class_level) ?></span>
                <?php endif; ?>
                <?php if ($selected_classes): ?>
                    <span class="filter-tag">Classes: <?= implode(',', $selected_classes) ?></span>
                <?php endif; ?>
                <?php if ($selected_books): ?>
                    <span class="filter-tag">Books: <?= count($selected_books) ?></span>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Books</div>
                <div class="value"><?= count($translated) + count($non_translated) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Total <?= ucfirst($report_type) ?></div>
                <div class="value"><?= number_format($grand_total) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Translated</div>
                <div class="value"><?= count($translated) ?></div>
            </div>
            <div class="summary-card">
                <div class="label">Non-Translated</div>
                <div class="value"><?= count($non_translated) ?></div>
            </div>
        </div>

        <div class="export-section">
            <a href="?<?= http_build_query($_GET + ['export' => 'csv']) ?>" class="btn btn-success"><i class="fas fa-file-csv"></i> CSV</a>
            <a href="?<?= http_build_query($_GET + ['export' => 'excel']) ?>" class="btn btn-success"><i class="fas fa-file-excel"></i> Excel</a>
            <button onclick="window.print()" class="btn btn-primary"><i class="fas fa-print"></i> Print</button>
        </div>
    </div>

    <!-- Print-only header -->
    <div class="report-header print-only">
        <h1>JANAK EDUCATION MATERIALS CENTER</h1>
        <h2>Daily Production Report</h2>
        <p><?= htmlspecialchars($nepali_months[$month]) ?> <?= htmlspecialchars($year) ?> | <?= ucfirst($report_type) ?></p>
    </div>

    <!-- Report Table -->
    <table class="report-table">
        <tr>
            <th>SN</th>
            <th class="book-name">Book</th>
            <th>Code</th>
            <th>Class</th>
            <?php for ($i = 1; $i <= 32; $i++): ?>
                <th class="day-col"><?= $i ?></th>
            <?php endfor; ?>
            <th>Total</th>
        </tr>

        <!-- Translated Books -->
        <tr><td colspan="37" class="section-header">TRANSLATED BOOKS</td></tr>
        <?php
        $sn = 1;
        foreach ($translated as $b): ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td class="book-name"><?= htmlspecialchars($b['name']) ?></td>
                <td><?= $b['code'] ?></td>
                <td><?= $b['class'] ?></td>
                <?php foreach ($b['days'] as $v): ?>
                    <td><?= $v ?: '-' ?></td>
                <?php endforeach; ?>
                <td><strong><?= $b['total'] ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <tr class="section-total">
            <td colspan="36">Translated Total</td>
            <td><?= $translated_total ?></td>
        </tr>

        <!-- Non-Translated Books -->
        <tr><td colspan="37" class="section-header">NON-TRANSLATED BOOKS</td></tr>
        <?php
        $sn = 1;
        foreach ($non_translated as $b): ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td class="book-name"><?= htmlspecialchars($b['name']) ?></td>
                <td><?= $b['code'] ?></td>
                <td><?= $b['class'] ?></td>
                <?php foreach ($b['days'] as $v): ?>
                    <td><?= $v ?: '-' ?></td>
                <?php endforeach; ?>
                <td><strong><?= $b['total'] ?></strong></td>
            </tr>
        <?php endforeach; ?>
        <tr class="section-total">
            <td colspan="36">Non-Translated Total</td>
            <td><?= $non_translated_total ?></td>
        </tr>

        <tr class="grand-total">
            <td colspan="36">GRAND TOTAL</td>
            <td><?= $grand_total ?></td>
        </tr>
    </table>
</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
const allBooks = <?= json_encode($all_books, JSON_HEX_TAG | JSON_HEX_AMP) ?>;

$(document).ready(function() {
    $('.select2-classes, .select2-books').select2({ placeholder: "Select...", allowClear: true });

    const searchInput = document.getElementById('searchInput');
    const searchDropdown = document.getElementById('searchDropdown');

    if (searchInput) {
        searchInput.addEventListener('input', function () {
            const query = this.value.toLowerCase().trim();
            if (query.length < 2) {
                searchDropdown.classList.remove('show');
                return;
            }

            const filtered = allBooks
                .filter(book => 
                    book.book_name.toLowerCase().includes(query) ||
                    book.book_code.toLowerCase().includes(query)
                )
                .slice(0, 10);

            if (filtered.length > 0) {
                searchDropdown.innerHTML = filtered.map(book =>
                    `<div class="search-option" onclick="selectBook('${book.book_code}', ${JSON.stringify(book.book_name)})">
                        <strong>${book.book_name}</strong><br>
                        <small>Code: ${book.book_code} | Class: ${book.class_level}</small>
                    </div>`
                ).join('');
                searchDropdown.classList.add('show');
            } else {
                searchDropdown.innerHTML = '<div class="search-option">No books found</div>';
                searchDropdown.classList.add('show');
            }
        });

        document.addEventListener('click', function (e) {
            if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
                searchDropdown.classList.remove('show');
            }
        });
    }
});

function selectBook(code, name) {
    document.getElementById('searchInput').value = name;
    $('.select2-books').val([code]).trigger('change');
    document.getElementById('searchDropdown').classList.remove('show');
}
</script>

</body>
</html>
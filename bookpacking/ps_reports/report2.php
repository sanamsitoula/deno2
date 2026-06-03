<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (!has_role('supervisor') && !has_role('incharge') && !has_role('admin')) {

        echo "<div class='alert alert-danger'>You don't have permission to perform this action.</div>";
        exit();
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// -----------------------------
// Get Active Fiscal Year
// -----------------------------
$fiscal_stmt = $conn->query("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = true LIMIT 1");
$active_fiscal = $fiscal_stmt->fetch(PDO::FETCH_ASSOC);

if (!$active_fiscal) {
    die("Error: No active fiscal year found in the database.");
}

$fiscal_id = $active_fiscal['id'];
$fiscal_code = $active_fiscal['fiscal_code']; // e.g., "2081/82" (used only as label)
$date_from ='2082.04.01'; // e.g., "2081.04.01"
$date_to ='2083.03.32';     // e.g., "2082.03.32"

// -----------------------------
// Selected Month (Nepali: 01-12)
// -----------------------------
$selected_month = $_GET['month'] ?? '04'; // Default: Shrawan

// Override fiscal year if selected
if (!empty($_GET['fiscal_year_id'])) {
    $selected_fiscal_id = (int)$_GET['fiscal_year_id'];
    $fiscal_stmt = $conn->prepare("SELECT id, fiscal_code FROM fiscal_years WHERE id = :id");
    $fiscal_stmt->execute([':id' => $selected_fiscal_id]);
    $active_fiscal = $fiscal_stmt->fetch(PDO::FETCH_ASSOC);
    if ($active_fiscal) {
        $fiscal_id = $active_fiscal['id'];
        $fiscal_code = $active_fiscal['fiscal_code'];
$date_from ='2082.04.01'; // e.g., "2081.04.01"
$date_to ='2083.03.32';     // e.g., "2082.03.32"
    }
}

// -----------------------------
// Nepali Months
// -----------------------------
$nepali_months = [
    '01' => 'Baisakh', '02' => 'Jestha', '03' => 'Ashad',
    '04' => 'Shrawan', '05' => 'Bhadra', '06' => 'Ashoj',
    '07' => 'Kartik', '08' => 'Mangsir', '09' => 'Poush',
    '10' => 'Magh', '11' => 'Falgun', '12' => 'Chaitra'
];

// Days 1–32
$days = array_fill_keys(range(1, 32), 0);

// -----------------------------
// Filters
// -----------------------------
$book_filter = $_GET['books'] ?? [];
$class_filter = $_GET['classes'] ?? [];
$supervisor_filter = $_GET['supervisor_id'] ?? '';
$packing_status_filter = $_GET['packing_status'] ?? 'all';
$search_text = trim($_GET['search_text'] ?? '');

// -----------------------------
// Build WHERE Clause
// -----------------------------
$where_conditions = ["bp.status = true", "bp.date_nep >= :date_from", "bp.date_nep < :date_to"];
$params = [
    ':date_from' => $date_from,
    ':date_to' => $date_to
];

if ($fiscal_id) {
    $where_conditions[] = "bp.fiscal_year_id = :fiscal_year_id";
    $params[':fiscal_year_id'] = $fiscal_id;
}

if ($packing_status_filter !== 'all') {
    $where_conditions[] = "bp.packing_status = :packing_status";
    $params[':packing_status'] = $packing_status_filter;
}

if ($supervisor_filter) {
    $where_conditions[] = "bp.supervisor_id = :supervisor_id";
    $params[':supervisor_id'] = $supervisor_filter;
}

if (!empty($book_filter)) {
    $book_placeholders = [];
    foreach ($book_filter as $i => $code) {
        $ph = ":book_$i";
        $book_placeholders[] = $ph;
        $params[$ph] = $code;
    }
    $where_conditions[] = "bp.book_code IN (" . implode(',', $book_placeholders) . ")";
}

if (!empty($class_filter)) {
    $class_placeholders = [];
    foreach ($class_filter as $i => $level) {
        $ph = ":class_$i";
        $class_placeholders[] = $ph;
        $params[$ph] = $level;
    }
    $where_conditions[] = "b.class_level IN (" . implode(',', $class_placeholders) . ")";
}

if ($search_text) {
    $where_conditions[] = "(b.book_name LIKE :search OR bp.book_code LIKE :search)";
    $params[':search'] = '%' . $search_text . '%';
}

$where_clause = implode(' AND ', $where_conditions);

// -----------------------------
// Fetch Data
// -----------------------------
$query = "
    SELECT 
        bp.*,
        jt.job_ticket_code,
        b.book_name,
        b.book_code as full_book_code,
        b.class_level,
        b.is_translated,
        u_supervisor.username as supervisor_name
    FROM book_packing bp
    LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN users u_supervisor ON bp.supervisor_id = u_supervisor.id
    WHERE $where_clause
    ORDER BY bp.book_code, bp.date_nep
";

try {
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// -----------------------------
// Get All Books & Supervisors
// -----------------------------
$books_stmt = $conn->prepare("
    SELECT DISTINCT b.book_code, b.book_name, b.class_level, b.is_translated
    FROM books b
    INNER JOIN job_ticket jt ON b.book_id = jt.book_id
    INNER JOIN book_packing bp ON jt.id = bp.jt_id
    WHERE bp.status = true
    ORDER BY b.is_translated DESC, b.class_level, b.book_name
");
$books_stmt->execute();
$all_books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);

$supervisors_stmt = $conn->prepare("
    SELECT DISTINCT u.id, u.username
    FROM users u
    INNER JOIN book_packing bp ON u.id = bp.supervisor_id
    WHERE bp.status = true AND u.role IN ('supervisor', 'admin')
    ORDER BY u.username
");
$supervisors_stmt->execute();
$supervisors = $supervisors_stmt->fetchAll(PDO::FETCH_ASSOC);

$class_levels = array_unique(array_column($all_books, 'class_level'));
sort($class_levels);

// -----------------------------
// Initialize Data Arrays
// -----------------------------
$data = ['translated' => [], 'non_translated' => []];
$daily_totals = array_fill_keys(range(1, 32), 0);
$job_tickets_map = [];

// -----------------------------
// Process Data
// -----------------------------
foreach ($raw_data as $row) {
    $book_code = $row['book_code'];
    $is_translated = (bool)$row['is_translated'];
    $key = $is_translated ? 'translated' : 'non_translated';

    if (!isset($data[$key][$book_code])) {
        $data[$key][$book_code] = [
            'book_name' => $row['book_name'],
            'book_code' => $book_code,
            'class_level' => $row['class_level'],
            'is_translated' => $is_translated,
            'days' => array_fill_keys(range(1, 32), 0),
            'total_qty' => 0
        ];
        $job_tickets_map[$book_code] = [];
    }

    // Parse day
    $date_parts = explode('.', $row['date_nep']);
    $day = (int)end($date_parts);
    if ($day < 1 || $day > 32) continue;

    $qty = (int)$row['p_qty'];
    $data[$key][$book_code]['days'][$day] += $qty;
    $data[$key][$book_code]['total_qty'] += $qty;
    $daily_totals[$day] += $qty;

    if (!in_array($row['job_ticket_code'], $job_tickets_map[$book_code])) {
        $job_tickets_map[$book_code][] = $row['job_ticket_code'];
    }
}

// -----------------------------
// Calculate Totals
// -----------------------------
$total_translated = count($data['translated']);
$total_non_translated = count($data['non_translated']);
$total_books = $total_translated + $total_non_translated;
$grand_total = array_sum($daily_totals);

// -----------------------------
// Export Handling
// -----------------------------
if (isset($_GET['export'])) {
    $export_type = $_GET['export'];
    $filename = "monthly_packing_{$nepali_months[$selected_month]}_{$fiscal_code}_" . date('Y-m-d_H-i-s');

    if ($export_type === 'csv') {
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$filename.csv\"");
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
        fputcsv($output, ['Janak Education Materials Center']);
        fputcsv($output, ['Monthly Book Packing Daily Report']);
        fputcsv($output, ["Month: {$nepali_months[$selected_month]} | Fiscal Year: $fiscal_code"]);
        fputcsv($output, ['Generated on: ' . date('Y-m-d H:i:s')]);
        fputcsv($output, []);

        // Summary
        fputcsv($output, ['Summary']);
        fputcsv($output, ['Total Books', $total_books]);
        fputcsv($output, ['Total Packed', $grand_total]);
        fputcsv($output, ['Translated', $total_translated]);
        fputcsv($output, ['Non-Translated', $total_non_translated]);
        fputcsv($output, []);

        // Headers
        $headers = ['SN', 'Book Name', 'Code', 'Class', 'Type', 'Job Tickets'];
        for ($i = 1; $i <= 32; $i++) $headers[] = "Day $i";
        $headers[] = 'Total';
        fputcsv($output, $headers);

        $writeSection = function($books, $type) use ($output, &$job_tickets_map) {
            $sn = 1;
            foreach ($books as $code => $book) {
                $row = [
                    $sn++,
                    $book['book_name'],
                    $code,
                    $book['class_level'],
                    $type,
                    implode(', ', $job_tickets_map[$code])
                ];
                for ($i = 1; $i <= 32; $i++) {
                    $row[] = $book['days'][$i] > 0 ? $book['days'][$i] : 0;
                }
                $row[] = $book['total_qty'];
                fputcsv($output, $row);
            }
        };

        if (!empty($data['translated'])) {
            fputcsv($output, ['', 'TRANSLATED BOOKS', '', '', '', '']);
            $writeSection($data['translated'], 'Translated');
        }

        if (!empty($data['non_translated'])) {
            fputcsv($output, ['', 'NON-TRANSLATED BOOKS', '', '', '', '']);
            $writeSection($data['non_translated'], 'Non-Translated');
        }

        $total_row = ['', 'GRAND TOTAL', '', '', '', ''];
        for ($i = 1; $i <= 32; $i++) $total_row[] = $daily_totals[$i];
        $total_row[] = $grand_total;
        fputcsv($output, $total_row);
        fclose($output);
        exit;
    } elseif ($export_type === 'excel') {
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$filename.xls\"");
        echo '<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel"><head><meta charset="UTF-8"></head><body>';
        echo '<table border="1">';
        echo '<tr><td colspan="39">Janak Education Materials Center</td></tr>';
        echo '<tr><td colspan="39">Monthly Book Packing Daily Report</td></tr>';
        echo '<tr><td colspan="39">Month: ' . $nepali_months[$selected_month] . ' | Fiscal Year: ' . $fiscal_code . '</td></tr>';
        echo '<tr><td colspan="39">Generated on: ' . date('Y-m-d H:i:s') . '</td></tr>';
        echo '<tr><td colspan="39">&nbsp;</td></tr>';
        echo '<tr><td colspan="39"><b>Summary</b></td></tr>';
        echo '<tr><td>Total Books</td><td>' . $total_books . '</td></tr>';
        echo '<tr><td>Total Packed</td><td>' . $grand_total . '</td></tr>';
        echo '<tr><td colspan="39">&nbsp;</td></tr>';
        echo '<tr>';
        $headers = ['SN', 'Book Name', 'Code', 'Class', 'Type', 'Job Tickets'];
        for ($i = 1; $i <= 32; $i++) $headers[] = "Day $i";
        $headers[] = 'Total';
        foreach ($headers as $h) echo '<th>' . htmlspecialchars($h) . '</th>';
        echo '</tr>';

        $writeSection = function($books, $type, $class = '') use (&$job_tickets_map) {
            $sn = 1;
            foreach ($books as $code => $book) {
                echo '<tr class="' . $class . '">';
                echo '<td>' . $sn++ . '</td>';
                echo '<td>' . htmlspecialchars($book['book_name']) . '</td>';
                echo '<td>' . $code . '</td>';
                echo '<td>' . $book['class_level'] . '</td>';
                echo '<td>' . $type . '</td>';
                echo '<td>' . htmlspecialchars(implode(', ', $job_tickets_map[$code])) . '</td>';
                for ($i = 1; $i <= 32; $i++) {
                    echo '<td>' . ($book['days'][$i] > 0 ? number_format($book['days'][$i]) : '') . '</td>';
                }
                echo '<td><b>' . number_format($book['total_qty']) . '</b></td>';
                echo '</tr>';
            }
        };

        if (!empty($data['translated'])) {
            echo '<tr><td colspan="39" style="background:#4CAF50;color:white;font-weight:bold;">TRANSLATED BOOKS</td></tr>';
            $writeSection($data['translated'], 'Translated', 'translated');
        }

        if (!empty($data['non_translated'])) {
            echo '<tr><td colspan="39" style="background:#f44336;color:white;font-weight:bold;">NON-TRANSLATED BOOKS</td></tr>';
            $writeSection($data['non_translated'], 'Non-Translated', 'non-translated');
        }

        echo '<tr style="font-weight:bold;background:#f0f0f0;"><td colspan="5">GRAND TOTAL</td><td></td>';
        for ($i = 1; $i <= 32; $i++) echo '<td><b>' . ($daily_totals[$i] > 0 ? number_format($daily_totals[$i]) : '') . '</b></td>';
        echo '<td><b>' . number_format($grand_total) . '</b></td></tr>';
        echo '</table></body></html>';
        exit;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Monthly Packing Report - <?= $nepali_months[$selected_month] ?> (<?= $fiscal_code ?>)</title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #3498db;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --border-color: #ddd;
        }
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f8f9fa;
            color: #333;
            margin: 0;
            padding: 0;
        }
        .report-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
            background: #fff;
        }
        .no-print { display: block; }
        .print-only { display: none; }

        @media print {
            .no-print { display: none !important; }
            .print-only { display: block !important; }
            @page { size: A4 landscape; margin: 8mm; }
            body { font-size: 9pt; }
            .print-header { text-align: center; margin-bottom: 10px; border-bottom: 2px solid #000; }
            .print-header h1 { margin: 0; font-size: 16pt; font-weight: bold; }
            .summary-cards-print {
                display: flex;
                justify-content: space-between;
                margin: 8px 0;
            }
            .summary-card-print {
                border: 1px solid #000;
                padding: 4px;
                width: 24%;
                text-align: center;
                font-size: 8pt;
            }
            .section-header {
                background: #333 !important;
                color: white !important;
                font-weight: bold;
            }
            .daily-table th, .daily-table td {
                padding: 2px;
                font-size: 7pt;
                text-align: center;
            }
            .book-name-col { text-align: left; max-width: 120px; }
            .section-total-row td, .grand-total-row td {
                font-weight: bold;
                background: #f0f0f0 !important;
            }
        }
        h2 { text-align: center; color: var(--dark-color); margin-bottom: 20px; }
        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
        }
        .filter-group label { font-weight: 600; color: var(--dark-color); margin-bottom: 5px; }
        .filter-group select, .filter-group input, .filter-group button {
            width: 100%; padding: 10px; border: 1px solid var(--border-color);
            border-radius: 6px; background: white;
        }
        .filter-group button {
            background: var(--primary-color); color: white; border: none; margin-top: 5px;
        }
        .filter-group a {
            display: block; text-align: center; padding: 10px; background: #f1f1f1;
            border-radius: 6px; margin-top: 5px; text-decoration: none;
        }
        .table-container { overflow-x: auto; margin-bottom: 20px; }
        .daily-table { width: auto; border-collapse: collapse; }
        .daily-table th, .daily-table td { border: 1px solid var(--border-color); padding: 8px; }
        .daily-table th { background: var(--dark-color); color: white; }
        .book-name-col { text-align: left; min-width: 220px; }
        .section-header {
            font-weight: bold; text-align: left; padding: 10px 15px;
            font-size: 15px; background: var(--dark-color); color: white;
        }
        .translated-header { background: var(--success-color); }
        .non-translated-header { background: var(--danger-color); }
        .export-section {
            display: flex; gap: 10px; flex-wrap: wrap;
            background: #e8f5ff; padding: 15px; border-radius: 8px;
        }
        .export-section a, .export-section button {
            padding: 10px 15px; border-radius: 6px; background: var(--primary-color);
            color: white; border: none; display: inline-flex; align-items: center; gap: 8px;
        }
        .summary-cards {
            display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px; margin: 20px 0;
        }
        .summary-card {
            padding: 15px; border-radius: 8px; background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1); text-align: center;
        }
        .summary-card .value { font-size: 24px; font-weight: bold; color: var(--primary-color); }
    </style>
</head>
<body>
<div class="report-container">
    <h2 class="no-print">Monthly Book Packing Report - <?= $nepali_months[$selected_month] ?> (<?= $fiscal_code ?>)</h2>

    <!-- Filters -->
    <form method="GET" class="filter-form no-print">
        <div class="filter-group">
            <label>Month:</label>
            <select name="month">
                <?php foreach ($nepali_months as $k => $v): ?>
                    <option value="<?= $k ?>" <?= $k == $selected_month ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Fiscal Year:</label>
            <select name="fiscal_year_id">
                <?php
                $fy_query = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC");
                while ($fy = $fy_query->fetch(PDO::FETCH_ASSOC)):
                ?>
                    <option value="<?= $fy['id'] ?>" <?= $fy['id'] == $fiscal_id ? 'selected' : '' ?>>
                        <?= $fy['fiscal_code'] ?>
                    </option>
                <?php endwhile; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Packing Status:</label>
            <select name="packing_status">
                <option value="all" <?= $packing_status_filter === 'all' ? 'selected' : '' ?>>All Status</option>
                <option value="active" <?= $packing_status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                <option value="completed" <?= $packing_status_filter === 'completed' ? 'selected' : '' ?>>Completed</option>
                <option value="pending" <?= $packing_status_filter === 'pending' ? 'selected' : '' ?>>Pending</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Supervisor:</label>
            <select name="supervisor_id">
                <option value="">All Supervisors</option>
                <?php foreach ($supervisors as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $s['id'] == $supervisor_filter ? 'selected' : '' ?>>
                        <?= $s['username'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Search Book:</label>
            <input type="text" name="search_text" value="<?= htmlspecialchars($search_text) ?>"
                   placeholder="Search by name or code...">
        </div>
        <div class="filter-group">
            <label>Classes:</label>
            <select name="classes[]" multiple>
                <?php foreach ($class_levels as $c): ?>
                    <option value="<?= $c ?>" <?= in_array($c, $class_filter) ? 'selected' : '' ?>>
                        Class <?= $c ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Books:</label>
            <select name="books[]" multiple>
                <?php foreach ($all_books as $b): ?>
                    <option value="<?= $b['book_code'] ?>" <?= in_array($b['book_code'], $book_filter) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($b['book_name']) ?> (<?= $b['book_code'] ?>)
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit">Apply Filters</button>
            <a href="?">Reset</a>
        </div>
    </form>

    <!-- Summary Cards -->
    <div class="summary-cards no-print">
        <div class="summary-card">
            <div class="label">Total Books</div>
            <div class="value"><?= $total_books ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total Packed</div>
            <div class="value"><?= number_format($grand_total) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Translated</div>
            <div class="value"><?= $total_translated ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Non-Translated</div>
            <div class="value"><?= $total_non_translated ?></div>
        </div>
    </div>

    <!-- Export -->
    <div class="export-section no-print">
        <a href="?<?= http_build_query($_GET + ['export' => 'csv']) ?>">
            <i class="fas fa-file-csv"></i> Export CSV
        </a>
        <a href="?<?= http_build_query($_GET + ['export' => 'excel']) ?>">
            <i class="fas fa-file-excel"></i> Export Excel
        </a>
        <button onclick="printReport()">
            <i class="fas fa-print"></i> Print
        </button>
    </div>

    <!-- Print Section -->
    <div class="table-container print-section">
        <div class="print-only print-header">
            <h1>जनक शिक्षा सामग्री केन्द्र लिमिटेड</h1>
            <div>मासिक पुस्तक प्याकिङ रिपोर्ट</div>
            <div><?= $nepali_months[$selected_month] ?> <?= $fiscal_code ?></div>
        </div>

        <table class="daily-table" id="reportTable">
            <thead>
                <tr>
                    <th>SN</th>
                    <th class="book-name-col">Book Name</th>
                    <th>Code</th>
                    <th>Class</th>
                    <th>Type</th>
                    <th>Job Tickets</th>
                    <?php for ($i = 1; $i <= 32; $i++): ?>
                        <th><?= $i ?></th>
                    <?php endfor; ?>
                    <th>Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($data['translated'])): ?>
                <tr>
                    <td colspan="39" class="section-header translated-header">
                        📚 TRANSLATED BOOKS (<?= $total_translated ?>)
                    </td>
                </tr>
                <?php $sn = 1; foreach ($data['translated'] as $code => $b): ?>
                <tr>
                    <td><?= $sn++ ?></td>
                    <td class="book-name-col"><?= htmlspecialchars($b['book_name']) ?></td>
                    <td><?= $code ?></td>
                    <td><?= $b['class_level'] ?></td>
                    <td>T</td>
                    <td title="<?= htmlspecialchars(implode(', ', $job_tickets_map[$code])) ?>">
                        <?= count($job_tickets_map[$code]) > 1 ? 
                           htmlspecialchars($job_tickets_map[$code][0] . ' + ' . (count($job_tickets_map[$code]) - 1)) : 
                           (isset($job_tickets_map[$code][0]) ? htmlspecialchars($job_tickets_map[$code][0]) : '') ?>
                    </td>
                    <?php for ($i = 1; $i <= 32; $i++): ?>
                        <td><?= $b['days'][$i] > 0 ? number_format($b['days'][$i]) : '-' ?></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($b['total_qty']) ?></b></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if (!empty($data['non_translated'])): ?>
                <tr>
                    <td colspan="39" class="section-header non-translated-header">
                        📖 NON-TRANSLATED BOOKS (<?= $total_non_translated ?>)
                    </td>
                </tr>
                <?php $sn = 1; foreach ($data['non_translated'] as $code => $b): ?>
                <tr>
                    <td><?= $sn++ ?></td>
                    <td class="book-name-col"><?= htmlspecialchars($b['book_name']) ?></td>
                    <td><?= $code ?></td>
                    <td><?= $b['class_level'] ?></td>
                    <td>NT</td>
                    <td title="<?= htmlspecialchars(implode(', ', $job_tickets_map[$code])) ?>">
                        <?= count($job_tickets_map[$code]) > 1 ? 
                           htmlspecialchars($job_tickets_map[$code][0] . ' + ' . (count($job_tickets_map[$code]) - 1)) : 
                           (isset($job_tickets_map[$code][0]) ? htmlspecialchars($job_tickets_map[$code][0]) : '') ?>
                    </td>
                    <?php for ($i = 1; $i <= 32; $i++): ?>
                        <td><?= $b['days'][$i] > 0 ? number_format($b['days'][$i]) : '-' ?></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($b['total_qty']) ?></b></td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>

                <tr class="grand-total-row">
                    <td colspan="5"><b>GRAND TOTAL</b></td>
                    <td></td>
                    <?php for ($i = 1; $i <= 32; $i++): ?>
                        <td><b><?= $daily_totals[$i] > 0 ? number_format($daily_totals[$i]) : '-' ?></b></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($grand_total) ?></b></td>
                </tr>
            </tbody>
        </table>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>
<script>
function printReport() {
    // Get references to the main table
    const table = document.querySelector(".daily-table");
    if (!table) {
        alert("Report table not found!");
        return;
    }

    // Get all rows (excluding header and grand total)
    const rows = table.querySelectorAll("tbody tr");
    let tableRows = '';
    let totalPackedQty = 0;

    // Loop through each row
    rows.forEach((row, index) => {
        // Skip section headers (TRANSLATED / NON-TRANSLATED)
        if (row.querySelector('.section-header')) return;

        const cells = row.querySelectorAll("td");
        if (cells.length === 0) return;

        const sn = cells[0]?.textContent.trim();
        const bookName = cells[1]?.textContent.trim();
        const bookCode = cells[2]?.textContent.trim();
        const classLevel = cells[3]?.textContent.trim();
        const bookType = cells[4]?.textContent.trim();
        const jobTickets = cells[5]?.getAttribute('title') || cells[5]?.textContent.trim();

        // Collect daily quantities (columns 6 to 37 → days 1–32)
        const dailyCells = Array.from(cells).slice(6, 38); // Day 1 to Day 32
        const dailyValues = dailyCells.map(cell => cell.textContent.trim()).join('</td><td>');

        // Total column (39th cell)
        const totalQtyText = cells[38]?.textContent.trim();
        const totalQty = parseInt(totalQtyText.replace(/,/g, '')) || 0;
        totalPackedQty += totalQty;

        tableRows += `
            <tr>
                <td>${sn}</td>
                <td>${bookName}</td>
                <td>${bookCode}</td>
                <td>${classLevel}</td>
                <td>${bookType}</td>
                <td>${jobTickets}</td>
                <td>${dailyValues}</td>
                <td><b>${totalQty.toLocaleString()}</b></td>
            </tr>
        `;
    });

    // Add grand total row
    tableRows += `
        <tr class="total-row" style="font-weight:bold;background:#f0f0f0;">
            <td colspan="6"><strong>GRAND TOTAL</strong></td>
            <td colspan="32"></td>
            <td><strong>${totalPackedQty.toLocaleString()}</strong></td>
        </tr>
    `;

    // Current date/time
    const currentDateTime = new Date().toLocaleString();

    // === PRINT CSS STYLES ===
    const printCSS = `
        <style>
            @page {
                size: A4 landscape;
                margin: 15mm;
                @bottom-center {
                    content: "Page " counter(page) " of " counter(pages);
                    font-size: 10px;
                    color: #333;
                }
            }
            body {
                font-family: 'Segoe UI', Arial, sans-serif;
                font-size: 10pt;
                line-height: 1.2;
                color: #000;
                margin: 0;
                padding: 0;
                background: #fff !important;
            }
            .header {
                text-align: center;
                margin-bottom: 15px;
                border-bottom: 3px double #000;
                padding-bottom: 10px;
            }
            .company-name {
                font-size: 18pt;
                font-weight: bold;
            }
            .report-title {
                font-size: 14pt;
                font-weight: bold;
            }
            .report-subtitle {
                font-size: 11pt;
            }
            .summary {
                border: 1px solid #000;
                padding: 8px;
                margin: 10px 0;
                font-size: 11pt;
            }
            table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 10px;
                font-size: 9pt;
            }
            th, td {
                border: 1px solid #000;
                padding: 4px;
                text-align: center;
            }
            th {
                background-color: #f0f0f0;
                font-weight: bold;
            }
            .book-name-col {
                text-align: left;
                max-width: 150px;
            }
            .section-header {
                background-color: #333 !important;
                color: white !important;
                text-align: left;
                font-weight: bold;
            }
            .total-row td {
                font-weight: bold;
                background-color: #f0f0f0;
            }
            .footer {
                margin-top: 30px;
                text-align: center;
                font-size: 10pt;
                color: #555;
                border-top: 1px solid #aaa;
                padding-top: 10px;
            }
        </style>
    `;

    // Build final print HTML
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Monthly Packing Report - Print</title>
            ${printCSS}
        </head>
        <body>
            <div class="header">
                <div class="company-name">जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
                <div>Janak Educational Materials Center Ltd.</div>
                <div class="report-title">Monthly Book Packing Daily Report</div>
                <div class="report-subtitle">Month: ${"<?= addslashes($nepali_months[$selected_month]) ?>"} | Fiscal Year: <?= addslashes($fiscal_code) ?></div>
            </div>

            <div class="summary">
                <strong>Total Books:</strong> <?= $total_books ?> |
                <strong>Total Packed:</strong> ${totalPackedQty.toLocaleString()} |
                <strong>Generated On:</strong> ${currentDateTime}
            </div>

            <table>
                <thead>
                    <tr>
                        <th>SN</th>
                        <th>Book Name</th>
                        <th>Code</th>
                        <th>Class</th>
                        <th>Type</th>
                        <th>Job Tickets</th>
                        ${Array.from({length: 32}, (_, i) => `<th>${i + 1}</th>`).join('')}
                        <th>Total</th>
                    </tr>
                </thead>
                <tbody>
                    ${tableRows}
                </tbody>
            </table>

            <div class="footer">
                Report generated on: ${currentDateTime}
            </div>
        </body>
        </html>
    `;

    // Open print window
    const printWindow = window.open('', '_blank', 'width=1200,height=800');
    printWindow.document.write(printContent);
    printWindow.document.close();

    printWindow.onload = function () {
        printWindow.print();
    };
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
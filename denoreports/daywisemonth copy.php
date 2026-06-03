<?php 
// Move all output handling to the very beginning, before any includes
ob_start(); // Start output buffering to prevent header issues

// Handle export requests FIRST, before any includes or output
if (isset($_GET['export'])) {
    // Clear any previous output
    if (ob_get_length()) {
        ob_clean();
    }
    
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
    
    // Get parameters for export
    $year = $_GET['year'] ?? '2081';
    $selected_month = $_GET['month'] ?? '04';
    $report_type = $_GET['report_type'] ?? 'production';
    $translation_filter = $_GET['translation_filter'] ?? 'all';
    $selected_books = isset($_GET['books']) && is_array($_GET['books']) ? $_GET['books'] : [];
    $selected_classes = isset($_GET['classes']) && is_array($_GET['classes']) ? array_map('intval', $_GET['classes']) : [];
    $search_text = trim($_GET['search_text'] ?? '');
    
    // Nepali months
    $nepali_months = [
        '01' => 'Baisakh', '02' => 'Jestha', '03' => 'Ashad', 
        '04' => 'Shrawan', '05' => 'Bhadra', '06' => 'Ashoj',
        '07' => 'Kartik', '08' => 'Mangsir', '09' => 'Poush',
        '10' => 'Magh', '11' => 'Falgun', '12' => 'Chaitra'
    ];
    
    // Build query (same as your existing logic)
    $where_conditions = [];
    $params = [':year' => $year, ':month' => $selected_month];
    $where_conditions[] = "d.deno_year = :year AND SUBSTRING(d.deno_date_nep, 6, 2) = :month";
    
    if ($translation_filter === 'translated') {
        $where_conditions[] = "b.is_translated = TRUE";
    } elseif ($translation_filter === 'non_translated') {
        $where_conditions[] = "b.is_translated = FALSE";
    }
    
    if (!empty($selected_books)) {
        $book_placeholders = [];
        foreach ($selected_books as $index => $code) {
            $ph = ":book_code_$index";
            $book_placeholders[] = $ph;
            $params[$ph] = $code;
        }
        $where_conditions[] = "b.book_code IN (" . implode(',', $book_placeholders) . ")";
    }
    
    if (!empty($selected_classes)) {
        $class_placeholders = [];
        foreach ($selected_classes as $index => $level) {
            $ph = ":class_$index";
            $class_placeholders[] = $ph;
            $params[$ph] = $level;
        }
        $where_conditions[] = "b.class_level IN (" . implode(',', $class_placeholders) . ")";
    }
    
    if (!empty($search_text)) {
        $where_conditions[] = "(b.book_name ILIKE :search OR b.book_code ILIKE :search)";
        $params[':search'] = '%' . $search_text . '%';
    }
    
    $where_clause = implode(' AND ', $where_conditions);
    
    $sql = "
        SELECT 
            b.book_name,
            b.book_code,
            b.class_level,
            b.is_translated,
            SUBSTRING(d.deno_date_nep, 9, 2) AS day_of_month,
            d.deno_date_nep,
            d.deno_date_eng,
            COALESCE(SUM(d.total_qty), 0) AS total_produced,
            COALESCE(SUM(d.quantity_openpcs), 0) AS total_openpcs
        FROM Books b
        LEFT JOIN Deno d ON b.book_code = d.book_code 
            AND d.deno_year = :year 
            AND SUBSTRING(d.deno_date_nep, 6, 2) = :month
    ";
    
    if (!empty($where_conditions)) {
        $sql .= " WHERE " . implode(' AND ', $where_conditions);
    }
    
    $sql .= "
        GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, 
                 d.deno_date_nep, d.deno_date_eng
        ORDER BY b.class_level, b.book_name, day_of_month
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Process data (same logic as your existing code)
    $translated_data = [];
    $non_translated_data = [];
    $daily_totals = [];
    
    $days = [];
    for ($i = 1; $i <= 32; $i++) {
        $days[sprintf('%02d', $i)] = $i;
    }
    
    foreach ($days as $day_key => $day_num) {
        $daily_totals[$day_key] = [
            'total_produced' => 0,
            'total_openpcs' => 0,
            'translated' => 0,
            'non_translated' => 0,
        ];
    }
    
    foreach ($raw_data as $row) {
        $book_key = $row['book_code'];
        $is_translated = (bool)$row['is_translated'];
  if ($is_translated) {
    $target_array =& $translated_data;
} else {
    $target_array =& $non_translated_data;
}

        
        if (!isset($target_array[$book_key])) {
            $target_array[$book_key] = [
                'book_name' => $row['book_name'],
                'book_code' => $row['book_code'],
                'class_level' => $row['class_level'],
                'is_translated' => $is_translated,
                'days' => [],
                'total_produced' => 0,
                'total_openpcs' => 0
            ];
            foreach ($days as $day_key => $day_num) {
                $target_array[$book_key]['days'][$day_key] = [
                    'total_produced' => 0,
                    'total_openpcs' => 0,
                ];
            }
        }
        
        $day = $row['day_of_month'];
        if ($day && isset($target_array[$book_key]['days'][$day])) {
            $target_array[$book_key]['days'][$day] = [
                'total_produced' => (int)$row['total_produced'],
                'total_openpcs' => (int)$row['total_openpcs'],
            ];
            $target_array[$book_key]['total_produced'] += (int)$row['total_produced'];
            $target_array[$book_key]['total_openpcs'] += (int)$row['total_openpcs'];
            $daily_totals[$day]['total_produced'] += (int)$row['total_produced'];
            $daily_totals[$day]['total_openpcs'] += (int)$row['total_openpcs'];
            
            if ($is_translated) {
                $daily_totals[$day]['translated'] += (int)$row['total_produced'];
            } else {
                $daily_totals[$day]['non_translated'] += (int)$row['total_produced'];
            }
        }
    }
    
    // Calculate totals
    $grand_total_produced = array_sum(array_column($daily_totals, 'total_produced'));
    $grand_total_openpcs = array_sum(array_column($daily_totals, 'total_openpcs'));
    $translated_total_produced = array_sum(array_column($translated_data, 'total_produced'));
    $non_translated_total_produced = array_sum(array_column($non_translated_data, 'total_produced'));
    $translated_total_openpcs = array_sum(array_column($translated_data, 'total_openpcs'));
    $non_translated_total_openpcs = array_sum(array_column($non_translated_data, 'total_openpcs'));
    
    // Handle export
    $export_type = $_GET['export'];
    $filename = "daily_report_{$nepali_months[$selected_month]}_{$year}_" . date('Y-m-d_H-i-s');
    
    if ($export_type === 'csv') {
        header("Content-Type: text/csv; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$filename.csv\"");
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
        
        $output = fopen('php://output', 'w');
        fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // BOM for UTF-8
        
        // Headers
        fputcsv($output, ['Janak Education Materials Center']);
        fputcsv($output, ['Daily Production Report']);
        fputcsv($output, ["{$nepali_months[$selected_month]} {$year}"]);
        fputcsv($output, ['Report Type: ' . ucfirst($report_type)]);
        fputcsv($output, []);
        
        $headers = ['SN', 'Book Name', 'Code', 'Class', 'Type'];
        for ($i = 1; $i <= 32; $i++) $headers[] = "Day $i";
        $headers[] = 'Total';
        fputcsv($output, $headers);
        
    } else {
        // Excel export
        header("Content-Type: application/vnd.ms-excel; charset=utf-8");
        header("Content-Disposition: attachment; filename=\"$filename.xls\"");
        header("Cache-Control: no-cache, must-revalidate");
        header("Expires: Sat, 26 Jul 1997 05:00:00 GMT");
        
        echo '<html><head><meta charset="utf-8"><title>Daily Report</title></head><body>';
        echo '<table border="1" style="border-collapse: collapse;">';
        echo '<tr><td colspan="38" style="text-align: center; font-weight: bold;">Janak Education Materials Center</td></tr>';
        echo '<tr><td colspan="38" style="text-align: center; font-weight: bold;">Daily Production Report</td></tr>';
        echo '<tr><td colspan="38" style="text-align: center;">' . $nepali_months[$selected_month] . ' ' . $year . '</td></tr>';
        echo '<tr><td colspan="38"></td></tr>';
        
        echo '<tr>';
        $headers = ['SN', 'Book Name', 'Code', 'Class', 'Type'];
        for ($i = 1; $i <= 32; $i++) $headers[] = "Day $i";
        $headers[] = 'Total';
        foreach ($headers as $header) {
            echo '<th style="background: #333; color: white; padding: 5px;">' . htmlspecialchars($header) . '</th>';
        }
        echo '</tr>';
    }
    
    // Write data
    $writeBookData = function($books, $type, $section_name = '') use ($output, $report_type, $export_type) {
        if ($section_name && $export_type === 'csv') {
            fputcsv($output, ['', $section_name, '', '', '']);
        } elseif ($section_name && $export_type !== 'csv') {
            echo '<tr><td colspan="38" style="background: #4CAF50; color: white; font-weight: bold; padding: 8px;">' . htmlspecialchars($section_name) . '</td></tr>';
        }
        
        $sn = 1;
        foreach ($books as $book) {
            $row = [
                $sn++,
                $book['book_name'],
                $book['book_code'],
                $book['class_level'],
                $type
            ];
            for ($i = 1; $i <= 32; $i++) {
                $day_key = sprintf('%02d', $i);
                $val = $report_type === 'openpcs' 
                    ? $book['days'][$day_key]['total_openpcs'] 
                    : $book['days'][$day_key]['total_produced'];
                $row[] = $val > 0 ? $val : 0;
            }
            $total = $report_type === 'openpcs' ? $book['total_openpcs'] : $book['total_produced'];
            $row[] = $total;
            
            if ($export_type === 'csv') {
                fputcsv($output, $row);
            } else {
                echo '<tr>';
                foreach ($row as $cell) {
                    echo '<td style="padding: 3px; text-align: center;">' . htmlspecialchars($cell) . '</td>';
                }
                echo '</tr>';
            }
        }
    };
    
    // Export data
    if (!empty($translated_data)) {
        $writeBookData($translated_data, 'Translated', 'TRANSLATED BOOKS (' . count($translated_data) . ')');
    }
    
    if (!empty($non_translated_data)) {
        $writeBookData($non_translated_data, 'Non-Translated', 'NON-TRANSLATED BOOKS (' . count($non_translated_data) . ')');
    }
    
    // Grand total
    $grand_row = ['', 'GRAND TOTAL', '', '', ''];
    for ($i = 1; $i <= 32; $i++) {
        $day_key = sprintf('%02d', $i);
        $val = $report_type === 'openpcs' 
            ? ($daily_totals[$day_key]['total_openpcs'] ?? 0) 
            : ($daily_totals[$day_key]['total_produced'] ?? 0);
        $grand_row[] = $val;
    }
    $grand_row[] = $report_type === 'openpcs' ? $grand_total_openpcs : $grand_total_produced;
    
    if ($export_type === 'csv') {
        fputcsv($output, $grand_row);
        fclose($output);
    } else {
        echo '<tr style="background: #333; color: white; font-weight: bold;">';
        foreach ($grand_row as $cell) {
            echo '<td style="padding: 5px; text-align: center;"><strong>' . htmlspecialchars($cell) . '</strong></td>';
        }
        echo '</tr>';
        echo '</table></body></html>';
    }
    exit;
}

// Clear output buffer and continue with normal page rendering
if (ob_get_length()) {
    ob_end_clean();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php'; 
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Rest of your existing PHP logic here...
function getLastNepaliMonth() {
    $current_year = 2081;
    $current_month = 4;
    if ($current_month == 1) {
        return ['year' => $current_year - 1, 'month' => '12'];
    } else {
        return ['year' => $current_year, 'month' => sprintf('%02d', $current_month - 1)];
    }
}
$lastMonth = getLastNepaliMonth();

$year = $_GET['year'] ?? $lastMonth['year'];
$selected_month = $_GET['month'] ?? $lastMonth['month'];
$report_type = $_GET['report_type'] ?? 'production';
$translation_filter = $_GET['translation_filter'] ?? 'all';
$selected_books = isset($_GET['books']) && is_array($_GET['books']) ? $_GET['books'] : [];
$selected_classes = isset($_GET['classes']) && is_array($_GET['classes']) ? array_map('intval', $_GET['classes']) : [];
$search_text = trim($_GET['search_text'] ?? '');

$nepali_months = [
    '01' => 'Baisakh', '02' => 'Jestha', '03' => 'Ashad', 
    '04' => 'Shrawan', '05' => 'Bhadra', '06' => 'Ashoj',
    '07' => 'Kartik', '08' => 'Mangsir', '09' => 'Poush',
    '10' => 'Magh', '11' => 'Falgun', '12' => 'Chaitra'
];

$days = [];
for ($i = 1; $i <= 32; $i++) {
    $days[sprintf('%02d', $i)] = $i;
}

// Build WHERE clause
$where_conditions = [];
$params = [':year' => $year, ':month' => $selected_month];
$where_conditions[] = "d.deno_year = :year AND SUBSTRING(d.deno_date_nep, 6, 2) = :month";

if ($translation_filter === 'translated') {
    $where_conditions[] = "b.is_translated = TRUE";
} elseif ($translation_filter === 'non_translated') {
    $where_conditions[] = "b.is_translated = FALSE";
}

if (!empty($selected_books)) {
    $book_placeholders = [];
    foreach ($selected_books as $index => $code) {
        $ph = ":book_code_$index";
        $book_placeholders[] = $ph;
        $params[$ph] = $code;
    }
    $where_conditions[] = "b.book_code IN (" . implode(',', $book_placeholders) . ")";
}

if (!empty($selected_classes)) {
    $class_placeholders = [];
    foreach ($selected_classes as $index => $level) {
        $ph = ":class_$index";
        $class_placeholders[] = $ph;
        $params[$ph] = $level;
    }
    $where_conditions[] = "b.class_level IN (" . implode(',', $class_placeholders) . ")";
}

if (!empty($search_text)) {
    $where_conditions[] = "(b.book_name ILIKE :search OR b.book_code ILIKE :search)";
    $params[':search'] = '%' . $search_text . '%';
}

$where_clause = implode(' AND ', $where_conditions);

// Fetch Data
$sql = "
    SELECT 
        b.book_name,
        b.book_code,
        b.class_level,
        b.is_translated,
        SUBSTRING(d.deno_date_nep, 9, 2) AS day_of_month,
        d.deno_date_nep,
        d.deno_date_eng,
        COALESCE(SUM(d.total_qty), 0) AS total_produced,
        COALESCE(SUM(d.quantity_openpcs), 0) AS total_openpcs
    FROM Books b
    LEFT JOIN Deno d ON b.book_code = d.book_code 
        AND d.deno_year = :year 
        AND SUBSTRING(d.deno_date_nep, 6, 2) = :month
";

if (!empty($where_conditions)) {
    $sql .= " WHERE " . implode(' AND ', $where_conditions);
}

$sql .= "
    GROUP BY b.book_name, b.book_code, b.class_level, b.is_translated, 
             d.deno_date_nep, d.deno_date_eng
    ORDER BY b.class_level, b.book_name, day_of_month
";

try {
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $raw_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    die("Database error: " . $e->getMessage());
}

// Get All Books & Classes
$books_stmt = $conn->prepare("SELECT book_code, book_name, class_level, is_translated FROM Books ORDER BY is_translated DESC, class_level, book_name");
$books_stmt->execute();
$all_books = $books_stmt->fetchAll(PDO::FETCH_ASSOC);

$classes_stmt = $conn->prepare("SELECT DISTINCT class_level FROM Books ORDER BY class_level");
$classes_stmt->execute();
$all_classes = $classes_stmt->fetchAll(PDO::FETCH_COLUMN);

// Initialize Data Arrays
$translated_data = [];
$non_translated_data = [];
$daily_totals = [];

foreach ($days as $day_key => $day_num) {
    $daily_totals[$day_key] = [
        'total_produced' => 0,
        'total_openpcs' => 0,
        'translated' => 0,
        'non_translated' => 0,
        'date_nep' => '',
        'date_eng' => ''
    ];
}

// Process Data
foreach ($raw_data as $row) {
    $book_key = $row['book_code'];
    $is_translated = (bool)$row['is_translated'];

    if ($is_translated) {
        $target_array = &$translated_data;
    } else {
        $target_array = &$non_translated_data;
    }

    if (!isset($target_array[$book_key])) {
        $target_array[$book_key] = [
            'book_name' => $row['book_name'],
            'book_code' => $row['book_code'],
            'class_level' => $row['class_level'],
            'is_translated' => $is_translated,
            'days' => [],
            'total_produced' => 0,
            'total_openpcs' => 0
        ];
        foreach ($days as $day_key => $day_num) {
            $target_array[$book_key]['days'][$day_key] = [
                'total_produced' => 0,
                'total_openpcs' => 0,
                'date_nep' => '',
                'date_eng' => ''
            ];
        }
    }

    $day = $row['day_of_month'];
    if ($day && isset($target_array[$book_key]['days'][$day])) {
        $target_array[$book_key]['days'][$day] = [
            'total_produced' => (int)$row['total_produced'],
            'total_openpcs' => (int)$row['total_openpcs'],
            'date_nep' => $row['deno_date_nep'],
            'date_eng' => $row['deno_date_eng']
        ];
        $target_array[$book_key]['total_produced'] += (int)$row['total_produced'];
        $target_array[$book_key]['total_openpcs'] += (int)$row['total_openpcs'];
        $daily_totals[$day]['total_produced'] += (int)$row['total_produced'];
        $daily_totals[$day]['total_openpcs'] += (int)$row['total_openpcs'];
        $daily_totals[$day]['date_nep'] = $row['deno_date_nep'];
        $daily_totals[$day]['date_eng'] = $row['deno_date_eng'];
        if ($is_translated) {
            $daily_totals[$day]['translated'] += (int)$row['total_produced'];
        } else {
            $daily_totals[$day]['non_translated'] += (int)$row['total_produced'];
        }
    }
}

// Calculate Totals
$grand_total_produced = array_sum(array_column($daily_totals, 'total_produced'));
$grand_total_openpcs = array_sum(array_column($daily_totals, 'total_openpcs'));
$translated_total_produced = array_sum(array_column($translated_data, 'total_produced'));
$non_translated_total_produced = array_sum(array_column($non_translated_data, 'total_produced'));
$translated_total_openpcs = array_sum(array_column($translated_data, 'total_openpcs'));
$non_translated_total_openpcs = array_sum(array_column($non_translated_data, 'total_openpcs'));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Daily Production Report - <?= htmlspecialchars($nepali_months[$selected_month]) ?> <?= $year ?></title>
    <link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet" />
    <style>
        :root {
            --primary-color: #3498db;
            --secondary-color: #2980b9;
            --success-color: #27ae60;
            --danger-color: #e74c3c;
            --light-color: #ecf0f1;
            --dark-color: #2c3e50;
            --text-color: #333;
            --border-color: #ddd;
        }

        body {
            font-family: 'Arial', 'Helvetica', sans-serif;
            background: #f8f9fa;
            color: var(--text-color);
            font-size: 14px;
            margin: 0;
            padding: 0;
            line-height: 1.4;
        }

        .report-container {
            max-width: 100%;
            margin: 0 auto;
            padding: 20px;
            background: #fff;
        }

        .print-only {
            display: none;
        }

        /* Enhanced Print Styles */
        @media print {
            @page {
                size: A4 landscape;
                margin: 8mm;
            }

            * {
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            body {
                font-size: 7pt !important;
                line-height: 1.1 !important;
                margin: 0 !important;
                padding: 0 !important;
                background: white !important;
                font-family: 'Arial', sans-serif !important;
            }

            .no-print, .no-print * {
                display: none !important;
                visibility: hidden !important;
            }

            .print-only, .print-only * {
                display: block !important;
                visibility: visible !important;
            }

            .report-container {
                width: 100% !important;
                max-width: 100% !important;
                padding: 0 !important;
                margin: 0 !important;
            }

            /* Print Header */
            .print-header {
                text-align: center;
                margin-bottom: 10px;
                padding: 8px;
                border: 2px solid #2c3e50 !important;
                border-radius: 4px;
                background: #f8f9fa !important;
                page-break-inside: avoid;
            }

            .print-header h1 {
                margin: 0 0 4px 0;
                font-size: 14pt !important;
                color: #2c3e50 !important;
                font-weight: bold;
                text-transform: uppercase;
            }

            .print-header .report-title {
                font-size: 12pt !important;
                font-weight: bold;
                margin: 2px 0;
                color: #34495e !important;
            }

            .print-header .report-period {
                font-size: 10pt !important;
                margin: 2px 0;
                color: #2980b9 !important;
                font-weight: 600;
            }

            .print-header .report-details {
                font-size: 8pt !important;
                color: #555 !important;
                margin: 1px 0;
            }

            /* Summary Cards */
            .summary-cards-print {
                display: flex !important;
                justify-content: space-between;
                margin: 8px 0;
                gap: 5px;
                page-break-inside: avoid;
            }

            .summary-card-print {
                border: 1px solid #3498db !important;
                border-radius: 3px;
                padding: 4px;
                text-align: center;
                width: 24%;
                font-size: 7pt !important;
                background: #ffffff !important;
            }

            .summary-card-print .label {
                font-weight: bold;
                margin-bottom: 1px;
                font-size: 6pt !important;
                text-transform: uppercase;
                color: #2c3e50 !important;
            }

            .summary-card-print .value {
                font-weight: bold;
                font-size: 9pt !important;
                color: #2980b9 !important;
            }

            /* Optimized Table */
            .table-container {
                width: 100% !important;
                overflow: visible !important;
                margin: 0 !important;
                padding: 0 !important;
                border: none !important;
            }

            .daily-table {
                width: 100% !important;
                font-size: 6pt !important;
                border-collapse: collapse !important;
                border: 1px solid #2c3e50 !important;
                margin: 0 !important;
                table-layout: auto !important;
            }

            .daily-table th, .daily-table td {
                padding: 1px 2px !important;
                border: 0.5px solid #34495e !important;
                text-align: center !important;
                vertical-align: middle !important;
                font-size: 6pt !important;
                white-space: nowrap !important;
            }

            .daily-table th {
                background: #2c3e50 !important;
                color: #ffffff !important;
                font-weight: bold !important;
                font-size: 6pt !important;
                text-transform: uppercase !important;
            }

            /* Column Widths */
            .sn-col { width: 15px !important; }
            .book-name-col { 
                width: 80px !important; 
                text-align: left !important; 
                padding-left: 2px !important;
                font-size: 5pt !important;
                word-wrap: break-word !important;
            }
            .code-col { width: 25px !important; }
            .class-col { width: 20px !important; }
            .type-col { width: 15px !important; }
            .day-col { width: 12px !important; }
            .total-col { width: 25px !important; }

            /* Section Headers */
            .section-header {
                background: #27ae60 !important;
                color: #ffffff !important;
                font-weight: bold !important;
                text-align: left !important;
                padding: 3px 5px !important;
                font-size: 7pt !important;
                text-transform: uppercase;
            }

            .non-translated-header { 
                background: #e74c3c !important;
                color: #ffffff !important;
            }

            /* Row Styling */
            .daily-table tbody tr:nth-child(even):not(.section-header):not(.section-total-row):not(.grand-total-row) {
                background: rgba(236, 240, 241, 0.3) !important;
            }

            /* Total Rows */
            .section-total-row td {
                font-weight: bold !important;
                background: #f8f9fa !important;
                color: #2c3e50 !important;
                font-size: 6pt !important;
            }

            .grand-total-row td {
                background: #343a40 !important;
                color: #ffffff !important;
                font-weight: bold !important;
                font-size: 7pt !important;
                text-transform: uppercase;
            }

            /* Print Footer */
            .print-footer {
                position: fixed;
                bottom: 5mm;
                left: 0;
                right: 0;
                text-align: center;
                font-size: 6pt !important;
                color: #666 !important;
                border-top: 1px solid #ddd !important;
                padding-top: 3px;
                background: white !important;
            }

            /* Page Break Rules */
            .section-header {
                page-break-after: avoid;
            }

            .section-total-row {
                page-break-after: avoid;
            }

            .grand-total-row {
                page-break-inside: avoid;
            }

            /* Hide zero values with dash for better readability */
            .zero-value {
                color: #ccc !important;
                font-style: italic;
            }
        }

        /* Screen Styles */
        h2 {
            text-align: center;
            color: var(--dark-color);
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 1px solid var(--border-color);
        }

        .filter-form {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            padding: 20px;
            background: #f8f9fa;
            border-radius: 8px;
            margin-bottom: 20px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.05);
        }

        .filter-group label {
            display: block;
            font-weight: 600;
            color: var(--dark-color);
            margin-bottom: 5px;
        }

        .filter-group select, .filter-group input, .filter-group button {
            width: 100%;
            padding: 10px 12px;
            border: 1px solid var(--border-color);
            border-radius: 6px;
            background: white;
            font-size: 14px;
            transition: all 0.3s;
        }

        .filter-group select:focus, .filter-group input:focus {
            border-color: var(--primary-color);
            box-shadow: 0 0 0 3px rgba(52, 152, 219, 0.2);
            outline: none;
        }

        .filter-group button {
            background: var(--primary-color);
            color: white;
            font-weight: 600;
            cursor: pointer;
            border: none;
            margin-top: 5px;
        }

        .filter-group button:hover {
            background: var(--secondary-color);
        }

        .filter-group a {
            display: block;
            text-align: center;
            padding: 10px;
            background: #f1f1f1;
            border-radius: 6px;
            color: var(--text-color);
            text-decoration: none;
            font-weight: 500;
            margin-top: 5px;
        }

        .filter-group a:hover {
            background: #e0e0e0;
        }

        .table-container {
            overflow-x: auto;
            max-height: calc(100vh - 400px);
            margin-bottom: 20px;
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.05);
            width: 100%;
        }

        .daily-table {
            width: auto;
            min-width: 100%;
            border-collapse: collapse;
            table-layout: auto;
        }

        .daily-table th, .daily-table td {
            border: 1px solid var(--border-color);
            padding: 8px;
            text-align: center;
            vertical-align: middle;
            white-space: nowrap;
        }

        .daily-table th {
            background: var(--dark-color);
            color: white;
            position: sticky;
            top: 0;
            z-index: 10;
            font-weight: 600;
        }

        .book-name-col {
            text-align: left;
            min-width: 220px;
            max-width: 300px;
            white-space: normal;
        }

        .section-header {
            font-weight: bold;
            text-align: left;
            padding: 10px 15px;
            font-size: 15px;
            position: sticky;
            top: 40px;
            z-index: 5;
        }

        .translated-header { 
            background: var(--success-color); 
            color: white; 
        }

        .non-translated-header { 
            background: var(--danger-color); 
            color: white; 
        }

        .section-total-row td, .grand-total-row td {
            font-weight: bold;
            background: var(--light-color) !important;
        }

        .export-section {
            background: #e8f5ff;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }

        .export-section a, .export-section button {
            padding: 10px 15px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: var(--primary-color);
            color: white;
            border: none;
            cursor: pointer;
            transition: background 0.3s;
        }

        .export-section a:hover, .export-section button:hover {
            background: var(--secondary-color);
        }

        .active-filters {
            background: #fff8e1;
            padding: 15px;
            border-radius: 8px;
            font-size: 14px;
            margin-bottom: 20px;
            display: flex;
            flex-wrap: wrap;
            gap: 10px;
            align-items: center;
        }

        .filter-tag {
            background: #ffeb3b;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 12px;
            font-weight: 500;
        }

        .summary-cards {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
            gap: 15px;
            margin: 20px 0;
        }

        .summary-card {
            padding: 15px;
            border-radius: 8px;
            background: white;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
            text-align: center;
        }

        .summary-card .label {
            font-size: 14px;
            color: #666;
            margin-bottom: 5px;
        }

        .summary-card .value {
            font-size: 24px;
            font-weight: bold;
            color: var(--primary-color);
        }

        .select2-container--default .select2-selection--multiple {
            border: 1px solid var(--border-color);
            border-radius: 6px;
            min-height: 42px;
            padding: 5px;
        }

        .loading-overlay {
            position: fixed;
            top: 0;
            left: 0;
            width: 100%;
            height: 100%;
            background: rgba(255,255,255,0.8);
            display: flex;
            justify-content: center;
            align-items: center;
            z-index: 9999;
            display: none;
        }

        .spinner {
            border: 5px solid #f3f3f3;
            border-top: 5px solid var(--primary-color);
            border-radius: 50%;
            width: 50px;
            height: 50px;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            0% { transform: rotate(0deg); }
            100% { transform: rotate(360deg); }
        }

        .search-container {
            position: relative;
        }

        .search-dropdown {
            position: absolute;
            top: 100%;
            left: 0;
            right: 0;
            background: white;
            border: 1px solid var(--border-color);
            border-top: none;
            border-radius: 0 0 6px 6px;
            max-height: 200px;
            overflow-y: auto;
            z-index: 1000;
            display: none;
        }

        .search-dropdown.show {
            display: block;
        }

        .search-option {
            padding: 10px 12px;
            cursor: pointer;
            border-bottom: 1px solid #eee;
        }

        .search-option:hover {
            background: #f5f5f5;
        }

        .search-option:last-child {
            border-bottom: none;
        }
    </style>
</head>
<body>

<!-- Loading Overlay -->
<div class="loading-overlay no-print">
    <div class="spinner"></div>
</div>

<div class="report-container">

    <h2 class="no-print">Daily Production Report - <?= $nepali_months[$selected_month] ?> <?= $year ?></h2>

    <!-- Filters -->
    <form method="GET" class="filter-form no-print" id="reportFilterForm">
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
                    <option value="<?= $k ?>" <?= $k == $selected_month ? 'selected' : '' ?>><?= $v ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label>Report Type:</label>
            <select name="report_type">
                <option value="production" <?= $report_type == 'production' ? 'selected' : '' ?>>Production</option>
                <option value="openpcs" <?= $report_type == 'openpcs' ? 'selected' : '' ?>>Open Pcs</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Translation:</label>
            <select name="translation_filter">
                <option value="all" <?= $translation_filter == 'all' ? 'selected' : '' ?>>All Books</option>
                <option value="translated" <?= $translation_filter == 'translated' ? 'selected' : '' ?>>Translated Only</option>
                <option value="non_translated" <?= $translation_filter == 'non_translated' ? 'selected' : '' ?>>Non-Translated Only</option>
            </select>
        </div>
        <div class="filter-group">
            <label>Search Book:</label>
            <div class="search-container">
                <input type="text" 
                       name="search_text" 
                       id="searchInput"
                       value="<?= htmlspecialchars($search_text) ?>" 
                       placeholder="Type to search books..."
                       autocomplete="off">
                <div class="search-dropdown" id="searchDropdown"></div>
            </div>
        </div>
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
                        <?= htmlspecialchars($b['book_name']) ?> (<?= $b['book_code'] ?>) - Class <?= $b['class_level'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <button type="submit">Apply Filters</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>">Reset All</a>
        </div>
    </form>

    <!-- Active Filters -->
    <?php if ($translation_filter != 'all' || $search_text || $selected_books || $selected_classes): ?>
    <div class="active-filters no-print">
        <strong>Active Filters:</strong>
        <span class="filter-tag"><?= $nepali_months[$selected_month] ?> <?= $year ?></span>
        <?php if ($translation_filter != 'all'): ?>
            <span class="filter-tag"><?= ucfirst(str_replace('_', ' ', $translation_filter)) ?></span>
        <?php endif; ?>
        <?php if ($search_text): ?>
            <span class="filter-tag">Search: "<?= htmlspecialchars($search_text) ?>"</span>
        <?php endif; ?>
        <?php if ($selected_classes): ?>
            <span class="filter-tag">Classes: <?= implode(', ', $selected_classes) ?></span>
        <?php endif; ?>
        <?php if ($selected_books): ?>
            <span class="filter-tag">Books: <?= count($selected_books) ?> selected</span>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Summary Cards -->
    <div class="summary-cards no-print">
        <div class="summary-card">
            <div class="label">Total Books</div>
            <div class="value"><?= count($translated_data) + count($non_translated_data) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Total <?= ucfirst($report_type) ?></div>
            <div class="value"><?= number_format($report_type === 'openpcs' ? $grand_total_openpcs : $grand_total_produced) ?></div>
        </div>
        <div class="summary-card">
            <div class="label">Translated Books</div>
            <div class="value"><?= count($translated_data) ?></div>
            <div style="font-size: 14px; color: #666;"><?= number_format($report_type === 'openpcs' ? $translated_total_openpcs : $translated_total_produced) ?> units</div>
        </div>
        <div class="summary-card">
            <div class="label">Non-Translated Books</div>
            <div class="value"><?= count($non_translated_data) ?></div>
            <div style="font-size: 14px; color: #666;"><?= number_format($report_type === 'openpcs' ? $non_translated_total_openpcs : $non_translated_total_produced) ?> units</div>
        </div>
    </div>

    <!-- Export Buttons -->
    <div class="export-section no-print">
        <a href="?<?= http_build_query($_GET + ['export' => 'csv']) ?>">
            <i class="fas fa-file-csv"></i> Export as CSV
        </a>
        <a href="?<?= http_build_query($_GET + ['export' => 'excel']) ?>">
            <i class="fas fa-file-excel"></i> Export as Excel
        </a>
        <button onclick="printReport()">
            <i class="fas fa-print"></i> Print Report
        </button>
    </div>

    <!-- Print Header (Only visible when printing) -->
    <div class="print-only print-header">
        <h1>Janak Education Materials Center</h1>
        <div class="report-title">Daily Production Report</div>
        <div class="report-period"><?= $nepali_months[$selected_month] ?> <?= $year ?></div>
        <div class="report-details">Report Type: <?= ucfirst($report_type) ?></div>
        <div class="report-details">Generated on: <?= date('Y-m-d H:i:s') ?></div>
        <?php if ($translation_filter != 'all' || $search_text || $selected_books || $selected_classes): ?>
            <div class="report-details">
                Filters: 
                <?php if ($translation_filter != 'all') echo ucfirst(str_replace('_', ' ', $translation_filter)) . ' | '; ?>
                <?php if ($search_text) echo 'Search: "' . htmlspecialchars($search_text) . '" | '; ?>
                <?php if ($selected_classes) echo 'Classes: ' . implode(', ', $selected_classes) . ' | '; ?>
                <?php if ($selected_books) echo count($selected_books) . ' books selected'; ?>
            </div>
        <?php endif; ?>
    </div>

    <!-- Print Summary Cards (Only visible when printing) -->
    <div class="print-only summary-cards-print">
        <div class="summary-card-print">
            <div class="label">Total Books</div>
            <div class="value"><?= count($translated_data) + count($non_translated_data) ?></div>
        </div>
        <div class="summary-card-print">
            <div class="label">Total <?= ucfirst($report_type) ?></div>
            <div class="value"><?= number_format($report_type === 'openpcs' ? $grand_total_openpcs : $grand_total_produced) ?></div>
        </div>
        <div class="summary-card-print">
            <div class="label">Translated</div>
            <div class="value"><?= number_format($report_type === 'openpcs' ? $translated_total_openpcs : $translated_total_produced) ?></div>
            <div>(<?= count($translated_data) ?> books)</div>
        </div>
        <div class="summary-card-print">
            <div class="label">Non-Translated</div>
            <div class="value"><?= number_format($report_type === 'openpcs' ? $non_translated_total_openpcs : $non_translated_total_produced) ?></div>
            <div>(<?= count($non_translated_data) ?> books)</div>
        </div>
    </div>

    <!-- Table Container -->
    <div class="table-container">
        <table class="daily-table">
            <thead>
                <tr>
                    <th class="sn-col">SN</th>
                    <th class="book-name-col">Book Name</th>
                    <th class="code-col">Code</th>
                    <th class="class-col">Class</th>
                    <th class="type-col">Type</th>
                    <?php for ($i = 1; $i <= 32; $i++): ?>
                        <th class="day-col"><?= $i ?></th>
                    <?php endfor; ?>
                    <th class="total-col">Total</th>
                </tr>
            </thead>
            <tbody>
                <?php if ($translated_data): ?>
                <tr><td colspan="38" class="section-header translated-header">TRANSLATED BOOKS (<?= count($translated_data) ?>)</td></tr>
                <?php $sn = 1; foreach ($translated_data as $b): ?>
                <tr>
                    <td><?= $sn++ ?></td>
                    <td class="book-name-col"><?= htmlspecialchars($b['book_name']) ?></td>
                    <td><?= $b['book_code'] ?></td>
                    <td><?= $b['class_level'] ?></td>
                    <td>T</td>
                    <?php for ($i = 1; $i <= 32; $i++): 
                        $d = $b['days'][sprintf('%02d', $i)];
                        $val = $report_type === 'openpcs' ? $d['total_openpcs'] : $d['total_produced'];
                    ?>
                        <td<?= $val == 0 ? ' class="zero-value"' : '' ?>><?= $val > 0 ? number_format($val) : '-' ?></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($report_type === 'openpcs' ? $b['total_openpcs'] : $b['total_produced']) ?></b></td>
                </tr>
                <?php endforeach; ?>
                <tr class="section-total-row">
                    <td colspan="5"><b>Daily Translated Total</b></td>
                    <?php for ($i = 1; $i <= 32; $i++): 
                        $day_key = sprintf('%02d', $i);
                        $val = $daily_totals[$day_key]['translated'] ?? 0;
                    ?>
                        <td><b><?= $val > 0 ? number_format($val) : '-' ?></b></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($report_type === 'openpcs' ? $translated_total_openpcs : $translated_total_produced) ?></b></td>
                </tr>
                <?php endif; ?>

                <?php if ($non_translated_data): ?>
                <tr><td colspan="38" class="section-header non-translated-header">NON-TRANSLATED BOOKS (<?= count($non_translated_data) ?>)</td></tr>
                <?php $sn = 1; foreach ($non_translated_data as $b): ?>
                <tr>
                    <td><?= $sn++ ?></td>
                    <td class="book-name-col"><?= htmlspecialchars($b['book_name']) ?></td>
                    <td><?= $b['book_code'] ?></td>
                    <td><?= $b['class_level'] ?></td>
                    <td>NT</td>
                    <?php for ($i = 1; $i <= 32; $i++): 
                        $d = $b['days'][sprintf('%02d', $i)];
                        $val = $report_type === 'openpcs' ? $d['total_openpcs'] : $d['total_produced'];
                    ?>
                        <td<?= $val == 0 ? ' class="zero-value"' : '' ?>><?= $val > 0 ? number_format($val) : '-' ?></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($report_type === 'openpcs' ? $b['total_openpcs'] : $b['total_produced']) ?></b></td>
                </tr>
                <?php endforeach; ?>
                <tr class="section-total-row">
                    <td colspan="5"><b>Daily Non-Translated Total</b></td>
                    <?php for ($i = 1; $i <= 32; $i++): 
                        $day_key = sprintf('%02d', $i);
                        $val = $daily_totals[$day_key]['non_translated'] ?? 0;
                    ?>
                        <td><b><?= $val > 0 ? number_format($val) : '-' ?></b></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($report_type === 'openpcs' ? $non_translated_total_openpcs : $non_translated_total_produced) ?></b></td>
                </tr>
                <?php endif; ?>

                <tr class="grand-total-row">
                    <td colspan="5"><b>GRAND TOTAL</b></td>
                    <?php for ($i = 1; $i <= 32; $i++): 
                        $day_key = sprintf('%02d', $i);
                        $val = $report_type === 'openpcs' 
                            ? ($daily_totals[$day_key]['total_openpcs'] ?? 0) 
                            : ($daily_totals[$day_key]['total_produced'] ?? 0);
                    ?>
                        <td><b><?= $val > 0 ? number_format($val) : '-' ?></b></td>
                    <?php endfor; ?>
                    <td><b><?= number_format($report_type === 'openpcs' ? $grand_total_openpcs : $grand_total_produced) ?></b></td>
                </tr>
            </tbody>
        </table>
    </div>

    <div style="margin-top:20px; font-size:12px; color:#666;" class="no-print">
        Report generated on <?= date('Y-m-d H:i:s') ?>
    </div>

    <!-- Print Footer (Only visible when printing) -->
    <div class="print-only print-footer">
        <div>Developed and Maintained by: IT Section, JEMC | Generated on: <?= date('Y-m-d H:i:s') ?></div>
    </div>

</div>

<!-- Scripts -->
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<script>
    const nepaliMonths = <?= json_encode($nepali_months) ?>;
    const selectedMonth = "<?= addslashes($selected_month) ?>";
    const reportYear = "<?= addslashes($year) ?>";
    const monthName = nepaliMonths[selectedMonth] || selectedMonth;
    const allBooks = <?= json_encode($all_books) ?>;

    // Enhanced search functionality
    function initializeSearch() {
        const searchInput = document.getElementById('searchInput');
        const searchDropdown = document.getElementById('searchDropdown');
        
        if (!searchInput || !searchDropdown) return;

        searchInput.addEventListener('input', function() {
            const query = this.value.toLowerCase().trim();
            
            if (query.length < 2) {
                searchDropdown.classList.remove('show');
                return;
            }

            const filteredBooks = allBooks.filter(book => 
                book.book_name.toLowerCase().includes(query) || 
                book.book_code.toLowerCase().includes(query)
            ).slice(0, 10);

            if (filteredBooks.length > 0) {
                searchDropdown.innerHTML = filteredBooks.map(book => 
                    `<div class="search-option" onclick="selectBook('${book.book_code}', '${book.book_name.replace(/'/g, "\\'")}')">
                        <strong>${book.book_name}</strong><br>
                        <small>Code: ${book.book_code} | Class: ${book.class_level} | ${book.is_translated ? 'Translated' : 'Non-Translated'}</small>
                    </div>`
                ).join('');
                searchDropdown.classList.add('show');
            } else {
                searchDropdown.innerHTML = '<div class="search-option">No books found</div>';
                searchDropdown.classList.add('show');
            }
        });

        document.addEventListener('click', function(e) {
            if (!searchInput.contains(e.target) && !searchDropdown.contains(e.target)) {
                searchDropdown.classList.remove('show');
            }
        });
    }

    function selectBook(bookCode, bookName) {
        const searchInput = document.getElementById('searchInput');
        const searchDropdown = document.getElementById('searchDropdown');
        const booksSelect = $('.select2-books');
        
        searchInput.value = bookName;
        booksSelect.val([bookCode]).trigger('change');
        searchDropdown.classList.remove('show');
    }

    // Optimized Print Function
function printReport() {
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    
    // Get current report parameters
    const monthName = "<?= $nepali_months[$selected_month] ?>";
    const year = "<?= $year ?>";
    const reportType = "<?= ucfirst($report_type) ?>";
    
    // Create optimized print content
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <meta charset="UTF-8">
            <title>Daily Production Report - ${monthName} ${year}</title>
            <style>
                @page {
                    size: A4 landscape;
                    margin: 0;
                }
                body {
                    font-family: Arial, sans-serif;
                    margin: 0;
                    padding: 0;
                    color: #000;
                    font-size: 10pt;
                }
                .header {
                    text-align: center;
                    padding: 15px 0;
                    background-color: #000;
                    color: white;
                    width: 100%;
                }
                .report-title {
                    font-size: 16pt;
                    margin: 5px 0;
                    font-weight: bold;
                }
                .report-period {
                    font-size: 14pt;
                    margin: 5px 0;
                    font-weight: bold;
                }
                .table-container {
                    width: 100%;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin: 0;
                    table-layout: fixed;
                }
                th, td {
                    border: 1px solid #000;
                    padding: 6px 4px;
                    text-align: center;
                    vertical-align: middle;
                    font-size: 9pt;
                }
                th {
                    background-color: #000;
                    color: white;
                    font-weight: bold;
                    font-size: 10pt;
                }
                .book-name-col {
                    text-align: left;
                    padding-left: 8px;
                }
                .section-header {
                    background-color: #000 !important;
                    color: white !important;
                    font-weight: bold !important;
                    text-align: left !important;
                    padding: 8px 10px !important;
                    font-size: 11pt !important;
                    text-transform: uppercase;
                    border: 2px solid #000 !important;
                }
                .non-translated-header { 
                    background-color: #000 !important;
                    color: white !important;
                    border: 2px solid #000 !important;
                }
                .grand-total-row td {
                    background-color: #000 !important;
                    color: #ffffff !important;
                    font-weight: bold !important;
                    font-size: 11pt !important;
                    text-transform: uppercase;
                    border: 2px solid #ffffff !important;
                }
                .section-total-row td {
                    font-weight: bold;
                    background-color: #333 !important;
                    color: #ffffff !important;
                    border: 1px solid #000 !important;
                }
                .zero-value {
                    color: #000;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="report-title">JANAK EDUCATION MATERIALS CENTER</div>
                <div class="report-title">Daily Production Report</div>
                <div class="report-period">${monthName} ${year}</div>
            </div>
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>SN</th>
                            <th class="book-name-col">Book Name</th>
                            <th>Code</th>
                            <th>Class</th>
                            <th>Type</th>
                            ${Array.from({length: 32}, (_, i) => `<th>${i + 1}</th>`).join('')}
                            <th>Total</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if ($translated_data): ?>
                        <tr>
                            <td colspan="38" class="section-header">TRANSLATED BOOKS (<?= count($translated_data) ?>)</td>
                        </tr>
                        <?php $sn = 1; foreach ($translated_data as $b): ?>
                        <tr>
                            <td><?= $sn++ ?></td>
                            <td class="book-name-col"><?= htmlspecialchars($b['book_name']) ?></td>
                            <td><?= $b['book_code'] ?></td>
                            <td><?= $b['class_level'] ?></td>
                            <td>T</td>
                            <?php for ($i = 1; $i <= 32; $i++): 
                                $d = $b['days'][sprintf('%02d', $i)];
                                $val = $report_type === 'openpcs' ? $d['total_openpcs'] : $d['total_produced'];
                            ?>
                                <td<?= $val == 0 ? ' class="zero-value"' : '' ?>><?= $val > 0 ? number_format($val) : '-' ?></td>
                            <?php endfor; ?>
                            <td><b><?= number_format($report_type === 'openpcs' ? $b['total_openpcs'] : $b['total_produced']) ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="section-total-row">
                            <td colspan="5"><b>Daily Translated Total</b></td>
                            <?php for ($i = 1; $i <= 32; $i++): 
                                $day_key = sprintf('%02d', $i);
                                $val = $daily_totals[$day_key]['translated'] ?? 0;
                            ?>
                                <td><b><?= $val > 0 ? number_format($val) : '-' ?></b></td>
                            <?php endfor; ?>
                            <td><b><?= number_format($report_type === 'openpcs' ? $translated_total_openpcs : $translated_total_produced) ?></b></td>
                        </tr>
                        <?php endif; ?>
                        <?php if ($non_translated_data): ?>
                        <tr>
                            <td colspan="38" class="section-header">NON-TRANSLATED BOOKS (<?= count($non_translated_data) ?>)</td>
                        </tr>
                        <?php $sn = 1; foreach ($non_translated_data as $b): ?>
                        <tr>
                            <td><?= $sn++ ?></td>
                            <td class="book-name-col"><?= htmlspecialchars($b['book_name']) ?></td>
                            <td><?= $b['book_code'] ?></td>
                            <td><?= $b['class_level'] ?></td>
                            <td>NT</td>
                            <?php for ($i = 1; $i <= 32; $i++): 
                                $d = $b['days'][sprintf('%02d', $i)];
                                $val = $report_type === 'openpcs' ? $d['total_openpcs'] : $d['total_produced'];
                            ?>
                                <td<?= $val == 0 ? ' class="zero-value"' : '' ?>><?= $val > 0 ? number_format($val) : '-' ?></td>
                            <?php endfor; ?>
                            <td><b><?= number_format($report_type === 'openpcs' ? $b['total_openpcs'] : $b['total_produced']) ?></b></td>
                        </tr>
                        <?php endforeach; ?>
                        <tr class="section-total-row">
                            <td colspan="5"><b>Daily Non-Translated Total</b></td>
                            <?php for ($i = 1; $i <= 32; $i++): 
                                $day_key = sprintf('%02d', $i);
                                $val = $daily_totals[$day_key]['non_translated'] ?? 0;
                            ?>
                                <td><b><?= $val > 0 ? number_format($val) : '-' ?></b></td>
                            <?php endfor; ?>
                            <td><b><?= number_format($report_type === 'openpcs' ? $non_translated_total_openpcs : $non_translated_total_produced) ?></b></td>
                        </tr>
                        <?php endif; ?>
                        <tr class="grand-total-row">
                            <td colspan="5"><b>GRAND TOTAL</b></td>
                            <?php for ($i = 1; $i <= 32; $i++): 
                                $day_key = sprintf('%02d', $i);
                                $val = $report_type === 'openpcs' 
                                    ? ($daily_totals[$day_key]['total_openpcs'] ?? 0) 
                                    : ($daily_totals[$day_key]['total_produced'] ?? 0);
                            ?>
                                <td><b><?= $val > 0 ? number_format($val) : '-' ?></b></td>
                            <?php endfor; ?>
                            <td><b><?= number_format($report_type === 'openpcs' ? $grand_total_openpcs : $grand_total_produced) ?></b></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </body>
        </html>
    `;
    
    // Write content to the print window
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    // Print after content is loaded
    printWindow.onload = function() {
        printWindow.print();
        setTimeout(() => {
            printWindow.close();
        }, 1000);
    };
}
    // Initialize everything when document is ready
    $(document).ready(function() {
        $('.select2-classes, .select2-books').select2({
            placeholder: "Select options...",
            allowClear: true
        });

        initializeSearch();

        $('#reportFilterForm').on('submit', function() {
            $('.loading-overlay').show();
        });

        $('.select2-books').on('change', function() {
            const selectedValues = $(this).val();
            if (selectedValues && selectedValues.length === 1) {
                const selectedBook = allBooks.find(book => book.book_code === selectedValues[0]);
                if (selectedBook) {
                    $('#searchInput').val(selectedBook.book_name);
                }
            } else if (!selectedValues || selectedValues.length === 0) {
                $('#searchInput').val('');
            }
        });
    });
</script>

</body>
</html>
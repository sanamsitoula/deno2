<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

$type = isset($_GET['type']) ? $_GET['type'] : 'daily';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : '';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : '';
$export = isset($_GET['export']) ? $_GET['export'] : '';

try {
    $result = [];
    
    switch ($type) {
        case 'daily':
            $result = getDailyReport($conn);
            break;
        case 'monthly':
            $result = getMonthlyReport($conn);
            break;
        case 'daterange':
            $result = getDateRangeReport($conn, $start_date, $end_date);
            break;
        case 'bookwise':
            $result = getBookwiseReport($conn, $start_date, $end_date);
            break;
        case 'classwise':
            $result = getClasswiseReport($conn, $start_date, $end_date);
            break;
        case 'userwise':
            $result = getUserwiseReport($conn, $start_date, $end_date);
            break;
        case 'refwise':
            $result = getRefwiseReport($conn, $start_date, $end_date);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid report type']);
            exit;
    }
    
    // Export to Excel if requested
    if ($export === 'excel') {
        exportToExcel($result, $type);
        exit;
    }
    
    echo json_encode($result);
    
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Error: ' . $e->getMessage()
    ]);
}

// Daily Report
function getDailyReport($conn) {
    $today = date('Y.m.d');
    
    $query = "
        SELECT 
            deno_date_nep as date,
            COUNT(*) as record_count,
            SUM(poka_qty) as total_poka_qty,
            SUM(total_qty) as total_quantity,
            SUM(quantity_openpcs) as total_open_pcs
        FROM deno
        WHERE deno_date_eng = :today
        GROUP BY deno_date_nep
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':today' => $today]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = calculateSummary($data);
    
    return [
        'success' => true,
        'type' => 'daily',
        'summary' => $summary,
        'data' => $data
    ];
}

// Monthly Report
function getMonthlyReport($conn) {
    $current_month = date('Y.m');
    
    $query = "
        SELECT 
            deno_date_nep as date,
            COUNT(*) as record_count,
            SUM(poka_qty) as total_poka_qty,
            SUM(total_qty) as total_quantity,
            SUM(quantity_openpcs) as total_open_pcs
        FROM deno
        WHERE deno_date_eng LIKE :month
        GROUP BY deno_date_nep
        ORDER BY deno_date_nep DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([':month' => $current_month . '%']);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = calculateSummary($data);
    
    return [
        'success' => true,
        'type' => 'monthly',
        'summary' => $summary,
        'data' => $data
    ];
}

// Date Range Report
function getDateRangeReport($conn, $start_date, $end_date) {
    if (empty($start_date) || empty($end_date)) {
        throw new Exception('Start date and end date are required');
    }
    
    $query = "
        SELECT 
            deno_date_nep as date,
            COUNT(*) as record_count,
            SUM(poka_qty) as total_poka_qty,
            SUM(total_qty) as total_quantity,
            SUM(quantity_openpcs) as total_open_pcs
        FROM deno
        WHERE deno_date_eng BETWEEN :start_date AND :end_date
        GROUP BY deno_date_nep
        ORDER BY deno_date_nep DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute([
        ':start_date' => $start_date,
        ':end_date' => $end_date
    ]);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = calculateSummary($data);
    
    return [
        'success' => true,
        'type' => 'daterange',
        'summary' => $summary,
        'data' => $data
    ];
}

// Book-wise Report
function getBookwiseReport($conn, $start_date, $end_date) {
    $date_condition = "";
    $params = [];
    
    if (!empty($start_date) && !empty($end_date)) {
        $date_condition = "WHERE d.deno_date_eng BETWEEN :start_date AND :end_date";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
    }
    
    $query = "
        SELECT 
            d.book_code,
            b.book_name,
            COUNT(*) as record_count,
            SUM(d.poka_qty) as total_poka_qty,
            SUM(d.total_qty) as total_quantity,
            SUM(d.quantity_openpcs) as total_open_pcs
        FROM deno d
        LEFT JOIN books b ON d.book_code = b.book_code
        $date_condition
        GROUP BY d.book_code, b.book_name
        ORDER BY total_quantity DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = calculateSummary($data);
    
    return [
        'success' => true,
        'type' => 'bookwise',
        'summary' => $summary,
        'data' => $data
    ];
}

// Class-wise Report
function getClasswiseReport($conn, $start_date, $end_date) {
    $date_condition = "";
    $params = [];
    
    if (!empty($start_date) && !empty($end_date)) {
        $date_condition = "WHERE d.deno_date_eng BETWEEN :start_date AND :end_date";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
    }
    
    $query = "
        SELECT 
            b.class_level,
            COUNT(*) as record_count,
            SUM(d.poka_qty) as total_poka_qty,
            SUM(d.total_qty) as total_quantity,
            SUM(d.quantity_openpcs) as total_open_pcs
        FROM deno d
        LEFT JOIN books b ON d.book_code = b.book_code
        $date_condition
        GROUP BY b.class_level
        ORDER BY b.class_level ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = calculateSummary($data);
    
    return [
        'success' => true,
        'type' => 'classwise',
        'summary' => $summary,
        'data' => $data
    ];
}

// User-wise Report
function getUserwiseReport($conn, $start_date, $end_date) {
    $date_condition = "";
    $params = [];
    
    if (!empty($start_date) && !empty($end_date)) {
        $date_condition = "WHERE deno_date_eng BETWEEN :start_date AND :end_date";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
    }
    
    $query = "
        SELECT 
            created_by,
            COUNT(*) as record_count,
            SUM(poka_qty) as total_poka_qty,
            SUM(total_qty) as total_quantity,
            SUM(quantity_openpcs) as total_open_pcs
        FROM deno
        $date_condition
        GROUP BY created_by
        ORDER BY total_quantity DESC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = calculateSummary($data);
    
    return [
        'success' => true,
        'type' => 'userwise',
        'summary' => $summary,
        'data' => $data
    ];
}

// Reference-wise Report
function getRefwiseReport($conn, $start_date, $end_date) {
    $date_condition = "";
    $params = [];
    
    if (!empty($start_date) && !empty($end_date)) {
        $date_condition = "WHERE deno_date_eng BETWEEN :start_date AND :end_date";
        $params = [':start_date' => $start_date, ':end_date' => $end_date];
    }
    
    $query = "
        SELECT 
            ref_no,
            COUNT(*) as record_count,
            SUM(poka_qty) as total_poka_qty,
            SUM(total_qty) as total_quantity,
            SUM(quantity_openpcs) as total_open_pcs
        FROM deno
        $date_condition
        GROUP BY ref_no
        ORDER BY ref_no ASC
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $summary = calculateSummary($data);
    
    return [
        'success' => true,
        'type' => 'refwise',
        'summary' => $summary,
        'data' => $data
    ];
}

// Calculate Summary
function calculateSummary($data) {
    $total_records = 0;
    $total_poka = 0;
    $total_quantity = 0;
    $total_open = 0;
    
    foreach ($data as $row) {
        $total_records += $row['record_count'];
        $total_poka += $row['total_poka_qty'];
        $total_quantity += $row['total_quantity'];
        $total_open += $row['total_open_pcs'];
    }
    
    return [
        'total_records' => $total_records,
        'total_poka' => $total_poka,
        'total_quantity' => $total_quantity,
        'total_open' => $total_open
    ];
}

// Export to Excel
function exportToExcel($result, $type) {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="report_' . $type . '_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    echo "<table border='1'>";
    
    // Headers based on report type
    if ($type === 'bookwise') {
        echo "<tr><th>Book Code</th><th>Book Name</th><th>Records</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th></tr>";
    } elseif ($type === 'classwise') {
        echo "<tr><th>Class Level</th><th>Records</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th></tr>";
    } elseif ($type === 'userwise') {
        echo "<tr><th>Created By</th><th>Records</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th></tr>";
    } elseif ($type === 'refwise') {
        echo "<tr><th>Reference No</th><th>Records</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th></tr>";
    } else {
        echo "<tr><th>Date</th><th>Records</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th></tr>";
    }
    
    // Data rows
    foreach ($result['data'] as $row) {
        echo "<tr>";
        if ($type === 'bookwise') {
            echo "<td>" . $row['book_code'] . "</td>";
            echo "<td>" . $row['book_name'] . "</td>";
        } elseif ($type === 'classwise') {
            echo "<td>Class " . $row['class_level'] . "</td>";
        } elseif ($type === 'userwise') {
            echo "<td>" . $row['created_by'] . "</td>";
        } elseif ($type === 'refwise') {
            echo "<td>" . $row['ref_no'] . "</td>";
        } else {
            echo "<td>" . $row['date'] . "</td>";
        }
        echo "<td>" . number_format($row['record_count']) . "</td>";
        echo "<td>" . number_format($row['total_poka_qty']) . "</td>";
        echo "<td>" . number_format($row['total_quantity']) . "</td>";
        echo "<td>" . number_format($row['total_open_pcs']) . "</td>";
        echo "</tr>";
    }
    
    // Summary row
    echo "<tr style='background-color: #FFE082; font-weight: bold;'>";
    echo "<td>TOTAL</td>";
    if ($type === 'bookwise') echo "<td></td>";
    echo "<td>" . number_format($result['summary']['total_records']) . "</td>";
    echo "<td>" . number_format($result['summary']['total_poka']) . "</td>";
    echo "<td>" . number_format($result['summary']['total_quantity']) . "</td>";
    echo "<td>" . number_format($result['summary']['total_open']) . "</td>";
    echo "</tr>";
    
    echo "</table>";
}
?>
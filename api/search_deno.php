<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

// Get search parameters
$book_code = isset($_GET['book_code']) ? trim($_GET['book_code']) : '';
$class_level = isset($_GET['class_level']) ? trim($_GET['class_level']) : '';
$translated = isset($_GET['translated']) ? trim($_GET['translated']) : '';
$ref_no = isset($_GET['ref_no']) ? trim($_GET['ref_no']) : '';
$start_date = isset($_GET['start_date']) ? trim($_GET['start_date']) : '2082.04.01';
$end_date = isset($_GET['end_date']) ? trim($_GET['end_date']) : '2083.03.32';

try {
    // Build query
    $query = "
        SELECT 
            d.id,
            d.book_code,
            b.book_name,
            d.ref_no,
            d.deno_date_nep,
            d.deno_date_eng,
            d.per_poka_qty,
            d.poka_qty,
            d.total_qty,
            d.quantity_openpcs,
            b.class_level,
            b.is_translated,
            d.created_by,
            d.received_by,
            d.verify_by,
            d.notes,
            d.update_remarks,
            d.created_at
        FROM deno d
        LEFT JOIN books b ON d.book_code = b.book_code
        WHERE 1=1
    ";
    
    $params = [];
    
    // Add search conditions
    if (!empty($book_code)) {
        $query .= " AND d.book_code = :book_code";
        $params[':book_code'] = $book_code;
    }
    
    if (!empty($class_level)) {
        $query .= " AND b.class_level = :class_level";
        $params[':class_level'] = $class_level;
    }
    
    if ($translated !== '') {
        $query .= " AND b.is_translated = :translated";
        $params[':translated'] = ($translated === 'yes') ? true : false;
    }
    
    if (!empty($ref_no)) {
        $query .= " AND d.ref_no LIKE :ref_no";
        $params[':ref_no'] = '%' . $ref_no . '%';
    }
    
    // Add date range
    $query .= " AND d.deno_date_nep BETWEEN :start_date AND :end_date";
    $params[':start_date'] = $start_date;
    $params[':end_date'] = $end_date;
    
    $query .= " ORDER BY d.deno_date_nep DESC, d.created_at DESC";
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate totals
    $total_poka_qty = 0;
    $total_quantity = 0;
    $total_open_pcs = 0;
    
    foreach ($records as $record) {
        $total_poka_qty += $record['poka_qty'];
        $total_quantity += $record['total_qty'];
        $total_open_pcs += $record['quantity_openpcs'];
    }
    
    echo json_encode([
        'success' => true,
        'data' => $records,
        'summary' => [
            'total_records' => count($records),
            'total_poka_qty' => $total_poka_qty,
            'total_quantity' => $total_quantity,
            'total_open_pcs' => $total_open_pcs
        ]
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

// Pagination parameters
$page = isset($_GET['page']) ? intval($_GET['page']) : 1;
$limit = isset($_GET['limit']) ? intval($_GET['limit']) : 50;
$offset = ($page - 1) * $limit;

try {
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
        ORDER BY d.deno_date_nep DESC, d.created_at DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($query);
    $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get total count for pagination info
    $countStmt = $conn->query("SELECT COUNT(*) as total FROM deno");
    $total = $countStmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    echo json_encode([
        'success' => true,
        'data' => $records,
        'pagination' => [
            'current_page' => $page,
            'total_records' => $total,
            'total_pages' => ceil($total / $limit),
            'per_page' => $limit
        ]
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
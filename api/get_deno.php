<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../config/database.php';

$id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT 
            d.*,
            b.book_name,
            b.class_level,
            b.is_translated
        FROM deno d
        LEFT JOIN books b ON d.book_code = b.book_code
        WHERE d.id = :id
    ");
    
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($record) {
        echo json_encode($record);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Record not found'
        ]);
    }
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
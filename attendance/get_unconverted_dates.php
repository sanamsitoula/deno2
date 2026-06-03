<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

try {
    $stmt = $conn->query("
        SELECT id, attendance_date_nep, attendance_date_eng 
        FROM attendance 
        WHERE attendance_date_eng = '2025-01-01' 
        OR attendance_date_eng IS NULL
        LIMIT 1000
    ");
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($records);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()]);
}
?>

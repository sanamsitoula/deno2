<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed']);
    exit();
}

$id = $_POST['id'] ?? null;
$date_eng = $_POST['date_eng'] ?? null;

if (!$id || !$date_eng) {
    echo json_encode(['success' => false, 'error' => 'Missing parameters']);
    exit();
}

try {
    // Convert date format from YYYY.MM.DD to YYYY-MM-DD for database
    $date_eng_db = str_replace('.', '-', $date_eng);
    
    $stmt = $conn->prepare("
        UPDATE attendance 
        SET attendance_date_eng = :date_eng 
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':date_eng' => $date_eng_db,
        ':id' => $id
    ]);
    
    echo json_encode(['success' => true]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => $e->getMessage()]);
}
?>

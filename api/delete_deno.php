<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$username = isset($_POST['username']) ? trim($_POST['username']) : '';

if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid ID']);
    exit;
}

try {
    // Check if record exists
    $check_stmt = $conn->prepare("SELECT id FROM deno WHERE id = :id");
    $check_stmt->execute([':id' => $id]);
    
    if (!$check_stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => 'Record not found'
        ]);
        exit;
    }
    
    // Delete record
    $stmt = $conn->prepare("DELETE FROM deno WHERE id = :id");
    $stmt->execute([':id' => $id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Deno record deleted successfully'
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
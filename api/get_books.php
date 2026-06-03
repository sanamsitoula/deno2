<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');

require_once '../config/database.php';

try {
    $query = "SELECT book_id, book_code, book_name, class_level, is_translated, is_optional 
              FROM books 
              ORDER BY book_name ASC";
    
    $stmt = $conn->query($query);
    $books = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo json_encode($books);
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
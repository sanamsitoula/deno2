<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (!isset($_GET['book_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$bookId = $_GET['book_id'];

$formas = $conn->query("
    SELECT f.id, f.name 
    FROM forma f
    JOIN books bf ON f.book_id = bf.book_id
    WHERE bf.book_id = $bookId
    ORDER BY f.order_no
")->fetchAll();

echo json_encode([
    'success' => true,
    'formas' => $formas
]);
?>
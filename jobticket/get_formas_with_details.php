<?php
// jobticket/get_formas_with_details.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$bookId = $_GET['book_id'] ?? null;

if (!$bookId) {
    echo json_encode(['success' => false, 'message' => 'Missing book_id parameter']);
    exit;
}

try {
    // Get formas for the book ordered by order_no ASC
    $stmt = $conn->prepare("
        SELECT 
            f.id, 
            f.name, 
            f.page, 
            f.order_no,
            f.remarks as machine
        FROM forma f
        WHERE f.book_id = ?
        ORDER BY f.order_no ASC
    ");
    $stmt->execute([$bookId]);
    $formas = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'formas' => $formas,
        'count' => count($formas)
    ]);
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}
?>
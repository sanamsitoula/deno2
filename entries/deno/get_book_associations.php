<?php
// ============================================
// File 1: get_book_associations.php
// ============================================
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$book_code = $_GET['book_code'] ?? '';
$type = $_GET['type'] ?? 'jt'; // 'jt' or 'bp'

if (!$book_code) {
    echo json_encode([]);
    exit;
}

if ($type === 'jt') {
    // Get job tickets for this book
    $stmt = $conn->prepare("
        SELECT jt.id, jt.job_ticket_code, jt.lot, jt.print_qty
        FROM job_ticket jt
        LEFT JOIN books b ON jt.book_id = b.book_id
        WHERE b.book_code = :book_code
          AND jt.status NOT IN ('cancelled')
        ORDER BY jt.created_date DESC
        LIMIT 50
    ");
    $stmt->execute([':book_code' => $book_code]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
    
} else if ($type === 'bp') {
    // Get book packings for this book
    $stmt = $conn->prepare("
        SELECT bp.id, bp.name, bp.p_qty, jt.job_ticket_code
        FROM book_packing bp
        LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
        WHERE bp.book_code = :book_code
          AND bp.status = true
        ORDER BY bp.created_date DESC
        LIMIT 50
    ");
    $stmt->execute([':book_code' => $book_code]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
}
?>

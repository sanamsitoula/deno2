<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$bookId = $_GET['book_id'] ?? null;
$fiscalYearId = $_GET['fiscal_year_id'] ?? null;

if (!$bookId || !$fiscalYearId) {
    echo json_encode(['success' => false, 'message' => 'Missing parameters']);
    exit;
}

try {
    // Get next lot number (PostgreSQL compatible)
    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(CAST(lot AS INTEGER)), 0) + 1 as next_lot
        FROM job_ticket 
        WHERE book_id = ? AND fiscal_year_id = ?
    ");
    $stmt->execute([$bookId, $fiscalYearId]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    $nextLot = $result['next_lot'];

    // Get lot history (PostgreSQL compatible)
    $stmt = $conn->prepare("
        SELECT lot, print_qty, job_ticket_code 
        FROM job_ticket 
        WHERE book_id = ? AND fiscal_year_id = ? 
        ORDER BY CAST(lot AS INTEGER) ASC
    ");
    $stmt->execute([$bookId, $fiscalYearId]);
    $lotHistory = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo json_encode([
        'success' => true,
        'next_lot' => $nextLot,
        'lot_history' => $lotHistory
    ]);

} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => $e->getMessage()
    ]);
}
?>
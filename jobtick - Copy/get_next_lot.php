<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (!isset($_GET['book_id']) || !isset($_GET['fiscal_year_id'])) {
    echo json_encode(['success' => false, 'message' => 'Invalid request']);
    exit;
}

$bookId = $_GET['book_id'];
$fiscalYearId = $_GET['fiscal_year_id'];

$stmt = $conn->prepare("SELECT MAX(lot) FROM job_ticket WHERE book_id = ? AND fiscal_year_id = ?");
$stmt->execute([$bookId, $fiscalYearId]);
$maxLot = $stmt->fetchColumn();

echo json_encode([
    'success' => true,
    'next_lot' => $maxLot ? $maxLot + 1 : 1
]);
?>
<?php
/**
 * Returns the line items of a given D2M that are eligible for THIS voucher:
 * assigned to the CURRENT marketing user for the CHOSEN godam, with their
 * remaining-to-inward quantity (v_d2m_item_inward_summary).
 *
 * GET params: d2m_id (required), godam_id (required)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// redirect_if_not_logged_in();

header('Content-Type: application/json');

$d2m_id   = (int)($_GET['d2m_id'] ?? 0);
$godam_id = (int)($_GET['godam_id'] ?? 0);
$current_user_id = $_SESSION['user_id'] ?? null;

if (!$d2m_id || !$godam_id) {
    http_response_code(400);
    echo json_encode(['error' => 'd2m_id and godam_id are required']);
    exit;
}

try {
    $stmt = $conn->prepare("
        SELECT di.id, di.book_code, b.book_name,
               di.per_poka_qty, di.total_poka_qty, di.total_qty, di.open_pcs,
               di.deno_serial_number,
               COALESCE(s.remaining_to_inward, di.total_qty) AS remaining_to_inward,
               COALESCE(s.total_sent, 0)     AS total_sent,
               COALESCE(s.total_received, 0) AS total_received,
               COALESCE(s.inward_entries, 0) AS inward_entries
        FROM   d2m_items di
        LEFT JOIN books b ON di.book_code = b.book_code
        LEFT JOIN v_d2m_item_inward_summary s ON s.d2m_item_id = di.id
        JOIN   godam_book_assignment gba ON gba.book_code = di.book_code
                                         AND gba.godam_id  = :godam_id
                                         AND gba.marketing_user_id = :uid
                                         AND gba.is_active = true
        WHERE  di.d2m_id = :d2m_id
        ORDER  BY b.book_name
    ");
    $stmt->execute([':d2m_id' => $d2m_id, ':godam_id' => $godam_id, ':uid' => $current_user_id]);
    echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Failed to load D2M items']);
}

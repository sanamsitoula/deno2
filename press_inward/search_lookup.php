<?php
/**
 * Lightweight AJAX search endpoint for the Press Inward create form.
 * type=d2m  -> search D2Ms by d2m_no (LIMIT 20)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// redirect_if_not_logged_in();

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';
$q    = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';

$results = [];

try {
    switch ($type) {

        case 'd2m':
            $stmt = $conn->prepare("
                SELECT d.id, d.d2m_no, d.nep_date, d.eng_date, d.total_books, d.total_quantity,
                       COALESCE(us.username, uc.username) AS sender_name
                FROM   d2m d
                LEFT JOIN users us ON d.send_by    = us.id
                LEFT JOIN users uc ON d.created_by = uc.id
                WHERE  d.deleted_at IS NULL
                  AND  d.status <> 'CANCELLED'
                  AND  (:q = '' OR d.d2m_no ILIKE :like)
                ORDER  BY d.created_at DESC
                LIMIT  20
            ");
            $stmt->execute([':q' => $q, ':like' => $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $results[] = [
                    'value'       => $row['id'],
                    'label'       => $row['d2m_no'],
                    'sublabel'    => 'Date: ' . ($row['nep_date'] ?? '-') . ' · Books: ' . ($row['total_books'] ?? 0)
                                     . ' · Sender: ' . ($row['sender_name'] ?? '-'),
                    'nep_date'    => $row['nep_date'],
                    'eng_date'    => $row['eng_date'],
                    'sender_name' => $row['sender_name'],
                ];
            }
            break;

        default:
            http_response_code(400);
            echo json_encode(['error' => 'Unknown lookup type']);
            exit;
    }

    echo json_encode($results);

} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Search failed']);
}

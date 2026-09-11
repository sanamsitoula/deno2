<?php
/**
 * Lightweight, read-only AJAX search endpoint that backs the searchable
 * dropdowns on the Deno create/edit form (book / job_ticket / book_packing).
 *
 * Only ever returns a small page of matches (LIMIT 20), so large tables
 * (job_ticket, book_packing) never get dumped whole to the browser — this
 * is what keeps the dropdowns fast/"optimal" as those tables grow.
 *
 * GET params:
 *   type = book | job_ticket | book_packing   (required)
 *   q    = free-text search term               (optional — empty returns
 *                                                the most recent/relevant 20)
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
// redirect_if_not_logged_in(); // enable once wired the same way as the rest of the app

header('Content-Type: application/json');

$type = $_GET['type'] ?? '';
$q    = trim($_GET['q'] ?? '');
$like = '%' . $q . '%';

$results = [];

try {
    switch ($type) {

        case 'book':
            $stmt = $conn->prepare("
                SELECT book_code, book_name
                FROM   books
                WHERE  is_active = true
                  AND  (:q = '' OR book_name ILIKE :like OR book_code ILIKE :like)
                ORDER  BY book_name
                LIMIT  20
            ");
            $stmt->execute([':q' => $q, ':like' => $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $results[] = [
                    'value'    => $row['book_code'],
                    'label'    => $row['book_name'] . ' (' . $row['book_code'] . ')',
                    'sublabel' => '',
                ];
            }
            break;

        case 'job_ticket':
            $stmt = $conn->prepare("
                SELECT jt.id, jt.job_ticket_code, jt.lot, jt.print_qty,
                       b.book_code, b.book_name
                FROM   job_ticket jt
                LEFT JOIN books b ON jt.book_id = b.book_id
                WHERE  jt.status NOT IN ('cancelled')
                  AND  (:q = '' OR jt.job_ticket_code ILIKE :like
                                 OR b.book_name ILIKE :like
                                 OR jt.lot ILIKE :like)
                ORDER  BY jt.created_date DESC
                LIMIT  20
            ");
            $stmt->execute([':q' => $q, ':like' => $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $results[] = [
                    'value'     => $row['id'],
                    'label'     => $row['job_ticket_code'] . ' — ' . ($row['book_name'] ?? ''),
                    'sublabel'  => 'Lot: ' . ($row['lot'] ?? '-') . ' · Qty: ' . number_format((int)$row['print_qty']),
                    'book_code' => $row['book_code'],
                    'book_name' => $row['book_name'],
                    'lot'       => $row['lot'],
                    'print_qty' => $row['print_qty'],
                ];
            }
            break;

        case 'book_packing':
            $stmt = $conn->prepare("
                SELECT bp.id, bp.name, bp.p_qty, bp.book_code,
                       b.book_name, jt.job_ticket_code, jt.id AS jt_id
                FROM   book_packing bp
                LEFT JOIN books b       ON bp.book_code = b.book_code
                LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
                WHERE  bp.status = true
                  AND  (:q = '' OR bp.name ILIKE :like
                                 OR b.book_name ILIKE :like
                                 OR jt.job_ticket_code ILIKE :like)
                ORDER  BY bp.created_date DESC
                LIMIT  20
            ");
            $stmt->execute([':q' => $q, ':like' => $like]);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $results[] = [
                    'value'           => $row['id'],
                    'label'           => $row['name'] . ' — ' . ($row['book_name'] ?? ''),
                    'sublabel'        => 'JT: ' . ($row['job_ticket_code'] ?? '-') . ' · Packed: ' . number_format((int)$row['p_qty']),
                    'book_code'       => $row['book_code'],
                    'book_name'       => $row['book_name'],
                    'jt_id'           => $row['jt_id'],
                    'job_ticket_code' => $row['job_ticket_code'],
                    'p_qty'           => $row['p_qty'],
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

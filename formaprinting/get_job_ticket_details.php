<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$jt_id = $_GET['jt_id'] ?? 0;

$stmt = $conn->prepare("

    SELECT 
    jtd.*,
    f.name,
    f.book_id AS forma_book_id,
    b.book_code as book_code,b.book_name
FROM job_ticket_details jtd
JOIN forma f ON jtd.forma_id = f.id
JOIN books b ON f.book_id = b.book_id
WHERE jtd.job_ticket_id = :jt_id
ORDER BY jtd.order_no;
");
$stmt->execute([':jt_id' => $jt_id]);
$jtds = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode($jtds);
?>
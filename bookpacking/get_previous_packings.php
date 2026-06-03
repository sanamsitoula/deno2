<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

if (!isset($_GET['jt_id']) || !is_numeric($_GET['jt_id'])) {
    echo json_encode([]);
    exit();
}

$jt_id = (int)$_GET['jt_id'];

$stmt = $conn->prepare("
    SELECT bp.id, bp.name, bp.p_qty, bp.date_eng, bp.date_nep, bp.packing_status,
           u.username
    FROM book_packing bp
    LEFT JOIN users u ON bp.created_by = u.id
    WHERE bp.jt_id = :jt_id 
    -- AND bp.status = true
    ORDER BY bp.created_date DESC
");
$stmt->execute([':jt_id' => $jt_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>
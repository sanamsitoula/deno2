<?php
// ============================================
// File 2: get_jt_packings.php
// ============================================
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$jt_id = isset($_GET['jt_id']) ? (int)$_GET['jt_id'] : 0;

if (!$jt_id) {
    echo json_encode([]);
    exit;
}

$stmt = $conn->prepare("
    SELECT id, name, p_qty, date_nep, packing_status
    FROM book_packing
    WHERE jt_id = :jt_id AND status = true
    ORDER BY created_date DESC
");
$stmt->execute([':jt_id' => $jt_id]);
echo json_encode($stmt->fetchAll(PDO::FETCH_ASSOC));
?>

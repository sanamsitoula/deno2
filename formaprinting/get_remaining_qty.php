<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$jtd_id = $_GET['jtd_id'] ?? 0;

// Get target quantity from job ticket details
$stmt = $conn->prepare("SELECT print_qty FROM job_ticket_details WHERE id = :jtd_id");
$stmt->execute([':jtd_id' => $jtd_id]);
$jtd = $stmt->fetch(PDO::FETCH_ASSOC);

$target_qty = $jtd['print_qty'] ?? 0;

// Get sum of already printed quantities for this JTD
$stmt = $conn->prepare("
    SELECT COALESCE(SUM(fp_printqty), 0) as printed_qty 
    FROM forma_printing 
    WHERE jtd_id = :jtd_id
");
$stmt->execute([':jtd_id' => $jtd_id]);
$printed = $stmt->fetch(PDO::FETCH_ASSOC);

$remaining_qty = $target_qty - $printed['printed_qty'];

echo json_encode(['remaining_qty' => $remaining_qty]);
?>
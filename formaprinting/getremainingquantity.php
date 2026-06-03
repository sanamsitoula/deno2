<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['jtd_id']) || empty($_GET['jtd_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Job Ticket Detail ID is required']);
    exit;
}

try {
    $jtd_id = (int)$_GET['jtd_id'];
    
    // Get remaining quantity for this job ticket detail
    $stmt = $conn->prepare("
        SELECT 
            jtd.print_qty as target_qty,
            COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = :jtd_id 
                 AND fp.status = true), 
                0
            ) as total_printed,
            (jtd.print_qty - COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = :jtd_id 
                 AND fp.status = true), 
                0
            )) as remaining_qty,
            jtd.status,
            f.name as forma_name
        FROM job_ticket_details jtd
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE jtd.id = :jtd_id
    ");
    
    $stmt->execute([':jtd_id' => $jtd_id]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$result) {
        http_response_code(404);
        echo json_encode(['error' => 'Job Ticket Detail not found']);
        exit;
    }
    
    $response = [
        'jtd_id' => $jtd_id,
        'target_qty' => (int)$result['target_qty'],
        'total_printed' => (int)$result['total_printed'],
        'remaining_qty' => (int)$result['remaining_qty'],
        'status' => $result['status'],
        'forma_name' => $result['forma_name'],
        'can_print' => (int)$result['remaining_qty'] > 0
    ];
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected error: ' . $e->getMessage()]);
}
?>
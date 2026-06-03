<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

if (!isset($_GET['jt_id']) || empty($_GET['jt_id'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Job Ticket ID is required']);
    exit;
}

try {
    $jt_id = (int)$_GET['jt_id'];
    
    // Get job ticket details with forma information
    $stmt = $conn->prepare("
        SELECT 
            jtd.id,
            jtd.order_no,
            jtd.page,
            jtd.print_qty,
            jtd.status,
            f.name as forma_name,
            f.status as forma_status,
            -- Calculate total already printed for this JTD
            COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id), 
                0
            ) as total_printed,
            -- Calculate remaining quantity
            (jtd.print_qty - COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id), 
                0
            )) as remaining_qty
        FROM job_ticket_details jtd
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE jtd.job_ticket_id = :jt_id
        AND jtd.status IN ('active', 'processing', 'pending')
        ORDER BY jtd.order_no, jtd.page
    ");
    
    $stmt->execute([':jt_id' => $jt_id]);
    $details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response
    $response = [];
    foreach ($details as $detail) {
        $response[] = [
            'id' => (int)$detail['id'],
            'order_no' => (int)$detail['order_no'],
            'page' => (int)$detail['page'],
            'print_qty' => (int)$detail['print_qty'],
            'status' => $detail['status'],
            'forma_name' => $detail['forma_name'] ?: 'Forma',
            'forma_status' => $detail['forma_status'],
            'total_printed' => (int)$detail['total_printed'],
            'remaining_qty' => (int)$detail['remaining_qty']
        ];
    }
    
    echo json_encode($response);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Database error: ' . $e->getMessage()]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode(['error' => 'Unexpected error: ' . $e->getMessage()]);
}
?>
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
    
    // Get previous forma printing records for this JTD
    $stmt = $conn->prepare("
        SELECT 
            fp.id,
            fp.name,
            fp.date_nep,
            fp.date_eng,
            fp.fp_printqty,
            fp.fp_remainqty,
            fp.remarks,
            fp.created_date,
            m.machine_name,
            operator.username as operator_name,
            supervisor.username as supervisor_name,
            incharge.username as incharge_name,
            s.name as shift_name
        FROM forma_printing fp
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN users operator ON fp.operator_id = operator.id
        LEFT JOIN users supervisor ON fp.supervisor_id = supervisor.id
        LEFT JOIN users incharge ON fp.incharge_id = incharge.id
        LEFT JOIN shifts s ON fp.shift_id = s.id
        WHERE fp.jtd_id = :jtd_id
        AND fp.status = true
        ORDER BY fp.created_date DESC
        LIMIT 10
    ");
    
    $stmt->execute([':jtd_id' => $jtd_id]);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get summary information
    $summaryStmt = $conn->prepare("
        SELECT 
            jtd.print_qty as target_qty,
            COUNT(fp.id) as total_records,
            COALESCE(SUM(fp.fp_printqty), 0) as total_printed,
            (jtd.print_qty - COALESCE(SUM(fp.fp_printqty), 0)) as remaining_qty,
            MIN(fp.created_date) as first_print_date,
            MAX(fp.created_date) as last_print_date
        FROM job_ticket_details jtd
        LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = true
        WHERE jtd.id = :jtd_id
        GROUP BY jtd.id, jtd.print_qty
    ");
    
    $summaryStmt->execute([':jtd_id' => $jtd_id]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    // Format records
    $formattedRecords = [];
    foreach ($records as $record) {
        $formattedRecords[] = [
            'id' => (int)$record['id'],
            'name' => $record['name'],
            'date_nep' => $record['date_nep'],
            'date_eng' => $record['date_eng'],
            'fp_printqty' => (int)$record['fp_printqty'],
            'fp_remainqty' => (int)$record['fp_remainqty'],
            'machine_name' => $record['machine_name'],
            'operator_name' => $record['operator_name'],
            'supervisor_name' => $record['supervisor_name'],
            'incharge_name' => $record['incharge_name'],
            'shift_name' => $record['shift_name'],
            'remarks' => $record['remarks'],
            'created_date' => $record['created_date']
        ];
    }
    
    $response = [
        'jtd_id' => $jtd_id,
        'summary' => [
            'target_qty' => (int)($summary['target_qty'] ?? 0),
            'total_records' => (int)($summary['total_records'] ?? 0),
            'total_printed' => (int)($summary['total_printed'] ?? 0),
            'remaining_qty' => (int)($summary['remaining_qty'] ?? 0),
            'first_print_date' => $summary['first_print_date'],
            'last_print_date' => $summary['last_print_date'],
            'completion_percentage' => $summary['target_qty'] > 0 ? 
                round(($summary['total_printed'] / $summary['target_qty']) * 100, 2) : 0
        ],
        'records' => $formattedRecords,
        'has_previous_records' => count($formattedRecords) > 0,
        // For backward compatibility
        'total_printed' => (int)($summary['total_printed'] ?? 0),
        'remaining_qty' => (int)($summary['remaining_qty'] ?? 0)
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
<?php
/**
 * API Endpoint: check_forma_completion.php
 * Checks if all formas for a job ticket are 100% completed
 */

header('Content-Type: application/json');
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

$jt_id = $_GET['jt_id'] ?? null;

if (!$jt_id) {
    echo json_encode(['error' => 'Job ticket ID is required']);
    exit;
}

try {
    // Get forma completion status
    $stmt = $conn->prepare("
        SELECT 
            jtd.id as jtd_id,
            jtd.print_qty as target_qty,
            f.name as forma_name,
            jtd.order_no,
            COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id AND fp.status = true), 0
            ) as printed_qty
        FROM job_ticket_details jtd
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE jtd.job_ticket_id = :jt_id
        ORDER BY jtd.order_no ASC
    ");
    
    $stmt->execute([':jt_id' => $jt_id]);
    $formas = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $total_formas = count($formas);
    $completed_formas = 0;
    $pending_formas = [];
    
    foreach ($formas as $forma) {
        $printed = (int)$forma['printed_qty'];
        $target = (int)$forma['target_qty'];
        $is_completed = $printed >= $target;
        
        if ($is_completed) {
            $completed_formas++;
        } else {
            $pending_formas[] = [
                'order_no' => $forma['order_no'],
                'forma_name' => $forma['forma_name'],
                'target_qty' => $target,
                'printed_qty' => $printed,
                'remaining_qty' => $target - $printed,
                'completion_pct' => $target > 0 ? round(($printed / $target) * 100, 2) : 0
            ];
        }
    }
    
    $all_completed = ($total_formas > 0 && $total_formas == $completed_formas);
    
    // Get job ticket status
    $jt_stmt = $conn->prepare("SELECT status FROM job_ticket WHERE id = :jt_id");
    $jt_stmt->execute([':jt_id' => $jt_id]);
    $jt_status = $jt_stmt->fetchColumn();
    
    echo json_encode([
        'success' => true,
        'jt_id' => (int)$jt_id,
        'jt_status' => $jt_status,
        'total_formas' => $total_formas,
        'completed_formas' => $completed_formas,
        'pending_formas_count' => count($pending_formas),
        'all_completed' => $all_completed,
        'can_start_packing' => $all_completed,
        'pending_formas' => $pending_formas,
        'message' => $all_completed 
            ? 'All formas completed. Packing can start.' 
            : "Packing cannot start. {$completed_formas}/{$total_formas} formas completed."
    ]);
    
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}
?>
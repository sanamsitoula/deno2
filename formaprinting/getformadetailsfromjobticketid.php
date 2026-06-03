<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

// Check if job_ticket_id is provided
if (!isset($_GET['job_ticket_id']) || empty($_GET['job_ticket_id'])) {
    http_response_code(400);
    echo json_encode([
        'error' => 'Job Ticket ID is required',
        'message' => 'Please provide job_ticket_id parameter'
    ]);
    exit;
}

try {
    $job_ticket_id = (int)$_GET['job_ticket_id'];
    
    // First, verify the job ticket exists and get its fiscal year
    $jtStmt = $conn->prepare("
        SELECT 
            jt.id,
            jt.job_ticket_code,
            jt.fiscal_year_id,
            jt.status as jt_status,
            fy.fiscal_code,
            fy.is_active as fiscal_year_active,
            b.book_code,
            b.book_name
        FROM job_ticket jt
        INNER JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        WHERE jt.id = :job_ticket_id
        AND fy.is_active = true
    ");
    
    $jtStmt->execute([':job_ticket_id' => $job_ticket_id]);
    $jobTicket = $jtStmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$jobTicket) {
        http_response_code(404);
        echo json_encode([
            'error' => 'Job Ticket not found or not in active fiscal year',
            'message' => 'The specified job ticket does not exist or belongs to an inactive fiscal year'
        ]);
        exit;
    }
    
    // Get forma details for this job ticket
    $stmt = $conn->prepare("
        SELECT 
            jtd.id as jtd_id,
            jtd.job_ticket_id,
            jtd.order_no,
            jtd.forma_id,
            jtd.page,
            jtd.old_forma_qty,
            jtd.print_qty as jtd_target_qty,
            jtd.machine,
            jtd.remarks as jtd_remarks,
            jtd.description as jtd_description,
            jtd.status as jtd_status,
            
            -- Forma information
            f.name as forma_name,
            f.status as forma_status,
            f.remarks as forma_remarks,
            
            -- Calculate totals from forma_printing
            COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id 
                 AND fp.status = true), 
                0
            ) as total_printed,
            
            -- Calculate remaining quantity
            (jtd.print_qty - COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id 
                 AND fp.status = true), 
                0
            )) as fp_remaining_qty,
            
            -- Get latest forma printing record for this JTD
            (SELECT fp.fp_printqty 
             FROM forma_printing fp 
             WHERE fp.jtd_id = jtd.id 
             AND fp.status = true 
             ORDER BY fp.created_date DESC 
             LIMIT 1) as last_printed_qty,
            
            -- Count of printing records
            (SELECT COUNT(*) 
             FROM forma_printing fp 
             WHERE fp.jtd_id = jtd.id 
             AND fp.status = true) as printing_records_count,
            
            -- Latest printing date
            (SELECT fp.date_nep 
             FROM forma_printing fp 
             WHERE fp.jtd_id = jtd.id 
             AND fp.status = true 
             ORDER BY fp.created_date DESC 
             LIMIT 1) as last_printed_date
            
        FROM job_ticket_details jtd
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE jtd.job_ticket_id = :job_ticket_id
    --    AND jtd.status IN ('active', 'pending', 'processing')
        ORDER BY jtd.order_no ASC, jtd.page ASC
    ");
    
    $stmt->execute([':job_ticket_id' => $job_ticket_id]);
    $formaDetails = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format the response data
    $formattedDetails = [];
    foreach ($formaDetails as $detail) {
        // Calculate completion percentage
        $targetQty = (int)$detail['jtd_target_qty'];
        $printedQty = (int)$detail['total_printed'];
        $completionPercentage = $targetQty > 0 ? round(($printedQty / $targetQty) * 100, 2) : 0;
        
        // Determine printing status
        $printingStatus = 'not_started';
        if ($printedQty > 0) {
            if ($printedQty >= $targetQty) {
                $printingStatus = 'completed';
            } else {
                $printingStatus = 'in_progress';
            }
        }
        
        $formattedDetails[] = [
            'jtd_id' => (int)$detail['jtd_id'],
            'order_no' => (int)$detail['order_no'],
            'page' => (int)$detail['page'],
            'forma_id' => (int)$detail['forma_id'],
            'forma_name' => $detail['forma_name'] ?: 'Forma ' . $detail['order_no'],
            'forma_display_name' => $detail['forma_name'] 
                ? $detail['forma_name'] . ' - Order: ' . $detail['order_no'] . ' - Page: ' . $detail['page']
                : 'Forma ' . $detail['order_no'] . ' - Page: ' . $detail['page'],
            
            // Quantities
            'jtd_target_qty' => $targetQty,
            'total_printed' => $printedQty,
            'fp_remaining_qty' => max(0, (int)$detail['fp_remaining_qty']),
            'old_forma_qty' => (int)$detail['old_forma_qty'],
            'last_printed_qty' => $detail['last_printed_qty'] ? (int)$detail['last_printed_qty'] : 0,
            
            // Status and metadata
            'jtd_status' => $detail['jtd_status'],
            'forma_status' => $detail['forma_status'],
            'printing_status' => $printingStatus,
            'completion_percentage' => $completionPercentage,
            'printing_records_count' => (int)$detail['printing_records_count'],
            'last_printed_date' => $detail['last_printed_date'],
            
            // Additional info
            'machine' => $detail['machine'],
            'jtd_remarks' => $detail['jtd_remarks'],
            'jtd_description' => $detail['jtd_description'],
            'forma_remarks' => $detail['forma_remarks'],
            
            // Flags for UI
            'can_print' => (int)$detail['fp_remaining_qty'] > 0,
            'is_completed' => $printingStatus === 'completed',
            'needs_attention' => $printedQty > $targetQty, // Over-printed
        ];
    }
    
    // Get job ticket summary
    $summaryStmt = $conn->prepare("
        SELECT 
            COUNT(jtd.id) as total_formas,
            SUM(jtd.print_qty) as total_target_qty,
            SUM(COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id 
                 AND fp.status = true), 
                0
            )) as total_printed_qty,
            SUM(jtd.print_qty - COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id 
                 AND fp.status = true), 
                0
            )) as total_remaining_qty,
            COUNT(CASE WHEN COALESCE(
                (SELECT SUM(fp.fp_printqty) 
                 FROM forma_printing fp 
                 WHERE fp.jtd_id = jtd.id 
                 AND fp.status = true), 
                0
            ) >= jtd.print_qty THEN 1 END) as completed_formas
        FROM job_ticket_details jtd
        WHERE jtd.job_ticket_id = :job_ticket_id
        AND jtd.status IN ('active', 'pending', 'processing')
    ");
    
    $summaryStmt->execute([':job_ticket_id' => $job_ticket_id]);
    $summary = $summaryStmt->fetch(PDO::FETCH_ASSOC);
    
    // Calculate overall completion
    $totalTargetQty = (int)$summary['total_target_qty'];
    $totalPrintedQty = (int)$summary['total_printed_qty'];
    $overallCompletion = $totalTargetQty > 0 ? round(($totalPrintedQty / $totalTargetQty) * 100, 2) : 0;
    
    // Final response
    $response = [
        'success' => true,
        'job_ticket' => [
            'id' => (int)$jobTicket['id'],
            'code' => $jobTicket['job_ticket_code'],
            'fiscal_year_id' => (int)$jobTicket['fiscal_year_id'],
            'fiscal_code' => $jobTicket['fiscal_code'],
            'book_code' => $jobTicket['book_code'],
            'book_name' => $jobTicket['book_name'],
            'status' => $jobTicket['jt_status']
        ],
        'summary' => [
            'total_formas' => (int)$summary['total_formas'],
            'completed_formas' => (int)$summary['completed_formas'],
            'pending_formas' => (int)$summary['total_formas'] - (int)$summary['completed_formas'],
            'total_target_qty' => $totalTargetQty,
            'total_printed_qty' => $totalPrintedQty,
            'total_remaining_qty' => max(0, (int)$summary['total_remaining_qty']),
            'overall_completion_percentage' => $overallCompletion,
            'is_fully_completed' => $overallCompletion >= 100
        ],
        'forma_details' => $formattedDetails,
        'has_formas' => count($formattedDetails) > 0,
        'fiscal_year_active' => true, // Already filtered for active fiscal year
        'timestamp' => date('Y-m-d H:i:s')
    ];
    
    echo json_encode($response, JSON_PRETTY_PRINT);
    
} catch (PDOException $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Database error',
        'message' => 'Failed to retrieve forma details',
        'debug' => $e->getMessage() // Remove in production
    ]);
} catch (Exception $e) {
    http_response_code(500);
    echo json_encode([
        'error' => 'Unexpected error',
        'message' => 'An unexpected error occurred',
        'debug' => $e->getMessage() // Remove in production
    ]);
}
?>
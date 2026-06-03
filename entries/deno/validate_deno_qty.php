<?php
// validate_deno_qty.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$jt_id = isset($_GET['jt_id']) ? (int)$_GET['jt_id'] : null;
$bp_id = isset($_GET['bp_id']) ? (int)$_GET['bp_id'] : null;
$new_qty = isset($_GET['new_qty']) ? (int)$_GET['new_qty'] : 0;
$edit_id = isset($_GET['edit_id']) ? (int)$_GET['edit_id'] : null;

$response = [
    'valid' => true,
    'exceeds_jt' => false,
    'exceeds_bp' => false
];

try {
    // Validate against Book Packing
    if ($bp_id) {
        // Get BP quantity
        $stmt = $conn->prepare("SELECT p_qty FROM book_packing WHERE id = :bp_id");
        $stmt->execute([':bp_id' => $bp_id]);
        $bp_data = $stmt->fetch();
        
        if ($bp_data) {
            $bp_qty = (int)$bp_data['p_qty'];
            
            // Get existing deno total for this BP (excluding current edit)
            $sql = "SELECT COALESCE(SUM(total_qty), 0) as total FROM deno WHERE bp_id = :bp_id AND deleted_at IS NULL";
            if ($edit_id) {
                $sql .= " AND id != :edit_id";
            }
            $stmt = $conn->prepare($sql);
            $params = [':bp_id' => $bp_id];
            if ($edit_id) {
                $params[':edit_id'] = $edit_id;
            }
            $stmt->execute($params);
            $existing_bp_deno = (int)$stmt->fetch()['total'];
            
            $total_bp = $existing_bp_deno + $new_qty;
            
            if ($total_bp > $bp_qty) {
                $response['valid'] = false;
                $response['exceeds_bp'] = true;
                $response['bp_qty'] = $bp_qty;
                $response['existing_bp_deno'] = $existing_bp_deno;
                $response['total_bp'] = $total_bp;
                $response['bp_excess'] = $total_bp - $bp_qty;
            }
        }
    }
    
    // Validate against Job Ticket
    if ($jt_id) {
        // Get JT print quantity
        $stmt = $conn->prepare("SELECT print_qty FROM job_ticket WHERE id = :jt_id");
        $stmt->execute([':jt_id' => $jt_id]);
        $jt_data = $stmt->fetch();
        
        if ($jt_data) {
            $jt_print_qty = (int)$jt_data['print_qty'];
            
            // Get existing deno total for this JT (excluding current edit)
            $sql = "SELECT COALESCE(SUM(total_qty), 0) as total FROM deno WHERE jt_id = :jt_id AND deleted_at IS NULL";
            if ($edit_id) {
                $sql .= " AND id != :edit_id";
            }
            $stmt = $conn->prepare($sql);
            $params = [':jt_id' => $jt_id];
            if ($edit_id) {
                $params[':edit_id'] = $edit_id;
            }
            $stmt->execute($params);
            $existing_jt_deno = (int)$stmt->fetch()['total'];
            
            $total_jt = $existing_jt_deno + $new_qty;
            
            if ($total_jt > $jt_print_qty) {
                $response['valid'] = false;
                $response['exceeds_jt'] = true;
                $response['jt_print_qty'] = $jt_print_qty;
                $response['existing_jt_deno'] = $existing_jt_deno;
                $response['total_jt'] = $total_jt;
                $response['jt_excess'] = $total_jt - $jt_print_qty;
            }
        }
    }
    
    // If only JT validation (from_jt mode)
    if ($jt_id && !$bp_id && !$response['valid']) {
        $response['existing_deno'] = $response['existing_jt_deno'];
        $response['total'] = $response['total_jt'];
        $response['excess'] = $response['jt_excess'];
    }
    
} catch (Exception $e) {
    $response['error'] = $e->getMessage();
}

echo json_encode($response);
?>
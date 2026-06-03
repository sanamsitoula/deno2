<?php
// ============================================
// File 3: get_bp_summary.php
// ============================================
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$bp_id = isset($_GET['bp_id']) ? (int)$_GET['bp_id'] : 0;
$jt_id = isset($_GET['jt_id']) ? (int)$_GET['jt_id'] : 0;

if (!$bp_id || !$jt_id) {
    echo json_encode(['error' => 'Missing parameters']);
    exit;
}

try {
    // Get JT info
    $stmt = $conn->prepare("SELECT job_ticket_code, print_qty FROM job_ticket WHERE id = :jt_id");
    $stmt->execute([':jt_id' => $jt_id]);
    $jt_data = $stmt->fetch();
    
    // Get total packed for this JT
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(p_qty), 0) as total_packed
        FROM book_packing
        WHERE jt_id = :jt_id AND status = true
    ");
    $stmt->execute([':jt_id' => $jt_id]);
    $total_packed = (int)$stmt->fetch()['total_packed'];
    
    // Get total deno entries count for this JT
    $stmt = $conn->prepare("
        SELECT COUNT(*) as count
        FROM deno
        WHERE jt_id = :jt_id AND deleted_at IS NULL
    ");
    $stmt->execute([':jt_id' => $jt_id]);
    $total_deno_entries = (int)$stmt->fetch()['count'];
    
    // Get total deno quantity for this JT
    $stmt = $conn->prepare("
        SELECT COALESCE(SUM(total_qty), 0) as total_qty
        FROM deno
        WHERE jt_id = :jt_id AND deleted_at IS NULL
    ");
    $stmt->execute([':jt_id' => $jt_id]);
    $total_deno_qty = (int)$stmt->fetch()['total_qty'];
    
    $total_print_qty = (int)$jt_data['print_qty'];
    $remaining = $total_print_qty - $total_deno_qty;
    
    echo json_encode([
        'jt_code' => $jt_data['job_ticket_code'],
        'total_print_qty' => $total_print_qty,
        'total_packed' => $total_packed,
        'total_deno_entries' => $total_deno_entries,
        'total_deno_qty' => $total_deno_qty,
        'remaining' => $remaining
    ]);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>
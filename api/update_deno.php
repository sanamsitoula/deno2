<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once '../config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

// Get POST data
$id = isset($_POST['id']) ? intval($_POST['id']) : 0;
$book_code = isset($_POST['book_code']) ? trim($_POST['book_code']) : '';
$ref_no = isset($_POST['ref_no']) ? trim($_POST['ref_no']) : '';
$deno_date_nep = isset($_POST['deno_date_nep']) ? trim($_POST['deno_date_nep']) : '';
$deno_date_eng = isset($_POST['deno_date_eng']) ? trim($_POST['deno_date_eng']) : '';
$per_poka_qty = isset($_POST['per_poka_qty']) ? intval($_POST['per_poka_qty']) : 0;
$poka_qty = isset($_POST['poka_qty']) ? intval($_POST['poka_qty']) : 0;
$quantity_openpcs = isset($_POST['quantity_openpcs']) ? intval($_POST['quantity_openpcs']) : 0;
$notes = isset($_POST['notes']) ? trim($_POST['notes']) : '';
$update_remarks = isset($_POST['update_remarks']) ? trim($_POST['update_remarks']) : '';
$logged_in_user_id = isset($_POST['logged_in_user_id']) ? intval($_POST['logged_in_user_id']) : 0;
$received_by_id = isset($_POST['received_by_id']) ? intval($_POST['received_by_id']) : null;
$verify_by_id = isset($_POST['verify_by_id']) ? intval($_POST['verify_by_id']) : null;

// Validation
if ($id <= 0 || empty($book_code) || empty($ref_no) || empty($deno_date_nep) || 
    empty($deno_date_eng) || $per_poka_qty <= 0 || $poka_qty <= 0 || $logged_in_user_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'All required fields must be filled']);
    exit;
}

try {
    // Get logged in user's username
    $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $user_stmt->execute([$logged_in_user_id]);
    $logged_in_username = $user_stmt->fetchColumn();
    
    if (!$logged_in_username) {
        echo json_encode(['success' => false, 'message' => 'Invalid user']);
        exit;
    }

    // Get received_by and verify_by usernames if IDs provided
    $received_by = null;
    $verify_by = null;
    
    if ($received_by_id) {
        $user_stmt->execute([$received_by_id]);
        $received_by = $user_stmt->fetchColumn();
    }
    
    if ($verify_by_id) {
        $user_stmt->execute([$verify_by_id]);
        $verify_by = $user_stmt->fetchColumn();
    }

    // Check for duplicate ref_no with different dates (excluding current record)
    $check_stmt = $conn->prepare("
        SELECT deno_date_nep FROM deno 
        WHERE ref_no = :ref_no AND deno_date_nep != :deno_date_nep AND id != :id
        LIMIT 1
    ");
    $check_stmt->execute([
        ':ref_no' => $ref_no,
        ':deno_date_nep' => $deno_date_nep,
        ':id' => $id
    ]);
    
    if ($check_stmt->fetch()) {
        echo json_encode([
            'success' => false,
            'message' => "Reference number $ref_no already exists with a different date"
        ]);
        exit;
    }
    
    // Update record - updated_by is automatically set to logged in user
    $stmt = $conn->prepare("
        UPDATE deno SET
            book_code = :book_code,
            ref_no = :ref_no,
            deno_date_nep = :deno_date_nep,
            deno_date_eng = :deno_date_eng,
            per_poka_qty = :per_poka_qty,
            poka_qty = :poka_qty,
            quantity_openpcs = :quantity_openpcs,
            notes = :notes,
            received_by = :received_by,
            verify_by = :verify_by,
            updated_by = :updated_by,
            update_remarks = :update_remarks,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':book_code' => $book_code,
        ':ref_no' => $ref_no,
        ':deno_date_nep' => $deno_date_nep,
        ':deno_date_eng' => $deno_date_eng,
        ':per_poka_qty' => $per_poka_qty,
        ':poka_qty' => $poka_qty,
        ':quantity_openpcs' => $quantity_openpcs,
        ':notes' => $notes,
        ':received_by' => $received_by,
        ':verify_by' => $verify_by,
        ':updated_by' => $logged_in_username,
        ':update_remarks' => $update_remarks,
        ':id' => $id
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Deno record updated successfully'
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
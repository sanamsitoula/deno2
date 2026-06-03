<?php
/**
 * Forma Printing API
 * File: /deno2/api/forma_printing.php
 * require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/api/auth.php';

$auth = new APIAuth($conn);
$user = $auth->requireAuth();

$method = $_SERVER['REQUEST_METHOD'];
$request_uri = $_SERVER['REQUEST_URI'];

// Parse action from URI
$uri_parts = explode('/', trim($request_uri, '/'));
$action = $_GET['action'] ?? '';

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user, $action);
            break;
        case 'POST':
            handlePost($conn, $user, $action);
            break;
        case 'PUT':
            handlePut($conn, $user, $action);
            break;
        case 'DELETE':
            handleDelete($conn, $user, $action);
            break;
        default:
            APIAuth::sendError('Method not allowed', 405);
    }
} catch (Exception $e) {
    APIAuth::sendError('Server error: ' . $e->getMessage(), 500);
}

/**
 * Handle GET requests
 */
function handleGet($conn, $user, $action) {
    switch ($action) {
        case 'list':
            getFormaList($conn, $user);
            break;
        case 'detail':
            getFormaDetail($conn, $user);
            break;
        case 'dropdowns':
            getDropdowns($conn, $user);
            break;
        case 'job_tickets':
            getJobTickets($conn, $user);
            break;
        case 'job_ticket_formas':
            getJobTicketFormas($conn, $user);
            break;
        case 'previous_records':
            getPreviousRecords($conn, $user);
            break;
        default:
            APIAuth::sendError('Invalid action');
    }
}

/**
 * Get forma printing list with filters
 */
function getFormaList($conn, $user) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(10, min(200, intval($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $per_page;
    
    // Build WHERE clause
    $where = ["fp.deleted_at IS NULL"];
    $params = [];
    
    if (!empty($_GET['name'])) {
        $where[] = "fp.name LIKE :name";
        $params[':name'] = '%' . $_GET['name'] . '%';
    }
    
    if (!empty($_GET['jt_code'])) {
        $where[] = "jt.job_ticket_code LIKE :jt_code";
        $params[':jt_code'] = '%' . $_GET['jt_code'] . '%';
    }
    
    if (!empty($_GET['book_code'])) {
        $where[] = "b.book_code LIKE :book_code";
        $params[':book_code'] = '%' . $_GET['book_code'] . '%';
    }
    
    if (!empty($_GET['fiscal_year_id'])) {
        $where[] = "fp.fiscal_year_id = :fiscal_year_id";
        $params[':fiscal_year_id'] = $_GET['fiscal_year_id'];
    }
    
    if (!empty($_GET['machine_id'])) {
        $where[] = "fp.machine_id = :machine_id";
        $params[':machine_id'] = $_GET['machine_id'];
    }
    
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = "fp.status = :status";
        $params[':status'] = (bool)$_GET['status'];
    }
    
    if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
        $where[] = "fp.date_nep BETWEEN :start_date AND :end_date";
        $params[':start_date'] = $_GET['start_date'];
        $params[':end_date'] = $_GET['end_date'];
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Count total records
    $count_query = "
        SELECT COUNT(*) as total
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        WHERE $where_clause
    ";
    
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute($params);
    $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Fetch records
    $query = "
        SELECT 
            fp.*,
            jt.job_ticket_code,
            jt.lot,
            b.book_code,
            b.book_name,
            b.class_level,
            m.machine_name,
            fy.fiscal_code,
            supervisor.username as supervisor_name,
            operator.username as operator_name,
            incharge.username as incharge_name,
            f.name as forma_name,
            jtd.print_qty as jtd_targetqty,
            jtd.page as jtd_page
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
        LEFT JOIN users supervisor ON fp.supervisor_id = supervisor.id
        LEFT JOIN users operator ON fp.operator_id = operator.id
        LEFT JOIN users incharge ON fp.incharge_id = incharge.id
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE $where_clause
        ORDER BY fp.created_date DESC
        LIMIT :limit OFFSET :offset
    ";
    
    $stmt = $conn->prepare($query);
    foreach ($params as $key => $value) {
        $stmt->bindValue($key, $value);
    }
    $stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
    $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
    $stmt->execute();
    
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    APIAuth::sendSuccess([
        'records' => $records,
        'pagination' => [
            'total' => (int)$total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => ceil($total / $per_page)
        ]
    ]);
}

/**
 * Get single forma printing detail
 */
function getFormaDetail($conn, $user) {
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        APIAuth::sendError('ID is required');
    }
    
    $stmt = $conn->prepare("
        SELECT 
            fp.*,
            jt.job_ticket_code,
            jt.lot,
            jt.print_qty as jt_print_qty,
            b.book_code,
            b.book_name,
            b.class_level,
            m.machine_name,
            fy.fiscal_code,
            s.name as shift_name,
            supervisor.username as supervisor_name,
            operator.username as operator_name,
            incharge.username as incharge_name,
            creator.username as created_by_name,
            updater.username as updated_by_name,
            f.name as forma_name,
            jtd.print_qty as jtd_targetqty,
            jtd.page as jtd_page,
            jtd.order_no
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
        LEFT JOIN shifts s ON fp.shift_id = s.id
        LEFT JOIN users supervisor ON fp.supervisor_id = supervisor.id
        LEFT JOIN users operator ON fp.operator_id = operator.id
        LEFT JOIN users incharge ON fp.incharge_id = incharge.id
        LEFT JOIN users creator ON fp.created_by = creator.id
        LEFT JOIN users updater ON fp.updated_by = updater.id
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE fp.id = :id AND fp.deleted_at IS NULL
    ");
    
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        APIAuth::sendError('Record not found', 404);
    }
    
    APIAuth::sendSuccess($record);
}

/**
 * Get dropdown data
 */
function getDropdowns($conn, $user) {
    $data = [];
    
    // Fiscal Years
    $stmt = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC");
    $data['fiscal_years'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Machines
    $stmt = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name");
    $data['machines'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Supervisors
    $stmt = $conn->query("SELECT id, username FROM users WHERE role IN ('supervisor', 'admin') ORDER BY username");
    $data['supervisors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Operators
    $stmt = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'admin') ORDER BY username");
    $data['operators'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Incharges
    $stmt = $conn->query("SELECT id, username FROM users WHERE role IN ('incharge', 'admin') ORDER BY username");
    $data['incharges'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Shifts
    $stmt = $conn->query("SELECT id, name FROM shifts WHERE status = true ORDER BY name");
    $data['shifts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Active Fiscal Year
    $stmt = $conn->query("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = true LIMIT 1");
    $data['active_fiscal_year'] = $stmt->fetch(PDO::FETCH_ASSOC);
    
    APIAuth::sendSuccess($data);
}

/**
 * Get job tickets
 */
function getJobTickets($conn, $user) {
    $stmt = $conn->query("
        SELECT 
            jt.id,
            jt.job_ticket_code,
            jt.lot,
            jt.print_qty,
            jt.page_qty,
            jt.status,
            b.book_code,
            b.book_name,
            b.class_level,
            fy.fiscal_code
        FROM job_ticket jt
        LEFT JOIN books b ON jt.book_id = b.book_id
        INNER JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
        WHERE jt.status IN ('active', 'processing', 'pending')
        AND fy.is_active = true
        ORDER BY jt.job_ticket_code DESC
    ");
    
    $job_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);
    APIAuth::sendSuccess($job_tickets);
}

/**
 * Get job ticket formas
 */
function getJobTicketFormas($conn, $user) {
    $jt_id = intval($_GET['job_ticket_id'] ?? 0);
    
    if (!$jt_id) {
        APIAuth::sendError('Job Ticket ID is required');
    }
    
    // Use the existing getformadetailsfromjobticketid.php logic
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/modules/forma_printing/getformadetailsfromjobticketid.php';
    
    // The file already outputs JSON, so we'll capture it
    ob_start();
    $_GET['job_ticket_id'] = $jt_id;
    include $_SERVER['DOCUMENT_ROOT'] . '/deno2/modules/forma_printing/getformadetailsfromjobticketid.php';
    $output = ob_get_clean();
    
    echo $output;
    exit();
}

/**
 * Get previous printing records
 */
function getPreviousRecords($conn, $user) {
    $jtd_id = intval($_GET['jtd_id'] ?? 0);
    
    if (!$jtd_id) {
        APIAuth::sendError('Job Ticket Detail ID is required');
    }
    
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/modules/forma_printing/getpreviousformaprinting.php';
    
    ob_start();
    $_GET['jtd_id'] = $jtd_id;
    include $_SERVER['DOCUMENT_ROOT'] . '/deno2/modules/forma_printing/getpreviousformaprinting.php';
    $output = ob_get_clean();
    
    echo $output;
    exit();
}

/**
 * Handle POST requests (Create)
 */
function handlePost($conn, $user, $action) {
    // Check permission
    $allowed_roles = ['incharge', 'supervisor', 'admin'];
    if (!$auth->hasRole($user, $allowed_roles)) {
        APIAuth::sendError('Permission denied. Only incharge, supervisor, and admin can create records.', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input) {
        APIAuth::sendError('Invalid JSON input');
    }
    
    createFormaPrinting($conn, $user, $input);
}

/**
 * Create forma printing record
 */
function createFormaPrinting($conn, $user, $input) {
    // Validate required fields
    $required = [
        'date_nep', 'date_eng', 'name', 'fiscal_year_id', 'jt_id', 'jtd_id',
        'jtd_targetqty', 'fp_printqty', 'fp_remainqty', 'supervisor_id',
        'operator_id', 'incharge_id', 'shift_id', 'machine_id'
    ];
    
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            APIAuth::sendError("Missing required field: $field");
        }
    }
    
    try {
        $conn->beginTransaction();
        
        // Validate quantities
        if ($input['fp_printqty'] <= 0) {
            throw new Exception("Print quantity must be greater than 0");
        }
        
        // Verify remaining quantity
        $checkStmt = $conn->prepare("
            SELECT 
                jtd.print_qty as target_qty,
                COALESCE(SUM(fp.fp_printqty), 0) as already_printed
            FROM job_ticket_details jtd
            LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id 
                AND fp.status = true 
                AND fp.deleted_at IS NULL
            WHERE jtd.id = :jtd_id
            GROUP BY jtd.id, jtd.print_qty
        ");
        $checkStmt->execute([':jtd_id' => $input['jtd_id']]);
        $qtyCheck = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($qtyCheck) {
            $available_qty = $qtyCheck['target_qty'] - $qtyCheck['already_printed'];
            if ($input['fp_printqty'] > $available_qty) {
                throw new Exception("Print quantity ({$input['fp_printqty']}) exceeds available quantity ({$available_qty})");
            }
        }
        
        // Insert record
        $stmt = $conn->prepare("
            INSERT INTO forma_printing (
                date_nep, date_eng, name, fiscal_year_id, jt_id, jtd_id,
                jtd_targetqty, fp_printqty, fp_remainqty, supervisor_id,
                created_by, operator_id, incharge_id, shift_id, machine_id,
                remarks, description, status, created_date
            ) VALUES (
                :date_nep, :date_eng, :name, :fiscal_year_id, :jt_id, :jtd_id,
                :jtd_targetqty, :fp_printqty, :fp_remainqty, :supervisor_id,
                :created_by, :operator_id, :incharge_id, :shift_id, :machine_id,
                :remarks, :description, true, NOW()
            )
        ");
        
        $result = $stmt->execute([
            ':date_nep' => $input['date_nep'],
            ':date_eng' => $input['date_eng'],
            ':name' => $input['name'],
            ':fiscal_year_id' => $input['fiscal_year_id'],
            ':jt_id' => $input['jt_id'],
            ':jtd_id' => $input['jtd_id'],
            ':jtd_targetqty' => $input['jtd_targetqty'],
            ':fp_printqty' => $input['fp_printqty'],
            ':fp_remainqty' => $input['fp_remainqty'],
            ':supervisor_id' => $input['supervisor_id'],
            ':created_by' => $user['user_id'], // From authenticated user
            ':operator_id' => $input['operator_id'],
            ':incharge_id' => $input['incharge_id'],
            ':shift_id' => $input['shift_id'],
            ':machine_id' => $input['machine_id'],
            ':remarks' => $input['remarks'] ?? null,
            ':description' => $input['description'] ?? null
        ]);
        
        $id = $conn->lastInsertId();
        $conn->commit();
        
        APIAuth::sendSuccess([
            'id' => $id,
            'message' => 'Forma Printing record created successfully'
        ]);
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        APIAuth::sendError($e->getMessage());
    }
}

/**
 * Handle PUT requests (Update)
 */
function handlePut($conn, $user, $action) {
    global $auth;
    
    // Check permission
    $allowed_roles = ['incharge', 'supervisor', 'admin'];
    if (!$auth->hasRole($user, $allowed_roles)) {
        APIAuth::sendError('Permission denied', 403);
    }
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!$input || !isset($input['id'])) {
        APIAuth::sendError('Invalid input or missing ID');
    }
    
    updateFormaPrinting($conn, $user, $input);
}

/**
 * Update forma printing record
 */
function updateFormaPrinting($conn, $user, $input) {
    $id = intval($input['id']);
    
    try {
        $conn->beginTransaction();
        
        // Check if record exists and not deleted
        $checkStmt = $conn->prepare("SELECT id FROM forma_printing WHERE id = :id AND deleted_at IS NULL");
        $checkStmt->execute([':id' => $id]);
        if (!$checkStmt->fetch()) {
            throw new Exception("Record not found");
        }
        
        // Build update query dynamically
        $updateFields = [];
        $params = [':id' => $id, ':updated_by' => $user['user_id']];
        
        $allowedFields = [
            'date_nep', 'date_eng', 'name', 'fiscal_year_id', 'jt_id', 'jtd_id',
            'jtd_targetqty', 'fp_printqty', 'fp_remainqty', 'supervisor_id',
            'operator_id', 'incharge_id', 'shift_id', 'machine_id',
            'remarks', 'description', 'status'
        ];
        
        foreach ($allowedFields as $field) {
            if (isset($input[$field])) {
                $updateFields[] = "$field = :$field";
                $params[":$field"] = $input[$field];
            }
        }
        
        if (empty($updateFields)) {
            throw new Exception("No fields to update");
        }
        
        $updateFields[] = "updated_by = :updated_by";
        $updateFields[] = "updated_at = NOW()";
        
        $sql = "UPDATE forma_printing SET " . implode(', ', $updateFields) . " WHERE id = :id";
        
        $stmt = $conn->prepare($sql);
        $stmt->execute($params);
        
        $conn->commit();
        
        APIAuth::sendSuccess([
            'id' => $id,
            'message' => 'Forma Printing record updated successfully'
        ]);
        
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        APIAuth::sendError($e->getMessage());
    }
}

/**
 * Handle DELETE requests (Soft Delete)
 */
function handleDelete($conn, $user, $action) {
    global $auth;
    
    // Check permission
    $allowed_roles = ['incharge', 'supervisor', 'admin'];
    if (!$auth->hasRole($user, $allowed_roles)) {
        APIAuth::sendError('Permission denied', 403);
    }
    
    $id = intval($_GET['id'] ?? 0);
    
    if (!$id) {
        APIAuth::sendError('ID is required');
    }
    
    softDeleteFormaPrinting($conn, $user, $id);
}

/**
 * Soft delete forma printing record
 */
function softDeleteFormaPrinting($conn, $user, $id) {
    try {
        $stmt = $conn->prepare("
            UPDATE forma_printing 
            SET deleted_at = NOW(), 
                delete_by = :delete_by,
                status = false
            WHERE id = :id AND deleted_at IS NULL
        ");
        
        $result = $stmt->execute([
            ':id' => $id,
            ':delete_by' => $user['user_id']
        ]);
        
        if ($stmt->rowCount() === 0) {
            APIAuth::sendError('Record not found or already deleted', 404);
        }
        
        APIAuth::sendSuccess([
            'id' => $id,
            'message' => 'Forma Printing record deleted successfully'
        ]);
        
    } catch (Exception $e) {
        APIAuth::sendError($e->getMessage());
    }
}
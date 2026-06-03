<?php
/**
 * Forma Printing API - Full Implementation
 * File: /deno2/api/forma_printing.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE');
header('Access-Control-Allow-Headers: Content-Type');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

function sendResponse($success, $dataOrMessage, $code = 200) {
    http_response_code($code);
    echo json_encode([
        'success' => $success,
        ($success ? 'data' : 'message') => $dataOrMessage
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

function getUserById($conn, $user_id) {
    if (!$user_id || $user_id <= 0) return null;
    $stmt = $conn->prepare("SELECT id, username, role::text FROM users WHERE id = ?");
    $stmt->execute([$user_id]);
    return $stmt->fetch(PDO::FETCH_ASSOC);
}

$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';
$input = null;

if ($method === 'POST' || $method === 'PUT') {
    $json = file_get_contents('php://input');
    $input = json_decode($json, true);
}

// For GET: use $_GET
// For DELETE: allow $_GET (since no body is typical)
// For POST/PUT: use JSON body
if ($method === 'GET' || $method === 'DELETE') {
    $user_id = $_GET['logged_in_user_id'] ?? null;
} else {
    $user_id = $input['logged_in_user_id'] ?? null;
}


$user = getUserById($conn, $user_id);

if (!$user) {
    sendResponse(false, 'Invalid or missing logged_in_user_id', 401);
}

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $user, $action); break;
        case 'POST':
            handlePost($conn, $user, $input); break;
        case 'PUT':
            handlePut($conn, $user, $input); break;
        case 'DELETE':
            handleDelete($conn, $user); break;
        default:
            sendResponse(false, 'Method not allowed', 405);
    }
} catch (Exception $e) {
    error_log("FormaPrinting API Error: " . $e->getMessage());
    sendResponse(false, 'Server error', 500);
}

// --- HANDLERS ---

function handleGet($conn, $user, $action) {
    switch ($action) {
        case 'dropdowns':
            getDropdowns($conn, $user); break;
        case 'list':
            getFormaList($conn, $user); break;
        case 'detail':
            getFormaDetail($conn, $user); break;
        case 'job_tickets':
            getJobTickets($conn, $user); break;
        case 'job_ticket_formas':
            getJobTicketFormas($conn, $user); break;
        case 'previous_records':
            getPreviousRecords($conn, $user); break;
        default:
            sendResponse(false, 'Invalid action');
    }
}

function getDropdowns($conn, $user) {
    $data = [];

    // Fiscal Years
    $stmt = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC");
    $data['fiscal_years'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Machines (status = 'active')
    $stmt = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name");
    $data['machines'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Supervisors (role = 'supervisor' or 'admin')
    $stmt = $conn->query("SELECT id, username FROM users WHERE role::text IN ('supervisor', 'admin') ORDER BY username");
    $data['supervisors'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Operators (role = 'operator' or 'admin')
    $stmt = $conn->query("SELECT id, username FROM users WHERE role::text IN ('operator', 'admin') ORDER BY username");
    $data['operators'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Incharges (role = 'incharge' or 'admin')
    $stmt = $conn->query("SELECT id, username FROM users WHERE role::text IN ('incharge', 'admin') ORDER BY username");
    $data['incharges'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Shifts (status = true)
    $stmt = $conn->query("SELECT id, name FROM shifts WHERE status = true ORDER BY name");
    $data['shifts'] = $stmt->fetchAll(PDO::FETCH_ASSOC);

    sendResponse(true, $data);
}

function getJobTickets($conn, $user) {
    $stmt = $conn->prepare("
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
        LEFT JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
        WHERE jt.status IN ('active','processing','pending') 
          AND fy.is_active = true
        ORDER BY jt.job_ticket_code DESC
    ");
    $stmt->execute();
    sendResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getJobTicketFormas($conn, $user) {
    $jt_id = intval($_GET['job_ticket_id'] ?? 0);
    if (!$jt_id) sendResponse(false, 'Job Ticket ID is required');

    $stmt = $conn->prepare("
        SELECT 
            jtd.id as jtd_id,
            jtd.forma_id,  
            f.name as forma_display_name,
            jtd.print_qty as jtd_targetqty,
            COALESCE(SUM(fp.fp_printqty), 0) as total_printed,
            (jtd.print_qty - COALESCE(SUM(fp.fp_printqty), 0)) as fp_remainingqty,
            CASE 
                WHEN (jtd.print_qty - COALESCE(SUM(fp.fp_printqty), 0)) = 0 THEN 'completed'
                WHEN COALESCE(SUM(fp.fp_printqty), 0) > 0 THEN 'in_progress'
                ELSE 'not_started'
            END as printing_status
        FROM job_ticket_details jtd
        LEFT JOIN forma f ON jtd.forma_id = f.id
        LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.delete_by IS NULL
        WHERE jtd.job_ticket_id = ?
        GROUP BY jtd.id, jtd.forma_id, f.name, jtd.print_qty  
        ORDER BY jtd.order_no
    ");
    $stmt->execute([$jt_id]);
    sendResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getPreviousRecords($conn, $user) {
    $jtd_id = intval($_GET['jtd_id'] ?? 0);
    if (!$jtd_id) sendResponse(false, 'Job Ticket Detail ID is required');

    $stmt = $conn->prepare("
        SELECT 
            fp.id,
            fp.date_nep,
            fp.fp_printqty,
            fp.fp_remainqty,
            fp.remarks,
            fp.created_date,
            u.username as created_by_name
        FROM forma_printing fp
        LEFT JOIN users u ON fp.created_by = u.id
        WHERE fp.jtd_id = ? AND fp.delete_by IS NULL
        ORDER BY fp.created_date DESC
    ");
    $stmt->execute([$jtd_id]);
    sendResponse(true, $stmt->fetchAll(PDO::FETCH_ASSOC));
}

function getFormaList($conn, $user) {
    $page = max(1, intval($_GET['page'] ?? 1));
    $per_page = max(10, min(200, intval($_GET['per_page'] ?? 50)));
    $offset = ($page - 1) * $per_page;
    
    $where = ["fp.delete_by IS NULL"];
    $params = [];
    
    if (!empty($_GET['name'])) {
        $where[] = "fp.name LIKE ?";
        $params[] = '%' . $_GET['name'] . '%';
    }
    if (!empty($_GET['jt_code'])) {
        $where[] = "jt.job_ticket_code LIKE ?";
        $params[] = '%' . $_GET['jt_code'] . '%';
    }
    if (!empty($_GET['book_code'])) {
        $where[] = "b.book_code LIKE ?";
        $params[] = '%' . $_GET['book_code'] . '%';
    }
    if (!empty($_GET['fiscal_year_id'])) {
        $where[] = "fp.fiscal_year_id = ?";
        $params[] = $_GET['fiscal_year_id'];
    }
    if (!empty($_GET['machine_id'])) {
        $where[] = "fp.machine_id = ?";
        $params[] = $_GET['machine_id'];
    }
    if (isset($_GET['status']) && $_GET['status'] !== '') {
        $where[] = "fp.status = ?";
        $params[] = (bool)$_GET['status'];
    }
    if (!empty($_GET['start_date']) && !empty($_GET['end_date'])) {
        $where[] = "fp.date_nep BETWEEN ? AND ?";
        $params[] = $_GET['start_date'];
        $params[] = $_GET['end_date'];
    }
    
    $where_clause = implode(' AND ', $where);
    
    // Count total
    $count_query = "
        SELECT COUNT(*) as total
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        WHERE $where_clause
    ";
    $count_stmt = $conn->prepare($count_query);
    $count_stmt->execute($params);
    $total = (int)$count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
    
    // Fetch records
    $query = "
        SELECT 
            fp.id,
            fp.date_nep,
            fp.date_eng,
            fp.name,
            fp.jtd_targetqty,
            fp.fp_printqty,
            fp.fp_remainqty,
            fp.status,
            fp.remarks,
            fp.description,
            fp.created_date,
            jt.job_ticket_code,
            b.book_code,
            b.book_name,
            b.class_level,
            m.machine_name,
            fy.fiscal_code,
            su.username as supervisor_name,
            op.username as operator_name,
            inc.username as incharge_name,
            f.name as forma_name
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
        LEFT JOIN users su ON fp.supervisor_id = su.id
        LEFT JOIN users op ON fp.operator_id = op.id
        LEFT JOIN users inc ON fp.incharge_id = inc.id
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE $where_clause
        ORDER BY fp.created_date DESC
        LIMIT ? OFFSET ?
    ";
    $params[] = $per_page;
    $params[] = $offset;
    
    $stmt = $conn->prepare($query);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Cast numeric fields
    foreach ($records as &$rec) {
        $rec['jtd_targetqty'] = (int)$rec['jtd_targetqty'];
        $rec['fp_printqty'] = (int)$rec['fp_printqty'];
        $rec['fp_remainqty'] = (int)$rec['fp_remainqty'];
        $rec['class_level'] = (int)$rec['class_level'];
        $rec['status'] = (bool)$rec['status'];
    }
    
    sendResponse(true, [
        'records' => $records,
        'pagination' => [
            'total' => $total,
            'page' => $page,
            'per_page' => $per_page,
            'total_pages' => (int)ceil($total / $per_page)
        ]
    ]);
}

function getFormaDetail($conn, $user) {
    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendResponse(false, 'ID is required');

    $stmt = $conn->prepare("
        SELECT 
            fp.*,
            jt.job_ticket_code,
            b.book_name,
            b.class_level,
            m.machine_name,
            fy.fiscal_code,
            su.username as supervisor_name,
            op.username as operator_name,
            inc.username as incharge_name,
            f.name as forma_name,
            jtd.print_qty as jtd_targetqty
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
        LEFT JOIN users su ON fp.supervisor_id = su.id
        LEFT JOIN users op ON fp.operator_id = op.id
        LEFT JOIN users inc ON fp.incharge_id = inc.id
        LEFT JOIN forma f ON jtd.forma_id = f.id
        WHERE fp.id = ? AND fp.delete_by IS NULL
    ");
    $stmt->execute([$id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) sendResponse(false, 'Record not found', 404);
    
    $record['status'] = (bool)$record['status'];
    $record['jtd_targetqty'] = (int)$record['jtd_targetqty'];
    $record['fp_printqty'] = (int)$record['fp_printqty'];
    $record['fp_remainqty'] = (int)$record['fp_remainqty'];
    $record['class_level'] = (int)$record['class_level'];
    
    sendResponse(true, $record);
}

function checkRole($user, $allowed) {
    return in_array($user['role'], $allowed);
}

function handlePost($conn, $user, $input) {
    if (!checkRole($user, ['incharge','supervisor','admin','operator'])) {
        sendResponse(false, 'Permission denied', 403);
    }
    createFormaPrinting($conn, $user, $input);
}

function createFormaPrinting($conn, $user, $input) {
    $required = ['date_nep','date_eng','name','fiscal_year_id','jt_id','jtd_id',
        'jtd_targetqty','fp_printqty','fp_remainqty','supervisor_id',
        'operator_id','incharge_id','shift_id','machine_id'];
    
    foreach ($required as $field) {
        if (!isset($input[$field])) {
            sendResponse(false, "Missing required field: $field");
        }
    }
    
    if ($input['fp_printqty'] <= 0) {
        sendResponse(false, "Print quantity must be greater than 0");
    }

  $dateNepRaw = trim($input['date_nep'] ?? '');

// Case 1: yyyy.mm.dd → yyyy-mm-dd
if (preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $dateNepRaw)) {
    $dateNepFormatted = str_replace('.', '-', $dateNepRaw);

// Case 2: yyyymmdd → yyyy-mm-dd
} elseif (preg_match('/^\d{8}$/', $dateNepRaw)) {
    $dateNepFormatted = substr($dateNepRaw, 0, 4) . '-' .
                        substr($dateNepRaw, 4, 2) . '-' .
                        substr($dateNepRaw, 6, 2);

// Case 3: already yyyy-mm-dd
} elseif (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateNepRaw)) {
    $dateNepFormatted = $dateNepRaw;

// ❌ Invalid format
} else {
    sendResponse(false, "Invalid Nepali date format. Expected yyyy.mm.dd or yyyymmdd");
}

// Final safety check
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateNepFormatted)) {
    sendResponse(false, "Invalid Nepali date format after conversion");
}

    try {
        $conn->beginTransaction();
        
        // Check available quantity
        $stmt = $conn->prepare("
            SELECT jtd.print_qty as target_qty, COALESCE(SUM(fp.fp_printqty), 0) as printed
            FROM job_ticket_details jtd
            LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.delete_by IS NULL
            WHERE jtd.id = ?
            GROUP BY jtd.id, jtd.print_qty
        ");
        $stmt->execute([$input['jtd_id']]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($row) {
            $available = $row['target_qty'] - $row['printed'];
            if ($input['fp_printqty'] > $available) {
                sendResponse(false, "Print quantity exceeds available ($available)");
            }
        }
        
        $stmt = $conn->prepare("
            INSERT INTO forma_printing (
                date_nep, date_eng, name, fiscal_year_id, jt_id, jtd_id,
                jtd_targetqty, fp_printqty, fp_remainqty, supervisor_id,
                created_by, operator_id, incharge_id, shift_id, machine_id,
                remarks, description, status, created_date
            ) VALUES (
                ?, ?, ?, ?, ?, ?, ?, ?, ?, ?,
                ?, ?, ?, ?, ?,
                ?, ?, true, NOW()
            )
        ");
        
        $stmt->execute([
            $dateNepFormatted, // ← Now in yyyy-mm-dd format
            $input['date_eng'],
            $input['name'],
            $input['fiscal_year_id'],
            $input['jt_id'],
            $input['jtd_id'],
            $input['jtd_targetqty'],
            $input['fp_printqty'],
            $input['fp_remainqty'],
            $input['supervisor_id'],
            $user['id'],
            $input['operator_id'],
            $input['incharge_id'],
            $input['shift_id'],
            $input['machine_id'],
            $input['remarks'] ?? null,
            $input['description'] ?? null
        ]);
        
        $id = $conn->lastInsertId();
        $conn->commit();
        sendResponse(true, ['id' => $id, 'message' => 'Record created']);
        
    } catch (Exception $e) {
        $conn->rollBack();
        error_log("Create Forma Error: " . $e->getMessage());
        sendResponse(false, 'Failed to create record');
    }
}
function handlePut($conn, $user, $input) {
    if (!checkRole($user, ['incharge','supervisor','admin','operator'])) {
        sendResponse(false, 'Permission denied', 403);
    }
    if (!isset($input['id'])) sendResponse(false, 'ID is required');
    updateFormaPrinting($conn, $user, $input);
}

function updateFormaPrinting($conn, $user, $input) {
    $id = intval($input['id']);
    
    $check = $conn->prepare("SELECT id FROM forma_printing WHERE id = ? AND delete_by IS NULL");
    $check->execute([$id]);
    if (!$check->fetch()) sendResponse(false, 'Record not found', 404);
    
    $allowed = ['date_nep','date_eng','name','fiscal_year_id','jt_id','jtd_id',
        'jtd_targetqty','fp_printqty','fp_remainqty','supervisor_id',
        'operator_id','incharge_id','shift_id','machine_id',
        'remarks','description','status'];
    
    $sets = [];
    $params = [];
    foreach ($allowed as $field) {
        if (isset($input[$field])) {
            $sets[] = "$field = ?";
            $params[] = $input[$field];
        }
    }
    
    if (empty($sets)) sendResponse(false, 'No fields to update');
    
    $sets[] = "updated_by = ?";
    $params[] = $user['id'];
    $params[] = $id;
    
    $sql = "UPDATE forma_printing SET " . implode(', ', $sets) . " WHERE id = ?";
    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    
    sendResponse(true, ['id' => $id, 'message' => 'Record updated']);
}

function handleDelete($conn, $user) {
    if (!checkRole($user, ['incharge','supervisor','admin','operator'])) {
        sendResponse(false, 'Permission denied', 403);
    }
    
    $id = intval($_GET['id'] ?? 0);
    if (!$id) sendResponse(false, 'ID is required');
    
    $stmt = $conn->prepare("
        UPDATE forma_printing 
        SET delete_by = ?, status = false
        WHERE id = ? AND delete_by IS NULL
    ");
    $stmt->execute([$user['id'], $id]);
    
    if ($stmt->rowCount() === 0) {
        sendResponse(false, 'Record not found or already deleted', 404);
    }
    
    sendResponse(true, ['id' => $id, 'message' => 'Record deleted']);
}
?>
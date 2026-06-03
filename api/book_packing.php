<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

// Get request method and action
$method = $_SERVER['REQUEST_METHOD'];
$action = $_GET['action'] ?? '';

// Get logged_in_user_id based on request method
if ($method === 'GET' || $method === 'DELETE') {
    $logged_in_user_id = $_GET['logged_in_user_id'] ?? null;
} else if ($method === 'POST' || $method === 'PUT') {
    // For POST/PUT, first try to get from JSON body
    $input = file_get_contents('php://input');
    $inputData = json_decode($input, true);
    $logged_in_user_id = $inputData['logged_in_user_id'] ?? null;
    
    // If not in JSON body, try POST params (fallback)
    if (!$logged_in_user_id) {
        $logged_in_user_id = $_POST['logged_in_user_id'] ?? null;
    }
} else {
    $logged_in_user_id = null;
}

// Validate logged in user
if (!$logged_in_user_id) {
    echo json_encode(['success' => false, 'message' => 'User not logged in']);
    exit();
}

try {
    switch ($method) {
        case 'GET':
            handleGet($conn, $action, $logged_in_user_id);
            break;
        case 'POST':
            handlePost($conn, $logged_in_user_id);
            break;
        case 'PUT':
            handlePut($conn, $logged_in_user_id);
            break;
        case 'DELETE':
            handleDelete($conn, $logged_in_user_id);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    }
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => $e->getMessage()]);
}

function handleGet($conn, $action, $logged_in_user_id) {
    switch ($action) {
        case 'dropdowns':
            getDropdowns($conn);
            break;
        case 'job_tickets':
            getJobTickets($conn);
            break;
        case 'previous_packings':
            getPreviousPackings($conn);
            break;
        case 'list':
            getPackingList($conn);
            break;
        case 'detail':
            getPackingDetail($conn);
            break;
        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
}

function getDropdowns($conn) {
    try {
        // Get active fiscal years
        $stmt = $conn->query("
            SELECT id, fiscal_code 
            FROM fiscal_years 
            WHERE is_active = true 
            ORDER BY id DESC
        ");
        $fiscal_years = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get supervisors
        $stmt = $conn->query("
            SELECT id, username 
            FROM users 
            WHERE role IN ('supervisor', 'admin') 
            ORDER BY username
        ");
        $supervisors = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get operators
        $stmt = $conn->query("
            SELECT id, username 
            FROM users 
            WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') 
            ORDER BY username
        ");
        $operators = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Get incharges
        $stmt = $conn->query("
            SELECT id, username 
            FROM users 
            WHERE role IN ('incharge', 'supervisor', 'admin') 
            ORDER BY username
        ");
        $incharges = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => [
                'fiscal_years' => $fiscal_years,
                'supervisors' => $supervisors,
                'operators' => $operators,
                'incharges' => $incharges
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getJobTickets($conn) {
    try {
        $stmt = $conn->query("
            SELECT jt.id, jt.job_ticket_code, jt.lot, jt.print_qty, 
                   b.book_name, b.book_code, b.class_level
            FROM job_ticket jt
            LEFT JOIN books b ON jt.book_id = b.book_id
            WHERE jt.status IN ('active', 'pending') OR jt.status IS NULL
            ORDER BY jt.created_date DESC
        ");
        $job_tickets = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $job_tickets
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getPreviousPackings($conn) {
    $jt_id = $_GET['jt_id'] ?? null;
    
    if (!$jt_id) {
        echo json_encode(['success' => false, 'message' => 'Job ticket ID required']);
        return;
    }

    try {
        $stmt = $conn->prepare("
            SELECT bp.id, bp.name, bp.p_qty, bp.date_eng, bp.date_nep, 
                   bp.packing_status, u.username
            FROM book_packing bp
            LEFT JOIN users u ON bp.created_by = u.id
            WHERE bp.jt_id = :jt_id AND bp.status = true
            ORDER BY bp.created_date DESC
        ");
        $stmt->execute([':jt_id' => $jt_id]);
        $packings = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $packings
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getPackingList($conn) {
    try {
        $page = $_GET['page'] ?? 1;
        $per_page = $_GET['per_page'] ?? 20;
        $offset = ($page - 1) * $per_page;

        // Build WHERE conditions
        $where_conditions = ["bp.status = true"];
        $params = [];

        if (!empty($_GET['search'])) {
            $where_conditions[] = "(bp.name ILIKE :search OR bp.book_code ILIKE :search OR b.book_name ILIKE :search OR jt.job_ticket_code ILIKE :search)";
            $params[':search'] = '%' . $_GET['search'] . '%';
        }

        if (!empty($_GET['book_code'])) {
            $where_conditions[] = "bp.book_code = :book_code";
            $params[':book_code'] = $_GET['book_code'];
        }

        if (!empty($_GET['fiscal_year_id'])) {
            $where_conditions[] = "bp.fiscal_year_id = :fiscal_year_id";
            $params[':fiscal_year_id'] = $_GET['fiscal_year_id'];
        }

        if (!empty($_GET['packing_status'])) {
            $where_conditions[] = "bp.packing_status = :packing_status";
            $params[':packing_status'] = $_GET['packing_status'];
        }

        if (!empty($_GET['supervisor_id'])) {
            $where_conditions[] = "bp.supervisor_id = :supervisor_id";
            $params[':supervisor_id'] = $_GET['supervisor_id'];
        }

        $where_clause = implode(' AND ', $where_conditions);

        // Get total count
        $count_query = "
            SELECT COUNT(*) as total
            FROM book_packing bp
            LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
            LEFT JOIN books b ON jt.book_id = b.book_id
            WHERE $where_clause
        ";
        $count_stmt = $conn->prepare($count_query);
        $count_stmt->execute($params);
        $total = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];

        // Get data
        $query = "
            SELECT 
                bp.*,
                jt.job_ticket_code,
                jt.lot,
                b.book_name,
                b.book_code as book_code_full,
                b.class_level,
                fy.fiscal_code,
                u_supervisor.username as supervisor_name,
                u_incharge.username as incharge_name,
                u_operator.username as operator_name,
                u_created.username as created_by_name
            FROM book_packing bp
            LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
            LEFT JOIN books b ON jt.book_id = b.book_id
            LEFT JOIN fiscal_years fy ON bp.fiscal_year_id = fy.id
            LEFT JOIN users u_supervisor ON bp.supervisor_id = u_supervisor.id
            LEFT JOIN users u_incharge ON bp.incharge_id = u_incharge.id
            LEFT JOIN users u_operator ON bp.operator_id = u_operator.id
            LEFT JOIN users u_created ON bp.created_by = u_created.id
            WHERE $where_clause
            ORDER BY bp.created_date DESC, bp.id DESC
            LIMIT :limit OFFSET :offset
        ";

        $stmt = $conn->prepare($query);
        foreach ($params as $key => $value) {
            $stmt->bindValue($key, $value);
        }
        $stmt->bindValue(':limit', (int)$per_page, PDO::PARAM_INT);
        $stmt->bindValue(':offset', (int)$offset, PDO::PARAM_INT);
        $stmt->execute();
        $data = $stmt->fetchAll(PDO::FETCH_ASSOC);

        echo json_encode([
            'success' => true,
            'data' => $data,
            'pagination' => [
                'current_page' => (int)$page,
                'per_page' => (int)$per_page,
                'total' => (int)$total,
                'total_pages' => ceil($total / $per_page)
            ]
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function getPackingDetail($conn) {
    $id = $_GET['id'] ?? null;
    
    if (!$id) {
        echo json_encode(['success' => false, 'message' => 'Packing ID required']);
        return;
    }

    try {
        $stmt = $conn->prepare("
            SELECT 
                bp.*,
                jt.job_ticket_code,
                jt.lot,
                b.book_name,
                b.book_code as book_code_full,
                b.class_level,
                fy.fiscal_code,
                u_supervisor.username as supervisor_name,
                u_incharge.username as incharge_name,
                u_operator.username as operator_name,
                u_created.username as created_by_name
            FROM book_packing bp
            LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
            LEFT JOIN books b ON jt.book_id = b.book_id
            LEFT JOIN fiscal_years fy ON bp.fiscal_year_id = fy.id
            LEFT JOIN users u_supervisor ON bp.supervisor_id = u_supervisor.id
            LEFT JOIN users u_incharge ON bp.incharge_id = u_incharge.id
            LEFT JOIN users u_operator ON bp.operator_id = u_operator.id
            LEFT JOIN users u_created ON bp.created_by = u_created.id
            WHERE bp.id = :id AND bp.status = true
        ");
        $stmt->execute([':id' => $id]);
        $data = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($data) {
            echo json_encode(['success' => true, 'data' => $data]);
        } else {
            echo json_encode(['success' => false, 'message' => 'Packing record not found']);
        }
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handlePost($conn, $logged_in_user_id) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data) {
            echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
            return;
        }

        $conn->beginTransaction();

        // Validate required fields
        $required = ['name', 'jt_id', 'jt_print_qty', 'p_qty', 'book_code', 'date_nep', 
                     'date_eng', 'supervisor_id', 'incharge_id', 'operator_id', 'fiscal_year_id'];
        
        foreach ($required as $field) {
            if (empty($data[$field])) {
                throw new Exception("Field '$field' is required");
            }
        }

        $jt_id = (int)$data['jt_id'];
        $p_qty = (int)$data['p_qty'];
        $jt_print_qty = (int)$data['jt_print_qty'];

        // Check total packed quantity
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(p_qty), 0) as total_packed 
            FROM book_packing 
            WHERE jt_id = :jt_id AND status = true
        ");
        $stmt->execute([':jt_id' => $jt_id]);
        $total_packed = (int)$stmt->fetch()['total_packed'];

        if ($p_qty + $total_packed > $jt_print_qty) {
            throw new Exception("Total packed quantity (" . ($total_packed + $p_qty) . ") exceeds print quantity ($jt_print_qty)");
        }

        // Insert packing record
        $insert_sql = "
            INSERT INTO book_packing (
                name, jt_id, jt_print_qty, p_qty, book_code, date_nep, date_eng,
                supervisor_id, incharge_id, operator_id, status, packing_status,
                created_by, fiscal_year_id, remarks, description
            ) VALUES (
                :name, :jt_id, :jt_print_qty, :p_qty, :book_code, :date_nep, :date_eng,
                :supervisor_id, :incharge_id, :operator_id, true, :packing_status,
                :created_by, :fiscal_year_id, :remarks, :description
            )
        ";

        $stmt = $conn->prepare($insert_sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':jt_id' => $jt_id,
            ':jt_print_qty' => $jt_print_qty,
            ':p_qty' => $p_qty,
            ':book_code' => $data['book_code'],
            ':date_nep' => $data['date_nep'],
            ':date_eng' => $data['date_eng'],
            ':supervisor_id' => $data['supervisor_id'],
            ':incharge_id' => $data['incharge_id'],
            ':operator_id' => $data['operator_id'],
            ':packing_status' => $data['packing_status'] ?? 'active',
            ':created_by' => $logged_in_user_id,
            ':fiscal_year_id' => $data['fiscal_year_id'],
            ':remarks' => $data['remarks'] ?? null,
            ':description' => $data['description'] ?? null
        ]);

        $packing_id = $conn->lastInsertId();

        // Update job ticket status if fully packed
        $new_total = $total_packed + $p_qty;
        if ($new_total >= $jt_print_qty) {
            $stmt = $conn->prepare("UPDATE job_ticket SET status = 'bp_completed' WHERE id = :jt_id");
            $stmt->execute([':jt_id' => $jt_id]);
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Packing record created successfully',
            'data' => ['id' => $packing_id]
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handlePut($conn, $logged_in_user_id) {
    try {
        $input = file_get_contents('php://input');
        $data = json_decode($input, true);

        if (!$data || empty($data['id'])) {
            echo json_encode(['success' => false, 'message' => 'Invalid data or missing ID']);
            return;
        }

        $conn->beginTransaction();

        $id = (int)$data['id'];
        $jt_id = (int)$data['jt_id'];
        $p_qty = (int)$data['p_qty'];
        $jt_print_qty = (int)$data['jt_print_qty'];

        // Get current packed quantity for this record
        $stmt = $conn->prepare("SELECT p_qty FROM book_packing WHERE id = :id");
        $stmt->execute([':id' => $id]);
        $current_record = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$current_record) {
            throw new Exception("Packing record not found");
        }
        
        $old_p_qty = (int)$current_record['p_qty'];

        // Calculate total packed (excluding current record)
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(p_qty), 0) as total_packed 
            FROM book_packing 
            WHERE jt_id = :jt_id AND status = true AND id != :id
        ");
        $stmt->execute([':jt_id' => $jt_id, ':id' => $id]);
        $total_packed_others = (int)$stmt->fetch()['total_packed'];

        // Validate: new total should not exceed print qty
        if ($p_qty + $total_packed_others > $jt_print_qty) {
            throw new Exception("Total packed quantity (" . ($total_packed_others + $p_qty) . ") exceeds print quantity ($jt_print_qty)");
        }

        // Update packing record
        $update_sql = "
            UPDATE book_packing SET
                name = :name,
                jt_id = :jt_id,
                jt_print_qty = :jt_print_qty,
                p_qty = :p_qty,
                book_code = :book_code,
                date_nep = :date_nep,
                date_eng = :date_eng,
                supervisor_id = :supervisor_id,
                incharge_id = :incharge_id,
                operator_id = :operator_id,
                packing_status = :packing_status,
                fiscal_year_id = :fiscal_year_id,
                remarks = :remarks,
                description = :description,
                updated_by = :updated_by,
                updated_date = NOW()
            WHERE id = :id
        ";

        $stmt = $conn->prepare($update_sql);
        $stmt->execute([
            ':name' => $data['name'],
            ':jt_id' => $jt_id,
            ':jt_print_qty' => $jt_print_qty,
            ':p_qty' => $p_qty,
            ':book_code' => $data['book_code'],
            ':date_nep' => $data['date_nep'],
            ':date_eng' => $data['date_eng'],
            ':supervisor_id' => $data['supervisor_id'],
            ':incharge_id' => $data['incharge_id'],
            ':operator_id' => $data['operator_id'],
            ':packing_status' => $data['packing_status'] ?? 'active',
            ':fiscal_year_id' => $data['fiscal_year_id'],
            ':remarks' => $data['remarks'] ?? null,
            ':description' => $data['description'] ?? null,
            ':updated_by' => $logged_in_user_id,
            ':id' => $id
        ]);

        // Update job ticket status
        $new_total = $total_packed_others + $p_qty;
        if ($new_total >= $jt_print_qty) {
            $stmt = $conn->prepare("UPDATE job_ticket SET status = 'bp_completed' WHERE id = :jt_id");
            $stmt->execute([':jt_id' => $jt_id]);
        } else {
            // If total is now less than print qty, change status back to active
            $stmt = $conn->prepare("UPDATE job_ticket SET status = 'active' WHERE id = :jt_id AND status = 'bp_completed'");
            $stmt->execute([':jt_id' => $jt_id]);
        }

        $conn->commit();

        echo json_encode([
            'success' => true,
            'message' => 'Packing record updated successfully',
            'data' => ['id' => $id]
        ]);
    } catch (Exception $e) {
        $conn->rollBack();
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}

function handleDelete($conn, $logged_in_user_id) {
    try {
        $id = $_GET['id'] ?? null;
        
        if (!$id) {
            echo json_encode(['success' => false, 'message' => 'Packing ID required']);
            return;
        }

        $stmt = $conn->prepare("
            UPDATE book_packing 
            SET status = false, updated_by = :updated_by, updated_date = NOW() 
            WHERE id = :id
        ");
        $stmt->execute([
            ':id' => $id,
            ':updated_by' => $logged_in_user_id
        ]);

        echo json_encode([
            'success' => true,
            'message' => 'Packing record deleted successfully'
        ]);
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => $e->getMessage()]);
    }
}
?>
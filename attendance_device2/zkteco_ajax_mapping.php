<?php
/**
 * AJAX Handler for User Mapping Operations
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once __DIR__ . '/ZKLibrary.php';

header('Content-Type: application/json');

if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$action = $_POST['action'] ?? '';

try {
    switch ($action) {
        case 'add_mapping':
            addMapping();
            break;
        case 'update_mapping':
            updateMapping();
            break;
        case 'delete_mapping':
            deleteMapping();
            break;
        case 'sync_mapping':
            syncMapping();
            break;
        case 'sync_all_mappings':
            syncAllMappings();
            break;
        case 'pull_device_users':
            pullDeviceUsers();
            break;
        case 'auto_map_users':
            autoMapUsers();
            break;
        default:
            throw new Exception("Invalid action: $action");
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage()
    ]);
}

function addMapping() {
    global $conn;
    
    $device_id = $_POST['device_id'] ?? null;
    $employee_id = $_POST['employee_id'] ?? null;
    $device_user_id = $_POST['device_user_id'] ?? '';
    $device_uid = $_POST['device_uid'] ?? 0;
    $shift_id = $_POST['shift_id'] ?? null;
    $shift_type = $_POST['shift_type'] ?? 'REGULAR';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $push_to_device = isset($_POST['push_to_device']) ? 1 : 0;
    $notes = $_POST['notes'] ?? '';
    
    if (!$device_id || !$employee_id || !$device_user_id || !$device_uid) {
        throw new Exception('All required fields must be filled');
    }
    
    // Check for duplicate
    $stmt = $conn->prepare("
        SELECT id FROM zkteco_user_mapping 
        WHERE device_id = :device AND device_user_id = :user_id
    ");
    $stmt->execute([':device' => $device_id, ':user_id' => $device_user_id]);
    if ($stmt->fetch()) {
        throw new Exception('This device user ID is already mapped');
    }
    
    // Insert mapping
    $stmt = $conn->prepare("
        INSERT INTO zkteco_user_mapping (
            device_id, device_user_id, device_uid, employee_id,
            shift_id, shift_type, is_active, mapped_by, notes
        ) VALUES (
            :device, :user_id, :uid, :employee,
            :shift, :shift_type, :active, :mapped_by, :notes
        )
    ");
    
    $stmt->execute([
        ':device' => $device_id,
        ':user_id' => $device_user_id,
        ':uid' => $device_uid,
        ':employee' => $employee_id,
        ':shift' => $shift_id,
        ':shift_type' => $shift_type,
        ':active' => $is_active,
        ':mapped_by' => $_SESSION['user_id'],
        ':notes' => $notes
    ]);
    
    $mapping_id = $conn->lastInsertId();
    
    // Push to device if requested
    if ($push_to_device) {
        syncMappingToDevice($mapping_id);
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Mapping added successfully',
        'mapping_id' => $mapping_id
    ]);
}

function updateMapping() {
    global $conn;
    
    $mapping_id = $_POST['id'] ?? null;
    
    if (!$mapping_id) {
        throw new Exception('Mapping ID required');
    }
    
    $shift_id = $_POST['shift_id'] ?? null;
    $shift_type = $_POST['shift_type'] ?? 'REGULAR';
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $notes = $_POST['notes'] ?? '';
    
    $stmt = $conn->prepare("
        UPDATE zkteco_user_mapping SET
            shift_id = :shift,
            shift_type = :shift_type,
            is_active = :active,
            notes = :notes,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':id' => $mapping_id,
        ':shift' => $shift_id,
        ':shift_type' => $shift_type,
        ':active' => $is_active,
        ':notes' => $notes
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Mapping updated successfully'
    ]);
}

function deleteMapping() {
    global $conn;
    
    $mapping_id = $_POST['mapping_id'] ?? null;
    
    if (!$mapping_id) {
        throw new Exception('Mapping ID required');
    }
    
    // Get mapping info for device deletion
    $stmt = $conn->prepare("SELECT * FROM zkteco_user_mapping WHERE id = :id");
    $stmt->execute([':id' => $mapping_id]);
    $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mapping) {
        throw new Exception('Mapping not found');
    }
    
    // Delete from database
    $stmt = $conn->prepare("DELETE FROM zkteco_user_mapping WHERE id = :id");
    $stmt->execute([':id' => $mapping_id]);
    
    // Try to delete from device (optional - don't fail if it errors)
    try {
        deleteUserFromDevice($mapping['device_id'], $mapping['device_uid']);
    } catch (Exception $e) {
        // Ignore device errors
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Mapping deleted successfully'
    ]);
}

function syncMapping() {
    $mapping_id = $_POST['mapping_id'] ?? null;
    
    if (!$mapping_id) {
        throw new Exception('Mapping ID required');
    }
    
    syncMappingToDevice($mapping_id);
    
    echo json_encode([
        'success' => true,
        'message' => 'Mapping synced to device successfully'
    ]);
}

function syncAllMappings() {
    global $conn;
    
    $device_id = $_POST['device_id'] ?? null;
    
    if (!$device_id) {
        throw new Exception('Device ID required');
    }
    
    // Get all unsync mappings
    $stmt = $conn->prepare("
        SELECT id FROM zkteco_user_mapping 
        WHERE device_id = :device 
        AND is_active = true 
        AND synced_to_device = false
    ");
    $stmt->execute([':device' => $device_id]);
    $mappings = $stmt->fetchAll(PDO::FETCH_COLUMN);
    
    $synced_count = 0;
    
    foreach ($mappings as $mapping_id) {
        try {
            syncMappingToDevice($mapping_id);
            $synced_count++;
        } catch (Exception $e) {
            // Continue with others
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Synced $synced_count mappings successfully",
        'synced_count' => $synced_count
    ]);
}

function pullDeviceUsers() {
    global $conn;
    
    $device_id = $_POST['device_id'] ?? null;
    
    if (!$device_id) {
        throw new Exception('Device ID required');
    }
    
    // Get device
    $stmt = $conn->prepare("SELECT * FROM zkteco_devices WHERE id = :id");
    $stmt->execute([':id' => $device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        throw new Exception('Device not found');
    }
    
    // Connect to device
    $zk = new ZKLibrary($device['ip_address'], $device['port']);
    $zk->setTimeout($device['timeout']);
    
    if (!$zk->connect()) {
        throw new Exception('Failed to connect to device');
    }
    
    // Get users
    $users = $zk->getUser();
    $zk->disconnect();
    
    if ($users === false) {
        throw new Exception('Failed to retrieve users from device');
    }
    
    // Format for response
    $formatted_users = [];
    foreach ($users as $uid => $user) {
        $formatted_users[] = [
            'uid' => $uid,
            'userid' => $user['userid'],
            'name' => $user['name'],
            'cardno' => $user['cardno'],
            'role' => $user['role']
        ];
    }
    
    echo json_encode([
        'success' => true,
        'users' => $formatted_users,
        'count' => count($formatted_users)
    ]);
}

function autoMapUsers() {
    global $conn;
    
    $device_id = $_POST['device_id'] ?? null;
    
    if (!$device_id) {
        throw new Exception('Device ID required');
    }
    
    // Get device
    $stmt = $conn->prepare("SELECT * FROM zkteco_devices WHERE id = :id");
    $stmt->execute([':id' => $device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        throw new Exception('Device not found');
    }
    
    // Connect and get users
    $zk = new ZKLibrary($device['ip_address'], $device['port']);
    $zk->setTimeout($device['timeout']);
    
    if (!$zk->connect()) {
        throw new Exception('Failed to connect to device');
    }
    
    $device_users = $zk->getUser();
    $zk->disconnect();
    
    if ($device_users === false) {
        throw new Exception('Failed to retrieve users from device');
    }
    
    // Get employees with attendance_id
    $stmt = $conn->query("
        SELECT id, attendance_id, name 
        FROM employee 
        WHERE attendance_id IS NOT NULL 
        AND deleted_date IS NULL
    ");
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $mapped_count = 0;
    
    foreach ($employees as $employee) {
        $attendance_id = trim($employee['attendance_id']);
        
        // Find matching device user
        foreach ($device_users as $uid => $device_user) {
            if (trim($device_user['userid']) == $attendance_id) {
                // Check if already mapped
                $stmt = $conn->prepare("
                    SELECT id FROM zkteco_user_mapping 
                    WHERE device_id = :device AND device_uid = :uid
                ");
                $stmt->execute([':device' => $device_id, ':uid' => $uid]);
                
                if (!$stmt->fetch()) {
                    // Create mapping
                    $stmt = $conn->prepare("
                        INSERT INTO zkteco_user_mapping (
                            device_id, device_user_id, device_uid, employee_id,
                            shift_type, is_active, synced_to_device, mapped_by
                        ) VALUES (
                            :device, :user_id, :uid, :employee,
                            'REGULAR', 1, 1, :mapped_by
                        )
                    ");
                    
                    $stmt->execute([
                        ':device' => $device_id,
                        ':user_id' => $device_user['userid'],
                        ':uid' => $uid,
                        ':employee' => $employee['id'],
                        ':mapped_by' => $_SESSION['user_id']
                    ]);
                    
                    $mapped_count++;
                }
                
                break;
            }
        }
    }
    
    echo json_encode([
        'success' => true,
        'message' => "Auto-mapped $mapped_count users",
        'mapped_count' => $mapped_count
    ]);
}

// Helper function to sync mapping to device
function syncMappingToDevice($mapping_id) {
    global $conn;
    
    // Get mapping with employee info
    $stmt = $conn->prepare("
        SELECT zum.*, e.name, e.emp_code, d.*
        FROM zkteco_user_mapping zum
        JOIN employee e ON zum.employee_id = e.id
        JOIN zkteco_devices d ON zum.device_id = d.id
        WHERE zum.id = :id
    ");
    $stmt->execute([':id' => $mapping_id]);
    $mapping = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$mapping) {
        throw new Exception('Mapping not found');
    }
    
    // Connect to device
    $zk = new ZKLibrary($mapping['ip_address'], $mapping['port']);
    $zk->setTimeout($mapping['timeout']);
    
    if (!$zk->connect()) {
        throw new Exception('Failed to connect to device');
    }
    
    if ($mapping['disable_during_pull']) {
        $zk->disableDevice();
    }
    
    // Set user on device
    $result = $zk->setUser(
        $mapping['device_uid'],
        $mapping['device_user_id'],
        $mapping['name'],
        '', // password (empty for now)
        ZKLibrary::LEVEL_USER
    );
    
    if ($mapping['disable_during_pull']) {
        $zk->enableDevice();
    }
    
    $zk->disconnect();
    
    if (!$result) {
        throw new Exception('Failed to set user on device');
    }
    
    // Update sync status
    $stmt = $conn->prepare("
        UPDATE zkteco_user_mapping SET
            synced_to_device = true,
            last_synced_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    $stmt->execute([':id' => $mapping_id]);
}

// Helper function to delete user from device
function deleteUserFromDevice($device_id, $device_uid) {
    global $conn;
    
    // Get device
    $stmt = $conn->prepare("SELECT * FROM zkteco_devices WHERE id = :id");
    $stmt->execute([':id' => $device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        return;
    }
    
    // Connect
    $zk = new ZKLibrary($device['ip_address'], $device['port']);
    $zk->setTimeout($device['timeout']);
    
    if ($zk->connect()) {
        if ($device['disable_during_pull']) {
            $zk->disableDevice();
        }
        
        $zk->deleteUser($device_uid);
        
        if ($device['disable_during_pull']) {
            $zk->enableDevice();
        }
        
        $zk->disconnect();
    }
}
?>

<?php
/**
 * AJAX Handler for Device Operations
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
        case 'add_device':
            addDevice();
            break;
        case 'update_device':
            updateDevice();
            break;
        case 'delete_device':
            deleteDevice();
            break;
        case 'test_connection':
            testConnection();
            break;
        case 'get_device_info':
            getDeviceInfo();
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

function addDevice() {
    global $conn;
    
    $device_code = $_POST['device_code'] ?? '';
    $device_name = $_POST['device_name'] ?? '';
    $ip_address = $_POST['ip_address'] ?? '';
    $port = $_POST['port'] ?? 4370;
    $location = $_POST['location'] ?? '';
    $timeout = $_POST['timeout'] ?? 5;
    $priority = $_POST['priority'] ?? 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $disable_during_pull = isset($_POST['disable_during_pull']) ? 1 : 0;
    $auto_clear_records = isset($_POST['auto_clear_records']) ? 1 : 0;
    $notes = $_POST['notes'] ?? '';
    
    // Validate
    if (empty($device_code) || empty($device_name) || empty($ip_address)) {
        throw new Exception('Device code, name, and IP address are required');
    }
    
    // Check for duplicate
    $stmt = $conn->prepare("SELECT id FROM zkteco_devices WHERE device_code = :code");
    $stmt->execute([':code' => $device_code]);
    if ($stmt->fetch()) {
        throw new Exception('Device code already exists');
    }
    
    // Insert device
    $stmt = $conn->prepare("
        INSERT INTO zkteco_devices (
            device_code, device_name, ip_address, port, location,
            timeout, priority, is_active, disable_during_pull,
            auto_clear_records, notes
        ) VALUES (
            :code, :name, :ip, :port, :location,
            :timeout, :priority, :active, :disable_pull,
            :auto_clear, :notes
        )
    ");
    
    $stmt->execute([
        ':code' => $device_code,
        ':name' => $device_name,
        ':ip' => $ip_address,
        ':port' => $port,
        ':location' => $location,
        ':timeout' => $timeout,
        ':priority' => $priority,
        ':active' => $is_active,
        ':disable_pull' => $disable_during_pull,
        ':auto_clear' => $auto_clear_records,
        ':notes' => $notes
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Device added successfully',
        'device_id' => $conn->lastInsertId()
    ]);
}

function updateDevice() {
    global $conn;
    
    $device_id = $_POST['id'] ?? null;
    
    if (!$device_id) {
        throw new Exception('Device ID required');
    }
    
    $device_code = $_POST['device_code'] ?? '';
    $device_name = $_POST['device_name'] ?? '';
    $ip_address = $_POST['ip_address'] ?? '';
    $port = $_POST['port'] ?? 4370;
    $location = $_POST['location'] ?? '';
    $timeout = $_POST['timeout'] ?? 5;
    $priority = $_POST['priority'] ?? 0;
    $is_active = isset($_POST['is_active']) ? 1 : 0;
    $disable_during_pull = isset($_POST['disable_during_pull']) ? 1 : 0;
    $auto_clear_records = isset($_POST['auto_clear_records']) ? 1 : 0;
    $notes = $_POST['notes'] ?? '';
    
    // Update device
    $stmt = $conn->prepare("
        UPDATE zkteco_devices SET
            device_code = :code,
            device_name = :name,
            ip_address = :ip,
            port = :port,
            location = :location,
            timeout = :timeout,
            priority = :priority,
            is_active = :active,
            disable_during_pull = :disable_pull,
            auto_clear_records = :auto_clear,
            notes = :notes,
            updated_at = CURRENT_TIMESTAMP
        WHERE id = :id
    ");
    
    $stmt->execute([
        ':id' => $device_id,
        ':code' => $device_code,
        ':name' => $device_name,
        ':ip' => $ip_address,
        ':port' => $port,
        ':location' => $location,
        ':timeout' => $timeout,
        ':priority' => $priority,
        ':active' => $is_active,
        ':disable_pull' => $disable_during_pull,
        ':auto_clear' => $auto_clear_records,
        ':notes' => $notes
    ]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Device updated successfully'
    ]);
}

function deleteDevice() {
    global $conn;
    
    $device_id = $_POST['device_id'] ?? null;
    
    if (!$device_id) {
        throw new Exception('Device ID required');
    }
    
    // Delete device (cascade will delete mappings)
    $stmt = $conn->prepare("DELETE FROM zkteco_devices WHERE id = :id");
    $stmt->execute([':id' => $device_id]);
    
    echo json_encode([
        'success' => true,
        'message' => 'Device deleted successfully'
    ]);
}

function testConnection() {
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
    
    // Test connection
    $zk = new ZKLibrary($device['ip_address'], $device['port']);
    $zk->setTimeout($device['timeout']);
    
    if ($zk->connect()) {
        // Update status
        $stmt = $conn->prepare("
            UPDATE zkteco_devices SET
                connection_status = 'ONLINE',
                last_online_at = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $device_id]);
        
        $zk->disconnect();
        
        echo json_encode([
            'success' => true,
            'message' => 'Connection successful',
            'device' => $device['device_name']
        ]);
    } else {
        // Update status
        $stmt = $conn->prepare("
            UPDATE zkteco_devices SET
                connection_status = 'OFFLINE'
            WHERE id = :id
        ");
        $stmt->execute([':id' => $device_id]);
        
        throw new Exception('Failed to connect to device. Check IP address, port, and network connectivity.');
    }
}

function getDeviceInfo() {
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
    
    // Connect and get info
    $zk = new ZKLibrary($device['ip_address'], $device['port']);
    $zk->setTimeout($device['timeout']);
    
    if (!$zk->connect()) {
        throw new Exception('Failed to connect to device');
    }
    
    $info = [];
    
    // Get version
    $info['version'] = $zk->version();
    
    // Get serial number
    $info['serial_number'] = $zk->serialNumber();
    
    // Get platform
    $info['platform'] = $zk->platform();
    
    // Get OS version
    $info['os_version'] = $zk->osVersion();
    
    // Get device time
    $info['device_time'] = $zk->getTime();
    
    // Get capacity info
    $capacity = $zk->getFreeSizes();
    if ($capacity) {
        $info['users_count'] = $capacity['users'];
        $info['logs_count'] = $capacity['logs'];
        $info['capacity_users'] = $capacity['capacity_users'];
        $info['capacity_logs'] = $capacity['capacity_logs'];
        
        // Update database
        $stmt = $conn->prepare("
            UPDATE zkteco_devices SET
                total_users = :users,
                total_logs = :logs,
                capacity_users = :cap_users,
                capacity_logs = :cap_logs,
                serial_number = :serial
            WHERE id = :id
        ");
        $stmt->execute([
            ':users' => $capacity['users'],
            ':logs' => $capacity['logs'],
            ':cap_users' => $capacity['capacity_users'],
            ':cap_logs' => $capacity['capacity_logs'],
            ':serial' => $info['serial_number'],
            ':id' => $device_id
        ]);
    }
    
    $zk->disconnect();
    
    echo json_encode([
        'success' => true,
        'info' => $info
    ]);
}
?>

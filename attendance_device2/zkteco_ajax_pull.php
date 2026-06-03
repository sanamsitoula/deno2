<?php
/**
 * AJAX Handler for Manual Pull Operations
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

// Debug logging
$debug_log = [];
function debugLog($label, $value = null) {
    global $debug_log;
    $debug_log[] = ['label' => $label, 'value' => $value];
}

try {
    switch ($action) {
        case 'manual_pull':
            executeManualPull();
            break;
        case 'test_connection':
            testConnection();
            break;
        default:
            throw new Exception("Invalid action: $action");
    }
} catch (Exception $e) {
    echo json_encode([
        'success' => false,
        'error' => $e->getMessage(),
        '_debug' => $debug_log
    ]);
}

function executeManualPull() {
    global $conn, $debug_log;
    
    $device_id = $_POST['device_id'] ?? null;
    $date = $_POST['date'] ?? date('Y-m-d');
    $schedule = $_POST['schedule'] ?? null;
    
    debugLog('executeManualPull', [
        'device_id' => $device_id,
        'date' => $date,
        'schedule' => $schedule
    ]);
    
    if (!$device_id) {
        throw new Exception('Device ID required');
    }
    
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        throw new Exception("Invalid date format: $date");
    }
    
    // Get PHP CLI path
    $php_bin = getPhpCliBinary();
    debugLog('PHP_BINARY', $php_bin);
    
    if (!file_exists($php_bin)) {
        throw new Exception("PHP CLI binary not found at: $php_bin");
    }
    
    // Build command
    $script_path = __DIR__ . '/zkteco_puller.php';
    $doc_root = $_SERVER['DOCUMENT_ROOT'];
    
    $cmd_parts = [
        '"' . str_replace('"', '""', $php_bin) . '"',
        '"' . str_replace('"', '""', $script_path) . '"',
        '--device=' . escapeshellarg($device_id),
        '--date=' . escapeshellarg($date),
        '--doc_root=' . escapeshellarg($doc_root)
    ];
    
    if ($schedule) {
        $cmd_parts[] = '--schedule=' . escapeshellarg($schedule);
    }
    
    $cmd = implode(' ', $cmd_parts);
    debugLog('CMD', $cmd);
    
    // Execute
    $descriptorspec = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w']
    ];
    
    $process = proc_open($cmd, $descriptorspec, $pipes);
    
    if (!is_resource($process)) {
        throw new Exception('Failed to execute command');
    }
    
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    
    $return_code = proc_close($process);
    
    debugLog('EXIT_CODE', $return_code);
    debugLog('OUTPUT', $stdout . "\n" . $stderr);
    
    if ($return_code === 0) {
        // Fetch pull log
        $stmt = $conn->prepare("
            SELECT *
            FROM zkteco_pull_log
            WHERE device_id = :device_id
            AND pull_date = :date
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $stmt->execute([':device_id' => $device_id, ':date' => $date]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($log) {
            echo json_encode([
                'success' => true,
                'message' => 'Pull completed successfully',
                'stats' => [
                    'inserted' => $log['inserted_records'],
                    'updated' => $log['updated_records'],
                    'skipped' => $log['skipped_records'],
                    'errors' => $log['error_records'],
                    'employees_count' => $log['employees_processed']
                ],
                '_debug' => $debug_log
            ]);
        } else {
            echo json_encode([
                'success' => true,
                'message' => 'Pull executed',
                'output' => $stdout,
                '_debug' => $debug_log
            ]);
        }
    } else {
        throw new Exception("Pull failed with exit code $return_code: $stderr");
    }
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
            'message' => 'Connection successful'
        ]);
    } else {
        // Update status
        $stmt = $conn->prepare("
            UPDATE zkteco_devices SET connection_status = 'OFFLINE' WHERE id = :id
        ");
        $stmt->execute([':id' => $device_id]);
        
        throw new Exception('Failed to connect to device');
    }
}

function getPhpCliBinary() {
    // Check common locations
    $candidates = [
        'C:\\xampp\\php\\php.exe',
        'C:\\wamp64\\bin\\php\\php.exe',
        'C:\\Program Files\\PHP\\php.exe',
        dirname(PHP_BINARY) . '\\php.exe'
    ];
    
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    // Check database setting
    global $conn;
    try {
        $stmt = $conn->prepare("SELECT setting_value FROM zkteco_settings WHERE setting_key = 'php_cli_path'");
        $stmt->execute();
        $setting = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($setting && file_exists($setting['setting_value'])) {
            return $setting['setting_value'];
        }
    } catch (Exception $e) {
        // Ignore
    }
    
    return PHP_BINARY;
}
?>

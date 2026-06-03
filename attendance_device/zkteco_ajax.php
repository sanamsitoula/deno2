<?php
/**
* ZKTeco AJAX Handler
* Fixed for Windows environments:
*  - PHP_BINARY path quoting (handles spaces in Program Files)
*  - DOCUMENT_ROOT not available in CLI child process
*  - Full error capture from exec() output
*  - Structured JSON error responses for browser console debugging
*/
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/attendance_device/ZKLibrary.php';
header('Content-Type: application/json');

// ============================================================
// FUNCTION: Get correct PHP CLI binary path on Windows
// ============================================================
function getPhpCliBinary() {
    // Method 1: Check common XAMPP/WAMP locations
    $candidates = [
        'C:\\xampp\\php\\php.exe',
        'C:\\Program Files\\PHP\\php.exe',
        'C:\\Program Files (x86)\\PHP\\php.exe',
        dirname(PHP_BINARY) . '\\php.exe', // Same dir as httpd.exe → likely has php.exe
        $_SERVER['SystemRoot'] . '\\php.exe'
    ];
    
    foreach ($candidates as $path) {
        if (file_exists($path)) {
            return $path;
        }
    }
    
    // Method 2: Try PATH environment variable
    $path_env = getenv('PATH');
    if ($path_env) {
        $paths = explode(';', $path_env);
        foreach ($paths as $dir) {
            $php_path = rtrim($dir, '\\/') . '\\php.exe';
            if (file_exists($php_path)) {
                return $php_path;
            }
        }
    }
    
    // Method 3: Check system settings table
    global $conn;
    if ($conn) {
        try {
            $stmt = $conn->prepare("SELECT value FROM system_settings WHERE key = 'php_cli_path'");
            $stmt->execute();
            $setting = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($setting && file_exists($setting['value'])) {
                return $setting['value'];
            }
        } catch (Exception $e) {
            // Ignore - setting table might not exist yet
        }
    }
    
    // Fallback: Use PHP_BINARY but warn it might be wrong
    return PHP_BINARY;
}

// ============================================================
// Debug logger — writes to a log file AND returns in response
// ============================================================
$debug_log = [];
function debugLog($label, $value = null) {
    global $debug_log;
    $entry = [
        'time'  => date('H:i:s'),
        'label' => $label,
        'value' => $value
    ];
    $debug_log[] = $entry;
    
    // Also append to a rolling file log (safe path on Windows)
    $log_dir  = __DIR__ . '/logs/zkteco/';
    $log_file = $log_dir . 'ajax_debug_' . date('Y-m-d') . '.log';
    if (!is_dir($log_dir)) {
        @mkdir($log_dir, 0777, true);
    }
    
    $line = '[' . $entry['time'] . '] ' . $label;
    if ($value !== null) {
        $line .= ': ' . (is_string($value) ? $value : json_encode($value));
    }
    @file_put_contents($log_file, $line . PHP_EOL, FILE_APPEND);
}

function sendResponse(array $payload) {
    global $debug_log;
    // Always attach debug log so browser console shows full trace
    $payload['_debug'] = $debug_log;
    // Discard any stray output (PHP warnings, BOM, etc.) before JSON
    ob_end_clean();
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
    exit();
}

// ============================================================
// Auth check
// ============================================================
if (!isset($_SESSION['user_id'])) {
    debugLog('AUTH', 'No session user_id — unauthorized');
    sendResponse(['success' => false, 'error' => 'Unauthorized']);
}

$action = $_POST['action'] ?? '';
debugLog('REQUEST', [
    'action'    => $action,
    'device_id' => $_POST['device_id'] ?? null,
    'date'      => $_POST['date']      ?? null,
    'schedule'  => $_POST['schedule']  ?? null,
    'php_sapi'  => PHP_SAPI,
    'php_bin'   => PHP_BINARY,
]);

try {
    switch ($action) {
        case 'test_connection':
            testDeviceConnection();
            break;
        case 'manual_pull':
            executeManualPull();
            break;
        default:
            debugLog('ERROR', "Unknown action: '$action'");
            sendResponse(['success' => false, 'error' => "Invalid action: '$action'"]);
    }
} catch (Exception $e) {
    debugLog('EXCEPTION', $e->getMessage());
    debugLog('TRACE', $e->getTraceAsString());
    sendResponse(['success' => false, 'error' => $e->getMessage()]);
}

// ============================================================
// FUNCTION: Test ZKTeco device connection
// ============================================================
function testDeviceConnection() {
    global $conn;
    $device_id = $_POST['device_id'] ?? null;
    debugLog('testDeviceConnection', "device_id=$device_id");
    
    if (!$device_id) {
        sendResponse(['success' => false, 'error' => 'Device ID required']);
    }
    
    // Fetch device
    $stmt = $conn->prepare("SELECT * FROM zkteco_devices WHERE id = :id");
    $stmt->execute([':id' => $device_id]);
    $device = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$device) {
        debugLog('testDeviceConnection', 'Device not found in DB');
        sendResponse(['success' => false, 'error' => 'Device not found']);
    }
    
    debugLog('testDeviceConnection', [
        'name' => $device['device_name'],
        'ip'   => $device['ip_address'],
        'port' => $device['port'],
    ]);
    
    // Attempt connection
    $zk = new ZKLibrary($device['ip_address'], $device['port']);
    $zk->setTimeout($device['timeout'] ?? 5);
    
    if ($zk->connect()) {
        debugLog('testDeviceConnection', 'Connection SUCCESS');
        $stmt = $conn->prepare("
            UPDATE zkteco_devices
            SET connection_status = 'ONLINE',
                last_online_at    = CURRENT_TIMESTAMP,
                updated_at        = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $device_id]);
        $zk->disconnect();
        
        sendResponse([
            'success' => true,
            'message' => 'Connection successful',
            'device'  => $device['device_name'],
        ]);
    } else {
        debugLog('testDeviceConnection', 'Connection FAILED');
        $stmt = $conn->prepare("
            UPDATE zkteco_devices
            SET connection_status = 'OFFLINE',
                updated_at        = CURRENT_TIMESTAMP
            WHERE id = :id
        ");
        $stmt->execute([':id' => $device_id]);
        
        sendResponse([
            'success' => false,
            'error'   => 'Failed to connect. Check IP address, port, and network connectivity.',
        ]);
    }
}

// ============================================================
// FUNCTION: Execute manual attendance pull
// ============================================================
function executeManualPull() {
    global $conn;
    $device_id = $_POST['device_id'] ?? null;
    $date      = $_POST['date']      ?? date('Y-m-d');
    $schedule  = $_POST['schedule']  ?? null;
    
    debugLog('executeManualPull', "device_id=$device_id | date=$date | schedule=$schedule");
    
    if (!$device_id) {
        sendResponse(['success' => false, 'error' => 'Device ID required']);
    }
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        sendResponse(['success' => false, 'error' => "Invalid date format: '$date'. Expected YYYY-MM-DD"]);
    }
    
    // --------------------------------------------------------
    // Build the CLI command — Windows-safe
    // --------------------------------------------------------
    $script_path = __DIR__ . '/zkteco_puller.php';
    
    if (!file_exists($script_path)) {
        debugLog('executeManualPull', "Puller script not found at: $script_path");
        sendResponse([
            'success' => false,
            'error'   => "Puller script not found at: $script_path",
        ]);
    }
    
    // CRITICAL FIX: Get correct PHP CLI binary (not Apache httpd.exe)
    $php_bin = getPhpCliBinary();
    debugLog('PHP_CLI_BINARY', $php_bin);
    
    // Verify PHP binary exists
    if (!file_exists($php_bin)) {
        debugLog('PHP_BINARY_ERROR', "PHP CLI binary not found at: $php_bin");
        sendResponse([
            'success' => false,
            'error'   => "PHP CLI binary not found at: $php_bin. Please configure system_settings.php_cli_path",
        ]);
    }
    
    // Pass DOCUMENT_ROOT explicitly so the CLI child process has it
    $doc_root = $_SERVER['DOCUMENT_ROOT'];
    
    // Build command — Windows-safe with proper quoting
    $cmd_parts = [
        '"' . str_replace('"', '""', $php_bin) . '"',  // Double-quote for Windows cmd.exe
        '-d', 'display_errors=1',
        '-d', 'error_reporting=E_ALL',
        '"' . str_replace('"', '""', $script_path) . '"',  // Quote script path too
        '--device=' . escapeshellarg($device_id),
        '--date=' . escapeshellarg($date),
        '--doc_root=' . escapeshellarg($doc_root),
    ];
    
    if ($schedule) {
        $cmd_parts[] = '--schedule=' . escapeshellarg($schedule);
    }
    
    $cmd = implode(' ', $cmd_parts);
    debugLog('CMD_BUILT', $cmd);
    
    // --------------------------------------------------------
    // Execute using proc_open for better Windows compatibility
    // --------------------------------------------------------
    $output = '';
    $return_code = -1;
    
    $descriptorspec = [
        0 => ['pipe', 'r'],  // stdin
        1 => ['pipe', 'w'],  // stdout
        2 => ['pipe', 'w'],  // stderr
    ];
    
    $process = proc_open($cmd, $descriptorspec, $pipes);
    
    if (is_resource($process)) {
        fclose($pipes[0]); // No input
        
        // Read stdout and stderr
        $stdout = stream_get_contents($pipes[1]);
        $stderr = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        
        $output = trim($stdout . "\n" . $stderr);
        $return_code = proc_close($process);
    } else {
        $output = "Failed to execute command via proc_open";
        $return_code = 127;
    }
    
    debugLog('CMD_EXIT_CODE', $return_code);
    debugLog('CMD_OUTPUT', $output ?: '(empty)');
    
    // --------------------------------------------------------
    // Diagnose common Windows failure modes from output
    // --------------------------------------------------------
    $diagnosis = diagnosePullerOutput($return_code, $output, $php_bin, $script_path, $doc_root);
    
    if ($return_code === 0) {
        // Fetch the pull log written by the puller
        $stmt = $conn->prepare("
            SELECT *
            FROM zkteco_pull_log
            WHERE device_id = :device_id
            AND pull_date  = :date
            ORDER BY started_at DESC
            LIMIT 1
        ");
        $stmt->execute([':device_id' => $device_id, ':date' => $date]);
        $log = $stmt->fetch(PDO::FETCH_ASSOC);
        
        debugLog('PULL_LOG', $log ?: 'No log row found');
        
        if ($log) {
            sendResponse([
                'success' => true,
                'message' => 'Pull completed successfully',
                'stats'   => [
                    'inserted'        => $log['inserted_records'],
                    'updated'         => $log['updated_records'],
                    'skipped'         => $log['skipped_records'],
                    'errors'          => $log['error_records'],
                    'employees_count' => $log['employees_processed'],
                ],
                'log_id'  => $log['id'],
            ]);
        } else {
            sendResponse([
                'success' => true,
                'message' => 'Pull executed but no log row found in DB',
                'output'  => $output,
            ]);
        }
    } else {
        // Pull failed — return everything useful for debugging
        sendResponse([
            'success'     => false,
            'error'       => "Pull failed with exit code $return_code",
            'diagnosis'   => $diagnosis,
            'output'      => $output,
            'cmd'         => $cmd,  // visible in _debug in browser console
        ]);
    }
}

// ============================================================
// HELPER: Diagnose common failure causes on Windows
// ============================================================
function diagnosePullerOutput($exit_code, $output, $php_bin, $script_path, $doc_root) {
    $checks = [];
    
    // PHP binary exists?
    if (!file_exists($php_bin)) {
        $checks[] = "PHP binary not found at: $php_bin";
    } else {
        $checks[] = "PHP binary OK: $php_bin";
    }
    
    // Script exists?
    if (!file_exists($script_path)) {
        $checks[] = "Puller script NOT found at: $script_path";
    } else {
        $checks[] = "Puller script OK: $script_path";
    }
    
    // DOCUMENT_ROOT empty?
    if (empty($doc_root)) {
        $checks[] = "DOCUMENT_ROOT is EMPTY — child process will fail on require_once";
    } else {
        $checks[] = "DOCUMENT_ROOT passed: $doc_root";
    }
    
    // Parse output for known PHP errors
    if (str_contains($output, 'No such file or directory')) {
        $checks[] = "Output indicates a missing file — likely a require_once path issue";
    }
    
    if (str_contains($output, 'Fatal error')) {
        preg_match('/Fatal error:.+/i', $output, $m);
        $checks[] = "PHP Fatal error detected: " . ($m[0] ?? 'see output');
    }
    
    if (str_contains($output, 'Call to undefined')) {
        $checks[] = "Undefined function — extension or class not loaded in CLI php.ini";
    }
    
    if (str_contains($output, 'syntax error')) {
        $checks[] = "PHP syntax error in puller script";
    }
    
    if ($exit_code === 3) {
        $checks[] = "Exit code 3 on Windows often means: (1) spaces in PHP binary path not quoted, "
                  . "(2) DOCUMENT_ROOT empty in CLI, or (3) exec() is disabled in php.ini";
    }
    
    // exec() disabled?
    $disabled = ini_get('disable_functions');
    if (str_contains($disabled, 'exec') || str_contains($disabled, 'proc_open')) {
        $checks[] = "WARNING: exec() or proc_open() is listed in disable_functions in php.ini — command cannot run";
    }
    
    return $checks;
}
?>
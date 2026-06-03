<?php
/**
 * ZKTeco Diagnostic Script
 * Identifies issues causing exit code 255
 */

// Force error display
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

echo "=== ZKTeco Diagnostic Test ===\n\n";

// Test 1: Check PHP CLI
echo "1. PHP Environment:\n";
echo "   SAPI: " . php_sapi_name() . "\n";
echo "   PHP Version: " . PHP_VERSION . "\n";
echo "   PHP Binary: " . PHP_BINARY . "\n";
echo "   Script: " . __FILE__ . "\n\n";

// Test 2: Check document root resolution
$doc_root = dirname(__DIR__);
echo "2. Path Resolution:\n";
echo "   Detected doc_root: $doc_root\n";
echo "   Script directory: " . __DIR__ . "\n";
echo "   Config path: $doc_root/config/database.php\n";
echo "   Config exists: " . (file_exists("$doc_root/config/database.php") ? "YES" : "NO") . "\n\n";

// Test 3: Try loading database config
echo "3. Database Configuration:\n";
$config_path = $doc_root . '/config/database.php';
if (!file_exists($config_path)) {
    echo "   ❌ ERROR: Config file not found at: $config_path\n";
    echo "   Please check your directory structure.\n\n";
    exit(255);
}

try {
    require_once $config_path;
    if (isset($conn) && $conn) {
        echo "   ✅ Database config loaded\n";
        echo "   ✅ Connection object exists\n";
        
        // Test query
        $test = $conn->query("SELECT 1");
        echo "   ✅ Database connection working\n\n";
    } else {
        echo "   ❌ ERROR: Connection object not created\n\n";
        exit(255);
    }
} catch (Exception $e) {
    echo "   ❌ ERROR: " . $e->getMessage() . "\n\n";
    exit(255);
}

// Test 4: Check ZKTeco library
echo "4. ZKTeco Library:\n";
$zk_lib_path = $doc_root . '/attendance_device/includes/zkteco/zklibrary.php';
echo "   Library path: $zk_lib_path\n";
echo "   Library exists: " . (file_exists($zk_lib_path) ? "YES" : "NO") . "\n";

if (!file_exists($zk_lib_path)) {
    echo "   ❌ ERROR: ZKTeco library not found\n";
    echo "   Expected location: $zk_lib_path\n\n";
    
    // Try to find it
    echo "   Searching for zklibrary.php...\n";
    $search_paths = [
        $doc_root . '/includes/zkteco/zklibrary.php',
        $doc_root . '/lib/zkteco/zklibrary.php',
        $doc_root . '/vendor/zkteco/zklibrary.php',
        __DIR__ . '/includes/zkteco/zklibrary.php',
    ];
    
    foreach ($search_paths as $path) {
        if (file_exists($path)) {
            echo "   Found at: $path\n";
        }
    }
    echo "\n";
    exit(255);
}

try {
    require_once $zk_lib_path;
    echo "   ✅ Library loaded successfully\n";
    
    if (class_exists('ZKLibrary')) {
        echo "   ✅ ZKLibrary class available\n\n";
    } else {
        echo "   ❌ ERROR: ZKLibrary class not found in library file\n\n";
        exit(255);
    }
} catch (Exception $e) {
    echo "   ❌ ERROR loading library: " . $e->getMessage() . "\n\n";
    exit(255);
}

// Test 5: Check log directory
echo "5. Log Directory:\n";
$log_dir = $doc_root . '/attendance_device/logs/zkteco';
echo "   Log path: $log_dir\n";
echo "   Directory exists: " . (is_dir($log_dir) ? "YES" : "NO") . "\n";
echo "   Writable: " . (is_writable($log_dir) ? "YES" : "NO") . "\n";

if (!is_dir($log_dir)) {
    echo "   Creating directory...\n";
    if (mkdir($log_dir, 0755, true)) {
        echo "   ✅ Directory created\n\n";
    } else {
        echo "   ❌ ERROR: Cannot create log directory\n\n";
        exit(255);
    }
} else {
    echo "\n";
}

// Test 6: Check device exists
echo "6. Device Check:\n";
try {
    $stmt = $conn->prepare("SELECT id, device_name, ip_address, port FROM attendance_devices WHERE is_active = 1 LIMIT 5");
    $stmt->execute();
    $devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if (empty($devices)) {
        echo "   ⚠️  WARNING: No active devices found in database\n\n";
    } else {
        echo "   ✅ Found " . count($devices) . " active device(s):\n";
        foreach ($devices as $device) {
            echo "      - ID: {$device['id']}, Name: {$device['device_name']}, IP: {$device['ip_address']}:{$device['port']}\n";
        }
        echo "\n";
    }
} catch (Exception $e) {
    echo "   ❌ ERROR querying devices: " . $e->getMessage() . "\n\n";
    exit(255);
}

// Test 7: Check system settings table
echo "7. System Settings:\n";
try {
    $stmt = $conn->prepare("SELECT `key`, value FROM system_settings WHERE `key` = 'php_cli_path'");
    $stmt->execute();
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($setting) {
        echo "   ✅ PHP CLI path configured: {$setting['value']}\n";
        echo "   Path exists: " . (file_exists($setting['value']) ? "YES" : "NO") . "\n\n";
    } else {
        echo "   ⚠️  WARNING: php_cli_path not configured in system_settings\n";
        echo "   The system will attempt auto-detection\n\n";
    }
} catch (Exception $e) {
    echo "   ⚠️  WARNING: system_settings table may not exist\n";
    echo "   Error: " . $e->getMessage() . "\n\n";
}

// Test 8: Test argument parsing
echo "8. Command Line Arguments:\n";
$test_args = [
    'device' => '1',
    'date' => '2024-02-14',
    'doc_root' => $doc_root
];

echo "   Test arguments:\n";
foreach ($test_args as $key => $value) {
    echo "      --$key=$value\n";
}
echo "\n";

// Final summary
echo "=== Diagnostic Summary ===\n";
echo "✅ All basic checks passed!\n";
echo "\nIf you're still getting exit code 255, run the puller directly:\n";
echo "\n";

$php_cli = 'C:\\xampp\\php\\php.exe'; // Adjust for your system
echo "Windows CMD:\n";
echo "cd " . str_replace('/', '\\', $doc_root) . "\\attendance_device\n";
echo "$php_cli zkteco_puller.php --device=1 --date=2024-02-14 --doc_root=" . str_replace('/', '\\', $doc_root) . " --debug\n";
echo "\n";

echo "Linux/Mac:\n";
echo "cd $doc_root/attendance_device\n";
echo "php zkteco_puller.php --device=1 --date=2024-02-14 --doc_root=$doc_root --debug\n";
echo "\n";

echo "Check the output for specific error messages.\n";
echo "Also check: $log_dir/puller_" . date('Y-m-d') . ".log\n";

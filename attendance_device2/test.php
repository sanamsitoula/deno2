<?php
/**
 * ZKTeco Device Test Page - PostgreSQL Version
 * Tests device connectivity and displays data
 */
error_reporting(E_ALL);
ini_set('display_errors', 1);

// ============================================
// CONFIGURATION
// ============================================

// PostgreSQL Database Configuration
$db_config = [
    'host' => 'localhost',
    'port' => '5432',
    'dbname' => 'press_jemc',
    'user' => 'postgres',
    'password' => 'Nepal@123'
];


// Device Configuration
$device_config = [
    'ip' => '10.10.10.18',
    'port' => 4370,
    'timeout' => 5
];

// Load ZKLibrary
require_once __DIR__ . '/ZKLibrary.php';

// ============================================
// DATABASE CONNECTION
// ============================================
$conn = null;
$db_connected = false;
$db_error = null;

try {
    $dsn = "pgsql:host={$db_config['host']};port={$db_config['port']};dbname={$db_config['dbname']}";
    $conn = new PDO($dsn, $db_config['user'], $db_config['password']);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_connected = true;
} catch (PDOException $e) {
    $db_error = $e->getMessage();
}

// ============================================
// DEVICE CONNECTION TEST
// ============================================
$device_connected = false;
$device_error = null;
$device_info = [];
$device_users = [];
$device_attendance = [];
$capacity_info = [];

try {
    $zk = new ZKLibrary($device_config['ip'], $device_config['port']);
    $zk->setTimeout($device_config['timeout']);
    
    if ($zk->connect()) {
        $device_connected = true;
        
        // Get device information
        $device_info['version'] = $zk->version();
        $device_info['os_version'] = $zk->osVersion();
        $device_info['platform'] = $zk->platform();
        $device_info['serial_number'] = $zk->serialNumber();
        $device_info['device_name'] = $zk->deviceName();
        $device_info['device_time'] = $zk->getTime();
        
        // Get capacity
        $capacity_info = $zk->getFreeSizes();
        
        // Get users (all users)
        $all_users = $zk->getUser();
        if ($all_users && is_array($all_users)) {
            $device_users = array_slice($all_users, 0, 10, true); // Top 10
        }
        
        // Get attendance (recent 10)
        $all_attendance = $zk->getAttendance();
        if ($all_attendance && is_array($all_attendance)) {
            $device_attendance = array_slice($all_attendance, -10, 10); // Last 10
        }
        
        $zk->disconnect();
    } else {
        $device_error = "Failed to connect to device";
    }
} catch (Exception $e) {
    $device_error = $e->getMessage();
}

// ============================================
// DATABASE QUERIES
// ============================================
$db_devices = [];
$db_mappings = [];
$db_pull_logs = [];

if ($db_connected && $conn) {
    try {
        // Get devices from database
        $stmt = $conn->query("
            SELECT * FROM zkteco_devices 
            ORDER BY priority, device_name 
            LIMIT 10
        ");
        $db_devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get user mappings
        $stmt = $conn->query("
            SELECT 
                zum.*,
                e.name as employee_name,
                e.code as employee_code
            FROM zkteco_user_mapping zum
            LEFT JOIN employee e ON zum.employee_id = e.id
            ORDER BY zum.id DESC
            LIMIT 10
        ");
        $db_mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Get recent pull logs
        $stmt = $conn->query("
            SELECT 
                pl.*,
                d.device_name
            FROM zkteco_pull_log pl
            LEFT JOIN zkteco_devices d ON pl.device_id = d.id
            ORDER BY pl.started_at DESC
            LIMIT 10
        ");
        $db_pull_logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
    } catch (PDOException $e) {
        // Silently handle errors
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>ZKTeco Device Test - PostgreSQL</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: #f5f7fa;
            padding: 20px;
        }
        
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        .header {
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            color: white;
            padding: 30px;
            border-radius: 12px;
            margin-bottom: 20px;
            box-shadow: 0 4px 15px rgba(0,0,0,0.2);
        }
        
        .header h1 {
            font-size: 32px;
            margin-bottom: 10px;
        }
        
        .status-banner {
            padding: 15px 20px;
            border-radius: 8px;
            margin-bottom: 20px;
            font-weight: 600;
        }
        
        .status-success {
            background: #d4edda;
            color: #155724;
            border: 2px solid #c3e6cb;
        }
        
        .status-error {
            background: #f8d7da;
            color: #721c24;
            border: 2px solid #f5c6cb;
        }
        
        .status-warning {
            background: #fff3cd;
            color: #856404;
            border: 2px solid #ffeaa7;
        }
        
        .grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .card {
            background: white;
            border-radius: 12px;
            padding: 25px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        }
        
        .card h2 {
            font-size: 20px;
            margin-bottom: 15px;
            color: #333;
            border-bottom: 2px solid #667eea;
            padding-bottom: 10px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            padding: 10px 0;
            border-bottom: 1px solid #f0f0f0;
        }
        
        .info-label {
            font-weight: 600;
            color: #666;
        }
        
        .info-value {
            color: #333;
            text-align: right;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            background: white;
            border-radius: 12px;
            overflow: hidden;
            box-shadow: 0 2px 8px rgba(0,0,0,0.1);
            margin-bottom: 30px;
        }
        
        th {
            background: #667eea;
            color: white;
            padding: 15px;
            text-align: left;
            font-weight: 600;
        }
        
        td {
            padding: 12px 15px;
            border-bottom: 1px solid #f0f0f0;
        }
        
        tr:hover {
            background: #f8f9fa;
        }
        
        .badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 12px;
            font-size: 12px;
            font-weight: 600;
        }
        
        .badge-success {
            background: #d4edda;
            color: #155724;
        }
        
        .badge-danger {
            background: #f8d7da;
            color: #721c24;
        }
        
        .badge-warning {
            background: #fff3cd;
            color: #856404;
        }
        
        .badge-info {
            background: #d1ecf1;
            color: #0c5460;
        }
        
        .section-title {
            font-size: 24px;
            margin: 30px 0 15px 0;
            color: #333;
        }
        
        code {
            background: #f4f4f4;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: 'Courier New', monospace;
        }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #999;
        }
    </style>
</head>
<body>
    <div class="container">
        <!-- Header -->
        <div class="header">
            <h1>🔌 ZKTeco Device Test</h1>
            <p>Testing device connectivity and data retrieval - PostgreSQL Integration</p>
        </div>
        
        <!-- Status Banners -->
        <?php if ($db_connected): ?>
        <div class="status-banner status-success">
            ✅ Database Connected: PostgreSQL @ <?= htmlspecialchars($db_config['host']) ?>
        </div>
        <?php else: ?>
        <div class="status-banner status-error">
            ❌ Database Connection Failed: <?= htmlspecialchars($db_error) ?>
        </div>
        <?php endif; ?>
        
        <?php if ($device_connected): ?>
        <div class="status-banner status-success">
            ✅ Device Connected: <?= htmlspecialchars($device_config['ip']) ?>:<?= $device_config['port'] ?>
        </div>
        <?php else: ?>
        <div class="status-banner status-error">
            ❌ Device Connection Failed: <?= htmlspecialchars($device_error ?? 'Unknown error') ?>
        </div>
        <?php endif; ?>
        
        <!-- Device Information -->
        <?php if ($device_connected): ?>
        <h2 class="section-title">📱 Device Information</h2>
        <div class="grid">
            <div class="card">
                <h2>Basic Info</h2>
                <div class="info-row">
                    <span class="info-label">IP Address</span>
                    <span class="info-value"><code><?= htmlspecialchars($device_config['ip']) ?></code></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Port</span>
                    <span class="info-value"><code><?= $device_config['port'] ?></code></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Device Name</span>
                    <span class="info-value"><?= htmlspecialchars($device_info['device_name'] ?? 'N/A') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Serial Number</span>
                    <span class="info-value"><code><?= htmlspecialchars($device_info['serial_number'] ?? 'N/A') ?></code></span>
                </div>
            </div>
            
            <div class="card">
                <h2>Firmware Info</h2>
                <div class="info-row">
                    <span class="info-label">Version</span>
                    <span class="info-value"><?= htmlspecialchars($device_info['version'] ?? 'N/A') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">OS Version</span>
                    <span class="info-value"><?= htmlspecialchars($device_info['os_version'] ?? 'N/A') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Platform</span>
                    <span class="info-value"><?= htmlspecialchars($device_info['platform'] ?? 'N/A') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Device Time</span>
                    <span class="info-value"><?= htmlspecialchars($device_info['device_time'] ?? 'N/A') ?></span>
                </div>
            </div>
            
            <div class="card">
                <h2>Capacity</h2>
                <div class="info-row">
                    <span class="info-label">Users</span>
                    <span class="info-value">
                        <?= $capacity_info['users'] ?? 0 ?> / <?= $capacity_info['capacity_users'] ?? 0 ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Users %</span>
                    <span class="info-value">
                        <?php 
                        $user_pct = ($capacity_info['capacity_users'] ?? 0) > 0 
                            ? round(($capacity_info['users'] ?? 0) / $capacity_info['capacity_users'] * 100, 1) 
                            : 0;
                        ?>
                        <strong><?= $user_pct ?>%</strong>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Logs</span>
                    <span class="info-value">
                        <?= $capacity_info['logs'] ?? 0 ?> / <?= $capacity_info['capacity_logs'] ?? 0 ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Logs %</span>
                    <span class="info-value">
                        <?php 
                        $log_pct = ($capacity_info['capacity_logs'] ?? 0) > 0 
                            ? round(($capacity_info['logs'] ?? 0) / $capacity_info['capacity_logs'] * 100, 1) 
                            : 0;
                        ?>
                        <strong><?= $log_pct ?>%</strong>
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Device Users -->
        <h2 class="section-title">👥 Device Users (Top 10)</h2>
        <table>
            <thead>
                <tr>
                    <th>UID</th>
                    <th>User ID</th>
                    <th>Name</th>
                    <th>Card No</th>
                    <th>Role</th>
                    <th>Password</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($device_users)): ?>
                    <?php foreach ($device_users as $uid => $user): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($uid) ?></code></td>
                        <td><code><?= htmlspecialchars($user['userid']) ?></code></td>
                        <td><?= htmlspecialchars($user['name']) ?></td>
                        <td><code><?= htmlspecialchars($user['cardno']) ?></code></td>
                        <td>
                            <?php if ($user['role'] == 14): ?>
                            <span class="badge badge-danger">ADMIN</span>
                            <?php else: ?>
                            <span class="badge badge-info">USER</span>
                            <?php endif; ?>
                        </td>
                        <td><?= !empty($user['password']) ? '●●●●' : '-' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="6" class="empty-state">No users found on device</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Recent Attendance -->
        <h2 class="section-title">📊 Recent Attendance (Last 10 Records)</h2>
        <table>
            <thead>
                <tr>
                    <th>UID</th>
                    <th>User ID</th>
                    <th>Status</th>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Full Timestamp</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($device_attendance)): ?>
                    <?php foreach ($device_attendance as $idx => $att): ?>
                    <tr>
                        <td><code><?= htmlspecialchars($att['uid']) ?></code></td>
                        <td><code><?= htmlspecialchars($att['id']) ?></code></td>
                        <td>
                            <?php if ($att['state'] == 0 || $att['state'] == 'I'): ?>
                            <span class="badge badge-success">Check In</span>
                            <?php else: ?>
                            <span class="badge badge-warning">Check Out</span>
                            <?php endif; ?>
                        </td>
                        <td><?= date('d M Y', strtotime($att['timestamp'])) ?></td>
                        <td><strong><?= date('H:i:s', strtotime($att['timestamp'])) ?></strong></td>
                        <td><code><?= htmlspecialchars($att['timestamp']) ?></code></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="6" class="empty-state">No attendance records found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- Database Information -->
        <?php if ($db_connected): ?>
        <h2 class="section-title">💾 Database Information</h2>
        
        <!-- Devices Table -->
        <h3 style="margin: 20px 0 10px 0;">Configured Devices</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Device Name</th>
                    <th>Code</th>
                    <th>IP Address</th>
                    <th>Status</th>
                    <th>Users</th>
                    <th>Logs</th>
                    <th>Active</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($db_devices)): ?>
                    <?php foreach ($db_devices as $device): ?>
                    <tr>
                        <td><?= $device['id'] ?></td>
                        <td><?= htmlspecialchars($device['device_name']) ?></td>
                        <td><code><?= htmlspecialchars($device['device_code']) ?></code></td>
                        <td><code><?= htmlspecialchars($device['ip_address']) ?>:<?= $device['port'] ?></code></td>
                        <td>
                            <?php if ($device['connection_status'] == 'ONLINE'): ?>
                            <span class="badge badge-success">ONLINE</span>
                            <?php elseif ($device['connection_status'] == 'OFFLINE'): ?>
                            <span class="badge badge-danger">OFFLINE</span>
                            <?php else: ?>
                            <span class="badge badge-warning">UNKNOWN</span>
                            <?php endif; ?>
                        </td>
                        <td><?= $device['total_users'] ?></td>
                        <td><?= $device['total_logs'] ?></td>
                        <td>
                            <?= $device['is_active'] ? '✅' : '❌' ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="8" class="empty-state">No devices configured in database</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- User Mappings -->
        <h3 style="margin: 20px 0 10px 0;">User Mappings (Recent 10)</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Device User ID</th>
                    <th>Device UID</th>
                    <th>Employee</th>
                    <th>Shift Type</th>
                    <th>Synced</th>
                    <th>Active</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($db_mappings)): ?>
                    <?php foreach ($db_mappings as $mapping): ?>
                    <tr>
                        <td><?= $mapping['id'] ?></td>
                        <td><code><?= htmlspecialchars($mapping['device_user_id']) ?></code></td>
                        <td><?= $mapping['device_uid'] ?></td>
                        <td>
                            <?= htmlspecialchars($mapping['employee_name'] ?? 'N/A') ?>
                            <br><small><code><?= htmlspecialchars($mapping['employee_code'] ?? '') ?></code></small>
                        </td>
                        <td><span class="badge badge-info"><?= $mapping['shift_type'] ?></span></td>
                        <td><?= $mapping['synced_to_device'] ? '✅' : '❌' ?></td>
                        <td><?= $mapping['is_active'] ? '✅' : '❌' ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="7" class="empty-state">No user mappings configured</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        
        <!-- Pull Logs -->
        <h3 style="margin: 20px 0 10px 0;">Recent Pull Logs (Last 10)</h3>
        <table>
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Device</th>
                    <th>Date</th>
                    <th>Schedule</th>
                    <th>Records</th>
                    <th>Status</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($db_pull_logs)): ?>
                    <?php foreach ($db_pull_logs as $log): ?>
                    <tr>
                        <td><?= $log['id'] ?></td>
                        <td><?= htmlspecialchars($log['device_name'] ?? 'N/A') ?></td>
                        <td><?= date('d M Y', strtotime($log['pull_date'])) ?></td>
                        <td><span class="badge badge-info"><?= strtoupper($log['schedule_type'] ?? 'MANUAL') ?></span></td>
                        <td>
                            ↑<?= $log['inserted_records'] ?> 
                            ↻<?= $log['updated_records'] ?>
                            ✗<?= $log['error_records'] ?>
                        </td>
                        <td>
                            <?php if ($log['status'] == 'SUCCESS'): ?>
                            <span class="badge badge-success">SUCCESS</span>
                            <?php elseif ($log['status'] == 'FAILED'): ?>
                            <span class="badge badge-danger">FAILED</span>
                            <?php else: ?>
                            <span class="badge badge-warning"><?= $log['status'] ?></span>
                            <?php endif; ?>
                        </td>
                        <td><?= number_format($log['duration_seconds'], 2) ?>s</td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                <tr>
                    <td colspan="7" class="empty-state">No pull logs found</td>
                </tr>
                <?php endif; ?>
            </tbody>
        </table>
        <?php endif; ?>
        
        <!-- Footer -->
        <div class="card" style="margin-top: 30px; text-align: center;">
            <p><strong>Configuration Summary</strong></p>
            <p>Device IP: <code><?= htmlspecialchars($device_config['ip']) ?></code> | 
               Database: <code><?= htmlspecialchars($db_config['dbname']) ?>@<?= htmlspecialchars($db_config['host']) ?></code></p>
            <p style="margin-top: 10px; color: #999;">
                Generated on <?= date('Y-m-d H:i:s') ?>
            </p>
        </div>
    </div>
</body>
</html>
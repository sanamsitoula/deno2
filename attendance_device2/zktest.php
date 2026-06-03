<html>
<head>
    <title>ZK Test - PHP 8 Compatible</title>
    <style>
        body {
            font-family: Arial, sans-serif;
            margin: 20px;
            background: #f5f5f5;
        }
        table {
            background: white;
            border-collapse: collapse;
            margin: 10px 0;
            box-shadow: 0 2px 5px rgba(0,0,0,0.1);
        }
        th, td {
            border: 1px solid #ddd;
            padding: 10px;
            text-align: left;
        }
        th {
            background: #4CAF50;
            color: white;
            font-weight: bold;
        }
        tr:hover {
            background: #f5f5f5;
        }
        .status-connected {
            color: green;
            font-weight: bold;
        }
        .float-left {
            float: left;
            margin-right: 20px;
        }
        fieldset {
            clear: both;
            margin-top: 20px;
            background: white;
            padding: 15px;
        }
        .error {
            background: #f44336;
            color: white;
            padding: 15px;
            margin: 10px 0;
            border-radius: 4px;
        }
    </style>
</head>
<body>
<?php
// Include the ZKLib
include("zklib/zklib.php");

// Device configuration
$device_ip = "10.10.10.18";
$device_port = 4370;

try {
    $zk = new ZKLib($device_ip, $device_port);
    $ret = $zk->connect();
    
    if (!$ret) {
        throw new Exception("Failed to connect to device at $device_ip:$device_port");
    }
?>
    
    <!-- Device Information Table -->
    <h2>Device Information</h2>
    <table>
        <tr>
            <td><b>Status</b></td>
            <td class="status-connected">Connected</td>
            <td><b>Version</b></td>
            <td><?php echo htmlspecialchars($zk->version()); ?></td>
            <td><b>OS Version</b></td>
            <td><?php echo htmlspecialchars($zk->osversion()); ?></td>
            <td><b>Platform</b></td>
            <td><?php echo htmlspecialchars($zk->platform()); ?></td>
        </tr>
        <tr>
            <td><b>Firmware Version</b></td>
            <td><?php echo htmlspecialchars($zk->fmVersion()); ?></td>
            <td><b>WorkCode</b></td>
            <td><?php echo htmlspecialchars($zk->workCode()); ?></td>
            <td><b>SSR</b></td>
            <td><?php echo htmlspecialchars($zk->ssr()); ?></td>
            <td><b>Pin Width</b></td>
            <td><?php echo htmlspecialchars($zk->pinWidth()); ?></td>
        </tr>
        <tr>
            <td><b>Face Function On</b></td>
            <td><?php echo htmlspecialchars($zk->faceFunctionOn()); ?></td>
            <td><b>Serial Number</b></td>
            <td><?php echo htmlspecialchars($zk->serialNumber()); ?></td>
            <td><b>Device Name</b></td>
            <td><?php echo htmlspecialchars($zk->deviceName()); ?></td>
            <td><b>Device Time</b></td>
            <td><?php echo htmlspecialchars($zk->getTime()); ?></td>
        </tr>
    </table>
    
    <hr />
    
    <!-- Users Table -->
    <h2>Device Users</h2>
    <table class="float-left">
        <tr>
            <th>UID</th>
            <th>User ID</th>
            <th>Name</th>
            <th>Role</th>
            <th>Password</th>
        </tr>
        <?php
        $users = $zk->getUser();
        
        if ($users && is_array($users)) {
            // PHP 8 compatible: Use foreach instead of each()
            foreach ($users as $uid => $userdata) {
                if ($userdata[2] == LEVEL_ADMIN)
                    $role = 'ADMIN';
                elseif ($userdata[2] == LEVEL_USER)
                    $role = 'USER';
                else
                    $role = 'Unknown';
                
                echo '<tr>';
                echo '<td>' . htmlspecialchars($uid) . '</td>';
                echo '<td>' . htmlspecialchars($userdata[0]) . '</td>';
                echo '<td>' . htmlspecialchars($userdata[1]) . '</td>';
                echo '<td>' . htmlspecialchars($role) . '</td>';
                echo '<td>' . (!empty($userdata[3]) ? '****' : '&nbsp;') . '</td>';
                echo '</tr>';
            }
        } else {
            echo '<tr><td colspan="5">No users found</td></tr>';
        }
        ?>
    </table>
    
    <!-- Attendance Table -->
    <table>
        <tr>
            <th colspan="6">Attendance Data</th>
        </tr>
        <tr>
            <th>Index</th>
            <th>UID</th>
            <th>User ID</th>
            <th>Status</th>
            <th>Date</th>
            <th>Time</th>
        </tr>
        <?php
        $attendance = $zk->getAttendance();
        
        if ($attendance && is_array($attendance)) {
            // PHP 8 compatible: Use foreach instead of each()
            $index = 0;
            foreach ($attendance as $attendancedata) {
                if ($attendancedata[2] == 1 || $attendancedata[2] == 14)
                    $status = 'Check Out';
                else
                    $status = 'Check In';
                
                echo '<tr>';
                echo '<td>' . $index . '</td>';
                echo '<td>' . htmlspecialchars($attendancedata[0]) . '</td>';
                echo '<td>' . htmlspecialchars($attendancedata[1]) . '</td>';
                echo '<td>' . htmlspecialchars($status) . '</td>';
                echo '<td>' . date("d-m-Y", strtotime($attendancedata[3])) . '</td>';
                echo '<td>' . date("H:i:s", strtotime($attendancedata[3])) . '</td>';
                echo '</tr>';
                
                $index++;
            }
        } else {
            echo '<tr><td colspan="6">No attendance records found</td></tr>';
        }
        ?>
    </table>
    
    <fieldset>
        <legend><b>Example Usage (PHP 8+ Compatible):</b></legend>
        <pre style='color:#000000;background:#f9f9f9; padding: 15px; border-left: 4px solid #4CAF50;'>
&lt;?php
include("zklib/zklib.php");

$zk = new ZKLib("10.10.10.18", 4370);
$ret = $zk->connect();

if ($ret) {
    $zk->disableDevice();
    
    // Get device info
    $version = $zk->version();
    $osversion = $zk->osversion();
    $platform = $zk->platform();
    $deviceName = $zk->deviceName();
    $deviceTime = $zk->getTime();
    
    // Get users (PHP 8 compatible)
    $users = $zk->getUser();
    foreach ($users as $uid => $userdata) {
        echo "UID: " . $uid . "\n";
        echo "User ID: " . $userdata[0] . "\n";
        echo "Name: " . $userdata[1] . "\n";
        echo "Role: " . ($userdata[2] == LEVEL_ADMIN ? 'ADMIN' : 'USER') . "\n";
    }
    
    // Get attendance (PHP 8 compatible)
    $attendance = $zk->getAttendance();
    foreach ($attendance as $idx => $attendancedata) {
        echo "UID: " . $attendancedata[0] . "\n";
        echo "Status: " . ($attendancedata[2] == 1 ? 'Check Out' : 'Check In') . "\n";
        echo "Date: " . date("d-m-Y", strtotime($attendancedata[3])) . "\n";
        echo "Time: " . date("H:i:s", strtotime($attendancedata[3])) . "\n";
    }
    
    $zk->enableDevice();
    $zk->disconnect();
} else {
    echo "Failed to connect";
}
?&gt;
        </pre>
    </fieldset>
    
    <?php
    // Clean up
    $zk->enableDevice();
    $zk->disconnect();
    
} catch (Exception $e) {
    echo '<div class="error">';
    echo '<strong>Error:</strong> ' . htmlspecialchars($e->getMessage());
    echo '</div>';
}
?>

</body>
</html>
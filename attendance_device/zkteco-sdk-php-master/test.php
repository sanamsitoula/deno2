<?php
 include("zklib/zklib.php");

// Device configuration
$ip = '10.10.10.18';
$port = 4370;
$protocol = 'TCP';

echo "<!DOCTYPE html>
<html>
<head>
    <title>ZK Device Test</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        .success { color: green; font-weight: bold; }
        .error { color: red; font-weight: bold; }
        .info { color: blue; }
        table { border-collapse: collapse; width: 100%; margin-bottom: 20px; }
        th, td { border: 1px solid #ddd; padding: 8px; text-align: left; }
        th { background-color: #4CAF50; color: white; }
        tr:nth-child(even) { background-color: #f2f2f2; }
        .summary { background-color: #e7f3fe; padding: 15px; border-radius: 5px; margin-bottom: 20px; }
    </style>
</head>
<body>
    <h1>ZK Attendance Device Test</h1>";

// Test connection and get data
try {
    echo "<h2>Connection Test</h2>";
    
    // Create connection
    $zk = new ZKLibrary($ip, $port, $protocol);
    echo "Connecting to {$ip}:{$port}... ";
    
    // Test ping first
    $pingResult = $zk->ping(2);
    if ($pingResult == 'down') {
        echo "<span class='error'>Device is not reachable (Ping failed)</span><br>";
        echo "Please check:";
        echo "<ul>";
        echo "<li>If device IP is correct: {$ip}</li>";
        echo "<li>If device is powered on</li>";
        echo "<li>Network connectivity</li>";
        echo "<li>Firewall settings (port {$port})</li>";
        echo "</ul>";
    } else {
        echo "<span class='info'>Ping response: {$pingResult}ms</span><br>";
        
        // Attempt to connect
        if ($zk->connect()) {
            echo "<span class='success'>✓ Connected successfully!</span><br>";
            
            // Get device info
            echo "<div class='summary'>";
            echo "<h3>Device Information:</h3>";
            
            // Get serial number
            $serial = $zk->getSerialNumber();
            echo "Serial Number: " . ($serial ? $serial : 'N/A') . "<br>";
            
            // Get device name
            $devicename = $zk->getDeviceName();
            echo "Device Name: " . ($devicename ? $devicename : 'N/A') . "<br>";
            
            // Get firmware version
            $firmware = $zk->getFirmwareVersion();
            echo "Firmware: " . ($firmware ? $firmware : 'N/A') . "<br>";
            
            // Get device time
            $devicetime = $zk->getTime();
            echo "Device Time: " . ($devicetime ? $devicetime : 'N/A') . "<br>";
            echo "</div>";
            
            // Disable device for data reading
            $zk->disableDevice();
            echo "<span class='info'>Device disabled for reading</span><br>";
            
            // Get users
            echo "<h2>User Data</h2>";
            $users = $zk->getUser();
            
            if ($users && is_array($users)) {
                $userCount = count($users);
                echo "<span class='success'>✓ Total users found: {$userCount}</span><br>";
                
                if ($userCount > 0) {
                    echo "<h3>First 5 Users:</h3>";
                    echo "<table>";
                    echo "<tr><th>No</th><th>UID</th><th>User ID</th><th>Name</th><th>Role</th><th>Password</th></tr>";
                    
                    $count = 0;
                    foreach ($users as $uid => $user) {
                        $count++;
                        if ($count > 5) break;
                        
                        // Convert role number to text
                        $roleText = 'User';
                        if ($user[2] == 14) $roleText = 'Super Admin';
                        elseif ($user[2] == 12) $roleText = 'Manager';
                        elseif ($user[2] == 2) $roleText = 'Enroller';
                        
                        echo "<tr>";
                        echo "<td>{$count}</td>";
                        echo "<td>{$uid}</td>";
                        echo "<td>{$user[0]}</td>";
                        echo "<td>" . htmlspecialchars($user[1]) . "</td>";
                        echo "<td>{$roleText} ({$user[2]})</td>";
                        echo "<td>" . ($user[3] ? '******' : 'None') . "</td>";
                        echo "</tr>";
                    }
                    echo "</table>";
                    
                    if ($userCount > 5) {
                        echo "<span class='info'>... and " . ($userCount - 5) . " more users</span><br>";
                    }
                } else {
                    echo "<span class='error'>No users found in device</span><br>";
                }
            } else {
                echo "<span class='error'>Failed to get user data</span><br>";
            }
            
            // Get attendance
            echo "<h2>Attendance Data</h2>";
            $attendance = $zk->getAttendance();
            
            if ($attendance && is_array($attendance)) {
                $attCount = count($attendance);
                echo "<span class='success'>✓ Total attendance records found: {$attCount}</span><br>";
                
                if ($attCount > 0) {
                    echo "<h3>Last 5 Attendance Records:</h3>";
                    echo "<table>";
                    echo "<tr><th>No</th><th>UID</th><th>User ID</th><th>State</th><th>Date/Time</th></tr>";
                    
                    // Show last 5 records (most recent)
                    $start = max(0, $attCount - 5);
                    $displayCount = 0;
                    
                    for ($i = $start; $i < $attCount; $i++) {
                        if (isset($attendance[$i])) {
                            $displayCount++;
                            $at = $attendance[$i];
                            
                            // Convert state to text
                            $stateText = 'Check In';
                            if ($at[2] == 1) $stateText = 'Check Out';
                            elseif ($at[2] == 2) $stateText = 'Overtime In';
                            elseif ($at[2] == 3) $stateText = 'Overtime Out';
                            
                            echo "<tr>";
                            echo "<td>{$displayCount}</td>";
                            echo "<td>{$at[0]}</td>";
                            echo "<td>{$at[1]}</td>";
                            echo "<td>{$stateText} ({$at[2]})</td>";
                            echo "<td>{$at[3]}</td>";
                            echo "</tr>";
                        }
                    }
                    echo "</table>";
                } else {
                    echo "<span class='error'>No attendance records found in device</span><br>";
                }
            } else {
                echo "<span class='error'>Failed to get attendance data</span><br>";
            }
            
            // Re-enable device and disconnect
            $zk->enableDevice();
            echo "<span class='info'>Device re-enabled</span><br>";
            
            $zk->disconnect();
            echo "<span class='success'>✓ Disconnected successfully</span><br>";
            
        } else {
            echo "<span class='error'>✗ Connection failed!</span><br>";
            echo "<p>Possible issues:</p>";
            echo "<ul>";
            echo "<li>Wrong protocol (try 'UDP' instead of 'TCP')</li>";
            echo "<li>Device communication port is different</li>";
            echo "<li>Device is busy or in use</li>";
            echo "</ul>";
            
            // Try with UDP as fallback
            echo "<h3>Trying UDP protocol as fallback...</h3>";
            $zk2 = new ZKLibrary($ip, $port, 'UDP');
            if ($zk2->connect()) {
                echo "<span class='success'>✓ UDP connection successful!</span><br>";
                $zk2->disconnect();
            } else {
                echo "<span class='error'>✗ UDP connection also failed</span><br>";
            }
        }
    }
    
} catch (Exception $e) {
    echo "<div class='error'>";
    echo "<h3>Error Occurred:</h3>";
    echo "Message: " . $e->getMessage() . "<br>";
    echo "File: " . $e->getFile() . "<br>";
    echo "Line: " . $e->getLine() . "<br>";
    echo "</div>";
}

echo "</body></html>";
?>
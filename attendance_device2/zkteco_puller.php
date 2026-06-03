<?php
/**
 * ZKTeco Attendance Data Puller - Enhanced Version
 * 
 * Features:
 * - Multi-device support
 * - Multi-schedule support (morning, midmorning, afternoon, evening, night)
 * - Automatic employee mapping
 * - Duplicate entry handling (multiple punch per day)
 * - 24-hour duty shift support
 * - Comprehensive logging
 * - Database transaction safety
 * 
 * Usage:
 *   php zkteco_puller.php [--date=YYYY-MM-DD] [--device=id] [--schedule=morning|...] [--doc_root=PATH]
 */

// ============================================================
// STEP 1: Resolve DOCUMENT_ROOT for CLI execution
// ============================================================
if (php_sapi_name() === 'cli') {
    $early_opts = getopt('', ['doc_root::']);
    
    if (!empty($early_opts['doc_root'])) {
        $_SERVER['DOCUMENT_ROOT'] = rtrim($early_opts['doc_root'], '/\\');
    } elseif (!empty($_SERVER['DOCUMENT_ROOT'])) {
        $_SERVER['DOCUMENT_ROOT'] = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');
    } else {
        $_SERVER['DOCUMENT_ROOT'] = dirname(__DIR__, 2);
    }
}

$_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/\\');

// ============================================================
// STEP 2: Load dependencies
// ============================================================
$required_files = [
    $_root . '/deno2/config/database.php',
    __DIR__ . '/ZKLibrary.php'
];

foreach ($required_files as $file) {
    if (!file_exists($file)) {
        $error = "FATAL: Required file not found: $file\n";
        fwrite(STDERR, $error);
        exit(127);
    }
}

require_once $_root . '/deno2/config/database.php';
require_once __DIR__ . '/ZKLibrary.php';

// ============================================================
// STEP 3: Configuration
// ============================================================
define('LOG_DIR', __DIR__ . '/logs/zkteco/');
define('LOG_FILE', LOG_DIR . 'puller_' . date('Y-m-d') . '.log');
define('ERROR_LOG', LOG_DIR . 'errors_' . date('Y-m-d') . '.log');

// ============================================================
// ZKTecoDataPuller Class
// ============================================================
class ZKTecoDataPuller {
    private $conn;
    private $devices = [];
    private $schedule_type = null;
    private $stats = [
        'total_records' => 0,
        'inserted' => 0,
        'updated' => 0,
        'skipped' => 0,
        'errors' => 0,
        'employees_processed' => []
    ];
    
    private $schedules = [
        'morning'    => ['time' => '07:35', 'description' => 'Morning check-in'],
        'midmorning' => ['time' => '10:45', 'description' => 'Mid-morning update'],
        'afternoon'  => ['time' => '13:25', 'description' => 'After lunch check'],
        'evening'    => ['time' => '17:25', 'description' => 'Evening check-out'],
        'night'      => ['time' => '19:15', 'description' => 'Late shift/OT'],
    ];
    
    public function __construct($db_connection, $schedule_type = null) {
        $this->conn = $db_connection;
        $this->schedule_type = $schedule_type;
        $this->ensureLogDirectory();
        $this->loadDevices();
    }
    
    private function ensureLogDirectory() {
        if (!file_exists(LOG_DIR)) {
            mkdir(LOG_DIR, 0777, true);
        }
    }
    
    private function loadDevices() {
        try {
            $stmt = $this->conn->query("
                SELECT * FROM zkteco_devices
                WHERE is_active = true
                ORDER BY priority
            ");
            $this->devices = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->log("Loaded " . count($this->devices) . " active devices");
        } catch (Exception $e) {
            $this->logError("Failed to load devices: " . $e->getMessage());
            throw $e;
        }
    }
    
    public function pullAttendance($target_date = null, $device_id = null) {
        $start_time = microtime(true);
        $date = $target_date ?: date('Y-m-d');
        
        $schedule_info = $this->schedule_type
            ? " [{$this->schedules[$this->schedule_type]['description']}]"
            : "";
        
        $this->log("=== Starting attendance pull for date: {$date}{$schedule_info} ===");
        
        // Filter devices
        $devices_to_pull = $this->devices;
        if ($device_id) {
            $devices_to_pull = array_filter($this->devices, function($d) use ($device_id) {
                return $d['id'] == $device_id;
            });
        }
        
        if (empty($devices_to_pull)) {
            $this->log("No devices to pull from");
            return false;
        }
        
        try {
            $this->conn->beginTransaction();
            
            foreach ($devices_to_pull as $device) {
                $this->pullFromDevice($device, $date);
            }
            
            $this->updateMonthlySummaries($date);
            $this->calculateDailySummary($date);
            $this->logPullToDatabase($device_id, $date, microtime(true) - $start_time);
            
            $this->conn->commit();
        } catch (Exception $e) {
            $this->conn->rollBack();
            $this->logError("Transaction failed: " . $e->getMessage());
            throw $e;
        }
        
        $duration = round(microtime(true) - $start_time, 2);
        $this->log("=== Pull completed in {$duration}s ===");
        $this->log("Stats: Inserted: {$this->stats['inserted']}, Updated: {$this->stats['updated']}, " .
                   "Skipped: {$this->stats['skipped']}, Errors: {$this->stats['errors']}");
        $this->log("Employees processed: " . count($this->stats['employees_processed']));
        
        return $this->stats;
    }
    
    private function pullFromDevice($device, $date) {
        $this->log("Pulling from device: {$device['device_name']} ({$device['ip_address']})");
        $zk = null;
        
        try {
            $zk = new ZKLibrary($device['ip_address'], $device['port']);
            $zk->setTimeout($device['timeout'] ?? 5);
            
            if (!$zk->connect()) {
                throw new Exception("Failed to connect to {$device['ip_address']}:{$device['port']}");
            }
            
            $this->log("Connected to {$device['device_name']}");
            
            if ($device['disable_during_pull']) {
                $zk->disableDevice();
                $this->log("Device disabled for data pull");
            }
            
            $records = $zk->getAttendance();
            if ($records === false) {
                throw new Exception("Failed to retrieve attendance data");
            }
            
            $this->log("Retrieved " . count($records) . " total records");
            
            // Filter by date
            $date_records = $this->filterRecordsByDate($records, $date);
            $this->log("Filtered to " . count($date_records) . " records for {$date}");
            
            // Process each record
            foreach ($date_records as $record) {
                $this->processRecord($record, $device);
            }
            
            if ($device['disable_during_pull']) {
                $zk->enableDevice();
                $this->log("Device re-enabled");
            }
            
            $zk->disconnect();
            $this->log("Disconnected from {$device['device_name']}");
            
            // Update device status
            $stmt = $this->conn->prepare("
                UPDATE zkteco_devices SET
                    connection_status = 'ONLINE',
                    last_online_at = CURRENT_TIMESTAMP,
                    last_pull_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([':id' => $device['id']]);
            
        } catch (Exception $e) {
            $this->logError("Device {$device['device_name']}: " . $e->getMessage());
            $this->stats['errors']++;
            
            // Update device status to offline
            $stmt = $this->conn->prepare("
                UPDATE zkteco_devices SET connection_status = 'OFFLINE' WHERE id = :id
            ");
            $stmt->execute([':id' => $device['id']]);
            
            if ($zk !== null && $device['disable_during_pull']) {
                try { $zk->enableDevice(); } catch (Exception $e2) {}
            }
        }
    }
    
    private function filterRecordsByDate($records, $target_date) {
        return array_filter($records, function($record) use ($target_date) {
            $record_date = date('Y-m-d', strtotime($record['timestamp']));
            return $record_date === $target_date;
        });
    }
    
    private function processRecord($record, $device) {
        try {
            $this->stats['total_records']++;
            
            $user_id = $record['uid'];
            $timestamp = $record['timestamp'];
            $punch_type = $record['state'];
            
            $datetime = new DateTime($timestamp);
            $date = $datetime->format('Y-m-d');
            $time = $datetime->format('H:i:s');
            
            // Map device user to employee
            $employee = $this->mapDeviceUserToEmployee($user_id, $device['id']);
            if (!$employee) {
                $this->log("Warning: No mapping for device user {$user_id}");
                $this->stats['skipped']++;
                return;
            }
            
            $employee_id = $employee['employee_id'];
            $shift_type = $employee['shift_type'] ?? 'REGULAR';
            
            // Convert to Nepali date
            $date_nep = $this->convertToNepaliDate($date);
            
            // Store in raw table first
            $this->storeRawAttendance($device['id'], $user_id, $timestamp, $punch_type);
            
            // Get or create attendance record
            $existing = $this->getExistingAttendance($employee_id, $date_nep);
            
            if ($existing) {
                $updated = $this->updateAttendanceRecord($existing, $time, $punch_type, $shift_type);
                if ($updated) {
                    $this->stats['updated']++;
                    $this->stats['employees_processed'][$employee_id] = true;
                }
            } else {
                $this->createAttendanceRecord($employee_id, $date, $date_nep, $time, $punch_type, $shift_type);
                $this->stats['inserted']++;
                $this->stats['employees_processed'][$employee_id] = true;
            }
            
        } catch (Exception $e) {
            $this->logError("Error processing record: " . $e->getMessage());
            $this->stats['errors']++;
        }
    }
    
    private function storeRawAttendance($device_id, $user_id, $timestamp, $punch_state) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO zkteco_raw_attendance (
                    device_id, device_user_id, device_uid,
                    punch_time, punch_state
                ) VALUES (
                    :device, :user_id, :uid,
                    :time, :state
                )
            ");
            
            $stmt->execute([
                ':device' => $device_id,
                ':user_id' => $user_id,
                ':uid' => $user_id,
                ':time' => $timestamp,
                ':state' => $punch_state
            ]);
        } catch (Exception $e) {
            // Ignore duplicate raw entries
        }
    }
    
    private function mapDeviceUserToEmployee($device_user_id, $device_id) {
        static $cache = [];
        $cache_key = "{$device_id}_{$device_user_id}";
        
        if (isset($cache[$cache_key])) {
            return $cache[$cache_key];
        }
        
        try {
            $stmt = $this->conn->prepare("
                SELECT
                    zum.employee_id,
                    e.name as employee_name,
                    COALESCE(zum.shift_type, 'REGULAR') as shift_type
                FROM zkteco_user_mapping zum
                JOIN employee e ON zum.employee_id = e.id
                WHERE zum.device_id = :device_id
                AND (zum.device_uid = :user_id OR zum.device_user_id = :user_id)
                AND zum.is_active = true
                AND e.deleted_date IS NULL
            ");
            
            $stmt->execute([
                ':device_id' => $device_id,
                ':user_id' => $device_user_id
            ]);
            
            $result = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($result) {
                $cache[$cache_key] = $result;
                return $result;
            }
            
            return null;
        } catch (Exception $e) {
            $this->logError("Error mapping user: " . $e->getMessage());
            return null;
        }
    }
    
    private function convertToNepaliDate($eng_date) {
        $parts = explode('-', $eng_date);
        $year = (int)$parts[0] + 57;
        $month = (int)$parts[1];
        $day = (int)$parts[2];
        
        $nep_month = $month - 4;
        $nep_year = $year;
        
        if ($nep_month <= 0) {
            $nep_month += 12;
            $nep_year--;
        }
        
        return sprintf('%04d.%02d.%02d', $nep_year, $nep_month, $day);
    }
    
    private function getExistingAttendance($employee_id, $date_nep) {
        $stmt = $this->conn->prepare("
            SELECT * FROM attendance
            WHERE employee_id = :emp_id
            AND attendance_date_nep = :date_nep
        ");
        $stmt->execute([':emp_id' => $employee_id, ':date_nep' => $date_nep]);
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
    
    private function createAttendanceRecord($employee_id, $date_eng, $date_nep, $time, $punch_type, $shift_type) {
        $check_in = null;
        $check_out = null;
        $status_id = 1; // Present
        
        if ($punch_type == 0 || $punch_type == 'I') {
            $check_in = $time;
        } else {
            $check_out = $time;
        }
        
        $break_hours = ($shift_type === 'DUTY_24HR') ? 0 : 1.0;
        
        $stmt = $this->conn->prepare("
            INSERT INTO attendance (
                employee_id, attendance_date_nep, attendance_date_eng,
                status_id, check_in_time, check_out_time, break_hours,
                remarks, marked_by, created_at, data_source, shift_type
            ) VALUES (
                :employee_id, :date_nep, :date_eng,
                :status_id, :check_in, :check_out, :break_hours,
                'Auto-imported from ZKTeco', 0, CURRENT_TIMESTAMP, 'ZKTECO', :shift_type
            )
        ");
        
        $stmt->execute([
            ':employee_id' => $employee_id,
            ':date_nep' => $date_nep,
            ':date_eng' => $date_eng,
            ':status_id' => $status_id,
            ':check_in' => $check_in,
            ':check_out' => $check_out,
            ':break_hours' => $break_hours,
            ':shift_type' => $shift_type
        ]);
    }
    
    private function updateAttendanceRecord($existing, $time, $punch_type, $shift_type) {
        $updates = [];
        $params = [':id' => $existing['id']];
        $updated = false;
        
        // Logic for updating check-in/check-out
        if ($shift_type === 'DUTY_24HR') {
            // For 24-hour duty: earliest check-in, latest check-out
            if (empty($existing['check_in_time'])) {
                $updates[] = "check_in_time = :check_in";
                $params[':check_in'] = $time;
                $updated = true;
            } elseif (empty($existing['check_out_time']) || $time > $existing['check_out_time']) {
                $updates[] = "check_out_time = :check_out";
                $params[':check_out'] = $time;
                $updated = true;
            }
        } else {
            // Regular shift
            if ($punch_type == 0 || $punch_type == 'I') {
                // Check-in: use earliest time
                if (empty($existing['check_in_time']) || $time < $existing['check_in_time']) {
                    $updates[] = "check_in_time = :check_in";
                    $params[':check_in'] = $time;
                    $updated = true;
                }
            } else {
                // Check-out: use latest time
                if (empty($existing['check_out_time']) || $time > $existing['check_out_time']) {
                    $updates[] = "check_out_time = :check_out";
                    $params[':check_out'] = $time;
                    $updated = true;
                }
            }
        }
        
        if (!$updated) return false;
        
        $updates[] = "updated_at = CURRENT_TIMESTAMP";
        $updates[] = "data_source = 'ZKTECO'";
        
        $sql = "UPDATE attendance SET " . implode(', ', $updates) . " WHERE id = :id";
        $stmt = $this->conn->prepare($sql);
        $stmt->execute($params);
        
        return true;
    }
    
    private function updateMonthlySummaries($date) {
        if (empty($this->stats['employees_processed'])) return;
        
        $nep_date = $this->convertToNepaliDate($date);
        $year_month = substr($nep_date, 0, 7);
        
        foreach (array_keys($this->stats['employees_processed']) as $emp_id) {
            try {
                $stmt = $this->conn->prepare("CALL update_monthly_summary(:emp_id, :month)");
                $stmt->execute([':emp_id' => $emp_id, ':month' => $year_month]);
            } catch (Exception $e) {
                $this->logError("Failed to update summary for employee {$emp_id}");
            }
        }
    }
    
    private function calculateDailySummary($date) {
        try {
            $nep_date = $this->convertToNepaliDate($date);
            $stmt = $this->conn->prepare("
                SELECT
                    COUNT(*) as total,
                    COUNT(CASE WHEN ast.status_code = 'P' THEN 1 END) as present,
                    COUNT(CASE WHEN ast.status_code = 'A' THEN 1 END) as absent
                FROM attendance a
                JOIN attendance_status ast ON a.status_id = ast.id
                WHERE a.attendance_date_nep = :date_nep
            ");
            $stmt->execute([':date_nep' => $nep_date]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            $this->log("Daily Summary: " . json_encode($summary));
        } catch (Exception $e) {
            $this->logError("Error calculating daily summary: " . $e->getMessage());
        }
    }
    
    private function logPullToDatabase($device_id, $date, $duration) {
        try {
            $stmt = $this->conn->prepare("
                INSERT INTO zkteco_pull_log (
                    device_id, pull_date, pull_time, schedule_type,
                    total_records, inserted_records, updated_records,
                    skipped_records, error_records, employees_processed,
                    status, duration_seconds, completed_at
                ) VALUES (
                    :device_id, :pull_date, CURRENT_TIME, :schedule_type,
                    :total, :inserted, :updated,
                    :skipped, :errors, :employees,
                    :status, :duration, CURRENT_TIMESTAMP
                )
            ");
            
            $stmt->execute([
                ':device_id' => $device_id,
                ':pull_date' => $date,
                ':schedule_type' => $this->schedule_type,
                ':total' => $this->stats['total_records'],
                ':inserted' => $this->stats['inserted'],
                ':updated' => $this->stats['updated'],
                ':skipped' => $this->stats['skipped'],
                ':errors' => $this->stats['errors'],
                ':employees' => count($this->stats['employees_processed']),
                ':status' => ($this->stats['errors'] === 0) ? 'SUCCESS' : 'PARTIAL',
                ':duration' => round($duration, 2)
            ]);
        } catch (Exception $e) {
            $this->logError("Failed to write pull log: " . $e->getMessage());
        }
    }
    
    private function log($message) {
        $ts = date('Y-m-d H:i:s');
        $line = "[{$ts}] {$message}\n";
        file_put_contents(LOG_FILE, $line, FILE_APPEND);
        if (php_sapi_name() === 'cli') echo $line;
    }
    
    private function logError($message) {
        $ts = date('Y-m-d H:i:s');
        $line = "[{$ts}] ERROR: {$message}\n";
        file_put_contents(ERROR_LOG, $line, FILE_APPEND);
        file_put_contents(LOG_FILE, $line, FILE_APPEND);
        if (php_sapi_name() === 'cli') fwrite(STDERR, $line);
    }
    
    public function getStats() {
        return $this->stats;
    }
}

// ============================================================
// CLI EXECUTION
// ============================================================
if (php_sapi_name() === 'cli') {
    $options = getopt('', ['date::', 'device::', 'schedule::', 'doc_root::']);
    
    $date = $options['date'] ?? null;
    $device_id = $options['device'] ?? null;
    $schedule = $options['schedule'] ?? null;
    
    echo "ZKTeco Attendance Puller\n";
    echo "========================\n";
    echo "Date: " . ($date ?: date('Y-m-d')) . "\n";
    echo "Device: " . ($device_id ?: 'ALL') . "\n";
    echo "Schedule: " . ($schedule ?: 'auto') . "\n\n";
    
    try {
        $puller = new ZKTecoDataPuller($conn, $schedule);
        $stats = $puller->pullAttendance($date, $device_id);
        
        echo "\n=== Pull Summary ===\n";
        echo "Inserted: {$stats['inserted']}\n";
        echo "Updated: {$stats['updated']}\n";
        echo "Skipped: {$stats['skipped']}\n";
        echo "Errors: {$stats['errors']}\n";
        echo "Employees: " . count($stats['employees_processed']) . "\n";
        
        exit(0);
    } catch (Exception $e) {
        echo "FATAL ERROR: " . $e->getMessage() . "\n";
        exit(1);
    }
}
?>

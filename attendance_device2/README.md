# ZKTeco Attendance Management System

Complete attendance management system for ZKTeco biometric devices with multi-device support, automatic data synchronization, and comprehensive reporting.

## Features

✅ **Multi-Device Management**
- Add, edit, and monitor multiple ZKTeco devices
- Real-time connection testing
- Device capacity monitoring
- Automatic device status tracking

✅ **User Mapping**
- Map device users to employees
- Auto-mapping by attendance ID
- Pull users directly from device
- Sync users back to device
- Support for multiple shifts

✅ **Attendance Pulling**
- Multi-schedule automatic pulls (5 times daily)
- Manual pull on-demand
- Support for multiple entries per day
- 24-hour duty shift support
- Duplicate entry handling

✅ **Data Management**
- Raw attendance data storage
- Automatic employee mapping
- Monthly summary updates
- Comprehensive logging
- Transaction safety

## System Requirements

- PHP 7.4+ with extensions:
  - `sockets`
  - `pdo_mysql`
  - `mbstring`
- MySQL 5.7+ or MariaDB 10.3+
- Windows Server / Linux server
- Network access to ZKTeco devices (UDP port 4370)

## Installation

### Step 1: Database Setup

```sql
-- Run the database schema
mysql -u root -p your_database < database_schema.sql
```

### Step 2: File Deployment

Copy all files to your web directory:

```
/deno2/attendance_device/
├── ZKLibrary.php              # Core library
├── zkteco_puller.php          # Attendance puller script
├── zkteco_device_management.php    # Device management UI
├── zkteco_user_mapping.php    # User mapping UI
├── zkteco_ajax_device.php     # Device AJAX handler
├── zkteco_ajax_mapping.php    # Mapping AJAX handler
├── zkteco_ajax_pull.php       # Pull AJAX handler
├── zkteco_auto_pull.bat       # Windows scheduler batch file
└── logs/zkteco/               # Log directory (auto-created)
```

### Step 3: Configuration

1. **Update database connection** in `/deno2/config/database.php`:
```php
$host = 'localhost';
$dbname = 'your_database';
$username = 'your_user';
$password = 'your_password';
```

2. **Set PHP CLI path** (Windows):
   - Open `zkteco_auto_pull.bat`
   - Update `SET PHP_PATH=C:\xampp\php\php.exe`
   - Update `SET WEB_ROOT=C:\xampp\htdocs`

3. **Configure settings** in database:
```sql
UPDATE zkteco_settings 
SET setting_value = 'C:\\xampp\\php\\php.exe' 
WHERE setting_key = 'php_cli_path';
```

### Step 4: Windows Task Scheduler Setup

1. Open **Task Scheduler** (Win + R → `taskschd.msc`)

2. Create **5 scheduled tasks** for each pull time:

#### Morning Pull (07:35 AM)
- **Name:** ZKTeco Morning Pull
- **Trigger:** Daily at 7:35 AM
- **Action:** Start a program
  - **Program:** `C:\xampp\htdocs\deno2\attendance_device\zkteco_auto_pull.bat`
  - **Start in:** `C:\xampp\htdocs\deno2\attendance_device\`

#### Mid-Morning Pull (10:45 AM)
- Repeat above with time: 10:45 AM

#### Afternoon Pull (01:25 PM)
- Repeat above with time: 1:25 PM

#### Evening Pull (05:25 PM)
- Repeat above with time: 5:25 PM

#### Night Pull (07:15 PM)
- Repeat above with time: 7:15 PM

**Important Settings:**
- ✅ Run whether user is logged on or not
- ✅ Run with highest privileges
- ✅ Configure for: Windows 10/Server 2016
- ✅ Allow task to be run on demand
- ✅ If task fails, restart every: 1 minute (3 attempts)

### Step 5: Linux Cron Setup (Alternative)

Add to crontab:
```bash
# Morning pull
35 07 * * * php /var/www/html/deno2/attendance_device/zkteco_puller.php --schedule=morning

# Mid-morning pull
45 10 * * * php /var/www/html/deno2/attendance_device/zkteco_puller.php --schedule=midmorning

# Afternoon pull
25 13 * * * php /var/www/html/deno2/attendance_device/zkteco_puller.php --schedule=afternoon

# Evening pull
25 17 * * * php /var/www/html/deno2/attendance_device/zkteco_puller.php --schedule=evening

# Night pull
15 19 * * * php /var/www/html/deno2/attendance_device/zkteco_puller.php --schedule=night
```

## Usage

### 1. Device Management

**Access:** `http://yourserver/deno2/attendance_device/zkteco_device_management.php`

**Add Device:**
1. Click "Add New Device"
2. Fill in details:
   - Device Name
   - Device Code (unique identifier)
   - IP Address
   - Port (default: 4370)
   - Location
3. Click "Test Connection" to verify
4. Save device

**Test Device:**
- Click "Test" button on any device
- System will connect and verify communication

**View Device Info:**
- Click "Info" to see:
  - Firmware version
  - Serial number
  - Capacity (users/logs)
  - Current device time

### 2. User Mapping

**Access:** `http://yourserver/deno2/attendance_device/zkteco_user_mapping.php`

**Method 1: Pull from Device**
1. Select device
2. Click "Pull Users from Device"
3. Review list of device users
4. Click "Map" next to each user
5. Select corresponding employee

**Method 2: Auto-Map by Attendance ID**
1. Ensure employees have `attendance_id` field populated
2. Select device
3. Click "Auto-Map by Attendance ID"
4. System matches automatically

**Method 3: Manual Mapping**
1. Click "Add Mapping"
2. Select employee
3. Enter device user ID and UID
4. Configure shift settings
5. Check "Push to device immediately" to sync
6. Save mapping

**Sync to Device:**
- Click "Sync" on individual mappings
- Or "Sync All to Device" for bulk sync
- System will push user data to device

### 3. Manual Pull

**From Dashboard:**
1. Navigate to main dashboard
2. Click "Pull Now" on any device
3. Select date (default: today)
4. Select schedule type (optional)
5. Click "Start Pull"
6. Monitor progress and view statistics

**From Command Line:**
```bash
# Pull specific device for today
php zkteco_puller.php --device=1

# Pull specific date
php zkteco_puller.php --date=2025-02-15

# Pull with schedule type
php zkteco_puller.php --schedule=evening

# Pull specific device for specific date
php zkteco_puller.php --device=1 --date=2025-02-15 --schedule=afternoon
```

### 4. Viewing Logs

**Pull Logs:**
```sql
SELECT * FROM zkteco_pull_log 
ORDER BY started_at DESC 
LIMIT 50;
```

**Raw Attendance Data:**
```sql
SELECT * FROM zkteco_raw_attendance 
WHERE pull_date = CURDATE()
ORDER BY punch_time DESC;
```

**File Logs:**
- Location: `/logs/zkteco/`
- `puller_YYYY-MM-DD.log` - Pull operations
- `errors_YYYY-MM-DD.log` - Error messages

## Troubleshooting

### Connection Issues

**Problem:** Cannot connect to device

**Solutions:**
1. Verify device IP address: `ping 192.168.1.100`
2. Check firewall (allow UDP port 4370)
3. Ensure device is on same network/subnet
4. Verify device is powered on and network cable connected
5. Check device web interface (if available)

**Problem:** Connection timeout

**Solutions:**
1. Increase timeout in device settings
2. Check network latency
3. Verify no proxy/VPN blocking UDP traffic

### Pull Issues

**Problem:** Pull fails with exit code 3

**Solutions:**
1. Check PHP CLI path in settings
2. Verify DOCUMENT_ROOT is correct
3. Check file permissions
4. Review `errors_YYYY-MM-DD.log`

**Problem:** No records pulled

**Solutions:**
1. Verify user mappings exist
2. Check device has attendance data
3. Verify date range is correct
4. Check device clock is synchronized

### Mapping Issues

**Problem:** Cannot push user to device

**Solutions:**
1. Ensure device UID is unique
2. Check device capacity (not full)
3. Disable device during push
4. Verify user ID format matches device requirements

**Problem:** Duplicate mapping error

**Solutions:**
1. Check for existing mapping
2. Delete old mapping first
3. Use different device UID

## Multiple Entry Handling

The system handles multiple check-in/out per day:

**Regular Shifts:**
- First check-in of day = check_in_time
- Last check-out of day = check_out_time
- Intermediate entries stored in raw table

**24-Hour Duty:**
- First entry = check_in_time
- Last entry = check_out_time
- All entries logged

**Example:**
```
Employee punches: 07:30, 07:45, 12:00, 13:00, 17:00, 17:30

Result in attendance table:
- check_in_time: 07:30 (earliest)
- check_out_time: 17:30 (latest)

All 6 entries in zkteco_raw_attendance table
```

## Database Schema Overview

### Main Tables

**zkteco_devices** - Device configuration
**zkteco_user_mapping** - Device user to employee mapping
**zkteco_pull_log** - Pull operation history
**zkteco_raw_attendance** - Raw punch data
**zkteco_sync_queue** - Pending sync operations
**zkteco_settings** - System settings

## API Reference

### ZKLibrary Methods

```php
$zk = new ZKLibrary('192.168.1.100', 4370);

// Connection
$zk->connect();
$zk->disconnect();
$zk->setTimeout(10);

// Device control
$zk->disableDevice();
$zk->enableDevice();

// User management
$users = $zk->getUser();
$zk->setUser($uid, $userid, $name, $password, $role);
$zk->deleteUser($uid);

// Attendance
$records = $zk->getAttendance();
$zk->clearAttendance();

// Device info
$version = $zk->version();
$serial = $zk->serialNumber();
$time = $zk->getTime();
$zk->setTime('2025-02-16 10:30:00');
$capacity = $zk->getFreeSizes();
```

## Best Practices

1. **Regular Backups:**
   - Backup database daily
   - Archive log files monthly
   - Keep raw attendance data for audit

2. **Monitoring:**
   - Check pull logs daily
   - Monitor device connection status
   - Review error logs weekly

3. **Maintenance:**
   - Clear device memory monthly (if enabled)
   - Update firmware annually
   - Test disaster recovery quarterly

4. **Security:**
   - Restrict access to management pages
   - Use HTTPS for web interface
   - Secure database credentials
   - Limit network access to devices

## Support

For issues or questions:
1. Check logs in `/logs/zkteco/`
2. Review database pull_log table
3. Test device connection
4. Verify network connectivity
5. Check Task Scheduler history (Windows)

## License

Internal use only. All rights reserved.

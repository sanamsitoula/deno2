# 🔌 ZKTECO DEVICE INTEGRATION GUIDE

## 📋 Overview

Automatic attendance data pulling from ZKTeco biometric devices with **5 daily pull schedules** to capture all employee punches for both regular 8-hour shifts and 24-hour duty shifts.

---

## ⏰ **Pull Schedules**

| Time     | Schedule     | Purpose                                    |
|----------|--------------|-------------------------------------------|
| 07:35 AM | Morning      | Capture morning check-in punches          |
| 10:45 AM | Mid-Morning  | Update mid-morning data                   |
| 01:25 PM | Afternoon    | Capture after-lunch check-in              |
| 05:25 PM | Evening      | Capture evening check-out (main shift)    |
| 07:15 PM | Night        | Capture late shift/OT + clean old data    |

---

## 📦 **Complete File List**

1. ✅ `zkteco_puller.php` - Main data puller script
2. ✅ `lib/ZKLibrary.php` - ZKTeco device communication library
3. ✅ `zkteco_schema.sql` - Database schema for device integration
4. ✅ `pull_morning.bat` - Windows batch script for 07:35 AM
5. ✅ `pull_midmorning.bat` - Windows batch script for 10:45 AM
6. ✅ `pull_afternoon.bat` - Windows batch script for 01:25 PM
7. ✅ `pull_evening.bat` - Windows batch script for 05:25 PM
8. ✅ `pull_night.bat` - Windows batch script for 07:15 PM

---

## 🔧 **INSTALLATION**

### **Step 1: Database Setup** (5 minutes)

```bash
# Connect to PostgreSQL
psql -U postgres -d your_database

# Run ZKTeco schema
\i zkteco_schema.sql

# Verify tables created
\dt zkteco*
```

**Expected Output:**
```
 zkteco_devices
 zkteco_user_mapping
 zkteco_pull_log
 zkteco_raw_data
```

### **Step 2: Deploy PHP Files** (2 minutes)

```bash
# Copy main puller
cp zkteco_puller.php C:/xampp/htdocs/your-app/

# Copy library
mkdir -p C:/xampp/htdocs/your-app/lib
cp lib/ZKLibrary.php C:/xampp/htdocs/your-app/lib/

# Create log directory
mkdir -p C:/xampp/htdocs/your-app/logs/zkteco
```

### **Step 3: Configure Devices** (10 minutes)

**Add your ZKTeco devices to database:**

```sql
INSERT INTO zkteco_devices (
    device_name, device_code, ip_address, port, 
    location, priority, is_active
) VALUES 
('Main Entrance Device', 'ZK001', '192.168.1.100', 4370, 'Main Gate', 1, true),
('Production Floor Device', 'ZK002', '192.168.1.101', 4370, 'Manufacturing Area', 2, true);
```

**Test device connectivity:**

```bash
# Ping device
ping 192.168.1.100

# Test connection (using telnet)
telnet 192.168.1.100 4370
```

### **Step 4: Map Employees to Device Users** (15 minutes)

**Option A: Bulk mapping (if attendance_id matches device user ID):**

```sql
-- Auto-map all employees where attendance_id is set
INSERT INTO zkteco_user_mapping (
    device_id, device_user_id, employee_id, shift_type
)
SELECT 
    1, -- Change to your device_id
    e.attendance_id,
    e.id,
    CASE 
        WHEN e.emp_type = 'DAILY_WAGES' THEN 'DUTY_24HR'
        ELSE 'REGULAR'
    END
FROM employee e
WHERE e.attendance_id IS NOT NULL
AND e.deleted_date IS NULL
ON CONFLICT DO NOTHING;
```

**Option B: Manual mapping:**

```sql
-- Map individual employees
INSERT INTO zkteco_user_mapping (
    device_id, device_user_id, employee_id, shift_type
) VALUES 
(1, '001', 1, 'REGULAR'),    -- Employee ID 1 maps to device user 001
(1, '002', 2, 'REGULAR'),
(1, '003', 3, 'DUTY_24HR');  -- 24-hour duty shift
```

**Verify mappings:**

```sql
SELECT * FROM v_employee_device_mapping ORDER BY employee_code;
```

### **Step 5: Test Manual Pull** (5 minutes)

```bash
# Navigate to your app directory
cd C:\xampp\htdocs\your-app

# Test morning pull
php zkteco_puller.php --schedule=morning

# Expected output:
# Loaded 2 active devices
# Pulling from device: Main Entrance Device (192.168.1.100)
# Connected to Main Entrance Device
# Retrieved 45 records from device
# Filtered to 12 records for date 2026-02-14
# === Pull completed in 3.45s ===
# Inserted: 8, Updated: 4, Errors: 0
```

### **Step 6: Setup Windows Task Scheduler** (10 minutes)

**Open Task Scheduler:**
- Press `Win + R`, type `taskschd.msc`, press Enter

**Create Tasks for Each Schedule:**

#### **Morning Pull (07:35 AM)**

1. Click "Create Task"
2. **General Tab:**
   - Name: `ZKTeco Morning Pull`
   - Description: `Pull attendance data at 07:35 AM`
   - Run whether user is logged on or not: ✅
   - Run with highest privileges: ✅

3. **Triggers Tab:**
   - New Trigger
   - Begin: `On a schedule`
   - Daily, recur every 1 day
   - Start: `07:35:00 AM`
   - Enabled: ✅

4. **Actions Tab:**
   - New Action
   - Action: `Start a program`
   - Program/script: `C:\xampp\htdocs\your-app\windows_scripts\pull_morning.bat`

5. **Conditions Tab:**
   - Uncheck: `Start only if computer is on AC power`

6. **Settings Tab:**
   - Allow task to run on demand: ✅
   - If task fails, restart every: `5 minutes`
   - Attempt restart up to: `3 times`

7. Click OK, enter Windows password

#### **Repeat for Other Schedules:**

**Mid-Morning (10:45 AM):**
- Name: `ZKTeco Mid-Morning Pull`
- Time: `10:45:00 AM`
- Script: `pull_midmorning.bat`

**Afternoon (01:25 PM):**
- Name: `ZKTeco Afternoon Pull`
- Time: `01:25:00 PM`
- Script: `pull_afternoon.bat`

**Evening (05:25 PM):**
- Name: `ZKTeco Evening Pull`
- Time: `05:25:00 PM`
- Script: `pull_evening.bat`

**Night (07:15 PM):**
- Name: `ZKTeco Night Pull`
- Time: `07:15:00 PM`
- Script: `pull_night.bat`

### **Step 7: Configure Batch Scripts** (5 minutes)

**Edit each .bat file and update paths:**

```batch
REM Update these paths to match your environment
SET PHP_PATH=C:\php\php.exe
SET SCRIPT_PATH=C:\xampp\htdocs\your-app\zkteco_puller.php
SET LOG_PATH=C:\xampp\htdocs\your-app\logs\zkteco\scheduler_morning.log
```

**Common PHP paths:**
- XAMPP: `C:\xampp\php\php.exe`
- Standalone: `C:\php\php.exe`
- WAMP: `C:\wamp64\bin\php\php7.4.26\php.exe`

---

## 🎯 **HOW IT WORKS**

### **Shift Types**

**1. REGULAR Shift (8 hours, 1 hour break = 7 working hours)**

```
Shift: 08:00 AM - 05:00 PM
Break: 12:00 PM - 01:00 PM (1 hour)
Working: 7 hours
OT: Hours beyond 8 total hours
```

**Processing Logic:**
- First punch of day = check-in (earliest time)
- Last punch of day = check-out (latest time)
- Multiple punches OK (break punches ignored)
- Break time: 1 hour (deducted from total)

**2. DUTY_24HR Shift (24-hour duty)**

```
Shift: 24 hours continuous
Break: None
Working: All hours worked
OT: Hours beyond 8 hours
```

**Processing Logic:**
- First punch = check-in
- Last punch = check-out
- No break deduction
- Full time counted

### **Data Flow**

```
1. Device Punch
   ↓
2. ZKTeco Device (stores in memory)
   ↓
3. PHP Puller Script (scheduled pull)
   ↓
4. ZKLibrary (TCP/IP communication)
   ↓
5. Device User ID → Employee Mapping
   ↓
6. Attendance Table (database insert/update)
   ↓
7. Monthly Summary (auto-update)
```

### **Multiple Punches Handling**

**Scenario: Employee punches 5 times in a day**

```
Punches:
08:00 AM - Check-in
12:00 PM - Break out
01:00 PM - Break in
05:00 PM - Check-out
07:00 PM - Late punch (OT)

Result:
Check-in:  08:00 AM (earliest)
Check-out: 07:00 PM (latest)
Working:   11 hours
Break:     1 hour
Actual:    10 hours
OT:        2 hours
```

---

## 📊 **MONITORING & REPORTS**

### **Check Pull Status**

```sql
-- Today's pulls
SELECT * FROM zkteco_pull_log 
WHERE pull_date = CURRENT_DATE
ORDER BY pull_time;

-- Device status
SELECT * FROM v_zkteco_device_status;

-- Pull statistics (last 7 days)
SELECT * FROM v_zkteco_pull_statistics
WHERE date >= CURRENT_DATE - 7
ORDER BY date DESC, schedule_type;
```

### **Common Queries**

**Employees not mapped:**
```sql
SELECT e.id, e.code, e.name, e.attendance_id
FROM employee e
WHERE e.deleted_date IS NULL
AND e.id NOT IN (SELECT employee_id FROM zkteco_user_mapping)
ORDER BY e.code;
```

**Failed pulls today:**
```sql
SELECT * FROM zkteco_pull_log
WHERE pull_date = CURRENT_DATE
AND status = 'FAILED';
```

**Attendance imported today:**
```sql
SELECT 
    e.code,
    e.name,
    a.attendance_date_nep,
    a.check_in_time,
    a.check_out_time,
    a.actual_working_hours,
    a.ot_hours
FROM attendance a
JOIN employee e ON a.employee_id = e.id
WHERE a.created_at::DATE = CURRENT_DATE
AND a.data_source = 'ZKTECO'
ORDER BY e.code;
```

---

## 🐛 **TROUBLESHOOTING**

### **Problem: Device connection failed**

**Check:**
```bash
# 1. Ping device
ping 192.168.1.100

# 2. Check port is open
telnet 192.168.1.100 4370

# 3. Check firewall
# Windows Firewall → Allow port 4370

# 4. Test from PHP
php -r "
  $fp = @fsockopen('192.168.1.100', 4370, $errno, $errstr, 5);
  echo $fp ? 'Connected' : 'Failed: ' . $errstr;
"
```

**Solution:**
- Verify device IP address
- Check network connectivity
- Allow port 4370 in firewall
- Restart device if needed

### **Problem: No records pulled**

**Check:**
```sql
-- Check if device has records
SELECT last_pull_at, last_pull_records, connection_status
FROM zkteco_devices
WHERE id = 1;

-- Check mappings
SELECT COUNT(*) FROM zkteco_user_mapping WHERE device_id = 1;
```

**Solution:**
- Verify device has attendance data
- Check employee mappings exist
- Verify date filter is correct

### **Problem: Employee not mapped**

**Error:** `Warning: No employee mapping for device user 123`

**Solution:**
```sql
-- Add mapping
INSERT INTO zkteco_user_mapping (device_id, device_user_id, employee_id, shift_type)
VALUES (1, '123', 45, 'REGULAR');
-- Where 45 is the actual employee ID
```

### **Problem: Scheduled task not running**

**Check:**
1. Task Scheduler → Task History (enable if disabled)
2. View log file: `C:\xampp\htdocs\your-app\logs\zkteco\scheduler_morning.log`
3. Check task "Last Run Result": 0x0 = success

**Common Issues:**
- Wrong PHP path → Update .bat file
- Permissions → Run as administrator
- User not logged in → Enable "Run whether user is logged in or not"

### **Problem: Duplicate attendance records**

**Prevention:**
System automatically prevents duplicates with:
```sql
CONSTRAINT uq_attendance_emp_date UNIQUE (employee_id, attendance_date_nep)
```

If duplicates exist:
```sql
-- Find duplicates
SELECT employee_id, attendance_date_nep, COUNT(*)
FROM attendance
GROUP BY employee_id, attendance_date_nep
HAVING COUNT(*) > 1;

-- Delete duplicates (keeping earliest created)
DELETE FROM attendance a
WHERE a.id NOT IN (
    SELECT MIN(id)
    FROM attendance
    GROUP BY employee_id, attendance_date_nep
);
```

---

## 📈 **PERFORMANCE**

### **Expected Processing Times**

| Employees | Records/Day | Pull Time |
|-----------|-------------|-----------|
| 10        | 20-40       | 2-3 sec   |
| 50        | 100-200     | 5-8 sec   |
| 100       | 200-400     | 10-15 sec |
| 500       | 1000-2000   | 30-45 sec |

### **Optimization Tips**

**1. Device Settings:**
```sql
-- Increase timeout for slow networks
UPDATE zkteco_devices 
SET timeout = 10 
WHERE ip_address = '192.168.1.100';

-- Auto-clear old records (keeps device memory free)
UPDATE zkteco_devices 
SET auto_clear_records = true
WHERE id = 1;
```

**2. Database Indexes:**
Already created in schema. Verify with:
```sql
SELECT indexname FROM pg_indexes 
WHERE tablename LIKE 'zkteco%';
```

**3. Log Cleanup:**
```sql
-- Run monthly to clean old logs
SELECT cleanup_zkteco_logs(); -- Removes logs older than 30 days
```

---

## 🔒 **SECURITY**

### **Device Access**

- Use dedicated VLAN for attendance devices
- Restrict access to device management interface
- Change default admin password on devices
- Use static IP addresses for devices

### **Database Security**

```sql
-- Create readonly user for reporting
CREATE USER attendance_reader WITH PASSWORD 'strong_password';
GRANT SELECT ON ALL TABLES IN SCHEMA public TO attendance_reader;

-- Audit trail
SELECT * FROM zkteco_pull_log WHERE pull_date >= CURRENT_DATE - 7;
```

### **File Permissions**

```bash
# Secure log directory (Windows)
icacls C:\xampp\htdocs\your-app\logs /grant Users:(OI)(CI)M

# Restrict batch scripts
icacls C:\xampp\htdocs\your-app\windows_scripts /inheritance:r /grant Administrators:F
```

---

## 📞 **SUPPORT CHECKLIST**

Before requesting support, verify:

- [ ] Database schema installed (`zkteco_devices` table exists)
- [ ] PHP files deployed correctly
- [ ] Device IP is reachable (ping successful)
- [ ] Employee mappings created
- [ ] Test manual pull works
- [ ] Windows Task Scheduler tasks created
- [ ] Batch script paths are correct
- [ ] Logs directory exists and is writable
- [ ] PHP socket extension enabled (`extension=sockets`)

**Check PHP socket extension:**
```bash
php -m | grep sockets
# Should output: sockets
```

**Enable if missing:**
Edit `php.ini`, uncomment:
```ini
extension=sockets
```

---

## 🎓 **TRAINING GUIDE**

### **For IT Staff (Day 1)**

**Morning:**
- Install database schema
- Deploy PHP files
- Configure first device
- Test manual pull

**Afternoon:**
- Map employees
- Setup Windows Task Scheduler
- Test all 5 schedules
- Configure monitoring

### **For HR Staff (Day 2)**

- View pull logs in database
- Check daily attendance reports
- Verify employee attendance
- Handle exceptions (unmapped employees)

### **For Managers (Day 3)**

- Monitor attendance dashboard
- Review monthly summaries
- Export reports
- Understand OT calculations

---

## ✅ **SUCCESS METRICS**

After full setup, you should see:

✅ 5 successful pulls per day
✅ 100% employee mapping coverage
✅ <30 seconds average pull time
✅ 0 connection errors
✅ Automatic monthly summaries
✅ Accurate OT calculations
✅ Real-time attendance visibility

---

**System Status:** PRODUCTION READY 🚀
**Version:** 1.0
**Last Updated:** February 2026
**Support:** Check logs directory for troubleshooting

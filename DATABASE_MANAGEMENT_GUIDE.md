# DATABASE MANAGEMENT GUIDE
## Janak Education Materials Center (JEMC) - Press Management System

---

## 📚 TABLE OF CONTENTS
1. [Database Overview](#database-overview)
2. [Connection Details](#connection-details)
3. [Backup & Recovery](#backup--recovery)
4. [Data Migration](#data-migration)
5. [Common Operations](#common-operations)
6. [Performance Tips](#performance-tips)
7. [Troubleshooting](#troubleshooting)

---

## 🗄️ DATABASE OVERVIEW

### PostgreSQL Server Details
```
Host: localhost
Port: 5432
Database Name: press_jemc
Database Owner: postgres
Database Encoding: UTF-8
Database Size: ~50-100 MB (varies with data)
```

### Connection String
```
PostgreSQL: pgsql:host=localhost;port=5432;dbname=press_jemc
PDO String: pgsql:host=localhost;port=5432;dbname=press_jemc
JDBC URL: jdbc:postgresql://localhost:5432/press_jemc
```

### Database Statistics
| Metric | Value |
|--------|-------|
| Total Tables | 58 |
| Total Views | 29 |
| Total Functions | ~10+ |
| Indexes | Auto-generated |
| Encoding | UTF-8 |

---

## 🔐 CONNECTION DETAILS

### PHP Configuration (PDO)
**File**: `/config/database.php`

```php
<?php
$host = "localhost";
$port = "5432";
$dbname = "press_jemc";
$user = "postgres";
$password = "Nepal@123";

try {
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    $db_status = true;
} catch (PDOException $e) {
    $db_status = false;
    $db_error = $e->getMessage();
    die("Connection failed: " . $e->getMessage());
}
?>
```

### Command Line Connection
```bash
# Connect to press_jemc database
psql -h localhost -U postgres -d press_jemc

# Connect without entering password (if configured)
PGPASSWORD=Nepal@123 psql -h localhost -U postgres -d press_jemc

# Execute SQL command directly
psql -h localhost -U postgres -d press_jemc -c "SELECT version();"

# Execute SQL from file
psql -h localhost -U postgres -d press_jemc -f script.sql
```

### Python Connection (SQLAlchemy)
```python
import psycopg2

conn = psycopg2.connect(
    host="localhost",
    database="press_jemc",
    user="postgres",
    password="Nepal@123",
    port=5432
)

cur = conn.cursor()
cur.execute("SELECT COUNT(*) FROM employee;")
print(cur.fetchone())
cur.close()
conn.close()
```

---

## 💾 BACKUP & RECOVERY

### 1. Full Database Backup

#### SQL Text Format (Human Readable)
```bash
# Single-threaded, smaller file
pg_dump -h localhost -U postgres -d press_jemc > backup_press_jemc_$(date +%Y%m%d_%H%M%S).sql

# With verbose output
pg_dump -h localhost -U postgres -v -d press_jemc > backup_detailed.sql

# With custom format (compressed, faster for large DBs)
pg_dump -h localhost -U postgres -Fc -d press_jemc > backup_press_jemc.dump

# With parallel jobs (faster on multi-core)
pg_dump -h localhost -U postgres -j 4 -Fd -d press_jemc -f backup_directory/
```

#### Binary Format (Recommended for Production)
```bash
# Custom format (compressed, most efficient)
pg_dump -h localhost -U postgres -Fc -f press_jemc_backup_$(date +%Y%m%d).dump -d press_jemc

# Directory format (parallel restoration)
pg_dump -h localhost -U postgres -Fd -j 4 -f press_jemc_backup_dir -d press_jemc
```

### 2. Restore from Backup

#### From SQL File
```bash
# Basic restore
psql -h localhost -U postgres -d press_jemc < backup_press_jemc.sql

# With verbose output
psql -h localhost -U postgres -d press_jemc -v -f backup_press_jemc.sql

# Restore to new database
psql -h localhost -U postgres -c "CREATE DATABASE press_jemc_restored;"
psql -h localhost -U postgres -d press_jemc_restored < backup_press_jemc.sql
```

#### From Binary Dump
```bash
# Restore from custom format
pg_restore -h localhost -U postgres -d press_jemc -v backup_press_jemc.dump

# Restore to new database
createdb -h localhost -U postgres press_jemc_restored
pg_restore -h localhost -U postgres -d press_jemc_restored backup_press_jemc.dump

# Restore with parallel jobs (faster)
pg_restore -h localhost -U postgres -j 4 -d press_jemc backup_press_jemc.dump

# List contents before restore
pg_restore -l backup_press_jemc.dump | head -20
```

### 3. Scheduled Backups (Windows Task Scheduler)

#### Create Backup Script
**File**: `C:\xampp\backup_db.bat`

```batch
@echo off
REM Daily database backup script

REM Set variables
SET PGBIN=C:\Program Files\PostgreSQL\17\bin
SET BACKUP_DIR=C:\xampp\backups\deno2
SET DB_NAME=press_jemc
SET DB_USER=postgres
SET TIMESTAMP=%DATE:~-4,4%%DATE:~-10,2%%DATE:~-7,2%_%TIME:~0,2%%TIME:~3,2%

REM Create backup directory if not exists
IF NOT EXIST "%BACKUP_DIR%" mkdir "%BACKUP_DIR%"

REM Set password
SET PGPASSWORD=Nepal@123

REM Backup database
"%PGBIN%\pg_dump.exe" -h localhost -U %DB_USER% -Fc -f "%BACKUP_DIR%\press_jemc_backup_%TIMESTAMP%.dump" %DB_NAME%

REM Keep only last 30 days of backups
FOR /F "skip=30 eol=: delims=" %%A IN ('dir /b /o-d "%BACKUP_DIR%\press_jemc_backup_*.dump"') DO DEL "%BACKUP_DIR%\%%A"

REM Log backup completion
echo Backup completed at %DATE% %TIME% >> "%BACKUP_DIR%\backup.log"
```

#### Schedule Task
```bash
# Create daily backup task at 2 AM
schtasks /create /tn "DatabaseBackup_DailyPress" ^
  /tr "C:\xampp\backup_db.bat" ^
  /sc daily /st 02:00 /ru SYSTEM

# Create weekly full backup at Sunday 1 AM
schtasks /create /tn "DatabaseBackup_WeeklyFull" ^
  /tr "C:\xampp\backup_db_full.bat" ^
  /sc weekly /d SUN /st 01:00 /ru SYSTEM

# List scheduled backup tasks
schtasks /query /tn "DatabaseBackup*"

# Delete backup task
schtasks /delete /tn "DatabaseBackup_DailyPress" /f
```

### 4. Backup Verification

```bash
# Check backup file size
ls -lh backup_press_jemc.dump

# Verify backup integrity
pg_restore -l backup_press_jemc.dump > /dev/null && echo "Backup OK" || echo "Backup CORRUPTED"

# Count tables in backup
pg_restore -l backup_press_jemc.dump | grep -c "TABLE"

# Test restore to temporary database
createdb -h localhost -U postgres press_jemc_test
pg_restore -h localhost -U postgres -d press_jemc_test backup_press_jemc.dump
dropdb -h localhost -U postgres press_jemc_test
```

---

## 📊 DATA MIGRATION

### 1. Export Data to CSV

```sql
-- Export employee data to CSV
\COPY (SELECT id, name, email, department_id FROM employee) 
TO '/tmp/employees.csv' WITH CSV HEADER;

-- Export attendance data
\COPY (SELECT * FROM attendance WHERE attendance_date_eng = CURRENT_DATE) 
TO '/tmp/attendance_today.csv' WITH CSV HEADER;

-- Export production data
\COPY (SELECT * FROM deno WHERE deno_date_eng >= '2026-01-01') 
TO '/tmp/deno_2026.csv' WITH CSV HEADER;
```

### 2. Import Data from CSV

```bash
# Using psql COPY command
psql -h localhost -U postgres -d press_jemc << EOF
\COPY employee(id, name, email, department_id) 
FROM '/tmp/employees.csv' WITH CSV HEADER;
EOF

# Using SQL COPY
psql -h localhost -U postgres -d press_jemc -c "
  COPY employee(id, name, email, department_id) 
  FROM STDIN WITH CSV HEADER;" < employees.csv
```

### 3. Data Synchronization Between Databases

```bash
# Create temporary copy of prod database
pg_dump -h prod-server -U postgres -Fc press_jemc | \
  pg_restore -h localhost -U postgres -d press_jemc_copy -v

# Compare databases for differences
pg_dump -h localhost -U postgres --schema-only press_jemc > schema1.sql
pg_dump -h prod-server -U postgres --schema-only press_jemc > schema2.sql
diff schema1.sql schema2.sql
```

---

## ⚙️ COMMON OPERATIONS

### User Management

```sql
-- List all users
SELECT * FROM users;

-- Create new user (in psql)
CREATE USER newuser WITH PASSWORD 'password123';
GRANT CONNECT ON DATABASE press_jemc TO newuser;
GRANT USAGE ON SCHEMA public TO newuser;
GRANT SELECT ON ALL TABLES IN SCHEMA public TO newuser;

-- Change password
ALTER USER postgres WITH PASSWORD 'NewPassword123';

-- Grant permissions
GRANT ALL PRIVILEGES ON DATABASE press_jemc TO newuser;

-- Revoke permissions
REVOKE ALL PRIVILEGES ON DATABASE press_jemc FROM newuser;
```

### Database Maintenance

```bash
# Analyze tables (update statistics)
psql -h localhost -U postgres -d press_jemc -c "ANALYZE;"

# Vacuum tables (remove dead rows)
psql -h localhost -U postgres -d press_jemc -c "VACUUM;"

# Full vacuum and analyze (slower, removes bloat)
psql -h localhost -U postgres -d press_jemc -c "VACUUM FULL ANALYZE;"

# Check table sizes
psql -h localhost -U postgres -d press_jemc << EOF
SELECT schemaname, tablename, 
  pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size
FROM pg_tables 
WHERE schemaname NOT IN ('pg_catalog', 'information_schema')
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC;
EOF

# Check index usage
psql -h localhost -U postgres -d press_jemc -c "
SELECT schemaname, tablename, indexname, idx_scan
FROM pg_stat_user_indexes
ORDER BY idx_scan DESC;"
```

### Query Performance

```sql
-- Enable query statistics
SET log_min_duration_statement = 1000; -- Log queries taking > 1s

-- Analyze query execution plan
EXPLAIN (ANALYZE, BUFFERS) 
SELECT * FROM attendance 
WHERE attendance_date_eng = CURRENT_DATE;

-- Find slow queries
SELECT query, calls, total_time, mean_time 
FROM pg_stat_statements 
ORDER BY mean_time DESC LIMIT 10;
```

### Data Cleanup

```sql
-- Delete old attendance data (keep last 2 years)
DELETE FROM attendance 
WHERE attendance_date_eng < CURRENT_DATE - INTERVAL '2 years';

-- Archive old vehicle logs
INSERT INTO vehicle_daily_logs_archive
SELECT * FROM vehicle_daily_logs 
WHERE log_date < CURRENT_DATE - INTERVAL '1 year';

DELETE FROM vehicle_daily_logs 
WHERE log_date < CURRENT_DATE - INTERVAL '1 year';

-- Cleanup zkteco_raw_attendance older than 6 months
DELETE FROM zkteco_raw_attendance 
WHERE punch_time < CURRENT_TIMESTAMP - INTERVAL '6 months';
```

---

## 🚀 PERFORMANCE TIPS

### 1. Index Optimization

```sql
-- Create indexes on frequently searched columns
CREATE INDEX idx_employee_id ON attendance(employee_id);
CREATE INDEX idx_attendance_date ON attendance(attendance_date_eng);
CREATE INDEX idx_deno_date ON deno(deno_date_eng);
CREATE INDEX idx_vehicle_logs_date ON vehicle_daily_logs(log_date);

-- Create composite indexes for common queries
CREATE INDEX idx_attendance_emp_date 
ON attendance(employee_id, attendance_date_eng);

-- Check existing indexes
SELECT * FROM pg_stat_user_indexes 
WHERE schemaname = 'public';

-- Remove unused indexes
DROP INDEX CONCURRENTLY idx_unused_index;
```

### 2. Query Optimization

```sql
-- BAD: Slow query with multiple joins
SELECT a.*, e.name, d.dept_name 
FROM attendance a, employee e, department d
WHERE a.employee_id = e.id 
AND e.dept_id = d.id;

-- GOOD: Optimized with explicit joins and indexes
SELECT a.id, a.attendance_date_eng, a.check_in_time,
       e.name, d.dept_name
FROM attendance a
INNER JOIN employee e ON a.employee_id = e.id
INNER JOIN department d ON e.dept_id = d.id
WHERE a.attendance_date_eng = CURRENT_DATE;

-- Use EXPLAIN to check execution plan
EXPLAIN ANALYZE SELECT ...;
```

### 3. Connection Pooling (PHP)

```php
// Increase connection pool in config/database.php
$conn = new PDO(
    "pgsql:host=$host;port=$port;dbname=$dbname",
    $user,
    $password,
    [
        PDO::ATTR_PERSISTENT => false,
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_TIMEOUT => 60
    ]
);
```

### 4. Caching Strategy

```php
// Cache frequently accessed data (fiscal years, holidays, etc.)
function getCachedFiscalYears() {
    $cache_file = '/tmp/fiscal_years.json';
    $cache_time = 86400; // 24 hours
    
    if (file_exists($cache_file) && time() - filemtime($cache_file) < $cache_time) {
        return json_decode(file_get_contents($cache_file), true);
    }
    
    // Fetch from database
    $stmt = $conn->query("SELECT * FROM fiscal_years");
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Save to cache
    file_put_contents($cache_file, json_encode($data));
    
    return $data;
}
```

---

## 🔍 TROUBLESHOOTING

### 1. Connection Issues

```bash
# Test PostgreSQL is running
pg_isready -h localhost -p 5432

# Start PostgreSQL (Windows)
net start PostgreSQL-x64-17

# Start PostgreSQL (Linux/Mac)
sudo service postgresql start
psql -U postgres -h localhost -c "SELECT 1"

# Check pg_hba.conf for authentication method
psql -U postgres -c "SHOW hba_file;"
# Typical location: C:\Program Files\PostgreSQL\17\data\pg_hba.conf
```

### 2. Database Access Denied

```bash
# Check PostgreSQL logs
tail -f "/var/log/postgresql/postgresql.log"  # Linux
# Windows: C:\Program Files\PostgreSQL\17\data\log\

# Reset PostgreSQL password
# Windows (XAMPP): Use pg_ctl to start without password
"C:\Program Files\PostgreSQL\17\bin\pg_ctl.exe" -D "C:\Program Files\PostgreSQL\17\data" stop

# Connect without password (emergency)
"C:\Program Files\PostgreSQL\17\bin\psql.exe" -h localhost -U postgres postgres

# If locked out, reinstall PostgreSQL or reset password via
ALTER ROLE postgres WITH PASSWORD 'newpassword';
```

### 3. Disk Space Issues

```bash
# Check PostgreSQL data directory size
du -sh "/var/lib/postgresql/17/main"  # Linux
# Windows: C:\Program Files\PostgreSQL\17\data

# Find largest tables
psql -h localhost -U postgres -d press_jemc << EOF
SELECT 
  schemaname, tablename,
  pg_size_pretty(pg_total_relation_size(schemaname||'.'||tablename)) AS size
FROM pg_tables
WHERE schemaname = 'public'
ORDER BY pg_total_relation_size(schemaname||'.'||tablename) DESC
LIMIT 10;
EOF

# Cleanup old data
DELETE FROM zkteco_raw_attendance WHERE punch_time < NOW() - INTERVAL '1 year';
VACUUM FULL ANALYZE;
```

### 4. Slow Queries

```bash
# Enable slow query logging
psql -h localhost -U postgres -d press_jemc << EOF
ALTER SYSTEM SET log_min_duration_statement = 1000;
ALTER SYSTEM SET log_statement = 'all';
SELECT pg_reload_conf();
EOF

# Check slow query log
tail -f "/var/log/postgresql/postgresql-17-main.log"  # Linux

# Kill slow running queries
psql -h localhost -U postgres -d press_jemc << EOF
SELECT pid, usename, application_name, state, query 
FROM pg_stat_activity 
WHERE state = 'active' AND query_start < NOW() - INTERVAL '10 minutes';

-- Kill specific query
SELECT pg_terminate_backend(12345);  -- Replace with actual PID
EOF
```

### 5. Corruption Detection

```bash
# Check database integrity
psql -h localhost -U postgres -d press_jemc -c "REINDEX DATABASE press_jemc;"

# Verify all tables
psql -h localhost -U postgres -d press_jemc << EOF
DO $$
DECLARE r RECORD;
BEGIN
  FOR r IN SELECT tablename FROM pg_tables WHERE schemaname = 'public'
  LOOP
    EXECUTE 'ANALYZE ' || quote_ident(r.tablename);
  END LOOP;
END $$;
EOF

# Restore from backup if corrupted
dropdb -h localhost -U postgres press_jemc
createdb -h localhost -U postgres press_jemc
pg_restore -h localhost -U postgres -d press_jemc backup_press_jemc.dump
```

---

## 📋 CHECKLIST: PRODUCTION READINESS

- [ ] Database created and accessible
- [ ] 87 tables verified with `\dt` command
- [ ] Initial backup taken (`20260603_backup.sql`)
- [ ] Daily backup scheduled via Task Scheduler
- [ ] Database user password changed from default
- [ ] PostgreSQL running as Windows Service (not manual)
- [ ] pg_hba.conf configured for network access if needed
- [ ] Fire wall allows port 5432 (if remote)
- [ ] Performance baseline established
- [ ] Monitoring/alerting configured
- [ ] Disaster recovery plan documented
- [ ] Test recovery from backup completed

---

## 📞 QUICK REFERENCE

| Task | Command |
|------|---------|
| Connect to DB | `psql -h localhost -U postgres -d press_jemc` |
| Backup | `pg_dump -h localhost -U postgres -Fc -d press_jemc > backup.dump` |
| Restore | `pg_restore -h localhost -U postgres -d press_jemc backup.dump` |
| List tables | `psql -c "\dt" -d press_jemc` |
| Check DB size | `psql -c "\l+" press_jemc` |
| Analyze tables | `psql -d press_jemc -c "ANALYZE;"` |
| Kill slow query | `psql -d press_jemc -c "SELECT pg_terminate_backend(PID);"` |

---

**Last Updated**: June 3, 2026  
**PostgreSQL Version**: 15/17  
**Database**: press_jemc  
**Status**: ✅ Production

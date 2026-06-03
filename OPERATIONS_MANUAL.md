# OPERATIONAL PROCEDURES GUIDE
## Janak Education Materials Center (JEMC) - Press Management System (Deno2)

---

## 📋 TABLE OF CONTENTS
1. [Daily Operations](#daily-operations)
2. [Module Workflows](#module-workflows)
3. [Attendance Management](#attendance-management)
4. [Production Tracking](#production-tracking)
5. [Vehicle Management](#vehicle-management)
6. [HR & Payroll](#hr--payroll)
7. [Report Generation](#report-generation)
8. [Error Handling](#error-handling)

---

## 📅 DAILY OPERATIONS

### Morning Startup (7:00 AM)

1. **Verify PostgreSQL is Running**
   ```bash
   # Check if database is accessible
   psql -h localhost -U postgres -d press_jemc -c "SELECT NOW();"
   # Should return current date/time
   ```

2. **Check ZKTeco Attendance Pull**
   - Go to: `http://localhost/deno2/attendance_device/zkteco_pull_history.php`
   - Verify morning pull (07:35 AM) completed successfully
   - Check for any error messages

3. **Review Dashboard**
   - Go to: `http://localhost/deno2/` (dashboard)
   - Check today's production status
   - Check pending job orders
   - Review attendance statistics

4. **Check Database Backup**
   - Verify previous night's backup completed
   - Location: `C:\xampp\backups\deno2\`
   - File format: `press_jemc_backup_YYYYMMDD_HHMMSS.dump`

### End of Day Shutdown (6:00 PM)

1. **Final Attendance Pull** (Evening pull at 05:25 PM)
   - Status should show in ZKTeco history

2. **Production Entry**
   - Ensure all Deno (production) data entered for the day
   - All D2M verifications completed

3. **Vehicle Logs**
   - Check all vehicle trips logged
   - Fuel consumption recorded

4. **System Backup**
   - Automatic backup should run at 2:00 AM (configured in Task Scheduler)
   - No manual action needed unless backup failed

---

## 🔄 MODULE WORKFLOWS

### WORKFLOW 1: JOB ORDER CREATION & COMPLETION

**Timeline**: Job Order → Printing → QC → Dispatch

```
Step 1: CREATE JOB ORDER
├─ Path: /jobticket/
├─ Data:
│  ├─ Select Book (from catalog)
│  ├─ Select Forma (printing plate)
│  ├─ Enter Quantity (in pieces)
│  ├─ Assign Machine
│  └─ Set Priority
└─ Output: Job Ticket Code (e.g., 2081-JT001)

Step 2: ASSIGN TO PRODUCTION
├─ Job automatically appears in Deno tracking
├─ Operatives begin production
└─ Track through CTP/FCTP if applicable

Step 3: RECORD PRODUCTION (Deno)
├─ Path: /deno/ (or auto from CTP)
├─ Daily Record:
│  ├─ Book/Forma produced
│  ├─ Quantity in pieces
│  ├─ Total weight
│  └─ Date (Nepali calendar)
└─ Status: Auto-saved daily

Step 4: VERIFICATION (D2M)
├─ Path: /d2m/
├─ Workflow:
│  ├─ DRAFT → Review
│  ├─ CHECKED → Supervisor approval
│  ├─ VERIFIED → Final authorization
│  └─ CLOSED → Archive
└─ Output: Verified production record

Step 5: DISPATCH & ARCHIVE
├─ Mark job complete in job_ticket
├─ Update Book Packing status
└─ Generate report
```

**Responsible**: Production Manager

---

### WORKFLOW 2: EMPLOYEE ATTENDANCE

**Automated via ZKTeco** (Minimal manual intervention)

```
Step 1: AUTOMATIC PULL (5 times daily)
├─ 07:35 AM: Morning check-in
├─ 10:45 AM: Mid-morning update
├─ 01:25 PM: After-lunch check-in
├─ 05:25 PM: Evening check-out
└─ 07:15 PM: Night shift/OT capture

Step 2: DATA PROCESSING
├─ Raw punch data → zkteco_raw_attendance
├─ Validate against employee mapping
├─ Match to shifts
└─ Calculate working hours

Step 3: FINAL RECORD
├─ Processed data → attendance table
├─ Auto-calculate:
│  ├─ Working hours (8.0 hour standard)
│  ├─ Overtime hours (if > 8.0)
│  ├─ Late arrival minutes
│  └─ Day type flags (holiday, weekly-off)
└─ Status: Recorded in system

Step 4: MONTHLY SUMMARY
├─ System auto-generates: attendance_monthly_summary
├─ Includes:
│  ├─ Present days
│  ├─ Absent days
│  ├─ Leave days
│  ├─ Total working hours
│  └─ Total OT hours
└─ Ready for payroll

Step 5: MANUAL ADJUSTMENTS (If needed)
├─ Path: /attendance/ → Edit Attendance
├─ Cases:
│  ├─ Device missed punch → Manual entry
│  ├─ Leave approval → Change status
│  ├─ Excused absence → Record reason
│  └─ Shift change → Update manually
└─ HR Manager: Only authorized user
```

**Responsible**: HR Manager, System Admin (ZKTeco config)

---

### WORKFLOW 3: VEHICLE MAINTENANCE

**Timeline**: Daily → Monthly Summary → Budget Planning

```
Step 1: DAILY VEHICLE USE
├─ Path: /vehicle/daily_log.php
├─ Record:
│  ├─ Driver name
│  ├─ Vehicle registration
│  ├─ Trip purpose
│  ├─ Starting/ending KM
│  ├─ Fuel consumed
│  └─ Maintenance issues noted
└─ Time: At end of each trip

Step 2: FUEL TRACKING
├─ Path: /vehicle/fuel_coupons.php
├─ Record:
│  ├─ Fuel coupon number
│  ├─ Amount (Liters)
│  ├─ Cost
│  ├─ Driver receiving
│  └─ Date
└─ Purpose: Budget control & theft prevention

Step 3: MAINTENANCE SCHEDULING
├─ Path: /vehicle/vehicle_maintenance.php
├─ Record:
│  ├─ Maintenance type (Servicing, Repair, etc.)
│  ├─ Cost
│  ├─ Parts replaced
│  ├─ Date
│  └─ Mechanic name
└─ Triggers: Based on KM or time interval

Step 4: MONTHLY SUMMARY
├─ Auto-generated: monthly_vehicle_summary
├─ Shows:
│  ├─ Total KM driven
│  ├─ Total fuel consumed
│  ├─ Total fuel cost
│  ├─ Total maintenance cost
│  └─ Efficiency metrics (KM per Liter)
└─ Use: Budget forecasting & efficiency analysis

Step 5: REPORTING
├─ Path: /vehicle/vehicle_reports_nepali.php
├─ Generate:
│  ├─ Daily fuel log
│  ├─ Maintenance schedule
│  ├─ Driver efficiency report
│  ├─ Fleet health report
│  └─ Cost analysis
└─ Export: PDF or CSV
```

**Responsible**: Fleet Manager, Drivers

---

## 👥 ATTENDANCE MANAGEMENT

### Manual Attendance Entry (If ZKTeco Fails)

```
Path: /attendance/ → Edit Attendance

Steps:
1. Select Employee
2. Select Date
3. Set Status:
   ├─ Present (Full day)
   ├─ Absent
   ├─ Half day
   ├─ Leave (Approved)
   └─ Holiday
4. Enter Times:
   ├─ Check-in time (HH:MM)
   ├─ Check-out time (HH:MM)
   └─ Break hours (if any)
5. System auto-calculates:
   ├─ Working hours
   ├─ Overtime
   └─ Late arrival
6. Submit
```

### Late Attendance Entry

```
If employee arrives after punch-in time:

1. Employee punches in
2. HR Manager logs late arrival reason:
   ├─ Traffic
   ├─ Medical
   ├─ Excused
   └─ Unexcused

3. System calculates:
   ├─ Late minutes
   └─ Possible salary deduction (per policy)

4. Report in: Monthly attendance summary
```

### Leave Processing

```
Path: /hr/ → Leave Management

Workflow:
1. Employee or HR creates leave request:
   ├─ Leave type (Casual, Sick, Annual)
   ├─ Start date
   ├─ End date
   └─ Reason

2. HR Manager approves/rejects

3. Approved leaves → Attendance marked as "Leave"

4. System calculates:
   ├─ Remaining leave balance
   └─ Carry-forward if applicable
```

---

## 📊 PRODUCTION TRACKING

### DENO (Daily Production)

```
Path: /deno/

Daily Workflow:
1. Production team completes printing job
2. Quantity recorded:
   ├─ Pieces (unit count)
   ├─ Weight (if applicable)
   ├─ Book/Forma produced
   └─ Date (auto = today, Nepali date)

3. Data saved to deno table
4. Monthly total auto-calculated
5. Used for:
   ├─ Payroll (productivity bonus)
   ├─ Performance analysis
   ├─ Forecasting
   └─ Budget tracking
```

### D2M (Verification Workflow)

```
Path: /d2m/

Four-Stage Process:

STAGE 1 - DRAFT
├─ Created: Usually auto from Deno
├─ Status: Initial record
└─ Editable: Yes

STAGE 2 - CHECKED
├─ Action: Supervisor reviews
├─ Fields: Verified by, Checked timestamp
├─ Status: Verified by supervisor
└─ Editable: No (read-only after check)

STAGE 3 - VERIFIED
├─ Action: Manager final approval
├─ Fields: Verified by, Verified timestamp
├─ Status: Final approval given
└─ Editable: No

STAGE 4 - CLOSED
├─ Action: Archive for record
├─ Status: Finalized, cannot edit
└─ Editable: No

Cancellation:
├─ Only from DRAFT or CHECKED stage
├─ Records cancelled status
└─ Original data retained (audit trail)
```

### CTP/FCTP Tracking

```
Path: /ctp/ and /formaprinting/

CTP (Computer-to-Plate):
├─ Machine operation tracking
├─ Input: Digital design files
├─ Output: Printing plates (formas)
├─ Records: Plate quality, issues
└─ Data used: For Forma inventory

FCTP (Forma CTP):
├─ Specialized forma production
├─ Job creation with book/forma selection
├─ Imposition template assignment
├─ Output: Ready-to-print formas
└─ Integration: Links to forma_printing
```

---

## 🚗 VEHICLE MANAGEMENT

### Daily Vehicle Operations

```
MORNING (Before trip):
├─ Driver checks vehicle condition
├─ Records starting odometer reading
├─ Notes any damage

DURING TRIP:
├─ Driver records trip purpose
└─ Notes any issues

END OF TRIP:
├─ Path: /vehicle/daily_log.php
├─ Record:
│  ├─ Ending odometer reading
│  ├─ KM traveled = ending - starting
│  ├─ Fuel consumed (liters)
│  ├─ Trip purpose (if changed)
│  └─ Any maintenance issues found
└─ Status: Logged in daily_logs table

FUEL TRACKING:
├─ Path: /vehicle/fuel_coupons.php
├─ When: Driver fills fuel tank
├─ Record:
│  ├─ Coupon number
│  ├─ Liters purchased
│  ├─ Cost per liter
│  ├─ Total cost
│  └─ Date/Time
└─ Purpose: Inventory control & audit

MAINTENANCE ISSUES:
├─ If issue found during trip
├─ Path: /vehicle/vehicle_maintenance.php
├─ Schedule maintenance ASAP
├─ Record:
│  ├─ Issue description
│  ├─ Parts needed
│  ├─ Mechanic assigned
│  ├─ Estimated cost
│  └─ Completion status
└─ Track: Until resolved
```

### Monthly Vehicle Summary

```
Auto-Generated Reports:
├─ Total KM driven (all vehicles)
├─ Total fuel used (liters)
├─ Total fuel cost
├─ Maintenance cost by type
├─ Cost per KM
├─ Efficiency metrics
├─ Vehicle-wise breakdown
└─ Driver-wise breakdown

Located: /vehicle/monthly_summary.php
Used for: Budget planning, efficiency analysis
```

---

## 👔 HR & PAYROLL

### Employee Management

```
Path: /hr/

Master Data:
├─ Employee Information:
│  ├─ Name, ID, DOB
│  ├─ Department
│  ├─ Designation
│  ├─ Salary grade
│  └─ Contact info
├─ Educational Details
├─ Family Information
└─ Document Storage

Updates:
├─ Annual review (designation changes)
├─ New employee onboarding
├─ Employee departure
└─ Salary revisions
```

### Payroll Input Generation

```
Process:
1. Month-end: Generate payroll report
2. Report includes:
   ├─ Attendance summary (from attendance_monthly_summary)
   ├─ Present days
   ├─ Absent days
   ├─ Leave days
   ├─ Working hours
   ├─ Overtime hours
   └─ Late days
3. Export: CSV or PDF
4. Send to: Finance/Payroll department
5. Payroll process: Calculate salary
```

---

## 📈 REPORT GENERATION

### Standard Reports

#### Attendance Reports
```
Path: /report/ and /attendance/

Available Reports:
1. Daily Attendance Report
   ├─ All employees present/absent today
   └─ Export: PDF, Excel

2. Monthly Attendance Summary
   ├─ All attendance stats for month
   ├─ Used for: Payroll
   └─ Auto-generated from attendance_monthly_summary

3. Employee Attendance Statistics
   ├─ Individual performance
   ├─ Late arrival analysis
   └─ Leave balance

4. OT (Overtime) Report
   ├─ Daily OT by employee
   ├─ Total OT hours by month
   └─ OT cost analysis
```

#### Production Reports
```
Path: /denoreports/

Available Reports:
1. Daily Deno Report
   ├─ Production quantities
   ├─ By book/forma
   └─ Total units

2. Monthly Production Summary
   ├─ Total units produced (Nepali month)
   ├─ By machine
   └─ Trend analysis

3. D2M Verification Status
   ├─ Draft records
   ├─ Checked records
   ├─ Verified records
   └─ Pending verifications
```

#### Vehicle Reports
```
Path: /vehicle/vehicle_reports_nepali.php

Available Reports:
1. Daily Vehicle Log
   ├─ All trips today
   ├─ KM, fuel, purpose
   └─ By vehicle & driver

2. Monthly Vehicle Summary
   ├─ Total KM, fuel, cost
   ├─ Maintenance summary
   └─ Efficiency metrics

3. Driver Performance
   ├─ KM per liter
   ├─ Fuel consumption vs. standard
   └─ Maintenance history

4. Maintenance Schedule
   ├─ Due maintenance
   ├─ Overdue items
   └─ Next 30 days schedule

5. Fuel Price Analysis
   ├─ Historical fuel prices
   ├─ Cost trend
   └─ Budget vs. actual
```

---

## ⚠️ ERROR HANDLING

### Common Issues & Solutions

#### 1. ZKTeco Pull Failed

**Error**: "Connection to device failed"

**Solutions**:
1. Check device is powered on and online
   ```bash
   ping <device_ip>
   ```

2. Verify network connectivity
   ```bash
   ipconfig (Windows) or ifconfig (Linux)
   ```

3. Check employee-device mapping
   ```
   Path: /attendance_device/zkteco_employee_mapping.php
   ```

4. Review pull logs
   ```
   Path: /attendance_device/logs/zkteco/
   ```

5. Manual pull test
   ```bash
   cd C:\xampp\htdocs\deno2\attendance_device
   php zkteco_puller.php --schedule=morning
   ```

---

#### 2. Database Connection Error

**Error**: "FATAL: password authentication failed"

**Solutions**:
1. Verify PostgreSQL is running
   ```bash
   pg_isready -h localhost -p 5432
   ```

2. Check credentials in config/database.php
   ```
   Host: localhost
   Port: 5432
   User: postgres
   Pass: Nepal@123
   ```

3. If password changed, update config file

4. Restart Apache:
   ```bash
   net stop Apache2.4
   net start Apache2.4
   ```

---

#### 3. Attendance Data Missing

**Error**: "No attendance records for employee"

**Possible Causes**:
1. ZKTeco pull failed → Check /logs/zkteco/
2. Employee not mapped to device → Add mapping
3. Device offline → Check device status
4. Manual entry needed → Use /attendance/ edit

**Resolution**:
```
1. Check device status: /attendance_device/zkteco_device_management.php
2. Verify mapping: /attendance_device/zkteco_employee_mapping.php
3. Check pull history: /attendance_device/zkteco_pull_history.php
4. If failed, manually add: /attendance/ → Edit Attendance
```

---

#### 4. Production Data Not Saving

**Error**: "D2M verification failed" or "Deno entry not saved"

**Solutions**:
1. Verify database connection
2. Check for validation errors in form
3. Ensure required fields filled:
   - Deno: Quantity, Date, Book/Forma
   - D2M: Status, Verified by (if checking)
4. Check browser console for JavaScript errors
5. Try different browser
6. Clear browser cache: Ctrl+Shift+Delete

---

#### 5. Report Generation Timeout

**Error**: "Maximum execution time exceeded" or "504 Gateway Timeout"

**Solutions**:
1. Reduce date range (select fewer months)
2. Increase PHP timeout in xampp/php/php.ini
   ```ini
   max_execution_time = 300  (change from default)
   ```
3. Try smaller report scope
4. Check database performance
   ```sql
   ANALYZE; -- Optimize table statistics
   ```

---

### Emergency Procedures

#### Database Backup Failed

```
Action:
1. Check disk space: dir C:\ | find "free"
2. Check backup directory exists: C:\xampp\backups\deno2\
3. Run manual backup:
   pg_dump -h localhost -U postgres -Fc -d press_jemc > emergency_backup.dump
4. Verify backup: pg_restore -l emergency_backup.dump | wc -l
```

#### System Crash

```
Action:
1. Restart PostgreSQL:
   net restart PostgreSQL-x64-17
2. Restart Apache:
   net stop Apache2.4
   net start Apache2.4
3. Verify connectivity:
   psql -h localhost -U postgres -d press_jemc -c "SELECT 1;"
4. Check for file corruption:
   pg_dump -h localhost -U postgres -Fc -d press_jemc > recovery_backup.dump
```

#### Data Corruption Detected

```
Action:
1. Stop all application access
2. Restore from latest known-good backup:
   dropdb -h localhost -U postgres press_jemc
   createdb -h localhost -U postgres press_jemc
   pg_restore -h localhost -U postgres -d press_jemc latest_backup.dump
3. Verify data integrity:
   psql -c "REINDEX DATABASE press_jemc;"
4. Test functionality in staging before going live
```

---

## 📞 SUPPORT CONTACTS

| Role | Contact | Responsibility |
|------|---------|-----------------|
| System Admin | - | Database, Server, ZKTeco |
| HR Manager | - | Attendance, Employees, Payroll |
| Production Manager | - | Job orders, Deno, D2M |
| Fleet Manager | - | Vehicles, Maintenance, Fuel |

---

## 📋 MONTHLY CHECKLIST

### Beginning of Month
- [ ] Verify previous month's data complete
- [ ] Approve pending leave applications
- [ ] Review monthly summaries (attendance, production, vehicle)
- [ ] Check vehicle maintenance schedule
- [ ] Plan upcoming production
- [ ] Verify fuel coupon allocations

### End of Month
- [ ] Finalize attendance (lock previous month)
- [ ] Approve D2M records
- [ ] Generate payroll report
- [ ] Close production records (Deno)
- [ ] Monthly vehicle summary review
- [ ] Database backup (already automatic)
- [ ] Review system logs for errors
- [ ] Plan maintenance activities for next month

### Quarterly
- [ ] Full system audit
- [ ] Performance review
- [ ] Budget variance analysis
- [ ] Database maintenance (VACUUM, ANALYZE)
- [ ] Security review

### Annually
- [ ] Complete system backup archive
- [ ] Disaster recovery test
- [ ] Capacity planning
- [ ] Training update for new features

---

**Last Updated**: June 3, 2026  
**System**: Deno2 (Press Management System)  
**Organization**: Janak Education Materials Center (JEMC)  
**Database**: press_jemc on PostgreSQL 15/17  
**Status**: ✅ Operational

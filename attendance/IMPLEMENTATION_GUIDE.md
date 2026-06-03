# 🚀 ATTENDANCE SYSTEM - QUICK START IMPLEMENTATION GUIDE

## 📦 What You're Getting

A complete Nepali calendar-based attendance management system with:

### ✅ **7 Complete Files**
1. `attendance_schema.sql` - Complete database structure
2. `attendance_entry.php` - Data entry interface (single & bulk)
3. `daily_attendance_report.php` - Daily attendance report
4. `monthly_attendance_report.php` - Monthly summary for payroll
5. `holiday_management.php` - Holiday management interface
6. `check_holiday.php` - AJAX helper for holiday detection
7. `README.md` - Complete documentation

### 🎯 **Key Features**

#### 📊 **Attendance Tracking**
- ✅ Single employee entry
- ✅ Bulk employee entry (for holidays/weekly offs)
- ✅ Nepali calendar (Bikram Sambat) support
- ✅ Automatic working hours calculation
- ✅ Automatic OT (overtime) calculation
- ✅ Late arrival tracking
- ✅ Break time management

#### 📈 **Overtime (OT) System**
- **Weekday OT**: 1.5x rate after 8 hours
- **Weekend OT**: 2.0x rate (all hours)
- **Holiday OT**: 2.5x rate (all hours)
- Automatic calculation via database triggers
- Approval workflow support

#### 📅 **Holiday Management**
- Multiple holiday types (Public, Festival, Optional, etc.)
- Nepali calendar based
- Color-coded display
- Paid/unpaid tracking
- Fiscal year management

#### 📋 **Comprehensive Reports**
- **Daily Report**: Day-wise attendance with filtering
- **Monthly Summary**: Payroll-ready data with:
  - Present days
  - Absent days
  - Leave days
  - OT hours
  - **Payable days** (for salary calculation)
- Export to Excel
- Print-friendly layouts
- Filtering by department, designation, level

## 🔧 INSTALLATION STEPS

### Step 1: Database Setup (5 minutes)

```bash
# Connect to PostgreSQL
psql -U postgres -d your_database_name

# Run the schema
\i attendance_schema.sql

# Verify installation
\dt
```

**Expected Output:**
- ✅ 10 new tables created
- ✅ 7 views created
- ✅ 5 functions created
- ✅ Sample holiday data inserted

### Step 2: Deploy PHP Files (2 minutes)

```bash
# Copy to your web directory
cp attendance_*.php /var/www/html/your-app/attendance/
cp holiday_management.php /var/www/html/your-app/attendance/
cp check_holiday.php /var/www/html/your-app/attendance/
```

### Step 3: Configure File Paths (3 minutes)

Edit each PHP file and update these paths:

```php
// At the top of each file, update:
require_once $_SERVER['DOCUMENT_ROOT'] . '/YOUR-APP/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/YOUR-APP/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/YOUR-APP/includes/header.php';

// At the bottom:
require_once $_SERVER['DOCUMENT_ROOT'] . '/YOUR-APP/includes/footer.php';
```

### Step 4: Test the System (5 minutes)

1. **Test Holiday Management:**
   - Navigate to `holiday_management.php`
   - Add a test holiday
   - Verify it appears in the calendar

2. **Test Attendance Entry:**
   - Navigate to `attendance_entry.php`
   - Mark attendance for one employee
   - Check working hours calculation
   - Verify OT calculation

3. **Test Reports:**
   - Navigate to `daily_attendance_report.php`
   - View today's attendance
   - Navigate to `monthly_attendance_report.php`
   - Check monthly summary

## 📊 DATABASE STRUCTURE OVERVIEW

### Core Tables Relationships

```
employee (existing)
    ↓
attendance (daily records)
    ↓
attendance_monthly_summary (aggregated)
    ↓
payroll integration

holidays ← holiday_types
shifts (existing)
attendance_status (lookup)
ot_rules (calculation rules)
```

### Critical Auto-Calculations

1. **Working Hours**
   ```
   Working Hours = (Check-out - Check-in) - Break Hours
   ```

2. **OT Hours**
   ```
   Weekday: max(0, Working Hours - 8)
   Weekend/Holiday: Working Hours
   ```

3. **Payable Days**
   ```
   Payable Days = Present Days + (Half Days × 0.5) + Leave Days
   ```

## 🎯 USAGE SCENARIOS

### Scenario 1: Daily Attendance Marking

**Morning Routine:**
1. Open `attendance_entry.php`
2. Use **Single Entry** tab
3. Select employee
4. Enter today's date (auto-filled)
5. Select status "Present"
6. Enter check-in time (e.g., 09:15)
7. Click "Mark Attendance"

**Evening Update:**
1. Edit the same record
2. Add check-out time (e.g., 18:30)
3. System auto-calculates:
   - Working hours: 8.25 hrs
   - OT hours: 0.25 hrs

### Scenario 2: Marking Weekly Off for All

1. Open `attendance_entry.php`
2. Switch to **Bulk Entry** tab
3. Select Saturday's date
4. Select status "Weekly Off"
5. Click "Select All Employees"
6. Click "Mark Bulk Attendance"

### Scenario 3: Monthly Payroll Processing

1. At month-end, open `monthly_attendance_report.php`
2. Select the month (e.g., 2082.10)
3. Export to Excel
4. Use "Payable Days" column for salary calculation
5. Formula: `Salary = (Monthly Salary / 30) × Payable Days`

### Scenario 4: Public Holiday Setup

1. Before fiscal year starts
2. Open `holiday_management.php`
3. Add all public holidays for the year
4. System will auto-detect holidays during attendance entry

## 🔍 KEY FEATURES EXPLAINED

### 1. Automatic Time Calculations

The system uses PostgreSQL triggers:

```sql
-- This happens automatically on INSERT/UPDATE
NEW.actual_working_hours := calculate_working_hours(
    check_in, check_out, break_hours
);

NEW.ot_hours := calculate_ot_hours(
    actual_working_hours, 8.0, is_holiday, is_weekly_off
);
```

### 2. Monthly Summary Auto-Update

After each attendance entry:

```sql
SELECT update_monthly_summary(employee_id, '2082.10');
```

This updates all monthly statistics automatically.

### 3. Holiday Detection

When entering a date:
- System checks `holidays` table
- Shows warning if holiday exists
- Auto-fills status as "Public Holiday"

### 4. Late Arrival Tracking

System can track:
- Expected shift start time (from shifts table)
- Actual check-in time
- Late minutes = check-in - shift start

## 📅 NEPALI CALENDAR GUIDE

### Date Format: YYYY.MM.DD

Examples:
- `2082.01.01` = Baishakh 1, 2082 (Nepali New Year)
- `2082.10.15` = Magh 15, 2082
- `2082.03.32` = Ashadh 32, 2082 (last day of Ashadh)

### Month Numbers

| No  | Nepali   | English Approx |
|-----|----------|----------------|
| 01  | Baishakh | Apr-May        |
| 02  | Jestha   | May-Jun        |
| 03  | Ashadh   | Jun-Jul        |
| 04  | Shrawan  | Jul-Aug        |
| 05  | Bhadra   | Aug-Sep        |
| 06  | Ashwin   | Sep-Oct        |
| 07  | Kartik   | Oct-Nov        |
| 08  | Mangsir  | Nov-Dec        |
| 09  | Poush    | Dec-Jan        |
| 10  | Magh     | Jan-Feb        |
| 11  | Falgun   | Feb-Mar        |
| 12  | Chaitra  | Mar-Apr        |

### Fiscal Year

Nepali fiscal year = Shrawan to Ashadh
- FY 2082 = 2082.04.01 to 2083.03.32

## 🎨 CUSTOMIZATION OPTIONS

### 1. Modify OT Rates

Edit `ot_rules` table:

```sql
UPDATE ot_rules 
SET ot_rate = 2.0 
WHERE day_type = 'WEEKDAY';
```

### 2. Add New Attendance Status

```sql
INSERT INTO attendance_status 
(status_code, status_name, is_present, affects_salary, color_code)
VALUES 
('TR', 'Training', true, true, '#9C27B0');
```

### 3. Customize Working Hours

```sql
-- Change standard hours from 8 to 9
UPDATE ot_rules 
SET min_hours_for_ot = 9.0 
WHERE day_type = 'WEEKDAY';
```

### 4. Add New Holiday Type

```sql
INSERT INTO holiday_types 
(type_name, description, is_paid, color_code)
VALUES 
('Company Anniversary', 'Special company occasions', true, '#FF5722');
```

## 🔐 SECURITY CHECKLIST

- ✅ All forms use POST for data submission
- ✅ Prepared statements prevent SQL injection
- ✅ User authentication required (via auth.php)
- ✅ Role-based access control ready
- ✅ Audit trail (created_by, updated_by)
- ✅ Transaction management for data consistency

## 📊 REPORTING CAPABILITIES

### Daily Report Features
- Filter by date, department, designation, level
- Summary cards showing key metrics
- Export to Excel
- Print-ready format
- Pagination for large datasets

### Monthly Report Features
- Department-wise summary
- Payable days calculation
- OT hours tracking
- Lock months to prevent changes
- Export for payroll software

## ❓ FAQ

**Q: Can I modify attendance after it's marked?**
A: Yes, use the Edit button in the reports. But if the month is locked, you need to unlock it first.

**Q: How is OT calculated on holidays?**
A: All working hours on holidays count as OT at 2.5x rate (configurable).

**Q: What if employee forgets to check-out?**
A: You can manually add check-out time by editing the record. System will recalculate hours.

**Q: Can I have different OT rates for different employees?**
A: Currently, OT rates are based on day type. You'd need to customize the calculation function for per-employee rates.

**Q: How do I handle night shifts?**
A: System supports shifts. If check-out < check-in, it assumes overnight shift and calculates accordingly.

## 🆘 TROUBLESHOOTING

### Problem: "Function does not exist"
**Solution:**
```sql
-- Re-run the schema file
\i attendance_schema.sql
```

### Problem: Working hours showing 0
**Check:**
1. Both check-in and check-out are filled
2. Times are in 24-hour format
3. Check-out is after check-in

### Problem: OT not calculating
**Check:**
1. Working hours > 8 for weekdays
2. `ot_rules` table has active rules
3. Trigger is enabled on attendance table

### Problem: Monthly summary not updating
**Run manually:**
```sql
SELECT update_monthly_summary(employee_id, '2082.10');
```

## 📞 NEXT STEPS

1. ✅ Install and test the system
2. 📅 Add holidays for fiscal year
3. 👥 Import or add employees
4. 📊 Start marking daily attendance
5. 📈 Review monthly reports
6. 💰 Integrate with payroll

## 🎉 SUCCESS METRICS

After implementation, you should be able to:
- ✅ Mark attendance in < 30 seconds per employee
- ✅ Generate daily reports in < 5 seconds
- ✅ Get month-end payroll data instantly
- ✅ Track OT hours automatically
- ✅ Manage 100+ employees efficiently

---

**Need Help?**
Refer to README.md for detailed documentation.

**Version:** 1.0
**Created:** February 2026
**Compatible with:** PostgreSQL 12+, PHP 7.4+

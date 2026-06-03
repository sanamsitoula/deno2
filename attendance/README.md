# Attendance Management System - Nepalese HR System

## 📋 Overview

This is a comprehensive attendance management system designed specifically for Nepalese organizations, featuring:
- **Nepali Calendar Support** - Full integration with Bikram Sambat (BS) calendar
- **OT (Overtime) Calculation** - Automatic calculation based on working hours and day type
- **Holiday Management** - Comprehensive public and festival holiday tracking
- **Multi-level Reporting** - Daily, monthly, and summary reports
- **Payroll Integration** - Ready-to-use data for salary processing

## 🏗️ Database Structure

### Core Tables

#### 1. `attendance`
Main table tracking daily employee attendance.

**Key Fields:**
- `employee_id` - Reference to employee table
- `attendance_date_nep` - Date in Nepali calendar (YYYY.MM.DD)
- `attendance_date_eng` - Date in English calendar
- `status_id` - Attendance status (Present, Absent, Leave, etc.)
- `check_in_time` / `check_out_time` - Time tracking
- `actual_working_hours` - Auto-calculated working hours
- `ot_hours` - Overtime hours (auto-calculated)
- `late_arrival_minutes` - Late coming tracking
- `is_holiday` / `is_weekly_off` - Day type flags

**Automatic Calculations:**
- Working hours = (Check-out - Check-in) - Break hours
- OT hours calculated based on day type and standard hours (8.0)

#### 2. `attendance_monthly_summary`
Monthly aggregated data for payroll processing.

**Key Fields:**
- `present_days`, `absent_days`, `half_days`, `leave_days`
- `weekly_offs`, `public_holidays`
- `total_working_hours`, `total_ot_hours`
- `lwp_days` - Leave Without Pay
- `payable_days` - Final days eligible for salary

**Update Mechanism:**
- Automatically updated via `update_monthly_summary()` function
- Called after each attendance entry/update
- Can be locked for finalized months

#### 3. `holidays`
Stores all holiday information.

**Key Fields:**
- `holiday_date_nep` / `holiday_date_eng`
- `holiday_name` - Name of the holiday
- `holiday_type_id` - Type (Public, Festival, Optional, etc.)
- `fiscal_year` - Nepali fiscal year
- `is_active` - Enable/disable holidays

#### 4. `attendance_status`
Predefined attendance status codes.

**Default Statuses:**
- `P` - Present
- `A` - Absent
- `HD` - Half Day
- `L` - Leave
- `WO` - Weekly Off
- `PH` - Public Holiday
- `CL` - Casual Leave
- `SL` - Sick Leave
- `PL` - Paid Leave
- `LWP` - Leave Without Pay
- `CO` - Compensatory Off

## 📁 File Structure

```
attendance_system/
├── attendance_schema.sql          # Complete database schema
├── attendance_entry.php           # Data entry form (single & bulk)
├── daily_attendance_report.php    # Daily attendance report
├── monthly_attendance_report.php  # Monthly summary report
├── holiday_management.php         # Holiday CRUD interface
├── check_holiday.php              # AJAX holiday checker
└── README.md                      # This file
```

## 🚀 Installation

### Step 1: Database Setup

```sql
-- Run the schema file
psql -U postgres -d your_database -f attendance_schema.sql

-- Verify tables
\dt
```

### Step 2: Configure Database Connection

Update the database connection in your existing `config/database.php`:

```php
$conn = new PDO(
    "pgsql:host=localhost;dbname=your_database",
    "postgres",
    "your_password"
);
```

### Step 3: Deploy PHP Files

Copy all PHP files to your web directory:
```bash
cp *.php /path/to/your/webroot/attendance/
```

### Step 4: Update Include Paths

Ensure all files have correct paths to:
- `config/database.php`
- `config/auth.php`
- `includes/header.php`
- `includes/footer.php`

## 📊 Features in Detail

### 1. Attendance Entry System

**Single Entry Mode:**
- Select employee from dropdown
- Choose date (Nepali & English)
- Select shift and status
- Enter check-in/check-out times
- Auto-calculates working hours and OT
- Holiday detection and warning

**Bulk Entry Mode:**
- Mark attendance for multiple employees at once
- Useful for weekly offs, public holidays
- Select date and status, then choose employees

### 2. Overtime (OT) Calculation

**Automatic Calculation Rules:**
```sql
Weekday OT:
- Standard hours: 8.0
- OT starts after 8 hours
- Rate: 1.5x

Weekend OT:
- All hours count as OT
- Rate: 2.0x

Holiday OT:
- All hours count as OT
- Rate: 2.5x
```

**Trigger Function:**
```sql
CREATE TRIGGER trg_attendance_calculate_hours
BEFORE INSERT OR UPDATE ON attendance
EXECUTE FUNCTION trg_calculate_attendance_hours();
```

### 3. Monthly Summary Generation

**Automated Process:**
1. Attendance is marked daily
2. `update_monthly_summary()` function is called
3. Summary table is updated with aggregated data
4. Data locked when month is finalized

**Summary Includes:**
- Total working days in month
- Present/absent/half-day counts
- Leave days breakdown
- Total OT hours
- Payable days calculation

### 4. Holiday Management

**Holiday Types:**
- Public Holiday
- Festival Holiday
- Optional Holiday
- Weekly Off
- Compensatory Off
- Emergency Holiday

**Features:**
- Add/edit/delete holidays
- Activate/deactivate holidays
- Filter by fiscal year
- Color-coded display
- Paid/unpaid tracking

## 📈 Reports

### Daily Attendance Report

**Features:**
- Filter by date, department, designation, level, status
- Summary statistics cards
- Export to Excel
- Print-friendly layout
- Pagination support

**Key Metrics:**
- Total employees
- Present count
- Absent count
- Leave count
- Total OT hours

### Monthly Attendance Report

**Features:**
- Monthly summary by employee
- Payable days calculation
- Department/designation/level filtering
- Export to Excel
- Lock/unlock months

**Key Metrics:**
- Present days
- Absent days
- Half days
- Leave days
- Weekly offs
- Public holidays
- Total OT hours
- LWP days
- **Payable Days** (most important for payroll)

## 🔧 Database Functions

### `calculate_working_hours()`
Calculates actual working hours from check-in/check-out times minus breaks.

```sql
SELECT calculate_working_hours('09:00', '17:00', 1.0);
-- Returns: 7.0 hours (8 hours - 1 hour break)
```

### `calculate_ot_hours()`
Calculates overtime based on working hours and day type.

```sql
SELECT calculate_ot_hours(10.0, 8.0, false, false);
-- Returns: 2.0 hours (10 - 8 standard hours)
```

### `update_monthly_summary()`
Updates monthly summary for an employee.

```sql
SELECT update_monthly_summary(123, '2082.10');
-- Updates summary for employee 123 for Magh 2082
```

## 📅 Nepali Calendar Integration

### Date Format
All Nepali dates use format: `YYYY.MM.DD`
Example: `2082.10.15` (15 Magh 2082)

### Month Reference
```
01 - Baishakh   (बैशाख)
02 - Jestha     (जेष्ठ)
03 - Ashadh     (असार)
04 - Shrawan    (श्रावण)
05 - Bhadra     (भाद्र)
06 - Ashwin     (आश्विन)
07 - Kartik     (कार्तिक)
08 - Mangsir    (मंसिर)
09 - Poush      (पौष)
10 - Magh       (माघ)
11 - Falgun     (फाल्गुन)
12 - Chaitra    (चैत्र)
```

### Fiscal Year
Nepali fiscal year starts from Shrawan 1 (mid-July).
Example: FY 2082 = 2082.04.01 to 2083.03.32

## 🎯 Usage Examples

### Example 1: Mark Daily Attendance
```php
// Mark present with check-in/out times
POST attendance_entry.php
{
    employee_id: 15,
    attendance_date_nep: "2082.10.15",
    attendance_date_eng: "2026-01-29",
    status_id: 1, // Present
    check_in_time: "09:15",
    check_out_time: "18:30",
    break_hours: 1.0
}
// Result: 8.25 working hours, 0.25 OT hours
```

### Example 2: Bulk Mark Weekly Off
```php
POST attendance_entry.php
{
    action: "bulk_mark",
    employee_ids: [1,2,3,4,5],
    bulk_date_nep: "2082.10.16",
    bulk_date_eng: "2026-01-30",
    bulk_status_id: 5 // Weekly Off
}
```

### Example 3: Generate Monthly Report
```php
GET monthly_attendance_report.php?year_month_nep=2082.10
// Returns summary for all employees for Magh 2082
```

## 🔐 Security Considerations

1. **Authentication Required**: All pages check for logged-in user
2. **Role-Based Access**: Check user roles before operations
3. **SQL Injection Prevention**: Using prepared statements
4. **Transaction Management**: Database operations wrapped in transactions
5. **Audit Trail**: Created_by, updated_by fields for tracking

## 📊 Performance Tips

1. **Indexes**: All date fields and foreign keys are indexed
2. **Pagination**: Large reports use pagination (50 records per page)
3. **Monthly Lock**: Lock finalized months to prevent accidental changes
4. **Batch Operations**: Use bulk entry for mass updates

## 🐛 Troubleshooting

### Issue: Working hours not calculating
**Solution**: Check that check-in and check-out times are both filled

### Issue: OT hours showing 0
**Solution**: 
- Ensure working hours exceed 8 for weekdays
- Check if day is marked as holiday/weekend
- Verify OT rules in ot_rules table

### Issue: Monthly summary not updating
**Solution**:
- Check if `update_monthly_summary()` function exists
- Verify employee_id and year_month format
- Check PostgreSQL logs for errors

### Issue: Holiday not detected
**Solution**:
- Verify holiday is marked as active
- Check date format matches exactly
- Ensure fiscal year is correct

## 📝 Future Enhancements

1. **Shift Management**
   - Multiple shifts per day
   - Shift-based OT rules
   - Shift rotation scheduling

2. **Leave Management**
   - Leave application workflow
   - Leave balance tracking
   - Leave approval system

3. **Biometric Integration**
   - Auto-import from fingerprint devices
   - Real-time attendance sync

4. **Mobile App**
   - Mobile attendance marking
   - QR code check-in
   - GPS-based location verification

5. **Advanced Analytics**
   - Attendance trends
   - Department-wise comparison
   - Prediction models

## 📞 Support

For issues or questions:
1. Check this README first
2. Review database schema comments
3. Examine function definitions in schema
4. Contact system administrator

## 📜 License

This system is designed for internal organizational use.
Modify as needed for your requirements.

---

**Version:** 1.0
**Last Updated:** February 2026
**Database:** PostgreSQL 12+
**PHP Version:** 7.4+

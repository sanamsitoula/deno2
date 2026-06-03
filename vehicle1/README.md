# Janak Education Vehicle Management System - Enhanced Version

## Overview
This is a comprehensive enhancement to the existing vehicle management system with improved fuel coupon management, daily logs, maintenance tracking, and Nepali format reports.

## Key Improvements

### 1. **Fuel Coupon Management (fuel_coupons_v2.php)**
- ✅ Multiple fuel types per vehicle (Diesel + Mobil, Petrol + Mobil, etc.)
- ✅ Multiple coupons per month for same vehicle with different fuel types
- ✅ Automatic fuel price fetching based on distribution date
- ✅ Default pump name: "Om Sai Oil Pvt. Ltd." with dropdown options
- ✅ Created by automatically populated from logged-in user
- ✅ Simplified coupon-distribution workflow

**Key Features:**
- Vehicle can have petrol AND mobil, diesel AND mobil, or any combination
- No need to reselect coupon for each distribution
- Fuel price automatically fetched from price history based on date
- If price not provided, system auto-fetches current rate
- Better validation and user feedback

### 2. **Fuel Reports (fuel_reports.php)**
- ✅ Separate reports for Coupon Issuance and Distribution
- ✅ Multiple rates shown per coupon if applicable
- ✅ Filterable by fiscal year, month, vehicle
- ✅ Shows total amounts and quantities
- ✅ Print-friendly format

**Report Types:**
1. **Coupon Issuance Report** - Shows all issued coupons with allocation details
2. **Distribution Report** - Shows fuel actually distributed with rates and amounts

### 3. **Nepali Format Reports (fuel_report_nepali.php)**
- ✅ Dynamic month and year filtering
- ✅ Shows all three fuel types (Petrol, Diesel, Mobil) in one report
- ✅ Matches the format from provided images
- ✅ Signature sections at bottom
- ✅ Print-optimized

### 4. **Vehicle Daily Log (vehicle_daily_log_v2.php)**
- ✅ Current driver automatically selected based on vehicle assignment
- ✅ English date defaults to today
- ✅ Created by automatically set to logged-in user
- ✅ Distance and fuel efficiency auto-calculation
- ✅ Better validation and user experience

**Automatic Features:**
- When vehicle is selected, current assigned driver is auto-filled
- Today's date is pre-filled
- Distance calculated automatically from start/end meter
- Fuel efficiency (KM/L) calculated if fuel used is entered

### 5. **Maintenance Tracking (vehicle_maintenance.php)**
- ✅ Comprehensive maintenance record keeping
- ✅ Nepali date format for requisition/maintenance date
- ✅ Next maintenance auto-calculation for scheduled maintenance
- ✅ Cost tracking (labor + parts)
- ✅ Payment status tracking
- ✅ Warranty information

**Features:**
- Scheduled maintenance types auto-calculate next due date/km
- Total cost auto-calculated
- Downtime tracking
- Service provider and mechanic information
- Bill/invoice tracking

### 6. **Maintenance Reports (maintenance_report_nepali.php)**
- ✅ Shows maintenance date, vehicle number, KM from-to
- ✅ Work description and cost breakdown
- ✅ Nepali format with signature sections
- ✅ Filterable and print-friendly

## Database Schema Enhancement

The provided SQL schema includes:
- `fuel_price_history` - Historical fuel rates by date
- `maintenance_types` - Master data for maintenance categories
- `vehicle_maintenance_records` - Maintenance tracking
- `maintenance_parts` - Parts inventory (optional)
- Enhanced views and functions

## Installation Instructions

### Step 1: Update Database Schema
```sql
-- Run the provided SQL file to create:
-- - fuel_price_history table
-- - maintenance_types table
-- - vehicle_maintenance_records table
-- - maintenance_parts table
-- - Updated views and functions
```

### Step 2: Add Initial Fuel Price Data
```sql
-- Example: Add current fuel prices
INSERT INTO fuel_price_history 
(fiscal_year, month_nep, fuel_type, effective_from_date_nep, effective_from_date_eng, rate_per_liter, source, is_active, created_by)
VALUES
('2082/83', 'Poush', 'petrol', '2082.09.15', '2026-01-01', 161.75, 'Nepal Oil Corporation', TRUE, 1),
('2082/83', 'Poush', 'diesel', '2082.09.15', '2026-01-01', 147.50, 'Nepal Oil Corporation', TRUE, 1),
('2082/83', 'Poush', 'mobil', '2082.09.15', '2026-01-01', 175.00, 'Nepal Oil Corporation', TRUE, 1);
```

### Step 3: Deploy Files
Place the following files in your `/deno2/modules/vehicles/` directory:

1. `fuel_coupons_v2.php` - Enhanced fuel coupon management
2. `fuel_reports.php` - English format reports
3. `fuel_report_nepali.php` - Nepali format fuel report
4. `vehicle_daily_log_v2.php` - Enhanced daily log
5. `vehicle_maintenance.php` - Maintenance tracking
6. `maintenance_report_nepali.php` - Maintenance report
7. `get_fuel_price.php` - API for fetching current fuel prices

### Step 4: Update Navigation
Add links to new modules in your navigation menu:

```php
<!-- Fuel Management -->
<a href="/deno2/modules/vehicles/fuel_coupons_v2.php">Fuel Coupons</a>
<a href="/deno2/modules/vehicles/fuel_reports.php">Fuel Reports</a>
<a href="/deno2/modules/vehicles/fuel_report_nepali.php">Nepali Fuel Report</a>

<!-- Daily Operations -->
<a href="/deno2/modules/vehicles/vehicle_daily_log_v2.php">Daily Log</a>

<!-- Maintenance -->
<a href="/deno2/modules/vehicles/vehicle_maintenance.php">Maintenance</a>
<a href="/deno2/modules/vehicles/maintenance_report_nepali.php">Maintenance Report</a>
```

## Real-Life Usage Flow

### Monthly Fuel Coupon Process:

1. **Start of Month**: Vehicle unit issues coupons
   - Go to Fuel Coupons → Create Coupon
   - Select vehicle, fuel type, allocated quantity
   - Can issue multiple coupons for same vehicle (diesel + mobil)
   - Default pump: Om Sai Oil Pvt. Ltd.

2. **Throughout Month**: Drivers get fuel
   - Go to Fuel Coupons → Add Distribution
   - Select coupon
   - Enter quantity and date
   - System auto-fetches current fuel price
   - Can distribute multiple times from same coupon

3. **Month End**: Pump submits bill
   - Pump submits bill with different rates (price changes)
   - Each distribution can have different rate
   - System tracks all distributions with their respective rates
   - Generate report showing all distributions with amounts

4. **Generate Reports**:
   - **Coupon Report**: Shows what was issued
   - **Distribution Report**: Shows what was actually distributed
   - **Nepali Report**: Combined report for official submission

## Key Business Logic

### Fuel Type Rules:
- **Diesel vehicles**: Can have diesel + mobil coupons
- **Petrol vehicles**: Can have petrol + mobil coupons  
- **Any vehicle**: Can have mobil-only coupon
- Multiple coupons per month allowed for different fuel types

### Price Management:
- Prices stored in `fuel_price_history` with effective dates
- System automatically selects correct price based on distribution date
- Manual override available if needed
- Price changes handled automatically

### Maintenance Scheduling:
- Scheduled maintenance types auto-calculate next due
- Based on KM intervals and/or time intervals
- Alerts for upcoming/overdue maintenance
- Complete cost tracking with payment status

## Reports Available

### 1. Fuel Coupon Issuance Report
- Shows all coupons issued
- Allocated vs distributed quantities
- By period, vehicle, or fuel type

### 2. Fuel Distribution Report  
- Detailed distribution with rates
- Multiple rates per coupon supported
- Total quantities and amounts

### 3. Nepali Format Fuel Report
- Official format for submission
- All fuel types in single report
- Signature sections included

### 4. Vehicle Monthly Summary
- Total fuel consumption per vehicle
- Cost analysis
- KM traveled vs fuel used

### 5. Maintenance Report
- All maintenance activities
- Cost breakdown (labor + parts)
- KM tracking (from → to)
- Nepali format for official use

## Troubleshooting

### Issue: Fuel price not auto-filling
**Solution**: Ensure fuel price history has entries for the date range. Add current prices using SQL or fuel price management page.

### Issue: Driver not auto-selecting
**Solution**: Ensure vehicle has an active assignment in `vehicle_assignments` table with `is_active = TRUE`.

### Issue: Multiple coupons showing error
**Solution**: This is only if you try to create duplicate coupon with SAME fuel type for SAME vehicle in SAME month. Different fuel types are allowed.

## Future Enhancements

1. **Mobile App**: For drivers to report daily logs
2. **SMS Alerts**: For maintenance due dates
3. **Fuel Analytics**: Consumption patterns and anomaly detection
4. **Budget Integration**: Link with financial system
5. **GPS Integration**: Auto-capture KM readings
6. **Digital Signatures**: For report approvals

## Support

For issues or questions:
- Check database connection in `/deno2/config/database.php`
- Verify user authentication in `/deno2/config/auth.php`
- Review error logs in application
- Ensure all SQL views are created successfully

## Credits

Developed for Janak Shiksha Samagri Kendra Ltd.
Vehicle Management System Enhancement
January 2026

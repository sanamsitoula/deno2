# Fix for Multiple Coupons Per Month Issue

## Problem
You're getting this error:
```
SQLSTATE[23505]: Unique violation: 7 ERROR: duplicate key value violates unique constraint "uk_coupon_vehicle_month"
DETAIL: Key (fiscal_year, month_nep, vehicle_id)=(2082/83, Mangsir, 16) already exists.
```

## Root Cause
The database has a unique constraint that prevents multiple coupons for the same vehicle in the same month, regardless of fuel type.

## Solution Options

### **OPTION 1: Allow Different Fuel Types Only** (RECOMMENDED)
This allows multiple coupons per month BUT only for different fuel types (diesel + mobil, petrol + mobil, etc.)

**Run this SQL:**
```sql
-- Drop old constraint
ALTER TABLE fuel_coupons DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month;

-- Add new constraint including fuel_type
ALTER TABLE fuel_coupons 
ADD CONSTRAINT uk_coupon_vehicle_month_fuel 
UNIQUE (fiscal_year, month_nep, vehicle_id, fuel_type);
```

**What this allows:**
- ✅ Mangsir: Vehicle 16, Diesel 100L
- ✅ Mangsir: Vehicle 16, Mobil 5L (different fuel type)
- ❌ Mangsir: Vehicle 16, Diesel 50L (duplicate fuel type)

---

### **OPTION 2: Allow Unlimited Coupons** (FLEXIBLE)
This allows truly unlimited coupons - even multiple of the same fuel type.

**Run this SQL:**
```sql
-- Drop old constraint
ALTER TABLE fuel_coupons DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month;
ALTER TABLE fuel_coupons DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month_fuel;

-- No new constraint - fully flexible
```

**What this allows:**
- ✅ Mangsir: Vehicle 16, Diesel 100L
- ✅ Mangsir: Vehicle 16, Diesel 50L (additional coupon)
- ✅ Mangsir: Vehicle 16, Diesel 30L (another coupon)
- ✅ Mangsir: Vehicle 16, Mobil 5L
- ✅ Everything is allowed!

---

## Quick Fix Steps

### Step 1: Choose Your Option
Based on your business needs:
- **Option 1** if vehicles typically need one coupon per fuel type per month
- **Option 2** if you need complete flexibility

### Step 2: Run the SQL
Connect to your PostgreSQL database and run the appropriate SQL from above.

```bash
# Using psql
psql -U your_user -d your_database -f fix_unique_constraint.sql

# Or using pgAdmin
# Copy and paste the SQL into the query tool and execute
```

### Step 3: Update the PHP File
The updated `fuel_coupons_v2.php` already has the duplicate check removed, so it will work with Option 2.

If you chose Option 1, you can add this check back in the PHP (optional):
```php
// Check if coupon already exists for this fuel type
$check_stmt = $conn->prepare("
    SELECT coupon_id FROM fuel_coupons 
    WHERE fiscal_year = :fiscal_year 
      AND month_nep = :month_nep 
      AND vehicle_id = :vehicle_id 
      AND fuel_type = :fuel_type
      AND deleted_at IS NULL
");
// ... execute and throw exception if exists
```

### Step 4: Test
Try creating multiple coupons:
1. Create diesel coupon for vehicle 16, Mangsir - Should work
2. Create mobil coupon for vehicle 16, Mangsir - Should work
3. Create another diesel coupon for vehicle 16, Mangsir:
   - Option 1: Should fail
   - Option 2: Should work

---

## Real-World Examples

### Scenario 1: Normal Monthly Allocation (Option 1)
```
Vehicle: Ba 1 Cha 2520
Month: Mangsir
Coupons:
1. Diesel: 100L (main fuel)
2. Mobil: 5L (oil change)
✅ Works with Option 1
```

### Scenario 2: Emergency Additional Fuel (Option 2)
```
Vehicle: Ba 1 Cha 2520
Month: Mangsir
Coupons:
1. Diesel: 100L (regular allocation)
2. Diesel: 50L (emergency - vehicle needs extra)
3. Mobil: 5L (oil change)
❌ Fails with Option 1
✅ Works with Option 2
```

### Scenario 3: Multiple Distributions
```
Vehicle: Ba 1 Cha 2520
Month: Mangsir
Coupon 1: Diesel 100L
Distributions:
1. Week 1: 25L @ रू 161/L
2. Week 2: 30L @ रू 163/L (price changed)
3. Week 3: 25L @ रू 163/L
4. Week 4: 20L @ रू 165/L (price changed again)
✅ Works with both options (distributions are unlimited)
```

---

## Verification Queries

### Check Current Constraints
```sql
SELECT 
    conname as constraint_name,
    pg_get_constraintdef(oid) as definition
FROM pg_constraint 
WHERE conrelid = 'fuel_coupons'::regclass 
  AND contype = 'u';  -- u = unique constraint
```

### Check Existing Coupons
```sql
SELECT 
    fiscal_year,
    month_nep,
    v.vehicle_no,
    fc.fuel_type,
    fc.allocated_qty,
    fc.issued_date_nep,
    COUNT(*) OVER (PARTITION BY fc.fiscal_year, fc.month_nep, fc.vehicle_id, fc.fuel_type) as coupon_count
FROM fuel_coupons fc
JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
WHERE fc.deleted_at IS NULL
ORDER BY fc.fiscal_year, fc.month_nep, v.vehicle_no, fc.fuel_type;
```

### Find Duplicate Coupons (if any)
```sql
SELECT 
    fiscal_year,
    month_nep,
    vehicle_id,
    fuel_type,
    COUNT(*) as coupon_count
FROM fuel_coupons
WHERE deleted_at IS NULL
GROUP BY fiscal_year, month_nep, vehicle_id, fuel_type
HAVING COUNT(*) > 1;
```

---

## Recommended Approach

**I recommend OPTION 2** (unlimited coupons) because:

1. **Flexibility**: Handles emergency situations where extra fuel is needed
2. **Real-world**: Sometimes vehicles need more fuel than initially allocated
3. **Simple**: No complex validation logic needed
4. **Audit Trail**: Each coupon can be tracked separately with its own coupon number
5. **Price Tracking**: Multiple coupons allow tracking different price periods

The system already has good controls:
- Each distribution is tracked with date and rate
- Total amounts are calculated correctly
- Reports show all coupons and distributions
- You can still track by vehicle and month

---

## Files Updated

1. **fix_unique_constraint.sql** - Option 1 SQL script
2. **allow_unlimited_coupons.sql** - Option 2 SQL script  
3. **fuel_coupons_v2.php** - Already updated to allow unlimited coupons

---

## After Applying Fix

You should be able to:
- ✅ Create multiple coupons per vehicle per month
- ✅ Mix different fuel types freely
- ✅ Create emergency/additional coupons when needed
- ✅ Track all distributions separately
- ✅ Generate accurate reports

---

## Troubleshooting

**Still getting error after running SQL?**
1. Make sure you're connected to the correct database
2. Check if constraint was actually dropped: `\d fuel_coupons` in psql
3. Restart your application/web server
4. Clear any application cache

**Need to revert?**
```sql
-- To go back to original (single coupon per vehicle per month)
ALTER TABLE fuel_coupons DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month_fuel;
ALTER TABLE fuel_coupons 
ADD CONSTRAINT uk_coupon_vehicle_month 
UNIQUE (fiscal_year, month_nep, vehicle_id);
```

---

## Support

If you continue to have issues:
1. Check the PostgreSQL error log
2. Verify the constraint status with the verification queries above
3. Make sure you're using the updated fuel_coupons_v2.php file
4. Test with a simple INSERT statement to isolate the issue

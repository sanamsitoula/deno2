-- ================================================================================
-- FIX: Allow Multiple Coupons Per Vehicle Per Month
-- ================================================================================

-- Step 1: Drop the existing unique constraint
ALTER TABLE fuel_coupons 
DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month;

-- Step 2: Create a NEW unique constraint that includes fuel_type
-- This allows multiple coupons for same vehicle/month but different fuel types
ALTER TABLE fuel_coupons 
ADD CONSTRAINT uk_coupon_vehicle_month_fuel 
UNIQUE (fiscal_year, month_nep, vehicle_id, fuel_type);

-- Step 3: Verify the constraint
SELECT 
    conname as constraint_name,
    pg_get_constraintdef(oid) as definition
FROM pg_constraint 
WHERE conrelid = 'fuel_coupons'::regclass 
  AND conname LIKE '%coupon%';

-- ================================================================================
-- Test Cases
-- ================================================================================

-- These should now ALL work:

-- Test 1: Same vehicle, same month, DIFFERENT fuel types (SHOULD WORK)
-- Vehicle Ba 1 Cha 2520, Mangsir 2082/83
-- INSERT diesel coupon - OK
-- INSERT mobil coupon - OK (different fuel type)
-- INSERT petrol coupon - OK (different fuel type)

-- Test 2: Same vehicle, same month, SAME fuel type (SHOULD FAIL)
-- Vehicle Ba 1 Cha 2520, Mangsir 2082/83
-- INSERT diesel coupon - OK (first one)
-- INSERT diesel coupon AGAIN - FAIL (duplicate)

-- ================================================================================
-- Expected Behavior After Fix
-- ================================================================================

/*
SCENARIO 1: Vehicle needs diesel AND mobil
- Month: Mangsir
- Vehicle: Ba 1 Cha 2520
- Coupon 1: Diesel 100L ✅
- Coupon 2: Mobil 5L ✅
- Both coupons in same month - WORKS!

SCENARIO 2: Vehicle needs multiple diesel coupons (emergency)
- Month: Mangsir  
- Vehicle: Ba 1 Cha 2520
- Coupon 1: Diesel 100L ✅
- Coupon 2: Diesel 50L (emergency) ❌ PREVENTED
- Solution: Increase quantity on existing coupon OR
           Use "carry forward" field OR
           Create in different month

SCENARIO 3: Across different months
- Mangsir: Diesel 100L ✅
- Poush: Diesel 100L ✅
- Different months - WORKS!
*/

-- ================================================================================
-- Optional: If you want to allow truly unlimited coupons
-- ================================================================================

-- If you want NO restrictions at all (not recommended):
-- ALTER TABLE fuel_coupons DROP CONSTRAINT uk_coupon_vehicle_month_fuel;

-- Better approach: Keep the constraint but add a serial/sequence number
-- This is commented out - only use if you really need multiple same-fuel-type coupons

/*
-- Add a sequence number column
ALTER TABLE fuel_coupons ADD COLUMN IF NOT EXISTS coupon_sequence INT DEFAULT 1;

-- Drop the constraint
ALTER TABLE fuel_coupons DROP CONSTRAINT uk_coupon_vehicle_month_fuel;

-- Create new constraint with sequence
ALTER TABLE fuel_coupons 
ADD CONSTRAINT uk_coupon_vehicle_month_fuel_seq 
UNIQUE (fiscal_year, month_nep, vehicle_id, fuel_type, coupon_sequence);

-- Now you can have:
-- Coupon 1: Vehicle X, Mangsir, Diesel, Seq 1
-- Coupon 2: Vehicle X, Mangsir, Diesel, Seq 2
-- Coupon 3: Vehicle X, Mangsir, Mobil, Seq 1
*/

-- ================================================================================
-- Verification Query
-- ================================================================================

-- Check what coupons exist for a vehicle in a month
SELECT 
    coupon_id,
    fiscal_year,
    month_nep,
    vehicle_id,
    fuel_type,
    allocated_qty,
    issued_date_nep
FROM fuel_coupons
WHERE vehicle_id = 16 
  AND fiscal_year = '2082/83'
  AND month_nep = 'Mangsir'
  AND deleted_at IS NULL
ORDER BY fuel_type;

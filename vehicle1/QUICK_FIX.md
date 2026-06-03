# QUICK FIX - Multiple Coupons Issue

## The Error You're Getting:
```
duplicate key value violates unique constraint "uk_coupon_vehicle_month"
```

## The Quick Fix (2 minutes):

### Step 1: Run This SQL
Connect to your database and execute:

```sql
-- Option A: Allow different fuel types only
ALTER TABLE fuel_coupons DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month;
ALTER TABLE fuel_coupons ADD CONSTRAINT uk_coupon_vehicle_month_fuel 
UNIQUE (fiscal_year, month_nep, vehicle_id, fuel_type);

-- OR

-- Option B: Allow unlimited coupons (RECOMMENDED)
ALTER TABLE fuel_coupons DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month;
```

### Step 2: Replace Your File
Replace your current `fuel_coupons.php` with `fuel_coupons_v2_FIXED.php`

### Step 3: Test
Try creating a coupon again - it should work!

---

## What Changed:

**BEFORE:** Only 1 coupon per vehicle per month (total)
**AFTER:** Unlimited coupons per vehicle per month

### Examples Now Allowed:

✅ **Mangsir, Vehicle 16:**
- Coupon 1: Diesel 100L
- Coupon 2: Diesel 50L (emergency)
- Coupon 3: Mobil 5L
- Coupon 4: Petrol 20L

✅ **Across Months:**
- Mangsir: Diesel 100L
- Poush: Diesel 100L
- Magh: Diesel 100L
(This always worked)

---

## Verify the Fix:

```sql
-- Check if constraint was removed
SELECT conname 
FROM pg_constraint 
WHERE conrelid = 'fuel_coupons'::regclass 
  AND conname LIKE '%coupon%';

-- Should NOT show: uk_coupon_vehicle_month
```

---

## Rollback (if needed):

```sql
-- To go back to original
ALTER TABLE fuel_coupons DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month_fuel;
ALTER TABLE fuel_coupons ADD CONSTRAINT uk_coupon_vehicle_month 
UNIQUE (fiscal_year, month_nep, vehicle_id);
```

---

## File Changes:
- ✅ `fuel_coupons_v2_FIXED.php` - Duplicate check removed
- ✅ `fix_unique_constraint.sql` - Option A SQL
- ✅ `allow_unlimited_coupons.sql` - Option B SQL  
- ✅ `FIX_MULTIPLE_COUPONS.md` - Complete documentation

---

**That's it! 2 SQL commands and you're done. ✅**

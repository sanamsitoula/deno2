-- ================================================================================
-- OPTION 2: Allow Unlimited Coupons (Multiple Same Fuel Type)
-- Use this if you need multiple diesel/petrol/mobil coupons in same month
-- ================================================================================

-- Step 1: Add sequence number column
ALTER TABLE fuel_coupons 
ADD COLUMN IF NOT EXISTS coupon_sequence INT DEFAULT 1;

-- Step 2: Drop existing unique constraint
ALTER TABLE fuel_coupons 
DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month;

ALTER TABLE fuel_coupons 
DROP CONSTRAINT IF EXISTS uk_coupon_vehicle_month_fuel;

-- Step 3: Create index for better performance (optional, but recommended)
CREATE INDEX IF NOT EXISTS idx_fuel_coupons_lookup 
ON fuel_coupons(fiscal_year, month_nep, vehicle_id, fuel_type) 
WHERE deleted_at IS NULL;

-- Step 4: Update existing records to have sequence numbers
WITH numbered_coupons AS (
    SELECT 
        coupon_id,
        ROW_NUMBER() OVER (
            PARTITION BY fiscal_year, month_nep, vehicle_id, fuel_type 
            ORDER BY issued_date_eng, coupon_id
        ) as seq_num
    FROM fuel_coupons
    WHERE deleted_at IS NULL
)
UPDATE fuel_coupons fc
SET coupon_sequence = nc.seq_num
FROM numbered_coupons nc
WHERE fc.coupon_id = nc.coupon_id;

-- Step 5: Verify the update
SELECT 
    fiscal_year,
    month_nep,
    vehicle_id,
    fuel_type,
    coupon_sequence,
    allocated_qty,
    issued_date_nep
FROM fuel_coupons
WHERE deleted_at IS NULL
ORDER BY fiscal_year, month_nep, vehicle_id, fuel_type, coupon_sequence;

-- ================================================================================
-- Now you can have unlimited coupons!
-- ================================================================================

/*
EXAMPLES:

Vehicle Ba 1 Cha 2520, Mangsir 2082/83:
- Coupon 1: Diesel, Seq 1, 100L - Initial allocation
- Coupon 2: Diesel, Seq 2, 50L - Emergency fuel needed
- Coupon 3: Diesel, Seq 3, 30L - Additional requirement
- Coupon 4: Mobil, Seq 1, 5L - Oil change
- Coupon 5: Mobil, Seq 2, 3L - Top up

All valid and allowed!
*/

-- ================================================================================
-- Function to get next sequence number (for application use)
-- ================================================================================

CREATE OR REPLACE FUNCTION get_next_coupon_sequence(
    p_fiscal_year VARCHAR(9),
    p_month_nep VARCHAR(20),
    p_vehicle_id INT,
    p_fuel_type VARCHAR(10)
) RETURNS INT AS $$
DECLARE
    v_next_seq INT;
BEGIN
    SELECT COALESCE(MAX(coupon_sequence), 0) + 1
    INTO v_next_seq
    FROM fuel_coupons
    WHERE fiscal_year = p_fiscal_year
      AND month_nep = p_month_nep
      AND vehicle_id = p_vehicle_id
      AND fuel_type = p_fuel_type
      AND deleted_at IS NULL;
    
    RETURN v_next_seq;
END;
$$ LANGUAGE plpgsql;

-- Test the function
SELECT get_next_coupon_sequence('2082/83', 'Mangsir', 16, 'diesel');

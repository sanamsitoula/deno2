-- =============================================================================
-- DATABASE FIXES
-- Run these in order on your PostgreSQL database.
-- =============================================================================


-- -----------------------------------------------------------------------------
-- FIX 1: get_current_fuel_price() function
-- -----------------------------------------------------------------------------
-- PROBLEM: The function filtered by `is_active = TRUE`.
--          When a new price is added, the previous price row gets
--          is_active = FALSE and effective_to_date_eng set.
--          So for any historical date, the matching row has is_active=FALSE
--          and the function returns NULL → price never populates.
--
-- FIX:     Remove the is_active filter. Match purely on date range.
-- -----------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION public.get_current_fuel_price(
    p_fuel_type character varying,
    p_date date DEFAULT CURRENT_DATE
)
RETURNS numeric
LANGUAGE plpgsql
AS $function$
DECLARE
    v_price NUMERIC(10,2);
BEGIN
    SELECT rate_per_liter INTO v_price
    FROM fuel_price_history
    WHERE fuel_type = p_fuel_type
      AND effective_from_date_eng <= p_date
      AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= p_date)
      -- REMOVED: AND is_active = TRUE
      AND deleted_at IS NULL
    ORDER BY effective_from_date_eng DESC
    LIMIT 1;
    
    RETURN v_price;
END;
$function$;


-- -----------------------------------------------------------------------------
-- FIX 2: auto_set_fuel_price() trigger function
-- -----------------------------------------------------------------------------
-- PROBLEM: Same as above — it called get_current_fuel_price() which had
--          the is_active bug. Since we fixed the function above, this trigger
--          now automatically benefits. But we also inline-fix the logic here
--          for clarity and to remove the dependency.
--
-- This trigger fires BEFORE INSERT on fuel_coupon_distributions.
-- If rate_per_liter is NULL or 0, it looks up the price for the distribution
-- date and sets it automatically.
-- -----------------------------------------------------------------------------

CREATE OR REPLACE FUNCTION public.auto_set_fuel_price()
RETURNS trigger
LANGUAGE plpgsql
AS $function$
DECLARE
    v_fuel_type    VARCHAR(10);
    v_current_price NUMERIC(10,2);
BEGIN
    -- Get fuel type from the parent coupon
    SELECT fc.fuel_type INTO v_fuel_type
    FROM fuel_coupons fc
    WHERE fc.coupon_id = NEW.coupon_id;
    
    -- If rate_per_liter is not provided (NULL or 0), auto-fetch by date range
    IF NEW.rate_per_liter IS NULL OR NEW.rate_per_liter = 0 THEN
        SELECT rate_per_liter INTO v_current_price
        FROM fuel_price_history
        WHERE fuel_type = v_fuel_type
          AND effective_from_date_eng <= NEW.disburse_date_eng
          AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= NEW.disburse_date_eng)
          -- REMOVED: AND is_active = TRUE
          AND deleted_at IS NULL
        ORDER BY effective_from_date_eng DESC
        LIMIT 1;

        IF v_current_price IS NOT NULL THEN
            NEW.rate_per_liter := v_current_price;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$function$;


-- -----------------------------------------------------------------------------
-- FIX 3: (Optional) Add a GENERATED ALWAYS column for total_amount
-- -----------------------------------------------------------------------------
-- PROBLEM: The PHP distribution INSERT never writes total_amount.
--          Reports were referencing fcd.total_amount which doesn't exist.
--          The PHP-side reports are now fixed to compute qty * rate on the fly,
--          so this column is optional. But if you want it for convenience
--          (e.g. other queries), add it as a generated column:
--
-- NOTE: Only run this if fuel_coupon_distributions does NOT already have
--       a total_amount column. If it does exist as a regular column, skip this.
-- -----------------------------------------------------------------------------

-- ALTER TABLE fuel_coupon_distributions
--     ADD COLUMN total_amount NUMERIC(12,2) 
--         GENERATED ALWAYS AS (disburse_qty * rate_per_liter) STORED;
--
-- If the column already exists as a plain column and you want to keep it,
-- just add a trigger or update existing rows:
--
-- UPDATE fuel_coupon_distributions
--    SET total_amount = disburse_qty * rate_per_liter
--  WHERE total_amount IS NULL OR total_amount = 0;
--
-- -----------------------------------------------------------------------------


-- -----------------------------------------------------------------------------
-- VERIFICATION: After running, test with:
-- -----------------------------------------------------------------------------
-- SELECT get_current_fuel_price('petrol', '2024-08-15');
-- SELECT get_current_fuel_price('diesel', CURRENT_DATE);
-- -----------------------------------------------------------------------------

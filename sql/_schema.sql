--
-- PostgreSQL database dump
--

-- Dumped from database version 17.5
-- Dumped by pg_dump version 17.5

SET statement_timeout = 0;
SET lock_timeout = 0;
SET idle_in_transaction_session_timeout = 0;
SET transaction_timeout = 0;
SET client_encoding = 'UTF8';
SET standard_conforming_strings = on;
SELECT pg_catalog.set_config('search_path', '', false);
SET check_function_bodies = false;
SET xmloption = content;
SET client_min_messages = warning;
SET row_security = off;

--
-- Name: SCHEMA public; Type: COMMENT; Schema: -; Owner: pg_database_owner
--

COMMENT ON SCHEMA public IS 'ZKTeco Device Integration for Attendance Management';


--
-- Name: fiscal_year_enum; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.fiscal_year_enum AS ENUM (
    '2081',
    '2082',
    '2083',
    '2084',
    '2080-81',
    '2081-82',
    '2082-83',
    '2083-84'
);


ALTER TYPE public.fiscal_year_enum OWNER TO postgres;

--
-- Name: received_by_enum; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.received_by_enum AS ENUM (
    'sarala',
    'dambar',
    'Sarala Joshi',
    'Ramesh Kuikel',
    'Balram Acharya'
);


ALTER TYPE public.received_by_enum OWNER TO postgres;

--
-- Name: user_enum; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.user_enum AS ENUM (
    'usha',
    'sanam',
    'Usha Thapa',
    'Durba Raj Panta',
    'Babu Ram Shrestha',
    'Babu Raja Shrestha',
    'Madhab Poudel',
    'Sanjay Verma'
);


ALTER TYPE public.user_enum OWNER TO postgres;

--
-- Name: user_role; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.user_role AS ENUM (
    'admin',
    'editor',
    'viewer',
    'marketing',
    'supervisor',
    'operator',
    'incharge',
    'presss',
    'press'
);


ALTER TYPE public.user_role OWNER TO postgres;

--
-- Name: verified_by_enum; Type: TYPE; Schema: public; Owner: postgres
--

CREATE TYPE public.verified_by_enum AS ENUM (
    'ram',
    'shyam'
);


ALTER TYPE public.verified_by_enum OWNER TO postgres;

--
-- Name: auto_set_fuel_price(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.auto_set_fuel_price() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.auto_set_fuel_price() OWNER TO postgres;

--
-- Name: calculate_monthly_vehicle_summary(integer, character varying, character varying); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.calculate_monthly_vehicle_summary(p_vehicle_id integer, p_fiscal_year character varying, p_month_nep character varying) RETURNS text
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_opening_meter NUMERIC;
    v_closing_meter NUMERIC;
    v_total_km NUMERIC;
    v_fuel_allocated NUMERIC;
    v_fuel_used NUMERIC;
    v_balance_fuel NUMERIC;
    v_mileage_avg NUMERIC;
    v_fuel_standard NUMERIC;
    v_performance VARCHAR(50);
    v_overuse_flag BOOLEAN;
    v_result TEXT;
BEGIN
    -- Get opening meter (FIRST log's start_meter)
    SELECT start_meter INTO v_opening_meter
    FROM vehicle_daily_logs
    WHERE vehicle_id = p_vehicle_id
      AND fiscal_year = p_fiscal_year
      AND month_nep = p_month_nep
      AND deleted_at IS NULL
      AND start_meter IS NOT NULL
      AND start_meter > 0
    ORDER BY log_date_eng ASC, log_id ASC
    LIMIT 1;
    
    -- Get closing meter (LAST log's end_meter)
    SELECT end_meter INTO v_closing_meter
    FROM vehicle_daily_logs
    WHERE vehicle_id = p_vehicle_id
      AND fiscal_year = p_fiscal_year
      AND month_nep = p_month_nep
      AND deleted_at IS NULL
      AND end_meter IS NOT NULL
      AND end_meter > 0
    ORDER BY log_date_eng DESC, log_id DESC
    LIMIT 1;
    
    -- Calculate total KM (SUM of all distances)
    SELECT COALESCE(SUM(end_meter - start_meter), 0) INTO v_total_km
    FROM vehicle_daily_logs
    WHERE vehicle_id = p_vehicle_id
      AND fiscal_year = p_fiscal_year
      AND month_nep = p_month_nep
      AND deleted_at IS NULL
      AND end_meter IS NOT NULL
      AND start_meter IS NOT NULL
      AND end_meter > start_meter;
    
    -- Get fuel allocated
    SELECT COALESCE(SUM(fc.allocated_qty + fc.carry_forward_qty), 0) INTO v_fuel_allocated
    FROM fuel_coupons fc
    WHERE fc.vehicle_id = p_vehicle_id
      AND fc.fiscal_year = p_fiscal_year
      AND fc.month_nep = p_month_nep
      AND fc.deleted_at IS NULL;
    
    -- Get fuel used (from distributions)
    SELECT COALESCE(SUM(fcd.disburse_qty), 0) INTO v_fuel_used
    FROM fuel_coupon_distributions fcd
    JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
    WHERE fc.vehicle_id = p_vehicle_id
      AND fc.fiscal_year = p_fiscal_year
      AND fc.month_nep = p_month_nep
      AND fcd.deleted_at IS NULL
      AND fc.deleted_at IS NULL;
    
    -- Calculate balance
    v_balance_fuel := v_fuel_allocated - v_fuel_used;
    
    -- Calculate mileage
    IF v_fuel_used > 0 AND v_total_km > 0 THEN
        v_mileage_avg := v_total_km / v_fuel_used;
    ELSE
        v_mileage_avg := 0;
    END IF;
    
    -- Get standard mileage from vehicle
    SELECT fuel_per_liter_standard INTO v_fuel_standard
    FROM vehicles
    WHERE vehicle_id = p_vehicle_id;
    
    -- Determine performance
    IF v_mileage_avg >= v_fuel_standard THEN
        v_performance := 'Above Standard';
    ELSIF v_mileage_avg >= (v_fuel_standard * 0.9) THEN
        v_performance := 'At Standard';
    ELSE
        v_performance := 'Below Standard';
    END IF;
    
    -- Check overuse
    v_overuse_flag := (v_balance_fuel < 0);
    
    -- Set defaults for NULL values
    v_opening_meter := COALESCE(v_opening_meter, 0);
    v_closing_meter := COALESCE(v_closing_meter, 0);
    v_total_km := COALESCE(v_total_km, 0);
    v_fuel_allocated := COALESCE(v_fuel_allocated, 0);
    v_fuel_used := COALESCE(v_fuel_used, 0);
    v_balance_fuel := COALESCE(v_balance_fuel, 0);
    v_mileage_avg := COALESCE(v_mileage_avg, 0);
    v_fuel_standard := COALESCE(v_fuel_standard, 0);
    
    -- Delete existing summary if any
    DELETE FROM monthly_vehicle_summary
    WHERE vehicle_id = p_vehicle_id
      AND fiscal_year = p_fiscal_year
      AND month_nep = p_month_nep;
    
    -- Insert new summary (ALL columns match table structure)
    INSERT INTO monthly_vehicle_summary (
        vehicle_id, 
        fiscal_year, 
        month_nep,
        opening_meter, 
        closing_meter, 
        total_km,
        total_fuel_allocated, 
        total_fuel_used, 
        balance_fuel,
        mileage_avg, 
        fuel_per_liter_standard,
        performance_status, 
        overuse_flag,
        created_by
    ) VALUES (
        p_vehicle_id, 
        p_fiscal_year, 
        p_month_nep,
        v_opening_meter, 
        v_closing_meter, 
        v_total_km,
        v_fuel_allocated, 
        v_fuel_used, 
        v_balance_fuel,
        v_mileage_avg, 
        v_fuel_standard,
        v_performance, 
        v_overuse_flag,
        1
    );
    
    v_result := FORMAT('✅ Summary: Vehicle %s, %s %s - KM: %s, Fuel: %s L, Mileage: %s km/L',
                       p_vehicle_id, p_month_nep, p_fiscal_year, 
                       v_total_km, v_fuel_used, ROUND(v_mileage_avg, 2));
    
    RETURN v_result;
    
EXCEPTION
    WHEN OTHERS THEN
        RAISE NOTICE 'Error: %', SQLERRM;
        RETURN 'ERROR: ' || SQLERRM;
END;
$$;


ALTER FUNCTION public.calculate_monthly_vehicle_summary(p_vehicle_id integer, p_fiscal_year character varying, p_month_nep character varying) OWNER TO postgres;

--
-- Name: calculate_ot_hours(numeric, numeric, boolean, boolean); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.calculate_ot_hours(p_working_hours numeric, p_standard_hours numeric DEFAULT 8.0, p_is_holiday boolean DEFAULT false, p_is_weekend boolean DEFAULT false) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_ot_hours DECIMAL;
    v_min_hours DECIMAL;
BEGIN
    -- Determine minimum hours based on day type
    IF p_is_holiday OR p_is_weekend THEN
        v_min_hours := 0; -- All hours are OT on holidays/weekends
    ELSE
        v_min_hours := p_standard_hours;
    END IF;
    
    -- Calculate OT hours
    v_ot_hours := GREATEST(0, p_working_hours - v_min_hours);
    
    RETURN ROUND(v_ot_hours, 2);
END;
$$;


ALTER FUNCTION public.calculate_ot_hours(p_working_hours numeric, p_standard_hours numeric, p_is_holiday boolean, p_is_weekend boolean) OWNER TO postgres;

--
-- Name: calculate_working_hours(time without time zone, time without time zone, numeric); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.calculate_working_hours(p_check_in time without time zone, p_check_out time without time zone, p_break_hours numeric DEFAULT 0) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_total_minutes INTEGER;
    v_break_minutes INTEGER;
    v_working_hours DECIMAL;
BEGIN
    IF p_check_in IS NULL OR p_check_out IS NULL THEN
        RETURN 0;
    END IF;
    
    -- Calculate total minutes between check-in and check-out
    v_total_minutes := EXTRACT(EPOCH FROM (p_check_out - p_check_in)) / 60;
    
    -- Convert break hours to minutes
    v_break_minutes := p_break_hours * 60;
    
    -- Calculate working hours
    v_working_hours := (v_total_minutes - v_break_minutes) / 60.0;
    
    -- Return maximum of 0 (in case of negative values)
    RETURN GREATEST(0, ROUND(v_working_hours, 2));
END;
$$;


ALTER FUNCTION public.calculate_working_hours(p_check_in time without time zone, p_check_out time without time zone, p_break_hours numeric) OWNER TO postgres;

--
-- Name: cleanup_zkteco_logs(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.cleanup_zkteco_logs() RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_retention_days INTEGER;
    v_deleted INTEGER;
BEGIN
    -- Get retention setting from zkteco_settings
    SELECT setting_value::INTEGER INTO v_retention_days
    FROM public.zkteco_settings
    WHERE setting_key = 'clear_device_after_days';
    
    IF v_retention_days IS NULL THEN
        v_retention_days := 30;
    END IF;
    
    -- Delete old logs
    DELETE FROM public.zkteco_pull_log
    WHERE pull_date < CURRENT_DATE - v_retention_days;
    
    GET DIAGNOSTICS v_deleted = ROW_COUNT;
    
    RETURN v_deleted;
END;
$$;


ALTER FUNCTION public.cleanup_zkteco_logs() OWNER TO postgres;

--
-- Name: enforce_single_active_fiscal_year(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.enforce_single_active_fiscal_year() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF NEW.is_active THEN
        UPDATE fiscal_years SET is_active = FALSE WHERE id != NEW.id;
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.enforce_single_active_fiscal_year() OWNER TO postgres;

--
-- Name: fn_set_month_nep(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.fn_set_month_nep() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_month_num   INT;
    v_month_name  VARCHAR(20);
    v_date_str    VARCHAR(20);
BEGIN
    -- Only run when month_nep is NULL or empty, or when log_date_nep changed
    IF (TG_OP = 'INSERT' AND (NEW.month_nep IS NULL OR TRIM(NEW.month_nep) = ''))
    OR (TG_OP = 'UPDATE' AND (
           NEW.log_date_nep IS DISTINCT FROM OLD.log_date_nep
        OR NEW.month_nep IS NULL
        OR TRIM(NEW.month_nep) = ''
    )) THEN

        v_date_str := TRIM(NEW.log_date_nep);

        -- Normalise separator to '.' then split: YYYY.MM.DD
        v_date_str := REPLACE(REPLACE(v_date_str, '-', '.'), '/', '.');

        -- Extract middle part (month number)
        -- Supports both  YYYY.MM.DD  and  YY.MM.DD
        BEGIN
            v_month_num := SPLIT_PART(v_date_str, '.', 2)::INT;
        EXCEPTION WHEN OTHERS THEN
            v_month_num := NULL;
        END;

        -- Map number → name
        v_month_name := CASE v_month_num
            WHEN  1 THEN 'Baishakh'
            WHEN  2 THEN 'Jestha'
            WHEN  3 THEN 'Ashadh'
            WHEN  4 THEN 'Shrawan'
            WHEN  5 THEN 'Bhadra'
            WHEN  6 THEN 'Ashwin'
            WHEN  7 THEN 'Kartik'
            WHEN  8 THEN 'Mangsir'
            WHEN  9 THEN 'Poush'
            WHEN 10 THEN 'Magh'
            WHEN 11 THEN 'Falgun'
            WHEN 12 THEN 'Chaitra'
            ELSE NULL
        END;

        NEW.month_nep := v_month_name;
    END IF;

    RETURN NEW;
END;
$$;


ALTER FUNCTION public.fn_set_month_nep() OWNER TO postgres;

--
-- Name: generate_employee_code(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.generate_employee_code() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
DECLARE
    emp_type_prefix VARCHAR(5);
    next_num INTEGER;
    new_code VARCHAR(20);
BEGIN
    -- If code is already provided, don't override
    IF NEW.code IS NOT NULL AND NEW.code != '' THEN
        RETURN NEW;
    END IF;
    
    -- Determine prefix based on employee type
    CASE NEW.emp_type
        WHEN 'PERMANENT' THEN emp_type_prefix := 'P';
        WHEN 'CONTRACT' THEN emp_type_prefix := 'C';
        WHEN 'DAILY_WAGES' THEN emp_type_prefix := 'DW';
        ELSE emp_type_prefix := 'O'; -- Other
    END CASE;
    
    -- Get next sequence number for this type
    SELECT COALESCE(MAX(
        CAST(SUBSTRING(code FROM '[0-9]+$') AS INTEGER)
    ), 0) + 1 INTO next_num
    FROM employee
    WHERE code ~ ('^EMP-' || emp_type_prefix || '-[0-9]+$')
      AND deleted_date IS NULL;
    
    -- Generate final code: EMP-TYPE-0001
    new_code := 'EMP-' || emp_type_prefix || '-' || LPAD(next_num::TEXT, 4, '0');
    
    -- Ensure uniqueness (in case of race condition)
    WHILE EXISTS (SELECT 1 FROM employee WHERE code = new_code AND deleted_date IS NULL) LOOP
        next_num := next_num + 1;
        new_code := 'EMP-' || emp_type_prefix || '-' || LPAD(next_num::TEXT, 4, '0');
    END LOOP;
    
    NEW.code := new_code;
    RETURN NEW;
END;
$_$;


ALTER FUNCTION public.generate_employee_code() OWNER TO postgres;

--
-- Name: get_current_fuel_price(character varying, date); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.get_current_fuel_price(p_fuel_type character varying, p_date date DEFAULT CURRENT_DATE) RETURNS numeric
    LANGUAGE plpgsql
    AS $$
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
$$;


ALTER FUNCTION public.get_current_fuel_price(p_fuel_type character varying, p_date date) OWNER TO postgres;

--
-- Name: get_deno_details_for_d2m_item(integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.get_deno_details_for_d2m_item(p_d2m_item_id integer) RETURNS TABLE(deno_id integer, ref_no character varying, per_poka_qty bigint, poka_qty bigint, total_qty bigint, quantity_openpcs integer, created_by character varying)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    SELECT 
        d.id,
        d.ref_no,
        d.per_poka_qty,
        d.poka_qty,
        d.total_qty,
        d.quantity_openpcs,
        d.created_by::VARCHAR
    FROM deno d
    WHERE d.id = ANY(
        SELECT unnest(string_to_array(
            (SELECT associated_deno_ids FROM d2m_items WHERE id = p_d2m_item_id),
            ','
        ))::INTEGER
    );
END;
$$;


ALTER FUNCTION public.get_deno_details_for_d2m_item(p_d2m_item_id integer) OWNER TO postgres;

--
-- Name: get_maintenance_alerts(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.get_maintenance_alerts() RETURNS TABLE(vehicle_id integer, vehicle_no text, maintenance_type_name text, alert_type text, alert_message text, next_due_km integer, next_due_date_eng date, current_meter integer, days_remaining integer)
    LANGUAGE plpgsql
    AS $$
BEGIN
    RETURN QUERY
    SELECT
        v.vehicle_id,

        /* FIX 1: CAST VARCHAR → TEXT */
        v.vehicle_no::TEXT AS vehicle_no,

        /* FIX 2: CAST VARCHAR → TEXT */
        mt.type_name::TEXT AS maintenance_type_name,

        /* ---------- ALERT TYPE ---------- */
        CASE
            WHEN vmr.next_due_km IS NOT NULL
                 AND vdl.current_meter >= vmr.next_due_km
                THEN 'OVERDUE_KM'
            WHEN vmr.next_due_km IS NOT NULL
                 AND vdl.current_meter >= vmr.next_due_km - 500
                THEN 'DUE_SOON_KM'
            WHEN vmr.next_due_date_eng IS NOT NULL
                 AND vmr.next_due_date_eng < CURRENT_DATE
                THEN 'OVERDUE_DATE'
            WHEN vmr.next_due_date_eng IS NOT NULL
                 AND vmr.next_due_date_eng <= CURRENT_DATE + INTERVAL '30 days'
                THEN 'DUE_SOON_DATE'
            ELSE 'OK'
        END AS alert_type,

        /* ---------- ALERT MESSAGE ---------- */
        CASE
            WHEN vmr.next_due_km IS NOT NULL
                 AND vdl.current_meter >= vmr.next_due_km
                THEN 'OVERDUE by ' || (vdl.current_meter - vmr.next_due_km) || ' km'
            WHEN vmr.next_due_km IS NOT NULL
                 AND vdl.current_meter >= vmr.next_due_km - 500
                THEN 'Due in ' || (vmr.next_due_km - vdl.current_meter) || ' km'
            WHEN vmr.next_due_date_eng IS NOT NULL
                 AND vmr.next_due_date_eng < CURRENT_DATE
                THEN 'OVERDUE by ' || (CURRENT_DATE - vmr.next_due_date_eng) || ' days'
            WHEN vmr.next_due_date_eng IS NOT NULL
                 AND vmr.next_due_date_eng <= CURRENT_DATE + INTERVAL '30 days'
                THEN 'Due in ' || (vmr.next_due_date_eng - CURRENT_DATE) || ' days'
            ELSE 'Up to date'
        END AS alert_message,

        vmr.next_due_km,
        vmr.next_due_date_eng,
        vdl.current_meter,

        /* ---------- DAYS REMAINING ---------- */
        CASE
            WHEN vmr.next_due_date_eng IS NOT NULL
                THEN (vmr.next_due_date_eng - CURRENT_DATE)::INT
            ELSE NULL
        END AS days_remaining

    FROM vehicles v

    LEFT JOIN (
        SELECT
            vdl2.vehicle_id,
            MAX(vdl2.end_meter) AS current_meter
        FROM vehicle_daily_logs vdl2
        WHERE vdl2.deleted_at IS NULL
        GROUP BY vdl2.vehicle_id
    ) vdl
        ON v.vehicle_id = vdl.vehicle_id

    LEFT JOIN vehicle_maintenance_records vmr
        ON v.vehicle_id = vmr.vehicle_id
        AND vmr.deleted_at IS NULL
        AND vmr.status = 'completed'

    LEFT JOIN maintenance_types mt
        ON vmr.maintenance_type_id = mt.maintenance_type_id

    WHERE v.deleted_at IS NULL
      AND v.status = TRUE
      AND (
            (vmr.next_due_km IS NOT NULL
             AND vdl.current_meter >= vmr.next_due_km - 500)
         OR (vmr.next_due_date_eng IS NOT NULL
             AND vmr.next_due_date_eng <= CURRENT_DATE + INTERVAL '30 days')
      )

    ORDER BY
        CASE
            WHEN vmr.next_due_km IS NOT NULL
                 AND vdl.current_meter >= vmr.next_due_km THEN 1
            WHEN vmr.next_due_date_eng IS NOT NULL
                 AND vmr.next_due_date_eng < CURRENT_DATE THEN 1
            ELSE 2
        END,
        v.vehicle_no;
END;
$$;


ALTER FUNCTION public.get_maintenance_alerts() OWNER TO postgres;

--
-- Name: get_month_nep_from_date(character varying); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.get_month_nep_from_date(nep_date character varying) RETURNS character varying
    LANGUAGE plpgsql IMMUTABLE
    AS $_$
DECLARE
    month_part TEXT;
    month_num  INTEGER;
BEGIN
    -- Guard: return NULL if input is null or empty
    IF nep_date IS NULL OR TRIM(nep_date) = '' THEN
        RETURN NULL;
    END IF;

    month_part := SPLIT_PART(nep_date, '.', 2);

    -- Guard: return NULL if month part is missing or not numeric
    IF month_part IS NULL OR TRIM(month_part) = '' OR month_part !~ '^\d+$' THEN
        RETURN NULL;
    END IF;

    month_num := CAST(month_part AS INTEGER);

    RETURN CASE month_num
        WHEN 1  THEN 'Baishakh'
        WHEN 2  THEN 'Jestha'
        WHEN 3  THEN 'Ashadh'
        WHEN 4  THEN 'Shrawan'
        WHEN 5  THEN 'Bhadra'
        WHEN 6  THEN 'Ashwin'
        WHEN 7  THEN 'Kartik'
        WHEN 8  THEN 'Mangsir'
        WHEN 9  THEN 'Poush'
        WHEN 10 THEN 'Magh'
        WHEN 11 THEN 'Falgun'
        WHEN 12 THEN 'Chaitra'
        ELSE NULL
    END;
END;
$_$;


ALTER FUNCTION public.get_month_nep_from_date(nep_date character varying) OWNER TO postgres;

--
-- Name: get_next_d2m_serial(character varying, character, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.get_next_d2m_serial(p_nep_date character varying, p_type character, p_fy integer) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE next_serial INTEGER;
BEGIN
    SELECT COALESCE(MAX(serial_no), 0) + 1
    INTO next_serial
    FROM d2m
    WHERE nep_date = p_nep_date
      AND d2m_type = p_type
      AND fiscal_year_id = p_fy
      AND deleted_at IS NULL;

    RETURN next_serial;
END;
$$;


ALTER FUNCTION public.get_next_d2m_serial(p_nep_date character varying, p_type character, p_fy integer) OWNER TO postgres;

--
-- Name: get_next_maintenance_due(integer, integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.get_next_maintenance_due(p_vehicle_id integer, p_maintenance_type_id integer) RETURNS TABLE(next_due_km integer, next_due_date date)
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_last_meter INT;
    v_last_date DATE;
    v_interval_km INT;
    v_interval_months INT;
BEGIN
    -- Get last maintenance
    SELECT meter_reading, maintenance_date_eng
    INTO v_last_meter, v_last_date
    FROM vehicle_maintenance_records
    WHERE vehicle_id = p_vehicle_id
      AND maintenance_type_id = p_maintenance_type_id
      AND deleted_at IS NULL
    ORDER BY maintenance_date_eng DESC
    LIMIT 1;
    
    -- Get maintenance intervals
    SELECT default_interval_km, default_interval_months
    INTO v_interval_km, v_interval_months
    FROM maintenance_types
    WHERE maintenance_type_id = p_maintenance_type_id;
    
    -- Calculate next due
    IF v_last_meter IS NOT NULL AND v_interval_km IS NOT NULL THEN
        next_due_km := v_last_meter + v_interval_km;
    END IF;
    
    IF v_last_date IS NOT NULL AND v_interval_months IS NOT NULL THEN
        next_due_date := v_last_date + (v_interval_months || ' months')::INTERVAL;
    END IF;
    
    RETURN NEXT;
END;
$$;


ALTER FUNCTION public.get_next_maintenance_due(p_vehicle_id integer, p_maintenance_type_id integer) OWNER TO postgres;

--
-- Name: import_deno_from_staging(); Type: PROCEDURE; Schema: public; Owner: postgres
--

CREATE PROCEDURE public.import_deno_from_staging()
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_inserted  INT := 0;
    v_skipped   INT := 0;
    v_total     INT := 0;
BEGIN
    SELECT COUNT(*) INTO v_total FROM public.deno_staging;

    IF v_total = 0 THEN
        RAISE NOTICE 'Staging table is empty. Load CSV into deno_staging first.';
        RETURN;
    END IF;

    RAISE NOTICE 'Starting import of % rows from staging...', v_total;

    INSERT INTO public.deno_test (
        book_code, ref_no, deno_date_nep, deno_date_eng,
        deno_month, deno_year, per_poka_qty, poka_qty,
        total_qty, quantity_openpcs, notes, updated_at,
        update_remarks, fiscal_year, created_at, deleted_at,
        jt_id, bp_id, d2m_id, entry_type, sender_by,
        created_by, updated_by, received_by, verify_by
    )
    SELECT
        NULLIF(TRIM(book_code), ''),
        NULLIF(TRIM(ref_no), ''),
        NULLIF(TRIM(deno_date_nep), ''),
        NULLIF(TRIM(deno_date_eng), ''),
        NULLIF(TRIM(deno_month), ''),
        NULLIF(TRIM(deno_year), '')::fiscal_year_enum,
        NULLIF(TRIM(per_poka_qty), '')::bigint,
        NULLIF(TRIM(poka_qty), '')::bigint,
        NULLIF(TRIM(total_qty), '')::bigint,
        COALESCE(NULLIF(TRIM(quantity_openpcs), '')::integer, 0),
        NULLIF(TRIM(notes), ''),
        CASE WHEN TRIM(updated_at) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(updated_at)::timestamp END,
        NULLIF(TRIM(update_remarks), ''),
        COALESCE(NULLIF(TRIM(fiscal_year), '')::fiscal_year_enum, '2082'::fiscal_year_enum),
        CASE WHEN TRIM(created_at) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(created_at)::timestamp END,
        CASE WHEN TRIM(deleted_at) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(deleted_at)::timestamp END,
        CASE WHEN TRIM(jt_id) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(jt_id)::integer END,
        CASE WHEN TRIM(bp_id) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(bp_id)::integer END,
        CASE WHEN TRIM(d2m_id) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(d2m_id)::integer END,
        COALESCE(NULLIF(TRIM(entry_type), ''), 'direct'),
        COALESCE(CASE WHEN TRIM(sender_by) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(sender_by)::integer END, 1),
        NULLIF(TRIM(created_by), '')::integer,
        CASE WHEN TRIM(updated_by) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(updated_by)::integer END,
        CASE WHEN TRIM(received_by) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(received_by)::integer END,
        CASE WHEN TRIM(verify_by) IN ('', 'null', 'NULL', 'None') THEN NULL ELSE TRIM(verify_by)::integer END
    FROM public.deno_staging
    WHERE NULLIF(TRIM(book_code), '') IS NOT NULL
      AND NULLIF(TRIM(ref_no), '') IS NOT NULL
      AND NULLIF(TRIM(created_by), '') IS NOT NULL;

    GET DIAGNOSTICS v_inserted = ROW_COUNT;
    v_skipped := v_total - v_inserted;

    TRUNCATE public.deno_staging;

    RAISE NOTICE '✅ Import complete: % inserted, % skipped.', v_inserted, v_skipped;
END;
$$;


ALTER PROCEDURE public.import_deno_from_staging() OWNER TO postgres;

--
-- Name: log_deno_changes(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.log_deno_changes() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_action TEXT;
    v_user_id INTEGER;
    v_ip_address VARCHAR(45);
    v_user_agent TEXT;
BEGIN
    -- Determine the action type
    IF TG_OP = 'INSERT' THEN
        v_action := 'CREATE';
    ELSIF TG_OP = 'UPDATE' THEN
        v_action := 'UPDATE';
    ELSIF TG_OP = 'DELETE' THEN
        v_action := 'DELETE';
    END IF;
    
    -- Get user information with robust error handling
    BEGIN
        v_user_id := NULLIF(current_setting('app.current_user_id', TRUE), '')::INTEGER;
    EXCEPTION WHEN OTHERS THEN
        v_user_id := 40; -- System user as fallback
    END;
    
    BEGIN
        v_ip_address := NULLIF(current_setting('app.client_ip', TRUE), '');
    EXCEPTION WHEN OTHERS THEN
        v_ip_address := '0.0.0.0';
    END;
    
    BEGIN
        v_user_agent := NULLIF(current_setting('app.user_agent', TRUE), '');
    EXCEPTION WHEN OTHERS THEN
        v_user_agent := 'Unknown';
    END;
    
    -- For INSERT actions
    IF TG_OP = 'INSERT' THEN
        INSERT INTO audit_log (
            module_name,
            table_name,
            record_id,
            action,
            changed_by,
            ip_address,
            user_agent
        ) VALUES (
            'Inventory',
            'deno',
            NEW.id,
            v_action,
            COALESCE(v_user_id, 40), -- Ensure NOT NULL constraint is satisfied
            v_ip_address,
            v_user_agent
        );
    
    -- For UPDATE actions - track individual field changes
    ELSIF TG_OP = 'UPDATE' THEN
        -- Track book_code changes
        IF NEW.book_code IS DISTINCT FROM OLD.book_code THEN
            INSERT INTO audit_log (
                module_name,
                table_name,
                record_id,
                action,
                field_name,
                old_value,
                new_value,
                changed_by,
                ip_address,
                user_agent
            ) VALUES (
                'Inventory',
                'deno',
                NEW.id,
                v_action,
                'book_code',
                OLD.book_code,
                NEW.book_code,
                COALESCE(v_user_id, 40),
                v_ip_address,
                v_user_agent
            );
        END IF;
        
        -- Track ref_no changes
        IF NEW.ref_no IS DISTINCT FROM OLD.ref_no THEN
            INSERT INTO audit_log (
                module_name,
                table_name,
                record_id,
                action,
                field_name,
                old_value,
                new_value,
                changed_by,
                ip_address,
                user_agent
            ) VALUES (
                'Inventory',
                'deno',
                NEW.id,
                v_action,
                'ref_no',
                OLD.ref_no,
                NEW.ref_no,
                COALESCE(v_user_id, 40),
                v_ip_address,
                v_user_agent
            );
        END IF;
        
        -- Add similar blocks for other fields you want to track
        
    -- For DELETE actions
    ELSIF TG_OP = 'DELETE' THEN
        INSERT INTO audit_log (
            module_name,
            table_name,
            record_id,
            action,
            changed_by,
            ip_address,
            user_agent
        ) VALUES (
            'Inventory',
            'deno',
            OLD.id,
            v_action,
            COALESCE(v_user_id,40),
            v_ip_address,
            v_user_agent
        );
    END IF;
    
    RETURN (CASE WHEN TG_OP = 'DELETE' THEN OLD ELSE NEW END);
END;
$$;


ALTER FUNCTION public.log_deno_changes() OWNER TO postgres;

--
-- Name: log_device_capacity(integer); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.log_device_capacity(p_device_id integer) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_log_id INTEGER;
    v_users INTEGER;
    v_logs INTEGER;
    v_cap_users INTEGER;
    v_cap_logs INTEGER;
BEGIN
    -- Get current device capacity
    SELECT total_users, total_logs, capacity_users, capacity_logs
    INTO v_users, v_logs, v_cap_users, v_cap_logs
    FROM public.zkteco_devices
    WHERE id = p_device_id;
    
    -- Insert capacity log
    INSERT INTO public.zkteco_capacity_log (
        device_id, users_count, logs_count, 
        capacity_users, capacity_logs,
        users_percentage, logs_percentage
    ) VALUES (
        p_device_id, v_users, v_logs,
        v_cap_users, v_cap_logs,
        CASE WHEN v_cap_users > 0 THEN (v_users::DECIMAL / v_cap_users * 100) ELSE 0 END,
        CASE WHEN v_cap_logs > 0 THEN (v_logs::DECIMAL / v_cap_logs * 100) ELSE 0 END
    )
    RETURNING id INTO v_log_id;
    
    RETURN v_log_id;
END;
$$;


ALTER FUNCTION public.log_device_capacity(p_device_id integer) OWNER TO postgres;

--
-- Name: log_forma_printing_changes(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.log_forma_printing_changes() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    IF TG_OP = 'INSERT' THEN
        INSERT INTO audit_log (
            module_name, table_name, record_id, action, 
            field_name, old_value, new_value, changed_by,
            ip_address, user_agent
        ) VALUES (
            'Forma Printing', 'forma_printing', NEW.id, 'create',
            NULL, NULL, NULL, NEW.created_by,
            NULL, NULL
        );
    ELSIF TG_OP = 'UPDATE' THEN
        IF OLD.name IS DISTINCT FROM NEW.name THEN
            INSERT INTO audit_log (
                module_name, table_name, record_id, action, 
                field_name, old_value, new_value, changed_by,
                ip_address, user_agent
            ) VALUES (
                'Forma Printing', 'forma_printing', NEW.id, 'update',
                'name', OLD.name, NEW.name, NEW.updated_by,
                NULL, NULL
            );
        END IF;
        
        -- Add similar checks for other fields you want to track
        -- Example for status:
        IF OLD.status IS DISTINCT FROM NEW.status THEN
            INSERT INTO audit_log (
                module_name, table_name, record_id, action, 
                field_name, old_value, new_value, changed_by,
                ip_address, user_agent
            ) VALUES (
                'Forma Printing', 'forma_printing', NEW.id, 'update',
                'status', OLD.status::text, NEW.status::text, NEW.updated_by,
                NULL, NULL
            );
        END IF;
        
    ELSIF TG_OP = 'DELETE' THEN
        INSERT INTO audit_log (
            module_name, table_name, record_id, action, 
            field_name, old_value, new_value, changed_by,
            ip_address, user_agent
        ) VALUES (
            'Forma Printing', 'forma_printing', OLD.id, 'delete',
            NULL, NULL, NULL, OLD.delete_by,
            NULL, NULL
        );
    END IF;
    RETURN NULL;
END;
$$;


ALTER FUNCTION public.log_forma_printing_changes() OWNER TO postgres;

--
-- Name: log_zkteco_pull(integer, character varying, integer, integer, integer, integer, integer, integer, numeric, character varying, text); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.log_zkteco_pull(p_device_id integer, p_schedule_type character varying, p_total_records integer, p_inserted integer, p_updated integer, p_skipped integer, p_errors integer, p_employees integer, p_duration numeric, p_status character varying, p_error_message text DEFAULT NULL::text) RETURNS integer
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_log_id INTEGER;
BEGIN
    INSERT INTO public.zkteco_pull_log (
        device_id, pull_date, pull_time, schedule_type,
        total_records, inserted_records, updated_records, 
        skipped_records, error_records, employees_processed,
        status, duration_seconds, error_message, completed_at
    ) VALUES (
        p_device_id, CURRENT_DATE, CURRENT_TIME, p_schedule_type,
        p_total_records, p_inserted, p_updated,
        p_skipped, p_errors, p_employees,
        p_status, p_duration, p_error_message, CURRENT_TIMESTAMP
    )
    RETURNING id INTO v_log_id;
    
    -- Update device last pull info
    UPDATE public.zkteco_devices
    SET 
        last_pull_at = CURRENT_TIMESTAMP,
        last_pull_status = p_status,
        last_pull_records = p_total_records,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_device_id;
    
    RETURN v_log_id;
END;
$$;


ALTER FUNCTION public.log_zkteco_pull(p_device_id integer, p_schedule_type character varying, p_total_records integer, p_inserted integer, p_updated integer, p_skipped integer, p_errors integer, p_employees integer, p_duration numeric, p_status character varying, p_error_message text) OWNER TO postgres;

--
-- Name: set_active_fiscal_year(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.set_active_fiscal_year() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    active_fy_id INTEGER;
BEGIN
    -- If fiscal_year_id is already provided, don't override
    IF NEW.fiscal_year_id IS NOT NULL THEN
        RETURN NEW;
    END IF;
    
    -- Get active fiscal year
    SELECT id INTO active_fy_id
    FROM fiscal_years
    WHERE is_active = TRUE
    LIMIT 1;
    
    -- Set the fiscal year
    IF active_fy_id IS NOT NULL THEN
        NEW.fiscal_year_id := active_fy_id;
    END IF;
    
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.set_active_fiscal_year() OWNER TO postgres;

--
-- Name: trg_calculate_attendance_hours(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.trg_calculate_attendance_hours() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Calculate actual working hours
    NEW.actual_working_hours := calculate_working_hours(
        NEW.check_in_time, 
        NEW.check_out_time, 
        NEW.break_hours
    );
    
    -- Calculate OT hours
    NEW.ot_hours := calculate_ot_hours(
        NEW.actual_working_hours,
        8.0,
        NEW.is_holiday,
        NEW.is_weekly_off
    );
    
    -- Update timestamp
    NEW.updated_at := CURRENT_TIMESTAMP;
    
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.trg_calculate_attendance_hours() OWNER TO postgres;

--
-- Name: trg_set_month_nep(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.trg_set_month_nep() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
    -- Auto-populate month_nep whenever log_date_nep is inserted or changed
    IF TG_OP = 'INSERT' OR (TG_OP = 'UPDATE' AND NEW.log_date_nep IS DISTINCT FROM OLD.log_date_nep) THEN
        NEW.month_nep := get_month_nep_from_date(NEW.log_date_nep);
    END IF;
    RETURN NEW;
END;
$$;


ALTER FUNCTION public.trg_set_month_nep() OWNER TO postgres;

--
-- Name: update_deno_fields(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.update_deno_fields() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_year     int;
    v_month    int;
    v_fy_start int;
    v_fy_name  varchar;   -- e.g. '2081-82'  → stored in fiscal_year
    v_fy_code  varchar;   -- e.g. '2082'     → stored in deno_year
BEGIN
    -- 1. total_qty
    IF NEW.per_poka_qty IS NOT NULL AND NEW.poka_qty IS NOT NULL THEN
        NEW.total_qty := NEW.per_poka_qty * NEW.poka_qty + NEW.quantity_openpcs ; 
    END IF;

    -- 2. deno_month + fiscal year from Nepali date
    IF NEW.deno_date_nep IS NOT NULL THEN
        v_month := substring(NEW.deno_date_nep, 6, 2)::int;

        NEW.deno_month := CASE v_month
            WHEN  1 THEN 'Baishakh'
            WHEN  2 THEN 'Jestha'
            WHEN  3 THEN 'Ashadh'
            WHEN  4 THEN 'Shrawan'
            WHEN  5 THEN 'Bhadra'
            WHEN  6 THEN 'Ashwin'
            WHEN  7 THEN 'Kartik'
            WHEN  8 THEN 'Mangsir'
            WHEN  9 THEN 'Poush'
            WHEN 10 THEN 'Magh'
            WHEN 11 THEN 'Falgun'
            WHEN 12 THEN 'Chaitra'
            ELSE NULL
        END;

        -- Nepali FY: Shrawan(04)–Ashadh(03)
        v_year     := substring(NEW.deno_date_nep, 1, 4)::int;
        v_fy_start := CASE WHEN v_month >= 4 THEN v_year ELSE v_year - 1 END;

        -- Build name string, e.g. 2081 → '2081-82'
        v_fy_name := v_fy_start::text
                     || '-'
                     || lpad(((v_fy_start + 1) % 100)::text, 2, '0');

        -- Lookup fiscal_years table (varchar, no enum)
        SELECT fiscal_code, fiscal_name
        INTO   v_fy_code, v_fy_name
        FROM   public.fiscal_years
        WHERE  fiscal_name = v_fy_name
        LIMIT  1;

        IF v_fy_code IS NOT NULL THEN
            NEW.deno_year   := v_fy_name;   -- e.g. '2082'
            NEW.fiscal_year := v_fy_name;   -- e.g. '2081-82'
        END IF;
    END IF;

    RETURN NEW;
END;
$$;


ALTER FUNCTION public.update_deno_fields() OWNER TO postgres;

--
-- Name: update_device_connection_status(integer, character varying); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.update_device_connection_status(p_device_id integer, p_status character varying) RETURNS void
    LANGUAGE plpgsql
    AS $$
BEGIN
    UPDATE public.zkteco_devices
    SET 
        connection_status = p_status,
        last_online_at = CASE WHEN p_status = 'ONLINE' THEN CURRENT_TIMESTAMP ELSE last_online_at END,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_device_id;
END;
$$;


ALTER FUNCTION public.update_device_connection_status(p_device_id integer, p_status character varying) OWNER TO postgres;

--
-- Name: update_monthly_summary(integer, character varying); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.update_monthly_summary(p_employee_id integer, p_year_month_nep character varying) RETURNS void
    LANGUAGE plpgsql
    AS $$
DECLARE
    v_summary RECORD;
BEGIN
    SELECT 
        COUNT(*) FILTER (WHERE ast.status_code = 'P') as present_days,
        COUNT(*) FILTER (WHERE ast.status_code = 'A') as absent_days,
        COUNT(*) FILTER (WHERE ast.status_code = 'HD') * 0.5 as half_days,
        COUNT(*) FILTER (WHERE ast.status_code IN ('L', 'CL', 'SL', 'PL')) as leave_days,
        COUNT(*) FILTER (WHERE ast.status_code = 'WO') as weekly_offs,
        COUNT(*) FILTER (WHERE ast.status_code = 'PH') as public_holidays,
        COUNT(*) FILTER (WHERE ast.status_code = 'LWP') as lwp_days,
        COALESCE(SUM(a.actual_working_hours), 0) as total_working_hours,
        COALESCE(SUM(a.ot_hours), 0) as total_ot_hours,
        COALESCE(SUM(a.late_arrival_minutes), 0) as total_late_minutes
    INTO v_summary
    FROM attendance a
    JOIN attendance_status ast ON a.status_id = ast.id
    WHERE a.employee_id = p_employee_id
    AND a.attendance_date_nep LIKE p_year_month_nep || '%';
    
    -- Upsert monthly summary
    INSERT INTO attendance_monthly_summary (
        employee_id, year_month_nep, fiscal_year,
        present_days, absent_days, half_days, leave_days,
        weekly_offs, public_holidays, lwp_days,
        total_working_hours, total_ot_hours, total_late_minutes,
        payable_days
    ) VALUES (
        p_employee_id, p_year_month_nep, SPLIT_PART(p_year_month_nep, '.', 1),
        v_summary.present_days, v_summary.absent_days, v_summary.half_days,
        v_summary.leave_days, v_summary.weekly_offs, v_summary.public_holidays,
        v_summary.lwp_days, v_summary.total_working_hours, v_summary.total_ot_hours,
        v_summary.total_late_minutes,
        v_summary.present_days + (v_summary.half_days * 0.5) + v_summary.leave_days
    )
    ON CONFLICT (employee_id, year_month_nep)
    DO UPDATE SET
        present_days = EXCLUDED.present_days,
        absent_days = EXCLUDED.absent_days,
        half_days = EXCLUDED.half_days,
        leave_days = EXCLUDED.leave_days,
        weekly_offs = EXCLUDED.weekly_offs,
        public_holidays = EXCLUDED.public_holidays,
        lwp_days = EXCLUDED.lwp_days,
        total_working_hours = EXCLUDED.total_working_hours,
        total_ot_hours = EXCLUDED.total_ot_hours,
        total_late_minutes = EXCLUDED.total_late_minutes,
        payable_days = EXCLUDED.payable_days,
        updated_at = CURRENT_TIMESTAMP;
END;
$$;


ALTER FUNCTION public.update_monthly_summary(p_employee_id integer, p_year_month_nep character varying) OWNER TO postgres;

--
-- Name: update_timestamp(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.update_timestamp() RETURNS trigger
    LANGUAGE plpgsql
    AS $$
BEGIN
   NEW.updated_at = NOW();
   RETURN NEW;
END;
$$;


ALTER FUNCTION public.update_timestamp() OWNER TO postgres;

--
-- Name: validate_employee_data(); Type: FUNCTION; Schema: public; Owner: postgres
--

CREATE FUNCTION public.validate_employee_data() RETURNS trigger
    LANGUAGE plpgsql
    AS $_$
BEGIN
    -- Validate PAN number format (if provided)
    IF NEW.pan_no IS NOT NULL AND NEW.pan_no != '' THEN
        IF LENGTH(NEW.pan_no) < 9 THEN
            RAISE EXCEPTION 'PAN number must be at least 9 characters';
        END IF;
    END IF;
    
    -- Validate ward number is numeric (if provided)
    IF NEW.ward_no IS NOT NULL AND NEW.ward_no != '' THEN
        IF NEW.ward_no !~ '^[0-9]+$' THEN
            RAISE EXCEPTION 'Ward number must be numeric';
        END IF;
    END IF;
    
    -- Validate email format (if provided)
    IF NEW.email IS NOT NULL AND NEW.email != '' THEN
        IF NEW.email !~ '^[A-Za-z0-9._%+-]+@[A-Za-z0-9.-]+\.[A-Za-z]{2,}$' THEN
            RAISE EXCEPTION 'Invalid email format';
        END IF;
    END IF;
    
    -- Validate mobile number (if provided)
    IF NEW.mobile_number IS NOT NULL AND NEW.mobile_number != '' THEN
        IF LENGTH(NEW.mobile_number) != 10 THEN
            RAISE EXCEPTION 'Mobile number must be 10 digits';
        END IF;
    END IF;
    
    RETURN NEW;
END;
$_$;


ALTER FUNCTION public.validate_employee_data() OWNER TO postgres;

SET default_tablespace = '';

SET default_table_access_method = heap;

--
-- Name: attendance; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.attendance (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    attendance_date_nep character varying(20) NOT NULL,
    attendance_date_eng date NOT NULL,
    shift_id integer,
    status_id integer NOT NULL,
    check_in_time time without time zone,
    check_out_time time without time zone,
    total_hours numeric(5,2) DEFAULT 0,
    break_hours numeric(5,2) DEFAULT 0,
    actual_working_hours numeric(5,2) DEFAULT 0,
    ot_hours numeric(5,2) DEFAULT 0,
    ot_approved boolean DEFAULT false,
    ot_rate numeric(5,2) DEFAULT 1.5,
    late_arrival_minutes integer DEFAULT 0,
    early_departure_minutes integer DEFAULT 0,
    is_holiday boolean DEFAULT false,
    is_weekly_off boolean DEFAULT false,
    remarks text,
    marked_by integer,
    marked_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    approved_by integer,
    approved_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    data_source character varying(20) DEFAULT 'MANUAL'::character varying,
    shift_type character varying(20),
    device_id integer
);


ALTER TABLE public.attendance OWNER TO postgres;

--
-- Name: TABLE attendance; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.attendance IS 'Main attendance table tracking daily employee attendance with time, OT, and status';


--
-- Name: COLUMN attendance.ot_hours; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.attendance.ot_hours IS 'Overtime hours worked beyond standard hours';


--
-- Name: COLUMN attendance.ot_rate; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.attendance.ot_rate IS 'OT rate multiplier (1.5x for weekday, 2x for weekend, 2.5x for holiday)';


--
-- Name: COLUMN attendance.late_arrival_minutes; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.attendance.late_arrival_minutes IS 'Minutes late from shift start time';


--
-- Name: COLUMN attendance.data_source; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.attendance.data_source IS 'MANUAL, ZKTECO, PDF, EXCEL';


--
-- Name: COLUMN attendance.shift_type; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.attendance.shift_type IS 'REGULAR or DUTY_24HR';


--
-- Name: attendance_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.attendance_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.attendance_id_seq OWNER TO postgres;

--
-- Name: attendance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.attendance_id_seq OWNED BY public.attendance.id;


--
-- Name: attendance_monthly_summary; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.attendance_monthly_summary (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    year_month_nep character varying(10) NOT NULL,
    fiscal_year character varying(10) NOT NULL,
    total_working_days integer DEFAULT 0,
    present_days numeric(5,2) DEFAULT 0,
    absent_days numeric(5,2) DEFAULT 0,
    half_days numeric(5,2) DEFAULT 0,
    leave_days numeric(5,2) DEFAULT 0,
    weekly_offs integer DEFAULT 0,
    public_holidays integer DEFAULT 0,
    total_working_hours numeric(8,2) DEFAULT 0,
    total_ot_hours numeric(8,2) DEFAULT 0,
    total_late_minutes integer DEFAULT 0,
    lwp_days numeric(5,2) DEFAULT 0,
    late_deduction_days numeric(5,2) DEFAULT 0,
    payable_days numeric(5,2) DEFAULT 0,
    is_locked boolean DEFAULT false,
    locked_by integer,
    locked_at timestamp without time zone,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.attendance_monthly_summary OWNER TO postgres;

--
-- Name: TABLE attendance_monthly_summary; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.attendance_monthly_summary IS 'Monthly aggregated attendance data for payroll processing';


--
-- Name: COLUMN attendance_monthly_summary.payable_days; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.attendance_monthly_summary.payable_days IS 'Total days eligible for salary payment';


--
-- Name: attendance_monthly_summary_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.attendance_monthly_summary_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.attendance_monthly_summary_id_seq OWNER TO postgres;

--
-- Name: attendance_monthly_summary_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.attendance_monthly_summary_id_seq OWNED BY public.attendance_monthly_summary.id;


--
-- Name: attendance_status; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.attendance_status (
    id integer NOT NULL,
    status_code character varying(10) NOT NULL,
    status_name character varying(50) NOT NULL,
    description text,
    is_present boolean DEFAULT true,
    affects_salary boolean DEFAULT true,
    color_code character varying(7) DEFAULT '#000000'::character varying
);


ALTER TABLE public.attendance_status OWNER TO postgres;

--
-- Name: attendance_status_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.attendance_status_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.attendance_status_id_seq OWNER TO postgres;

--
-- Name: attendance_status_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.attendance_status_id_seq OWNED BY public.attendance_status.id;


--
-- Name: audit_log; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.audit_log (
    id integer NOT NULL,
    module_name character varying(100) NOT NULL,
    table_name character varying(100) NOT NULL,
    record_id integer NOT NULL,
    action character varying(20) NOT NULL,
    field_name character varying(100),
    old_value text,
    new_value text,
    changed_by integer,
    changed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    ip_address character varying(45),
    user_agent text
);


ALTER TABLE public.audit_log OWNER TO postgres;

--
-- Name: audit_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.audit_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.audit_log_id_seq OWNER TO postgres;

--
-- Name: audit_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.audit_log_id_seq OWNED BY public.audit_log.id;


--
-- Name: book_packing; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.book_packing (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    jt_id integer NOT NULL,
    jt_print_qty integer NOT NULL,
    p_qty integer NOT NULL,
    book_code character varying(100) NOT NULL,
    date_nep character varying(20) NOT NULL,
    date_eng character varying(20) NOT NULL,
    supervisor_id integer NOT NULL,
    incharge_id integer NOT NULL,
    operator_id integer NOT NULL,
    status boolean DEFAULT true,
    packing_status character varying(50) DEFAULT 'active'::character varying,
    created_by integer NOT NULL,
    created_date timestamp without time zone DEFAULT now(),
    updated_by integer,
    updated_date timestamp without time zone,
    fiscal_year_id integer NOT NULL,
    remarks text,
    description text
);


ALTER TABLE public.book_packing OWNER TO postgres;

--
-- Name: TABLE book_packing; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.book_packing IS 'Book packing records for completed forma printing jobs';


--
-- Name: COLUMN book_packing.name; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.book_packing.name IS 'Packing record identifier name';


--
-- Name: COLUMN book_packing.jt_print_qty; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.book_packing.jt_print_qty IS 'Total quantity from job ticket that was printed';


--
-- Name: COLUMN book_packing.p_qty; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.book_packing.p_qty IS 'Actual quantity being packed';


--
-- Name: COLUMN book_packing.status; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.book_packing.status IS 'Record status: true=active, false=deleted/inactive';


--
-- Name: COLUMN book_packing.packing_status; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.book_packing.packing_status IS 'Status of packing: active, completed, pending, etc.';


--
-- Name: book_packing_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.book_packing_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.book_packing_id_seq OWNER TO postgres;

--
-- Name: book_packing_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.book_packing_id_seq OWNED BY public.book_packing.id;


--
-- Name: books; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.books (
    book_id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    book_name character varying(255) NOT NULL,
    class_level integer,
    fiscal_year character varying(10) DEFAULT '2082'::character varying NOT NULL,
    is_translated boolean DEFAULT false,
    is_optional boolean DEFAULT false,
    created_by character varying(5) NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    book_type character varying(20) DEFAULT 'TextBook'::character varying,
    business_associated character varying(10) DEFAULT 'CDC'::character varying,
    CONSTRAINT books_book_type_check CHECK (((book_type)::text = ANY ((ARRAY['TextBook'::character varying, 'Copy'::character varying, 'RechargeCard'::character varying, 'Lalpurja'::character varying, 'QuestionPaper'::character varying])::text[]))),
    CONSTRAINT books_business_associated_check CHECK (((business_associated)::text = ANY ((ARRAY['CDC'::character varying, 'JEMC'::character varying, 'NTC'::character varying, 'NEB'::character varying])::text[])))
);


ALTER TABLE public.books OWNER TO postgres;

--
-- Name: books_book_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.books_book_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.books_book_id_seq OWNER TO postgres;

--
-- Name: books_book_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.books_book_id_seq OWNED BY public.books.book_id;


--
-- Name: ctp_export_jobs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ctp_export_jobs (
    id integer NOT NULL,
    job_name character varying(200) NOT NULL,
    book_code character varying(100),
    deno_id integer,
    template_id integer,
    original_pdf text,
    pdf_filename character varying(300),
    total_pages integer DEFAULT 0 NOT NULL,
    padded_pages integer DEFAULT 0 NOT NULL,
    blank_inserted integer DEFAULT 0 NOT NULL,
    layout_type character varying(50) DEFAULT '8up_booklet'::character varying,
    cols integer DEFAULT 4,
    rows integer DEFAULT 2,
    signature_size integer DEFAULT 16,
    sheet_width double precision DEFAULT 720,
    sheet_height double precision DEFAULT 508,
    bleed double precision DEFAULT 3,
    gutter double precision DEFAULT 5,
    trim_outer double precision DEFAULT 8,
    gripper double precision DEFAULT 10,
    head_margin double precision DEFAULT 8,
    foot_margin double precision DEFAULT 8,
    output_pdf text,
    status character varying(30) DEFAULT 'pending'::character varying,
    error_msg text,
    page_order_json text,
    notes text,
    created_by character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.ctp_export_jobs OWNER TO postgres;

--
-- Name: ctp_export_jobs_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ctp_export_jobs_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ctp_export_jobs_id_seq OWNER TO postgres;

--
-- Name: ctp_export_jobs_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ctp_export_jobs_id_seq OWNED BY public.ctp_export_jobs.id;


--
-- Name: d2m; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.d2m (
    id bigint NOT NULL,
    d2m_no character varying(50) NOT NULL,
    serial_no integer NOT NULL,
    d2m_type character(2) NOT NULL,
    fiscal_year_id integer NOT NULL,
    nep_date character varying(10) NOT NULL,
    eng_date date NOT NULL,
    created_by integer NOT NULL,
    checked_by integer,
    verified_by integer,
    updated_by integer,
    deleted_by integer,
    status character varying(20) DEFAULT 'DRAFT'::character varying NOT NULL,
    remarks text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    checked_at timestamp without time zone,
    verified_at timestamp without time zone,
    send_by integer,
    send_by_date date,
    total_quantity integer DEFAULT 0,
    total_books integer DEFAULT 0,
    CONSTRAINT d2m_d2m_type_check CHECK ((d2m_type = ANY (ARRAY['T'::bpchar, 'NT'::bpchar]))),
    CONSTRAINT d2m_status_check CHECK (((status)::text = ANY ((ARRAY['DRAFT'::character varying, 'CHECKED'::character varying, 'VERIFIED'::character varying, 'CANCELLED'::character varying, 'CLOSE'::character varying])::text[])))
);


ALTER TABLE public.d2m OWNER TO postgres;

--
-- Name: COLUMN d2m.total_quantity; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.d2m.total_quantity IS 'Total quantity of books in this D2M';


--
-- Name: COLUMN d2m.total_books; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.d2m.total_books IS 'Total number of different books in this D2M';


--
-- Name: d2m_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.d2m_id_seq
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.d2m_id_seq OWNER TO postgres;

--
-- Name: d2m_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.d2m_id_seq OWNED BY public.d2m.id;


--
-- Name: d2m_items; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.d2m_items (
    id integer NOT NULL,
    d2m_id integer,
    book_code character varying(50) NOT NULL,
    per_poka_qty integer NOT NULL,
    total_poka_qty integer NOT NULL,
    total_qty integer NOT NULL,
    open_pcs integer DEFAULT 0,
    associated_deno_ids text,
    deno_serial_number character varying(50) DEFAULT NULL::character varying
);


ALTER TABLE public.d2m_items OWNER TO postgres;

--
-- Name: COLUMN d2m_items.associated_deno_ids; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.d2m_items.associated_deno_ids IS 'Comma-separated list of DENO IDs that contributed to this D2M item. Format: "123,456,789"';


--
-- Name: COLUMN d2m_items.deno_serial_number; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.d2m_items.deno_serial_number IS 'Serial number from the source DENO record';


--
-- Name: d2m_items_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.d2m_items_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.d2m_items_id_seq OWNER TO postgres;

--
-- Name: d2m_items_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.d2m_items_id_seq OWNED BY public.d2m_items.id;


--
-- Name: deno; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.deno (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    ref_no character varying NOT NULL,
    deno_date_nep character varying(10),
    deno_date_eng character varying(10),
    deno_month character varying(20),
    deno_year character varying,
    per_poka_qty bigint,
    poka_qty bigint,
    total_qty bigint,
    quantity_openpcs integer DEFAULT 0,
    notes character varying,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    update_remarks character varying,
    fiscal_year character varying DEFAULT '2082'::public.fiscal_year_enum NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp without time zone,
    jt_id integer,
    bp_id integer,
    d2m_id integer,
    entry_type character varying(20) DEFAULT 'direct'::character varying,
    sender_by integer DEFAULT 1,
    created_by integer NOT NULL,
    updated_by integer,
    received_by integer,
    verify_by integer,
    CONSTRAINT deno_deno_date_eng_check CHECK (((deno_date_eng)::text ~ '^\d{4}\.\d{2}\.\d{2}$'::text)),
    CONSTRAINT deno_deno_date_nep_check CHECK (((deno_date_nep)::text ~ '^\d{4}\.\d{2}\.\d{2}$'::text)),
    CONSTRAINT deno_entry_type_check CHECK (((entry_type)::text = ANY ((ARRAY['direct'::character varying, 'from_jt'::character varying, 'from_bp'::character varying])::text[])))
);


ALTER TABLE public.deno OWNER TO postgres;

--
-- Name: COLUMN deno.jt_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno.jt_id IS 'Foreign key to job_ticket table';


--
-- Name: COLUMN deno.bp_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno.bp_id IS 'Foreign key to book_packing table';


--
-- Name: COLUMN deno.d2m_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno.d2m_id IS 'Foreign key to d2m table';


--
-- Name: COLUMN deno.entry_type; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno.entry_type IS 'Type of entry: direct, from_jt, from_bp';


--
-- Name: COLUMN deno.sender_by; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno.sender_by IS 'Foreign key to users table - tracks which press user sent this deno entry';


--
-- Name: deno_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.deno_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.deno_id_seq OWNER TO postgres;

--
-- Name: deno_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.deno_id_seq OWNED BY public.deno.id;


--
-- Name: deno_staging; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.deno_staging (
    book_code text,
    ref_no text,
    deno_date_nep text,
    deno_date_eng text,
    deno_month text,
    deno_year text,
    per_poka_qty text,
    poka_qty text,
    total_qty text,
    quantity_openpcs text,
    notes text,
    updated_at text,
    update_remarks text,
    fiscal_year text,
    created_at text,
    deleted_at text,
    jt_id text,
    bp_id text,
    d2m_id text,
    entry_type text,
    sender_by text,
    created_by text,
    updated_by text,
    received_by text,
    verify_by text
);


ALTER TABLE public.deno_staging OWNER TO postgres;

--
-- Name: deno_test; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.deno_test (
    id integer DEFAULT nextval('public.deno_id_seq'::regclass) NOT NULL,
    book_code character varying(50) NOT NULL,
    ref_no character varying NOT NULL,
    deno_date_nep character varying(10),
    deno_date_eng character varying(10),
    deno_month character varying(20),
    deno_year public.fiscal_year_enum,
    per_poka_qty bigint,
    poka_qty bigint,
    total_qty bigint,
    quantity_openpcs integer DEFAULT 0,
    notes character varying,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    update_remarks character varying,
    fiscal_year public.fiscal_year_enum DEFAULT '2082'::public.fiscal_year_enum NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp without time zone,
    jt_id integer,
    bp_id integer,
    d2m_id integer,
    entry_type character varying(20) DEFAULT 'direct'::character varying,
    sender_by integer DEFAULT 1,
    created_by integer NOT NULL,
    updated_by integer,
    received_by integer,
    verify_by integer,
    CONSTRAINT deno_deno_date_eng_check CHECK (((deno_date_eng)::text ~ '^\d{4}\.\d{2}\.\d{2}$'::text)),
    CONSTRAINT deno_deno_date_nep_check CHECK (((deno_date_nep)::text ~ '^\d{4}\.\d{2}\.\d{2}$'::text)),
    CONSTRAINT deno_entry_type_check CHECK (((entry_type)::text = ANY ((ARRAY['direct'::character varying, 'from_jt'::character varying, 'from_bp'::character varying])::text[])))
);


ALTER TABLE public.deno_test OWNER TO postgres;

--
-- Name: COLUMN deno_test.jt_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno_test.jt_id IS 'Foreign key to job_ticket table';


--
-- Name: COLUMN deno_test.bp_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno_test.bp_id IS 'Foreign key to book_packing table';


--
-- Name: COLUMN deno_test.d2m_id; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno_test.d2m_id IS 'Foreign key to d2m table';


--
-- Name: COLUMN deno_test.entry_type; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno_test.entry_type IS 'Type of entry: direct, from_jt, from_bp';


--
-- Name: COLUMN deno_test.sender_by; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.deno_test.sender_by IS 'Foreign key to users table - tracks which press user sent this deno entry';


--
-- Name: department; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.department (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    sub_department_name character varying(255),
    status boolean DEFAULT true,
    remarks character varying(255),
    display_order integer DEFAULT 0,
    is_technical boolean DEFAULT false
);


ALTER TABLE public.department OWNER TO postgres;

--
-- Name: department_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.department_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.department_id_seq OWNER TO postgres;

--
-- Name: department_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.department_id_seq OWNED BY public.department.id;


--
-- Name: designation; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.designation (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    status boolean DEFAULT true,
    is_technical boolean DEFAULT false
);


ALTER TABLE public.designation OWNER TO postgres;

--
-- Name: designation_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.designation_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.designation_id_seq OWNER TO postgres;

--
-- Name: designation_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.designation_id_seq OWNED BY public.designation.id;


--
-- Name: drivers; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.drivers (
    driver_id integer NOT NULL,
    driver_name character varying(100) NOT NULL,
    mobile_no character varying(20),
    license_no character varying(50),
    status boolean DEFAULT true,
    remarks text,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone
);


ALTER TABLE public.drivers OWNER TO postgres;

--
-- Name: drivers_driver_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.drivers_driver_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.drivers_driver_id_seq OWNER TO postgres;

--
-- Name: drivers_driver_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.drivers_driver_id_seq OWNED BY public.drivers.driver_id;


--
-- Name: education_details; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.education_details (
    id integer NOT NULL,
    emp_id integer,
    institution_name character varying(255) NOT NULL,
    degree_name character varying(255) NOT NULL,
    university character varying(255),
    marks numeric(5,2),
    remarks text,
    status boolean DEFAULT true,
    display_order integer DEFAULT 0,
    completion_year integer,
    created_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.education_details OWNER TO postgres;

--
-- Name: education_details_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.education_details_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.education_details_id_seq OWNER TO postgres;

--
-- Name: education_details_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.education_details_id_seq OWNED BY public.education_details.id;


--
-- Name: employee; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.employee (
    id integer NOT NULL,
    code character varying(50) NOT NULL,
    attendance_id character varying(100),
    emp_status character varying(50) DEFAULT 'ACTIVE'::character varying,
    emp_type character varying(50),
    name character varying(255) NOT NULL,
    citizenship_no character varying(100),
    national_id_card_no character varying(100),
    mobile_number character varying(20),
    email character varying(255),
    full_address text,
    join_date character varying(20),
    retirement_date character varying(20),
    initial_appointment_date character varying(20),
    dob character varying(20),
    gender character varying(20),
    picture character varying(255),
    designation_id integer,
    level_id integer,
    department_id integer,
    created_by integer,
    created_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer,
    updated_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_by integer,
    deleted_date timestamp without time zone,
    draft_status character varying(20) DEFAULT 'COMPLETE'::character varying,
    is_technical boolean DEFAULT false,
    pan_no character varying(50),
    bank_name character varying(100),
    bank_branch character varying(100),
    bank_account_number character varying(50),
    name_nep character varying(200),
    name_eng character varying(200),
    local_body character varying(100),
    state character varying(100),
    ward_no character varying(20),
    card_id character varying(50),
    fiscal_year_id integer,
    join_date_nep character varying(20),
    dob_nep character varying(20),
    initial_appointment_date_nep character varying(20),
    retirement_date_nep character varying(20),
    CONSTRAINT chk_emp_status CHECK (((emp_status)::text = ANY ((ARRAY['ACTIVE'::character varying, 'INACTIVE'::character varying, 'RETIRED'::character varying, 'DRAFT'::character varying, 'TERMINATED'::character varying])::text[]))),
    CONSTRAINT chk_emp_type CHECK (((emp_type)::text = ANY ((ARRAY['PERMANENT'::character varying, 'CONTRACT'::character varying, 'DAILY_WAGES'::character varying])::text[]))),
    CONSTRAINT chk_gender CHECK (((gender)::text = ANY ((ARRAY['MALE'::character varying, 'FEMALE'::character varying, 'OTHER'::character varying])::text[])))
);


ALTER TABLE public.employee OWNER TO postgres;

--
-- Name: employee_designation; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.employee_designation (
    id integer NOT NULL,
    emp_id integer,
    date_of_join date NOT NULL,
    date_of_attendance character varying(20),
    date_of_left character varying(20),
    no_of_days integer,
    designation_id integer,
    level_id integer,
    department_id integer,
    status character varying(50) DEFAULT 'ACTIVE'::character varying,
    display_order integer DEFAULT 0,
    remarks text,
    description text,
    documents character varying(255),
    created_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.employee_designation OWNER TO postgres;

--
-- Name: employee_designation_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.employee_designation_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_designation_id_seq OWNER TO postgres;

--
-- Name: employee_designation_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.employee_designation_id_seq OWNED BY public.employee_designation.id;


--
-- Name: employee_documents; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.employee_documents (
    id integer NOT NULL,
    employee_id integer,
    document_name character varying(255) NOT NULL,
    document_type character varying(100),
    file_path character varying(500) NOT NULL,
    file_size integer,
    mime_type character varying(100),
    description text,
    status character varying(20) DEFAULT 'active'::character varying,
    created_by integer,
    created_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer,
    updated_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.employee_documents OWNER TO postgres;

--
-- Name: employee_documents_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.employee_documents_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_documents_id_seq OWNER TO postgres;

--
-- Name: employee_documents_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.employee_documents_id_seq OWNED BY public.employee_documents.id;


--
-- Name: employee_family; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.employee_family (
    id integer NOT NULL,
    emp_id integer,
    name character varying(255) NOT NULL,
    relation character varying(100) NOT NULL,
    contact character varying(20),
    remarks text,
    created_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.employee_family OWNER TO postgres;

--
-- Name: employee_family_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.employee_family_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_family_id_seq OWNER TO postgres;

--
-- Name: employee_family_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.employee_family_id_seq OWNED BY public.employee_family.id;


--
-- Name: employee_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.employee_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.employee_id_seq OWNER TO postgres;

--
-- Name: employee_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.employee_id_seq OWNED BY public.employee.id;


--
-- Name: fctp_books; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fctp_books (
    id integer NOT NULL,
    book_code character varying(100) NOT NULL,
    book_name character varying(300) NOT NULL,
    class character varying(20),
    subject character varying(100),
    total_pages integer DEFAULT 0 NOT NULL,
    master_pdf_path text,
    master_pdf_name character varying(300),
    master_pdf_pages integer DEFAULT 0,
    notes text,
    created_by character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.fctp_books OWNER TO postgres;

--
-- Name: fctp_books_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fctp_books_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fctp_books_id_seq OWNER TO postgres;

--
-- Name: fctp_books_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fctp_books_id_seq OWNED BY public.fctp_books.id;


--
-- Name: fctp_formas; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fctp_formas (
    id integer NOT NULL,
    job_ticket_id integer NOT NULL,
    book_code character varying(100) NOT NULL,
    order_no integer DEFAULT 1 NOT NULL,
    forma_name character varying(50) NOT NULL,
    forma_type character varying(20) DEFAULT 'body'::character varying,
    page_start integer NOT NULL,
    page_end integer NOT NULL,
    page_count integer NOT NULL,
    print_qty integer DEFAULT 0 NOT NULL,
    old_forma_qty integer DEFAULT 0,
    machine character varying(100),
    description text,
    source_pdf_path text,
    source_pdf_type character varying(20) DEFAULT 'master'::character varying,
    source_pdf_pages integer DEFAULT 0,
    layout_type character varying(30) DEFAULT '8up_booklet'::character varying,
    cols integer DEFAULT 4,
    rows integer DEFAULT 2,
    pages_per_plate integer DEFAULT 8,
    pages_per_side integer DEFAULT 4,
    imposition_mode character varying(20) DEFAULT 'sheetwork'::character varying,
    plate_width double precision DEFAULT 720,
    plate_height double precision DEFAULT 508,
    bleed double precision DEFAULT 3,
    gutter double precision DEFAULT 5,
    trim_outer double precision DEFAULT 8,
    gripper double precision DEFAULT 10,
    head_margin double precision DEFAULT 8,
    foot_margin double precision DEFAULT 8,
    spine_margin double precision DEFAULT 5,
    cutting_margin double precision DEFAULT 3,
    plates_required integer DEFAULT 0,
    imposition_json text,
    output_pdf_path text,
    output_status character varying(20) DEFAULT 'pending'::character varying,
    output_generated_at timestamp without time zone,
    notes text,
    created_by character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.fctp_formas OWNER TO postgres;

--
-- Name: fctp_formas_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fctp_formas_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fctp_formas_id_seq OWNER TO postgres;

--
-- Name: fctp_formas_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fctp_formas_id_seq OWNED BY public.fctp_formas.id;


--
-- Name: fctp_imposition_templates; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fctp_imposition_templates (
    id integer NOT NULL,
    template_name character varying(100) NOT NULL,
    layout_type character varying(30) DEFAULT '8up_booklet'::character varying NOT NULL,
    cols integer DEFAULT 4,
    rows integer DEFAULT 2,
    pages_per_plate integer DEFAULT 8,
    pages_per_side integer DEFAULT 4,
    imposition_mode character varying(20) DEFAULT 'sheetwork'::character varying,
    plate_width double precision DEFAULT 720,
    plate_height double precision DEFAULT 508,
    bleed double precision DEFAULT 3,
    gutter double precision DEFAULT 5,
    trim_outer double precision DEFAULT 8,
    gripper double precision DEFAULT 10,
    head_margin double precision DEFAULT 8,
    foot_margin double precision DEFAULT 8,
    spine_margin double precision DEFAULT 5,
    cutting_margin double precision DEFAULT 3,
    is_default boolean DEFAULT false,
    notes text,
    created_by character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.fctp_imposition_templates OWNER TO postgres;

--
-- Name: fctp_imposition_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fctp_imposition_templates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fctp_imposition_templates_id_seq OWNER TO postgres;

--
-- Name: fctp_imposition_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fctp_imposition_templates_id_seq OWNED BY public.fctp_imposition_templates.id;


--
-- Name: fctp_job_tickets; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fctp_job_tickets (
    id integer NOT NULL,
    book_code character varying(100) NOT NULL,
    job_ticket_code character varying(100) NOT NULL,
    fiscal_year character varying(20),
    lot_no integer DEFAULT 1,
    print_qty integer DEFAULT 0 NOT NULL,
    page_qty integer DEFAULT 0 NOT NULL,
    date_nep character varying(20),
    date_eng date,
    status character varying(30) DEFAULT 'active'::character varying,
    notes text,
    created_by character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.fctp_job_tickets OWNER TO postgres;

--
-- Name: fctp_job_tickets_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fctp_job_tickets_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fctp_job_tickets_id_seq OWNER TO postgres;

--
-- Name: fctp_job_tickets_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fctp_job_tickets_id_seq OWNED BY public.fctp_job_tickets.id;


--
-- Name: fctp_uploads; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fctp_uploads (
    id integer NOT NULL,
    forma_id integer,
    book_code character varying(100),
    upload_type character varying(20) DEFAULT 'master'::character varying,
    original_name character varying(300),
    saved_path text NOT NULL,
    file_size_bytes bigint DEFAULT 0,
    page_count integer DEFAULT 0,
    uploaded_by character varying(100),
    uploaded_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.fctp_uploads OWNER TO postgres;

--
-- Name: fctp_uploads_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fctp_uploads_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fctp_uploads_id_seq OWNER TO postgres;

--
-- Name: fctp_uploads_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fctp_uploads_id_seq OWNED BY public.fctp_uploads.id;


--
-- Name: fiscal_years; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fiscal_years (
    id integer NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    start_date date NOT NULL,
    end_date date NOT NULL,
    is_active boolean DEFAULT false,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    fiscal_name character varying
);


ALTER TABLE public.fiscal_years OWNER TO postgres;

--
-- Name: fiscal_years_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fiscal_years_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fiscal_years_id_seq OWNER TO postgres;

--
-- Name: fiscal_years_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fiscal_years_id_seq OWNED BY public.fiscal_years.id;


--
-- Name: forma; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.forma (
    id integer NOT NULL,
    name character varying(100) NOT NULL,
    status character varying(20),
    page integer NOT NULL,
    remarks text,
    order_no integer NOT NULL,
    book_id integer
);


ALTER TABLE public.forma OWNER TO postgres;

--
-- Name: forma_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.forma_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.forma_id_seq OWNER TO postgres;

--
-- Name: forma_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.forma_id_seq OWNED BY public.forma.id;


--
-- Name: forma_printing; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.forma_printing (
    id integer NOT NULL,
    date_nep character varying(20),
    date_eng character varying(20),
    name character varying(255) NOT NULL,
    fiscal_year_id integer NOT NULL,
    jt_id integer,
    jtd_id integer,
    jtd_targetqty bigint,
    fp_printqty bigint,
    fp_remainqty bigint,
    supervisor_id integer,
    created_by integer NOT NULL,
    operator_id integer,
    incharge_id integer,
    shift_id integer,
    machine_id integer,
    remarks text,
    status boolean DEFAULT true,
    created_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer,
    delete_by integer,
    description text,
    updated_date timestamp without time zone
);


ALTER TABLE public.forma_printing OWNER TO postgres;

--
-- Name: forma_printing_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.forma_printing_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.forma_printing_id_seq OWNER TO postgres;

--
-- Name: forma_printing_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.forma_printing_id_seq OWNED BY public.forma_printing.id;


--
-- Name: fuel_coupon_distributions; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fuel_coupon_distributions (
    distribution_id integer NOT NULL,
    coupon_id integer NOT NULL,
    disburse_date_nep character varying(20) NOT NULL,
    disburse_date_eng date NOT NULL,
    disburse_qty numeric(10,2) NOT NULL,
    rate_per_liter numeric(10,2) NOT NULL,
    total_amount numeric(12,2) GENERATED ALWAYS AS ((disburse_qty * rate_per_liter)) STORED,
    verified_flag boolean DEFAULT false,
    remarks text,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone
);


ALTER TABLE public.fuel_coupon_distributions OWNER TO postgres;

--
-- Name: fuel_coupon_distributions_distribution_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fuel_coupon_distributions_distribution_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fuel_coupon_distributions_distribution_id_seq OWNER TO postgres;

--
-- Name: fuel_coupon_distributions_distribution_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fuel_coupon_distributions_distribution_id_seq OWNED BY public.fuel_coupon_distributions.distribution_id;


--
-- Name: fuel_coupons; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fuel_coupons (
    coupon_id integer NOT NULL,
    fiscal_year character varying(9) NOT NULL,
    month_nep character varying(20) NOT NULL,
    vehicle_id integer NOT NULL,
    fuel_type character varying(10),
    allocated_qty numeric(10,2) NOT NULL,
    carry_forward_qty numeric(10,2) DEFAULT 0,
    total_available_qty numeric(10,2) GENERATED ALWAYS AS ((allocated_qty + carry_forward_qty)) STORED,
    issued_date_nep character varying(20),
    issued_date_eng date,
    coupon_no character varying(50),
    pump_name character varying(100),
    verified_with_pump boolean DEFAULT false,
    paid_status boolean DEFAULT false,
    remarks text,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    fuel_expense_type character varying(30),
    CONSTRAINT fuel_coupons_fuel_type_check CHECK (((fuel_type)::text = ANY ((ARRAY['petrol'::character varying, 'diesel'::character varying, 'mobil'::character varying])::text[])))
);


ALTER TABLE public.fuel_coupons OWNER TO postgres;

--
-- Name: fuel_coupons_coupon_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fuel_coupons_coupon_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fuel_coupons_coupon_id_seq OWNER TO postgres;

--
-- Name: fuel_coupons_coupon_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fuel_coupons_coupon_id_seq OWNED BY public.fuel_coupons.coupon_id;


--
-- Name: fuel_price_history; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.fuel_price_history (
    price_id integer NOT NULL,
    fiscal_year character varying(9) NOT NULL,
    month_nep character varying(20) NOT NULL,
    fuel_type character varying(10),
    effective_from_date_nep character varying(20) NOT NULL,
    effective_from_date_eng date NOT NULL,
    effective_to_date_nep character varying(20),
    effective_to_date_eng date,
    rate_per_liter numeric(10,2) NOT NULL,
    source character varying(100),
    notification_no character varying(50),
    is_active boolean DEFAULT true,
    remarks text,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    CONSTRAINT fuel_price_history_fuel_type_check CHECK (((fuel_type)::text = ANY ((ARRAY['petrol'::character varying, 'diesel'::character varying, 'mobil'::character varying])::text[])))
);


ALTER TABLE public.fuel_price_history OWNER TO postgres;

--
-- Name: fuel_price_history_price_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.fuel_price_history_price_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.fuel_price_history_price_id_seq OWNER TO postgres;

--
-- Name: fuel_price_history_price_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.fuel_price_history_price_id_seq OWNED BY public.fuel_price_history.price_id;


--
-- Name: holiday_types; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.holiday_types (
    id integer NOT NULL,
    type_name character varying(100) NOT NULL,
    description text,
    is_paid boolean DEFAULT true,
    color_code character varying(7) DEFAULT '#FF0000'::character varying,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.holiday_types OWNER TO postgres;

--
-- Name: holiday_types_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.holiday_types_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.holiday_types_id_seq OWNER TO postgres;

--
-- Name: holiday_types_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.holiday_types_id_seq OWNED BY public.holiday_types.id;


--
-- Name: holidays; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.holidays (
    id integer NOT NULL,
    holiday_date_nep character varying(20) NOT NULL,
    holiday_date_eng date NOT NULL,
    holiday_name character varying(200) NOT NULL,
    holiday_type_id integer,
    fiscal_year character varying(10) NOT NULL,
    is_active boolean DEFAULT true,
    remarks text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.holidays OWNER TO postgres;

--
-- Name: TABLE holidays; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.holidays IS 'Stores all types of holidays including public holidays, festivals, and special occasions';


--
-- Name: holidays_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.holidays_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.holidays_id_seq OWNER TO postgres;

--
-- Name: holidays_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.holidays_id_seq OWNED BY public.holidays.id;


--
-- Name: imposition_templates; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.imposition_templates (
    id integer NOT NULL,
    template_name character varying(100) NOT NULL,
    signature_size integer,
    pages_per_sheet integer,
    cols integer DEFAULT 4,
    rows integer DEFAULT 2,
    layout_type character varying(50) DEFAULT '8up_booklet'::character varying,
    formula text,
    bleed double precision DEFAULT 3,
    gutter double precision DEFAULT 5,
    trim_outer double precision DEFAULT 8,
    gripper double precision DEFAULT 10,
    head_margin double precision DEFAULT 8,
    foot_margin double precision DEFAULT 8,
    is_default boolean DEFAULT false,
    notes text,
    created_by character varying(100),
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.imposition_templates OWNER TO postgres;

--
-- Name: imposition_templates_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.imposition_templates_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.imposition_templates_id_seq OWNER TO postgres;

--
-- Name: imposition_templates_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.imposition_templates_id_seq OWNED BY public.imposition_templates.id;


--
-- Name: job_ticket; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.job_ticket (
    id integer NOT NULL,
    book_id integer,
    job_ticket_code character varying(50) NOT NULL,
    lot character varying(50),
    remarks text,
    description text,
    print_qty integer NOT NULL,
    page_qty integer NOT NULL,
    class integer,
    date_nep character varying(50),
    date_eng character varying(50),
    print_done_qty integer DEFAULT 0,
    status character varying(20) DEFAULT 'pending'::character varying,
    fiscal_year_id integer,
    created_by integer,
    created_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer,
    updated_date timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.job_ticket OWNER TO postgres;

--
-- Name: job_ticket_details; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.job_ticket_details (
    id integer NOT NULL,
    job_ticket_id integer,
    order_no integer NOT NULL,
    forma_id integer,
    page integer NOT NULL,
    old_forma_qty integer DEFAULT 0,
    print_qty integer NOT NULL,
    machine character varying(100),
    remarks text,
    description text,
    status character varying(20) DEFAULT 'scheduled'::character varying,
    start_date character varying(20),
    end_date character varying(20)
);


ALTER TABLE public.job_ticket_details OWNER TO postgres;

--
-- Name: job_ticket_details_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.job_ticket_details_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.job_ticket_details_id_seq OWNER TO postgres;

--
-- Name: job_ticket_details_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.job_ticket_details_id_seq OWNED BY public.job_ticket_details.id;


--
-- Name: job_ticket_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.job_ticket_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.job_ticket_id_seq OWNER TO postgres;

--
-- Name: job_ticket_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.job_ticket_id_seq OWNED BY public.job_ticket.id;


--
-- Name: leave_balance; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.leave_balance (
    id integer NOT NULL,
    employee_id integer NOT NULL,
    fiscal_year character varying(10) NOT NULL,
    leave_type character varying(50) NOT NULL,
    total_allocated numeric(5,2) DEFAULT 0,
    used_leaves numeric(5,2) DEFAULT 0,
    balance_leaves numeric(5,2) DEFAULT 0,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.leave_balance OWNER TO postgres;

--
-- Name: TABLE leave_balance; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.leave_balance IS 'Leave balance tracking for each employee by fiscal year';


--
-- Name: leave_balance_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.leave_balance_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.leave_balance_id_seq OWNER TO postgres;

--
-- Name: leave_balance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.leave_balance_id_seq OWNED BY public.leave_balance.id;


--
-- Name: level; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.level (
    id integer NOT NULL,
    name character varying(255) NOT NULL,
    status boolean DEFAULT true,
    display_order integer DEFAULT 0,
    remarks character varying(50)
);


ALTER TABLE public.level OWNER TO postgres;

--
-- Name: level_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.level_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.level_id_seq OWNER TO postgres;

--
-- Name: level_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.level_id_seq OWNED BY public.level.id;


--
-- Name: machines; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.machines (
    id integer NOT NULL,
    machine_name character varying(100) NOT NULL,
    status character varying(20) DEFAULT 'active'::character varying
);


ALTER TABLE public.machines OWNER TO postgres;

--
-- Name: machines_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.machines_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.machines_id_seq OWNER TO postgres;

--
-- Name: machines_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.machines_id_seq OWNED BY public.machines.id;


--
-- Name: maintenance_parts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.maintenance_parts (
    part_id integer NOT NULL,
    maintenance_id integer NOT NULL,
    part_name character varying(150) NOT NULL,
    part_number character varying(50),
    quantity integer NOT NULL,
    unit_price numeric(10,2) NOT NULL,
    total_price numeric(10,2) GENERATED ALWAYS AS (((quantity)::numeric * unit_price)) STORED,
    supplier_name character varying(100),
    remarks text,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    deleted_at timestamp without time zone
);


ALTER TABLE public.maintenance_parts OWNER TO postgres;

--
-- Name: maintenance_parts_part_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.maintenance_parts_part_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.maintenance_parts_part_id_seq OWNER TO postgres;

--
-- Name: maintenance_parts_part_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.maintenance_parts_part_id_seq OWNED BY public.maintenance_parts.part_id;


--
-- Name: maintenance_types; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.maintenance_types (
    maintenance_type_id integer NOT NULL,
    type_code character varying(20) NOT NULL,
    type_name character varying(100) NOT NULL,
    description text,
    is_scheduled boolean DEFAULT false,
    default_interval_km integer,
    default_interval_months integer,
    status boolean DEFAULT true,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone
);


ALTER TABLE public.maintenance_types OWNER TO postgres;

--
-- Name: maintenance_types_maintenance_type_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.maintenance_types_maintenance_type_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.maintenance_types_maintenance_type_id_seq OWNER TO postgres;

--
-- Name: maintenance_types_maintenance_type_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.maintenance_types_maintenance_type_id_seq OWNED BY public.maintenance_types.maintenance_type_id;


--
-- Name: monthly_vehicle_summary; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.monthly_vehicle_summary (
    summary_id integer NOT NULL,
    fiscal_year character varying(9) NOT NULL,
    month_nep character varying(20) NOT NULL,
    vehicle_id integer NOT NULL,
    opening_meter integer,
    closing_meter integer,
    total_km integer,
    total_fuel_allocated numeric(10,2),
    total_fuel_used numeric(10,2),
    balance_fuel numeric(10,2),
    mileage_avg numeric(8,2),
    overuse_flag boolean DEFAULT false,
    remarks text,
    created_by integer,
    updated_by integer,
    generated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    fuel_per_liter_standard numeric(10,2),
    performance_status character varying(50)
);


ALTER TABLE public.monthly_vehicle_summary OWNER TO postgres;

--
-- Name: monthly_vehicle_summary_summary_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.monthly_vehicle_summary_summary_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.monthly_vehicle_summary_summary_id_seq OWNER TO postgres;

--
-- Name: monthly_vehicle_summary_summary_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.monthly_vehicle_summary_summary_id_seq OWNED BY public.monthly_vehicle_summary.summary_id;


--
-- Name: ot_rules; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.ot_rules (
    id integer NOT NULL,
    rule_name character varying(100) NOT NULL,
    day_type character varying(20) NOT NULL,
    min_hours_for_ot numeric(5,2) DEFAULT 8.0,
    ot_rate numeric(5,2) DEFAULT 1.5,
    max_ot_hours_per_day numeric(5,2) DEFAULT 4.0,
    requires_approval boolean DEFAULT true,
    is_active boolean DEFAULT true,
    effective_from date,
    effective_to date,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.ot_rules OWNER TO postgres;

--
-- Name: TABLE ot_rules; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.ot_rules IS 'Overtime calculation rules based on day type and working hours';


--
-- Name: ot_rules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.ot_rules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.ot_rules_id_seq OWNER TO postgres;

--
-- Name: ot_rules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.ot_rules_id_seq OWNED BY public.ot_rules.id;


--
-- Name: page_setups; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.page_setups (
    id integer NOT NULL,
    setup_name character varying(100),
    sheet_width double precision NOT NULL,
    sheet_height double precision NOT NULL,
    bleed double precision DEFAULT 3,
    gutter double precision DEFAULT 5,
    trim_outer double precision DEFAULT 8,
    gripper double precision DEFAULT 10,
    head_margin double precision DEFAULT 8,
    foot_margin double precision DEFAULT 8,
    orientation character varying(20) DEFAULT 'landscape'::character varying,
    notes text,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.page_setups OWNER TO postgres;

--
-- Name: page_setups_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.page_setups_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.page_setups_id_seq OWNER TO postgres;

--
-- Name: page_setups_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.page_setups_id_seq OWNED BY public.page_setups.id;


--
-- Name: recon_brt; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_brt (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    price numeric(12,2) DEFAULT 0,
    qty integer DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_brt OWNER TO postgres;

--
-- Name: recon_brt_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_brt_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_brt_id_seq OWNER TO postgres;

--
-- Name: recon_brt_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_brt_id_seq OWNED BY public.recon_brt.id;


--
-- Name: recon_comparative; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_comparative (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    price numeric(18,4) DEFAULT 0,
    qty bigint DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_comparative OWNER TO postgres;

--
-- Name: recon_comparative_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_comparative_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_comparative_id_seq OWNER TO postgres;

--
-- Name: recon_comparative_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_comparative_id_seq OWNED BY public.recon_comparative.id;


--
-- Name: recon_marketing; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_marketing (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    price numeric(18,4) DEFAULT 0,
    qty bigint DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_marketing OWNER TO postgres;

--
-- Name: recon_marketing_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_marketing_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_marketing_id_seq OWNER TO postgres;

--
-- Name: recon_marketing_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_marketing_id_seq OWNED BY public.recon_marketing.id;


--
-- Name: recon_modules; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_modules (
    id integer NOT NULL,
    slug character varying(50) NOT NULL,
    label character varying(100) NOT NULL,
    tbl character varying(100) NOT NULL,
    color character varying(20) DEFAULT '#3b82f6'::character varying,
    icon character varying(10) DEFAULT '📦'::character varying,
    sort_order integer DEFAULT 99,
    is_active boolean DEFAULT true,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_modules OWNER TO postgres;

--
-- Name: recon_modules_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_modules_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_modules_id_seq OWNER TO postgres;

--
-- Name: recon_modules_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_modules_id_seq OWNED BY public.recon_modules.id;


--
-- Name: recon_opening_stock_2080; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_opening_stock_2080 (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    price numeric(12,2) DEFAULT 0,
    qty integer DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_opening_stock_2080 OWNER TO postgres;

--
-- Name: recon_opening_stock_2080_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_opening_stock_2080_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_opening_stock_2080_id_seq OWNER TO postgres;

--
-- Name: recon_opening_stock_2080_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_opening_stock_2080_id_seq OWNED BY public.recon_opening_stock_2080.id;


--
-- Name: recon_pkr; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_pkr (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    price numeric(18,4) DEFAULT 0,
    qty bigint DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_pkr OWNER TO postgres;

--
-- Name: recon_pkr_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_pkr_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_pkr_id_seq OWNER TO postgres;

--
-- Name: recon_pkr_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_pkr_id_seq OWNED BY public.recon_pkr.id;


--
-- Name: recon_software; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_software (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    price numeric(18,4) DEFAULT 0,
    qty bigint DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_software OWNER TO postgres;

--
-- Name: recon_software_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_software_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_software_id_seq OWNER TO postgres;

--
-- Name: recon_software_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_software_id_seq OWNED BY public.recon_software.id;


--
-- Name: recon_stockkeeper; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.recon_stockkeeper (
    id integer NOT NULL,
    book_code character varying(50) NOT NULL,
    fiscal_code character varying(10) NOT NULL,
    price numeric(18,4) DEFAULT 0,
    qty bigint DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.recon_stockkeeper OWNER TO postgres;

--
-- Name: recon_stockkeeper_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.recon_stockkeeper_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.recon_stockkeeper_id_seq OWNER TO postgres;

--
-- Name: recon_stockkeeper_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.recon_stockkeeper_id_seq OWNED BY public.recon_stockkeeper.id;


--
-- Name: shifts; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.shifts (
    id integer NOT NULL,
    name character varying(20),
    remarks text,
    status boolean DEFAULT true
);


ALTER TABLE public.shifts OWNER TO postgres;

--
-- Name: shifts_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.shifts_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.shifts_id_seq OWNER TO postgres;

--
-- Name: shifts_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.shifts_id_seq OWNED BY public.shifts.id;


--
-- Name: system_settings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.system_settings (
    id integer NOT NULL,
    key character varying(100) NOT NULL,
    value text,
    description text,
    setting_type character varying(50) DEFAULT 'STRING'::character varying,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.system_settings OWNER TO postgres;

--
-- Name: system_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.system_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.system_settings_id_seq OWNER TO postgres;

--
-- Name: system_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.system_settings_id_seq OWNED BY public.system_settings.id;


--
-- Name: users; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.users (
    id integer NOT NULL,
    username character varying(50) NOT NULL,
    password_hash character varying(255) NOT NULL,
    role public.user_role DEFAULT 'viewer'::public.user_role NOT NULL,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    last_login timestamp without time zone
);


ALTER TABLE public.users OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.users_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.users_id_seq OWNER TO postgres;

--
-- Name: users_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.users_id_seq OWNED BY public.users.id;


--
-- Name: v_ctp_job_summary; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_ctp_job_summary AS
 SELECT j.id,
    j.job_name,
    j.book_code,
    b.book_name,
    j.layout_type,
    j.cols,
    j.rows,
    j.total_pages,
    j.padded_pages,
    j.blank_inserted,
    ceil(((j.padded_pages)::double precision / (((j.cols * j.rows) * 2))::double precision)) AS sheets_required,
    j.sheet_width,
    j.sheet_height,
    j.bleed,
    j.gutter,
    j.gripper,
    j.status,
    j.created_by,
    j.created_at
   FROM (public.ctp_export_jobs j
     LEFT JOIN public.books b ON (((j.book_code)::text = (b.book_code)::text)));


ALTER VIEW public.v_ctp_job_summary OWNER TO postgres;

--
-- Name: v_d2m_items_with_deno_count; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_d2m_items_with_deno_count AS
 SELECT di.id,
    di.d2m_id,
    di.book_code,
    di.per_poka_qty,
    di.total_poka_qty,
    di.total_qty,
    di.open_pcs,
    di.associated_deno_ids,
    b.book_name,
    b.class_level,
    b.is_translated,
        CASE
            WHEN (di.associated_deno_ids IS NULL) THEN 0
            WHEN (di.associated_deno_ids = ''::text) THEN 0
            ELSE array_length(string_to_array(di.associated_deno_ids, ','::text), 1)
        END AS deno_count
   FROM (public.d2m_items di
     LEFT JOIN public.books b ON (((di.book_code)::text = (b.book_code)::text)));


ALTER VIEW public.v_d2m_items_with_deno_count OWNER TO postgres;

--
-- Name: VIEW v_d2m_items_with_deno_count; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON VIEW public.v_d2m_items_with_deno_count IS 'Shows D2M items with count of associated DENO records';


--
-- Name: v_daily_attendance_report; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_daily_attendance_report AS
 SELECT a.id,
    a.attendance_date_nep,
    a.attendance_date_eng,
    e.code AS employee_code,
    e.name AS employee_name,
    e.name_nep AS employee_name_nep,
    d.name AS designation,
    l.name AS level,
    dept.name AS department,
    s.name AS shift,
    ast.status_code,
    ast.status_name,
    ast.color_code,
    a.check_in_time,
    a.check_out_time,
    a.actual_working_hours,
    a.ot_hours,
    a.late_arrival_minutes,
    a.early_departure_minutes,
    a.is_holiday,
    a.is_weekly_off,
    a.remarks
   FROM ((((((public.attendance a
     JOIN public.employee e ON ((a.employee_id = e.id)))
     LEFT JOIN public.designation d ON ((e.designation_id = d.id)))
     LEFT JOIN public.level l ON ((e.level_id = l.id)))
     LEFT JOIN public.department dept ON ((e.department_id = dept.id)))
     LEFT JOIN public.shifts s ON ((a.shift_id = s.id)))
     JOIN public.attendance_status ast ON ((a.status_id = ast.id)))
  WHERE (e.deleted_date IS NULL)
  ORDER BY a.attendance_date_eng DESC, e.code;


ALTER VIEW public.v_daily_attendance_report OWNER TO postgres;

--
-- Name: v_dashboard_class_distribution; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_dashboard_class_distribution AS
 SELECT class_level AS class,
    count(*) AS total_books,
    count(*) FILTER (WHERE (is_translated = true)) AS translated,
    count(*) FILTER (WHERE ((is_translated = false) OR (is_translated IS NULL))) AS non_translated,
    round((((count(*) FILTER (WHERE (is_translated = true)))::numeric * 100.0) / (NULLIF(count(*), 0))::numeric), 2) AS translation_percentage
   FROM public.books
  WHERE ((fiscal_year)::text = (( SELECT fiscal_years.fiscal_code
           FROM public.fiscal_years
          WHERE (fiscal_years.is_active = true)
         LIMIT 1))::text)
  GROUP BY class_level
  ORDER BY class_level;


ALTER VIEW public.v_dashboard_class_distribution OWNER TO postgres;

--
-- Name: v_dashboard_subject_production; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_dashboard_subject_production AS
 SELECT b.book_name AS subject,
    b.book_code,
    b.class_level,
    COALESCE(sum(d.total_qty), (0)::numeric) AS total_produced,
    COALESCE(sum(d.quantity_openpcs), (0)::bigint) AS total_openpcs,
    count(DISTINCT d.id) AS production_entries
   FROM ((public.deno d
     JOIN public.books b ON (((d.book_code)::text = (b.book_code)::text)))
     JOIN public.fiscal_years fy ON (((b.fiscal_year)::text = (fy.fiscal_code)::text)))
  WHERE (fy.is_active = true)
  GROUP BY b.book_name, b.book_code, b.class_level
  ORDER BY COALESCE(sum(d.total_qty), (0)::numeric) DESC;


ALTER VIEW public.v_dashboard_subject_production OWNER TO postgres;

--
-- Name: v_employee_attendance_stats; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_employee_attendance_stats AS
 SELECT e.id AS employee_id,
    e.code AS employee_code,
    e.name AS employee_name,
    d.name AS designation,
    l.name AS level,
    dept.name AS department,
    count(
        CASE
            WHEN ((ast.status_code)::text = 'P'::text) THEN 1
            ELSE NULL::integer
        END) AS total_present,
    count(
        CASE
            WHEN ((ast.status_code)::text = 'A'::text) THEN 1
            ELSE NULL::integer
        END) AS total_absent,
    count(
        CASE
            WHEN ((ast.status_code)::text = 'HD'::text) THEN 1
            ELSE NULL::integer
        END) AS total_half_days,
    count(
        CASE
            WHEN ((ast.status_code)::text = ANY ((ARRAY['L'::character varying, 'CL'::character varying, 'SL'::character varying, 'PL'::character varying])::text[])) THEN 1
            ELSE NULL::integer
        END) AS total_leaves,
    sum(a.ot_hours) AS total_ot_hours,
    sum(a.late_arrival_minutes) AS total_late_minutes,
    round(avg(a.actual_working_hours), 2) AS avg_working_hours
   FROM (((((public.employee e
     LEFT JOIN public.attendance a ON ((e.id = a.employee_id)))
     LEFT JOIN public.attendance_status ast ON ((a.status_id = ast.id)))
     LEFT JOIN public.designation d ON ((e.designation_id = d.id)))
     LEFT JOIN public.level l ON ((e.level_id = l.id)))
     LEFT JOIN public.department dept ON ((e.department_id = dept.id)))
  WHERE (e.deleted_date IS NULL)
  GROUP BY e.id, e.code, e.name, d.name, l.name, dept.name;


ALTER VIEW public.v_employee_attendance_stats OWNER TO postgres;

--
-- Name: zkteco_devices; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zkteco_devices (
    id integer NOT NULL,
    device_code character varying(50) NOT NULL,
    device_name character varying(100) NOT NULL,
    ip_address character varying(45) NOT NULL,
    port integer DEFAULT 4370,
    location character varying(200),
    device_model character varying(100),
    serial_number character varying(100),
    description text,
    is_active boolean DEFAULT true,
    priority integer DEFAULT 0,
    timeout integer DEFAULT 5,
    disable_during_pull boolean DEFAULT true,
    auto_clear_records boolean DEFAULT false,
    connection_status character varying(20) DEFAULT 'UNKNOWN'::character varying,
    last_online_at timestamp without time zone,
    last_pull_at timestamp without time zone,
    last_pull_status character varying(20),
    last_pull_records integer DEFAULT 0,
    total_users integer DEFAULT 0,
    total_logs integer DEFAULT 0,
    capacity_users integer DEFAULT 0,
    capacity_logs integer DEFAULT 0,
    notes text,
    created_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_by integer,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_connection_status CHECK (((connection_status)::text = ANY ((ARRAY['ONLINE'::character varying, 'OFFLINE'::character varying, 'UNKNOWN'::character varying])::text[]))),
    CONSTRAINT chk_port CHECK (((port > 0) AND (port < 65536)))
);


ALTER TABLE public.zkteco_devices OWNER TO postgres;

--
-- Name: TABLE zkteco_devices; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.zkteco_devices IS 'ZKTeco attendance device configuration';


--
-- Name: COLUMN zkteco_devices.disable_during_pull; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.zkteco_devices.disable_during_pull IS 'Disable device during data pull to prevent new punches';


--
-- Name: COLUMN zkteco_devices.auto_clear_records; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON COLUMN public.zkteco_devices.auto_clear_records IS 'Auto clear old records from device memory';


--
-- Name: zkteco_user_mapping; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zkteco_user_mapping (
    id integer NOT NULL,
    device_id integer NOT NULL,
    device_user_id character varying(50) NOT NULL,
    device_uid integer NOT NULL,
    employee_id integer NOT NULL,
    shift_id integer,
    shift_type character varying(20) DEFAULT 'REGULAR'::character varying,
    is_active boolean DEFAULT true,
    synced_to_device boolean DEFAULT false,
    last_synced_at timestamp without time zone,
    has_fingerprint boolean DEFAULT false,
    fingerprint_count integer DEFAULT 0,
    mapped_by integer,
    mapped_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    notes text,
    CONSTRAINT chk_shift_type CHECK (((shift_type)::text = ANY ((ARRAY['REGULAR'::character varying, 'DUTY_24HR'::character varying, 'FLEXIBLE'::character varying])::text[])))
);


ALTER TABLE public.zkteco_user_mapping OWNER TO postgres;

--
-- Name: TABLE zkteco_user_mapping; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.zkteco_user_mapping IS 'Maps device user IDs to employee records';


--
-- Name: v_employee_device_mapping; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_employee_device_mapping AS
 SELECT e.id AS employee_id,
    e.code AS employee_code,
    e.name AS employee_name,
    e.attendance_id,
    d.device_name,
    d.ip_address,
    zum.device_user_id,
    zum.device_uid,
    zum.shift_type,
    s.name AS shift_name,
    zum.is_active AS mapping_active,
    zum.synced_to_device,
    zum.has_fingerprint,
    zum.fingerprint_count
   FROM (((public.employee e
     LEFT JOIN public.zkteco_user_mapping zum ON ((e.id = zum.employee_id)))
     LEFT JOIN public.zkteco_devices d ON ((zum.device_id = d.id)))
     LEFT JOIN public.shifts s ON ((zum.shift_id = s.id)))
  WHERE (e.deleted_date IS NULL)
  ORDER BY e.code;


ALTER VIEW public.v_employee_device_mapping OWNER TO postgres;

--
-- Name: v_employees_active; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_employees_active AS
 SELECT e.id,
    e.code,
    e.attendance_id,
    e.emp_status,
    e.emp_type,
    e.name,
    e.citizenship_no,
    e.national_id_card_no,
    e.mobile_number,
    e.email,
    e.full_address,
    e.join_date,
    e.retirement_date,
    e.initial_appointment_date,
    e.dob,
    e.gender,
    e.picture,
    e.designation_id,
    e.level_id,
    e.department_id,
    e.created_by,
    e.created_date,
    e.updated_by,
    e.updated_date,
    e.deleted_by,
    e.deleted_date,
    e.draft_status,
    e.is_technical,
    e.pan_no,
    e.bank_name,
    e.bank_branch,
    e.bank_account_number,
    e.name_nep,
    e.name_eng,
    e.local_body,
    e.state,
    e.ward_no,
    e.card_id,
    e.fiscal_year_id,
    e.join_date_nep,
    e.dob_nep,
    e.initial_appointment_date_nep,
    e.retirement_date_nep,
    d.name AS department_name,
    d.sub_department_name,
    des.name AS designation_name,
    l.name AS level_name,
    fy.fiscal_code,
    creator.name AS created_by_name,
    updater.name AS updated_by_name
   FROM ((((((public.employee e
     LEFT JOIN public.department d ON ((e.department_id = d.id)))
     LEFT JOIN public.designation des ON ((e.designation_id = des.id)))
     LEFT JOIN public.level l ON ((e.level_id = l.id)))
     LEFT JOIN public.fiscal_years fy ON ((e.fiscal_year_id = fy.id)))
     LEFT JOIN public.employee creator ON ((e.created_by = creator.id)))
     LEFT JOIN public.employee updater ON ((e.updated_by = updater.id)))
  WHERE (e.deleted_date IS NULL)
  ORDER BY e.name;


ALTER VIEW public.v_employees_active OWNER TO postgres;

--
-- Name: v_fctp_forma_summary; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_fctp_forma_summary AS
 SELECT f.id AS forma_id,
    f.forma_name,
    f.forma_type,
    f.order_no,
    f.page_start,
    f.page_end,
    f.page_count,
    f.print_qty,
    f.layout_type,
    f.cols,
    f.rows,
    f.pages_per_plate,
    f.plates_required,
    f.plate_width,
    f.plate_height,
    f.output_status,
    f.output_generated_at,
    jt.id AS job_ticket_id,
    jt.job_ticket_code,
    jt.fiscal_year,
    jt.lot_no,
    jt.print_qty AS jt_print_qty,
    b.book_code,
    b.book_name,
    b.class,
    b.total_pages,
    b.master_pdf_path,
    f.created_by,
    f.created_at
   FROM ((public.fctp_formas f
     JOIN public.fctp_job_tickets jt ON ((f.job_ticket_id = jt.id)))
     JOIN public.fctp_books b ON (((f.book_code)::text = (b.book_code)::text)));


ALTER VIEW public.v_fctp_forma_summary OWNER TO postgres;

--
-- Name: v_fctp_job_summary; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_fctp_job_summary AS
SELECT
    NULL::integer AS id,
    NULL::character varying(100) AS job_ticket_code,
    NULL::character varying(20) AS fiscal_year,
    NULL::integer AS lot_no,
    NULL::integer AS print_qty,
    NULL::integer AS page_qty,
    NULL::character varying(20) AS date_nep,
    NULL::date AS date_eng,
    NULL::character varying(30) AS status,
    NULL::character varying(100) AS book_code,
    NULL::character varying(300) AS book_name,
    NULL::character varying(20) AS class,
    NULL::integer AS total_pages,
    NULL::text AS master_pdf_path,
    NULL::bigint AS forma_count,
    NULL::bigint AS formas_done,
    NULL::character varying(100) AS created_by,
    NULL::timestamp without time zone AS created_at;


ALTER VIEW public.v_fctp_job_summary OWNER TO postgres;

--
-- Name: vehicles; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicles (
    vehicle_id integer NOT NULL,
    vehicle_no character varying(30) NOT NULL,
    vehicle_type character varying(20),
    fuel_type character varying(10),
    fuel_per_liter_standard numeric(6,2),
    status boolean DEFAULT true,
    remarks text,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    CONSTRAINT vehicles_fuel_type_check CHECK (((fuel_type)::text = ANY ((ARRAY['petrol'::character varying, 'diesel'::character varying, 'mobil'::character varying])::text[]))),
    CONSTRAINT vehicles_vehicle_type_check CHECK (((vehicle_type)::text = ANY (ARRAY[('car'::character varying)::text, ('jeep'::character varying)::text, ('bike'::character varying)::text, ('truck'::character varying)::text, ('generator'::character varying)::text, ('tractor'::character varying)::text, ('forklift'::character varying)::text])))
);


ALTER TABLE public.vehicles OWNER TO postgres;

--
-- Name: v_fuel_coupon_full_details; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_fuel_coupon_full_details AS
 SELECT fc.coupon_id,
    fc.fiscal_year,
    fc.month_nep,
    fc.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    v.fuel_type,
    fc.coupon_no,
    fc.allocated_qty,
    fc.carry_forward_qty,
    fc.total_available_qty,
    fc.issued_date_nep,
    fc.issued_date_eng,
    fc.pump_name,
    fc.verified_with_pump,
    fc.paid_status,
    COALESCE(sum(fcd.disburse_qty), (0)::numeric) AS total_distributed,
    (fc.total_available_qty - COALESCE(sum(fcd.disburse_qty), (0)::numeric)) AS remaining_qty,
    u_created.username AS created_by_username,
    fc.created_at,
    fc.remarks
   FROM (((public.fuel_coupons fc
     LEFT JOIN public.vehicles v ON ((fc.vehicle_id = v.vehicle_id)))
     LEFT JOIN public.fuel_coupon_distributions fcd ON (((fc.coupon_id = fcd.coupon_id) AND (fcd.deleted_at IS NULL))))
     LEFT JOIN public.users u_created ON ((fc.created_by = u_created.id)))
  WHERE (fc.deleted_at IS NULL)
  GROUP BY fc.coupon_id, fc.fiscal_year, fc.month_nep, fc.vehicle_id, v.vehicle_no, v.vehicle_type, v.fuel_type, fc.coupon_no, fc.allocated_qty, fc.carry_forward_qty, fc.total_available_qty, fc.issued_date_nep, fc.issued_date_eng, fc.pump_name, fc.verified_with_pump, fc.paid_status, u_created.username, fc.created_at, fc.remarks;


ALTER VIEW public.v_fuel_coupon_full_details OWNER TO postgres;

--
-- Name: v_fuel_price_current; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_fuel_price_current AS
 SELECT DISTINCT ON (fuel_type) price_id,
    fiscal_year,
    month_nep,
    fuel_type,
    effective_from_date_nep,
    effective_from_date_eng,
    rate_per_liter,
    source,
    notification_no,
    is_active
   FROM public.fuel_price_history
  WHERE ((deleted_at IS NULL) AND (is_active = true) AND (effective_from_date_eng <= CURRENT_DATE) AND ((effective_to_date_eng IS NULL) OR (effective_to_date_eng >= CURRENT_DATE)))
  ORDER BY fuel_type, effective_from_date_eng DESC;


ALTER VIEW public.v_fuel_price_current OWNER TO postgres;

--
-- Name: v_monthly_attendance_summary; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_monthly_attendance_summary AS
 SELECT ams.id,
    ams.year_month_nep,
    ams.fiscal_year,
    e.code AS employee_code,
    e.name AS employee_name,
    e.name_nep AS employee_name_nep,
    d.name AS designation,
    l.name AS level,
    dept.name AS department,
    ams.total_working_days,
    ams.present_days,
    ams.absent_days,
    ams.half_days,
    ams.leave_days,
    ams.weekly_offs,
    ams.public_holidays,
    ams.total_working_hours,
    ams.total_ot_hours,
    ams.lwp_days,
    ams.payable_days,
    ams.is_locked
   FROM ((((public.attendance_monthly_summary ams
     JOIN public.employee e ON ((ams.employee_id = e.id)))
     LEFT JOIN public.designation d ON ((e.designation_id = d.id)))
     LEFT JOIN public.level l ON ((e.level_id = l.id)))
     LEFT JOIN public.department dept ON ((e.department_id = dept.id)))
  WHERE (e.deleted_date IS NULL)
  ORDER BY ams.year_month_nep DESC, e.code;


ALTER VIEW public.v_monthly_attendance_summary OWNER TO postgres;

--
-- Name: v_monthly_summary_full_details; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_monthly_summary_full_details AS
 SELECT mvs.summary_id,
    mvs.fiscal_year,
    mvs.month_nep,
    mvs.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    v.fuel_type,
    v.fuel_per_liter_standard,
    mvs.opening_meter,
    mvs.closing_meter,
    mvs.total_km,
    mvs.total_fuel_allocated,
    mvs.total_fuel_used,
    mvs.balance_fuel,
    mvs.mileage_avg,
    mvs.overuse_flag,
        CASE
            WHEN (mvs.mileage_avg < v.fuel_per_liter_standard) THEN 'Below Standard'::text
            WHEN (mvs.mileage_avg > v.fuel_per_liter_standard) THEN 'Above Standard'::text
            ELSE 'On Standard'::text
        END AS performance_status,
    u_created.username AS created_by_username,
    mvs.generated_at,
    mvs.remarks
   FROM ((public.monthly_vehicle_summary mvs
     LEFT JOIN public.vehicles v ON ((mvs.vehicle_id = v.vehicle_id)))
     LEFT JOIN public.users u_created ON ((mvs.created_by = u_created.id)))
  WHERE (mvs.deleted_at IS NULL)
  ORDER BY mvs.fiscal_year DESC, mvs.month_nep, v.vehicle_no;


ALTER VIEW public.v_monthly_summary_full_details OWNER TO postgres;

--
-- Name: vehicle_driver_assignments; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicle_driver_assignments (
    assignment_id integer NOT NULL,
    vehicle_id integer NOT NULL,
    driver_id integer NOT NULL,
    assigned_from_date_nep character varying(20) NOT NULL,
    assigned_from_date_eng date NOT NULL,
    assigned_to_date_nep character varying(20),
    assigned_to_date_eng date,
    active_flag boolean DEFAULT true,
    remarks text,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone
);


ALTER TABLE public.vehicle_driver_assignments OWNER TO postgres;

--
-- Name: v_vehicle_full_details; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_vehicle_full_details AS
 SELECT v.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    v.fuel_type,
    v.fuel_per_liter_standard,
    v.status,
    v.fiscal_year,
    v.remarks AS vehicle_remarks,
    vda.driver_id AS current_driver_id,
    d.driver_name AS current_driver_name,
    d.mobile_no AS driver_mobile,
    vda.assigned_from_date_nep AS driver_assigned_from_nep,
    vda.assigned_from_date_eng AS driver_assigned_from_eng,
    u_created.username AS created_by_username,
    u_updated.username AS updated_by_username,
    v.created_at,
    v.updated_at
   FROM ((((public.vehicles v
     LEFT JOIN public.vehicle_driver_assignments vda ON (((v.vehicle_id = vda.vehicle_id) AND (vda.active_flag = true) AND (vda.deleted_at IS NULL))))
     LEFT JOIN public.drivers d ON (((vda.driver_id = d.driver_id) AND (d.deleted_at IS NULL))))
     LEFT JOIN public.users u_created ON ((v.created_by = u_created.id)))
     LEFT JOIN public.users u_updated ON ((v.updated_by = u_updated.id)))
  WHERE (v.deleted_at IS NULL);


ALTER VIEW public.v_vehicle_full_details OWNER TO postgres;

--
-- Name: vehicle_daily_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicle_daily_logs (
    log_id integer NOT NULL,
    vehicle_id integer NOT NULL,
    driver_id integer,
    log_date_nep character varying(20) NOT NULL,
    log_date_eng date NOT NULL,
    from_location character varying(150),
    to_location character varying(150),
    start_meter integer NOT NULL,
    end_meter integer NOT NULL,
    total_km integer GENERATED ALWAYS AS ((end_meter - start_meter)) STORED,
    purpose text,
    fuel_used_estimated numeric(8,2),
    remarks text,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    month_nep character varying(20),
    CONSTRAINT chk_meter_reading CHECK ((end_meter >= start_meter))
);


ALTER TABLE public.vehicle_daily_logs OWNER TO postgres;

--
-- Name: v_vehicle_logs_full_details; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_vehicle_logs_full_details AS
 SELECT vdl.log_id,
    vdl.log_date_nep,
    vdl.log_date_eng,
    vdl.fiscal_year,
    vdl.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    vdl.driver_id,
    d.driver_name,
    vdl.from_location,
    vdl.to_location,
    vdl.start_meter,
    vdl.end_meter,
    vdl.total_km,
    vdl.purpose,
    vdl.fuel_used_estimated,
        CASE
            WHEN (vdl.fuel_used_estimated > (0)::numeric) THEN round(((vdl.total_km)::numeric / vdl.fuel_used_estimated), 2)
            ELSE NULL::numeric
        END AS calculated_mileage,
    u_created.username AS created_by_username,
    vdl.created_at,
    vdl.remarks
   FROM (((public.vehicle_daily_logs vdl
     LEFT JOIN public.vehicles v ON ((vdl.vehicle_id = v.vehicle_id)))
     LEFT JOIN public.drivers d ON ((vdl.driver_id = d.driver_id)))
     LEFT JOIN public.users u_created ON ((vdl.created_by = u_created.id)))
  WHERE (vdl.deleted_at IS NULL)
  ORDER BY vdl.log_date_eng DESC, vdl.created_at DESC;


ALTER VIEW public.v_vehicle_logs_full_details OWNER TO postgres;

--
-- Name: vehicle_maintenance_records; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicle_maintenance_records (
    maintenance_id integer NOT NULL,
    vehicle_id integer NOT NULL,
    maintenance_type_id integer NOT NULL,
    maintenance_date_nep character varying(20) NOT NULL,
    maintenance_date_eng date NOT NULL,
    meter_reading integer NOT NULL,
    next_due_km integer,
    next_due_date_nep character varying(20),
    next_due_date_eng date,
    work_description text,
    parts_replaced text,
    service_provider character varying(150),
    mechanic_name character varying(100),
    labor_cost numeric(10,2) DEFAULT 0,
    parts_cost numeric(10,2) DEFAULT 0,
    total_cost numeric(10,2) GENERATED ALWAYS AS ((labor_cost + parts_cost)) STORED,
    payment_status boolean DEFAULT false,
    payment_date_nep character varying(20),
    payment_date_eng date,
    bill_no character varying(50),
    downtime_days integer DEFAULT 0,
    is_warranty boolean DEFAULT false,
    warranty_remarks text,
    status character varying(20) DEFAULT 'completed'::character varying,
    remarks text,
    fiscal_year character varying(9) DEFAULT '2082/83'::character varying NOT NULL,
    created_by integer,
    updated_by integer,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    updated_at timestamp without time zone,
    deleted_at timestamp without time zone,
    CONSTRAINT vehicle_maintenance_records_status_check CHECK (((status)::text = ANY ((ARRAY['pending'::character varying, 'in_progress'::character varying, 'completed'::character varying, 'cancelled'::character varying])::text[])))
);


ALTER TABLE public.vehicle_maintenance_records OWNER TO postgres;

--
-- Name: v_vehicle_maintenance_full_details; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_vehicle_maintenance_full_details AS
 SELECT vmr.maintenance_id,
    vmr.maintenance_date_nep,
    vmr.maintenance_date_eng,
    vmr.fiscal_year,
    vmr.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    v.fuel_type,
    vmr.maintenance_type_id,
    mt.type_code,
    mt.type_name,
    mt.is_scheduled,
    vmr.meter_reading,
    vmr.next_due_km,
    vmr.next_due_date_nep,
    vmr.next_due_date_eng,
    vmr.work_description,
    vmr.parts_replaced,
    vmr.service_provider,
    vmr.mechanic_name,
    vmr.labor_cost,
    vmr.parts_cost,
    vmr.total_cost,
    vmr.payment_status,
    vmr.payment_date_nep,
    vmr.bill_no,
    vmr.downtime_days,
    vmr.is_warranty,
    vmr.status,
    vmr.remarks,
    ( SELECT count(*) AS count
           FROM public.maintenance_parts mp
          WHERE ((mp.maintenance_id = vmr.maintenance_id) AND (mp.deleted_at IS NULL))) AS parts_count,
    u_created.username AS created_by_username,
    vmr.created_at
   FROM (((public.vehicle_maintenance_records vmr
     JOIN public.vehicles v ON ((vmr.vehicle_id = v.vehicle_id)))
     JOIN public.maintenance_types mt ON ((vmr.maintenance_type_id = mt.maintenance_type_id)))
     LEFT JOIN public.users u_created ON ((vmr.created_by = u_created.id)))
  WHERE ((vmr.deleted_at IS NULL) AND (v.deleted_at IS NULL) AND (mt.deleted_at IS NULL))
  ORDER BY vmr.maintenance_date_eng DESC;


ALTER VIEW public.v_vehicle_maintenance_full_details OWNER TO postgres;

--
-- Name: v_vehicle_maintenance_summary; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_vehicle_maintenance_summary AS
 SELECT v.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    count(vmr.maintenance_id) AS total_maintenance_count,
    count(
        CASE
            WHEN ((vmr.status)::text = 'completed'::text) THEN 1
            ELSE NULL::integer
        END) AS completed_count,
    count(
        CASE
            WHEN ((vmr.status)::text = 'pending'::text) THEN 1
            ELSE NULL::integer
        END) AS pending_count,
    count(
        CASE
            WHEN ((vmr.status)::text = 'in_progress'::text) THEN 1
            ELSE NULL::integer
        END) AS in_progress_count,
    COALESCE(sum(vmr.total_cost), (0)::numeric) AS total_maintenance_cost,
    COALESCE(sum(vmr.labor_cost), (0)::numeric) AS total_labor_cost,
    COALESCE(sum(vmr.parts_cost), (0)::numeric) AS total_parts_cost,
    COALESCE(sum(vmr.downtime_days), (0)::bigint) AS total_downtime_days,
    max(vmr.maintenance_date_eng) AS last_maintenance_date_eng,
    max((vmr.maintenance_date_nep)::text) AS last_maintenance_date_nep,
    max(vmr.meter_reading) AS last_maintenance_meter,
    min(vmr.next_due_km) AS next_due_km,
    min(vmr.next_due_date_eng) AS next_due_date_eng
   FROM (public.vehicles v
     LEFT JOIN public.vehicle_maintenance_records vmr ON (((v.vehicle_id = vmr.vehicle_id) AND (vmr.deleted_at IS NULL))))
  WHERE (v.deleted_at IS NULL)
  GROUP BY v.vehicle_id, v.vehicle_no, v.vehicle_type
  ORDER BY v.vehicle_no;


ALTER VIEW public.v_vehicle_maintenance_summary OWNER TO postgres;

--
-- Name: zkteco_pull_log; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zkteco_pull_log (
    id integer NOT NULL,
    device_id integer,
    pull_date date NOT NULL,
    pull_time time without time zone NOT NULL,
    schedule_type character varying(20),
    total_records integer DEFAULT 0,
    inserted_records integer DEFAULT 0,
    updated_records integer DEFAULT 0,
    skipped_records integer DEFAULT 0,
    error_records integer DEFAULT 0,
    employees_processed integer DEFAULT 0,
    status character varying(20) NOT NULL,
    error_message text,
    started_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    completed_at timestamp without time zone,
    duration_seconds numeric(10,2),
    CONSTRAINT chk_pull_status CHECK (((status)::text = ANY ((ARRAY['SUCCESS'::character varying, 'FAILED'::character varying, 'PARTIAL'::character varying, 'RUNNING'::character varying])::text[])))
);


ALTER TABLE public.zkteco_pull_log OWNER TO postgres;

--
-- Name: TABLE zkteco_pull_log; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.zkteco_pull_log IS 'Log of all ZKTeco data pull operations';


--
-- Name: v_zkteco_device_status; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_zkteco_device_status AS
 SELECT d.id,
    d.device_name,
    d.device_code,
    d.ip_address,
    d.port,
    d.location,
    d.is_active,
    d.connection_status,
    d.last_online_at,
    d.last_pull_at,
    d.last_pull_status,
    d.last_pull_records,
    d.total_users,
    d.total_logs,
    d.capacity_users,
    d.capacity_logs,
    count(DISTINCT zum.employee_id) AS mapped_employees,
    ( SELECT count(*) AS count
           FROM public.zkteco_pull_log
          WHERE ((zkteco_pull_log.device_id = d.id) AND (zkteco_pull_log.pull_date = CURRENT_DATE))) AS pulls_today
   FROM (public.zkteco_devices d
     LEFT JOIN public.zkteco_user_mapping zum ON (((d.id = zum.device_id) AND (zum.is_active = true))))
  GROUP BY d.id, d.device_name, d.device_code, d.ip_address, d.port, d.location, d.is_active, d.connection_status, d.last_online_at, d.last_pull_at, d.last_pull_status, d.last_pull_records, d.total_users, d.total_logs, d.capacity_users, d.capacity_logs;


ALTER VIEW public.v_zkteco_device_status OWNER TO postgres;

--
-- Name: v_zkteco_pull_statistics; Type: VIEW; Schema: public; Owner: postgres
--

CREATE VIEW public.v_zkteco_pull_statistics AS
 SELECT pull_date AS date,
    schedule_type,
    count(*) AS total_pulls,
    count(
        CASE
            WHEN ((status)::text = 'SUCCESS'::text) THEN 1
            ELSE NULL::integer
        END) AS successful_pulls,
    count(
        CASE
            WHEN ((status)::text = 'FAILED'::text) THEN 1
            ELSE NULL::integer
        END) AS failed_pulls,
    sum(inserted_records) AS total_inserted,
    sum(updated_records) AS total_updated,
    sum(employees_processed) AS total_employees,
    avg(duration_seconds) AS avg_duration_seconds
   FROM public.zkteco_pull_log
  GROUP BY pull_date, schedule_type
  ORDER BY pull_date DESC, schedule_type;


ALTER VIEW public.v_zkteco_pull_statistics OWNER TO postgres;

--
-- Name: vehicle_audit_logs; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.vehicle_audit_logs (
    audit_id integer NOT NULL,
    table_name character varying(50) NOT NULL,
    record_id integer NOT NULL,
    action character varying(20) NOT NULL,
    old_values jsonb,
    new_values jsonb,
    performed_by character varying(50),
    performed_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    remarks text
);


ALTER TABLE public.vehicle_audit_logs OWNER TO postgres;

--
-- Name: vehicle_audit_logs_audit_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicle_audit_logs_audit_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicle_audit_logs_audit_id_seq OWNER TO postgres;

--
-- Name: vehicle_audit_logs_audit_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicle_audit_logs_audit_id_seq OWNED BY public.vehicle_audit_logs.audit_id;


--
-- Name: vehicle_daily_logs_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicle_daily_logs_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicle_daily_logs_log_id_seq OWNER TO postgres;

--
-- Name: vehicle_daily_logs_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicle_daily_logs_log_id_seq OWNED BY public.vehicle_daily_logs.log_id;


--
-- Name: vehicle_driver_assignments_assignment_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicle_driver_assignments_assignment_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicle_driver_assignments_assignment_id_seq OWNER TO postgres;

--
-- Name: vehicle_driver_assignments_assignment_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicle_driver_assignments_assignment_id_seq OWNED BY public.vehicle_driver_assignments.assignment_id;


--
-- Name: vehicle_maintenance_records_maintenance_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicle_maintenance_records_maintenance_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicle_maintenance_records_maintenance_id_seq OWNER TO postgres;

--
-- Name: vehicle_maintenance_records_maintenance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicle_maintenance_records_maintenance_id_seq OWNED BY public.vehicle_maintenance_records.maintenance_id;


--
-- Name: vehicles_vehicle_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.vehicles_vehicle_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.vehicles_vehicle_id_seq OWNER TO postgres;

--
-- Name: vehicles_vehicle_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.vehicles_vehicle_id_seq OWNED BY public.vehicles.vehicle_id;


--
-- Name: zkteco_capacity_log; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zkteco_capacity_log (
    id integer NOT NULL,
    device_id integer NOT NULL,
    users_count integer DEFAULT 0,
    logs_count integer DEFAULT 0,
    capacity_users integer DEFAULT 0,
    capacity_logs integer DEFAULT 0,
    users_percentage numeric(5,2),
    logs_percentage numeric(5,2),
    logged_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT chk_logs_pct CHECK (((logs_percentage >= (0)::numeric) AND (logs_percentage <= (100)::numeric))),
    CONSTRAINT chk_users_pct CHECK (((users_percentage >= (0)::numeric) AND (users_percentage <= (100)::numeric)))
);


ALTER TABLE public.zkteco_capacity_log OWNER TO postgres;

--
-- Name: TABLE zkteco_capacity_log; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.zkteco_capacity_log IS 'Track device capacity over time';


--
-- Name: zkteco_capacity_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zkteco_capacity_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zkteco_capacity_log_id_seq OWNER TO postgres;

--
-- Name: zkteco_capacity_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zkteco_capacity_log_id_seq OWNED BY public.zkteco_capacity_log.id;


--
-- Name: zkteco_devices_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zkteco_devices_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zkteco_devices_id_seq OWNER TO postgres;

--
-- Name: zkteco_devices_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zkteco_devices_id_seq OWNED BY public.zkteco_devices.id;


--
-- Name: zkteco_pull_log_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zkteco_pull_log_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zkteco_pull_log_id_seq OWNER TO postgres;

--
-- Name: zkteco_pull_log_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zkteco_pull_log_id_seq OWNED BY public.zkteco_pull_log.id;


--
-- Name: zkteco_raw_attendance; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zkteco_raw_attendance (
    id integer NOT NULL,
    device_id integer NOT NULL,
    device_user_id character varying(50) NOT NULL,
    device_uid integer NOT NULL,
    punch_time timestamp without time zone NOT NULL,
    punch_state integer,
    punch_type character varying(20),
    is_processed boolean DEFAULT false,
    processed_at timestamp without time zone,
    employee_id integer,
    attendance_id integer,
    pulled_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    pull_log_id integer,
    raw_data text
);


ALTER TABLE public.zkteco_raw_attendance OWNER TO postgres;

--
-- Name: TABLE zkteco_raw_attendance; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.zkteco_raw_attendance IS 'Raw punch data from devices before processing';


--
-- Name: zkteco_raw_attendance_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zkteco_raw_attendance_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zkteco_raw_attendance_id_seq OWNER TO postgres;

--
-- Name: zkteco_raw_attendance_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zkteco_raw_attendance_id_seq OWNED BY public.zkteco_raw_attendance.id;


--
-- Name: zkteco_settings; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zkteco_settings (
    id integer NOT NULL,
    setting_key character varying(100) NOT NULL,
    setting_value text,
    setting_type character varying(50) DEFAULT 'string'::character varying,
    description text,
    updated_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);


ALTER TABLE public.zkteco_settings OWNER TO postgres;

--
-- Name: TABLE zkteco_settings; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.zkteco_settings IS 'ZKTeco integration settings';


--
-- Name: zkteco_settings_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zkteco_settings_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zkteco_settings_id_seq OWNER TO postgres;

--
-- Name: zkteco_settings_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zkteco_settings_id_seq OWNED BY public.zkteco_settings.id;


--
-- Name: zkteco_sync_queue; Type: TABLE; Schema: public; Owner: postgres
--

CREATE TABLE public.zkteco_sync_queue (
    id integer NOT NULL,
    device_id integer NOT NULL,
    employee_id integer NOT NULL,
    operation character varying(20) NOT NULL,
    priority integer DEFAULT 0,
    user_data jsonb,
    status character varying(20) DEFAULT 'PENDING'::character varying,
    attempts integer DEFAULT 0,
    max_attempts integer DEFAULT 3,
    error_message text,
    created_at timestamp without time zone DEFAULT CURRENT_TIMESTAMP,
    processed_at timestamp without time zone,
    completed_at timestamp without time zone,
    CONSTRAINT chk_operation CHECK (((operation)::text = ANY ((ARRAY['ADD_USER'::character varying, 'UPDATE_USER'::character varying, 'DELETE_USER'::character varying, 'ADD_FINGERPRINT'::character varying])::text[]))),
    CONSTRAINT chk_sync_status CHECK (((status)::text = ANY ((ARRAY['PENDING'::character varying, 'PROCESSING'::character varying, 'COMPLETED'::character varying, 'FAILED'::character varying])::text[])))
);


ALTER TABLE public.zkteco_sync_queue OWNER TO postgres;

--
-- Name: TABLE zkteco_sync_queue; Type: COMMENT; Schema: public; Owner: postgres
--

COMMENT ON TABLE public.zkteco_sync_queue IS 'Queue for pushing data to devices';


--
-- Name: zkteco_sync_queue_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zkteco_sync_queue_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zkteco_sync_queue_id_seq OWNER TO postgres;

--
-- Name: zkteco_sync_queue_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zkteco_sync_queue_id_seq OWNED BY public.zkteco_sync_queue.id;


--
-- Name: zkteco_user_mapping_id_seq; Type: SEQUENCE; Schema: public; Owner: postgres
--

CREATE SEQUENCE public.zkteco_user_mapping_id_seq
    AS integer
    START WITH 1
    INCREMENT BY 1
    NO MINVALUE
    NO MAXVALUE
    CACHE 1;


ALTER SEQUENCE public.zkteco_user_mapping_id_seq OWNER TO postgres;

--
-- Name: zkteco_user_mapping_id_seq; Type: SEQUENCE OWNED BY; Schema: public; Owner: postgres
--

ALTER SEQUENCE public.zkteco_user_mapping_id_seq OWNED BY public.zkteco_user_mapping.id;


--
-- Name: attendance id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance ALTER COLUMN id SET DEFAULT nextval('public.attendance_id_seq'::regclass);


--
-- Name: attendance_monthly_summary id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_monthly_summary ALTER COLUMN id SET DEFAULT nextval('public.attendance_monthly_summary_id_seq'::regclass);


--
-- Name: attendance_status id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_status ALTER COLUMN id SET DEFAULT nextval('public.attendance_status_id_seq'::regclass);


--
-- Name: audit_log id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_log ALTER COLUMN id SET DEFAULT nextval('public.audit_log_id_seq'::regclass);


--
-- Name: book_packing id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing ALTER COLUMN id SET DEFAULT nextval('public.book_packing_id_seq'::regclass);


--
-- Name: books book_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.books ALTER COLUMN book_id SET DEFAULT nextval('public.books_book_id_seq'::regclass);


--
-- Name: ctp_export_jobs id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ctp_export_jobs ALTER COLUMN id SET DEFAULT nextval('public.ctp_export_jobs_id_seq'::regclass);


--
-- Name: d2m id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m ALTER COLUMN id SET DEFAULT nextval('public.d2m_id_seq'::regclass);


--
-- Name: d2m_items id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m_items ALTER COLUMN id SET DEFAULT nextval('public.d2m_items_id_seq'::regclass);


--
-- Name: deno id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno ALTER COLUMN id SET DEFAULT nextval('public.deno_id_seq'::regclass);


--
-- Name: department id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.department ALTER COLUMN id SET DEFAULT nextval('public.department_id_seq'::regclass);


--
-- Name: designation id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.designation ALTER COLUMN id SET DEFAULT nextval('public.designation_id_seq'::regclass);


--
-- Name: drivers driver_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers ALTER COLUMN driver_id SET DEFAULT nextval('public.drivers_driver_id_seq'::regclass);


--
-- Name: education_details id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.education_details ALTER COLUMN id SET DEFAULT nextval('public.education_details_id_seq'::regclass);


--
-- Name: employee id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee ALTER COLUMN id SET DEFAULT nextval('public.employee_id_seq'::regclass);


--
-- Name: employee_designation id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_designation ALTER COLUMN id SET DEFAULT nextval('public.employee_designation_id_seq'::regclass);


--
-- Name: employee_documents id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_documents ALTER COLUMN id SET DEFAULT nextval('public.employee_documents_id_seq'::regclass);


--
-- Name: employee_family id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_family ALTER COLUMN id SET DEFAULT nextval('public.employee_family_id_seq'::regclass);


--
-- Name: fctp_books id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_books ALTER COLUMN id SET DEFAULT nextval('public.fctp_books_id_seq'::regclass);


--
-- Name: fctp_formas id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_formas ALTER COLUMN id SET DEFAULT nextval('public.fctp_formas_id_seq'::regclass);


--
-- Name: fctp_imposition_templates id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_imposition_templates ALTER COLUMN id SET DEFAULT nextval('public.fctp_imposition_templates_id_seq'::regclass);


--
-- Name: fctp_job_tickets id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_job_tickets ALTER COLUMN id SET DEFAULT nextval('public.fctp_job_tickets_id_seq'::regclass);


--
-- Name: fctp_uploads id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_uploads ALTER COLUMN id SET DEFAULT nextval('public.fctp_uploads_id_seq'::regclass);


--
-- Name: fiscal_years id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fiscal_years ALTER COLUMN id SET DEFAULT nextval('public.fiscal_years_id_seq'::regclass);


--
-- Name: forma id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma ALTER COLUMN id SET DEFAULT nextval('public.forma_id_seq'::regclass);


--
-- Name: forma_printing id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing ALTER COLUMN id SET DEFAULT nextval('public.forma_printing_id_seq'::regclass);


--
-- Name: fuel_coupon_distributions distribution_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupon_distributions ALTER COLUMN distribution_id SET DEFAULT nextval('public.fuel_coupon_distributions_distribution_id_seq'::regclass);


--
-- Name: fuel_coupons coupon_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupons ALTER COLUMN coupon_id SET DEFAULT nextval('public.fuel_coupons_coupon_id_seq'::regclass);


--
-- Name: fuel_price_history price_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_price_history ALTER COLUMN price_id SET DEFAULT nextval('public.fuel_price_history_price_id_seq'::regclass);


--
-- Name: holiday_types id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holiday_types ALTER COLUMN id SET DEFAULT nextval('public.holiday_types_id_seq'::regclass);


--
-- Name: holidays id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holidays ALTER COLUMN id SET DEFAULT nextval('public.holidays_id_seq'::regclass);


--
-- Name: imposition_templates id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.imposition_templates ALTER COLUMN id SET DEFAULT nextval('public.imposition_templates_id_seq'::regclass);


--
-- Name: job_ticket id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket ALTER COLUMN id SET DEFAULT nextval('public.job_ticket_id_seq'::regclass);


--
-- Name: job_ticket_details id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket_details ALTER COLUMN id SET DEFAULT nextval('public.job_ticket_details_id_seq'::regclass);


--
-- Name: leave_balance id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leave_balance ALTER COLUMN id SET DEFAULT nextval('public.leave_balance_id_seq'::regclass);


--
-- Name: level id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.level ALTER COLUMN id SET DEFAULT nextval('public.level_id_seq'::regclass);


--
-- Name: machines id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.machines ALTER COLUMN id SET DEFAULT nextval('public.machines_id_seq'::regclass);


--
-- Name: maintenance_parts part_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_parts ALTER COLUMN part_id SET DEFAULT nextval('public.maintenance_parts_part_id_seq'::regclass);


--
-- Name: maintenance_types maintenance_type_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_types ALTER COLUMN maintenance_type_id SET DEFAULT nextval('public.maintenance_types_maintenance_type_id_seq'::regclass);


--
-- Name: monthly_vehicle_summary summary_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.monthly_vehicle_summary ALTER COLUMN summary_id SET DEFAULT nextval('public.monthly_vehicle_summary_summary_id_seq'::regclass);


--
-- Name: ot_rules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ot_rules ALTER COLUMN id SET DEFAULT nextval('public.ot_rules_id_seq'::regclass);


--
-- Name: page_setups id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.page_setups ALTER COLUMN id SET DEFAULT nextval('public.page_setups_id_seq'::regclass);


--
-- Name: recon_brt id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_brt ALTER COLUMN id SET DEFAULT nextval('public.recon_brt_id_seq'::regclass);


--
-- Name: recon_comparative id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_comparative ALTER COLUMN id SET DEFAULT nextval('public.recon_comparative_id_seq'::regclass);


--
-- Name: recon_marketing id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_marketing ALTER COLUMN id SET DEFAULT nextval('public.recon_marketing_id_seq'::regclass);


--
-- Name: recon_modules id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_modules ALTER COLUMN id SET DEFAULT nextval('public.recon_modules_id_seq'::regclass);


--
-- Name: recon_opening_stock_2080 id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_opening_stock_2080 ALTER COLUMN id SET DEFAULT nextval('public.recon_opening_stock_2080_id_seq'::regclass);


--
-- Name: recon_pkr id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_pkr ALTER COLUMN id SET DEFAULT nextval('public.recon_pkr_id_seq'::regclass);


--
-- Name: recon_software id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_software ALTER COLUMN id SET DEFAULT nextval('public.recon_software_id_seq'::regclass);


--
-- Name: recon_stockkeeper id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_stockkeeper ALTER COLUMN id SET DEFAULT nextval('public.recon_stockkeeper_id_seq'::regclass);


--
-- Name: shifts id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.shifts ALTER COLUMN id SET DEFAULT nextval('public.shifts_id_seq'::regclass);


--
-- Name: system_settings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings ALTER COLUMN id SET DEFAULT nextval('public.system_settings_id_seq'::regclass);


--
-- Name: users id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users ALTER COLUMN id SET DEFAULT nextval('public.users_id_seq'::regclass);


--
-- Name: vehicle_audit_logs audit_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_audit_logs ALTER COLUMN audit_id SET DEFAULT nextval('public.vehicle_audit_logs_audit_id_seq'::regclass);


--
-- Name: vehicle_daily_logs log_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_daily_logs ALTER COLUMN log_id SET DEFAULT nextval('public.vehicle_daily_logs_log_id_seq'::regclass);


--
-- Name: vehicle_driver_assignments assignment_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_driver_assignments ALTER COLUMN assignment_id SET DEFAULT nextval('public.vehicle_driver_assignments_assignment_id_seq'::regclass);


--
-- Name: vehicle_maintenance_records maintenance_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance_records ALTER COLUMN maintenance_id SET DEFAULT nextval('public.vehicle_maintenance_records_maintenance_id_seq'::regclass);


--
-- Name: vehicles vehicle_id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles ALTER COLUMN vehicle_id SET DEFAULT nextval('public.vehicles_vehicle_id_seq'::regclass);


--
-- Name: zkteco_capacity_log id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_capacity_log ALTER COLUMN id SET DEFAULT nextval('public.zkteco_capacity_log_id_seq'::regclass);


--
-- Name: zkteco_devices id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_devices ALTER COLUMN id SET DEFAULT nextval('public.zkteco_devices_id_seq'::regclass);


--
-- Name: zkteco_pull_log id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_pull_log ALTER COLUMN id SET DEFAULT nextval('public.zkteco_pull_log_id_seq'::regclass);


--
-- Name: zkteco_raw_attendance id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_raw_attendance ALTER COLUMN id SET DEFAULT nextval('public.zkteco_raw_attendance_id_seq'::regclass);


--
-- Name: zkteco_settings id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_settings ALTER COLUMN id SET DEFAULT nextval('public.zkteco_settings_id_seq'::regclass);


--
-- Name: zkteco_sync_queue id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_sync_queue ALTER COLUMN id SET DEFAULT nextval('public.zkteco_sync_queue_id_seq'::regclass);


--
-- Name: zkteco_user_mapping id; Type: DEFAULT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_user_mapping ALTER COLUMN id SET DEFAULT nextval('public.zkteco_user_mapping_id_seq'::regclass);


--
-- Name: attendance_monthly_summary attendance_monthly_summary_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_monthly_summary
    ADD CONSTRAINT attendance_monthly_summary_pkey PRIMARY KEY (id);


--
-- Name: attendance attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT attendance_pkey PRIMARY KEY (id);


--
-- Name: attendance_status attendance_status_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_status
    ADD CONSTRAINT attendance_status_pkey PRIMARY KEY (id);


--
-- Name: audit_log audit_log_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_pkey PRIMARY KEY (id);


--
-- Name: book_packing book_packing_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_pkey PRIMARY KEY (id);


--
-- Name: books books_book_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.books
    ADD CONSTRAINT books_book_code_key UNIQUE (book_code);


--
-- Name: books books_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.books
    ADD CONSTRAINT books_pkey PRIMARY KEY (book_id);


--
-- Name: ctp_export_jobs ctp_export_jobs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ctp_export_jobs
    ADD CONSTRAINT ctp_export_jobs_pkey PRIMARY KEY (id);


--
-- Name: d2m d2m_d2m_no_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT d2m_d2m_no_key UNIQUE (d2m_no);


--
-- Name: d2m_items d2m_items_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m_items
    ADD CONSTRAINT d2m_items_pkey PRIMARY KEY (id);


--
-- Name: d2m d2m_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT d2m_pkey PRIMARY KEY (id);


--
-- Name: deno deno_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_pkey PRIMARY KEY (id);


--
-- Name: deno_test deno_test_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno_test
    ADD CONSTRAINT deno_test_pkey PRIMARY KEY (id);


--
-- Name: department department_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.department
    ADD CONSTRAINT department_pkey PRIMARY KEY (id);


--
-- Name: designation designation_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.designation
    ADD CONSTRAINT designation_pkey PRIMARY KEY (id);


--
-- Name: drivers drivers_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT drivers_pkey PRIMARY KEY (driver_id);


--
-- Name: education_details education_details_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.education_details
    ADD CONSTRAINT education_details_pkey PRIMARY KEY (id);


--
-- Name: employee employee_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT employee_code_key UNIQUE (code);


--
-- Name: employee_designation employee_designation_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_designation
    ADD CONSTRAINT employee_designation_pkey PRIMARY KEY (id);


--
-- Name: employee_documents employee_documents_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_documents
    ADD CONSTRAINT employee_documents_pkey PRIMARY KEY (id);


--
-- Name: employee_family employee_family_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_family
    ADD CONSTRAINT employee_family_pkey PRIMARY KEY (id);


--
-- Name: employee employee_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT employee_pkey PRIMARY KEY (id);


--
-- Name: fctp_books fctp_books_book_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_books
    ADD CONSTRAINT fctp_books_book_code_key UNIQUE (book_code);


--
-- Name: fctp_books fctp_books_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_books
    ADD CONSTRAINT fctp_books_pkey PRIMARY KEY (id);


--
-- Name: fctp_formas fctp_formas_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_formas
    ADD CONSTRAINT fctp_formas_pkey PRIMARY KEY (id);


--
-- Name: fctp_imposition_templates fctp_imposition_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_imposition_templates
    ADD CONSTRAINT fctp_imposition_templates_pkey PRIMARY KEY (id);


--
-- Name: fctp_job_tickets fctp_job_tickets_job_ticket_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_job_tickets
    ADD CONSTRAINT fctp_job_tickets_job_ticket_code_key UNIQUE (job_ticket_code);


--
-- Name: fctp_job_tickets fctp_job_tickets_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_job_tickets
    ADD CONSTRAINT fctp_job_tickets_pkey PRIMARY KEY (id);


--
-- Name: fctp_uploads fctp_uploads_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_uploads
    ADD CONSTRAINT fctp_uploads_pkey PRIMARY KEY (id);


--
-- Name: fiscal_years fiscal_years_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fiscal_years
    ADD CONSTRAINT fiscal_years_fiscal_code_key UNIQUE (fiscal_code);


--
-- Name: fiscal_years fiscal_years_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fiscal_years
    ADD CONSTRAINT fiscal_years_pkey PRIMARY KEY (id);


--
-- Name: forma forma_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma
    ADD CONSTRAINT forma_pkey PRIMARY KEY (id);


--
-- Name: forma_printing forma_printing_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_pkey PRIMARY KEY (id);


--
-- Name: fuel_coupon_distributions fuel_coupon_distributions_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupon_distributions
    ADD CONSTRAINT fuel_coupon_distributions_pkey PRIMARY KEY (distribution_id);


--
-- Name: fuel_coupons fuel_coupons_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupons
    ADD CONSTRAINT fuel_coupons_pkey PRIMARY KEY (coupon_id);


--
-- Name: fuel_price_history fuel_price_history_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_price_history
    ADD CONSTRAINT fuel_price_history_pkey PRIMARY KEY (price_id);


--
-- Name: holiday_types holiday_types_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holiday_types
    ADD CONSTRAINT holiday_types_pkey PRIMARY KEY (id);


--
-- Name: holidays holidays_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_pkey PRIMARY KEY (id);


--
-- Name: imposition_templates imposition_templates_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.imposition_templates
    ADD CONSTRAINT imposition_templates_pkey PRIMARY KEY (id);


--
-- Name: job_ticket_details job_ticket_details_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket_details
    ADD CONSTRAINT job_ticket_details_pkey PRIMARY KEY (id);


--
-- Name: job_ticket job_ticket_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket
    ADD CONSTRAINT job_ticket_pkey PRIMARY KEY (id);


--
-- Name: leave_balance leave_balance_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leave_balance
    ADD CONSTRAINT leave_balance_pkey PRIMARY KEY (id);


--
-- Name: level level_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.level
    ADD CONSTRAINT level_pkey PRIMARY KEY (id);


--
-- Name: machines machines_machine_name_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_machine_name_key UNIQUE (machine_name);


--
-- Name: machines machines_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.machines
    ADD CONSTRAINT machines_pkey PRIMARY KEY (id);


--
-- Name: maintenance_parts maintenance_parts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_parts
    ADD CONSTRAINT maintenance_parts_pkey PRIMARY KEY (part_id);


--
-- Name: maintenance_types maintenance_types_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_types
    ADD CONSTRAINT maintenance_types_pkey PRIMARY KEY (maintenance_type_id);


--
-- Name: maintenance_types maintenance_types_type_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_types
    ADD CONSTRAINT maintenance_types_type_code_key UNIQUE (type_code);


--
-- Name: monthly_vehicle_summary monthly_vehicle_summary_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.monthly_vehicle_summary
    ADD CONSTRAINT monthly_vehicle_summary_pkey PRIMARY KEY (summary_id);


--
-- Name: ot_rules ot_rules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ot_rules
    ADD CONSTRAINT ot_rules_pkey PRIMARY KEY (id);


--
-- Name: page_setups page_setups_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.page_setups
    ADD CONSTRAINT page_setups_pkey PRIMARY KEY (id);


--
-- Name: recon_brt recon_brt_book_code_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_brt
    ADD CONSTRAINT recon_brt_book_code_fiscal_code_key UNIQUE (book_code, fiscal_code);


--
-- Name: recon_brt recon_brt_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_brt
    ADD CONSTRAINT recon_brt_pkey PRIMARY KEY (id);


--
-- Name: recon_comparative recon_comparative_book_code_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_comparative
    ADD CONSTRAINT recon_comparative_book_code_fiscal_code_key UNIQUE (book_code, fiscal_code);


--
-- Name: recon_comparative recon_comparative_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_comparative
    ADD CONSTRAINT recon_comparative_pkey PRIMARY KEY (id);


--
-- Name: recon_marketing recon_marketing_book_code_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_marketing
    ADD CONSTRAINT recon_marketing_book_code_fiscal_code_key UNIQUE (book_code, fiscal_code);


--
-- Name: recon_marketing recon_marketing_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_marketing
    ADD CONSTRAINT recon_marketing_pkey PRIMARY KEY (id);


--
-- Name: recon_modules recon_modules_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_modules
    ADD CONSTRAINT recon_modules_pkey PRIMARY KEY (id);


--
-- Name: recon_modules recon_modules_slug_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_modules
    ADD CONSTRAINT recon_modules_slug_key UNIQUE (slug);


--
-- Name: recon_modules recon_modules_tbl_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_modules
    ADD CONSTRAINT recon_modules_tbl_key UNIQUE (tbl);


--
-- Name: recon_opening_stock_2080 recon_opening_stock_2080_book_code_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_opening_stock_2080
    ADD CONSTRAINT recon_opening_stock_2080_book_code_fiscal_code_key UNIQUE (book_code, fiscal_code);


--
-- Name: recon_opening_stock_2080 recon_opening_stock_2080_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_opening_stock_2080
    ADD CONSTRAINT recon_opening_stock_2080_pkey PRIMARY KEY (id);


--
-- Name: recon_pkr recon_pkr_book_code_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_pkr
    ADD CONSTRAINT recon_pkr_book_code_fiscal_code_key UNIQUE (book_code, fiscal_code);


--
-- Name: recon_pkr recon_pkr_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_pkr
    ADD CONSTRAINT recon_pkr_pkey PRIMARY KEY (id);


--
-- Name: recon_software recon_software_book_code_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_software
    ADD CONSTRAINT recon_software_book_code_fiscal_code_key UNIQUE (book_code, fiscal_code);


--
-- Name: recon_software recon_software_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_software
    ADD CONSTRAINT recon_software_pkey PRIMARY KEY (id);


--
-- Name: recon_stockkeeper recon_stockkeeper_book_code_fiscal_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_stockkeeper
    ADD CONSTRAINT recon_stockkeeper_book_code_fiscal_code_key UNIQUE (book_code, fiscal_code);


--
-- Name: recon_stockkeeper recon_stockkeeper_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.recon_stockkeeper
    ADD CONSTRAINT recon_stockkeeper_pkey PRIMARY KEY (id);


--
-- Name: shifts shifts_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.shifts
    ADD CONSTRAINT shifts_pkey PRIMARY KEY (id);


--
-- Name: system_settings system_settings_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_key_key UNIQUE (key);


--
-- Name: system_settings system_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.system_settings
    ADD CONSTRAINT system_settings_pkey PRIMARY KEY (id);


--
-- Name: monthly_vehicle_summary uk_summary_vehicle_month; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.monthly_vehicle_summary
    ADD CONSTRAINT uk_summary_vehicle_month UNIQUE (fiscal_year, month_nep, vehicle_id);


--
-- Name: attendance uq_attendance_emp_date; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT uq_attendance_emp_date UNIQUE (employee_id, attendance_date_nep);


--
-- Name: attendance_status uq_attendance_status_code; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_status
    ADD CONSTRAINT uq_attendance_status_code UNIQUE (status_code);


--
-- Name: zkteco_user_mapping uq_device_user; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_user_mapping
    ADD CONSTRAINT uq_device_user UNIQUE (device_id, device_user_id);


--
-- Name: employee uq_employee_card_id; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT uq_employee_card_id UNIQUE (card_id);


--
-- Name: employee uq_employee_code; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT uq_employee_code UNIQUE (code);


--
-- Name: employee uq_employee_pan; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT uq_employee_pan UNIQUE (pan_no);


--
-- Name: holidays uq_holiday_date_year; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT uq_holiday_date_year UNIQUE (holiday_date_nep, fiscal_year);


--
-- Name: holiday_types uq_holiday_type_name; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holiday_types
    ADD CONSTRAINT uq_holiday_type_name UNIQUE (type_name);


--
-- Name: leave_balance uq_leave_balance; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leave_balance
    ADD CONSTRAINT uq_leave_balance UNIQUE (employee_id, fiscal_year, leave_type);


--
-- Name: attendance_monthly_summary uq_monthly_summary; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_monthly_summary
    ADD CONSTRAINT uq_monthly_summary UNIQUE (employee_id, year_month_nep);


--
-- Name: users users_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_pkey PRIMARY KEY (id);


--
-- Name: users users_username_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.users
    ADD CONSTRAINT users_username_key UNIQUE (username);


--
-- Name: vehicle_audit_logs vehicle_audit_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_audit_logs
    ADD CONSTRAINT vehicle_audit_logs_pkey PRIMARY KEY (audit_id);


--
-- Name: vehicle_daily_logs vehicle_daily_logs_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_daily_logs
    ADD CONSTRAINT vehicle_daily_logs_pkey PRIMARY KEY (log_id);


--
-- Name: vehicle_driver_assignments vehicle_driver_assignments_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_driver_assignments
    ADD CONSTRAINT vehicle_driver_assignments_pkey PRIMARY KEY (assignment_id);


--
-- Name: vehicle_maintenance_records vehicle_maintenance_records_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance_records
    ADD CONSTRAINT vehicle_maintenance_records_pkey PRIMARY KEY (maintenance_id);


--
-- Name: vehicles vehicles_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_pkey PRIMARY KEY (vehicle_id);


--
-- Name: vehicles vehicles_vehicle_no_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT vehicles_vehicle_no_key UNIQUE (vehicle_no);


--
-- Name: zkteco_capacity_log zkteco_capacity_log_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_capacity_log
    ADD CONSTRAINT zkteco_capacity_log_pkey PRIMARY KEY (id);


--
-- Name: zkteco_devices zkteco_devices_device_code_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_devices
    ADD CONSTRAINT zkteco_devices_device_code_key UNIQUE (device_code);


--
-- Name: zkteco_devices zkteco_devices_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_devices
    ADD CONSTRAINT zkteco_devices_pkey PRIMARY KEY (id);


--
-- Name: zkteco_pull_log zkteco_pull_log_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_pull_log
    ADD CONSTRAINT zkteco_pull_log_pkey PRIMARY KEY (id);


--
-- Name: zkteco_raw_attendance zkteco_raw_attendance_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_raw_attendance
    ADD CONSTRAINT zkteco_raw_attendance_pkey PRIMARY KEY (id);


--
-- Name: zkteco_settings zkteco_settings_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_settings
    ADD CONSTRAINT zkteco_settings_pkey PRIMARY KEY (id);


--
-- Name: zkteco_settings zkteco_settings_setting_key_key; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_settings
    ADD CONSTRAINT zkteco_settings_setting_key_key UNIQUE (setting_key);


--
-- Name: zkteco_sync_queue zkteco_sync_queue_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_sync_queue
    ADD CONSTRAINT zkteco_sync_queue_pkey PRIMARY KEY (id);


--
-- Name: zkteco_user_mapping zkteco_user_mapping_pkey; Type: CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_user_mapping
    ADD CONSTRAINT zkteco_user_mapping_pkey PRIMARY KEY (id);


--
-- Name: deno_test_book_code_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_book_code_idx ON public.deno_test USING btree (book_code);


--
-- Name: deno_test_bp_id_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_bp_id_idx ON public.deno_test USING btree (bp_id);


--
-- Name: deno_test_created_by_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_created_by_idx ON public.deno_test USING btree (created_by);


--
-- Name: deno_test_d2m_id_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_d2m_id_idx ON public.deno_test USING btree (d2m_id);


--
-- Name: deno_test_deleted_at_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_deleted_at_idx ON public.deno_test USING btree (deleted_at);


--
-- Name: deno_test_entry_type_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_entry_type_idx ON public.deno_test USING btree (entry_type);


--
-- Name: deno_test_jt_id_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_jt_id_idx ON public.deno_test USING btree (jt_id);


--
-- Name: deno_test_received_by_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_received_by_idx ON public.deno_test USING btree (received_by);


--
-- Name: deno_test_sender_by_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_sender_by_idx ON public.deno_test USING btree (sender_by);


--
-- Name: deno_test_updated_by_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_updated_by_idx ON public.deno_test USING btree (updated_by);


--
-- Name: deno_test_verify_by_idx; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX deno_test_verify_by_idx ON public.deno_test USING btree (verify_by);


--
-- Name: idx_assignments_active; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_assignments_active ON public.vehicle_driver_assignments USING btree (active_flag) WHERE (deleted_at IS NULL);


--
-- Name: idx_assignments_driver; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_assignments_driver ON public.vehicle_driver_assignments USING btree (driver_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_assignments_vehicle; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_assignments_vehicle ON public.vehicle_driver_assignments USING btree (vehicle_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_attendance_data_source; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_data_source ON public.attendance USING btree (data_source);


--
-- Name: idx_attendance_date_eng; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_date_eng ON public.attendance USING btree (attendance_date_eng);


--
-- Name: idx_attendance_date_nep; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_date_nep ON public.attendance USING btree (attendance_date_nep);


--
-- Name: idx_attendance_device; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_device ON public.attendance USING btree (device_id);


--
-- Name: idx_attendance_employee; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_employee ON public.attendance USING btree (employee_id);


--
-- Name: idx_attendance_ot; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_ot ON public.attendance USING btree (ot_hours) WHERE (ot_hours > (0)::numeric);


--
-- Name: idx_attendance_shift; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_shift ON public.attendance USING btree (shift_id);


--
-- Name: idx_attendance_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_attendance_status ON public.attendance USING btree (status_id);


--
-- Name: idx_audit_log_action; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_audit_log_action ON public.audit_log USING btree (action);


--
-- Name: idx_audit_log_changed_at; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_audit_log_changed_at ON public.audit_log USING btree (changed_at);


--
-- Name: idx_audit_log_module; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_audit_log_module ON public.audit_log USING btree (module_name);


--
-- Name: idx_audit_log_record; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_audit_log_record ON public.audit_log USING btree (record_id);


--
-- Name: idx_audit_log_table; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_audit_log_table ON public.audit_log USING btree (table_name);


--
-- Name: idx_audit_performed_at; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_audit_performed_at ON public.vehicle_audit_logs USING btree (performed_at);


--
-- Name: idx_audit_table_record; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_audit_table_record ON public.vehicle_audit_logs USING btree (table_name, record_id);


--
-- Name: idx_book_packing_book_code; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_book_packing_book_code ON public.book_packing USING btree (book_code);


--
-- Name: idx_book_packing_created_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_book_packing_created_date ON public.book_packing USING btree (created_date);


--
-- Name: idx_book_packing_date_nep; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_book_packing_date_nep ON public.book_packing USING btree (date_nep);


--
-- Name: idx_book_packing_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_book_packing_fiscal_year ON public.book_packing USING btree (fiscal_year_id);


--
-- Name: idx_book_packing_jt_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_book_packing_jt_id ON public.book_packing USING btree (jt_id);


--
-- Name: idx_book_packing_packing_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_book_packing_packing_status ON public.book_packing USING btree (packing_status);


--
-- Name: idx_book_packing_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_book_packing_status ON public.book_packing USING btree (status);


--
-- Name: idx_coupons_fiscal_month; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_coupons_fiscal_month ON public.fuel_coupons USING btree (fiscal_year, month_nep) WHERE (deleted_at IS NULL);


--
-- Name: idx_coupons_vehicle; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_coupons_vehicle ON public.fuel_coupons USING btree (vehicle_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_coupons_verified; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_coupons_verified ON public.fuel_coupons USING btree (verified_with_pump) WHERE (deleted_at IS NULL);


--
-- Name: idx_ctp_jobs_book; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_ctp_jobs_book ON public.ctp_export_jobs USING btree (book_code);


--
-- Name: idx_ctp_jobs_created; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_ctp_jobs_created ON public.ctp_export_jobs USING btree (created_at DESC);


--
-- Name: idx_ctp_jobs_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_ctp_jobs_status ON public.ctp_export_jobs USING btree (status);


--
-- Name: idx_d2m_checked_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_checked_by ON public.d2m USING btree (checked_by);


--
-- Name: idx_d2m_created_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_created_by ON public.d2m USING btree (created_by);


--
-- Name: idx_d2m_eng_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_eng_date ON public.d2m USING btree (eng_date);


--
-- Name: idx_d2m_fy; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_fy ON public.d2m USING btree (fiscal_year_id);


--
-- Name: idx_d2m_items_deno_ids; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_items_deno_ids ON public.d2m_items USING gin (string_to_array(associated_deno_ids, ','::text));


--
-- Name: idx_d2m_nep_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_nep_date ON public.d2m USING btree (nep_date);


--
-- Name: idx_d2m_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_status ON public.d2m USING btree (status);


--
-- Name: idx_d2m_type; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_type ON public.d2m USING btree (d2m_type);


--
-- Name: idx_d2m_verified_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_d2m_verified_by ON public.d2m USING btree (verified_by);


--
-- Name: idx_deno_book_code; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_book_code ON public.deno USING btree (book_code);


--
-- Name: idx_deno_bp_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_bp_id ON public.deno USING btree (bp_id);


--
-- Name: idx_deno_created_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_created_by ON public.deno USING btree (created_by);


--
-- Name: idx_deno_d2m_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_d2m_id ON public.deno USING btree (d2m_id);


--
-- Name: idx_deno_deleted_at; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_deleted_at ON public.deno USING btree (deleted_at);


--
-- Name: idx_deno_entry_type; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_entry_type ON public.deno USING btree (entry_type);


--
-- Name: idx_deno_jt_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_jt_id ON public.deno USING btree (jt_id);


--
-- Name: idx_deno_received_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_received_by ON public.deno USING btree (received_by);


--
-- Name: idx_deno_sender_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_sender_by ON public.deno USING btree (sender_by);


--
-- Name: idx_deno_updated_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_updated_by ON public.deno USING btree (updated_by);


--
-- Name: idx_deno_verify_by; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_deno_verify_by ON public.deno USING btree (verify_by);


--
-- Name: idx_distributions_coupon; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_distributions_coupon ON public.fuel_coupon_distributions USING btree (coupon_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_distributions_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_distributions_date ON public.fuel_coupon_distributions USING btree (disburse_date_eng) WHERE (deleted_at IS NULL);


--
-- Name: idx_drivers_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_drivers_status ON public.drivers USING btree (status) WHERE (deleted_at IS NULL);


--
-- Name: idx_education_emp_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_education_emp_id ON public.education_details USING btree (emp_id);


--
-- Name: idx_emp_designation_dates; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_emp_designation_dates ON public.employee_designation USING btree (date_of_join, date_of_left);


--
-- Name: idx_emp_designation_emp_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_emp_designation_emp_id ON public.employee_designation USING btree (emp_id);


--
-- Name: idx_emp_family_emp_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_emp_family_emp_id ON public.employee_family USING btree (emp_id);


--
-- Name: idx_employee_card_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_card_id ON public.employee USING btree (card_id);


--
-- Name: idx_employee_code; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_code ON public.employee USING btree (code);


--
-- Name: idx_employee_deleted; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_deleted ON public.employee USING btree (deleted_date);


--
-- Name: idx_employee_designation; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_designation ON public.employee USING btree (designation_id);


--
-- Name: idx_employee_email; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_email ON public.employee USING btree (email);


--
-- Name: idx_employee_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_fiscal_year ON public.employee USING btree (fiscal_year_id);


--
-- Name: idx_employee_is_technical; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_is_technical ON public.employee USING btree (is_technical);


--
-- Name: idx_employee_local_body; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_local_body ON public.employee USING btree (local_body);


--
-- Name: idx_employee_mobile; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_mobile ON public.employee USING btree (mobile_number);


--
-- Name: idx_employee_pan; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_pan ON public.employee USING btree (pan_no);


--
-- Name: idx_employee_state; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_employee_state ON public.employee USING btree (state);


--
-- Name: idx_fctp_books_code; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_books_code ON public.fctp_books USING btree (book_code);


--
-- Name: idx_fctp_formas_book; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_formas_book ON public.fctp_formas USING btree (book_code);


--
-- Name: idx_fctp_formas_jt; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_formas_jt ON public.fctp_formas USING btree (job_ticket_id);


--
-- Name: idx_fctp_formas_order; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_formas_order ON public.fctp_formas USING btree (job_ticket_id, order_no);


--
-- Name: idx_fctp_formas_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_formas_status ON public.fctp_formas USING btree (output_status);


--
-- Name: idx_fctp_formas_unique; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX idx_fctp_formas_unique ON public.fctp_formas USING btree (job_ticket_id, forma_name);


--
-- Name: idx_fctp_jt_book; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_jt_book ON public.fctp_job_tickets USING btree (book_code);


--
-- Name: idx_fctp_jt_code; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_jt_code ON public.fctp_job_tickets USING btree (job_ticket_code);


--
-- Name: idx_fctp_jt_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_jt_status ON public.fctp_job_tickets USING btree (status);


--
-- Name: idx_fctp_uploads_book; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_uploads_book ON public.fctp_uploads USING btree (book_code);


--
-- Name: idx_fctp_uploads_forma; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fctp_uploads_forma ON public.fctp_uploads USING btree (forma_id);


--
-- Name: idx_forma_printing_created_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_forma_printing_created_date ON public.forma_printing USING btree (created_date);


--
-- Name: idx_forma_printing_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_forma_printing_fiscal_year ON public.forma_printing USING btree (fiscal_year_id);


--
-- Name: idx_forma_printing_jt_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_forma_printing_jt_id ON public.forma_printing USING btree (jt_id);


--
-- Name: idx_forma_printing_jtd_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_forma_printing_jtd_id ON public.forma_printing USING btree (jtd_id);


--
-- Name: idx_forma_printing_machine_id; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_forma_printing_machine_id ON public.forma_printing USING btree (machine_id);


--
-- Name: idx_forma_printing_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_forma_printing_status ON public.forma_printing USING btree (status);


--
-- Name: idx_fuel_price_fiscal_month; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fuel_price_fiscal_month ON public.fuel_price_history USING btree (fiscal_year, month_nep) WHERE (deleted_at IS NULL);


--
-- Name: idx_fuel_price_type; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_fuel_price_type ON public.fuel_price_history USING btree (fuel_type) WHERE (deleted_at IS NULL);


--
-- Name: idx_holidays_date_eng; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holidays_date_eng ON public.holidays USING btree (holiday_date_eng);


--
-- Name: idx_holidays_date_nep; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holidays_date_nep ON public.holidays USING btree (holiday_date_nep);


--
-- Name: idx_holidays_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holidays_fiscal_year ON public.holidays USING btree (fiscal_year);


--
-- Name: idx_holidays_type; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_holidays_type ON public.holidays USING btree (holiday_type_id);


--
-- Name: idx_leave_balance_employee; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leave_balance_employee ON public.leave_balance USING btree (employee_id);


--
-- Name: idx_leave_balance_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_leave_balance_fiscal_year ON public.leave_balance USING btree (fiscal_year);


--
-- Name: idx_logs_driver; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_logs_driver ON public.vehicle_daily_logs USING btree (driver_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_logs_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_logs_fiscal_year ON public.vehicle_daily_logs USING btree (fiscal_year) WHERE (deleted_at IS NULL);


--
-- Name: idx_logs_month_nep; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_logs_month_nep ON public.vehicle_daily_logs USING btree (month_nep) WHERE (deleted_at IS NULL);


--
-- Name: idx_logs_vehicle_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_logs_vehicle_date ON public.vehicle_daily_logs USING btree (vehicle_id, log_date_eng) WHERE (deleted_at IS NULL);


--
-- Name: idx_maintenance_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_maintenance_date ON public.vehicle_maintenance_records USING btree (maintenance_date_eng) WHERE (deleted_at IS NULL);


--
-- Name: idx_maintenance_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_maintenance_fiscal_year ON public.vehicle_maintenance_records USING btree (fiscal_year) WHERE (deleted_at IS NULL);


--
-- Name: idx_maintenance_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_maintenance_status ON public.vehicle_maintenance_records USING btree (status) WHERE (deleted_at IS NULL);


--
-- Name: idx_maintenance_type; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_maintenance_type ON public.vehicle_maintenance_records USING btree (maintenance_type_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_maintenance_types_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_maintenance_types_status ON public.maintenance_types USING btree (status) WHERE (deleted_at IS NULL);


--
-- Name: idx_maintenance_vehicle; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_maintenance_vehicle ON public.vehicle_maintenance_records USING btree (vehicle_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_monthly_summary_employee; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_monthly_summary_employee ON public.attendance_monthly_summary USING btree (employee_id);


--
-- Name: idx_monthly_summary_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_monthly_summary_fiscal_year ON public.attendance_monthly_summary USING btree (fiscal_year);


--
-- Name: idx_monthly_summary_month; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_monthly_summary_month ON public.attendance_monthly_summary USING btree (year_month_nep);


--
-- Name: idx_parts_maintenance; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_parts_maintenance ON public.maintenance_parts USING btree (maintenance_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_summary_fiscal_month; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_summary_fiscal_month ON public.monthly_vehicle_summary USING btree (fiscal_year, month_nep) WHERE (deleted_at IS NULL);


--
-- Name: idx_summary_overuse; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_summary_overuse ON public.monthly_vehicle_summary USING btree (overuse_flag) WHERE (deleted_at IS NULL);


--
-- Name: idx_summary_vehicle; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_summary_vehicle ON public.monthly_vehicle_summary USING btree (vehicle_id) WHERE (deleted_at IS NULL);


--
-- Name: idx_system_settings_key; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_system_settings_key ON public.system_settings USING btree (key);


--
-- Name: idx_vehicles_fiscal_year; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_vehicles_fiscal_year ON public.vehicles USING btree (fiscal_year);


--
-- Name: idx_vehicles_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_vehicles_status ON public.vehicles USING btree (status) WHERE (deleted_at IS NULL);


--
-- Name: idx_zkteco_capacity_device; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_capacity_device ON public.zkteco_capacity_log USING btree (device_id);


--
-- Name: idx_zkteco_capacity_logged; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_capacity_logged ON public.zkteco_capacity_log USING btree (logged_at);


--
-- Name: idx_zkteco_devices_active; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_devices_active ON public.zkteco_devices USING btree (is_active);


--
-- Name: idx_zkteco_devices_code; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_devices_code ON public.zkteco_devices USING btree (device_code);


--
-- Name: idx_zkteco_devices_priority; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_devices_priority ON public.zkteco_devices USING btree (priority);


--
-- Name: idx_zkteco_devices_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_devices_status ON public.zkteco_devices USING btree (connection_status);


--
-- Name: idx_zkteco_mapping_active; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_mapping_active ON public.zkteco_user_mapping USING btree (is_active);


--
-- Name: idx_zkteco_mapping_device; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_mapping_device ON public.zkteco_user_mapping USING btree (device_id);


--
-- Name: idx_zkteco_mapping_device_user; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_mapping_device_user ON public.zkteco_user_mapping USING btree (device_user_id);


--
-- Name: idx_zkteco_mapping_employee; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_mapping_employee ON public.zkteco_user_mapping USING btree (employee_id);


--
-- Name: idx_zkteco_pull_log_date; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_pull_log_date ON public.zkteco_pull_log USING btree (pull_date);


--
-- Name: idx_zkteco_pull_log_device; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_pull_log_device ON public.zkteco_pull_log USING btree (device_id);


--
-- Name: idx_zkteco_pull_log_schedule; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_pull_log_schedule ON public.zkteco_pull_log USING btree (schedule_type);


--
-- Name: idx_zkteco_pull_log_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_pull_log_status ON public.zkteco_pull_log USING btree (status);


--
-- Name: idx_zkteco_raw_device; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_raw_device ON public.zkteco_raw_attendance USING btree (device_id);


--
-- Name: idx_zkteco_raw_device_user; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_raw_device_user ON public.zkteco_raw_attendance USING btree (device_user_id);


--
-- Name: idx_zkteco_raw_processed; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_raw_processed ON public.zkteco_raw_attendance USING btree (is_processed);


--
-- Name: idx_zkteco_raw_time; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_raw_time ON public.zkteco_raw_attendance USING btree (punch_time);


--
-- Name: idx_zkteco_settings_key; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_settings_key ON public.zkteco_settings USING btree (setting_key);


--
-- Name: idx_zkteco_sync_device; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_sync_device ON public.zkteco_sync_queue USING btree (device_id);


--
-- Name: idx_zkteco_sync_employee; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_sync_employee ON public.zkteco_sync_queue USING btree (employee_id);


--
-- Name: idx_zkteco_sync_priority; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_sync_priority ON public.zkteco_sync_queue USING btree (priority);


--
-- Name: idx_zkteco_sync_status; Type: INDEX; Schema: public; Owner: postgres
--

CREATE INDEX idx_zkteco_sync_status ON public.zkteco_sync_queue USING btree (status);


--
-- Name: uniq_d2m_day; Type: INDEX; Schema: public; Owner: postgres
--

CREATE UNIQUE INDEX uniq_d2m_day ON public.d2m USING btree (nep_date, d2m_type, fiscal_year_id, serial_no) WHERE (deleted_at IS NULL);


--
-- Name: v_fctp_job_summary _RETURN; Type: RULE; Schema: public; Owner: postgres
--

CREATE OR REPLACE VIEW public.v_fctp_job_summary AS
 SELECT jt.id,
    jt.job_ticket_code,
    jt.fiscal_year,
    jt.lot_no,
    jt.print_qty,
    jt.page_qty,
    jt.date_nep,
    jt.date_eng,
    jt.status,
    b.book_code,
    b.book_name,
    b.class,
    b.total_pages,
    b.master_pdf_path,
    count(f.id) AS forma_count,
    sum(
        CASE
            WHEN ((f.output_status)::text = 'generated'::text) THEN 1
            ELSE 0
        END) AS formas_done,
    jt.created_by,
    jt.created_at
   FROM ((public.fctp_job_tickets jt
     JOIN public.fctp_books b ON (((jt.book_code)::text = (b.book_code)::text)))
     LEFT JOIN public.fctp_formas f ON ((f.job_ticket_id = jt.id)))
  GROUP BY jt.id, b.book_code, b.book_name, b.class, b.total_pages, b.master_pdf_path;


--
-- Name: deno deno_audit; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER deno_audit AFTER INSERT OR DELETE OR UPDATE ON public.deno FOR EACH ROW EXECUTE FUNCTION public.log_deno_changes();


--
-- Name: forma_printing forma_printing_audit; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER forma_printing_audit AFTER INSERT OR DELETE OR UPDATE ON public.forma_printing FOR EACH ROW EXECUTE FUNCTION public.log_forma_printing_changes();


--
-- Name: attendance trg_attendance_calculate_hours; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_attendance_calculate_hours BEFORE INSERT OR UPDATE ON public.attendance FOR EACH ROW EXECUTE FUNCTION public.trg_calculate_attendance_hours();


--
-- Name: deno trg_deno_delete; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_deno_delete AFTER DELETE ON public.deno FOR EACH ROW EXECUTE FUNCTION public.log_deno_changes();


--
-- Name: deno trg_deno_fields; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_deno_fields BEFORE INSERT OR UPDATE ON public.deno FOR EACH ROW EXECUTE FUNCTION public.update_deno_fields();


--
-- Name: deno trg_deno_insert; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_deno_insert AFTER INSERT ON public.deno FOR EACH ROW EXECUTE FUNCTION public.log_deno_changes();


--
-- Name: deno trg_deno_update; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_deno_update AFTER UPDATE ON public.deno FOR EACH ROW EXECUTE FUNCTION public.log_deno_changes();


--
-- Name: employee trg_generate_employee_code; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_generate_employee_code BEFORE INSERT ON public.employee FOR EACH ROW WHEN (((new.code IS NULL) OR ((new.code)::text = ''::text))) EXECUTE FUNCTION public.generate_employee_code();


--
-- Name: employee trg_set_fiscal_year; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_set_fiscal_year BEFORE INSERT ON public.employee FOR EACH ROW EXECUTE FUNCTION public.set_active_fiscal_year();


--
-- Name: vehicle_daily_logs trg_set_month_nep; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_set_month_nep BEFORE INSERT OR UPDATE ON public.vehicle_daily_logs FOR EACH ROW EXECUTE FUNCTION public.fn_set_month_nep();


--
-- Name: fiscal_years trg_single_active_fiscal_year; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_single_active_fiscal_year BEFORE INSERT OR UPDATE ON public.fiscal_years FOR EACH ROW EXECUTE FUNCTION public.enforce_single_active_fiscal_year();


--
-- Name: employee trg_validate_employee; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_validate_employee BEFORE INSERT OR UPDATE ON public.employee FOR EACH ROW EXECUTE FUNCTION public.validate_employee_data();


--
-- Name: vehicle_daily_logs trg_vehicle_logs_month_nep; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trg_vehicle_logs_month_nep BEFORE INSERT OR UPDATE OF log_date_nep ON public.vehicle_daily_logs FOR EACH ROW EXECUTE FUNCTION public.trg_set_month_nep();


--
-- Name: fuel_coupon_distributions trigger_auto_set_fuel_price; Type: TRIGGER; Schema: public; Owner: postgres
--

CREATE TRIGGER trigger_auto_set_fuel_price BEFORE INSERT ON public.fuel_coupon_distributions FOR EACH ROW EXECUTE FUNCTION public.auto_set_fuel_price();


--
-- Name: attendance attendance_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT attendance_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);


--
-- Name: attendance_monthly_summary attendance_monthly_summary_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance_monthly_summary
    ADD CONSTRAINT attendance_monthly_summary_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);


--
-- Name: attendance attendance_shift_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT attendance_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES public.shifts(id);


--
-- Name: attendance attendance_status_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.attendance
    ADD CONSTRAINT attendance_status_id_fkey FOREIGN KEY (status_id) REFERENCES public.attendance_status(id);


--
-- Name: audit_log audit_log_changed_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.audit_log
    ADD CONSTRAINT audit_log_changed_by_fkey FOREIGN KEY (changed_by) REFERENCES public.users(id);


--
-- Name: book_packing book_packing_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: book_packing book_packing_fiscal_year_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_fiscal_year_id_fkey FOREIGN KEY (fiscal_year_id) REFERENCES public.fiscal_years(id);


--
-- Name: book_packing book_packing_incharge_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_incharge_id_fkey FOREIGN KEY (incharge_id) REFERENCES public.users(id);


--
-- Name: book_packing book_packing_jt_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_jt_id_fkey FOREIGN KEY (jt_id) REFERENCES public.job_ticket(id);


--
-- Name: book_packing book_packing_operator_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_operator_id_fkey FOREIGN KEY (operator_id) REFERENCES public.users(id);


--
-- Name: book_packing book_packing_supervisor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_supervisor_id_fkey FOREIGN KEY (supervisor_id) REFERENCES public.users(id);


--
-- Name: book_packing book_packing_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.book_packing
    ADD CONSTRAINT book_packing_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: ctp_export_jobs ctp_export_jobs_book_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ctp_export_jobs
    ADD CONSTRAINT ctp_export_jobs_book_code_fkey FOREIGN KEY (book_code) REFERENCES public.books(book_code) ON DELETE SET NULL;


--
-- Name: ctp_export_jobs ctp_export_jobs_deno_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ctp_export_jobs
    ADD CONSTRAINT ctp_export_jobs_deno_id_fkey FOREIGN KEY (deno_id) REFERENCES public.deno(id) ON DELETE SET NULL;


--
-- Name: ctp_export_jobs ctp_export_jobs_template_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.ctp_export_jobs
    ADD CONSTRAINT ctp_export_jobs_template_id_fkey FOREIGN KEY (template_id) REFERENCES public.imposition_templates(id);


--
-- Name: d2m_items d2m_items_book_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m_items
    ADD CONSTRAINT d2m_items_book_code_fkey FOREIGN KEY (book_code) REFERENCES public.books(book_code) ON DELETE RESTRICT;


--
-- Name: d2m_items d2m_items_d2m_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m_items
    ADD CONSTRAINT d2m_items_d2m_id_fkey FOREIGN KEY (d2m_id) REFERENCES public.d2m(id) ON DELETE CASCADE;


--
-- Name: deno deno_book_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_book_code_fkey FOREIGN KEY (book_code) REFERENCES public.books(book_code);


--
-- Name: deno deno_bp_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_bp_id_fkey FOREIGN KEY (bp_id) REFERENCES public.book_packing(id) ON DELETE SET NULL;


--
-- Name: deno deno_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id) ON DELETE RESTRICT;


--
-- Name: deno deno_d2m_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_d2m_id_fkey FOREIGN KEY (d2m_id) REFERENCES public.d2m(id) ON DELETE SET NULL;


--
-- Name: deno deno_jt_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_jt_id_fkey FOREIGN KEY (jt_id) REFERENCES public.job_ticket(id) ON DELETE SET NULL;


--
-- Name: deno deno_received_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_received_by_fkey FOREIGN KEY (received_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deno deno_sender_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_sender_by_fkey FOREIGN KEY (sender_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deno deno_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: deno deno_verify_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.deno
    ADD CONSTRAINT deno_verify_by_fkey FOREIGN KEY (verify_by) REFERENCES public.users(id) ON DELETE SET NULL;


--
-- Name: education_details education_details_emp_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.education_details
    ADD CONSTRAINT education_details_emp_id_fkey FOREIGN KEY (emp_id) REFERENCES public.employee(id) ON DELETE CASCADE;


--
-- Name: employee employee_department_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT employee_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);


--
-- Name: employee_designation employee_designation_department_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_designation
    ADD CONSTRAINT employee_designation_department_id_fkey FOREIGN KEY (department_id) REFERENCES public.department(id);


--
-- Name: employee_designation employee_designation_designation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_designation
    ADD CONSTRAINT employee_designation_designation_id_fkey FOREIGN KEY (designation_id) REFERENCES public.designation(id);


--
-- Name: employee_designation employee_designation_emp_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_designation
    ADD CONSTRAINT employee_designation_emp_id_fkey FOREIGN KEY (emp_id) REFERENCES public.employee(id) ON DELETE CASCADE;


--
-- Name: employee employee_designation_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT employee_designation_id_fkey FOREIGN KEY (designation_id) REFERENCES public.designation(id);


--
-- Name: employee_designation employee_designation_level_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_designation
    ADD CONSTRAINT employee_designation_level_id_fkey FOREIGN KEY (level_id) REFERENCES public.level(id);


--
-- Name: employee_documents employee_documents_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_documents
    ADD CONSTRAINT employee_documents_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id) ON DELETE CASCADE;


--
-- Name: employee_family employee_family_emp_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee_family
    ADD CONSTRAINT employee_family_emp_id_fkey FOREIGN KEY (emp_id) REFERENCES public.employee(id) ON DELETE CASCADE;


--
-- Name: employee employee_fiscal_year_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT employee_fiscal_year_id_fkey FOREIGN KEY (fiscal_year_id) REFERENCES public.fiscal_years(id);


--
-- Name: employee employee_level_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.employee
    ADD CONSTRAINT employee_level_id_fkey FOREIGN KEY (level_id) REFERENCES public.level(id);


--
-- Name: fctp_formas fctp_formas_job_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_formas
    ADD CONSTRAINT fctp_formas_job_ticket_id_fkey FOREIGN KEY (job_ticket_id) REFERENCES public.fctp_job_tickets(id) ON DELETE CASCADE;


--
-- Name: fctp_job_tickets fctp_job_tickets_book_code_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_job_tickets
    ADD CONSTRAINT fctp_job_tickets_book_code_fkey FOREIGN KEY (book_code) REFERENCES public.fctp_books(book_code) ON DELETE CASCADE;


--
-- Name: fctp_uploads fctp_uploads_forma_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fctp_uploads
    ADD CONSTRAINT fctp_uploads_forma_id_fkey FOREIGN KEY (forma_id) REFERENCES public.fctp_formas(id) ON DELETE CASCADE;


--
-- Name: vehicle_driver_assignments fk_assignment_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_driver_assignments
    ADD CONSTRAINT fk_assignment_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: vehicle_driver_assignments fk_assignment_driver; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_driver_assignments
    ADD CONSTRAINT fk_assignment_driver FOREIGN KEY (driver_id) REFERENCES public.drivers(driver_id);


--
-- Name: vehicle_driver_assignments fk_assignment_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_driver_assignments
    ADD CONSTRAINT fk_assignment_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: vehicle_driver_assignments fk_assignment_vehicle; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_driver_assignments
    ADD CONSTRAINT fk_assignment_vehicle FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(vehicle_id);


--
-- Name: forma fk_book; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma
    ADD CONSTRAINT fk_book FOREIGN KEY (book_id) REFERENCES public.books(book_id);


--
-- Name: fuel_coupons fk_coupon_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupons
    ADD CONSTRAINT fk_coupon_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: fuel_coupons fk_coupon_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupons
    ADD CONSTRAINT fk_coupon_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: fuel_coupons fk_coupon_vehicle; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupons
    ADD CONSTRAINT fk_coupon_vehicle FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(vehicle_id);


--
-- Name: d2m fk_d2m_checked_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT fk_d2m_checked_by FOREIGN KEY (checked_by) REFERENCES public.users(id);


--
-- Name: d2m fk_d2m_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT fk_d2m_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: d2m fk_d2m_deleted_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT fk_d2m_deleted_by FOREIGN KEY (deleted_by) REFERENCES public.users(id);


--
-- Name: d2m fk_d2m_fy; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT fk_d2m_fy FOREIGN KEY (fiscal_year_id) REFERENCES public.fiscal_years(id);


--
-- Name: d2m fk_d2m_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT fk_d2m_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: d2m fk_d2m_verified_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.d2m
    ADD CONSTRAINT fk_d2m_verified_by FOREIGN KEY (verified_by) REFERENCES public.users(id);


--
-- Name: fuel_coupon_distributions fk_distribution_coupon; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupon_distributions
    ADD CONSTRAINT fk_distribution_coupon FOREIGN KEY (coupon_id) REFERENCES public.fuel_coupons(coupon_id) ON DELETE CASCADE;


--
-- Name: fuel_coupon_distributions fk_distribution_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupon_distributions
    ADD CONSTRAINT fk_distribution_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: fuel_coupon_distributions fk_distribution_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_coupon_distributions
    ADD CONSTRAINT fk_distribution_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: drivers fk_driver_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT fk_driver_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: drivers fk_driver_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.drivers
    ADD CONSTRAINT fk_driver_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: vehicle_daily_logs fk_log_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_daily_logs
    ADD CONSTRAINT fk_log_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: vehicle_daily_logs fk_log_driver; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_daily_logs
    ADD CONSTRAINT fk_log_driver FOREIGN KEY (driver_id) REFERENCES public.drivers(driver_id);


--
-- Name: vehicle_daily_logs fk_log_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_daily_logs
    ADD CONSTRAINT fk_log_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: vehicle_daily_logs fk_log_vehicle; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_daily_logs
    ADD CONSTRAINT fk_log_vehicle FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(vehicle_id);


--
-- Name: vehicle_maintenance_records fk_maint_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance_records
    ADD CONSTRAINT fk_maint_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: vehicle_maintenance_records fk_maint_type; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance_records
    ADD CONSTRAINT fk_maint_type FOREIGN KEY (maintenance_type_id) REFERENCES public.maintenance_types(maintenance_type_id);


--
-- Name: maintenance_types fk_maint_type_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_types
    ADD CONSTRAINT fk_maint_type_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: maintenance_types fk_maint_type_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_types
    ADD CONSTRAINT fk_maint_type_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: vehicle_maintenance_records fk_maint_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance_records
    ADD CONSTRAINT fk_maint_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: vehicle_maintenance_records fk_maint_vehicle; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicle_maintenance_records
    ADD CONSTRAINT fk_maint_vehicle FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(vehicle_id);


--
-- Name: maintenance_parts fk_part_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_parts
    ADD CONSTRAINT fk_part_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: maintenance_parts fk_part_maintenance; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.maintenance_parts
    ADD CONSTRAINT fk_part_maintenance FOREIGN KEY (maintenance_id) REFERENCES public.vehicle_maintenance_records(maintenance_id) ON DELETE CASCADE;


--
-- Name: fuel_price_history fk_price_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_price_history
    ADD CONSTRAINT fk_price_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: fuel_price_history fk_price_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.fuel_price_history
    ADD CONSTRAINT fk_price_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: monthly_vehicle_summary fk_summary_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.monthly_vehicle_summary
    ADD CONSTRAINT fk_summary_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: monthly_vehicle_summary fk_summary_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.monthly_vehicle_summary
    ADD CONSTRAINT fk_summary_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: monthly_vehicle_summary fk_summary_vehicle; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.monthly_vehicle_summary
    ADD CONSTRAINT fk_summary_vehicle FOREIGN KEY (vehicle_id) REFERENCES public.vehicles(vehicle_id);


--
-- Name: vehicles fk_vehicle_created_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT fk_vehicle_created_by FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: vehicles fk_vehicle_updated_by; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.vehicles
    ADD CONSTRAINT fk_vehicle_updated_by FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: forma_printing forma_printing_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: forma_printing forma_printing_delete_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_delete_by_fkey FOREIGN KEY (delete_by) REFERENCES public.users(id);


--
-- Name: forma_printing forma_printing_fiscal_year_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_fiscal_year_id_fkey FOREIGN KEY (fiscal_year_id) REFERENCES public.fiscal_years(id);


--
-- Name: forma_printing forma_printing_incharge_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_incharge_id_fkey FOREIGN KEY (incharge_id) REFERENCES public.users(id);


--
-- Name: forma_printing forma_printing_jt_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_jt_id_fkey FOREIGN KEY (jt_id) REFERENCES public.job_ticket(id);


--
-- Name: forma_printing forma_printing_jtd_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_jtd_id_fkey FOREIGN KEY (jtd_id) REFERENCES public.job_ticket_details(id);


--
-- Name: forma_printing forma_printing_machine_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_machine_id_fkey FOREIGN KEY (machine_id) REFERENCES public.machines(id);


--
-- Name: forma_printing forma_printing_operator_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_operator_id_fkey FOREIGN KEY (operator_id) REFERENCES public.users(id);


--
-- Name: forma_printing forma_printing_shift_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES public.shifts(id);


--
-- Name: forma_printing forma_printing_supervisor_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_supervisor_id_fkey FOREIGN KEY (supervisor_id) REFERENCES public.users(id);


--
-- Name: forma_printing forma_printing_updated_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.forma_printing
    ADD CONSTRAINT forma_printing_updated_by_fkey FOREIGN KEY (updated_by) REFERENCES public.users(id);


--
-- Name: holidays holidays_holiday_type_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.holidays
    ADD CONSTRAINT holidays_holiday_type_id_fkey FOREIGN KEY (holiday_type_id) REFERENCES public.holiday_types(id);


--
-- Name: job_ticket job_ticket_book_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket
    ADD CONSTRAINT job_ticket_book_id_fkey FOREIGN KEY (book_id) REFERENCES public.books(book_id);


--
-- Name: job_ticket job_ticket_created_by_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket
    ADD CONSTRAINT job_ticket_created_by_fkey FOREIGN KEY (created_by) REFERENCES public.users(id);


--
-- Name: job_ticket_details job_ticket_details_job_ticket_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket_details
    ADD CONSTRAINT job_ticket_details_job_ticket_id_fkey FOREIGN KEY (job_ticket_id) REFERENCES public.job_ticket(id);


--
-- Name: job_ticket job_ticket_fiscal_year_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.job_ticket
    ADD CONSTRAINT job_ticket_fiscal_year_id_fkey FOREIGN KEY (fiscal_year_id) REFERENCES public.fiscal_years(id);


--
-- Name: leave_balance leave_balance_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.leave_balance
    ADD CONSTRAINT leave_balance_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);


--
-- Name: zkteco_capacity_log zkteco_capacity_log_device_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_capacity_log
    ADD CONSTRAINT zkteco_capacity_log_device_id_fkey FOREIGN KEY (device_id) REFERENCES public.zkteco_devices(id) ON DELETE CASCADE;


--
-- Name: zkteco_pull_log zkteco_pull_log_device_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_pull_log
    ADD CONSTRAINT zkteco_pull_log_device_id_fkey FOREIGN KEY (device_id) REFERENCES public.zkteco_devices(id) ON DELETE CASCADE;


--
-- Name: zkteco_raw_attendance zkteco_raw_attendance_attendance_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_raw_attendance
    ADD CONSTRAINT zkteco_raw_attendance_attendance_id_fkey FOREIGN KEY (attendance_id) REFERENCES public.attendance(id);


--
-- Name: zkteco_raw_attendance zkteco_raw_attendance_device_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_raw_attendance
    ADD CONSTRAINT zkteco_raw_attendance_device_id_fkey FOREIGN KEY (device_id) REFERENCES public.zkteco_devices(id) ON DELETE CASCADE;


--
-- Name: zkteco_raw_attendance zkteco_raw_attendance_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_raw_attendance
    ADD CONSTRAINT zkteco_raw_attendance_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id);


--
-- Name: zkteco_raw_attendance zkteco_raw_attendance_pull_log_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_raw_attendance
    ADD CONSTRAINT zkteco_raw_attendance_pull_log_id_fkey FOREIGN KEY (pull_log_id) REFERENCES public.zkteco_pull_log(id) ON DELETE SET NULL;


--
-- Name: zkteco_sync_queue zkteco_sync_queue_device_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_sync_queue
    ADD CONSTRAINT zkteco_sync_queue_device_id_fkey FOREIGN KEY (device_id) REFERENCES public.zkteco_devices(id) ON DELETE CASCADE;


--
-- Name: zkteco_sync_queue zkteco_sync_queue_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_sync_queue
    ADD CONSTRAINT zkteco_sync_queue_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id) ON DELETE CASCADE;


--
-- Name: zkteco_user_mapping zkteco_user_mapping_device_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_user_mapping
    ADD CONSTRAINT zkteco_user_mapping_device_id_fkey FOREIGN KEY (device_id) REFERENCES public.zkteco_devices(id) ON DELETE CASCADE;


--
-- Name: zkteco_user_mapping zkteco_user_mapping_employee_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_user_mapping
    ADD CONSTRAINT zkteco_user_mapping_employee_id_fkey FOREIGN KEY (employee_id) REFERENCES public.employee(id) ON DELETE CASCADE;


--
-- Name: zkteco_user_mapping zkteco_user_mapping_shift_id_fkey; Type: FK CONSTRAINT; Schema: public; Owner: postgres
--

ALTER TABLE ONLY public.zkteco_user_mapping
    ADD CONSTRAINT zkteco_user_mapping_shift_id_fkey FOREIGN KEY (shift_id) REFERENCES public.shifts(id);


--
-- PostgreSQL database dump complete
--


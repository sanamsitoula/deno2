-- ================================================================================
-- FUEL PRICE HISTORY & VEHICLE MAINTENANCE TRACKING
-- Extension to Vehicle Tracking System
-- ================================================================================

-- ================================================================================
-- 8️⃣ FUEL PRICE HISTORY MODULE
-- ================================================================================

-- 💰 FUEL PRICE MASTER (Historical fuel rates)
CREATE TABLE fuel_price_history (
    price_id SERIAL PRIMARY KEY,
    
    -- Price period
    fiscal_year VARCHAR(9) NOT NULL,
    month_nep VARCHAR(20) NOT NULL,
    
    -- Fuel type
    fuel_type VARCHAR(10) CHECK (fuel_type IN ('petrol','diesel','mobil')),
    
    -- Effective dates (can have multiple rates in same month)
    effective_from_date_nep VARCHAR(20) NOT NULL,
    effective_from_date_eng DATE NOT NULL,
    effective_to_date_nep VARCHAR(20),
    effective_to_date_eng DATE,
    
    -- Pricing
    rate_per_liter NUMERIC(10,2) NOT NULL,
    
    -- Additional info
    source VARCHAR(100), -- e.g., "Nepal Oil Corporation"
    notification_no VARCHAR(50), -- Government notification number
    is_active BOOLEAN DEFAULT TRUE,
    remarks TEXT,
    
    -- Tracking fields
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP,
    
    CONSTRAINT fk_price_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_price_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
);

CREATE INDEX idx_fuel_price_fiscal_month ON fuel_price_history(fiscal_year, month_nep) WHERE deleted_at IS NULL;
CREATE INDEX idx_fuel_price_type ON fuel_price_history(fuel_type) WHERE deleted_at IS NULL;
CREATE INDEX idx_fuel_price_effective ON fuel_price_history(effective_from_date_eng) WHERE deleted_at IS NULL;
CREATE INDEX idx_fuel_price_active ON fuel_price_history(is_active) WHERE deleted_at IS NULL;

-- ================================================================================
-- 9️⃣ VEHICLE MAINTENANCE TRACKING MODULE
-- ================================================================================

-- 🔧 MAINTENANCE TYPES (Master data for maintenance categories)
CREATE TABLE maintenance_types (
    maintenance_type_id SERIAL PRIMARY KEY,
    type_code VARCHAR(20) UNIQUE NOT NULL,
    type_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_scheduled BOOLEAN DEFAULT FALSE, -- TRUE for scheduled maintenance (oil change, etc.)
    default_interval_km INT, -- Default km interval for scheduled maintenance
    default_interval_months INT, -- Default month interval
    status BOOLEAN DEFAULT TRUE,
    
    -- Tracking fields
    fiscal_year VARCHAR(9) NOT NULL DEFAULT '2082/83',
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP,
    
    CONSTRAINT fk_maint_type_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_maint_type_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
);

CREATE INDEX idx_maintenance_types_status ON maintenance_types(status) WHERE deleted_at IS NULL;

-- Insert default maintenance types
INSERT INTO maintenance_types (type_code, type_name, is_scheduled, default_interval_km, default_interval_months, created_by) VALUES
('OIL_CHANGE', 'Engine Oil Change', TRUE, 5000, 6, 1),
('TIRE_ROTATION', 'Tire Rotation', TRUE, 10000, NULL, 1),
('BRAKE_SERVICE', 'Brake Service', TRUE, 15000, 12, 1),
('BATTERY_CHECK', 'Battery Check', TRUE, NULL, 6, 1),
('GENERAL_SERVICE', 'General Service', TRUE, 10000, 12, 1),
('REPAIR', 'Repair Work', FALSE, NULL, NULL, 1),
('ACCIDENT_REPAIR', 'Accident Repair', FALSE, NULL, NULL, 1),
('BODY_WORK', 'Body Work', FALSE, NULL, NULL, 1),
('ELECTRICAL', 'Electrical Work', FALSE, NULL, NULL, 1),
('AC_SERVICE', 'AC Service', TRUE, NULL, 12, 1);

-- 🔧 VEHICLE MAINTENANCE RECORDS
CREATE TABLE vehicle_maintenance_records (
    maintenance_id SERIAL PRIMARY KEY,
    
    -- Vehicle info
    vehicle_id INT NOT NULL,
    maintenance_type_id INT NOT NULL,
    
    -- Maintenance dates
    maintenance_date_nep VARCHAR(20) NOT NULL,
    maintenance_date_eng DATE NOT NULL,
    
    -- Meter reading at maintenance
    meter_reading INT NOT NULL,
    
    -- Next scheduled maintenance (for scheduled types)
    next_due_km INT,
    next_due_date_nep VARCHAR(20),
    next_due_date_eng DATE,
    
    -- Work details
    work_description TEXT,
    parts_replaced TEXT,
    
    -- Service provider
    service_provider VARCHAR(150), -- Workshop name
    mechanic_name VARCHAR(100),
    
    -- Cost details
    labor_cost NUMERIC(10,2) DEFAULT 0,
    parts_cost NUMERIC(10,2) DEFAULT 0,
    total_cost NUMERIC(10,2) GENERATED ALWAYS AS (labor_cost + parts_cost) STORED,
    
    -- Payment
    payment_status BOOLEAN DEFAULT FALSE,
    payment_date_nep VARCHAR(20),
    payment_date_eng DATE,
    bill_no VARCHAR(50),
    
    -- Additional info
    downtime_days INT DEFAULT 0, -- How many days vehicle was down
    is_warranty BOOLEAN DEFAULT FALSE,
    warranty_remarks TEXT,
    
    status VARCHAR(20) CHECK (status IN ('pending', 'in_progress', 'completed', 'cancelled')) DEFAULT 'completed',
    remarks TEXT,
    
    -- Tracking fields
    fiscal_year VARCHAR(9) NOT NULL DEFAULT '2082/83',
    created_by INT,
    updated_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP,
    deleted_at TIMESTAMP,
    
    CONSTRAINT fk_maint_vehicle FOREIGN KEY (vehicle_id) REFERENCES vehicles(vehicle_id),
    CONSTRAINT fk_maint_type FOREIGN KEY (maintenance_type_id) REFERENCES maintenance_types(maintenance_type_id),
    CONSTRAINT fk_maint_created_by FOREIGN KEY (created_by) REFERENCES users(id),
    CONSTRAINT fk_maint_updated_by FOREIGN KEY (updated_by) REFERENCES users(id)
);

CREATE INDEX idx_maintenance_vehicle ON vehicle_maintenance_records(vehicle_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_maintenance_type ON vehicle_maintenance_records(maintenance_type_id) WHERE deleted_at IS NULL;
CREATE INDEX idx_maintenance_date ON vehicle_maintenance_records(maintenance_date_eng) WHERE deleted_at IS NULL;
CREATE INDEX idx_maintenance_status ON vehicle_maintenance_records(status) WHERE deleted_at IS NULL;
CREATE INDEX idx_maintenance_fiscal_year ON vehicle_maintenance_records(fiscal_year) WHERE deleted_at IS NULL;

-- 📦 MAINTENANCE PARTS INVENTORY (Optional - for tracking parts)
CREATE TABLE maintenance_parts (
    part_id SERIAL PRIMARY KEY,
    maintenance_id INT NOT NULL,
    
    -- Part details
    part_name VARCHAR(150) NOT NULL,
    part_number VARCHAR(50),
    quantity INT NOT NULL,
    unit_price NUMERIC(10,2) NOT NULL,
    total_price NUMERIC(10,2) GENERATED ALWAYS AS (quantity * unit_price) STORED,
    
    -- Supplier
    supplier_name VARCHAR(100),
    
    remarks TEXT,
    
    -- Tracking fields
    fiscal_year VARCHAR(9) NOT NULL DEFAULT '2082/83',
    created_by INT,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    deleted_at TIMESTAMP,
    
    CONSTRAINT fk_part_maintenance FOREIGN KEY (maintenance_id) REFERENCES vehicle_maintenance_records(maintenance_id) ON DELETE CASCADE,
    CONSTRAINT fk_part_created_by FOREIGN KEY (created_by) REFERENCES users(id)
);

CREATE INDEX idx_parts_maintenance ON maintenance_parts(maintenance_id) WHERE deleted_at IS NULL;

-- ================================================================================
-- 🔟 ENHANCED VIEWS WITH FUEL PRICE & MAINTENANCE
-- ================================================================================

-- 📋 VIEW: Fuel Distribution with Current Price
CREATE OR REPLACE VIEW v_fuel_distribution_with_price AS
SELECT 
    fcd.distribution_id,
    fcd.coupon_id,
    fcd.disburse_date_nep,
    fcd.disburse_date_eng,
    fcd.disburse_qty,
    fcd.rate_per_liter,
    fcd.total_amount,
    fcd.verified_flag,
    
    -- Fuel coupon info
    fc.fiscal_year,
    fc.month_nep,
    fc.vehicle_id,
    fc.fuel_type,
    v.vehicle_no,
    
    -- Current fuel price at time of distribution
    fph.price_id,
    fph.rate_per_liter as market_rate_per_liter,
    
    -- Price comparison
    CASE 
        WHEN fcd.rate_per_liter < fph.rate_per_liter THEN 'Below Market'
        WHEN fcd.rate_per_liter > fph.rate_per_liter THEN 'Above Market'
        ELSE 'At Market'
    END as price_comparison,
    
    (fcd.rate_per_liter - fph.rate_per_liter) as price_difference,
    
    -- User info
    u_created.username as created_by_username
    
FROM fuel_coupon_distributions fcd
JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
LEFT JOIN fuel_price_history fph ON 
    fc.fuel_type = fph.fuel_type AND
    fcd.disburse_date_eng BETWEEN fph.effective_from_date_eng AND COALESCE(fph.effective_to_date_eng, '2099-12-31') AND
    fph.is_active = TRUE AND
    fph.deleted_at IS NULL
LEFT JOIN users u_created ON fcd.created_by = u_created.id
WHERE fcd.deleted_at IS NULL
  AND fc.deleted_at IS NULL
  AND v.deleted_at IS NULL
ORDER BY fcd.disburse_date_eng DESC;

-- 📋 VIEW: Vehicle Maintenance Full Details
CREATE OR REPLACE VIEW v_vehicle_maintenance_full_details AS
SELECT 
    vmr.maintenance_id,
    vmr.maintenance_date_nep,
    vmr.maintenance_date_eng,
    vmr.fiscal_year,
    
    -- Vehicle info
    vmr.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    v.fuel_type,
    
    -- Maintenance type
    vmr.maintenance_type_id,
    mt.type_code,
    mt.type_name,
    mt.is_scheduled,
    
    -- Meter & schedule
    vmr.meter_reading,
    vmr.next_due_km,
    vmr.next_due_date_nep,
    vmr.next_due_date_eng,
    
    -- Work details
    vmr.work_description,
    vmr.parts_replaced,
    vmr.service_provider,
    vmr.mechanic_name,
    
    -- Costs
    vmr.labor_cost,
    vmr.parts_cost,
    vmr.total_cost,
    
    -- Payment
    vmr.payment_status,
    vmr.payment_date_nep,
    vmr.bill_no,
    
    -- Status
    vmr.downtime_days,
    vmr.is_warranty,
    vmr.status,
    vmr.remarks,
    
    -- Parts count
    (SELECT COUNT(*) FROM maintenance_parts mp 
     WHERE mp.maintenance_id = vmr.maintenance_id AND mp.deleted_at IS NULL) as parts_count,
    
    -- User info
    u_created.username as created_by_username,
    vmr.created_at
    
FROM vehicle_maintenance_records vmr
JOIN vehicles v ON vmr.vehicle_id = v.vehicle_id
JOIN maintenance_types mt ON vmr.maintenance_type_id = mt.maintenance_type_id
LEFT JOIN users u_created ON vmr.created_by = u_created.id
WHERE vmr.deleted_at IS NULL
  AND v.deleted_at IS NULL
  AND mt.deleted_at IS NULL
ORDER BY vmr.maintenance_date_eng DESC;

-- 📋 VIEW: Vehicle Maintenance Summary (by vehicle)
CREATE OR REPLACE VIEW v_vehicle_maintenance_summary AS
SELECT 
    v.vehicle_id,
    v.vehicle_no,
    v.vehicle_type,
    
    -- Maintenance counts
    COUNT(vmr.maintenance_id) as total_maintenance_count,
    COUNT(CASE WHEN vmr.status = 'completed' THEN 1 END) as completed_count,
    COUNT(CASE WHEN vmr.status = 'pending' THEN 1 END) as pending_count,
    COUNT(CASE WHEN vmr.status = 'in_progress' THEN 1 END) as in_progress_count,
    
    -- Cost summary
    COALESCE(SUM(vmr.total_cost), 0) as total_maintenance_cost,
    COALESCE(SUM(vmr.labor_cost), 0) as total_labor_cost,
    COALESCE(SUM(vmr.parts_cost), 0) as total_parts_cost,
    
    -- Downtime
    COALESCE(SUM(vmr.downtime_days), 0) as total_downtime_days,
    
    -- Last maintenance
    MAX(vmr.maintenance_date_eng) as last_maintenance_date_eng,
    MAX(vmr.maintenance_date_nep) as last_maintenance_date_nep,
    MAX(vmr.meter_reading) as last_maintenance_meter,
    
    -- Next due (earliest)
    MIN(vmr.next_due_km) as next_due_km,
    MIN(vmr.next_due_date_eng) as next_due_date_eng
    
FROM vehicles v
LEFT JOIN vehicle_maintenance_records vmr ON v.vehicle_id = vmr.vehicle_id 
    AND vmr.deleted_at IS NULL
WHERE v.deleted_at IS NULL
GROUP BY v.vehicle_id, v.vehicle_no, v.vehicle_type
ORDER BY v.vehicle_no;

-- 📋 VIEW: Fuel Price Current Rates
CREATE OR REPLACE VIEW v_fuel_price_current AS
SELECT DISTINCT ON (fuel_type)
    price_id,
    fiscal_year,
    month_nep,
    fuel_type,
    effective_from_date_nep,
    effective_from_date_eng,
    rate_per_liter,
    source,
    notification_no,
    is_active
FROM fuel_price_history
WHERE deleted_at IS NULL
  AND is_active = TRUE
  AND effective_from_date_eng <= CURRENT_DATE
  AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= CURRENT_DATE)
ORDER BY fuel_type, effective_from_date_eng DESC;

-- ================================================================================
-- 🔧 HELPER FUNCTIONS FOR MAINTENANCE
-- ================================================================================

-- Function to get next maintenance due
CREATE OR REPLACE FUNCTION get_next_maintenance_due(
    p_vehicle_id INT,
    p_maintenance_type_id INT
) RETURNS TABLE (
    next_due_km INT,
    next_due_date DATE
) AS $$
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
$$ LANGUAGE plpgsql;

-- Function to get current fuel price
CREATE OR REPLACE FUNCTION get_current_fuel_price(
    p_fuel_type VARCHAR(10),
    p_date DATE DEFAULT CURRENT_DATE
) RETURNS NUMERIC(10,2) AS $$
DECLARE
    v_price NUMERIC(10,2);
BEGIN
    SELECT rate_per_liter INTO v_price
    FROM fuel_price_history
    WHERE fuel_type = p_fuel_type
      AND effective_from_date_eng <= p_date
      AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= p_date)
      AND is_active = TRUE
      AND deleted_at IS NULL
    ORDER BY effective_from_date_eng DESC
    LIMIT 1;
    
    RETURN v_price;
END;
$$ LANGUAGE plpgsql;

-- ================================================================================
-- 📊 TRIGGER TO AUTO-UPDATE FUEL DISTRIBUTION WITH CURRENT PRICE
-- ================================================================================

CREATE OR REPLACE FUNCTION auto_set_fuel_price()
RETURNS TRIGGER AS $$
DECLARE
    v_fuel_type VARCHAR(10);
    v_current_price NUMERIC(10,2);
BEGIN
    -- Get fuel type from coupon
    SELECT fc.fuel_type INTO v_fuel_type
    FROM fuel_coupons fc
    WHERE fc.coupon_id = NEW.coupon_id;
    
    -- If rate_per_liter is not provided, use current price
    IF NEW.rate_per_liter IS NULL OR NEW.rate_per_liter = 0 THEN
        v_current_price := get_current_fuel_price(v_fuel_type, NEW.disburse_date_eng);
        IF v_current_price IS NOT NULL THEN
            NEW.rate_per_liter := v_current_price;
        END IF;
    END IF;
    
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

CREATE TRIGGER trigger_auto_set_fuel_price
BEFORE INSERT ON fuel_coupon_distributions
FOR EACH ROW
EXECUTE FUNCTION auto_set_fuel_price();

-- ================================================================================
-- 📈 MAINTENANCE ALERT FUNCTION
-- ================================================================================

CREATE OR REPLACE FUNCTION get_maintenance_alerts()
RETURNS TABLE (
    vehicle_id INT,
    vehicle_no VARCHAR(30),
    maintenance_type_name VARCHAR(100),
    alert_type VARCHAR(20),
    alert_message TEXT,
    next_due_km INT,
    next_due_date DATE,
    current_meter INT,
    days_until_due INT
) AS $$
BEGIN
    RETURN QUERY
    SELECT 
        v.vehicle_id,
        v.vehicle_no,
        mt.type_name,
        CASE 
            WHEN vmr.next_due_km IS NOT NULL AND vdl.current_meter >= vmr.next_due_km THEN 'OVERDUE_KM'
            WHEN vmr.next_due_km IS NOT NULL AND vdl.current_meter >= vmr.next_due_km - 500 THEN 'DUE_SOON_KM'
            WHEN vmr.next_due_date_eng IS NOT NULL AND vmr.next_due_date_eng <= CURRENT_DATE THEN 'OVERDUE_DATE'
            WHEN vmr.next_due_date_eng IS NOT NULL AND vmr.next_due_date_eng <= CURRENT_DATE + INTERVAL '30 days' THEN 'DUE_SOON_DATE'
            ELSE 'OK'
        END as alert_type,
        CASE 
            WHEN vmr.next_due_km IS NOT NULL AND vdl.current_meter >= vmr.next_due_km 
                THEN 'OVERDUE by ' || (vdl.current_meter - vmr.next_due_km) || ' km'
            WHEN vmr.next_due_km IS NOT NULL AND vdl.current_meter >= vmr.next_due_km - 500 
                THEN 'Due in ' || (vmr.next_due_km - vdl.current_meter) || ' km'
            WHEN vmr.next_due_date_eng IS NOT NULL AND vmr.next_due_date_eng <= CURRENT_DATE 
                THEN 'OVERDUE by ' || (CURRENT_DATE - vmr.next_due_date_eng) || ' days'
            WHEN vmr.next_due_date_eng IS NOT NULL AND vmr.next_due_date_eng <= CURRENT_DATE + INTERVAL '30 days' 
                THEN 'Due in ' || (vmr.next_due_date_eng - CURRENT_DATE) || ' days'
            ELSE 'Up to date'
        END as alert_message,
        vmr.next_due_km,
        vmr.next_due_date_eng,
        vdl.current_meter,
        CASE 
            WHEN vmr.next_due_date_eng IS NOT NULL 
            THEN (vmr.next_due_date_eng - CURRENT_DATE)::INT
            ELSE NULL
        END as days_until_due
    FROM vehicles v
    LEFT JOIN (
        SELECT vehicle_id, MAX(end_meter) as current_meter
        FROM vehicle_daily_logs
        WHERE deleted_at IS NULL
        GROUP BY vehicle_id
    ) vdl ON v.vehicle_id = vdl.vehicle_id
    LEFT JOIN vehicle_maintenance_records vmr ON v.vehicle_id = vmr.vehicle_id 
        AND vmr.deleted_at IS NULL
        AND vmr.status = 'completed'
    LEFT JOIN maintenance_types mt ON vmr.maintenance_type_id = mt.maintenance_type_id
    WHERE v.deleted_at IS NULL
      AND v.status = TRUE
      AND (
          (vmr.next_due_km IS NOT NULL AND vdl.current_meter >= vmr.next_due_km - 500) OR
          (vmr.next_due_date_eng IS NOT NULL AND vmr.next_due_date_eng <= CURRENT_DATE + INTERVAL '30 days')
      )
    ORDER BY 
        CASE 
            WHEN vdl.current_meter >= vmr.next_due_km THEN 1
            WHEN vmr.next_due_date_eng <= CURRENT_DATE THEN 1
            ELSE 2
        END,
        v.vehicle_no;
END;
$$ LANGUAGE plpgsql;

-- ================================================================================
-- END OF ENHANCED SCHEMA
-- ================================================================================

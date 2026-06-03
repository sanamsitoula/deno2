-- ================================================================
-- ZKTECO DEVICE INTEGRATION - DATABASE SCHEMA
-- ================================================================

-- ----------------------------------------------------------------
-- 1. ZKTECO DEVICES TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_devices
(
    id SERIAL PRIMARY KEY,
    device_name VARCHAR(100) NOT NULL,
    device_code VARCHAR(50) UNIQUE NOT NULL,
    ip_address VARCHAR(50) NOT NULL,
    port INTEGER DEFAULT 4370,
    location VARCHAR(200),
    description TEXT,
    
    -- Configuration
    is_active BOOLEAN DEFAULT true,
    priority INTEGER DEFAULT 1,
    timeout INTEGER DEFAULT 5,
    disable_during_pull BOOLEAN DEFAULT true,
    auto_clear_records BOOLEAN DEFAULT false,
    
    -- Status
    last_pull_at TIMESTAMP,
    last_pull_status VARCHAR(20), -- SUCCESS, FAILED, PARTIAL
    last_pull_records INTEGER DEFAULT 0,
    connection_status VARCHAR(20) DEFAULT 'UNKNOWN', -- ONLINE, OFFLINE, UNKNOWN
    last_online_at TIMESTAMP,
    
    -- Audit
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT chk_port CHECK (port > 0 AND port < 65536)
);

CREATE INDEX idx_zkteco_devices_active ON public.zkteco_devices(is_active);
CREATE INDEX idx_zkteco_devices_priority ON public.zkteco_devices(priority);

COMMENT ON TABLE public.zkteco_devices IS 'ZKTeco attendance device configuration';
COMMENT ON COLUMN public.zkteco_devices.disable_during_pull IS 'Disable device during data pull to prevent new punches';
COMMENT ON COLUMN public.zkteco_devices.auto_clear_records IS 'Auto clear old records from device memory';

-- ----------------------------------------------------------------
-- 2. ZKTECO USER MAPPING TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_user_mapping
(
    id SERIAL PRIMARY KEY,
    device_id INTEGER NOT NULL REFERENCES public.zkteco_devices(id) ON DELETE CASCADE,
    device_user_id VARCHAR(50) NOT NULL,
    employee_id INTEGER NOT NULL REFERENCES public.employee(id) ON DELETE CASCADE,
    
    -- Shift information
    shift_id INTEGER REFERENCES public.shifts(id),
    shift_type VARCHAR(20) DEFAULT 'REGULAR', -- REGULAR, DUTY_24HR
    
    -- Mapping metadata
    mapped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    mapped_by INTEGER,
    is_active BOOLEAN DEFAULT true,
    notes TEXT,
    
    CONSTRAINT uq_device_user UNIQUE (device_id, device_user_id),
    CONSTRAINT chk_shift_type CHECK (shift_type IN ('REGULAR', 'DUTY_24HR'))
);

CREATE INDEX idx_zkteco_mapping_device ON public.zkteco_user_mapping(device_id);
CREATE INDEX idx_zkteco_mapping_employee ON public.zkteco_user_mapping(employee_id);
CREATE INDEX idx_zkteco_mapping_device_user ON public.zkteco_user_mapping(device_user_id);

COMMENT ON TABLE public.zkteco_user_mapping IS 'Maps device user IDs to employee records';
COMMENT ON COLUMN public.zkteco_user_mapping.shift_type IS 'REGULAR = 8hr shift with 1hr break, DUTY_24HR = 24-hour duty shift';

-- ----------------------------------------------------------------
-- 3. ZKTECO PULL LOG TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_pull_log
(
    id SERIAL PRIMARY KEY,
    device_id INTEGER REFERENCES public.zkteco_devices(id),
    pull_date DATE NOT NULL,
    pull_time TIME NOT NULL,
    schedule_type VARCHAR(20), -- morning, midmorning, afternoon, evening, night
    
    -- Statistics
    total_records INTEGER DEFAULT 0,
    inserted_records INTEGER DEFAULT 0,
    updated_records INTEGER DEFAULT 0,
    skipped_records INTEGER DEFAULT 0,
    error_records INTEGER DEFAULT 0,
    employees_processed INTEGER DEFAULT 0,
    
    -- Status
    status VARCHAR(20) NOT NULL, -- SUCCESS, FAILED, PARTIAL
    duration_seconds DECIMAL(10,2),
    error_message TEXT,
    
    -- Metadata
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    
    CONSTRAINT chk_status CHECK (status IN ('SUCCESS', 'FAILED', 'PARTIAL', 'RUNNING'))
);

CREATE INDEX idx_zkteco_pull_log_date ON public.zkteco_pull_log(pull_date);
CREATE INDEX idx_zkteco_pull_log_device ON public.zkteco_pull_log(device_id);
CREATE INDEX idx_zkteco_pull_log_schedule ON public.zkteco_pull_log(schedule_type);

COMMENT ON TABLE public.zkteco_pull_log IS 'Log of all ZKTeco data pull operations';

-- ----------------------------------------------------------------
-- 4. ZKTECO RAW DATA TABLE (Optional - for troubleshooting)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_raw_data
(
    id SERIAL PRIMARY KEY,
    device_id INTEGER REFERENCES public.zkteco_devices(id),
    device_user_id VARCHAR(50) NOT NULL,
    punch_timestamp TIMESTAMP NOT NULL,
    punch_type INTEGER, -- 0=in, 1=out, 255=other
    verify_type INTEGER, -- Fingerprint, face, card, etc.
    
    -- Processing
    processed BOOLEAN DEFAULT false,
    processed_at TIMESTAMP,
    employee_id INTEGER REFERENCES public.employee(id),
    attendance_id INTEGER REFERENCES public.attendance(id),
    
    -- Metadata
    imported_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    raw_data TEXT -- Original data for debugging
);

CREATE INDEX idx_zkteco_raw_processed ON public.zkteco_raw_data(processed, imported_at);
CREATE INDEX idx_zkteco_raw_device_user ON public.zkteco_raw_data(device_user_id);
CREATE INDEX idx_zkteco_raw_timestamp ON public.zkteco_raw_data(punch_timestamp);

COMMENT ON TABLE public.zkteco_raw_data IS 'Raw punch data from devices before processing';

-- ----------------------------------------------------------------
-- 5. SYSTEM SETTINGS TABLE (if not exists)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.system_settings
(
    id SERIAL PRIMARY KEY,
    key VARCHAR(100) UNIQUE NOT NULL,
    value TEXT,
    description TEXT,
    setting_type VARCHAR(50) DEFAULT 'STRING',
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default settings
INSERT INTO public.system_settings (key, value, description, setting_type) VALUES
('notify_attendance_pull', 'false', 'Send notification after attendance pull', 'BOOLEAN'),
('attendance_notification_email', '', 'Email address for attendance notifications', 'STRING'),
('zkteco_auto_pull_enabled', 'true', 'Enable automatic attendance pulling', 'BOOLEAN'),
('zkteco_pull_retention_days', '30', 'Days to keep pull logs', 'INTEGER'),
('zkteco_connection_retry', '3', 'Number of connection retry attempts', 'INTEGER')
ON CONFLICT (key) DO NOTHING;

-- ----------------------------------------------------------------
-- 6. ALTER ATTENDANCE TABLE (Add ZKTeco fields)
-- ----------------------------------------------------------------
ALTER TABLE public.attendance 
ADD COLUMN IF NOT EXISTS data_source VARCHAR(20) DEFAULT 'MANUAL',
ADD COLUMN IF NOT EXISTS shift_type VARCHAR(20),
ADD COLUMN IF NOT EXISTS device_id INTEGER REFERENCES public.zkteco_devices(id);

CREATE INDEX IF NOT EXISTS idx_attendance_data_source ON public.attendance(data_source);
CREATE INDEX IF NOT EXISTS idx_attendance_device ON public.attendance(device_id);

COMMENT ON COLUMN public.attendance.data_source IS 'MANUAL, ZKTECO, PDF, EXCEL';
COMMENT ON COLUMN public.attendance.shift_type IS 'REGULAR or DUTY_24HR';

-- ----------------------------------------------------------------
-- 7. VIEWS FOR REPORTING
-- ----------------------------------------------------------------

-- ZKTeco Device Status View
CREATE OR REPLACE VIEW v_zkteco_device_status AS
SELECT 
    d.id,
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
    COUNT(DISTINCT zum.employee_id) as mapped_employees,
    (SELECT COUNT(*) FROM zkteco_pull_log WHERE device_id = d.id AND pull_date = CURRENT_DATE) as pulls_today
FROM zkteco_devices d
LEFT JOIN zkteco_user_mapping zum ON d.id = zum.device_id AND zum.is_active = true
GROUP BY d.id, d.device_name, d.device_code, d.ip_address, d.port, 
         d.location, d.is_active, d.connection_status, d.last_online_at,
         d.last_pull_at, d.last_pull_status, d.last_pull_records;

-- ZKTeco Pull Statistics View
CREATE OR REPLACE VIEW v_zkteco_pull_statistics AS
SELECT 
    DATE(pull_date) as date,
    schedule_type,
    COUNT(*) as total_pulls,
    COUNT(CASE WHEN status = 'SUCCESS' THEN 1 END) as successful_pulls,
    COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed_pulls,
    SUM(inserted_records) as total_inserted,
    SUM(updated_records) as total_updated,
    SUM(employees_processed) as total_employees,
    AVG(duration_seconds) as avg_duration_seconds
FROM zkteco_pull_log
GROUP BY DATE(pull_date), schedule_type
ORDER BY date DESC, schedule_type;

-- Employee Device Mapping View
CREATE OR REPLACE VIEW v_employee_device_mapping AS
SELECT 
    e.id as employee_id,
    e.code as employee_code,
    e.name as employee_name,
    e.attendance_id,
    d.device_name,
    d.ip_address,
    zum.device_user_id,
    zum.shift_type,
    s.name as shift_name,
    zum.is_active as mapping_active
FROM employee e
LEFT JOIN zkteco_user_mapping zum ON e.id = zum.employee_id
LEFT JOIN zkteco_devices d ON zum.device_id = d.id
LEFT JOIN shifts s ON zum.shift_id = s.id
WHERE e.deleted_date IS NULL
ORDER BY e.code;

-- ----------------------------------------------------------------
-- 8. FUNCTIONS
-- ----------------------------------------------------------------

-- Function to log pull operation
CREATE OR REPLACE FUNCTION log_zkteco_pull(
    p_device_id INTEGER,
    p_schedule_type VARCHAR,
    p_total_records INTEGER,
    p_inserted INTEGER,
    p_updated INTEGER,
    p_skipped INTEGER,
    p_errors INTEGER,
    p_employees INTEGER,
    p_duration DECIMAL,
    p_status VARCHAR,
    p_error_message TEXT DEFAULT NULL
)
RETURNS INTEGER AS $$
DECLARE
    v_log_id INTEGER;
BEGIN
    INSERT INTO zkteco_pull_log (
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
    UPDATE zkteco_devices
    SET 
        last_pull_at = CURRENT_TIMESTAMP,
        last_pull_status = p_status,
        last_pull_records = p_total_records,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_device_id;
    
    RETURN v_log_id;
END;
$$ LANGUAGE plpgsql;

-- Function to update device connection status
CREATE OR REPLACE FUNCTION update_device_connection_status(
    p_device_id INTEGER,
    p_status VARCHAR
)
RETURNS VOID AS $$
BEGIN
    UPDATE zkteco_devices
    SET 
        connection_status = p_status,
        last_online_at = CASE WHEN p_status = 'ONLINE' THEN CURRENT_TIMESTAMP ELSE last_online_at END,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_device_id;
END;
$$ LANGUAGE plpgsql;

-- Function to clean old pull logs
CREATE OR REPLACE FUNCTION cleanup_zkteco_logs()
RETURNS INTEGER AS $$
DECLARE
    v_retention_days INTEGER;
    v_deleted INTEGER;
BEGIN
    -- Get retention setting
    SELECT value::INTEGER INTO v_retention_days
    FROM system_settings
    WHERE key = 'zkteco_pull_retention_days';
    
    IF v_retention_days IS NULL THEN
        v_retention_days := 30;
    END IF;
    
    -- Delete old logs
    DELETE FROM zkteco_pull_log
    WHERE pull_date < CURRENT_DATE - v_retention_days;
    
    GET DIAGNOSTICS v_deleted = ROW_COUNT;
    
    RETURN v_deleted;
END;
$$ LANGUAGE plpgsql;

-- ----------------------------------------------------------------
-- 9. SAMPLE DATA (for testing)
-- ----------------------------------------------------------------

-- Sample device configuration
INSERT INTO zkteco_devices (
    device_name, device_code, ip_address, port, location, priority
) VALUES 
('Main Entrance Device', 'ZK001', '192.168.1.100', 4370, 'Main Gate', 1),
('Production Floor Device', 'ZK002', '192.168.1.101', 4370, 'Manufacturing Area', 2)
ON CONFLICT (device_code) DO NOTHING;

-- Sample user mapping (adjust employee IDs as needed)
-- This assumes employees with IDs 1-10 exist
INSERT INTO zkteco_user_mapping (
    device_id, device_user_id, employee_id, shift_type
)
SELECT 
    1, -- device_id
    e.attendance_id,
    e.id,
    'REGULAR'
FROM employee e
WHERE e.id BETWEEN 1 AND 10
AND e.deleted_date IS NULL
AND e.attendance_id IS NOT NULL
ON CONFLICT (device_id, device_user_id) DO NOTHING;

-- ----------------------------------------------------------------
-- COMMENTS AND DOCUMENTATION
-- ----------------------------------------------------------------

COMMENT ON SCHEMA public IS 'ZKTeco Device Integration for Attendance Management';

-- Maintenance job for cleaning old logs (run weekly)
-- SELECT cleanup_zkteco_logs();

-- ================================================================
-- ZKTECO DEVICE INTEGRATION - COMPLETE DROP AND RECREATE
-- POSTGRESQL VERSION
-- ================================================================

-- Disable foreign key checks temporarily (for clean drop)
SET session_replication_role = 'replica';

-- ================================================================
-- DROP ALL DEPENDENT OBJECTS FIRST
-- ================================================================

-- Drop views
DROP VIEW IF EXISTS public.v_zkteco_device_status CASCADE;
DROP VIEW IF EXISTS public.v_zkteco_pull_statistics CASCADE;
DROP VIEW IF EXISTS public.v_employee_device_mapping CASCADE;

-- Drop functions
DROP FUNCTION IF EXISTS public.log_zkteco_pull(INTEGER, VARCHAR, INTEGER, INTEGER, INTEGER, INTEGER, INTEGER, INTEGER, DECIMAL, VARCHAR, TEXT) CASCADE;
DROP FUNCTION IF EXISTS public.update_device_connection_status(INTEGER, VARCHAR) CASCADE;
DROP FUNCTION IF EXISTS public.cleanup_zkteco_logs() CASCADE;
DROP FUNCTION IF EXISTS public.log_device_capacity(INTEGER) CASCADE;

-- Drop tables in reverse order of dependencies
DROP TABLE IF EXISTS public.zkteco_sync_queue CASCADE;
DROP TABLE IF EXISTS public.zkteco_capacity_log CASCADE;
DROP TABLE IF EXISTS public.zkteco_raw_attendance CASCADE;
DROP TABLE IF EXISTS public.zkteco_raw_data CASCADE;
DROP TABLE IF EXISTS public.zkteco_pull_log CASCADE;
DROP TABLE IF EXISTS public.zkteco_user_mapping CASCADE;
DROP TABLE IF EXISTS public.zkteco_devices CASCADE;
DROP TABLE IF EXISTS public.zkteco_settings CASCADE;
DROP TABLE IF EXISTS public.system_settings CASCADE;

-- Re-enable foreign key checks
SET session_replication_role = 'origin';

-- ================================================================
-- CREATE TABLES
-- ================================================================

-- ----------------------------------------------------------------
-- 1. ZKTECO DEVICES TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_devices
(
    id SERIAL PRIMARY KEY,
    device_code VARCHAR(50) UNIQUE NOT NULL,
    device_name VARCHAR(100) NOT NULL,
    ip_address VARCHAR(45) NOT NULL,
    port INTEGER DEFAULT 4370,
    location VARCHAR(200),
    device_model VARCHAR(100),
    serial_number VARCHAR(100),
    description TEXT,
    
    -- Configuration
    is_active BOOLEAN DEFAULT true,
    priority INTEGER DEFAULT 0,
    timeout INTEGER DEFAULT 5,
    disable_during_pull BOOLEAN DEFAULT true,
    auto_clear_records BOOLEAN DEFAULT false,
    
    -- Status
    connection_status VARCHAR(20) DEFAULT 'UNKNOWN', -- ONLINE, OFFLINE, UNKNOWN
    last_online_at TIMESTAMP,
    last_pull_at TIMESTAMP,
    last_pull_status VARCHAR(20), -- SUCCESS, FAILED, PARTIAL
    last_pull_records INTEGER DEFAULT 0,
    
    -- Capacity info
    total_users INTEGER DEFAULT 0,
    total_logs INTEGER DEFAULT 0,
    capacity_users INTEGER DEFAULT 0,
    capacity_logs INTEGER DEFAULT 0,
    
    -- Audit
    notes TEXT,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT chk_port CHECK (port > 0 AND port < 65536),
    CONSTRAINT chk_connection_status CHECK (connection_status IN ('ONLINE', 'OFFLINE', 'UNKNOWN'))
);

CREATE INDEX idx_zkteco_devices_active ON public.zkteco_devices(is_active);
CREATE INDEX idx_zkteco_devices_priority ON public.zkteco_devices(priority);
CREATE INDEX idx_zkteco_devices_status ON public.zkteco_devices(connection_status);
CREATE INDEX idx_zkteco_devices_code ON public.zkteco_devices(device_code);

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
    device_uid INTEGER NOT NULL,
    employee_id INTEGER NOT NULL REFERENCES public.employee(id) ON DELETE CASCADE,
    
    -- Shift configuration
    shift_id INTEGER REFERENCES public.shifts(id),
    shift_type VARCHAR(20) DEFAULT 'REGULAR', -- REGULAR, DUTY_24HR, FLEXIBLE
    
    -- Sync status
    is_active BOOLEAN DEFAULT true,
    synced_to_device BOOLEAN DEFAULT false,
    last_synced_at TIMESTAMP,
    
    -- Fingerprint data
    has_fingerprint BOOLEAN DEFAULT false,
    fingerprint_count INTEGER DEFAULT 0,
    
    -- Mapping metadata
    mapped_by INTEGER,
    mapped_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    notes TEXT,
    
    CONSTRAINT uq_device_user UNIQUE (device_id, device_user_id),
    CONSTRAINT chk_shift_type CHECK (shift_type IN ('REGULAR', 'DUTY_24HR', 'FLEXIBLE'))
);

CREATE INDEX idx_zkteco_mapping_device ON public.zkteco_user_mapping(device_id);
CREATE INDEX idx_zkteco_mapping_employee ON public.zkteco_user_mapping(employee_id);
CREATE INDEX idx_zkteco_mapping_device_user ON public.zkteco_user_mapping(device_user_id);
CREATE INDEX idx_zkteco_mapping_active ON public.zkteco_user_mapping(is_active);

COMMENT ON TABLE public.zkteco_user_mapping IS 'Maps device user IDs to employee records';

-- ----------------------------------------------------------------
-- 3. ZKTECO PULL LOG TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_pull_log
(
    id SERIAL PRIMARY KEY,
    device_id INTEGER REFERENCES public.zkteco_devices(id) ON DELETE CASCADE,
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
    status VARCHAR(20) NOT NULL, -- SUCCESS, FAILED, PARTIAL, RUNNING
    error_message TEXT,
    
    -- Timing
    started_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_at TIMESTAMP,
    duration_seconds DECIMAL(10,2),
    
    CONSTRAINT chk_pull_status CHECK (status IN ('SUCCESS', 'FAILED', 'PARTIAL', 'RUNNING'))
);

CREATE INDEX idx_zkteco_pull_log_date ON public.zkteco_pull_log(pull_date);
CREATE INDEX idx_zkteco_pull_log_device ON public.zkteco_pull_log(device_id);
CREATE INDEX idx_zkteco_pull_log_status ON public.zkteco_pull_log(status);
CREATE INDEX idx_zkteco_pull_log_schedule ON public.zkteco_pull_log(schedule_type);

COMMENT ON TABLE public.zkteco_pull_log IS 'Log of all ZKTeco data pull operations';

-- ----------------------------------------------------------------
-- 4. ZKTECO SYNC QUEUE TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_sync_queue
(
    id SERIAL PRIMARY KEY,
    device_id INTEGER NOT NULL REFERENCES public.zkteco_devices(id) ON DELETE CASCADE,
    employee_id INTEGER NOT NULL REFERENCES public.employee(id) ON DELETE CASCADE,
    
    -- Operation
    operation VARCHAR(20) NOT NULL, -- ADD_USER, UPDATE_USER, DELETE_USER, ADD_FINGERPRINT
    priority INTEGER DEFAULT 0,
    
    -- Data
    user_data JSONB,
    
    -- Status
    status VARCHAR(20) DEFAULT 'PENDING', -- PENDING, PROCESSING, COMPLETED, FAILED
    attempts INTEGER DEFAULT 0,
    max_attempts INTEGER DEFAULT 3,
    error_message TEXT,
    
    -- Timing
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    processed_at TIMESTAMP,
    completed_at TIMESTAMP,
    
    CONSTRAINT chk_operation CHECK (operation IN ('ADD_USER', 'UPDATE_USER', 'DELETE_USER', 'ADD_FINGERPRINT')),
    CONSTRAINT chk_sync_status CHECK (status IN ('PENDING', 'PROCESSING', 'COMPLETED', 'FAILED'))
);

CREATE INDEX idx_zkteco_sync_device ON public.zkteco_sync_queue(device_id);
CREATE INDEX idx_zkteco_sync_employee ON public.zkteco_sync_queue(employee_id);
CREATE INDEX idx_zkteco_sync_status ON public.zkteco_sync_queue(status);
CREATE INDEX idx_zkteco_sync_priority ON public.zkteco_sync_queue(priority);

COMMENT ON TABLE public.zkteco_sync_queue IS 'Queue for pushing data to devices';

-- ----------------------------------------------------------------
-- 5. ZKTECO CAPACITY LOG TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_capacity_log
(
    id SERIAL PRIMARY KEY,
    device_id INTEGER NOT NULL REFERENCES public.zkteco_devices(id) ON DELETE CASCADE,
    
    -- Capacity data
    users_count INTEGER DEFAULT 0,
    logs_count INTEGER DEFAULT 0,
    capacity_users INTEGER DEFAULT 0,
    capacity_logs INTEGER DEFAULT 0,
    
    -- Calculated
    users_percentage DECIMAL(5,2),
    logs_percentage DECIMAL(5,2),
    
    -- Timing
    logged_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT chk_users_pct CHECK (users_percentage BETWEEN 0 AND 100),
    CONSTRAINT chk_logs_pct CHECK (logs_percentage BETWEEN 0 AND 100)
);

CREATE INDEX idx_zkteco_capacity_device ON public.zkteco_capacity_log(device_id);
CREATE INDEX idx_zkteco_capacity_logged ON public.zkteco_capacity_log(logged_at);

COMMENT ON TABLE public.zkteco_capacity_log IS 'Track device capacity over time';

-- ----------------------------------------------------------------
-- 6. ZKTECO RAW ATTENDANCE TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_raw_attendance
(
    id SERIAL PRIMARY KEY,
    device_id INTEGER NOT NULL REFERENCES public.zkteco_devices(id) ON DELETE CASCADE,
    device_user_id VARCHAR(50) NOT NULL,
    device_uid INTEGER NOT NULL,
    
    -- Attendance data
    punch_time TIMESTAMP NOT NULL,
    punch_state INTEGER,
    punch_type VARCHAR(20),
    
    -- Processing status
    is_processed BOOLEAN DEFAULT false,
    processed_at TIMESTAMP,
    employee_id INTEGER REFERENCES public.employee(id),
    attendance_id INTEGER REFERENCES public.attendance(id),
    
    -- Metadata
    pulled_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    pull_log_id INTEGER REFERENCES public.zkteco_pull_log(id) ON DELETE SET NULL,
    
    -- Raw data for debugging
    raw_data TEXT
);

CREATE INDEX idx_zkteco_raw_device ON public.zkteco_raw_attendance(device_id);
CREATE INDEX idx_zkteco_raw_time ON public.zkteco_raw_attendance(punch_time);
CREATE INDEX idx_zkteco_raw_processed ON public.zkteco_raw_attendance(is_processed);
CREATE INDEX idx_zkteco_raw_device_user ON public.zkteco_raw_attendance(device_user_id);

COMMENT ON TABLE public.zkteco_raw_attendance IS 'Raw punch data from devices before processing';

-- ----------------------------------------------------------------
-- 7. ZKTECO SETTINGS TABLE (PostgreSQL version - fixed)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.zkteco_settings
(
    id SERIAL PRIMARY KEY,
    setting_key VARCHAR(100) NOT NULL UNIQUE,
    setting_value TEXT,
    setting_type VARCHAR(50) DEFAULT 'string',
    description TEXT,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX idx_zkteco_settings_key ON public.zkteco_settings(setting_key);

COMMENT ON TABLE public.zkteco_settings IS 'ZKTeco integration settings';

-- ----------------------------------------------------------------
-- 8. SYSTEM SETTINGS TABLE (keep for backward compatibility)
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

CREATE INDEX idx_system_settings_key ON public.system_settings(key);

-- ================================================================
-- INSERT DEFAULT SETTINGS
-- ================================================================

-- Insert into zkteco_settings
INSERT INTO public.zkteco_settings (setting_key, setting_value, setting_type, description) VALUES
('auto_pull_enabled', 'true', 'boolean', 'Enable automatic attendance pulls'),
('pull_schedule_morning', '07:35', 'time', 'Morning pull time'),
('pull_schedule_midmorning', '10:45', 'time', 'Mid-morning pull time'),
('pull_schedule_afternoon', '13:25', 'time', 'Afternoon pull time'),
('pull_schedule_evening', '17:25', 'time', 'Evening pull time'),
('pull_schedule_night', '19:15', 'time', 'Night pull time'),
('notification_enabled', 'true', 'boolean', 'Enable pull notifications'),
('notification_email', '', 'email', 'Email for notifications'),
('auto_sync_users', 'false', 'boolean', 'Auto sync users to devices'),
('clear_device_after_days', '30', 'integer', 'Clear device logs after days'),
('php_cli_path', '', 'path', 'Path to PHP CLI binary')
ON CONFLICT (setting_key) DO NOTHING;

-- Insert into system_settings (for backward compatibility)
INSERT INTO public.system_settings (key, value, description, setting_type) VALUES
('notify_attendance_pull', 'false', 'Send notification after attendance pull', 'BOOLEAN'),
('attendance_notification_email', '', 'Email address for attendance notifications', 'STRING'),
('zkteco_auto_pull_enabled', 'true', 'Enable automatic attendance pulling', 'BOOLEAN'),
('zkteco_pull_retention_days', '30', 'Days to keep pull logs', 'INTEGER'),
('zkteco_connection_retry', '3', 'Number of connection retry attempts', 'INTEGER')
ON CONFLICT (key) DO NOTHING;

-- ================================================================
-- ALTER ATTENDANCE TABLE (if exists)
-- ================================================================

DO $$
BEGIN
    -- Check if attendance table exists before altering
    IF EXISTS (SELECT 1 FROM information_schema.tables WHERE table_schema = 'public' AND table_name = 'attendance') THEN
        -- Add columns if they don't exist
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'attendance' AND column_name = 'data_source') THEN
            ALTER TABLE public.attendance ADD COLUMN data_source VARCHAR(20) DEFAULT 'MANUAL';
        END IF;
        
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'attendance' AND column_name = 'shift_type') THEN
            ALTER TABLE public.attendance ADD COLUMN shift_type VARCHAR(20);
        END IF;
        
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_schema = 'public' AND table_name = 'attendance' AND column_name = 'device_id') THEN
            ALTER TABLE public.attendance ADD COLUMN device_id INTEGER REFERENCES public.zkteco_devices(id);
        END IF;
        
        -- Create indexes
        CREATE INDEX IF NOT EXISTS idx_attendance_data_source ON public.attendance(data_source);
        CREATE INDEX IF NOT EXISTS idx_attendance_device ON public.attendance(device_id);
    END IF;
END $$;

-- ================================================================
-- CREATE VIEWS
-- ================================================================

-- ZKTeco Device Status View
CREATE OR REPLACE VIEW public.v_zkteco_device_status AS
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
    d.total_users,
    d.total_logs,
    d.capacity_users,
    d.capacity_logs,
    COUNT(DISTINCT zum.employee_id) as mapped_employees,
    (SELECT COUNT(*) FROM public.zkteco_pull_log WHERE device_id = d.id AND pull_date = CURRENT_DATE) as pulls_today
FROM public.zkteco_devices d
LEFT JOIN public.zkteco_user_mapping zum ON d.id = zum.device_id AND zum.is_active = true
GROUP BY d.id, d.device_name, d.device_code, d.ip_address, d.port, 
         d.location, d.is_active, d.connection_status, d.last_online_at,
         d.last_pull_at, d.last_pull_status, d.last_pull_records,
         d.total_users, d.total_logs, d.capacity_users, d.capacity_logs;

-- ZKTeco Pull Statistics View
CREATE OR REPLACE VIEW public.v_zkteco_pull_statistics AS
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
FROM public.zkteco_pull_log
GROUP BY DATE(pull_date), schedule_type
ORDER BY date DESC, schedule_type;

-- Employee Device Mapping View
CREATE OR REPLACE VIEW public.v_employee_device_mapping AS
SELECT 
    e.id as employee_id,
    e.code as employee_code,
    e.name as employee_name,
    e.attendance_id,
    d.device_name,
    d.ip_address,
    zum.device_user_id,
    zum.device_uid,
    zum.shift_type,
    s.name as shift_name,
    zum.is_active as mapping_active,
    zum.synced_to_device,
    zum.has_fingerprint,
    zum.fingerprint_count
FROM public.employee e
LEFT JOIN public.zkteco_user_mapping zum ON e.id = zum.employee_id
LEFT JOIN public.zkteco_devices d ON zum.device_id = d.id
LEFT JOIN public.shifts s ON zum.shift_id = s.id
WHERE e.deleted_date IS NULL
ORDER BY e.code;

-- ================================================================
-- CREATE FUNCTIONS
-- ================================================================

-- Function to log pull operation
CREATE OR REPLACE FUNCTION public.log_zkteco_pull(
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
$$ LANGUAGE plpgsql;

-- Function to update device connection status
CREATE OR REPLACE FUNCTION public.update_device_connection_status(
    p_device_id INTEGER,
    p_status VARCHAR
)
RETURNS VOID AS $$
BEGIN
    UPDATE public.zkteco_devices
    SET 
        connection_status = p_status,
        last_online_at = CASE WHEN p_status = 'ONLINE' THEN CURRENT_TIMESTAMP ELSE last_online_at END,
        updated_at = CURRENT_TIMESTAMP
    WHERE id = p_device_id;
END;
$$ LANGUAGE plpgsql;

-- Function to clean old pull logs
CREATE OR REPLACE FUNCTION public.cleanup_zkteco_logs()
RETURNS INTEGER AS $$
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
$$ LANGUAGE plpgsql;

-- Function to log device capacity
CREATE OR REPLACE FUNCTION public.log_device_capacity(
    p_device_id INTEGER
)
RETURNS INTEGER AS $$
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
$$ LANGUAGE plpgsql;

-- ================================================================
-- SAMPLE DATA (optional - comment out if not needed)
-- ================================================================

/*
-- Sample device configuration
INSERT INTO public.zkteco_devices (
    device_name, device_code, ip_address, port, location, priority, device_model
) VALUES 
('Main Entrance Device', 'ZK001', '192.168.1.100', 4370, 'Main Gate', 1, 'InBio Pro'),
('Production Floor Device', 'ZK002', '192.168.1.101', 4370, 'Manufacturing Area', 2, 'uFace 302')
ON CONFLICT (device_code) DO NOTHING;
*/

-- ================================================================
-- COMMENTS
-- ================================================================

COMMENT ON SCHEMA public IS 'ZKTeco Device Integration for Attendance Management';
-- ================================================================
-- ATTENDANCE MANAGEMENT SYSTEM - DATABASE SCHEMA
-- Nepalese HR System with Nepali Calendar Support
-- ================================================================

-- ----------------------------------------------------------------
-- 1. HOLIDAY TYPES TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.holiday_types
(
    id SERIAL PRIMARY KEY,
    type_name VARCHAR(100) NOT NULL,
    description TEXT,
    is_paid BOOLEAN DEFAULT true,
    color_code VARCHAR(7) DEFAULT '#FF0000',
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_holiday_type_name UNIQUE (type_name)
);

-- Insert default holiday types
INSERT INTO public.holiday_types (type_name, description, is_paid, color_code) VALUES
('Public Holiday', 'National public holidays', true, '#FF0000'),
('Festival Holiday', 'Cultural and religious festivals', true, '#FF6B6B'),
('Optional Holiday', 'Optional holidays for specific communities', true, '#FFA500'),
('Weekly Off', 'Regular weekly off days', true, '#4CAF50'),
('Compensatory Off', 'Compensatory leave for working on holidays', true, '#2196F3'),
('Emergency Holiday', 'Emergency declared holidays', true, '#9C27B0')
ON CONFLICT (type_name) DO NOTHING;

-- ----------------------------------------------------------------
-- 2. HOLIDAYS TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.holidays
(
    id SERIAL PRIMARY KEY,
    holiday_date_nep VARCHAR(20) NOT NULL,
    holiday_date_eng DATE NOT NULL,
    holiday_name VARCHAR(200) NOT NULL,
    holiday_type_id INTEGER REFERENCES public.holiday_types(id),
    fiscal_year VARCHAR(10) NOT NULL,
    is_active BOOLEAN DEFAULT true,
    remarks TEXT,
    created_by INTEGER,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_by INTEGER,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    CONSTRAINT uq_holiday_date_year UNIQUE (holiday_date_nep, fiscal_year)
);

CREATE INDEX idx_holidays_date_nep ON public.holidays(holiday_date_nep);
CREATE INDEX idx_holidays_date_eng ON public.holidays(holiday_date_eng);
CREATE INDEX idx_holidays_fiscal_year ON public.holidays(fiscal_year);
CREATE INDEX idx_holidays_type ON public.holidays(holiday_type_id);

-- ----------------------------------------------------------------
-- 3. ATTENDANCE STATUS TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.attendance_status
(
    id SERIAL PRIMARY KEY,
    status_code VARCHAR(10) NOT NULL UNIQUE,
    status_name VARCHAR(50) NOT NULL,
    description TEXT,
    is_present BOOLEAN DEFAULT true,
    affects_salary BOOLEAN DEFAULT true,
    color_code VARCHAR(7) DEFAULT '#000000',
    CONSTRAINT uq_attendance_status_code UNIQUE (status_code)
);

-- Insert default attendance statuses
INSERT INTO public.attendance_status (status_code, status_name, description, is_present, affects_salary, color_code) VALUES
('P', 'Present', 'Employee present for full day', true, true, '#4CAF50'),
('A', 'Absent', 'Employee absent without leave', false, true, '#F44336'),
('HD', 'Half Day', 'Employee present for half day', true, true, '#FF9800'),
('L', 'Leave', 'Employee on approved leave', false, false, '#2196F3'),
('WO', 'Weekly Off', 'Weekly off day', false, false, '#9E9E9E'),
('PH', 'Public Holiday', 'Public/Festival holiday', false, false, '#673AB7'),
('CL', 'Casual Leave', 'Casual leave taken', false, false, '#00BCD4'),
('SL', 'Sick Leave', 'Sick leave taken', false, false, '#E91E63'),
('PL', 'Paid Leave', 'Other paid leave', false, false, '#3F51B5'),
('LWP', 'Leave Without Pay', 'Unpaid leave', false, true, '#795548'),
('CO', 'Compensatory Off', 'Compensatory off for overtime', false, false, '#009688')
ON CONFLICT (status_code) DO NOTHING;

-- ----------------------------------------------------------------
-- 4. ATTENDANCE TABLE (Main)
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.attendance
(
    id SERIAL PRIMARY KEY,
    employee_id INTEGER NOT NULL REFERENCES public.employee(id),
    attendance_date_nep VARCHAR(20) NOT NULL,
    attendance_date_eng DATE NOT NULL,
    shift_id INTEGER REFERENCES public.shifts(id),
    status_id INTEGER NOT NULL REFERENCES public.attendance_status(id),
    
    -- Time tracking
    check_in_time TIME,
    check_out_time TIME,
    total_hours DECIMAL(5,2) DEFAULT 0,
    
    -- Break tracking
    break_hours DECIMAL(5,2) DEFAULT 0,
    actual_working_hours DECIMAL(5,2) DEFAULT 0,
    
    -- Overtime tracking
    ot_hours DECIMAL(5,2) DEFAULT 0,
    ot_approved BOOLEAN DEFAULT false,
    ot_rate DECIMAL(5,2) DEFAULT 1.5,
    
    -- Late/Early tracking
    late_arrival_minutes INTEGER DEFAULT 0,
    early_departure_minutes INTEGER DEFAULT 0,
    
    -- Additional info
    is_holiday BOOLEAN DEFAULT false,
    is_weekly_off BOOLEAN DEFAULT false,
    remarks TEXT,
    
    -- Audit fields
    marked_by INTEGER,
    marked_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    approved_by INTEGER,
    approved_at TIMESTAMP,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT uq_attendance_emp_date UNIQUE (employee_id, attendance_date_nep)
);

CREATE INDEX idx_attendance_employee ON public.attendance(employee_id);
CREATE INDEX idx_attendance_date_nep ON public.attendance(attendance_date_nep);
CREATE INDEX idx_attendance_date_eng ON public.attendance(attendance_date_eng);
CREATE INDEX idx_attendance_status ON public.attendance(status_id);
CREATE INDEX idx_attendance_shift ON public.attendance(shift_id);
CREATE INDEX idx_attendance_ot ON public.attendance(ot_hours) WHERE ot_hours > 0;

-- ----------------------------------------------------------------
-- 5. MONTHLY ATTENDANCE SUMMARY TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.attendance_monthly_summary
(
    id SERIAL PRIMARY KEY,
    employee_id INTEGER NOT NULL REFERENCES public.employee(id),
    year_month_nep VARCHAR(10) NOT NULL, -- Format: 2082.04
    fiscal_year VARCHAR(10) NOT NULL,
    
    -- Working days calculation
    total_working_days INTEGER DEFAULT 0,
    present_days DECIMAL(5,2) DEFAULT 0,
    absent_days DECIMAL(5,2) DEFAULT 0,
    half_days DECIMAL(5,2) DEFAULT 0,
    leave_days DECIMAL(5,2) DEFAULT 0,
    weekly_offs INTEGER DEFAULT 0,
    public_holidays INTEGER DEFAULT 0,
    
    -- Time calculation
    total_working_hours DECIMAL(8,2) DEFAULT 0,
    total_ot_hours DECIMAL(8,2) DEFAULT 0,
    total_late_minutes INTEGER DEFAULT 0,
    
    -- Deductions
    lwp_days DECIMAL(5,2) DEFAULT 0,
    late_deduction_days DECIMAL(5,2) DEFAULT 0,
    
    -- Payable days
    payable_days DECIMAL(5,2) DEFAULT 0,
    
    -- Status
    is_locked BOOLEAN DEFAULT false,
    locked_by INTEGER,
    locked_at TIMESTAMP,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT uq_monthly_summary UNIQUE (employee_id, year_month_nep)
);

CREATE INDEX idx_monthly_summary_employee ON public.attendance_monthly_summary(employee_id);
CREATE INDEX idx_monthly_summary_month ON public.attendance_monthly_summary(year_month_nep);
CREATE INDEX idx_monthly_summary_fiscal_year ON public.attendance_monthly_summary(fiscal_year);

-- ----------------------------------------------------------------
-- 6. OT (OVERTIME) RULES TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.ot_rules
(
    id SERIAL PRIMARY KEY,
    rule_name VARCHAR(100) NOT NULL,
    day_type VARCHAR(20) NOT NULL, -- 'WEEKDAY', 'WEEKEND', 'HOLIDAY'
    min_hours_for_ot DECIMAL(5,2) DEFAULT 8.0,
    ot_rate DECIMAL(5,2) DEFAULT 1.5,
    max_ot_hours_per_day DECIMAL(5,2) DEFAULT 4.0,
    requires_approval BOOLEAN DEFAULT true,
    is_active BOOLEAN DEFAULT true,
    effective_from DATE,
    effective_to DATE,
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

-- Insert default OT rules
INSERT INTO public.ot_rules (rule_name, day_type, min_hours_for_ot, ot_rate, max_ot_hours_per_day, effective_from) VALUES
('Weekday OT', 'WEEKDAY', 8.0, 1.5, 4.0, '2023-01-01'),
('Weekend OT', 'WEEKEND', 0.0, 2.0, 8.0, '2023-01-01'),
('Holiday OT', 'HOLIDAY', 0.0, 2.5, 8.0, '2023-01-01');

-- ----------------------------------------------------------------
-- 7. LEAVE BALANCE TABLE
-- ----------------------------------------------------------------
CREATE TABLE IF NOT EXISTS public.leave_balance
(
    id SERIAL PRIMARY KEY,
    employee_id INTEGER NOT NULL REFERENCES public.employee(id),
    fiscal_year VARCHAR(10) NOT NULL,
    leave_type VARCHAR(50) NOT NULL,
    
    total_allocated DECIMAL(5,2) DEFAULT 0,
    used_leaves DECIMAL(5,2) DEFAULT 0,
    balance_leaves DECIMAL(5,2) DEFAULT 0,
    
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    
    CONSTRAINT uq_leave_balance UNIQUE (employee_id, fiscal_year, leave_type)
);

CREATE INDEX idx_leave_balance_employee ON public.leave_balance(employee_id);
CREATE INDEX idx_leave_balance_fiscal_year ON public.leave_balance(fiscal_year);

-- ----------------------------------------------------------------
-- 8. VIEWS FOR REPORTING
-- ----------------------------------------------------------------

-- Daily Attendance Report View
CREATE OR REPLACE VIEW v_daily_attendance_report AS
SELECT 
    a.id,
    a.attendance_date_nep,
    a.attendance_date_eng,
    e.code as employee_code,
    e.name as employee_name,
    e.name_nep as employee_name_nep,
    d.name as designation,
    l.name as level,
    dept.name as department,
    s.name as shift,
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
FROM attendance a
JOIN employee e ON a.employee_id = e.id
LEFT JOIN designation d ON e.designation_id = d.id
LEFT JOIN level l ON e.level_id = l.id
LEFT JOIN department dept ON e.department_id = dept.id
LEFT JOIN shifts s ON a.shift_id = s.id
JOIN attendance_status ast ON a.status_id = ast.id
WHERE e.deleted_date IS NULL
ORDER BY a.attendance_date_eng DESC, e.code;

-- Monthly Summary Report View
CREATE OR REPLACE VIEW v_monthly_attendance_summary AS
SELECT 
    ams.id,
    ams.year_month_nep,
    ams.fiscal_year,
    e.code as employee_code,
    e.name as employee_name,
    e.name_nep as employee_name_nep,
    d.name as designation,
    l.name as level,
    dept.name as department,
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
FROM attendance_monthly_summary ams
JOIN employee e ON ams.employee_id = e.id
LEFT JOIN designation d ON e.designation_id = d.id
LEFT JOIN level l ON e.level_id = l.id
LEFT JOIN department dept ON e.department_id = dept.id
WHERE e.deleted_date IS NULL
ORDER BY ams.year_month_nep DESC, e.code;

-- Employee Attendance Statistics View
CREATE OR REPLACE VIEW v_employee_attendance_stats AS
SELECT 
    e.id as employee_id,
    e.code as employee_code,
    e.name as employee_name,
    d.name as designation,
    l.name as level,
    dept.name as department,
    COUNT(CASE WHEN ast.status_code = 'P' THEN 1 END) as total_present,
    COUNT(CASE WHEN ast.status_code = 'A' THEN 1 END) as total_absent,
    COUNT(CASE WHEN ast.status_code = 'HD' THEN 1 END) as total_half_days,
    COUNT(CASE WHEN ast.status_code IN ('L', 'CL', 'SL', 'PL') THEN 1 END) as total_leaves,
    SUM(a.ot_hours) as total_ot_hours,
    SUM(a.late_arrival_minutes) as total_late_minutes,
    ROUND(AVG(a.actual_working_hours), 2) as avg_working_hours
FROM employee e
LEFT JOIN attendance a ON e.id = a.employee_id
LEFT JOIN attendance_status ast ON a.status_id = ast.id
LEFT JOIN designation d ON e.designation_id = d.id
LEFT JOIN level l ON e.level_id = l.id
LEFT JOIN department dept ON e.department_id = dept.id
WHERE e.deleted_date IS NULL
GROUP BY e.id, e.code, e.name, d.name, l.name, dept.name;

-- ----------------------------------------------------------------
-- 9. FUNCTIONS FOR AUTOMATION
-- ----------------------------------------------------------------

-- Function to calculate actual working hours
CREATE OR REPLACE FUNCTION calculate_working_hours(
    p_check_in TIME,
    p_check_out TIME,
    p_break_hours DECIMAL DEFAULT 0
)
RETURNS DECIMAL AS $$
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
$$ LANGUAGE plpgsql;

-- Function to calculate OT hours
CREATE OR REPLACE FUNCTION calculate_ot_hours(
    p_working_hours DECIMAL,
    p_standard_hours DECIMAL DEFAULT 8.0,
    p_is_holiday BOOLEAN DEFAULT false,
    p_is_weekend BOOLEAN DEFAULT false
)
RETURNS DECIMAL AS $$
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
$$ LANGUAGE plpgsql;

-- Trigger to auto-calculate working hours and OT
CREATE OR REPLACE FUNCTION trg_calculate_attendance_hours()
RETURNS TRIGGER AS $$
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
$$ LANGUAGE plpgsql;

CREATE TRIGGER trg_attendance_calculate_hours
    BEFORE INSERT OR UPDATE ON public.attendance
    FOR EACH ROW
    EXECUTE FUNCTION trg_calculate_attendance_hours();

-- Function to update monthly summary
CREATE OR REPLACE FUNCTION update_monthly_summary(
    p_employee_id INTEGER,
    p_year_month_nep VARCHAR
)
RETURNS VOID AS $$
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
$$ LANGUAGE plpgsql;

-- ----------------------------------------------------------------
-- 10. SAMPLE HOLIDAY DATA FOR FISCAL YEAR 2082
-- ----------------------------------------------------------------

-- Public Holidays for 2082 (Sample - adjust as per actual calendar)
INSERT INTO public.holidays (holiday_date_nep, holiday_date_eng, holiday_name, holiday_type_id, fiscal_year) VALUES
('2082.01.01', '2025-04-14', 'Nepali New Year', 1, '2082'),
('2082.01.11', '2025-04-24', 'Ram Navami', 1, '2082'),
('2082.02.01', '2025-05-14', 'Buddha Jayanti', 1, '2082'),
('2082.03.15', '2025-06-28', 'Eid al-Adha', 1, '2082'),
('2082.05.01', '2025-08-16', 'Janai Purnima', 1, '2082'),
('2082.05.08', '2025-08-23', 'Gaijatra', 2, '2082'),
('2082.05.23', '2025-09-07', 'Krishna Janmashtami', 1, '2082'),
('2082.06.07', '2025-09-22', 'Teej', 1, '2082'),
('2082.06.23', '2025-10-08', 'Ghatasthapana', 1, '2082'),
('2082.07.01', '2025-10-16', 'Fulpati', 1, '2082'),
('2082.07.02', '2025-10-17', 'Maha Astami', 1, '2082'),
('2082.07.03', '2025-10-18', 'Maha Navami', 1, '2082'),
('2082.07.04', '2025-10-19', 'Vijaya Dashami', 1, '2082'),
('2082.07.19', '2025-11-03', 'Laxmi Puja', 1, '2082'),
('2082.07.20', '2025-11-04', 'Gobardhan Puja', 1, '2082'),
('2082.07.21', '2025-11-05', 'Bhai Tika', 1, '2082'),
('2082.08.24', '2025-12-09', 'Udhauli Parva', 2, '2082'),
('2082.09.15', '2025-12-30', 'Tamu Lhosar', 2, '2082'),
('2082.10.01', '2026-01-15', 'Maghe Sankranti', 1, '2082'),
('2082.10.07', '2026-01-21', 'Sonam Lhosar', 2, '2082'),
('2082.11.07', '2026-02-20', 'Maha Shivaratri', 1, '2082'),
('2082.11.18', '2026-03-03', 'Fagu Purnima/Holi', 1, '2082'),
('2082.12.01', '2026-03-15', 'Gyalpo Lhosar', 2, '2082')
ON CONFLICT DO NOTHING;

-- ----------------------------------------------------------------
-- COMMENTS AND DOCUMENTATION
-- ----------------------------------------------------------------

COMMENT ON TABLE public.holidays IS 'Stores all types of holidays including public holidays, festivals, and special occasions';
COMMENT ON TABLE public.attendance IS 'Main attendance table tracking daily employee attendance with time, OT, and status';
COMMENT ON TABLE public.attendance_monthly_summary IS 'Monthly aggregated attendance data for payroll processing';
COMMENT ON TABLE public.ot_rules IS 'Overtime calculation rules based on day type and working hours';
COMMENT ON TABLE public.leave_balance IS 'Leave balance tracking for each employee by fiscal year';

COMMENT ON COLUMN attendance.ot_hours IS 'Overtime hours worked beyond standard hours';
COMMENT ON COLUMN attendance.ot_rate IS 'OT rate multiplier (1.5x for weekday, 2x for weekend, 2.5x for holiday)';
COMMENT ON COLUMN attendance.late_arrival_minutes IS 'Minutes late from shift start time';
COMMENT ON COLUMN attendance_monthly_summary.payable_days IS 'Total days eligible for salary payment';

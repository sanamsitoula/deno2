-- Migration 004: Proper Leave Types + Hajiri (Attendance) enhancements
-- Based on JEMC Excel data analysis: OT Permanent, Talab Permanent,
-- Contract Guard Talab OT, Contract Talab OT, Ghar Bida files

-- ── 1. Drop and recreate holiday_types with full schema ───────────────────────
ALTER TABLE holiday_types
    ADD COLUMN IF NOT EXISTS type_code       VARCHAR(10),
    ADD COLUMN IF NOT EXISTS carry_forward   BOOLEAN DEFAULT false,
    ADD COLUMN IF NOT EXISTS max_carry_days  INTEGER DEFAULT 0,    -- 0 = unlimited
    ADD COLUMN IF NOT EXISTS annual_days     NUMERIC(5,2) DEFAULT 0,
    ADD COLUMN IF NOT EXISTS is_personal     BOOLEAN DEFAULT true,  -- false = public holiday (no employee quota)
    ADD COLUMN IF NOT EXISTS requires_approval BOOLEAN DEFAULT true,
    ADD COLUMN IF NOT EXISTS half_day_allowed  BOOLEAN DEFAULT true,
    ADD COLUMN IF NOT EXISTS applies_to      VARCHAR(20) DEFAULT 'ALL',  -- ALL|PERMANENT|CONTRACT
    ADD COLUMN IF NOT EXISTS sort_order      INTEGER DEFAULT 0;

-- ── 2. Update existing holiday types ─────────────────────────────────────────
UPDATE holiday_types SET type_code='PUB',  carry_forward=false, is_personal=false, annual_days=0, sort_order=1 WHERE id=1;  -- Public Holiday
UPDATE holiday_types SET type_code='FEST', carry_forward=false, is_personal=false, annual_days=0, sort_order=2 WHERE id=2;  -- Festival Holiday
UPDATE holiday_types SET type_code='OPT',  carry_forward=false, is_personal=false, annual_days=0, sort_order=3 WHERE id=3;  -- Optional Holiday
UPDATE holiday_types SET type_code='SAT',  carry_forward=false, is_personal=false, annual_days=0, sort_order=4 WHERE id=4;  -- Weekly Off
UPDATE holiday_types SET type_code='COMP', carry_forward=false, is_personal=true,  annual_days=0, sort_order=5 WHERE id=5;  -- Compensatory
UPDATE holiday_types SET type_code='EMRG', carry_forward=false, is_personal=false, annual_days=0, sort_order=6 WHERE id=6;  -- Emergency

-- ── 3. Add JEMC-specific leave types ─────────────────────────────────────────
INSERT INTO holiday_types (type_name, description, is_paid, color_code, type_code,
    carry_forward, max_carry_days, annual_days, is_personal, requires_approval,
    half_day_allowed, applies_to, sort_order)
VALUES
    -- घर बिदा (Home Leave) — 30 days/year, unlimited carry-forward, paid
    ('घर बिदा',     'Home/Annual Leave — carries forward unlimited (Nepal Civil Service)',
     true,  '#2196F3', 'HOME',  true,  0,   30,   true,  true,  true,  'ALL',       10),

    -- बिरामी बिदा (Sick Leave) — 12 days/year, NO carry-forward
    ('बिरामी बिदा', 'Sick Leave — expires each fiscal year, no carry-forward',
     true,  '#FF9800', 'SICK',  false, 0,   12,   true,  false, true,  'ALL',       11),

    -- शोक बिदा (Bereavement Leave) — limited, paid
    ('शोक बिदा',   'Bereavement/Mourning Leave',
     true,  '#607D8B', 'MOURNING', false, 0, 13, true, true,  false, 'ALL',       12),

    -- काज (On Duty/Deputation) — counts as present
    ('काज',         'On Duty / Deputation — counted as present day',
     true,  '#00BCD4', 'DUTY',  false, 0,   0,    true,  false, true,  'ALL',       13),

    -- भैपरी (Half Day)
    ('भैपरी बिदा',  'Half Day Leave',
     true,  '#9C27B0', 'HALF',  false, 0,   0,    true,  true,  true,  'ALL',       14),

    -- प्रसूति बिदा (Maternity Leave) — 60 days, paid
    ('प्रसूति बिदा','Maternity Leave — 60 days paid',
     true,  '#E91E63', 'MAT',   false, 0,   60,   true,  true,  false, 'ALL',       15),

    -- अनुपस्थित (Absent without leave) — unpaid
    ('अनुपस्थित',  'Absent Without Leave — unpaid deduction',
     false, '#F44336', 'ABS',   false, 0,   0,    true,  false, false, 'ALL',       20),

    -- वैकल्पिक बिदा (Optional/Substitute holiday)
    ('वैकल्पिक बिदा','Optional Holiday / Substitute Leave',
     true,  '#8BC34A', 'OPTL',  false, 0,   0,    false, false, false, 'ALL',       21),

    -- राष्ट्रिय बिदा (National Holiday)
    ('राष्ट्रिय बिदा','National/State Holiday',
     true,  '#F44336', 'NAT',   false, 0,   0,    false, false, false, 'ALL',       22)

ON CONFLICT (type_name) DO UPDATE SET
    type_code        = EXCLUDED.type_code,
    carry_forward    = EXCLUDED.carry_forward,
    annual_days      = EXCLUDED.annual_days,
    is_personal      = EXCLUDED.is_personal,
    sort_order       = EXCLUDED.sort_order;

-- ── 4. Add display_code to attendance_status for hajiri report ────────────────
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='attendance_status' AND column_name='display_code') THEN
        ALTER TABLE attendance_status ADD COLUMN display_code VARCHAR(5);
        ALTER TABLE attendance_status ADD COLUMN holiday_type_code VARCHAR(10);
    END IF;
END $$;

-- Update attendance_status with display codes matching the hajiri report
UPDATE attendance_status SET display_code='√',  holiday_type_code='WORK'   WHERE status_code='P';
UPDATE attendance_status SET display_code='X',  holiday_type_code='ABS'    WHERE status_code='A';
UPDATE attendance_status SET display_code='½',  holiday_type_code='HALF'   WHERE status_code='HD';
UPDATE attendance_status SET display_code='घ',  holiday_type_code='HOME'   WHERE status_code='L';
UPDATE attendance_status SET display_code='ह',  holiday_type_code='HOLIDAY' WHERE status_code='H';

-- Insert missing attendance statuses
INSERT INTO attendance_status (status_name, status_code, display_code, holiday_type_code) VALUES
    ('बिरामी बिदा',   'SL', 'बि',  'SICK'),
    ('घर बिदा',       'HL', 'घ',   'HOME'),
    ('शोक बिदा',      'BL', 'शो',  'MOURNING'),
    ('काज',           'DD', 'का',  'DUTY'),
    ('शनि बार',       'SAT','शनि', 'SAT'),
    ('राष्ट्रिय बिदा','NH', 'रा',  'NAT'),
    ('वैकल्पिक बिदा', 'OL', 'वै',  'OPTL'),
    ('सार्वजनिक बिदा','PH', 'सा',  'PUB'),
    ('अनुपस्थित',     'UL', 'X',   'ABS')
ON CONFLICT (status_code) DO NOTHING;

-- ── 5. Update leave_balance with carry-forward tracking ───────────────────────
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='leave_balance' AND column_name='carried_forward') THEN
        ALTER TABLE leave_balance
            ADD COLUMN carried_forward  NUMERIC(5,2) DEFAULT 0,
            ADD COLUMN annual_allocated NUMERIC(5,2) DEFAULT 0;
    END IF;
END $$;

-- ── 6. OT tracking table (for bi-monthly OT like the Excel) ──────────────────
CREATE TABLE IF NOT EXISTS employee_ot_records (
    id              SERIAL PRIMARY KEY,
    employee_id     INTEGER NOT NULL REFERENCES employee(id),
    ot_year         SMALLINT NOT NULL,
    ot_month        SMALLINT NOT NULL,       -- BS month (1=Baisakh...12=Chaitra)
    ot_month_nep    VARCHAR(20),             -- 'Baisakh', 'Magh' etc.
    ot_hours_first  NUMERIC(6,2) DEFAULT 0, -- 1st-15th BS days
    ot_hours_second NUMERIC(6,2) DEFAULT 0, -- 16th-end BS days
    ot_hours_total  NUMERIC(6,2) GENERATED ALWAYS AS (ot_hours_first + ot_hours_second) STORED,
    ot_rate         NUMERIC(5,2) DEFAULT 1.5,
    remarks         TEXT,
    entered_by      INTEGER,
    created_at      TIMESTAMP DEFAULT NOW(),
    UNIQUE(employee_id, ot_year, ot_month)
);

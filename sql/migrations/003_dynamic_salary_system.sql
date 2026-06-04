-- Migration 003: Dynamic Salary Component System
-- Grade-based salary scales + per-employee component overrides
-- Run once on press_jemc

-- ── 1. Salary Grade / Scale (level-based pay scales) ─────────────────────────
CREATE TABLE IF NOT EXISTS salary_grades (
    id              SERIAL PRIMARY KEY,
    grade_code      VARCHAR(20) UNIQUE NOT NULL,   -- e.g. 'GRADE-6', 'GRADE-8'
    grade_name      VARCHAR(100) NOT NULL,          -- e.g. 'शाखा अधिकृत (Level 6)'
    level_id        INTEGER REFERENCES level(id),   -- links to level table
    emp_type        VARCHAR(20),                    -- PERMANENT|CONTRACT|DAILY_WAGES|NULL=all
    opening_basic   NUMERIC(12,2) NOT NULL DEFAULT 0,  -- minimum basic for this grade
    mid_basic       NUMERIC(12,2) DEFAULT 0,            -- mid-point
    max_basic       NUMERIC(12,2) DEFAULT 0,            -- maximum basic
    increment_amount NUMERIC(10,2) DEFAULT 0,           -- annual increment per step
    fiscal_year_id  INTEGER REFERENCES fiscal_years(id),
    is_active       BOOLEAN DEFAULT true,
    created_at      TIMESTAMP DEFAULT NOW()
);

-- ── 2. Salary Component Master (enhanced) ────────────────────────────────────
-- Add percentage_base column to existing table
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='salary_components' AND column_name='percentage_base') THEN
        ALTER TABLE salary_components ADD COLUMN percentage_base VARCHAR(20) DEFAULT 'BASIC';
        -- BASIC | GROSS | FIXED_AMOUNT
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='salary_components' AND column_name='applies_to') THEN
        ALTER TABLE salary_components ADD COLUMN applies_to VARCHAR(20) DEFAULT 'ALL';
        -- ALL | PERMANENT | CONTRACT | DAILY_WAGES | TECHNICAL
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='salary_components' AND column_name='component_order') THEN
        ALTER TABLE salary_components ADD COLUMN component_order INTEGER DEFAULT 0;
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='salary_components' AND column_name='description') THEN
        ALTER TABLE salary_components ADD COLUMN description TEXT;
    END IF;
END $$;

-- ── 3. Grade-Component Defaults (default components for a grade) ─────────────
-- Defines which components apply to a grade and at what value
CREATE TABLE IF NOT EXISTS grade_salary_components (
    id              SERIAL PRIMARY KEY,
    grade_id        INTEGER NOT NULL REFERENCES salary_grades(id) ON DELETE CASCADE,
    component_id    INTEGER NOT NULL REFERENCES salary_components(id) ON DELETE CASCADE,
    calculation_type VARCHAR(20) NOT NULL DEFAULT 'FIXED',  -- FIXED | PERCENTAGE | FORMULA
    fixed_amount    NUMERIC(12,2) DEFAULT 0,   -- used if FIXED
    percentage_value NUMERIC(5,2) DEFAULT 0,   -- used if PERCENTAGE (of basic)
    is_mandatory    BOOLEAN DEFAULT true,       -- can employee opt out?
    is_active       BOOLEAN DEFAULT true,
    UNIQUE(grade_id, component_id)
);

-- ── 4. Employee Salary Structure (replaces simple employee_salary) ───────────
-- Keep employee_salary for basic, add this for full component breakdown
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='employee_salary' AND column_name='grade_id') THEN
        ALTER TABLE employee_salary ADD COLUMN grade_id INTEGER REFERENCES salary_grades(id);
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='employee_salary' AND column_name='grade_step') THEN
        ALTER TABLE employee_salary ADD COLUMN grade_step INTEGER DEFAULT 1;  -- step within grade
    END IF;
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns WHERE table_name='employee_salary' AND column_name='remarks') THEN
        ALTER TABLE employee_salary ADD COLUMN remarks TEXT;
    END IF;
END $$;

-- ── 5. Employee Component Overrides (per-employee exceptions) ─────────────────
-- Override or add specific components for individual employees
CREATE TABLE IF NOT EXISTS employee_salary_components (
    id              SERIAL PRIMARY KEY,
    employee_id     INTEGER NOT NULL REFERENCES employee(id) ON DELETE CASCADE,
    component_id    INTEGER NOT NULL REFERENCES salary_components(id) ON DELETE CASCADE,
    calculation_type VARCHAR(20) NOT NULL DEFAULT 'FIXED',
    fixed_amount    NUMERIC(12,2) DEFAULT 0,
    percentage_value NUMERIC(5,2) DEFAULT 0,
    is_active       BOOLEAN DEFAULT true,
    effective_from  DATE NOT NULL DEFAULT CURRENT_DATE,
    effective_to    DATE,
    remarks         TEXT,
    created_by      INTEGER,
    created_at      TIMESTAMP DEFAULT NOW(),
    UNIQUE(employee_id, component_id, effective_from)
);

-- ── 6. Seed: Update salary_components with better data ───────────────────────
UPDATE salary_components SET
    component_order = 1, description = 'Base monthly salary as per grade scale', applies_to = 'ALL'
WHERE component_code = 'BASIC';

UPDATE salary_components SET
    component_order = 2, description = 'Overtime at 1.5× hourly rate', applies_to = 'ALL'
WHERE component_code = 'OT';

UPDATE salary_components SET
    component_order = 3, description = '11% of basic — employee SSF contribution', applies_to = 'ALL', percentage_base = 'BASIC'
WHERE component_code = 'SSF';

UPDATE salary_components SET
    component_order = 4, description = '10% of basic — Provident Fund (PERMANENT only)', applies_to = 'PERMANENT', percentage_base = 'BASIC'
WHERE component_code = 'PF';

UPDATE salary_components SET
    component_order = 5, description = 'Monthly TDS based on annual income tax slabs', applies_to = 'ALL'
WHERE component_code = 'TDS';

-- ── 7. Seed: Common salary components for Nepal ──────────────────────────────
INSERT INTO salary_components (component_code, component_name, component_type, calculation_type,
    percentage_base, default_value, is_taxable, is_active, applies_to, component_order, description)
SELECT * FROM (VALUES
    ('DA',      'Dearness Allowance',    'EARNING',   'PERCENTAGE', 'BASIC',  0.00, true,  true, 'ALL',       6,  '% of basic salary'),
    ('HRA',     'House Rent Allowance',  'EARNING',   'PERCENTAGE', 'BASIC',  0.00, true,  true, 'ALL',       7,  '% of basic salary'),
    ('TA',      'Travel Allowance',      'EARNING',   'FIXED',      'BASIC',  0.00, false, true, 'ALL',       8,  'Monthly travel/transport allowance'),
    ('PETROL',  'Petrol Allowance',      'EARNING',   'FIXED',      'BASIC',  0.00, false, true, 'ALL',       9,  'Fuel allowance for vehicle use'),
    ('MEDICAL', 'Medical Allowance',     'EARNING',   'FIXED',      'BASIC',  0.00, false, true, 'ALL',       10, 'Monthly medical allowance'),
    ('PHONE',   'Phone/Communication',   'EARNING',   'FIXED',      'BASIC',  0.00, false, true, 'ALL',       11, 'Mobile/internet allowance'),
    ('UNIFORM', 'Uniform Allowance',     'EARNING',   'FIXED',      'BASIC',  0.00, false, true, 'ALL',       12, 'Annual uniform allowance (monthly portion)'),
    ('FESTIVAL','Festival Bonus',        'EARNING',   'PERCENTAGE', 'BASIC',  0.00, true,  true, 'ALL',       13, 'Festival/dashain allowance'),
    ('RISK',    'Risk Allowance',        'EARNING',   'FIXED',      'BASIC',  0.00, false, true, 'TECHNICAL', 14, 'Risk/hazard allowance for technical staff'),
    ('ABSENT',  'Absence Deduction',     'DEDUCTION', 'FORMULA',    'BASIC',  0.00, false, true, 'ALL',       20, 'Per-day deduction for absent days'),
    ('ADVANCE', 'Salary Advance',        'DEDUCTION', 'FIXED',      'BASIC',  0.00, false, true, 'ALL',       21, 'Loan/advance recovery'),
    ('FINE',    'Fine/Penalty',          'DEDUCTION', 'FIXED',      'BASIC',  0.00, false, true, 'ALL',       22, 'Disciplinary fine')
) AS v(code, name, ctype, calc, base, val, taxable, active, applies, ord, cdesc)
WHERE NOT EXISTS (SELECT 1 FROM salary_components WHERE component_code = v.code);
-- Fix column alias reference (desc is reserved word, renamed to cdesc above)

-- ── 8. Seed: Grade scales for Nepal government-style levels ──────────────────
DO $$
DECLARE fy_id INTEGER;
BEGIN
    SELECT id INTO fy_id FROM fiscal_years WHERE is_active=true LIMIT 1;

    INSERT INTO salary_grades (grade_code, grade_name, level_id, opening_basic, mid_basic, max_basic, increment_amount, fiscal_year_id)
    SELECT * FROM (VALUES
        ('GRADE-1',  'Level 1 — का.स.चतूर्थस्तर', 1,  13500, 16000, 20000, 500,  fy_id),
        ('GRADE-2',  'Level 2 — Post',               2,  15000, 18000, 22000, 600,  fy_id),
        ('GRADE-3',  'Level 3 — उपसहायक',            3,  17000, 21000, 26000, 700,  fy_id),
        ('GRADE-4',  'Level 4 — सहायक',              4,  20000, 25000, 32000, 800,  fy_id),
        ('GRADE-5',  'Level 5 — ब-सहायक',            5,  24000, 30000, 38000, 1000, fy_id),
        ('GRADE-6',  'Level 6 — शाखा अधिकृत',        6,  30000, 38000, 48000, 1200, fy_id),
        ('GRADE-7',  'Level 7 — उपप्रबन्धक',         7,  38000, 48000, 60000, 1500, fy_id),
        ('GRADE-8',  'Level 8 — प्रबन्धक',            8,  48000, 60000, 75000, 2000, fy_id),
        ('GRADE-9',  'Level 9 — उपनिर्देशक',         9,  60000, 75000, 95000, 2500, fy_id),
        ('GRADE-10', 'Level 10 — निर्देशक',          10, 75000, 95000, 120000,3000, fy_id)
    ) AS v(code, name, level_id, opening, mid, max_b, increment, fy)
    WHERE NOT EXISTS (SELECT 1 FROM salary_grades WHERE grade_code = v.code);
END $$;

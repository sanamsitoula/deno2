-- Migration 002: Payroll & Tax tables
-- Run once against press_jemc database (idempotent)

-- ── Tax slabs ───────────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS tax_slabs (
    id              SERIAL PRIMARY KEY,
    fiscal_year_id  INTEGER REFERENCES fiscal_years(id),
    taxpayer_type   VARCHAR(20) NOT NULL CHECK (taxpayer_type IN ('SINGLE','COUPLE')),
    slab_order      SMALLINT    NOT NULL,
    income_from     NUMERIC(14,2) NOT NULL,
    income_to       NUMERIC(14,2),        -- NULL = no upper limit
    tax_rate        NUMERIC(5,4) NOT NULL, -- e.g. 0.01 = 1%
    is_active       BOOLEAN DEFAULT true,
    UNIQUE (fiscal_year_id, taxpayer_type, slab_order)
);

-- ── SSF / PF / statutory rates ──────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS statutory_rates (
    id              SERIAL PRIMARY KEY,
    fiscal_year_id  INTEGER REFERENCES fiscal_years(id),
    rate_type       VARCHAR(30) NOT NULL CHECK (rate_type IN (
                        'SSF_EMPLOYEE','SSF_EMPLOYER','PF_EMPLOYEE','PF_EMPLOYER')),
    rate_value      NUMERIC(5,4) NOT NULL,
    effective_from  DATE NOT NULL,
    effective_to    DATE,
    notes           TEXT
);

-- ── Salary component master ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS salary_components (
    id               SERIAL PRIMARY KEY,
    component_code   VARCHAR(20) UNIQUE NOT NULL,
    component_name   VARCHAR(100) NOT NULL,
    component_type   VARCHAR(20) NOT NULL CHECK (component_type IN ('EARNING','DEDUCTION','STATUTORY')),
    calculation_type VARCHAR(20) NOT NULL CHECK (calculation_type IN ('FIXED','PERCENTAGE','FORMULA')),
    calculation_base VARCHAR(50),           -- NULL for FIXED, 'BASIC' for PERCENTAGE
    default_value    NUMERIC(10,2) DEFAULT 0,
    is_taxable       BOOLEAN DEFAULT true,
    is_active        BOOLEAN DEFAULT true
);

-- ── Employee salary history ─────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employee_salary (
    id             SERIAL PRIMARY KEY,
    employee_id    INTEGER NOT NULL REFERENCES employee(id),
    basic_salary   NUMERIC(12,2) NOT NULL,
    daily_wage_rate NUMERIC(10,2),
    salary_mode    VARCHAR(20) DEFAULT 'MONTHLY' CHECK (salary_mode IN ('MONTHLY','DAILY')),
    effective_from DATE NOT NULL,
    effective_to   DATE,
    is_current     BOOLEAN DEFAULT true,
    created_by     INTEGER,
    created_at     TIMESTAMP DEFAULT NOW(),
    updated_at     TIMESTAMP DEFAULT NOW()
);

-- Ensure only one current salary per employee
CREATE UNIQUE INDEX IF NOT EXISTS uidx_employee_salary_current
    ON employee_salary (employee_id)
    WHERE is_current = true;

-- ── YTD tax records ─────────────────────────────────────────────────────────
CREATE TABLE IF NOT EXISTS employee_tax_records (
    id                  SERIAL PRIMARY KEY,
    employee_id         INTEGER NOT NULL REFERENCES employee(id),
    fiscal_year_id      INTEGER REFERENCES fiscal_years(id),
    taxpayer_type       VARCHAR(20) DEFAULT 'SINGLE',
    ytd_gross_income    NUMERIC(14,2) DEFAULT 0,
    ytd_ssf_employee    NUMERIC(12,2) DEFAULT 0,
    ytd_pf_employee     NUMERIC(12,2) DEFAULT 0,
    ytd_income_tax      NUMERIC(12,2) DEFAULT 0,
    ytd_net_paid        NUMERIC(14,2) DEFAULT 0,
    last_updated_at     TIMESTAMP DEFAULT NOW(),
    UNIQUE (employee_id, fiscal_year_id)
);

-- ── Extend payroll_processing (add columns if missing) ──────────────────────
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                   WHERE table_name='payroll_processing' AND column_name='total_gross') THEN
        ALTER TABLE payroll_processing
            ADD COLUMN total_gross       NUMERIC(14,2) DEFAULT 0,
            ADD COLUMN total_deductions  NUMERIC(14,2) DEFAULT 0,
            ADD COLUMN total_net_payable NUMERIC(14,2) DEFAULT 0,
            ADD COLUMN total_employees   INTEGER DEFAULT 0,
            ADD COLUMN payroll_code      VARCHAR(30),
            ADD COLUMN from_date         DATE,
            ADD COLUMN to_date           DATE,
            ADD COLUMN processed_by      INTEGER,
            ADD COLUMN processed_at      TIMESTAMP,
            ADD COLUMN approved_by       INTEGER,
            ADD COLUMN approved_at       TIMESTAMP;
    END IF;
END $$;

-- Status constraint on payroll_processing
DO $$
BEGIN
    IF NOT EXISTS (SELECT 1 FROM pg_constraint WHERE conname = 'payroll_processing_status_check') THEN
        ALTER TABLE payroll_processing
            ADD CONSTRAINT payroll_processing_status_check
            CHECK (status IN ('DRAFT','CALCULATED','APPROVED','PAID','LOCKED'));
    END IF;
END $$;

-- ── Extend payroll_details (add columns if missing) ─────────────────────────
DO $$
DECLARE cols TEXT[] := ARRAY[
    'total_working_days SMALLINT',
    'total_present_days SMALLINT',
    'total_absent_days  SMALLINT',
    'total_leaves       SMALLINT',
    'total_holidays     SMALLINT',
    'total_paid_days    NUMERIC(5,2)',
    'overtime_hours     NUMERIC(6,2) DEFAULT 0',
    'overtime_amount    NUMERIC(12,2) DEFAULT 0',
    'total_earnings     NUMERIC(12,2)',
    'gross_salary       NUMERIC(12,2)',
    'ssf_employee       NUMERIC(12,2) DEFAULT 0',
    'ssf_employer       NUMERIC(12,2) DEFAULT 0',
    'pf_employee        NUMERIC(12,2) DEFAULT 0',
    'pf_employer        NUMERIC(12,2) DEFAULT 0',
    'income_tax         NUMERIC(12,2) DEFAULT 0',
    'other_deductions   NUMERIC(12,2) DEFAULT 0',
    'total_deductions   NUMERIC(12,2)',
    'net_payable        NUMERIC(12,2)'
];
col_def TEXT;
col_name TEXT;
BEGIN
    FOREACH col_def IN ARRAY cols LOOP
        col_name := split_part(trim(col_def), ' ', 1);
        IF NOT EXISTS (SELECT 1 FROM information_schema.columns
                       WHERE table_name='payroll_details' AND column_name=col_name) THEN
            EXECUTE 'ALTER TABLE payroll_details ADD COLUMN ' || col_def;
        END IF;
    END LOOP;
END $$;

-- ── Seed: Nepal income tax slabs FY 2081/82 ─────────────────────────────────
-- Run only if no slabs exist for any year; adjust fiscal_year_id to match your DB
DO $$
DECLARE fy_id INTEGER;
BEGIN
    -- Find the most recent fiscal year
    SELECT id INTO fy_id FROM fiscal_years ORDER BY start_date DESC LIMIT 1;

    IF fy_id IS NOT NULL AND NOT EXISTS (SELECT 1 FROM tax_slabs WHERE fiscal_year_id = fy_id) THEN
        -- SINGLE taxpayer
        INSERT INTO tax_slabs (fiscal_year_id, taxpayer_type, slab_order, income_from, income_to, tax_rate) VALUES
            (fy_id, 'SINGLE', 1,       0,  500000, 0.01),
            (fy_id, 'SINGLE', 2,  500000,  700000, 0.10),
            (fy_id, 'SINGLE', 3,  700000, 1000000, 0.20),
            (fy_id, 'SINGLE', 4, 1000000, 2000000, 0.30),
            (fy_id, 'SINGLE', 5, 2000000,    NULL, 0.36);
        -- COUPLE taxpayer (first slab extends to 600,000)
        INSERT INTO tax_slabs (fiscal_year_id, taxpayer_type, slab_order, income_from, income_to, tax_rate) VALUES
            (fy_id, 'COUPLE', 1,       0,  600000, 0.01),
            (fy_id, 'COUPLE', 2,  600000,  700000, 0.10),
            (fy_id, 'COUPLE', 3,  700000, 1000000, 0.20),
            (fy_id, 'COUPLE', 4, 1000000, 2000000, 0.30),
            (fy_id, 'COUPLE', 5, 2000000,    NULL, 0.36);

        -- SSF / PF rates
        INSERT INTO statutory_rates (fiscal_year_id, rate_type, rate_value, effective_from) VALUES
            (fy_id, 'SSF_EMPLOYEE', 0.11, '2024-07-17'),
            (fy_id, 'SSF_EMPLOYER', 0.20, '2024-07-17'),
            (fy_id, 'PF_EMPLOYEE',  0.10, '2024-07-17'),
            (fy_id, 'PF_EMPLOYER',  0.10, '2024-07-17');
    END IF;
END $$;

-- ── Default salary components ────────────────────────────────────────────────
INSERT INTO salary_components (component_code, component_name, component_type, calculation_type, is_taxable)
SELECT * FROM (VALUES
    ('BASIC',  'Basic Salary',          'EARNING',    'FIXED',      true),
    ('OT',     'Overtime Allowance',    'EARNING',    'FORMULA',    true),
    ('SSF',    'SSF Deduction',         'STATUTORY',  'PERCENTAGE', false),
    ('PF',     'Provident Fund',        'STATUTORY',  'PERCENTAGE', false),
    ('TDS',    'Income Tax (TDS)',       'STATUTORY',  'FORMULA',    false)
) AS v(code, name, type, calc, taxable)
WHERE NOT EXISTS (SELECT 1 FROM salary_components WHERE component_code = v.code);

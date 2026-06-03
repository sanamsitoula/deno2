-- Migration 002b: Create payroll_processing and payroll_details from scratch
-- Run AFTER 002_payroll_tax_tables.sql

CREATE TABLE IF NOT EXISTS payroll_processing (
    id               SERIAL PRIMARY KEY,
    payroll_code     VARCHAR(30) UNIQUE,
    payroll_month    SMALLINT NOT NULL,
    payroll_year     SMALLINT NOT NULL,
    fiscal_year_id   INTEGER REFERENCES fiscal_years(id),
    from_date        DATE,
    to_date          DATE,
    status           VARCHAR(20) DEFAULT 'DRAFT'
                         CHECK (status IN ('DRAFT','CALCULATED','APPROVED','PAID','LOCKED')),
    total_employees  INTEGER DEFAULT 0,
    total_gross      NUMERIC(14,2) DEFAULT 0,
    total_deductions NUMERIC(14,2) DEFAULT 0,
    total_net_payable NUMERIC(14,2) DEFAULT 0,
    created_by       INTEGER,
    created_at       TIMESTAMP DEFAULT NOW(),
    processed_by     INTEGER,
    processed_at     TIMESTAMP,
    approved_by      INTEGER,
    approved_at      TIMESTAMP,
    UNIQUE (payroll_month, payroll_year)
);

CREATE TABLE IF NOT EXISTS payroll_details (
    id                    SERIAL PRIMARY KEY,
    payroll_processing_id INTEGER NOT NULL REFERENCES payroll_processing(id) ON DELETE CASCADE,
    employee_id           INTEGER NOT NULL REFERENCES employee(id),

    -- Attendance
    total_working_days    SMALLINT,
    total_present_days    SMALLINT,
    total_absent_days     SMALLINT,
    total_leaves          SMALLINT,
    total_holidays        SMALLINT,
    total_paid_days       NUMERIC(5,2),

    -- Overtime
    overtime_hours        NUMERIC(6,2)  DEFAULT 0,
    overtime_amount       NUMERIC(12,2) DEFAULT 0,

    -- Earnings
    basic_salary          NUMERIC(12,2),
    total_earnings        NUMERIC(12,2),
    gross_salary          NUMERIC(12,2),

    -- Statutory deductions
    ssf_employee          NUMERIC(12,2) DEFAULT 0,
    ssf_employer          NUMERIC(12,2) DEFAULT 0,
    pf_employee           NUMERIC(12,2) DEFAULT 0,
    pf_employer           NUMERIC(12,2) DEFAULT 0,
    income_tax            NUMERIC(12,2) DEFAULT 0,
    other_deductions      NUMERIC(12,2) DEFAULT 0,
    total_deductions      NUMERIC(12,2),
    net_payable           NUMERIC(12,2),

    status                VARCHAR(20) DEFAULT 'CALCULATED',
    created_by            INTEGER,
    created_at            TIMESTAMP DEFAULT NOW(),

    UNIQUE (payroll_processing_id, employee_id)
);

CREATE INDEX IF NOT EXISTS idx_payroll_details_processing_id
    ON payroll_details (payroll_processing_id);

CREATE INDEX IF NOT EXISTS idx_payroll_details_employee_id
    ON payroll_details (employee_id);

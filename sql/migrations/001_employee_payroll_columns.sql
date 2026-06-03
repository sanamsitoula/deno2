-- Migration 001: Add payroll-related columns to employee table
-- Run once against press_jemc database
-- Safe to run multiple times (uses IF NOT EXISTS / DO $$ blocks)

DO $$
BEGIN
    -- SSF enrollment flag (needed by TaxService/SSFCalculator)
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employee' AND column_name = 'is_ssf_enrolled'
    ) THEN
        ALTER TABLE employee ADD COLUMN is_ssf_enrolled BOOLEAN NOT NULL DEFAULT false;
    END IF;

    -- Taxpayer type for income tax slab selection
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.columns
        WHERE table_name = 'employee' AND column_name = 'taxpayer_type'
    ) THEN
        ALTER TABLE employee ADD COLUMN taxpayer_type VARCHAR(20) NOT NULL DEFAULT 'SINGLE';
        -- Valid values: 'SINGLE' | 'COUPLE'
    END IF;
END $$;

-- Add constraint if it doesn't exist
DO $$
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM pg_constraint
        WHERE conname = 'employee_taxpayer_type_check'
    ) THEN
        ALTER TABLE employee
            ADD CONSTRAINT employee_taxpayer_type_check
            CHECK (taxpayer_type IN ('SINGLE', 'COUPLE'));
    END IF;
END $$;

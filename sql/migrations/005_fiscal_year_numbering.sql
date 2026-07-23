-- Migration 005: Fiscal-year-scoped number series + additive fiscal_year_id columns
-- Per plan_numberseries.md — additive only, no destructive changes to existing
-- free-text fiscal_year columns or existing data. Safe to re-run (IF NOT EXISTS).

-- ── deno: new FK + new auto-generated fiscal-scoped number ──────────────────
-- ref_no (existing, manual, user-typed) is left completely untouched.
-- deno_no is a NEW, separate, auto-generated column: "{serial}/deno/{fiscalShort}"
ALTER TABLE deno ADD COLUMN IF NOT EXISTS fiscal_year_id integer REFERENCES fiscal_years(id);
ALTER TABLE deno ADD COLUMN IF NOT EXISTS deno_serial_no integer;
ALTER TABLE deno ADD COLUMN IF NOT EXISTS deno_no varchar(50);
CREATE INDEX IF NOT EXISTS idx_deno_fiscal_year_id ON deno(fiscal_year_id);
CREATE UNIQUE INDEX IF NOT EXISTS uq_deno_no ON deno(deno_no) WHERE deno_no IS NOT NULL;

-- ── books: new FK only (existing free-text fiscal_year column untouched) ────
ALTER TABLE books ADD COLUMN IF NOT EXISTS fiscal_year_id integer REFERENCES fiscal_years(id);
CREATE INDEX IF NOT EXISTS idx_books_fiscal_year_id ON books(fiscal_year_id);

-- ── book_packing: new auto-generated fiscal-scoped number ───────────────────
-- fiscal_year_id already exists on this table. name (free text) untouched.
-- packing_no is a NEW column: "{serial}/BP/{fiscalShort}"
ALTER TABLE book_packing ADD COLUMN IF NOT EXISTS packing_serial_no integer;
ALTER TABLE book_packing ADD COLUMN IF NOT EXISTS packing_no varchar(50);
CREATE UNIQUE INDEX IF NOT EXISTS uq_book_packing_no ON book_packing(packing_no) WHERE packing_no IS NOT NULL;

-- Migration 008: Backfill deno.fiscal_year_id for historical records
-- Migration 005 added deno.fiscal_year_id but only new inserts populated it —
-- 6739 of 6740 existing DENO rows were left NULL, so filtering entries/index.php
-- by an older fiscal year (e.g. 2082-83) returned zero rows even though the
-- matching records existed (BS date fell in range, fiscal_year_id was just NULL).
-- Safe to re-run: only touches rows where fiscal_year_id IS NULL.

UPDATE deno d
SET fiscal_year_id = fy.id
FROM fiscal_years fy
WHERE d.fiscal_year_id IS NULL
  AND d.deno_date_nep BETWEEN fy.fiscal_code || '.04.01' AND (fy.fiscal_code::int + 1) || '.03.32';

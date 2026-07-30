-- Migration 009: Add trip end date (to-date) to vehicle_daily_logs
-- vehicle_daily_logs only stored a single date (log_date_nep/log_date_eng),
-- but a trip's meter readings (start_meter/end_meter) can span multiple days
-- (e.g. an out-of-station trip Magh 5 -> Magh 8). log_date_nep/log_date_eng
-- now represent the trip's FROM date; these new columns hold the TO date.
-- Existing rows are single-day trips, so backfill end date = start date.
-- Safe to re-run: uses ADD COLUMN IF NOT EXISTS and only backfills NULLs.

ALTER TABLE vehicle_daily_logs
    ADD COLUMN IF NOT EXISTS log_end_date_nep character varying(20),
    ADD COLUMN IF NOT EXISTS log_end_date_eng date;

UPDATE vehicle_daily_logs
SET log_end_date_nep = log_date_nep,
    log_end_date_eng = log_date_eng
WHERE log_end_date_eng IS NULL;

ALTER TABLE vehicle_daily_logs
    ALTER COLUMN log_end_date_nep SET NOT NULL,
    ALTER COLUMN log_end_date_eng SET NOT NULL;

ALTER TABLE vehicle_daily_logs
    DROP CONSTRAINT IF EXISTS chk_log_end_date_after_start;

ALTER TABLE vehicle_daily_logs
    ADD CONSTRAINT chk_log_end_date_after_start CHECK (log_end_date_eng >= log_date_eng);

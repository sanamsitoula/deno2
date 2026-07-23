-- Migration 007: proper integer user reference for books.created_by
-- Additive only, safe to re-run.
--
-- Problem: books.created_by is character varying(5), but book/create.php was
-- inserting the full username into it. Any username longer than 5 characters
-- (most of them — e.g. "NyuchheRamTyata", "Bishnu") throws
-- SQLSTATE[22001] "String data, right truncated" and the book fails to save.
-- The legacy column's LEFT JOIN users ON b.created_by = u1.username also never
-- matched for the same reason, so "Created By" showed blank for most books.
--
-- Fix: add a proper integer FK column. The old varchar(5) column is left in
-- place (still NOT NULL) so nothing else that reads it breaks — book/create.php
-- now writes a safely truncated value there and the real value into the new
-- column, which is what index/view now join against for display.

ALTER TABLE books ADD COLUMN IF NOT EXISTS created_by_id integer REFERENCES users(id);
CREATE INDEX IF NOT EXISTS idx_books_created_by_id ON books(created_by_id);

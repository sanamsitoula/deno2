-- Migration 006: Book Titles (cross-year canonical identity) + edition versioning
-- Additive only, per plan_numberseries.md conventions — safe to re-run.
--
-- Problem: books.book_code is UNIQUE, so a revised edition (new price/pages/
-- content) for a later fiscal year needs a brand-new book_code (e.g.
-- MATH6-NT-2080 -> MATH6-NT-2081). That's correct for per-edition tracking
-- (job_ticket/forma/deno/d2m must reference the exact edition), but there was
-- no way to answer "how much Math6-NT have we produced across all years?".
--
-- Fix: book_titles is the stable, never-changing identity for a title
-- (subject+class+translation-status). books.title_id links each yearly
-- edition back to it. Production tables (job_ticket, forma, deno, d2m_items)
-- are untouched — they keep referencing the specific edition as before.

CREATE TABLE IF NOT EXISTS book_titles (
    id                    SERIAL PRIMARY KEY,
    title_code            varchar(50) UNIQUE NOT NULL,
    title_name            varchar(255) NOT NULL,
    class_level           integer,
    is_translated         boolean DEFAULT false,
    book_type             varchar(20) DEFAULT 'TextBook',
    business_associated   varchar(10) DEFAULT 'CDC',
    is_active             boolean DEFAULT true,
    created_at            timestamp without time zone DEFAULT CURRENT_TIMESTAMP
);
CREATE INDEX IF NOT EXISTS idx_book_titles_active ON book_titles(is_active);

-- books = one row per yearly edition. New columns:
--   title_id    -> which canonical title this edition belongs to
--   page_count  -> this edition's page count (changes year to year with content)
--   is_active   -> whether this specific edition is still current/in-use for
--                  new job tickets etc. Historical/obsolete editions stay in
--                  the table (and in every report) — this only hides them
--                  from day-to-day "pick a book" dropdowns.
ALTER TABLE books ADD COLUMN IF NOT EXISTS title_id    integer REFERENCES book_titles(id);
ALTER TABLE books ADD COLUMN IF NOT EXISTS page_count  integer;
ALTER TABLE books ADD COLUMN IF NOT EXISTS is_active   boolean DEFAULT true;
CREATE INDEX IF NOT EXISTS idx_books_title_id  ON books(title_id);
CREATE INDEX IF NOT EXISTS idx_books_is_active ON books(is_active);

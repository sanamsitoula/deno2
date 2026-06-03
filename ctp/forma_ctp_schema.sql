-- ============================================================
-- FORMA CTP MODULE — PostgreSQL Schema v1.0
-- Fully independent module for CTP plate PDF generation
-- Per-book, per-forma imposition management
-- ============================================================

-- ── 1. Forma CTP Books (master book registry for this module) ──
CREATE TABLE IF NOT EXISTS fctp_books (
    id              SERIAL PRIMARY KEY,
    book_code       VARCHAR(100) NOT NULL UNIQUE,
    book_name       VARCHAR(300) NOT NULL,
    class           VARCHAR(20),
    subject         VARCHAR(100),
    total_pages     INTEGER NOT NULL DEFAULT 0,
    -- Master PDF (full book PDF uploaded once)
    master_pdf_path TEXT,                        -- server path to full book PDF
    master_pdf_name VARCHAR(300),                -- original filename
    master_pdf_pages INTEGER DEFAULT 0,
    notes           TEXT,
    created_by      VARCHAR(100),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fctp_books_code ON fctp_books(book_code);


-- ── 2. Job Tickets ──────────────────────────────────────────
CREATE TABLE IF NOT EXISTS fctp_job_tickets (
    id                  SERIAL PRIMARY KEY,
    book_code           VARCHAR(100) NOT NULL REFERENCES fctp_books(book_code) ON DELETE CASCADE,
    job_ticket_code     VARCHAR(100) NOT NULL UNIQUE,   -- e.g. 2082-JT094
    fiscal_year         VARCHAR(20),                     -- e.g. 2082
    lot_no              INTEGER DEFAULT 1,
    print_qty           INTEGER NOT NULL DEFAULT 0,
    page_qty            INTEGER NOT NULL DEFAULT 0,
    date_nep            VARCHAR(20),                     -- Nepali BS date
    date_eng            DATE,
    status              VARCHAR(30) DEFAULT 'active',    -- active|closed|cancelled
    notes               TEXT,
    created_by          VARCHAR(100),
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fctp_jt_book   ON fctp_job_tickets(book_code);
CREATE INDEX IF NOT EXISTS idx_fctp_jt_code   ON fctp_job_tickets(job_ticket_code);
CREATE INDEX IF NOT EXISTS idx_fctp_jt_status ON fctp_job_tickets(status);


-- ── 3. Formas (per job ticket) ──────────────────────────────
-- Each forma represents one physical printing plate group
-- e.g. T-28 (32pp), 29-44 (16pp), COVER (4pp)
CREATE TABLE IF NOT EXISTS fctp_formas (
    id                  SERIAL PRIMARY KEY,
    job_ticket_id       INTEGER NOT NULL REFERENCES fctp_job_tickets(id) ON DELETE CASCADE,
    book_code           VARCHAR(100) NOT NULL,
    order_no            INTEGER NOT NULL DEFAULT 1,     -- sequence order
    forma_name          VARCHAR(50) NOT NULL,            -- e.g. T-28, 29-44, COVER
    forma_type          VARCHAR(20) DEFAULT 'body',      -- body|cover|insert
    page_start          INTEGER NOT NULL,                -- first book page in this forma
    page_end            INTEGER NOT NULL,                -- last book page in this forma
    page_count          INTEGER NOT NULL,                -- total pages (page_end - page_start + 1)
    print_qty           INTEGER NOT NULL DEFAULT 0,
    old_forma_qty       INTEGER DEFAULT 0,
    machine             VARCHAR(100),
    description         TEXT,

    -- PDF source for this forma (extracted from master or uploaded separately)
    source_pdf_path     TEXT,                            -- path to source PDF (full book or forma-specific)
    source_pdf_type     VARCHAR(20) DEFAULT 'master',    -- master|separate
    source_pdf_pages    INTEGER DEFAULT 0,

    -- Imposition layout config
    layout_type         VARCHAR(30) DEFAULT '8up_booklet', -- 8up_booklet|4up_booklet|2up|cover_4pp|custom
    cols                INTEGER DEFAULT 4,
    rows                INTEGER DEFAULT 2,
    pages_per_plate     INTEGER DEFAULT 8,               -- book pages per physical plate (both sides combined)
    pages_per_side      INTEGER DEFAULT 4,               -- book pages per plate side
    imposition_mode     VARCHAR(20) DEFAULT 'sheetwork', -- sheetwork|work_and_turn|work_and_tumble

    -- Plate / sheet physical dimensions (mm)
    plate_width         FLOAT DEFAULT 720,
    plate_height        FLOAT DEFAULT 508,

    -- Margins (mm)
    bleed               FLOAT DEFAULT 3,
    gutter              FLOAT DEFAULT 5,
    trim_outer          FLOAT DEFAULT 8,
    gripper             FLOAT DEFAULT 10,
    head_margin         FLOAT DEFAULT 8,
    foot_margin         FLOAT DEFAULT 8,
    spine_margin        FLOAT DEFAULT 5,   -- extra at spine/binding edge
    cutting_margin      FLOAT DEFAULT 3,   -- cutting/trim marks offset

    -- Calculated plate count
    plates_required     INTEGER DEFAULT 0,  -- auto-calculated: ceil(page_count / pages_per_plate)

    -- Imposition slot assignments (JSON array of slot objects)
    -- Each plate has front and back, each side has (cols x rows) slots
    -- slot: {plate_no, side, col, row, book_page, rotation_deg, is_blank}
    imposition_json     TEXT,              -- JSON: full slot assignment

    -- Output
    output_pdf_path     TEXT,              -- generated CTP plate PDF
    output_status       VARCHAR(20) DEFAULT 'pending', -- pending|ready|generated|failed
    output_generated_at TIMESTAMP,

    notes               TEXT,
    created_by          VARCHAR(100),
    created_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    updated_at          TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fctp_formas_jt      ON fctp_formas(job_ticket_id);
CREATE INDEX IF NOT EXISTS idx_fctp_formas_book     ON fctp_formas(book_code);
CREATE INDEX IF NOT EXISTS idx_fctp_formas_status   ON fctp_formas(output_status);
CREATE INDEX IF NOT EXISTS idx_fctp_formas_order    ON fctp_formas(job_ticket_id, order_no);

-- Unique constraint: one forma name per job ticket
CREATE UNIQUE INDEX IF NOT EXISTS idx_fctp_formas_unique 
    ON fctp_formas(job_ticket_id, forma_name);


-- ── 4. Imposition Templates (reusable layout presets) ────────
CREATE TABLE IF NOT EXISTS fctp_imposition_templates (
    id              SERIAL PRIMARY KEY,
    template_name   VARCHAR(100) NOT NULL,
    layout_type     VARCHAR(30) NOT NULL DEFAULT '8up_booklet',
    cols            INTEGER DEFAULT 4,
    rows            INTEGER DEFAULT 2,
    pages_per_plate INTEGER DEFAULT 8,
    pages_per_side  INTEGER DEFAULT 4,
    imposition_mode VARCHAR(20) DEFAULT 'sheetwork',
    plate_width     FLOAT DEFAULT 720,
    plate_height    FLOAT DEFAULT 508,
    bleed           FLOAT DEFAULT 3,
    gutter          FLOAT DEFAULT 5,
    trim_outer      FLOAT DEFAULT 8,
    gripper         FLOAT DEFAULT 10,
    head_margin     FLOAT DEFAULT 8,
    foot_margin     FLOAT DEFAULT 8,
    spine_margin    FLOAT DEFAULT 5,
    cutting_margin  FLOAT DEFAULT 3,
    is_default      BOOLEAN DEFAULT FALSE,
    notes           TEXT,
    created_by      VARCHAR(100),
    created_at      TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

INSERT INTO fctp_imposition_templates
    (template_name, layout_type, cols, rows, pages_per_plate, pages_per_side, imposition_mode, plate_width, plate_height, is_default)
VALUES
    ('8-Up Booklet (4×2) 720×508',  '8up_booklet', 4, 2, 8, 4, 'sheetwork', 720, 508, TRUE),
    ('4-Up Booklet (2×2) 720×508',  '4up_booklet', 2, 2, 4, 2, 'sheetwork', 720, 508, FALSE),
    ('2-Up (2×1) 720×508',          '2up',         2, 1, 2, 1, 'sheetwork', 720, 508, FALSE),
    ('Cover 4pp (2×1)',              'cover_4pp',   2, 1, 4, 2, 'work_and_turn', 720, 508, FALSE),
    ('8-Up SRA2 (4×2) 640×450',     '8up_booklet', 4, 2, 8, 4, 'sheetwork', 640, 450, FALSE)
ON CONFLICT DO NOTHING;


-- ── 5. Forma Upload Log (track PDF uploads per forma) ────────
CREATE TABLE IF NOT EXISTS fctp_uploads (
    id              SERIAL PRIMARY KEY,
    forma_id        INTEGER REFERENCES fctp_formas(id) ON DELETE CASCADE,
    book_code       VARCHAR(100),
    upload_type     VARCHAR(20) DEFAULT 'master',   -- master|forma_pdf|output
    original_name   VARCHAR(300),
    saved_path      TEXT NOT NULL,
    file_size_bytes BIGINT DEFAULT 0,
    page_count      INTEGER DEFAULT 0,
    uploaded_by     VARCHAR(100),
    uploaded_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_fctp_uploads_forma ON fctp_uploads(forma_id);
CREATE INDEX IF NOT EXISTS idx_fctp_uploads_book  ON fctp_uploads(book_code);


-- ── Helpful Views ─────────────────────────────────────────────
CREATE OR REPLACE VIEW v_fctp_forma_summary AS
SELECT
    f.id                AS forma_id,
    f.forma_name,
    f.forma_type,
    f.order_no,
    f.page_start,
    f.page_end,
    f.page_count,
    f.print_qty,
    f.layout_type,
    f.cols,
    f.rows,
    f.pages_per_plate,
    f.plates_required,
    f.plate_width,
    f.plate_height,
    f.output_status,
    f.output_generated_at,
    jt.id               AS job_ticket_id,
    jt.job_ticket_code,
    jt.fiscal_year,
    jt.lot_no,
    jt.print_qty        AS jt_print_qty,
    b.book_code,
    b.book_name,
    b.class,
    b.total_pages,
    b.master_pdf_path,
    f.created_by,
    f.created_at
FROM fctp_formas f
JOIN fctp_job_tickets jt ON f.job_ticket_id = jt.id
JOIN fctp_books b ON f.book_code = b.book_code;

CREATE OR REPLACE VIEW v_fctp_job_summary AS
SELECT
    jt.id,
    jt.job_ticket_code,
    jt.fiscal_year,
    jt.lot_no,
    jt.print_qty,
    jt.page_qty,
    jt.date_nep,
    jt.date_eng,
    jt.status,
    b.book_code,
    b.book_name,
    b.class,
    b.total_pages,
    b.master_pdf_path,
    COUNT(f.id)         AS forma_count,
    SUM(CASE WHEN f.output_status = 'generated' THEN 1 ELSE 0 END) AS formas_done,
    jt.created_by,
    jt.created_at
FROM fctp_job_tickets jt
JOIN fctp_books b ON jt.book_code = b.book_code
LEFT JOIN fctp_formas f ON f.job_ticket_id = jt.id
GROUP BY jt.id, b.book_code, b.book_name, b.class, b.total_pages, b.master_pdf_path;

-- ── Notes ────────────────────────────────────────────────────
-- imposition_json structure per forma:
-- {
--   "plates": [
--     {
--       "plate_no": 1,
--       "sides": {
--         "front": [
--           {"slot": 1, "col": 1, "row": 1, "book_page": 32, "rotation": 180, "is_blank": false},
--           {"slot": 2, "col": 2, "row": 1, "book_page": 1,  "rotation": 180, "is_blank": false},
--           {"slot": 3, "col": 3, "row": 1, "book_page": 17, "rotation": 180, "is_blank": false},
--           {"slot": 4, "col": 4, "row": 1, "book_page": 16, "rotation": 180, "is_blank": false},
--           {"slot": 5, "col": 1, "row": 2, "book_page": 2,  "rotation": 0,   "is_blank": false},
--           {"slot": 6, "col": 2, "row": 2, "book_page": 31, "rotation": 0,   "is_blank": false},
--           {"slot": 7, "col": 3, "row": 2, "book_page": 15, "rotation": 0,   "is_blank": false},
--           {"slot": 8, "col": 4, "row": 2, "book_page": 18, "rotation": 0,   "is_blank": false}
--         ],
--         "back": [...]
--       }
--     },
--     ...
--   ]
-- }

-- ============================================================
-- CTP EXPORT MODULE — PostgreSQL Schema
-- Run this once to initialize all required tables
-- Compatible with deno2 system (PHP 8.x + PostgreSQL)
-- ============================================================

-- ── 1. Imposition Templates ──────────────────────────────────
CREATE TABLE IF NOT EXISTS imposition_templates (
    id              SERIAL PRIMARY KEY,
    template_name   VARCHAR(100)  NOT NULL,
    signature_size  INTEGER,                          -- pages per physical sheet (both sides)
    pages_per_sheet INTEGER,                          -- pages per side
    cols            INTEGER       DEFAULT 4,
    rows            INTEGER       DEFAULT 2,
    layout_type     VARCHAR(50)   DEFAULT '8up_booklet',
    formula         TEXT,                             -- custom formula string (optional)
    -- Margin presets (mm)
    bleed           FLOAT         DEFAULT 3,
    gutter          FLOAT         DEFAULT 5,
    trim_outer      FLOAT         DEFAULT 8,
    gripper         FLOAT         DEFAULT 10,
    head_margin     FLOAT         DEFAULT 8,
    foot_margin     FLOAT         DEFAULT 8,
    is_default      BOOLEAN       DEFAULT FALSE,
    notes           TEXT,
    created_by      VARCHAR(100),
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
);

-- Insert built-in templates
INSERT INTO imposition_templates
    (template_name, signature_size, pages_per_sheet, cols, rows, layout_type, bleed, gutter, gripper, is_default)
VALUES
    ('8-Up Booklet (4×2)',   16, 8,  4, 2, '8up_booklet',  3, 5, 10, TRUE),
    ('4-Up Booklet (2×2)',    8, 4,  2, 2, '4up_booklet',  3, 5, 10, FALSE),
    ('2-Up (2×1)',            4, 2,  2, 1, '2up_booklet',  3, 4, 10, FALSE),
    ('16-Up (4×4)',          32, 16, 4, 4, '16up_booklet', 3, 4, 10, FALSE)
ON CONFLICT DO NOTHING;


-- ── 2. CTP Export Jobs ────────────────────────────────────────
CREATE TABLE IF NOT EXISTS ctp_export_jobs (
    id              SERIAL PRIMARY KEY,

    -- Source reference
    job_name        VARCHAR(200)  NOT NULL,
    book_code       VARCHAR(100)  REFERENCES books(book_code) ON DELETE SET NULL,
    deno_id         INTEGER       REFERENCES deno(id)         ON DELETE SET NULL,
    template_id     INTEGER       REFERENCES imposition_templates(id),
    original_pdf    TEXT,                             -- server path to source PDF
    pdf_filename    VARCHAR(300),                     -- original uploaded filename

    -- Page info
    total_pages     INTEGER       NOT NULL DEFAULT 0,
    padded_pages    INTEGER       NOT NULL DEFAULT 0, -- after blank insertion
    blank_inserted  INTEGER       NOT NULL DEFAULT 0,

    -- Layout config
    layout_type     VARCHAR(50)   DEFAULT '8up_booklet',
    cols            INTEGER       DEFAULT 4,
    rows            INTEGER       DEFAULT 2,
    signature_size  INTEGER       DEFAULT 16,         -- pages per full sheet (both sides)

    -- Sheet dimensions (mm)
    sheet_width     FLOAT         DEFAULT 720,
    sheet_height    FLOAT         DEFAULT 508,

    -- Margin settings (mm) — all dynamic, set per job
    bleed           FLOAT         DEFAULT 3,
    gutter          FLOAT         DEFAULT 5,
    trim_outer      FLOAT         DEFAULT 8,
    gripper         FLOAT         DEFAULT 10,
    head_margin     FLOAT         DEFAULT 8,
    foot_margin     FLOAT         DEFAULT 8,

    -- Output
    output_pdf      TEXT,                             -- path to generated PRC PDF
    status          VARCHAR(30)   DEFAULT 'pending',  -- pending|processing|complete|failed
    error_msg       TEXT,

    -- Page imposition order (JSON)
    page_order_json TEXT,

    -- Audit
    notes           TEXT,
    created_by      VARCHAR(100),
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP,
    updated_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_ctp_jobs_book   ON ctp_export_jobs(book_code);
CREATE INDEX IF NOT EXISTS idx_ctp_jobs_status ON ctp_export_jobs(status);
CREATE INDEX IF NOT EXISTS idx_ctp_jobs_created ON ctp_export_jobs(created_at DESC);


-- ── 3. Page Setup Configurations ────────────────────────────
CREATE TABLE IF NOT EXISTS page_setups (
    id              SERIAL PRIMARY KEY,
    setup_name      VARCHAR(100),
    sheet_width     FLOAT         NOT NULL,   -- mm
    sheet_height    FLOAT         NOT NULL,   -- mm
    bleed           FLOAT         DEFAULT 3,
    gutter          FLOAT         DEFAULT 5,
    trim_outer      FLOAT         DEFAULT 8,
    gripper         FLOAT         DEFAULT 10,
    head_margin     FLOAT         DEFAULT 8,
    foot_margin     FLOAT         DEFAULT 8,
    orientation     VARCHAR(20)   DEFAULT 'landscape',
    notes           TEXT,
    is_active       BOOLEAN       DEFAULT TRUE,
    created_at      TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
);

-- Standard presets
INSERT INTO page_setups (setup_name, sheet_width, sheet_height, orientation) VALUES
    ('SRA3 Landscape', 450,  320,  'landscape'),
    ('SRA2 Landscape', 640,  450,  'landscape'),
    ('A1 Landscape',   841,  594,  'landscape'),
    ('Custom 720×508', 720,  508,  'landscape')
ON CONFLICT DO NOTHING;


-- ── Helpful view: CTP job summary ────────────────────────────
CREATE OR REPLACE VIEW v_ctp_job_summary AS
SELECT
    j.id,
    j.job_name,
    j.book_code,
    b.book_name,
    j.layout_type,
    j.cols,
    j.rows,
    j.total_pages,
    j.padded_pages,
    j.blank_inserted,
    CEIL(j.padded_pages::FLOAT / (j.cols * j.rows * 2)) AS sheets_required,
    j.sheet_width,
    j.sheet_height,
    j.bleed,
    j.gutter,
    j.gripper,
    j.status,
    j.created_by,
    j.created_at
FROM ctp_export_jobs j
LEFT JOIN books b ON j.book_code = b.book_code;

-- ─── MIGRATION: Add pdf_filename column if upgrading from v1 ─────────────────
-- Run this if you already have the table and are upgrading to v2:
-- ALTER TABLE ctp_export_jobs ADD COLUMN IF NOT EXISTS pdf_filename VARCHAR(300);
-- ALTER TABLE ctp_export_jobs ADD COLUMN IF NOT EXISTS original_pdf TEXT;
-- (The "Initialize DB Tables" button in the UI will handle this automatically)

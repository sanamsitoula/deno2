-- ═══════════════════════════════════════════════════════════════════════
--  PRESS INWARD MODULE — schema (v2: header + details)
--
--  One press_inward ROW = one voucher: a marketing user receiving a batch
--  of books from a single D2M into a single godam, on one date. The D2M
--  itself is snapshotted once on the header (not repeated per book).
--
--  One press_inward_details ROW = one book within that voucher, with its
--  own sent/received quantities, discrepancy, status and remarks — the
--  same "batch header + line items" shape as d2m / d2m_items.
--
--  Uses the existing 'marketing' role — no user_role enum change needed.
-- ═══════════════════════════════════════════════════════════════════════

-- ─── 1. GODAM (warehouse) ───────────────────────────────────────────────
CREATE TABLE public.godam (
    id           serial PRIMARY KEY,
    godam_code   varchar(20)  NOT NULL UNIQUE,
    godam_name   varchar(150) NOT NULL,
    location     varchar(255),
    is_active    boolean      DEFAULT true,
    created_by   integer      REFERENCES users(id),
    created_at   timestamp    DEFAULT CURRENT_TIMESTAMP,
    updated_at   timestamp
);

-- ─── 2. GODAM ↔ BOOK ↔ MARKETING-USER ASSIGNMENT ───────────────────────
-- "Each item is fixed to whoever handles it in this godam" — one
-- marketing user per book per godam. A marketing user can be assigned
-- many books; a godam can have many marketing users across its books.
CREATE TABLE public.godam_book_assignment (
    id                serial PRIMARY KEY,
    godam_id          integer     NOT NULL REFERENCES godam(id),
    book_code         varchar(50) NOT NULL REFERENCES books(book_code),
    marketing_user_id integer     NOT NULL REFERENCES users(id),
    is_active         boolean     DEFAULT true,
    created_at        timestamp   DEFAULT CURRENT_TIMESTAMP,
    UNIQUE (godam_id, book_code)
);

CREATE INDEX idx_gba_marketing_user ON public.godam_book_assignment(marketing_user_id);
CREATE INDEX idx_gba_book           ON public.godam_book_assignment(book_code);

-- ─── 3. PRESS INWARD — HEADER (one row per voucher) ────────────────────
CREATE TABLE public.press_inward (
    id                bigserial PRIMARY KEY,

    inward_no         varchar(50) NOT NULL UNIQUE,   -- fiscal-scoped auto number, e.g. "14/inward/82-83"
    inward_serial_no  integer     NOT NULL,

    -- source D2M (snapshotted ONCE per voucher, not per book)
    d2m_id            integer NOT NULL REFERENCES d2m(id),
    d2m_no            varchar(50),
    d2m_type          varchar(10),
    d2m_nep_date      varchar(10),
    d2m_eng_date      date,
    d2m_sender_id     integer,        -- snapshot of d2m.send_by (fallback d2m.created_by)
    d2m_sender_name   varchar(100),   -- snapshot username — survives later user renames/deletes

    -- godam + receiving marketing user (fixed for the WHOLE voucher)
    godam_id          integer NOT NULL REFERENCES godam(id),
    received_by       integer NOT NULL REFERENCES users(id),   -- marketing-role user, = session user, auto-set

    -- fiscal year + dates of THIS inward transaction
    fiscal_year_id    integer     NOT NULL REFERENCES fiscal_years(id),
    inward_date_nep   varchar(10) NOT NULL,
    inward_date_eng   date        NOT NULL,

    remarks           text,   -- optional voucher-level note (separate from per-book discrepancy remarks)

    -- audit
    created_by  integer   NOT NULL REFERENCES users(id),  -- = received_by in practice
    created_at  timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_by  integer   REFERENCES users(id),
    updated_at  timestamp,
    deleted_at  timestamp
);

CREATE INDEX idx_pi_d2m          ON public.press_inward(d2m_id);
CREATE INDEX idx_pi_godam        ON public.press_inward(godam_id);
CREATE INDEX idx_pi_received_by  ON public.press_inward(received_by);
CREATE INDEX idx_pi_fiscal_year  ON public.press_inward(fiscal_year_id);

-- ─── 4. PRESS INWARD — DETAILS (one row per book within the voucher) ───
CREATE TABLE public.press_inward_details (
    id                bigserial PRIMARY KEY,
    press_inward_id   bigint  NOT NULL REFERENCES press_inward(id) ON DELETE CASCADE,
    d2m_item_id       integer NOT NULL REFERENCES d2m_items(id),

    -- ── snapshot of the D2M ITEM at time of inward ──
    book_code                varchar(50) NOT NULL REFERENCES books(book_code),
    book_name                varchar(255),
    item_per_poka_qty        integer,
    item_total_poka_qty      integer,
    item_total_qty           integer NOT NULL,   -- the item's full line quantity (ceiling for validation)
    item_open_pcs            integer,
    item_deno_serial_number  varchar(50),        -- shown as "Ref No" in reports
    item_associated_deno_ids text,

    -- ── this line's quantities (this partial batch, this voucher) ──
    sent_qty          integer NOT NULL CHECK (sent_qty >= 0),      -- what the challan/D2M claims for this line
    received_qty      integer NOT NULL CHECK (received_qty >= 0),  -- what was actually counted at the godam
    discrepancy_qty   integer GENERATED ALWAYS AS (received_qty - sent_qty) STORED,

    status   varchar(20) NOT NULL DEFAULT 'RECEIVED'
             CHECK (status IN ('RECEIVED', 'DISCREPANCY', 'CANCELLED')),
    remarks  text,   -- required by the app whenever sent_qty <> received_qty for THIS line

    created_at timestamp DEFAULT CURRENT_TIMESTAMP,

    UNIQUE (press_inward_id, d2m_item_id)   -- a given book appears at most once per voucher
);

CREATE INDEX idx_pid_press_inward ON public.press_inward_details(press_inward_id);
CREATE INDEX idx_pid_d2m_item     ON public.press_inward_details(d2m_item_id);
CREATE INDEX idx_pid_book         ON public.press_inward_details(book_code);
CREATE INDEX idx_pid_status       ON public.press_inward_details(status);

-- ─── 5. Remaining-to-inward summary (per D2M item, across ALL vouchers) ─
-- Mirrors the JT/BP "remaining" pattern already used on the Deno form.
CREATE VIEW public.v_d2m_item_inward_summary AS
SELECT
    di.id        AS d2m_item_id,
    di.d2m_id,
    di.book_code,
    di.total_qty AS item_total_qty,
    COALESCE(SUM(pid.sent_qty)     FILTER (WHERE pid.status <> 'CANCELLED' AND pi.deleted_at IS NULL), 0) AS total_sent,
    COALESCE(SUM(pid.received_qty) FILTER (WHERE pid.status <> 'CANCELLED' AND pi.deleted_at IS NULL), 0) AS total_received,
    di.total_qty - COALESCE(SUM(pid.sent_qty) FILTER (WHERE pid.status <> 'CANCELLED' AND pi.deleted_at IS NULL), 0) AS remaining_to_inward,
    COUNT(pid.id) FILTER (WHERE pid.status <> 'CANCELLED' AND pi.deleted_at IS NULL) AS inward_entries,
    COUNT(pid.id) FILTER (WHERE pid.status = 'DISCREPANCY' AND pi.deleted_at IS NULL) AS discrepancy_entries
FROM public.d2m_items di
LEFT JOIN public.press_inward_details pid ON pid.d2m_item_id = di.id
LEFT JOIN public.press_inward pi          ON pi.id = pid.press_inward_id
GROUP BY di.id, di.d2m_id, di.book_code, di.total_qty;

-- ─── 6. Flattened line-level view for listing / reports ────────────────
-- One row per book line (same shape the create/index/report pages expect),
-- with header (voucher) fields joined in.
CREATE VIEW public.v_press_inward_full_details AS
SELECT
    pid.id                       AS detail_id,
    pi.id                        AS press_inward_id,
    pi.inward_no,
    pi.inward_serial_no,

    pi.d2m_id,
    pi.d2m_no,
    pi.d2m_type,
    pi.d2m_nep_date,
    pi.d2m_eng_date,
    pi.d2m_sender_id,
    pi.d2m_sender_name,

    pid.d2m_item_id,
    pid.book_code,
    pid.book_name,
    pid.item_per_poka_qty,
    pid.item_total_poka_qty,
    pid.item_total_qty,
    pid.item_open_pcs,
    pid.item_deno_serial_number,
    pid.item_associated_deno_ids,

    pid.sent_qty,
    pid.received_qty,
    pid.discrepancy_qty,
    pid.status,
    pid.remarks,          -- per-line (per-book) remarks
    pi.remarks AS header_remarks,   -- optional overall voucher note

    pi.godam_id,
    g.godam_name,
    g.godam_code,

    pi.received_by,
    uk.username AS received_by_name,
    us.username AS sender_username,
    uc.username AS created_by_name,

    pi.fiscal_year_id,
    fy.fiscal_name,
    pi.inward_date_nep,
    pi.inward_date_eng,

    pi.created_at,
    pi.updated_at
FROM public.press_inward_details pid
JOIN public.press_inward pi        ON pid.press_inward_id = pi.id
LEFT JOIN public.godam g           ON pi.godam_id = g.id
LEFT JOIN public.users uk          ON pi.received_by = uk.id
LEFT JOIN public.users us          ON pi.d2m_sender_id = us.id
LEFT JOIN public.users uc          ON pi.created_by = uc.id
LEFT JOIN public.fiscal_years fy   ON pi.fiscal_year_id = fy.id
WHERE pi.deleted_at IS NULL;

-- ─── 7. Voucher-level rollup (handy for a future "vouchers" list page) ──
-- Not currently consumed by any page, included for when you want a
-- header-only list instead of the flattened per-book view above.
CREATE VIEW public.v_press_inward_voucher_summary AS
SELECT
    pi.id, pi.inward_no, pi.d2m_id, pi.d2m_no, pi.godam_id, g.godam_name,
    pi.received_by, uk.username AS received_by_name,
    pi.fiscal_year_id, fy.fiscal_name, pi.inward_date_nep, pi.inward_date_eng,
    COUNT(pid.id) AS line_count,
    SUM(pid.sent_qty) AS total_sent_qty,
    SUM(pid.received_qty) AS total_received_qty,
    COUNT(pid.id) FILTER (WHERE pid.status = 'DISCREPANCY') AS discrepancy_lines,
    pi.created_at
FROM public.press_inward pi
JOIN public.press_inward_details pid ON pid.press_inward_id = pi.id
LEFT JOIN public.godam g ON pi.godam_id = g.id
LEFT JOIN public.users uk ON pi.received_by = uk.id
LEFT JOIN public.fiscal_years fy ON pi.fiscal_year_id = fy.id
WHERE pi.deleted_at IS NULL
GROUP BY pi.id, pi.inward_no, pi.d2m_id, pi.d2m_no, pi.godam_id, g.godam_name,
         pi.received_by, uk.username, pi.fiscal_year_id, fy.fiscal_name,
         pi.inward_date_nep, pi.inward_date_eng, pi.created_at;

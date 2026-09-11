-- Migration 010: Marketing Inward module
-- Adds the physical goods-receipt step that sits after D2M (the paperwork
-- handover from Press to Marketing): a goddam (store) master, a per-user/
-- per-class/per-book-type/per-date-window handler-permission table, and the
-- marketing_inward header + line-item tables that reconcile press_qty vs
-- marketing_qty per book, per D2M line.
--
-- See marketing_inward_plan.md at the repo root for the full design,
-- worked examples, and open assumptions.
--
-- Safe to re-run — uses CREATE TABLE IF NOT EXISTS / ADD COLUMN IF NOT EXISTS
-- and DROPs constraints before re-adding them.

/* ============================================================
   1. goddam — warehouse/store master
============================================================ */
CREATE TABLE IF NOT EXISTS public.goddam (
    id           serial PRIMARY KEY,
    code         varchar(20)  NOT NULL UNIQUE,
    name         varchar(100) NOT NULL,
    total_qty    bigint       NOT NULL DEFAULT 0,
    is_active    boolean      NOT NULL DEFAULT true,
    remarks      text,
    created_by   integer      NOT NULL REFERENCES public.users(id),
    created_at   timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_by   integer      REFERENCES public.users(id),
    updated_at   timestamp
);

COMMENT ON TABLE public.goddam IS 'Warehouse/store master (project spelling: goddam = godown)';
COMMENT ON COLUMN public.goddam.total_qty IS 'Cached running total of net quantity currently held; bumped when a marketing_inward reaches APPROVED';

/* ============================================================
   2. goddam_handlers — which user handles which class/book-type,
      at which goddam, and within what date window
============================================================ */
CREATE TABLE IF NOT EXISTS public.goddam_handlers (
    id               serial PRIMARY KEY,
    goddam_id        integer      NOT NULL REFERENCES public.goddam(id),
    user_id          integer      NOT NULL REFERENCES public.users(id),
    class_level      integer      NOT NULL,
    book_type        char(2)      NOT NULL,
    active_from_nep  varchar(10)  NOT NULL,
    active_from_eng  date         NOT NULL,
    active_to_nep    varchar(10),
    active_to_eng    date,
    is_active        boolean      NOT NULL DEFAULT true,
    created_by       integer      NOT NULL REFERENCES public.users(id),
    created_at       timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_by       integer      REFERENCES public.users(id),
    updated_at       timestamp,
    CONSTRAINT goddam_handlers_book_type_check CHECK (book_type IN ('T','NT')),
    CONSTRAINT goddam_handlers_date_range_check
        CHECK (active_to_eng IS NULL OR active_to_eng >= active_from_eng),
    CONSTRAINT goddam_handlers_unique UNIQUE (goddam_id, user_id, class_level, book_type)
);

COMMENT ON TABLE public.goddam_handlers IS 'One row per (goddam, marketing user, class, T/NT) the user is authorized to inward, valid only within active_from/active_to. Only admin may set those two dates.';

/* ============================================================
   3. marketing_inward — header (mirrors d2m)
============================================================ */
CREATE TABLE IF NOT EXISTS public.marketing_inward (
    id                    bigserial PRIMARY KEY,
    inward_no             varchar(50) NOT NULL UNIQUE,
    serial_no             integer     NOT NULL,
    goddam_id             integer     NOT NULL REFERENCES public.goddam(id),
    d2m_id                integer     NOT NULL REFERENCES public.d2m(id),
    fiscal_year_id        integer     NOT NULL REFERENCES public.fiscal_years(id),
    nep_date              varchar(10) NOT NULL,
    eng_date              date        NOT NULL,
    created_by            integer     NOT NULL REFERENCES public.users(id),
    checked_by            integer     REFERENCES public.users(id),
    verified_by           integer     REFERENCES public.users(id),
    approved_by           integer     REFERENCES public.users(id),
    updated_by            integer,
    deleted_by            integer,
    status                varchar(20) NOT NULL DEFAULT 'DRAFT',
    remarks               text,
    created_at            timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamp,
    deleted_at            timestamp,
    checked_at            timestamp,
    verified_at           timestamp,
    approved_at           timestamp,
    total_press_qty       integer NOT NULL DEFAULT 0,
    total_marketing_qty   integer NOT NULL DEFAULT 0,
    total_mismatch_qty    integer NOT NULL DEFAULT 0,
    total_books           integer NOT NULL DEFAULT 0,
    CONSTRAINT marketing_inward_status_check CHECK (status IN
        ('DRAFT','CHECKED','VERIFIED','APPROVED','CANCELLED','CLOSE')),
    CONSTRAINT marketing_inward_approver_distinct_check CHECK (
        approved_by IS NULL OR (
            approved_by <> created_by AND
            (verified_by IS NULL OR approved_by <> verified_by)
        )
    )
);

COMMENT ON TABLE public.marketing_inward IS 'Physical goods-receipt of a D2M into a goddam. created_by/verified_by may be the same user; approved_by must differ from both.';
COMMENT ON COLUMN public.marketing_inward.total_mismatch_qty IS 'Cached sum of detail mismatch_qty (press_qty - marketing_qty); can be negative';

/* ============================================================
   4. marketing_inward_details — line items (mirrors d2m_items)
============================================================ */
CREATE TABLE IF NOT EXISTS public.marketing_inward_details (
    id                    bigserial PRIMARY KEY,
    marketing_inward_id   integer NOT NULL REFERENCES public.marketing_inward(id) ON DELETE CASCADE,
    d2m_id                integer NOT NULL REFERENCES public.d2m(id),
    d2m_item_id           integer NOT NULL REFERENCES public.d2m_items(id),
    book_code             varchar(50) NOT NULL REFERENCES public.books(book_code),
    class_level           integer,
    bp_id                 integer REFERENCES public.book_packing(id),
    job_ticket_id         integer REFERENCES public.job_ticket(id),
    press_qty             integer NOT NULL DEFAULT 0,
    marketing_qty         integer NOT NULL DEFAULT 0,
    mismatch_qty          integer GENERATED ALWAYS AS (press_qty - marketing_qty) STORED,
    remarks               text,
    created_at            timestamp DEFAULT CURRENT_TIMESTAMP,
    updated_at            timestamp
);

COMMENT ON COLUMN public.marketing_inward_details.mismatch_qty IS 'press_qty - marketing_qty; positive = short-received, negative = over-received';

-- A d2m_item can only ever be inwarded once across any non-cancelled
-- marketing_inward. Postgres partial unique indexes can't reference a
-- different table in their predicate, so this is enforced with a trigger
-- instead (per marketing_inward_plan.md §2.4's noted follow-up).
CREATE OR REPLACE FUNCTION public.mi_details_prevent_double_inward() RETURNS trigger AS $$
BEGIN
    IF EXISTS (
        SELECT 1
        FROM public.marketing_inward_details mid
        JOIN public.marketing_inward mi ON mi.id = mid.marketing_inward_id
        WHERE mid.d2m_item_id = NEW.d2m_item_id
          AND mi.status <> 'CANCELLED'
          AND mid.id IS DISTINCT FROM NEW.id
    ) THEN
        RAISE EXCEPTION 'd2m_item % has already been inwarded in an active marketing_inward', NEW.d2m_item_id;
    END IF;
    RETURN NEW;
END;
$$ LANGUAGE plpgsql;

DROP TRIGGER IF EXISTS trg_mi_details_prevent_double_inward ON public.marketing_inward_details;
CREATE TRIGGER trg_mi_details_prevent_double_inward
    BEFORE INSERT OR UPDATE ON public.marketing_inward_details
    FOR EACH ROW EXECUTE FUNCTION public.mi_details_prevent_double_inward();

/* ============================================================
   5. d2m — ALTER existing table: auto-approval cascade support
============================================================ */
ALTER TABLE public.d2m
    ADD COLUMN IF NOT EXISTS approved_by integer REFERENCES public.users(id),
    ADD COLUMN IF NOT EXISTS approved_at timestamp;

ALTER TABLE public.d2m
    DROP CONSTRAINT IF EXISTS d2m_status_check;
ALTER TABLE public.d2m
    ADD CONSTRAINT d2m_status_check CHECK ((status)::text = ANY (
        (ARRAY['DRAFT','CHECKED','VERIFIED','APPROVED','CANCELLED','CLOSE'])::text[]
    ));

COMMENT ON COLUMN public.d2m.approved_by IS 'Auto-stamped by the system (never a manual button) when every d2m_item under this D2M has been inwarded and approved via marketing_inward — see marketing_inward_plan.md §4a';

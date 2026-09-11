# Marketing Inward — Module Plan

Status: **DRAFT PLAN — not yet implemented.** Nothing in this document has been
applied to `sql/_schema.sql` or the codebase. It exists so the data model and
workflow can be reviewed/corrected before any table is created or any PHP file
is written.

"Goddam" below is the project's spelling of **godown** (warehouse/store). It is
kept as the literal table/column/module name throughout, per how it was
specified.

---

## 1. Business purpose

Today the chain is:

```
deno (per-forma packing record, has jt_id, bp_id)
   └─▶ d2m + d2m_items   ("Deno to Marketing" — paperwork handover, one row
                           per deno, grouped into a numbered D2M document,
                           workflow DRAFT → CHECKED → VERIFIED → CLOSE)
```

`d2m` is the *paper* handover from Press to Marketing. Nothing today models
the *physical* receipt of those books into a specific store (goddam), by a
specific store-keeper, with a quantity check against what the press claims it
sent. That's what **Marketing Inward** adds:

```
d2m_items (VERIFIED/CLOSE only)
   └─▶ marketing_inward + marketing_inward_details
        (physical goods-receipt into a goddam: press_qty vs marketing_qty,
         mismatch, done by the marketing user who handles that book's class,
         partial — one handler inwards only the book classes/types assigned
         to them, within their active date window)
        └─▶ once every line of a D2M is APPROVED this way, the D2M itself is
             auto-approved (§4a) — closing the loop back onto the existing
             d2m module
```

This is the same shape as `d2m`/`d2m_items` (header + line items, numbered
document, view/edit/print), extended with: a goddam master, a per-user/
per-class/per-type/per-date-window handler-permission table, press-vs-
marketing quantity reconciliation on each line, and a three-way create/
verify/approve sign-off (§4) that also feeds back into `d2m` itself (§4a) —
so `d2m`'s own workflow becomes `DRAFT → CHECKED → VERIFIED →
APPROVED(auto) → CLOSE` rather than stopping at `CLOSE`.

---

## 2. New tables

### 2.1 `goddam` (warehouse/store master — separate CRUD)

| Column | Type | Notes |
|---|---|---|
| `id` | `serial PK` | |
| `code` | `varchar(20) NOT NULL UNIQUE` | short code, e.g. `GD-01`, used in the inward number series |
| `name` | `varchar(100) NOT NULL` | display name |
| `total_qty` | `bigint NOT NULL DEFAULT 0` | **cached** running total of net quantity currently held (see §6.4) |
| `is_active` | `boolean NOT NULL DEFAULT true` | soft-disable instead of deleting |
| `remarks` | `text` | |
| `created_by` | `integer NOT NULL REFERENCES users(id)` | |
| `created_at` | `timestamp DEFAULT CURRENT_TIMESTAMP` | |
| `updated_by` | `integer REFERENCES users(id)` | |
| `updated_at` | `timestamp` | |

```sql
CREATE TABLE public.goddam (
    id           serial PRIMARY KEY,
    code         varchar(20)  NOT NULL UNIQUE,
    name         varchar(100) NOT NULL,
    total_qty    bigint       NOT NULL DEFAULT 0,
    is_active    boolean      NOT NULL DEFAULT true,
    remarks      text,
    created_by   integer      NOT NULL REFERENCES public.users(id),
    created_at   timestamp    DEFAULT CURRENT_TIMESTAMP,
    updated_by   integer      REFERENCES public.users(id),
    updated_at   timestamp
);
```

### 2.2 `goddam_handlers` (which user handles which book classes/type, at which goddam, and for how long)

Models: *"store keeper A can handle book class 1, 5, 7 (both translated and
non-translated); store keeper B can handle class 2, 4, 5."* One row per
`(goddam, user, class, book_type)` — a handler with several classes and both
types gets several rows, same as before, now with one more dimension (type)
and a validity window.

| Column | Type | Notes |
|---|---|---|
| `id` | `serial PK` | |
| `goddam_id` | `integer NOT NULL REFERENCES goddam(id)` | |
| `user_id` | `integer NOT NULL REFERENCES users(id)` | must hold role `marketing` (checked in app, not DB) |
| `class_level` | `integer NOT NULL` | matches `books.class_level` |
| `book_type` | `char(2) NOT NULL` | `'T'` or `'NT'` — matches `d2m.d2m_type`; a handler who does both classes T and NT gets one row per combination, exactly like class_level today |
| `active_from_nep` | `varchar(10) NOT NULL` | Nepali date the assignment starts being usable, picked via the Nepali calendar (§3a) |
| `active_from_eng` | `date NOT NULL` | auto-derived English date from `active_from_nep` |
| `active_to_nep` | `varchar(10)` | Nepali date the assignment stops being usable; **nullable = open-ended, still active today** |
| `active_to_eng` | `date` | auto-derived English date from `active_to_nep`; nullable to match |
| `is_active` | `boolean NOT NULL DEFAULT true` | manual kill-switch, independent of the date window (e.g. revoke immediately without touching dates) |
| `created_by` | `integer NOT NULL REFERENCES users(id)` | |
| `created_at` | `timestamp DEFAULT CURRENT_TIMESTAMP` | |
| `updated_by` | `integer REFERENCES users(id)` | who last changed the dates — always an admin (see rule below) |
| `updated_at` | `timestamp` | |

```sql
CREATE TABLE public.goddam_handlers (
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
    UNIQUE (goddam_id, user_id, class_level, book_type)
);
```

*Design choice: this reuses the existing `marketing` role rather than adding a
new enum value to `user_role` (`storekeeper`, etc.) — a "goddam handler" is
just a `marketing`-role user with rows in this table. Simpler, no enum
migration needed.*

**Only `admin` may set or change `active_from_*`/`active_to_*`** — enforced at
the page level (`goddam/handlers.php` gates the date fields behind
`has_role('admin')`; a non-admin, if the page ever lets a `marketing` user
create their own row at all, cannot set/edit the dates themselves — admin
input is mandatory on this field, not just default-gated). Leaving
`active_to` blank means "active indefinitely, until an admin later sets an
end date" — i.e. today's present date is always inside the window as long as
`active_to` is empty or ≥ today.

### 2.3 `marketing_inward` (header — mirrors `d2m`)

| Column | Type | Notes |
|---|---|---|
| `id` | `bigserial PK` | |
| `inward_no` | `varchar(50) NOT NULL UNIQUE` | generated, see §3 |
| `serial_no` | `integer NOT NULL` | per fiscal_year + goddam |
| `goddam_id` | `integer NOT NULL REFERENCES goddam(id)` | which store this receipt is into |
| `d2m_id` | `integer NOT NULL REFERENCES d2m(id)` | source D2M being inwarded |
| `fiscal_year_id` | `integer NOT NULL REFERENCES fiscal_years(id)` | |
| `nep_date` | `varchar(10) NOT NULL` | |
| `eng_date` | `date NOT NULL` | |
| `created_by` | `integer NOT NULL REFERENCES users(id)` | the goddam handler (storekeeper) who did the inward |
| `checked_by` | `integer REFERENCES users(id)` | |
| `verified_by` | `integer REFERENCES users(id)` | may be the **same** user as `created_by` |
| `approved_by` | `integer REFERENCES users(id)` | must be a **different** user from both `created_by` and `verified_by` — see §4 |
| `updated_by` | `integer` | |
| `deleted_by` | `integer` | |
| `status` | `varchar(20) NOT NULL DEFAULT 'DRAFT'` | `DRAFT, CHECKED, VERIFIED, APPROVED, CANCELLED, CLOSE` |
| `remarks` | `text` | |
| `created_at/updated_at/deleted_at/checked_at/verified_at/approved_at` | `timestamp` | |
| `total_press_qty` | `integer NOT NULL DEFAULT 0` | cached sum of detail `press_qty` |
| `total_marketing_qty` | `integer NOT NULL DEFAULT 0` | cached sum of detail `marketing_qty` |
| `total_mismatch_qty` | `integer NOT NULL DEFAULT 0` | cached sum of detail `mismatch_qty` (can be negative) |
| `total_books` | `integer NOT NULL DEFAULT 0` | count of detail rows |

```sql
CREATE TABLE public.marketing_inward (
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
```

### 2.4 `marketing_inward_details` (line items — mirrors `d2m_items`)

One row per **d2m_item** being inwarded (see §5 for why splitting is per-item,
not per-quantity). Carries the actual reconciliation.

| Column | Type | Notes |
|---|---|---|
| `id` | `bigserial PK` | |
| `marketing_inward_id` | `integer NOT NULL REFERENCES marketing_inward(id) ON DELETE CASCADE` | |
| `d2m_id` | `integer NOT NULL REFERENCES d2m(id)` | denormalized for easy querying, per your ask to store both |
| `d2m_item_id` | `integer NOT NULL REFERENCES d2m_items(id)` | the specific line being inwarded |
| `book_code` | `varchar(50) NOT NULL REFERENCES books(book_code)` | |
| `class_level` | `integer` | snapshot of `books.class_level` at entry time (audit + permission check) |
| `bp_id` | `integer REFERENCES book_packing(id)` | pulled from the source `deno` row(s) behind this d2m_item |
| `job_ticket_id` | `integer REFERENCES job_ticket(id)` | pulled the same way (`deno.jt_id`) |
| `press_qty` | `integer NOT NULL DEFAULT 0` | copied from `d2m_items.total_qty` — what the press says it sent |
| `marketing_qty` | `integer NOT NULL DEFAULT 0` | what marketing actually counted into the goddam |
| `mismatch_qty` | `integer GENERATED ALWAYS AS (press_qty - marketing_qty) STORED` | **press_qty − marketing_qty** (positive = short-received, negative = over-received) |
| `remarks` | `text` | e.g. reason for a mismatch |
| `created_at/updated_at` | `timestamp` | |

```sql
CREATE TABLE public.marketing_inward_details (
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

-- A d2m_item can only ever be inwarded once (in a non-cancelled document):
CREATE UNIQUE INDEX ux_mi_details_one_active_per_item
    ON public.marketing_inward_details (d2m_item_id)
    WHERE marketing_inward_id IN (
        SELECT id FROM public.marketing_inward WHERE status <> 'CANCELLED'
    );
```

*(Postgres doesn't allow a subquery directly in a partial-index predicate like
that — the actual migration will instead enforce this with a `BEFORE INSERT`
trigger or an application-level `SELECT ... FOR UPDATE` check inside the
transaction. Flagged here as a known follow-up, not a blocker for the plan.)*

---

## 3. Number series

Mirrors `d2m`'s `{serial}-D2M/{fiscal_code}-{type}-{nep_date}` pattern,
scoped per **fiscal year + goddam** (each store keeps its own sequence):

```
{serial_no}-MI/{fiscal_code}-{goddam_code}-{nep_date}
e.g.  7-MI/2082-GD-01-2082.09.03
```

`serial_no` = `COALESCE(MAX(serial_no),0)+1` within `(fiscal_year_id,
goddam_id)`, computed inside the same transaction as the insert (same
pattern as `d2m/create.php`).

### 3a. Nepali calendar on the handler's active-from/to dates

`goddam/handlers.php` gets the same Nepali-date-picker widget already used on
`d2m/create.php` (the `nepalidatepicker.sajanmaharjan.com.np` v5 widget +
`NepaliFunctions.BS2AD`/`AD2BS`), wired to **two** date fields instead of one:

- Admin types/picks `active_from_nep` (e.g. `2082.04.01`) → `onDateSelect`
  fires `NepaliFunctions.BS2AD(...)` → auto-fills the hidden `active_from_eng`
  field, exactly like `create.php`'s `nep_date` → `eng_date` auto-fill.
- Same pair of widgets again for `active_to_nep` → `active_to_eng`, with a
  "leave blank = still active" checkbox/clear button that empties both
  `active_to_nep` and `active_to_eng` (open-ended).
- A native `<input type="date">` overlay stays available for both (same
  transparent-overlay trick as `create.php`'s `eng_date_native`), so English
  date can also be picked directly and it back-converts to Nepali via
  `AD2BS`.
- Client-side guard mirrors `d2m/create.php`'s regex check
  (`/^\d{4}\.\d{2}\.\d{2}$/`) before submit; server-side re-derives/validates
  the English date rather than trusting the posted value blindly.

---

## 4. Status workflow — create / verify / approve (three-way sign-off)

```
DRAFT ──check──▶ CHECKED ──verify──▶ VERIFIED ──approve──▶ APPROVED ──close──▶ CLOSE
  │                  │                   │
  └──────cancel──────┴───────────────────┴────────────────────────▶ CANCELLED
```

`APPROVED` is a new stage inserted between `VERIFIED` and `CLOSE`. It exists
specifically to carry the three-way actor rule you asked for:

| Actor field | Set at stage | Rule |
|---|---|---|
| `created_by` | DRAFT | the storekeeper (goddam handler) who did the physical count |
| `verified_by` | VERIFIED | **may be the same user as `created_by`** |
| `approved_by` | APPROVED | **must be a different user from both `created_by` and `verified_by`** — enforced by `marketing_inward_approver_distinct_check` (§2.3) at the DB level, and by excluding those two user ids from the "Select Approver" dropdown at the UI level |

Role gates (mirrors `d2m/index.php`'s `has_role()` checks):

| Transition | Who |
|---|---|
| Create (DRAFT) | `marketing` (must be a goddam handler for at least one class/type in the D2M, within their active date window — §5), `admin` |
| Check | `incharge`, `operator`, `supervisor`, `admin` |
| Verify | `marketing` (can equal `created_by`), `admin` |
| Approve | `marketing` or `admin` — **any user except `created_by` or `verified_by`** |
| Close | `admin` |
| Cancel | `admin`, only from `DRAFT`/`CHECKED`/`VERIFIED` |

*The approve action's user picker (same modal pattern as `d2m/index.php`'s
"Select Marketing User" verify modal) must query users excluding both
`created_by` and `verified_by` of that specific document, not just exclude
the currently logged-in user — otherwise a third party could still pick the
creator's name from the dropdown.*

---

## 4a. Auto-approval cascade: completing a D2M approves it automatically

Once **every** `d2m_item` under a given `d2m` has been inwarded and that
inward has reached `APPROVED`, the parent `d2m` should flip to an `APPROVED`
state of its own automatically — stamped with the same user who approved the
`marketing_inward` that happened to complete it. No one clicks "approve" on
the `d2m` record itself for this; the system does it as a side effect.

This requires two changes to the **existing** `d2m` table (not a new table —
an actual `ALTER TABLE` against production):

```sql
ALTER TABLE public.d2m
    ADD COLUMN approved_by integer REFERENCES public.users(id),
    ADD COLUMN approved_at timestamp;

ALTER TABLE public.d2m
    DROP CONSTRAINT d2m_status_check,
    ADD CONSTRAINT d2m_status_check CHECK ((status)::text = ANY (
        (ARRAY['DRAFT','CHECKED','VERIFIED','APPROVED','CANCELLED','CLOSE'])::text[]
    ));
```

`d2m`'s workflow becomes `DRAFT → CHECKED → VERIFIED → APPROVED(auto) →
CLOSE`, with `APPROVED` reachable only by the system, never by a button.

**Trigger point** — inside the same DB transaction where a `marketing_inward`
is set to `APPROVED` (i.e. the same request/transaction as §4's "Approve"
action), run:

```sql
-- "Is there anything left of this D2M that hasn't been inwarded yet?"
SELECT COUNT(*) AS remaining
FROM d2m_items di
WHERE di.d2m_id = :d2m_id
  AND di.id NOT IN (
        SELECT d2m_item_id FROM marketing_inward_details mid
        JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
        WHERE mi.status <> 'CANCELLED'
          AND mi.id != :this_marketing_inward_id  -- exclude the row we're about to flip to APPROVED itself, or run this AFTER committing that row's status update within the same transaction
      );
```

If `remaining = 0` (this was the last outstanding line):

```sql
UPDATE public.d2m
SET status = 'APPROVED',
    approved_by = :approver_user_id,   -- the same user who approved this marketing_inward
    approved_at = NOW()
WHERE id = :d2m_id
  AND status = 'VERIFIED';             -- don't touch a d2m that's already CANCELLED/CLOSE
```

Implementation notes:
- Run the "remaining" check and the `d2m` update in the **same transaction**
  as the `marketing_inward` approval, so either both commit or neither does.
- `d2m/view.php`'s status timeline gets a new entry for `APPROVED`, labeled
  something like *"Approved (auto) — completed via Marketing Inward
  MI-xxxxx"*, with the same `approved_by`/`approved_at` shown.
- `d2m/index.php`'s status filter dropdown and status-badge CSS need an
  `APPROVED` option/style alongside the existing five.
- The existing "Close" button on `d2m/index.php` currently only shows for
  `status == 'VERIFIED'`; it should also show (or be reserved exclusively)
  for `status == 'APPROVED'` going forward — flagging this as something to
  confirm when this stage gets built, since older D2Ms already sitting in
  `VERIFIED` predate Marketing Inward entirely and have nothing to
  auto-approve against.

---

## 5. Partial entry / class-based permission — the core rule

A single `d2m` can contain book lines (`d2m_items`) across several classes.
Marketing Inward must let **different handlers inward only their own
classes**, progressively, until the whole D2M is received.

**Eligibility query** (drives both the create-page item picker and any
server-side guard):

```sql
SELECT di.*, b.class_level, b.book_name
FROM d2m_items di
JOIN books b ON b.book_code = di.book_code
JOIN d2m d   ON d.id = di.d2m_id
WHERE d.id = :d2m_id
  AND d.status IN ('VERIFIED','CLOSE')          -- paperwork handover confirmed
  AND EXISTS (
        SELECT 1 FROM goddam_handlers gh
        WHERE gh.user_id = :current_user_id
          AND gh.goddam_id = :goddam_id         -- the goddam picked on the create form
          AND gh.is_active
          AND gh.class_level = b.class_level
          AND gh.book_type   = d.d2m_type        -- T/NT must match too, not just class
          AND gh.active_from_eng <= CURRENT_DATE -- validity window, checked "today"
          AND (gh.active_to_eng IS NULL OR gh.active_to_eng >= CURRENT_DATE)
      )
  AND di.id NOT IN (
        SELECT d2m_item_id FROM marketing_inward_details mid
        JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
        WHERE mi.status <> 'CANCELLED'
      );
```

The date window is checked against **today** (the moment the handler is
performing the inward), not against the D2M's or item's own date — it's an
authorization window ("this person is allowed to do inward work between
these dates"), separate from which historical D2M they happen to be
receiving.

Worked example: D2M #12 (`d2m_type = 'NT'`) has books of class 1, 2, 5, 7.
- Handler A is assigned `(class 1, NT)`, `(class 5, NT)`, `(class 7, NT)`,
  each with `active_from = 2082.01.01`, `active_to =` *(blank, open-ended)*.
  Today falls inside that window, so opening "Create Marketing Inward" and
  picking D2M #12 shows only the class-1/5/7 lines → submits →
  `marketing_inward` #1 (goddam GD-01).
- Handler B is assigned `(class 2, NT)` and `(class 4, NT)`, with
  `active_from = 2082.06.01, active_to = 2082.06.30`. If today is inside that
  window, the class-2 line (class 4 isn't on this D2M) shows as eligible →
  submits → `marketing_inward` #2. If today is *outside* that window (e.g.
  the admin never renewed it past `2082.06.30`), Handler B sees no eligible
  items at all, even though the class/type match — until an admin edits their
  `active_to` date forward.
- A handler assigned `(class 1, T)` would **not** see D2M #12's class-1 line,
  because #12 is `NT` — they'd only see it on a `T`-type D2M.
- D2M #12 is "fully inwarded" once no eligible lines remain across any
  handler — worth a badge on `d2m/view.php` ("Inward: 4/4 items received")
  but not a blocker for this plan.

*Granularity note: partial fulfillment is per **book line** (`d2m_item`), not
per-quantity-within-a-line. One `d2m_item` is inwarded whole, by exactly one
handler, in exactly one `marketing_inward_details` row. Splitting a single
line's quantity across two separate inward receipts is out of scope unless
you tell me it's actually needed — it would need a `remaining_qty` model
instead of the simple uniqueness constraint above.*

### 5a. Showing "how much of this D2M is still left to inward" in the dropdown

`marketing_inward/create.php`'s D2M picker must show, per D2M option, how
much of it has already moved into Marketing Inward and how much is still
outstanding — so anyone can see where the press→marketing pipeline is
backed up, not just their own eligible slice of it.

**Amended:** the dropdown query no longer restricts by `d.status` at all — it
shows every non-deleted D2M (DRAFT/CHECKED included, not just
VERIFIED/CLOSE/APPROVED) as long as it still has an outstanding line, per
explicit request. The status is now shown in each option's label instead, so
a DRAFT/CHECKED D2M is still visibly distinguishable from a VERIFIED one —
the paperwork-confirmed gate this originally enforced is gone by design.

```sql
SELECT d.id, d.d2m_no, d.nep_date, d.d2m_type, d.status,
       COUNT(di.id)  AS total_items,
       COUNT(di.id) FILTER (
           WHERE di.id IN (
               SELECT d2m_item_id FROM marketing_inward_details mid
               JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
               WHERE mi.status <> 'CANCELLED'
           )
       ) AS inwarded_items
FROM d2m d
JOIN d2m_items di ON di.d2m_id = d.id
WHERE d.deleted_at IS NULL
GROUP BY d.id
HAVING COUNT(di.id) > COUNT(di.id) FILTER (
           WHERE di.id IN (
               SELECT d2m_item_id FROM marketing_inward_details mid
               JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
               WHERE mi.status <> 'CANCELLED'
           )
       )
ORDER BY d.nep_date DESC;
```

Each `<option>` renders as, e.g.:

```
D2M-7-D2M/2082-NT-2082.09.03  —  NT  —  5/7 items inwarded (2 remaining)
```

This list is **not** filtered down to the current handler's own
class/type/date permissions — it's a pipeline-visibility feature, so every
marketing user can see which D2Ms still have outstanding lines overall, even
lines that belong to someone else's class assignment. Once a D2M is picked,
the item picker *inside* that D2M still only shows/accepts the lines this
specific handler is eligible for (§5's query) — the dropdown answers "what's
left, globally", the item picker answers "what's left, for me".

The same "X/Y inwarded" figure is worth surfacing as a small badge on
`d2m/index.php` and `d2m/view.php` too (mentioned in the worked example
above), reusing this same query.

---

## 6. Business rules / validation

1. **press_qty is not user-editable** — always copied from `d2m_items.total_qty`
   at selection time (it's what the press's paperwork claims).
2. **marketing_qty is the only number the handler types in** — what they
   physically counted while shelving into the goddam.
3. **mismatch_qty = press_qty − marketing_qty**, a generated column (always
   consistent, never drifts from the two inputs). Positive → short-received
   (marketing got less than press sent). Negative → over-received.
4. A goddam handler may only create/edit lines for `(class, book_type)`
   combinations they're assigned to, **and only while today's date falls
   inside their `active_from`/`active_to` window** (§5 query), enforced both
   in the item picker (only eligible items shown) and again server-side on
   submit (never trust the client-submitted item ids list — re-validate
   against §5's query before inserting). A request that arrives after the
   window has lapsed (e.g. a stale browser tab left open past `active_to`)
   must be rejected server-side even if the picker showed it earlier.
4a. Only `admin` can set/change a handler row's `active_from`/`active_to`
    dates — `goddam/handlers.php` never exposes those two fields to a
    `marketing` user, even for their own row.
5. Only `DRAFT` documents are editable; `CHECKED`/`VERIFIED`/`APPROVED`/
   `CLOSE` are read-only except for status transitions.
6. Deleting a line or cancelling a document releases its `d2m_item_id`
   back into the eligible pool (since the uniqueness check filters out
   `CANCELLED` documents).
7. `goddam.total_qty` is a cache, recomputed when a `marketing_inward`
   transitions to `VERIFIED` (or `CLOSE`, whichever you pick as the "counts as
   received" gate): `total_qty += SUM(marketing_qty of that document's lines)`.
   A nightly/admin "recalculate from history" utility should exist as a
   safety net against drift, same idea as the reconciliation modules already
   in `report/index.php`.
8. **`approved_by` must differ from both `created_by` and `verified_by`**
   (§2.3's `marketing_inward_approver_distinct_check`) — enforced at the DB
   level so it can't be bypassed even by a bug in the UI's dropdown filtering.
9. **Completing the last outstanding line of a D2M auto-approves the D2M
   itself** (§4a) — this only fires when a `marketing_inward` reaches
   `APPROVED`, inside the same transaction, and only touches `d2m` rows still
   in `VERIFIED` status.

---

## 7. File/module layout (mirrors `d2m/`)

```
goddam/
  index.php        -- list + create/edit modal, activate/deactivate (admin only)
  handlers.php      -- assign/revoke (user, class, book_type, active_from/to) rows per
                        goddam, admin only; active_from/to use the Nepali-calendar
                        picker from d2m/create.php (§3a) with auto BS↔AD conversion

marketing_inward/
  create.php        -- pick D2M + goddam -> eligible-items picker (per §5) -> preview -> submit
  index.php          -- list, search/filter, status-action buttons (mirrors d2m/index.php)
  view.php            -- detail + timeline (mirrors d2m/view.php)
  edit.php             -- DRAFT-only line edits
  print.php             -- printable layout (@media print, mirrors d2m/print.php)

marketing_inward_reports/
  daily.php           -- mirrors d2mreports/daily.php: date filter, CSV + Excel export (client-side,
  monthly.php         --   same pattern as d2mreports — Blob/text-csv + HTML-table-as-.xls, no server
  yearly.php          --   library), window.print() for print, mismatch column highlighted when non-zero
  book_pipeline.php   -- §8.1: book_code -> job ticket -> forma printing -> packing -> deno -> d2m ->
                          marketing_inward funnel, with per-stage gap/bottleneck highlighting
  jobticket_flow.php  -- §8.2: job ticket -> ... -> last marketing_inward, with day-duration columns
                          between every stage and a slowest-stage bottleneck flag
```

Both new report pages get the same CSV/Excel export buttons and `@media
print` layout as `daily.php`/`monthly.php`/`yearly.php` (§8 gives the exact
column sets).

This plan also now requires one change to the **existing** `d2m` table
(`ALTER TABLE`, not a new table — see §4a): adding `approved_by`/`approved_at`
and widening `d2m_status_check` to include `APPROVED`. Everything else in
this section remains new tables/files only.

Menu additions in `includes/header.php`, next to the existing D2M entries:
`Modules → Goddam`, `Modules → Marketing Inward`, `Reports → Marketing Inward
Daily/Monthly/Yearly`.

`src/Core/Auth.php` `MODULE_PERMISSIONS` additions:

```php
'goddam'           => ['admin'],
'marketing_inward' => ['admin', 'marketing'],
```

(Page-level `has_role()` calls still gate the individual check/verify/close/
cancel buttons the same fine-grained way `d2m/index.php` does today.)

---

## 8. Cross-module pipeline & bottleneck reports

Two new reports trace a book (or a job ticket) across *every* stage from
printing to physically landing in a goddam, so slow stages ("bottlenecks")
are visible instead of buried across five separate modules.

Both are read-only reports built from staged PHP queries rather than one
giant SQL view — `d2m_items.associated_deno_ids` is a comma-separated text
list rather than a proper join column, and one `book_packing` record can
draw from more than one `job_ticket`, so a single flat view would either
double-count or silently drop rows. Assembling the funnel in PHP (one query
per stage, aggregated in code) keeps each query simple and auditable.

### 8.1 Book-code pipeline report (`book_pipeline.php`)

Filter: `book_code` (required, searchable), optional fiscal year. For the
selected book, pull one aggregate row per stage:

| Stage | Source | Key aggregate(s) |
|---|---|---|
| 1. Job Ticket (forma printing) | `job_ticket` + `job_ticket_details` | `job_ticket.print_qty` (planned) vs `SUM(job_ticket_details.print_qty)` (actual forma print total) vs `job_ticket.print_done_qty` |
| 2. Packing & Stitching | `book_packing` | `SUM(p_qty)`, count of packing records, breakdown by `packing_status` |
| 3. Deno (forma→book entries) | `deno` (via `bp_id`) | `SUM(total_qty)`, `SUM(quantity_openpcs)`, count of entries |
| 4. D2M (paperwork handover) | `d2m_items` for this `book_code` | `SUM(total_qty)`, plus parent `d2m.status` breakdown |
| 5. Marketing Inward (physical receipt) | `marketing_inward_details` for this `book_code` | `SUM(press_qty)`, `SUM(marketing_qty)`, `SUM(mismatch_qty)` |

Gap columns (the bottleneck signal), each **upstream stage qty − downstream
stage qty**:

```
Gap(Print→Pack)   = job_ticket.print_qty      − SUM(book_packing.p_qty)
Gap(Pack→Deno)    = SUM(book_packing.p_qty)   − SUM(deno.total_qty)
Gap(Deno→D2M)     = SUM(deno.total_qty)       − SUM(d2m_items.total_qty)
Gap(D2M→Inward)   = SUM(d2m_items.total_qty)  − SUM(marketing_inward_details.marketing_qty)
                    (this is the aggregate view of the same mismatch_qty
                     concept, at the D2M→Inward boundary specifically)
```

A positive gap means quantity is still sitting *before* that boundary —
printed-but-not-packed, packed-but-not-deno'd, deno'd-but-not-handed-to-
marketing, or handed-over-but-not-yet-physically-received. The report
renders the five stages left-to-right as a funnel table with the gap value
between each pair of columns, and color-highlights (red/amber) whichever gap
is largest — that's the current bottleneck for that book.

### 8.2 Job-ticket flow & lead-time report (`jobticket_flow.php`)

Filter: `job_ticket_id` or `job_ticket_code` (required, searchable), or a
list view across all job tickets in a date range. For the selected job
ticket, pull one **timestamp** per stage (not quantities — this report is
about *how long each hand-off took*, complementing §8.1's *how much is
stuck*):

| # | Stage | Timestamp source |
|---|---|---|
| T0 | Job Ticket created | `job_ticket.created_date` |
| T1 | Printing completed | `MAX(job_ticket_details.end_date)` across that ticket's formas |
| T2 | Packing completed | `book_packing.created_date` (or `updated_date` when `packing_status = 'completed'`) for records linked to this `jt_id` |
| T3 | First Deno entry | `MIN(deno.created_at)` where `deno.bp_id` is one of the packing records above (or `deno.jt_id` directly) |
| T4 | D2M created | `MIN(d2m.created_at)` among D2Ms whose items trace back (via `associated_deno_ids`) to those deno rows |
| T5 | D2M verified | `d2m.verified_at` |
| T6 | First Marketing Inward created | `MIN(marketing_inward.created_at)` among inwards referencing that D2M |
| T7 | Last Marketing Inward approved (D2M fully received) | `MAX(marketing_inward.approved_at)` — this is the same moment `d2m.approved_at` gets auto-stamped, per §4a |

Duration columns, each in whole days (`DATE(T_next) - DATE(T_prev)`):

```
Printing duration       = T1 − T0
Packing wait            = T2 − T1
Deno-entry wait         = T3 − T2
D2M-creation wait       = T4 − T3
D2M-verify wait         = T5 − T4
Inward-start wait       = T6 − T5
Inward-completion time  = T7 − T6
──────────────────────────────────
TOTAL lead time         = T7 − T0
```

Rendered as one row per job ticket with all eight day-count columns plus
TOTAL, sortable by any column, with a configurable "flag if > N days"
threshold that highlights whichever single stage took the longest for that
ticket — the bottleneck stage for that specific job. A summary view (all
job tickets in a fiscal year/date range) makes it possible to spot systemic
bottlenecks (e.g. "D2M-verify wait" is consistently the slowest stage across
most tickets, not just one outlier).

Both reports get the same CSV/Excel export and print buttons already used in
`d2mreports/daily.php` (§7).

---

## 9. Decisions confirmed

1. **Verify step owner** — `marketing` role (a different user than the
   creator), same as `d2m`'s own verify step. Not `admin`.
2. **Role model** — reuse the existing `marketing` role plus the
   `goddam_handlers` table. No new `storekeeper` enum value.
3. **"Fully received" gate** — implemented at `APPROVED` (not `VERIFIED` as
   originally drafted here) — once the three-way sign-off (§4) was added,
   `APPROVED` became the point where a second, independent user has actually
   confirmed the receipt, which is the more meaningful moment to both bump
   `goddam.total_qty` and run the §4a auto-approval cascade onto `d2m`.
4. **Quantity splitting** — whole-line partial entry only (§5's granularity
   note), not sub-line quantity splitting.
5. **Handler assignment now carries `book_type` (T/NT) and a validity
   window** (`active_from`/`active_to`) in addition to `class_level` — a
   handler can be given several classes and both translated/non-translated
   rows, but can only actually perform Marketing Inward while today's date is
   inside their window. Only `admin` can set or change that window, via the
   Nepali-calendar picker described in §3a.
6. **Three-way sign-off** — `created_by`/`verified_by` may be the same
   person; `approved_by` must differ from both, enforced by a DB check
   constraint (§2.3, §4).
7. **Approver role** — assumed to be `marketing` or `admin` (same pool as
   verify), gated purely by the distinct-user rule rather than a distinct
   role. Flag if you actually want approval restricted to `admin` only.
8. **D2M auto-approval** — completing every line of a D2M via
   `marketing_inward` automatically flips the `d2m` row to a new `APPROVED`
   status, stamped with whichever user approved the completing
   `marketing_inward` (§4a). This requires an `ALTER TABLE` on the existing
   `d2m` table (`approved_by`, `approved_at`, widened status check) — flagged
   clearly since it's the one change in this plan that touches an existing
   production table rather than only adding new ones.
9. **Pipeline visibility** — the D2M dropdown on `marketing_inward/create.php`
   shows every D2M's overall inwarded/remaining count (§5a), not just the
   current user's eligible slice, so the press→marketing backlog is visible
   to everyone.
10. **Two new cross-module reports** (§8): a book-code pipeline/bottleneck
    funnel (`book_pipeline.php`) and a job-ticket lead-time report
    (`jobticket_flow.php`) with day-duration columns between every stage from
    job-ticket creation to final Marketing Inward approval.

## 10. Status

**Built.** `sql/migrations/010_marketing_inward.sql` has the four new tables
plus the `d2m` `ALTER TABLE`; `src/Core/Auth.php` and `includes/header.php`
are wired up; `goddam/`, `marketing_inward/`, and `marketing_inward_reports/`
contain the full CRUD, workflow, and report pages; `d2m/index.php` and
`d2m/view.php` show the new `APPROVED` status and its auto-approval timeline
entry. (`sql/_schema.sql` itself was intentionally left untouched — it's a
static historical dump that isn't kept in sync with `sql/migrations/`, same
as every other prior migration.)

**Not done here — this sandbox has no `psql` or `php` binary available, so
nothing could be executed or tested:**
- Run `sql/migrations/010_marketing_inward.sql` (or `sql/run_migrations.ps1`,
  which re-runs all of them — safe, they're idempotent) against the real
  `press_jemc` database before using any of this.
- Smoke-test the actual pages in a browser once the migration is applied —
  only careful manual code review was possible here, not execution.

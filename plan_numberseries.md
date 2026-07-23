# Fiscal Year Setup + Fiscal-Year-Reset Number Series — Audit & Migration Plan

Status: **jobticket, d2m, and deno (entries) are now fully complete** end-to-end — create,
edit, view, print, and index/list pages all generate/display numbering and `fiscal_name`
consistently (2026-07-23). bookpacking has Phase B/C done but not the full Phase D sweep.
book/forma/coupon numbering and the wider report-page sweep remain open — see Section 5.

---

## 0. What we're building

1. A real **Fiscal Year setup menu** (CRUD) driven by the existing `public.fiscal_years`
   table, with "active" fiscal year enforced by the existing DB trigger
   `trg_single_active_fiscal_year`.
2. **`fiscal_name` shown everywhere** fiscal year is relevant — create forms, edit forms,
   view pages, print pages, index/list pages, and all reports — instead of raw
   `fiscal_code` / free-text year strings.
3. **Number series that resets to 1 at the start of each new fiscal year**, consistently,
   in every module that has a business-facing sequence number: deno, d2m, jobticket,
   bookpacking, book, forma/formaprinting, coupon (fuel/vehicle), and any others found.

---

## 1. Current state (audit findings)

### 1.1 `fiscal_years` table & triggers (already exist)

- `sql/_schema.sql:2895-2903` — `public.fiscal_years(id, fiscal_code varchar(10), start_date, end_date, is_active bool default false, created_at, fiscal_name varchar)`.
- Trigger `trg_single_active_fiscal_year` (`sql/_schema.sql:7970`) → function `enforce_single_active_fiscal_year()` (`:412-421`) — unsets `is_active` on all other rows when one is set active. "Last write wins," fine for an admin UI.
- Trigger `trg_set_fiscal_year` on `employee` (`:7956`) → `set_active_fiscal_year()` (`:1180-1207`) — auto-fills `fiscal_year_id` FK from the active row if not supplied. **Precedent pattern** we can reuse for other tables.
- DB helper `get_next_d2m_serial(nep_date, type, fy)` (`:750-765`) already implements fiscal-scoped MAX()+1 for d2m — currently **dead code**, PHP duplicates the same logic inline instead of calling it.
- **No dedicated number-series/counter table exists anywhere** (`counters`, `number_series`, `xxx_seq` as business tables). All numbering today is either a raw Postgres `*_id_seq` (internal PK, global, never resets) or ad-hoc `MAX()+1`/`COUNT()+1` PHP queries.
- Legacy inconsistency to reconcile: `deno.fiscal_year` and `books.fiscal_year` are **free-text varchar** columns (default `'2082'`), not FK'd to `fiscal_years.id`. A `fiscal_year_enum` type also exists in the schema — check `sql/_schema.sql` for remaining users of it during implementation and plan to retire it in favor of the FK.
- **Decision (2026-07-23, current DB is test data only)**: existing free-text `fiscal_year` columns on `deno` and `books` are **left untouched, as-is** — no rename, no drop, no backfill of historical rows. We **add a new, separate, nullable column** `fiscal_year_id integer REFERENCES fiscal_years(id)` to each table instead. New records populate the new FK column going forward; the old free-text column keeps working for any code that still reads it, so nothing existing breaks. Mapping/backfilling old free-text values (`'2082'`, etc.) to real `fiscal_years` rows for the live production database is **not done automatically** — that requires the user's guidance on the live system, since fiscal year boundaries and historical corrections are business decisions, not something to infer from a string. This plan only prepares the schema/columns; the live backfill is a separate, user-directed step.

### 1.2 Per-module numbering audit

| Module | Numbering mechanism today | Resets by fiscal year today? | Table / display column | Key files |
|---|---|---|---|---|
| **d2m** | `SELECT COALESCE(MAX(serial_no),0)+1 FROM d2m WHERE fiscal_year_id=:fy AND d2m_type=:type` | **Yes** | `d2m.serial_no` (int) / `d2m.d2m_no` (formatted `{serial}-D2M/{fiscal_code}-{type}-{date}`) | `d2m/create.php:64-99`, duplicated in `d2m/create copy.php:85-116` |
| **jobticket** | `generateJobTicketCode()`: `COUNT(*) FROM job_ticket WHERE fiscal_year_id=:fy` zero-padded to 3 digits → `"$fyCode-JT$seq"`. Lot: `MAX(lot)+1 WHERE book_id=? AND fiscal_year_id=?` | **Yes**, but **fragile** (COUNT, not MAX — a deleted row causes a duplicate code) | `job_ticket.job_ticket_code`, `job_ticket.lot` | `jobticket/create.php:8-40`, duplicated in `create1.php`, `create3.php`, `create4.php`, and again in `config/functions.php:24-33` (not consistently `require`'d) |
| **bookpacking** | **None.** `name` is free-text user input; the only identifier is the raw PK `book_packing.id` | No number exists to reset | `book_packing.name` (text) / `book_packing.id` (global seq) | `bookpacking/create.php:17-96`, `bookpacking/create_.php` |
| **book** | **None.** `book_code` is free-text with a uniqueness check only | No number exists | `books.book_code` (text) | `book/create.php:56-59` |
| **forma / formaprinting** | **None.** `forma.order_no` is a static intra-book ordinal; `forma_printing.name` = `{job_ticket_code} - {date}` (inherits jobticket's numbering) | Inherited only (yes, indirectly) | `forma_printing.name` (text) | `formaprinting/create.php:900` (client-side concat) |
| **deno (entries)** | **None.** `ref_no` is fully manual user input; only a same-day duplicate-check exists. `deno.fiscal_year`/`deno_year` are auto-computed by DB trigger `update_deno_fields()` from the Nepali date, independent of any FK | No — `ref_no` never auto-generated | `deno.ref_no` (text) | `entries/create.php:81-108`, `entries/deno.php:100-134`, trigger `sql/_schema.sql:1265-1327` |
| **ctp (fctp_\*)** | **None.** `job_ticket_code` manually typed; separate `fctp_*` tables, own schema, not FK'd to `fiscal_years` | No | `fctp_job_tickets.job_ticket_code` (text) | `ctp/forma_ctp_job_create.php:32,132` |
| **ctp+cop** | **None.** Raw serial `id` only, own runtime-created table | No | `ctp_export_jobs.id` | `ctp+cop/ctp_export.php:81` |
| **coupon / vehicle, vehicle1 (fuel)** | **None.** `fuel_coupons.coupon_no` is optional free text (placeholder example `"CPN-2082-001"` suggests an intended convention that was never enforced) | No | `fuel_coupons.coupon_no` (text) | `vehicle/fuel_coupons*.php`, `vehicle1/fuel_coupons*.php` |
| **denoreports / report** | N/A — pure read/aggregate/print pages, no inserts | N/A | N/A | reads numbering columns from the modules above |

**Stale/duplicate code flagged for cleanup during implementation** (fixing numbering logic in only one copy would leave the others broken):
- `jobtick/` and `jobtick - Copy/` are older, smaller duplicates of `jobticket/` (missing `create3.php`, `create4.php`, `delete.php`, `view.php`, `reports/`). Confirm with the user whether these are still served/linked anywhere before touching them — do not silently delete.
- `d2m/create copy.php` duplicates `d2m/create.php`'s numbering logic.
- `jobticket/create1.php`, `create3.php`, `create4.php` each redefine their own local `generateJobTicketCode()`/`getFiscalYearId()` instead of sharing `config/functions.php`.

### 1.3 App structure relevant to Fiscal Year setup

- **Menu**: not database-driven — hardcoded in `includes/header.php` (mobile sidebar ~L94-148, desktop nav ~L150-243). An existing **"Setup" dropdown** (`includes/header.php:173-180`, currently Books / Forma / Users) is the natural home for a new "Fiscal Years" link.
- **Admin CRUD template to copy**: `config/users.php` (POST action switch, prepared statements, `$errors`/`$success` pattern) — best existing template for a new `config/fiscal_years.php` CRUD page.
- **Existing active-FY helper (partial)**: `config/functions.php:26-29` — `getFiscalYearId($conn)` returns just the id via `SELECT id FROM fiscal_years WHERE is_active = TRUE LIMIT 1`. Not consistently used — `index.php`, `api/book_packing.php`, and others each re-query `fiscal_years` independently instead of calling it.
- **DB connection**: `config/database.php` (PDO/Postgres, exposes global `$conn`), loaded via `config/bootstrap.php`.
- **Session/auth**: `config/auth.php` (`session_start`, `is_logged_in()`, `has_role()`, `can_access_module()`), backed by `src/Core/Auth.php`.
- **`system_settings` table exists** (`sql/_schema.sql:4138`, key/value) but is unused by any PHP code today — not the right place for active-fiscal-year state, since `fiscal_years.is_active` + trigger already models that directly.
- **Reports surface area** referencing fiscal year today (candidates for `fiscal_name` display): `denoreports/*.php` (books, daywisemonth, jobticket_fp, jobticket_fp1, monthly, monthly1), `report/*.php` (calendar_report, index/index1/index11, p2-p5, production_process_control(2), report_detail, trend), `bookpacking/ps_reports/*.php`, `formaprinting/reports/*.php`, `jobticket/reports/*.php`, `d2m/production_report.php` + `report_detail.php`, `book/report.php`, `hr/reports/hajiri_vivaran.php`, root `reports.php`, `api/book_packing.php`, `api/forma_printing.php`.

---

## 2. Design decisions to lock in before building

These need a yes/no from the user before implementation starts:

1. ~~**Fiscal year FK vs free-text**~~ — **RESOLVED**: additive column, not a migration. See Section 1.1. `deno.fiscal_year_id` and `books.fiscal_year_id` are added as new nullable FK columns alongside the existing free-text columns, which stay untouched. Live-data backfill is a separate, user-guided step outside this plan.
2. **New numbering for modules that have none today**: **RESOLVED for deno and bookpacking** — user confirmed target formats `1/deno/82-83` and `1/BP/82-83`, i.e. these get new fiscal-year-scoped numbering. Still open for **book**, **forma/formaprinting's own record**, and **coupon (`fuel_coupons.coupon_no`)** — confirm whether these also get new numbering or are out of scope for now. See Section 2.1 for the format spec.
3. **jobticket `COUNT(*)` bug**: fix to `MAX(...)+1`-based generation while we're in there (recommended, low risk), or leave behavior unchanged and only touch the fiscal-year-reset piece?
4. **Stale `jobtick/` / `jobtick - Copy/` folders**: confirm safe to leave untouched / flag as dead, or are they still routed to from anywhere?
5. **Display format**: confirm the desired on-screen/print label — e.g. "Fiscal Year: 2082/83" using `fiscal_name`, vs. showing `fiscal_code` — and where fiscal_name should appear (page header only vs. every row of a list vs. print letterhead).

### 2.1 Number format specification (per user guidance, 2026-07-23)

Principle: **keep each module's existing numbering "shape"/principle where one already exists (d2m, jobticket) — only make it reset to 1 per fiscal year and use a fiscal_name that's consistent everywhere.** For modules with no numbering today (deno, bookpacking), introduce a new number in the pattern the user specified:

```
{serial}/{ModuleCode}/{fiscalShort}
```

- `{serial}` — resets to `1` at the start of each fiscal year, scoped per module (and per sub-type where one already exists, e.g. d2m's `d2m_type`). Computed the same way as the existing d2m/jobticket logic: `COALESCE(MAX(serial), 0) + 1 WHERE fiscal_year_id = :active_fy [AND type = :type]` — no new counter table, consistent with existing codebase convention (Section 1.1).
- `{ModuleCode}` — short fixed code per module, matching the examples given: `deno` for deno entries, `BP` for book packing. Use the same short-code convention for any other module added later (d2m already uses `D2M`, jobticket already uses `JT`).
- `{fiscalShort}` — derived from `fiscal_years.fiscal_name` (e.g. `82-83`), **not** re-typed per module. One shared helper formats this consistently so every module and every report prints the identical fiscal label. This is what "fiscal_name should be consistent in all" means in practice: a single formatting function, not per-module string logic.

| Module | Current format (if any) | Target format | Change needed |
|---|---|---|---|
| d2m | `{serial}-D2M/{fiscal_code}-{type}-{date}` | same shape, keep as-is | Already resets to 1 by fiscal year — only standardize the fiscal-label piece to use the shared `fiscal_name`/`fiscalShort` helper instead of `fiscal_code` directly, if the user wants the printed label to match the new consistent style |
| jobticket | `{fyCode}-JT{seq}` (COUNT-based) | same shape, `1`-based reset via MAX() | Fix COUNT→MAX (decision #3); standardize fiscal-label piece same as d2m |
| deno (entries) | none — manual `ref_no` | `1/deno/82-83` | **New**: auto-generate `ref_no` (or a new dedicated column, TBD — see open question below) fiscal-year-scoped, format exactly as specified |
| bookpacking | none — free-text `name` | `1/BP/82-83` | **New**: auto-generate a new display-number column (`book_packing` currently has no such column — `name` stays free text as-is, or is repurposed — TBD), fiscal-year-scoped |
| book, forma/formaprinting, coupon | none | TBD | Pending decision #2 — confirm scope and target format (likely `1/BOOK/82-83`, `1/FP/82-83`, `1/CPN/82-83` following the same principle) if these are in scope |

**Resolved**: both sub-questions were resolved as the conservative/additive option and implemented as such:
- **deno**: `deno_no` (`1/deno/82-83`) is a **new, additional** column. `ref_no` is completely untouched — still manual, still has its duplicate-guard.
- **bookpacking**: `packing_no` (`1/BP/82-83`) is a **new, dedicated** column. `name` is completely untouched — still free text.

---

## 3. Implementation plan (once decisions above are confirmed)

### Phase A — Fiscal Year setup module
- `config/fiscal_years.php`: new CRUD page modeled on `config/users.php` (list, add, edit, set-active, deactivate). Setting a row active relies on the existing trigger to unset others — no extra guard code needed.
- Add "Fiscal Years" link to the **Setup** dropdown in `includes/header.php` (both mobile ~L108/168 style insert and desktop block), gated by `can_access_module('admin')` or equivalent.
- Add `getActiveFiscalYear($conn)` to `config/functions.php` (returns full row: id, fiscal_code, fiscal_name, start_date, end_date) next to the existing `getFiscalYearId()`; keep the old function as a thin wrapper for backward compatibility, or replace call sites (see Phase D).

### Phase B — Number series reset logic
- Add a single shared helper, e.g. `getFiscalShort($fiscalName)` / `formatFiscalNumber($serial, $moduleCode, $fiscalId, $conn)` in `config/functions.php`, so every module builds its number the same way and the fiscal-year segment is always sourced from `fiscal_years.fiscal_name` — never re-typed per module.
- **d2m**: already correct (fiscal_year_id-scoped, resets to 1). Wire the unused `get_next_d2m_serial()` DB function in, or leave the inline PHP — no functional reset change needed, just consolidate `create copy.php` duplication and switch the fiscal-label piece to the shared helper.
- **jobticket**: switch `generateJobTicketCode()` to `MAX()+1` semantics (if decision #3 = yes), consolidate the 4 duplicate copies into one shared function in `config/functions.php`, `require_once` it everywhere, switch fiscal-label piece to the shared helper.
- **deno**: add new `deno.fiscal_year_id` column (Section 1.1) + a new auto-generated number in the `1/deno/82-83` format, `MAX()+1` scoped by `fiscal_year_id`. Needs the open sub-question in 2.1 answered first (replace vs. supplement `ref_no`).
- **bookpacking**: add new fiscal-year-scoped display number in the `1/BP/82-83` format, `MAX()+1` scoped by `fiscal_year_id` (column already exists on `book_packing`). Needs confirmation on which column holds it (new column vs. repurposing `name`).
- **book / forma(printing) / coupon**: only if decision #2 is extended to include them — same `MAX()+1` pattern, add the display column if one doesn't exist yet, wire into each `create*.php`.

### Phase C — `fiscal_year_id` FK columns (additive)
- Add nullable `fiscal_year_id integer REFERENCES fiscal_years(id)` to `deno` and `books` (Section 1.1) — new column only, existing free-text `fiscal_year` columns stay untouched and keep working for existing code/reports.
- New records populate the new FK column going forward (e.g. via the same `trg_set_fiscal_year`-style auto-default pattern already used on `employee`, or explicitly in each `create*.php`).
- Historical-row backfill (matching old free-text values to real `fiscal_years` rows) is **out of scope for this plan** — live/production data needs the user's direct guidance before any backfill runs, since it's a business decision, not something inferable from the string alone.
- Audit remaining `fiscal_year_enum` usages in `sql/_schema.sql` — leave in place for now since the free-text columns aren't being removed; revisit only if/when the user later decides to fully retire the free-text columns.

### Phase D — `fiscal_name` display sweep
Go through every **create / edit / view / print / index** page per module and confirm/add a fiscal-year label sourced from `fiscal_years.fiscal_name` (via join or the new helper), not raw code/string:

| Module | create | edit | view | print | index/list |
|---|---|---|---|---|---|
| deno (entries) | `entries/create.php`, `create2.php`, `entries/deno/create.php` | `entries/edit.php` | — | — | `entries/index.php`, `index2.php` |
| d2m | `d2m/create.php` | `d2m/edit.php` | `d2m/view.php` | `d2m/print.php`, `print2.php` | `d2m/index.php`, `index2.php` |
| jobticket | `jobticket/create.php` (+`create1/3/4.php`) | `jobticket/edit.php` | `jobticket/view.php` | `jobticket/print.php` | `jobticket/index.php` |
| bookpacking | `bookpacking/create.php` | `bookpacking/edit.php` | `bookpacking/view.php` | — (check `ps_reports/`) | `bookpacking/index.php` |
| book | `book/create.php`, `create1.php` | `book/edit.php` | `book/view.php` | `book/print.php` | `book/index.php`, `index1.php` |
| forma / formaprinting | `formaprinting/create.php`, `create2.php` | `formaprinting/edit.php` (+`edit2/5.php`) | `formaprinting/view.php`, `forma_printing_view.php` | `formaprinting/print.php` | `formaprinting/index.php`, `index2.php`; `forma/index.php`, `index3.php` |
| coupon (fuel) | `vehicle/fuel_coupons*.php`, `vehicle1/fuel_coupons*.php` | (same files, inline edit) | — | — | `vehicle/vehicle_index.php`, `vehicle1/vehicle_index.php` |
| Reports | — | — | — | — | `denoreports/*.php`, `report/*.php`, `bookpacking/ps_reports/*.php`, `formaprinting/reports/*.php`, `jobticket/reports/*.php`, `d2m/production_report.php`, `d2m/report_detail.php`, `book/report.php`, `reports.php`, `hr/reports/hajiri_vivaran.php` |

Each cell above needs: (a) confirm the page already has `fiscal_year_id`/`fiscal_year` available in its query, (b) join/lookup `fiscal_name`, (c) render it in the header/filter/label area, (d) for print pages specifically, confirm it appears in the printed letterhead, not just the on-screen filter bar.

### Phase E — Cleanup
- Consolidate duplicate create files (`d2m/create copy.php`, `jobticket/create1/3/4.php`, `bookpacking/create_.php`) once shared numbering logic is centralized, or explicitly confirm with the user they're intentionally kept as alternate flows.
- Confirm fate of `jobtick/` and `jobtick - Copy/` (decision #4).

---

## 4. Open questions for the user (blocking Phase A start)

**Resolved so far:**
- Decision #1 (FK vs free-text) — additive column, no migration of existing data. ✅
- Decision #2 for **deno** and **bookpacking** — new numbering confirmed, format `1/deno/82-83` and `1/BP/82-83`. ✅

**Still open:**
1. Decision #2 for **book**, **forma/formaprinting**, **coupon** — in scope for new numbering too, or leave as-is for now?
2. Decision #3 — fix jobticket's `COUNT(*)`→`MAX(...)+1` while touching this code, yes/no?
3. Decision #4 — fate of stale `jobtick/` / `jobtick - Copy/` folders.
4. Decision #5 — exact on-screen/print label wording and placement for `fiscal_name`.
5. **deno sub-question** (Section 2.1): does the new `1/deno/82-83` number *replace* the existing manual `ref_no` field, or sit alongside it as a new column while `ref_no` stays user-typed?
6. **bookpacking sub-question** (Section 2.1): new dedicated column for `1/BP/82-83`, or repurpose the existing free-text `name` field?
7. ~~Confirm the Setup dropdown...~~ **Implemented** as-is: added to the existing Setup dropdown, gated by `redirect_if_not_authorized('admin')` on the page itself (same pattern as `config/users.php`; the nav link itself isn't role-hidden, matching how Users is already handled).

---

## 5. Implementation log (2026-07-23)

Applied to the **local dev database** (`press_jemc` on `localhost`, verified via direct `psql` connection and a CLI smoke test of the new helpers — see below). Not yet applied to any other environment; the user will push and migrate live separately.

### Done

**Schema** — `sql/migrations/005_fiscal_year_numbering.sql` (additive only, `IF NOT EXISTS` throughout, applied):
- `deno`: + `fiscal_year_id` (FK, nullable), + `deno_serial_no` (int), + `deno_no` (varchar, unique partial index)
- `books`: + `fiscal_year_id` (FK, nullable) — not yet populated by any create page (book numbering stayed out of scope, see below)
- `book_packing`: + `packing_serial_no` (int), + `packing_no` (varchar, unique partial index) — `fiscal_year_id` already existed

**`config/functions.php`** — new shared helpers (all guarded with `function_exists()`):
- `getActiveFiscalYear($conn)` — full active row, not just id
- `getFiscalShort($fiscalYear)` — `"2082-83"` → `"82-83"`, the single source of truth for the fiscal label used in every number
- `generateFiscalScopedNumber($conn, $table, $serialColumn, $fiscalYearId, $moduleCode, $fiscalYear, $extraWhere)` — generic `MAX()+1` scoped generator producing `"{serial}/{moduleCode}/{fiscalShort}"`
- `generateJobTicketCode()` fixed: `COUNT(*)` → `MAX()`-based (extracted from `job_ticket_code` via regex), per decision #3 (resolved as recommended)

**jobticket** — `create.php`, `create1.php`, `create3.php`, `create4.php` deduplicated: all now `require_once config/functions.php` instead of each redeclaring their own (buggy) local copy of `generateJobTicketCode()`/`getFiscalYearId()`/`getStatusBadge()`.

**deno** (`entries/create.php`):
- New `deno_no` (`1/deno/82-83`) generated on create via `getActiveFiscalYear()` + `generateFiscalScopedNumber()`; `ref_no` untouched.
- Fixed a pre-existing, unrelated fatal error: the "recent records" table queried `v_deno_full_details`, a view that **does not exist** on this database. Replaced with an equivalent direct join query (also needed to add `deno_no`/`fiscal_name` to the display anyway).
- Recent-records table now shows **Deno No.** and **Fiscal Year** columns (sourced from `fiscal_years.fiscal_name`).

**bookpacking**:
- `create.php`: generates `packing_no` (`1/BP/82-83`) on insert.
- `index.php` and `view.php`: now select and display `packing_no` and `fiscal_name` (was `fiscal_code`).

**d2m**: left as-is — already correctly resets to 1 per fiscal year; no functional change needed. (Standardizing its label piece onto the shared `getFiscalShort()` helper and de-duplicating `create copy.php` were not done — low priority since it already works correctly.)

**Fiscal Year setup module**:
- New `config/fiscal_years.php` — add / edit / set-active CRUD, modeled on `config/users.php`. "Set Active" relies entirely on the existing `trg_single_active_fiscal_year` trigger. No delete action (fiscal years are FK'd from many tables).
- Linked into the existing **Setup** dropdown in `includes/header.php`.

### Verified

- `php -l` clean on every edited file.
- Local PHP is 7.4.33, matching the documented production runtime — no 7.4-incompatible syntax used.
- CLI smoke test against the live local DB confirmed `getActiveFiscalYear()`, `getFiscalShort()`, and `generateFiscalScopedNumber()` produce exactly `1/deno/82-83` and `1/BP/82-83` for the current active fiscal year (2082-83), and `generateJobTicketCode()` returns a valid `2082-JT###` code.
- `fiscal_years` list query for the new admin page verified directly against the DB.

### Deliberately NOT done in this pass (still open)

- **book / forma-printing / coupon numbering** — decision #2 for these three modules was never resolved by the user; no schema or code changes made for them. `books.fiscal_year_id` column exists (added for consistency/future use) but nothing populates it yet.
- **`jobtick/` / `jobtick - Copy/` stale folders** — left untouched (decision #4 still unanswered).
- **Live/production migration** — `sql/migrations/005_fiscal_year_numbering.sql` needs to be run on the production database before any of this works there (same as migrations 003/004 discussed earlier). Nothing here touches production automatically.

---

## 6. Round 2 — jobticket / d2m / deno completed end-to-end (2026-07-23)

Per explicit user instruction ("first complete jobticket, d2m, deno entries properly"), did a full create/edit/view/print/index pass on these three modules specifically, rather than a shallow one-page-per-module touch.

### Important correction found during this pass

**`entries/create.php` is dead code — not linked from anywhere in the app.** The real, live "Add Deno" entry point (linked from `index.php`'s dashboard buttons/quick-links) is **`entries/deno.php`**, a completely different file with its own create/update/delete handling. Round 1's deno work went into `create.php`, which nobody actually uses. This round fixes the real file:

- **`entries/deno.php`** (the real create/edit page): now generates `deno_no`/`deno_serial_no` via `getActiveFiscalYear()` + `generateFiscalScopedNumber()` on create, exactly like `entries/create.php` got in round 1. Records table, empty-state colspan, and Excel export all updated to show **Deno No.** Verified with a rolled-back CLI transaction against the live local DB — insert succeeds, produces `1/deno/82-83`, and the existing `update_deno_fields()` trigger still correctly sets the legacy `fiscal_year`/`deno_year` text fields alongside it.
- `entries/create.php` was still fixed in round 1 and left in place (harmless, just unused) — not deleted, since confirming dead code should be destroyed is the user's call, not an autonomous one.

### jobticket — now fully consistent

- `config/functions.php`: `getJobTicketWithDetails()` now also selects `fy.fiscal_name`.
- `jobticket/view.php`: displays `fiscal_name` instead of raw `fiscal_code`.
- `jobticket/index.php`: main list query, Excel export, PDF export, and the fiscal-year filter dropdown all switched from `fiscal_code` to `fiscal_name`.
- `jobticket/print.php`: query now selects `fiscal_name`; the "शैक्षिक सत्र" (academic session) field — previously the odd hack `fiscal_code + 1` — now shows the real `fiscal_name`.
- `jobticket/edit.php`: previously showed **no** fiscal year at all; added a read-only Fiscal Year field (fetched via the ticket's `fiscal_year_id`).

### d2m — now fully consistent

- `d2m/index.php`, `d2m/view.php`, `d2m/edit.php`: all three aliased `fy.fiscal_code AS fiscal_year_name` — silently showing the code, not the name, despite the HTML already saying "Fiscal Year". Changed the alias source to `fy.fiscal_name` in all three (one-line fix each, since the HTML already reads `fiscal_year_name`). Also fixed the matching `GROUP BY` clause in `index.php`.
- `d2m/print.php`: was already correct (already selected `fiscal_name`) — no change needed.
- `d2m/create.php`: had no fiscal year indicator anywhere in the UI; added an "Fiscal Year: {fiscal_name}" badge to the card header, fetched at page load.

### deno (entries) — now fully consistent

- `entries/deno.php`: see above — the real fix.
- `entries/index.php` (the real list/management page — separate from `deno.php`'s own embedded 20-row table): added `deno_no` and `fiscal_name` (via a new join to `fiscal_years`) to the main query, table header/rows, Excel export, and fixed all colspans (empty-state 15→17, totals-row 8→10).
- `entries/edit.php`: added `fiscal_name` to the fetch query and displays both **Deno No** and **Fiscal Year** in the record-info banner (both are auto-generated/trigger-set, so shown read-only, not editable).

### Verified

- `php -l` clean on every file touched in this round.
- Live DB verification: re-ran the d2m index query with the `fiscal_name` alias and `GROUP BY` fix directly against `press_jemc` — returns correct rows (e.g. `2082-83`).
- Live DB verification: dry-run (rolled-back transaction) of the exact `entries/deno.php` insert path — confirms `deno_no` generation, FK insert, and trigger interaction all work together correctly.

### Still open after this round

- bookpacking's Phase D sweep (edit.php, print/report pages) wasn't revisited in this round — round 1's create/index/view coverage stands.
- book / forma-printing / coupon numbering — still unresolved (decision #2).
- The wider report-page sweep (`denoreports/*`, `report/*`, `jobticket/reports/*`, `formaprinting/reports/*`, `hr/reports/*`, etc.) — still not started.
- `jobtick/` / `jobtick - Copy/` stale folders — still untouched.
- Live/production migration still pending (same as always — `005_fiscal_year_numbering.sql` needs to run there).

---

## 7. Round 3 — search filters + fiscal-year-driven defaults across index/report pages (2026-07-23)

Per user requests during testing: (1) add Deno No + Fiscal Year search filters to `entries/index.php`, defaulting to the active fiscal year; (2) when the active fiscal year changes, every module's index/report page default filters should follow automatically (not a hardcoded year); (3) sweep report pages the same way. The Nepali-calendar-widget rollout requested alongside this was explicitly deferred to a separate pass (user's choice when asked).

### New shared helper

`config/functions.php::getActiveFiscalDateRange($conn)` — returns the active fiscal year's BS date range (`fiscal_code.'.04.01'` → `(fiscal_code+1).'.03.32'`) plus `fiscal_code`/`fiscal_year_id`, so every page computes the "current FY date range" identically instead of each hardcoding `'2082'`.

### `entries/index.php`

- Added **Deno No** (`ILIKE`) and **Fiscal Year** (dropdown, all fiscal years) search filters.
- Fiscal Year filter defaults to the active fiscal year on first load (`isset($_GET['fiscal_year_id'])` so explicitly choosing "All Fiscal Years" sticks — doesn't snap back to active).
- Date-range default switched from hardcoded `'2082.04.01'`–`'2083.03.32'` to `getActiveFiscalDateRange()`.

### Fiscal-year-default sweep — index/report pages

Applied the same "default to active fiscal year, `isset()` so an explicit blank choice sticks" pattern to:
- `d2m/index.php` — date-range default (was hardcoded `'2082'`).
- `jobticket/index.php`, `bookpacking/index.php`, `d2m/production_report.php`, `report/p2.php`, `report/p3.php`, `report/production_process_control.php`, `formaprinting/reports/production_report.php` — fiscal-year dropdown filter default (was blank/"All").
- `hr/reports/hajiri_vivaran.php`, `hr/reports/talab_report.php` — BS year default (was hardcoded `2082`).
- `denoreports/2.php` — year default in both its AJAX-export and page-render code paths (was hardcoded `'2082'`).

Also fixed a few fiscal-year dropdowns that were still displaying raw `fiscal_code` instead of `fiscal_name` while touching these files: `bookpacking/index.php`, `d2m/production_report.php`, `formaprinting/reports/production_report.php`.

**Already correct, no change needed** (verified, not just assumed): `report/index.php`, `report/index1.php`, `report/index11.php`, `report/calendar_report.php`, `report/trend.php`, `report/p4.php`, `report/p5.php`, `report/production_process_control2.php`, `formaprinting/reports/bp_create.php`, `jobticket/reports/daily.php`, `jobticket/reports/deno_report.php`, `jobticket/reports/job_ticket_export.php`, `denoreports/daywisemonth.php`, `denoreports/monthly.php`, `denoreports/monthly1.php` — these already queried `is_active = true` and fell back to `'2082'` only if no fiscal year is active at all.

**Not touched**: `report/report_detail.php` (drill-down page always reached via explicit link params from a parent report — no independent "default" to set), single-date defaults on `denoreports/daily.php`/`books1.php` (placeholder example dates, not fiscal-year ranges — different concern, left as-is).

### Verified

- `php -l` clean on all 12 files touched this round.
- Live DB smoke test of `getActiveFiscalYear()`/`getActiveFiscalDateRange()`: confirmed the active fiscal year had actually changed mid-session (to **2083-84**, presumably via the new `config/fiscal_years.php` admin page) and the helpers picked it up immediately — proving the "changing active FY moves every default automatically" requirement genuinely works end-to-end, not just in theory.

### Explicitly deferred (user's choice)

- **Nepali calendar-picker widget rollout** across create/edit/index date filters in deno, reports, d2m, jobticket, bookpacking, formaprinting — not started. Some pages already have it (`d2m/create.php`, `entries/deno.php` use `NepaliDatePicker v5`); most search-filter date inputs across the report pages above are still plain text fields with a placeholder hint. Needs its own audit pass.

---

## 8. Book Titles — cross-year identity + lifetime production reporting (2026-07-23)

Separate from fiscal-year numbering, but related and requested in the same session: `books.book_code` is UNIQUE, so a revised edition (new price/pages/content) each fiscal year needs a brand-new code (e.g. `MATH6-NT-2080` → `MATH6-NT-2081`). There was no stable identity to answer "how much Math6-NT have we produced across every year?" — this section adds one, additively, without changing how job_ticket/forma/deno/d2m reference the specific edition.

### Schema (`sql/migrations/006_book_titles_versioning.sql`, applied to local DB)

- New `book_titles` table: `title_code` (unique, stable, never changes across editions), `title_name`, `class_level`, `is_translated`, `book_type`, `business_associated`, `is_active`.
- `books` gets three new columns: `title_id` (FK to `book_titles`), `page_count` (this edition's page count — changes year to year with content, tracked directly rather than inferred from `forma`, since forma rows may not exist yet when a book is created), `is_active` (hides obsolete editions from day-to-day pick-lists; never affects reports).

### `book/create.php` (create + edit, same file)

- New "Title (Cross-Year Identity)" section: link to an existing active title, or create a new one inline (title_code/title_name auto-suggested from the book name, editable). `resolveTitleId()` handles both paths server-side, including reusing an existing title_code if it already exists rather than erroring.
- New **Page Count** field and **Active Edition** toggle (defaults checked — "no need to always show the old edition... when needed it should be seen in the report" per your request: unchecking only hides it from pick-lists, never from reports/history).
- **Book code generation now aligns with the Title**: when a title is linked (existing or new), the auto-generated book code uses the title_code as its base (`{title_code}-{fiscalYear}`) instead of re-abbreviating the book name from scratch each time — so every edition of the same title shares a recognizable, consistent code pattern. Falls back to the old name-abbreviation behavior only when no title is linked.
- Edit mode: if a book's linked title has since gone inactive, it's still shown as the selected option (fetched separately) rather than silently disappearing from the dropdown.

### `book/index.php`

- Defaults to showing **Active editions only** (`status=active`), with a Status filter (Active / Obsolete / All) — obsolete editions stay fully in the database and every report, just out of the day-to-day list.
- New columns: Title, Page Count, Status badge.

### `book/view.php`

- Shows Page Count, Edition Status (Active/Obsolete), and the linked Title with a link to that title's lifetime report.

### New: `book/title_report.php` — the "summing + separate" report

- Top-level list: one row per Title, with lifetime production **summed from `deno.total_qty` across every edition** (matches the "10,000 this year + 25,000 next year" framing) — verified end-to-end with a rolled-back transaction test producing exactly 35,000 for a 2-edition title.
- Click a title to expand a **separate**, per-edition breakdown table (book code, fiscal year, page count, active status, entry count, that edition's own total) — so both the combined total and the individual years are visible in one place.
- Filterable by search/class/title-status. Linked from `book/index.php` and the main `reports.php` hub.

### `denoreports/monthly.php` / `daywisemonth.php`

- Both already-per-book report tables now show the linked Title code as a small annotation under the book code (added via a `LEFT JOIN book_titles`), so a user browsing these existing per-fiscal-year reports can see which title an edition belongs to, without restructuring the tables' existing pivot/column layout.

### `config/functions.php`

- `getBooks($conn, $includeInactive = false)` now filters to active editions by default (used by `jobticket/edit.php` etc.) — reports and other explicit book queries were **not** changed to filter by active, per the "always visible in reports" requirement.

### Verified

- `php -l` clean on all 8 files touched.
- Full dry-run (rolled-back transaction): created a title, two editions (2080 inactive/120pp, 2081 active/135pp), two deno production entries (10,000 and 25,000), then ran the exact lifetime-report and per-edition queries — got `lifetime_total = 35000`, `edition_count = 2`, and correct per-edition figures. No data left behind.

### Fixed: `books.created_by` truncation bug (2026-07-23, follow-up)

The pre-existing bug above is now fixed, additively. `sql/migrations/007_books_created_by_id.sql` adds `books.created_by_id integer REFERENCES users(id)` — the legacy `created_by varchar(5)` column is left in place (still `NOT NULL`) but `book/create.php` now writes a safely truncated value there (`substr($username, 0, 5)`, just to satisfy the constraint) while `created_by_id` becomes the real source of truth. `book/index.php` and `book/view.php`'s "Created By" display now joins on `created_by_id = users.id` instead of the broken username match. Verified with a rolled-back transaction using a 15-character username (`NyuchheRamTyata`) that previously crashed the insert — now inserts cleanly and displays the full username correctly.

### Not touched / still open

- Other book dropdowns across the app (`entries/*.php`, `forma/*.php`, `jobtick*/`, various report pages) still list *all* books regardless of `is_active` — only `getBooks()`/`jobticket/edit.php` was switched. Extending "active-only in pick-lists" everywhere is a larger sweep, not done in this pass.
- No auto-deactivation: creating a new edition of a title does **not** automatically flip the previous edition's `is_active` to false — that's a manual step (or future enhancement) so nothing changes state unexpectedly.
- Live/production migration still pending — `006_book_titles_versioning.sql` needs to run there too, same as `005`.

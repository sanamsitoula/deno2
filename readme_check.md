# JEMC Production System — Audit & Standardization Log

Running record of the codebase audit, database restore, and bug fixes performed on this project. Started 2026-07-22.

## Environment

- **Stack**: PHP 7.4.33 (XAMPP, Apache on port **81** — port 80 is taken by IIS on this machine), PostgreSQL 15 (server) restored from a pg_dump 17.5 backup
- **URL**: app is served at `http://localhost:81/jemc/` (aliased to `D:/claude_project/deno2`; old `/deno2` paths 301-redirect to `/jemc`)
- **DB**: `press_jemc`, user `postgres` — see `config/database.php`
- **Auto-prepend**: `.htaccess` sets `auto_prepend_file` to `config/fix_docroot.php`, which patches `$_SERVER['DOCUMENT_ROOT']` so the codebase's `require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/...'` pattern keeps working after the folder was renamed/aliased to `/jemc`.

## Database restore (2026-07-22)

Restored `press_jemc` from `sql/deno2_20260722.sql` (pg_dump custom format) via `pg_restore --clean --create`. This backup predated the payroll feature, so 5 migrations had to be applied afterward:

```
001_employee_payroll_columns.sql
002b_create_payroll_tables.sql   <- must run BEFORE 002, despite its own comment saying "after"
002_payroll_tax_tables.sql       <- ALTERs payroll_processing, which 002b actually creates
003_dynamic_salary_system.sql
004_leave_types_and_hajiri.sql
```

**Note for future restores**: the migration file names/comments say to run 002 before 002b — that's wrong for a from-scratch restore. Run 002b first.

## Bugs found and fixed

### 1. `payroll_processing` table missing → dashboard fatal error
Root cause: backup predates the payroll feature. Fixed by running the migrations above in corrected order.

### 2. PHP 8-only syntax on a PHP 7.4 server
This server only has PHP 7.4 installed (no PHP 8 available), but parts of the codebase used PHP 8+ syntax:
- `match()` expressions — converted to array-lookups / `switch` / if-chains in 8 files (`includes/functions.php`, `report/production_process_control2.php`, `report/p5.php`, `vehicle/monthly_summary.php` + 2 variants, `vehicle/fuel_summary.php`, `denoreports/daily.php`, `denoreports/daily_report.php`, `hr/employee/import_k.php`)
- `str_contains()` / `str_starts_with()` / `str_ends_with()` — added guarded polyfills in `config/database.php` (loaded on virtually every page) rather than touching every call site

Verified: all 362 `.php` files in the repo now pass `php -l` with zero syntax errors.

### 3. One corrupted file
`auto/report_details.php` was truncated mid-table (broken since an earlier "restore corrupted files" commit per git history). Not linked from any menu (dead page), but closed out the HTML/PHP structure using a sibling page's pattern as reference so it no longer fatals if hit directly.

### 4. "Headers already sent" — export/delete handlers running after `header.php`
Recurring pattern: `includes/header.php` (which prints `<!DOCTYPE html>...`) was being `require_once`'d *before* code later in the same file that calls PHP's `header()` for redirects or file downloads. Once HTML output starts, `header()` calls silently fail (or warn) — redirects don't happen, Excel/PDF exports get HTML mixed into the file.

Fixed in:
- `book/index.php` — export handler, delete handler
- `book/create.php` — **three** separate broken redirects: permission check, "book not found", and delete-from-edit-page. This one was silent (no visible error) — deleting a book from the edit page looked like it did nothing.
- `jobticket/index.php` — export handler, delete handler

**This pattern is worth checking on every other module's index/create/edit page** — it's an easy copy-paste bug and there are ~20+ similar modules.

### 5. Global variable collision: `$current_page` — pagination broken app-wide
`includes/header.php` set `$current_page = basename($_SERVER['PHP_SELF'])` (leftover dead code — never actually read anywhere, apparently obsolete nav-highlighting code). Because `header.php` is `require_once`'d directly into the caller's scope, this **clobbered any page's own `$current_page` pagination variable** if `header.php` ran after that variable was set — which it did on `jobticket/index.php` after the header-order fix above. Symptom: "non-numeric value" warnings and pagination stuck on page 1 no matter what.

Fixed by deleting the dead assignment from `includes/header.php` entirely (confirmed unused anywhere else in the codebase).

### 6a. AJAX endpoints returning HTML-contaminated JSON
`bookpacking/index.php`'s AJAX delete handler (soft-delete via `fetch()`/`action=delete`) buffers `header.php`'s HTML via `ob_start()` (correct pattern) but never called `ob_end_clean()` before echoing its `json_encode(...)` response — so the buffered HTML got flushed together with the JSON at script end. The frontend's `fetch(...).then(r => r.json())` would fail to parse this. Fixed by adding `ob_end_clean(); header('Content-Type: application/json');` right before the JSON response, matching the pattern already used elsewhere in the same file for the permission-check redirect.

### 6b. UTF-8 BOM prefix on 28 files — breaks JSON/redirects even with `ob_start()`
28 files across the repo start with a UTF-8 BOM (`EF BB BF`) *before* the opening `<?php` tag. Since that's raw bytes outside any PHP tag, PHP outputs it immediately as the file is parsed — **before `ob_start()` even runs**, bypassing the output-buffering pattern the rest of the codebase relies on to make `header()`/JSON-response calls safe after `header.php`. This is a sneaky variant of bug #4/#6a that `ob_start()` alone doesn't protect against.

Affected (BOM stripped from all): `book/create1.php`, `book/edit.php`, `book/get_lot_info.php`, `book/print.php`, `book/report.php`, `d2m/print.php`, `denoreports/monthly1.php`, `jobticket/create.php`, `jobticket/edit.php`, `jobticket/get_lot_info.php`, `jobticket/print.php`, `jobticket/report.php`, `report/calendar_report.php`, `report/index.php`, `vehicle/fuel_summary.php`, `vehicle/monthly_summary.php`, `vehicle/vehicle_daily_log_v2.php`, `vehicle1/fuel_coupons_v2.php`, plus the dead `jobtick/*` and `jobtick - Copy/*` duplicate folders.

**Worth adding a repo-wide check for this** (any editor/tool that saves as "UTF-8 with BOM" will silently reintroduce it) — see Standardization section below.

### 6c. `d2m/edit.php`, `d2m/view.php`, `d2m/double_check.php`, `entries/details.php` — same header-order bug as #4
Same root cause as fix #4 (redirects via `header()` after `header.php` already sent HTML), confirmed live via internal links (`d2m/index.php` → `view.php` → `edit.php`/`double_check.php`; `entries/index.php` → `details.php`). Fixed with the `ob_start()` pattern already used by ~30 other files in this codebase (rather than restructuring each file's logic) for consistency.

### 6. `includes/footer.php` — dashboard-only code shipped on every page
`footer.php` is `require_once`'d by every page, but it unconditionally builds Chart.js data using `$subject_data`, `$class_data`, `$job_vs_printed`, `$daily_production` — variables only ever set by `index.php` (the dashboard). Every other page threw `array_column()`/`array_map()` warnings on `null`.

Fixed with null-coalescing defaults (`$subject_data ??= [];` etc.) at the top of `footer.php`.

Side note: the canvas elements these charts target (`subjectChart`, `classChart`, etc.) don't even exist in the current `index.php` anymore — this chart code is fully dead/orphaned on the dashboard too. Not touched (out of scope for a bug-fix pass), but worth deleting in a later cleanup.

## 7. Hardcoded URL prefix — broke CSS/nav/footer/redirects on the live server

The live server (nginx on `10.10.10.2:80` reverse-proxying `/deno2/` → Apache on `127.0.0.1:8080`, Apache with no custom vhost, app served directly from `.../htdocs/deno2`) showed completely unstyled nav/pages — raw HTML with no CSS applied.

Root cause: `includes/header.php` hardcoded `$base_url = '/jemc'`, and several core files (`config/auth.php`, `src/Core/Auth.php`, `login.php`, `logout.php`, `config/functions.php`) hardcoded redirects to `/deno2/...` or `/jemc/...` directly instead of going through `getUrl()`. This is leftover from an earlier "URL /deno2→/jemc" rename commit that only accounted for the dev box's specific Apache `Alias /jemc` setup (per `config/bootstrap.php`'s own comment: *"DOCUMENT_ROOT is fixed to D:/claude_project"* — literally this one machine). The live server was never updated to match, and still proxies `/deno2/`, so every `/jemc/assets/...` CSS/JS link 404'd, and `/jemc/login.php` redirects would 404 too.

**Fix**: replaced the hardcoded prefix with `detect_deno2_base_url()` — a small function (duplicated with `function_exists()` guards in `includes/header.php`, `config/auth.php`, `config/functions.php`, and `src/Core/Auth.php`, since different pages load these in different orders) that derives the actual URL prefix for the current request by comparing `$_SERVER['SCRIPT_FILENAME']` against `$_SERVER['DOCUMENT_ROOT'] . '/deno2'`, and applying the same offset to `$_SERVER['SCRIPT_NAME']`. This works correctly regardless of whether the app is reached via `/jemc/`, `/deno2/`, or no prefix at all — **no per-environment configuration needed**.

Also fixed `index.php` (the dashboard), which had the *opposite* problem — 12 links hardcoded to `/deno2/...` (never updated by that same rename commit), which only worked on the dev box by accident via the `RedirectMatch 301 /deno2 → /jemc` rule.

**Not yet fixed** (same bug, but confined to files inside modules explicitly excluded from this audit — HR, attendance): `hr/setup/index.php`, `hr/employee/profile.php`, `hr/index.php`, `hr/setup/salary.php`, `hr/employee/department/index.php`, `attendance_device/device_users.php`. These pages' own internal links/redirects/image paths still hardcode `/jemc/...` — the shared nav/CSS/footer bug affecting *every* page is fixed, but navigating *within* HR pages on the live server would still hit some broken internal links until those are patched too.

**Also found**: ~40 more files (mostly `auto/*`, `formaprinting/*`, `bookpacking/*`, `report/*`) contain the same hardcoded-path pattern in scattered internal links (not the shared nav/CSS, so lower visual impact, but still worth a follow-up sweep). Not fixed in this pass — flagging for a dedicated cleanup session given the surface area.

## 8. Apache/Windows environment hardening (dev box only — not in git)
Converted the bare `Alias /jemc` in this dev machine's `httpd-vhosts.conf` into a proper `VirtualHost` (`jemc.deno2`) with `DocumentRoot "D:/claude_project"` set natively, removing the fragile `auto_prepend_file` shim (`config/fix_docroot.php`) that was causing intermittent "Failed to open stream" fatals under file-system contention. This is a local environment change only, not part of the repo — your live server's Apache config is separate and untouched.

## Module audit progress

Testing each module's Create / Read / Update / Delete against the live app (logged in as `usha`, role `admin`).

| Module | Index | Create | Update | Delete | Notes |
|---|---|---|---|---|---|
| **Books** (`book/`) | ✅ | ✅ | ✅ | ✅ | Fully verified end-to-end with a real demo record (created, edited, deleted). Dead files in this folder: `create1.php`, `index1.php` (not linked from any menu). |
| **Job Tickets** (`jobticket/`) | ✅ (after fix #4/#5) | ✅ | ✅ | ✅ | Verified via direct POST against `create.php` (action=create/update/delete) and `index.php`'s inline delete handler — all confirmed against the live DB (created, updated, deleted a real test record each time). Create/update/delete on `create.php` never had the header-order bug (they re-render with a `$message` div instead of `header()` redirecting), so no fix was needed there. Dead files: `create1.php`, `create3.php`, `create4.php`, `index_test.php`. `delete.php` exists as a standalone file but isn't linked anywhere — `index.php` and `create.php` both handle delete inline; worth confirming `delete.php` is truly dead before removing. |
| **Book Packing** (`bookpacking/`) | ✅ | ✅ | not tested | ✅ (soft-delete) | Found and fixed a real bug: the AJAX soft-delete endpoint (`index.php?action=delete`) returned JSON contaminated with the full HTML page — see fix #6a/#6b below. Create confirmed via `create.php` → redirects to `view.php`. |
| **Vehicle List** (`vehicle/vehicle_index.php`) | ✅ | ✅ | ✅ | ✅ (soft-delete) | Fully verified end-to-end with a real test vehicle. |
| **Drivers** (`vehicle/driver_index.php`) | ✅ | ✅ | ✅ | ✅ (soft-delete) | Fully verified end-to-end. |
| **Vehicle Daily Log** (`vehicle/vehicle_daily_log_v2.php`) | ✅ | ✅ | not tested | not tested | Create confirmed. |
| **Vehicle Maintenance** (`vehicle/vehicle_maintenance.php`) | ✅ | ✅ | not tested | not tested | Create confirmed. |
| **Vehicle Assignments** (`vehicle/vehicle_assignments.php`) | ✅ | ✅ (validated) | not tested | not tested | Create endpoint confirmed functioning — hit the "vehicle already has an active assignment" business-rule check correctly, proving the validation path works. |
| **Fuel Price History** (`vehicle/fuel_price_history.php`) | ✅ | ✅ | not tested | not tested | Create confirmed. |
| **Fuel Coupons** (`vehicle/fuel_coupons_v2.php`) | ✅ | ✅ | not tested | not tested | Create confirmed (coupon sub-module; distribution sub-module not tested). |
| D2M, Entries, Forma, Forma Printing, Reports, Setup | in progress | | | | |

## Database overview

`press_jemc` has **98 tables/views** in `public` schema after migrations. Key subject areas (grouped by FK relationships, not exhaustive):

- **Production core**: `books` → `job_ticket` → `job_ticket_details` → `forma_printing`; `deno` (production entries) links to `books`, `job_ticket`, `book_packing`, `d2m`; `d2m`/`d2m_items` (design-to-machine workflow)
- **HR/Payroll**: `employee` (central) → `employee_designation`, `employee_salary`, `employee_salary_components`, `employee_tax_records`, `education_details`, `employee_family`, `employee_documents`, `leave_balance`; `payroll_processing` → `payroll_details`; `salary_grades`/`grade_salary_components`/`salary_components` (dynamic pay-scale system added by migration 003); `department`, `designation`, `level` as lookup tables
- **Attendance**: `attendance` (links `employee`, `shifts`, `attendance_status`) + `attendance_monthly_summary`; ZKTeco biometric device integration (`zkteco_devices`, `zkteco_raw_attendance`, `zkteco_pull_log`, `zkteco_sync_queue`, `zkteco_user_mapping`) — device data flows into `attendance` via `zkteco_raw_attendance.attendance_id`
- **Vehicle/Fleet**: `vehicles` → `vehicle_daily_logs`, `vehicle_maintenance_records` (→ `maintenance_parts`, `maintenance_types`), `fuel_coupons` (→ `fuel_coupon_distributions`), `vehicle_driver_assignments` → `drivers`
- **CTP/Forma imposition**: separate near-duplicate schema `fctp_*` (`fctp_books`, `fctp_job_tickets`, `fctp_formas`, `fctp_uploads`) alongside `forma`, `imposition_templates`, `ctp_export_jobs` — **these look like two parallel/competing implementations of the same feature; worth clarifying which is current**
- **Reconciliation**: `recon_brt`, `recon_pkr`, `recon_marketing`, `recon_software`, `recon_stockkeeper`, `recon_comparative`, `recon_modules`, `recon_opening_stock_2080` — appear to be ad-hoc reconciliation tables, likely built per-report rather than a designed subsystem
- **Fiscal years** (`fiscal_years`) is a hub table referenced by nearly every transactional table (`employee`, `job_ticket`, `payroll_processing`, `salary_grades`, `tax_slabs`, `statutory_rates`, `book_packing`, `d2m`, `forma_printing`, `employee_tax_records`) — Nepali BS fiscal-year-based reporting is a core cross-cutting concern
- Several `v_*` views exist purely for dashboard/report convenience (`v_dashboard_subject_production`, `v_employee_attendance_stats`, etc.)
- `deno_staging`, `deno_test` — look like scratch/dev tables sitting in production, not part of any FK graph

## Standardization observations (preliminary)

- **Dead file sprawl**: every module folder audited so far (`book/`, `jobticket/`) has 2-5 orphaned `create1.php`/`index1.php`/`-Copy.php` style files left over from iteration, none linked from the menu. Worth a repo-wide sweep to delete or archive these — they're confusing for anyone new to the codebase and this exact pattern almost certainly repeats in other modules.
- **The header/footer include pattern is fragile by design**: `includes/header.php` and `includes/footer.php` are `require_once`'d directly (not wrapped in functions), so they run in and pollute the including page's variable scope. This is what caused bugs #4, #5, and #6 above. A longer-term fix would scope these into functions/classes, but that's a larger refactor than a bug-fix pass — flagging for the standardization discussion rather than doing it now.
- **Inconsistent redirect pattern**: some POST handlers use `header('Location: ...')` (correct HTTP redirect, but only safe before any output), others use `echo "<script>window.location.href='...'</script>"` (works regardless of output position, but is a JS-dependent hack). Standardizing on one pattern — with the redirect-capable handlers always running before `header.php` — would prevent bug #4 from recurring.
- **CTP/imposition schema duplication** (`fctp_*` vs `forma`/`imposition_templates`) needs a decision on which is authoritative.

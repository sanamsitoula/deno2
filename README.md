# JEMC Press Management System (deno2)

**Janak Education Materials Center (JEMC)** — A full-stack ERP for a press production and printing company in Nepal. Manages production, HR, payroll, attendance (ZKTeco biometric), vehicle fleet, job orders, and reconciliation.

---

## 🏗️ Technology Stack

| Layer | Technology |
|---|---|
| Backend | PHP 7.4+ |
| Database | PostgreSQL 15/17 |
| Frontend | HTML5, Bootstrap 5, Chart.js, JavaScript |
| Server | Apache / XAMPP (port 81) |
| Calendar | Bikram Sambat (BS) + Gregorian |
| PDF | TCPDF, FPDI, Poppler-PHP (Composer) |
| Autoloader | Composer PSR-4 (`Administrator\Deno2\`) |

---

## 🚀 Quick Start

### Prerequisites
- XAMPP (PHP 7.4+, Apache on port 81)
- PostgreSQL 15 or 17
- Composer

### Step 1 — Enable PostgreSQL driver in XAMPP

Edit `C:\xampp\php\php.ini` — uncomment these two lines:
```ini
extension=pdo_pgsql
extension=pgsql
```
Then **restart Apache** in XAMPP Control Panel.

### Step 2 — Install PHP dependencies
```bash
cd D:\claude_project\deno2
composer install
```

### Step 3 — Restore database
```bash
# Windows (XAMPP)
"C:\Program Files\PostgreSQL\17\bin\psql.exe" -U postgres -c "CREATE DATABASE press_jemc;"
"C:\Program Files\PostgreSQL\17\bin\psql.exe" -U postgres -d press_jemc -f sql/20260603_backup.sql
```

### Step 4 — Run migrations (in order)
```bash
SET PGPASSWORD=Nepal@123
psql -U postgres -d press_jemc -f sql/migrations/001_employee_payroll_columns.sql
psql -U postgres -d press_jemc -f sql/migrations/002_payroll_tax_tables.sql
psql -U postgres -d press_jemc -f sql/migrations/002b_create_payroll_tables.sql
```

### Step 5 — Open the app
```
http://localhost:81/deno2/
```

---

## 📦 Modules

### 1. Dashboard (`index.php`)
Unified management dashboard combining all modules:
- Production KPIs (today / month / all-time Deno quantities)
- HR & Attendance (live headcount, present/absent today)
- 14-day production trend chart + 7-day attendance chart
- ZKTeco device status, Department headcount, D2M pipeline
- Job ticket progress, Quick reports, Quick actions

### 2. Production — Deno (`/entries/`, `/denoreports/`)
Daily press production tracking by book, month, fiscal year.
- Entry forms: `entries/deno.php`
- Reports: daily, monthly, books, translated, trend, process control

### 3. D2M Verification (`/d2m/`)
4-stage approval workflow: **DRAFT → CHECKED → VERIFIED → CLOSED**

### 4. Job Orders (`/jobticket/`)
Job ticket lifecycle: Pending → In Progress → Completed.
Tracks book, print qty, done qty, machine assignment.

### 5. HR & Employee (`/hr/`)
- Employee directory with photo, departments, designations, levels
- Multi-step employee creation with document uploads
- Leave management, education records, family details
- Role-based access (admin / hr)

### 6. Attendance (`/attendance/`, `/attendance_device/`)
- Manual attendance marking
- ZKTeco biometric integration — 5 auto-pulls daily
- OT calculation, shift support (8-hour + 24-hour duty)

### 7. Payroll (`/hr/modules/payroll/`)
- `PayrollService::generatePayroll()` — per-employee loop
- Attendance-based paid days, OT, proportional basic
- Statutory deductions: SSF, PF, Income Tax (TDS)
- TCPDF payslips (single + bulk PDF)
- Nepal tax slabs FY 2081/82 seeded in DB

### 8. Tax Calculation (`src/Tax/`)
Nepal-specific statutory calculations:
| Component | Rate | Applies To |
|---|---|---|
| SSF Employee | 11% of basic | SSF-enrolled employees |
| SSF Employer | 20% of basic | SSF-enrolled employees |
| PF Employee | 10% of basic | PERMANENT employees |
| PF Employer | 10% of basic | PERMANENT employees |
| Income Tax | Slab-based (1%–36%) | All, via monthly TDS |

### 9. Vehicle Fleet (`/vehicle/`)
- Vehicle registration, driver assignments
- Daily logs, fuel coupons, maintenance records
- Monthly summaries, Nepali-date reports

### 10. Book & Forma Management (`/book/`, `/forma/`, `/formaprinting/`, `/ctp/`)
Books catalog, CTP jobs, Forma printing, FCTP workflow, Pack & Stitch.

### 11. Reports (`/reports.php`, `/report/`, `/denoreports/`)
- Daily / Monthly / Books / Translated production
- Job Ticket vs Printed, Process Control, Trend
- Stock Reconciliation (marketing, software, stockkeeper)
- All reports linked from the dashboard

---

## 🗂️ Project Structure

```
deno2/
├── src/                        ← PSR-4 autoloaded business logic
│   ├── Core/
│   │   ├── Database.php        ← PDO singleton
│   │   ├── Auth.php            ← RBAC with module permission matrix
│   │   ├── Response.php        ← JSON API response builder
│   │   ├── Logger.php          ← File logger → logs/{module}/YYYY-MM-DD.log
│   │   └── Validator.php       ← Chainable validator (incl. nepaliDate)
│   ├── Shared/
│   │   └── DateConverter.php   ← BS ↔ AD date conversion (2000–2090 BS)
│   ├── HR/
│   │   ├── EmployeeRepository.php
│   │   ├── EmployeeService.php
│   │   └── DepartmentRepository.php
│   ├── Attendance/
│   │   ├── AttendanceRepository.php
│   │   └── ZKTecoService.php
│   ├── Tax/
│   │   ├── SSFCalculator.php
│   │   ├── PFCalculator.php
│   │   ├── IncomeTaxCalculator.php
│   │   └── TaxService.php
│   ├── Payroll/
│   │   ├── PayrollRepository.php
│   │   ├── PayrollService.php
│   │   └── PayslipGenerator.php
│   └── Vehicle/
│       ├── VehicleRepository.php
│       └── FuelService.php
│
├── api/v1/                     ← Versioned REST endpoints
│   ├── _middleware.php         ← Shared bootstrap (auth, DB, CORS)
│   ├── employees.php           ← GET list/single, DELETE
│   ├── attendance.php          ← GET records/summary, POST upsert/ZKTeco pull
│   └── payroll.php             ← GET list/detail/PDF, POST generate run
│
├── config/
│   ├── bootstrap.php           ← Single include for all pages (NEW)
│   ├── database.php            ← PDO connection ($conn)
│   ├── auth.php                ← Session auth helpers (delegates to Auth class)
│   └── config.php             ← BASE_URL, BASE_PATH
│
├── sql/migrations/
│   ├── 001_employee_payroll_columns.sql   ← is_ssf_enrolled, taxpayer_type
│   ├── 002_payroll_tax_tables.sql         ← tax_slabs, statutory_rates, salary_components, etc.
│   └── 002b_create_payroll_tables.sql     ← payroll_processing, payroll_details
│
├── attendance_device/          ← ZKTeco biometric integration
│   ├── zkteco_puller.php       ← Main puller (CLI + Web, portable paths)
│   ├── ZKLibrary.php           ← Device SDK wrapper
│   ├── pull_*.bat              ← Windows Task Scheduler scripts
│   └── logs/zkteco/            ← Pull logs
│
├── hr/                         ← HR module pages
│   ├── employee/               ← Employee CRUD
│   └── modules/
│       ├── attendance/mark.php
│       ├── leaves/apply.php
│       └── payroll/process.php
│
├── includes/
│   ├── header.php              ← Nav with role-based module visibility
│   └── footer.php
│
├── vehicle/                    ← Fleet management pages
├── jobticket/                  ← Job order management
├── d2m/                        ← D2M verification workflow
├── entries/                    ← Deno production entry forms
├── denoreports/                ← 16 production report files
├── report/                     ← 12 reconciliation/trend report files
├── logs/                       ← Application logs (gitignored)
├── vendor/                     ← Composer packages (gitignored)
├── index.php                   ← 🏠 Management Dashboard (HOME)
├── dashboard.php               ← Alternate full dashboard
├── login.php / logout.php
└── composer.json
```

---

## 🗄️ Database

**Name**: `press_jemc` | **Host**: `localhost:5432` | **User**: `postgres`

### Tables (86 total after migrations)

| Category | Tables |
|---|---|
| **Core HR** | `employee`, `department`, `designation`, `level`, `shifts` |
| **Payroll** | `payroll_processing`, `payroll_details`, `employee_salary`, `salary_components` |
| **Tax** | `tax_slabs`, `statutory_rates`, `employee_tax_records` |
| **Attendance** | `attendance`, `attendance_monthly_summary`, `attendance_status` |
| **ZKTeco** | `zkteco_devices`, `zkteco_user_mapping`, `zkteco_raw_attendance`, `zkteco_pull_log` |
| **Production** | `deno`, `d2m`, `d2m_items`, `job_ticket`, `forma`, `forma_printing` |
| **Books** | `books`, `fctp_job_tickets`, `fctp_books`, `fctp_formas` |
| **Vehicle** | `vehicles`, `vehicle_daily_logs`, `vehicle_maintenance_records`, `fuel_coupons` |
| **Finance** | `recon_brt`, `recon_marketing`, `recon_software`, `recon_stockkeeper` |
| **Config** | `fiscal_years`, `holidays`, `system_settings`, `ot_rules`, `users` |

### Backup & Restore
```bash
# Backup
PGPASSWORD=Nepal@123 pg_dump -U postgres -d press_jemc > backup_$(date +%Y%m%d).sql

# Restore
PGPASSWORD=Nepal@123 psql -U postgres -d press_jemc < sql/20260603_backup.sql
```

---

## 🔐 Authentication & RBAC

Roles and module access are controlled by `src/Core/Auth.php`:

| Role | Modules |
|---|---|
| `admin` | All modules |
| `hr` | HR, Employee, Attendance |
| `finance` | Payroll, Tax |
| `production_manager` | Production, Job Tickets, Reports |
| `fleet_manager` | Vehicle |
| `supervisor` | Attendance, Job Tickets |

Nav items in `includes/header.php` auto-hide based on `can_access_module($slug)`.

---

## 🤖 ZKTeco Auto-Pull Schedule

Pulls run via Windows Task Scheduler 5× daily:

| Time | Schedule | Batch File |
|---|---|---|
| 07:35 | morning | `pull_morning.bat` |
| 10:45 | midmorning | `pull_midmorning.bat` |
| 13:25 | afternoon | `pull_afternoon.bat` |
| 17:25 | evening | `pull_evening.bat` |
| 19:15 | night | `pull_night.bat` |

**Manual pull:**
```bash
php attendance_device/zkteco_puller.php --schedule=morning
```

**Logs:** `attendance_device/logs/zkteco/YYYY-MM-DD.log`

---

## 🌐 REST API (v1)

Base: `http://localhost:81/deno2/api/v1/`

| Endpoint | Methods | Module |
|---|---|---|
| `employees.php` | GET (list/single), DELETE | HR |
| `attendance.php` | GET (records/summary), POST (upsert/pull) | Attendance |
| `payroll.php` | GET (list/detail/PDF), POST (generate) | Payroll |

All responses: `{ "success": true/false, "message": "...", "data": {...} }`

---

## 🔧 Troubleshooting

### "Connection failed: could not find driver"
PostgreSQL PHP extension not enabled. Edit `C:\xampp\php\php.ini`:
```ini
extension=pdo_pgsql   ← remove the leading semicolon
extension=pgsql       ← remove the leading semicolon
```
Restart Apache.

### ZKTeco device not connecting
- Check logs: `attendance_device/logs/zkteco/`
- Verify device IP is reachable: `ping <device_ip>`
- Check employee-device mapping: `SELECT * FROM zkteco_user_mapping LIMIT 5;`

### PHP memory / timeout
Edit `C:\xampp\php\php.ini`:
```ini
memory_limit = 512M
max_execution_time = 300
```

---

## 📊 Key Reports

| Report | Path |
|---|---|
| Daily Production | `/denoreports/daily.php` |
| Monthly Production | `/denoreports/monthly.php` |
| Books Report | `/denoreports/books.php` |
| Job Ticket vs Printed | `/denoreports/jobticket_fp.php` |
| Process Control | `/report/production_process_control.php` |
| Trend Analysis | `/report/trend.php` |
| Stock Reconciliation | `/report/index.php` |
| All Reports Hub | `/reports.php` |

---

## 📅 Nepal Tax Slabs (FY 2081/82)

> Verify with IRD each fiscal year and update `tax_slabs` table.

| Taxable Income (NPR) | Single | Couple |
|---|---|---|
| 0 – 5,00,000 | 1% | — |
| 0 – 6,00,000 | — | 1% |
| 5/6,00,001 – 7,00,000 | 10% | 10% |
| 7,00,001 – 10,00,000 | 20% | 20% |
| 10,00,001 – 20,00,000 | 30% | 30% |
| Above 20,00,000 | 36% | 36% |

---

## ✅ System Checklist

- [x] PHP extensions `pdo_pgsql` + `pgsql` enabled in XAMPP
- [x] Composer autoloader active (`Administrator\Deno2\` → `src/`)
- [x] `config/bootstrap.php` — single include entry point
- [x] `src/Core/Auth` — module-based RBAC
- [x] `src/HR/` — EmployeeRepository, EmployeeService, DepartmentRepository
- [x] `src/Attendance/` — AttendanceRepository, ZKTecoService
- [x] `src/Tax/` — SSF, PF, IncomeTax calculators + TaxService
- [x] `src/Payroll/` — PayrollRepository, PayrollService, PayslipGenerator
- [x] `src/Vehicle/` — VehicleRepository, FuelService
- [x] `api/v1/` — employees, attendance, payroll endpoints
- [x] DB migrations 001, 002, 002b applied to `press_jemc`
- [x] Nepal FY 2081/82 tax slabs seeded
- [x] `index.php` — Full management dashboard with charts
- [x] Nav bug fixed (HR links no longer point to Vehicle URLs)
- [x] ZKTeco hardcoded `C:/xampp` paths removed
- [ ] Windows Task Scheduler pull tasks configured
- [ ] Employee SSF enrollment flags updated
- [ ] Employee salary records populated (`employee_salary` table)

---

**Last Updated**: June 2026 &nbsp;·&nbsp; **DB Backup**: `sql/20260603_backup.sql` &nbsp;·&nbsp; **Tables**: 86+ &nbsp;·&nbsp; **Status**: ✅ Active Development

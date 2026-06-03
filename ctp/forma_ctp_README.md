# forma_ctp Module — Installation & Usage Guide

## Overview

A fully independent CTP (Computer-to-Plate) prepress management module for PHP/PostgreSQL systems. Manages books, job tickets, formas, imposition layouts, and generates CTP plate PDFs.

---

## Files

| File | Purpose |
|---|---|
| `forma_ctp_schema.sql` | PostgreSQL schema — run once to create all tables |
| `forma_ctp.php` | Main dashboard — list all job tickets |
| `forma_ctp_book.php` | Book registry — upload master PDFs |
| `forma_ctp_job_create.php` | Create/edit job ticket + forma rows |
| `forma_ctp_job_view.php` | View job ticket, all formas, generate links |
| `forma_ctp_imposition.php` | **Imposition Editor** — assign pages to slots, rotation, blanks |
| `forma_ctp_generate.php` | Generate CTP plate PDF using FPDI |
| `forma_ctp_templates.php` | Manage reusable imposition templates |

---

## Installation

### 1. Database
```sql
-- Run the schema file in your PostgreSQL database:
\i forma_ctp_schema.sql
-- OR click "🗄️ Init DB" button in the UI
```

### 2. PHP Dependencies (FPDI/FPDF)
```bash
composer require setasign/fpdi
composer require setasign/fpdf
```
Or manually install to `/lib/fpdf/` and `/lib/fpdi/`.

### 3. Upload Directories
```bash
mkdir -p /var/www/html/deno2/uploads/forma_pdfs/
chmod 755 /var/www/html/deno2/uploads/forma_pdfs/
```

### 4. Place Files
Copy all PHP files to your `/deno2/` or relevant directory.

---

## Workflow

```
1. Add Book (forma_ctp_book.php)
   → Enter book_code, book_name, class
   → Upload master PDF (full book)

2. Create Job Ticket (forma_ctp_job_create.php)
   → Select book
   → Enter job ticket code, FY, lot, print qty
   → Add forma rows (T-28, 29-44, COVER etc.)
   → Set page range for each forma
   → Configure layout (8-up, 4-up etc.)
   → Set margins: bleed, gutter, gripper, trim, head, foot, spine, cut

3. Configure Imposition (forma_ctp_imposition.php)
   → Auto-calculated from page range + layout
   → Visual grid: each slot shows book page number
   → Toggle 180° rotation per slot (head-to-head)
   → Mark slots as BLANK
   → Manual override any slot
   → Save imposition

4. Generate PDF (forma_ctp_generate.php)
   → Extracts pages from master PDF
   → Imposes them onto plate with correct rotation
   → Adds cut marks, gutter lines, plate labels
   → Saves to /uploads/forma_pdfs/{book_code}/output/
```

---

## Imposition Logic

### 8-Up Booklet (standard body forma, 32pp signature)

For a 32-page forma:
- 4 plates total (32pp ÷ 8pp per plate)
- Each plate: FRONT side (4 pages) + BACK side (4 pages)
- Layout: 4 columns × 2 rows per side
- **Row 1** = rotated 180° (head-to-head imposition)
- **Row 2** = normal orientation

### Page numbering in slots (auto-calculated)
The system fills slots sequentially from `page_start` to `page_end`, padding with BLANK if needed. The CTP professional can then manually rearrange using the imposition editor.

### For standard booklet imposition formula:
- Plate 1 Front: pages in order from the start
- The system auto-fills; if you need precise saddle-stitch imposition (p32+p1, p2+p31...), use the manual override in the imposition editor.

---

## Database Tables

| Table | Purpose |
|---|---|
| `fctp_books` | Book registry + master PDF path |
| `fctp_job_tickets` | Job tickets (linked to books) |
| `fctp_formas` | Forma definitions per job ticket |
| `fctp_imposition_templates` | Reusable layout templates |
| `fctp_uploads` | File upload log |

### Views
- `v_fctp_forma_summary` — Joined forma details
- `v_fctp_job_summary` — Job ticket summary with counts

---

## Imposition JSON Structure

Stored in `fctp_formas.imposition_json`:

```json
{
  "plates": [
    {
      "plate_no": 1,
      "sides": {
        "front": [
          {
            "slot": 1, "col": 1, "row": 1,
            "book_page": 32,
            "rotation": 180,
            "is_blank": false,
            "label": "32"
          },
          {
            "slot": 2, "col": 2, "row": 1,
            "book_page": 1,
            "rotation": 180,
            "is_blank": false,
            "label": "1"
          }
        ],
        "back": [...]
      }
    }
  ]
}
```

---

## Margin Reference (all in mm)

| Margin | Default | Description |
|---|---|---|
| `bleed` | 3mm | Image bleed beyond trim |
| `gutter` | 5mm | Space between columns (fold area) |
| `trim_outer` | 8mm | Outer trim margin |
| `gripper` | 10mm | Press gripper edge (top) |
| `head_margin` | 8mm | Space between rows |
| `foot_margin` | 8mm | Bottom margin |
| `spine_margin` | 5mm | Spine/binding edge |
| `cutting_margin` | 3mm | Offset for cut marks |

---

## FPDI Notes

- FPDI Pro is required for PDF 1.5+ (most modern PDFs)
- Free FPDI works for PDF 1.4 and below
- Install: `composer require setasign/fpdi`
- For encrypted PDFs, you must first decrypt them (use Ghostscript or qpdf)

```bash
# Decrypt PDF with Ghostscript:
gs -dBATCH -dNOPAUSE -sDEVICE=pdfwrite -sOutputFile=output.pdf -c .setpdfwrite -f input.pdf
```

---

## Example Forma Configuration (NEPALI-9 T-28)

```
Forma Name: T-28
Type: Body
Page Start: 1
Page End: 32
Page Count: 32
Layout: 8-Up (4×2)
Plates Required: 4  (32 ÷ 8 = 4)
Plate Size: 720×508mm
Gripper: 10mm
Gutter: 5mm
Bleed: 3mm
```

Each plate generates:
- **Plate 1 FRONT** — 4 slots (row1=rotated, row2=normal)
- **Plate 1 BACK** — 4 slots
- **Plate 2 FRONT**, etc.

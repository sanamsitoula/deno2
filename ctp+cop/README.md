# 🖨️ CTP Export Module — Print Ready Copy Generator

## Overview

This module integrates with your **deno2** system to produce professional **Print Ready Copy (PRC) PDFs** for CTP (Computer-to-Plate) output. It handles imposition, page rearrangement, margins, crop marks, and registration marks automatically.

---

## Files

| File | Purpose |
|------|---------|
| `ctp_export.php` | Main UI — create jobs, view page orders, margin guide |
| `ctp_generate_prc.php` | FPDI-based PDF generator (web or CLI) |
| `ctp_export_download.php` | Download page order as CSV / JSON |
| `ctp_schema.sql` | PostgreSQL table definitions |

---

## Installation

### 1. Database
```bash
psql -U your_user -d your_db -f ctp_schema.sql
```

### 2. PHP Dependencies
```bash
cd /your/project/root
composer require setasign/fpdi tecnickcom/tcpdf
```

### 3. Place files
Copy all PHP files to: `/deno2/ctp/`

---

## Dynamic Margin Settings

All margins are configurable per job. Industry-standard defaults:

| Margin | Default | Purpose |
|--------|---------|---------|
| **Bleed** | **3 mm** | Artwork extends beyond trim; prevents white edge after cutting |
| **Gutter** | **5 mm** | Space between pages on sheet; hidden in spine binding |
| **Trim / Outer** | **8 mm** | Clearance for crop mark lines outside the bleed area |
| **Gripper** | **10 mm** | Press clamp zone — **never print here** |
| **Head Margin** | **8 mm** | Top edge; holds registration marks |
| **Foot Margin** | **8 mm** | Bottom edge; holds CMYK colour bars |

### Minimum values for high-speed presses:
- Bleed: ≥ 2 mm (3 mm recommended)
- Gutter: ≥ 3 mm (5 mm for perfect binding, more for saddle stitch)
- Gripper: ≥ 8 mm (10-12 mm for sheetfed presses)

---

## Supported Imposition Layouts

| Layout | Pages/Side | Signature | Use Case |
|--------|-----------|-----------|---------|
| **8-Up (4×2)** | 8 | 16 pp/sheet | Standard textbook signature |
| **4-Up (2×2)** | 4 | 8 pp/sheet | Smaller books, brochures |
| **2-Up (2×1)** | 2 | 4 pp/sheet | Simple booklets |
| **16-Up (4×4)** | 16 | 32 pp/sheet | Large format, newspapers |
| **Custom** | User-defined | Variable | Any layout |

---

## Booklet Page Order (8-Up, 32 pages example)

```
Sheet 1 — Front:  [32|1]  [30|3]  [2|31]  [4|29]
Sheet 1 — Back:   [6|27]  [8|25]  [26|7]  [24|9]
Sheet 2 — Front:  [22|11] [20|13] [10|23] [12|21]
Sheet 2 — Back:   [14|19] [16|17] [18|15] ...
```

Blank pages are automatically inserted when total pages are not divisible by signature size.

---

## CLI Usage (batch processing)

```bash
# Generate PRC PDF from command line
php ctp_generate_prc.php --job_id=5
```

---

## Sheet Size Calculation

For **B5 book (180×254 mm)** on **720×508 mm sheet** (8-up, 4×2):

```
Usable Width  = 720 - 10(gripper) - 2×3(bleed) - 3×5(gutters) = 689 mm
Usable Height = 508 - 2×3(bleed) - 8(head) - 8(foot) - 1×5(gutter) = 473 mm

Per-cell Width  = 689 / 4 = 172.25 mm  (vs 180 mm finished — bleed adds the diff)
Per-cell Height = 473 / 2 = 236.5 mm   (vs 254 mm finished)
```

---

## Output Marks Included

- ✅ Crop / Trim marks (4 corners per page cell)
- ✅ Registration marks (4 corners of sheet)  
- ✅ CMYK colour bar (foot margin)
- ✅ Sheet identifier label (job name, sheet #, side)
- ✅ Blank page indicators for easy plate verification

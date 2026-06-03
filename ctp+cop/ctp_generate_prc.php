<?php
/**
 * ╔══════════════════════════════════════════════════════════════════════════╗
 * ║         CTP PRC PDF GENERATOR — FPDI-based Imposition Engine            ║
 * ║   Rearranges pages from original PDF into press-ready (PRC) layout      ║
 * ╚══════════════════════════════════════════════════════════════════════════╝
 *
 * Usage:  php ctp_generate_prc.php --job_id=5
 *     or: call via web: ctp_generate_prc.php?job_id=5
 *
 * Requires: composer require setasign/fpdi tecnickcom/tcpdf
 *
 * Output: PRC PDF saved to /uploads/prc/prc_<job_id>_<timestamp>.pdf
 */

// ─── CLI or Web detection ────────────────────────────────────────────────────
$isCLI = (php_sapi_name() === 'cli');

if (!$isCLI) {
    ob_start();
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
    redirect_if_not_logged_in();
    $job_id = (int)($_GET['job_id'] ?? $_POST['job_id'] ?? 0);
    // ── Nav bar (shown before PDF generation output) ──────────────────────
    if (!$job_id) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
        echo '
        <style>.ctp-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 auto 20px;max-width:1200px;background:#fff;padding:14px 18px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);align-items:center;}.ctp-nav-title{font-weight:800;font-size:15px;color:#1e293b;margin-right:10px;}.nav-link{padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;color:#64748b;border:1.5px solid #e2e8f0;transition:all .2s;}.nav-link:hover,.nav-link.active{background:#1a73e8;color:#fff;border-color:#1a73e8;}</style>
        <div class="ctp-nav" style="max-width:1200px;margin:20px auto;">
            <span class="ctp-nav-title">🖨️ CTP Module</span>
            <a href="ctp_export.php" class="nav-link">📋 Export Jobs</a>
            <a href="ctp_export.php?new=1" class="nav-link">➕ New Job</a>
            <a href="ctp_generate_prc.php" class="nav-link active">⚙️ Generate PRC PDF</a>
            <a href="ctp_export_download.php" class="nav-link">⬇️ Download</a>
            <a href="ctp_export.php?tab=margins" class="nav-link">📐 Margin Guide</a>
        </div>
        <div style="max-width:600px;margin:40px auto;padding:28px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.1);font-family:Segoe UI,sans-serif;text-align:center;">
            <div style="font-size:40px;margin-bottom:12px;">⚙️</div>
            <h2 style="color:#1e293b;margin:0 0 14px;">Generate PRC PDF</h2>
            <p style="color:#555;margin-bottom:20px;">Provide a Job ID to generate the press-ready imposition PDF for that job.</p>
            <p><a href="ctp_export.php" style="padding:10px 24px;background:#1a73e8;color:#fff;border-radius:7px;text-decoration:none;font-weight:700;">← View All Jobs</a></p>
        </div>';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';
        exit;
    }
} else {
    // CLI: parse --job_id=N
    parse_str(implode('&', array_slice($_SERVER['argv'], 1)), $args);
    $job_id = (int)($args['job_id'] ?? $args['-job_id'] ?? 0);
    // Bootstrap DB manually for CLI
    require_once dirname($_SERVER['DOCUMENT_ROOT']) . '/deno2/config/database.php';
}

if (!$job_id) {
    die('ERROR: job_id is required.');
}

// ─── Load job from DB ─────────────────────────────────────────────────────────
$stmt = $conn->prepare("SELECT * FROM ctp_export_jobs WHERE id = :id");
$stmt->execute([':id' => $job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job) {
    die("ERROR: CTP job #{$job_id} not found.");
}

// ─── FPDI / TCPDF Autoload ────────────────────────────────────────────────────
// Vendor is at: C:\xampp\htdocs\deno2\vendor\autoload.php
$composer_autoload = $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';

// Fallback: if file lives inside deno2/ctp/ folder, go two levels up
if (!file_exists($composer_autoload)) {
    $composer_autoload = dirname(__DIR__) . '/vendor/autoload.php';
}
if (!file_exists($composer_autoload)) {
    // Show a helpful styled error page instead of a blank die()
    if (!$isCLI) {
        require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
        echo '
        <style>
            .ctp-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 auto 20px;max-width:1200px;background:#fff;padding:14px 18px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);align-items:center;}
            .ctp-nav-title{font-weight:800;font-size:15px;color:#1e293b;margin-right:10px;}
            .nav-link{padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;color:#64748b;border:1.5px solid #e2e8f0;transition:all .2s;}
            .nav-link:hover,.nav-link.active{background:#1a73e8;color:#fff;border-color:#1a73e8;}
        </style>
        <div class="ctp-nav" style="max-width:1200px;margin:20px auto;">
            <span class="ctp-nav-title">🖨️ CTP Module</span>
            <a href="ctp_export.php" class="nav-link">📋 Export Jobs</a>
            <a href="ctp_export.php?new=1" class="nav-link">➕ New Job</a>
            <a href="ctp_generate_prc.php" class="nav-link active">⚙️ Generate PRC PDF</a>
            <a href="ctp_export_download.php" class="nav-link">⬇️ Download</a>
            <a href="ctp_export.php?tab=margins" class="nav-link">📐 Margin Guide</a>
        </div>
        <div style="max-width:700px;margin:40px auto;padding:32px;background:#fff;border-radius:12px;box-shadow:0 2px 16px rgba(0,0,0,.1);font-family:Segoe UI,sans-serif;">
            <div style="font-size:48px;text-align:center;margin-bottom:16px;">⚠️</div>
            <h2 style="color:#dc3545;margin:0 0 16px;text-align:center;">Composer Autoload Not Found</h2>
            <p style="color:#555;margin-bottom:20px;">The <strong>FPDI + TCPDF</strong> libraries are required to generate the press-ready PDF. They have not been installed yet.</p>
            <div style="background:#fff3cd;border:1px solid #ffd96a;border-radius:8px;padding:16px;margin-bottom:20px;">
                <strong>📋 Step 1 — Install Composer (if not already)</strong><br>
                Download from <a href="https://getcomposer.org/download/" target="_blank">getcomposer.org</a> and run the installer.
            </div>
            <div style="background:#d1ecf1;border:1px solid #bee5eb;border-radius:8px;padding:16px;margin-bottom:20px;">
                <strong>📋 Step 2 — Open Command Prompt and navigate to your project</strong><br>
                <code style="background:#e9ecef;padding:4px 8px;border-radius:4px;display:block;margin-top:8px;">cd C:\xampp\htdocs\deno2</code>
            </div>
            <div style="background:#d4edda;border:1px solid #c3e6cb;border-radius:8px;padding:16px;margin-bottom:20px;">
                <strong>📋 Step 3 — Run this command</strong><br>
                <code style="background:#e9ecef;padding:4px 8px;border-radius:4px;display:block;margin-top:8px;word-break:break-all;">composer require setasign/fpdi tecnickcom/tcpdf</code>
            </div>
            <div style="background:#f8f9fa;border:1px solid #dee2e6;border-radius:8px;padding:14px;font-size:13px;color:#555;">
                ℹ️ After installation, <code>C:\xampp\htdocs\deno2\vendor\autoload.php</code> will exist and this page will work.
            </div>
            <div style="text-align:center;margin-top:24px;">
                <a href="ctp_export.php" style="padding:10px 24px;background:#1a73e8;color:#fff;border-radius:7px;text-decoration:none;font-weight:700;">← Back to CTP Export</a>
            </div>
        </div>';
        require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';
        exit;
    } else {
        die("ERROR: Composer autoload not found.\nRun: composer require setasign/fpdi tecnickcom/tcpdf\nIn: C:\\xampp\\htdocs\\deno2\\\n");
    }
}
require_once $composer_autoload;

use setasign\Fpdi\Tcpdf\Fpdi;

// ─── Configuration from job record ───────────────────────────────────────────
$original_pdf    = $job['original_pdf'];
$total_pages     = (int)$job['total_pages'];
$padded_pages    = (int)$job['padded_pages'];
$blank_inserted  = (int)$job['blank_inserted'];
$cols            = (int)$job['cols'];
$rows            = (int)$job['rows'];
$sheet_width_mm  = (float)$job['sheet_width'];    // e.g. 720mm
$sheet_height_mm = (float)$job['sheet_height'];   // e.g. 508mm
$bleed_mm        = (float)$job['bleed'];           // 3mm
$gutter_mm       = (float)$job['gutter'];          // 5mm
$trim_outer_mm   = (float)$job['trim_outer'];      // 8mm
$gripper_mm      = (float)$job['gripper'];         // 10mm
$head_mm         = (float)$job['head_margin'];     // 8mm
$foot_mm         = (float)$job['foot_margin'];     // 8mm

// MM → points (FPDI/TCPDF works in mm natively)
// Sheet in mm is directly used.

// ─── Page order from JSON ─────────────────────────────────────────────────────
$page_order = json_decode($job['page_order_json'], true);
if (!$page_order) {
    die("ERROR: Invalid page order JSON in job record.");
}

// ─── Output path ─────────────────────────────────────────────────────────────
$output_dir = $_SERVER['DOCUMENT_ROOT'] . '/deno2/uploads/prc/';
if (!is_dir($output_dir)) {
    mkdir($output_dir, 0755, true);
}
$output_filename = "prc_{$job_id}_" . date('YmdHis') . ".pdf";
$output_path     = $output_dir . $output_filename;

// ─── Usable page cell dimensions (mm) ────────────────────────────────────────
// Total gutters
$total_gutter_w  = $gutter_mm * ($cols - 1);
$total_gutter_h  = $gutter_mm * ($rows - 1);
// Printable zone (after gripper and bleed on sheet)
$printable_w     = $sheet_width_mm  - $gripper_mm - 2 * $bleed_mm;
$printable_h     = $sheet_height_mm - 2 * $bleed_mm - $head_mm - $foot_mm;
// Each page cell size on sheet
$cell_w          = ($printable_w - $total_gutter_w) / $cols;
$cell_h          = ($printable_h - $total_gutter_h) / $rows;

// ─── FPDI PDF object ─────────────────────────────────────────────────────────
$pdf = new Fpdi('L', 'mm', [$sheet_width_mm, $sheet_height_mm]);
$pdf->setPrintHeader(false);
$pdf->setPrintFooter(false);
$pdf->SetMargins(0, 0, 0);
$pdf->SetAutoPageBreak(false);

// Load source PDF page count
// ─── Resolve path: convert web path → filesystem path if needed ──────────────
if (!empty($original_pdf) && !file_exists($original_pdf)) {
    $resolved = $_SERVER['DOCUMENT_ROOT'] . $original_pdf;
    if (file_exists($resolved)) {
        $original_pdf = $resolved;
    }
}
if (!file_exists($original_pdf)) {
    $conn->prepare("UPDATE ctp_export_jobs SET status='failed', error_msg=:e WHERE id=:id")
         ->execute([':e' => "PDF not found: {$original_pdf}", ':id' => $job_id]);
    die("ERROR: Original PDF not found: {$original_pdf}");
}
$source_page_count = $pdf->setSourceFile($original_pdf);

// ─── Helper: place one page on sheet ─────────────────────────────────────────
function placePage(Fpdi $pdf, int $pageNum, int $totalPages, string $origPdf,
                   float $x, float $y, float $w, float $h,
                   float $bleed): void
{
    if ($pageNum < 1 || $pageNum > $totalPages) {
        // Blank page — draw white rectangle
        $pdf->SetFillColor(255, 255, 255);
        $pdf->Rect($x - $bleed, $y - $bleed, $w + 2*$bleed, $h + 2*$bleed, 'F');
        // Optionally draw "BLANK" text for plate verification
        $pdf->SetTextColor(200, 200, 200);
        $pdf->SetFont('helvetica', '', 8);
        $pdf->SetXY($x + $w/2 - 10, $y + $h/2 - 3);
        $pdf->Cell(20, 6, 'BLANK', 0, 0, 'C');
        $pdf->SetTextColor(0, 0, 0);
        return;
    }

    // Import the source page
    $tpl = $pdf->importPage($pageNum);
    // Place it scaled to cell
    $pdf->useTemplate($tpl, $x, $y, $w, $h, true);
}

// ─── Helper: draw crop / trim marks ──────────────────────────────────────────
function drawCropMarks(Fpdi $pdf, float $x, float $y, float $w, float $h,
                       float $bleed, float $markLen = 5): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $markOff = $bleed;  // marks start at bleed edge

    // Top-left corner
    $pdf->Line($x - $markOff - $markLen, $y, $x - $markOff, $y);           // horizontal
    $pdf->Line($x, $y - $markOff - $markLen, $x, $y - $markOff);           // vertical
    // Top-right corner
    $pdf->Line($x + $w + $markOff, $y, $x + $w + $markOff + $markLen, $y);
    $pdf->Line($x + $w, $y - $markOff - $markLen, $x + $w, $y - $markOff);
    // Bottom-left corner
    $pdf->Line($x - $markOff - $markLen, $y + $h, $x - $markOff, $y + $h);
    $pdf->Line($x, $y + $h + $markOff, $x, $y + $h + $markOff + $markLen);
    // Bottom-right corner
    $pdf->Line($x + $w + $markOff, $y + $h, $x + $w + $markOff + $markLen, $y + $h);
    $pdf->Line($x + $w, $y + $h + $markOff, $x + $w, $y + $h + $markOff + $markLen);
}

// ─── Helper: draw registration mark (cross in circle) ─────────────────────────
function drawRegMark(Fpdi $pdf, float $cx, float $cy, float $r = 3): void
{
    $pdf->SetDrawColor(0, 0, 0);
    $pdf->SetLineWidth(0.25);
    $pdf->Circle($cx, $cy, $r, 0, 360, 'D');
    $pdf->Line($cx - $r, $cy, $cx + $r, $cy);
    $pdf->Line($cx, $cy - $r, $cx, $cy + $r);
}

// ─── Helper: draw CMYK colour bar ─────────────────────────────────────────────
function drawColourBar(Fpdi $pdf, float $x, float $y, float $totalW, float $barH = 4): void
{
    $bars = [
        [0,0,0,100,   'K'],
        [100,0,0,0,   'C'],
        [0,100,0,0,   'M'],
        [0,0,100,0,   'Y'],
        [100,100,0,0, 'CM'],
        [0,100,100,0, 'MY'],
        [100,0,100,0, 'CY'],
        [0,0,0,50,    '50K'],
    ];
    $bw = $totalW / count($bars);
    foreach ($bars as $i => [$c,$m,$yk,$k,$label]) {
        $pdf->SetFillColor(
            round(255*(1-$c/100)*(1-$k/100)),
            round(255*(1-$m/100)*(1-$k/100)),
            round(255*(1-$yk/100)*(1-$k/100))
        );
        $pdf->Rect($x + $i*$bw, $y, $bw, $barH, 'F');
    }
    $pdf->SetDrawColor(180,180,180);
    $pdf->SetLineWidth(0.1);
    $pdf->Rect($x, $y, $totalW, $barH, 'D');
}

// ─── MAIN LOOP: generate one PDF page per CTP sheet ─────────────────────────
foreach ($page_order as $sheetData) {
    $sheetIdx = (int)($sheetData['sheet_index'] ?? 1);

    // FRONT SIDE
    $pdf->AddPage('L', [$sheet_width_mm, $sheet_height_mm]);

    // Draw gripper zone label
    $pdf->SetFillColor(200, 220, 255);
    $pdf->Rect(0, 0, $gripper_mm, $sheet_height_mm, 'F');
    $pdf->SetFont('helvetica', 'B', 6);
    $pdf->SetTextColor(50, 50, 200);

    // Lay out pages on front side
    $frontPairs = $sheetData['front'] ?? [];
    $pairIdx = 0;
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $pair = $frontPairs[$pairIdx] ?? null;
            if (!$pair) { $pairIdx++; continue; }

            $cellX = $gripper_mm + $bleed_mm + $c * ($cell_w + $gutter_mm);
            $cellY = $bleed_mm + $head_mm + $r * ($cell_h + $gutter_mm);

            // Determine which page this cell gets
            // Pairs alternate: left_pg and right_pg fill adjacent cols
            $pageNum = ($c % 2 === 0) ? ($pair['right_pg'] ?? 0) : ($pair['left_pg'] ?? 0);

            placePage($pdf, $pageNum, $total_pages, $original_pdf,
                      $cellX, $cellY, $cell_w, $cell_h, $bleed_mm);
            drawCropMarks($pdf, $cellX, $cellY, $cell_w, $cell_h, $bleed_mm);

            if ($c % 2 === 1) { $pairIdx++; }
        }
    }

    // Sheet labels
    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY($gripper_mm + 2, $sheet_height_mm - 8);
    $pdf->Cell(80, 5, "Sheet {$sheetIdx} | FRONT | Job: {$job['job_name']} | Pages: {$total_pages}+{$blank_inserted}B", 0, 0, 'L');

    // Registration marks (4 corners)
    drawRegMark($pdf, $gripper_mm + $bleed_mm + 5, $bleed_mm + 5);
    drawRegMark($pdf, $sheet_width_mm - $bleed_mm - 5, $bleed_mm + 5);
    drawRegMark($pdf, $gripper_mm + $bleed_mm + 5, $sheet_height_mm - $bleed_mm - 5);
    drawRegMark($pdf, $sheet_width_mm - $bleed_mm - 5, $sheet_height_mm - $bleed_mm - 5);

    // CMYK colour bar at foot
    drawColourBar($pdf,
        $gripper_mm + $bleed_mm,
        $sheet_height_mm - $bleed_mm - $foot_mm / 2,
        $printable_w,
        3
    );

    // BACK SIDE
    $pdf->AddPage('L', [$sheet_width_mm, $sheet_height_mm]);

    $backPairs = $sheetData['back'] ?? [];
    $pairIdx = 0;
    for ($r = 0; $r < $rows; $r++) {
        for ($c = 0; $c < $cols; $c++) {
            $pair = $backPairs[$pairIdx] ?? null;
            if (!$pair) { $pairIdx++; continue; }

            $cellX = $gripper_mm + $bleed_mm + $c * ($cell_w + $gutter_mm);
            $cellY = $bleed_mm + $head_mm + $r * ($cell_h + $gutter_mm);

            $pageNum = ($c % 2 === 0) ? ($pair['left_pg'] ?? 0) : ($pair['right_pg'] ?? 0);

            placePage($pdf, $pageNum, $total_pages, $original_pdf,
                      $cellX, $cellY, $cell_w, $cell_h, $bleed_mm);
            drawCropMarks($pdf, $cellX, $cellY, $cell_w, $cell_h, $bleed_mm);

            if ($c % 2 === 1) { $pairIdx++; }
        }
    }

    $pdf->SetFont('helvetica', 'B', 7);
    $pdf->SetTextColor(80, 80, 80);
    $pdf->SetXY($gripper_mm + 2, $sheet_height_mm - 8);
    $pdf->Cell(80, 5, "Sheet {$sheetIdx} | BACK | Job: {$job['job_name']}", 0, 0, 'L');

    drawRegMark($pdf, $gripper_mm + $bleed_mm + 5, $bleed_mm + 5);
    drawRegMark($pdf, $sheet_width_mm - $bleed_mm - 5, $bleed_mm + 5);
    drawRegMark($pdf, $gripper_mm + $bleed_mm + 5, $sheet_height_mm - $bleed_mm - 5);
    drawRegMark($pdf, $sheet_width_mm - $bleed_mm - 5, $sheet_height_mm - $bleed_mm - 5);
    drawColourBar($pdf,
        $gripper_mm + $bleed_mm,
        $sheet_height_mm - $bleed_mm - $foot_mm / 2,
        $printable_w,
        3
    );
}

// ─── Output PDF ───────────────────────────────────────────────────────────────
$pdf->Output($output_path, 'F');

// Update DB
$conn->prepare("
    UPDATE ctp_export_jobs
       SET output_pdf = :path, status = 'complete', updated_at = CURRENT_TIMESTAMP
     WHERE id = :id
")->execute([':path' => $output_path, ':id' => $job_id]);

if ($isCLI) {
    echo "✅ PRC PDF generated: {$output_path}\n";
    echo "   Sheets: " . count($page_order) . " (× 2 sides)\n";
    echo "   File size: " . number_format(filesize($output_path) / 1024, 1) . " KB\n";
} else {
    // Trigger download
    header('Content-Type: application/pdf');
    header('Content-Disposition: attachment; filename="' . $output_filename . '"');
    header('Content-Length: ' . filesize($output_path));
    readfile($output_path);
    exit;
}

<?php
/**
 * forma_ctp_generate.php — Generate CTP Plate PDF
 * Uses FPDI to extract pages from master PDF and impose them onto plates
 *
 * REQUIREMENTS (run in /deno2/ folder):
 *   composer require setasign/fpdf setasign/fpdi
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

// ── Load FPDF first, then FPDI (order matters!) ───────────────
$autoload = $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';
if (!file_exists($autoload)) {
    die('<h2>❌ Composer autoload not found.</h2><p>Run: <code>cd ' . $_SERVER['DOCUMENT_ROOT'] . '/deno2 &amp;&amp; composer require setasign/fpdf setasign/fpdi</code></p>');
}
require_once $autoload;

// Explicitly require FPDF before FPDI to fix "Class FPDF not found"
if (!class_exists('FPDF')) {
    $fpdf_path = $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/setasign/fpdf/fpdf.php';
    if (!file_exists($fpdf_path)) {
        die('<h2>❌ FPDF not found.</h2><p>Run: <code>composer require setasign/fpdf</code> inside your deno2 folder.</p>');
    }
    require_once $fpdf_path;
}

use setasign\Fpdi\Fpdi;

$forma_id = (int)($_GET['id'] ?? 0);
if (!$forma_id) { header('Location: forma_ctp.php'); exit; }

// Load forma + job + book
$stmt = $conn->prepare("
    SELECT f.*, jt.job_ticket_code, b.book_name, b.book_code, b.master_pdf_path, b.master_pdf_pages
    FROM fctp_formas f
    JOIN fctp_job_tickets jt ON f.job_ticket_id = jt.id
    JOIN fctp_books b ON f.book_code = b.book_code
    WHERE f.id = :id
");
$stmt->execute([':id' => $forma_id]);
$forma = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$forma)                            die("Forma #{$forma_id} not found.");
if (empty($forma['imposition_json']))   die("Imposition not configured. Please set up imposition first.");
if (empty($forma['master_pdf_path']))   die("No master PDF uploaded for this book.");

$imposition = json_decode($forma['imposition_json'], true);
if (!$imposition || empty($imposition['plates'])) die("Invalid imposition data.");

$master_pdf_server = $_SERVER['DOCUMENT_ROOT'] . $forma['master_pdf_path'];
if (!file_exists($master_pdf_server)) die("Master PDF not found: {$master_pdf_server}");

// ── Layout parameters ─────────────────────────────────────────
$plate_w     = (float)$forma['plate_width'];
$plate_h     = (float)$forma['plate_height'];
$cols        = (int)$forma['cols'];
$rows        = (int)$forma['rows'];
$gutter      = (float)$forma['gutter'];
$trim_outer  = (float)$forma['trim_outer'];
$gripper     = (float)$forma['gripper'];
$head_margin = (float)$forma['head_margin'];
$foot_margin = (float)$forma['foot_margin'];
$cut_margin  = (float)$forma['cutting_margin'];

// ── Cell dimensions ───────────────────────────────────────────
$cell_w = ($plate_w - $trim_outer * 2 - $gutter * ($cols - 1)) / $cols;
$cell_h = ($plate_h - $gripper - $foot_margin - $head_margin * ($rows - 1)) / $rows;

// ── Helper: dashed line (FPDF has no native SetDash) ──────────
function drawDashedLine(Fpdi $pdf, float $x1, float $y1, float $x2, float $y2,
                         float $dash = 2.5, float $gap = 2.0): void {
    $dx = $x2 - $x1; $dy = $y2 - $y1;
    $len = sqrt($dx*$dx + $dy*$dy);
    if ($len == 0) return;
    $ux = $dx/$len; $uy = $dy/$len;
    $pos = 0; $draw = true;
    while ($pos < $len) {
        $seg = $draw ? $dash : $gap;
        $end = min($pos + $seg, $len);
        if ($draw) $pdf->Line($x1+$ux*$pos, $y1+$uy*$pos, $x1+$ux*$end, $y1+$uy*$end);
        $pos += $seg; $draw = !$draw;
    }
}

// ── Helper: crop marks around a cell ─────────────────────────
function drawCutMarks(Fpdi $pdf, float $cx, float $cy, float $cw, float $ch,
                       float $cm, float $ml = 5.0, float $mg = 2.0): void {
    $pdf->SetDrawColor(0,0,0); $pdf->SetLineWidth(0.1);
    $x1=$cx-$cm; $y1=$cy-$cm; $x2=$cx+$cw+$cm; $y2=$cy+$ch+$cm;
    $pdf->Line($x1-$ml,$y1,$x1-$mg,$y1); $pdf->Line($x1,$y1-$ml,$x1,$y1-$mg); // TL
    $pdf->Line($x2+$mg,$y1,$x2+$ml,$y1); $pdf->Line($x2,$y1-$ml,$x2,$y1-$mg); // TR
    $pdf->Line($x1-$ml,$y2,$x1-$mg,$y2); $pdf->Line($x1,$y2+$mg,$x1,$y2+$ml); // BL
    $pdf->Line($x2+$mg,$y2,$x2+$ml,$y2); $pdf->Line($x2,$y2+$mg,$x2,$y2+$ml); // BR
}

// ── Generate ──────────────────────────────────────────────────
try {
    $pdf = new Fpdi('L', 'mm', [$plate_w, $plate_h]);
    $pdf->SetAutoPageBreak(false);
    $pdf->SetMargins(0, 0, 0);

    // Set source file once
    $pdf->setSourceFile($master_pdf_server);
    $page_count_in_pdf = (int)($forma['master_pdf_pages'] ?: 9999);

    foreach ($imposition['plates'] as $plate_data) {
        foreach (['front', 'back'] as $side) {
            $slots = $plate_data['sides'][$side] ?? [];
            if (empty($slots)) continue;

            $pdf->AddPage('L', [$plate_w, $plate_h]);

            // Cut marks for all cells
            for ($c = 0; $c < $cols; $c++) {
                for ($r = 0; $r < $rows; $r++) {
                    $cx = $trim_outer + $c * ($cell_w + $gutter);
                    $cy = $gripper   + $r * ($cell_h + $head_margin);
                    drawCutMarks($pdf, $cx, $cy, $cell_w, $cell_h, $cut_margin);
                }
            }

            // Dashed gutter lines between columns
            $pdf->SetDrawColor(160,160,160); $pdf->SetLineWidth(0.25);
            for ($c = 1; $c < $cols; $c++) {
                $gx = $trim_outer + $c * ($cell_w + $gutter) - $gutter / 2;
                drawDashedLine($pdf, $gx, $gripper/2, $gx, $plate_h - $foot_margin/2);
            }
            $pdf->SetDrawColor(0,0,0);

            // Place pages
            foreach ($slots as $slot) {
                $is_blank  = $slot['is_blank'] ?? false;
                $book_page = $slot['book_page'] ?? null;
                $rotation  = (int)($slot['rotation'] ?? 0);
                $slot_num  = (int)($slot['slot'] ?? 1);
                // Compute col/row from slot number (robust)
                $col_idx = isset($slot['col']) ? (int)$slot['col']-1 : ($slot_num-1) % $cols;
                $row_idx = isset($slot['row']) ? (int)$slot['row']-1 : (int)(($slot_num-1) / $cols);
                $cx = $trim_outer + $col_idx * ($cell_w + $gutter);
                $cy = $gripper   + $row_idx * ($cell_h + $head_margin);

                if ($is_blank || $book_page === null) {
                    $pdf->SetFillColor(238,238,238);
                    $pdf->Rect($cx,$cy,$cell_w,$cell_h,'F');
                    $pdf->SetFont('Helvetica','B',9);
                    $pdf->SetTextColor(180,180,180);
                    $pdf->SetXY($cx, $cy+$cell_h/2-4);
                    $pdf->Cell($cell_w,8,'BLANK',0,0,'C');
                    $pdf->SetTextColor(0,0,0);
                    continue;
                }

                if ($book_page < 1 || $book_page > $page_count_in_pdf) {
                    $pdf->SetFillColor(255,235,235);
                    $pdf->Rect($cx,$cy,$cell_w,$cell_h,'F');
                    $pdf->SetFont('Helvetica','',7); $pdf->SetTextColor(200,0,0);
                    $pdf->SetXY($cx,$cy+$cell_h/2-3);
                    $pdf->Cell($cell_w,6,"P{$book_page} out of range",0,0,'C');
                    $pdf->SetTextColor(0,0,0);
                    continue;
                }

                try {
                    $tpl = $pdf->importPage($book_page, \setasign\Fpdi\PdfReader\PageBoundaries::MEDIA_BOX);

                    if ($rotation === 180) {
                        $mcx = $cx + $cell_w / 2;
                        $mcy = $cy + $cell_h / 2;
                        $pdf->StartTransform();
                        $pdf->Rotate(180, $mcx, $mcy);
                        $pdf->useTemplate($tpl, $cx, $cy, $cell_w, $cell_h, true);
                        $pdf->StopTransform();
                    } else {
                        $pdf->useTemplate($tpl, $cx, $cy, $cell_w, $cell_h, true);
                    }
                } catch (Exception $ie) {
                    $pdf->SetFillColor(255,210,210);
                    $pdf->Rect($cx,$cy,$cell_w,$cell_h,'F');
                    $pdf->SetFont('Helvetica','',6); $pdf->SetTextColor(150,0,0);
                    $pdf->SetXY($cx,$cy+2);
                    $pdf->MultiCell($cell_w,4,"ERR P{$book_page}: ".substr($ie->getMessage(),0,60),0,'C');
                    $pdf->SetTextColor(0,0,0);
                }
            }

            // Plate label footer
            $pdf->SetFont('Helvetica','B',7); $pdf->SetTextColor(80,80,80);
            $label = sprintf('%s | %s | Plate %d %s | pp %d–%d | %s | %s',
                $forma['book_code'], $forma['forma_name'],
                $plate_data['plate_no'], strtoupper($side),
                $forma['page_start'], $forma['page_end'],
                $forma['job_ticket_code'], date('Y-m-d H:i')
            );
            $pdf->SetXY(2, $plate_h-4);
            $pdf->Cell($plate_w-4, 4, $label, 0, 0, 'L');
            $pdf->SetTextColor(0,0,0);
        }
    }

    // Save PDF
    $out_dir = $_SERVER['DOCUMENT_ROOT'] . "/deno2/uploads/forma_pdfs/{$forma['book_code']}/output/";
    if (!is_dir($out_dir)) mkdir($out_dir, 0755, true);

    $filename = strtolower(preg_replace('/[^a-zA-Z0-9_-]/','_',
        $forma['book_code'].'_'.$forma['forma_name']
    )) . '_' . date('YmdHis') . '.pdf';
    $out_path = $out_dir . $filename;
    $web_path = "/deno2/uploads/forma_pdfs/{$forma['book_code']}/output/{$filename}";

    $pdf->Output('F', $out_path);

    $conn->prepare("UPDATE fctp_formas SET output_pdf_path=:p, output_status='generated', output_generated_at=NOW(), updated_at=NOW() WHERE id=:id")
         ->execute([':p'=>$web_path,':id'=>$forma_id]);

    $conn->prepare("INSERT INTO fctp_uploads (forma_id,book_code,upload_type,original_name,saved_path,file_size_bytes,uploaded_by) VALUES(:fid,:bc,'output',:fn,:sp,:sz,:by)")
         ->execute([':fid'=>$forma_id,':bc'=>$forma['book_code'],':fn'=>$filename,
                    ':sp'=>$web_path,':sz'=>filesize($out_path),':by'=>$_SESSION['username']??'system']);

    header("Location: forma_ctp_job_view.php?id={$forma['job_ticket_id']}&msg=".urlencode("✅ Plate PDF generated: {$filename}"));
    exit;

} catch (Exception $e) {
    try {
        $conn->prepare("UPDATE fctp_formas SET output_status='failed',error_msg=:e WHERE id=:id")
             ->execute([':e'=>$e->getMessage(),':id'=>$forma_id]);
    } catch (Exception $ignored) {}

    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
    $emsg = $e->getMessage();
    echo '<div style="max-width:740px;margin:40px auto;padding:28px;background:#fff;border-radius:12px;font-family:Segoe UI,sans-serif;border:1px solid #fee2e2">';
    echo '<h2 style="color:#dc2626;margin-top:0">❌ PDF Generation Failed</h2>';
    echo '<p><strong>Error:</strong> <code style="background:#fef2f2;padding:3px 8px;border-radius:4px;color:#b91c1c">'.htmlspecialchars($emsg).'</code></p>';
    if (stripos($emsg,'FPDF')!==false || stripos($emsg,'class')!==false):
    echo '<div style="background:#fef9c3;padding:12px;border-radius:6px;margin:12px 0;font-size:13px">💡 <strong>Fix:</strong> In your deno2 folder run:<br><code style="display:block;margin-top:6px;padding:8px;background:#1e293b;color:#f1f5f9;border-radius:4px">composer require setasign/fpdf setasign/fpdi</code></div>';
    endif;
    if (stripos($emsg,'encrypt')!==false || stripos($emsg,'password')!==false):
    echo '<div style="background:#fef9c3;padding:12px;border-radius:6px;margin:12px 0;font-size:13px">💡 <strong>Fix:</strong> Your PDF is encrypted/password-protected or uses PDF 1.5+ cross-reference streams. You need <strong>FPDI Pro</strong>, or flatten/re-export the PDF without encryption.</div>';
    endif;
    if (stripos($emsg,'not found')!==false || stripos($emsg,'no such file')!==false):
    echo '<div style="background:#fef9c3;padding:12px;border-radius:6px;margin:12px 0;font-size:13px">💡 <strong>Fix:</strong> Master PDF path is wrong. Re-upload the PDF in Book management.</div>';
    endif;
    echo '<p style="margin-top:16px"><a href="forma_ctp_imposition.php?id='.$forma_id.'" style="color:#1a73e8;margin-right:16px">← Back to Imposition</a> <a href="forma_ctp_job_view.php?id='.($forma['job_ticket_id']??'').'" style="color:#64748b">← Back to Job</a></p>';
    echo '</div>';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';
}
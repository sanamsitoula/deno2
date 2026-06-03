<?php
/**
 * CTP Export Download — generates CSV / JSON / PHP imposition data for CTP software
 *
 * Usage:
 *   ctp_export_download.php?job_id=5&format=csv    → imposition plan as CSV
 *   ctp_export_download.php?job_id=5&format=json   → full JSON
 *   ctp_export_download.php?job_id=5&format=pdf    → trigger PDF generation
 */

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$job_id = (int)($_GET['job_id'] ?? 0);
$format = strtolower($_GET['format'] ?? 'json');

// Show nav landing page if no job_id
if (!$job_id) {
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
    echo '
    <style>.ctp-nav{display:flex;gap:8px;flex-wrap:wrap;margin:0 auto 20px;max-width:1200px;background:#fff;padding:14px 18px;border-radius:10px;box-shadow:0 2px 12px rgba(0,0,0,.08);align-items:center;}.ctp-nav-title{font-weight:800;font-size:15px;color:#1e293b;margin-right:10px;}.nav-link{padding:7px 16px;border-radius:6px;font-size:13px;font-weight:600;text-decoration:none;color:#64748b;border:1.5px solid #e2e8f0;}.nav-link:hover,.nav-link.active{background:#1a73e8;color:#fff;border-color:#1a73e8;}</style>
    <div class="ctp-nav" style="max-width:1200px;margin:20px auto;">
        <span class="ctp-nav-title">🖨️ CTP Module</span>
        <a href="ctp_export.php" class="nav-link">📋 Export Jobs</a>
        <a href="ctp_export.php?new=1" class="nav-link">➕ New Job</a>
        <a href="ctp_generate_prc.php" class="nav-link">⚙️ Generate PRC PDF</a>
        <a href="ctp_export_download.php" class="nav-link active">⬇️ Download</a>
        <a href="ctp_export.php?tab=margins" class="nav-link">📐 Margin Guide</a>
    </div>
    <div style="max-width:600px;margin:40px auto;padding:28px;background:#fff;border-radius:12px;box-shadow:0 2px 12px rgba(0,0,0,.1);font-family:Segoe UI,sans-serif;text-align:center;">
        <div style="font-size:40px;margin-bottom:12px;">⬇️</div>
        <h2 style="color:#1e293b;margin:0 0 14px;">Download / Export</h2>
        <p style="color:#555;margin-bottom:20px;">Select a job from the jobs list to download CSV or JSON imposition data.</p>
        <p><a href="ctp_export.php" style="padding:10px 24px;background:#1a73e8;color:#fff;border-radius:7px;text-decoration:none;font-weight:700;">← View All Jobs</a></p>
    </div>';
    require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';
    exit;
}

$stmt = $conn->prepare("SELECT * FROM ctp_export_jobs WHERE id = :id");
$stmt->execute([':id' => $job_id]);
$job = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$job) die("Job #{$job_id} not found");

$page_order = json_decode($job['page_order_json'], true) ?: [];
$total      = (int)$job['total_pages'];
$timestamp  = date('YmdHis');
$name       = preg_replace('/[^a-z0-9_]/i', '_', $job['job_name']);

if ($format === 'csv') {
    header('Content-Type: text/csv');
    header("Content-Disposition: attachment; filename=\"ctp_{$name}_{$timestamp}.csv\"");
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Sheet', 'Side', 'Column', 'Row', 'Page_Number', 'Is_Blank']);
    foreach ($page_order as $sheet) {
        $si = $sheet['sheet_index'];
        foreach (['front','back'] as $side) {
            $col = 1; $row = 1;
            foreach ($sheet[$side] as $pair) {
                foreach (['right_pg','left_pg'] as $k) {
                    $pg = $pair[$k] ?? null;
                    if ($pg !== null) {
                        fputcsv($out, [$si, $side, $col, $row, $pg, ($pg > $total || $pg < 1) ? 'YES' : 'NO']);
                        $col++;
                        if ($col > $job['cols']) { $col = 1; $row++; }
                    }
                }
            }
        }
    }
    fclose($out);
    exit;
}

if ($format === 'json') {
    $export = [
        'job_id'         => $job_id,
        'job_name'       => $job['job_name'],
        'book_code'      => $job['book_code'],
        'generated_at'   => date('c'),
        'layout'         => $job['layout_type'],
        'cols'           => (int)$job['cols'],
        'rows'           => (int)$job['rows'],
        'signature_size' => (int)$job['signature_size'],
        'total_pages'    => $total,
        'padded_pages'   => (int)$job['padded_pages'],
        'blank_pages'    => (int)$job['blank_inserted'],
        'sheet_size_mm'  => ['w' => $job['sheet_width'], 'h' => $job['sheet_height']],
        'margins_mm'     => [
            'bleed'       => $job['bleed'],
            'gutter'      => $job['gutter'],
            'trim_outer'  => $job['trim_outer'],
            'gripper'     => $job['gripper'],
            'head'        => $job['head_margin'],
            'foot'        => $job['foot_margin'],
        ],
        'page_order'     => $page_order,
    ];
    header('Content-Type: application/json');
    header("Content-Disposition: attachment; filename=\"ctp_{$name}_{$timestamp}.json\"");
    echo json_encode($export, JSON_PRETTY_PRINT);
    exit;
}

if ($format === 'pdf') {
    // Redirect to generator
    header("Location: ctp_generate_prc.php?job_id={$job_id}");
    exit;
}

die('Unknown format. Use: csv, json, or pdf');

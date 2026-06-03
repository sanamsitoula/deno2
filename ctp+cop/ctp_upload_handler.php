<?php
/**
 * ctp_upload_handler.php
 * Handles PDF upload and returns page count via AJAX.
 * No FPDI/Composer needed — pure PHP PDF page counter.
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_FILES['pdf_file'])) {
    echo json_encode(['success' => false, 'error' => 'No file uploaded.']);
    exit;
}

$file    = $_FILES['pdf_file'];
$maxSize = 200 * 1024 * 1024; // 200MB

if ($file['error'] !== UPLOAD_ERR_OK) {
    echo json_encode(['success' => false, 'error' => 'Upload error code: ' . $file['error']]);
    exit;
}

if ($file['size'] > $maxSize) {
    echo json_encode(['success' => false, 'error' => 'File too large (max 200MB).']);
    exit;
}

$ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
if ($ext !== 'pdf') {
    echo json_encode(['success' => false, 'error' => 'Only PDF files allowed.']);
    exit;
}

// Save to uploads/ctp_pdfs/
$uploadDir = $_SERVER['DOCUMENT_ROOT'] . '/deno2/uploads/ctp_pdfs/';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0755, true);
}

$safeName  = date('Ymd_His') . '_' . preg_replace('/[^a-zA-Z0-9._-]/', '_', $file['name']);
$destPath  = $uploadDir . $safeName;
$webPath   = '/deno2/uploads/ctp_pdfs/' . $safeName;

if (!move_uploaded_file($file['tmp_name'], $destPath)) {
    echo json_encode(['success' => false, 'error' => 'Failed to move uploaded file.']);
    exit;
}

// ── Count PDF pages WITHOUT FPDI ─────────────────────────────────────────────
function countPdfPages(string $path): int {
    $count = 0;
    // Method 1: Read /Count from PDF structure
    $handle = fopen($path, 'rb');
    if (!$handle) return 0;

    $content = '';
    // Read in chunks scanning for /Count
    while (!feof($handle)) {
        $chunk = fread($handle, 65536);
        $content .= $chunk;
        // Once we have enough data or found the marker, break
        if (strlen($content) > 1048576) break; // 1MB limit for header search
    }
    fclose($handle);

    // Try to find all /Count N entries and take the max (root page count)
    if (preg_match_all('/\/Count\s+(\d+)/', $content, $matches)) {
        $count = (int) max($matches[1]);
    }

    if ($count > 0) return $count;

    // Method 2: Count /Page objects
    $fullContent = file_get_contents($path);
    $count = preg_match_all('/\/Type\s*\/Page[^s]/', $fullContent);
    return max(0, $count);
}

$pageCount = countPdfPages($destPath);

if ($pageCount === 0) {
    // Try alternative: read full file
    $fullContent = file_get_contents($destPath);
    preg_match_all('/\/Type\s*\/Page\b/', $fullContent, $m);
    $pageCount = count($m[0]);
}

echo json_encode([
    'success'    => true,
    'pages'      => $pageCount,
    'filename'   => $file['name'],
    'saved_path' => $webPath,
    'file_size'  => round($file['size'] / 1024 / 1024, 2) . ' MB',
]);
ob_end_flush();

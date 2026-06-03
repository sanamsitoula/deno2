<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message   = null;
$success_message = null;
$upload_stats    = [];

// ================================================================
// PDF TEXT EXTRACTOR
// This PDF uses FlateDecode (zlib-compressed) streams — the raw
// file bytes are compressed, so you cannot grep for BT/ET in the
// raw binary. We decompress every stream with gzuncompress(), then
// extract positioned text cells and reconstruct logical lines.
// No pdftotext / poppler / external tools needed. Works on Windows.
// ================================================================
function extractTextFromPDF(string $filepath): string
{
    $raw = file_get_contents($filepath);
    if ($raw === false) {
        throw new Exception("Cannot read the uploaded PDF file.");
    }

    // Find every raw stream blob between "stream\n" and "endstream"
    preg_match_all('/stream\r?\n(.*?)endstream/s', $raw, $streamMatches);
    $streams = $streamMatches[1];

    $allLines = [];

    foreach ($streams as $streamRaw) {
        // Decompress zlib/FlateDecode stream
        $decompressed = @gzuncompress($streamRaw);
        if ($decompressed === false) {
            $decompressed = @gzinflate($streamRaw); // raw deflate fallback
        }
        if ($decompressed === false || strlen($decompressed) < 10) {
            continue; // font/image stream, skip
        }

        // Extract positioned text cells: "x y Td (text) Tj"
        // Each BT block in this PDF has one text piece at explicit X,Y coords
        preg_match_all(
            '/([\d.]+)\s+([\d.]+)\s+Td\s+\((.*?)\)\s+Tj/s',
            $decompressed,
            $entries
        );
        if (empty($entries[0])) continue;

        // Group by Y coordinate (same Y = same visual row on page)
        $rows = [];
        $count = count($entries[0]);
        for ($i = 0; $i < $count; $i++) {
            $x    = (float)$entries[1][$i];
            $y    = round((float)$entries[2][$i], 0); // round to nearest pt
            $text = $entries[3][$i];

            // Unescape PDF string escapes
            $text = str_replace(
                ['\\n','\\r','\\t','\\\\','\\(','\\)'],
                ["\n", "\r", "\t", '\\',  '(',  ')' ],
                $text
            );
            $text = trim($text);
            if ($text === '') continue;

            $rows[$y][] = ['x' => $x, 'text' => $text];
        }

        // Sort rows top-to-bottom (PDF Y=0 is bottom, so higher Y = higher on page)
        krsort($rows);

        foreach ($rows as $cells) {
            usort($cells, fn($a, $b) => $a['x'] <=> $b['x']); // left-to-right
            $allLines[] = implode(' ', array_column($cells, 'text'));
        }
    }

    return implode("\n", $allLines);
}

// ================================================================
// ATTENDANCE TEXT PARSER
// Lines reconstructed from X/Y positions look like:
//   "Periodic Attendance Report From 01/10/2082 to 29/10/2082"
//   "Employee Id 1 Employee Name Yadu Nath poudel"
//   "01/10/2082 , Thursday 10:00 17:00 07:00 11:33 20:07 ... Present"
//
// Actual check-in/check-out are at fixed column positions:
//   times[0] = Planned In  (~x151)
//   times[1] = Planned Out (~x209)
//   times[2] = Planned Work Time (~x270)
//   times[3] = Actual Check-In  (~x318)  <-- we want this
//   times[4] = Actual Check-Out (~x407)  <-- we want this
// ================================================================
function parseAttendanceText(string $pdf_text): array
{
    $lines       = explode("\n", $pdf_text);
    $month_range = null;
    $employees   = [];
    $current     = null;

    foreach ($lines as $rawLine) {
        $line = trim($rawLine);
        if ($line === '') continue;

        // ── Date-range header ─────────────────────────────────
        if (preg_match(
            '/From\s+(\d{2})\/(\d{2})\/(\d{4})\s+to\s+(\d{2})\/(\d{2})\/(\d{4})/',
            $line, $m
        )) {
            $month_range = [
                'start_day'   => $m[1], 'start_month' => $m[2], 'start_year' => $m[3],
                'end_day'     => $m[4], 'end_month'   => $m[5], 'end_year'   => $m[6],
            ];
            continue;
        }

        // ── Employee Id line ──────────────────────────────────
        if (preg_match('/Employee\s+Id\s+(\d+)/i', $line, $m)) {
            if ($current !== null) $employees[] = $current;
            $current = ['attendance_id' => $m[1], 'rows' => []];
            continue;
        }

        // ── Attendance data row ───────────────────────────────
        if ($current !== null &&
            preg_match('/^(\d{2})\/(\d{2})\/(\d{4})\s*,\s*\w+\s+(.*)$/', $line, $m)
        ) {
            $date_nep     = sprintf('%s.%s.%s', $m[3], $m[2], $m[1]); // YYYY.MM.DD
            $rest         = trim($m[4]);
            $words        = preg_split('/\s+/', $rest);
            $remark       = end($words);
            $remark_lower = strtolower($remark);

            preg_match_all('/\b(\d{2}:\d{2})\b/', $rest, $tm);
            $times = $tm[1];

            // Weekend: planned cols are 00:00/00:00; actual punches follow at [2],[3]
            if ($remark_lower === 'weekend') {
                if (count($times) >= 4 && $times[0] === '00:00') {
                    $check_in  = $times[2] ?? null;
                    $check_out = $times[3] ?? null;
                } else {
                    $check_in  = $times[0] ?? null;
                    $check_out = $times[1] ?? null;
                }
            } else {
                // Normal rows: actual check-in at index 3, check-out at index 4
                $check_in  = $times[3] ?? null;
                $check_out = $times[4] ?? null;
            }

            if      (strpos($remark_lower, 'absent')  !== false) $status_code = 'A';
            elseif  (strpos($remark_lower, 'weekend') !== false) $status_code = 'WO';
            elseif  (strpos($remark_lower, 'holiday') !== false) $status_code = 'PH';
            elseif  (strpos($remark_lower, 'leave')   !== false) $status_code = 'L';
            elseif  (strpos($remark_lower, 'half')    !== false) $status_code = 'HD';
            elseif  (strpos($remark_lower, 'misc')    !== false) $status_code = 'P';
            else                                                  $status_code = 'P';

            $current['rows'][] = compact('date_nep', 'status_code', 'check_in', 'check_out', 'remark');
        }
    }

    if ($current !== null) $employees[] = $current;

    return ['month_range' => $month_range, 'employees' => $employees];
}

// ================================================================
// HANDLE POST
// ================================================================
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['attendance_pdf'])) {
    try {
        $conn->beginTransaction();

        $file = $_FILES['attendance_pdf'];
        if ($file['error'] !== UPLOAD_ERR_OK) throw new Exception("Upload error code: " . $file['error']);
        if (strtolower(pathinfo($file['name'], PATHINFO_EXTENSION)) !== 'pdf') throw new Exception("Only PDF files allowed.");

        $upload_dir = rtrim(sys_get_temp_dir(), '/\\') . DIRECTORY_SEPARATOR . 'att_uploads' . DIRECTORY_SEPARATOR;
        if (!file_exists($upload_dir)) mkdir($upload_dir, 0777, true);
        $temp_file = $upload_dir . uniqid('att_', true) . '.pdf';

        if (!move_uploaded_file($file['tmp_name'], $temp_file)) throw new Exception("Failed to save file to temp directory.");

        $pdf_text = extractTextFromPDF($temp_file);
        @unlink($temp_file);

        if (strlen(trim($pdf_text)) < 50) {
            throw new Exception("Could not extract text. Ensure this is a text-based PDF, not a scanned image.");
        }

        $parsed         = parseAttendanceText($pdf_text);
        $month_range    = $parsed['month_range'];
        $employees_data = $parsed['employees'];

        if (empty($employees_data)) {
            throw new Exception("No employee records found. Verify this is a 'Periodic Attendance Report' PDF.");
        }

        $inserted_count = $skipped_count = $error_count = 0;
        $errors = [];
        $status_cache = [];

        foreach ($employees_data as $emp_data) {
            $attendance_id = $emp_data['attendance_id'];

            $stmt = $conn->prepare("SELECT id FROM employee WHERE attendance_id = :aid AND deleted_date IS NULL LIMIT 1");
            $stmt->execute([':aid' => $attendance_id]);
            $employee = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$employee) {
                $errors[] = "Employee not found for attendance_id: {$attendance_id}";
                $error_count++;
                continue;
            }
            $employee_id = $employee['id'];

            foreach ($emp_data['rows'] as $row) {
                try {
                    $code = $row['status_code'];
                    if (!isset($status_cache[$code])) {
                        $ss = $conn->prepare("SELECT id FROM attendance_status WHERE status_code = :code");
                        $ss->execute([':code' => $code]);
                        $status_cache[$code] = $ss->fetchColumn();
                    }
                    $status_id = $status_cache[$code];
                    if (!$status_id) { $errors[] = "Unknown status '{$code}' for {$row['date_nep']}"; $error_count++; continue; }

                    $dup = $conn->prepare("SELECT id FROM attendance WHERE employee_id=:eid AND attendance_date_nep=:dn LIMIT 1");
                    $dup->execute([':eid' => $employee_id, ':dn' => $row['date_nep']]);
                    if ($dup->fetch()) { $skipped_count++; continue; }

                    $ins = $conn->prepare("
                        INSERT INTO attendance (employee_id,attendance_date_nep,attendance_date_eng,
                            status_id,check_in_time,check_out_time,remarks,marked_by,created_at)
                        VALUES (:eid,:dn,:de,:sid,:ci,:co,:rem,:mb,CURRENT_TIMESTAMP)
                    ");
                    $ins->execute([
                        ':eid' => $employee_id, ':dn' => $row['date_nep'],
                        ':de'  => '2025-01-01',  // placeholder — add BS→AD converter here if available
                        ':sid' => $status_id,
                        ':ci'  => $row['check_in']  ?: null,
                        ':co'  => $row['check_out'] ?: null,
                        ':rem' => $row['remark'],
                        ':mb'  => $_SESSION['user_id'] ?? 1,
                    ]);
                    $inserted_count++;
                } catch (Exception $e) {
                    $errors[] = "Error on {$row['date_nep']} (emp {$attendance_id}): " . $e->getMessage();
                    $error_count++;
                }
            }

            // Update monthly summary
            if ($month_range) {
                $ym = $month_range['start_year'] . '.' . $month_range['start_month'];
                try { $conn->prepare("SELECT update_monthly_summary(:eid,:m)")->execute([':eid'=>$employee_id,':m'=>$ym]); } catch(Exception $e){}
            }
        }

        $conn->commit();
        $success_message = "✅ Upload completed! Inserted: {$inserted_count} | Skipped (duplicates): {$skipped_count} | Errors: {$error_count}";
        $upload_stats = ['inserted'=>$inserted_count,'skipped'=>$skipped_count,'errors'=>$error_count,'error_details'=>$errors];

    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
        if (isset($temp_file) && file_exists($temp_file)) @unlink($temp_file);
    }
}
?>
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
body{font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif;background:#f5f7fa;margin:0;padding:0}
.container{max-width:1400px;margin:0 auto;padding:20px}
.page-header{background:linear-gradient(135deg,#667eea 0%,#764ba2 100%);color:white;padding:30px;border-radius:12px;margin-bottom:30px;box-shadow:0 4px 15px rgba(0,0,0,.2)}
.page-title{font-size:32px;font-weight:700;margin:0 0 10px}
.page-subtitle{font-size:16px;opacity:.9}
.info-section{background:#fff3cd;border:2px solid #ffc107;border-radius:12px;padding:25px;margin-bottom:30px}
.info-section h3{color:#856404;margin-top:0;font-size:20px}
.info-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(280px,1fr));gap:20px;margin-top:15px}
.info-item{background:white;padding:15px;border-radius:8px;border-left:4px solid #ffc107}
.info-label{font-weight:600;color:#856404;margin-bottom:5px}
.info-value{color:#333;font-size:14px}
.upload-container{background:white;border-radius:12px;padding:40px;margin-bottom:30px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.upload-zone{border:3px dashed #667eea;border-radius:12px;padding:60px 40px;text-align:center;background:#f8f9ff;cursor:pointer;transition:all .3s;margin-top:20px}
.upload-zone:hover,.upload-zone.drag-over{background:#f0f2ff;border-color:#5568d3}
.upload-zone.drag-over{transform:scale(1.02)}
.upload-icon{font-size:72px;margin-bottom:20px}
.upload-text{font-size:20px;font-weight:600;color:#333;margin-bottom:10px}
.upload-subtext{font-size:14px;color:#6c757d}
.file-input{display:none}
.selected-file{background:white;border:2px solid #28a745;border-radius:8px;padding:20px;margin-top:20px;display:none}
.selected-file.show{display:flex;align-items:center;gap:20px}
.file-icon{font-size:48px}.file-details{flex:1}
.file-name{font-weight:600;color:#333;font-size:16px;margin-bottom:5px}
.file-size{font-size:13px;color:#6c757d}
.btn{padding:12px 24px;border:none;border-radius:6px;cursor:pointer;font-size:15px;font-weight:600;text-decoration:none;display:inline-flex;align-items:center;gap:8px;transition:all .2s}
.btn-success{background:#28a745;color:white}.btn-danger{background:#dc3545;color:white}.btn-secondary{background:#6c757d;color:white}
.btn:hover{transform:translateY(-1px);box-shadow:0 4px 12px rgba(0,0,0,.2)}.btn:disabled{background:#ccc;cursor:not-allowed;transform:none}
.alert{padding:16px 20px;border-radius:8px;margin-bottom:20px;font-size:14px}
.alert-success{background:#d4edda;color:#155724;border:2px solid #c3e6cb}
.alert-danger{background:#f8d7da;color:#721c24;border:2px solid #f5c6cb}
.stats-container{background:white;border-radius:12px;padding:30px;margin-bottom:30px;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:20px;margin-top:20px}
.stat-card{color:white;padding:20px;border-radius:8px;text-align:center}
.stat-card.success{background:linear-gradient(135deg,#28a745,#20c997)}
.stat-card.warning{background:linear-gradient(135deg,#ffc107,#ff9800)}
.stat-card.danger{background:linear-gradient(135deg,#dc3545,#c82333)}
.stat-value{font-size:36px;font-weight:700;margin-bottom:5px}.stat-label{font-size:14px;opacity:.9}
.error-list{background:#fff;border:2px solid #dc3545;border-radius:8px;padding:20px;margin-top:20px;max-height:400px;overflow-y:auto}
.error-item{padding:10px;background:#f8d7da;border-left:4px solid #dc3545;margin-bottom:10px;border-radius:4px;font-size:13px}
.progress-container{margin-top:20px;display:none}
.progress-bar{width:100%;height:40px;background:#e9ecef;border-radius:20px;overflow:hidden}
.progress-fill{height:100%;background:linear-gradient(90deg,#667eea,#764ba2);width:0%;transition:width .3s;display:flex;align-items:center;justify-content:center;color:white;font-weight:600;font-size:14px}
.sample-format{background:white;border:2px solid #17a2b8;border-radius:8px;padding:20px;margin-top:20px;font-family:'Courier New',monospace;font-size:12px;overflow-x:auto}
.sample-format pre{margin:0;white-space:pre}
details summary{cursor:pointer;font-weight:600;padding:10px;background:#f8f9fa;border-radius:4px;list-style:none}
details[open] summary{border-radius:4px 4px 0 0}
details .detail-body{padding:15px;border-left:3px solid #667eea;margin-top:2px;background:#fff;border-radius:0 0 4px 4px}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📤 Attendance PDF Bulk Upload</h1>
        <p class="page-subtitle">Upload periodic attendance reports — pure PHP, no extra tools, works on Windows</p>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success"><?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>
    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <?php if (!empty($upload_stats)): ?>
    <div class="stats-container">
        <h3 style="margin-top:0">📊 Upload Results</h3>
        <div class="stats-grid">
            <div class="stat-card success"><div class="stat-value"><?= $upload_stats['inserted'] ?></div><div class="stat-label">Records Inserted</div></div>
            <div class="stat-card warning"><div class="stat-value"><?= $upload_stats['skipped'] ?></div><div class="stat-label">Duplicates Skipped</div></div>
            <div class="stat-card danger"><div class="stat-value"><?= $upload_stats['errors'] ?></div><div class="stat-label">Errors</div></div>
        </div>
        <?php if (!empty($upload_stats['error_details'])): ?>
        <div class="error-list">
            <h4 style="margin-top:0">⚠️ Error Details:</h4>
            <?php foreach (array_slice($upload_stats['error_details'],0,20) as $err): ?>
                <div class="error-item"><?= htmlspecialchars($err) ?></div>
            <?php endforeach; ?>
            <?php if (count($upload_stats['error_details'])>20): ?>
                <p style="margin-top:10px;color:#6c757d">… and <?= count($upload_stats['error_details'])-20 ?> more errors</p>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <div class="info-section">
        <h3>📋 How This Works</h3>
        <div class="info-grid">
            <div class="info-item"><div class="info-label">1️⃣ Upload PDF</div><div class="info-value">Upload the "Periodic Attendance Report" PDF exported from your HR software.</div></div>
            <div class="info-item"><div class="info-label">2️⃣ Auto-Parse</div><div class="info-value">Employee ID, dates, actual check-in/out times and remarks extracted automatically from compressed PDF streams.</div></div>
            <div class="info-item"><div class="info-label">3️⃣ Match Employees</div><div class="info-value">PDF "Employee Id" matched with <code>employee.attendance_id</code> in the database.</div></div>
            <div class="info-item"><div class="info-label">4️⃣ Import Data</div><div class="info-value">Records inserted with status and times. Duplicates skipped automatically.</div></div>
        </div>
        <div class="sample-format">
            <strong>Expected Format:</strong>
            <pre>
Periodic Attendance Report From 01/10/2082 to 29/10/2082
Employee Id  1    Employee Name  Yadu Nath poudel
01/10/2082 , Thursday   10:00  17:00  07:00                   Absent
02/10/2082 , Friday     10:00  17:00  07:00  11:33  20:07 ... Present
03/10/2082 , Saturday   00:00  00:00         09:31  09:31     Weekend</pre>
        </div>
    </div>

    <div class="upload-container">
        <h3 style="margin-top:0">📁 Upload Your PDF</h3>
        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-zone" id="uploadZone">
                <div class="upload-icon">📄</div>
                <div class="upload-text">Click to upload or drag and drop</div>
                <div class="upload-subtext">PDF files only &bull; Max size: 50 MB</div>
                <input type="file" name="attendance_pdf" id="attendance_pdf" class="file-input" accept=".pdf" required>
            </div>
            <div class="selected-file" id="selectedFile">
                <div class="file-icon">📄</div>
                <div class="file-details">
                    <div class="file-name" id="fileName"></div>
                    <div class="file-size" id="fileSize"></div>
                </div>
                <button type="button" class="btn btn-danger" onclick="removeFile()">✕ Remove</button>
            </div>
            <div class="progress-container" id="progressContainer">
                <div class="progress-bar"><div class="progress-fill" id="progressFill">0%</div></div>
            </div>
            <div style="margin-top:30px;text-align:center">
                <button type="submit" class="btn btn-success" id="uploadBtn" disabled>📤 Process and Import Attendance</button>
                <a href="attendance_entry.php" class="btn btn-secondary">← Back to Manual Entry</a>
            </div>
        </form>
    </div>

    <div class="upload-container">
        <h3 style="margin-top:0">❓ Troubleshooting</h3>
        <details style="margin-bottom:12px">
            <summary>Error: "Could not extract text from this PDF"</summary>
            <div class="detail-body">The PDF is likely a <strong>scanned image</strong>. Export the report directly from your HR attendance software to get a text-based PDF.</div>
        </details>
        <details style="margin-bottom:12px">
            <summary>Error: "Employee not found for attendance_id: X"</summary>
            <div class="detail-body">
                The <code>attendance_id</code> column in the <code>employee</code> table must match the Employee Id in the PDF.<br><br>
                <code>UPDATE employee SET attendance_id = '1' WHERE id = 1;</code>
            </div>
        </details>
        <details>
            <summary>Many records skipped as duplicates</summary>
            <div class="detail-body"><strong>Normal behaviour.</strong> Re-uploading the same PDF skips records already in the database to prevent duplicates.</div>
        </details>
    </div>
</div>

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script>
const uploadZone=document.getElementById('uploadZone'),fileInput=document.getElementById('attendance_pdf'),
      selectedFile=document.getElementById('selectedFile'),uploadBtn=document.getElementById('uploadBtn'),
      uploadForm=document.getElementById('uploadForm'),progressContainer=document.getElementById('progressContainer'),
      progressFill=document.getElementById('progressFill');

uploadZone.addEventListener('click',()=>fileInput.click());
uploadZone.addEventListener('dragover',e=>{e.preventDefault();uploadZone.classList.add('drag-over')});
uploadZone.addEventListener('dragleave',()=>uploadZone.classList.remove('drag-over'));
uploadZone.addEventListener('drop',e=>{e.preventDefault();uploadZone.classList.remove('drag-over');if(e.dataTransfer.files.length){fileInput.files=e.dataTransfer.files;handleFileSelect();}});
fileInput.addEventListener('change',handleFileSelect);

function handleFileSelect(){
    const file=fileInput.files[0];if(!file)return;
    if(file.type!=='application/pdf'){alert('❌ Please upload a PDF file only');fileInput.value='';return;}
    if(file.size>50*1024*1024){alert('❌ File size must be less than 50 MB');fileInput.value='';return;}
    document.getElementById('fileName').textContent=file.name;
    document.getElementById('fileSize').textContent=formatFileSize(file.size);
    selectedFile.classList.add('show');uploadBtn.disabled=false;
}
function removeFile(){fileInput.value='';selectedFile.classList.remove('show');uploadBtn.disabled=true;}
function formatFileSize(b){if(!b)return'0 Bytes';const k=1024,s=['Bytes','KB','MB','GB'],i=Math.floor(Math.log(b)/Math.log(k));return Math.round(b/Math.pow(k,i)*100)/100+' '+s[i];}
uploadForm.addEventListener('submit',()=>{
    progressContainer.style.display='block';uploadBtn.disabled=true;uploadBtn.innerHTML='⏳ Processing PDF…';
    let p=0;const t=setInterval(()=>{p+=2;if(p<=90){progressFill.style.width=p+'%';progressFill.textContent=p+'%';}else clearInterval(t);},400);
});
window.addEventListener('beforeunload',e=>{if(uploadBtn.disabled&&uploadBtn.innerHTML.includes('Processing')){e.preventDefault();e.returnValue='';}});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;

// Get active fiscal year
$active_fy_stmt = $conn->query("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
$active_fy = $active_fy_stmt->fetch(PDO::FETCH_ASSOC);

// ================== Validation Functions ==================
function validateEmployeeType($type) {
    return in_array(strtoupper(trim($type)), ['PERMANENT', 'CONTRACT', 'DAILY_WAGES']);
}

function validateGender($gender) {
    if (empty($gender)) return true;
    return in_array(strtoupper(trim($gender)), ['MALE', 'FEMALE', 'OTHER']);
}

function validateEmail($email) {
    if (empty($email)) return true;
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validateMobile($mobile) {
    if (empty($mobile)) return true;
    return preg_match('/^98\d{8}$/', $mobile);
}

function validateDate($date) {
    if (empty($date)) return true;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function validateReference($conn, $table, $id) {
    if (empty($id)) return true;
    $stmt = $conn->prepare("SELECT id FROM $table WHERE id = :id AND status = true");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch() !== false;
}

function convertToBoolean($value) {
    if (is_bool($value)) return $value;
    if (is_null($value) || trim($value) === '') return false;
    
    $value = strtolower(trim((string)$value));
    if (in_array($value, ['true', 'yes', '1', 't', 'y'])) return true;
    if (in_array($value, ['false', 'no', '0', 'f', 'n'])) return false;
    if (is_numeric($value)) return (int)$value != 0;
    
    return false;
}

function fixDateFormat($dateStr) {
    if (empty(trim($dateStr))) return null;
    $dateStr = trim($dateStr);
    
    // Already YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) return $dateStr;
    
    // MM/DD/YYYY or M/D/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
        $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        if (checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day";
        }
    }
    
    // DD-MM-YYYY or D-M-YYYY (Nepali format sometimes)
    if (preg_match('/^(\d{1,2})-(\d{1,2})-(\d{4})$/', $dateStr, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        if (checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day";
        }
    }
    
    return $dateStr;
}

function generateEmployeeCode($conn, $emp_type) {
    $prefix = match(strtoupper($emp_type)) {
        'PERMANENT' => 'EMP-P',
        'CONTRACT' => 'EMP-C',
        'DAILY_WAGES' => 'EMP-DW',
        default => 'EMP'
    };
    
    $stmt = $conn->query("SELECT MAX(CAST(SUBSTRING(code FROM '[0-9]+$') AS INTEGER)) 
                          FROM employee WHERE code LIKE '$prefix-%'");
    $max_num = $stmt->fetchColumn() ?? 0;
    $next_num = $max_num + 1;
    
    return sprintf("%s-%04d", $prefix, $next_num);
}

// ================== Handle Actions ==================
$errors = [];
$warnings = [];
$success = false;
$imported_count = 0;
$skipped_count = 0;
$preview_data = [];
$preview_mode = false;

// Handle Preview Request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    handlePreview($conn, $active_fy);
}

// Handle Import Request (after preview)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'import') {
    handleImport($conn, $active_fy, $current_user_id);
}

// Handle Error Download
if (isset($_GET['download_errors']) && isset($_SESSION['error_rows'])) {
    downloadErrorCSV();
}

// Handle Regular Upload (Legacy mode - now redirects to preview)
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && !isset($_POST['action'])) {
    // Redirect to preview mode
    $_POST['action'] = 'preview';
    handlePreview($conn, $active_fy);
}

function handlePreview($conn, $active_fy) {
    global $preview_data, $preview_mode, $errors;
    
    $file = $_FILES['csv_file'];
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error. Code: " . $file['error'];
        return;
    }
    
    if ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = "File size exceeds 5MB limit.";
        return;
    }
    
    $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
    if ($ext !== 'csv') {
        $errors[] = "Only CSV files are allowed.";
        return;
    }
    
    $handle = fopen($file['tmp_name'], 'r');
    if (!$handle) {
        $errors[] = "Could not read uploaded file.";
        return;
    }
    
    // Handle BOM
    $firstLine = fgets($handle);
    rewind($handle);
    if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
        fseek($handle, 3);
    }
    
    $headers = fgetcsv($handle);
    if ($headers === false) {
        $errors[] = "Could not read CSV headers.";
        fclose($handle);
        return;
    }
    
    // Clean headers
    $headers = array_map(fn($h) => strtolower(trim($h)), $headers);
    
    // Check required columns
    $required = ['name', 'emp_type'];
    foreach ($required as $col) {
        if (!in_array($col, $headers)) {
            $errors[] = "Missing required column: '$col'";
        }
    }
    
    if (!empty($errors)) {
        fclose($handle);
        return;
    }
    
    // Store headers in session for error export
    $_SESSION['csv_headers'] = $headers;
    
    $line_number = 1;
    $valid_rows = [];
    $error_rows = [];
    
    while (($row = fgetcsv($handle)) !== FALSE) {
        $line_number++;
        
        if (empty(array_filter($row))) continue;
        
        // Pad row to match headers
        while (count($row) < count($headers)) {
            $row[] = '';
        }
        
        $data = array_combine($headers, array_slice($row, 0, count($headers)));
        $data = array_map('trim', $data);
        $row_errors = [];
        $row_warnings = [];
        
        // Validate required fields
        if (empty($data['name'])) {
            $row_errors[] = "Name is required";
        }
        
        if (empty($data['emp_type']) || !validateEmployeeType($data['emp_type'])) {
            $row_errors[] = "Invalid emp_type (PERMANENT/CONTRACT/DAILY_WAGES)";
        }
        
        // Validate optional fields
        if (!empty($data['gender']) && !validateGender($data['gender'])) {
            $row_errors[] = "Invalid gender (MALE/FEMALE/OTHER)";
        }
        
        if (!empty($data['email']) && !validateEmail($data['email'])) {
            $row_errors[] = "Invalid email format";
        }
        
        if (!empty($data['mobile_number']) && !validateMobile($data['mobile_number'])) {
            $row_warnings[] = "Mobile format should be 98XXXXXXXX";
        }
        
        // Fix and validate dates
        foreach (['join_date', 'dob', 'initial_appointment_date', 'retirement_date'] as $date_field) {
            if (!empty($data[$date_field])) {
                $fixedDate = fixDateFormat($data[$date_field]);
                $data[$date_field] = $fixedDate;
                
                if (!validateDate($fixedDate)) {
                    $row_errors[] = "Invalid $date_field format (use YYYY-MM-DD or MM/DD/YYYY)";
                }
            }
        }
        
        // Validate references
        if (!empty($data['department_id']) && !validateReference($conn, 'department', $data['department_id'])) {
            $row_errors[] = "Invalid department_id";
        }
        if (!empty($data['designation_id']) && !validateReference($conn, 'designation', $data['designation_id'])) {
            $row_errors[] = "Invalid designation_id";
        }
        if (!empty($data['level_id']) && !validateReference($conn, 'level', $data['level_id'])) {
            $row_errors[] = "Invalid level_id";
        }
        
        // Prepare processed data
        $processed_data = [
            'line_number' => $line_number,
            'raw_data' => $data,
            'name' => $data['name'],
            'name_eng' => !empty($data['name_eng']) ? $data['name_eng'] : $data['name'],
            'name_nep' => $data['name_nep'] ?? null,
            'emp_type' => strtoupper($data['emp_type']),
            'department_id' => !empty($data['department_id']) ? (int)$data['department_id'] : null,
            'designation_id' => !empty($data['designation_id']) ? (int)$data['designation_id'] : null,
            'level_id' => !empty($data['level_id']) ? (int)$data['level_id'] : null,
            'email' => $data['email'] ?: null,
            'mobile_number' => $data['mobile_number'] ?: null,
            'gender' => !empty($data['gender']) ? strtoupper($data['gender']) : null,
            'full_address' => $data['full_address'] ?: null,
            'join_date' => $data['join_date'] ?: null,
            'join_date_nep' => $data['join_date_nep'] ?: null,
            'dob' => $data['dob'] ?: null,
            'dob_nep' => $data['dob_nep'] ?: null,
            'initial_appointment_date' => $data['initial_appointment_date'] ?: null,
            'initial_appointment_date_nep' => $data['initial_appointment_date_nep'] ?: null,
            'retirement_date' => $data['retirement_date'] ?: null,
            'retirement_date_nep' => $data['retirement_date_nep'] ?: null,
            'citizenship_no' => $data['citizenship_no'] ?: null,
            'national_id_card_no' => $data['national_id_card_no'] ?: null,
            'pan_no' => $data['pan_no'] ?: null,
            'bank_name' => $data['bank_name'] ?: null,
            'bank_branch' => $data['bank_branch'] ?: null,
            'bank_account_number' => $data['bank_account_number'] ?: null,
            'state' => $data['state'] ?: null,
            'local_body' => $data['local_body'] ?: null,
            'ward_no' => $data['ward_no'] ?: null,
            'card_id' => $data['card_id'] ?: null,
            'is_technical' => convertToBoolean($data['is_technical'] ?? ''),
            'errors' => $row_errors,
            'warnings' => $row_warnings,
            'is_valid' => empty($row_errors)
        ];
        
        if (empty($row_errors)) {
            $valid_rows[] = $processed_data;
        } else {
            $error_rows[] = $processed_data;
        }
    }
    
    fclose($handle);
    
    // Store in session for import phase
    $_SESSION['preview_valid'] = $valid_rows;
    $_SESSION['preview_errors'] = $error_rows;
    $_SESSION['csv_filename'] = $file['name'];
    
    $preview_data = [
        'valid' => $valid_rows,
        'errors' => $error_rows,
        'total' => count($valid_rows) + count($error_rows),
        'valid_count' => count($valid_rows),
        'error_count' => count($error_rows)
    ];
    $preview_mode = true;
}

function handleImport($conn, $active_fy, $current_user_id) {
    global $success, $imported_count, $skipped_count, $errors, $warnings;
    
    if (!isset($_SESSION['preview_valid'])) {
        $errors[] = "No data to import. Please upload CSV again.";
        return;
    }
    
    $valid_rows = $_SESSION['preview_valid'];
    $imported_count = 0;
    $error_during_import = [];
    
    $conn->beginTransaction();
    
    try {
        foreach ($valid_rows as $row) {
            $code = generateEmployeeCode($conn, $row['emp_type']);
            
            // Prepare SQL with explicit boolean handling for PostgreSQL
            $sql = "INSERT INTO employee (
                code, name, name_eng, name_nep, emp_type, emp_status,
                department_id, designation_id, level_id,
                email, mobile_number, gender, full_address,
                join_date, join_date_nep, dob, dob_nep,
                initial_appointment_date, initial_appointment_date_nep,
                retirement_date, retirement_date_nep,
                citizenship_no, national_id_card_no,
                pan_no, bank_name, bank_branch, bank_account_number,
                state, local_body, ward_no, card_id,
                is_technical, fiscal_year_id,
                created_by, updated_by, created_date
            ) VALUES (
                :code, :name, :name_eng, :name_nep, :emp_type, 'ACTIVE',
                :department_id, :designation_id, :level_id,
                :email, :mobile_number, :gender, :full_address,
                :join_date, :join_date_nep, :dob, :dob_nep,
                :initial_appointment_date, :initial_appointment_date_nep,
                :retirement_date, :retirement_date_nep,
                :citizenship_no, :national_id_card_no,
                :pan_no, :bank_name, :bank_branch, :bank_account_number,
                :state, :local_body, :ward_no, :card_id,
                :is_technical, :fiscal_year_id,
                :created_by, :updated_by, NOW()
            )";
            
            $stmt = $conn->prepare($sql);
            
            // Bind parameters with proper types for PostgreSQL
            $stmt->bindValue(':code', $code);
            $stmt->bindValue(':name', $row['name']);
            $stmt->bindValue(':name_eng', $row['name_eng']);
            $stmt->bindValue(':name_nep', $row['name_nep']);
            $stmt->bindValue(':emp_type', $row['emp_type']);
            $stmt->bindValue(':department_id', $row['department_id'], $row['department_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':designation_id', $row['designation_id'], $row['designation_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':level_id', $row['level_id'], $row['level_id'] ? PDO::PARAM_INT : PDO::PARAM_NULL);
            $stmt->bindValue(':email', $row['email']);
            $stmt->bindValue(':mobile_number', $row['mobile_number']);
            $stmt->bindValue(':gender', $row['gender']);
            $stmt->bindValue(':full_address', $row['full_address']);
            $stmt->bindValue(':join_date', $row['join_date']);
            $stmt->bindValue(':join_date_nep', $row['join_date_nep']);
            $stmt->bindValue(':dob', $row['dob']);
            $stmt->bindValue(':dob_nep', $row['dob_nep']);
            $stmt->bindValue(':initial_appointment_date', $row['initial_appointment_date']);
            $stmt->bindValue(':initial_appointment_date_nep', $row['initial_appointment_date_nep']);
            $stmt->bindValue(':retirement_date', $row['retirement_date']);
            $stmt->bindValue(':retirement_date_nep', $row['retirement_date_nep']);
            $stmt->bindValue(':citizenship_no', $row['citizenship_no']);
            $stmt->bindValue(':national_id_card_no', $row['national_id_card_no']);
            $stmt->bindValue(':pan_no', $row['pan_no']);
            $stmt->bindValue(':bank_name', $row['bank_name']);
            $stmt->bindValue(':bank_branch', $row['bank_branch']);
            $stmt->bindValue(':bank_account_number', $row['bank_account_number']);
            $stmt->bindValue(':state', $row['state']);
            $stmt->bindValue(':local_body', $row['local_body']);
            $stmt->bindValue(':ward_no', $row['ward_no']);
            $stmt->bindValue(':card_id', $row['card_id']);
            // CRITICAL: Boolean binding for PostgreSQL
            $stmt->bindValue(':is_technical', $row['is_technical'], PDO::PARAM_BOOL);
            $stmt->bindValue(':fiscal_year_id', $active_fy['id'] ?? null, PDO::PARAM_INT);
            $stmt->bindValue(':created_by', $current_user_id, PDO::PARAM_INT);
            $stmt->bindValue(':updated_by', $current_user_id, PDO::PARAM_INT);
            
            $stmt->execute();
            $imported_count++;
        }
        
        $conn->commit();
        $success = true;
        $_SESSION['success_message'] = "Successfully imported $imported_count employee(s).";
        
        // Clear session data
        unset($_SESSION['preview_valid']);
        unset($_SESSION['preview_errors']);
        
    } catch (Exception $e) {
        $conn->rollBack();
        $errors[] = "Import failed: " . $e->getMessage();
        error_log("Import error: " . $e->getMessage());
    }
}

function downloadErrorCSV() {
    $error_rows = $_SESSION['preview_errors'] ?? [];
    $headers = $_SESSION['csv_headers'] ?? [];
    
    if (empty($error_rows)) return;
    
    $filename = 'import_errors_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF)); // UTF-8 BOM for Excel
    
    // Headers with error column
    $export_headers = array_merge($headers, ['error_messages', 'row_number']);
    fputcsv($output, $export_headers);
    
    foreach ($error_rows as $row) {
        $data = $row['raw_data'];
        $data[] = implode('; ', $row['errors']); // Error messages
        $data[] = $row['line_number']; // Row number
        fputcsv($output, $data);
    }
    
    fclose($output);
    exit();
}

// ================== Generate Sample CSV ==================
if (isset($_GET['download_sample'])) {
    $sample_dept = $conn->query("SELECT id FROM department WHERE status = true LIMIT 1")->fetchColumn();
    $sample_desig = $conn->query("SELECT id FROM designation WHERE status = true LIMIT 1")->fetchColumn();
    $sample_level = $conn->query("SELECT id FROM level WHERE status = true LIMIT 1")->fetchColumn();
    
    $sample_data = [
        [
            'name', 'name_eng', 'name_nep', 'emp_type', 'department_id', 'designation_id', 'level_id',
            'email', 'mobile_number', 'gender', 'full_address',
            'join_date', 'join_date_nep', 'dob', 'dob_nep',
            'citizenship_no', 'national_id_card_no',
            'pan_no', 'bank_name', 'bank_branch', 'bank_account_number',
            'state', 'local_body', 'ward_no', 'card_id', 'is_technical'
        ],
        [
            'Ram Kumar Sharma', 'Ram Kumar Sharma', 'राम कुमार शर्मा', 'PERMANENT', $sample_dept ?: '1', $sample_desig ?: '1', $sample_level ?: '1',
            'ram.sharma@company.com', '9812345678', 'MALE', 'Kathmandu-10, Baneshwor',
            '2025-05-01', '2082-01-15', '1990-05-20', '2047-02-07',
            '12-01-75-12345', 'NID123456789',
            '123456789', 'Nepal Bank Ltd', 'Kathmandu Branch', '1230000000000',
            'Bagmati', 'Kathmandu Metropolitan', '10', 'CARD001', 'No'
        ],
        [
            'Sita Devi Thapa', 'Sita Devi Thapa', 'सीता देवी थापा', 'CONTRACT', $sample_dept ?: '1', $sample_desig ?: '1', $sample_level ?: '1',
            'sita.thapa@company.com', '9823456789', 'FEMALE', 'Pokhara-5, Lakeside',
            '2025-05-01', '2082-01-15', '1992-08-15', '2049-04-32',
            '12-02-76-23456', 'NID234567890',
            '234567890', 'Himalayan Bank', 'Pokhara Branch', '1230000000001',
            'Gandaki', 'Pokhara Metropolitan', '5', 'CARD002', 'Yes'
        ]
    ];

    $filename = 'employee_import_template_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    foreach ($sample_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// ================== Download Reference Data ==================
if (isset($_GET['download_reference'])) {
    $filename = 'reference_data_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    fputcsv($output, ['=== DEPARTMENTS ===']);
    fputcsv($output, ['ID', 'Name', 'Sub Department']);
    $depts = $conn->query("SELECT id, name, sub_department_name FROM department WHERE status = true ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($depts as $d) {
        fputcsv($output, [$d['id'], $d['name'], $d['sub_department_name'] ?? '']);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['=== DESIGNATIONS ===']);
    fputcsv($output, ['ID', 'Name']);
    $desigs = $conn->query("SELECT id, name FROM designation WHERE status = true ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($desigs as $d) {
        fputcsv($output, [$d['id'], $d['name']]);
    }
    
    fputcsv($output, []);
    fputcsv($output, ['=== LEVELS ===']);
    fputcsv($output, ['ID', 'Name']);
    $levels = $conn->query("SELECT id, name FROM level WHERE status = true ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($levels as $l) {
        fputcsv($output, [$l['id'], $l['name']]);
    }
    
    fclose($output);
    exit();
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Bulk Import Employees</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <style>
        .feature-badge {
            background: #e7f3ff;
            color: #0066cc;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            display: inline-block;
            margin-right: 0.5rem;
        }
        .field-list { column-count: 2; }
        .field-list li { break-inside: avoid; }
        .alert-pre {
            white-space: pre-wrap;
            font-family: monospace;
            font-size: 0.9em;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            border-left: 4px solid #dc3545;
        }
        .preview-valid { background-color: #d4edda !important; }
        .preview-error { background-color: #f8d7da !important; }
        .preview-warning { background-color: #fff3cd !important; }
        .nepali-text {
            font-family: 'Kalimati', 'Noto Sans Devanagari', 'Mangal', sans-serif;
            font-size: 1.1em;
        }
        .table-sm td, .table-sm th {
            font-size: 0.85rem;
            vertical-align: middle;
        }
        .sticky-top { top: 20px; }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-import"></i> Bulk Import Employees</h2>
                <p class="text-muted mb-0">Import multiple employees with preview and error handling</p>
            </div>
            <a href="index_enhanced.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <h5><i class="fas fa-check-circle"></i> Import Completed!</h5>
                <p class="mb-0">Successfully imported <strong><?= $imported_count ?></strong> employee(s).</p>
                <a href="index_enhanced.php" class="btn btn-primary mt-2">View Employees</a>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Active Fiscal Year Info -->
        <?php if ($active_fy): ?>
            <div class="alert alert-info">
                <i class="fas fa-calendar-alt"></i> 
                <strong>Active Fiscal Year:</strong> <?= htmlspecialchars($active_fy['fiscal_code']) ?>
                (All imported employees will be assigned to this fiscal year)
            </div>
        <?php else: ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle"></i> 
                <strong>Warning:</strong> No active fiscal year found. Please activate a fiscal year before importing.
            </div>
        <?php endif; ?>

        <?php if (!empty($errors) && !$preview_mode): ?>
            <div class="alert alert-danger">
                <h6><i class="fas fa-exclamation-triangle"></i> Errors</h6>
                <div class="alert-pre"><?php 
                    foreach ($errors as $error) {
                        echo htmlspecialchars($error) . "\n";
                    }
                ?></div>
            </div>
        <?php endif; ?>

        <?php if ($preview_mode): ?>
            <!-- PREVIEW MODE -->
            <div class="card border-primary mb-4">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-eye"></i> Import Preview: <?= htmlspecialchars($_SESSION['csv_filename'] ?? '') ?></h5>
                    <span class="badge bg-light text-primary">
                        Valid: <?= $preview_data['valid_count'] ?> | Errors: <?= $preview_data['error_count'] ?>
                    </span>
                </div>
                <div class="card-body">
                    <?php if ($preview_data['error_count'] > 0): ?>
                        <div class="alert alert-warning">
                            <i class="fas fa-exclamation-circle"></i> 
                            <strong><?= $preview_data['error_count'] ?> row(s)</strong> have errors and will be skipped unless fixed.
                            <a href="?download_errors=1" class="btn btn-sm btn-warning ms-2">
                                <i class="fas fa-download"></i> Download Error Rows
                            </a>
                        </div>
                    <?php endif; ?>

                    <?php if ($preview_data['valid_count'] > 0): ?>
                        <div class="alert alert-success">
                            <i class="fas fa-check-circle"></i> 
                            <strong><?= $preview_data['valid_count'] ?> row(s)</strong> are ready to import.
                        </div>
                        
                        <form method="POST" class="mb-3">
                            <input type="hidden" name="action" value="import">
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-upload"></i> Import <?= $preview_data['valid_count'] ?> Valid Row(s)
                            </button>
                            <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary btn-lg ms-2">
                                <i class="fas fa-times"></i> Cancel & Start Over
                            </a>
                        </form>
                    <?php else: ?>
                        <div class="alert alert-danger">
                            <i class="fas fa-times-circle"></i> No valid rows found. Please fix errors and re-upload.
                        </div>
                    <?php endif; ?>

                    <!-- Preview Table -->
                    <div class="table-responsive" style="max-height: 600px; overflow-y: auto;">
                        <table class="table table-sm table-bordered table-hover">
                            <thead class="table-dark sticky-top">
                                <tr>
                                    <th width="50">#</th>
                                    <th>Status</th>
                                    <th>Name (English)</th>
                                    <th>Name (Nepali)</th>
                                    <th>Type</th>
                                    <th>Dept/Desig/Lvl</th>
                                    <th>Contact</th>
                                    <th>Technical</th>
                                    <th>Errors/Warnings</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php 
                                $counter = 1;
                                foreach ($preview_data['valid'] as $row): 
                                ?>
                                    <tr class="preview-valid">
                                        <td><?= $counter++ ?></td>
                                        <td><span class="badge bg-success">Valid</span></td>
                                        <td><?= htmlspecialchars($row['name_eng']) ?></td>
                                        <td class="nepali-text"><?= htmlspecialchars($row['name_nep'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['emp_type']) ?></td>
                                        <td>
                                            <?= $row['department_id'] ? 'D:'.$row['department_id'] : '' ?>
                                            <?= $row['designation_id'] ? 'Ds:'.$row['designation_id'] : '' ?>
                                            <?= $row['level_id'] ? 'L:'.$row['level_id'] : '' ?>
                                        </td>
                                        <td>
                                            <?= $row['email'] ? '<i class="fas fa-envelope"></i> '.htmlspecialchars($row['email']).'<br>' : '' ?>
                                            <?= $row['mobile_number'] ? '<i class="fas fa-phone"></i> '.htmlspecialchars($row['mobile_number']) : '' ?>
                                        </td>
                                        <td><?= $row['is_technical'] ? '<span class="badge bg-info">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?></td>
                                        <td>
                                            <?php foreach ($row['warnings'] as $warning): ?>
                                                <span class="badge bg-warning text-dark"><?= htmlspecialchars($warning) ?></span>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                
                                <?php foreach ($preview_data['errors'] as $row): ?>
                                    <tr class="preview-error">
                                        <td><?= $counter++ ?></td>
                                        <td><span class="badge bg-danger">Error</span></td>
                                        <td><?= htmlspecialchars($row['name_eng']) ?></td>
                                        <td class="nepali-text"><?= htmlspecialchars($row['name_nep'] ?? '-') ?></td>
                                        <td><?= htmlspecialchars($row['emp_type']) ?></td>
                                        <td>
                                            <?= $row['department_id'] ? 'D:'.$row['department_id'] : '' ?>
                                            <?= $row['designation_id'] ? 'Ds:'.$row['designation_id'] : '' ?>
                                            <?= $row['level_id'] ? 'L:'.$row['level_id'] : '' ?>
                                        </td>
                                        <td>
                                            <?= $row['email'] ? htmlspecialchars($row['email']).'<br>' : '' ?>
                                            <?= $row['mobile_number'] ? htmlspecialchars($row['mobile_number']) : '' ?>
                                        </td>
                                        <td><?= $row['is_technical'] ? 'Yes' : 'No' ?></td>
                                        <td>
                                            <?php foreach ($row['errors'] as $error): ?>
                                                <div class="text-danger small"><i class="fas fa-times"></i> <?= htmlspecialchars($error) ?></div>
                                            <?php endforeach; ?>
                                            <?php foreach ($row['warnings'] as $warning): ?>
                                                <div class="text-warning small"><i class="fas fa-exclamation"></i> <?= htmlspecialchars($warning) ?></div>
                                            <?php endforeach; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- UPLOAD MODE -->
            
            <!-- Download Section -->
            <div class="row mb-4">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5><i class="fas fa-download"></i> Step 1: Download Template</h5>
                            <p>Download CSV template with sample data including Nepali text.</p>
                            <a href="?download_sample" class="btn btn-primary w-100">
                                <i class="fas fa-file-csv"></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-body">
                            <h5><i class="fas fa-database"></i> Step 2: Get Reference IDs</h5>
                            <p>Download list of valid Department, Designation, and Level IDs.</p>
                            <a href="?download_reference" class="btn btn-info w-100">
                                <i class="fas fa-table"></i> Download Reference Data
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Field Information -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle"></i> Import Fields & Validation</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><span class="feature-badge">✓ REQUIRED</span> Required Fields</h6>
                            <ul class="small">
                                <li><strong>name:</strong> Employee full name</li>
                                <li><strong>emp_type:</strong> PERMANENT, CONTRACT, or DAILY_WAGES</li>
                            </ul>
                            
                            <h6 class="mt-3"><span class="feature-badge">AUTO</span> Auto-Generated</h6>
                            <ul class="small">
                                <li><strong>code:</strong> Auto-generated (EMP-P-0001, etc.)</li>
                                <li><strong>fiscal_year_id:</strong> Set to active fiscal year</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><span class="feature-badge">✓ OPTIONAL</span> Optional Fields</h6>
                            <ul class="small field-list">
                                <li>name_eng, <strong>name_nep</strong> (Nepali Unicode supported)</li>
                                <li>department_id, designation_id, level_id</li>
                                <li>email, mobile_number</li>
                                <li>gender (MALE/FEMALE/OTHER)</li>
                                <li>full_address</li>
                                <li>join_date (YYYY-MM-DD or MM/DD/YYYY)</li>
                                <li>join_date_nep (Nepali date)</li>
                                <li>dob, dob_nep</li>
                                <li>citizenship_no, national_id_card_no</li>
                                <li>pan_no</li>
                                <li>bank_name, bank_branch, bank_account_number</li>
                                <li>state, local_body, ward_no</li>
                                <li>card_id</li>
                                <li><strong>is_technical:</strong> Yes/No/True/False/1/0</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Form -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-upload"></i> Step 3: Upload CSV for Preview</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="action" value="preview">
                        
                        <div class="mb-3">
                            <label class="form-label">Select CSV File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control form-control-lg" name="csv_file" accept=".csv" required>
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i> 
                                Maximum 5MB. UTF-8 encoding required for Nepali text. 
                                Dates: YYYY-MM-DD or MM/DD/YYYY.
                            </div>
                        </div>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-eye"></i> Preview Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
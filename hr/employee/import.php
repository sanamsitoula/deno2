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
    return in_array(strtoupper($type), ['PERMANENT', 'CONTRACT', 'DAILY_WAGES']);
}

function validateGender($gender) {
    if (empty($gender)) return true;
    return in_array(strtoupper($gender), ['MALE', 'FEMALE', 'OTHER']);
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

// FIXED: Proper boolean conversion
function convertToBoolean($value) {
    // If it's already boolean, return it
    if (is_bool($value)) {
        return $value;
    }
    
    // If it's null or empty string, return false (default)
    if (is_null($value) || (is_string($value) && trim($value) === '')) {
        return false;
    }
    
    // Convert to string and lowercase for comparison
    $value = strtolower(trim((string)$value));
    
    // Check for true values
    if (in_array($value, ['true', 'yes', '1', 't', 'y'])) {
        return true;
    }
    
    // Check for false values
    if (in_array($value, ['false', 'no', '0', 'f', 'n'])) {
        return false;
    }
    
    // If numeric, 1 is true, 0 is false
    if (is_numeric($value)) {
        return (int)$value != 0;
    }
    
    // Default to false
    return false;
}

// Fix date format from MM/DD/YYYY to YYYY-MM-DD
function fixDateFormat($dateStr) {
    if (empty(trim($dateStr))) {
        return null;
    }
    
    $dateStr = trim($dateStr);
    
    // Check if already in YYYY-MM-DD format
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        return $dateStr;
    }
    
    // Try MM/DD/YYYY format (from your CSV)
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
        $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        
        // Validate date
        if (checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day";
        }
    }
    
    // Return original if we can't parse it
    return $dateStr;
}

// ================== Handle CSV Upload ==================
$errors = [];
$warnings = [];
$success = false;
$imported_count = 0;
$skipped_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    $file = $_FILES['csv_file'];

    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error. Error code: " . $file['error'];
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = "File size exceeds 5MB limit.";
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $errors[] = "Only CSV files are allowed.";
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                $errors[] = "Could not read uploaded file.";
            } else {
                // Read first line to check for BOM
                $firstLine = fgets($handle);
                rewind($handle);
                
                // Remove BOM if present
                if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
                    fseek($handle, 3);
                }
                
                $headers = fgetcsv($handle);
                if ($headers === false) {
                    $errors[] = "Could not read CSV headers.";
                    fclose($handle);
                } else {
                    // Clean headers
                    $headers = array_map(function($header) {
                        return strtolower(trim($header));
                    }, $headers);
                    
                    // Debug: Show headers
                    error_log("CSV Headers: " . implode(", ", $headers));
                    
                    // Required fields
                    $required = ['name', 'emp_type'];

                    foreach ($required as $col) {
                        if (!in_array($col, $headers)) {
                            $errors[] = "Missing required column: '$col'";
                        }
                    }

                    if (empty($errors)) {
                        $conn->beginTransaction();
                        try {
                            $line_number = 1;
                            
                            while (($row = fgetcsv($handle)) !== FALSE) {
                                $line_number++;
                                
                                if (empty(array_filter($row))) {
                                    continue;
                                }
                                
                                // Pad row to match headers length
                                while (count($row) < count($headers)) {
                                    $row[] = '';
                                }
                                
                                $data = array_combine($headers, array_slice($row, 0, count($headers)));
                                $data = array_map('trim', $data);
                                
                                $row_errors = [];
                                
                                // Validate required fields
                                if (empty($data['name'])) {
                                    $row_errors[] = "Line $line_number: Name is required";
                                }
                                
                                if (empty($data['emp_type']) || !validateEmployeeType($data['emp_type'])) {
                                    $row_errors[] = "Line $line_number: Invalid emp_type. Must be: PERMANENT, CONTRACT, or DAILY_WAGES";
                                }
                                
                                // Validate optional fields
                                if (!empty($data['gender']) && !validateGender($data['gender'])) {
                                    $row_errors[] = "Line $line_number: Invalid gender. Must be: MALE, FEMALE, or OTHER";
                                }
                                
                                if (!empty($data['email']) && !validateEmail($data['email'])) {
                                    $row_errors[] = "Line $line_number: Invalid email format";
                                }
                                
                                if (!empty($data['mobile_number']) && !validateMobile($data['mobile_number'])) {
                                    $warnings[] = "Line $line_number: Mobile number doesn't match Nepal format (98XXXXXXXX)";
                                }
                                
                                // Fix date formats BEFORE validation
                                foreach (['join_date', 'dob', 'initial_appointment_date', 'retirement_date'] as $date_field) {
                                    if (!empty($data[$date_field])) {
                                        $fixedDate = fixDateFormat($data[$date_field]);
                                        $data[$date_field] = $fixedDate;
                                        
                                        if (!validateDate($fixedDate)) {
                                            $row_errors[] = "Line $line_number: Invalid $date_field format. Use YYYY-MM-DD. Got: " . $data[$date_field];
                                        }
                                    }
                                }
                                
                                // Validate references
                                if (!empty($data['department_id']) && !validateReference($conn, 'department', $data['department_id'])) {
                                    $row_errors[] = "Line $line_number: Invalid department_id";
                                }
                                
                                if (!empty($data['designation_id']) && !validateReference($conn, 'designation', $data['designation_id'])) {
                                    $row_errors[] = "Line $line_number: Invalid designation_id";
                                }
                                
                                if (!empty($data['level_id']) && !validateReference($conn, 'level', $data['level_id'])) {
                                    $row_errors[] = "Line $line_number: Invalid level_id";
                                }
                                
                                if (!empty($row_errors)) {
                                    $errors = array_merge($errors, $row_errors);
                                    $skipped_count++;
                                    continue;
                                }
                                
                                // DEBUG: Check is_technical value
                                $is_technical_raw = $data['is_technical'] ?? '';
                                $is_technical = convertToBoolean($is_technical_raw);
                                error_log("Line $line_number - is_technical raw: '$is_technical_raw' -> converted: " . ($is_technical ? 'true' : 'false'));
                                
                                // Prepare parameters - ensure proper types
                                $params = [
                                    ':name' => $data['name'],
                                    ':name_eng' => isset($data['name_eng']) && $data['name_eng'] !== '' ? $data['name_eng'] : $data['name'],
                                    ':name_nep' => isset($data['name_nep']) && $data['name_nep'] !== '' ? $data['name_nep'] : null,
                                    ':emp_type' => strtoupper($data['emp_type']),
                                    ':department_id' => isset($data['department_id']) && $data['department_id'] !== '' ? (int)$data['department_id'] : null,
                                    ':designation_id' => isset($data['designation_id']) && $data['designation_id'] !== '' ? (int)$data['designation_id'] : null,
                                    ':level_id' => isset($data['level_id']) && $data['level_id'] !== '' ? (int)$data['level_id'] : null,
                                    ':email' => isset($data['email']) && $data['email'] !== '' ? $data['email'] : null,
                                    ':mobile_number' => isset($data['mobile_number']) && $data['mobile_number'] !== '' ? $data['mobile_number'] : null,
                                    ':gender' => isset($data['gender']) && $data['gender'] !== '' ? strtoupper($data['gender']) : null,
                                    ':full_address' => isset($data['full_address']) && $data['full_address'] !== '' ? $data['full_address'] : null,
                                    ':join_date' => isset($data['join_date']) && $data['join_date'] !== '' ? $data['join_date'] : null,
                                    ':join_date_nep' => isset($data['join_date_nep']) && $data['join_date_nep'] !== '' ? $data['join_date_nep'] : null,
                                    ':dob' => isset($data['dob']) && $data['dob'] !== '' ? $data['dob'] : null,
                                    ':dob_nep' => isset($data['dob_nep']) && $data['dob_nep'] !== '' ? $data['dob_nep'] : null,
                                    ':initial_appointment_date' => isset($data['initial_appointment_date']) && $data['initial_appointment_date'] !== '' ? $data['initial_appointment_date'] : null,
                                    ':initial_appointment_date_nep' => isset($data['initial_appointment_date_nep']) && $data['initial_appointment_date_nep'] !== '' ? $data['initial_appointment_date_nep'] : null,
                                    ':retirement_date' => isset($data['retirement_date']) && $data['retirement_date'] !== '' ? $data['retirement_date'] : null,
                                    ':retirement_date_nep' => isset($data['retirement_date_nep']) && $data['retirement_date_nep'] !== '' ? $data['retirement_date_nep'] : null,
                                    ':citizenship_no' => isset($data['citizenship_no']) && $data['citizenship_no'] !== '' ? $data['citizenship_no'] : null,
                                    ':national_id_card_no' => isset($data['national_id_card_no']) && $data['national_id_card_no'] !== '' ? $data['national_id_card_no'] : null,
                                    ':pan_no' => isset($data['pan_no']) && $data['pan_no'] !== '' ? $data['pan_no'] : null,
                                    ':bank_name' => isset($data['bank_name']) && $data['bank_name'] !== '' ? $data['bank_name'] : null,
                                    ':bank_branch' => isset($data['bank_branch']) && $data['bank_branch'] !== '' ? $data['bank_branch'] : null,
                                    ':bank_account_number' => isset($data['bank_account_number']) && $data['bank_account_number'] !== '' ? $data['bank_account_number'] : null,
                                    ':state' => isset($data['state']) && $data['state'] !== '' ? $data['state'] : null,
                                    ':local_body' => isset($data['local_body']) && $data['local_body'] !== '' ? $data['local_body'] : null,
                                    ':ward_no' => isset($data['ward_no']) && $data['ward_no'] !== '' ? $data['ward_no'] : null,
                                    ':card_id' => isset($data['card_id']) && $data['card_id'] !== '' ? $data['card_id'] : null,
                                    ':is_technical' => $is_technical,
                                    ':fiscal_year_id' => $active_fy['id'] ?? null,
                                    ':created_by' => $current_user_id,
                                    ':updated_by' => $current_user_id
                                ];
                                
                                // Insert employee
                                $stmt = $conn->prepare("
                                    INSERT INTO employee (
                                        name, name_eng, name_nep, emp_type, emp_status,
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
                                        :name, :name_eng, :name_nep, :emp_type, 'ACTIVE',
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
                                    )
                                ");
                                
                                // Debug: Log the is_technical parameter
                                error_log("Inserting - is_technical: " . ($params[':is_technical'] ? 'true' : 'false') . " (type: " . gettype($params[':is_technical']) . ")");
                                
                                try {
                                    $stmt->execute($params);
                                    $imported_count++;
                                } catch (Exception $e) {
                                    // Log detailed error for this row
                                    $row_error = "Line $line_number: Failed to insert - " . $e->getMessage();
                                    $row_error .= "\nData: " . print_r($params, true);
                                    error_log($row_error);
                                    $errors[] = "Line $line_number: " . $e->getMessage();
                                    $skipped_count++;
                                }
                            }

                            if (empty($errors)) {
                                $conn->commit();
                                $_SESSION['success_message'] = "Successfully imported $imported_count employee(s).";
                                $success = true;
                            } else {
                                $conn->rollBack();
                            }
                            
                        } catch (Exception $e) {
                            $conn->rollBack();
                            $errors[] = "Import failed: " . $e->getMessage();
                            error_log("Import error: " . $e->getMessage());
                        }
                    }
                    fclose($handle);
                }
            }
        }
    }
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
            '2025-05-01', '5/1/2025', '1990-05-20', '2047-02-07',
            '12-01-75-12345', 'NID123456789',
            '123456789', 'Nepal Bank Ltd', 'Kathmandu Branch', '1230000000000',
            'Bagmati', 'Kathmandu Metropolitan', '10', 'CARD001', 'No'
        ],
        [
            'Sita Devi Thapa', 'Sita Devi Thapa', 'सीता देवी थापा', 'CONTRACT', $sample_dept ?: '1', $sample_desig ?: '1', $sample_level ?: '1',
            'sita.thapa@company.com', '9823456789', 'FEMALE', 'Pokhara-5, Lakeside',
            '2025-05-01', '5/1/2025', '1992-08-15', '2049-04-32',
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
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <div>
                <h2><i class="fas fa-file-import"></i> Bulk Import Employees</h2>
                <p class="text-muted mb-0">Import multiple employees with complete information</p>
            </div>
            <a href="index_enhanced.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
        </div>

        <?php if ($success): ?>
            <div class="alert alert-success">
                <h5><i class="fas fa-check-circle"></i> Import Completed!</h5>
                <p class="mb-0">Successfully imported <strong><?= $imported_count ?></strong> employee(s).</p>
                <?php if ($skipped_count > 0): ?>
                    <p class="mb-0">Skipped <strong><?= $skipped_count ?></strong> row(s) due to errors.</p>
                <?php endif; ?>
                <a href="index_enhanced.php" class="btn btn-primary mt-2">View Employees</a>
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

        <!-- Important Note about CSV Format -->
        <div class="alert alert-warning">
            <h5><i class="fas fa-exclamation-circle"></i> Important Note:</h5>
            <p>Your CSV file must follow these formats:</p>
            <ul class="mb-0">
                <li><strong>Date formats:</strong> MM/DD/YYYY (e.g., 5/1/2025) will be automatically converted to YYYY-MM-DD</li>
                <li><strong>is_technical field:</strong> Accepts "Yes", "No", "True", "False", "1", "0" (case-insensitive)</li>
                <li><strong>Boolean fields:</strong> Empty values are treated as "No"/"False"</li>
            </ul>
        </div>

        <!-- Download Section -->
        <div class="row mb-4">
            <div class="col-md-6">
                <div class="card h-100">
                    <div class="card-body">
                        <h5><i class="fas fa-download"></i> Step 1: Download Template</h5>
                        <p>Download CSV template with sample data and all required fields.</p>
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
                            <li><strong>code:</strong> Auto-generated (EMP-P-0001, EMP-C-0001, EMP-DW-0001)</li>
                            <li><strong>fiscal_year_id:</strong> Set to active fiscal year automatically</li>
                        </ul>
                    </div>
                    <div class="col-md-6">
                        <h6><span class="feature-badge">✓ OPTIONAL</span> Optional Fields</h6>
                        <ul class="small field-list">
                            <li>name_eng, name_nep</li>
                            <li>department_id, designation_id, level_id</li>
                            <li>email, mobile_number</li>
                            <li>gender (MALE/FEMALE/OTHER)</li>
                            <li>full_address</li>
                            <li>join_date, join_date_nep</li>
                            <li>dob, dob_nep</li>
                            <li>citizenship_no, national_id_card_no</li>
                            <li>pan_no</li>
                            <li>bank_name, bank_branch, bank_account_number</li>
                            <li>state, local_body, ward_no</li>
                            <li>card_id</li>
                            <li><strong>is_technical:</strong> Yes/No/True/False/1/0 (case-insensitive)</li>
                        </ul>
                    </div>
                </div>
            </div>
        </div>

        <!-- Upload Form -->
        <?php if (!$success): ?>
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-upload"></i> Step 3: Upload CSV File</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data">
                        <div class="mb-3">
                            <label class="form-label">Select CSV File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control form-control-lg" name="csv_file" accept=".csv" required>
                            <div class="form-text">
                                <i class="fas fa-info-circle"></i> Maximum 5MB. Only CSV files accepted. Dates in MM/DD/YYYY format will be automatically converted.
                            </div>
                        </div>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <h6><i class="fas fa-exclamation-triangle"></i> Errors (<?= count($errors) ?>)</h6>
                                <div class="alert-pre"><?php 
                                    foreach ($errors as $error) {
                                        echo htmlspecialchars($error) . "\n";
                                    }
                                ?></div>
                            </div>
                        <?php endif; ?>

                        <?php if (!empty($warnings)): ?>
                            <div class="alert alert-warning">
                                <h6><i class="fas fa-exclamation-circle"></i> Warnings (<?= count($warnings) ?>)</h6>
                                <ul class="mb-0">
                                    <?php foreach (array_slice($warnings, 0, 10) as $warning): ?>
                                        <li><?= htmlspecialchars($warning) ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>

                        <div class="d-flex justify-content-end gap-2">
                            <button type="reset" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Reset
                            </button>
                            <button type="submit" class="btn btn-success btn-lg">
                                <i class="fas fa-upload"></i> Start Import
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
<?php
ob_start();
session_start();

// [cite: 3] Database Configuration & Header
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Authorization Check
if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;

// Clear previous session data if it's a fresh page load
if ($_SERVER['REQUEST_METHOD'] === 'GET' && !isset($_GET['download_errors']) && !isset($_GET['download_sample']) && !isset($_GET['download_reference'])) {
    unset($_SESSION['import_stage']);
    unset($_SESSION['import_data']);
    unset($_SESSION['import_errors']);
}

// ================== 1. HELPER FUNCTIONS ==================

// Robust Boolean Conversion
function convertToBoolean($value) {
    if (is_bool($value)) return $value;
    if (is_null($value) || (is_string($value) && trim($value) === '')) return false;
    
    $value = strtolower(trim((string)$value));
    if (in_array($value, ['true', 'yes', '1', 't', 'y', 'on'])) return true;
    return false;
}

// Date Formatter (MM/DD/YYYY -> YYYY-MM-DD)
function fixDateFormat($dateStr) {
    if (empty(trim($dateStr))) return null;
    $dateStr = trim($dateStr);
    
    // YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) return $dateStr;
    
    // MM/DD/YYYY
    if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateStr, $matches)) {
        return sprintf("%04d-%02d-%02d", $matches[3], $matches[1], $matches[2]);
    }
    return $dateStr; // Return original to fail validation later if invalid
}

function validateDate($date) {
    if (empty($date)) return true;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

// Optimized Pre-fetching for Reference IDs (Prevents N+1 queries)
function getValidIds($conn, $table) {
    $stmt = $conn->query("SELECT id FROM $table WHERE status = true");
    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

// ================== 2. HANDLE DOWNLOADS ==================

// Download Sample CSV
if (isset($_GET['download_sample'])) {
    // [cite: 122, 125]
    $filename = 'employee_import_template.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, "\xEF\xBB\xBF"); // UTF-8 BOM for Nepali support
    
    // [cite: 125] Headers
    fputcsv($output, [
        'name', 'name_eng', 'name_nep', 'emp_type', 'department_id', 'designation_id', 'level_id',
        'email', 'mobile_number', 'gender', 'full_address',
        'join_date', 'join_date_nep', 'dob', 'dob_nep',
        'citizenship_no', 'national_id_card_no',
        'pan_no', 'bank_name', 'bank_branch', 'bank_account_number',
        'state', 'local_body', 'ward_no', 'card_id', 'is_technical'
    ]);
    
    // Sample Data
    fputcsv($output, [
        'Ram Sharma', 'Ram Sharma', 'राम शर्मा', 'PERMANENT', '1', '1', '1',
        'ram@example.com', '9841000000', 'MALE', 'Kathmandu',
        '2025-01-01', '2081-09-17', '1990-01-01', '2046-09-17',
        '123-456', 'NID123', 'PAN123', 'NIC Asia', 'Ktm', '00112233',
        'Bagmati', 'KMC', '10', 'CARD001', 'Yes'
    ]);
    
    fclose($output);
    exit();
}

// Download Error Log [cite: 1]
if (isset($_GET['download_errors']) && isset($_SESSION['import_errors'])) {
    $filename = 'import_errors_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    fprintf($output, "\xEF\xBB\xBF"); // BOM
    
    // Get headers from the first error row if available, prepend 'Error Message'
    $first_error = reset($_SESSION['import_errors']);
    $headers = array_keys($first_error['data']);
    array_unshift($headers, 'Row Line', 'Error Message');
    
    fputcsv($output, $headers);
    
    foreach ($_SESSION['import_errors'] as $error) {
        $row = $error['data'];
        array_unshift($row, $error['line'], $error['msg']);
        fputcsv($output, $row);
    }
    
    fclose($output);
    exit();
}

// ================== 3. PROCESSING LOGIC ==================

$valid_rows = [];
$error_rows = [];
$stats = ['total' => 0, 'valid' => 0, 'invalid' => 0];
$msg = '';
$msg_type = '';

// Get Active Fiscal Year [cite: 4, 5]
$active_fy_stmt = $conn->query("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
$active_fy = $active_fy_stmt->fetch(PDO::FETCH_ASSOC);

// --- STAGE 1: UPLOAD & PREVIEW ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'preview') {
    
    if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
        $msg = "Please upload a valid CSV file.";
        $msg_type = "danger";
    } else {
        $file = $_FILES['csv_file'];
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        
        if ($ext !== 'csv') {
            $msg = "Only CSV files are allowed.";
            $msg_type = "danger";
        } else {
            // Pre-fetch reference IDs for validation
            $valid_depts = getValidIds($conn, 'department');
            $valid_desigs = getValidIds($conn, 'designation');
            $valid_levels = getValidIds($conn, 'level');
            
            $handle = fopen($file['tmp_name'], 'r');
            
            // Handle BOM [cite: 34]
            $firstLine = fgets($handle);
            if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
                $firstLine = substr($firstLine, 3);
            }
            // Reset pointer, but we need to handle the first line manually if we read it
            rewind($handle);
            if (substr(fgets($handle), 0, 3) === "\xEF\xBB\xBF") {
                fseek($handle, 3);
            } else {
                rewind($handle);
            }

            $headers = fgetcsv($handle);
            // Clean headers [cite: 37]
            $headers = array_map(function($h) { return strtolower(trim($h)); }, $headers);
            
            $line_number = 1;
            
            while (($row = fgetcsv($handle)) !== FALSE) {
                $line_number++;
                if (empty(array_filter($row))) continue; // Skip empty lines
                
                // Pad row [cite: 46]
                while (count($row) < count($headers)) $row[] = '';
                
                $data = array_combine($headers, array_slice($row, 0, count($headers)));
                $data = array_map('trim', $data);
                
                $row_errors = [];
                
                // --- Validations ---
                
                // Required Fields [cite: 48, 49]
                if (empty($data['name'])) $row_errors[] = "Name is required";
                if (empty($data['emp_type']) || !in_array(strtoupper($data['emp_type']), ['PERMANENT', 'CONTRACT', 'DAILY_WAGES'])) {
                    $row_errors[] = "Invalid emp_type";
                }
                
                // Dates [cite: 58]
                $date_fields = ['join_date', 'dob', 'initial_appointment_date', 'retirement_date'];
                foreach ($date_fields as $df) {
                    if (!empty($data[$df])) {
                        $fixed = fixDateFormat($data[$df]);
                        if (!validateDate($fixed)) {
                            $row_errors[] = "Invalid $df (Format: YYYY-MM-DD)";
                        } else {
                            $data[$df] = $fixed; // Update with fixed format
                        }
                    }
                }
                
                // References [cite: 61, 63, 65]
                if (!empty($data['department_id']) && !in_array($data['department_id'], $valid_depts)) 
                    $row_errors[] = "Invalid Department ID";
                if (!empty($data['designation_id']) && !in_array($data['designation_id'], $valid_desigs)) 
                    $row_errors[] = "Invalid Designation ID";
                if (!empty($data['level_id']) && !in_array($data['level_id'], $valid_levels)) 
                    $row_errors[] = "Invalid Level ID";

                // Booleans [cite: 69]
                $data['is_technical'] = convertToBoolean($data['is_technical'] ?? false);

                // --- Sort Result ---
                if (!empty($row_errors)) {
                    $error_rows[] = [
                        'line' => $line_number,
                        'msg' => implode(", ", $row_errors),
                        'data' => $data // Keep original data for download
                    ];
                } else {
                    $valid_rows[] = $data;
                }
            }
            fclose($handle);
            
            // Store results in Session 
            $_SESSION['import_stage'] = 'preview';
            $_SESSION['import_data'] = $valid_rows;
            $_SESSION['import_errors'] = $error_rows;
            $stats['total'] = count($valid_rows) + count($error_rows);
            $stats['valid'] = count($valid_rows);
            $stats['invalid'] = count($error_rows);
        }
    }
}

// --- STAGE 2: CONFIRM IMPORT ---
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'confirm') {
    if (isset($_SESSION['import_data']) && !empty($_SESSION['import_data'])) {
        $valid_rows = $_SESSION['import_data'];
        $imported_count = 0;
        
        $conn->beginTransaction();
        try {
            // [cite: 101] Prepared Statement for Postgres
            $sql = "INSERT INTO employee (
                        name, name_eng, name_nep, emp_type, emp_status,
                        department_id, designation_id, level_id,
                        email, mobile_number, gender, full_address,
                        join_date, join_date_nep, dob, dob_nep,
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
                        :citizenship_no, :national_id_card_no,
                        :pan_no, :bank_name, :bank_branch, :bank_account_number,
                        :state, :local_body, :ward_no, :card_id,
                        :is_technical, :fiscal_year_id,
                        :created_by, :updated_by, NOW()
                    )";
            
            $stmt = $conn->prepare($sql);
            
            foreach ($valid_rows as $row) {
                $stmt->bindValue(':name', $row['name']);
                $stmt->bindValue(':name_eng', $row['name_eng'] ?: $row['name']);
                $stmt->bindValue(':name_nep', $row['name_nep'] ?: null);
                $stmt->bindValue(':emp_type', strtoupper($row['emp_type']));
                
                // Handle Integers
                $stmt->bindValue(':department_id', !empty($row['department_id']) ? $row['department_id'] : null, PDO::PARAM_INT);
                $stmt->bindValue(':designation_id', !empty($row['designation_id']) ? $row['designation_id'] : null, PDO::PARAM_INT);
                $stmt->bindValue(':level_id', !empty($row['level_id']) ? $row['level_id'] : null, PDO::PARAM_INT);
                
                // Strings
                $fields = ['email', 'mobile_number', 'gender', 'full_address', 'join_date', 'join_date_nep', 'dob', 'dob_nep', 
                           'citizenship_no', 'national_id_card_no', 'pan_no', 'bank_name', 'bank_branch', 'bank_account_number',
                           'state', 'local_body', 'ward_no', 'card_id'];
                
                foreach($fields as $f) {
                    $stmt->bindValue(":$f", !empty($row[$f]) ? $row[$f] : null);
                }
                
                // [cite: 113] Boolean Handling for Postgres
                $stmt->bindValue(':is_technical', $row['is_technical'], PDO::PARAM_BOOL);
                
                // System Fields
                $stmt->bindValue(':fiscal_year_id', $active_fy['id'] ?? null, PDO::PARAM_INT);
                $stmt->bindValue(':created_by', $current_user_id, PDO::PARAM_INT);
                $stmt->bindValue(':updated_by', $current_user_id, PDO::PARAM_INT);
                
                $stmt->execute();
                $imported_count++;
            }
            
            $conn->commit();
            
            // Clean Session
            unset($_SESSION['import_stage']);
            unset($_SESSION['import_data']);
            unset($_SESSION['import_errors']);
            
            $msg = "Success! Imported $imported_count employees.";
            $msg_type = "success";
            
        } catch (Exception $e) {
            $conn->rollBack();
            $msg = "Database Error: " . $e->getMessage();
            $msg_type = "danger";
        }
    }
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
        .step-badge { width: 30px; height: 30px; border-radius: 50%; background: #eee; display: inline-flex; align-items: center; justify-content: center; margin-right: 10px; font-weight: bold; }
        .step-active { background: #0d6efd; color: white; }
    </style>
</head>
<body class="bg-light">
    <div class="container mt-5">
        
        <div class="d-flex justify-content-between mb-4">
            <h2><i class="fas fa-users-cog"></i> Employee Bulk Import</h2>
            <a href="index.php" class="btn btn-outline-secondary">Back to List</a>
        </div>

        <?php if ($msg): ?>
            <div class="alert alert-<?= $msg_type ?>"><?= htmlspecialchars($msg) ?></div>
        <?php endif; ?>
        
        <?php if (!$active_fy): ?>
            <div class="alert alert-danger">No Active Fiscal Year Found! Import Disabled.</div>
        <?php endif; ?>

        <?php if (isset($_SESSION['import_stage']) && $_SESSION['import_stage'] === 'preview'): ?>
            
            <div class="card shadow mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-eye"></i> Import Preview</h5>
                </div>
                <div class="card-body">
                    <div class="row text-center mb-4">
                        <div class="col-md-4">
                            <div class="p-3 border rounded bg-light">
                                <h3><?= $stats['total'] ?></h3>
                                <span class="text-muted">Total Rows Read</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 border rounded bg-success-subtle text-success">
                                <h3><?= $stats['valid'] ?></h3>
                                <span><i class="fas fa-check"></i> Ready to Import</span>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="p-3 border rounded bg-danger-subtle text-danger">
                                <h3><?= $stats['invalid'] ?></h3>
                                <span><i class="fas fa-times"></i> Rows with Errors</span>
                            </div>
                        </div>
                    </div>

                    <div class="d-flex justify-content-between align-items-center">
                        <div>
                            <?php if ($stats['invalid'] > 0): ?>
                                <a href="?download_errors=1" class="btn btn-danger">
                                    <i class="fas fa-download"></i> Download Error Report (CSV)
                                </a>
                                <small class="text-muted d-block mt-1">Fix these rows and re-upload.</small>
                            <?php endif; ?>
                        </div>
                        <form method="POST">
                            <input type="hidden" name="action" value="confirm">
                            <a href="bulk_import.php" class="btn btn-secondary">Cancel</a>
                            <?php if ($stats['valid'] > 0): ?>
                                <button type="submit" class="btn btn-success ms-2">
                                    <i class="fas fa-file-import"></i> Confirm Import (<?= $stats['valid'] ?> Rows)
                                </button>
                            <?php endif; ?>
                        </form>
                    </div>
                </div>
            </div>

            <?php if ($stats['valid'] > 0): ?>
            <div class="card mb-4">
                <div class="card-header">Valid Data Preview (First 5 Rows)</div>
                <div class="table-responsive">
                    <table class="table table-sm table-striped mb-0">
                        <thead>
                            <tr>
                                <th>Name</th>
                                <th>Type</th>
                                <th>Email</th>
                                <th>Dept ID</th>
                                <th>Technical</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach (array_slice($_SESSION['import_data'], 0, 5) as $row): ?>
                            <tr>
                                <td><?= htmlspecialchars($row['name']) ?></td>
                                <td><?= htmlspecialchars($row['emp_type']) ?></td>
                                <td><?= htmlspecialchars($row['email']) ?></td>
                                <td><?= htmlspecialchars($row['department_id']) ?></td>
                                <td>
                                    <?= $row['is_technical'] ? '<span class="badge bg-success">Yes</span>' : '<span class="badge bg-secondary">No</span>' ?>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
            <?php endif; ?>

        <?php else: ?>
            <div class="row">
                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header">Instructions</div>
                        <div class="card-body">
                            <ol class="list-group list-group-numbered list-group-flush">
                                <li class="list-group-item">Download the <strong>template</strong> and <strong>reference IDs</strong>.</li>
                                <li class="list-group-item">Fill in employee data. Supports Nepali fonts.</li>
                                <li class="list-group-item">Date format: <code>YYYY-MM-DD</code> or <code>MM/DD/YYYY</code>.</li>
                                <li class="list-group-item">Upload to see a <strong>preview</strong> and check for errors.</li>
                            </ol>
                            <div class="mt-3 d-grid gap-2">
                                <a href="?download_sample=1" class="btn btn-outline-primary btn-sm">
                                    <i class="fas fa-file-csv"></i> Download Template
                                </a>
                                <a href="?download_reference=1" class="btn btn-outline-info btn-sm">
                                    <i class="fas fa-list"></i> Download Reference IDs
                                </a>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="col-md-6">
                    <div class="card h-100">
                        <div class="card-header bg-white">
                            <span class="step-badge step-active">1</span> Upload CSV
                        </div>
                        <div class="card-body">
                            <form method="POST" enctype="multipart/form-data">
                                <input type="hidden" name="action" value="preview">
                                <div class="mb-3">
                                    <label class="form-label">Select File</label>
                                    <input type="file" name="csv_file" class="form-control" accept=".csv" required <?= !$active_fy ? 'disabled' : '' ?>>
                                </div>
                                <div class="alert alert-warning small py-2">
                                    <i class="fas fa-info-circle"></i> Postgres Boolean: Use 1/0, Yes/No, True/False
                                </div>
                                <button type="submit" class="btn btn-primary w-100" <?= !$active_fy ? 'disabled' : '' ?>>
                                    Next: Preview & Validate <i class="fas fa-arrow-right"></i>
                                </button>
                            </form>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</body>
</html>
<?php
ob_start();
require_once '../../config/database.php';
require_once '../../includes/header.php';

if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

require_once 'vendor/autoload.php'; // PhpSpreadsheet
use PhpOffice\PhpSpreadsheet\IOFactory;

$current_user_id = $_SESSION['user_id'] ?? null;
$preview_data = [];
$errors = [];

// Handle file upload and preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['excel_file'])) {
    try {
        $file = $_FILES['excel_file'];
        
        if ($file['error'] !== UPLOAD_ERR_OK) {
            throw new Exception("File upload error");
        }
        
        $allowed_types = [
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/octet-stream'
        ];
        
        if (!in_array($file['type'], $allowed_types)) {
            throw new Exception("Invalid file type. Please upload an Excel file (.xls or .xlsx)");
        }
        
        $spreadsheet = IOFactory::load($file['tmp_name']);
        $worksheet = $spreadsheet->getActiveSheet();
        $rows = $worksheet->toArray();
        
        // Get headers (first row)
        $headers = array_shift($rows);
        
        // Map Excel columns to database fields
        $column_map = [
            'Employee Type' => 'emp_type',
            'Eployee Type' => 'emp_type', // Handle typo in original file
            'Name' => 'name',
            'Rank' => 'level_id',
            'Department ??' => 'department_id',
            'Salary scale' => 'basic_salary',
            'Pan No' => 'pan_no',
            'CIT  No.' => 'cit_number',
            'Fund No.' => 'ssf_number',
            'Bank 1' => 'bank_code',
            'Bank 2' => 'bank_account_number',
            'Attendance Day' => 'attendance_days',
            'Shift' => 'shift_id'
        ];
        
        // Process rows for preview
        $row_num = 2; // Start from row 2 (after header)
        foreach ($rows as $row) {
            if (empty($row[0]) && empty($row[8])) { // Skip if both emp type and name are empty
                continue;
            }
            
            $employee_data = [
                'row_number' => $row_num,
                'name' => $row[8] ?? '', // Name
                'emp_type' => isset($row[0]) ? strtoupper(trim($row[0])) : 'PERMANENT',
                'pan_no' => $row[6] ?? null,
                'cit_number' => $row[5] ?? null,
                'ssf_number' => $row[7] ?? null,
                'bank_account_number' => $row[4] ?? null,
                'basic_salary' => is_numeric($row[14] ?? 0) ? $row[14] : 0,
                'level_id' => $row[9] ?? 1, // Rank
                'department_id' => $row[11] ?? 1,
                'shift_id' => $row[26] ?? 1,
                'errors' => []
            ];
            
            // Validate employee type
            if (!in_array($employee_data['emp_type'], ['PERMANENT', 'CONTRACT', 'DAILY_WAGES', 'DAILYWAGES'])) {
                $employee_data['emp_type'] = 'PERMANENT';
            }
            
            if ($employee_data['emp_type'] === 'DAILYWAGES') {
                $employee_data['emp_type'] = 'DAILY_WAGES';
            }
            
            // Validate required fields
            if (empty($employee_data['name'])) {
                $employee_data['errors'][] = "Name is required";
            }
            
            // Check for duplicate PAN
            if ($employee_data['pan_no']) {
                $check = $conn->prepare("SELECT id FROM employee WHERE pan_no = :pan AND deleted_date IS NULL");
                $check->execute([':pan' => $employee_data['pan_no']]);
                if ($check->fetch()) {
                    $employee_data['errors'][] = "Duplicate PAN number";
                }
            }
            
            $preview_data[] = $employee_data;
            $row_num++;
            
            // Limit preview to 100 rows
            if (count($preview_data) >= 100) {
                break;
            }
        }
        
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// Handle confirmed import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import'])) {
    $conn->beginTransaction();
    try {
        $import_data = json_decode($_POST['import_data'], true);
        $success_count = 0;
        $error_count = 0;
        
        // Get active fiscal year
        $fiscal_year = $conn->query("SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1")->fetch();
        $fiscal_year_id = $fiscal_year['id'] ?? null;
        
        // Get default designation (create if not exists)
        $default_designation = $conn->query("SELECT id FROM designation WHERE status = true LIMIT 1")->fetch();
        if (!$default_designation) {
            $conn->query("INSERT INTO designation (name, status) VALUES ('General', true)");
            $default_designation = $conn->query("SELECT id FROM designation WHERE name = 'General' LIMIT 1")->fetch();
        }
        $default_designation_id = $default_designation['id'];
        
        foreach ($import_data as $data) {
            // Skip rows with errors
            if (!empty($data['errors'])) {
                $error_count++;
                continue;
            }
            
            try {
                // Map level_id to actual level
                $level_stmt = $conn->prepare("SELECT id FROM level WHERE status = true ORDER BY display_order DESC LIMIT 1 OFFSET :offset");
                $offset = max(0, (int)$data['level_id'] - 1);
                $level_stmt->execute([':offset' => $offset]);
                $level = $level_stmt->fetch();
                $level_id = $level['id'] ?? null;
                
                // Map department_id to actual department
                $dept_stmt = $conn->prepare("SELECT id FROM department WHERE status = true LIMIT 1 OFFSET :offset");
                $dept_offset = max(0, (int)$data['department_id'] - 1);
                $dept_stmt->execute([':offset' => $dept_offset]);
                $dept = $dept_stmt->fetch();
                $department_id = $dept['id'] ?? null;
                
                // Insert employee
                $stmt = $conn->prepare("
                    INSERT INTO employee (
                        name, emp_type, emp_status,
                        designation_id, level_id, department_id,
                        pan_no, cit_number, ssf_number,
                        bank_account_number,
                        fiscal_year_id, shift_id,
                        created_by
                    ) VALUES (
                        :name, :emp_type, 'ACTIVE',
                        :designation_id, :level_id, :department_id,
                        :pan_no, :cit_number, :ssf_number,
                        :bank_account,
                        :fiscal_year_id, :shift_id,
                        :created_by
                    ) RETURNING id
                ");
                
                $stmt->execute([
                    ':name' => $data['name'],
                    ':emp_type' => $data['emp_type'],
                    ':designation_id' => $default_designation_id,
                    ':level_id' => $level_id,
                    ':department_id' => $department_id,
                    ':pan_no' => $data['pan_no'],
                    ':cit_number' => $data['cit_number'],
                    ':ssf_number' => $data['ssf_number'],
                    ':bank_account' => $data['bank_account_number'],
                    ':fiscal_year_id' => $fiscal_year_id,
                    ':shift_id' => $data['shift_id'],
                    ':created_by' => $current_user_id
                ]);
                
                $employee_id = $stmt->fetch()['id'];
                
                // Create salary record if basic salary is provided
                if ($data['basic_salary'] > 0) {
                    $sal_stmt = $conn->prepare("
                        INSERT INTO employee_salary (
                            employee_id, basic_salary, gross_salary,
                            effective_from, is_current, salary_mode,
                            created_by
                        ) VALUES (
                            :emp_id, :basic, :basic,
                            CURRENT_DATE, true, 'MONTHLY',
                            :created_by
                        )
                    ");
                    
                    $sal_stmt->execute([
                        ':emp_id' => $employee_id,
                        ':basic' => $data['basic_salary'],
                        ':created_by' => $current_user_id
                    ]);
                }
                
                $success_count++;
            } catch (Exception $e) {
                $error_count++;
                error_log("Import error for row " . $data['row_number'] . ": " . $e->getMessage());
            }
        }
        
        $conn->commit();
        $_SESSION['success_message'] = "Import completed! Successfully imported: $success_count employees. Errors: $error_count";
        header("Location: ../employees/index.php");
        exit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Import failed: " . $e->getMessage();
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
        .preview-table {
            font-size: 0.875rem;
        }
        .error-row {
            background-color: #f8d7da;
        }
        .success-row {
            background-color: #d1e7dd;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="row mb-4">
            <div class="col-12">
                <h2><i class="fas fa-file-import"></i> Bulk Import Employees</h2>
                <nav aria-label="breadcrumb">
                    <ol class="breadcrumb">
                        <li class="breadcrumb-item"><a href="../../index.php">Dashboard</a></li>
                        <li class="breadcrumb-item"><a href="../employees/index.php">Employees</a></li>
                        <li class="breadcrumb-item active">Bulk Import</li>
                    </ol>
                </nav>
            </div>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <?php if (empty($preview_data)): ?>
            <!-- Upload Form -->
            <div class="row">
                <div class="col-md-8 mx-auto">
                    <div class="card shadow">
                        <div class="card-header bg-primary text-white">
                            <h5 class="mb-0"><i class="fas fa-upload"></i> Upload Excel File</h5>
                        </div>
                        <div class="card-body">
                            <div class="alert alert-info">
                                <h6><i class="fas fa-info-circle"></i> Instructions:</h6>
                                <ol class="mb-0">
                                    <li>Prepare your Excel file with employee data</li>
                                    <li>Required columns: Employee Type, Name</li>
                                    <li>Optional columns: PAN No, CIT No, Fund No, Bank Account, Salary, etc.</li>
                                    <li>Supported formats: .xls, .xlsx</li>
                                    <li>Maximum 1000 rows per import</li>
                                </ol>
                            </div>

                            <form method="POST" enctype="multipart/form-data">
                                <div class="mb-4">
                                    <label class="form-label">Select Excel File <span class="text-danger">*</span></label>
                                    <input type="file" name="excel_file" class="form-control" accept=".xls,.xlsx" required>
                                    <small class="text-muted">Maximum file size: 10MB</small>
                                </div>

                                <div class="mb-3">
                                    <div class="alert alert-warning">
                                        <strong><i class="fas fa-exclamation-triangle"></i> Important:</strong>
                                        <ul class="mb-0 mt-2">
                                            <li>Employee codes will be auto-generated based on employee type</li>
                                            <li>Duplicate PAN numbers will be skipped</li>
                                            <li>Invalid data will be highlighted for review</li>
                                            <li>You will be able to review data before final import</li>
                                        </ul>
                                    </div>
                                </div>

                                <div class="d-flex justify-content-between">
                                    <a href="../employees/index.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        <i class="fas fa-eye"></i> Preview Data
                                    </button>
                                </div>
                            </form>
                        </div>
                    </div>

                    <!-- Sample Template -->
                    <div class="card shadow mt-4">
                        <div class="card-header bg-light">
                            <h6 class="mb-0"><i class="fas fa-download"></i> Download Sample Template</h6>
                        </div>
                        <div class="card-body">
                            <p class="mb-3">Download a sample Excel template with the correct format and column headers.</p>
                            <a href="download_template.php" class="btn btn-success">
                                <i class="fas fa-download"></i> Download Template
                            </a>
                        </div>
                    </div>
                </div>
            </div>
        <?php else: ?>
            <!-- Preview Data -->
            <div class="card shadow">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-eye"></i> Preview Import Data (<?= count($preview_data) ?> rows)</h5>
                </div>
                <div class="card-body">
                    <div class="alert alert-info">
                        <strong><i class="fas fa-info-circle"></i> Review the data below:</strong>
                        <ul class="mb-0 mt-2">
                            <li>Green rows: Ready to import</li>
                            <li>Red rows: Contains errors (will be skipped)</li>
                            <li>Review and click "Confirm Import" to proceed</li>
                        </ul>
                    </div>

                    <div class="table-responsive">
                        <table class="table table-sm table-bordered preview-table">
                            <thead class="table-light">
                                <tr>
                                    <th>Row</th>
                                    <th>Name</th>
                                    <th>Type</th>
                                    <th>PAN No</th>
                                    <th>Basic Salary</th>
                                    <th>Level</th>
                                    <th>Department</th>
                                    <th>Status</th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($preview_data as $data): ?>
                                    <tr class="<?= empty($data['errors']) ? 'success-row' : 'error-row' ?>">
                                        <td><?= $data['row_number'] ?></td>
                                        <td><?= htmlspecialchars($data['name']) ?></td>
                                        <td><?= htmlspecialchars($data['emp_type']) ?></td>
                                        <td><?= htmlspecialchars($data['pan_no'] ?? 'N/A') ?></td>
                                        <td>₹<?= number_format($data['basic_salary'], 2) ?></td>
                                        <td><?= $data['level_id'] ?></td>
                                        <td><?= $data['department_id'] ?></td>
                                        <td>
                                            <?php if (empty($data['errors'])): ?>
                                                <span class="badge bg-success">Ready</span>
                                            <?php else: ?>
                                                <span class="badge bg-danger">Errors</span>
                                                <small class="d-block text-danger">
                                                    <?= implode(', ', $data['errors']) ?>
                                                </small>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>

                    <form method="POST" id="importForm">
                        <input type="hidden" name="import_data" value='<?= htmlspecialchars(json_encode($preview_data), ENT_QUOTES) ?>'>
                        
                        <div class="d-flex justify-content-between mt-4">
                            <a href="import.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left"></i> Upload Different File
                            </a>
                            <button type="submit" name="confirm_import" class="btn btn-success">
                                <i class="fas fa-check"></i> Confirm Import
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Confirm before importing
        document.getElementById('importForm')?.addEventListener('submit', function(e) {
            if (!confirm('Are you sure you want to import these employees? This action cannot be undone.')) {
                e.preventDefault();
                return false;
            }
            
            const submitBtn = this.querySelector('button[type="submit"]');
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Importing...';
        });
    </script>
</body>
</html>

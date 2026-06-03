<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ================= Permission check =================
if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}
$current_user_id = $_SESSION['user_id'] ?? null;

// ================= Handle Export Requests =================
if (isset($_GET['export'])) {
    handleExport($conn);
    exit();
}

// ================= Fetch dropdown data =================
$designations = $conn->query("SELECT id, name FROM designation WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels       = $conn->query("SELECT 
    id, 
    CONCAT(name, CASE WHEN remarks IS NOT NULL AND remarks != '' THEN CONCAT(' - ', remarks) ELSE '' END) AS name
FROM level
WHERE status = true
ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);
$departments  = $conn->query("SELECT id, CONCAT(COALESCE(sub_department_name, ''), CASE WHEN sub_department_name IS NOT NULL THEN '/' ELSE '' END, name) as name FROM department WHERE status = true ORDER BY display_order")->fetchAll(PDO::FETCH_ASSOC);

// ================= Handle employee draft/edit =================
$employee_id  = $_GET['id'] ?? null;
$employee     = null;
$draft_stage  = 'basic_info';
$is_edit_mode = false;

if ($employee_id) {
    $stmt = $conn->prepare("SELECT * FROM employee WHERE id = :id");
    $stmt->execute([':id' => $employee_id]);
    $employee = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($employee) {
        $is_edit_mode = true;
        $draft_stage = $_GET['stage'] ?? 'basic_info';
        
        // If employee is ACTIVE and we're starting edit, go to basic_info
        if ($employee['emp_status'] === 'ACTIVE' && !isset($_GET['stage'])) {
            $draft_stage = 'basic_info';
        }
    } else {
        $_SESSION['error_message'] = "Employee not found.";
        header('Location: index.php');
        exit();
    }
}

// ================= Handle form submission =================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->beginTransaction();
    try {
        $stage       = $_POST['stage'] ?? 'basic_info';
        $is_complete = isset($_POST['complete']);
        
        // Get employee_id from POST if editing
        if (isset($_POST['employee_id']) && !empty($_POST['employee_id'])) {
            $employee_id = $_POST['employee_id'];
            $is_edit_mode = true;
        }

        if ($stage === 'basic_info') {
            $employee_id = handleBasicInfoSubmission($conn, $employee_id, $current_user_id, $is_edit_mode);
            $draft_stage = 'personal_info';
        } elseif ($stage === 'personal_info') {
            if (!$employee_id) {
                throw new Exception("Employee ID is required for personal info update");
            }
            handlePersonalInfoSubmission($conn, $employee_id, $current_user_id);
            $draft_stage = 'documents';
        } elseif ($stage === 'documents') {
            if (!$employee_id) {
                throw new Exception("Employee ID is required for document upload");
            }
            handleDocumentSubmission($conn, $employee_id, $current_user_id);

            if ($is_complete) {
                $stmt = $conn->prepare("
                    UPDATE employee 
                    SET emp_status = 'ACTIVE', updated_by = :updated_by, updated_date = NOW() 
                    WHERE id = :id
                ");
                $stmt->execute([
                    ':id' => $employee_id,
                    ':updated_by' => $current_user_id
                ]);
                $_SESSION['success_message'] = "Employee " . ($is_edit_mode ? "updated" : "created") . " successfully!";
            } else {
                $_SESSION['success_message'] = "Employee draft saved successfully!";
            }

            $conn->commit();
            ob_end_clean();
            header("Location: index.php");
            exit();
        }

        $conn->commit();
        header("Location: create.php?id=$employee_id&stage=$draft_stage");
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}

// ================= Helper functions =================
function handleBasicInfoSubmission($conn, $employee_id, $current_user_id, $is_edit_mode) {
    $required_fields = ['code', 'name', 'designation_id', 'level_id', 'department_id', 'emp_type'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            throw new Exception("Missing required field: " . $field);
        }
    }

    // Check for duplicate employee code
    if ($is_edit_mode && $employee_id) {
        // For edit mode, check if code exists for other employees
        $stmt = $conn->prepare("SELECT id FROM employee WHERE code = :code AND id != :id");
        $stmt->execute([':code' => $_POST['code'], ':id' => $employee_id]);
        if ($stmt->fetch()) {
            throw new Exception("Employee code '{$_POST['code']}' already exists. Please use a different code.");
        }
    } else {
        // For create mode, check if code exists
        $stmt = $conn->prepare("SELECT id FROM employee WHERE code = :code");
        $stmt->execute([':code' => $_POST['code']]);
        if ($stmt->fetch()) {
            throw new Exception("Employee code '{$_POST['code']}' already exists. Please use a different code.");
        }
    }

    $data = [
        ':code'          => $_POST['code'],
        ':name'          => $_POST['name'],
        ':designation_id'=> $_POST['designation_id'],
        ':level_id'      => $_POST['level_id'],
        ':department_id' => $_POST['department_id'],
        ':emp_type'      => $_POST['emp_type'],
        ':emp_status'    => $_POST['emp_status'] ?? 'DRAFT',
        ':updated_by'    => $current_user_id
    ];

    if ($is_edit_mode && $employee_id) {
        // UPDATE existing employee
        $data[':id'] = $employee_id;
        $stmt = $conn->prepare("
            UPDATE employee SET 
                code = :code, name = :name, designation_id = :designation_id, 
                level_id = :level_id, department_id = :department_id, 
                emp_type = :emp_type, emp_status = :emp_status,
                updated_by = :updated_by, updated_date = NOW() 
            WHERE id = :id
        ");
        $stmt->execute($data);
        return $employee_id;
    } else {
        // INSERT new employee
        $data[':created_by'] = $current_user_id;
        $stmt = $conn->prepare("
            INSERT INTO employee (
                code, name, designation_id, level_id, department_id, 
                emp_type, emp_status, created_by, updated_by
            ) VALUES (
                :code, :name, :designation_id, :level_id, :department_id, 
                :emp_type, :emp_status, :created_by, :updated_by
            )
        ");
        $stmt->execute($data);
        return $conn->lastInsertId('employee_id_seq');
    }
}

function handlePersonalInfoSubmission($conn, $employee_id, $current_user_id) {
    if (!$employee_id) {
        throw new Exception("Employee ID is required for personal info update");
    }

    $data = [
        ':id'                     => $employee_id,
        ':citizenship_no'         => !empty($_POST['citizenship_no']) ? $_POST['citizenship_no'] : null,
        ':national_id_card_no'    => !empty($_POST['national_id_card_no']) ? $_POST['national_id_card_no'] : null,
        ':mobile_number'          => !empty($_POST['mobile_number']) ? $_POST['mobile_number'] : null,
        ':email'                  => !empty($_POST['email']) ? $_POST['email'] : null,
        ':full_address'           => !empty($_POST['full_address']) ? $_POST['full_address'] : null,
        ':join_date'              => !empty($_POST['join_date']) ? $_POST['join_date'] : null,
        ':retirement_date'        => !empty($_POST['retirement_date']) ? $_POST['retirement_date'] : null,
        ':initial_appointment_date' => !empty($_POST['initial_appointment_date']) ? $_POST['initial_appointment_date'] : null,
        ':dob'                    => !empty($_POST['dob']) ? $_POST['dob'] : null,
        ':gender'                 => !empty($_POST['gender']) ? $_POST['gender'] : null,
        ':updated_by'             => $current_user_id
    ];

    $picturePath = null;
    if (!empty($_FILES['picture']['name'])) {
        $picturePath = uploadProfilePicture($employee_id);
        $data[':picture'] = $picturePath;
    }

    $sql = "
        UPDATE employee SET 
            citizenship_no = :citizenship_no,
            national_id_card_no = :national_id_card_no,
            mobile_number = :mobile_number,
            email = :email,
            full_address = :full_address,
            join_date = :join_date,
            retirement_date = :retirement_date,
            initial_appointment_date = :initial_appointment_date,
            dob = :dob,
            gender = :gender,
            updated_by = :updated_by,
            updated_date = NOW()"
            . ($picturePath ? ", picture = :picture" : "") . 
        " WHERE id = :id";

    $stmt = $conn->prepare($sql);
    $stmt->execute($data);
}

function handleDocumentSubmission($conn, $employee_id, $current_user_id) {
    if (!$employee_id) {
        throw new Exception("Employee ID is required for document upload");
    }

    if (empty($_FILES['documents']['name'][0])) {
        return; // No documents to upload
    }

    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/deno2/docs/employees/$employee_id/";
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    foreach ($_FILES['documents']['name'] as $i => $name) {
        if ($_FILES['documents']['error'][$i] !== UPLOAD_ERR_OK) {
            continue;
        }

        $ext = pathinfo($name, PATHINFO_EXTENSION);
        $filename = "doc_" . time() . "_" . uniqid() . ".$ext";
        $target_path = $upload_dir . $filename;

        if (!move_uploaded_file($_FILES['documents']['tmp_name'][$i], $target_path)) {
            continue;
        }

        $document_path = "docs/employees/$employee_id/$filename";

        $stmt = $conn->prepare("
            INSERT INTO employee_documents (
                employee_id, document_name, file_path, document_type, created_by, status
            ) VALUES (
                :employee_id, :document_name, :file_path, :document_type, :created_by, 'ACTIVE'
            )
        ");

        $stmt->execute([
            ':employee_id'   => $employee_id,
            ':document_name' => $name,
            ':file_path'     => $document_path,
            ':document_type' => $_POST['document_types'][$i] ?? 'OTHER',
            ':created_by'    => $current_user_id
        ]);
    }
}

function uploadProfilePicture($employee_id) {
    $upload_dir = $_SERVER['DOCUMENT_ROOT'] . '/deno2/docs/employees/';
    if (!file_exists($upload_dir)) {
        mkdir($upload_dir, 0777, true);
    }

    $file = $_FILES['picture'];
    $ext = pathinfo($file['name'], PATHINFO_EXTENSION);
    $filename = "emp_{$employee_id}_" . time() . ".$ext";
    $target_path = $upload_dir . $filename;

    $check = getimagesize($file['tmp_name']);
    if ($check === false) {
        throw new Exception("Invalid image file");
    }
    if ($file['size'] > 5000000) {
        throw new Exception("Max 5MB allowed");
    }
    if (!in_array(strtolower($ext), ['jpg', 'jpeg', 'png', 'gif'])) {
        throw new Exception("Only JPG, JPEG, PNG, GIF allowed");
    }
    if (!move_uploaded_file($file['tmp_name'], $target_path)) {
        throw new Exception("Error uploading image");
    }

    return 'docs/employees/' . $filename;
}

function handleExport($conn) {
    $format = $_GET['format'] ?? 'excel';
    
    // Build query with all employee details
    $sql = "
        SELECT 
            e.id,
            e.code,
            e.attendance_id,
            e.name,
            e.citizenship_no,
            e.national_id_card_no,
            e.mobile_number,
            e.email,
            e.full_address,
            e.join_date,
            e.retirement_date,
            e.initial_appointment_date,
            e.dob,
            e.gender,
            e.emp_status,
            e.emp_type,
            d.name as designation_name,
            CONCAT(l.name, CASE WHEN l.remarks IS NOT NULL AND l.remarks != '' THEN CONCAT(' - ', l.remarks) ELSE '' END) as level_name,
            CONCAT(COALESCE(dept.sub_department_name, ''), CASE WHEN dept.sub_department_name IS NOT NULL THEN '/' ELSE '' END, dept.name) as department_name,
            e.created_date,
            e.updated_date,
            creator.name as created_by_name,
            updater.name as updated_by_name
        FROM employee e
        LEFT JOIN designation d ON e.designation_id = d.id
        LEFT JOIN level l ON e.level_id = l.id
        LEFT JOIN department dept ON e.department_id = dept.id
        LEFT JOIN employee creator ON e.created_by = creator.id
        LEFT JOIN employee updater ON e.updated_by = updater.id
        ORDER BY e.code, e.name
    ";
    
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    if ($format === 'excel') {
        exportToExcel($employees);
    } elseif ($format === 'pdf') {
        exportToPDF($employees);
    }
}

function exportToExcel($employees) {
    $filename = 'employees_export_' . date('Y-m-d_H-i-s') . '.xls';
    
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Cache-Control: max-age=0');
    
    echo "\xEF\xBB\xBF"; // UTF-8 BOM
    
    // Headers
    $headers = [
        'S.N.', 'Employee Code', 'Attendance ID', 'Full Name', 'Designation', 'Level', 
        'Department', 'Employee Type', 'Status', 'Mobile Number', 'Email', 
        'Citizenship No', 'National ID', 'Date of Birth', 'Gender', 'Full Address',
        'Join Date', 'Initial Appointment', 'Retirement Date', 'Created Date', 
        'Created By', 'Updated Date', 'Updated By'
    ];
    
    echo implode("\t", $headers) . "\n";
    
    // Data rows
    $sn = 1;
    foreach ($employees as $emp) {
        $row = [
            $sn++,
            $emp['code'] ?? '',
            $emp['attendance_id'] ?? '',
            $emp['name'] ?? '',
            $emp['designation_name'] ?? '',
            $emp['level_name'] ?? '',
            $emp['department_name'] ?? '',
            $emp['emp_type'] ?? '',
            $emp['emp_status'] ?? '',
            $emp['mobile_number'] ?? '',
            $emp['email'] ?? '',
            $emp['citizenship_no'] ?? '',
            $emp['national_id_card_no'] ?? '',
            $emp['dob'] ?? '',
            $emp['gender'] ?? '',
            str_replace(["\n", "\r", "\t"], [' ', ' ', ' '], $emp['full_address'] ?? ''),
            $emp['join_date'] ?? '',
            $emp['initial_appointment_date'] ?? '',
            $emp['retirement_date'] ?? '',
            $emp['created_date'] ? date('Y-m-d H:i:s', strtotime($emp['created_date'])) : '',
            $emp['created_by_name'] ?? '',
            $emp['updated_date'] ? date('Y-m-d H:i:s', strtotime($emp['updated_date'])) : '',
            $emp['updated_by_name'] ?? ''
        ];
        
        // Clean data for Excel
        $cleanRow = array_map(function($cell) {
            return str_replace(["\n", "\r", "\t"], [' ', ' ', ' '], $cell);
        }, $row);
        
        echo implode("\t", $cleanRow) . "\n";
    }
}

function exportToPDF($employees) {
    $filename = 'employees_export_' . date('Y-m-d_H-i-s') . '.html';
    
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');
    
    echo '<!DOCTYPE html>
<html>
<head>
    <meta charset="UTF-8">
    <title>Employee Report</title>
    <style>
        @media print {
            .no-print { display: none; }
            body { margin: 0; }
        }
        body { font-family: Arial, sans-serif; font-size: 12px; margin: 20px; }
        .header { text-align: center; margin-bottom: 30px; border-bottom: 2px solid #333; padding-bottom: 10px; }
        .print-btn { margin-bottom: 20px; }
        table { width: 100%; border-collapse: collapse; margin-bottom: 20px; font-size: 10px; }
        th, td { border: 1px solid #ddd; padding: 4px; text-align: left; vertical-align: top; }
        th { background-color: #f2f2f2; font-weight: bold; }
        tr:nth-child(even) { background-color: #f9f9f9; }
        .rotate { writing-mode: vertical-rl; text-orientation: mixed; min-width: 30px; }
    </style>
    <script>
        function printReport() {
            window.print();
        }
    </script>
</head>
<body>
    <div class="no-print print-btn">
        <button onclick="printReport()">Print Report</button>
        <button onclick="window.close()">Close</button>
    </div>
    
    <div class="header">
        <h2>Employee Report</h2>
        <p>Generated on: ' . date('Y-m-d H:i:s') . '</p>
        <p>Total Records: ' . count($employees) . '</p>
    </div>
    
    <table>
        <thead>
            <tr>
                <th>S.N.</th>
                <th>Code</th>
                <th>Att ID</th>
                <th>Name</th>
                <th>Designation</th>
                <th>Level</th>
                <th>Department</th>
                <th>Type</th>
                <th>Status</th>
                <th>Mobile</th>
                <th>Email</th>
                <th>Citizenship</th>
                <th>National ID</th>
                <th>DOB</th>
                <th>Gender</th>
                <th>Address</th>
                <th>Join Date</th>
                <th>Init Appt</th>
                <th>Retirement</th>
            </tr>
        </thead>
        <tbody>';
    
    $sn = 1;
    foreach ($employees as $emp) {
        echo '<tr>
            <td>' . $sn++ . '</td>
            <td>' . htmlspecialchars($emp['code'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['attendance_id'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['name'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['designation_name'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['level_name'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['department_name'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['emp_type'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['emp_status'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['mobile_number'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['email'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['citizenship_no'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['national_id_card_no'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['dob'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['gender'] ?? '') . '</td>
            <td>' . htmlspecialchars(substr($emp['full_address'] ?? '', 0, 50)) . '</td>
            <td>' . htmlspecialchars($emp['join_date'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['initial_appointment_date'] ?? '') . '</td>
            <td>' . htmlspecialchars($emp['retirement_date'] ?? '') . '</td>
        </tr>';
    }
    
    echo '</tbody>
    </table>
    
    <div class="no-print">
        <p><em>This report can be printed using the Print button above or Ctrl+P</em></p>
    </div>
</body>
</html>';
}

// Get existing documents
$existing_documents = [];
if ($employee_id) {
    try {
        $stmt = $conn->prepare("
            SELECT id, document_name, file_path as document_path, document_type, created_date, status 
            FROM employee_documents 
            WHERE employee_id = :id AND status = 'ACTIVE'
            ORDER BY created_date DESC
        ");
        $stmt->execute([':id' => $employee_id]);
        $existing_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Document query failed: " . $e->getMessage());
        $existing_documents = [];
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $employee_id ? 'Edit' : 'Create' ?> Employee</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-12">
                <!-- Progress Bar -->
                <div class="card mb-4">
                    <div class="card-body">
                        <div class="d-flex justify-content-between align-items-center">
                            <h4 class="card-title mb-0"><?= $employee_id ? 'Edit' : 'Create New' ?> Employee</h4>
                            <?php if ($is_edit_mode): ?>
                                <div>
                                    <a href="create.php?export=1&format=excel" class="btn btn-success btn-sm me-2">
                                        <i class="fas fa-file-excel"></i> Export Excel
                                    </a>
                                    <a href="create.php?export=1&format=pdf" class="btn btn-danger btn-sm" target="_blank">
                                        <i class="fas fa-file-pdf"></i> Print/PDF
                                    </a>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="progress mt-3">
                            <div class="progress-bar" role="progressbar" 
                                 style="width: <?= $draft_stage === 'basic_info' ? '33' : ($draft_stage === 'personal_info' ? '66' : '100') ?>%">
                                Step <?= $draft_stage === 'basic_info' ? '1' : ($draft_stage === 'personal_info' ? '2' : '3') ?> of 3
                            </div>
                        </div>
                        <div class="row mt-3">
                            <div class="col-4 text-center">
                                <span class="badge <?= $draft_stage === 'basic_info' ? 'bg-primary' : 'bg-success' ?>">
                                    1. Basic Information
                                </span>
                            </div>
                            <div class="col-4 text-center">
                                <span class="badge <?= $draft_stage === 'personal_info' ? 'bg-primary' : ($draft_stage === 'documents' || $draft_stage === 'complete' ? 'bg-success' : 'bg-secondary') ?>">
                                    2. Personal Information
                                </span>
                            </div>
                            <div class="col-4 text-center">
                                <span class="badge <?= $draft_stage === 'documents' ? 'bg-primary' : ($draft_stage === 'complete' ? 'bg-success' : 'bg-secondary') ?>">
                                    3. Documents
                                </span>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Display Messages -->
                <?php if (isset($_SESSION['error_message'])): ?>
                    <div class="alert alert-danger alert-dismissible fade show">
                        <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['error_message']); ?>
                <?php endif; ?>
                <?php if (isset($_SESSION['success_message'])): ?>
                    <div class="alert alert-success alert-dismissible fade show">
                        <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
                        <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                    </div>
                    <?php unset($_SESSION['success_message']); ?>
                <?php endif; ?>

                <!-- Forms -->
                <div class="card">
                    <div class="card-body">
                        <!-- Basic Information Form -->
                        <?php if ($draft_stage === 'basic_info'): ?>
                            <h5 class="card-title"><i class="fas fa-user"></i> Step 1: Basic Information</h5>
                            <form method="POST" action="create.php" id="basicInfoForm">
                                <input type="hidden" name="stage" value="basic_info">
                                <?php if ($employee_id): ?>
                                    <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <?php endif; ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="code" class="form-label">Employee Code <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="code" name="code" 
                                                   value="<?= htmlspecialchars($employee['code'] ?? '') ?>" required
                                                   placeholder="e.g., EMP-124">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="name" class="form-label">Full Name <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="name" name="name" 
                                                   value="<?= htmlspecialchars($employee['name'] ?? '') ?>" required
                                                   placeholder="Enter full name">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="designation_id" class="form-label">Designation <span class="text-danger">*</span></label>
                                            <select class="form-select" id="designation_id" name="designation_id" required>
                                                <option value="">Select Designation</option>
                                                <?php foreach ($designations as $designation): ?>
                                                    <option value="<?= $designation['id'] ?>" 
                                                            <?= ($employee['designation_id'] ?? '') == $designation['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($designation['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="level_id" class="form-label">Level <span class="text-danger">*</span></label>
                                            <select class="form-select" id="level_id" name="level_id" required>
                                                <option value="">Select Level</option>
                                                <?php foreach ($levels as $level): ?>
                                                    <option value="<?= $level['id'] ?>" 
                                                            <?= ($employee['level_id'] ?? '') == $level['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($level['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="department_id" class="form-label">Department <span class="text-danger">*</span></label>
                                            <select class="form-select" id="department_id" name="department_id" required>
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?= $department['id'] ?>" 
                                                            <?= ($employee['department_id'] ?? '') == $department['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($department['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="emp_type" class="form-label">Employee Type <span class="text-danger">*</span></label>
                                            <select class="form-select" id="emp_type" name="emp_type" required>
                                                <option value="">Select Type</option>
                                                <option value="PERMANENT" <?= ($employee['emp_type'] ?? '') == 'PERMANENT' ? 'selected' : '' ?>>Permanent</option>
                                                <option value="CONTRACT" <?= ($employee['emp_type'] ?? '') == 'CONTRACT' ? 'selected' : '' ?>>Contract</option>
                                                <option value="TEMPORARY" <?= ($employee['emp_type'] ?? '') == 'TEMPORARY' ? 'selected' : '' ?>>Temporary</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="emp_status" class="form-label">Status</label>
                                            <select class="form-select" id="emp_status" name="emp_status">
                                                <option value="DRAFT" <?= ($employee['emp_status'] ?? 'DRAFT') == 'DRAFT' ? 'selected' : '' ?>>Draft</option>
                                                <option value="ACTIVE" <?= ($employee['emp_status'] ?? '') == 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                                                <option value="INACTIVE" <?= ($employee['emp_status'] ?? '') == 'ACTIVE' ? 'selected' : '' ?>>InActive</option>
                                                <option value="RETIRED" <?= ($employee['emp_status'] ?? '') == 'ACTIVE' ? 'selected' : '' ?>>Retired</option>

                                            </select>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <a href="index.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Cancel
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        Next: Personal Info <i class="fas fa-arrow-right"></i>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <!-- Personal Information Form -->
                        <?php if ($draft_stage === 'personal_info'): ?>
                            <h5 class="card-title"><i class="fas fa-id-card"></i> Step 2: Personal Information</h5>
                            <form method="POST" action="create.php" enctype="multipart/form-data" id="personalInfoForm">
                                <input type="hidden" name="stage" value="personal_info">
                                <?php if ($employee_id): ?>
                                    <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <?php endif; ?>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="citizenship_no" class="form-label">Citizenship Number</label>
                                            <input type="text" class="form-control" id="citizenship_no" name="citizenship_no" 
                                                   value="<?= htmlspecialchars($employee['citizenship_no'] ?? '') ?>"
                                                   placeholder="Enter citizenship number">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="national_id_card_no" class="form-label">National ID Card Number</label>
                                            <input type="text" class="form-control" id="national_id_card_no" name="national_id_card_no" 
                                                   value="<?= htmlspecialchars($employee['national_id_card_no'] ?? '') ?>"
                                                   placeholder="Enter national ID number">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="mobile_number" class="form-label">Mobile Number</label>
                                            <input type="text" class="form-control" id="mobile_number" name="mobile_number" 
                                                   value="<?= htmlspecialchars($employee['mobile_number'] ?? '') ?>"
                                                   placeholder="Enter mobile number">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?= htmlspecialchars($employee['email'] ?? '') ?>"
                                                   placeholder="Enter email address">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <label for="full_address" class="form-label">Full Address</label>
                                            <textarea class="form-control" id="full_address" name="full_address" rows="3" 
                                                      placeholder="Enter complete address"><?= htmlspecialchars($employee['full_address'] ?? '') ?></textarea>
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="dob" class="form-label">Date of Birth</label>
                                            <input type="date" class="form-control" id="dob" name="dob" 
                                                   value="<?= $employee['dob'] ?? '' ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="gender" class="form-label">Gender</label>
                                            <select class="form-select" id="gender" name="gender">
                                                <option value="">Select Gender</option>
                                                <option value="MALE" <?= ($employee['gender'] ?? '') == 'MALE' ? 'selected' : '' ?>>Male</option>
                                                <option value="FEMALE" <?= ($employee['gender'] ?? '') == 'FEMALE' ? 'selected' : '' ?>>Female</option>
                                                <option value="OTHER" <?= ($employee['gender'] ?? '') == 'OTHER' ? 'selected' : '' ?>>Other</option>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="join_date" class="form-label">Join Date</label>
                                            <input type="text" class="form-control" id="join_date" name="join_date" 
                                                   value="<?= $employee['join_date'] ?? '' ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="initial_appointment_date" class="form-label">Initial Appointment Date</label>
                                            <input type="text" class="form-control" id="initial_appointment_date" name="initial_appointment_date" 
                                                   value="<?= $employee['initial_appointment_date'] ?? '' ?>">
                                        </div>
                                    </div>
                                </div>
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="retirement_date" class="form-label">Retirement Date</label>
                                            <input type="text" class="form-control" id="retirement_date" name="retirement_date" 
                                                   value="<?= $employee['retirement_date'] ?? '' ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="picture" class="form-label">Profile Picture</label>
                                            <input type="file" class="form-control" id="picture" name="picture" accept="image/*">
                                            <?php if (!empty($employee['picture'])): ?>
                                                <small class="text-muted">Current: <?= basename($employee['picture']) ?></small>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <a href="create.php?id=<?= $employee_id ?>&stage=basic_info" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                    <button type="submit" class="btn btn-primary">
                                        Next: Documents <i class="fas fa-arrow-right"></i>
                                    </button>
                                </div>
                            </form>
                        <?php endif; ?>

                        <!-- Documents Form -->
                        <?php if ($draft_stage === 'documents'): ?>
                            <h5 class="card-title"><i class="fas fa-file-upload"></i> Step 3: Documents</h5>
                            <?php if (!empty($existing_documents)): ?>
                                <div class="mb-4">
                                    <h6><i class="fas fa-folder-open"></i> Existing Documents</h6>
                                    <div class="table-responsive">
                                        <table class="table table-sm table-striped">
                                            <thead>
                                                <tr>
                                                    <th>Document Name</th>
                                                    <th>Type</th>
                                                    <th>Upload Date</th>
                                                    <th>Actions</th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php foreach ($existing_documents as $doc): ?>
                                                    <tr>
                                                        <td><?= htmlspecialchars($doc['document_name']) ?></td>
                                                        <td>
                                                            <span class="badge bg-secondary"><?= htmlspecialchars($doc['document_type']) ?></span>
                                                        </td>
                                                        <td><?= date('Y-m-d', strtotime($doc['created_date'])) ?></td>
                                                        <td>
                                                            <a href="<?= htmlspecialchars($doc['document_path']) ?>" 
                                                               target="_blank" class="btn btn-sm btn-outline-primary">
                                                                <i class="fas fa-eye"></i> View
                                                            </a>
                                                        </td>
                                                    </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>

                            <form method="POST" action="create.php" enctype="multipart/form-data" id="documentsForm">
                                <input type="hidden" name="stage" value="documents">
                                <?php if ($employee_id): ?>
                                    <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <?php endif; ?>
                                <div class="mb-3">
                                    <label for="documents" class="form-label">Upload New Documents</label>
                                    <input type="file" class="form-control" id="documents" name="documents[]" multiple>
                                    <small class="text-muted">You can select multiple files at once. Supported formats: PDF, DOC, DOCX, JPG, PNG</small>
                                </div>
                                <div class="mb-3" id="document-types" style="display:none;">
                                    <label class="form-label">Document Types</label>
                                    <div id="document-type-inputs"></div>
                                </div>
                                <div class="d-flex justify-content-between">
                                    <a href="create.php?id=<?= $employee_id ?>&stage=personal_info" class="btn btn-secondary">
                                        <i class="fas fa-arrow-left"></i> Back
                                    </a>
                                    <div>
                                        <button type="submit" class="btn btn-outline-primary me-2">
                                            <i class="fas fa-save"></i> Save Draft
                                        </button>
                                        <button type="submit" name="complete" class="btn btn-success">
                                            <i class="fas fa-check"></i> Complete & Activate
                                        </button>
                                    </div>
                                </div>
                            </form>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Handle document file selection
        document.getElementById('documents').addEventListener('change', function() {
            const files = this.files;
            const documentTypesDiv = document.getElementById('document-types');
            const documentTypeInputs = document.getElementById('document-type-inputs');
            
            if (files.length > 0) {
                documentTypesDiv.style.display = 'block';
                documentTypeInputs.innerHTML = '';
                
                for (let i = 0; i < files.length; i++) {
                    const div = document.createElement('div');
                    div.className = 'row mb-2 align-items-center';
                    div.innerHTML = `
                        <div class="col-md-6">
                            <input type="text" class="form-control form-control-sm" value="${files[i].name}" readonly>
                        </div>
                        <div class="col-md-6">
                            <select name="document_types[]" class="form-select form-select-sm" required>
                                <option value="">Select Type</option>
                                <option value="CV">CV/Resume</option>
                                <option value="CERTIFICATE">Certificate</option>
                                <option value="CONTRACT">Contract</option>
                                <option value="ID_COPY">ID Copy</option>
                                <option value="ACADEMIC">Academic Documents</option>
                                <option value="EXPERIENCE">Experience Letter</option>
                                <option value="MEDICAL">Medical Certificate</option>
                                <option value="TRAINING">Training Certificate</option>
                                <option value="OTHER">Other</option>
                            </select>
                        </div>
                    `;
                    documentTypeInputs.appendChild(div);
                }
            } else {
                documentTypesDiv.style.display = 'none';
            }
        });

        // Prevent double submission
        document.querySelectorAll('form').forEach(form => {
            form.addEventListener('submit', function(e) {
                const submitButtons = form.querySelectorAll('button[type="submit"]');
                
                // Prevent multiple submissions
                if (form.dataset.submitted === 'true') {
                    e.preventDefault();
                    return false;
                }
                
                form.dataset.submitted = 'true';
                
                submitButtons.forEach(btn => {
                    btn.disabled = true;
                    const originalText = btn.innerHTML;
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Processing...';
                });
                
                // Re-enable after 10 seconds as failsafe
                setTimeout(() => {
                    form.dataset.submitted = 'false';
                    submitButtons.forEach(btn => {
                        btn.disabled = false;
                        btn.innerHTML = originalText;
                    });
                }, 10000);
            });
        });

        // Form validation
        document.addEventListener('DOMContentLoaded', function() {
            // Basic info form validation
            const basicForm = document.getElementById('basicInfoForm');
            if (basicForm) {
                basicForm.addEventListener('submit', function(e) {
                    const code = document.getElementById('code').value.trim();
                    const name = document.getElementById('name').value.trim();
                    
                    if (!code || !name) {
                        e.preventDefault();
                        alert('Employee Code and Name are required fields.');
                        return false;
                    }
                });
            }
            
            // Documents form validation
            const docsForm = document.getElementById('documentsForm');
            if (docsForm) {
                docsForm.addEventListener('submit', function(e) {
                    const files = document.getElementById('documents').files;
                    const complete = e.submitter && e.submitter.name === 'complete';
                    
                    if (files.length > 0) {
                        const types = document.querySelectorAll('select[name="document_types[]"]');
                        for (let type of types) {
                            if (!type.value) {
                                e.preventDefault();
                                alert('Please select document type for all uploaded files.');
                                return false;
                            }
                        }
                    }
                    
                    if (complete && files.length === 0) {
                        const hasExisting = <?= !empty($existing_documents) ? 'true' : 'false' ?>;
                        if (!hasExisting && !confirm('No documents uploaded. Complete anyway?')) {
                            e.preventDefault();
                            return false;
                        }
                    }
                });
            }
        });
    </script>
</body>
</html>
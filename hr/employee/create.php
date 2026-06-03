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

// ================= Fetch dropdown data =================
$designations = $conn->query("SELECT id, name FROM designation WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels       = $conn->query("
    SELECT 
        id, 
        CONCAT(name, CASE WHEN remarks IS NOT NULL AND remarks != '' THEN CONCAT(' - ', remarks) ELSE '' END) AS name
    FROM level
    WHERE status = true
    ORDER BY display_order DESC
")->fetchAll(PDO::FETCH_ASSOC);
$departments  = $conn->query("
    SELECT 
        id, 
        CONCAT(
            COALESCE(sub_department_name, ''), 
            CASE WHEN sub_department_name IS NOT NULL THEN ' / ' ELSE '' END, 
            name
        ) as name 
    FROM department 
    WHERE status = true 
    ORDER BY display_order
")->fetchAll(PDO::FETCH_ASSOC);
$fiscal_years = $conn->query("
    SELECT id, fiscal_code 
    FROM fiscal_years 
    ORDER BY start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

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
    // Validation errors array
    $errors = [];
    
    $required_fields = ['name', 'designation_id', 'level_id', 'department_id', 'emp_type'];
    foreach ($required_fields as $field) {
        if (empty($_POST[$field])) {
            $errors[] = "Missing required field: " . $field;
        }
    }
    
    // Validate emp_type
    if (!empty($_POST['emp_type']) && !in_array($_POST['emp_type'], ['PERMANENT', 'CONTRACT', 'DAILY_WAGES'])) {
        $errors[] = "Invalid employee type";
    }
    
    // If there are validation errors, throw exception
    if (!empty($errors)) {
        throw new Exception(implode("<br>", $errors));
    }

    $data = [
        ':name'           => $_POST['name'],
        ':name_nep'       => $_POST['name_nep'] ?? null,
        ':name_eng'       => $_POST['name_eng'] ?? null,
        ':designation_id' => $_POST['designation_id'],
        ':level_id'       => $_POST['level_id'],
        ':department_id'  => $_POST['department_id'],
        ':emp_type'       => $_POST['emp_type'],
        ':emp_status'     => $_POST['emp_status'] ?? 'DRAFT',
        ':is_technical'   => isset($_POST['is_technical']) ? ($_POST['is_technical'] === '1' ? true : false) : false,
        ':card_id'        => $_POST['card_id'] ?? null,
        ':fiscal_year_id' => $_POST['fiscal_year_id'] ?? null,
        ':updated_by'     => $current_user_id
    ];

    if ($is_edit_mode && $employee_id) {
        // UPDATE existing employee
        $data[':id'] = $employee_id;
        $stmt = $conn->prepare("
            UPDATE employee SET 
                name = :name, 
                name_nep = :name_nep,
                name_eng = :name_eng,
                designation_id = :designation_id, 
                level_id = :level_id, 
                department_id = :department_id, 
                emp_type = :emp_type, 
                emp_status = :emp_status,
                is_technical = :is_technical,
                card_id = :card_id,
                fiscal_year_id = :fiscal_year_id,
                updated_by = :updated_by, 
                updated_date = NOW() 
            WHERE id = :id
        ");
        $stmt->execute($data);
        return $employee_id;
    } else {
        // INSERT new employee - CODE is auto-generated by database trigger
        $data[':created_by'] = $current_user_id;
        $stmt = $conn->prepare("
            INSERT INTO employee (
                name, name_nep, name_eng, designation_id, level_id, department_id, 
                emp_type, emp_status, is_technical, card_id, fiscal_year_id, created_by, updated_by
            ) VALUES (
                :name, :name_nep, :name_eng, :designation_id, :level_id, :department_id, 
                :emp_type, :emp_status, :is_technical, :card_id, :fiscal_year_id, :created_by, :updated_by
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
    
    // Validation errors array
    $errors = [];
    
    // Validate email if provided
    if (!empty($_POST['email']) && !filter_var($_POST['email'], FILTER_VALIDATE_EMAIL)) {
        $errors[] = "Invalid email format";
    }
    
    // Validate mobile number if provided
    if (!empty($_POST['mobile_number']) && !preg_match('/^\d{10}$/', $_POST['mobile_number'])) {
        $errors[] = "Mobile number must be 10 digits";
    }
    
    // Validate PAN if provided
    if (!empty($_POST['pan_no']) && strlen($_POST['pan_no']) < 9) {
        $errors[] = "PAN number must be at least 9 characters";
    }
    
    // Validate ward number if provided
    if (!empty($_POST['ward_no']) && !is_numeric($_POST['ward_no'])) {
        $errors[] = "Ward number must be numeric";
    }
    
    // If there are validation errors, throw exception
    if (!empty($errors)) {
        throw new Exception(implode("<br>", $errors));
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
        ':pan_no'                 => !empty($_POST['pan_no']) ? $_POST['pan_no'] : null,
        ':bank_name'              => !empty($_POST['bank_name']) ? $_POST['bank_name'] : null,
        ':bank_branch'            => !empty($_POST['bank_branch']) ? $_POST['bank_branch'] : null,
        ':bank_account_number'    => !empty($_POST['bank_account_number']) ? $_POST['bank_account_number'] : null,
        ':local_body'             => !empty($_POST['local_body']) ? $_POST['local_body'] : null,
        ':state'                  => !empty($_POST['state']) ? $_POST['state'] : null,
        ':ward_no'                => !empty($_POST['ward_no']) ? $_POST['ward_no'] : null,
        ':join_date_nep'          => !empty($_POST['join_date_nep']) ? $_POST['join_date_nep'] : null,
        ':dob_nep'                => !empty($_POST['dob_nep']) ? $_POST['dob_nep'] : null,
        ':initial_appointment_date_nep' => !empty($_POST['initial_appointment_date_nep']) ? $_POST['initial_appointment_date_nep'] : null,
        ':retirement_date_nep'    => !empty($_POST['retirement_date_nep']) ? $_POST['retirement_date_nep'] : null,
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
            pan_no = :pan_no,
            bank_name = :bank_name,
            bank_branch = :bank_branch,
            bank_account_number = :bank_account_number,
            local_body = :local_body,
            state = :state,
            ward_no = :ward_no,
            join_date_nep = :join_date_nep,
            dob_nep = :dob_nep,
            initial_appointment_date_nep = :initial_appointment_date_nep,
            retirement_date_nep = :retirement_date_nep,
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
    <style>
        .form-error {
            border-color: #dc3545;
        }
        .error-message {
            color: #dc3545;
            font-size: 0.875em;
            margin-top: 0.25rem;
        }
        .required-field::after {
            content: " *";
            color: #dc3545;
        }
        .info-badge {
            background-color: #e7f1ff;
            color: #0d6efd;
            padding: 0.5rem;
            border-radius: 0.375rem;
            margin-bottom: 1rem;
            border-left: 4px solid #0d6efd;
        }
    </style>
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
                    <div class="alert alert-danger alert-dismissible fade show" id="server-error">
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
                            
                            <?php if (!$is_edit_mode): ?>
                                <div class="info-badge">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <strong>Note:</strong> Employee code will be auto-generated in the format: 
                                    <code>EMP-P-0001</code> (Permanent), <code>EMP-C-0001</code> (Contract), 
                                    <code>EMP-DW-0001</code> (Daily Wages) based on employee type.
                                </div>
                            <?php elseif ($is_edit_mode && isset($employee['code'])): ?>
                                <div class="info-badge">
                                    <i class="fas fa-id-card me-2"></i>
                                    <strong>Current Employee Code:</strong> <code><?= htmlspecialchars($employee['code']) ?></code>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="create.php" id="basicInfoForm" novalidate>
                                <input type="hidden" name="stage" value="basic_info">
                                <?php if ($employee_id): ?>
                                    <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="name" class="form-label required-field">Full Name</label>
                                            <input type="text" class="form-control" id="name" name="name" 
                                                   value="<?= htmlspecialchars($employee['name'] ?? '') ?>" required
                                                   placeholder="Enter full name">
                                            <div class="error-message" id="name-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="card_id" class="form-label">Card ID</label>
                                            <input type="text" class="form-control" id="card_id" name="card_id" 
                                                   value="<?= htmlspecialchars($employee['card_id'] ?? '') ?>"
                                                   placeholder="Enter card ID (if any)">
                                            <div class="error-message" id="card_id-error"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="name_eng" class="form-label">Name (English)</label>
                                            <input type="text" class="form-control" id="name_eng" name="name_eng" 
                                                   value="<?= htmlspecialchars($employee['name_eng'] ?? '') ?>"
                                                   placeholder="Enter name in English">
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="name_nep" class="form-label">Name (Nepali)</label>
                                            <input type="text" class="form-control" id="name_nep" name="name_nep" 
                                                   value="<?= htmlspecialchars($employee['name_nep'] ?? '') ?>"
                                                   placeholder="Enter name in Nepali">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="designation_id" class="form-label required-field">Designation</label>
                                            <select class="form-select" id="designation_id" name="designation_id" required>
                                                <option value="">Select Designation</option>
                                                <?php foreach ($designations as $designation): ?>
                                                    <option value="<?= $designation['id'] ?>" 
                                                            <?= ($employee['designation_id'] ?? '') == $designation['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($designation['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="error-message" id="designation_id-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="level_id" class="form-label required-field">Level</label>
                                            <select class="form-select" id="level_id" name="level_id" required>
                                                <option value="">Select Level</option>
                                                <?php foreach ($levels as $level): ?>
                                                    <option value="<?= $level['id'] ?>" 
                                                            <?= ($employee['level_id'] ?? '') == $level['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($level['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="error-message" id="level_id-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="department_id" class="form-label required-field">Department</label>
                                            <select class="form-select" id="department_id" name="department_id" required>
                                                <option value="">Select Department</option>
                                                <?php foreach ($departments as $department): ?>
                                                    <option value="<?= $department['id'] ?>" 
                                                            <?= ($employee['department_id'] ?? '') == $department['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($department['name']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                            <div class="error-message" id="department_id-error"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="emp_type" class="form-label required-field">Employee Type</label>
                                            <select class="form-select" id="emp_type" name="emp_type" required>
                                                <option value="">Select Type</option>
                                                <option value="PERMANENT" <?= ($employee['emp_type'] ?? '') == 'PERMANENT' ? 'selected' : '' ?>>Permanent</option>
                                                <option value="CONTRACT" <?= ($employee['emp_type'] ?? '') == 'CONTRACT' ? 'selected' : '' ?>>Contract</option>
                                                <option value="DAILY_WAGES" <?= ($employee['emp_type'] ?? '') == 'DAILY_WAGES' ? 'selected' : '' ?>>Daily Wages</option>
                                            </select>
                                            <div class="error-message" id="emp_type-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="fiscal_year_id" class="form-label">Fiscal Year</label>
                                            <select class="form-select" id="fiscal_year_id" name="fiscal_year_id">
                                                <option value="">Select Fiscal Year</option>
                                                <?php foreach ($fiscal_years as $fy): ?>
                                                    <option value="<?= $fy['id'] ?>" 
                                                            <?= ($employee['fiscal_year_id'] ?? '') == $fy['id'] ? 'selected' : '' ?>>
                                                        <?= htmlspecialchars($fy['fiscal_code']) ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="emp_status" class="form-label">Status</label>
                                            <select class="form-select" id="emp_status" name="emp_status">
                                                <option value="DRAFT" <?= ($employee['emp_status'] ?? 'DRAFT') == 'DRAFT' ? 'selected' : '' ?>>Draft</option>
                                                <option value="ACTIVE" <?= ($employee['emp_status'] ?? '') == 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                                                <option value="INACTIVE" <?= ($employee['emp_status'] ?? '') == 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                                                <option value="RETIRED" <?= ($employee['emp_status'] ?? '') == 'RETIRED' ? 'selected' : '' ?>>Retired</option>
                                                <option value="TERMINATED" <?= ($employee['emp_status'] ?? '') == 'TERMINATED' ? 'selected' : '' ?>>Terminated</option>
                                            </select>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-12">
                                        <div class="mb-3">
                                            <div class="form-check">
                                                <input class="form-check-input" type="checkbox" id="is_technical" name="is_technical" value="1"
                                                    <?= ($employee['is_technical'] ?? false) ? 'checked' : '' ?>>
                                                <label class="form-check-label" for="is_technical">
                                                    <i class="fas fa-cog me-1"></i> Is Technical Staff?
                                                </label>
                                            </div>
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
                            
                            <?php if ($is_edit_mode && isset($employee['code'])): ?>
                                <div class="info-badge">
                                    <i class="fas fa-id-card me-2"></i>
                                    <strong>Employee Code:</strong> <code><?= htmlspecialchars($employee['code']) ?></code>
                                </div>
                            <?php endif; ?>
                            
                            <form method="POST" action="create.php" enctype="multipart/form-data" id="personalInfoForm" novalidate>
                                <input type="hidden" name="stage" value="personal_info">
                                <?php if ($employee_id): ?>
                                    <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <?php endif; ?>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="citizenship_no" class="form-label">Citizenship Number</label>
                                            <input type="text" class="form-control" id="citizenship_no" name="citizenship_no" 
                                                   value="<?= htmlspecialchars($employee['citizenship_no'] ?? '') ?>"
                                                   placeholder="Enter citizenship number">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="national_id_card_no" class="form-label">National ID Card Number</label>
                                            <input type="text" class="form-control" id="national_id_card_no" name="national_id_card_no" 
                                                   value="<?= htmlspecialchars($employee['national_id_card_no'] ?? '') ?>"
                                                   placeholder="Enter national ID number">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="pan_no" class="form-label">PAN Number</label>
                                            <input type="text" class="form-control" id="pan_no" name="pan_no" 
                                                   value="<?= htmlspecialchars($employee['pan_no'] ?? '') ?>"
                                                   placeholder="Enter PAN number">
                                            <div class="error-message" id="pan_no-error"></div>
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="mobile_number" class="form-label">Mobile Number</label>
                                            <input type="text" class="form-control" id="mobile_number" name="mobile_number" 
                                                   value="<?= htmlspecialchars($employee['mobile_number'] ?? '') ?>"
                                                   placeholder="Enter 10-digit mobile number">
                                            <div class="error-message" id="mobile_number-error"></div>
                                        </div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="email" class="form-label">Email</label>
                                            <input type="email" class="form-control" id="email" name="email" 
                                                   value="<?= htmlspecialchars($employee['email'] ?? '') ?>"
                                                   placeholder="Enter email address">
                                            <div class="error-message" id="email-error"></div>
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
                                            <label for="state" class="form-label">State</label>
                                            <input type="text" class="form-control" id="state" name="state" 
                                                   value="<?= htmlspecialchars($employee['state'] ?? '') ?>"
                                                   placeholder="Enter state">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="local_body" class="form-label">Local Body</label>
                                            <input type="text" class="form-control" id="local_body" name="local_body" 
                                                   value="<?= htmlspecialchars($employee['local_body'] ?? '') ?>"
                                                   placeholder="Enter local body">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="ward_no" class="form-label">Ward No</label>
                                            <input type="text" class="form-control" id="ward_no" name="ward_no" 
                                                   value="<?= htmlspecialchars($employee['ward_no'] ?? '') ?>"
                                                   placeholder="Enter ward number">
                                            <div class="error-message" id="ward_no-error"></div>
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
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="dob" class="form-label">Date of Birth (AD)</label>
                                            <input type="date" class="form-control" id="dob" name="dob" 
                                                   value="<?= $employee['dob'] ?? '' ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="dob_nep" class="form-label">Date of Birth (BS)</label>
                                            <input type="text" class="form-control" id="dob_nep" name="dob_nep" 
                                                   value="<?= htmlspecialchars($employee['dob_nep'] ?? '') ?>"
                                                   placeholder="YYYY-MM-DD">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="join_date" class="form-label">Join Date (AD)</label>
                                            <input type="date" class="form-control" id="join_date" name="join_date" 
                                                   value="<?= $employee['join_date'] ?? '' ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-3">
                                        <div class="mb-3">
                                            <label for="join_date_nep" class="form-label">Join Date (BS)</label>
                                            <input type="text" class="form-control" id="join_date_nep" name="join_date_nep" 
                                                   value="<?= htmlspecialchars($employee['join_date_nep'] ?? '') ?>"
                                                   placeholder="YYYY-MM-DD">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="initial_appointment_date" class="form-label">Initial Appointment (AD)</label>
                                            <input type="date" class="form-control" id="initial_appointment_date" name="initial_appointment_date" 
                                                   value="<?= $employee['initial_appointment_date'] ?? '' ?>">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="initial_appointment_date_nep" class="form-label">Initial Appointment (BS)</label>
                                            <input type="text" class="form-control" id="initial_appointment_date_nep" name="initial_appointment_date_nep" 
                                                   value="<?= htmlspecialchars($employee['initial_appointment_date_nep'] ?? '') ?>"
                                                   placeholder="YYYY-MM-DD">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="retirement_date" class="form-label">Retirement Date (AD)</label>
                                            <input type="date" class="form-control" id="retirement_date" name="retirement_date" 
                                                   value="<?= $employee['retirement_date'] ?? '' ?>">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="retirement_date_nep" class="form-label">Retirement Date (BS)</label>
                                            <input type="text" class="form-control" id="retirement_date_nep" name="retirement_date_nep" 
                                                   value="<?= htmlspecialchars($employee['retirement_date_nep'] ?? '') ?>"
                                                   placeholder="YYYY-MM-DD">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="bank_name" class="form-label">Bank Name</label>
                                            <input type="text" class="form-control" id="bank_name" name="bank_name" 
                                                   value="<?= htmlspecialchars($employee['bank_name'] ?? '') ?>"
                                                   placeholder="Enter bank name">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="bank_branch" class="form-label">Bank Branch</label>
                                            <input type="text" class="form-control" id="bank_branch" name="bank_branch" 
                                                   value="<?= htmlspecialchars($employee['bank_branch'] ?? '') ?>"
                                                   placeholder="Enter bank branch">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <div class="mb-3">
                                            <label for="bank_account_number" class="form-label">Account Number</label>
                                            <input type="text" class="form-control" id="bank_account_number" name="bank_account_number" 
                                                   value="<?= htmlspecialchars($employee['bank_account_number'] ?? '') ?>"
                                                   placeholder="Enter account number">
                                        </div>
                                    </div>
                                </div>
                                
                                <div class="row">
                                    <div class="col-md-6">
                                        <div class="mb-3">
                                            <label for="picture" class="form-label">Profile Picture</label>
                                            <input type="file" class="form-control" id="picture" name="picture" accept="image/*">
                                            <?php if (!empty($employee['picture'])): ?>
                                                <div class="mt-2">
                                                    <small class="text-muted">Current: <?= basename($employee['picture']) ?></small>
                                                    <?php if (file_exists($_SERVER['DOCUMENT_ROOT'] . '/deno2/' . $employee['picture'])): ?>
                                                        <br>
                                                        <img src="/deno2/<?= htmlspecialchars($employee['picture']) ?>" 
                                                             alt="Current Profile" class="img-thumbnail mt-2" style="max-width: 100px;">
                                                    <?php endif; ?>
                                                </div>
                                            <?php endif; ?>
                                            <div class="error-message" id="picture-error"></div>
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
                            
                            <?php if ($is_edit_mode && isset($employee['code'])): ?>
                                <div class="info-badge">
                                    <i class="fas fa-id-card me-2"></i>
                                    <strong>Employee Code:</strong> <code><?= htmlspecialchars($employee['code']) ?></code>
                                </div>
                            <?php endif; ?>
                            
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

                            <form method="POST" action="create.php" enctype="multipart/form-data" id="documentsForm" novalidate>
                                <input type="hidden" name="stage" value="documents">
                                <?php if ($employee_id): ?>
                                    <input type="hidden" name="employee_id" value="<?= $employee_id ?>">
                                <?php endif; ?>
                                
                                <div class="mb-3">
                                    <label for="documents" class="form-label">Upload New Documents</label>
                                    <input type="file" class="form-control" id="documents" name="documents[]" multiple>
                                    <small class="text-muted">You can select multiple files at once. Supported formats: PDF, DOC, DOCX, JPG, PNG</small>
                                    <div class="error-message" id="documents-error"></div>
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
        // Real-time validation functions
        function validateEmail(email) {
            const re = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
            return re.test(email);
        }

        function validateMobile(mobile) {
            return /^\d{10}$/.test(mobile);
        }

        function validatePan(pan) {
            return pan.length >= 9;
        }

        function validateWard(ward) {
            return ward === '' || /^\d+$/.test(ward);
        }

        function showError(fieldId, message) {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(fieldId + '-error');
            if (field && errorElement) {
                field.classList.add('form-error');
                errorElement.textContent = message;
                errorElement.style.display = 'block';
            }
        }

        function clearError(fieldId) {
            const field = document.getElementById(fieldId);
            const errorElement = document.getElementById(fieldId + '-error');
            if (field && errorElement) {
                field.classList.remove('form-error');
                errorElement.textContent = '';
                errorElement.style.display = 'none';
            }
        }

        // Basic Info Form Validation
        const basicForm = document.getElementById('basicInfoForm');
        if (basicForm) {
            const nameField = document.getElementById('name');
            const designationField = document.getElementById('designation_id');
            const levelField = document.getElementById('level_id');
            const departmentField = document.getElementById('department_id');
            const empTypeField = document.getElementById('emp_type');
            const cardIdField = document.getElementById('card_id');

            // Real-time validation
            if (nameField) {
                nameField.addEventListener('blur', function() {
                    if (!this.value.trim()) {
                        showError('name', 'Employee name is required');
                    } else {
                        clearError('name');
                    }
                });
            }

            if (designationField) {
                designationField.addEventListener('change', function() {
                    if (!this.value) {
                        showError('designation_id', 'Designation is required');
                    } else {
                        clearError('designation_id');
                    }
                });
            }

            if (levelField) {
                levelField.addEventListener('change', function() {
                    if (!this.value) {
                        showError('level_id', 'Level is required');
                    } else {
                        clearError('level_id');
                    }
                });
            }

            if (departmentField) {
                departmentField.addEventListener('change', function() {
                    if (!this.value) {
                        showError('department_id', 'Department is required');
                    } else {
                        clearError('department_id');
                    }
                });
            }

            if (empTypeField) {
                empTypeField.addEventListener('change', function() {
                    if (!this.value) {
                        showError('emp_type', 'Employee type is required');
                    } else {
                        clearError('emp_type');
                    }
                });
            }

            if (cardIdField) {
                cardIdField.addEventListener('blur', function() {
                    if (this.value && this.value.length < 3) {
                        showError('card_id', 'Card ID must be at least 3 characters');
                    } else {
                        clearError('card_id');
                    }
                });
            }

            // Form submission validation
            basicForm.addEventListener('submit', function(e) {
                let isValid = true;
                
                if (!nameField.value.trim()) {
                    showError('name', 'Employee name is required');
                    isValid = false;
                }
                
                if (!designationField.value) {
                    showError('designation_id', 'Designation is required');
                    isValid = false;
                }
                
                if (!levelField.value) {
                    showError('level_id', 'Level is required');
                    isValid = false;
                }
                
                if (!departmentField.value) {
                    showError('department_id', 'Department is required');
                    isValid = false;
                }
                
                if (!empTypeField.value) {
                    showError('emp_type', 'Employee type is required');
                    isValid = false;
                }
                
                if (cardIdField.value && cardIdField.value.length < 3) {
                    showError('card_id', 'Card ID must be at least 3 characters');
                    isValid = false;
                }
                
                if (!isValid) {
                    e.preventDefault();
                    // Scroll to first error
                    const firstError = document.querySelector('.form-error');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        }

        // Personal Info Form Validation
        const personalForm = document.getElementById('personalInfoForm');
        if (personalForm) {
            const emailField = document.getElementById('email');
            const mobileField = document.getElementById('mobile_number');
            const panField = document.getElementById('pan_no');
            const wardField = document.getElementById('ward_no');
            const pictureField = document.getElementById('picture');

            // Real-time validation
            if (emailField) {
                emailField.addEventListener('blur', function() {
                    if (this.value.trim() && !validateEmail(this.value)) {
                        showError('email', 'Invalid email format');
                    } else {
                        clearError('email');
                    }
                });
            }

            if (mobileField) {
                mobileField.addEventListener('blur', function() {
                    if (this.value.trim() && !validateMobile(this.value)) {
                        showError('mobile_number', 'Mobile number must be 10 digits');
                    } else {
                        clearError('mobile_number');
                    }
                });
            }

            if (panField) {
                panField.addEventListener('blur', function() {
                    if (this.value.trim() && !validatePan(this.value)) {
                        showError('pan_no', 'PAN number must be at least 9 characters');
                    } else {
                        clearError('pan_no');
                    }
                });
            }

            if (wardField) {
                wardField.addEventListener('blur', function() {
                    if (this.value.trim() && !validateWard(this.value)) {
                        showError('ward_no', 'Ward number must be numeric');
                    } else {
                        clearError('ward_no');
                    }
                });
            }

            if (pictureField) {
                pictureField.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                        const maxSize = 5 * 1024 * 1024; // 5MB
                        
                        if (!validTypes.includes(file.type)) {
                            showError('picture', 'Only JPG, JPEG, PNG, and GIF files are allowed');
                            this.value = '';
                        } else if (file.size > maxSize) {
                            showError('picture', 'File size must be less than 5MB');
                            this.value = '';
                        } else {
                            clearError('picture');
                        }
                    }
                });
            }

            // Form submission validation
            personalForm.addEventListener('submit', function(e) {
                let isValid = true;
                
                if (emailField.value.trim() && !validateEmail(emailField.value)) {
                    showError('email', 'Invalid email format');
                    isValid = false;
                }
                
                if (mobileField.value.trim() && !validateMobile(mobileField.value)) {
                    showError('mobile_number', 'Mobile number must be 10 digits');
                    isValid = false;
                }
                
                if (panField.value.trim() && !validatePan(panField.value)) {
                    showError('pan_no', 'PAN number must be at least 9 characters');
                    isValid = false;
                }
                
                if (wardField.value.trim() && !validateWard(wardField.value)) {
                    showError('ward_no', 'Ward number must be numeric');
                    isValid = false;
                }
                
                if (pictureField.files.length > 0) {
                    const file = pictureField.files[0];
                    const validTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif'];
                    const maxSize = 5 * 1024 * 1024;
                    
                    if (!validTypes.includes(file.type)) {
                        showError('picture', 'Only JPG, JPEG, PNG, and GIF files are allowed');
                        isValid = false;
                    } else if (file.size > maxSize) {
                        showError('picture', 'File size must be less than 5MB');
                        isValid = false;
                    }
                }
                
                if (!isValid) {
                    e.preventDefault();
                    const firstError = document.querySelector('.form-error');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        }

        // Documents Form Validation
        const docsForm = document.getElementById('documentsForm');
        if (docsForm) {
            const documentsField = document.getElementById('documents');
            
            if (documentsField) {
                documentsField.addEventListener('change', function() {
                    if (this.files.length > 0) {
                        const validTypes = [
                            'application/pdf',
                            'application/msword',
                            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                            'image/jpeg',
                            'image/jpg',
                            'image/png'
                        ];
                        const maxSize = 10 * 1024 * 1024; // 10MB
                        
                        for (let i = 0; i < this.files.length; i++) {
                            const file = this.files[i];
                            if (!validTypes.includes(file.type)) {
                                showError('documents', `File "${file.name}" has an invalid type. Only PDF, DOC, DOCX, JPG, PNG are allowed.`);
                                this.value = '';
                                return;
                            }
                            if (file.size > maxSize) {
                                showError('documents', `File "${file.name}" is too large. Maximum size is 10MB.`);
                                this.value = '';
                                return;
                            }
                        }
                        clearError('documents');
                    }
                });
            }
            
            // Handle document file selection
            documentsField.addEventListener('change', function() {
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

            // Form submission validation
            docsForm.addEventListener('submit', function(e) {
                let isValid = true;
                
                // Check if documents are uploaded
                if (documentsField.files.length > 0) {
                    // Validate each file
                    const validTypes = [
                        'application/pdf',
                        'application/msword',
                        'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                        'image/jpeg',
                        'image/jpg',
                        'image/png'
                    ];
                    const maxSize = 10 * 1024 * 1024;
                    
                    for (let i = 0; i < documentsField.files.length; i++) {
                        const file = documentsField.files[i];
                        if (!validTypes.includes(file.type)) {
                            showError('documents', `File "${file.name}" has an invalid type. Only PDF, DOC, DOCX, JPG, PNG are allowed.`);
                            isValid = false;
                            break;
                        }
                        if (file.size > maxSize) {
                            showError('documents', `File "${file.name}" is too large. Maximum size is 10MB.`);
                            isValid = false;
                            break;
                        }
                    }
                    
                    // Check if document types are selected
                    const typeSelects = document.querySelectorAll('select[name="document_types[]"]');
                    for (let select of typeSelects) {
                        if (!select.value) {
                            isValid = false;
                            select.classList.add('form-error');
                        } else {
                            select.classList.remove('form-error');
                        }
                    }
                }
                
                if (!isValid) {
                    e.preventDefault();
                    const firstError = document.querySelector('.form-error');
                    if (firstError) {
                        firstError.scrollIntoView({ behavior: 'smooth', block: 'center' });
                    }
                }
            });
        }

        // Prevent double submission for all forms
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
                    btn.setAttribute('data-original-text', originalText);
                    btn.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status"></span>Processing...';
                });
                
                // Re-enable after 10 seconds as failsafe
                setTimeout(() => {
                    form.dataset.submitted = 'false';
                    submitButtons.forEach(btn => {
                        btn.disabled = false;
                        const originalText = btn.getAttribute('data-original-text');
                        if (originalText) btn.innerHTML = originalText;
                    });
                }, 10000);
            });
        });

        // Clear server error message when user starts typing
        document.querySelectorAll('input, select, textarea').forEach(field => {
            field.addEventListener('input', function() {
                const serverError = document.getElementById('server-error');
                if (serverError) {
                    serverError.remove();
                }
            });
        });
    </script>
</body>
</html>
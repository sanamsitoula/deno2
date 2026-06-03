<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$employee_id = $_GET['id'] ?? null;
if (!$employee_id) {
    $_SESSION['error_message'] = "Employee ID is required.";
    header('Location: index.php');
    exit();
}

// Fetch employee data
$stmt = $conn->prepare("
    SELECT 
        e.*, 
        d.name AS designation_name,
        l.name AS level_name,
        dep.name AS department_name,
        dep.sub_department_name AS sub_department_name,
        creator.name AS created_by_name,
        updater.name AS updated_by_name
    FROM employee e
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN level l ON e.level_id = l.id
    LEFT JOIN department dep ON e.department_id = dep.id
    LEFT JOIN employee creator ON e.created_by = creator.id
    LEFT JOIN employee updater ON e.updated_by = updater.id
    WHERE e.id = :id
");
$stmt->execute([':id' => $employee_id]);
$employee = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    $_SESSION['error_message'] = "Employee not found.";
    header('Location: index.php');
    exit();
}

// Fetch family members
$family = $conn->prepare("SELECT * FROM employee_family WHERE emp_id = :id ORDER BY relation");
$family->execute([':id' => $employee_id]);
$family_members = $family->fetchAll(PDO::FETCH_ASSOC);

// Fetch education
$education = $conn->prepare("SELECT * FROM education_details WHERE emp_id = :id AND status = TRUE ORDER BY completion_year DESC");
$education->execute([':id' => $employee_id]);
$education_list = $education->fetchAll(PDO::FETCH_ASSOC);

// Fetch designation history
$history = $conn->prepare("
    SELECT ed.*, d.name AS designation_name, dep.name AS department_name, l.name AS level_name 
    FROM employee_designation ed
    LEFT JOIN designation d ON ed.designation_id = d.id
    LEFT JOIN department dep ON ed.department_id = dep.id
    LEFT JOIN level l ON ed.level_id = l.id
    WHERE ed.emp_id = :id ORDER BY ed.date_of_join DESC
");
$history->execute([':id' => $employee_id]);
$designation_history = $history->fetchAll(PDO::FETCH_ASSOC);

// Fetch documents
$docs = $conn->prepare("SELECT * FROM employee_documents WHERE employee_id = :id AND status = 'ACTIVE' ORDER BY created_date DESC");
$docs->execute([':id' => $employee_id]);
$documents = $docs->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>View Employee: <?= htmlspecialchars($employee['name']) ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        .profile-img {
            width: 150px;
            height: 150px;
            object-fit: cover;
            border-radius: 50%;
            border: 5px solid #fff;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
        }
        .section-title {
            border-bottom: 2px solid #0d6efd;
            padding-bottom: 5px;
            margin: 25px 0 15px;
            color: #0d6efd;
            display: flex;
            justify-content: space-between;
            align-items: center;
        }
        .detail-label {
            font-weight: bold;
            color: #495057;
        }
        .doc-card {
            border: 1px solid #dee2e6;
            border-radius: 8px;
            padding: 10px;
            margin-bottom: 10px;
            background-color: #f8f9fa;
        }
        .action-buttons {
            display: flex;
            gap: 0.5rem;
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-4">
            <h2><i class="fas fa-user-circle"></i> Employee Profile</h2>
            <div>
                <a href="index.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back</a>
                <a href="create.php?id=<?= $employee['id'] ?>" class="btn btn-primary"><i class="fas fa-edit"></i> Edit</a>
            </div>
        </div>

        <?php if (isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['success_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['success_message']); ?>
        <?php endif; ?>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger alert-dismissible fade show">
                <?= htmlspecialchars($_SESSION['error_message']) ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <!-- Profile Header -->
        <div class="card shadow mb-4">
            <div class="card-body text-center">
                <img src="<?= !empty($employee['picture']) ? '/deno2/' . htmlspecialchars($employee['picture']) : 'https://via.placeholder.com/150' ?>"
                     alt="Profile" class="profile-img mb-3">
                <h3><?= htmlspecialchars($employee['name']) ?></h3>
                <p class="text-muted">
                    <strong><?= htmlspecialchars($employee['designation_name']) ?></strong> at
                    <?= htmlspecialchars($employee['sub_department_name'] ?? '') ?><?= $employee['sub_department_name'] ? ' / ' : '' ?><?= htmlspecialchars($employee['department_name']) ?>
                </p>
                <div class="badge bg-primary fs-6"><?= htmlspecialchars($employee['emp_status']) ?></div>
                <div class="badge bg-success ms-2 fs-6"><?= htmlspecialchars($employee['emp_type']) ?></div>
            </div>
        </div>

        <div class="row">
            <!-- Personal Info -->
            <div class="col-lg-6">
                <h5 class="section-title"><i class="fas fa-id-card"></i> Personal Information</h5>
                <dl class="row">
                    <dt class="col-sm-4 detail-label">Employee Code</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['code']) ?></dd>

                    <dt class="col-sm-4 detail-label">Mobile</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['mobile_number'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">Email</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['email'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">DOB</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['dob'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">Gender</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['gender'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">Address</dt>
                    <dd class="col-sm-8"><?= nl2br(htmlspecialchars($employee['full_address'] ?? 'N/A')) ?></dd>

                    <dt class="col-sm-4 detail-label">Citizenship No</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['citizenship_no'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">National ID</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['national_id_card_no'] ?? 'N/A') ?></dd>
                </dl>
            </div>

            <!-- Employment Info -->
            <div class="col-lg-6">
                <h5 class="section-title"><i class="fas fa-briefcase"></i> Employment Details</h5>
                <dl class="row">
                    <dt class="col-sm-4 detail-label">Join Date</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['join_date'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">Initial Appointment</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['initial_appointment_date'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">Retirement Date</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['retirement_date'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">Level</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['level_name'] ?? 'N/A') ?></dd>

                    <dt class="col-sm-4 detail-label">Created On</dt>
                    <dd class="col-sm-8"><?= $employee['created_date'] ? date('F j, Y', strtotime($employee['created_date'])) : 'N/A' ?></dd>

                    <dt class="col-sm-4 detail-label">Created By</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['created_by_name'] ?? 'System') ?></dd>

                    <dt class="col-sm-4 detail-label">Last Updated</dt>
                    <dd class="col-sm-8">
                        <?= $employee['updated_date'] ? date('F j, Y, H:i', strtotime($employee['updated_date'])) : 'N/A' ?>
                    </dd>

                    <dt class="col-sm-4 detail-label">Updated By</dt>
                    <dd class="col-sm-8"><?= htmlspecialchars($employee['updated_by_name'] ?? 'N/A') ?></dd>
                </dl>
            </div>
        </div>

        <!-- Family Members -->
        <h5 class="section-title">
            <span><i class="fas fa-users"></i> Family Members</span>
            <a href="family_create.php?emp_id=<?= $employee_id ?>" class="btn btn-sm btn-success">
                <i class="fas fa-plus"></i> Add Family Member
            </a>
        </h5>
        <?php if (!empty($family_members)): ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm table-bordered">
                    <thead class="table-light">
                        <tr>
                            <th>Name</th>
                            <th>Relation</th>
                            <th>Contact</th>
                            <th>Remarks</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($family_members as $fam): ?>
                            <tr>
                                <td><?= htmlspecialchars($fam['name']) ?></td>
                                <td><?= htmlspecialchars($fam['relation']) ?></td>
                                <td><?= htmlspecialchars($fam['contact'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($fam['remarks'] ?? 'N/A') ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="family_create.php?emp_id=<?= $employee_id ?>&id=<?= $fam['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No family members added yet.</div>
        <?php endif; ?>

        <!-- Education -->
        <h5 class="section-title">
            <span><i class="fas fa-graduation-cap"></i> Education Background</span>
            <a href="education_create.php?emp_id=<?= $employee_id ?>" class="btn btn-sm btn-success">
                <i class="fas fa-plus"></i> Add Education
            </a>
        </h5>
        <?php if (!empty($education_list)): ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm table-striped">
                    <thead class="table-light">
                        <tr>
                            <th>Degree</th>
                            <th>Institution</th>
                            <th>University</th>
                            <th>Year</th>
                            <th>Marks</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($education_list as $edu): ?>
                            <tr>
                                <td><?= htmlspecialchars($edu['degree_name']) ?></td>
                                <td><?= htmlspecialchars($edu['institution_name']) ?></td>
                                <td><?= htmlspecialchars($edu['university'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($edu['completion_year'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($edu['marks'] ?? 'N/A') ?></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="education_create.php?emp_id=<?= $employee_id ?>&id=<?= $edu['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No education records added yet.</div>
        <?php endif; ?>

        <!-- Designation History -->
        <h5 class="section-title">
            <span><i class="fas fa-history"></i> Designation & Department History</span>
            <a href="designation_create.php?emp_id=<?= $employee_id ?>" class="btn btn-sm btn-success">
                <i class="fas fa-plus"></i> Add Designation
            </a>
        </h5>
        <?php if (!empty($designation_history)): ?>
            <div class="table-responsive mb-4">
                <table class="table table-sm">
                    <thead class="table-light">
                        <tr>
                            <th>Designation</th>
                            <th>Department</th>
                            <th>Level</th>
                            <th>Join Date</th>
                            <th>Left Date</th>
                            <th>Status</th>
                            <th width="120">Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($designation_history as $hist): ?>
                            <tr>
                                <td><?= htmlspecialchars($hist['designation_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($hist['department_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($hist['level_name'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($hist['date_of_join'] ?? 'N/A') ?></td>
                                <td><?= htmlspecialchars($hist['date_of_left'] ?? 'N/A') ?></td>
                                <td><span class="badge bg-<?= $hist['status'] === 'ACTIVE' ? 'success' : 'secondary' ?>">
                                    <?= htmlspecialchars($hist['status']) ?>
                                </span></td>
                                <td>
                                    <div class="action-buttons">
                                        <a href="designation_create.php?emp_id=<?= $employee_id ?>&id=<?= $hist['id'] ?>" 
                                           class="btn btn-sm btn-outline-primary" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No designation history added yet.</div>
        <?php endif; ?>

        <!-- Documents -->
        <h5 class="section-title">
            <span><i class="fas fa-folder"></i> Uploaded Documents</span>
            <a href="document_create.php?emp_id=<?= $employee_id ?>" class="btn btn-sm btn-success">
                <i class="fas fa-plus"></i> Upload Document
            </a>
        </h5>
        <?php if (!empty($documents)): ?>
            <div class="row">
                <?php foreach ($documents as $doc): ?>
                    <div class="col-md-4 col-sm-6 mb-3">
                        <div class="doc-card">
                            <strong><?= htmlspecialchars($doc['document_name']) ?></strong><br>
                            <small>Type: <?= htmlspecialchars($doc['document_type']) ?></small><br>
                            <small>Uploaded: <?= date('M j, Y', strtotime($doc['created_date'])) ?></small><br>
                            <a href="/deno2/<?= htmlspecialchars($doc['file_path']) ?>" target="_blank" class="btn btn-sm btn-outline-primary mt-2">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php else: ?>
            <div class="alert alert-info">No documents uploaded yet.</div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>

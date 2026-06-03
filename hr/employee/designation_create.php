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
$emp_id = $_GET['emp_id'] ?? $_POST['emp_id'] ?? null;
$hist_id = $_GET['id'] ?? null;

$emp_stmt = $conn->prepare("SELECT name FROM employee WHERE id = :id");
$emp_stmt->execute([':id' => $emp_id]);
$employee = $emp_stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    die("Employee not found.");
}

// Fetch data for edit
$history = null;
if ($hist_id) {
    $stmt = $conn->prepare("SELECT * FROM employee_designation WHERE id = :id AND emp_id = :emp_id");
    $stmt->execute([':id' => $hist_id, ':emp_id' => $emp_id]);
    $history = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch dropdowns
$designations = $conn->query("SELECT id, name FROM designation WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$departments = $conn->query("SELECT id, name FROM department WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$levels = $conn->query("SELECT id, name FROM level WHERE status = true ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $required = ['date_of_join', 'designation_id'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }

        $data = [
            ':emp_id' => $emp_id,
            ':date_of_join' => $_POST['date_of_join'],
            ':date_of_attendance' => $_POST['date_of_attendance'] ?? null,
            ':date_of_left' => $_POST['date_of_left'] ?? null,
            ':designation_id' => $_POST['designation_id'],
            ':level_id' => $_POST['level_id'] ?? null,
            ':department_id' => $_POST['department_id'] ?? null,
            ':status' => $_POST['status'] ?? 'ACTIVE',
            ':remarks' => $_POST['remarks'] ?? null,
            ':description' => $_POST['description'] ?? null,
        ];

        if ($hist_id) {
            $data[':id'] = $hist_id;
            $stmt = $conn->prepare("
                UPDATE employee_designation SET 
                    date_of_join = :date_of_join, date_of_attendance = :date_of_attendance,
                    date_of_left = :date_of_left, designation_id = :designation_id,
                    level_id = :level_id, department_id = :department_id,
                    status = :status, remarks = :remarks, description = :description
                WHERE id = :id AND emp_id = :emp_id
            ");
            $msg = "Designation history updated.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO employee_designation (
                    emp_id, date_of_join, date_of_attendance, date_of_left,
                    designation_id, level_id, department_id, status,
                    remarks, description
                ) VALUES (
                    :emp_id, :date_of_join, :date_of_attendance, :date_of_left,
                    :designation_id, :level_id, :department_id, :status,
                    :remarks, :description
                )
            ");
            $msg = "New designation record added.";
        }

        $stmt->execute($data);
        $_SESSION['success_message'] = $msg;
        header("Location: view.php?id=$emp_id");
        exit();
    } catch (Exception $e) {
        $_SESSION['error_message'] = "Error: " . $e->getMessage();
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= $hist_id ? 'Edit' : 'Add' ?> Designation History</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
</head>
<body>
    <div class="container-fluid mt-4">
        <div class="d-flex justify-content-between align-items-center mb-3">
            <h4>
                <a href="view.php?id=<?= $emp_id ?>" class="btn btn-sm btn-outline-secondary me-2">
                    <i class="fas fa-arrow-left"></i>
                </a>
                <?= $hist_id ? 'Edit' : 'Add' ?> Designation – <strong><?= htmlspecialchars($employee['name']) ?></strong>
            </h4>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error_message']) ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="card shadow">
            <div class="card-body">
                <form method="POST">
                    <input type="hidden" name="emp_id" value="<?= $emp_id ?>">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Designation <span class="text-danger">*</span></label>
                                <select class="form-select" name="designation_id" required>
                                    <option value="">Select</option>
                                    <?php foreach ($designations as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= ($history['designation_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Department</label>
                                <select class="form-select" name="department_id">
                                    <option value="">Select</option>
                                    <?php foreach ($departments as $d): ?>
                                        <option value="<?= $d['id'] ?>" <?= ($history['department_id'] ?? '') == $d['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($d['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Level</label>
                                <select class="form-select" name="level_id">
                                    <option value="">Select</option>
                                    <?php foreach ($levels as $l): ?>
                                        <option value="<?= $l['id'] ?>" <?= ($history['level_id'] ?? '') == $l['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($l['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Join Date <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" name="date_of_join" required
                                       value="<?= $history['date_of_join'] ?? '' ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Left Date</label>
                                <input type="date" class="form-control" name="date_of_left"
                                       value="<?= $history['date_of_left'] ?? '' ?>">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Attendance Date</label>
                                <input type="text" class="form-control" name="date_of_attendance"
                                       value="<?= htmlspecialchars($history['date_of_attendance'] ?? '') ?>"
                                       placeholder="e.g., 2080-01-15">
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="mb-3">
                                <label class="form-label">Status</label>
                                <select class="form-select" name="status">
                                    <option value="ACTIVE" <?= ($history['status'] ?? 'ACTIVE') == 'ACTIVE' ? 'selected' : '' ?>>Active</option>
                                    <option value="INACTIVE" <?= ($history['status'] ?? '') == 'INACTIVE' ? 'selected' : '' ?>>Inactive</option>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Description</label>
                        <textarea class="form-control" name="description" rows="2"><?= htmlspecialchars($history['description'] ?? '') ?></textarea>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2"><?= htmlspecialchars($history['remarks'] ?? '') ?></textarea>
                    </div>
                    <div class="d-flex justify-content-end">
                        <a href="view.php?id=<?= $emp_id ?>" class="btn btn-secondary me-2">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-save"></i> Save
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
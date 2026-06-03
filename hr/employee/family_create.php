<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Permission check
if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;
$emp_id = $_GET['emp_id'] ?? $_POST['emp_id'] ?? null;
$family_id = $_GET['id'] ?? null;

// Fetch employee name for back link
$emp_stmt = $conn->prepare("SELECT name FROM employee WHERE id = :id");
$emp_stmt->execute([':id' => $emp_id]);
$employee = $emp_stmt->fetch(PDO::FETCH_ASSOC);

if (!$employee) {
    die("Employee not found.");
}

// Fetch existing family member (if editing)
$family = null;
if ($family_id) {
    $stmt = $conn->prepare("SELECT * FROM employee_family WHERE id = :id AND emp_id = :emp_id");
    $stmt->execute([':id' => $family_id, ':emp_id' => $emp_id]);
    $family = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$family) {
        $_SESSION['error_message'] = "Family record not found.";
        header("Location: view.php?id=$emp_id");
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $required = ['name', 'relation'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }

        $data = [
            ':emp_id' => $emp_id,
            ':name' => $_POST['name'],
            ':relation' => $_POST['relation'],
            ':contact' => $_POST['contact'] ?? null,
            ':remarks' => $_POST['remarks'] ?? null,
        ];

        if ($family_id) {
            // Update
            $data[':id'] = $family_id;
            $stmt = $conn->prepare("
                UPDATE employee_family SET 
                    name = :name, relation = :relation, contact = :contact, remarks = :remarks
                WHERE id = :id AND emp_id = :emp_id
            ");
            $msg = "Family member updated successfully.";
        } else {
            // Insert
            $stmt = $conn->prepare("
                INSERT INTO employee_family (emp_id, name, relation, contact, remarks)
                VALUES (:emp_id, :name, :relation, :contact, :remarks)
            ");
            $msg = "Family member added successfully.";
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
    <title><?= $family_id ? 'Edit' : 'Add' ?> Family Member</title>
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
                <?= $family_id ? 'Edit' : 'Add' ?> Family Member – <strong><?= htmlspecialchars($employee['name']) ?></strong>
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
                                <label class="form-label">Full Name <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="name" required
                                       value="<?= htmlspecialchars($family['name'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Relation <span class="text-danger">*</span></label>
                                <select class="form-select" name="relation" required>
                                    <option value="">Select Relation</option>
                                    <?php
                                    $relations = ['Father', 'Mother', 'Spouse', 'Son', 'Daughter', 'Brother', 'Sister'];
                                    foreach ($relations as $rel): ?>
                                        <option value="<?= $rel ?>" <?= ($family['relation'] ?? '') == $rel ? 'selected' : '' ?>>
                                            <?= $rel ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Contact Number</label>
                                <input type="text" class="form-control" name="contact"
                                       value="<?= htmlspecialchars($family['contact'] ?? '') ?>">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2"><?= htmlspecialchars($family['remarks'] ?? '') ?></textarea>
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
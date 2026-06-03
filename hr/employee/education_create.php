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
$edu_id = $_GET['id'] ?? null;

$emp_stmt = $conn->prepare("SELECT name FROM employee WHERE id = :id");
$emp_stmt->execute([':id' => $emp_id]);
$employee = $emp_stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    die("Employee not found.");
}

$education = null;
if ($edu_id) {
    $stmt = $conn->prepare("SELECT * FROM education_details WHERE id = :id AND emp_id = :emp_id");
    $stmt->execute([':id' => $edu_id, ':emp_id' => $emp_id]);
    $education = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$education) {
        $_SESSION['error_message'] = "Education record not found.";
        header("Location: view.php?id=$emp_id");
        exit();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $required = ['degree_name', 'institution_name'];
        foreach ($required as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '$field' is required.");
            }
        }

        $data = [
            ':emp_id' => $emp_id,
            ':degree_name' => $_POST['degree_name'],
            ':institution_name' => $_POST['institution_name'],
            ':university' => $_POST['university'] ?? null,
            ':completion_year' => $_POST['completion_year'] ? (int)$_POST['completion_year'] : null,
            ':marks' => $_POST['marks'] ? (float)$_POST['marks'] : null,
            ':remarks' => $_POST['remarks'] ?? null,
            ':status' => 1,
        ];

        if ($edu_id) {
            $data[':id'] = $edu_id;
            $stmt = $conn->prepare("
                UPDATE education_details SET 
                    degree_name = :degree_name, institution_name = :institution_name,
                    university = :university, completion_year = :completion_year,
                    marks = :marks, remarks = :remarks
                WHERE id = :id AND emp_id = :emp_id
            ");
            $msg = "Education record updated.";
        } else {
            $stmt = $conn->prepare("
                INSERT INTO education_details (emp_id, degree_name, institution_name, university, 
                                              completion_year, marks, remarks, status)
                VALUES (:emp_id, :degree_name, :institution_name, :university, 
                        :completion_year, :marks, :remarks, :status)
            ");
            $msg = "Education record added.";
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
    <title><?= $edu_id ? 'Edit' : 'Add' ?> Education</title>
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
                <?= $edu_id ? 'Edit' : 'Add' ?> Education – <strong><?= htmlspecialchars($employee['name']) ?></strong>
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
                                <label class="form-label">Degree <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="degree_name" required
                                       value="<?= htmlspecialchars($education['degree_name'] ?? '') ?>"
                                       placeholder="e.g., BSc Computer Science">
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">Institution <span class="text-danger">*</span></label>
                                <input type="text" class="form-control" name="institution_name" required
                                       value="<?= htmlspecialchars($education['institution_name'] ?? '') ?>"
                                       placeholder="e.g., Tribhuvan University">
                            </div>
                        </div>
                    </div>
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label class="form-label">University/Board</label>
                                <input type="text" class="form-control" name="university"
                                       value="<?= htmlspecialchars($education['university'] ?? '') ?>">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Year</label>
                                <input type="number" class="form-control" name="completion_year"
                                       value="<?= $education['completion_year'] ?? '' ?>"
                                       min="1950" max="2030">
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="mb-3">
                                <label class="form-label">Marks (%)</label>
                                <input type="number" class="form-control" name="marks"
                                       value="<?= $education['marks'] ?? '' ?>"
                                       min="0" max="100" step="0.01">
                            </div>
                        </div>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Remarks</label>
                        <textarea class="form-control" name="remarks" rows="2"><?= htmlspecialchars($education['remarks'] ?? '') ?></textarea>
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
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

$emp_stmt = $conn->prepare("SELECT name FROM employee WHERE id = :id");
$emp_stmt->execute([':id' => $emp_id]);
$employee = $emp_stmt->fetch(PDO::FETCH_ASSOC);
if (!$employee) {
    die("Employee not found.");
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (empty($_FILES['document_file']['name'])) {
        $_SESSION['error_message'] = "Please select a file to upload.";
    } else {
        try {
            $upload_dir = $_SERVER['DOCUMENT_ROOT'] . "/deno2/docs/employees/$emp_id/";
            if (!file_exists($upload_dir)) {
                mkdir($upload_dir, 0777, true);
            }

            $file = $_FILES['document_file'];
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            $allowed = ['pdf', 'doc', 'docx', 'jpg', 'jpeg', 'png', 'txt'];
            if (!in_array($ext, $allowed)) {
                throw new Exception("File type not allowed. Use PDF, DOC, JPG, PNG.");
            }

            $filename = "doc_" . time() . "_" . uniqid() . ".$ext";
            $target_path = $upload_dir . $filename;
            $document_path = "docs/employees/$emp_id/$filename";

            if (!move_uploaded_file($file['tmp_name'], $target_path)) {
                throw new Exception("Failed to upload file.");
            }

            $stmt = $conn->prepare("
                INSERT INTO employee_documents (
                    employee_id, document_name, file_path, document_type, created_by, status
                ) VALUES (
                    :employee_id, :document_name, :file_path, :document_type, :created_by, 'ACTIVE'
                )
            ");
            $stmt->execute([
                ':employee_id' => $emp_id,
                ':document_name' => $_POST['document_name'] ?: $file['name'],
                ':file_path' => $document_path,
                ':document_type' => $_POST['document_type'] ?? 'OTHER',
                ':created_by' => $current_user_id
            ]);

            $_SESSION['success_message'] = "Document uploaded successfully.";
            header("Location: view.php?id=$emp_id");
            exit();
        } catch (Exception $e) {
            $_SESSION['error_message'] = "Upload failed: " . $e->getMessage();
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Document</title>
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
                Upload Document – <strong><?= htmlspecialchars($employee['name']) ?></strong>
            </h4>
        </div>

        <?php if (isset($_SESSION['error_message'])): ?>
            <div class="alert alert-danger"><?= htmlspecialchars($_SESSION['error_message']) ?></div>
            <?php unset($_SESSION['error_message']); ?>
        <?php endif; ?>

        <div class="card shadow">
            <div class="card-body">
                <form method="POST" enctype="multipart/form-data">
                    <input type="hidden" name="emp_id" value="<?= $emp_id ?>">
                    <div class="mb-3">
                        <label class="form-label">Document Name</label>
                        <input type="text" class="form-control" name="document_name"
                               placeholder="e.g., CV, Experience Letter">
                    </div>
                    <div class="mb-3">
                        <label class="form-label">Document Type</label>
                        <select class="form-select" name="document_type">
                            <option value="CV">CV/Resume</option>
                            <option value="CERTIFICATE">Certificate</option>
                            <option value="CONTRACT">Contract</option>
                            <option value="ID_COPY">ID Copy</option>
                            <option value="ACADEMIC">Academic</option>
                            <option value="EXPERIENCE">Experience Letter</option>
                            <option value="MEDICAL">Medical Certificate</option>
                            <option value="OTHER">Other</option>
                        </select>
                    </div>
                    <div class="mb-3">
                        <label class="form-label">File <span class="text-danger">*</span></label>
                        <input type="file" class="form-control" name="document_file" required>
                        <small class="text-muted">Allowed: PDF, DOC, DOCX, JPG, PNG (Max 5MB)</small>
                    </div>
                    <div class="d-flex justify-content-end">
                        <a href="view.php?id=<?= $emp_id ?>" class="btn btn-secondary me-2">Cancel</a>
                        <button type="submit" class="btn btn-primary">
                            <i class="fas fa-upload"></i> Upload
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.1.3/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
<?php
// books/edit.php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('editor') && !has_role('admin')) {
    ob_end_clean();
    $_SESSION['error'] = "You don't have permission to edit books.";
    header("Location: index.php");
    exit();
}

$message = '';
$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$book_id) {
    ob_end_clean();
    $_SESSION['error'] = "Book ID not provided.";
    header("Location: index.php");
    exit();
}

// Fetch book details
$stmt = $conn->prepare("SELECT * FROM books WHERE book_id = ?");
$stmt->execute([$book_id]);
$book = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$book) {
    ob_end_clean();
    $_SESSION['error'] = "Book not found.";
    header("Location: index.php");
    exit();
}

// Check if book is used in job tickets (for warnings)
$usage_check = $conn->prepare("
    SELECT COUNT(*) as jt_count,
           COUNT(DISTINCT fiscal_year_id) as fy_count
    FROM job_ticket 
    WHERE book_id = ?
");
$usage_check->execute([$book_id]);
$usage = $usage_check->fetch(PDO::FETCH_ASSOC);
$is_in_use = $usage['jt_count'] > 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        // Check if book code already exists for other books
        $check_stmt = $conn->prepare("SELECT COUNT(*) FROM books WHERE book_code = ? AND book_id != ?");
        $check_stmt->execute([$_POST['book_code'], $book_id]);
        if ($check_stmt->fetchColumn() > 0) {
            throw new Exception("Book code already exists. Please use a unique book code.");
        }
        
        $stmt = $conn->prepare("
            UPDATE books SET
                book_code = ?, book_name = ?, class_level = ?, fiscal_year = ?,
                is_translated = ?, is_optional = ?, updated_at = CURRENT_TIMESTAMP
            WHERE book_id = ?
        ");
        
        $stmt->execute([
            $_POST['book_code'],
            $_POST['book_name'],
            !empty($_POST['class_level']) ? $_POST['class_level'] : null,
            !empty($_POST['fiscal_year']) ? $_POST['fiscal_year'] : null,
            isset($_POST['is_translated']) ? 't' : 'f',
            isset($_POST['is_optional']) ? 't' : 'f',
            $book_id
        ]);
        
        $conn->commit();
        $_SESSION['success'] = 'Book updated successfully!';
        
        ob_end_clean();
        header("Location: view.php?id=" . $book_id);
        exit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>Error: " . $e->getMessage() . "</div>";
    }
}

// Get existing fiscal years for dropdown
$fiscal_years = $conn->query("SELECT DISTINCT fiscal_year FROM books WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get related job tickets for context
$related_jt_stmt = $conn->prepare("
    SELECT jt.job_ticket_code, jt.status, jt.created_date, fy.fiscal_code
    FROM job_ticket jt
    LEFT JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
    WHERE jt.book_id = ?
    ORDER BY jt.created_date DESC
    LIMIT 5
");
$related_jt_stmt->execute([$book_id]);
$related_tickets = $related_jt_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
body {
    font-size: 16px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
}

.container {
    max-width: 1200px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 10px 30px rgba(0,0,0,0.15);
}

.page-header h2 {
    margin: 0;
    font-weight: 700;
    font-size: 2rem;
}

.page-header .breadcrumb {
    background: transparent;
    margin: 0;
    padding: 0;
    margin-top: 10px;
}

.page-header .breadcrumb-item {
    color: rgba(255,255,255,0.8);
}

.page-header .breadcrumb-item.active {
    color: white;
}

.page-header .breadcrumb-item a {
    color: white;
    text-decoration: none;
}

.content-wrapper {
    display: grid;
    grid-template-columns: 2fr 1fr;
    gap: 30px;
}

.form-container {
    background: white;
    padding: 40px;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
}

.sidebar {
    display: flex;
    flex-direction: column;
    gap: 20px;
}

.info-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
}

.info-card-title {
    font-size: 1.2rem;
    font-weight: 700;
    color: #343a40;
    margin-bottom: 15px;
    padding-bottom: 10px;
    border-bottom: 2px solid #667eea;
    display: flex;
    align-items: center;
    gap: 10px;
}

.warning-card {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.warning-card-title {
    font-weight: 700;
    color: #856404;
    margin-bottom: 10px;
    display: flex;
    align-items: center;
    gap: 8px;
}

.warning-card-text {
    color: #856404;
    font-size: 14px;
    margin-bottom: 5px;
}

.form-section {
    margin-bottom: 35px;
}

.form-section-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #343a40;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 3px solid #667eea;
}

.form-row {
    display: flex;
    gap: 20px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.form-group {
    flex: 1;
    min-width: 250px;
}

.form-group.full-width {
    flex: 1 1 100%;
}

.form-group label {
    display: block;
    margin-bottom: 8px;
    font-weight: 600;
    color: #495057;
    font-size: 15px;
}

.form-group label .required {
    color: #dc3545;
    margin-left: 3px;
}

.form-control, .form-select {
    width: 100%;
    padding: 12px 16px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    font-size: 15px;
    transition: all 0.2s ease;
    background-color: white;
}

.form-control:focus, .form-select:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    transform: translateY(-1px);
}

.form-control:read-only {
    background-color: #f8f9fa;
    cursor: not-allowed;
}

.form-control.changed {
    border-color: #ffc107;
    background-color: #fffbf0;
}

.is-invalid {
    border-color: #dc3545 !important;
}

.invalid-feedback {
    color: #dc3545;
    font-size: 14px;
    margin-top: 5px;
    display: block;
}

.form-check {
    display: flex;
    align-items: center;
    margin-bottom: 15px;
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 8px;
    transition: all 0.2s ease;
}

.form-check:hover {
    background-color: #e9ecef;
}

.form-check-input {
    width: 20px;
    height: 20px;
    margin-right: 12px;
    cursor: pointer;
    border: 2px solid #667eea;
}

.form-check-input:checked {
    background-color: #667eea;
    border-color: #667eea;
}

.form-check-label {
    font-weight: 500;
    color: #495057;
    cursor: pointer;
    font-size: 15px;
    margin: 0;
}

.btn {
    padding: 14px 28px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 16px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    text-align: center;
    transition: all 0.3s ease;
    margin-right: 12px;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    box-shadow: 0 4px 15px rgba(102, 126, 234, 0.4);
}

.btn-primary:hover {
    transform: translateY(-2px);
    box-shadow: 0 6px 20px rgba(102, 126, 234, 0.5);
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-secondary:hover {
    background-color: #5a6268;
    transform: translateY(-2px);
}

.btn-info {
    background-color: #17a2b8;
    color: white;
}

.btn-info:hover {
    background-color: #138496;
    transform: translateY(-2px);
}

.alert {
    padding: 16px 20px;
    margin-bottom: 25px;
    border: none;
    border-radius: 10px;
    font-size: 15px;
    font-weight: 500;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-left: 4px solid #28a745;
}

.alert-danger {
    color: #721c24;
    background-color: #f8d7da;
    border-left: 4px solid #dc3545;
}

.alert i {
    margin-right: 8px;
}

.form-help-text {
    font-size: 13px;
    color: #6c757d;
    margin-top: 5px;
    font-style: italic;
}

.action-buttons {
    margin-top: 35px;
    padding-top: 25px;
    border-top: 2px solid #e9ecef;
    display: flex;
    gap: 15px;
    justify-content: flex-start;
}

.input-group {
    position: relative;
}

.input-group-text {
    position: absolute;
    left: 12px;
    top: 50%;
    transform: translateY(-50%);
    color: #6c757d;
    pointer-events: none;
}

.input-group .form-control {
    padding-left: 40px;
}

.autocomplete-wrapper {
    position: relative;
}

.autocomplete-suggestions {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #e9ecef;
    border-top: none;
    border-radius: 0 0 8px 8px;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.autocomplete-suggestion {
    padding: 12px 16px;
    cursor: pointer;
    transition: background-color 0.2s;
    font-size: 14px;
}

.autocomplete-suggestion:hover {
    background-color: #f8f9fa;
}

.autocomplete-suggestion.active {
    background-color: #667eea;
    color: white;
}

.stat-box {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.stat-label {
    font-size: 13px;
    color: #6c757d;
    font-weight: 600;
}

.stat-value {
    font-size: 18px;
    font-weight: 700;
    color: #667eea;
}

.ticket-list {
    list-style: none;
    padding: 0;
    margin: 0;
}

.ticket-item {
    padding: 12px;
    background: #f8f9fa;
    border-radius: 6px;
    margin-bottom: 8px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.ticket-code {
    font-weight: 600;
    color: #333;
    font-size: 14px;
}

.ticket-status {
    font-size: 11px;
    padding: 4px 8px;
    border-radius: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-completed { background: #28a745; color: white; }
.status-pending { background: #ffc107; color: #000; }
.status-processing { background: #17a2b8; color: white; }

.empty-state {
    text-align: center;
    padding: 30px;
    color: #6c757d;
}

@media (max-width: 992px) {
    .content-wrapper {
        grid-template-columns: 1fr;
    }
    
    .form-row {
        flex-direction: column;
    }
    
    .form-group {
        min-width: 100%;
    }
}
</style>

<div class="container">
    <!-- Page Header -->
    <div class="page-header">
        <h2>
            <i class="fas fa-edit me-3"></i>
            Edit Book
        </h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/deno2/">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Books</a></li>
                <li class="breadcrumb-item"><a href="view.php?id=<?= $book_id ?>">View</a></li>
                <li class="breadcrumb-item active">Edit</li>
            </ol>
        </nav>
    </div>

    <?php if ($message): ?>
        <?= $message ?>
    <?php endif; ?>

    <?php if ($is_in_use): ?>
    <div class="warning-card">
        <div class="warning-card-title">
            <i class="fas fa-exclamation-triangle"></i>
            Book In Use - Edit with Caution
        </div>
        <div class="warning-card-text">
            <strong>⚠️ This book is currently used in <?= $usage['jt_count'] ?> job ticket(s) across <?= $usage['fy_count'] ?> fiscal year(s).</strong>
        </div>
        <div class="warning-card-text">
            Changes to critical fields may affect existing job tickets and reports. Please verify before saving.
        </div>
    </div>
    <?php endif; ?>

    <div class="content-wrapper">
        <!-- Main Form -->
        <div class="form-container">
            <form method="post" id="bookForm">
                <!-- Basic Information Section -->
                <div class="form-section">
                    <h3 class="form-section-title">
                        <i class="fas fa-info-circle me-2"></i>Basic Information
                    </h3>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="book_code">
                                Book Code: <span class="required">*</span>
                            </label>
                            <div class="input-group">
                                <span class="input-group-text">
                                    <i class="fas fa-barcode"></i>
                                </span>
                                <input type="text" 
                                       name="book_code" 
                                       id="book_code" 
                                       class="form-control"
                                       value="<?= htmlspecialchars($book['book_code']) ?>"
                                       placeholder="e.g., ENG-09, MATH-10"
                                       required
                                       data-original="<?= htmlspecialchars($book['book_code']) ?>">
                            </div>
                            <div class="invalid-feedback">Please enter a unique book code</div>
                            <small class="form-help-text">Unique identifier for the book</small>
                        </div>

                        <div class="form-group">
                            <label for="class_level">
                                Class Level:
                            </label>
                            <select name="class_level" id="class_level" class="form-select" data-original="<?= $book['class_level'] ?>">
                                <option value="">Select Class Level</option>
                                <?php for ($i = 1; $i <= 12; $i++): ?>
                                    <option value="<?= $i ?>" <?= ($book['class_level'] == $i) ? 'selected' : '' ?>>
                                        Class <?= $i ?>
                                    </option>
                                <?php endfor; ?>
                            </select>
                            <small class="form-help-text">Optional: Select the class level</small>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group full-width">
                            <label for="book_name">
                                Book Name: <span class="required">*</span>
                            </label>
                            <input type="text" 
                                   name="book_name" 
                                   id="book_name" 
                                   class="form-control"
                                   value="<?= htmlspecialchars($book['book_name']) ?>"
                                   placeholder="Enter full book name"
                                   required
                                   data-original="<?= htmlspecialchars($book['book_name']) ?>">
                            <div class="invalid-feedback">Please enter a book name</div>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="fiscal_year">
                                Fiscal Year:
                            </label>
                            <div class="autocomplete-wrapper">
                                <input type="text" 
                                       name="fiscal_year" 
                                       id="fiscal_year" 
                                       class="form-control"
                                       value="<?= htmlspecialchars($book['fiscal_year'] ?? '') ?>"
                                       placeholder="e.g., 2081/82"
                                       autocomplete="off"
                                       data-original="<?= htmlspecialchars($book['fiscal_year'] ?? '') ?>">
                                <div class="autocomplete-suggestions" id="fiscalYearSuggestions">
                                    <?php foreach ($fiscal_years as $fy): ?>
                                        <div class="autocomplete-suggestion" data-value="<?= htmlspecialchars($fy['fiscal_year']) ?>">
                                            <?= htmlspecialchars($fy['fiscal_year']) ?>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                            <small class="form-help-text">Optional: Fiscal year for this book</small>
                        </div>
                    </div>
                </div>

                <!-- Book Properties Section -->
                <div class="form-section">
                    <h3 class="form-section-title">
                        <i class="fas fa-cog me-2"></i>Book Properties
                    </h3>
                    
                    <div class="form-check">
                        <input type="checkbox" 
                               class="form-check-input" 
                               name="is_translated" 
                               id="is_translated"
                               <?= ($book['is_translated'] == 't' || $book['is_translated'] === true) ? 'checked' : '' ?>
                               data-original="<?= ($book['is_translated'] == 't' || $book['is_translated'] === true) ? '1' : '0' ?>">
                        <label class="form-check-label" for="is_translated">
                            <i class="fas fa-language me-2"></i>This book is translated
                        </label>
                    </div>

                    <div class="form-check">
                        <input type="checkbox" 
                               class="form-check-input" 
                               name="is_optional" 
                               id="is_optional"
                               <?= ($book['is_optional'] == 't' || $book['is_optional'] === true) ? 'checked' : '' ?>
                               data-original="<?= ($book['is_optional'] == 't' || $book['is_optional'] === true) ? '1' : '0' ?>">
                        <label class="form-check-label" for="is_optional">
                            <i class="fas fa-star me-2"></i>This is an optional book
                        </label>
                    </div>
                </div>

                <!-- Action Buttons -->
                <div class="action-buttons">
                    <button type="submit" class="btn btn-primary" id="saveBtn">
                        <i class="fas fa-save me-2"></i>Update Book
                    </button>
                    <a href="view.php?id=<?= $book_id ?>" class="btn btn-secondary">
                        <i class="fas fa-times me-2"></i>Cancel
                    </a>
                    <a href="view.php?id=<?= $book_id ?>" class="btn btn-info">
                        <i class="fas fa-eye me-2"></i>View Details
                    </a>
                </div>
            </form>
        </div>

        <!-- Sidebar -->
        <div class="sidebar">
            <!-- Current Book Info -->
            <div class="info-card">
                <div class="info-card-title">
                    <i class="fas fa-book"></i>
                    Current Book Info
                </div>
                <div class="stat-box">
                    <span class="stat-label">Book Code</span>
                    <span class="stat-value"><?= htmlspecialchars($book['book_code']) ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Created</span>
                    <span class="stat-value"><?= date('Y-m-d', strtotime($book['created_at'])) ?></span>
                </div>
                <?php if ($book['updated_at'] && $book['updated_at'] != $book['created_at']): ?>
                <div class="stat-box">
                    <span class="stat-label">Last Updated</span>
                    <span class="stat-value"><?= date('Y-m-d', strtotime($book['updated_at'])) ?></span>
                </div>
                <?php endif; ?>
                <div class="stat-box">
                    <span class="stat-label">Created By</span>
                    <span class="stat-value"><?= htmlspecialchars($book['created_by']) ?></span>
                </div>
            </div>

            <!-- Usage Statistics -->
            <div class="info-card">
                <div class="info-card-title">
                    <i class="fas fa-chart-bar"></i>
                    Usage Statistics
                </div>
                <div class="stat-box">
                    <span class="stat-label">Job Tickets</span>
                    <span class="stat-value"><?= $usage['jt_count'] ?></span>
                </div>
                <div class="stat-box">
                    <span class="stat-label">Fiscal Years</span>
                    <span class="stat-value"><?= $usage['fy_count'] ?></span>
                </div>
            </div>

            <!-- Recent Job Tickets -->
            <?php if (!empty($related_tickets)): ?>
            <div class="info-card">
                <div class="info-card-title">
                    <i class="fas fa-tasks"></i>
                    Recent Job Tickets
                </div>
                <ul class="ticket-list">
                    <?php foreach ($related_tickets as $ticket): ?>
                    <li class="ticket-item">
                        <div>
                            <div class="ticket-code"><?= htmlspecialchars($ticket['job_ticket_code']) ?></div>
                            <small style="color: #6c757d;"><?= htmlspecialchars($ticket['fiscal_code']) ?></small>
                        </div>
                        <span class="ticket-status status-<?= $ticket['status'] ?>">
                            <?= ucfirst($ticket['status']) ?>
                        </span>
                    </li>
                    <?php endforeach; ?>
                </ul>
            </div>
            <?php else: ?>
            <div class="info-card">
                <div class="info-card-title">
                    <i class="fas fa-tasks"></i>
                    Job Tickets
                </div>
                <div class="empty-state">
                    <i class="fas fa-inbox fa-2x"></i>
                    <p>No job tickets yet</p>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bookForm = document.getElementById('bookForm');
    const bookCodeInput = document.getElementById('book_code');
    const fiscalYearInput = document.getElementById('fiscal_year');
    const fiscalYearSuggestions = document.getElementById('fiscalYearSuggestions');
    const saveBtn = document.getElementById('saveBtn');
    
    let formChanged = false;
    let originalValues = {};

    // Store original values
    document.querySelectorAll('[data-original]').forEach(input => {
        originalValues[input.name || input.id] = input.getAttribute('data-original');
    });

    // Track changes and highlight modified fields
    function checkForChanges() {
        let hasChanges = false;
        
        document.querySelectorAll('[data-original]').forEach(input => {
            let currentValue = input.type === 'checkbox' ? (input.checked ? '1' : '0') : input.value;
            let originalValue = input.getAttribute('data-original');
            
            if (currentValue !== originalValue) {
                input.classList.add('changed');
                hasChanges = true;
            } else {
                input.classList.remove('changed');
            }
        });
        
        if (hasChanges) {
            saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Save Changes';
            saveBtn.style.animation = 'pulse 1.5s infinite';
        } else {
            saveBtn.innerHTML = '<i class="fas fa-save me-2"></i>Update Book';
            saveBtn.style.animation = 'none';
        }
        
        formChanged = hasChanges;
    }

    // Monitor all inputs
    document.querySelectorAll('input, select, textarea').forEach(input => {
        input.addEventListener('input', checkForChanges);
        input.addEventListener('change', checkForChanges);
    });

    // Form validation
    if (bookForm) {
        bookForm.addEventListener('submit', function(e) {
            let isValid = true;

            // Validate book code
            if (!bookCodeInput.value.trim()) {
                bookCodeInput.classList.add('is-invalid');
                isValid = false;
            } else {
                bookCodeInput.classList.remove('is-invalid');
            }

            // Validate book name
            const bookNameInput = document.getElementById('book_name');
            if (!bookNameInput.value.trim()) {
                bookNameInput.classList.add('is-invalid');
                isValid = false;
            } else {
                bookNameInput.classList.remove('is-invalid');
            }

            if (!isValid) {
                e.preventDefault();
                alert('Please fill in all required fields.');
            }
        });
    }

    // Auto-uppercase book code
    if (bookCodeInput) {
        bookCodeInput.addEventListener('input', function() {
            this.value = this.value.toUpperCase();
        });

        bookCodeInput.addEventListener('blur', function() {
            this.classList.remove('is-invalid');
        });
    }

    // Fiscal year autocomplete
    if (fiscalYearInput && fiscalYearSuggestions) {
        fiscalYearInput.addEventListener('focus', function() {
            if (fiscalYearSuggestions.children.length > 0) {
                fiscalYearSuggestions.style.display = 'block';
            }
        });

        fiscalYearInput.addEventListener('input', function() {
            const searchTerm = this.value.toLowerCase();
            const suggestions = fiscalYearSuggestions.querySelectorAll('.autocomplete-suggestion');
            let hasVisible = false;

            suggestions.forEach(suggestion => {
                const text = suggestion.getAttribute('data-value').toLowerCase();
                if (text.includes(searchTerm)) {
                    suggestion.style.display = 'block';
                    hasVisible = true;
                } else {
                    suggestion.style.display = 'none';
                }
            });

            fiscalYearSuggestions.style.display = hasVisible ? 'block' : 'none';
        });

        fiscalYearSuggestions.addEventListener('click', function(e) {
            if (e.target.classList.contains('autocomplete-suggestion')) {
                fiscalYearInput.value = e.target.getAttribute('data-value');
                fiscalYearSuggestions.style.display = 'none';
                checkForChanges();
            }
        });

        document.addEventListener('click', function(e) {
            if (!fiscalYearInput.contains(e.target) && !fiscalYearSuggestions.contains(e.target)) {
                fiscalYearSuggestions.style.display = 'none';
            }
        });

        // Keyboard navigation
        let selectedIndex = -1;
        fiscalYearInput.addEventListener('keydown', function(e) {
            const suggestions = Array.from(fiscalYearSuggestions.querySelectorAll('.autocomplete-suggestion:not([style*="display: none"])'));
            
            if (e.key === 'ArrowDown') {
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, suggestions.length - 1);
                updateSelection(suggestions);
            } else if (e.key === 'ArrowUp') {
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, 0);
                updateSelection(suggestions);
            } else if (e.key === 'Enter' && selectedIndex >= 0) {
                e.preventDefault();
                fiscalYearInput.value = suggestions[selectedIndex].getAttribute('data-value');
                fiscalYearSuggestions.style.display = 'none';
                selectedIndex = -1;
                checkForChanges();
            } else if (e.key === 'Escape') {
                fiscalYearSuggestions.style.display = 'none';
                selectedIndex = -1;
            }
        });

        function updateSelection(suggestions) {
            suggestions.forEach((suggestion, index) => {
                if (index === selectedIndex) {
                    suggestion.classList.add('active');
                    suggestion.scrollIntoView({ block: 'nearest' });
                } else {
                    suggestion.classList.remove('active');
                }
            });
        }
    }

    // Warn before leaving with unsaved changes
    window.addEventListener('beforeunload', function(e) {
        if (formChanged && !bookForm.submitted) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    bookForm.addEventListener('submit', function() {
        bookForm.submitted = true;
    });

    // Add pulse animation
    const style = document.createElement('style');
    style.textContent = `
        @keyframes pulse {
            0%, 100% { transform: scale(1); }
            50% { transform: scale(1.05); }
        }
    `;
    document.head.appendChild(style);
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>


<?php
// books/create.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('editor') && !has_role('admin')) {
    $_SESSION['error'] = "You don't have permission to create books.";
    header("Location: index.php");
    exit();
}

$message = '';
$edit_book = null;

// Handle edit mode
if (isset($_GET['id'])) {
    $stmt = $conn->prepare("SELECT * FROM books WHERE book_id = ?");
    $stmt->execute([$_GET['id']]);
    $edit_book = $stmt->fetch();
    
    if (!$edit_book) {
        $_SESSION['error'] = "Book not found.";
        header("Location: index.php");
        exit();
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    try {
        $conn->beginTransaction();
        
        switch ($action) {
            case 'create':
                // Check if book code already exists
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM books WHERE book_code = ?");
                $check_stmt->execute([$_POST['book_code']]);
                if ($check_stmt->fetchColumn() > 0) {
                    throw new Exception("Book code already exists. Please use a unique book code.");
                }
                
                // Get current username
                $user_stmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
                $user_stmt->execute([$_SESSION['user_id']]);
                $username = $user_stmt->fetchColumn();
                
                $stmt = $conn->prepare("
                    INSERT INTO books (
                        book_code, book_name, class_level, fiscal_year,
                        is_translated, is_optional, business_associated, book_type, created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $_POST['book_code'],
                    $_POST['book_name'],
                    !empty($_POST['class_level']) ? $_POST['class_level'] : null,
                    !empty($_POST['fiscal_year']) ? $_POST['fiscal_year'] : null,
                    isset($_POST['is_translated']) ? 't' : 'f',
                    isset($_POST['is_optional']) ? 't' : 'f',
                    $_POST['business_associated'] ?? 'CDC',
                    $_POST['book_type'] ?? 'TextBook',
                    $username
                ]);
                
                $conn->commit();
                $_SESSION['success'] = 'Book created successfully!';
                
                echo "<script>window.location.href='index.php';</script>";
                exit();
                
            case 'update':
                if (!isset($_POST['id'])) {
                    throw new Exception("Invalid request for update.");
                }
                
                $book_id = $_POST['id'];
                
                // Check if book code already exists for other books
                $check_stmt = $conn->prepare("SELECT COUNT(*) FROM books WHERE book_code = ? AND book_id != ?");
                $check_stmt->execute([$_POST['book_code'], $book_id]);
                if ($check_stmt->fetchColumn() > 0) {
                    throw new Exception("Book code already exists. Please use a unique book code.");
                }
                
                $stmt = $conn->prepare("
                    UPDATE books SET
                        book_code = ?, book_name = ?, class_level = ?, fiscal_year = ?,
                        is_translated = ?, is_optional = ?, business_associated = ?, 
                        book_type = ?, updated_at = CURRENT_TIMESTAMP
                    WHERE book_id = ?
                ");
                
                $stmt->execute([
                    $_POST['book_code'],
                    $_POST['book_name'],
                    !empty($_POST['class_level']) ? $_POST['class_level'] : null,
                    !empty($_POST['fiscal_year']) ? $_POST['fiscal_year'] : null,
                    isset($_POST['is_translated']) ? 't' : 'f',
                    isset($_POST['is_optional']) ? 't' : 'f',
                    $_POST['business_associated'] ?? 'CDC',
                    $_POST['book_type'] ?? 'TextBook',
                    $book_id
                ]);
                
                $conn->commit();
                $_SESSION['success'] = 'Book updated successfully!';
                
                echo "<script>window.location.href='index.php';</script>";
                exit();
        }
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "<div class='alert alert-danger'><i class='fas fa-exclamation-triangle me-2'></i>Error: " . $e->getMessage() . "</div>";
    }
}

// Get existing fiscal years for dropdown
$fiscal_years = $conn->query("SELECT DISTINCT fiscal_year FROM books WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC")->fetchAll();
?>

<style>
body {
    font-size: 16px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
}

.container {
    max-width: 1000px;
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

.form-container {
    background: white;
    padding: 40px;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
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

@media (max-width: 768px) {
    .form-row {
        flex-direction: column;
    }
    
    .form-group {
        min-width: 100%;
    }
    
    .page-header h2 {
        font-size: 1.5rem;
    }
    
    .form-container {
        padding: 25px;
    }
}
</style>

<div class="container">
    <!-- Page Header -->
    <div class="page-header">
        <h2>
            <i class="fas fa-book me-3"></i>
            <?= $edit_book ? 'Edit Book' : 'Create New Book' ?>
        </h2>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/deno2/">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Books</a></li>
                <li class="breadcrumb-item active"><?= $edit_book ? 'Edit' : 'Create' ?></li>
            </ol>
        </nav>
    </div>

    <?php if ($message): ?>
        <?= $message ?>
    <?php endif; ?>

    <div class="form-container">
        <form method="post" id="bookForm">
            <input type="hidden" name="action" value="<?= $edit_book ? 'update' : 'create' ?>">
            <?php if ($edit_book): ?>
                <input type="hidden" name="id" value="<?= $edit_book['book_id'] ?>">
            <?php endif; ?>

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
                                   value="<?= $edit_book ? htmlspecialchars($edit_book['book_code']) : '' ?>"
                                   placeholder="e.g., ENG-09, MATH-10"
                                   required
                                   <?= $edit_book ? '' : 'autofocus' ?>>
                        </div>
                        <div class="invalid-feedback">Please enter a unique book code</div>
                        <small class="form-help-text">Unique identifier for the book (e.g., SUBJECT-CLASS)</small>
                    </div>

                    <div class="form-group">
                        <label for="class_level">
                            Class Level:
                        </label>
                        <select name="class_level" id="class_level" class="form-select">
                            <option value="">Select Class Level</option>
                            <?php for ($i = 1; $i <= 12; $i++): ?>
                                <option value="<?= $i ?>" <?= ($edit_book && $edit_book['class_level'] == $i) ? 'selected' : '' ?>>
                                    Class <?= $i ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <small class="form-help-text">Optional: Select the class level for this book</small>
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
                               value="<?= $edit_book ? htmlspecialchars($edit_book['book_name']) : '' ?>"
                               placeholder="Enter full book name"
                               required>
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
                                   value="<?= $edit_book ? htmlspecialchars($edit_book['fiscal_year']) : '' ?>"
                                   placeholder="e.g., 2081/82"
                                   autocomplete="off">
                            <div class="autocomplete-suggestions" id="fiscalYearSuggestions">
                                <?php foreach ($fiscal_years as $fy): ?>
                                    <div class="autocomplete-suggestion" data-value="<?= htmlspecialchars($fy['fiscal_year']) ?>">
                                        <?= htmlspecialchars($fy['fiscal_year']) ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>
                        <small class="form-help-text">Optional: Fiscal year for this book (e.g., 2081/82)</small>
                    </div>
                </div>
            </div>

            <!-- Business & Type Classification Section -->
            <div class="form-section">
                <h3 class="form-section-title">
                    <i class="fas fa-building me-2"></i>Business & Type Classification
                </h3>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="business_associated">
                            Business Associated: <span class="required">*</span>
                        </label>
                        <select name="business_associated" id="business_associated" class="form-select" required>
                            <option value="CDC" <?= ($edit_book && $edit_book['business_associated'] == 'CDC') ? 'selected' : '' ?>>CDC - Curriculum Development Centre</option>
                            <option value="JEMC" <?= ($edit_book && $edit_book['business_associated'] == 'JEMC') ? 'selected' : '' ?>>JEMC - Janak Education Materials Centre</option>
                            <option value="NTC" <?= ($edit_book && $edit_book['business_associated'] == 'NTC') ? 'selected' : '' ?>>NTC - Nepal Telecom</option>
                            <option value="NEB" <?= ($edit_book && $edit_book['business_associated'] == 'NEB') ? 'selected' : '' ?>>NEB - National Examination Board</option>
                        </select>
                        <small class="form-help-text">Select the business/organization this book is associated with</small>
                    </div>

                    <div class="form-group">
                        <label for="book_type">
                            Book Type: <span class="required">*</span>
                        </label>
                        <select name="book_type" id="book_type" class="form-select" required>
                            <option value="TextBook" <?= ($edit_book && $edit_book['book_type'] == 'TextBook') ? 'selected' : '' ?>>Text Book</option>
                            <option value="Copy" <?= ($edit_book && $edit_book['book_type'] == 'Copy') ? 'selected' : '' ?>>Copy</option>
                            <option value="RechargeCard" <?= ($edit_book && $edit_book['book_type'] == 'RechargeCard') ? 'selected' : '' ?>>Recharge Card</option>
                            <option value="Lalpurja" <?= ($edit_book && $edit_book['book_type'] == 'Lalpurja') ? 'selected' : '' ?>>Lalpurja</option>
                            <option value="QuestionPaper" <?= ($edit_book && $edit_book['book_type'] == 'QuestionPaper') ? 'selected' : '' ?>>Question Paper</option>
                        </select>
                        <small class="form-help-text">Select the type of book/material</small>
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
                           <?= ($edit_book && $edit_book['is_translated']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_translated">
                        <i class="fas fa-language me-2"></i>This book is translated
                    </label>
                </div>

                <div class="form-check">
                    <input type="checkbox" 
                           class="form-check-input" 
                           name="is_optional" 
                           id="is_optional"
                           <?= ($edit_book && $edit_book['is_optional']) ? 'checked' : '' ?>>
                    <label class="form-check-label" for="is_optional">
                        <i class="fas fa-star me-2"></i>This is an optional book
                    </label>
                </div>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save me-2"></i>
                    <?= $edit_book ? 'Update Book' : 'Create Book' ?>
                </button>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-times me-2"></i>Cancel
                </a>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const bookForm = document.getElementById('bookForm');
    const bookCodeInput = document.getElementById('book_code');
    const fiscalYearInput = document.getElementById('fiscal_year');
    const fiscalYearSuggestions = document.getElementById('fiscalYearSuggestions');

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

        // Click on suggestion
        fiscalYearSuggestions.addEventListener('click', function(e) {
            if (e.target.classList.contains('autocomplete-suggestion')) {
                fiscalYearInput.value = e.target.getAttribute('data-value');
                fiscalYearSuggestions.style.display = 'none';
            }
        });

        // Close suggestions when clicking outside
        document.addEventListener('click', function(e) {
            if (!fiscalYearInput.contains(e.target) && !fiscalYearSuggestions.contains(e.target)) {
                fiscalYearSuggestions.style.display = 'none';
            }
        });

        // Keyboard navigation for suggestions
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

    // Auto-generate book code suggestion based on class level
    const classLevelSelect = document.getElementById('class_level');
    if (classLevelSelect && !<?= $edit_book ? 'true' : 'false' ?>) {
        classLevelSelect.addEventListener('change', function() {
            if (this.value && !bookCodeInput.value) {
                // This is just a helper, user can modify
                const suggestion = `BOOK-${this.value.padStart(2, '0')}`;
                bookCodeInput.placeholder = `Suggestion: ${suggestion}`;
            }
        });
    }

    // Confirm before leaving if form has changes
    let formChanged = false;
    const formInputs = bookForm.querySelectorAll('input, select, textarea');
    
    formInputs.forEach(input => {
        input.addEventListener('change', function() {
            formChanged = true;
        });
    });

    window.addEventListener('beforeunload', function(e) {
        if (formChanged && !bookForm.submitted) {
            e.preventDefault();
            e.returnValue = '';
        }
    });

    bookForm.addEventListener('submit', function() {
        bookForm.submitted = true;
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
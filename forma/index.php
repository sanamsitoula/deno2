<?php
// Start output buffering FIRST - before any includes
ob_start();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Initialize variables for filtering
$search_term = $_GET['search'] ?? '';
$book_filter = $_GET['book_filter'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';
$class_filter = $_GET['class_filter'] ?? '';
$page = $_GET['page'] ?? 1;
$limit = 50;
$offset = ($page - 1) * $limit;

// Handle CRUD operations
$error_message = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    // Only allow editors and admins to modify data
    if (!has_role('editor') && !has_role('admin')) {
        $error_message = "You don't have permission to perform this action.";
    } else {
        try {
            switch ($action) {
                case 'create':
                    // Check for duplicate order_no with same book_id
                    $check_stmt = $conn->prepare("
                        SELECT id FROM forma 
                        WHERE order_no = :order_no AND book_id = :book_id
                        LIMIT 1
                    ");
                    $check_stmt->execute([
                        ':order_no' => $_POST['order_no'],
                        ':book_id' => $_POST['book_id']
                    ]);
                    
                    if ($check_stmt->fetch()) {
                        $error_message = "Error: Order number " . $_POST['order_no'] . " already exists for this book. Please use a unique order number for each book.";
                        break;
                    }
                    
                    $stmt = $conn->prepare("
                        INSERT INTO forma (
                            name, status, page, remarks, order_no, book_id
                        ) VALUES (
                            :name, :status, :page, :remarks, :order_no, :book_id
                        )
                    ");
                    
                    $stmt->execute([
                        ':name' => $_POST['name'],
                        ':status' => $_POST['status'],
                        ':page' => $_POST['page'],
                        ':remarks' => $_POST['remarks'],
                        ':order_no' => $_POST['order_no'],
                        ':book_id' => $_POST['book_id']
                    ]);
                    
                    $_SESSION['success_message'] = "Forma record added successfully!";
                    
                    // Clean output buffer and redirect
                    ob_end_clean();
                    header("Location: ".$_SERVER['PHP_SELF']);
                    exit();
                    break;
                    
                case 'update':
                    // Check for duplicate order_no with same book_id (excluding current record)
                    $check_stmt = $conn->prepare("
                        SELECT id FROM forma 
                        WHERE order_no = :order_no AND book_id = :book_id AND id != :id
                        LIMIT 1
                    ");
                    $check_stmt->execute([
                        ':order_no' => $_POST['order_no'],
                        ':book_id' => $_POST['book_id'],
                        ':id' => $_POST['id']
                    ]);
                    
                    if ($check_stmt->fetch()) {
                        $error_message = "Error: Order number " . $_POST['order_no'] . " already exists for this book. Please use a unique order number for each book.";
                        break;
                    }
                    
                    $stmt = $conn->prepare("
                        UPDATE forma SET 
                            name = :name, 
                            status = :status, 
                            page = :page, 
                            remarks = :remarks,
                            order_no = :order_no,
                            book_id = :book_id
                        WHERE id = :id
                    ");
                    
                    $stmt->execute([
                        ':id' => $_POST['id'],
                        ':name' => $_POST['name'],
                        ':status' => $_POST['status'],
                        ':page' => $_POST['page'],
                        ':remarks' => $_POST['remarks'],
                        ':order_no' => $_POST['order_no'],
                        ':book_id' => $_POST['book_id']
                    ]);
                    
                    $_SESSION['success_message'] = "Forma record updated successfully!";
                    
                    // Clean output buffer and redirect
                    ob_end_clean();
                    header("Location: ".$_SERVER['PHP_SELF']);
                    exit();
                    break;
                    
                case 'delete':
                    $stmt = $conn->prepare("DELETE FROM forma WHERE id = :id");
                    $stmt->execute([':id' => $_POST['id']]);
                    
                    $_SESSION['success_message'] = "Forma record deleted successfully!";
                    
                    // Clean output buffer and redirect
                    ob_end_clean();
                    header("Location: ".$_SERVER['PHP_SELF']);
                    exit();
                    break;
            }
        } catch (PDOException $e) {
            $error_message = "Error: " . $e->getMessage();
        }
    }
}

// If we reach here, no redirect occurred, so end output buffering and flush content
ob_end_flush();

// Display success message if set
if (isset($_SESSION['success_message'])) {
    echo "<div class='alert alert-success'>".$_SESSION['success_message']."</div>";
    unset($_SESSION['success_message']);
}

// Fetch books for dropdown
$books = $conn->query("SELECT book_id, book_code, book_name, class_level FROM books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

// Build query for forma records with filters
$query = "
    SELECT f.*, b.book_name, b.book_code, b.class_level 
    FROM forma f 
    LEFT JOIN books b ON f.book_id = b.book_id 
    WHERE 1=1
";

$params = [];
$count_params = [];

// Apply filters
if (!empty($search_term)) {
    $query .= " AND (f.name LIKE :search OR f.remarks LIKE :search OR b.book_name LIKE :search OR b.book_code LIKE :search)";
    $params[':search'] = "%$search_term%";
    $count_params[':search'] = "%$search_term%";
}

if (!empty($book_filter)) {
    $query .= " AND f.book_id = :book_id";
    $params[':book_id'] = $book_filter;
    $count_params[':book_id'] = $book_filter;
}

if (!empty($status_filter)) {
    $query .= " AND f.status = :status";
    $params[':status'] = $status_filter;
    $count_params[':status'] = $status_filter;
}

if (!empty($class_filter)) {
    $query .= " AND b.class_level = :class_level";
    $params[':class_level'] = $class_filter;
    $count_params[':class_level'] = $class_filter;
}

// Count total records for pagination
$count_query = "SELECT COUNT(*) as total FROM forma f LEFT JOIN books b ON f.book_id = b.book_id WHERE 1=1";

// Add the same filters to count query as the main query
if (!empty($search_term)) {
    $count_query .= " AND (f.name LIKE :search OR f.remarks LIKE :search OR b.book_name LIKE :search OR b.book_code LIKE :search)";
}

if (!empty($book_filter)) {
    $count_query .= " AND f.book_id = :book_id";
}

if (!empty($status_filter)) {
    $count_query .= " AND f.status = :status";
}

if (!empty($class_filter)) {
    $count_query .= " AND b.class_level = :class_level";
}

$count_stmt = $conn->prepare($count_query);

// Bind parameters if they exist
if (!empty($search_term)) {
    $count_stmt->bindValue(':search', "%$search_term%");
}

if (!empty($book_filter)) {
    $count_stmt->bindValue(':book_id', $book_filter);
}

if (!empty($status_filter)) {
    $count_stmt->bindValue(':status', $status_filter);
}

if (!empty($class_filter)) {
    $count_stmt->bindValue(':class_level', $class_filter);
}

$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $limit);

// Complete the main query with ordering and pagination
$query .= " ORDER BY f.id DESC LIMIT :limit OFFSET :offset";

// Fetch filtered forma records
$stmt = $conn->prepare($query);

// Bind search parameters if they exist
if (!empty($search_term)) {
    $stmt->bindValue(':search', "%$search_term%");
}

if (!empty($book_filter)) {
    $stmt->bindValue(':book_id', $book_filter);
}

if (!empty($status_filter)) {
    $stmt->bindValue(':status', $status_filter);
}

if (!empty($class_level)) {
    $stmt->bindValue(':class_level', $class_filter);
}

// Bind pagination parameters
$stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);

$stmt->execute();
$forma_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique class levels for filter
$class_levels = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);

// Get record for editing if edit_id is provided
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM forma WHERE id = :id");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Helper function for pagination
function buildQueryString($newParams) {
    $currentParams = $_GET;
    foreach ($newParams as $key => $value) {
        $currentParams[$key] = $value;
    }
    return http_build_query($currentParams);
}
?>

<!-- Rest of your HTML remains the same -->
<style>
/* Error message styling */
.error-message {
    color: #dc3545;
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    padding: 10px 15px;
    border-radius: 4px;
    margin-bottom: 15px;
    font-weight: 500;
}

/* Alert styling */
.alert {
    padding: 15px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}

/* Compact form styling */
.compact-form {
    background: #f8f9fa;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.compact-form .form-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 10px;
}

.compact-form .form-group {
    flex: 1;
    min-width: 150px;
}

.compact-form label {
    display: block;
    margin-bottom: 3px;
    font-size: 13px;
    font-weight: 600;
}

.compact-form input, 
.compact-form select, 
.compact-form textarea {
    width: 100%;
    padding: 8px 10px;
    font-size: 13px;
    border: 1px solid #ddd;
    border-radius: 3px;
}

.compact-form .btn {
    padding: 8px 15px;
    font-size: 13px;
}

/* Button styling */
.btn {
    display: inline-block;
    padding: 6px 12px;
    margin-bottom: 0;
    font-size: 14px;
    font-weight: 400;
    line-height: 1.42857143;
    text-align: center;
    white-space: nowrap;
    vertical-align: middle;
    cursor: pointer;
    border: 1px solid transparent;
    border-radius: 4px;
    text-decoration: none;
}

.btn-primary {
    color: #fff;
    background-color: #007bff;
    border-color: #007bff;
}

.btn-secondary {
    color: #fff;
    background-color: #6c757d;
    border-color: #6c757d;
}

.btn-warning {
    color: #212529;
    background-color: #ffc107;
    border-color: #ffc107;
}

.btn-danger {
    color: #fff;
    background-color: #dc3545;
    border-color: #dc3545;
}

.btn-sm {
    padding: 4px 8px;
    font-size: 12px;
    margin-right: 3px;
}

/* Search dropdown styling (same as deno.php) */
.search-dropdown {
    position: relative;
}

.dropdown-search {
    width: 100%;
    padding: 8px 10px;
    border: 1px solid #ddd;
    border-radius: 3px;
    font-size: 13px;
}

.dropdown-options {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.dropdown-option {
    padding: 8px 10px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    font-size: 13px;
}

.dropdown-option:hover {
    background-color: #f8f9fa;
}

.dropdown-option:last-child {
    border-bottom: none;
}

/* Filter section styling */
.filter-section {
    background: #e9ecef;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 15px;
}

.filter-section .filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 10px;
    margin-bottom: 10px;
}

.filter-section .filter-group {
    flex: 1;
    min-width: 180px;
}

.filter-section label {
    display: block;
    margin-bottom: 3px;
    font-size: 13px;
    font-weight: 600;
}

.filter-section input, 
.filter-section select {
    width: 100%;
    padding: 8px 10px;
    font-size: 13px;
    border: 1px solid #ced4da;
    border-radius: 3px;
}

/* Table styling */
.table-container {
    background: white;
    border-radius: 5px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.compact-table {
    width: 100%;
    font-size: 13px;
    border-collapse: collapse;
}

.compact-table th, 
.compact-table td {
    padding: 8px 10px;
    border: 1px solid #ddd;
    text-align: left;
}

.compact-table th {
    background-color: #f5f5f5;
    font-weight: 600;
}

.compact-table tr:nth-child(even) {
    background-color: #f9f9f9;
}

/* Status badges */
.status-badge {
    display: inline-block;
    padding: 3px 8px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}

.status-active {
    background-color: #d4edda;
    color: #155724;
}

.status-inactive {
    background-color: #f8d7da;
    color: #721c24;
}

/* Pagination styling */
.pagination {
    display: flex;
    justify-content: center;
    margin-top: 15px;
    gap: 5px;
}

.pagination a, 
.pagination span {
    padding: 6px 12px;
    border: 1px solid #dee2e6;
    border-radius: 3px;
    text-decoration: none;
    color: #007bff;
}

.pagination a:hover {
    background-color: #e9ecef;
}

.pagination .current {
    background-color: #007bff;
    color: white;
    border-color: #007bff;
}

/* Responsive adjustments */
@media (max-width: 768px) {
    .compact-form .form-group,
    .filter-section .filter-group {
        min-width: 100%;
    }
}
</style>

<div class="container">
<h2><?= $edit_record ? 'Edit Forma Entry' : 'Add Forma Entry' ?></h2>

<?php if ($error_message): ?>
    <div class="error-message"><?= $error_message ?></div>
<?php endif; ?>

<div class="compact-form">
    <form method="post" id="formaForm">
        <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
        <?php if ($edit_record): ?>
            <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
        <?php endif; ?>
        
        <div class="form-row">
            <div class="form-group">
                <label for="name">Name</label>
                <input type="text" name="name" id="name" class="form-control" 
                       value="<?= $edit_record['name'] ?? '' ?>" required>
            </div>
            
            <div class="form-group">
                <label for="status">Status</label>
                <select name="status" id="status" class="form-control" required>
                    <option value="active" <?= ($edit_record['status'] ?? '') === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= ($edit_record['status'] ?? '') === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="page">Page</label>
                <input type="number" name="page" id="page" class="form-control" 
                       value="<?= $edit_record['page'] ?? '' ?>" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="book_id">Book</label>
                <div class="search-dropdown">
                    <input type="text" 
                           class="dropdown-search" 
                           id="book_search" 
                           placeholder="Search book..."
                           autocomplete="off">
                    <input type="hidden" name="book_id" id="book_id" value="<?= $edit_record['book_id'] ?? '' ?>">
                    <div class="dropdown-options" id="book_options">
                        <?php foreach ($books as $book): ?>
                            <div class="dropdown-option" 
                                 data-value="<?= $book['book_id'] ?>"
                                 data-text="<?= $book['book_name'] ?> (<?= $book['book_code'] ?>) - Class <?= $book['class_level'] ?>">
                                <?= $book['book_name'] ?> (<?= $book['book_code'] ?>) - Class <?= $book['class_level'] ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="order_no">Order No</label>
                <input type="number" name="order_no" id="order_no" class="form-control" 
                       value="<?= $edit_record['order_no'] ?? '' ?>" required>
            </div>
            
            <div class="form-group">
                <label for="remarks">Remarks</label>
                <textarea name="remarks" id="remarks" class="form-control" rows="1"><?= $edit_record['remarks'] ?? '' ?></textarea>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <?= $edit_record ? 'Update' : 'Save' ?>
                </button>
                <?php if ($edit_record): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<h3>Forma Records</h3>

<div class="filter-section">
    <form method="get" id="filterForm">
        <div class="filter-row">
            <div class="filter-group">
                <label for="search">Search</label>
                <input type="text" name="search" id="search" class="form-control" 
                       placeholder="Search by name, remarks, book..." value="<?= htmlspecialchars($search_term) ?>">
            </div>
            
            <div class="filter-group">
                <label for="book_filter">Book</label>
                <select name="book_filter" id="book_filter" class="form-control">
                    <option value="">All Books</option>
                    <?php foreach ($books as $book): ?>
                        <option value="<?= $book['book_id'] ?>" <?= $book_filter == $book['book_id'] ? 'selected' : '' ?>>
                            <?= $book['book_name'] ?> (<?= $book['book_code'] ?>)
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="status_filter">Status</label>
                <select name="status_filter" id="status_filter" class="form-control">
                    <option value="">All Statuses</option>
                    <option value="active" <?= $status_filter === 'active' ? 'selected' : '' ?>>Active</option>
                    <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Inactive</option>
                </select>
            </div>
            
            <div class="filter-group">
                <label for="class_filter">Class</label>
                <select name="class_filter" id="class_filter" class="form-control">
                    <option value="">All Classes</option>
                    <?php foreach ($class_levels as $class): ?>
                        <option value="<?= $class ?>" <?= $class_filter == $class ? 'selected' : '' ?>>Class <?= $class ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="filter-row">
            <div class="filter-group">
                <button type="submit" class="btn btn-primary">Apply Filters</button>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Reset</a>
            </div>
        </div>
    </form>
</div>

<div class="table-container">
    <table class="compact-table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Name</th>
                <th>Status</th>
                <th>Page</th>
                <th>Book</th>
                <th>Class</th>
                <th>Order No</th>
                <th>Remarks</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($forma_records as $record): ?>
            <tr>
                <td><?= $record['id'] ?></td>
                <td><?= htmlspecialchars($record['name']) ?></td>
                <td>
                    <span class="status-badge status-<?= $record['status'] ?>">
                        <?= ucfirst($record['status']) ?>
                    </span>
                </td>
                <td><?= $record['page'] ?></td>
                <td><?= htmlspecialchars($record['book_name']) ?> (<?= $record['book_code'] ?>)</td>
                <td><?= $record['class_level'] ?></td>
                <td><?= $record['order_no'] ?></td>
                <td><?= substr(htmlspecialchars($record['remarks']), 0, 30) ?><?= strlen($record['remarks']) > 30 ? '...' : '' ?></td>
                <td>
                    <a href="?edit_id=<?= $record['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <form method="post" style="display: inline;" 
                          onsubmit="return confirm('Are you sure you want to delete this record?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $record['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination">
    <?php if ($page > 1): ?>
        <a href="?<?= buildQueryString(['page' => $page - 1]) ?>">&laquo; Previous</a>
    <?php endif; ?>
    
    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
        <?php if ($i == $page): ?>
            <span class="current"><?= $i ?></span>
        <?php else: ?>
            <a href="?<?= buildQueryString(['page' => $i]) ?>"><?= $i ?></a>
        <?php endif; ?>
    <?php endfor; ?>
    
    <?php if ($page < $total_pages): ?>
        <a href="?<?= buildQueryString(['page' => $page + 1]) ?>">Next &raquo;</a>
    <?php endif; ?>
</div>
<?php endif; ?>
    </div>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('book_search');
    const hiddenInput = document.getElementById('book_id');
    const optionsContainer = document.getElementById('book_options');
    const options = optionsContainer.querySelectorAll('.dropdown-option');
    
    // Set initial value if editing
    <?php if ($edit_record): ?>
    const editBookId = "<?= $edit_record['book_id'] ?>";
    const editOption = document.querySelector(`[data-value="${editBookId}"]`);
    if (editOption) {
        searchInput.value = editOption.dataset.text;
        hiddenInput.value = editBookId;
    }
    <?php endif; ?>
    
    // Show/hide dropdown
    searchInput.addEventListener('focus', function() {
        optionsContainer.style.display = 'block';
        filterOptions();
    });
    
    searchInput.addEventListener('input', function() {
        filterOptions();
        optionsContainer.style.display = 'block';
    });
    
    // Hide dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-dropdown')) {
            optionsContainer.style.display = 'none';
        }
    });
    
    // Filter options based on search
    function filterOptions() {
        const searchTerm = searchInput.value.toLowerCase();
        
        options.forEach(option => {
            const text = option.textContent.toLowerCase();
            const bookId = option.dataset.value.toLowerCase();
            
            if (text.includes(searchTerm) || bookId.includes(searchTerm)) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    // Handle option selection
    options.forEach(option => {
        option.addEventListener('click', function() {
            searchInput.value = this.dataset.text;
            hiddenInput.value = this.dataset.value;
            optionsContainer.style.display = 'none';
        });
    });
    
    // Form validation
    const form = document.getElementById('formaForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const bookId = hiddenInput.value;
            const orderNo = document.getElementById('order_no').value;
            
            if (!bookId || bookId === '') {
                alert('Please select a book from the dropdown');
                e.preventDefault();
                searchInput.focus();
                return false;
            }
            
            if (!orderNo || orderNo === '') {
                alert('Please enter an order number');
                e.preventDefault();
                document.getElementById('order_no').focus();
                return false;
            }
        });
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
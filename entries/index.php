<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Handle delete action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    $delete_id = $_POST['id'];
    
    try {
        // Check if DENO is used in any D2M
        $checkStmt = $conn->prepare("
            SELECT COUNT(*) as count, 
                   string_agg(DISTINCT d.d2m_no, ', ') as d2m_numbers
            FROM d2m_items di
            JOIN d2m d ON di.d2m_id = d.id
            WHERE di.associated_deno_ids LIKE '%' || :deno_id || '%'
              AND d.deleted_at IS NULL
        ");
        $checkStmt->execute([':deno_id' => $delete_id]);
        $result = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($result['count'] > 0) {
            $error_message = "Cannot delete this DENO record! It is being used in the following D2M(s): <strong>" . 
                           htmlspecialchars($result['d2m_numbers']) . "</strong>. " .
                           "Please delete or modify those D2M records first.";
        } else {
            // Safe to delete
            $deleteStmt = $conn->prepare("DELETE FROM deno WHERE id = :id");
            $deleteStmt->execute([':id' => $delete_id]);
            $success_message = "DENO record deleted successfully!";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Pagination settings
$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Default date range (2082.04.01 to 2083.03.32)
$current_nepali_year = '2082';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $current_nepali_year . '.04.01';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : ($current_nepali_year + 1) . '.03.32';

// Get search parameters
$search_params = [
    'book_code' => $_GET['book_code'] ?? '',
    'class_level' => $_GET['class_level'] ?? '',
    'translated' => $_GET['translated'] ?? '',
    'ref_no' => $_GET['ref_no'] ?? '',
    'start_date' => $start_date,
    'end_date' => $end_date
];

// Build the base query for counting total records
$count_query = "
    SELECT COUNT(*) as total
    FROM deno d 
    LEFT JOIN books b ON d.book_code = b.book_code 
    WHERE 1=1
";

// Build the main query - WITH D2M NUMBERS
$query = "
    SELECT d.*, 
           b.book_name, 
           b.class_level, 
           b.is_translated,
           (
               SELECT COUNT(*) 
               FROM d2m_items di 
               JOIN d2m dm ON di.d2m_id = dm.id
               WHERE di.associated_deno_ids LIKE '%' || d.id || '%'
                 AND dm.deleted_at IS NULL
           ) as d2m_count,
           (
               SELECT string_agg(DISTINCT dm.d2m_no, ', ' ORDER BY dm.d2m_no)
               FROM d2m_items di 
               JOIN d2m dm ON di.d2m_id = dm.id
               WHERE di.associated_deno_ids LIKE '%' || d.id || '%'
                 AND dm.deleted_at IS NULL
           ) as d2m_numbers
    FROM deno d 
    LEFT JOIN books b ON d.book_code = b.book_code 
    WHERE 1=1
";

// Add search conditions
$conditions = "";
$bind_params = [];

if (!empty($search_params['book_code'])) {
    $conditions .= " AND d.book_code = :book_code";
    $bind_params[':book_code'] = $search_params['book_code'];
}

if (!empty($search_params['class_level'])) {
    $conditions .= " AND b.class_level = :class_level";
    $bind_params[':class_level'] = $search_params['class_level'];
}

if ($search_params['translated'] !== '') {
    $conditions .= " AND b.is_translated = :translated";
    $bind_params[':translated'] = ($search_params['translated'] === 'yes') ? true : false;
}

if (!empty($search_params['ref_no'])) {
    $conditions .= " AND d.ref_no LIKE :ref_no";
    $bind_params[':ref_no'] = '%' . $search_params['ref_no'] . '%';
}

// Add date range condition
$conditions .= " AND d.deno_date_nep BETWEEN :start_date AND :end_date";
$bind_params[':start_date'] = $search_params['start_date'];
$bind_params[':end_date'] = $search_params['end_date'];

// Apply conditions to both queries
$count_query .= $conditions;
$query .= $conditions;

// Get total count for pagination
$count_stmt = $conn->prepare($count_query);
foreach ($bind_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Add ordering and pagination to main query
$query .= " ORDER BY d.deno_date_nep DESC, d.created_at DESC LIMIT :limit OFFSET :offset";

// Prepare and execute the main query
$stmt = $conn->prepare($query);
foreach ($bind_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$deno_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get distinct class levels and books for dropdowns
$class_levels = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);
$books = $conn->query("SELECT book_code, book_name, class_level FROM books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals for current page
$total_poka_qty = 0;
$total_open_pcs = 0;
$total_quantity = 0;

foreach ($deno_records as $record) {
    $total_poka_qty += $record['poka_qty'];
    $total_open_pcs += $record['quantity_openpcs'];
    $total_quantity += $record['total_qty'];
}

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="deno_records_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $export_query = str_replace(" LIMIT :limit OFFSET :offset", "", $query);
    $export_stmt = $conn->prepare($export_query);
    foreach ($bind_params as $key => $value) {
        $export_stmt->bindValue($key, $value);
    }
    $export_stmt->execute();
    $export_records = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Book Name</th><th>Book Code</th><th>Class Level</th><th>Translated</th><th>Ref No</th><th>Nepali Date</th><th>Per Poka</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th><th>D2M Numbers</th><th>Created By</th><th>Created At</th></tr>";
    
    foreach ($export_records as $record) {
        echo "<tr>";
        echo "<td>" . $record['id'] . "</td>";
        echo "<td>" . htmlspecialchars($record['book_name']) . "</td>";
        echo "<td>" . htmlspecialchars($record['book_code']) . "</td>";
        echo "<td>" . htmlspecialchars($record['class_level']) . "</td>";
        echo "<td>" . ($record['is_translated'] ? 'Yes' : 'No') . "</td>";
        echo "<td>" . htmlspecialchars($record['ref_no']) . "</td>";
        echo "<td>" . htmlspecialchars($record['deno_date_nep']) . "</td>";
        echo "<td>" . number_format($record['per_poka_qty']) . "</td>";
        echo "<td>" . number_format($record['poka_qty']) . "</td>";
        echo "<td>" . number_format($record['total_qty']) . "</td>";
        echo "<td>" . number_format($record['quantity_openpcs']) . "</td>";
        echo "<td>" . htmlspecialchars($record['d2m_numbers'] ?? 'N/A') . "</td>";
        echo "<td>" . htmlspecialchars($record['created_by']) . "</td>";
        echo "<td>" . date('Y-m-d H:i', strtotime($record['created_at'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit();
}
?>

<style>
body {
    font-size: 14px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 20px;
    background-color: #f8f9fa;
}

.container {
    max-width: 1600px;
    margin: 0 auto;
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
}

h2 {
    color: #333;
    margin-bottom: 30px;
    text-align: center;
    border-bottom: 2px solid #007bff;
    padding-bottom: 10px;
}

.alert {
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 5px;
    font-weight: bold;
}

.alert-danger {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.alert-success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.search-container {
    background: #f8f9fa;
    padding: 25px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #e9ecef;
}

.search-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.search-group {
    display: flex;
    flex-direction: column;
}

.search-group label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 5px;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.search-control {
    padding: 10px 12px;
    border: 2px solid #e9ecef;
    border-radius: 5px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.search-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,.1);
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    text-align: center;
    margin-right: 8px;
    transition: all 0.3s ease;
}

.btn-primary { background-color: #007bff; color: white; }
.btn-secondary { background-color: #6c757d; color: white; }
.btn-success { background-color: #28a745; color: white; }
.btn-warning { background-color: #ffc107; color: #212529; }
.btn-danger { background-color: #dc3545; color: white; }
.btn-info { background-color: #17a2b8; color: white; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.btn-sm {
    padding: 5px 10px;
    font-size: 12px;
    margin-right: 4px;
}

.btn:disabled {
    opacity: 0.5;
    cursor: not-allowed;
}

.action-buttons {
    margin-bottom: 20px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 10px;
}

.pagination-info {
    font-weight: bold;
    color: #495057;
}

.table-container {
    background: white;
    border-radius: 8px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    min-width: 1400px;
}

.table th,
.table td {
    padding: 10px 8px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
    vertical-align: middle;
}

.table th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: 700;
    font-size: 11px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
    position: sticky;
    top: 0;
    z-index: 10;
}

.table tbody tr:hover {
    background-color: #f8f9fa;
}

.table tbody tr:nth-child(even) {
    background-color: #fafafa;
}

.totals-row {
    font-weight: bold;
    background: linear-gradient(135deg, #ffecd2 0%, #fcb69f 100%) !important;
    border-top: 2px solid #007bff;
}

.d2m-badge {
    display: inline-block;
    background: #17a2b8;
    color: white;
    padding: 3px 8px;
    border-radius: 3px;
    font-size: 11px;
    margin: 2px;
    white-space: nowrap;
}

.d2m-column {
    max-width: 200px;
    font-size: 11px;
}

.no-d2m {
    color: #6c757d;
    font-style: italic;
    font-size: 11px;
}

.search-dropdown {
    position: relative;
}

.dropdown-options {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 2px solid #007bff;
    border-top: none;
    max-height: 250px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
}

.dropdown-option {
    padding: 12px 15px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    font-size: 13px;
    transition: background-color 0.2s ease;
}

.dropdown-option:hover {
    background-color: #f8f9fa;
}

.dropdown-option:last-child {
    border-bottom: none;
}

/* Pagination Styles */
.pagination-container {
    display: flex;
    justify-content: center;
    align-items: center;
    margin-top: 30px;
    gap: 10px;
}

.pagination {
    display: flex;
    list-style: none;
    margin: 0;
    padding: 0;
    gap: 5px;
}

.page-item {
    margin: 0;
}

.page-link {
    display: block;
    padding: 8px 12px;
    text-decoration: none;
    border: 2px solid #dee2e6;
    color: #007bff;
    background-color: white;
    border-radius: 5px;
    transition: all 0.3s ease;
}

.page-item.active .page-link {
    background-color: #007bff;
    border-color: #007bff;
    color: white;
}

.page-link:hover {
    background-color: #e9ecef;
    border-color: #adb5bd;
    color: #0056b3;
}

.page-item.disabled .page-link {
    color: #6c757d;
    pointer-events: none;
    background-color: #fff;
    border-color: #dee2e6;
}

/* Responsive Design */
@media (max-width: 768px) {
    .search-row {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
        align-items: stretch;
    }
}

/* Print Styles */
@media print {
    body { font-size: 10px; }
    .search-container, .action-buttons, .pagination-container { display: none !important; }
    .table { font-size: 8px; }
    .table th, .table td { padding: 2px 4px; border: 1px solid #000; }
    @page { margin: 0.5in; size: A4 landscape; }
}
</style>

<div class="container">
    <h2>📚 Deno Records Management System</h2>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            ⚠️ <?= $error_message ?>
        </div>
    <?php endif; ?>

    <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            ✓ <?= $success_message ?>
        </div>
    <?php endif; ?>

    <div class="action-buttons">
        <?php if (has_role('editor') || has_role('admin')): ?>
            <a href="deno.php" class="btn btn-primary btn-lg shadow-sm">
                <i class="fas fa-plus-circle me-2"></i>Create New Deno
            </a>
        <?php endif; ?>
    </div>

    <div class="search-container">
        <form method="get" id="searchForm">
            <input type="hidden" name="page" value="1">
            
            <div class="search-row">
                <div class="search-group">
                    <label for="book_search">🔍 Search Book:</label>
                    <div class="search-dropdown">
                        <input type="text" 
                               class="search-control dropdown-search" 
                               id="book_search" 
                               placeholder="Type to search books..."
                               autocomplete="off"
                               value="">
                        <input type="hidden" name="book_code" id="book_code" value="<?= htmlspecialchars($search_params['book_code']) ?>">
                        <div class="dropdown-options" id="book_options">
                            <?php foreach ($books as $book): ?>
                                <div class="dropdown-option" 
                                     data-value="<?= $book['book_code'] ?>"
                                     data-text="<?= $book['book_name'] ?> (<?= $book['book_code'] ?>)"
                                     data-class="<?= $book['class_level'] ?>">
                                    <strong><?= htmlspecialchars($book['book_name']) ?></strong><br>
                                    <small>Code: <?= $book['book_code'] ?> | Class: <?= $book['class_level'] ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </div>
                
                <div class="search-group">
                    <label for="class_level">🎓 Class Level:</label>
                    <select name="class_level" id="class_level" class="search-control">
                        <option value="">All Classes</option>
                        <?php foreach ($class_levels as $class_level): ?>
                            <option value="<?= htmlspecialchars($class_level) ?>" 
                                    <?= $search_params['class_level'] == $class_level ? 'selected' : '' ?>>
                                Class <?= htmlspecialchars($class_level) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="translated">🌐 Translation Status:</label>
                    <select name="translated" id="translated" class="search-control">
                        <option value="">All Books</option>
                        <option value="yes" <?= $search_params['translated'] === 'yes' ? 'selected' : '' ?>>Translated</option>
                        <option value="no" <?= $search_params['translated'] === 'no' ? 'selected' : '' ?>>Not Translated</option>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="ref_no">📄 Reference No:</label>
                    <input type="text" name="ref_no" id="ref_no" class="search-control" 
                           placeholder="Enter reference number..."
                           value="<?= htmlspecialchars($search_params['ref_no']) ?>">
                </div>
            </div>
            
            <div class="search-row">
                <div class="search-group">
                    <label for="start_date">📅 Start Date (YYYY.MM.DD):</label>
                    <input type="text" name="start_date" id="start_date" class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2082.04.01"
                           value="<?= htmlspecialchars($search_params['start_date']) ?>">
                </div>
                
                <div class="search-group">
                    <label for="end_date">📅 End Date (YYYY.MM.DD):</label>
                    <input type="text" name="end_date" id="end_date" class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2083.03.32"
                           value="<?= htmlspecialchars($search_params['end_date']) ?>">
                </div>
                
                <div class="search-group" style="align-self: end;">
                    <button type="submit" class="btn btn-primary">🔍 Search</button>
                    <a href="?" class="btn btn-secondary">🔄 Reset</a>
                </div>
            </div>
        </form>
    </div>

    <div class="action-buttons">
        <div>
            <button onclick="window.print()" class="btn btn-info">🖨️ Print</button>
            <a href="?export=excel&<?= http_build_query(array_merge($_GET, ['page' => null])) ?>" class="btn btn-success">📊 Export Excel</a>
            <button onclick="downloadCSV()" class="btn btn-secondary">📥 Download CSV</button>
        </div>
        <div class="pagination-info">
            Showing <?= count($deno_records) ?> of <?= number_format($total_records) ?> records
            (Page <?= $page ?> of <?= $total_pages ?>)
        </div>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Book Name</th>
                    <th>Code</th>
                    <th>Class</th>
                    <th>Translated</th>
                    <th>Ref No</th>
                    <th>Nepali Date</th>
                    <th>Per Poka</th>
                    <th>Poka Qty</th>
                    <th>Total Qty</th>
                    <th>Open Pcs</th>
                    <th>D2M Numbers</th>
                    <th>Created By</th>
                    <th>Created At</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($deno_records) > 0): ?>
                    <?php foreach ($deno_records as $record): ?>
                    <tr>
                        <td><?= $record['id'] ?></td>
                        <td><strong><?= htmlspecialchars($record['book_name']) ?></strong></td>
                        <td><?= htmlspecialchars($record['book_code']) ?></td>
                        <td><?= $record['class_level'] ?></td>
                        <td>
                            <?php if ($record['is_translated']): ?>
                                <span style="color: #28a745; font-weight: bold;">✓ Yes</span>
                            <?php else: ?>
                                <span style="color: #dc3545; font-weight: bold;">✗ No</span>
                            <?php endif; ?>
                        </td>
                        <td><?= htmlspecialchars($record['ref_no']) ?></td>
                        <td><?= htmlspecialchars($record['deno_date_nep']) ?></td>
                        <td><?= number_format($record['per_poka_qty']) ?></td>
                        <td><?= number_format($record['poka_qty']) ?></td>
                        <td><strong><?= number_format($record['total_qty']) ?></strong></td>
                        <td><?= number_format($record['quantity_openpcs']) ?></td>
                        <td class="d2m-column">
                            <?php if ($record['d2m_numbers']): ?>
                                <?php 
                                $d2m_list = explode(', ', $record['d2m_numbers']);
                                foreach ($d2m_list as $d2m_no): 
                                ?>
                                    <span class="d2m-badge" title="Used in D2M: <?= htmlspecialchars($d2m_no) ?>">
                                        <?= htmlspecialchars($d2m_no) ?>
                                    </span>
                                <?php endforeach; ?>
                            <?php else: ?>
                                <span class="no-d2m">Not in D2M</span>
                            <?php endif; ?>
                        </td>
                        <td><?= ucfirst(htmlspecialchars($record['created_by'])) ?></td>
                        <td><?= date('Y-m-d H:i', strtotime($record['created_at'])) ?></td>
                        <td>
                            <?php if (has_role('editor') || has_role('admin')): ?>
                                <a href="details.php?id=<?= $record['id'] ?>" class="btn btn-primary btn-sm">Details</a>
                                <a href="edit.php?id=<?= $record['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                
                                <?php if ($record['d2m_count'] > 0): ?>
                                    <button type="button" 
                                            class="btn btn-danger btn-sm" 
                                            disabled 
                                            title="Cannot delete: Used in <?= $record['d2m_count'] ?> D2M(s)">
                                        🔒 Locked
                                    </button>
                                <?php else: ?>
                                    <form method="post" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to delete this DENO record?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                <?php endif; ?>
                            <?php endif; ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="totals-row">
                        <td colspan="8"><strong>📊 Page Totals:</strong></td>
                        <td><strong><?= number_format($total_poka_qty) ?></strong></td>
                        <td><strong><?= number_format($total_quantity) ?></strong></td>
                        <td><strong><?= number_format($total_open_pcs) ?></strong></td>
                        <td colspan="4"></td>
                    </tr>
                <?php else: ?>
                    <tr>
                        <td colspan="15" style="text-align: center; padding: 40px; color: #6c757d;">
                            <strong>📭 No records found matching your search criteria.</strong><br>
                            <small>Try adjusting your search parameters.</small>
                        </td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <nav aria-label="Page navigation">
            <ul class="pagination">
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">First</a>
                </li>
                <li class="page-item <?= $page <= 1 ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">‹ Previous</a>
                </li>
                
                <?php
                $start_page = max(1, $page - 2);
                $end_page = min($total_pages, $page + 2);
                
                for ($i = $start_page; $i <= $end_page; $i++):
                ?>
                <li class="page-item <?= $i === $page ? 'active' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
                <?php endfor; ?>
                
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next ›</a>
                </li>
                <li class="page-item <?= $page >= $total_pages ? 'disabled' : '' ?>">
                    <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">Last</a>
                </li>
            </ul>
        </nav>
    </div>
    <?php endif; ?>
</div>

<script>
// CSV Download Function
function downloadCSV() {
    const table = document.querySelector('.table');
    const rows = Array.from(table.querySelectorAll('tr'));
    
    let csvContent = "data:text/csv;charset=utf-8,";
    
    rows.forEach((row, index) => {
        if (row.classList.contains('totals-row')) return;
        
        const cols = Array.from(row.querySelectorAll('th, td'));
        const csvRow = cols.map(col => {
            let cellData = col.textContent.trim();
            if (cellData.includes('Edit') && cellData.includes('Delete')) {
                cellData = '';
            }
            if (cellData.includes('✓') || cellData.includes('✗')) {
                cellData = cellData.includes('✓') ? 'Yes' : 'No';
            }
            if (cellData.includes(',') || cellData.includes('"')) {
                cellData = '"' + cellData.replace(/"/g, '""') + '"';
            }
            return cellData;
        }).join(',');
        csvContent += csvRow + "\r\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "deno_records_page_" + <?= $page ?> + "_" + new Date().toISOString().split('T')[0] + ".csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

// Book search dropdown functionality
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('book_search');
    const hiddenInput = document.getElementById('book_code');
    const optionsContainer = document.getElementById('book_options');
    const options = optionsContainer.querySelectorAll('.dropdown-option');
    
    const currentBookCode = "<?= htmlspecialchars($search_params['book_code']) ?>";
    if (currentBookCode) {
        const currentOption = document.querySelector(`[data-value="${currentBookCode}"]`);
        if (currentOption) {
            searchInput.value = currentOption.dataset.text;
            hiddenInput.value = currentBookCode;
        }
    }
    
    searchInput.addEventListener('focus', function() {
        optionsContainer.style.display = 'block';
        filterOptions();
    });
    
    searchInput.addEventListener('input', function() {
        filterOptions();
        optionsContainer.style.display = 'block';
        if (this.value === '') {
            hiddenInput.value = '';
        }
    });
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-dropdown')) {
            optionsContainer.style.display = 'none';
        }
    });
    
    function filterOptions() {
        const searchTerm = searchInput.value.toLowerCase();
        
        options.forEach(option => {
            const text = option.textContent.toLowerCase();
            const bookCode = option.dataset.value.toLowerCase();
            
            if (text.includes(searchTerm) || bookCode.includes(searchTerm)) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    options.forEach(option => {
        option.addEventListener('click', function() {
            searchInput.value = this.dataset.text;
            hiddenInput.value = this.dataset.value;
            optionsContainer.style.display = 'none';
            
            const classLevel = this.dataset.class;
            if (classLevel && classLevel !== 'null') {
                const classSelect = document.getElementById('class_level');
                classSelect.value = classLevel;
            }
        });
    });
    
    let selectedIndex = -1;
    const visibleOptions = () => Array.from(options).filter(opt => opt.style.display !== 'none');
    
    searchInput.addEventListener('keydown', function(e) {
        const visible = visibleOptions();
        
        switch(e.key) {
            case 'ArrowDown':
                e.preventDefault();
                selectedIndex = Math.min(selectedIndex + 1, visible.length - 1);
                updateSelection(visible);
                break;
            case 'ArrowUp':
                e.preventDefault();
                selectedIndex = Math.max(selectedIndex - 1, -1);
                updateSelection(visible);
                break;
            case 'Enter':
                e.preventDefault();
                if (selectedIndex >= 0 && visible[selectedIndex]) {
                    visible[selectedIndex].click();
                }
                break;
            case 'Escape':
                optionsContainer.style.display = 'none';
                selectedIndex = -1;
                break;
        }
    });
    
    function updateSelection(visible) {
        options.forEach(opt => opt.style.backgroundColor = '');
        
        if (selectedIndex >= 0 && visible[selectedIndex]) {
            visible[selectedIndex].style.backgroundColor = '#e9ecef';
            visible[selectedIndex].scrollIntoView({ block: 'nearest' });
        }
    }
});

// Date validation and formatting
document.addEventListener('DOMContentLoaded', function() {
    const startDateInput = document.getElementById('start_date');
    const endDateInput = document.getElementById('end_date');
    
    function validateNepaliDate(input) {
        input.addEventListener('blur', function() {
            const value = this.value.trim();
            if (value && !value.match(/^\d{4}\.\d{2}\.\d{2}$/)) {
                alert('Please enter date in YYYY.MM.DD format (e.g., 2082.04.01)');
                this.focus();
            }
        });
        
        input.addEventListener('input', function() {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length >= 4) {
                value = value.substring(0, 4) + '.' + value.substring(4);
            }
            if (value.length >= 7) {
                value = value.substring(0, 7) + '.' + value.substring(7);
            }
            if (value.length > 10) {
                value = value.substring(0, 10);
            }
            this.value = value;
        });
    }
    
    validateNepaliDate(startDateInput);
    validateNepaliDate(endDateInput);
    
    const quickRangesContainer = document.createElement('div');
    quickRangesContainer.innerHTML = `
        <div style="margin-top: 10px; display: flex; gap: 8px; flex-wrap: wrap;">
            <small style="color: #6c757d; align-self: center;">Quick ranges:</small>
            <button type="button" class="btn btn-sm" style="background: #e9ecef; color: #495057;" onclick="setDateRange('2082.04.01', '2083.03.32')">Current Year</button>
            <button type="button" class="btn btn-sm" style="background: #e9ecef; color: #495057;" onclick="setDateRange('2081.04.01', '2082.03.32')">Previous Year</button>
            <button type="button" class="btn btn-sm" style="background: #e9ecef; color: #495057;" onclick="setDateRange('2083.04.01', '2084.03.32')">Next Year</button>
            <button type="button" class="btn btn-sm" style="background: #e9ecef; color: #495057;" onclick="setCurrentMonth()">Current Month</button>
        </div>
    `;
    endDateInput.parentNode.parentNode.appendChild(quickRangesContainer);
});

function setDateRange(start, end) {
    document.getElementById('start_date').value = start;
    document.getElementById('end_date').value = end;
}

function setCurrentMonth() {
    const today = new Date();
    const currentYear = 2082;
    const currentMonth = String(today.getMonth() + 1).padStart(2, '0');
    
    setDateRange(`${currentYear}.${currentMonth}.01`, `${currentYear}.${currentMonth}.32`);
}

document.getElementById('searchForm').addEventListener('submit', function(e) {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    if (startDate && endDate && startDate > endDate) {
        e.preventDefault();
        alert('Start date cannot be later than end date.');
        return false;
    }
    
    const submitBtn = this.querySelector('button[type="submit"]');
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '🔍 Searching...';
    submitBtn.disabled = true;
    
    setTimeout(() => {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }, 5000);
});

document.getElementById('class_level').addEventListener('change', function() {
    if (this.value !== '') {
        document.getElementById('searchForm').submit();
    }
});

document.getElementById('translated').addEventListener('change', function() {
    document.getElementById('searchForm').submit();
});

document.addEventListener('DOMContentLoaded', function() {
    const refNoSearch = "<?= htmlspecialchars($search_params['ref_no']) ?>";
    if (refNoSearch) {
        const cells = document.querySelectorAll('tbody td:nth-child(6)');
        cells.forEach(cell => {
            const text = cell.textContent;
            const highlightedText = text.replace(new RegExp(`(${refNoSearch})`, 'gi'), '<mark style="background-color: #fff3cd;">$1</mark>');
            if (highlightedText !== text) {
                cell.innerHTML = highlightedText;
            }
        });
    }
});

function confirmBulkAction(action) {
    return confirm(`Are you sure you want to ${action} the selected records?`);
}

function toggleTableView() {
    const table = document.querySelector('.table');
    if (window.innerWidth < 768) {
        table.style.fontSize = '11px';
    } else {
        table.style.fontSize = '12px';
    }
}

window.addEventListener('resize', toggleTableView);
toggleTableView();

document.addEventListener('DOMContentLoaded', function() {
    const cells = document.querySelectorAll('.table td');
    cells.forEach(cell => {
        if (cell.scrollWidth > cell.clientWidth) {
            cell.title = cell.textContent.trim();
        }
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
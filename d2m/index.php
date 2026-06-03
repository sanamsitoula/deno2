<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Handle status update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $d2m_id = $_POST['d2m_id'];
    $action = $_POST['action'];
    $user_id = $_SESSION['user_id'];
    
    try {
        if ($action === 'check') {
            // Update to CHECKED status
            $stmt = $conn->prepare("
                UPDATE d2m 
                SET status = 'CHECKED', 
                    checked_by = :user_id,
                    checked_at = NOW()
                WHERE id = :id AND status = 'DRAFT'
            ");
            $stmt->execute([':user_id' => $user_id, ':id' => $d2m_id]);
            $success_message = "D2M marked as CHECKED successfully!";
            
        } elseif ($action === 'verify') {
            $verified_by = $_POST['verified_by'];
            // Update to VERIFIED status
            $stmt = $conn->prepare("
                UPDATE d2m 
                SET status = 'VERIFIED', 
                    verified_by = :verified_by,
                    verified_at = NOW()
                WHERE id = :id AND status = 'CHECKED'
            ");
            $stmt->execute([':verified_by' => $verified_by, ':id' => $d2m_id]);
            $success_message = "D2M marked as VERIFIED successfully!";
            
        } elseif ($action === 'cancel') {
            // Update to CANCELLED status
            $stmt = $conn->prepare("
                UPDATE d2m 
                SET status = 'CANCELLED'
                WHERE id = :id
            ");
            $stmt->execute([':id' => $d2m_id]);
            $success_message = "D2M marked as CANCELLED!";
            
        } elseif ($action === 'close') {
            // Update to CLOSE status
            $stmt = $conn->prepare("
                UPDATE d2m 
                SET status = 'CLOSE'
                WHERE id = :id AND status = 'VERIFIED'
            ");
            $stmt->execute([':id' => $d2m_id]);
            $success_message = "D2M marked as CLOSED!";
        }
    } catch (Exception $e) {
        $error_message = "Error: " . $e->getMessage();
    }
}

// Get users for dropdowns
$marketing_users = $conn->query("
    SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

$checker_users = $conn->query("
    SELECT id, username FROM users 
    WHERE role IN ('incharge', 'operator', 'supervisor') 
    ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

// Pagination settings
$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$current_nepali_year = '2082';
$start_date = isset($_GET['start_date']) ? $_GET['start_date'] : $current_nepali_year . '.04.01';
$end_date = isset($_GET['end_date']) ? $_GET['end_date'] : ($current_nepali_year + 1) . '.03.32';

$search_params = [
    'd2m_no' => $_GET['d2m_no'] ?? '',
    'd2m_type' => $_GET['d2m_type'] ?? '',
    'status' => $_GET['status'] ?? '',
    'book_code' => $_GET['book_code'] ?? '',
    'ref_no' => $_GET['ref_no'] ?? '',
    'start_date' => $start_date,
    'end_date' => $end_date
];

$count_query = "
    SELECT COUNT(DISTINCT d.id) as total
    FROM d2m d 
    LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
    LEFT JOIN d2m_items di ON d.id = di.d2m_id
    WHERE d.deleted_at IS NULL
";

$query = "
    SELECT d.*, 
           fy.fiscal_code as fiscal_year_name,
           u_created.username as created_by_name,
           u_checked.username as checked_by_name,
           u_verified.username as verified_by_name,
           (SELECT COUNT(*) FROM d2m_items WHERE d2m_id = d.id) as items_count,
           (SELECT SUM(total_qty) FROM d2m_items WHERE d2m_id = d.id) as total_books,
           (
               SELECT string_agg(DISTINCT deno.ref_no, ', ' ORDER BY deno.ref_no)
               FROM d2m_items di2
               CROSS JOIN LATERAL unnest(string_to_array(di2.associated_deno_ids, ',')) AS deno_id
               JOIN deno ON deno.id = deno_id::integer
               WHERE di2.d2m_id = d.id
           ) as ref_numbers
    FROM d2m d 
    LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
    LEFT JOIN users u_created ON d.created_by = u_created.id
    LEFT JOIN users u_checked ON d.checked_by = u_checked.id
    LEFT JOIN users u_verified ON d.verified_by = u_verified.id
    LEFT JOIN d2m_items di ON d.id = di.d2m_id
    WHERE d.deleted_at IS NULL
";

$conditions = "";
$bind_params = [];

if (!empty($search_params['d2m_no'])) {
    $conditions .= " AND d.d2m_no LIKE :d2m_no";
    $bind_params[':d2m_no'] = '%' . $search_params['d2m_no'] . '%';
}

if (!empty($search_params['d2m_type'])) {
    $conditions .= " AND d.d2m_type = :d2m_type";
    $bind_params[':d2m_type'] = $search_params['d2m_type'];
}

if (!empty($search_params['status'])) {
    $conditions .= " AND d.status = :status";
    $bind_params[':status'] = $search_params['status'];
}

if (!empty($search_params['book_code'])) {
    $conditions .= " AND di.book_code LIKE :book_code";
    $bind_params[':book_code'] = '%' . $search_params['book_code'] . '%';
}

if (!empty($search_params['ref_no'])) {
    $conditions .= " AND EXISTS (
        SELECT 1 FROM d2m_items di3
        CROSS JOIN LATERAL unnest(string_to_array(di3.associated_deno_ids, ',')) AS deno_id
        JOIN deno ON deno.id = deno_id::integer
        WHERE di3.d2m_id = d.id AND deno.ref_no LIKE :ref_no
    )";
    $bind_params[':ref_no'] = '%' . $search_params['ref_no'] . '%';
}

$conditions .= " AND d.nep_date BETWEEN :start_date AND :end_date";
$bind_params[':start_date'] = $search_params['start_date'];
$bind_params[':end_date'] = $search_params['end_date'];

$count_query .= $conditions;
$query .= $conditions;
$query .= " GROUP BY d.id, fy.fiscal_code, u_created.username, u_checked.username, u_verified.username";

$count_stmt = $conn->prepare($count_query);
foreach ($bind_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

$query .= " ORDER BY d.nep_date DESC, d.created_at DESC LIMIT :limit OFFSET :offset";

$stmt = $conn->prepare($query);
foreach ($bind_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$d2m_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    max-width: 1800px;
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

.alert-success {
    background-color: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.alert-danger {
    background-color: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
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
    font-size: 11px;
    margin: 2px;
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
    font-size: 11px;
    min-width: 1600px;
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
    font-size: 10px;
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

.status-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: bold;
    text-transform: uppercase;
    display: inline-block;
}

.status-draft { background-color: #f8d7da; color: #721c24; }
.status-checked { background-color: #fff3cd; color: #856404; }
.status-verified { background-color: #d4edda; color: #155724; }
.status-cancelled { background-color: #d6d8db; color: #383d41; }
.status-close { background-color: #d1ecf1; color: #0c5460; }

.type-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 10px;
    font-weight: bold;
}

.type-t { background-color: #e8f5e8; color: #2d5a2d; }
.type-nt { background-color: #f0f8ff; color: #1e3a8a; }

.ref-numbers {
    font-size: 10px;
    color: #666;
    max-width: 150px;
    overflow: hidden;
    text-overflow: ellipsis;
    white-space: nowrap;
}

.status-actions {
    display: flex;
    flex-direction: column;
    gap: 5px;
}

.verify-form {
    display: flex;
    gap: 5px;
    align-items: center;
}

.verify-form select {
    font-size: 10px;
    padding: 4px;
    border: 1px solid #ccc;
    border-radius: 3px;
}

.user-info {
    font-size: 10px;
    color: #666;
    margin-top: 2px;
}

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background-color: rgba(0,0,0,0.5);
}

.modal-content {
    background-color: white;
    margin: 10% auto;
    padding: 30px;
    border-radius: 8px;
    width: 400px;
    box-shadow: 0 4px 20px rgba(0,0,0,0.3);
}

.modal-header {
    font-size: 18px;
    font-weight: bold;
    margin-bottom: 20px;
    color: #333;
}

.modal-body {
    margin-bottom: 20px;
}

.modal-footer {
    display: flex;
    justify-content: flex-end;
    gap: 10px;
}

.close {
    float: right;
    font-size: 28px;
    font-weight: bold;
    cursor: pointer;
    color: #aaa;
}

.close:hover {
    color: #000;
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

@media (max-width: 768px) {
    .search-row {
        grid-template-columns: 1fr;
    }
    
    .action-buttons {
        flex-direction: column;
        align-items: stretch;
    }
}

@media print {
    body { font-size: 10px; }
    .search-container, .action-buttons, .pagination-container { display: none !important; }
    .table { font-size: 8px; }
    .table th, .table td { padding: 2px 4px; border: 1px solid #000; }
    @page { margin: 0.5in; size: A4 landscape; }
}
</style>

<div class="container">
    <h2>📋 D2M (Deno to Marketing) Records Management</h2>
    
    <?php if (isset($success_message)): ?>
        <div class="alert alert-success">
            ✓ <?= $success_message ?>
        </div>
    <?php endif; ?>
    
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            ⚠️ <?= $error_message ?>
        </div>
    <?php endif; ?>
    
    <div class="action-buttons">
        <?php if (has_role('editor') || has_role('admin')): ?>
            <a href="create.php" class="btn btn-primary btn-lg shadow-sm">
                <i class="fas fa-plus-circle me-2"></i>Create New D2M
            </a>
        <?php endif; ?>
    </div>

    <div class="search-container">
        <form method="get" id="searchForm">
            <input type="hidden" name="page" value="1">
            
            <div class="search-row">
                <div class="search-group">
                    <label for="d2m_no">🔍 D2M Number:</label>
                    <input type="text" 
                           name="d2m_no" 
                           id="d2m_no" 
                           class="search-control" 
                           placeholder="Search D2M number..."
                           value="<?= htmlspecialchars($search_params['d2m_no']) ?>">
                </div>
                
                <div class="search-group">
                    <label for="book_code">📚 Book Code:</label>
                    <input type="text" 
                           name="book_code" 
                           id="book_code" 
                           class="search-control" 
                           placeholder="Search book code..."
                           value="<?= htmlspecialchars($search_params['book_code']) ?>">
                </div>
                
                <div class="search-group">
                    <label for="ref_no">📄 DENO Ref No:</label>
                    <input type="text" 
                           name="ref_no" 
                           id="ref_no" 
                           class="search-control" 
                           placeholder="Search reference number..."
                           value="<?= htmlspecialchars($search_params['ref_no']) ?>">
                </div>
                
                <div class="search-group">
                    <label for="d2m_type">📑 Type:</label>
                    <select name="d2m_type" id="d2m_type" class="search-control">
                        <option value="">All Types</option>
                        <option value="T" <?= $search_params['d2m_type'] == 'T' ? 'selected' : '' ?>>Translated (T)</option>
                        <option value="NT" <?= $search_params['d2m_type'] == 'NT' ? 'selected' : '' ?>>Non-Translated (NT)</option>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="status">📊 Status:</label>
                    <select name="status" id="status" class="search-control">
                        <option value="">All Status</option>
                        <option value="DRAFT" <?= $search_params['status'] === 'DRAFT' ? 'selected' : '' ?>>Draft</option>
                        <option value="CHECKED" <?= $search_params['status'] === 'CHECKED' ? 'selected' : '' ?>>Checked</option>
                        <option value="VERIFIED" <?= $search_params['status'] === 'VERIFIED' ? 'selected' : '' ?>>Verified</option>
                        <option value="CANCELLED" <?= $search_params['status'] === 'CANCELLED' ? 'selected' : '' ?>>Cancelled</option>
                        <option value="CLOSE" <?= $search_params['status'] === 'CLOSE' ? 'selected' : '' ?>>Closed</option>
                    </select>
                </div>
            </div>
            
            <div class="search-row">
                <div class="search-group">
                    <label for="start_date">📅 Start Date (YYYY.MM.DD):</label>
                    <input type="text" 
                           name="start_date" 
                           id="start_date" 
                           class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2082.04.01"
                           value="<?= htmlspecialchars($search_params['start_date']) ?>">
                </div>
                
                <div class="search-group">
                    <label for="end_date">📅 End Date (YYYY.MM.DD):</label>
                    <input type="text" 
                           name="end_date" 
                           id="end_date" 
                           class="search-control" 
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
        <div class="pagination-info">
            Showing <?= count($d2m_records) ?> of <?= number_format($total_records) ?> records
            (Page <?= $page ?> of <?= $total_pages ?>)
        </div>
    </div>

    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th style="width: 3%;">ID</th>
                    <th style="width: 10%;">D2M Number</th>
                    <th style="width: 4%;">Serial</th>
                    <th style="width: 6%;">Type</th>
                    <th style="width: 6%;">Fiscal Year</th>
                    <th style="width: 7%;">Nepali Date</th>
                    <th style="width: 7%;">English Date</th>
                    <th style="width: 7%;">Status</th>
                    <th style="width: 4%;">Items</th>
                    <th style="width: 6%;">Total Books</th>
                    <th style="width: 10%;">DENO Refs</th>
                    <th style="width: 8%;">Created By</th>
                    <th style="width: 8%;">Checked By</th>
                    <th style="width: 8%;">Verified By</th>
                    <th style="width: 12%;">Status Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($d2m_records) > 0): ?>
                    <?php foreach ($d2m_records as $record): ?>
                    <tr>
                        <td><?= $record['id'] ?></td>
                        <td><strong><?= htmlspecialchars($record['d2m_no']) ?></strong></td>
                        <td><?= $record['serial_no'] ?></td>
                        <td>
                            <span class="type-badge type-<?= strtolower($record['d2m_type']) ?>">
                                <?= $record['d2m_type'] == 'T' ? '✓ T' : '✗ NT' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($record['fiscal_year_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($record['nep_date']) ?></td>
                        <td><?= date('Y-m-d', strtotime($record['eng_date'])) ?></td>
                        <td>
                            <span class="status-badge status-<?= strtolower($record['status']) ?>">
                                <?= $record['status'] ?>
                            </span>
                        </td>
                        <td><?= number_format($record['items_count']) ?></td>
                        <td><strong><?= number_format($record['total_books']) ?></strong></td>
                        <td>
                            <span class="ref-numbers" title="<?= htmlspecialchars($record['ref_numbers'] ?? 'N/A') ?>">
                                <?= htmlspecialchars($record['ref_numbers'] ?? 'N/A') ?>
                            </span>
                        </td>
                        <td>
                            <?= htmlspecialchars($record['created_by_name']) ?>
                        </td>
                        <td>
                            <?php if ($record['checked_by_name']): ?>
                                <div class="user-info">
                                    ✓ <?= htmlspecialchars($record['checked_by_name']) ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <?php if ($record['verified_by_name']): ?>
                                <div class="user-info">
                                    ✓ <?= htmlspecialchars($record['verified_by_name']) ?>
                                </div>
                            <?php else: ?>
                                <span style="color: #999;">-</span>
                            <?php endif; ?>
                        </td>
<td>
    <div class="status-actions">
        <!-- CHECK action -->
        <?php if (has_role('incharge') || has_role('operator') || has_role('supervisor') || has_role('admin')): ?>
            <?php if ($record['status'] == 'DRAFT'): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="check">
                    <input type="hidden" name="d2m_id" value="<?= $record['id'] ?>">
                    <button type="submit" class="btn btn-warning btn-sm" 
                            onclick="return confirm('Mark this D2M as CHECKED?')">
                        ✓ Check
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- VERIFY action -->
        <?php if ((has_role('marketing') || has_role('admin')) && $record['status'] == 'CHECKED'): ?>
            <button type="button" class="btn btn-success btn-sm" 
                    onclick="openVerifyModal(<?= $record['id'] ?>)">
                ✓ Verify
            </button>
        <?php endif; ?>
        
        <!-- CLOSE and CANCEL actions (admin only) -->
        <?php if (has_role('admin')): ?>
            <?php if ($record['status'] == 'VERIFIED'): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="close">
                    <input type="hidden" name="d2m_id" value="<?= $record['id'] ?>">
                    <button type="submit" class="btn btn-info btn-sm"
                            onclick="return confirm('Mark this D2M as CLOSED?')">
                        🔒 Close
                    </button>
                </form>
            <?php endif; ?>
            
            <?php if (in_array($record['status'], ['DRAFT', 'CHECKED'])): ?>
                <form method="post" style="display: inline;">
                    <input type="hidden" name="action" value="cancel">
                    <input type="hidden" name="d2m_id" value="<?= $record['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm"
                            onclick="return confirm('Cancel this D2M?')">
                        ✗ Cancel
                    </button>
                </form>
            <?php endif; ?>
        <?php endif; ?>
        
        <!-- View and Print (always visible) -->
        <a href="view.php?id=<?= $record['id'] ?>" class="btn btn-info btn-sm">View</a>
       <a href="print.php?id=<?= $record['id'] ?>"
   target="_blank"
   class="btn btn-primary btn-sm"
   title="Open print preview and print">
    🖨️ Print
</a>
 
    </div>
</td>
                </tr>
                <?php endforeach; ?>
            <?php else: ?>
                <tr>
                    <td colspan="15" style="text-align: center; padding: 40px; color: #6c757d;">
                        <strong>📭 No D2M records found matching your search criteria.</strong><br>
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

<!-- Verify Modal -->
<div id="verifyModal" class="modal">
    <div class="modal-content">
        <span class="close" onclick="closeVerifyModal()">&times;</span>
        <div class="modal-header">Verify D2M Record</div>
        <form method="post" id="verifyForm">
            <input type="hidden" name="action" value="verify">
            <input type="hidden" name="d2m_id" id="verify_d2m_id">
            <div class="modal-body">
                <label for="verified_by" style="font-weight: bold; margin-bottom: 10px; display: block;">
                    Select Marketing User:
                </label>
                <select name="verified_by" id="verified_by" class="search-control" required style="width: 100%;">
                    <option value="">-- Select User --</option>
                    <?php foreach ($marketing_users as $user): ?>
                        <option value="<?= $user['id'] ?>">
                            <?= htmlspecialchars($user['username']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" onclick="closeVerifyModal()">Cancel</button>
                <button type="submit" class="btn btn-success">✓ Verify D2M</button>
            </div>
        </form>
    </div>
</div>

<script>
function openVerifyModal(d2mId) {
    document.getElementById('verify_d2m_id').value = d2mId;
    document.getElementById('verifyModal').style.display = 'block';
}

function closeVerifyModal() {
    document.getElementById('verifyModal').style.display = 'none';
    document.getElementById('verifyForm').reset();
}

// Close modal when clicking outside
window.onclick = function(event) {
    const modal = document.getElementById('verifyModal');
    if (event.target == modal) {
        closeVerifyModal();
    }
}

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
});

// Form validation
document.getElementById('searchForm').addEventListener('submit', function(e) {
    const startDate = document.getElementById('start_date').value;
    const endDate = document.getElementById('end_date').value;
    
    if (startDate && endDate && startDate > endDate) {
        e.preventDefault();
        alert('Start date cannot be later than end date.');
        return false;
    }
});

// Auto-submit on status/type change
document.getElementById('status').addEventListener('change', function() {
    document.getElementById('searchForm').submit();
});

document.getElementById('d2m_type').addEventListener('change', function() {
    document.getElementById('searchForm').submit();
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
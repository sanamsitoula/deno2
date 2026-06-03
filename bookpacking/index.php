<?php
 ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('supervisor') && !has_role('incharge') && !has_role('admin') && !has_role('operator')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Get search parameters
$search = $_GET['search'] ?? '';
$book_code = $_GET['book_code'] ?? '';
$fiscal_year_id = $_GET['fiscal_year_id'] ?? '';
$packing_status = $_GET['packing_status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$supervisor_id = $_GET['supervisor_id'] ?? '';

// Pagination parameters
$page = max(1, $_GET['page'] ?? 1);
$per_page = 20;
$offset = ($page - 1) * $per_page;

// Build search query
$where_conditions = ["bp.status = true"]; // Only show active records
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(bp.name ILIKE :search OR bp.book_code ILIKE :search OR b.book_name ILIKE :search OR jt.job_ticket_code ILIKE :search)";
    $params[':search'] = '%' . $search . '%';
}

if (!empty($book_code)) {
    $where_conditions[] = "bp.book_code = :book_code";
    $params[':book_code'] = $book_code;
}

if (!empty($fiscal_year_id)) {
    $where_conditions[] = "bp.fiscal_year_id = :fiscal_year_id";
    $params[':fiscal_year_id'] = $fiscal_year_id;
}

if (!empty($packing_status)) {
    $where_conditions[] = "bp.packing_status = :packing_status";
    $params[':packing_status'] = $packing_status;
}

if (!empty($date_from)) {
    $where_conditions[] = "bp.date_nep >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "bp.date_nep <= :date_to";
    $params[':date_to'] = $date_to;
}

if (!empty($supervisor_id)) {
    $where_conditions[] = "bp.supervisor_id = :supervisor_id";
    $params[':supervisor_id'] = $supervisor_id;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM book_packing bp
    LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN fiscal_years fy ON bp.fiscal_year_id = fy.id
    $where_clause
";

$count_stmt = $conn->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $per_page);

// Main query to get packing records
$main_query = "
    SELECT 
        bp.*,
        jt.job_ticket_code,
        jt.lot,
        b.book_name,
        b.book_code as book_code_full,
        b.class_level,
        fy.fiscal_code,
        u_supervisor.username as supervisor_name,
        u_incharge.username as incharge_name,
        u_operator.username as operator_name,
        u_created.username as created_by_name
    FROM book_packing bp
    LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN fiscal_years fy ON bp.fiscal_year_id = fy.id
    LEFT JOIN users u_supervisor ON bp.supervisor_id = u_supervisor.id
    LEFT JOIN users u_incharge ON bp.incharge_id = u_incharge.id
    LEFT JOIN users u_operator ON bp.operator_id = u_operator.id
    LEFT JOIN users u_created ON bp.created_by = u_created.id
    $where_clause
    ORDER BY bp.created_date DESC, bp.id DESC
    LIMIT :limit OFFSET :offset
";

$stmt = $conn->prepare($main_query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$packing_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get filter options
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$book_codes = $conn->query("
    SELECT DISTINCT bp.book_code 
    FROM book_packing bp 
    WHERE bp.status = true 
    ORDER BY bp.book_code
")->fetchAll(PDO::FETCH_ASSOC);
$supervisors = $conn->query("
    SELECT DISTINCT u.id, u.username 
    FROM users u 
    INNER JOIN book_packing bp ON u.id = bp.supervisor_id 
    WHERE bp.status = true AND u.role IN ('supervisor', 'admin') 
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$stats_query = "
    SELECT 
        COUNT(*) as total_records,
        SUM(bp.p_qty) as total_packed_qty,
        COUNT(CASE WHEN bp.packing_status = 'active' THEN 1 END) as active_records,
        COUNT(CASE WHEN bp.packing_status = 'completed' THEN 1 END) as completed_records,
        COUNT(CASE WHEN bp.packing_status = 'pending' THEN 1 END) as pending_records
    FROM book_packing bp
    WHERE bp.status = true
";

$stats_stmt = $conn->query($stats_query);
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

// Handle AJAX delete request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'delete') {
    try {
        $delete_id = $_POST['id'];
        $delete_stmt = $conn->prepare("UPDATE book_packing SET status = false, updated_by = :updated_by, updated_date = NOW() WHERE id = :id");
        $delete_stmt->execute([':id' => $delete_id, ':updated_by' => $_SESSION['user_id']]);
        
        echo json_encode(['success' => true, 'message' => 'Packing record deleted successfully']);
        exit;
    } catch (Exception $e) {
        echo json_encode(['success' => false, 'message' => 'Error deleting record: ' . $e->getMessage()]);
        exit;
    }
}
?>

<style>
    /* Enhanced styles for professional look */
    .container {
        max-width: 100%;
        padding: 20px;
    }

    h1 {
        margin: 20px 15px;
        color: #333;
        font-size: 32px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .stats-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(220px, 1fr));
        gap: 20px;
        margin: 0 15px 30px;
    }

    .stat-card {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 25px;
        border-radius: 12px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        transition: transform 0.2s ease;
    }

    .stat-card:hover {
        transform: translateY(-2px);
        box-shadow: 0 6px 20px rgba(0,0,0,0.15);
    }

    .stat-card.completed { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); }
    .stat-card.active { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); }
    .stat-card.pending { background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%); }
    .stat-card.total { background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%); }
    .stat-card.quantity { background: linear-gradient(135deg, #fa709a 0%, #fee140 100%); }

    .stat-number {
        font-size: 36px;
        font-weight: 800;
        margin-bottom: 8px;
        display: block;
    }

    .stat-label {
        font-size: 14px;
        opacity: 0.9;
        font-weight: 500;
    }

    .search-filter-container {
        background: #f8f9fa;
        padding: 25px;
        border-radius: 12px;
        margin: 0 15px 25px;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .search-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .search-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .search-group {
        display: flex;
        flex-direction: column;
    }

    .search-group label {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 6px;
        color: #495057;
    }

    .search-control {
        padding: 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }

    .search-control:focus {
        outline: none;
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,.1);
    }

    .btn {
        padding: 12px 20px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        text-align: center;
        margin-right: 10px;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .btn-success { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .btn-warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
    .btn-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; }
    .btn-secondary { background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%); color: white; }

    .search-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
    }

    .table-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        margin: 0 15px;
        overflow: hidden;
        border: 1px solid #e9ecef;
    }

    .table-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px 25px;
        font-size: 18px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .table-responsive {
        overflow-x: auto;
    }

    .data-table {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }

    .data-table th {
        background: #f8f9fa;
        padding: 15px 12px;
        text-align: left;
        font-weight: 600;
        color: #333;
        border-bottom: 2px solid #dee2e6;
        white-space: nowrap;
    }

    .data-table td {
        padding: 15px 12px;
        border-bottom: 1px solid #e9ecef;
        vertical-align: middle;
    }

    .data-table tbody tr:hover {
        background-color: #f8f9fa;
    }

    .data-table tbody tr:nth-child(even) {
        background-color: #fbfcfd;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .status-active { background: #d4edda; color: #155724; }
    .status-completed { background: #cce7ff; color: #004085; }
    .status-pending { background: #fff3cd; color: #856404; }

    .book-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }

    .book-code {
        font-weight: 600;
        color: #495057;
    }

    .book-name {
        color: #6c757d;
        font-size: 12px;
    }

    .quantity-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
        text-align: right;
    }

    .qty-number {
        font-weight: 600;
        font-size: 16px;
    }

    .qty-label {
        font-size: 11px;
        color: #6c757d;
        text-transform: uppercase;
    }

    .user-info {
        font-size: 13px;
        color: #495057;
    }

    .date-info {
        display: flex;
        flex-direction: column;
        gap: 2px;
    }

    .date-nep {
        font-weight: 600;
        color: #495057;
    }

    .date-eng {
        color: #6c757d;
        font-size: 12px;
    }

    .actions-dropdown {
        position: relative;
        display: inline-block;
    }

    .dropdown-btn {
        background: #f8f9fa;
        border: 1px solid #dee2e6;
        padding: 8px 12px;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .dropdown-menu-bp {
        position: absolute;
        top: 100%;
        right: 0;
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        z-index: 1000;
        min-width: 150px;
        display: none;
    }

    .dropdown-item {
        display: flex;
        align-items: center;
        gap: 8px;
        padding: 10px 15px;
        text-decoration: none;
        color: #333;
        border-bottom: 1px solid #f1f3f5;
        transition: background-color 0.2s;
    }

    .dropdown-item:hover {
        background-color: #f8f9fa;
    }

    .dropdown-item:last-child {
        border-bottom: none;
    }

    .pagination {
        display: flex;
        justify-content: center;
        align-items: center;
        gap: 5px;
        margin: 25px 15px;
    }

    .page-btn {
        padding: 8px 12px;
        border: 1px solid #dee2e6;
        background: white;
        color: #495057;
        text-decoration: none;
        border-radius: 6px;
        transition: all 0.2s ease;
    }

    .page-btn:hover {
        background: #f8f9fa;
        text-decoration: none;
        color: #495057;
    }

    .page-btn.active {
        background: #007bff;
        color: white;
        border-color: #007bff;
    }

    .page-info {
        margin: 0 15px;
        color: #6c757d;
        font-size: 14px;
    }

    .empty-state {
        text-align: center;
        padding: 60px 20px;
        color: #6c757d;
    }

    .empty-state-icon {
        font-size: 64px;
        margin-bottom: 20px;
        opacity: 0.3;
    }

    .empty-state h3 {
        margin-bottom: 10px;
        color: #495057;
    }

    .alert {
        margin: 0 15px 20px;
        padding: 15px 20px;
        border-radius: 8px;
        border: 1px solid transparent;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
    }

    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border-color: #f5c6cb;
    }

    @media (max-width: 768px) {
        .search-row {
            grid-template-columns: 1fr;
        }
        
        .search-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .stats-cards {
            grid-template-columns: 1fr;
        }

        .table-responsive {
            font-size: 13px;
        }

        .data-table th, .data-table td {
            padding: 10px 8px;
        }
    }

    .loading {
        opacity: 0.6;
        pointer-events: none;
    }

    .spinner {
        display: inline-block;
        width: 20px;
        height: 20px;
        border: 2px solid #f3f3f3;
        border-top: 2px solid #007bff;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }
</style>

<div class="container">
    
    <!-- Search and Filters -->
    <div class="search-filter-container">
        <div class="search-title">
            <i class="fas fa-search"></i> Search & Filter Packing Records
        </div>
        
        <form method="GET" action="" id="searchForm">
            <div class="search-row">
                <div class="search-group">
                    <label for="search">🔍 General Search</label>
                    <input type="text" name="search" id="search" class="search-control" 
                           placeholder="Search by name, book code, job ticket..." 
                           value="<?= htmlspecialchars($search) ?>">
                </div>
                
                <div class="search-group">
                    <label for="book_code">📚 Book Code</label>
                    <select name="book_code" id="book_code" class="search-control">
                        <option value="">All Book Codes</option>
                        <?php foreach ($book_codes as $code): ?>
                            <option value="<?= htmlspecialchars($code['book_code']) ?>" 
                                    <?= $book_code === $code['book_code'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($code['book_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="fiscal_year_id">📅 Fiscal Year</label>
                    <select name="fiscal_year_id" id="fiscal_year_id" class="search-control">
                        <option value="">All Fiscal Years</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $fiscal_year_id == $fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="packing_status">📦 Packing Status</label>
                    <select name="packing_status" id="packing_status" class="search-control">
                        <option value="">All Statuses</option>
                        <option value="active" <?= $packing_status === 'active' ? 'selected' : '' ?>>Active</option>
                        <option value="completed" <?= $packing_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                        <option value="pending" <?= $packing_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    </select>
                </div>
            </div>
            
            <div class="search-row">
                <div class="search-group">
                    <label for="date_from">📅 Date From (Nepali)</label>
                    <input type="text" name="date_from" id="date_from" class="search-control" 
                           placeholder="2081.01.01" value="<?= htmlspecialchars($date_from) ?>">
                </div>
                
                <div class="search-group">
                    <label for="date_to">📅 Date To (Nepali)</label>
                    <input type="text" name="date_to" id="date_to" class="search-control" 
                           placeholder="2081.12.30" value="<?= htmlspecialchars($date_to) ?>">
                </div>
                
                <div class="search-group">
                    <label for="supervisor_id">👔 Supervisor</label>
                    <select name="supervisor_id" id="supervisor_id" class="search-control">
                        <option value="">All Supervisors</option>
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= $supervisor['id'] ?>" <?= $supervisor_id == $supervisor['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($supervisor['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="search-actions">
                <div>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search"></i> Search
                    </button>
                    <a href="?" class="btn btn-secondary">
                        <i class="fas fa-refresh"></i> Clear Filters
                    </a>
                </div>
                <div>
                    <?php if (has_role('incharge') || has_role('supervisor') || has_role('admin')): ?>
                        <a href="create.php" class="btn btn-success">
                            <i class="fas fa-plus"></i> Create New Packing
                        </a>
                    <?php endif; ?>
                    <button type="button" class="btn btn-warning" onclick="exportData()">
                        <i class="fas fa-download"></i> Export Data
                    </button>
                </div>
            </div>
        </form>
    </div>

    <!-- Data Table -->
    <div class="table-container">
        <div class="table-header">
            <span>📋 Packing Records (<?= number_format($total_records) ?> total)</span>
            <span>Page <?= $page ?> of <?= $total_pages ?></span>
        </div>
        
        <?php if (empty($packing_records)): ?>
            <div class="empty-state">
                <div class="empty-state-icon">📦</div>
                <h3>No Packing Records Found</h3>
                <p>
                    <?php if (!empty(array_filter([$search, $book_code, $fiscal_year_id, $packing_status, $date_from, $date_to, $supervisor_id]))): ?>
                        No records match your current filters. Try adjusting your search criteria.
                    <?php else: ?>
                        No packing records have been created yet. Click "Create New Packing" to get started.
                    <?php endif; ?>
                </p>
                <?php if (has_role('editor') || has_role('admin')): ?>
                    <a href="packing_create.php" class="btn btn-success">
                        <i class="fas fa-plus"></i> Create First Packing Record
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="data-table">
                    <thead>
                        <tr>
                            <th>S.N.</th>
                            <th>Record Name</th>
                            <th>Job Ticket</th>
                            <th>Book Information</th>
                            <th>Quantities</th>
                            <th>Date</th>
                            <th>Personnel</th>
                            <th>Status</th>
                            <th>Fiscal Year</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($packing_records as $index => $record): ?>
                            <tr id="row-<?= $record['id'] ?>">
                                <td><?= $offset + $index + 1 ?></td>
                                
                                <td>
                                    <div style="font-weight: 600; color: #495057;">
                                        <?= htmlspecialchars($record['name']) ?>
                                    </div>
                                    <?php if (!empty($record['remarks'])): ?>
                                        <div style="font-size: 12px; color: #6c757d; margin-top: 2px;">
                                            <i class="fas fa-comment"></i> <?= htmlspecialchars(substr($record['remarks'], 0, 50)) ?><?= strlen($record['remarks']) > 50 ? '...' : '' ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div style="font-weight: 600; color: #007bff;">
                                        <?= htmlspecialchars($record['job_ticket_code']) ?>
                                    </div>
                                    <?php if (!empty($record['lot'])): ?>
                                        <div style="font-size: 12px; color: #6c757d;">
                                            Lot: <?= htmlspecialchars($record['lot']) ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                
                                <td>
                                    <div class="book-info">
                                        <div class="book-code"><?= htmlspecialchars($record['book_code']) ?></div>
                                        <div class="book-name"><?= htmlspecialchars($record['book_name']) ?></div>
                                        <div style="font-size: 11px; color: #6c757d;">Class: <?= $record['class_level'] ?></div>
                                    </div>
                                </td>
                                
                                <td>
                                    <div class="quantity-info">
                                        <div>
                                            <span class="qty-number" style="color: #28a745;"><?= number_format($record['p_qty']) ?></span>
                                            <div class="qty-label">Packed Qty</div>
                                        </div>
                                        <div style="margin-top: 5px; padding-top: 5px; border-top: 1px solid #e9ecef;">
                                            <span style="color: #6c757d; font-size: 12px;">JT Total: <?= number_format($record['jt_print_qty']) ?></span>
                                        </div>
                                    </div>
                                </td>
                                
                                <td>
                                    <div class="date-info">
                                        <div class="date-nep"><?= htmlspecialchars($record['date_nep']) ?></div>
                                        <div class="date-eng"><?= htmlspecialchars($record['date_eng']) ?></div>
                                    </div>
                                </td>
                                
                                <td>
                                    <div style="font-size: 12px;">
                                        <div class="user-info">
                                            <strong>S:</strong> <?= htmlspecialchars($record['supervisor_name']) ?>
                                        </div>
                                        <div class="user-info">
                                            <strong>I:</strong> <?= htmlspecialchars($record['incharge_name']) ?>
                                        </div>
                                        <div class="user-info">
                                            <strong>O:</strong> <?= htmlspecialchars($record['operator_name']) ?>
                                        </div>
                                    </div>
                                </td>
                                
                                <td>
                                    <span class="status-badge status-<?= $record['packing_status'] ?>">
                                        <?= ucfirst($record['packing_status']) ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <span style="font-weight: 500; color: #495057;">
                                        <?= htmlspecialchars($record['fiscal_code']) ?>
                                    </span>
                                </td>
                                
                                <td>
                                    <div class="actions-dropdown">
                                        <button type="button" class="dropdown-btn" onclick="toggleDropdown(<?= $record['id'] ?>)">
                                            <i class="fas fa-ellipsis-v"></i> Actions
                                        </button>
                                        <div class="dropdown-menu-bp" id="dropdown-<?= $record['id'] ?>">
                                            <a href="view.php?id=<?= $record['id'] ?>" class="dropdown-item">
                                                <i class="fas fa-eye"></i> View Details
                                            </a>
                                            <?php if (has_role('incharge') || has_role('supervisor') || has_role('admin')): ?>
                                                <a href="edit.php?id=<?= $record['id'] ?>" class="dropdown-item">
                                                    <i class="fas fa-edit"></i> Edit Record
                                                </a>
                                                <a href="#" class="dropdown-item" onclick="deleteRecord(<?= $record['id'] ?>, '<?= htmlspecialchars($record['name']) ?>')">
                                                    <i class="fas fa-trash"></i> Delete
                                                </a>
                                            <?php endif; ?>
                                            <a href="packing_report.php?id=<?= $record['id'] ?>" class="dropdown-item">
                                                <i class="fas fa-file-pdf"></i> Generate Report
                                            </a>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
        <div class="pagination">
            <?php if ($page > 1): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>" class="page-btn">
                    <i class="fas fa-angle-double-left"></i> First
                </a>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>" class="page-btn">
                    <i class="fas fa-angle-left"></i> Previous
                </a>
            <?php endif; ?>

            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            for ($i = $start_page; $i <= $end_page; $i++):
            ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>" 
                   class="page-btn <?= $i == $page ? 'active' : '' ?>">
                    <?= $i ?>
                </a>
            <?php endfor; ?>

            <?php if ($page < $total_pages): ?>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>" class="page-btn">
                    Next <i class="fas fa-angle-right"></i>
                </a>
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>" class="page-btn">
                    Last <i class="fas fa-angle-double-right"></i>
                </a>
            <?php endif; ?>
        </div>

        <div class="page-info">
            Showing <?= number_format($offset + 1) ?> to <?= number_format(min($offset + $per_page, $total_records)) ?> of <?= number_format($total_records) ?> entries
        </div>
    <?php endif; ?>
</div>

<!-- JavaScript -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit search form on filter change
    const searchForm = document.getElementById('searchForm');
    const filterSelects = searchForm.querySelectorAll('select');
    
    filterSelects.forEach(select => {
        select.addEventListener('change', function() {
            searchForm.submit();
        });
    });

    // Format date inputs
    const dateInputs = document.querySelectorAll('#date_from, #date_to');
    dateInputs.forEach(input => {
        input.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 4) {
                value = value.substring(0, 4) + '.' + value.substring(4);
            }
            if (value.length > 7) {
                value = value.substring(0, 7) + '.' + value.substring(7, 9);
            }
            this.value = value.substring(0, 10);
        });
    });
});

// Toggle dropdown menus
function toggleDropdown(id) {
    // Close all other dropdowns
    document.querySelectorAll('.dropdown-menu-bp').forEach(menu => {
        if (menu.id !== `dropdown-${id}`) {
            menu.style.display = 'none';
        }
    });
    
    // Toggle current dropdown
    const dropdown = document.getElementById(`dropdown-${id}`);
    dropdown.style.display = dropdown.style.display === 'block' ? 'none' : 'block';
}

// Close dropdowns when clicking outside
document.addEventListener('click', function(e) {
    if (!e.target.closest('.actions-dropdown')) {
        document.querySelectorAll('.dropdown-menu-bp').forEach(menu => {
            menu.style.display = 'none';
        });
    }
});

// Delete record function
function deleteRecord(id, name) {
    if (confirm(`Are you sure you want to delete the packing record "${name}"?\n\nThis action will mark the record as inactive and cannot be undone.`)) {
        const row = document.getElementById(`row-${id}`);
        row.classList.add('loading');
        
        fetch('', {
            method: 'POST',
            headers: {
                'Content-Type': 'application/x-www-form-urlencoded',
            },
            body: `action=delete&id=${id}`
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Fade out and remove row
                row.style.transition = 'opacity 0.5s ease';
                row.style.opacity = '0';
                setTimeout(() => {
                    row.remove();
                    showAlert('Record deleted successfully', 'success');
                    
                    // Update statistics if needed
                    updateStatistics();
                }, 500);
            } else {
                row.classList.remove('loading');
                showAlert('Error deleting record: ' + data.message, 'danger');
            }
        })
        .catch(error => {
            row.classList.remove('loading');
            console.error('Delete error:', error);
            showAlert('An error occurred while deleting the record', 'danger');
        });
    }
}

// Export data function
function exportData() {
    const params = new URLSearchParams(window.location.search);
    params.set('export', 'csv');
    window.open('packing_export.php?' + params.toString(), '_blank');
}

// Update statistics after deletion
function updateStatistics() {
    fetch('packing_stats.php')
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                document.querySelector('.stat-card.total .stat-number').textContent = 
                    new Intl.NumberFormat().format(data.stats.total_records);
                document.querySelector('.stat-card.quantity .stat-number').textContent = 
                    new Intl.NumberFormat().format(data.stats.total_packed_qty);
                document.querySelector('.stat-card.active .stat-number').textContent = 
                    new Intl.NumberFormat().format(data.stats.active_records);
                document.querySelector('.stat-card.completed .stat-number').textContent = 
                    new Intl.NumberFormat().format(data.stats.completed_records);
                document.querySelector('.stat-card.pending .stat-number').textContent = 
                    new Intl.NumberFormat().format(data.stats.pending_records);
            }
        })
        .catch(error => console.error('Stats update error:', error));
}

// Show alert messages
function showAlert(message, type = 'info') {
    // Remove existing alerts
    document.querySelectorAll('.alert').forEach(alert => alert.remove());
    
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type}`;
    alertDiv.innerHTML = `<i class="fas fa-${getAlertIcon(type)}"></i> ${message}`;
    
    const container = document.querySelector('.container');
    const firstChild = container.children[1]; // Insert after h1
    container.insertBefore(alertDiv, firstChild);
    
    // Auto-remove after 5 seconds
    setTimeout(() => {
        alertDiv.style.transition = 'opacity 0.5s ease';
        alertDiv.style.opacity = '0';
        setTimeout(() => alertDiv.remove(), 500);
    }, 5000);
}

function getAlertIcon(type) {
    switch(type) {
        case 'success': return 'check-circle';
        case 'danger': return 'exclamation-triangle';
        case 'warning': return 'exclamation-triangle';
        default: return 'info-circle';
    }
}

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // Ctrl+N for new record
    if (e.ctrlKey && e.key === 'n') {
        e.preventDefault();
        const createBtn = document.querySelector('a[href="packing_create.php"]');
        if (createBtn) createBtn.click();
    }
    
    // Ctrl+F for search focus
    if (e.ctrlKey && e.key === 'f') {
        e.preventDefault();
        document.getElementById('search').focus();
    }
});

console.log('Book Packing Index loaded successfully');
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
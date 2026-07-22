<?php
// jobticket/index.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';

// Pagination settings
$items_per_page = 50;
$current_page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($current_page - 1) * $items_per_page;

// Filter parameters
$book_code_filter = isset($_GET['book_code']) ? $_GET['book_code'] : '';
$class_filter = isset($_GET['class']) ? $_GET['class'] : '';
$fiscal_year_filter = isset($_GET['fiscal_year']) ? $_GET['fiscal_year'] : '';
$status_filter = isset($_GET['status']) ? $_GET['status'] : '';
$search_text = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if (!empty($book_code_filter)) {
    $where_conditions[] = "b.book_code = ?";
    $params[] = $book_code_filter;
}

if (!empty($class_filter)) {
    $where_conditions[] = "b.class_level = ?";
    $params[] = $class_filter;
}

if (!empty($fiscal_year_filter)) {
    $where_conditions[] = "j.fiscal_year_id = ?";
    $params[] = $fiscal_year_filter;
}

if (!empty($status_filter)) {
    $where_conditions[] = "j.status = ?";
    $params[] = $status_filter;
}

if (!empty($search_text)) {
    $where_conditions[] = "(j.job_ticket_code ILIKE ? OR b.book_name ILIKE ? OR j.lot ILIKE ?)";
    $params[] = "%$search_text%";
    $params[] = "%$search_text%";
    $params[] = "%$search_text%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count for pagination
$count_query = "
    SELECT COUNT(*) as total
    FROM job_ticket j
    JOIN books b ON j.book_id = b.book_id
    JOIN users u ON j.created_by = u.id
    JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
    $where_clause
";

$count_stmt = $conn->prepare($count_query);
$count_stmt->execute($params);
$total_records = $count_stmt->fetch()['total'];
$total_pages = ceil($total_records / $items_per_page);

// Get job tickets with pagination
$tickets_query = "
    SELECT j.id, j.job_ticket_code, j.lot, j.print_qty, j.status,
           j.created_date, j.class, j.print_done_qty, j.page_qty,
           b.book_name, b.book_code, b.class_level, u.username as created_by,
           fy.fiscal_code, fy.id as fiscal_year_id
    FROM job_ticket j
    JOIN books b ON j.book_id = b.book_id
    JOIN users u ON j.created_by = u.id
    JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
    $where_clause
    ORDER BY j.created_date DESC
    LIMIT ? OFFSET ?
";

$params[] = $items_per_page;
$params[] = $offset;

$tickets_stmt = $conn->prepare($tickets_query);
$tickets_stmt->execute($params);
$tickets = $tickets_stmt->fetchAll();

// Get filter options
$book_codes = $conn->query("SELECT DISTINCT book_code, book_name FROM books ORDER BY book_code")->fetchAll();
$classes = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll();
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll();
$statuses = $conn->query("SELECT DISTINCT status FROM job_ticket WHERE status IS NOT NULL ORDER BY status")->fetchAll();

// Handle export
if (isset($_GET['export'])) {
    // Get all records for export (without pagination)
    $export_query = "
        SELECT j.job_ticket_code, b.book_code, b.book_name, fy.fiscal_code,
               b.class_level, j.lot, j.print_qty, j.print_done_qty, j.page_qty,
               j.status, u.username as created_by, j.created_date
        FROM job_ticket j
        JOIN books b ON j.book_id = b.book_id
        JOIN users u ON j.created_by = u.id
        JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
        $where_clause
        ORDER BY j.created_date DESC
    ";
    
    $export_params = array_slice($params, 0, -2); // Remove LIMIT and OFFSET params
    $export_stmt = $conn->prepare($export_query);
    $export_stmt->execute($export_params);
    $export_data = $export_stmt->fetchAll();
    
    if ($_GET['export'] === 'excel') {
        // Set headers for Excel download
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment;filename="job_tickets_' . date('Y-m-d_H-i-s') . '.xls"');
        header('Cache-Control: max-age=0');
        
        echo "<table border='1'>";
        echo "<tr>";
        echo "<th>Job Ticket Code</th>";
        echo "<th>Book Code</th>";
        echo "<th>Book Name</th>";
        echo "<th>Fiscal Year</th>";
        echo "<th>Class Level</th>";
        echo "<th>Lot No</th>";
        echo "<th>Print Qty</th>";
        echo "<th>Print Done</th>";
        echo "<th>Page Qty</th>";
        echo "<th>Status</th>";
        echo "<th>Created By</th>";
        echo "<th>Created Date</th>";
        echo "</tr>";
        
        foreach ($export_data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['job_ticket_code']) . "</td>";
            echo "<td>" . htmlspecialchars($row['book_code']) . "</td>";
            echo "<td>" . htmlspecialchars($row['book_name']) . "</td>";
            echo "<td>" . htmlspecialchars($row['fiscal_code']) . "</td>";
            echo "<td>" . htmlspecialchars($row['class_level'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['lot'] ?? 'N/A') . "</td>";
            echo "<td>" . number_format($row['print_qty']) . "</td>";
            echo "<td>" . number_format($row['print_done_qty'] ?? 0) . "</td>";
            echo "<td>" . number_format($row['page_qty']) . "</td>";
            echo "<td>" . ucfirst(str_replace('_', ' ', $row['status'])) . "</td>";
            echo "<td>" . htmlspecialchars($row['created_by']) . "</td>";
            echo "<td>" . $row['created_date'] . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        exit;
    }
    
    if ($_GET['export'] === 'pdf') {
        // Include PDF library (assuming you have TCPDF or similar)
        require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/tcpdf/tcpdf.php';
        
        $pdf = new TCPDF('L', PDF_UNIT, 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Job Ticket System');
        $pdf->SetTitle('Job Tickets Export');
        $pdf->SetMargins(10, 10, 10);
        $pdf->SetAutoPageBreak(TRUE, 10);
        $pdf->AddPage();
        
        $html = '<style>
            table { border-collapse: collapse; width: 100%; font-size: 8px; }
            th { background-color: #f0f0f0; border: 1px solid #000; padding: 3px; font-weight: bold; }
            td { border: 1px solid #000; padding: 3px; }
        </style>';
        
        $html .= '<table>';
        $html .= '<tr>
            <th>Job Code</th>
            <th>Book Code</th>
            <th>Book Name</th>
            <th>Fiscal Year</th>
            <th>Class</th>
            <th>Lot</th>
            <th>Print Qty</th>
            <th>Done</th>
            <th>Status</th>
            <th>Created By</th>
            <th>Date</th>
        </tr>';
        
        foreach ($export_data as $row) {
            $html .= '<tr>';
            $html .= '<td>' . htmlspecialchars($row['job_ticket_code']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['book_code']) . '</td>';
            $html .= '<td>' . htmlspecialchars(substr($row['book_name'], 0, 30)) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['fiscal_code']) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['class_level'] ?? 'N/A') . '</td>';
            $html .= '<td>' . htmlspecialchars($row['lot'] ?? 'N/A') . '</td>';
            $html .= '<td>' . number_format($row['print_qty']) . '</td>';
            $html .= '<td>' . number_format($row['print_done_qty'] ?? 0) . '</td>';
            $html .= '<td>' . ucfirst(str_replace('_', ' ', $row['status'])) . '</td>';
            $html .= '<td>' . htmlspecialchars($row['created_by']) . '</td>';
            $html .= '<td>' . date('Y-m-d', strtotime($row['created_date'])) . '</td>';
            $html .= '</tr>';
        }
        $html .= '</table>';
        
        $pdf->writeHTML($html, true, false, true, false, '');
        $pdf->Output('job_tickets_' . date('Y-m-d_H-i-s') . '.pdf', 'D');
        exit;
    }
}

// Handle delete — must also run BEFORE any HTML output (uses header() redirect)
if (isset($_POST['delete_id']) && (has_role('admin') || has_role('editor'))) {
    $delete_id = (int)$_POST['delete_id'];
    try {
        $conn->beginTransaction();

        // Delete job ticket details first
        $stmt = $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ?");
        $stmt->execute([$delete_id]);

        // Delete job ticket
        $stmt = $conn->prepare("DELETE FROM job_ticket WHERE id = ?");
        $stmt->execute([$delete_id]);

        $conn->commit();
        $_SESSION['success'] = 'Job ticket deleted successfully.';
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Error deleting job ticket: ' . $e->getMessage();
    }

    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit;
}

// ── Normal page load ────────────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

 <div class="container">
    <!-- Header Section -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="text-primary fw-bold mb-1">
                <i class="fas fa-tasks me-2"></i>Job Tickets Management
            </h2>
        </div>
        <div class="col-md-4 text-md-end">
            <?php if (has_role('editor') || has_role('admin')): ?>
                <a href="create.php" class="btn btn-primary btn-lg shadow">
                    <i class="fas fa-plus-circle me-2"></i>Create New Ticket
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show">
            <i class="fas fa-check-circle me-2"></i><?= $_SESSION['success'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show">
            <i class="fas fa-exclamation-triangle me-2"></i><?= $_SESSION['error'] ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Search & Filters Card -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-dark">
                    <i class="fas fa-filter me-2"></i>Search & Filters
                </h5>
                <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">
                    <i class="fas fa-sync me-1"></i>Reset
                </button>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <div class="row g-3">
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search_text) ?>" placeholder="Job code, book name...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Book Code</label>
                        <select class="form-select" name="book_code">
                            <option value="">All Books</option>
                            <?php foreach ($book_codes as $book): ?>
                                <option value="<?= $book['book_code'] ?>" <?= $book_code_filter === $book['book_code'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($book['book_code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Class Level</label>
                        <select class="form-select" name="class">
                            <option value="">All Classes</option>
                            <?php foreach ($classes as $class): ?>
                                <option value="<?= $class['class_level'] ?>" <?= $class_filter == $class['class_level'] ? 'selected' : '' ?>>
                                    Class <?= $class['class_level'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Fiscal Year</label>
                        <select class="form-select" name="fiscal_year">
                            <option value="">All Years</option>
                            <?php foreach ($fiscal_years as $fy): ?>
                                <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fy['fiscal_code']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Status</label>
                        <select class="form-select" name="status">
                            <option value="">All Status</option>
                            <?php foreach ($statuses as $status): ?>
                                <option value="<?= $status['status'] ?>" <?= $status_filter === $status['status'] ? 'selected' : '' ?>>
                                    <?= ucfirst(str_replace('_', ' ', $status['status'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary w-100">
                            <i class="fas fa-search me-1"></i>Search
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Export Options -->
    <div class="card shadow-sm mb-4">
        <div class="card-body py-3">
            <div class="d-flex justify-content-between align-items-center">
                <h6 class="mb-0 text-muted">
                    <i class="fas fa-download me-2"></i>Export Options
                </h6>
                <div class="btn-group">
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" 
                       class="btn btn-success btn-sm" target="_blank">
                        <i class="fas fa-file-excel me-1"></i>Excel
                    </a>
                    <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'pdf'])) ?>" 
                       class="btn btn-danger btn-sm" target="_blank">
                        <i class="fas fa-file-pdf me-1"></i>PDF
                    </a>
                    <button class="btn btn-info btn-sm" onclick="printTable()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Results Card -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Job Tickets List
                <span class="badge bg-light text-primary ms-2"><?= number_format($total_records) ?></span>
            </h5>
            <small>
                <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $items_per_page, $total_records)) ?> of <?= number_format($total_records) ?>
            </small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="ticketsTable">
                <thead class="table-dark">
                    <tr>
                        <th>Job Code</th>
                        <th>Book Code</th>
                        <th>Book Name</th>
                        <th>Fiscal Year</th>
                        <th>Class</th>
                        <th>Lot</th>
                        <th>Print Qty</th>
                        <th>Done Qty</th>
                        <th>Progress</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Date</th>
                        <th>Action</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($tickets)): ?>
                        <tr>
                            <td colspan="13" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No job tickets found matching your criteria.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($tickets as $ticket): ?>
                            <?php 
                            $progress = $ticket['print_qty'] > 0 ? 
                                round(($ticket['print_done_qty'] / $ticket['print_qty']) * 100, 1) : 0;
                            ?>
                            <tr>
                                <td><strong class="text-primary"><?= htmlspecialchars($ticket['job_ticket_code']) ?></strong></td>
                                <td><code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($ticket['book_code']) ?></code></td>
                                <td class="text-truncate" style="max-width: 150px;" title="<?= htmlspecialchars($ticket['book_name']) ?>">
                                    <?= htmlspecialchars($ticket['book_name']) ?>
                                </td>
                                <td><?= htmlspecialchars($ticket['fiscal_code']) ?></td>
                                <td>
                                    <?php if ($ticket['class_level']): ?>
                                        <span class="badge bg-secondary">Class <?= $ticket['class_level'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($ticket['lot'] ?: 'N/A') ?></td>
                                <td><strong class="text-success"><?= number_format($ticket['print_qty']) ?></strong></td>
                                <td><strong class="text-info"><?= number_format($ticket['print_done_qty'] ?? 0) ?></strong></td>
                                <td>
                                    <div class="progress" style="height: 20px;">
                                        <div class="progress-bar <?= $progress >= 100 ? 'bg-success' : ($progress > 50 ? 'bg-warning' : 'bg-info') ?>"
                                             role="progressbar" style="width: <?= $progress ?>%">
                                            <?= $progress ?>%
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <span class="badge bg-<?= getStatusBadge($ticket['status']) ?>">
                                        <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($ticket['created_by']) ?></td>
                                <td><?= date('M d, Y', strtotime($ticket['created_date'])) ?></td>
                                <td>
                                    <a href="view.php?id=<?= $ticket['id'] ?>" class="btn btn-sm" title="View Details">
                                       View
                                    </a>
                                    <a href="print.php?id=<?= $ticket['id'] ?>" class="btn btn-sm" target="_blank"  title="Print">
                                       Print
                                    </a>
                                    <?php if (has_role('editor') || has_role('admin')): ?>
                                        <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn btn-sm" title="Edit">
                                          Edit
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>

        <!-- Pagination -->
        <?php if ($total_pages > 1): ?>
            <div class="card-footer bg-light">
                <nav>
                    <ul class="pagination justify-content-center mb-0">
                        <?php
                        $query_params = $_GET;
                        unset($query_params['page']);
                        $base_url = '?' . http_build_query($query_params) . '&page=';
                        ?>
                        <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $base_url . ($current_page - 1) ?>">
                                <i class="fas fa-chevron-left"></i> Prev
                            </a>
                        </li>
                        <?php for ($i = max(1, $current_page - 1); $i <= min($total_pages, $current_page + 1); $i++): ?>
                            <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                <a class="page-link" href="<?= $base_url . $i ?>"><?= $i ?></a>
                            </li>
                        <?php endfor; ?>
                        <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                            <a class="page-link" href="<?= $base_url . ($current_page + 1) ?>">Next <i class="fas fa-chevron-right"></i></a>
                        </li>
                    </ul>
                </nav>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1">
    <div class="modal-dialog">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-trash me-2"></i>Confirm Delete</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center">
                <i class="fas fa-exclamation-triangle fa-3x text-warning mb-3"></i>
                <h5>Permanently delete this job ticket?</h5>
                <p class="text-muted">Job Ticket: <strong id="deleteTicketCode"></strong></p>
                <p class="text-danger">This action cannot be undone.</p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <form method="POST" class="d-inline">
                    <input type="hidden" name="delete_id" id="deleteId">
                    <button type="submit" class="btn btn-danger">
                        <i class="fas fa-trash me-1"></i>Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>
<!-- Print Styles -->
<style>
    body {
    font-size: 14px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 20px;
    background-color: #f8f9fa;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    background: white;
    padding: 20px;
    border-radius: 8px;
   
}
@media print {
    .no-print, .btn, .card-header, .card-footer, .alert, .modal { 
        display: none !important; 
    }
    .card { 
        border: none !important; 
        box-shadow: none !important; 
        margin: 0 !important;
    }
    .card-body { 
        padding: 0 !important; 
    }
    .table { 
        font-size: 10px !important; 
    }
    .table th, .table td { 
        padding: 4px !important; 
        border: 1px solid #000 !important; 
    }
    .table thead th { 
        background-color: #f8f9fa !important; 
        color: #000 !important; 
    }


.card {
    border: none;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1), 0 2px 4px -1px rgba(0, 0, 0, 0.06);
    transition: all 0.3s ease;
}

.card:hover {
    box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1), 0 4px 6px -2px rgba(0, 0, 0, 0.05);
}

.card-header {
    border-radius: 12px 12px 0 0 !important;
    border-bottom: 1px solid rgba(255,255,255,0.1);
}

.table th {
    background: linear-gradient(135deg, #343a40 0%, #495057 100%);
    color: white !important;
    font-weight: 600;
    border: none !important;
    text-transform: uppercase;
    font-size: 0.85rem;
    letter-spacing: 0.5px;
}

.table tbody tr {
    transition: all 0.2s ease;
}

.table tbody tr:hover {
    background-color: rgba(0, 123, 255, 0.05);
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.progress {
    border-radius: 15px;
    background-color: rgba(0,0,0,0.05);
    overflow: hidden;
    box-shadow: inset 0 1px 2px rgba(0,0,0,0.1);
}

.progress-bar {
    border-radius: 15px;
    font-size: 11px;
    font-weight: bold;
    transition: width 0.6s ease;
    background: linear-gradient(45deg, currentColor 25%, transparent 25%), 
                linear-gradient(-45deg, currentColor 25%, transparent 25%), 
                linear-gradient(45deg, transparent 75%, currentColor 75%), 
                linear-gradient(-45deg, transparent 75%, currentColor 75%);
    background-size: 4px 4px;
    background-position: 0 0, 0 2px, 2px -2px, -2px 0px;
}

.badge {
    font-size: 0.75em;
    padding: 0.5em 0.8em;
    border-radius: 8px;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.btn {
    border-radius: 8px;
    font-weight: 500;
    transition: all 0.2s ease;
    border: 2px solid transparent;
}

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.btn-group .btn {
    margin-right: 2px;
    border-radius: 6px !important;
}

.btn-outline-info:hover { background-color: #17a2b8; border-color: #17a2b8; }
.btn-outline-success:hover { background-color: #28a745; border-color: #28a745; }
.btn-outline-warning:hover { background-color: #ffc107; border-color: #ffc107; color: #000; }
.btn-outline-danger:hover { background-color: #dc3545; border-color: #dc3545; }

.form-control, .form-select {
    border-radius: 8px;
    border: 2px solid #e9ecef;
    transition: all 0.2s ease;
}

.form-control:focus, .form-select:focus {
    border-color: #0d6efd;
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.15);
    transform: translateY(-1px);
}

.alert {
    border-radius: 10px;
    border: none;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.table-responsive {
    border-radius: 12px;
    overflow: hidden;
}

.pagination .page-link {
    border-radius: 8px;
    margin: 0 2px;
    border: 2px solid transparent;
    transition: all 0.2s ease;
}

.pagination .page-item.active .page-link {
    background: linear-gradient(135deg, #0d6efd 0%, #0056b3 100%);
    border-color: #0d6efd;
    box-shadow: 0 2px 4px rgba(13, 110, 253, 0.3);
}

.pagination .page-link:hover {
    transform: translateY(-1px);
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.modal-content {
    border-radius: 15px;
    border: none;
    box-shadow: 0 25px 50px -12px rgba(0, 0, 0, 0.25);
}

.modal-header {
    border-radius: 15px 15px 0 0;
}

.text-primary { color: #0d6efd !important; }
.text-success { color: #198754 !important; }
.text-info { color: #0dcaf0 !important; }
.text-warning { color: #ffc107 !important; }
.text-danger { color: #dc3545 !important; }

code {
    background-color: #f8f9fa !important;
    color: #e83e8c !important;
    padding: 0.2rem 0.4rem !important;
    border-radius: 4px !important;
    font-size: 0.875em !important;
}

.fw-semibold { font-weight: 600; }

/* Custom scrollbar */
.table-responsive::-webkit-scrollbar {
    height: 8px;
}

.table-responsive::-webkit-scrollbar-track {
    background: #f1f1f1;
    border-radius: 10px;
}

.table-responsive::-webkit-scrollbar-thumb {
    background: linear-gradient(135deg, #0d6efd, #0056b3);
    border-radius: 10px;
}

.table-responsive::-webkit-scrollbar-thumb:hover {
    background: linear-gradient(135deg, #0056b3, #004085);
}

/* Loading animation */
@keyframes pulse {
    0% { opacity: 1; }
    50% { opacity: 0.5; }
    100% { opacity: 1; }
}

.loading {
    animation: pulse 1.5s ease-in-out infinite;
}

/* Responsive improvements */
@media (max-width: 768px) {
    .btn-group {
        flex-direction: column;
    }
    
    .btn-group .btn {
        margin-bottom: 2px;
        border-radius: 6px !important;
    }
    
    .table th, .table td {
        font-size: 0.8rem;
        padding: 0.5rem 0.25rem;
    }
    
    .progress {
        height: 20px !important;
    }
}
</style>

<script>
function resetFilters() {
    window.location.href = window.location.pathname;
}

function printTable() {
    // Create a new window for printing
    const printWindow = window.open('', '_blank');
    const table = document.getElementById('ticketsTable').cloneNode(true);
    
    // Remove action column for print
    const actionHeaders = table.querySelectorAll('th:last-child, td:last-child');
    actionHeaders.forEach(cell => cell.remove());
    
    const printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Job Tickets Report</title>
            <style>
                body { font-family: Arial, sans-serif; margin: 20px; }
                table { width: 100%; border-collapse: collapse; margin-top: 20px; }
                th, td { border: 1px solid #000; padding: 8px; text-align: left; font-size: 10px; }
                th { background-color: #f0f0f0; font-weight: bold; }
                .header { text-align: center; margin-bottom: 20px; }
                .progress { display: none; }
                .badge { padding: 2px 6px; border-radius: 3px; font-size: 9px; }
                .bg-success { background-color: #28a745; color: white; }
                .bg-warning { background-color: #ffc107; color: black; }
                .bg-danger { background-color: #dc3545; color: white; }
                .bg-info { background-color: #17a2b8; color: white; }
                .bg-secondary { background-color: #6c757d; color: white; }
                .bg-primary { background-color: #007bff; color: white; }
                @page { margin: 15mm; }
            </style>
        </head>
        <body>
            <div class="header">
                <h2>Job Tickets Report</h2>
                <p>Generated on: ${new Date().toLocaleDateString()}</p>
                <p>Total Records: ${<?= $total_records ?>}</p>
            </div>
            ${table.outerHTML}
        </body>
        </html>
    `;
    
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.print();
}

function confirmDelete(id, ticketCode) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteTicketCode').textContent = ticketCode;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}

// Auto-submit form when filters change
document.addEventListener('DOMContentLoaded', function() {
    const selects = document.querySelectorAll('#filterForm select');
    selects.forEach(select => {
        select.addEventListener('change', function() {
            document.getElementById('filterForm').submit();
        });
    });
    
    // Add search delay
    const searchInput = document.querySelector('input[name="search"]');
    let searchTimeout;
    searchInput.addEventListener('input', function() {
        clearTimeout(searchTimeout);
        searchTimeout = setTimeout(() => {
            document.getElementById('filterForm').submit();
        }, 1000);
    });
    
    // Initialize tooltips
    var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
    var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
        return new bootstrap.Tooltip(tooltipTriggerEl);
    });
    
    // Add loading animation to buttons
    document.querySelectorAll('a[href*="export"]').forEach(button => {
        button.addEventListener('click', function() {
            this.classList.add('loading');
            this.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Generating...';
        });
    });
    
    // Smooth scroll to top after form submission
    if (window.location.search) {
        window.scrollTo({ top: 0, behavior: 'smooth' });
    }
});

// Add confirmation for bulk actions
function confirmBulkAction(action) {
    const selectedItems = document.querySelectorAll('input[name="selected_items[]"]:checked');
    if (selectedItems.length === 0) {
        alert('Please select at least one item.');
        return false;
    }
    
    return confirm(`Are you sure you want to ${action} ${selectedItems.length} selected item(s)?`);
}

// Enhanced progress bar animation
document.addEventListener('DOMContentLoaded', function() {
    const progressBars = document.querySelectorAll('.progress-bar');
    progressBars.forEach(bar => {
        const width = bar.style.width;
        bar.style.width = '0%';
        setTimeout(() => {
            bar.style.width = width;
        }, 200);
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
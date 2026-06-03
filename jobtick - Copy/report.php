<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Get filter parameters
$book_id = $_GET['book_id'] ?? '';
$fiscal_year_id = $_GET['fiscal_year_id'] ?? '';
$lot = $_GET['lot'] ?? '';
$status = $_GET['status'] ?? '';
$date_from = $_GET['date_from'] ?? '';
$date_to = $_GET['date_to'] ?? '';
$book_code = $_GET['book_code'] ?? '';

// Build query
$sql = "
    SELECT 
        j.id,
        j.job_ticket_code,
        j.lot,
        j.print_qty,
        j.page_qty,
        j.class,
        j.status,
        j.date_nep,
        j.date_eng,
        j.remarks,
        j.description,
        j.created_date,
        j.updated_date,
        b.book_code,
        b.book_name,
        b.class_level,
        fy.fiscal_code,
        u_created.username as created_by_name,
        u_updated.username as updated_by_name
    FROM job_ticket j
    JOIN books b ON j.book_id = b.book_id
    JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
    JOIN users u_created ON j.created_by = u_created.id
    LEFT JOIN users u_updated ON j.updated_by = u_updated.id
    WHERE 1=1
";

$params = [];

if ($book_id) {
    $sql .= " AND j.book_id = ?";
    $params[] = $book_id;
}

if ($fiscal_year_id) {
    $sql .= " AND j.fiscal_year_id = ?";
    $params[] = $fiscal_year_id;
}

if ($lot) {
    $sql .= " AND j.lot = ?";
    $params[] = $lot;
}

if ($status) {
    $sql .= " AND j.status = ?";
    $params[] = $status;
}

if ($date_from) {
    $sql .= " AND j.date_eng >= ?";
    $params[] = $date_from;
}

if ($date_to) {
    $sql .= " AND j.date_eng <= ?";
    $params[] = $date_to;
}

if ($book_code) {
    $sql .= " AND b.book_code LIKE ?";
    $params[] = "%$book_code%";
}

$sql .= " ORDER BY j.created_date DESC";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$tickets = $stmt->fetchAll();

// Get books for dropdown
$books = $conn->query("SELECT book_id, book_code, book_name FROM books ORDER BY book_code")->fetchAll();

// Get fiscal years for dropdown
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll();

// Calculate summary statistics
$total_tickets = count($tickets);
$total_print_qty = array_sum(array_column($tickets, 'print_qty'));
$total_page_qty = array_sum(array_column($tickets, 'page_qty'));

// Export to Excel if requested
if (isset($_GET['export']) && $_GET['export'] == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="job_ticket_report_' . date('Y-m-d') . '.xls"');
    
    echo "<table border='1'>";
    echo "<tr>
        <th>Job Ticket Code</th>
        <th>Book Code</th>
        <th>Book Name</th>
        <th>Lot</th>
        <th>Print Qty</th>
        <th>Page Qty</th>
        <th>Class</th>
        <th>Status</th>
        <th>Date (Nepali)</th>
        <th>Date (English)</th>
        <th>Fiscal Year</th>
        <th>Created By</th>
        <th>Created Date</th>
        <th>Updated By</th>
        <th>Updated Date</th>
        <th>Remarks</th>
    </tr>";
    
    foreach ($tickets as $ticket) {
        echo "<tr>";
        echo "<td>" . htmlspecialchars($ticket['job_ticket_code']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['book_code']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['book_name']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['lot']) . "</td>";
        echo "<td>" . number_format($ticket['print_qty']) . "</td>";
        echo "<td>" . number_format($ticket['page_qty']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['class']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['status']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['date_nep']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['date_eng']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['fiscal_code']) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['created_by_name']) . "</td>";
        echo "<td>" . date('Y-m-d H:i', strtotime($ticket['created_date'])) . "</td>";
        echo "<td>" . htmlspecialchars($ticket['updated_by_name'] ?? '-') . "</td>";
        echo "<td>" . ($ticket['updated_date'] ? date('Y-m-d H:i', strtotime($ticket['updated_date'])) : '-') . "</td>";
        echo "<td>" . htmlspecialchars($ticket['remarks']) . "</td>";
        echo "</tr>";
    }
    
    echo "</table>";
    exit();
}
?>

<style>
body {
    font-size: 16px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}
.filter-container {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
}
.filter-row {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
    flex-wrap: wrap;
}
.filter-group {
    flex: 1;
    min-width: 200px;
}
.filter-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
    font-size: 14px;
}
.form-control, .form-select {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 14px;
}
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    text-align: center;
    margin-right: 8px;
}
.btn-primary { background-color: #007bff; color: white; }
.btn-secondary { background-color: #6c757d; color: white; }
.btn-success { background-color: #28a745; color: white; }
.btn-info { background-color: #17a2b8; color: white; }
.btn-warning { background-color: #ffc107; color: #212529; }
.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.summary-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.summary-card h4 {
    margin: 0 0 10px 0;
    color: #666;
    font-size: 14px;
    text-transform: uppercase;
}
.summary-card .value {
    font-size: 32px;
    font-weight: bold;
    color: #333;
}
.table-container {
    background: white;
    border-radius: 8px;
    overflow: hidden;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table th, .table td {
    padding: 10px 8px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
    vertical-align: middle;
}
.table th {
    background-color: #f8f9fa;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.table tbody tr:hover { background-color: #f5f5f5; }
.badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}
.badge-warning { background-color: #ffc107; color: #212529; }
.badge-primary { background-color: #007bff; color: white; }
.badge-success { background-color: #28a745; color: white; }
.badge-danger { background-color: #dc3545; color: white; }
.action-buttons {
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    align-items: center;
}
</style>

<h2>Job Ticket Reports</h2>

<!-- Filter Form -->
<div class="filter-container">
    <form method="GET" id="filterForm">
        <h4>Search Filters</h4>
        <div class="filter-row">
            <div class="filter-group">
                <label for="book_code">Book Code</label>
                <input type="text" name="book_code" id="book_code" class="form-control" 
                       value="<?= htmlspecialchars($book_code) ?>" placeholder="Enter book code">
            </div>
            <div class="filter-group">
                <label for="book_id">Book</label>
                <select name="book_id" id="book_id" class="form-select">
                    <option value="">All Books</option>
                    <?php foreach ($books as $book): ?>
                        <option value="<?= $book['book_id'] ?>" <?= $book_id == $book['book_id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($book['book_code']) ?> - <?= htmlspecialchars($book['book_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="filter-group">
                <label for="fiscal_year_id">Fiscal Year</label>
                <select name="fiscal_year_id" id="fiscal_year_id" class="form-select">
                    <option value="">All Fiscal Years</option>
                    <?php foreach ($fiscal_years as $fy): ?>
                        <option value="<?= $fy['id'] ?>" <?= $fiscal_year_id == $fy['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($fy['fiscal_code']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="filter-row">
            <div class="filter-group">
                <label for="lot">Lot Number</label>
                <input type="text" name="lot" id="lot" class="form-control" 
                       value="<?= htmlspecialchars($lot) ?>" placeholder="Enter lot number">
            </div>
            <div class="filter-group">
                <label for="status">Status</label>
                <select name="status" id="status" class="form-select">
                    <option value="">All Statuses</option>
                    <option value="pending" <?= $status == 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="in_progress" <?= $status == 'in_progress' ? 'selected' : '' ?>>In Progress</option>
                    <option value="completed" <?= $status == 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $status == 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>
            <div class="filter-group">
                <label for="date_from">Date From</label>
                <input type="date" name="date_from" id="date_from" class="form-control" 
                       value="<?= htmlspecialchars($date_from) ?>">
            </div>
            <div class="filter-group">
                <label for="date_to">Date To</label>
                <input type="date" name="date_to" id="date_to" class="form-control" 
                       value="<?= htmlspecialchars($date_to) ?>">
            </div>
        </div>
        <div class="filter-row">
            <div class="filter-group">
                <button type="submit" class="btn btn-primary">Search</button>
                <a href="report.php" class="btn btn-secondary">Reset</a>
                <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-success">Export to Excel</a>
            </div>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="summary-cards">
    <div class="summary-card">
        <h4>Total Job Tickets</h4>
        <div class="value"><?= number_format($total_tickets) ?></div>
    </div>
    <div class="summary-card">
        <h4>Total Print Quantity</h4>
        <div class="value"><?= number_format($total_print_qty) ?></div>
    </div>
    <div class="summary-card">
        <h4>Total Pages</h4>
        <div class="value"><?= number_format($total_page_qty) ?></div>
    </div>
</div>

<!-- Results Table -->
<h3>Job Tickets (<?= number_format($total_tickets) ?> results)</h3>
<div class="table-container">
    <table class="table">
        <thead>
            <tr>
                <th>Code</th>
                <th>Book</th>
                <th>Lot</th>
                <th>Print Qty</th>
                <th>Page Qty</th>
                <th>Class</th>
                <th>Status</th>
                <th>Date (Nep)</th>
                <th>Date (Eng)</th>
                <th>Fiscal Year</th>
                <th>Created By</th>
                <th>Updated By</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($tickets)): ?>
                <tr>
                    <td colspan="13" style="text-align: center; padding: 30px;">
                        No job tickets found matching your criteria.
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($tickets as $ticket): ?>
                <tr>
                    <td><?= htmlspecialchars($ticket['job_ticket_code']) ?></td>
                    <td><?= htmlspecialchars($ticket['book_code']) ?><br>
                        <small style="color: #666;"><?= htmlspecialchars($ticket['book_name']) ?></small>
                    </td>
                    <td><?= htmlspecialchars($ticket['lot']) ?></td>
                    <td><?= number_format($ticket['print_qty']) ?></td>
                    <td><?= number_format($ticket['page_qty']) ?></td>
                    <td><?= htmlspecialchars($ticket['class']) ?></td>
                    <td>
                        <?php
                        $badgeClass = 'secondary';
                        switch ($ticket['status']) {
                            case 'pending': $badgeClass = 'warning'; break;
                            case 'in_progress': $badgeClass = 'primary'; break;
                            case 'completed': $badgeClass = 'success'; break;
                            case 'cancelled': $badgeClass = 'danger'; break;
                        }
                        ?>
                        <span class="badge badge-<?= $badgeClass ?>">
                            <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($ticket['date_nep']) ?></td>
                    <td><?= htmlspecialchars($ticket['date_eng']) ?></td>
                    <td><?= htmlspecialchars($ticket['fiscal_code']) ?></td>
                    <td>
                        <?= htmlspecialchars($ticket['created_by_name']) ?><br>
                        <small style="color: #666;"><?= date('Y-m-d', strtotime($ticket['created_date'])) ?></small>
                    </td>
                    <td>
                        <?= $ticket['updated_by_name'] ? htmlspecialchars($ticket['updated_by_name']) : '-' ?><br>
                        <?php if ($ticket['updated_date']): ?>
                            <small style="color: #666;"><?= date('Y-m-d', strtotime($ticket['updated_date'])) ?></small>
                        <?php endif; ?>
                    </td>
                    <td>
                        <a href="view.php?id=<?= $ticket['id'] ?>" class="btn btn-info btn-sm">View</a>
                        <a href="print.php?id=<?= $ticket['id'] ?>" class="btn btn-success btn-sm" target="_blank">Print</a>
                    </td>
                </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Auto-submit form when filters change
    const filterInputs = document.querySelectorAll('#filterForm select, #filterForm input[type="date"]');
    filterInputs.forEach(input => {
        input.addEventListener('change', function() {
            // Only auto-submit for dropdowns and date fields, not text inputs
            if (this.type !== 'text') {
                document.getElementById('filterForm').submit();
            }
        });
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
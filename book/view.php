<?php
// books/view.php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Get book ID from URL
$book_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$book_id) {
    $_SESSION['error'] = "Book ID not provided.";
    header("Location: index.php");
    exit();
}

// Fetch book details
$stmt = $conn->prepare("
    SELECT b.*,
           u1.username as created_by_name,
           t.title_code, t.title_name
    FROM books b
    LEFT JOIN users u1 ON b.created_by_id = u1.id
    LEFT JOIN book_titles t ON b.title_id = t.id
    WHERE b.book_id = ?
");
$stmt->execute([$book_id]);
$book = $stmt->fetch();

if (!$book) {
    $_SESSION['error'] = "Book not found.";
    header("Location: index.php");
    exit();
}

// Get related job tickets
$job_tickets_stmt = $conn->prepare("
    SELECT jt.id, jt.job_ticket_code, jt.lot, jt.print_qty, jt.status, 
           jt.created_date, fy.fiscal_code, fy.fiscal_name
    FROM job_ticket jt
    JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
    WHERE jt.book_id = ?
    ORDER BY jt.created_date DESC
    LIMIT 10
");
$job_tickets_stmt->execute([$book_id]);
$job_tickets = $job_tickets_stmt->fetchAll();

// Get formas related to this book
$formas_stmt = $conn->prepare("
    SELECT id, name, page, order_no
    FROM forma
    WHERE book_id = ?
    ORDER BY order_no ASC
");
$formas_stmt->execute([$book_id]);
$formas = $formas_stmt->fetchAll();

// Get statistics
$stats_stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_job_tickets,
        SUM(print_qty) as total_print_qty,
        SUM(print_done_qty) as total_done_qty
    FROM job_ticket
    WHERE book_id = ?
");
$stats_stmt->execute([$book_id]);
$stats = $stats_stmt->fetch();
?>

<style>
body {
    font-size: 14px;
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

.page-header .book-code {
    font-size: 1.1rem;
    opacity: 0.9;
    margin-top: 5px;
}

.action-bar {
    display: flex;
    gap: 10px;
    margin-top: 15px;
}

.info-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 4px 6px -1px rgba(0, 0, 0, 0.1);
    margin-bottom: 25px;
}

.info-card-title {
    font-size: 1.3rem;
    font-weight: 700;
    color: #343a40;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 3px solid #667eea;
    display: flex;
    align-items: center;
}

.info-card-title i {
    margin-right: 10px;
    color: #667eea;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.info-item {
    padding: 15px;
    background-color: #f8f9fa;
    border-radius: 8px;
    border-left: 4px solid #667eea;
}

.info-label {
    font-size: 0.9rem;
    color: #6c757d;
    font-weight: 600;
    margin-bottom: 5px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.info-value {
    font-size: 1.1rem;
    color: #343a40;
    font-weight: 600;
}

.badge {
    font-size: 0.85em;
    padding: 0.5em 0.8em;
    border-radius: 8px;
    font-weight: 600;
}

.badge-yes {
    background-color: #28a745;
    color: white;
}

.badge-no {
    background-color: #6c757d;
    color: white;
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.stat-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 25px;
    border-radius: 12px;
    text-align: center;
    box-shadow: 0 4px 6px rgba(0,0,0,0.1);
}

.stat-value {
    font-size: 2.5rem;
    font-weight: 700;
    margin-bottom: 5px;
}

.stat-label {
    font-size: 0.9rem;
    opacity: 0.9;
    text-transform: uppercase;
    letter-spacing: 1px;
}

.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
}

.table th, .table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
}

.table th {
    background: linear-gradient(135deg, #343a40 0%, #495057 100%);
    color: white;
    font-weight: 600;
    text-transform: uppercase;
    font-size: 0.85rem;
}

.table tbody tr:hover {
    background-color: rgba(102, 126, 234, 0.05);
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: all 0.2s ease;
}

.btn-primary {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
}

.btn-warning {
    background-color: #ffc107;
    color: #000;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 13px;
}

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.empty-state {
    text-align: center;
    padding: 60px 20px;
    color: #6c757d;
}

.empty-state i {
    font-size: 4rem;
    margin-bottom: 20px;
    opacity: 0.5;
}

.breadcrumb {
    background: transparent;
    margin: 0;
    padding: 0;
    margin-top: 10px;
}

.breadcrumb-item {
    color: rgba(255,255,255,0.8);
}

.breadcrumb-item.active {
    color: white;
}

.breadcrumb-item a {
    color: white;
    text-decoration: none;
}

@media (max-width: 768px) {
    .info-grid {
        grid-template-columns: 1fr;
    }
    
    .stats-grid {
        grid-template-columns: 1fr;
    }
    
    .action-bar {
        flex-direction: column;
    }
}
</style>

<div class="container">
    <!-- Page Header -->
    <div class="page-header">
        <h2>
            <i class="fas fa-book me-2"></i>
            <?= htmlspecialchars($book['book_name']) ?>
        </h2>
        <div class="book-code">
            <i class="fas fa-barcode me-2"></i>
            Code: <?= htmlspecialchars($book['book_code']) ?>
        </div>
        <nav aria-label="breadcrumb">
            <ol class="breadcrumb">
                <li class="breadcrumb-item"><a href="/deno2/">Home</a></li>
                <li class="breadcrumb-item"><a href="index.php">Books</a></li>
                <li class="breadcrumb-item active">View Details</li>
            </ol>
        </nav>
        <div class="action-bar">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left me-2"></i>Back to List
            </a>
            <?php if (has_role('editor') || has_role('admin')): ?>
                <a href="edit.php?id=<?= $book['book_id'] ?>" class="btn btn-warning">
                    <i class="fas fa-edit me-2"></i>Edit Book
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_job_tickets'] ?? 0) ?></div>
            <div class="stat-label">Total Job Tickets</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_print_qty'] ?? 0) ?></div>
            <div class="stat-label">Total Print Quantity</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= number_format($stats['total_done_qty'] ?? 0) ?></div>
            <div class="stat-label">Completed Quantity</div>
        </div>
    </div>

    <!-- Book Information -->
    <div class="info-card">
        <h3 class="info-card-title">
            <i class="fas fa-info-circle"></i>
            Book Information
        </h3>
        <div class="info-grid">
            <div class="info-item">
                <div class="info-label">Book Code</div>
                <div class="info-value"><?= htmlspecialchars($book['book_code']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Book Name</div>
                <div class="info-value"><?= htmlspecialchars($book['book_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Class Level</div>
                <div class="info-value">
                    <?= $book['class_level'] ? 'Class ' . $book['class_level'] : '<span class="text-muted">N/A</span>' ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Fiscal Year</div>
                <div class="info-value">
                    <?= $book['fiscal_year'] ? htmlspecialchars($book['fiscal_year']) : '<span class="text-muted">N/A</span>' ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Page Count</div>
                <div class="info-value">
                    <?= $book['page_count'] !== null ? (int)$book['page_count'] : '<span class="text-muted">N/A</span>' ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Edition Status</div>
                <div class="info-value">
                    <?php if ($book['is_active']): ?>
                        <span class="badge badge-yes"><i class="fas fa-check me-1"></i>Active</span>
                    <?php else: ?>
                        <span class="badge badge-no"><i class="fas fa-ban me-1"></i>Obsolete</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Title (Cross-Year Identity)</div>
                <div class="info-value">
                    <?php if ($book['title_id']): ?>
                        <code><?= htmlspecialchars($book['title_code']) ?></code> — <?= htmlspecialchars($book['title_name']) ?>
                        &nbsp;<a href="title_report.php?title_id=<?= (int)$book['title_id'] ?>" class="small">View all editions →</a>
                    <?php else: ?>
                        <span class="text-muted">Not linked to a title</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Is Translated</div>
                <div class="info-value">
                    <?php if ($book['is_translated']): ?>
                        <span class="badge badge-yes"><i class="fas fa-check me-1"></i>Yes</span>
                    <?php else: ?>
                        <span class="badge badge-no"><i class="fas fa-times me-1"></i>No</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Is Optional</div>
                <div class="info-value">
                    <?php if ($book['is_optional']): ?>
                        <span class="badge badge-yes"><i class="fas fa-check me-1"></i>Yes</span>
                    <?php else: ?>
                        <span class="badge badge-no"><i class="fas fa-times me-1"></i>No</span>
                    <?php endif; ?>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Created By</div>
                <div class="info-value"><?= htmlspecialchars($book['created_by_name'] ?? 'N/A') ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Created Date</div>
                <div class="info-value"><?= date('M d, Y', strtotime($book['created_at'])) ?></div>
            </div>
            <?php if ($book['updated_at'] && $book['updated_at'] != $book['created_at']): ?>
            <div class="info-item">
                <div class="info-label">Last Updated</div>
                <div class="info-value"><?= date('M d, Y', strtotime($book['updated_at'])) ?></div>
            </div>
            <?php endif; ?>
        </div>
    </div>

    <!-- Formas Section -->
    <?php if (!empty($formas)): ?>
    <div class="info-card">
        <h3 class="info-card-title">
            <i class="fas fa-layer-group"></i>
            Formas (<?= count($formas) ?>)
        </h3>
        <div class="table-responsive">
            <table class="table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Forma Name</th>
                        <th>Pages</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($formas as $forma): ?>
                    <tr>
                        <td><?= $forma['order_no'] ?></td>
                        <td><?= htmlspecialchars($forma['name']) ?></td>
                        <td><?= $forma['page'] ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <?php endif; ?>

    <!-- Recent Job Tickets -->
    <div class="info-card">
        <h3 class="info-card-title">
            <i class="fas fa-tasks"></i>
            Recent Job Tickets
        </h3>
        <?php if (empty($job_tickets)): ?>
            <div class="empty-state">
                <i class="fas fa-inbox"></i>
                <p>No job tickets found for this book.</p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th>Job Code</th>
                            <th>Fiscal Year</th>
                            <th>Lot</th>
                            <th>Print Qty</th>
                            <th>Status</th>
                            <th>Created Date</th>
                            <th>Action</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($job_tickets as $ticket): ?>
                        <tr>
                            <td><strong><?= htmlspecialchars($ticket['job_ticket_code']) ?></strong></td>
                            <td><?= htmlspecialchars($ticket['fiscal_name'] ?? $ticket['fiscal_code']) ?></td>
                            <td><?= htmlspecialchars($ticket['lot']) ?></td>
                            <td><?= number_format($ticket['print_qty']) ?></td>
                            <td>
                                <span class="badge" style="background-color: <?= 
                                    $ticket['status'] === 'completed' ? '#28a745' : 
                                    ($ticket['status'] === 'in_progress' ? '#17a2b8' : '#ffc107') 
                                ?>;">
                                    <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                                </span>
                            </td>
                            <td><?= date('M d, Y', strtotime($ticket['created_date'])) ?></td>
                            <td>
                                <a href="../jobticket/view.php?id=<?= $ticket['id'] ?>" class="btn btn-primary btn-sm">
                                    <i class="fas fa-eye"></i> View
                                </a>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if (count($job_tickets) >= 10): ?>
                <div style="text-align: center; margin-top: 15px;">
                    <a href="../jobticket/index.php?book_code=<?= urlencode($book['book_code']) ?>" class="btn btn-secondary">
                        <i class="fas fa-list me-2"></i>View All Job Tickets
                    </a>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

<?php
// books/index.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';

// Filter parameters
$book_code_filter     = isset($_GET['book_code'])     ? trim($_GET['book_code'])     : '';
$class_filter         = isset($_GET['class'])         ? trim($_GET['class'])         : '';
$fiscal_year_filter   = isset($_GET['fiscal_year'])   ? trim($_GET['fiscal_year'])   : '';
$is_translated_filter = isset($_GET['is_translated']) ? $_GET['is_translated']       : '';
$is_optional_filter   = isset($_GET['is_optional'])   ? $_GET['is_optional']         : '';
$search_text          = isset($_GET['search'])        ? trim($_GET['search'])        : '';
$business_filter      = isset($_GET['business'])      ? trim($_GET['business'])      : '';
$book_type_filter     = isset($_GET['book_type'])     ? trim($_GET['book_type'])     : '';

// Build WHERE clause
$where_conditions = [];
$filter_params    = [];

if (!empty($book_code_filter)) {
    $where_conditions[] = "book_code ILIKE ?";
    $filter_params[] = "%$book_code_filter%";
}
if (!empty($class_filter)) {
    $where_conditions[] = "class_level = ?";
    $filter_params[] = $class_filter;
}
if (!empty($fiscal_year_filter)) {
    $where_conditions[] = "fiscal_year ILIKE ?";
    $filter_params[] = "%$fiscal_year_filter%";
}
if ($is_translated_filter !== '') {
    $where_conditions[] = "is_translated = ?";
    $filter_params[] = $is_translated_filter === '1' ? 't' : 'f';
}
if (!empty($business_filter)) {
    $where_conditions[] = "business_associated = ?";
    $filter_params[] = $business_filter;
}
if (!empty($book_type_filter)) {
    $where_conditions[] = "book_type = ?";
    $filter_params[] = $book_type_filter;
}
if ($is_optional_filter !== '') {
    $where_conditions[] = "is_optional = ?";
    $filter_params[] = $is_optional_filter === '1' ? 't' : 'f';
}
if (!empty($search_text)) {
    $where_conditions[] = "(book_code ILIKE ? OR book_name ILIKE ?)";
    $filter_params[] = "%$search_text%";
    $filter_params[] = "%$search_text%";
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// ── Export handler — must run BEFORE any HTML output ──────────────────────────
if (isset($_GET['export'])) {
    $export_query = "
        SELECT b.book_code, b.book_name, b.class_level, b.fiscal_year,
               b.business_associated, b.book_type,
               b.is_translated, b.is_optional, b.created_at,
               u1.username as created_by_name
        FROM books b
        LEFT JOIN users u1 ON b.created_by = u1.username
        ORDER BY b.created_at DESC
    ";
    // No WHERE clause — export ALL books in the database
    $export_stmt = $conn->prepare($export_query);
    $export_stmt->execute();
    $export_data = $export_stmt->fetchAll();

    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="books_' . date('Y-m-d_H-i-s') . '.xls"');
        header('Cache-Control: max-age=0');

        echo "\xEF\xBB\xBF"; // UTF-8 BOM so Excel opens Nepali text correctly
        echo "<table border='1'>";
        echo "<tr>
            <th>Book Code</th>
            <th>Book Name</th>
            <th>Class Level</th>
            <th>Fiscal Year</th>
            <th>Business</th>
            <th>Book Type</th>
            <th>Is Translated</th>
            <th>Is Optional</th>
            <th>Created By</th>
            <th>Created Date</th>
        </tr>";

        foreach ($export_data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['book_code'])           . "</td>";
            echo "<td>" . htmlspecialchars($row['book_name'])           . "</td>";
            echo "<td>" . htmlspecialchars($row['class_level'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['fiscal_year'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['business_associated'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['book_type'] ?? 'N/A')  . "</td>";
            echo "<td>" . ($row['is_translated'] ? 'Yes' : 'No')       . "</td>";
            echo "<td>" . ($row['is_optional']   ? 'Yes' : 'No')       . "</td>";
            echo "<td>" . htmlspecialchars($row['created_by_name'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at'])          . "</td>";
            echo "</tr>";
        }
        echo "</table>";
        exit;
    }

    if ($_GET['export'] === 'pdf') {
        header('Content-Type: text/html; charset=UTF-8');
        $total_export = count($export_data);
        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <title>Books Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; font-size: 11px; }
            h2 { text-align: center; margin-bottom: 4px; }
            .meta { text-align: center; color: #555; margin-bottom: 14px; font-size: 10px; }
            table { width: 100%; border-collapse: collapse; }
            th, td { border: 1px solid #333; padding: 5px 7px; text-align: left; }
            th { background: #e8e8e8; font-weight: bold; font-size: 10px; text-transform: uppercase; }
            tr:nth-child(even) { background: #f9f9f9; }
            @page { margin: 12mm; size: A4 landscape; }
            @media print { button { display: none !important; } }
        </style>
        </head><body>
        <button onclick="window.print()" style="margin-bottom:12px;padding:8px 20px;background:#0d6efd;color:#fff;border:none;border-radius:5px;cursor:pointer;font-size:13px;">
            Print / Save as PDF
        </button>
        <h2>Books Report</h2>
        <div class="meta">Generated: ' . date('Y-m-d H:i:s') . ' &nbsp;|&nbsp; Total Records: ' . $total_export . '</div>
        <table>
        <tr>
            <th>#</th><th>Book Code</th><th>Book Name</th><th>Class</th>
            <th>Fiscal Year</th><th>Business</th><th>Type</th>
            <th>Translated</th><th>Optional</th><th>Created By</th><th>Created Date</th>
        </tr>';
        $n = 1;
        foreach ($export_data as $row) {
            echo '<tr>';
            echo '<td>' . $n++ . '</td>';
            echo '<td>' . htmlspecialchars($row['book_code']) . '</td>';
            echo '<td>' . htmlspecialchars($row['book_name']) . '</td>';
            echo '<td>' . htmlspecialchars($row['class_level'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($row['fiscal_year'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($row['business_associated'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($row['book_type'] ?? 'N/A') . '</td>';
            echo '<td>' . ($row['is_translated'] ? 'Yes' : 'No') . '</td>';
            echo '<td>' . ($row['is_optional']   ? 'Yes' : 'No') . '</td>';
            echo '<td>' . htmlspecialchars($row['created_by_name'] ?? 'N/A') . '</td>';
            echo '<td>' . htmlspecialchars($row['created_at']) . '</td>';
            echo '</tr>';
        }
        echo '</table></body></html>';
        exit;
    }
}

// ── Normal page load ───────────────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Pagination
$items_per_page = 50;
$current_page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset         = ($current_page - 1) * $items_per_page;

// Total count
$count_query = "SELECT COUNT(*) as total FROM books $where_clause";
$count_stmt  = $conn->prepare($count_query);
$count_stmt->execute($filter_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages   = max(1, ceil($total_records / $items_per_page));
// Clamp current page
if ($current_page > $total_pages) { $current_page = $total_pages; $offset = ($current_page - 1) * $items_per_page; }

// Paged query
$page_params   = array_merge($filter_params, [$items_per_page, $offset]);
$books_query   = "
    SELECT b.*, u1.username as created_by_name
    FROM books b
    LEFT JOIN users u1 ON b.created_by = u1.username
    $where_clause
    ORDER BY b.created_at DESC
    LIMIT ? OFFSET ?
";
$books_stmt = $conn->prepare($books_query);
$books_stmt->execute($page_params);
$books = $books_stmt->fetchAll();

// Filter drop-down data
$class_levels = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL ORDER BY class_level")->fetchAll();
$fiscal_years = $conn->query("SELECT DISTINCT fiscal_year FROM books WHERE fiscal_year IS NOT NULL ORDER BY fiscal_year DESC")->fetchAll();

// Handle delete
if (isset($_POST['delete_id']) && (has_role('admin') || has_role('editor'))) {
    $delete_id = (int)$_POST['delete_id'];
    try {
        $conn->beginTransaction();
        $check_stmt = $conn->prepare("SELECT COUNT(*) as count FROM job_ticket WHERE book_id = ?");
        $check_stmt->execute([$delete_id]);
        $usage_count = $check_stmt->fetch()['count'];
        if ($usage_count > 0) {
            throw new Exception("Cannot delete book. It is used in $usage_count job ticket(s).");
        }
        $stmt = $conn->prepare("DELETE FROM books WHERE book_id = ?");
        $stmt->execute([$delete_id]);
        $conn->commit();
        $_SESSION['success'] = 'Book deleted successfully.';
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = 'Error deleting book: ' . $e->getMessage();
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . '?' . http_build_query($_GET));
    exit;
}

// Build base URL for pagination (strip page param)
$query_no_page = $_GET;
unset($query_no_page['page']);
$base_url = '?' . http_build_query($query_no_page) . '&page=';
?>

<div class="container">
    <!-- Header -->
    <div class="row mb-4">
        <div class="col-md-8">
            <h2 class="text-primary fw-bold mb-1">
                <i class="fas fa-book me-2"></i>Books Management
            </h2>
        </div>
        <div class="col-md-4 text-md-end">
            <?php if (has_role('editor') || has_role('admin')): ?>
                <a href="create.php" class="btn btn-primary btn-lg shadow">
                    <i class="fas fa-plus-circle me-2"></i>Add New Book
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

    <!-- Search & Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light">
            <div class="d-flex justify-content-between align-items-center">
                <h5 class="mb-0 text-dark"><i class="fas fa-filter me-2"></i>Search & Filters</h5>
                <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">
                    <i class="fas fa-sync me-1"></i>Reset
                </button>
            </div>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold">Search</label>
                        <input type="text" class="form-control" name="search" value="<?= htmlspecialchars($search_text) ?>" placeholder="Book code or name...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Book Code</label>
                        <input type="text" class="form-control" name="book_code" value="<?= htmlspecialchars($book_code_filter) ?>" placeholder="e.g., ENG-09">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Class Level</label>
                        <select class="form-select" name="class">
                            <option value="">All Classes</option>
                            <?php foreach ($class_levels as $class): ?>
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
                                <option value="<?= htmlspecialchars($fy['fiscal_year']) ?>" <?= $fiscal_year_filter === $fy['fiscal_year'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fy['fiscal_year']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Business</label>
                        <select class="form-select" name="business">
                            <option value="">All Business</option>
                            <option value="CDC"  <?= $business_filter === 'CDC'  ? 'selected' : '' ?>>CDC</option>
                            <option value="JEMC" <?= $business_filter === 'JEMC' ? 'selected' : '' ?>>JEMC</option>
                            <option value="NTC"  <?= $business_filter === 'NTC'  ? 'selected' : '' ?>>NTC</option>
                            <option value="NEB"  <?= $business_filter === 'NEB'  ? 'selected' : '' ?>>NEB</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold">Book Type</label>
                        <select class="form-select" name="book_type">
                            <option value="">All Types</option>
                            <option value="TextBook"      <?= $book_type_filter === 'TextBook'      ? 'selected' : '' ?>>Text Book</option>
                            <option value="Copy"          <?= $book_type_filter === 'Copy'          ? 'selected' : '' ?>>Copy</option>
                            <option value="RechargeCard"  <?= $book_type_filter === 'RechargeCard'  ? 'selected' : '' ?>>Recharge Card</option>
                            <option value="Lalpurja"      <?= $book_type_filter === 'Lalpurja'      ? 'selected' : '' ?>>Lalpurja</option>
                            <option value="QuestionPaper" <?= $book_type_filter === 'QuestionPaper' ? 'selected' : '' ?>>Question Paper</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Translated</label>
                        <select class="form-select" name="is_translated">
                            <option value="">All</option>
                            <option value="1" <?= $is_translated_filter === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= $is_translated_filter === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold">Optional</label>
                        <select class="form-select" name="is_optional">
                            <option value="">All</option>
                            <option value="1" <?= $is_optional_filter === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= $is_optional_filter === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
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
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <h6 class="mb-0 text-muted">
                    <i class="fas fa-download me-2"></i>Download All Books
                    <small class="text-info ms-2">(exports entire database — <?= number_format($total_records) ?> filtered / all records)</small>
                </h6>
                <div class="btn-group">
                    <a href="?export=excel" class="btn btn-success btn-sm" target="_blank">
                        <i class="fas fa-file-excel me-1"></i>Download Excel
                    </a>
                    <a href="?export=pdf" class="btn btn-danger btn-sm" target="_blank">
                        <i class="fas fa-file-pdf me-1"></i>Download PDF
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
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center flex-wrap gap-2">
            <h5 class="mb-0">
                <i class="fas fa-list me-2"></i>Books List
                <span class="badge bg-light text-primary ms-2"><?= number_format($total_records) ?></span>
            </h5>
            <small>
                Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $items_per_page, $total_records)) ?>
                of <?= number_format($total_records) ?> records
                &nbsp;|&nbsp; Page <?= $current_page ?> of <?= $total_pages ?>
            </small>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0" id="booksTable">
                <thead class="table-dark">
                    <tr>
                        <th>#</th>
                        <th>Book Code</th>
                        <th>Book Name</th>
                        <th>Class Level</th>
                        <th>Fiscal Year</th>
                        <th>Business</th>
                        <th>Type</th>
                        <th>Translated</th>
                        <th>Optional</th>
                        <th>Created By</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($books)): ?>
                        <tr>
                            <td colspan="12" class="text-center py-5">
                                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                                <p class="text-muted mb-0">No books found matching your criteria.</p>
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($books as $index => $book): ?>
                            <tr>
                                <td class="text-muted small"><?= $offset + $index + 1 ?></td>
                                <td><code class="bg-light px-2 py-1 rounded"><?= htmlspecialchars($book['book_code']) ?></code></td>
                                <td class="text-truncate" style="max-width: 250px;" title="<?= htmlspecialchars($book['book_name']) ?>">
                                    <strong><?= htmlspecialchars($book['book_name']) ?></strong>
                                </td>
                                <td>
                                    <?php if ($book['class_level']): ?>
                                        <span class="badge bg-secondary">Class <?= $book['class_level'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">N/A</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($book['fiscal_year'] ?: 'N/A') ?></td>
                                <td><span class="badge bg-info"><?= htmlspecialchars($book['business_associated'] ?? 'CDC') ?></span></td>
                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($book['book_type'] ?? 'TextBook') ?></span></td>
                                <td>
                                    <?php if ($book['is_translated']): ?>
                                        <span class="badge bg-success"><i class="fas fa-check"></i> Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="fas fa-times"></i> No</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($book['is_optional']): ?>
                                        <span class="badge bg-info"><i class="fas fa-check"></i> Yes</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary"><i class="fas fa-times"></i> No</span>
                                    <?php endif; ?>
                                </td>
                                <td><?= htmlspecialchars($book['created_by_name'] ?? 'N/A') ?></td>
                                <td><?= date('M d, Y', strtotime($book['created_at'])) ?></td>
                                <td>
                                    <a href="view.php?id=<?= $book['book_id'] ?>" class="btn btn-sm btn-outline-info" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (has_role('editor') || has_role('admin')): ?>
                                        <a href="edit.php?id=<?= $book['book_id'] ?>" class="btn btn-sm btn-outline-warning" title="Edit">
                                            <i class="fas fa-edit"></i>
                                        </a>
                                    <?php endif; ?>
                                    <?php if (has_role('admin')): ?>
                                        <button onclick="confirmDelete(<?= $book['book_id'] ?>, '<?= htmlspecialchars($book['book_code']) ?>')"
                                                class="btn btn-sm btn-outline-danger" title="Delete">
                                            <i class="fas fa-trash"></i>
                                        </button>
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
                <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                    <small class="text-muted">
                        Page <strong><?= $current_page ?></strong> of <strong><?= $total_pages ?></strong>
                        &nbsp;&mdash;&nbsp; <?= number_format($total_records) ?> total records
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm justify-content-center mb-0">

                            <!-- First -->
                            <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url ?>1" title="First page">
                                    <i class="fas fa-angle-double-left"></i>
                                </a>
                            </li>

                            <!-- Prev -->
                            <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url . ($current_page - 1) ?>">
                                    <i class="fas fa-chevron-left"></i> Prev
                                </a>
                            </li>

                            <?php
                            // Show up to 7 page links centred on the current page
                            $window = 3;
                            $page_start = max(1, $current_page - $window);
                            $page_end   = min($total_pages, $current_page + $window);

                            // Leading ellipsis
                            if ($page_start > 2): ?>
                                <li class="page-item"><a class="page-link" href="<?= $base_url ?>1">1</a></li>
                                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                            <?php elseif ($page_start == 2): ?>
                                <li class="page-item"><a class="page-link" href="<?= $base_url ?>1">1</a></li>
                            <?php endif; ?>

                            <?php for ($i = $page_start; $i <= $page_end; $i++): ?>
                                <li class="page-item <?= $i == $current_page ? 'active' : '' ?>">
                                    <a class="page-link" href="<?= $base_url . $i ?>"><?= $i ?></a>
                                </li>
                            <?php endfor; ?>

                            <!-- Trailing ellipsis -->
                            <?php if ($page_end < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <li class="page-item"><a class="page-link" href="<?= $base_url . $total_pages ?>"><?= $total_pages ?></a></li>
                            <?php elseif ($page_end == $total_pages - 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= $base_url . $total_pages ?>"><?= $total_pages ?></a></li>
                            <?php endif; ?>

                            <!-- Next -->
                            <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url . ($current_page + 1) ?>">
                                    Next <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>

                            <!-- Last -->
                            <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url . $total_pages ?>" title="Last page">
                                    <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        </ul>
                    </nav>

                    <!-- Jump to page -->
                    <form method="GET" class="d-flex align-items-center gap-2" id="jumpForm">
                        <?php foreach ($query_no_page as $k => $v): ?>
                            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                        <?php endforeach; ?>
                        <label class="text-muted small mb-0">Go to:</label>
                        <input type="number" name="page" min="1" max="<?= $total_pages ?>"
                               class="form-control form-control-sm" style="width:70px;"
                               placeholder="<?= $current_page ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Go</button>
                    </form>
                </div>
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
                <h5>Permanently delete this book?</h5>
                <p class="text-muted">Book Code: <strong id="deleteBookCode"></strong></p>
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

<style>
body {
    font-size: 14px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    margin: 0;
    padding: 20px;
    background-color: #f8f9fa;
}
.container { max-width: 1400px; margin: 0 auto; background: white; padding: 20px; border-radius: 8px; }
.card { border: none; border-radius: 12px; box-shadow: 0 4px 6px -1px rgba(0,0,0,.1), 0 2px 4px -1px rgba(0,0,0,.06); transition: all .3s ease; }
.card:hover { box-shadow: 0 10px 15px -3px rgba(0,0,0,.1), 0 4px 6px -2px rgba(0,0,0,.05); }
.card-header { border-radius: 12px 12px 0 0 !important; border-bottom: 1px solid rgba(255,255,255,.1); }
.table th { background: linear-gradient(135deg,#343a40 0%,#495057 100%); color: white !important; font-weight: 600; border: none !important; text-transform: uppercase; font-size: .85rem; letter-spacing: .5px; }
.table tbody tr { transition: all .2s ease; }
.table tbody tr:hover { background-color: rgba(0,123,255,.05); transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,.1); }
.badge { font-size: .75em; padding: .5em .8em; border-radius: 8px; font-weight: 600; text-transform: uppercase; letter-spacing: .5px; }
.btn { border-radius: 8px; font-weight: 500; transition: all .2s ease; border: 2px solid transparent; }
.btn:hover { transform: translateY(-1px); box-shadow: 0 4px 8px rgba(0,0,0,.15); }
.btn-outline-info:hover { background-color: #17a2b8; border-color: #17a2b8; color: white; }
.btn-outline-warning:hover { background-color: #ffc107; border-color: #ffc107; color: #000; }
.btn-outline-danger:hover { background-color: #dc3545; border-color: #dc3545; color: white; }
.form-control, .form-select { border-radius: 8px; border: 2px solid #e9ecef; transition: all .2s ease; }
.form-control:focus, .form-select:focus { border-color: #0d6efd; box-shadow: 0 0 0 .2rem rgba(13,110,253,.15); transform: translateY(-1px); }
.alert { border-radius: 10px; border: none; box-shadow: 0 2px 4px rgba(0,0,0,.1); }
.table-responsive { border-radius: 12px; overflow: hidden; }
.pagination .page-link { border-radius: 8px; margin: 0 2px; border: 2px solid transparent; transition: all .2s ease; }
.pagination .page-item.active .page-link { background: linear-gradient(135deg,#0d6efd 0%,#0056b3 100%); border-color: #0d6efd; box-shadow: 0 2px 4px rgba(13,110,253,.3); }
.pagination .page-link:hover { transform: translateY(-1px); box-shadow: 0 2px 4px rgba(0,0,0,.1); }
.modal-content { border-radius: 15px; border: none; box-shadow: 0 25px 50px -12px rgba(0,0,0,.25); }
.modal-header { border-radius: 15px 15px 0 0; }
code { background-color: #f8f9fa !important; color: #e83e8c !important; padding: .2rem .4rem !important; border-radius: 4px !important; font-size: .875em !important; }
.fw-semibold { font-weight: 600; }

@media print {
    .no-print, .btn, .card-header, .card-footer, .alert, .modal { display: none !important; }
    .card { border: none !important; box-shadow: none !important; }
    .table { font-size: 10px !important; }
}
@media (max-width: 768px) {
    .table th, .table td { font-size: .8rem; padding: .5rem .25rem; }
}
</style>

<script>
function resetFilters() {
    window.location.href = window.location.pathname;
}

function printTable() {
    const printWindow = window.open('', '_blank');
    const table = document.getElementById('booksTable').cloneNode(true);
    table.querySelectorAll('th:last-child, td:last-child').forEach(cell => cell.remove());
    printWindow.document.write(`<!DOCTYPE html><html><head><title>Books Report</title>
        <style>
            body { font-family: Arial, sans-serif; margin: 20px; }
            table { width: 100%; border-collapse: collapse; margin-top: 20px; }
            th, td { border: 1px solid #000; padding: 6px 8px; text-align: left; font-size: 11px; }
            th { background-color: #f0f0f0; font-weight: bold; }
            .header { text-align: center; margin-bottom: 20px; }
            @page { margin: 15mm; }
        </style></head><body>
        <div class="header">
            <h2>Books Report</h2>
            <p>Generated: ${new Date().toLocaleDateString()}</p>
            <p>Total Shown: <?= number_format($total_records) ?></p>
        </div>
        ${table.outerHTML}
    </body></html>`);
    printWindow.document.close();
    printWindow.print();
}

function confirmDelete(id, bookCode) {
    document.getElementById('deleteId').value = id;
    document.getElementById('deleteBookCode').textContent = bookCode;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

document.addEventListener('DOMContentLoaded', function () {
    document.querySelectorAll('#filterForm select').forEach(select => {
        select.addEventListener('change', () => document.getElementById('filterForm').submit());
    });

    let searchTimeout;
    const searchInput = document.querySelector('input[name="search"]');
    if (searchInput) {
        searchInput.addEventListener('input', function () {
            clearTimeout(searchTimeout);
            searchTimeout = setTimeout(() => document.getElementById('filterForm').submit(), 1000);
        });
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

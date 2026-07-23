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
// Default view hides obsolete/superseded editions ("Active" only) — they're
// never deleted, still fully visible in every report, just out of the way
// of the day-to-day list. "status=all"/"inactive" override this.
$status_filter        = isset($_GET['status'])        ? trim($_GET['status'])        : 'active';

// Build WHERE clause
$where_conditions = [];
$filter_params    = [];

if ($status_filter === 'active') {
    $where_conditions[] = "b.is_active = true";
} elseif ($status_filter === 'inactive') {
    $where_conditions[] = "b.is_active = false";
}
// $status_filter === 'all' -> no filter

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
        LEFT JOIN users u1 ON b.created_by_id = u1.id
        ORDER BY b.created_at DESC
    ";
    $export_stmt = $conn->prepare($export_query);
    $export_stmt->execute();
    $export_data = $export_stmt->fetchAll();

    if ($_GET['export'] === 'excel') {
        header('Content-Type: application/vnd.ms-excel; charset=UTF-8');
        header('Content-Disposition: attachment; filename="books_' . date('Y-m-d_H-i-s') . '.xls"');
        header('Cache-Control: max-age=0');
        echo "\xEF\xBB\xBF";
        echo "<table border='1'>";
        echo "<tr>
            <th>Book Code</th><th>Book Name</th><th>Class Level</th>
            <th>Fiscal Year</th><th>Business</th><th>Book Type</th>
            <th>Is Translated</th><th>Is Optional</th><th>Created By</th><th>Created Date</th>
        </tr>";
        foreach ($export_data as $row) {
            echo "<tr>";
            echo "<td>" . htmlspecialchars($row['book_code'])                    . "</td>";
            echo "<td>" . htmlspecialchars($row['book_name'])                    . "</td>";
            echo "<td>" . htmlspecialchars($row['class_level'] ?? 'N/A')         . "</td>";
            echo "<td>" . htmlspecialchars($row['fiscal_year'] ?? 'N/A')         . "</td>";
            echo "<td>" . htmlspecialchars($row['business_associated'] ?? 'N/A') . "</td>";
            echo "<td>" . htmlspecialchars($row['book_type'] ?? 'N/A')           . "</td>";
            echo "<td>" . ($row['is_translated'] ? 'Yes' : 'No')                . "</td>";
            echo "<td>" . ($row['is_optional']   ? 'Yes' : 'No')                . "</td>";
            echo "<td>" . htmlspecialchars($row['created_by_name'] ?? 'N/A')    . "</td>";
            echo "<td>" . htmlspecialchars($row['created_at'])                   . "</td>";
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
            body{font-family:Arial,sans-serif;margin:20px;font-size:11px;}
            h2{text-align:center;margin-bottom:4px;}
            .meta{text-align:center;color:#555;margin-bottom:14px;font-size:10px;}
            table{width:100%;border-collapse:collapse;}
            th,td{border:1px solid #333;padding:5px 7px;text-align:left;}
            th{background:#e8e8e8;font-weight:bold;font-size:10px;text-transform:uppercase;}
            tr:nth-child(even){background:#f9f9f9;}
            @page{margin:12mm;size:A4 landscape;}
            @media print{button{display:none!important;}}
        </style></head><body>
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

// ── Handle delete (POST) — must run BEFORE any HTML output (uses header() redirect) ──
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

// ── Normal page load ───────────────────────────────────────────────────────────
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Pagination
$items_per_page = 50;
$current_page   = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$offset         = ($current_page - 1) * $items_per_page;

// Total count
$count_query = "SELECT COUNT(*) as total FROM books b $where_clause";
$count_stmt  = $conn->prepare($count_query);
$count_stmt->execute($filter_params);
$total_records = $count_stmt->fetch()['total'];
$total_pages   = max(1, ceil($total_records / $items_per_page));
if ($current_page > $total_pages) { $current_page = $total_pages; $offset = ($current_page - 1) * $items_per_page; }

// Paged query
$page_params = array_merge($filter_params, [$items_per_page, $offset]);
$books_query = "
    SELECT b.*, u1.username as created_by_name,
           t.title_code, t.title_name
    FROM books b
    LEFT JOIN users u1 ON b.created_by_id = u1.id
    LEFT JOIN book_titles t ON b.title_id = t.id
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

// Build base URL for pagination
$query_no_page = $_GET;
unset($query_no_page['page']);
$base_url = '?' . http_build_query($query_no_page) . '&page=';
?>

<div class="container-fluid px-4 py-3">

    <!-- Page Header -->
    <div class="d-flex justify-content-between align-items-center mb-4">
        <div>
            <h2 class="fw-bold text-primary mb-1">
                <i class="fas fa-book me-2"></i>Books Management
            </h2>
            <nav aria-label="breadcrumb">
                <ol class="breadcrumb mb-0 small">
                    <li class="breadcrumb-item"><a href="/deno2/">Home</a></li>
                    <li class="breadcrumb-item active">Books</li>
                </ol>
            </nav>
        </div>
        <div class="d-flex gap-2">
            <a href="title_report.php" class="btn btn-outline-primary px-4">
                <i class="fas fa-chart-bar me-2"></i>Lifetime by Title
            </a>
            <?php if (has_role('editor') || has_role('admin')): ?>
                <a href="create.php" class="btn btn-primary px-4">
                    <i class="fas fa-plus-circle me-2"></i>Add New Book
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Alerts -->
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success alert-dismissible fade show shadow-sm">
            <i class="fas fa-check-circle me-2"></i><?= htmlspecialchars($_SESSION['success']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger alert-dismissible fade show shadow-sm">
            <i class="fas fa-exclamation-triangle me-2"></i><?= htmlspecialchars($_SESSION['error']) ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <!-- Search & Filters -->
    <div class="card shadow-sm mb-4">
        <div class="card-header bg-light d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0 fw-semibold"><i class="fas fa-filter me-2 text-primary"></i>Search & Filters</h6>
            <button class="btn btn-outline-secondary btn-sm" onclick="resetFilters()">
                <i class="fas fa-sync me-1"></i>Reset
            </button>
        </div>
        <div class="card-body">
            <form method="GET" id="filterForm">
                <div class="row g-3">
                    <div class="col-md-3">
                        <label class="form-label fw-semibold small">Search</label>
                        <input type="text" class="form-control form-control-sm" name="search"
                               value="<?= htmlspecialchars($search_text) ?>" placeholder="Book code or name...">
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small">Book Code</label>
                        <input type="text" class="form-control form-control-sm" name="book_code"
                               value="<?= htmlspecialchars($book_code_filter) ?>" placeholder="e.g., ENG-09">
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold small">Class</label>
                        <select class="form-select form-select-sm" name="class">
                            <option value="">All</option>
                            <?php foreach ($class_levels as $class): ?>
                                <option value="<?= $class['class_level'] ?>" <?= $class_filter == $class['class_level'] ? 'selected' : '' ?>>
                                    <?= $class['class_level'] ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small">Fiscal Year</label>
                        <select class="form-select form-select-sm" name="fiscal_year">
                            <option value="">All Years</option>
                            <?php foreach ($fiscal_years as $fy): ?>
                                <option value="<?= htmlspecialchars($fy['fiscal_year']) ?>" <?= $fiscal_year_filter === $fy['fiscal_year'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($fy['fiscal_year']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold small">Business</label>
                        <select class="form-select form-select-sm" name="business">
                            <option value="">All</option>
                            <option value="CDC"  <?= $business_filter === 'CDC'  ? 'selected' : '' ?>>CDC</option>
                            <option value="JEMC" <?= $business_filter === 'JEMC' ? 'selected' : '' ?>>JEMC</option>
                            <option value="NTC"  <?= $business_filter === 'NTC'  ? 'selected' : '' ?>>NTC</option>
                            <option value="NEB"  <?= $business_filter === 'NEB'  ? 'selected' : '' ?>>NEB</option>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <label class="form-label fw-semibold small">Book Type</label>
                        <select class="form-select form-select-sm" name="book_type">
                            <option value="">All Types</option>
                            <option value="TextBook"      <?= $book_type_filter === 'TextBook'      ? 'selected' : '' ?>>Text Book</option>
                            <option value="Copy"          <?= $book_type_filter === 'Copy'          ? 'selected' : '' ?>>Copy</option>
                            <option value="RechargeCard"  <?= $book_type_filter === 'RechargeCard'  ? 'selected' : '' ?>>Recharge Card</option>
                            <option value="Lalpurja"      <?= $book_type_filter === 'Lalpurja'      ? 'selected' : '' ?>>Lalpurja</option>
                            <option value="QuestionPaper" <?= $book_type_filter === 'QuestionPaper' ? 'selected' : '' ?>>Question Paper</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold small">Translated</label>
                        <select class="form-select form-select-sm" name="is_translated">
                            <option value="">All</option>
                            <option value="1" <?= $is_translated_filter === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= $is_translated_filter === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold small">Optional</label>
                        <select class="form-select form-select-sm" name="is_optional">
                            <option value="">All</option>
                            <option value="1" <?= $is_optional_filter === '1' ? 'selected' : '' ?>>Yes</option>
                            <option value="0" <?= $is_optional_filter === '0' ? 'selected' : '' ?>>No</option>
                        </select>
                    </div>
                    <div class="col-md-1">
                        <label class="form-label fw-semibold small">Status</label>
                        <select class="form-select form-select-sm" name="status">
                            <option value="active"   <?= $status_filter === 'active'   ? 'selected' : '' ?>>Active</option>
                            <option value="inactive" <?= $status_filter === 'inactive' ? 'selected' : '' ?>>Obsolete</option>
                            <option value="all"      <?= $status_filter === 'all'      ? 'selected' : '' ?>>All</option>
                        </select>
                    </div>
                    <div class="col-md-1 d-flex align-items-end">
                        <button type="submit" class="btn btn-primary btn-sm w-100">
                            <i class="fas fa-search"></i>
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>

    <!-- Export bar -->
    <div class="card shadow-sm mb-3">
        <div class="card-body py-2 px-3">
            <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
                <span class="small text-muted">
                    <i class="fas fa-download me-1"></i>Export entire database
                    <span class="badge bg-secondary ms-1"><?= number_format($total_records) ?> records</span>
                </span>
                <div class="btn-group btn-group-sm">
                    <a href="?export=excel" class="btn btn-success" target="_blank">
                        <i class="fas fa-file-excel me-1"></i>Excel
                    </a>
                    <a href="?export=pdf" class="btn btn-danger" target="_blank">
                        <i class="fas fa-file-pdf me-1"></i>PDF
                    </a>
                    <button class="btn btn-secondary" onclick="printTable()">
                        <i class="fas fa-print me-1"></i>Print
                    </button>
                </div>
            </div>
        </div>
    </div>

    <!-- Table Card -->
    <div class="card shadow-sm">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center py-3">
            <h6 class="mb-0 fw-semibold">
                <i class="fas fa-list me-2"></i>Books List
                <span class="badge bg-white text-primary ms-2"><?= number_format($total_records) ?></span>
            </h6>
            <small class="opacity-75">
                <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $items_per_page, $total_records)) ?>
                of <?= number_format($total_records) ?> &nbsp;|&nbsp; Page <?= $current_page ?>/<?= $total_pages ?>
            </small>
        </div>

        <div class="table-responsive">
            <table class="table table-hover table-sm align-middle mb-0" id="booksTable">
                <thead>
                    <tr>
                        <th>#</th>
                        <th>Book Code</th>
                        <th>Book Name</th>
                        <th>Title</th>
                        <th>Class</th>
                        <th>Fiscal Year</th>
                        <th>Pages</th>
                        <th>Business</th>
                        <th>Type</th>
                        <th>Tr.</th>
                        <th>Opt.</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Date</th>
                        <th class="text-center">Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($books)): ?>
                        <tr>
                            <td colspan="15" class="text-center py-5 text-muted">
                                <i class="fas fa-inbox fa-3x mb-3 d-block"></i>
                                No books found matching your criteria.
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($books as $index => $book): ?>
                            <tr>
                                <td class="text-muted small"><?= $offset + $index + 1 ?></td>
                                <td><code><?= htmlspecialchars($book['book_code']) ?></code></td>
                                <td class="text-truncate" style="max-width:220px" title="<?= htmlspecialchars($book['book_name']) ?>">
                                    <strong><?= htmlspecialchars($book['book_name']) ?></strong>
                                </td>
                                <td class="small">
                                    <?php if ($book['title_code']): ?>
                                        <span class="text-primary" title="<?= htmlspecialchars($book['title_name']) ?>"><?= htmlspecialchars($book['title_code']) ?></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($book['class_level']): ?>
                                        <span class="badge bg-secondary">Cls <?= $book['class_level'] ?></span>
                                    <?php else: ?>
                                        <span class="text-muted small">—</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small"><?= htmlspecialchars($book['fiscal_year'] ?: '—') ?></td>
                                <td class="small text-center"><?= $book['page_count'] !== null ? (int)$book['page_count'] : '—' ?></td>
                                <td><span class="badge bg-info text-dark"><?= htmlspecialchars($book['business_associated'] ?? 'CDC') ?></span></td>
                                <td><span class="badge bg-warning text-dark"><?= htmlspecialchars($book['book_type'] ?? 'TextBook') ?></span></td>
                                <td class="text-center">
                                    <?php if ($book['is_translated']): ?>
                                        <span class="badge bg-success">T</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">N</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($book['is_optional']): ?>
                                        <span class="badge bg-info text-dark">Y</span>
                                    <?php else: ?>
                                        <span class="badge bg-light text-muted">N</span>
                                    <?php endif; ?>
                                </td>
                                <td class="text-center">
                                    <?php if ($book['is_active']): ?>
                                        <span class="badge bg-success">Active</span>
                                    <?php else: ?>
                                        <span class="badge bg-secondary">Obsolete</span>
                                    <?php endif; ?>
                                </td>
                                <td class="small text-muted"><?= htmlspecialchars($book['created_by_name'] ?? '—') ?></td>
                                <td class="small text-muted text-nowrap"><?= date('d M Y', strtotime($book['created_at'])) ?></td>
                                <td class="text-center text-nowrap">
                                    <!-- View -->
                                    <a href="view.php?id=<?= $book['book_id'] ?>"
                                       class="action-btn action-view" title="View">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if (has_role('editor') || has_role('admin')): ?>
                                    <!-- Edit -->
                                    <a href="create.php?id=<?= $book['book_id'] ?>"
                                       class="action-btn action-edit" title="Edit">
                                        <i class="fas fa-edit"></i>
                                    </a>
                                    <?php endif; ?>
                                    <?php if (has_role('admin') || has_role('editor')): ?>
                                    <!-- Delete -->
                                    <button type="button"
                                            class="action-btn action-delete"
                                            title="Delete"
                                            onclick="confirmDelete(<?= $book['book_id'] ?>, '<?= htmlspecialchars(addslashes($book['book_code'])) ?>')">
                                        <i class="fas fa-trash-alt"></i>
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
                        Page <strong><?= $current_page ?></strong> / <strong><?= $total_pages ?></strong>
                        &mdash; <?= number_format($total_records) ?> total records
                    </small>
                    <nav>
                        <ul class="pagination pagination-sm mb-0">
                            <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url ?>1"><i class="fas fa-angle-double-left"></i></a>
                            </li>
                            <li class="page-item <?= $current_page <= 1 ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url . ($current_page - 1) ?>"><i class="fas fa-chevron-left"></i></a>
                            </li>

                            <?php
                            $window = 3;
                            $page_start = max(1, $current_page - $window);
                            $page_end   = min($total_pages, $current_page + $window);
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
                            <?php if ($page_end < $total_pages - 1): ?>
                                <li class="page-item disabled"><span class="page-link">&hellip;</span></li>
                                <li class="page-item"><a class="page-link" href="<?= $base_url . $total_pages ?>"><?= $total_pages ?></a></li>
                            <?php elseif ($page_end == $total_pages - 1): ?>
                                <li class="page-item"><a class="page-link" href="<?= $base_url . $total_pages ?>"><?= $total_pages ?></a></li>
                            <?php endif; ?>

                            <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url . ($current_page + 1) ?>"><i class="fas fa-chevron-right"></i></a>
                            </li>
                            <li class="page-item <?= $current_page >= $total_pages ? 'disabled' : '' ?>">
                                <a class="page-link" href="<?= $base_url . $total_pages ?>"><i class="fas fa-angle-double-right"></i></a>
                            </li>
                        </ul>
                    </nav>

                    <form method="GET" class="d-flex align-items-center gap-2">
                        <?php foreach ($query_no_page as $k => $v): ?>
                            <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                        <?php endforeach; ?>
                        <label class="text-muted small mb-0 text-nowrap">Go to:</label>
                        <input type="number" name="page" min="1" max="<?= $total_pages ?>"
                               class="form-control form-control-sm" style="width:65px" placeholder="<?= $current_page ?>">
                        <button type="submit" class="btn btn-sm btn-outline-primary">Go</button>
                    </form>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div><!-- /.container-fluid -->

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content border-0 shadow-lg">
            <div class="modal-header text-white" style="background: linear-gradient(135deg,#dc3545,#b02a37);">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-trash-alt me-2"></i>Confirm Delete
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body text-center py-4">
                <div class="mb-3">
                    <span style="font-size:3rem; line-height:1;">⚠️</span>
                </div>
                <h5 class="fw-bold">Delete this book permanently?</h5>
                <p class="mb-1 text-muted">Book Code:</p>
                <p class="fw-bold fs-5 text-danger" id="deleteBookCode"></p>
                <p class="text-muted small mb-0">This action cannot be undone. Books linked to job tickets cannot be deleted.</p>
            </div>
            <div class="modal-footer justify-content-center border-0 pt-0">
                <button type="button" class="btn btn-secondary px-4" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i>Cancel
                </button>
                <form method="POST" class="d-inline" id="deleteForm">
                    <input type="hidden" name="delete_id" id="deleteId">
                    <?php foreach ($query_no_page as $k => $v): ?>
                        <input type="hidden" name="<?= htmlspecialchars($k) ?>" value="<?= htmlspecialchars($v) ?>">
                    <?php endforeach; ?>
                    <button type="submit" class="btn btn-danger px-4">
                        <i class="fas fa-trash-alt me-1"></i>Yes, Delete
                    </button>
                </form>
            </div>
        </div>
    </div>
</div>

<style>
/* ── Action buttons — always visible, colour-coded ── */
.action-btn {
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 30px;
    height: 30px;
    border-radius: 6px;
    border: none;
    font-size: .8rem;
    cursor: pointer;
    text-decoration: none;
    transition: transform .15s, box-shadow .15s, opacity .15s;
    margin: 0 2px;
}
.action-btn:hover { transform: translateY(-2px); box-shadow: 0 4px 8px rgba(0,0,0,.2); opacity: .9; }

.action-view   { background:#0dcaf0; color:#fff; }
.action-view:hover { background:#0bb5d9; color:#fff; }

.action-edit   { background:#ffc107; color:#212529; }
.action-edit:hover { background:#e6ac00; color:#212529; }

.action-delete { background:#dc3545; color:#fff; }
.action-delete:hover { background:#bb2d3b; color:#fff; }

/* ── Table head ── */
.table thead th {
    background: linear-gradient(135deg,#343a40 0%,#495057 100%);
    color: #fff !important;
    font-size: .78rem;
    font-weight: 600;
    text-transform: uppercase;
    letter-spacing: .4px;
    border: none;
    padding: .6rem .75rem;
    white-space: nowrap;
}
.table tbody tr { transition: background .15s; }
.table tbody tr:hover { background-color: rgba(13,110,253,.04); }

code {
    background: #f0f4ff !important;
    color: #6f42c1 !important;
    padding: .2em .45em !important;
    border-radius: 4px !important;
    font-size: .82em !important;
    font-weight: 600 !important;
}

.card { border-radius: 10px; border: 1px solid rgba(0,0,0,.06); }
.card-header { border-radius: 10px 10px 0 0 !important; }

@media print {
    .action-btn, .btn, .card-header, .card-footer, .alert, .modal { display:none!important; }
}
@media (max-width:768px) {
    .table th, .table td { font-size:.75rem; padding:.35rem .3rem; }
}
</style>

<script>
function resetFilters() { window.location.href = window.location.pathname; }

function confirmDelete(id, bookCode) {
    document.getElementById('deleteId').value      = id;
    document.getElementById('deleteBookCode').textContent = bookCode;
    new bootstrap.Modal(document.getElementById('deleteModal')).show();
}

function printTable() {
    const win = window.open('', '_blank');
    const tbl = document.getElementById('booksTable').cloneNode(true);
    tbl.querySelectorAll('th:last-child, td:last-child').forEach(c => c.remove());
    win.document.write(`<!DOCTYPE html><html><head><title>Books Report</title>
    <style>
        body{font-family:Arial,sans-serif;margin:20px;}
        h2{text-align:center;}
        table{width:100%;border-collapse:collapse;margin-top:16px;}
        th,td{border:1px solid #000;padding:5px 7px;font-size:11px;text-align:left;}
        th{background:#f0f0f0;font-weight:bold;}
        @page{margin:15mm;}
    </style></head><body>
    <h2>Books Report</h2>
    <p style="text-align:center;font-size:12px;">Generated: ${new Date().toLocaleString()} | Total: <?= number_format($total_records) ?></p>
    ${tbl.outerHTML}
    </body></html>`);
    win.document.close();
    win.print();
}

document.addEventListener('DOMContentLoaded', function () {
    // Auto-submit on select change
    document.querySelectorAll('#filterForm select').forEach(s => {
        s.addEventListener('change', () => document.getElementById('filterForm').submit());
    });
    // Debounced search
    let t;
    const si = document.querySelector('input[name="search"]');
    if (si) si.addEventListener('input', () => { clearTimeout(t); t = setTimeout(() => document.getElementById('filterForm').submit(), 900); });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

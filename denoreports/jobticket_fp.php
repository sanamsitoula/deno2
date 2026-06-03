<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('viewer') && !has_role('editor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Pagination setup
$records_per_page = isset($_GET['per_page']) ? (int)$_GET['per_page'] : 50;
$page = (int)($_GET['page'] ?? 1);
$offset = ($page - 1) * $records_per_page;

// Get current active fiscal year
$current_fiscal_year = $conn->query("
    SELECT id, fiscal_code 
    FROM fiscal_years 
    WHERE is_active = true 
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Search parameters
$search_params = [
    'job_ticket_code' => $_GET['job_ticket_code'] ?? '',
    'book_code'       => $_GET['book_code'] ?? '',
    'class'           => $_GET['class'] ?? '',
    'machine_id'      => $_GET['machine_id'] ?? '',
    'fiscal_year_id'  => $_GET['fiscal_year_id'] ?? $current_fiscal_year['id'],
    'start_date_nep'  => $_GET['start_date_nep'] ?? '',
    'end_date_nep'    => $_GET['end_date_nep'] ?? '',
];

// Base query with aggregation
$base_query = "
    SELECT 
        jt.job_ticket_code,
        b.book_code,
        b.book_name,
        jt.class,
        jtd.id AS jtd_id,
        f.name AS forma_name,
        jtd.page,
        jtd.print_qty AS target_print_qty,
        COALESCE(SUM(fp.fp_printqty), 0) AS actual_printed_qty,
        fy.fiscal_code,
        m.machine_name,
        STRING_AGG(DISTINCT fp.date_nep, ', ') AS printing_dates_nep,
        jt.date_nep AS job_ticket_date_nep
    FROM job_ticket jt
    JOIN job_ticket_details jtd ON jt.id = jtd.job_ticket_id
    JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN forma f ON jtd.forma_id = f.id
    LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = TRUE
    LEFT JOIN machines m ON fp.machine_id = m.id
    JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
    WHERE 1=1
";

// Build conditions
$conditions = "";
$bind_params = [];

if (!empty($search_params['job_ticket_code'])) {
    $conditions .= " AND jt.job_ticket_code ILIKE :job_ticket_code";
    $bind_params[':job_ticket_code'] = '%' . $search_params['job_ticket_code'] . '%';
}

if (!empty($search_params['book_code'])) {
    $conditions .= " AND b.book_code ILIKE :book_code";
    $bind_params[':book_code'] = '%' . $search_params['book_code'] . '%';
}

if (!empty($search_params['class'])) {
    $conditions .= " AND jt.class = :class";
    $bind_params[':class'] = $search_params['class'];
}

if (!empty($search_params['machine_id'])) {
    $conditions .= " AND fp.machine_id = :machine_id";
    $bind_params[':machine_id'] = $search_params['machine_id'];
}

if (!empty($search_params['fiscal_year_id'])) {
    $conditions .= " AND jt.fiscal_year_id = :fiscal_year_id";
    $bind_params[':fiscal_year_id'] = $search_params['fiscal_year_id'];
}

if (!empty($search_params['start_date_nep']) && !empty($search_params['end_date_nep'])) {
    $conditions .= " AND fp.date_nep BETWEEN :start_date_nep AND :end_date_nep";
    $bind_params[':start_date_nep'] = $search_params['start_date_nep'];
    $bind_params[':end_date_nep'] = $search_params['end_date_nep'];
}

$group_order = " GROUP BY jt.job_ticket_code, b.book_code, b.book_name, jt.class, jtd.id, f.name, fy.fiscal_code, m.machine_name, jt.date_nep ";

// Count query
$count_query = "SELECT COUNT(*) as total FROM ($base_query $conditions $group_order) as sub";
$count_stmt = $conn->prepare($count_query);
foreach ($bind_params as $key => $value) {
    $count_stmt->bindValue($key, $value);
}
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = ceil($total_records / $records_per_page);

// Main query with pagination
$final_query = "$base_query $conditions $group_order ORDER BY jt.job_ticket_code, jtd.order_no LIMIT :limit OFFSET :offset";
$stmt = $conn->prepare($final_query);
foreach ($bind_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$report_data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_target = array_sum(array_column($report_data, 'target_print_qty'));
$total_printed = array_sum(array_column($report_data, 'actual_printed_qty'));

// Fetch dropdown data
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name")->fetchAll(PDO::FETCH_ASSOC);
$classes = $conn->query("SELECT DISTINCT class FROM job_ticket WHERE class IS NOT NULL ORDER BY class")->fetchAll(PDO::FETCH_COLUMN);

// Handle Excel Export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    $export_query = str_replace("LIMIT :limit OFFSET :offset", "", $final_query);
    $export_stmt = $conn->prepare($export_query);
    foreach ($bind_params as $key => $value) {
        $export_stmt->bindValue($key, $value);
    }
    $export_stmt->execute();
    $export_data = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="forma_completion_report_' . date('Y-m-d_H-i-s') . '.xls"');
    header('Cache-Control: max-age=0');

    echo '<table border="1">';
    echo '<tr style="background-color:#4CAF50;color:white;">
        <th>S.N</th>
        <th>Job Ticket</th>
        <th>Book Code</th>
        <th>Book Name</th>
        <th>Class</th>
        <th>Forma Name</th>
        <th>Page</th>
        <th>Target Qty</th>
        <th>Printed Qty</th>
        <th>Remaining</th>
        <th>Status</th>
        <th>Completion %</th>
        <th>Machines Used</th>
        <th>Printing Dates (Nepali)</th>
        <th>Fiscal Year</th>
        <th>Job Date (Nepali)</th>
    </tr>';

    foreach ($export_data as $index => $row) {
        $printed = (int)$row['actual_printed_qty'];
        $target = (int)$row['target_print_qty'];
        $remaining = max(0, $target - $printed);
        $percent = $target > 0 ? round(($printed / $target) * 100, 2) : 0;

        $status = $printed == 0 ? 'Not Started' : ($printed >= $target ? 'Completed' : 'In Progress');

        echo '<tr>';
        echo '<td>' . ($index + 1) . '</td>';
        echo '<td>' . htmlspecialchars($row['job_ticket_code']) . '</td>';
        echo '<td>' . htmlspecialchars($row['book_code']) . '</td>';
        echo '<td>' . htmlspecialchars($row['book_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['class']) . '</td>';
        echo '<td>' . htmlspecialchars($row['forma_name']) . '</td>';
        echo '<td>' . htmlspecialchars($row['page']) . '</td>';
        echo '<td class="numeric">' . number_format($target) . '</td>';
        echo '<td class="numeric">' . number_format($printed) . '</td>';
        echo '<td class="numeric">' . number_format($remaining) . '</td>';
        echo '<td>' . $status . '</td>';
        echo '<td class="numeric">' . number_format($percent, 2) . '%</td>';
        echo '<td>' . htmlspecialchars($row['machine_name'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['printing_dates_nep'] ?? 'N/A') . '</td>';
        echo '<td>' . htmlspecialchars($row['fiscal_code']) . '</td>';
        echo '<td>' . htmlspecialchars($row['job_ticket_date_nep']) . '</td>';
        echo '</tr>';
    }

    // Export totals
    $exp_total_target = array_sum(array_column($export_data, 'target_print_qty'));
    $exp_total_printed = array_sum(array_column($export_data, 'actual_printed_qty'));
    $exp_total_remaining = $exp_total_target - $exp_total_printed;

    echo '<tr style="font-weight:bold;background-color:#f8f9fa;">
        <td colspan="7"><strong>GRAND TOTALS</strong></td>
        <td class="numeric"><strong>' . number_format($exp_total_target) . '</strong></td>
        <td class="numeric"><strong>' . number_format($exp_total_printed) . '</strong></td>
        <td class="numeric"><strong>' . number_format($exp_total_remaining) . '</strong></td>
        <td colspan="6"></td>
    </tr>';
    echo '</table>';
    exit();
}
?>

<style>
    .container {
        max-width: 100%;
        padding: 20px;
    }
    h2 {
        margin: 20px 15px;
        color: #333;
        font-size: 28px;
        font-weight: 600;
    }
    .action-buttons {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 0 15px 20px;
        flex-wrap: wrap;
        gap: 10px;
        background: #f8f9fa;
        padding: 15px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
    }
    .pagination-info {
        color: #666;
        font-size: 0.95em;
        font-weight: 500;
    }
    .records-per-page {
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .search-container {
        background: #f8f9fa;
        padding: 25px;
        border-radius: 10px;
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
    .search-group label {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 6px;
        display: block;
        color: #495057;
    }
    .search-control {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 14px;
        box-sizing: border-box;
    }
    .search-control:focus {
        outline: none;
        border-color: #007bff;
    }
    .btn {
        padding: 10px 18px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
    }
    .btn-primary { background: #007bff; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-info { background: #17a2b8; color: white; }
    .btn-sm { padding: 6px 12px; font-size: 12px; }
    .table-responsive {
        margin: 0 15px;
        overflow-x: auto;
        background: white;
        border-radius: 8px;
        box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    }
    .table th, .table td {
        padding: 12px 8px;
        text-align: left;
        border-bottom: 1px solid #dee2e6;
    }
    .table th {
        background-color: #343a40;
        color: white;
        font-weight: 600;
        text-align: center;
        font-size: 12px;
    }
    .table tbody tr:hover {
        background-color: #f8f9fa;
    }
    .badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: 500;
    }
    .badge-completed { background: #28a745; color: white; }
    .badge-pending { background: #ffc107; color: #212529; }
    .badge-not-started { background: #dc3545; color: white; }
    .totals-row {
        font-weight: bold;
        background-color: #e9ecef;
    }
    .numeric { text-align: right; }
    .center { text-align: center; }
    @media (max-width: 768px) {
        .search-row { grid-template-columns: 1fr; }
        .table th, .table td { padding: 8px 4px; font-size: 11px; }
    }
</style>

<div class="container">
    <h2>📋 Forma Printing Completion Report</h2>

    <div class="action-buttons">
        <div class="pagination-info">
            Showing <?= count($report_data) ?> of <?= number_format($total_records) ?> records
            <?php if ($total_pages > 1): ?>
                (Page <?= $page ?> of <?= $total_pages ?>)
            <?php endif; ?>
        </div>
        <div class="records-per-page">
            <label for="per_page">Records per page:</label>
            <select name="per_page" id="per_page" onchange="changePerPage(this.value)">
                <option value="25" <?= $records_per_page == 25 ? 'selected' : '' ?>>25</option>
                <option value="50" <?= $records_per_page == 50 ? 'selected' : '' ?>>50</option>
                <option value="100" <?= $records_per_page == 100 ? 'selected' : '' ?>>100</option>
            </select>
            <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-success btn-sm">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
            <button onclick="window.print()" class="btn btn-info btn-sm">Print</button>
        </div>
    </div>

    <!-- Summary Stats -->
    <?php if (count($report_data) > 0): ?>
    <div class="search-stats">
        <div class="stats-grid">
            <div class="stat-item">
                <span class="stat-number"><?= number_format($total_target) ?></span>
                <span class="stat-label">Total Target</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= number_format($total_printed) ?></span>
                <span class="stat-label">Printed</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= number_format($total_target - $total_printed) ?></span>
                <span class="stat-label">Remaining</span>
            </div>
            <div class="stat-item">
                <span class="stat-number"><?= $total_target > 0 ? number_format(($total_printed / $total_target) * 100, 1) : '0' ?>%</span>
                <span class="stat-label">Overall Completion</span>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Search Form -->
    <div class="search-container">
        <div class="search-title">🔍 Filter Report</div>
        <form method="get" id="searchForm">
            <input type="hidden" name="page" value="1">
            <input type="hidden" name="per_page" value="<?= $records_per_page ?>">
            <div class="search-row">
                <div class="search-group">
                    <label>Job Ticket Code</label>
                    <input type="text" name="job_ticket_code" class="search-control"
                           value="<?= htmlspecialchars($search_params['job_ticket_code']) ?>"
                           placeholder="JT-2024-...">
                </div>
                <div class="search-group">
                    <label>Book Code</label>
                    <input type="text" name="book_code" class="search-control"
                           value="<?= htmlspecialchars($search_params['book_code']) ?>"
                           placeholder="ENG-001">
                </div>
                <div class="search-group">
                    <label>Class</label>
                    <select name="class" class="search-control">
                        <option value="">All Classes</option>
                        <?php foreach ($classes as $cls): ?>
                            <option value="<?= $cls ?>" <?= $search_params['class'] == $cls ? 'selected' : '' ?>>
                                Class <?= $cls ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>Machine</label>
                    <select name="machine_id" class="search-control">
                        <option value="">All Machines</option>
                        <?php foreach ($machines as $m): ?>
                            <option value="<?= $m['id'] ?>" <?= $search_params['machine_id'] == $m['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($m['machine_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div class="search-row">
                <div class="search-group">
                    <label>Fiscal Year</label>
                    <select name="fiscal_year_id" class="search-control">
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= $search_params['fiscal_year_id'] == $fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label>Date From (Nepali)</label>
                    <input type="text" name="start_date_nep" class="search-control"
                           value="<?= htmlspecialchars($search_params['start_date_nep']) ?>"
                           placeholder="2081.04.01">
                </div>
                <div class="search-group">
                    <label>Date To (Nepali)</label>
                    <input type="text" name="end_date_nep" class="search-control"
                           value="<?= htmlspecialchars($search_params['end_date_nep']) ?>"
                           placeholder="2081.05.30">
                </div>
                <div class="search-group" style="align-self:flex-end">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="?" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Report Table -->
    <div class="table-responsive">
        <table class="table">
            <thead>
            <tr>
                <th>S.N</th>
                <th>Job Ticket</th>
                <th>Book Code</th>
                <th>Book Name</th>
                <th>Class</th>
                <th>Forma Name</th>
                <th>Page</th>
                <th>Target Qty</th>
                <th>Printed Qty</th>
                <th>Remaining</th>
                <th>Status</th>
                <th>%</th>
                <th>Machine</th>
                <th>Print Dates</th>
                <th>FY</th>
                <th>Job Date</th>
            </tr>
            </thead>
            <tbody>
            <?php if (count($report_data) > 0): ?>
                <?php foreach ($report_data as $index => $row): 
                    $printed = (int)$row['actual_printed_qty'];
                    $target = (int)$row['target_print_qty'];
                    $remaining = max(0, $target - $printed);
                    $percent = $target > 0 ? round(($printed / $target) * 100, 2) : 0;
                    $status = $printed == 0 ? 'Not Started' : ($printed >= $target ? 'Completed' : 'In Progress');
                    $badge_class = $printed == 0 ? 'badge-not-started' : ($printed >= $target ? 'badge-completed' : 'badge-pending');
                ?>
                <tr>
                    <td class="center"><?= $index + 1 + $offset ?></td>
                    <td><?= htmlspecialchars($row['job_ticket_code']) ?></td>
                    <td><?= htmlspecialchars($row['book_code']) ?></td>
                    <td><?= htmlspecialchars($row['book_name']) ?></td>
                    <td class="center"><?= htmlspecialchars($row['class']) ?></td>
                    <td><?= htmlspecialchars($row['forma_name']) ?></td>
                    <td class="center"><?= htmlspecialchars($row['page']) ?></td>
                    <td class="numeric"><?= number_format($target) ?></td>
                    <td class="numeric"><?= number_format($printed) ?></td>
                    <td class="numeric"><?= number_format($remaining) ?></td>
                    <td class="center">
                        <span class="badge <?= $badge_class ?>"><?= $status ?></span>
                    </td>
                    <td class="numeric"><?= number_format($percent, 1) ?>%</td>
                    <td><?= htmlspecialchars($row['machine_name'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['printing_dates_nep'] ?? 'N/A') ?></td>
                    <td><?= htmlspecialchars($row['fiscal_code']) ?></td>
                    <td><?= htmlspecialchars($row['job_ticket_date_nep']) ?></td>
                </tr>
                <?php endforeach; ?>
                <tr class="totals-row">
                    <td colspan="7"><strong>TOTALS</strong></td>
                    <td class="numeric"><strong><?= number_format($total_target) ?></strong></td>
                    <td class="numeric"><strong><?= number_format($total_printed) ?></strong></td>
                    <td class="numeric"><strong><?= number_format($total_target - $total_printed) ?></strong></td>
                    <td colspan="6"></td>
                </tr>
            <?php else: ?>
                <tr>
                    <td colspan="16" class="center" style="color: #666; padding: 50px;">
                        <i class="fas fa-search" style="font-size: 48px; margin-bottom: 10px;"></i><br>
                        No matching records found. Try adjusting your filters.
                    </td>
                </tr>
            <?php endif; ?>
            </tbody>
        </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1): ?>
    <div class="pagination-container">
        <ul class="pagination">
            <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => 1])) ?>">First</a>
            </li>
            <li class="<?= $page <= 1 ? 'disabled' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page - 1])) ?>">Previous</a>
            </li>
            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                <li class="<?= $i == $page ? 'active' : '' ?>">
                    <a href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
                </li>
            <?php endfor; ?>
            <li class="<?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $page + 1])) ?>">Next</a>
            </li>
            <li class="<?= $page >= $total_pages ? 'disabled' : '' ?>">
                <a href="?<?= http_build_query(array_merge($_GET, ['page' => $total_pages])) ?>">Last</a>
            </li>
        </ul>
    </div>
    <?php endif; ?>
</div>

<script>
function changePerPage(value) {
    const url = new URL(window.location);
    url.searchParams.set('per_page', value);
    url.searchParams.set('page', '1');
    window.location.href = url.toString();
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
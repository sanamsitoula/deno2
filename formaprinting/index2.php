<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('editor') && !has_role('admin')) {
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Pagination settings
$records_per_page = 50;
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

// Default date range (current fiscal year)
$current_fiscal_year = $conn->query("SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1")->fetch(PDO::FETCH_ASSOC);
$fiscal_year_id = $current_fiscal_year['id'] ?? null;

// Get search parameters
$search_params = [
    'name' => $_GET['name'] ?? '',
    'jt_id' => $_GET['jt_id'] ?? '',
    'machine_id' => $_GET['machine_id'] ?? '',
    'status' => $_GET['status'] ?? '',
    'fiscal_year_id' => $_GET['fiscal_year_id'] ?? $fiscal_year_id,
    'start_date' => $_GET['start_date'] ?? '',
    'end_date' => $_GET['end_date'] ?? ''
];

// Build the base query for counting total records
$count_query = "
    SELECT COUNT(*) as total
    FROM forma_printing fp
    LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
    LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
    LEFT JOIN machines m ON fp.machine_id = m.id
    LEFT JOIN users u ON fp.created_by = u.id
    WHERE 1=1
";

// Build the main query
$query = "
    SELECT 
        fp.*,
        jt.job_ticket_code,
        m.machine_name,
        u.username as created_by_name,
        fy.fiscal_code
    FROM forma_printing fp
    LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
    LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
    LEFT JOIN machines m ON fp.machine_id = m.id
    LEFT JOIN users u ON fp.created_by = u.id
    LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
    WHERE 1=1
";

// Add search conditions
$conditions = "";
$bind_params = [];

if (!empty($search_params['name'])) {
    $conditions .= " AND fp.name LIKE :name";
    $bind_params[':name'] = '%' . $search_params['name'] . '%';
}

if (!empty($search_params['jt_id'])) {
    $conditions .= " AND fp.jt_id = :jt_id";
    $bind_params[':jt_id'] = $search_params['jt_id'];
}

if (!empty($search_params['machine_id'])) {
    $conditions .= " AND fp.machine_id = :machine_id";
    $bind_params[':machine_id'] = $search_params['machine_id'];
}

if ($search_params['status'] !== '') {
    $conditions .= " AND fp.status = :status";
    $bind_params[':status'] = ($search_params['status'] === '1') ? true : false;
}

if (!empty($search_params['fiscal_year_id'])) {
    $conditions .= " AND fp.fiscal_year_id = :fiscal_year_id";
    $bind_params[':fiscal_year_id'] = $search_params['fiscal_year_id'];
}

// Add date range condition if both dates are provided
if (!empty($search_params['start_date']) && !empty($search_params['end_date'])) {
    $conditions .= " AND fp.created_date BETWEEN :start_date AND :end_date";
    $bind_params[':start_date'] = $search_params['start_date'];
    $bind_params[':end_date'] = $search_params['end_date'];
}

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
$query .= " ORDER BY fp.created_date DESC LIMIT :limit OFFSET :offset";

// Prepare and execute the main query
$stmt = $conn->prepare($query);
foreach ($bind_params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$forma_records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get dropdown data
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code")->fetchAll(PDO::FETCH_ASSOC);
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name")->fetchAll(PDO::FETCH_ASSOC);
$job_tickets = $conn->query("SELECT id, job_ticket_code FROM job_ticket ORDER BY job_ticket_code")->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals for current page
$total_target = 0;
$total_printed = 0;
$total_remaining = 0;

foreach ($forma_records as $record) {
    $total_target += $record['jtd_targetqty'];
    $total_printed += $record['fp_printqty'];
    $total_remaining += $record['fp_remainqty'];
}

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="forma_printing_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    // Get all records for export (without pagination)
    $export_query = str_replace(" LIMIT :limit OFFSET :offset", "", $query);
    $export_stmt = $conn->prepare($export_query);
    foreach ($bind_params as $key => $value) {
        $export_stmt->bindValue($key, $value);
    }
    $export_stmt->execute();
    $export_records = $export_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr>
        <th>ID</th>
        <th>Name</th>
        <th>Fiscal Year</th>
        <th>Job Ticket</th>
        <th>Machine</th>
        <th>Target Qty</th>
        <th>Printed Qty</th>
        <th>Remaining Qty</th>
        <th>Status</th>
        <th>Created By</th>
        <th>Created Date</th>
    </tr>";
    
    foreach ($export_records as $record) {
        echo "<tr>";
        echo "<td>" . $record['id'] . "</td>";
        echo "<td>" . htmlspecialchars($record['name']) . "</td>";
        echo "<td>" . htmlspecialchars($record['fiscal_code']) . "</td>";
        echo "<td>" . htmlspecialchars($record['job_ticket_code']) . "</td>";
        echo "<td>" . htmlspecialchars($record['machine_name']) . "</td>";
        echo "<td>" . number_format($record['jtd_targetqty']) . "</td>";
        echo "<td>" . number_format($record['fp_printqty']) . "</td>";
        echo "<td>" . number_format($record['fp_remainqty']) . "</td>";
        echo "<td>" . ($record['status'] ? 'Active' : 'Inactive') . "</td>";
        echo "<td>" . htmlspecialchars($record['created_by_name']) . "</td>";
        echo "<td>" . date('Y-m-d H:i', strtotime($record['created_date'])) . "</td>";
        echo "</tr>";
    }
    
    // Add totals row
    echo "<tr style='font-weight:bold;background-color:#f8f9fa;'>";
    echo "<td colspan='5'>Totals</td>";
    echo "<td>" . number_format($total_target) . "</td>";
    echo "<td>" . number_format($total_printed) . "</td>";
    echo "<td>" . number_format($total_remaining) . "</td>";
    echo "<td colspan='3'></td>";
    echo "</tr>";
    
    echo "</table>";
    exit();
}
?>

<!-- HTML and CSS would be similar to your example, just adjusted for the forma_printing fields -->
<!-- I've included the key parts below -->

<div class="container">
    <h2>📄 Forma Printing Management</h2>
    
    <div class="action-buttons">
        <a href="forma_printing_create.php" class="btn btn-primary">
            <i class="fas fa-plus-circle"></i> Create New Forma Printing
        </a>
    </div>

    <div class="search-container">
        <form method="get" id="searchForm">
            <input type="hidden" name="page" value="1">
            
            <div class="search-row">
                <div class="search-group">
                    <label for="name">Name:</label>
                    <input type="text" name="name" id="name" class="search-control" 
                           value="<?= htmlspecialchars($search_params['name']) ?>">
                </div>
                
                <div class="search-group">
                    <label for="jt_id">Job Ticket:</label>
                    <select name="jt_id" id="jt_id" class="search-control">
                        <option value="">All Job Tickets</option>
                        <?php foreach ($job_tickets as $jt): ?>
                            <option value="<?= $jt['id'] ?>" 
                                <?= $search_params['jt_id'] == $jt['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($jt['job_ticket_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="machine_id">Machine:</label>
                    <select name="machine_id" id="machine_id" class="search-control">
                        <option value="">All Machines</option>
                        <?php foreach ($machines as $machine): ?>
                            <option value="<?= $machine['id'] ?>" 
                                <?= $search_params['machine_id'] == $machine['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($machine['machine_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="search-row">
                <div class="search-group">
                    <label for="fiscal_year_id">Fiscal Year:</label>
                    <select name="fiscal_year_id" id="fiscal_year_id" class="search-control">
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" 
                                <?= $search_params['fiscal_year_id'] == $fy['id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="status">Status:</label>
                    <select name="status" id="status" class="search-control">
                        <option value="">All Statuses</option>
                        <option value="1" <?= $search_params['status'] === '1' ? 'selected' : '' ?>>Active</option>
                        <option value="0" <?= $search_params['status'] === '0' ? 'selected' : '' ?>>Inactive</option>
                    </select>
                </div>
                
                <div class="search-group">
                    <button type="submit" class="btn btn-primary">Search</button>
                    <a href="?" class="btn btn-secondary">Reset</a>
                </div>
            </div>
        </form>
    </div>

    <!-- Table display would be similar to your example -->
    <!-- Include pagination and other UI elements as in your example -->

</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
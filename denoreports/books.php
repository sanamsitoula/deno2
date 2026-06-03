<?php 

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

// For actions that modify data, add role checks:s
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    // Only allow editors and admins to modify data
    if (!has_role('editor') && !has_role('admin')) {
        echo "<div class='alert alert-danger'>You don't have permission to perform this action.</div>";
        exit();
    }
    
    // Rest of your POST handling code...
}?>
<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php'; 
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Get filter parameters
$from_date_nep = $_GET['from_date_nep'] ?? '';
$to_date_nep = $_GET['to_date_nep'] ?? '';
$fiscal_year = $_GET['fiscal_year'] ?? '';
$book_filter = $_GET['book_filter'] ?? '';
$book_code_filter = $_GET['book_code_filter'] ?? [];
$class_filter = $_GET['class_filter'] ?? [];
$type_filter = $_GET['type_filter'] ?? '';

// Handle multiple selections
if (is_string($class_filter)) {
    $class_filter = empty($class_filter) ? [] : [$class_filter];
}
if (is_string($book_code_filter)) {
    $book_code_filter = empty($book_code_filter) ? [] : [$book_code_filter];
}

// Build WHERE clause for filters
$where_conditions = [];
$params = [];

if (!empty($from_date_nep)) {
    $where_conditions[] = "d.deno_date_nep >= :from_date_nep";
    $params[':from_date_nep'] = $from_date_nep;
}

if (!empty($to_date_nep)) {
    $where_conditions[] = "d.deno_date_nep <= :to_date_nep";
    $params[':to_date_nep'] = $to_date_nep;
}

if (!empty($fiscal_year)) {
    $where_conditions[] = "d.fiscal_year = :fiscal_year";
    $params[':fiscal_year'] = $fiscal_year;
}

if (!empty($book_filter)) {
    $where_conditions[] = "b.book_name ILIKE :book_filter";
    $params[':book_filter'] = '%' . $book_filter . '%';
}

if (!empty($book_code_filter)) {
    $placeholders = [];
    foreach ($book_code_filter as $index => $code) {
        $param_name = ':book_code_filter_' . $index;
        $placeholders[] = $param_name;
        $params[$param_name] = $code;
    }
    $where_conditions[] = "b.book_code IN (" . implode(',', $placeholders) . ")";
}

if (!empty($class_filter)) {
    $placeholders = [];
    foreach ($class_filter as $index => $class) {
        $param_name = ':class_filter_' . $index;
        $placeholders[] = $param_name;
        $params[$param_name] = $class;
    }
    $where_conditions[] = "b.class_level IN (" . implode(',', $placeholders) . ")";
}

if (!empty($type_filter)) {
    if ($type_filter == 'translated') {
        $where_conditions[] = "b.is_translated = true";
    } elseif ($type_filter == 'non_translated') {
        $where_conditions[] = "b.is_translated = false";
    }
}

$where_clause = '';
if (!empty($where_conditions)) {
    $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
}

// Get filtered books with production summary
$sql = "
    SELECT 
        b.book_id,
        b.book_name,
        b.book_code,
        b.class_level,
        b.is_translated,
        b.fiscal_year as book_fiscal_year,
        COALESCE(SUM(d.total_qty), 0) as total_produced,
        COALESCE(SUM(d.quantity_openpcs), 0) as total_openpcs
    FROM Books b
    LEFT JOIN Deno d ON b.book_code = d.book_code
    $where_clause
    GROUP BY b.book_id, b.book_name, b.book_code, b.class_level, b.is_translated, b.fiscal_year
    ORDER BY b.class_level, b.book_name
";

$stmt = $conn->prepare($sql);
$stmt->execute($params);
$books = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get unique classes for filter dropdown (only from filtered data if filters applied)
$class_sql = "SELECT DISTINCT b.class_level FROM Books b";
if (!empty($where_conditions)) {
    // Remove class filter from where conditions for class dropdown
    $class_where_conditions = array_filter($where_conditions, function($condition) {
        return strpos($condition, 'b.class_level') === false;
    });
    if (!empty($class_where_conditions)) {
        $class_sql .= " LEFT JOIN Deno d ON b.book_code = d.book_code WHERE " . implode(' AND ', $class_where_conditions);
    }
}
$class_sql .= " ORDER BY b.class_level";
$class_stmt = $conn->prepare($class_sql);
$class_stmt->execute(array_filter($params, function($key) {
    return strpos($key, 'class_filter') === false;
}, ARRAY_FILTER_USE_KEY));
$classes = $class_stmt->fetchAll(PDO::FETCH_COLUMN);

// Get unique book codes for filter dropdown (only from filtered data if filters applied)
$book_code_sql = "SELECT DISTINCT b.book_code, b.book_name FROM Books b";
if (!empty($where_conditions)) {
    // Remove book_code filter from where conditions for book_code dropdown
    $book_code_where_conditions = array_filter($where_conditions, function($condition) {
        return strpos($condition, 'b.book_code IN') === false;
    });
    if (!empty($book_code_where_conditions)) {
        $book_code_sql .= " LEFT JOIN Deno d ON b.book_code = d.book_code WHERE " . implode(' AND ', $book_code_where_conditions);
    }
}
$book_code_sql .= " ORDER BY b.book_code";
$book_code_stmt = $conn->prepare($book_code_sql);
$book_code_stmt->execute(array_filter($params, function($key) {
    return strpos($key, 'book_code_filter') === false;
}, ARRAY_FILTER_USE_KEY));
$book_codes = $book_code_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get fiscal years from enum
$fiscal_stmt = $conn->prepare("
    SELECT unnest(enum_range(NULL::fiscal_year_enum)) as fiscal_year 
    ORDER BY fiscal_year
");
$fiscal_stmt->execute();
$fiscal_years = $fiscal_stmt->fetchAll(PDO::FETCH_COLUMN);

// Calculate totals
$total_books = count($books);
$total_produced = array_sum(array_column($books, 'total_produced'));
$total_openpcs = array_sum(array_column($books, 'total_openpcs'));
$net_production = $total_produced + $total_openpcs;

// Handle export requests
if (isset($_GET['export']) && ($_GET['export'] == 'csv' || $_GET['export'] == 'excel')) {
    $filename = 'books_production_summary_' . date('Y-m-d_H-i-s');
    
    if ($_GET['export'] == 'csv') {
        header('Content-Type: text/csv');
        header('Content-Disposition: attachment; filename="' . $filename . '.csv"');
        
        $output = fopen('php://output', 'w');
        
        // CSV headers
        fputcsv($output, [
            'SN', 'Book Name', 'Book Code', 'Class', 'Fiscal Year', 'Type', 
            'Total Produced', 'Defective', 'Net Production'
        ]);
        
        // CSV data
        $sn = 1;
        foreach ($books as $book) {
            fputcsv($output, [
                $sn++,
                $book['book_name'],
                $book['book_code'],
                $book['class_level'],
                $book['book_fiscal_year'],
                $book['is_translated'] ? 'Translated' : 'Non Translation',
                $book['total_produced'],
                $book['total_openpcs'],
                $book['total_produced'] + $book['total_openpcs']
            ]);
        }
        
        // Add totals row
        fputcsv($output, [
            '', 'TOTAL', '', '', '', '',
            $total_produced, $total_openpcs, $net_production
        ]);
        
        fclose($output);
        exit;
    }
    
    if ($_GET['export'] == 'excel') {
        header('Content-Type: application/vnd.ms-excel');
        header('Content-Disposition: attachment; filename="' . $filename . '.xls"');
        
        echo '<table border="1">';
        echo '<tr><th>SN</th><th>Book Name</th><th>Book Code</th><th>Class</th><th>Fiscal Year</th><th>Type</th><th>Total Produced</th><th>Openpcs</th><th>Net Production</th></tr>';
        
        $sn = 1;
        foreach ($books as $book) {
            echo '<tr>';
            echo '<td>' . $sn++ . '</td>';
            echo '<td>' . htmlspecialchars($book['book_name']) . '</td>';
            echo '<td>' . htmlspecialchars($book['book_code']) . '</td>';
            echo '<td>' . $book['class_level'] . '</td>';
            echo '<td>' . $book['book_fiscal_year'] . '</td>';
            echo '<td>' . ($book['is_translated'] ? 'Translated' : 'Non Translation') . '</td>';
            echo '<td>' . $book['total_produced'] . '</td>';
            echo '<td>' . $book['total_openpcs'] . '</td>';
            echo '<td>' . ($book['total_produced'] + $book['total_openpcs']) . '</td>';
            echo '</tr>';
        }
        
        // Add totals row
        echo '<tr style="background-color: #f0f0f0; font-weight: bold;">';
        echo '<td></td><td>TOTAL</td><td></td><td></td><td></td><td></td>';
        echo '<td>' . $total_produced . '</td>';
        echo '<td>' . $total_openpcs . '</td>';
        echo '<td>' . $net_production . '</td>';
        echo '</tr>';
        
        echo '</table>';
        exit;
    }
}
?>

<style>
.filter-container {
    background-color: #f8f9fa;
    padding: 15px;
    border-radius: 6px;
    margin-bottom: 15px;
    border: 1px solid #dee2e6;
}

.filter-row {
    display: flex;
    flex-wrap: wrap;
    gap: 12px;
    align-items: end;
    margin-bottom: 12px;
}

.filter-group {
    display: flex;
    flex-direction: column;
    min-width: 140px;
}

.filter-group label {
    font-weight: bold;
    margin-bottom: 4px;
    color: #495057;
    font-size: 13px;
}

.filter-group input,
.filter-group select {
    padding: 6px 10px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 13px;
}

.filter-group select[multiple] {
    height: 80px;
}

.filter-actions {
    display: flex;
    gap: 8px;
    margin-top: 8px;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 13px;
    text-decoration: none;
    display: inline-block;
    text-align: center;
}

.btn-primary {
    background-color: #007bff;
    color: white;
}

.btn-secondary {
    background-color: #6c757d;
    color: white;
}

.btn-success {
    background-color: #28a745;
    color: white;
}

.btn-info {
    background-color: #17a2b8;
    color: white;
}

.btn:hover {
    opacity: 0.9;
}

.export-section {
    margin: 15px 0;
    padding: 12px;
    background-color: #e9ecef;
    border-radius: 5px;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 13px;
}

table th, table td {
    border: 1px solid #dee2e6;
    padding: 8px;
    text-align: left;
}

table th {
    background-color: #f8f9fa;
    font-weight: bold;
    font-size: 12px;
}

table tr:nth-child(even) {
    background-color: #f8f9fa;
}

.total-row {
    background-color: #e3f2fd !important;
    font-weight: bold;
    border-top: 2px solid #007bff;
}

.total-row td {
    background-color: #e3f2fd !important;
    font-weight: bold;
}

.summary-stats {
    display: flex;
    gap: 15px;
    margin: 15px 0;
    flex-wrap: wrap;
}

.stat-card {
    background-color: #fff;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 12px;
    min-width: 130px;
    text-align: center;
}

.stat-number {
    font-size: 20px;
    font-weight: bold;
    color: #007bff;
}

.stat-label {
    font-size: 12px;
    color: #6c757d;
    margin-top: 4px;
}

.multi-select-info {
    font-size: 11px;
    color: #6c757d;
    margin-top: 2px;
}

/* Compact Print Styles */
@media print {
    .filter-container,
    .export-section,
    .no-print {
        display: none !important;
    }
    
    body {
        font-size: 10px !important;
        margin: 0;
        padding: 0;
    }
    
    h2 {
        font-size: 14px !important;
        margin: 5px 0 !important;
        text-align: center;
    }
    
    table {
        width: 100% !important;
        font-size: 9px !important;
        border-collapse: collapse !important;
        margin: 0 !important;
    }
    
    table th, table td {
        padding: 3px 4px !important;
        border: 1px solid #000 !important;
        font-size: 9px !important;
        line-height: 1.1 !important;
    }
    
    table th {
        background-color: #f0f0f0 !important;
        font-weight: bold !important;
        text-align: center !important;
    }
    
    .total-row {
        background-color: #e0e0e0 !important;
        font-weight: bold !important;
        border-top: 2px solid #000 !important;
    }
    
    .total-row td {
        background-color: #e0e0e0 !important;
        font-weight: bold !important;
    }
    
    .summary-stats {
        display: flex !important;
        gap: 10px !important;
        margin: 5px 0 !important;
        flex-wrap: wrap !important;
    }
    
    .stat-card {
        padding: 5px !important;
        min-width: 80px !important;
        border: 1px solid #000 !important;
    }
    
    .stat-number {
        font-size: 12px !important;
    }
    
    .stat-label {
        font-size: 8px !important;
        margin-top: 2px !important;
    }
    
    .compact-col {
        max-width: 80px !important;
        word-wrap: break-word !important;
    }
    
    @page {
        size: A4 landscape;
        margin: 0.5cm;
    }
}
</style>

<h2>Books Production Summary Report</h2>

<!-- Filter Section -->
<div class="filter-container">
    <h4>Search Filters</h4>
    <form method="GET" action="">
        <div class="filter-row">
            <div class="filter-group">
                <label for="from_date_nep">From Date (Nepali):</label>
                <input type="text" id="from_date_nep" name="from_date_nep" value="<?= htmlspecialchars($from_date_nep) ?>" placeholder="YYYY.MM.DD" pattern="\d{4}\.\d{2}\.\d{2}">
                <div class="multi-select-info">Format: YYYY.MM.DD (e.g. 2081.01.15)</div>
            </div>
            
            <div class="filter-group">
                <label for="to_date_nep">To Date (Nepali):</label>
                <input type="text" id="to_date_nep" name="to_date_nep" value="<?= htmlspecialchars($to_date_nep) ?>" placeholder="YYYY.MM.DD" pattern="\d{4}\.\d{2}\.\d{2}">
                <div class="multi-select-info">Format: YYYY.MM.DD (e.g. 2081.12.30)</div>
            </div>
            
            <div class="filter-group">
                <label for="fiscal_year">Fiscal Year:</label>
                <select id="fiscal_year" name="fiscal_year">
                    <option value="">All Years</option>
                    <?php foreach ($fiscal_years as $fy): ?>
                        <option value="<?= $fy ?>" <?= $fiscal_year == $fy ? 'selected' : '' ?>><?= $fy ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        
        <div class="filter-row">
            <div class="filter-group">
                <label for="book_filter">Book Name:</label>
                <input type="text" id="book_filter" name="book_filter" value="<?= htmlspecialchars($book_filter) ?>" placeholder="Search book name...">
            </div>
            
            <div class="filter-group">
                <label for="book_code_filter">Book Code:</label>
                <select id="book_code_filter" name="book_code_filter[]" multiple>
                    <?php foreach ($book_codes as $book_code): ?>
                        <option value="<?= $book_code['book_code'] ?>" <?= in_array($book_code['book_code'], $book_code_filter) ? 'selected' : '' ?>>
                            <?= $book_code['book_code'] ?> - <?= htmlspecialchars($book_code['book_name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="multi-select-info">Hold Ctrl/Cmd to select multiple</div>
            </div>
            
            <div class="filter-group">
                <label for="class_filter">Class Level:</label>
                <select id="class_filter" name="class_filter[]" multiple>
                    <?php foreach ($classes as $class): ?>
                        <option value="<?= $class ?>" <?= in_array($class, $class_filter) ? 'selected' : '' ?>><?= $class ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="multi-select-info">Hold Ctrl/Cmd to select multiple</div>
            </div>
            
            <div class="filter-group">
                <label for="type_filter">Type:</label>
                <select id="type_filter" name="type_filter">
                    <option value="">All Types</option>
                    <option value="translated" <?= $type_filter == 'translated' ? 'selected' : '' ?>>Translated</option>
                    <option value="non_translated" <?= $type_filter == 'non_translated' ? 'selected' : '' ?>>Non Translation</option>
                </select>
            </div>
        </div>
        
        <div class="filter-actions">
            <button type="submit" class="btn btn-primary">Apply Filters</button>
            <a href="?" class="btn btn-secondary">Clear Filters</a>
        </div>
    </form>
</div>

<!-- Summary Statistics -->
<div class="summary-stats">
    <div class="stat-card">
        <div class="stat-number"><?= $total_books ?></div>
        <div class="stat-label">Total Books</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= number_format($total_produced) ?></div>
        <div class="stat-label">Total Produced</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= number_format($total_openpcs) ?></div>
        <div class="stat-label">Total Openpcs</div>
    </div>
    <div class="stat-card">
        <div class="stat-number"><?= number_format($net_production) ?></div>
        <div class="stat-label">Total Grand Production</div>
    </div>
</div>

<!-- Export Section -->
<div class="export-section no-print">
    <h4>Export Options</h4>
    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'csv'])) ?>" class="btn btn-success">
            📊 Download CSV
        </a>
        <a href="?<?= http_build_query(array_merge($_GET, ['export' => 'excel'])) ?>" class="btn btn-info">
            📈 Download Excel
        </a>
        <button onclick="window.print()" class="btn btn-secondary">
            🖨️ Print Report
        </button>
    </div>
</div>

<!-- Results Table -->
<table>
    <thead>
        <tr> 
            <th>SN</th>
            <th class="compact-col">Book Name</th>
            <th>Code</th>
            <th>Class</th>
            <th>FY</th>
            <th>Type</th>
            <th>Produced</th>
            <th>OpenPcs</th>
            <th>TotalNet</th>
        </tr>
    </thead>
    <tbody>
        <?php if (empty($books)): ?>
            <tr>
                <td colspan="9" style="text-align: center; color: #6c757d; padding: 30px;">
                    No books found matching the selected criteria.
                </td>
            </tr>
        <?php else: ?>
            <?php 
            $sn = 1;
            foreach ($books as $book): ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td class="compact-col"><?= htmlspecialchars($book['book_name']) ?></td>
                <td><?= htmlspecialchars($book['book_code']) ?></td>
                <td><?= $book['class_level'] ?></td>
                <td><?= $book['book_fiscal_year'] ?></td>
                <td><?= $book['is_translated'] == 't' || $book['is_translated'] === true ? 'T' : 'NT' ?></td>
                <td><?= number_format($book['total_produced']) ?></td>
                <td><?= number_format($book['total_openpcs']) ?></td>
                <td><?= number_format($book['total_produced'] + $book['total_openpcs']) ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Totals Row -->
            <tr class="total-row">
                <td></td>
                <td><strong>TOTAL</strong></td>
                <td></td>
                <td></td>
                <td></td>
                <td></td>
                <td><strong><?= number_format($total_produced) ?></strong></td>
                <td><strong><?= number_format($total_openpcs) ?></strong></td>
                <td><strong><?= number_format($net_production) ?></strong></td>
            </tr>
        <?php endif; ?>
    </tbody>
</table>

<!-- Additional Print Information -->
<div style="margin-top: 20px; font-size: 12px; color: #6c757d;" class="no-print">
    <p><strong>Applied Filters:</strong>
    <?php if (!empty($from_date_nep) || !empty($to_date_nep)): ?>
        Date Range (Nepali): <?= $from_date_nep ?: 'Start' ?> to <?= $to_date_nep ?: 'End' ?> |
    <?php endif; ?>
    <?php if (!empty($fiscal_year)): ?>
        Fiscal Year: <?= $fiscal_year ?> |
    <?php endif; ?>
    <?php if (!empty($book_filter)): ?>
        Book: "<?= htmlspecialchars($book_filter) ?>" |
    <?php endif; ?>
    <?php if (!empty($book_code_filter)): ?>
        Book Codes: <?= implode(', ', $book_code_filter) ?> |
    <?php endif; ?>
    <?php if (!empty($class_filter)): ?>
        Classes: <?= implode(', ', $class_filter) ?> |
    <?php endif; ?>
    <?php if (!empty($type_filter)): ?>
        Type: <?= ucfirst(str_replace('_', ' ', $type_filter)) ?> |
    <?php endif; ?>
    <?php if (empty($from_date_nep) && empty($to_date_nep) && empty($fiscal_year) && empty($book_filter) && empty($book_code_filter) && empty($class_filter) && empty($type_filter)): ?>
        No filters applied - Showing all records
    <?php endif; ?>
    </p>
    <p><strong>Generated on:</strong> <?= date('Y-m-d H:i:s') ?> | <strong>Total Records:</strong> <?= $total_books ?></p>
</div>

<script>
// Auto-submit form when fiscal year changes
document.getElementById('fiscal_year').addEventListener('change', function() {
    if (this.value !== '') {
        // Clear date fields when fiscal year is selected
        document.getElementById('from_date_nep').value = '';
        document.getElementById('to_date_nep').value = '';
    }
});

// Date format validation
document.getElementById('from_date_nep').addEventListener('input', function() {
    var value = this.value;
    if (value && !value.match(/^\d{4}\.\d{2}\.\d{2}$/)) {
        this.setCustomValidity('Please use format YYYY.MM.DD (e.g. 2081.01.15)');
    } else {
        this.setCustomValidity('');
    }
});

document.getElementById('to_date_nep').addEventListener('input', function() {
    var value = this.value;
    if (value && !value.match(/^\d{4}\.\d{2}\.\d{2}$/)) {
        this.setCustomValidity('Please use format YYYY.MM.DD (e.g. 2081.12.30)');
    } else {
        this.setCustomValidity('');
    }
});

// Enhance multi-select functionality
document.getElementById('class_filter').addEventListener('change', function() {
    var selected = Array.from(this.selectedOptions).map(option => option.text);
    if (selected.length > 0) {
        console.log('Selected classes:', selected.join(', '));
    }
});

document.getElementById('book_code_filter').addEventListener('change', function() {
    var selected = Array.from(this.selectedOptions).map(option => option.value);
    if (selected.length > 0) {
        console.log('Selected book codes:', selected.join(', '));
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
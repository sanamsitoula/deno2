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
}?><?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Date range filters
$from_date = $_GET['from_date'] ?? date('Y.m.01');
$to_date = $_GET['to_date'] ?? date('Y.m.d');

// Get translated/non-translated report
$type = $_GET['type'] ?? 'all';

$query = "
    SELECT 
        b.book_name,
        b.book_code,
        b.class_level,
        d.deno_date_nep,
         d.deno_date_eng,
        d.total_qty,
        d.quantity_openpcs,
        (d.total_qty - d.quantity_openpcs) as net_production
    FROM Deno d
    JOIN Books b ON d.book_code = b.book_code
    WHERE d.deno_date_nep BETWEEN :from_date AND :to_date OR d.deno_date_eng BETWEEN :from_date AND :to_date
";

if ($type === 'translated') {
    $query .= " AND b.is_translated = TRUE";
} elseif ($type === 'Non Translation') {
    $query .= " AND b.is_translated = FALSE";
}

$query .= " ORDER BY d.deno_date_nep, b.book_name";

$stmt = $conn->prepare($query);
$stmt->execute([':from_date' => $from_date, ':to_date' => $to_date]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_qty = array_sum(array_column($records, 'total_qty'));
$total_openpcs = array_sum(array_column($records, 'quantity_openpcs'));
?>

<h2>Translated/Non Translation Books Report</h2>
<form method="get" class="report-filter">
    <div>
        <label for="type">Report Type:</label>
        <select name="type" id="type">
            <option value="all" <?= $type === 'all' ? 'selected' : '' ?>>All Books</option>
            <option value="translated" <?= $type === 'translated' ? 'selected' : '' ?>>Translated Only</option>
            <option value="Non Translation" <?= $type === 'Non Translation' ? 'selected' : '' ?>>Non Translation Only</option>
        </select>
    </div>
    
    <div>
        <label for="from_date">From Date (YYYY.MM.DD):</label>
        <input type="text" name="from_date" id="from_date" value="<?= htmlspecialchars($from_date) ?>" pattern="\d{4}\.\d{2}\.\d{2}">
    </div>
    
    <div>
        <label for="to_date">To Date (YYYY.MM.DD):</label>
        <input type="text" name="to_date" id="to_date" value="<?= htmlspecialchars($to_date) ?>" pattern="\d{4}\.\d{2}\.\d{2}">
    </div>
    
    <button type="submit">Generate</button>
</form>

<div class="report-summary">
    <p>Date Range: <?= $from_date ?> to <?= $to_date ?></p>
    <p>Report Type: <?= ucfirst($type) ?> Books</p>
    <p>Total Produced: <?= $total_qty ?></p>
    <p>Total Defective: <?= $total_openpcs ?></p>
    <p>Net Production: <?= $total_qty + $total_openpcs ?></p>
</div>

<table>
    <thead>
        <tr>
            <th>SN</th>
            
            <th>Date</th>
              <th>Date-EN</th>
            <th>Book</th>
            <th>Code</th>
            <th>Class</th>
            <th>Total Qty</th>
            <th>Defective</th>
            <th>Net Production</th>
        </tr>
    </thead>
    <tbody>
        
        <?php 
           $sn = 1; // Initialize serial number
        foreach ($records as $record): ?>
        <tr>
            <td><?= $sn++ ?></td> <!-- Auto-incrementing SN -->
            <td><?= $record['deno_date_nep'] ?></td>
              <td><?= $record['deno_date_eng'] ?></td>
            <td><?= htmlspecialchars($record['book_name']) ?></td>
            <td><?= htmlspecialchars($record['book_code']) ?></td>
            <td><?= $record['class_level'] ?></td>
            <td><?= $record['total_qty'] ?></td>
            <td><?= $record['quantity_openpcs'] ?></td>
            <td><?= $record['net_production'] ?></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php';  ?>
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
<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('book_search');
    const hiddenInput = document.getElementById('book_code');
    const optionsContainer = document.getElementById('book_options');
    const options = optionsContainer.querySelectorAll('.dropdown-option');
    
    // Set initial value if editing
    <?php if ($edit_record): ?>
    const editBookCode = "<?= $edit_record['book_code'] ?>";
    const editOption = document.querySelector(`[data-value="${editBookCode}"]`);
    if (editOption) {
        searchInput.value = editOption.dataset.text;
        hiddenInput.value = editBookCode;
    }
    <?php endif; ?>
    
    // Show/hide dropdown
    searchInput.addEventListener('focus', function() {
        optionsContainer.style.display = 'block';
        filterOptions();
    });
    
    searchInput.addEventListener('input', function() {
        filterOptions();
        optionsContainer.style.display = 'block';
    });
    
    // Hide dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-dropdown')) {
            optionsContainer.style.display = 'none';
        }
    });
    
    // Filter options based on search
    function filterOptions() {
        const searchTerm = searchInput.value.toLowerCase();
        
        options.forEach(option => {
            const text = option.textContent.toLowerCase();
            const bookCode = option.dataset.value.toLowerCase();
            
            if (text.includes(searchTerm) || bookCode.includes(searchTerm)) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    // Handle option selection
    options.forEach(option => {
        option.addEventListener('click', function() {
            searchInput.value = this.dataset.text;
            hiddenInput.value = this.dataset.value;
            optionsContainer.style.display = 'none';
        });
    });
    
    // Calculate total quantity automatically
    const perPokaQty = document.getElementById('per_poka_qty');
    const pokaQty = document.getElementById('poka_qty');
    
    function calculateTotal() {
        const per = parseInt(perPokaQty.value) || 0;
        const qty = parseInt(pokaQty.value) || 0;
        const total = per * qty;
        
        // You can display this somewhere if needed
        console.log('Total Quantity:', total);
    }
    
    perPokaQty.addEventListener('input', calculateTotal);
    pokaQty.addEventListener('input', calculateTotal);
});

// CSV Download Function
function downloadCSV() {
    const table = document.querySelector('.table');
    const rows = Array.from(table.querySelectorAll('tr'));
    
    let csvContent = "data:text/csv;charset=utf-8,";
    
    rows.forEach(row => {
        const cols = Array.from(row.querySelectorAll('th, td'));
        const csvRow = cols.map(col => {
            let cellData = col.textContent.trim();
            // Remove action buttons from CSV
            if (cellData.includes('Edit') && cellData.includes('Delete')) {
                cellData = '';
            }
            // Escape quotes and wrap in quotes if contains comma
            if (cellData.includes(',') || cellData.includes('"')) {
                cellData = '"' + cellData.replace(/"/g, '""') + '"';
            }
            return cellData;
        }).join(',');
        csvContent += csvRow + "\r\n";
    });
    
    const encodedUri = encodeURI(csvContent);
    const link = document.createElement("a");
    link.setAttribute("href", encodedUri);
    link.setAttribute("download", "deno_records_" + new Date().toISOString().split('T')[0] + ".csv");
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script><?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Handle Excel export
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="deno_records_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');
    
    $records = $conn->query("
        SELECT d.*, b.book_name 
        FROM Deno d 
        LEFT JOIN Books b ON d.book_code = b.book_code 
        ORDER BY d.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);
    
    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Book Name</th><th>Book Code</th><th>Ref No</th><th>Nepali Date</th><th>English Date</th><th>Per Poka Qty</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th><th>Created By</th><th>Received By</th><th>Verified By</th><th>Notes</th><th>Created At</th></tr>";
    
    foreach ($records as $record) {
        echo "<tr>";
        echo "<td>" . $record['id'] . "</td>";
        echo "<td>" . $record['book_name'] . "</td>";
        echo "<td>" . $record['book_code'] . "</td>";
        echo "<td>" . $record['ref_no'] . "</td>";
        echo "<td>" . $record['deno_date_nep'] . "</td>";
        echo "<td>" . $record['deno_date_eng'] . "</td>";
        echo "<td>" . $record['per_poka_qty'] . "</td>";
        echo "<td>" . $record['poka_qty'] . "</td>";
        echo "<td>" . $record['total_qty'] . "</td>";
        echo "<td>" . $record['quantity_openpcs'] . "</td>";
        echo "<td>" . $record['created_by'] . "</td>";
        echo "<td>" . $record['received_by'] . "</td>";
        echo "<td>" . $record['verify_by'] . "</td>";
        echo "<td>" . $record['notes'] . "</td>";
        echo "<td>" . date('Y-m-d H:i', strtotime($record['created_at'])) . "</td>";
        echo "</tr>";
    }
    echo "</table>";
    exit;
}

// Handle CRUD operations
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    try {
        switch ($action) {
            case 'create':
                // Check for duplicate ref_no with different dates
                $check_stmt = $conn->prepare("
                    SELECT deno_date_nep FROM Deno 
                    WHERE ref_no = :ref_no AND deno_date_nep != :deno_date_nep
                    LIMIT 1
                ");
                $check_stmt->execute([
                    ':ref_no' => $_POST['ref_no'],
                    ':deno_date_nep' => $_POST['deno_date_nep']
                ]);
                
                if ($check_stmt->fetch()) {
                    echo "<div class='alert alert-danger'>Error: Reference number " . $_POST['ref_no'] . " already exists with a different date. Please use consistent dates for the same reference number.</div>";
                    break;
                }
                
                $stmt = $conn->prepare("
                    INSERT INTO Deno (
                        book_code, ref_no, deno_date_nep, deno_date_eng,
                        per_poka_qty, poka_qty, quantity_openpcs, notes,
                        created_by, received_by, updated_by, verify_by, update_remarks
                    ) VALUES (
                        :book_code, :ref_no, :deno_date_nep, :deno_date_eng,
                        :per_poka_qty, :poka_qty, :quantity_openpcs, :notes,
                        :created_by, :received_by, :updated_by, :verify_by, :update_remarks
                    )
                ");
                
                $stmt->execute([
                    ':book_code' => $_POST['book_code'],
                    ':ref_no' => $_POST['ref_no'],
                    ':deno_date_nep' => $_POST['deno_date_nep'],
                    ':deno_date_eng' => $_POST['deno_date_eng'],
                    ':per_poka_qty' => $_POST['per_poka_qty'],
                    ':poka_qty' => $_POST['poka_qty'],
                    ':quantity_openpcs' => $_POST['quantity_openpcs'] ?? 0,
                    ':notes' => $_POST['notes'],
                    ':created_by' => $_POST['created_by'],
                    ':received_by' => $_POST['received_by'] ?: null,
                    ':updated_by' => $_POST['updated_by'] ?: null,
                    ':verify_by' => $_POST['verify_by'] ?: null,
                    ':update_remarks' => $_POST['update_remarks']
                ]);
                
                echo "<div class='alert alert-success'>Deno record added successfully!</div>";
                break;
                
            case 'update':
                // Check for duplicate ref_no with different dates (excluding current record)
                $check_stmt = $conn->prepare("
                    SELECT deno_date_nep FROM Deno 
                    WHERE ref_no = :ref_no AND deno_date_nep != :deno_date_nep AND id != :id
                    LIMIT 1
                ");
                $check_stmt->execute([
                    ':ref_no' => $_POST['ref_no'],
                    ':deno_date_nep' => $_POST['deno_date_nep'],
                    ':id' => $_POST['id']
                ]);
                
                if ($check_stmt->fetch()) {
                    echo "<div class='alert alert-danger'>Error: Reference number " . $_POST['ref_no'] . " already exists with a different date. Please use consistent dates for the same reference number.</div>";
                    break;
                }
                
                $stmt = $conn->prepare("
                    UPDATE Deno SET 
                        book_code = :book_code, ref_no = :ref_no, 
                        deno_date_nep = :deno_date_nep, deno_date_eng = :deno_date_eng,
                        per_poka_qty = :per_poka_qty, poka_qty = :poka_qty, 
                        quantity_openpcs = :quantity_openpcs, notes = :notes,
                        received_by = :received_by, updated_by = :updated_by, 
                        verify_by = :verify_by, update_remarks = :update_remarks,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ");
                
                $stmt->execute([
                    ':id' => $_POST['id'],
                    ':book_code' => $_POST['book_code'],
                    ':ref_no' => $_POST['ref_no'],
                    ':deno_date_nep' => $_POST['deno_date_nep'],
                    ':deno_date_eng' => $_POST['deno_date_eng'],
                    ':per_poka_qty' => $_POST['per_poka_qty'],
                    ':poka_qty' => $_POST['poka_qty'],
                    ':quantity_openpcs' => $_POST['quantity_openpcs'] ?? 0,
                    ':notes' => $_POST['notes'],
                    ':received_by' => $_POST['received_by'] ?: null,
                    ':updated_by' => $_POST['updated_by'] ?: null,
                    ':verify_by' => $_POST['verify_by'] ?: null,
                    ':update_remarks' => $_POST['update_remarks']
                ]);
                
                echo "<div class='alert alert-success'>Deno record updated successfully!</div>";
                break;
                
            case 'delete':
                // Soft delete - you can add a deleted_at column or is_deleted flag
                $stmt = $conn->prepare("DELETE FROM Deno WHERE id = :id");
                $stmt->execute([':id' => $_POST['id']]);
                
                echo "<div class='alert alert-success'>Deno record deleted successfully!</div>";
                break;
        }
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}

// Fetch books for dropdown
$books = $conn->query("SELECT book_code, book_name FROM Books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

// Fetch latest Deno records with book details
$deno_records = $conn->query("
    SELECT d.*, b.book_name 
    FROM Deno d 
    LEFT JOIN Books b ON d.book_code = b.book_code 
    ORDER BY d.created_at DESC 
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing if edit_id is provided
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM Deno WHERE id = :id");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<style>
body {
    font-size: 16px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
}

.form-container {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 30px;
}

.form-row {
    display: flex;
    gap: 15px;
    margin-bottom: 15px;
    align-items: end;
}

.form-group {
    flex: 1;
    min-width: 200px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
    font-size: 15px;
}

.form-control {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 15px;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,.25);
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    text-align: center;
    margin-right: 8px;
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

.btn-warning {
    background-color: #ffc107;
    color: #212529;
}

.btn-danger {
    background-color: #dc3545;
    color: white;
}

.btn-info {
    background-color: #17a2b8;
    color: white;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 13px;
    margin-right: 4px;
}

.action-buttons {
    margin-bottom: 20px;
    display: flex;
    gap: 10px;
    align-items: center;
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
    font-size: 14px;
}

.table th,
.table td {
    padding: 10px 8px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
    vertical-align: middle;
}

.table th {
    background-color: #f8f9fa;
    font-weight: 700;
    color: #495057;
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.table tbody tr:hover {
    background-color: #f5f5f5;
}

.table tbody tr:nth-child(even) {
    background-color: #fafafa;
}

.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
    font-size: 15px;
    font-weight: 500;
}

.alert-success {
    color: #155724;
    background-color: #d4edda;
    border-color: #c3e6cb;
}

.alert-danger {
    color: #721c24;
    background-color: #f8d7da;
    border-color: #f5c6cb;
}

.search-dropdown {
    position: relative;
}

.dropdown-search {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 15px;
}

.dropdown-options {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    max-height: 200px;
    overflow-y: auto;
    z-index: 1000;
    display: none;
}

.dropdown-option {
    padding: 12px 14px;
    cursor: pointer;
    border-bottom: 1px solid #eee;
    font-size: 14px;
}

.dropdown-option:hover {
    background-color: #f8f9fa;
}

.dropdown-option:last-child {
    border-bottom: none;
}

/* Print Styles */
@media print {
    body {
        font-size: 11px;
        line-height: 1.2;
    }
    
    .form-container,
    .action-buttons,
    .btn {
        display: none !important;
    }
    
    h2, h3 {
        font-size: 14px;
        margin: 10px 0;
    }
    
    .table {
        font-size: 9px;
        width: 100%;
    }
    
    .table th,
    .table td {
        padding: 3px 2px;
        border: 1px solid #000;
        font-size: 8px;
    }
    
    .table th {
        background-color: #f0f0f0 !important;
        font-weight: bold;
    }
    
    .table-container {
        box-shadow: none;
        border: 1px solid #000;
    }
    
    .alert {
        display: none !important;
    }
    
    @page {
        margin: 0.5in;
        size: A4 landscape;
    }
}

/* Compact table for better data display */
.table td:nth-child(1) { width: 40px; } /* ID */
.table td:nth-child(2) { width: 200px; } /* Book */
.table td:nth-child(3) { width: 80px; } /* Ref No */
.table td:nth-child(4) { width: 90px; } /* Date */
.table td:nth-child(5) { width: 70px; } /* Per Poka */
.table td:nth-child(6) { width: 70px; } /* Poka Qty */
.table td:nth-child(7) { width: 80px; } /* Total */
.table td:nth-child(8) { width: 70px; } /* Defective */
.table td:nth-child(9) { width: 80px; } /* Created By */
.table td:nth-child(10) { width: 120px; } /* Created At */
.table td:nth-child(11) { width: 120px; } /* Actions */
</style>

<h2><?= $edit_record ? 'Edit Deno Entry' : 'Add Deno Entry' ?></h2>

<div class="form-container">
    <form method="post" id="denoForm">
        <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
        <?php if ($edit_record): ?>
            <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
        <?php endif; ?>
        
        <div class="form-row">
            <div class="form-group">
                <label for="book_code">Book:</label>
                <div class="search-dropdown">
                    <input type="text" 
                           class="form-control dropdown-search" 
                           id="book_search" 
                           placeholder="Search book..."
                           autocomplete="off">
                    <input type="hidden" name="book_code" id="book_code" value="<?= $edit_record['book_code'] ?? '' ?>">
                    <div class="dropdown-options" id="book_options">
                        <?php foreach ($books as $book): ?>
                            <div class="dropdown-option" 
                                 data-value="<?= $book['book_code'] ?>"
                                 data-text="<?= $book['book_name'] ?> (<?= $book['book_code'] ?>)">
                                <?= $book['book_name'] ?> (<?= $book['book_code'] ?>)
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
            
            <div class="form-group">
                <label for="ref_no">Reference No:</label>
                <input type="text" name="ref_no" id="ref_no" class="form-control" 
                       value="<?= $edit_record['ref_no'] ?? '' ?>" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="deno_date_nep">Nepali Date (YYYY.MM.DD):</label>
                <input type="text" name="deno_date_nep" id="deno_date_nep" class="form-control" 
                       pattern="\d{4}\.\d{2}\.\d{2}" 
                       value="<?= $edit_record['deno_date_nep'] ?? '' ?>" required>
            </div>
            
            <div class="form-group">
                <label for="deno_date_eng">English Date (YYYY.MM.DD):</label>
                <input type="text" name="deno_date_eng" id="deno_date_eng" class="form-control" 
                       pattern="\d{4}\.\d{2}\.\d{2}" 
                       value="<?= $edit_record['deno_date_eng'] ?? '' ?>" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="per_poka_qty">Quantity per Poka:</label>
                <input type="number" name="per_poka_qty" id="per_poka_qty" class="form-control" 
                       value="<?= $edit_record['per_poka_qty'] ?? '' ?>" required>
            </div>
            
            <div class="form-group">
                <label for="poka_qty">Number of Pokas:</label>
                <input type="number" name="poka_qty" id="poka_qty" class="form-control" 
                       value="<?= $edit_record['poka_qty'] ?? '' ?>" required>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="quantity_openpcs">Open Pieces:</label>
                <input type="number" name="quantity_openpcs" id="quantity_openpcs" class="form-control" 
                       value="<?= $edit_record['quantity_openpcs'] ?? '0' ?>">
            </div>
            
            <div class="form-group">
                <label for="created_by">Created By:</label>
                <select name="created_by" id="created_by" class="form-control" required>
                    <option value="usha" <?= ($edit_record['created_by'] ?? '') === 'usha' ? 'selected' : '' ?>>Usha</option>
                    <option value="sanam" <?= ($edit_record['created_by'] ?? '') === 'sanam' ? 'selected' : '' ?>>Sanam</option>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="received_by">Received By:</label>
                <select name="received_by" id="received_by" class="form-control">
                    <option value="">Select</option>
                    <option value="sarala" <?= ($edit_record['received_by'] ?? '') === 'sarala' ? 'selected' : '' ?>>Sarala</option>
                    <option value="dambar" <?= ($edit_record['received_by'] ?? '') === 'dambar' ? 'selected' : '' ?>>Dambar</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="verify_by">Verified By:</label>
                <select name="verify_by" id="verify_by" class="form-control">
                    <option value="">Select</option>
                    <option value="ram" <?= ($edit_record['verify_by'] ?? '') === 'ram' ? 'selected' : '' ?>>Ram</option>
                    <option value="shyam" <?= ($edit_record['verify_by'] ?? '') === 'shyam' ? 'selected' : '' ?>>Shyam</option>
                </select>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="updated_by">Updated By:</label>
                <select name="updated_by" id="updated_by" class="form-control">
                    <option value="">Select</option>
                    <option value="usha" <?= ($edit_record['updated_by'] ?? '') === 'usha' ? 'selected' : '' ?>>Usha</option>
                    <option value="sanam" <?= ($edit_record['updated_by'] ?? '') === 'sanam' ? 'selected' : '' ?>>Sanam</option>
                </select>
            </div>
            
            <div class="form-group">
                <label for="notes">Notes:</label>
                <textarea name="notes" id="notes" class="form-control" rows="1"><?= $edit_record['notes'] ?? '' ?></textarea>
            </div>
        </div>
        
        <div class="form-row">
            <div class="form-group">
                <label for="update_remarks">Update Remarks:</label>
                <textarea name="update_remarks" id="update_remarks" class="form-control" rows="1"><?= $edit_record['update_remarks'] ?? '' ?></textarea>
            </div>
            
            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <?= $edit_record ? 'Update Deno' : 'Save Deno' ?>
                </button>
                <?php if ($edit_record): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>

<h3>Latest Deno Records</h3>

<div class="action-buttons">
    <button onclick="window.print()" class="btn btn-info">🖨️ Print</button>
    <a href="?export=excel" class="btn btn-success">📊 Export to Excel</a>
    <button onclick="downloadCSV()" class="btn btn-secondary">📥 Download CSV</button>
</div>

<div class="table-container">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Book</th>
                <th>Ref No</th>
                <th>Nepali Date</th>
                <th>Per Poka Qty</th>
                <th>Poka Qty</th>
                <th>Total Qty</th>
                <th>Open Pcs</th>
                <th>Created By</th>
                <th>Created At</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($deno_records as $record): ?>
            <tr>
                <td><?= $record['id'] ?></td>
                <td><?= $record['book_name'] ?> (<?= $record['book_code'] ?>)</td>
                <td><?= $record['ref_no'] ?></td>
                <td><?= $record['deno_date_nep'] ?></td>
                <td><?= number_format($record['per_poka_qty']) ?></td>
                <td><?= number_format($record['poka_qty']) ?></td>
                <td><?= number_format($record['total_qty']) ?></td>
                <td><?= number_format($record['quantity_openpcs']) ?></td>
                <td><?= ucfirst($record['created_by']) ?></td>
                <td><?= date('Y-m-d H:i', strtotime($record['created_at'])) ?></td>
                <td>
                    <a href="?edit_id=<?= $record['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <form method="post" style="display: inline;" 
                          onsubmit="return confirm('Are you sure you want to delete this record?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $record['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const searchInput = document.getElementById('book_search');
    const hiddenInput = document.getElementById('book_code');
    const optionsContainer = document.getElementById('book_options');
    const options = optionsContainer.querySelectorAll('.dropdown-option');
    
    // Set initial value if editing
    <?php if ($edit_record): ?>
    const editBookCode = "<?= $edit_record['book_code'] ?>";
    const editOption = document.querySelector(`[data-value="${editBookCode}"]`);
    if (editOption) {
        searchInput.value = editOption.dataset.text;
        hiddenInput.value = editBookCode;
    }
    <?php endif; ?>
    
    // Show/hide dropdown
    searchInput.addEventListener('focus', function() {
        optionsContainer.style.display = 'block';
        filterOptions();
    });
    
    searchInput.addEventListener('input', function() {
        filterOptions();
        optionsContainer.style.display = 'block';
    });
    
    // Hide dropdown when clicking outside
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-dropdown')) {
            optionsContainer.style.display = 'none';
        }
    });
    
    // Filter options based on search
    function filterOptions() {
        const searchTerm = searchInput.value.toLowerCase();
        
        options.forEach(option => {
            const text = option.textContent.toLowerCase();
            const bookCode = option.dataset.value.toLowerCase();
            
            if (text.includes(searchTerm) || bookCode.includes(searchTerm)) {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    // Handle option selection
    options.forEach(option => {
        option.addEventListener('click', function() {
            searchInput.value = this.dataset.text;
            hiddenInput.value = this.dataset.value;
            optionsContainer.style.display = 'none';
        });
    });
    
    // Calculate total quantity automatically
    const perPokaQty = document.getElementById('per_poka_qty');
    const pokaQty = document.getElementById('poka_qty');
    
    function calculateTotal() {
        const per = parseInt(perPokaQty.value) || 0;
        const qty = parseInt(pokaQty.value) || 0;
        const total = per * qty;
        
        // You can display this somewhere if needed
        console.log('Total Quantity:', total);
    }
    
    perPokaQty.addEventListener('input', calculateTotal);
    pokaQty.addEventListener('input', calculateTotal);
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
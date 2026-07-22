<?php
// jobticket/edit.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';

// Check authentication and permissions before any output
redirect_if_not_logged_in();

if (!has_role('editor') && !has_role('admin')) {
    redirectWithError("You don't have permission to perform this action.", 'index.php');
}

$currentUserId = $_SESSION['user_id'] ?? 0;

if (!isset($_GET['id'])) {
    redirectWithError("Invalid request.", 'index.php');
}
$id = intval($_GET['id']);

$books = getBooks($conn);
$machines = getMachines($conn);

$ticket = getJobTicketById($conn, $id);
if (!$ticket) {
    redirectWithError("Job Ticket not found!", 'index.php');
}

// Get formas associated with the current book
$associatedFormas = getFormasByBookId($conn, $ticket['book_id']);
$details = getJobTicketDetails($conn, $id);

// Get fiscal year info
$fiscalYearId = $ticket['fiscal_year_id'];

// Get lot history - PostgreSQL compatible version
function getLotHistory($conn, $bookId, $fiscalYearId) {
    $stmt = $conn->prepare("
        SELECT lot, print_qty, job_ticket_code 
        FROM job_ticket 
        WHERE book_id = ? AND fiscal_year_id = ? 
        ORDER BY CAST(lot AS INTEGER) ASC
    ");
    $stmt->execute([$bookId, $fiscalYearId]);
    return $stmt->fetchAll();
}

$lotHistory = getLotHistory($conn, $ticket['book_id'], $fiscalYearId);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        // Validate start and end dates
        if (isset($_POST['start_date']) && isset($_POST['end_date'])) {
            foreach ($_POST['start_date'] as $index => $startDate) {
                $endDate = $_POST['end_date'][$index] ?? '';
                if (!empty($startDate) && !empty($endDate)) {
                    if (strtotime($endDate) < strtotime($startDate)) {
                        throw new Exception("End date cannot be earlier than start date for row " . ($index + 1));
                    }
                }
            }
        }

        $conn->beginTransaction();

        // Calculate page_qty from forma details
        $totalPages = 0;
        if (isset($_POST['forma']) && is_array($_POST['forma'])) {
            foreach ($_POST['forma'] as $index => $formaId) {
                if (!empty($formaId)) {
                    $totalPages += intval($_POST['forma_page'][$index] ?? 0);
                }
            }
        }

        $stmt = $conn->prepare("UPDATE job_ticket SET
            book_id = ?, lot = ?, remarks = ?, description = ?,
            print_qty = ?, page_qty = ?, class = ?, date_nep = ?, date_eng = ?,
            updated_by = ?, updated_date = CURRENT_TIMESTAMP
            WHERE id = ?");
        $stmt->execute([
            $_POST['book_id'],
            $_POST['lot'],
            $_POST['remarks'],
            $_POST['description'],
            $_POST['print_qty'],
            $totalPages,
            $_POST['class'],
            $_POST['date_nep'],
            $_POST['date_eng'],
            $currentUserId,
            $id
        ]);

        // Handle job ticket details with foreign key constraint
        $referencedDetailsStmt = $conn->prepare("
            SELECT DISTINCT jtd.id 
            FROM job_ticket_details jtd 
            INNER JOIN forma_printing fp ON jtd.id = fp.jtd_id 
            WHERE jtd.job_ticket_id = ?
        ");
        $referencedDetailsStmt->execute([$id]);
        $referencedDetails = $referencedDetailsStmt->fetchAll(PDO::FETCH_COLUMN);

        // Delete only non-referenced details
        if (!empty($referencedDetails)) {
            $placeholders = str_repeat('?,', count($referencedDetails) - 1) . '?';
            $deleteStmt = $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ? AND id NOT IN ($placeholders)");
            $deleteStmt->execute(array_merge([$id], $referencedDetails));
            
            // Update existing referenced details
            foreach ($_POST['forma'] as $i => $formaId) {
                if (empty($formaId)) continue;
                
                if (isset($details[$i]) && in_array($details[$i]['id'], $referencedDetails)) {
                    $updateStmt = $conn->prepare("UPDATE job_ticket_details SET
                        order_no = ?, forma_id = ?, page = ?, old_forma_qty = ?, 
                        print_qty = ?, machine = ?, description = ?
                        WHERE id = ?");
                    $updateStmt->execute([
                        $i + 1,
                        $formaId,
                        $_POST['forma_page'][$i] ?? 0,
                        $_POST['old_qty'][$i] ?? 0,
                        $_POST['forma_print_qty'][$i] ?? 0,
                        $_POST['machine'][$i] ?? null,
                        $_POST['desc'][$i] ?? null,
                        $details[$i]['id']
                    ]);
                } else {
                    $conn->prepare("INSERT INTO job_ticket_details (
                        job_ticket_id, order_no, forma_id, page,
                        old_forma_qty, print_qty, machine, description
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $id,
                        $i + 1,
                        $formaId,
                        $_POST['forma_page'][$i] ?? 0,
                        $_POST['old_qty'][$i] ?? 0,
                        $_POST['forma_print_qty'][$i] ?? 0,
                        $_POST['machine'][$i] ?? null,
                        $_POST['desc'][$i] ?? null
                    ]);
                }
            }
        } else {
            $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ?")->execute([$id]);
            
            if (isset($_POST['forma']) && is_array($_POST['forma'])) {
                foreach ($_POST['forma'] as $i => $formaId) {
                    if (empty($formaId)) continue;
                    
                    $conn->prepare("INSERT INTO job_ticket_details (
                        job_ticket_id, order_no, forma_id, page,
                        old_forma_qty, print_qty, machine, description
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([
                        $id,
                        $i + 1,
                        $formaId,
                        $_POST['forma_page'][$i] ?? 0,
                        $_POST['old_qty'][$i] ?? 0,
                        $_POST['forma_print_qty'][$i] ?? 0,
                        $_POST['machine'][$i] ?? null,
                        $_POST['desc'][$i] ?? null
                    ]);
                }
            }
        }

        $conn->commit();
        redirectWithSuccess("Job Ticket updated successfully!", "view.php?id=$id");
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error'] = "Error updating job ticket: " . $e->getMessage();
    }
}

// Include header after all processing
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
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
    margin-bottom: 15px;
}
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
    font-size: 15px;
}
.form-group label .required {
    color: #dc3545;
}
.form-control, .form-select {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 15px;
}
.form-control:focus, .form-select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 2px rgba(0,123,255,.25);
}
.form-control:read-only {
    background-color: #e9ecef;
    cursor: not-allowed;
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
.btn-primary { background-color: #007bff; color: white; }
.btn-secondary { background-color: #6c757d; color: white; }
.btn-success { background-color: #28a745; color: white; }
.btn-warning { background-color: #ffc107; color: #212529; }
.btn-danger { background-color: #dc3545; color: white; }
.btn-info { background-color: #17a2b8; color: white; }
.btn-sm { padding: 6px 12px; font-size: 13px; margin-right: 4px; }
.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border: 1px solid transparent;
    border-radius: 4px;
    font-size: 15px;
    font-weight: 500;
}
.alert-success { color: #155724; background-color: #d4edda; border-color: #c3e6cb; }
.alert-danger { color: #721c24; background-color: #f8d7da; border-color: #f5c6cb; }
.card {
    background: white;
    border-radius: 8px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
    margin-bottom: 20px;
}
.card-header {
    padding: 15px 20px;
    background-color: #f8f9fa;
    border-bottom: 1px solid #dee2e6;
    border-radius: 8px 8px 0 0;
}
.card-body {
    padding: 20px;
}
.table-responsive {
    overflow-x: auto;
}
.table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}
.table th, .table td {
    padding: 8px 6px;
    text-align: left;
    border: 1px solid #dee2e6;
    vertical-align: middle;
}
.table th {
    background-color: #f8f9fa;
    font-weight: 700;
    color: #495057;
}
.table-bordered {
    border: 1px solid #dee2e6;
}
.lot-history {
    background-color: #f8f9fa;
    padding: 10px;
    border-radius: 4px;
    margin-top: 5px;
    font-size: 13px;
    color: #495057;
    border: 1px solid #dee2e6;
}
.lot-history-item {
    padding: 4px 0;
    border-bottom: 1px solid #dee2e6;
}
.lot-history-item:last-child {
    border-bottom: none;
}
.error-message {
    color: #dc3545;
    font-size: 14px;
    margin-top: 5px;
    display: none;
}
</style>

<div class="container">
    <h2>Edit Job Ticket</h2>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <form method="POST" id="jobTicketForm">
        <div class="card mb-4">
            <div class="card-header">
                <h4>Basic Information</h4>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="book_id" class="form-label">Book</label>
                            <select name="book_id" id="book_id" class="form-select" required disabled>
                                <option value="">Select a book</option>
                                <?php foreach ($books as $book): ?>
                                    <option value="<?= $book['book_id'] ?>"
                                        data-book-code="<?= htmlspecialchars($book['book_code']) ?>"
                                        data-class-level="<?= $book['class_level'] ?>"
                                        <?= ($ticket && $ticket['book_id'] == $book['book_id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($book['book_code']) ?> - <?= htmlspecialchars($book['book_name']) ?> (Class: <?= $book['class_level'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <input type="hidden" name="book_id" value="<?= $ticket['book_id'] ?>">
                        </div>
                        <div class="mb-3">
                            <label for="lot" class="form-label">Lot No</label>
                            <input type="text" name="lot" id="lot" class="form-control" readonly
                                value="<?= htmlspecialchars($ticket['lot']) ?>" required>
                            <?php if (!empty($lotHistory)): ?>
                                <div class="lot-history">
                                    <strong>Previous Lots:</strong>
                                    <?php foreach ($lotHistory as $lot): ?>
                                        <div class="lot-history-item">
                                            Lot <?= htmlspecialchars($lot['lot']) ?>: <?= number_format($lot['print_qty']) ?> pcs (<?= htmlspecialchars($lot['job_ticket_code']) ?>)
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </div>
                        <div class="mb-3">
                            <label for="remarks" class="form-label">Remarks</label>
                            <input type="text" name="remarks" id="remarks" class="form-control"
                                value="<?= htmlspecialchars($ticket['remarks']) ?>">
                        </div>
                        <div class="mb-3">
                            <label for="description" class="form-label">Description</label>
                            <textarea name="description" id="description" class="form-control" rows="3"><?= htmlspecialchars($ticket['description']) ?></textarea>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="print_qty" class="form-label">Print Qty</label>
                            <input type="number" name="print_qty" id="print_qty" class="form-control"
                                value="<?= $ticket['print_qty'] ?>" required min="1">
                        </div>
                        <div class="mb-3">
                            <label for="page_qty" class="form-label">Page Qty (Auto-calculated)</label>
                            <input type="number" name="page_qty" id="page_qty" class="form-control bg-light"
                                value="<?= $ticket['page_qty'] ?>" readonly>
                        </div>
                        <div class="mb-3">
                            <label for="class" class="form-label">Class</label>
                            <input type="number" name="class" id="class" class="form-control"
                                value="<?= $ticket['class'] ?>" required min="1" max="12">
                        </div>
                        <div class="row">
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="date_nep" class="form-label">Date (Nepali)</label>
                                    <input type="text" name="date_nep" id="date_nep" class="form-control"
                                        placeholder="YYYY-MM-DD"
                                        value="<?= htmlspecialchars($ticket['date_nep']) ?>" required>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="mb-3">
                                    <label for="date_eng" class="form-label">Date (English)</label>
                                    <input type="date" name="date_eng" id="date_eng" class="form-control"
                                        value="<?= htmlspecialchars($ticket['date_eng']) ?>" required>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <div class="card mb-4">
            <div class="card-header d-flex justify-content-between align-items-center">
                <h4>Forma Details</h4>
                <div>
                    <button type="button" id="addForma" class="btn btn-sm btn-primary">+ Add Forma</button>
                    <button type="button" id="resetFormas" class="btn btn-sm btn-secondary">Reset to 10</button>
                </div>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-bordered table-sm" id="formaTable">
                        <thead class="table-light">
                            <tr>
                                <th width="3%">Order No</th>
                                <th width="20%">Forma</th>
                                <th width="6%">Page</th>
                                <th width="10%">Old Forma Qty</th>
                                <th width="10%">Print Qty</th>
                                <th width="12%">Machine</th>
                                <th width="12%">Start Date</th>
                                <th width="12%">End Date</th>
                                <th width="12%">Description</th>
                                <th width="3%">×</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php
                            $rowsToDisplay = max(10, count($details));
                            for ($i = 0; $i < $rowsToDisplay; $i++):
                                $detail = $details[$i] ?? null;
                            ?>
                                <tr>
                                    <td class="text-center align-middle"><?= $i + 1 ?></td>
                                    <td>
                                        <select name="forma[]" class="form-select forma-select form-select-sm">
                                            <option value="">Select Forma</option>
                                            <?php foreach ($associatedFormas as $f): ?>
                                                <option value="<?= $f['id'] ?>"
                                                    <?= ($detail && $detail['forma_id'] == $f['id']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($f['name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="number" name="forma_page[]" class="form-control form-control-sm page-input"
                                            value="<?= $detail ? $detail['page'] : '32' ?>" min="0">
                                    </td>
                                    <td>
                                        <input type="number" name="old_qty[]" class="form-control form-control-sm old-qty-input"
                                            value="<?= $detail ? $detail['old_forma_qty'] : '0' ?>" min="0">
                                    </td>
                                    <td>
                                        <input type="number" name="forma_print_qty[]" class="form-control form-control-sm print-qty-input"
                                            value="<?= $detail ? $detail['print_qty'] : $ticket['print_qty'] ?>" min="0">
                                    </td>
                                    <td>
                                        <select name="machine[]" class="form-select form-select-sm">
                                            <option value="">Select Machine</option>
                                            <?php foreach ($machines as $m): ?>
                                                <option value="<?= htmlspecialchars($m['machine_name']) ?>"
                                                    <?= ($detail && $detail['machine'] == $m['machine_name']) ? 'selected' : '' ?>>
                                                    <?= htmlspecialchars($m['machine_name']) ?>
                                                </option>
                                            <?php endforeach; ?>
                                        </select>
                                    </td>
                                    <td>
                                        <input type="text" name="start_date[]" class="form-control form-control-sm start-date-input"
                                            placeholder="YYYY-MM-DD" value="">
                                    </td>
                                    <td>
                                        <input type="text" name="end_date[]" class="form-control form-control-sm end-date-input"
                                            placeholder="YYYY-MM-DD" value="">
                                        <div class="error-message date-error">End date must be after start date</div>
                                    </td>
                                    <td>
                                        <input type="text" name="desc[]" class="form-control form-control-sm"
                                            value="<?= $detail ? htmlspecialchars($detail['description']) : '' ?>"
                                            placeholder="Description">
                                    </td>
                                    <td>
                                        <button type="button" class="btn btn-sm btn-outline-danger remove-forma">×</button>
                                    </td>
                                </tr>
                            <?php endfor; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-secondary">
                                <td colspan="2" class="text-end fw-bold">Total Pages:</td>
                                <td class="text-center"><span id="totalPages" class="fw-bold">0</span></td>
                                <td class="text-end fw-bold">Total Print Qty:</td>
                                <td class="text-center"><span id="totalPrintQty" class="fw-bold">0</span></td>
                                <td colspan="5"></td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
            </div>
        </div>
        <div class="d-flex justify-content-between">
            <a href="view.php?id=<?= $id ?>" class="btn btn-secondary">Cancel</a>
            <button type="submit" class="btn btn-primary">Update Job Ticket</button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const printQtyInput = document.getElementById('print_qty');
    const pageQtyInput = document.getElementById('page_qty');
    const formaTable = document.getElementById('formaTable');

    // Calculate totals
    function calculateTotals() {
        let totalPrintQty = 0;
        let totalPages = 0;
        
        document.querySelectorAll('.print-qty-input').forEach(function(input) {
            if (input.closest('tr').querySelector('select[name="forma[]"]').value) {
                totalPrintQty += parseInt(input.value) || 0;
            }
        });
        
        document.querySelectorAll('.page-input').forEach(function(input) {
            if (input.closest('tr').querySelector('select[name="forma[]"]').value) {
                totalPages += parseInt(input.value) || 0;
            }
        });
        
        document.getElementById('totalPrintQty').textContent = totalPrintQty.toLocaleString();
        document.getElementById('totalPages').textContent = totalPages;
        pageQtyInput.value = totalPages;
    }

    // Update all print qty when main print qty changes
    // Formula: Print Qty = Main Print Qty - Old Qty
    printQtyInput.addEventListener('input', function() {
        const mainQty = parseInt(this.value) || 0;
        if (mainQty <= 0) return;

        document.querySelectorAll('tbody tr').forEach(function(row) {
            const oldQtyInput = row.querySelector('.old-qty-input');
            const printQtyInput = row.querySelector('.print-qty-input');
            const oldQty = parseInt(oldQtyInput.value) || 0;
            
            // Calculate: Main Print Qty - Old Qty
            const calculatedQty = Math.max(0, mainQty - oldQty);
            printQtyInput.value = calculatedQty;
        });

        calculateTotals();
    });

    // Listen to old_qty changes to recalculate print qty for that row
    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('old-qty-input')) {
            const row = e.target.closest('tr');
            const oldQty = parseInt(e.target.value) || 0;
            const mainPrintQty = parseInt(printQtyInput.value) || 0;
            const rowPrintQtyInput = row.querySelector('.print-qty-input');
            
            // Calculate: Main Print Qty - Old Qty
            const calculatedQty = Math.max(0, mainPrintQty - oldQty);
            rowPrintQtyInput.value = calculatedQty;
            
            calculateTotals();
        }
        
        // Keep existing page-input listener
        if (e.target.classList.contains('page-input')) {
            calculateTotals();
        }
        
        // Allow manual override of print qty but still recalculate totals
        if (e.target.classList.contains('print-qty-input')) {
            calculateTotals();
        }
    });

    document.addEventListener('change', function(e) {
        if (e.target.name === 'forma[]') {
            calculateTotals();
        }
    });

    // Validate dates
    function validateDates() {
        let isValid = true;
        document.querySelectorAll('.start-date-input').forEach(function(startInput, index) {
            const endInput = document.querySelectorAll('.end-date-input')[index];
            const errorDiv = endInput.nextElementSibling;
            
            if (startInput.value && endInput.value) {
                const startDate = new Date(startInput.value);
                const endDate = new Date(endInput.value);
                
                if (endDate < startDate) {
                    errorDiv.style.display = 'block';
                    endInput.style.borderColor = '#dc3545';
                    isValid = false;
                } else {
                    errorDiv.style.display = 'none';
                    endInput.style.borderColor = '#ddd';
                }
            }
        });
        return isValid;
    }

    document.addEventListener('input', function(e) {
        if (e.target.classList.contains('start-date-input') || e.target.classList.contains('end-date-input')) {
            validateDates();
        }
    });

    // Add forma row
    document.getElementById('addForma').addEventListener('click', function() {
        const rowCount = formaTable.querySelectorAll('tbody tr').length;
        const newRow = formaTable.querySelector('tbody tr:first-child').cloneNode(true);
        
        newRow.querySelector('td:first-child').textContent = rowCount + 1;
        newRow.querySelectorAll('select').forEach(s => s.selectedIndex = 0);
        
        const mainPrintQty = parseInt(printQtyInput.value) || 100000;
        newRow.querySelectorAll('input[type="number"]').forEach(i => {
            if (i.classList.contains('page-input')) i.value = '32';
            else if (i.classList.contains('print-qty-input')) i.value = mainPrintQty;
            else i.value = '0';
        });
        newRow.querySelectorAll('input[type="text"]').forEach(i => i.value = '');
        
        formaTable.querySelector('tbody').appendChild(newRow);
        calculateTotals();
    });

    // Reset to 10 rows
    document.getElementById('resetFormas').addEventListener('click', function() {
        const currentRows = formaTable.querySelectorAll('tbody tr').length;
        if (currentRows > 10) {
            const rows = formaTable.querySelectorAll('tbody tr');
            for (let i = 10; i < rows.length; i++) {
                rows[i].remove();
            }
        } else if (currentRows < 10) {
            for (let i = currentRows; i < 10; i++) {
                document.getElementById('addForma').click();
            }
        }
        calculateTotals();
    });

    // Remove forma row
    document.addEventListener('click', function(e) {
        if (e.target.classList.contains('remove-forma')) {
            if (formaTable.querySelectorAll('tbody tr').length > 1) {
                e.target.closest('tr').remove();
                
                // Renumber rows
                formaTable.querySelectorAll('tbody tr').forEach(function(row, index) {
                    row.querySelector('td:first-child').textContent = index + 1;
                });
                
                calculateTotals();
            } else {
                alert("At least one Forma detail is required.");
            }
        }
    });

    // Form submission validation
    document.getElementById('jobTicketForm').addEventListener('submit', function(e) {
        if (!validateDates()) {
            e.preventDefault();
            alert("Please fix the date validation errors before submitting.");
            return false;
        }

        let hasForma = false;
        document.querySelectorAll('select[name="forma[]"]').forEach(function(select) {
            if (select.value) {
                hasForma = true;
            }
        });
        
        if (!hasForma) {
            e.preventDefault();
            alert("Please select at least one forma.");
            return false;
        }
    });

    // Initial calculation
    calculateTotals();
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
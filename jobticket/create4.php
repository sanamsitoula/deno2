<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// generateJobTicketCode(), getFiscalYearId() and getStatusBadge() now live in
// config/functions.php (shared, MAX()-based so numbering resets to 1 per fiscal year).

function getNextLotNumber($conn, $bookId, $fiscalYearId) {
    $stmt = $conn->prepare("SELECT MAX(lot) FROM job_ticket WHERE book_id = ? AND fiscal_year_id = ?");
    $stmt->execute([$bookId, $fiscalYearId]);
    $maxLot = $stmt->fetchColumn();
    return $maxLot ? $maxLot + 1 : 1;
}

// --- Role Check for Modifications ---
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!has_role('editor') && !has_role('admin')) {
        $_SESSION['error'] = "You don't have permission to perform this action.";
        header("Location: jobticket_manager.php");
        exit();
    }
}

// --- Handle CRUD Operations ---
$message = '';
$fiscalYearId = getFiscalYearId($conn);
if (!$fiscalYearId) {
    die("No active fiscal year set. Please configure an active fiscal year.");
}

$edit_ticket = null;
$edit_details = [];
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM job_ticket WHERE id = ?");
    $stmt->execute([$_GET['edit_id']]);
    $edit_ticket = $stmt->fetch();
    if ($edit_ticket) {
        $stmt_details = $conn->prepare("SELECT * FROM job_ticket_details WHERE job_ticket_id = ? ORDER BY order_no ASC");
        $stmt_details->execute([$_GET['edit_id']]);
        $edit_details = $stmt_details->fetchAll();
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    try {
        switch ($action) {
            case 'create':
                if (!$fiscalYearId) {
                    throw new Exception("Cannot create job ticket: No active fiscal year.");
                }
                $conn->beginTransaction();
                $lotNumber = getNextLotNumber($conn, $_POST['book_id'], $fiscalYearId);
                $jobTicketCode = generateJobTicketCode($conn, $fiscalYearId);

                $stmt = $conn->prepare("
                    INSERT INTO job_ticket (
                        book_id, job_ticket_code, lot, remarks, description,
                        print_qty, page_qty, class, date_nep, date_eng,
                        created_by, fiscal_year_id
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $_POST['book_id'],
                    $jobTicketCode,
                    $lotNumber,
                    $_POST['remarks'],
                    $_POST['description'],
                    $_POST['print_qty'],
                    $_POST['page_qty'],
                    $_POST['class'],
                    $_POST['date_nep'],
                    $_POST['date_eng'],
                    $_SESSION['user_id'],
                    $fiscalYearId
                ]);
                $jobTicketId = $conn->lastInsertId();

                if (isset($_POST['forma_id']) && is_array($_POST['forma_id'])) {
                    foreach ($_POST['forma_id'] as $index => $formaId) {
                        if (empty($formaId)) continue;
                        $conn->prepare("
                            INSERT INTO job_ticket_details (
                                job_ticket_id, order_no, forma_id, page,
                                old_forma_qty, print_qty, machine, description
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $jobTicketId,
                            $index + 1,
                            $formaId,
                            $_POST['page'][$index] ?? 0,
                            $_POST['old_forma_qty'][$index] ?? 0,
                            $_POST['detail_print_qty'][$index] ?? 0,
                            $_POST['machine'][$index] ?? null,
                            $_POST['detail_description'][$index] ?? null
                        ]);
                    }
                }
                $conn->commit();
                $message = "<div class='alert alert-success'>Job Ticket created successfully!</div>";
                $edit_ticket = null;
                $edit_details = [];
                break;

            case 'update':
                if (!isset($_POST['id'])) {
                    throw new Exception("Invalid request for update.");
                }
                $jobTicketId = $_POST['id'];
                $conn->beginTransaction();

                $stmt = $conn->prepare("
                    UPDATE job_ticket SET
                        book_id = ?, remarks = ?, description = ?,
                        print_qty = ?, page_qty = ?, class = ?, date_nep = ?, date_eng = ?,
                        updated_by = ?, updated_date = CURRENT_TIMESTAMP
                    WHERE id = ?
                ");
                $stmt->execute([
                    $_POST['book_id'],
                    $_POST['remarks'],
                    $_POST['description'],
                    $_POST['print_qty'],
                    $_POST['page_qty'],
                    $_POST['class'],
                    $_POST['date_nep'],
                    $_POST['date_eng'],
                    $_SESSION['user_id'],
                    $jobTicketId
                ]);

                $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ?")->execute([$jobTicketId]);

                if (isset($_POST['forma_id']) && is_array($_POST['forma_id'])) {
                    foreach ($_POST['forma_id'] as $index => $formaId) {
                        if (empty($formaId)) continue;
                        $conn->prepare("
                            INSERT INTO job_ticket_details (
                                job_ticket_id, order_no, forma_id, page,
                                old_forma_qty, print_qty, machine, description
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                        ")->execute([
                            $jobTicketId,
                            $index + 1,
                            $formaId,
                            $_POST['page'][$index] ?? 0,
                            $_POST['old_forma_qty'][$index] ?? 0,
                            $_POST['detail_print_qty'][$index] ?? 0,
                            $_POST['machine'][$index] ?? null,
                            $_POST['detail_description'][$index] ?? null
                        ]);
                    }
                }
                $conn->commit();
                $message = "<div class='alert alert-success'>Job Ticket updated successfully!</div>";

                $stmt_reload = $conn->prepare("SELECT * FROM job_ticket WHERE id = ?");
                $stmt_reload->execute([$jobTicketId]);
                $edit_ticket = $stmt_reload->fetch();

                $stmt_details_reload = $conn->prepare("SELECT * FROM job_ticket_details WHERE job_ticket_id = ? ORDER BY order_no ASC");
                $stmt_details_reload->execute([$jobTicketId]);
                $edit_details = $stmt_details_reload->fetchAll();
                break;

            case 'delete':
                if (!has_role('admin')) {
                    throw new Exception("You don't have permission to delete job tickets.");
                }
                if (!isset($_POST['id'])) {
                    throw new Exception("Invalid request for deletion.");
                }
                $jobTicketId = $_POST['id'];
                $conn->beginTransaction();
                $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ?")->execute([$jobTicketId]);
                $conn->prepare("DELETE FROM job_ticket WHERE id = ?")->execute([$jobTicketId]);
                $conn->commit();
                $message = "<div class='alert alert-success'>Job Ticket deleted successfully!</div>";
                $edit_ticket = null;
                $edit_details = [];
                break;
        }
    } catch (Exception $e) {
        $conn->rollBack();
        $message = "<div class='alert alert-danger'>Error: " . $e->getMessage() . "</div>";
    }
}

// --- Fetch Data for Forms and Lists ---
$books = $conn->query("SELECT book_id, book_code, book_name, class_level FROM books ORDER BY book_code")->fetchAll();
$machines = $conn->query("SELECT machine_name FROM machines WHERE status = 'active'")->fetchAll();

$formas = [];
if ($edit_ticket) {
    $formas = $conn->query("
        SELECT f.id, f.name 
        FROM forma f
        JOIN book_forma bf ON f.id = bf.forma_id
        WHERE bf.book_id = {$edit_ticket['book_id']}
        ORDER BY f.name
    ")->fetchAll();
}

$job_tickets = $conn->query("
    SELECT j.*, b.book_name, b.book_code, u.username as created_by_name, fy.fiscal_code
    FROM job_ticket j
    JOIN books b ON j.book_id = b.book_id
    JOIN users u ON j.created_by = u.id
    JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
    ORDER BY j.created_date DESC
    LIMIT 20
")->fetchAll();
?>

<style>
/* --- Styles --- */
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
.action-buttons { margin-bottom: 20px; display: flex; gap: 10px; align-items: center; }
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
    font-size: 13px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.table tbody tr:hover { background-color: #f5f5f5; }
.table tbody tr:nth-child(even) { background-color: #fafafa; }
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
/* Searchable dropdown styles */
.search-dropdown {
    position: relative;
    width: 100%;
}
.dropdown-search {
    width: 100%;
    padding: 10px 14px;
    border: 1px solid #ddd;
    border-radius: 4px;
    font-size: 15px;
    background-color: white;
}
.dropdown-options {
    position: absolute;
    top: 100%;
    left: 0;
    right: 0;
    background: white;
    border: 1px solid #ddd;
    border-top: none;
    max-height: 300px;
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
.selected-book {
    display: none;
    padding: 8px;
    background: #e9ecef;
    border-radius: 4px;
    margin-top: 5px;
}
.is-invalid {
    border-color: #dc3545;
}
.invalid-feedback {
    color: #dc3545;
    font-size: 14px;
    margin-top: 5px;
}
/* Details table styles */
.details-table { margin-top: 20px; }
.details-table th, .details-table td { padding: 8px 6px; font-size: 13px; }
.details-table .form-control, .details-table .form-select {
    padding: 6px 8px; font-size: 13px; height: auto;
}
.details-row-actions { width: 60px; text-align: center; }
.remove-detail-row { color: #dc3545; cursor: pointer; font-weight: bold; }
.add-detail-row { margin-top: 10px; }
.total-print-qty { font-weight: bold; text-align: right; }
</style>

<h2><?= $edit_ticket ? 'Edit Job Ticket' : 'Create Job Ticket' ?></h2>
<?php if ($message): ?>
    <?= $message ?>
<?php endif; ?>

<?php if (has_role('editor') || has_role('admin')): ?>
<div class="form-container">
    <form method="post" id="jobTicketForm">
        <input type="hidden" name="action" value="<?= $edit_ticket ? 'update' : 'create' ?>">
        <?php if ($edit_ticket): ?>
            <input type="hidden" name="id" value="<?= $edit_ticket['id'] ?>">
            <input type="hidden" id="selected_book_id" value="<?= $edit_ticket['book_id'] ?>">
        <?php endif; ?>

        <!-- Basic Job Ticket Information -->
        <h4>Basic Information</h4>
        <div class="form-row">
            <div class="form-group">
                <label for="bookSearch">Book: <span class="required">*</span></label>
                <div class="search-dropdown">
                    <input type="text" class="dropdown-search" id="bookSearch" placeholder="Search books..." autocomplete="off"
                           value="<?= $edit_ticket ? $conn->query("SELECT CONCAT(book_code, ' - ', book_name) FROM books WHERE book_id = {$edit_ticket['book_id']}")->fetchColumn() : '' ?>">
                    <div class="dropdown-options" id="bookOptions">
                        <?php foreach ($books as $book): ?>
                            <div class="dropdown-option" 
                                 data-value="<?= $book['book_id'] ?>" 
                                 data-class-level="<?= $book['class_level'] ?>"
                                 data-text="<?= htmlspecialchars($book['book_code'] . ' - ' . $book['book_name']) ?>">
                                <?= htmlspecialchars($book['book_code']) ?> - <?= htmlspecialchars($book['book_name']) ?>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <input type="hidden" name="book_id" id="book_id" value="<?= $edit_ticket ? $edit_ticket['book_id'] : '' ?>" required>
                <div class="invalid-feedback">Please select a book</div>
            </div>
            <div class="form-group">
                <label for="class">Class: <span class="required">*</span></label>
                <input type="number" name="class" id="class" class="form-control"
                       value="<?= $edit_ticket ? htmlspecialchars($edit_ticket['class']) : '' ?>" required readonly>
                <div class="invalid-feedback">Please select a book first</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="lot">Lot No: <span class="required">*</span></label>
                <input type="text" name="lot" id="lot" class="form-control"
                       value="<?= $edit_ticket ? htmlspecialchars($edit_ticket['lot']) : '' ?>" required>
                <div class="invalid-feedback">Please enter a lot number</div>
            </div>
            <div class="form-group">
                <label for="print_qty">Main Print Qty: <span class="required">*</span></label>
                <input type="number" name="print_qty" id="print_qty" class="form-control"
                       value="<?= $edit_ticket ? $edit_ticket['print_qty'] : '' ?>" required min="1">
                <div class="invalid-feedback">Please enter a valid quantity</div>
            </div>
            <div class="form-group">
                <label for="page_qty">Page Qty: <span class="required">*</span></label>
                <input type="number" name="page_qty" id="page_qty" class="form-control"
                       value="<?= $edit_ticket ? $edit_ticket['page_qty'] : '' ?>" required min="1">
                <div class="invalid-feedback">Please enter a valid quantity</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="date_nep">Date (Nepali YYYY-MM-DD): <span class="required">*</span></label>
                <input type="text" name="date_nep" id="date_nep" class="form-control"
                       value="<?= $edit_ticket ? htmlspecialchars($edit_ticket['date_nep']) : '' ?>" required>
                <div class="invalid-feedback">Please enter a valid date</div>
            </div>
            <div class="form-group">
                <label for="date_eng">Date (English): <span class="required">*</span></label>
                <input type="date" name="date_eng" id="date_eng" class="form-control"
                       value="<?= $edit_ticket ? htmlspecialchars($edit_ticket['date_eng']) : date('Y-m-d') ?>" required>
                <div class="invalid-feedback">Please enter a valid date</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="remarks">Remarks:</label>
                <input type="text" name="remarks" id="remarks" class="form-control"
                       value="<?= $edit_ticket ? htmlspecialchars($edit_ticket['remarks']) : '' ?>">
            </div>
            <div class="form-group">
                <label for="description">Description:</label>
                <textarea name="description" id="description" class="form-control" rows="2"><?= $edit_ticket ? htmlspecialchars($edit_ticket['description']) : '' ?></textarea>
            </div>
        </div>

        <!-- Job Ticket Details (Forma Details) -->
        <h4 class="mt-4">Forma Details <span class="required">*</span></h4>
        <div class="table-responsive">
            <table class="table table-bordered table-sm details-table" id="detailsTable">
                <thead class="table-light">
                    <tr>
                        <th>#</th>
                        <th>Forma <span class="required">*</span></th>
                        <th>Page</th>
                        <th>Old Qty</th>
                        <th>Print Qty <span class="required">*</span></th>
                        <th>Machine <span class="required">*</span></th>
                        <th>Description</th>
                        <th class="details-row-actions">Action</th>
                    </tr>
                </thead>
                <tbody id="detailsTableBody">
                    <?php
                    $numDetailRows = max(5, count($edit_details) + 2);
                    for ($i = 0; $i < $numDetailRows; $i++):
                        $detail = $edit_details[$i] ?? null;
                        $defaultPrintQty = $edit_ticket ? $edit_ticket['print_qty'] : 1000;
                    ?>
                    <tr class="detail-row">
                        <td class="align-middle"><?= $i + 1 ?></td>
                        <td>
                            <select name="forma_id[]" class="form-select form-select-sm detail-forma" <?= $detail ? '' : 'disabled' ?> required>
                                <option value="">Select Forma</option>
                                <?php foreach ($formas as $forma): ?>
                                    <option value="<?= $forma['id'] ?>"
                                        <?= ($detail && $detail['forma_id'] == $forma['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($forma['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="number" name="page[]" class="form-control form-control-sm" value="<?= $detail ? $detail['page'] : '32' ?>" min="0"></td>
                        <td><input type="number" name="old_forma_qty[]" class="form-control form-control-sm old-qty" value="<?= $detail ? $detail['old_forma_qty'] : '0' ?>" min="0"></td>
                        <td><input type="number" name="detail_print_qty[]" class="form-control form-control-sm detail-print-qty" value="<?= $detail ? $detail['print_qty'] : $defaultPrintQty ?>" min="1" required></td>
                        <td>
                            <select name="machine[]" class="form-select form-select-sm" required>
                                <option value="">Select Machine</option>
                                <?php foreach ($machines as $machine): ?>
                                    <option value="<?= htmlspecialchars($machine['machine_name']) ?>"
                                        <?= ($detail && $detail['machine'] == $machine['machine_name']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($machine['machine_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </td>
                        <td><input type="text" name="detail_description[]" class="form-control form-control-sm" value="<?= $detail ? htmlspecialchars($detail['description']) : '' ?>"></td>
                        <td class="text-center align-middle details-row-actions">
                            <span class="remove-detail-row" title="Remove Row">&times;</span>
                        </td>
                    </tr>
                    <?php endfor; ?>
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <td colspan="4" class="text-end fw-bold">Total Print Qty:</td>
                        <td><span id="totalPrintQtyDisplay" class="fw-bold">0</span></td>
                        <td colspan="3"></td>
                    </tr>
                </tfoot>
            </table>
        </div>
        <button type="button" id="addDetailRow" class="btn btn-sm btn-outline-primary add-detail-row">+ Add Forma Row</button>

        <div class="form-row mt-3">
            <div class="form-group">
                <button type="submit" class="btn btn-primary">
                    <?= $edit_ticket ? 'Update Job Ticket' : 'Create Job Ticket' ?>
                </button>
                <?php if ($edit_ticket): ?>
                    <a href="jobticket_manager.php" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
            </div>
        </div>
    </form>
</div>
<?php endif; ?>

<h3>Recent Job Tickets</h3>
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
                <th>Created By</th>
                <th>Date</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($job_tickets as $ticket): ?>
            <tr>
                <td><?= htmlspecialchars($ticket['job_ticket_code']) ?></td>
                <td><?= htmlspecialchars($ticket['book_code']) ?> - <?= htmlspecialchars($ticket['book_name']) ?></td>
                <td><?= htmlspecialchars($ticket['lot']) ?></td>
                <td><?= number_format($ticket['print_qty']) ?></td>
                <td><?= number_format($ticket['page_qty']) ?></td>
                <td><?= htmlspecialchars($ticket['class']) ?></td>
                <td><span class="badge bg-<?= getStatusBadge($ticket['status']) ?>"><?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?></span></td>
                <td><?= htmlspecialchars($ticket['created_by_name']) ?></td>
                <td><?= date('Y-m-d', strtotime($ticket['created_date'])) ?></td>
                <td>
                    <a href="jobticket_manager.php?edit_id=<?= $ticket['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <?php if (has_role('admin')): ?>
                    <form method="post" style="display: inline;" onsubmit="return confirm('Are you sure you want to delete this Job Ticket?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $ticket['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                    <?php endif; ?>
                    <a href="print.php?id=<?= $ticket['id'] ?>" class="btn btn-success btn-sm" target="_blank">Print</a>
                </td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const mainPrintQtyInput = document.getElementById('print_qty');
    const detailsTableBody = document.getElementById('detailsTableBody');

    // Automatically populate Print Qty fields when Main Print Qty changes
    mainPrintQtyInput.addEventListener('input', function() {
        const mainPrintQty = parseInt(this.value) || 0;
        document.querySelectorAll('.detail-print-qty').forEach(input => {
            // Get the old qty from the same row
            const oldQty = parseInt(input.closest('.detail-row').querySelector('.old-qty').value) || 0;
            // Calculate new print qty (main print qty - old qty)
            const newPrintQty = Math.max(0, mainPrintQty - oldQty);
            input.value = newPrintQty;
        });
        calculateTotalPrintQty();
    });

    // When Old Qty changes, update Print Qty (subtract from Main Print Qty)
    document.addEventListener('input', function(e) {
        if (e.target && e.target.classList.contains('old-qty')) {
            const row = e.target.closest('.detail-row');
            const oldQty = parseInt(e.target.value) || 0;
            const printQtyInput = row.querySelector('.detail-print-qty');
            const mainPrintQty = parseInt(mainPrintQtyInput.value) || 0;
            
            // Calculate new print qty (main print qty - old qty)
            const newPrintQty = Math.max(0, mainPrintQty - oldQty);
            printQtyInput.value = newPrintQty;
            
            calculateTotalPrintQty();
        }
    });

    // --- Searchable Book Dropdown ---
    const bookSearch = document.getElementById('bookSearch');
    const bookOptions = document.getElementById('bookOptions');
    const bookIdInput = document.getElementById('book_id');
    const classInput = document.getElementById('class');
    const lotInput = document.getElementById('lot');
    const fiscalYearId = <?= $fiscalYearId ?>;
    window.currentFormas = [];

    if (bookSearch) {
        bookSearch.addEventListener('focus', function() {
            bookOptions.style.display = 'block';
            filterOptions(this.value);
        });
        document.addEventListener('click', function(e) {
            if (!bookSearch.contains(e.target) && !bookOptions.contains(e.target)) {
                bookOptions.style.display = 'none';
            }
        });
        bookSearch.addEventListener('input', function() {
            filterOptions(this.value);
        });
        bookOptions.addEventListener('click', function(e) {
            if (e.target.classList.contains('dropdown-option')) {
                const option = e.target;
                bookSearch.value = option.getAttribute('data-text');
                bookIdInput.value = option.getAttribute('data-value');
                classInput.value = option.getAttribute('data-class-level');
                bookOptions.style.display = 'none';
                bookSearch.classList.remove('is-invalid');
                loadFormasAndLot();
            }
        });
        function filterOptions(searchTerm) {
            const options = bookOptions.querySelectorAll('.dropdown-option');
            const term = searchTerm.toLowerCase();
            options.forEach(option => {
                const text = option.textContent.toLowerCase();
                option.style.display = text.includes(term) ? 'block' : 'none';
            });
        }
    }

    function loadFormasAndLot() {
        const bookId = bookIdInput.value;
        if (!bookId) return;
        document.querySelectorAll('.detail-forma').forEach(select => {
            select.innerHTML = '<option value="">Loading formas...</option>';
            select.disabled = true;
        });
        loadFormas(bookId)
            .then(() => loadNextLot(bookId))
            .catch(error => console.error('Error:', error));
    }

    function loadFormas(bookId) {
        return new Promise((resolve, reject) => {
            fetch(`get_formas.php?book_id=${bookId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.currentFormas = data.formas || [];
                        document.querySelectorAll('.detail-forma').forEach(select => {
                            populateFormaSelect(select, window.currentFormas);
                        });
                        resolve(data.formas);
                    } else {
                        throw new Error(data.message);
                    }
                })
                .catch(reject);
        });
    }

    function loadNextLot(bookId) {
        return fetch(`get_next_lot.php?book_id=${bookId}&fiscal_year_id=${fiscalYearId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) lotInput.value = data.next_lot;
            })
            .catch(console.error);
    }

    function populateFormaSelect(selectElement, formas) {
        if (!selectElement) return;
        const currentValue = selectElement.value;
        let optionsHtml = '<option value="">Select Forma</option>';
        (formas || []).forEach(forma => {
            if (forma && forma.id && forma.name) {
                const selected = currentValue == forma.id ? 'selected' : '';
                optionsHtml += `<option value="${forma.id}" ${selected}>${escapeHtml(forma.name)}</option>`;
            }
        });
        selectElement.innerHTML = optionsHtml;
        selectElement.disabled = false;
    }

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = text;
        return div.innerHTML;
    }

    // --- Dynamic Detail Rows ---
    const addDetailRowBtn = document.getElementById('addDetailRow');

    function addRow() {
        const rowCount = document.querySelectorAll('.detail-row').length;
        const bookId = bookIdInput.value;
        if (!bookId) {
            alert("Please select a book first");
            return;
        }

        const newRow = document.createElement('tr');
        newRow.className = 'detail-row';
        newRow.innerHTML = `
            <td class="align-middle">${rowCount + 1}</td>
            <td>
                <select name="forma_id[]" class="form-select form-select-sm detail-forma" required>
                    <option value="">Select Forma</option>
                </select>
            </td>
            <td><input type="number" name="page[]" class="form-control form-control-sm" value="32" min="0"></td>
            <td><input type="number" name="old_forma_qty[]" class="form-control form-control-sm old-qty" value="0" min="0"></td>
            <td><input type="number" name="detail_print_qty[]" class="form-control form-control-sm detail-print-qty" value="${mainPrintQtyInput.value || 1000}" min="1" required></td>
            <td>
                <select name="machine[]" class="form-select form-select-sm" required>
                    <option value="">Select Machine</option>
                    <?php foreach ($machines as $machine): ?>
                    <option value="<?= htmlspecialchars($machine['machine_name']) ?>"><?= htmlspecialchars($machine['machine_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="detail_description[]" class="form-control form-control-sm"></td>
            <td class="text-center align-middle details-row-actions">
                <span class="remove-detail-row" title="Remove Row">&times;</span>
            </td>
        `;

        detailsTableBody.appendChild(newRow);
        const newFormaSelect = newRow.querySelector('.detail-forma');
        populateFormaSelect(newFormaSelect, window.currentFormas);

        updateRowNumbers();
        attachRemoveListeners();
        calculateTotalPrintQty();
    }

    function attachRemoveListeners() {
        document.querySelectorAll('.remove-detail-row').forEach(btn => {
            btn.replaceWith(btn.cloneNode(true));
        });
        document.querySelectorAll('.remove-detail-row').forEach(btn => {
            btn.addEventListener('click', function() {
                if (document.querySelectorAll('.detail-row').length > 1) {
                    this.closest('.detail-row').remove();
                    updateRowNumbers();
                    calculateTotalPrintQty();
                } else {
                    alert("At least one forma detail row is required.");
                }
            });
        });
    }

    function updateRowNumbers() {
        document.querySelectorAll('.detail-row').forEach((row, index) => {
            const cell = row.cells[0];
            if (cell) cell.textContent = index + 1;
        });
    }

    if (addDetailRowBtn) {
        addDetailRowBtn.addEventListener('click', addRow);
    }
    attachRemoveListeners();

    // --- Calculate Total Print Quantity ---
    function calculateTotalPrintQty() {
        let total = 0;
        document.querySelectorAll('.detail-print-qty').forEach(input => {
            total += parseInt(input.value) || 0;
        });
        const totalDisplay = document.getElementById('totalPrintQtyDisplay');
        if (totalDisplay) {
            totalDisplay.textContent = total.toLocaleString();
        }
    }

    calculateTotalPrintQty();

    // Initialize on edit
    if (bookIdInput.value && <?= $edit_ticket ? 'true' : 'false' ?>) {
        loadFormasAndLot();
    }

    // --- Form Validation ---
    const jobTicketForm = document.getElementById('jobTicketForm');
    if (jobTicketForm) {
        jobTicketForm.addEventListener('submit', function(e) {
            let isValid = true;
            if (!bookIdInput.value) {
                bookSearch.classList.add('is-invalid');
                isValid = false;
            } else {
                bookSearch.classList.remove('is-invalid');
            }
            
            // Check machine fields are filled
            let hasMachineError = false;
            document.querySelectorAll('select[name="machine[]"]').forEach(select => {
                if (!select.value && select.closest('.detail-row').style.display !== 'none') {
                    select.classList.add('is-invalid');
                    hasMachineError = true;
                } else {
                    select.classList.remove('is-invalid');
                }
            });
            
            if (hasMachineError) {
                alert("Please select a machine for all forma rows");
                isValid = false;
            }
            
            let hasForma = false;
            document.querySelectorAll('select[name="forma_id[]"]').forEach(s => {
                if (s.value) hasForma = true;
            });
            if (!hasForma) {
                alert("Please select at least one forma.");
                isValid = false;
            }
            if (!isValid) e.preventDefault();
        });
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
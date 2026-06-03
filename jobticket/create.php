<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// --- Utility Functions ---
function generateJobTicketCode($conn, $fiscalYearId) {
    $fyCode = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE id = $fiscalYearId")->fetchColumn();
    $count = $conn->query("SELECT COUNT(*) FROM job_ticket WHERE fiscal_year_id = $fiscalYearId")->fetchColumn();
    $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    return "$fyCode-JT$seq";
}

function getFiscalYearId($conn) {
    $fiscalStmt = $conn->query("SELECT id FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
    return $fiscalStmt->fetchColumn();
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pending': return 'warning';
        case 'in_progress': return 'primary';
        case 'completed': return 'success';
        case 'cancelled': return 'danger';
        default: return 'secondary';
    }
}

function getNextLotNumber($conn, $bookId, $fiscalYearId) {
    // PostgreSQL compatible version
    $stmt = $conn->prepare("
        SELECT COALESCE(MAX(CAST(lot AS INTEGER)), 0) 
        FROM job_ticket 
        WHERE book_id = ? AND fiscal_year_id = ?
    ");
    $stmt->execute([$bookId, $fiscalYearId]);
    $maxLot = $stmt->fetchColumn();
    return $maxLot + 1;
}

function getLotHistory($conn, $bookId, $fiscalYearId) {
    // PostgreSQL compatible version
    $stmt = $conn->prepare("
        SELECT lot, print_qty, job_ticket_code 
        FROM job_ticket 
        WHERE book_id = ? AND fiscal_year_id = ? 
        ORDER BY CAST(lot AS INTEGER) ASC
    ");
    $stmt->execute([$bookId, $fiscalYearId]);
    return $stmt->fetchAll();
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

                // Calculate page_qty from forma details
                $totalPages = 0;
                if (isset($_POST['forma_id']) && is_array($_POST['forma_id'])) {
                    foreach ($_POST['forma_id'] as $index => $formaId) {
                        if (!empty($formaId)) {
                            $totalPages += intval($_POST['page'][$index] ?? 0);
                        }
                    }
                }

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
                    $totalPages,
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

                // Calculate page_qty from forma details
                $totalPages = 0;
                if (isset($_POST['forma_id']) && is_array($_POST['forma_id'])) {
                    foreach ($_POST['forma_id'] as $index => $formaId) {
                        if (!empty($formaId)) {
                            $totalPages += intval($_POST['page'][$index] ?? 0);
                        }
                    }
                }

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
                    $totalPages,
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
        SELECT f.id, f.name, f.page, f.order_no
        FROM forma f
        WHERE f.book_id = {$edit_ticket['book_id']}
        ORDER BY f.order_no ASC
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
                                 data-book-code="<?= htmlspecialchars($book['book_code']) ?>"
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
                <input type="text" name="lot" id="lot" class="form-control" readonly
                       value="<?= $edit_ticket ? htmlspecialchars($edit_ticket['lot']) : '' ?>" required>
                <div class="invalid-feedback">Please select a book first</div>
                <div id="lotHistory" class="lot-history" style="display: none;">
                    <strong>Previous Lots:</strong>
                    <div id="lotHistoryContent"></div>
                </div>
            </div>
            <div class="form-group">
                <label for="print_qty">Main Print Qty: <span class="required">*</span></label>
                <input type="number" name="print_qty" id="print_qty" class="form-control"
                       value="<?= $edit_ticket ? $edit_ticket['print_qty'] : '' ?>" required min="1">
                <div class="invalid-feedback">Please enter a valid quantity</div>
            </div>
        </div>
        <div class="form-row">
            <div class="form-group">
                <label for="page_qty">Page Qty (Auto-calculated): <span class="required">*</span></label>
                <input type="number" name="page_qty" id="page_qty" class="form-control" readonly
                       value="<?= $edit_ticket ? $edit_ticket['page_qty'] : '0' ?>" required min="0">
                <div class="invalid-feedback">Calculated from forma pages</div>
            </div>
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
                        <th>Machine</th>
                        <th>Description</th>
                        <th class="details-row-actions">Action</th>
                    </tr>
                </thead>
                <tbody id="detailsTableBody">
                    <?php if ($edit_ticket && !empty($edit_details)): ?>
                        <?php foreach ($edit_details as $i => $detail): ?>
                        <tr class="detail-row">
                            <td class="align-middle"><?= $i + 1 ?></td>
                            <td>
                                <select name="forma_id[]" class="form-select form-select-sm detail-forma">
                                    <option value="">Select Forma</option>
                                    <?php foreach ($formas as $forma): ?>
                                        <option value="<?= $forma['id'] ?>" 
                                                data-page="<?= $forma['page'] ?>"
                                                <?= ($detail['forma_id'] == $forma['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($forma['name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="number" name="page[]" class="form-control form-control-sm page-input" value="<?= $detail['page'] ?>" min="0"></td>
                            <td><input type="number" name="old_forma_qty[]" class="form-control form-control-sm" value="<?= $detail['old_forma_qty'] ?>" min="0"></td>
                            <td><input type="number" name="detail_print_qty[]" class="form-control form-control-sm detail-print-qty" value="<?= $detail['print_qty'] ?>" min="0"></td>
                            <td>
                                <select name="machine[]" class="form-select form-select-sm">
                                    <option value="">Select Machine</option>
                                    <?php foreach ($machines as $machine): ?>
                                        <option value="<?= htmlspecialchars($machine['machine_name']) ?>"
                                            <?= ($detail['machine'] == $machine['machine_name']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($machine['machine_name']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </td>
                            <td><input type="text" name="detail_description[]" class="form-control form-control-sm" value="<?= htmlspecialchars($detail['description']) ?>"></td>
                            <td class="text-center align-middle details-row-actions">
                                <span class="remove-detail-row" title="Remove Row">&times;</span>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    <?php else: ?>
                        <!-- Empty row - will be populated when book is selected -->
                        <tr class="detail-row">
                            <td class="align-middle">1</td>
                            <td>
                                <select name="forma_id[]" class="form-select form-select-sm detail-forma" disabled>
                                    <option value="">Select a book first</option>
                                </select>
                            </td>
                            <td><input type="number" name="page[]" class="form-control form-control-sm page-input" value="0" min="0" readonly></td>
                            <td><input type="number" name="old_forma_qty[]" class="form-control form-control-sm" value="0" min="0"></td>
                            <td><input type="number" name="detail_print_qty[]" class="form-control form-control-sm detail-print-qty" value="0" min="0"></td>
                            <td>
                                <select name="machine[]" class="form-select form-select-sm">
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
                        </tr>
                    <?php endif; ?>
                </tbody>
                <tfoot>
                    <tr class="table-secondary">
                        <td colspan="2" class="text-end fw-bold">Total Pages:</td>
                        <td><span id="totalPages" class="fw-bold">0</span></td>
                        <td class="text-end fw-bold">Total Print Qty:</td>
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
                    <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
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
    const pageQtyInput = document.getElementById('page_qty');
    const detailsTableBody = document.getElementById('detailsTableBody');
    const printQtyInput = document.getElementById('print_qty');
    const bookIdInput = document.getElementById('book_id');
    const bookSearch = document.getElementById('bookSearch');
    const bookOptions = document.getElementById('bookOptions');
    const classInput = document.getElementById('class');
    const lotInput = document.getElementById('lot');
    const lotHistory = document.getElementById('lotHistory');
    const lotHistoryContent = document.getElementById('lotHistoryContent');
    const fiscalYearId = <?= $fiscalYearId ?>;
    window.currentFormas = [];

    // Calculate total pages and print qty
    function calculateTotals() {
        let totalPages = 0;
        let totalPrintQty = 0;
        
        document.querySelectorAll('.detail-row').forEach(row => {
            const formaSelect = row.querySelector('.detail-forma');
            const pageInput = row.querySelector('.page-input');
            const printQtyInput = row.querySelector('.detail-print-qty');
            
            // Only count if forma is selected
            if (formaSelect && formaSelect.value) {
                if (pageInput && pageInput.value) {
                    totalPages += parseInt(pageInput.value) || 0;
                }
                
                if (printQtyInput && printQtyInput.value) {
                    totalPrintQty += parseInt(printQtyInput.value) || 0;
                }
            }
        });
        
        document.getElementById('totalPages').textContent = totalPages;
        document.getElementById('totalPrintQtyDisplay').textContent = totalPrintQty.toLocaleString();
        pageQtyInput.value = totalPages;
    }

    // Auto-update all detail print qty when main print qty changes
    // Formula: Print Qty = Main Print Qty - Old Qty
    if (printQtyInput) {
        printQtyInput.addEventListener('input', function() {
            const mainQty = parseInt(this.value.trim()) || 0;
            if (mainQty <= 0) return;

            document.querySelectorAll('.detail-row').forEach(row => {
                const oldQtyInput = row.querySelector('input[name="old_forma_qty[]"]');
                const rowPrintQtyInput = row.querySelector('.detail-print-qty');
                const oldQty = parseInt(oldQtyInput.value) || 0;
                
                // Calculate: Main Print Qty - Old Qty
                const calculatedQty = Math.max(0, mainQty - oldQty);
                rowPrintQtyInput.value = calculatedQty;
            });

            calculateTotals();
        });
    }

    // Listen to old_forma_qty changes to recalculate print qty for that row
    document.addEventListener('input', function(e) {
        if (e.target && e.target.name === 'old_forma_qty[]') {
            const row = e.target.closest('.detail-row');
            const oldQty = parseInt(e.target.value) || 0;
            const mainPrintQty = parseInt(printQtyInput.value) || 0;
            const rowPrintQtyInput = row.querySelector('.detail-print-qty');
            
            // Calculate: Main Print Qty - Old Qty
            const calculatedQty = Math.max(0, mainPrintQty - oldQty);
            rowPrintQtyInput.value = calculatedQty;
            
            calculateTotals();
        }
        
        // Keep existing page-input listener
        if (e.target && e.target.classList.contains('page-input')) {
            calculateTotals();
        }
        
        // Allow manual override of print qty but still recalculate totals
        if (e.target && e.target.classList.contains('detail-print-qty')) {
            calculateTotals();
        }
    });

    // Listen to forma selection changes
    document.addEventListener('change', function(e) {
        if (e.target && e.target.classList.contains('detail-forma')) {
            const selectedOption = e.target.options[e.target.selectedIndex];
            const pageValue = selectedOption.getAttribute('data-page');
            const row = e.target.closest('tr');
            const pageInput = row.querySelector('.page-input');
            
            if (pageValue && pageInput) {
                pageInput.value = pageValue;
                pageInput.readOnly = false;
            }
            
            calculateTotals();
        }
    });

    // --- Searchable Book Dropdown ---
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
        
        loadFormasForBook(bookId)
            .then(() => loadLotInfo(bookId))
            .catch(error => console.error('Error:', error));
    }

    function loadFormasForBook(bookId) {
        return new Promise((resolve, reject) => {
            fetch(`get_formas_with_details.php?book_id=${bookId}`)
                .then(r => r.json())
                .then(data => {
                    if (data.success) {
                        window.currentFormas = data.formas || [];
                        
                        // Clear existing rows
                        detailsTableBody.innerHTML = '';
                        
                        // Create rows for each forma
                        const mainPrintQty = parseInt(printQtyInput.value) || 100000;
                        
                        if (window.currentFormas.length > 0) {
                            window.currentFormas.forEach((forma, index) => {
                                addRowWithForma(forma, index + 1, mainPrintQty);
                            });
                        } else {
                            // If no formas, add one empty row
                            addEmptyRow(1);
                        }
                        
                        calculateTotals();
                        resolve(data.formas);
                    } else {
                        throw new Error(data.message || 'Failed to load formas');
                    }
                })
                .catch(reject);
        });
    }

    function addRowWithForma(forma, orderNo, printQty) {
        const newRow = document.createElement('tr');
        newRow.className = 'detail-row';
        newRow.innerHTML = `
            <td class="align-middle">${orderNo}</td>
            <td>
                <select name="forma_id[]" class="form-select form-select-sm detail-forma">
                    <option value="">Select Forma</option>
                    ${window.currentFormas.map(f => `
                        <option value="${f.id}" 
                                data-page="${f.page}" 
                                data-machine="${f.machine || ''}"
                                ${f.id == forma.id ? 'selected' : ''}>
                            ${escapeHtml(f.name)}
                        </option>
                    `).join('')}
                </select>
            </td>
            <td><input type="number" name="page[]" class="form-control form-control-sm page-input" value="${forma.page || 32}" min="0"></td>
            <td><input type="number" name="old_forma_qty[]" class="form-control form-control-sm" value="0" min="0"></td>
            <td><input type="number" name="detail_print_qty[]" class="form-control form-control-sm detail-print-qty" value="${printQty}" min="0"></td>
            <td>
                <select name="machine[]" class="form-select form-select-sm">
                    <option value="">Select Machine</option>
                    <?php foreach ($machines as $machine): ?>
                    <option value="<?= htmlspecialchars($machine['machine_name']) ?>" ${forma.machine == '<?= htmlspecialchars($machine['machine_name']) ?>' ? 'selected' : ''}>
                        <?= htmlspecialchars($machine['machine_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="detail_description[]" class="form-control form-control-sm"></td>
            <td class="text-center align-middle details-row-actions">
                <span class="remove-detail-row" title="Remove Row">&times;</span>
            </td>
        `;
        detailsTableBody.appendChild(newRow);
    }

    function addEmptyRow(orderNo) {
        const mainPrintQty = parseInt(printQtyInput.value) || 100000;
        const newRow = document.createElement('tr');
        newRow.className = 'detail-row';
        newRow.innerHTML = `
            <td class="align-middle">${orderNo}</td>
            <td>
                <select name="forma_id[]" class="form-select form-select-sm detail-forma">
                    <option value="">Select Forma</option>
                    ${window.currentFormas.map(f => `
                        <option value="${f.id}" data-page="${f.page}" data-machine="${f.machine || ''}">
                            ${escapeHtml(f.name)}
                        </option>
                    `).join('')}
                </select>
            </td>
            <td><input type="number" name="page[]" class="form-control form-control-sm page-input" value="0" min="0" readonly></td>
            <td><input type="number" name="old_forma_qty[]" class="form-control form-control-sm" value="0" min="0"></td>
            <td><input type="number" name="detail_print_qty[]" class="form-control form-control-sm detail-print-qty" value="${mainPrintQty}" min="0"></td>
            <td>
                <select name="machine[]" class="form-select form-select-sm">
                    <option value="">Select Machine</option>
                    <?php foreach ($machines as $machine): ?>
                    <option value="<?= htmlspecialchars($machine['machine_name']) ?>">
                        <?= htmlspecialchars($machine['machine_name']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </td>
            <td><input type="text" name="detail_description[]" class="form-control form-control-sm"></td>
            <td class="text-center align-middle details-row-actions">
                <span class="remove-detail-row" title="Remove Row">&times;</span>
            </td>
        `;
        detailsTableBody.appendChild(newRow);
    }

    function loadLotInfo(bookId) {
        return fetch(`get_lot_info.php?book_id=${bookId}&fiscal_year_id=${fiscalYearId}`)
            .then(r => r.json())
            .then(data => {
                if (data.success) {
                    lotInput.value = data.next_lot;
                    
                    if (data.lot_history && data.lot_history.length > 0) {
                        let historyHtml = '';
                        data.lot_history.forEach(lot => {
                            historyHtml += `<div class="lot-history-item">
                                Lot ${lot.lot}: ${parseInt(lot.print_qty).toLocaleString()} pcs (${lot.job_ticket_code})
                            </div>`;
                        });
                        lotHistoryContent.innerHTML = historyHtml;
                        lotHistory.style.display = 'block';
                    } else {
                        lotHistory.style.display = 'none';
                    }
                }
            })
            .catch(console.error);
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

        addEmptyRow(rowCount + 1);
        attachRemoveListeners();
        calculateTotals();
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
                    calculateTotals();
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
    calculateTotals();

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
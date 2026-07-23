<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('incharge') && !has_role('operator') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Handle form submission
$error_message = null;
$success_message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        // Validate required fields
        $required_fields = ['name', 'jt_id', 'jt_print_qty', 'p_qty', 'book_code', 'date_nep', 'date_eng',
            'supervisor_id', 'incharge_id', 'operator_id', 'fiscal_year_id'];

        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '{$field}' is required");
            }
        }

        $jt_id = (int)$_POST['jt_id'];
        $p_qty = (int)$_POST['p_qty'];
        $jt_print_qty = (int)$_POST['jt_print_qty'];

        // Fetch total already packed for this job ticket
        $stmt = $conn->prepare("SELECT COALESCE(SUM(p_qty), 0) as total_packed FROM book_packing WHERE jt_id = :jt_id AND status = true");
        $stmt->execute([':jt_id' => $jt_id]);
        $total_packed = (int)$stmt->fetch()['total_packed'];

        // Validate: new p_qty + total_packed <= jt_print_qty
        if ($p_qty + $total_packed > $jt_print_qty) {
            throw new Exception("Total packed quantity ({$total_packed} + {$p_qty}) exceeds print quantity ({$jt_print_qty}).");
        }

        // Fiscal-year-scoped packing number: "{serial}/BP/{fiscalShort}" — resets
        // to 1 for each new fiscal year (see plan_numberseries.md).
        $fy_stmt = $conn->prepare("SELECT id, fiscal_code, fiscal_name FROM fiscal_years WHERE id = :fy");
        $fy_stmt->execute([':fy' => $_POST['fiscal_year_id']]);
        $fy_row = $fy_stmt->fetch(PDO::FETCH_ASSOC);
        if (!$fy_row) {
            throw new Exception("Invalid fiscal year selected.");
        }
        [$packing_serial_no, $packing_no] = generateFiscalScopedNumber(
            $conn, 'book_packing', 'packing_serial_no', $fy_row['id'], 'BP', $fy_row
        );

        // Insert packing record
        $insert_sql = "
            INSERT INTO book_packing (
                name, jt_id, jt_print_qty, p_qty, book_code, date_nep, date_eng,
                supervisor_id, incharge_id, operator_id, status, packing_status,
                created_by, fiscal_year_id, remarks, description,
                packing_serial_no, packing_no
            ) VALUES (
                :name, :jt_id, :jt_print_qty, :p_qty, :book_code, :date_nep, :date_eng,
                :supervisor_id, :incharge_id, :operator_id, true, :packing_status,
                :created_by, :fiscal_year_id, :remarks, :description,
                :packing_serial_no, :packing_no
            )
        ";

        $stmt = $conn->prepare($insert_sql);
        $stmt->execute([
            ':name' => $_POST['name'],
            ':jt_id' => $jt_id,
            ':jt_print_qty' => $jt_print_qty,
            ':p_qty' => $p_qty,
            ':book_code' => $_POST['book_code'],
            ':date_nep' => $_POST['date_nep'],
            ':date_eng' => $_POST['date_eng'],
            ':supervisor_id' => $_POST['supervisor_id'],
            ':incharge_id' => $_POST['incharge_id'],
            ':operator_id' => $_POST['operator_id'],
            ':packing_status' => $_POST['packing_status'] ?? 'active',
            ':created_by' => $_SESSION['user_id'],
            ':fiscal_year_id' => $_POST['fiscal_year_id'],
            ':remarks' => $_POST['remarks'] ?? null,
            ':description' => $_POST['description'] ?? null,
            ':packing_serial_no' => $packing_serial_no,
            ':packing_no' => $packing_no
        ]);

        $packing_id = $conn->lastInsertId();

        // Check if total packed now equals print_qty → mark job ticket as bp_completed
        $new_total = $total_packed + $p_qty;
        if ($new_total >= $jt_print_qty) {
            $stmt = $conn->prepare("UPDATE job_ticket SET status = 'bp_completed' WHERE id = :jt_id");
            $stmt->execute([':jt_id' => $jt_id]);
        }

        $conn->commit();

        $_SESSION['success_message'] = "Packing record created successfully!";
        header('Location: view.php?id=' . $packing_id);
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Get active fiscal years
$stmt = $conn->prepare("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = true ORDER BY id ASC");
$stmt->execute();
$fiscal_years = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Use first (current) fiscal year as default
$default_fy = $fiscal_years[0]['id'] ?? null;

// Get job tickets (only active and not bp_completed)
$job_tickets = $conn->query("
    SELECT jt.id, jt.job_ticket_code, jt.lot, jt.print_qty, b.book_name, b.book_code, b.class_level
    FROM job_ticket jt
    LEFT JOIN books b ON jt.book_id = b.book_id
    WHERE jt.status in ( 'active','pending') OR jt.status IS NULL
    ORDER BY jt.created_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// User lists
$supervisors = $conn->query("SELECT id, username FROM users WHERE role IN ('supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$incharges = $conn->query("SELECT id, username FROM users WHERE role IN ('incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$operators = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* Same as before — kept for brevity */
    .container { max-width: 1000px; margin: 0 auto; padding: 20px; }
    h1 { margin: 20px 15px; color: #333; font-size: 32px; font-weight: 700; display: flex; align-items: center; gap: 15px; }
    .form-container { background: white; border-radius: 12px; box-shadow: 0 4px 6px rgba(0,0,0,0.05); margin: 0 15px; overflow: hidden; border: 1px solid #e9ecef; }
    .form-header { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; padding: 20px 25px; font-size: 18px; font-weight: 600; display: flex; justify-content: space-between; align-items: center; }
    .form-content { padding: 30px; }
    .form-section { margin-bottom: 40px; padding: 25px; background: #f8f9fa; border-radius: 8px; border-left: 4px solid #667eea; }
    .section-title { font-size: 18px; font-weight: 600; color: #333; margin-bottom: 20px; display: flex; align-items: center; gap: 10px; }
    .form-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(300px, 1fr)); gap: 20px; margin-bottom: 20px; }
    .form-group { display: flex; flex-direction: column; }
    .form-group label { font-size: 14px; font-weight: 600; margin-bottom: 8px; color: #495057; }
    .form-group .required { color: #dc3545; }
    .form-control { padding: 12px 15px; border: 2px solid #e9ecef; border-radius: 8px; font-size: 14px; transition: all 0.3s ease; box-sizing: border-box; }
    .form-control:focus { outline: none; border-color: #667eea; box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1); }
    .form-control:invalid { border-color: #dc3545; }
    textarea.form-control { min-height: 100px; resize: vertical; }
    .btn { padding: 12px 24px; border: none; border-radius: 8px; cursor: pointer; font-size: 14px; font-weight: 600; text-decoration: none; display: inline-flex; align-items: center; gap: 8px; transition: all 0.3s ease; text-align: center; margin-right: 10px; }
    .btn:hover { transform: translateY(-1px); box-shadow: 0 4px 12px rgba(0,0,0,0.15); }
    .btn-success { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .btn-secondary { background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%); color: white; }
    .btn-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; }
    .form-actions { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px; padding-top: 20px; border-top: 1px solid #e9ecef; margin-top: 30px; }
    .alert { margin: 0 15px 20px; padding: 15px 20px; border-radius: 8px; border: 1px solid transparent; }
    .alert-danger { background: #f8d7da; color: #721c24; border-color: #f5c6cb; }
    .job-ticket-info { background: #e3f2fd; padding: 15px; border-radius: 8px; margin-top: 10px; display: none; }
    .job-ticket-info.active { display: block; }
    .info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 10px; }
    .info-item { font-size: 13px; }
    .info-label { font-weight: 600; color: #1976d2; }
    .info-value { color: #424242; }
    .quantity-alert { background: #fff3cd; border: 1px solid #ffeaa7; color: #856404; padding: 10px; border-radius: 6px; margin-top: 10px; font-size: 13px; display: none; }
    .quantity-alert.show { display: block; }

    /* Previous Records Table */
    .previous-records { margin-top: 20px; border: 1px solid #ddd; border-radius: 8px; overflow: hidden; }
    .previous-records th, .previous-records td { padding: 12px 15px; text-align: left; font-size: 13px; border-bottom: 1px solid #eee; }
    .previous-records th { background: #f1f3f5; color: #495057; font-weight: 600; }
    .previous-records tr:hover { background: #f8f9fa; }
    .status-badge { padding: 4px 8px; border-radius: 4px; font-size: 11px; font-weight: bold; }
    .status-active { background: #d1ecf1; color: #0c5460; }
    .status-pending { background: #fff3cd; color: #856404; }
    .status-completed { background: #d4edda; color: #155724; }

    @media (max-width: 768px) {
        .form-row { grid-template-columns: 1fr; }
        .form-actions { flex-direction: column; align-items: stretch; }
        .previous-records th, .previous-records td { padding: 8px; font-size: 12px; }
    }
</style>

<div class="container">
    <h1>
        ➕ Create New Packing Record
        <span style="font-size: 16px; font-weight: normal; color: #6c757d;">Production Management System</span>
    </h1>

    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <div class="form-container">
        <div class="form-header">
            <span>📝 Packing Record Information</span>
            <span>Fill in all required fields</span>
        </div>

        <div class="form-content">
            <form method="POST" action="" id="packingForm" novalidate>

                <!-- Basic Information Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-info-circle"></i> Basic Information
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="name">Record Name <span class="required">*</span></label>
                            <input type="text" id="name" name="name" class="form-control"
                                   placeholder="Enter packing record name"
                                   value="<?= htmlspecialchars($_POST['name'] ?? '') ?>" required>
                        </div>
                        <div class="form-group">
                            <label for="fiscal_year_id">Fiscal Year <span class="required">*</span></label>
                            <?php if (count($fiscal_years) === 1): ?>
                                <input type="text" class="form-control" value="<?= htmlspecialchars($fiscal_years[0]['fiscal_code']) ?>" readonly>
                                <input type="hidden" name="fiscal_year_id" value="<?= $fiscal_years[0]['id'] ?>">
                            <?php else: ?>
                                <select id="fiscal_year_id" name="fiscal_year_id" class="form-control" required>
                                    <option value="">Select Fiscal Year</option>
                                    <?php foreach ($fiscal_years as $fy): ?>
                                        <option value="<?= $fy['id'] ?>"
                                            <?= (($_POST['fiscal_year_id'] ?? $default_fy) == $fy['id']) ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($fy['fiscal_code']) ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>

                <!-- Job Ticket Information Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-ticket-alt"></i> Job Ticket Information
                    </div>
                    <div class="form-row">
                        <div class="form-group" style="grid-column: 1/-1;">
                            <label for="jt_id">Job Ticket <span class="required">*</span></label>
                            <select id="jt_id" name="jt_id" class="form-control" required>
                                <option value="">Select Job Ticket</option>
                                <?php foreach ($job_tickets as $jt): ?>
                                    <option value="<?= $jt['id'] ?>"
                                            data-print-qty="<?= $jt['print_qty'] ?>"
                                            data-book-code="<?= htmlspecialchars($jt['book_code']) ?>"
                                            data-book-name="<?= htmlspecialchars($jt['book_name']) ?>"
                                            data-lot="<?= htmlspecialchars($jt['lot']) ?>"
                                            data-class="<?= htmlspecialchars($jt['class_level']) ?>">
                                        <?= htmlspecialchars($jt['job_ticket_code']) ?> - <?= htmlspecialchars($jt['book_name']) ?> (Qty: <?= number_format($jt['print_qty']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>

                            <div class="job-ticket-info" id="jobTicketInfo">
                                <div class="info-grid">
                                    <div class="info-item">
                                        <div class="info-label">Book Code:</div>
                                        <div class="info-value" id="infoBookCode">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Book Name:</div>
                                        <div class="info-value" id="infoBookName">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Class Level:</div>
                                        <div class="info-value" id="infoClass">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Lot:</div>
                                        <div class="info-value" id="infoLot">-</div>
                                    </div>
                                    <div class="info-item">
                                        <div class="info-label">Print Quantity:</div>
                                        <div class="info-value" id="infoPrintQty">-</div>
                                    </div>
                                </div>
                            </div>

                            <!-- Previous Packings -->
                            <div id="previousPackingContainer" style="display: none; margin-top: 20px;">
                                <div class="section-title">
                                    <i class="fas fa-history"></i> Previous Packing Records
                                </div>
                                <p style="margin-bottom: 10px; color: #666;">Existing packing history for this job ticket:</p>
                                <div class="previous-records">
                                    <table width="100%" id="previousPackingTable">
                                        <thead>
                                            <tr>
                                                <th>ID</th>
                                                <th>Name</th>
                                                <th>Packed Qty</th>
                                                <th>Date (Eng)</th>
                                                <th>Date (Nep)</th>
                                                <th>Status</th>
                                                <th>Created By</th>
                                            </tr>
                                        </thead>
                                        <tbody></tbody>
                                    </table>
                                </div>

                                <!-- Summary -->
                                <div style="background: #f8f9fa; border: 1px solid #ddd; padding: 12px; margin-top: 15px; border-radius: 6px;">
                                    <div style="display: flex; justify-content: space-between; font-size: 14px;">
                                        <strong>Total Packed:</strong>
                                        <span id="totalPacked">0</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 14px;">
                                        <strong>Remaining Capacity:</strong>
                                        <span id="remainingCapacity">0</span>
                                    </div>
                                    <div style="display: flex; justify-content: space-between; font-size: 14px; color: #dc3545;">
                                        <strong>Max Allowed:</strong>
                                        <span id="maxAllowed">0</span>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Quantity & Details -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-boxes"></i> Quantity & Book Details
                    </div>
                    <div class="form-row">
                        <div class="form-group">
                            <label for="book_code">Book Code <span class="required">*</span></label>
                            <input type="text" id="book_code" name="book_code" class="form-control"
                                   placeholder="Auto-filled from job ticket"
                                   value="<?= htmlspecialchars($_POST['book_code'] ?? '') ?>" readonly required>
                        </div>
                        <div class="form-group">
                            <label for="jt_print_qty">Job Ticket Print Quantity <span class="required">*</span></label>
                            <input type="number" id="jt_print_qty" name="jt_print_qty" class="form-control"
                                   placeholder="Auto-filled from job ticket"
                                   value="<?= htmlspecialchars($_POST['jt_print_qty'] ?? '') ?>" readonly required>
                        </div>
                        <div class="form-group">
                            <label for="p_qty">Packed Quantity <span class="required">*</span></label>
                            <input type="number" id="p_qty" name="p_qty" class="form-control"
                                   placeholder="Enter quantity to be packed" min="1"
                                   value="<?= htmlspecialchars($_POST['p_qty'] ?? '') ?>" required>
                            <div class="quantity-alert" id="quantityAlert">
                                <i class="fas fa-exclamation-triangle"></i>
                                Cannot exceed remaining capacity!
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Date, Personnel, Status, etc. -->
                <!-- (Copy from your original or use above) -->
                <!-- ... other sections ... -->

                     <!-- Date Information Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-calendar-alt"></i> Date Information
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="date_nep">Nepali Date <span class="required">*</span></label>
                            <input type="text" id="date_nep" name="date_nep" class="form-control" 
                                   placeholder="2081.01.01" pattern="[0-9]{4}\.[0-9]{2}\.[0-9]{2}"
                                   value="<?= htmlspecialchars($_POST['date_nep'] ?? date('Y.m.d', strtotime('+57 years'))) ?>" required>
                        </div>
                        
                        <div class="form-group">
                            <label for="date_eng">English Date <span class="required">*</span></label>
                            <input type="date" id="date_eng" name="date_eng" class="form-control" 
                                   value="<?= htmlspecialchars($_POST['date_eng'] ?? date('Y-m-d')) ?>" required>
                        </div>
                    </div>
                </div>

                <!-- Personnel Information Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-users"></i> Personnel Assignment
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="supervisor_id">Supervisor <span class="required">*</span></label>
                            <select id="supervisor_id" name="supervisor_id" class="form-control" required>
                                <option value="">Select Supervisor</option>
                                <?php foreach ($supervisors as $supervisor): ?>
                                    <option value="<?= $supervisor['id'] ?>" 
                                            <?= (($_POST['supervisor_id'] ?? '') == $supervisor['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($supervisor['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="incharge_id">In-charge <span class="required">*</span></label>
                            <select id="incharge_id" name="incharge_id" class="form-control" required>
                                <option value="">Select In-charge</option>
                                <?php foreach ($incharges as $incharge): ?>
                                    <option value="<?= $incharge['id'] ?>" 
                                            <?= (($_POST['incharge_id'] ?? '') == $incharge['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($incharge['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                        
                        <div class="form-group">
                            <label for="operator_id">Operator <span class="required">*</span></label>
                            <select id="operator_id" name="operator_id" class="form-control" required>
                                <option value="">Select Operator</option>
                                <?php foreach ($operators as $operator): ?>
                                    <option value="<?= $operator['id'] ?>" 
                                            <?= (($_POST['operator_id'] ?? '') == $operator['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($operator['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <!-- Status and Additional Information Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-cogs"></i> Status & Additional Information
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="packing_status">Packing Status</label>
                            <select id="packing_status" name="packing_status" class="form-control">
                                <option value="active" <?= (($_POST['packing_status'] ?? 'active') == 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="pending" <?= (($_POST['packing_status'] ?? '') == 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="completed" <?= (($_POST['packing_status'] ?? '') == 'completed') ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label for="remarks">Remarks</label>
                            <textarea id="remarks" name="remarks" class="form-control" 
                                      placeholder="Enter any remarks or special notes..."><?= htmlspecialchars($_POST['remarks'] ?? '') ?></textarea>
                        </div>
                        
                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control" 
                                      placeholder="Enter detailed description..."><?= htmlspecialchars($_POST['description'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>


                <!-- Form Actions -->
                <div class="form-actions">
                    <div>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                    </div>
                    <div>
                        <button type="reset" class="btn btn-danger" onclick="resetForm()">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Create Packing Record
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const jtSelect = document.getElementById('jt_id');
    const bookCodeInput = document.getElementById('book_code');
    const jtPrintQtyInput = document.getElementById('jt_print_qty');
    const pQtyInput = document.getElementById('p_qty');
    const quantityAlert = document.getElementById('quantityAlert');
    const jobTicketInfo = document.getElementById('jobTicketInfo');
    const prevContainer = document.getElementById('previousPackingContainer');
    const prevTableBody = document.getElementById('previousPackingTable').querySelector('tbody');
    const totalPackedEl = document.getElementById('totalPacked');
    const remainingCapacityEl = document.getElementById('remainingCapacity');
    const maxAllowedEl = document.getElementById('maxAllowed');

    jtSelect.addEventListener('change', function () {
        const selected = this.options[this.selectedIndex];
        if (!selected.value) {
            resetJobTicketInfo();
            return;
        }

        // Fill job ticket info
        bookCodeInput.value = selected.dataset.bookCode || '';
        jtPrintQtyInput.value = selected.dataset.printQty || '';

        document.getElementById('infoBookCode').textContent = selected.dataset.bookCode || '-';
        document.getElementById('infoBookName').textContent = selected.dataset.bookName || '-';
        document.getElementById('infoClass').textContent = selected.dataset.class || '-';
        document.getElementById('infoLot').textContent = selected.dataset.lot || '-';
        document.getElementById('infoPrintQty').textContent = new Intl.NumberFormat().format(selected.dataset.printQty || 0);
        jobTicketInfo.classList.add('active');

        // Fetch previous packings
        fetch(`get_previous_packings.php?jt_id=${selected.value}`)
            .then(res => res.json())
            .then(data => {
                prevTableBody.innerHTML = '';
                let totalPacked = 0;

                data.forEach(p => {
                    const row = document.createElement('tr');
                    row.innerHTML = `
                        <td>${p.id}</td>
                        <td>${p.name}</td>
                        <td><strong>${new Intl.NumberFormat().format(p.p_qty)}</strong></td>
                        <td>${p.date_eng}</td>
                        <td>${p.date_nep}</td>
                        <td><span class="status-badge status-${p.packing_status.toLowerCase()}">${p.packing_status}</span></td>
                        <td>${p.username || 'Unknown'}</td>
                    `;
                    prevTableBody.appendChild(row);
                    totalPacked += p.p_qty;
                });

                const printQty = parseInt(selected.dataset.printQty) || 0;
                const remaining = printQty - totalPacked;

                totalPackedEl.textContent = new Intl.NumberFormat().format(totalPacked);
                remainingCapacityEl.textContent = new Intl.NumberFormat().format(remaining);
                maxAllowedEl.textContent = new Intl.NumberFormat().format(printQty);

                pQtyInput.value = Math.max(remaining, 0);
                pQtyInput.disabled = remaining <= 0;

                if (remaining <= 0) {
                    alert("This job ticket is fully packed.");
                }

                prevContainer.style.display = 'block';
                validateQuantities();
            })
            .catch(() => {
                prevContainer.style.display = 'none';
            });
    });

    function resetJobTicketInfo() {
        bookCodeInput.value = '';
        jtPrintQtyInput.value = '';
        pQtyInput.value = '';
        jobTicketInfo.classList.remove('active');
        prevContainer.style.display = 'none';
    }

    function validateQuantities() {
        const printQty = parseInt(jtPrintQtyInput.value) || 0;
        const packQty = parseInt(pQtyInput.value) || 0;
        const totalPacked = parseInt(totalPackedEl.textContent.replace(/,/g, '')) || 0;
        const remaining = printQty - totalPacked;

        if (packQty > remaining && remaining > 0) {
            quantityAlert.classList.add('show');
            pQtyInput.style.borderColor = '#dc3545';
        } else {
            quantityAlert.classList.remove('show');
            pQtyInput.style.borderColor = '#e9ecef';
        }
    }

    pQtyInput.addEventListener('input', validateQuantities);

    // Form submit validation
    document.getElementById('packingForm').addEventListener('submit', function (e) {
        const printQty = parseInt(jtPrintQtyInput.value) || 0;
        const packQty = parseInt(pQtyInput.value) || 0;
        const totalPacked = parseInt(totalPackedEl.textContent.replace(/,/g, '')) || 0;
        if (packQty + totalPacked > printQty) {
            e.preventDefault();
            alert(`Total packed quantity cannot exceed ${printQty}.`);
            pQtyInput.focus();
        }
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
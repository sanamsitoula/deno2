<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Get packing ID from URL
if (!isset($_GET['id']) || !is_numeric($_GET['id'])) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}
$packing_id = (int)$_GET['id'];

// Check permissions
if (!has_role('supervisor') && !has_role('incharge') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Fetch existing packing record
$stmt = $conn->prepare("SELECT * FROM book_packing WHERE id = :id");
$stmt->execute([':id' => $packing_id]);
$packing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$packing) {
    ob_end_clean();
    $_SESSION['error_message'] = "Packing record not found.";
    header('Location: index.php');
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

        $p_qty = (int)$_POST['p_qty'];
        $jt_print_qty = (int)$_POST['jt_print_qty'];

        if ($p_qty > $jt_print_qty) {
            throw new Exception("Packed quantity cannot be greater than job ticket print quantity ({$jt_print_qty}).");
        }

        // Update packing record
        $update_sql = "
            UPDATE book_packing SET
                name = :name,
                jt_id = :jt_id,
                jt_print_qty = :jt_print_qty,
                p_qty = :p_qty,
                book_code = :book_code,
                date_nep = :date_nep,
                date_eng = :date_eng,
                supervisor_id = :supervisor_id,
                incharge_id = :incharge_id,
                operator_id = :operator_id,
                packing_status = :packing_status,
                fiscal_year_id = :fiscal_year_id,
                remarks = :remarks,
                description = :description,
                updated_by = :updated_by,
                updated_date = NOW()
            WHERE id = :id
        ";

        $stmt = $conn->prepare($update_sql);
        $stmt->execute([
            ':name' => $_POST['name'],
            ':jt_id' => $_POST['jt_id'],
            ':jt_print_qty' => $jt_print_qty,
            ':p_qty' => $p_qty,
            ':book_code' => $_POST['book_code'],
            ':date_nep' => $_POST['date_nep'],
            ':date_eng' => $_POST['date_eng'],
            ':supervisor_id' => $_POST['supervisor_id'],
            ':incharge_id' => $_POST['incharge_id'],
            ':operator_id' => $_POST['operator_id'],
            ':packing_status' => $_POST['packing_status'] ?? 'active',
            ':fiscal_year_id' => $_POST['fiscal_year_id'],
            ':remarks' => $_POST['remarks'] ?? null,
            ':description' => $_POST['description'] ?? null,
            ':updated_by' => $_SESSION['user_id'],
            ':id' => $packing_id
        ]);

        $conn->commit();
        $_SESSION['success_message'] = "Packing record updated successfully!";
        header('Location: view.php?id=' . $packing_id);
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Get data for form dropdowns
$job_tickets = $conn->query("
    SELECT jt.id, jt.job_ticket_code, jt.lot, jt.print_qty, b.book_name, b.book_code, b.class_level
    FROM job_ticket jt
    LEFT JOIN books b ON jt.book_id = b.book_id
  --  WHERE jt.status = true
    ORDER BY jt.created_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);

$supervisors = $conn->query("SELECT id, username FROM users WHERE role IN ('supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$incharges = $conn->query("SELECT id, username FROM users WHERE role IN ('incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$operators = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Fetch previous packing records for the same job ticket
$previous_packings = [];
if ($packing['jt_id']) {
    $stmt = $conn->prepare("
        SELECT bp.id, bp.p_qty, bp.name, bp.date_nep, bp.date_eng, bp.packing_status,
               u.username as created_by_name
        FROM book_packing bp
        LEFT JOIN users u ON bp.created_by = u.id
        WHERE bp.jt_id = :jt_id AND bp.id != :current_id
        -- AND bp.status = true
        ORDER BY bp.created_date DESC
    ");
    $stmt->execute([':jt_id' => $packing['jt_id'], ':current_id' => $packing_id]);
    $previous_packings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
?>

<style>
    /* Same CSS as create.php — copied exactly */
    .container {
        max-width: 1000px;
        margin: 0 auto;
        padding: 20px;
    }

    h1 {
        margin: 20px 15px;
        color: #333;
        font-size: 32px;
        font-weight: 700;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .form-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        margin: 0 15px;
        overflow: hidden;
        border: 1px solid #e9ecef;
    }

    .form-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px 25px;
        font-size: 18px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .form-content {
        padding: 30px;
    }

    .form-section {
        margin-bottom: 40px;
        padding: 25px;
        background: #f8f9fa;
        border-radius: 8px;
        border-left: 4px solid #667eea;
    }

    .section-title {
        font-size: 18px;
        font-weight: 600;
        color: #333;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .form-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .form-group {
        display: flex;
        flex-direction: column;
    }

    .form-group label {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 8px;
        color: #495057;
    }

    .form-group .required {
        color: #dc3545;
    }

    .form-control {
        padding: 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 8px;
        font-size: 14px;
        transition: all 0.3s ease;
        box-sizing: border-box;
    }

    .form-control:focus {
        outline: none;
        border-color: #667eea;
        box-shadow: 0 0 0 3px rgba(102, 126, 234, 0.1);
    }

    .form-control:invalid {
        border-color: #dc3545;
    }

    textarea.form-control {
        min-height: 100px;
        resize: vertical;
    }

    .btn {
        padding: 12px 24px;
        border: none;
        border-radius: 8px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 600;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
        text-align: center;
        margin-right: 10px;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
        text-decoration: none;
    }

    .btn-primary { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .btn-success { background: linear-gradient(135deg, #667eea 0%, #764ba2 100%); color: white; }
    .btn-secondary { background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%); color: white; }
    .btn-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; }

    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        padding-top: 20px;
        border-top: 1px solid #e9ecef;
        margin-top: 30px;
    }

    .alert {
        margin: 0 15px 20px;
        padding: 15px 20px;
        border-radius: 8px;
        border: 1px solid transparent;
    }

    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border-color: #f5c6cb;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
    }

    .job-ticket-info {
        background: #e3f2fd;
        padding: 15px;
        border-radius: 8px;
        margin-top: 10px;
        display: none;
    }

    .job-ticket-info.active {
        display: block;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 10px;
    }

    .info-item {
        font-size: 13px;
    }

    .info-label {
        font-weight: 600;
        color: #1976d2;
    }

    .info-value {
        color: #424242;
    }

    .quantity-alert {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
        padding: 10px;
        border-radius: 6px;
        margin-top: 10px;
        font-size: 13px;
        display: none;
    }

    .quantity-alert.show {
        display: block;
    }

    /* Table for previous records */
    .previous-records {
        margin-top: 20px;
        border: 1px solid #ddd;
        border-radius: 8px;
        overflow: hidden;
    }

    .previous-records th,
    .previous-records td {
        padding: 12px 15px;
        text-align: left;
        font-size: 13px;
        border-bottom: 1px solid #eee;
    }

    .previous-records th {
        background: #f1f3f5;
        color: #495057;
        font-weight: 600;
    }

    .previous-records tr:hover {
        background: #f8f9fa;
    }

    .status-badge {
        padding: 4px 8px;
        border-radius: 4px;
        font-size: 11px;
        font-weight: bold;
    }

    .status-active {
        background: #d1ecf1;
        color: #0c5460;
    }

    .status-pending {
        background: #fff3cd;
        color: #856404;
    }

    .status-completed {
        background: #d4edda;
        color: #155724;
    }

    @media (max-width: 768px) {
        .form-row {
            grid-template-columns: 1fr;
        }

        .form-actions {
            flex-direction: column;
            align-items: stretch;
        }

        .previous-records th,
        .previous-records td {
            padding: 8px;
            font-size: 12px;
        }
    }
</style>

<div class="container">
    <h1>
        ✏️ Edit Packing Record #<?= $packing_id ?>
        <span style="font-size: 16px; font-weight: normal; color: #6c757d;">Production Management System</span>
    </h1>

    <!-- Success & Error Messages -->
    <?php if (isset($error_message)): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($error_message) ?>
        </div>
    <?php endif; ?>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
            <?php unset($_SESSION['success_message']); ?>
        </div>
    <?php endif; ?>

    <!-- Form Container -->
    <div class="form-container">
        <div class="form-header">
            <span>📝 Packing Record Information</span>
            <span>Edit existing record</span>
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
                                   value="<?= htmlspecialchars($_POST['name'] ?? $packing['name']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="fiscal_year_id">Fiscal Year <span class="required">*</span></label>
                            <select id="fiscal_year_id" name="fiscal_year_id" class="form-control" required>
                                <option value="">Select Fiscal Year</option>
                                <?php foreach ($fiscal_years as $fy): ?>
                                    <option value="<?= $fy['id'] ?>"
                                        <?= (($_POST['fiscal_year_id'] ?? $packing['fiscal_year_id']) == $fy['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($fy['fiscal_code']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
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
                                            data-class="<?= htmlspecialchars($jt['class_level']) ?>"
                                        <?= (($_POST['jt_id'] ?? $packing['jt_id']) == $jt['id']) ? 'selected' : '' ?>>
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
                        </div>
                    </div>
                </div>

                <!-- Quantity and Book Information Section -->
                <div class="form-section">
                    <div class="section-title">
                        <i class="fas fa-boxes"></i> Quantity & Book Details
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="book_code">Book Code <span class="required">*</span></label>
                            <input type="text" id="book_code" name="book_code" class="form-control"
                                   placeholder="Auto-filled from job ticket"
                                   value="<?= htmlspecialchars($_POST['book_code'] ?? $packing['book_code']) ?>" readonly required>
                        </div>

                        <div class="form-group">
                            <label for="jt_print_qty">Job Ticket Print Quantity <span class="required">*</span></label>
                            <input type="number" id="jt_print_qty" name="jt_print_qty" class="form-control"
                                   placeholder="Auto-filled from job ticket"
                                   value="<?= htmlspecialchars($_POST['jt_print_qty'] ?? $packing['jt_print_qty']) ?>" readonly required>
                        </div>

                        <div class="form-group">
                            <label for="p_qty">Packed Quantity <span class="required">*</span></label>
                            <input type="number" id="p_qty" name="p_qty" class="form-control"
                                   placeholder="Enter quantity to be packed" min="1"
                                   value="<?= htmlspecialchars($_POST['p_qty'] ?? $packing['p_qty']) ?>" required>
                            <div class="quantity-alert" id="quantityAlert">
                                <i class="fas fa-exclamation-triangle"></i>
                                Packed quantity cannot exceed job ticket print quantity!
                            </div>
                        </div>
                    </div>
                </div>

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
                                   value="<?= htmlspecialchars($_POST['date_nep'] ?? $packing['date_nep']) ?>" required>
                        </div>

                        <div class="form-group">
                            <label for="date_eng">English Date <span class="required">*</span></label>
                            <input type="date" id="date_eng" name="date_eng" class="form-control"
                                   value="<?= htmlspecialchars($_POST['date_eng'] ?? $packing['date_eng']) ?>" required>
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
                                        <?= (($_POST['supervisor_id'] ?? $packing['supervisor_id']) == $supervisor['id']) ? 'selected' : '' ?>>
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
                                        <?= (($_POST['incharge_id'] ?? $packing['incharge_id']) == $incharge['id']) ? 'selected' : '' ?>>
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
                                        <?= (($_POST['operator_id'] ?? $packing['operator_id']) == $operator['id']) ? 'selected' : '' ?>>
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
                                <option value="active" <?= (($_POST['packing_status'] ?? $packing['packing_status']) == 'active') ? 'selected' : '' ?>>Active</option>
                                <option value="pending" <?= (($_POST['packing_status'] ?? $packing['packing_status']) == 'pending') ? 'selected' : '' ?>>Pending</option>
                                <option value="completed" <?= (($_POST['packing_status'] ?? $packing['packing_status']) == 'completed') ? 'selected' : '' ?>>Completed</option>
                            </select>
                        </div>
                    </div>

                    <div class="form-row">
                        <div class="form-group">
                            <label for="remarks">Remarks</label>
                            <textarea id="remarks" name="remarks" class="form-control"
                                      placeholder="Enter any remarks or special notes..."><?= htmlspecialchars($_POST['remarks'] ?? $packing['remarks']) ?></textarea>
                        </div>

                        <div class="form-group">
                            <label for="description">Description</label>
                            <textarea id="description" name="description" class="form-control"
                                      placeholder="Enter detailed description..."><?= htmlspecialchars($_POST['description'] ?? $packing['description']) ?></textarea>
                        </div>
                    </div>
                </div>

                <!-- Previous Packing Records -->
                <?php if (!empty($previous_packings)): ?>
                    <div class="form-section">
                        <div class="section-title">
                            <i class="fas fa-history"></i> Previous Packing Records (Same Job Ticket)
                        </div>
                        <p style="margin-bottom: 15px; color: #666;">
                            Below are previously created packing records for the same job ticket:
                        </p>
                        <div class="previous-records">
                            <table width="100%">
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
                                <tbody>
                                    <?php foreach ($previous_packings as $prev): ?>
                                        <tr>
                                            <td><?= $prev['id'] ?></td>
                                            <td><?= htmlspecialchars($prev['name']) ?></td>
                                            <td><strong><?= number_format($prev['p_qty']) ?></strong></td>
                                            <td><?= $prev['date_eng'] ?></td>
                                            <td><?= $prev['date_nep'] ?></td>
                                            <td>
                                                <span class="status-badge status-<?= strtolower($prev['packing_status']) ?>">
                                                    <?= ucfirst($prev['packing_status']) ?>
                                                </span>
                                            </td>
                                            <td><?= htmlspecialchars($prev['created_by_name'] ?? 'Unknown') ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Form Actions -->
                <div class="form-actions">
                    <div>
                        <a href="view.php?id=<?= $packing_id ?>" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Cancel
                        </a>
                    </div>
                    <div>
                        <button type="reset" class="btn btn-danger" onclick="return confirm('Reset all changes?')">
                            <i class="fas fa-undo"></i> Reset
                        </button>
                        <button type="submit" class="btn btn-success">
                            <i class="fas fa-save"></i> Save Changes
                        </button>
                    </div>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('packingForm');
    const jtSelect = document.getElementById('jt_id');
    const bookCodeInput = document.getElementById('book_code');
    const jtPrintQtyInput = document.getElementById('jt_print_qty');
    const pQtyInput = document.getElementById('p_qty');
    const quantityAlert = document.getElementById('quantityAlert');
    const jobTicketInfo = document.getElementById('jobTicketInfo');

    // Populate job ticket info on load if already selected
    const selectedOption = jtSelect.options[jtSelect.selectedIndex];
    if (selectedOption.value) {
        bookCodeInput.value = selectedOption.dataset.bookCode || '';
        jtPrintQtyInput.value = selectedOption.dataset.printQty || '';

        document.getElementById('infoBookCode').textContent = selectedOption.dataset.bookCode || '-';
        document.getElementById('infoBookName').textContent = selectedOption.dataset.bookName || '-';
        document.getElementById('infoClass').textContent = selectedOption.dataset.class || '-';
        document.getElementById('infoLot').textContent = selectedOption.dataset.lot || '-';
        document.getElementById('infoPrintQty').textContent = selectedOption.dataset.printQty ?
            new Intl.NumberFormat().format(selectedOption.dataset.printQty) : '-';

        jobTicketInfo.classList.add('active');
    }

    // Handle job ticket change
    jtSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        if (selected.value) {
            bookCodeInput.value = selected.dataset.bookCode || '';
            jtPrintQtyInput.value = selected.dataset.printQty || '';
            pQtyInput.value = selected.dataset.printQty || '';

            document.getElementById('infoBookCode').textContent = selected.dataset.bookCode || '-';
            document.getElementById('infoBookName').textContent = selected.dataset.bookName || '-';
            document.getElementById('infoClass').textContent = selected.dataset.class || '-';
            document.getElementById('infoLot').textContent = selected.dataset.lot || '-';
            document.getElementById('infoPrintQty').textContent = selected.dataset.printQty ?
                new Intl.NumberFormat().format(selected.dataset.printQty) : '-';

            jobTicketInfo.classList.add('active');
        } else {
            bookCodeInput.value = '';
            jtPrintQtyInput.value = '';
            pQtyInput.value = '';
            jobTicketInfo.classList.remove('active');
        }
        validateQuantities();
    });

    // Validate packed quantity
    function validateQuantities() {
        const printQty = parseInt(jtPrintQtyInput.value) || 0;
        const packQty = parseInt(pQtyInput.value) || 0;

        if (packQty > printQty && printQty > 0) {
            quantityAlert.classList.add('show');
            pQtyInput.style.borderColor = '#dc3545';
        } else {
            quantityAlert.classList.remove('show');
            pQtyInput.style.borderColor = '#e9ecef';
        }
    }

    pQtyInput.addEventListener('input', validateQuantities);

    // Format Nepali date
    const dateNepInput = document.getElementById('date_nep');
    dateNepInput.addEventListener('input', function(e) {
        let value = this.value.replace(/[^\d]/g, '');
        if (value.length > 4) value = value.substring(0, 4) + '.' + value.substring(4);
        if (value.length > 7) value = value.substring(0, 7) + '.' + value.substring(7, 9);
        this.value = value.substring(0, 10);
    });

    // Form submit validation
    form.addEventListener('submit', function(e) {
        const printQty = parseInt(jtPrintQtyInput.value) || 0;
        const packQty = parseInt(pQtyInput.value) || 0;

        if (packQty > printQty) {
            e.preventDefault();
            alert('❌ Packed quantity cannot exceed job ticket print quantity of ' + printQty + '!');
            pQtyInput.focus();
            return false;
        }

        const submitBtn = form.querySelector('button[type="submit"]');
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;

        setTimeout(() => {
            submitBtn.innerHTML = originalText;
            submitBtn.disabled = false;
        }, 10000);
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('editor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Get record ID
$id = $_GET['id'] ?? null;
if (!$id || !is_numeric($id)) {
    $_SESSION['error_message'] = "Invalid record ID";
    header('Location: index.php');
    exit();
}

// Get current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Edit form submission data: " . print_r($_POST, true));
    $conn->beginTransaction();
    try {
        // First validate that all required fields exist
        $required_fields = [
            'date_nep', 'date_eng', 'name', 'fiscal_year_id', 'jt_id', 'jtd_id',
            'jtd_targetqty', 'fp_printqty', 'fp_remainqty', 'supervisor_id',
            'operator_id', 'incharge_id', 'shift_id', 'machine_id'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field])){
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            throw new Exception("Missing required fields: " . implode(', ', $missing_fields));
        }

        // Prepare data
        $date_nep = $_POST['date_nep'];
        $date_eng = $_POST['date_eng'];
        $name = $_POST['name'];
        $fiscal_year_id = $_POST['fiscal_year_id'];
        $jt_id = $_POST['jt_id'];
        $jtd_id = $_POST['jtd_id'];
        $jtd_targetqty = $_POST['jtd_targetqty'];
        $fp_printqty = $_POST['fp_printqty'];
        $fp_remainqty = $_POST['fp_remainqty'];
        $supervisor_id = $_POST['supervisor_id'];
        $operator_id = $_POST['operator_id'];
        $incharge_id = $_POST['incharge_id'];
        $shift_id = $_POST['shift_id'];
        $machine_id = $_POST['machine_id'];
        $remarks = $_POST['remarks'] ?? null; // Optional field
        $description = $_POST['description'] ?? null; // Optional field
        
        // Validate quantities before update
        if ($fp_printqty <= 0) {
            throw new Exception("Print quantity must be greater than 0");
        }
        
        // Verify remaining quantity is not negative after this update
        $checkStmt = $conn->prepare("
            SELECT 
                jtd.print_qty as target_qty,
                COALESCE(SUM(fp.fp_printqty), 0) as already_printed
            FROM job_ticket_details jtd
            LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = true AND fp.id != :current_id
            WHERE jtd.id = :jtd_id
            GROUP BY jtd.id, jtd.print_qty
        ");
        $checkStmt->execute([':jtd_id' => $jtd_id, ':current_id' => $id]);
        $qtyCheck = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($qtyCheck) {
            $available_qty = $qtyCheck['target_qty'] - $qtyCheck['already_printed'];
            if ($fp_printqty > $available_qty) {
                throw new Exception("Print quantity ({$fp_printqty}) exceeds available quantity ({$available_qty})");
            }
        }
        
        // Update forma printing record
        $stmt = $conn->prepare("
            UPDATE forma_printing SET
                date_nep = :date_nep,
                date_eng = :date_eng,
                name = :name,
                fiscal_year_id = :fiscal_year_id,
                jt_id = :jt_id,
                jtd_id = :jtd_id,
                jtd_targetqty = :jtd_targetqty,
                fp_printqty = :fp_printqty,
                fp_remainqty = :fp_remainqty,
                supervisor_id = :supervisor_id,
                operator_id = :operator_id,
                incharge_id = :incharge_id,
                shift_id = :shift_id,
                machine_id = :machine_id,
                remarks = :remarks,
                description = :description,
                updated_by = :updated_by,
                updated_date = NOW()
            WHERE id = :id
        ");
        
        $result = $stmt->execute([
            ':id' => $id,
            ':date_nep' => $date_nep,
            ':date_eng' => $date_eng,
            ':name' => $name,
            ':fiscal_year_id' => $fiscal_year_id,
            ':jt_id' => $jt_id,
            ':jtd_id' => $jtd_id,
            ':jtd_targetqty' => $jtd_targetqty,
            ':fp_printqty' => $fp_printqty,
            ':fp_remainqty' => $fp_remainqty,
            ':supervisor_id' => $supervisor_id,
            ':operator_id' => $operator_id,
            ':incharge_id' => $incharge_id,
            ':shift_id' => $shift_id,
            ':machine_id' => $machine_id,
            ':remarks' => $remarks,
            ':description' => $description,
            ':updated_by' => $current_user_id
        ]);
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Forma Printing record updated successfully!";
        ob_end_clean();
        header("Location: forma_printing_view.php?id=$id");
        exit();
        
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error_message'] = "Error updating Forma Printing record: " . $e->getMessage();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Fetch the record to edit
$stmt = $conn->prepare("
    SELECT 
        fp.*,
        jt.job_ticket_code,
        jt.lot,
        b.book_code,
        b.book_name,
        b.class_level,
        jt.print_qty as jt_print_qty,
        jt.page_qty,
        fy.fiscal_code,
        f.name as forma_name
    FROM forma_printing fp
    LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
    LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
    LEFT JOIN forma f ON jtd.forma_id = f.id
    WHERE fp.id = :id
");
$stmt->execute([':id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['error_message'] = "Record not found";
    header('Location: index.php');
    exit();
}

// Get all fiscal years for dropdown
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get job tickets with book information
$job_tickets = $conn->query("
    SELECT 
        jt.id, 
        jt.job_ticket_code, 
        jt.lot,
        b.book_code,
        b.book_name,
        b.class_level,
        jt.print_qty as jt_print_qty,
        jt.page_qty,
        jt.status as jt_status,
        fy.fiscal_code
    FROM job_ticket jt
    LEFT JOIN books b ON jt.book_id = b.book_id
    INNER JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
    WHERE jt.status IN ('active', 'processing','pending')
    ORDER BY jt.job_ticket_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Get users by roles
$supervisors = $conn->query("
    SELECT id, username 
    FROM users 
    WHERE role IN ('supervisor', 'admin') 
    ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

$operators = $conn->query("
    SELECT id, username 
    FROM users 
    WHERE role IN ('operator', 'admin')
    ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

$incharges = $conn->query("
    SELECT id, username 
    FROM users 
    WHERE role IN ('incharge', 'admin')
    ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

// Get shifts and machines
$shifts = $conn->query("SELECT id, name FROM shifts WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
    /* Enhanced styles matching the create page */
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

    .form-container {
        background: #f8f9fa;
        padding: 25px;
        border-radius: 10px;
        margin: 0 15px 25px;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .form-title {
        font-size: 18px;
        font-weight: 600;
        margin-bottom: 20px;
        color: #333;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 2px solid #ffc107;
        padding-bottom: 10px;
    }

    .search-row {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
        margin-bottom: 20px;
    }

    .search-group {
        position: relative;
    }

    .search-group label {
        font-size: 14px;
        font-weight: 600;
        margin-bottom: 6px;
        display: block;
        color: #495057;
    }

    .search-control, .form-control {
        width: 100%;
        padding: 12px 15px;
        border: 2px solid #e9ecef;
        border-radius: 6px;
        font-size: 14px;
        box-sizing: border-box;
        transition: all 0.3s ease;
    }

    .search-control:focus, .form-control:focus {
        outline: none;
        border-color: #ffc107;
        box-shadow: 0 0 0 3px rgba(255,193,7,.1);
    }

    .search-control:read-only, .search-control:disabled {
        background-color: #e9ecef;
        cursor: not-allowed;
        opacity: 0.6;
    }

    .btn {
        padding: 12px 20px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        display: inline-block;
        transition: all 0.3s ease;
        text-align: center;
        margin-right: 10px;
    }

    .btn:hover:not(:disabled) {
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .btn:disabled {
        opacity: 0.6;
        cursor: not-allowed;
        transform: none;
        box-shadow: none;
    }

    .btn-primary { background: #007bff; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-warning { background: #ffc107; color: #212529; }
    .btn-danger { background: #dc3545; color: white; }

    .alert {
        padding: 15px;
        margin: 0 15px 20px;
        border-radius: 6px;
        border: 1px solid transparent;
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

    .alert-warning {
        color: #856404;
        background-color: #fff3cd;
        border-color: #ffeaa7;
    }

    .alert-info {
        color: #0c5460;
        background-color: #d1ecf1;
        border-color: #bee5eb;
    }

    .info-card {
        background: white;
        border: 1px solid #dee2e6;
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 20px;
        box-shadow: 0 1px 3px rgba(0,0,0,0.1);
    }

    .info-card-title {
        font-weight: 600;
        color: #333;
        margin-bottom: 10px;
        font-size: 16px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 15px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
    }

    .info-label {
        font-size: 12px;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 500;
        margin-bottom: 4px;
    }

    .info-value {
        font-size: 14px;
        color: #333;
        font-weight: 500;
    }

    .required {
        color: #dc3545;
    }

    .form-actions {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-top: 30px;
        padding-top: 20px;
        border-top: 1px solid #dee2e6;
    }

    @media (max-width: 768px) {
        .search-row {
            grid-template-columns: 1fr;
        }

        .form-actions {
            flex-direction: column;
            gap: 15px;
        }
    }

    .close {
        background: none;
        border: none;
        font-size: 1.5rem;
        font-weight: bold;
        color: #000;
        opacity: 0.5;
        cursor: pointer;
        float: right;
        margin-left: 10px;
    }

    .close:hover {
        opacity: 1;
    }

    .record-info {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 6px;
        padding: 15px;
        margin-bottom: 20px;
        border-left: 4px solid #ffc107;
    }

    .record-info-title {
        font-weight: 600;
        color: #856404;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
</style>

<div class="container">
    <h2>✏️ Edit Forma Printing Record</h2>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">
                <span>&times;</span>
            </button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
            <button type="button" class="close" onclick="this.parentElement.remove()">
                <span>&times;</span>
            </button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Record Info -->
    <div class="record-info">
        <div class="record-info-title">
            <i class="fas fa-info-circle"></i>
            Currently Editing Record #<?= $record['id'] ?>
        </div>
        <div class="info-grid">
            <div class="info-item">
                <span class="info-label">Original Name</span>
                <span class="info-value"><?= htmlspecialchars($record['name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Job Ticket</span>
                <span class="info-value"><?= htmlspecialchars($record['job_ticket_code']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Book</span>
                <span class="info-value"><?= htmlspecialchars($record['book_code']) ?> - <?= htmlspecialchars($record['book_name']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Current Print Qty</span>
                <span class="info-value"><?= number_format($record['fp_printqty']) ?></span>
            </div>
            <div class="info-item">
                <span class="info-label">Created Date</span>
                <span class="info-value"><?= date('Y-m-d H:i', strtotime($record['created_date'])) ?></span>
            </div>
        </div>
    </div>
    
    <form method="post" id="formaPrintingEditForm" class="needs-validation" novalidate>
        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-calendar-alt"></i> Basic Information
            </div>
            
            <!-- Row 1: Dates and Fiscal Year -->
            <div class="search-row">
                <div class="search-group">
                    <label for="date_nep">📅 Nepali Date <span class="required">*</span></label>
                    <input type="text" name="date_nep" id="date_nep" class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2081.05.15"
                           value="<?= htmlspecialchars($record['date_nep']) ?>"
                           required>
                    <div class="invalid-feedback">Please enter a valid Nepali date (YYYY.MM.DD)</div>
                </div>
                
                <div class="search-group">
                    <label for="date_eng">📅 English Date <span class="required">*</span></label>
                    <input type="text" name="date_eng" id="date_eng" class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2024.08.30"
                           value="<?= htmlspecialchars($record['date_eng']) ?>"
                           required>
                    <div class="invalid-feedback">Please enter a valid English date (YYYY.MM.DD)</div>
                </div>
                
                <div class="search-group">
                    <label for="fiscal_year_id">📆 Fiscal Year <span class="required">*</span></label>
                    <select name="fiscal_year_id" id="fiscal_year_id" class="search-control" required>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= ($fy['id'] == $record['fiscal_year_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Row 2: Name -->
            <div class="search-row">
                <div class="search-group">
                    <label for="name">📛 Record Name <span class="required">*</span></label>
                    <input type="text" name="name" id="name" class="search-control" 
                           value="<?= htmlspecialchars($record['name']) ?>" required>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-clipboard-list"></i> Job Ticket & Forma Information
            </div>
            
            <!-- Job Ticket Info (Read-only for edit) -->
            <div class="info-card">
                <div class="info-card-title">📋 Job Ticket Information (Read-only)</div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Job Ticket Code</span>
                        <span class="info-value"><?= htmlspecialchars($record['job_ticket_code']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Book Code</span>
                        <span class="info-value"><?= htmlspecialchars($record['book_code']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Book Name</span>
                        <span class="info-value"><?= htmlspecialchars($record['book_name']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Class Level</span>
                        <span class="info-value"><?= htmlspecialchars($record['class_level']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Lot Number</span>
                        <span class="info-value"><?= htmlspecialchars($record['lot']) ?></span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Forma Name</span>
                        <span class="info-value"><?= htmlspecialchars($record['forma_name']) ?></span>
                    </div>
                </div>
            </div>
            
            <!-- Hidden fields for Job Ticket and Forma -->
            <input type="hidden" name="jt_id" value="<?= $record['jt_id'] ?>">
            <input type="hidden" name="jtd_id" value="<?= $record['jtd_id'] ?>">
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-calculator"></i> Quantity Information
            </div>
            
            <!-- Row 5: Quantities -->
            <div class="search-row">
                <div class="search-group">
                    <label for="jtd_targetqty">🎯 Target Quantity (JTD)</label>
                    <input type="number" name="jtd_targetqty" id="jtd_targetqty" 
                           class="search-control" value="<?= $record['jtd_targetqty'] ?>" required readonly>
                </div>
                
                <div class="search-group">
                    <label for="fp_printqty">🖨️ Print Quantity <span class="required">*</span></label>
                    <input type="number" name="fp_printqty" id="fp_printqty" 
                           class="search-control" value="<?= $record['fp_printqty'] ?>" required min="1" step="1">
                </div>
                
                <div class="search-group">
                    <label for="fp_remainqty">📦 Remaining Quantity</label>
                    <input type="number" name="fp_remainqty" id="fp_remainqty" 
                           class="search-control" value="<?= $record['fp_remainqty'] ?>" required readonly>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-users"></i> Personnel & Machine Assignment
            </div>
            
            <!-- Row 6: Personnel -->
            <div class="search-row">
                <div class="search-group">
                    <label for="supervisor_id">👔 Supervisor <span class="required">*</span></label>
                    <select name="supervisor_id" id="supervisor_id" class="search-control" required>
                        <option value="">Select Supervisor</option>
                        <?php foreach ($supervisors as $supervisor): ?>
                            <option value="<?= $supervisor['id'] ?>" <?= ($supervisor['id'] == $record['supervisor_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($supervisor['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="operator_id">👷 Operator <span class="required">*</span></label>
                    <select name="operator_id" id="operator_id" class="search-control" required>
                        <option value="">Select Operator</option>
                        <?php foreach ($operators as $operator): ?>
                            <option value="<?= $operator['id'] ?>" <?= ($operator['id'] == $record['operator_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($operator['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="incharge_id">👨‍💼 Incharge <span class="required">*</span></label>
                    <select name="incharge_id" id="incharge_id" class="search-control" required>
                        <option value="">Select Incharge</option>
                        <?php foreach ($incharges as $incharge): ?>
                            <option value="<?= $incharge['id'] ?>" <?= ($incharge['id'] == $record['incharge_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($incharge['username']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <!-- Row 7: Shift and Machine -->
            <div class="search-row">
                <div class="search-group">
                    <label for="shift_id">⏰ Shift <span class="required">*</span></label>
                    <select name="shift_id" id="shift_id" class="search-control" required>
                        <option value="">Select Shift</option>
                        <?php foreach ($shifts as $shift): ?>
                            <option value="<?= $shift['id'] ?>" <?= ($shift['id'] == $record['shift_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($shift['name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="machine_id">🖨️ Machine <span class="required">*</span></label>
                    <select name="machine_id" id="machine_id" class="search-control" required>
                        <option value="">Select Machine</option>
                        <?php foreach ($machines as $machine): ?>
                            <option value="<?= $machine['id'] ?>" <?= ($machine['id'] == $record['machine_id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($machine['machine_name']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-comment-alt"></i> Additional Information
            </div>
            
            <!-- Row 8: Remarks and Description -->
            <div class="search-row">
                <div class="search-group">
                    <label for="remarks">📝 Remarks</label>
                    <textarea name="remarks" id="remarks" class="search-control" rows="3" 
                              placeholder="Enter any remarks or notes..."><?= htmlspecialchars($record['remarks'] ?? '') ?></textarea>
                </div>
                
                <div class="search-group">
                    <label for="description">📄 Description</label>
                    <textarea name="description" id="description" class="search-control" rows="3"
                              placeholder="Enter detailed description..."><?= htmlspecialchars($record['description'] ?? '') ?></textarea>
                </div>
            </div>
        </div>
        
        <!-- Form Actions -->
        <div class="form-actions">
            <div>
                <a href="forma_printing_view.php?id=<?= $record['id'] ?>" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to View
                </a>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-list"></i> Back to List
                </a>
            </div>
            <div>
                <button type="submit" class="btn btn-warning" id="update_btn">
                    <i class="fas fa-save"></i> Update Record
                </button>
            </div>
        </div>
    </form>
</div>

<!-- JavaScript for enhanced functionality -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Format date inputs as user types
    function formatDateInput(input) {
        input.addEventListener('input', function(e) {
            let value = this.value.replace(/[^\d]/g, '');
            if (value.length > 4) {
                value = value.substring(0, 4) + '.' + value.substring(4);
            }
            if (value.length > 7) {
                value = value.substring(0, 7) + '.' + value.substring(7, 9);
            }
            this.value = value.substring(0, 10);
        });
    }
    
    formatDateInput(document.getElementById('date_nep'));
    formatDateInput(document.getElementById('date_eng'));
    
    // Calculate remaining quantity when print quantity changes
    document.getElementById('fp_printqty').addEventListener('input', function() {
        const targetQty = parseInt(document.getElementById('jtd_targetqty').value) || 0;
        const printQty = parseInt(this.value) || 0;
        const remainingQty = Math.max(0, targetQty - printQty);
        
        document.getElementById('fp_remainqty').value = remainingQty;
        
        // Validation
        const updateBtn = document.getElementById('update_btn');
        if (printQty <= 0 || printQty > targetQty) {
            updateBtn.disabled = true;
            updateBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Fix Quantity Issues';
            this.style.borderColor = '#dc3545';
        } else {
            updateBtn.disabled = false;
            updateBtn.innerHTML = '<i class="fas fa-save"></i> Update Record';
            this.style.borderColor = '#28a745';
        }
    });
    
    // Form validation and submission
    document.getElementById('formaPrintingEditForm').addEventListener('submit', function(e) {
        const printQty = parseInt(document.getElementById('fp_printqty').value) || 0;
        const targetQty = parseInt(document.getElementById('jtd_targetqty').value) || 0;
        
        if (printQty <= 0) {
            e.preventDefault();
            alert('Print quantity must be greater than 0');
            document.getElementById('fp_printqty').focus();
            return;
        }
        
        if (printQty > targetQty) {
            e.preventDefault();
            alert('Print quantity cannot exceed target quantity');
            document.getElementById('fp_printqty').focus();
            return;
        }
        
        // Show loading state
        const updateBtn = document.getElementById('update_btn');
        updateBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Updating...';
        updateBtn.disabled = true;
    });
    
    // Enhanced date validation
    document.getElementById('date_nep').addEventListener('blur', function() {
        const nepaliDatePattern = /^\d{4}\.\d{2}\.\d{2}$/;
        if (this.value && !nepaliDatePattern.test(this.value)) {
            this.classList.add('is-invalid');
            alert('Please enter Nepali date in YYYY.MM.DD format');
        } else {
            this.classList.remove('is-invalid');
        }
    });
    
    document.getElementById('date_eng').addEventListener('blur', function() {
        const englishDatePattern = /^\d{4}\.\d{2}\.\d{2}$/;
        if (this.value && !englishDatePattern.test(this.value)) {
            this.classList.add('is-invalid');
            alert('Please enter English date in YYYY.MM.DD format');
        } else {
            this.classList.remove('is-invalid');
        }
    });
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to save
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if (!document.getElementById('update_btn').disabled) {
                document.getElementById('formaPrintingEditForm').submit();
            }
        }
        
        // Escape to go back
        if (e.key === 'Escape') {
            if (confirm('Are you sure you want to go back? Any unsaved changes will be lost.')) {
                window.history.back();
            }
        }
    });
    
    // Warn about unsaved changes
    let formChanged = false;
    const formElements = document.querySelectorAll('input, select, textarea');
    
    formElements.forEach(element => {
        element.addEventListener('change', function() {
            formChanged = true;
        });
    });
    
    window.addEventListener('beforeunload', function(e) {
        if (formChanged && !document.getElementById('update_btn').innerHTML.includes('Updating')) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        }
    });
    
    // Clear form changed flag on form submission
    document.getElementById('formaPrintingEditForm').addEventListener('submit', function() {
        formChanged = false;
    });
    
    console.log('Forma Printing Edit Form initialized successfully');
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
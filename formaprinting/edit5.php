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
if (!$id) {
    $_SESSION['error_message'] = "No record ID provided";
    header('Location: index.php');
    exit();
}

// Get current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Fetch existing record
$stmt = $conn->prepare("
    SELECT fp.*, 
        jt.job_ticket_code, 
        b.book_code,
        b.book_name,
        jtd.forma_id,
        jtd.print_qty as jtd_targetqty,
        jtd.page as jtd_page,
        f.name as forma_name
    FROM forma_printing fp
    LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
    LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
    LEFT JOIN forma f ON jtd.forma_id = f.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    WHERE fp.id = :id
");
$stmt->execute([':id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['error_message'] = "Record not found";
    header('Location: index.php');
    exit();
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $conn->beginTransaction();
    try {
        // Validate required fields
        $required_fields = [
            'date_nep', 'date_eng', 'name', 'fiscal_year_id', 'jt_id', 'jtd_id',
            'jtd_targetqty', 'fp_printqty', 'fp_remainqty', 'supervisor_id',
            'operator_id', 'incharge_id', 'shift_id', 'machine_id'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field])) {
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
        $remarks = $_POST['remarks'] ?? null;
        $description = $_POST['description'] ?? null;
        
        // Validate quantities
        if ($fp_printqty <= 0) {
            throw new Exception("Print quantity must be greater than 0");
        }
        
        // Update record
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
                updated_at = NOW()
            WHERE id = :id
        ");
        
        $result = $stmt->execute([
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
            ':updated_by' => $current_user_id,
            ':id' => $id
        ]);
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Forma Printing record updated successfully!";
        header("Location: forma_printing_view.php?id=$id");
        exit();
        
    } catch (PDOException $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = "Error updating record: " . $e->getMessage();
    } catch (Exception $e) {
        $conn->rollBack();
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Fetch data for dropdowns
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$supervisors = $conn->query("SELECT id, username FROM users WHERE role IN ('supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$operators = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$incharges = $conn->query("SELECT id, username FROM users WHERE role IN ('incharge', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$shifts = $conn->query("SELECT id, name FROM shifts WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name")->fetchAll(PDO::FETCH_ASSOC);

// Get job tickets for dropdown
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

// Get formas for the selected job ticket
$formas = [];
if ($record['jt_id']) {
    $formas = $conn->prepare("
        SELECT 
            jtd.id as jtd_id,
            jtd.print_qty as jtd_target_qty,
            f.name as forma_name,
            jtd.page,
            COALESCE(SUM(fp.fp_printqty), 0) as total_printed,
            (jtd.print_qty - COALESCE(SUM(fp.fp_printqty), 0)) as fp_remaining_qty
        FROM job_ticket_details jtd
        LEFT JOIN forma f ON jtd.forma_id = f.id
        LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = true
        WHERE jtd.job_ticket_id = :jt_id
        GROUP BY jtd.id, jtd.print_qty, f.name, jtd.page
    ");
    $formas->execute([':jt_id' => $record['jt_id']]);
    $formas = $formas->fetchAll(PDO::FETCH_ASSOC);
}
?>

<!-- Reuse the same CSS styles from create.php -->

<div class="container">
    <h2>✏️ Edit Forma Printing Record</h2>
    
    <!-- Display success/error messages -->
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
    
    <form method="post" id="formaPrintingForm">
        <input type="hidden" name="id" value="<?= $id ?>">
        
        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-calendar-alt"></i> Basic Information
            </div>
            
            <div class="search-row">
                <div class="search-group">
                    <label for="date_nep">📅 Nepali Date <span class="required">*</span></label>
                    <input type="text" name="date_nep" id="date_nep" class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2081.05.15"
                           value="<?= htmlspecialchars($record['date_nep']) ?>"
                           required>
                </div>
                
                <div class="search-group">
                    <label for="date_eng">📅 English Date <span class="required">*</span></label>
                    <input type="text" name="date_eng" id="date_eng" class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2024.08.30"
                           value="<?= htmlspecialchars($record['date_eng']) ?>"
                           required>
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
            
            <div class="search-row">
                <div class="search-group">
                    <label for="name">📛 Record Name <span class="required">*</span></label>
                    <input type="text" name="name" id="name" class="search-control" 
                           value="<?= htmlspecialchars($record['name']) ?>"
                           required>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-clipboard-list"></i> Job Ticket & Forma Selection
            </div>
            
            <div class="search-row">
                <div class="search-group search-dropdown">
                    <label for="jt_search">🎫 Job Ticket <span class="required">*</span></label>
                    <input type="text" id="jt_search" class="search-control dropdown-search" 
                           value="<?= htmlspecialchars($record['job_ticket_code'] . ' - ' . $record['book_name']) ?>"
                           placeholder="Search job tickets..." autocomplete="off">
                    <input type="hidden" name="jt_id" id="jt_id" value="<?= $record['jt_id'] ?>" required>
                    <div class="dropdown-options" id="jt_options">
                        <?php foreach ($job_tickets as $jt): ?>
                            <div class="dropdown-option" 
                                 data-value="<?= $jt['id'] ?>" 
                                 data-code="<?= htmlspecialchars($jt['job_ticket_code']) ?>"
                                 data-book-code="<?= htmlspecialchars($jt['book_code']) ?>"
                                 data-book-name="<?= htmlspecialchars($jt['book_name']) ?>"
                                 data-class="<?= htmlspecialchars($jt['class_level']) ?>"
                                 data-lot="<?= htmlspecialchars($jt['lot']) ?>"
                                 data-print-qty="<?= $jt['jt_print_qty'] ?>"
                                 data-page-qty="<?= $jt['page_qty'] ?>"
                                 data-status="<?= $jt['jt_status'] ?>"
                                 data-fiscal-code="<?= htmlspecialchars($jt['fiscal_code']) ?>">
                                <strong><?= htmlspecialchars($jt['job_ticket_code']) ?></strong>
                                <small><?= htmlspecialchars($jt['book_code']) ?> - <?= htmlspecialchars($jt['book_name']) ?> | Class: <?= $jt['class_level'] ?> | Lot: <?= htmlspecialchars($jt['lot']) ?> | FY: <?= htmlspecialchars($jt['fiscal_code']) ?></small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="search-row">
                <div class="search-group">
                    <label for="jtd_id">📄 Forma (Job Ticket Details) <span class="required">*</span></label>
                    <select name="jtd_id" id="jtd_id" class="search-control" required>
                        <?php foreach ($formas as $forma): ?>
                            <option value="<?= $forma['jtd_id'] ?>" 
                                <?= ($forma['jtd_id'] == $record['jtd_id']) ? 'selected' : '' ?>
                                data-print-qty="<?= $forma['jtd_target_qty'] ?>"
                                data-total-printed="<?= $forma['total_printed'] ?>"
                                data-remaining-qty="<?= $forma['fp_remaining_qty'] ?>">
                                <?= htmlspecialchars($forma['forma_name']) ?> - Page: <?= $forma['page'] ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-calculator"></i> Quantity Information
            </div>
            
            <div class="search-row">
                <div class="search-group">
                    <label for="jtd_targetqty">🎯 Target Quantity (JTD)</label>
                    <input type="number" name="jtd_targetqty" id="jtd_targetqty" 
                           class="search-control" value="<?= $record['jtd_targetqty'] ?>" readonly>
                </div>
                
                <div class="search-group">
                    <label for="fp_printqty">🖨️ Print Quantity <span class="required">*</span></label>
                    <input type="number" name="fp_printqty" id="fp_printqty" 
                           class="search-control" value="<?= $record['fp_printqty'] ?>" required min="1" step="1">
                    <div class="quantity-warning" id="qty_warning"></div>
                </div>
                
                <div class="search-group">
                    <label for="fp_remainqty">📦 Remaining Quantity</label>
                    <input type="number" name="fp_remainqty" id="fp_remainqty" 
                           class="search-control" value="<?= $record['fp_remainqty'] ?>" readonly>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-users"></i> Personnel & Machine Assignment
            </div>
            
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
            
            <div class="search-row">
                <div class="search-group">
                    <label for="remarks">📝 Remarks</label>
                    <textarea name="remarks" id="remarks" class="search-control" rows="3"><?= htmlspecialchars($record['remarks']) ?></textarea>
                </div>
                
                <div class="search-group">
                    <label for="description">📄 Description</label>
                    <textarea name="description" id="description" class="search-control" rows="3"><?= htmlspecialchars($record['description']) ?></textarea>
                </div>
            </div>
        </div>
        
        <div class="form-actions">
            <div>
                <a href="forma_printing_view.php?id=<?= $id ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
            <div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Update Record
                </button>
            </div>
        </div>
    </form>
</div>

<!-- Reuse JavaScript from create.php with minor adjustments -->
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize all JavaScript functionality from create.php
    // Make sure to handle the pre-selected values appropriately
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
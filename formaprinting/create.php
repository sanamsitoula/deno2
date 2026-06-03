<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('incharge') && !has_role('supervisor') && !has_role('admin') && !has_role('operator') ) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Get current user ID
$current_user_id = $_SESSION['user_id'] ?? null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
 error_log("Form submission data: " . print_r($_POST, true));
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
        
        // Validate quantities before insertion
        if ($fp_printqty <= 0) {
            throw new Exception("Print quantity must be greater than 0");
        }
        
        // Verify remaining quantity is not negative after this print
        $checkStmt = $conn->prepare("
            SELECT 
                jtd.print_qty as target_qty,
                COALESCE(SUM(fp.fp_printqty), 0) as already_printed
            FROM job_ticket_details jtd
            LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = true
            WHERE jtd.id = :jtd_id
            GROUP BY jtd.id, jtd.print_qty
        ");
        $checkStmt->execute([':jtd_id' => $jtd_id]);
        $qtyCheck = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($qtyCheck) {
            $available_qty = $qtyCheck['target_qty'] - $qtyCheck['already_printed'];
            if ($fp_printqty > $available_qty) {
                throw new Exception("Print quantity ({$fp_printqty}) exceeds available quantity ({$available_qty})");
            }
        }
        
        // Insert forma printing record
        $stmt = $conn->prepare("
            INSERT INTO forma_printing (
                date_nep, date_eng, name, fiscal_year_id, jt_id, jtd_id,
                jtd_targetqty, fp_printqty, fp_remainqty, supervisor_id,
                created_by, operator_id, incharge_id, shift_id, machine_id,
                remarks, description, status, created_date
            ) VALUES (
                :date_nep, :date_eng, :name, :fiscal_year_id, :jt_id, :jtd_id,
                :jtd_targetqty, :fp_printqty, :fp_remainqty, :supervisor_id,
                :created_by, :operator_id, :incharge_id, :shift_id, :machine_id,
                :remarks, :description, true, NOW()
            )
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
            ':created_by' => $current_user_id,
            ':operator_id' => $operator_id,
            ':incharge_id' => $incharge_id,
            ':shift_id' => $shift_id,
            ':machine_id' => $machine_id,
            ':remarks' => $remarks,
            ':description' => $description
        ]);
        
        // Get the inserted ID
        $forma_printing_id = $conn->lastInsertId();
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Forma Printing record created successfully!";
        ob_end_clean();
        header("Location: index.php?id=$forma_printing_id");
        exit();
        
    } catch (PDOException $e) {
      if ($conn->inTransaction()) { // Only rollback if in a transaction
            $conn->rollBack();
        }
        $_SESSION['error_message'] = "Error creating Forma Printing record: " . $e->getMessage();
    } catch (Exception $e) {
        if ($conn->inTransaction()) { // Only rollback if in a transaction
            $conn->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Get current fiscal year (active)
$current_fiscal_year = $conn->query("
    SELECT id, fiscal_code 
    FROM fiscal_years 
    WHERE is_active = true 
    LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// Get all fiscal years for dropdown
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);

// Get job tickets with book information from active fiscal year only
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
    AND fy.is_active = true
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

// Get current date in Nepali (you might want to use a proper Nepali date function)
$current_nepali_date = date('Y.m.d'); // Replace with actual Nepali date conversion
$current_english_date = date('Y.m.d');
?>

<style>
    /* Enhanced styles matching the index page */
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
        border-bottom: 2px solid #007bff;
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
        border-color: #007bff;
        box-shadow: 0 0 0 3px rgba(0,123,255,.1);
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

    .search-dropdown {
        position: relative;
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
        border-radius: 0 0 6px 6px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.1);
    }

    .dropdown-option {
        padding: 12px 15px;
        cursor: pointer;
        border-bottom: 1px solid #eee;
        transition: background-color 0.2s ease;
    }

    .dropdown-option:hover {
        background-color: #f8f9fa;
    }

    .dropdown-option:last-child {
        border-bottom: none;
    }

    .dropdown-option strong {
        display: block;
        color: #333;
        font-size: 14px;
    }

    .dropdown-option small {
        display: block;
        color: #666;
        font-size: 12px;
        margin-top: 2px;
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

    .previous-records {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 6px;
        padding: 15px;
        margin-top: 15px;
    }

    .previous-records-title {
        font-weight: 600;
        color: #856404;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .previous-record-item {
        background: white;
        border-radius: 4px;
        padding: 10px;
        margin-bottom: 8px;
        border-left: 4px solid #ffc107;
        font-size: 13px;
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

    .loading {
        opacity: 0.6;
        pointer-events: none;
        position: relative;
    }

    .loading::after {
        content: "";
        position: absolute;
        top: 50%;
        left: 50%;
        width: 20px;
        height: 20px;
        margin: -10px 0 0 -10px;
        border: 2px solid #ccc;
        border-top-color: #007bff;
        border-radius: 50%;
        animation: spin 1s linear infinite;
    }

    @keyframes spin {
        0% { transform: rotate(0deg); }
        100% { transform: rotate(360deg); }
    }

    .quantity-warning {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        color: #856404;
        padding: 8px 12px;
        border-radius: 4px;
        font-size: 13px;
        margin-top: 5px;
        display: none;
    }

    .quantity-error {
        background: #f8d7da;
        border: 1px solid #f5c6cb;
        color: #721c24;
    }

    .quantity-success {
        background: #d4edda;
        border: 1px solid #c3e6cb;
        color: #155724;
    }

    .is-invalid {
        border-color: #dc3545;
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

    .status-indicator {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        padding: 2px 8px;
        border-radius: 12px;
        font-weight: 500;
    }

    .status-completed {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .status-in-progress {
        background: #d1ecf1;
        color: #0c5460;
        border: 1px solid #bee5eb;
    }

    .status-not-started {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
    }
</style>

<div class="container">
    <h2>➕ Create Forma Printing Record</h2>
    
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
    
    <form method="post" id="formaPrintingForm"  enctype="multipart/form-data" class="needs-validation" novalidate>
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
                           value="<?= htmlspecialchars($current_nepali_date) ?>"
                           required>
                    <div class="invalid-feedback">Please enter a valid Nepali date (YYYY.MM.DD)</div>
                </div>
                
                <div class="search-group">
                    <label for="date_eng">📅 English Date <span class="required">*</span></label>
                    <input type="text" name="date_eng" id="date_eng" class="search-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="2024.08.30"
                           value="<?= htmlspecialchars($current_english_date) ?>"
                           required>
                    <div class="invalid-feedback">Please enter a valid English date (YYYY.MM.DD)</div>
                </div>
                
                <div class="search-group">
                    <label for="fiscal_year_id">📆 Fiscal Year <span class="required">*</span></label>
                    <select name="fiscal_year_id" id="fiscal_year_id" class="search-control" required>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>" <?= ($fy['id'] == $current_fiscal_year['id']) ? 'selected' : '' ?>>
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
                    <input type="text" name="name" id="name" class="search-control" required readonly>
                    <small class="form-text text-muted">Auto-generated from Job Ticket and Date</small>
                </div>
            </div>
        </div>

        <div class="form-container">
            <div class="form-title">
                <i class="fas fa-clipboard-list"></i> Job Ticket & Forma Selection
            </div>
            
            <!-- Row 3: Job Ticket Selection -->
            <div class="search-row">
                <div class="search-group search-dropdown">
                    <label for="jt_search">🎫 Job Ticket <span class="required">*</span></label>
                    <input type="text" id="jt_search" class="search-control dropdown-search" 
                           placeholder="Search job tickets..." autocomplete="off">
                    <input type="hidden" name="jt_id" id="jt_id" required>
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
                    <div class="invalid-feedback">Please select a job ticket</div>
                </div>
            </div>

            <!-- Job Ticket Info Card -->
            <div class="info-card" id="jt_info_card" style="display: none;">
                <div class="info-card-title">📋 Job Ticket Information</div>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Book Code</span>
                        <span class="info-value" id="jt_book_code">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Book Name</span>
                        <span class="info-value" id="jt_book_name">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Class Level</span>
                        <span class="info-value" id="jt_class">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Lot Number</span>
                        <span class="info-value" id="jt_lot">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Total Print Quantity</span>
                        <span class="info-value" id="jt_print_qty">-</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Page Quantity</span>
                        <span class="info-value" id="jt_page_qty">-</span>
                    </div>
                </div>
            </div>
            
            <!-- Job Ticket Progress Summary Card -->
            <div class="info-card" id="jt_summary_card" style="display: none;"></div>
            
            <!-- Row 4: Forma Selection -->
            <div class="search-row">
                <div class="search-group">
                    <label for="jtd_id">📄 Forma (Job Ticket Details) <span class="required">*</span></label>
                    <select name="jtd_id" id="jtd_id" class="search-control" required disabled>
                        <option value="">Please select a job ticket first</option>
                    </select>
                    <div class="invalid-feedback">Please select a forma</div>
                </div>
            </div>

            <!-- Forma Status Info Card -->
            <div id="forma_status_card" style="display: none;"></div>

            <!-- Previous Records Info -->
            <div id="previous_records" style="display: none;"></div>
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
                           class="search-control" required readonly>
                </div>
                
                <div class="search-group">
                    <label for="fp_printqty">🖨️ Print Quantity <span class="required">*</span></label>
                    <input type="number" name="fp_printqty" id="fp_printqty" 
                           class="search-control" required min="1" step="1" disabled>
                    <div class="quantity-warning" id="qty_warning"></div>
                </div>
                
                <div class="search-group">
                    <label for="fp_remainqty">📦 Remaining Quantity</label>
                    <input type="number" name="fp_remainqty" id="fp_remainqty" 
                           class="search-control" required readonly>
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
                            <option value="<?= $supervisor['id'] ?>"><?= htmlspecialchars($supervisor['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="operator_id">👷 Operator <span class="required">*</span></label>
                    <select name="operator_id" id="operator_id" class="search-control" required>
                        <option value="">Select Operator</option>
                        <?php foreach ($operators as $operator): ?>
                            <option value="<?= $operator['id'] ?>"><?= htmlspecialchars($operator['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="incharge_id">👨‍💼 Incharge <span class="required">*</span></label>
                    <select name="incharge_id" id="incharge_id" class="search-control" required>
                        <option value="">Select Incharge</option>
                        <?php foreach ($incharges as $incharge): ?>
                            <option value="<?= $incharge['id'] ?>"><?= htmlspecialchars($incharge['username']) ?></option>
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
                            <option value="<?= $shift['id'] ?>"><?= htmlspecialchars($shift['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="search-group">
                    <label for="machine_id">🖨️ Machine <span class="required">*</span></label>
                    <select name="machine_id" id="machine_id" class="search-control" required>
                        <option value="">Select Machine</option>
                        <?php foreach ($machines as $machine): ?>
                            <option value="<?= $machine['id'] ?>"><?= htmlspecialchars($machine['machine_name']) ?></option>
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
                              placeholder="Enter any remarks or notes..."></textarea>
                </div>
                
                <div class="search-group">
                    <label for="description">📄 Description</label>
                    <textarea name="description" id="description" class="search-control" rows="3"
                              placeholder="Enter detailed description..."></textarea>
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
                <button type="reset" class="btn btn-warning" id="reset_btn">
                    <i class="fas fa-undo"></i> Reset Form
                </button>
                <button type="submit" class="btn btn-primary" id="submit_btn" disabled>
                    <i class="fas fa-save"></i> Save Forma Printing
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
            
            updateNameField();
        });
    }
    
    formatDateInput(document.getElementById('date_nep'));
    formatDateInput(document.getElementById('date_eng'));
    
    // Auto-generate name when date or JT is selected
    function updateNameField() {
        const dateNep = document.getElementById('date_nep').value;
        const jtCode = document.getElementById('jt_search').value.split(' - ')[0];
        
        if (dateNep && jtCode) {
            document.getElementById('name').value = `${jtCode} - ${dateNep}`;
        }
    }
    
    // Job Ticket search dropdown functionality
    const jtSearch = document.getElementById('jt_search');
    const jtIdInput = document.getElementById('jt_id');
    const jtOptions = document.getElementById('jt_options');
    const jtInfoCard = document.getElementById('jt_info_card');
    
    jtSearch.addEventListener('focus', function() {
        jtOptions.style.display = 'block';
        filterJobTicketOptions();
    });
    
    jtSearch.addEventListener('input', function() {
        filterJobTicketOptions();
        jtOptions.style.display = 'block';
        
        // Clear job ticket selection if search is cleared
        if (this.value.trim() === '') {
            jtIdInput.value = '';
            jtInfoCard.style.display = 'none';
            resetFormaSelection();
        }
    });
    
    document.addEventListener('click', function(e) {
        if (!e.target.closest('.search-dropdown')) {
            jtOptions.style.display = 'none';
        }
    });
    
    function filterJobTicketOptions() {
        const searchTerm = jtSearch.value.toLowerCase();
        const options = jtOptions.querySelectorAll('.dropdown-option');
        
        options.forEach(option => {
            const text = option.textContent.toLowerCase();
            if (text.includes(searchTerm) || searchTerm === '') {
                option.style.display = 'block';
            } else {
                option.style.display = 'none';
            }
        });
    }
    
    // Handle Job Ticket selection
    jtOptions.querySelectorAll('.dropdown-option').forEach(option => {
        option.addEventListener('click', function() {
            const jtCode = this.dataset.code;
            jtSearch.value = jtCode + ' - ' + this.dataset.bookName;
            jtIdInput.value = this.dataset.value;
            jtOptions.style.display = 'none';
            
            // Update job ticket info card
            updateJobTicketInfo(this);
            
            // Load formas for this job ticket
            loadJobTicketFormas(this.dataset.value);
            
            // Update name field
            updateNameField();
        });
    });
    
    function updateJobTicketInfo(optionElement) {
        document.getElementById('jt_book_code').textContent = optionElement.dataset.bookCode || '-';
        document.getElementById('jt_book_name').textContent = optionElement.dataset.bookName || '-';
        document.getElementById('jt_class').textContent = optionElement.dataset.class || '-';
        document.getElementById('jt_lot').textContent = optionElement.dataset.lot || '-';
        document.getElementById('jt_print_qty').textContent = formatNumber(optionElement.dataset.printQty) || '-';
        document.getElementById('jt_page_qty').textContent = formatNumber(optionElement.dataset.pageQty) || '-';
        
        jtInfoCard.style.display = 'block';
    }
    
    // Load Job Ticket Formas using the new API
    function loadJobTicketFormas(jtId) {
        const jtdSelect = document.getElementById('jtd_id');
        const previousRecords = document.getElementById('previous_records');
        const summaryCard = document.getElementById('jt_summary_card');
        
        jtdSelect.innerHTML = '<option value="">Loading formas...</option>';
        jtdSelect.disabled = true;
        previousRecords.style.display = 'none';
        summaryCard.style.display = 'none';
        
        // Reset quantity fields
        resetQuantityFields();
        
        fetch(`getformadetailsfromjobticketid.php?job_ticket_id=${jtId}`)
            .then(response => {
                if (!response.ok) {
                    throw new Error(`HTTP error! status: ${response.status}`);
                }
                return response.json();
            })
            .then(data => {
                if (!data.success) {
                    throw new Error(data.message || 'Failed to load forma details');
                }
                
                jtdSelect.innerHTML = '<option value="">Select Forma</option>';
                
                if (!data.has_formas || data.forma_details.length === 0) {
                    jtdSelect.innerHTML = '<option value="">No formas available for this job ticket</option>';
                    showAlert('No formas found for the selected job ticket', 'warning');
                    return;
                }
                
                // Update job ticket summary display
                updateJobTicketSummary(data.summary);
                
                // Populate forma options
                data.forma_details.forEach(forma => {
                    const option = document.createElement('option');
                    option.value = forma.jtd_id;
                    
                    // Create descriptive option text with status indicators
                    const statusIcon = getStatusIcon(forma.printing_status);
                    const completionText = forma.completion_percentage > 0 
                        ? ` (${forma.completion_percentage}% complete)` 
                        : '';
                    const remainingText = forma.fp_remaining_qty > 0 
                        ? ` - Remaining: ${formatNumber(forma.fp_remaining_qty)}` 
                        : '';
                    
                    option.textContent = `${statusIcon} ${forma.forma_display_name} - Target: ${formatNumber(forma.jtd_target_qty)}${remainingText}${completionText}`;
                    
                    // Store forma data in dataset
                    option.dataset.printQty = forma.jtd_target_qty;
                    option.dataset.totalPrinted = forma.total_printed;
                    option.dataset.remainingQty = forma.fp_remaining_qty;
                    option.dataset.formaName = forma.forma_name;
                    option.dataset.orderNo = forma.order_no;
                    option.dataset.page = forma.page;
                    option.dataset.printingStatus = forma.printing_status;
                    option.dataset.canPrint = forma.can_print;
                    option.dataset.completionPercentage = forma.completion_percentage;
                    
                    // Disable option if cannot print (fully completed or over-printed)
                    if (!forma.can_print) {
                        option.disabled = true;
                        option.style.color = '#6c757d';
                        option.textContent += ' [COMPLETED]';
                    }
                    
                    jtdSelect.appendChild(option);
                });
                
                jtdSelect.disabled = false;
                
                // Show success message with summary
                const message = `Loaded ${data.forma_details.length} formas. Overall completion: ${data.summary.overall_completion_percentage}%`;
                showAlert(message, 'info', 3000);
                
            })
            .catch(error => {
                console.error('Error loading forma details:', error);
                jtdSelect.innerHTML = '<option value="">Error loading formas</option>';
                showAlert('Error loading forma details: ' + error.message, 'danger');
            });
    }
    
    // Helper function to reset forma selection
    function resetFormaSelection() {
        const jtdSelect = document.getElementById('jtd_id');
        jtdSelect.innerHTML = '<option value="">Please select a job ticket first</option>';
        jtdSelect.disabled = true;
        
        resetQuantityFields();
        hideStatusCards();
    }
    
    function resetQuantityFields() {
        document.getElementById('jtd_targetqty').value = '';
        document.getElementById('fp_printqty').value = '';
        document.getElementById('fp_remainqty').value = '';
        document.getElementById('fp_printqty').disabled = true;
        document.getElementById('fp_printqty').placeholder = 'Select forma first';
        
        // Hide quantity warnings
        document.getElementById('qty_warning').style.display = 'none';
        document.getElementById('fp_printqty').classList.remove('is-invalid');
        
        // Disable submit button
        document.getElementById('submit_btn').disabled = true;
    }
    
    function hideStatusCards() {
        const formaStatusCard = document.getElementById('forma_status_card');
        const previousRecords = document.getElementById('previous_records');
        const summaryCard = document.getElementById('jt_summary_card');
        
        if (formaStatusCard) formaStatusCard.style.display = 'none';
        if (previousRecords) previousRecords.style.display = 'none';
        if (summaryCard) summaryCard.style.display = 'none';
    }
    
    // Helper function to get status icons
    function getStatusIcon(status) {
        switch(status) {
            case 'completed': return '✅';
            case 'in_progress': return '🔄';
            case 'not_started': return '⏳';
            default: return '📄';
        }
    }
    
    // Handle Forma selection
    document.getElementById('jtd_id').addEventListener('change', function() {
        if (this.value) {
            const selectedOption = this.options[this.selectedIndex];
            const targetQty = parseInt(selectedOption.dataset.printQty) || 0;
            const totalPrinted = parseInt(selectedOption.dataset.totalPrinted) || 0;
            const remainingQty = parseInt(selectedOption.dataset.remainingQty) || 0;
            const printingStatus = selectedOption.dataset.printingStatus;
            const canPrint = selectedOption.dataset.canPrint === 'true';
            
            // Update quantity fields
            document.getElementById('jtd_targetqty').value = targetQty;
            document.getElementById('fp_remainqty').value = remainingQty;
            
            // Clear print quantity input
            document.getElementById('fp_printqty').value = '';
            
            // Show status information
            showFormaStatusInfo({
                targetQty: targetQty,
                totalPrinted: totalPrinted,
                remainingQty: remainingQty,
                printingStatus: printingStatus,
                canPrint: canPrint,
                completionPercentage: parseFloat(selectedOption.dataset.completionPercentage) || 0
            });
            
            // Load previous printing records for this forma
            loadPreviousRecords(this.value);
            
            // Enable/disable print quantity input based on whether printing is allowed
            const printQtyInput = document.getElementById('fp_printqty');
            printQtyInput.disabled = !canPrint;
            
            if (!canPrint) {
                printQtyInput.placeholder = 'Forma printing completed';
                showAlert('This forma has been fully printed and cannot accept more print jobs.', 'warning');
                document.getElementById('submit_btn').disabled = true;
            } else {
                printQtyInput.placeholder = `Enter quantity (max: ${formatNumber(remainingQty)})`;
                printQtyInput.max = remainingQty;
                printQtyInput.focus();
            }
            
        } else {
            // Reset all fields if no forma selected
            resetQuantityFields();
            hideFormaStatusCard();
        }
    });
    
    // Function to show forma status information
    function showFormaStatusInfo(info) {
        let statusCard = document.getElementById('forma_status_card');
        
        if (!statusCard) {
            statusCard = document.createElement('div');
            statusCard.className = 'info-card';
            statusCard.id = 'forma_status_card';
            
            // Insert after the forma selection
            const formaGroup = document.getElementById('jtd_id').closest('.search-group');
            formaGroup.insertAdjacentElement('afterend', statusCard);
        }
        
        statusCard.innerHTML = `
            <div class="info-card-title">📊 Selected Forma Status</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Target Quantity</span>
                    <span class="info-value">${formatNumber(info.targetQty)}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Total Printed</span>
                    <span class="info-value">${formatNumber(info.totalPrinted)}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Remaining</span>
                    <span class="info-value">${formatNumber(info.remainingQty)}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Completion</span>
                    <span class="info-value">${info.completionPercentage}%</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="status-indicator status-${info.printingStatus.replace('_', '-')}">
                            ${getStatusIcon(info.printingStatus)} ${info.printingStatus.replace('_', ' ').toUpperCase()}
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">Can Print More</span>
                    <span class="info-value">${info.canPrint ? '✅ Yes' : '❌ No'}</span>
                </div>
            </div>
        `;
        
        statusCard.style.display = 'block';
    }
    
    function hideFormaStatusCard() {
        const statusCard = document.getElementById('forma_status_card');
        if (statusCard) {
            statusCard.style.display = 'none';
        }
        document.getElementById('previous_records').style.display = 'none';
    }
    
    // Function to update job ticket summary display
    function updateJobTicketSummary(summary) {
        const summaryCard = document.getElementById('jt_summary_card');
        
        summaryCard.innerHTML = `
            <div class="info-card-title">📈 Job Ticket Progress Summary</div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Total Formas</span>
                    <span class="info-value">${summary.total_formas}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Completed</span>
                    <span class="info-value">${summary.completed_formas}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Pending</span>
                    <span class="info-value">${summary.pending_formas}</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Overall Progress</span>
                    <span class="info-value">${summary.overall_completion_percentage}%</span>
                </div>
                <div class="info-item">
                    <span class="info-label">Status</span>
                    <span class="info-value">
                        <span class="status-indicator ${summary.is_fully_completed ? 'status-completed' : 'status-in-progress'}">
                            ${summary.is_fully_completed ? '✅ COMPLETED' : '🔄 IN PROGRESS'}
                        </span>
                    </span>
                </div>
            </div>
        `;
        
        summaryCard.style.display = 'block';
    }
    
    // Load previous printing records
    function loadPreviousRecords(jtdId) {
        const previousRecords = document.getElementById('previous_records');
        
        fetch(`getpreviousformaprinting.php?jtd_id=${jtdId}`)
            .then(response => response.json())
            .then(data => {
                if (data.records && data.records.length > 0) {
                    let html = `
                        <div class="previous-records">
                            <div class="previous-records-title">
                                <i class="fas fa-history"></i> 
                                Previous Printing Records (${data.records.length} found)
                            </div>
                            <div class="info-grid" style="margin-bottom: 10px;">
                                <div class="info-item">
                                    <span class="info-label">Total Printed Previously</span>
                                    <span class="info-value">${formatNumber(data.total_printed)}</span>
                                </div>
                                <div class="info-item">
                                    <span class="info-label">Available to Print</span>
                                    <span class="info-value">${formatNumber(data.remaining_qty)}</span>
                                </div>
                            </div>
                    `;
                    
                    data.records.forEach(record => {
                        html += `
                            <div class="previous-record-item">
                                <strong>Date:</strong> ${record.date_nep} | 
                                <strong>Printed:</strong> ${formatNumber(record.fp_printqty)} | 
                                <strong>Machine:</strong> ${record.machine_name} | 
                                <strong>Operator:</strong> ${record.operator_name}
                            </div>
                        `;
                    });
                    
                    html += '</div>';
                    previousRecords.innerHTML = html;
                    previousRecords.style.display = 'block';
                } else {
                    previousRecords.style.display = 'none';
                }
            })
            .catch(error => {
                console.error('Error loading previous records:', error);
                previousRecords.style.display = 'none';
            });
    }
    
    // Calculate remaining quantity when print quantity changes
    document.getElementById('fp_printqty').addEventListener('input', function() {
        calculateRemainingQty();
        validateQuantities();
    });
    
    function calculateRemainingQty() {
        const jtdSelect = document.getElementById('jtd_id');
        if (!jtdSelect.value) return;
        
        const selectedOption = jtdSelect.options[jtdSelect.selectedIndex];
        const currentRemaining = parseInt(selectedOption.dataset.remainingQty) || 0;
        const printQty = parseInt(document.getElementById('fp_printqty').value) || 0;
        
        const newRemaining = Math.max(0, currentRemaining - printQty);
        document.getElementById('fp_remainqty').value = newRemaining;
        
        // Update quantity warnings
        updateQuantityWarnings(printQty, currentRemaining, newRemaining);
    }
    
    function updateQuantityWarnings(printQty, availableQty, remainingQty) {
        const warningDiv = document.getElementById('qty_warning');
        const printQtyInput = document.getElementById('fp_printqty');
        
        warningDiv.style.display = 'none';
        warningDiv.className = 'quantity-warning';
        printQtyInput.classList.remove('is-invalid');
        
        if (printQty <= 0) {
            warningDiv.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Print quantity must be greater than 0';
            warningDiv.className = 'quantity-warning quantity-error';
            warningDiv.style.display = 'block';
            printQtyInput.classList.add('is-invalid');
        } else if (printQty > availableQty) {
            warningDiv.innerHTML = `<i class="fas fa-exclamation-triangle"></i> Print quantity (${formatNumber(printQty)}) exceeds available quantity (${formatNumber(availableQty)})`;
            warningDiv.className = 'quantity-warning quantity-error';
            warningDiv.style.display = 'block';
            printQtyInput.classList.add('is-invalid');
        } else if (printQty === availableQty) {
            warningDiv.innerHTML = '<i class="fas fa-check"></i> This will complete the forma printing';
            warningDiv.className = 'quantity-warning quantity-success';
            warningDiv.style.display = 'block';
        } else if (printQty > 0) {
            warningDiv.innerHTML = `<i class="fas fa-info-circle"></i> Will print ${formatNumber(printQty)} units. ${formatNumber(remainingQty)} will remain.`;
            warningDiv.className = 'quantity-warning';
            warningDiv.style.backgroundColor = '#d1ecf1';
            warningDiv.style.borderColor = '#bee5eb';
            warningDiv.style.color = '#0c5460';
            warningDiv.style.display = 'block';
        }
    }
    
    function validateQuantities() {
        const printQty = parseInt(document.getElementById('fp_printqty').value) || 0;
        const remainingQty = parseInt(document.getElementById('fp_remainqty').value) || 0;
        const submitBtn = document.getElementById('submit_btn');
        
        const jtdSelect = document.getElementById('jtd_id');
        const selectedOption = jtdSelect.options[jtdSelect.selectedIndex];
        const availableQty = parseInt(selectedOption?.dataset?.remainingQty) || 0;
        
        if (printQty <= 0 || printQty > availableQty || !jtdSelect.value) {
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Fix Issues to Save';
        } else {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-save"></i> Save Forma Printing';
        }
    }
    
    // Form validation and submission
    document.getElementById('formaPrintingForm').addEventListener('submit', function(e) {
        const printQty = parseInt(document.getElementById('fp_printqty').value) || 0;
        const jtdSelect = document.getElementById('jtd_id');
        
        if (!jtdSelect.value) {
            e.preventDefault();
            showAlert('Please select a forma before saving', 'danger');
            jtdSelect.focus();
            return;
        }
        
        const selectedOption = jtdSelect.options[jtdSelect.selectedIndex];
        const availableQty = parseInt(selectedOption.dataset.remainingQty) || 0;
        
        if (printQty <= 0) {
            e.preventDefault();
            showAlert('Print quantity must be greater than 0', 'danger');
            document.getElementById('fp_printqty').focus();
            return;
        }
        
        if (printQty > availableQty) {
            e.preventDefault();
            showAlert('Print quantity cannot exceed available quantity', 'danger');
            document.getElementById('fp_printqty').focus();
            return;
        }
        
        // Show loading state
        const submitBtn = document.getElementById('submit_btn');
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        submitBtn.disabled = true;
        
        // Disable form to prevent double submission
      //  const formElements = this.querySelectorAll('input, select, textarea, button');
       // formElements.forEach(element => element.disabled = true);

//         // Only disable submit button
// submitBtn.disabled = true;
// submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';


    });
    
    // Reset form functionality
    document.getElementById('reset_btn').addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
            // Reset all cards and states
            document.getElementById('jt_info_card').style.display = 'none';
            document.getElementById('jt_summary_card').style.display = 'none';
            hideFormaStatusCard();
            resetFormaSelection();
            
            // Reset form fields
            document.getElementById('formaPrintingForm').reset();
            
            // Reset date fields to current date
            document.getElementById('date_nep').value = '<?= $current_nepali_date ?>';
            document.getElementById('date_eng').value = '<?= $current_english_date ?>';
            
            // Reset fiscal year to current
            document.getElementById('fiscal_year_id').value = '<?= $current_fiscal_year['id'] ?? '' ?>';
            
            // Clear job ticket search
            document.getElementById('jt_search').value = '';
            document.getElementById('jt_id').value = '';
            
            // Clear name field
            document.getElementById('name').value = '';
            
            showAlert('Form has been reset', 'info', 2000);
        }
    });
    
    // Utility functions
    function formatNumber(num) {
        return num ? parseInt(num).toLocaleString() : '0';
    }
    
    function showAlert(message, type = 'info', timeout = 5000) {
        // Remove any existing alerts
        document.querySelectorAll('.alert').forEach(alert => {
            if (alert.querySelector('.close')) {
                alert.remove();
            }
        });
        
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type}`;
        alertDiv.innerHTML = `
            <i class="fas fa-${getAlertIcon(type)}"></i>
            ${message}
            <button type="button" class="close" onclick="this.parentElement.remove()">
                <span>&times;</span>
            </button>
        `;
        
        // Insert after the title
        const title = document.querySelector('h2');
        title.insertAdjacentElement('afterend', alertDiv);
        
        // Auto-remove after timeout
        if (timeout > 0) {
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, timeout);
        }
    }
    
    function getAlertIcon(type) {
        switch(type) {
            case 'danger': return 'exclamation-triangle';
            case 'warning': return 'exclamation-triangle';
            case 'success': return 'check-circle';
            case 'info': return 'info-circle';
            default: return 'info-circle';
        }
    }
    
    // Keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // Ctrl+S to save
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if (!document.getElementById('submit_btn').disabled) {
                document.getElementById('formaPrintingForm').submit();
            }
        }
        
        // Escape to close dropdowns
        if (e.key === 'Escape') {
            document.querySelectorAll('.dropdown-options').forEach(dropdown => {
                dropdown.style.display = 'none';
            });
        }
    });
    
    // Auto-save draft functionality (optional)
    let autoSaveTimer;
    const formElements = document.querySelectorAll('input, select, textarea');
    
    formElements.forEach(element => {
        element.addEventListener('input', function() {
            clearTimeout(autoSaveTimer);
            autoSaveTimer = setTimeout(() => {
                saveDraft();
            }, 30000); // Save draft after 30 seconds of inactivity
        });
    });
    
    function saveDraft() {
        const formData = new FormData(document.getElementById('formaPrintingForm'));
        const draftData = {};
        
        for (let [key, value] of formData.entries()) {
            if (value.trim() !== '') {
                draftData[key] = value;
            }
        }
        
        // Only save if there's meaningful data
        if (Object.keys(draftData).length > 3) { // More than just dates and fiscal year
            try {
                localStorage.setItem('forma_printing_draft', JSON.stringify(draftData));
                
                // Show brief notification
                showBriefNotification('Draft saved', 'success');
            } catch (e) {
                console.error('Failed to save draft:', e);
            }
        }
    }
    
    function showBriefNotification(message, type = 'info') {
        const notification = document.createElement('div');
        notification.style.cssText = `
            position: fixed;
            top: 20px;
            right: 20px;
            background: ${type === 'success' ? '#28a745' : '#007bff'};
            color: white;
            padding: 8px 16px;
            border-radius: 4px;
            z-index: 9999;
            opacity: 0;
            transition: opacity 0.3s ease;
            font-size: 13px;
            box-shadow: 0 2px 8px rgba(0,0,0,0.15);
        `;
        notification.innerHTML = `<i class="fas fa-${type === 'success' ? 'check' : 'info-circle'}"></i> ${message}`;
        document.body.appendChild(notification);
        
        setTimeout(() => notification.style.opacity = '1', 10);
        setTimeout(() => {
            notification.style.opacity = '0';
            setTimeout(() => notification.remove(), 300);
        }, 1500);
    }
    
    // Load draft on page load
    function loadDraft() {
        try {
            const draftData = localStorage.getItem('forma_printing_draft');
            if (draftData) {
                const data = JSON.parse(draftData);
                
                if (confirm('Found a saved draft from a previous session. Would you like to restore it?')) {
                    Object.keys(data).forEach(key => {
                        const element = document.querySelector(`[name="${key}"]`);
                        if (element && element.type !== 'hidden') {
                            element.value = data[key];
                        }
                    });
                    
                    // Trigger change events to update dependent fields
                    if (data.jt_id) {
                        // Find and trigger the job ticket selection
                        const jtOptions = document.querySelectorAll('.dropdown-option');
                        jtOptions.forEach(option => {
                            if (option.dataset.value === data.jt_id) {
                                option.click();
                            }
                        });
                    }
                    
                    showAlert('Draft restored successfully', 'success', 3000);
                } else {
                    // Clear the draft if user doesn't want to restore
                    localStorage.removeItem('forma_printing_draft');
                }
            }
        } catch (e) {
            console.error('Failed to load draft:', e);
            localStorage.removeItem('forma_printing_draft');
        }
    }
    
    // Clear draft on successful submission
    window.addEventListener('beforeunload', function(e) {
        // Only show warning if form has unsaved changes and we're not submitting
        const hasChanges = Array.from(formElements).some(element => 
            element.value && element.value !== element.defaultValue && element.type !== 'hidden'
        );
        
        const isSubmitting = document.getElementById('submit_btn').innerHTML.includes('Saving');
        
        if (hasChanges && !isSubmitting) {
            e.preventDefault();
            e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
        } else if (isSubmitting) {
            // Clear draft on successful submission
            localStorage.removeItem('forma_printing_draft');
        }
    });
    
    // Clear draft when form is successfully submitted
    document.getElementById('formaPrintingForm').addEventListener('submit', function() {
        setTimeout(() => {
            localStorage.removeItem('forma_printing_draft');
        }, 1000);
    });
    
    // Enhanced validation on form fields
    document.getElementById('date_nep').addEventListener('blur', function() {
        const nepaliDatePattern = /^\d{4}\.\d{2}\.\d{2}$/;
        if (this.value && !nepaliDatePattern.test(this.value)) {
            this.classList.add('is-invalid');
            showAlert('Please enter Nepali date in YYYY.MM.DD format', 'danger');
        } else {
            this.classList.remove('is-invalid');
        }
    });
    
    document.getElementById('date_eng').addEventListener('blur', function() {
        const englishDatePattern = /^\d{4}\.\d{2}\.\d{2}$/;
        if (this.value && !englishDatePattern.test(this.value)) {
            this.classList.add('is-invalid');
            showAlert('Please enter English date in YYYY.MM.DD format', 'danger');
        } else {
            this.classList.remove('is-invalid');
        }
    });
    
    // Initialize form
    console.log('Forma Printing Create Form initialized successfully');
    
    // Load draft after page is fully loaded
    setTimeout(() => {
        loadDraft();
    }, 500);
    
    // Set initial focus
    document.getElementById('jt_search').focus();
});

// Global error handler for unhandled promise rejections
window.addEventListener('unhandledrejection', function(event) {
    console.error('Unhandled promise rejection:', event.reason);
    showAlert('An unexpected error occurred. Please try again.', 'danger');
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
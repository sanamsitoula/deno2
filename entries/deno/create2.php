<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/lib/AuditLogger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$auditLogger = new AuditLogger($conn, 'DenoCreate', 'Deno');
$error_message = null;
$success_message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        $auditLogger->prepareForAudit();
        
        $entry_type = $_POST['entry_type'] ?? 'direct';
        $action = $_POST['action'] ?? 'create';
        
        // Validate required fields
        $required_fields = ['ref_no', 'deno_date_nep', 'deno_date_eng', 'per_poka_qty', 'poka_qty', 'created_by'];
        
        if ($entry_type === 'direct') {
            $required_fields[] = 'book_code';
        } elseif ($entry_type === 'from_jt') {
            $required_fields[] = 'jt_id';
        } elseif ($entry_type === 'from_bp') {
            $required_fields[] = 'bp_id';
        }
        
        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '{$field}' is required");
            }
        }
        
        // Get book_code and IDs based on entry type
        $book_code = null;
        $jt_id = null;
        $bp_id = null;
        
        if ($entry_type === 'direct') {
            $book_code = $_POST['book_code'];
            // Optional: user can select JT and BP for reference
            $jt_id = !empty($_POST['jt_id_optional']) ? (int)$_POST['jt_id_optional'] : null;
            $bp_id = !empty($_POST['bp_id_optional']) ? (int)$_POST['bp_id_optional'] : null;
            
        } elseif ($entry_type === 'from_jt') {
            $jt_id = (int)$_POST['jt_id'];
            $stmt = $conn->prepare("SELECT b.book_code FROM job_ticket jt LEFT JOIN books b ON jt.book_id = b.book_id WHERE jt.id = :jt_id");
            $stmt->execute([':jt_id' => $jt_id]);
            $result = $stmt->fetch();
            if (!$result) throw new Exception("Job ticket not found");
            $book_code = $result['book_code'];
            // Optional: user can select BP
            $bp_id = !empty($_POST['bp_id_optional']) ? (int)$_POST['bp_id_optional'] : null;
            
        } elseif ($entry_type === 'from_bp') {
            $bp_id = (int)$_POST['bp_id'];
            $stmt = $conn->prepare("SELECT book_code, jt_id FROM book_packing WHERE id = :bp_id");
            $stmt->execute([':bp_id' => $bp_id]);
            $result = $stmt->fetch();
            if (!$result) throw new Exception("Book packing record not found");
            $book_code = $result['book_code'];
            $jt_id = $result['jt_id'];
        }
        
        if ($action === 'create') {
            // Check for duplicate ref_no with different dates
            $check_stmt = $conn->prepare("
                SELECT deno_date_nep FROM deno 
                WHERE ref_no = :ref_no AND deno_date_nep != :deno_date_nep AND deleted_at IS NULL
                LIMIT 1
            ");
            $check_stmt->execute([
                ':ref_no' => $_POST['ref_no'],
                ':deno_date_nep' => $_POST['deno_date_nep']
            ]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("Reference number " . htmlspecialchars($_POST['ref_no']) . " already exists with a different date.");
            }
            
            $insert_sql = "
                INSERT INTO deno (
                    book_code, ref_no, deno_date_nep, deno_date_eng,
                    per_poka_qty, poka_qty, quantity_openpcs, notes,
                    created_by, received_by, verify_by, update_remarks, fiscal_year,
                    entry_type, jt_id, bp_id
                ) VALUES (
                    :book_code, :ref_no, :deno_date_nep, :deno_date_eng,
                    :per_poka_qty, :poka_qty, :quantity_openpcs, :notes,
                    :created_by, :received_by, :verify_by, :update_remarks, :fiscal_year,
                    :entry_type, :jt_id, :bp_id
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':book_code' => $book_code,
                ':ref_no' => $_POST['ref_no'],
                ':deno_date_nep' => $_POST['deno_date_nep'],
                ':deno_date_eng' => $_POST['deno_date_eng'],
                ':per_poka_qty' => $_POST['per_poka_qty'],
                ':poka_qty' => $_POST['poka_qty'],
                ':quantity_openpcs' => $_POST['quantity_openpcs'] ?? 0,
                ':notes' => $_POST['notes'] ?? null,
                ':created_by' => $_POST['created_by'],
                ':received_by' => $_POST['received_by'] ?: null,
                ':verify_by' => $_POST['verify_by'] ?: null,
                ':update_remarks' => $_POST['update_remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'] ?? '2082',
                ':entry_type' => $entry_type,
                ':jt_id' => $jt_id,
                ':bp_id' => $bp_id
            ]);
            
            $success_message = "Deno record created successfully!";
            
        } elseif ($action === 'update') {
            $check_stmt = $conn->prepare("
                SELECT deno_date_nep FROM deno 
                WHERE ref_no = :ref_no AND deno_date_nep != :deno_date_nep AND id != :id AND deleted_at IS NULL
                LIMIT 1
            ");
            $check_stmt->execute([
                ':ref_no' => $_POST['ref_no'],
                ':deno_date_nep' => $_POST['deno_date_nep'],
                ':id' => $_POST['id']
            ]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("Reference number already exists with a different date.");
            }
            
            $update_sql = "
                UPDATE deno SET 
                    book_code = :book_code, ref_no = :ref_no, 
                    deno_date_nep = :deno_date_nep, deno_date_eng = :deno_date_eng,
                    per_poka_qty = :per_poka_qty, poka_qty = :poka_qty, 
                    quantity_openpcs = :quantity_openpcs, notes = :notes,
                    received_by = :received_by, verify_by = :verify_by, 
                    update_remarks = :update_remarks, fiscal_year = :fiscal_year,
                    updated_by = :updated_by, entry_type = :entry_type,
                    jt_id = :jt_id, bp_id = :bp_id
                WHERE id = :id
            ";
            
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([
                ':id' => $_POST['id'],
                ':book_code' => $book_code,
                ':ref_no' => $_POST['ref_no'],
                ':deno_date_nep' => $_POST['deno_date_nep'],
                ':deno_date_eng' => $_POST['deno_date_eng'],
                ':per_poka_qty' => $_POST['per_poka_qty'],
                ':poka_qty' => $_POST['poka_qty'],
                ':quantity_openpcs' => $_POST['quantity_openpcs'] ?? 0,
                ':notes' => $_POST['notes'] ?? null,
                ':received_by' => $_POST['received_by'] ?: null,
                ':verify_by' => $_POST['verify_by'] ?: null,
                ':update_remarks' => $_POST['update_remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'] ?? '2082',
                ':updated_by' => $_SESSION['username'] ?? 'system',
                ':entry_type' => $entry_type,
                ':jt_id' => $jt_id,
                ':bp_id' => $bp_id
            ]);
            
            $success_message = "Deno record updated successfully!";
            
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE deno SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id");
            $stmt->execute([':id' => $_POST['id']]);
            $success_message = "Deno record deleted successfully!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch dropdown data
$books = $conn->query("SELECT book_code, book_name FROM books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

$job_tickets = $conn->query("
    SELECT jt.id, jt.job_ticket_code, jt.lot, jt.print_qty, b.book_name, b.book_code
    FROM job_ticket jt
    LEFT JOIN books b ON jt.book_id = b.book_id
    WHERE jt.status NOT IN ('cancelled')
    ORDER BY jt.created_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$book_packings = $conn->query("
    SELECT bp.id, bp.name, bp.p_qty, bp.book_code, b.book_name, jt.job_ticket_code, jt.id as jt_id
    FROM book_packing bp
    LEFT JOIN books b ON bp.book_code = b.book_code
    LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
    WHERE bp.status = true
    ORDER BY bp.created_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// User lists by role
$created_by_users = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$received_by_users = $conn->query("SELECT id, username FROM users WHERE role IN ('marketing', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$verified_by_users = $conn->query("SELECT id, username FROM users WHERE role IN ('marketing', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Fetch latest Deno records
$deno_records = $conn->query("
    SELECT * FROM v_deno_full_details
    ORDER BY created_at DESC 
    LIMIT 20
")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM deno WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    margin-bottom: 30px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
    margin-bottom: 10px;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.entry-type-selector {
    display: flex;
    gap: 15px;
    margin-bottom: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
}

.entry-type-option {
    flex: 1;
    position: relative;
}

.entry-type-option input[type="radio"] {
    position: absolute;
    opacity: 0;
}

.entry-type-option label {
    display: block;
    padding: 20px;
    background: white;
    border: 2px solid #dee2e6;
    border-radius: 8px;
    cursor: pointer;
    text-align: center;
    transition: all 0.3s ease;
}

.entry-type-option input[type="radio"]:checked + label {
    border-color: #007bff;
    background: #e7f3ff;
    font-weight: 600;
}

.entry-type-option label:hover {
    border-color: #007bff;
}

.entry-type-icon {
    font-size: 32px;
    margin-bottom: 10px;
}

.entry-type-title {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 5px;
}

.entry-type-desc {
    font-size: 12px;
    color: #6c757d;
}

.form-section {
    margin-bottom: 30px;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
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

.form-group label .required {
    color: #dc3545;
}

.form-control {
    padding: 10px 14px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.form-control:disabled {
    background: #e9ecef;
    cursor: not-allowed;
}

.info-box {
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    border-radius: 8px;
    padding: 15px;
    margin-top: 10px;
}

.info-box.hidden {
    display: none;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
}

.info-label {
    font-weight: 600;
    color: #0066cc;
}

.info-value {
    color: #333;
}

.summary-box {
    background: #fff3cd;
    border: 2px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #ffe69c;
}

.summary-row:last-child {
    border-bottom: none;
    font-weight: 700;
    font-size: 15px;
    color: #856404;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.btn-primary { background: #007bff; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
}

.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    font-size: 14px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.data-table-container {
    background: white;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #f8f9fa;
}

.data-table th {
    padding: 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    border-bottom: 2px solid #dee2e6;
}

.data-table td {
    padding: 12px;
    font-size: 14px;
    border-bottom: 1px solid #f0f0f0;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.badge-direct { background: #cce5ff; color: #004085; }
.badge-from-jt { background: #d4edda; color: #155724; }
.badge-from-bp { background: #fff3cd; color: #856404; }

.hidden {
    display: none;
}

.reference-list {
    max-height: 200px;
    overflow-y: auto;
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 10px;
    margin-top: 10px;
}

.reference-item {
    padding: 8px;
    margin-bottom: 5px;
    background: white;
    border-radius: 4px;
    font-size: 13px;
    border-left: 3px solid #007bff;
}

.validation-warning {
    background: #fff3cd;
    border: 2px solid #ffc107;
    color: #856404;
    padding: 15px;
    border-radius: 8px;
    margin-top: 15px;
    font-size: 14px;
    font-weight: 600;
    display: none;
}

.validation-warning.show {
    display: block;
    animation: shake 0.5s;
}

@keyframes shake {
    0%, 100% { transform: translateX(0); }
    10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
    20%, 40%, 60%, 80% { transform: translateX(5px); }
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📝 <?= $edit_record ? 'Edit' : 'Create' ?> Deno Entry</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="denoForm">
            <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
            <?php if ($edit_record): ?>
                <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
            <?php endif; ?>

            <!-- Entry Type Selector -->
            <div class="entry-type-selector">
                <div class="entry-type-option">
                    <input type="radio" name="entry_type" id="type_direct" value="direct" 
                           <?= (!$edit_record || $edit_record['entry_type'] === 'direct') ? 'checked' : '' ?>>
                    <label for="type_direct">
                        <div class="entry-type-icon">📚</div>
                        <div class="entry-type-title">Direct Entry</div>
                        <div class="entry-type-desc">Manual book entry</div>
                    </label>
                </div>

                <div class="entry-type-option">
                    <input type="radio" name="entry_type" id="type_from_jt" value="from_jt"
                           <?= ($edit_record && $edit_record['entry_type'] === 'from_jt') ? 'checked' : '' ?>>
                    <label for="type_from_jt">
                        <div class="entry-type-icon">🎫</div>
                        <div class="entry-type-title">From Job Ticket</div>
                        <div class="entry-type-desc">Link to existing JT</div>
                    </label>
                </div>

                <div class="entry-type-option">
                    <input type="radio" name="entry_type" id="type_from_bp" value="from_bp"
                           <?= ($edit_record && $edit_record['entry_type'] === 'from_bp') ? 'checked' : '' ?>>
                    <label for="type_from_bp">
                        <div class="entry-type-icon">📦</div>
                        <div class="entry-type-title">From Book Packing</div>
                        <div class="entry-type-desc">Link to packing record</div>
                    </label>
                </div>
            </div>

            <!-- Mode 1: Direct Entry -->
            <div id="direct_mode" class="mode-panel">
                <div class="form-section">
                    <div class="section-title">📚 Book Information</div>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="book_code_direct">Book <span class="required">*</span></label>
                            <select name="book_code" id="book_code_direct" class="form-control">
                                <option value="">Select Book</option>
                                <?php foreach ($books as $book): ?>
                                    <option value="<?= $book['book_code'] ?>"
                                            <?= ($edit_record && $edit_record['book_code'] === $book['book_code']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($book['book_name']) ?> (<?= $book['book_code'] ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="info-box hidden" id="direct_reference_box">
                                <strong style="display: block; margin-bottom: 10px;">📋 Associated Records (Optional Reference)</strong>
                                
                                <div id="direct_jt_section" style="margin-bottom: 15px;">
                                    <label style="font-size: 13px; font-weight: 600; margin-bottom: 5px; display: block;">Job Tickets (Optional)</label>
                                    <select name="jt_id_optional" id="jt_id_direct" class="form-control">
                                        <option value="">None</option>
                                    </select>
                                    <div class="reference-list hidden" id="direct_jt_list"></div>
                                </div>
                                
                                <div id="direct_bp_section">
                                    <label style="font-size: 13px; font-weight: 600; margin-bottom: 5px; display: block;">Book Packings (Optional)</label>
                                    <select name="bp_id_optional" id="bp_id_direct" class="form-control">
                                        <option value="">None</option>
                                    </select>
                                    <div class="reference-list hidden" id="direct_bp_list"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mode 2: From Job Ticket -->
            <div id="jt_mode" class="mode-panel hidden">
                <div class="form-section">
                    <div class="section-title">🎫 Job Ticket Selection</div>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="jt_id">Job Ticket <span class="required">*</span></label>
                            <select name="jt_id" id="jt_id" class="form-control">
                                <option value="">Select Job Ticket</option>
                                <?php foreach ($job_tickets as $jt): ?>
                                    <option value="<?= $jt['id'] ?>"
                                            data-book-code="<?= htmlspecialchars($jt['book_code']) ?>"
                                            data-book-name="<?= htmlspecialchars($jt['book_name']) ?>"
                                            data-lot="<?= htmlspecialchars($jt['lot']) ?>"
                                            data-print-qty="<?= $jt['print_qty'] ?>"
                                            <?= ($edit_record && $edit_record['jt_id'] == $jt['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($jt['job_ticket_code']) ?> - <?= htmlspecialchars($jt['book_name']) ?> (Qty: <?= number_format($jt['print_qty']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="info-box hidden" id="jt_info_box">
                                <div class="info-row">
                                    <span class="info-label">Book Code:</span>
                                    <span class="info-value" id="jt_book_code">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Book Name:</span>
                                    <span class="info-value" id="jt_book_name">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Lot:</span>
                                    <span class="info-value" id="jt_lot">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Print Quantity:</span>
                                    <span class="info-value" id="jt_print_qty">-</span>
                                </div>
                                
                                <div style="margin-top: 15px; padding-top: 15px; border-top: 1px solid #b3d9ff;">
                                    <strong style="display: block; margin-bottom: 10px;">📦 Book Packings for this JT</strong>
                                    <div class="reference-list" id="jt_bp_list">
                                        <div style="text-align: center; color: #6c757d;">Loading...</div>
                                    </div>
                                    
                                    <label style="font-size: 13px; font-weight: 600; margin: 10px 0 5px 0; display: block;">Select Book Packing (Optional)</label>
                                    <select name="bp_id_optional" id="bp_id_from_jt" class="form-control">
                                        <option value="">None</option>
                                    </select>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="book_code_from_jt">Book Code (Auto-filled)</label>
                            <input type="text" id="book_code_from_jt" class="form-control" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Mode 3: From Book Packing -->
            <div id="bp_mode" class="mode-panel hidden">
                <div class="form-section">
                    <div class="section-title">📦 Book Packing Selection</div>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="bp_id">Book Packing Record <span class="required">*</span></label>
                            <select name="bp_id" id="bp_id" class="form-control">
                                <option value="">Select Book Packing</option>
                                <?php foreach ($book_packings as $bp): ?>
                                    <option value="<?= $bp['id'] ?>"
                                            data-book-code="<?= htmlspecialchars($bp['book_code']) ?>"
                                            data-book-name="<?= htmlspecialchars($bp['book_name']) ?>"
                                            data-jt-code="<?= htmlspecialchars($bp['job_ticket_code']) ?>"
                                            data-jt-id="<?= $bp['jt_id'] ?>"
                                            data-packed-qty="<?= $bp['p_qty'] ?>"
                                            <?= ($edit_record && $edit_record['bp_id'] == $bp['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($bp['name']) ?> - <?= htmlspecialchars($bp['book_name']) ?> (Qty: <?= number_format($bp['p_qty']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="summary-box hidden" id="bp_summary_box">
                                <strong style="display: block; margin-bottom: 15px; font-size: 16px; color: #856404;">📊 Production Summary</strong>
                                <div class="summary-row">
                                    <span>Job Ticket:</span>
                                    <strong id="bp_jt_code">-</strong>
                                </div>
                                <div class="summary-row">
                                    <span>Total Print Quantity:</span>
                                    <strong id="bp_total_print">0</strong>
                                </div>
                                <div class="summary-row">
                                    <span>Total Packed (All BP):</span>
                                    <strong id="bp_total_packed">0</strong>
                                </div>
                                <div class="summary-row">
                                    <span>Total Deno Entries:</span>
                                    <strong id="bp_total_deno_entries">0</strong>
                                </div>
                                <div class="summary-row">
                                    <span>Total Deno Quantity:</span>
                                    <strong id="bp_total_deno_qty">0</strong>
                                </div>
                                <div class="summary-row" style="background: #fff; padding: 10px; border-radius: 4px; margin-top: 10px;">
                                    <span>Remaining to Process:</span>
                                    <strong id="bp_remaining" style="color: #dc3545;">0</strong>
                                </div>
                            </div>
                        </div>
                        
                        <div class="form-group">
                            <label for="book_code_from_bp">Book Code (Auto-filled)</label>
                            <input type="text" id="book_code_from_bp" class="form-control" readonly>
                        </div>
                        
                        <div class="form-group">
                            <label for="jt_id_from_bp">Job Ticket ID (Auto-filled)</label>
                            <input type="text" id="jt_id_from_bp" class="form-control" readonly>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Common Deno Details Section -->
            <div class="form-section">
                <div class="section-title">📋 Deno Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="ref_no">Reference No <span class="required">*</span></label>
                        <input type="text" name="ref_no" id="ref_no" class="form-control"
                               value="<?= htmlspecialchars($edit_record['ref_no'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="deno_date_nep">Nepali Date <span class="required">*</span></label>
                        <input type="text" name="deno_date_nep" id="deno_date_nep" class="form-control"
                               pattern="\d{4}\.\d{2}\.\d{2}" placeholder="YYYY.MM.DD"
                               value="<?= htmlspecialchars($edit_record['deno_date_nep'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="deno_date_eng">English Date <span class="required">*</span></label>
                        <input type="text" name="deno_date_eng" id="deno_date_eng" class="form-control"
                               pattern="\d{4}\.\d{2}\.\d{2}" placeholder="YYYY.MM.DD"
                               value="<?= htmlspecialchars($edit_record['deno_date_eng'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="fiscal_year">Fiscal Year <span class="required">*</span></label>
                        <select name="fiscal_year" id="fiscal_year" class="form-control" required>
                            <option value="2082" <?= (!$edit_record || $edit_record['fiscal_year'] === '2082') ? 'selected' : '' ?>>2082</option>
                            <option value="2083" <?= ($edit_record && $edit_record['fiscal_year'] === '2083') ? 'selected' : '' ?>>2083</option>
                            <option value="2084" <?= ($edit_record && $edit_record['fiscal_year'] === '2084') ? 'selected' : '' ?>>2084</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="per_poka_qty">Quantity per Poka <span class="required">*</span></label>
                        <input type="number" name="per_poka_qty" id="per_poka_qty" class="form-control"
                               value="<?= htmlspecialchars($edit_record['per_poka_qty'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="poka_qty">Number of Pokas <span class="required">*</span></label>
                        <input type="number" name="poka_qty" id="poka_qty" class="form-control"
                               value="<?= htmlspecialchars($edit_record['poka_qty'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="quantity_openpcs">Open Pieces</label>
                        <input type="number" name="quantity_openpcs" id="quantity_openpcs" class="form-control"
                               value="<?= htmlspecialchars($edit_record['quantity_openpcs'] ?? '0') ?>">
                    </div>

                    <div class="form-group">
                        <label>Total Quantity</label>
                        <input type="text" id="total_qty_display" class="form-control" readonly
                               style="background: #e9ecef; font-weight: 600;" value="0">
                        <div class="validation-warning" id="quantity_warning">
                            ⚠️ <span id="warning_message"></span>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Personnel Section -->
            <div class="form-section">
                <div class="section-title">👥 Personnel Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="created_by">Created By <span class="required">*</span></label>
                        <select name="created_by" id="created_by" class="form-control" required>
                            <option value="">Select</option>
                            <?php foreach ($created_by_users as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= ($edit_record && $edit_record['created_by'] == $user['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="received_by">Received By</label>
                        <select name="received_by" id="received_by" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($received_by_users as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= ($edit_record && $edit_record['received_by'] == $user['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="verify_by">Verified By</label>
                        <select name="verify_by" id="verify_by" class="form-control">
                            <option value="">Select</option>
                            <?php foreach ($verified_by_users as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= ($edit_record && $edit_record['verify_by'] == $user['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="notes">Notes</label>
                        <textarea name="notes" id="notes" class="form-control" rows="2"><?= htmlspecialchars($edit_record['notes'] ?? '') ?></textarea>
                    </div>

                    <div class="form-group">
                        <label for="update_remarks">Update Remarks</label>
                        <textarea name="update_remarks" id="update_remarks" class="form-control" rows="2"><?= htmlspecialchars($edit_record['update_remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <?php if ($edit_record): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <?= $edit_record ? '💾 Update Deno' : '✅ Create Deno' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Recent Records Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Ref No</th>
                    <th>Type</th>
                    <th>Book</th>
                    <th>JT/BP</th>
                    <th>Date (Nep)</th>
                    <th>Total Qty</th>
                    <th>Created By</th>
                    <th>D2M</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($deno_records)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px;">No records found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deno_records as $record): ?>
                        <tr>
                            <td><?= $record['deno_id'] ?></td>
                            <td><strong><?= htmlspecialchars($record['ref_no']) ?></strong></td>
                            <td>
                                <span class="badge badge-<?= $record['entry_type'] ?>">
                                    <?= ucfirst(str_replace('_', ' ', $record['entry_type'])) ?>
                                </span>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($record['book_code']) ?></div>
                                <small style="color: #6c757d;"><?= htmlspecialchars($record['book_name']) ?></small>
                            </td>
                            <td>
                                <?php if ($record['job_ticket_code']): ?>
                                    <div>JT: <?= htmlspecialchars($record['job_ticket_code']) ?></div>
                                <?php endif; ?>
                                <?php if ($record['bp_name']): ?>
                                    <div>BP: <?= htmlspecialchars($record['bp_name']) ?></div>
                                <?php endif; ?>
                                <?php if (!$record['job_ticket_code'] && !$record['bp_name']): ?>
                                    <span style="color: #6c757d;">-</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($record['deno_date_nep']) ?></td>
                            <td><strong><?= number_format($record['total_qty']) ?></strong></td>
                            <td><?= htmlspecialchars($record['created_by']) ?></td>
                            <td>
                                <?php if ($record['d2m_no']): ?>
                                    <div><?= htmlspecialchars($record['d2m_no']) ?></div>
                                    <small style="color: #6c757d;"><?= htmlspecialchars($record['d2m_status']) ?></small>
                                <?php else: ?>
                                    <span style="color: #999;">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?edit_id=<?= $record['deno_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this record?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $record['deno_id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const entryTypeRadios = document.querySelectorAll('input[name="entry_type"]');
    const directMode = document.getElementById('direct_mode');
    const jtMode = document.getElementById('jt_mode');
    const bpMode = document.getElementById('bp_mode');
    
    const bookCodeDirect = document.getElementById('book_code_direct');
    const jtSelect = document.getElementById('jt_id');
    const bpSelect = document.getElementById('bp_id');
    
    const perPokaQty = document.getElementById('per_poka_qty');
    const pokaQty = document.getElementById('poka_qty');
    const openPcs = document.getElementById('quantity_openpcs');
    const totalDisplay = document.getElementById('total_qty_display');
    
    // Toggle modes
    function updateModeVisibility() {
        const selectedType = document.querySelector('input[name="entry_type"]:checked').value;
        
        directMode.classList.toggle('hidden', selectedType !== 'direct');
        jtMode.classList.toggle('hidden', selectedType !== 'from_jt');
        bpMode.classList.toggle('hidden', selectedType !== 'from_bp');
        
        // Set required fields
        bookCodeDirect.required = (selectedType === 'direct');
        jtSelect.required = (selectedType === 'from_jt');
        bpSelect.required = (selectedType === 'from_bp');
    }
    
    entryTypeRadios.forEach(radio => {
        radio.addEventListener('change', updateModeVisibility);
    });
    
    // DIRECT MODE: Load associated JTs and BPs when book is selected
    bookCodeDirect.addEventListener('change', function() {
        const bookCode = this.value;
        const refBox = document.getElementById('direct_reference_box');
        
        if (!bookCode) {
            refBox.classList.add('hidden');
            return;
        }
        
        refBox.classList.remove('hidden');
        
        // Fetch associated job tickets
        fetch(`get_book_associations.php?book_code=${encodeURIComponent(bookCode)}&type=jt`)
            .then(res => res.json())
            .then(data => {
                const jtSelectDirect = document.getElementById('jt_id_direct');
                const jtList = document.getElementById('direct_jt_list');
                
                jtSelectDirect.innerHTML = '<option value="">None</option>';
                jtList.innerHTML = '';
                
                if (data.length > 0) {
                    data.forEach(jt => {
                        const option = document.createElement('option');
                        option.value = jt.id;
                        option.textContent = `${jt.job_ticket_code} - Qty: ${parseInt(jt.print_qty).toLocaleString()}`;
                        jtSelectDirect.appendChild(option);
                        
                        const item = document.createElement('div');
                        item.className = 'reference-item';
                        item.innerHTML = `<strong>${jt.job_ticket_code}</strong> | Lot: ${jt.lot} | Qty: ${parseInt(jt.print_qty).toLocaleString()}`;
                        jtList.appendChild(item);
                    });
                    jtList.classList.remove('hidden');
                } else {
                    jtList.innerHTML = '<div style="text-align:center;color:#6c757d;">No job tickets found</div>';
                    jtList.classList.remove('hidden');
                }
            });
        
        // Fetch associated book packings
        fetch(`get_book_associations.php?book_code=${encodeURIComponent(bookCode)}&type=bp`)
            .then(res => res.json())
            .then(data => {
                const bpSelectDirect = document.getElementById('bp_id_direct');
                const bpList = document.getElementById('direct_bp_list');
                
                bpSelectDirect.innerHTML = '<option value="">None</option>';
                bpList.innerHTML = '';
                
                if (data.length > 0) {
                    data.forEach(bp => {
                        const option = document.createElement('option');
                        option.value = bp.id;
                        option.textContent = `${bp.name} - Qty: ${parseInt(bp.p_qty).toLocaleString()}`;
                        bpSelectDirect.appendChild(option);
                        
                        const item = document.createElement('div');
                        item.className = 'reference-item';
                        item.innerHTML = `<strong>${bp.name}</strong> | JT: ${bp.job_ticket_code} | Packed: ${parseInt(bp.p_qty).toLocaleString()}`;
                        bpList.appendChild(item);
                    });
                    bpList.classList.remove('hidden');
                } else {
                    bpList.innerHTML = '<div style="text-align:center;color:#6c757d;">No book packings found</div>';
                    bpList.classList.remove('hidden');
                }
            });
    });
    
    // FROM JT MODE: Load JT details and associated BPs
    jtSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        const infoBox = document.getElementById('jt_info_box');
        const bookCodeFromJT = document.getElementById('book_code_from_jt');
        
        if (!this.value) {
            infoBox.classList.add('hidden');
            bookCodeFromJT.value = '';
            return;
        }
        
        // Fill JT info
        document.getElementById('jt_book_code').textContent = option.dataset.bookCode;
        document.getElementById('jt_book_name').textContent = option.dataset.bookName;
        document.getElementById('jt_lot').textContent = option.dataset.lot;
        document.getElementById('jt_print_qty').textContent = parseInt(option.dataset.printQty).toLocaleString();
        bookCodeFromJT.value = option.dataset.bookCode;
        infoBox.classList.remove('hidden');
        
        // Fetch book packings for this JT
        fetch(`get_jt_packings.php?jt_id=${this.value}`)
            .then(res => res.json())
            .then(data => {
                const bpList = document.getElementById('jt_bp_list');
                const bpSelect = document.getElementById('bp_id_from_jt');
                
                bpList.innerHTML = '';
                bpSelect.innerHTML = '<option value="">None</option>';
                
                if (data.length > 0) {
                    data.forEach(bp => {
                        const item = document.createElement('div');
                        item.className = 'reference-item';
                        item.innerHTML = `<strong>${bp.name}</strong> | Packed: ${parseInt(bp.p_qty).toLocaleString()} | Date: ${bp.date_nep}`;
                        bpList.appendChild(item);
                        
                        const option = document.createElement('option');
                        option.value = bp.id;
                        option.textContent = `${bp.name} - ${parseInt(bp.p_qty).toLocaleString()}`;
                        bpSelect.appendChild(option);
                    });
                } else {
                    bpList.innerHTML = '<div style="text-align:center;color:#6c757d;">No book packings found for this JT</div>';
                }
            });
    });
    
    // FROM BP MODE: Load complete summary
    bpSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        const summaryBox = document.getElementById('bp_summary_box');
        const bookCodeFromBP = document.getElementById('book_code_from_bp');
        const jtIdFromBP = document.getElementById('jt_id_from_bp');
        
        if (!this.value) {
            summaryBox.classList.add('hidden');
            bookCodeFromBP.value = '';
            jtIdFromBP.value = '';
            return;
        }
        
        bookCodeFromBP.value = option.dataset.bookCode;
        jtIdFromBP.value = option.dataset.jtCode;
        
        // Fetch complete summary
        fetch(`get_bp_summary.php?bp_id=${this.value}&jt_id=${option.dataset.jtId}`)
            .then(res => res.json())
            .then(data => {
                document.getElementById('bp_jt_code').textContent = data.jt_code;
                document.getElementById('bp_total_print').textContent = parseInt(data.total_print_qty).toLocaleString();
                document.getElementById('bp_total_packed').textContent = parseInt(data.total_packed).toLocaleString();
                document.getElementById('bp_total_deno_entries').textContent = data.total_deno_entries;
                document.getElementById('bp_total_deno_qty').textContent = parseInt(data.total_deno_qty).toLocaleString();
                document.getElementById('bp_remaining').textContent = parseInt(data.remaining).toLocaleString();
                
                summaryBox.classList.remove('hidden');
            });
    });
    
    // Calculate total quantity
    function calculateTotal() {
        const per = parseInt(perPokaQty.value) || 0;
        const qty = parseInt(pokaQty.value) || 0;
        const open = parseInt(openPcs.value) || 0;
        const total = (per * qty) + open;
        
        totalDisplay.value = total.toLocaleString();
        
        // Validate against limits
        validateQuantityLimits(total);
    }
    
    // Validate quantity limits based on mode
    function validateQuantityLimits(newQty) {
        const warningDiv = document.getElementById('quantity_warning');
        const warningMsg = document.getElementById('warning_message');
        const selectedMode = document.querySelector('input[name="entry_type"]:checked').value;
        
        warningDiv.classList.remove('show');
        
        if (selectedMode === 'from_jt' && jtSelect.value) {
            // Validate against JT
            const jtId = jtSelect.value;
            fetch(`validate_deno_qty.php?jt_id=${jtId}&new_qty=${newQty}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.valid) {
                        warningMsg.textContent = `EXCEEDS JT limit by ${data.excess.toLocaleString()}! JT Qty: ${data.jt_print_qty.toLocaleString()}, Existing Deno: ${data.existing_deno.toLocaleString()}, New: ${newQty.toLocaleString()}, Total: ${data.total.toLocaleString()}`;
                        warningDiv.classList.add('show');
                    }
                });
                
        } else if (selectedMode === 'from_bp' && bpSelect.value) {
            // Validate against BP
            const bpId = bpSelect.value;
            const option = bpSelect.options[bpSelect.selectedIndex];
            const jtId = option.dataset.jtId;
            
            fetch(`validate_deno_qty.php?bp_id=${bpId}&jt_id=${jtId}&new_qty=${newQty}`)
                .then(res => res.json())
                .then(data => {
                    if (!data.valid) {
                        if (data.exceeds_bp) {
                            warningMsg.textContent = `EXCEEDS BP limit by ${data.bp_excess.toLocaleString()}! BP Qty: ${data.bp_qty.toLocaleString()}, Existing Deno: ${data.existing_bp_deno.toLocaleString()}, New: ${newQty.toLocaleString()}, Total: ${data.total_bp.toLocaleString()}`;
                            warningDiv.classList.add('show');
                        } else if (data.exceeds_jt) {
                            warningMsg.textContent = `EXCEEDS JT limit by ${data.jt_excess.toLocaleString()}! JT Qty: ${data.jt_print_qty.toLocaleString()}, Existing Deno: ${data.existing_jt_deno.toLocaleString()}, New: ${newQty.toLocaleString()}, Total: ${data.total_jt.toLocaleString()}`;
                            warningDiv.classList.add('show');
                        }
                    }
                });
        }
    }
    
    perPokaQty.addEventListener('input', calculateTotal);
    pokaQty.addEventListener('input', calculateTotal);
    openPcs.addEventListener('input', calculateTotal);
    
    // Initialize
    updateModeVisibility();
    calculateTotal();
    
    // Trigger change events if editing
    if (bookCodeDirect.value) bookCodeDirect.dispatchEvent(new Event('change'));
    if (jtSelect.value) jtSelect.dispatchEvent(new Event('change'));
    if (bpSelect.value) bpSelect.dispatchEvent(new Event('change'));
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('incharge')&& !has_role('operator')  && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}
// Get record ID
$id = $_GET['id'] ?? null;
if (!$id) {
    die("No record ID provided");
  
}



$record_id = $_GET['id'];

try {
    // Fetch the main Forma Printing record
    $stmt = $conn->prepare("
        SELECT 
            fp.*, 
            fy.fiscal_code,
            jt.job_ticket_code, 
            f.name as forma_name, 
            jtd.order_no, 
            jtd.page,
            b.book_code,
            b.book_name,
            b.class_level,
            sup.username AS supervisor_name,
            op.username AS operator_name,
            inc.username AS incharge_name,
            s.name AS shift_name,
            m.machine_name,
            creator.username AS created_by_name
        FROM forma_printing fp
        LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
         LEFT JOIN forma f ON jtd.forma_id = f.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN users sup ON fp.supervisor_id = sup.id
        LEFT JOIN users op ON fp.operator_id = op.id
        LEFT JOIN users inc ON fp.incharge_id = inc.id
        LEFT JOIN shifts s ON fp.shift_id = s.id
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN users creator ON fp.created_by = creator.id
        WHERE fp.id = :id
    ");
    $stmt->execute([':id' => $record_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        throw new Exception("Forma Printing record not found");
    }
    
    // Fetch previous printing records for the same forma (jtd_id)
    $prevStmt = $conn->prepare("
        SELECT 
            fp.id, fp.date_nep, fp.fp_printqty, 
            m.machine_name, u.username AS operator_name
        FROM forma_printing fp
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN users u ON fp.operator_id = u.id
        WHERE fp.jtd_id = :jtd_id AND fp.id != :id AND fp.status = true
        ORDER BY fp.created_date DESC
    ");
    $prevStmt->execute([
        ':jtd_id' => $record['jtd_id'],
        ':id' => $record_id
    ]);
    $previousRecords = $prevStmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate total printed for this forma
    $totalPrintedStmt = $conn->prepare("
        SELECT SUM(fp_printqty) AS total_printed 
        FROM forma_printing 
        WHERE jtd_id = :jtd_id AND status = true
    ");
    $totalPrintedStmt->execute([':jtd_id' => $record['jtd_id']]);
    $totalPrinted = $totalPrintedStmt->fetch(PDO::FETCH_ASSOC);
    
} 

 catch (Exception $e) {
    // Log the error (optional but recommended)
    error_log("Error retrieving data: " . $e->getMessage());

    // Display error message and stop further execution
    die("<h2 style='color: red;'>An error occurred:</h2><p><strong>" . 
        htmlspecialchars($e->getMessage()) . "</strong></p><p>Execution stopped.</p>");
 header('Location: index.php');
    exit();
    }

?>

<style>
    /* Enhanced styles for the view page */
    .container {
        max-width: 100%;
        padding: 20px;
    }

    h2 {
        margin: 20px 15px;
        color: #333;
        font-size: 28px;
        font-weight: 600;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .record-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin: 0 15px 20px;
        padding-bottom: 15px;
        border-bottom: 1px solid #e9ecef;
    }

    .record-id {
        font-size: 18px;
        font-weight: 600;
        color: #495057;
    }

    .record-actions {
        display: flex;
        gap: 10px;
    }

    .section-container {
        background: #f8f9fa;
        padding: 25px;
        border-radius: 10px;
        margin: 0 15px 25px;
        border: 1px solid #e9ecef;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .section-title {
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

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 25px;
    }

    .info-group {
        margin-bottom: 15px;
    }

    .info-label {
        font-size: 13px;
        color: #6c757d;
        text-transform: uppercase;
        font-weight: 500;
        margin-bottom: 4px;
        display: flex;
        align-items: center;
        gap: 5px;
    }

    .info-value {
        font-size: 16px;
        color: #333;
        font-weight: 500;
        padding: 8px 0;
        border-bottom: 1px solid #e9ecef;
    }

    .status-indicator {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        font-size: 12px;
        padding: 4px 10px;
        border-radius: 12px;
        font-weight: 500;
    }

    .status-active {
        background: #d4edda;
        color: #155724;
        border: 1px solid #c3e6cb;
    }

    .status-inactive {
        background: #f8d7da;
        color: #721c24;
        border: 1px solid #f5c6cb;
    }

    .progress-container {
        background: #fff;
        border-radius: 6px;
        padding: 15px;
        margin-top: 20px;
        border: 1px solid #e9ecef;
    }

    .progress-header {
        display: flex;
        justify-content: space-between;
        margin-bottom: 10px;
        font-size: 14px;
    }

    .progress-bar {
        height: 20px;
        background: #e9ecef;
        border-radius: 10px;
        overflow: hidden;
    }

    .progress-fill {
        height: 100%;
        background: #007bff;
        border-radius: 10px;
        transition: width 0.5s ease;
    }

    .previous-records {
        background: #fff3cd;
        border: 1px solid #ffeaa7;
        border-radius: 6px;
        padding: 15px;
        margin-top: 25px;
    }

    .previous-records-title {
        font-weight: 600;
        color: #856404;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 8px;
    }

    .record-list {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 15px;
    }

    .record-item {
        background: white;
        border-radius: 6px;
        padding: 12px;
        border-left: 4px solid #ffc107;
        font-size: 13px;
        box-shadow: 0 2px 4px rgba(0,0,0,0.05);
    }

    .record-item-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 8px;
    }

    .record-item-id {
        font-weight: 600;
        color: #495057;
    }

    .record-item-date {
        color: #6c757d;
        font-size: 12px;
    }

    .btn {
        padding: 10px 16px;
        border: none;
        border-radius: 6px;
        cursor: pointer;
        font-size: 14px;
        font-weight: 500;
        text-decoration: none;
        display: inline-flex;
        align-items: center;
        gap: 8px;
        transition: all 0.3s ease;
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 2px 8px rgba(0,0,0,0.15);
    }

    .btn-primary { background: #007bff; color: white; }
    .btn-secondary { background: #6c757d; color: white; }
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

    .notes-container {
        background: white;
        border-radius: 6px;
        padding: 15px;
        margin-top: 15px;
        border: 1px solid #e9ecef;
    }

    .note-content {
        white-space: pre-wrap;
        line-height: 1.6;
    }

    @media (max-width: 768px) {
        .record-header {
            flex-direction: column;
            align-items: flex-start;
            gap: 15px;
        }
        
        .record-actions {
            width: 100%;
            justify-content: flex-end;
        }
        
        .info-grid {
            grid-template-columns: 1fr;
        }
    }
</style>

<div class="container">
    <h2>
        <i class="fas fa-print"></i>
        Forma Printing Record: <?= htmlspecialchars($record['name']) ?>
    </h2>
    
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
    
    <div class="record-header">
        <div class="record-id">
            Record ID: FP-<?= str_pad($record['id'], 5, '0', STR_PAD_LEFT) ?>
            <span class="status-indicator status-active">
                <i class="fas fa-check-circle"></i> ACTIVE
            </span>
        </div>
        <div class="record-actions">
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <?php if (has_role('editor') || has_role('admin')): ?>
                <a href="forma_printing_edit.php?id=<?= $record['id'] ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit Record
                </a>
            <?php endif; ?>
        </div>
    </div>

    <!-- Basic Information Section -->
    <div class="section-container">
        <div class="section-title">
            <i class="fas fa-calendar-alt"></i> Basic Information
        </div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-calendar-day"></i> Nepali Date
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['date_nep']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-calendar-day"></i> English Date
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['date_eng']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-folder"></i> Record Name
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-calendar-week"></i> Fiscal Year
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['fiscal_code']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-user-plus"></i> Created By
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['created_by_name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-clock"></i> Created On
                </div>
                <div class="info-value">
                    <?= date('Y.m.d H:i', strtotime($record['created_date'])) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Job Ticket & Forma Information -->
    <div class="section-container">
        <div class="section-title">
            <i class="fas fa-clipboard-list"></i> Job Ticket & Forma Details
        </div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-ticket-alt"></i> Job Ticket
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['job_ticket_code']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-book"></i> Book
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['book_code']) ?> - <?= htmlspecialchars($record['book_name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-graduation-cap"></i> Class Level
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['class_level']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-file-alt"></i> Forma Name
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['forma_name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-sort-numeric-down"></i> Order Number
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['order_no']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-file"></i> Page
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['page']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Quantity Information -->
    <div class="section-container">
        <div class="section-title">
            <i class="fas fa-calculator"></i> Quantity Information
        </div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-bullseye"></i> Target Quantity
                </div>
                <div class="info-value">
                    <?= number_format($record['jtd_targetqty']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-print"></i> Print Quantity
                </div>
                <div class="info-value">
                    <?= number_format($record['fp_printqty']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-box"></i> Remaining Quantity
                </div>
                <div class="info-value">
                    <?= number_format($record['fp_remainqty']) ?>
                </div>
            </div>
        </div>
        
        <div class="progress-container">
            <div class="progress-header">
                <div>Forma Completion</div>
                <div><?= round(($totalPrinted['total_printed'] / $record['jtd_targetqty']) * 100, 2) ?>%</div>
            </div>
            <div class="progress-bar">
                <div class="progress-fill" 
                     style="width: <?= min(100, round(($totalPrinted['total_printed'] / $record['jtd_targetqty']) * 100, 2)) ?>%">
                </div>
            </div>
        </div>
    </div>

    <!-- Personnel & Machine Information -->
    <div class="section-container">
        <div class="section-title">
            <i class="fas fa-users"></i> Personnel & Machine Assignment
        </div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-user-tie"></i> Supervisor
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['supervisor_name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-user-cog"></i> Operator
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['operator_name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-user-shield"></i> Incharge
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['incharge_name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-clock"></i> Shift
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['shift_name']) ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-cogs"></i> Machine
                </div>
                <div class="info-value">
                    <?= htmlspecialchars($record['machine_name']) ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Additional Information -->
    <div class="section-container">
        <div class="section-title">
            <i class="fas fa-comment-alt"></i> Additional Information
        </div>
        
        <div class="info-grid">
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-sticky-note"></i> Remarks
                </div>
                <div class="info-value">
                    <?= $record['remarks'] ? htmlspecialchars($record['remarks']) : '<span class="text-muted">No remarks</span>' ?>
                </div>
            </div>
            
            <div class="info-group">
                <div class="info-label">
                    <i class="fas fa-align-left"></i> Description
                </div>
                <div class="info-value">
                    <?= $record['description'] ? htmlspecialchars($record['description']) : '<span class="text-muted">No description</span>' ?>
                </div>
            </div>
        </div>
    </div>

    <!-- Previous Printing Records -->
    <?php if (!empty($previousRecords)): ?>
    <div class="section-container">
        <div class="section-title">
            <i class="fas fa-history"></i> Previous Printing Records for This Forma
        </div>
        
        <div class="previous-records">
            <div class="previous-records-title">
                <i class="fas fa-info-circle"></i> 
                Total Printed: <?= number_format($totalPrinted['total_printed']) ?> units
            </div>
            
            <div class="record-list">
                <?php foreach ($previousRecords as $prevRecord): ?>
                <div class="record-item">
                    <div class="record-item-header">
                        <div class="record-item-id">Record FP-<?= str_pad($prevRecord['id'], 5, '0', STR_PAD_LEFT) ?></div>
                        <div class="record-item-date"><?= htmlspecialchars($prevRecord['date_nep']) ?></div>
                    </div>
                    <div><strong>Printed:</strong> <?= number_format($prevRecord['fp_printqty']) ?> units</div>
                    <div><strong>Machine:</strong> <?= htmlspecialchars($prevRecord['machine_name']) ?></div>
                    <div><strong>Operator:</strong> <?= htmlspecialchars($prevRecord['operator_name']) ?></div>
                </div>
                <?php endforeach; ?>
            </div>
        </div>
    </div>
    <?php endif; ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
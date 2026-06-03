<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('incharge') && !has_role('supervisor') && !has_role('admin') && !has_role('operator')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Get packing record ID
$packing_id = $_GET['id'] ?? null;

if (!$packing_id) {
    $_SESSION['error_message'] = "Packing record ID is required";
    header('Location: book_packing.php');
    exit();
}

// Fetch packing record details
$query = "
    SELECT 
        bp.*,
        jt.job_ticket_code,
        jt.lot,
        jt.print_qty as jt_total_qty,
        b.book_name,
        b.book_code as book_code_full,
        b.class_level,
       -- b.subject,
       -- b.publisher,
        fy.fiscal_code,
        u_supervisor.username as supervisor_name,
     --   u_supervisor.full_name as supervisor_full_name,
        u_incharge.username as incharge_name,
      --  u_incharge.full_name as incharge_full_name,
        u_operator.username as operator_name,
     --   u_operator.full_name as operator_full_name,
        u_created.username as created_by_name,
       -- u_created.full_name as created_by_full_name,
        u_updated.username as updated_by_name
     --   u_updated.full_name as updated_by_full_name
    FROM book_packing bp
    LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN fiscal_years fy ON bp.fiscal_year_id = fy.id
    LEFT JOIN users u_supervisor ON bp.supervisor_id = u_supervisor.id
    LEFT JOIN users u_incharge ON bp.incharge_id = u_incharge.id
    LEFT JOIN users u_operator ON bp.operator_id = u_operator.id
    LEFT JOIN users u_created ON bp.created_by = u_created.id
    LEFT JOIN users u_updated ON bp.updated_by = u_updated.id
    WHERE bp.id = :id 
    AND bp.status = true
";

$stmt = $conn->prepare($query);
$stmt->execute([':id' => $packing_id]);
$packing = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$packing) {
    $_SESSION['error_message'] = "Packing record not found or has been deleted";
    header('Location: book_packing.php');
    exit();
}

// Calculate completion percentage
$completion_percentage = ($packing['jt_total_qty'] > 0) ? 
    round(($packing['p_qty'] / $packing['jt_total_qty']) * 100, 2) : 0;
?>

<style>
    .container {
        max-width: 1200px;
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

    .view-container {
        background: white;
        border-radius: 12px;
        box-shadow: 0 4px 6px rgba(0,0,0,0.05);
        margin: 0 15px 20px;
        overflow: hidden;
        border: 1px solid #e9ecef;
    }

    .view-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 20px 25px;
        font-size: 18px;
        font-weight: 600;
        display: flex;
        justify-content: space-between;
        align-items: center;
    }

    .view-content {
        padding: 30px;
    }

    .info-section {
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

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
        gap: 20px;
    }

    .info-item {
        display: flex;
        flex-direction: column;
        gap: 5px;
    }

    .info-label {
        font-size: 14px;
        font-weight: 600;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 16px;
        color: #333;
        font-weight: 500;
    }

    .info-value.large {
        font-size: 24px;
        font-weight: 700;
    }

    .status-badge {
        padding: 8px 16px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
        display: inline-block;
    }

    .status-active { background: #d4edda; color: #155724; }
    .status-completed { background: #cce7ff; color: #004085; }
    .status-pending { background: #fff3cd; color: #856404; }

    .progress-container {
        background: white;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
    }

    .progress-bar {
        height: 20px;
        background: #e9ecef;
        border-radius: 10px;
        overflow: hidden;
        margin: 10px 0;
    }

    .progress-fill {
        height: 100%;
        background: linear-gradient(90deg, #667eea, #764ba2);
        transition: width 0.5s ease;
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-weight: 600;
        font-size: 12px;
    }

    .quantity-comparison {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
        gap: 20px;
        margin-top: 20px;
    }

    .qty-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        text-align: center;
    }

    .qty-number {
        font-size: 32px;
        font-weight: 800;
        margin-bottom: 5px;
    }

    .qty-label {
        font-size: 14px;
        color: #6c757d;
        font-weight: 500;
    }

    .qty-card.total { border-left: 4px solid #007bff; }
    .qty-card.packed { border-left: 4px solid #28a745; }
    .qty-card.remaining { border-left: 4px solid #ffc107; }

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
    .btn-success { background: linear-gradient(135deg, #28a745 0%, #20c997 100%); color: white; }
    .btn-warning { background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%); color: white; }
    .btn-danger { background: linear-gradient(135deg, #ff6b6b 0%, #ee5a24 100%); color: white; }
    .btn-secondary { background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%); color: white; }

    .action-buttons {
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 15px;
        padding-top: 20px;
        border-top: 1px solid #e9ecef;
        margin-top: 30px;
    }

    .personnel-card {
        background: white;
        padding: 20px;
        border-radius: 8px;
        border: 1px solid #e9ecef;
        text-align: center;
    }

    .personnel-avatar {
        width: 60px;
        height: 60px;
        border-radius: 50%;
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        display: flex;
        align-items: center;
        justify-content: center;
        color: white;
        font-size: 24px;
        font-weight: 700;
        margin: 0 auto 10px;
    }

    .personnel-name {
        font-weight: 600;
        color: #333;
        margin-bottom: 5px;
    }

    .personnel-role {
        font-size: 12px;
        color: #6c757d;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .alert {
        margin: 0 15px 20px;
        padding: 15px 20px;
        border-radius: 8px;
        border: 1px solid transparent;
    }

    .alert-success {
        background: #d4edda;
        color: #155724;
        border-color: #c3e6cb;
    }

    .alert-danger {
        background: #f8d7da;
        color: #721c24;
        border-color: #f5c6cb;
    }

    .meta-info {
        background: #f8f9fa;
        padding: 15px;
        border-radius: 6px;
        font-size: 13px;
        color: #6c757d;
        margin-top: 20px;
    }

    .meta-item {
        margin-bottom: 5px;
    }

    .meta-item:last-child {
        margin-bottom: 0;
    }

    @media (max-width: 768px) {
        .info-grid {
            grid-template-columns: 1fr;
        }
        
        .quantity-comparison {
            grid-template-columns: 1fr;
        }
        
        .action-buttons {
            flex-direction: column;
            align-items: stretch;
        }
    }
</style>

<div class="container">
    <h1>
        👁️ View Packing Record
        <span style="font-size: 16px; font-weight: normal; color: #6c757d;"><?= htmlspecialchars($packing['name']) ?></span>
    </h1>
    
    <!-- Success/Error Messages -->
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <!-- Main Information Container -->
    <div class="view-container">
        <div class="view-header">
            <span>📋 Packing Record Details</span>
            <span class="status-badge status-<?= $packing['packing_status'] ?>">
                <?= ucfirst($packing['packing_status']) ?>
            </span>
        </div>
        
        <div class="view-content">
            
            <!-- Basic Information Section -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-info-circle"></i> Basic Information
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Record Name</div>
                        <div class="info-value large"><?= htmlspecialchars($packing['name']) ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Fiscal Year</div>
                        <div class="info-value"><?= htmlspecialchars($packing['fiscal_code']) ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Packing Status</div>
                        <div class="info-value">
                            <span class="status-badge status-<?= $packing['packing_status'] ?>">
                                <?= ucfirst($packing['packing_status']) ?>
                            </span>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Completion Percentage</div>
                        <div class="info-value large" style="color: #28a745;"><?= $completion_percentage ?>%</div>
                    </div>
                </div>
            </div>

            <!-- Job Ticket Information Section -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-ticket-alt"></i> Job Ticket Information
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Job Ticket Code</div>
                        <div class="info-value" style="color: #007bff; font-weight: 600;">
                            <?= htmlspecialchars($packing['job_ticket_code']) ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Lot Number</div>
                        <div class="info-value"><?= htmlspecialchars($packing['lot'] ?? 'N/A') ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Total Print Quantity</div>
                        <div class="info-value"><?= number_format($packing['jt_total_qty']) ?></div>
                    </div>
                </div>
            </div>

            <!-- Book Information Section -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-book"></i> Book Information
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Book Code</div>
                        <div class="info-value" style="font-weight: 700; color: #495057;">
                            <?= htmlspecialchars($packing['book_code']) ?>
                        </div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Book Name</div>
                        <div class="info-value"><?= htmlspecialchars($packing['book_name']) ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Class Level</div>
                        <div class="info-value"><?= htmlspecialchars($packing['class_level']) ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">Subject</div>
                        <div class="info-value"><?= htmlspecialchars($packing['subject'] ?? 'N/A') ?></div>
                    </div>
                </div>
            </div>

            <!-- Quantity Information Section -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-boxes"></i> Quantity Information
                </div>
                
                <div class="progress-container">
                    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 10px;">
                        <span style="font-weight: 600;">Packing Progress</span>
                        <span style="font-weight: 700; color: #28a745;"><?= $completion_percentage ?>% Complete</span>
                    </div>
                    
                    <div class="progress-bar">
                        <div class="progress-fill" style="width: <?= $completion_percentage ?>%;">
                            <?php if ($completion_percentage > 20): ?>
                                <?= $completion_percentage ?>%
                            <?php endif; ?>
                        </div>
                    </div>
                    
                    <div style="font-size: 13px; color: #6c757d; text-align: center; margin-top: 10px;">
                        <?= number_format($packing['p_qty']) ?> of <?= number_format($packing['jt_total_qty']) ?> items packed
                    </div>
                </div>
                
                <div class="quantity-comparison">
                    <div class="qty-card total">
                        <div class="qty-number" style="color: #007bff;"><?= number_format($packing['jt_total_qty']) ?></div>
                        <div class="qty-label">Total Quantity</div>
                    </div>
                    
                    <div class="qty-card packed">
                        <div class="qty-number" style="color: #28a745;"><?= number_format($packing['p_qty']) ?></div>
                        <div class="qty-label">Packed Quantity</div>
                    </div>
                    
                    <div class="qty-card remaining">
                        <div class="qty-number" style="color: #ffc107;"><?= number_format($packing['jt_total_qty'] - $packing['p_qty']) ?></div>
                        <div class="qty-label">Remaining</div>
                    </div>
                </div>
            </div>

            <!-- Date Information Section -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-calendar-alt"></i> Date Information
                </div>
                
                <div class="info-grid">
                    <div class="info-item">
                        <div class="info-label">Nepali Date</div>
                        <div class="info-value" style="font-weight: 600;"><?= htmlspecialchars($packing['date_nep']) ?></div>
                    </div>
                    
                    <div class="info-item">
                        <div class="info-label">English Date</div>
                        <div class="info-value"><?= date('F j, Y', strtotime($packing['date_eng'])) ?></div>
                    </div>
                </div>
            </div>

            <!-- Personnel Information Section -->
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-users"></i> Personnel Information
                </div>
                
                <div class="info-grid">
                    <div class="personnel-card">
                        <div class="personnel-avatar">
                            <?= strtoupper(substr($packing['supervisor_name'], 0, 2)) ?>
                        </div>
                        <div class="personnel-name"><?= htmlspecialchars($packing['supervisor_name']) ?></div>
                        <div class="personnel-role">Supervisor</div>
                    </div>
                    
                    <div class="personnel-card">
                        <div class="personnel-avatar" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                            <?= strtoupper(substr($packing['incharge_name'], 0, 2)) ?>
                        </div>
                        <div class="personnel-name"><?= htmlspecialchars($packing['incharge_name']) ?></div>
                        <div class="personnel-role">In-charge</div>
                    </div>
                    
                    <div class="personnel-card">
                        <div class="personnel-avatar" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                            <?= strtoupper(substr($packing['operator_name'], 0, 2)) ?>
                        </div>
                        <div class="personnel-name"><?= htmlspecialchars($packing['operator_name']) ?></div>
                        <div class="personnel-role">Operator</div>
                    </div>
                </div>
            </div>

            <!-- Additional Information Section -->
            <?php if (!empty($packing['remarks']) || !empty($packing['description'])): ?>
            <div class="info-section">
                <div class="section-title">
                    <i class="fas fa-comment-alt"></i> Additional Information
                </div>
                
                <div class="info-grid">
                    <?php if (!empty($packing['remarks'])): ?>
                    <div class="info-item">
                        <div class="info-label">Remarks</div>
                        <div class="info-value" style="white-space: pre-wrap;"><?= htmlspecialchars($packing['remarks']) ?></div>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($packing['description'])): ?>
                    <div class="info-item">
                        <div class="info-label">Description</div>
                        <div class="info-value" style="white-space: pre-wrap;"><?= htmlspecialchars($packing['description']) ?></div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            <?php endif; ?>

            <!-- Metadata Section -->
            <div class="meta-info">
                <div class="meta-item">
                    <i class="fas fa-user-plus"></i> <strong>Created by:</strong> 
                    <?= htmlspecialchars($packing['created_by_name']) ?> on 
                    <?= date('F j, Y \a\t g:i A', strtotime($packing['created_date'])) ?>
                </div>
                
                <?php if (!empty($packing['updated_by']) && !empty($packing['updated_date'])): ?>
                <div class="meta-item">
                    <i class="fas fa-user-edit"></i> <strong>Last updated by:</strong> 
                    <?= htmlspecialchars($packing['updated_by_name']) ?> on 
                    <?= date('F j, Y \a\t g:i A', strtotime($packing['updated_date'])) ?>
                </div>
                <?php endif; ?>
            </div>

            <!-- Action Buttons -->
            <div class="action-buttons">
                <div>
                    <a href="index.php" class="btn btn-secondary">
                        <i class="fas fa-arrow-left"></i> Back to List
                    </a>
                </div>
                <div>
                    <?php if (has_role('editor') || has_role('admin')): ?>
                        <a href="edit.php?id=<?= $packing['id'] ?>" class="btn btn-warning">
                            <i class="fas fa-edit"></i> Edit Record
                        </a>
                    <?php endif; ?>
                    <a href="print.php?id=<?= $packing['id'] ?>" class="btn btn-success" target="_blank">
                        <i class="fas fa-print"></i> Print Report
                    </a>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Animate progress bar
    const progressFill = document.querySelector('.progress-fill');
    if (progressFill) {
        const targetWidth = progressFill.style.width;
        progressFill.style.width = '0%';
        
        setTimeout(() => {
            progressFill.style.width = targetWidth;
        }, 500);
    }
    
    // Add hover effects to cards
    const cards = document.querySelectorAll('.qty-card, .personnel-card');
    cards.forEach(card => {
        card.addEventListener('mouseenter', function() {
            this.style.transform = 'translateY(-2px)';
            this.style.boxShadow = '0 4px 12px rgba(0,0,0,0.1)';
        });
        
        card.addEventListener('mouseleave', function() {
            this.style.transform = 'translateY(0)';
            this.style.boxShadow = 'none';
        });
    });
});

// Keyboard shortcuts
document.addEventListener('keydown', function(e) {
    // E for edit
    if (e.key.toLowerCase() === 'e' && !e.ctrlKey && !e.altKey) {
        const editBtn = document.querySelector('a[href*="packing_edit.php"]');
        if (editBtn) {
            editBtn.click();
        }
    }
    
    // P for print
    if (e.key.toLowerCase() === 'p' && !e.ctrlKey && !e.altKey) {
        e.preventDefault();
        const printBtn = document.querySelector('a[href*="packing_print.php"]');
        if (printBtn) {
            window.open(printBtn.href, '_blank');
        }
    }
    
    // B for back
    if (e.key.toLowerCase() === 'b' && !e.ctrlKey && !e.altKey) {
        const backBtn = document.querySelector('a[href*="book_packing.php"]');
        if (backBtn) {
            backBtn.click();
        }
    }
});

console.log('Packing View page loaded successfully');
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
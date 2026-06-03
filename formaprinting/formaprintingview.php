<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
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

try {
    // Get forma printing record with all related data
    $stmt = $conn->prepare("
        SELECT 
            fp.*,
            jt.job_ticket_code,
            jt.lot,
            jt.print_qty as jt_print_qty,
            jt.page_qty as jt_page_qty,
            jt.status as jt_status,
            jtd.order_no,
            jtd.page as jtd_page,
            jtd.print_qty as jtd_print_qty,
            jtd.status as jtd_status,
            b.book_code,
            b.book_name,
            b.class_level,
            m.machine_name,
            fy.fiscal_code,
            supervisor.username as supervisor_name,
            operator.username as operator_name,
            incharge.username as incharge_name,
            creator.username as created_by_name,
            updater.username as updated_by_name,
            f.name as forma_name,
            f.status as forma_status,
            s.name as shift_name
        FROM forma_printing fp
        LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
        LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
        LEFT JOIN books b ON jt.book_id = b.book_id
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
        LEFT JOIN users supervisor ON fp.supervisor_id = supervisor.id
        LEFT JOIN users operator ON fp.operator_id = operator.id
        LEFT JOIN users incharge ON fp.incharge_id = incharge.id
        LEFT JOIN users creator ON fp.created_by = creator.id
        LEFT JOIN users updater ON fp.updated_by = updater.id
        LEFT JOIN forma f ON jtd.forma_id = f.id
        LEFT JOIN shifts s ON fp.shift_id = s.id
        WHERE fp.id = :id
    ");
    
    $stmt->execute([':id' => $id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        $_SESSION['error_message'] = "Record not found";
        header('Location: index.php');
        exit();
    }
    
    // Get other forma printing records for the same JTD
    $relatedStmt = $conn->prepare("
        SELECT 
            fp.id,
            fp.name,
            fp.date_nep,
            fp.fp_printqty,
            fp.fp_remainqty,
            fp.status,
            fp.created_date,
            m.machine_name,
            operator.username as operator_name
        FROM forma_printing fp
        LEFT JOIN machines m ON fp.machine_id = m.id
        LEFT JOIN users operator ON fp.operator_id = operator.id
        WHERE fp.jtd_id = :jtd_id 
        AND fp.id != :current_id
        AND fp.status = true
        ORDER BY fp.created_date DESC
    ");
    
    $relatedStmt->execute([
        ':jtd_id' => $record['jtd_id'],
        ':current_id' => $id
    ]);
    $relatedRecords = $relatedStmt->fetchAll(PDO::FETCH_ASSOC);
    
} catch (PDOException $e) {
    $_SESSION['error_message'] = "Error fetching record: " . $e->getMessage();
    header('Location: index.php');
    exit();
}
?>

<style>
    .container {
        max-width: 100%;
        padding: 20px;
    }

    .record-header {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
        color: white;
        padding: 30px;
        border-radius: 10px;
        margin-bottom: 30px;
        box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    }

    .record-title {
        font-size: 28px;
        font-weight: 600;
        margin-bottom: 10px;
        display: flex;
        align-items: center;
        gap: 15px;
    }

    .record-subtitle {
        font-size: 16px;
        opacity: 0.9;
        display: flex;
        align-items: center;
        gap: 20px;
        flex-wrap: wrap;
    }

    .action-buttons {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 30px;
        flex-wrap: wrap;
        gap: 15px;
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
    }

    .btn:hover {
        transform: translateY(-1px);
        box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    }

    .btn-primary { background: #007bff; color: white; }
    .btn-success { background: #28a745; color: white; }
    .btn-warning { background: #ffc107; color: #212529; }
    .btn-secondary { background: #6c757d; color: white; }
    .btn-info { background: #17a2b8; color: white; }

    .info-cards {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(350px, 1fr));
        gap: 25px;
        margin-bottom: 30px;
    }

    .info-card {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        padding: 25px;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
        transition: all 0.3s ease;
    }

    .info-card:hover {
        box-shadow: 0 4px 15px rgba(0,0,0,0.15);
        transform: translateY(-2px);
    }

    .info-card-title {
        font-size: 18px;
        font-weight: 600;
        color: #333;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        border-bottom: 2px solid #f8f9fa;
        padding-bottom: 10px;
    }

    .info-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
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
        font-weight: 600;
        margin-bottom: 5px;
        letter-spacing: 0.5px;
    }

    .info-value {
        font-size: 15px;
        color: #333;
        font-weight: 500;
        word-break: break-word;
    }

    .info-value.large {
        font-size: 24px;
        font-weight: 700;
        color: #007bff;
    }

    .status-badge {
        padding: 6px 12px;
        border-radius: 20px;
        font-size: 12px;
        font-weight: 600;
        text-transform: uppercase;
        letter-spacing: 0.5px;
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

    .status-completed {
        background: #cce7ff;
        color: #004085;
        border: 1px solid #99d3ff;
    }

    .status-processing {
        background: #fff3cd;
        color: #856404;
        border: 1px solid #ffeaa7;
    }

    .related-records {
        background: white;
        border: 1px solid #e9ecef;
        border-radius: 10px;
        overflow: hidden;
        box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    }

    .related-header {
        background: #f8f9fa;
        padding: 20px;
        border-bottom: 1px solid #e9ecef;
    }

    .related-title {
        font-size: 18px;
        font-weight: 600;
        color: #333;
        margin: 0;
        display: flex;
        align-items: center;
        gap: 10px;
    }

    .related-table {
        width: 100%;
        border-collapse: collapse;
    }

    .related-table th,
    .related-table td {
        padding: 15px;
        text-align: left;
        border-bottom: 1px solid #e9ecef;
    }

    .related-table th {
        background: #f8f9fa;
        font-weight: 600;
        color: #495057;
        font-size: 13px;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }

    .related-table tbody tr:hover {
        background: #f8f9fa;
    }

    .no-data {
        text-align: center;
        padding: 40px 20px;
        color: #6c757d;
        font-style: italic;
    }

    .alert {
        padding: 15px;
        margin-bottom: 20px;
        border: 1px solid transparent;
        border-radius: 6px;
    }

    .alert-success {
        color: #155724;
        background-color: #d4edda;
        border-color: #c3e6cb;
    }

    .quantity-summary {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
        color: white;
        padding: 20px;
        border-radius: 10px;
        margin-bottom: 25px;
    }

    .quantity-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(120px, 1fr));
        gap: 20px;
        text-align: center;
    }

    .quantity-item h3 {
        font-size: 28px;
        font-weight: 700;
        margin-bottom: 5px;
    }

    .quantity-item span {
        font-size: 12px;
        text-transform: uppercase;
        opacity: 0.9;
        font-weight: 500;
    }

    @media (max-width: 768px) {
        .container {
            padding: 10px;
        }
        
        .info-cards {
            grid-template-columns: 1fr;
        }
        
        .action-buttons {
            flex-direction: column;
            align-items: stretch;
        }
        
        .record-subtitle {
            flex-direction: column;
            align-items: flex-start;
            gap: 10px;
        }
        
        .quantity-grid {
            grid-template-columns: repeat(2, 1fr);
        }
    }

    @media print {
        .action-buttons, .btn {
            display: none !important;
        }
        
        .container {
            padding: 0;
        }
        
        .info-card, .related-records {
            box-shadow: none;
            border: 1px solid #ddd;
        }
        
        .record-header {
            background: #f8f9fa !important;
            color: #333 !important;
        }
    }
</style>

<div class="container">
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= $_SESSION['success_message'] ?>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <!-- Record Header -->
    <div class="record-header">
        <div class="record-title">
            <i class="fas fa-print"></i>
            <?= htmlspecialchars($record['name']) ?>
        </div>
        <div class="record-subtitle">
            <span><i class="fas fa-hashtag"></i> ID: #<?= $record['id'] ?></span>
            <span><i class="fas fa-calendar"></i> <?= htmlspecialchars($record['date_nep']) ?></span>
            <span><i class="fas fa-ticket-alt"></i> <?= htmlspecialchars($record['job_ticket_code']) ?></span>
            <span class="status-badge <?= $record['status'] ? 'status-active' : 'status-inactive' ?>">
                <?= $record['status'] ? 'Active' : 'Inactive' ?>
            </span>
        </div>
    </div>

    <!-- Action Buttons -->
    <div class="action-buttons">
        <div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <a href="index.php?jt_code=<?= urlencode($record['job_ticket_code']) ?>" class="btn btn-info">
                <i class="fas fa-search"></i> View Related Records
            </a>
        </div>
        <div>
            <?php if (has_role('editor') || has_role('admin')): ?>
                <a href="forma_printing_edit.php?id=<?= $record['id'] ?>" class="btn btn-warning">
                    <i class="fas fa-edit"></i> Edit Record
                </a>
                <a href="create.php" class="btn btn-success">
                    <i class="fas fa-plus"></i> Create New
                </a>
            <?php endif; ?>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print
            </button>
        </div>
    </div>

    <!-- Quantity Summary -->
    <div class="quantity-summary">
        <div class="quantity-grid">
            <div class="quantity-item">
                <h3><?= number_format($record['jtd_targetqty']) ?></h3>
                <span>Target Quantity</span>
            </div>
            <div class="quantity-item">
                <h3><?= number_format($record['fp_printqty']) ?></h3>
                <span>Printed Quantity</span>
            </div>
            <div class="quantity-item">
                <h3><?= number_format($record['fp_remainqty']) ?></h3>
                <span>Remaining Quantity</span>
            </div>
            <div class="quantity-item">
                <h3><?= $record['jtd_targetqty'] > 0 ? round(($record['fp_printqty'] / $record['jtd_targetqty']) * 100, 1) : 0 ?>%</h3>
                <span>Completion</span>
            </div>
        </div>
    </div>

    <!-- Information Cards -->
    <div class="info-cards">
        <!-- Job Ticket Information -->
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-clipboard-list"></i> Job Ticket Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Job Ticket Code</span>
                    <span class="info-value"><?= htmlspecialchars($record['job_ticket_code']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Lot Number</span>
                    <span class="info-value"><?= htmlspecialchars($record['lot']) ?></span>
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
                    <span class="info-value">Class <?= htmlspecialchars($record['class_level']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">JT Status</span>
                    <span class="info-value">
                        <span class="status-badge <?= $record['jt_status'] == 'completed' ? 'status-completed' : 'status-processing' ?>">
                            <?= htmlspecialchars($record['jt_status']) ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Forma Information -->
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-file-alt"></i> Forma Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Forma Name</span>
                    <span class="info-value"><?= htmlspecialchars($record['forma_name']) ?: 'N/A' ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Order Number</span>
                    <span class="info-value"><?= $record['order_no'] ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Page Number</span>
                    <span class="info-value"><?= $record['jtd_page'] ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Forma Status</span>
                    <span class="info-value">
                        <span class="status-badge <?= $record['forma_status'] == 'completed' ? 'status-completed' : 'status-processing' ?>">
                            <?= htmlspecialchars($record['forma_status']) ?: 'N/A' ?>
                        </span>
                    </span>
                </div>
                <div class="info-item">
                    <span class="info-label">JTD Status</span>
                    <span class="info-value">
                        <span class="status-badge <?= $record['jtd_status'] == 'completed' ? 'status-completed' : 'status-processing' ?>">
                            <?= htmlspecialchars($record['jtd_status']) ?>
                        </span>
                    </span>
                </div>
            </div>
        </div>

        <!-- Personnel Information -->
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-users"></i> Personnel Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Supervisor</span>
                    <span class="info-value"><?= htmlspecialchars($record['supervisor_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Operator</span>
                    <span class="info-value"><?= htmlspecialchars($record['operator_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Incharge</span>
                    <span class="info-value"><?= htmlspecialchars($record['incharge_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Shift</span>
                    <span class="info-value"><?= htmlspecialchars($record['shift_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Machine</span>
                    <span class="info-value"><?= htmlspecialchars($record['machine_name']) ?></span>
                </div>
            </div>
        </div>

        <!-- System Information -->
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-info-circle"></i> System Information
            </div>
            <div class="info-grid">
                <div class="info-item">
                    <span class="info-label">Fiscal Year</span>
                    <span class="info-value"><?= htmlspecialchars($record['fiscal_code']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">English Date</span>
                    <span class="info-value"><?= htmlspecialchars($record['date_eng']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created By</span>
                    <span class="info-value"><?= htmlspecialchars($record['created_by_name']) ?></span>
                </div>
                <div class="info-item">
                    <span class="info-label">Created Date</span>
                    <span class="info-value"><?= date('Y-m-d H:i:s', strtotime($record['created_date'])) ?></span>
                </div>
                <?php if ($record['updated_by']): ?>
                <div class="info-item">
                    <span class="info-label">Updated By</span>
                    <span class="info-value"><?= htmlspecialchars($record['updated_by_name']) ?></span>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <!-- Remarks and Description -->
    <?php if ($record['remarks'] || $record['description']): ?>
    <div class="info-cards">
        <?php if ($record['remarks']): ?>
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-comment"></i> Remarks
            </div>
            <div class="info-value">
                <?= nl2br(htmlspecialchars($record['remarks'])) ?>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($record['description']): ?>
        <div class="info-card">
            <div class="info-card-title">
                <i class="fas fa-file-text"></i> Description
            </div>
            <div class="info-value">
                <?= nl2br(htmlspecialchars($record['description'])) ?>
            </div>
        </div>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <!-- Related Records -->
    <?php if (count($relatedRecords) > 0): ?>
    <div class="related-records">
        <div class="related-header">
            <h3 class="related-title">
                <i class="fas fa-link"></i> 
                Other Printing Records for Same Forma (<?= count($relatedRecords) ?> found)
            </h3>
        </div>
        <table class="related-table">
            <thead>
                <tr>
                    <th>Name</th>
                    <th>Date</th>
                    <th>Printed Qty</th>
                    <th>Remaining</th>
                    <th>Machine</th>
                    <th>Operator</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($relatedRecords as $related): ?>
                <tr>
                    <td><?= htmlspecialchars($related['name']) ?></td>
                    <td><?= htmlspecialchars($related['date_nep']) ?></td>
                    <td><?= number_format($related['fp_printqty']) ?></td>
                    <td><?= number_format($related['fp_remainqty']) ?></td>
                    <td><?= htmlspecialchars($related['machine_name']) ?></td>
                    <td><?= htmlspecialchars($related['operator_name']) ?></td>
                    <td>
                        <span class="status-badge <?= $related['status'] ? 'status-active' : 'status-inactive' ?>">
                            <?= $related['status'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <a href="forma_printing_view.php?id=<?= $related['id'] ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-eye"></i>
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <?php endif; ?>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Add smooth scrolling to anchor links
    document.querySelectorAll('a[href^="#"]').forEach(anchor => {
        anchor.addEventListener('click', function (e) {
            e.preventDefault();
            document.querySelector(this.getAttribute('href')).scrollIntoView({
                behavior: 'smooth'
            });
        });
    });
    
    // Add keyboard shortcuts
    document.addEventListener('keydown', function(e) {
        // 'E' key for edit (if user has permission)
        if (e.key.toLowerCase() === 'e' && !e.ctrlKey && !e.altKey) {
            const editBtn = document.querySelector('a[href*="edit"]');
            if (editBtn) {
                window.location.href = editBtn.href;
            }
        }
        
        // 'B' key for back
        if (e.key.toLowerCase() === 'b' && !e.ctrlKey && !e.altKey) {
            window.location.href = 'index.php';
        }
        
        // 'P' key for print
        if (e.key.toLowerCase() === 'p' && e.ctrlKey) {
            e.preventDefault();
            window.print();
        }
    });
    
    // Add tooltips to status badges
    document.querySelectorAll('.status-badge').forEach(badge => {
        const status = badge.textContent.trim().toLowerCase();
        let tooltip = '';
        
        switch(status) {
            case 'active':
                tooltip = 'This record is currently active';
                break;
            case 'inactive':
                tooltip = 'This record has been deactivated';
                break;
            case 'completed':
                tooltip = 'This process has been completed';
                break;
            case 'processing':
                tooltip = 'This process is currently in progress';
                break;
        }
        
        if (tooltip) {
            badge.title = tooltip;
        }
    });
    
    console.log('Forma Printing View page loaded successfully');
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
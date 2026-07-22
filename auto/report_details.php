<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('viewer') && !has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

// Get job ticket ID
$jt_id = isset($_GET['jt_id']) ? (int)$_GET['jt_id'] : 0;

if (!$jt_id) {
    header('Location: production_report.php');
    exit();
}

// Fetch Job Ticket Details
$jt_sql = "
SELECT 
    jt.*,
    b.book_code,
    b.book_name,
    b.class_level,
    b.book_name_nepali,
    fy.fiscal_code,
    u.username as created_by_name
FROM job_ticket jt
LEFT JOIN books b ON jt.book_id = b.book_id
LEFT JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
LEFT JOIN users u ON jt.created_by = u.id
WHERE jt.id = :jt_id
";

$stmt = $conn->prepare($jt_sql);
$stmt->execute([':jt_id' => $jt_id]);
$job_ticket = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$job_ticket) {
    header('Location: production_report.php');
    exit();
}

// Fetch Job Ticket Details (Formas)
$jtd_sql = "
SELECT 
    jtd.*,
    COALESCE(SUM(fp.fp_printqty), 0) as printed_qty,
    CASE 
        WHEN COALESCE(SUM(fp.fp_printqty), 0) >= jtd.print_qty THEN true 
        ELSE false 
    END as is_completed
FROM job_ticket_details jtd
LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = true
WHERE jtd.job_ticket_id = :jt_id
GROUP BY jtd.id
ORDER BY jtd.order_no
";

$stmt = $conn->prepare($jtd_sql);
$stmt->execute([':jt_id' => $jt_id]);
$formas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Forma Printing Records
$fp_sql = "
SELECT 
    fp.*,
    jtd.order_no,
    jtd.forma_id,
    jtd.page,
    m.machine_name,
    s.name as shift_name,
    u_op.username as operator_name,
    u_sup.username as supervisor_name,
    u_inc.username as incharge_name
FROM forma_printing fp
LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
LEFT JOIN machines m ON fp.machine_id = m.id
LEFT JOIN shifts s ON fp.shift_id = s.id
LEFT JOIN users u_op ON fp.operator_id = u_op.id
LEFT JOIN users u_sup ON fp.supervisor_id = u_sup.id
LEFT JOIN users u_inc ON fp.incharge_id = u_inc.id
WHERE fp.jt_id = :jt_id AND fp.status = true
ORDER BY fp.created_date DESC
";

$stmt = $conn->prepare($fp_sql);
$stmt->execute([':jt_id' => $jt_id]);
$forma_printings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Book Packing Records
$bp_sql = "
SELECT 
    bp.*,
    u_op.username as operator_name,
    u_sup.username as supervisor_name,
    u_inc.username as incharge_name,
    u_cr.username as created_by_name
FROM book_packing bp
LEFT JOIN users u_op ON bp.operator_id = u_op.id
LEFT JOIN users u_sup ON bp.supervisor_id = u_sup.id
LEFT JOIN users u_inc ON bp.incharge_id = u_inc.id
LEFT JOIN users u_cr ON bp.created_by = u_cr.id
WHERE bp.jt_id = :jt_id AND bp.status = true
ORDER BY bp.created_date DESC
";

$stmt = $conn->prepare($bp_sql);
$stmt->execute([':jt_id' => $jt_id]);
$book_packings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch Deno Entries
$deno_sql = "
SELECT 
    d.*,
    b.book_name
FROM deno d
LEFT JOIN books b ON d.book_code = b.book_code
WHERE d.jt_id = :jt_id AND d.deleted_at IS NULL
ORDER BY d.created_at DESC
";

$stmt = $conn->prepare($deno_sql);
$stmt->execute([':jt_id' => $jt_id]);
$deno_entries = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Fetch D2M Documents
$d2m_sql = "
SELECT DISTINCT
    d2m.*,
    fy.fiscal_code,
    u_cr.username as created_by_name,
    u_ch.username as checked_by_name,
    u_vr.username as verified_by_name
FROM deno d
JOIN d2m_items di ON d.id = ANY(string_to_array(di.associated_deno_ids, ',')::int[])
JOIN d2m ON di.d2m_id = d2m.id
LEFT JOIN fiscal_years fy ON d2m.fiscal_year_id = fy.id
LEFT JOIN users u_cr ON d2m.created_by = u_cr.id
LEFT JOIN users u_ch ON d2m.checked_by = u_ch.id
LEFT JOIN users u_vr ON d2m.verified_by = u_vr.id
WHERE d.jt_id = :jt_id AND d.deleted_at IS NULL
ORDER BY d2m.created_at DESC
";

$stmt = $conn->prepare($d2m_sql);
$stmt->execute([':jt_id' => $jt_id]);
$d2m_documents = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate Summary Statistics
$total_formas = count($formas);
$completed_formas = count(array_filter($formas, fn($f) => $f['is_completed']));
$total_forma_target = array_sum(array_column($formas, 'print_qty'));
$total_printed = array_sum(array_column($forma_printings, 'fp_printqty'));
$total_packed = array_sum(array_column($book_packings, 'p_qty'));
$total_deno_qty = array_sum(array_column($deno_entries, 'total_qty'));

$printing_pct = $total_forma_target > 0 ? round(($total_printed / $total_forma_target) * 100, 2) : 0;
$packing_pct = $job_ticket['print_qty'] > 0 ? round(($total_packed / $job_ticket['print_qty']) * 100, 2) : 0;
$deno_pct = $job_ticket['print_qty'] > 0 ? round(($total_deno_qty / $job_ticket['print_qty']) * 100, 2) : 0;

$deno_verified = count(array_filter($deno_entries, fn($d) => !empty($d['verify_by'])));
$d2m_verified = count(array_filter($d2m_documents, fn($d) => $d['status'] === 'VERIFIED'));

$overall_progress = round(
    ($printing_pct * 0.30) + 
    ($packing_pct * 0.30) + 
    ($deno_pct * 0.25) +
    (count($d2m_documents) > 0 && $d2m_verified > 0 ? 15 : 0),
    2
);

?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
    font-size: 14px;
}

.detail-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.header-top {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.header-actions {
    display: flex;
    gap: 10px;
}

.jt-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
}

.info-item {
    display: flex;
    flex-direction: column;
}

.info-label {
    font-size: 12px;
    font-weight: 600;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 5px;
}

.info-value {
    font-size: 16px;
    font-weight: 600;
    color: #333;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    border-radius: 10px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #007bff;
}

.summary-card-value {
    font-size: 32px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.summary-card-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
}

.section-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.section-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
}

.section-title {
    font-size: 20px;
    font-weight: 700;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.section-count {
    background: #007bff;
    color: white;
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 13px;
}

.data-table thead {
    background: #f8f9fa;
}

.data-table th {
    padding: 12px;
    text-align: left;
    font-size: 11px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    border-bottom: 2px solid #dee2e6;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
    vertical-align: middle;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 10px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-completed { background: #d4edda; color: #155724; }
.status-pending { background: #fff3cd; color: #856404; }
.status-active { background: #cce5ff; color: #004085; }
.status-verified { background: #28a745; color: white; }
.status-checked { background: #17a2b8; color: white; }
.status-draft { background: #e9ecef; color: #495057; }

.progress-bar-simple {
    width: 100px;
    height: 8px;
    background: #e9ecef;
    border-radius: 4px;
    overflow: hidden;
    display: inline-block;
    vertical-align: middle;
}

.progress-fill {
    height: 100%;
    background: #28a745;
    transition: width 0.3s;
}

.progress-fill.low { background: #dc3545; }
.progress-fill.medium { background: #ffc107; }
.progress-fill.high { background: #28a745; }

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 13px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    transition: all 0.2s;
}

.btn-primary { background: #007bff; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-info { background: #17a2b8; color: white; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.empty-state {
    text-align: center;
    padding: 40px;
    color: #6c757d;
}

.timeline {
    position: relative;
    padding-left: 30px;
}

.timeline-item {
    position: relative;
    padding-bottom: 20px;
}

.timeline-item::before {
    content: '';
    position: absolute;
    left: -22px;
    top: 5px;
    width: 12px;
    height: 12px;
    border-radius: 50%;
    background: #007bff;
    border: 2px solid white;
    box-shadow: 0 0 0 2px #007bff;
}

.timeline-item::after {
    content: '';
    position: absolute;
    left: -17px;
    top: 17px;
    width: 2px;
    height: calc(100% - 12px);
    background: #e9ecef;
}

.timeline-item:last-child::after {
    display: none;
}

.timeline-date {
    font-size: 12px;
    color: #6c757d;
    font-weight: 600;
}

.timeline-content {
    background: #f8f9fa;
    padding: 12px;
    border-radius: 6px;
    margin-top: 5px;
}

@media print {
    .header-actions,
    .btn {
        display: none !important;
    }
}

@media (max-width: 768px) {
    .jt-info-grid {
        grid-template-columns: 1fr;
    }
    
    .summary-cards {
        grid-template-columns: repeat(2, 1fr);
    }
}
</style>

<div class="detail-container">
    <!-- Page Header -->
    <div class="page-header">
        <div class="header-top">
            <h1 class="page-title">📋 Job Ticket Detail Report</h1>
            <div class="header-actions">
                <a href="production_report.php" class="btn btn-secondary">← Back to Report</a>
                <button class="btn btn-info" onclick="window.print()">🖨️ Print</button>
            </div>
        </div>
        
        <div class="jt-info-grid">
            <div class="info-item">
                <div class="info-label">Job Ticket Code</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['job_ticket_code']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Book Name</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['book_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Book Code</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['book_code']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Class Level</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['class_level']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Lot</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['lot']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Print Quantity</div>
                <div class="info-value"><?= number_format($job_ticket['print_qty']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Page Quantity</div>
                <div class="info-value"><?= number_format($job_ticket['page_qty']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Fiscal Year</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['fiscal_code']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Status</div>
                <div class="info-value">
                    <span class="status-badge status-<?= $job_ticket['status'] ?>">
                        <?= ucfirst(str_replace('_', ' ', $job_ticket['status'])) ?>
                    </span>
                </div>
            </div>
            <div class="info-item">
                <div class="info-label">Created By</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['created_by_name']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Date (Nep)</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['date_nep']) ?></div>
            </div>
            <div class="info-item">
                <div class="info-label">Date (Eng)</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['date_eng']) ?></div>
            </div>
        </div>
    </div>

    <!-- Summary Cards -->
    <div class="summary-cards">
        <div class="summary-card" style="border-left-color: #6f42c1;">
            <div class="summary-card-value"><?= $printing_pct ?>%</div>
            <div class="summary-card-label">Printing Progress</div>
        </div>
        <div class="summary-card" style="border-left-color: #ffc107;">
            <div class="summary-card-value"><?= $packing_pct ?>%</div>
            <div class="summary-card-label">Packing Progress</div>
        </div>
        <div class="summary-card" style="border-left-color: #17a2b8;">
            <div class="summary-card-value"><?= $deno_pct ?>%</div>
            <div class="summary-card-label">Deno Progress</div>
        </div>
        <div class="summary-card" style="border-left-color: #28a745;">
            <div class="summary-card-value"><?= $overall_progress ?>%</div>
            <div class="summary-card-label">Overall Progress</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= $completed_formas ?>/<?= $total_formas ?></div>
            <div class="summary-card-label">Formas Completed</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= count($book_packings) ?></div>
            <div class="summary-card-label">Packing Records</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= count($deno_entries) ?></div>
            <div class="summary-card-label">Deno Entries</div>
        </div>
        <div class="summary-card">
            <div class="summary-card-value"><?= count($d2m_documents) ?></div>
            <div class="summary-card-label">D2M Documents</div>
        </div>
    </div>

    <!-- Formas Section -->
    <div class="section-container">
        <div class="section-header">
            <div class="section-title">
                📄 Formas (Job Ticket Details)
                <span class="section-count"><?= count($formas) ?></span>
            </div>
        </div>
        
        <?php if (empty($formas)): ?>
            <div class="empty-state">No formas found for this job ticket.</div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Order</th>
                        <th>Forma ID</th>
                        <th>Page</th>
                        <th>Target Qty</th>
                        <th>Printed Qty</th>
                        <th>Progress</th>
                        <th>Machine</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($formas as $forma): 
                        $progress = $forma['print_qty'] > 0 ? round(($forma['printed_qty'] / $forma['print_qty']) * 100, 2) : 0;
                        $progress_class = $progress < 50 ? 'low' : ($progress < 100 ? 'medium' : 'high');
                    ?>
                    <tr>
                        <td><strong><?= $forma['order_no'] ?></strong></td>
                        <td><?= htmlspecialchars($forma['forma_id']) ?></td>
                        <td><?= $forma['page'] ?></td>
                        <td><?= number_format($forma['print_qty']) ?></td>
                        <td><strong><?= number_format($forma['printed_qty']) ?></strong></td>
                        <td>
                            <div class="progress-bar-simple">
                                <div class="progress-fill <?= $progress_class ?>" style="width: <?= $progress ?>%"></div>
                            </div>
                            <?= $progress ?>%
                        </td>
                        <td><?= htmlspecialchars($forma['machine']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $forma['is_completed'] ? 'completed' : 'pending' ?>">
                                <?= $forma['is_completed'] ? 'Completed' : 'Pending' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Forma Printing Records Section -->
    <div class="section-container">
        <div class="section-header">
            <div class="section-title">
                🖨️ Forma Printing Records
                <span class="section-count"><?= count($forma_printings) ?></span>
            </div>
        </div>
        
        <?php if (empty($forma_printings)): ?>
            <div class="empty-state">No forma printing records found.</div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date (Nep)</th>
                        <th>Forma</th>
                        <th>Printed Qty</th>
                        <th>Waste</th>
                        <th>Machine</th>
                        <th>Shift</th>
                        <th>Operator</th>
                        <th>Supervisor</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($forma_printings as $fp): ?>
                    <tr>
                        <td><?= htmlspecialchars($fp['fp_date_nep']) ?></td>
                        <td>
                            <div>Order: <?= $fp['order_no'] ?></div>
                            <small style="color: #6c757d;">Forma: <?= htmlspecialchars($fp['forma_id']) ?></small>
                        </td>
                        <td><strong><?= number_format($fp['fp_printqty']) ?></strong></td>
                        <td><?= number_format($fp['fp_waste']) ?></td>
                        <td><?= htmlspecialchars($fp['machine_name']) ?></td>
                        <td><?= htmlspecialchars($fp['shift_name']) ?></td>
                        <td><?= htmlspecialchars($fp['operator_name']) ?></td>
                        <td><?= htmlspecialchars($fp['supervisor_name']) ?></td>
                        <td>
                            <small><?= htmlspecialchars($fp['remarks'] ?: '-') ?></small>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Book Packing Section -->
    <div class="section-container">
        <div class="section-header">
            <div class="section-title">
                📦 Book Packing Records
                <span class="section-count"><?= count($book_packings) ?></span>
            </div>
        </div>
        
        <?php if (empty($book_packings)): ?>
            <div class="empty-state">No book packing records found.</div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Name</th>
                        <th>Date (Nep)</th>
                        <th>Packed Qty</th>
                        <th>Operator</th>
                        <th>Supervisor</th>
                        <th>Incharge</th>
                        <th>Status</th>
                        <th>Remarks</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($book_packings as $bp): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($bp['name']) ?></strong></td>
                        <td><?= htmlspecialchars($bp['date_nep']) ?></td>
                        <td><strong><?= number_format($bp['p_qty']) ?></strong></td>
                        <td><?= htmlspecialchars($bp['operator_name']) ?></td>
                        <td><?= htmlspecialchars($bp['supervisor_name']) ?></td>
                        <td><?= htmlspecialchars($bp['incharge_name']) ?></td>
                        <td>
                            <span class="status-badge status-<?= $bp['packing_status'] ?>">
                                <?= ucfirst($bp['packing_status']) ?>
                            </span>
                        </td>
                        <td><small><?= htmlspecialchars($bp['remarks'] ?: '-') ?></small></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>

    <!-- Deno Entries Section -->
    <div class="section-container">
        <div class="section-header">
            <div class="section-title">
                📝 Deno Entries
                <span class="section-count"><?= count($deno_entries) ?></span>
            </div>
        </div>
        
        <?php if (empty($deno_entries)): ?>
            <div class="empty-state">No deno entries found.</div>
        <?php else: ?>
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Ref No</th>
                        <th>Date (Nep)</th>
                        <th>Per Poka</th>
                        <th>Poka Qty</th>
                        <th>Open Pcs</th>
                        <th>Total Qty</th>
                        <th>Created By</th>
                        <th>Received By</th>
                        <th>Verified By</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($deno_entries as $deno): ?>
                    <tr>
                        <td><strong><?= htmlspecialchars($deno['ref_no']) ?></strong></td>
                        <td><?= htmlspecialchars($deno['deno_date_nep']) ?></td>
                        <td><?= number_format($deno['per_poka_qty']) ?></td>
                        <td><?= number_format($deno['poka_qty']) ?></td>
                        <td><?= number_format($deno['quantity_openpcs']) ?></td>
                        <td><strong><?= number_format($deno['total_qty']) ?></strong></td>
                        <td><?= htmlspecialchars($deno['created_by']) ?></td>
                        <td><?= htmlspecialchars($deno['received_by'] ?: '-') ?></td>
                        <td><?= htmlspecialchars($deno['verify_by'] ?: '-') ?></td>
                        <td>
                            <span class="status-badge status-<?= !empty($deno['verify_by']) ? 'verified' : 'pending' ?>">
                                <?= !empty($deno['verify_by']) ? 'Verified' : 'Pending' ?>
                            </span>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
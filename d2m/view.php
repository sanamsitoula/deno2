<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$d2m_id = $_GET['id'] ?? null;

if (!$d2m_id) {
    header("Location: index.php");
    exit;
}

// Fetch D2M record with related data
$stmt = $conn->prepare("
    SELECT d.*, 
           fy.fiscal_code as fiscal_year_name,
           u_created.username as created_by_name,
           u_checked.username as checked_by_name,
           u_verified.username as verified_by_name
    FROM d2m d 
    LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
    LEFT JOIN users u_created ON d.created_by = u_created.id
    LEFT JOIN users u_checked ON d.checked_by = u_checked.id
    LEFT JOIN users u_verified ON d.verified_by = u_verified.id
    WHERE d.id = :id AND d.deleted_at IS NULL
");
$stmt->execute([':id' => $d2m_id]);
$d2m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$d2m) {
    header("Location: index.php");
    exit;
}

// Fetch D2M items with book details and DENO reference numbers
$items_stmt = $conn->prepare("
    SELECT di.*, 
           b.book_name, 
           b.class_level, 
           b.is_translated,
           (
               SELECT string_agg(deno.ref_no, ', ' ORDER BY deno.ref_no)
               FROM unnest(string_to_array(di.associated_deno_ids, ',')) AS deno_id
               JOIN deno ON deno.id = deno_id::integer
           ) as ref_numbers,
           (
               SELECT COUNT(*)
               FROM unnest(string_to_array(di.associated_deno_ids, ',')) AS deno_id
               WHERE deno_id != ''
           ) as deno_count
    FROM d2m_items di
    JOIN books b ON di.book_code = b.book_code
    WHERE di.d2m_id = :d2m_id
    ORDER BY b.book_name
");
$items_stmt->execute([':d2m_id' => $d2m_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_poka_qty = array_sum(array_column($items, 'total_poka_qty'));
$total_qty = array_sum(array_column($items, 'total_qty'));
$total_open_pcs = array_sum(array_column($items, 'open_pcs'));
$net_production = $total_qty + $total_open_pcs;
?>

<style>
.view-container {
    max-width: 1400px;
    margin: 20px auto;
    background: white;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
    overflow: hidden;
}

.view-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    position: relative;
}

.view-header h2 {
    margin: 0 0 10px 0;
    font-size: 28px;
}

.view-header .d2m-number {
    font-size: 18px;
    opacity: 0.95;
}

.action-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 20px 30px;
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
}

.status-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 14px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-draft { background: #f8d7da; color: #721c24; }
.status-checked { background: #fff3cd; color: #856404; }
.status-verified { background: #d4edda; color: #155724; }
.status-cancelled { background: #d6d8db; color: #383d41; }
.status-close { background: #d1ecf1; color: #0c5460; }

.info-section {
    padding: 30px;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.info-card {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    border-left: 4px solid #007bff;
}

.info-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
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
    gap: 20px;
    margin-bottom: 30px;
}

.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
}

.summary-card .label {
    font-size: 12px;
    opacity: 0.9;
    margin-bottom: 10px;
}

.summary-card .value {
    font-size: 28px;
    font-weight: bold;
}

.items-section {
    padding: 0 30px 30px 30px;
}

.items-header {
    background: #e9ecef;
    padding: 15px;
    border-radius: 8px 8px 0 0;
    margin-bottom: 0;
}

.table-container {
    border: 1px solid #dee2e6;
    border-radius: 0 0 8px 8px;
    overflow-x: auto;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
    margin: 0;
}

.items-table th,
.items-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
}

.items-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 13px;
    color: #495057;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.items-table tbody tr:hover {
    background: #f8f9fa;
}

.items-table tbody tr:last-child td {
    border-bottom: none;
}

.total-row {
    background: #e8f4f8 !important;
    font-weight: bold;
}

.total-row td {
    border-top: 2px solid #007bff !important;
}

.book-type-badge {
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.type-t {
    background: #d4edda;
    color: #155724;
}

.type-nt {
    background: #d1ecf1;
    color: #0c5460;
}

.deno-badge {
    display: inline-block;
    padding: 2px 6px;
    background: #17a2b8;
    color: white;
    border-radius: 3px;
    font-size: 10px;
    margin-right: 5px;
}

.timeline-section {
    padding: 30px;
    background: #f8f9fa;
    border-top: 2px solid #e9ecef;
}

.timeline {
    position: relative;
    padding: 20px 0;
}

.timeline-item {
    display: flex;
    margin-bottom: 30px;
    position: relative;
}

.timeline-item:before {
    content: '';
    position: absolute;
    left: 20px;
    top: 40px;
    bottom: -30px;
    width: 2px;
    background: #dee2e6;
}

.timeline-item:last-child:before {
    display: none;
}

.timeline-icon {
    width: 40px;
    height: 40px;
    background: #007bff;
    color: white;
    border-radius: 50%;
    display: flex;
    align-items: center;
    justify-content: center;
    font-weight: bold;
    margin-right: 20px;
    z-index: 1;
}

.timeline-content {
    flex: 1;
    background: white;
    padding: 15px 20px;
    border-radius: 8px;
    border-left: 3px solid #007bff;
}

.timeline-title {
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
}

.timeline-meta {
    font-size: 12px;
    color: #6c757d;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
    margin-right: 10px;
}

.btn-primary { background: #007bff; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #212529; }

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

@media print {
    .action-bar, .btn { display: none !important; }
    .view-container { box-shadow: none; }
}
</style>

<div class="view-container">
    <div class="view-header">
        <h2>📋 D2M Record Details</h2>
        <div class="d2m-number">D2M Number: <?= htmlspecialchars($d2m['d2m_no']) ?></div>
    </div>

    <div class="action-bar">
        <div>
            <span class="status-badge status-<?= strtolower($d2m['status']) ?>">
                <?= $d2m['status'] ?>
            </span>
        </div>
        <div>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to List
            </a>
            <?php if (has_role('editor') || has_role('admin')): ?>
                <?php if ($d2m['status'] == 'DRAFT'): ?>
                    <a href="edit.php?id=<?= $d2m_id ?>" class="btn btn-warning">
                        <i class="fas fa-edit"></i> Edit
                    </a>
                <?php endif; ?>
            <?php endif; ?>
            <a href="print.php?id=<?= $d2m_id ?>" target="_blank" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Report
            </a>
            <a href="double_check.php?id=<?= $d2m_id ?>" class="btn btn-info">
                <i class="fas fa-check-double"></i> Double Check
            </a>
        </div>
    </div>

    <div class="info-section">
        <h4 style="margin-bottom: 20px; color: #333;">📄 Basic Information</h4>
        <div class="info-grid">
            <div class="info-card">
                <div class="info-label">D2M Number</div>
                <div class="info-value"><?= htmlspecialchars($d2m['d2m_no']) ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Serial Number</div>
                <div class="info-value"><?= $d2m['serial_no'] ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Type</div>
                <div class="info-value">
                    <?= $d2m['d2m_type'] == 'T' ? 'Translated (अनुवादित)' : 'Non-Translated (गैर-अनुवादित)' ?>
                </div>
            </div>
            <div class="info-card">
                <div class="info-label">Fiscal Year</div>
                <div class="info-value"><?= htmlspecialchars($d2m['fiscal_year_name']) ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">Nepali Date</div>
                <div class="info-value"><?= htmlspecialchars($d2m['nep_date']) ?></div>
            </div>
            <div class="info-card">
                <div class="info-label">English Date</div>
                <div class="info-value"><?= date('F d, Y', strtotime($d2m['eng_date'])) ?></div>
            </div>
        </div>

        <?php if ($d2m['remarks']): ?>
        <div class="info-card" style="margin-bottom: 30px;">
            <div class="info-label">Remarks</div>
            <div class="info-value"><?= nl2br(htmlspecialchars($d2m['remarks'])) ?></div>
        </div>
        <?php endif; ?>

        <h4 style="margin-bottom: 20px; color: #333;">📊 Summary Statistics</h4>
        <div class="summary-cards">
            <div class="summary-card" style="background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);">
                <div class="label">Total Items</div>
                <div class="value"><?= count($items) ?></div>
            </div>
            <div class="summary-card" style="background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);">
                <div class="label">Total Poka</div>
                <div class="value"><?= number_format($total_poka_qty) ?></div>
            </div>
            <div class="summary-card" style="background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);">
                <div class="label">Total Books</div>
                <div class="value"><?= number_format($total_qty) ?></div>
            </div>
            <div class="summary-card" style="background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);">
                <div class="label">Open Pieces</div>
                <div class="value"><?= number_format($total_open_pcs) ?></div>
            </div>
            <div class="summary-card" style="background: linear-gradient(135deg, #fa709a 0%, #fee140 100%);">
                <div class="label">Net Production</div>
                <div class="value"><?= number_format($net_production) ?></div>
            </div>
        </div>
    </div>

    <div class="items-section">
        <div class="items-header">
            <h4 style="margin: 0; color: #333;">📚 D2M Items (<?= count($items) ?> items)</h4>
        </div>
        <div class="table-container">
            <table class="items-table">
                <thead>
                    <tr>
                        <th style="width: 4%;">S.N.</th>
                        <th style="width: 25%;">Book Name</th>
                        <th style="width: 8%;">Class</th>
                        <th style="width: 8%;">Type</th>
                        <th style="width: 10%;">Book Code</th>
                        <th style="width: 8%;">Per Poka</th>
                        <th style="width: 8%;">Poka Qty</th>
                        <th style="width: 8%;">Total Qty</th>
                        <th style="width: 8%;">Open Pcs</th>
                        <th style="width: 8%;">DENO Count</th>
                        <th>Reference Numbers</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sn = 1;
                    foreach ($items as $item): 
                    ?>
                    <tr>
                        <td><?= $sn++ ?></td>
                        <td><strong><?= htmlspecialchars($item['book_name']) ?></strong></td>
                        <td><?= htmlspecialchars($item['class_level']) ?></td>
                        <td>
                            <span class="book-type-badge type-<?= $item['is_translated'] ? 't' : 'nt' ?>">
                                <?= $item['is_translated'] ? 'Translated' : 'Non-Translated' ?>
                            </span>
                        </td>
                        <td><?= htmlspecialchars($item['book_code']) ?></td>
                        <td><?= number_format($item['per_poka_qty']) ?></td>
                        <td><?= number_format($item['total_poka_qty']) ?></td>
                        <td><strong><?= number_format($item['total_qty']) ?></strong></td>
                        <td><?= number_format($item['open_pcs']) ?></td>
                        <td>
                            <span class="deno-badge"><?= $item['deno_count'] ?> DENO(s)</span>
                        </td>
                        <td style="font-size: 12px;">
                            <?= htmlspecialchars($item['ref_numbers'] ?? 'N/A') ?>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    
                    <tr class="total-row">
                        <td colspan="6"><strong>GRAND TOTAL</strong></td>
                        <td><strong><?= number_format($total_poka_qty) ?></strong></td>
                        <td><strong><?= number_format($total_qty) ?></strong></td>
                        <td><strong><?= number_format($total_open_pcs) ?></strong></td>
                        <td colspan="2"></td>
                    </tr>
                </tbody>
            </table>
        </div>
    </div>

    <div class="timeline-section">
        <h4 style="margin-bottom: 20px; color: #333;">⏱️ Status Timeline</h4>
        <div class="timeline">
            <div class="timeline-item">
                <div class="timeline-icon">✓</div>
                <div class="timeline-content">
                    <div class="timeline-title">Created</div>
                    <div class="timeline-meta">
                        By: <strong><?= htmlspecialchars($d2m['created_by_name']) ?></strong><br>
                        On: <?= date('F d, Y g:i A', strtotime($d2m['created_at'])) ?>
                    </div>
                </div>
            </div>

            <?php if ($d2m['checked_by_name']): ?>
            <div class="timeline-item">
                <div class="timeline-icon" style="background: #ffc107;">✓</div>
                <div class="timeline-content" style="border-left-color: #ffc107;">
                    <div class="timeline-title">Checked</div>
                    <div class="timeline-meta">
                        By: <strong><?= htmlspecialchars($d2m['checked_by_name']) ?></strong><br>
                        On: <?= $d2m['checked_at'] ? date('F d, Y g:i A', strtotime($d2m['checked_at'])) : 'N/A' ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($d2m['verified_by_name']): ?>
            <div class="timeline-item">
                <div class="timeline-icon" style="background: #28a745;">✓</div>
                <div class="timeline-content" style="border-left-color: #28a745;">
                    <div class="timeline-title">Verified</div>
                    <div class="timeline-meta">
                        By: <strong><?= htmlspecialchars($d2m['verified_by_name']) ?></strong><br>
                        On: <?= $d2m['verified_at'] ? date('F d, Y g:i A', strtotime($d2m['verified_at'])) : 'N/A' ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>

            <?php if ($d2m['status'] == 'CLOSE'): ?>
            <div class="timeline-item">
                <div class="timeline-icon" style="background: #17a2b8;">🔒</div>
                <div class="timeline-content" style="border-left-color: #17a2b8;">
                    <div class="timeline-title">Closed</div>
                    <div class="timeline-meta">
                        On: <?= $d2m['updated_at'] ? date('F d, Y g:i A', strtotime($d2m['updated_at'])) : 'N/A' ?>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
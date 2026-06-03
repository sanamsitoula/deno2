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

$jt_id = $_GET['jt_id'] ?? null;
if (!$jt_id) {
    header('Location: production_report.php');
    exit();
}

// Get job ticket details
$jt_sql = "
SELECT 
    jt.*,
    b.book_code,
    b.book_name,
    b.class_level,
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
    die("Job ticket not found");
}

// Get all formas with their printing details
$formas_sql = "
SELECT 
    jtd.id as jtd_id,
    jtd.order_no,
    jtd.forma_id,
    jtd.page,
    jtd.print_qty as jtd_target,
    f.name as forma_name,
    
    (SELECT SUM(fp.fp_printqty) 
     FROM forma_printing fp 
     WHERE fp.jtd_id = jtd.id AND fp.status = true
    ) as total_printed,
    
    (SELECT COUNT(*) 
     FROM forma_printing fp 
     WHERE fp.jtd_id = jtd.id AND fp.status = true
    ) as print_count,
    
    (SELECT MIN(fp.created_date) 
     FROM forma_printing fp 
     WHERE fp.jtd_id = jtd.id AND fp.status = true
    ) as first_print_date,
    
    (SELECT MAX(fp.created_date) 
     FROM forma_printing fp 
     WHERE fp.jtd_id = jtd.id AND fp.status = true
    ) as last_print_date
    
FROM job_ticket_details jtd
LEFT JOIN forma f ON jtd.forma_id = f.id
WHERE jtd.job_ticket_id = :jt_id
ORDER BY jtd.order_no ASC
";

$stmt = $conn->prepare($formas_sql);
$stmt->execute([':jt_id' => $jt_id]);
$formas = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate progress
foreach ($formas as &$forma) {
    $forma['total_printed'] = (int)($forma['total_printed'] ?? 0);
    $forma['remaining'] = $forma['jtd_target'] - $forma['total_printed'];
    $forma['completion_pct'] = $forma['jtd_target'] > 0 
        ? round(($forma['total_printed'] / $forma['jtd_target']) * 100, 2)
        : 0;
    $forma['is_completed'] = $forma['completion_pct'] >= 100;
    $forma['is_overprinted'] = $forma['total_printed'] > $forma['jtd_target'];
    
    if ($forma['first_print_date'] && $forma['last_print_date']) {
        $start = new DateTime($forma['first_print_date']);
        $end = new DateTime($forma['last_print_date']);
        $forma['duration_days'] = $start->diff($end)->days;
    } else {
        $forma['duration_days'] = 0;
    }
}
unset($forma);

// Get forma printing history
$fp_history_sql = "
SELECT 
    fp.*,
    u_created.username as created_by_name,
    u_operator.username as operator_name,
    u_supervisor.username as supervisor_name,
    u_incharge.username as incharge_name,
    m.machine_name,
    s.name as shift_name,
    f.name as forma_name
FROM forma_printing fp
LEFT JOIN users u_created ON fp.created_by = u_created.id
LEFT JOIN users u_operator ON fp.operator_id = u_operator.id
LEFT JOIN users u_supervisor ON fp.supervisor_id = u_supervisor.id
LEFT JOIN users u_incharge ON fp.incharge_id = u_incharge.id
LEFT JOIN machines m ON fp.machine_id = m.id
LEFT JOIN shifts s ON fp.shift_id = s.id
LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
LEFT JOIN forma f ON jtd.forma_id = f.id
WHERE fp.jt_id = :jt_id AND fp.status = true
ORDER BY fp.created_date DESC
";

$stmt = $conn->prepare($fp_history_sql);
$stmt->execute([':jt_id' => $jt_id]);
$fp_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get book packing history
$bp_history_sql = "
SELECT 
    bp.*,
    u_created.username as created_by_name,
    u_operator.username as operator_name,
    u_supervisor.username as supervisor_name,
    u_incharge.username as incharge_name
FROM book_packing bp
LEFT JOIN users u_created ON bp.created_by = u_created.id
LEFT JOIN users u_operator ON bp.operator_id = u_operator.id
LEFT JOIN users u_supervisor ON bp.supervisor_id = u_supervisor.id
LEFT JOIN users u_incharge ON bp.incharge_id = u_incharge.id
WHERE bp.jt_id = :jt_id AND bp.status = true
ORDER BY bp.created_date DESC
";

$stmt = $conn->prepare($bp_history_sql);
$stmt->execute([':jt_id' => $jt_id]);
$bp_history = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate statistics
$total_formas = count($formas);
$completed_formas = count(array_filter($formas, fn($f) => $f['is_completed']));
$pending_formas = $total_formas - $completed_formas;

$total_forma_target = array_sum(array_column($formas, 'jtd_target'));
$total_printed = array_sum(array_column($formas, 'total_printed'));
$printing_completion = $total_forma_target > 0 ? round(($total_printed / $total_forma_target) * 100, 2) : 0;

$total_packed = array_sum(array_column($bp_history, 'p_qty'));
$packing_completion = $job_ticket['print_qty'] > 0 
    ? round(($total_packed / $job_ticket['print_qty']) * 100, 2) 
    : 0;

// Performance metrics by operator
$operator_performance = [];
foreach ($fp_history as $fp) {
    $op_name = $fp['operator_name'] ?? 'Unknown';
    if (!isset($operator_performance[$op_name])) {
        $operator_performance[$op_name] = [
            'name' => $op_name,
            'records' => 0,
            'total_qty' => 0,
            'shifts' => []
        ];
    }
    $operator_performance[$op_name]['records']++;
    $operator_performance[$op_name]['total_qty'] += $fp['fp_printqty'];
    if ($fp['shift_name']) {
        $operator_performance[$op_name]['shifts'][$fp['shift_name']] = 
            ($operator_performance[$op_name]['shifts'][$fp['shift_name']] ?? 0) + 1;
    }
}

// Machine utilization
$machine_utilization = [];
foreach ($fp_history as $fp) {
    $machine = $fp['machine_name'] ?? 'Unknown';
    if (!isset($machine_utilization[$machine])) {
        $machine_utilization[$machine] = [
            'name' => $machine,
            'records' => 0,
            'total_qty' => 0
        ];
    }
    $machine_utilization[$machine]['records']++;
    $machine_utilization[$machine]['total_qty'] += $fp['fp_printqty'];
}
?>

<style>
/* Previous styles remain the same, adding new detail-specific styles */

.detail-container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.back-button {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    color: #007bff;
    text-decoration: none;
    margin-bottom: 20px;
    font-weight: 600;
}

.back-button:hover {
    color: #0056b3;
}

.job-header {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.job-title {
    font-size: 24px;
    font-weight: 700;
    color: #333;
    margin-bottom: 20px;
}

.job-info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.info-box {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
    border-left: 3px solid #007bff;
}

.info-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    font-weight: 600;
    margin-bottom: 5px;
}

.info-value {
    font-size: 18px;
    font-weight: 700;
    color: #333;
}

.section {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.section-header {
    font-size: 20px;
    font-weight: 700;
    color: #333;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
    display: flex;
    align-items: center;
    gap: 10px;
}

.bottleneck-badge {
    background: #dc3545;
    color: white;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    animation: pulse 2s infinite;
}

@keyframes pulse {
    0%, 100% { opacity: 1; }
    50% { opacity: 0.6; }
}

.performance-card {
    background: #f8f9fa;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
}

.performance-name {
    font-weight: 700;
    color: #333;
    margin-bottom: 8px;
}

.performance-stats {
    display: flex;
    gap: 20px;
    font-size: 14px;
    color: #6c757d;
}

.history-table {
    width: 100%;
    font-size: 13px;
}

.history-table th {
    background: #f8f9fa;
    padding: 10px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
}

.history-table td {
    padding: 10px;
    border-bottom: 1px solid #f0f0f0;
}

.history-table tr:hover {
    background: #f8f9fa;
}
</style>

<div class="detail-container">
    <a href="production_report.php" class="back-button">
        ← Back to Report
    </a>

    <!-- Job Ticket Header -->
    <div class="job-header">
        <div class="job-title">
            <?= htmlspecialchars($job_ticket['job_ticket_code']) ?> - 
            <?= htmlspecialchars($job_ticket['book_name']) ?>
            <span class="status-badge status-<?= $job_ticket['status'] ?>">
                <?= ucfirst(str_replace('_', ' ', $job_ticket['status'])) ?>
            </span>
        </div>
        
        <div class="job-info-grid">
            <div class="info-box">
                <div class="info-label">Book Code</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['book_code']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Class Level</div>
                <div class="info-value"><?= $job_ticket['class'] ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Lot Number</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['lot']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Print Quantity</div>
                <div class="info-value"><?= number_format($job_ticket['print_qty']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Page Quantity</div>
                <div class="info-value"><?= number_format($job_ticket['page_qty']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Fiscal Year</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['fiscal_code']) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Created Date</div>
                <div class="info-value"><?= date('Y-m-d', strtotime($job_ticket['created_date'])) ?></div>
            </div>
            <div class="info-box">
                <div class="info-label">Created By</div>
                <div class="info-value"><?= htmlspecialchars($job_ticket['created_by_name']) ?></div>
            </div>
        </div>
    </div>

    <!-- Progress Overview -->
    <div class="section">
        <div class="section-header">
            📊 Progress Overview
        </div>
        <div class="summary-cards" style="grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));">
            <div class="summary-card">
                <div class="summary-card-value"><?= $completed_formas ?> / <?= $total_formas ?></div>
                <div class="summary-card-label">Formas Completed</div>
            </div>
            <div class="summary-card">
                <div class="summary-card-value"><?= $printing_completion ?>%</div>
                <div class="summary-card-label">Printing Progress</div>
            </div>
            <div class="summary-card">
                <div class="summary-card-value"><?= $packing_completion ?>%</div>
                <div class="summary-card-label">Packing Progress</div>
            </div>
            <div class="summary-card">
                <div class="summary-card-value"><?= number_format($total_printed) ?></div>
                <div class="summary-card-label">Total Printed</div>
            </div>
            <div class="summary-card">
                <div class="summary-card-value"><?= number_format($total_packed) ?></div>
                <div class="summary-card-label">Total Packed</div>
            </div>
        </div>
    </div>

    <!-- Forma Status -->
    <div class="section">
        <div class="section-header">
            📄 Forma Status & Bottlenecks
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Forma Name</th>
                    <th>Pages</th>
                    <th>Target Qty</th>
                    <th>Printed</th>
                    <th>Remaining</th>
                    <th>Progress</th>
                    <th>Print Count</th>
                    <th>Duration</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($formas as $forma): 
                    $progress_class = $forma['completion_pct'] < 30 ? 'low' : 
                                    ($forma['completion_pct'] < 70 ? 'medium' : 'high');
                    $is_bottleneck = !$forma['is_completed'] && $forma['completion_pct'] < 50;
                ?>
                <tr>
                    <td><?= $forma['order_no'] ?></td>
                    <td>
                        <strong><?= htmlspecialchars($forma['forma_name']) ?></strong>
                        <?php if ($is_bottleneck): ?>
                            <span class="bottleneck-badge">⚠️ BOTTLENECK</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $forma['page'] ?></td>
                    <td><?= number_format($forma['jtd_target']) ?></td>
                    <td>
                        <?= number_format($forma['total_printed']) ?>
                        <?php if ($forma['is_overprinted']): ?>
                            <span class="alert-warning" style="display: inline-block; margin-left: 5px;">
                                +<?= number_format($forma['total_printed'] - $forma['jtd_target']) ?>
                            </span>
                        <?php endif; ?>
                    </td>
                    <td><?= number_format(max(0, $forma['remaining'])) ?></td>
                    <td>
                        <div class="progress-container">
                            <div class="progress-bar <?= $progress_class ?>" 
                                 style="width: <?= min(100, $forma['completion_pct']) ?>%"></div>
                            <div class="progress-text"><?= $forma['completion_pct'] ?>%</div>
                        </div>
                    </td>
                    <td><?= $forma['print_count'] ?> runs</td>
                    <td><?= $forma['duration_days'] ?> days</td>
                    <td>
                        <?php if ($forma['is_completed']): ?>
                            <span class="status-badge status-completed">✓ Complete</span>
                        <?php elseif ($forma['total_printed'] > 0): ?>
                            <span class="status-badge status-processing">⏳ In Progress</span>
                        <?php else: ?>
                            <span class="status-badge status-pending">○ Not Started</span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Operator Performance -->
    <div class="section">
        <div class="section-header">
            👥 Operator Performance
        </div>
        <?php foreach ($operator_performance as $op): ?>
        <div class="performance-card">
            <div class="performance-name"><?= htmlspecialchars($op['name']) ?></div>
            <div class="performance-stats">
                <span><strong>Records:</strong> <?= $op['records'] ?></span>
                <span><strong>Total Printed:</strong> <?= number_format($op['total_qty']) ?></span>
                <span><strong>Avg per Run:</strong> <?= number_format($op['total_qty'] / $op['records']) ?></span>
                <span><strong>Shifts:</strong> <?= implode(', ', array_keys($op['shifts'])) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Machine Utilization -->
    <div class="section">
        <div class="section-header">
            🖨️ Machine Utilization
        </div>
        <?php foreach ($machine_utilization as $machine): ?>
        <div class="performance-card">
            <div class="performance-name"><?= htmlspecialchars($machine['name']) ?></div>
            <div class="performance-stats">
                <span><strong>Print Jobs:</strong> <?= $machine['records'] ?></span>
                <span><strong>Total Output:</strong> <?= number_format($machine['total_qty']) ?></span>
                <span><strong>Avg per Job:</strong> <?= number_format($machine['total_qty'] / $machine['records']) ?></span>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Forma Printing History -->
    <div class="section">
        <div class="section-header">
            🖨️ Forma Printing History (<?= count($fp_history) ?> records)
        </div>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Forma</th>
                    <th>Quantity</th>
                    <th>Machine</th>
                    <th>Shift</th>
                    <th>Operator</th>
                    <th>Supervisor</th>
                    <th>Incharge</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fp_history as $fp): ?>
                <tr>
                    <td><?= htmlspecialchars($fp['date_eng']) ?></td>
                    <td><?= htmlspecialchars($fp['forma_name']) ?></td>
                    <td><strong><?= number_format($fp['fp_printqty']) ?></strong></td>
                    <td><?= htmlspecialchars($fp['machine_name']) ?></td>
                    <td><?= htmlspecialchars($fp['shift_name']) ?></td>
                    <td><?= htmlspecialchars($fp['operator_name']) ?></td>
                    <td><?= htmlspecialchars($fp['supervisor_name']) ?></td>
                    <td><?= htmlspecialchars($fp['incharge_name']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>

    <!-- Book Packing History -->
    <div class="section">
        <div class="section-header">
            📦 Book Packing History (<?= count($bp_history) ?> records)
        </div>
        <table class="history-table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Name</th>
                    <th>Packed Qty</th>
                    <th>Status</th>
                    <th>Operator</th>
                    <th>Supervisor</th>
                    <th>Incharge</th>
                    <th>Created By</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bp_history as $bp): ?>
                <tr>
                    <td><?= htmlspecialchars($bp['date_eng']) ?></td>
                    <td><?= htmlspecialchars($bp['name']) ?></td>
                    <td><strong><?= number_format($bp['p_qty']) ?></strong></td>
                    <td>
                        <span class="status-badge status-<?= $bp['packing_status'] ?>">
                            <?= ucfirst($bp['packing_status']) ?>
                        </span>
                    </td>
                    <td><?= htmlspecialchars($bp['operator_name']) ?></td>
                    <td><?= htmlspecialchars($bp['supervisor_name']) ?></td>
                    <td><?= htmlspecialchars($bp['incharge_name']) ?></td>
                    <td><?= htmlspecialchars($bp['created_by_name']) ?></td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$id = $_GET['id'] ?? 0;

// Get forma printing record
$stmt = $conn->prepare("
    SELECT fp.*, 
           jt.job_ticket_code,
           jtd.order_no as jtd_order_no,
           jtd.page as jtd_page,
           m.machine_name,
           fy.fiscal_code,
           s.name as shift_name,
           creator.username as created_by_name,
           supervisor.username as supervisor_name,
           operator.username as operator_name,
           incharge.username as incharge_name
    FROM forma_printing fp
    LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
    LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
    LEFT JOIN machines m ON fp.machine_id = m.id
    LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
    LEFT JOIN shifts s ON fp.shift_id = s.id
    LEFT JOIN users creator ON fp.created_by = creator.id
    LEFT JOIN users supervisor ON fp.supervisor_id = supervisor.id
    LEFT JOIN users operator ON fp.operator_id = operator.id
    LEFT JOIN users incharge ON fp.incharge_id = incharge.id
    WHERE fp.id = :id
");
$stmt->execute([':id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    $_SESSION['error_message'] = "Forma Printing record not found!";
    header('Location: forma_printing_list.php');
    exit();
}

// Get audit logs for this record
$logs = $conn->prepare("
    SELECT al.*, u.username as changed_by_name
    FROM audit_log al
    LEFT JOIN users u ON al.changed_by = u.id
    WHERE al.table_name = 'forma_printing' AND al.record_id = :id
    ORDER BY al.changed_at DESC
");
$logs->execute([':id' => $id]);
$audit_logs = $logs->fetchAll(PDO::FETCH_ASSOC);
?>

<div class="container">
    <h2>👁️ View Forma Printing Record</h2>
    
    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success_message'] ?></div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>
    
    <div class="table-container">
        <table class="table">
            <tbody>
                <tr>
                    <th>ID</th>
                    <td><?= $record['id'] ?></td>
                    <th>Name</th>
                    <td><?= htmlspecialchars($record['name']) ?></td>
                </tr>
                <tr>
                    <th>Nepali Date</th>
                    <td><?= $record['date_nep'] ?></td>
                    <th>English Date</th>
                    <td><?= $record['date_eng'] ?></td>
                </tr>
                <tr>
                    <th>Fiscal Year</th>
                    <td><?= $record['fiscal_code'] ?></td>
                    <th>Job Ticket</th>
                    <td><?= $record['job_ticket_code'] ?></td>
                </tr>
                <tr>
                    <th>Forma Details</th>
                    <td>Order #<?= $record['jtd_order_no'] ?>, <?= $record['jtd_page'] ?> pages</td>
                    <th>Machine</th>
                    <td><?= $record['machine_name'] ?></td>
                </tr>
                <tr>
                    <th>Target Qty</th>
                    <td><?= number_format($record['jtd_targetqty']) ?></td>
                    <th>Printed Qty</th>
                    <td><?= number_format($record['fp_printqty']) ?></td>
                </tr>
                <tr>
                    <th>Remaining Qty</th>
                    <td><?= number_format($record['fp_remainqty']) ?></td>
                    <th>Status</th>
                    <td><?= $record['status'] ? 'Active' : 'Inactive' ?></td>
                </tr>
                <tr>
                    <th>Supervisor</th>
                    <td><?= $record['supervisor_name'] ?></td>
                    <th>Operator</th>
                    <td><?= $record['operator_name'] ?></td>
                </tr>
                <tr>
                    <th>Incharge</th>
                    <td><?= $record['incharge_name'] ?></td>
                    <th>Shift</th>
                    <td><?= $record['shift_name'] ?></td>
                </tr>
                <tr>
                    <th>Created By</th>
                    <td><?= $record['created_by_name'] ?></td>
                    <th>Created Date</th>
                    <td><?= date('Y-m-d H:i', strtotime($record['created_date'])) ?></td>
                </tr>
                <tr>
                    <th>Remarks</th>
                    <td colspan="3"><?= htmlspecialchars($record['remarks']) ?></td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td colspan="3"><?= htmlspecialchars($record['description']) ?></td>
                </tr>
            </tbody>
        </table>
    </div>
    
    <h3>Audit Log</h3>
    <div class="table-container">
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Action</th>
                    <th>Changed By</th>
                    <th>Field</th>
                    <th>Old Value</th>
                    <th>New Value</th>
                </tr>
            </thead>
            <tbody>
                <?php if (count($audit_logs) > 0): ?>
                    <?php foreach ($audit_logs as $log): ?>
                    <tr>
                        <td><?= date('Y-m-d H:i', strtotime($log['changed_at'])) ?></td>
                        <td><?= ucfirst($log['action']) ?></td>
                        <td><?= $log['changed_by_name'] ?></td>
                        <td><?= $log['field_name'] ?? 'N/A' ?></td>
                        <td><?= htmlspecialchars($log['old_value'] ?? '') ?></td>
                        <td><?= htmlspecialchars($log['new_value'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td colspan="6" class="text-center">No audit logs found</td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
    
    <div class="action-buttons">
        <a href="index.php" class="btn btn-secondary">Back to List</a>
        <?php if (has_role('editor') || has_role('admin')): ?>
            <a href="forma_printing_edit.php?id=<?= $record['id'] ?>" class="btn btn-warning">Edit</a>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
// jobticket/index.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php'; // Include shared functions
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$tickets = $conn->query("
    SELECT j.id, j.job_ticket_code, j.lot, j.print_qty, j.status,
           j.created_date, b.book_name, b.book_code, u.username as created_by,
           fy.fiscal_code
    FROM job_ticket j
    JOIN books b ON j.book_id = b.book_id
    JOIN users u ON j.created_by = u.id
    JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
    ORDER BY j.created_date DESC
")->fetchAll();
?>

<div class="container">
    <div class="d-flex justify-content-between align-items-center mb-4">
        <h2>Job Tickets</h2>
        <?php if (has_role('editor') || has_role('admin')): ?>
            <a href="create.php" class="btn btn-primary">Create New</a>
        <?php endif; ?>
    </div>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <?php if (isset($_SESSION['error'])): ?>
        <div class="alert alert-danger"><?= $_SESSION['error'] ?></div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>
    <div class="card">
        <div class="card-body">
            <table class="table table-striped table-hover" id="ticketsTable">
                <thead>
                    <tr>
                        <th>Job Ticket Code</th>
                        <th>Book Code</th>
                        <th>Book Name</th>
                        <th>Fiscal Year</th>
                        <th>Lot No</th>
                        <th>Print Qty</th>
                        <th>Status</th>
                        <th>Created By</th>
                        <th>Created Date</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($tickets as $ticket): ?>
                        <tr>
                            <td><?= htmlspecialchars($ticket['job_ticket_code']) ?></td>
                            <td><?= htmlspecialchars($ticket['book_code']) ?></td>
                            <td><?= htmlspecialchars($ticket['book_name']) ?></td>
                            <td><?= htmlspecialchars($ticket['fiscal_code']) ?></td> <!-- Assuming fiscal_year is fetched correctly -->
                            <td><?= htmlspecialchars($ticket['lot']) ?></td>
                            <td><?= number_format($ticket['print_qty']) ?></td>
                            <td>
                                <span class="badge bg-<?= getStatusBadge($ticket['status']) ?>">
                                    <?= ucfirst(str_replace('_', ' ', $ticket['status'])) ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($ticket['created_by']) ?></td>
                            <td><?= $ticket['created_date'] ?></td>
                            <td>
                                <a href="view.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-info">View</a>
                                <a href="print.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-success" target="_blank">Print</a>
                                <?php if (has_role('editor') || has_role('admin')): ?>
                                    <a href="edit.php?id=<?= $ticket['id'] ?>" class="btn btn-sm btn-warning">Edit</a>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
<script>
$(document).ready(function() {
    $('#ticketsTable').DataTable({
        responsive: true,
        order: [[8, 'desc']], // Adjust index if column order changes
        columnDefs: [
            {
                targets: [1, 2], // Book Code and Book Name columns
                searchable: true
            }
        ]
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
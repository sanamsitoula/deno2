<?php
// jobticket/view.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php'; // Include shared functions
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!isset($_GET['id'])) {
    redirectWithError("Invalid request.", 'index.php');
}
$id = intval($_GET['id']);

// Use the helper function to get ticket and details
list($ticket, $details) = getJobTicketWithDetails($conn, $id);

if (!$ticket) {
    redirectWithError("Job Ticket not found!", 'index.php');
}

?>

<div class="container">
    <h2>View Job Ticket: <?= htmlspecialchars($ticket['job_ticket_code']) ?></h2>
    <?php if (isset($_SESSION['success'])): ?>
        <div class="alert alert-success"><?= $_SESSION['success'] ?></div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>
    <div class="card mb-4">
        <div class="card-header">
            <div class="d-flex justify-content-between">
                <h4>Basic Information</h4>
                <div>
                    <a href="print.php?id=<?= $id ?>" class="btn btn-success btn-sm" target="_blank">Print</a>
                    <?php if (has_role('editor') || has_role('admin')): ?>
                        <a href="edit.php?id=<?= $id ?>" class="btn btn-primary btn-sm">Edit</a>
                        <a href="delete.php?id=<?= $id ?>" class="btn btn-danger btn-sm" onclick="return confirm('Are you sure you want to delete this Job Ticket? This action cannot be undone.')">Delete</a>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        <div class="card-body">
            <div class="row">
                <div class="col-md-6">
                    <p><strong>Book Code:</strong> <?= htmlspecialchars($ticket['book_code']) ?></p>
                    <p><strong>Book Name:</strong> <?= htmlspecialchars($ticket['book_name']) ?></p>
                    <p><strong>Job Ticket Code:</strong> <?= htmlspecialchars($ticket['job_ticket_code']) ?></p>
                    <p><strong>Fiscal Year:</strong> <?= htmlspecialchars($ticket['fiscal_code']) ?></p> <!-- Assuming fiscal_year is fetched correctly -->
                    <p><strong>Lot No:</strong> <?= htmlspecialchars($ticket['lot']) ?></p>
                </div>
                <div class="col-md-6">
                    <p><strong>Print Qty:</strong> <?= number_format($ticket['print_qty']) ?></p>
                    <p><strong>Page Qty:</strong> <?= number_format($ticket['page_qty']) ?></p>
                    <p><strong>Class:</strong> <?= htmlspecialchars($ticket['class']) ?></p>
                    <p><strong>Date (Nep):</strong> <?= htmlspecialchars($ticket['date_nep']) ?></p>
                    <p><strong>Date (Eng):</strong> <?= htmlspecialchars($ticket['date_eng']) ?></p>
                </div>
            </div>
            <?php if ($ticket['remarks']): ?>
                <p><strong>Remarks:</strong> <?= htmlspecialchars($ticket['remarks']) ?></p>
            <?php endif; ?>
            <?php if ($ticket['description']): ?>
                <p><strong>Description:</strong> <?= htmlspecialchars($ticket['description']) ?></p>
            <?php endif; ?>
        </div>
        <div class="card-footer">
            <small class="text-muted">
                Created by <?= htmlspecialchars($ticket['created_by_name']) ?> on <?= $ticket['created_date'] ?>
                <?php if ($ticket['updated_by']): ?>
                    | Last updated by <?= htmlspecialchars($ticket['updated_by']) ?> on <?= $ticket['updated_date'] ?>
                <?php endif; ?>
            </small>
        </div>
    </div>
    <div class="card">
        <div class="card-header">
            <h4>Forma Details</h4>
        </div>
        <div class="card-body">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th>Order No</th>
                        <th>Forma</th>
                        <th>Page</th>
                        <th>Old Forma Qty</th>
                        <th>Print Qty</th>
                        <th>Machine</th>
                        <th>Description</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($details as $detail): ?>
                        <tr>
                            <td><?= $detail['order_no'] ?></td>
                            <td><?= htmlspecialchars($detail['forma_name']) ?></td>
                            <td><?= $detail['page'] ?></td>
                            <td><?= number_format($detail['old_forma_qty']) ?></td>
                            <td><?= number_format($detail['print_qty']) ?></td>
                            <td><?= htmlspecialchars($detail['machine']) ?></td>
                            <td><?= htmlspecialchars($detail['description']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
    <a href="index.php" class="btn btn-secondary mt-3">Back to List</a>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
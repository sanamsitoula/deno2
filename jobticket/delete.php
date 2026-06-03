<?php
// jobticket/delete.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

if (!has_role('admin')) { // Assuming only admins can delete
    redirectWithError("You don't have permission to perform this action.", 'index.php');
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php'; // Include shared functions

if (!isset($_GET['id'])) {
    redirectWithError("Invalid request.", 'index.php');
}
$id = intval($_GET['id']);

try {
    $conn->beginTransaction();
    // Delete details first
    $conn->prepare("DELETE FROM job_ticket_details WHERE job_ticket_id = ?")->execute([$id]);
    // Then delete the ticket
    $conn->prepare("DELETE FROM job_ticket WHERE id = ?")->execute([$id]);
    $conn->commit();
    redirectWithSuccess("Job Ticket deleted successfully!", 'index.php');
} catch (Exception $e) {
    $conn->rollBack();
    redirectWithError("Error deleting job ticket: " . $e->getMessage(), 'index.php');
}
// Redirect handled in functions above
?>
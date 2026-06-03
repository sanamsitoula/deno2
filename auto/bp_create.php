<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
// Include the status management functions
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';

// Check permissions
if (!has_role('incharge') && !has_role('supervisor') && !has_role('operator') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$error_message = null;
$success_message = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        // Validate required fields
        $required_fields = ['name', 'jt_id', 'jt_print_qty', 'p_qty', 'book_code', 'date_nep', 'date_eng',
            'supervisor_id', 'incharge_id', 'operator_id', 'fiscal_year_id'];

        foreach ($required_fields as $field) {
            if (empty($_POST[$field])) {
                throw new Exception("Field '{$field}' is required");
            }
        }

        $jt_id = (int)$_POST['jt_id'];
        $p_qty = (int)$_POST['p_qty'];
        $jt_print_qty = (int)$_POST['jt_print_qty'];

        // ========================================
        // VALIDATE: Check if ALL formas are 100% printed
        // ========================================
        if (!can_start_book_packing($conn, $jt_id)) {
            throw new Exception("Cannot start book packing! All formas must be 100% printed before packing can begin. Please complete all forma printing first.");
        }

        // Fetch total already packed for this job ticket
        $stmt = $conn->prepare("
            SELECT COALESCE(SUM(p_qty), 0) as total_packed 
            FROM book_packing 
            WHERE jt_id = :jt_id AND status = true
        ");
        $stmt->execute([':jt_id' => $jt_id]);
        $total_packed = (int)$stmt->fetch()['total_packed'];

        // Validate: new p_qty + total_packed <= jt_print_qty
        if ($p_qty + $total_packed > $jt_print_qty) {
            throw new Exception("Total packed quantity ({$total_packed} + {$p_qty}) exceeds print quantity ({$jt_print_qty}).");
        }

        // Insert packing record
        $insert_sql = "
            INSERT INTO book_packing (
                name, jt_id, jt_print_qty, p_qty, book_code, date_nep, date_eng,
                supervisor_id, incharge_id, operator_id, status, packing_status,
                created_by, fiscal_year_id, remarks, description, created_date
            ) VALUES (
                :name, :jt_id, :jt_print_qty, :p_qty, :book_code, :date_nep, :date_eng,
                :supervisor_id, :incharge_id, :operator_id, true, :packing_status,
                :created_by, :fiscal_year_id, :remarks, :description, NOW()
            )
        ";

        $stmt = $conn->prepare($insert_sql);
        $stmt->execute([
            ':name' => $_POST['name'],
            ':jt_id' => $jt_id,
            ':jt_print_qty' => $jt_print_qty,
            ':p_qty' => $p_qty,
            ':book_code' => $_POST['book_code'],
            ':date_nep' => $_POST['date_nep'],
            ':date_eng' => $_POST['date_eng'],
            ':supervisor_id' => $_POST['supervisor_id'],
            ':incharge_id' => $_POST['incharge_id'],
            ':operator_id' => $_POST['operator_id'],
            ':packing_status' => $_POST['packing_status'] ?? 'active',
            ':created_by' => $_SESSION['user_id'],
            ':fiscal_year_id' => $_POST['fiscal_year_id'],
            ':remarks' => $_POST['remarks'] ?? null,
            ':description' => $_POST['description'] ?? null
        ]);

        $packing_id = $conn->lastInsertId();

        // ========================================
        // AUTOMATIC STATUS UPDATE
        // ========================================
        $status_update = update_job_ticket_status($conn, $jt_id);
        
        if ($status_update['success']) {
            error_log("Status Update Result: " . print_r($status_update, true));
            
            // Add status change message to session if status changed
            if ($status_update['old_status'] !== $status_update['new_status']) {
                $_SESSION['status_change_message'] = 
                    "Job Ticket status automatically updated: " . 
                    strtoupper($status_update['old_status']) . " → " . 
                    strtoupper($status_update['new_status']);
            }
        } else {
            error_log("Status Update Failed: " . $status_update['error']);
            // Don't fail the transaction, just log the error
        }

        $conn->commit();

        $_SESSION['success_message'] = "Packing record created successfully!";
        header('Location: view.php?id=' . $packing_id);
        exit();

    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Get active fiscal years
$stmt = $conn->prepare("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = true ORDER BY id ASC");
$stmt->execute();
$fiscal_years = $stmt->fetchAll(PDO::FETCH_ASSOC);

$default_fy = $fiscal_years[0]['id'] ?? null;

// ========================================
// IMPORTANT: Only show job tickets where ALL formas are completed
// Status should be 'fp_completed' or 'bp_completed'
// ========================================
$job_tickets = $conn->query("
    SELECT 
        jt.id, 
        jt.job_ticket_code, 
        jt.lot, 
        jt.print_qty, 
        jt.status,
        b.book_name, 
        b.book_code, 
        b.class_level,
        -- Check if all formas are completed
        (SELECT COUNT(*) FROM job_ticket_details jtd WHERE jtd.job_ticket_id = jt.id) as total_formas,
        (SELECT COUNT(DISTINCT jtd2.id) 
         FROM job_ticket_details jtd2
         LEFT JOIN forma_printing fp2 ON fp2.jtd_id = jtd2.id AND fp2.status = true
         WHERE jtd2.job_ticket_id = jt.id
         GROUP BY jtd2.job_ticket_id
         HAVING SUM(COALESCE(fp2.fp_printqty, 0)) >= jtd2.print_qty
        ) as completed_formas
    FROM job_ticket jt
    LEFT JOIN books b ON jt.book_id = b.book_id
    WHERE jt.status IN ('fp_completed', 'bp_completed')
    ORDER BY jt.created_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Filter to only show job tickets where ALL formas are completed
$job_tickets = array_filter($job_tickets, function($jt) {
    return $jt['total_formas'] > 0 && $jt['total_formas'] == $jt['completed_formas'];
});

// User lists (keep existing code)
$supervisors = $conn->query("SELECT id, username FROM users WHERE role IN ('supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$incharges = $conn->query("SELECT id, username FROM users WHERE role IN ('incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$operators = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- Add this display for status change message and forma completion warning -->
<?php if (isset($_SESSION['status_change_message'])): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> <?= htmlspecialchars($_SESSION['status_change_message']) ?>
        <button type="button" class="close" onclick="this.parentElement.remove()">
            <span>&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['status_change_message']); ?>
<?php endif; ?>

<?php if (empty($job_tickets)): ?>
    <div class="alert alert-warning">
        <i class="fas fa-exclamation-triangle"></i> 
        <strong>No job tickets available for packing!</strong><br>
        Book packing can only start when ALL formas are 100% printed (status: fp_completed).
        Please complete all forma printing before proceeding with packing.
    </div>
<?php endif; ?>

<!-- Keep all your existing HTML code -->

<script>
// Add JavaScript validation to check forma completion status
document.addEventListener('DOMContentLoaded', function() {
    const jtSelect = document.getElementById('jt_id');
    
    // Add additional client-side validation
    jtSelect.addEventListener('change', function() {
        const selected = this.options[this.selectedIndex];
        if (selected.value) {
            // You could add an AJAX call here to double-check forma completion status
            // before allowing the form to proceed
            checkFormaCompletion(selected.value);
        }
    });
    
    function checkFormaCompletion(jtId) {
        // Optional: Add AJAX call to verify forma completion
        fetch(`check_forma_completion.php?jt_id=${jtId}`)
            .then(res => res.json())
            .then(data => {
                if (!data.all_completed) {
                    alert('Warning: Not all formas are completed for this job ticket!\n' +
                          `Completed: ${data.completed_formas}/${data.total_formas}\n` +
                          'Packing cannot proceed until all formas are 100% printed.');
                    jtSelect.value = '';
                }
            })
            .catch(err => console.error('Error checking forma completion:', err));
    }
});
</script>

<!-- Keep all your existing HTML and styles -->
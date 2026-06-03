<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
// Include the status management functions
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/status_functions.php';

// Check permissions
if (!has_role('incharge') && !has_role('supervisor') && !has_role('admin')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Form submission data: " . print_r($_POST, true));
    $conn->beginTransaction();
    
    try {
        // Validate required fields
        $required_fields = [
            'date_nep', 'date_eng', 'name', 'fiscal_year_id', 'jt_id', 'jtd_id',
            'jtd_targetqty', 'fp_printqty', 'fp_remainqty', 'supervisor_id',
            'operator_id', 'incharge_id', 'shift_id', 'machine_id'
        ];
        
        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field])){
                $missing_fields[] = $field;
            }
        }
        
        if (!empty($missing_fields)) {
            throw new Exception("Missing required fields: " . implode(', ', $missing_fields));
        }

        // Prepare data
        $date_nep = $_POST['date_nep'];
        $date_eng = $_POST['date_eng'];
        $name = $_POST['name'];
        $fiscal_year_id = $_POST['fiscal_year_id'];
        $jt_id = $_POST['jt_id'];
        $jtd_id = $_POST['jtd_id'];
        $jtd_targetqty = $_POST['jtd_targetqty'];
        $fp_printqty = $_POST['fp_printqty'];
        $fp_remainqty = $_POST['fp_remainqty'];
        $supervisor_id = $_POST['supervisor_id'];
        $operator_id = $_POST['operator_id'];
        $incharge_id = $_POST['incharge_id'];
        $shift_id = $_POST['shift_id'];
        $machine_id = $_POST['machine_id'];
        $remarks = $_POST['remarks'] ?? null;
        $description = $_POST['description'] ?? null;
        
        // Validate quantities before insertion
        if ($fp_printqty <= 0) {
            throw new Exception("Print quantity must be greater than 0");
        }
        
        // Verify remaining quantity is not negative after this print
        $checkStmt = $conn->prepare("
            SELECT 
                jtd.print_qty as target_qty,
                COALESCE(SUM(fp.fp_printqty), 0) as already_printed
            FROM job_ticket_details jtd
            LEFT JOIN forma_printing fp ON fp.jtd_id = jtd.id AND fp.status = true
            WHERE jtd.id = :jtd_id
            GROUP BY jtd.id, jtd.print_qty
        ");
        $checkStmt->execute([':jtd_id' => $jtd_id]);
        $qtyCheck = $checkStmt->fetch(PDO::FETCH_ASSOC);
        
        if ($qtyCheck) {
            $available_qty = $qtyCheck['target_qty'] - $qtyCheck['already_printed'];
            // Allow overprinting but log it
            if ($fp_printqty > $available_qty) {
                $overprint_qty = $fp_printqty - $available_qty;
                error_log("OVERPRINTING DETECTED: JT_ID $jt_id, JTD_ID $jtd_id - Overprint: $overprint_qty units");
                // You can choose to warn but still allow, or throw exception
                // For now, we'll allow but log
            }
        }
        
        // Insert forma printing record
        $stmt = $conn->prepare("
            INSERT INTO forma_printing (
                date_nep, date_eng, name, fiscal_year_id, jt_id, jtd_id,
                jtd_targetqty, fp_printqty, fp_remainqty, supervisor_id,
                created_by, operator_id, incharge_id, shift_id, machine_id,
                remarks, description, status, created_date
            ) VALUES (
                :date_nep, :date_eng, :name, :fiscal_year_id, :jt_id, :jtd_id,
                :jtd_targetqty, :fp_printqty, :fp_remainqty, :supervisor_id,
                :created_by, :operator_id, :incharge_id, :shift_id, :machine_id,
                :remarks, :description, true, NOW()
            )
        ");
        
        $result = $stmt->execute([
            ':date_nep' => $date_nep,
            ':date_eng' => $date_eng,
            ':name' => $name,
            ':fiscal_year_id' => $fiscal_year_id,
            ':jt_id' => $jt_id,
            ':jtd_id' => $jtd_id,
            ':jtd_targetqty' => $jtd_targetqty,
            ':fp_printqty' => $fp_printqty,
            ':fp_remainqty' => $fp_remainqty,
            ':supervisor_id' => $supervisor_id,
            ':created_by' => $current_user_id,
            ':operator_id' => $operator_id,
            ':incharge_id' => $incharge_id,
            ':shift_id' => $shift_id,
            ':machine_id' => $machine_id,
            ':remarks' => $remarks,
            ':description' => $description
        ]);
        
        $forma_printing_id = $conn->lastInsertId();
        
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
        
        // Commit transaction
        $conn->commit();
        
        $_SESSION['success_message'] = "Forma Printing record created successfully!";
        ob_end_clean();
        header("Location: index.php?id=$forma_printing_id");
        exit();
        
    } catch (PDOException $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error_message'] = "Error creating Forma Printing record: " . $e->getMessage();
    } catch (Exception $e) {
        if ($conn->inTransaction()) {
            $conn->rollBack();
        }
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// ... REST OF THE ORIGINAL CODE (fetching fiscal years, job tickets, users, etc.) ...
// Keep all the HTML and JavaScript from your original file

// Add this display for status change message
?>

<!-- Add this after your existing alerts in the HTML -->
<?php if (isset($_SESSION['status_change_message'])): ?>
    <div class="alert alert-info">
        <i class="fas fa-info-circle"></i> <?= htmlspecialchars($_SESSION['status_change_message']) ?>
        <button type="button" class="close" onclick="this.parentElement.remove()">
            <span>&times;</span>
        </button>
    </div>
    <?php unset($_SESSION['status_change_message']); ?>
<?php endif; ?>

<!-- Keep all your existing HTML and JavaScript -->
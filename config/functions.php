<?php
// config/functions.php
// --- Utility Functions ---
function generateJobTicketCode($conn, $fiscalYearId) {
    $fyCode = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE id = $fiscalYearId")->fetchColumn();
    $count = $conn->query("SELECT COUNT(*) FROM job_ticket WHERE fiscal_year_id = $fiscalYearId")->fetchColumn();
    $seq = str_pad($count + 1, 3, '0', STR_PAD_LEFT);
    return "$fyCode-JT$seq";
}

function getFiscalYearId($conn) {
    $fiscalStmt = $conn->query("SELECT id FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
    return $fiscalStmt->fetchColumn();
}

function getStatusBadge($status) {
    switch ($status) {
        case 'pending': return 'warning';
        case 'in_progress': return 'primary';
        case 'completed': return 'success';
        case 'cancelled': return 'danger';
        default: return 'secondary';
    }
}

// --- Helper Functions for Forms ---
function getBooks($conn) {
    return $conn->query("SELECT book_id, book_code, book_name, class_level FROM books ORDER BY book_code")->fetchAll();
}

function getFormas($conn) {
    return $conn->query("SELECT id, name FROM forma WHERE status = 'active' ORDER BY name")->fetchAll();
}

function getMachines($conn) {
    return $conn->query("SELECT machine_name FROM machines WHERE status = 'active'")->fetchAll();
}

// Fixed function to get formas by book ID
function getFormasByBookId($conn, $bookId) {
    $stmt = $conn->prepare("SELECT id, name FROM forma WHERE book_id = ? AND status = 'active' ORDER BY name");
    $stmt->execute([intval($bookId)]);
    return $stmt->fetchAll(PDO::FETCH_ASSOC);
}

function getJobTicketById($conn, $id) {
    return $conn->query("SELECT * FROM job_ticket WHERE id = " . intval($id))->fetch();
}

function getJobTicketDetails($conn, $jobTicketId) {
    return $conn->query("
        SELECT jd.*, f.name as forma_name
        FROM job_ticket_details jd
        JOIN forma f ON jd.forma_id = f.id
        WHERE jd.job_ticket_id = " . intval($jobTicketId) . "
        ORDER BY jd.order_no
    ")->fetchAll();
}

function getJobTicketWithDetails($conn, $id) {
    $ticket = $conn->query("
        SELECT j.*, b.book_name, b.book_code, b.class_level, u.username as created_by_name,
               fy.fiscal_code, j.fiscal_year_id
        FROM job_ticket j
        JOIN books b ON j.book_id = b.book_id
        JOIN users u ON j.created_by = u.id
        JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
        WHERE j.id = " . intval($id) . "
    ")->fetch();
    
    if (!$ticket) {
        return [null, null];
    }
    
    $details = $conn->query("
        SELECT jd.*, f.name as forma_name
        FROM job_ticket_details jd
        JOIN forma f ON jd.forma_id = f.id
        WHERE jd.job_ticket_id = " . intval($id) . "
        ORDER BY jd.order_no
    ")->fetchAll();
    
    return [$ticket, $details];
}

// --- Common Redirect Logic ---
function redirectWithError($message, $location = 'index.php') {
    $_SESSION['error'] = $message;
    
    // Handle relative and absolute paths properly
    if (strpos($location, 'http') === 0) {
        // Full URL
        header("Location: $location");
    } elseif (strpos($location, '/') === 0) {
        // Absolute path
        header("Location: $location");
    } else {
        // Relative path - prepend base path
        header("Location: /deno2/jobticket/$location");
    }
    exit();
}

function redirectWithSuccess($message, $location = 'index.php') {
    $_SESSION['success'] = $message;
    
    // Handle relative and absolute paths properly
    if (strpos($location, 'http') === 0) {
        // Full URL
        header("Location: $location");
    } elseif (strpos($location, '/') === 0) {
        // Absolute path
        header("Location: $location");
    } else {
        // Relative path - prepend base path
        header("Location: /deno2/jobticket/$location");
    }
    exit();
}

// --- Permission Check (Assuming this function exists elsewhere or needs definition) ---
// function has_role($role) {
//     // Implement your role checking logic here
//     // Example (adjust based on your auth system):
//     return isset($_SESSION['role']) && $_SESSION['role'] === $role;
// }
?>
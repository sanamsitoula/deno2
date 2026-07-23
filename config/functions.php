<?php
// config/functions.php

// Defensive: same detection as config/auth.php, in case this file is ever
// loaded without auth.php first.
if (!function_exists('detect_deno2_base_url')) {
    function detect_deno2_base_url(): string {
        $docRoot    = rtrim(str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT'] ?? ''), '/');
        $scriptFile = str_replace('\\', '/', $_SERVER['SCRIPT_FILENAME'] ?? '');
        $scriptName = $_SERVER['SCRIPT_NAME'] ?? '';
        $appRoot    = $docRoot . '/deno2';

        if ($docRoot !== '' && strpos($scriptFile, $appRoot) === 0) {
            $relative = substr($scriptFile, strlen($appRoot));
            if (strlen($relative) <= strlen($scriptName)) {
                return substr($scriptName, 0, strlen($scriptName) - strlen($relative));
            }
        }
        return '';
    }
}

// --- Utility Functions ---
if (!function_exists('generateJobTicketCode')) {
    function generateJobTicketCode($conn, $fiscalYearId) {
        $fyCode = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE id = " . (int)$fiscalYearId)->fetchColumn();
        // MAX()-based (not COUNT()) so a deleted job_ticket row never causes a
        // duplicate code — resets to 1 automatically per fiscal_year_id since a
        // new fiscal year has no rows yet.
        $stmt = $conn->prepare("
            SELECT COALESCE(MAX(CAST(SUBSTRING(job_ticket_code FROM 'JT(\\d+)$') AS INTEGER)), 0) + 1
            FROM job_ticket WHERE fiscal_year_id = :fy
        ");
        $stmt->execute([':fy' => $fiscalYearId]);
        $next = (int)$stmt->fetchColumn();
        $seq = str_pad($next, 3, '0', STR_PAD_LEFT);
        return "$fyCode-JT$seq";
    }
}

if (!function_exists('getFiscalYearId')) {
    function getFiscalYearId($conn) {
        $fiscalStmt = $conn->query("SELECT id FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
        return $fiscalStmt->fetchColumn();
    }
}

// Full active fiscal_years row (id, fiscal_code, fiscal_name, start_date, end_date)
// — the one place every module/report should pull the active fiscal year from,
// so fiscal_name is always displayed consistently everywhere.
if (!function_exists('getActiveFiscalYear')) {
    function getActiveFiscalYear($conn) {
        $stmt = $conn->query("SELECT id, fiscal_code, fiscal_name, start_date, end_date FROM fiscal_years WHERE is_active = TRUE LIMIT 1");
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

// Short fiscal label used inside number-series strings, e.g. "2082-83" -> "82-83".
// Accepts either a fiscal_years row (array) or a fiscal_name/fiscal_code string.
if (!function_exists('getFiscalShort')) {
    function getFiscalShort($fiscalYear) {
        $name = is_array($fiscalYear)
            ? ($fiscalYear['fiscal_name'] ?? $fiscalYear['fiscal_code'] ?? '')
            : (string)$fiscalYear;
        if ($name === '') return '';
        if (preg_match('/^\d{2}(\d{2})[\/\-](\d{2,4})$/', $name, $m)) {
            $endShort = strlen($m[2]) === 4 ? substr($m[2], 2) : $m[2];
            return $m[1] . '-' . $endShort;
        }
        return str_replace('/', '-', $name);
    }
}

// Default BS date range (Shrawan 1 → next Ashadh end) for the active fiscal
// year, e.g. fiscal_code "2082" -> ['start' => '2082.04.01', 'end' => '2083.03.32'].
// Used so every module's index/list page defaults its date-range filter to
// whichever fiscal year is currently active, instead of a hardcoded year.
if (!function_exists('getActiveFiscalDateRange')) {
    function getActiveFiscalDateRange($conn) {
        $fy = getActiveFiscalYear($conn);
        $code = $fy['fiscal_code'] ?? '2082';
        return [
            'start'          => $code . '.04.01',
            'end'            => ((int)$code + 1) . '.03.32',
            'fiscal_code'    => $code,
            'fiscal_year_id' => $fy['id'] ?? null,
        ];
    }
}

// Generic fiscal-year-scoped "next number" generator: MAX(serialColumn)+1,
// scoped to fiscal_year_id (and any extra WHERE columns), formatted as
// "{serial}/{moduleCode}/{fiscalShort}" per plan_numberseries.md.
// $table/$serialColumn are developer-supplied constants, never user input.
if (!function_exists('generateFiscalScopedNumber')) {
    function generateFiscalScopedNumber($conn, $table, $serialColumn, $fiscalYearId, $moduleCode, $fiscalYear, array $extraWhere = []) {
        $where = "fiscal_year_id = :fy";
        $params = [':fy' => $fiscalYearId];
        foreach ($extraWhere as $col => $val) {
            $ph = ':' . $col;
            $where .= " AND $col = $ph";
            $params[$ph] = $val;
        }
        $stmt = $conn->prepare("SELECT COALESCE(MAX($serialColumn), 0) + 1 FROM $table WHERE $where");
        $stmt->execute($params);
        $serial = (int)$stmt->fetchColumn();
        $short = getFiscalShort($fiscalYear);
        $formatted = "{$serial}/{$moduleCode}/{$short}";
        return [$serial, $formatted];
    }
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
               fy.fiscal_code, fy.fiscal_name, j.fiscal_year_id
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
        header("Location: " . detect_deno2_base_url() . "/jobticket/$location");
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
        header("Location: " . detect_deno2_base_url() . "/jobticket/$location");
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
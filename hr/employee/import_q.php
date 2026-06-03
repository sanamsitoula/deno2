<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// PostgreSQL-specific setup
$conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$conn->setAttribute(PDO::ATTR_EMULATE_PREPARES, false);
$conn->exec("SET CLIENT_ENCODING TO 'UTF8'");

if (!has_role('admin') && !has_role('hr')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;
$preview_mode = false;
$preview_data = [];
$errors = [];
$warnings = [];

// ================== Validation Functions ==================
function validateEmployeeType($type) {
    return in_array(strtoupper(trim($type)), ['PERMANENT', 'CONTRACT', 'DAILY_WAGES']);
}

function validateGender($gender) {
    if (empty($gender)) return true;
    return in_array(strtoupper(trim($gender)), ['MALE', 'FEMALE', 'OTHER']);
}

function validateEmail($email) {
    if (empty($email)) return true;
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

function validateMobile($mobile) {
    if (empty($mobile)) return true;
    return preg_match('/^98\d{8}$/', $mobile);
}

function validateDate($date) {
    if (empty($date)) return true;
    $d = DateTime::createFromFormat('Y-m-d', $date);
    return $d && $d->format('Y-m-d') === $date;
}

function validateReference($conn, $table, $id) {
    if (empty($id)) return true;
    $stmt = $conn->prepare("SELECT id FROM {$table} WHERE id = :id AND status = true");
    $stmt->execute([':id' => (int)$id]);
    return $stmt->fetch(PDO::FETCH_ASSOC) !== false;
}

function convertToBoolean($value) {
    if (is_bool($value)) return $value;
    if (is_null($value) || (is_string($value) && trim($value) === '')) return false;
    
    $clean = strtolower(trim((string)$value));
    $trueVals = ['true', 'yes', '1', 't', 'y', 'हो', 'होइन']; // Nepali support
    $falseVals = ['false', 'no', '0', 'f', 'n'];
    
    if (in_array($clean, $trueVals)) return true;
    if (in_array($clean, $falseVals)) return false;
    
    return is_numeric($value) ? ((int)$value !== 0) : false;
}

function fixDateFormat($dateStr) {
    if (empty(trim($dateStr))) return null;
    $dateStr = trim($dateStr);
    
    // Already in YYYY-MM-DD
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateStr)) {
        return validateDate($dateStr) ? $dateStr : null;
    }
    
    // Handle MM/DD/YYYY (US format common in Excel)
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dateStr, $matches)) {
        $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        if (checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day";
        }
    }
    
    // Handle DD/MM/YYYY (common Nepali format)
    if (preg_match('/^(\d{1,2})[\/\-](\d{1,2})[\/\-](\d{4})$/', $dateStr, $matches)) {
        $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
        $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
        $year = $matches[3];
        if (checkdate((int)$month, (int)$day, (int)$year)) {
            return "$year-$month-$day";
        }
    }
    
    return null;
}

// ================== Handle Actions ==================
// Cancel preview
if (isset($_GET['cancel_preview']) && isset($_SESSION['import_preview'])) {
    unset($_SESSION['import_preview']);
    header('Location: ' . $_SERVER['PHP_SELF']);
    exit();
}

// Download error report
if (isset($_GET['download_errors']) && isset($_SESSION['import_preview']['invalid_rows'])) {
    $invalid_rows = $_SESSION['import_preview']['invalid_rows'];
    $headers = $_SESSION['import_preview']['headers'];
    
    $filename = 'import_errors_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    // Add UTF-8 BOM for Excel Nepali font support
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Header row
    fputcsv($output, array_merge(['Row Number', 'Error Messages'], $headers));
    
    // Data rows
    foreach ($invalid_rows as $row) {
        $error_msg = implode(' | ', $row['errors']);
        fputcsv($output, array_merge([$row['row_number'], $error_msg], $row['original_data']));
    }
    
    fclose($output);
    exit();
}

// Confirm import
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['confirm_import']) && isset($_SESSION['import_preview'])) {
    $preview = $_SESSION['import_preview'];
    $valid_rows = $preview['valid_rows'] ?? [];
    $active_fy_id = $preview['active_fy_id'] ?? null;
    $imported_count = 0;
    $errors = [];
    
    if (empty($valid_rows)) {
        $errors[] = "No valid rows to import";
    } else {
        $conn->beginTransaction();
        try {
            foreach ($valid_rows as $idx => $data) {
                try {
                    // Prepare boolean values with explicit binding
                    $is_technical = convertToBoolean($data['is_technical'] ?? '');
                    
                    $stmt = $conn->prepare("
                        INSERT INTO employee (
                            name, name_eng, name_nep, emp_type, emp_status,
                            department_id, designation_id, level_id,
                            email, mobile_number, gender, full_address,
                            join_date, join_date_nep, dob, dob_nep,
                            initial_appointment_date, initial_appointment_date_nep,
                            retirement_date, retirement_date_nep,
                            citizenship_no, national_id_card_no,
                            pan_no, bank_name, bank_branch, bank_account_number,
                            state, local_body, ward_no, card_id,
                            is_technical, fiscal_year_id,
                            created_by, updated_by, created_date
                        ) VALUES (
                            :name, :name_eng, :name_nep, :emp_type, 'ACTIVE',
                            :department_id, :designation_id, :level_id,
                            :email, :mobile_number, :gender, :full_address,
                            :join_date, :join_date_nep, :dob, :dob_nep,
                            :initial_appointment_date, :initial_appointment_date_nep,
                            :retirement_date, :retirement_date_nep,
                            :citizenship_no, :national_id_card_no,
                            :pan_no, :bank_name, :bank_branch, :bank_account_number,
                            :state, :local_body, :ward_no, :card_id,
                            :is_technical, :fiscal_year_id,
                            :created_by, :updated_by, CURRENT_TIMESTAMP
                        )
                    ");
                    
                    // Bind parameters with explicit types for booleans
                    $stmt->bindValue(':name', $data['name'], PDO::PARAM_STR);
                    $stmt->bindValue(':name_eng', $data['name_eng'] ?? $data['name'], PDO::PARAM_STR);
                    $stmt->bindValue(':name_nep', !empty($data['name_nep']) ? $data['name_nep'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':emp_type', strtoupper($data['emp_type']), PDO::PARAM_STR);
                    $stmt->bindValue(':department_id', !empty($data['department_id']) ? (int)$data['department_id'] : null, PDO::PARAM_INT);
                    $stmt->bindValue(':designation_id', !empty($data['designation_id']) ? (int)$data['designation_id'] : null, PDO::PARAM_INT);
                    $stmt->bindValue(':level_id', !empty($data['level_id']) ? (int)$data['level_id'] : null, PDO::PARAM_INT);
                    $stmt->bindValue(':email', !empty($data['email']) ? $data['email'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':mobile_number', !empty($data['mobile_number']) ? $data['mobile_number'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':gender', !empty($data['gender']) ? strtoupper($data['gender']) : null, PDO::PARAM_STR);
                    $stmt->bindValue(':full_address', !empty($data['full_address']) ? $data['full_address'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':join_date', !empty($data['join_date']) ? $data['join_date'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':join_date_nep', !empty($data['join_date_nep']) ? $data['join_date_nep'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':dob', !empty($data['dob']) ? $data['dob'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':dob_nep', !empty($data['dob_nep']) ? $data['dob_nep'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':initial_appointment_date', !empty($data['initial_appointment_date']) ? $data['initial_appointment_date'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':initial_appointment_date_nep', !empty($data['initial_appointment_date_nep']) ? $data['initial_appointment_date_nep'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':retirement_date', !empty($data['retirement_date']) ? $data['retirement_date'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':retirement_date_nep', !empty($data['retirement_date_nep']) ? $data['retirement_date_nep'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':citizenship_no', !empty($data['citizenship_no']) ? $data['citizenship_no'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':national_id_card_no', !empty($data['national_id_card_no']) ? $data['national_id_card_no'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':pan_no', !empty($data['pan_no']) ? $data['pan_no'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':bank_name', !empty($data['bank_name']) ? $data['bank_name'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':bank_branch', !empty($data['bank_branch']) ? $data['bank_branch'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':bank_account_number', !empty($data['bank_account_number']) ? $data['bank_account_number'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':state', !empty($data['state']) ? $data['state'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':local_body', !empty($data['local_body']) ? $data['local_body'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':ward_no', !empty($data['ward_no']) ? $data['ward_no'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':card_id', !empty($data['card_id']) ? $data['card_id'] : null, PDO::PARAM_STR);
                    $stmt->bindValue(':is_technical', $is_technical, PDO::PARAM_BOOL); // CRITICAL: Explicit boolean binding
                    $stmt->bindValue(':fiscal_year_id', $active_fy_id, PDO::PARAM_INT);
                    $stmt->bindValue(':created_by', $current_user_id, PDO::PARAM_INT);
                    $stmt->bindValue(':updated_by', $current_user_id, PDO::PARAM_INT);
                    
                    $stmt->execute();
                    $imported_count++;
                } catch (PDOException $e) {
                    $errors[] = "Row " . ($idx + 2) . ": Database error - " . $e->getMessage();
                    error_log("Import error row " . ($idx + 2) . ": " . $e->getMessage());
                }
            }
            
            if (empty($errors)) {
                $conn->commit();
                $_SESSION['success_message'] = "Successfully imported {$imported_count} employee(s).";
                unset($_SESSION['import_preview']);
                header('Location: ' . $_SERVER['PHP_SELF'] . '?success=1');
                exit();
            } else {
                $conn->rollBack();
            }
        } catch (Exception $e) {
            $conn->rollBack();
            $errors[] = "Transaction failed: " . $e->getMessage();
            error_log("Transaction error: " . $e->getMessage());
        }
    }
}

// Process CSV upload for preview
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file']) && empty($errors)) {
    $file = $_FILES['csv_file'];
    $valid_rows = [];
    $invalid_rows = [];
    $headers = [];
    $line_number = 1;
    
    if ($file['error'] !== UPLOAD_ERR_OK) {
        $errors[] = "File upload error. Error code: " . $file['error'];
    } elseif ($file['size'] > 5 * 1024 * 1024) {
        $errors[] = "File size exceeds 5MB limit.";
    } else {
        $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            $errors[] = "Only CSV files are allowed.";
        } else {
            $handle = fopen($file['tmp_name'], 'r');
            if (!$handle) {
                $errors[] = "Could not read uploaded file.";
            } else {
                // Handle BOM
                $firstLine = fgets($handle);
                if (substr($firstLine, 0, 3) === "\xEF\xBB\xBF") {
                    fseek($handle, 3);
                } else {
                    rewind($handle);
                }
                
                $headers = fgetcsv($handle);
                if ($headers === false) {
                    $errors[] = "Could not read CSV headers.";
                    fclose($handle);
                } else {
                    // Clean headers
                    $headers = array_map(function($h) {
                        return strtolower(trim($h));
                    }, $headers);
                    
                    // Required fields check
                    $required = ['name', 'emp_type'];
                    foreach ($required as $col) {
                        if (!in_array($col, $headers)) {
                            $errors[] = "Missing required column: '{$col}'";
                        }
                    }
                    
                    if (empty($errors)) {
                        // Get active fiscal year for preview context
                        $active_fy_stmt = $conn->query("SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1");
                        $active_fy = $active_fy_stmt->fetch(PDO::FETCH_ASSOC);
                        $active_fy_id = $active_fy['id'] ?? null;
                        
                        if (!$active_fy_id) {
                            $errors[] = "No active fiscal year found. Please activate a fiscal year before importing.";
                        } else {
                            // Process each row
                            while (($row = fgetcsv($handle)) !== false) {
                                $line_number++;
                                
                                // Skip empty rows
                                if (empty(array_filter($row, 'strlen'))) {
                                    continue;
                                }
                                
                                // Pad row to match headers
                                while (count($row) < count($headers)) {
                                    $row[] = '';
                                }
                                
                                $data = array_combine($headers, array_slice($row, 0, count($headers)));
                                $data = array_map('trim', $data);
                                $row_errors = [];
                                $original_data = $row; // For error report
                                
                                // Validate required fields
                                if (empty($data['name'])) {
                                    $row_errors[] = "Name is required";
                                }
                                
                                if (empty($data['emp_type']) || !validateEmployeeType($data['emp_type'])) {
                                    $row_errors[] = "Invalid emp_type. Must be: PERMANENT, CONTRACT, or DAILY_WAGES";
                                }
                                
                                // Validate optional fields
                                if (!empty($data['gender']) && !validateGender($data['gender'])) {
                                    $row_errors[] = "Invalid gender. Must be: MALE, FEMALE, or OTHER";
                                }
                                
                                if (!empty($data['email']) && !validateEmail($data['email'])) {
                                    $row_errors[] = "Invalid email format";
                                }
                                
                                if (!empty($data['mobile_number']) && !validateMobile($data['mobile_number'])) {
                                    $warnings[] = "Line $line_number: Mobile number should be Nepal format (98XXXXXXXX)";
                                }
                                
                                // Fix and validate dates
                                $date_fields = ['join_date', 'dob', 'initial_appointment_date', 'retirement_date'];
                                foreach ($date_fields as $field) {
                                    if (!empty($data[$field])) {
                                        $fixed = fixDateFormat($data[$field]);
                                        if ($fixed === null) {
                                            $row_errors[] = "Invalid {$field} format. Use YYYY-MM-DD, MM/DD/YYYY or DD/MM/YYYY";
                                        } else {
                                            $data[$field] = $fixed;
                                        }
                                    }
                                }
                                
                                // Validate references
                                $ref_checks = [
                                    'department_id' => 'department',
                                    'designation_id' => 'designation',
                                    'level_id' => 'level'
                                ];
                                
                                foreach ($ref_checks as $col => $table) {
                                    if (!empty($data[$col]) && !validateReference($conn, $table, $data[$col])) {
                                        $row_errors[] = "Invalid {$col} (must exist in system)";
                                    }
                                }
                                
                                // Convert boolean fields
                                $data['is_technical'] = convertToBoolean($data['is_technical'] ?? '');
                                
                                // Store row with validation result
                                if (!empty($row_errors)) {
                                    $invalid_rows[] = [
                                        'row_number' => $line_number,
                                        'errors' => $row_errors,
                                        'original_data' => $original_data
                                    ];
                                } else {
                                    // Clean and prepare valid data
                                    $clean_data = [
                                        'name' => $data['name'],
                                        'name_eng' => $data['name_eng'] ?? $data['name'],
                                        'name_nep' => !empty($data['name_nep']) ? $data['name_nep'] : null,
                                        'emp_type' => strtoupper($data['emp_type']),
                                        'department_id' => !empty($data['department_id']) ? (int)$data['department_id'] : null,
                                        'designation_id' => !empty($data['designation_id']) ? (int)$data['designation_id'] : null,
                                        'level_id' => !empty($data['level_id']) ? (int)$data['level_id'] : null,
                                        'email' => !empty($data['email']) ? $data['email'] : null,
                                        'mobile_number' => !empty($data['mobile_number']) ? $data['mobile_number'] : null,
                                        'gender' => !empty($data['gender']) ? strtoupper($data['gender']) : null,
                                        'full_address' => $data['full_address'] ?? null,
                                        'join_date' => $data['join_date'] ?? null,
                                        'join_date_nep' => $data['join_date_nep'] ?? null,
                                        'dob' => $data['dob'] ?? null,
                                        'dob_nep' => $data['dob_nep'] ?? null,
                                        'initial_appointment_date' => $data['initial_appointment_date'] ?? null,
                                        'initial_appointment_date_nep' => $data['initial_appointment_date_nep'] ?? null,
                                        'retirement_date' => $data['retirement_date'] ?? null,
                                        'retirement_date_nep' => $data['retirement_date_nep'] ?? null,
                                        'citizenship_no' => $data['citizenship_no'] ?? null,
                                        'national_id_card_no' => $data['national_id_card_no'] ?? null,
                                        'pan_no' => $data['pan_no'] ?? null,
                                        'bank_name' => $data['bank_name'] ?? null,
                                        'bank_branch' => $data['bank_branch'] ?? null,
                                        'bank_account_number' => $data['bank_account_number'] ?? null,
                                        'state' => $data['state'] ?? null,
                                        'local_body' => $data['local_body'] ?? null,
                                        'ward_no' => $data['ward_no'] ?? null,
                                        'card_id' => $data['card_id'] ?? null,
                                        'is_technical' => $data['is_technical'],
                                    ];
                                    $valid_rows[] = $clean_data;
                                }
                            }
                        }
                    }
                    fclose($handle);
                }
            }
        }
    }
    
    // Store preview data in session
    if (empty($errors)) {
        $_SESSION['import_preview'] = [
            'valid_rows' => $valid_rows,
            'invalid_rows' => $invalid_rows,
            'headers' => $headers,
            'total_rows' => $line_number - 1,
            'valid_count' => count($valid_rows),
            'invalid_count' => count($invalid_rows),
            'file_name' => $file['name'],
            'active_fy_id' => $active_fy_id ?? null,
            'warnings' => $warnings
        ];
        $preview_mode = true;
        $preview_data = $_SESSION['import_preview'];
    }
}

// ================== Generate Sample CSV ==================
if (isset($_GET['download_sample'])) {
    // Get sample references (PostgreSQL compatible)
    $sample_dept = $conn->query("SELECT id FROM department WHERE status = true LIMIT 1")->fetchColumn();
    $sample_desig = $conn->query("SELECT id FROM designation WHERE status = true LIMIT 1")->fetchColumn();
    $sample_level = $conn->query("SELECT id FROM level WHERE status = true LIMIT 1")->fetchColumn();
    
    $sample_data = [
        [
            'name', 'name_eng', 'name_nep', 'emp_type', 'department_id', 'designation_id', 'level_id',
            'email', 'mobile_number', 'gender', 'full_address',
            'join_date', 'join_date_nep', 'dob', 'dob_nep',
            'citizenship_no', 'national_id_card_no',
            'pan_no', 'bank_name', 'bank_branch', 'bank_account_number',
            'state', 'local_body', 'ward_no', 'card_id', 'is_technical'
        ],
        [
            'राम कुमार शर्मा', 'Ram Kumar Sharma', 'राम कुमार शर्मा', 'PERMANENT', 
            $sample_dept ?: '1', $sample_desig ?: '1', $sample_level ?: '1',
            'ram.sharma@company.com', '9812345678', 'MALE', 'काठमाडौं-१०, बानेश्वर',
            '2025-05-01', '२०८२-०१-१८', '1990-05-20', '२०४७-०२-०७',
            '१२-०१-७५-१२३४५', 'राष्ट्रिय परिचयपत्र १२३४५६७८९',
            '१२३४५६७८९', 'नेपाल बैंक लिमिटेड', 'काठमाडौं शाखा', '१२३०००००००००००',
            'बागमती', 'काठमाडौं महानगरपालिका', '१०', 'कार्ड००१', 'होइन'
        ],
        [
            'सीता देवी थापा', 'Sita Devi Thapa', 'सीता देवी थापा', 'CONTRACT',
            $sample_dept ?: '1', $sample_desig ?: '1', $sample_level ?: '1',
            'sita.thapa@company.com', '9823456789', 'FEMALE', 'पोखरा-५, लेकसाइड',
            '2025-05-01', '२०८२-०१-१८', '1992-08-15', '२०४९-०४-३२',
            '१२-०२-७६-२३४५६', 'राष्ट्रिय परिचयपत्र २३४५६७८९०',
            '२३४५६७८९०', 'हिमालयन बैंक', 'पोखरा शाखा', '१२३००००००००००१',
            'गण्डकी', 'पोखरा महानगरपालिका', '५', 'कार्ड००२', 'हो'
        ]
    ];

    $filename = 'employee_import_template_' . date('Y-m-d_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    // UTF-8 BOM for Excel Nepali font support
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    foreach ($sample_data as $row) {
        fputcsv($output, $row);
    }
    fclose($output);
    exit();
}

// ================== Download Reference Data ==================
if (isset($_GET['download_reference'])) {
    $filename = 'reference_data_' . date('Y-m-d') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));
    
    // Departments
    fputcsv($output, ['=== DEPARTMENTS (विभागहरू) ===']);
    fputcsv($output, ['ID', 'Name (नाम)', 'Sub Department (उपविभाग)']);
    $depts = $conn->query("SELECT id, name, COALESCE(sub_department_name, '') AS sub_department_name FROM department WHERE status = true ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($depts as $d) {
        fputcsv($output, [$d['id'], $d['name'], $d['sub_department_name']]);
    }
    
    // Designations
    fputcsv($output, []);
    fputcsv($output, ['=== DESIGNATIONS (पदहरू) ===']);
    fputcsv($output, ['ID', 'Name (नाम)']);
    $desigs = $conn->query("SELECT id, name FROM designation WHERE status = true ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($desigs as $d) {
        fputcsv($output, [$d['id'], $d['name']]);
    }
    
    // Levels
    fputcsv($output, []);
    fputcsv($output, ['=== LEVELS (तहहरू) ===']);
    fputcsv($output, ['ID', 'Name (नाम)']);
    $levels = $conn->query("SELECT id, name FROM level WHERE status = true ORDER BY display_order DESC")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($levels as $l) {
        fputcsv($output, [$l['id'], $l['name']]);
    }
    
    fclose($output);
    exit();
}

// Get active fiscal year for display
$active_fy_stmt = $conn->query("SELECT id, fiscal_code FROM fiscal_years WHERE is_active = true LIMIT 1");
$active_fy = $active_fy_stmt->fetch(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ne" dir="ltr">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>कर्मचारी समूह आयात | Bulk Import Employees</title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css" rel="stylesheet">
    <link href="https://fonts.googleapis.com/css2?family=Preeti&display=swap" rel="stylesheet">
    <style>
        :lang(ne) {
            font-family: 'Preeti', 'Arial Unicode MS', 'Noto Sans Devanagari', 'Sans-serif';
            font-size: 1.05rem;
        }
        .feature-badge {
            background: #e7f3ff;
            color: #0066cc;
            padding: 0.25rem 0.5rem;
            border-radius: 4px;
            font-size: 0.75rem;
            display: inline-block;
            margin-right: 0.5rem;
        }
        .field-list { 
            column-count: 2; 
            padding-left: 1.5rem;
        }
        .field-list li { 
            break-inside: avoid;
            margin-bottom: 0.3rem;
        }
        .alert-pre {
            white-space: pre-wrap;
            font-family: 'Courier New', monospace;
            font-size: 0.9em;
            background: #f8f9fa;
            padding: 10px;
            border-radius: 5px;
            border-left: 4px solid #dc3545;
            max-height: 300px;
            overflow-y: auto;
        }
        .preview-table th {
            background-color: #f8f9fa;
            font-weight: 600;
        }
        .error-cell {
            background-color: #fff5f5;
            color: #dc3545;
            font-weight: 500;
        }
        .success-cell {
            background-color: #f0fdf4;
            color: #16a34a;
        }
        .nepali-font {
            font-family: 'Preeti', 'Arial Unicode MS', 'Noto Sans Devanagari', 'Sans-serif';
        }
        .preview-container {
            max-height: 60vh;
            overflow-y: auto;
            border: 1px solid #dee2e6;
            border-radius: 0.375rem;
        }
        @media (max-width: 768px) {
            .field-list {
                column-count: 1;
            }
        }
    </style>
</head>
<body>
    <div class="container-fluid mt-3 px-3 px-md-4">
        <div class="d-flex flex-column flex-md-row justify-content-between align-items-start align-items-md-center mb-4">
            <div>
                <h1 class="h3 fw-bold mb-1">
                    <i class="fas fa-file-import me-2"></i>
                    <span class="d-none d-md-inline">Bulk Import Employees | </span>कर्मचारी समूह आयात
                </h1>
                <p class="text-muted mb-0">Upload CSV to import multiple employees with validation preview</p>
            </div>
            <a href="index_enhanced.php" class="btn btn-secondary mt-2 mt-md-0">
                <i class="fas fa-arrow-left me-1"></i> Back to List | सूचीमा फर्कनुहोस्
            </a>
        </div>

        <?php if (isset($_GET['success']) && isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success alert-dismissible fade show" role="alert">
                <h5 class="alert-heading"><i class="fas fa-check-circle me-2"></i>Import Completed Successfully!</h5>
                <p class="mb-0"><?= htmlspecialchars($_SESSION['success_message']) ?></p>
                <?php unset($_SESSION['success_message']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
            </div>
        <?php endif; ?>

        <!-- Active Fiscal Year Info -->
        <?php if ($active_fy): ?>
            <div class="alert alert-info mb-4">
                <i class="fas fa-calendar-alt me-2"></i>
                <strong>सक्रिय आर्थिक वर्ष | Active Fiscal Year:</strong> 
                <span class="fw-bold"><?= htmlspecialchars($active_fy['fiscal_code']) ?></span>
                <br><small class="text-muted">(All imported employees will be assigned to this fiscal year)</small>
            </div>
        <?php else: ?>
            <div class="alert alert-warning mb-4">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong>Warning | चेतावनी:</strong> No active fiscal year found. Please activate a fiscal year before importing.
            </div>
        <?php endif; ?>

        <!-- Preview Mode -->
        <?php if ($preview_mode && !empty($preview_data)): ?>
            <div class="card mb-4 border-primary">
                <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
                    <h5 class="mb-0"><i class="fas fa-eye me-2"></i>Import Preview | आयात पूर्वावलोकन</h5>
                    <div>
                        <span class="badge bg-success me-2">
                            <i class="fas fa-check me-1"></i>Valid: <?= $preview_data['valid_count'] ?>
                        </span>
                        <span class="badge bg-danger">
                            <i class="fas fa-times me-1"></i>Invalid: <?= $preview_data['invalid_count'] ?>
                        </span>
                    </div>
                </div>
                <div class="card-body">
                    <div class="alert alert-warning mb-3">
                        <h6><i class="fas fa-exclamation-triangle me-1"></i>Important | महत्त्वपूर्ण:</h6>
                        <ul class="mb-0 small">
                            <li>Only valid rows will be imported (<?= $preview_data['valid_count'] ?> rows)</li>
                            <li>Invalid rows contain errors - download error report to fix issues</li>
                            <li>Nepali text displays correctly in database and exports</li>
                        </ul>
                    </div>
                    
                    <?php if (!empty($preview_data['warnings'])): ?>
                        <div class="alert alert-warning mb-3">
                            <h6><i class="fas fa-exclamation-circle me-1"></i>Warnings (<?= count($preview_data['warnings']) ?>)</h6>
                            <ul class="mb-0 small">
                                <?php foreach (array_slice($preview_data['warnings'], 0, 5) as $warning): ?>
                                    <li><?= htmlspecialchars($warning) ?></li>
                                <?php endforeach; ?>
                                <?php if (count($preview_data['warnings']) > 5): ?>
                                    <li>... and <?= count($preview_data['warnings']) - 5 ?> more warnings</li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                    
                    <?php if ($preview_data['invalid_count'] > 0): ?>
                        <div class="alert alert-danger mb-4">
                            <h6><i class="fas fa-exclamation-triangle me-1"></i>Errors Found | त्रुटिहरू भेटियो</h6>
                            <p class="mb-2">Please fix errors in your CSV file before importing. Download the error report for details:</p>
                            <a href="?download_errors" class="btn btn-danger">
                                <i class="fas fa-download me-1"></i>Download Error Report (CSV) | त्रुटि प्रतिवेदन डाउनलोड गर्नुहोस्
                            </a>
                        </div>
                    <?php endif; ?>
                    
                    <div class="mb-4">
                        <h6 class="fw-bold mb-2">Valid Rows Preview (First 5) | मान्य पङ्क्तिहरूको पूर्वावलोकन</h6>
                        <div class="preview-container">
                            <table class="table table-sm table-bordered mb-0">
                                <thead class="sticky-top bg-white">
                                    <tr>
                                        <th>#</th>
                                        <th>Name (नाम)</th>
                                        <th>Emp Type</th>
                                        <th>Department ID</th>
                                        <th>Email</th>
                                        <th>is_technical</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $valid_preview = array_slice($preview_data['valid_rows'], 0, 5);
                                    foreach ($valid_preview as $idx => $row): 
                                        $is_tech = $row['is_technical'] ? 'हो (Yes)' : 'होइन (No)';
                                    ?>
                                        <tr class="success-cell">
                                            <td><?= $idx + 1 ?></td>
                                            <td class="nepali-font"><?= htmlspecialchars($row['name_nep'] ?? $row['name']) ?></td>
                                            <td><?= htmlspecialchars($row['emp_type']) ?></td>
                                            <td><?= htmlspecialchars($row['department_id'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($row['email'] ?? '-') ?></td>
                                            <td><?= htmlspecialchars($is_tech) ?></td>
                                        </tr>
                                    <?php endforeach; ?>
                                    <?php if (empty($valid_preview)): ?>
                                        <tr><td colspan="6" class="text-center text-muted">No valid rows to display</td></tr>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($preview_data['valid_count'] > 5): ?>
                            <small class="text-muted">Showing first 5 of <?= $preview_data['valid_count'] ?> valid rows</small>
                        <?php endif; ?>
                    </div>
                    
                    <div class="d-flex justify-content-between flex-column flex-md-row gap-2">
                        <form method="GET" class="d-inline">
                            <input type="hidden" name="cancel_preview" value="1">
                            <button type="submit" class="btn btn-secondary w-100">
                                <i class="fas fa-times me-1"></i>Cancel | रद्द गर्नुहोस्
                            </button>
                        </form>
                        <form method="POST" class="d-inline">
                            <input type="hidden" name="confirm_import" value="1">
                            <button type="submit" class="btn btn-success w-100" 
                                <?= $preview_data['valid_count'] == 0 ? 'disabled' : '' ?>>
                                <i class="fas fa-check-circle me-1"></i>
                                Confirm Import (<?= $preview_data['valid_count'] ?> rows) | आयात पुष्टि गर्नुहोस्
                            </button>
                        </form>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <!-- Upload Form (hidden in preview mode) -->
        <?php if (!$preview_mode): ?>
            <!-- Important Notes -->
            <div class="alert alert-warning mb-4">
                <h5><i class="fas fa-exclamation-circle me-2"></i>Important Notes | महत्त्वपूर्ण नोटहरू:</h5>
                <ul class="mb-0">
                    <li><strong>Date Formats:</strong> Supports YYYY-MM-DD, MM/DD/YYYY, DD/MM/YYYY (auto-converted)</li>
                    <li><strong>Nepali Text:</strong> Save CSV as UTF-8. Excel: "Save As" → CSV UTF-8 (Comma delimited)</li>
                    <li><strong>Boolean Values:</strong> is_technical accepts: Yes/No, True/False, 1/0, हो/होइन (case-insensitive)</li>
                    <li><strong>Preview First:</strong> After upload, you'll see validation results before final import</li>
                    <li><strong>Error Handling:</strong> Download row-wise error report to fix invalid entries</li>
                </ul>
            </div>

            <!-- Download Section -->
            <div class="row mb-4">
                <div class="col-md-6 mb-3">
                    <div class="card h-100 border-info">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-download text-info me-2"></i>Step 1: Download Template</h5>
                            <p class="card-text">CSV template with Nepali/English samples and all fields</p>
                            <a href="?download_sample" class="btn btn-info w-100">
                                <i class="fas fa-file-csv me-1"></i>Download Template | टेम्पलेट डाउनलोड गर्नुहोस्
                            </a>
                        </div>
                    </div>
                </div>
                <div class="col-md-6 mb-3">
                    <div class="card h-100 border-warning">
                        <div class="card-body">
                            <h5 class="card-title"><i class="fas fa-database text-warning me-2"></i>Step 2: Get Reference IDs</h5>
                            <p class="card-text">Valid Department, Designation & Level IDs (with Nepali names)</p>
                            <a href="?download_reference" class="btn btn-warning w-100">
                                <i class="fas fa-table me-1"></i>Download Reference | सन्दर्भ डाउनलोड गर्नुहोस्
                            </a>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Field Information -->
            <div class="card mb-4">
                <div class="card-header bg-primary text-white">
                    <h5 class="mb-0"><i class="fas fa-info-circle me-2"></i>Import Fields & Validation | आयात फाइल्डहरू र प्रमाणीकरण</h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <h6><span class="feature-badge">✓ अनिवार्य | REQUIRED</span> Required Fields</h6>
                            <ul class="small">
                                <li><strong>name:</strong> Employee full name (English or Nepali)</li>
                                <li><strong>emp_type:</strong> PERMANENT, CONTRACT, or DAILY_WAGES</li>
                            </ul>
                            
                            <h6 class="mt-3"><span class="feature-badge">✓ स्वत: | AUTO</span> Auto-Generated</h6>
                            <ul class="small">
                                <li><strong>code:</strong> Auto-generated (EMP-P-0001 format)</li>
                                <li><strong>fiscal_year_id:</strong> Set to active fiscal year</li>
                                <li><strong>emp_status:</strong> Default: ACTIVE</li>
                            </ul>
                        </div>
                        <div class="col-md-6">
                            <h6><span class="feature-badge">✓ वैकल्पिक | OPTIONAL</span> Optional Fields</h6>
                            <ul class="small field-list">
                                <li>name_eng, name_nep (Nepali font supported)</li>
                                <li>department_id, designation_id, level_id</li>
                                <li>email, mobile_number (Nepal format: 98XXXXXXXX)</li>
                                <li>gender (MALE/FEMALE/OTHER)</li>
                                <li>full_address (Nepali text supported)</li>
                                <li>join_date, dob (multiple formats accepted)</li>
                                <li>citizenship_no, national_id_card_no</li>
                                <li>pan_no, bank details</li>
                                <li>state, local_body, ward_no</li>
                                <li>card_id</li>
                                <li><strong>is_technical:</strong> Yes/No/True/False/1/0/हो/होइन</li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Upload Form -->
            <div class="card">
                <div class="card-header bg-success text-white">
                    <h5 class="mb-0"><i class="fas fa-upload me-2"></i>Step 3: Upload CSV File | CSV फाइल अपलोड गर्नुहोस्</h5>
                </div>
                <div class="card-body">
                    <form method="POST" enctype="multipart/form-data" id="uploadForm">
                        <div class="mb-4">
                            <label class="form-label fw-bold">Select CSV File <span class="text-danger">*</span></label>
                            <input type="file" class="form-control form-control-lg" name="csv_file" accept=".csv" required>
                            <div class="form-text">
                                <i class="fas fa-info-circle me-1"></i>Max 5MB. UTF-8 encoded CSV only. 
                                Excel users: Save as "CSV UTF-8 (Comma delimited)" for Nepali text support.
                            </div>
                        </div>

                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger mb-4">
                                <h6><i class="fas fa-exclamation-triangle me-1"></i>Validation Errors (<?= count($errors) ?>)</h6>
                                <div class="alert-pre">
                                    <?php foreach ($errors as $error): ?>
                                        <?= htmlspecialchars($error) . "\n" ?>
                                    <?php endforeach; ?>
                                </div>
                            </div>
                        <?php endif; ?>

                        <div class="d-grid gap-2 d-md-flex justify-content-md-end">
                            <button type="reset" class="btn btn-secondary px-4">
                                <i class="fas fa-rotate me-1"></i>Reset
                            </button>
                            <button type="submit" class="btn btn-success btn-lg px-5">
                                <i class="fas fa-eye me-1"></i>Preview Data | डाटा पूर्वावलोकन गर्नुहोस्
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        <?php endif; ?>

        <!-- Success Message after import -->
        <?php if (isset($_GET['success']) && !isset($_SESSION['success_message'])): ?>
            <div class="alert alert-success mt-4">
                <h5><i class="fas fa-check-circle me-2"></i>Import Completed!</h5>
                <p>Successfully imported employees. <a href="index_enhanced.php" class="alert-link">View employees list</a></p>
            </div>
        <?php endif; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>
    <script>
        // Auto-hide alerts after 10 seconds
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert-success, .alert-warning');
            alerts.forEach(alert => {
                setTimeout(() => {
                    const bsAlert = new bootstrap.Alert(alert);
                    bsAlert.close();
                }, 10000);
            });
            
            // Confirm before canceling preview
            document.querySelectorAll('[name="cancel_preview"]').forEach(btn => {
                btn.closest('form').addEventListener('submit', function(e) {
                    if (!confirm('Are you sure you want to cancel the import preview? All preview data will be lost.')) {
                        e.preventDefault();
                    }
                });
            });
        });
    </script>
</body>
</html>
<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['action'] ?? 'create';
        
        if ($action === 'create' || $action === 'update') {
            // Validate required fields
            $required_fields = ['employee_id', 'attendance_date_nep', 'attendance_date_eng', 'status_id'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Check if attendance already exists for this employee and date
            if ($action === 'create') {
                $check_stmt = $conn->prepare("
                    SELECT id FROM attendance 
                    WHERE employee_id = :employee_id 
                    AND attendance_date_nep = :attendance_date_nep
                ");
                $check_stmt->execute([
                    ':employee_id' => $_POST['employee_id'],
                    ':attendance_date_nep' => $_POST['attendance_date_nep']
                ]);
                
                if ($check_stmt->fetch()) {
                    throw new Exception("Attendance already marked for this employee on " . $_POST['attendance_date_nep']);
                }
            }
            
            // Check if date is a holiday
            $holiday_check = $conn->prepare("
                SELECT h.holiday_name, ht.type_name 
                FROM holidays h
                JOIN holiday_types ht ON h.holiday_type_id = ht.id
                WHERE h.holiday_date_nep = :date_nep AND h.is_active = true
            ");
            $holiday_check->execute([':date_nep' => $_POST['attendance_date_nep']]);
            $holiday_info = $holiday_check->fetch(PDO::FETCH_ASSOC);
            
            $is_holiday = $holiday_info ? true : false;
            $is_weekly_off = ($_POST['status_id'] == 5); // Assuming status_id 5 is 'WO'
            
            // Prepare data
            $data = [
                ':employee_id' => $_POST['employee_id'],
                ':attendance_date_nep' => $_POST['attendance_date_nep'],
                ':attendance_date_eng' => $_POST['attendance_date_eng'],
                ':shift_id' => $_POST['shift_id'] ?: null,
                ':status_id' => $_POST['status_id'],
                ':check_in_time' => $_POST['check_in_time'] ?: null,
                ':check_out_time' => $_POST['check_out_time'] ?: null,
                ':break_hours' => $_POST['break_hours'] ?: 0,
                ':is_holiday' => $is_holiday,
                ':is_weekly_off' => $is_weekly_off,
                ':remarks' => $_POST['remarks'] ?? null,
                ':marked_by' => $_SESSION['user_id'] ?? 1
            ];
            
            if ($action === 'create') {
                $sql = "
                    INSERT INTO attendance (
                        employee_id, attendance_date_nep, attendance_date_eng,
                        shift_id, status_id, check_in_time, check_out_time,
                        break_hours, is_holiday, is_weekly_off, remarks, marked_by
                    ) VALUES (
                        :employee_id, :attendance_date_nep, :attendance_date_eng,
                        :shift_id, :status_id, :check_in_time, :check_out_time,
                        :break_hours, :is_holiday, :is_weekly_off, :remarks, :marked_by
                    )
                ";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($data);
                
                $success_message = "Attendance marked successfully!";
                
            } else if ($action === 'update') {
                $data[':id'] = $_POST['id'];
                
                $sql = "
                    UPDATE attendance SET
                        employee_id = :employee_id,
                        attendance_date_nep = :attendance_date_nep,
                        attendance_date_eng = :attendance_date_eng,
                        shift_id = :shift_id,
                        status_id = :status_id,
                        check_in_time = :check_in_time,
                        check_out_time = :check_out_time,
                        break_hours = :break_hours,
                        is_holiday = :is_holiday,
                        is_weekly_off = :is_weekly_off,
                        remarks = :remarks,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE id = :id
                ";
                
                $stmt = $conn->prepare($sql);
                $stmt->execute($data);
                
                $success_message = "Attendance updated successfully!";
            }
            
            // Update monthly summary
            $year_month = substr($_POST['attendance_date_nep'], 0, 7); // Get YYYY.MM
            $update_summary_sql = "SELECT update_monthly_summary(:emp_id, :year_month)";
            $summary_stmt = $conn->prepare($update_summary_sql);
            $summary_stmt->execute([
                ':emp_id' => $_POST['employee_id'],
                ':year_month' => $year_month
            ]);
            
        } else if ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM attendance WHERE id = :id");
            $stmt->execute([':id' => $_POST['id']]);
            $success_message = "Attendance record deleted successfully!";
        } else if ($action === 'bulk_mark') {
            // Bulk attendance marking
            $employee_ids = $_POST['employee_ids'] ?? [];
            $date_nep = $_POST['bulk_date_nep'];
            $date_eng = $_POST['bulk_date_eng'];
            $status_id = $_POST['bulk_status_id'];
            
            $count = 0;
            foreach ($employee_ids as $emp_id) {
                // Check if already marked
                $check = $conn->prepare("SELECT id FROM attendance WHERE employee_id = :emp_id AND attendance_date_nep = :date_nep");
                $check->execute([':emp_id' => $emp_id, ':date_nep' => $date_nep]);
                
                if (!$check->fetch()) {
                    $insert = $conn->prepare("
                        INSERT INTO attendance (employee_id, attendance_date_nep, attendance_date_eng, status_id, marked_by)
                        VALUES (:emp_id, :date_nep, :date_eng, :status_id, :marked_by)
                    ");
                    $insert->execute([
                        ':emp_id' => $emp_id,
                        ':date_nep' => $date_nep,
                        ':date_eng' => $date_eng,
                        ':status_id' => $status_id,
                        ':marked_by' => $_SESSION['user_id'] ?? 1
                    ]);
                    $count++;
                }
            }
            
            $success_message = "Bulk attendance marked for {$count} employees!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch dropdown data
$employees = $conn->query("
    SELECT e.id, e.code, e.name, e.name_nep, d.name as designation, l.name as level
    FROM employee e
    LEFT JOIN designation d ON e.designation_id = d.id
    LEFT JOIN level l ON e.level_id = l.id
    WHERE e.emp_status = 'ACTIVE' AND e.deleted_date IS NULL
    ORDER BY e.code
")->fetchAll(PDO::FETCH_ASSOC);

$shifts = $conn->query("SELECT * FROM shifts WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

$statuses = $conn->query("SELECT * FROM attendance_status ORDER BY id")->fetchAll(PDO::FETCH_ASSOC);

// Get recent attendance records
$recent_attendance = $conn->query("
    SELECT * FROM v_daily_attendance_report
    ORDER BY attendance_date_eng DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM attendance WHERE id = :id");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Get today's date in Nepali
$today_eng = date('Y-m-d');
$today_nep = '2082.10.30'; // This should come from a Nepali date converter
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
    margin: 0;
    padding: 0;
}

.container {
    max-width: 1600px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 10px 0;
}

.page-subtitle {
    font-size: 16px;
    opacity: 0.9;
    margin: 0;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.tab-navigation {
    display: flex;
    gap: 10px;
    margin-bottom: 25px;
    border-bottom: 2px solid #e9ecef;
    padding-bottom: 0;
}

.tab-btn {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    color: #6c757d;
    transition: all 0.3s ease;
}

.tab-btn.active {
    color: #667eea;
    border-bottom-color: #667eea;
}

.tab-btn:hover {
    color: #667eea;
    background: #f8f9fa;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.form-section {
    margin-bottom: 30px;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #495057;
}

.form-group label .required {
    color: #dc3545;
}

.form-control {
    padding: 10px 14px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
}

.form-select {
    padding: 10px 14px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
    background: white;
    cursor: pointer;
}

.info-card {
    background: #e7f3ff;
    border: 2px solid #b3d9ff;
    border-radius: 8px;
    padding: 15px;
    margin-top: 10px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    margin-bottom: 8px;
    font-size: 13px;
}

.info-label {
    font-weight: 600;
    color: #0066cc;
}

.info-value {
    color: #333;
}

.holiday-alert {
    background: #fff3cd;
    border: 2px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
    font-weight: 600;
    color: #856404;
    display: none;
}

.holiday-alert.show {
    display: block;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid #e9ecef;
}

.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    font-size: 14px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 2px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 2px solid #f5c6cb;
}

.table-container {
    background: white;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
    min-width: 1200px;
}

.data-table thead {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
}

.data-table th {
    padding: 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    color: white;
    text-transform: uppercase;
}

.data-table td {
    padding: 12px;
    font-size: 13px;
    border-bottom: 1px solid #f0f0f0;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.status-badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
    color: white;
}

.employee-selector {
    max-height: 400px;
    overflow-y: auto;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    padding: 10px;
}

.employee-item {
    padding: 10px;
    margin-bottom: 5px;
    background: #f8f9fa;
    border-radius: 4px;
    cursor: pointer;
    display: flex;
    align-items: center;
    gap: 10px;
}

.employee-item:hover {
    background: #e9ecef;
}

.employee-item input[type="checkbox"] {
    width: 18px;
    height: 18px;
    cursor: pointer;
}

.quick-actions {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    flex-wrap: wrap;
}

.stat-card {
    background: white;
    border-radius: 8px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    flex: 1;
    min-width: 200px;
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #667eea;
}

.stat-label {
    font-size: 14px;
    color: #6c757d;
    margin-top: 5px;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📅 Attendance Management System</h1>
        <p class="page-subtitle">Nepali Calendar Based Attendance Tracking</p>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <div class="tab-navigation">
            <button class="tab-btn active" onclick="switchTab('single')">👤 Single Entry</button>
            <button class="tab-btn" onclick="switchTab('bulk')">👥 Bulk Entry</button>
        </div>

        <!-- Single Entry Tab -->
        <div id="single-tab" class="tab-content active">
            <form method="POST" id="attendanceForm">
                <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
                <?php if ($edit_record): ?>
                    <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
                <?php endif; ?>

                <div class="form-section">
                    <div class="section-title">📋 Basic Information</div>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="employee_id">Employee <span class="required">*</span></label>
                            <select name="employee_id" id="employee_id" class="form-select" required>
                                <option value="">Select Employee</option>
                                <?php foreach ($employees as $emp): ?>
                                    <option value="<?= $emp['id'] ?>"
                                            data-name="<?= htmlspecialchars($emp['name']) ?>"
                                            data-designation="<?= htmlspecialchars($emp['designation']) ?>"
                                            data-level="<?= htmlspecialchars($emp['level']) ?>"
                                            <?= ($edit_record && $edit_record['employee_id'] == $emp['id']) ? 'selected' : '' ?>>
                                        <?= $emp['code'] ?> - <?= htmlspecialchars($emp['name']) ?> (<?= htmlspecialchars($emp['designation']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            
                            <div class="info-card" id="employee_info" style="display: none;">
                                <div class="info-row">
                                    <span class="info-label">Name:</span>
                                    <span class="info-value" id="emp_name">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Designation:</span>
                                    <span class="info-value" id="emp_designation">-</span>
                                </div>
                                <div class="info-row">
                                    <span class="info-label">Level:</span>
                                    <span class="info-value" id="emp_level">-</span>
                                </div>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="attendance_date_nep">Nepali Date <span class="required">*</span></label>
                            <input type="text" 
                                   name="attendance_date_nep" 
                                   id="attendance_date_nep" 
                                   class="form-control"
                                   pattern="\d{4}\.\d{2}\.\d{2}" 
                                   placeholder="YYYY.MM.DD"
                                   value="<?= $edit_record['attendance_date_nep'] ?? $today_nep ?>" 
                                   required>
                            <div class="holiday-alert" id="holiday_alert"></div>
                        </div>

                        <div class="form-group">
                            <label for="attendance_date_eng">English Date <span class="required">*</span></label>
                            <input type="date" 
                                   name="attendance_date_eng" 
                                   id="attendance_date_eng" 
                                   class="form-control"
                                   value="<?= $edit_record['attendance_date_eng'] ?? $today_eng ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="shift_id">Shift</label>
                            <select name="shift_id" id="shift_id" class="form-select">
                                <option value="">Select Shift</option>
                                <?php foreach ($shifts as $shift): ?>
                                    <option value="<?= $shift['id'] ?>"
                                            <?= ($edit_record && $edit_record['shift_id'] == $shift['id']) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($shift['name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="status_id">Status <span class="required">*</span></label>
                            <select name="status_id" id="status_id" class="form-select" required>
                                <option value="">Select Status</option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= $status['id'] ?>"
                                            data-code="<?= $status['status_code'] ?>"
                                            data-color="<?= $status['color_code'] ?>"
                                            <?= ($edit_record && $edit_record['status_id'] == $status['id']) ? 'selected' : '' ?>>
                                        <?= $status['status_code'] ?> - <?= htmlspecialchars($status['status_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section" id="time_section">
                    <div class="section-title">⏰ Time Information</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="check_in_time">Check-In Time</label>
                            <input type="time" 
                                   name="check_in_time" 
                                   id="check_in_time" 
                                   class="form-control"
                                   value="<?= $edit_record['check_in_time'] ?? '' ?>">
                        </div>

                        <div class="form-group">
                            <label for="check_out_time">Check-Out Time</label>
                            <input type="time" 
                                   name="check_out_time" 
                                   id="check_out_time" 
                                   class="form-control"
                                   value="<?= $edit_record['check_out_time'] ?? '' ?>">
                        </div>

                        <div class="form-group">
                            <label for="break_hours">Break Hours</label>
                            <input type="number" 
                                   name="break_hours" 
                                   id="break_hours" 
                                   class="form-control"
                                   step="0.25"
                                   min="0"
                                   max="4"
                                   value="<?= $edit_record['break_hours'] ?? '0' ?>">
                        </div>

                        <div class="form-group">
                            <label>Calculated Working Hours</label>
                            <input type="text" 
                                   id="calc_working_hours" 
                                   class="form-control" 
                                   readonly
                                   style="background: #e9ecef; font-weight: 600;">
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="remarks">Remarks</label>
                            <textarea name="remarks" 
                                      id="remarks" 
                                      class="form-control" 
                                      rows="3"><?= htmlspecialchars($edit_record['remarks'] ?? '') ?></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <?php if ($edit_record): ?>
                        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                    <?php endif; ?>
                    <button type="submit" class="btn btn-primary">
                        <?= $edit_record ? '💾 Update Attendance' : '✅ Mark Attendance' ?>
                    </button>
                </div>
            </form>
        </div>

        <!-- Bulk Entry Tab -->
        <div id="bulk-tab" class="tab-content">
            <form method="POST" id="bulkForm">
                <input type="hidden" name="action" value="bulk_mark">

                <div class="form-section">
                    <div class="section-title">📅 Bulk Attendance Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="bulk_date_nep">Nepali Date <span class="required">*</span></label>
                            <input type="text" 
                                   name="bulk_date_nep" 
                                   id="bulk_date_nep" 
                                   class="form-control"
                                   pattern="\d{4}\.\d{2}\.\d{2}" 
                                   placeholder="YYYY.MM.DD"
                                   value="<?= $today_nep ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="bulk_date_eng">English Date <span class="required">*</span></label>
                            <input type="date" 
                                   name="bulk_date_eng" 
                                   id="bulk_date_eng" 
                                   class="form-control"
                                   value="<?= $today_eng ?>" 
                                   required>
                        </div>

                        <div class="form-group">
                            <label for="bulk_status_id">Status <span class="required">*</span></label>
                            <select name="bulk_status_id" id="bulk_status_id" class="form-select" required>
                                <option value="">Select Status</option>
                                <?php foreach ($statuses as $status): ?>
                                    <option value="<?= $status['id'] ?>">
                                        <?= $status['status_code'] ?> - <?= htmlspecialchars($status['status_name']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">
                        👥 Select Employees
                        <button type="button" class="btn btn-sm btn-info" onclick="selectAllEmployees()">Select All</button>
                        <button type="button" class="btn btn-sm btn-secondary" onclick="deselectAllEmployees()">Deselect All</button>
                    </div>
                    
                    <div class="employee-selector" id="employee_selector">
                        <?php foreach ($employees as $emp): ?>
                            <div class="employee-item">
                                <input type="checkbox" 
                                       name="employee_ids[]" 
                                       value="<?= $emp['id'] ?>"
                                       id="emp_<?= $emp['id'] ?>">
                                <label for="emp_<?= $emp['id'] ?>" style="margin: 0; cursor: pointer; flex: 1;">
                                    <strong><?= $emp['code'] ?></strong> - 
                                    <?= htmlspecialchars($emp['name']) ?> 
                                    (<?= htmlspecialchars($emp['designation']) ?>)
                                </label>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">
                        ✅ Mark Bulk Attendance
                    </button>
                </div>
            </form>
        </div>
    </div>

    <!-- Recent Attendance Table -->
    <div class="table-container">
        <h3 style="padding: 20px; margin: 0; background: #f8f9fa; border-bottom: 2px solid #e9ecef;">
            📊 Recent Attendance Records
        </h3>
        <table class="data-table">
            <thead>
                <tr>
                    <th>Date (Nep)</th>
                    <th>Date (Eng)</th>
                    <th>Employee</th>
                    <th>Designation</th>
                    <th>Shift</th>
                    <th>Status</th>
                    <th>Check-In</th>
                    <th>Check-Out</th>
                    <th>Working Hrs</th>
                    <th>OT Hrs</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($recent_attendance)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 40px;">
                            No attendance records found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($recent_attendance as $record): ?>
                        <tr>
                            <td><?= htmlspecialchars($record['attendance_date_nep']) ?></td>
                            <td><?= date('Y-m-d', strtotime($record['attendance_date_eng'])) ?></td>
                            <td>
                                <strong><?= $record['employee_code'] ?></strong><br>
                                <small><?= htmlspecialchars($record['employee_name']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($record['designation']) ?></td>
                            <td><?= htmlspecialchars($record['shift']) ?></td>
                            <td>
                                <span class="status-badge" style="background: <?= $record['color_code'] ?>">
                                    <?= $record['status_code'] ?>
                                </span>
                            </td>
                            <td><?= $record['check_in_time'] ?: '-' ?></td>
                            <td><?= $record['check_out_time'] ?: '-' ?></td>
                            <td><?= number_format($record['actual_working_hours'], 2) ?></td>
                            <td><?= number_format($record['ot_hours'], 2) ?></td>
                            <td>
                                <a href="?edit_id=<?= $record['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Delete this record?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="id" value="<?= $record['id'] ?>">
                                    <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
function switchTab(tab) {
    // Hide all tabs
    document.querySelectorAll('.tab-content').forEach(el => el.classList.remove('active'));
    document.querySelectorAll('.tab-btn').forEach(el => el.classList.remove('active'));
    
    // Show selected tab
    document.getElementById(tab + '-tab').classList.add('active');
    event.target.classList.add('active');
}

// Employee selection
document.getElementById('employee_id').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    const infoCard = document.getElementById('employee_info');
    
    if (this.value) {
        document.getElementById('emp_name').textContent = option.dataset.name;
        document.getElementById('emp_designation').textContent = option.dataset.designation;
        document.getElementById('emp_level').textContent = option.dataset.level;
        infoCard.style.display = 'block';
    } else {
        infoCard.style.display = 'none';
    }
});

// Check for holidays
document.getElementById('attendance_date_nep').addEventListener('blur', function() {
    const dateNep = this.value;
    if (dateNep) {
        fetch('check_holiday.php?date=' + encodeURIComponent(dateNep))
            .then(res => res.json())
            .then(data => {
                const alert = document.getElementById('holiday_alert');
                if (data.is_holiday) {
                    alert.textContent = '⚠️ ' + data.holiday_name + ' (' + data.type_name + ')';
                    alert.classList.add('show');
                } else {
                    alert.classList.remove('show');
                }
            });
    }
});

// Calculate working hours
function calculateWorkingHours() {
    const checkIn = document.getElementById('check_in_time').value;
    const checkOut = document.getElementById('check_out_time').value;
    const breakHours = parseFloat(document.getElementById('break_hours').value) || 0;
    
    if (checkIn && checkOut) {
        const inTime = new Date('2000-01-01 ' + checkIn);
        const outTime = new Date('2000-01-01 ' + checkOut);
        
        const diffMs = outTime - inTime;
        const diffHrs = diffMs / (1000 * 60 * 60);
        const workingHrs = Math.max(0, diffHrs - breakHours);
        
        document.getElementById('calc_working_hours').value = workingHrs.toFixed(2) + ' hours';
    }
}

document.getElementById('check_in_time').addEventListener('change', calculateWorkingHours);
document.getElementById('check_out_time').addEventListener('change', calculateWorkingHours);
document.getElementById('break_hours').addEventListener('input', calculateWorkingHours);

// Show/hide time section based on status
document.getElementById('status_id').addEventListener('change', function() {
    const option = this.options[this.selectedIndex];
    const code = option.dataset.code;
    const timeSection = document.getElementById('time_section');
    
    // Show time section only for Present, Half Day
    if (code === 'P' || code === 'HD') {
        timeSection.style.display = 'block';
    } else {
        timeSection.style.display = 'none';
    }
});

// Bulk selection functions
function selectAllEmployees() {
    document.querySelectorAll('#employee_selector input[type="checkbox"]').forEach(cb => {
        cb.checked = true;
    });
}

function deselectAllEmployees() {
    document.querySelectorAll('#employee_selector input[type="checkbox"]').forEach(cb => {
        cb.checked = false;
    });
}

// Initialize
document.addEventListener('DOMContentLoaded', function() {
    if (document.getElementById('employee_id').value) {
        document.getElementById('employee_id').dispatchEvent(new Event('change'));
    }
    
    if (document.getElementById('status_id').value) {
        document.getElementById('status_id').dispatchEvent(new Event('change'));
    }
    
    calculateWorkingHours();
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

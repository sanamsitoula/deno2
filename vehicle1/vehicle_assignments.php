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
        
        if ($action === 'create') {
            $required_fields = ['vehicle_id', 'driver_id', 'assigned_from_date_nep', 
                              'assigned_from_date_eng', 'fiscal_year', 'created_by'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Check if vehicle already has active assignment
            $check_stmt = $conn->prepare("
                SELECT assignment_id FROM vehicle_driver_assignments 
                WHERE vehicle_id = :vehicle_id AND active_flag = TRUE AND deleted_at IS NULL
            ");
            $check_stmt->execute([':vehicle_id' => $_POST['vehicle_id']]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("Vehicle already has an active driver assignment. Please end the current assignment first.");
            }
            
            $insert_sql = "
                INSERT INTO vehicle_driver_assignments (
                    vehicle_id, driver_id, assigned_from_date_nep, assigned_from_date_eng,
                    assigned_to_date_nep, assigned_to_date_eng, active_flag, remarks,
                    fiscal_year, created_by
                ) VALUES (
                    :vehicle_id, :driver_id, :assigned_from_date_nep, :assigned_from_date_eng,
                    :assigned_to_date_nep, :assigned_to_date_eng, :active_flag, :remarks,
                    :fiscal_year, :created_by
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':vehicle_id' => $_POST['vehicle_id'],
                ':driver_id' => $_POST['driver_id'],
                ':assigned_from_date_nep' => $_POST['assigned_from_date_nep'],
                ':assigned_from_date_eng' => $_POST['assigned_from_date_eng'],
                ':assigned_to_date_nep' => $_POST['assigned_to_date_nep'] ?: null,
                ':assigned_to_date_eng' => $_POST['assigned_to_date_eng'] ?: null,
                ':active_flag' => isset($_POST['active_flag']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':created_by' => $_POST['created_by']
            ]);
            
            $success_message = "Assignment created successfully!";
            
        } elseif ($action === 'update') {
            $update_sql = "
                UPDATE vehicle_driver_assignments SET 
                    vehicle_id = :vehicle_id,
                    driver_id = :driver_id,
                    assigned_from_date_nep = :assigned_from_date_nep,
                    assigned_from_date_eng = :assigned_from_date_eng,
                    assigned_to_date_nep = :assigned_to_date_nep,
                    assigned_to_date_eng = :assigned_to_date_eng,
                    active_flag = :active_flag,
                    remarks = :remarks,
                    fiscal_year = :fiscal_year,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE assignment_id = :assignment_id
            ";
            
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([
                ':assignment_id' => $_POST['assignment_id'],
                ':vehicle_id' => $_POST['vehicle_id'],
                ':driver_id' => $_POST['driver_id'],
                ':assigned_from_date_nep' => $_POST['assigned_from_date_nep'],
                ':assigned_from_date_eng' => $_POST['assigned_from_date_eng'],
                ':assigned_to_date_nep' => $_POST['assigned_to_date_nep'] ?: null,
                ':assigned_to_date_eng' => $_POST['assigned_to_date_eng'] ?: null,
                ':active_flag' => isset($_POST['active_flag']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $success_message = "Assignment updated successfully!";
            
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE vehicle_driver_assignments SET deleted_at = CURRENT_TIMESTAMP WHERE assignment_id = :assignment_id");
            $stmt->execute([':assignment_id' => $_POST['assignment_id']]);
            $success_message = "Assignment deleted successfully!";
            
        } elseif ($action === 'end_assignment') {
            $stmt = $conn->prepare("
                UPDATE vehicle_driver_assignments 
                SET active_flag = FALSE,
                    assigned_to_date_nep = :to_date_nep,
                    assigned_to_date_eng = :to_date_eng,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE assignment_id = :assignment_id
            ");
            $stmt->execute([
                ':assignment_id' => $_POST['assignment_id'],
                ':to_date_nep' => $_POST['to_date_nep'],
                ':to_date_eng' => $_POST['to_date_eng'],
                ':updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            $success_message = "Assignment ended successfully!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch dropdown data
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type 
    FROM vehicles 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

$drivers = $conn->query("
    SELECT driver_id, driver_name, mobile_no, license_no 
    FROM drivers 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY driver_name
")->fetchAll(PDO::FETCH_ASSOC);

$users = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Fetch assignments
$assignments = $conn->query("
    SELECT 
        vda.*,
        v.vehicle_no,
        v.vehicle_type,
        d.driver_name,
        d.mobile_no,
        d.license_no,
        u.username as created_by_username
    FROM vehicle_driver_assignments vda
    JOIN vehicles v ON vda.vehicle_id = v.vehicle_id
    JOIN drivers d ON vda.driver_id = d.driver_id
    LEFT JOIN users u ON vda.created_by = u.id
    WHERE vda.deleted_at IS NULL
    ORDER BY vda.active_flag DESC, vda.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM vehicle_driver_assignments WHERE assignment_id = :assignment_id AND deleted_at IS NULL");
    $stmt->execute([':assignment_id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    margin-bottom: 30px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
    margin-bottom: 10px;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.info-box {
    background: #e7f3ff;
    border: 1px solid #b3d9ff;
    border-radius: 8px;
    padding: 15px;
    margin-top: 10px;
}

.info-box.hidden {
    display: none;
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

.checkbox-group {
    display: flex;
    align-items: center;
    gap: 10px;
}

.checkbox-group input[type="checkbox"] {
    width: 20px;
    height: 20px;
    cursor: pointer;
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

.btn-primary { background: #007bff; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; color: white; }
.btn-success { background: #28a745; color: white; }
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
    border-top: 1px solid #e9ecef;
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
    border: 1px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.data-table-container {
    background: white;
    border-radius: 12px;
    overflow-x: auto;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table thead {
    background: #f8f9fa;
}

.data-table th {
    padding: 12px;
    text-align: left;
    font-size: 12px;
    font-weight: 700;
    color: #495057;
    text-transform: uppercase;
    border-bottom: 2px solid #dee2e6;
}

.data-table td {
    padding: 12px;
    font-size: 14px;
    border-bottom: 1px solid #f0f0f0;
}

.data-table tbody tr:hover {
    background: #f8f9fa;
}

.badge {
    display: inline-block;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: 600;
}

.badge-active { background: #d4edda; color: #155724; }
.badge-inactive { background: #f8d7da; color: #721c24; }

.hidden {
    display: none;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">🔄 <?= $edit_record ? 'Edit' : 'Create' ?> Vehicle-Driver Assignment</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="assignmentForm">
            <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
            <?php if ($edit_record): ?>
                <input type="hidden" name="assignment_id" value="<?= $edit_record['assignment_id'] ?>">
            <?php endif; ?>

            <!-- Vehicle & Driver Selection -->
            <div class="form-section">
                <div class="section-title">🚗 Vehicle & Driver</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="vehicle_id">Vehicle <span class="required">*</span></label>
                        <select name="vehicle_id" id="vehicle_id" class="form-control" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?= $vehicle['vehicle_id'] ?>"
                                        data-type="<?= $vehicle['vehicle_type'] ?>"
                                        <?= ($edit_record && $edit_record['vehicle_id'] == $vehicle['vehicle_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($vehicle['vehicle_no']) ?> (<?= ucfirst($vehicle['vehicle_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div class="info-box hidden" id="vehicle_info">
                            <div class="info-row">
                                <span class="info-label">Vehicle Type:</span>
                                <span class="info-value" id="vehicle_type">-</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="driver_id">Driver <span class="required">*</span></label>
                        <select name="driver_id" id="driver_id" class="form-control" required>
                            <option value="">Select Driver</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['driver_id'] ?>"
                                        data-name="<?= htmlspecialchars($driver['driver_name']) ?>"
                                        data-mobile="<?= htmlspecialchars($driver['mobile_no']) ?>"
                                        data-license="<?= htmlspecialchars($driver['license_no']) ?>"
                                        <?= ($edit_record && $edit_record['driver_id'] == $driver['driver_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['driver_name']) ?> (<?= $driver['mobile_no'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                        
                        <div class="info-box hidden" id="driver_info">
                            <div class="info-row">
                                <span class="info-label">Driver Name:</span>
                                <span class="info-value" id="driver_name">-</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Mobile:</span>
                                <span class="info-value" id="driver_mobile">-</span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">License No:</span>
                                <span class="info-value" id="driver_license">-</span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Assignment Dates -->
            <div class="form-section">
                <div class="section-title">📅 Assignment Period</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="assigned_from_date_nep">Assigned From (Nepali) <span class="required">*</span></label>
                        <input type="text" name="assigned_from_date_nep" id="assigned_from_date_nep" 
                               class="form-control" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.01"
                               value="<?= htmlspecialchars($edit_record['assigned_from_date_nep'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="assigned_from_date_eng">Assigned From (English) <span class="required">*</span></label>
                        <input type="date" name="assigned_from_date_eng" id="assigned_from_date_eng" 
                               class="form-control"
                               value="<?= htmlspecialchars($edit_record['assigned_from_date_eng'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="assigned_to_date_nep">Assigned To (Nepali)</label>
                        <input type="text" name="assigned_to_date_nep" id="assigned_to_date_nep" 
                               class="form-control" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.06.30"
                               value="<?= htmlspecialchars($edit_record['assigned_to_date_nep'] ?? '') ?>">
                        <small style="color: #6c757d; margin-top: 5px;">Leave empty if currently active</small>
                    </div>

                    <div class="form-group">
                        <label for="assigned_to_date_eng">Assigned To (English)</label>
                        <input type="date" name="assigned_to_date_eng" id="assigned_to_date_eng" 
                               class="form-control"
                               value="<?= htmlspecialchars($edit_record['assigned_to_date_eng'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <!-- Additional Info -->
            <div class="form-section">
                <div class="section-title">📋 Additional Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="fiscal_year">Fiscal Year <span class="required">*</span></label>
                        <select name="fiscal_year" id="fiscal_year" class="form-control" required>
                            <option value="2082/83" <?= (!$edit_record || $edit_record['fiscal_year'] === '2082/83') ? 'selected' : '' ?>>2082/83</option>
                            <option value="2083/84" <?= ($edit_record && $edit_record['fiscal_year'] === '2083/84') ? 'selected' : '' ?>>2083/84</option>
                            <option value="2084/85" <?= ($edit_record && $edit_record['fiscal_year'] === '2084/85') ? 'selected' : '' ?>>2084/85</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="created_by">Created By <span class="required">*</span></label>
                        <select name="created_by" id="created_by" class="form-control" required>
                            <option value="">Select User</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= ($edit_record && $edit_record['created_by'] == $user['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="active_flag">Status</label>
                        <div class="checkbox-group">
                            <input type="checkbox" name="active_flag" id="active_flag" 
                                   <?= (!$edit_record || $edit_record['active_flag']) ? 'checked' : '' ?>>
                            <label for="active_flag" style="margin: 0;">Active Assignment</label>
                        </div>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="remarks">Remarks</label>
                        <textarea name="remarks" id="remarks" class="form-control" rows="2"><?= htmlspecialchars($edit_record['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="form-actions">
                <?php if ($edit_record): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <?= $edit_record ? '💾 Update Assignment' : '✅ Create Assignment' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Assignments Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>License No</th>
                    <th>Assigned From</th>
                    <th>Assigned To</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($assignments)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px;">No assignments found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($assignments as $assignment): ?>
                        <tr>
                            <td><?= $assignment['assignment_id'] ?></td>
                            <td>
                                <strong><?= htmlspecialchars($assignment['vehicle_no']) ?></strong>
                                <br><small style="color: #6c757d;"><?= ucfirst($assignment['vehicle_type']) ?></small>
                            </td>
                            <td>
                                <div><?= htmlspecialchars($assignment['driver_name']) ?></div>
                                <small style="color: #6c757d;"><?= $assignment['mobile_no'] ?></small>
                            </td>
                            <td><?= htmlspecialchars($assignment['license_no']) ?></td>
                            <td><?= htmlspecialchars($assignment['assigned_from_date_nep']) ?></td>
                            <td>
                                <?php if ($assignment['assigned_to_date_nep']): ?>
                                    <?= htmlspecialchars($assignment['assigned_to_date_nep']) ?>
                                <?php else: ?>
                                    <span style="color: #28a745; font-weight: 600;">Current</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $assignment['active_flag'] ? 'active' : 'inactive' ?>">
                                    <?= $assignment['active_flag'] ? 'Active' : 'Ended' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($assignment['created_by_username']) ?></td>
                            <td>
                                <a href="?edit_id=<?= $assignment['assignment_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                
                                <?php if ($assignment['active_flag']): ?>
                                    <button type="button" class="btn btn-success btn-sm" 
                                            onclick="endAssignment(<?= $assignment['assignment_id'] ?>)">End</button>
                                <?php endif; ?>
                                
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this assignment?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="assignment_id" value="<?= $assignment['assignment_id'] ?>">
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

<!-- End Assignment Modal -->
<div id="endAssignmentModal" style="display: none; position: fixed; top: 0; left: 0; right: 0; bottom: 0; background: rgba(0,0,0,0.5); z-index: 1000; align-items: center; justify-content: center;">
    <div style="background: white; border-radius: 12px; padding: 30px; max-width: 500px; width: 90%;">
        <h3 style="margin-top: 0;">End Assignment</h3>
        <form method="POST" id="endAssignmentForm">
            <input type="hidden" name="action" value="end_assignment">
            <input type="hidden" name="assignment_id" id="end_assignment_id">
            
            <div class="form-group" style="margin-bottom: 15px;">
                <label for="to_date_nep">End Date (Nepali) <span class="required">*</span></label>
                <input type="text" name="to_date_nep" id="to_date_nep" 
                       class="form-control" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.06.30" required>
            </div>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label for="to_date_eng">End Date (English) <span class="required">*</span></label>
                <input type="date" name="to_date_eng" id="to_date_eng" class="form-control" required>
            </div>
            
            <div style="display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" class="btn btn-secondary" onclick="closeEndModal()">Cancel</button>
                <button type="submit" class="btn btn-success">End Assignment</button>
            </div>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const vehicleSelect = document.getElementById('vehicle_id');
    const vehicleInfo = document.getElementById('vehicle_info');
    const driverSelect = document.getElementById('driver_id');
    const driverInfo = document.getElementById('driver_info');
    
    // Show vehicle info
    vehicleSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('vehicle_type').textContent = option.dataset.type;
            vehicleInfo.classList.remove('hidden');
        } else {
            vehicleInfo.classList.add('hidden');
        }
    });
    
    // Show driver info
    driverSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('driver_name').textContent = option.dataset.name;
            document.getElementById('driver_mobile').textContent = option.dataset.mobile;
            document.getElementById('driver_license').textContent = option.dataset.license;
            driverInfo.classList.remove('hidden');
        } else {
            driverInfo.classList.add('hidden');
        }
    });
    
    // Initialize
    if (vehicleSelect.value) vehicleSelect.dispatchEvent(new Event('change'));
    if (driverSelect.value) driverSelect.dispatchEvent(new Event('change'));
});

function endAssignment(assignmentId) {
    document.getElementById('end_assignment_id').value = assignmentId;
    document.getElementById('endAssignmentModal').style.display = 'flex';
}

function closeEndModal() {
    document.getElementById('endAssignmentModal').style.display = 'none';
    document.getElementById('endAssignmentForm').reset();
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

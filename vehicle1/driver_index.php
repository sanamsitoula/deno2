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
            $required_fields = ['driver_name', 'mobile_no', 'license_no', 'fiscal_year', 'created_by'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Check for duplicate license number
            $check_stmt = $conn->prepare("
                SELECT driver_id FROM drivers 
                WHERE license_no = :license_no AND deleted_at IS NULL
            ");
            $check_stmt->execute([':license_no' => $_POST['license_no']]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("License number already exists.");
            }
            
            $insert_sql = "
                INSERT INTO drivers (
                    driver_name, mobile_no, license_no, status, remarks, 
                    fiscal_year, created_by
                ) VALUES (
                    :driver_name, :mobile_no, :license_no, :status, :remarks,
                    :fiscal_year, :created_by
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':driver_name' => $_POST['driver_name'],
                ':mobile_no' => $_POST['mobile_no'],
                ':license_no' => strtoupper($_POST['license_no']),
                ':status' => isset($_POST['status']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':created_by' => $_POST['created_by']
            ]);
            
            $success_message = "Driver registered successfully!";
            
        } elseif ($action === 'update') {
            $check_stmt = $conn->prepare("
                SELECT driver_id FROM drivers 
                WHERE license_no = :license_no AND driver_id != :driver_id AND deleted_at IS NULL
            ");
            $check_stmt->execute([
                ':license_no' => $_POST['license_no'],
                ':driver_id' => $_POST['driver_id']
            ]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("License number already exists.");
            }
            
            $update_sql = "
                UPDATE drivers SET 
                    driver_name = :driver_name,
                    mobile_no = :mobile_no,
                    license_no = :license_no,
                    status = :status,
                    remarks = :remarks,
                    fiscal_year = :fiscal_year,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE driver_id = :driver_id
            ";
            
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([
                ':driver_id' => $_POST['driver_id'],
                ':driver_name' => $_POST['driver_name'],
                ':mobile_no' => $_POST['mobile_no'],
                ':license_no' => strtoupper($_POST['license_no']),
                ':status' => isset($_POST['status']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $success_message = "Driver updated successfully!";
            
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE drivers SET deleted_at = CURRENT_TIMESTAMP WHERE driver_id = :driver_id");
            $stmt->execute([':driver_id' => $_POST['driver_id']]);
            $success_message = "Driver deleted successfully!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

$users = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

$drivers = $conn->query("
    SELECT 
        d.*,
        u.username as created_by_username,
        v.vehicle_no as current_vehicle,
        vda.assigned_from_date_nep
    FROM drivers d
    LEFT JOIN users u ON d.created_by = u.id
    LEFT JOIN vehicle_driver_assignments vda ON d.driver_id = vda.driver_id 
        AND vda.active_flag = TRUE AND vda.deleted_at IS NULL
    LEFT JOIN vehicles v ON vda.vehicle_id = v.vehicle_id AND v.deleted_at IS NULL
    WHERE d.deleted_at IS NULL
    ORDER BY d.created_at DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM drivers WHERE driver_id = :driver_id AND deleted_at IS NULL");
    $stmt->execute([':driver_id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<style>



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
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; color: white; }
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
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">👨‍✈️ <?= $edit_record ? 'Edit' : 'Register New' ?> Driver</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="driverForm">
            <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
            <?php if ($edit_record): ?>
                <input type="hidden" name="driver_id" value="<?= $edit_record['driver_id'] ?>">
            <?php endif; ?>

            <div class="form-section">
                <div class="section-title">👤 Driver Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="driver_name">Driver Name <span class="required">*</span></label>
                        <input type="text" name="driver_name" id="driver_name" class="form-control"
                               placeholder="Ram Bahadur Shrestha"
                               value="<?= htmlspecialchars($edit_record['driver_name'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="mobile_no">Mobile Number <span class="required">*</span></label>
                        <input type="text" name="mobile_no" id="mobile_no" class="form-control"
                               placeholder="9841234567" pattern="[0-9]{10}"
                               value="<?= htmlspecialchars($edit_record['mobile_no'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="license_no">License Number <span class="required">*</span></label>
                        <input type="text" name="license_no" id="license_no" class="form-control"
                               placeholder="DL-001-2080" style="text-transform: uppercase;"
                               value="<?= htmlspecialchars($edit_record['license_no'] ?? '') ?>" required>
                    </div>

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
                        <label for="status">Status</label>
                        <div class="checkbox-group">
                            <input type="checkbox" name="status" id="status" 
                                   <?= (!$edit_record || $edit_record['status']) ? 'checked' : '' ?>>
                            <label for="status" style="margin: 0;">Active</label>
                        </div>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="remarks">Remarks</label>
                        <textarea name="remarks" id="remarks" class="form-control" rows="3"><?= htmlspecialchars($edit_record['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <?php if ($edit_record): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <?= $edit_record ? '💾 Update Driver' : '✅ Register Driver' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Drivers List -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Driver Name</th>
                    <th>Mobile</th>
                    <th>License No</th>
                    <th>Current Vehicle</th>
                    <th>Assigned Since</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($drivers)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px;">No drivers registered</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($drivers as $driver): ?>
                        <tr>
                            <td><?= $driver['driver_id'] ?></td>
                            <td><strong><?= htmlspecialchars($driver['driver_name']) ?></strong></td>
                            <td><?= htmlspecialchars($driver['mobile_no']) ?></td>
                            <td><?= htmlspecialchars($driver['license_no']) ?></td>
                            <td>
                                <?php if ($driver['current_vehicle']): ?>
                                    <strong><?= htmlspecialchars($driver['current_vehicle']) ?></strong>
                                <?php else: ?>
                                    <span style="color: #999;">Not assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($driver['assigned_from_date_nep']): ?>
                                    <?= htmlspecialchars($driver['assigned_from_date_nep']) ?>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $driver['status'] ? 'active' : 'inactive' ?>">
                                    <?= $driver['status'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($driver['created_by_username']) ?></td>
                            <td>
                                <a href="?edit_id=<?= $driver['driver_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this driver?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="driver_id" value="<?= $driver['driver_id'] ?>">
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
document.addEventListener('DOMContentLoaded', function() {
    const licenseNo = document.getElementById('license_no');
    const mobileNo = document.getElementById('mobile_no');
    
    licenseNo.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
    
    mobileNo.addEventListener('input', function() {
        this.value = this.value.replace(/[^0-9]/g, '');
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

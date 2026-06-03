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
            // Validate required fields
            $required_fields = ['vehicle_no', 'vehicle_type', 'fuel_type', 'fuel_per_liter_standard', 'fiscal_year', 'created_by'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Check for duplicate vehicle number
            $check_stmt = $conn->prepare("
                SELECT vehicle_id FROM vehicles 
                WHERE vehicle_no = :vehicle_no AND deleted_at IS NULL
            ");
            $check_stmt->execute([':vehicle_no' => $_POST['vehicle_no']]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("Vehicle number " . htmlspecialchars($_POST['vehicle_no']) . " already exists.");
            }
            
            $insert_sql = "
                INSERT INTO vehicles (
                    vehicle_no, vehicle_type, fuel_type, fuel_per_liter_standard,
                    status, remarks, fiscal_year, created_by
                ) VALUES (
                    :vehicle_no, :vehicle_type, :fuel_type, :fuel_per_liter_standard,
                    :status, :remarks, :fiscal_year, :created_by
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':vehicle_no' => strtoupper($_POST['vehicle_no']),
                ':vehicle_type' => $_POST['vehicle_type'],
                ':fuel_type' => $_POST['fuel_type'],
                ':fuel_per_liter_standard' => $_POST['fuel_per_liter_standard'],
                ':status' => isset($_POST['status']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':created_by' => $_POST['created_by']
            ]);
            
            $success_message = "Vehicle registered successfully!";
            
        } elseif ($action === 'update') {
            // Check for duplicate vehicle number (excluding current record)
            $check_stmt = $conn->prepare("
                SELECT vehicle_id FROM vehicles 
                WHERE vehicle_no = :vehicle_no AND vehicle_id != :vehicle_id AND deleted_at IS NULL
            ");
            $check_stmt->execute([
                ':vehicle_no' => $_POST['vehicle_no'],
                ':vehicle_id' => $_POST['vehicle_id']
            ]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("Vehicle number already exists.");
            }
            
            $update_sql = "
                UPDATE vehicles SET 
                    vehicle_no = :vehicle_no,
                    vehicle_type = :vehicle_type,
                    fuel_type = :fuel_type,
                    fuel_per_liter_standard = :fuel_per_liter_standard,
                    status = :status,
                    remarks = :remarks,
                    fiscal_year = :fiscal_year,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE vehicle_id = :vehicle_id
            ";
            
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([
                ':vehicle_id' => $_POST['vehicle_id'],
                ':vehicle_no' => strtoupper($_POST['vehicle_no']),
                ':vehicle_type' => $_POST['vehicle_type'],
                ':fuel_type' => $_POST['fuel_type'],
                ':fuel_per_liter_standard' => $_POST['fuel_per_liter_standard'],
                ':status' => isset($_POST['status']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $success_message = "Vehicle updated successfully!";
            
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE vehicles SET deleted_at = CURRENT_TIMESTAMP WHERE vehicle_id = :vehicle_id");
            $stmt->execute([':vehicle_id' => $_POST['vehicle_id']]);
            $success_message = "Vehicle deleted successfully!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch users for dropdowns
$users = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Fetch vehicles
$vehicles = $conn->query("
    SELECT * FROM v_vehicle_full_details
    ORDER BY created_at DESC 
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM vehicles WHERE vehicle_id = :vehicle_id AND deleted_at IS NULL");
    $stmt->execute([':vehicle_id' => $_GET['edit_id']]);
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
.badge-car { background: #cce5ff; color: #004085; }
.badge-jeep { background: #d4edda; color: #155724; }
.badge-bike { background: #fff3cd; color: #856404; }
.badge-truck { background: #d1ecf1; color: #0c5460; }
.badge-generator { background: #f8d7da; color: #721c24; }
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">🚗 <?= $edit_record ? 'Edit' : 'Register New' ?> Vehicle</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="vehicleForm">
            <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
            <?php if ($edit_record): ?>
                <input type="hidden" name="vehicle_id" value="<?= $edit_record['vehicle_id'] ?>">
            <?php endif; ?>

            <div class="form-section">
                <div class="section-title">🚙 Vehicle Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="vehicle_no">Vehicle Number <span class="required">*</span></label>
                        <input type="text" name="vehicle_no" id="vehicle_no" class="form-control"
                               placeholder="BA 1 KHA 1234" style="text-transform: uppercase;"
                               value="<?= htmlspecialchars($edit_record['vehicle_no'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="vehicle_type">Vehicle Type <span class="required">*</span></label>
                        <select name="vehicle_type" id="vehicle_type" class="form-control" required>
                            <option value="">Select Type</option>
                            <option value="car" <?= ($edit_record && $edit_record['vehicle_type'] === 'car') ? 'selected' : '' ?>>Car</option>
                            <option value="jeep" <?= ($edit_record && $edit_record['vehicle_type'] === 'jeep') ? 'selected' : '' ?>>Jeep</option>
                            <option value="bike" <?= ($edit_record && $edit_record['vehicle_type'] === 'bike') ? 'selected' : '' ?>>Bike</option>
                            <option value="truck" <?= ($edit_record && $edit_record['vehicle_type'] === 'truck') ? 'selected' : '' ?>>Truck</option>
                            <option value="generator" <?= ($edit_record && $edit_record['vehicle_type'] === 'generator') ? 'selected' : '' ?>>Generator</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="fuel_type">Fuel Type <span class="required">*</span></label>
                        <select name="fuel_type" id="fuel_type" class="form-control" required>
                            <option value="">Select Fuel</option>
                            <option value="petrol" <?= ($edit_record && $edit_record['fuel_type'] === 'petrol') ? 'selected' : '' ?>>Petrol</option>
                            <option value="diesel" <?= ($edit_record && $edit_record['fuel_type'] === 'diesel') ? 'selected' : '' ?>>Diesel</option>
                            <option value="mobil" <?= ($edit_record && $edit_record['fuel_type'] === 'mobil') ? 'selected' : '' ?>>Mobil</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="fuel_per_liter_standard">Standard Mileage (km/liter) <span class="required">*</span></label>
                        <input type="number" step="0.01" name="fuel_per_liter_standard" id="fuel_per_liter_standard" 
                               class="form-control" placeholder="12.5"
                               value="<?= htmlspecialchars($edit_record['fuel_per_liter_standard'] ?? '') ?>" required>
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
                    <?= $edit_record ? '💾 Update Vehicle' : '✅ Register Vehicle' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Vehicles List -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Vehicle No</th>
                    <th>Type</th>
                    <th>Fuel Type</th>
                    <th>Mileage (km/L)</th>
                    <th>Current Driver</th>
                    <th>Status</th>
                    <th>Fiscal Year</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($vehicles)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px;">No vehicles registered</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($vehicles as $vehicle): ?>
                        <tr>
                            <td><?= $vehicle['vehicle_id'] ?></td>
                            <td><strong><?= htmlspecialchars($vehicle['vehicle_no']) ?></strong></td>
                            <td>
                                <span class="badge badge-<?= $vehicle['vehicle_type'] ?>">
                                    <?= ucfirst($vehicle['vehicle_type']) ?>
                                </span>
                            </td>
                            <td><?= strtoupper($vehicle['fuel_type']) ?></td>
                            <td><?= number_format($vehicle['fuel_per_liter_standard'], 2) ?></td>
                            <td>
                                <?php if ($vehicle['current_driver_name']): ?>
                                    <div><?= htmlspecialchars($vehicle['current_driver_name']) ?></div>
                                    <small style="color: #6c757d;"><?= $vehicle['driver_mobile'] ?></small>
                                <?php else: ?>
                                    <span style="color: #999;">No driver assigned</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <span class="badge badge-<?= $vehicle['status'] ? 'active' : 'inactive' ?>">
                                    <?= $vehicle['status'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($vehicle['fiscal_year']) ?></td>
                            <td><?= htmlspecialchars($vehicle['created_by_username']) ?></td>
                            <td>
                                <a href="?edit_id=<?= $vehicle['vehicle_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this vehicle?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="vehicle_id" value="<?= $vehicle['vehicle_id'] ?>">
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
    const vehicleNo = document.getElementById('vehicle_no');
    
    // Auto-uppercase vehicle number
    vehicleNo.addEventListener('input', function() {
        this.value = this.value.toUpperCase();
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

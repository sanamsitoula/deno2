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
            $required_fields = ['vehicle_id', 'log_date_nep', 'log_date_eng', 'start_meter', 'end_meter', 'fiscal_year', 'created_by'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Validate meter readings
            if ($_POST['end_meter'] < $_POST['start_meter']) {
                throw new Exception("End meter reading cannot be less than start meter reading.");
            }
            
     $insert_sql = "
    INSERT INTO vehicle_daily_logs (
        vehicle_id, driver_id, log_date_nep, log_date_eng,
        from_location, to_location, start_meter, end_meter,
        purpose, fuel_used_estimated, remarks, fiscal_year, month_nep, created_by
    ) VALUES (
        :vehicle_id, :driver_id, :log_date_nep, :log_date_eng,
        :from_location, :to_location, :start_meter, :end_meter,
        :purpose, :fuel_used_estimated, :remarks, :fiscal_year, :month_nep, :created_by
    )
";


            
            $stmt = $conn->prepare($insert_sql);
   // Extract month from Nepali date
$log_date_parts = explode('.', $_POST['log_date_nep']);
$month_names = [
    1 => 'Baishakh', 2 => 'Jestha', 3 => 'Ashadh', 4 => 'Shrawan',
    5 => 'Bhadra', 6 => 'Ashwin', 7 => 'Kartik', 8 => 'Mangsir',
    9 => 'Poush', 10 => 'Magh', 11 => 'Falgun', 12 => 'Chaitra'
];
$month_nep = isset($log_date_parts[1]) ? ($month_names[(int)$log_date_parts[1]] ?? null) : null;

$stmt->execute([
    ':vehicle_id' => $_POST['vehicle_id'],
    ':driver_id' => $_POST['driver_id'] ?: null,
    ':log_date_nep' => $_POST['log_date_nep'],
    ':log_date_eng' => $_POST['log_date_eng'],
    ':from_location' => $_POST['from_location'] ?? null,
    ':to_location' => $_POST['to_location'] ?? null,
    ':start_meter' => $_POST['start_meter'],
    ':end_meter' => $_POST['end_meter'],
    ':purpose' => $_POST['purpose'] ?? null,
    ':fuel_used_estimated' => $_POST['fuel_used_estimated'] ?: null,
    ':remarks' => $_POST['remarks'] ?? null,
    ':fiscal_year' => $_POST['fiscal_year'],
    ':month_nep' => $month_nep,  // ← ADD THIS
    ':created_by' => $_POST['created_by']
]);

            
            $success_message = "Vehicle log created successfully!";
            
        } elseif ($action === 'update') {
    if ($_POST['end_meter'] < $_POST['start_meter']) {
        throw new Exception("End meter reading cannot be less than start meter reading.");
    }
    
    $update_sql = "
        UPDATE vehicle_daily_logs SET 
            vehicle_id = :vehicle_id,
            driver_id = :driver_id,
            log_date_nep = :log_date_nep,
            log_date_eng = :log_date_eng,
            from_location = :from_location,
            to_location = :to_location,
            start_meter = :start_meter,
            end_meter = :end_meter,
            purpose = :purpose,
            fuel_used_estimated = :fuel_used_estimated,
            remarks = :remarks,
            fiscal_year = :fiscal_year,
            month_nep = :month_nep,
            updated_by = :updated_by,
            updated_at = CURRENT_TIMESTAMP
        WHERE log_id = :log_id
    ";
    
    $stmt = $conn->prepare($update_sql);
    
    // Extract month from Nepali date (SAME AS INSERT)
    $log_date_parts = explode('.', $_POST['log_date_nep']);
    $month_names = [
        1 => 'Baishakh', 2 => 'Jestha', 3 => 'Ashadh', 4 => 'Shrawan',
        5 => 'Bhadra', 6 => 'Ashwin', 7 => 'Kartik', 8 => 'Mangsir',
        9 => 'Poush', 10 => 'Magh', 11 => 'Falgun', 12 => 'Chaitra'
    ];
    $month_nep = isset($log_date_parts[1]) ? ($month_names[(int)$log_date_parts[1]] ?? null) : null;
    
    $stmt->execute([
        ':log_id' => $_POST['log_id'],
        ':vehicle_id' => $_POST['vehicle_id'],
        ':driver_id' => $_POST['driver_id'] ?: null,
        ':log_date_nep' => $_POST['log_date_nep'],
        ':log_date_eng' => $_POST['log_date_eng'],
        ':from_location' => $_POST['from_location'] ?? null,
        ':to_location' => $_POST['to_location'] ?? null,
        ':start_meter' => $_POST['start_meter'],
        ':end_meter' => $_POST['end_meter'],
        ':purpose' => $_POST['purpose'] ?? null,
        ':fuel_used_estimated' => $_POST['fuel_used_estimated'] ?: null,
        ':remarks' => $_POST['remarks'] ?? null,
        ':fiscal_year' => $_POST['fiscal_year'],
        ':month_nep' => $month_nep,  // ← ADD THIS
        ':updated_by' => $_SESSION['user_id'] ?? 1
    ]);
    
    $success_message = "Vehicle log updated successfully!";
}
 elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE vehicle_daily_logs SET deleted_at = CURRENT_TIMESTAMP WHERE log_id = :log_id");
            $stmt->execute([':log_id' => $_POST['log_id']]);
            $success_message = "Vehicle log deleted successfully!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch dropdown data
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type, fuel_type 
    FROM vehicles 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

$drivers = $conn->query("
    SELECT driver_id, driver_name, mobile_no 
    FROM drivers 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY driver_name
")->fetchAll(PDO::FETCH_ASSOC);

$users = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Fetch recent logs
$logs = $conn->query("
    SELECT * FROM v_vehicle_logs_full_details
    ORDER BY log_date_eng DESC, created_at DESC
    LIMIT 30
")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM vehicle_daily_logs WHERE log_id = :log_id AND deleted_at IS NULL");
    $stmt->execute([':log_id' => $_GET['edit_id']]);
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

.summary-box {
    background: #fff3cd;
    border: 2px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #ffe69c;
}

.summary-row:last-child {
    border-bottom: none;
    font-weight: 700;
    font-size: 15px;
    color: #856404;
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

.badge-car { background: #cce5ff; color: #004085; }
.badge-jeep { background: #d4edda; color: #155724; }
.badge-bike { background: #fff3cd; color: #856404; }
.badge-truck { background: #d1ecf1; color: #0c5460; }

.hidden {
    display: none;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📍 <?= $edit_record ? 'Edit' : 'Add' ?> Vehicle Daily Log</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="logForm">
            <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
            <?php if ($edit_record): ?>
                <input type="hidden" name="log_id" value="<?= $edit_record['log_id'] ?>">
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
                                        data-fuel="<?= $vehicle['fuel_type'] ?>"
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
                            <div class="info-row">
                                <span class="info-label">Fuel Type:</span>
                                <span class="info-value" id="fuel_type">-</span>
                            </div>
                        </div>
                    </div>

                    <div class="form-group">
                        <label for="driver_id">Driver (Optional)</label>
                        <select name="driver_id" id="driver_id" class="form-control">
                            <option value="">Select Driver</option>
                            <?php foreach ($drivers as $driver): ?>
                                <option value="<?= $driver['driver_id'] ?>"
                                        <?= ($edit_record && $edit_record['driver_id'] == $driver['driver_id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($driver['driver_name']) ?> (<?= $driver['mobile_no'] ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Date Information -->
            <div class="form-section">
                <div class="section-title">📅 Date Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="log_date_nep">Nepali Date <span class="required">*</span></label>
                        <input type="text" name="log_date_nep" id="log_date_nep" class="form-control"
                               pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.15"
                               value="<?= htmlspecialchars($edit_record['log_date_nep'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="log_date_eng">English Date <span class="required">*</span></label>
                        <input type="date" name="log_date_eng" id="log_date_eng" class="form-control"
                               value="<?= htmlspecialchars($edit_record['log_date_eng'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="fiscal_year">Fiscal Year <span class="required">*</span></label>
                        <select name="fiscal_year" id="fiscal_year" class="form-control" required>
                            <option value="2082/83" <?= (!$edit_record || $edit_record['fiscal_year'] === '2082/83') ? 'selected' : '' ?>>2082/83</option>
                            <option value="2083/84" <?= ($edit_record && $edit_record['fiscal_year'] === '2083/84') ? 'selected' : '' ?>>2083/84</option>
                            <option value="2084/85" <?= ($edit_record && $edit_record['fiscal_year'] === '2084/85') ? 'selected' : '' ?>>2084/85</option>
                        </select>
                    </div>
                </div>
            </div>

            <!-- Journey Details -->
            <div class="form-section">
                <div class="section-title">🗺️ Journey Details</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="from_location">From Location</label>
                        <input type="text" name="from_location" id="from_location" class="form-control"
                               placeholder="Kathmandu Office"
                               value="<?= htmlspecialchars($edit_record['from_location'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="to_location">To Location</label>
                        <input type="text" name="to_location" id="to_location" class="form-control"
                               placeholder="Pokhara Branch"
                               value="<?= htmlspecialchars($edit_record['to_location'] ?? '') ?>">
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="purpose">Purpose</label>
                        <textarea name="purpose" id="purpose" class="form-control" rows="2"
                                  placeholder="Official visit to branch office"><?= htmlspecialchars($edit_record['purpose'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Meter Readings -->
            <div class="form-section">
                <div class="section-title">⏱️ Meter Readings</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="start_meter">Start Meter <span class="required">*</span></label>
                        <input type="number" name="start_meter" id="start_meter" class="form-control"
                               placeholder="45000"
                               value="<?= htmlspecialchars($edit_record['start_meter'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="end_meter">End Meter <span class="required">*</span></label>
                        <input type="number" name="end_meter" id="end_meter" class="form-control"
                               placeholder="45250"
                               value="<?= htmlspecialchars($edit_record['end_meter'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label>Total KM</label>
                        <input type="text" id="total_km_display" class="form-control" readonly
                               style="background: #e9ecef; font-weight: 600; font-size: 18px; color: #28a745;"
                               value="0 km">
                    </div>

                    <div class="form-group">
                        <label for="fuel_used_estimated">Fuel Used (Liters)</label>
                        <input type="number" step="0.01" name="fuel_used_estimated" id="fuel_used_estimated" 
                               class="form-control" placeholder="20.5"
                               value="<?= htmlspecialchars($edit_record['fuel_used_estimated'] ?? '') ?>">
                    </div>
                </div>

                <div class="summary-box hidden" id="mileage_summary">
                    <div class="summary-row">
                        <span>Distance Traveled:</span>
                        <strong id="distance_km">0 km</strong>
                    </div>
                    <div class="summary-row">
                        <span>Fuel Used:</span>
                        <strong id="fuel_used_display">0 L</strong>
                    </div>
                    <div class="summary-row">
                        <span>Calculated Mileage:</span>
                        <strong id="calculated_mileage">0 km/L</strong>
                    </div>
                </div>
            </div>

            <!-- Additional Info -->
            <div class="form-section">
                <div class="section-title">📋 Additional Information</div>
                <div class="form-grid">
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
                    <?= $edit_record ? '💾 Update Log' : '✅ Create Log' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Recent Logs Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Date (Nep)</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>From → To</th>
                    <th>Meters</th>
                    <th>Total KM</th>
                    <th>Fuel Used</th>
                    <th>Mileage</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="10" style="text-align: center; padding: 40px;">No logs found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td><?= $log['log_id'] ?></td>
                            <td><?= htmlspecialchars($log['log_date_nep']) ?></td>
                            <td>
                                <div><strong><?= htmlspecialchars($log['vehicle_no']) ?></strong></div>
                                <span class="badge badge-<?= $log['vehicle_type'] ?>">
                                    <?= ucfirst($log['vehicle_type']) ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($log['driver_name']): ?>
                                    <?= htmlspecialchars($log['driver_name']) ?>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['from_location'] && $log['to_location']): ?>
                                    <small><?= htmlspecialchars($log['from_location']) ?> → <?= htmlspecialchars($log['to_location']) ?></small>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <small><?= number_format($log['start_meter']) ?> → <?= number_format($log['end_meter']) ?></small>
                            </td>
                            <td><strong><?= number_format($log['total_km']) ?> km</strong></td>
                            <td>
                                <?php if ($log['fuel_used_estimated']): ?>
                                    <?= number_format($log['fuel_used_estimated'], 2) ?> L
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['calculated_mileage']): ?>
                                    <strong style="color: #28a745;"><?= number_format($log['calculated_mileage'], 2) ?> km/L</strong>
                                <?php else: ?>
                                    <span style="color: #999;">-</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <a href="?edit_id=<?= $log['log_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this log?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="log_id" value="<?= $log['log_id'] ?>">
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
    const vehicleSelect = document.getElementById('vehicle_id');
    const vehicleInfo = document.getElementById('vehicle_info');
    const startMeter = document.getElementById('start_meter');
    const endMeter = document.getElementById('end_meter');
    const totalKmDisplay = document.getElementById('total_km_display');
    const fuelUsed = document.getElementById('fuel_used_estimated');
    const mileageSummary = document.getElementById('mileage_summary');
    
    // Show vehicle info
    vehicleSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (this.value) {
            document.getElementById('vehicle_type').textContent = option.dataset.type;
            document.getElementById('fuel_type').textContent = option.dataset.fuel.toUpperCase();
            vehicleInfo.classList.remove('hidden');
        } else {
            vehicleInfo.classList.add('hidden');
        }
    });
    
    // Calculate total KM and mileage
    function calculateMileage() {
        const start = parseInt(startMeter.value) || 0;
        const end = parseInt(endMeter.value) || 0;
        const fuel = parseFloat(fuelUsed.value) || 0;
        
        const totalKm = end - start;
        totalKmDisplay.value = totalKm + ' km';
        
        if (totalKm > 0 && fuel > 0) {
            const mileage = totalKm / fuel;
            
            document.getElementById('distance_km').textContent = totalKm + ' km';
            document.getElementById('fuel_used_display').textContent = fuel.toFixed(2) + ' L';
            document.getElementById('calculated_mileage').textContent = mileage.toFixed(2) + ' km/L';
            
            mileageSummary.classList.remove('hidden');
        } else {
            mileageSummary.classList.add('hidden');
        }
    }
    
    startMeter.addEventListener('input', calculateMileage);
    endMeter.addEventListener('input', calculateMileage);
    fuelUsed.addEventListener('input', calculateMileage);
    
    // Initialize
    if (vehicleSelect.value) {
        vehicleSelect.dispatchEvent(new Event('change'));
    }
    calculateMileage();
});

// Auto-extract month name from Nepali date
document.getElementById('log_date_nep').addEventListener('blur', function() {
    const nepaliDate = this.value; // e.g., "2082.05.15"
    const parts = nepaliDate.split('.');
    
    if (parts.length >= 2) {
        const monthNum = parseInt(parts[1]);
        const months = {
            1: 'Baishakh', 2: 'Jestha', 3: 'Ashadh', 4: 'Shrawan',
            5: 'Bhadra', 6: 'Ashwin', 7: 'Kartik', 8: 'Mangsir',
            9: 'Poush', 10: 'Magh', 11: 'Falgun', 12: 'Chaitra'
        };
        
        // Add hidden field if it doesn't exist
        let monthInput = document.getElementById('month_nep_hidden');
        if (!monthInput) {
            monthInput = document.createElement('input');
            monthInput.type = 'hidden';
            monthInput.name = 'month_nep';
            monthInput.id = 'month_nep_hidden';
            document.getElementById('logForm').appendChild(monthInput);
        }
        
        monthInput.value = months[monthNum] || '';
        console.log('Month extracted:', months[monthNum]);
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

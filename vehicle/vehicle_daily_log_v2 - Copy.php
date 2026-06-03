<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;
$logged_user_id = $_SESSION['user_id'] ?? 1;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['action'] ?? 'create';
        
        if ($action === 'create') {
            $required_fields = ['vehicle_id', 'log_date_nep', 'log_date_eng', 'start_meter', 'end_meter', 'fiscal_year'];
            
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
                ':month_nep' => $month_nep,
                ':created_by' => $logged_user_id
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
            
            // Extract month from Nepali date
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
                ':month_nep' => $month_nep,
                ':updated_by' => $logged_user_id
            ]);
            
            $success_message = "Vehicle log updated successfully!";
            
        } elseif ($action === 'delete') {
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
    SELECT driver_id, driver_name, license_no 
    FROM drivers 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY driver_name
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch vehicle driver assignments to get current drivers
$vehicle_assignments = [];
$assign_query = $conn->query("
    SELECT vda.vehicle_id, vda.driver_id, d.driver_name
    FROM vehicle_driver_assignments vda
    JOIN drivers d ON vda.driver_id = d.driver_id
    WHERE vda.active_flag = TRUE AND vda.deleted_at IS NULL
");
while ($row = $assign_query->fetch(PDO::FETCH_ASSOC)) {
    $vehicle_assignments[$row['vehicle_id']] = [
        'driver_id' => $row['driver_id'],
        'driver_name' => $row['driver_name']
    ];
}

// Fetch logs
$filter_fiscal = $_GET['fiscal_year'] ?? '2082/83';
$filter_month = $_GET['month_nep'] ?? '';
$filter_vehicle = $_GET['vehicle_id'] ?? '';

$where_clause = "WHERE vdl.deleted_at IS NULL";
$params = [];

if ($filter_fiscal) {
    $where_clause .= " AND vdl.fiscal_year = :fiscal_year";
    $params[':fiscal_year'] = $filter_fiscal;
}
if ($filter_month) {
    $where_clause .= " AND vdl.month_nep = :month_nep";
    $params[':month_nep'] = $filter_month;
}
if ($filter_vehicle) {
    $where_clause .= " AND vdl.vehicle_id = :vehicle_id";
    $params[':vehicle_id'] = $filter_vehicle;
}

$stmt = $conn->prepare("
    SELECT 
        vdl.log_id,
        vdl.log_date_nep,
        vdl.log_date_eng,
        vdl.vehicle_id,
        v.vehicle_no,
        v.vehicle_type,
        vdl.driver_id,
        d.driver_name,
        vdl.start_meter,
        vdl.end_meter,
        (vdl.end_meter - vdl.start_meter) as distance_traveled,
        vdl.fuel_used_estimated,
        vdl.from_location,
        vdl.to_location,
        vdl.purpose,
        vdl.remarks
    FROM vehicle_daily_logs vdl
    JOIN vehicles v ON vdl.vehicle_id = v.vehicle_id
    LEFT JOIN drivers d ON vdl.driver_id = d.driver_id
    $where_clause
    ORDER BY vdl.log_date_eng DESC
    LIMIT 50
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
}

.container {
    max-width: 1800px;
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
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
    font-size: 14px;
}

.required::after {
    content: ' *';
    color: #dc3545;
}

.form-input, .form-select, .form-textarea {
    padding: 10px 15px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.3s;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

.info-box {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 15px;
    border-radius: 6px;
    margin: 20px 0;
}

.info-box h4 {
    margin: 0 0 10px 0;
    color: #004085;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
}

.info-item {
    display: flex;
    flex-direction: column;
}

.info-label {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 4px;
}

.info-value {
    font-size: 18px;
    font-weight: 700;
    color: #333;
}

.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 25px;
    padding-top: 25px;
    border-top: 1px solid #dee2e6;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-success:hover {
    background: #218838;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.data-table-container {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #f1f1f1;
}

.data-table tr:hover {
    background: #f8f9fa;
}

.filter-container {
    background: white;
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 15px;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📋 Vehicle Daily Log</h1>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="logForm">
            <input type="hidden" name="action" value="create">
            
            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label required">Vehicle</label>
                    <select name="vehicle_id" id="vehicle_id" class="form-select" required>
                        <option value="">Select Vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= $vehicle['vehicle_id'] ?>" 
                                    data-fuel-type="<?= $vehicle['fuel_type'] ?>"
                                    data-current-driver="<?= $vehicle_assignments[$vehicle['vehicle_id']]['driver_id'] ?? '' ?>">
                                <?= htmlspecialchars($vehicle['vehicle_no']) ?> 
                                (<?= ucfirst($vehicle['vehicle_type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Driver</label>
                    <select name="driver_id" id="driver_id" class="form-select">
                        <option value="">Select Driver</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?= $driver['driver_id'] ?>">
                                <?= htmlspecialchars($driver['driver_name']) ?> 
                                (<?= htmlspecialchars($driver['license_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color: #6c757d; margin-top: 4px;">
                        Current driver will be auto-selected
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label required">Log Date (Nepali)</label>
                    <input type="text" name="log_date_nep" id="log_date_nep" 
                           class="form-input" placeholder="2082.08.15" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Log Date (English)</label>
                    <input type="date" name="log_date_eng" id="log_date_eng" 
                           class="form-input" value="<?= date('Y-m-d') ?>" required>
                    <small style="color: #6c757d; margin-top: 4px;">
                        Default: Today's date
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label required">Start Meter (KM)</label>
                    <input type="number" name="start_meter" id="start_meter" 
                           class="form-input" step="1" min="0" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">End Meter (KM)</label>
                    <input type="number" name="end_meter" id="end_meter" 
                           class="form-input" step="1" min="0" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Distance Covered</label>
                    <input type="text" id="distance_covered" class="form-input" 
                           readonly style="background: #e9ecef;">
                </div>

                <div class="form-group">
                    <label class="form-label">Fuel Used (Est. Liters)</label>
                    <input type="number" name="fuel_used_estimated" id="fuel_used_estimated" 
                           class="form-input" step="0.01" min="0">
                </div>

                <div class="form-group">
                    <label class="form-label">From Location</label>
                    <input type="text" name="from_location" class="form-input" 
                           placeholder="Starting location">
                </div>

                <div class="form-group">
                    <label class="form-label">To Location</label>
                    <input type="text" name="to_location" class="form-input" 
                           placeholder="Destination">
                </div>

                <div class="form-group">
                    <label class="form-label required">Fiscal Year</label>
                    <input type="text" name="fiscal_year" class="form-input" 
                           value="2082/83" required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Purpose</label>
                <textarea name="purpose" class="form-textarea" 
                          placeholder="Purpose of trip..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Remarks</label>
                <textarea name="remarks" class="form-textarea" 
                          placeholder="Any additional notes..."></textarea>
            </div>

            <div id="log_summary" class="info-box" style="display: none;">
                <h4>📊 Log Summary</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Distance Covered</span>
                        <span class="info-value" id="summary_distance">0 KM</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Est. Fuel Used</span>
                        <span class="info-value" id="summary_fuel">0 L</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fuel Efficiency</span>
                        <span class="info-value" id="summary_efficiency">0 KM/L</span>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-success">📝 Create Log</button>
            </div>
        </form>
    </div>

    <!-- Filter Section -->
    <div class="filter-container">
        <form method="GET">
            <div class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <input type="text" name="fiscal_year" class="form-input" 
                           value="<?= htmlspecialchars($filter_fiscal) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Month</label>
                    <select name="month_nep" class="form-select">
                        <option value="">All Months</option>
                        <?php 
                        $months = ['Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
                                   'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'];
                        foreach ($months as $month): 
                        ?>
                            <option value="<?= $month ?>" <?= $filter_month === $month ? 'selected' : '' ?>>
                                <?= $month ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Vehicle</label>
                    <select name="vehicle_id" class="form-select">
                        <option value="">All Vehicles</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= $vehicle['vehicle_id'] ?>" 
                                    <?= $filter_vehicle == $vehicle['vehicle_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicle['vehicle_no']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: flex; gap: 10px;">
                <button type="submit" class="btn btn-primary">🔍 Filter</button>
                <a href="?" class="btn" style="background: #6c757d; color: white;">🔄 Reset</a>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Log Date</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Start KM</th>
                    <th>End KM</th>
                    <th>Distance</th>
                    <th>Fuel Used</th>
                    <th>From - To</th>
                    <th>Purpose</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="9" style="text-align: center; padding: 40px;">No logs found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $log): ?>
                        <tr>
                            <td>
                                <?= htmlspecialchars($log['log_date_nep']) ?><br>
                                <small style="color: #6c757d;">
                                    <?= date('d M Y', strtotime($log['log_date_eng'])) ?>
                                </small>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($log['vehicle_no']) ?></strong><br>
                                <small style="color: #6c757d;"><?= ucfirst($log['vehicle_type']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($log['driver_name']) ?: '-' ?></td>
                            <td><?= number_format($log['start_meter']) ?></td>
                            <td><?= number_format($log['end_meter']) ?></td>
                            <td><strong><?= number_format($log['distance_traveled']) ?> KM</strong></td>
                            <td><?= $log['fuel_used_estimated'] ? number_format($log['fuel_used_estimated'], 2) . ' L' : '-' ?></td>
                            <td>
                                <?php if ($log['from_location'] || $log['to_location']): ?>
                                    <?= htmlspecialchars($log['from_location']) ?: '?' ?> → 
                                    <?= htmlspecialchars($log['to_location']) ?: '?' ?>
                                <?php else: ?>
                                    -
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($log['purpose']) ?: '-' ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script>
// Vehicle assignments data
const vehicleAssignments = <?= json_encode($vehicle_assignments) ?>;

document.addEventListener('DOMContentLoaded', function() {
    const vehicleSelect = document.getElementById('vehicle_id');
    const driverSelect = document.getElementById('driver_id');
    const startMeter = document.getElementById('start_meter');
    const endMeter = document.getElementById('end_meter');
    const distanceCovered = document.getElementById('distance_covered');
    const fuelUsed = document.getElementById('fuel_used_estimated');
    
    // Auto-fill current driver when vehicle is selected
    vehicleSelect.addEventListener('change', function() {
        const vehicleId = this.value;
        if (vehicleId && vehicleAssignments[vehicleId]) {
            const currentDriverId = vehicleAssignments[vehicleId].driver_id;
            driverSelect.value = currentDriverId;
        }
    });
    
    // Calculate distance
    function calculateDistance() {
        const start = parseFloat(startMeter.value) || 0;
        const end = parseFloat(endMeter.value) || 0;
        
        if (end >= start) {
            const distance = end - start;
            distanceCovered.value = distance.toFixed(0) + ' KM';
            
            const fuel = parseFloat(fuelUsed.value) || 0;
            
            document.getElementById('summary_distance').textContent = distance.toFixed(0) + ' KM';
            document.getElementById('summary_fuel').textContent = fuel.toFixed(2) + ' L';
            
            if (fuel > 0) {
                const efficiency = distance / fuel;
                document.getElementById('summary_efficiency').textContent = efficiency.toFixed(2) + ' KM/L';
            } else {
                document.getElementById('summary_efficiency').textContent = '0 KM/L';
            }
            
            if (distance > 0 || fuel > 0) {
                document.getElementById('log_summary').style.display = 'block';
            } else {
                document.getElementById('log_summary').style.display = 'none';
            }
        } else {
            distanceCovered.value = '';
            document.getElementById('log_summary').style.display = 'none';
        }
    }
    
    startMeter.addEventListener('input', calculateDistance);
    endMeter.addEventListener('input', calculateDistance);
    fuelUsed.addEventListener('input', calculateDistance);
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
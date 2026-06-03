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
            $required_fields = ['vehicle_id', 'maintenance_type_id', 'maintenance_date_nep', 
                              'maintenance_date_eng', 'meter_reading', 'fiscal_year'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            $insert_sql = "
                INSERT INTO vehicle_maintenance_records (
                    vehicle_id, maintenance_type_id, maintenance_date_nep, maintenance_date_eng,
                    meter_reading, next_due_km, next_due_date_nep, next_due_date_eng,
                    work_description, parts_replaced, service_provider, mechanic_name,
                    labor_cost, parts_cost, payment_status, payment_date_nep, payment_date_eng,
                    bill_no, downtime_days, is_warranty, warranty_remarks,
                    status, remarks, fiscal_year, created_by
                ) VALUES (
                    :vehicle_id, :maintenance_type_id, :maintenance_date_nep, :maintenance_date_eng,
                    :meter_reading, :next_due_km, :next_due_date_nep, :next_due_date_eng,
                    :work_description, :parts_replaced, :service_provider, :mechanic_name,
                    :labor_cost, :parts_cost, :payment_status, :payment_date_nep, :payment_date_eng,
                    :bill_no, :downtime_days, :is_warranty, :warranty_remarks,
                    :status, :remarks, :fiscal_year, :created_by
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':vehicle_id' => $_POST['vehicle_id'],
                ':maintenance_type_id' => $_POST['maintenance_type_id'],
                ':maintenance_date_nep' => $_POST['maintenance_date_nep'],
                ':maintenance_date_eng' => $_POST['maintenance_date_eng'],
                ':meter_reading' => $_POST['meter_reading'],
                ':next_due_km' => $_POST['next_due_km'] ?: null,
                ':next_due_date_nep' => $_POST['next_due_date_nep'] ?: null,
                ':next_due_date_eng' => $_POST['next_due_date_eng'] ?: null,
                ':work_description' => $_POST['work_description'] ?? null,
                ':parts_replaced' => $_POST['parts_replaced'] ?? null,
                ':service_provider' => $_POST['service_provider'] ?? null,
                ':mechanic_name' => $_POST['mechanic_name'] ?? null,
                ':labor_cost' => $_POST['labor_cost'] ?: 0,
                ':parts_cost' => $_POST['parts_cost'] ?: 0,
                ':payment_status' => isset($_POST['payment_status']) ? 1 : 0,
                ':payment_date_nep' => $_POST['payment_date_nep'] ?: null,
                ':payment_date_eng' => $_POST['payment_date_eng'] ?: null,
                ':bill_no' => $_POST['bill_no'] ?? null,
                ':downtime_days' => $_POST['downtime_days'] ?: 0,
                ':is_warranty' => isset($_POST['is_warranty']) ? 1 : 0,
                ':warranty_remarks' => $_POST['warranty_remarks'] ?? null,
                ':status' => $_POST['status'] ?? 'completed',
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':created_by' => $logged_user_id
            ]);
            
            $success_message = "Maintenance record created successfully!";
            
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE vehicle_maintenance_records SET deleted_at = CURRENT_TIMESTAMP WHERE maintenance_id = :maintenance_id");
            $stmt->execute([':maintenance_id' => $_POST['maintenance_id']]);
            $success_message = "Maintenance record deleted successfully!";
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

$maintenance_types = $conn->query("
    SELECT maintenance_type_id, type_name, is_scheduled, default_interval_km, default_interval_months
    FROM maintenance_types 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY type_name
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch maintenance records
$filter_fiscal = $_GET['fiscal_year'] ?? '2082/83';
$filter_vehicle = $_GET['vehicle_id'] ?? '';

$where = ["vmr.deleted_at IS NULL"];
$params = [];

if ($filter_fiscal) {
    $where[] = "vmr.fiscal_year = :fiscal_year";
    $params[':fiscal_year'] = $filter_fiscal;
}
if ($filter_vehicle) {
    $where[] = "vmr.vehicle_id = :vehicle_id";
    $params[':vehicle_id'] = $filter_vehicle;
}

$where_clause = implode(" AND ", $where);

$stmt = $conn->prepare("
    SELECT * FROM v_vehicle_maintenance_full_details
    WHERE $where_clause
    ORDER BY maintenance_date_eng DESC
    LIMIT 50
");
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);
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

.tabs {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #dee2e6;
}

.tab {
    padding: 12px 24px;
    background: none;
    border: none;
    color: #6c757d;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    border-bottom: 3px solid transparent;
}

.tab:hover {
    color: #495057;
    background: #f8f9fa;
}

.tab.active {
    color: #007bff;
    border-bottom-color: #007bff;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
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

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
}

.form-checkbox input {
    width: 18px;
    height: 18px;
}

.info-box {
    background: #fff3cd;
    border-left: 4px solid #ffc107;
    padding: 15px;
    border-radius: 6px;
    margin: 20px 0;
}

.form-section {
    background: #f8f9fa;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.form-section h3 {
    margin: 0 0 15px 0;
    color: #495057;
    font-size: 16px;
    font-weight: 600;
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

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 13px;
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

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-completed {
    background: #d4edda;
    color: #155724;
}

.badge-pending {
    background: #fff3cd;
    color: #856404;
}

.badge-in-progress {
    background: #cfe2ff;
    color: #084298;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">🔧 Vehicle Maintenance Tracking</h1>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab active" onclick="switchTab('create-maintenance')">🔧 Add Maintenance</button>
        <button class="tab" onclick="switchTab('view-maintenance')">📋 View Records</button>
    </div>

    <!-- Tab 1: Add Maintenance -->
    <div id="create-maintenance" class="tab-content active">
        <div class="form-container">
            <form method="POST">
                <input type="hidden" name="action" value="create">
                
                <div class="form-section">
                    <h3>Basic Information</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label required">Vehicle</label>
                            <select name="vehicle_id" id="vehicle_id" class="form-select" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle['vehicle_id'] ?>">
                                        <?= htmlspecialchars($vehicle['vehicle_no']) ?> 
                                        (<?= ucfirst($vehicle['vehicle_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Maintenance Type</label>
                            <select name="maintenance_type_id" id="maintenance_type_id" class="form-select" required>
                                <option value="">Select Type</option>
                                <?php foreach ($maintenance_types as $type): ?>
                                    <option value="<?= $type['maintenance_type_id'] ?>"
                                            data-scheduled="<?= $type['is_scheduled'] ?>"
                                            data-km-interval="<?= $type['default_interval_km'] ?>"
                                            data-month-interval="<?= $type['default_interval_months'] ?>">
                                        <?= htmlspecialchars($type['type_name']) ?>
                                        <?= $type['is_scheduled'] ? '(Scheduled)' : '(As Needed)' ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Maintenance Date (Nepali)</label>
                            <input type="text" name="maintenance_date_nep" class="form-input" 
                                   placeholder="2082.08.15" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Maintenance Date (English)</label>
                            <input type="date" name="maintenance_date_eng" class="form-input" 
                                   value="<?= date('Y-m-d') ?>" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Meter Reading (KM)</label>
                            <input type="number" name="meter_reading" id="meter_reading" 
                                   class="form-input" step="1" min="0" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label required">Fiscal Year</label>
                            <input type="text" name="fiscal_year" class="form-input" 
                                   value="2082/83" required>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Status</label>
                            <select name="status" class="form-select">
                                <option value="completed">Completed</option>
                                <option value="pending">Pending</option>
                                <option value="in_progress">In Progress</option>
                                <option value="cancelled">Cancelled</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Next Scheduled Maintenance</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Next Due (KM)</label>
                            <input type="number" name="next_due_km" id="next_due_km" 
                                   class="form-input" step="1" min="0">
                            <small style="color: #6c757d; margin-top: 4px;">
                                Auto-calculated for scheduled maintenance
                            </small>
                        </div>

                        <div class="form-group">
                            <label class="form-label">Next Due Date (Nepali)</label>
                            <input type="text" name="next_due_date_nep" class="form-input" 
                                   placeholder="2082.12.15">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Next Due Date (English)</label>
                            <input type="date" name="next_due_date_eng" id="next_due_date_eng" 
                                   class="form-input">
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Work Details</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Service Provider</label>
                            <input type="text" name="service_provider" class="form-input" 
                                   placeholder="Workshop/Garage name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Mechanic Name</label>
                            <input type="text" name="mechanic_name" class="form-input" 
                                   placeholder="Mechanic name">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Downtime (Days)</label>
                            <input type="number" name="downtime_days" class="form-input" 
                                   step="1" min="0" value="0">
                        </div>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Work Description</label>
                        <textarea name="work_description" class="form-textarea" 
                                  placeholder="Describe the maintenance work performed..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Parts Replaced</label>
                        <textarea name="parts_replaced" class="form-textarea" 
                                  placeholder="List of parts replaced..."></textarea>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Cost & Payment</h3>
                    <div class="form-grid">
                        <div class="form-group">
                            <label class="form-label">Labor Cost (रू)</label>
                            <input type="number" name="labor_cost" id="labor_cost" 
                                   class="form-input" step="0.01" min="0" value="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Parts Cost (रू)</label>
                            <input type="number" name="parts_cost" id="parts_cost" 
                                   class="form-input" step="0.01" min="0" value="0">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Total Cost</label>
                            <input type="text" id="total_cost" class="form-input" 
                                   readonly style="background: #e9ecef;">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Bill Number</label>
                            <input type="text" name="bill_no" class="form-input" 
                                   placeholder="Invoice/Bill number">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Date (Nepali)</label>
                            <input type="text" name="payment_date_nep" class="form-input" 
                                   placeholder="2082.08.15">
                        </div>

                        <div class="form-group">
                            <label class="form-label">Payment Date (English)</label>
                            <input type="date" name="payment_date_eng" class="form-input">
                        </div>
                    </div>

                    <div class="form-checkbox">
                        <input type="checkbox" name="payment_status" id="payment_status">
                        <label for="payment_status">Payment Completed</label>
                    </div>
                </div>

                <div class="form-section">
                    <h3>Warranty & Additional Info</h3>
                    <div class="form-checkbox">
                        <input type="checkbox" name="is_warranty" id="is_warranty">
                        <label for="is_warranty">Under Warranty</label>
                    </div>

                    <div class="form-group" style="margin-top: 15px;">
                        <label class="form-label">Warranty Remarks</label>
                        <textarea name="warranty_remarks" class="form-textarea" 
                                  placeholder="Warranty details..."></textarea>
                    </div>

                    <div class="form-group">
                        <label class="form-label">General Remarks</label>
                        <textarea name="remarks" class="form-textarea" 
                                  placeholder="Any additional notes..."></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">🔧 Create Maintenance Record</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab 2: View Maintenance -->
    <div id="view-maintenance" class="tab-content">
        <div class="filter-container" style="background: white; padding: 20px; border-radius: 12px; margin-bottom: 20px;">
            <form method="GET" style="display: flex; gap: 15px; align-items: end;">
                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <input type="text" name="fiscal_year" class="form-input" 
                           value="<?= htmlspecialchars($filter_fiscal) ?>">
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

                <button type="submit" class="btn" style="background: #007bff; color: white;">🔍 Filter</button>
            </form>
        </div>

        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>Date</th>
                        <th>Vehicle</th>
                        <th>Type</th>
                        <th>KM Reading</th>
                        <th>Work Done</th>
                        <th>Service Provider</th>
                        <th>Cost</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($records)): ?>
                        <tr>
                            <td colspan="9" style="text-align: center; padding: 40px;">
                                No maintenance records found
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($records as $record): ?>
                            <tr>
                                <td>
                                    <?= htmlspecialchars($record['maintenance_date_nep']) ?><br>
                                    <small style="color: #6c757d;">
                                        <?= date('d M Y', strtotime($record['maintenance_date_eng'])) ?>
                                    </small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($record['vehicle_no']) ?></strong><br>
                                    <small style="color: #6c757d;"><?= ucfirst($record['vehicle_type']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($record['type_name']) ?></td>
                                <td><?= number_format($record['meter_reading']) ?> KM</td>
                                <td>
                                    <?= $record['work_description'] ? htmlspecialchars(substr($record['work_description'], 0, 50)) . '...' : '-' ?>
                                </td>
                                <td><?= htmlspecialchars($record['service_provider']) ?: '-' ?></td>
                                <td>
                                    <strong>रू <?= number_format($record['total_cost'], 2) ?></strong><br>
                                    <small style="color: #6c757d;">
                                        <?= $record['payment_status'] ? '✓ Paid' : '⏳ Pending' ?>
                                    </small>
                                </td>
                                <td>
                                    <span class="badge badge-<?= $record['status'] ?>">
                                        <?= ucfirst($record['status']) ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Delete this record?')">
                                        <input type="hidden" name="action" value="delete">
                                        <input type="hidden" name="maintenance_id" 
                                               value="<?= $record['maintenance_id'] ?>">
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
</div>

<script>
function switchTab(tabName) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
    
    event.target.classList.add('active');
    document.getElementById(tabName).classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    const maintenanceTypeSelect = document.getElementById('maintenance_type_id');
    const meterReading = document.getElementById('meter_reading');
    const nextDueKm = document.getElementById('next_due_km');
    const maintenanceDateEng = document.querySelector('[name="maintenance_date_eng"]');
    const nextDueDateEng = document.getElementById('next_due_date_eng');
    const laborCost = document.getElementById('labor_cost');
    const partsCost = document.getElementById('parts_cost');
    const totalCost = document.getElementById('total_cost');
    
    // Auto-calculate next due based on maintenance type
    maintenanceTypeSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option && option.dataset.scheduled === '1') {
            const kmInterval = parseInt(option.dataset.kmInterval) || 0;
            const monthInterval = parseInt(option.dataset.monthInterval) || 0;
            
            if (kmInterval > 0 && meterReading.value) {
                nextDueKm.value = parseInt(meterReading.value) + kmInterval;
            }
            
            if (monthInterval > 0 && maintenanceDateEng.value) {
                const date = new Date(maintenanceDateEng.value);
                date.setMonth(date.getMonth() + monthInterval);
                nextDueDateEng.value = date.toISOString().split('T')[0];
            }
        }
    });
    
    meterReading.addEventListener('change', function() {
        const option = maintenanceTypeSelect.options[maintenanceTypeSelect.selectedIndex];
        if (option && option.dataset.scheduled === '1') {
            const kmInterval = parseInt(option.dataset.kmInterval) || 0;
            if (kmInterval > 0) {
                nextDueKm.value = parseInt(this.value) + kmInterval;
            }
        }
    });
    
    // Calculate total cost
    function calculateTotal() {
        const labor = parseFloat(laborCost.value) || 0;
        const parts = parseFloat(partsCost.value) || 0;
        const total = labor + parts;
        totalCost.value = 'रू ' + total.toFixed(2);
    }
    
    laborCost.addEventListener('input', calculateTotal);
    partsCost.addEventListener('input', calculateTotal);
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

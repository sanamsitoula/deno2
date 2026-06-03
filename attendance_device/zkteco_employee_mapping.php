<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$message = '';
$message_type = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        if ($action === 'add_mapping') {
            $stmt = $conn->prepare("
                INSERT INTO zkteco_user_mapping (
                    device_id, device_user_id, employee_id, shift_type, shift_id, mapped_by
                ) VALUES (
                    :device_id, :device_user_id, :employee_id, :shift_type, :shift_id, :mapped_by
                )
            ");
            
            $stmt->execute([
                ':device_id' => $_POST['device_id'],
                ':device_user_id' => $_POST['device_user_id'],
                ':employee_id' => $_POST['employee_id'],
                ':shift_type' => $_POST['shift_type'],
                ':shift_id' => $_POST['shift_id'] ?: null,
                ':mapped_by' => $_SESSION['user_id']
            ]);
            
            $message = 'Employee mapping added successfully!';
            $message_type = 'success';
            
        } elseif ($action === 'delete_mapping') {
            $stmt = $conn->prepare("DELETE FROM zkteco_user_mapping WHERE id = :id");
            $stmt->execute([':id' => $_POST['mapping_id']]);
            
            $message = 'Mapping deleted successfully!';
            $message_type = 'success';
            
        } elseif ($action === 'bulk_auto_map') {
            // Auto-map employees where attendance_id matches
            $device_id = $_POST['device_id'];
            $shift_type = $_POST['bulk_shift_type'];
            
            $stmt = $conn->prepare("
                INSERT INTO zkteco_user_mapping (device_id, device_user_id, employee_id, shift_type, mapped_by)
                SELECT 
                    :device_id,
                    e.attendance_id,
                    e.id,
                    :shift_type,
                    :mapped_by
                FROM employee e
                WHERE e.attendance_id IS NOT NULL
                AND e.deleted_date IS NULL
                AND NOT EXISTS (
                    SELECT 1 FROM zkteco_user_mapping zum
                    WHERE zum.device_id = :device_id
                    AND zum.employee_id = e.id
                )
            ");
            
            $stmt->execute([
                ':device_id' => $device_id,
                ':shift_type' => $shift_type,
                ':mapped_by' => $_SESSION['user_id']
            ]);
            
            $count = $stmt->rowCount();
            $message = "Successfully auto-mapped {$count} employees!";
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get filter values
$device_filter = $_GET['device_id'] ?? '';
$mapped_only = $_GET['mapped_only'] ?? '';

// Get mappings
$where = ["e.deleted_date IS NULL"];
$params = [];

if ($device_filter) {
    $where[] = "zum.device_id = :device_id";
    $params[':device_id'] = $device_filter;
}

if ($mapped_only === 'yes') {
    $where[] = "zum.id IS NOT NULL";
} elseif ($mapped_only === 'no') {
    $where[] = "zum.id IS NULL";
}

$stmt = $conn->prepare("
    SELECT 
        e.id as employee_id,
        e.code as employee_code,
        e.name as employee_name,
        e.attendance_id,
        e.emp_type,
        zum.id as mapping_id,
        zum.device_user_id,
        zum.shift_type,
        zum.mapped_at,
        d.device_name,
        d.id as device_id,
        s.name as shift_name
    FROM employee e
    LEFT JOIN zkteco_user_mapping zum ON e.id = zum.employee_id " . 
        ($device_filter ? "AND zum.device_id = :device_id" : "") . "
    LEFT JOIN zkteco_devices d ON zum.device_id = d.id
    LEFT JOIN shifts s ON zum.shift_id = s.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY e.code
");

$stmt->execute($params);
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get devices for dropdowns
$devices = $conn->query("SELECT id, device_name FROM zkteco_devices WHERE is_active = true ORDER BY device_name")->fetchAll(PDO::FETCH_ASSOC);

// Get shifts
$shifts = $conn->query("SELECT id, name FROM shifts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats = $conn->query("
    SELECT 
        COUNT(DISTINCT e.id) as total_employees,
        COUNT(DISTINCT zum.employee_id) as mapped_employees,
        COUNT(DISTINCT CASE WHEN e.attendance_id IS NULL THEN e.id END) as no_attendance_id
    FROM employee e
    LEFT JOIN zkteco_user_mapping zum ON e.id = zum.employee_id
    WHERE e.deleted_date IS NULL
")->fetch(PDO::FETCH_ASSOC);

?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
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
}

.stats-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.stat-box {
    background: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #333;
}

.stat-label {
    font-size: 13px;
    color: #6c757d;
    margin-top: 5px;
}

.action-section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.action-buttons {
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-danger { background: #dc3545; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.filter-section {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 13px;
}

.form-control {
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
}

.table-container {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow-x: auto;
}

.table {
    width: 100%;
    border-collapse: collapse;
}

.table th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    font-size: 13px;
    border-bottom: 2px solid #e9ecef;
}

.table td {
    padding: 12px;
    border-bottom: 1px solid #e9ecef;
    font-size: 14px;
}

.table tbody tr:hover {
    background: #f8f9fa;
}

.badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    display: inline-block;
}

.badge-success { background: #d4edda; color: #155724; }
.badge-warning { background: #fff3cd; color: #856404; }
.badge-danger { background: #f8d7da; color: #721c24; }

.modal {
    display: none;
    position: fixed;
    z-index: 1000;
    left: 0;
    top: 0;
    width: 100%;
    height: 100%;
    background: rgba(0,0,0,0.5);
    align-items: center;
    justify-content: center;
}

.modal.show {
    display: flex;
}

.modal-content {
    background: white;
    padding: 30px;
    border-radius: 12px;
    max-width: 600px;
    width: 90%;
}

.modal-header {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 20px;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
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
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">👥 Employee Device Mapping</h1>
        <p>Map employees to ZKTeco device user IDs</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- Statistics -->
    <div class="stats-row">
        <div class="stat-box">
            <div class="stat-value"><?= $stats['total_employees'] ?></div>
            <div class="stat-label">Total Employees</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= $stats['mapped_employees'] ?></div>
            <div class="stat-label">Mapped Employees</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= $stats['total_employees'] - $stats['mapped_employees'] ?></div>
            <div class="stat-label">Not Mapped</div>
        </div>
        <div class="stat-box">
            <div class="stat-value"><?= $stats['no_attendance_id'] ?></div>
            <div class="stat-label">No Attendance ID</div>
        </div>
    </div>

    <!-- Actions -->
    <div class="action-section">
        <div class="action-buttons">
            <button onclick="showAddMapping()" class="btn btn-primary">
                ➕ Add Mapping
            </button>
            <button onclick="showBulkAutoMap()" class="btn btn-success">
                🔄 Bulk Auto-Map
            </button>
            <a href="?" class="btn btn-secondary">
                🔄 Refresh
            </a>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET">
            <div class="filter-row">
                <div class="form-group">
                    <label>Device</label>
                    <select name="device_id" class="form-control">
                        <option value="">All Devices</option>
                        <?php foreach ($devices as $dev): ?>
                        <option value="<?= $dev['id'] ?>" <?= $device_filter == $dev['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dev['device_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Mapping Status</label>
                    <select name="mapped_only" class="form-control">
                        <option value="">All</option>
                        <option value="yes" <?= $mapped_only === 'yes' ? 'selected' : '' ?>>Mapped Only</option>
                        <option value="no" <?= $mapped_only === 'no' ? 'selected' : '' ?>>Not Mapped</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>&nbsp;</label>
                    <button type="submit" class="btn btn-primary">Filter</button>
                </div>
            </div>
        </form>
    </div>

    <!-- Employee List -->
    <div class="table-container">
        <h3 style="margin-top: 0;">Employees (<?= count($employees) ?>)</h3>
        
        <table class="table">
            <thead>
                <tr>
                    <th>Employee Code</th>
                    <th>Employee Name</th>
                    <th>Attendance ID</th>
                    <th>Device</th>
                    <th>Device User ID</th>
                    <th>Shift Type</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($emp['employee_code']) ?></strong></td>
                    <td><?= htmlspecialchars($emp['employee_name']) ?></td>
                    <td>
                        <?php if ($emp['attendance_id']): ?>
                            <code><?= htmlspecialchars($emp['attendance_id']) ?></code>
                        <?php else: ?>
                            <span class="badge badge-danger">Not Set</span>
                        <?php endif; ?>
                    </td>
                    <td><?= $emp['device_name'] ? htmlspecialchars($emp['device_name']) : '-' ?></td>
                    <td><?= $emp['device_user_id'] ? '<code>' . htmlspecialchars($emp['device_user_id']) . '</code>' : '-' ?></td>
                    <td>
                        <?php if ($emp['shift_type']): ?>
                            <span class="badge badge-<?= $emp['shift_type'] === 'REGULAR' ? 'success' : 'warning' ?>">
                                <?= $emp['shift_type'] ?>
                            </span>
                        <?php else: ?>
                            -
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($emp['mapping_id']): ?>
                            <span class="badge badge-success">Mapped</span>
                        <?php else: ?>
                            <span class="badge badge-danger">Not Mapped</span>
                        <?php endif; ?>
                    </td>
                    <td>
                        <?php if ($emp['mapping_id']): ?>
                            <form method="POST" style="display: inline;" onsubmit="return confirm('Delete this mapping?')">
                                <input type="hidden" name="action" value="delete_mapping">
                                <input type="hidden" name="mapping_id" value="<?= $emp['mapping_id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        <?php else: ?>
                            <button onclick="quickMap(<?= $emp['employee_id'] ?>, '<?= htmlspecialchars($emp['employee_code']) ?>', '<?= htmlspecialchars($emp['attendance_id'] ?? '') ?>')" 
                                    class="btn btn-primary btn-sm">
                                Map
                            </button>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add Mapping Modal -->
<div id="addMappingModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">Add Employee Mapping</div>
        <form method="POST">
            <input type="hidden" name="action" value="add_mapping">
            <input type="hidden" name="employee_id" id="add_employee_id">
            
            <div class="form-group">
                <label>Employee</label>
                <input type="text" id="add_employee_display" class="form-control" readonly>
            </div>
            
            <div class="form-group">
                <label>Device</label>
                <select name="device_id" class="form-control" required>
                    <option value="">Select Device</option>
                    <?php foreach ($devices as $dev): ?>
                    <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Device User ID</label>
                <input type="text" name="device_user_id" id="add_device_user_id" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Shift Type</label>
                <select name="shift_type" class="form-control" required>
                    <option value="REGULAR">REGULAR (8hr with 1hr break)</option>
                    <option value="DUTY_24HR">DUTY_24HR (24-hour duty)</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>Shift (Optional)</label>
                <select name="shift_id" class="form-control">
                    <option value="">None</option>
                    <?php foreach ($shifts as $shift): ?>
                    <option value="<?= $shift['id'] ?>"><?= htmlspecialchars($shift['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeModal('addMappingModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary">Save Mapping</button>
            </div>
        </form>
    </div>
</div>

<!-- Bulk Auto-Map Modal -->
<div id="bulkAutoMapModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">Bulk Auto-Map Employees</div>
        <p>This will automatically map all employees whose <strong>attendance_id</strong> is set, matching it to the device user ID.</p>
        <form method="POST">
            <input type="hidden" name="action" value="bulk_auto_map">
            
            <div class="form-group">
                <label>Device</label>
                <select name="device_id" class="form-control" required>
                    <option value="">Select Device</option>
                    <?php foreach ($devices as $dev): ?>
                    <option value="<?= $dev['id'] ?>"><?= htmlspecialchars($dev['device_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Default Shift Type</label>
                <select name="bulk_shift_type" class="form-control" required>
                    <option value="REGULAR">REGULAR (8hr with 1hr break)</option>
                    <option value="DUTY_24HR">DUTY_24HR (24-hour duty)</option>
                </select>
            </div>
            
            <div style="margin-top: 20px; display: flex; gap: 10px; justify-content: flex-end;">
                <button type="button" onclick="closeModal('bulkAutoMapModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-success">Auto-Map All</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddMapping() {
    document.getElementById('addMappingModal').classList.add('show');
}

function showBulkAutoMap() {
    document.getElementById('bulkAutoMapModal').classList.add('show');
}

function quickMap(employeeId, employeeCode, attendanceId) {
    document.getElementById('add_employee_id').value = employeeId;
    document.getElementById('add_employee_display').value = employeeCode;
    document.getElementById('add_device_user_id').value = attendanceId || '';
    showAddMapping();
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

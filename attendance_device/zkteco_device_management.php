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
        if ($action === 'add_device') {
            $stmt = $conn->prepare("
                INSERT INTO zkteco_devices (
                    device_name, device_code, ip_address, port, location,
                    description, priority, timeout, disable_during_pull,
                    auto_clear_records, is_active, created_by
                ) VALUES (
                    :device_name, :device_code, :ip_address, :port, :location,
                    :description, :priority, :timeout, :disable_during_pull,
                    :auto_clear_records, :is_active, :created_by
                )
            ");
            
            $stmt->execute([
                ':device_name' => $_POST['device_name'],
                ':device_code' => $_POST['device_code'],
                ':ip_address' => $_POST['ip_address'],
                ':port' => $_POST['port'],
                ':location' => $_POST['location'],
                ':description' => $_POST['description'],
                ':priority' => $_POST['priority'],
                ':timeout' => $_POST['timeout'],
                ':disable_during_pull' => isset($_POST['disable_during_pull']) ? 1 : 0,
                ':auto_clear_records' => isset($_POST['auto_clear_records']) ? 1 : 0,
                ':is_active' => isset($_POST['is_active']) ? 1 : 0,
                ':created_by' => $_SESSION['user_id']
            ]);
            
            $message = 'Device added successfully!';
            $message_type = 'success';
            
        } elseif ($action === 'edit_device') {
            $stmt = $conn->prepare("
                UPDATE zkteco_devices SET
                    device_name = :device_name,
                    device_code = :device_code,
                    ip_address = :ip_address,
                    port = :port,
                    location = :location,
                    description = :description,
                    priority = :priority,
                    timeout = :timeout,
                    disable_during_pull = :disable_during_pull,
                    auto_clear_records = :auto_clear_records,
                    is_active = :is_active,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            
            $stmt->execute([
                ':id' => $_POST['device_id'],
                ':device_name' => $_POST['device_name'],
                ':device_code' => $_POST['device_code'],
                ':ip_address' => $_POST['ip_address'],
                ':port' => $_POST['port'],
                ':location' => $_POST['location'],
                ':description' => $_POST['description'],
                ':priority' => $_POST['priority'],
                ':timeout' => $_POST['timeout'],
                ':disable_during_pull' => isset($_POST['disable_during_pull']) ? 1 : 0,
                ':auto_clear_records' => isset($_POST['auto_clear_records']) ? 1 : 0,
                ':is_active' => isset($_POST['is_active']) ? 1 : 0,
                ':updated_by' => $_SESSION['user_id']
            ]);
            
            $message = 'Device updated successfully!';
            $message_type = 'success';
            
        } elseif ($action === 'delete_device') {
            $stmt = $conn->prepare("DELETE FROM zkteco_devices WHERE id = :id");
            $stmt->execute([':id' => $_POST['device_id']]);
            
            $message = 'Device deleted successfully!';
            $message_type = 'success';
            
        } elseif ($action === 'toggle_active') {
            $stmt = $conn->prepare("
                UPDATE zkteco_devices 
                SET is_active = NOT is_active,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ");
            $stmt->execute([':id' => $_POST['device_id']]);
            
            $message = 'Device status updated!';
            $message_type = 'success';
        }
    } catch (Exception $e) {
        $message = 'Error: ' . $e->getMessage();
        $message_type = 'danger';
    }
}

// Get all devices
$devices = $conn->query("
    SELECT 
        d.*,
        COUNT(DISTINCT zum.employee_id) as mapped_employees,
        (SELECT COUNT(*) FROM zkteco_pull_log WHERE device_id = d.id AND pull_date = CURRENT_DATE) as pulls_today,
        (SELECT COUNT(*) FROM zkteco_pull_log WHERE device_id = d.id AND pull_date = CURRENT_DATE AND status = 'SUCCESS') as successful_pulls_today
    FROM zkteco_devices d
    LEFT JOIN zkteco_user_mapping zum ON d.id = zum.device_id AND zum.is_active = true
    GROUP BY d.id
    ORDER BY d.priority, d.device_name
")->fetchAll(PDO::FETCH_ASSOC);

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

.page-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 10px 0;
}

.actions-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
    display: flex;
    gap: 10px;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-warning { background: #ffc107; color: #333; }
.btn-danger { background: #dc3545; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-sm { padding: 8px 16px; font-size: 13px; }

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

.devices-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(400px, 1fr));
    gap: 25px;
}

.device-card {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 5px solid #667eea;
    transition: all 0.3s ease;
}

.device-card:hover {
    transform: translateY(-4px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.15);
}

.device-card.inactive {
    opacity: 0.6;
    border-left-color: #dc3545;
}

.device-card.offline {
    border-left-color: #ffc107;
}

.device-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #f8f9fa;
}

.device-title {
    flex: 1;
}

.device-name {
    font-size: 20px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}

.device-code {
    font-size: 13px;
    color: #6c757d;
    font-family: 'Courier New', monospace;
    background: #f8f9fa;
    padding: 3px 8px;
    border-radius: 4px;
    display: inline-block;
}

.device-status {
    padding: 6px 14px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: 700;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active {
    background: #d4edda;
    color: #155724;
}

.status-inactive {
    background: #f8d7da;
    color: #721c24;
}

.device-info {
    margin-bottom: 20px;
}

.info-row {
    display: flex;
    justify-content: space-between;
    padding: 10px 0;
    border-bottom: 1px solid #f8f9fa;
}

.info-label {
    font-size: 13px;
    color: #6c757d;
    font-weight: 600;
}

.info-value {
    font-size: 14px;
    color: #333;
    font-weight: 600;
}

.connection-indicator {
    display: inline-block;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    margin-right: 8px;
}

.connection-online { background: #28a745; }
.connection-offline { background: #dc3545; }
.connection-unknown { background: #ffc107; }

.device-stats {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
    margin-bottom: 20px;
}

.stat-box {
    background: #f8f9fa;
    padding: 12px;
    border-radius: 6px;
    text-align: center;
}

.stat-value {
    font-size: 24px;
    font-weight: 700;
    color: #667eea;
}

.stat-label {
    font-size: 11px;
    color: #6c757d;
    margin-top: 4px;
}

.device-actions {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 10px;
}

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
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 25px;
    color: #333;
}

.form-group {
    margin-bottom: 20px;
}

.form-group label {
    display: block;
    font-weight: 600;
    margin-bottom: 8px;
    color: #333;
    font-size: 14px;
}

.form-control {
    width: 100%;
    padding: 12px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
    box-sizing: border-box;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
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

.form-row {
    display: grid;
    grid-template-columns: repeat(2, 1fr);
    gap: 15px;
}

.modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 25px;
    padding-top: 20px;
    border-top: 2px solid #f8f9fa;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
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

.help-text {
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">⚙️ Device Management</h1>
        <p>Configure and manage ZKTeco attendance devices</p>
    </div>

    <?php if ($message): ?>
    <div class="alert alert-<?= $message_type ?>">
        <?= htmlspecialchars($message) ?>
    </div>
    <?php endif; ?>

    <!-- Actions Bar -->
    <div class="actions-bar">
        <button onclick="showAddDevice()" class="btn btn-primary">
            ➕ Add New Device
        </button>
        <a href="?" class="btn btn-secondary">
            🔄 Refresh
        </a>
        <a href="zkteco_index.php" class="btn btn-info">
            ← Back to Dashboard
        </a>
    </div>

    <!-- Devices Grid -->
    <?php if (empty($devices)): ?>
    <div style="background: white; padding: 40px; border-radius: 12px; text-align: center;">
        <p style="font-size: 18px; color: #6c757d; margin-bottom: 20px;">
            No devices configured yet.
        </p>
        <button onclick="showAddDevice()" class="btn btn-primary">
            Add Your First Device
        </button>
    </div>
    <?php else: ?>
    
    <div class="devices-grid">
        <?php foreach ($devices as $device): ?>
        <div class="device-card <?= $device['is_active'] ? '' : 'inactive' ?> <?= $device['connection_status'] === 'OFFLINE' ? 'offline' : '' ?>">
            <div class="device-header">
                <div class="device-title">
                    <div class="device-name"><?= htmlspecialchars($device['device_name']) ?></div>
                    <span class="device-code"><?= htmlspecialchars($device['device_code']) ?></span>
                </div>
                <span class="device-status status-<?= $device['is_active'] ? 'active' : 'inactive' ?>">
                    <?= $device['is_active'] ? 'Active' : 'Inactive' ?>
                </span>
            </div>

            <div class="device-info">
                <div class="info-row">
                    <span class="info-label">IP Address</span>
                    <span class="info-value">
                        <?= htmlspecialchars($device['ip_address']) ?>:<?= $device['port'] ?>
                    </span>
                </div>
                <div class="info-row">
                    <span class="info-label">Location</span>
                    <span class="info-value"><?= htmlspecialchars($device['location'] ?? 'N/A') ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Priority</span>
                    <span class="info-value"><?= $device['priority'] ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label">Connection Status</span>
                    <span class="info-value">
                        <span class="connection-indicator connection-<?= strtolower($device['connection_status'] ?? 'unknown') ?>"></span>
                        <?= $device['connection_status'] ?? 'Unknown' ?>
                    </span>
                </div>
                <?php if ($device['last_online_at']): ?>
                <div class="info-row">
                    <span class="info-label">Last Online</span>
                    <span class="info-value"><?= date('M d, h:i A', strtotime($device['last_online_at'])) ?></span>
                </div>
                <?php endif; ?>
            </div>

            <div class="device-stats">
                <div class="stat-box">
                    <div class="stat-value"><?= $device['mapped_employees'] ?></div>
                    <div class="stat-label">Mapped Employees</div>
                </div>
                <div class="stat-box">
                    <div class="stat-value"><?= $device['pulls_today'] ?></div>
                    <div class="stat-label">Pulls Today</div>
                </div>
            </div>

            <?php if ($device['description']): ?>
            <div style="padding: 12px; background: #f8f9fa; border-radius: 6px; margin-bottom: 15px;">
                <small style="color: #6c757d;"><?= htmlspecialchars($device['description']) ?></small>
            </div>
            <?php endif; ?>

            <div class="device-actions">
                <button onclick='editDevice(<?= json_encode($device) ?>)' class="btn btn-warning btn-sm">
                    ✏️ Edit
                </button>
                <form method="POST" style="margin: 0;" onsubmit="return confirm('Toggle device status?')">
                    <input type="hidden" name="action" value="toggle_active">
                    <input type="hidden" name="device_id" value="<?= $device['id'] ?>">
                    <button type="submit" class="btn btn-<?= $device['is_active'] ? 'secondary' : 'success' ?> btn-sm" style="width: 100%;">
                        <?= $device['is_active'] ? '⏸️ Deactivate' : '▶️ Activate' ?>
                    </button>
                </form>
                <a href="zkteco_pull_history.php?device_id=<?= $device['id'] ?>" class="btn btn-info btn-sm">
                    📋 View History
                </a>
                <form method="POST" style="margin: 0;" onsubmit="return confirm('Delete this device? This will also delete all related mappings and pull logs!')">
                    <input type="hidden" name="action" value="delete_device">
                    <input type="hidden" name="device_id" value="<?= $device['id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm" style="width: 100%;">
                        🗑️ Delete
                    </button>
                </form>
            </div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<!-- Add/Edit Device Modal -->
<div id="deviceModal" class="modal">
    <div class="modal-content">
        <div class="modal-header" id="modalTitle">Add New Device</div>
        <form method="POST">
            <input type="hidden" name="action" id="formAction" value="add_device">
            <input type="hidden" name="device_id" id="device_id">

            <div class="form-row">
                <div class="form-group">
                    <label>Device Name <span style="color: red;">*</span></label>
                    <input type="text" name="device_name" id="device_name" class="form-control" required>
                </div>
                <div class="form-group">
                    <label>Device Code <span style="color: red;">*</span></label>
                    <input type="text" name="device_code" id="device_code" class="form-control" required>
                    <div class="help-text">Unique identifier (e.g., ZK001)</div>
                </div>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>IP Address <span style="color: red;">*</span></label>
                    <input type="text" name="ip_address" id="ip_address" class="form-control" 
                           placeholder="192.168.1.100" required>
                </div>
                <div class="form-group">
                    <label>Port <span style="color: red;">*</span></label>
                    <input type="number" name="port" id="port" class="form-control" 
                           value="4370" min="1" max="65535" required>
                </div>
            </div>

            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" id="location" class="form-control" 
                       placeholder="Main Gate, Production Floor, etc.">
            </div>

            <div class="form-group">
                <label>Description</label>
                <textarea name="description" id="description" class="form-control" rows="2" 
                          placeholder="Optional notes about this device"></textarea>
            </div>

            <div class="form-row">
                <div class="form-group">
                    <label>Priority</label>
                    <input type="number" name="priority" id="priority" class="form-control" 
                           value="1" min="1" max="100">
                    <div class="help-text">Lower number = higher priority</div>
                </div>
                <div class="form-group">
                    <label>Timeout (seconds)</label>
                    <input type="number" name="timeout" id="timeout" class="form-control" 
                           value="5" min="1" max="30">
                </div>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" name="disable_during_pull" id="disable_during_pull" checked>
                    <label for="disable_during_pull" style="margin: 0;">Disable device during pull</label>
                </div>
                <div class="help-text">Prevents new punches during data retrieval</div>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" name="auto_clear_records" id="auto_clear_records">
                    <label for="auto_clear_records" style="margin: 0;">Auto-clear old records</label>
                </div>
                <div class="help-text">Automatically delete old records from device memory</div>
            </div>

            <div class="form-group">
                <div class="checkbox-group">
                    <input type="checkbox" name="is_active" id="is_active" checked>
                    <label for="is_active" style="margin: 0;">Active</label>
                </div>
            </div>

            <div class="modal-footer">
                <button type="button" onclick="closeModal()" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-primary" id="submitBtn">Save Device</button>
            </div>
        </form>
    </div>
</div>

<script>
function showAddDevice() {
    document.getElementById('modalTitle').textContent = 'Add New Device';
    document.getElementById('formAction').value = 'add_device';
    document.getElementById('submitBtn').textContent = 'Save Device';
    
    // Reset form
    document.getElementById('device_id').value = '';
    document.getElementById('device_name').value = '';
    document.getElementById('device_code').value = '';
    document.getElementById('ip_address').value = '';
    document.getElementById('port').value = '4370';
    document.getElementById('location').value = '';
    document.getElementById('description').value = '';
    document.getElementById('priority').value = '1';
    document.getElementById('timeout').value = '5';
    document.getElementById('disable_during_pull').checked = true;
    document.getElementById('auto_clear_records').checked = false;
    document.getElementById('is_active').checked = true;
    
    document.getElementById('deviceModal').classList.add('show');
}

function editDevice(device) {
    document.getElementById('modalTitle').textContent = 'Edit Device';
    document.getElementById('formAction').value = 'edit_device';
    document.getElementById('submitBtn').textContent = 'Update Device';
    
    // Fill form
    document.getElementById('device_id').value = device.id;
    document.getElementById('device_name').value = device.device_name;
    document.getElementById('device_code').value = device.device_code;
    document.getElementById('ip_address').value = device.ip_address;
    document.getElementById('port').value = device.port;
    document.getElementById('location').value = device.location || '';
    document.getElementById('description').value = device.description || '';
    document.getElementById('priority').value = device.priority;
    document.getElementById('timeout').value = device.timeout;
    document.getElementById('disable_during_pull').checked = device.disable_during_pull;
    document.getElementById('auto_clear_records').checked = device.auto_clear_records;
    document.getElementById('is_active').checked = device.is_active;
    
    document.getElementById('deviceModal').classList.add('show');
}

function closeModal() {
    document.getElementById('deviceModal').classList.remove('show');
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

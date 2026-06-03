<?php
/**
 * ZKTeco Device Management
 * Add, edit, delete, and test devices
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get all devices
$devices = $conn->query("
    SELECT 
        d.*,
        COUNT(DISTINCT zum.employee_id) as mapped_users,
        (SELECT COUNT(*) FROM zkteco_pull_log WHERE device_id = d.id) as total_pulls
    FROM zkteco_devices d
    LEFT JOIN zkteco_user_mapping zum ON d.id = zum.device_id AND zum.is_active = true
    GROUP BY d.id
    ORDER BY d.priority, d.device_name
")->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
.container { max-width: 1400px; margin: 0 auto; padding: 20px; }
.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
}
.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    transition: all 0.2s;
}
.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-danger { background: #dc3545; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }

.devices-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
table { width: 100%; border-collapse: collapse; }
th {
    background: #f8f9fa;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    color: #333;
    border-bottom: 2px solid #dee2e6;
}
td { padding: 15px; border-bottom: 1px solid #f0f0f0; }
tr:hover { background: #f8f9fa; }

.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 12px;
    font-weight: 600;
}
.status-online { background: #d4edda; color: #155724; }
.status-offline { background: #f8d7da; color: #721c24; }
.status-unknown { background: #fff3cd; color: #856404; }

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
.modal.show { display: flex; }
.modal-content {
    background: white;
    padding: 30px;
    border-radius: 12px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.form-group { margin-bottom: 15px; }
.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
}
.form-control {
    width: 100%;
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
}
.form-control:focus { outline: none; border-color: #667eea; }
</style>

<div class="container">
    <div class="page-header">
        <h1>🖥️ Device Management</h1>
        <p>Manage ZKTeco attendance devices</p>
    </div>

    <div style="margin-bottom: 20px;">
        <button onclick="showAddDeviceModal()" class="btn btn-primary">
            ➕ Add New Device
        </button>
        <button onclick="testAllDevices()" class="btn btn-info">
            🔌 Test All Devices
        </button>
        <button onclick="syncAllDevices()" class="btn btn-success">
            🔄 Sync All Devices
        </button>
    </div>

    <div id="alertArea"></div>

    <div class="devices-table">
        <table>
            <thead>
                <tr>
                    <th>Device Name</th>
                    <th>Code</th>
                    <th>IP Address</th>
                    <th>Location</th>
                    <th>Status</th>
                    <th>Users</th>
                    <th>Last Pull</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($devices)): ?>
                <tr>
                    <td colspan="8" style="text-align: center; padding: 40px;">
                        No devices configured. Click "Add New Device" to get started.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($devices as $device): ?>
                <tr>
                    <td>
                        <strong><?= htmlspecialchars($device['device_name']) ?></strong>
                        <?php if (!$device['is_active']): ?>
                        <br><small style="color: #dc3545;">Inactive</small>
                        <?php endif; ?>
                    </td>
                    <td><code><?= htmlspecialchars($device['device_code']) ?></code></td>
                    <td><?= htmlspecialchars($device['ip_address']) ?>:<?= $device['port'] ?></td>
                    <td><?= htmlspecialchars($device['location'] ?? 'N/A') ?></td>
                    <td>
                        <span class="status-badge status-<?= strtolower($device['connection_status'] ?? 'unknown') ?>">
                            <?= $device['connection_status'] ?? 'UNKNOWN' ?>
                        </span>
                    </td>
                    <td><?= $device['mapped_users'] ?></td>
                    <td>
                        <?= $device['last_pull_at'] 
                            ? date('M d, H:i', strtotime($device['last_pull_at'])) 
                            : 'Never' ?>
                    </td>
                    <td>
                        <button onclick="testDevice(<?= $device['id'] ?>)" class="btn btn-info" style="font-size: 12px; padding: 5px 10px;">
                            Test
                        </button>
                        <button onclick="editDevice(<?= $device['id'] ?>)" class="btn btn-primary" style="font-size: 12px; padding: 5px 10px;">
                            Edit
                        </button>
                        <button onclick="viewDeviceInfo(<?= $device['id'] ?>)" class="btn btn-success" style="font-size: 12px; padding: 5px 10px;">
                            Info
                        </button>
                        <button onclick="deleteDevice(<?= $device['id'] ?>)" class="btn btn-danger" style="font-size: 12px; padding: 5px 10px;">
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Add/Edit Device Modal -->
<div id="deviceModal" class="modal">
    <div class="modal-content">
        <h2 id="modalTitle">Add Device</h2>
        <form id="deviceForm">
            <input type="hidden" id="deviceId" name="id">
            
            <div class="form-group">
                <label>Device Name *</label>
                <input type="text" name="device_name" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>Device Code *</label>
                <input type="text" name="device_code" class="form-control" required>
            </div>
            
            <div class="form-group">
                <label>IP Address *</label>
                <input type="text" name="ip_address" class="form-control" required placeholder="192.168.1.100">
            </div>
            
            <div class="form-group">
                <label>Port</label>
                <input type="number" name="port" class="form-control" value="4370">
            </div>
            
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" class="form-control">
            </div>
            
            <div class="form-group">
                <label>Timeout (seconds)</label>
                <input type="number" name="timeout" class="form-control" value="5">
            </div>
            
            <div class="form-group">
                <label>Priority</label>
                <input type="number" name="priority" class="form-control" value="0">
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" checked> Active
                </label>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="disable_during_pull" value="1" checked> Disable during pull
                </label>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="auto_clear_records" value="1"> Auto clear old records
                </label>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeModal('deviceModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-success">Save Device</button>
            </div>
        </form>
    </div>
</div>

<!-- Device Info Modal -->
<div id="infoModal" class="modal">
    <div class="modal-content">
        <h2>Device Information</h2>
        <div id="deviceInfo"></div>
        <button onclick="closeModal('infoModal')" class="btn btn-secondary" style="margin-top: 20px;">Close</button>
    </div>
</div>

<script>
function showAddDeviceModal() {
    document.getElementById('modalTitle').textContent = 'Add Device';
    document.getElementById('deviceForm').reset();
    document.getElementById('deviceId').value = '';
    document.getElementById('deviceModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

document.getElementById('deviceForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', document.getElementById('deviceId').value ? 'update_device' : 'add_device');
    
    try {
        const res = await fetch('zkteco_ajax_device.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            showAlert('✅ ' + data.message, 'success');
            closeModal('deviceModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
});

async function testDevice(id) {
    showAlert('Testing connection...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('device_id', id);
    
    try {
        const res = await fetch('zkteco_ajax_device.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showAlert('✅ Connection successful!', 'success');
        } else {
            showAlert('❌ Connection failed: ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
}

async function viewDeviceInfo(id) {
    const formData = new FormData();
    formData.append('action', 'get_device_info');
    formData.append('device_id', id);
    
    try {
        const res = await fetch('zkteco_ajax_device.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            const info = data.info;
            document.getElementById('deviceInfo').innerHTML = `
                <div style="line-height: 2;">
                    <strong>Version:</strong> ${info.version || 'N/A'}<br>
                    <strong>Serial Number:</strong> ${info.serial_number || 'N/A'}<br>
                    <strong>Platform:</strong> ${info.platform || 'N/A'}<br>
                    <strong>OS:</strong> ${info.os_version || 'N/A'}<br>
                    <strong>Device Time:</strong> ${info.device_time || 'N/A'}<br>
                    <strong>Users:</strong> ${info.users_count || 0} / ${info.capacity_users || 0}<br>
                    <strong>Logs:</strong> ${info.logs_count || 0} / ${info.capacity_logs || 0}<br>
                </div>
            `;
            document.getElementById('infoModal').classList.add('show');
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
}

async function deleteDevice(id) {
    if (!confirm('Are you sure you want to delete this device? This will also delete all associated mappings.')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_device');
    formData.append('device_id', id);
    
    try {
        const res = await fetch('zkteco_ajax_device.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showAlert('✅ Device deleted', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
}

function showAlert(message, type) {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.style.cssText = 'padding: 15px; margin-bottom: 20px; border-radius: 6px;';
    
    if (type === 'success') alert.style.background = '#d4edda';
    if (type === 'danger') alert.style.background = '#f8d7da';
    if (type === 'info') alert.style.background = '#d1ecf1';
    
    alert.textContent = message;
    
    const area = document.getElementById('alertArea');
    area.innerHTML = '';
    area.appendChild(alert);
    
    setTimeout(() => alert.remove(), 5000);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

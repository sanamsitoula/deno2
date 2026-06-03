<?php
/**
 * ZKTeco User Mapping Management
 * Map device users to employees
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get devices
$devices = $conn->query("SELECT * FROM zkteco_devices WHERE is_active = true ORDER BY device_name")->fetchAll(PDO::FETCH_ASSOC);

// Get selected device
$selected_device = $_GET['device'] ?? ($devices[0]['id'] ?? null);

// Get mappings for selected device
$mappings = [];
if ($selected_device) {
    $stmt = $conn->prepare("
        SELECT 
            zum.*,
            e.name as employee_name,
            e.emp_code as employee_code,
            e.emp_type,
            d.device_name,
            s.name as shift_name
        FROM zkteco_user_mapping zum
        JOIN employee e ON zum.employee_id = e.id
        JOIN zkteco_devices d ON zum.device_id = d.id
        LEFT JOIN shifts s ON zum.shift_id = s.id
        WHERE zum.device_id = :device_id
        ORDER BY e.name
    ");
    $stmt->execute([':device_id' => $selected_device]);
    $mappings = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

// Get unmapped employees
$stmt = $conn->query("
    SELECT id, name, emp_code, attendance_id, emp_type
    FROM employee
    WHERE deleted_date IS NULL
    AND id NOT IN (
        SELECT employee_id FROM zkteco_user_mapping WHERE is_active = true
    )
    ORDER BY name
");
$unmapped_employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get shifts
$shifts = $conn->query("SELECT * FROM shifts ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);

?>

<style>
.container { max-width: 1600px; margin: 0 auto; padding: 20px; }
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
.btn-warning { background: #ffc107; color: #333; }
.btn:hover { transform: translateY(-2px); box-shadow: 0 4px 12px rgba(0,0,0,0.2); }

.device-selector {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}

.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}
.stat-card {
    background: white;
    padding: 20px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
}
.stat-value { font-size: 32px; font-weight: 700; color: #333; }
.stat-label { font-size: 14px; color: #6c757d; margin-top: 5px; }

.mappings-table {
    background: white;
    border-radius: 12px;
    overflow: hidden;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    margin-bottom: 30px;
}
table { width: 100%; border-collapse: collapse; }
th {
    background: #f8f9fa;
    padding: 15px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
}
td { padding: 15px; border-bottom: 1px solid #f0f0f0; }
tr:hover { background: #f8f9fa; }

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
    max-width: 700px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}
.form-group { margin-bottom: 15px; }
.form-group label { display: block; margin-bottom: 5px; font-weight: 600; }
.form-control {
    width: 100%;
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
}
.form-control:focus { outline: none; border-color: #667eea; }

.sync-badge {
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
}
.sync-yes { background: #d4edda; color: #155724; }
.sync-no { background: #fff3cd; color: #856404; }
</style>

<div class="container">
    <div class="page-header">
        <h1>👥 User Mapping Management</h1>
        <p>Map device users to employees</p>
    </div>

    <!-- Device Selector -->
    <div class="device-selector">
        <label style="font-weight: 600; margin-bottom: 10px; display: block;">Select Device:</label>
        <select id="deviceSelect" class="form-control" style="max-width: 400px;" onchange="location.href='?device='+this.value">
            <option value="">-- Select Device --</option>
            <?php foreach ($devices as $device): ?>
            <option value="<?= $device['id'] ?>" <?= $selected_device == $device['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($device['device_name']) ?> (<?= $device['ip_address'] ?>)
            </option>
            <?php endforeach; ?>
        </select>
    </div>

    <?php if ($selected_device): ?>
    
    <!-- Stats -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-value"><?= count($mappings) ?></div>
            <div class="stat-label">Total Mappings</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count(array_filter($mappings, fn($m) => $m['synced_to_device'])) ?></div>
            <div class="stat-label">Synced to Device</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count(array_filter($mappings, fn($m) => !$m['synced_to_device'])) ?></div>
            <div class="stat-label">Pending Sync</div>
        </div>
        <div class="stat-card">
            <div class="stat-value"><?= count($unmapped_employees) ?></div>
            <div class="stat-label">Unmapped Employees</div>
        </div>
    </div>

    <!-- Actions -->
    <div style="margin-bottom: 20px; display: flex; gap: 10px;">
        <button onclick="showAddMappingModal()" class="btn btn-primary">
            ➕ Add Mapping
        </button>
        <button onclick="pullDeviceUsers()" class="btn btn-info">
            📥 Pull Users from Device
        </button>
        <button onclick="syncAllMappings()" class="btn btn-success">
            🔄 Sync All to Device
        </button>
        <button onclick="autoMapUsers()" class="btn btn-warning">
            🤖 Auto-Map by Attendance ID
        </button>
    </div>

    <div id="alertArea"></div>

    <!-- Mappings Table -->
    <div class="mappings-table">
        <table>
            <thead>
                <tr>
                    <th>Employee</th>
                    <th>Emp Code</th>
                    <th>Device User ID</th>
                    <th>Device UID</th>
                    <th>Shift</th>
                    <th>Type</th>
                    <th>Synced</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($mappings)): ?>
                <tr>
                    <td colspan="9" style="text-align: center; padding: 40px;">
                        No mappings found for this device. Click "Add Mapping" or "Pull Users from Device" to start.
                    </td>
                </tr>
                <?php else: ?>
                <?php foreach ($mappings as $mapping): ?>
                <tr>
                    <td><strong><?= htmlspecialchars($mapping['employee_name']) ?></strong></td>
                    <td><code><?= htmlspecialchars($mapping['employee_code']) ?></code></td>
                    <td><code><?= htmlspecialchars($mapping['device_user_id']) ?></code></td>
                    <td><?= $mapping['device_uid'] ?></td>
                    <td><?= htmlspecialchars($mapping['shift_name'] ?? 'Default') ?></td>
                    <td><?= $mapping['shift_type'] ?></td>
                    <td>
                        <span class="sync-badge sync-<?= $mapping['synced_to_device'] ? 'yes' : 'no' ?>">
                            <?= $mapping['synced_to_device'] ? 'YES' : 'NO' ?>
                        </span>
                    </td>
                    <td>
                        <?= $mapping['is_active'] ? '✅ Active' : '❌ Inactive' ?>
                    </td>
                    <td>
                        <?php if (!$mapping['synced_to_device']): ?>
                        <button onclick="syncMapping(<?= $mapping['id'] ?>)" class="btn btn-success" style="font-size: 12px; padding: 5px 10px;">
                            Sync
                        </button>
                        <?php endif; ?>
                        <button onclick="editMapping(<?= $mapping['id'] ?>)" class="btn btn-primary" style="font-size: 12px; padding: 5px 10px;">
                            Edit
                        </button>
                        <button onclick="deleteMapping(<?= $mapping['id'] ?>)" class="btn btn-danger" style="font-size: 12px; padding: 5px 10px;">
                            Delete
                        </button>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>

    <?php endif; ?>
</div>

<!-- Add/Edit Mapping Modal -->
<div id="mappingModal" class="modal">
    <div class="modal-content">
        <h2 id="modalTitle">Add User Mapping</h2>
        <form id="mappingForm">
            <input type="hidden" id="mappingId" name="id">
            <input type="hidden" name="device_id" value="<?= $selected_device ?>">
            
            <div class="form-group">
                <label>Employee *</label>
                <select name="employee_id" class="form-control" required>
                    <option value="">-- Select Employee --</option>
                    <?php foreach ($unmapped_employees as $emp): ?>
                    <option value="<?= $emp['id'] ?>">
                        <?= htmlspecialchars($emp['name']) ?> (<?= $emp['emp_code'] ?>)
                        <?php if ($emp['attendance_id']): ?>
                        - ID: <?= $emp['attendance_id'] ?>
                        <?php endif; ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Device User ID *</label>
                <input type="text" name="device_user_id" class="form-control" required>
                <small>The user ID on the device (usually numeric)</small>
            </div>
            
            <div class="form-group">
                <label>Device UID *</label>
                <input type="number" name="device_uid" class="form-control" required>
                <small>The unique ID on the device</small>
            </div>
            
            <div class="form-group">
                <label>Shift</label>
                <select name="shift_id" class="form-control">
                    <option value="">Default</option>
                    <?php foreach ($shifts as $shift): ?>
                    <option value="<?= $shift['id'] ?>"><?= htmlspecialchars($shift['name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            
            <div class="form-group">
                <label>Shift Type</label>
                <select name="shift_type" class="form-control">
                    <option value="REGULAR">Regular (8 hours)</option>
                    <option value="DUTY_24HR">24 Hour Duty</option>
                    <option value="FLEXIBLE">Flexible</option>
                </select>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="is_active" value="1" checked> Active
                </label>
            </div>
            
            <div class="form-group">
                <label>
                    <input type="checkbox" name="push_to_device" value="1" checked> Push to device immediately
                </label>
            </div>
            
            <div class="form-group">
                <label>Notes</label>
                <textarea name="notes" class="form-control" rows="3"></textarea>
            </div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeModal('mappingModal')" class="btn btn-secondary">Cancel</button>
                <button type="submit" class="btn btn-success">Save Mapping</button>
            </div>
        </form>
    </div>
</div>

<!-- Device Users Modal -->
<div id="deviceUsersModal" class="modal">
    <div class="modal-content">
        <h2>Users from Device</h2>
        <div id="deviceUsersList"></div>
        <button onclick="closeModal('deviceUsersModal')" class="btn btn-secondary" style="margin-top: 20px;">Close</button>
    </div>
</div>

<script>
const deviceId = <?= $selected_device ?: 'null' ?>;

function showAddMappingModal() {
    document.getElementById('modalTitle').textContent = 'Add User Mapping';
    document.getElementById('mappingForm').reset();
    document.getElementById('mappingId').value = '';
    document.getElementById('mappingModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

document.getElementById('mappingForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const formData = new FormData(e.target);
    formData.append('action', document.getElementById('mappingId').value ? 'update_mapping' : 'add_mapping');
    
    try {
        const res = await fetch('zkteco_ajax_mapping.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        if (data.success) {
            showAlert('✅ ' + data.message, 'success');
            closeModal('mappingModal');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
});

async function pullDeviceUsers() {
    if (!deviceId) {
        showAlert('Please select a device first', 'warning');
        return;
    }
    
    showAlert('Pulling users from device...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'pull_device_users');
    formData.append('device_id', deviceId);
    
    try {
        const res = await fetch('zkteco_ajax_mapping.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            const users = data.users;
            let html = '<table style="width: 100%;"><thead><tr><th>UID</th><th>User ID</th><th>Name</th><th>Action</th></tr></thead><tbody>';
            
            users.forEach(user => {
                html += `<tr>
                    <td>${user.uid}</td>
                    <td>${user.userid}</td>
                    <td>${user.name}</td>
                    <td><button onclick="quickMapUser(${user.uid}, '${user.userid}')" class="btn btn-primary" style="font-size: 12px; padding: 5px 10px;">Map</button></td>
                </tr>`;
            });
            
            html += '</tbody></table>';
            document.getElementById('deviceUsersList').innerHTML = html;
            document.getElementById('deviceUsersModal').classList.add('show');
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
}

async function autoMapUsers() {
    if (!deviceId) {
        showAlert('Please select a device first', 'warning');
        return;
    }
    
    if (!confirm('This will automatically map employees using their attendance_id field. Continue?')) return;
    
    showAlert('Auto-mapping users...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'auto_map_users');
    formData.append('device_id', deviceId);
    
    try {
        const res = await fetch('zkteco_ajax_mapping.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showAlert(`✅ Mapped ${data.mapped_count} users successfully`, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
}

async function syncAllMappings() {
    if (!deviceId) return;
    
    if (!confirm('Sync all mappings to device? This may take a while.')) return;
    
    showAlert('Syncing all mappings...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'sync_all_mappings');
    formData.append('device_id', deviceId);
    
    try {
        const res = await fetch('zkteco_ajax_mapping.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showAlert(`✅ Synced ${data.synced_count} mappings`, 'success');
            setTimeout(() => location.reload(), 2000);
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
}

async function syncMapping(id) {
    const formData = new FormData();
    formData.append('action', 'sync_mapping');
    formData.append('mapping_id', id);
    
    try {
        const res = await fetch('zkteco_ajax_mapping.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showAlert('✅ Mapping synced to device', 'success');
            setTimeout(() => location.reload(), 1000);
        } else {
            showAlert('❌ ' + data.error, 'danger');
        }
    } catch (err) {
        showAlert('Error: ' + err.message, 'danger');
    }
}

async function deleteMapping(id) {
    if (!confirm('Delete this mapping?')) return;
    
    const formData = new FormData();
    formData.append('action', 'delete_mapping');
    formData.append('mapping_id', id);
    
    try {
        const res = await fetch('zkteco_ajax_mapping.php', { method: 'POST', body: formData });
        const data = await res.json();
        
        if (data.success) {
            showAlert('✅ Mapping deleted', 'success');
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
    alert.style.cssText = 'padding: 15px; margin-bottom: 20px; border-radius: 6px;';
    
    if (type === 'success') alert.style.background = '#d4edda';
    if (type === 'danger') alert.style.background = '#f8d7da';
    if (type === 'info') alert.style.background = '#d1ecf1';
    if (type === 'warning') alert.style.background = '#fff3cd';
    
    alert.textContent = message;
    
    const area = document.getElementById('alertArea');
    area.innerHTML = '';
    area.appendChild(alert);
    
    setTimeout(() => alert.remove(), 5000);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

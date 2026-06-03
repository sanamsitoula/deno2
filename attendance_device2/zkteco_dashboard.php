<?php
/**
 * ZKTeco Attendance Management Dashboard
 * Main overview and quick actions
 */
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get statistics
$stats = [];

// Device stats
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN is_active THEN 1 END) as active,
        COUNT(CASE WHEN connection_status = 'ONLINE' THEN 1 END) as online
    FROM zkteco_devices
");
$stats['devices'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Mapping stats
$stmt = $conn->query("
    SELECT 
        COUNT(DISTINCT employee_id) as mapped,
        COUNT(CASE WHEN synced_to_device THEN 1 END) as synced
    FROM zkteco_user_mapping 
    WHERE is_active = true
");
$stats['mappings'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Today's pull stats
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total_pulls,
        COUNT(CASE WHEN status = 'SUCCESS' THEN 1 END) as successful,
        SUM(inserted_records) as inserted,
        SUM(updated_records) as updated
    FROM zkteco_pull_log 
    WHERE pull_date = CURRENT_DATE
");
$stats['today'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Recent pulls
$stmt = $conn->query("
    SELECT 
        pl.*,
        d.device_name,
        d.ip_address
    FROM zkteco_pull_log pl
    JOIN zkteco_devices d ON pl.device_id = d.id
    ORDER BY pl.started_at DESC 
    LIMIT 10
");
$recent_pulls = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get devices for quick actions
$devices = $conn->query("
    SELECT 
        d.*,
        COUNT(DISTINCT zum.employee_id) as mapped_users
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
    margin: 0;
    padding: 0;
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
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}
.page-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 10px 0;
}
.stats-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}
.stat-card {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    border-left: 4px solid #667eea;
}
.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
    transition: all 0.2s;
}
.stat-icon { font-size: 36px; margin-bottom: 10px; }
.stat-value {
    font-size: 32px;
    font-weight: 700;
    color: #333;
    margin-bottom: 5px;
}
.stat-label {
    font-size: 14px;
    color: #6c757d;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}
.stat-detail {
    font-size: 12px;
    color: #999;
    margin-top: 10px;
}
.quick-actions {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}
.action-buttons {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 20px;
}
.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    transition: all 0.2s;
}
.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-warning { background: #ffc107; color: #333; }
.btn-danger { background: #dc3545; color: white; }
.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}
.devices-section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}
.device-card {
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 15px;
    margin-bottom: 15px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}
.device-card:hover { background: #f8f9fa; }
.device-info { flex: 1; }
.device-name {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 5px;
}
.device-details {
    font-size: 13px;
    color: #6c757d;
}
.device-actions {
    display: flex;
    gap: 10px;
}
.recent-pulls {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}
table {
    width: 100%;
    border-collapse: collapse;
}
th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    border-bottom: 2px solid #dee2e6;
}
td {
    padding: 12px;
    border-bottom: 1px solid #f0f0f0;
}
.status-success { color: #28a745; font-weight: 600; }
.status-partial { color: #ffc107; font-weight: 600; }
.status-failed { color: #dc3545; font-weight: 600; }
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
    max-width: 500px;
    width: 90%;
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
.alert {
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}
.alert-success { background: #d4edda; color: #155724; }
.alert-danger { background: #f8d7da; color: #721c24; }
.alert-info { background: #d1ecf1; color: #0c5460; }
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">🔌 ZKTeco Attendance Dashboard</h1>
        <p>Device monitoring, manual pulls, and attendance tracking</p>
    </div>

    <div id="alertArea"></div>

    <!-- Statistics -->
    <div class="stats-grid">
        <div class="stat-card">
            <div class="stat-icon">🖥️</div>
            <div class="stat-value"><?= $stats['devices']['online'] ?>/<?= $stats['devices']['active'] ?></div>
            <div class="stat-label">Online Devices</div>
            <div class="stat-detail">Total: <?= $stats['devices']['total'] ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">👥</div>
            <div class="stat-value"><?= $stats['mappings']['mapped'] ?></div>
            <div class="stat-label">Mapped Employees</div>
            <div class="stat-detail">Synced: <?= $stats['mappings']['synced'] ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-value"><?= $stats['today']['total_pulls'] ?></div>
            <div class="stat-label">Pulls Today</div>
            <div class="stat-detail">Success: <?= $stats['today']['successful'] ?></div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📥</div>
            <div class="stat-value"><?= $stats['today']['inserted'] + $stats['today']['updated'] ?></div>
            <div class="stat-label">Records Today</div>
            <div class="stat-detail">New: <?= $stats['today']['inserted'] ?> | Updated: <?= $stats['today']['updated'] ?></div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <h3>⚡ Quick Actions</h3>
        <div class="action-buttons">
            <a href="zkteco_device_management.php" class="btn btn-primary">
                🖥️ Manage Devices
            </a>
            <a href="zkteco_user_mapping.php" class="btn btn-info">
                👥 User Mapping
            </a>
            <button onclick="pullAllDevices()" class="btn btn-success">
                📥 Pull All Devices
            </button>
            <button onclick="testAllDevices()" class="btn btn-warning">
                🔌 Test All Devices
            </button>
        </div>
    </div>

    <!-- Devices -->
    <div class="devices-section">
        <h3>🖥️ Devices - Quick Pull</h3>
        
        <?php foreach ($devices as $device): ?>
        <div class="device-card">
            <div class="device-info">
                <div class="device-name">
                    <?= htmlspecialchars($device['device_name']) ?>
                    <?php if ($device['connection_status'] === 'ONLINE'): ?>
                    <span style="color: #28a745;">●</span>
                    <?php else: ?>
                    <span style="color: #dc3545;">●</span>
                    <?php endif; ?>
                </div>
                <div class="device-details">
                    <?= htmlspecialchars($device['ip_address']) ?> | 
                    <?= $device['mapped_users'] ?> users | 
                    Last: <?= $device['last_pull_at'] ? date('M d, H:i', strtotime($device['last_pull_at'])) : 'Never' ?>
                </div>
            </div>
            <div class="device-actions">
                <button onclick="manualPull(<?= $device['id'] ?>, '<?= htmlspecialchars($device['device_name']) ?>')" 
                        class="btn btn-success">
                    📥 Pull Now
                </button>
                <button onclick="testDevice(<?= $device['id'] ?>)" 
                        class="btn btn-info">
                    🔌 Test
                </button>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Recent Pulls -->
    <div class="recent-pulls">
        <h3>📋 Recent Pulls</h3>
        <table>
            <thead>
                <tr>
                    <th>Time</th>
                    <th>Device</th>
                    <th>Schedule</th>
                    <th>Records</th>
                    <th>Status</th>
                    <th>Duration</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_pulls as $pull): ?>
                <tr>
                    <td><?= date('M d, H:i', strtotime($pull['started_at'])) ?></td>
                    <td><?= htmlspecialchars($pull['device_name']) ?></td>
                    <td><?= ucfirst($pull['schedule_type'] ?? 'Manual') ?></td>
                    <td>
                        ↑<?= $pull['inserted_records'] ?> 
                        ↻<?= $pull['updated_records'] ?>
                        ✗<?= $pull['error_records'] ?>
                    </td>
                    <td class="status-<?= strtolower($pull['status']) ?>">
                        <?= $pull['status'] ?>
                    </td>
                    <td><?= number_format($pull['duration_seconds'], 2) ?>s</td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Manual Pull Modal -->
<div id="pullModal" class="modal">
    <div class="modal-content">
        <h2>Manual Pull</h2>
        <form id="pullForm">
            <input type="hidden" id="pullDeviceId">
            
            <div class="form-group">
                <label>Device</label>
                <input type="text" id="pullDeviceName" class="form-control" readonly>
            </div>
            
            <div class="form-group">
                <label>Date</label>
                <input type="date" id="pullDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            
            <div class="form-group">
                <label>Schedule Type (optional)</label>
                <select id="pullSchedule" class="form-control">
                    <option value="">Auto-detect</option>
                    <option value="morning">Morning (07:35)</option>
                    <option value="midmorning">Mid-Morning (10:45)</option>
                    <option value="afternoon">Afternoon (13:25)</option>
                    <option value="evening">Evening (17:25)</option>
                    <option value="night">Night (19:15)</option>
                </select>
            </div>
            
            <div id="pullProgress" style="display: none; text-align: center; padding: 20px;">
                <div style="border: 4px solid #f3f3f3; border-top: 4px solid #667eea; border-radius: 50%; width: 40px; height: 40px; animation: spin 1s linear infinite; margin: 0 auto;"></div>
                <p style="margin-top: 10px;">Pulling data...</p>
            </div>
            
            <div id="pullResult"></div>
            
            <div style="display: flex; gap: 10px; margin-top: 20px;">
                <button type="button" onclick="closeModal('pullModal')" class="btn btn-danger">Cancel</button>
                <button type="submit" class="btn btn-success" id="pullBtn">Start Pull</button>
            </div>
        </form>
    </div>
</div>

<style>
@keyframes spin {
    0% { transform: rotate(0deg); }
    100% { transform: rotate(360deg); }
}
</style>

<script>
function manualPull(deviceId, deviceName) {
    document.getElementById('pullDeviceId').value = deviceId;
    document.getElementById('pullDeviceName').value = deviceName;
    document.getElementById('pullResult').innerHTML = '';
    document.getElementById('pullProgress').style.display = 'none';
    document.getElementById('pullModal').classList.add('show');
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

document.getElementById('pullForm').addEventListener('submit', async (e) => {
    e.preventDefault();
    
    const deviceId = document.getElementById('pullDeviceId').value;
    const date = document.getElementById('pullDate').value;
    const schedule = document.getElementById('pullSchedule').value;
    
    document.getElementById('pullProgress').style.display = 'block';
    document.getElementById('pullBtn').disabled = true;
    document.getElementById('pullResult').innerHTML = '';
    
    const formData = new FormData();
    formData.append('action', 'manual_pull');
    formData.append('device_id', deviceId);
    formData.append('date', date);
    formData.append('schedule', schedule);
    
    try {
        const res = await fetch('zkteco_ajax_pull.php', {
            method: 'POST',
            body: formData
        });
        const data = await res.json();
        
        document.getElementById('pullProgress').style.display = 'none';
        document.getElementById('pullBtn').disabled = false;
        
        if (data.success) {
            const stats = data.stats;
            document.getElementById('pullResult').innerHTML = `
                <div class="alert alert-success">
                    <strong>✅ Pull Completed!</strong><br><br>
                    Inserted: ${stats.inserted}<br>
                    Updated: ${stats.updated}<br>
                    Skipped: ${stats.skipped}<br>
                    Errors: ${stats.errors}<br>
                    Employees: ${stats.employees_count}
                </div>
            `;
            setTimeout(() => location.reload(), 3000);
        } else {
            document.getElementById('pullResult').innerHTML = `
                <div class="alert alert-danger">
                    <strong>❌ Pull Failed</strong><br>
                    ${data.error}
                </div>
            `;
        }
    } catch (err) {
        document.getElementById('pullProgress').style.display = 'none';
        document.getElementById('pullBtn').disabled = false;
        document.getElementById('pullResult').innerHTML = `
            <div class="alert alert-danger">
                Error: ${err.message}
            </div>
        `;
    }
});

async function testDevice(deviceId) {
    showAlert('Testing connection...', 'info');
    
    const formData = new FormData();
    formData.append('action', 'test_connection');
    formData.append('device_id', deviceId);
    
    try {
        const res = await fetch('zkteco_ajax_pull.php', { method: 'POST', body: formData });
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

function showAlert(message, type) {
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.textContent = message;
    
    const area = document.getElementById('alertArea');
    area.innerHTML = '';
    area.appendChild(alert);
    
    setTimeout(() => alert.remove(), 5000);
}

window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

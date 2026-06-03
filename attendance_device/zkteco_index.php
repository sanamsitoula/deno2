<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get statistics
$stats = [];

// Total devices
$stmt = $conn->query("SELECT COUNT(*) as total, COUNT(CASE WHEN is_active THEN 1 END) as active FROM zkteco_devices");
$stats['devices'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Total mapped employees
$stmt = $conn->query("SELECT COUNT(DISTINCT employee_id) as mapped FROM zkteco_user_mapping WHERE is_active = true");
$stats['employees'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Today's pulls
$stmt = $conn->query("
    SELECT 
        COUNT(*) as total,
        COUNT(CASE WHEN status = 'SUCCESS' THEN 1 END) as successful,
        COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed,
        SUM(inserted_records) as inserted,
        SUM(updated_records) as updated
    FROM zkteco_pull_log 
    WHERE pull_date = CURRENT_DATE
");
$stats['today'] = $stmt->fetch(PDO::FETCH_ASSOC);

// Last pull info
$stmt = $conn->query("
    SELECT 
        pl.*,
        d.device_name,
        d.ip_address
    FROM zkteco_pull_log pl
    JOIN zkteco_devices d ON pl.device_id = d.id
    ORDER BY pl.started_at DESC 
    LIMIT 1
");
$last_pull = $stmt->fetch(PDO::FETCH_ASSOC);

// Get all devices
$devices = $conn->query("
    SELECT 
        d.*,
        COUNT(DISTINCT zum.employee_id) as mapped_employees,
        (SELECT COUNT(*) FROM zkteco_pull_log WHERE device_id = d.id AND pull_date = CURRENT_DATE) as pulls_today
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

.page-subtitle {
    font-size: 16px;
    opacity: 0.9;
}

/* Stats Cards */
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
    transition: transform 0.2s ease;
}

.stat-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.12);
}

.stat-card.success { border-left-color: #28a745; }
.stat-card.warning { border-left-color: #ffc107; }
.stat-card.danger { border-left-color: #dc3545; }
.stat-card.info { border-left-color: #17a2b8; }

.stat-icon {
    font-size: 36px;
    margin-bottom: 10px;
}

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

/* Quick Actions */
.quick-actions {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.quick-actions h3 {
    margin-top: 0;
    color: #333;
    font-size: 20px;
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
    transition: all 0.2s ease;
}

.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-warning { background: #ffc107; color: #333; }
.btn-danger { background: #dc3545; color: white; }
.btn-secondary { background: #6c757d; color: white; }

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
}

/* Devices Section */
.devices-section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.devices-section h3 {
    margin-top: 0;
    color: #333;
    font-size: 20px;
}

.devices-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
    gap: 20px;
    margin-top: 20px;
}

.device-card {
    background: #f8f9fa;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    padding: 20px;
    position: relative;
}

.device-card.active {
    border-color: #28a745;
}

.device-card.inactive {
    opacity: 0.6;
    border-color: #dc3545;
}

.device-header {
    display: flex;
    justify-content: space-between;
    align-items: flex-start;
    margin-bottom: 15px;
}

.device-name {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin-bottom: 5px;
}

.device-code {
    font-size: 12px;
    color: #6c757d;
    font-family: 'Courier New', monospace;
}

.device-status {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-online {
    background: #d4edda;
    color: #155724;
}

.status-offline {
    background: #f8d7da;
    color: #721c24;
}

.status-unknown {
    background: #fff3cd;
    color: #856404;
}

.device-info {
    margin-bottom: 15px;
}

.device-info-item {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #e9ecef;
    font-size: 13px;
}

.device-info-label {
    color: #6c757d;
}

.device-info-value {
    font-weight: 600;
    color: #333;
}

.device-actions {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 10px;
    margin-top: 15px;
}

.btn-sm {
    padding: 8px 16px;
    font-size: 13px;
}

/* Last Pull Info */
.last-pull-info {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.last-pull-info h3 {
    margin-top: 0;
    color: #333;
}

.pull-details {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-top: 15px;
}

.pull-detail-item {
    padding: 15px;
    background: #f8f9fa;
    border-radius: 6px;
}

.pull-detail-label {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 5px;
}

.pull-detail-value {
    font-size: 18px;
    font-weight: 600;
    color: #333;
}

/* Modal */
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
    max-width: 500px;
    width: 90%;
    box-shadow: 0 8px 24px rgba(0,0,0,0.2);
}

.modal-header {
    font-size: 24px;
    font-weight: 700;
    margin-bottom: 20px;
}

.modal-body {
    margin-bottom: 20px;
}

.modal-footer {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
}

.form-group {
    margin-bottom: 15px;
}

.form-group label {
    display: block;
    margin-bottom: 5px;
    font-weight: 600;
    color: #333;
}

.form-control {
    width: 100%;
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
}

/* Loading Spinner */
.loading {
    display: inline-block;
    width: 20px;
    height: 20px;
    border: 3px solid rgba(255,255,255,.3);
    border-radius: 50%;
    border-top-color: white;
    animation: spin 1s ease-in-out infinite;
}

@keyframes spin {
    to { transform: rotate(360deg); }
}

/* Alerts */
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

.alert-info {
    background: #d1ecf1;
    color: #0c5460;
    border: 2px solid #bee5eb;
}
</style>

<div class="container">
    <!-- Page Header -->
    <div class="page-header">
        <h1 class="page-title">🔌 ZKTeco Attendance Management</h1>
        <p class="page-subtitle">Device monitoring, manual pulls, and attendance tracking</p>
    </div>

    <!-- Alert Area -->
    <div id="alertArea"></div>

    <!-- Statistics Cards -->
    <div class="stats-grid">
        <div class="stat-card info">
            <div class="stat-icon">🖥️</div>
            <div class="stat-value"><?= $stats['devices']['active'] ?>/<?= $stats['devices']['total'] ?></div>
            <div class="stat-label">Active Devices</div>
        </div>

        <div class="stat-card success">
            <div class="stat-icon">👥</div>
            <div class="stat-value"><?= $stats['employees']['mapped'] ?></div>
            <div class="stat-label">Mapped Employees</div>
        </div>

        <div class="stat-card">
            <div class="stat-icon">📊</div>
            <div class="stat-value"><?= $stats['today']['total'] ?></div>
            <div class="stat-label">Pulls Today</div>
            <div class="stat-detail">
                Success: <?= $stats['today']['successful'] ?> | Failed: <?= $stats['today']['failed'] ?>
            </div>
        </div>

        <div class="stat-card warning">
            <div class="stat-icon">📥</div>
            <div class="stat-value"><?= $stats['today']['inserted'] + $stats['today']['updated'] ?></div>
            <div class="stat-label">Records Today</div>
            <div class="stat-detail">
                New: <?= $stats['today']['inserted'] ?> | Updated: <?= $stats['today']['updated'] ?>
            </div>
        </div>
    </div>

    <!-- Quick Actions -->
    <div class="quick-actions">
        <h3>⚡ Quick Actions</h3>
        <div class="action-buttons">
            <a href="zkteco_pull_history.php" class="btn btn-info">
                📋 View Pull History
            </a>
            <a href="zkteco_employee_mapping.php" class="btn btn-primary">
                👥 Employee Mapping
            </a>
            <a href="zkteco_device_management.php" class="btn btn-secondary">
                ⚙️ Manage Devices
            </a>
            <a href="zkteco_attendance_report.php" class="btn btn-success">
                📊 Attendance Report
            </a>
        </div>
    </div>

    <!-- Last Pull Information -->
    <?php if ($last_pull): ?>
    <div class="last-pull-info">
        <h3>🕐 Last Pull Information</h3>
        <div class="pull-details">
            <div class="pull-detail-item">
                <div class="pull-detail-label">Device</div>
                <div class="pull-detail-value"><?= htmlspecialchars($last_pull['device_name']) ?></div>
            </div>
            <div class="pull-detail-item">
                <div class="pull-detail-label">Time</div>
                <div class="pull-detail-value"><?= date('h:i A', strtotime($last_pull['pull_time'])) ?></div>
            </div>
            <div class="pull-detail-item">
                <div class="pull-detail-label">Schedule</div>
                <div class="pull-detail-value"><?= ucfirst($last_pull['schedule_type']) ?></div>
            </div>
            <div class="pull-detail-item">
                <div class="pull-detail-label">Status</div>
                <div class="pull-detail-value" style="color: <?= $last_pull['status'] == 'SUCCESS' ? '#28a745' : '#dc3545' ?>">
                    <?= $last_pull['status'] ?>
                </div>
            </div>
            <div class="pull-detail-item">
                <div class="pull-detail-label">Records</div>
                <div class="pull-detail-value"><?= $last_pull['total_records'] ?></div>
            </div>
            <div class="pull-detail-item">
                <div class="pull-detail-label">Duration</div>
                <div class="pull-detail-value"><?= number_format($last_pull['duration_seconds'], 2) ?>s</div>
            </div>
        </div>
    </div>
    <?php endif; ?>

    <!-- Devices -->
    <div class="devices-section">
        <h3>🖥️ Attendance Devices</h3>
        
        <?php if (empty($devices)): ?>
        <div class="alert alert-info">
            No devices configured. <a href="zkteco_device_management.php">Add your first device</a>.
        </div>
        <?php else: ?>
        
        <div class="devices-grid">
            <?php foreach ($devices as $device): ?>
            <div class="device-card <?= $device['is_active'] ? 'active' : 'inactive' ?>">
                <div class="device-header">
                    <div>
                        <div class="device-name"><?= htmlspecialchars($device['device_name']) ?></div>
                        <div class="device-code"><?= htmlspecialchars($device['device_code']) ?></div>
                    </div>
                    <span class="device-status status-<?= strtolower($device['connection_status'] ?? 'unknown') ?>">
                        <?= $device['connection_status'] ?? 'UNKNOWN' ?>
                    </span>
                </div>

                <div class="device-info">
                    <div class="device-info-item">
                        <span class="device-info-label">IP Address</span>
                        <span class="device-info-value"><?= htmlspecialchars($device['ip_address']) ?>:<?= $device['port'] ?></span>
                    </div>
                    <div class="device-info-item">
                        <span class="device-info-label">Location</span>
                        <span class="device-info-value"><?= htmlspecialchars($device['location'] ?? 'N/A') ?></span>
                    </div>
                    <div class="device-info-item">
                        <span class="device-info-label">Mapped Employees</span>
                        <span class="device-info-value"><?= $device['mapped_employees'] ?></span>
                    </div>
                    <div class="device-info-item">
                        <span class="device-info-label">Pulls Today</span>
                        <span class="device-info-value"><?= $device['pulls_today'] ?></span>
                    </div>
                    <div class="device-info-item">
                        <span class="device-info-label">Last Pull</span>
                        <span class="device-info-value">
                            <?= $device['last_pull_at'] ? date('h:i A', strtotime($device['last_pull_at'])) : 'Never' ?>
                        </span>
                    </div>
                </div>

                <div class="device-actions">
                    <button onclick="manualPull(<?= $device['id'] ?>, '<?= htmlspecialchars($device['device_name']) ?>')" 
                            class="btn btn-primary btn-sm">
                        📥 Pull Now
                    </button>
                    <button onclick="testConnection(<?= $device['id'] ?>, '<?= htmlspecialchars($device['device_name']) ?>')" 
                            class="btn btn-info btn-sm">
                        🔌 Test Connection
                    </button>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
</div>

<!-- Manual Pull Modal -->
<div id="pullModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">Manual Pull</div>
        <div class="modal-body">
            <div class="form-group">
                <label>Device</label>
                <input type="text" id="pullDeviceName" class="form-control" readonly>
            </div>
            <div class="form-group">
                <label>Date</label>
                <input type="date" id="pullDate" class="form-control" value="<?= date('Y-m-d') ?>">
            </div>
            <div class="form-group">
                <label>Schedule Type</label>
                <select id="pullSchedule" class="form-control">
                    <option value="">Auto-detect</option>
                    <option value="morning">Morning (07:35 AM)</option>
                    <option value="midmorning">Mid-Morning (10:45 AM)</option>
                    <option value="afternoon">Afternoon (01:25 PM)</option>
                    <option value="evening">Evening (05:25 PM)</option>
                    <option value="night">Night (07:15 PM)</option>
                </select>
            </div>
            <div id="pullProgress" style="display: none; text-align: center; padding: 20px;">
                <div class="loading"></div>
                <p style="margin-top: 10px;">Pulling data from device...</p>
            </div>
            <div id="pullResult"></div>
        </div>
        <div class="modal-footer">
            <button onclick="closeModal('pullModal')" class="btn btn-secondary">Cancel</button>
            <button onclick="executePull()" class="btn btn-primary" id="pullBtn">Start Pull</button>
        </div>
    </div>
</div>

<script>
let currentDeviceId = null;

function manualPull(deviceId, deviceName) {
    currentDeviceId = deviceId;
    document.getElementById('pullDeviceName').value = deviceName;
    document.getElementById('pullResult').innerHTML = '';
    document.getElementById('pullProgress').style.display = 'none';
    document.getElementById('pullModal').classList.add('show');
}

function testConnection(deviceId, deviceName) {
    if (!confirm(`Test connection to ${deviceName}?`)) return;
    
    showAlert('Testing connection...', 'info');
    
    fetch('zkteco_ajax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=test_connection&device_id=${deviceId}`
    })
    .then(res => res.json())
    .then(data => {
        if (data.success) {
            showAlert(`✅ Connection successful! Device is online.`, 'success');
        } else {
            showAlert(`❌ Connection failed: ${data.error}`, 'danger');
        }
    })
    .catch(err => {
        showAlert(`Error: ${err.message}`, 'danger');
    });
}

function executePull() {
    const date = document.getElementById('pullDate').value;
    const schedule = document.getElementById('pullSchedule').value;
    
    if (!date) {
        alert('Please select a date');
        return;
    }
    
    document.getElementById('pullProgress').style.display = 'block';
    document.getElementById('pullBtn').disabled = true;
    document.getElementById('pullResult').innerHTML = '';
    
    fetch('zkteco_ajax.php', {
        method: 'POST',
        headers: {'Content-Type': 'application/x-www-form-urlencoded'},
        body: `action=manual_pull&device_id=${currentDeviceId}&date=${date}&schedule=${schedule}`
    })
    .then(res => res.json())
    .then(data => {
        document.getElementById('pullProgress').style.display = 'none';
        document.getElementById('pullBtn').disabled = false;
        
        if (data.success) {
            const stats = data.stats;
            document.getElementById('pullResult').innerHTML = `
                <div class="alert alert-success">
                    <strong>✅ Pull Completed Successfully!</strong><br><br>
                    <strong>Inserted:</strong> ${stats.inserted}<br>
                    <strong>Updated:</strong> ${stats.updated}<br>
                    <strong>Skipped:</strong> ${stats.skipped}<br>
                    <strong>Errors:</strong> ${stats.errors}<br>
                    <strong>Employees:</strong> ${stats.employees_count}
                </div>
            `;
            
            // Reload page after 3 seconds
            setTimeout(() => {
                location.reload();
            }, 3000);
        } else {
            document.getElementById('pullResult').innerHTML = `
                <div class="alert alert-danger">
                    <strong>❌ Pull Failed</strong><br>
                    ${data.error}
                </div>
            `;
        }
    })
    .catch(err => {
        document.getElementById('pullProgress').style.display = 'none';
        document.getElementById('pullBtn').disabled = false;
        document.getElementById('pullResult').innerHTML = `
            <div class="alert alert-danger">
                <strong>Error:</strong> ${err.message}
            </div>
        `;
    });
}

function closeModal(modalId) {
    document.getElementById(modalId).classList.remove('show');
}

function showAlert(message, type) {
    const alertArea = document.getElementById('alertArea');
    const alert = document.createElement('div');
    alert.className = `alert alert-${type}`;
    alert.innerHTML = message;
    alertArea.appendChild(alert);
    
    setTimeout(() => {
        alert.remove();
    }, 5000);
}

// Close modal when clicking outside
window.onclick = function(event) {
    if (event.target.classList.contains('modal')) {
        event.target.classList.remove('show');
    }
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

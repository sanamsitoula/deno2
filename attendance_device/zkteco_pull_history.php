<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Filters
$date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
$date_to = $_GET['date_to'] ?? date('Y-m-d');
$device_id = $_GET['device_id'] ?? '';
$schedule_type = $_GET['schedule_type'] ?? '';
$status = $_GET['status'] ?? '';

// Build query
$where = ["1=1"];
$params = [];

if ($date_from) {
    $where[] = "pl.pull_date >= :date_from";
    $params[':date_from'] = $date_from;
}

if ($date_to) {
    $where[] = "pl.pull_date <= :date_to";
    $params[':date_to'] = $date_to;
}

if ($device_id) {
    $where[] = "pl.device_id = :device_id";
    $params[':device_id'] = $device_id;
}

if ($schedule_type) {
    $where[] = "pl.schedule_type = :schedule_type";
    $params[':schedule_type'] = $schedule_type;
}

if ($status) {
    $where[] = "pl.status = :status";
    $params[':status'] = $status;
}

// Get pull history
$stmt = $conn->prepare("
    SELECT 
        pl.*,
        d.device_name,
        d.device_code,
        d.ip_address
    FROM zkteco_pull_log pl
    JOIN zkteco_devices d ON pl.device_id = d.id
    WHERE " . implode(' AND ', $where) . "
    ORDER BY pl.started_at DESC
    LIMIT 100
");
$stmt->execute($params);
$pulls = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get devices for filter
$devices = $conn->query("SELECT id, device_name FROM zkteco_devices ORDER BY device_name")->fetchAll(PDO::FETCH_ASSOC);

// Get summary statistics
$stmt = $conn->prepare("
    SELECT 
        COUNT(*) as total_pulls,
        COUNT(CASE WHEN status = 'SUCCESS' THEN 1 END) as successful,
        COUNT(CASE WHEN status = 'FAILED' THEN 1 END) as failed,
        SUM(inserted_records) as total_inserted,
        SUM(updated_records) as total_updated,
        SUM(employees_processed) as total_employees,
        AVG(duration_seconds) as avg_duration
    FROM zkteco_pull_log pl
    WHERE " . implode(' AND ', $where)
);
$stmt->execute($params);
$summary = $stmt->fetch(PDO::FETCH_ASSOC);

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

.filter-section {
    background: white;
    padding: 25px;
    border-radius: 12px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    margin-bottom: 30px;
}

.filter-row {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
    margin-bottom: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 13px;
    color: #333;
}

.form-control {
    padding: 10px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-weight: 600;
    font-size: 14px;
}

.btn-primary { background: #667eea; color: white; }
.btn-secondary { background: #6c757d; color: white; }

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(180px, 1fr));
    gap: 15px;
    margin-bottom: 30px;
}

.summary-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    text-align: center;
}

.summary-value {
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.summary-label {
    font-size: 12px;
    color: #6c757d;
    margin-top: 5px;
}

.table-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
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

.status-badge {
    padding: 4px 12px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.status-success {
    background: #d4edda;
    color: #155724;
}

.status-failed {
    background: #f8d7da;
    color: #721c24;
}

.status-partial {
    background: #fff3cd;
    color: #856404;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 12px;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📋 Pull History</h1>
        <p>View and analyze attendance pull operations</p>
    </div>

    <!-- Filters -->
    <div class="filter-section">
        <form method="GET">
            <div class="filter-row">
                <div class="form-group">
                    <label>From Date</label>
                    <input type="date" name="date_from" class="form-control" value="<?= $date_from ?>">
                </div>
                <div class="form-group">
                    <label>To Date</label>
                    <input type="date" name="date_to" class="form-control" value="<?= $date_to ?>">
                </div>
                <div class="form-group">
                    <label>Device</label>
                    <select name="device_id" class="form-control">
                        <option value="">All Devices</option>
                        <?php foreach ($devices as $dev): ?>
                        <option value="<?= $dev['id'] ?>" <?= $device_id == $dev['id'] ? 'selected' : '' ?>>
                            <?= htmlspecialchars($dev['device_name']) ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label>Schedule</label>
                    <select name="schedule_type" class="form-control">
                        <option value="">All Schedules</option>
                        <option value="morning" <?= $schedule_type == 'morning' ? 'selected' : '' ?>>Morning</option>
                        <option value="midmorning" <?= $schedule_type == 'midmorning' ? 'selected' : '' ?>>Mid-Morning</option>
                        <option value="afternoon" <?= $schedule_type == 'afternoon' ? 'selected' : '' ?>>Afternoon</option>
                        <option value="evening" <?= $schedule_type == 'evening' ? 'selected' : '' ?>>Evening</option>
                        <option value="night" <?= $schedule_type == 'night' ? 'selected' : '' ?>>Night</option>
                    </select>
                </div>
                <div class="form-group">
                    <label>Status</label>
                    <select name="status" class="form-control">
                        <option value="">All Status</option>
                        <option value="SUCCESS" <?= $status == 'SUCCESS' ? 'selected' : '' ?>>Success</option>
                        <option value="FAILED" <?= $status == 'FAILED' ? 'selected' : '' ?>>Failed</option>
                        <option value="PARTIAL" <?= $status == 'PARTIAL' ? 'selected' : '' ?>>Partial</option>
                    </select>
                </div>
            </div>
            <button type="submit" class="btn btn-primary">🔍 Filter</button>
            <a href="?" class="btn btn-secondary">🔄 Reset</a>
        </form>
    </div>

    <!-- Summary -->
    <div class="summary-cards">
        <div class="summary-card">
            <div class="summary-value"><?= $summary['total_pulls'] ?></div>
            <div class="summary-label">Total Pulls</div>
        </div>
        <div class="summary-card">
            <div class="summary-value" style="color: #28a745"><?= $summary['successful'] ?></div>
            <div class="summary-label">Successful</div>
        </div>
        <div class="summary-card">
            <div class="summary-value" style="color: #dc3545"><?= $summary['failed'] ?></div>
            <div class="summary-label">Failed</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= $summary['total_inserted'] ?></div>
            <div class="summary-label">Records Inserted</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= $summary['total_updated'] ?></div>
            <div class="summary-label">Records Updated</div>
        </div>
        <div class="summary-card">
            <div class="summary-value"><?= number_format($summary['avg_duration'], 2) ?>s</div>
            <div class="summary-label">Avg Duration</div>
        </div>
    </div>

    <!-- Pull History Table -->
    <div class="table-container">
        <h3 style="margin-top: 0;">Pull History (Last 100 Records)</h3>
        
        <?php if (empty($pulls)): ?>
        <p>No pull history found for the selected filters.</p>
        <?php else: ?>
        
        <table class="table">
            <thead>
                <tr>
                    <th>Date</th>
                    <th>Time</th>
                    <th>Device</th>
                    <th>Schedule</th>
                    <th>Status</th>
                    <th>Records</th>
                    <th>Inserted</th>
                    <th>Updated</th>
                    <th>Errors</th>
                    <th>Employees</th>
                    <th>Duration</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($pulls as $pull): ?>
                <tr>
                    <td><?= date('M d, Y', strtotime($pull['pull_date'])) ?></td>
                    <td><?= date('h:i A', strtotime($pull['pull_time'])) ?></td>
                    <td>
                        <strong><?= htmlspecialchars($pull['device_name']) ?></strong><br>
                        <small style="color: #6c757d"><?= htmlspecialchars($pull['ip_address']) ?></small>
                    </td>
                    <td><?= ucfirst($pull['schedule_type'] ?? 'Manual') ?></td>
                    <td>
                        <span class="status-badge status-<?= strtolower($pull['status']) ?>">
                            <?= $pull['status'] ?>
                        </span>
                    </td>
                    <td><?= $pull['total_records'] ?></td>
                    <td><?= $pull['inserted_records'] ?></td>
                    <td><?= $pull['updated_records'] ?></td>
                    <td><?= $pull['error_records'] ?></td>
                    <td><?= $pull['employees_processed'] ?></td>
                    <td><?= number_format($pull['duration_seconds'], 2) ?>s</td>
                    <td>
                        <a href="zkteco_pull_details.php?id=<?= $pull['id'] ?>" class="btn btn-primary btn-sm">
                            View Details
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;

// Nepali months
$nepali_months = [
    'Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
    'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'
];

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['action'] ?? '';
        
        if ($action === 'generate_summary') {
            $vehicle_id = $_POST['vehicle_id'];
            $fiscal_year = $_POST['fiscal_year'];
            $month_nep = $_POST['month_nep'];
            
            // Call the stored function to generate summary
            $stmt = $conn->prepare("SELECT calculate_monthly_vehicle_summary(:vehicle_id, :fiscal_year, :month_nep)");
            $stmt->execute([
                ':vehicle_id' => $vehicle_id,
                ':fiscal_year' => $fiscal_year,
                ':month_nep' => $month_nep
            ]);
            
            $success_message = "Monthly summary generated successfully for " . $month_nep . " " . $fiscal_year;
            
        } elseif ($action === 'generate_all') {
            $fiscal_year = $_POST['fiscal_year'];
            $month_nep = $_POST['month_nep'];
            
            // Get all active vehicles
            $vehicles = $conn->query("SELECT vehicle_id FROM vehicles WHERE status = TRUE AND deleted_at IS NULL")->fetchAll(PDO::FETCH_COLUMN);
            
            $count = 0;
            foreach ($vehicles as $vehicle_id) {
                $stmt = $conn->prepare("SELECT calculate_monthly_vehicle_summary(:vehicle_id, :fiscal_year, :month_nep)");
                $stmt->execute([
                    ':vehicle_id' => $vehicle_id,
                    ':fiscal_year' => $fiscal_year,
                    ':month_nep' => $month_nep
                ]);
                $count++;
            }
            
            $success_message = "Generated summaries for {$count} vehicles for {$month_nep} {$fiscal_year}";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch vehicles
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type, fuel_type 
    FROM vehicles 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

// Fetch summaries
$filter_fiscal = $_GET['fiscal_year'] ?? '2082/83';
$filter_month = $_GET['month_nep'] ?? '';

$where_clause = "WHERE 1=1";
$params = [];

if ($filter_fiscal) {
    $where_clause .= " AND fiscal_year = :fiscal_year";
    $params[':fiscal_year'] = $filter_fiscal;
}

if ($filter_month) {
    $where_clause .= " AND month_nep = :month_nep";
    $params[':month_nep'] = $filter_month;
}

$stmt = $conn->prepare("
    SELECT * FROM v_monthly_summary_full_details
    $where_clause
    ORDER BY fiscal_year DESC, month_nep, vehicle_no
");
$stmt->execute($params);
$summaries = $stmt->fetchAll(PDO::FETCH_ASSOC);
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
    margin-bottom: 30px;
    display: flex;
    justify-content: space-between;
    align-items: center;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.action-buttons {
    display: flex;
    gap: 10px;
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
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 20px;
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

.form-control {
    padding: 10px 14px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
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
.btn-success { background: #28a745; color: white; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
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

.stats-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.stat-card {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.stat-label {
    font-size: 14px;
    color: #6c757d;
    margin-bottom: 8px;
}

.stat-value {
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.stat-value.good { color: #28a745; }
.stat-value.bad { color: #dc3545; }

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
    white-space: nowrap;
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

.badge-good { background: #d4edda; color: #155724; }
.badge-warning { background: #fff3cd; color: #856404; }
.badge-danger { background: #f8d7da; color: #721c24; }

.filter-bar {
    background: white;
    padding: 20px;
    border-radius: 12px;
    margin-bottom: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.modal {
    display: none;
    position: fixed;
    top: 0;
    left: 0;
    right: 0;
    bottom: 0;
    background: rgba(0,0,0,0.5);
    z-index: 1000;
    align-items: center;
    justify-content: center;
}

.modal.active {
    display: flex;
}

.modal-content {
    background: white;
    border-radius: 12px;
    padding: 30px;
    max-width: 600px;
    width: 90%;
    max-height: 90vh;
    overflow-y: auto;
}

.modal-header {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 20px;
    padding-bottom: 15px;
    border-bottom: 2px solid #e9ecef;
}

.modal-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 20px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📊 Monthly Vehicle Summary</h1>
        <div class="action-buttons">
            <button class="btn btn-success" onclick="openModal('generate')">
                ⚡ Generate Summary
            </button>
            <a href="vehicle_reports_nepali.php" class="btn btn-info">
                📄 Nepali Reports
            </a>
        </div>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- Statistics -->
    <?php
    $total_km = array_sum(array_column($summaries, 'total_km'));
    $total_fuel = array_sum(array_column($summaries, 'total_fuel_used'));
    $avg_mileage = $total_fuel > 0 ? $total_km / $total_fuel : 0;
    $overuse_count = count(array_filter($summaries, fn($s) => $s['overuse_flag']));
    ?>
    
    <div class="stats-cards">
        <div class="stat-card">
            <div class="stat-label">Total Distance</div>
            <div class="stat-value"><?= number_format($total_km) ?> km</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Total Fuel Used</div>
            <div class="stat-value"><?= number_format($total_fuel, 2) ?> L</div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Average Mileage</div>
            <div class="stat-value <?= $avg_mileage >= 10 ? 'good' : 'bad' ?>">
                <?= number_format($avg_mileage, 2) ?> km/L
            </div>
        </div>
        <div class="stat-card">
            <div class="stat-label">Overuse Alerts</div>
            <div class="stat-value <?= $overuse_count > 0 ? 'bad' : 'good' ?>">
                <?= $overuse_count ?>
            </div>
        </div>
    </div>

    <!-- Filters -->
    <div class="filter-bar">
        <form method="GET" id="filterForm">
            <div class="form-grid">
                <div class="form-group">
                    <label for="fiscal_year">Fiscal Year</label>
                    <select name="fiscal_year" id="fiscal_year" class="form-control" onchange="this.form.submit()">
                        <option value="">All Years</option>
                        <option value="2082/83" <?= $filter_fiscal === '2082/83' ? 'selected' : '' ?>>2082/83</option>
                        <option value="2083/84" <?= $filter_fiscal === '2083/84' ? 'selected' : '' ?>>2083/84</option>
                        <option value="2084/85" <?= $filter_fiscal === '2084/85' ? 'selected' : '' ?>>2084/85</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="month_nep">Month</label>
                    <select name="month_nep" id="month_nep" class="form-control" onchange="this.form.submit()">
                        <option value="">All Months</option>
                        <?php foreach ($nepali_months as $month): ?>
                            <option value="<?= $month ?>" <?= $filter_month === $month ? 'selected' : '' ?>>
                                <?= $month ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                
                <div class="form-group" style="display: flex; align-items: flex-end;">
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary" style="width: 100%;">
                        🔄 Reset Filters
                    </a>
                </div>
            </div>
        </form>
    </div>

    <!-- Summary Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>Vehicle</th>
                    <th>Period</th>
                    <th>Opening<br>Meter</th>
                    <th>Closing<br>Meter</th>
                    <th>Total<br>KM</th>
                    <th>Fuel<br>Allocated</th>
                    <th>Fuel<br>Used</th>
                    <th>Balance</th>
                    <th>Avg<br>Mileage</th>
                    <th>Standard<br>Mileage</th>
                    <th>Performance</th>
                    <th>Status</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($summaries)): ?>
                    <tr>
                        <td colspan="12" style="text-align: center; padding: 40px;">
                            No summaries found. Generate summaries using the button above.
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($summaries as $summary): ?>
                        <tr>
                            <td>
                                <strong><?= htmlspecialchars($summary['vehicle_no']) ?></strong><br>
                                <small style="color: #6c757d;"><?= ucfirst($summary['vehicle_type']) ?></small>
                            </td>
                            <td>
                                <?= htmlspecialchars($summary['fiscal_year']) ?><br>
                                <small style="color: #6c757d;"><?= $summary['month_nep'] ?></small>
                            </td>
                            <td><?= number_format($summary['opening_meter']) ?></td>
                            <td><?= number_format($summary['closing_meter']) ?></td>
                            <td><strong><?= number_format($summary['total_km']) ?> km</strong></td>
                            <td><?= number_format($summary['total_fuel_allocated'], 2) ?> L</td>
                            <td><?= number_format($summary['total_fuel_used'], 2) ?> L</td>
                            <td>
                                <strong style="color: <?= $summary['balance_fuel'] < 0 ? '#dc3545' : '#28a745' ?>;">
                                    <?= number_format($summary['balance_fuel'], 2) ?> L
                                </strong>
                            </td>
                            <td>
                                <strong style="font-size: 16px; color: #007bff;">
                                    <?= number_format($summary['mileage_avg'], 2) ?>
                                </strong>
                            </td>
                            <td><?= number_format($summary['fuel_per_liter_standard'], 2) ?></td>
                            <td>
                                <?php
                                $performance = $summary['performance_status'];
                                $badge_class = 'badge-good';
                                if ($performance === 'Below Standard') $badge_class = 'badge-warning';
                                ?>
                                <span class="badge <?= $badge_class ?>">
                                    <?= $performance ?>
                                </span>
                            </td>
                            <td>
                                <?php if ($summary['overuse_flag']): ?>
                                    <span class="badge badge-danger">⚠️ Overuse</span>
                                <?php else: ?>
                                    <span class="badge badge-good">✓ Normal</span>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Generate Summary Modal -->
<div id="generateModal" class="modal">
    <div class="modal-content">
        <div class="modal-header">⚡ Generate Monthly Summary</div>
        
        <form method="POST" id="generateForm">
            <div class="form-group" style="margin-bottom: 20px;">
                <label>
                    <input type="radio" name="action" value="generate_summary" checked style="margin-right: 8px;">
                    Generate for Single Vehicle
                </label>
            </div>
            
            <div id="singleVehicleFields" style="margin-bottom: 20px;">
                <div class="form-group" style="margin-bottom: 15px;">
                    <label for="vehicle_id">Vehicle</label>
                    <select name="vehicle_id" id="vehicle_id" class="form-control">
                        <option value="">Select Vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= $vehicle['vehicle_id'] ?>">
                                <?= htmlspecialchars($vehicle['vehicle_no']) ?> (<?= ucfirst($vehicle['vehicle_type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="form-group" style="margin-bottom: 20px;">
                <label>
                    <input type="radio" name="action" value="generate_all" style="margin-right: 8px;">
                    Generate for All Vehicles
                </label>
            </div>
            
            <div class="form-grid" style="margin-top: 20px;">
                <div class="form-group">
                    <label for="fiscal_year_gen">Fiscal Year</label>
                    <select name="fiscal_year" id="fiscal_year_gen" class="form-control" required>
                        <option value="2082/83">2082/83</option>
                        <option value="2083/84">2083/84</option>
                        <option value="2084/85">2084/85</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label for="month_nep_gen">Nepali Month</label>
                    <select name="month_nep" id="month_nep_gen" class="form-control" required>
                        <option value="">Select Month</option>
                        <?php foreach ($nepali_months as $month): ?>
                            <option value="<?= $month ?>"><?= $month ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            
            <div class="modal-actions">
                <button type="button" class="btn btn-secondary" onclick="closeModal()">Cancel</button>
                <button type="submit" class="btn btn-success">⚡ Generate</button>
            </div>
        </form>
    </div>
</div>

<script>
function openModal(type) {
    document.getElementById('generateModal').classList.add('active');
}

function closeModal() {
    document.getElementById('generateModal').classList.remove('active');
}

// Toggle vehicle field based on action
document.querySelectorAll('input[name="action"]').forEach(radio => {
    radio.addEventListener('change', function() {
        const singleFields = document.getElementById('singleVehicleFields');
        const vehicleSelect = document.getElementById('vehicle_id');
        
        if (this.value === 'generate_summary') {
            singleFields.style.display = 'block';
            vehicleSelect.required = true;
        } else {
            singleFields.style.display = 'none';
            vehicleSelect.required = false;
        }
    });
});

// Close modal on outside click
document.getElementById('generateModal').addEventListener('click', function(e) {
    if (e.target === this) {
        closeModal();
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

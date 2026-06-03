<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Nepali months
$nepali_months = [
    'Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
    'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'
];

// Get filter parameters
$fiscal_year = $_GET['fiscal_year'] ?? '2082/83';
$month_nep = $_GET['month_nep'] ?? '';
$vehicle_id = $_GET['vehicle_id'] ?? '';
$report_type = $_GET['report_type'] ?? 'coupon';

// Fetch vehicles
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no 
    FROM vehicles 
    WHERE deleted_at IS NULL 
    ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

// Build query based on report type
if ($report_type === 'coupon') {
    // COUPON ISSUANCE REPORT
    $where = ["fc.deleted_at IS NULL"];
    $params = [];
    
    if ($fiscal_year) {
        $where[] = "fc.fiscal_year = :fiscal_year";
        $params[':fiscal_year'] = $fiscal_year;
    }
    if ($month_nep) {
        $where[] = "fc.month_nep = :month_nep";
        $params[':month_nep'] = $month_nep;
    }
    if ($vehicle_id) {
        $where[] = "fc.vehicle_id = :vehicle_id";
        $params[':vehicle_id'] = $vehicle_id;
    }
    
    $where_clause = implode(" AND ", $where);
    
    $stmt = $conn->prepare("
        SELECT 
            fc.coupon_id,
            fc.fiscal_year,
            fc.month_nep,
            fc.coupon_no,
            v.vehicle_no,
            v.vehicle_type,
            fc.fuel_type,
            fc.allocated_qty,
            fc.carry_forward_qty,
            fc.total_available_qty,
            fc.issued_date_nep,
            fc.issued_date_eng,
            fc.pump_name,
            d.driver_name,
            COALESCE(SUM(fcd.disburse_qty), 0) as total_distributed,
            (fc.total_available_qty - COALESCE(SUM(fcd.disburse_qty), 0)) as remaining_qty,
            fc.verified_with_pump,
            fc.paid_status,
            fc.remarks
        FROM fuel_coupons fc
        JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
        LEFT JOIN (
            SELECT vehicle_id, driver_name
            FROM vehicle_assignments
            WHERE is_active = TRUE AND deleted_at IS NULL
            ORDER BY assignment_date_eng DESC
            LIMIT 1
        ) d ON v.vehicle_id = d.vehicle_id
        LEFT JOIN fuel_coupon_distributions fcd ON fc.coupon_id = fcd.coupon_id 
            AND fcd.deleted_at IS NULL
        WHERE $where_clause
        GROUP BY fc.coupon_id, v.vehicle_no, v.vehicle_type, d.driver_name
        ORDER BY fc.fiscal_year DESC, fc.month_nep, v.vehicle_no, fc.fuel_type
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
} else {
    // DISTRIBUTION REPORT
    $where = ["fcd.deleted_at IS NULL"];
    $params = [];
    
    if ($fiscal_year) {
        $where[] = "fc.fiscal_year = :fiscal_year";
        $params[':fiscal_year'] = $fiscal_year;
    }
    if ($month_nep) {
        $where[] = "fc.month_nep = :month_nep";
        $params[':month_nep'] = $month_nep;
    }
    if ($vehicle_id) {
        $where[] = "fc.vehicle_id = :vehicle_id";
        $params[':vehicle_id'] = $vehicle_id;
    }
    
    $where_clause = implode(" AND ", $where);
    
    $stmt = $conn->prepare("
        SELECT 
            fcd.distribution_id,
            fc.fiscal_year,
            fc.month_nep,
            fc.coupon_id,
            fc.coupon_no,
            v.vehicle_no,
            v.vehicle_type,
            fc.fuel_type,
            d.driver_name,
            fcd.disburse_date_nep,
            fcd.disburse_date_eng,
            fcd.disburse_qty,
            fcd.rate_per_liter,
            fcd.total_amount,
            fc.pump_name,
            fcd.verified_flag,
            fcd.remarks,
            u.username as created_by_user
        FROM fuel_coupon_distributions fcd
        JOIN fuel_coupons fc ON fcd.coupon_id = fc.coupon_id
        JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
        LEFT JOIN (
            SELECT vehicle_id, driver_name
            FROM vehicle_assignments
            WHERE is_active = TRUE AND deleted_at IS NULL
            ORDER BY assignment_date_eng DESC
            LIMIT 1
        ) d ON v.vehicle_id = d.vehicle_id
        LEFT JOIN users u ON fcd.created_by = u.id
        WHERE $where_clause
        ORDER BY fcd.disburse_date_eng DESC, v.vehicle_no, fc.fuel_type
    ");
    $stmt->execute($params);
    $data = $stmt->fetchAll(PDO::FETCH_ASSOC);
}
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
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 30px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
}

.filter-container {
    background: white;
    border-radius: 12px;
    padding: 25px;
    margin-bottom: 25px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
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

.form-input, .form-select {
    padding: 10px 15px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
}

.form-input:focus, .form-select:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.filter-actions {
    display: flex;
    gap: 10px;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 8px;
    font-size: 14px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-success:hover {
    background: #218838;
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
    text-decoration: none;
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

.report-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
}

.report-table th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
    font-size: 13px;
}

.report-table td {
    padding: 12px;
    border-bottom: 1px solid #f1f1f1;
    font-size: 13px;
}

.report-table tr:hover {
    background: #f8f9fa;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    text-transform: uppercase;
}

.badge-verified {
    background: #d4edda;
    color: #155724;
}

.badge-unverified {
    background: #fff3cd;
    color: #856404;
}

.badge-paid {
    background: #d4edda;
    color: #155724;
}

.badge-unpaid {
    background: #f8d7da;
    color: #721c24;
}

.summary-box {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
    margin-bottom: 25px;
}

.summary-card {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    padding: 20px;
    border-radius: 12px;
    color: white;
}

.summary-card.alt1 {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
}

.summary-card.alt2 {
    background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
}

.summary-card.alt3 {
    background: linear-gradient(135deg, #43e97b 0%, #38f9d7 100%);
}

.summary-label {
    font-size: 13px;
    opacity: 0.9;
    margin-bottom: 8px;
}

.summary-value {
    font-size: 28px;
    font-weight: 700;
}

@media print {
    .filter-container, .page-header .btn, .tabs {
        display: none;
    }
    
    .container {
        max-width: 100%;
        padding: 0;
    }
    
    .report-table {
        font-size: 11px;
    }
    
    .report-table th,
    .report-table td {
        padding: 8px 6px;
    }
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📊 Fuel Coupon Reports</h1>
        <button class="btn btn-success" onclick="window.print()">🖨️ Print Report</button>
    </div>

    <div class="filter-container">
        <form method="GET">
            <div class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Report Type</label>
                    <select name="report_type" class="form-select" onchange="this.form.submit()">
                        <option value="coupon" <?= $report_type === 'coupon' ? 'selected' : '' ?>>
                            Coupon Issuance
                        </option>
                        <option value="distribution" <?= $report_type === 'distribution' ? 'selected' : '' ?>>
                            Distribution Details
                        </option>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <input type="text" name="fiscal_year" class="form-input" 
                           value="<?= htmlspecialchars($fiscal_year) ?>" placeholder="2082/83">
                </div>

                <div class="form-group">
                    <label class="form-label">Month (Nepali)</label>
                    <select name="month_nep" class="form-select">
                        <option value="">All Months</option>
                        <?php foreach ($nepali_months as $month): ?>
                            <option value="<?= $month ?>" <?= $month_nep === $month ? 'selected' : '' ?>>
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
                                    <?= $vehicle_id == $vehicle['vehicle_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicle['vehicle_no']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="filter-actions">
                <button type="submit" class="btn btn-primary">🔍 Generate Report</button>
                <a href="?" class="btn" style="background: #6c757d; color: white;">🔄 Reset</a>
            </div>
        </form>
    </div>

    <div class="report-container">
        <?php if ($report_type === 'coupon'): ?>
            <!-- COUPON ISSUANCE REPORT -->
            <h2 style="margin-bottom: 20px; color: #333;">🎫 Fuel Coupon Issuance Report</h2>
            
            <?php if ($fiscal_year || $month_nep): ?>
            <p style="color: #6c757d; margin-bottom: 20px;">
                Period: <strong><?= $month_nep ?: 'All Months' ?> <?= $fiscal_year ?></strong>
            </p>
            <?php endif; ?>

            <?php
            // Calculate summary
            $total_coupons = count($data);
            $total_petrol = 0;
            $total_diesel = 0;
            $total_mobil = 0;
            
            foreach ($data as $row) {
                if ($row['fuel_type'] === 'petrol') $total_petrol += $row['total_available_qty'];
                if ($row['fuel_type'] === 'diesel') $total_diesel += $row['total_available_qty'];
                if ($row['fuel_type'] === 'mobil') $total_mobil += $row['total_available_qty'];
            }
            ?>

            <div class="summary-box">
                <div class="summary-card">
                    <div class="summary-label">Total Coupons</div>
                    <div class="summary-value"><?= $total_coupons ?></div>
                </div>
                <div class="summary-card alt1">
                    <div class="summary-label">Petrol Allocated</div>
                    <div class="summary-value"><?= number_format($total_petrol, 2) ?> L</div>
                </div>
                <div class="summary-card alt2">
                    <div class="summary-label">Diesel Allocated</div>
                    <div class="summary-value"><?= number_format($total_diesel, 2) ?> L</div>
                </div>
                <div class="summary-card alt3">
                    <div class="summary-label">Mobil Allocated</div>
                    <div class="summary-value"><?= number_format($total_mobil, 2) ?> L</div>
                </div>
            </div>

            <table class="report-table">
                <thead>
                    <tr>
                        <th>S.N.</th>
                        <th>Coupon No.</th>
                        <th>Period</th>
                        <th>Vehicle No.</th>
                        <th>Driver</th>
                        <th>Fuel Type</th>
                        <th>Allocated</th>
                        <th>C/F</th>
                        <th>Total Available</th>
                        <th>Distributed</th>
                        <th>Remaining</th>
                        <th>Issued Date</th>
                        <th>Pump</th>
                        <th>Status</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr>
                            <td colspan="14" style="text-align: center; padding: 40px;">
                                No coupons found for selected criteria
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data as $index => $row): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td><?= htmlspecialchars($row['coupon_no']) ?: '-' ?></td>
                                <td>
                                    <?= $row['month_nep'] ?><br>
                                    <small style="color: #6c757d;"><?= $row['fiscal_year'] ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($row['vehicle_no']) ?></strong><br>
                                    <small style="color: #6c757d;"><?= ucfirst($row['vehicle_type']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['driver_name']) ?: '-' ?></td>
                                <td><strong><?= strtoupper($row['fuel_type']) ?></strong></td>
                                <td><?= number_format($row['allocated_qty'], 2) ?></td>
                                <td><?= number_format($row['carry_forward_qty'], 2) ?></td>
                                <td><strong><?= number_format($row['total_available_qty'], 2) ?></strong></td>
                                <td><?= number_format($row['total_distributed'], 2) ?></td>
                                <td style="color: <?= $row['remaining_qty'] < 0 ? '#dc3545' : '#28a745' ?>;">
                                    <strong><?= number_format($row['remaining_qty'], 2) ?></strong>
                                </td>
                                <td>
                                    <?= $row['issued_date_nep'] ?><br>
                                    <small style="color: #6c757d;"><?= date('d M Y', strtotime($row['issued_date_eng'])) ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['pump_name']) ?: '-' ?></td>
                                <td>
                                    <span class="badge badge-<?= $row['verified_with_pump'] ? 'verified' : 'unverified' ?>">
                                        <?= $row['verified_with_pump'] ? 'Verified' : 'Pending' ?>
                                    </span><br>
                                    <span class="badge badge-<?= $row['paid_status'] ? 'paid' : 'unpaid' ?>">
                                        <?= $row['paid_status'] ? 'Paid' : 'Unpaid' ?>
                                    </span>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>

        <?php else: ?>
            <!-- DISTRIBUTION REPORT -->
            <h2 style="margin-bottom: 20px; color: #333;">📦 Fuel Distribution Report</h2>
            
            <?php if ($fiscal_year || $month_nep): ?>
            <p style="color: #6c757d; margin-bottom: 20px;">
                Period: <strong><?= $month_nep ?: 'All Months' ?> <?= $fiscal_year ?></strong>
            </p>
            <?php endif; ?>

            <?php
            // Calculate summary
            $total_distributions = count($data);
            $total_qty = 0;
            $total_amount = 0;
            $fuel_totals = ['petrol' => 0, 'diesel' => 0, 'mobil' => 0];
            
            foreach ($data as $row) {
                $total_qty += $row['disburse_qty'];
                $total_amount += $row['total_amount'];
                $fuel_totals[$row['fuel_type']] += $row['disburse_qty'];
            }
            ?>

            <div class="summary-box">
                <div class="summary-card">
                    <div class="summary-label">Total Distributions</div>
                    <div class="summary-value"><?= $total_distributions ?></div>
                </div>
                <div class="summary-card alt1">
                    <div class="summary-label">Total Quantity</div>
                    <div class="summary-value"><?= number_format($total_qty, 2) ?> L</div>
                </div>
                <div class="summary-card alt2">
                    <div class="summary-label">Total Amount</div>
                    <div class="summary-value">रू <?= number_format($total_amount, 2) ?></div>
                </div>
            </div>

            <table class="report-table">
                <thead>
                    <tr>
                        <th>S.N.</th>
                        <th>Date</th>
                        <th>Coupon No.</th>
                        <th>Vehicle No.</th>
                        <th>Driver</th>
                        <th>Fuel Type</th>
                        <th>Quantity (L)</th>
                        <th>Rate/L</th>
                        <th>Total Amount</th>
                        <th>Pump</th>
                        <th>Verified</th>
                        <th>Created By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($data)): ?>
                        <tr>
                            <td colspan="12" style="text-align: center; padding: 40px;">
                                No distributions found for selected criteria
                            </td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($data as $index => $row): ?>
                            <tr>
                                <td><?= $index + 1 ?></td>
                                <td>
                                    <?= $row['disburse_date_nep'] ?><br>
                                    <small style="color: #6c757d;"><?= date('d M Y', strtotime($row['disburse_date_eng'])) ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['coupon_no']) ?: '-' ?></td>
                                <td>
                                    <strong><?= htmlspecialchars($row['vehicle_no']) ?></strong><br>
                                    <small style="color: #6c757d;"><?= ucfirst($row['vehicle_type']) ?></small>
                                </td>
                                <td><?= htmlspecialchars($row['driver_name']) ?: '-' ?></td>
                                <td><strong><?= strtoupper($row['fuel_type']) ?></strong></td>
                                <td><?= number_format($row['disburse_qty'], 2) ?></td>
                                <td>रू <?= number_format($row['rate_per_liter'], 2) ?></td>
                                <td><strong>रू <?= number_format($row['total_amount'], 2) ?></strong></td>
                                <td><?= htmlspecialchars($row['pump_name']) ?: '-' ?></td>
                                <td>
                                    <span class="badge badge-<?= $row['verified_flag'] ? 'verified' : 'unverified' ?>">
                                        <?= $row['verified_flag'] ? 'Yes' : 'No' ?>
                                    </span>
                                </td>
                                <td><?= htmlspecialchars($row['created_by_user']) ?: '-' ?></td>
                            </tr>
                        <?php endforeach; ?>
                        
                        <!-- Summary Row -->
                        <tr style="background: #f8f9fa; font-weight: 700;">
                            <td colspan="6" style="text-align: right;">TOTAL:</td>
                            <td><?= number_format($total_qty, 2) ?> L</td>
                            <td>-</td>
                            <td>रू <?= number_format($total_amount, 2) ?></td>
                            <td colspan="3"></td>
                        </tr>
                    <?php endif; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

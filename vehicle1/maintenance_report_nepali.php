<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

// Get filter parameters
$fiscal_year = $_GET['fiscal_year'] ?? '2082/83';
$vehicle_id = $_GET['vehicle_id'] ?? '';
$from_date = $_GET['from_date'] ?? '';
$to_date = $_GET['to_date'] ?? '';

// Fetch vehicles
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no 
    FROM vehicles 
    WHERE deleted_at IS NULL 
    ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

// Build query
$where = ["vmr.deleted_at IS NULL"];
$params = [];

if ($fiscal_year) {
    $where[] = "vmr.fiscal_year = :fiscal_year";
    $params[':fiscal_year'] = $fiscal_year;
}
if ($vehicle_id) {
    $where[] = "vmr.vehicle_id = :vehicle_id";
    $params[':vehicle_id'] = $vehicle_id;
}
if ($from_date) {
    $where[] = "vmr.maintenance_date_eng >= :from_date";
    $params[':from_date'] = $from_date;
}
if ($to_date) {
    $where[] = "vmr.maintenance_date_eng <= :to_date";
    $params[':to_date'] = $to_date;
}

$where_clause = implode(" AND ", $where);

$stmt = $conn->prepare("
    SELECT 
        ROW_NUMBER() OVER (ORDER BY vmr.maintenance_date_eng, v.vehicle_no) as sn,
        vmr.maintenance_id,
        vmr.maintenance_date_nep,
        vmr.maintenance_date_eng,
        v.vehicle_no,
        v.vehicle_type,
        mt.type_name,
        vmr.meter_reading,
        vmr.next_due_km,
        vmr.work_description,
        vmr.parts_replaced,
        vmr.service_provider,
        vmr.mechanic_name,
        vmr.labor_cost,
        vmr.parts_cost,
        vmr.total_cost,
        vmr.downtime_days,
        vmr.bill_no,
        vmr.payment_status,
        vmr.is_warranty,
        vmr.remarks
    FROM vehicle_maintenance_records vmr
    JOIN vehicles v ON vmr.vehicle_id = v.vehicle_id
    JOIN maintenance_types mt ON vmr.maintenance_type_id = mt.maintenance_type_id
    WHERE $where_clause
    ORDER BY vmr.maintenance_date_eng DESC, v.vehicle_no
");
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_cost = 0;
$total_labor = 0;
$total_parts = 0;
foreach ($data as $row) {
    $total_cost += $row['total_cost'];
    $total_labor += $row['labor_cost'];
    $total_parts += $row['parts_cost'];
}
?>

<style>
@page {
    size: A4 landscape;
    margin: 15mm;
}

body {
    font-family: 'Noto Sans Devanagari', 'Preeti', Arial, sans-serif;
    margin: 0;
    padding: 20px;
    background: white;
}

.report-container {
    max-width: 1200px;
    margin: 0 auto;
    background: white;
}

.report-header {
    text-align: center;
    margin-bottom: 20px;
    border: 2px solid #000;
    padding: 15px;
}

.org-name {
    font-size: 20px;
    font-weight: 700;
    margin-bottom: 5px;
}

.org-address {
    font-size: 14px;
    margin-bottom: 10px;
}

.report-title {
    font-size: 16px;
    font-weight: 700;
    margin: 15px 0;
}

.report-period {
    font-size: 13px;
    margin: 10px 0;
}

.filter-container {
    background: #f5f7fa;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
    display: flex;
    gap: 15px;
    align-items: end;
    flex-wrap: wrap;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-label {
    font-weight: 600;
    margin-bottom: 5px;
    font-size: 13px;
}

.form-input, .form-select {
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 13px;
}

.btn {
    padding: 8px 16px;
    border: none;
    border-radius: 4px;
    font-size: 13px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-success {
    background: #28a745;
    color: white;
}

.report-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 11px;
}

.report-table th,
.report-table td {
    border: 1px solid #000;
    padding: 6px 8px;
    text-align: center;
}

.report-table th {
    background: #f0f0f0;
    font-weight: 700;
}

.report-table td.text-left {
    text-align: left;
}

.report-table td.text-right {
    text-align: right;
}

.report-table .total-row {
    background: #f8f8f8;
    font-weight: 700;
}

.summary-box {
    display: grid;
    grid-template-columns: repeat(3, 1fr);
    gap: 15px;
    margin: 20px 0;
    padding: 15px;
    background: #f8f9fa;
    border-radius: 8px;
}

.summary-item {
    text-align: center;
}

.summary-label {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 5px;
}

.summary-value {
    font-size: 20px;
    font-weight: 700;
    color: #333;
}

.report-footer {
    margin-top: 40px;
    display: flex;
    justify-content: space-between;
    padding-top: 20px;
    border-top: 1px solid #dee2e6;
}

.signature-section {
    text-align: center;
}

.signature-line {
    width: 180px;
    border-top: 1px solid #000;
    margin: 40px auto 5px;
}

.signature-label {
    font-size: 12px;
}

@media print {
    .filter-container {
        display: none !important;
    }
    
    body {
        padding: 0;
    }
    
    .report-table {
        font-size: 9px;
    }
    
    .report-table th,
    .report-table td {
        padding: 4px 5px;
    }
}
</style>

<div class="filter-container">
    <form method="GET" style="display: flex; gap: 15px; align-items: end; flex-wrap: wrap; width: 100%;">
        <div class="form-group">
            <label class="form-label">Fiscal Year</label>
            <input type="text" name="fiscal_year" class="form-input" 
                   value="<?= htmlspecialchars($fiscal_year) ?>" placeholder="2082/83">
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

        <div class="form-group">
            <label class="form-label">From Date</label>
            <input type="date" name="from_date" class="form-input" 
                   value="<?= htmlspecialchars($from_date) ?>">
        </div>

        <div class="form-group">
            <label class="form-label">To Date</label>
            <input type="date" name="to_date" class="form-input" 
                   value="<?= htmlspecialchars($to_date) ?>">
        </div>

        <button type="submit" class="btn btn-primary">🔍 Generate</button>
        <button type="button" class="btn btn-success" onclick="window.print()">🖨️ Print</button>
    </form>
</div>

<div class="report-container">
    <div class="report-header">
        <div class="org-name">जनक शिक्षा सामग्री केन्द्र लि.</div>
        <div class="org-address">(नेपाल सरकारको पूर्ण स्वामित्व भएको)</div>
        <div class="org-address">केन्द्रीय कार्यालय, सानोठिमी, भक्तपुर</div>
        <div class="report-title">सवारी मर्मत सम्भार विवरण</div>
        <div class="report-period">
            आर्थिक वर्षः <?= htmlspecialchars($fiscal_year ?: '2082/83') ?>
        </div>
    </div>

    <div class="summary-box">
        <div class="summary-item">
            <div class="summary-label">कुल मर्मत संख्या</div>
            <div class="summary-value"><?= count($data) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">कुल लागत</div>
            <div class="summary-value">रू <?= number_format($total_cost, 2) ?></div>
        </div>
        <div class="summary-item">
            <div class="summary-label">औसत लागत</div>
            <div class="summary-value">
                रू <?= count($data) > 0 ? number_format($total_cost / count($data), 2) : '0.00' ?>
            </div>
        </div>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th rowspan="2">क्र.सं.</th>
                <th rowspan="2">मिति<br>(नेपाली)</th>
                <th rowspan="2">सवारी नं.</th>
                <th colspan="2">किलोमिटर</th>
                <th rowspan="2">मर्मत प्रकार</th>
                <th rowspan="2">कार्य विवरण</th>
                <th rowspan="2">सेवा प्रदायक</th>
                <th colspan="3">लागत (रु.)</th>
                <th rowspan="2">बिल नं.</th>
                <th rowspan="2">भुक्तानी<br>स्थिति</th>
            </tr>
            <tr>
                <th>मर्मत गर्दा</th>
                <th>अर्को मर्मत</th>
                <th>श्रम</th>
                <th>पार्टस</th>
                <th>जम्मा</th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($data)): ?>
                <tr>
                    <td colspan="13" style="text-align: center; padding: 30px;">
                        कुनै डाटा उपलब्ध छैन
                    </td>
                </tr>
            <?php else: ?>
                <?php foreach ($data as $row): ?>
                    <tr>
                        <td><?= $row['sn'] ?></td>
                        <td><?= htmlspecialchars($row['maintenance_date_nep']) ?></td>
                        <td class="text-left">
                            <strong><?= htmlspecialchars($row['vehicle_no']) ?></strong><br>
                            <small style="color: #666;"><?= ucfirst($row['vehicle_type']) ?></small>
                        </td>
                        <td><?= number_format($row['meter_reading']) ?></td>
                        <td><?= $row['next_due_km'] ? number_format($row['next_due_km']) : '-' ?></td>
                        <td class="text-left"><?= htmlspecialchars($row['type_name']) ?></td>
                        <td class="text-left">
                            <?php 
                            $desc = $row['work_description'] ?: '';
                            echo htmlspecialchars(mb_substr($desc, 0, 50));
                            if (mb_strlen($desc) > 50) echo '...';
                            ?>
                        </td>
                        <td class="text-left">
                            <?= htmlspecialchars($row['service_provider']) ?: '-' ?>
                            <?php if ($row['mechanic_name']): ?>
                                <br><small><?= htmlspecialchars($row['mechanic_name']) ?></small>
                            <?php endif; ?>
                        </td>
                        <td class="text-right"><?= number_format($row['labor_cost'], 2) ?></td>
                        <td class="text-right"><?= number_format($row['parts_cost'], 2) ?></td>
                        <td class="text-right"><strong><?= number_format($row['total_cost'], 2) ?></strong></td>
                        <td><?= htmlspecialchars($row['bill_no']) ?: '-' ?></td>
                        <td>
                            <?php if ($row['payment_status']): ?>
                                <span style="color: green;">✓</span>
                            <?php else: ?>
                                <span style="color: orange;">⏳</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Total Row -->
                <tr class="total-row">
                    <td colspan="8" class="text-right">जम्मा:</td>
                    <td class="text-right"><?= number_format($total_labor, 2) ?></td>
                    <td class="text-right"><?= number_format($total_parts, 2) ?></td>
                    <td class="text-right"><strong><?= number_format($total_cost, 2) ?></strong></td>
                    <td colspan="2"></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="report-footer">
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">तयार गर्ने</div>
            <div class="signature-label">कर्मचारी प्रशासन शाखा</div>
        </div>
        
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">प्रमुख</div>
            <div class="signature-label">कर्मचारी प्रशासन शाखा</div>
        </div>
        
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">मानव संसाधन तथा वित्त निर्देशनालय</div>
        </div>
        
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">प्रमुख सञ्चालक</div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

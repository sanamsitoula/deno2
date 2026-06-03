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

// Fetch data
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

$where_clause = implode(" AND ", $where);

$stmt = $conn->prepare("
    SELECT 
        ROW_NUMBER() OVER (ORDER BY v.vehicle_no, fc.fuel_type) as sn,
        v.vehicle_no,
        v.vehicle_type,
        fc.fuel_type,
        fc.coupon_no,
        -- Petrol details
        CASE WHEN fc.fuel_type = 'petrol' THEN fcd.disburse_qty ELSE NULL END as petrol_qty,
        CASE WHEN fc.fuel_type = 'petrol' THEN fcd.rate_per_liter ELSE NULL END as petrol_rate,
        CASE WHEN fc.fuel_type = 'petrol' THEN fcd.total_amount ELSE NULL END as petrol_amount,
        -- Diesel details
        CASE WHEN fc.fuel_type = 'diesel' THEN fcd.disburse_qty ELSE NULL END as diesel_qty,
        CASE WHEN fc.fuel_type = 'diesel' THEN fcd.rate_per_liter ELSE NULL END as diesel_rate,
        CASE WHEN fc.fuel_type = 'diesel' THEN fcd.total_amount ELSE NULL END as diesel_amount,
        -- Mobil details
        CASE WHEN fc.fuel_type = 'mobil' THEN fcd.disburse_qty ELSE NULL END as mobil_qty,
        CASE WHEN fc.fuel_type = 'mobil' THEN fcd.rate_per_liter ELSE NULL END as mobil_rate,
        CASE WHEN fc.fuel_type = 'mobil' THEN fcd.total_amount ELSE NULL END as mobil_amount,
        -- Total for this row
        COALESCE(fcd.total_amount, 0) as row_total,
        fc.fiscal_year,
        fc.month_nep,
        fc.pump_name,
        fcd.disburse_date_nep
    FROM fuel_coupons fc
    JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
    LEFT JOIN fuel_coupon_distributions fcd ON fc.coupon_id = fcd.coupon_id 
        AND fcd.deleted_at IS NULL
    WHERE $where_clause
    ORDER BY v.vehicle_no, fc.fuel_type
");
$stmt->execute($params);
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate grand totals
$grand_petrol_qty = 0;
$grand_petrol_amount = 0;
$grand_diesel_qty = 0;
$grand_diesel_amount = 0;
$grand_mobil_qty = 0;
$grand_mobil_amount = 0;
$grand_total = 0;

foreach ($data as $row) {
    if ($row['petrol_qty']) {
        $grand_petrol_qty += $row['petrol_qty'];
        $grand_petrol_amount += $row['petrol_amount'];
    }
    if ($row['diesel_qty']) {
        $grand_diesel_qty += $row['diesel_qty'];
        $grand_diesel_amount += $row['diesel_amount'];
    }
    if ($row['mobil_qty']) {
        $grand_mobil_qty += $row['mobil_qty'];
        $grand_mobil_amount += $row['mobil_amount'];
    }
    $grand_total += $row['row_total'];
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

.report-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 15px;
    font-size: 12px;
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

.report-table .section-header {
    background: #e0e0e0;
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

.filter-container {
    background: #f5f7fa;
    padding: 15px;
    margin-bottom: 20px;
    border-radius: 8px;
    display: flex;
    gap: 15px;
    align-items: end;
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

.form-select {
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
        font-size: 10px;
    }
    
    .report-table th,
    .report-table td {
        padding: 4px 6px;
    }
}
</style>

<div class="filter-container">
    <form method="GET" style="display: flex; gap: 15px; align-items: end; width: 100%;">
        <div class="form-group">
            <label class="form-label">Fiscal Year</label>
            <input type="text" name="fiscal_year" class="form-select" 
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

        <button type="submit" class="btn btn-primary">🔍 Generate</button>
        <button type="button" class="btn btn-success" onclick="window.print()">🖨️ Print</button>
    </form>
</div>

<div class="report-container">
    <div class="report-header">
        <div class="org-name">जनक शिक्षा सामग्री केन्द्र लि.</div>
        <div class="org-address">(नेपाल सरकारको पूर्ण स्वामित्व भएको)</div>
        <div class="org-address">केन्द्रीय कार्यालय, सानोठिमी, भक्तपुर</div>
        <div class="report-title">कर्मचारी प्रशासन शाखा, सवारी इकाई</div>
        <div class="report-period">
            मितिः <?= htmlspecialchars($fiscal_year ?: '2082/83') ?>
            <?php if ($month_nep): ?>
                (<?= htmlspecialchars($month_nep) ?> महिना)
            <?php endif; ?>
        </div>
    </div>

    <table class="report-table">
        <thead>
            <tr>
                <th rowspan="2">क्र.<br>सं.</th>
                <th rowspan="2">सवारी नं.</th>
                <th rowspan="2">डिजेल</th>
                <th colspan="3" class="section-header">पेट्रोलको विवरण</th>
                <th colspan="3" class="section-header">डिजलको विवरण</th>
                <th colspan="3" class="section-header">मोबिलको विवरण</th>
                <th rowspan="2">जम्मा रकम<br>(रु.)</th>
            </tr>
            <tr>
                <!-- Petrol -->
                <th>खपत लि.</th>
                <th>दर</th>
                <th>रकम</th>
                <!-- Diesel -->
                <th>खपत लि.</th>
                <th>दर</th>
                <th>रकम</th>
                <!-- Mobil -->
                <th>खपत लि.</th>
                <th>दर</th>
                <th>रकम</th>
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
                        <td class="text-left"><?= htmlspecialchars($row['vehicle_no']) ?></td>
                        <td class="text-left"><?= ucfirst($row['vehicle_type']) ?></td>
                        
                        <!-- Petrol -->
                        <td><?= $row['petrol_qty'] ? number_format($row['petrol_qty'], 2) : '' ?></td>
                        <td><?= $row['petrol_rate'] ? number_format($row['petrol_rate'], 2) : '' ?></td>
                        <td class="text-right"><?= $row['petrol_amount'] ? number_format($row['petrol_amount'], 2) : '' ?></td>
                        
                        <!-- Diesel -->
                        <td><?= $row['diesel_qty'] ? number_format($row['diesel_qty'], 2) : '' ?></td>
                        <td><?= $row['diesel_rate'] ? number_format($row['diesel_rate'], 2) : '' ?></td>
                        <td class="text-right"><?= $row['diesel_amount'] ? number_format($row['diesel_amount'], 2) : '' ?></td>
                        
                        <!-- Mobil -->
                        <td><?= $row['mobil_qty'] ? number_format($row['mobil_qty'], 2) : '' ?></td>
                        <td><?= $row['mobil_rate'] ? number_format($row['mobil_rate'], 2) : '' ?></td>
                        <td class="text-right"><?= $row['mobil_amount'] ? number_format($row['mobil_amount'], 2) : '' ?></td>
                        
                        <td class="text-right"><strong><?= number_format($row['row_total'], 2) ?></strong></td>
                    </tr>
                <?php endforeach; ?>
                
                <!-- Total Row -->
                <tr class="total-row">
                    <td colspan="3" class="text-right">जम्मा</td>
                    <td><?= number_format($grand_petrol_qty, 2) ?></td>
                    <td></td>
                    <td class="text-right"><?= number_format($grand_petrol_amount, 2) ?></td>
                    <td><?= number_format($grand_diesel_qty, 2) ?></td>
                    <td></td>
                    <td class="text-right"><?= number_format($grand_diesel_amount, 2) ?></td>
                    <td><?= number_format($grand_mobil_qty, 2) ?></td>
                    <td></td>
                    <td class="text-right"><?= number_format($grand_mobil_amount, 2) ?></td>
                    <td class="text-right"><strong><?= number_format($grand_total, 2) ?></strong></td>
                </tr>
            <?php endif; ?>
        </tbody>
    </table>

    <div class="report-footer">
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">प्रमुख</div>
            <div class="signature-label">कर्मचारी प्रशासन शाखा</div>
        </div>
        
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">प्रशासन विभाग</div>
            <div class="signature-label">ज.शि.सा.के.लि.</div>
        </div>
        
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">मानव संसाधन तथा वित्त निर्देशनालय</div>
        </div>
        
        <div class="signature-section">
            <div class="signature-line"></div>
            <div class="signature-label">प्रमुख सञ्चालक</div>
            <div class="signature-label">ज.शि.सा.के.लि.</div>
        </div>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

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
        
        $action = $_POST['action'] ?? 'create';
        
        if ($action === 'create_coupon') {
            $required_fields = ['fiscal_year', 'month_nep', 'vehicle_id', 'fuel_type', 
                              'allocated_qty', 'issued_date_nep', 'issued_date_eng', 'created_by'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Check for duplicate coupon
            $check_stmt = $conn->prepare("
                SELECT coupon_id FROM fuel_coupons 
                WHERE fiscal_year = :fiscal_year 
                  AND month_nep = :month_nep 
                  AND vehicle_id = :vehicle_id 
                  AND deleted_at IS NULL
            ");
            $check_stmt->execute([
                ':fiscal_year' => $_POST['fiscal_year'],
                ':month_nep' => $_POST['month_nep'],
                ':vehicle_id' => $_POST['vehicle_id']
            ]);
            
            if ($check_stmt->fetch()) {
                throw new Exception("Fuel coupon already exists for this vehicle in this month.");
            }
            
            $insert_sql = "
                INSERT INTO fuel_coupons (
                    fiscal_year, month_nep, vehicle_id, fuel_type,
                    allocated_qty, carry_forward_qty,
                    issued_date_nep, issued_date_eng, coupon_no, pump_name,
                    verified_with_pump, paid_status, remarks, created_by
                ) VALUES (
                    :fiscal_year, :month_nep, :vehicle_id, :fuel_type,
                    :allocated_qty, :carry_forward_qty,
                    :issued_date_nep, :issued_date_eng, :coupon_no, :pump_name,
                    :verified_with_pump, :paid_status, :remarks, :created_by
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':fiscal_year' => $_POST['fiscal_year'],
                ':month_nep' => $_POST['month_nep'],
                ':vehicle_id' => $_POST['vehicle_id'],
                ':fuel_type' => $_POST['fuel_type'],
                ':allocated_qty' => $_POST['allocated_qty'],
                ':carry_forward_qty' => $_POST['carry_forward_qty'] ?? 0,
                ':issued_date_nep' => $_POST['issued_date_nep'],
                ':issued_date_eng' => $_POST['issued_date_eng'],
                ':coupon_no' => $_POST['coupon_no'] ?? null,
                ':pump_name' => $_POST['pump_name'] ?? null,
                ':verified_with_pump' => isset($_POST['verified_with_pump']) ? 1 : 0,
                ':paid_status' => isset($_POST['paid_status']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':created_by' => $_POST['created_by']
            ]);
            
            $success_message = "Fuel coupon created successfully!";
            
        } elseif ($action === 'add_distribution') {
            $required_fields = ['coupon_id', 'disburse_date_nep', 'disburse_date_eng', 
                              'disburse_qty', 'rate_per_liter', 'created_by'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Check available quantity
            $check_stmt = $conn->prepare("
                SELECT 
                    fc.total_available_qty,
                    COALESCE(SUM(fcd.disburse_qty), 0) as already_distributed
                FROM fuel_coupons fc
                LEFT JOIN fuel_coupon_distributions fcd ON fc.coupon_id = fcd.coupon_id 
                    AND fcd.deleted_at IS NULL
                WHERE fc.coupon_id = :coupon_id
                GROUP BY fc.coupon_id, fc.total_available_qty
            ");
            $check_stmt->execute([':coupon_id' => $_POST['coupon_id']]);
            $result = $check_stmt->fetch();
            
            $remaining = $result['total_available_qty'] - $result['already_distributed'];
            if ($_POST['disburse_qty'] > $remaining) {
                throw new Exception("Distribution quantity ({$_POST['disburse_qty']} L) exceeds remaining balance ({$remaining} L)");
            }
            
            $insert_sql = "
                INSERT INTO fuel_coupon_distributions (
                    coupon_id, disburse_date_nep, disburse_date_eng,
                    disburse_qty, rate_per_liter, verified_flag, remarks,
                    fiscal_year, created_by
                ) VALUES (
                    :coupon_id, :disburse_date_nep, :disburse_date_eng,
                    :disburse_qty, :rate_per_liter, :verified_flag, :remarks,
                    :fiscal_year, :created_by
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':coupon_id' => $_POST['coupon_id'],
                ':disburse_date_nep' => $_POST['disburse_date_nep'],
                ':disburse_date_eng' => $_POST['disburse_date_eng'],
                ':disburse_qty' => $_POST['disburse_qty'],
                ':rate_per_liter' => $_POST['rate_per_liter'],
                ':verified_flag' => isset($_POST['verified_flag']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $_POST['fiscal_year'],
                ':created_by' => $_POST['created_by']
            ]);
            
            $success_message = "Fuel distribution added successfully!";
            
        } elseif ($action === 'delete_coupon') {
            $stmt = $conn->prepare("UPDATE fuel_coupons SET deleted_at = CURRENT_TIMESTAMP WHERE coupon_id = :coupon_id");
            $stmt->execute([':coupon_id' => $_POST['coupon_id']]);
            $success_message = "Fuel coupon deleted successfully!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Fetch dropdown data
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type, fuel_type 
    FROM vehicles 
    WHERE status = TRUE AND deleted_at IS NULL 
    ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

$users = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Fetch coupons with details
$coupons = $conn->query("
    SELECT * FROM v_fuel_coupon_full_details
    ORDER BY fiscal_year DESC, month_nep, vehicle_no
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get current prices
$current_prices = [];
try {
    $prices = $conn->query("SELECT * FROM v_fuel_price_current")->fetchAll(PDO::FETCH_ASSOC);
    foreach ($prices as $p) {
        $current_prices[$p['fuel_type']] = $p['rate_per_liter'];
    }
} catch (Exception $e) {
    // Prices table might not exist yet
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    margin-bottom: 30px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
    margin-bottom: 10px;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-section {
    margin-bottom: 30px;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    margin-bottom: 20px;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
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

.form-group label .required {
    color: #dc3545;
}

.form-control {
    padding: 10px 14px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.summary-box {
    background: #fff3cd;
    border: 2px solid #ffc107;
    border-radius: 8px;
    padding: 15px;
    margin-top: 15px;
}

.summary-row {
    display: flex;
    justify-content: space-between;
    padding: 8px 0;
    border-bottom: 1px solid #ffe69c;
}

.summary-row:last-child {
    border-bottom: none;
    font-weight: 700;
    font-size: 15px;
    color: #856404;
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
.btn-secondary { background: #6c757d; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 1px solid #e9ecef;
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

.badge-verified { background: #d4edda; color: #155724; }
.badge-unverified { background: #f8d7da; color: #721c24; }
.badge-paid { background: #d4edda; color: #155724; }
.badge-unpaid { background: #fff3cd; color: #856404; }

.tab-container {
    display: flex;
    gap: 10px;
    margin-bottom: 20px;
    border-bottom: 2px solid #e9ecef;
}

.tab {
    padding: 12px 24px;
    background: none;
    border: none;
    border-bottom: 3px solid transparent;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    color: #6c757d;
    transition: all 0.3s ease;
}

.tab.active {
    color: #007bff;
    border-bottom-color: #007bff;
}

.tab:hover {
    color: #007bff;
}

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">⛽ Fuel Coupon Management</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- Tabs -->
    <div class="tab-container">
        <button class="tab active" onclick="switchTab('create-coupon')">📋 Create Coupon</button>
        <button class="tab" onclick="switchTab('add-distribution')">📦 Add Distribution</button>
        <button class="tab" onclick="switchTab('view-coupons')">📊 View Coupons</button>
    </div>

    <!-- Tab 1: Create Coupon -->
    <div id="create-coupon" class="tab-content active">
        <div class="form-container">
            <form method="POST" id="couponForm">
                <input type="hidden" name="action" value="create_coupon">

                <div class="form-section">
                    <div class="section-title">📅 Period & Vehicle</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="fiscal_year">Fiscal Year <span class="required">*</span></label>
                            <select name="fiscal_year" id="fiscal_year" class="form-control" required>
                                <option value="2082/83">2082/83</option>
                                <option value="2083/84">2083/84</option>
                                <option value="2084/85">2084/85</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="month_nep">Nepali Month <span class="required">*</span></label>
                            <select name="month_nep" id="month_nep" class="form-control" required>
                                <option value="">Select Month</option>
                                <?php foreach ($nepali_months as $month): ?>
                                    <option value="<?= $month ?>"><?= $month ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="vehicle_id">Vehicle <span class="required">*</span></label>
                            <select name="vehicle_id" id="vehicle_id" class="form-control" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($vehicles as $vehicle): ?>
                                    <option value="<?= $vehicle['vehicle_id'] ?>" 
                                            data-fuel-type="<?= $vehicle['fuel_type'] ?>">
                                        <?= htmlspecialchars($vehicle['vehicle_no']) ?> (<?= ucfirst($vehicle['vehicle_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="fuel_type">Fuel Type <span class="required">*</span></label>
                            <select name="fuel_type" id="fuel_type" class="form-control" required>
                                <option value="">Select Fuel</option>
                                <option value="petrol">Petrol</option>
                                <option value="diesel">Diesel</option>
                                <option value="mobil">Mobil</option>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">📊 Allocation Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="allocated_qty">Allocated Quantity (Liters) <span class="required">*</span></label>
                            <input type="number" step="0.01" name="allocated_qty" id="allocated_qty" 
                                   class="form-control" placeholder="100.00" required>
                        </div>

                        <div class="form-group">
                            <label for="carry_forward_qty">Carry Forward (Liters)</label>
                            <input type="number" step="0.01" name="carry_forward_qty" id="carry_forward_qty" 
                                   class="form-control" placeholder="0.00" value="0">
                        </div>

                        <div class="form-group">
                            <label>Total Available</label>
                            <input type="text" id="total_available_display" class="form-control" readonly
                                   style="background: #e9ecef; font-weight: 600; font-size: 18px; color: #28a745;"
                                   value="0.00 L">
                        </div>
                    </div>

                    <div class="summary-box" id="allocation_summary" style="display: none;">
                        <div class="summary-row">
                            <span>Allocated:</span>
                            <strong id="alloc_display">0 L</strong>
                        </div>
                        <div class="summary-row">
                            <span>Carry Forward:</span>
                            <strong id="carry_display">0 L</strong>
                        </div>
                        <div class="summary-row">
                            <span>Total Available:</span>
                            <strong id="total_display">0 L</strong>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">📄 Coupon Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="issued_date_nep">Issue Date (Nepali) <span class="required">*</span></label>
                            <input type="text" name="issued_date_nep" id="issued_date_nep" 
                                   class="form-control" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.15" required>
                        </div>

                        <div class="form-group">
                            <label for="issued_date_eng">Issue Date (English) <span class="required">*</span></label>
                            <input type="date" name="issued_date_eng" id="issued_date_eng" 
                                   class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="coupon_no">Coupon Number</label>
                            <input type="text" name="coupon_no" id="coupon_no" 
                                   class="form-control" placeholder="FC-2082-001">
                        </div>

                        <div class="form-group">
                            <label for="pump_name">Pump Name</label>
                            <input type="text" name="pump_name" id="pump_name" 
                                   class="form-control" placeholder="Janak Fuel Station">
                        </div>

                        <div class="form-group">
                            <label>Verified with Pump</label>
                            <div class="checkbox-group">
                                <input type="checkbox" name="verified_with_pump" id="verified_with_pump">
                                <label for="verified_with_pump" style="margin: 0;">Verified</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label>Payment Status</label>
                            <div class="checkbox-group">
                                <input type="checkbox" name="paid_status" id="paid_status">
                                <label for="paid_status" style="margin: 0;">Paid</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="created_by">Created By <span class="required">*</span></label>
                            <select name="created_by" id="created_by" class="form-control" required>
                                <option value="">Select User</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="remarks">Remarks</label>
                            <textarea name="remarks" id="remarks" class="form-control" rows="2"></textarea>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">✅ Create Fuel Coupon</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab 2: Add Distribution -->
    <div id="add-distribution" class="tab-content">
        <div class="form-container">
            <form method="POST" id="distributionForm">
                <input type="hidden" name="action" value="add_distribution">

                <div class="form-section">
                    <div class="section-title">⛽ Select Coupon</div>
                    <div class="form-grid">
                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="coupon_id_dist">Fuel Coupon <span class="required">*</span></label>
                            <select name="coupon_id" id="coupon_id_dist" class="form-control" required>
                                <option value="">Select Coupon</option>
                                <?php foreach ($coupons as $coupon): ?>
                                    <option value="<?= $coupon['coupon_id'] ?>"
                                            data-allocated="<?= $coupon['total_available_qty'] ?>"
                                            data-distributed="<?= $coupon['total_distributed'] ?>"
                                            data-remaining="<?= $coupon['remaining_qty'] ?>"
                                            data-fuel="<?= $coupon['fuel_type'] ?>">
                                        <?= $coupon['fiscal_year'] ?> - <?= $coupon['month_nep'] ?> - 
                                        <?= htmlspecialchars($coupon['vehicle_no']) ?> - 
                                        <?= strtoupper($coupon['fuel_type']) ?> 
                                        (Remaining: <?= number_format($coupon['remaining_qty'], 2) ?> L)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>
                </div>

                <div class="form-section">
                    <div class="section-title">📦 Distribution Details</div>
                    <div class="form-grid">
                        <div class="form-group">
                            <label for="disburse_date_nep">Distribution Date (Nepali) <span class="required">*</span></label>
                            <input type="text" name="disburse_date_nep" id="disburse_date_nep" 
                                   class="form-control" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.20" required>
                        </div>

                        <div class="form-group">
                            <label for="disburse_date_eng">Distribution Date (English) <span class="required">*</span></label>
                            <input type="date" name="disburse_date_eng" id="disburse_date_eng" 
                                   class="form-control" required>
                        </div>

                        <div class="form-group">
                            <label for="disburse_qty">Quantity (Liters) <span class="required">*</span></label>
                            <input type="number" step="0.01" name="disburse_qty" id="disburse_qty" 
                                   class="form-control" placeholder="50.00" required>
                        </div>

                        <div class="form-group">
                            <label for="rate_per_liter">Rate per Liter (रू) <span class="required">*</span></label>
                            <input type="number" step="0.01" name="rate_per_liter" id="rate_per_liter" 
                                   class="form-control" placeholder="165.00" required>
                        </div>

                        <div class="form-group">
                            <label>Total Amount</label>
                            <input type="text" id="dist_total_amount" class="form-control" readonly
                                   style="background: #e9ecef; font-weight: 600; font-size: 18px; color: #28a745;"
                                   value="रू 0.00">
                        </div>

                        <div class="form-group">
                            <label>Verified</label>
                            <div class="checkbox-group">
                                <input type="checkbox" name="verified_flag" id="verified_flag">
                                <label for="verified_flag" style="margin: 0;">Verified</label>
                            </div>
                        </div>

                        <div class="form-group">
                            <label for="fiscal_year_dist">Fiscal Year <span class="required">*</span></label>
                            <select name="fiscal_year" id="fiscal_year_dist" class="form-control" required>
                                <option value="2082/83">2082/83</option>
                                <option value="2083/84">2083/84</option>
                                <option value="2084/85">2084/85</option>
                            </select>
                        </div>

                        <div class="form-group">
                            <label for="created_by_dist">Created By <span class="required">*</span></label>
                            <select name="created_by" id="created_by_dist" class="form-control" required>
                                <option value="">Select User</option>
                                <?php foreach ($users as $user): ?>
                                    <option value="<?= $user['id'] ?>">
                                        <?= htmlspecialchars($user['username']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="form-group" style="grid-column: 1 / -1;">
                            <label for="remarks_dist">Remarks</label>
                            <textarea name="remarks" id="remarks_dist" class="form-control" rows="2"></textarea>
                        </div>
                    </div>

                    <div class="summary-box" id="dist_summary" style="display: none;">
                        <div class="summary-row">
                            <span>Allocated:</span>
                            <strong id="dist_allocated">0 L</strong>
                        </div>
                        <div class="summary-row">
                            <span>Already Distributed:</span>
                            <strong id="dist_already">0 L</strong>
                        </div>
                        <div class="summary-row">
                            <span>Remaining:</span>
                            <strong id="dist_remaining">0 L</strong>
                        </div>
                        <div class="summary-row">
                            <span>This Distribution:</span>
                            <strong id="dist_this">0 L</strong>
                        </div>
                        <div class="summary-row">
                            <span>After Distribution:</span>
                            <strong id="dist_after" style="color: #28a745;">0 L</strong>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">📦 Add Distribution</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab 3: View Coupons -->
    <div id="view-coupons" class="tab-content">
        <div class="data-table-container">
            <table class="data-table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Period</th>
                        <th>Vehicle</th>
                        <th>Fuel Type</th>
                        <th>Allocated</th>
                        <th>Distributed</th>
                        <th>Remaining</th>
                        <th>Pump</th>
                        <th>Status</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php if (empty($coupons)): ?>
                        <tr>
                            <td colspan="10" style="text-align: center; padding: 40px;">No coupons found</td>
                        </tr>
                    <?php else: ?>
                        <?php foreach ($coupons as $coupon): ?>
                            <tr>
                                <td><?= $coupon['coupon_id'] ?></td>
                                <td>
                                    <?= htmlspecialchars($coupon['fiscal_year']) ?><br>
                                    <small style="color: #6c757d;"><?= $coupon['month_nep'] ?></small>
                                </td>
                                <td>
                                    <strong><?= htmlspecialchars($coupon['vehicle_no']) ?></strong><br>
                                    <small style="color: #6c757d;"><?= ucfirst($coupon['vehicle_type']) ?></small>
                                </td>
                                <td><?= strtoupper($coupon['fuel_type']) ?></td>
                                <td><?= number_format($coupon['total_available_qty'], 2) ?> L</td>
                                <td><?= number_format($coupon['total_distributed'], 2) ?> L</td>
                                <td>
                                    <strong style="color: <?= $coupon['remaining_qty'] < 0 ? '#dc3545' : '#28a745' ?>;">
                                        <?= number_format($coupon['remaining_qty'], 2) ?> L
                                    </strong>
                                </td>
                                <td><?= htmlspecialchars($coupon['pump_name']) ?: '-' ?></td>
                                <td>
                                    <span class="badge badge-<?= $coupon['verified_with_pump'] ? 'verified' : 'unverified' ?>">
                                        <?= $coupon['verified_with_pump'] ? 'Verified' : 'Unverified' ?>
                                    </span><br>
                                    <span class="badge badge-<?= $coupon['paid_status'] ? 'paid' : 'unpaid' ?>">
                                        <?= $coupon['paid_status'] ? 'Paid' : 'Unpaid' ?>
                                    </span>
                                </td>
                                <td>
                                    <form method="POST" style="display: inline;" 
                                          onsubmit="return confirm('Are you sure you want to delete this coupon?')">
                                        <input type="hidden" name="action" value="delete_coupon">
                                        <input type="hidden" name="coupon_id" value="<?= $coupon['coupon_id'] ?>">
                                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                                    </form>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>

<script>
// Tab switching
function switchTab(tabName) {
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab-content').forEach(tc => tc.classList.remove('active'));
    
    event.target.classList.add('active');
    document.getElementById(tabName).classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {
    // Auto-fill fuel type from vehicle
    document.getElementById('vehicle_id').addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option) {
            document.getElementById('fuel_type').value = option.dataset.fuelType || '';
        }
    });
    
    // Calculate total available
    function calculateTotal() {
        const allocated = parseFloat(document.getElementById('allocated_qty').value) || 0;
        const carry = parseFloat(document.getElementById('carry_forward_qty').value) || 0;
        const total = allocated + carry;
        
        document.getElementById('total_available_display').value = total.toFixed(2) + ' L';
        
        if (allocated > 0 || carry > 0) {
            document.getElementById('alloc_display').textContent = allocated.toFixed(2) + ' L';
            document.getElementById('carry_display').textContent = carry.toFixed(2) + ' L';
            document.getElementById('total_display').textContent = total.toFixed(2) + ' L';
            document.getElementById('allocation_summary').style.display = 'block';
        } else {
            document.getElementById('allocation_summary').style.display = 'none';
        }
    }
    
    document.getElementById('allocated_qty').addEventListener('input', calculateTotal);
    document.getElementById('carry_forward_qty').addEventListener('input', calculateTotal);
    
    // Distribution calculations
    const couponSelect = document.getElementById('coupon_id_dist');
    const disburseQty = document.getElementById('disburse_qty');
    const ratePerLiter = document.getElementById('rate_per_liter');
    
    couponSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option && option.value) {
            const allocated = parseFloat(option.dataset.allocated) || 0;
            const distributed = parseFloat(option.dataset.distributed) || 0;
            const remaining = parseFloat(option.dataset.remaining) || 0;
            
            document.getElementById('dist_allocated').textContent = allocated.toFixed(2) + ' L';
            document.getElementById('dist_already').textContent = distributed.toFixed(2) + ' L';
            document.getElementById('dist_remaining').textContent = remaining.toFixed(2) + ' L';
            document.getElementById('dist_summary').style.display = 'block';
            
            // Try to get current price
            const fuelType = option.dataset.fuel;
            const prices = <?= json_encode($current_prices) ?>;
            if (prices[fuelType]) {
                ratePerLiter.value = prices[fuelType];
                calculateDistAmount();
            }
            
            calculateDistSummary();
        } else {
            document.getElementById('dist_summary').style.display = 'none';
        }
    });
    
    function calculateDistAmount() {
        const qty = parseFloat(disburseQty.value) || 0;
        const rate = parseFloat(ratePerLiter.value) || 0;
        const total = qty * rate;
        
        document.getElementById('dist_total_amount').value = 'रू ' + total.toFixed(2);
        calculateDistSummary();
    }
    
    function calculateDistSummary() {
        const option = couponSelect.options[couponSelect.selectedIndex];
        if (option && option.value) {
            const remaining = parseFloat(option.dataset.remaining) || 0;
            const thisQty = parseFloat(disburseQty.value) || 0;
            const after = remaining - thisQty;
            
            document.getElementById('dist_this').textContent = thisQty.toFixed(2) + ' L';
            document.getElementById('dist_after').textContent = after.toFixed(2) + ' L';
            document.getElementById('dist_after').style.color = after < 0 ? '#dc3545' : '#28a745';
        }
    }
    
    disburseQty.addEventListener('input', calculateDistAmount);
    ratePerLiter.addEventListener('input', calculateDistAmount);
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

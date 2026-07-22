<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;
$logged_user_id = $_SESSION['user_id'] ?? 1;

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
                              'allocated_qty', 'issued_date_nep', 'issued_date_eng'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Allow multiple coupons - even same fuel type multiple times
            // No duplicate check - user can create as many coupons as needed
            
            // Default pump name
            $pump_name = $_POST['pump_name'] ?? 'Om Sai Oil Pvt. Ltd.';
            
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
                ':pump_name' => $pump_name,
                ':verified_with_pump' => isset($_POST['verified_with_pump']) ? 1 : 0,
                ':paid_status' => isset($_POST['paid_status']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':created_by' => $logged_user_id
            ]);
            
            $success_message = "Fuel coupon created successfully!";
            
        } elseif ($action === 'add_distribution') {
            $required_fields = ['coupon_id', 'disburse_date_nep', 'disburse_date_eng', 'disburse_qty'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Get fuel type from coupon
            $fuel_stmt = $conn->prepare("
                SELECT fc.fuel_type, fc.fiscal_year,
                       fc.total_available_qty,
                       COALESCE(SUM(fcd.disburse_qty), 0) as already_distributed
                FROM fuel_coupons fc
                LEFT JOIN fuel_coupon_distributions fcd ON fc.coupon_id = fcd.coupon_id 
                    AND fcd.deleted_at IS NULL
                WHERE fc.coupon_id = :coupon_id
                GROUP BY fc.coupon_id, fc.fuel_type, fc.fiscal_year, fc.total_available_qty
            ");
            $fuel_stmt->execute([':coupon_id' => $_POST['coupon_id']]);
            $coupon_data = $fuel_stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$coupon_data) {
                throw new Exception("Coupon not found");
            }
            
            // Check available quantity
            $remaining = $coupon_data['total_available_qty'] - $coupon_data['already_distributed'];
            if ($_POST['disburse_qty'] > $remaining) {
                throw new Exception("Distribution quantity ({$_POST['disburse_qty']} L) exceeds remaining balance ({$remaining} L)");
            }
            
            // Auto-fetch fuel price if not provided
            $rate_per_liter = $_POST['rate_per_liter'] ?? null;
            if (empty($rate_per_liter)) {
                $price_stmt = $conn->prepare("
                    SELECT rate_per_liter
                    FROM fuel_price_history
                    WHERE fuel_type = :fuel_type
                      AND effective_from_date_eng <= :date
                      AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= :date)
                      AND is_active = TRUE
                      AND deleted_at IS NULL
                    ORDER BY effective_from_date_eng DESC
                    LIMIT 1
                ");
                $price_stmt->execute([
                    ':fuel_type' => $coupon_data['fuel_type'],
                    ':date' => $_POST['disburse_date_eng']
                ]);
                $price_result = $price_stmt->fetch(PDO::FETCH_ASSOC);
                $rate_per_liter = $price_result['rate_per_liter'] ?? 0;
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
                ':rate_per_liter' => $rate_per_liter,
                ':verified_flag' => isset($_POST['verified_flag']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':fiscal_year' => $coupon_data['fiscal_year'],
                ':created_by' => $logged_user_id
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

// Fetch coupons with details
$coupons = $conn->query("
    SELECT * FROM v_fuel_coupon_full_details
    ORDER BY fiscal_year DESC, month_nep, vehicle_no
    LIMIT 100
")->fetchAll(PDO::FETCH_ASSOC);

// Get current prices
$current_prices = [];
try {
    $price_query = $conn->query("SELECT * FROM v_fuel_price_current");
    while ($row = $price_query->fetch(PDO::FETCH_ASSOC)) {
        $current_prices[$row['fuel_type']] = $row['rate_per_liter'];
    }
} catch (Exception $e) {
    // Prices not available
}

// Pump names
$pump_names = ['Om Sai Oil Pvt. Ltd.', 'Nepal Oil Corporation', 'Other Pump'];
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
    margin-bottom: 30px;
}

.page-title {
    font-size: 28px;
    font-weight: 700;
    color: #333;
    display: flex;
    align-items: center;
    gap: 10px;
}

.alert {
    padding: 15px 20px;
    border-radius: 8px;
    margin-bottom: 20px;
    font-weight: 500;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 1px solid #c3e6cb;
}

.alert-error {
    background: #f8d7da;
    color: #721c24;
    border: 1px solid #f5c6cb;
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
    transition: all 0.3s;
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

.tab-content {
    display: none;
}

.tab-content.active {
    display: block;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
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

.required::after {
    content: ' *';
    color: #dc3545;
}

.form-input, .form-select, .form-textarea {
    padding: 10px 15px;
    border: 1px solid #ced4da;
    border-radius: 6px;
    font-size: 14px;
    transition: border-color 0.3s;
}

.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,0.1);
}

.form-textarea {
    resize: vertical;
    min-height: 80px;
}

.form-checkbox {
    display: flex;
    align-items: center;
    gap: 8px;
    margin-top: 10px;
}

.form-checkbox input {
    width: 18px;
    height: 18px;
}

.info-box {
    background: #e7f3ff;
    border-left: 4px solid #007bff;
    padding: 15px;
    border-radius: 6px;
    margin: 20px 0;
}

.info-box h4 {
    margin: 0 0 10px 0;
    color: #004085;
}

.info-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
}

.info-item {
    display: flex;
    flex-direction: column;
}

.info-label {
    font-size: 12px;
    color: #6c757d;
    margin-bottom: 4px;
}

.info-value {
    font-size: 18px;
    font-weight: 700;
    color: #333;
}

.form-actions {
    display: flex;
    gap: 15px;
    margin-top: 25px;
    padding-top: 25px;
    border-top: 1px solid #dee2e6;
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 8px;
    font-size: 15px;
    font-weight: 600;
    cursor: pointer;
    transition: all 0.3s;
    display: inline-flex;
    align-items: center;
    gap: 8px;
}

.btn-success {
    background: #28a745;
    color: white;
}

.btn-success:hover {
    background: #218838;
}

.btn-primary {
    background: #007bff;
    color: white;
}

.btn-primary:hover {
    background: #0056b3;
}

.btn-danger {
    background: #dc3545;
    color: white;
}

.btn-danger:hover {
    background: #c82333;
}

.btn-sm {
    padding: 6px 12px;
    font-size: 13px;
}

.data-table-container {
    background: white;
    border-radius: 12px;
    padding: 20px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
    overflow-x: auto;
}

.data-table {
    width: 100%;
    border-collapse: collapse;
}

.data-table th {
    background: #f8f9fa;
    padding: 12px;
    text-align: left;
    font-weight: 600;
    color: #495057;
    border-bottom: 2px solid #dee2e6;
    white-space: nowrap;
}

.data-table td {
    padding: 12px;
    border-bottom: 1px solid #f1f1f1;
}

.data-table tr:hover {
    background: #f8f9fa;
}

.badge {
    display: inline-block;
    padding: 4px 10px;
    border-radius: 12px;
    font-size: 12px;
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

.current-price-info {
    background: #e7f3ff;
    padding: 15px;
    border-radius: 8px;
    margin-bottom: 20px;
}

.current-price-info h4 {
    margin: 0 0 10px 0;
    color: #004085;
}

.price-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
    gap: 15px;
}

.price-item {
    background: white;
    padding: 10px;
    border-radius: 6px;
    text-align: center;
}

.fuel-type-label {
    font-size: 12px;
    color: #6c757d;
    text-transform: uppercase;
    margin-bottom: 4px;
}

.fuel-price {
    font-size: 20px;
    font-weight: 700;
    color: #007bff;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">⛽ Fuel Coupon Management</h1>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if (!empty($current_prices)): ?>
    <div class="current-price-info">
        <h4>📊 Current Fuel Prices</h4>
        <div class="price-grid">
            <?php foreach ($current_prices as $fuel => $price): ?>
            <div class="price-item">
                <div class="fuel-type-label"><?= strtoupper($fuel) ?></div>
                <div class="fuel-price">रू <?= number_format($price, 2) ?>/L</div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="tabs">
        <button class="tab active" onclick="switchTab('create-coupon')">🎫 Create Coupon</button>
        <button class="tab" onclick="switchTab('add-distribution')">📦 Add Distribution</button>
        <button class="tab" onclick="switchTab('view-coupons')">📋 View Coupons</button>
    </div>

    <!-- Tab 1: Create Coupon -->
    <div id="create-coupon" class="tab-content active">
        <div class="form-container">
            <form method="POST">
                <input type="hidden" name="action" value="create_coupon">
                
                <div class="form-grid">
                    <div class="form-group">
                        <label class="form-label required">Fiscal Year</label>
                        <input type="text" name="fiscal_year" class="form-input" value="2082/83" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Month (Nepali)</label>
                        <select name="month_nep" class="form-select" required>
                            <option value="">Select Month</option>
                            <?php foreach ($nepali_months as $month): ?>
                                <option value="<?= $month ?>"><?= $month ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Vehicle</label>
                        <select name="vehicle_id" id="vehicle_id" class="form-select" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $vehicle): ?>
                                <option value="<?= $vehicle['vehicle_id'] ?>" 
                                        data-fuel-type="<?= $vehicle['fuel_type'] ?>">
                                    <?= htmlspecialchars($vehicle['vehicle_no']) ?> 
                                    (<?= ucfirst($vehicle['vehicle_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Fuel Type</label>
                        <select name="fuel_type" id="fuel_type" class="form-select" required>
                            <option value="">Select Fuel Type</option>
                            <option value="petrol">Petrol</option>
                            <option value="diesel">Diesel</option>
                            <option value="mobil">Mobil</option>
                        </select>
                        <small style="color: #6c757d; margin-top: 4px;">
                            Note: Vehicle can have multiple fuel types (e.g., diesel + mobil)
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Allocated Quantity (Liters)</label>
                        <input type="number" name="allocated_qty" id="allocated_qty" 
                               class="form-input" step="0.01" min="0" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Carry Forward (Liters)</label>
                        <input type="number" name="carry_forward_qty" id="carry_forward_qty" 
                               class="form-input" step="0.01" min="0" value="0">
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Issued Date (Nepali)</label>
                        <input type="text" name="issued_date_nep" class="form-input" 
                               placeholder="2082.08.15" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Issued Date (English)</label>
                        <input type="date" name="issued_date_eng" class="form-input" 
                               value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Coupon Number</label>
                        <input type="text" name="coupon_no" class="form-input" 
                               placeholder="Optional">
                    </div>

                    <div class="form-group">
                        <label class="form-label">Pump Name</label>
                        <select name="pump_name" class="form-select">
                            <?php foreach ($pump_names as $pump): ?>
                                <option value="<?= $pump ?>" <?= $pump === 'Om Sai Oil Pvt. Ltd.' ? 'selected' : '' ?>>
                                    <?= $pump ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Total Available</label>
                        <input type="text" id="total_available_display" class="form-input" 
                               readonly style="background: #e9ecef;">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-textarea" 
                              placeholder="Any additional notes..."></textarea>
                </div>

                <div class="form-checkbox">
                    <input type="checkbox" name="verified_with_pump" id="verified_with_pump">
                    <label for="verified_with_pump">Verified with pump</label>
                </div>

                <div class="form-checkbox">
                    <input type="checkbox" name="paid_status" id="paid_status">
                    <label for="paid_status">Paid</label>
                </div>

                <div id="allocation_summary" class="info-box" style="display: none;">
                    <h4>💰 Allocation Summary</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Allocated</span>
                            <span class="info-value" id="alloc_display">0 L</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Carry Forward</span>
                            <span class="info-value" id="carry_display">0 L</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Total Available</span>
                            <span class="info-value" id="total_display">0 L</span>
                        </div>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-success">🎫 Create Coupon</button>
                </div>
            </form>
        </div>
    </div>

    <!-- Tab 2: Add Distribution -->
    <div id="add-distribution" class="tab-content">
        <div class="form-container">
            <form method="POST">
                <input type="hidden" name="action" value="add_distribution">
                
                <div class="form-grid">
                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label class="form-label required">Select Coupon</label>
                        <select name="coupon_id" id="coupon_id_dist" class="form-select" required>
                            <option value="">Select a coupon...</option>
                            <?php foreach ($coupons as $coupon): ?>
                                <?php if ($coupon['remaining_qty'] > 0): ?>
                                <option value="<?= $coupon['coupon_id'] ?>"
                                        data-fuel="<?= $coupon['fuel_type'] ?>"
                                        data-allocated="<?= $coupon['total_available_qty'] ?>"
                                        data-distributed="<?= $coupon['total_distributed'] ?>"
                                        data-remaining="<?= $coupon['remaining_qty'] ?>">
                                    <?= htmlspecialchars($coupon['vehicle_no']) ?> - 
                                    <?= $coupon['month_nep'] ?> <?= $coupon['fiscal_year'] ?> - 
                                    <?= strtoupper($coupon['fuel_type']) ?> 
                                    (Remaining: <?= number_format($coupon['remaining_qty'], 2) ?> L)
                                </option>
                                <?php endif; ?>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Distribution Date (Nepali)</label>
                        <input type="text" name="disburse_date_nep" class="form-input" 
                               placeholder="2082.08.15" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Distribution Date (English)</label>
                        <input type="date" name="disburse_date_eng" id="disburse_date_eng" 
                               class="form-input" value="<?= date('Y-m-d') ?>" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label required">Quantity (Liters)</label>
                        <input type="number" name="disburse_qty" id="disburse_qty" 
                               class="form-input" step="0.01" min="0.01" required>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Rate per Liter</label>
                        <input type="number" name="rate_per_liter" id="rate_per_liter" 
                               class="form-input" step="0.01" min="0"
                               placeholder="Auto-fetched from price history">
                        <small style="color: #6c757d; margin-top: 4px;">
                            Leave empty to auto-fetch current price
                        </small>
                    </div>

                    <div class="form-group">
                        <label class="form-label">Total Amount</label>
                        <input type="text" id="dist_total_amount" class="form-input" 
                               readonly style="background: #e9ecef;">
                    </div>
                </div>

                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-textarea" 
                              placeholder="Any notes about this distribution..."></textarea>
                </div>

                <div class="form-checkbox">
                    <input type="checkbox" name="verified_flag" id="verified_flag">
                    <label for="verified_flag">Verified</label>
                </div>

                <div id="dist_summary" class="info-box" style="display: none;">
                    <h4>📊 Distribution Summary</h4>
                    <div class="info-grid">
                        <div class="info-item">
                            <span class="info-label">Total Allocated</span>
                            <span class="info-value" id="dist_allocated">0 L</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Already Distributed</span>
                            <span class="info-value" id="dist_already">0 L</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Remaining Before</span>
                            <span class="info-value" id="dist_remaining">0 L</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">This Distribution</span>
                            <span class="info-value" id="dist_this">0 L</span>
                        </div>
                        <div class="info-item">
                            <span class="info-label">Remaining After</span>
                            <span class="info-value" id="dist_after">0 L</span>
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
    // Auto-fill fuel type from vehicle (but allow manual selection)
    document.getElementById('vehicle_id').addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option && option.dataset.fuelType) {
            // Suggest but don't force
            const fuelType = option.dataset.fuelType;
            if (fuelType !== 'mobil') {
                document.getElementById('fuel_type').value = fuelType;
            }
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
    const disburseDate = document.getElementById('disburse_date_eng');
    
    couponSelect.addEventListener('change', function() {
        const option = this.options[this.selectedIndex];
        if (option && option.value) {
            const allocated = parseFloat(option.dataset.allocated) || 0;
            const distributed = parseFloat(option.dataset.distributed) || 0;
            const remaining = parseFloat(option.dataset.remaining) || 0;
            const fuelType = option.dataset.fuel;
            
            document.getElementById('dist_allocated').textContent = allocated.toFixed(2) + ' L';
            document.getElementById('dist_already').textContent = distributed.toFixed(2) + ' L';
            document.getElementById('dist_remaining').textContent = remaining.toFixed(2) + ' L';
            document.getElementById('dist_summary').style.display = 'block';
            
            // Fetch current price via AJAX
            fetchCurrentPrice(fuelType, disburseDate.value);
            calculateDistSummary();
        } else {
            document.getElementById('dist_summary').style.display = 'none';
        }
    });
    
    // Also fetch price when date changes
    disburseDate.addEventListener('change', function() {
        const option = couponSelect.options[couponSelect.selectedIndex];
        if (option && option.value) {
            const fuelType = option.dataset.fuel;
            fetchCurrentPrice(fuelType, this.value);
        }
    });
    
    function fetchCurrentPrice(fuelType, date) {
        fetch(`/deno2/modules/vehicles/get_fuel_price.php?fuel=${fuelType}&date=${date}`)
            .then(response => response.json())
            .then(data => {
                if (data.success && data.price > 0) {
                    ratePerLiter.value = data.price;
                    ratePerLiter.placeholder = `Current price: रू ${data.price}/L`;
                    calculateDistAmount();
                }
            })
            .catch(error => console.error('Error fetching price:', error));
    }
    
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

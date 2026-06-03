<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['action'] ?? 'create';
        
        if ($action === 'create') {
            $required_fields = ['fiscal_year', 'month_nep', 'fuel_type', 'effective_from_date_nep', 
                              'effective_from_date_eng', 'rate_per_liter', 'created_by'];
            
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            
            // Deactivate previous prices for this fuel type if setting as active
            if (isset($_POST['is_active']) && $_POST['is_active']) {
                $stmt = $conn->prepare("
                    UPDATE fuel_price_history 
                    SET is_active = FALSE, 
                        updated_at = CURRENT_TIMESTAMP,
                        effective_to_date_eng = :new_from_date
                    WHERE fuel_type = :fuel_type 
                      AND is_active = TRUE 
                      AND deleted_at IS NULL
                      AND effective_to_date_eng IS NULL
                ");
                $stmt->execute([
                    ':fuel_type' => $_POST['fuel_type'],
                    ':new_from_date' => $_POST['effective_from_date_eng']
                ]);
            }
            
            $insert_sql = "
                INSERT INTO fuel_price_history (
                    fiscal_year, month_nep, fuel_type, 
                    effective_from_date_nep, effective_from_date_eng,
                    effective_to_date_nep, effective_to_date_eng,
                    rate_per_liter, source, notification_no, is_active, remarks, created_by
                ) VALUES (
                    :fiscal_year, :month_nep, :fuel_type,
                    :effective_from_date_nep, :effective_from_date_eng,
                    :effective_to_date_nep, :effective_to_date_eng,
                    :rate_per_liter, :source, :notification_no, :is_active, :remarks, :created_by
                )
            ";
            
            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':fiscal_year' => $_POST['fiscal_year'],
                ':month_nep' => $_POST['month_nep'],
                ':fuel_type' => $_POST['fuel_type'],
                ':effective_from_date_nep' => $_POST['effective_from_date_nep'],
                ':effective_from_date_eng' => $_POST['effective_from_date_eng'],
                ':effective_to_date_nep' => $_POST['effective_to_date_nep'] ?: null,
                ':effective_to_date_eng' => $_POST['effective_to_date_eng'] ?: null,
                ':rate_per_liter' => $_POST['rate_per_liter'],
                ':source' => $_POST['source'] ?? null,
                ':notification_no' => $_POST['notification_no'] ?? null,
                ':is_active' => isset($_POST['is_active']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':created_by' => $_POST['created_by']
            ]);
            
            $success_message = "Fuel price record created successfully!";
            
        } elseif ($action === 'update') {
            $update_sql = "
                UPDATE fuel_price_history SET 
                    fiscal_year = :fiscal_year,
                    month_nep = :month_nep,
                    fuel_type = :fuel_type,
                    effective_from_date_nep = :effective_from_date_nep,
                    effective_from_date_eng = :effective_from_date_eng,
                    effective_to_date_nep = :effective_to_date_nep,
                    effective_to_date_eng = :effective_to_date_eng,
                    rate_per_liter = :rate_per_liter,
                    source = :source,
                    notification_no = :notification_no,
                    is_active = :is_active,
                    remarks = :remarks,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE price_id = :price_id
            ";
            
            $stmt = $conn->prepare($update_sql);
            $stmt->execute([
                ':price_id' => $_POST['price_id'],
                ':fiscal_year' => $_POST['fiscal_year'],
                ':month_nep' => $_POST['month_nep'],
                ':fuel_type' => $_POST['fuel_type'],
                ':effective_from_date_nep' => $_POST['effective_from_date_nep'],
                ':effective_from_date_eng' => $_POST['effective_from_date_eng'],
                ':effective_to_date_nep' => $_POST['effective_to_date_nep'] ?: null,
                ':effective_to_date_eng' => $_POST['effective_to_date_eng'] ?: null,
                ':rate_per_liter' => $_POST['rate_per_liter'],
                ':source' => $_POST['source'] ?? null,
                ':notification_no' => $_POST['notification_no'] ?? null,
                ':is_active' => isset($_POST['is_active']) ? 1 : 0,
                ':remarks' => $_POST['remarks'] ?? null,
                ':updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $success_message = "Fuel price updated successfully!";
            
        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE fuel_price_history SET deleted_at = CURRENT_TIMESTAMP WHERE price_id = :price_id");
            $stmt->execute([':price_id' => $_POST['price_id']]);
            $success_message = "Fuel price deleted successfully!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

$users = $conn->query("SELECT id, username FROM users WHERE role IN ('operator', 'incharge', 'supervisor', 'admin') ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Nepali months
$nepali_months = [
    'Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
    'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'
];

// Fetch price history
$prices = $conn->query("
    SELECT 
        fph.*,
        u.username as created_by_username
    FROM fuel_price_history fph
    LEFT JOIN users u ON fph.created_by = u.id
    WHERE fph.deleted_at IS NULL
    ORDER BY fph.effective_from_date_eng DESC, fph.created_at DESC
    LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

// Get current prices
$current_prices = $conn->query("SELECT * FROM v_fuel_price_current")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM fuel_price_history WHERE price_id = :price_id AND deleted_at IS NULL");
    $stmt->execute([':price_id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
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

.current-prices-box {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    border-radius: 12px;
    padding: 20px;
    margin-bottom: 30px;
    color: white;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.current-prices-title {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 15px;
}

.price-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 15px;
}

.price-card {
    background: rgba(255,255,255,0.2);
    backdrop-filter: blur(10px);
    border-radius: 8px;
    padding: 15px;
    text-align: center;
}

.fuel-type-label {
    font-size: 14px;
    text-transform: uppercase;
    margin-bottom: 5px;
    opacity: 0.9;
}

.price-amount {
    font-size: 32px;
    font-weight: 700;
    margin-bottom: 5px;
}

.price-date {
    font-size: 12px;
    opacity: 0.8;
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

.badge-active { background: #d4edda; color: #155724; }
.badge-inactive { background: #f8d7da; color: #721c24; }
.badge-petrol { background: #fff3cd; color: #856404; }
.badge-diesel { background: #d1ecf1; color: #0c5460; }
.badge-mobil { background: #cce5ff; color: #004085; }
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">💰 Fuel Price History Management</h1>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <!-- Current Prices Display -->
    <?php if (!empty($current_prices)): ?>
    <div class="current-prices-box">
        <div class="current-prices-title">📊 Current Fuel Prices (Active Rates)</div>
        <div class="price-cards">
            <?php foreach ($current_prices as $cp): ?>
                <div class="price-card">
                    <div class="fuel-type-label"><?= strtoupper($cp['fuel_type']) ?></div>
                    <div class="price-amount">रू <?= number_format($cp['rate_per_liter'], 2) ?></div>
                    <div class="price-date">Since: <?= htmlspecialchars($cp['effective_from_date_nep']) ?></div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="priceForm">
            <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
            <?php if ($edit_record): ?>
                <input type="hidden" name="price_id" value="<?= $edit_record['price_id'] ?>">
            <?php endif; ?>

            <div class="form-section">
                <div class="section-title">💰 Price Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="fiscal_year">Fiscal Year <span class="required">*</span></label>
                        <select name="fiscal_year" id="fiscal_year" class="form-control" required>
                            <option value="2082/83" <?= (!$edit_record || $edit_record['fiscal_year'] === '2082/83') ? 'selected' : '' ?>>2082/83</option>
                            <option value="2083/84" <?= ($edit_record && $edit_record['fiscal_year'] === '2083/84') ? 'selected' : '' ?>>2083/84</option>
                            <option value="2084/85" <?= ($edit_record && $edit_record['fiscal_year'] === '2084/85') ? 'selected' : '' ?>>2084/85</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="month_nep">Nepali Month <span class="required">*</span></label>
                        <select name="month_nep" id="month_nep" class="form-control" required>
                            <option value="">Select Month</option>
                            <?php foreach ($nepali_months as $month): ?>
                                <option value="<?= $month ?>" <?= ($edit_record && $edit_record['month_nep'] === $month) ? 'selected' : '' ?>>
                                    <?= $month ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="fuel_type">Fuel Type <span class="required">*</span></label>
                        <select name="fuel_type" id="fuel_type" class="form-control" required>
                            <option value="">Select Fuel</option>
                            <option value="petrol" <?= ($edit_record && $edit_record['fuel_type'] === 'petrol') ? 'selected' : '' ?>>Petrol</option>
                            <option value="diesel" <?= ($edit_record && $edit_record['fuel_type'] === 'diesel') ? 'selected' : '' ?>>Diesel</option>
                            <option value="mobil" <?= ($edit_record && $edit_record['fuel_type'] === 'mobil') ? 'selected' : '' ?>>Mobil</option>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="rate_per_liter">Rate per Liter (रू) <span class="required">*</span></label>
                        <input type="number" step="0.01" name="rate_per_liter" id="rate_per_liter" 
                               class="form-control" placeholder="165.00"
                               value="<?= htmlspecialchars($edit_record['rate_per_liter'] ?? '') ?>" required>
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="section-title">📅 Effective Period</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="effective_from_date_nep">Effective From (Nepali) <span class="required">*</span></label>
                        <input type="text" name="effective_from_date_nep" id="effective_from_date_nep" 
                               class="form-control" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.05.01"
                               value="<?= htmlspecialchars($edit_record['effective_from_date_nep'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="effective_from_date_eng">Effective From (English) <span class="required">*</span></label>
                        <input type="date" name="effective_from_date_eng" id="effective_from_date_eng" 
                               class="form-control"
                               value="<?= htmlspecialchars($edit_record['effective_from_date_eng'] ?? '') ?>" required>
                    </div>

                    <div class="form-group">
                        <label for="effective_to_date_nep">Effective To (Nepali) - Optional</label>
                        <input type="text" name="effective_to_date_nep" id="effective_to_date_nep" 
                               class="form-control" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.05.15"
                               value="<?= htmlspecialchars($edit_record['effective_to_date_nep'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="effective_to_date_eng">Effective To (English) - Optional</label>
                        <input type="date" name="effective_to_date_eng" id="effective_to_date_eng" 
                               class="form-control"
                               value="<?= htmlspecialchars($edit_record['effective_to_date_eng'] ?? '') ?>">
                    </div>
                </div>
            </div>

            <div class="form-section">
                <div class="section-title">📋 Additional Information</div>
                <div class="form-grid">
                    <div class="form-group">
                        <label for="source">Source</label>
                        <input type="text" name="source" id="source" class="form-control"
                               placeholder="Nepal Oil Corporation"
                               value="<?= htmlspecialchars($edit_record['source'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="notification_no">Notification Number</label>
                        <input type="text" name="notification_no" id="notification_no" class="form-control"
                               placeholder="NOC/2082/123"
                               value="<?= htmlspecialchars($edit_record['notification_no'] ?? '') ?>">
                    </div>

                    <div class="form-group">
                        <label for="created_by">Created By <span class="required">*</span></label>
                        <select name="created_by" id="created_by" class="form-control" required>
                            <option value="">Select User</option>
                            <?php foreach ($users as $user): ?>
                                <option value="<?= $user['id'] ?>" <?= ($edit_record && $edit_record['created_by'] == $user['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($user['username']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                    <div class="form-group">
                        <label for="is_active">Status</label>
                        <div class="checkbox-group">
                            <input type="checkbox" name="is_active" id="is_active" 
                                   <?= (!$edit_record || $edit_record['is_active']) ? 'checked' : '' ?>>
                            <label for="is_active" style="margin: 0;">Active (Current Price)</label>
                        </div>
                        <small style="color: #6c757d; margin-top: 5px;">Check this to make it the current active price</small>
                    </div>

                    <div class="form-group" style="grid-column: 1 / -1;">
                        <label for="remarks">Remarks</label>
                        <textarea name="remarks" id="remarks" class="form-control" rows="2"><?= htmlspecialchars($edit_record['remarks'] ?? '') ?></textarea>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <?php if ($edit_record): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <?= $edit_record ? '💾 Update Price' : '✅ Add Price' ?>
                </button>
            </div>
        </form>
    </div>

    <!-- Price History Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>ID</th>
                    <th>Fiscal Year</th>
                    <th>Month</th>
                    <th>Fuel Type</th>
                    <th>Rate/Liter</th>
                    <th>Effective From</th>
                    <th>Effective To</th>
                    <th>Source</th>
                    <th>Status</th>
                    <th>Created By</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($prices)): ?>
                    <tr>
                        <td colspan="11" style="text-align: center; padding: 40px;">No price records found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($prices as $price): ?>
                        <tr>
                            <td><?= $price['price_id'] ?></td>
                            <td><?= htmlspecialchars($price['fiscal_year']) ?></td>
                            <td><?= htmlspecialchars($price['month_nep']) ?></td>
                            <td>
                                <span class="badge badge-<?= $price['fuel_type'] ?>">
                                    <?= strtoupper($price['fuel_type']) ?>
                                </span>
                            </td>
                            <td><strong>रू <?= number_format($price['rate_per_liter'], 2) ?></strong></td>
                            <td>
                                <div><?= htmlspecialchars($price['effective_from_date_nep']) ?></div>
                                <small style="color: #6c757d;"><?= date('M d, Y', strtotime($price['effective_from_date_eng'])) ?></small>
                            </td>
                            <td>
                                <?php if ($price['effective_to_date_nep']): ?>
                                    <div><?= htmlspecialchars($price['effective_to_date_nep']) ?></div>
                                    <small style="color: #6c757d;"><?= date('M d, Y', strtotime($price['effective_to_date_eng'])) ?></small>
                                <?php else: ?>
                                    <span style="color: #999;">Current</span>
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($price['source']) ?: '-' ?></td>
                            <td>
                                <span class="badge badge-<?= $price['is_active'] ? 'active' : 'inactive' ?>">
                                    <?= $price['is_active'] ? 'Active' : 'Inactive' ?>
                                </span>
                            </td>
                            <td><?= htmlspecialchars($price['created_by_username']) ?></td>
                            <td>
                                <a href="?edit_id=<?= $price['price_id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                                <form method="POST" style="display: inline;" 
                                      onsubmit="return confirm('Are you sure you want to delete this price record?')">
                                    <input type="hidden" name="action" value="delete">
                                    <input type="hidden" name="price_id" value="<?= $price['price_id'] ?>">
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

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

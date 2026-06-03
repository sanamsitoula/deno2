<?php
//fuel_coupons_old
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;
$logged_user_id = $_SESSION['user_id'] ?? 1;

$nepali_months = [
    'Baishakh', 'Jestha', 'Ashadh', 'Shrawan', 'Bhadra', 'Ashwin',
    'Kartik', 'Mangsir', 'Poush', 'Magh', 'Falgun', 'Chaitra'
];

$fuel_expense_types = [
    'internalfacility' => 'Internal Facility',
    'nepalpolice'      => 'Nepal Police',
    'gon'              => 'GON',
    'media'            => 'Media',
    'others'           => 'Others'
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        $action = $_POST['action'] ?? '';

        if ($action === 'create_coupon') {
            $required = ['fiscal_year','month_nep','vehicle_id','fuel_type','allocated_qty','issued_date_nep','issued_date_eng'];
            foreach ($required as $f) {
                if (empty($_POST[$f])) throw new Exception("Field '{$f}' is required");
            }
            $pump_name = $_POST['pump_name'] ?? 'Om Sai Oil Pvt. Ltd.';
            $conn->prepare("
                INSERT INTO fuel_coupons (
                    fiscal_year, month_nep, vehicle_id, fuel_type,
                    allocated_qty, carry_forward_qty,
                    issued_date_nep, issued_date_eng, coupon_no, pump_name,
                    fuel_expense_type, remarks,
                    verified_with_pump, paid_status, created_by
                ) VALUES (
                    :fiscal_year, :month_nep, :vehicle_id, :fuel_type,
                    :allocated_qty, :carry_forward_qty,
                    :issued_date_nep, :issued_date_eng, :coupon_no, :pump_name,
                    :fuel_expense_type, :remarks,
                    :verified_with_pump, :paid_status, :created_by
                )
            ")->execute([
                ':fiscal_year'       => $_POST['fiscal_year'],
                ':month_nep'         => $_POST['month_nep'],
                ':vehicle_id'        => $_POST['vehicle_id'],
                ':fuel_type'         => $_POST['fuel_type'],
                ':allocated_qty'     => $_POST['allocated_qty'],
                ':carry_forward_qty' => $_POST['carry_forward_qty'] ?? 0,
                ':issued_date_nep'   => $_POST['issued_date_nep'],
                ':issued_date_eng'   => $_POST['issued_date_eng'],
                ':coupon_no'         => $_POST['coupon_no'] ?? null,
                ':pump_name'         => $pump_name,
                ':fuel_expense_type' => $_POST['fuel_expense_type'] ?? null,
                ':remarks'           => $_POST['remarks'] ?? null,
                ':verified_with_pump'=> isset($_POST['verified_with_pump']) ? 1 : 0,
                ':paid_status'       => isset($_POST['paid_status']) ? 1 : 0,
                ':created_by'        => $logged_user_id
            ]);
            $success_message = "Fuel coupon created successfully!";

        } elseif ($action === 'add_distribution') {
            $required = ['coupon_id','disburse_date_nep','disburse_date_eng','disburse_qty','fuel_expense_type'];
            foreach ($required as $f) {
                if (empty($_POST[$f])) throw new Exception("Field '{$f}' is required. Expense Type is mandatory.");
            }
            $fuel_stmt = $conn->prepare("
                SELECT fc.fuel_type, fc.fiscal_year,
                       fc.total_available_qty,
                       COALESCE(SUM(fcd.disburse_qty), 0) as already_distributed
                FROM fuel_coupons fc
                LEFT JOIN fuel_coupon_distributions fcd ON fc.coupon_id = fcd.coupon_id AND fcd.deleted_at IS NULL
                WHERE fc.coupon_id = :coupon_id
                GROUP BY fc.coupon_id, fc.fuel_type, fc.fiscal_year, fc.total_available_qty
            ");
            $fuel_stmt->execute([':coupon_id' => $_POST['coupon_id']]);
            $coupon_data = $fuel_stmt->fetch(PDO::FETCH_ASSOC);
            if (!$coupon_data) throw new Exception("Coupon not found");

            $remaining = $coupon_data['total_available_qty'] - $coupon_data['already_distributed'];
            if ($_POST['disburse_qty'] > $remaining) {
                throw new Exception("Distribution qty ({$_POST['disburse_qty']} L) exceeds remaining balance ({$remaining} L)");
            }
            $rate_per_liter = $_POST['rate_per_liter'] ?? null;
            if (empty($rate_per_liter)) {
                $price_stmt = $conn->prepare("
                    SELECT rate_per_liter FROM fuel_price_history
                    WHERE fuel_type = :fuel_type
                      AND effective_from_date_eng <= :date
                      AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= :date)
                      AND is_active = TRUE AND deleted_at IS NULL
                    ORDER BY effective_from_date_eng DESC LIMIT 1
                ");
                $price_stmt->execute([':fuel_type' => $coupon_data['fuel_type'], ':date' => $_POST['disburse_date_eng']]);
                $rate_per_liter = $price_stmt->fetchColumn() ?? 0;
            }
            $conn->prepare("
                INSERT INTO fuel_coupon_distributions (
                    coupon_id, disburse_date_nep, disburse_date_eng,
                    disburse_qty, rate_per_liter, verified_flag, remarks,
                    fiscal_year, created_by
                ) VALUES (
                    :coupon_id, :disburse_date_nep, :disburse_date_eng,
                    :disburse_qty, :rate_per_liter, :verified_flag, :remarks,
                    :fiscal_year, :created_by
                )
            ")->execute([
                ':coupon_id'        => $_POST['coupon_id'],
                ':disburse_date_nep'=> $_POST['disburse_date_nep'],
                ':disburse_date_eng'=> $_POST['disburse_date_eng'],
                ':disburse_qty'     => $_POST['disburse_qty'],
                ':rate_per_liter'   => $rate_per_liter,
                ':verified_flag'    => isset($_POST['verified_flag']) ? 1 : 0,
                ':remarks'          => $_POST['remarks'] ?? null,
                ':fiscal_year'      => $coupon_data['fiscal_year'],
                ':created_by'       => $logged_user_id
            ]);
            $success_message = "Fuel distribution added successfully!";

        } elseif ($action === 'delete_coupon') {
            $conn->prepare("UPDATE fuel_coupons SET deleted_at = CURRENT_TIMESTAMP WHERE coupon_id = :id")
                 ->execute([':id' => $_POST['coupon_id']]);
            $success_message = "Coupon deleted.";
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Filters
$f_vehicle   = $_GET['f_vehicle']   ?? '';
$f_month     = $_GET['f_month']     ?? '';
$f_fiscal    = $_GET['f_fiscal']    ?? '2082/83';
$f_fuel_type = $_GET['f_fuel_type'] ?? '';
$f_exp_type  = $_GET['f_exp_type']  ?? '';

$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type, fuel_type
    FROM vehicles WHERE status = TRUE AND deleted_at IS NULL ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

// Build coupon query with filters
$where = "WHERE fc.deleted_at IS NULL";
$params = [];
if ($f_vehicle)   { $where .= " AND fc.vehicle_id = :fv";  $params[':fv']  = $f_vehicle; }
if ($f_month)     { $where .= " AND fc.month_nep = :fm";   $params[':fm']  = $f_month; }
if ($f_fiscal)    { $where .= " AND fc.fiscal_year = :ff"; $params[':ff']  = $f_fiscal; }
if ($f_fuel_type) { $where .= " AND fc.fuel_type = :ft";   $params[':ft']  = $f_fuel_type; }
if ($f_exp_type)  { $where .= " AND fc.fuel_expense_type = :fe"; $params[':fe'] = $f_exp_type; }

$coupon_stmt = $conn->prepare("
    SELECT fc.*,
           v.vehicle_no, v.vehicle_type,
           COALESCE(SUM(fcd.disburse_qty), 0) AS total_distributed,
           (fc.allocated_qty + COALESCE(fc.carry_forward_qty,0)) AS total_available_qty,
           (fc.allocated_qty + COALESCE(fc.carry_forward_qty,0)) - COALESCE(SUM(fcd.disburse_qty), 0) AS remaining_qty
    FROM fuel_coupons fc
    JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
    LEFT JOIN fuel_coupon_distributions fcd ON fc.coupon_id = fcd.coupon_id AND fcd.deleted_at IS NULL
    $where
    GROUP BY fc.coupon_id, v.vehicle_no, v.vehicle_type
    ORDER BY fc.fiscal_year DESC, fc.month_nep, v.vehicle_no
    LIMIT 200
");
$coupon_stmt->execute($params);
$coupons = $coupon_stmt->fetchAll(PDO::FETCH_ASSOC);

// Coupons with remaining qty for distribution tab
$avail_coupons = array_filter($coupons, fn($c) => $c['remaining_qty'] > 0);

$current_prices = [];
try {
    $pq = $conn->query("SELECT * FROM v_fuel_price_current");
    while ($r = $pq->fetch(PDO::FETCH_ASSOC)) $current_prices[$r['fuel_type']] = $r['rate_per_liter'];
} catch (Exception $e) {}

$pump_names = ['Om Sai Oil Pvt. Ltd.', 'Nepal Oil Corporation', 'Other Pump'];
?>
<!-- Nepali Datepicker v5 CSS -->
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
body { font-family: 'Segoe UI', sans-serif; background: #f5f7fa; font-size:15px; }
.container { max-width:1800px; margin:0 auto; padding:20px; }
.page-title { font-size:24px; font-weight:700; color:#333; margin-bottom:20px; }
.alert { padding:12px 18px; border-radius:6px; margin-bottom:15px; font-weight:500; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-error   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* Tabs */
.tabs { display:flex; gap:5px; border-bottom:2px solid #dee2e6; margin-bottom:20px; }
.tab { padding:10px 20px; background:none; border:none; color:#6c757d; font-size:14px; font-weight:600; cursor:pointer; border-bottom:3px solid transparent; }
.tab.active { color:#007bff; border-bottom-color:#007bff; }
.tab-content { display:none; }
.tab-content.active { display:block; }

/* Forms */
.form-box { background:#fff; border-radius:8px; padding:20px; box-shadow:0 1px 4px rgba(0,0,0,.1); margin-bottom:20px; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(220px, 1fr)); gap:15px; margin-bottom:15px; }
.fg { display:flex; flex-direction:column; }
.fg label { font-size:13px; font-weight:600; color:#495057; margin-bottom:5px; }
.fg label.req::after { content:' *'; color:#dc3545; }
input[type=text], input[type=number], input[type=date], select, textarea {
    padding:8px 12px; border:1px solid #ced4da; border-radius:5px; font-size:14px; width:100%; box-sizing:border-box;
}
input:focus, select:focus, textarea:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.2); }
.ndp-input { font-family:inherit; }
textarea { resize:vertical; min-height:60px; }
.chk { display:flex; align-items:center; gap:8px; margin-top:8px; }
.chk input { width:16px; height:16px; }

/* Buttons */
.btn { padding:9px 18px; border:none; border-radius:6px; font-size:14px; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:6px; text-decoration:none; }
.btn-success  { background:#28a745; color:#fff; }
.btn-primary  { background:#007bff; color:#fff; }
.btn-danger   { background:#dc3545; color:#fff; }
.btn-secondary{ background:#6c757d; color:#fff; }
.btn-info     { background:#17a2b8; color:#fff; }
.btn-sm { padding:5px 10px; font-size:12px; }

/* Filter bar */
.filter-bar { background:#fff; padding:15px 20px; border-radius:8px; margin-bottom:15px; box-shadow:0 1px 4px rgba(0,0,0,.08); }
.filter-grid { display:grid; grid-template-columns:repeat(auto-fit, minmax(160px,1fr)); gap:10px; align-items:end; }
.filter-grid .fg label { font-size:12px; }

/* Price info */
.price-bar { background:#e7f3ff; padding:12px 18px; border-radius:6px; margin-bottom:15px; border-left:4px solid #007bff; }
.price-bar h4 { margin:0 0 8px 0; color:#004085; font-size:14px; }
.price-items { display:flex; gap:20px; flex-wrap:wrap; }
.price-item { background:#fff; padding:8px 14px; border-radius:5px; text-align:center; min-width:100px; }
.price-item .pl { font-size:11px; color:#6c757d; text-transform:uppercase; }
.price-item .pv { font-size:17px; font-weight:700; color:#007bff; }

/* Info box */
.info-box { background:#e7f3ff; border-left:4px solid #007bff; padding:12px 16px; border-radius:6px; margin:12px 0; }
.info-box h4 { margin:0 0 8px 0; color:#004085; font-size:13px; }
.info-grid { display:flex; gap:20px; flex-wrap:wrap; }
.info-item .il { font-size:11px; color:#6c757d; }
.info-item .iv { font-size:16px; font-weight:700; color:#333; }

/* Table */
.tbl-wrap { background:#fff; border-radius:8px; overflow-x:auto; box-shadow:0 1px 4px rgba(0,0,0,.08); }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { background:#f8f9fa; padding:10px 8px; text-align:left; font-weight:700; color:#495057; border-bottom:2px solid #dee2e6; white-space:nowrap; font-size:12px; text-transform:uppercase; }
td { padding:10px 8px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
tr:hover { background:#f8f9fa; }
.badge { display:inline-block; padding:3px 8px; border-radius:10px; font-size:11px; font-weight:600; }
.badge-v { background:#d4edda; color:#155724; }
.badge-u { background:#fff3cd; color:#856404; }
.badge-p { background:#d4edda; color:#155724; }
.badge-np{ background:#f8d7da; color:#721c24; }

@media print {
    .no-print, .tabs, .filter-bar, .form-box, .btn, .page-title { display:none !important; }
    body { font-size:11px; background:#fff; }
    .tbl-wrap { box-shadow:none; }
    table { font-size:10px; }
    th, td { padding:4px 5px; border:1px solid #999 !important; }
}
</style>

<div class="container">
<h1 class="page-title">⛽ Fuel Coupon Management</h1>

<?php if ($error_message): ?>
    <div class="alert alert-error">❌ <?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>
<?php if ($success_message): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
<?php endif; ?>

<?php if (!empty($current_prices)): ?>
<div class="price-bar">
    <h4>📊 Current Fuel Prices</h4>
    <div class="price-items">
        <?php foreach ($current_prices as $ft => $pr): ?>
        <div class="price-item">
            <div class="pl"><?= strtoupper($ft) ?></div>
            <div class="pv">रू <?= number_format($pr, 2) ?>/L</div>
        </div>
        <?php endforeach; ?>
    </div>
</div>
<?php endif; ?>

<div class="tabs no-print">
    <button class="tab active" onclick="switchTab('tab-create',this)">🎫 Create Coupon</button>
    <button class="tab" onclick="switchTab('tab-dist',this)">📦 Add Distribution</button>
    <button class="tab" onclick="switchTab('tab-view',this)">📋 View / Search</button>
</div>

<!-- ═══════════════ TAB 1: Create Coupon ═══════════════ -->
<div id="tab-create" class="tab-content active">
<div class="form-box">
<form method="POST">
    <input type="hidden" name="action" value="create_coupon">
    <div class="form-grid">
        <div class="fg">
            <label class="req">Fiscal Year</label>
            <input type="text" name="fiscal_year" value="2082/83" required>
        </div>
        <div class="fg">
            <label class="req">Month (Nepali)</label>
            <select name="month_nep" required>
                <option value="">Select Month</option>
                <?php foreach ($nepali_months as $m): ?>
                <option value="<?= $m ?>"><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label class="req">Vehicle</label>
            <select name="vehicle_id" id="cc_vehicle" required>
                <option value="">Select Vehicle</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= $v['vehicle_id'] ?>" data-fuel="<?= $v['fuel_type'] ?>">
                    <?= htmlspecialchars($v['vehicle_no']) ?> (<?= ucfirst($v['vehicle_type']) ?>)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <!-- FIX: All 3 fuel types including mobil always shown -->
            <label class="req">Fuel Type</label>
            <select name="fuel_type" id="cc_fuel_type" required>
                <option value="">Select Fuel Type</option>
                <option value="petrol">Petrol</option>
                <option value="diesel">Diesel</option>
                <option value="mobil">Mobil</option>
            </select>
        </div>
        <div class="fg">
            <label class="req">Allocated Qty (L)</label>
            <input type="number" name="allocated_qty" id="cc_alloc" step="0.01" min="0" required>
        </div>
        <div class="fg">
            <label>Carry Forward (L)</label>
            <input type="number" name="carry_forward_qty" id="cc_carry" step="0.01" min="0" value="0">
        </div>
        <div class="fg">
            <label class="req">Issued Date (Nepali BS)</label>
            <input type="text" id="cc_date_nep" name="issued_date_nep" class="ndp-input" placeholder="२०८२.०१.०१" autocomplete="off" required>
        </div>
        <div class="fg">
            <label class="req">Issued Date (English AD)</label>
            <input type="hidden" name="issued_date_eng" id="cc_date_eng_h">
            <div style="position:relative;">
                <div id="cc_date_eng_d" style="padding:8px 12px;border:1px solid #ced4da;border-radius:5px;min-height:36px;background:#fff;cursor:pointer;font-size:14px;" onclick="document.getElementById('cc_date_eng_n').showPicker && document.getElementById('cc_date_eng_n').showPicker()"></div>
                <input type="date" id="cc_date_eng_n" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:2;">
            </div>
        </div>
        <div class="fg">
            <label>Coupon No.</label>
            <input type="text" name="coupon_no" placeholder="Optional">
        </div>
        <div class="fg">
            <label>Pump Name</label>
            <select name="pump_name">
                <?php foreach ($pump_names as $p): ?>
                <option value="<?= $p ?>" <?= $p==='Om Sai Oil Pvt. Ltd.' ? 'selected':'' ?>><?= $p ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Expense Type</label>
            <select name="fuel_expense_type">
                <option value="">-- Select --</option>
                <?php foreach ($fuel_expense_types as $k=>$lbl): ?>
                <option value="<?= $k ?>"><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Total Available (calc)</label>
            <input type="text" id="cc_total_d" readonly style="background:#e9ecef;">
        </div>
    </div>
    <div class="fg">
        <label>Remarks</label>
        <textarea name="remarks" rows="2" placeholder="Any notes..."></textarea>
    </div>
    <div style="display:flex;gap:20px;margin-top:10px;">
        <label class="chk"><input type="checkbox" name="verified_with_pump"> Verified with pump</label>
        <label class="chk"><input type="checkbox" name="paid_status"> Paid</label>
    </div>
    <div id="cc_summary" class="info-box" style="display:none;">
        <h4>💰 Allocation Summary</h4>
        <div class="info-grid">
            <div class="info-item"><div class="il">Allocated</div><div class="iv" id="cc_s_alloc">0 L</div></div>
            <div class="info-item"><div class="il">Carry Fwd</div><div class="iv" id="cc_s_carry">0 L</div></div>
            <div class="info-item"><div class="il">Total</div><div class="iv" id="cc_s_total">0 L</div></div>
        </div>
    </div>
    <div style="margin-top:15px;">
        <button type="submit" class="btn btn-success">🎫 Create Coupon</button>
    </div>
</form>
</div>
</div>

<!-- ═══════════════ TAB 2: Add Distribution ═══════════════ -->
<div id="tab-dist" class="tab-content">
<div class="form-box">
<form method="POST">
    <input type="hidden" name="action" value="add_distribution">
    <div class="form-grid">
        <div class="fg" style="grid-column:1/-1;">
            <label class="req">Select Coupon (vehicles with remaining balance)</label>
            <select name="coupon_id" id="dist_coupon" required>
                <option value="">Select a coupon...</option>
                <?php foreach ($avail_coupons as $c): ?>
                <option value="<?= $c['coupon_id'] ?>"
                        data-fuel="<?= $c['fuel_type'] ?>"
                        data-exptype="<?= htmlspecialchars($c['fuel_expense_type'] ?? '') ?>"
                        data-allocated="<?= $c['total_available_qty'] ?>"
                        data-distributed="<?= $c['total_distributed'] ?>"
                        data-remaining="<?= $c['remaining_qty'] ?>">
                    <?= htmlspecialchars($c['vehicle_no']) ?> — <?= $c['month_nep'] ?> <?= $c['fiscal_year'] ?> — <?= strtoupper($c['fuel_type']) ?> (Rem: <?= number_format($c['remaining_qty'],2) ?> L)
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label class="req">Distribution Date (Nepali BS)</label>
            <input type="text" id="dist_date_nep" name="disburse_date_nep" class="ndp-input" placeholder="२०८२.०१.०१" autocomplete="off" required>
        </div>
        <div class="fg">
            <label class="req">Distribution Date (English AD)</label>
            <input type="hidden" name="disburse_date_eng" id="dist_date_eng_h">
            <div style="position:relative;">
                <div id="dist_date_eng_d" style="padding:8px 12px;border:1px solid #ced4da;border-radius:5px;min-height:36px;background:#fff;cursor:pointer;font-size:14px;"></div>
                <input type="date" id="dist_date_eng_n" style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:2;">
            </div>
        </div>
        <div class="fg">
            <label class="req">Quantity (L)</label>
            <input type="number" name="disburse_qty" id="dist_qty" step="0.01" min="0.01" required>
        </div>
        <div class="fg">
            <label>Rate/Liter (रू)</label>
            <input type="number" name="rate_per_liter" id="dist_rate" step="0.01" min="0" placeholder="Auto-fetched">
        </div>
        <div class="fg">
            <label>Total Amount</label>
            <input type="text" id="dist_amt" readonly style="background:#e9ecef;">
        </div>
        <div class="fg">
            <label class="req" style="color:#dc3545">Expense Type <span style="font-size:11px;color:#dc3545">*Required</span></label>
            <select name="fuel_expense_type" id="dist_exp_type" required>
                <option value="">-- Select Expense Type (Required) --</option>
                <?php foreach ($fuel_expense_types as $k=>$lbl): ?>
                <option value="<?= $k ?>"><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="fg">
        <label>Remarks</label>
        <textarea name="remarks" rows="2" placeholder="Distribution notes..."></textarea>
    </div>
    <label class="chk"><input type="checkbox" name="verified_flag"> Verified</label>
    <div id="dist_summary" class="info-box" style="display:none; margin-top:12px;">
        <h4>📊 Distribution Summary</h4>
        <div class="info-grid">
            <div class="info-item"><div class="il">Total Allocated</div><div class="iv" id="ds_alloc">-</div></div>
            <div class="info-item"><div class="il">Already Distributed</div><div class="iv" id="ds_already">-</div></div>
            <div class="info-item"><div class="il">Remaining Before</div><div class="iv" id="ds_rem">-</div></div>
            <div class="info-item"><div class="il">This Distribution</div><div class="iv" id="ds_this">-</div></div>
            <div class="info-item"><div class="il">Remaining After</div><div class="iv" id="ds_after">-</div></div>
        </div>
    </div>
    <div style="margin-top:15px;">
        <button type="submit" class="btn btn-success">📦 Add Distribution</button>
    </div>
</form>
</div>
</div>

<!-- ═══════════════ TAB 3: View / Search ═══════════════ -->
<div id="tab-view" class="tab-content">
<form method="GET" class="filter-bar">
    <div class="filter-grid">
        <div class="fg">
            <label>Fiscal Year</label>
            <select name="f_fiscal">
                <option value="">All</option>
                <option value="2082/83" <?= $f_fiscal==='2082/83'?'selected':'' ?>>2082/83</option>
                <option value="2083/84" <?= $f_fiscal==='2083/84'?'selected':'' ?>>2083/84</option>
                <option value="2084/85" <?= $f_fiscal==='2084/85'?'selected':'' ?>>2084/85</option>
            </select>
        </div>
        <div class="fg">
            <label>Month</label>
            <select name="f_month">
                <option value="">All Months</option>
                <?php foreach ($nepali_months as $m): ?>
                <option value="<?= $m ?>" <?= $f_month===$m?'selected':'' ?>><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Vehicle</label>
            <select name="f_vehicle">
                <option value="">All Vehicles</option>
                <?php foreach ($vehicles as $v): ?>
                <option value="<?= $v['vehicle_id'] ?>" <?= $f_vehicle==$v['vehicle_id']?'selected':'' ?>>
                    <?= htmlspecialchars($v['vehicle_no']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label>Fuel Type</label>
            <select name="f_fuel_type">
                <option value="">All Types</option>
                <option value="petrol" <?= $f_fuel_type==='petrol'?'selected':'' ?>>Petrol</option>
                <option value="diesel" <?= $f_fuel_type==='diesel'?'selected':'' ?>>Diesel</option>
                <option value="mobil"  <?= $f_fuel_type==='mobil' ?'selected':'' ?>>Mobil</option>
            </select>
        </div>
        <div class="fg">
            <label>Expense Type</label>
            <select name="f_exp_type">
                <option value="">All</option>
                <?php foreach ($fuel_expense_types as $k=>$lbl): ?>
                <option value="<?= $k ?>" <?= $f_exp_type===$k?'selected':'' ?>><?= $lbl ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg" style="display:flex;align-items:flex-end;gap:8px;">
            <button type="submit" class="btn btn-primary">🔍 Search</button>
            <a href="<?= $_SERVER['PHP_SELF'] ?>?f_fiscal=2082/83" class="btn btn-secondary">↺ Reset</a>
            <button onclick="window.print()" type="button" class="btn btn-info">🖨️ Print</button>
        </div>
    </div>
</form>

<p style="margin:0 0 10px 0; font-size:13px; color:#6c757d;">Showing <?= count($coupons) ?> coupon(s)</p>

<div class="tbl-wrap">
<table>
    <thead>
        <tr>
            <th>#</th>
            <th>Vehicle</th>
            <th>Period</th>
            <th>Fuel</th>
            <th>Expense Type</th>
            <th>Allocated (L)</th>
            <th>Distributed (L)</th>
            <th>Remaining (L)</th>
            <th>Pump</th>
            <th>Issued Date (BS)</th>
            <th>Remarks</th>
            <th>Status</th>
            <th class="no-print">Actions</th>
        </tr>
    </thead>
    <tbody>
    <?php if (empty($coupons)): ?>
        <tr><td colspan="13" style="text-align:center;padding:30px;color:#6c757d;">No coupons found for selected filters.</td></tr>
    <?php else: ?>
        <?php foreach ($coupons as $c): ?>
        <tr>
            <td><?= $c['coupon_id'] ?></td>
            <td><strong><?= htmlspecialchars($c['vehicle_no']) ?></strong><br><small style="color:#6c757d;"><?= ucfirst($c['vehicle_type']) ?></small></td>
            <td><?= $c['month_nep'] ?><br><small><?= $c['fiscal_year'] ?></small></td>
            <td><strong><?= strtoupper($c['fuel_type']) ?></strong></td>
            <td><?= $fuel_expense_types[$c['fuel_expense_type'] ?? ''] ?? ($c['fuel_expense_type'] ?? '-') ?></td>
            <td><?= number_format($c['total_available_qty'],2) ?></td>
            <td><?= number_format($c['total_distributed'],2) ?></td>
            <td><strong style="color:<?= $c['remaining_qty']<0?'#dc3545':'#28a745' ?>;"><?= number_format($c['remaining_qty'],2) ?></strong></td>
            <td><?= htmlspecialchars($c['pump_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['issued_date_nep'] ?? '-') ?></td>
            <td><?= htmlspecialchars($c['remarks'] ?? '-') ?></td>
            <td>
                <span class="badge <?= $c['verified_with_pump']?'badge-v':'badge-u' ?>"><?= $c['verified_with_pump']?'Verified':'Unverified' ?></span><br>
                <span class="badge <?= $c['paid_status']?'badge-p':'badge-np' ?>"><?= $c['paid_status']?'Paid':'Unpaid' ?></span>
            </td>
            <td class="no-print">
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete this coupon?')">
                    <input type="hidden" name="action" value="delete_coupon">
                    <input type="hidden" name="coupon_id" value="<?= $c['coupon_id'] ?>">
                    <button type="submit" class="btn btn-danger btn-sm">Del</button>
                </form>
            </td>
        </tr>
        <?php endforeach; ?>
        <!-- Totals row -->
        <tr style="background:#f0f0f0;font-weight:700;">
            <td colspan="5" style="text-align:right;">जम्मा (Total)</td>
            <td><?= number_format(array_sum(array_column($coupons,'total_available_qty')),2) ?></td>
            <td><?= number_format(array_sum(array_column($coupons,'total_distributed')),2) ?></td>
            <td><?= number_format(array_sum(array_column($coupons,'remaining_qty')),2) ?></td>
            <td colspan="5"></td>
        </tr>
    <?php endif; ?>
    </tbody>
</table>
</div>
</div><!-- /tab-view -->
</div><!-- /container -->

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js" type="text/javascript"></script>
<script>
// Tab switching
function switchTab(id, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    btn.classList.add('active');
}

document.addEventListener('DOMContentLoaded', function() {

    // ── Nepali datepicker helper ──
    function setupNepaliPicker(nepFieldId, engDisplayId, engNativeId, engHiddenId) {
        var nep    = document.getElementById(nepFieldId);
        var disp   = document.getElementById(engDisplayId);
        var nat    = document.getElementById(engNativeId);
        var hidden = document.getElementById(engHiddenId);

        function fillEng(dotVal) {
            hidden.value = dotVal;
            disp.textContent = dotVal;
            nat.value = dotVal.replace(/\./g, '-');
        }

        nep.NepaliDatePicker({
            dateFormat: 'YYYY.MM.DD',
            onDateSelect: function() {
                try {
                    var ad = NepaliFunctions.BS2AD(nep.value.trim(), 'YYYY.MM.DD', 'YYYY.MM.DD');
                    if (ad) fillEng(ad);
                } catch(e) {}
            }
        });

        nep.addEventListener('blur', function() {
            try {
                var ad = NepaliFunctions.BS2AD(nep.value.trim(), 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (ad) fillEng(ad);
            } catch(e) {}
        });

        nat.addEventListener('change', function() {
            var dotVal = this.value.replace(/-/g, '.');
            fillEng(dotVal);
            try {
                var bs = NepaliFunctions.AD2BS(dotVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (bs) nep.value = bs;
            } catch(e) {}
        });
    }

    setupNepaliPicker('cc_date_nep',   'cc_date_eng_d',   'cc_date_eng_n',   'cc_date_eng_h');
    setupNepaliPicker('dist_date_nep', 'dist_date_eng_d', 'dist_date_eng_n', 'dist_date_eng_h');

    // ── Create coupon calc ──
    function calcCouponTotal() {
        var a = parseFloat(document.getElementById('cc_alloc').value) || 0;
        var c = parseFloat(document.getElementById('cc_carry').value) || 0;
        var t = a + c;
        document.getElementById('cc_total_d').value = t.toFixed(2) + ' L';
        if (a > 0 || c > 0) {
            document.getElementById('cc_s_alloc').textContent = a.toFixed(2) + ' L';
            document.getElementById('cc_s_carry').textContent = c.toFixed(2) + ' L';
            document.getElementById('cc_s_total').textContent = t.toFixed(2) + ' L';
            document.getElementById('cc_summary').style.display = 'block';
        }
    }
    document.getElementById('cc_alloc').addEventListener('input', calcCouponTotal);
    document.getElementById('cc_carry').addEventListener('input', calcCouponTotal);

    // ── Distribution calc ──
    var couponSel  = document.getElementById('dist_coupon');
    var distQty    = document.getElementById('dist_qty');
    var distRate   = document.getElementById('dist_rate');
    var distEngNat = document.getElementById('dist_date_eng_n');

    couponSel.addEventListener('change', function() {
        var opt = this.options[this.selectedIndex];
        if (!opt.value) { document.getElementById('dist_summary').style.display='none'; return; }
        var allocated = parseFloat(opt.dataset.allocated)||0;
        var distd     = parseFloat(opt.dataset.distributed)||0;
        var rem       = parseFloat(opt.dataset.remaining)||0;
        document.getElementById('ds_alloc').textContent  = allocated.toFixed(2)+' L';
        document.getElementById('ds_already').textContent= distd.toFixed(2)+' L';
        document.getElementById('ds_rem').textContent    = rem.toFixed(2)+' L';
        document.getElementById('dist_summary').style.display='block';
        // Auto-fill expense type from coupon
        var expSel = document.getElementById('dist_exp_type');
        if (expSel && opt.dataset.exptype) {
            expSel.value = opt.dataset.exptype;
        }
        fetchPrice(opt.dataset.fuel, distEngNat.value);
        calcDistSummary();
    });

    distEngNat.addEventListener('change', function() {
        var opt = couponSel.options[couponSel.selectedIndex];
        if (opt && opt.value) fetchPrice(opt.dataset.fuel, this.value);
    });

    function fetchPrice(fuelType, date) {
        if (!fuelType || !date) return;
        fetch('/deno2/modules/vehicles/get_fuel_price.php?fuel='+fuelType+'&date='+date)
            .then(r => r.json()).then(d => {
                if (d.success && d.price > 0) {
                    distRate.value = d.price;
                    calcDistAmt();
                }
            }).catch(()=>{});
    }

    function calcDistAmt() {
        var q = parseFloat(distQty.value)||0;
        var r = parseFloat(distRate.value)||0;
        document.getElementById('dist_amt').value = 'रू ' + (q*r).toFixed(2);
        calcDistSummary();
    }

    function calcDistSummary() {
        var opt = couponSel.options[couponSel.selectedIndex];
        if (!opt || !opt.value) return;
        var rem   = parseFloat(opt.dataset.remaining)||0;
        var thisQ = parseFloat(distQty.value)||0;
        var after = rem - thisQ;
        document.getElementById('ds_this').textContent  = thisQ.toFixed(2)+' L';
        document.getElementById('ds_after').textContent = after.toFixed(2)+' L';
        document.getElementById('ds_after').style.color = after < 0 ? '#dc3545' : '#28a745';
    }

    distQty.addEventListener('input', calcDistAmt);
    distRate.addEventListener('input', calcDistAmt);

    // Open View tab if filters applied
    <?php if ($f_vehicle || $f_month || $f_fuel_type || $f_exp_type): ?>
    document.querySelectorAll('.tab')[2].click();
    <?php endif; ?>
});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
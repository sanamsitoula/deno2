<?php
// fuel_price_history.php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message   = null;
$success_message = null;
$logged_user_id  = $_SESSION['user_id'] ?? 1;

$nepali_months = [
    'Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
    'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'
];

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        $action = $_POST['action'] ?? 'create';

        if ($action === 'create') {
            $required = ['fiscal_year','month_nep','fuel_type',
                         'effective_from_date_nep','effective_from_date_eng','rate_per_liter'];
            foreach ($required as $f) {
                if (empty($_POST[$f])) throw new Exception("Field '{$f}' is required");
            }

            // Deactivate previous active price for same fuel type if marking as active
            if (!empty($_POST['is_active'])) {
                $conn->prepare("
                    UPDATE fuel_price_history
                    SET is_active = FALSE,
                        effective_to_date_eng = :to_eng,
                        updated_at = CURRENT_TIMESTAMP
                    WHERE fuel_type = :fuel_type
                      AND is_active = TRUE
                      AND deleted_at IS NULL
                      AND effective_to_date_eng IS NULL
                ")->execute([
                    ':fuel_type' => $_POST['fuel_type'],
                    ':to_eng'    => $_POST['effective_from_date_eng'],
                ]);
            }

            $conn->prepare("
                INSERT INTO fuel_price_history (
                    fiscal_year, month_nep, fuel_type,
                    effective_from_date_nep, effective_from_date_eng,
                    effective_to_date_nep,   effective_to_date_eng,
                    rate_per_liter, source, notification_no,
                    is_active, remarks, created_by
                ) VALUES (
                    :fiscal_year, :month_nep, :fuel_type,
                    :from_nep, :from_eng,
                    :to_nep,   :to_eng,
                    :rate, :source, :notif,
                    :is_active, :remarks, :created_by
                )
            ")->execute([
                ':fiscal_year' => $_POST['fiscal_year'],
                ':month_nep'   => $_POST['month_nep'],
                ':fuel_type'   => $_POST['fuel_type'],
                ':from_nep'    => $_POST['effective_from_date_nep'],
                ':from_eng'    => $_POST['effective_from_date_eng'],
                ':to_nep'      => $_POST['effective_to_date_nep']  ?: null,
                ':to_eng'      => $_POST['effective_to_date_eng']  ?: null,
                ':rate'        => $_POST['rate_per_liter'],
                ':source'      => $_POST['source']           ?? null,
                ':notif'       => $_POST['notification_no']  ?? null,
                ':is_active'   => isset($_POST['is_active'])  ? 1 : 0,
                ':remarks'     => $_POST['remarks']           ?? null,
                ':created_by'  => $logged_user_id,
            ]);
            $success_message = "Fuel price record created successfully!";

        } elseif ($action === 'update') {
            $conn->prepare("
                UPDATE fuel_price_history SET
                    fiscal_year             = :fiscal_year,
                    month_nep               = :month_nep,
                    fuel_type               = :fuel_type,
                    effective_from_date_nep = :from_nep,
                    effective_from_date_eng = :from_eng,
                    effective_to_date_nep   = :to_nep,
                    effective_to_date_eng   = :to_eng,
                    rate_per_liter          = :rate,
                    source                  = :source,
                    notification_no         = :notif,
                    is_active               = :is_active,
                    remarks                 = :remarks,
                    updated_by              = :updated_by,
                    updated_at              = CURRENT_TIMESTAMP
                WHERE price_id = :price_id AND deleted_at IS NULL
            ")->execute([
                ':price_id'    => $_POST['price_id'],
                ':fiscal_year' => $_POST['fiscal_year'],
                ':month_nep'   => $_POST['month_nep'],
                ':fuel_type'   => $_POST['fuel_type'],
                ':from_nep'    => $_POST['effective_from_date_nep'],
                ':from_eng'    => $_POST['effective_from_date_eng'],
                ':to_nep'      => $_POST['effective_to_date_nep']  ?: null,
                ':to_eng'      => $_POST['effective_to_date_eng']  ?: null,
                ':rate'        => $_POST['rate_per_liter'],
                ':source'      => $_POST['source']          ?? null,
                ':notif'       => $_POST['notification_no'] ?? null,
                ':is_active'   => isset($_POST['is_active']) ? 1 : 0,
                ':remarks'     => $_POST['remarks']          ?? null,
                ':updated_by'  => $logged_user_id,
            ]);
            $success_message = "Fuel price #" . $_POST['price_id'] . " updated successfully!";

        } elseif ($action === 'delete') {
            $conn->prepare("UPDATE fuel_price_history SET deleted_at = CURRENT_TIMESTAMP WHERE price_id = :id")
                 ->execute([':id' => $_POST['price_id']]);
            $success_message = "Fuel price deleted.";
        }

        $conn->commit();
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// ── Load edit record ──────────────────────────────────────────
$edit_record = null;
if (!empty($_GET['edit_id'])) {
    $s = $conn->prepare("SELECT * FROM fuel_price_history WHERE price_id=:id AND deleted_at IS NULL");
    $s->execute([':id' => $_GET['edit_id']]);
    $edit_record = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Price list (paginated) ────────────────────────────────────
$price_page     = max(1, intval($_GET['pp'] ?? 1));
$price_per_page = 15;
$price_search   = trim($_GET['ps'] ?? '');
$price_offset   = ($price_page - 1) * $price_per_page;

$price_where  = "WHERE fph.deleted_at IS NULL";
$price_params = [];
if ($price_search !== '') {
    $price_where .= " AND (fph.fuel_type ILIKE :ps OR fph.month_nep ILIKE :ps OR fph.source ILIKE :ps OR fph.notification_no ILIKE :ps OR fph.effective_from_date_nep ILIKE :ps)";
    $price_params[':ps'] = '%' . $price_search . '%';
}

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM fuel_price_history fph $price_where");
$count_stmt->execute($price_params);
$price_total = $count_stmt->fetchColumn();
$price_pages = ceil($price_total / $price_per_page);

$list_stmt = $conn->prepare("
    SELECT fph.*, u.username AS created_by_username
    FROM fuel_price_history fph
    LEFT JOIN users u ON fph.created_by = u.id
    $price_where
    ORDER BY fph.effective_from_date_eng DESC, fph.created_at DESC
    LIMIT $price_per_page OFFSET $price_offset
");
$list_stmt->execute($price_params);
$prices = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

// Active price rows for timeline and JS
$all_active_prices = $conn->query("
    SELECT fuel_type, rate_per_liter, effective_from_date_eng, effective_to_date_eng, effective_from_date_nep, month_nep
    FROM fuel_price_history
    WHERE is_active = TRUE AND deleted_at IS NULL
    ORDER BY fuel_type, effective_from_date_eng ASC
")->fetchAll(PDO::FETCH_ASSOC);

// Group by fuel
$ph_by_fuel = [];
foreach ($all_active_prices as $p) {
    $ph_by_fuel[$p['fuel_type']][] = $p;
}

$active_tab = !empty($_GET['edit_id']) ? 'tab-form' : (!empty($_GET['tab']) ? $_GET['tab'] : 'tab-list');
?>
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
body { font-family:'Segoe UI',sans-serif; background:#f5f7fa; }
.container { max-width:1400px; margin:0 auto; padding:20px; }
.page-title { font-size:26px; font-weight:700; color:#333; margin-bottom:5px; }
.page-subtitle { color:#6c757d; font-size:14px; margin-bottom:20px; }

.alert { padding:14px 18px; border-radius:8px; margin-bottom:16px; font-weight:500; }
.alert-success { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
.alert-danger   { background:#f8d7da; color:#721c24; border:1px solid #f5c6cb; }

/* Today's price panel */
.today-panel {
    background: linear-gradient(135deg, #1e3c72, #2a5298);
    border-radius:12px; padding:20px; margin-bottom:24px; color:#fff;
    box-shadow:0 6px 20px rgba(30,60,114,.35);
}
.today-panel .panel-title { font-size:14px; font-weight:700; opacity:.85; margin-bottom:4px; }
.today-panel .today-date  { font-size:21px; font-weight:700; margin-bottom:14px; }
.price-cards { display:grid; grid-template-columns:repeat(auto-fit,minmax(190px,1fr)); gap:12px; }
.pc {
    background:rgba(255,255,255,.15); backdrop-filter:blur(8px);
    border-radius:10px; padding:16px; text-align:center; border:1px solid rgba(255,255,255,.2);
    transition:transform .2s;
}
.pc:hover { transform:translateY(-2px); background:rgba(255,255,255,.22); }
.pc .fuel-lbl   { font-size:13px; text-transform:uppercase; letter-spacing:1px; opacity:.85; margin-bottom:4px; }
.pc .fuel-rate  { font-size:34px; font-weight:800; margin:4px 0; }
.pc .fuel-since { font-size:11px; opacity:.7; }
.pc .fuel-range { font-size:11px; opacity:.7; margin-top:2px; }

/* Tabs */
.tabs { display:flex; gap:4px; border-bottom:2px solid #dee2e6; margin-bottom:22px; }
.tab  { padding:10px 20px; background:none; border:none; color:#6c757d; font-size:14px; font-weight:600;
        cursor:pointer; border-bottom:3px solid transparent; transition:all .2s; border-radius:6px 6px 0 0; }
.tab.active  { color:#007bff; border-bottom-color:#007bff; background:#f0f7ff; }
.tab:hover:not(.active) { background:#f8f9fa; }
.tab-content { display:none; }
.tab-content.active { display:block; }

/* Form */
.form-box { background:#fff; border-radius:10px; padding:24px; box-shadow:0 2px 10px rgba(0,0,0,.08); margin-bottom:20px; }
.section-title { font-size:16px; font-weight:700; color:#333; padding-bottom:10px; border-bottom:2px solid #e9ecef; margin-bottom:18px; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(240px,1fr)); gap:18px; margin-bottom:18px; }
.fg { display:flex; flex-direction:column; }
.fg label { font-size:13px; font-weight:600; color:#495057; margin-bottom:6px; }
.req::after { content:' *'; color:#dc3545; }
input,select,textarea {
    padding:9px 13px; border:1px solid #ced4da; border-radius:6px; font-size:14px;
    width:100%; box-sizing:border-box; font-family:inherit; transition:border-color .2s;
}
input:focus,select:focus,textarea:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 3px rgba(0,123,255,.12); }
.ndp-input { font-family:inherit; }
textarea { resize:vertical; min-height:60px; }
small.hint { color:#6c757d; font-size:11px; margin-top:4px; }
.chk-row { display:flex; align-items:center; gap:10px; margin-top:6px; }
.chk-row input { width:18px; height:18px; cursor:pointer; }
.form-actions { display:flex; gap:10px; margin-top:20px; padding-top:16px; border-top:1px solid #e9ecef; flex-wrap:wrap; }

/* Buttons */
.btn { padding:9px 18px; border:none; border-radius:7px; font-size:14px; font-weight:600; cursor:pointer;
       display:inline-flex; align-items:center; gap:6px; text-decoration:none; transition:all .2s; }
.btn:hover { filter:brightness(.9); transform:translateY(-1px); }
.btn-primary   { background:#007bff; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-warning   { background:#ffc107; color:#212529; }
.btn-danger    { background:#dc3545; color:#fff; }
.btn-info      { background:#17a2b8; color:#fff; }
.btn-sm { padding:5px 10px; font-size:12px; }

/* Table */
.tbl-wrap { background:#fff; border-radius:10px; overflow-x:auto; box-shadow:0 2px 8px rgba(0,0,0,.08); }
.tbl-header { padding:16px 18px; display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; border-bottom:1px solid #e9ecef; }
.tbl-header h3 { margin:0; font-size:15px; color:#333; }
.search-bar { display:flex; gap:8px; align-items:center; }
.search-bar input { width:230px; padding:7px 12px; font-size:13px; }
table { width:100%; border-collapse:collapse; font-size:13px; }
th { background:#f8f9fa; padding:11px 10px; text-align:left; font-weight:700; color:#495057;
     border-bottom:2px solid #dee2e6; white-space:nowrap; font-size:11px; text-transform:uppercase; }
td { padding:11px 10px; border-bottom:1px solid #f0f0f0; vertical-align:middle; }
tr:hover td { background:#f8f9fa; }
.badge { display:inline-block; padding:3px 9px; border-radius:10px; font-size:11px; font-weight:600; }
.badge-active   { background:#d4edda; color:#155724; }
.badge-inactive { background:#f8d7da; color:#721c24; }
.badge-petrol   { background:#fff3cd; color:#856404; }
.badge-diesel   { background:#cfe2ff; color:#084298; }
.badge-mobil    { background:#e2d9f3; color:#5a3d8a; }

/* Pagination */
.pagination { display:flex; gap:5px; padding:14px 18px; align-items:center; flex-wrap:wrap; }
.pg-btn { padding:5px 11px; border:1px solid #dee2e6; background:#fff; border-radius:6px; font-size:13px;
          cursor:pointer; text-decoration:none; color:#333; transition:all .2s; }
.pg-btn:hover  { background:#e9ecef; }
.pg-btn.active { background:#007bff; color:#fff; border-color:#007bff; }
.pg-info { color:#6c757d; font-size:13px; margin-left:8px; }

/* Price timeline */
.ph-section { background:#fff; border-radius:8px; padding:16px; margin-bottom:12px; border-left:4px solid #007bff; box-shadow:0 1px 4px rgba(0,0,0,.06); }
.ph-section h4 { margin:0 0 10px 0; color:#004085; font-size:14px; }
.ph-timeline { display:flex; gap:8px; flex-wrap:wrap; }
.ph-item { background:#f0f7ff; border:1px solid #b8daff; border-radius:6px; padding:8px 12px; font-size:12px; text-align:center; min-width:130px; }
.ph-item .phi-rate { font-size:17px; font-weight:700; color:#004085; }
.ph-item .phi-from { color:#333; font-weight:600; }
.ph-item .phi-to   { color:#6c757d; }
.ph-item.current   { background:#d4edda; border-color:#4caf50; }
.ph-item.current .phi-rate { color:#155724; }
</style>

<div class="container">
<h1 class="page-title">💰 Fuel Price History</h1>
<p class="page-subtitle">Manage fuel price records. Prices are automatically applied to distributions based on the date range.</p>

<?php if ($error_message): ?>
    <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>
<?php if ($success_message): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
<?php endif; ?>

<!-- ── Today's Price Panel ── -->
<div class="today-panel">
    <div class="panel-title">📅 Today's Date &amp; Current Fuel Prices</div>
    <div class="today-date" id="today-nep-date">Loading...</div>
    <div class="price-cards" id="today-price-cards">
        <?php foreach (['petrol','diesel','mobil'] as $ftype): ?>
        <div class="pc" id="pc-<?= $ftype ?>">
            <div class="fuel-lbl"><?= strtoupper($ftype) ?></div>
            <div class="fuel-rate" id="rate-<?= $ftype ?>">—</div>
            <div class="fuel-since" id="since-<?= $ftype ?>"></div>
            <div class="fuel-range" id="range-<?= $ftype ?>"></div>
        </div>
        <?php endforeach; ?>
    </div>
</div>

<!-- ── Price Timeline by Fuel Type ── -->
<?php if (!empty($ph_by_fuel)): ?>
<div style="margin-bottom:20px;">
    <h3 style="margin:0 0 12px 0;font-size:16px;color:#333;">📊 Active Price Timeline by Fuel Type</h3>
    <?php foreach ($ph_by_fuel as $ftype => $rows): ?>
    <div class="ph-section">
        <h4><?= strtoupper($ftype) ?></h4>
        <div class="ph-timeline">
            <?php foreach ($rows as $r):
                $isCurrent = empty($r['effective_to_date_eng']);
            ?>
            <div class="ph-item <?= $isCurrent?'current':'' ?>">
                <div class="phi-rate">रू <?= number_format($r['rate_per_liter'],2) ?></div>
                <div class="phi-from">From: <?= htmlspecialchars($r['effective_from_date_nep']) ?></div>
                <div class="phi-to"><?= $isCurrent ? '✅ Current' : 'To: '.htmlspecialchars($r['effective_to_date_eng']) ?></div>
            </div>
            <?php endforeach; ?>
        </div>
    </div>
    <?php endforeach; ?>
</div>
<?php endif; ?>

<!-- ── Tabs ── -->
<div class="tabs">
    <button class="tab <?= $active_tab==='tab-form'?'active':'' ?>" data-tab="tab-form" onclick="switchTab('tab-form',this)">
        <?= $edit_record ? '✏️ Edit Price #'.$edit_record['price_id'] : '➕ Add New Price' ?>
    </button>
    <button class="tab <?= $active_tab==='tab-list'?'active':'' ?>" data-tab="tab-list" onclick="switchTab('tab-list',this)">📋 Price History</button>
</div>

<!-- ══════════════════════════════
     TAB: ADD / EDIT FORM
══════════════════════════════ -->
<div id="tab-form" class="tab-content <?= $active_tab==='tab-form'?'active':'' ?>">
<div class="form-box">
<?php if ($edit_record): ?>
<h3 style="margin:0 0 15px 0;color:#333;">✏️ Editing Price Record #<?= $edit_record['price_id'] ?></h3>
<?php else: ?>
<h3 style="margin:0 0 15px 0;color:#333;">➕ Add New Fuel Price</h3>
<?php endif; ?>
<form method="POST">
    <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
    <?php if ($edit_record): ?>
    <input type="hidden" name="price_id" value="<?= $edit_record['price_id'] ?>">
    <?php endif; ?>

    <div class="section-title">📌 Price Information</div>
    <div class="form-grid">
        <div class="fg">
            <label class="req">Fiscal Year</label>
            <select name="fiscal_year" required>
                <?php foreach (['2082/83','2083/84','2084/85','2081/82','2080/81'] as $fy): ?>
                <option value="<?= $fy ?>" <?= ($edit_record && $edit_record['fiscal_year']===$fy)||(!$edit_record&&$fy==='2082/83')?'selected':'' ?>>
                    <?= $fy ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label class="req">Nepali Month</label>
            <select name="month_nep" required>
                <option value="">Select Month</option>
                <?php foreach ($nepali_months as $m): ?>
                <option value="<?= $m ?>" <?= ($edit_record && $edit_record['month_nep']===$m)?'selected':'' ?>><?= $m ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="fg">
            <label class="req">Fuel Type</label>
            <select name="fuel_type" required>
                <option value="">Select Fuel</option>
                <option value="petrol" <?= ($edit_record && $edit_record['fuel_type']==='petrol')?'selected':'' ?>>Petrol</option>
                <option value="diesel" <?= ($edit_record && $edit_record['fuel_type']==='diesel')?'selected':'' ?>>Diesel</option>
                <option value="mobil"  <?= ($edit_record && $edit_record['fuel_type']==='mobil' )?'selected':'' ?>>Mobil</option>
            </select>
        </div>
        <div class="fg">
            <label class="req">Rate per Liter (रू)</label>
            <input type="number" step="0.01" name="rate_per_liter" placeholder="165.00"
                   value="<?= htmlspecialchars($edit_record['rate_per_liter'] ?? '') ?>" required>
        </div>
    </div>

    <div class="section-title">📅 Effective Period (Nepali BS Calendar)</div>
    <div class="form-grid">
        <div class="fg">
            <label class="req">Effective From (Nepali BS)</label>
            <input type="text" name="effective_from_date_nep" id="from_date_nep"
                   class="ndp-input" placeholder="२०८२.०५.०१"
                   value="<?= htmlspecialchars($edit_record['effective_from_date_nep'] ?? '') ?>" required>
        </div>
        <div class="fg">
            <label class="req">Effective From (English AD)</label>
            <input type="hidden" name="effective_from_date_eng" id="from_date_eng_h"
                   value="<?= htmlspecialchars($edit_record['effective_from_date_eng'] ?? '') ?>">
            <div style="position:relative;">
                <div id="from_date_eng_d" style="padding:9px 13px;border:1px solid #ced4da;border-radius:6px;min-height:38px;background:#fff;cursor:pointer;font-size:14px;">
                    <?= htmlspecialchars($edit_record['effective_from_date_eng'] ?? '') ?>
                </div>
                <input type="date" id="from_date_eng_n"
                       value="<?= !empty($edit_record['effective_from_date_eng'])?$edit_record['effective_from_date_eng']:'' ?>"
                       style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:2;">
            </div>
        </div>
        <div class="fg">
            <label>Effective To (Nepali BS) <span style="font-size:11px;color:#6c757d;">— leave blank if still active</span></label>
            <input type="text" name="effective_to_date_nep" id="to_date_nep"
                   class="ndp-input" placeholder="२०८२.०५.१५ (Optional)"
                   value="<?= htmlspecialchars($edit_record['effective_to_date_nep'] ?? '') ?>">
        </div>
        <div class="fg">
            <label>Effective To (English AD) <span style="font-size:11px;color:#6c757d;">— optional</span></label>
            <input type="hidden" name="effective_to_date_eng" id="to_date_eng_h"
                   value="<?= htmlspecialchars($edit_record['effective_to_date_eng'] ?? '') ?>">
            <div style="position:relative;">
                <div id="to_date_eng_d" style="padding:9px 13px;border:1px solid #ced4da;border-radius:6px;min-height:38px;background:#fff;cursor:pointer;font-size:14px;">
                    <?= htmlspecialchars($edit_record['effective_to_date_eng'] ?? '') ?>
                </div>
                <input type="date" id="to_date_eng_n"
                       value="<?= !empty($edit_record['effective_to_date_eng'])?$edit_record['effective_to_date_eng']:'' ?>"
                       style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:2;">
            </div>
        </div>
    </div>

    <div class="section-title">📋 Additional Information</div>
    <div class="form-grid">
        <div class="fg">
            <label>Source</label>
            <input type="text" name="source" placeholder="Nepal Oil Corporation"
                   value="<?= htmlspecialchars($edit_record['source'] ?? '') ?>">
        </div>
        <div class="fg">
            <label>Notification Number</label>
            <input type="text" name="notification_no" placeholder="NOC/2082/123"
                   value="<?= htmlspecialchars($edit_record['notification_no'] ?? '') ?>">
        </div>
        <div class="fg">
            <label>Status</label>
            <div class="chk-row" style="margin-top:10px;">
                <input type="checkbox" name="is_active" id="is_active"
                       <?= (!$edit_record || $edit_record['is_active']) ? 'checked' : '' ?>>
                <label for="is_active" style="margin:0;font-weight:400;">Active (Current Price)</label>
            </div>
            <small class="hint">⚠️ Checking this will close the previous active price for this fuel type.</small>
        </div>
        <div class="fg" style="grid-column:1/-1;">
            <label>Remarks</label>
            <textarea name="remarks" rows="2"><?= htmlspecialchars($edit_record['remarks'] ?? '') ?></textarea>
        </div>
    </div>

    <div class="form-actions">
        <?php if ($edit_record): ?>
        <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">↩ Cancel</a>
        <?php endif; ?>
        <button type="submit" class="btn btn-primary">
            <?= $edit_record ? '💾 Update Price' : '✅ Add Price' ?>
        </button>
    </div>
</form>
</div>
</div>

<!-- ══════════════════════════════
     TAB: PRICE HISTORY LIST
══════════════════════════════ -->
<div id="tab-list" class="tab-content <?= $active_tab==='tab-list'?'active':'' ?>">
<div class="tbl-wrap">
    <div class="tbl-header">
        <h3>📋 Price Records (<?= $price_total ?> total)</h3>
        <form method="GET" class="search-bar" style="margin:0;">
            <input type="hidden" name="tab" value="tab-list">
            <input type="text" name="ps" placeholder="Search fuel type, month, source..." value="<?= htmlspecialchars($price_search) ?>">
            <button type="submit" class="btn btn-info btn-sm">🔍 Search</button>
            <?php if ($price_search): ?>
            <a href="?tab=tab-list" class="btn btn-secondary btn-sm">✕ Clear</a>
            <?php endif; ?>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>ID</th><th>FY</th><th>Month</th><th>Fuel</th><th>Rate/L (रू)</th>
                <th>From (BS)</th><th>From (AD)</th><th>To (AD)</th>
                <th>Source</th><th>Notif No.</th><th>Status</th><th>Created By</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($prices)): ?>
            <tr><td colspan="13" style="text-align:center;padding:40px;color:#6c757d;">No price records found.</td></tr>
        <?php else: foreach ($prices as $p): ?>
        <tr>
            <td><?= $p['price_id'] ?></td>
            <td><?= htmlspecialchars($p['fiscal_year']) ?></td>
            <td><?= htmlspecialchars($p['month_nep'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $p['fuel_type'] ?>"><?= strtoupper($p['fuel_type']) ?></span></td>
            <td><strong>रू <?= number_format($p['rate_per_liter'],2) ?></strong></td>
            <td><?= htmlspecialchars($p['effective_from_date_nep']) ?></td>
            <td><?= htmlspecialchars($p['effective_from_date_eng']) ?></td>
            <td>
                <?php if ($p['effective_to_date_eng']): ?>
                    <?= htmlspecialchars($p['effective_to_date_eng']) ?>
                <?php else: ?>
                    <span style="color:#28a745;font-weight:600;">Ongoing</span>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($p['source'] ?? '—') ?></td>
            <td><?= htmlspecialchars($p['notification_no'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $p['is_active']?'active':'inactive' ?>"><?= $p['is_active'] ? '✓ Active' : '✗ Inactive' ?></span></td>
            <td><?= htmlspecialchars($p['created_by_username'] ?? '—') ?></td>
            <td style="white-space:nowrap;">
                <a href="?edit_id=<?= $p['price_id'] ?>" class="btn btn-warning btn-sm">✏️ Edit</a>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete price #<?= $p['price_id'] ?>?')">
                    <input type="hidden" name="action"   value="delete">
                    <input type="hidden" name="price_id" value="<?= $p['price_id'] ?>">
                    <button class="btn btn-danger btn-sm">🗑️</button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <!-- Pagination -->
    <?php if ($price_pages > 1): ?>
    <div class="pagination">
        <?php
        $pbase = '?tab=tab-list' . ($price_search ? '&ps='.urlencode($price_search) : '');
        if ($price_page > 1): ?>
        <a href="<?= $pbase ?>&pp=<?= $price_page-1 ?>" class="pg-btn">‹ Prev</a>
        <?php endif; ?>
        <?php for ($pg=1; $pg<=$price_pages; $pg++): ?>
        <a href="<?= $pbase ?>&pp=<?= $pg ?>" class="pg-btn <?= $pg==$price_page?'active':'' ?>"><?= $pg ?></a>
        <?php endfor; ?>
        <?php if ($price_page < $price_pages): ?>
        <a href="<?= $pbase ?>&pp=<?= $price_page+1 ?>" class="pg-btn">Next ›</a>
        <?php endif; ?>
        <span class="pg-info">Page <?= $price_page ?> of <?= $price_pages ?> (<?= $price_total ?> records)</span>
    </div>
    <?php endif; ?>
</div>
</div>

</div><!-- /container -->

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script>
// ── Tab switching ──────────────────────────────────────────
function switchTab(id, btn) {
    document.querySelectorAll('.tab-content').forEach(t => t.classList.remove('active'));
    document.querySelectorAll('.tab').forEach(t => t.classList.remove('active'));
    document.getElementById(id).classList.add('active');
    if (btn) btn.classList.add('active');
}

// ── All active price history for JS lookup ────────────────
var allActivePrices = <?= json_encode($all_active_prices) ?>;

function getPriceForDateAndFuel(fuelType, dateStr) {
    if (!fuelType || !dateStr) return null;
    var best = null;
    for (var i = 0; i < allActivePrices.length; i++) {
        var p = allActivePrices[i];
        if (p.fuel_type !== fuelType) continue;
        var from = p.effective_from_date_eng;
        var to   = p.effective_to_date_eng || '9999-12-31';
        if (dateStr >= from && dateStr <= to) {
            if (!best || from > best.effective_from_date_eng) best = p;
        }
    }
    return best;
}

// ── Show today's date + current prices ─────────────────────
document.addEventListener('DOMContentLoaded', function() {
    var todayAD  = new Date().toISOString().slice(0,10); // YYYY-MM-DD
    var todayDot = todayAD.replace(/-/g,'.');

    // Convert today to Nepali BS
    var nepDate = '';
    try {
        nepDate = NepaliFunctions.AD2BS(todayDot, 'YYYY.MM.DD', 'YYYY.MM.DD');
    } catch(e) {}
    if (!nepDate) {
        try { nepDate = NepaliFunctions.AD2BS(todayAD, 'YYYY-MM-DD', 'YYYY.MM.DD'); } catch(e){}
    }

    var titleEl = document.getElementById('today-nep-date');
    if (titleEl) {
        titleEl.textContent = nepDate
            ? '🗓️ Today: ' + nepDate + ' BS  (' + todayAD + ' AD)'
            : '🗓️ Today: ' + todayAD + ' AD';
    }

    ['petrol','diesel','mobil'].forEach(function(ft) {
        var priceRow = getPriceForDateAndFuel(ft, todayAD);
        var rateEl   = document.getElementById('rate-'  + ft);
        var sinceEl  = document.getElementById('since-' + ft);
        var rangeEl  = document.getElementById('range-' + ft);
        if (priceRow) {
            if (rateEl)  rateEl.textContent  = 'रू ' + parseFloat(priceRow.rate_per_liter).toFixed(2);
            if (sinceEl) sinceEl.textContent = 'From: ' + priceRow.effective_from_date_nep;
            if (rangeEl) {
                rangeEl.textContent = priceRow.effective_to_date_eng
                    ? '→ ' + priceRow.effective_to_date_eng
                    : 'Ongoing';
            }
        } else {
            if (rateEl)  rateEl.textContent  = 'N/A';
            if (sinceEl) sinceEl.textContent = 'No price record';
            if (rangeEl) rangeEl.textContent = '';
        }
    });

    // ── Nepali datepicker helper ──────────────────────────
    function setupNepaliPicker(nepId, dispId, nativeId, hiddenId) {
        var nep    = document.getElementById(nepId);
        var disp   = document.getElementById(dispId);
        var nat    = document.getElementById(nativeId);
        var hidden = document.getElementById(hiddenId);
        if (!nep) return;

        function apply(dotVal) {
            if (hidden) hidden.value = dotVal;
            if (disp)   disp.textContent = dotVal;
            if (nat)    nat.value = dotVal.replace(/\./g,'-');
        }

        nep.NepaliDatePicker({
            dateFormat: 'YYYY.MM.DD',
            onDateSelect: function() {
                try {
                    var ad = NepaliFunctions.BS2AD(nep.value.trim(), 'YYYY.MM.DD', 'YYYY.MM.DD');
                    if (ad) apply(ad);
                } catch(e){}
            }
        });
        nep.addEventListener('blur', function() {
            try {
                var ad = NepaliFunctions.BS2AD(nep.value.trim(), 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (ad) apply(ad);
            } catch(e){}
        });
        if (nat) nat.addEventListener('change', function() {
            var dot = this.value.replace(/-/g,'.');
            apply(dot);
            try {
                var bs = NepaliFunctions.AD2BS(dot, 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (bs) nep.value = bs;
            } catch(e){}
        });
    }

    setupNepaliPicker('from_date_nep', 'from_date_eng_d', 'from_date_eng_n', 'from_date_eng_h');
    setupNepaliPicker('to_date_nep',   'to_date_eng_d',   'to_date_eng_n',   'to_date_eng_h');
});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
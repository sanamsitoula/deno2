<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message   = null;
$success_message = null;
$logged_user_id  = $_SESSION['user_id'] ?? 1;

/* ══════════════════════════════════════════════════
   Helper: derive month_nep from Nepali date string
   Supports:  2082.12.01  |  2082-12-01  |  2082/12/01
══════════════════════════════════════════════════ */
function get_month_nep_from_date(string $nep_date): ?string {
    $month_names = [
        1  => 'Baishakh', 2  => 'Jestha',  3  => 'Ashadh',   4  => 'Shrawan',
        5  => 'Bhadra',   6  => 'Ashwin',  7  => 'Kartik',   8  => 'Mangsir',
        9  => 'Poush',   10  => 'Magh',   11  => 'Falgun',  12  => 'Chaitra'
    ];
    $normalised = str_replace(['-', '/'], '.', trim($nep_date));
    $parts      = explode('.', $normalised);
    $month_num  = isset($parts[1]) ? (int)$parts[1] : 0;
    return $month_names[$month_num] ?? null;
}

// ── Handle form submissions ──────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        $action = $_POST['action'] ?? 'create';

        if ($action === 'create') {
            $required_fields = ['vehicle_id', 'log_date_nep', 'log_date_eng', 'start_meter', 'end_meter', 'fiscal_year'];
            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }
            if ((int)$_POST['end_meter'] < (int)$_POST['start_meter']) {
                throw new Exception("End meter reading cannot be less than start meter reading.");
            }
            $month_nep = get_month_nep_from_date($_POST['log_date_nep']);
            $stmt = $conn->prepare("
                INSERT INTO vehicle_daily_logs (
                    vehicle_id, driver_id, log_date_nep, log_date_eng,
                    from_location, to_location, start_meter, end_meter,
                    purpose, fuel_used_estimated, remarks, fiscal_year, month_nep, created_by
                ) VALUES (
                    :vehicle_id, :driver_id, :log_date_nep, :log_date_eng,
                    :from_location, :to_location, :start_meter, :end_meter,
                    :purpose, :fuel_used_estimated, :remarks, :fiscal_year, :month_nep, :created_by
                )
            ");
            $stmt->execute([
                ':vehicle_id'          => $_POST['vehicle_id'],
                ':driver_id'           => $_POST['driver_id'] ?: null,
                ':log_date_nep'        => $_POST['log_date_nep'],
                ':log_date_eng'        => $_POST['log_date_eng'],
                ':from_location'       => $_POST['from_location']      ?? null,
                ':to_location'         => $_POST['to_location']        ?? null,
                ':start_meter'         => (int)$_POST['start_meter'],
                ':end_meter'           => (int)$_POST['end_meter'],
                ':purpose'             => $_POST['purpose']            ?? null,
                ':fuel_used_estimated' => $_POST['fuel_used_estimated'] ?: null,
                ':remarks'             => $_POST['remarks']            ?? null,
                ':fiscal_year'         => $_POST['fiscal_year'],
                ':month_nep'           => $month_nep,
                ':created_by'          => $logged_user_id,
            ]);
            $success_message = "Vehicle log created successfully!";

        } elseif ($action === 'update') {
            if ((int)$_POST['end_meter'] < (int)$_POST['start_meter']) {
                throw new Exception("End meter reading cannot be less than start meter reading.");
            }
            $month_nep = get_month_nep_from_date($_POST['log_date_nep']);
            $stmt = $conn->prepare("
                UPDATE vehicle_daily_logs SET
                    vehicle_id            = :vehicle_id,
                    driver_id             = :driver_id,
                    log_date_nep          = :log_date_nep,
                    log_date_eng          = :log_date_eng,
                    from_location         = :from_location,
                    to_location           = :to_location,
                    start_meter           = :start_meter,
                    end_meter             = :end_meter,
                    purpose               = :purpose,
                    fuel_used_estimated   = :fuel_used_estimated,
                    remarks               = :remarks,
                    fiscal_year           = :fiscal_year,
                    month_nep             = :month_nep,
                    updated_by            = :updated_by,
                    updated_at            = CURRENT_TIMESTAMP
                WHERE log_id = :log_id
            ");
            $stmt->execute([
                ':log_id'              => $_POST['log_id'],
                ':vehicle_id'          => $_POST['vehicle_id'],
                ':driver_id'           => $_POST['driver_id'] ?: null,
                ':log_date_nep'        => $_POST['log_date_nep'],
                ':log_date_eng'        => $_POST['log_date_eng'],
                ':from_location'       => $_POST['from_location']      ?? null,
                ':to_location'         => $_POST['to_location']        ?? null,
                ':start_meter'         => (int)$_POST['start_meter'],
                ':end_meter'           => (int)$_POST['end_meter'],
                ':purpose'             => $_POST['purpose']            ?? null,
                ':fuel_used_estimated' => $_POST['fuel_used_estimated'] ?: null,
                ':remarks'             => $_POST['remarks']            ?? null,
                ':fiscal_year'         => $_POST['fiscal_year'],
                ':month_nep'           => $month_nep,
                ':updated_by'          => $logged_user_id,
            ]);
            $success_message = "Vehicle log updated successfully!";

        } elseif ($action === 'delete') {
            $stmt = $conn->prepare("UPDATE vehicle_daily_logs SET deleted_at = CURRENT_TIMESTAMP WHERE log_id = :log_id");
            $stmt->execute([':log_id' => $_POST['log_id']]);
            $success_message = "Vehicle log deleted successfully!";
        }

        $conn->commit();

    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// ── Dropdown data ────────────────────────────────────────────────────────────
$vehicles = $conn->query("
    SELECT vehicle_id, vehicle_no, vehicle_type, fuel_type
    FROM vehicles
    WHERE status = TRUE AND deleted_at IS NULL
    ORDER BY vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

$drivers = $conn->query("
    SELECT driver_id, driver_name, license_no
    FROM drivers
    WHERE status = TRUE AND deleted_at IS NULL
    ORDER BY driver_name
")->fetchAll(PDO::FETCH_ASSOC);

// Vehicle–driver assignments
$vehicle_assignments = [];
$assign_query = $conn->query("
    SELECT vda.vehicle_id, vda.driver_id, d.driver_name
    FROM vehicle_driver_assignments vda
    JOIN drivers d ON vda.driver_id = d.driver_id
    WHERE vda.active_flag = TRUE AND vda.deleted_at IS NULL
");
while ($row = $assign_query->fetch(PDO::FETCH_ASSOC)) {
    $vehicle_assignments[$row['vehicle_id']] = [
        'driver_id'   => $row['driver_id'],
        'driver_name' => $row['driver_name'],
    ];
}

// ── Fiscal-year lookup table for JS (live preview) ──────────────────────────
$fy_rows   = $conn->query("SELECT fiscal_name FROM fiscal_years ORDER BY start_date DESC LIMIT 10")->fetchAll(PDO::FETCH_COLUMN);
$fy_list   = $fy_rows ?: ['2082/83','2081/82','2080/81'];

// ── List filters ─────────────────────────────────────────────────────────────
$filter_fiscal  = $_GET['fiscal_year'] ?? '2082/83';
$filter_month   = $_GET['month_nep']   ?? '';
$filter_vehicle = $_GET['vehicle_id']  ?? '';
$filter_driver  = $_GET['driver_id']   ?? '';

$where_clause = "WHERE vdl.deleted_at IS NULL";
$params = [];
if ($filter_fiscal)  { $where_clause .= " AND vdl.fiscal_year = :fiscal_year"; $params[':fiscal_year'] = $filter_fiscal; }
if ($filter_month)   { $where_clause .= " AND vdl.month_nep   = :month_nep";   $params[':month_nep']   = $filter_month;  }
if ($filter_vehicle) { $where_clause .= " AND vdl.vehicle_id  = :vehicle_id";  $params[':vehicle_id']  = $filter_vehicle;}
if ($filter_driver)  { $where_clause .= " AND vdl.driver_id   = :driver_id";   $params[':driver_id']   = $filter_driver; }

$stmt = $conn->prepare("
    SELECT
        vdl.log_id, vdl.log_date_nep, vdl.log_date_eng,
        vdl.vehicle_id, v.vehicle_no, v.vehicle_type,
        vdl.driver_id, d.driver_name,
        vdl.start_meter, vdl.end_meter, vdl.total_km,
        vdl.fuel_used_estimated,
        vdl.from_location, vdl.to_location,
        vdl.purpose, vdl.remarks, vdl.month_nep, vdl.fiscal_year
    FROM vehicle_daily_logs vdl
    JOIN  vehicles v ON vdl.vehicle_id = v.vehicle_id
    LEFT JOIN drivers d ON vdl.driver_id = d.driver_id
    $where_clause
    ORDER BY vdl.log_date_eng DESC, vdl.log_id DESC
    LIMIT 100
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ── External CSS for Nepali datepicker ──────────────────────────────────── -->
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>

<style>
/* ─── Base ─────────────────────────────────────────────────────────────────── */
body { font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif; background: #f0f2f5; }
.container { max-width: 1800px; margin: 0 auto; padding: 20px; }

/* ─── Page header ────────────────────────────────────────────────────────── */
.page-header { margin-bottom: 24px; }
.page-title  { font-size: 26px; font-weight: 700; color: #1a202c; display: flex; align-items: center; gap: 10px; margin: 0; }

/* ─── Alerts ─────────────────────────────────────────────────────────────── */
.alert          { padding: 14px 18px; border-radius: 8px; margin-bottom: 18px; font-weight: 500; }
.alert-success  { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-error    { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }

/* ─── Cards ──────────────────────────────────────────────────────────────── */
.card { background: #fff; border-radius: 12px; padding: 24px; margin-bottom: 24px; box-shadow: 0 1px 6px rgba(0,0,0,.08); }
.card-title { font-size: 16px; font-weight: 700; color: #1a202c; margin: 0 0 20px 0; padding-bottom: 14px; border-bottom: 1px solid #e2e8f0; }

/* ─── Form elements ──────────────────────────────────────────────────────── */
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(240px, 1fr)); gap: 18px; margin-bottom: 18px; }
.form-group { display: flex; flex-direction: column; }
.form-label { font-weight: 600; color: #4a5568; margin-bottom: 6px; font-size: 13px; }
.required::after { content: ' *'; color: #e53e3e; }
.form-input, .form-select, .form-textarea {
    padding: 9px 13px; border: 1px solid #cbd5e0; border-radius: 6px;
    font-size: 14px; color: #2d3748; transition: border-color .2s, box-shadow .2s;
    background: #fff;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: #4299e1; box-shadow: 0 0 0 3px rgba(66,153,225,.15);
}
.form-input[readonly], .form-input:disabled {
    background: #f7fafc; color: #718096; cursor: not-allowed;
}
.form-textarea { resize: vertical; min-height: 76px; }
.form-hint { font-size: 11px; color: #718096; margin-top: 4px; }

/* Searchable select wrapper */
.select-search-wrap { position: relative; }
.select-search-input {
    padding: 9px 13px; border: 1px solid #cbd5e0; border-radius: 6px 6px 0 0;
    font-size: 14px; width: 100%; box-sizing: border-box; display: none;
    border-bottom: none;
}
.select-search-input:focus { outline: none; border-color: #4299e1; }
.select-search-input.visible { display: block; }
.select-with-search { border-radius: 0 0 6px 6px !important; }

/* Nepali datepicker input wrapper */
.nep-date-wrap { position: relative; }
.nep-date-wrap .form-input { padding-right: 36px; }
.nep-date-icon {
    position: absolute; right: 10px; top: 50%; transform: translateY(-50%);
    color: #718096; pointer-events: none; font-size: 16px;
}

/* ─── Distance badge ─────────────────────────────────────────────────────── */
.distance-badge {
    display: inline-block; background: #ebf8ff; border: 1px solid #90cdf4;
    border-radius: 6px; padding: 7px 12px; font-weight: 700; font-size: 15px;
    color: #2b6cb0; margin-top: 2px;
}
.distance-badge.error { background: #fff5f5; border-color: #fc8181; color: #c53030; }

/* ─── Info / summary box ─────────────────────────────────────────────────── */
.info-box { background: #ebf8ff; border-left: 4px solid #4299e1; padding: 14px 18px; border-radius: 6px; margin: 18px 0; }
.info-box h4 { margin: 0 0 10px 0; color: #2b6cb0; font-size: 14px; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(140px,1fr)); gap: 12px; }
.info-item { display: flex; flex-direction: column; }
.info-label { font-size: 11px; color: #718096; margin-bottom: 3px; }
.info-value { font-size: 17px; font-weight: 700; color: #1a202c; }

/* ─── Form actions ───────────────────────────────────────────────────────── */
.form-actions { display: flex; gap: 12px; margin-top: 20px; padding-top: 20px; border-top: 1px solid #e2e8f0; }

/* ─── Buttons ────────────────────────────────────────────────────────────── */
.btn {
    padding: 10px 20px; border: none; border-radius: 7px; font-size: 14px;
    font-weight: 600; cursor: pointer; transition: all .2s;
    display: inline-flex; align-items: center; gap: 7px;
    text-decoration: none; white-space: nowrap;
}
.btn-success { background: #38a169; color: #fff; } .btn-success:hover { background: #2f855a; }
.btn-primary { background: #3182ce; color: #fff; } .btn-primary:hover { background: #2b6cb0; }
.btn-warning { background: #d69e2e; color: #fff; } .btn-warning:hover { background: #b7791f; }
.btn-danger  { background: #e53e3e; color: #fff; } .btn-danger:hover  { background: #c53030; }
.btn-gray    { background: #718096; color: #fff; } .btn-gray:hover    { background: #4a5568; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

/* ─── Filter card ────────────────────────────────────────────────────────── */
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(190px,1fr)); gap: 14px; margin-bottom: 14px; }

/* ─── Data table ─────────────────────────────────────────────────────────── */
.data-table-container { background: #fff; border-radius: 12px; padding: 20px; box-shadow: 0 1px 6px rgba(0,0,0,.08); overflow-x: auto; }
.table-header { display: flex; justify-content: space-between; align-items: center; margin-bottom: 16px; flex-wrap: wrap; gap: 10px; }
.table-title  { font-size: 15px; font-weight: 700; color: #1a202c; }
.record-count { font-size: 13px; color: #718096; }
.data-table { width: 100%; border-collapse: collapse; min-width: 900px; }
.data-table th {
    background: #f7fafc; padding: 11px 12px; text-align: left;
    font-weight: 600; font-size: 12px; color: #4a5568; text-transform: uppercase;
    letter-spacing: .04em; border-bottom: 2px solid #e2e8f0; white-space: nowrap;
}
.data-table td { padding: 11px 12px; border-bottom: 1px solid #f0f4f8; font-size: 13px; }
.data-table tr:hover td { background: #f7fafc; }
.data-table .actions-cell { white-space: nowrap; }

/* Badges */
.badge { display: inline-block; border-radius: 4px; padding: 2px 7px; font-size: 11px; font-weight: 600; }
.badge-month { background: #ebf8ff; color: #2b6cb0; }
.badge-km    { background: #f0fff4; color: #276749; }
.badge-warn  { background: #fff5f5; color: #c53030; }

/* ─── Modal ──────────────────────────────────────────────────────────────── */
.modal-overlay {
    display: none; position: fixed; inset: 0;
    background: rgba(0,0,0,.45); z-index: 1000;
    align-items: center; justify-content: center;
    padding: 20px;
}
.modal-overlay.active { display: flex; }
.modal-box {
    background: #fff; border-radius: 14px; width: 100%; max-width: 860px;
    max-height: 90vh; overflow-y: auto;
    box-shadow: 0 10px 40px rgba(0,0,0,.25);
    animation: modalIn .2s ease;
}
@keyframes modalIn { from { opacity:0; transform: translateY(-20px); } to { opacity:1; transform: translateY(0); } }
.modal-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 18px 24px; border-bottom: 1px solid #e2e8f0; position: sticky; top: 0; background: #fff; z-index: 1;
}
.modal-header h3 { margin: 0; font-size: 17px; font-weight: 700; color: #1a202c; }
.modal-close {
    background: none; border: none; font-size: 22px; cursor: pointer;
    color: #718096; line-height: 1; padding: 2px 6px; border-radius: 4px;
}
.modal-close:hover { background: #f0f4f8; color: #1a202c; }
.modal-body { padding: 24px; }

/* Delete confirmation modal */
.modal-delete .modal-body { text-align: center; padding: 32px 24px; }
.modal-delete .del-icon { font-size: 48px; margin-bottom: 12px; }
.modal-delete h4 { font-size: 18px; color: #1a202c; margin: 0 0 8px 0; }
.modal-delete p  { color: #718096; margin: 0 0 24px 0; font-size: 14px; }
.modal-delete .del-actions { display: flex; gap: 12px; justify-content: center; }

/* ─── Fetch KM status ────────────────────────────────────────────────────── */
.km-hint { font-size: 11px; margin-top: 4px; padding: 3px 8px; border-radius: 4px; display: none; }
.km-hint.found    { background: #f0fff4; color: #276749; display: block; }
.km-hint.notfound { background: #fffff0; color: #744210; display: block; }
.km-hint.loading  { background: #ebf8ff; color: #2b6cb0; display: block; }
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📋 Vehicle Daily Log</h1>
    </div>

    <?php if ($error_message):   ?><div class="alert alert-error">❌ <?= htmlspecialchars($error_message) ?></div><?php endif; ?>
    <?php if ($success_message): ?><div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div><?php endif; ?>

    <!-- ════════════ CREATE FORM ════════════ -->
    <div class="card">
        <h2 class="card-title">➕ Add New Log Entry</h2>
        <form method="POST" id="logForm">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">

                <!-- Vehicle (searchable) -->
                <div class="form-group">
                    <label class="form-label required">Vehicle</label>
                    <div class="select-search-wrap" id="vehicle_wrap">
                        <input type="text" class="select-search-input" id="vehicle_search"
                               placeholder="🔍 Search vehicle…" autocomplete="off">
                        <select name="vehicle_id" id="vehicle_id" class="form-select" required>
                            <option value="">Select Vehicle</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>"
                                        data-fuel-type="<?= htmlspecialchars($v['fuel_type']) ?>"
                                        data-current-driver="<?= $vehicle_assignments[$v['vehicle_id']]['driver_id'] ?? '' ?>">
                                    <?= htmlspecialchars($v['vehicle_no']) ?>
                                    (<?= ucfirst($v['vehicle_type']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Driver (searchable) -->
                <div class="form-group">
                    <label class="form-label">Driver</label>
                    <div class="select-search-wrap" id="driver_wrap">
                        <input type="text" class="select-search-input" id="driver_search"
                               placeholder="🔍 Search driver…" autocomplete="off">
                        <select name="driver_id" id="driver_id" class="form-select">
                            <option value="">Select Driver</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= $d['driver_id'] ?>">
                                    <?= htmlspecialchars($d['driver_name']) ?>
                                    (<?= htmlspecialchars($d['license_no']) ?>)
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <span class="form-hint">Current driver auto-selected on vehicle pick</span>
                </div>

                <!-- Log Date Nepali -->
                <div class="form-group">
                    <label class="form-label required">Log Date (Nepali)</label>
                    <div class="nep-date-wrap">
                        <input type="text" name="log_date_nep" id="log_date_nep"
                               class="form-input" placeholder="Click to pick Nepali date" readonly required>
                        <span class="nep-date-icon">📅</span>
                    </div>
                    <span class="form-hint">
                        Month: <strong id="nep_month_preview" style="color:#2b6cb0">—</strong>
                    </span>
                </div>

                <!-- Log Date English (auto-populated) -->
                <div class="form-group">
                    <label class="form-label required">Log Date (English)</label>
                    <input type="date" name="log_date_eng" id="log_date_eng"
                           class="form-input" value="<?= date('Y-m-d') ?>" required>
                    <span class="form-hint">Auto-filled from Nepali date</span>
                </div>

                <!-- Start Meter -->
                <div class="form-group">
                    <label class="form-label required">Start Meter (KM)</label>
                    <input type="number" name="start_meter" id="start_meter"
                           class="form-input" step="1" min="0" required>
                    <div id="start_km_hint" class="km-hint"></div>
                </div>

                <!-- End Meter -->
                <div class="form-group">
                    <label class="form-label required">End Meter (KM)</label>
                    <input type="number" name="end_meter" id="end_meter"
                           class="form-input" step="1" min="0" required>
                </div>

                <!-- Distance (computed) -->
                <div class="form-group">
                    <label class="form-label">Distance Covered</label>
                    <div id="distance_display" class="distance-badge" style="display:none"></div>
                </div>

                <!-- Fuel Used -->
                <div class="form-group">
                    <label class="form-label">Fuel Used (Est. Litres)</label>
                    <input type="number" name="fuel_used_estimated" id="fuel_used_estimated"
                           class="form-input" step="0.01" min="0">
                </div>

                <!-- From -->
                <div class="form-group">
                    <label class="form-label">From Location</label>
                    <input type="text" name="from_location" class="form-input" placeholder="Starting location">
                </div>

                <!-- To -->
                <div class="form-group">
                    <label class="form-label">To Location</label>
                    <input type="text" name="to_location" class="form-input" placeholder="Destination">
                </div>

                <!-- Fiscal Year (read-only) -->
                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <input type="text" name="fiscal_year" id="fiscal_year"
                           class="form-input" value="2082/83" readonly>
                    <span class="form-hint">Auto-set from log date</span>
                </div>

            </div><!-- /form-grid -->

            <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                <div class="form-group">
                    <label class="form-label">Purpose</label>
                    <textarea name="purpose" class="form-textarea" placeholder="Purpose of trip…"></textarea>
                </div>
                <div class="form-group">
                    <label class="form-label">Remarks</label>
                    <textarea name="remarks" class="form-textarea" placeholder="Any additional notes…"></textarea>
                </div>
            </div>

            <!-- Summary box -->
            <div id="log_summary" class="info-box" style="display:none">
                <h4>📊 Log Summary</h4>
                <div class="info-grid">
                    <div class="info-item">
                        <span class="info-label">Distance Covered</span>
                        <span class="info-value" id="summary_distance">0 KM</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Est. Fuel Used</span>
                        <span class="info-value" id="summary_fuel">0 L</span>
                    </div>
                    <div class="info-item">
                        <span class="info-label">Fuel Efficiency</span>
                        <span class="info-value" id="summary_efficiency">— KM/L</span>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-success">📝 Create Log</button>
            </div>
        </form>
    </div><!-- /card -->


    <!-- ════════════ FILTER ════════════ -->
    <div class="card">
        <h2 class="card-title">🔍 Filter Logs</h2>
        <form method="GET">
            <div class="filter-grid">

                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <input type="text" name="fiscal_year" class="form-input"
                           value="<?= htmlspecialchars($filter_fiscal) ?>">
                </div>

                <div class="form-group">
                    <label class="form-label">Month</label>
                    <select name="month_nep" class="form-select">
                        <option value="">All Months</option>
                        <?php
                        $months = ['Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
                                   'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
                        foreach ($months as $m): ?>
                            <option value="<?= $m ?>" <?= $filter_month === $m ? 'selected' : '' ?>><?= $m ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Vehicle filter (searchable) -->
                <div class="form-group">
                    <label class="form-label">Vehicle</label>
                    <div class="select-search-wrap" id="fvehicle_wrap">
                        <input type="text" class="select-search-input" id="fvehicle_search"
                               placeholder="🔍 Search vehicle…" autocomplete="off">
                        <select name="vehicle_id" id="fvehicle_id" class="form-select">
                            <option value="">All Vehicles</option>
                            <?php foreach ($vehicles as $v): ?>
                                <option value="<?= $v['vehicle_id'] ?>"
                                        <?= $filter_vehicle == $v['vehicle_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($v['vehicle_no']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <!-- Driver filter (searchable) -->
                <div class="form-group">
                    <label class="form-label">Driver</label>
                    <div class="select-search-wrap" id="fdriver_wrap">
                        <input type="text" class="select-search-input" id="fdriver_search"
                               placeholder="🔍 Search driver…" autocomplete="off">
                        <select name="driver_id" id="fdriver_id" class="form-select">
                            <option value="">All Drivers</option>
                            <?php foreach ($drivers as $d): ?>
                                <option value="<?= $d['driver_id'] ?>"
                                        <?= $filter_driver == $d['driver_id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($d['driver_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-primary">🔍 Apply Filter</button>
                <a href="?" class="btn btn-gray">🔄 Reset</a>
            </div>
        </form>
    </div>


    <!-- ════════════ LOGS TABLE ════════════ -->
    <div class="data-table-container">
        <div class="table-header">
            <span class="table-title">📄 Log Entries</span>
            <span class="record-count"><?= count($logs) ?> record(s) found</span>
        </div>
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Log Date</th>
                    <th>Month</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Start KM</th>
                    <th>End KM</th>
                    <th>Distance</th>
                    <th>Fuel (L)</th>
                    <th>From → To</th>
                    <th>Purpose</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr><td colspan="12" style="text-align:center;padding:40px;color:#718096">No logs found for the selected filters.</td></tr>
                <?php else: ?>
                    <?php foreach ($logs as $idx => $log):
                        $distance = (int)$log['end_meter'] - (int)$log['start_meter'];
                    ?>
                    <tr>
                        <td><?= $idx + 1 ?></td>
                        <td>
                            <strong><?= htmlspecialchars($log['log_date_nep']) ?></strong><br>
                            <span style="font-size:11px;color:#718096"><?= date('d M Y', strtotime($log['log_date_eng'])) ?></span>
                        </td>
                        <td>
                            <?php if ($log['month_nep']): ?>
                                <span class="badge badge-month"><?= htmlspecialchars($log['month_nep']) ?></span>
                            <?php else: ?>
                                <span class="badge badge-warn">⚠ Missing</span>
                            <?php endif; ?>
                        </td>
                        <td>
                            <strong><?= htmlspecialchars($log['vehicle_no']) ?></strong><br>
                            <span style="font-size:11px;color:#718096"><?= ucfirst($log['vehicle_type']) ?></span>
                        </td>
                        <td><?= htmlspecialchars($log['driver_name'] ?: '—') ?></td>
                        <td><?= number_format((int)$log['start_meter']) ?></td>
                        <td><?= number_format((int)$log['end_meter']) ?></td>
                        <td>
                            <span class="badge badge-km"><?= number_format($distance) ?> KM</span>
                        </td>
                        <td><?= $log['fuel_used_estimated'] ? number_format((float)$log['fuel_used_estimated'], 2) : '—' ?></td>
                        <td style="font-size:12px">
                            <?php if ($log['from_location'] || $log['to_location']): ?>
                                <?= htmlspecialchars($log['from_location'] ?: '?') ?> →
                                <?= htmlspecialchars($log['to_location']   ?: '?') ?>
                            <?php else: ?>—<?php endif; ?>
                        </td>
                        <td style="max-width:160px;font-size:12px;color:#4a5568">
                            <?= htmlspecialchars(mb_strimwidth($log['purpose'] ?: '—', 0, 60, '…')) ?>
                        </td>
                        <td class="actions-cell">
                            <button type="button" class="btn btn-warning btn-sm"
                                    onclick="openEditModal(<?= htmlspecialchars(json_encode($log)) ?>)">
                                ✏️ Edit
                            </button>
                            <button type="button" class="btn btn-danger btn-sm"
                                    onclick="openDeleteModal(<?= $log['log_id'] ?>, '<?= htmlspecialchars(addslashes($log['vehicle_no'])) ?>', '<?= htmlspecialchars(addslashes($log['log_date_nep'])) ?>')">
                                🗑 Del
                            </button>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div><!-- /container -->


<!-- ════════════════════════════════════════════════════════════
     EDIT MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay" id="editModal">
    <div class="modal-box">
        <div class="modal-header">
            <h3>✏️ Edit Vehicle Log</h3>
            <button class="modal-close" onclick="closeModal('editModal')">×</button>
        </div>
        <div class="modal-body">
            <form method="POST" id="editForm">
                <input type="hidden" name="action" value="update">
                <input type="hidden" name="log_id" id="edit_log_id">

                <div class="form-grid">

                    <!-- Vehicle (searchable) -->
                    <div class="form-group">
                        <label class="form-label required">Vehicle</label>
                        <div class="select-search-wrap" id="edit_vehicle_wrap">
                            <input type="text" class="select-search-input" id="edit_vehicle_search"
                                   placeholder="🔍 Search vehicle…" autocomplete="off">
                            <select name="vehicle_id" id="edit_vehicle_id" class="form-select" required>
                                <option value="">Select Vehicle</option>
                                <?php foreach ($vehicles as $v): ?>
                                    <option value="<?= $v['vehicle_id'] ?>"
                                            data-current-driver="<?= $vehicle_assignments[$v['vehicle_id']]['driver_id'] ?? '' ?>">
                                        <?= htmlspecialchars($v['vehicle_no']) ?>
                                        (<?= ucfirst($v['vehicle_type']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Driver (searchable) -->
                    <div class="form-group">
                        <label class="form-label">Driver</label>
                        <div class="select-search-wrap" id="edit_driver_wrap">
                            <input type="text" class="select-search-input" id="edit_driver_search"
                                   placeholder="🔍 Search driver…" autocomplete="off">
                            <select name="driver_id" id="edit_driver_id" class="form-select">
                                <option value="">Select Driver</option>
                                <?php foreach ($drivers as $d): ?>
                                    <option value="<?= $d['driver_id'] ?>">
                                        <?= htmlspecialchars($d['driver_name']) ?>
                                        (<?= htmlspecialchars($d['license_no']) ?>)
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        </div>
                    </div>

                    <!-- Log Date Nepali -->
                    <div class="form-group">
                        <label class="form-label required">Log Date (Nepali)</label>
                        <div class="nep-date-wrap">
                            <input type="text" name="log_date_nep" id="edit_log_date_nep"
                                   class="form-input" placeholder="Click to pick Nepali date" readonly required>
                            <span class="nep-date-icon">📅</span>
                        </div>
                        <span class="form-hint">Month: <strong id="edit_nep_month_preview" style="color:#2b6cb0">—</strong></span>
                    </div>

                    <!-- Log Date English -->
                    <div class="form-group">
                        <label class="form-label required">Log Date (English)</label>
                        <input type="date" name="log_date_eng" id="edit_log_date_eng" class="form-input" required>
                    </div>

                    <!-- Start Meter -->
                    <div class="form-group">
                        <label class="form-label required">Start Meter (KM)</label>
                        <input type="number" name="start_meter" id="edit_start_meter"
                               class="form-input" step="1" min="0" required>
                    </div>

                    <!-- End Meter -->
                    <div class="form-group">
                        <label class="form-label required">End Meter (KM)</label>
                        <input type="number" name="end_meter" id="edit_end_meter"
                               class="form-input" step="1" min="0" required>
                    </div>

                    <!-- Distance (display) -->
                    <div class="form-group">
                        <label class="form-label">Distance Covered</label>
                        <div id="edit_distance_display" class="distance-badge" style="display:none"></div>
                    </div>

                    <!-- Fuel Used -->
                    <div class="form-group">
                        <label class="form-label">Fuel Used (Est. Litres)</label>
                        <input type="number" name="fuel_used_estimated" id="edit_fuel_used_estimated"
                               class="form-input" step="0.01" min="0">
                    </div>

                    <!-- From -->
                    <div class="form-group">
                        <label class="form-label">From Location</label>
                        <input type="text" name="from_location" id="edit_from_location" class="form-input">
                    </div>

                    <!-- To -->
                    <div class="form-group">
                        <label class="form-label">To Location</label>
                        <input type="text" name="to_location" id="edit_to_location" class="form-input">
                    </div>

                    <!-- Fiscal Year (read-only) -->
                    <div class="form-group">
                        <label class="form-label">Fiscal Year</label>
                        <input type="text" name="fiscal_year" id="edit_fiscal_year" class="form-input" readonly>
                    </div>

                </div>

                <div class="form-grid" style="grid-template-columns: 1fr 1fr;">
                    <div class="form-group">
                        <label class="form-label">Purpose</label>
                        <textarea name="purpose" id="edit_purpose" class="form-textarea"></textarea>
                    </div>
                    <div class="form-group">
                        <label class="form-label">Remarks</label>
                        <textarea name="remarks" id="edit_remarks" class="form-textarea"></textarea>
                    </div>
                </div>

                <div class="form-actions">
                    <button type="submit" class="btn btn-primary">💾 Save Changes</button>
                    <button type="button" class="btn btn-gray" onclick="closeModal('editModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>

<!-- ════════════════════════════════════════════════════════════
     DELETE CONFIRM MODAL
════════════════════════════════════════════════════════════ -->
<div class="modal-overlay modal-delete" id="deleteModal">
    <div class="modal-box" style="max-width:440px">
        <div class="modal-body">
            <div class="del-icon">🗑️</div>
            <h4>Delete Log Entry?</h4>
            <p id="del_description">Are you sure you want to delete this log?</p>
            <form method="POST" id="deleteForm">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="log_id" id="delete_log_id">
                <div class="del-actions">
                    <button type="submit" class="btn btn-danger">Yes, Delete</button>
                    <button type="button" class="btn btn-gray" onclick="closeModal('deleteModal')">Cancel</button>
                </div>
            </form>
        </div>
    </div>
</div>


<!-- jQuery (required by Nepali datepicker) -->
<script src="https://cdnjs.cloudflare.com/ajax/libs/jquery/3.7.1/jquery.min.js"></script>
<!-- Nepali datepicker -->
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>

<!-- ── Fiscal year lookup (server-side for JS) ───────────────────────────── -->
<script>
const vehicleAssignments = <?= json_encode($vehicle_assignments) ?>;
const nepMonths = {
    1:'Baishakh',2:'Jestha',3:'Ashadh',4:'Shrawan',
    5:'Bhadra',6:'Ashwin',7:'Kartik',8:'Mangsir',
    9:'Poush',10:'Magh',11:'Falgun',12:'Chaitra'
};

// ── Fiscal-year determination from Nepali date ────────────────────────────
// Nepali fiscal year: Shrawan (4) – Ashadh (3)  →  e.g. 2082/83
function getFiscalYear(nepDateStr) {
    const norm  = nepDateStr.replace(/[-\/]/g, '.');
    const parts = norm.split('.');
    if (parts.length < 2) return '2082/83';
    const year  = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);
    // Months 1-3 (Baishakh-Ashadh) → previous year's FY  e.g. 2083 month 1 → 2082/83
    if (month >= 1 && month <= 3) {
        return (year - 1) + '/' + String(year).slice(-2);
    }
    // Months 4-12 (Shrawan-Chaitra) → current year's FY  e.g. 2082 month 4 → 2082/83
    return year + '/' + String(year + 1).slice(-2);
}

// ── Searchable select ─────────────────────────────────────────────────────
// Uses a hidden <datalist>-style filter — options stay in the DOM so
// data-* attributes and change events are never broken.
function initSearchableSelect(wrapId, searchInputId, selectId) {
    const wrap   = document.getElementById(wrapId);
    const search = document.getElementById(searchInputId);
    const select = document.getElementById(selectId);
    if (!wrap || !search || !select) return;

    search.classList.add('visible');
    select.classList.add('select-with-search');

    // Keep a master copy of text for each option (value → label)
    const optionTexts = {};
    Array.from(select.options).forEach(opt => {
        optionTexts[opt.value] = opt.text.toLowerCase();
    });

    search.addEventListener('input', function () {
        const q = this.value.toLowerCase().trim();
        let firstVisible = null;

        Array.from(select.options).forEach(opt => {
            const match = !q || opt.value === '' || (optionTexts[opt.value] || '').includes(q);
            opt.style.display = match ? '' : 'none';
            if (match && firstVisible === null && opt.value !== '') firstVisible = opt.value;
        });

        // If current selection is hidden, reset to blank
        const cur = select.options[select.selectedIndex];
        if (cur && cur.style.display === 'none') {
            select.value = '';
        }
    });
}

// ── Previous month end KM fetch ───────────────────────────────────────────
let prevKmAbort = null;
async function fetchPrevEndKm(vehicleId, nepDateStr) {
    const hintEl = document.getElementById('start_km_hint');
    if (!vehicleId || !nepDateStr) return;

    const norm  = nepDateStr.replace(/[-\/]/g, '.');
    const parts = norm.split('.');
    if (parts.length < 3) return;

    const year  = parseInt(parts[0], 10);
    const month = parseInt(parts[1], 10);

    // Determine previous month/year
    let prevMonth = month - 1;
    let prevYear  = year;
    if (prevMonth < 1) { prevMonth = 12; prevYear = year - 1; }
    const prevMonthName = nepMonths[prevMonth] || '';

    hintEl.textContent = '⏳ Looking up previous month end KM…';
    hintEl.className   = 'km-hint loading';

    try {
        if (prevKmAbort) prevKmAbort.abort();
        prevKmAbort = new AbortController();

        const url = `?ajax=prev_end_km&vehicle_id=${vehicleId}&year_nep=${prevYear}&month_nep=${encodeURIComponent(prevMonthName)}`;
        const resp = await fetch(url, { signal: prevKmAbort.signal });
        const data = await resp.json();

        const startInput = document.getElementById('start_meter');
        if (data.end_km !== null && data.end_km !== undefined) {
            startInput.value   = data.end_km;
            hintEl.textContent = `✅ Auto-filled from ${prevMonthName} ${prevYear} end KM: ${parseInt(data.end_km).toLocaleString()}`;
            hintEl.className   = 'km-hint found';
            recalcCreate();
        } else {
            hintEl.textContent = `ℹ️ No previous log found for ${prevMonthName} ${prevYear}. Enter manually.`;
            hintEl.className   = 'km-hint notfound';
        }
    } catch (e) {
        if (e.name !== 'AbortError') {
            hintEl.textContent = '';
            hintEl.className   = 'km-hint';
        }
    }
}

// ── Month preview helper ──────────────────────────────────────────────────
function updateMonthPreview(dateStr, previewId) {
    const norm  = (dateStr || '').replace(/[-\/]/g, '.');
    const parts = norm.split('.');
    const mNum  = parts.length >= 2 ? parseInt(parts[1], 10) : 0;
    const el    = document.getElementById(previewId);
    if (el) el.textContent = nepMonths[mNum] || '—';
}

// ── Distance recalc ───────────────────────────────────────────────────────
function recalcCreate() {
    const start = parseInt(document.getElementById('start_meter').value, 10);
    const end   = parseInt(document.getElementById('end_meter').value,   10);
    const dist  = document.getElementById('distance_display');
    const sumBox = document.getElementById('log_summary');

    if (!isNaN(start) && !isNaN(end)) {
        const d = end - start;
        dist.style.display = 'inline-block';
        if (d < 0) {
            dist.textContent = '⚠ End meter must be ≥ Start meter';
            dist.className   = 'distance-badge error';
            sumBox.style.display = 'none';
            return;
        }
        dist.textContent = d.toLocaleString() + ' KM';
        dist.className   = 'distance-badge';
        const fuel = parseFloat(document.getElementById('fuel_used_estimated').value) || 0;
        document.getElementById('summary_distance').textContent   = d.toLocaleString() + ' KM';
        document.getElementById('summary_fuel').textContent       = fuel.toFixed(2) + ' L';
        document.getElementById('summary_efficiency').textContent = fuel > 0 ? (d / fuel).toFixed(2) + ' KM/L' : '— KM/L';
        sumBox.style.display = (d > 0 || fuel > 0) ? 'block' : 'none';
    } else {
        dist.style.display   = 'none';
        sumBox.style.display = 'none';
    }
}

function recalcEdit() {
    const start = parseInt(document.getElementById('edit_start_meter').value, 10);
    const end   = parseInt(document.getElementById('edit_end_meter').value,   10);
    const dist  = document.getElementById('edit_distance_display');
    if (!isNaN(start) && !isNaN(end)) {
        const d = end - start;
        dist.style.display = 'inline-block';
        dist.textContent   = d < 0 ? '⚠ End must be ≥ Start' : d.toLocaleString() + ' KM';
        dist.className     = d < 0 ? 'distance-badge error' : 'distance-badge';
    } else {
        dist.style.display = 'none';
    }
}

// ── Modal helpers ─────────────────────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id).classList.remove('active');
    document.body.style.overflow = '';
}
function openModal(id) {
    document.getElementById(id).classList.add('active');
    document.body.style.overflow = 'hidden';
}
// Close on overlay click
document.querySelectorAll('.modal-overlay').forEach(el => {
    el.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

function openDeleteModal(logId, vehicleNo, logDateNep) {
    document.getElementById('delete_log_id').value = logId;
    document.getElementById('del_description').textContent =
        `Delete log for ${vehicleNo} on ${logDateNep}? This cannot be undone.`;
    openModal('deleteModal');
}

function openEditModal(log) {
    document.getElementById('edit_log_id').value              = log.log_id;
    document.getElementById('edit_vehicle_id').value          = log.vehicle_id;
    document.getElementById('edit_driver_id').value           = log.driver_id || '';
    document.getElementById('edit_log_date_nep').value        = log.log_date_nep;
    document.getElementById('edit_log_date_eng').value        = log.log_date_eng;
    document.getElementById('edit_start_meter').value         = log.start_meter;
    document.getElementById('edit_end_meter').value           = log.end_meter;
    document.getElementById('edit_fuel_used_estimated').value = log.fuel_used_estimated || '';
    document.getElementById('edit_from_location').value       = log.from_location || '';
    document.getElementById('edit_to_location').value         = log.to_location   || '';
    document.getElementById('edit_purpose').value             = log.purpose       || '';
    document.getElementById('edit_remarks').value             = log.remarks       || '';
    document.getElementById('edit_fiscal_year').value         = log.fiscal_year   || '';
    updateMonthPreview(log.log_date_nep, 'edit_nep_month_preview');
    recalcEdit();
    openModal('editModal');
}

// ── DOMContentLoaded ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function () {

    // ── Searchable selects ────────────────────────────────────────────────
    initSearchableSelect('vehicle_wrap',  'vehicle_search',       'vehicle_id');
    initSearchableSelect('driver_wrap',   'driver_search',        'driver_id');
    initSearchableSelect('fvehicle_wrap', 'fvehicle_search',      'fvehicle_id');
    initSearchableSelect('fdriver_wrap',  'fdriver_search',       'fdriver_id');
    initSearchableSelect('edit_vehicle_wrap', 'edit_vehicle_search', 'edit_vehicle_id');
    initSearchableSelect('edit_driver_wrap',  'edit_driver_search',  'edit_driver_id');

    // ── Nepali datepicker – CREATE form ───────────────────────────────────
    if (typeof $.fn.nepaliDatePicker !== 'undefined') {

        function handleNepDateChange(val, engFieldId, monthPreviewId, fiscalFieldId, fetchKm) {
            updateMonthPreview(val, monthPreviewId);
            // Auto-fill fiscal year
            document.getElementById(fiscalFieldId).value = getFiscalYear(val);
            // Auto-fill English date via nepali-to-english conversion
            try {
                const norm  = val.replace(/[-\/]/g, '-');
                // The v5 library exposes NepaliFunctions.BS2AD({bsYear, bsMonth, bsDate})
                const parts = val.replace(/[-\/\.]/g, '-').split('-');
                if (parts.length === 3) {
                    const bsY = parseInt(parts[0], 10);
                    const bsM = parseInt(parts[1], 10);
                    const bsD = parseInt(parts[2], 10);
                    // Try object form first (v5), fall back to positional (older)
                    let eng = null;
                    if (typeof NepaliFunctions !== 'undefined') {
                        try { eng = NepaliFunctions.BS2AD({bsYear: bsY, bsMonth: bsM, bsDate: bsD}); } catch(e2) {}
                        if (!eng) {
                            try { eng = NepaliFunctions.BS2AD(bsY, bsM, bsD); } catch(e3) {}
                        }
                    }
                    if (eng) {
                        const ey = eng.adYear  || eng.year;
                        const em = eng.adMonth || eng.month;
                        const ed = eng.adDate  || eng.day;
                        document.getElementById(engFieldId).value =
                            `${ey}-${String(em).padStart(2,'0')}-${String(ed).padStart(2,'0')}`;
                    }
                }
            } catch(e) { console.warn('BS2AD error', e); }
            if (fetchKm) {
                const vid = document.getElementById('vehicle_id').value;
                fetchPrevEndKm(vid, val);
            }
        }

        $('#log_date_nep').nepaliDatePicker({
            ndpYear  : true,
            ndpMonth : true,
            ndpDay   : true,
            dateFormat: 'YYYY-MM-DD',
            onSelect : function(val) {
                handleNepDateChange(val, 'log_date_eng', 'nep_month_preview', 'fiscal_year', true);
            }
        });

        // ── Nepali datepicker – EDIT modal ────────────────────────────────
        $('#edit_log_date_nep').nepaliDatePicker({
            ndpYear  : true,
            ndpMonth : true,
            ndpDay   : true,
            dateFormat: 'YYYY-MM-DD',
            onSelect : function(val) {
                handleNepDateChange(val, 'edit_log_date_eng', 'edit_nep_month_preview', 'edit_fiscal_year', false);
            }
        });

    } else {
        console.warn('Nepali datepicker plugin not loaded. Check jQuery and datepicker script order.');
    }

    // ── Vehicle change → auto-fill driver + fetch prev KM ─────────────────
    document.getElementById('vehicle_id').addEventListener('change', function () {
        const vid = this.value;
        if (vid && vehicleAssignments[vid]) {
            // Set driver select value
            document.getElementById('driver_id').value = vehicleAssignments[vid].driver_id;
            // Clear driver search box so selection is visible in the select
            const ds = document.getElementById('driver_search');
            if (ds) {
                ds.value = '';
                // Show all driver options again
                Array.from(document.getElementById('driver_id').options).forEach(o => o.style.display = '');
            }
        }
        const nepDate = document.getElementById('log_date_nep').value;
        if (nepDate) fetchPrevEndKm(vid, nepDate);
    });

    // ── Meter inputs recalculate ──────────────────────────────────────────
    document.getElementById('start_meter').addEventListener('input', recalcCreate);
    document.getElementById('end_meter').addEventListener('input', recalcCreate);
    document.getElementById('fuel_used_estimated').addEventListener('input', recalcCreate);
    document.getElementById('edit_start_meter').addEventListener('input', recalcEdit);
    document.getElementById('edit_end_meter').addEventListener('input',   recalcEdit);
});
</script>

<?php
// ════════════════════════════════════════════════════════════════════════════
// AJAX endpoint — previous month end KM
// Called as: ?ajax=prev_end_km&vehicle_id=X&year_nep=Y&month_nep=Z
// ════════════════════════════════════════════════════════════════════════════
if (isset($_GET['ajax']) && $_GET['ajax'] === 'prev_end_km') {
    ob_clean();
    header('Content-Type: application/json');
    $vid        = (int)($_GET['vehicle_id'] ?? 0);
    $year_nep   = (int)($_GET['year_nep']   ?? 0);
    $month_nep  = trim($_GET['month_nep']   ?? '');

    if (!$vid || !$year_nep || !$month_nep) {
        echo json_encode(['end_km' => null]);
        exit;
    }
    // Find the last log for this vehicle in that Nepali year + month
    $q = $conn->prepare("
        SELECT end_meter
        FROM vehicle_daily_logs
        WHERE vehicle_id = :vid
          AND month_nep  = :month_nep
          AND log_date_nep LIKE :year_prefix
          AND deleted_at IS NULL
        ORDER BY log_date_eng DESC, log_id DESC
        LIMIT 1
    ");
    $q->execute([
        ':vid'         => $vid,
        ':month_nep'   => $month_nep,
        ':year_prefix' => $year_nep . '%',
    ]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    echo json_encode(['end_km' => $row ? (int)$row['end_meter'] : null]);
    exit;
}
?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
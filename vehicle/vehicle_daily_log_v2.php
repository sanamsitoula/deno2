<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;
$logged_user_id = $_SESSION['user_id'] ?? 1;

/* ══════════════════════════════════════════════════
   Helper: derive month_nep from Nepali date string
   Supports:  2082.12.01  |  2082-12-01  |  2082/12/01
══════════════════════════════════════════════════ */
function get_month_nep_from_date(string $nep_date): ?string {
    $month_names = [
        1 => 'Baishakh', 2 => 'Jestha',  3 => 'Ashadh',   4 => 'Shrawan',
        5 => 'Bhadra',   6 => 'Ashwin',  7 => 'Kartik',   8 => 'Mangsir',
        9 => 'Poush',   10 => 'Magh',   11 => 'Falgun',   12 => 'Chaitra'
    ];
    $normalised = str_replace(['-', '/'], '.', trim($nep_date));
    $parts = explode('.', $normalised);
    $month_num = isset($parts[1]) ? (int)$parts[1] : 0;
    return $month_names[$month_num] ?? null;
}

/* ══════════════════════════════════════════════════
   Helper: normalize a Nepali date to one canonical
   format (YYYY.MM.DD, zero-padded) before it's stored.

   Root cause fix: users have typed 2083.04.01, 2083-04-01,
   2083/4/1 etc. interchangeably, so log_date_nep is not
   consistent in the table. This doesn't break fiscal year
   (that's derived from log_date_eng), but it makes the data
   messy and unsortable as text. From now on every save goes
   through this so new rows are always stored the same way.
══════════════════════════════════════════════════ */
function normalize_nep_date(string $raw): string {
    $normalised = str_replace(['-', '/'], '.', trim($raw));
    $parts = explode('.', $normalised);
    if (count($parts) < 3 || !ctype_digit($parts[0]) || !ctype_digit($parts[1]) || !ctype_digit($parts[2])) {
        // Malformed input — leave as typed rather than guessing, so it's
        // still visible/flaggable in the report instead of silently mangled.
        return trim($raw);
    }
    [$y, $m, $d] = array_map('intval', $parts);
    return sprintf('%d.%02d.%02d', $y, $m, $d);
}

/* ══════════════════════════════════════════════════
   Helper: derive fiscal year from the DB, not by
   hand-formatting a string.

   Root cause fix: the old code built strings like
   "2083/84" (slash) while public.fiscal_years.fiscal_name
   is stored as "2083-84" (hyphen). That mismatch meant a
   freshly-created log's fiscal_year value could never match
   any row in fiscal_years -> broken filtering, broken
   "active fiscal year" selection, and undefined-key
   fallbacks further down the page.

   This version looks up the fiscal_name straight from
   fiscal_years using the log's ENGLISH date against
   start_date/end_date, so the stored value is always
   byte-for-byte identical to what's in fiscal_years —
   whatever naming convention you use there.
══════════════════════════════════════════════════ */
function get_fiscal_year_for_date(PDO $conn, string $log_date_eng): ?string {
    $stmt = $conn->prepare("
        SELECT fiscal_name
        FROM fiscal_years
        WHERE :d BETWEEN start_date AND end_date
        LIMIT 1
    ");
    $stmt->execute([':d' => $log_date_eng]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row['fiscal_name'] ?? null;
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        $action = $_POST['action'] ?? 'create';

        if ($action === 'create') {
            $required_fields = ['vehicle_id', 'log_date_nep', 'log_date_eng', 'log_end_date_nep', 'log_end_date_eng', 'start_meter', 'end_meter'];

            foreach ($required_fields as $field) {
                if (empty($_POST[$field])) {
                    throw new Exception("Field '{$field}' is required");
                }
            }

            if ((int)$_POST['end_meter'] < (int)$_POST['start_meter']) {
                throw new Exception("End meter reading cannot be less than start meter reading.");
            }
            if ($_POST['log_end_date_eng'] < $_POST['log_date_eng']) {
                throw new Exception("To date cannot be earlier than From date.");
            }

            // Normalize both Nepali dates to one consistent stored format,
            // then derive month_nep from the normalized From date, and
            // fiscal_year from the authoritative fiscal_years table
            // (never trust a posted value for either).
            $log_date_nep     = normalize_nep_date($_POST['log_date_nep']);
            $log_end_date_nep = normalize_nep_date($_POST['log_end_date_nep']);
            $month_nep   = get_month_nep_from_date($log_date_nep);
            $fiscal_year = get_fiscal_year_for_date($conn, $_POST['log_date_eng']);
            if (!$fiscal_year) {
                throw new Exception("No fiscal year record in Fiscal Years covers {$_POST['log_date_eng']}. Please add/extend a fiscal year first.");
            }

            $insert_sql = "
                INSERT INTO vehicle_daily_logs (
                    vehicle_id, driver_id, log_date_nep, log_date_eng,
                    log_end_date_nep, log_end_date_eng,
                    from_location, to_location, start_meter, end_meter,
                    purpose, fuel_used_estimated, remarks, fiscal_year, month_nep, created_by
                ) VALUES (
                    :vehicle_id, :driver_id, :log_date_nep, :log_date_eng,
                    :log_end_date_nep, :log_end_date_eng,
                    :from_location, :to_location, :start_meter, :end_meter,
                    :purpose, :fuel_used_estimated, :remarks, :fiscal_year, :month_nep, :created_by
                )
            ";

            $stmt = $conn->prepare($insert_sql);
            $stmt->execute([
                ':vehicle_id'         => $_POST['vehicle_id'],
                ':driver_id'          => $_POST['driver_id'] ?: null,
                ':log_date_nep'       => $log_date_nep,
                ':log_date_eng'       => $_POST['log_date_eng'],
                ':log_end_date_nep'   => $log_end_date_nep,
                ':log_end_date_eng'   => $_POST['log_end_date_eng'],
                ':from_location'      => $_POST['from_location'] ?? null,
                ':to_location'        => $_POST['to_location']   ?? null,
                ':start_meter'        => (int)$_POST['start_meter'],
                ':end_meter'          => (int)$_POST['end_meter'],
                ':purpose'            => $_POST['purpose']       ?? null,
                ':fuel_used_estimated'=> $_POST['fuel_used_estimated'] ?: null,
                ':remarks'            => $_POST['remarks']       ?? null,
                ':fiscal_year'        => $fiscal_year,
                ':month_nep'          => $month_nep,
                ':created_by'         => $logged_user_id
            ]);

            $success_message = "Vehicle log created successfully! (Fiscal Year auto-set to {$fiscal_year}, Month: {$month_nep})";

        } elseif ($action === 'update') {
            if (empty($_POST['log_id'])) {
                throw new Exception("Missing log_id for update.");
            }
            if ((int)$_POST['end_meter'] < (int)$_POST['start_meter']) {
                throw new Exception("End meter reading cannot be less than start meter reading.");
            }
            if ($_POST['log_end_date_eng'] < $_POST['log_date_eng']) {
                throw new Exception("To date cannot be earlier than From date.");
            }

            $log_date_nep     = normalize_nep_date($_POST['log_date_nep']);
            $log_end_date_nep = normalize_nep_date($_POST['log_end_date_nep']);
            $month_nep   = get_month_nep_from_date($log_date_nep);
            $fiscal_year = get_fiscal_year_for_date($conn, $_POST['log_date_eng']);
            if (!$fiscal_year) {
                throw new Exception("No fiscal year record in Fiscal Years covers {$_POST['log_date_eng']}. Please add/extend a fiscal year first.");
            }

            $update_sql = "
                UPDATE vehicle_daily_logs SET
                    vehicle_id = :vehicle_id,
                    driver_id = :driver_id,
                    log_date_nep = :log_date_nep,
                    log_date_eng = :log_date_eng,
                    log_end_date_nep = :log_end_date_nep,
                    log_end_date_eng = :log_end_date_eng,
                    from_location = :from_location,
                    to_location = :to_location,
                    start_meter = :start_meter,
                    end_meter = :end_meter,
                    purpose = :purpose,
                    fuel_used_estimated = :fuel_used_estimated,
                    remarks = :remarks,
                    fiscal_year = :fiscal_year,
                    month_nep = :month_nep,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE log_id = :log_id
            ";

            $stmt = $conn->prepare($update_sql);
            $stmt->execute([
                ':log_id'             => $_POST['log_id'],
                ':vehicle_id'         => $_POST['vehicle_id'],
                ':driver_id'          => $_POST['driver_id'] ?: null,
                ':log_date_nep'       => $log_date_nep,
                ':log_date_eng'       => $_POST['log_date_eng'],
                ':log_end_date_nep'   => $log_end_date_nep,
                ':log_end_date_eng'   => $_POST['log_end_date_eng'],
                ':from_location'      => $_POST['from_location'] ?? null,
                ':to_location'        => $_POST['to_location']   ?? null,
                ':start_meter'        => (int)$_POST['start_meter'],
                ':end_meter'          => (int)$_POST['end_meter'],
                ':purpose'            => $_POST['purpose']       ?? null,
                ':fuel_used_estimated'=> $_POST['fuel_used_estimated'] ?: null,
                ':remarks'            => $_POST['remarks']       ?? null,
                ':fiscal_year'        => $fiscal_year,
                ':month_nep'          => $month_nep,
                ':updated_by'         => $logged_user_id
            ]);

            $success_message = "Vehicle log updated successfully! (Fiscal Year auto-set to {$fiscal_year}, Month: {$month_nep})";

        } elseif ($action === 'delete') {
            if (empty($_POST['log_id'])) {
                throw new Exception("Missing log_id for delete.");
            }
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

// Fetch dropdown data
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

// Fetch vehicle driver assignments
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
        'driver_name' => $row['driver_name']
    ];
}

// Latest known end meter per vehicle (used to auto-fill next log's start meter)
$vehicle_last_meter = [];
$lm_stmt = $conn->query("
    SELECT DISTINCT ON (vehicle_id) vehicle_id, end_meter
    FROM vehicle_daily_logs
    WHERE deleted_at IS NULL
    ORDER BY vehicle_id, log_date_eng DESC, log_id DESC
");
foreach ($lm_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
    $vehicle_last_meter[$row['vehicle_id']] = (int)$row['end_meter'];
}

// Fiscal years for the filter dropdown + dynamic default (active row, or most recent as fallback)
// fiscal_name is the ONLY value used anywhere (dropdown, filter, storage, badges) — uniform end to end.
$fiscal_years = $conn->query("
    SELECT fiscal_code, fiscal_name, is_active, start_date, end_date
    FROM fiscal_years
    ORDER BY start_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$active_fiscal_year = null;
foreach ($fiscal_years as $fy) {
    if ($fy['is_active']) {
        $active_fiscal_year = $fy['fiscal_name'];
        break;
    }
}
if (!$active_fiscal_year && !empty($fiscal_years)) {
    $active_fiscal_year = $fiscal_years[0]['fiscal_name'];
}

// Fetch logs with filters
$filter_fiscal  = $_GET['fiscal_year'] ?? $active_fiscal_year ?? '';
$filter_month   = $_GET['month_nep']   ?? '';
$filter_vehicle = $_GET['vehicle_id']  ?? '';

$where_clause = "WHERE vdl.deleted_at IS NULL";
$params = [];

if ($filter_fiscal) {
    $where_clause .= " AND vdl.fiscal_year = :fiscal_year";
    $params[':fiscal_year'] = $filter_fiscal;
}
if ($filter_month) {
    $where_clause .= " AND vdl.month_nep = :month_nep";
    $params[':month_nep'] = $filter_month;
}
if ($filter_vehicle) {
    $where_clause .= " AND vdl.vehicle_id = :vehicle_id";
    $params[':vehicle_id'] = $filter_vehicle;
}

$stmt = $conn->prepare("
    SELECT
        vdl.log_id,
        vdl.log_date_nep,
        vdl.log_date_eng,
        vdl.log_end_date_nep,
        vdl.log_end_date_eng,
        vdl.vehicle_id,
        v.vehicle_no,
        v.vehicle_type,
        vdl.driver_id,
        d.driver_name,
        vdl.start_meter,
        vdl.end_meter,
        vdl.total_km,
        vdl.fuel_used_estimated,
        vdl.from_location,
        vdl.to_location,
        vdl.purpose,
        vdl.remarks,
        vdl.month_nep,
        vdl.fiscal_year
    FROM vehicle_daily_logs vdl
    JOIN vehicles v ON vdl.vehicle_id = v.vehicle_id
    LEFT JOIN drivers d ON vdl.driver_id = d.driver_id
    $where_clause
    ORDER BY vdl.log_date_eng DESC, vdl.log_id DESC
    LIMIT 100
");
$stmt->execute($params);
$logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
}
.container { max-width: 1800px; margin: 0 auto; padding: 20px; }
.page-header { margin-bottom: 30px; }
.page-title { font-size: 28px; font-weight: 700; color: #333; display: flex; align-items: center; gap: 10px; }
.alert { padding: 15px 20px; border-radius: 8px; margin-bottom: 20px; font-weight: 500; }
.alert-success { background: #d4edda; color: #155724; border: 1px solid #c3e6cb; }
.alert-error   { background: #f8d7da; color: #721c24; border: 1px solid #f5c6cb; }
.form-container { background: white; border-radius: 12px; padding: 30px; margin-bottom: 30px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.form-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(250px, 1fr)); gap: 20px; margin-bottom: 20px; }
.form-group { display: flex; flex-direction: column; }
.form-label { font-weight: 600; color: #495057; margin-bottom: 8px; font-size: 14px; }
.required::after { content: ' *'; color: #dc3545; }
.form-input, .form-select, .form-textarea {
    padding: 10px 15px; border: 1px solid #ced4da; border-radius: 6px;
    font-size: 14px; transition: border-color .3s;
}
.form-input:focus, .form-select:focus, .form-textarea:focus {
    outline: none; border-color: #007bff; box-shadow: 0 0 0 3px rgba(0,123,255,.1);
}
.form-textarea { resize: vertical; min-height: 80px; }
.form-input[readonly] { background: #f1f3f5; color: #333; font-weight: 600; cursor: not-allowed; }

.distance-badge {
    display: inline-block; background: #e7f3ff; border: 1px solid #b3d7ff;
    border-radius: 6px; padding: 8px 14px; font-weight: 700; font-size: 16px;
    color: #004085; margin-top: 4px;
}
.distance-badge.error { background: #f8d7da; border-color: #f5c6cb; color: #721c24; }

.info-box { background: #e7f3ff; border-left: 4px solid #007bff; padding: 15px; border-radius: 6px; margin: 20px 0; }
.info-box h4 { margin: 0 0 10px 0; color: #004085; }
.info-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px,1fr)); gap: 15px; }
.info-item { display: flex; flex-direction: column; }
.info-label { font-size: 12px; color: #6c757d; margin-bottom: 4px; }
.info-value { font-size: 18px; font-weight: 700; color: #333; }
.form-actions { display: flex; gap: 15px; margin-top: 25px; padding-top: 25px; border-top: 1px solid #dee2e6; }
.btn { padding: 12px 24px; border: none; border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer; transition: all .3s; display: inline-flex; align-items: center; gap: 8px; text-decoration: none; }
.btn-success { background: #28a745; color: white; } .btn-success:hover { background: #218838; }
.btn-primary { background: #007bff; color: white; } .btn-primary:hover { background: #0056b3; }
.data-table-container { background: white; border-radius: 12px; padding: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.08); overflow-x: auto; }
.data-table { width: 100%; border-collapse: collapse; }
.data-table th { background: #f8f9fa; padding: 12px; text-align: left; font-weight: 600; color: #495057; border-bottom: 2px solid #dee2e6; white-space: nowrap; }
.data-table td { padding: 12px; border-bottom: 1px solid #f1f1f1; }
.data-table tr:hover { background: #f8f9fa; }
.filter-container { background: white; border-radius: 12px; padding: 20px; margin-bottom: 20px; box-shadow: 0 2px 8px rgba(0,0,0,.08); }
.filter-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px,1fr)); gap: 15px; margin-bottom: 15px; }

.month-badge { display:inline-block; background:#e7f3ff; color:#004085; border-radius:4px; padding:1px 6px; font-size:11px; font-weight:600; }
.fy-badge { display:inline-block; background:#fff3cd; color:#856404; border-radius:4px; padding:1px 6px; font-size:11px; font-weight:600; margin-left:4px; }
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📋 Vehicle Daily Log</h1>
    </div>

    <?php if ($error_message): ?>
        <div class="alert alert-error">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>
    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <form method="POST" id="logForm">
            <input type="hidden" name="action" value="create">

            <div class="form-grid">

                <div class="form-group">
                    <label class="form-label required">Vehicle</label>
                    <select name="vehicle_id" id="vehicle_id" class="form-select" required>
                        <option value="">Select Vehicle</option>
                        <?php foreach ($vehicles as $vehicle): ?>
                            <option value="<?= $vehicle['vehicle_id'] ?>"
                                    data-fuel-type="<?= $vehicle['fuel_type'] ?>"
                                    data-current-driver="<?= $vehicle_assignments[$vehicle['vehicle_id']]['driver_id'] ?? '' ?>">
                                <?= htmlspecialchars($vehicle['vehicle_no']) ?>
                                (<?= ucfirst($vehicle['vehicle_type']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label class="form-label">Driver</label>
                    <select name="driver_id" id="driver_id" class="form-select">
                        <option value="">Select Driver</option>
                        <?php foreach ($drivers as $driver): ?>
                            <option value="<?= $driver['driver_id'] ?>">
                                <?= htmlspecialchars($driver['driver_name']) ?>
                                (<?= htmlspecialchars($driver['license_no']) ?>)
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <small style="color:#6c757d;margin-top:4px">Current driver will be auto-selected</small>
                </div>

                <div class="form-group">
                    <label class="form-label required">From Date (Nepali)</label>
                    <input type="text" name="log_date_nep" id="log_date_nep"
                           class="form-input" placeholder="2082-12-01 or 2082.12.01" required>
                    <small style="color:#6c757d;margin-top:4px">
                        Format: YYYY-MM-DD or YYYY.MM.DD &nbsp;|&nbsp;
                        Month: <span id="nep_month_preview" style="font-weight:600;color:#004085">—</span>
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label required">From Date (English)</label>
                    <input type="date" name="log_date_eng" id="log_date_eng"
                           class="form-input" value="<?= date('Y-m-d') ?>" required>
                    <small style="color:#6c757d;margin-top:4px">Default: Today's date</small>
                </div>

                <div class="form-group">
                    <label class="form-label required">To Date (Nepali)</label>
                    <input type="text" name="log_end_date_nep" id="log_end_date_nep"
                           class="form-input" placeholder="2082-12-01 or 2082.12.01" required>
                    <small style="color:#6c757d;margin-top:4px">For a single-day trip, same as From Date</small>
                </div>

                <div class="form-group">
                    <label class="form-label required">To Date (English)</label>
                    <input type="date" name="log_end_date_eng" id="log_end_date_eng"
                           class="form-input" value="<?= date('Y-m-d') ?>" required>
                </div>

                <div class="form-group">
                    <label class="form-label required">Start Meter (KM)</label>
                    <input type="number" name="start_meter" id="start_meter"
                           class="form-input" step="1" min="0" required>
                    <small style="color:#6c757d;margin-top:4px" id="start_meter_hint">
                        Auto-filled from the vehicle's last logged end meter (editable)
                    </small>
                </div>

                <div class="form-group">
                    <label class="form-label required">End Meter (KM)</label>
                    <input type="number" name="end_meter" id="end_meter"
                           class="form-input" step="1" min="0" required>
                </div>

                <div class="form-group">
                    <label class="form-label">Distance Covered</label>
                    <div id="distance_display" class="distance-badge" style="display:none"></div>
                    <input type="hidden" id="distance_covered">
                </div>

                <div class="form-group">
                    <label class="form-label">Fuel Used (Est. Liters)</label>
                    <input type="number" name="fuel_used_estimated" id="fuel_used_estimated"
                           class="form-input" step="0.01" min="0">
                </div>

                <div class="form-group">
                    <label class="form-label">From Location</label>
                    <input type="text" name="from_location" class="form-input" placeholder="Starting location">
                </div>

                <div class="form-group">
                    <label class="form-label">To Location</label>
                    <input type="text" name="to_location" class="form-input" placeholder="Destination">
                </div>

                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <input type="text" id="fiscal_year_display" class="form-input" readonly
                           value="Current active: <?= htmlspecialchars($active_fiscal_year ?? 'none set') ?>">
                    <small style="color:#6c757d;margin-top:4px">
                        Calculated on save from the From Date (English) against Fiscal Years — not user-editable.
                    </small>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Purpose</label>
                <textarea name="purpose" class="form-textarea" placeholder="Purpose of trip..."></textarea>
            </div>

            <div class="form-group">
                <label class="form-label">Remarks</label>
                <textarea name="remarks" class="form-textarea" placeholder="Any additional notes..."></textarea>
            </div>

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
                        <span class="info-value" id="summary_efficiency">0 KM/L</span>
                    </div>
                </div>
            </div>

            <div class="form-actions">
                <button type="submit" class="btn btn-success">📝 Create Log</button>
            </div>
        </form>
    </div>

    <!-- Filter Section -->
    <div class="filter-container">
        <form method="GET">
            <div class="filter-grid">
                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <select name="fiscal_year" class="form-select">
                        <option value="">All Fiscal Years</option>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= htmlspecialchars($fy['fiscal_name']) ?>" <?= $filter_fiscal === $fy['fiscal_name'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_name']) ?><?= $fy['is_active'] ? ' (active)' : '' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="form-group">
                    <label class="form-label">Month</label>
                    <select name="month_nep" class="form-select">
                        <option value="">All Months</option>
                        <?php
                        $months = ['Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
                                   'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'];
                        foreach ($months as $month): ?>
                            <option value="<?= $month ?>" <?= $filter_month === $month ? 'selected' : '' ?>>
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
                                    <?= $filter_vehicle == $vehicle['vehicle_id'] ? 'selected' : '' ?>>
                                <?= htmlspecialchars($vehicle['vehicle_no']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
            <div style="display:flex;gap:10px">
                <button type="submit" class="btn btn-primary">🔍 Filter</button>
                <a href="?" class="btn" style="background:#6c757d;color:white">🔄 Reset</a>
            </div>
        </form>
    </div>

    <!-- Logs Table -->
    <div class="data-table-container">
        <table class="data-table">
            <thead>
                <tr>
                    <th>#</th>
                    <th>Trip Date</th>
                    <th>Month</th>
                    <th>Fiscal Year</th>
                    <th>Vehicle</th>
                    <th>Driver</th>
                    <th>Start KM</th>
                    <th>End KM</th>
                    <th>Distance</th>
                    <th>Fuel Used</th>
                    <th>Route</th>
                    <th>Purpose</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($logs)): ?>
                    <tr>
                        <td colspan="12" style="text-align:center;padding:40px;color:#666">No logs found</td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($logs as $idx => $log):
                        $distance = (int)$log['end_meter'] - (int)$log['start_meter'];
                        $expected_fy = get_fiscal_year_for_date($conn, $log['log_date_eng']);
                        $fy_mismatch = $expected_fy && $expected_fy !== $log['fiscal_year'];
                    ?>
                        <tr>
                            <td><?= $idx + 1 ?></td>
                            <td>
                                <?php if ($log['log_end_date_eng'] && $log['log_end_date_eng'] !== $log['log_date_eng']): ?>
                                    <?= htmlspecialchars($log['log_date_nep']) ?> → <?= htmlspecialchars($log['log_end_date_nep']) ?><br>
                                    <small style="color:#6c757d">
                                        <?= date('d M', strtotime($log['log_date_eng'])) ?> → <?= date('d M Y', strtotime($log['log_end_date_eng'])) ?>
                                    </small>
                                <?php else: ?>
                                    <?= htmlspecialchars($log['log_date_nep']) ?><br>
                                    <small style="color:#6c757d">
                                        <?= date('d M Y', strtotime($log['log_date_eng'])) ?>
                                    </small>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?php if ($log['month_nep']): ?>
                                    <span class="month-badge"><?= htmlspecialchars($log['month_nep']) ?></span>
                                <?php else: ?>
                                    <span style="color:#dc3545;font-size:11px">⚠ Missing</span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <?= htmlspecialchars($log['fiscal_year']) ?>
                                <?php if ($fy_mismatch): ?>
                                    <br><span class="fy-badge" title="Expected <?= htmlspecialchars($expected_fy) ?> based on the trip date">⚠ expected <?= htmlspecialchars($expected_fy) ?></span>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong><?= htmlspecialchars($log['vehicle_no']) ?></strong><br>
                                <small style="color:#6c757d"><?= ucfirst($log['vehicle_type']) ?></small>
                            </td>
                            <td><?= htmlspecialchars($log['driver_name'] ?: '—') ?></td>
                            <td><?= number_format((int)$log['start_meter']) ?></td>
                            <td><?= number_format((int)$log['end_meter']) ?></td>
                            <td>
                                <strong style="color:<?= $distance > 0 ? '#137333' : '#888' ?>">
                                    <?= number_format($distance) ?> KM
                                </strong>
                            </td>
                            <td><?= $log['fuel_used_estimated'] ? number_format((float)$log['fuel_used_estimated'], 2) . ' L' : '—' ?></td>
                            <td>
                                <?php if ($log['from_location'] || $log['to_location']): ?>
                                    <?= htmlspecialchars($log['from_location'] ?: '?') ?> →
                                    <?= htmlspecialchars($log['to_location']   ?: '?') ?>
                                <?php else: ?>
                                    —
                                <?php endif; ?>
                            </td>
                            <td><?= htmlspecialchars($log['purpose'] ?: '—') ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script>
// Vehicle assignments for auto-filling driver
const vehicleAssignments = <?= json_encode($vehicle_assignments) ?>;

// Month name lookup (Nepali date -> month name only; fiscal year is server-computed
// from the fiscal_years table, so it is intentionally NOT recomputed here anymore —
// that duplicate client-side formula is exactly what caused the slash/hyphen mismatch).
const nepMonths = {
    1:'Baishakh', 2:'Jestha', 3:'Ashadh', 4:'Shrawan',
    5:'Bhadra',   6:'Ashwin', 7:'Kartik', 8:'Mangsir',
    9:'Poush',   10:'Magh',  11:'Falgun', 12:'Chaitra'
};

document.addEventListener('DOMContentLoaded', function () {
    const vehicleSelect   = document.getElementById('vehicle_id');
    const driverSelect    = document.getElementById('driver_id');
    const startMeter      = document.getElementById('start_meter');
    const endMeter        = document.getElementById('end_meter');
    const fuelUsed        = document.getElementById('fuel_used_estimated');
    const distDisplay     = document.getElementById('distance_display');
    const nepDateInput    = document.getElementById('log_date_nep');
    const monthPreview    = document.getElementById('nep_month_preview');

    // Auto-fill current driver when vehicle is selected, and fetch that
    // vehicle's last end meter LIVE from the server (not a page-load
    // snapshot) so it always reflects the current DB state and so we can
    // clearly say "no previous log" instead of silently doing nothing.
    vehicleSelect.addEventListener('change', function () {
        const vid = this.value;
        if (vid && vehicleAssignments[vid]) {
            driverSelect.value = vehicleAssignments[vid].driver_id;
        }
        if (!vid) {
            document.getElementById('start_meter_hint').textContent =
                "Auto-filled from the vehicle's last logged end meter (editable)";
            return;
        }

        document.getElementById('start_meter_hint').textContent = 'Checking last logged reading…';

        fetch('get_last_meter.php?vehicle_id=' + encodeURIComponent(vid))
            .then(res => res.json())
            .then(data => {
                if (data.found) {
                    startMeter.value = data.end_meter;
                    document.getElementById('start_meter_hint').textContent =
                        `Auto-filled from last log on ${data.log_date_nep} (${data.log_date_eng}), editable`;
                    recalculate();
                } else {
                    document.getElementById('start_meter_hint').textContent =
                        'No previous log found for this vehicle — enter the starting reading manually';
                }
            })
            .catch(err => {
                console.error('Could not fetch last meter reading:', err);
                document.getElementById('start_meter_hint').textContent =
                    'Could not check last reading (see console) — enter it manually';
            });
    });

    // Show month name preview as user types Nepali date
    function updateMonthPreview() {
        const val = nepDateInput.value.replace(/-/g, '.').replace(/\//g, '.');
        const parts = val.split('.');
        const mNum = parts.length >= 2 ? parseInt(parts[1], 10) : 0;
        monthPreview.textContent = nepMonths[mNum] || '—';
    }
    nepDateInput.addEventListener('input', updateMonthPreview);

    // Nepali calendar dialog + auto-detect English (AD) date on selection
    const logDateEng    = document.getElementById('log_date_eng');
    const endNepInput   = document.getElementById('log_end_date_nep');
    const endEngInput   = document.getElementById('log_end_date_eng');
    let toDateTouched = false;

    function updateEngFromNep() {
        const val = nepDateInput.value.trim();
        if (!val) return;
        try {
            const adDot = NepaliFunctions.BS2AD(val, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adDot) {
                logDateEng.value = adDot.replace(/\./g, '-');
                if (!toDateTouched) {
                    endNepInput.value = val;
                    endEngInput.value = logDateEng.value;
                }
            }
        } catch (e) {}
        updateMonthPreview();
    }
    if (typeof nepDateInput.NepaliDatePicker === 'function') {
        nepDateInput.NepaliDatePicker({
            dateFormat: 'YYYY.MM.DD',
            onDateSelect: updateEngFromNep
        });
    }
    nepDateInput.addEventListener('blur', updateEngFromNep);

    // To date: same calendar dialog + auto AD detection
    function updateEndEngFromNep() {
        toDateTouched = true;
        const val = endNepInput.value.trim();
        if (!val) return;
        try {
            const adDot = NepaliFunctions.BS2AD(val, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adDot) endEngInput.value = adDot.replace(/\./g, '-');
        } catch (e) {}
    }
    if (typeof endNepInput.NepaliDatePicker === 'function') {
        endNepInput.NepaliDatePicker({
            dateFormat: 'YYYY.MM.DD',
            onDateSelect: updateEndEngFromNep
        });
    }
    endNepInput.addEventListener('blur', updateEndEngFromNep);
    endEngInput.addEventListener('change', function () { toDateTouched = true; });

    // Calculate distance (end - start)
    function recalculate() {
        const start = parseInt(startMeter.value, 10);
        const end   = parseInt(endMeter.value,   10);

        if (!isNaN(start) && !isNaN(end)) {
            const dist = end - start;
            distDisplay.style.display = 'inline-block';

            if (dist < 0) {
                distDisplay.textContent = '⚠ End meter must be ≥ Start meter';
                distDisplay.className   = 'distance-badge error';
                document.getElementById('log_summary').style.display = 'none';
                return;
            }

            distDisplay.textContent = dist.toLocaleString() + ' KM';
            distDisplay.className   = 'distance-badge';

            const fuel = parseFloat(fuelUsed.value) || 0;
            document.getElementById('summary_distance').textContent = dist.toLocaleString() + ' KM';
            document.getElementById('summary_fuel').textContent     = fuel.toFixed(2) + ' L';
            document.getElementById('summary_efficiency').textContent =
                (fuel > 0) ? (dist / fuel).toFixed(2) + ' KM/L' : '— KM/L';

            document.getElementById('log_summary').style.display =
                (dist > 0 || fuel > 0) ? 'block' : 'none';
        } else {
            distDisplay.style.display = 'none';
            document.getElementById('log_summary').style.display = 'none';
        }
    }

    startMeter.addEventListener('input', recalculate);
    endMeter.addEventListener('input', recalculate);
    fuelUsed.addEventListener('input', recalculate);
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
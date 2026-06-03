<?php
// fuel_coupons.php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$logged_user_id  = $_SESSION['user_id'] ?? 1;

$nepali_months = [
    'Baishakh','Jestha','Ashadh','Shrawan','Bhadra','Ashwin',
    'Kartik','Mangsir','Poush','Magh','Falgun','Chaitra'
];
$fuel_types = ['petrol','diesel','mobil'];

// ── FIX: Predefined pump names and expense types (like old file) ──
$pump_names = ['Om Sai Oil Pvt. Ltd.', 'Nepal Oil Corporation', 'Other Pump'];

$fuel_expense_types = [
    'internalfacility' => 'Internal Facility',
    'nepalpolice'      => 'Nepal Police',
    'gon'              => 'GON',
    'media'            => 'Media',
    'others'           => 'Others'
];

// ── Helper: PRG redirect with session flash message ───────────
// After any successful write we redirect (GET) so browser refresh
// cannot re-submit the form (Post/Redirect/Get pattern).
function prg_redirect(string $url, string $msg, bool $is_error = false): void {
    $_SESSION['flash_msg']      = $msg;
    $_SESSION['flash_is_error'] = $is_error;
    header('Location: ' . $url);
    exit;
}

// ── Read & clear session flash (set by PRG redirect above) ────
$success_message = null;
$error_message   = null;
if (!empty($_SESSION['flash_msg'])) {
    if ($_SESSION['flash_is_error']) {
        $error_message = $_SESSION['flash_msg'];
    } else {
        $success_message = $_SESSION['flash_msg'];
    }
    unset($_SESSION['flash_msg'], $_SESSION['flash_is_error']);
}

// ── POST handler ──────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action   = $_POST['action'] ?? '';
    $base_url = strtok($_SERVER['REQUEST_URI'], '?'); // path without query string

    try {
        $conn->beginTransaction();

        // ───── COUPON CRUD ─────
        if ($action === 'create_coupon') {
            $required = ['fiscal_year','month_nep','vehicle_id','fuel_type','allocated_qty'];
            foreach ($required as $f) {
                if (empty($_POST[$f]) && $_POST[$f] !== '0') throw new Exception("Field '{$f}' is required");
            }
            $conn->prepare("
                INSERT INTO fuel_coupons
                    (fiscal_year, month_nep, vehicle_id, fuel_type, allocated_qty, carry_forward_qty,
                     issued_date_nep, issued_date_eng, coupon_no, pump_name,
                     verified_with_pump, paid_status, fuel_expense_type, remarks, created_by)
                VALUES
                    (:fy, :mn, :vid, :ft, :aq, :cfq,
                     :idn, :ide, :cno, :pump,
                     :vwp, :ps, :fet, :rem, :cb)
            ")->execute([
                ':fy'   => $_POST['fiscal_year'],
                ':mn'   => $_POST['month_nep'],
                ':vid'  => $_POST['vehicle_id'],
                ':ft'   => $_POST['fuel_type'],
                ':aq'   => $_POST['allocated_qty'],
                ':cfq'  => $_POST['carry_forward_qty'] ?: 0,
                ':idn'  => $_POST['issued_date_nep'] ?: null,
                ':ide'  => $_POST['issued_date_eng'] ?: null,
                ':cno'  => $_POST['coupon_no'] ?: null,
                ':pump' => $_POST['pump_name'] ?: null,
                ':vwp'  => isset($_POST['verified_with_pump']) ? 1 : 0,
                ':ps'   => isset($_POST['paid_status']) ? 1 : 0,
                ':fet'  => $_POST['fuel_expense_type'] ?: null,
                ':rem'  => $_POST['remarks'] ?: null,
                ':cb'   => $logged_user_id,
            ]);
            $conn->commit();
            prg_redirect($base_url . '?tab=coupon-view', "Coupon created successfully!");

        } elseif ($action === 'update_coupon') {
            $conn->prepare("
                UPDATE fuel_coupons SET
                    fiscal_year=:fy, month_nep=:mn, vehicle_id=:vid, fuel_type=:ft,
                    allocated_qty=:aq, carry_forward_qty=:cfq,
                    issued_date_nep=:idn, issued_date_eng=:ide, coupon_no=:cno,
                    pump_name=:pump, verified_with_pump=:vwp, paid_status=:ps,
                    fuel_expense_type=:fet, remarks=:rem,
                    updated_by=:ub, updated_at=CURRENT_TIMESTAMP
                WHERE coupon_id=:cid AND deleted_at IS NULL
            ")->execute([
                ':fy'   => $_POST['fiscal_year'],
                ':mn'   => $_POST['month_nep'],
                ':vid'  => $_POST['vehicle_id'],
                ':ft'   => $_POST['fuel_type'],
                ':aq'   => $_POST['allocated_qty'],
                ':cfq'  => $_POST['carry_forward_qty'] ?: 0,
                ':idn'  => $_POST['issued_date_nep'] ?: null,
                ':ide'  => $_POST['issued_date_eng'] ?: null,
                ':cno'  => $_POST['coupon_no'] ?: null,
                ':pump' => $_POST['pump_name'] ?: null,
                ':vwp'  => isset($_POST['verified_with_pump']) ? 1 : 0,
                ':ps'   => isset($_POST['paid_status']) ? 1 : 0,
                ':fet'  => $_POST['fuel_expense_type'] ?: null,
                ':rem'  => $_POST['remarks'] ?: null,
                ':ub'   => $logged_user_id,
                ':cid'  => $_POST['coupon_id'],
            ]);
            $conn->commit();
            prg_redirect($base_url . '?tab=coupon-view', "Coupon #" . intval($_POST['coupon_id']) . " updated successfully!");

        } elseif ($action === 'delete_coupon') {
            $conn->prepare("UPDATE fuel_coupons SET deleted_at=CURRENT_TIMESTAMP WHERE coupon_id=:id")
                 ->execute([':id' => $_POST['coupon_id']]);
            $conn->commit();
            prg_redirect($base_url . '?tab=coupon-view', "Coupon deleted.");

        // ───── DISTRIBUTION CRUD ─────
        } elseif ($action === 'create_distribution') {
            $required = ['coupon_id','disburse_date_nep','disburse_date_eng','disburse_qty','rate_per_liter'];
            foreach ($required as $f) {
                if (empty($_POST[$f])) throw new Exception("Field '{$f}' is required");
            }
            $conn->prepare("
                INSERT INTO fuel_coupon_distributions
                    (coupon_id, disburse_date_nep, disburse_date_eng, disburse_qty,
                     rate_per_liter, verified_flag, remarks, fiscal_year, created_by)
                VALUES
                    (:cid, :ddn, :dde, :dq, :rpl, :vf, :rem, :fy, :cb)
            ")->execute([
                ':cid' => $_POST['coupon_id'],
                ':ddn' => $_POST['disburse_date_nep'],
                ':dde' => $_POST['disburse_date_eng'],
                ':dq'  => $_POST['disburse_qty'],
                ':rpl' => $_POST['rate_per_liter'],
                ':vf'  => isset($_POST['verified_flag']) ? 1 : 0,
                ':rem' => $_POST['remarks'] ?: null,
                ':fy'  => $_POST['fiscal_year'] ?: '2082/83',
                ':cb'  => $logged_user_id,
            ]);
            $conn->commit();
            prg_redirect($base_url . '?tab=dist-view', "Distribution record created successfully!");

        } elseif ($action === 'update_distribution') {
            $conn->prepare("
                UPDATE fuel_coupon_distributions SET
                    coupon_id=:cid, disburse_date_nep=:ddn, disburse_date_eng=:dde,
                    disburse_qty=:dq, rate_per_liter=:rpl, verified_flag=:vf,
                    remarks=:rem, fiscal_year=:fy,
                    updated_by=:ub, updated_at=CURRENT_TIMESTAMP
                WHERE distribution_id=:did AND deleted_at IS NULL
            ")->execute([
                ':cid' => $_POST['coupon_id'],
                ':ddn' => $_POST['disburse_date_nep'],
                ':dde' => $_POST['disburse_date_eng'],
                ':dq'  => $_POST['disburse_qty'],
                ':rpl' => $_POST['rate_per_liter'],
                ':vf'  => isset($_POST['verified_flag']) ? 1 : 0,
                ':rem' => $_POST['remarks'] ?: null,
                ':fy'  => $_POST['fiscal_year'] ?: '2082/83',
                ':ub'  => $logged_user_id,
                ':did' => $_POST['distribution_id'],
            ]);
            $conn->commit();
            prg_redirect($base_url . '?tab=dist-view', "Distribution #" . intval($_POST['distribution_id']) . " updated successfully!");

        } elseif ($action === 'delete_distribution') {
            $conn->prepare("UPDATE fuel_coupon_distributions SET deleted_at=CURRENT_TIMESTAMP WHERE distribution_id=:id")
                 ->execute([':id' => $_POST['distribution_id']]);
            $conn->commit();
            prg_redirect($base_url . '?tab=dist-view', "Distribution deleted.");

        } else {
            // Unknown action — just rollback and stay on page
            $conn->rollBack();
        }

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        // On error we do NOT redirect — stay on same page so user can see the error and fix it
        $error_message = $e->getMessage();
    }
}

// ── Edit records ──────────────────────────────────────────────
$edit_coupon = null;
$edit_dist   = null;
if (!empty($_GET['edit_coupon'])) {
    $s = $conn->prepare("SELECT * FROM fuel_coupons WHERE coupon_id=:id AND deleted_at IS NULL");
    $s->execute([':id' => $_GET['edit_coupon']]);
    $edit_coupon = $s->fetch(PDO::FETCH_ASSOC);
}
if (!empty($_GET['edit_dist'])) {
    $s = $conn->prepare("SELECT * FROM fuel_coupon_distributions WHERE distribution_id=:id AND deleted_at IS NULL");
    $s->execute([':id' => $_GET['edit_dist']]);
    $edit_dist = $s->fetch(PDO::FETCH_ASSOC);
}

// ── Vehicles with active driver name ─────────────────────────
$vehicles = $conn->query("
    SELECT
        v.vehicle_id,
        v.vehicle_no,
        v.vehicle_type,
        v.fuel_type,
        d.driver_name AS driver_name
    FROM vehicles v
    LEFT JOIN vehicle_driver_assignments vda
        ON vda.vehicle_id = v.vehicle_id
        AND vda.active_flag = TRUE
        AND vda.deleted_at IS NULL
    LEFT JOIN drivers d
        ON d.driver_id = vda.driver_id
        AND d.deleted_at IS NULL
    WHERE v.deleted_at IS NULL
      AND v.status = TRUE
    ORDER BY v.vehicle_no
")->fetchAll(PDO::FETCH_ASSOC);

// Build JS lookup: vehicle_id => {fuel_type, driver_name, vehicle_type}
$vehicles_js = [];
foreach ($vehicles as $v) {
    $vehicles_js[$v['vehicle_id']] = [
        'fuel_type'    => $v['fuel_type']    ?? '',
        'driver_name'  => $v['driver_name']  ?? '',
        'vehicle_type' => $v['vehicle_type'] ?? '',
        'vehicle_no'   => $v['vehicle_no'],
    ];
}

// ── FIX: Coupons for distribution dropdown WITH remaining qty calculated ──
// Only fetch coupons where remaining qty > 0
$coupons_for_dist = $conn->query("
    SELECT
        fc.coupon_id,
        fc.coupon_no,
        fc.issued_date_nep,
        fc.issued_date_eng,
        fc.fuel_type,
        fc.month_nep,
        fc.fiscal_year,
        fc.total_available_qty,
        COALESCE(SUM(fcd.disburse_qty), 0) AS total_distributed,
        (fc.total_available_qty - COALESCE(SUM(fcd.disburse_qty), 0)) AS remaining_qty,
        v.vehicle_no,
        v.vehicle_type,
        d.driver_name AS driver_name
    FROM fuel_coupons fc
    LEFT JOIN vehicles v ON fc.vehicle_id = v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda
        ON vda.vehicle_id = v.vehicle_id
        AND vda.active_flag = TRUE
        AND vda.deleted_at IS NULL
    LEFT JOIN drivers d
        ON d.driver_id = vda.driver_id
        AND d.deleted_at IS NULL
    LEFT JOIN fuel_coupon_distributions fcd
        ON fcd.coupon_id = fc.coupon_id
        AND fcd.deleted_at IS NULL
    WHERE fc.deleted_at IS NULL
    GROUP BY fc.coupon_id, fc.coupon_no, fc.issued_date_nep, fc.issued_date_eng,
             fc.fuel_type, fc.month_nep, fc.fiscal_year, fc.total_available_qty,
             v.vehicle_no, v.vehicle_type, d.driver_name
    HAVING (fc.total_available_qty - COALESCE(SUM(fcd.disburse_qty), 0)) > 0
    ORDER BY fc.issued_date_eng DESC NULLS LAST, fc.coupon_no
")->fetchAll(PDO::FETCH_ASSOC);

// ── FIX: Fuel price history - store as YYYY-MM-DD for proper JS date comparison ──
$all_active_prices = $conn->query("
    SELECT fuel_type, rate_per_liter,
           TO_CHAR(effective_from_date_eng, 'YYYY-MM-DD') AS effective_from_date_eng,
           CASE WHEN effective_to_date_eng IS NOT NULL
                THEN TO_CHAR(effective_to_date_eng, 'YYYY-MM-DD')
                ELSE NULL END AS effective_to_date_eng,
           effective_from_date_nep, month_nep
    FROM fuel_price_history
    WHERE is_active = TRUE AND deleted_at IS NULL
    ORDER BY fuel_type, effective_from_date_eng ASC
")->fetchAll(PDO::FETCH_ASSOC);

// ── Coupons list (paginated) ──────────────────────────────────
$coupon_page     = max(1, intval($_GET['cp'] ?? 1));
$coupon_per_page = 10;
$coupon_search   = trim($_GET['cs'] ?? '');
$coupon_offset   = ($coupon_page - 1) * $coupon_per_page;

$coupon_where  = "WHERE fc.deleted_at IS NULL";
$coupon_params = [];
if ($coupon_search !== '') {
    $coupon_where .= " AND (fc.coupon_no ILIKE :cs OR v.vehicle_no ILIKE :cs
                          OR fc.month_nep ILIKE :cs OR fc.fuel_type ILIKE :cs
                          OR d.driver_name ILIKE :cs)";
    $coupon_params[':cs'] = '%' . $coupon_search . '%';
}

$cnt_s = $conn->prepare("
    SELECT COUNT(*) FROM fuel_coupons fc
    LEFT JOIN vehicles v ON fc.vehicle_id=v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda
        ON vda.vehicle_id=v.vehicle_id AND vda.active_flag=TRUE AND vda.deleted_at IS NULL
    LEFT JOIN drivers d ON d.driver_id=vda.driver_id AND d.deleted_at IS NULL
    $coupon_where
");
$cnt_s->execute($coupon_params);
$coupon_total = $cnt_s->fetchColumn();
$coupon_pages = max(1, ceil($coupon_total / $coupon_per_page));

$c_stmt = $conn->prepare("
    SELECT fc.*, v.vehicle_no, v.vehicle_type, d.driver_name AS driver_name
    FROM fuel_coupons fc
    LEFT JOIN vehicles v ON fc.vehicle_id=v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda
        ON vda.vehicle_id=v.vehicle_id AND vda.active_flag=TRUE AND vda.deleted_at IS NULL
    LEFT JOIN drivers d ON d.driver_id=vda.driver_id AND d.deleted_at IS NULL
    $coupon_where
    ORDER BY fc.created_at DESC
    LIMIT $coupon_per_page OFFSET $coupon_offset
");
$c_stmt->execute($coupon_params);
$coupons = $c_stmt->fetchAll(PDO::FETCH_ASSOC);

// ── Distribution list (paginated) ────────────────────────────
$dist_page     = max(1, intval($_GET['dp'] ?? 1));
$dist_per_page = 10;
$dist_search   = trim($_GET['ds'] ?? '');
$dist_offset   = ($dist_page - 1) * $dist_per_page;

$dist_where  = "WHERE dist.deleted_at IS NULL";
$dist_params = [];
if ($dist_search !== '') {
    $dist_where .= " AND (fc.coupon_no ILIKE :ds OR v.vehicle_no ILIKE :ds
                         OR dist.disburse_date_nep ILIKE :ds OR dr.driver_name ILIKE :ds)";
    $dist_params[':ds'] = '%' . $dist_search . '%';
}

$cnt_d = $conn->prepare("
    SELECT COUNT(*) FROM fuel_coupon_distributions dist
    LEFT JOIN fuel_coupons fc ON dist.coupon_id=fc.coupon_id
    LEFT JOIN vehicles v ON fc.vehicle_id=v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda
        ON vda.vehicle_id=v.vehicle_id AND vda.active_flag=TRUE AND vda.deleted_at IS NULL
    LEFT JOIN drivers dr ON dr.driver_id=vda.driver_id AND dr.deleted_at IS NULL
    $dist_where
");
$cnt_d->execute($dist_params);
$dist_total = $cnt_d->fetchColumn();
$dist_pages = max(1, ceil($dist_total / $dist_per_page));

$d_stmt = $conn->prepare("
    SELECT dist.*, fc.coupon_no, fc.fuel_type, fc.month_nep,
           v.vehicle_no, v.vehicle_type, dr.driver_name AS driver_name
    FROM fuel_coupon_distributions dist
    LEFT JOIN fuel_coupons fc ON dist.coupon_id=fc.coupon_id
    LEFT JOIN vehicles v ON fc.vehicle_id=v.vehicle_id
    LEFT JOIN vehicle_driver_assignments vda
        ON vda.vehicle_id=v.vehicle_id AND vda.active_flag=TRUE AND vda.deleted_at IS NULL
    LEFT JOIN drivers dr ON dr.driver_id=vda.driver_id AND dr.deleted_at IS NULL
    $dist_where
    ORDER BY dist.disburse_date_eng DESC NULLS LAST, dist.created_at DESC
    LIMIT $dist_per_page OFFSET $dist_offset
");
$d_stmt->execute($dist_params);
$distributions = $d_stmt->fetchAll(PDO::FETCH_ASSOC);

// Active tab
$active_tab = 'coupon-view';
if (!empty($_GET['edit_coupon'])) $active_tab = 'coupon-create';
if (!empty($_GET['tab']))         $active_tab = $_GET['tab'];
if (!empty($_GET['edit_dist']))   $active_tab = 'dist-view';
?>
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet"/>
<style>
body{font-family:'Segoe UI',sans-serif;background:#f5f7fa}
.container{max-width:1400px;margin:0 auto;padding:20px}
.page-title{font-size:26px;font-weight:700;color:#333;margin-bottom:5px}
.page-subtitle{color:#6c757d;font-size:14px;margin-bottom:20px}
.alert{padding:14px 18px;border-radius:8px;margin-bottom:16px;font-weight:500}
.alert-success{background:#d4edda;color:#155724;border:1px solid #c3e6cb}
.alert-danger{background:#f8d7da;color:#721c24;border:1px solid #f5c6cb}

/* Tabs */
.tabs{display:flex;gap:4px;border-bottom:2px solid #dee2e6;margin-bottom:22px;flex-wrap:wrap}
.tab{padding:10px 18px;background:none;border:none;color:#6c757d;font-size:13px;font-weight:600;
     cursor:pointer;border-bottom:3px solid transparent;transition:all .2s;border-radius:6px 6px 0 0}
.tab.active{color:#007bff;border-bottom-color:#007bff;background:#f0f7ff}
.tab:hover:not(.active){background:#f8f9fa;color:#495057}
.tab-content{display:none}
.tab-content.active{display:block}

/* Form */
.form-box{background:#fff;border-radius:10px;padding:24px;box-shadow:0 2px 10px rgba(0,0,0,.08);margin-bottom:20px}
.form-box h3{margin:0 0 18px 0;color:#333;font-size:16px}
.section-title{font-size:12px;font-weight:700;color:#6c757d;padding-bottom:8px;
               border-bottom:2px solid #e9ecef;margin-bottom:14px;text-transform:uppercase;letter-spacing:.8px}
.form-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(230px,1fr));gap:16px;margin-bottom:16px}
.fg{display:flex;flex-direction:column}
.fg label{font-size:13px;font-weight:600;color:#495057;margin-bottom:5px}
.req::after{content:' *';color:#dc3545}
input,select,textarea{padding:9px 12px;border:1px solid #ced4da;border-radius:6px;font-size:14px;
    width:100%;box-sizing:border-box;font-family:inherit;transition:border-color .2s;color:#333}
input:focus,select:focus,textarea:focus{outline:none;border-color:#007bff;box-shadow:0 0 0 3px rgba(0,123,255,.12)}
input[readonly]{background:#f8f9fa;cursor:default}
textarea{resize:vertical;min-height:60px}
.chk-row{display:flex;align-items:center;gap:8px;margin-top:6px}
.chk-row input[type=checkbox]{width:17px;height:17px;cursor:pointer}
.form-actions{display:flex;gap:10px;margin-top:18px;padding-top:14px;border-top:1px solid #e9ecef;flex-wrap:wrap}
small.hint{color:#6c757d;font-size:11px;margin-top:3px}

/* Vehicle info box */
.veh-info{background:#e8f4f8;border:1px solid #90cdf4;border-radius:6px;padding:8px 12px;
           margin-top:6px;font-size:13px;display:none}
.veh-info.shown{display:flex;gap:16px;align-items:center;flex-wrap:wrap}
.veh-info .vi-fuel{font-weight:700;color:#1a56db;font-size:14px}
.veh-info .vi-driver{color:#374151}

/* Qty summary */
.qty-summary{display:inline-flex;gap:10px;font-size:12px;background:#f0fff4;
             border:1px solid #b7ebc6;border-radius:6px;padding:8px 14px;margin-top:4px;flex-wrap:wrap}
.qs-item{display:flex;flex-direction:column;align-items:center}
.qs-val{font-weight:700;color:#155724;font-size:16px}
.qs-lbl{color:#6c757d;font-size:10px;margin-top:2px}
.qs-sep{font-size:20px;color:#adb5bd;padding-top:4px}

/* Rate preview */
.rate-preview{background:#fff8e1;border:1px solid #ffe082;border-radius:6px;
              padding:8px 12px;margin-top:6px;font-size:13px;display:none}
.rate-preview.shown{display:block}
.rate-preview .rp-main{font-weight:700;font-size:15px;color:#b45309}
.rate-preview .rp-note{color:#78350f;font-size:11px;display:block;margin-top:2px}

/* Coupon price info */
#coupon-price-info{font-size:13px;color:#004085;background:#f0f7ff;
                   border:1px solid #b8daff;border-radius:6px;padding:8px 12px;
                   margin-top:6px;display:none;line-height:1.9}
#coupon-price-info.shown{display:block}

/* Distribution summary box */
.dist-summary-box{background:#e7f3ff;border-left:4px solid #007bff;border-radius:6px;
                  padding:12px 16px;margin-top:12px;display:none}
.dist-summary-box.shown{display:block}
.dist-summary-box h4{margin:0 0 8px 0;color:#004085;font-size:13px}
.dsb-grid{display:flex;gap:20px;flex-wrap:wrap}
.dsb-item .dsi-lbl{font-size:11px;color:#6c757d}
.dsb-item .dsi-val{font-size:16px;font-weight:700;color:#333}

/* Buttons */
.btn{padding:9px 18px;border:none;border-radius:7px;font-size:13px;font-weight:600;
     cursor:pointer;display:inline-flex;align-items:center;gap:5px;text-decoration:none;transition:all .2s}
.btn:hover{filter:brightness(.9);transform:translateY(-1px)}
.btn-primary{background:#007bff;color:#fff}
.btn-secondary{background:#6c757d;color:#fff}
.btn-warning{background:#ffc107;color:#212529}
.btn-danger{background:#dc3545;color:#fff}
.btn-info{background:#17a2b8;color:#fff}
.btn-sm{padding:4px 9px;font-size:12px}

/* Table */
.tbl-wrap{background:#fff;border-radius:10px;overflow-x:auto;box-shadow:0 2px 8px rgba(0,0,0,.08)}
.tbl-header{padding:14px 18px;display:flex;justify-content:space-between;align-items:center;
            flex-wrap:wrap;gap:10px;border-bottom:1px solid #e9ecef}
.tbl-header h3{margin:0;font-size:15px;color:#333}
.search-bar{display:flex;gap:8px;align-items:center}
.search-bar input{width:230px;padding:7px 12px;font-size:13px}
table{width:100%;border-collapse:collapse;font-size:13px}
th{background:#f8f9fa;padding:10px 10px;text-align:left;font-weight:700;color:#495057;
   border-bottom:2px solid #dee2e6;white-space:nowrap;font-size:11px;text-transform:uppercase}
td{padding:9px 10px;border-bottom:1px solid #f0f0f0;vertical-align:middle}
tr:hover td{background:#fafafa}
.badge{display:inline-block;padding:3px 9px;border-radius:10px;font-size:11px;font-weight:600}
.badge-petrol{background:#fff3cd;color:#856404}
.badge-diesel{background:#cfe2ff;color:#084298}
.badge-mobil{background:#e2d9f3;color:#5a3d8a}
.badge-yes{background:#d4edda;color:#155724}
.badge-no{background:#f8d7da;color:#721c24}

/* Pagination */
.pagination{display:flex;gap:5px;padding:12px 18px;align-items:center;flex-wrap:wrap}
.pg-btn{padding:5px 11px;border:1px solid #dee2e6;background:#fff;border-radius:6px;
        font-size:13px;text-decoration:none;color:#333;transition:all .2s}
.pg-btn:hover{background:#e9ecef}
.pg-btn.active{background:#007bff;color:#fff;border-color:#007bff}
.pg-info{color:#6c757d;font-size:12px;margin-left:8px}
.date-display{padding:9px 12px;border:1px solid #ced4da;border-radius:6px;min-height:38px;
              background:#fff;cursor:pointer;font-size:14px;color:#333}
</style>

<div class="container">
<h1 class="page-title">⛽ Fuel Coupons Management</h1>
<p class="page-subtitle">Manage fuel coupons and distributions. Fuel type auto-fills from vehicle. Rate auto-fills from price history by date.</p>

<?php if ($error_message): ?>
    <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
<?php endif; ?>
<?php if ($success_message): ?>
    <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
<?php endif; ?>

<div class="tabs">
    <button class="tab" data-tab="coupon-create" onclick="switchTab('coupon-create',this)">
        <?= $edit_coupon ? '✏️ Edit Coupon #'.$edit_coupon['coupon_id'] : '➕ Create Coupon' ?>
    </button>
    <button class="tab" data-tab="coupon-view" onclick="switchTab('coupon-view',this)">📋 View Coupons</button>
    <button class="tab" data-tab="dist-add" onclick="switchTab('dist-add',this)">
        <?= $edit_dist ? '✏️ Edit Distribution #'.$edit_dist['distribution_id'] : '🚚 Add Distribution' ?>
    </button>
    <button class="tab" data-tab="dist-view" onclick="switchTab('dist-view',this)">📊 View Distributions</button>
</div>

<!-- ══ TAB 1: CREATE / EDIT COUPON ══ -->
<div id="coupon-create" class="tab-content">
<div class="form-box">
    <h3><?= $edit_coupon ? '✏️ Edit Coupon #'.$edit_coupon['coupon_id'] : '➕ Create New Coupon' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $edit_coupon ? 'update_coupon' : 'create_coupon' ?>">
        <?php if ($edit_coupon): ?>
        <input type="hidden" name="coupon_id" value="<?= $edit_coupon['coupon_id'] ?>">
        <?php endif; ?>

        <div class="section-title">📌 Coupon Details</div>
        <div class="form-grid">
            <div class="fg">
                <label class="req">Fiscal Year</label>
                <select name="fiscal_year" required>
                    <?php foreach (['2082/83','2083/84','2084/85','2081/82','2080/81'] as $fy): ?>
                    <option value="<?= $fy ?>" <?= (($edit_coupon['fiscal_year'] ?? '2082/83')===$fy)?'selected':'' ?>><?= $fy ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label class="req">Nepali Month</label>
                <select name="month_nep" required>
                    <option value="">Select Month</option>
                    <?php foreach ($nepali_months as $m): ?>
                    <option value="<?= $m ?>" <?= (($edit_coupon['month_nep'] ?? '')===$m)?'selected':'' ?>><?= $m ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label class="req">Vehicle</label>
                <select name="vehicle_id" id="coupon_vehicle_id" required onchange="onVehicleChange(this.value)">
                    <option value="">— Select Vehicle —</option>
                    <?php foreach ($vehicles as $v):
                        $lbl = $v['vehicle_no'];
                        if ($v['vehicle_type']) $lbl .= ' ['.ucfirst($v['vehicle_type']).']';
                        if ($v['driver_name'])  $lbl .= ' ('.$v['driver_name'].')';
                    ?>
                    <option value="<?= $v['vehicle_id'] ?>"
                            data-fuel="<?= htmlspecialchars($v['fuel_type'] ?? '') ?>"
                            data-driver="<?= htmlspecialchars($v['driver_name'] ?? '') ?>"
                            data-type="<?= htmlspecialchars($v['vehicle_type'] ?? '') ?>"
                            <?= (($edit_coupon['vehicle_id'] ?? 0)==$v['vehicle_id'])?'selected':'' ?>>
                        <?= htmlspecialchars($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div class="veh-info" id="coupon-veh-info">
                    <span class="vi-fuel" id="coupon-vi-fuel"></span>
                    <span class="vi-driver" id="coupon-vi-driver"></span>
                </div>
            </div>
            <div class="fg">
                <label class="req">Fuel Type</label>
                <select name="fuel_type" id="coupon_fuel_type" required>
                    <option value="">Select Fuel</option>
                    <?php foreach ($fuel_types as $ft): ?>
                    <option value="<?= $ft ?>" <?= (($edit_coupon['fuel_type'] ?? '')===$ft)?'selected':'' ?>><?= ucfirst($ft) ?></option>
                    <?php endforeach; ?>
                </select>
                <small class="hint">⚡ Auto-set from vehicle's default fuel type when vehicle is selected.</small>
            </div>
            <div class="fg">
                <label>Coupon No.</label>
                <input type="text" name="coupon_no" placeholder="CPN-2082-001"
                       value="<?= htmlspecialchars($edit_coupon['coupon_no'] ?? '') ?>">
            </div>
            <!-- FIX: Pump Name as dropdown like old file -->
            <div class="fg">
                <label>Pump Name</label>
                <select name="pump_name">
                    <?php foreach ($pump_names as $p): ?>
                    <option value="<?= htmlspecialchars($p) ?>"
                        <?= (($edit_coupon['pump_name'] ?? 'Om Sai Oil Pvt. Ltd.')===$p) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($p) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <div class="section-title">📦 Quantity Details</div>
        <div class="form-grid">
            <div class="fg">
                <label class="req">Allocated Qty (L)</label>
                <input type="number" step="0.01" name="allocated_qty" id="allocated_qty"
                       placeholder="100.00"
                       value="<?= htmlspecialchars($edit_coupon['allocated_qty'] ?? '') ?>"
                       oninput="updateTotalQty()" required>
            </div>
            <div class="fg">
                <label>Carry Forward (L)</label>
                <input type="number" step="0.01" name="carry_forward_qty" id="carry_forward_qty"
                       placeholder="0.00"
                       value="<?= htmlspecialchars($edit_coupon['carry_forward_qty'] ?? 0) ?>"
                       oninput="updateTotalQty()">
            </div>
            <div class="fg">
                <label>Total Available (L) <small style="color:#28a745;font-weight:400;">(auto-calculated)</small></label>
                <div class="qty-summary">
                    <div class="qs-item"><span class="qs-val" id="disp-alloc">—</span><span class="qs-lbl">Allocated</span></div>
                    <span class="qs-sep">+</span>
                    <div class="qs-item"><span class="qs-val" id="disp-cf">—</span><span class="qs-lbl">Carry Fwd</span></div>
                    <span class="qs-sep">=</span>
                    <div class="qs-item"><span class="qs-val" id="disp-total" style="font-size:18px;color:#28a745;">—</span><span class="qs-lbl">Total (L)</span></div>
                </div>
                <small class="hint">Saved as <code>total_available_qty</code> (DB generated column).</small>
            </div>
        </div>

        <div class="section-title">📅 Issue Date</div>
        <div class="form-grid">
            <div class="fg">
                <label>Issued Date (Nepali BS)</label>
                <input type="text" name="issued_date_nep" id="coupon_issued_nep"
                       class="ndp-input" placeholder="२०८२.०८.०१"
                       value="<?= htmlspecialchars($edit_coupon['issued_date_nep'] ?? '') ?>">
            </div>
            <div class="fg">
                <label>Issued Date (English AD)</label>
                <input type="hidden" name="issued_date_eng" id="coupon_issued_eng_h"
                       value="<?= htmlspecialchars($edit_coupon['issued_date_eng'] ?? '') ?>">
                <div style="position:relative;">
                    <div class="date-display" id="coupon_issued_eng_d">
                        <?= htmlspecialchars($edit_coupon['issued_date_eng'] ?? '') ?>
                    </div>
                    <input type="date" id="coupon_issued_eng_n"
                           value="<?= htmlspecialchars($edit_coupon['issued_date_eng'] ?? '') ?>"
                           style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:2;">
                </div>
            </div>
        </div>

        <div class="section-title">⚙️ Other Settings</div>
        <div class="form-grid">
            <!-- FIX: Fuel Expense Type as predefined dropdown like old file -->
            <div class="fg">
                <label>Fuel Expense Type</label>
                <select name="fuel_expense_type">
                    <option value="">-- Select Expense Type --</option>
                    <?php foreach ($fuel_expense_types as $k => $lbl): ?>
                    <option value="<?= $k ?>"
                        <?= (($edit_coupon['fuel_expense_type'] ?? '')===$k) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="fg">
                <label>Flags</label>
                <div class="chk-row">
                    <input type="checkbox" name="verified_with_pump" id="chk_vwp"
                           <?= !empty($edit_coupon['verified_with_pump']) ? 'checked' : '' ?>>
                    <label for="chk_vwp" style="margin:0;font-weight:400;">Verified with Pump</label>
                </div>
                <div class="chk-row">
                    <input type="checkbox" name="paid_status" id="chk_ps"
                           <?= !empty($edit_coupon['paid_status']) ? 'checked' : '' ?>>
                    <label for="chk_ps" style="margin:0;font-weight:400;">Paid</label>
                </div>
            </div>
            <div class="fg" style="grid-column:1/-1;">
                <label>Remarks</label>
                <textarea name="remarks" rows="2"><?= htmlspecialchars($edit_coupon['remarks'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <?php if ($edit_coupon): ?>
            <a href="?tab=coupon-view" class="btn btn-secondary">↩ Cancel</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <?= $edit_coupon ? '💾 Update Coupon' : '✅ Create Coupon' ?>
            </button>
        </div>
    </form>
</div>
</div>

<!-- ══ TAB 2: VIEW COUPONS ══ -->
<div id="coupon-view" class="tab-content">
<div class="tbl-wrap">
    <div class="tbl-header">
        <h3>📋 Coupons <span style="font-size:13px;font-weight:400;color:#6c757d;">(<?= $coupon_total ?> records)</span></h3>
        <form method="GET" class="search-bar" style="margin:0;">
            <input type="hidden" name="tab" value="coupon-view">
            <input type="text" name="cs" placeholder="Coupon no, vehicle, month, driver..."
                   value="<?= htmlspecialchars($coupon_search) ?>">
            <button type="submit" class="btn btn-info btn-sm">🔍 Search</button>
            <?php if ($coupon_search): ?><a href="?tab=coupon-view" class="btn btn-secondary btn-sm">✕</a><?php endif; ?>
            <a href="?tab=coupon-create" class="btn btn-primary btn-sm">➕ New</a>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th><th>Coupon No</th><th>Vehicle</th><th>Driver</th>
                <th>FY / Month</th><th>Fuel</th><th>Alloc (L)</th><th>Carry (L)</th>
                <th>Total (L)</th><th>Issued (BS)</th><th>Pump</th><th>Exp. Type</th><th>Verified</th><th>Paid</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($coupons)): ?>
            <tr><td colspan="15" style="text-align:center;padding:40px;color:#6c757d;">
                No coupons found.<?= $coupon_search?' Try clearing search.':'' ?>
            </td></tr>
        <?php else: foreach ($coupons as $c): ?>
        <tr>
            <td><?= $c['coupon_id'] ?></td>
            <td><strong><?= htmlspecialchars($c['coupon_no'] ?? '—') ?></strong></td>
            <td>
                <?= htmlspecialchars($c['vehicle_no'] ?? '—') ?>
                <?php if ($c['vehicle_type']): ?>
                <br><small style="color:#6c757d;"><?= ucfirst($c['vehicle_type']) ?></small>
                <?php endif; ?>
            </td>
            <td><?= htmlspecialchars($c['driver_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['fiscal_year']) ?><br><small><?= htmlspecialchars($c['month_nep']) ?></small></td>
            <td><span class="badge badge-<?= $c['fuel_type'] ?>"><?= strtoupper($c['fuel_type']) ?></span></td>
            <td><?= number_format($c['allocated_qty'],2) ?></td>
            <td><?= number_format($c['carry_forward_qty'],2) ?></td>
            <td><strong style="color:#28a745;"><?= number_format($c['total_available_qty'],2) ?></strong></td>
            <td><?= htmlspecialchars($c['issued_date_nep'] ?? '—') ?></td>
            <td><?= htmlspecialchars($c['pump_name'] ?? '—') ?></td>
            <td><?= htmlspecialchars($fuel_expense_types[$c['fuel_expense_type'] ?? ''] ?? ($c['fuel_expense_type'] ?? '—')) ?></td>
            <td><span class="badge badge-<?= $c['verified_with_pump']?'yes':'no' ?>"><?= $c['verified_with_pump']?'✓':'✗' ?></span></td>
            <td><span class="badge badge-<?= $c['paid_status']?'yes':'no' ?>"><?= $c['paid_status']?'✓':'✗' ?></span></td>
            <td style="white-space:nowrap;">
                <a href="?edit_coupon=<?= $c['coupon_id'] ?>" class="btn btn-warning btn-sm">✏️</a>
                <form method="POST" style="display:inline;" onsubmit="return confirm('Delete coupon #<?= $c['coupon_id'] ?>?')">
                    <input type="hidden" name="action"    value="delete_coupon">
                    <input type="hidden" name="coupon_id" value="<?= $c['coupon_id'] ?>">
                    <button class="btn btn-danger btn-sm">🗑️</button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php if ($coupon_pages > 1):
        $pb = '?tab=coupon-view'.($coupon_search?'&cs='.urlencode($coupon_search):''); ?>
    <div class="pagination">
        <?php if ($coupon_page>1): ?><a href="<?= $pb ?>&cp=<?= $coupon_page-1 ?>" class="pg-btn">‹ Prev</a><?php endif; ?>
        <?php for ($pg=max(1,$coupon_page-2);$pg<=min($coupon_pages,$coupon_page+2);$pg++): ?>
        <a href="<?= $pb ?>&cp=<?= $pg ?>" class="pg-btn <?= $pg==$coupon_page?'active':'' ?>"><?= $pg ?></a>
        <?php endfor; ?>
        <?php if ($coupon_page<$coupon_pages): ?><a href="<?= $pb ?>&cp=<?= $coupon_page+1 ?>" class="pg-btn">Next ›</a><?php endif; ?>
        <span class="pg-info">Page <?= $coupon_page ?>/<?= $coupon_pages ?> | <?= $coupon_total ?> records</span>
    </div>
    <?php endif; ?>
</div>
</div>

<!-- ══ TAB 3: ADD / EDIT DISTRIBUTION ══ -->
<div id="dist-add" class="tab-content">
<div class="form-box">
    <h3><?= $edit_dist ? '✏️ Edit Distribution #'.$edit_dist['distribution_id'] : '🚚 Add Distribution' ?></h3>
    <form method="POST">
        <input type="hidden" name="action" value="<?= $edit_dist ? 'update_distribution' : 'create_distribution' ?>">
        <?php if ($edit_dist): ?>
        <input type="hidden" name="distribution_id" value="<?= $edit_dist['distribution_id'] ?>">
        <?php endif; ?>
        <input type="hidden" name="fiscal_year" id="dist_fiscal_year" value="2082/83">

        <div class="section-title">📋 Select Coupon</div>
        <div class="form-grid">
            <div class="fg" style="grid-column:1/-1;">
                <!-- FIX: Label updated, remaining qty shown, zero/negative hidden via PHP HAVING clause -->
                <label class="req">Coupon (Coupon No | Issue Date | Vehicle (Driver) | Fuel | Remaining Qty)</label>
                <select name="coupon_id" id="dist_coupon_id" required onchange="onCouponChange(this.value)">
                    <option value="">— Select a Coupon —</option>
                    <?php foreach ($coupons_for_dist as $c):
                        $lbl  = ($c['coupon_no'] ?: '#'.$c['coupon_id']);
                        $lbl .= ' | '.($c['issued_date_nep'] ?: ($c['issued_date_eng'] ?: 'No date'));
                        $lbl .= ' | '.($c['vehicle_no'] ?: '?');
                        if ($c['driver_name']) $lbl .= ' ('.$c['driver_name'].')';
                        $lbl .= ' | '.strtoupper($c['fuel_type']);
                        // FIX: Show remaining qty instead of total_available_qty
                        $lbl .= ' | Rem: '.number_format($c['remaining_qty'],2).'L';
                    ?>
                    <option value="<?= $c['coupon_id'] ?>"
                            data-fuel="<?= htmlspecialchars($c['fuel_type']) ?>"
                            data-month="<?= htmlspecialchars($c['month_nep']) ?>"
                            data-fy="<?= htmlspecialchars($c['fiscal_year']) ?>"
                            data-total="<?= htmlspecialchars($c['total_available_qty']) ?>"
                            data-distributed="<?= htmlspecialchars($c['total_distributed']) ?>"
                            data-remaining="<?= htmlspecialchars($c['remaining_qty']) ?>"
                            <?= ($edit_dist && $edit_dist['coupon_id']==$c['coupon_id'])?'selected':'' ?>>
                        <?= htmlspecialchars($lbl) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
                <div id="coupon-price-info"></div>
                <!-- Distribution summary box like old file -->
                <div class="dist-summary-box" id="dist-summary-box">
                    <h4>📊 Coupon Balance</h4>
                    <div class="dsb-grid">
                        <div class="dsb-item"><div class="dsi-lbl">Total Allocated</div><div class="dsi-val" id="dsb-total">—</div></div>
                        <div class="dsb-item"><div class="dsi-lbl">Already Distributed</div><div class="dsi-val" id="dsb-distributed">—</div></div>
                        <div class="dsb-item"><div class="dsi-lbl">Remaining</div><div class="dsi-val" id="dsb-remaining">—</div></div>
                        <div class="dsb-item"><div class="dsi-lbl">This Distribution</div><div class="dsi-val" id="dsb-this">—</div></div>
                        <div class="dsb-item"><div class="dsi-lbl">After This</div><div class="dsi-val" id="dsb-after">—</div></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="section-title">📅 Distribution Date</div>
        <div class="form-grid">
            <div class="fg">
                <label class="req">Date (Nepali BS)</label>
                <input type="text" name="disburse_date_nep" id="dist_date_nep"
                       class="ndp-input" placeholder="२०८२.०८.१०"
                       value="<?= htmlspecialchars($edit_dist['disburse_date_nep'] ?? '') ?>" required>
            </div>
            <div class="fg">
                <label class="req">Date (English AD)</label>
                <input type="hidden" name="disburse_date_eng" id="dist_date_eng_h"
                       value="<?= htmlspecialchars($edit_dist['disburse_date_eng'] ?? '') ?>">
                <div style="position:relative;">
                    <div class="date-display" id="dist_date_eng_d">
                        <?= htmlspecialchars($edit_dist['disburse_date_eng'] ?? '') ?>
                    </div>
                    <input type="date" id="dist_date_eng_n"
                           value="<?= htmlspecialchars($edit_dist['disburse_date_eng'] ?? '') ?>"
                           style="position:absolute;top:0;left:0;width:100%;height:100%;opacity:0;cursor:pointer;z-index:2;"
                           onchange="onDistDateNativeChange(this.value)">
                </div>
            </div>
        </div>

        <div class="section-title">⛽ Quantity &amp; Rate</div>
        <div class="form-grid">
            <div class="fg">
                <label class="req">Disburse Qty (L)</label>
                <input type="number" step="0.01" name="disburse_qty" id="dist_qty"
                       placeholder="50.00"
                       value="<?= htmlspecialchars($edit_dist['disburse_qty'] ?? '') ?>"
                       required oninput="calcTotal()">
            </div>
            <div class="fg">
                <label class="req">Rate per Liter (रू)</label>
                <input type="number" step="0.01" name="rate_per_liter" id="dist_rate"
                       placeholder="165.00"
                       value="<?= htmlspecialchars($edit_dist['rate_per_liter'] ?? '') ?>"
                       required oninput="calcTotal()">
                <div class="rate-preview" id="rate-preview-box">
                    <span class="rp-main" id="rp-main"></span>
                    <span class="rp-note" id="rp-note"></span>
                </div>
            </div>
            <div class="fg">
                <label>Total Amount (रू) <small style="color:#28a745;font-weight:400;">(auto)</small></label>
                <input type="text" id="dist_total_preview" readonly placeholder="—">
                <small class="hint">= Qty × Rate (DB generated column)</small>
            </div>
        </div>

        <div class="section-title">📋 Other</div>
        <div class="form-grid">
            <div class="fg">
                <label>Verified</label>
                <div class="chk-row" style="margin-top:10px;">
                    <input type="checkbox" name="verified_flag" id="chk_vflag"
                           <?= !empty($edit_dist['verified_flag']) ? 'checked' : '' ?>>
                    <label for="chk_vflag" style="margin:0;font-weight:400;">Mark as Verified</label>
                </div>
            </div>
            <div class="fg" style="grid-column:1/-1;">
                <label>Remarks</label>
                <textarea name="remarks" rows="2"><?= htmlspecialchars($edit_dist['remarks'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-actions">
            <?php if ($edit_dist): ?>
            <a href="?tab=dist-view" class="btn btn-secondary">↩ Cancel</a>
            <?php endif; ?>
            <button type="submit" class="btn btn-primary">
                <?= $edit_dist ? '💾 Update Distribution' : '✅ Add Distribution' ?>
            </button>
        </div>
    </form>
</div>
</div>

<!-- ══ TAB 4: VIEW DISTRIBUTIONS ══ -->
<div id="dist-view" class="tab-content">
<div class="tbl-wrap">
    <div class="tbl-header">
        <h3>📊 Distributions <span style="font-size:13px;font-weight:400;color:#6c757d;">(<?= $dist_total ?> records)</span></h3>
        <form method="GET" class="search-bar" style="margin:0;">
            <input type="hidden" name="tab" value="dist-view">
            <input type="text" name="ds" placeholder="Coupon, vehicle, date, driver..."
                   value="<?= htmlspecialchars($dist_search) ?>">
            <button type="submit" class="btn btn-info btn-sm">🔍 Search</button>
            <?php if ($dist_search): ?><a href="?tab=dist-view" class="btn btn-secondary btn-sm">✕</a><?php endif; ?>
            <a href="?tab=dist-add" class="btn btn-primary btn-sm">➕ Add</a>
        </form>
    </div>
    <table>
        <thead>
            <tr>
                <th>#</th><th>Coupon No</th><th>Vehicle</th><th>Driver</th><th>Fuel</th>
                <th>Date (BS)</th><th>Date (AD)</th><th>Qty (L)</th>
                <th>Rate/L (रू)</th><th>Total (रू)</th><th>Verified</th><th>Remarks</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($distributions)): ?>
            <tr><td colspan="13" style="text-align:center;padding:40px;color:#6c757d;">
                No distribution records found.<?= $dist_search?' Try clearing search.':'' ?>
            </td></tr>
        <?php else: foreach ($distributions as $d): ?>
        <tr>
            <td><?= $d['distribution_id'] ?></td>
            <td><strong><?= htmlspecialchars($d['coupon_no'] ?? '—') ?></strong></td>
            <td><?= htmlspecialchars($d['vehicle_no'] ?? '—') ?></td>
            <td><?= htmlspecialchars($d['driver_name'] ?? '—') ?></td>
            <td><span class="badge badge-<?= $d['fuel_type'] ?>"><?= strtoupper($d['fuel_type']) ?></span></td>
            <td><?= htmlspecialchars($d['disburse_date_nep']) ?></td>
            <td><?= htmlspecialchars($d['disburse_date_eng']) ?></td>
            <td><?= number_format($d['disburse_qty'],2) ?></td>
            <td>रू <?= number_format($d['rate_per_liter'],2) ?></td>
            <td><strong style="color:#28a745;">रू <?= number_format($d['total_amount'],2) ?></strong></td>
            <td><span class="badge badge-<?= $d['verified_flag']?'yes':'no' ?>"><?= $d['verified_flag']?'✓':'✗' ?></span></td>
            <td><?= htmlspecialchars($d['remarks'] ?? '—') ?></td>
            <td style="white-space:nowrap;">
                <a href="?edit_dist=<?= $d['distribution_id'] ?>" class="btn btn-warning btn-sm">✏️</a>
                <form method="POST" style="display:inline;"
                      onsubmit="return confirm('Delete distribution #<?= $d['distribution_id'] ?>?')">
                    <input type="hidden" name="action"          value="delete_distribution">
                    <input type="hidden" name="distribution_id" value="<?= $d['distribution_id'] ?>">
                    <button class="btn btn-danger btn-sm">🗑️</button>
                </form>
            </td>
        </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
    <?php if ($dist_pages > 1):
        $db = '?tab=dist-view'.($dist_search?'&ds='.urlencode($dist_search):''); ?>
    <div class="pagination">
        <?php if ($dist_page>1): ?><a href="<?= $db ?>&dp=<?= $dist_page-1 ?>" class="pg-btn">‹ Prev</a><?php endif; ?>
        <?php for ($pg=max(1,$dist_page-2);$pg<=min($dist_pages,$dist_page+2);$pg++): ?>
        <a href="<?= $db ?>&dp=<?= $pg ?>" class="pg-btn <?= $pg==$dist_page?'active':'' ?>"><?= $pg ?></a>
        <?php endfor; ?>
        <?php if ($dist_page<$dist_pages): ?><a href="<?= $db ?>&dp=<?= $dist_page+1 ?>" class="pg-btn">Next ›</a><?php endif; ?>
        <span class="pg-info">Page <?= $dist_page ?>/<?= $dist_pages ?> | <?= $dist_total ?> records</span>
    </div>
    <?php endif; ?>
</div>
</div>

</div><!-- /container -->

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>
<script>
// ── Data from PHP ─────────────────────────────────────────────
var vehiclesData    = <?= json_encode($vehicles_js) ?>;
// FIX: Prices now come as YYYY-MM-DD strings for proper JS string comparison
var allActivePrices = <?= json_encode($all_active_prices) ?>;

// ── Tab switching ─────────────────────────────────────────────
function switchTab(id, btn) {
    document.querySelectorAll('.tab-content').forEach(function(t){ t.classList.remove('active'); });
    document.querySelectorAll('.tab').forEach(function(t){ t.classList.remove('active'); });
    document.getElementById(id).classList.add('active');
    if (btn) btn.classList.add('active');
}

// ── Vehicle change → auto-fill fuel type + show info ─────────
function onVehicleChange(vehicleId) {
    var fuelSel  = document.getElementById('coupon_fuel_type');
    var infoBox  = document.getElementById('coupon-veh-info');
    var viFuel   = document.getElementById('coupon-vi-fuel');
    var viDriver = document.getElementById('coupon-vi-driver');

    if (!vehicleId || !vehiclesData[vehicleId]) {
        infoBox.classList.remove('shown');
        return;
    }
    var vd = vehiclesData[vehicleId];

    if (fuelSel && vd.fuel_type) {
        for (var i = 0; i < fuelSel.options.length; i++) {
            if (fuelSel.options[i].value === vd.fuel_type) {
                fuelSel.selectedIndex = i;
                break;
            }
        }
    }

    viFuel.textContent   = '⛽ Fuel: ' + (vd.fuel_type ? vd.fuel_type.toUpperCase() : 'N/A');
    viDriver.textContent = vd.driver_name
        ? '🧑‍✈️ Driver: ' + vd.driver_name
        : '🧑‍✈️ No active driver assigned';
    infoBox.classList.add('shown');
}

// ── FIX: Find price for a fuel type + date ────────────────────
// Dates are now YYYY-MM-DD strings — direct string comparison works correctly
function getPriceForDateAndFuel(fuelType, dateStr) {
    if (!fuelType || !dateStr) return null;
    // Normalise to YYYY-MM-DD (handle YYYY.MM.DD from Nepali picker AD output)
    var normDate = dateStr.replace(/\./g, '-');
    var best = null;
    for (var i = 0; i < allActivePrices.length; i++) {
        var p    = allActivePrices[i];
        var from = p.effective_from_date_eng; // already YYYY-MM-DD
        var to   = p.effective_to_date_eng ? p.effective_to_date_eng : '9999-12-31';
        if (p.fuel_type === fuelType && normDate >= from && normDate <= to) {
            if (!best || from > best.effective_from_date_eng) best = p;
        }
    }
    return best;
}

// ── Find all prices for a fuel type in a given month ─────────
function getPricesForFuelMonth(fuelType, monthNep) {
    return allActivePrices.filter(function(p) {
        return p.fuel_type === fuelType && (!monthNep || p.month_nep === monthNep);
    });
}

// ── Coupon selected → show price history + coupon balance ─────
function onCouponChange(couponId) {
    var infoBox    = document.getElementById('coupon-price-info');
    var summaryBox = document.getElementById('dist-summary-box');
    var fySel      = document.getElementById('dist_fiscal_year');
    var sel        = document.getElementById('dist_coupon_id');

    if (!couponId || !sel) {
        infoBox.innerHTML = ''; infoBox.classList.remove('shown');
        summaryBox.classList.remove('shown');
        return;
    }

    var opt = sel.querySelector('option[value="'+couponId+'"]');
    if (!opt) return;

    var fuelType    = opt.getAttribute('data-fuel');
    var monthNep    = opt.getAttribute('data-month');
    var fy          = opt.getAttribute('data-fy');
    var totalQty    = parseFloat(opt.getAttribute('data-total'))       || 0;
    var distributed = parseFloat(opt.getAttribute('data-distributed')) || 0;
    var remaining   = parseFloat(opt.getAttribute('data-remaining'))   || 0;

    if (fySel && fy) fySel.value = fy;

    // Update summary box
    document.getElementById('dsb-total').textContent       = totalQty.toFixed(2) + ' L';
    document.getElementById('dsb-distributed').textContent = distributed.toFixed(2) + ' L';
    document.getElementById('dsb-remaining').textContent   = remaining.toFixed(2) + ' L';
    document.getElementById('dsb-remaining').style.color   = remaining <= 0 ? '#dc3545' : '#28a745';
    summaryBox.classList.add('shown');
    updateDistAfter();

    // Price history info box
    var prices = getPricesForFuelMonth(fuelType, monthNep);
    var html   = '<strong>💰 ' + (fuelType||'').toUpperCase() + ' Price History';
    if (monthNep) html += ' — ' + monthNep;
    html += ':</strong><br>';

    if (!prices.length) {
        html += '<em style="color:#dc3545;">No active price record found for this fuel type.</em>';
    } else {
        prices.forEach(function(p) {
            var toLabel = p.effective_to_date_eng
                ? ' → ' + p.effective_to_date_eng
                : ' <strong style="color:#28a745;">Ongoing</strong>';
            html += '• <strong>रू ' + parseFloat(p.rate_per_liter).toFixed(2) + '/L</strong>';
            html += ' &nbsp; From: ' + p.effective_from_date_nep + ' (' + p.effective_from_date_eng + ')';
            html += toLabel + '<br>';
        });
    }
    infoBox.innerHTML = html;
    infoBox.classList.add('shown');

    triggerRateFetch();
}

// ── Update "after this distribution" preview ──────────────────
function updateDistAfter() {
    var sel      = document.getElementById('dist_coupon_id');
    var qtyInput = document.getElementById('dist_qty');
    if (!sel || !sel.value) return;
    var opt       = sel.querySelector('option[value="'+sel.value+'"]');
    if (!opt) return;
    var remaining = parseFloat(opt.getAttribute('data-remaining')) || 0;
    var thisQty   = parseFloat(qtyInput.value) || 0;
    var after     = remaining - thisQty;
    var afterEl   = document.getElementById('dsb-after');
    var thisEl    = document.getElementById('dsb-this');
    if (thisEl)  thisEl.textContent  = thisQty.toFixed(2) + ' L';
    if (afterEl) {
        afterEl.textContent = after.toFixed(2) + ' L';
        afterEl.style.color = after < 0 ? '#dc3545' : '#155724';
    }
}

// ── FIX: Auto-fill rate — now uses normalised YYYY-MM-DD dates ─
function triggerRateFetch() {
    var sel        = document.getElementById('dist_coupon_id');
    var dateHidden = document.getElementById('dist_date_eng_h');
    var rateInput  = document.getElementById('dist_rate');
    var previewBox = document.getElementById('rate-preview-box');
    var rateMain   = document.getElementById('rp-main');
    var rateNote   = document.getElementById('rp-note');

    if (!sel || !dateHidden) return;
    var couponId = sel.value;
    var dateStr  = dateHidden.value; // YYYY-MM-DD from hidden input
    if (!couponId || !dateStr) { previewBox.classList.remove('shown'); return; }

    var opt = sel.querySelector('option[value="'+couponId+'"]');
    if (!opt) return;

    var fuelType = opt.getAttribute('data-fuel');
    var priceRow = getPriceForDateAndFuel(fuelType, dateStr);

    if (priceRow) {
        var rate = parseFloat(priceRow.rate_per_liter).toFixed(2);
        rateInput.value = rate;
        var toLabel = priceRow.effective_to_date_eng
            ? 'Valid: ' + priceRow.effective_from_date_eng + ' → ' + priceRow.effective_to_date_eng
            : 'From: ' + priceRow.effective_from_date_nep + ' (Ongoing)';
        rateMain.textContent = '✅ Auto-filled: रू ' + rate + '/L [' + (fuelType||'').toUpperCase() + ']';
        rateNote.textContent  = toLabel;
    } else {
        rateMain.textContent = '⚠️ No price found for ' + (fuelType||'?').toUpperCase() + ' on ' + dateStr;
        rateNote.textContent  = 'Please enter rate manually.';
    }
    previewBox.classList.add('shown');
    calcTotal();
}

// ── Total preview ─────────────────────────────────────────────
function calcTotal() {
    var qty  = parseFloat(document.getElementById('dist_qty').value)  || 0;
    var rate = parseFloat(document.getElementById('dist_rate').value) || 0;
    var el   = document.getElementById('dist_total_preview');
    if (el) el.value = (qty && rate) ? 'रू ' + (qty * rate).toFixed(2) : '';
    updateDistAfter();
}

// ── Qty summary ───────────────────────────────────────────────
function updateTotalQty() {
    var a = parseFloat(document.getElementById('allocated_qty').value)     || 0;
    var c = parseFloat(document.getElementById('carry_forward_qty').value) || 0;
    document.getElementById('disp-alloc').textContent = a.toFixed(2) + 'L';
    document.getElementById('disp-cf').textContent    = c.toFixed(2) + 'L';
    document.getElementById('disp-total').textContent = (a + c).toFixed(2) + 'L';
}

// ── Nepali datepicker helper ──────────────────────────────────
function setupNepaliPicker(nepId, dispId, nativeId, hiddenId, onChangeCb) {
    var nep    = document.getElementById(nepId);
    var disp   = document.getElementById(dispId);
    var nat    = document.getElementById(nativeId);
    var hidden = document.getElementById(hiddenId);
    if (!nep) return;

    function applyAD(dotVal) {
        // FIX: Always store as YYYY-MM-DD in hidden input for consistent date comparison
        var dashVal = dotVal.replace(/\./g, '-');
        if (hidden) hidden.value     = dashVal;
        if (disp)   disp.textContent = dotVal.replace(/-/g, '.');
        if (nat)    nat.value        = dashVal;
        if (onChangeCb) onChangeCb(dashVal);
    }

    nep.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function() {
            try {
                var ad = NepaliFunctions.BS2AD(nep.value.trim(), 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (ad) applyAD(ad);
            } catch(e){}
        }
    });
    nep.addEventListener('blur', function() {
        try {
            var ad = NepaliFunctions.BS2AD(nep.value.trim(), 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (ad) applyAD(ad);
        } catch(e){}
    });
    if (nat) nat.addEventListener('change', function() {
        var dash = this.value; // native date input gives YYYY-MM-DD
        var dot  = dash.replace(/-/g, '.');
        applyAD(dot);
        try {
            var bs = NepaliFunctions.AD2BS(dot, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (bs) nep.value = bs;
        } catch(e){}
    });
}

function onDistDateNativeChange(val) {
    // val from native date input is YYYY-MM-DD
    var hid = document.getElementById('dist_date_eng_h');
    var dsp = document.getElementById('dist_date_eng_d');
    var nep = document.getElementById('dist_date_nep');
    var dot = val.replace(/-/g, '.');
    if (hid) hid.value     = val; // store as YYYY-MM-DD
    if (dsp) dsp.textContent = dot;
    if (nep) {
        try {
            var bs = NepaliFunctions.AD2BS(dot, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (bs) nep.value = bs;
        } catch(e){}
    }
    triggerRateFetch();
}

// ── Init ──────────────────────────────────────────────────────
document.addEventListener('DOMContentLoaded', function() {

    setupNepaliPicker('coupon_issued_nep', 'coupon_issued_eng_d', 'coupon_issued_eng_n', 'coupon_issued_eng_h');

    setupNepaliPicker('dist_date_nep', 'dist_date_eng_d', 'dist_date_eng_n', 'dist_date_eng_h', function() {
        triggerRateFetch();
    });

    updateTotalQty();

    var vSel = document.getElementById('coupon_vehicle_id');
    if (vSel && vSel.value) onVehicleChange(vSel.value);

    var cSel = document.getElementById('dist_coupon_id');
    if (cSel && cSel.value) onCouponChange(cSel.value);

    calcTotal();

    // Wire up qty input for live balance update
    var qtyInput = document.getElementById('dist_qty');
    if (qtyInput) qtyInput.addEventListener('input', updateDistAfter);

    // Activate correct tab
    var activeTabId = '<?= $active_tab ?>';
    var el = document.getElementById(activeTabId);
    if (el) {
        el.classList.add('active');
        document.querySelectorAll('.tab').forEach(function(btn) {
            if (btn.getAttribute('data-tab') === activeTabId) btn.classList.add('active');
        });
    } else {
        document.getElementById('coupon-view').classList.add('active');
        document.querySelectorAll('.tab')[1].classList.add('active');
    }
});
</script>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

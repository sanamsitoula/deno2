<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('incharge') && !has_role('supervisor') && !has_role('admin') && !has_role('operator')) {
    ob_end_clean();
    header('Location: /deno2/unauthorized.php');
    exit();
}

$current_user_id = $_SESSION['user_id'] ?? null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    error_log("Form submission data: " . print_r($_POST, true));
    $conn->beginTransaction();
    try {

        $required_fields = [
            'date_nep', 'date_eng', 'name', 'fiscal_year_id', 'jt_id', 'jtd_id',
            'jtd_targetqty', 'fp_printqty', 'fp_remainqty', 'supervisor_id',
            'operator_id', 'incharge_id', 'shift_id', 'machine_id'
        ];

        $missing_fields = [];
        foreach ($required_fields as $field) {
            if (!isset($_POST[$field])) {
                $missing_fields[] = $field;
            }
        }

        if (!empty($missing_fields)) {
            throw new Exception("Missing required fields: " . implode(', ', $missing_fields));
        }

        $date_nep        = $_POST['date_nep'];
        $date_eng        = $_POST['date_eng'];
        $name            = $_POST['name'];
        $fiscal_year_id  = $_POST['fiscal_year_id'];
        $jt_id           = $_POST['jt_id'];
        $jtd_id          = $_POST['jtd_id'];
        $jtd_targetqty   = $_POST['jtd_targetqty'];
        $fp_printqty     = $_POST['fp_printqty'];
        $fp_remainqty    = $_POST['fp_remainqty'];
        $supervisor_id   = $_POST['supervisor_id'];
        $operator_id     = $_POST['operator_id'];
        $incharge_id     = $_POST['incharge_id'];
        $shift_id        = $_POST['shift_id'];
        $machine_id      = $_POST['machine_id'];
        $remarks         = $_POST['remarks']     ?? null;
        $description     = $_POST['description'] ?? null;

        if ($fp_printqty <= 0) {
            throw new Exception("Print quantity must be greater than 0");
        }

        $checkStmt = $conn->prepare("
            SELECT
                jtd.print_qty as target_qty,
                COALESCE(SUM(fp.jt_print_qty), 0) as already_printed
              
            FROM job_ticket_details jtd
         LEFT JOIN book_packing fp ON fp.jt_id = jtd.id
           AND fp.status = true
            WHERE jtd.id = :jtd_id
            GROUP BY jtd.id, jtd.print_qty
        ");
        $checkStmt->execute([':jtd_id' => $jtd_id]);
        $qtyCheck = $checkStmt->fetch(PDO::FETCH_ASSOC);

        if ($qtyCheck) {
            $available_qty = $qtyCheck['target_qty'] - $qtyCheck['already_printed'];
            if ($fp_printqty > $available_qty) {
                throw new Exception("Print quantity ({$fp_printqty}) exceeds available quantity ({$available_qty})");
            }
        }

        $stmt = $conn->prepare("
            INSERT INTO book_packing (
                date_nep, date_eng, name, fiscal_year_id, jt_id, jtd_id,
                jtd_targetqty, fp_printqty, fp_remainqty, supervisor_id,
                created_by, operator_id, incharge_id, shift_id, machine_id,
                remarks, description, status, created_date
            ) VALUES (
                :date_nep, :date_eng, :name, :fiscal_year_id, :jt_id, :jtd_id,
                :jtd_targetqty, :fp_printqty, :fp_remainqty, :supervisor_id,
                :created_by, :operator_id, :incharge_id, :shift_id, :machine_id,
                :remarks, :description, true, NOW()
            )
        ");

        $result = $stmt->execute([
            ':date_nep'       => $date_nep,
            ':date_eng'       => $date_eng,
            ':name'           => $name,
            ':fiscal_year_id' => $fiscal_year_id,
            ':jt_id'          => $jt_id,
            ':jtd_id'         => $jtd_id,
            ':jtd_targetqty'  => $jtd_targetqty,
            ':fp_printqty'    => $fp_printqty,
            ':fp_remainqty'   => $fp_remainqty,
            ':supervisor_id'  => $supervisor_id,
            ':created_by'     => $current_user_id,
            ':operator_id'    => $operator_id,
            ':incharge_id'    => $incharge_id,
            ':shift_id'       => $shift_id,
            ':machine_id'     => $machine_id,
            ':remarks'        => $remarks,
            ':description'    => $description
        ]);

        $book_packing_id = $conn->lastInsertId();
        $conn->commit();

        $_SESSION['success_message'] = "Book Packing record created successfully!";
        ob_end_clean();
        header("Location: index.php?id=$book_packing_id");
        exit();

    } catch (PDOException $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error_message'] = "Error creating Book Packing record: " . $e->getMessage();
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['error_message'] = $e->getMessage();
    }
}

// Get current fiscal year (active)
$current_fiscal_year = $conn->query("
    SELECT id, fiscal_code FROM fiscal_years WHERE is_active = true LIMIT 1
")->fetch(PDO::FETCH_ASSOC);

// All fiscal years for dropdown
$fiscal_years = $conn->query("
    SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Active fiscal years for JS fiscal name lookup
$active_fiscal_years = $conn->query("
    SELECT fiscal_name, fiscal_code FROM fiscal_years WHERE is_active = true ORDER BY fiscal_name
")->fetchAll(PDO::FETCH_ASSOC);

// Job tickets from active fiscal year
$job_tickets = $conn->query("
    SELECT
        jt.id,
        jt.job_ticket_code,
        jt.lot,
        b.book_code,
        b.book_name,
        b.class_level,
        jt.print_qty as jt_print_qty,
        jt.page_qty,
        jt.status as jt_status,
        fy.fiscal_code
    FROM job_ticket jt
    LEFT JOIN books b ON jt.book_id = b.book_id
    INNER JOIN fiscal_years fy ON jt.fiscal_year_id = fy.id
    WHERE jt.status IN ('active', 'processing', 'pending')
    AND fy.is_active = true
    ORDER BY jt.job_ticket_code DESC
")->fetchAll(PDO::FETCH_ASSOC);

// Users by role
$supervisors = $conn->query("
    SELECT id, username FROM users WHERE role IN ('supervisor','admin') ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

$operators = $conn->query("
    SELECT id, username FROM users WHERE role IN ('operator','admin') ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

$incharges = $conn->query("
    SELECT id, username FROM users WHERE role IN ('incharge','admin') ORDER BY username
")->fetchAll(PDO::FETCH_ASSOC);

$shifts   = $conn->query("SELECT id, name FROM shifts WHERE status = true ORDER BY name")->fetchAll(PDO::FETCH_ASSOC);
$machines = $conn->query("SELECT id, machine_name FROM machines WHERE status = 'active' ORDER BY machine_name")->fetchAll(PDO::FETCH_ASSOC);

$current_english_date = date('Y.m.d');
// Nepali date: replace with your actual BS conversion if available
$current_nepali_date  = date('Y.m.d');
?>

<!-- Nepali Datepicker CSS -->
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>

<style>
    .container { max-width:100%; padding:20px; }
    h2 { margin:20px 15px; color:#333; font-size:28px; font-weight:600; }

    .form-container {
        background:#f8f9fa; padding:25px; border-radius:10px;
        margin:0 15px 25px; border:1px solid #e9ecef;
        box-shadow:0 2px 4px rgba(0,0,0,.05);
    }
    .form-title {
        font-size:18px; font-weight:600; margin-bottom:20px; color:#333;
        display:flex; align-items:center; gap:10px;
        border-bottom:2px solid #007bff; padding-bottom:10px;
    }
    .search-row {
        display:grid; grid-template-columns:repeat(auto-fit,minmax(300px,1fr));
        gap:20px; margin-bottom:20px;
    }
    .search-group { position:relative; }
    .search-group label { font-size:14px; font-weight:600; margin-bottom:6px; display:block; color:#495057; }

    .search-control, .form-control {
        width:100%; padding:12px 15px; border:2px solid #e9ecef;
        border-radius:6px; font-size:14px; box-sizing:border-box;
        transition:all .3s ease; font-family:inherit;
    }
    .search-control:focus, .form-control:focus {
        outline:none; border-color:#007bff; box-shadow:0 0 0 3px rgba(0,123,255,.1);
    }
    .search-control[readonly], .search-control:disabled {
        background:#e9ecef; cursor:not-allowed; opacity:.6;
    }

    /* ── Nepali datepicker input override ── */
    .ndp-input {
        width:100%; padding:12px 15px; border:2px solid #e9ecef;
        border-radius:6px; font-size:14px; box-sizing:border-box;
        background:#fff; font-family:inherit; transition:border-color .3s;
    }
    .ndp-input:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 3px rgba(0,123,255,.1); }

    /* ── English date overlay ── */
    .date-eng-wrapper { position:relative; }
    .date-eng-wrapper .eng-display {
        width:100%; padding:12px 15px; border:2px solid #e9ecef;
        border-radius:6px; font-size:14px; box-sizing:border-box;
        background:#fff; color:#333; font-family:inherit;
        min-height:44px; line-height:20px; cursor:pointer;
        position:relative; z-index:1;
    }
    .date-eng-wrapper .eng-display:empty::before { content:'Auto-filled from Nepali date'; color:#999; }
    .date-eng-wrapper input[type="date"] {
        position:absolute; top:0; left:0; width:100%; height:100%;
        opacity:0; cursor:pointer; z-index:2; margin:0; padding:0; border:none;
    }

    /* ── Fiscal year info ── */
    .fy-info { font-size:13px; color:#555; margin-top:5px; }
    .fy-info span { font-weight:600; color:#0066cc; }
    .fy-warning { color:#c00 !important; font-weight:600; }

    /* ── Searchable job ticket dropdown ── */
    .search-dropdown { position:relative; }
    .dropdown-options {
        position:absolute; top:100%; left:0; right:0;
        background:#fff; border:1px solid #ddd; border-top:none;
        max-height:300px; overflow-y:auto; z-index:1000; display:none;
        border-radius:0 0 6px 6px; box-shadow:0 4px 6px rgba(0,0,0,.1);
    }
    .dropdown-option {
        padding:12px 15px; cursor:pointer;
        border-bottom:1px solid #eee; transition:background-color .2s;
    }
    .dropdown-option:hover { background:#f8f9fa; }
    .dropdown-option:last-child { border-bottom:none; }
    .dropdown-option strong { display:block; color:#333; font-size:14px; }
    .dropdown-option small { display:block; color:#666; font-size:12px; margin-top:2px; }

    /* ── Info cards ── */
    .info-card {
        background:#fff; border:1px solid #dee2e6; border-radius:6px;
        padding:15px; margin-bottom:20px; box-shadow:0 1px 3px rgba(0,0,0,.1);
    }
    .info-card-title { font-weight:600; color:#333; margin-bottom:10px; font-size:16px; }
    .info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:15px; }
    .info-item { display:flex; flex-direction:column; }
    .info-label { font-size:12px; color:#6c757d; text-transform:uppercase; font-weight:500; margin-bottom:4px; }
    .info-value { font-size:14px; color:#333; font-weight:500; }

    /* ── Previous records ── */
    .previous-records {
        background:#fff3cd; border:1px solid #ffeaa7;
        border-radius:6px; padding:15px; margin-top:15px;
    }
    .previous-records-title {
        font-weight:600; color:#856404; margin-bottom:10px;
        display:flex; align-items:center; gap:8px;
    }
    .previous-record-item {
        background:#fff; border-radius:4px; padding:10px; margin-bottom:8px;
        border-left:4px solid #ffc107; font-size:13px;
    }

    /* ── Quantity warning ── */
    .quantity-warning {
        padding:8px 12px; border-radius:4px; font-size:13px;
        margin-top:5px; display:none; border:1px solid transparent;
    }
    .quantity-error   { background:#f8d7da; border-color:#f5c6cb; color:#721c24; }
    .quantity-success { background:#d4edda; border-color:#c3e6cb; color:#155724; }
    .quantity-info    { background:#d1ecf1; border-color:#bee5eb; color:#0c5460; }

    /* ── Status indicators ── */
    .status-indicator {
        display:inline-flex; align-items:center; gap:5px;
        font-size:12px; padding:2px 8px; border-radius:12px; font-weight:500;
    }
    .status-completed   { background:#d4edda; color:#155724; border:1px solid #c3e6cb; }
    .status-in-progress { background:#d1ecf1; color:#0c5460; border:1px solid #bee5eb; }
    .status-not-started { background:#fff3cd; color:#856404; border:1px solid #ffeaa7; }

    .btn {
        padding:12px 20px; border:none; border-radius:6px; cursor:pointer;
        font-size:14px; font-weight:500; text-decoration:none;
        display:inline-block; transition:all .3s ease; text-align:center; margin-right:10px;
    }
    .btn:hover:not(:disabled) { transform:translateY(-1px); box-shadow:0 2px 8px rgba(0,0,0,.15); }
    .btn:disabled { opacity:.6; cursor:not-allowed; transform:none; box-shadow:none; }
    .btn-primary   { background:#007bff; color:#fff; }
    .btn-secondary { background:#6c757d; color:#fff; }
    .btn-warning   { background:#ffc107; color:#212529; }
    .btn-danger    { background:#dc3545; color:#fff; }

    .alert { padding:15px; margin:0 15px 20px; border-radius:6px; border:1px solid transparent; }
    .alert-success { color:#155724; background:#d4edda; border-color:#c3e6cb; }
    .alert-danger  { color:#721c24; background:#f8d7da; border-color:#f5c6cb; }
    .alert-warning { color:#856404; background:#fff3cd; border-color:#ffeaa7; }
    .alert-info    { color:#0c5460; background:#d1ecf1; border-color:#bee5eb; }

    .close { background:none; border:none; font-size:1.5rem; font-weight:bold; color:#000; opacity:.5; cursor:pointer; float:right; margin-left:10px; }
    .close:hover { opacity:1; }
    .required { color:#dc3545; }
    .is-invalid { border-color:#dc3545 !important; }

    .form-actions {
        display:flex; justify-content:space-between; align-items:center;
        margin-top:30px; padding-top:20px; border-top:1px solid #dee2e6;
    }

    @media (max-width:768px) {
        .search-row   { grid-template-columns:1fr; }
        .form-actions { flex-direction:column; gap:15px; }
    }
</style>

<div class="container">
    <h2>➕ Create Book Packing Record</h2>

    <?php if (isset($_SESSION['success_message'])): ?>
        <div class="alert alert-success">
            <i class="fas fa-check-circle"></i> <?= htmlspecialchars($_SESSION['success_message']) ?>
            <button type="button" class="close" onclick="this.parentElement.remove()"><span>&times;</span></button>
        </div>
        <?php unset($_SESSION['success_message']); ?>
    <?php endif; ?>

    <?php if (isset($_SESSION['error_message'])): ?>
        <div class="alert alert-danger">
            <i class="fas fa-exclamation-triangle"></i> <?= htmlspecialchars($_SESSION['error_message']) ?>
            <button type="button" class="close" onclick="this.parentElement.remove()"><span>&times;</span></button>
        </div>
        <?php unset($_SESSION['error_message']); ?>
    <?php endif; ?>

    <form method="post" id="bookPackingForm" enctype="multipart/form-data" class="needs-validation" novalidate>

        <!-- ══ BASIC INFORMATION ══ -->
        <div class="form-container">
            <div class="form-title"><i class="fas fa-calendar-alt"></i> Basic Information</div>

            <div class="search-row">

                <!-- Nepali Date -->
                <div class="search-group">
                    <label for="date_nep">📅 Nepali Date <span class="required">*</span></label>
                    <input type="text"
                           id="date_nep"
                           name="date_nep"
                           class="ndp-input"
                           placeholder="e.g. 2082.01.15"
                           autocomplete="off"
                           required>
                    <div class="fy-info" id="fy_display"></div>
                </div>

                <!-- English Date (auto-filled + native override) -->
                <div class="search-group">
                    <label>📅 English Date <span class="required">*</span></label>
                    <input type="hidden" name="date_eng" id="date_eng_hidden">
                    <div class="date-eng-wrapper">
                        <div class="eng-display" id="eng_display"></div>
                        <input type="date" id="date_eng_native" min="1944-01-01" max="2044-12-31">
                    </div>
                    <small class="fy-info">Auto-filled from Nepali date, or click to override</small>
                </div>

                <!-- Fiscal Year -->
                <div class="search-group">
                    <label for="fiscal_year_id">📆 Fiscal Year <span class="required">*</span></label>
                    <select name="fiscal_year_id" id="fiscal_year_id" class="search-control" required>
                        <?php foreach ($fiscal_years as $fy): ?>
                            <option value="<?= $fy['id'] ?>"
                                <?= ($fy['id'] == $current_fiscal_year['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($fy['fiscal_code']) ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

            </div>

            <!-- Record Name (auto-generated) -->
            <div class="search-row">
                <div class="search-group">
                    <label for="name">📛 Record Name <span class="required">*</span></label>
                    <input type="text" name="name" id="name" class="search-control" required readonly>
                    <small class="fy-info">Auto-generated from Job Ticket and Date</small>
                </div>
            </div>
        </div>

        <!-- ══ JOB TICKET & FORMA SELECTION ══ -->
        <div class="form-container">
            <div class="form-title"><i class="fas fa-clipboard-list"></i> Job Ticket &amp; Forma Selection</div>

            <!-- Job Ticket searchable dropdown -->
            <div class="search-row">
                <div class="search-group search-dropdown">
                    <label for="jt_search">🎫 Job Ticket <span class="required">*</span></label>
                    <input type="text" id="jt_search" class="search-control dropdown-search"
                           placeholder="Search job tickets..." autocomplete="off">
                    <input type="hidden" name="jt_id" id="jt_id" required>
                    <div class="dropdown-options" id="jt_options">
                        <?php foreach ($job_tickets as $jt): ?>
                        <div class="dropdown-option"
                             data-value="<?= $jt['id'] ?>"
                             data-code="<?= htmlspecialchars($jt['job_ticket_code']) ?>"
                             data-book-code="<?= htmlspecialchars($jt['book_code']) ?>"
                             data-book-name="<?= htmlspecialchars($jt['book_name']) ?>"
                             data-class="<?= htmlspecialchars($jt['class_level']) ?>"
                             data-lot="<?= htmlspecialchars($jt['lot']) ?>"
                             data-print-qty="<?= $jt['jt_print_qty'] ?>"
                             data-page-qty="<?= $jt['page_qty'] ?>"
                             data-status="<?= $jt['jt_status'] ?>"
                             data-fiscal-code="<?= htmlspecialchars($jt['fiscal_code']) ?>">
                            <strong><?= htmlspecialchars($jt['job_ticket_code']) ?></strong>
                            <small>
                                <?= htmlspecialchars($jt['book_code']) ?> - <?= htmlspecialchars($jt['book_name']) ?>
                                | Class: <?= htmlspecialchars($jt['class_level']) ?>
                                | Lot: <?= htmlspecialchars($jt['lot']) ?>
                                | FY: <?= htmlspecialchars($jt['fiscal_code']) ?>
                            </small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <!-- Job Ticket Info Card -->
            <div class="info-card" id="jt_info_card" style="display:none;">
                <div class="info-card-title">📋 Job Ticket Information</div>
                <div class="info-grid">
                    <div class="info-item"><span class="info-label">Book Code</span><span class="info-value" id="jt_book_code">-</span></div>
                    <div class="info-item"><span class="info-label">Book Name</span><span class="info-value" id="jt_book_name">-</span></div>
                    <div class="info-item"><span class="info-label">Class Level</span><span class="info-value" id="jt_class">-</span></div>
                    <div class="info-item"><span class="info-label">Lot Number</span><span class="info-value" id="jt_lot">-</span></div>
                    <div class="info-item"><span class="info-label">Total Print Qty</span><span class="info-value" id="jt_print_qty">-</span></div>
                    <div class="info-item"><span class="info-label">Page Quantity</span><span class="info-value" id="jt_page_qty">-</span></div>
                </div>
            </div>

            <!-- Job Ticket Progress Summary Card -->
            <div class="info-card" id="jt_summary_card" style="display:none;"></div>

            <!-- Forma (JTD) select -->
            <div class="search-row">
                <div class="search-group">
                    <label for="jtd_id">📄 Forma (Job Ticket Details) <span class="required">*</span></label>
                    <select name="jtd_id" id="jtd_id" class="search-control" required disabled>
                        <option value="">Please select a job ticket first</option>
                    </select>
                </div>
            </div>

            <!-- Forma Status Info Card -->
            <div id="forma_status_card" style="display:none;"></div>

            <!-- Previous Records -->
            <div id="previous_records" style="display:none;"></div>
        </div>

        <!-- ══ QUANTITY INFORMATION ══ -->
        <div class="form-container">
            <div class="form-title"><i class="fas fa-calculator"></i> Quantity Information</div>
            <div class="search-row">
                <div class="search-group">
                    <label for="jtd_targetqty">🎯 Target Quantity (JTD)</label>
                    <input type="number" name="jtd_targetqty" id="jtd_targetqty"
                           class="search-control" required readonly>
                </div>
                <div class="search-group">
                    <label for="fp_printqty">📦 Pack Quantity <span class="required">*</span></label>
                    <input type="number" name="fp_printqty" id="fp_printqty"
                           class="search-control" required min="1" step="1" disabled>
                    <div class="quantity-warning" id="qty_warning"></div>
                </div>
                <div class="search-group">
                    <label for="fp_remainqty">📦 Remaining Quantity</label>
                    <input type="number" name="fp_remainqty" id="fp_remainqty"
                           class="search-control" required readonly>
                </div>
            </div>
        </div>

        <!-- ══ PERSONNEL & MACHINE ══ -->
        <div class="form-container">
            <div class="form-title"><i class="fas fa-users"></i> Personnel &amp; Machine Assignment</div>

            <div class="search-row">
                <div class="search-group">
                    <label for="supervisor_id">👔 Supervisor <span class="required">*</span></label>
                    <select name="supervisor_id" id="supervisor_id" class="search-control" required>
                        <option value="">Select Supervisor</option>
                        <?php foreach ($supervisors as $s): ?>
                            <option value="<?= $s['id'] ?>"><?= htmlspecialchars($s['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label for="operator_id">👷 Operator <span class="required">*</span></label>
                    <select name="operator_id" id="operator_id" class="search-control" required>
                        <option value="">Select Operator</option>
                        <?php foreach ($operators as $o): ?>
                            <option value="<?= $o['id'] ?>"><?= htmlspecialchars($o['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label for="incharge_id">👨‍💼 Incharge <span class="required">*</span></label>
                    <select name="incharge_id" id="incharge_id" class="search-control" required>
                        <option value="">Select Incharge</option>
                        <?php foreach ($incharges as $i): ?>
                            <option value="<?= $i['id'] ?>"><?= htmlspecialchars($i['username']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div class="search-row">
                <div class="search-group">
                    <label for="shift_id">⏰ Shift <span class="required">*</span></label>
                    <select name="shift_id" id="shift_id" class="search-control" required>
                        <option value="">Select Shift</option>
                        <?php foreach ($shifts as $sh): ?>
                            <option value="<?= $sh['id'] ?>"><?= htmlspecialchars($sh['name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="search-group">
                    <label for="machine_id">🖨️ Machine <span class="required">*</span></label>
                    <select name="machine_id" id="machine_id" class="search-control" required>
                        <option value="">Select Machine</option>
                        <?php foreach ($machines as $m): ?>
                            <option value="<?= $m['id'] ?>"><?= htmlspecialchars($m['machine_name']) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- ══ ADDITIONAL INFORMATION ══ -->
        <div class="form-container">
            <div class="form-title"><i class="fas fa-comment-alt"></i> Additional Information</div>
            <div class="search-row">
                <div class="search-group">
                    <label for="remarks">📝 Remarks</label>
                    <textarea name="remarks" id="remarks" class="search-control" rows="3"
                              placeholder="Enter any remarks or notes..."></textarea>
                </div>
                <div class="search-group">
                    <label for="description">📄 Description</label>
                    <textarea name="description" id="description" class="search-control" rows="3"
                              placeholder="Enter detailed description..."></textarea>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="form-actions">
            <div>
                <a href="index.php" class="btn btn-secondary">
                    <i class="fas fa-arrow-left"></i> Back to List
                </a>
            </div>
            <div>
                <button type="reset" class="btn btn-warning" id="reset_btn">
                    <i class="fas fa-undo"></i> Reset Form
                </button>
                <button type="submit" class="btn btn-primary" id="submit_btn" disabled>
                    <i class="fas fa-save"></i> Save Book Packing
                </button>
            </div>
        </div>

    </form>
</div>

<!-- Nepali Datepicker JS -->
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>

<script>
/* ── Fiscal years for client-side FY preview ── */
var FISCAL_YEARS = <?php
    $fy_js = [];
    foreach ($active_fiscal_years as $fy) {
        $fy_js[] = ['fiscal_name' => $fy['fiscal_name'], 'fiscal_code' => $fy['fiscal_code']];
    }
    echo json_encode($fy_js);
?>;

function computeFiscalName(nepDate) {
    var parts = nepDate.split('.');
    if (parts.length < 2) return null;
    var year  = parseInt(parts[0], 10);
    var month = parseInt(parts[1], 10);
    if (isNaN(year) || isNaN(month)) return null;
    var fyStart = (month >= 4) ? year : year - 1;
    var fyEnd   = (fyStart + 1) % 100;
    return fyStart + '-' + String(fyEnd).padStart(2, '0');
}

document.addEventListener('DOMContentLoaded', function () {

    /* ════════════════════════════════════
       NEPALI / ENGLISH DATE HANDLING
    ════════════════════════════════════ */
    var nepField   = document.getElementById('date_nep');
    var engHidden  = document.getElementById('date_eng_hidden');
    var engDisplay = document.getElementById('eng_display');
    var engNative  = document.getElementById('date_eng_native');
    var fyDisplay  = document.getElementById('fy_display');
    var blockNep   = false;

    function fillEngFields(dotVal) {
        engHidden.value        = dotVal;
        engDisplay.textContent = dotVal;
        engNative.value        = dotVal.replace(/\./g, '-');
    }

    function updateFyDisplay(nepVal) {
        if (!fyDisplay) return;
        var fyName = computeFiscalName(nepVal);
        if (!fyName) { fyDisplay.innerHTML = ''; return; }
        var found = FISCAL_YEARS.find(function (f) { return f.fiscal_name === fyName; });
        fyDisplay.innerHTML = found
            ? 'Fiscal year: <span>' + found.fiscal_name + '</span> (code: <span>' + found.fiscal_code + '</span>)'
            : '<span class="fy-warning">⚠ Fiscal year <b>' + fyName + '</b> not in active fiscal years</span>';
    }

    function convertAndFill(bsVal) {
        if (!bsVal) return;
        updateFyDisplay(bsVal);
        updateNameField();
        try {
            var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adVal) fillEngFields(adVal);
        } catch (e) { console.warn('BS→AD failed:', e); }
    }

    nepField.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function () {
            if (blockNep) return;
            convertAndFill(nepField.value.trim());
        }
    });

    nepField.addEventListener('blur', function () {
        if (blockNep) return;
        convertAndFill(nepField.value.trim());
    });

    engNative.addEventListener('change', function () {
        var nativeVal = engNative.value;
        if (!nativeVal) return;
        var dotVal = nativeVal.replace(/-/g, '.');
        fillEngFields(dotVal);
        try {
            var bsVal = NepaliFunctions.AD2BS(dotVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (bsVal) {
                blockNep       = true;
                nepField.value = bsVal;
                blockNep       = false;
                updateFyDisplay(bsVal);
                updateNameField();
            }
        } catch (e) { console.warn('AD→BS failed:', e); blockNep = false; }
    });

    /* ════════════════════════════════════
       AUTO-GENERATE NAME FIELD
    ════════════════════════════════════ */
    function updateNameField() {
        var dateNep = document.getElementById('date_nep').value;
        var jtCode  = document.getElementById('jt_search').value.split(' - ')[0];
        if (dateNep && jtCode) {
            document.getElementById('name').value = jtCode + ' - ' + dateNep;
        }
    }

    /* ════════════════════════════════════
       JOB TICKET SEARCHABLE DROPDOWN
    ════════════════════════════════════ */
    var jtSearch   = document.getElementById('jt_search');
    var jtIdInput  = document.getElementById('jt_id');
    var jtOptions  = document.getElementById('jt_options');
    var jtInfoCard = document.getElementById('jt_info_card');

    jtSearch.addEventListener('focus', function () {
        jtOptions.style.display = 'block';
        filterJobTickets();
    });
    jtSearch.addEventListener('input', function () {
        filterJobTickets();
        jtOptions.style.display = 'block';
        if (this.value.trim() === '') {
            jtIdInput.value = '';
            jtInfoCard.style.display = 'none';
            resetFormaSelection();
        }
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.search-dropdown')) jtOptions.style.display = 'none';
    });

    function filterJobTickets() {
        var term = jtSearch.value.toLowerCase();
        jtOptions.querySelectorAll('.dropdown-option').forEach(function (opt) {
            opt.style.display = opt.textContent.toLowerCase().includes(term) ? 'block' : 'none';
        });
    }

    jtOptions.querySelectorAll('.dropdown-option').forEach(function (opt) {
        opt.addEventListener('click', function () {
            jtSearch.value  = this.dataset.code + ' - ' + this.dataset.bookName;
            jtIdInput.value = this.dataset.value;
            jtOptions.style.display = 'none';
            updateJobTicketInfo(this);
            loadJobTicketFormas(this.dataset.value);
            updateNameField();
        });
    });

    function updateJobTicketInfo(el) {
        document.getElementById('jt_book_code').textContent  = el.dataset.bookCode  || '-';
        document.getElementById('jt_book_name').textContent  = el.dataset.bookName  || '-';
        document.getElementById('jt_class').textContent      = el.dataset.class     || '-';
        document.getElementById('jt_lot').textContent        = el.dataset.lot       || '-';
        document.getElementById('jt_print_qty').textContent  = fmtNum(el.dataset.printQty);
        document.getElementById('jt_page_qty').textContent   = fmtNum(el.dataset.pageQty);
        jtInfoCard.style.display = 'block';
    }

    /* ════════════════════════════════════
       LOAD FORMAS VIA AJAX
    ════════════════════════════════════ */
    function loadJobTicketFormas(jtId) {
        var jtdSelect       = document.getElementById('jtd_id');
        var previousRecords = document.getElementById('previous_records');
        var summaryCard     = document.getElementById('jt_summary_card');

        jtdSelect.innerHTML = '<option value="">Loading formas...</option>';
        jtdSelect.disabled  = true;
        previousRecords.style.display = 'none';
        summaryCard.style.display     = 'none';
        resetQuantityFields();

        fetch('getformadetailsfromjobticketid.php?job_ticket_id=' + jtId)
            .then(function (r) {
                if (!r.ok) throw new Error('HTTP ' + r.status);
                return r.json();
            })
            .then(function (data) {
                if (!data.success) throw new Error(data.message || 'Failed to load formas');

                jtdSelect.innerHTML = '<option value="">Select Forma</option>';

                if (!data.has_formas || !data.forma_details.length) {
                    jtdSelect.innerHTML = '<option value="">No formas available</option>';
                    showAlert('No formas found for the selected job ticket', 'warning');
                    return;
                }

                updateJobTicketSummary(data.summary);

                data.forma_details.forEach(function (forma) {
                    var opt = document.createElement('option');
                    opt.value = forma.jtd_id;
                    var icon       = statusIcon(forma.printing_status);
                    var remaining  = forma.fp_remaining_qty > 0 ? ' - Remaining: ' + fmtNum(forma.fp_remaining_qty) : '';
                    var completion = forma.completion_percentage > 0 ? ' (' + forma.completion_percentage + '% complete)' : '';
                    opt.textContent = icon + ' ' + forma.forma_display_name + ' - Target: ' + fmtNum(forma.jtd_target_qty) + remaining + completion;
                    opt.dataset.printQty            = forma.jtd_target_qty;
                    opt.dataset.totalPrinted        = forma.total_printed;
                    opt.dataset.remainingQty        = forma.fp_remaining_qty;
                    opt.dataset.printingStatus      = forma.printing_status;
                    opt.dataset.canPrint            = forma.can_print;
                    opt.dataset.completionPercentage = forma.completion_percentage;
                    if (!forma.can_print) {
                        opt.disabled = true;
                        opt.style.color = '#6c757d';
                        opt.textContent += ' [COMPLETED]';
                    }
                    jtdSelect.appendChild(opt);
                });

                jtdSelect.disabled = false;
                showAlert('Loaded ' + data.forma_details.length + ' formas. Overall completion: ' + data.summary.overall_completion_percentage + '%', 'info', 3000);
            })
            .catch(function (err) {
                console.error(err);
                jtdSelect.innerHTML = '<option value="">Error loading formas</option>';
                showAlert('Error loading forma details: ' + err.message, 'danger');
            });
    }

    function updateJobTicketSummary(summary) {
        var card = document.getElementById('jt_summary_card');
        card.innerHTML =
            '<div class="info-card-title">📈 Job Ticket Progress Summary</div>' +
            '<div class="info-grid">' +
            '<div class="info-item"><span class="info-label">Total Formas</span><span class="info-value">' + summary.total_formas + '</span></div>' +
            '<div class="info-item"><span class="info-label">Completed</span><span class="info-value">' + summary.completed_formas + '</span></div>' +
            '<div class="info-item"><span class="info-label">Pending</span><span class="info-value">' + summary.pending_formas + '</span></div>' +
            '<div class="info-item"><span class="info-label">Overall Progress</span><span class="info-value">' + summary.overall_completion_percentage + '%</span></div>' +
            '<div class="info-item"><span class="info-label">Status</span><span class="info-value">' +
            '<span class="status-indicator ' + (summary.is_fully_completed ? 'status-completed' : 'status-in-progress') + '">' +
            (summary.is_fully_completed ? '✅ COMPLETED' : '🔄 IN PROGRESS') + '</span></span></div>' +
            '</div>';
        card.style.display = 'block';
    }

    /* ════════════════════════════════════
       FORMA SELECT CHANGE
    ════════════════════════════════════ */
    document.getElementById('jtd_id').addEventListener('change', function () {
        if (!this.value) { resetQuantityFields(); hideFormaStatus(); return; }

        var opt           = this.options[this.selectedIndex];
        var targetQty     = parseInt(opt.dataset.printQty)    || 0;
        var totalPrinted  = parseInt(opt.dataset.totalPrinted) || 0;
        var remainingQty  = parseInt(opt.dataset.remainingQty) || 0;
        var printingStatus = opt.dataset.printingStatus;
        var canPrint      = opt.dataset.canPrint === 'true';
        var completion    = parseFloat(opt.dataset.completionPercentage) || 0;

        document.getElementById('jtd_targetqty').value = targetQty;
        document.getElementById('fp_remainqty').value  = remainingQty;
        document.getElementById('fp_printqty').value   = '';

        showFormaStatusCard({ targetQty:targetQty, totalPrinted:totalPrinted, remainingQty:remainingQty, printingStatus:printingStatus, canPrint:canPrint, completionPercentage:completion });
        loadPreviousRecords(this.value);

        var printQtyInput = document.getElementById('fp_printqty');
        printQtyInput.disabled = !canPrint;
        if (!canPrint) {
            printQtyInput.placeholder = 'Forma fully completed';
            showAlert('This forma has been fully packed and cannot accept more records.', 'warning');
            document.getElementById('submit_btn').disabled = true;
        } else {
            printQtyInput.placeholder = 'Enter quantity (max: ' + fmtNum(remainingQty) + ')';
            printQtyInput.max = remainingQty;
            printQtyInput.focus();
        }
    });

    function showFormaStatusCard(info) {
        var card = document.getElementById('forma_status_card');
        card.innerHTML =
            '<div class="info-card-title">📊 Selected Forma Status</div>' +
            '<div class="info-grid">' +
            '<div class="info-item"><span class="info-label">Target Quantity</span><span class="info-value">' + fmtNum(info.targetQty) + '</span></div>' +
            '<div class="info-item"><span class="info-label">Total Packed</span><span class="info-value">' + fmtNum(info.totalPrinted) + '</span></div>' +
            '<div class="info-item"><span class="info-label">Remaining</span><span class="info-value">' + fmtNum(info.remainingQty) + '</span></div>' +
            '<div class="info-item"><span class="info-label">Completion</span><span class="info-value">' + info.completionPercentage + '%</span></div>' +
            '<div class="info-item"><span class="info-label">Status</span><span class="info-value">' +
            '<span class="status-indicator status-' + info.printingStatus.replace('_','-') + '">' +
            statusIcon(info.printingStatus) + ' ' + info.printingStatus.replace('_',' ').toUpperCase() + '</span></span></div>' +
            '<div class="info-item"><span class="info-label">Can Pack More</span><span class="info-value">' + (info.canPrint ? '✅ Yes' : '❌ No') + '</span></div>' +
            '</div>';
        card.style.display = 'block';
    }

    function hideFormaStatus() {
        document.getElementById('forma_status_card').style.display  = 'none';
        document.getElementById('previous_records').style.display   = 'none';
    }

    /* ════════════════════════════════════
       PREVIOUS RECORDS
    ════════════════════════════════════ */
    function loadPreviousRecords(jtdId) {
        var box = document.getElementById('previous_records');
        fetch('getpreviousformaprinting.php?jtd_id=' + jtdId)
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.records || !data.records.length) { box.style.display = 'none'; return; }
                var html = '<div class="previous-records"><div class="previous-records-title"><i class="fas fa-history"></i> Previous Packing Records (' + data.records.length + ' found)</div>' +
                    '<div class="info-grid" style="margin-bottom:10px;">' +
                    '<div class="info-item"><span class="info-label">Total Packed Previously</span><span class="info-value">' + fmtNum(data.total_printed) + '</span></div>' +
                    '<div class="info-item"><span class="info-label">Available to Pack</span><span class="info-value">' + fmtNum(data.remaining_qty) + '</span></div>' +
                    '</div>';
                data.records.forEach(function (rec) {
                    html += '<div class="previous-record-item"><strong>Date:</strong> ' + rec.date_nep + ' | <strong>Packed:</strong> ' + fmtNum(rec.fp_printqty) + ' | <strong>Machine:</strong> ' + rec.machine_name + ' | <strong>Operator:</strong> ' + rec.operator_name + '</div>';
                });
                html += '</div>';
                box.innerHTML = html;
                box.style.display = 'block';
            })
            .catch(function () { box.style.display = 'none'; });
    }

    /* ════════════════════════════════════
       QUANTITY CALCULATION & VALIDATION
    ════════════════════════════════════ */
    document.getElementById('fp_printqty').addEventListener('input', function () {
        calcRemaining();
        validateQty();
    });

    function calcRemaining() {
        var jtdSelect = document.getElementById('jtd_id');
        if (!jtdSelect.value) return;
        var currentRemaining = parseInt(jtdSelect.options[jtdSelect.selectedIndex].dataset.remainingQty) || 0;
        var printQty         = parseInt(document.getElementById('fp_printqty').value) || 0;
        document.getElementById('fp_remainqty').value = Math.max(0, currentRemaining - printQty);
        updateQtyWarning(printQty, currentRemaining);
    }

    function updateQtyWarning(printQty, availableQty) {
        var warn  = document.getElementById('qty_warning');
        var input = document.getElementById('fp_printqty');
        warn.style.display = 'none';
        warn.className = 'quantity-warning';
        input.classList.remove('is-invalid');

        if (printQty <= 0) {
            warn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Pack quantity must be greater than 0';
            warn.className = 'quantity-warning quantity-error';
            warn.style.display = 'block';
            input.classList.add('is-invalid');
        } else if (printQty > availableQty) {
            warn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Pack quantity (' + fmtNum(printQty) + ') exceeds available (' + fmtNum(availableQty) + ')';
            warn.className = 'quantity-warning quantity-error';
            warn.style.display = 'block';
            input.classList.add('is-invalid');
        } else if (printQty === availableQty) {
            warn.innerHTML = '<i class="fas fa-check"></i> This will complete the forma packing';
            warn.className = 'quantity-warning quantity-success';
            warn.style.display = 'block';
        } else {
            var rem = Math.max(0, availableQty - printQty);
            warn.innerHTML = '<i class="fas fa-info-circle"></i> Will pack ' + fmtNum(printQty) + ' units. ' + fmtNum(rem) + ' will remain.';
            warn.className = 'quantity-warning quantity-info';
            warn.style.display = 'block';
        }
    }

    function validateQty() {
        var printQty     = parseInt(document.getElementById('fp_printqty').value) || 0;
        var jtdSelect    = document.getElementById('jtd_id');
        var opt          = jtdSelect.options[jtdSelect.selectedIndex];
        var availableQty = parseInt(opt && opt.dataset.remainingQty) || 0;
        var btn          = document.getElementById('submit_btn');

        if (printQty <= 0 || printQty > availableQty || !jtdSelect.value) {
            btn.disabled = true;
            btn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Fix Issues to Save';
        } else {
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-save"></i> Save Book Packing';
        }
    }

    /* ════════════════════════════════════
       RESET HELPERS
    ════════════════════════════════════ */
    function resetFormaSelection() {
        var jtdSelect = document.getElementById('jtd_id');
        jtdSelect.innerHTML = '<option value="">Please select a job ticket first</option>';
        jtdSelect.disabled  = true;
        resetQuantityFields();
        hideFormaStatus();
    }

    function resetQuantityFields() {
        document.getElementById('jtd_targetqty').value = '';
        document.getElementById('fp_printqty').value   = '';
        document.getElementById('fp_remainqty').value  = '';
        document.getElementById('fp_printqty').disabled     = true;
        document.getElementById('fp_printqty').placeholder  = 'Select forma first';
        document.getElementById('qty_warning').style.display = 'none';
        document.getElementById('fp_printqty').classList.remove('is-invalid');
        document.getElementById('submit_btn').disabled = true;
    }

    /* ════════════════════════════════════
       FORM SUBMIT
    ════════════════════════════════════ */
    document.getElementById('bookPackingForm').addEventListener('submit', function (e) {
        var printQty  = parseInt(document.getElementById('fp_printqty').value) || 0;
        var jtdSelect = document.getElementById('jtd_id');

        if (!jtdSelect.value) {
            e.preventDefault(); showAlert('Please select a forma before saving', 'danger'); return;
        }
        var availableQty = parseInt(jtdSelect.options[jtdSelect.selectedIndex].dataset.remainingQty) || 0;
        if (printQty <= 0) {
            e.preventDefault(); showAlert('Pack quantity must be greater than 0', 'danger'); document.getElementById('fp_printqty').focus(); return;
        }
        if (printQty > availableQty) {
            e.preventDefault(); showAlert('Pack quantity cannot exceed available quantity', 'danger'); document.getElementById('fp_printqty').focus(); return;
        }
        if (!document.getElementById('date_eng_hidden').value.trim()) {
            e.preventDefault(); showAlert('Please select a Nepali date first', 'danger'); document.getElementById('date_nep').focus(); return;
        }
        var btn = document.getElementById('submit_btn');
        btn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Saving...';
        btn.disabled  = true;
    });

    /* ════════════════════════════════════
       RESET BUTTON
    ════════════════════════════════════ */
    document.getElementById('reset_btn').addEventListener('click', function (e) {
        e.preventDefault();
        if (confirm('Reset the form? All entered data will be lost.')) {
            document.getElementById('jt_info_card').style.display    = 'none';
            document.getElementById('jt_summary_card').style.display = 'none';
            hideFormaStatus();
            resetFormaSelection();
            document.getElementById('bookPackingForm').reset();
            document.getElementById('fiscal_year_id').value = '<?= $current_fiscal_year['id'] ?? '' ?>';
            document.getElementById('jt_search').value = '';
            document.getElementById('jt_id').value     = '';
            document.getElementById('name').value       = '';
            // Reset date fields
            document.getElementById('date_eng_hidden').value  = '';
            document.getElementById('eng_display').textContent = '';
            document.getElementById('date_eng_native').value  = '';
            fyDisplay.innerHTML = '';
            showAlert('Form has been reset', 'info', 2000);
        }
    });

    /* ════════════════════════════════════
       UTILITY FUNCTIONS
    ════════════════════════════════════ */
    function fmtNum(n) { return n ? parseInt(n).toLocaleString() : '0'; }

    function statusIcon(s) {
        return s === 'completed' ? '✅' : s === 'in_progress' ? '🔄' : '⏳';
    }

    function showAlert(msg, type, timeout) {
        document.querySelectorAll('.alert').forEach(function (a) { if (a.querySelector('.close')) a.remove(); });
        var div = document.createElement('div');
        div.className = 'alert alert-' + type;
        div.innerHTML = '<i class="fas fa-' + (type==='danger'||type==='warning'?'exclamation-triangle':type==='success'?'check-circle':'info-circle') + '"></i> ' + msg +
            ' <button type="button" class="close" onclick="this.parentElement.remove()"><span>&times;</span></button>';
        document.querySelector('h2').insertAdjacentElement('afterend', div);
        if (timeout) setTimeout(function () { if (div.parentNode) div.remove(); }, timeout);
    }

    /* ════════════════════════════════════
       KEYBOARD SHORTCUTS
    ════════════════════════════════════ */
    document.addEventListener('keydown', function (e) {
        if (e.ctrlKey && e.key === 's') {
            e.preventDefault();
            if (!document.getElementById('submit_btn').disabled) document.getElementById('bookPackingForm').submit();
        }
        if (e.key === 'Escape') document.querySelectorAll('.dropdown-options').forEach(function (d) { d.style.display = 'none'; });
    });

    /* ── initial focus ── */
    document.getElementById('date_nep').focus();
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
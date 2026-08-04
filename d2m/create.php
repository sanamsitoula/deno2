<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit;
}

$message = "";
$error = "";
$preview_data = null;

/* ===============================
   PREVIEW DENO RECORDS
================================ */
if (isset($_GET['preview'])) {
    $nep_date = $_GET['nep_date'] ?? '';
    $type = $_GET['type'] ?? '';
    
    if ($nep_date && $type) {
        $translated = ($type === 'T') ? 'true' : 'false';
        
        $previewStmt = $conn->prepare("
            SELECT 
                d.id as deno_id,
                d.book_code,
                b.book_name,
                b.class_level,
                d.ref_no,
                d.per_poka_qty,
                d.poka_qty,
                d.total_qty,
                d.quantity_openpcs,
                d.created_by
            FROM deno d
            JOIN books b ON b.book_code = d.book_code
            WHERE d.deno_date_nep = :nep
              AND b.is_translated = :translated
            ORDER BY b.book_name, d.ref_no
        ");
        $previewStmt->execute([
            ':nep' => $nep_date,
            ':translated' => $translated
        ]);
        $preview_data = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nep_date = $_POST['nep_date'];
    $eng_date = $_POST['eng_date'];
    $type     = $_POST['type']; // T or NT
    $user_id  = $_SESSION['user_id'];
    $user     = $_SESSION['username'];

    try {
        $conn->beginTransaction();

        /* 1️⃣ Get Active Fiscal Year */
        $fyStmt = $conn->query("SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1");
        $fiscal_year = $fyStmt->fetchColumn();

        $fyStmt = $conn->query("SELECT fiscal_code FROM fiscal_years WHERE is_active = true LIMIT 1");
        $fiscal_code = $fyStmt->fetchColumn();

        if (!$fiscal_year) throw new Exception("Active fiscal year not found.");
        if (!$fiscal_code) throw new Exception("Active fiscal year code not found.");

        /* 2️⃣ Check Existing D2M (same date + type) — cancelled ones don't count */
        $checkStmt = $conn->prepare("
            SELECT id, d2m_no
            FROM d2m
            WHERE nep_date = :nep
              AND d2m_type = :type
              AND fiscal_year_id = :fy
              AND status <> 'CANCELLED'
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $checkStmt->execute([':nep' => $nep_date, ':type' => $type, ':fy' => $fiscal_year]);

        if ($checkStmt->fetch()) {
            throw new Exception("D2M already created for this date and type. (Cancelled D2Ms are ignored.)");
        }

        /* 3️⃣ Generate Serial */
        $serialStmt = $conn->prepare("
            SELECT COALESCE(MAX(serial_no),0)+1
            FROM d2m
            WHERE fiscal_year_id = :fy AND d2m_type = :type
        ");
        $serialStmt->execute([':fy' => $fiscal_year, ':type' => $type]);
        $serial_no = $serialStmt->fetchColumn();

        $d2m_no = "{$serial_no}-D2M/{$fiscal_code}-{$type}-{$nep_date}";

        /* 4️⃣ Insert D2M */
        $insertD2M = $conn->prepare("
            INSERT INTO d2m (d2m_no, serial_no, d2m_type, fiscal_year_id, nep_date, eng_date, created_by)
            VALUES (:no, :serial, :type, :fy, :nep, :eng, :user)
            RETURNING id
        ");
        $insertD2M->execute([
            ':no' => $d2m_no, ':serial' => $serial_no, ':type' => $type,
            ':fy' => $fiscal_year, ':nep' => $nep_date, ':eng' => $eng_date, ':user' => $user_id
        ]);
        $d2m_id = $insertD2M->fetchColumn();

        /* 5️⃣ Insert EACH DENO as Separate D2M Item */
        $translated = ($type === 'T') ? 'true' : 'false';

        $denoStmt = $conn->prepare("
            SELECT d.id as deno_id, d.book_code, d.per_poka_qty, d.poka_qty, d.total_qty, d.quantity_openpcs
            FROM deno d
            JOIN books b ON b.book_code = d.book_code
            WHERE d.deno_date_nep = :nep AND b.is_translated = :translated
            ORDER BY d.book_code, d.id
        ");
        $denoStmt->execute([':nep' => $nep_date, ':translated' => $translated]);
        $deno_records = $denoStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($deno_records)) {
            throw new Exception("No deno records found for selected date and type.");
        }

        $insertItemStmt = $conn->prepare("
            INSERT INTO d2m_items (d2m_id, book_code, per_poka_qty, total_poka_qty, total_qty, open_pcs, associated_deno_ids)
            VALUES (:d2m_id, :book_code, :per_poka, :total_poka, :total_qty, :open_pcs, :deno_ids)
        ");

        $items_inserted = 0;
        foreach ($deno_records as $record) {
            $insertItemStmt->execute([
                ':d2m_id'    => $d2m_id,
                ':book_code' => $record['book_code'],
                ':per_poka'  => $record['per_poka_qty'],
                ':total_poka'=> $record['poka_qty'],
                ':total_qty' => $record['total_qty'],
                ':open_pcs'  => $record['quantity_openpcs'],
                ':deno_ids'  => (string)$record['deno_id']
            ]);
            $items_inserted++;
        }

        $conn->commit();
        $message = "D2M successfully created: <strong>$d2m_no</strong><br>";
        $message .= "Total items: <strong>$items_inserted</strong> (each DENO record as separate item)";

    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}

/* ── Date-wise Deno records for bottom table ── */
$filter_date = $_GET['filter_date'] ?? '';
$deno_table_records = [];

if ($filter_date) {
    $denoTableStmt = $conn->prepare("
        SELECT d.*, b.book_name,
               u1.username as created_user,
               u2.username as received_user,
               u3.username as verified_user,
               u4.username as sender_user
        FROM deno d
        LEFT JOIN books b ON d.book_code = b.book_code
        LEFT JOIN users u1 ON d.created_by = u1.id
        LEFT JOIN users u2 ON d.received_by = u2.id
        LEFT JOIN users u3 ON d.verify_by = u3.id
        LEFT JOIN users u4 ON d.sender_by = u4.id
        WHERE d.deno_date_nep = :filter_date AND d.deleted_at IS NULL
        ORDER BY d.created_at DESC
    ");
    $denoTableStmt->execute([':filter_date' => $filter_date]);
    $deno_table_records = $denoTableStmt->fetchAll(PDO::FETCH_ASSOC);
} else {
    $deno_table_records = $conn->query("
        SELECT d.*, b.book_name,
               u1.username as created_user,
               u2.username as received_user,
               u3.username as verified_user,
               u4.username as sender_user
        FROM deno d
        LEFT JOIN books b ON d.book_code = b.book_code
        LEFT JOIN users u1 ON d.created_by = u1.id
        LEFT JOIN users u2 ON d.received_by = u2.id
        LEFT JOIN users u3 ON d.verify_by = u3.id
        LEFT JOIN users u4 ON d.sender_by = u4.id
        WHERE d.deleted_at IS NULL
        ORDER BY d.created_at DESC LIMIT 20
    ")->fetchAll(PDO::FETCH_ASSOC);
}

// Active fiscal year, for display only (numbering itself is generated on submit)
$active_fy_row = $conn->query("SELECT fiscal_code, fiscal_name FROM fiscal_years WHERE is_active = true LIMIT 1")->fetch(PDO::FETCH_ASSOC);
?>

<!-- Nepali Datepicker v5 CSS -->
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>

<style>
.preview-section {
    margin-top: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}
.preview-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px;
    border-radius: 8px 8px 0 0;
    margin: -20px -20px 20px -20px;
}
.deno-item {
    background: white;
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 5px;
    border-left: 4px solid #007bff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}
.summary-box {
    background: #e8f4f8;
    padding: 15px;
    border-radius: 5px;
    margin-top: 20px;
}
.badge-custom { padding: 5px 10px; border-radius: 4px; font-size: 12px; font-weight: bold; }
.badge-deno   { background: #17a2b8; color: white; }

/* Deno filter section */
.deno-filter-bar {
    display: flex;
    gap: 12px;
    align-items: flex-end;
    margin-bottom: 18px;
    background: #f1f3f5;
    padding: 14px 18px;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}
.deno-filter-bar label { font-weight: 600; font-size: 14px; margin-bottom: 4px; display: block; }
.deno-filter-bar .form-control { font-size: 14px; padding: 8px 12px; min-width: 180px; }
.deno-table-section { margin-top: 36px; }
.deno-table-section h5 { font-weight: 700; color: #343a40; margin-bottom: 14px; }
.table-container { background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,.08); }
.table { width:100%; border-collapse:collapse; font-size:13px; }
.table th, .table td { padding:9px 8px; text-align:left; border-bottom:1px solid #dee2e6; vertical-align:middle; }
.table th { background:#f8f9fa; font-weight:700; color:#495057; font-size:12px; text-transform:uppercase; }
.table tbody tr:hover { background:#f5f5f5; }

/* Datepicker input override so it fills nicely */
.ndp-input-d2m {
    width: 100%;
    padding: 8px 12px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 14px;
    box-sizing: border-box;
    font-family: inherit;
}
.ndp-input-d2m:focus { outline: none; border-color: #86b7fe; box-shadow: 0 0 0 2px rgba(13,110,253,.25); }
</style>

<div class="container mt-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white d-flex justify-content-between align-items-center">
            <h5 class="mb-0">📋 Create Deno to Marketing (D2M) - Each DENO Separate</h5>
            <span class="badge bg-light text-primary">Fiscal Year: <?= htmlspecialchars($active_fy_row['fiscal_name'] ?? $active_fy_row['fiscal_code'] ?? 'N/A') ?></span>
        </div>

        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" id="d2mForm">
                <div class="row mb-3">
                    <!-- ── Nepali Date (NDP) ── -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold">
                            Nepali Date <span class="text-danger">*</span>
                        </label>
                        <input type="text"
                               name="nep_date"
                               id="nep_date"
                               class="ndp-input-d2m"
                               placeholder="2082.09.03"
                               value="<?= htmlspecialchars($_POST['nep_date'] ?? '') ?>"
                               autocomplete="off"
                               required>
                        <small class="text-muted">Format: YYYY.MM.DD</small>
                    </div>

                    <!-- ── English Date (auto-filled, but user can also pick) ── -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold">
                            English Date <span class="text-danger">*</span>
                        </label>
                        <input type="hidden" name="eng_date" id="eng_date_hidden"
                               value="<?= htmlspecialchars($_POST['eng_date'] ?? '') ?>">
                        <div style="position:relative;">
                            <div id="eng_display_d2m"
                                 style="
                                    width:100%; padding:8px 12px; border:1px solid #ced4da;
                                    border-radius:4px; font-size:14px; min-height:38px;
                                    background:#fff; color:#333; cursor:pointer;
                                    line-height:22px; position:relative; z-index:1;
                                 ">
                                <?php
                                $engInit = $_POST['eng_date'] ?? '';
                                echo htmlspecialchars($engInit);
                                ?>
                            </div>
                            <input type="date"
                                   id="eng_date_native"
                                   value="<?= htmlspecialchars(str_replace('.', '-', $_POST['eng_date'] ?? '')) ?>"
                                   min="1944-01-01" max="2044-12-31"
                                   style="
                                        position:absolute; top:0; left:0;
                                        width:100%; height:100%;
                                        opacity:0; cursor:pointer; z-index:2;
                                        margin:0; padding:0; border:none;
                                   ">
                        </div>
                        <small class="text-muted">Auto-filled from Nepali date, or pick manually</small>
                    </div>

                    <!-- ── Type ── -->
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Type <span class="text-danger">*</span></label>
                        <select name="type" id="type" class="form-select" required>
                            <option value="">-- Select Type --</option>
                            <option value="NT" <?= (isset($_POST['type']) && $_POST['type'] == 'NT') ? 'selected' : '' ?>>
                                Non-Translated (NT)
                            </option>
                            <option value="T" <?= (isset($_POST['type']) && $_POST['type'] == 'T') ? 'selected' : '' ?>>
                                Translated (T)
                            </option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <div>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                        <button type="button" id="previewBtn" class="btn btn-info">
                            <i class="fas fa-eye"></i> Preview DENO Records
                        </button>
                    </div>
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-check-circle"></i> Generate D2M
                    </button>
                </div>
            </form>

            <?php if (isset($preview_data) && !empty($preview_data)): ?>
            <div class="preview-section">
                <div class="preview-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list-alt"></i>
                        Preview: DENO Records for <?= htmlspecialchars($nep_date) ?>
                        (<?= $type == 'T' ? 'Translated' : 'Non-Translated' ?>)
                    </h5>
                </div>

                <div class="alert alert-info">
                    <strong>Total DENO Records:</strong> <?= count($preview_data) ?> records
                    <br><strong>Note:</strong> Each DENO will be a separate line item in the D2M report
                </div>

                <?php
                $total_poka = 0;
                $total_qty  = 0;
                $total_open = 0;
                foreach ($preview_data as $item):
                    $total_poka += $item['poka_qty'];
                    $total_qty  += $item['total_qty'];
                    $total_open += $item['quantity_openpcs'];
                ?>
                <div class="deno-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div style="flex:1;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge-custom badge-deno">DENO #<?= $item['deno_id'] ?></span>
                                <strong><?= htmlspecialchars($item['book_name']) ?></strong>
                            </div>
                            <div style="font-size:13px;color:#666;">
                                <strong>Ref No:</strong> <?= htmlspecialchars($item['ref_no']) ?> |
                                <strong>Code:</strong> <?= htmlspecialchars($item['book_code']) ?> |
                                <strong>Class:</strong> <?= htmlspecialchars($item['class_level']) ?>
                            </div>
                        </div>
                        <div class="text-end" style="min-width:300px;">
                            <div style="font-size:13px;">
                                <strong>Per Poka:</strong> <?= number_format($item['per_poka_qty']) ?> |
                                <strong>Poka Qty:</strong> <?= number_format($item['poka_qty']) ?>
                            </div>
                            <div style="font-size:13px;">
                                <strong>Total Books:</strong> <?= number_format($item['total_qty']) ?> |
                                <strong>Open Pcs:</strong> <?= number_format($item['quantity_openpcs']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="summary-box">
                    <h6><strong>Grand Totals:</strong></h6>
                    <div class="row">
                        <div class="col-md-3"><strong>Total DENO Records:</strong> <?= count($preview_data) ?></div>
                        <div class="col-md-3"><strong>Total Poka:</strong> <?= number_format($total_poka) ?></div>
                        <div class="col-md-3"><strong>Total Books:</strong> <?= number_format($total_qty) ?></div>
                        <div class="col-md-3"><strong>Total Open Pcs:</strong> <?= number_format($total_open) ?></div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <button type="button" class="btn btn-success btn-lg"
                            onclick="document.getElementById('d2mForm').submit()">
                        <i class="fas fa-check-circle"></i>
                        Confirm and Create D2M (<?= count($preview_data) ?> separate items)
                    </button>
                </div>
            </div>
            <?php elseif (isset($_GET['preview'])): ?>
            <div class="preview-section">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    No DENO records found for the selected date and type.
                </div>
            </div>
            <?php endif; ?>
        </div><!-- /card-body -->
    </div><!-- /card -->

    <!-- ══════════════════════════════════════════════════════════
         DENO RECORDS — Date-wise filter
         ══════════════════════════════════════════════════════════ -->
    <div class="deno-table-section mt-5">
        <h5>📅 Deno Records (Date-wise Filter)</h5>

        <form method="GET" id="filterForm" class="deno-filter-bar">
            <!-- preserve preview params if any -->
            <?php if (isset($_GET['preview'])): ?>
                <input type="hidden" name="preview" value="<?= htmlspecialchars($_GET['preview']) ?>">
                <input type="hidden" name="nep_date_preview" value="<?= htmlspecialchars($_GET['nep_date'] ?? '') ?>">
                <input type="hidden" name="type" value="<?= htmlspecialchars($_GET['type'] ?? '') ?>">
            <?php endif; ?>

            <div>
                <label for="filter_nep_date_input">Filter by Nepali Date:</label>
                <input type="text"
                       id="filter_nep_date_input"
                       name="filter_date"
                       class="ndp-input-d2m form-control"
                       placeholder="2082.09.03"
                       value="<?= htmlspecialchars($filter_date) ?>"
                       autocomplete="off"
                       style="min-width:200px;">
            </div>
            <div>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-search"></i> Filter
                </button>
                <?php if ($filter_date): ?>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-outline-secondary">
                    Clear
                </a>
                <?php endif; ?>
            </div>
            <?php if ($filter_date): ?>
            <div class="ms-auto">
                <span class="badge bg-info fs-6">
                    <?= count($deno_table_records) ?> record(s) for <strong><?= htmlspecialchars($filter_date) ?></strong>
                </span>
            </div>
            <?php endif; ?>
        </form>

        <div class="table-container">
            <table class="table">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Book</th>
                        <th>Ref No</th>
                        <th>Nepali Date</th>
                        <th>English Date</th>
                        <th>Per Poka</th>
                        <th>Pokas</th>
                        <th>Total</th>
                        <th>Open Pcs</th>
                        <th>Created By</th>
                        <th>Sender By</th>
                        <th>Received By</th>
                    </tr>
                </thead>
                <tbody>
                <?php if (empty($deno_table_records)): ?>
                    <tr>
                        <td colspan="12" class="text-center text-muted py-3">
                            <?= $filter_date ? 'No records found for this date.' : 'No deno records available.' ?>
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($deno_table_records as $rec): ?>
                    <tr>
                        <td><?= $rec['id'] ?></td>
                        <td><?= htmlspecialchars($rec['book_name'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($rec['ref_no']) ?></td>
                        <td><?= htmlspecialchars($rec['deno_date_nep']) ?></td>
                        <td><?= htmlspecialchars($rec['deno_date_eng']) ?></td>
                        <td><?= number_format($rec['per_poka_qty']) ?></td>
                        <td><?= number_format($rec['poka_qty']) ?></td>
                        <td><?= number_format($rec['total_qty']) ?></td>
                        <td><?= number_format($rec['quantity_openpcs']) ?></td>
                        <td><?= htmlspecialchars($rec['created_user'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($rec['sender_user'] ?? '-') ?></td>
                        <td><?= htmlspecialchars($rec['received_user'] ?? '-') ?></td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
                </tbody>
            </table>
        </div>
        <?php if (!$filter_date): ?>
            <p class="text-muted mt-2" style="font-size:12px;">
                Showing latest 20 records. Use the date filter above to narrow results.
            </p>
        <?php endif; ?>
    </div>
</div><!-- /container -->

<!-- Nepali Datepicker JS -->
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ─── Helpers ─── */
    var engHidden  = document.getElementById('eng_date_hidden');
    var engDisplay = document.getElementById('eng_display_d2m');
    var engNative  = document.getElementById('eng_date_native');
    var nepField   = document.getElementById('nep_date');
    var blockNep   = false;

    function fillEngFields(dotVal) {
        if (!dotVal) return;
        engHidden.value        = dotVal;
        engDisplay.textContent = dotVal;
        engNative.value        = dotVal.replace(/\./g, '-');
    }

    /* ── NDP for main D2M form Nepali date ── */
    nepField.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function () {
            if (blockNep) return;
            var bsVal = nepField.value.trim();
            if (!bsVal) return;
            try {
                var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (adVal) fillEngFields(adVal);
            } catch (e) { console.warn('BS→AD:', e); }
        }
    });

    /* Manual Nepali typing → convert on blur */
    nepField.addEventListener('blur', function () {
        if (blockNep) return;
        var bsVal = this.value.trim();
        if (!bsVal) return;
        try {
            var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adVal) fillEngFields(adVal);
        } catch (e) { /* invalid */ }
    });

    /* English date native picker → back-convert to Nepali */
    engNative.addEventListener('change', function () {
        var nativeVal = this.value;
        if (!nativeVal) return;
        var dotVal = nativeVal.replace(/-/g, '.');
        fillEngFields(dotVal);
        try {
            var bsVal = NepaliFunctions.AD2BS(dotVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (bsVal) {
                blockNep       = true;
                nepField.value = bsVal;
                blockNep       = false;
            }
        } catch (e) { blockNep = false; }
    });

    /* ── NDP for date-wise filter ── */
    var filterNepField = document.getElementById('filter_nep_date_input');
    if (filterNepField) {
        filterNepField.NepaliDatePicker({
            dateFormat: 'YYYY.MM.DD',
            onDateSelect: function () {
                // Auto-submit filter form when date is picked
                document.getElementById('filterForm').submit();
            }
        });

        /* Also allow manual blur submit */
        filterNepField.addEventListener('blur', function () {
            var v = this.value.trim();
            if (v && /^\d{4}\.\d{2}\.\d{2}$/.test(v)) {
                document.getElementById('filterForm').submit();
            }
        });
    }

    /* ── Preview button ── */
    document.getElementById('previewBtn').addEventListener('click', function () {
        var nepDate = nepField.value.trim();
        var type    = document.getElementById('type').value;

        if (!nepDate || !type) {
            alert('Please select both Nepali Date and Type before previewing.');
            return;
        }
        if (!/^\d{4}\.\d{2}\.\d{2}$/.test(nepDate)) {
            alert('Please enter date in correct format (YYYY.MM.DD)');
            return;
        }
        window.location.href = '?preview=1&nep_date=' + encodeURIComponent(nepDate) +
                               '&type=' + encodeURIComponent(type);
    });

    /* ── Form validation on submit ── */
    document.getElementById('d2mForm').addEventListener('submit', function (e) {
        var nepDate = nepField.value.trim();
        if (!/^\d{4}\.\d{2}\.\d{2}$/.test(nepDate)) {
            e.preventDefault();
            alert('Please enter Nepali date in correct format (YYYY.MM.DD)');
            return false;
        }
        /* ensure hidden eng_date is populated */
        if (!engHidden.value) {
            try {
                var adVal = NepaliFunctions.BS2AD(nepDate, 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (adVal) fillEngFields(adVal);
            } catch (e) {}
        }
        var submitBtn = document.getElementById('submitBtn');
        submitBtn.disabled = true;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating D2M...';
    });
});
</script>

<?php include "../includes/footer.php"; ?>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/lib/AuditLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ─── Auth ───
$current_user    = $_SESSION['username'] ?? 'system';
$current_user_id = $_SESSION['user_id']  ?? null;

// ─── Get record ID ───
$edit_id = (int)($_GET['id'] ?? $_GET['edit_id'] ?? 0);
if (!$edit_id) {
    header('Location: index.php?error=No+record+ID+provided');
    exit();
}

$auditLogger = new AuditLogger($conn, 'DenoEdit', 'Deno');

// ─── Fetch users by role ───
$marketing_users = $conn->query("SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$press_users     = $conn->query("SELECT id, username FROM users WHERE role = 'press'      ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════
   POST handler  — POST / REDIRECT / GET
   ═══════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    try {
        $auditLogger->prepareForAudit();

        // Duplicate ref_no + different date check
        $check = $conn->prepare("
            SELECT id FROM deno
            WHERE ref_no        = :ref_no
              AND deno_date_nep != :deno_date_nep
              AND id            != :id
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $check->execute([
            ':ref_no'        => $_POST['ref_no'],
            ':deno_date_nep' => $_POST['deno_date_nep'],
            ':id'            => $edit_id,
        ]);
        if ($check->fetch()) {
            $_SESSION['flash'] = [
                'type' => 'danger',
                'msg'  => 'Error: Reference number ' . htmlspecialchars($_POST['ref_no']) . ' already exists with a different date.',
            ];
            header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $edit_id);
            exit;
        }

        $conn->prepare("
            UPDATE deno SET
                book_code         = :book_code,
                ref_no            = :ref_no,
                deno_date_nep     = :deno_date_nep,
                deno_date_eng     = :deno_date_eng,
                per_poka_qty      = :per_poka_qty,
                poka_qty          = :poka_qty,
                quantity_openpcs  = :quantity_openpcs,
                notes             = :notes,
                sender_by         = :sender_by,
                received_by       = :received_by,
                verify_by         = :verify_by,
                update_remarks    = :update_remarks,
                updated_by        = :updated_by,
                updated_at        = CURRENT_TIMESTAMP
            WHERE id = :id
        ")->execute([
            ':id'              => $edit_id,
            ':book_code'       => $_POST['book_code'],
            ':ref_no'          => $_POST['ref_no'],
            ':deno_date_nep'   => $_POST['deno_date_nep'],
            ':deno_date_eng'   => $_POST['deno_date_eng'],
            ':per_poka_qty'    => $_POST['per_poka_qty'],
            ':poka_qty'        => $_POST['poka_qty'],
            ':quantity_openpcs'=> $_POST['quantity_openpcs'] ?? 0,
            ':notes'           => $_POST['notes'],
            ':sender_by'       => $_POST['sender_by']   ?: null,
            ':received_by'     => $_POST['received_by'] ?: null,
            ':verify_by'       => $_POST['verify_by']   ?: null,
            ':update_remarks'  => $_POST['update_remarks'],
            ':updated_by'      => $current_user_id,
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Deno record updated successfully!'];
        header('Location: index.php');
        exit;

    } catch (PDOException $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Database error: ' . htmlspecialchars($e->getMessage())];
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $edit_id);
        exit;
    }
}

// ─── Fetch the record to edit ───
try {
    $stmt = $conn->prepare("
        SELECT d.*,
               b.book_name, b.class_level,
               fy.fiscal_name,
               u1.username AS created_user,
               u2.username AS received_user,
               u3.username AS verified_user,
               u4.username AS sender_user
        FROM deno d
        LEFT JOIN books b  ON d.book_code   = b.book_code
        LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
        LEFT JOIN users u1 ON d.created_by  = u1.id
        LEFT JOIN users u2 ON d.received_by = u2.id
        LEFT JOIN users u3 ON d.verify_by   = u3.id
        LEFT JOIN users u4 ON d.sender_by   = u4.id
        WHERE d.id = :id AND d.deleted_at IS NULL
    ");
    $stmt->execute([':id' => $edit_id]);
    $record = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$record) {
        header('Location: index.php?error=Record+not+found');
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=' . urlencode('Database error: ' . $e->getMessage()));
    exit();
}

// ─── Books dropdown ───
$books = $conn->query("SELECT book_code, book_name, class_level FROM books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

// ─── Prepare English date for native <input type="date"> ───
$editEngForNativePicker = '';
if (!empty($record['deno_date_eng'])) {
    $editEngForNativePicker = str_replace('.', '-', $record['deno_date_eng']);
}

// ─── Read & clear flash ───
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);

// ─── Now safe to output HTML ───
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<!-- Nepali Datepicker v5 CSS -->
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>

<style>
body { font-size:16px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }

.edit-container {
    max-width: 960px;
    margin: 0 auto;
    background: #fff;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,.1);
}

.page-header {
    text-align: center;
    margin-bottom: 24px;
    padding-bottom: 18px;
    border-bottom: 3px solid #ffc107;
}
.page-header h2 { color:#333; margin:0; font-size:26px; }

.breadcrumb { background:none; padding:0; margin-bottom:16px; font-size:14px; }
.breadcrumb a { color:#007bff; text-decoration:none; }
.breadcrumb a:hover { text-decoration:underline; }

.alert         { padding:14px 18px; margin-bottom:18px; border:1px solid transparent; border-radius:5px; font-size:15px; font-weight:500; }
.alert-success { color:#155724; background:#d4edda; border-color:#c3e6cb; }
.alert-danger  { color:#721c24; background:#f8d7da; border-color:#f5c6cb; }

.record-info {
    background: #e3f2fd;
    border-left: 4px solid #2196f3;
    padding: 14px 18px;
    margin-bottom: 22px;
    border-radius: 0 6px 6px 0;
    font-size: 14px;
    line-height: 1.7;
}

.form-container { background:#f8f9fa; padding:24px; border-radius:8px; border:1px solid #e9ecef; }

.form-row       { display:flex; gap:16px; margin-bottom:16px; align-items:end; flex-wrap:wrap; }
.form-group     { flex:1; min-width:220px; display:flex; flex-direction:column; }
.form-group label { font-weight:600; color:#495057; margin-bottom:6px; font-size:14px; }

.form-control {
    padding:10px 14px;
    border:1px solid #ddd;
    border-radius:5px;
    font-size:15px;
    box-sizing:border-box;
    font-family:inherit;
    transition: border-color .2s, box-shadow .2s;
}
.form-control:focus  { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.2); }
.form-control:disabled { background:#e9ecef; cursor:not-allowed; color:#6c757d; }

/* Buttons */
.btn { padding:11px 24px; border:none; border-radius:5px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; display:inline-block; text-align:center; margin-right:8px; transition:all .25s ease; }
.btn:hover    { transform:translateY(-1px); box-shadow:0 4px 10px rgba(0,0,0,.18); }
.btn-primary  { background:#007bff; color:#fff; }
.btn-secondary{ background:#6c757d; color:#fff; }
.btn-warning  { background:#ffc107; color:#212529; }
.btn-danger   { background:#dc3545; color:#fff; }
.btn-info     { background:#17a2b8; color:#fff; }

.button-group { text-align:center; margin-top:24px; padding-top:18px; border-top:1px solid #dee2e6; }

/* Book search dropdown */
.search-dropdown  { position:relative; }
.dropdown-options {
    position:absolute; top:100%; left:0; right:0;
    background:#fff; border:1px solid #ddd; border-top:none;
    max-height:210px; overflow-y:auto; z-index:1000; display:none;
    box-shadow:0 4px 12px rgba(0,0,0,.12);
}
.dropdown-option { padding:11px 14px; cursor:pointer; border-bottom:1px solid #eee; font-size:13px; }
.dropdown-option:hover      { background:#f0f7ff; }
.dropdown-option:last-child { border-bottom:none; }

/* Nepali date input */
.ndp-input {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:5px; font-size:15px; box-sizing:border-box;
    background:#fff; font-family:inherit;
}
.ndp-input:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.2); }

/* English date overlay picker */
.date-eng-wrapper { position:relative; }
.date-eng-wrapper .eng-display {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:5px; font-size:15px; box-sizing:border-box;
    background:#fff; color:#333; font-family:inherit;
    min-height:41px; line-height:20px; cursor:pointer; position:relative; z-index:1;
}
.date-eng-wrapper .eng-display:empty::before { content:'Click to pick date…'; color:#999; }
.date-eng-wrapper input[type="date"] {
    position:absolute; top:0; left:0;
    width:100%; height:100%;
    opacity:0; cursor:pointer; z-index:2;
    margin:0; padding:0; border:none;
}

/* Total qty highlight */
#total_qty { background:#fffde7; border-color:#ffc107; font-weight:700; color:#333; }

.calc-note {
    background:#fff8e1; border:1px solid #ffe082;
    border-radius:5px; padding:12px 16px;
    font-size:13px; color:#856404; margin-top:6px;
}

@media (max-width:768px) {
    .form-row { flex-direction:column; }
    .edit-container { margin:10px; padding:18px; }
}
</style>

<div class="edit-container">

    <!-- Breadcrumb -->
    <div class="breadcrumb">
        <a href="index.php">📋 Deno Entries</a> /
        <span>Edit Record #<?= $record['id'] ?></span>
    </div>

    <div class="page-header">
        <h2>✏️ Edit Deno Record #<?= $record['id'] ?></h2>
    </div>

    <!-- Current record info banner -->
    <div class="record-info">
        <strong>📋 Record Info:</strong>&nbsp;
        Book: <strong><?= htmlspecialchars($record['book_name']) ?></strong>
        (<?= htmlspecialchars($record['book_code']) ?>) &nbsp;|&nbsp;
        Ref: <strong><?= htmlspecialchars($record['ref_no']) ?></strong> &nbsp;|&nbsp;
        Deno No: <strong><?= htmlspecialchars($record['deno_no'] ?? '—') ?></strong> &nbsp;|&nbsp;
        Fiscal Year: <strong><?= htmlspecialchars($record['fiscal_name'] ?? '—') ?></strong> &nbsp;|&nbsp;
        Nepali Date: <strong><?= htmlspecialchars($record['deno_date_nep']) ?></strong><br>
        Created by: <strong><?= htmlspecialchars($record['created_user'] ?? '—') ?></strong> &nbsp;|&nbsp;
        Last updated: <strong><?= $record['updated_at'] ? date('Y-m-d H:i', strtotime($record['updated_at'])) : 'Never' ?></strong>
        &nbsp;|&nbsp; Now editing as: <strong><?= htmlspecialchars($current_user) ?></strong>
    </div>

    <?php if ($flash): ?>
        <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
            <?= htmlspecialchars($flash['msg']) ?>
        </div>
    <?php endif; ?>

    <!-- ══ FORM ══ -->
    <div class="form-container">
    <form method="post" id="editDenoForm" onsubmit="return validateForm()">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="id"     value="<?= $record['id'] ?>">

        <!-- Book + Ref No -->
        <div class="form-row">
            <div class="form-group">
                <label>📖 Book:</label>
                <div class="search-dropdown">
                    <input type="text"
                           class="form-control"
                           id="book_search"
                           placeholder="Type to search books…"
                           autocomplete="off">
                    <input type="hidden" name="book_code" id="book_code"
                           value="<?= htmlspecialchars($record['book_code']) ?>">
                    <div class="dropdown-options" id="book_options">
                        <?php foreach ($books as $book): ?>
                        <div class="dropdown-option"
                             data-value="<?= htmlspecialchars($book['book_code']) ?>"
                             data-text="<?= htmlspecialchars($book['book_name']) ?> (<?= htmlspecialchars($book['book_code']) ?>)"
                             data-class="<?= htmlspecialchars($book['class_level']) ?>">
                            <strong><?= htmlspecialchars($book['book_name']) ?></strong><br>
                            <small>Code: <?= htmlspecialchars($book['book_code']) ?> | Class: <?= htmlspecialchars($book['class_level']) ?></small>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>

            <div class="form-group">
                <label for="ref_no">📄 Reference No:</label>
                <input type="text" name="ref_no" id="ref_no" class="form-control"
                       value="<?= htmlspecialchars($record['ref_no']) ?>" required>
            </div>
        </div>

        <!-- Nepali Date + English Date -->
        <div class="form-row">
            <div class="form-group">
                <label for="deno_date_nep">📅 Nepali Date (YYYY.MM.DD):</label>
                <input type="text"
                       id="deno_date_nep"
                       name="deno_date_nep"
                       class="form-control ndp-input"
                       placeholder="e.g. 2082.01.15"
                       value="<?= htmlspecialchars($record['deno_date_nep'] ?? '') ?>"
                       autocomplete="off"
                       required>
            </div>

            <div class="form-group">
                <label>📅 English Date (YYYY.MM.DD):</label>
                <input type="hidden" name="deno_date_eng" id="deno_date_eng_hidden"
                       value="<?= htmlspecialchars($record['deno_date_eng'] ?? '') ?>">
                <div class="date-eng-wrapper">
                    <div class="eng-display" id="eng_display">
                        <?= htmlspecialchars($record['deno_date_eng'] ?? '') ?>
                    </div>
                    <input type="date"
                           id="deno_date_eng_native"
                           value="<?= $editEngForNativePicker ?>"
                           min="1944-01-01"
                           max="2044-12-31">
                </div>
            </div>
        </div>

        <!-- Per Poka + Poka Qty -->
        <div class="form-row">
            <div class="form-group">
                <label for="per_poka_qty">📦 Quantity per Poka:</label>
                <input type="number" name="per_poka_qty" id="per_poka_qty" class="form-control"
                       value="<?= $record['per_poka_qty'] ?>" min="1" required>
            </div>
            <div class="form-group">
                <label for="poka_qty">📊 Number of Pokas:</label>
                <input type="number" name="poka_qty" id="poka_qty" class="form-control"
                       value="<?= $record['poka_qty'] ?>" min="0" required>
            </div>
        </div>

        <!-- Open Pcs + Total Qty (auto-calc, read-only) -->
        <div class="form-row">
            <div class="form-group">
                <label for="quantity_openpcs">📋 Open Pieces:</label>
                <input type="number" name="quantity_openpcs" id="quantity_openpcs" class="form-control"
                       value="<?= $record['quantity_openpcs'] ?? 0 ?>" min="0">
            </div>
            <div class="form-group">
                <label for="total_qty">🎯 Total Quantity (auto-calculated):</label>
                <input type="number" name="total_qty" id="total_qty" class="form-control"
                       value="<?= $record['total_qty'] ?>" readonly>
                <div class="calc-note">
                    💡 Total = (Per Poka × Pokas) + Open Pieces
                </div>
            </div>
        </div>

        <!-- Created By (locked to session user) + Sender By -->
        <div class="form-row">
            <div class="form-group">
                <label for="created_by_display">👤 Created By (locked):</label>
                <input type="text" id="created_by_display" class="form-control"
                       value="<?= htmlspecialchars($record['created_user'] ?? $current_user) ?>" disabled>
                <!-- No hidden field — created_by is never changed on edit -->
            </div>
            <div class="form-group">
                <label for="sender_by">📤 Sender By:</label>
                <select name="sender_by" id="sender_by" class="form-control">
                    <option value="">— Select Sender —</option>
                    <?php foreach ($press_users as $user): ?>
                    <option value="<?= $user['id'] ?>"
                        <?= ($record['sender_by'] == $user['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Received By + Verified By -->
        <div class="form-row">
            <div class="form-group">
                <label for="received_by">📥 Received By:</label>
                <select name="received_by" id="received_by" class="form-control">
                    <option value="">— Select Receiver —</option>
                    <?php foreach ($marketing_users as $user): ?>
                    <option value="<?= $user['id'] ?>"
                        <?= ($record['received_by'] == $user['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="verify_by">✅ Verified By:</label>
                <select name="verify_by" id="verify_by" class="form-control">
                    <option value="">— Select Verifier —</option>
                    <?php foreach ($marketing_users as $user): ?>
                    <option value="<?= $user['id'] ?>"
                        <?= ($record['verify_by'] == $user['id']) ? 'selected' : '' ?>>
                        <?= htmlspecialchars($user['username']) ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>

        <!-- Notes + Update Remarks -->
        <div class="form-row">
            <div class="form-group">
                <label for="notes">📝 Notes:</label>
                <textarea name="notes" id="notes" class="form-control" rows="2"><?= htmlspecialchars($record['notes'] ?? '') ?></textarea>
            </div>
            <div class="form-group">
                <label for="update_remarks">🔖 Update Remarks:</label>
                <textarea name="update_remarks" id="update_remarks" class="form-control" rows="2"><?= htmlspecialchars($record['update_remarks'] ?? '') ?></textarea>
            </div>
        </div>

        <!-- Buttons -->
        <div class="button-group">
            <button type="submit" class="btn btn-warning">💾 Update Record</button>
            <a href="index.php" class="btn btn-secondary">❌ Cancel</a>
            <button type="button" class="btn btn-danger" onclick="confirmDelete(<?= $record['id'] ?>)">🗑️ Delete</button>
        </div>
    </form>
    </div><!-- /form-container -->
</div><!-- /edit-container -->

<!-- Delete hidden form -->
<form id="deleteForm" method="post" action="index.php" style="display:none">
    <input type="hidden" name="action" value="delete">
    <input type="hidden" name="id" id="deleteId">
</form>

<!-- Nepali Datepicker v5 JS -->
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ────────────────────────────────────────
       1.  BOOK SEARCH DROPDOWN
    ──────────────────────────────────────── */
    var searchInput      = document.getElementById('book_search');
    var hiddenInput      = document.getElementById('book_code');
    var optionsContainer = document.getElementById('book_options');
    var options          = optionsContainer.querySelectorAll('.dropdown-option');

    // Pre-fill from existing record
    (function () {
        var code = hiddenInput.value;
        if (!code) return;
        var el = optionsContainer.querySelector('[data-value="' + code + '"]');
        if (el) searchInput.value = el.dataset.text;
    })();

    searchInput.addEventListener('focus', function () {
        filterOptions();
        optionsContainer.style.display = 'block';
    });
    searchInput.addEventListener('input', function () {
        filterOptions();
        optionsContainer.style.display = 'block';
        if (this.value === '') hiddenInput.value = '';
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.search-dropdown')) optionsContainer.style.display = 'none';
    });

    function filterOptions() {
        var t = searchInput.value.toLowerCase();
        options.forEach(function (o) {
            var match = o.textContent.toLowerCase().includes(t) || o.dataset.value.toLowerCase().includes(t);
            o.style.display = match ? 'block' : 'none';
        });
    }

    options.forEach(function (o) {
        o.addEventListener('click', function () {
            searchInput.value  = this.dataset.text;
            hiddenInput.value  = this.dataset.value;
            optionsContainer.style.display = 'none';
        });
    });

    /* ────────────────────────────────────────
       2.  BIDIRECTIONAL DATE CONVERSION
    ──────────────────────────────────────── */
    var nepField   = document.getElementById('deno_date_nep');
    var engHidden  = document.getElementById('deno_date_eng_hidden');
    var engDisplay = document.getElementById('eng_display');
    var engNative  = document.getElementById('deno_date_eng_native');
    var blockNepConversion = false;

    function fillEngFields(dotVal) {
        engHidden.value        = dotVal;
        engDisplay.textContent = dotVal;
        engNative.value        = dotVal.replace(/\./g, '-');
    }

    // Nepali datepicker — BS → AD
    nepField.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function () {
            if (blockNepConversion) return;
            var bsVal = nepField.value.trim();
            if (!bsVal) return;
            try {
                var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (adVal) fillEngFields(adVal);
            } catch (e) { console.warn('BS→AD failed:', e); }
        }
    });

    // English native date picker — AD → BS
    engNative.addEventListener('change', function () {
        var nativeVal = engNative.value;
        if (!nativeVal) return;
        var dotVal = nativeVal.replace(/-/g, '.');
        fillEngFields(dotVal);
        try {
            var bsVal = NepaliFunctions.AD2BS(dotVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (bsVal) {
                blockNepConversion = true;
                nepField.value     = bsVal;
                blockNepConversion = false;
            }
        } catch (e) { console.warn('AD→BS failed:', e); blockNepConversion = false; }
    });

    // Manual type in Nepali field → convert on blur
    nepField.addEventListener('blur', function () {
        if (blockNepConversion) return;
        var bsVal = nepField.value.trim();
        if (!bsVal) return;
        try {
            var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adVal) fillEngFields(adVal);
        } catch (e) { /* invalid input — leave as-is */ }
    });

    /* ────────────────────────────────────────
       3.  AUTO-CALCULATE TOTAL QTY
    ──────────────────────────────────────── */
    function calcTotal() {
        var perPoka  = parseInt(document.getElementById('per_poka_qty').value)    || 0;
        var pokaQty  = parseInt(document.getElementById('poka_qty').value)         || 0;
        var openPcs  = parseInt(document.getElementById('quantity_openpcs').value) || 0;
        var total    = (perPoka * pokaQty) + openPcs;
        document.getElementById('total_qty').value = total;
    }

    document.getElementById('per_poka_qty').addEventListener('input', calcTotal);
    document.getElementById('poka_qty').addEventListener('input', calcTotal);
    document.getElementById('quantity_openpcs').addEventListener('input', calcTotal);

    // Run once on load so the displayed value is always consistent
    calcTotal();

    /* ────────────────────────────────────────
       4.  UNSAVED-CHANGES WARNING
    ──────────────────────────────────────── */
    var formDirty = false;
    document.getElementById('editDenoForm').addEventListener('input', function () { formDirty = true; });
    window.addEventListener('beforeunload', function (e) {
        if (formDirty) {
            e.preventDefault();
            e.returnValue = '';
            return 'You have unsaved changes.';
        }
    });
    document.getElementById('editDenoForm').addEventListener('submit', function () { formDirty = false; });
});

/* ────────────────────────────────────────
   5.  FORM VALIDATION
──────────────────────────────────────── */
function validateForm() {
    var bookCode = document.getElementById('book_code').value;
    var refNo    = document.getElementById('ref_no').value.trim();
    var nepDate  = document.getElementById('deno_date_nep').value.trim();
    var perPoka  = document.getElementById('per_poka_qty').value;
    var pokaQty  = document.getElementById('poka_qty').value;

    if (!bookCode) {
        alert('Please select a book from the dropdown.');
        document.getElementById('book_search').focus();
        return false;
    }
    if (!refNo) {
        alert('Please enter a reference number.');
        document.getElementById('ref_no').focus();
        return false;
    }
    if (!/^\d{4}\.\d{2}\.\d{2}$/.test(nepDate)) {
        alert('Please enter Nepali date in YYYY.MM.DD format (e.g. 2082.04.01).');
        document.getElementById('deno_date_nep').focus();
        return false;
    }
    if (!perPoka || parseInt(perPoka) < 1) {
        alert('Per Poka Quantity must be at least 1.');
        document.getElementById('per_poka_qty').focus();
        return false;
    }
    if (pokaQty === '' || parseInt(pokaQty) < 0) {
        alert('Number of Pokas must be 0 or more.');
        document.getElementById('poka_qty').focus();
        return false;
    }

    // Loading state
    var btn = document.querySelector('button[type="submit"]');
    btn.innerHTML = '💾 Updating…';
    btn.disabled  = true;
    return true;
}

/* ────────────────────────────────────────
   6.  DELETE CONFIRMATION
──────────────────────────────────────── */
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this record? This cannot be undone.')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
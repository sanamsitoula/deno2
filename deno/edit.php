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

        $entry_type = $_POST['entry_type'] ?? 'direct';
        if (!in_array($entry_type, ['direct', 'from_jt', 'from_bp'], true)) {
            $entry_type = 'direct';
        }

        $required = ['ref_no', 'deno_date_nep', 'deno_date_eng', 'per_poka_qty', 'poka_qty'];
        if ($entry_type === 'direct')  $required[] = 'book_code';
        if ($entry_type === 'from_jt') $required[] = 'jt_id';
        if ($entry_type === 'from_bp') $required[] = 'bp_id';

        foreach ($required as $f) {
            if (empty($_POST[$f])) {
                throw new Exception("Field '{$f}' is required");
            }
        }

        // Resolve book_code / jt_id / bp_id from the selected mode
        $book_code = null;
        $jt_id     = null;
        $bp_id     = null;

        if ($entry_type === 'direct') {
            $book_code = $_POST['book_code'];

        } elseif ($entry_type === 'from_jt') {
            $jt_id = (int)$_POST['jt_id'];
            $stmt = $conn->prepare("
                SELECT b.book_code
                FROM   job_ticket jt
                LEFT JOIN books b ON jt.book_id = b.book_id
                WHERE  jt.id = :jt_id
            ");
            $stmt->execute([':jt_id' => $jt_id]);
            $r = $stmt->fetch();
            if (!$r) throw new Exception('Selected Job Ticket was not found.');
            $book_code = $r['book_code'];

        } elseif ($entry_type === 'from_bp') {
            $bp_id = (int)$_POST['bp_id'];
            $stmt = $conn->prepare("SELECT book_code, jt_id FROM book_packing WHERE id = :bp_id");
            $stmt->execute([':bp_id' => $bp_id]);
            $r = $stmt->fetch();
            if (!$r) throw new Exception('Selected Book Packing record was not found.');
            $book_code = $r['book_code'];
            $jt_id     = $r['jt_id'];
        }

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

        // Received By / Verified By / Update Remarks ARE editable here — this
        // page is the marketing step where those fields actually get set.
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
                updated_at        = CURRENT_TIMESTAMP,
                entry_type        = :entry_type,
                jt_id             = :jt_id,
                bp_id             = :bp_id
            WHERE id = :id
        ")->execute([
            ':id'              => $edit_id,
            ':book_code'       => $book_code,
            ':ref_no'          => $_POST['ref_no'],
            ':deno_date_nep'   => $_POST['deno_date_nep'],
            ':deno_date_eng'   => $_POST['deno_date_eng'],
            ':per_poka_qty'    => $_POST['per_poka_qty'],
            ':poka_qty'        => $_POST['poka_qty'],
            ':quantity_openpcs'=> $_POST['quantity_openpcs'] ?? 0,
            ':notes'           => $_POST['notes'] ?? null,
            ':sender_by'       => $_POST['sender_by']   ?? null,
            ':received_by'     => $_POST['received_by'] ?? null,
            ':verify_by'       => $_POST['verify_by']   ?? null,
            ':update_remarks'  => $_POST['update_remarks'] ?? null,
            ':updated_by'      => $current_user_id,
            ':entry_type'      => $entry_type,
            ':jt_id'           => $jt_id,
            ':bp_id'           => $bp_id,
        ]);

        $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Deno record updated successfully!'];
        header('Location: index.php');
        exit;

    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error: ' . htmlspecialchars($e->getMessage())];
        header('Location: ' . $_SERVER['PHP_SELF'] . '?id=' . $edit_id);
        exit;
    }
}

// ─── Fetch the record to edit (with JT / BP labels for the search boxes) ───
try {
    $stmt = $conn->prepare("
        SELECT d.*,
               b.book_name, b.class_level,
               fy.fiscal_name,
               jt.job_ticket_code, jt.lot AS jt_lot, jt.print_qty AS jt_print_qty,
               bp.name AS bp_name, bp.p_qty AS bp_p_qty,
               u1.username AS created_user,
               u2.username AS received_user,
               u3.username AS verified_user,
               u4.username AS sender_user
        FROM deno d
        LEFT JOIN books b         ON d.book_code = b.book_code
        LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
        LEFT JOIN job_ticket jt   ON d.jt_id = jt.id
        LEFT JOIN book_packing bp ON d.bp_id = bp.id
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

$entry_type_val = $record['entry_type'] ?? 'direct';

$edit_book_label = !empty($record['book_code'])
    ? $record['book_name'] . ' (' . $record['book_code'] . ')'
    : '';
$edit_jt_label = !empty($record['job_ticket_code'])
    ? $record['job_ticket_code'] . ' — ' . ($record['book_name'] ?? '')
    : '';
$edit_bp_label = !empty($record['bp_name'])
    ? $record['bp_name'] . ' — ' . ($record['book_name'] ?? '')
    : '';

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

/* ── Entry type selector ── */
.entry-type-selector { display:flex; gap:15px; margin-bottom:20px; padding:16px; background:#eef2f6; border-radius:8px; flex-wrap:wrap; }
.entry-type-option   { flex:1; min-width:180px; position:relative; }
.entry-type-option input[type="radio"] { position:absolute; opacity:0; }
.entry-type-option label {
    display:block; padding:16px; background:#fff; border:2px solid #dee2e6;
    border-radius:8px; cursor:pointer; text-align:center; transition:all .2s ease;
}
.entry-type-option input[type="radio"]:checked + label { border-color:#007bff; background:#e7f3ff; font-weight:600; }
.entry-type-option label:hover { border-color:#007bff; }
.entry-type-icon  { font-size:28px; margin-bottom:6px; }
.entry-type-title { font-size:15px; font-weight:600; margin-bottom:2px; }
.entry-type-desc  { font-size:12px; color:#6c757d; }
.mode-panel.hidden { display:none; }

/* Searchable dropdown (book / job ticket / book packing) */
.search-dropdown  { position:relative; }
.dropdown-options {
    position:absolute; top:100%; left:0; right:0;
    background:#fff; border:1px solid #ddd; border-top:none;
    max-height:220px; overflow-y:auto; z-index:1000; display:none;
    box-shadow:0 4px 12px rgba(0,0,0,.12);
}
.dropdown-option { padding:11px 14px; cursor:pointer; border-bottom:1px solid #eee; font-size:13px; }
.dropdown-option:hover      { background:#f0f7ff; }
.dropdown-option:last-child { border-bottom:none; }

.info-box {
    background:#e7f3ff; border:1px solid #b3d9ff; border-radius:8px;
    padding:14px; margin-top:10px;
}
.info-box.hidden { display:none; }
.info-row   { display:flex; justify-content:space-between; margin-bottom:6px; font-size:13px; }
.info-label { font-weight:600; color:#0066cc; }
.info-value { color:#333; }

.summary-box {
    background:#fff3cd; border:2px solid #ffc107; border-radius:8px;
    padding:14px; margin-top:10px;
}
.summary-box.hidden { display:none; }
.summary-row { display:flex; justify-content:space-between; padding:6px 0; border-bottom:1px solid #ffe69c; font-size:14px; }
.summary-row:last-child { border-bottom:none; font-weight:700; }

.validation-warning {
    background:#fff3cd; border:2px solid #ffc107; color:#856404;
    padding:12px 14px; border-radius:8px; margin:0 0 16px 0;
    font-size:14px; font-weight:600; display:none;
}
.validation-warning.show { display:block; animation:shake .4s; }
@keyframes shake {
    0%,100% { transform:translateX(0); }
    20%,60% { transform:translateX(-4px); }
    40%,80% { transform:translateX(4px); }
}

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
        Book: <strong><?= htmlspecialchars($record['book_name'] ?? '-') ?></strong>
        (<?= htmlspecialchars($record['book_code'] ?? '-') ?>) &nbsp;|&nbsp;
        Ref: <strong><?= htmlspecialchars($record['ref_no']) ?></strong> &nbsp;|&nbsp;
        Deno No: <strong><?= htmlspecialchars($record['deno_no'] ?? '—') ?></strong> &nbsp;|&nbsp;
        Fiscal Year: <strong><?= htmlspecialchars($record['fiscal_name'] ?? '—') ?></strong> &nbsp;|&nbsp;
        Nepali Date: <strong><?= htmlspecialchars($record['deno_date_nep']) ?></strong><br>
        Entry Type: <strong><?= htmlspecialchars(ucfirst(str_replace('_', ' ', $entry_type_val))) ?></strong>
        <?php if ($edit_jt_label): ?> &nbsp;|&nbsp; JT: <strong><?= htmlspecialchars($record['job_ticket_code']) ?></strong><?php endif; ?>
        <?php if ($edit_bp_label): ?> &nbsp;|&nbsp; BP: <strong><?= htmlspecialchars($record['bp_name']) ?></strong><?php endif; ?>
        <br>
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

        <!-- Entry type selector -->
        <div class="entry-type-selector">
            <div class="entry-type-option">
                <input type="radio" name="entry_type" id="type_direct" value="direct"
                       <?= $entry_type_val === 'direct' ? 'checked' : '' ?>>
                <label for="type_direct">
                    <div class="entry-type-icon">📚</div>
                    <div class="entry-type-title">Direct Entry</div>
                    <div class="entry-type-desc">Manual book entry</div>
                </label>
            </div>
            <div class="entry-type-option">
                <input type="radio" name="entry_type" id="type_from_jt" value="from_jt"
                       <?= $entry_type_val === 'from_jt' ? 'checked' : '' ?>>
                <label for="type_from_jt">
                    <div class="entry-type-icon">🎫</div>
                    <div class="entry-type-title">From Job Ticket</div>
                    <div class="entry-type-desc">Link to existing JT</div>
                </label>
            </div>
            <div class="entry-type-option">
                <input type="radio" name="entry_type" id="type_from_bp" value="from_bp"
                       <?= $entry_type_val === 'from_bp' ? 'checked' : '' ?>>
                <label for="type_from_bp">
                    <div class="entry-type-icon">📦</div>
                    <div class="entry-type-title">From Book Packing</div>
                    <div class="entry-type-desc">Link to packing record</div>
                </label>
            </div>
        </div>

        <!-- Mode: Direct -->
        <div id="direct_mode" class="mode-panel">
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label>📖 Book:</label>
                    <div class="search-dropdown">
                        <input type="text" class="form-control dropdown-search" id="book_search"
                               placeholder="Type to search book by name or code…" autocomplete="off"
                               value="<?= htmlspecialchars($edit_book_label) ?>">
                        <input type="hidden" name="book_code" id="book_code"
                               value="<?= htmlspecialchars($record['book_code'] ?? '') ?>">
                        <div class="dropdown-options" id="book_options"></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mode: From Job Ticket -->
        <div id="jt_mode" class="mode-panel hidden">
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label>🎫 Job Ticket:</label>
                    <div class="search-dropdown">
                        <input type="text" class="form-control dropdown-search" id="jt_search"
                               placeholder="Type to search job ticket code, book, or lot…" autocomplete="off"
                               value="<?= htmlspecialchars($edit_jt_label) ?>">
                        <input type="hidden" name="jt_id" id="jt_id"
                               value="<?= htmlspecialchars($record['jt_id'] ?? '') ?>">
                        <div class="dropdown-options" id="jt_options"></div>
                    </div>
                    <div class="info-box <?= $edit_jt_label ? '' : 'hidden' ?>" id="jt_info_box">
                        <div class="info-row"><span class="info-label">Book:</span><span class="info-value" id="jt_book_name"><?= htmlspecialchars($record['book_name'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Lot:</span><span class="info-value" id="jt_lot"><?= htmlspecialchars($record['jt_lot'] ?? '-') ?></span></div>
                        <div class="info-row"><span class="info-label">Print Qty:</span><span class="info-value" id="jt_print_qty"><?= $record['jt_print_qty'] ? number_format($record['jt_print_qty']) : '-' ?></span></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Mode: From Book Packing -->
        <div id="bp_mode" class="mode-panel hidden">
            <div class="form-row">
                <div class="form-group" style="flex:2;">
                    <label>📦 Book Packing:</label>
                    <div class="search-dropdown">
                        <input type="text" class="form-control dropdown-search" id="bp_search"
                               placeholder="Type to search packing name, book, or JT code…" autocomplete="off"
                               value="<?= htmlspecialchars($edit_bp_label) ?>">
                        <input type="hidden" name="bp_id" id="bp_id"
                               value="<?= htmlspecialchars($record['bp_id'] ?? '') ?>">
                        <div class="dropdown-options" id="bp_options"></div>
                    </div>
                    <div class="summary-box hidden" id="bp_summary_box">
                        <strong style="display:block;margin-bottom:10px;">📊 Production Summary</strong>
                        <div class="summary-row"><span>Job Ticket:</span><strong id="bp_jt_code">-</strong></div>
                        <div class="summary-row"><span>Total Print Qty:</span><strong id="bp_total_print">0</strong></div>
                        <div class="summary-row"><span>Total Packed (All BP):</span><strong id="bp_total_packed">0</strong></div>
                        <div class="summary-row"><span>Total Deno Entries:</span><strong id="bp_total_deno_entries">0</strong></div>
                        <div class="summary-row"><span>Total Deno Qty:</span><strong id="bp_total_deno_qty">0</strong></div>
                        <div class="summary-row" style="background:#fff;padding:8px;border-radius:4px;">
                            <span>Remaining to Process:</span><strong id="bp_remaining" style="color:#dc3545;">0</strong>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="validation-warning" id="quantity_warning">⚠️ <span id="warning_message"></span></div>

        <!-- Ref No -->
        <div class="form-row">
            <div class="form-group">
                <label for="ref_no">📄 Reference No:</label>
                <input type="text" name="ref_no" id="ref_no" class="form-control"
                       value="<?= htmlspecialchars($record['ref_no']) ?>" required>
            </div>
            <div class="form-group">
                <label>📅 Fiscal Year:</label>
                <input type="text" class="form-control" value="<?= htmlspecialchars($record['fiscal_name'] ?? '—') ?>" disabled>
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

        <!-- Received By + Verified By — editable HERE (this is the marketing step) -->
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
/**
 * Generic AJAX-backed searchable dropdown (same contract as create.php).
 * Only pulls a small page of matches from search_lookup.php instead of
 * dumping whole tables into the page.
 */
function initSearchDropdown(opts) {
    var input  = document.getElementById(opts.inputId);
    var hidden = document.getElementById(opts.hiddenId);
    var box    = document.getElementById(opts.optionsId);
    var timer  = null;
    var items  = [];
    var activeIdx = -1;

    function render(list) {
        items = list;
        activeIdx = -1;
        box.innerHTML = '';
        if (!list.length) {
            box.innerHTML = '<div class="dropdown-option" style="color:#999;cursor:default;">No matches found</div>';
        } else {
            list.forEach(function (item, i) {
                var d = document.createElement('div');
                d.className = 'dropdown-option';
                d.innerHTML = '<div>' + item.label + '</div>' +
                    (item.sublabel ? '<small style="color:#888">' + item.sublabel + '</small>' : '');
                d.addEventListener('click', function () { select(i); });
                box.appendChild(d);
            });
        }
        box.style.display = 'block';
    }

    function select(i) {
        var item = items[i];
        if (!item) return;
        hidden.value = item.value;
        input.value  = item.label;
        box.style.display = 'none';
        if (opts.onSelect) opts.onSelect(item);
    }

    function search(term) {
        fetch('search_lookup.php?type=' + encodeURIComponent(opts.type) + '&q=' + encodeURIComponent(term))
            .then(function (r) { return r.json(); })
            .then(render)
            .catch(function () { render([]); });
    }

    input.addEventListener('input', function () {
        hidden.value = '';
        if (opts.onClear) opts.onClear();
        clearTimeout(timer);
        var term = input.value.trim();
        timer = setTimeout(function () { search(term); }, 250);
    });

    input.addEventListener('focus', function () { search(input.value.trim()); });

    input.addEventListener('keydown', function (e) {
        var opts_ = box.querySelectorAll('.dropdown-option');
        if (e.key === 'ArrowDown') { e.preventDefault(); activeIdx = Math.min(activeIdx + 1, opts_.length - 1); highlight(opts_); }
        else if (e.key === 'ArrowUp') { e.preventDefault(); activeIdx = Math.max(activeIdx - 1, 0); highlight(opts_); }
        else if (e.key === 'Enter') { e.preventDefault(); if (activeIdx >= 0) select(activeIdx); }
        else if (e.key === 'Escape') { box.style.display = 'none'; }
    });

    function highlight(opts_) {
        opts_.forEach(function (o, i) { o.style.background = (i === activeIdx) ? '#f0f7ff' : ''; });
    }

    document.addEventListener('click', function (e) {
        if (!e.target.closest('#' + opts.inputId) && !e.target.closest('#' + opts.optionsId)) {
            box.style.display = 'none';
        }
    });
}

document.addEventListener('DOMContentLoaded', function () {

    /* ── ENTRY TYPE TOGGLE ── */
    var entryRadios = document.querySelectorAll('input[name="entry_type"]');
    var directMode  = document.getElementById('direct_mode');
    var jtMode      = document.getElementById('jt_mode');
    var bpMode      = document.getElementById('bp_mode');
    var jtId        = document.getElementById('jt_id');
    var bpId        = document.getElementById('bp_id');

    function updateModeVisibility() {
        var val = document.querySelector('input[name="entry_type"]:checked').value;
        directMode.classList.toggle('hidden', val !== 'direct');
        jtMode.classList.toggle('hidden', val !== 'from_jt');
        bpMode.classList.toggle('hidden', val !== 'from_bp');
        validateQuantityLimits();
    }
    entryRadios.forEach(function (r) { r.addEventListener('change', updateModeVisibility); });

    /* ── BOOK SEARCH (direct) ── */
    initSearchDropdown({ inputId: 'book_search', hiddenId: 'book_code', optionsId: 'book_options', type: 'book' });

    /* ── JOB TICKET SEARCH ── */
    var jtInfoBox = document.getElementById('jt_info_box');
    initSearchDropdown({
        inputId: 'jt_search', hiddenId: 'jt_id', optionsId: 'jt_options', type: 'job_ticket',
        onSelect: function (item) {
            document.getElementById('jt_book_name').textContent = item.book_name || '-';
            document.getElementById('jt_lot').textContent       = item.lot || '-';
            document.getElementById('jt_print_qty').textContent = item.print_qty ? Number(item.print_qty).toLocaleString() : '-';
            jtInfoBox.classList.remove('hidden');
            validateQuantityLimits();
        },
        onClear: function () { jtInfoBox.classList.add('hidden'); }
    });

    /* ── BOOK PACKING SEARCH ── */
    var bpSummaryBox   = document.getElementById('bp_summary_box');
    var bpSelectedJtId = '';
    initSearchDropdown({
        inputId: 'bp_search', hiddenId: 'bp_id', optionsId: 'bp_options', type: 'book_packing',
        onSelect: function (item) {
            bpSelectedJtId = item.jt_id || '';
            fetch('get_bp_summary.php?bp_id=' + encodeURIComponent(item.value) + '&jt_id=' + encodeURIComponent(bpSelectedJtId))
                .then(function (r) { return r.json(); })
                .then(function (data) {
                    document.getElementById('bp_jt_code').textContent = data.jt_code || item.job_ticket_code || '-';
                    document.getElementById('bp_total_print').textContent = Number(data.total_print_qty || 0).toLocaleString();
                    document.getElementById('bp_total_packed').textContent = Number(data.total_packed || 0).toLocaleString();
                    document.getElementById('bp_total_deno_entries').textContent = data.total_deno_entries || 0;
                    document.getElementById('bp_total_deno_qty').textContent = Number(data.total_deno_qty || 0).toLocaleString();
                    document.getElementById('bp_remaining').textContent = Number(data.remaining || 0).toLocaleString();
                    bpSummaryBox.classList.remove('hidden');
                })
                .catch(function () { /* summary endpoint optional */ });
            validateQuantityLimits();
        },
        onClear: function () { bpSummaryBox.classList.add('hidden'); }
    });

    /* ── BIDIRECTIONAL DATE CONVERSION ── */
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

    nepField.addEventListener('blur', function () {
        if (blockNepConversion) return;
        var bsVal = nepField.value.trim();
        if (!bsVal) return;
        try {
            var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adVal) fillEngFields(adVal);
        } catch (e) { /* invalid input — leave as-is */ }
    });

    /* ── AUTO-CALCULATE TOTAL QTY + LIVE LIMIT VALIDATION ── */
    var perPokaEl = document.getElementById('per_poka_qty');
    var pokaEl    = document.getElementById('poka_qty');
    var openEl    = document.getElementById('quantity_openpcs');
    var totalEl   = document.getElementById('total_qty');

    function calcTotal() {
        var perPoka = parseInt(perPokaEl.value) || 0;
        var pokaQty = parseInt(pokaEl.value)    || 0;
        var openPcs = parseInt(openEl.value)    || 0;
        totalEl.value = (perPoka * pokaQty) + openPcs;
        validateQuantityLimits();
    }
    perPokaEl.addEventListener('input', calcTotal);
    pokaEl.addEventListener('input', calcTotal);
    openEl.addEventListener('input', calcTotal);
    calcTotal();

    function validateQuantityLimits() {
        var warningDiv = document.getElementById('quantity_warning');
        var warningMsg = document.getElementById('warning_message');
        warningDiv.classList.remove('show');

        var mode  = document.querySelector('input[name="entry_type"]:checked').value;
        var total = parseInt(totalEl.value, 10) || 0;

        if (mode === 'from_jt' && jtId.value) {
            fetch('validate_deno_qty.php?jt_id=' + encodeURIComponent(jtId.value) + '&new_qty=' + total + '&exclude_id=<?= $record['id'] ?>')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.valid) {
                        warningMsg.textContent = 'Exceeds JT limit by ' + Number(d.excess).toLocaleString() +
                            '! JT Qty: ' + Number(d.jt_print_qty).toLocaleString() +
                            ', Existing Deno: ' + Number(d.existing_deno).toLocaleString() +
                            ', New: ' + total.toLocaleString() + ', Total: ' + Number(d.total).toLocaleString();
                        warningDiv.classList.add('show');
                    }
                })
                .catch(function () { /* validation endpoint optional */ });
        } else if (mode === 'from_bp' && bpId.value) {
            fetch('validate_deno_qty.php?bp_id=' + encodeURIComponent(bpId.value) + '&jt_id=' + encodeURIComponent(bpSelectedJtId) + '&new_qty=' + total + '&exclude_id=<?= $record['id'] ?>')
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.valid) {
                        if (d.exceeds_bp) {
                            warningMsg.textContent = 'Exceeds BP limit by ' + Number(d.bp_excess).toLocaleString() + '!';
                        } else if (d.exceeds_jt) {
                            warningMsg.textContent = 'Exceeds JT limit by ' + Number(d.jt_excess).toLocaleString() + '!';
                        }
                        warningDiv.classList.add('show');
                    }
                })
                .catch(function () { /* validation endpoint optional */ });
        }
    }

    /* ── UNSAVED-CHANGES WARNING ── */
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

    // Initialize
    updateModeVisibility();
});

/* ── FORM VALIDATION ── */
function validateForm() {
    var mode    = document.querySelector('input[name="entry_type"]:checked').value;
    var refNo   = document.getElementById('ref_no').value.trim();
    var nepDate = document.getElementById('deno_date_nep').value.trim();
    var perPoka = document.getElementById('per_poka_qty').value;
    var pokaQty = document.getElementById('poka_qty').value;

    if (mode === 'direct' && !document.getElementById('book_code').value) {
        alert('Please select a book from the dropdown.');
        document.getElementById('book_search').focus();
        return false;
    }
    if (mode === 'from_jt' && !document.getElementById('jt_id').value) {
        alert('Please select a Job Ticket from the dropdown.');
        document.getElementById('jt_search').focus();
        return false;
    }
    if (mode === 'from_bp' && !document.getElementById('bp_id').value) {
        alert('Please select a Book Packing record from the dropdown.');
        document.getElementById('bp_search').focus();
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

    var btn = document.querySelector('button[type="submit"]');
    btn.innerHTML = '💾 Updating…';
    btn.disabled  = true;
    return true;
}

/* ── DELETE CONFIRMATION ── */
function confirmDelete(id) {
    if (confirm('Are you sure you want to delete this record? This cannot be undone.')) {
        document.getElementById('deleteId').value = id;
        document.getElementById('deleteForm').submit();
    }
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

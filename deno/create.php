<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/lib/AuditLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// redirect_if_not_logged_in();

$auditLogger = new AuditLogger($conn, 'DenoCreate', 'Deno');

$current_user    = $_SESSION['username'] ?? 'system';
$current_user_id = $_SESSION['user_id']  ?? null;

// Sender is a press-side user. Received/Verified are marketing-side and are
// intentionally NEVER set from this form — they belong to the marketing
// workflow and only get filled in later (via edit, by a marketing user).
$marketing_users = $conn->query("SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$press_users     = $conn->query("SELECT id, username FROM users WHERE role = 'press'      ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

// Active fiscal year — id is needed both for saving new records and for
// scoping the "latest records" list further down to just this fiscal year.
$active_fiscal_years = $conn->query("
    SELECT id, fiscal_name, fiscal_code
    FROM   fiscal_years
    WHERE  is_active = true
    ORDER  BY fiscal_name
")->fetchAll(PDO::FETCH_ASSOC);

$active_fy_row  = $active_fiscal_years[0] ?? null;
$active_fy_name = $active_fy_row['fiscal_name'] ?? null;
$active_fy_code = $active_fy_row['fiscal_code'] ?? null;
$active_fy_id   = $active_fy_row['id']          ?? null;

/* ─── Excel export ─── */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="deno_records_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    $records = $conn->query("
        SELECT d.*, b.book_name,
               u1.username AS created_user,
               u2.username AS received_user,
               u3.username AS verified_user,
               u4.username AS sender_user
        FROM   deno d
        LEFT JOIN books b  ON d.book_code   = b.book_code
        LEFT JOIN users u1 ON d.created_by  = u1.id
        LEFT JOIN users u2 ON d.received_by = u2.id
        LEFT JOIN users u3 ON d.verify_by   = u3.id
        LEFT JOIN users u4 ON d.sender_by   = u4.id
        WHERE  d.deleted_at IS NULL
        ORDER  BY d.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1'>";
    echo "<tr>
            <th>ID</th><th>Deno No</th><th>Book Name</th><th>Book Code</th><th>Ref No</th>
            <th>Nepali Date</th><th>English Date</th><th>Fiscal Year</th>
            <th>Per Poka Qty</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th>
            <th>Created By</th><th>Sender By</th><th>Received By</th>
            <th>Verified By</th><th>Notes</th><th>Created At</th>
          </tr>";
    foreach ($records as $r) {
        echo "<tr>
            <td>{$r['id']}</td>
            <td>" . htmlspecialchars($r['deno_no'] ?? '') . "</td>
            <td>" . htmlspecialchars($r['book_name']) . "</td>
            <td>{$r['book_code']}</td>
            <td>{$r['ref_no']}</td>
            <td>{$r['deno_date_nep']}</td>
            <td>{$r['deno_date_eng']}</td>
            <td>{$r['fiscal_year']}</td>
            <td>{$r['per_poka_qty']}</td>
            <td>{$r['poka_qty']}</td>
            <td>{$r['total_qty']}</td>
            <td>{$r['quantity_openpcs']}</td>
            <td>" . htmlspecialchars($r['created_user'] ?? '') . "</td>
            <td>" . htmlspecialchars($r['sender_user']  ?? '-') . "</td>
            <td>" . htmlspecialchars($r['received_user'] ?? '-') . "</td>
            <td>" . htmlspecialchars($r['verified_user'] ?? '-') . "</td>
            <td>" . htmlspecialchars($r['notes'] ?? '') . "</td>
            <td>" . date('Y-m-d H:i', strtotime($r['created_at'])) . "</td>
        </tr>";
    }
    echo "</table>";
    exit;
}

/* ═══════════════════════════════════════════════════════════════════
   CRUD  —  POST / REDIRECT / GET
   ═══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    try {
        $auditLogger->prepareForAudit();

        switch ($action) {

            /* ── CREATE / UPDATE share the same entry-type resolution ── */
            case 'create':
            case 'update':

                $entry_type = $_POST['entry_type'] ?? 'direct';
                if (!in_array($entry_type, ['direct', 'from_jt', 'from_bp'], true)) {
                    $entry_type = 'direct';
                }

                // Required fields depend on which mode was used
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

                // Duplicate ref_no + different date guard
                $dupSql = "
                    SELECT id FROM deno
                    WHERE  ref_no        = :ref_no
                      AND  deno_date_nep != :deno_date_nep
                      AND  deleted_at IS NULL
                ";
                $dupParams = [
                    ':ref_no'        => $_POST['ref_no'],
                    ':deno_date_nep' => $_POST['deno_date_nep'],
                ];
                if ($action === 'update') {
                    $dupSql .= " AND id != :id";
                    $dupParams[':id'] = $_POST['id'];
                }
                $dupSql .= " LIMIT 1";

                $check = $conn->prepare($dupSql);
                $check->execute($dupParams);
                if ($check->fetch()) {
                    $_SESSION['flash'] = [
                        'type' => 'danger',
                        'msg'  => 'Error: Ref No ' . htmlspecialchars($_POST['ref_no'])
                                . ' already exists with a different date.',
                    ];
                    header('Location: ' . $_SERVER['PHP_SELF'] . ($action === 'update' ? ('?edit_id=' . (int)$_POST['id']) : ''));
                    exit;
                }

                if ($action === 'create') {

                    // deno_no is a NEW, fiscal-year-scoped auto number: "{serial}/deno/{fiscalShort}"
                    // ref_no stays fully manual/untouched (see plan_numberseries.md).
                    $active_fy = getActiveFiscalYear($conn);
                    if (!$active_fy) {
                        $_SESSION['flash'] = [
                            'type' => 'danger',
                            'msg'  => 'No active fiscal year set. Please configure an active fiscal year first.',
                        ];
                        header('Location: ' . $_SERVER['PHP_SELF']);
                        exit;
                    }
                    [$deno_serial_no, $deno_no] = generateFiscalScopedNumber(
                        $conn, 'deno', 'deno_serial_no', $active_fy['id'], 'deno', $active_fy
                    );

                    $conn->prepare("
                        INSERT INTO deno
                            (book_code, ref_no, deno_date_nep, deno_date_eng,
                             per_poka_qty, poka_qty, quantity_openpcs, notes,
                             created_by, sender_by, received_by, verify_by, update_remarks,
                             fiscal_year_id, deno_serial_no, deno_no,
                             entry_type, jt_id, bp_id)
                        VALUES
                            (:book_code, :ref_no, :deno_date_nep, :deno_date_eng,
                             :per_poka_qty, :poka_qty, :quantity_openpcs, :notes,
                             :created_by, :sender_by, :received_by, :verify_by, :update_remarks,
                             :fiscal_year_id, :deno_serial_no, :deno_no,
                             :entry_type, :jt_id, :bp_id)
                    ")->execute([
                        ':book_code'        => $book_code,
                        ':ref_no'           => $_POST['ref_no'],
                        ':deno_date_nep'    => $_POST['deno_date_nep'],
                        ':deno_date_eng'    => $_POST['deno_date_eng'],
                        ':per_poka_qty'     => $_POST['per_poka_qty'],
                        ':poka_qty'         => $_POST['poka_qty'],
                        ':quantity_openpcs' => $_POST['quantity_openpcs'] ?? 0,
                        ':notes'            => $_POST['notes'] ?? null,
                        ':created_by'       => $current_user_id,
                        ':sender_by'        => $_POST['sender_by'] ?? null ?: null,
                        // Received/Verified are marketing-only fields — always NULL at create time.
                        ':received_by'      => null,
                        ':verify_by'        => null,
                        // Update Remarks is disabled on create — always empty here.
                        ':update_remarks'   => null,
                        ':fiscal_year_id'   => $active_fy['id'],
                        ':deno_serial_no'   => $deno_serial_no,
                        ':deno_no'          => $deno_no,
                        ':entry_type'       => $entry_type,
                        ':jt_id'            => $jt_id,
                        ':bp_id'            => $bp_id,
                    ]);

                    $_SESSION['flash'] = ['type' => 'success', 'msg' => "Deno record added successfully! Deno No: $deno_no"];
                    header('Location: index.php');
                    exit;

                } else {
                    // Trigger re-computes deno_year / fiscal_year automatically on UPDATE too.
                    // Received/Verified/Update Remarks ARE editable here (this is the marketing step).
                    $conn->prepare("
                        UPDATE deno SET
                            book_code        = :book_code,
                            ref_no           = :ref_no,
                            deno_date_nep    = :deno_date_nep,
                            deno_date_eng    = :deno_date_eng,
                            per_poka_qty     = :per_poka_qty,
                            poka_qty         = :poka_qty,
                            quantity_openpcs = :quantity_openpcs,
                            notes            = :notes,
                            sender_by        = :sender_by,
                            received_by      = :received_by,
                            verify_by        = :verify_by,
                            update_remarks   = :update_remarks,
                            updated_by       = :updated_by,
                            updated_at       = CURRENT_TIMESTAMP,
                            entry_type       = :entry_type,
                            jt_id            = :jt_id,
                            bp_id            = :bp_id
                        WHERE id = :id
                    ")->execute([
                        ':id'               => $_POST['id'],
                        ':book_code'        => $book_code,
                        ':ref_no'           => $_POST['ref_no'],
                        ':deno_date_nep'    => $_POST['deno_date_nep'],
                        ':deno_date_eng'    => $_POST['deno_date_eng'],
                        ':per_poka_qty'     => $_POST['per_poka_qty'],
                        ':poka_qty'         => $_POST['poka_qty'],
                        ':quantity_openpcs' => $_POST['quantity_openpcs'] ?? 0,
                        ':notes'            => $_POST['notes'] ?? null,
                        ':sender_by'        => $_POST['sender_by']   ?? null,
                        ':received_by'      => $_POST['received_by'] ?? null,
                        ':verify_by'        => $_POST['verify_by']   ?? null,
                        ':update_remarks'   => $_POST['update_remarks'] ?? null,
                        ':updated_by'       => $current_user_id,
                        ':entry_type'       => $entry_type,
                        ':jt_id'            => $jt_id,
                        ':bp_id'            => $bp_id,
                    ]);

                    $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Deno record updated successfully!'];
                    header('Location: index.php');
                    exit;
                }

            /* ── DELETE (soft) ── */
            case 'delete':
                $conn->prepare("
                    UPDATE deno SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id
                ")->execute([':id' => $_POST['id']]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Deno record deleted successfully!'];
                header('Location: ' . $_SERVER['PHP_SELF']);
                exit;
        }

    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error: ' . htmlspecialchars($e->getMessage())];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

/* ─── Page data ─── */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$edit_record     = null;
$edit_book_label = '';
$edit_jt_label   = '';
$edit_bp_label   = '';

if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM deno WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);

    // Pre-fill the search-box labels for whichever mode this record used
    if ($edit_record) {
        if (!empty($edit_record['book_code'])) {
            $stmt = $conn->prepare("SELECT book_name FROM books WHERE book_code = :c");
            $stmt->execute([':c' => $edit_record['book_code']]);
            if ($b = $stmt->fetch()) {
                $edit_book_label = $b['book_name'] . ' (' . $edit_record['book_code'] . ')';
            }
        }
        if (!empty($edit_record['jt_id'])) {
            $stmt = $conn->prepare("
                SELECT jt.job_ticket_code, b.book_name
                FROM   job_ticket jt LEFT JOIN books b ON jt.book_id = b.book_id
                WHERE  jt.id = :id
            ");
            $stmt->execute([':id' => $edit_record['jt_id']]);
            if ($j = $stmt->fetch()) {
                $edit_jt_label = $j['job_ticket_code'] . ' — ' . ($j['book_name'] ?? '');
            }
        }
        if (!empty($edit_record['bp_id'])) {
            $stmt = $conn->prepare("
                SELECT bp.name, b.book_name
                FROM   book_packing bp LEFT JOIN books b ON bp.book_code = b.book_code
                WHERE  bp.id = :id
            ");
            $stmt->execute([':id' => $edit_record['bp_id']]);
            if ($p = $stmt->fetch()) {
                $edit_bp_label = $p['name'] . ' — ' . ($p['book_name'] ?? '');
            }
        }
    }
}

$editEngForNativePicker = '';
if ($edit_record && !empty($edit_record['deno_date_eng'])) {
    $editEngForNativePicker = str_replace('.', '-', $edit_record['deno_date_eng']);
}

// Latest records for THIS page only — scoped to the active fiscal year, top 10
$deno_records = [];
if ($active_fy_id) {
    $stmt = $conn->prepare("
        SELECT d.*, b.book_name,
               u1.username AS created_user,
               u2.username AS received_user,
               u3.username AS verified_user,
               u4.username AS sender_user,
               jt.job_ticket_code,
               bp.name AS bp_name
        FROM   deno d
        LEFT JOIN books b         ON d.book_code = b.book_code
        LEFT JOIN users u1        ON d.created_by  = u1.id
        LEFT JOIN users u2        ON d.received_by = u2.id
        LEFT JOIN users u3        ON d.verify_by   = u3.id
        LEFT JOIN users u4        ON d.sender_by   = u4.id
        LEFT JOIN job_ticket jt   ON d.jt_id = jt.id
        LEFT JOIN book_packing bp ON d.bp_id = bp.id
        WHERE  d.deleted_at IS NULL
          AND  d.fiscal_year_id = :fyid
        ORDER  BY d.created_at DESC
        LIMIT  10
    ");
    $stmt->execute([':fyid' => $active_fy_id]);
    $deno_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>

<style>
body { font-size:16px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }

.form-container { background:#f8f9fa; padding:20px; border-radius:8px; margin-bottom:30px; }
.form-row       { display:flex; gap:15px; margin-bottom:15px; align-items:end; flex-wrap:wrap; }
.form-group     { flex:1; min-width:200px; }
.form-group label { display:block; margin-bottom:5px; font-weight:600; color:#333; font-size:15px; }

.form-control {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:4px; font-size:15px; box-sizing:border-box;
}
.form-control:focus  { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.25); }
.form-control:disabled { background:#e9ecef; cursor:not-allowed; color:#6c757d; }

.btn            { padding:12px 24px; border:none; border-radius:4px; cursor:pointer; font-size:15px; font-weight:600; text-decoration:none; display:inline-block; text-align:center; margin-right:8px; }
.btn-primary    { background:#007bff; color:#fff; }
.btn-secondary  { background:#6c757d; color:#fff; }
.btn-success    { background:#28a745; color:#fff; }
.btn-warning    { background:#ffc107; color:#212529; }
.btn-danger     { background:#dc3545; color:#fff; }
.btn-info       { background:#17a2b8; color:#fff; }
.btn-sm         { padding:6px 12px; font-size:13px; margin-right:4px; }

.action-buttons { margin-bottom:20px; display:flex; gap:10px; align-items:center; flex-wrap:wrap; }

.table-container { background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,.1); overflow-x:auto; }
.table           { width:100%; border-collapse:collapse; font-size:14px; }
.table th, .table td { padding:10px 8px; text-align:left; border-bottom:1px solid #dee2e6; vertical-align:middle; white-space:nowrap; }
.table th        { background:#f8f9fa; font-weight:700; color:#495057; font-size:13px; text-transform:uppercase; letter-spacing:.5px; }
.table tbody tr:hover           { background:#f5f5f5; }
.table tbody tr:nth-child(even) { background:#fafafa; }

.badge-fy { display:inline-block; padding:2px 8px; border-radius:12px; font-size:12px; font-weight:600; background:#e8f4fd; color:#0066cc; }
.badge    { display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; }
.badge-direct  { background:#cce5ff; color:#004085; }
.badge-from_jt { background:#d4edda; color:#155724; }
.badge-from_bp { background:#fff3cd; color:#856404; }

.alert         { padding:15px 20px; margin-bottom:20px; border:1px solid transparent; border-radius:4px; font-size:15px; font-weight:500; }
.alert-success { color:#155724; background:#d4edda; border-color:#c3e6cb; }
.alert-danger  { color:#721c24; background:#f8d7da; border-color:#f5c6cb; }

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

/* ── Searchable dropdown (used for book / job ticket / book packing) ── */
.search-dropdown  { position:relative; }
.dropdown-search   { width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:4px; font-size:15px; box-sizing:border-box; }
.dropdown-options {
    position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd;
    border-top:none; max-height:240px; overflow-y:auto; z-index:1000; display:none;
    border-radius:0 0 4px 4px; box-shadow:0 4px 10px rgba(0,0,0,.08);
}
.dropdown-option  { padding:10px 14px; cursor:pointer; border-bottom:1px solid #eee; font-size:14px; }
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
    padding:12px 14px; border-radius:8px; margin:0 0 15px 0;
    font-size:14px; font-weight:600; display:none;
}
.validation-warning.show { display:block; animation:shake .4s; }
@keyframes shake {
    0%,100% { transform:translateX(0); }
    20%,60% { transform:translateX(-4px); }
    40%,80% { transform:translateX(4px); }
}

/* ── Nepali datepicker override ── */
.ndp-input {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:4px; font-size:15px; box-sizing:border-box;
    background:#fff; font-family:inherit;
}
.ndp-input:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.25); }

/* ── English date overlay ── */
.date-eng-wrapper { position:relative; }
.date-eng-wrapper .eng-display {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:4px; font-size:15px; box-sizing:border-box;
    background:#fff; color:#333; font-family:inherit;
    min-height:40px; line-height:20px; cursor:pointer; position:relative; z-index:1;
}
.date-eng-wrapper .eng-display:empty::before { content:'Click to pick date…'; color:#999; }
.date-eng-wrapper input[type="date"] {
    position:absolute; top:0; left:0; width:100%; height:100%;
    opacity:0; cursor:pointer; z-index:2; margin:0; padding:0; border:none;
}

/* ── Fiscal year info banner ── */
.fy-info { font-size:13px; color:#555; margin-top:4px; }
.fy-info span { font-weight:600; color:#0066cc; }

@media print {
    body { font-size:11px; line-height:1.2; }
    .form-container, .action-buttons, .btn { display:none !important; }
    h2, h3 { font-size:14px; margin:10px 0; }
    .table { font-size:9px; width:100%; }
    .table th, .table td { padding:3px 2px; border:1px solid #000; font-size:8px; }
    .table th { background:#f0f0f0 !important; font-weight:bold; }
    .table-container { box-shadow:none; border:1px solid #000; overflow:visible; }
    .alert { display:none !important; }
    @page { margin:.5in; size:A4 landscape; }
}
</style>

<div class="container">

<?php if ($flash): ?>
    <div class="alert alert-<?= htmlspecialchars($flash['type']) ?>">
        <?= htmlspecialchars($flash['msg']) ?>
    </div>
<?php endif; ?>

<h2><?= $edit_record ? 'Edit Deno Entry' : 'Add Deno Entry' ?></h2>

<?php if ($active_fy_name): ?>
<div class="fy-info" style="margin-bottom:12px;">
    Active fiscal year: <span><?= htmlspecialchars($active_fy_name) ?></span>
    (code: <span><?= htmlspecialchars($active_fy_code) ?></span>)
    — <em>deno_year &amp; fiscal_year are set automatically from the Nepali date; Deno No is auto-generated.</em>
</div>
<?php else: ?>
<div class="alert alert-danger">No active fiscal year is configured. Please set one before creating entries.</div>
<?php endif; ?>

<div class="form-container">
<form method="post" id="denoForm">
    <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
    <?php if ($edit_record): ?>
        <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
    <?php endif; ?>

    <!-- ── Entry type selector ── -->
    <div class="entry-type-selector">
        <div class="entry-type-option">
            <input type="radio" name="entry_type" id="type_direct" value="direct"
                   <?= (!$edit_record || $edit_record['entry_type'] === 'direct') ? 'checked' : '' ?>>
            <label for="type_direct">
                <div class="entry-type-icon">📚</div>
                <div class="entry-type-title">Direct Entry</div>
                <div class="entry-type-desc">Manual book entry</div>
            </label>
        </div>
        <div class="entry-type-option">
            <input type="radio" name="entry_type" id="type_from_jt" value="from_jt"
                   <?= ($edit_record && $edit_record['entry_type'] === 'from_jt') ? 'checked' : '' ?>>
            <label for="type_from_jt">
                <div class="entry-type-icon">🎫</div>
                <div class="entry-type-title">From Job Ticket</div>
                <div class="entry-type-desc">Link to existing JT</div>
            </label>
        </div>
        <div class="entry-type-option">
            <input type="radio" name="entry_type" id="type_from_bp" value="from_bp"
                   <?= ($edit_record && $edit_record['entry_type'] === 'from_bp') ? 'checked' : '' ?>>
            <label for="type_from_bp">
                <div class="entry-type-icon">📦</div>
                <div class="entry-type-title">From Book Packing</div>
                <div class="entry-type-desc">Link to packing record</div>
            </label>
        </div>
    </div>

    <!-- ── Mode: Direct ── -->
    <div id="direct_mode" class="mode-panel">
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label>Book:</label>
                <div class="search-dropdown">
                    <input type="text" class="form-control dropdown-search" id="book_search"
                           placeholder="Type to search book by name or code…" autocomplete="off"
                           value="<?= htmlspecialchars($edit_book_label) ?>">
                    <input type="hidden" name="book_code" id="book_code"
                           value="<?= htmlspecialchars($edit_record['book_code'] ?? '') ?>">
                    <div class="dropdown-options" id="book_options"></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Mode: From Job Ticket ── -->
    <div id="jt_mode" class="mode-panel hidden">
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label>Job Ticket:</label>
                <div class="search-dropdown">
                    <input type="text" class="form-control dropdown-search" id="jt_search"
                           placeholder="Type to search job ticket code, book, or lot…" autocomplete="off"
                           value="<?= htmlspecialchars($edit_jt_label) ?>">
                    <input type="hidden" name="jt_id" id="jt_id"
                           value="<?= htmlspecialchars($edit_record['jt_id'] ?? '') ?>">
                    <div class="dropdown-options" id="jt_options"></div>
                </div>
                <div class="info-box <?= $edit_jt_label ? '' : 'hidden' ?>" id="jt_info_box">
                    <div class="info-row"><span class="info-label">Book:</span><span class="info-value" id="jt_book_name">-</span></div>
                    <div class="info-row"><span class="info-label">Lot:</span><span class="info-value" id="jt_lot">-</span></div>
                    <div class="info-row"><span class="info-label">Print Qty:</span><span class="info-value" id="jt_print_qty">-</span></div>
                </div>
            </div>
        </div>
    </div>

    <!-- ── Mode: From Book Packing ── -->
    <div id="bp_mode" class="mode-panel hidden">
        <div class="form-row">
            <div class="form-group" style="flex:2;">
                <label>Book Packing:</label>
                <div class="search-dropdown">
                    <input type="text" class="form-control dropdown-search" id="bp_search"
                           placeholder="Type to search packing name, book, or JT code…" autocomplete="off"
                           value="<?= htmlspecialchars($edit_bp_label) ?>">
                    <input type="hidden" name="bp_id" id="bp_id"
                           value="<?= htmlspecialchars($edit_record['bp_id'] ?? '') ?>">
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

    <!-- ── Ref No + Fiscal Year (read-only, from active FY) ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="ref_no">Reference No:</label>
            <input type="text" name="ref_no" id="ref_no" class="form-control"
                   value="<?= htmlspecialchars($edit_record['ref_no'] ?? '') ?>" required>
        </div>
        <div class="form-group">
            <label>Fiscal Year:</label>
            <input type="text" class="form-control"
                   value="<?= htmlspecialchars(($edit_record['fiscal_year'] ?? $active_fy_name) ?: '—') ?>" disabled>
        </div>
    </div>

    <!-- ── Dates ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="deno_date_nep">Nepali Date <small>(YYYY.MM.DD)</small>:</label>
            <input type="text" id="deno_date_nep" name="deno_date_nep"
                   class="form-control ndp-input"
                   placeholder="e.g. 2082.01.15"
                   value="<?= htmlspecialchars($edit_record['deno_date_nep'] ?? '') ?>"
                   autocomplete="off" required>
            <div class="fy-info" id="fy_display" style="margin-top:5px;"></div>
        </div>
        <div class="form-group">
            <label>English Date <small>(YYYY.MM.DD)</small>:</label>
            <input type="hidden" name="deno_date_eng" id="deno_date_eng_hidden"
                   value="<?= htmlspecialchars($edit_record['deno_date_eng'] ?? '') ?>">
            <div class="date-eng-wrapper">
                <div class="eng-display" id="eng_display">
                    <?= htmlspecialchars($edit_record['deno_date_eng'] ?? '') ?>
                </div>
                <input type="date" id="deno_date_eng_native"
                       value="<?= $editEngForNativePicker ?>"
                       min="1944-01-01" max="2044-12-31">
            </div>
        </div>
    </div>

    <!-- ── Quantities ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="per_poka_qty">Qty per Poka:</label>
            <input type="number" name="per_poka_qty" id="per_poka_qty" class="form-control"
                   value="<?= htmlspecialchars((string)($edit_record['per_poka_qty'] ?? '')) ?>" required min="0">
        </div>
        <div class="form-group">
            <label for="poka_qty">No. of Pokas:</label>
            <input type="number" name="poka_qty" id="poka_qty" class="form-control"
                   value="<?= htmlspecialchars((string)($edit_record['poka_qty'] ?? '')) ?>" required min="0">
        </div>
        <div class="form-group">
            <label>Total Qty <small>(auto)</small>:</label>
            <input type="text" id="total_qty_display" class="form-control" disabled
                   value="<?= ($edit_record && $edit_record['per_poka_qty'] && $edit_record['poka_qty'])
                              ? number_format($edit_record['per_poka_qty'] * $edit_record['poka_qty'])
                              : '' ?>">
        </div>
    </div>

    <!-- ── Open Pcs + Created By ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="quantity_openpcs">Open Pieces:</label>
            <input type="number" name="quantity_openpcs" id="quantity_openpcs" class="form-control"
                   value="<?= htmlspecialchars((string)($edit_record['quantity_openpcs'] ?? '0')) ?>" min="0">
        </div>
        <div class="form-group">
            <label>Created By:</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($current_user) ?>" disabled>
        </div>
    </div>

    <!-- ── Sender + Received ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="sender_by">Sender By:</label>
            <select name="sender_by" id="sender_by" class="form-control">
                <option value="">Select Sender</option>
                <?php foreach ($press_users as $user): ?>
                <option value="<?= $user['id'] ?>"
                    <?= (($edit_record && $edit_record['sender_by'] == $user['id'])
                         || (!$edit_record && $user['id'] == $current_user_id)) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($user['username']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="received_by">
                Received By <small style="font-weight:400;color:#888;">(set later by marketing)</small>
            </label>
            <select name="received_by" id="received_by" class="form-control"
                    <?= $edit_record ? '' : 'disabled' ?>>
                <option value="">Select Receiver</option>
                <?php foreach ($marketing_users as $user): ?>
                <option value="<?= $user['id'] ?>"
                    <?= ($edit_record && $edit_record['received_by'] == $user['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($user['username']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- ── Verified By + Notes ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="verify_by">
                Verified By <small style="font-weight:400;color:#888;">(set later by marketing)</small>
            </label>
            <select name="verify_by" id="verify_by" class="form-control"
                    <?= $edit_record ? '' : 'disabled' ?>>
                <option value="">Select Verifier</option>
                <?php foreach ($marketing_users as $user): ?>
                <option value="<?= $user['id'] ?>"
                    <?= ($edit_record && $edit_record['verify_by'] == $user['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($user['username']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="notes">Notes:</label>
            <textarea name="notes" id="notes" class="form-control" rows="1"><?= htmlspecialchars($edit_record['notes'] ?? '') ?></textarea>
        </div>
    </div>

    <!-- ── Update Remarks (edit only) + Submit ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="update_remarks">
                Update Remarks
                <small style="font-weight:400;color:#888;"><?= $edit_record ? '' : '(available when editing)' ?></small>
            </label>
            <textarea name="update_remarks" id="update_remarks" class="form-control" rows="1"
                      <?= $edit_record ? '' : 'disabled' ?>><?= htmlspecialchars($edit_record['update_remarks'] ?? '') ?></textarea>
        </div>
        <div class="form-group" style="display:flex;align-items:flex-end;gap:8px;">
            <button type="submit" class="btn btn-primary">
                <?= $edit_record ? '💾 Update Deno' : '➕ Save Deno' ?>
            </button>
            <?php if ($edit_record): ?>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">✕ Cancel</a>
            <?php endif; ?>
        </div>
    </div>
</form>
</div>

<!-- ── Records table ── -->
<h3>Latest 10 Deno Records <?= $active_fy_name ? '— FY ' . htmlspecialchars($active_fy_name) : '' ?></h3>
<div class="action-buttons">
    <button onclick="window.print()" class="btn btn-info">🖨️ Print</button>
    <a href="?export=excel" class="btn btn-success">📊 Export Excel</a>
    <button onclick="downloadCSV()" class="btn btn-secondary">📥 Download CSV</button>
</div>

<div class="table-container">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th>
                <th>Deno No</th>
                <th>Type</th>
                <th>Book</th>
                <th>JT / BP</th>
                <th>Ref No</th>
                <th>Nepali Date</th>
                <th>English Date</th>
                <th>Fiscal Year</th>
                <th>Per Poka</th>
                <th>Pokas</th>
                <th>Total</th>
                <th>Open Pcs</th>
                <th>Created By</th>
                <th>Sender</th>
                <th>Received</th>
                <th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($deno_records as $rec): ?>
            <tr>
                <td><?= $rec['id'] ?></td>
                <td><span style="font-weight:600;color:#007bff;"><?= htmlspecialchars($rec['deno_no'] ?? '-') ?></span></td>
                <td><span class="badge badge-<?= htmlspecialchars($rec['entry_type'] ?? 'direct') ?>">
                        <?= ucfirst(str_replace('_', ' ', $rec['entry_type'] ?? 'direct')) ?>
                    </span></td>
                <td><?= htmlspecialchars($rec['book_name'] ?? '') ?></td>
                <td>
                    <?php if (!empty($rec['job_ticket_code'])): ?>
                        <div>JT: <?= htmlspecialchars($rec['job_ticket_code']) ?></div>
                    <?php endif; ?>
                    <?php if (!empty($rec['bp_name'])): ?>
                        <div>BP: <?= htmlspecialchars($rec['bp_name']) ?></div>
                    <?php endif; ?>
                    <?php if (empty($rec['job_ticket_code']) && empty($rec['bp_name'])): ?>
                        <span style="color:#999;">-</span>
                    <?php endif; ?>
                </td>
                <td><?= htmlspecialchars($rec['ref_no']) ?></td>
                <td><?= htmlspecialchars($rec['deno_date_nep']) ?></td>
                <td><?= htmlspecialchars($rec['deno_date_eng'] ?? '') ?></td>
                <td>
                    <span class="badge-fy" title="Code: <?= htmlspecialchars($rec['deno_year'] ?? '') ?>">
                        <?= htmlspecialchars($rec['fiscal_year'] ?? $rec['deno_year'] ?? '-') ?>
                    </span>
                </td>
                <td><?= number_format((int)$rec['per_poka_qty']) ?></td>
                <td><?= number_format((int)$rec['poka_qty']) ?></td>
                <td><strong><?= number_format((int)$rec['total_qty']) ?></strong></td>
                <td><?= number_format((int)$rec['quantity_openpcs']) ?></td>
                <td><?= htmlspecialchars($rec['created_user'] ?? '') ?></td>
                <td><?= htmlspecialchars($rec['sender_user']  ?? '-') ?></td>
                <td><?= htmlspecialchars($rec['received_user'] ?? '-') ?></td>
                <td style="white-space:nowrap;">
                    <a href="?edit_id=<?= $rec['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <form method="post" style="display:inline"
                          onsubmit="return confirm('Delete this record?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $rec['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        <?php if (empty($deno_records)): ?>
            <tr><td colspan="17" style="text-align:center;color:#888;padding:24px;">No records found for the active fiscal year.</td></tr>
        <?php endif; ?>
        </tbody>
    </table>
</div>
</div><!-- /container -->

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>

<script>
// fiscal years passed from PHP for client-side preview only
// The authoritative lookup / assignment is always done server-side.
var FISCAL_YEARS = <?php
    $fy_js = [];
    foreach ($active_fiscal_years as $fy) {
        $fy_js[] = ['fiscal_name' => $fy['fiscal_name'], 'fiscal_code' => $fy['fiscal_code']];
    }
    echo json_encode($fy_js);
?>;

/**
 * Given a Nepali date string "YYYY.MM.DD", compute the fiscal name
 * e.g. "2080.05.15" → "2080-81"
 */
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

/**
 * Generic AJAX-backed searchable dropdown used for Book / Job Ticket / Book Packing.
 * Debounced + keyboard-navigable. Only ever pulls a small page of matches
 * (search_lookup.php caps results at 20) instead of dumping whole tables to the page —
 * this is what keeps it fast as job_ticket / book_packing grow over time.
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
        hidden.value = ''; // force an explicit re-selection once the visible text changes
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
    var bookCode    = document.getElementById('book_code');
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
    var bpSummaryBox  = document.getElementById('bp_summary_box');
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

    /* ── DATE FIELDS ── */
    var nepField   = document.getElementById('deno_date_nep');
    var engHidden  = document.getElementById('deno_date_eng_hidden');
    var engDisplay = document.getElementById('eng_display');
    var engNative  = document.getElementById('deno_date_eng_native');
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
        if (found) {
            fyDisplay.innerHTML = 'Fiscal year: <span>' + found.fiscal_name
                + '</span> (code: <span>' + found.fiscal_code + '</span>)';
        } else {
            fyDisplay.innerHTML = '<span style="color:#c00;">⚠ Fiscal year <b>'
                + fyName + '</b> not found in active fiscal years</span>';
        }
    }

    if (nepField.value) updateFyDisplay(nepField.value);

    // Nepali calendar widget
    nepField.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function () {
            if (blockNep) return;
            var bsVal = nepField.value.trim();
            if (!bsVal) return;
            updateFyDisplay(bsVal);
            try {
                var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (adVal) fillEngFields(adVal);
            } catch (e) { console.warn('BS→AD failed:', e); }
        }
    });

    nepField.addEventListener('blur', function () {
        if (blockNep) return;
        var bsVal = nepField.value.trim();
        if (!bsVal) return;
        updateFyDisplay(bsVal);
        try {
            var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adVal) fillEngFields(adVal);
        } catch (e) { /* invalid, ignore */ }
    });

    // English calendar widget (native <input type=date>) kept in sync both ways
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
            }
        } catch (e) { console.warn('AD→BS failed:', e); blockNep = false; }
    });

    /* ── TOTAL QTY + LIVE LIMIT VALIDATION ── */
    var perPokaEl = document.getElementById('per_poka_qty');
    var pokaEl    = document.getElementById('poka_qty');
    var totalEl   = document.getElementById('total_qty_display');

    function calcTotal() {
        var p = parseInt(perPokaEl.value, 10) || 0;
        var q = parseInt(pokaEl.value,    10) || 0;
        totalEl.value = (p * q).toLocaleString();
        validateQuantityLimits();
    }
    perPokaEl.addEventListener('input', calcTotal);
    pokaEl.addEventListener('input', calcTotal);

    function validateQuantityLimits() {
        var warningDiv = document.getElementById('quantity_warning');
        var warningMsg = document.getElementById('warning_message');
        warningDiv.classList.remove('show');

        var mode  = document.querySelector('input[name="entry_type"]:checked').value;
        var total = parseInt((totalEl.value || '0').replace(/,/g, ''), 10) || 0;

        if (mode === 'from_jt' && jtId.value) {
            fetch('validate_deno_qty.php?jt_id=' + encodeURIComponent(jtId.value) + '&new_qty=' + total)
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
            fetch('validate_deno_qty.php?bp_id=' + encodeURIComponent(bpId.value) + '&jt_id=' + encodeURIComponent(bpSelectedJtId) + '&new_qty=' + total)
                .then(function (r) { return r.json(); })
                .then(function (d) {
                    if (!d.valid) {
                        if (d.exceeds_bp) {
                            warningMsg.textContent = 'Exceeds BP limit by ' + Number(d.bp_excess).toLocaleString() +
                                '! BP Qty: ' + Number(d.bp_qty).toLocaleString() +
                                ', Existing Deno: ' + Number(d.existing_bp_deno).toLocaleString() +
                                ', New: ' + total.toLocaleString() + ', Total: ' + Number(d.total_bp).toLocaleString();
                        } else if (d.exceeds_jt) {
                            warningMsg.textContent = 'Exceeds JT limit by ' + Number(d.jt_excess).toLocaleString() +
                                '! JT Qty: ' + Number(d.jt_print_qty).toLocaleString() +
                                ', Existing Deno: ' + Number(d.existing_jt_deno).toLocaleString() +
                                ', New: ' + total.toLocaleString() + ', Total: ' + Number(d.total_jt).toLocaleString();
                        }
                        warningDiv.classList.add('show');
                    }
                })
                .catch(function () { /* validation endpoint optional */ });
        }
    }

    // Initialize
    updateModeVisibility();
    calcTotal();
});

/* ── CSV DOWNLOAD ── */
function downloadCSV() {
    var rows = Array.from(document.querySelector('.table').querySelectorAll('tr'));
    var csv  = 'data:text/csv;charset=utf-8,';
    rows.forEach(function (row) {
        var cols = Array.from(row.querySelectorAll('th,td'));
        csv += cols.map(function (c) {
            var d = c.textContent.trim();
            if (d.includes('Edit') && d.includes('Delete')) d = '';
            if (d.includes(',') || d.includes('"')) d = '"' + d.replace(/"/g, '""') + '"';
            return d;
        }).join(',') + '\r\n';
    });
    var a = document.createElement('a');
    a.setAttribute('href', encodeURI(csv));
    a.setAttribute('download', 'deno_records_' + new Date().toISOString().split('T')[0] + '.csv');
    document.body.appendChild(a);
    a.click();
    document.body.removeChild(a);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

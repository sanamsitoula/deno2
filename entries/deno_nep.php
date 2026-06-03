<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/lib/AuditLogger.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// redirect_if_not_logged_in();

$auditLogger = new AuditLogger($conn, 'DenoCreate', 'Deno');

// Get current user info
$current_user = $_SESSION['username'] ?? 'system';
$current_user_id = $_SESSION['user_id'] ?? null;

// Fetch users by role
$marketing_users = $conn->query("SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$press_users = $conn->query("SELECT id, username FROM users WHERE role = 'press' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

/* ─── Excel export ─── */
if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="deno_records_' . date('Y-m-d') . '.xls"');
    header('Cache-Control: max-age=0');

    $records = $conn->query("
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
        ORDER BY d.created_at DESC
    ")->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1'>";
    echo "<tr><th>ID</th><th>Book Name</th><th>Book Code</th><th>Ref No</th><th>Nepali Date</th><th>English Date</th><th>Per Poka Qty</th><th>Poka Qty</th><th>Total Qty</th><th>Open Pcs</th><th>Created By</th><th>Sender By</th><th>Received By</th><th>Verified By</th><th>Notes</th><th>Created At</th></tr>";
    foreach ($records as $r) {
        echo "<tr>
            <td>{$r['id']}</td>
            <td>{$r['book_name']}</td>
            <td>{$r['book_code']}</td>
            <td>{$r['ref_no']}</td>
            <td>{$r['deno_date_nep']}</td>
            <td>{$r['deno_date_eng']}</td>
            <td>{$r['per_poka_qty']}</td>
            <td>{$r['poka_qty']}</td>
            <td>{$r['total_qty']}</td>
            <td>{$r['quantity_openpcs']}</td>
            <td>{$r['created_user']}</td>
            <td>{$r['sender_user']}</td>
            <td>{$r['received_user']}</td>
            <td>{$r['verified_user']}</td>
            <td>{$r['notes']}</td>
            <td>" . date('Y-m-d H:i', strtotime($r['created_at'])) . "</td>
        </tr>";
    }
    echo "</table>";
    exit;
}

/* ─── CRUD ─── */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    try {
        $auditLogger->prepareForAudit();
        switch ($action) {
            case 'create':
                $check = $conn->prepare("SELECT deno_date_nep FROM deno WHERE ref_no = :ref_no AND deno_date_nep != :deno_date_nep AND deleted_at IS NULL LIMIT 1");
                $check->execute([':ref_no' => $_POST['ref_no'], ':deno_date_nep' => $_POST['deno_date_nep']]);
                if ($check->fetch()) {
                    echo "<div class='alert alert-danger'>Error: Reference number " . htmlspecialchars($_POST['ref_no']) . " already exists with a different date.</div>";
                    break;
                }
                $conn->prepare("
                    INSERT INTO deno (book_code, ref_no, deno_date_nep, deno_date_eng,
                        per_poka_qty, poka_qty, quantity_openpcs, notes,
                        created_by, sender_by, received_by, verify_by, update_remarks)
                    VALUES (:book_code,:ref_no,:deno_date_nep,:deno_date_eng,
                        :per_poka_qty,:poka_qty,:quantity_openpcs,:notes,
                        :created_by,:sender_by,:received_by,:verify_by,:update_remarks)
                ")->execute([
                    ':book_code'=>$_POST['book_code'], 
                    ':ref_no'=>$_POST['ref_no'],
                    ':deno_date_nep'=>$_POST['deno_date_nep'], 
                    ':deno_date_eng'=>$_POST['deno_date_eng'],
                    ':per_poka_qty'=>$_POST['per_poka_qty'], 
                    ':poka_qty'=>$_POST['poka_qty'],
                    ':quantity_openpcs'=>$_POST['quantity_openpcs']??0, 
                    ':notes'=>$_POST['notes'],
                    ':created_by'=>$current_user_id,
                    ':sender_by'=>$_POST['sender_by']?:null,
                    ':received_by'=>$_POST['received_by']?:null,
                    ':verify_by'=>$_POST['verify_by']?:null,
                    ':update_remarks'=>$_POST['update_remarks']
                ]);
                echo "<div class='alert alert-success'>Deno record added successfully!</div>";
                break;

            case 'update':
                $check = $conn->prepare("SELECT deno_date_nep FROM deno WHERE ref_no = :ref_no AND deno_date_nep != :deno_date_nep AND id != :id AND deleted_at IS NULL LIMIT 1");
                $check->execute([':ref_no'=>$_POST['ref_no'], ':deno_date_nep'=>$_POST['deno_date_nep'], ':id'=>$_POST['id']]);
                if ($check->fetch()) {
                    echo "<div class='alert alert-danger'>Error: Reference number " . htmlspecialchars($_POST['ref_no']) . " already exists with a different date.</div>";
                    break;
                }
                $conn->prepare("
                    UPDATE deno SET
                        book_code=:book_code, ref_no=:ref_no,
                        deno_date_nep=:deno_date_nep, deno_date_eng=:deno_date_eng,
                        per_poka_qty=:per_poka_qty, poka_qty=:poka_qty,
                        quantity_openpcs=:quantity_openpcs, notes=:notes,
                        sender_by=:sender_by, received_by=:received_by,
                        verify_by=:verify_by, update_remarks=:update_remarks,
                        updated_by=:updated_by, updated_at=CURRENT_TIMESTAMP
                    WHERE id=:id
                ")->execute([
                    ':id'=>$_POST['id'], 
                    ':book_code'=>$_POST['book_code'], 
                    ':ref_no'=>$_POST['ref_no'],
                    ':deno_date_nep'=>$_POST['deno_date_nep'], 
                    ':deno_date_eng'=>$_POST['deno_date_eng'],
                    ':per_poka_qty'=>$_POST['per_poka_qty'], 
                    ':poka_qty'=>$_POST['poka_qty'],
                    ':quantity_openpcs'=>$_POST['quantity_openpcs']??0, 
                    ':notes'=>$_POST['notes'],
                    ':sender_by'=>$_POST['sender_by']?:null,
                    ':received_by'=>$_POST['received_by']?:null,
                    ':verify_by'=>$_POST['verify_by']?:null,
                    ':update_remarks'=>$_POST['update_remarks'],
                    ':updated_by'=>$current_user_id
                ]);
                echo "<div class='alert alert-success'>Deno record updated successfully!</div>";
                break;

            case 'delete':
                // Soft delete
                $conn->prepare("UPDATE deno SET deleted_at = CURRENT_TIMESTAMP WHERE id = :id")->execute([':id'=>$_POST['id']]);
                echo "<div class='alert alert-success'>Deno record deleted successfully!</div>";
                break;
        }
    } catch (PDOException $e) {
        echo "<div class='alert alert-danger'>Error: " . htmlspecialchars($e->getMessage()) . "</div>";
    }
}

$books = $conn->query("SELECT book_code, book_name FROM books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);
$deno_records = $conn->query("
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

$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM deno WHERE id = :id AND deleted_at IS NULL");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}

// For the native <input type="date"> the browser needs YYYY-MM-DD (dashes).
$editEngForNativePicker = '';
if ($edit_record && !empty($edit_record['deno_date_eng'])) {
    $editEngForNativePicker = str_replace('.', '-', $edit_record['deno_date_eng']);
}
?>

<!-- Nepali Datepicker v5 CSS -->
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>
      <!-- <script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script> -->


<style>
/* ─── base ─── */
body { font-size:16px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }

.form-container { background:#f8f9fa; padding:20px; border-radius:8px; margin-bottom:30px; }
.form-row       { display:flex; gap:15px; margin-bottom:15px; align-items:end; }
.form-group     { flex:1; min-width:200px; }
.form-group label { display:block; margin-bottom:5px; font-weight:600; color:#333; font-size:15px; }

.form-control {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:4px; font-size:15px; box-sizing:border-box;
}
.form-control:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.25); }
.form-control:disabled { background-color:#e9ecef; cursor:not-allowed; }

/* ─── buttons ─── */
.btn { padding:12px 24px; border:none; border-radius:4px; cursor:pointer; font-size:15px; font-weight:600; text-decoration:none; display:inline-block; text-align:center; margin-right:8px; }
.btn-primary   { background:#007bff; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-success   { background:#28a745; color:#fff; }
.btn-warning   { background:#ffc107; color:#212529; }
.btn-danger    { background:#dc3545; color:#fff; }
.btn-info      { background:#17a2b8; color:#fff; }
.btn-sm        { padding:6px 12px; font-size:13px; margin-right:4px; }

.action-buttons { margin-bottom:20px; display:flex; gap:10px; align-items:center; }

/* ─── table ─── */
.table-container { background:#fff; border-radius:8px; overflow:hidden; box-shadow:0 2px 4px rgba(0,0,0,.1); }
.table { width:100%; border-collapse:collapse; font-size:14px; }
.table th, .table td { padding:10px 8px; text-align:left; border-bottom:1px solid #dee2e6; vertical-align:middle; }
.table th { background:#f8f9fa; font-weight:700; color:#495057; font-size:13px; text-transform:uppercase; letter-spacing:.5px; }
.table tbody tr:hover       { background:#f5f5f5; }
.table tbody tr:nth-child(even) { background:#fafafa; }

/* ─── alerts ─── */
.alert         { padding:15px 20px; margin-bottom:20px; border:1px solid transparent; border-radius:4px; font-size:15px; font-weight:500; }
.alert-success { color:#155724; background:#d4edda; border-color:#c3e6cb; }
.alert-danger  { color:#721c24; background:#f8d7da; border-color:#f5c6cb; }

/* ─── book search dropdown ─── */
.search-dropdown     { position:relative; }
.dropdown-search     { width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:4px; font-size:15px; box-sizing:border-box; }
.dropdown-options    { position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; border-top:none; max-height:200px; overflow-y:auto; z-index:1000; display:none; }
.dropdown-option     { padding:12px 14px; cursor:pointer; border-bottom:1px solid #eee; font-size:14px; }
.dropdown-option:hover       { background:#f8f9fa; }
.dropdown-option:last-child  { border-bottom:none; }

/* ─── Nepali datepicker input ─── */
.ndp-input {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:4px; font-size:15px; box-sizing:border-box;
    background:#fff; font-family:inherit;
}
.ndp-input:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.25); }

/* ─── English date wrapper ─── */
.date-eng-wrapper       { position:relative; }
.date-eng-wrapper .eng-display {
    width:100%; padding:10px 14px; border:1px solid #ddd;
    border-radius:4px; font-size:15px; box-sizing:border-box;
    background:#fff; color:#333; font-family:inherit;
    min-height:40px; line-height:20px; cursor:pointer;
    position:relative; z-index:1;
}
.date-eng-wrapper .eng-display:empty::before {
    content: 'Click to pick date…';
    color: #999;
}
.date-eng-wrapper input[type="date"] {
    position:absolute; top:0; left:0;
    width:100%; height:100%;
    opacity:0; cursor:pointer;
    z-index:2; margin:0; padding:0; border:none;
}

/* ─── print ─── */
@media print {
    body { font-size:11px; line-height:1.2; }
    .form-container,.action-buttons,.btn { display:none !important; }
    h2,h3 { font-size:14px; margin:10px 0; }
    .table { font-size:9px; width:100%; }
    .table th,.table td { padding:3px 2px; border:1px solid #000; font-size:8px; }
    .table th { background:#f0f0f0 !important; font-weight:bold; }
    .table-container { box-shadow:none; border:1px solid #000; }
    .alert { display:none !important; }
    @page { margin:.5in; size:A4 landscape; }
}
</style>

<div class="container">
<h2><?= $edit_record ? 'Edit Deno Entry' : 'Add Deno Entry' ?></h2>

<div class="form-container">
<form method="post" id="denoForm">
    <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
    <?php if ($edit_record): ?>
        <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
    <?php endif; ?>

    <!-- ── Book + Ref No ── -->
    <div class="form-row">
        <div class="form-group">
            <label>Book:</label>
            <div class="search-dropdown">
                <input type="text" class="form-control dropdown-search" id="book_search" placeholder="Search book..." autocomplete="off">
                <input type="hidden" name="book_code" id="book_code" value="<?= htmlspecialchars($edit_record['book_code'] ?? '') ?>">
                <div class="dropdown-options" id="book_options">
                    <?php foreach ($books as $book): ?>
                    <div class="dropdown-option"
                         data-value="<?= htmlspecialchars($book['book_code']) ?>"
                         data-text="<?= htmlspecialchars($book['book_name']) ?> (<?= htmlspecialchars($book['book_code']) ?>)">
                        <?= htmlspecialchars($book['book_name']) ?> (<?= htmlspecialchars($book['book_code']) ?>)
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
        <div class="form-group">
            <label for="ref_no">Reference No:</label>
            <input type="text" name="ref_no" id="ref_no" class="form-control" value="<?= htmlspecialchars($edit_record['ref_no'] ?? '') ?>" required>
        </div>
    </div>

    <!-- ══════════════════════════════════════════════════════════
         DATES — bi-directional auto-conversion
         ══════════════════════════════════════════════════════════ -->
    <div class="form-row">
        <!-- NEPALI (BS) -->
        <div class="form-group">
            <label for="deno_date_nep">Nepali Date (YYYY.MM.DD):</label>
            <input type="text"
                   id="deno_date_nep"
                   name="deno_date_nep"
                   class="form-control ndp-input"
                   placeholder="e.g. 2082.01.15"
                   value="<?= htmlspecialchars($edit_record['deno_date_nep'] ?? '') ?>"
                   autocomplete="off"
                   required>
        </div>

        <!-- ENGLISH (AD) -->
        <div class="form-group">
            <label>English Date (YYYY.MM.DD):</label>
            <input type="hidden" name="deno_date_eng" id="deno_date_eng_hidden"
                   value="<?= htmlspecialchars($edit_record['deno_date_eng'] ?? '') ?>">
            <div class="date-eng-wrapper">
                <div class="eng-display" id="eng_display">
                    <?= htmlspecialchars($edit_record['deno_date_eng'] ?? '') ?>
                </div>
                <input type="date"
                       id="deno_date_eng_native"
                       value="<?= $editEngForNativePicker ?>"
                       min="1944-01-01"
                       max="2044-12-31">
            </div>
        </div>
    </div>

    <!-- ── Quantities ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="per_poka_qty">Quantity per Poka:</label>
            <input type="number" name="per_poka_qty" id="per_poka_qty" class="form-control" value="<?= $edit_record['per_poka_qty'] ?? '' ?>" required>
        </div>
        <div class="form-group">
            <label for="poka_qty">Number of Pokas:</label>
            <input type="number" name="poka_qty" id="poka_qty" class="form-control" value="<?= $edit_record['poka_qty'] ?? '' ?>" required>
        </div>
    </div>

    <!-- ── Open Pcs + Created By (read-only, auto-filled) ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="quantity_openpcs">Open Pieces:</label>
            <input type="number" name="quantity_openpcs" id="quantity_openpcs" class="form-control" value="<?= $edit_record['quantity_openpcs'] ?? '0' ?>">
        </div>
        <div class="form-group">
            <label for="created_by_display">Created By:</label>
            <input type="text" id="created_by_display" class="form-control" value="<?= $current_user ?>" disabled>
        </div>
    </div>

    <!-- ── Sender By (role='press') + Received By (role='marketing') ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="sender_by">Sender By:</label>
            <select name="sender_by" id="sender_by" class="form-control">
                <option value="">Select Sender</option>
                <?php foreach ($press_users as $user): ?>
                <option value="<?= $user['id'] ?>" 
                    <?= ($edit_record && $edit_record['sender_by'] == $user['id']) || (!$edit_record && $user['id'] == $current_user_id) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($user['username']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group">
            <label for="received_by">Received By:</label>
            <select name="received_by" id="received_by" class="form-control">
                <option value="">Select Receiver</option>
                <?php foreach ($marketing_users as $user): ?>
                <option value="<?= $user['id'] ?>" <?= ($edit_record && $edit_record['received_by'] == $user['id']) ? 'selected' : '' ?>>
                    <?= htmlspecialchars($user['username']) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <!-- ── Verified By (role='marketing') + Notes ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="verify_by">Verified By:</label>
            <select name="verify_by" id="verify_by" class="form-control">
                <option value="">Select Verifier</option>
                <?php foreach ($marketing_users as $user): ?>
                <option value="<?= $user['id'] ?>" <?= ($edit_record && $edit_record['verify_by'] == $user['id']) ? 'selected' : '' ?>>
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

    <!-- ── Update Remarks + Submit ── -->
    <div class="form-row">
        <div class="form-group">
            <label for="update_remarks">Update Remarks:</label>
            <textarea name="update_remarks" id="update_remarks" class="form-control" rows="1"><?= htmlspecialchars($edit_record['update_remarks'] ?? '') ?></textarea>
        </div>
        <div class="form-group">
            <button type="submit" class="btn btn-primary">
                <?= $edit_record ? 'Update Deno' : 'Save Deno' ?>
            </button>
            <?php if ($edit_record): ?>
                <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
            <?php endif; ?>
        </div>
    </div>
</form>
</div>

<!-- ── Records table ── -->
<h3>Latest Deno Records</h3>
<div class="action-buttons">
    <button onclick="window.print()" class="btn btn-info">🖨️ Print</button>
    <a href="?export=excel" class="btn btn-success">📊 Export to Excel</a>
    <button onclick="downloadCSV()" class="btn btn-secondary">📥 Download CSV</button>
</div>
<div class="table-container">
    <table class="table">
        <thead>
            <tr>
                <th>ID</th><th>Book</th><th>Ref No</th><th>Nepali Date</th><th>English Date</th>
                <th>Per Poka</th><th>Pokas</th><th>Total</th><th>Open Pcs</th>
                <th>Created By</th><th>Sender By</th><th>Received By</th><th>Actions</th>
            </tr>
        </thead>
        <tbody>
        <?php foreach ($deno_records as $rec): ?>
            <tr>
                <td><?= $rec['id'] ?></td>
                <td><?= htmlspecialchars($rec['book_name']) ?></td>
                <td><?= htmlspecialchars($rec['ref_no']) ?></td>
                <td><?= htmlspecialchars($rec['deno_date_nep']) ?></td>
                <td><?= htmlspecialchars($rec['deno_date_eng']) ?></td>
                <td><?= number_format($rec['per_poka_qty']) ?></td>
                <td><?= number_format($rec['poka_qty']) ?></td>
                <td><?= number_format($rec['total_qty']) ?></td>
                <td><?= number_format($rec['quantity_openpcs']) ?></td>
                <td><?= htmlspecialchars($rec['created_user']) ?></td>
                <td><?= htmlspecialchars($rec['sender_user'] ?? '-') ?></td>
                <td><?= htmlspecialchars($rec['received_user'] ?? '-') ?></td>
                <td>
                    <a href="?edit_id=<?= $rec['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Delete this record?')">
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= $rec['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
</div>
</div><!-- /container -->

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ══════════════════════════════════════
       BOOK SEARCH DROPDOWN
       ══════════════════════════════════════ */
    var searchInput      = document.getElementById('book_search');
    var hiddenInput      = document.getElementById('book_code');
    var optionsContainer = document.getElementById('book_options');
    var options          = optionsContainer.querySelectorAll('.dropdown-option');

    <?php if ($edit_record): ?>
    (function(){
        var code = "<?= htmlspecialchars($edit_record['book_code']) ?>";
        var el   = document.querySelector('[data-value="' + code + '"]');
        if (el) { searchInput.value = el.dataset.text; hiddenInput.value = code; }
    })();
    <?php endif; ?>

    searchInput.addEventListener('focus', function(){ optionsContainer.style.display='block'; filterOptions(); });
    searchInput.addEventListener('input', function(){ filterOptions(); optionsContainer.style.display='block'; });
    document.addEventListener('click', function(e){ if(!e.target.closest('.search-dropdown')) optionsContainer.style.display='none'; });

    function filterOptions(){
        var t = searchInput.value.toLowerCase();
        options.forEach(function(o){
            o.style.display = (o.textContent.toLowerCase().includes(t) || o.dataset.value.toLowerCase().includes(t)) ? 'block' : 'none';
        });
    }
    options.forEach(function(o){
        o.addEventListener('click', function(){
            searchInput.value = this.dataset.text;
            hiddenInput.value = this.dataset.value;
            optionsContainer.style.display = 'none';
        });
    });

    /* ══════════════════════════════════════
       BIDIRECTIONAL DATE CONVERSION
       ══════════════════════════════════════ */
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

    // NEPALI → ENGLISH conversion
    nepField.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function () {
            if (blockNepConversion) return;
            var bsVal = nepField.value.trim();
            if (!bsVal) return;
            try {
                var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
                if (adVal) fillEngFields(adVal);
            } catch (e) {
                console.warn('BS→AD failed:', e);
            }
        }
    });

    // ENGLISH → NEPALI conversion
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
        } catch (e) {
            console.warn('AD→BS failed:', e);
            blockNepConversion = false;
        }
    });

    // Manual Nepali date typing → convert on blur
    nepField.addEventListener('blur', function () {
        if (blockNepConversion) return;
        var bsVal = nepField.value.trim();
        if (!bsVal) return;
        try {
            var adVal = NepaliFunctions.BS2AD(bsVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (adVal) fillEngFields(adVal);
        } catch (e) { /* invalid */ }
    });

    /* ══════════════════════════════════════
       TOTAL QUANTITY CALCULATION
       ══════════════════════════════════════ */
    var perPokaQty = document.getElementById('per_poka_qty');
    var pokaQty    = document.getElementById('poka_qty');
    function calcTotal(){
        console.log('Total Quantity:', (parseInt(perPokaQty.value)||0) * (parseInt(pokaQty.value)||0));
    }
    perPokaQty.addEventListener('input', calcTotal);
    pokaQty.addEventListener('input', calcTotal);
});

/* ─── CSV download ─── */
function downloadCSV() {
    var rows = Array.from(document.querySelector('.table').querySelectorAll('tr'));
    var csv  = "data:text/csv;charset=utf-8,";
    rows.forEach(function(row){
        var cols = Array.from(row.querySelectorAll('th,td'));
        csv += cols.map(function(c){
            var d = c.textContent.trim();
            if (d.includes('Edit') && d.includes('Delete')) d = '';
            if (d.includes(',') || d.includes('"')) d = '"' + d.replace(/"/g,'""') + '"';
            return d;
        }).join(',') + "\r\n";
    });
    var a = document.createElement('a');
    a.setAttribute('href', encodeURI(csv));
    a.setAttribute('download', 'deno_records_' + new Date().toISOString().split('T')[0] + '.csv');
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

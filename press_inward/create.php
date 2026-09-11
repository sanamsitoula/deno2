<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/lib/AuditLogger.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// redirect_if_not_logged_in();
// Only marketing-role users (they're the ones who receive stock into a godam) or admins may record inward.
// if (!has_role('marketing') && !has_role('admin')) {
//     header('Location: ' . $_SERVER['DOCUMENT_ROOT'] . '/deno2/index.php?error=' . urlencode('Only marketing users can record press inward.'));
//     exit;
// }

$auditLogger = new AuditLogger($conn, 'PressInwardCreate', 'PressInward');

$current_user    = $_SESSION['username'] ?? 'system';
$current_user_id = $_SESSION['user_id']  ?? null;

$active_fiscal_years = $conn->query("
    SELECT id, fiscal_name, fiscal_code FROM fiscal_years WHERE is_active = true ORDER BY fiscal_name
")->fetchAll(PDO::FETCH_ASSOC);
$active_fy_row  = $active_fiscal_years[0] ?? null;
$active_fy_name = $active_fy_row['fiscal_name'] ?? null;
$active_fy_id   = $active_fy_row['id']          ?? null;

// The godams THIS marketing user is authorized to receive into (any book) —
// drives the Godam dropdown. Once a godam + D2M are both picked, the item
// list narrows further to just the books assigned to this user in that godam.
$my_godams = $conn->prepare("
    SELECT DISTINCT g.id, g.godam_name
    FROM   godam_book_assignment gba
    JOIN   godam g ON g.id = gba.godam_id
    WHERE  gba.marketing_user_id = :uid AND gba.is_active = true AND g.is_active = true
    ORDER  BY g.godam_name
");
$my_godams->execute([':uid' => $current_user_id]);
$my_godams = $my_godams->fetchAll(PDO::FETCH_ASSOC);

/* ═══════════════════════════════════════════════════════════════════
   POST  —  create a press-inward VOUCHER (header + one or more lines)
   ═══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    try {
        $auditLogger->prepareForAudit();

        $required = ['d2m_id', 'godam_id', 'inward_date_nep', 'inward_date_eng'];
        foreach ($required as $f) {
            if (empty($_POST[$f])) {
                throw new Exception("Field '{$f}' is required");
            }
        }

        $d2m_id   = (int)$_POST['d2m_id'];
        $godam_id = (int)$_POST['godam_id'];

        $item_ids     = $_POST['item_ids']     ?? [];   // checked d2m_item_ids
        $sent_qtys    = $_POST['sent_qty']     ?? [];   // keyed by d2m_item_id
        $received_qtys= $_POST['received_qty'] ?? [];
        $line_remarks = $_POST['line_remarks'] ?? [];

        if (empty($item_ids)) {
            throw new Exception('Select at least one book to inward.');
        }

        if (!$active_fy_id) {
            throw new Exception('No active fiscal year set. Please configure one first.');
        }

        // Snapshot the D2M (once, for the header)
        $stmt = $conn->prepare("
            SELECT d.*, COALESCE(us.username, uc.username) AS sender_name,
                   COALESCE(d.send_by, d.created_by) AS resolved_sender_id
            FROM   d2m d
            LEFT JOIN users us ON d.send_by    = us.id
            LEFT JOIN users uc ON d.created_by = uc.id
            WHERE  d.id = :id AND d.deleted_at IS NULL
        ");
        $stmt->execute([':id' => $d2m_id]);
        $d2m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$d2m) throw new Exception('D2M record not found.');

        // Pre-validate every selected line BEFORE writing anything
        $lines = [];
        foreach ($item_ids as $item_id) {
            $item_id = (int)$item_id;
            $sent     = (int)($sent_qtys[$item_id]     ?? -1);
            $received = (int)($received_qtys[$item_id] ?? -1);
            $remarks  = trim($line_remarks[$item_id] ?? '');

            if ($sent < 0 || $received < 0) {
                throw new Exception("Sent/Received quantity missing for one of the selected books.");
            }
            if ($sent !== $received && $remarks === '') {
                throw new Exception("Remarks are required for a book whose sent and received quantities differ.");
            }

            $stmt = $conn->prepare("
                SELECT di.*, b.book_name
                FROM   d2m_items di
                LEFT JOIN books b ON di.book_code = b.book_code
                WHERE  di.id = :id AND di.d2m_id = :d2m_id
            ");
            $stmt->execute([':id' => $item_id, ':d2m_id' => $d2m_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) throw new Exception("D2M item #{$item_id} not found in this D2M.");

            // Confirm this marketing user is authorized for this book in this godam
            $stmt = $conn->prepare("
                SELECT 1 FROM godam_book_assignment
                WHERE godam_id = :gid AND book_code = :bc AND marketing_user_id = :uid AND is_active = true
            ");
            $stmt->execute([':gid' => $godam_id, ':bc' => $item['book_code'], ':uid' => $current_user_id]);
            if (!$stmt->fetch()) {
                throw new Exception("You are not assigned to receive '{$item['book_name']}' in the selected godam.");
            }

            // Remaining-quantity check
            $stmt = $conn->prepare("SELECT remaining_to_inward FROM v_d2m_item_inward_summary WHERE d2m_item_id = :id");
            $stmt->execute([':id' => $item_id]);
            $summary = $stmt->fetch(PDO::FETCH_ASSOC);
            $remaining = $summary ? (int)$summary['remaining_to_inward'] : (int)$item['total_qty'];
            if ($sent > $remaining) {
                throw new Exception("'{$item['book_name']}': sent quantity ({$sent}) exceeds remaining ({$remaining}).");
            }

            $lines[] = [
                'item'     => $item,
                'sent'     => $sent,
                'received' => $received,
                'remarks'  => $remarks ?: null,
                'status'   => ($sent === $received) ? 'RECEIVED' : 'DISCREPANCY',
            ];
        }

        $conn->beginTransaction();

        [$inward_serial_no, $inward_no] = generateFiscalScopedNumber(
            $conn, 'press_inward', 'inward_serial_no', $active_fy_id, 'inward', $active_fy_row
        );

        $headerStmt = $conn->prepare("
            INSERT INTO press_inward
                (inward_no, inward_serial_no,
                 d2m_id, d2m_no, d2m_type, d2m_nep_date, d2m_eng_date, d2m_sender_id, d2m_sender_name,
                 godam_id, received_by,
                 fiscal_year_id, inward_date_nep, inward_date_eng,
                 remarks, created_by)
            VALUES
                (:inward_no, :inward_serial_no,
                 :d2m_id, :d2m_no, :d2m_type, :d2m_nep_date, :d2m_eng_date, :d2m_sender_id, :d2m_sender_name,
                 :godam_id, :received_by,
                 :fiscal_year_id, :inward_date_nep, :inward_date_eng,
                 :remarks, :created_by)
            RETURNING id
        ");
        $headerStmt->execute([
            ':inward_no'        => $inward_no,
            ':inward_serial_no' => $inward_serial_no,
            ':d2m_id'           => $d2m_id,
            ':d2m_no'           => $d2m['d2m_no'],
            ':d2m_type'         => $d2m['d2m_type'],
            ':d2m_nep_date'     => $d2m['nep_date'],
            ':d2m_eng_date'     => $d2m['eng_date'],
            ':d2m_sender_id'    => $d2m['resolved_sender_id'],
            ':d2m_sender_name'  => $d2m['sender_name'],
            ':godam_id'         => $godam_id,
            ':received_by'      => $current_user_id,
            ':fiscal_year_id'   => $active_fy_id,
            ':inward_date_nep'  => $_POST['inward_date_nep'],
            ':inward_date_eng'  => $_POST['inward_date_eng'],
            ':remarks'          => trim($_POST['remarks'] ?? '') ?: null,
            ':created_by'       => $current_user_id,
        ]);
        $header_id = $headerStmt->fetchColumn();

        $lineStmt = $conn->prepare("
            INSERT INTO press_inward_details
                (press_inward_id, d2m_item_id,
                 book_code, book_name, item_per_poka_qty, item_total_poka_qty, item_total_qty,
                 item_open_pcs, item_deno_serial_number, item_associated_deno_ids,
                 sent_qty, received_qty, status, remarks)
            VALUES
                (:press_inward_id, :d2m_item_id,
                 :book_code, :book_name, :item_per_poka_qty, :item_total_poka_qty, :item_total_qty,
                 :item_open_pcs, :item_deno_serial_number, :item_associated_deno_ids,
                 :sent_qty, :received_qty, :status, :remarks)
        ");
        $discrepancy_lines = 0;
        foreach ($lines as $line) {
            $item = $line['item'];
            $lineStmt->execute([
                ':press_inward_id'          => $header_id,
                ':d2m_item_id'              => $item['id'],
                ':book_code'                => $item['book_code'],
                ':book_name'                => $item['book_name'],
                ':item_per_poka_qty'        => $item['per_poka_qty'],
                ':item_total_poka_qty'      => $item['total_poka_qty'],
                ':item_total_qty'           => $item['total_qty'],
                ':item_open_pcs'            => $item['open_pcs'],
                ':item_deno_serial_number'  => $item['deno_serial_number'],
                ':item_associated_deno_ids' => $item['associated_deno_ids'],
                ':sent_qty'                 => $line['sent'],
                ':received_qty'             => $line['received'],
                ':status'                   => $line['status'],
                ':remarks'                  => $line['remarks'],
            ]);
            if ($line['status'] === 'DISCREPANCY') $discrepancy_lines++;
        }

        $conn->commit();

        $_SESSION['flash'] = [
            'type' => $discrepancy_lines ? 'warning' : 'success',
            'msg'  => "Press Inward voucher {$inward_no} recorded with " . count($lines) . ' book(s)'
                    . ($discrepancy_lines ? " — {$discrepancy_lines} flagged as DISCREPANCY." : '.'),
        ];
        header('Location: index.php');
        exit;

    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error: ' . htmlspecialchars($e->getMessage())];
        header('Location: ' . $_SERVER['PHP_SELF']);
        exit;
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Latest 10 lines from vouchers THIS marketing user created, this fiscal year
$my_recent = [];
if ($active_fy_id && $current_user_id) {
    $stmt = $conn->prepare("
        SELECT * FROM v_press_inward_full_details
        WHERE  received_by = :uid AND fiscal_year_id = :fyid
        ORDER  BY created_at DESC
        LIMIT  10
    ");
    $stmt->execute([':uid' => $current_user_id, ':fyid' => $active_fy_id]);
    $my_recent = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>

<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>

<style>
body { font-size:16px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; }
.container { max-width:1200px; margin:0 auto; }

.form-container { background:#f8f9fa; padding:24px; border-radius:8px; margin-bottom:30px; border:1px solid #e9ecef; }
.form-row   { display:flex; gap:16px; margin-bottom:16px; align-items:end; flex-wrap:wrap; }
.form-group { flex:1; min-width:220px; display:flex; flex-direction:column; }
.form-group label { font-weight:600; color:#495057; margin-bottom:6px; font-size:14px; }

.form-control { padding:10px 14px; border:1px solid #ddd; border-radius:5px; font-size:15px; box-sizing:border-box; font-family:inherit; }
.form-control:focus { outline:none; border-color:#007bff; box-shadow:0 0 0 2px rgba(0,123,255,.2); }
.form-control:disabled, .form-control[readonly] { background:#e9ecef; cursor:not-allowed; color:#495057; }

.btn { padding:11px 24px; border:none; border-radius:5px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; display:inline-block; margin-right:8px; }
.btn-primary { background:#007bff; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }

.alert { padding:14px 18px; margin-bottom:18px; border-radius:5px; font-size:15px; font-weight:500; }
.alert-success { color:#155724; background:#d4edda; border:1px solid #c3e6cb; }
.alert-danger  { color:#721c24; background:#f8d7da; border:1px solid #f5c6cb; }
.alert-warning { color:#856404; background:#fff3cd; border:1px solid #ffe69c; }

.search-dropdown { position:relative; }
.dropdown-options {
    position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; border-top:none;
    max-height:240px; overflow-y:auto; z-index:1000; display:none; box-shadow:0 4px 10px rgba(0,0,0,.1);
}
.dropdown-option { padding:10px 14px; cursor:pointer; border-bottom:1px solid #eee; font-size:14px; }
.dropdown-option:hover { background:#f0f7ff; }

/* Item lines table */
.lines-table { width:100%; border-collapse:collapse; margin-top:8px; }
.lines-table th, .lines-table td { padding:8px; border-bottom:1px solid #dee2e6; font-size:13px; vertical-align:middle; }
.lines-table th { background:#f1f3f5; text-transform:uppercase; font-size:11px; color:#495057; text-align:left; }
.lines-table input[type="number"] { width:90px; padding:6px 8px; border:1px solid #ddd; border-radius:4px; }
.lines-table input[type="text"] { width:100%; padding:6px 8px; border:1px solid #ddd; border-radius:4px; }
.remaining-cell { font-weight:700; color:#28a745; }
.remaining-cell.zero { color:#dc3545; }
.remarks-cell.required input { border-color:#ffc107; background:#fffbea; }
.line-disc-flag { font-size:11px; font-weight:700; color:#dc3545; }

.ndp-input { width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:5px; font-size:15px; box-sizing:border-box; }
.date-eng-wrapper { position:relative; }
.date-eng-wrapper .eng-display {
    width:100%; padding:10px 14px; border:1px solid #ddd; border-radius:5px; font-size:15px; box-sizing:border-box;
    background:#fff; min-height:41px; line-height:20px; cursor:pointer; position:relative; z-index:1;
}
.date-eng-wrapper .eng-display:empty::before { content:'Click to pick date…'; color:#999; }
.date-eng-wrapper input[type="date"] { position:absolute; top:0; left:0; width:100%; height:100%; opacity:0; cursor:pointer; z-index:2; }

.table-container { background:#fff; border-radius:8px; overflow-x:auto; box-shadow:0 2px 4px rgba(0,0,0,.1); }
.table { width:100%; border-collapse:collapse; font-size:13px; }
.table th, .table td { padding:8px; text-align:left; border-bottom:1px solid #dee2e6; }
.table th { background:#f8f9fa; font-size:11px; text-transform:uppercase; color:#495057; }
.badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; }
.badge-received    { background:#d4edda; color:#155724; }
.badge-discrepancy { background:#fff3cd; color:#856404; }
.badge-cancelled   { background:#f8d7da; color:#721c24; }

.fy-info { font-size:13px; color:#555; margin-bottom:16px; }
.fy-info span { font-weight:600; color:#0066cc; }
.hint { font-size:12px; color:#888; }
</style>

<div class="container">
<h2>📥 Press Inward — New Voucher</h2>

<?php if ($active_fy_name): ?>
<div class="fy-info">Active fiscal year: <span><?= htmlspecialchars($active_fy_name) ?></span> — Inward No is auto-generated per voucher for this year.</div>
<?php else: ?>
<div class="alert alert-danger">No active fiscal year configured.</div>
<?php endif; ?>

<?php if (empty($my_godams)): ?>
<div class="alert alert-warning">You aren't assigned to any godam yet. Ask an admin to add you to a <code>godam_book_assignment</code> row before you can record an inward.</div>
<?php endif; ?>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= $flash['msg'] ?></div>
<?php endif; ?>

<div class="form-container">
<form method="post" id="inwardForm">
    <input type="hidden" name="action" value="create">
    <input type="hidden" name="d2m_id" id="d2m_id">

    <!-- Godam + D2M -->
    <div class="form-row">
        <div class="form-group">
            <label for="godam_id">🏬 Godam (yours):</label>
            <select name="godam_id" id="godam_id" class="form-control">
                <option value="">— Select Godam —</option>
                <?php foreach ($my_godams as $g): ?>
                <option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['godam_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="form-group" style="flex:2;">
            <label>🔎 D2M Number:</label>
            <div class="search-dropdown">
                <input type="text" class="form-control" id="d2m_search" placeholder="Type to search D2M number…" autocomplete="off">
                <div class="dropdown-options" id="d2m_options"></div>
            </div>
        </div>
        <div class="form-group">
            <label>Sender (from D2M)</label>
            <input type="text" class="form-control" id="sender_display" value="" disabled placeholder="—">
        </div>
        <div class="form-group">
            <label>Received By (you)</label>
            <input type="text" class="form-control" value="<?= htmlspecialchars($current_user) ?>" disabled>
        </div>
    </div>

    <p class="hint" id="pick_both_hint">Pick a godam and search a D2M to see which books you can receive.</p>

    <!-- Book lines -->
    <div id="lines_section" style="display:none;">
        <label>📚 Books in this D2M you're authorized to receive here — check the ones in this delivery:</label>
        <table class="lines-table">
            <thead>
                <tr>
                    <th style="width:26px;"></th>
                    <th>Book</th>
                    <th>Ref No</th>
                    <th>Remaining</th>
                    <th>Sent Qty</th>
                    <th>Received Qty</th>
                    <th>Remarks</th>
                </tr>
            </thead>
            <tbody id="lines_body"></tbody>
        </table>
    </div>

    <!-- Voucher-level date + remarks -->
    <div class="form-row" id="voucher_meta" style="display:none; margin-top:20px;">
        <div class="form-group">
            <label for="inward_date_nep">📅 Nepali Date:</label>
            <input type="text" id="inward_date_nep" name="inward_date_nep" class="form-control ndp-input"
                   placeholder="e.g. 2082.01.15" autocomplete="off" required>
        </div>
        <div class="form-group">
            <label>📅 English Date:</label>
            <input type="hidden" name="inward_date_eng" id="inward_date_eng_hidden">
            <div class="date-eng-wrapper">
                <div class="eng-display" id="eng_display"></div>
                <input type="date" id="inward_date_eng_native" min="1944-01-01" max="2044-12-31">
            </div>
        </div>
        <div class="form-group" style="flex:2;">
            <label for="remarks">Voucher Remarks <small class="hint">(optional, general note about this delivery)</small>:</label>
            <input type="text" name="remarks" id="remarks" class="form-control">
        </div>
    </div>

    <div class="form-row" id="submit_section" style="display:none;">
        <button type="submit" class="btn btn-primary">✅ Record Inward Voucher</button>
        <a href="index.php" class="btn btn-secondary">Cancel</a>
    </div>
</form>
</div>

<h3>My Recent Inward Lines <?= $active_fy_name ? '— FY ' . htmlspecialchars($active_fy_name) : '' ?></h3>
<div class="table-container">
    <table class="table">
        <thead>
            <tr>
                <th>Inward No</th><th>D2M No</th><th>Ref No</th><th>Book</th><th>Godam</th>
                <th>Sent</th><th>Recv</th><th>Status</th><th>Sender</th><th>Date</th>
            </tr>
        </thead>
        <tbody>
        <?php if (empty($my_recent)): ?>
            <tr><td colspan="10" style="text-align:center;color:#888;padding:20px;">No inward entries yet.</td></tr>
        <?php else: foreach ($my_recent as $r): ?>
            <tr>
                <td><strong><?= htmlspecialchars($r['inward_no']) ?></strong></td>
                <td><?= htmlspecialchars($r['d2m_no']) ?></td>
                <td><?= htmlspecialchars($r['item_deno_serial_number'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['book_name']) ?></td>
                <td><?= htmlspecialchars($r['godam_name'] ?? '-') ?></td>
                <td><?= number_format($r['sent_qty']) ?></td>
                <td><?= number_format($r['received_qty']) ?></td>
                <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
                <td><?= htmlspecialchars($r['sender_username'] ?? $r['d2m_sender_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($r['inward_date_nep']) ?></td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</div>

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"
        type="text/javascript"></script>
<script>
document.addEventListener('DOMContentLoaded', function () {

    var godamSelect = document.getElementById('godam_id');
    var d2mSearch    = document.getElementById('d2m_search');
    var d2mOptions   = document.getElementById('d2m_options');
    var d2mIdInput   = document.getElementById('d2m_id');
    var senderDisp   = document.getElementById('sender_display');

    var pickHint      = document.getElementById('pick_both_hint');
    var linesSection  = document.getElementById('lines_section');
    var linesBody     = document.getElementById('lines_body');
    var voucherMeta   = document.getElementById('voucher_meta');
    var submitSection = document.getElementById('submit_section');

    var searchTimer;

    /* ── D2M search ── */
    d2mSearch.addEventListener('input', function () {
        clearTimeout(searchTimer);
        var term = this.value.trim();
        searchTimer = setTimeout(function () {
            fetch('search_lookup.php?type=d2m&q=' + encodeURIComponent(term))
                .then(function (r) { return r.json(); })
                .then(renderD2mOptions);
        }, 250);
    });
    d2mSearch.addEventListener('focus', function () {
        fetch('search_lookup.php?type=d2m&q=' + encodeURIComponent(this.value.trim()))
            .then(function (r) { return r.json(); })
            .then(renderD2mOptions);
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#d2m_search') && !e.target.closest('#d2m_options')) d2mOptions.style.display = 'none';
    });

    function renderD2mOptions(list) {
        d2mOptions.innerHTML = '';
        if (!list.length) {
            d2mOptions.innerHTML = '<div class="dropdown-option" style="color:#999;">No matches</div>';
        } else {
            list.forEach(function (d) {
                var el = document.createElement('div');
                el.className = 'dropdown-option';
                el.innerHTML = '<strong>' + d.label + '</strong><br><small style="color:#888">' + d.sublabel + '</small>';
                el.addEventListener('click', function () {
                    d2mSearch.value = d.label;
                    d2mIdInput.value = d.value;
                    senderDisp.value = d.sender_name || '-';
                    d2mOptions.style.display = 'none';
                    maybeLoadItems();
                });
                d2mOptions.appendChild(el);
            });
        }
        d2mOptions.style.display = 'block';
    }

    godamSelect.addEventListener('change', maybeLoadItems);

    function maybeLoadItems() {
        if (!godamSelect.value || !d2mIdInput.value) {
            linesSection.style.display = 'none';
            voucherMeta.style.display = 'none';
            submitSection.style.display = 'none';
            pickHint.style.display = 'block';
            return;
        }
        pickHint.style.display = 'none';
        fetch('get_d2m_items.php?d2m_id=' + encodeURIComponent(d2mIdInput.value) + '&godam_id=' + encodeURIComponent(godamSelect.value))
            .then(function (r) { return r.json(); })
            .then(renderLines);
    }

    function renderLines(items) {
        linesBody.innerHTML = '';
        if (!items.length) {
            linesBody.innerHTML = '<tr><td colspan="7" style="text-align:center;color:#888;padding:14px;">No books in this D2M are assigned to you for this godam.</td></tr>';
            linesSection.style.display = 'block';
            voucherMeta.style.display = 'none';
            submitSection.style.display = 'none';
            return;
        }

        items.forEach(function (item) {
            var tr = document.createElement('tr');
            var remClass = (item.remaining_to_inward <= 0) ? 'remaining-cell zero' : 'remaining-cell';

            tr.innerHTML =
                '<td><input type="checkbox" class="line-check" data-id="' + item.id + '"></td>' +
                '<td>' + item.book_name + '</td>' +
                '<td>' + (item.deno_serial_number || '-') + '</td>' +
                '<td class="' + remClass + '">' + Number(item.remaining_to_inward).toLocaleString() + '</td>' +
                '<td><input type="number" min="0" class="sent-input" data-id="' + item.id + '" disabled></td>' +
                '<td><input type="number" min="0" class="received-input" data-id="' + item.id + '" disabled></td>' +
                '<td class="remarks-cell"><input type="text" class="remarks-input" data-id="' + item.id + '" placeholder="required if qty differs" disabled></td>';

            linesBody.appendChild(tr);

            var checkbox = tr.querySelector('.line-check');
            var sentInput = tr.querySelector('.sent-input');
            var receivedInput = tr.querySelector('.received-input');
            var remarksInput = tr.querySelector('.remarks-input');

            checkbox.addEventListener('change', function () {
                var enabled = this.checked;
                sentInput.disabled = !enabled;
                receivedInput.disabled = !enabled;
                remarksInput.disabled = !enabled;
                if (enabled) {
                    sentInput.name = 'sent_qty[' + item.id + ']';
                    receivedInput.name = 'received_qty[' + item.id + ']';
                    remarksInput.name = 'line_remarks[' + item.id + ']';
                    var idField = document.createElement('input');
                    idField.type = 'hidden';
                    idField.name = 'item_ids[]';
                    idField.value = item.id;
                    idField.className = 'item-id-hidden';
                    idField.dataset.id = item.id;
                    tr.appendChild(idField);
                } else {
                    sentInput.name = '';
                    receivedInput.name = '';
                    remarksInput.name = '';
                    sentInput.value = '';
                    receivedInput.value = '';
                    remarksInput.value = '';
                    var existing = tr.querySelector('.item-id-hidden');
                    if (existing) existing.remove();
                }
                syncSubmitVisibility();
            });

            sentInput.addEventListener('input', function () {
                if (receivedInput.value === '') receivedInput.value = sentInput.value;
                checkLineDiscrepancy(sentInput, receivedInput, remarksInput);
            });
            receivedInput.addEventListener('input', function () {
                checkLineDiscrepancy(sentInput, receivedInput, remarksInput);
            });
        });

        linesSection.style.display = 'block';
        voucherMeta.style.display = 'flex';
        syncSubmitVisibility();
    }

    function checkLineDiscrepancy(sentInput, receivedInput, remarksInput) {
        var s = parseInt(sentInput.value, 10);
        var r = parseInt(receivedInput.value, 10);
        var cell = remarksInput.closest('.remarks-cell');
        if (!isNaN(s) && !isNaN(r) && s !== r) {
            cell.classList.add('required');
            remarksInput.placeholder = 'required — quantities differ';
        } else {
            cell.classList.remove('required');
            remarksInput.placeholder = 'required if qty differs';
        }
    }

    function syncSubmitVisibility() {
        var anyChecked = document.querySelectorAll('.line-check:checked').length > 0;
        submitSection.style.display = anyChecked ? 'flex' : 'none';
    }

    /* ── Date pickers (same pattern as Deno) ── */
    var nepField  = document.getElementById('inward_date_nep');
    var engHidden = document.getElementById('inward_date_eng_hidden');
    var engDisplay= document.getElementById('eng_display');
    var engNative = document.getElementById('inward_date_eng_native');
    var blockNep  = false;

    function fillEngFields(dotVal) {
        engHidden.value = dotVal;
        engDisplay.textContent = dotVal;
        engNative.value = dotVal.replace(/\./g, '-');
    }
    nepField.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function () {
            if (blockNep) return;
            var v = nepField.value.trim();
            if (!v) return;
            try { var ad = NepaliFunctions.BS2AD(v, 'YYYY.MM.DD', 'YYYY.MM.DD'); if (ad) fillEngFields(ad); } catch (e) {}
        }
    });
    engNative.addEventListener('change', function () {
        if (!this.value) return;
        var dotVal = this.value.replace(/-/g, '.');
        fillEngFields(dotVal);
        try {
            var bs = NepaliFunctions.AD2BS(dotVal, 'YYYY.MM.DD', 'YYYY.MM.DD');
            if (bs) { blockNep = true; nepField.value = bs; blockNep = false; }
        } catch (e) { blockNep = false; }
    });

    document.getElementById('inwardForm').addEventListener('submit', function (e) {
        var anyChecked = document.querySelectorAll('.line-check:checked').length > 0;
        if (!anyChecked) { e.preventDefault(); alert('Select at least one book to inward.'); return; }

        var missing = false;
        document.querySelectorAll('.line-check:checked').forEach(function (cb) {
            var tr = cb.closest('tr');
            var sent = tr.querySelector('.sent-input').value;
            var received = tr.querySelector('.received-input').value;
            var remarks = tr.querySelector('.remarks-input').value.trim();
            if (sent === '' || received === '') missing = true;
            if (sent !== received && remarks === '') missing = true;
        });
        if (missing) {
            e.preventDefault();
            alert('Every checked book needs Sent & Received quantities, and Remarks if they differ.');
        }
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

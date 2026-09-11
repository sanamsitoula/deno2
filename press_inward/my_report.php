<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (session_status() === PHP_SESSION_NONE) session_start();
$current_user_id = $_SESSION['user_id'] ?? null;
$current_user    = $_SESSION['username'] ?? 'system';

$fiscal_years_list = $conn->query("SELECT id, fiscal_code, fiscal_name, is_active FROM fiscal_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_fy_range = getActiveFiscalDateRange($conn);
$active_fy_id    = $active_fy_range['fiscal_year_id'] ?? '';
$selected_fy_id  = isset($_GET['fiscal_year_id']) ? $_GET['fiscal_year_id'] : $active_fy_id;

$status_filter = $_GET['status'] ?? '';

$where = "WHERE received_by = :uid";
$params = [':uid' => $current_user_id];
if (!empty($selected_fy_id)) { $where .= " AND fiscal_year_id = :fyid"; $params[':fyid'] = $selected_fy_id; }
if (!empty($status_filter))  { $where .= " AND status = :status";      $params[':status'] = $status_filter; }

$stmt = $conn->prepare("SELECT * FROM v_press_inward_full_details $where ORDER BY created_at DESC");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_sent = 0; $total_received = 0; $discrepancy_count = 0;
foreach ($records as $r) {
    $total_sent += $r['sent_qty'];
    $total_received += $r['received_qty'];
    if ($r['status'] === 'DISCREPANCY') $discrepancy_count++;
}
?>
<style>
body { font-size:14px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; padding:20px; background:#f8f9fa; }
.container { max-width:1400px; margin:0 auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,.1); }
h2 { text-align:center; border-bottom:2px solid #007bff; padding-bottom:10px; }
.summary-cards { display:flex; gap:16px; margin-bottom:20px; flex-wrap:wrap; }
.summary-card { flex:1; min-width:160px; background:#f8f9fa; border:1px solid #e9ecef; border-radius:8px; padding:16px; text-align:center; }
.summary-card .value { font-size:24px; font-weight:700; color:#007bff; }
.summary-card .label { font-size:12px; color:#6c757d; text-transform:uppercase; margin-top:4px; }
.summary-card.warn .value { color:#dc3545; }
.search-container { background:#f8f9fa; padding:16px; border-radius:8px; margin-bottom:20px; display:flex; gap:16px; flex-wrap:wrap; align-items:end; }
.search-group { display:flex; flex-direction:column; }
.search-group label { font-weight:600; font-size:12px; color:#495057; margin-bottom:5px; text-transform:uppercase; }
.search-control { padding:8px 12px; border:2px solid #e9ecef; border-radius:5px; font-size:14px; }
.btn { padding:9px 18px; border:none; border-radius:5px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; display:inline-block; }
.btn-primary { background:#007bff; color:#fff; }
.table-container { background:#fff; border-radius:8px; overflow-x:auto; box-shadow:0 2px 8px rgba(0,0,0,.1); }
.table { width:100%; border-collapse:collapse; font-size:13px; }
.table th, .table td { padding:10px; text-align:left; border-bottom:1px solid #dee2e6; }
.table th { background:#f8f9fa; font-size:11px; text-transform:uppercase; color:#495057; }
.badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; }
.badge-received    { background:#d4edda; color:#155724; }
.badge-discrepancy { background:#fff3cd; color:#856404; }
.badge-cancelled   { background:#f8d7da; color:#721c24; }
</style>

<div class="container">
<h2>📋 My Inward Report — <?= htmlspecialchars($current_user) ?></h2>

<div class="summary-cards">
    <div class="summary-card"><div class="value"><?= count($records) ?></div><div class="label">Total Entries</div></div>
    <div class="summary-card"><div class="value"><?= number_format($total_sent) ?></div><div class="label">Total Sent Qty</div></div>
    <div class="summary-card"><div class="value"><?= number_format($total_received) ?></div><div class="label">Total Received Qty</div></div>
    <div class="summary-card <?= $discrepancy_count ? 'warn' : '' ?>"><div class="value"><?= $discrepancy_count ?></div><div class="label">Discrepancies</div></div>
</div>

<div class="search-container">
<form method="get" style="display:flex; gap:16px; flex-wrap:wrap; align-items:end;">
    <div class="search-group">
        <label>Fiscal Year</label>
        <select name="fiscal_year_id" class="search-control" onchange="this.form.submit()">
            <option value="">All</option>
            <?php foreach ($fiscal_years_list as $fy): ?>
            <option value="<?= $fy['id'] ?>" <?= (string)$selected_fy_id === (string)$fy['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($fy['fiscal_name'] ?? $fy['fiscal_code']) ?><?= $fy['is_active'] ? ' (Active)' : '' ?>
            </option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="search-group">
        <label>Status</label>
        <select name="status" class="search-control" onchange="this.form.submit()">
            <option value="">All</option>
            <option value="RECEIVED" <?= $status_filter==='RECEIVED'?'selected':'' ?>>Received</option>
            <option value="DISCREPANCY" <?= $status_filter==='DISCREPANCY'?'selected':'' ?>>Discrepancy</option>
        </select>
    </div>
    <a href="create.php" class="btn btn-primary">➕ New Inward</a>
</form>
</div>

<div class="table-container">
<table class="table">
    <thead><tr>
        <th>Inward No</th><th>D2M No</th><th>Ref No</th><th>Book</th><th>Godam</th>
        <th>Sent</th><th>Received</th><th>Status</th><th>Sender</th><th>Received By (me)</th><th>Date</th><th>Remarks</th>
    </tr></thead>
    <tbody>
    <?php if (empty($records)): ?>
        <tr><td colspan="12" style="text-align:center;padding:30px;color:#888;">No inward entries recorded yet.</td></tr>
    <?php else: foreach ($records as $r): ?>
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
            <td><?= htmlspecialchars($r['received_by_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['inward_date_nep']) ?></td>
            <td><?= htmlspecialchars($r['remarks'] ?? '-') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

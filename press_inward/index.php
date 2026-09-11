<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$records_per_page = 50;
$page   = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$offset = ($page - 1) * $records_per_page;

$fiscal_years_list = $conn->query("SELECT id, fiscal_code, fiscal_name, is_active FROM fiscal_years ORDER BY start_date DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_fy_range = getActiveFiscalDateRange($conn);
$active_fy_id    = $active_fy_range['fiscal_year_id'] ?? '';
$selected_fy_id  = isset($_GET['fiscal_year_id']) ? $_GET['fiscal_year_id'] : $active_fy_id;

$godams   = $conn->query("SELECT id, godam_name FROM godam WHERE is_active = true ORDER BY godam_name")->fetchAll(PDO::FETCH_ASSOC);
$marketing_users = $conn->query("SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

$search_params = [
    'fiscal_year_id' => $selected_fy_id,
    'godam_id'       => $_GET['godam_id']   ?? '',
    'received_by'    => $_GET['received_by']?? '',
    'd2m_no'         => $_GET['d2m_no']     ?? '',
    'ref_no'         => $_GET['ref_no']     ?? '',
    'status'         => $_GET['status']     ?? '',
];

$where = "WHERE 1=1";
$params = [];
if (!empty($search_params['fiscal_year_id'])) { $where .= " AND fiscal_year_id = :fyid"; $params[':fyid'] = $search_params['fiscal_year_id']; }
if (!empty($search_params['godam_id']))       { $where .= " AND godam_id = :gid";       $params[':gid']  = $search_params['godam_id']; }
if (!empty($search_params['received_by']))    { $where .= " AND received_by = :rid";    $params[':rid']  = $search_params['received_by']; }
if (!empty($search_params['d2m_no']))         { $where .= " AND d2m_no ILIKE :d2mno";   $params[':d2mno']= '%' . $search_params['d2m_no'] . '%'; }
if (!empty($search_params['ref_no']))         { $where .= " AND item_deno_serial_number ILIKE :refno"; $params[':refno'] = '%' . $search_params['ref_no'] . '%'; }
if (!empty($search_params['status']))         { $where .= " AND status = :status";      $params[':status']= $search_params['status']; }

$count_stmt = $conn->prepare("SELECT COUNT(*) AS total FROM v_press_inward_full_details $where");
foreach ($params as $k => $v) $count_stmt->bindValue($k, $v);
$count_stmt->execute();
$total_records = $count_stmt->fetch(PDO::FETCH_ASSOC)['total'];
$total_pages = max(1, ceil($total_records / $records_per_page));

$stmt = $conn->prepare("
    SELECT * FROM v_press_inward_full_details
    $where
    ORDER BY created_at DESC
    LIMIT :limit OFFSET :offset
");
foreach ($params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

if (isset($_GET['export']) && $_GET['export'] === 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment;filename="press_inward_' . date('Y-m-d') . '.xls"');
    $export_stmt = $conn->prepare("SELECT * FROM v_press_inward_full_details $where ORDER BY created_at DESC");
    foreach ($params as $k => $v) $export_stmt->bindValue($k, $v);
    $export_stmt->execute();
    $export_records = $export_stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "<table border='1'><tr><th>Inward No</th><th>D2M No</th><th>Ref No</th><th>Book</th><th>Godam</th>
          <th>Sent</th><th>Received</th><th>Discrepancy</th><th>Status</th><th>Sender</th><th>Received By</th>
          <th>Nepali Date</th><th>Fiscal Year</th><th>Remarks</th><th>Created At</th></tr>";
    foreach ($export_records as $r) {
        echo "<tr>
            <td>" . htmlspecialchars($r['inward_no']) . "</td>
            <td>" . htmlspecialchars($r['d2m_no']) . "</td>
            <td>" . htmlspecialchars($r['item_deno_serial_number'] ?? '') . "</td>
            <td>" . htmlspecialchars($r['book_name']) . "</td>
            <td>" . htmlspecialchars($r['godam_name'] ?? '') . "</td>
            <td>" . number_format($r['sent_qty']) . "</td>
            <td>" . number_format($r['received_qty']) . "</td>
            <td>" . number_format($r['discrepancy_qty']) . "</td>
            <td>" . htmlspecialchars($r['status']) . "</td>
            <td>" . htmlspecialchars($r['sender_username'] ?? $r['d2m_sender_name'] ?? '') . "</td>
            <td>" . htmlspecialchars($r['received_by_name'] ?? '') . "</td>
            <td>" . htmlspecialchars($r['inward_date_nep']) . "</td>
            <td>" . htmlspecialchars($r['fiscal_name'] ?? '') . "</td>
            <td>" . htmlspecialchars($r['remarks'] ?? '') . "</td>
            <td>" . date('Y-m-d H:i', strtotime($r['created_at'])) . "</td>
        </tr>";
    }
    echo "</table>";
    exit;
}
?>
<style>
body { font-size:14px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; padding:20px; background:#f8f9fa; }
.container { max-width:1600px; margin:0 auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,.1); }
h2 { text-align:center; border-bottom:2px solid #007bff; padding-bottom:10px; }
.search-container { background:#f8f9fa; padding:20px; border-radius:8px; margin-bottom:20px; border:1px solid #e9ecef; }
.search-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(200px,1fr)); gap:16px; margin-bottom:16px; }
.search-group { display:flex; flex-direction:column; }
.search-group label { font-weight:600; color:#495057; margin-bottom:5px; font-size:12px; text-transform:uppercase; }
.search-control { padding:9px 12px; border:2px solid #e9ecef; border-radius:5px; font-size:14px; }
.btn { padding:9px 18px; border:none; border-radius:5px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; display:inline-block; margin-right:8px; }
.btn-primary { background:#007bff; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-success { background:#28a745; color:#fff; }
.action-buttons { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:10px; margin-bottom:16px; }
.table-container { background:#fff; border-radius:8px; overflow-x:auto; box-shadow:0 2px 8px rgba(0,0,0,.1); }
.table { width:100%; border-collapse:collapse; font-size:12px; min-width:1300px; }
.table th, .table td { padding:8px; text-align:left; border-bottom:1px solid #dee2e6; }
.table th { background:linear-gradient(135deg,#667eea 0%,#764ba2 100%); color:#fff; text-transform:uppercase; font-size:11px; }
.badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; }
.badge-received    { background:#d4edda; color:#155724; }
.badge-discrepancy { background:#fff3cd; color:#856404; }
.badge-cancelled   { background:#f8d7da; color:#721c24; }
.pagination-container { display:flex; justify-content:center; margin-top:20px; gap:6px; }
.page-link { padding:6px 10px; border:1px solid #dee2e6; border-radius:4px; text-decoration:none; color:#007bff; }
.page-item.active .page-link { background:#007bff; color:#fff; border-color:#007bff; }
</style>

<div class="container">
<h2>📥 Press Inward Records</h2>

<div class="search-container">
<form method="get">
    <input type="hidden" name="page" value="1">
    <div class="search-row">
        <div class="search-group">
            <label>Fiscal Year</label>
            <select name="fiscal_year_id" class="search-control" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($fiscal_years_list as $fy): ?>
                <option value="<?= $fy['id'] ?>" <?= (string)$search_params['fiscal_year_id'] === (string)$fy['id'] ? 'selected' : '' ?>>
                    <?= htmlspecialchars($fy['fiscal_name'] ?? $fy['fiscal_code']) ?><?= $fy['is_active'] ? ' (Active)' : '' ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="search-group">
            <label>Godam</label>
            <select name="godam_id" class="search-control" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($godams as $g): ?>
                <option value="<?= $g['id'] ?>" <?= (string)$search_params['godam_id'] === (string)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['godam_name']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="search-group">
            <label>Received By (Marketing)</label>
            <select name="received_by" class="search-control" onchange="this.form.submit()">
                <option value="">All</option>
                <?php foreach ($marketing_users as $u): ?>
                <option value="<?= $u['id'] ?>" <?= (string)$search_params['received_by'] === (string)$u['id'] ? 'selected' : '' ?>><?= htmlspecialchars($u['username']) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="search-group">
            <label>Status</label>
            <select name="status" class="search-control" onchange="this.form.submit()">
                <option value="">All</option>
                <option value="RECEIVED" <?= $search_params['status']==='RECEIVED'?'selected':'' ?>>Received</option>
                <option value="DISCREPANCY" <?= $search_params['status']==='DISCREPANCY'?'selected':'' ?>>Discrepancy</option>
                <option value="CANCELLED" <?= $search_params['status']==='CANCELLED'?'selected':'' ?>>Cancelled</option>
            </select>
        </div>
        <div class="search-group">
            <label>D2M No</label>
            <input type="text" name="d2m_no" class="search-control" value="<?= htmlspecialchars($search_params['d2m_no']) ?>">
        </div>
        <div class="search-group">
            <label>Ref No</label>
            <input type="text" name="ref_no" class="search-control" value="<?= htmlspecialchars($search_params['ref_no']) ?>">
        </div>
        <div class="search-group" style="align-self:end;">
            <button type="submit" class="btn btn-primary">🔍 Search</button>
            <a href="?" class="btn btn-secondary">Reset</a>
        </div>
    </div>
</form>
</div>

<div class="action-buttons">
    <a href="create.php" class="btn btn-primary">➕ New Inward</a>
    <a href="?export=excel&<?= http_build_query(array_merge($_GET, ['page' => null])) ?>" class="btn btn-success">📊 Export Excel</a>
    <div>Showing <?= count($records) ?> of <?= number_format($total_records) ?> (Page <?= $page ?> of <?= $total_pages ?>)</div>
</div>

<div class="table-container">
<table class="table">
    <thead><tr>
        <th>Inward No</th><th>D2M No</th><th>Ref No</th><th>Book</th><th>Godam</th>
        <th>Sent</th><th>Received</th><th>Disc.</th><th>Status</th><th>Sender</th><th>Received By</th><th>Date</th><th>Fiscal Year</th>
    </tr></thead>
    <tbody>
    <?php if (empty($records)): ?>
        <tr><td colspan="13" style="text-align:center;padding:30px;color:#888;">No records found.</td></tr>
    <?php else: foreach ($records as $r): ?>
        <tr>
            <td><strong><?= htmlspecialchars($r['inward_no']) ?></strong></td>
            <td><?= htmlspecialchars($r['d2m_no']) ?></td>
            <td><?= htmlspecialchars($r['item_deno_serial_number'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['book_name']) ?></td>
            <td><?= htmlspecialchars($r['godam_name'] ?? '-') ?></td>
            <td><?= number_format($r['sent_qty']) ?></td>
            <td><?= number_format($r['received_qty']) ?></td>
            <td><?= $r['discrepancy_qty'] != 0 ? ('<span style="color:#dc3545;font-weight:700;">' . number_format($r['discrepancy_qty']) . '</span>') : '0' ?></td>
            <td><span class="badge badge-<?= strtolower($r['status']) ?>"><?= htmlspecialchars($r['status']) ?></span></td>
            <td><?= htmlspecialchars($r['sender_username'] ?? $r['d2m_sender_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['received_by_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['inward_date_nep']) ?></td>
            <td><?= htmlspecialchars($r['fiscal_name'] ?? '-') ?></td>
        </tr>
    <?php endforeach; endif; ?>
    </tbody>
</table>
</div>

<?php if ($total_pages > 1): ?>
<div class="pagination-container">
    <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
        <div class="page-item <?= $i===$page?'active':'' ?>">
            <a class="page-link" href="?<?= http_build_query(array_merge($_GET, ['page' => $i])) ?>"><?= $i ?></a>
        </div>
    <?php endfor; ?>
</div>
<?php endif; ?>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
redirect_if_not_authorized(['admin', 'marketing', 'incharge', 'operator', 'supervisor']);

$success_message = '';
$error_message = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action'])) {
    $mi_id  = (int)($_POST['mi_id'] ?? 0);
    $action = $_POST['action'];
    $uid    = (int)$_SESSION['user_id'];

    try {
        if ($action === 'check' && (has_role(['incharge','operator','supervisor','admin']))) {
            $stmt = $conn->prepare("
                UPDATE marketing_inward SET status = 'CHECKED', checked_by = :uid, checked_at = NOW()
                WHERE id = :id AND status = 'DRAFT'
            ");
            $stmt->execute([':uid' => $uid, ':id' => $mi_id]);
            $success_message = "Marketing Inward marked as CHECKED.";

        } elseif ($action === 'verify' && has_role(['marketing','admin'])) {
            $verified_by = (int)($_POST['verified_by'] ?? 0);
            $stmt = $conn->prepare("
                UPDATE marketing_inward SET status = 'VERIFIED', verified_by = :vb, verified_at = NOW()
                WHERE id = :id AND status = 'CHECKED'
            ");
            $stmt->execute([':vb' => $verified_by, ':id' => $mi_id]);
            $success_message = "Marketing Inward marked as VERIFIED.";

        } elseif ($action === 'approve' && has_role(['marketing','admin'])) {
            $approved_by = (int)($_POST['approved_by'] ?? 0);

            $conn->beginTransaction();

            // The CHECK constraint (marketing_inward_approver_distinct_check) is the
            // real guard; this pre-check just gives a friendlier error message.
            $rowStmt = $conn->prepare("SELECT * FROM marketing_inward WHERE id = :id FOR UPDATE");
            $rowStmt->execute([':id' => $mi_id]);
            $row = $rowStmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) throw new Exception('Marketing Inward not found.');
            if ($approved_by === (int)$row['created_by'] || $approved_by === (int)$row['verified_by']) {
                throw new Exception('Approver must be a different user from both the creator and the verifier.');
            }

            $stmt = $conn->prepare("
                UPDATE marketing_inward SET status = 'APPROVED', approved_by = :ab, approved_at = NOW()
                WHERE id = :id AND status = 'VERIFIED'
            ");
            $stmt->execute([':ab' => $approved_by, ':id' => $mi_id]);

            if ($stmt->rowCount() > 0) {
                // Bump the goddam's cached running total.
                $conn->prepare("
                    UPDATE goddam SET total_qty = total_qty + :qty WHERE id = :gid
                ")->execute([':qty' => $row['total_marketing_qty'], ':gid' => $row['goddam_id']]);

                // Auto-approval cascade (plan §4a): if this was the last outstanding
                // line of the parent D2M, flip the D2M itself to APPROVED too.
                $remainStmt = $conn->prepare("
                    SELECT COUNT(*) FROM d2m_items di
                    WHERE di.d2m_id = :d2m_id
                      AND di.id NOT IN (
                            SELECT d2m_item_id FROM marketing_inward_details mid
                            JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
                            WHERE mi.status <> 'CANCELLED'
                          )
                ");
                $remainStmt->execute([':d2m_id' => $row['d2m_id']]);
                if ((int)$remainStmt->fetchColumn() === 0) {
                    $conn->prepare("
                        UPDATE d2m SET status = 'APPROVED', approved_by = :ab, approved_at = NOW()
                        WHERE id = :d2m_id AND status = 'VERIFIED'
                    ")->execute([':ab' => $approved_by, ':d2m_id' => $row['d2m_id']]);
                }
            }

            $conn->commit();
            $success_message = "Marketing Inward marked as APPROVED.";

        } elseif ($action === 'close' && has_role('admin')) {
            $stmt = $conn->prepare("
                UPDATE marketing_inward SET status = 'CLOSE' WHERE id = :id AND status = 'APPROVED'
            ");
            $stmt->execute([':id' => $mi_id]);
            $success_message = "Marketing Inward marked as CLOSED.";

        } elseif ($action === 'cancel' && has_role('admin')) {
            $stmt = $conn->prepare("
                UPDATE marketing_inward SET status = 'CANCELLED'
                WHERE id = :id AND status IN ('DRAFT','CHECKED','VERIFIED')
            ");
            $stmt->execute([':id' => $mi_id]);
            $success_message = "Marketing Inward CANCELLED.";
        }
    } catch (Exception $e) {
        if ($conn->inTransaction()) $conn->rollBack();
        $error_message = "Error: " . $e->getMessage();
    }
}

$marketing_users = $conn->query("SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);

$records_per_page = 50;
$page = max(1, (int)($_GET['page'] ?? 1));
$offset = ($page - 1) * $records_per_page;

$search_params = [
    'inward_no' => $_GET['inward_no'] ?? '',
    'status'    => $_GET['status'] ?? '',
    'goddam_id' => $_GET['goddam_id'] ?? '',
    'd2m_id'    => $_GET['d2m_id'] ?? '',
];

// D2M filter dropdown — only D2Ms that actually have a Marketing Inward
// against them (filtering by an unrelated D2M would just return nothing),
// with enough detail (no., type, date, item counts) to pick the right one.
$d2m_filter_options = $conn->query("
    SELECT DISTINCT d.id, d.d2m_no, d.d2m_type, d.nep_date, d.status,
           (SELECT COUNT(*) FROM marketing_inward mi2 WHERE mi2.d2m_id = d.id AND mi2.status <> 'CANCELLED') AS mi_count
    FROM d2m d
    JOIN marketing_inward mi ON mi.d2m_id = d.id
    ORDER BY d.nep_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

$conditions = "";
$bind_params = [];
if (!empty($search_params['inward_no'])) {
    $conditions .= " AND mi.inward_no LIKE :inward_no";
    $bind_params[':inward_no'] = '%' . $search_params['inward_no'] . '%';
}
if (!empty($search_params['status'])) {
    $conditions .= " AND mi.status = :status";
    $bind_params[':status'] = $search_params['status'];
}
if (!empty($search_params['goddam_id'])) {
    $conditions .= " AND mi.goddam_id = :goddam_id";
    $bind_params[':goddam_id'] = $search_params['goddam_id'];
}
if (!empty($search_params['d2m_id'])) {
    $conditions .= " AND mi.d2m_id = :d2m_id";
    $bind_params[':d2m_id'] = $search_params['d2m_id'];
}

$count_stmt = $conn->prepare("SELECT COUNT(*) FROM marketing_inward mi WHERE 1=1 {$conditions}");
foreach ($bind_params as $k => $v) $count_stmt->bindValue($k, $v);
$count_stmt->execute();
$total_records = (int)$count_stmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_records / $records_per_page));

$query = "
    SELECT mi.*, g.code AS goddam_code, g.name AS goddam_name, d.d2m_no,
           u_created.username AS created_by_name, u_checked.username AS checked_by_name,
           u_verified.username AS verified_by_name, u_approved.username AS approved_by_name
    FROM marketing_inward mi
    JOIN goddam g ON g.id = mi.goddam_id
    JOIN d2m d ON d.id = mi.d2m_id
    LEFT JOIN users u_created ON mi.created_by = u_created.id
    LEFT JOIN users u_checked ON mi.checked_by = u_checked.id
    LEFT JOIN users u_verified ON mi.verified_by = u_verified.id
    LEFT JOIN users u_approved ON mi.approved_by = u_approved.id
    WHERE 1=1 {$conditions}
    ORDER BY mi.nep_date DESC, mi.created_at DESC
    LIMIT :limit OFFSET :offset
";
$stmt = $conn->prepare($query);
foreach ($bind_params as $k => $v) $stmt->bindValue($k, $v);
$stmt->bindValue(':limit', $records_per_page, PDO::PARAM_INT);
$stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
$stmt->execute();
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$goddams = $conn->query("SELECT id, code, name FROM goddam ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
.container { max-width:1700px; margin:0 auto; background:#fff; padding:20px; border-radius:8px; box-shadow:0 2px 10px rgba(0,0,0,.1); }
.alert { padding:12px 16px; margin-bottom:16px; border-radius:6px; font-weight:600; }
.alert-success { background:#d4edda; color:#155724; }
.alert-danger { background:#f8d7da; color:#721c24; }
.search-container { background:#f8f9fa; padding:20px; border-radius:8px; margin-bottom:20px; }
.search-row { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:16px; }
.search-group label { font-weight:600; font-size:12px; text-transform:uppercase; }
.search-control { padding:9px 12px; border:2px solid #e9ecef; border-radius:5px; width:100%; }
.btn { padding:8px 16px; border:none; border-radius:5px; cursor:pointer; font-size:13px; font-weight:600; text-decoration:none; display:inline-block; margin:2px; }
.btn-primary { background:#007bff; color:#fff; } .btn-secondary { background:#6c757d; color:#fff; }
.btn-success { background:#28a745; color:#fff; } .btn-warning { background:#ffc107; color:#212529; }
.btn-danger { background:#dc3545; color:#fff; } .btn-info { background:#17a2b8; color:#fff; }
.btn-sm { padding:4px 9px; font-size:11px; }
.table-container { overflow-x:auto; }
table { width:100%; border-collapse:collapse; font-size:11px; min-width:1400px; }
th,td { padding:8px; border-bottom:1px solid #dee2e6; text-align:left; vertical-align:middle; }
th { background:linear-gradient(135deg,#16a34a 0%,#059669 100%); color:#fff; text-transform:uppercase; font-size:10px; }
.status-badge { padding:3px 8px; border-radius:4px; font-size:9px; font-weight:bold; text-transform:uppercase; }
.status-draft { background:#f8d7da; color:#721c24; } .status-checked { background:#fff3cd; color:#856404; }
.status-verified { background:#d4edda; color:#155724; } .status-approved { background:#cfe2ff; color:#084298; }
.status-cancelled { background:#d6d8db; color:#383d41; } .status-close { background:#d1ecf1; color:#0c5460; }
.mismatch-nonzero { color:#dc3545; font-weight:bold; }
.mismatch-zero { color:#198754; }
.status-actions { display:flex; flex-direction:column; gap:4px; }
.modal { display:none; position:fixed; inset:0; background:rgba(0,0,0,.5); z-index:1000; }
.modal.open { display:flex; align-items:center; justify-content:center; }
.modal-content { background:#fff; padding:26px; border-radius:8px; width:380px; }
.pagination-container { display:flex; justify-content:center; margin-top:20px; gap:6px; }
.page-link { padding:6px 11px; border:1px solid #dee2e6; border-radius:5px; text-decoration:none; color:#007bff; }
.page-item.active .page-link { background:#007bff; color:#fff; }
</style>

<div class="container">
  <h2 style="text-align:center;border-bottom:2px solid #16a34a;padding-bottom:10px;">📥 Marketing Inward Records</h2>

  <?php if ($success_message): ?><div class="alert alert-success">✓ <?= htmlspecialchars($success_message) ?></div><?php endif; ?>
  <?php if ($error_message): ?><div class="alert alert-danger">⚠ <?= htmlspecialchars($error_message) ?></div><?php endif; ?>

  <div style="margin-bottom:16px;">
    <?php if (has_role(['marketing','admin'])): ?>
      <a href="<?= getUrl('marketing_inward/create.php') ?>" class="btn btn-primary">+ Create Marketing Inward</a>
    <?php endif; ?>
  </div>

  <div class="search-container">
    <form method="get">
      <div class="search-row">
        <div class="search-group"><label>Inward No</label>
          <input type="text" name="inward_no" class="search-control" value="<?= htmlspecialchars($search_params['inward_no']) ?>">
        </div>
        <div class="search-group"><label>Status</label>
          <select name="status" class="search-control">
            <option value="">All</option>
            <?php foreach (['DRAFT','CHECKED','VERIFIED','APPROVED','CANCELLED','CLOSE'] as $s): ?>
              <option value="<?= $s ?>" <?= $search_params['status']===$s?'selected':'' ?>><?= $s ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="search-group"><label>Goddam</label>
          <select name="goddam_id" class="search-control">
            <option value="">All</option>
            <?php foreach ($goddams as $g): ?>
              <option value="<?= $g['id'] ?>" <?= (string)$search_params['goddam_id']===(string)$g['id']?'selected':'' ?>><?= htmlspecialchars($g['code']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="search-group"><label>D2M</label>
          <select name="d2m_id" class="search-control">
            <option value="">All</option>
            <?php foreach ($d2m_filter_options as $d): ?>
              <option value="<?= $d['id'] ?>" <?= (string)$search_params['d2m_id']===(string)$d['id']?'selected':'' ?>>
                <?= htmlspecialchars($d['d2m_no']) ?> — <?= htmlspecialchars($d['d2m_type']) ?> — <?= htmlspecialchars($d['nep_date']) ?> (<?= (int)$d['mi_count'] ?> inward<?= (int)$d['mi_count']!==1?'s':'' ?>)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="search-group" style="align-self:end;">
          <button type="submit" class="btn btn-primary">🔍 Search</button>
          <a href="?" class="btn btn-secondary">Reset</a>
        </div>
      </div>
    </form>
  </div>

  <p><strong><?= number_format($total_records) ?></strong> record(s), page <?= $page ?> of <?= $total_pages ?></p>

  <div class="table-container">
    <table>
      <thead>
        <tr>
          <th>Inward No</th><th>D2M No</th><th>Goddam</th><th>Date</th><th>Status</th>
          <th>Press Qty</th><th>Mkt Qty</th><th>Mismatch</th>
          <th>Created By</th><th>Checked By</th><th>Verified By</th><th>Approved By</th><th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($records)): ?>
          <tr><td colspan="13" style="text-align:center;padding:30px;color:#6c757d;">No records found.</td></tr>
        <?php else: foreach ($records as $r): ?>
          <tr>
            <td><strong><?= htmlspecialchars($r['inward_no']) ?></strong></td>
            <td><?= htmlspecialchars($r['d2m_no']) ?></td>
            <td><?= htmlspecialchars($r['goddam_code']) ?></td>
            <td><?= htmlspecialchars($r['nep_date']) ?></td>
            <td><span class="status-badge status-<?= strtolower($r['status']) ?>"><?= $r['status'] ?></span></td>
            <td><?= number_format($r['total_press_qty']) ?></td>
            <td><?= number_format($r['total_marketing_qty']) ?></td>
            <td class="<?= (int)$r['total_mismatch_qty'] !== 0 ? 'mismatch-nonzero' : 'mismatch-zero' ?>"><?= number_format($r['total_mismatch_qty']) ?></td>
            <td><?= htmlspecialchars($r['created_by_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['checked_by_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['verified_by_name'] ?? '-') ?></td>
            <td><?= htmlspecialchars($r['approved_by_name'] ?? '-') ?></td>
            <td>
              <div class="status-actions">
                <?php if ($r['status'] === 'DRAFT' && has_role(['incharge','operator','supervisor','admin'])): ?>
                  <form method="post"><input type="hidden" name="action" value="check"><input type="hidden" name="mi_id" value="<?= $r['id'] ?>">
                    <button class="btn btn-warning btn-sm" onclick="return confirm('Mark as CHECKED?')">Check</button></form>
                <?php endif; ?>

                <?php if ($r['status'] === 'CHECKED' && has_role(['marketing','admin'])): ?>
                  <button type="button" class="btn btn-success btn-sm" onclick="openVerify(<?= $r['id'] ?>)">Verify</button>
                <?php endif; ?>

                <?php if ($r['status'] === 'VERIFIED' && has_role(['marketing','admin'])): ?>
                  <button type="button" class="btn btn-primary btn-sm"
                    onclick="openApprove(<?= $r['id'] ?>, <?= (int)$r['created_by'] ?>, <?= (int)($r['verified_by'] ?? 0) ?>)">Approve</button>
                <?php endif; ?>

                <?php if ($r['status'] === 'APPROVED' && has_role('admin')): ?>
                  <form method="post"><input type="hidden" name="action" value="close"><input type="hidden" name="mi_id" value="<?= $r['id'] ?>">
                    <button class="btn btn-info btn-sm" onclick="return confirm('Close this record?')">Close</button></form>
                <?php endif; ?>

                <?php if (in_array($r['status'], ['DRAFT','CHECKED','VERIFIED'], true) && has_role('admin')): ?>
                  <form method="post"><input type="hidden" name="action" value="cancel"><input type="hidden" name="mi_id" value="<?= $r['id'] ?>">
                    <button class="btn btn-danger btn-sm" onclick="return confirm('Cancel this record?')">Cancel</button></form>
                <?php endif; ?>

                <a href="<?= getUrl('marketing_inward/view.php?id=' . $r['id']) ?>" class="btn btn-info btn-sm">View</a>
                <a href="<?= getUrl('marketing_inward/print.php?id=' . $r['id']) ?>" target="_blank" class="btn btn-primary btn-sm">Print</a>
              </div>
            </td>
          </tr>
        <?php endforeach; endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($total_pages > 1): ?>
  <div class="pagination-container">
    <?php for ($i = max(1,$page-2); $i <= min($total_pages,$page+2); $i++): ?>
      <span class="page-item <?= $i===$page?'active':'' ?>"><a class="page-link" href="?<?= http_build_query(array_merge($_GET,['page'=>$i])) ?>"><?= $i ?></a></span>
    <?php endfor; ?>
  </div>
  <?php endif; ?>
</div>

<!-- Verify Modal -->
<div id="verifyModal" class="modal">
  <div class="modal-content">
    <h3>Verify Marketing Inward</h3>
    <form method="post">
      <input type="hidden" name="action" value="verify">
      <input type="hidden" name="mi_id" id="verify_mi_id">
      <label style="font-weight:600;display:block;margin-bottom:8px;">Select Marketing User (may be the creator):</label>
      <select name="verified_by" class="search-control" style="width:100%;" required>
        <option value="">-- Select --</option>
        <?php foreach ($marketing_users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option><?php endforeach; ?>
      </select>
      <div style="margin-top:16px;text-align:right;">
        <button type="button" class="btn btn-secondary" onclick="closeModal('verifyModal')">Cancel</button>
        <button type="submit" class="btn btn-success">Verify</button>
      </div>
    </form>
  </div>
</div>

<!-- Approve Modal -->
<div id="approveModal" class="modal">
  <div class="modal-content">
    <h3>Approve Marketing Inward</h3>
    <p style="font-size:12px;color:#6c757d;">Must be a different user from both the creator and the verifier.</p>
    <form method="post">
      <input type="hidden" name="action" value="approve">
      <input type="hidden" name="mi_id" id="approve_mi_id">
      <select name="approved_by" id="approve_user_select" class="search-control" style="width:100%;" required>
        <option value="">-- Select --</option>
        <?php foreach ($marketing_users as $u): ?><option value="<?= $u['id'] ?>" data-uid="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option><?php endforeach; ?>
      </select>
      <div style="margin-top:16px;text-align:right;">
        <button type="button" class="btn btn-secondary" onclick="closeModal('approveModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Approve</button>
      </div>
    </form>
  </div>
</div>

<script>
function openVerify(id) { document.getElementById('verify_mi_id').value = id; document.getElementById('verifyModal').classList.add('open'); }
function openApprove(id, createdBy, verifiedBy) {
    document.getElementById('approve_mi_id').value = id;
    var sel = document.getElementById('approve_user_select');
    Array.from(sel.options).forEach(function(opt) {
        if (!opt.value) return;
        var uid = parseInt(opt.getAttribute('data-uid'), 10);
        opt.disabled = (uid === createdBy || uid === verifiedBy);
        opt.textContent = opt.disabled ? opt.textContent.replace(' (excluded)', '') + ' (excluded)' : opt.textContent.replace(' (excluded)', '');
    });
    document.getElementById('approveModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal').forEach(function(m) {
    m.addEventListener('click', function(e) { if (e.target === this) this.classList.remove('open'); });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

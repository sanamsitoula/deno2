<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
redirect_if_not_authorized('admin');

$errors  = [];
$success = '';

// ─────────────────────────────────────────────────────────────────────────────
// POST HANDLER
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_goddam') {
        $code    = trim($_POST['code'] ?? '');
        $name    = trim($_POST['name'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($code === '' || strlen($code) > 20) $errors[] = 'Code is required (max 20 characters).';
        if ($name === '' || strlen($name) > 100) $errors[] = 'Name is required (max 100 characters).';

        if (empty($errors)) {
            try {
                $stmt = $conn->prepare("
                    INSERT INTO goddam (code, name, remarks, created_by)
                    VALUES (:code, :name, :remarks, :uid)
                ");
                $stmt->execute([
                    ':code' => $code, ':name' => $name,
                    ':remarks' => $remarks !== '' ? $remarks : null,
                    ':uid' => $_SESSION['user_id'],
                ]);
                $success = "Goddam '{$name}' created successfully.";
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $errors[] = (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false)
                    ? "Code '{$code}' already exists." : "Database error: " . $msg;
            }
        }

    } elseif ($action === 'edit_goddam') {
        $id      = (int)($_POST['goddam_id'] ?? 0);
        $code    = trim($_POST['code'] ?? '');
        $name    = trim($_POST['name'] ?? '');
        $remarks = trim($_POST['remarks'] ?? '');

        if ($id <= 0) $errors[] = 'Invalid goddam ID.';
        if ($code === '' || strlen($code) > 20) $errors[] = 'Code is required (max 20 characters).';
        if ($name === '' || strlen($name) > 100) $errors[] = 'Name is required (max 100 characters).';

        if (empty($errors)) {
            try {
                $stmt = $conn->prepare("
                    UPDATE goddam
                       SET code = :code, name = :name, remarks = :remarks,
                           updated_by = :uid, updated_at = NOW()
                     WHERE id = :id
                ");
                $stmt->execute([
                    ':code' => $code, ':name' => $name,
                    ':remarks' => $remarks !== '' ? $remarks : null,
                    ':uid' => $_SESSION['user_id'], ':id' => $id,
                ]);
                $success = "Goddam updated successfully.";
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $errors[] = (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false)
                    ? "Code '{$code}' already exists." : "Database error: " . $msg;
            }
        }

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['goddam_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE goddam SET is_active = NOT is_active, updated_by = :uid, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':uid' => $_SESSION['user_id'], ':id' => $id]);
            $success = "Goddam status updated.";
        } else {
            $errors[] = 'Invalid goddam ID.';
        }
    }

    if ($success) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode($success));
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?err=' . urlencode(implode('||', $errors)));
    }
    exit;
}

if (!empty($_GET['msg'])) $success = htmlspecialchars(urldecode($_GET['msg']));
if (!empty($_GET['err'])) $errors  = array_map('htmlspecialchars', explode('||', urldecode($_GET['err'])));

$search = trim($_GET['search'] ?? '');
$where  = ($search !== '') ? "WHERE g.code ILIKE :search OR g.name ILIKE :search" : "";
$params = ($search !== '') ? [':search' => "%{$search}%"] : [];

$stmt = $conn->prepare("
    SELECT g.*, u.username AS created_by_name,
           (SELECT COUNT(*) FROM goddam_handlers gh WHERE gh.goddam_id = g.id AND gh.is_active) AS handler_count
    FROM goddam g
    LEFT JOIN users u ON g.created_by = u.id
    {$where}
    ORDER BY g.code
");
$stmt->execute($params);
$goddams = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
.gd-wrap        { max-width:1100px; margin:0 auto; padding:24px 16px; }
.gd-title       { font-size:1.55rem; font-weight:700; color:#1e2a3b; margin:0 0 4px; }
.gd-subtitle    { color:#6c757d; font-size:.9rem; margin:0 0 22px; }
.gd-card        { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.08); padding:28px 32px; margin-bottom:24px; }
.gd-card-title  { font-size:.95rem; font-weight:700; color:#374151; margin:0 0 18px; }
.gd-alert       { padding:12px 18px; border-radius:7px; margin-bottom:18px; font-size:.9rem; }
.gd-alert.ok    { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.gd-alert.err   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.form-grid      { display:grid; grid-template-columns:1fr 2fr 2fr auto; gap:14px; align-items:flex-end; }
.form-group     { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:.78rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; }
.form-group input,
.form-group select { padding:9px 12px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.93rem; outline:none; }
.form-group input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.12); }
.btn            { padding:9px 18px; border:none; border-radius:7px; font-size:.88rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px; }
.btn:hover      { opacity:.87; }
.btn-primary    { background:#6366f1; color:#fff; }
.btn-neutral    { background:#f1f5f9; color:#374151; }
.btn-icon       { background:transparent; border:none; cursor:pointer; padding:5px 7px; border-radius:6px; font-size:1rem; }
.btn-icon:hover { background:#f3f4f6; }
.search-bar     { display:flex; gap:8px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
.search-bar input { padding:9px 13px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.93rem; width:240px; }
.count-label    { margin-left:auto; font-size:.82rem; color:#9ca3af; }
.gd-table       { width:100%; border-collapse:collapse; font-size:.9rem; }
.gd-table thead th { background:#f8fafc; padding:10px 14px; text-align:left; font-size:.73rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; border-bottom:2px solid #e5e7eb; }
.gd-table tbody tr { border-bottom:1px solid #f1f3f5; }
.gd-table tbody tr:hover { background:#fafbff; }
.gd-table td    { padding:12px 14px; vertical-align:middle; color:#374151; }
.actions-cell   { text-align:center; white-space:nowrap; }
.badge          { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.73rem; font-weight:700; }
.badge-active   { background:#d1fae5; color:#065f46; }
.badge-inactive { background:#f1f5f9; color:#9ca3af; }
.modal-bd       { display:none; position:fixed; inset:0; background:rgba(15,20,35,.5); z-index:2000; align-items:center; justify-content:center; }
.modal-bd.open  { display:flex; }
.modal-box      { background:#fff; border-radius:14px; padding:32px 34px; width:500px; max-width:96vw; max-height:92vh; overflow-y:auto; position:relative; }
.modal-box h3   { margin:0 0 22px; font-size:1.15rem; color:#1e2a3b; }
.modal-close-btn { position:absolute; top:14px; right:16px; background:none; border:none; font-size:1.25rem; cursor:pointer; color:#9ca3af; }
.modal-form-grid{ display:grid; gap:16px; }
.modal-footer   { display:flex; justify-content:flex-end; gap:10px; margin-top:24px; }
@media(max-width:780px){ .form-grid { grid-template-columns:1fr 1fr; } }
@media(max-width:500px){ .form-grid { grid-template-columns:1fr; } .gd-card { padding:18px 14px; } .modal-box { padding:22px 16px; } }
</style>

<div class="gd-wrap">

  <h2 class="gd-title">🏬 Goddam (Store) Management</h2>
  <p class="gd-subtitle">Warehouses that Marketing Inward receipts are booked into. See also <a href="<?= getUrl('goddam/handlers.php') ?>">Handler Assignments</a>.</p>

  <?php if ($success): ?><div class="gd-alert ok">✓ <?= $success ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="gd-alert err">
      <?php if (count($errors) === 1): ?>⚠ <?= $errors[0] ?>
      <?php else: ?>⚠ Please fix the following errors:<ul><?php foreach ($errors as $err): echo "<li>{$err}</li>"; endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="gd-card">
    <div class="gd-card-title">＋ Add New Goddam</div>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="add_goddam">
      <div class="form-grid">
        <div class="form-group">
          <label for="add_code">Code</label>
          <input type="text" id="add_code" name="code" maxlength="20" required placeholder="e.g. GD-01">
        </div>
        <div class="form-group">
          <label for="add_name">Name</label>
          <input type="text" id="add_name" name="name" maxlength="100" required placeholder="e.g. Main Marketing Store">
        </div>
        <div class="form-group">
          <label for="add_remarks">Remarks</label>
          <input type="text" id="add_remarks" name="remarks" placeholder="Optional">
        </div>
        <div class="form-group">
          <button type="submit" class="btn btn-primary">Add Goddam</button>
        </div>
      </div>
    </form>
  </div>

  <div class="gd-card">
    <form method="get" class="search-bar" autocomplete="off">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by code or name…">
      <button type="submit" class="btn btn-primary" style="padding:9px 14px;">🔍 Search</button>
      <?php if ($search !== ''): ?><a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-neutral">✕ Clear</a><?php endif; ?>
      <span class="count-label"><?= count($goddams) ?> goddam<?= count($goddams) !== 1 ? 's' : '' ?></span>
    </form>

    <?php if (empty($goddams)): ?>
      <p style="text-align:center;color:#9ca3af;padding:40px 0;">No goddams found.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="gd-table">
      <thead>
        <tr>
          <th>Code</th><th>Name</th><th>Total Qty</th><th>Handlers</th><th>Status</th><th>Created By</th><th style="width:110px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($goddams as $g): ?>
        <tr>
          <td><strong><?= htmlspecialchars($g['code']) ?></strong></td>
          <td><?= htmlspecialchars($g['name']) ?></td>
          <td><?= number_format($g['total_qty']) ?></td>
          <td><a href="<?= getUrl('goddam/handlers.php?goddam_id=' . $g['id']) ?>"><?= (int)$g['handler_count'] ?> active</a></td>
          <td><span class="badge badge-<?= $g['is_active'] ? 'active' : 'inactive' ?>"><?= $g['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td style="font-size:.85rem;color:#6b7280;"><?= htmlspecialchars($g['created_by_name'] ?? '-') ?></td>
          <td class="actions-cell">
            <button type="button" class="btn-icon" title="Edit"
              data-id="<?= $g['id'] ?>"
              data-code="<?= htmlspecialchars($g['code'], ENT_QUOTES, 'UTF-8') ?>"
              data-name="<?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?>"
              data-remarks="<?= htmlspecialchars($g['remarks'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
              onclick="openEditModal(this)">✏️</button>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="goddam_id" value="<?= $g['id'] ?>">
              <button type="submit" class="btn-icon" title="<?= $g['is_active'] ? 'Deactivate' : 'Activate' ?>"
                onclick="return confirm('<?= $g['is_active'] ? 'Deactivate' : 'Activate' ?> this goddam?')">
                <?= $g['is_active'] ? '🚫' : '✅' ?>
              </button>
            </form>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
    <?php endif; ?>
  </div>
</div>

<div class="modal-bd" id="editModal">
  <div class="modal-box">
    <button class="modal-close-btn" onclick="closeModal('editModal')" type="button">✕</button>
    <h3>✏️ Edit Goddam</h3>
    <form method="post" id="editForm" autocomplete="off">
      <input type="hidden" name="action" value="edit_goddam">
      <input type="hidden" name="goddam_id" id="edit_goddam_id">
      <div class="modal-form-grid">
        <div class="form-group"><label for="edit_code">Code</label><input type="text" id="edit_code" name="code" maxlength="20" required></div>
        <div class="form-group"><label for="edit_name">Name</label><input type="text" id="edit_name" name="name" maxlength="100" required></div>
        <div class="form-group"><label for="edit_remarks">Remarks</label><input type="text" id="edit_remarks" name="remarks"></div>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-neutral" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(btn) {
    document.getElementById('edit_goddam_id').value = btn.getAttribute('data-id');
    document.getElementById('edit_code').value      = btn.getAttribute('data-code');
    document.getElementById('edit_name').value      = btn.getAttribute('data-name');
    document.getElementById('edit_remarks').value   = btn.getAttribute('data-remarks');
    document.getElementById('editModal').classList.add('open');
}
function closeModal(id) { document.getElementById(id).classList.remove('open'); }
document.querySelectorAll('.modal-bd').forEach(function(bd) {
    bd.addEventListener('click', function(e) { if (e.target === this) closeModal(this.id); });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') document.querySelectorAll('.modal-bd.open').forEach(function(m) { m.classList.remove('open'); });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

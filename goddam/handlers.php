<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
redirect_if_not_authorized('admin');

// Only admin ever reaches this page, so every field here — including
// active_from/active_to — is admin-controlled by construction; there is no
// marketing-facing version of this form.

$errors  = [];
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_handler' || $action === 'edit_handler') {
        $id              = (int)($_POST['handler_id'] ?? 0);
        $goddam_id       = (int)($_POST['goddam_id'] ?? 0);
        $user_id         = (int)($_POST['user_id'] ?? 0);
        $class_level     = (int)($_POST['class_level'] ?? 0);
        $book_type       = $_POST['book_type'] ?? '';
        $active_from_nep = trim($_POST['active_from_nep'] ?? '');
        $active_from_eng = trim($_POST['active_from_eng'] ?? '');
        $active_to_nep   = trim($_POST['active_to_nep'] ?? '');
        $active_to_eng   = trim($_POST['active_to_eng'] ?? '');

        if ($goddam_id <= 0) $errors[] = 'Goddam is required.';
        if ($user_id <= 0) $errors[] = 'User is required.';
        if ($class_level <= 0) $errors[] = 'Class is required.';
        if (!in_array($book_type, ['T', 'NT'], true)) $errors[] = 'Book type must be T or NT.';
        if (!preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $active_from_nep) || $active_from_eng === '') {
            $errors[] = 'Valid "Active From" date is required.';
        }
        // "Active To" is optional — blank means open-ended/still active
        if ($active_to_nep !== '' && (!preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $active_to_nep) || $active_to_eng === '')) {
            $errors[] = 'If "Active To" is set, it must be a valid date.';
        }
        if ($active_to_eng !== '' && $active_to_eng < $active_from_eng) {
            $errors[] = '"Active To" cannot be before "Active From".';
        }

        if (empty($errors)) {
            try {
                if ($action === 'add_handler') {
                    $stmt = $conn->prepare("
                        INSERT INTO goddam_handlers
                            (goddam_id, user_id, class_level, book_type,
                             active_from_nep, active_from_eng, active_to_nep, active_to_eng, created_by)
                        VALUES
                            (:goddam_id, :user_id, :class_level, :book_type,
                             :from_nep, :from_eng, :to_nep, :to_eng, :uid)
                    ");
                    $stmt->execute([
                        ':goddam_id' => $goddam_id, ':user_id' => $user_id,
                        ':class_level' => $class_level, ':book_type' => $book_type,
                        ':from_nep' => $active_from_nep, ':from_eng' => $active_from_eng,
                        ':to_nep' => $active_to_nep !== '' ? $active_to_nep : null,
                        ':to_eng' => $active_to_eng !== '' ? $active_to_eng : null,
                        ':uid' => $_SESSION['user_id'],
                    ]);
                    $success = "Handler assignment created.";
                } else {
                    if ($id <= 0) throw new Exception('Invalid handler ID.');
                    $stmt = $conn->prepare("
                        UPDATE goddam_handlers
                           SET goddam_id = :goddam_id, user_id = :user_id,
                               class_level = :class_level, book_type = :book_type,
                               active_from_nep = :from_nep, active_from_eng = :from_eng,
                               active_to_nep = :to_nep, active_to_eng = :to_eng,
                               updated_by = :uid, updated_at = NOW()
                         WHERE id = :id
                    ");
                    $stmt->execute([
                        ':goddam_id' => $goddam_id, ':user_id' => $user_id,
                        ':class_level' => $class_level, ':book_type' => $book_type,
                        ':from_nep' => $active_from_nep, ':from_eng' => $active_from_eng,
                        ':to_nep' => $active_to_nep !== '' ? $active_to_nep : null,
                        ':to_eng' => $active_to_eng !== '' ? $active_to_eng : null,
                        ':uid' => $_SESSION['user_id'], ':id' => $id,
                    ]);
                    $success = "Handler assignment updated.";
                }
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $errors[] = (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false)
                    ? "This user is already assigned that class/type at this goddam." : "Database error: " . $msg;
            } catch (Exception $e) {
                $errors[] = $e->getMessage();
            }
        }

    } elseif ($action === 'toggle_active') {
        $id = (int)($_POST['handler_id'] ?? 0);
        if ($id > 0) {
            $stmt = $conn->prepare("
                UPDATE goddam_handlers SET is_active = NOT is_active, updated_by = :uid, updated_at = NOW()
                WHERE id = :id
            ");
            $stmt->execute([':uid' => $_SESSION['user_id'], ':id' => $id]);
            $success = "Handler status updated.";
        } else {
            $errors[] = 'Invalid handler ID.';
        }
    }

    $redirect = $_SERVER['PHP_SELF'] . (isset($_GET['goddam_id']) ? '?goddam_id=' . (int)$_GET['goddam_id'] . '&' : '?');
    if ($success) {
        header('Location: ' . $redirect . 'msg=' . urlencode($success));
    } else {
        header('Location: ' . $redirect . 'err=' . urlencode(implode('||', $errors)));
    }
    exit;
}

if (!empty($_GET['msg'])) $success = htmlspecialchars(urldecode($_GET['msg']));
if (!empty($_GET['err'])) $errors  = array_map('htmlspecialchars', explode('||', urldecode($_GET['err'])));

$goddam_filter = (int)($_GET['goddam_id'] ?? 0);

$goddams = $conn->query("SELECT id, code, name FROM goddam WHERE is_active ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$marketing_users = $conn->query("SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$class_options = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL AND class_level > 0 ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);

$where = $goddam_filter > 0 ? "WHERE gh.goddam_id = :gid" : "";
$stmt = $conn->prepare("
    SELECT gh.*, g.code AS goddam_code, g.name AS goddam_name, u.username
    FROM goddam_handlers gh
    JOIN goddam g ON g.id = gh.goddam_id
    JOIN users u ON u.id = gh.user_id
    {$where}
    ORDER BY g.code, u.username, gh.class_level, gh.book_type
");
if ($goddam_filter > 0) $stmt->bindValue(':gid', $goddam_filter, PDO::PARAM_INT);
$stmt->execute();
$handlers = $stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
.gh-wrap        { max-width:1200px; margin:0 auto; padding:24px 16px; }
.gh-title       { font-size:1.55rem; font-weight:700; color:#1e2a3b; margin:0 0 4px; }
.gh-subtitle    { color:#6c757d; font-size:.9rem; margin:0 0 22px; }
.gh-card        { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.08); padding:28px 32px; margin-bottom:24px; }
.gh-card-title  { font-size:.95rem; font-weight:700; color:#374151; margin:0 0 18px; }
.gh-alert       { padding:12px 18px; border-radius:7px; margin-bottom:18px; font-size:.9rem; }
.gh-alert.ok    { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.gh-alert.err   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.form-grid      { display:grid; grid-template-columns:repeat(auto-fit,minmax(150px,1fr)); gap:14px; align-items:flex-end; }
.form-group     { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:.78rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; }
.form-group input, .form-group select { padding:9px 12px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.93rem; }
.dual-date .bs-date, .dual-date .ad-date { padding:9px 12px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.85rem; }
.btn            { padding:9px 18px; border:none; border-radius:7px; font-size:.88rem; font-weight:600; cursor:pointer; display:inline-flex; align-items:center; gap:5px; }
.btn:hover      { opacity:.87; }
.btn-primary    { background:#6366f1; color:#fff; }
.btn-neutral    { background:#f1f5f9; color:#374151; }
.btn-icon       { background:transparent; border:none; cursor:pointer; padding:5px 7px; border-radius:6px; font-size:1rem; }
.btn-icon:hover { background:#f3f4f6; }
.gh-table       { width:100%; border-collapse:collapse; font-size:.87rem; }
.gh-table thead th { background:#f8fafc; padding:9px 12px; text-align:left; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.05em; color:#6b7280; border-bottom:2px solid #e5e7eb; }
.gh-table tbody tr { border-bottom:1px solid #f1f3f5; }
.gh-table tbody tr:hover { background:#fafbff; }
.gh-table td    { padding:10px 12px; vertical-align:middle; color:#374151; }
.actions-cell   { text-align:center; white-space:nowrap; }
.badge          { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.73rem; font-weight:700; }
.badge-active   { background:#d1fae5; color:#065f46; }
.badge-inactive { background:#f1f5f9; color:#9ca3af; }
.badge-t        { background:#d4edda; color:#155724; }
.badge-nt       { background:#d1ecf1; color:#0c5460; }
.filter-bar     { margin-bottom:16px; }
.filter-bar select { padding:8px 12px; border:1.5px solid #d1d5db; border-radius:7px; }
.modal-bd       { display:none; position:fixed; inset:0; background:rgba(15,20,35,.5); z-index:2000; align-items:center; justify-content:center; }
.modal-bd.open  { display:flex; }
.modal-box      { background:#fff; border-radius:14px; padding:32px 34px; width:560px; max-width:96vw; max-height:92vh; overflow-y:auto; position:relative; }
.modal-box h3   { margin:0 0 22px; font-size:1.15rem; color:#1e2a3b; }
.modal-close-btn { position:absolute; top:14px; right:16px; background:none; border:none; font-size:1.25rem; cursor:pointer; color:#9ca3af; }
.modal-form-grid{ display:grid; gap:16px; grid-template-columns:1fr 1fr; }
.modal-footer   { display:flex; justify-content:flex-end; gap:10px; margin-top:24px; grid-column:1/-1; }
.field-note     { font-size:.75rem; color:#9ca3af; grid-column:1/-1; }
</style>

<div class="gh-wrap">

  <h2 class="gh-title">🔑 Goddam Handler Assignments</h2>
  <p class="gh-subtitle">
    Which marketing user may perform Marketing Inward for which book class + type, at which goddam,
    and between which dates. Only admin can set these dates. Blank "Active To" = still active.
  </p>

  <?php if ($success): ?><div class="gh-alert ok">✓ <?= $success ?></div><?php endif; ?>
  <?php if (!empty($errors)): ?>
    <div class="gh-alert err">
      <?php if (count($errors) === 1): ?>⚠ <?= $errors[0] ?>
      <?php else: ?>⚠ Please fix the following errors:<ul><?php foreach ($errors as $err): echo "<li>{$err}</li>"; endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="gh-card">
    <div class="gh-card-title">＋ Add Handler Assignment</div>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="add_handler">
      <div class="form-grid">
        <div class="form-group">
          <label for="add_goddam_id">Goddam</label>
          <select id="add_goddam_id" name="goddam_id" required>
            <option value="">-- Select --</option>
            <?php foreach ($goddams as $g): ?>
              <option value="<?= $g['id'] ?>" <?= $goddam_filter === (int)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['code'] . ' - ' . $g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="add_user_id">Marketing User</label>
          <select id="add_user_id" name="user_id" required>
            <option value="">-- Select --</option>
            <?php foreach ($marketing_users as $u): ?>
              <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="add_class_level">Class</label>
          <select id="add_class_level" name="class_level" required>
            <option value="">-- Select --</option>
            <?php foreach ($class_options as $c): ?>
              <option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="add_book_type">Book Type</label>
          <select id="add_book_type" name="book_type" required>
            <option value="">-- Select --</option>
            <option value="T">Translated (T)</option>
            <option value="NT">Non-Translated (NT)</option>
          </select>
        </div>
        <div class="form-group">
          <label>Active From</label>
          <div class="dual-date">
            <input class="bs-date" name="active_from_nep" placeholder="2082.01.01" data-ad-pair="add_from_eng" required>
            <input class="ad-date" name="active_from_eng" id="add_from_eng" type="date" data-bs-pair="">
          </div>
        </div>
        <div class="form-group">
          <label>Active To <span style="text-transform:none;font-weight:400;">(blank = open-ended)</span></label>
          <div class="dual-date">
            <input class="bs-date" name="active_to_nep" placeholder="optional" data-ad-pair="add_to_eng">
            <input class="ad-date" name="active_to_eng" id="add_to_eng" type="date">
          </div>
        </div>
        <div class="form-group">
          <button type="submit" class="btn btn-primary">Add Assignment</button>
        </div>
      </div>
    </form>
  </div>

  <div class="gh-card">
    <form method="get" class="filter-bar">
      <label for="filter_goddam" style="font-weight:600;font-size:.85rem;">Filter by Goddam:</label>
      <select id="filter_goddam" name="goddam_id" onchange="this.form.submit()">
        <option value="">All Goddams</option>
        <?php foreach ($goddams as $g): ?>
          <option value="<?= $g['id'] ?>" <?= $goddam_filter === (int)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['code'] . ' - ' . $g['name']) ?></option>
        <?php endforeach; ?>
      </select>
    </form>

    <?php if (empty($handlers)): ?>
      <p style="text-align:center;color:#9ca3af;padding:40px 0;">No handler assignments found.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="gh-table">
      <thead>
        <tr>
          <th>Goddam</th><th>User</th><th>Class</th><th>Type</th><th>Active From</th><th>Active To</th><th>Status</th><th style="width:110px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($handlers as $h): ?>
        <tr>
          <td><?= htmlspecialchars($h['goddam_code']) ?></td>
          <td><?= htmlspecialchars($h['username']) ?></td>
          <td><?= htmlspecialchars($h['class_level']) ?></td>
          <td><span class="badge badge-<?= strtolower($h['book_type']) ?>"><?= htmlspecialchars($h['book_type']) ?></span></td>
          <td><?= htmlspecialchars($h['active_from_nep']) ?></td>
          <td><?= $h['active_to_nep'] ? htmlspecialchars($h['active_to_nep']) : '<span style="color:#9ca3af;">open-ended</span>' ?></td>
          <td><span class="badge badge-<?= $h['is_active'] ? 'active' : 'inactive' ?>"><?= $h['is_active'] ? 'Active' : 'Inactive' ?></span></td>
          <td class="actions-cell">
            <button type="button" class="btn-icon" title="Edit"
              data-id="<?= $h['id'] ?>" data-goddam="<?= $h['goddam_id'] ?>" data-user="<?= $h['user_id'] ?>"
              data-class="<?= $h['class_level'] ?>" data-type="<?= $h['book_type'] ?>"
              data-from-nep="<?= htmlspecialchars($h['active_from_nep'], ENT_QUOTES) ?>"
              data-from-eng="<?= htmlspecialchars($h['active_from_eng'], ENT_QUOTES) ?>"
              data-to-nep="<?= htmlspecialchars($h['active_to_nep'] ?? '', ENT_QUOTES) ?>"
              data-to-eng="<?= htmlspecialchars($h['active_to_eng'] ?? '', ENT_QUOTES) ?>"
              onclick="openEditModal(this)">✏️</button>
            <form method="post" style="display:inline;">
              <input type="hidden" name="action" value="toggle_active">
              <input type="hidden" name="handler_id" value="<?= $h['id'] ?>">
              <button type="submit" class="btn-icon" title="<?= $h['is_active'] ? 'Deactivate' : 'Activate' ?>"
                onclick="return confirm('<?= $h['is_active'] ? 'Deactivate' : 'Activate' ?> this assignment?')">
                <?= $h['is_active'] ? '🚫' : '✅' ?>
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
    <h3>✏️ Edit Handler Assignment</h3>
    <form method="post" id="editForm" autocomplete="off">
      <input type="hidden" name="action" value="edit_handler">
      <input type="hidden" name="handler_id" id="edit_handler_id">
      <div class="modal-form-grid">
        <div class="form-group">
          <label for="edit_goddam_id">Goddam</label>
          <select id="edit_goddam_id" name="goddam_id" required>
            <?php foreach ($goddams as $g): ?><option value="<?= $g['id'] ?>"><?= htmlspecialchars($g['code'] . ' - ' . $g['name']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="edit_user_id">Marketing User</label>
          <select id="edit_user_id" name="user_id" required>
            <?php foreach ($marketing_users as $u): ?><option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="edit_class_level">Class</label>
          <select id="edit_class_level" name="class_level" required>
            <?php foreach ($class_options as $c): ?><option value="<?= htmlspecialchars($c) ?>"><?= htmlspecialchars($c) ?></option><?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="edit_book_type">Book Type</label>
          <select id="edit_book_type" name="book_type" required>
            <option value="T">Translated (T)</option>
            <option value="NT">Non-Translated (NT)</option>
          </select>
        </div>
        <div class="form-group">
          <label>Active From</label>
          <div class="dual-date">
            <input class="bs-date" name="active_from_nep" id="edit_from_nep" data-ad-pair="edit_from_eng" required>
            <input class="ad-date" name="active_from_eng" id="edit_from_eng" type="date">
          </div>
        </div>
        <div class="form-group">
          <label>Active To</label>
          <div class="dual-date">
            <input class="bs-date" name="active_to_nep" id="edit_to_nep" data-ad-pair="edit_to_eng">
            <input class="ad-date" name="active_to_eng" id="edit_to_eng" type="date">
          </div>
        </div>
        <div class="field-note">Leave "Active To" blank to keep this assignment open-ended.</div>
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
    document.getElementById('edit_handler_id').value = btn.getAttribute('data-id');
    document.getElementById('edit_goddam_id').value   = btn.getAttribute('data-goddam');
    document.getElementById('edit_user_id').value     = btn.getAttribute('data-user');
    document.getElementById('edit_class_level').value = btn.getAttribute('data-class');
    document.getElementById('edit_book_type').value    = btn.getAttribute('data-type');
    document.getElementById('edit_from_nep').value     = btn.getAttribute('data-from-nep');
    document.getElementById('edit_from_eng').value     = btn.getAttribute('data-from-eng');
    document.getElementById('edit_to_nep').value       = btn.getAttribute('data-to-nep');
    document.getElementById('edit_to_eng').value       = btn.getAttribute('data-to-eng');
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

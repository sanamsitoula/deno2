<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
redirect_if_not_authorized('admin');

$errors  = [];
$success = '';

// ─────────────────────────────────────────────────────────────────────────────
// HELPERS
// ─────────────────────────────────────────────────────────────────────────────
$ALLOWED_ROLES = ['admin', 'editor', 'marketing', 'operator', 'viewer'];

// ─────────────────────────────────────────────────────────────────────────────
// POST HANDLER
// ─────────────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    // ── ADD USER ─────────────────────────────────────────────────────────────
    if ($action === 'add_user') {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';
        $role     = $_POST['role'] ?? 'viewer';

        if ($username === '') {
            $errors[] = 'Username is required.';
        } elseif (strlen($username) > 50) {
            $errors[] = 'Username must be 50 characters or fewer.';
        }
        if (strlen($password) < 6) {
            $errors[] = 'Password must be at least 6 characters.';
        }
        if (!in_array($role, $ALLOWED_ROLES, true)) {
            $errors[] = 'Invalid role selected.';
        }

        if (empty($errors)) {
            try {
                $hash = hash_password($password);
                // Cast role explicitly for PostgreSQL enum
                $stmt = $conn->prepare(
                    "INSERT INTO users (username, password_hash, role)
                     VALUES (:u, :h, :r::user_role)"
                );
                $stmt->execute([':u' => $username, ':h' => $hash, ':r' => $role]);
                $success = "User '{$username}' created successfully.";
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false) {
                    $errors[] = "Username already exists.";
                } else {
                    $errors[] = "Database error: " . $msg;
                }
            }
        }

    // ── EDIT USER ────────────────────────────────────────────────────────────
    } elseif ($action === 'edit_user') {
        $id       = (int)($_POST['user_id'] ?? 0);
        $username = trim($_POST['username'] ?? '');
        $role     = $_POST['role'] ?? '';
        // NOTE: field name is 'new_password' to avoid browser autofill stomping values
        $password = $_POST['new_password'] ?? '';

        if ($id <= 0)                                   $errors[] = 'Invalid user ID.';
        if ($username === '' || strlen($username) > 50) $errors[] = 'Valid username required (max 50 chars).';
        if (!in_array($role, $ALLOWED_ROLES, true))     $errors[] = 'Invalid role.';
        if ($password !== '' && strlen($password) < 6)  $errors[] = 'New password must be at least 6 characters.';

        if (empty($errors)) {
            try {
                if ($password !== '') {
                    // Update username + role + password
                    $hash = hash_password($password);
                    $stmt = $conn->prepare(
                        "UPDATE users
                            SET username      = :u,
                                role          = :r::user_role,
                                password_hash = :h
                          WHERE id = :id"
                    );
                    $stmt->execute([':u' => $username, ':r' => $role, ':h' => $hash, ':id' => $id]);
                } else {
                    // Update username + role only — leave password untouched
                    $stmt = $conn->prepare(
                        "UPDATE users
                            SET username = :u,
                                role     = :r::user_role
                          WHERE id = :id"
                    );
                    $stmt->execute([':u' => $username, ':r' => $role, ':id' => $id]);
                }
                $success = "User updated successfully.";
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                if (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false) {
                    $errors[] = "Username already exists.";
                } else {
                    $errors[] = "Database error: " . $msg;
                }
            }
        }

    // ── DELETE USER ──────────────────────────────────────────────────────────
    } elseif ($action === 'delete_user') {
        $id = (int)($_POST['user_id'] ?? 0);

        if ($id === (int)($_SESSION['user_id'] ?? 0)) {
            $errors[] = 'You cannot delete your own account.';
        } elseif ($id <= 0) {
            $errors[] = 'Invalid user ID.';
        } else {
            try {
                $stmt = $conn->prepare("DELETE FROM users WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $success = "User deleted successfully.";
            } catch (PDOException $e) {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }

    // PRG – redirect to avoid re-submission on refresh
    if ($success) {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?msg=' . urlencode($success));
    } else {
        header('Location: ' . $_SERVER['PHP_SELF'] . '?err=' . urlencode(implode('||', $errors)));
    }
    exit;
}

// ─────────────────────────────────────────────────────────────────────────────
// FLASH MESSAGES (from PRG redirect)
// ─────────────────────────────────────────────────────────────────────────────
if (!empty($_GET['msg'])) {
    $success = htmlspecialchars(urldecode($_GET['msg']));
}
if (!empty($_GET['err'])) {
    $errors = array_map('htmlspecialchars', explode('||', urldecode($_GET['err'])));
}

// ─────────────────────────────────────────────────────────────────────────────
// PAGINATION + FETCH
// ─────────────────────────────────────────────────────────────────────────────
$per_page = 10;
$page     = max(1, (int)($_GET['page'] ?? 1));
$search   = trim($_GET['search'] ?? '');
$offset   = ($page - 1) * $per_page;

$where  = ($search !== '') ? "WHERE username ILIKE :search" : "";
$params = ($search !== '') ? [':search' => "%{$search}%"] : [];

$cnt_stmt = $conn->prepare("SELECT COUNT(*) FROM users {$where}");
$cnt_stmt->execute($params);
$total_users = (int)$cnt_stmt->fetchColumn();
$total_pages = (int)ceil($total_users / $per_page);
if ($page > $total_pages && $total_pages > 0) $page = $total_pages;

$list_stmt = $conn->prepare(
    "SELECT id, username, role, created_at, last_login
       FROM users {$where}
      ORDER BY username
      LIMIT :lim OFFSET :off"
);
foreach ($params as $k => $v) $list_stmt->bindValue($k, $v);
$list_stmt->bindValue(':lim', $per_page, PDO::PARAM_INT);
$list_stmt->bindValue(':off', $offset,   PDO::PARAM_INT);
$list_stmt->execute();
$users = $list_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
/* ── Wrapper ── */
.um-wrap        { max-width:1100px; margin:0 auto; padding:24px 16px; }
.um-title       { font-size:1.55rem; font-weight:700; color:#1e2a3b; margin:0 0 4px; }
.um-subtitle    { color:#6c757d; font-size:.9rem; margin:0 0 22px; }

/* ── Cards ── */
.um-card        { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.08); padding:28px 32px; margin-bottom:24px; }
.um-card-title  { font-size:.95rem; font-weight:700; color:#374151; margin:0 0 18px; }

/* ── Alerts ── */
.um-alert       { padding:12px 18px; border-radius:7px; margin-bottom:18px; font-size:.9rem; }
.um-alert.ok    { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.um-alert.err   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.um-alert ul    { margin:4px 0 0 18px; padding:0; }

/* ── Form grid ── */
.form-grid      { display:grid; grid-template-columns:1fr 1fr 1fr auto; gap:14px; align-items:flex-end; }
.form-group     { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:.78rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; }
.form-group input,
.form-group select { padding:9px 12px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.93rem; outline:none; background:#fff; transition:border-color .18s, box-shadow .18s; }
.form-group input:focus,
.form-group select:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.12); }

/* ── Buttons ── */
.btn            { padding:9px 18px; border:none; border-radius:7px; font-size:.88rem; font-weight:600; cursor:pointer; transition:opacity .15s, transform .1s; display:inline-flex; align-items:center; gap:5px; }
.btn:hover      { opacity:.87; transform:translateY(-1px); }
.btn:active     { transform:none; opacity:1; }
.btn-primary    { background:#6366f1; color:#fff; }
.btn-danger     { background:#ef4444; color:#fff; }
.btn-neutral    { background:#f1f5f9; color:#374151; }
.btn-icon       { background:transparent; border:none; cursor:pointer; padding:5px 7px; border-radius:6px; font-size:1rem; line-height:1; transition:background .15s; }
.btn-icon:hover { background:#f3f4f6; }

/* ── Search ── */
.search-bar     { display:flex; gap:8px; align-items:center; margin-bottom:16px; flex-wrap:wrap; }
.search-bar input { padding:9px 13px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.93rem; width:240px; outline:none; }
.search-bar input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.12); }
.count-label    { margin-left:auto; font-size:.82rem; color:#9ca3af; }

/* ── Table ── */
.um-table       { width:100%; border-collapse:collapse; font-size:.9rem; }
.um-table thead th {
    background:#f8fafc; padding:10px 14px; text-align:left;
    font-size:.73rem; font-weight:700; text-transform:uppercase;
    letter-spacing:.05em; color:#6b7280; border-bottom:2px solid #e5e7eb;
}
.um-table tbody tr  { border-bottom:1px solid #f1f3f5; transition:background .1s; }
.um-table tbody tr:hover { background:#fafbff; }
.um-table td    { padding:12px 14px; vertical-align:middle; color:#374151; }
.actions-cell   { text-align:center; white-space:nowrap; }

/* ── Role badges ── */
.badge          { display:inline-block; padding:3px 10px; border-radius:20px; font-size:.73rem; font-weight:700; text-transform:capitalize; }
.badge-admin    { background:#ede9fe; color:#5b21b6; }
.badge-editor   { background:#dbeafe; color:#1d4ed8; }
.badge-marketing{ background:#fce7f3; color:#9d174d; }
.badge-operator { background:#d1fae5; color:#065f46; }
.badge-viewer   { background:#f1f5f9; color:#475569; }

/* ── Pagination ── */
.pagination     { display:flex; align-items:center; justify-content:space-between; margin-top:18px; flex-wrap:wrap; gap:10px; }
.pagination .info { font-size:.82rem; color:#9ca3af; }
.page-links     { display:flex; gap:4px; flex-wrap:wrap; }
.page-links a,
.page-links span {
    display:inline-block; padding:6px 12px; border-radius:6px;
    border:1.5px solid #e5e7eb; font-size:.82rem; text-decoration:none; color:#374151;
    transition:all .15s;
}
.page-links a:hover  { background:#6366f1; color:#fff; border-color:#6366f1; }
.page-links .active  { background:#6366f1; color:#fff; border-color:#6366f1; font-weight:700; pointer-events:none; }
.page-links .disabled{ color:#d1d5db; pointer-events:none; }

/* ── Modal backdrop ── */
.modal-bd       {
    display:none; position:fixed; inset:0;
    background:rgba(15,20,35,.5); backdrop-filter:blur(2px);
    z-index:2000; align-items:center; justify-content:center;
}
.modal-bd.open  { display:flex; }

/* ── Modal box ── */
.modal-box      {
    background:#fff; border-radius:14px; padding:32px 34px;
    width:500px; max-width:96vw; max-height:92vh; overflow-y:auto;
    box-shadow:0 24px 80px rgba(0,0,0,.22);
    animation:modalIn .18s ease;
    position:relative;
}
@keyframes modalIn { from{transform:translateY(20px) scale(.97);opacity:0} to{transform:none;opacity:1} }
.modal-box h3   { margin:0 0 22px; font-size:1.15rem; color:#1e2a3b; }
.modal-close-btn {
    position:absolute; top:14px; right:16px;
    background:none; border:none; font-size:1.25rem; cursor:pointer;
    color:#9ca3af; line-height:1; padding:4px 6px; border-radius:5px;
    transition:background .15s, color .15s;
}
.modal-close-btn:hover { background:#f3f4f6; color:#374151; }
.modal-form-grid{ display:grid; gap:16px; }
.modal-footer   { display:flex; justify-content:flex-end; gap:10px; margin-top:24px; }
.field-hint     { font-size:.76rem; color:#9ca3af; margin-top:3px; min-height:14px; }
.pw-section-title {
    font-size:.78rem; font-weight:700; color:#374151;
    text-transform:uppercase; letter-spacing:.04em;
    margin-bottom:10px; padding-top:4px;
}
.pw-section-sub { font-size:.75rem; color:#9ca3af; font-weight:400; text-transform:none; margin-left:6px; }
hr.divider      { border:none; border-top:1px dashed #e5e7eb; margin:6px 0; }

@media(max-width:780px){
    .form-grid  { grid-template-columns:1fr 1fr; }
}
@media(max-width:500px){
    .form-grid  { grid-template-columns:1fr; }
    .um-card    { padding:18px 14px; }
    .modal-box  { padding:22px 16px; }
}
</style>

<div class="um-wrap">

  <h2 class="um-title">👤 User Management</h2>
  <p class="um-subtitle">Create, edit, reset passwords, and manage system users.</p>

  <?php if ($success): ?>
    <div class="um-alert ok">✓ <?= $success ?></div>
  <?php endif; ?>

  <?php if (!empty($errors)): ?>
    <div class="um-alert err">
      <?php if (count($errors) === 1): ?>
        ⚠ <?= $errors[0] ?>
      <?php else: ?>
        ⚠ Please fix the following errors:
        <ul><?php foreach ($errors as $err): echo "<li>{$err}</li>"; endforeach; ?></ul>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- ═══════════════════════════════════════════
       ADD USER FORM
  ════════════════════════════════════════════ -->
  <div class="um-card">
    <div class="um-card-title">＋ Add New User</div>
    <form method="post" autocomplete="off">
      <input type="hidden" name="action" value="add_user">
      <div class="form-grid">
        <div class="form-group">
          <label for="add_username">Username</label>
          <input type="text" id="add_username" name="username" maxlength="50" required placeholder="e.g. john_doe">
        </div>
        <div class="form-group">
          <label for="add_password">Password</label>
          <input type="password" id="add_password" name="password" required placeholder="Min. 6 characters" autocomplete="new-password">
        </div>
        <div class="form-group">
          <label for="add_role">Role</label>
          <select id="add_role" name="role" required>
            <option value="viewer" selected>Viewer</option>
            <option value="editor">Editor</option>
            <option value="marketing">Marketing</option>
            <option value="operator">Operator</option>
            <option value="admin">Admin</option>
          </select>
        </div>
        <div class="form-group">
          <button type="submit" class="btn btn-primary">Add User</button>
        </div>
      </div>
    </form>
  </div>

  <!-- ═══════════════════════════════════════════
       USERS TABLE
  ════════════════════════════════════════════ -->
  <div class="um-card">

    <form method="get" class="search-bar" autocomplete="off">
      <input type="text" name="search" value="<?= htmlspecialchars($search) ?>" placeholder="Search by username…">
      <button type="submit" class="btn btn-primary" style="padding:9px 14px;">🔍 Search</button>
      <?php if ($search !== ''): ?>
        <a href="<?= htmlspecialchars($_SERVER['PHP_SELF']) ?>" class="btn btn-neutral">✕ Clear</a>
      <?php endif; ?>
      <span class="count-label">
        <?= $total_users ?> user<?= $total_users !== 1 ? 's' : '' ?>
        <?php if ($search !== ''): ?> matching "<em><?= htmlspecialchars($search) ?></em>"<?php endif; ?>
      </span>
    </form>

    <?php if (empty($users)): ?>
      <p style="text-align:center;color:#9ca3af;padding:40px 0;font-size:.93rem;">No users found.</p>
    <?php else: ?>

    <div style="overflow-x:auto;">
    <table class="um-table">
      <thead>
        <tr>
          <th style="width:50px;">ID</th>
          <th>Username</th>
          <th>Role</th>
          <th>Created</th>
          <th>Last Login</th>
          <th style="width:100px;">Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($users as $u):
            $uid     = (int)$u['id'];
            $uname   = $u['username'];
            $urole   = $u['role'];
            $is_self = ($uid === (int)($_SESSION['user_id'] ?? 0));
        ?>
        <tr>
          <td style="color:#adb5bd;font-size:.82rem;"><?= $uid ?></td>
          <td>
            <strong><?= htmlspecialchars($uname) ?></strong>
            <?php if ($is_self): ?>
              <span style="font-size:.72rem;color:#6366f1;font-weight:600;margin-left:5px;">(you)</span>
            <?php endif; ?>
          </td>
          <td><span class="badge badge-<?= htmlspecialchars($urole) ?>"><?= htmlspecialchars($urole) ?></span></td>
          <td style="font-size:.85rem;color:#6b7280;"><?= date('d M Y, H:i', strtotime($u['created_at'])) ?></td>
          <td style="font-size:.85rem;color:<?= $u['last_login'] ? '#6b7280' : '#d1d5db' ?>;">
            <?= $u['last_login'] ? date('d M Y, H:i', strtotime($u['last_login'])) : '— never' ?>
          </td>
          <td class="actions-cell">
            <!--
              IMPORTANT: values go in data-* attributes, NOT inline onclick() strings.
              This completely avoids HTML/JS quote-escaping bugs.
            -->
            <button
              type="button"
              class="btn-icon"
              title="Edit user"
              data-uid="<?= $uid ?>"
              data-uname="<?= htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') ?>"
              data-urole="<?= htmlspecialchars($urole, ENT_QUOTES, 'UTF-8') ?>"
              onclick="openEditModal(this)">✏️</button>

            <?php if (!$is_self): ?>
            <button
              type="button"
              class="btn-icon"
              title="Delete user"
              data-uid="<?= $uid ?>"
              data-uname="<?= htmlspecialchars($uname, ENT_QUOTES, 'UTF-8') ?>"
              onclick="openDeleteModal(this)">🗑️</button>
            <?php else: ?>
            <button type="button" class="btn-icon" style="opacity:.25;cursor:not-allowed;" title="Cannot delete your own account" disabled>🗑️</button>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>

    <!-- Pagination -->
    <?php if ($total_pages > 1):
        $qs_base = '?' . ($search !== '' ? 'search=' . urlencode($search) . '&' : '') . 'page=%d';
    ?>
    <div class="pagination">
      <span class="info">
        Showing <?= number_format($offset + 1) ?>–<?= number_format(min($offset + $per_page, $total_users)) ?>
        of <?= number_format($total_users) ?>
      </span>
      <div class="page-links">
        <?php if ($page > 1): ?>
          <a href="<?= sprintf($qs_base, $page - 1) ?>">‹ Prev</a>
        <?php else: ?>
          <span class="disabled">‹ Prev</span>
        <?php endif;

        $win_start = max(1, $page - 2);
        $win_end   = min($total_pages, $page + 2);
        if ($win_start > 1) echo '<span style="border:none;padding:6px 4px;">…</span>';

        for ($i = $win_start; $i <= $win_end; $i++):
            if ($i === $page): ?>
              <span class="active"><?= $i ?></span>
            <?php else: ?>
              <a href="<?= sprintf($qs_base, $i) ?>"><?= $i ?></a>
            <?php endif;
        endfor;

        if ($win_end < $total_pages) echo '<span style="border:none;padding:6px 4px;">…</span>';

        if ($page < $total_pages): ?>
          <a href="<?= sprintf($qs_base, $page + 1) ?>">Next ›</a>
        <?php else: ?>
          <span class="disabled">Next ›</span>
        <?php endif; ?>
      </div>
    </div>
    <?php endif; ?>

    <?php endif; // empty users ?>
  </div><!-- /.um-card -->

</div><!-- /.um-wrap -->


<!-- ═══════════════════════════════════════════
     EDIT / PASSWORD RESET MODAL
════════════════════════════════════════════ -->
<div class="modal-bd" id="editModal">
  <div class="modal-box">
    <button class="modal-close-btn" onclick="closeModal('editModal')" type="button">✕</button>
    <h3>✏️ Edit User</h3>

    <form method="post" id="editForm" autocomplete="off">
      <input type="hidden" name="action" value="edit_user">
      <input type="hidden" name="user_id" id="edit_user_id">

      <div class="modal-form-grid">

        <div class="form-group">
          <label for="edit_username">Username</label>
          <input type="text" id="edit_username" name="username" maxlength="50" required autocomplete="off">
        </div>

        <div class="form-group">
          <label for="edit_role">Role</label>
          <select id="edit_role" name="role" required>
            <option value="viewer">Viewer</option>
            <option value="editor">Editor</option>
            <option value="marketing">Marketing</option>
            <option value="operator">Operator</option>
            <option value="admin">Admin</option>
          </select>
        </div>

        <hr class="divider">

        <div>
          <div class="pw-section-title">
            🔐 Reset Password
            <span class="pw-section-sub">— leave blank to keep current password</span>
          </div>
          <div style="display:grid;grid-template-columns:1fr 1fr;gap:12px;">
            <div class="form-group">
              <label for="edit_new_password">New Password</label>
              <input type="password" id="edit_new_password" name="new_password"
                     placeholder="New password…"
                     autocomplete="new-password">
              <span class="field-hint">Min. 6 characters</span>
            </div>
            <div class="form-group">
              <label for="edit_confirm_password">Confirm Password</label>
              <input type="password" id="edit_confirm_password"
                     placeholder="Repeat new password…"
                     autocomplete="new-password">
              <span class="field-hint" id="pw_match_hint"></span>
            </div>
          </div>
        </div>

      </div><!-- /.modal-form-grid -->

      <div class="modal-footer">
        <button type="button" class="btn btn-neutral" onclick="closeModal('editModal')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>


<!-- ═══════════════════════════════════════════
     DELETE CONFIRM MODAL
════════════════════════════════════════════ -->
<div class="modal-bd" id="deleteModal">
  <div class="modal-box" style="width:400px;">
    <button class="modal-close-btn" onclick="closeModal('deleteModal')" type="button">✕</button>
    <h3>🗑️ Delete User</h3>
    <p style="color:#6b7280;font-size:.92rem;line-height:1.6;margin:0 0 8px;">
      You are about to permanently delete
      <strong id="del_uname_display" style="color:#1e2a3b;"></strong>.
      This action <strong>cannot be undone</strong>.
    </p>
    <form method="post" id="deleteForm">
      <input type="hidden" name="action" value="delete_user">
      <input type="hidden" name="user_id" id="del_user_id">
      <div class="modal-footer">
        <button type="button" class="btn btn-neutral" onclick="closeModal('deleteModal')">Cancel</button>
        <button type="submit" class="btn btn-danger">Yes, Delete</button>
      </div>
    </form>
  </div>
</div>


<script>
// ── Open Edit Modal ──────────────────────────────────────────────────────────
// Uses data-* attributes to avoid ALL inline JS string escaping problems.
// getAttribute() returns the raw decoded string — no escaping issues possible.
function openEditModal(btn) {
    var uid   = btn.getAttribute('data-uid');
    var uname = btn.getAttribute('data-uname');
    var urole = btn.getAttribute('data-urole');

    // Populate hidden + text fields
    document.getElementById('edit_user_id').value  = uid;
    document.getElementById('edit_username').value = uname;

    // Clear password fields every time the modal opens
    document.getElementById('edit_new_password').value     = '';
    document.getElementById('edit_confirm_password').value = '';
    document.getElementById('pw_match_hint').textContent   = '';
    document.getElementById('pw_match_hint').style.color   = '';

    // Select the correct role option
    var sel = document.getElementById('edit_role');
    for (var i = 0; i < sel.options.length; i++) {
        sel.options[i].selected = (sel.options[i].value === urole);
    }

    document.getElementById('editModal').classList.add('open');
    // Small delay so the modal animation finishes before focusing
    setTimeout(function(){ document.getElementById('edit_username').focus(); }, 80);
}

// ── Open Delete Modal ────────────────────────────────────────────────────────
function openDeleteModal(btn) {
    var uid   = btn.getAttribute('data-uid');
    var uname = btn.getAttribute('data-uname');

    document.getElementById('del_user_id').value              = uid;
    document.getElementById('del_uname_display').textContent  = uname;
    document.getElementById('deleteModal').classList.add('open');
}

// ── Close Modal ──────────────────────────────────────────────────────────────
function closeModal(id) {
    document.getElementById(id).classList.remove('open');
}

// Close on backdrop click (click outside the modal box)
document.querySelectorAll('.modal-bd').forEach(function(bd) {
    bd.addEventListener('click', function(e) {
        if (e.target === this) closeModal(this.id);
    });
});

// Close on ESC key
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        document.querySelectorAll('.modal-bd.open').forEach(function(m) {
            m.classList.remove('open');
        });
    }
});

// ── Real-time password match indicator ──────────────────────────────────────
function checkPasswordMatch() {
    var np   = document.getElementById('edit_new_password').value;
    var cp   = document.getElementById('edit_confirm_password').value;
    var hint = document.getElementById('pw_match_hint');

    if (np === '' || cp === '') {
        hint.textContent = '';
        hint.style.color = '';
        return;
    }
    if (np === cp) {
        hint.textContent = '✓ Passwords match';
        hint.style.color = '#059669';
    } else {
        hint.textContent = '✗ Do not match';
        hint.style.color = '#dc2626';
    }
}

document.getElementById('edit_new_password').addEventListener('input', checkPasswordMatch);
document.getElementById('edit_confirm_password').addEventListener('input', checkPasswordMatch);

// ── Block form submit if password fields are filled but don't match ──────────
document.getElementById('editForm').addEventListener('submit', function(e) {
    var np = document.getElementById('edit_new_password').value;
    var cp = document.getElementById('edit_confirm_password').value;

    if (np !== '' && np !== cp) {
        e.preventDefault();
        var hint = document.getElementById('pw_match_hint');
        hint.textContent = '✗ Passwords do not match — fix before saving.';
        hint.style.color = '#dc2626';
        document.getElementById('edit_confirm_password').focus();
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// redirect_if_not_logged_in();
// This page manages who receives what, where — restrict to admins.
// if (!has_role('admin')) {
//     header('Location: ' . $_SERVER['DOCUMENT_ROOT'] . '/deno2/index.php?error=' . urlencode('Admins only.'));
//     exit;
// }

$current_user_id = $_SESSION['user_id'] ?? null;

/* ═══════════════════════════════════════════════════════════════════
   POST handlers
   ═══════════════════════════════════════════════════════════════════ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    try {
        switch ($action) {

            /* ── Create / update a godam ── */
            case 'godam_save':
                if (empty($_POST['godam_code']) || empty($_POST['godam_name'])) {
                    throw new Exception('Godam code and name are required.');
                }
                if (!empty($_POST['godam_id'])) {
                    $conn->prepare("
                        UPDATE godam SET godam_code = :code, godam_name = :name, location = :loc, updated_at = CURRENT_TIMESTAMP
                        WHERE id = :id
                    ")->execute([
                        ':code' => $_POST['godam_code'], ':name' => $_POST['godam_name'],
                        ':loc'  => $_POST['location'] ?? null, ':id' => $_POST['godam_id'],
                    ]);
                    $msg = 'Godam updated.';
                } else {
                    $conn->prepare("
                        INSERT INTO godam (godam_code, godam_name, location, created_by)
                        VALUES (:code, :name, :loc, :uid)
                    ")->execute([
                        ':code' => $_POST['godam_code'], ':name' => $_POST['godam_name'],
                        ':loc'  => $_POST['location'] ?? null, ':uid' => $current_user_id,
                    ]);
                    $msg = 'Godam created.';
                }
                $_SESSION['flash'] = ['type' => 'success', 'msg' => $msg];
                break;

            /* ── Toggle a godam active/inactive ── */
            case 'godam_toggle':
                $conn->prepare("UPDATE godam SET is_active = NOT is_active, updated_at = CURRENT_TIMESTAMP WHERE id = :id")
                     ->execute([':id' => $_POST['godam_id']]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Godam status updated.'];
                break;

            /* ── Assign (or reassign) a book to a marketing user within a godam ── */
            case 'assignment_save':
                if (empty($_POST['godam_id']) || empty($_POST['book_code']) || empty($_POST['marketing_user_id'])) {
                    throw new Exception('Godam, Book, and Marketing User are all required.');
                }
                // One marketing user per book per godam — upsert on the (godam_id, book_code) unique key.
                $conn->prepare("
                    INSERT INTO godam_book_assignment (godam_id, book_code, marketing_user_id, is_active)
                    VALUES (:gid, :bc, :uid, true)
                    ON CONFLICT (godam_id, book_code)
                    DO UPDATE SET marketing_user_id = EXCLUDED.marketing_user_id, is_active = true
                ")->execute([
                    ':gid' => $_POST['godam_id'], ':bc' => $_POST['book_code'], ':uid' => $_POST['marketing_user_id'],
                ]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Assignment saved.'];
                break;

            /* ── Toggle an assignment active/inactive ── */
            case 'assignment_toggle':
                $conn->prepare("UPDATE godam_book_assignment SET is_active = NOT is_active WHERE id = :id")
                     ->execute([':id' => $_POST['assignment_id']]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Assignment status updated.'];
                break;

            /* ── Delete an assignment entirely ── */
            case 'assignment_delete':
                $conn->prepare("DELETE FROM godam_book_assignment WHERE id = :id")
                     ->execute([':id' => $_POST['assignment_id']]);
                $_SESSION['flash'] = ['type' => 'success', 'msg' => 'Assignment removed.'];
                break;
        }
    } catch (Exception $e) {
        $_SESSION['flash'] = ['type' => 'danger', 'msg' => 'Error: ' . htmlspecialchars($e->getMessage())];
    }
    header('Location: ' . $_SERVER['PHP_SELF'] . (!empty($_GET['godam_id']) ? '?godam_id=' . (int)$_GET['godam_id'] : ''));
    exit;
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$godams = $conn->query("SELECT * FROM godam ORDER BY godam_name")->fetchAll(PDO::FETCH_ASSOC);
$marketing_users = $conn->query("SELECT id, username FROM users WHERE role = 'marketing' ORDER BY username")->fetchAll(PDO::FETCH_ASSOC);
$books = $conn->query("SELECT book_code, book_name, class_level FROM books WHERE is_active = true ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

$edit_godam = null;
if (!empty($_GET['edit_godam_id'])) {
    $stmt = $conn->prepare("SELECT * FROM godam WHERE id = :id");
    $stmt->execute([':id' => $_GET['edit_godam_id']]);
    $edit_godam = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Assignments list, optionally filtered to one godam
$filter_godam_id = $_GET['godam_id'] ?? '';
$assign_where = '';
$assign_params = [];
if (!empty($filter_godam_id)) {
    $assign_where = 'WHERE gba.godam_id = :gid';
    $assign_params[':gid'] = $filter_godam_id;
}
$stmt = $conn->prepare("
    SELECT gba.*, g.godam_name, b.book_name, u.username AS marketing_username
    FROM   godam_book_assignment gba
    LEFT JOIN godam g ON gba.godam_id = g.id
    LEFT JOIN books b ON gba.book_code = b.book_code
    LEFT JOIN users u ON gba.marketing_user_id = u.id
    $assign_where
    ORDER  BY g.godam_name, b.book_name
");
$stmt->execute($assign_params);
$assignments = $stmt->fetchAll(PDO::FETCH_ASSOC);

$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<style>
body { font-size:15px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; padding:20px; background:#f8f9fa; }
.container { max-width:1300px; margin:0 auto; }
h2 { border-bottom:2px solid #007bff; padding-bottom:10px; }
h3 { margin-top:36px; }

.alert { padding:12px 16px; margin-bottom:16px; border-radius:5px; font-weight:500; }
.alert-success { color:#155724; background:#d4edda; border:1px solid #c3e6cb; }
.alert-danger  { color:#721c24; background:#f8d7da; border:1px solid #f5c6cb; }

.panel { background:#fff; border:1px solid #e9ecef; border-radius:8px; padding:20px; box-shadow:0 2px 6px rgba(0,0,0,.06); margin-bottom:24px; }
.form-row { display:flex; gap:14px; flex-wrap:wrap; align-items:end; margin-bottom:14px; }
.form-group { flex:1; min-width:200px; display:flex; flex-direction:column; }
.form-group label { font-weight:600; font-size:13px; color:#495057; margin-bottom:5px; }
.form-control { padding:9px 12px; border:1px solid #ddd; border-radius:5px; font-size:14px; box-sizing:border-box; }

.btn { padding:9px 18px; border:none; border-radius:5px; cursor:pointer; font-size:14px; font-weight:600; text-decoration:none; display:inline-block; margin-right:6px; }
.btn-primary { background:#007bff; color:#fff; }
.btn-secondary { background:#6c757d; color:#fff; }
.btn-sm { padding:5px 10px; font-size:12px; }
.btn-warning { background:#ffc107; color:#212529; }
.btn-danger { background:#dc3545; color:#fff; }

.table { width:100%; border-collapse:collapse; font-size:13px; background:#fff; }
.table th, .table td { padding:9px; text-align:left; border-bottom:1px solid #dee2e6; }
.table th { background:#f8f9fa; text-transform:uppercase; font-size:11px; color:#495057; }
.badge { display:inline-block; padding:3px 8px; border-radius:4px; font-size:11px; font-weight:700; }
.badge-active   { background:#d4edda; color:#155724; }
.badge-inactive { background:#f8d7da; color:#721c24; }

.search-dropdown { position:relative; }
.dropdown-options {
    position:absolute; top:100%; left:0; right:0; background:#fff; border:1px solid #ddd; border-top:none;
    max-height:220px; overflow-y:auto; z-index:1000; display:none; box-shadow:0 4px 10px rgba(0,0,0,.1);
}
.dropdown-option { padding:9px 12px; cursor:pointer; border-bottom:1px solid #eee; font-size:13px; }
.dropdown-option:hover { background:#f0f7ff; }
</style>

<div class="container">
<h2>🏬 Godam &amp; Book Assignment Management</h2>

<?php if ($flash): ?>
<div class="alert alert-<?= htmlspecialchars($flash['type']) ?>"><?= htmlspecialchars($flash['msg']) ?></div>
<?php endif; ?>

<!-- ── GODAM CRUD ── -->
<div class="panel">
    <h3 style="margin-top:0;"><?= $edit_godam ? '✏️ Edit Godam' : '➕ Add Godam' ?></h3>
    <form method="post">
        <input type="hidden" name="action" value="godam_save">
        <?php if ($edit_godam): ?><input type="hidden" name="godam_id" value="<?= $edit_godam['id'] ?>"><?php endif; ?>
        <div class="form-row">
            <div class="form-group">
                <label>Godam Code</label>
                <input type="text" name="godam_code" class="form-control" required
                       value="<?= htmlspecialchars($edit_godam['godam_code'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Godam Name</label>
                <input type="text" name="godam_name" class="form-control" required
                       value="<?= htmlspecialchars($edit_godam['godam_name'] ?? '') ?>">
            </div>
            <div class="form-group">
                <label>Location</label>
                <input type="text" name="location" class="form-control"
                       value="<?= htmlspecialchars($edit_godam['location'] ?? '') ?>">
            </div>
            <div class="form-group" style="flex:0;">
                <button type="submit" class="btn btn-primary"><?= $edit_godam ? '💾 Update' : '➕ Add' ?></button>
                <?php if ($edit_godam): ?><a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a><?php endif; ?>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h3 style="margin-top:0;">Godams</h3>
    <table class="table">
        <thead><tr><th>Code</th><th>Name</th><th>Location</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (empty($godams)): ?>
            <tr><td colspan="5" style="text-align:center;color:#888;padding:16px;">No godams yet — add one above.</td></tr>
        <?php else: foreach ($godams as $g): ?>
            <tr>
                <td><?= htmlspecialchars($g['godam_code']) ?></td>
                <td><strong><?= htmlspecialchars($g['godam_name']) ?></strong></td>
                <td><?= htmlspecialchars($g['location'] ?? '-') ?></td>
                <td><span class="badge badge-<?= $g['is_active'] ? 'active' : 'inactive' ?>"><?= $g['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td>
                    <a href="?edit_godam_id=<?= $g['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                    <a href="?godam_id=<?= $g['id'] ?>" class="btn btn-secondary btn-sm">View Assignments</a>
                    <form method="post" style="display:inline" onsubmit="return confirm('Toggle this godam\'s active status?')">
                        <input type="hidden" name="action" value="godam_toggle">
                        <input type="hidden" name="godam_id" value="<?= $g['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm"><?= $g['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>

<!-- ── ASSIGNMENT CRUD ── -->
<div class="panel">
    <h3 style="margin-top:0;">➕ Assign a Book to a Marketing User in a Godam</h3>
    <p style="font-size:13px;color:#888;margin-top:-8px;">One marketing user per book per godam — saving again for the same Godam + Book reassigns it.</p>
    <form method="post">
        <input type="hidden" name="action" value="assignment_save">
        <input type="hidden" name="book_code" id="assign_book_code">
        <div class="form-row">
            <div class="form-group">
                <label>Godam</label>
                <select name="godam_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($godams as $g): if (!$g['is_active']) continue; ?>
                    <option value="<?= $g['id'] ?>" <?= (string)$filter_godam_id === (string)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['godam_name']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:2;">
                <label>Book <small style="font-weight:400;color:#888;">(click to see full list, type to filter)</small></label>
                <div class="search-dropdown">
                    <input type="text" class="form-control" id="assign_book_search" placeholder="Click to browse, or type to filter…" autocomplete="off">
                    <div class="dropdown-options" id="assign_book_options">
                        <?php foreach ($books as $b): ?>
                        <div class="dropdown-option"
                             data-value="<?= htmlspecialchars($b['book_code']) ?>"
                             data-text="<?= htmlspecialchars($b['book_name']) ?> (<?= htmlspecialchars($b['book_code']) ?>)">
                            <?= htmlspecialchars($b['book_name']) ?>
                            <small style="color:#888">(<?= htmlspecialchars($b['book_code']) ?><?= $b['class_level'] ? ' · Class ' . htmlspecialchars($b['class_level']) : '' ?>)</small>
                        </div>
                        <?php endforeach; ?>
                        <?php if (empty($books)): ?>
                        <div class="dropdown-option" style="color:#999;">No active books found.</div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="form-group">
                <label>Marketing User</label>
                <select name="marketing_user_id" class="form-control" required>
                    <option value="">— Select —</option>
                    <?php foreach ($marketing_users as $u): ?>
                    <option value="<?= $u['id'] ?>"><?= htmlspecialchars($u['username']) ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group" style="flex:0;">
                <button type="submit" class="btn btn-primary">💾 Save Assignment</button>
            </div>
        </div>
    </form>
</div>

<div class="panel">
    <h3 style="margin-top:0;">
        Assignments
        <?php if ($filter_godam_id): $fg = array_values(array_filter($godams, fn($g) => (string)$g['id'] === (string)$filter_godam_id));
              if ($fg) echo '— ' . htmlspecialchars($fg[0]['godam_name']); ?>
            <a href="<?= $_SERVER['PHP_SELF'] ?>" style="font-size:13px;font-weight:400;">(clear filter)</a>
        <?php endif; ?>
    </h3>
    <table class="table">
        <thead><tr><th>Godam</th><th>Book</th><th>Marketing User</th><th>Status</th><th>Actions</th></tr></thead>
        <tbody>
        <?php if (empty($assignments)): ?>
            <tr><td colspan="5" style="text-align:center;color:#888;padding:16px;">No assignments yet.</td></tr>
        <?php else: foreach ($assignments as $a): ?>
            <tr>
                <td><?= htmlspecialchars($a['godam_name'] ?? '-') ?></td>
                <td><?= htmlspecialchars($a['book_name'] ?? $a['book_code']) ?></td>
                <td><?= htmlspecialchars($a['marketing_username'] ?? '-') ?></td>
                <td><span class="badge badge-<?= $a['is_active'] ? 'active' : 'inactive' ?>"><?= $a['is_active'] ? 'Active' : 'Inactive' ?></span></td>
                <td>
                    <form method="post" style="display:inline">
                        <input type="hidden" name="action" value="assignment_toggle">
                        <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn btn-warning btn-sm"><?= $a['is_active'] ? 'Deactivate' : 'Activate' ?></button>
                    </form>
                    <form method="post" style="display:inline" onsubmit="return confirm('Remove this assignment entirely?')">
                        <input type="hidden" name="action" value="assignment_delete">
                        <input type="hidden" name="assignment_id" value="<?= $a['id'] ?>">
                        <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; endif; ?>
        </tbody>
    </table>
</div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var searchInput = document.getElementById('assign_book_search');
    var hiddenInput = document.getElementById('assign_book_code');
    var box = document.getElementById('assign_book_options');
    var options = box.querySelectorAll('.dropdown-option[data-value]');

    // Show the full list immediately on click/focus — no AJAX round-trip needed for this admin form.
    searchInput.addEventListener('focus', function () {
        filterOptions();
        box.style.display = 'block';
    });
    searchInput.addEventListener('input', function () {
        hiddenInput.value = '';
        filterOptions();
        box.style.display = 'block';
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('#assign_book_search') && !e.target.closest('#assign_book_options')) {
            box.style.display = 'none';
        }
    });

    function filterOptions() {
        var term = searchInput.value.toLowerCase();
        options.forEach(function (o) {
            var match = o.dataset.text.toLowerCase().includes(term) || o.dataset.value.toLowerCase().includes(term);
            o.style.display = match ? 'block' : 'none';
        });
    }

    options.forEach(function (o) {
        o.addEventListener('click', function () {
            searchInput.value = this.dataset.text;
            hiddenInput.value = this.dataset.value;
            box.style.display = 'none';
        });
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

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

    // ── ADD FISCAL YEAR ──────────────────────────────────────────────────────
    if ($action === 'add_fy') {
        $fiscal_code = trim($_POST['fiscal_code'] ?? '');
        $fiscal_name = trim($_POST['fiscal_name'] ?? '');
        $start_date  = $_POST['start_date'] ?? '';
        $end_date    = $_POST['end_date'] ?? '';
        $make_active = isset($_POST['is_active']);

        if ($fiscal_code === '' || strlen($fiscal_code) > 10) $errors[] = 'Fiscal code is required (max 10 characters).';
        if ($fiscal_name === '')                              $errors[] = 'Fiscal name is required (e.g. 2082-83).';
        if ($start_date === '' || $end_date === '')           $errors[] = 'Start and end date are required.';
        if ($start_date !== '' && $end_date !== '' && $start_date >= $end_date) $errors[] = 'End date must be after start date.';

        if (empty($errors)) {
            try {
                $conn->beginTransaction();
                $stmt = $conn->prepare(
                    "INSERT INTO fiscal_years (fiscal_code, fiscal_name, start_date, end_date, is_active)
                     VALUES (:code, :name, :start, :end, :active)"
                );
                $stmt->execute([
                    ':code' => $fiscal_code, ':name' => $fiscal_name,
                    ':start' => $start_date, ':end' => $end_date,
                    ':active' => $make_active ? 't' : 'f'
                ]);
                $conn->commit();
                $success = "Fiscal year '{$fiscal_name}' created successfully.";
            } catch (PDOException $e) {
                $conn->rollBack();
                $msg = $e->getMessage();
                $errors[] = (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false)
                    ? "Fiscal code already exists."
                    : "Database error: " . $msg;
            }
        }

    // ── EDIT FISCAL YEAR ─────────────────────────────────────────────────────
    } elseif ($action === 'edit_fy') {
        $id          = (int)($_POST['fy_id'] ?? 0);
        $fiscal_code = trim($_POST['fiscal_code'] ?? '');
        $fiscal_name = trim($_POST['fiscal_name'] ?? '');
        $start_date  = $_POST['start_date'] ?? '';
        $end_date    = $_POST['end_date'] ?? '';

        if ($id <= 0)                                         $errors[] = 'Invalid fiscal year.';
        if ($fiscal_code === '' || strlen($fiscal_code) > 10) $errors[] = 'Fiscal code is required (max 10 characters).';
        if ($fiscal_name === '')                              $errors[] = 'Fiscal name is required.';
        if ($start_date === '' || $end_date === '')           $errors[] = 'Start and end date are required.';
        if ($start_date !== '' && $end_date !== '' && $start_date >= $end_date) $errors[] = 'End date must be after start date.';

        if (empty($errors)) {
            try {
                $stmt = $conn->prepare(
                    "UPDATE fiscal_years
                        SET fiscal_code = :code, fiscal_name = :name,
                            start_date = :start, end_date = :end
                      WHERE id = :id"
                );
                $stmt->execute([
                    ':code' => $fiscal_code, ':name' => $fiscal_name,
                    ':start' => $start_date, ':end' => $end_date, ':id' => $id
                ]);
                $success = "Fiscal year updated successfully.";
            } catch (PDOException $e) {
                $msg = $e->getMessage();
                $errors[] = (stripos($msg, 'unique') !== false || stripos($msg, 'duplicate') !== false)
                    ? "Fiscal code already exists."
                    : "Database error: " . $msg;
            }
        }

    // ── SET ACTIVE ───────────────────────────────────────────────────────────
    // trg_single_active_fiscal_year automatically deactivates every other row.
    } elseif ($action === 'activate_fy') {
        $id = (int)($_POST['fy_id'] ?? 0);
        if ($id <= 0) {
            $errors[] = 'Invalid fiscal year.';
        } else {
            try {
                $stmt = $conn->prepare("UPDATE fiscal_years SET is_active = true WHERE id = :id");
                $stmt->execute([':id' => $id]);
                $success = "Active fiscal year updated.";
            } catch (PDOException $e) {
                $errors[] = "Database error: " . $e->getMessage();
            }
        }
    }

    // PRG — redirect to avoid re-submission on refresh
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
// FETCH
// ─────────────────────────────────────────────────────────────────────────────
$fiscal_years = $conn->query(
    "SELECT id, fiscal_code, fiscal_name, start_date, end_date, is_active, created_at
       FROM fiscal_years
      ORDER BY start_date DESC"
)->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
.fy-wrap        { max-width:1100px; margin:0 auto; padding:24px 16px; }
.fy-title       { font-size:1.55rem; font-weight:700; color:#1e2a3b; margin:0 0 4px; }
.fy-subtitle    { color:#6c757d; font-size:.9rem; margin:0 0 22px; }
.fy-card        { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.08); padding:28px 32px; margin-bottom:24px; }
.fy-card-title  { font-size:.95rem; font-weight:700; color:#374151; margin:0 0 18px; }
.fy-alert       { padding:12px 18px; border-radius:7px; margin-bottom:18px; font-size:.9rem; }
.fy-alert.ok    { background:#d1fae5; color:#065f46; border:1px solid #6ee7b7; }
.fy-alert.err   { background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.fy-alert ul    { margin:4px 0 0 18px; padding:0; }
.fy-form-grid   { display:grid; grid-template-columns:1fr 1fr 1fr 1fr auto auto; gap:14px; align-items:flex-end; }
.fy-form-group  { display:flex; flex-direction:column; gap:5px; }
.fy-form-group label { font-size:.78rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; }
.fy-form-group input { padding:9px 12px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.93rem; outline:none; background:#fff; }
.fy-form-group input:focus { border-color:#6366f1; box-shadow:0 0 0 3px rgba(99,102,241,.12); }
.fy-checkbox    { display:flex; align-items:center; gap:6px; font-size:.85rem; color:#374151; padding-bottom:9px; }
.fy-btn         { padding:9px 18px; border:none; border-radius:7px; font-size:.88rem; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
.fy-btn-primary { background:#6366f1; color:#fff; }
.fy-btn-primary:hover { background:#4f46e5; }
.fy-btn-sm      { padding:6px 12px; font-size:.8rem; }
.fy-btn-outline { background:#fff; border:1.5px solid #d1d5db; color:#374151; }
.fy-btn-success { background:#059669; color:#fff; }
.fy-table       { width:100%; border-collapse:collapse; font-size:.9rem; }
.fy-table th    { text-align:left; padding:10px 12px; background:#f9fafb; color:#6b7280; font-size:.75rem; text-transform:uppercase; letter-spacing:.04em; border-bottom:1.5px solid #e5e7eb; }
.fy-table td    { padding:12px; border-bottom:1px solid #f1f3f5; vertical-align:middle; }
.fy-badge       { padding:4px 10px; border-radius:20px; font-size:.72rem; font-weight:700; text-transform:uppercase; letter-spacing:.03em; }
.fy-badge.active   { background:#d1fae5; color:#065f46; }
.fy-badge.inactive { background:#f3f4f6; color:#6b7280; }
.fy-row.active-row { background:#f0fdf4; }
</style>

<div class="fy-wrap">
    <h1 class="fy-title">📅 Fiscal Year Setup</h1>
    <p class="fy-subtitle">Manage fiscal years. The active fiscal year drives number-series resets and fiscal_name display across every module.</p>

    <?php if ($success): ?>
        <div class="fy-alert ok"><?= $success ?></div>
    <?php endif; ?>
    <?php if (!empty($errors)): ?>
        <div class="fy-alert err">
            <strong>Please fix the following:</strong>
            <ul><?php foreach ($errors as $e): ?><li><?= $e ?></li><?php endforeach; ?></ul>
        </div>
    <?php endif; ?>

    <!-- Add Fiscal Year -->
    <div class="fy-card">
        <div class="fy-card-title">➕ Add Fiscal Year</div>
        <form method="post">
            <input type="hidden" name="action" value="add_fy">
            <div class="fy-form-grid">
                <div class="fy-form-group">
                    <label for="fiscal_code">Fiscal Code</label>
                    <input type="text" name="fiscal_code" id="fiscal_code" maxlength="10" placeholder="2085" required>
                </div>
                <div class="fy-form-group">
                    <label for="fiscal_name">Fiscal Name</label>
                    <input type="text" name="fiscal_name" id="fiscal_name" placeholder="2085-86" required>
                </div>
                <div class="fy-form-group">
                    <label for="start_date">Start Date</label>
                    <input type="date" name="start_date" id="start_date" required>
                </div>
                <div class="fy-form-group">
                    <label for="end_date">End Date</label>
                    <input type="date" name="end_date" id="end_date" required>
                </div>
                <label class="fy-checkbox">
                    <input type="checkbox" name="is_active" value="1"> Make active
                </label>
                <button type="submit" class="fy-btn fy-btn-primary">Add</button>
            </div>
        </form>
    </div>

    <!-- List -->
    <div class="fy-card">
        <div class="fy-card-title">📋 All Fiscal Years</div>
        <table class="fy-table">
            <thead>
                <tr>
                    <th>Fiscal Code</th>
                    <th>Fiscal Name</th>
                    <th>Start Date</th>
                    <th>End Date</th>
                    <th>Status</th>
                    <th>Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($fiscal_years as $fy): $editFormId = 'edit-form-' . $fy['id']; ?>
                <form id="<?= $editFormId ?>" method="post"></form>
                <tr class="fy-row <?= $fy['is_active'] ? 'active-row' : '' ?>">
                    <td>
                        <input type="hidden" name="action" value="edit_fy" form="<?= $editFormId ?>">
                        <input type="hidden" name="fy_id" value="<?= $fy['id'] ?>" form="<?= $editFormId ?>">
                        <input type="text" name="fiscal_code" form="<?= $editFormId ?>" value="<?= htmlspecialchars($fy['fiscal_code']) ?>" maxlength="10" style="width:80px;padding:6px 8px;border:1px solid #d1d5db;border-radius:5px;">
                    </td>
                    <td><input type="text" name="fiscal_name" form="<?= $editFormId ?>" value="<?= htmlspecialchars($fy['fiscal_name']) ?>" style="width:100px;padding:6px 8px;border:1px solid #d1d5db;border-radius:5px;"></td>
                    <td><input type="date" name="start_date" form="<?= $editFormId ?>" value="<?= htmlspecialchars($fy['start_date']) ?>" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:5px;"></td>
                    <td><input type="date" name="end_date" form="<?= $editFormId ?>" value="<?= htmlspecialchars($fy['end_date']) ?>" style="padding:6px 8px;border:1px solid #d1d5db;border-radius:5px;"></td>
                    <td>
                        <span class="fy-badge <?= $fy['is_active'] ? 'active' : 'inactive' ?>">
                            <?= $fy['is_active'] ? 'Active' : 'Inactive' ?>
                        </span>
                    </td>
                    <td>
                        <button type="submit" form="<?= $editFormId ?>" class="fy-btn fy-btn-outline fy-btn-sm">Save</button>
                        <?php if (!$fy['is_active']): ?>
                        <form method="post" style="display:inline;" onsubmit="return confirm('Set &quot;<?= htmlspecialchars($fy['fiscal_name']) ?>&quot; as the active fiscal year? All new number series will reset to 1 under it.')">
                            <input type="hidden" name="action" value="activate_fy">
                            <input type="hidden" name="fy_id" value="<?= $fy['id'] ?>">
                            <button type="submit" class="fy-btn fy-btn-success fy-btn-sm">Set Active</button>
                        </form>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
                <?php if (empty($fiscal_years)): ?>
                <tr><td colspan="6" style="text-align:center;padding:30px;color:#6c757d;">No fiscal years configured yet.</td></tr>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

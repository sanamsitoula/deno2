<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
redirect_if_not_authorized(['admin', 'marketing']);

$mi_id = $_GET['id'] ?? null;
$error = '';
if (!$mi_id) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("SELECT * FROM marketing_inward WHERE id = :id");
$stmt->execute([':id' => $mi_id]);
$mi = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mi) { header('Location: index.php'); exit; }

if ($mi['status'] !== 'DRAFT') {
    $_SESSION['error'] = 'Only DRAFT Marketing Inward records can be edited.';
    header('Location: view.php?id=' . $mi_id);
    exit;
}

$items_stmt = $conn->prepare("
    SELECT mid.*, b.book_name FROM marketing_inward_details mid
    JOIN books b ON b.book_code = mid.book_code
    WHERE mid.marketing_inward_id = :id ORDER BY b.book_name
");
$items_stmt->execute([':id' => $mi_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        $remarks = trim($_POST['remarks'] ?? '');

        $conn->prepare("UPDATE marketing_inward SET remarks = :r, updated_by = :uid, updated_at = NOW() WHERE id = :id AND status = 'DRAFT'")
             ->execute([':r' => $remarks !== '' ? $remarks : null, ':uid' => $_SESSION['user_id'], ':id' => $mi_id]);

        $total_press = 0; $total_marketing = 0; $count = 0;
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $upd = $conn->prepare("UPDATE marketing_inward_details SET marketing_qty = :mq, updated_at = NOW() WHERE id = :id AND marketing_inward_id = :mi_id");
            foreach ($_POST['items'] as $detail_id => $mqty) {
                $upd->execute([':mq' => (int)$mqty, ':id' => (int)$detail_id, ':mi_id' => $mi_id]);
            }
        }

        $sums = $conn->prepare("SELECT COALESCE(SUM(press_qty),0) tp, COALESCE(SUM(marketing_qty),0) tm, COUNT(*) tb FROM marketing_inward_details WHERE marketing_inward_id = :id");
        $sums->execute([':id' => $mi_id]);
        $s = $sums->fetch(PDO::FETCH_ASSOC);
        $conn->prepare("UPDATE marketing_inward SET total_press_qty=:tp, total_marketing_qty=:tm, total_mismatch_qty=:tmis, total_books=:tb WHERE id=:id")
             ->execute([':tp'=>$s['tp'], ':tm'=>$s['tm'], ':tmis'=>$s['tp']-$s['tm'], ':tb'=>$s['tb'], ':id'=>$mi_id]);

        $conn->commit();
        header('Location: view.php?id=' . $mi_id);
        exit;
    } catch (Exception $e) {
        $conn->rollBack();
        $error = 'Error updating: ' . $e->getMessage();
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>
<style>
.edit-container { max-width:1100px; margin:20px auto; background:#fff; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,.1); }
.edit-header { background:linear-gradient(135deg,#f093fb 0%,#f5576c 100%); color:#fff; padding:24px; border-radius:10px 10px 0 0; }
.form-section { padding:24px; }
.items-table { width:100%; border-collapse:collapse; margin-top:14px; }
.items-table th,.items-table td { padding:10px; border-bottom:1px solid #dee2e6; text-align:left; }
.items-table input[type=number] { width:110px; padding:6px; border:1px solid #ced4da; border-radius:4px; }
.btn { padding:9px 18px; border:none; border-radius:5px; font-weight:600; cursor:pointer; text-decoration:none; display:inline-block; }
.btn-primary { background:#007bff; color:#fff; } .btn-secondary { background:#6c757d; color:#fff; }
.alert-danger { background:#f8d7da; color:#721c24; padding:12px; border-radius:5px; margin:16px 24px; }
</style>
<div class="edit-container">
  <div class="edit-header"><h2>✏️ Edit Marketing Inward</h2><div><?= htmlspecialchars($mi['inward_no']) ?></div></div>
  <?php if ($error): ?><div class="alert-danger"><?= htmlspecialchars($error) ?></div><?php endif; ?>
  <form method="post">
    <div class="form-section">
      <label style="font-weight:600;">Remarks</label>
      <textarea name="remarks" rows="3" style="width:100%;padding:8px;border:1px solid #ced4da;border-radius:5px;"><?= htmlspecialchars($mi['remarks'] ?? '') ?></textarea>

      <table class="items-table">
        <thead><tr><th>Book</th><th>Code</th><th>Press Qty</th><th>Marketing Qty</th></tr></thead>
        <tbody>
          <?php foreach ($items as $it): ?>
          <tr>
            <td><?= htmlspecialchars($it['book_name']) ?></td>
            <td><?= htmlspecialchars($it['book_code']) ?></td>
            <td><?= number_format($it['press_qty']) ?></td>
            <td><input type="number" min="0" name="items[<?= $it['id'] ?>]" value="<?= (int)$it['marketing_qty'] ?>"></td>
          </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
    <div class="form-section" style="display:flex;justify-content:space-between;padding-top:0;">
      <a href="view.php?id=<?= $mi_id ?>" class="btn btn-secondary">Cancel</a>
      <button type="submit" class="btn btn-primary">Save Changes</button>
    </div>
  </form>
</div>
<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

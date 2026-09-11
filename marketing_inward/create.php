<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';
redirect_if_not_authorized(['admin', 'marketing']);

use Administrator\Deno2\Shared\DateConverter;

$user_id  = (int)$_SESSION['user_id'];
$is_admin = has_role('admin');
$error    = '';

/* ===============================================================
   D2M DROPDOWN — shows every non-deleted, non-cancelled D2M with
   outstanding lines (any other status — DRAFT/CHECKED included), and
   how much of it is already inwarded (plan §5a: pipeline visibility,
   not filtered to this user's own eligible slice).
=============================================================== */
$d2m_options = $conn->query("
    SELECT d.id, d.d2m_no, d.nep_date, d.d2m_type, d.status,
           COUNT(di.id) AS total_items,
           COUNT(di.id) FILTER (
               WHERE di.id IN (
                   SELECT d2m_item_id FROM marketing_inward_details mid
                   JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
                   WHERE mi.status <> 'CANCELLED'
               )
           ) AS inwarded_items
    FROM d2m d
    JOIN d2m_items di ON di.d2m_id = d.id
    WHERE d.deleted_at IS NULL AND d.status <> 'CANCELLED'
    GROUP BY d.id
    HAVING COUNT(di.id) > COUNT(di.id) FILTER (
               WHERE di.id IN (
                   SELECT d2m_item_id FROM marketing_inward_details mid
                   JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
                   WHERE mi.status <> 'CANCELLED'
               )
           )
    ORDER BY d.nep_date DESC
")->fetchAll(PDO::FETCH_ASSOC);

/* Goddams the current user may pick from — every active goddam for admin,
   only the ones this user has an active handler row at, for marketing. */
if ($is_admin) {
    $goddam_options = $conn->query("SELECT id, code, name FROM goddam WHERE is_active ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
} else {
    $stmt = $conn->prepare("
        SELECT DISTINCT g.id, g.code, g.name
        FROM goddam g
        JOIN goddam_handlers gh ON gh.goddam_id = g.id
        WHERE g.is_active AND gh.is_active AND gh.user_id = :uid
          AND gh.active_from_eng <= CURRENT_DATE
          AND (gh.active_to_eng IS NULL OR gh.active_to_eng >= CURRENT_DATE)
        ORDER BY g.code
    ");
    $stmt->execute([':uid' => $user_id]);
    $goddam_options = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$d2m_id    = (int)($_GET['d2m_id'] ?? 0);
$goddam_id = (int)($_GET['goddam_id'] ?? 0);
$eligible_items = [];
$d2m_row = null;

if ($d2m_id && $goddam_id) {
    $stmt = $conn->prepare("SELECT * FROM d2m WHERE id = :id");
    $stmt->execute([':id' => $d2m_id]);
    $d2m_row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($d2m_row) {
        $sql = "
            SELECT di.id AS d2m_item_id, di.book_code, di.total_qty AS press_qty,
                   di.associated_deno_ids, b.book_name, b.class_level, b.is_translated
            FROM d2m_items di
            JOIN books b ON b.book_code = di.book_code
            WHERE di.d2m_id = :d2m_id
              AND di.id NOT IN (
                    SELECT d2m_item_id FROM marketing_inward_details mid
                    JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
                    WHERE mi.status <> 'CANCELLED'
                  )
        ";
        if (!$is_admin) {
            $sql .= "
              AND EXISTS (
                    SELECT 1 FROM goddam_handlers gh
                    WHERE gh.user_id = :uid AND gh.goddam_id = :goddam_id AND gh.is_active
                      AND gh.class_level = b.class_level AND gh.book_type = :d2m_type
                      AND gh.active_from_eng <= CURRENT_DATE
                      AND (gh.active_to_eng IS NULL OR gh.active_to_eng >= CURRENT_DATE)
                  )
            ";
        }
        $sql .= " ORDER BY b.book_name";

        $stmt = $conn->prepare($sql);
        $params = [':d2m_id' => $d2m_id];
        if (!$is_admin) {
            $params[':uid'] = $user_id;
            $params[':goddam_id'] = $goddam_id;
            $params[':d2m_type'] = $d2m_row['d2m_type'];
        }
        $stmt->execute($params);
        $eligible_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ===============================================================
   SUBMIT
=============================================================== */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $d2m_id    = (int)($_POST['d2m_id'] ?? 0);
    $goddam_id = (int)($_POST['goddam_id'] ?? 0);
    $nep_date  = trim($_POST['nep_date'] ?? '');
    $eng_date  = trim($_POST['eng_date'] ?? '');
    $remarks   = trim($_POST['remarks'] ?? '');
    $items     = $_POST['items'] ?? []; // [d2m_item_id => marketing_qty]

    if (!$d2m_id || !$goddam_id) $error = 'D2M and Goddam are required.';
    elseif (!preg_match('/^\d{4}\.\d{2}\.\d{2}$/', $nep_date) || $eng_date === '') $error = 'A valid Nepali/English date is required.';
    elseif (empty($items)) $error = 'Select at least one item to inward.';

    if (!$error) {
        try {
            $conn->beginTransaction();

            $d2mStmt = $conn->prepare("SELECT * FROM d2m WHERE id = :id FOR UPDATE");
            $d2mStmt->execute([':id' => $d2m_id]);
            $d2m_row = $d2mStmt->fetch(PDO::FETCH_ASSOC);
            if (!$d2m_row) throw new Exception('D2M not found.');

            // Re-validate eligibility server-side — never trust posted item ids.
            $eligSql = "
                SELECT di.id AS d2m_item_id, di.book_code, di.total_qty AS press_qty,
                       di.associated_deno_ids, b.class_level
                FROM d2m_items di
                JOIN books b ON b.book_code = di.book_code
                WHERE di.d2m_id = :d2m_id
                  AND di.id NOT IN (
                        SELECT d2m_item_id FROM marketing_inward_details mid
                        JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
                        WHERE mi.status <> 'CANCELLED'
                      )
            ";
            if (!$is_admin) {
                $eligSql .= "
                  AND EXISTS (
                        SELECT 1 FROM goddam_handlers gh
                        WHERE gh.user_id = :uid AND gh.goddam_id = :goddam_id AND gh.is_active
                          AND gh.class_level = b.class_level AND gh.book_type = :d2m_type
                          AND gh.active_from_eng <= CURRENT_DATE
                          AND (gh.active_to_eng IS NULL OR gh.active_to_eng >= CURRENT_DATE)
                      )
                ";
            }
            $stmt = $conn->prepare($eligSql);
            $params = [':d2m_id' => $d2m_id];
            if (!$is_admin) {
                $params[':uid'] = $user_id;
                $params[':goddam_id'] = $goddam_id;
                $params[':d2m_type'] = $d2m_row['d2m_type'];
            }
            $stmt->execute($params);
            $valid_items = [];
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $valid_items[$row['d2m_item_id']] = $row;
            }

            $fy = getActiveFiscalYear($conn);
            if (!$fy) throw new Exception('Active fiscal year not found.');

            $goddamStmt = $conn->prepare("SELECT code FROM goddam WHERE id = :id");
            $goddamStmt->execute([':id' => $goddam_id]);
            $goddam_code = $goddamStmt->fetchColumn();
            if (!$goddam_code) throw new Exception('Goddam not found.');

            [$serial, $inward_no] = generateFiscalScopedNumber(
                $conn, 'marketing_inward', 'serial_no', $fy['id'],
                'MI-' . $goddam_code, $fy, ['goddam_id' => $goddam_id]
            );

            $insertHeader = $conn->prepare("
                INSERT INTO marketing_inward
                    (inward_no, serial_no, goddam_id, d2m_id, fiscal_year_id, nep_date, eng_date, created_by, remarks)
                VALUES
                    (:no, :serial, :goddam_id, :d2m_id, :fy, :nep, :eng, :uid, :remarks)
                RETURNING id
            ");
            $insertHeader->execute([
                ':no' => $inward_no, ':serial' => $serial, ':goddam_id' => $goddam_id,
                ':d2m_id' => $d2m_id, ':fy' => $fy['id'], ':nep' => $nep_date, ':eng' => $eng_date,
                ':uid' => $user_id, ':remarks' => $remarks !== '' ? $remarks : null,
            ]);
            $mi_id = $insertHeader->fetchColumn();

            $insertDetail = $conn->prepare("
                INSERT INTO marketing_inward_details
                    (marketing_inward_id, d2m_id, d2m_item_id, book_code, class_level, bp_id, job_ticket_id, press_qty, marketing_qty)
                VALUES
                    (:mi_id, :d2m_id, :item_id, :book_code, :class_level, :bp_id, :jt_id, :press_qty, :marketing_qty)
            ");
            $denoLookup = $conn->prepare("
                SELECT bp_id, jt_id FROM deno WHERE id = ANY(string_to_array(:ids, ',')::int[]) LIMIT 1
            ");

            $inserted = 0;
            $total_press = 0;
            $total_marketing = 0;
            foreach ($items as $item_id => $mqty) {
                $item_id = (int)$item_id;
                $mqty    = (int)$mqty;
                if (!isset($valid_items[$item_id])) continue; // not eligible — silently skip rather than trust client
                $row = $valid_items[$item_id];

                $bp_id = null; $jt_id = null;
                if (!empty($row['associated_deno_ids'])) {
                    $denoLookup->execute([':ids' => $row['associated_deno_ids']]);
                    $d = $denoLookup->fetch(PDO::FETCH_ASSOC);
                    if ($d) { $bp_id = $d['bp_id']; $jt_id = $d['jt_id']; }
                }

                $insertDetail->execute([
                    ':mi_id' => $mi_id, ':d2m_id' => $d2m_id, ':item_id' => $item_id,
                    ':book_code' => $row['book_code'], ':class_level' => $row['class_level'],
                    ':bp_id' => $bp_id, ':jt_id' => $jt_id,
                    ':press_qty' => $row['press_qty'], ':marketing_qty' => $mqty,
                ]);
                $inserted++;
                $total_press += (int)$row['press_qty'];
                $total_marketing += $mqty;
            }

            if ($inserted === 0) throw new Exception('None of the submitted items are currently eligible — they may have been inwarded already.');

            $conn->prepare("
                UPDATE marketing_inward
                SET total_press_qty = :tp, total_marketing_qty = :tm,
                    total_mismatch_qty = :tmis, total_books = :tb
                WHERE id = :id
            ")->execute([
                ':tp' => $total_press, ':tm' => $total_marketing,
                ':tmis' => $total_press - $total_marketing, ':tb' => $inserted, ':id' => $mi_id,
            ]);

            $conn->commit();
            header('Location: view.php?id=' . $mi_id);
            exit;
        } catch (Exception $e) {
            $conn->rollBack();
            $error = $e->getMessage();
        }
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
$today_bs = str_replace('-', '.', DateConverter::todayBs());
?>

<style>
.mi-wrap { max-width:1300px; margin:0 auto; padding:24px 16px; }
.mi-card { background:#fff; border-radius:10px; box-shadow:0 2px 12px rgba(0,0,0,.08); padding:28px 32px; margin-bottom:24px; }
.mi-alert { padding:12px 18px; border-radius:7px; margin-bottom:18px; font-size:.9rem; background:#fee2e2; color:#991b1b; border:1px solid #fca5a5; }
.form-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(180px,1fr)); gap:14px; align-items:flex-end; margin-bottom:10px; }
.form-group { display:flex; flex-direction:column; gap:5px; }
.form-group label { font-size:.78rem; font-weight:700; color:#374151; text-transform:uppercase; letter-spacing:.04em; }
.form-group input, .form-group select { padding:9px 12px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.93rem; }
.dual-date .bs-date, .dual-date .ad-date { padding:9px 12px; border:1.5px solid #d1d5db; border-radius:7px; font-size:.85rem; }
.btn { padding:9px 18px; border:none; border-radius:7px; font-size:.88rem; font-weight:600; cursor:pointer; }
.btn-primary { background:#6366f1; color:#fff; }
.btn-success { background:#16a34a; color:#fff; }
.item-table { width:100%; border-collapse:collapse; font-size:.87rem; margin-top:14px; }
.item-table th { background:#f8fafc; padding:9px 10px; text-align:left; font-size:.72rem; font-weight:700; text-transform:uppercase; color:#6b7280; border-bottom:2px solid #e5e7eb; }
.item-table td { padding:8px 10px; border-bottom:1px solid #f1f3f5; vertical-align:middle; }
.item-table input[type=number] { width:100px; padding:6px 8px; border:1px solid #d1d5db; border-radius:5px; }
.d2m-opt-remaining { color:#b45309; font-weight:600; }
.no-items { text-align:center; color:#9ca3af; padding:30px 0; }
</style>

<div class="mi-wrap">
  <h2 style="font-size:1.55rem;font-weight:700;color:#1e2a3b;">📥 Create Marketing Inward</h2>
  <p style="color:#6c757d;font-size:.9rem;">Receive a D2M's outstanding book lines into your goddam and record press vs. marketing quantities.</p>

  <?php if ($error): ?><div class="mi-alert">⚠ <?= htmlspecialchars($error) ?></div><?php endif; ?>

  <div class="mi-card">
    <form method="get" id="pickForm">
      <div class="form-grid">
        <div class="form-group">
          <label for="pick_d2m_id">D2M (outstanding lines only)</label>
          <select name="d2m_id" id="pick_d2m_id" required onchange="document.getElementById('pickForm').submit()">
            <option value="">-- Select D2M --</option>
            <?php foreach ($d2m_options as $d): $remaining = $d['total_items'] - $d['inwarded_items']; ?>
              <option value="<?= $d['id'] ?>" <?= $d2m_id === (int)$d['id'] ? 'selected' : '' ?>>
                <?= htmlspecialchars($d['d2m_no']) ?> — <?= htmlspecialchars($d['d2m_type']) ?> —
                <?= htmlspecialchars($d['status']) ?> —
                <?= (int)$d['inwarded_items'] ?>/<?= (int)$d['total_items'] ?> inwarded (<?= $remaining ?> remaining)
              </option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label for="pick_goddam_id">Goddam</label>
          <select name="goddam_id" id="pick_goddam_id" required onchange="document.getElementById('pickForm').submit()">
            <option value="">-- Select Goddam --</option>
            <?php foreach ($goddam_options as $g): ?>
              <option value="<?= $g['id'] ?>" <?= $goddam_id === (int)$g['id'] ? 'selected' : '' ?>><?= htmlspecialchars($g['code'] . ' - ' . $g['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
      </div>
    </form>

    <?php if (empty($goddam_options)): ?>
      <div class="no-items">
        <?php if ($is_admin): ?>
          No active goddams exist yet. <a href="<?= getUrl('goddam/index.php') ?>">Create one first</a>.
        <?php else: ?>
          You have no active goddam handler assignment yet — an admin needs to add one for you (with a class,
          book type, and today's date inside its active window) via
          <a href="<?= getUrl('goddam/handlers.php') ?>">Goddam → Handler Assignments</a> before you can create a
          Marketing Inward.
        <?php endif; ?>
      </div>
    <?php elseif (empty($d2m_options)): ?>
      <div class="no-items">
        No D2M currently has outstanding lines to inward — either none exist yet, or every one has
        already been fully received. Check <a href="<?= getUrl('d2m/index.php') ?>">D2M records</a>.
      </div>
    <?php endif; ?>

    <?php if ($d2m_id && $goddam_id && $d2m_row): ?>
      <?php if (empty($eligible_items)): ?>
        <div class="no-items">No eligible items for you on this D2M — either everything is already inwarded, or you don't have a class/type/date-window assignment (via Goddam → Handler Assignments) that covers this D2M's remaining lines.</div>
      <?php else: ?>
        <form method="post" id="inwardForm">
          <input type="hidden" name="d2m_id" value="<?= $d2m_id ?>">
          <input type="hidden" name="goddam_id" value="<?= $goddam_id ?>">

          <div class="form-grid">
            <div class="form-group">
              <label>Nepali Date</label>
              <div class="dual-date">
                <input class="bs-date" name="nep_date" value="<?= htmlspecialchars($today_bs) ?>" data-ad-pair="mi_eng_date" required>
                <input class="ad-date" name="eng_date" id="mi_eng_date" type="date" required>
              </div>
            </div>
            <div class="form-group">
              <label for="mi_remarks">Remarks</label>
              <input type="text" id="mi_remarks" name="remarks" placeholder="Optional">
            </div>
          </div>

          <table class="item-table">
            <thead>
              <tr>
                <th style="width:30px;"><input type="checkbox" id="checkAll" checked></th>
                <th>Book</th><th>Code</th><th>Class</th><th>Type</th><th>Press Qty</th><th>Marketing Qty</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($eligible_items as $it): ?>
              <tr>
                <td><input type="checkbox" class="item-check" data-item="<?= $it['d2m_item_id'] ?>" checked></td>
                <td><?= htmlspecialchars($it['book_name']) ?></td>
                <td><?= htmlspecialchars($it['book_code']) ?></td>
                <td><?= htmlspecialchars($it['class_level']) ?></td>
                <td><?= $it['is_translated'] ? 'T' : 'NT' ?></td>
                <td><?= number_format($it['press_qty']) ?></td>
                <td>
                  <input type="number" min="0" class="mqty-input" data-item="<?= $it['d2m_item_id'] ?>"
                         value="<?= (int)$it['press_qty'] ?>" required>
                </td>
              </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div style="margin-top:20px;">
            <button type="submit" class="btn btn-success">✓ Submit Marketing Inward</button>
          </div>
        </form>
      <?php endif; ?>
    <?php endif; ?>
  </div>
</div>

<script>
document.getElementById('checkAll')?.addEventListener('change', function() {
    document.querySelectorAll('.item-check').forEach(cb => cb.checked = this.checked);
});

document.getElementById('inwardForm')?.addEventListener('submit', function(e) {
    // Build items[<id>] inputs only for checked rows, right before submit.
    document.querySelectorAll('input[name^="items["]').forEach(el => el.remove());
    document.querySelectorAll('.item-check').forEach(function(cb) {
        if (!cb.checked) return;
        var itemId = cb.getAttribute('data-item');
        var qtyInput = document.querySelector('.mqty-input[data-item="' + itemId + '"]');
        var hidden = document.createElement('input');
        hidden.type = 'hidden';
        hidden.name = 'items[' + itemId + ']';
        hidden.value = qtyInput.value;
        e.target.appendChild(hidden);
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

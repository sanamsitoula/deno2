<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

/*
 * Book-code pipeline / bottleneck report (marketing_inward_plan.md §8.1).
 * Assembled from several staged queries rather than one SQL view — see the
 * plan doc for why (d2m_items.associated_deno_ids is a comma list, not a
 * join column, and one book_packing row can draw from more than one
 * job_ticket).
 */

$book_code = trim($_GET['book_code'] ?? '');
$all_books = $conn->query("SELECT book_code, book_name FROM books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

$stage = null;
if ($book_code !== '') {
    $bookStmt = $conn->prepare("SELECT * FROM books WHERE book_code = :c");
    $bookStmt->execute([':c' => $book_code]);
    $book = $bookStmt->fetch(PDO::FETCH_ASSOC);

    if ($book) {
        // 1. Job Ticket / Forma Printing
        $jt = $conn->prepare("
            SELECT COALESCE(SUM(jt.print_qty),0) AS planned_qty,
                   COALESCE(SUM(jt.print_done_qty),0) AS printed_qty,
                   COUNT(*) AS ticket_count
            FROM job_ticket jt WHERE jt.book_id = :bid
        ");
        $jt->execute([':bid' => $book['book_id']]);
        $jt_row = $jt->fetch(PDO::FETCH_ASSOC);

        // 2. Packing & Stitching
        $pk = $conn->prepare("
            SELECT COALESCE(SUM(p_qty),0) AS packed_qty, COUNT(*) AS record_count
            FROM book_packing WHERE book_code = :bc AND status = true
        ");
        $pk->execute([':bc' => $book_code]);
        $pk_row = $pk->fetch(PDO::FETCH_ASSOC);

        // 3. Deno (forma -> book entries)
        $dn = $conn->prepare("
            SELECT COALESCE(SUM(total_qty),0) AS deno_qty, COALESCE(SUM(quantity_openpcs),0) AS open_pcs, COUNT(*) AS entry_count
            FROM deno WHERE book_code = :bc AND deleted_at IS NULL
        ");
        $dn->execute([':bc' => $book_code]);
        $dn_row = $dn->fetch(PDO::FETCH_ASSOC);

        // 4. D2M (paperwork handover to Marketing)
        $d2 = $conn->prepare("
            SELECT COALESCE(SUM(di.total_qty),0) AS d2m_qty, COUNT(*) AS item_count
            FROM d2m_items di JOIN d2m d ON d.id = di.d2m_id
            WHERE di.book_code = :bc AND d.status <> 'CANCELLED'
        ");
        $d2->execute([':bc' => $book_code]);
        $d2_row = $d2->fetch(PDO::FETCH_ASSOC);

        // 5. Marketing Inward (physical receipt)
        $mi = $conn->prepare("
            SELECT COALESCE(SUM(mid.press_qty),0) AS press_qty, COALESCE(SUM(mid.marketing_qty),0) AS marketing_qty,
                   COALESCE(SUM(mid.mismatch_qty),0) AS mismatch_qty, COUNT(*) AS line_count
            FROM marketing_inward_details mid JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
            WHERE mid.book_code = :bc AND mi.status <> 'CANCELLED'
        ");
        $mi->execute([':bc' => $book_code]);
        $mi_row = $mi->fetch(PDO::FETCH_ASSOC);

        $gap_print_pack = (int)$jt_row['printed_qty'] - (int)$pk_row['packed_qty'];
        $gap_pack_deno  = (int)$pk_row['packed_qty'] - (int)$dn_row['deno_qty'];
        $gap_deno_d2m   = (int)$dn_row['deno_qty'] - (int)$d2_row['d2m_qty'];
        $gap_d2m_inward = (int)$d2_row['d2m_qty'] - (int)$mi_row['marketing_qty'];

        $gaps = ['Print → Pack' => $gap_print_pack, 'Pack → Deno' => $gap_pack_deno,
                 'Deno → D2M' => $gap_deno_d2m, 'D2M → Inward' => $gap_d2m_inward];
        arsort($gaps);
        $bottleneck_stage = array_key_first($gaps);
        $bottleneck_value = $gaps[$bottleneck_stage];

        $stage = compact('book', 'jt_row', 'pk_row', 'dn_row', 'd2_row', 'mi_row',
                          'gap_print_pack', 'gap_pack_deno', 'gap_deno_d2m', 'gap_d2m_inward',
                          'bottleneck_stage', 'bottleneck_value');
    }
}
?>
<style>
.report-filter{background:#f5f5f5;padding:15px;border-radius:5px;margin-bottom:20px;}
.filter-row{display:flex;align-items:flex-end;flex-wrap:wrap;gap:15px;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-weight:bold;font-size:12px;}
.filter-group input,.filter-group select{padding:8px;border:1px solid #ddd;border-radius:4px;min-width:220px;font-size:12px;}
.filter-group button{padding:8px 15px;background:#16a34a;color:#fff;border:none;border-radius:4px;cursor:pointer;}
.funnel{display:flex;gap:0;overflow-x:auto;margin-top:20px;align-items:stretch;}
.funnel-stage{flex:1;min-width:170px;background:#fff;border:2px solid #16a34a;border-radius:8px;padding:14px;text-align:center;}
.funnel-stage h4{font-size:13px;margin-bottom:8px;color:#166534;}
.funnel-stage .qty{font-size:22px;font-weight:800;color:#1e2a3b;}
.funnel-stage .sub{font-size:11px;color:#6c757d;margin-top:4px;}
.funnel-gap{display:flex;align-items:center;justify-content:center;min-width:90px;flex-direction:column;font-size:12px;font-weight:700;}
.funnel-gap .arrow{font-size:20px;color:#9ca3af;}
.gap-ok{color:#198754;}
.gap-bottleneck{color:#dc3545;background:#fee2e2;padding:4px 8px;border-radius:6px;}
.bottleneck-banner{margin-top:20px;padding:14px 18px;background:#fee2e2;border-left:4px solid #dc3545;border-radius:6px;color:#7f1d1d;font-weight:600;}
.bottleneck-banner.none{background:#d1fae5;border-left-color:#16a34a;color:#065f46;}
@media print{.report-filter,nav,header,footer{display:none!important;}}
</style>

<h2>📊 Book Pipeline / Bottleneck Report</h2>
<p style="color:#6c757d;">Traces one book code from forma printing through to Marketing Inward, and flags where quantity is piling up.</p>

<form method="get" class="report-filter">
  <div class="filter-row">
    <div class="filter-group">
      <label>Book Code</label>
      <select name="book_code" onchange="this.form.submit()">
        <option value="">-- Select a book --</option>
        <?php foreach ($all_books as $b): ?>
          <option value="<?= htmlspecialchars($b['book_code']) ?>" <?= $book_code===$b['book_code']?'selected':'' ?>>
            <?= htmlspecialchars($b['book_code'] . ' - ' . $b['book_name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group"><button type="submit">Load</button></div>
  </div>
</form>

<?php if ($stage): extract($stage); ?>
  <h3 style="margin-top:10px;"><?= htmlspecialchars($book['book_name']) ?> (<?= htmlspecialchars($book['book_code']) ?>, Class <?= htmlspecialchars($book['class_level']) ?>)</h3>

  <div class="funnel">
    <div class="funnel-stage">
      <h4>1. Job Ticket<br>(Forma Printing)</h4>
      <div class="qty"><?= number_format($jt_row['printed_qty']) ?></div>
      <div class="sub">planned <?= number_format($jt_row['planned_qty']) ?> · <?= $jt_row['ticket_count'] ?> ticket(s)</div>
    </div>
    <div class="funnel-gap">
      <div class="arrow">→</div>
      <div class="<?= $gap_print_pack > 0 ? 'gap-bottleneck' : 'gap-ok' ?>"><?= $gap_print_pack > 0 ? '+' : '' ?><?= number_format($gap_print_pack) ?></div>
    </div>
    <div class="funnel-stage">
      <h4>2. Packing &amp;<br>Stitching</h4>
      <div class="qty"><?= number_format($pk_row['packed_qty']) ?></div>
      <div class="sub"><?= $pk_row['record_count'] ?> record(s)</div>
    </div>
    <div class="funnel-gap">
      <div class="arrow">→</div>
      <div class="<?= $gap_pack_deno > 0 ? 'gap-bottleneck' : 'gap-ok' ?>"><?= $gap_pack_deno > 0 ? '+' : '' ?><?= number_format($gap_pack_deno) ?></div>
    </div>
    <div class="funnel-stage">
      <h4>3. Deno<br>(Forma→Book)</h4>
      <div class="qty"><?= number_format($dn_row['deno_qty']) ?></div>
      <div class="sub"><?= $dn_row['entry_count'] ?> entries · +<?= number_format($dn_row['open_pcs']) ?> open pcs</div>
    </div>
    <div class="funnel-gap">
      <div class="arrow">→</div>
      <div class="<?= $gap_deno_d2m > 0 ? 'gap-bottleneck' : 'gap-ok' ?>"><?= $gap_deno_d2m > 0 ? '+' : '' ?><?= number_format($gap_deno_d2m) ?></div>
    </div>
    <div class="funnel-stage">
      <h4>4. D2M<br>(Paperwork Handover)</h4>
      <div class="qty"><?= number_format($d2_row['d2m_qty']) ?></div>
      <div class="sub"><?= $d2_row['item_count'] ?> line(s)</div>
    </div>
    <div class="funnel-gap">
      <div class="arrow">→</div>
      <div class="<?= $gap_d2m_inward > 0 ? 'gap-bottleneck' : 'gap-ok' ?>"><?= $gap_d2m_inward > 0 ? '+' : '' ?><?= number_format($gap_d2m_inward) ?></div>
    </div>
    <div class="funnel-stage">
      <h4>5. Marketing Inward<br>(Physical Receipt)</h4>
      <div class="qty"><?= number_format($mi_row['marketing_qty']) ?></div>
      <div class="sub">press <?= number_format($mi_row['press_qty']) ?> · mismatch <?= number_format($mi_row['mismatch_qty']) ?></div>
    </div>
  </div>

  <?php if ($bottleneck_value > 0): ?>
    <div class="bottleneck-banner">
      ⚠ Bottleneck: <strong><?= htmlspecialchars($bottleneck_stage) ?></strong> is holding
      <strong><?= number_format($bottleneck_value) ?></strong> unit(s) that haven't moved to the next stage yet.
    </div>
  <?php else: ?>
    <div class="bottleneck-banner none">✓ No stage is currently holding back quantity — this book's pipeline is fully flowing through.</div>
  <?php endif; ?>

<?php elseif ($book_code !== ''): ?>
  <p style="color:#dc3545;margin-top:20px;">Book code not found.</p>
<?php endif; ?>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

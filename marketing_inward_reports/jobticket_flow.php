<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

/*
 * Job-ticket lead-time / cycle-time report (marketing_inward_plan.md §8.2).
 * Traces job_ticket -> forma printing -> packing -> deno -> d2m -> last
 * marketing_inward as TIMESTAMPS, and reports the day-count between each
 * hand-off so a slow stage is visible, complementing book_pipeline.php's
 * "how much quantity is stuck" view with "how long did each step take".
 */

function daysBetween($from, $to) {
    if (!$from || !$to) return null;
    try {
        $d1 = new DateTime($from);
        $d2 = new DateTime($to);
        return (int)$d1->diff($d2)->days * ($d2 >= $d1 ? 1 : -1);
    } catch (Exception $e) { return null; }
}

function computeFlow($conn, $jt) {
    $t0 = $jt['created_date'];

    $t1 = $conn->prepare("
        SELECT MAX(end_date) FROM job_ticket_details
        WHERE job_ticket_id = :id AND end_date ~ '^\\d{4}-\\d{2}-\\d{2}$'
    ");
    $t1->execute([':id' => $jt['id']]);
    $t1v = $t1->fetchColumn() ?: null;

    $t2 = $conn->prepare("SELECT MIN(created_date) FROM book_packing WHERE jt_id = :id");
    $t2->execute([':id' => $jt['id']]);
    $t2v = $t2->fetchColumn() ?: null;

    $t3 = $conn->prepare("SELECT MIN(created_at) FROM deno WHERE jt_id = :id AND deleted_at IS NULL");
    $t3->execute([':id' => $jt['id']]);
    $t3v = $t3->fetchColumn() ?: null;

    $d2mIds = $conn->prepare("
        SELECT DISTINCT d2m_id FROM deno WHERE jt_id = :id AND d2m_id IS NOT NULL
    ");
    $d2mIds->execute([':id' => $jt['id']]);
    $ids = $d2mIds->fetchAll(PDO::FETCH_COLUMN);

    $t4v = $t5v = $t6v = $t7v = null;
    if (!empty($ids)) {
        $in = implode(',', array_map('intval', $ids));
        $t4v = $conn->query("SELECT MIN(created_at) FROM d2m WHERE id IN ($in)")->fetchColumn() ?: null;
        $t5v = $conn->query("SELECT MIN(verified_at) FROM d2m WHERE id IN ($in)")->fetchColumn() ?: null;
        $t6v = $conn->query("SELECT MIN(created_at) FROM marketing_inward WHERE d2m_id IN ($in) AND status <> 'CANCELLED'")->fetchColumn() ?: null;
        $t7v = $conn->query("SELECT MAX(approved_at) FROM marketing_inward WHERE d2m_id IN ($in) AND status <> 'CANCELLED'")->fetchColumn() ?: null;
    }

    $durations = [
        'Printing duration'      => daysBetween($t0, $t1v),
        'Packing wait'           => daysBetween($t1v, $t2v),
        'Deno-entry wait'        => daysBetween($t2v, $t3v),
        'D2M-creation wait'      => daysBetween($t3v, $t4v),
        'D2M-verify wait'        => daysBetween($t4v, $t5v),
        'Inward-start wait'      => daysBetween($t5v, $t6v),
        'Inward-completion time' => daysBetween($t6v, $t7v),
    ];
    $total = daysBetween($t0, $t7v);

    $known = array_filter($durations, fn($v) => $v !== null);
    $bottleneck = null;
    if (!empty($known)) { arsort($known); $bottleneck = array_key_first($known); }

    return compact('t0','t1v','t2v','t3v','t4v','t5v','t6v','t7v','durations','total','bottleneck');
}

$search = trim($_GET['jt_code'] ?? '');
$threshold = (int)($_GET['threshold'] ?? 7);

$single = null;
if ($search !== '') {
    $stmt = $conn->prepare("SELECT jt.*, b.book_name FROM job_ticket jt JOIN books b ON b.book_id = jt.book_id WHERE jt.job_ticket_code = :c");
    $stmt->execute([':c' => $search]);
    $jt = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($jt) $single = ['jt' => $jt, 'flow' => computeFlow($conn, $jt)];
}

// Summary: most recent 50 job tickets, with total lead time + bottleneck stage.
$recent = $conn->query("
    SELECT jt.*, b.book_name FROM job_ticket jt
    JOIN books b ON b.book_id = jt.book_id
    ORDER BY jt.created_date DESC LIMIT 50
")->fetchAll(PDO::FETCH_ASSOC);

$summary = [];
foreach ($recent as $jt) {
    $summary[] = ['jt' => $jt, 'flow' => computeFlow($conn, $jt)];
}
?>
<style>
.report-filter{background:#f5f5f5;padding:15px;border-radius:5px;margin-bottom:20px;}
.filter-row{display:flex;align-items:flex-end;flex-wrap:wrap;gap:15px;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-weight:bold;font-size:12px;}
.filter-group input{padding:8px;border:1px solid #ddd;border-radius:4px;min-width:200px;font-size:12px;}
.filter-group button{padding:8px 15px;background:#16a34a;color:#fff;border:none;border-radius:4px;cursor:pointer;}
table{width:100%;border-collapse:collapse;margin-top:20px;font-size:11px;}
th,td{border:1px solid #000;padding:6px;text-align:center;}
th{background:#16a34a;color:#fff;font-size:10px;}
.na{color:#adb5bd;}
.slow{background:#fee2e2;color:#991b1b;font-weight:bold;}
.total-cell{font-weight:bold;background:#eafaf0;}
@media print{.report-filter,nav,header,footer{display:none!important;}}
</style>

<h2>⏱️ Job Ticket Flow &amp; Lead-Time Report</h2>
<p style="color:#6c757d;">Job ticket → forma printing → packing → deno → D2M → last Marketing Inward, with day-counts between each hand-off.</p>

<form method="get" class="report-filter">
  <div class="filter-row">
    <div class="filter-group"><label>Job Ticket Code</label>
      <input type="text" name="jt_code" value="<?= htmlspecialchars($search) ?>" placeholder="e.g. 2082-JT001">
    </div>
    <div class="filter-group"><button type="submit">Look Up</button></div>
  </div>
</form>

<?php if ($search !== '' && !$single): ?>
  <p style="color:#dc3545;">Job ticket code not found.</p>
<?php elseif ($single): $jt = $single['jt']; $f = $single['flow']; ?>
  <h3><?= htmlspecialchars($jt['job_ticket_code']) ?> — <?= htmlspecialchars($jt['book_name']) ?></h3>
  <table>
    <thead><tr><th>Created</th><th>Printing Done</th><th>Packing Done</th><th>First Deno</th><th>D2M Created</th><th>D2M Verified</th><th>Inward Started</th><th>Inward Approved</th><th>Total Days</th></tr></thead>
    <tbody>
      <tr>
        <td><?= $f['t0'] ? date('Y-m-d', strtotime($f['t0'])) : '-' ?></td>
        <td><?= $f['t1v'] ?: '<span class="na">—</span>' ?></td>
        <td><?= $f['t2v'] ? date('Y-m-d', strtotime($f['t2v'])) : '<span class="na">—</span>' ?></td>
        <td><?= $f['t3v'] ? date('Y-m-d', strtotime($f['t3v'])) : '<span class="na">—</span>' ?></td>
        <td><?= $f['t4v'] ? date('Y-m-d', strtotime($f['t4v'])) : '<span class="na">—</span>' ?></td>
        <td><?= $f['t5v'] ? date('Y-m-d', strtotime($f['t5v'])) : '<span class="na">—</span>' ?></td>
        <td><?= $f['t6v'] ? date('Y-m-d', strtotime($f['t6v'])) : '<span class="na">—</span>' ?></td>
        <td><?= $f['t7v'] ? date('Y-m-d', strtotime($f['t7v'])) : '<span class="na">—</span>' ?></td>
        <td class="total-cell"><?= $f['total'] !== null ? $f['total'] . 'd' : '<span class="na">in progress</span>' ?></td>
      </tr>
    </tbody>
  </table>

  <h4 style="margin-top:20px;">Stage durations</h4>
  <table>
    <thead><tr><?php foreach ($f['durations'] as $label => $v): ?><th><?= htmlspecialchars($label) ?></th><?php endforeach; ?></tr></thead>
    <tbody>
      <tr>
        <?php foreach ($f['durations'] as $label => $v): ?>
          <td class="<?= ($v !== null && $v >= $threshold) ? 'slow' : '' ?>"><?= $v !== null ? $v . 'd' : '<span class="na">—</span>' ?></td>
        <?php endforeach; ?>
      </tr>
    </tbody>
  </table>
  <?php if ($f['bottleneck']): ?>
    <p style="margin-top:10px;"><strong>Slowest stage:</strong> <?= htmlspecialchars($f['bottleneck']) ?> (<?= $f['durations'][$f['bottleneck']] ?>d)</p>
  <?php endif; ?>
<?php endif; ?>

<h3 style="margin-top:30px;">Recent Job Tickets — Lead Time Summary</h3>
<p style="font-size:12px;color:#6c757d;">Most recent 50 tickets. A stage cell is highlighted when it took ≥ <?= $threshold ?> days.</p>
<table>
  <thead><tr><th>Job Ticket</th><th>Book</th><th>Slowest Stage</th><th>Slowest Days</th><th>Total Lead Time</th></tr></thead>
  <tbody>
    <?php foreach ($summary as $row): $jt = $row['jt']; $f = $row['flow']; ?>
    <tr>
      <td><a href="?jt_code=<?= urlencode($jt['job_ticket_code']) ?>"><?= htmlspecialchars($jt['job_ticket_code']) ?></a></td>
      <td style="text-align:left;"><?= htmlspecialchars($jt['book_name']) ?></td>
      <td><?= $f['bottleneck'] ? htmlspecialchars($f['bottleneck']) : '<span class="na">—</span>' ?></td>
      <td class="<?= ($f['bottleneck'] && $f['durations'][$f['bottleneck']] >= $threshold) ? 'slow' : '' ?>">
        <?= $f['bottleneck'] ? $f['durations'][$f['bottleneck']] . 'd' : '<span class="na">—</span>' ?>
      </td>
      <td class="total-cell"><?= $f['total'] !== null ? $f['total'] . 'd' : '<span class="na">in progress</span>' ?></td>
    </tr>
    <?php endforeach; ?>
  </tbody>
</table>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

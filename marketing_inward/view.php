<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
redirect_if_not_logged_in();

$mi_id = $_GET['id'] ?? null;
if (!$mi_id) { header('Location: index.php'); exit; }

$stmt = $conn->prepare("
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
    WHERE mi.id = :id
");
$stmt->execute([':id' => $mi_id]);
$mi = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mi) { header('Location: index.php'); exit; }

$items_stmt = $conn->prepare("
    SELECT mid.*, b.book_name, jt.job_ticket_code
    FROM marketing_inward_details mid
    JOIN books b ON b.book_code = mid.book_code
    LEFT JOIN job_ticket jt ON jt.id = mid.job_ticket_id
    WHERE mid.marketing_inward_id = :id
    ORDER BY b.book_name
");
$items_stmt->execute([':id' => $mi_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';
?>

<style>
.view-container { max-width:1300px; margin:20px auto; background:#fff; border-radius:10px; box-shadow:0 4px 15px rgba(0,0,0,.1); overflow:hidden; }
.view-header { background:linear-gradient(135deg,#16a34a 0%,#059669 100%); color:#fff; padding:26px 30px; }
.action-bar { display:flex; justify-content:space-between; align-items:center; padding:16px 30px; background:#f8f9fa; border-bottom:2px solid #e9ecef; flex-wrap:wrap; gap:10px; }
.status-badge { padding:6px 14px; border-radius:16px; font-size:12px; font-weight:bold; text-transform:uppercase; }
.status-draft{background:#f8d7da;color:#721c24;} .status-checked{background:#fff3cd;color:#856404;}
.status-verified{background:#d4edda;color:#155724;} .status-approved{background:#cfe2ff;color:#084298;}
.status-cancelled{background:#d6d8db;color:#383d41;} .status-close{background:#d1ecf1;color:#0c5460;}
.info-section { padding:26px 30px; }
.info-grid { display:grid; grid-template-columns:repeat(auto-fit,minmax(220px,1fr)); gap:16px; margin-bottom:26px; }
.info-card { background:#f8f9fa; padding:14px 18px; border-radius:8px; border-left:4px solid #16a34a; }
.info-label { font-size:11px; color:#6c757d; text-transform:uppercase; margin-bottom:4px; }
.info-value { font-size:15px; font-weight:600; color:#333; }
.items-table { width:100%; border-collapse:collapse; margin-top:10px; font-size:13px; }
.items-table th,.items-table td { padding:10px; border-bottom:1px solid #dee2e6; text-align:left; }
.items-table th { background:#f8f9fa; font-size:11px; text-transform:uppercase; }
.mismatch-nonzero { color:#dc3545; font-weight:bold; } .mismatch-zero { color:#198754; }
.total-row td { background:#eaf7ee; font-weight:bold; border-top:2px solid #16a34a; }
.timeline-section { padding:26px 30px; background:#f8f9fa; border-top:2px solid #e9ecef; }
.timeline-item { display:flex; margin-bottom:20px; }
.timeline-icon { width:34px; height:34px; border-radius:50%; background:#16a34a; color:#fff; display:flex; align-items:center; justify-content:center; margin-right:16px; font-weight:bold; flex-shrink:0; }
.timeline-content { background:#fff; padding:12px 16px; border-radius:8px; border-left:3px solid #16a34a; flex:1; }
.btn { padding:9px 18px; border:none; border-radius:6px; font-weight:600; text-decoration:none; display:inline-block; cursor:pointer; }
.btn-secondary { background:#6c757d; color:#fff; } .btn-primary { background:#007bff; color:#fff; }
@media print { .action-bar { display:none; } }
</style>

<div class="view-container">
  <div class="view-header">
    <h2 style="margin:0 0 8px;">📥 Marketing Inward Details</h2>
    <div>Inward No: <?= htmlspecialchars($mi['inward_no']) ?> — from D2M <?= htmlspecialchars($mi['d2m_no']) ?></div>
  </div>

  <div class="action-bar">
    <span class="status-badge status-<?= strtolower($mi['status']) ?>"><?= $mi['status'] ?></span>
    <div>
      <a href="<?= getUrl('marketing_inward/index.php') ?>" class="btn btn-secondary">← Back</a>
      <a href="<?= getUrl('marketing_inward/print.php?id=' . $mi_id) ?>" target="_blank" class="btn btn-primary">🖨️ Print</a>
    </div>
  </div>

  <div class="info-section">
    <div class="info-grid">
      <div class="info-card"><div class="info-label">Goddam</div><div class="info-value"><?= htmlspecialchars($mi['goddam_code'] . ' - ' . $mi['goddam_name']) ?></div></div>
      <div class="info-card"><div class="info-label">Nepali Date</div><div class="info-value"><?= htmlspecialchars($mi['nep_date']) ?></div></div>
      <div class="info-card"><div class="info-label">English Date</div><div class="info-value"><?= date('F d, Y', strtotime($mi['eng_date'])) ?></div></div>
      <div class="info-card"><div class="info-label">Total Press Qty</div><div class="info-value"><?= number_format($mi['total_press_qty']) ?></div></div>
      <div class="info-card"><div class="info-label">Total Marketing Qty</div><div class="info-value"><?= number_format($mi['total_marketing_qty']) ?></div></div>
      <div class="info-card"><div class="info-label">Total Mismatch</div><div class="info-value <?= (int)$mi['total_mismatch_qty']!==0?'mismatch-nonzero':'mismatch-zero' ?>"><?= number_format($mi['total_mismatch_qty']) ?></div></div>
    </div>

    <?php if ($mi['remarks']): ?><div class="info-card" style="margin-bottom:20px;"><div class="info-label">Remarks</div><div class="info-value"><?= nl2br(htmlspecialchars($mi['remarks'])) ?></div></div><?php endif; ?>

    <h4>📚 Line Items (<?= count($items) ?>)</h4>
    <table class="items-table">
      <thead>
        <tr><th>Book</th><th>Code</th><th>Class</th><th>Job Ticket</th><th>Press Qty</th><th>Marketing Qty</th><th>Mismatch</th><th>Remarks</th></tr>
      </thead>
      <tbody>
        <?php foreach ($items as $it): ?>
        <tr>
          <td><?= htmlspecialchars($it['book_name']) ?></td>
          <td><?= htmlspecialchars($it['book_code']) ?></td>
          <td><?= htmlspecialchars($it['class_level']) ?></td>
          <td><?= htmlspecialchars($it['job_ticket_code'] ?? '-') ?></td>
          <td><?= number_format($it['press_qty']) ?></td>
          <td><?= number_format($it['marketing_qty']) ?></td>
          <td class="<?= (int)$it['mismatch_qty']!==0?'mismatch-nonzero':'mismatch-zero' ?>"><?= number_format($it['mismatch_qty']) ?></td>
          <td><?= htmlspecialchars($it['remarks'] ?? '') ?></td>
        </tr>
        <?php endforeach; ?>
        <tr class="total-row">
          <td colspan="4">TOTAL</td>
          <td><?= number_format($mi['total_press_qty']) ?></td>
          <td><?= number_format($mi['total_marketing_qty']) ?></td>
          <td><?= number_format($mi['total_mismatch_qty']) ?></td>
          <td></td>
        </tr>
      </tbody>
    </table>
  </div>

  <div class="timeline-section">
    <h4>⏱️ Status Timeline</h4>
    <div class="timeline-item">
      <div class="timeline-icon">✓</div>
      <div class="timeline-content"><strong>Created</strong><br>By: <?= htmlspecialchars($mi['created_by_name']) ?> (storekeeper) on <?= date('F d, Y g:i A', strtotime($mi['created_at'])) ?></div>
    </div>
    <?php if ($mi['checked_by_name']): ?>
    <div class="timeline-item">
      <div class="timeline-icon" style="background:#ffc107;">✓</div>
      <div class="timeline-content"><strong>Checked</strong><br>By: <?= htmlspecialchars($mi['checked_by_name']) ?> on <?= $mi['checked_at'] ? date('F d, Y g:i A', strtotime($mi['checked_at'])) : '-' ?></div>
    </div>
    <?php endif; ?>
    <?php if ($mi['verified_by_name']): ?>
    <div class="timeline-item">
      <div class="timeline-icon" style="background:#28a745;">✓</div>
      <div class="timeline-content"><strong>Verified</strong><br>By: <?= htmlspecialchars($mi['verified_by_name']) ?> on <?= $mi['verified_at'] ? date('F d, Y g:i A', strtotime($mi['verified_at'])) : '-' ?></div>
    </div>
    <?php endif; ?>
    <?php if ($mi['approved_by_name']): ?>
    <div class="timeline-item">
      <div class="timeline-icon" style="background:#0d6efd;">✓</div>
      <div class="timeline-content"><strong>Approved</strong> (by a different user from the creator/verifier)<br>By: <?= htmlspecialchars($mi['approved_by_name']) ?> on <?= $mi['approved_at'] ? date('F d, Y g:i A', strtotime($mi['approved_at'])) : '-' ?></div>
    </div>
    <?php endif; ?>
    <?php if ($mi['status'] === 'CLOSE'): ?>
    <div class="timeline-item">
      <div class="timeline-icon" style="background:#17a2b8;">🔒</div>
      <div class="timeline-content"><strong>Closed</strong></div>
    </div>
    <?php endif; ?>
  </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

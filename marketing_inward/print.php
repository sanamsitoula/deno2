<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

$mi_id = $_GET['id'] ?? null;
if (!$mi_id) die("Marketing Inward ID is required");

$stmt = $conn->prepare("
    SELECT mi.*, g.code AS goddam_code, g.name AS goddam_name, d.d2m_no,
           u_created.username AS created_by_name, u_verified.username AS verified_by_name,
           u_approved.username AS approved_by_name
    FROM marketing_inward mi
    JOIN goddam g ON g.id = mi.goddam_id
    JOIN d2m d ON d.id = mi.d2m_id
    LEFT JOIN users u_created ON mi.created_by = u_created.id
    LEFT JOIN users u_verified ON mi.verified_by = u_verified.id
    LEFT JOIN users u_approved ON mi.approved_by = u_approved.id
    WHERE mi.id = :id
");
$stmt->execute([':id' => $mi_id]);
$mi = $stmt->fetch(PDO::FETCH_ASSOC);
if (!$mi) die("Marketing Inward record not found");

$items_stmt = $conn->prepare("
    SELECT mid.*, b.book_name
    FROM marketing_inward_details mid
    JOIN books b ON b.book_code = mid.book_code
    WHERE mid.marketing_inward_id = :id ORDER BY b.book_name
");
$items_stmt->execute([':id' => $mi_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);
?>
<!DOCTYPE html>
<html lang="ne">
<head>
<meta charset="UTF-8">
<title>Marketing Inward Report - <?= htmlspecialchars($mi['inward_no']) ?></title>
<style>
*,*::before,*::after{margin:0;padding:0;box-sizing:border-box;}
@page{size:A4 portrait;margin:8mm;}
body{font-family:Arial,sans-serif;font-size:11px;color:#000;}
.report-header{text-align:center;border-bottom:2px solid #000;padding-bottom:6px;margin-bottom:6px;}
.company-name{font-size:17px;font-weight:900;}
.company-name-english{font-size:13px;font-weight:800;margin-bottom:2px;}
.report-title{font-size:13px;font-weight:bold;}
.report-info{border:1.5px solid #000;padding:5px 8px;background:#f9f9f9;font-size:10px;margin-bottom:6px;}
.info-row{display:flex;justify-content:space-between;gap:10px;margin:2px 0;}
.info-label{font-weight:bold;}
table.main-table{width:100%;border-collapse:collapse;font-size:11px;}
table.main-table th,table.main-table td{border:1px solid #000;padding:4px 5px;text-align:center;}
table.main-table th{background:#e0e0e0;font-weight:bold;font-size:10px;}
.total-row td{background:#d8eef8;font-weight:bold;border-top:2px solid #000;}
.mismatch-nonzero{color:#c0392b;font-weight:bold;}
.signature-section{margin-top:20px;}
.signature-row{display:flex;justify-content:space-between;gap:10px;}
.signature-item{flex:1;text-align:center;}
.signature-line{border-bottom:1px solid #000;height:26px;margin:10px 5px;}
.signature-label{font-size:9px;color:#555;}
.print-button{position:fixed;top:12px;right:14px;padding:8px 18px;background:#16a34a;color:#fff;border:none;border-radius:6px;cursor:pointer;}
@media print{.print-button{display:none;}}
</style>
</head>
<body>
<button class="print-button" onclick="window.print()">🖨️ Print</button>

<div class="report-header">
  <div class="company-name">जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
  <div class="company-name-english">Janak Education Materials Centre Ltd.</div>
  <div class="report-title">Marketing Inward Report</div>
</div>

<div class="report-info">
  <div class="info-row">
    <span><span class="info-label">Inward No:</span> <?= htmlspecialchars($mi['inward_no']) ?></span>
    <span><span class="info-label">D2M No:</span> <?= htmlspecialchars($mi['d2m_no']) ?></span>
    <span><span class="info-label">Goddam:</span> <?= htmlspecialchars($mi['goddam_code']) ?></span>
    <span><span class="info-label">Status:</span> <?= htmlspecialchars($mi['status']) ?></span>
  </div>
  <div class="info-row">
    <span><span class="info-label">Nepali Date:</span> <?= htmlspecialchars($mi['nep_date']) ?></span>
    <span><span class="info-label">English Date:</span> <?= date('Y-m-d', strtotime($mi['eng_date'])) ?></span>
    <span><span class="info-label">Created By:</span> <?= htmlspecialchars($mi['created_by_name'] ?? '-') ?></span>
  </div>
</div>

<table class="main-table">
<thead><tr><th>SN</th><th>Book Name</th><th>Code</th><th>Class</th><th>Press Qty</th><th>Marketing Qty</th><th>Mismatch</th></tr></thead>
<tbody>
<?php $sn=1; foreach ($items as $it): ?>
<tr>
  <td><?= $sn++ ?></td>
  <td style="text-align:left;"><?= htmlspecialchars($it['book_name']) ?></td>
  <td><?= htmlspecialchars($it['book_code']) ?></td>
  <td><?= htmlspecialchars($it['class_level']) ?></td>
  <td><?= number_format($it['press_qty']) ?></td>
  <td><?= number_format($it['marketing_qty']) ?></td>
  <td class="<?= (int)$it['mismatch_qty']!==0?'mismatch-nonzero':'' ?>"><?= number_format($it['mismatch_qty']) ?></td>
</tr>
<?php endforeach; ?>
<tr class="total-row">
  <td colspan="4">TOTAL</td>
  <td><?= number_format($mi['total_press_qty']) ?></td>
  <td><?= number_format($mi['total_marketing_qty']) ?></td>
  <td><?= number_format($mi['total_mismatch_qty']) ?></td>
</tr>
</tbody>
</table>

<div class="signature-section">
  <div class="signature-row">
    <div class="signature-item">
      <p><strong>Storekeeper (Created By)</strong></p>
      <div class="signature-line"></div>
      <p class="signature-label"><?= htmlspecialchars($mi['created_by_name'] ?? '') ?></p>
    </div>
    <div class="signature-item">
      <p><strong>Verified By</strong></p>
      <div class="signature-line"></div>
      <p class="signature-label"><?= htmlspecialchars($mi['verified_by_name'] ?? '') ?></p>
    </div>
    <div class="signature-item">
      <p><strong>Approved By</strong></p>
      <div class="signature-line"></div>
      <p class="signature-label"><?= htmlspecialchars($mi['approved_by_name'] ?? '') ?></p>
    </div>
  </div>
</div>

<script>
window.addEventListener('load', function() { setTimeout(function(){ window.print(); }, 350); });
</script>
</body>
</html>

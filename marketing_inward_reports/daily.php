<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

use Administrator\Deno2\Shared\DateConverter;

$todayBs      = str_replace('-', '.', DateConverter::todayBs());
$date         = $_GET['date'] ?? $todayBs;
$goddam_id    = $_GET['goddam_id'] ?? '';
$status_filter = $_GET['status_filter'] ?? '';

$query = "
    SELECT mid.*, mi.inward_no, mi.nep_date, mi.status, g.code AS goddam_code, b.book_name
    FROM marketing_inward_details mid
    JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
    JOIN goddam g ON g.id = mi.goddam_id
    JOIN books b ON b.book_code = mid.book_code
    WHERE mi.nep_date = :date AND mi.status <> 'CANCELLED'
";
$params = [':date' => $date];
if (!empty($goddam_id)) { $query .= " AND mi.goddam_id = :gid"; $params[':gid'] = $goddam_id; }
if (!empty($status_filter)) { $query .= " AND mi.status = :status"; $params[':status'] = $status_filter; }
$query .= " ORDER BY g.code, b.book_name";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_press = array_sum(array_column($records, 'press_qty'));
$total_mkt   = array_sum(array_column($records, 'marketing_qty'));
$total_mis   = array_sum(array_column($records, 'mismatch_qty'));

$goddams = $conn->query("SELECT id, code, name FROM goddam ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
$mi_docs = array_unique(array_column($records, 'inward_no'));
?>
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet" type="text/css"/>
<style>
.report-filter{background:#f5f5f5;padding:15px;border-radius:5px;margin-bottom:20px;}
.filter-row{display:flex;align-items:flex-end;flex-wrap:wrap;gap:15px;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-weight:bold;font-size:12px;}
.filter-group input,.filter-group select{padding:8px;border:1px solid #ddd;border-radius:4px;min-width:140px;font-size:12px;}
.filter-group button{padding:8px 15px;background:#16a34a;color:#fff;border:none;border-radius:4px;cursor:pointer;}
.report-summary{background:#eafaf0;padding:15px;border-radius:5px;margin-bottom:20px;border-left:4px solid #16a34a;}
table{width:100%;border-collapse:collapse;margin-top:20px;font-size:11px;}
th,td{border:1px solid #000;padding:6px;text-align:center;}
th{background:#16a34a;color:#fff;font-size:10px;}
.total-row{background:#d8f3e0!important;font-weight:bold;}
.mismatch-nonzero{color:#dc3545;font-weight:bold;}
.btn-print,.btn-export,.btn-excel{padding:10px 20px;margin-right:10px;border:none;border-radius:5px;cursor:pointer;font-size:14px;color:#fff;}
.btn-print{background:#28a745;} .btn-export{background:#17a2b8;} .btn-excel{background:#007bff;}
@media print{.report-filter,.print-actions,nav,header,footer{display:none!important;}}
</style>

<h2>दैनिक Marketing Inward विवरण (Daily Marketing Inward Report)</h2>

<form method="get" id="filterForm" class="report-filter" autocomplete="off">
  <div class="filter-row">
    <div class="filter-group"><label>Date (YYYY.MM.DD):</label>
      <input type="text" name="date" id="date" class="ndp-date-filter" value="<?= htmlspecialchars($date) ?>" readonly>
    </div>
    <div class="filter-group"><label>Goddam:</label>
      <select name="goddam_id"><option value="">All</option>
        <?php foreach ($goddams as $g): ?><option value="<?= $g['id'] ?>" <?= (string)$goddam_id===(string)$g['id']?'selected':'' ?>><?= htmlspecialchars($g['code']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group"><label>Status:</label>
      <select name="status_filter">
        <option value="">All</option>
        <?php foreach (['DRAFT','CHECKED','VERIFIED','APPROVED','CLOSE'] as $s): ?>
          <option value="<?= $s ?>" <?= $status_filter===$s?'selected':'' ?>><?= $s ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group"><button type="submit">Generate</button></div>
  </div>
</form>

<div class="report-summary">
  <p><strong>Date:</strong> <?= htmlspecialchars($date) ?></p>
  <p><strong>Marketing Inward Docs:</strong> <?= count($mi_docs) ?> | <strong>Line Items:</strong> <?= count($records) ?></p>
  <p><strong>Total Press Qty:</strong> <?= number_format($total_press) ?> | <strong>Total Marketing Qty:</strong> <?= number_format($total_mkt) ?> | <strong>Total Mismatch:</strong> <?= number_format($total_mis) ?></p>
</div>

<?php if (empty($records)): ?>
  <p style="text-align:center;color:#666;padding:30px;">No data found for <?= htmlspecialchars($date) ?>.</p>
<?php else: ?>
<table id="reportTable">
  <thead><tr><th>SN</th><th>Inward No</th><th>Goddam</th><th>Book Name</th><th>Code</th><th>Class</th><th>Press Qty</th><th>Marketing Qty</th><th>Mismatch</th><th>Status</th></tr></thead>
  <tbody>
    <?php $sn=1; foreach ($records as $r): ?>
    <tr>
      <td><?= $sn++ ?></td><td><?= htmlspecialchars($r['inward_no']) ?></td><td><?= htmlspecialchars($r['goddam_code']) ?></td>
      <td style="text-align:left"><?= htmlspecialchars($r['book_name']) ?></td><td><?= htmlspecialchars($r['book_code']) ?></td>
      <td><?= htmlspecialchars($r['class_level']) ?></td><td><?= number_format($r['press_qty']) ?></td>
      <td><?= number_format($r['marketing_qty']) ?></td>
      <td class="<?= (int)$r['mismatch_qty']!==0?'mismatch-nonzero':'' ?>"><?= number_format($r['mismatch_qty']) ?></td>
      <td><?= htmlspecialchars($r['status']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row"><td colspan="6">TOTAL</td><td><?= number_format($total_press) ?></td><td><?= number_format($total_mkt) ?></td><td><?= number_format($total_mis) ?></td><td></td></tr>
  </tbody>
</table>
<div class="print-actions" style="margin-top:20px;">
  <button onclick="printReport()" class="btn-print">🖨️ Print</button>
  <button onclick="exportToCSV()" class="btn-export">📊 CSV</button>
  <button onclick="exportToExcel()" class="btn-excel">📋 Excel</button>
</div>
<?php endif; ?>

<script>
function exportToCSV() {
    var d = document.getElementById('date').value;
    var csv = "﻿" + `"Daily Marketing Inward Report - ${d}"\n\n`;
    csv += "SN,Inward No,Goddam,Book Name,Code,Class,Press Qty,Marketing Qty,Mismatch,Status\n";
    document.querySelectorAll("#reportTable tbody tr:not(.total-row)").forEach(function(row) {
        var cells = Array.from(row.querySelectorAll("td")).map(c => {
            var t = c.textContent.trim();
            return (t.includes(',') || t.includes('"')) ? '"' + t.replace(/"/g,'""') + '"' : t;
        });
        csv += cells.join(",") + "\n";
    });
    var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = `mi_daily_${d}.csv`;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
function exportToExcel() {
    var d = document.getElementById('date').value;
    var html = `<html><head><meta charset="UTF-8"></head><body><table>${document.getElementById('reportTable').innerHTML}</table></body></html>`;
    var blob = new Blob([html], {type:'application/vnd.ms-excel;charset=utf-8;'});
    var a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = `mi_daily_${d}.xls`;
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
function printReport() { window.print(); }
document.addEventListener('DOMContentLoaded', function() {
    var f = document.getElementById('date');
    f.NepaliDatePicker({ dateFormat:'YYYY.MM.DD', onDateSelect:function(){ document.getElementById('filterForm').submit(); } });
    f.removeAttribute('readonly');
    document.querySelectorAll('#filterForm select').forEach(s => s.addEventListener('change', () => document.getElementById('filterForm').submit()));
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

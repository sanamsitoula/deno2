<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$fiscal_years = $conn->query("SELECT id, fiscal_code, fiscal_name, is_active FROM fiscal_years ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);
$active_fy = null;
foreach ($fiscal_years as $fy) { if ($fy['is_active']) { $active_fy = $fy; break; } }

$selected_fy_id = $_GET['fy_id'] ?? ($active_fy['id'] ?? '');
$selected_month = $_GET['month'] ?? '';   // BS month number 01-12
$goddam_id      = $_GET['goddam_id'] ?? '';

$fiscal_month_order = ['04'=>'Shrawan','05'=>'Bhadra','06'=>'Ashwin','07'=>'Kartik','08'=>'Mangsir','09'=>'Poush',
                        '10'=>'Magh','11'=>'Falgun','12'=>'Chaitra','01'=>'Baisakh','02'=>'Jestha','03'=>'Ashadh'];

$selected_fy_code = '';
foreach ($fiscal_years as $fy) if ((string)$fy['id'] === (string)$selected_fy_id) $selected_fy_code = $fy['fiscal_code'];

$records = [];
$bs_prefix = '';
if ($selected_fy_code && $selected_month) {
    // Months 01-03 fall in the fiscal year following fiscal_code (Baisakh–Ashadh);
    // 04-12 fall within fiscal_code itself (Shrawan–Chaitra).
    $bs_year = (in_array($selected_month, ['01','02','03'], true)) ? ((int)$selected_fy_code + 1) : (int)$selected_fy_code;
    $bs_prefix = $bs_year . '.' . $selected_month . '.';

    $sql = "
        SELECT b.book_name, mid.book_code, mid.class_level, g.code AS goddam_code,
               SUM(mid.press_qty) AS press_qty, SUM(mid.marketing_qty) AS marketing_qty,
               SUM(mid.mismatch_qty) AS mismatch_qty, COUNT(DISTINCT mi.id) AS doc_count
        FROM marketing_inward_details mid
        JOIN marketing_inward mi ON mi.id = mid.marketing_inward_id
        JOIN goddam g ON g.id = mi.goddam_id
        JOIN books b ON b.book_code = mid.book_code
        WHERE mi.nep_date LIKE :prefix AND mi.status <> 'CANCELLED'
    ";
    $params = [':prefix' => $bs_prefix . '%'];
    if (!empty($goddam_id)) { $sql .= " AND mi.goddam_id = :gid"; $params[':gid'] = $goddam_id; }
    $sql .= " GROUP BY b.book_name, mid.book_code, mid.class_level, g.code ORDER BY g.code, b.book_name";

    $stmt = $conn->prepare($sql);
    $stmt->execute($params);
    $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
}

$total_press = array_sum(array_column($records, 'press_qty'));
$total_mkt   = array_sum(array_column($records, 'marketing_qty'));
$total_mis   = array_sum(array_column($records, 'mismatch_qty'));
$goddams = $conn->query("SELECT id, code FROM goddam ORDER BY code")->fetchAll(PDO::FETCH_ASSOC);
?>
<style>
.report-filter{background:#f5f5f5;padding:15px;border-radius:5px;margin-bottom:20px;}
.filter-row{display:flex;align-items:flex-end;flex-wrap:wrap;gap:15px;}
.filter-group{display:flex;flex-direction:column;gap:5px;}
.filter-group label{font-weight:bold;font-size:12px;}
.filter-group select{padding:8px;border:1px solid #ddd;border-radius:4px;min-width:140px;font-size:12px;}
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

<h2>मासिक Marketing Inward विवरण (Monthly Marketing Inward Report)</h2>

<form method="get" class="report-filter">
  <div class="filter-row">
    <div class="filter-group"><label>Fiscal Year:</label>
      <select name="fy_id">
        <?php foreach ($fiscal_years as $fy): ?>
          <option value="<?= $fy['id'] ?>" <?= (string)$selected_fy_id===(string)$fy['id']?'selected':'' ?>><?= htmlspecialchars($fy['fiscal_name'] ?? $fy['fiscal_code']) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group"><label>Month:</label>
      <select name="month">
        <option value="">-- Select --</option>
        <?php foreach ($fiscal_month_order as $num => $name): ?>
          <option value="<?= $num ?>" <?= $selected_month===$num?'selected':'' ?>><?= $name ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group"><label>Goddam:</label>
      <select name="goddam_id"><option value="">All</option>
        <?php foreach ($goddams as $g): ?><option value="<?= $g['id'] ?>" <?= (string)$goddam_id===(string)$g['id']?'selected':'' ?>><?= htmlspecialchars($g['code']) ?></option><?php endforeach; ?>
      </select>
    </div>
    <div class="filter-group"><button type="submit">Generate</button></div>
  </div>
</form>

<?php if ($selected_month): ?>
<div class="report-summary">
  <p><strong>Period:</strong> BS <?= htmlspecialchars($bs_prefix) ?>* (<?= htmlspecialchars($fiscal_month_order[$selected_month] ?? '') ?>)</p>
  <p><strong>Total Press Qty:</strong> <?= number_format($total_press) ?> | <strong>Total Marketing Qty:</strong> <?= number_format($total_mkt) ?> | <strong>Total Mismatch:</strong> <?= number_format($total_mis) ?></p>
</div>

<?php if (empty($records)): ?>
  <p style="text-align:center;color:#666;padding:30px;">No data found for this period.</p>
<?php else: ?>
<table id="reportTable">
  <thead><tr><th>SN</th><th>Goddam</th><th>Book Name</th><th>Code</th><th>Class</th><th>Docs</th><th>Press Qty</th><th>Marketing Qty</th><th>Mismatch</th></tr></thead>
  <tbody>
    <?php $sn=1; foreach ($records as $r): ?>
    <tr>
      <td><?= $sn++ ?></td><td><?= htmlspecialchars($r['goddam_code']) ?></td>
      <td style="text-align:left"><?= htmlspecialchars($r['book_name']) ?></td><td><?= htmlspecialchars($r['book_code']) ?></td>
      <td><?= htmlspecialchars($r['class_level']) ?></td><td><?= $r['doc_count'] ?></td>
      <td><?= number_format($r['press_qty']) ?></td><td><?= number_format($r['marketing_qty']) ?></td>
      <td class="<?= (int)$r['mismatch_qty']!==0?'mismatch-nonzero':'' ?>"><?= number_format($r['mismatch_qty']) ?></td>
    </tr>
    <?php endforeach; ?>
    <tr class="total-row"><td colspan="6">TOTAL</td><td><?= number_format($total_press) ?></td><td><?= number_format($total_mkt) ?></td><td><?= number_format($total_mis) ?></td></tr>
  </tbody>
</table>
<div class="print-actions" style="margin-top:20px;">
  <button onclick="window.print()" class="btn-print">🖨️ Print</button>
  <button onclick="exportToCSV()" class="btn-export">📊 CSV</button>
  <button onclick="exportToExcel()" class="btn-excel">📋 Excel</button>
</div>
<?php endif; endif; ?>

<script>
function exportToCSV() {
    var csv = "﻿\"Monthly Marketing Inward Report\"\n\nSN,Goddam,Book Name,Code,Class,Docs,Press Qty,Marketing Qty,Mismatch\n";
    document.querySelectorAll("#reportTable tbody tr:not(.total-row)").forEach(function(row) {
        var cells = Array.from(row.querySelectorAll("td")).map(c => { var t=c.textContent.trim(); return t.includes(',')?'"'+t+'"':t; });
        csv += cells.join(",") + "\n";
    });
    var blob = new Blob([csv], {type:'text/csv;charset=utf-8;'});
    var a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = 'mi_monthly.csv';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
function exportToExcel() {
    var html = `<html><head><meta charset="UTF-8"></head><body><table>${document.getElementById('reportTable').innerHTML}</table></body></html>`;
    var blob = new Blob([html], {type:'application/vnd.ms-excel;charset=utf-8;'});
    var a = document.createElement("a"); a.href = URL.createObjectURL(blob); a.download = 'mi_monthly.xls';
    document.body.appendChild(a); a.click(); document.body.removeChild(a);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

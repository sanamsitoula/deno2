<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

use Administrator\Deno2\Shared\DateConverter;

/* ================= PARAMETERS ================= */
$todayBs            = str_replace('-', '.', DateConverter::todayBs());
$date               = $_GET['date']               ?? $todayBs;
$translation_filter = $_GET['translation_filter'] ?? 'all';
$book_code_filter   = $_GET['book_code_filter']   ?? '';
$class_filter       = $_GET['class_filter']       ?? '';
$status_filter      = $_GET['status_filter']      ?? '';

/* ================= QUERY ================= */
$query = "
    SELECT di.id as item_id,
           di.book_code,
           di.per_poka_qty,
           di.total_poka_qty,
           di.total_qty,
           di.open_pcs,
           d.id as d2m_id,
           d.d2m_no,
           d.d2m_type,
           d.status,
           d.nep_date,
           b.book_name,
           b.class_level,
           b.is_translated
    FROM d2m_items di
    JOIN d2m d ON di.d2m_id = d.id
    JOIN books b ON di.book_code = b.book_code
    WHERE d.nep_date = :date
      AND d.status <> 'CANCELLED'
      AND d.deleted_at IS NULL
";
$params = [':date' => $date];

if ($translation_filter === 'translated')         $query .= " AND b.is_translated = TRUE";
elseif ($translation_filter === 'non_translated') $query .= " AND b.is_translated = FALSE";

if (!empty($book_code_filter)) {
    $query .= " AND di.book_code LIKE :book_code";
    $params[':book_code'] = '%' . $book_code_filter . '%';
}
if (!empty($class_filter)) {
    $query .= " AND b.class_level = :class_level";
    $params[':class_level'] = $class_filter;
}
if (!empty($status_filter)) {
    $query .= " AND d.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY d.d2m_type, d.d2m_no, b.book_name";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_poka_qty = array_sum(array_column($records, 'total_poka_qty'));
$total_qty      = array_sum(array_column($records, 'total_qty'));
$total_openpcs  = array_sum(array_column($records, 'open_pcs'));

$filter_display_map = [
    'translated'     => ' (अनुवादित मात्र / Translated Only)',
    'non_translated' => ' (गैर-अनुवादित मात्र / Non-Translated Only)',
];
$filter_display = $filter_display_map[$translation_filter] ?? ' (सबै / All Books)';

/* ================= DROPDOWN DATA ================= */
$class_options = $conn->query("SELECT DISTINCT class_level FROM books WHERE class_level IS NOT NULL AND class_level > 0 ORDER BY class_level")->fetchAll(PDO::FETCH_COLUMN);
$all_books     = $conn->query("SELECT book_code, book_name FROM books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);

/* Unique D2M documents for summary count */
$d2m_docs = array_unique(array_column($records, 'd2m_no'));
?>

<!-- Nepali Datepicker v5 -->
<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet" type="text/css"/>

<style>
.report-filter { background:#f5f5f5; padding:15px; border-radius:5px; margin-bottom:20px; }
.filter-row    { display:flex; align-items:flex-end; flex-wrap:wrap; gap:15px; }
.filter-group  { display:flex; flex-direction:column; gap:5px; }
.filter-group label { font-weight:bold; white-space:nowrap; font-size:12px; }
.filter-group input,.filter-group select {
    padding:8px; border:1px solid #ddd; border-radius:4px;
    min-width:120px; font-size:12px; font-family:inherit; box-sizing:border-box;
}
.filter-group input:focus,.filter-group select:focus { outline:none; border-color:#007cba; box-shadow:0 0 0 2px rgba(0,124,186,.18); }
.filter-group button {
    padding:8px 15px; background:#6f42c1; color:#fff; border:none; border-radius:4px;
    cursor:pointer; white-space:nowrap; font-size:12px; align-self:flex-end;
}
.filter-group button:hover { background:#5a32a3; }
.ndp-date-filter {
    padding:8px; border:1px solid #ddd; border-radius:4px;
    min-width:135px; font-size:12px; font-family:inherit;
    background:#fff; box-sizing:border-box; width:135px;
}
.ndp-date-filter:focus { outline:none; border-color:#007cba; box-shadow:0 0 0 2px rgba(0,124,186,.18); }

.report-summary { background:#f3eefc; padding:15px; border-radius:5px; margin-bottom:20px; border-left:4px solid #6f42c1; }
.report-summary p { margin:5px 0; font-size:13px; }
.no-data { text-align:center; padding:40px; background:#f9f9f9; border-radius:5px; color:#666; }

table { width:100%; border-collapse:collapse; margin-top:20px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; font-size:11px; }
th,td { border:1px solid #000; padding:6px; text-align:center; vertical-align:middle; }
th { background-color:#6f42c1; color:#fff; font-weight:bold; font-size:10px; line-height:1.2; }
tr:nth-child(even) { background-color:#f9f5ff; }
.total-row { background-color:#e2d5f0 !important; font-weight:bold; }
.total-row td { border-top:2px solid #000; }
.book-type { font-size:9px; padding:4px; line-height:1.1; }
.book-type.translated     { background-color:#e8f5e8; color:#2d5a2d; }
.book-type.non-translated { background-color:#f0f8ff; color:#1e3a8a; }
.d2m-no-cell { font-size:10px; font-weight:600; color:#6f42c1; }
.status-badge-sm { padding:2px 6px; border-radius:3px; font-size:9px; font-weight:bold; text-transform:uppercase; }
.status-draft-sm { background:#f8d7da; color:#721c24; }
.status-checked-sm { background:#fff3cd; color:#856404; }
.status-verified-sm { background:#d4edda; color:#155724; }
.status-close-sm { background:#d1ecf1; color:#0c5460; }

.btn-print  { padding:10px 20px; margin-right:10px; border:none; border-radius:5px; cursor:pointer; font-size:14px; background:#28a745; color:#fff; }
.btn-export { padding:10px 20px; margin-right:10px; border:none; border-radius:5px; cursor:pointer; font-size:14px; background:#17a2b8; color:#fff; }
.btn-excel  { padding:10px 20px; margin-right:10px; border:none; border-radius:5px; cursor:pointer; font-size:14px; background:#007bff; color:#fff; }
.btn-print:hover,.btn-export:hover,.btn-excel:hover { opacity:.8; }

@media print {
    .report-filter,.print-actions,nav,header,footer,.no-print { display:none !important; }
    * { margin:0!important; padding:0!important; }
    @page { margin:0!important; size:A4 landscape; }
    body { font-size:9px!important; margin:0!important; padding:3px!important; line-height:1.0; }
    h2 { font-size:14px!important; margin-bottom:8px!important; text-align:center; }
    table { font-size:8px!important; width:100%; margin-top:5px!important; }
    th,td { padding:2px!important; font-size:8px!important; border:1px solid #000; }
    .report-summary { background:none; border:1px solid #000; padding:5px!important; margin:5px 0!important; font-size:8px!important; }
    th { background:#6f42c1!important; }
}
</style>

<h2>दैनिक D2M विवरण (Daily D2M Dispatch Report)<?= $filter_display ?></h2>

<form method="get" id="filterForm" class="report-filter" autocomplete="off">
    <div class="filter-row">
        <div class="filter-group">
            <label for="date">मिति (Date) (YYYY.MM.DD):</label>
            <input type="text" name="date" id="date" class="ndp-date-filter"
                   placeholder="2082.01.15" value="<?= htmlspecialchars($date) ?>" readonly>
        </div>

        <div class="filter-group">
            <label for="translation_filter">पुस्तक प्रकार (Book Type):</label>
            <select name="translation_filter" id="translation_filter">
                <option value="all"            <?= $translation_filter==='all'            ?'selected':'' ?>>सबै (All Books)</option>
                <option value="translated"     <?= $translation_filter==='translated'     ?'selected':'' ?>>अनुवादित (Translated)</option>
                <option value="non_translated" <?= $translation_filter==='non_translated' ?'selected':'' ?>>गैर-अनुवादित (Non-Translated)</option>
            </select>
        </div>

        <div class="filter-group">
            <label for="book_code_filter">पुस्तक (Book Code):</label>
            <input type="text" name="book_code_filter" id="book_code_filter"
                   value="<?= htmlspecialchars($book_code_filter) ?>" placeholder="Book code">
        </div>

        <div class="filter-group">
            <label for="class_filter">कक्षा (Class):</label>
            <select name="class_filter" id="class_filter">
                <option value="">सबै (All Classes)</option>
                <?php foreach ($class_options as $co): ?>
                <option value="<?= htmlspecialchars($co) ?>" <?= $class_filter===$co?'selected':'' ?>><?= htmlspecialchars($co) ?></option>
                <?php endforeach; ?>
            </select>
        </div>

        <div class="filter-group">
            <label for="status_filter">D2M Status:</label>
            <select name="status_filter" id="status_filter">
                <option value="">All Status</option>
                <option value="DRAFT"    <?= $status_filter==='DRAFT'    ?'selected':'' ?>>Draft</option>
                <option value="CHECKED"  <?= $status_filter==='CHECKED'  ?'selected':'' ?>>Checked</option>
                <option value="VERIFIED" <?= $status_filter==='VERIFIED' ?'selected':'' ?>>Verified</option>
                <option value="CLOSE"    <?= $status_filter==='CLOSE'    ?'selected':'' ?>>Close</option>
            </select>
        </div>

        <div class="filter-group">
            <button type="submit">रिपोर्ट तयार गर्नुहोस् (Generate)</button>
        </div>
    </div>
</form>

<div class="report-summary">
    <p><strong>मिति (Date):</strong> <?= htmlspecialchars($date) ?></p>
    <p><strong>फिल्टर (Filter):</strong> <?= $filter_display ?>
       <?php if ($status_filter): ?> | <strong>Status:</strong> <?= htmlspecialchars($status_filter) ?><?php endif; ?>
       <?php if ($book_code_filter): ?> | <strong>Book:</strong> <?= htmlspecialchars($book_code_filter) ?><?php endif; ?>
       <?php if ($class_filter): ?> | <strong>Class:</strong> <?= htmlspecialchars($class_filter) ?><?php endif; ?>
    </p>
    <p><strong>D2M कागजात संख्या (D2M Documents):</strong> <?= count($d2m_docs) ?></p>
    <p><strong>कुल आइटम (Total Items):</strong> <?= count($records) ?></p>
    <p><strong>कुल पोका (Total Poka):</strong> <?= number_format($total_poka_qty) ?>
       | <strong>कुल खुद्रा (Total Open Pcs):</strong> <?= number_format($total_openpcs) ?>
       | <strong>कुल पुस्तक (Total Books):</strong> <?= number_format($total_qty) ?></p>
</div>

<?php if (empty($records)): ?>
    <div class="no-data">
        <p><?= htmlspecialchars($date) ?> को लागि कुनै D2M डाटा फेला परेन।</p>
    </div>
<?php else: ?>
    <table id="reportTable">
        <thead>
            <tr>
                <th>सि.नं.<br>(SN)</th>
                <th>D2M नं.<br>(D2M No)</th>
                <th>पुस्तकको नाम<br>(Book Name)</th>
                <th>कक्षा<br>(Class)</th>
                <th>प्रकार<br>(Type)</th>
                <th>कोडा<br>(Code)</th>
                <th>प्रति पोका<br>(Per Poka)</th>
                <th>पोका संख्या<br>(Poka Qty)</th>
                <th>खुद्रा<br>(Open Pcs)</th>
                <th>जम्मा<br>(Total Qty)</th>
                <th>Status</th>
            </tr>
        </thead>
        <tbody>
            <?php $sn = 1; foreach ($records as $record):
                $status_lower = strtolower($record['status']);
            ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td class="d2m-no-cell"><?= htmlspecialchars($record['d2m_no']) ?></td>
                <td style="text-align:left"><?= htmlspecialchars($record['book_name']) ?></td>
                <td><?= htmlspecialchars($record['class_level']) ?></td>
                <td class="book-type <?= $record['is_translated'] ? 'translated' : 'non-translated' ?>">
                    <?= $record['is_translated'] ? 'T' : 'NT' ?>
                </td>
                <td><?= htmlspecialchars($record['book_code']) ?></td>
                <td><?= number_format($record['per_poka_qty']) ?></td>
                <td><?= number_format($record['total_poka_qty']) ?></td>
                <td><?= number_format($record['open_pcs']) ?></td>
                <td><?= number_format($record['total_qty']) ?></td>
                <td><span class="status-badge-sm status-<?= $status_lower ?>-sm"><?= htmlspecialchars($record['status']) ?></span></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="7"><strong>जम्मा (Total)</strong></td>
                <td><strong><?= number_format($total_poka_qty) ?></strong></td>
                <td><strong><?= number_format($total_openpcs) ?></strong></td>
                <td><strong><?= number_format($total_qty) ?></strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="print-actions no-print" style="margin-top:20px;">
        <button onclick="printReport()"   class="btn-print" >🖨️ Print</button>
        <button onclick="exportToCSV()"   class="btn-export">📊 CSV</button>
        <button onclick="exportToExcel()" class="btn-excel" >📋 Excel</button>
    </div>
<?php endif; ?>

<script>
function exportToCSV() {
    var currentDate = document.getElementById('date').value;
    var csvContent = "\uFEFF";
    csvContent += `"Daily D2M Report - ${currentDate}"\n\n`;
    csvContent += "SN,D2M No,Book Name,Class,Type,Code,Per Poka,Poka Qty,Open Pcs,Total Qty,Status\n";
    var rows = document.querySelectorAll("#reportTable tbody tr:not(.total-row)");
    rows.forEach(function(row, index) {
        var cells = row.querySelectorAll("td");
        var rowData = [];
        cells.forEach(function(cell) {
            var cellText = cell.textContent.trim();
            if (cellText.includes(',') || cellText.includes('"') || cellText.includes('\n'))
                cellText = '"' + cellText.replace(/"/g, '""') + '"';
            rowData.push(cellText);
        });
        csvContent += rowData.join(",") + "\n";
    });
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement("a");
    link.setAttribute("href", URL.createObjectURL(blob));
    link.setAttribute("download", `d2m_daily_report_${currentDate}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

function exportToExcel() {
    var currentDate = document.getElementById('date').value;
    var excelContent = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head><meta charset="UTF-8"><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">
        <style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:6px;text-align:center}th{background:#6f42c1;color:#fff;font-weight:bold}.total-row{background:#e2d5f0;font-weight:bold}</style>
        </head><body>
        <div style="text-align:center;font-size:16px;font-weight:bold;margin-bottom:20px">Daily D2M Dispatch Report - ${currentDate}</div>
        <table><thead><tr>
            <th>SN</th><th>D2M No</th><th>Book Name</th><th>Class</th><th>Type</th><th>Code</th>
            <th>Per Poka</th><th>Poka Qty</th><th>Open Pcs</th><th>Total Qty</th><th>Status</th>
        </tr></thead><tbody>`;
    document.querySelectorAll("#reportTable tbody tr").forEach(function(row) {
        excelContent += '<tr>';
        row.querySelectorAll("td").forEach(function(cell) {
            excelContent += '<td>' + cell.textContent.trim() + '</td>';
        });
        excelContent += '</tr>';
    });
    excelContent += `</tbody></table></body></html>`;
    var blob = new Blob([excelContent], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var link = document.createElement("a");
    link.setAttribute("href", URL.createObjectURL(blob));
    link.setAttribute("download", `d2m_daily_report_${currentDate}.xls`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
}

function printReport() {
    var currentDate = document.getElementById('date').value;
    var tableHtml = document.getElementById('reportTable').outerHTML;
    var recordCount = document.querySelectorAll("#reportTable tbody tr:not(.total-row)").length;
    var currentDateTime = new Date().toLocaleString();
    var printContent = `<!DOCTYPE html><html><head>
        <title>Daily D2M Report - ${currentDate}</title>
        <style>
            *{margin:0!important;padding:0!important;box-sizing:border-box}
            @page{margin:0!important;size:A4 landscape}
            body{font-family:Arial,sans-serif;padding:10px!important;font-size:12px}
            .header{text-align:center;margin-bottom:15px!important;border-bottom:2px solid #000;padding-bottom:10px!important}
            .company-name{font-size:20px;font-weight:bold;margin-bottom:5px!important}
            .report-title{font-size:16px;font-weight:bold}
            table{width:100%;border-collapse:collapse;margin-top:10px!important;font-size:11px}
            th,td{border:1px solid #000;padding:4px!important;text-align:center;font-size:11px}
            th{background:#6f42c1;color:#fff;font-weight:bold}
            .total-row{background:#e2d5f0;font-weight:bold}
            .d2m-no-cell{color:#6f42c1;font-weight:600}
        </style></head><body>
        <div class="header">
            <div class="company-name">JANAK EDUCATION MATERIALS CENTER</div>
            <div class="report-title">Daily D2M Dispatch Report</div>
            <div style="margin-top:5px;font-size:12px">Date: ${currentDate}</div>
        </div>
        <table>${tableHtml}</table>
        <div style="margin-top:15px;font-size:11px">Records: ${recordCount} | Generated: ${currentDateTime}</div>
        </body></html>`;
    var printWindow = window.open('', '_blank', 'width=1200,height=800');
    printWindow.document.open();
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.onload = function() { printWindow.print(); };
}

document.addEventListener('DOMContentLoaded', function () {
    var dateField = document.getElementById('date');
    dateField.NepaliDatePicker({
        dateFormat: 'YYYY.MM.DD',
        onDateSelect: function () { document.getElementById('filterForm').submit(); }
    });
    dateField.removeAttribute('readonly');

    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.querySelectorAll('select').forEach(function(input) {
            input.addEventListener('change', function() { filterForm.submit(); });
        });
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

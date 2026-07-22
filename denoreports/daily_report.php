<?php 

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

// For actions that modify data, add role checks:
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    // Only allow editors and admins to modify data
    if (!has_role('editor') && !has_role('admin')) {
        echo "<div class='alert alert-danger'>You don't have permission to perform this action.</div>";
        exit();
    }
    
    // Rest of your POST handling code...
}?>

<script>
function exportToCSV() {
    var currentDate = document.getElementById('date').value;
    var filterSelect = document.getElementById('translation_filter');
    var currentFilter = filterSelect.value;
    var filterText = filterSelect.options[filterSelect.selectedIndex].text;
    
    var bookCodeFilter = document.getElementById('book_code_filter').value;
    var classFilter = document.getElementById('class_filter').value;
    var remarksFilter = document.getElementById('remarks_filter').value;
    
    var csvContent = "\uFEFF";
    csvContent += `"Daily Production Report - ${currentDate} (${filterText})"\n`;
    if (bookCodeFilter) csvContent += `"Book Code Filter: ${bookCodeFilter}"\n`;
    if (classFilter) csvContent += `"Class Filter: ${classFilter}"\n`;
    if (remarksFilter) csvContent += `"Remarks Filter: ${remarksFilter}"\n`;
    csvContent += "\n";
    csvContent += "सि.नं.,पुस्तकको नाम,कक्षा,प्रकार,कोडा,प्रति पोका,पुस्तक पोका संख्या,खुद्रा पुस्तक संख्या,जम्मा पुस्तक संख्या,कैफियत\n";
    csvContent += "SN,Book Name,Class,Type,Code,Per Poka,Poka Qty,Openpcs,Total Qty,Remarks\n";
    
    var totalPokaQty = 0, totalOpenpcs = 0, totalQty = 0;
    
    var rows = document.querySelectorAll("#reportTable tbody tr:not(.total-row)");
    rows.forEach(function(row, index) {
        var cells = row.querySelectorAll("td");
        var rowData = [];
        cells.forEach(function(cell, cellIndex) {
            var cellText = cell.textContent.trim();
            if (cellIndex === 6) totalPokaQty += parseInt(cellText.replace(/,/g, '')) || 0;
            else if (cellIndex === 7) totalOpenpcs += parseInt(cellText.replace(/,/g, '')) || 0;
            else if (cellIndex === 8) totalQty += parseInt(cellText.replace(/,/g, '')) || 0;
            if (cellIndex === 3) cellText = cellText.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim();
            if (cellText.includes(',') || cellText.includes('"') || cellText.includes('\n'))
                cellText = '"' + cellText.replace(/"/g, '""') + '"';
            rowData.push(cellText);
        });
        csvContent += rowData.join(",") + "\n";
    });
    csvContent += '"जम्मा (Total)","","","","","","' + totalPokaQty.toLocaleString() + '","' + totalOpenpcs.toLocaleString() + '","' + totalQty.toLocaleString() + '",""\n';
    
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement("a");
    link.setAttribute("href", URL.createObjectURL(blob));
    link.setAttribute("download", `daily_report_${currentDate}_${currentFilter}.csv`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    alert('CSV file exported successfully! Open with Excel for best results.');
}

function exportToExcel() {
    var currentDate = document.getElementById('date').value;
    var filterSelect = document.getElementById('translation_filter');
    var currentFilter = filterSelect.value;
    var filterText = filterSelect.options[filterSelect.selectedIndex].text;
    var bookCodeFilter = document.getElementById('book_code_filter').value;
    var classFilter = document.getElementById('class_filter').value;
    var remarksFilter = document.getElementById('remarks_filter').value;
    
    var excelContent = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head><meta charset="UTF-8"><meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">
        <style>table{border-collapse:collapse;width:100%}th,td{border:1px solid #000;padding:8px;text-align:center}th{background-color:#f2f2f2;font-weight:bold}.total-row{background-color:#e8f4f8;font-weight:bold}.book-type.translated{background-color:#e8f5e8;color:#2d5a2d}.book-type.non-translated{background-color:#f0f8ff;color:#1e3a8a}</style>
        </head><body>
        <div style="text-align:center;font-size:16px;font-weight:bold;margin-bottom:20px">
            <div>जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
            <div>दैनिक उत्पादन विवरण (Daily Production Report)</div>
            <div>मिति: ${currentDate} (${filterText})</div>`;
    if (bookCodeFilter || classFilter || remarksFilter) {
        excelContent += `<div>Filters: `;
        if (bookCodeFilter) excelContent += `Book Code: ${bookCodeFilter} `;
        if (classFilter)    excelContent += `Class: ${classFilter} `;
        if (remarksFilter)  excelContent += `Remarks: ${remarksFilter}`;
        excelContent += `</div>`;
    }
    excelContent += `</div>
        <table><thead><tr>
            <th>सि.नं.<br>(SN)</th><th>पुस्तकको नाम<br>(Book Name)</th><th>कक्षा<br>(Class)</th>
            <th>प्रकार<br>(Type)</th><th>कोडा<br>(Code)</th><th>प्रति पोका<br>(Per Poka)</th>
            <th>पुस्तक पोका संख्या<br>(Poka Qty)</th><th>खुद्रा पुस्तक संख्या<br>(Openpcs)</th>
            <th>जम्मा पुस्तक संख्या<br>(Total Qty)</th><th>कैफियत<br>(Remarks)</th>
        </tr></thead><tbody>`;
    
    var totalPokaQty = 0, totalOpenpcs = 0, totalQty = 0;
    document.querySelectorAll("#reportTable tbody tr:not(.total-row)").forEach(function(row) {
        excelContent += '<tr>';
        row.querySelectorAll("td").forEach(function(cell, cellIndex) {
            var cellText = cell.textContent.trim();
            if (cellIndex === 6) totalPokaQty += parseInt(cellText.replace(/,/g, '')) || 0;
            else if (cellIndex === 7) totalOpenpcs += parseInt(cellText.replace(/,/g, '')) || 0;
            else if (cellIndex === 8) totalQty += parseInt(cellText.replace(/,/g, '')) || 0;
            var cls = (cellIndex === 3) ? cell.className : '';
            excelContent += `<td class="${cls}">${cellText}</td>`;
        });
        excelContent += '</tr>';
    });
    excelContent += `<tr class="total-row"><td colspan="6"><strong>जम्मा (Total)</strong></td>
        <td><strong>${totalPokaQty.toLocaleString()}</strong></td>
        <td><strong>${totalOpenpcs.toLocaleString()}</strong></td>
        <td><strong>${totalQty.toLocaleString()}</strong></td><td></td></tr>
        </tbody></table></body></html>`;
    
    var blob = new Blob([excelContent], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    var link = document.createElement("a");
    link.setAttribute("href", URL.createObjectURL(blob));
    link.setAttribute("download", `daily_report_${currentDate}_${currentFilter}.xls`);
    link.style.visibility = 'hidden';
    document.body.appendChild(link); link.click(); document.body.removeChild(link);
    alert('Excel file exported successfully!');
}

function printReport() {
    var currentDate = document.getElementById('date').value;
    var filterSelect = document.getElementById('translation_filter');
    var filterText = filterSelect.options[filterSelect.selectedIndex].text;
    var bookCodeFilter = document.getElementById('book_code_filter').value;
    var classFilter = document.getElementById('class_filter').value;
    var remarksFilter = document.getElementById('remarks_filter').value;

    // ── Collect rows then sort by Class (int), Book Name, Remarks ──
    var rawRows = [];
    document.querySelectorAll("#reportTable tbody tr:not(.total-row)").forEach(function(row) {
        var cells = row.querySelectorAll("td");
        rawRows.push({
            bookName : cells[1].textContent.trim(),
            classLvl : cells[2].textContent.trim(),
            typeClass: cells[3].classList.contains('translated') ? 'translated' : 'non-translated',
            code     : cells[4].textContent.trim(),
            perPoka  : cells[5].textContent.trim(),
            pokaQty  : cells[6].textContent.trim(),
            openpcs  : cells[7].textContent.trim(),
            totalQty : cells[8].textContent.trim(),
            remarks  : cells[9].textContent.trim()
        });
    });

    rawRows.sort(function(a, b) {
        var ca = parseInt(a.classLvl) || 0;
        var cb = parseInt(b.classLvl) || 0;
        if (ca !== cb) return ca - cb;
        if (a.bookName < b.bookName) return -1;
        if (a.bookName > b.bookName) return  1;
        if (a.remarks  < b.remarks)  return -1;
        if (a.remarks  > b.remarks)  return  1;
        return 0;
    });

    var totalPokaQty = 0, totalOpenpcs = 0, totalQty = 0;
    var tableRows = '';
    rawRows.forEach(function(r, idx) {
        totalPokaQty += parseInt(r.pokaQty.replace(/,/g, ''))  || 0;
        totalOpenpcs += parseInt(r.openpcs.replace(/,/g, ''))  || 0;
        totalQty     += parseInt(r.totalQty.replace(/,/g, '')) || 0;
        var typeLabel = r.typeClass === 'translated' ? 'T' : 'NT';
        tableRows += `<tr>
            <td>${idx + 1}</td>
            <td class="left">${r.bookName}</td>
            <td>${r.classLvl}</td>
            <td class="book-type ${r.typeClass}">${typeLabel}</td>
            <td>${r.code}</td>
            <td>${r.perPoka}</td>
            <td>${r.pokaQty}</td>
            <td>${r.openpcs}</td>
            <td>${r.totalQty}</td>
            <td class="left">${r.remarks}</td>
        </tr>`;
    });
    tableRows += `<tr class="total-row">
        <td colspan="6"><strong>जम्मा (Total)</strong></td>
        <td><strong>${totalPokaQty.toLocaleString()}</strong></td>
        <td><strong>${totalOpenpcs.toLocaleString()}</strong></td>
        <td><strong>${totalQty.toLocaleString()}</strong></td>
        <td></td>
    </tr>`;

    var recordCount = rawRows.length;
    var currentDateTime = new Date().toLocaleString();
    var filterSummary = '';
    if (bookCodeFilter || classFilter || remarksFilter) {
        filterSummary = '<div class="summary-row"><strong>Filters:</strong> ';
        if (bookCodeFilter) filterSummary += `Book: ${bookCodeFilter} `;
        if (classFilter)    filterSummary += `Class: ${classFilter} `;
        if (remarksFilter)  filterSummary += `Remarks: ${remarksFilter}`;
        filterSummary += '</div>';
    }

    var printContent = `<!DOCTYPE html><html><head>
        <title>दैनिक उत्पादन विवरण - ${currentDate}</title>
        <meta charset="UTF-8">
        <style>
            * { margin:0; padding:0; box-sizing:border-box; }
            @page { margin:8mm 6mm; size:A4 portrait; }
            body { font-family:'Segoe UI', Arial, sans-serif; font-size:10pt; line-height:1.3; }

            .header { text-align:center; margin-bottom:6px; border-bottom:2px solid #000; padding-bottom:6px; }
            .company-name { font-size:14pt; font-weight:bold; }
            .report-title { font-size:12pt; font-weight:bold; margin:2px 0; }
            .report-date  { font-size:10pt; }

            .report-summary {
                margin:6px 0; border:1px solid #999; padding:5px 8px;
                background:#f5f9fc; font-size:9pt;
            }
            .summary-row {
                display:flex; justify-content:space-between; flex-wrap:wrap; margin:2px 0;
            }

            table { width:100%; border-collapse:collapse; margin-top:6px; font-size:10pt; }
            th, td {
                border:1px solid #555;
                padding:3px 4px;
                text-align:center;
                vertical-align:middle;
                word-break:break-word;
            }
            td.left { text-align:left; }
            th { background:#e8e8e8; font-weight:bold; font-size:9pt; line-height:1.2; }
            tr:nth-child(even) { background:#f8f8f8; }
            .total-row td { background:#ddeef6 !important; font-weight:bold; border-top:2px solid #000; }

            .book-type { font-size:10pt; font-weight:bold; }
            .book-type.translated     { background:#d4edda; color:#155724; }
            .book-type.non-translated { background:#cce5ff; color:#004085; }

            /* Column widths tuned for portrait A4 */
            col.c-sn      { width:4%;  }
            col.c-book    { width:27%; }
            col.c-class   { width:5%;  }
            col.c-type    { width:5%;  }
            col.c-code    { width:10%; }
            col.c-perpoka { width:7%;  }
            col.c-poka    { width:9%;  }
            col.c-open    { width:9%;  }
            col.c-total   { width:10%; }
            col.c-remarks { width:14%; }

            .signature-section { margin-top:16px; page-break-inside:avoid; }
            .signature-row { display:flex; justify-content:space-between; margin-top:14px; }
            .signature-item { flex:1; text-align:center; margin:0 8px; }
            .signature-item p { margin:4px 0; font-weight:bold; font-size:9pt; }
            .signature-line { border-bottom:1px solid #000; height:22px; margin:10px 4px; }
            .signature-label { font-size:8pt; color:#555; font-weight:normal !important; }

            .footer {
                margin-top:10px; border-top:1px solid #aaa;
                padding-top:5px; font-size:8pt; text-align:center; color:#555;
            }
        </style>
    </head><body>

        <div class="header">
            <div class="company-name">जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
            <div style="font-size:9pt;margin:2px 0">सानोठिमी, भक्तपुर – उत्पादन विभाग</div>
            <div class="report-title">दैनिक उत्पादन विवरण (Daily Production Report)</div>
            <div class="report-date">मिति: ${currentDate} &nbsp;|&nbsp; ${filterText}</div>
        </div>

        <div class="report-summary">
            <div class="summary-row">
                <span><strong>मिति:</strong> ${currentDate}</span>
                <span><strong>कुल रेकर्ड:</strong> ${recordCount}</span>
                <span><strong>कुल उत्पादन:</strong> ${totalQty.toLocaleString()}</span>
                <span><strong>कुल खुद्रा:</strong> ${totalOpenpcs.toLocaleString()}</span>
                <span><strong>शुद्ध उत्पादन:</strong> ${(totalQty + totalOpenpcs).toLocaleString()}</span>
            </div>
            ${filterSummary}
        </div>

        <table>
            <colgroup>
                <col class="c-sn">
                <col class="c-book">
                <col class="c-class">
                <col class="c-type">
                <col class="c-code">
                <col class="c-perpoka">
                <col class="c-poka">
                <col class="c-open">
                <col class="c-total">
                <col class="c-remarks">
            </colgroup>
            <thead>
                <tr>
                    <th>सि.नं.<br>(SN)</th>
                    <th>पुस्तकको नाम<br>(Book Name)</th>
                    <th>कक्षा<br>(Cls)</th>
                    <th>प्रकार<br>(T/NT)</th>
                    <th>कोडा<br>(Code)</th>
                    <th>प्रति पोका<br>(Per Poka)</th>
                    <th>पोका संख्या<br>(Poka Qty)</th>
                    <th>खुद्रा संख्या<br>(Openpcs)</th>
                    <th>जम्मा<br>(Total)</th>
                    <th>कैफियत<br>(Remarks)</th>
                </tr>
            </thead>
            <tbody>${tableRows}</tbody>
        </table>

        <div class="signature-section">
            <div class="signature-row">
                <div class="signature-item">
                    <p><strong>रुजु गरी निकासा गर्ने:</strong></p>
                    <div class="signature-line"></div>
                    <p class="signature-label">हस्ताक्षर (Signature)</p>
                    <p style="font-size:8pt">(उत्पादन विभाग)</p>
                </div>
                <div class="signature-item">
                    <p><strong>बुझिलिनेको सही:</strong></p>
                    <div class="signature-line"></div>
                    <p class="signature-label">हस्ताक्षर (Signature)</p>
                    <p style="font-size:8pt">(उत्पादन विभाग)</p>
                </div>
                <div class="signature-item">
                    <p><strong>जाँच गर्ने (Verified By):</strong></p>
                    <div class="signature-line"></div>
                    <p class="signature-label">हस्ताक्षर (Signature)</p>
                </div>
            </div>
        </div>

        <div class="footer">
            <p>रिपोर्ट तयार गरिएको मिति: ${currentDateTime}</p>
        </div>

    </body></html>`;

    var printWindow = window.open('', '_blank', 'width=900,height=1100');
    printWindow.document.open();
    printWindow.document.write(printContent);
    printWindow.document.close();
    printWindow.onload = function() { printWindow.print(); };
}

document.addEventListener('DOMContentLoaded', function() {
    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
        filterForm.querySelectorAll('select').forEach(function(input) {
            input.addEventListener('change', function() { filterForm.submit(); });
        });
        // NOTE: text inputs (date, book, class, remarks) intentionally NOT auto-submitted
        // so the user can finish typing before the form fires.
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$date               = $_GET['date']               ?? '2082.04.07';
$translation_filter = $_GET['translation_filter'] ?? 'all';
$book_code_filter   = $_GET['book_code_filter']   ?? '';
$class_filter       = $_GET['class_filter']       ?? '';
$remarks_filter     = $_GET['remarks_filter']     ?? '';

$query = "
    SELECT d.*, b.book_name, b.is_translated, b.class_level
    FROM Deno d
    JOIN Books b ON d.book_code = b.book_code
    WHERE (d.deno_date_nep = :date OR d.deno_date_eng = :date)
";
$params = [':date' => $date];

if ($translation_filter === 'translated')         $query .= " AND b.is_translated = TRUE";
elseif ($translation_filter === 'non_translated') $query .= " AND b.is_translated = FALSE";

if (!empty($book_code_filter)) {
    $query .= " AND d.book_code LIKE :book_code";
    $params[':book_code'] = '%' . $book_code_filter . '%';
}
if (!empty($class_filter)) {
    $query .= " AND b.class_level = :class_level";
    $params[':class_level'] = $class_filter;
}
if (!empty($remarks_filter)) {
    $query .= " AND d.ref_no LIKE :remarks";
    $params[':remarks'] = '%' . $remarks_filter . '%';
}
$query .= " ORDER BY b.book_name";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

$total_qty      = array_sum(array_column($records, 'total_qty'));
$total_openpcs  = array_sum(array_column($records, 'quantity_openpcs'));
$total_poka_qty = array_sum(array_column($records, 'poka_qty'));

$filter_display_map = [
    'translated'     => ' (अनुवादित मात्र / Translated Only)',
    'non_translated' => ' (गैर-अनुवादित मात्र / Non-Translated Only)',
];
$filter_display = $filter_display_map[$translation_filter] ?? ' (सबै / All Books)';

$class_stmt = $conn->prepare("SELECT DISTINCT class_level FROM Books WHERE class_level IS NOT NULL AND class_level > 0 ORDER BY class_level");
$class_stmt->execute();
$class_options = $class_stmt->fetchAll(PDO::FETCH_COLUMN);

/* All books for the book dropdown */
$all_books = $conn->query("SELECT book_code, book_name FROM Books ORDER BY book_name")->fetchAll(PDO::FETCH_ASSOC);
?>

<!-- ── Nepali Datepicker v5 ── -->
<link  href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css" rel="stylesheet" type="text/css"/>
<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"  type="text/javascript"></script>

<style>
/* ── filter form ── */
.report-filter { background:#f5f5f5; padding:15px; border-radius:5px; margin-bottom:20px; }
.filter-row    { display:flex; align-items:flex-end; flex-wrap:wrap; gap:15px; }
.filter-group  { display:flex; flex-direction:column; gap:5px; }
.filter-group label { font-weight:bold; white-space:nowrap; font-size:12px; }
.filter-group input,.filter-group select {
    padding:8px; border:1px solid #ddd; border-radius:4px;
    min-width:120px; font-size:12px; font-family:inherit;
    box-sizing:border-box;
}
.filter-group input:focus,.filter-group select:focus { outline:none; border-color:#007cba; box-shadow:0 0 0 2px rgba(0,124,186,.18); }
.filter-group button {
    padding:8px 15px; background:#007cba; color:#fff;
    border:none; border-radius:4px; cursor:pointer;
    white-space:nowrap; font-size:12px; align-self:flex-end;
}
.filter-group button:hover { background:#005a87; }

/* ndp date input — same look as other filter inputs */
.ndp-date-filter {
    padding:8px; border:1px solid #ddd; border-radius:4px;
    min-width:135px; font-size:12px; font-family:inherit;
    background:#fff; box-sizing:border-box; width:135px;
}
.ndp-date-filter:focus { outline:none; border-color:#007cba; box-shadow:0 0 0 2px rgba(0,124,186,.18); }

/* ── book search dropdown (filter bar) ── */
.book-filter-wrap   { position:relative; }
.book-filter-input  {
    padding:8px; border:1px solid #ddd; border-radius:4px;
    min-width:200px; width:200px; font-size:12px; font-family:inherit;
    box-sizing:border-box; background:#fff;
}
.book-filter-input:focus { outline:none; border-color:#007cba; box-shadow:0 0 0 2px rgba(0,124,186,.18); }
.book-filter-opts {
    position:absolute; top:100%; left:0; right:0; z-index:1000;
    background:#fff; border:1px solid #ddd; border-top:none;
    max-height:210px; overflow-y:auto; display:none;
    box-shadow:0 4px 12px rgba(0,0,0,.14); border-radius:0 0 4px 4px;
    min-width:200px;
}
.book-filter-opt {
    padding:8px 11px; cursor:pointer; font-size:12px;
    border-bottom:1px solid #f0f0f0; line-height:1.3;
}
.book-filter-opt:hover     { background:#e8f4fd; }
.book-filter-opt.active    { background:#dbeeff; font-weight:600; }
.book-filter-opt:last-child{ border-bottom:none; }
.book-filter-opt small     { color:#999; display:block; font-size:11px; }

/* ── rest of styles ── */
.report-summary { background:#e8f4f8; padding:15px; border-radius:5px; margin-bottom:20px; }
.report-summary p { margin:5px 0; font-size:13px; }
.no-data { text-align:center; padding:40px; background:#f9f9f9; border-radius:5px; color:#666; }
table { width:100%; border-collapse:collapse; margin-top:20px; font-family:'Segoe UI',Tahoma,Geneva,Verdana,sans-serif; font-size:11px; }
th,td { border:1px solid #000; padding:6px; text-align:center; vertical-align:middle; }
th { background-color:#f2f2f2; font-weight:bold; font-size:10px; line-height:1.2; }
tr:nth-child(even) { background-color:#f9f9f9; }
.total-row { background-color:#e8f4f8 !important; font-weight:bold; }
.total-row td { border-top:2px solid #000; }
.book-type { font-size:9px; padding:4px; line-height:1.1; }
.book-type.translated     { background-color:#e8f5e8; color:#2d5a2d; }
.book-type.non-translated { background-color:#f0f8ff; color:#1e3a8a; }
.signature-section { margin-top:40px; page-break-inside:avoid; }
.signature-row { display:flex; justify-content:space-between; margin-top:30px; }
.signature-item { flex:1; text-align:center; margin:0 20px; }
.signature-item p { margin:10px 0; font-weight:bold; }
.signature-line { border-bottom:1px solid #000; height:40px; margin:20px 10px; }
.signature-label { font-size:12px; color:#666; font-weight:normal !important; }
.btn-print  { padding:10px 20px; margin-right:10px; border:none; border-radius:5px; cursor:pointer; font-size:14px; background:#28a745; color:#fff; }
.btn-export { padding:10px 20px; margin-right:10px; border:none; border-radius:5px; cursor:pointer; font-size:14px; background:#17a2b8; color:#fff; }
.btn-excel  { padding:10px 20px; margin-right:10px; border:none; border-radius:5px; cursor:pointer; font-size:14px; background:#007bff; color:#fff; }
.btn-print:hover,.btn-export:hover,.btn-excel:hover { opacity:.8; }

@media print {
    .report-filter,.print-actions,nav,header,footer { display:none !important; }
    * { margin:0!important; padding:0!important; }
    @page { margin:0!important; size:A4 landscape; }
    body { font-size:9px!important; margin:0!important; padding:3px!important; line-height:1.0; }
    h2 { font-size:14px!important; margin-bottom:8px!important; text-align:center; }
    table { font-size:8px!important; width:100%; margin-top:5px!important; }
    th,td { padding:2px!important; font-size:8px!important; border:1px solid #000; }
    .report-summary { background:none; border:1px solid #000; padding:5px!important; margin:5px 0!important; font-size:8px!important; }
    .report-summary p { margin:2px 0!important; font-size:8px!important; }
    .signature-section { margin-top:15px!important; page-break-inside:avoid; }
    .signature-row { display:flex; justify-content:space-between; margin-top:10px!important; }
    .signature-item { flex:1; margin:0 5px!important; }
    .signature-item p { font-size:8px!important; margin:3px 0!important; }
    .signature-line { height:15px!important; margin:5px 2px!important; }
    .signature-label { font-size:7px!important; }
    .book-type,.total-row td { font-size:7px!important; font-weight:bold; }
}
</style>

<h2>दैनिक उत्पादन विवरण (Daily Production Report)<?= $filter_display ?></h2>

<form method="get" id="filterForm" class="report-filter" autocomplete="off">
    <div class="filter-row">

        <!-- ── 1. Nepali Date (datepicker) ── -->
        <div class="filter-group">
            <label for="date">मिति (Date) (YYYY.MM.DD):</label>
            <input type="text"
                   name="date"
                   id="date"
                   class="ndp-date-filter"
                   placeholder="2082.01.15"
                   value="<?= htmlspecialchars($date) ?>"
                   readonly>
        </div>

        <!-- ── 2. Book Type ── -->
        <div class="filter-group">
            <label for="translation_filter">पुस्तक प्रकार (Book Type):</label>
            <select name="translation_filter" id="translation_filter">
                <option value="all"            <?= $translation_filter==='all'            ?'selected':'' ?>>सबै (All Books)</option>
                <option value="translated"     <?= $translation_filter==='translated'     ?'selected':'' ?>>अनुवादित (Translated Only)</option>
                <option value="non_translated" <?= $translation_filter==='non_translated' ?'selected':'' ?>>गैर-अनुवादित (Non-Translated Only)</option>
            </select>
        </div>

        <!-- ── 3. Book searchable dropdown ── -->
        <div class="filter-group">
            <label>पुस्तक (Book):</label>
            <div class="book-filter-wrap">
                <input type="text"
                       id="book_filter_search"
                       class="book-filter-input"
                       placeholder="Search book…">
                <!-- The actual value posted as book_code_filter -->
                <input type="hidden" name="book_code_filter" id="book_code_filter"
                       value="<?= htmlspecialchars($book_code_filter) ?>">
                <div class="book-filter-opts" id="book_filter_opts">
                    <div class="book-filter-opt" data-value="" data-text="">— सबै पुस्तक (All Books) —</div>
                    <?php foreach ($all_books as $b): ?>
                    <div class="book-filter-opt"
                         data-value="<?= htmlspecialchars($b['book_code']) ?>"
                         data-text="<?= htmlspecialchars($b['book_name']) ?>">
                        <?= htmlspecialchars($b['book_name']) ?>
                        <small><?= htmlspecialchars($b['book_code']) ?></small>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>

        <!-- ── 4. Class ── -->
        <div class="filter-group">
            <label for="class_filter">कक्षा (Class):</label>
            <select name="class_filter" id="class_filter">
                <option value="">सबै कक्षा (All Classes)</option>
                <?php foreach ($class_options as $co): ?>
                <option value="<?= htmlspecialchars($co) ?>" <?= $class_filter===$co?'selected':'' ?>>
                    <?= htmlspecialchars($co) ?>
                </option>
                <?php endforeach; ?>
            </select>
        </div>

        <!-- ── 5. Remarks / Ref filter ── -->
        <div class="filter-group">
            <label for="remarks_filter">कैफियत (Remarks):</label>
            <input type="text" name="remarks_filter" id="remarks_filter"
                   value="<?= htmlspecialchars($remarks_filter) ?>"
                   placeholder="Enter remarks">
        </div>

        <!-- ── 6. Submit ── -->
        <div class="filter-group">
            <button type="submit">रिपोर्ट तयार गर्नुहोस् (Generate)</button>
        </div>

    </div>
</form>

<div class="report-summary">
    <p><strong>मिति (Date):</strong> <?= htmlspecialchars($date) ?></p>
    <p><strong>फिल्टर (Filter):</strong> <?= $filter_display ?></p>
    <?php if ($book_code_filter): ?>
        <p><strong>बुक कोड फिल्टर:</strong> <?= htmlspecialchars($book_code_filter) ?></p>
    <?php endif; ?>
    <?php if ($class_filter): ?>
        <p><strong>कक्षा फिल्टर:</strong> <?= htmlspecialchars($class_filter) ?></p>
    <?php endif; ?>
    <?php if ($remarks_filter): ?>
        <p><strong>कैफियत फिल्टर:</strong> <?= htmlspecialchars($remarks_filter) ?></p>
    <?php endif; ?>
    <p><strong>रेकर्ड संख्या:</strong> <?= count($records) ?></p>
    <p><strong>कुल उत्पादन:</strong> <?= number_format($total_qty) ?></p>
    <p><strong>कुल खुद्रा:</strong> <?= number_format($total_openpcs) ?></p>
    <p><strong>शुद्ध उत्पादन:</strong> <?= number_format($total_qty + $total_openpcs) ?></p>
</div>

<?php if (empty($records)): ?>
    <div class="no-data">
        <p><?= htmlspecialchars($date) ?> को लागि <?= $filter_display ?> कुनै उत्पादन डाटा फेला परेन।</p>
        <p>फरक मिति वा फिल्टर प्रयोग गर्नुहोस्।</p>
    </div>
<?php else: ?>
    <table id="reportTable">
        <thead>
            <tr>
                <th>सि.नं.<br>(SN)</th>
                <th>पुस्तकको नाम<br>(Book Name)</th>
                <th>कक्षा<br>(Class)</th>
                <th>प्रकार<br>(Type)</th>
                <th>कोडा<br>(Code)</th>
                <th>प्रति पोका<br>(Per Poka)</th>
                <th>पुस्तक पोका संख्या<br>(Poka Qty)</th>
                <th>खुद्रा पुस्तक संख्या<br>(Openpcs)</th>
                <th>जम्मा पुस्तक संख्या<br>(Total Qty)</th>
                <th>कैफियत<br>(Remarks)</th>
            </tr>
        </thead>
        <tbody>
            <?php $sn = 1; foreach ($records as $record): ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td><?= htmlspecialchars($record['book_name']) ?></td>
                <td><?= htmlspecialchars($record['class_level']) ?></td>
                <td class="book-type <?= $record['is_translated'] ? 'translated' : 'non-translated' ?>">
                    <?= $record['is_translated'] ? 'अनुवादित<br>(Translated)' : 'गैर-अनुवादित<br>(Original)' ?>
                </td>
                <td><?= htmlspecialchars($record['book_code']) ?></td>
                <td><?= number_format($record['per_poka_qty']) ?></td>
                <td><?= number_format($record['poka_qty']) ?></td>
                <td><?= number_format($record['quantity_openpcs']) ?></td>
                <td><?= number_format($record['total_qty']) ?></td>
                <td><?= htmlspecialchars($record['ref_no'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="6"><strong>जम्मा (Total)</strong></td>
                <td><strong><?= number_format($total_poka_qty) ?></strong></td>
                <td><strong><?= number_format($total_openpcs) ?></strong></td>
                <td><strong><?= number_format($total_qty) ?></strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <div class="signature-section">
        <div class="signature-row">
            <div class="signature-item">
                <p><strong>तयार गर्ने (Created By):</strong></p>
                <div class="signature-line"></div>
                <p class="signature-label">हस्ताक्षर (Signature)</p>
            </div>
            <div class="signature-item">
                <p><strong>प्राप्त गर्ने (Received By):</strong></p>
                <div class="signature-line"></div>
                <p class="signature-label">हस्ताक्षर (Signature)</p>
            </div>
            <div class="signature-item">
                <p><strong>जाँच गर्ने (Verified By):</strong></p>
                <div class="signature-line"></div>
                <p class="signature-label">हस्ताक्षर (Signature)</p>
            </div>
        </div>
    </div>

    <div class="print-actions" style="margin-top:20px;">
        <button onclick="printReport()"   class="btn-print" >🖨️ छाप्नुहोस् (Print Report)</button>
        <button onclick="exportToCSV()"   class="btn-export">📊 CSV मा निर्यात गर्नुहोस्</button>
        <button onclick="exportToExcel()" class="btn-excel" >📋 Excel मा निर्यात गर्नुहोस्</button>
    </div>
<?php endif; ?>

<script>
document.addEventListener('DOMContentLoaded', function () {

    /* ══════════════════════════════════════════════
       1. NEPALI DATE PICKER on the filter date field
    ══════════════════════════════════════════════ */
    var dateField = document.getElementById('date');
    dateField.NepaliDatePicker({
        dateFormat : 'YYYY.MM.DD',
        onDateSelect: function () {
            // Auto-submit the form after picking a date
            document.getElementById('filterForm').submit();
        }
    });
    // Allow typing manually too (remove readonly once picker is init'd)
    dateField.removeAttribute('readonly');

    /* ══════════════════════════════════════════════
       2. BOOK SEARCHABLE DROPDOWN
    ══════════════════════════════════════════════ */
    var bSearch  = document.getElementById('book_filter_search');
    var bHidden  = document.getElementById('book_code_filter');
    var bOpts    = document.getElementById('book_filter_opts');
    var bOptions = bOpts.querySelectorAll('.book-filter-opt');

    // Pre-fill label from the current filter value
    (function () {
        var code = bHidden.value;
        if (!code) { bSearch.value = ''; return; }
        var el = bOpts.querySelector('[data-value="' + CSS.escape(code) + '"]');
        if (el) {
            bSearch.value = el.querySelector('small') ? el.childNodes[0].textContent.trim() : el.textContent.trim();
            el.classList.add('active');
        }
    })();

    bSearch.addEventListener('focus', function () { filterBookOpts(); bOpts.style.display = 'block'; });
    bSearch.addEventListener('input', function () {
        filterBookOpts();
        bOpts.style.display = 'block';
        if (!this.value) bHidden.value = '';
    });
    document.addEventListener('click', function (e) {
        if (!e.target.closest('.book-filter-wrap')) bOpts.style.display = 'none';
    });

    function filterBookOpts() {
        var t = bSearch.value.toLowerCase();
        bOptions.forEach(function (o) {
            var match = o.textContent.toLowerCase().includes(t) ||
                        o.dataset.value.toLowerCase().includes(t);
            o.style.display = match ? 'block' : 'none';
        });
    }

    bOptions.forEach(function (o) {
        o.addEventListener('click', function () {
            // Show the book name (not the code) in the visible input
            var nameNode = this.childNodes[0];
            bSearch.value = nameNode ? nameNode.textContent.trim() : this.dataset.text;
            bHidden.value = this.dataset.value;
            bOpts.style.display = 'none';
            bOptions.forEach(function (x) { x.classList.remove('active'); });
            this.classList.add('active');
            // Auto-submit on selection
            document.getElementById('filterForm').submit();
        });
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

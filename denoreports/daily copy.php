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
    // Get current values from the page
    var currentDate = document.getElementById('date').value;
    var filterSelect = document.getElementById('translation_filter');
    var currentFilter = filterSelect.value;
    var filterText = filterSelect.options[filterSelect.selectedIndex].text;
    
    // Get other filter values
    var bookCodeFilter = document.getElementById('book_code_filter').value;
    var classFilter = document.getElementById('class_filter').value;
    var remarksFilter = document.getElementById('remarks_filter').value;
    
    // Prepare CSV data with UTF-8 BOM for proper Nepali character display in Excel
    var csvContent = "\uFEFF"; // UTF-8 BOM
    
    // Add filter information
    csvContent += `"Daily Production Report - ${currentDate} (${filterText})"\n`;
    if (bookCodeFilter) csvContent += `"Book Code Filter: ${bookCodeFilter}"\n`;
    if (classFilter) csvContent += `"Class Filter: ${classFilter}"\n`;
    if (remarksFilter) csvContent += `"Remarks Filter: ${remarksFilter}"\n`;
    csvContent += "\n";
    
    // Add Nepali headers
    csvContent += "सि.नं.,पुस्तकको नाम,कक्षा,प्रकार,कोडा,प्रति पोका,पुस्तक पोका संख्या,खुद्रा पुस्तक संख्या,जम्मा पुस्तक संख्या,कैफियत\n";
    // Add English headers
    csvContent += "SN,Book Name,Class,Type,Code,Per Poka,Poka Qty,Openpcs,Total Qty,Remarks\n";
    
    // Calculate totals from actual data
    var totalPokaQty = 0;
    var totalOpenpcs = 0;
    var totalQty = 0;
    
    var rows = document.querySelectorAll("#reportTable tbody tr:not(.total-row)");
    rows.forEach(function(row, index) {
        var cells = row.querySelectorAll("td");
        var rowData = [];
        
        cells.forEach(function(cell, cellIndex) {
            var cellText = cell.textContent.trim();
            
            // Remove commas from numbers for calculation
            if (cellIndex === 6) { // Poka Qty column
                totalPokaQty += parseInt(cellText.replace(/,/g, '')) || 0;
            } else if (cellIndex === 7) { // Openpcs column
                totalOpenpcs += parseInt(cellText.replace(/,/g, '')) || 0;
            } else if (cellIndex === 8) { // Total Qty column
                totalQty += parseInt(cellText.replace(/,/g, '')) || 0;
            }
            
            // Clean up cell text for Type column
            if (cellIndex === 3) { // Type column
                cellText = cellText.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim();
            }
            
            // Properly escape quotes and wrap in quotes if contains comma or quotes
            if (cellText.includes(',') || cellText.includes('"') || cellText.includes('\n')) {
                cellText = '"' + cellText.replace(/"/g, '""') + '"';
            }
            rowData.push(cellText);
        });
        csvContent += rowData.join(",") + "\n";
    });
    
    // Add total row with calculated values
    csvContent += '"जम्मा (Total)","","","","","","' + totalPokaQty.toLocaleString() + '","' + totalOpenpcs.toLocaleString() + '","' + totalQty.toLocaleString() + '",""\n';
    
    // Create and download CSV file with proper encoding
    var blob = new Blob([csvContent], { 
        type: 'text/csv;charset=utf-8;' 
    });
    
    var link = document.createElement("a");
    var url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    
    // Create filename with filter info
    var filename = `daily_report_${currentDate}_${currentFilter}.csv`;
    link.setAttribute("download", filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    // Show success message
    alert('CSV file exported successfully! Open with Excel for best results.');
}

function exportToExcel() {
    // Get current values from the page
    var currentDate = document.getElementById('date').value;
    var filterSelect = document.getElementById('translation_filter');
    var currentFilter = filterSelect.value;
    var filterText = filterSelect.options[filterSelect.selectedIndex].text;
    
    // Get other filter values
    var bookCodeFilter = document.getElementById('book_code_filter').value;
    var classFilter = document.getElementById('class_filter').value;
    var remarksFilter = document.getElementById('remarks_filter').value;
    
    // Create HTML table structure for Excel
    var excelContent = `
        <html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
        <head>
            <meta charset="UTF-8">
            <meta http-equiv="content-type" content="application/vnd.ms-excel; charset=UTF-8">
            <style>
                table { border-collapse: collapse; width: 100%; }
                th, td { border: 1px solid #000; padding: 8px; text-align: center; }
                th { background-color: #f2f2f2; font-weight: bold; }
                .total-row { background-color: #e8f4f8; font-weight: bold; }
                .header { text-align: center; font-size: 16px; font-weight: bold; margin-bottom: 20px; }
                .book-type.translated { background-color: #e8f5e8; color: #2d5a2d; }
                .book-type.non-translated { background-color: #f0f8ff; color: #1e3a8a; }
            </style>
        </head>
        <body>
            <div class="header">
                <div>जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
                <div>दैनिक उत्पादन विवरण (Daily Production Report)</div>
                <div>मिति: ${currentDate} (${filterText})</div>`;
    
    if (bookCodeFilter || classFilter || remarksFilter) {
        excelContent += `<div>Filters Applied: `;
        if (bookCodeFilter) excelContent += `Book Code: ${bookCodeFilter} `;
        if (classFilter) excelContent += `Class: ${classFilter} `;
        if (remarksFilter) excelContent += `Remarks: ${remarksFilter}`;
        excelContent += `</div>`;
    }
    
    excelContent += `
            </div>
            <table>
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
                <tbody>`;
    
    // Calculate totals from actual data
    var totalPokaQty = 0;
    var totalOpenpcs = 0;
    var totalQty = 0;
    
    var rows = document.querySelectorAll("#reportTable tbody tr:not(.total-row)");
    rows.forEach(function(row, index) {
        excelContent += '<tr>';
        var cells = row.querySelectorAll("td");
        
        cells.forEach(function(cell, cellIndex) {
            var cellText = cell.textContent.trim();
            var cellClass = '';
            
            // Calculate totals
            if (cellIndex === 6) { // Poka Qty column
                totalPokaQty += parseInt(cellText.replace(/,/g, '')) || 0;
            } else if (cellIndex === 7) { // Openpcs column
                totalOpenpcs += parseInt(cellText.replace(/,/g, '')) || 0;
            } else if (cellIndex === 8) { // Total Qty column
                totalQty += parseInt(cellText.replace(/,/g, '')) || 0;
            }
            
            // Add class for book type styling
            if (cellIndex === 3) { // Type column
                cellClass = cell.className;
                cellText = cellText.replace(/\n/g, '<br>');
            }
            
            excelContent += `<td class="${cellClass}">${cellText}</td>`;
        });
        excelContent += '</tr>';
    });
    
    // Add total row
    excelContent += `
                    <tr class="total-row">
                        <td colspan="6"><strong>जम्मा (Total)</strong></td>
                        <td><strong>${totalPokaQty.toLocaleString()}</strong></td>
                        <td><strong>${totalOpenpcs.toLocaleString()}</strong></td>
                        <td><strong>${totalQty.toLocaleString()}</strong></td>
                        <td></td>
                    </tr>
                </tbody>
            </table>
        </body>
        </html>`;
    
    // Create and download Excel file
    var blob = new Blob([excelContent], { 
        type: 'application/vnd.ms-excel;charset=utf-8;' 
    });
    
    var link = document.createElement("a");
    var url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    
    // Create filename with filter info
    var filename = `daily_report_${currentDate}_${currentFilter}.xls`;
    link.setAttribute("download", filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    alert('Excel file exported successfully!');
}

function printReport() {
    // Get current values from the page
    var currentDate = document.getElementById('date').value;
    var filterSelect = document.getElementById('translation_filter');
    var currentFilter = filterSelect.value;
    var filterText = filterSelect.options[filterSelect.selectedIndex].text;
    
    // Get other filter values
    var bookCodeFilter = document.getElementById('book_code_filter').value;
    var classFilter = document.getElementById('class_filter').value;
    var remarksFilter = document.getElementById('remarks_filter').value;
    
    // Get the actual table data
    var tableRows = '';
    var totalPokaQty = 0, totalOpenpcs = 0, totalQty = 0;
    
    document.querySelectorAll("#reportTable tbody tr:not(.total-row)").forEach(function(row, index) {
        var cells = row.querySelectorAll("td");
        var sn = cells[0].textContent.trim();
        var bookName = cells[1].textContent.trim();
        var className = cells[2].textContent.trim();
        var bookType = cells[3].innerHTML;
        var bookCode = cells[4].textContent.trim();
        var perPoka = cells[5].textContent.trim();
        var pokaQty = cells[6].textContent.trim();
        var openpcs = cells[7].textContent.trim();
        var totalQ = cells[8].textContent.trim();
        var remarks = cells[9].textContent.trim();
        
        // Calculate totals
        totalPokaQty += parseInt(pokaQty.replace(/,/g, '')) || 0;
        totalOpenpcs += parseInt(openpcs.replace(/,/g, '')) || 0;
        totalQty += parseInt(totalQ.replace(/,/g, '')) || 0;
        
        // Determine book type class
        var typeClass = cells[3].classList.contains('translated') ? 'translated' : 'non-translated';
        
        tableRows += `
            <tr>
                <td>${sn}</td>
                <td>${bookName}</td>
                <td>${className}</td>
                <td class="book-type ${typeClass}">${bookType}</td>
                <td>${bookCode}</td>
                <td>${perPoka}</td>
                <td>${pokaQty}</td>
                <td>${openpcs}</td>
                <td>${totalQ}</td>
                <td>${remarks}</td>
            </tr>
        `;
    });
    
    // Add total row
    tableRows += `
        <tr class="total-row">
            <td colspan="6"><strong>जम्मा (Total)</strong></td>
            <td><strong>${totalPokaQty.toLocaleString()}</strong></td>
            <td><strong>${totalOpenpcs.toLocaleString()}</strong></td>
            <td><strong>${totalQty.toLocaleString()}</strong></td>
            <td></td>
        </tr>
    `;
    
    // Get summary data
    var recordCount = document.querySelectorAll("#reportTable tbody tr:not(.total-row)").length;
    var currentDateTime = new Date().toLocaleString();
    
    // Create filter summary
    var filterSummary = '';
    if (bookCodeFilter || classFilter || remarksFilter) {
        filterSummary = '<div class="summary-row"><strong>Filters Applied:</strong> ';
        if (bookCodeFilter) filterSummary += `Book Code: ${bookCodeFilter} `;
        if (classFilter) filterSummary += `Class: ${classFilter} `;
        if (remarksFilter) filterSummary += `Remarks: ${remarksFilter}`;
        filterSummary += '</div>';
    }
    
    // Create print content with zero margins
    var printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>दैनिक उत्पादन विवरण - ${currentDate}</title>
           <style>
                * {
                    margin: 0 !important;
                    padding: 0 !important;
                    box-sizing: border-box;
                }
                
                @page {
                    margin: 0 !important;
                    size: A4 landscape;
                }
                
                body {
                    font-family: Arial, sans-serif;
                    margin: 0 !important;
                    padding: 10px !important;
                    font-size: 14px; /* Increased from 11px */
                    line-height: 1.2;
                }
                
                .header {
                    text-align: center;
                    margin-bottom: 15px !important;
                    border-bottom: 2px solid #000;
                    padding-bottom: 10px !important;
                }
                
                .company-name {
                    font-size: 20px; /* Increased from 16px */
                    font-weight: bold;
                    margin-bottom: 5px !important;
                }
                
                .report-title {
                    font-size: 18px; /* Increased from 14px */
                    font-weight: bold;
                    margin-bottom: 10px !important;
                }
                
                .report-summary {
                    margin: 10px 0 !important;
                    border: 1px solid #000;
                    padding: 10px !important;
                    background: #f9f9f9;
                    font-size: 12px; /* Increased from 10px */
                }
                
                .summary-row {
                    display: flex;
                    justify-content: space-between;
                    margin: 5px 0 !important;
                    font-size: 12px; /* Increased from 10px */
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 10px !important;
                    font-size: 12px; /* Increased from 9px */
                }
                
                th, td {
                    border: 1px solid #000;
                    padding: 4px !important; /* Increased from 2px */
                    text-align: center;
                    font-size: 12px; /* Increased from 9px */
                    vertical-align: middle;
                }
                
                th {
                    background-color: #f0f0f0;
                    font-weight: bold;
                    font-size: 12px; /* Increased from 9px */
                    padding: 6px !important; /* Increased padding for headers */
                }
                
                .total-row {
                    background-color: #e8f4f8;
                    font-weight: bold;
                }
                
                .total-row td {
                    border-top: 2px solid #000;
                    font-size: 13px; /* Increased from 10px */
                    padding: 5px !important;
                }
                
                .book-type {
                    font-size: 11px; /* Increased from 8px */
                    padding: 4px !important; /* Increased from 2px */
                }
                
                .book-type.translated {
                    background-color: #e8f5e8;
                    color: #2d5a2d;
                }
                
                .book-type.non-translated {
                    background-color: #f0f8ff;
                    color: #1e3a8a;
                }
                
                .signature-section {
                    margin-top: 20px !important;
                    page-break-inside: avoid;
                }
                
                .signature-row {
                    display: flex;
                    justify-content: space-between;
                    margin-top: 20px !important;
                }
                
                .signature-item {
                    flex: 1;
                    text-align: center;
                    margin: 0 10px !important;
                }
                
                .signature-item p {
                    margin: 8px 0 !important;
                    font-weight: bold;
                    font-size: 13px; /* Increased from 10px */
                }
                
                .signature-line {
                    border-bottom: 1px solid #000;
                    height: 25px;
                    margin: 12px 5px !important;
                }
                
                .signature-label {
                    font-size: 11px; /* Increased from 8px */
                    color: #666;
                    font-weight: normal !important;
                }
                
                .footer {
                    margin-top: 15px !important;
                    border-top: 1px solid #000;
                    padding-top: 8px !important;
                    font-size: 11px; /* Increased from 8px */
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="company-name">जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
                <div style="margin: 3px 0; font-size: 12px;">सानोठिमी, भक्तपुर - उत्पादन विभाग</div>
                <div class="report-title">दैनिक उत्पादन विवरण (Daily Production Report)</div>
                <div style="margin-top: 5px; font-size: 12px;">मिति: ${currentDate} (${filterText})</div>
            </div>
            
            <div class="report-summary">
                <div class="summary-row">
                    <span><strong>मिति (Date):</strong> ${currentDate}</span>
                    <span><strong>कुल रेकर्ड (Total Records):</strong> ${recordCount}</span>
                    <span><strong>कुल उत्पादन (Total Produced):</strong> ${totalQty.toLocaleString()}</span>
                </div>
                <div class="summary-row">
                    <span><strong>कुल खुद्रा (Total Openpcs):</strong> ${totalOpenpcs.toLocaleString()}</span>
                    <span><strong>शुद्ध उत्पादन (Net Production):</strong> ${(totalQty + totalOpenpcs).toLocaleString()}</span>
                    <span><strong>तयार गरिएको (Generated):</strong> ${currentDateTime}</span>
                </div>
                ${filterSummary}
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th style="width: 5%;">सि.नं.<br>(SN)</th>
                        <th style="width: 25%;">पुस्तकको नाम<br>(Book Name)</th>
                        <th style="width: 8%;">कक्षा<br>(Class)</th>  
                        <th style="width: 10%;">प्रकार<br>(Type)</th>
                        <th style="width: 10%;">कोडा<br>(Code)</th>
                        <th style="width: 8%;">प्रति पोका<br>(Per Poka)</th>
                        <th style="width: 8%;">पुस्तक पोका<br>(Poka Qty)</th>
                        <th style="width: 8%;">खुद्रा पुस्तक<br>(Openpcs)</th>
                        <th style="width: 8%;">जम्मा पुस्तक<br>(Total Qty)</th>
                        <th style="width: 10%;">कैफियत<br>(Remarks)</th>
                    </tr>
                </thead>
                <tbody>
                    ${tableRows}
                </tbody>
            </table>
            
            <div class="signature-section">
                <div class="signature-row">
                    <div class="signature-item">
                        <p><strong>रुजु गरी निकासा गर्ने:</strong></p>
                        <div class="signature-line"></div>
                        <p class="signature-label">हस्ताक्षर (Signature)</p>
                        <p class="signature">(उत्पादन विभाग)</p>
                    </div>
                    <div class="signature-item">
                        <p><strong>बुझिलिनेको सही:</strong></p>
                        <div class="signature-line"></div>
                        <p class="signature-label">हस्ताक्षर (Signature)</p>
                        <p class="signature">(उत्पादन विभाग)</p>
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
        </body>
        </html>
    `;
    
    // Create new window and print
    var printWindow = window.open('', '_blank', 'width=1200,height=800');
    printWindow.document.open();
    printWindow.document.write(printContent);
    printWindow.document.close();
    
    // Wait for content to load, then print
    printWindow.onload = function() {
        printWindow.print();
    };
}

// Add functionality to maintain filter state in URL
document.addEventListener('DOMContentLoaded', function() {
    var filterForm = document.getElementById('filterForm');
    if (filterForm) {
        // Auto-submit on any filter change
        var filterInputs = filterForm.querySelectorAll('input, select');
        filterInputs.forEach(function(input) {
            input.addEventListener('change', function() {
                filterForm.submit();
            });
        });
    }
});
</script>

<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Default to today's date if not specified - use Nepali format
$date = $_GET['date'] ?? '2082.04.07'; // Sample Nepali date
$translation_filter = $_GET['translation_filter'] ?? 'all'; // all, translated, non_translated
$book_code_filter = $_GET['book_code_filter'] ?? '';
$class_filter = $_GET['class_filter'] ?? '';
$remarks_filter = $_GET['remarks_filter'] ?? '';

// Build the query based on all filters
$query = "
    SELECT d.*, b.book_name, b.is_translated, b.class_level
    FROM Deno d
    JOIN Books b ON d.book_code = b.book_code
    WHERE (d.deno_date_nep = :date OR d.deno_date_eng = :date)
";

$params = [':date' => $date];

// Add translation filter condition
if ($translation_filter === 'translated') {
    $query .= " AND b.is_translated = TRUE";
} elseif ($translation_filter === 'non_translated') {
    $query .= " AND b.is_translated = FALSE";
}

// Add book code filter (this is correct - book_code is character varying)
if (!empty($book_code_filter)) {
    $query .= " AND d.book_code LIKE :book_code";
    $params[':book_code'] = '%' . $book_code_filter . '%';
}

// Add class filter (FIXED: class_level is integer, use = instead of LIKE)
if (!empty($class_filter)) {
    $query .= " AND b.class_level = :class_level";
    $params[':class_level'] = $class_filter;
}

// Add remarks filter (this is correct - ref_no is character varying)
if (!empty($remarks_filter)) {
    $query .= " AND d.ref_no LIKE :remarks";
    $params[':remarks'] = '%' . $remarks_filter . '%';
}

$query .= " ORDER BY b.book_name";

// Get daily report
$stmt = $conn->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_qty = array_sum(array_column($records, 'total_qty'));
$total_openpcs = array_sum(array_column($records, 'quantity_openpcs'));
$total_poka_qty = array_sum(array_column($records, 'poka_qty'));

// Get filter display text
$filter_display = '';
switch ($translation_filter) {
    case 'translated':
        $filter_display = ' (अनुवादित मात्र / Translated Only)';
        break;
    case 'non_translated':
        $filter_display = ' (गैर-अनुवादित मात्र / Non-Translated Only)';
        break;
    default:
        $filter_display = ' (सबै / All Books)';
        break;
}

// Get unique values for filter dropdowns
$class_options_query = "SELECT DISTINCT class_level FROM Books WHERE class_level IS NOT NULL AND class_level > 0 ORDER BY class_level";

$class_stmt = $conn->prepare($class_options_query);
$class_stmt->execute();
$class_options = $class_stmt->fetchAll(PDO::FETCH_COLUMN);
?>

<h2>दैनिक उत्पादन विवरण (Daily Production Report)<?= $filter_display ?></h2>

<form method="get" id="filterForm" class="report-filter">
    <div class="filter-row">
        <div class="filter-group">
            <label for="date">मिति (Date) (YYYY.MM.DD):</label>
            <input type="text" name="date" id="date" value="<?= htmlspecialchars($date) ?>" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.15">
        </div>
        
        <div class="filter-group">
            <label for="translation_filter">पुस्तक प्रकार (Book Type):</label>
            <select name="translation_filter" id="translation_filter">
                <option value="all" <?= $translation_filter === 'all' ? 'selected' : '' ?>>सबै (All Books)</option>
                <option value="translated" <?= $translation_filter === 'translated' ? 'selected' : '' ?>>अनुवादित (Translated Only)</option>
                <option value="non_translated" <?= $translation_filter === 'non_translated' ? 'selected' : '' ?>>गैर-अनुवादित (Non-Translated Only)</option>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="book_code_filter">बुक कोड (Book Code):</label>
            <input type="text" name="book_code_filter" id="book_code_filter" value="<?= htmlspecialchars($book_code_filter) ?>" placeholder="Enter book code">
        </div>
        
        <div class="filter-group">
            <label for="class_filter">कक्षा (Class):</label>
            <select name="class_filter" id="class_filter">
                <option value="">सबै कक्षा (All Classes)</option>
                <?php foreach ($class_options as $class_option): ?>
                    <option value="<?= htmlspecialchars($class_option) ?>" <?= $class_filter === $class_option ? 'selected' : '' ?>>
                        <?= htmlspecialchars($class_option) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="remarks_filter">कैफियत (Remarks):</label>
            <input type="text" name="remarks_filter" id="remarks_filter" value="<?= htmlspecialchars($remarks_filter) ?>" placeholder="Enter remarks">
        </div>
        
        <div class="filter-group">
            <button type="submit">रिपोर्ट तयार गर्नुहोस् (Generate Report)</button>
        </div>
    </div>
</form>

<div class="report-summary">
    <p><strong>मिति (Date):</strong> <?= htmlspecialchars($date) ?></p>
    <p><strong>फिल्टर (Filter):</strong> <?= $filter_display ?></p>
    <?php if ($book_code_filter): ?>
        <p><strong>बुक कोड फिल्टर (Book Code Filter):</strong> <?= htmlspecialchars($book_code_filter) ?></p>
    <?php endif; ?>
    <?php if ($class_filter): ?>
        <p><strong>कक्षा फिल्टर (Class Filter):</strong> <?= htmlspecialchars($class_filter) ?></p>
    <?php endif; ?>
    <?php if ($remarks_filter): ?>
        <p><strong>कैफियत फिल्टर (Remarks Filter):</strong> <?= htmlspecialchars($remarks_filter) ?></p>
    <?php endif; ?>
    <p><strong>रेकर्ड संख्या (Records Found):</strong> <?= count($records) ?></p>
    <p><strong>कुल उत्पादन (Total Produced):</strong> <?= number_format($total_qty) ?></p>
    <p><strong>कुल खुद्रा (Total Openpcs):</strong> <?= number_format($total_openpcs) ?></p>
    <p><strong>शुद्ध उत्पादन (Net Production):</strong> <?= number_format($total_qty + $total_openpcs) ?></p>
</div>

<?php if (empty($records)): ?>
    <div class="no-data">
        <p><?= htmlspecialchars($date) ?> को लागि <?= $filter_display ?> कुनै उत्पादन डाटा फेला परेन।</p>
        <p>फरक मिति वा फिल्टर प्रयोग गर्नुहोस् वा यो मितिको लागि डाटा छ कि छैन जाँच गर्नुहोस्।</p>
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
            <?php 
            $sn = 1; 
            foreach ($records as $record): ?>
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
            <!-- Total Row -->
            <tr class="total-row">
                <td colspan="6"><strong>जम्मा (Total)</strong></td>
                <td><strong><?= number_format($total_poka_qty) ?></strong></td>
                <td><strong><?= number_format($total_openpcs) ?></strong></td>
                <td><strong><?= number_format($total_qty) ?></strong></td>
                <td></td>
            </tr>
        </tbody>
    </table>

    <!-- Signature Section -->
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

    <div class="print-actions" style="margin-top: 20px;">
        <button onclick="printReport()" class="btn-print">🖨️ छाप्नुहोस् (Print Report)</button>
        <button onclick="exportToCSV()" class="btn-export">📊 CSV मा निर्यात गर्नुहोस् (Export to CSV)</button>
        <button onclick="exportToExcel()" class="btn-excel">📋 Excel मा निर्यात गर्नुहोस् (Export to Excel)</button>
    </div>
<?php endif; ?>

<style>
.report-filter {
    background: #f5f5f5;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.filter-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 15px;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 8px;
}

.filter-group label {
    font-weight: bold;
    white-space: nowrap;
    font-size: 12px;
}

.filter-group input, .filter-group select {
    padding: 8px;
    border: 1px solid #ddd;
    border-radius: 4px;
    min-width: 120px;
    font-size: 12px;
}

.filter-group button {
    padding: 8px 15px;
    background: #007cba;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
    white-space: nowrap;
    font-size: 12px;
}

.filter-group button:hover {
    background: #005a87;
}

.report-summary {
    background: #e8f4f8;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.report-summary p {
    margin: 5px 0;
    font-size: 13px;
}

.no-data {
    text-align: center;
    padding: 40px;
    background: #f9f9f9;
    border-radius: 5px;
    color: #666;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    font-size: 11px;
}

th, td {
    border: 1px solid #000;
    padding: 6px;
    text-align: center;
    vertical-align: middle;
}

th {
    background-color: #f2f2f2;
    font-weight: bold;
    font-size: 10px;
    line-height: 1.2;
}

tr:nth-child(even) {
    background-color: #f9f9f9;
}

.total-row {
    background-color: #e8f4f8 !important;
    font-weight: bold;
}

.total-row td {
    border-top: 2px solid #000;
}

.book-type {
    font-size: 9px;
    padding: 4px;
    line-height: 1.1;
}

.book-type.translated {
    background-color: #e8f5e8;
    color: #2d5a2d;
}

.book-type.non-translated {
    background-color: #f0f8ff;
    color: #1e3a8a;
}

.signature-section {
    margin-top: 40px;
    page-break-inside: avoid;
}

.signature-row {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
}

.signature-item {
    flex: 1;
    text-align: center;
    margin: 0 20px;
}

.signature-item p {
    margin: 10px 0;
    font-weight: bold;
}

.signature-line {
    border-bottom: 1px solid #000;
    height: 40px;
    margin: 20px 10px;
}

.signature-label {
    font-size: 12px;
    color: #666;
    font-weight: normal !important;
}

.btn-print, .btn-export, .btn-excel {
    padding: 10px 20px;
    margin-right: 10px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
}

.btn-print {
    background: #28a745;
    color: white;
}

.btn-export {
    background: #17a2b8;
    color: white;
}

.btn-excel {
    background: #007bff;
    color: white;
}

.btn-print:hover, .btn-export:hover, .btn-excel:hover {
    opacity: 0.8;
}

/* Print styles with zero margins for landscape A4 */
@media print {
    .report-filter, .print-actions, nav, header, footer {
        display: none !important;
    }
    
    * {
        margin: 0 !important;
        padding: 0 !important;
    }
    
    @page {
        margin: 0 !important;
        size: A4 landscape;
    }
    
    body {
        font-size: 9px !important;
        margin: 0 !important;
        padding: 3px !important;
        line-height: 1.0;
    }
    
    h2 {
        font-size: 14px !important;
        margin-bottom: 8px !important;
        text-align: center;
    }
    
    table {
        font-size: 8px !important;
        width: 100%;
        margin-top: 5px !important;
    }
    
    th, td {
        padding: 2px !important;
        font-size: 8px !important;
        border: 1px solid #000;
    }
    
    th {
        font-size: 8px !important;
        font-weight: bold;
    }
    
    .report-summary {
        background: none;
        border: 1px solid #000;
        padding: 5px !important;
        margin: 5px 0 !important;
        font-size: 8px !important;
    }
    
    .report-summary p {
        margin: 2px 0 !important;
        font-size: 8px !important;
    }
    
    .signature-section {
        margin-top: 15px !important;
        page-break-inside: avoid;
    }
    
    .signature-row {
        display: flex;
        justify-content: space-between;
        margin-top: 10px !important;
    }
    
    .signature-item {
        flex: 1;
        margin: 0 5px !important;
    }
    
    .signature-item p {
        font-size: 8px !important;
        margin: 3px 0 !important;
    }
    
    .signature-line {
        height: 15px !important;
        margin: 5px 2px !important;
    }
    
    .signature-label {
        font-size: 7px !important;
    }
    
    .book-type {
        font-size: 7px !important;
    }
    
    .total-row td {
        font-size: 8px !important;
        font-weight: bold;
    }
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
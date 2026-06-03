<?php 
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

// For actions that modify data, add role checks
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    // Only allow editors and admins to modify data
    if (!has_role('supervisor') && !has_role('incharge') && !has_role('admin')) {
        echo "<div class='alert alert-danger'>You don't have permission to perform this action.</div>";
        exit();
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Default date range - last 30 days in Nepali format
$date_from = $_GET['date_from'] ?? date('Y.m.d', strtotime('+57 years -30 days')); // Approximate Nepali date
$date_to = $_GET['date_to'] ?? date('Y.m.d', strtotime('+57 years')); // Current approximate Nepali date
$book_code_filter = $_GET['book_code'] ?? '';
$fiscal_year_filter = $_GET['fiscal_year_id'] ?? '';
$packing_status_filter = $_GET['packing_status'] ?? 'all';
$supervisor_filter = $_GET['supervisor_id'] ?? '';
$class_level_filter = $_GET['class_level'] ?? '';

// Build the query based on filters
$where_conditions = ["bp.status = true"];
$params = [];

if (!empty($date_from)) {
    $where_conditions[] = "bp.date_nep >= :date_from";
    $params[':date_from'] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "bp.date_nep <= :date_to";
    $params[':date_to'] = $date_to;
}

if (!empty($book_code_filter)) {
    $where_conditions[] = "bp.book_code = :book_code";
    $params[':book_code'] = $book_code_filter;
}

if (!empty($fiscal_year_filter)) {
    $where_conditions[] = "bp.fiscal_year_id = :fiscal_year_id";
    $params[':fiscal_year_id'] = $fiscal_year_filter;
}

if ($packing_status_filter !== 'all') {
    $where_conditions[] = "bp.packing_status = :packing_status";
    $params[':packing_status'] = $packing_status_filter;
}

if (!empty($supervisor_filter)) {
    $where_conditions[] = "bp.supervisor_id = :supervisor_id";
    $params[':supervisor_id'] = $supervisor_filter;
}

if (!empty($class_level_filter)) {
    $where_conditions[] = "b.class_level = :class_level";
    $params[':class_level'] = $class_level_filter;
}

$where_clause = implode(' AND ', $where_conditions);

// Main query to get packing records
$query = "
    SELECT 
        bp.*,
        jt.job_ticket_code,
        jt.lot,
        b.book_name,
        b.book_code as book_code_full,
        b.class_level,
        b.is_translated,
        fy.fiscal_code,
        u_supervisor.username as supervisor_name,
        u_incharge.username as incharge_name,
        u_operator.username as operator_name,
        u_created.username as created_by_name
    FROM book_packing bp
    LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN fiscal_years fy ON bp.fiscal_year_id = fy.id
    LEFT JOIN users u_supervisor ON bp.supervisor_id = u_supervisor.id
    LEFT JOIN users u_incharge ON bp.incharge_id = u_incharge.id
    LEFT JOIN users u_operator ON bp.operator_id = u_operator.id
    LEFT JOIN users u_created ON bp.created_by = u_created.id
    WHERE {$where_clause}
    ORDER BY bp.date_nep DESC, bp.id DESC
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_packed_qty = array_sum(array_column($records, 'p_qty'));
$total_print_qty = array_sum(array_column($records, 'jt_print_qty'));
$unique_books = count(array_unique(array_column($records, 'book_code')));
$unique_job_tickets = count(array_unique(array_column($records, 'jt_id')));

// Get filter options
$fiscal_years = $conn->query("SELECT id, fiscal_code FROM fiscal_years ORDER BY fiscal_code DESC")->fetchAll(PDO::FETCH_ASSOC);
$book_codes = $conn->query("
    SELECT DISTINCT bp.book_code, b.book_name 
    FROM book_packing bp 
    LEFT JOIN job_ticket jt ON bp.jt_id = jt.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    WHERE bp.status = true 
    ORDER BY bp.book_code
")->fetchAll(PDO::FETCH_ASSOC);
$supervisors = $conn->query("
    SELECT DISTINCT u.id, u.username 
    FROM users u 
    INNER JOIN book_packing bp ON u.id = bp.supervisor_id 
    WHERE bp.status = true AND u.role IN ('supervisor', 'admin') 
    ORDER BY u.username
")->fetchAll(PDO::FETCH_ASSOC);
$class_levels = $conn->query("
    SELECT DISTINCT b.class_level 
    FROM books b 
    INNER JOIN job_ticket jt ON b.book_id = jt.book_id
    INNER JOIN book_packing bp ON jt.id = bp.jt_id
    WHERE bp.status = true AND b.class_level IS NOT NULL
    ORDER BY b.class_level
")->fetchAll(PDO::FETCH_ASSOC);

// Get filter display text
$filter_display = [];
if (!empty($date_from) || !empty($date_to)) {
    $filter_display[] = "मिति: {$date_from} देखि {$date_to} सम्म";
}
if ($packing_status_filter !== 'all') {
    $status_names = ['active' => 'सक्रिय', 'completed' => 'सम्पन्न', 'pending' => 'बाँकी'];
    $filter_display[] = "स्थिति: " . ($status_names[$packing_status_filter] ?? $packing_status_filter);
}
if (!empty($book_code_filter)) {
    $filter_display[] = "पुस्तक कोड: {$book_code_filter}";
}

$filter_text = !empty($filter_display) ? ' (' . implode(', ', $filter_display) . ')' : ' (सबै रेकर्ड)';
?>

<script>
function exportToCSV() {
    // Get current filter values
    var dateFrom = document.getElementById('date_from').value || 'सबै';
    var dateTo = document.getElementById('date_to').value || 'सबै';
    var packingStatus = document.getElementById('packing_status').value;
    var statusText = packingStatus === 'all' ? 'सबै' : 
                    packingStatus === 'active' ? 'सक्रिय' : 
                    packingStatus === 'completed' ? 'सम्पन्न' : 'बाँकी';
    
    // Prepare CSV data with UTF-8 BOM
    var csvContent = "\uFEFF";
    
    // Add header information
    csvContent += `"पुस्तक प्याकिङ रिपोर्ट (Book Packing Report)"\n`;
    csvContent += `"मिति: ${dateFrom} देखि ${dateTo} सम्म"\n`;
    csvContent += `"स्थिति: ${statusText}"\n\n`;
    
    // Add column headers in Nepali and English
    csvContent += "सि.नं.,रेकर्ड नाम,जब टिकट,पुस्तकको नाम,कक्षा,प्रकार,पुस्तक कोड,प्याक मात्रा,जेटी प्रिन्ट मात्रा,मिति (नेपाली),मिति (अंग्रेजी),सुपरभाइजर,इन्चार्ज,अपरेटर,स्थिति,वित्तीय वर्ष,कैफियत\n";
    csvContent += "SN,Record Name,Job Ticket,Book Name,Class,Type,Book Code,Packed Qty,JT Print Qty,Date Nep,Date Eng,Supervisor,Incharge,Operator,Status,Fiscal Year,Remarks\n";
    
    // Calculate totals
    var totalPackedQty = 0;
    var totalPrintQty = 0;
    
    var rows = document.querySelectorAll("#reportTable tbody tr:not(.total-row)");
    rows.forEach(function(row, index) {
        var cells = row.querySelectorAll("td");
        var rowData = [];
        
        cells.forEach(function(cell, cellIndex) {
            var cellText = cell.textContent.trim();
            
            // Calculate totals for specific columns
            if (cellIndex === 7) { // Packed Qty column
                totalPackedQty += parseInt(cellText.replace(/,/g, '')) || 0;
            } else if (cellIndex === 8) { // JT Print Qty column
                totalPrintQty += parseInt(cellText.replace(/,/g, '')) || 0;
            }
            
            // Clean up cell text
            if (cellIndex === 4) { // Type column
                cellText = cellText.replace(/\n/g, ' ').replace(/\s+/g, ' ').trim();
            }
            
            // Escape quotes and wrap if contains comma or quotes
            if (cellText.includes(',') || cellText.includes('"') || cellText.includes('\n')) {
                cellText = '"' + cellText.replace(/"/g, '""') + '"';
            }
            rowData.push(cellText);
        });
        csvContent += rowData.join(",") + "\n";
    });
    
    // Add total row
    csvContent += `"जम्मा (Total)","","","","","","","${totalPackedQty.toLocaleString()}","${totalPrintQty.toLocaleString()}","","","","","","","",""\n`;
    
    // Create and download CSV file
    var blob = new Blob([csvContent], { 
        type: 'text/csv;charset=utf-8;' 
    });
    
    var link = document.createElement("a");
    var url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    
    // Create filename
    var filename = `packing_report_${dateFrom}_${dateTo}.csv`;
    link.setAttribute("download", filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    alert('CSV file exported successfully! Open with Excel for best results.');
}

function exportToExcel() {
    // Get current filter values
    var dateFrom = document.getElementById('date_from').value || 'सबै';
    var dateTo = document.getElementById('date_to').value || 'सबै';
    var packingStatus = document.getElementById('packing_status').value;
    var statusText = packingStatus === 'all' ? 'सबै' : 
                    packingStatus === 'active' ? 'सक्रिय' : 
                    packingStatus === 'completed' ? 'सम्पन्न' : 'बाँकी';
    
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
                .status-active { background-color: #d4edda; color: #155724; }
                .status-completed { background-color: #cce7ff; color: #004085; }
                .status-pending { background-color: #fff3cd; color: #856404; }
            </style>
        </head>
        <body>
            <div class="header">
                <div>जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
                <div>पुस्तक प्याकिङ रिपोर्ट (Book Packing Report)</div>
                <div>मिति: ${dateFrom} देखि ${dateTo} सम्म (${statusText})</div>
            </div>
            <table>
                <thead>
                    <tr>
                        <th>सि.नं.<br>(SN)</th>
                        <th>रेकर्ड नाम<br>(Record Name)</th>
                        <th>जब टिकट<br>(Job Ticket)</th>
                        <th>पुस्तकको नाम<br>(Book Name)</th>
                        <th>कक्षा<br>(Class)</th>
                        <th>प्रकार<br>(Type)</th>
                        <th>पुस्तक कोड<br>(Book Code)</th>
                        <th>प्याक मात्रा<br>(Packed Qty)</th>
                        <th>जेटी प्रिन्ट मात्रा<br>(JT Print Qty)</th>
                        <th>मिति (नेपाली)<br>(Date Nep)</th>
                        <th>मिति (अंग्रेजी)<br>(Date Eng)</th>
                        <th>सुपरभाइजर<br>(Supervisor)</th>
                        <th>इन्चार्ज<br>(Incharge)</th>
                        <th>अपरेटर<br>(Operator)</th>
                        <th>स्थिति<br>(Status)</th>
                        <th>वित्तीय वर्ष<br>(Fiscal Year)</th>
                        <th>कैफियत<br>(Remarks)</th>
                    </tr>
                </thead>
                <tbody>`;
    
    // Calculate totals
    var totalPackedQty = 0;
    var totalPrintQty = 0;
    
    var rows = document.querySelectorAll("#reportTable tbody tr:not(.total-row)");
    rows.forEach(function(row, index) {
        excelContent += '<tr>';
        var cells = row.querySelectorAll("td");
        
        cells.forEach(function(cell, cellIndex) {
            var cellText = cell.textContent.trim();
            var cellClass = '';
            
            // Calculate totals
            if (cellIndex === 7) { // Packed Qty column
                totalPackedQty += parseInt(cellText.replace(/,/g, '')) || 0;
            } else if (cellIndex === 8) { // JT Print Qty column
                totalPrintQty += parseInt(cellText.replace(/,/g, '')) || 0;
            }
            
            // Add class for styling
            if (cellIndex === 5) { // Type column
                cellClass = cell.className;
                cellText = cellText.replace(/\n/g, '<br>');
            } else if (cellIndex === 14) { // Status column
                cellClass = cell.querySelector('.status-badge') ? cell.querySelector('.status-badge').className : '';
            }
            
            excelContent += `<td class="${cellClass}">${cellText}</td>`;
        });
        excelContent += '</tr>';
    });
    
    // Add total row
    excelContent += `
                    <tr class="total-row">
                        <td colspan="7"><strong>जम्मा (Total)</strong></td>
                        <td><strong>${totalPackedQty.toLocaleString()}</strong></td>
                        <td><strong>${totalPrintQty.toLocaleString()}</strong></td>
                        <td colspan="7"></td>
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
    
    // Create filename
    var filename = `packing_report_${dateFrom}_${dateTo}.xls`;
    link.setAttribute("download", filename);
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
    
    alert('Excel file exported successfully!');
}

function printReport() {
    // Get current filter values
    var dateFrom = document.getElementById('date_from').value || 'सबै';
    var dateTo = document.getElementById('date_to').value || 'सबै';
    var packingStatus = document.getElementById('packing_status').value;
    var statusText = packingStatus === 'all' ? 'सबै' : 
                    packingStatus === 'active' ? 'सक्रिय' : 
                    packingStatus === 'completed' ? 'सम्पन्न' : 'बाँकी';
    
    // Get the actual table data
    var tableRows = '';
    var totalPackedQty = 0, totalPrintQty = 0;
    
    document.querySelectorAll("#reportTable tbody tr:not(.total-row)").forEach(function(row, index) {
        var cells = row.querySelectorAll("td");
        var sn = cells[0].textContent.trim();
        var recordName = cells[1].textContent.trim();
        var jobTicket = cells[2].textContent.trim();
        var bookName = cells[3].textContent.trim();
        var className = cells[4].textContent.trim();
        var bookType = cells[5].innerHTML;
        var bookCode = cells[6].textContent.trim();
        var packedQty = cells[7].textContent.trim();
        var jtPrintQty = cells[8].textContent.trim();
        var dateNep = cells[9].textContent.trim();
        var dateEng = cells[10].textContent.trim();
        var supervisor = cells[11].textContent.trim();
        var incharge = cells[12].textContent.trim();
        var operator = cells[13].textContent.trim();
        var status = cells[14].innerHTML;
        var fiscalYear = cells[15].textContent.trim();
        var remarks = cells[16].textContent.trim();
        
        // Calculate totals
        totalPackedQty += parseInt(packedQty.replace(/,/g, '')) || 0;
        totalPrintQty += parseInt(jtPrintQty.replace(/,/g, '')) || 0;
        
        // Determine type class
        var typeClass = cells[5].classList.contains('translated') ? 'translated' : 'non-translated';
        
        tableRows += `
            <tr>
                <td>${sn}</td>
                <td>${recordName}</td>
                <td>${jobTicket}</td>
                <td>${bookName}</td>
                <td>${className}</td>
                <td class="book-type ${typeClass}">${bookType}</td>
                <td>${bookCode}</td>
                <td>${packedQty}</td>
                <td>${jtPrintQty}</td>
                <td>${dateNep}</td>
                <td>${dateEng}</td>
                <td>${supervisor}</td>
                <td>${incharge}</td>
                <td>${operator}</td>
                <td>${status}</td>
                <td>${fiscalYear}</td>
                <td>${remarks}</td>
            </tr>
        `;
    });
    
    // Add total row
    tableRows += `
        <tr class="total-row">
            <td colspan="7"><strong>जम्मा (Total)</strong></td>
            <td><strong>${totalPackedQty.toLocaleString()}</strong></td>
            <td><strong>${totalPrintQty.toLocaleString()}</strong></td>
            <td colspan="7"></td>
        </tr>
    `;
    
    // Get summary data
    var recordCount = document.querySelectorAll("#reportTable tbody tr:not(.total-row)").length;
    var currentDateTime = new Date().toLocaleString();
    
    // Create print content
    var printContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>पुस्तक प्याकिङ रिपोर्ट - ${dateFrom} to ${dateTo}</title>
            <style>
                * {
                    margin: 0;
                    padding: 0;
                    box-sizing: border-box;
                }
                
                @page {
                    margin: 0;
                    size: A4 landscape;
                }
                
                body {
                    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
                    margin: 0;
                    padding: 15px;
                    font-size: 20px;
                    line-height: 1.2;
                }
                
                .header {
                    text-align: center;
                    margin-bottom: 20px;
                    border-bottom: 3px solid #000;
                    padding-bottom: 15px;
                }
                
                .company-name {
                    font-size: 28px;
                    font-weight: bold;
                    margin-bottom: 8px;
                }
                
                .report-title {
                    font-size: 24px;
                    font-weight: bold;
                    margin-bottom: 15px;
                }
                
                .report-summary {
                    margin: 15px 0;
                    border: 2px solid #000;
                    padding: 15px;
                    background: #f9f9f9;
                }
                
                .summary-row {
                    display: flex;
                    justify-content: space-between;
                    margin: 8px 0;
                    font-size: 18px;
                }
                
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 15px;
                    font-size: 14px;
                }
                
                th, td {
                    border: 1px solid #000;
                    padding: 4px;
                    text-align: center;
                    vertical-align: middle;
                }
                
                th {
                    background-color: #f0f0f0;
                    font-weight: bold;
                    font-size: 12px;
                }
                
                .total-row {
                    background-color: #e8f4f8;
                    font-weight: bold;
                }
                
                .total-row td {
                    border-top: 3px solid #000;
                    font-size: 16px;
                }
                
                .book-type {
                    font-size: 10px;
                    padding: 2px;
                }
                
                .book-type.translated {
                    background-color: #e8f5e8;
                    color: #2d5a2d;
                }
                
                .book-type.non-translated {
                    background-color: #f0f8ff;
                    color: #1e3a8a;
                }
                
                .status-badge {
                    padding: 2px 4px;
                    border-radius: 4px;
                    font-size: 10px;
                    font-weight: bold;
                }
                
                .status-active { background-color: #d4edda; color: #155724; }
                .status-completed { background-color: #cce7ff; color: #004085; }
                .status-pending { background-color: #fff3cd; color: #856404; }
                
                .signature-section {
                    margin-top: 30px;
                    page-break-inside: avoid;
                }
                
                .signature-row {
                    display: flex;
                    justify-content: space-between;
                    margin-top: 20px;
                }
                
                .signature-item {
                    flex: 1;
                    text-align: center;
                    margin: 0 10px;
                }
                
                .signature-item p {
                    margin: 8px 0;
                    font-weight: bold;
                    font-size: 16px;
                }
                
                .signature-line {
                    border-bottom: 2px solid #000;
                    height: 30px;
                    margin: 15px 5px;
                }
                
                .signature-label {
                    font-size: 14px;
                    color: #666;
                    font-weight: normal !important;
                }
                
                .footer {
                    margin-top: 20px;
                    border-top: 2px solid #000;
                    padding-top: 10px;
                    font-size: 14px;
                    text-align: center;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="company-name">जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
                <div style="margin: 8px 0; font-size: 18px;">सानोठिमी, भक्तपुर</div>
                <div style="margin: 8px 0; font-size: 18px;">उत्पादन विभाग</div>
                <div class="report-title">पुस्तक प्याकिङ रिपोर्ट (Book Packing Report)</div>
                <div style="margin-top: 10px; font-size: 18px;">मिति: ${dateFrom} देखि ${dateTo} सम्म (${statusText})</div>
            </div>
            
            <div class="report-summary">
                <div class="summary-row">
                    <span><strong>मिति दायरा (Date Range):</strong> ${dateFrom} - ${dateTo}</span>
                    <span><strong>कुल रेकर्ड (Total Records):</strong> ${recordCount}</span>
                </div>
                <div class="summary-row">
                    <span><strong>कुल प्याक मात्रा (Total Packed):</strong> ${totalPackedQty.toLocaleString()}</span>
                    <span><strong>कुल प्रिन्ट मात्रा (Total Print):</strong> ${totalPrintQty.toLocaleString()}</span>
                </div>
                <div class="summary-row">
                    <span><strong>स्थिति फिल्टर (Status Filter):</strong> ${statusText}</span>
                    <span><strong>तयार गरिएको (Generated):</strong> ${currentDateTime}</span>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>सि.नं.</th>
                        <th>रेकर्ड नाम</th>
                        <th>जब टिकट</th>
                        <th>पुस्तकको नाम</th>
                        <th>कक्षा</th>
                        <th>प्रकार</th>
                        <th>कोड</th>
                        <th>प्याक मात्रा</th>
                        <th>जेटी प्रिन्ट</th>
                        <th>मिति (नेप)</th>
                        <th>मिति (अंग्र)</th>
                        <th>सुपरभाइजर</th>
                        <th>इन्चार्ज</th>
                        <th>अपरेटर</th>
                        <th>स्थिति</th>
                        <th>आर्थिक वर्ष</th>
                        <th>कैफियत</th>
                    </tr>
                </thead>
                <tbody>
                    ${tableRows}
                </tbody>
            </table>
            
            <div class="signature-section">
                <div class="signature-row">
                    <div class="signature-item">
                        <p><strong>तयार गर्ने:</strong></p>
                        <div class="signature-line"></div>
                        <p class="signature-label">हस्ताक्षर (Signature)</p>
                        <p class="signature-label">(उत्पादन विभाग)</p>
                    </div>
                    <div class="signature-item">
                        <p><strong>जाँच गर्ने:</strong></p>
                        <div class="signature-line"></div>
                        <p class="signature-label">हस्ताक्षर (Signature)</p>
                        <p class="signature-label">(गुणस्तर नियन्त्रण)</p>
                    </div>
                </div>
            </div>
            
            <div class="footer">
                <p>रिपोर्ट तयार गरिएको मिति: ${currentDateTime} | Report generated on: ${currentDateTime}</p>
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
    var filterElements = document.querySelectorAll('select, input[name="date_from"], input[name="date_to"]');
    filterElements.forEach(function(element) {
        element.addEventListener('change', function() {
            // Auto-submit form when filter changes
            this.form.submit();
        });
    });
});
</script>

<h2>पुस्तक प्याकिङ रिपोर्ट (Book Packing Report)<?= $filter_text ?></h2>

<form method="get" class="report-filter">
    <div class="filter-row">
        <div class="filter-group">
            <label for="date_from">मिति देखि (Date From) (YYYY.MM.DD):</label>
            <input type="text" name="date_from" id="date_from" value="<?= htmlspecialchars($date_from) ?>" 
                   pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.01">
        </div>
        
        <div class="filter-group">
            <label for="date_to">मिति सम्म (Date To) (YYYY.MM.DD):</label>
            <input type="text" name="date_to" id="date_to" value="<?= htmlspecialchars($date_to) ?>" 
                   pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.30">
        </div>
        
        <div class="filter-group">
            <label for="packing_status">प्याकिङ स्थिति (Packing Status):</label>
            <select name="packing_status" id="packing_status">
                <option value="all" <?= $packing_status_filter === 'all' ? 'selected' : '' ?>>सबै (All Status)</option>
                <option value="active" <?= $packing_status_filter === 'active' ? 'selected' : '' ?>>सक्रिय (Active)</option>
                <option value="completed" <?= $packing_status_filter === 'completed' ? 'selected' : '' ?>>सम्पन्न (Completed)</option>
                <option value="pending" <?= $packing_status_filter === 'pending' ? 'selected' : '' ?>>बाँकी (Pending)</option>
            </select>
        </div>
    </div>
    
    <div class="filter-row">
        <div class="filter-group">
            <label for="book_code">पुस्तक कोड (Book Code):</label>
            <select name="book_code" id="book_code">
                <option value="">सबै पुस्तक (All Books)</option>
                <?php foreach ($book_codes as $book): ?>
                    <option value="<?= htmlspecialchars($book['book_code']) ?>" 
                            <?= $book_code_filter === $book['book_code'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($book['book_code']) ?> - <?= htmlspecialchars($book['book_name']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="fiscal_year_id">आर्थिक वर्ष (Fiscal Year):</label>
            <select name="fiscal_year_id" id="fiscal_year_id">
                <option value="">सबै आर्थिक वर्ष (All Years)</option>
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($fy['fiscal_code']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <label for="supervisor_id">सुपरभाइजर (Supervisor):</label>
            <select name="supervisor_id" id="supervisor_id">
                <option value="">सबै सुपरभाइजर (All Supervisors)</option>
                <?php foreach ($supervisors as $supervisor): ?>
                    <option value="<?= $supervisor['id'] ?>" <?= $supervisor_filter == $supervisor['id'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($supervisor['username']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    
    <div class="filter-row">
        <div class="filter-group">
            <label for="class_level">कक्षा (Class Level):</label>
            <select name="class_level" id="class_level">
                <option value="">सबै कक्षा (All Classes)</option>
                <?php foreach ($class_levels as $class): ?>
                    <option value="<?= htmlspecialchars($class['class_level']) ?>" 
                            <?= $class_level_filter === $class['class_level'] ? 'selected' : '' ?>>
                        <?= htmlspecialchars($class['class_level']) ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        
        <div class="filter-group">
            <button type="submit">रिपोर्ट तयार गर्नुहोस् (Generate Report)</button>
        </div>
        
        <div class="filter-group">
            <a href="?" class="btn-clear">फिल्टर क्लियर गर्नुहोस् (Clear Filters)</a>
        </div>
    </div>
</form>

<div class="report-summary">
    <p><strong>मिति दायरा (Date Range):</strong> <?= htmlspecialchars($date_from) ?> देखि <?= htmlspecialchars($date_to) ?> सम्म</p>
    <p><strong>फिल्टर (Filters):</strong> <?= $filter_text ?></p>
    <p><strong>रेकर्ड संख्या (Records Found):</strong> <?= count($records) ?></p>
    <p><strong>कुल प्याक मात्रा (Total Packed Qty):</strong> <?= number_format($total_packed_qty) ?></p>
    <p><strong>कुल प्रिन्ट मात्रा (Total Print Qty):</strong> <?= number_format($total_print_qty) ?></p>
    <p><strong>विभिन्न पुस्तकहरू (Unique Books):</strong> <?= $unique_books ?></p>
    <p><strong>विभिन्न जब टिकटहरू (Unique Job Tickets):</strong> <?= $unique_job_tickets ?></p>
</div>

<?php if (empty($records)): ?>
    <div class="no-data">
        <p>चयन गरिएको मिति र फिल्टरको लागि कुनै प्याकिङ रेकर्ड फेला परेन।</p>
        <p>फरक मिति दायरा वा फिल्टर प्रयोग गर्नुहोस्।</p>
    </div>
<?php else: ?>
    <table id="reportTable">
        <thead>
            <tr>
                <th>सि.नं.<br>(SN)</th>
                <th>रेकर्ड नाम<br>(Record Name)</th>
                <th>जब टिकट<br>(Job Ticket)</th>
                <th>पुस्तकको नाम<br>(Book Name)</th>
                <th>कक्षा<br>(Class)</th>
                <th>प्रकार<br>(Type)</th>
                <th>पुस्तक कोड<br>(Book Code)</th>
                <th>प्याक मात्रा<br>(Packed Qty)</th>
                <th>जेटी प्रिन्ट मात्रा<br>(JT Print Qty)</th>
                <th>मिति (नेपाली)<br>(Date Nep)</th>
                <th>मिति (अंग्रेजी)<br>(Date Eng)</th>
                <th>सुपरभाइजर<br>(Supervisor)</th>
                <th>इन्चार्ज<br>(Incharge)</th>
                <th>अपरेटर<br>(Operator)</th>
                <th>स्थिति<br>(Status)</th>
                <th>वित्तीय वर्ष<br>(Fiscal Year)</th>
                <th>कैफियत<br>(Remarks)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sn = 1; 
            foreach ($records as $record): ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td><?= htmlspecialchars($record['name']) ?></td>
                <td><?= htmlspecialchars($record['job_ticket_code']) ?></td>
                <td><?= htmlspecialchars($record['book_name']) ?></td>
                <td><?= htmlspecialchars($record['class_level']) ?></td>
                <td class="book-type <?= $record['is_translated'] ? 'translated' : 'non-translated' ?>">
                    <?= $record['is_translated'] ? 'अनुवादित<br>(Translated)' : 'गैर-अनुवादित<br>(Original)' ?>
                </td>
                <td><?= htmlspecialchars($record['book_code']) ?></td>
                <td><?= number_format($record['p_qty']) ?></td>
                <td><?= number_format($record['jt_print_qty']) ?></td>
                <td><?= htmlspecialchars($record['date_nep']) ?></td>
                <td><?= htmlspecialchars($record['date_eng']) ?></td>
                <td><?= htmlspecialchars($record['supervisor_name']) ?></td>
                <td><?= htmlspecialchars($record['incharge_name']) ?></td>
                <td><?= htmlspecialchars($record['operator_name']) ?></td>
                <td>
                    <span class="status-badge status-<?= $record['packing_status'] ?>">
                        <?php 
                        $status_names = [
                            'active' => 'सक्रिय',
                            'completed' => 'सम्पन्न', 
                            'pending' => 'बाँकी'
                        ];
                        echo $status_names[$record['packing_status']] ?? $record['packing_status'];
                        ?>
                    </span>
                </td>
                <td><?= htmlspecialchars($record['fiscal_code']) ?></td>
                <td><?= htmlspecialchars($record['remarks'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
            <!-- Total Row -->
            <tr class="total-row">
                <td colspan="7"><strong>जम्मा (Total)</strong></td>
                <td><strong><?= number_format($total_packed_qty) ?></strong></td>
                <td><strong><?= number_format($total_print_qty) ?></strong></td>
                <td colspan="7"></td>
            </tr>
        </tbody>
    </table>

    <!-- Signature Section -->
    <div class="signature-section">
        <div class="signature-row">
            <div class="signature-item">
                <p><strong>तयार गर्ने (Prepared By):</strong></p>
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
        <a href="index.php" class="btn-back">🔙 सूचीमा फर्कनुहोस् (Back to List)</a>
    </div>
<?php endif; ?>

<style>
.report-filter {
    background: #f5f5f5;
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #ddd;
}

.filter-row {
    display: flex;
    align-items: center;
    flex-wrap: wrap;
    gap: 20px;
    margin-bottom: 15px;
}

.filter-group {
    display: flex;
    align-items: center;
    gap: 10px;
    min-width: 250px;
}

.filter-group label {
    font-weight: bold;
    white-space: nowrap;
    min-width: 120px;
    font-size: 14px;
}

.filter-group input, .filter-group select {
    padding: 10px;
    border: 2px solid #ddd;
    border-radius: 5px;
    min-width: 180px;
    font-size: 14px;
}

.filter-group input:focus, .filter-group select:focus {
    outline: none;
    border-color: #007cba;
    box-shadow: 0 0 5px rgba(0,124,186,0.3);
}

.filter-group button {
    padding: 10px 20px;
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-weight: bold;
    white-space: nowrap;
    min-width: 200px;
    font-size: 14px;
}

.filter-group button:hover {
    background: linear-gradient(135deg, #764ba2 0%, #667eea 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.btn-clear {
    padding: 10px 20px;
    background: linear-gradient(135deg, #74b9ff 0%, #0984e3 100%);
    color: white;
    text-decoration: none;
    border-radius: 5px;
    font-weight: bold;
    white-space: nowrap;
    min-width: 180px;
    font-size: 14px;
    text-align: center;
    display: inline-block;
}

.btn-clear:hover {
    background: linear-gradient(135deg, #0984e3 0%, #74b9ff 100%);
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
    text-decoration: none;
    color: white;
}

.report-summary {
    background: linear-gradient(135deg, #e8f4f8 0%, #f0f8ff 100%);
    padding: 20px;
    border-radius: 8px;
    margin-bottom: 25px;
    border: 1px solid #c3e6f0;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.report-summary p {
    margin: 8px 0;
    font-size: 15px;
}

.report-summary strong {
    color: #2c5282;
}

.no-data {
    text-align: center;
    padding: 60px;
    background: #f9f9f9;
    border-radius: 8px;
    color: #666;
    border: 2px dashed #ddd;
}

.no-data p {
    font-size: 18px;
    margin: 10px 0;
}

table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 20px;
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    border-radius: 8px;
    overflow: hidden;
}

th, td {
    border: 1px solid #ddd;
    padding: 12px 8px;
    text-align: center;
    vertical-align: middle;
    font-size: 13px;
}

th {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    font-weight: bold;
    font-size: 12px;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

tr:nth-child(even) {
    background-color: #f8f9fa;
}

tr:hover:not(.total-row) {
    background-color: #e3f2fd;
    transform: scale(1.005);
    transition: all 0.2s ease;
}

.total-row {
    background: linear-gradient(135deg, #e8f4f8 0%, #f0f8ff 100%) !important;
    font-weight: bold;
    border-top: 3px solid #667eea !important;
}

.total-row td {
    border-top: 3px solid #667eea;
    font-size: 14px;
    color: #2c5282;
}

.book-type {
    font-size: 11px;
    padding: 6px 4px;
    border-radius: 4px;
    font-weight: bold;
}

.book-type.translated {
    background: linear-gradient(135deg, #e8f5e8 0%, #f0fff0 100%);
    color: #2d5a2d;
}

.book-type.non-translated {
    background: linear-gradient(135deg, #f0f8ff 0%, #e6f3ff 100%);
    color: #1e3a8a;
}

.status-badge {
    padding: 6px 10px;
    border-radius: 20px;
    font-size: 11px;
    font-weight: bold;
    text-transform: uppercase;
    letter-spacing: 0.5px;
}

.status-active { 
    background: linear-gradient(135deg, #d4edda 0%, #c3e6cb 100%); 
    color: #155724; 
}

.status-completed { 
    background: linear-gradient(135deg, #cce7ff 0%, #b3d9ff 100%); 
    color: #004085; 
}

.status-pending { 
    background: linear-gradient(135deg, #fff3cd 0%, #ffeaa7 100%); 
    color: #856404; 
}

.signature-section {
    margin-top: 50px;
    page-break-inside: avoid;
    background: #f8f9fa;
    padding: 30px;
    border-radius: 8px;
    border: 1px solid #ddd;
}

.signature-row {
    display: flex;
    justify-content: space-between;
    margin-top: 30px;
    gap: 30px;
}

.signature-item {
    flex: 1;
    text-align: center;
    background: white;
    padding: 20px;
    border-radius: 6px;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.signature-item p {
    margin: 12px 0;
    font-weight: bold;
    font-size: 16px;
    color: #333;
}

.signature-line {
    border-bottom: 2px solid #333;
    height: 50px;
    margin: 25px 15px;
}

.signature-label {
    font-size: 14px;
    color: #666;
    font-weight: normal !important;
    margin-top: 10px;
}

.print-actions {
    display: flex;
    gap: 15px;
    justify-content: center;
    flex-wrap: wrap;
    margin-top: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #ddd;
}

.btn-print, .btn-export, .btn-excel, .btn-back {
    padding: 12px 24px;
    margin: 5px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: bold;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.3s ease;
    box-shadow: 0 2px 4px rgba(0,0,0,0.1);
}

.btn-print {
    background: linear-gradient(135deg, #28a745 0%, #20c997 100%);
    color: white;
}

.btn-export {
    background: linear-gradient(135deg, #17a2b8 0%, #138496 100%);
    color: white;
}

.btn-excel {
    background: linear-gradient(135deg, #007bff 0%, #0056b3 100%);
    color: white;
}

.btn-back {
    background: linear-gradient(135deg, #6c757d 0%, #495057 100%);
    color: white;
}

.btn-print:hover, .btn-export:hover, .btn-excel:hover, .btn-back:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 12px rgba(0,0,0,0.2);
    text-decoration: none;
    color: white;
}

/* Enhanced Print styles */
@media print {
    .report-filter, .print-actions, nav, header, footer {
        display: none !important;
    }
    
    * {
        margin: 0 !important;
        padding: 0 !important;
    }
    
    @page {
        margin: 0;
        size: A4 landscape;
    }
    
    body {
        font-size: 20px !important;
        margin: 0;
        padding: 15px;
        line-height: 1.2;
    }
    
    h2 {
        font-size: 24px !important;
        margin-bottom: 15px !important;
        text-align: center;
        color: #333;
    }
    
    table {
        font-size: 14px !important;
        width: 100%;
        margin-top: 10px !important;
    }
    
    th, td {
        padding: 6px 4px !important;
        font-size: 12px !important;
        border: 1px solid #000 !important;
    }
    
    th {
        font-size: 11px !important;
        font-weight: bold;
        background: #f0f0f0 !important;
        -webkit-print-color-adjust: exact;
    }
    
    .report-summary {
        background: none;
        border: 2px solid #000;
        padding: 15px !important;
        margin: 10px 0 !important;
        font-size: 16px !important;
    }
    
    .report-summary p {
        margin: 6px 0 !important;
        font-size: 16px !important;
    }
    
    .signature-section {
        margin-top: 30px !important;
        page-break-inside: avoid;
        background: none !important;
        border: 1px solid #000 !important;
    }
    
    .signature-row {
        display: flex;
        justify-content: space-between;
        margin-top: 20px !important;
    }
    
    .signature-item {
        flex: 1;
        margin: 0 10px !important;
        background: none !important;
        box-shadow: none !important;
    }
    
    .signature-item p {
        font-size: 14px !important;
        margin: 8px 0 !important;
    }
    
    .signature-line {
        height: 30px !important;
        margin: 15px 5px !important;
        border-bottom: 1px solid #000 !important;
    }
    
    .signature-label {
        font-size: 12px !important;
    }
    
    .book-type {
        font-size: 10px !important;
        -webkit-print-color-adjust: exact;
    }
    
    .status-badge {
        font-size: 10px !important;
        -webkit-print-color-adjust: exact;
    }
    
    .total-row td {
        font-size: 14px !important;
        font-weight: bold;
        border-top: 2px solid #000 !important;
    }
}

@media (max-width: 768px) {
    .filter-row {
        flex-direction: column;
        align-items: stretch;
    }
    
    .filter-group {
        flex-direction: column;
        align-items: stretch;
        min-width: 100%;
    }
    
    .filter-group label {
        min-width: auto;
        margin-bottom: 5px;
    }
    
    .filter-group input, .filter-group select {
        min-width: 100%;
    }
    
    .signature-row {
        flex-direction: column;
        gap: 20px;
    }
    
    .print-actions {
        flex-direction: column;
        align-items: center;
    }
    
    table {
        font-size: 11px;
    }
    
    th, td {
        padding: 6px 4px;
        font-size: 10px;
    }
}
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>्ताक्षर (Signature)</p>
                        <p class="signature-label">(उत्पादन विभाग)</p>
                    </div>
                    <div class="signature-item">
                        <p><strong>प्राप्त गर्ने:</strong></p>
                        <div class="signature-line"></div>
                        <p class="signature-label">हस्
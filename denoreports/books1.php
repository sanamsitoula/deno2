<?php 

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

// For actions that modify data, add role checks:s
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';
    
    // Only allow editors and admins to modify data
    if (!has_role('editor') && !has_role('admin')) {
        echo "<div class='alert alert-danger'>You don't have permission to perform this action.</div>";
        exit();
    }
    
    // Rest of your POST handling code...
}?><?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Default to today's date if not specified - use Nepali format
$date = $_GET['date'] ?? '2082.01.15'; // Sample Nepali date

// Get daily report
$stmt = $conn->prepare("
    SELECT d.*, b.book_name 
    FROM Deno d
    JOIN Books b ON d.book_code = b.book_code
    WHERE d.deno_date_nep = :date OR d.deno_date_eng = :date
    ORDER BY b.book_name
");
$stmt->execute([':date' => $date]);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_qty = array_sum(array_column($records, 'total_qty'));
$total_openpcs = array_sum(array_column($records, 'quantity_openpcs'));
$unique_dates = array_unique(array_column($records, 'deno_date_nep'));
?>

<h2>Daily Production Report</h2>

<form method="get" class="report-filter">
    <label for="date">Date (YYYY.MM.DD):</label>
    <input type="text" name="date" id="date" value="<?= htmlspecialchars($date) ?>" pattern="\d{4}\.\d{2}\.\d{2}" placeholder="2082.01.15">
    <button type="submit">Generate Report</button>
</form>

<div class="report-summary">
    <p><strong>Date:</strong> <?= htmlspecialchars($date) ?></p>
    <p><strong>Records Found:</strong> <?= count($records) ?></p>
    <p><strong>Total Produced:</strong> <?= number_format($total_qty) ?></p>
    <p><strong>Total Defective:</strong> <?= number_format($total_openpcs) ?></p>
    <p><strong>Net Production:</strong> <?= number_format($total_qty + $total_openpcs) ?></p>
</div>

<?php if (empty($records)): ?>
    <div class="no-data">
        <p>No production data found for <?= htmlspecialchars($date) ?>.</p>
        <p>Try using a different date format or check if data exists for this date.</p>
    </div>
<?php else: ?>
    <table id="reportTable">
        <thead>
            <tr>
                <th>SN</th>
                <th>Book Name</th>
                <th>Book Code</th>
                <th>Ref No</th>
                <th>Per Poka Qty</th>
                <th>Poka Qty</th>
                <th>Total Qty</th>
                <th>Defective</th>
                <th>Net Qty</th>
                <th>Created By</th>
                <th>Date (Nepali)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sn = 1; 
            foreach ($records as $record): ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td><?= htmlspecialchars($record['book_name']) ?></td>
                <td><?= htmlspecialchars($record['book_code']) ?></td>
                <td><?= htmlspecialchars($record['ref_no']) ?></td>
                <td><?= number_format($record['per_poka_qty']) ?></td>
                <td><?= number_format($record['poka_qty']) ?></td>
                <td><?= number_format($record['total_qty']) ?></td>
                <td><?= number_format($record['quantity_openpcs']) ?></td>
                <td><?= number_format($record['total_qty'] - $record['quantity_openpcs']) ?></td>
                <td><?= ucfirst($record['created_by']) ?></td>
                <td><?= htmlspecialchars($record['deno_date_nep']) ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="print-actions" style="margin-top: 20px;">
        <button onclick="printReport()" class="btn-print">🖨️ Print Report</button>
        <button onclick="exportToCSV()" class="btn-export">📊 Export to CSV</button>
    </div>
<?php endif; ?>

<style>
.report-filter {
    background: #f5f5f5;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.report-filter label {
    margin-right: 10px;
    font-weight: bold;
}

.report-filter input {
    padding: 8px;
    margin-right: 10px;
    border: 1px solid #ddd;
    border-radius: 4px;
}

.report-filter button {
    padding: 8px 15px;
    background: #007cba;
    color: white;
    border: none;
    border-radius: 4px;
    cursor: pointer;
}

.report-summary {
    background: #e8f4f8;
    padding: 15px;
    border-radius: 5px;
    margin-bottom: 20px;
}

.report-summary p {
    margin: 5px 0;
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
}

th, td {
    border: 1px solid #ddd;
    padding: 8px;
    text-align: center;
}

th {
    background-color: #f2f2f2;
    font-weight: bold;
}

tr:nth-child(even) {
    background-color: #f9f9f9;
}

.btn-print, .btn-export {
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

.btn-print:hover, .btn-export:hover {
    opacity: 0.8;
}

/* Print styles */
@media print {
    .report-filter, .print-actions, nav, header, footer {
        display: none !important;
    }
    
    body {
        font-size: 12px;
    }
    
    table {
        font-size: 10px;
    }
    
    .report-summary {
        background: none;
        border: 1px solid #000;
    }
}
</style>

<script>
function printReport() {
    // Create a new window for printing
    var printWindow = window.open('', '_blank', 'width=800,height=600');
    
    // Get the report content
    var reportContent = `
        <!DOCTYPE html>
        <html>
        <head>
            <title>Daily Production Report - <?= htmlspecialchars($date) ?></title>
            <style>
                body {
                    font-family: Arial, sans-serif;
                    margin: 20px;
                    font-size: 12px;
                }
                .header {
                    text-align: center;
                    margin-bottom: 20px;
                    border-bottom: 2px solid #000;
                    padding-bottom: 10px;
                }
                .company-name {
                    font-size: 18px;
                    font-weight: bold;
                    margin-bottom: 5px;
                }
                .report-title {
                    font-size: 16px;
                    font-weight: bold;
                }
                .report-summary {
                    margin: 20px 0;
                    border: 1px solid #000;
                    padding: 10px;
                }
                .summary-row {
                    display: flex;
                    justify-content: space-between;
                    margin: 5px 0;
                }
                table {
                    width: 100%;
                    border-collapse: collapse;
                    margin-top: 20px;
                }
                th, td {
                    border: 1px solid #000;
                    padding: 6px;
                    text-align: center;
                    font-size: 10px;
                }
                th {
                    background-color: #f0f0f0;
                    font-weight: bold;
                }
                .footer {
                    margin-top: 30px;
                    border-top: 1px solid #000;
                    padding-top: 10px;
                    font-size: 10px;
                }
            </style>
        </head>
        <body>
            <div class="header">
                <div class="company-name">Book Production Management System</div>
                <div class="report-title">Daily Production Report</div>
            </div>
            
            <div class="report-summary">
                <div class="summary-row">
                    <span><strong>Date:</strong> <?= htmlspecialchars($date) ?></span>
                    <span><strong>Total Records:</strong> <?= count($records) ?></span>
                </div>
                <div class="summary-row">
                    <span><strong>Total Produced:</strong> <?= number_format($total_qty) ?></span>
                    <span><strong>Total Defective:</strong> <?= number_format($total_openpcs) ?></span>
                </div>
                <div class="summary-row">
                    <span><strong>Net Production:</strong> <?= number_format($total_qty + $total_openpcs) ?></span>
                    <span><strong>Generated:</strong> <?= date('Y-m-d H:i:s') ?></span>
                </div>
            </div>
            
            <table>
                <thead>
                    <tr>
                        <th>SN</th>
                        <th>Book Name</th>
                        <th>Book Code</th>
                        <th>Ref No</th>
                        <th>Per Poka</th>
                        <th>Poka Qty</th>
                        <th>Total</th>
                        <th>Openpcs</th>
                        <th>Net</th>
                        <th>Created By</th>
                    </tr>
                </thead>
                <tbody>
                    <?php 
                    $sn = 1; 
                    foreach ($records as $record): ?>
                    <tr>
                        <td><?= $sn++ ?></td>
                        <td><?= htmlspecialchars($record['book_name']) ?></td>
                        <td><?= htmlspecialchars($record['book_code']) ?></td>
                        <td><?= htmlspecialchars($record['ref_no']) ?></td>
                        <td><?= number_format($record['per_poka_qty']) ?></td>
                        <td><?= number_format($record['poka_qty']) ?></td>
                        <td><?= number_format($record['total_qty']) ?></td>
                        <td><?= number_format($record['quantity_openpcs']) ?></td>
                        <td><?= number_format($record['total_qty'] + $record['quantity_openpcs']) ?></td>
                        <td><?= ucfirst($record['created_by']) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
            
            <div class="footer">
                <p>Report generated on <?= date('Y-m-d H:i:s') ?></p>
            </div>
        </body>
        </html>
    `;
    
    // Write content to the new window
    printWindow.document.open();
    printWindow.document.write(reportContent);
    printWindow.document.close();
    
    // Wait for content to load, then print
    printWindow.onload = function() {
        printWindow.print();
        // Optionally close the window after printing
        // printWindow.close();
    };
}

function exportToCSV() {
    // Prepare CSV data
    var csvContent = "SN,Book Name,Book Code,Ref No,Per Poka Qty,Poka Qty,Total Qty,Openpcs,Net Qty,Created By,Date\\n";
    
    var rows = document.querySelectorAll("#reportTable tbody tr");
    rows.forEach(function(row, index) {
        var cells = row.querySelectorAll("td");
        var rowData = [];
        cells.forEach(function(cell) {
            rowData.push('"' + cell.textContent.trim().replace(/"/g, '""') + '"');
        });
        csvContent += rowData.join(",") + "\\n";
    });
    
    // Create and download CSV file
    var blob = new Blob([csvContent], { type: 'text/csv;charset=utf-8;' });
    var link = document.createElement("a");
    var url = URL.createObjectURL(blob);
    link.setAttribute("href", url);
    link.setAttribute("download", "daily_report_<?= $date ?>.csv");
    link.style.visibility = 'hidden';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

// Get record ID
$id = $_GET['id'] ?? null;
if (!$id) {
    die("No record ID provided");
}

// Fetch record with related data
$stmt = $conn->prepare("
    SELECT fp.*,
        jt.job_ticket_code,
        jt.lot,
        b.book_code,
        b.book_name,
        b.class_level,
        f.name as forma_name,
        jtd.print_qty as jtd_targetqty,
        jtd.page as jtd_page,
        m.machine_name,
        fy.fiscal_code,
        supervisor.username as supervisor_name,
        operator.username as operator_name,
        incharge.username as incharge_name,
        s.name as shift_name
    FROM forma_printing fp
    LEFT JOIN job_ticket jt ON fp.jt_id = jt.id
    LEFT JOIN job_ticket_details jtd ON fp.jtd_id = jtd.id
    LEFT JOIN forma f ON jtd.forma_id = f.id
    LEFT JOIN books b ON jt.book_id = b.book_id
    LEFT JOIN machines m ON fp.machine_id = m.id
    LEFT JOIN fiscal_years fy ON fp.fiscal_year_id = fy.id
    LEFT JOIN users supervisor ON fp.supervisor_id = supervisor.id
    LEFT JOIN users operator ON fp.operator_id = operator.id
    LEFT JOIN users incharge ON fp.incharge_id = incharge.id
    LEFT JOIN shifts s ON fp.shift_id = s.id
    WHERE fp.id = :id
");
$stmt->execute([':id' => $id]);
$record = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$record) {
    die("Record not found");
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Print Forma Printing Record</title>
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            color: #333;
            background: white;
            padding: 20px;
        }
        
        .print-container {
            max-width: 100%;
        }
        
        .print-header {
            text-align: center;
            margin-bottom: 30px;
            border-bottom: 2px solid #000;
            padding-bottom: 15px;
        }
        
        .company-name {
            font-size: 28px;
            font-weight: bold;
            color: #2c3e50;
            margin-bottom: 5px;
        }
        
        .company-address {
            font-size: 14px;
            color: #7f8c8d;
            margin-bottom: 15px;
        }
        
        .report-title {
            font-size: 22px;
            font-weight: 600;
            color: #2c3e50;
            text-transform: uppercase;
        }
        
        .section {
            margin-bottom: 25px;
            page-break-inside: avoid;
        }
        
        .section-title {
            font-size: 16px;
            font-weight: bold;
            background-color: #f8f9fa;
            padding: 8px 12px;
            border-left: 4px solid #007bff;
            margin-bottom: 15px;
        }
        
        .info-table {
            width: 100%;
            border-collapse: collapse;
            margin-bottom: 15px;
        }
        
        .info-table th {
            text-align: left;
            padding: 8px 10px;
            background-color: #f1f5f9;
            border-bottom: 1px solid #dee2e6;
            font-size: 13px;
            font-weight: 600;
            width: 30%;
        }
        
        .info-table td {
            padding: 8px 10px;
            border-bottom: 1px solid #dee2e6;
            font-size: 14px;
        }
        
        .signature-section {
            margin-top: 50px;
            display: flex;
            justify-content: space-around;
        }
        
        .signature-box {
            text-align: center;
            width: 200px;
            border-top: 1px solid #333;
            padding-top: 10px;
            margin-top: 60px;
        }
        
        .print-footer {
            text-align: center;
            margin-top: 30px;
            font-size: 12px;
            color: #7f8c8d;
            border-top: 1px solid #dee2e6;
            padding-top: 10px;
        }
        
        @media print {
            body {
                padding: 0;
            }
            .no-print {
                display: none;
            }
        }
    </style>
</head>
<body>
    <div class="print-container">
        <div class="print-header">
            <div class="company-name">Janak Education Material Center</div>
            <div class="company-address">Sanothimi,Bhaktapur,Nepal</div>
            <div class="report-title">Forma Printing Record</div>
        </div>
        
        <div class="section">
            <div class="section-title">Basic Information</div>
            <table class="info-table">
                <tr>
                    <th>Record ID</th>
                    <td>FP-<?= str_pad($record['id'], 5, '0', STR_PAD_LEFT) ?></td>
                </tr>
                <tr>
                    <th>Record Name</th>
                    <td><?= htmlspecialchars($record['name']) ?></td>
                </tr>
                <tr>
                    <th>Nepali Date</th>
                    <td><?= htmlspecialchars($record['date_nep']) ?></td>
                </tr>
                <tr>
                    <th>English Date</th>
                    <td><?= htmlspecialchars($record['date_eng']) ?></td>
                </tr>
                <tr>
                    <th>Fiscal Year</th>
                    <td><?= htmlspecialchars($record['fiscal_code']) ?></td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <div class="section-title">Job & Forma Information</div>
            <table class="info-table">
                <tr>
                    <th>Job Ticket Code</th>
                    <td><?= htmlspecialchars($record['job_ticket_code']) ?></td>
                </tr>
                <tr>
                    <th>Book Code</th>
                    <td><?= htmlspecialchars($record['book_code']) ?></td>
                </tr>
                <tr>
                    <th>Book Name</th>
                    <td><?= htmlspecialchars($record['book_name']) ?></td>
                </tr>
                <tr>
                    <th>Class Level</th>
                    <td><?= htmlspecialchars($record['class_level']) ?></td>
                </tr>
                <tr>
                    <th>Forma Name</th>
                    <td><?= htmlspecialchars($record['forma_name']) ?></td>
                </tr>
                <tr>
                    <th>Page</th>
                    <td><?= htmlspecialchars($record['jtd_page']) ?></td>
                </tr>
                <tr>
                    <th>Lot Number</th>
                    <td><?= htmlspecialchars($record['lot']) ?></td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <div class="section-title">Quantity Information</div>
            <table class="info-table">
                <tr>
                    <th>Target Quantity</th>
                    <td><?= number_format($record['jtd_targetqty']) ?></td>
                </tr>
                <tr>
                    <th>Printed Quantity</th>
                    <td><?= number_format($record['fp_printqty']) ?></td>
                </tr>
                <tr>
                    <th>Remaining Quantity</th>
                    <td><?= number_format($record['fp_remainqty']) ?></td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <div class="section-title">Personnel & Equipment</div>
            <table class="info-table">
                <tr>
                    <th>Supervisor</th>
                    <td><?= htmlspecialchars($record['supervisor_name']) ?></td>
                </tr>
                <tr>
                    <th>Operator</th>
                    <td><?= htmlspecialchars($record['operator_name']) ?></td>
                </tr>
                <tr>
                    <th>Incharge</th>
                    <td><?= htmlspecialchars($record['incharge_name']) ?></td>
                </tr>
                <tr>
                    <th>Shift</th>
                    <td><?= htmlspecialchars($record['shift_name']) ?></td>
                </tr>
                <tr>
                    <th>Machine</th>
                    <td><?= htmlspecialchars($record['machine_name']) ?></td>
                </tr>
            </table>
        </div>
        
        <div class="section">
            <div class="section-title">Additional Information</div>
            <table class="info-table">
                <tr>
                    <th>Remarks</th>
                    <td><?= htmlspecialchars($record['remarks']) ?></td>
                </tr>
                <tr>
                    <th>Description</th>
                    <td><?= htmlspecialchars($record['description']) ?></td>
                </tr>
            </table>
        </div>
        
        <div class="signature-section">
            <div class="signature-box">
                Prepared By<br>
                ________________________<br>
                <small>Name & Signature</small>
            </div>
            <div class="signature-box">
                Verified By<br>
                ________________________<br>
                <small>Name & Signature</small>
            </div>
            <div class="signature-box">
                Approved By<br>
                ________________________<br>
                <small>Name & Signature</small>
            </div>
        </div>
        
        <div class="print-footer">
            Printed on: <?= date('Y-m-d H:i:s') ?> | Janak Education Material Center
        </div>
    </div>
    
    <div class="no-print" style="text-align: center; margin-top: 20px;">
        <button onclick="window.print()" class="btn btn-primary">
            <i class="fas fa-print"></i> Print
        </button>
        <button onclick="window.close()" class="btn btn-secondary">
            <i class="fas fa-times"></i> Close
        </button>
    </div>
    
    <script>
        // Auto-print when page loads
        window.onload = function() {
            window.print();
        }
    </script>
</body>
</html>
<?php

function exportToExcel($employees) {
    // Generate filename with timestamp
    $filename = 'employees_' . date('Y-m-d_H-i-s') . '.csv';

    // Critical: Send headers BEFORE any output
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Pragma: no-cache');
    header('Expires: 0');
    header('Cache-Control: must-revalidate, post-check=0, pre-check=0');

    // === UTF-8 BOM (required for Excel to show Unicode like Nepali correctly) ===
    echo "\xEF\xBB\xBF";

    // Turn off output buffering
    if (ob_get_level()) ob_end_clean();

    // === HEADER ROW (Column Titles) ===
    $headers = [
        'S.N.', 'Code', 'Attendance ID', 'Name', 'Citizenship No', 'National ID',
        'Mobile', 'Email', 'Address', 'DOB', 'Gender',
        'Join Date', 'Retirement', 'Initial Appointment', 'Designation', 'Level',
        'Department', 'Type', 'Status', 'Created By', 'Created', 'Updated By', 'Updated'
    ];
    echo implode("\t", $headers) . "\n";

    // === DATA ROWS ===
    $sn = 1;
    foreach ($employees as $emp) {
        $row = [
            $sn++,
            $emp['code'] ?? '',
            $emp['attendance_id'] ?? '',
            $emp['name'] ?? '',
            $emp['citizenship_no'] ?? '',
            $emp['national_id_card_no'] ?? '',
            $emp['mobile_number'] ?? '',
            $emp['email'] ?? '',
            // Clean address: remove line breaks, extra spaces
            trim(preg_replace('/\s+/', ' ', str_replace(["\n", "\r"], ", ", strip_tags($emp['full_address'])))),
            $emp['dob'] ?? '',
            $emp['gender'] ?? '',
            $emp['join_date'] ?? '',
            $emp['retirement_date'] ?? '',
            $emp['initial_appointment_date'] ?? '',
            $emp['designation_name'] ?? '',
            $emp['level_name'] ?? '',
            $emp['department_name'] ?? '',
            $emp['emp_type'] ?? '',
            $emp['emp_status'] ?? '',
            $emp['created_by_name'] ?? '',
            $emp['created_date'] ?? '',
            $emp['updated_by_name'] ?? '',
            $emp['updated_date'] ?? ''
        ];

        // Escape fields containing tab or quotes
        $escapedRow = array_map(function ($value) {
            $value = (string)$value;
            $value = trim($value);
            // If value contains tab, newline, or quote → wrap in quotes
            if (strpos($value, '"') !== false || strpos($value, "\t") !== false || strpos($value, "\n") !== false) {
                $value = '"' . str_replace('"', '""', $value) . '"';
            }
            return $value;
        }, $row);

        echo implode("\t", $escapedRow) . "\n";
    }

    // End output
    exit();
}
function exportToPDF($employees) {
    $filename = 'employee_report_' . date('Y-m-d_H-i-s') . '.pdf.html';
    header('Content-Type: text/html; charset=UTF-8');
    header('Content-Disposition: inline; filename="' . $filename . '"');

    $total = count($employees);
    $generatedAt = date('F j, Y, g:i A');

    echo <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Employee Report</title>
    <style>
        @page { margin: 0.4in; size: A4 portrait; }
        body { font-family: 'Segoe UI', Arial, sans-serif; color: #000; margin: 0; padding: 0; line-height: 1.3; font-size: 10.5px; }
        .container { width: 100%; margin: 0; padding: 0; box-sizing: border-box; }
        .header { text-align: center; margin-bottom: 10px; border-bottom: 1.5px solid #003366; padding-bottom: 8px; }
        .header h1 { margin: 0; color: #003366; font-size: 16px; font-weight: bold; }
        .header p { margin: 3px 0; color: #333; font-size: 10px; }
        .meta-info { font-size: 11px; color: #333; text-align: center; margin-bottom: 15px; font-weight: bold; }
        table { width: 100%; border-collapse: collapse; table-layout: fixed; margin: 0; font-size: 10px; }
        th, td { padding: 4px 5px; text-align: left; overflow: hidden; border: 1px solid #aaa; }
        th { background-color: #003366; color: white; font-weight: bold; text-align: center; font-size: 10.5px; white-space: normal; }
        tbody tr:nth-child(even) { background-color: #f9f9f9; }
        .badge { display: inline-block; padding: 0.15em 0.3em; font-size: 8.5px; font-weight: bold; border-radius: 3px; color: white; text-transform: uppercase; }
        .status-active { background-color: #198754; }
        .status-inactive { background-color: #dc3545; }
        .status-resigned { background-color: #fd7e14; }
        .status-on-leave { background-color: #0dcaf0; }
        .address-cell { white-space: normal; word-wrap: break-word; max-width: 130px; line-height: 1.3; }
        .footer { text-align: center; font-size: 9px; color: #555; margin-top: 20px; padding-top: 5px; border-top: 1px dashed #ccc; }
        .no-print { text-align: center; padding: 8px; background: #f0f0f0; border-bottom: 1px solid #ddd; }
        .btn { padding: 8px 14px; margin: 0 8px; font-size: 12px; cursor: pointer; border: none; border-radius: 4px; color: white; font-weight: bold; }
        .btn-print { background-color: #007bff; }
        .btn-close { background-color: #6c757d; }
        @media print {
            .no-print { display: none !important; }
            body { font-size: 10px; }
            table { font-size: 9.5px; }
            th, td { padding: 3.5px 4px; font-size: 9px; }
            .address-cell { white-space: normal; word-break: break-word; }
            .footer { font-size: 8px; }
            tr { page-break-inside: avoid; }
            thead { display: table-header-group; }
        }
    </style>
    <script>
        function printReport() {
            window.onload = function () {
                setTimeout(function () {
                    window.print();
                }, 300);
            };
        }
    </script>
</head>
<body onload="printReport()">
    <div class="container">
        <div class="no-print">
            <button onclick="printReport()" class="btn">🖨️ Print Report</button>
            <button onclick="window.close()" class="btn">❌ Close</button>
        </div>

        <div class="header">
            <h1>EMPLOYEE REPORT</h1>
            <p><strong>Generated on:</strong> {$generatedAt}</p>
        </div>

        <div class="meta-info">
            Total Employees: {$total}
        </div>

        <table>
            <thead>
                <tr>
                    <th>S.N.</th>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Designation</th>
                    <th>Dept</th>
                    <th>Lvl</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>DOB</th>
                    <th>Gender</th>
                    <th>Address</th>
                    <th>Join</th>
                    <th>Retire</th>
                    <th>Init App</th>
                </tr>
            </thead>
            <tbody>
HTML;

    $sn = 1;
    foreach ($employees as $emp) {
        $statusClass = ['ACTIVE' => 'status-active', 'INACTIVE' => 'status-inactive', 'RESIGNED' => 'status-resigned'][$emp['emp_status']] ?? 'status-on-leave';

        echo "<tr>
            <td class='text-center'>" . $sn++ . "</td>
            <td>" . htmlspecialchars($emp['code']) . "</td>
            <td><strong>" . htmlspecialchars($emp['name']) . "</strong></td>
            <td>" . htmlspecialchars($emp['designation_name'] ?? '-') . "</td>
            <td>" . htmlspecialchars(strlen($emp['department_name'] ?? '') > 12 ? substr($emp['department_name'], 0, 12) . '..' : ($emp['department_name'] ?? '-')) . "</td>
            <td>" . htmlspecialchars($emp['level_name'] ?? '-') . "</td>
            <td>" . htmlspecialchars($emp['emp_type']) . "</td>
            <td><span class='badge {$statusClass}'>" . htmlspecialchars($emp['emp_status']) . "</span></td>
            <td>" . htmlspecialchars($emp['mobile_number']) . "</td>
            <td>" . htmlspecialchars(strlen($emp['email'] ?? '') > 15 ? substr($emp['email'], 0, 15) . '..' : ($emp['email'] ?? '-')) . "</td>
            <td>" . htmlspecialchars($emp['dob']) . "</td>
            <td>" . htmlspecialchars($emp['gender']) . "</td>
            <td class='address-cell'>" . htmlspecialchars($emp['full_address']) . "</td>
            <td>" . htmlspecialchars($emp['join_date']) . "</td>
            <td>" . htmlspecialchars($emp['retirement_date'] ?? '-') . "</td>
            <td>" . htmlspecialchars($emp['initial_appointment_date'] ?? '-') . "</td>
        </tr>";
    }

    echo <<<HTML
            </tbody>
        </table>

        <div class="footer">
            Report generated via Employee Management System • {$generatedAt}
        </div>
    </div>
</body>
</html>
HTML;

    exit();
}

// === Main Export Handler ===
function handleExport($conn) {
    $format = $_GET['format'] ?? 'excel';
    $sql = "
        SELECT 
            e.id,
            e.code,
            e.attendance_id,
            e.name,
            e.citizenship_no,
            e.national_id_card_no,
            e.mobile_number,
            e.email,
            e.full_address,
            e.join_date,
            e.retirement_date,
            e.initial_appointment_date,
            e.dob,
            e.gender,
            e.emp_status,
            e.emp_type,
            d.name as designation_name,
            l.name as level_name,
            CONCAT(COALESCE(dep.sub_department_name, ''), '/', dep.name) as department_name,
            e.created_date,
            e.updated_date,
            creator.name as created_by_name,
            updater.name as updated_by_name
        FROM employee e
        LEFT JOIN designation d ON e.designation_id = d.id
        LEFT JOIN level l ON e.level_id = l.id
        LEFT JOIN department dep ON e.department_id = dep.id
        LEFT JOIN employee creator ON e.created_by = creator.id
        LEFT JOIN employee updater ON e.updated_by = updater.id
        ORDER BY e.code
    ";
    $stmt = $conn->prepare($sql);
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($format === 'excel') {
        exportToExcel($employees);   // ✅ Now defined above
    } elseif ($format === 'pdf') {
        exportToPDF($employees);
    }
}
?>
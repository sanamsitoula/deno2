<?php
/**
 * Employee Export Handler
 * Handles Excel, PDF, and Print exports
 */

function handleEmployeeExport($conn, $params) {
    $format = $params['format'] ?? 'excel';
    
    // Build the same query as index but without pagination
    $sql = "
        SELECT 
            e.code, e.name, e.name_eng, e.name_nep,
            e.mobile_number, e.email, e.emp_status, e.emp_type,
            e.is_technical, e.state, e.local_body, e.ward_no,
            e.pan_no, e.bank_name, e.bank_branch, e.bank_account_number,
            e.card_id, e.join_date, e.join_date_nep,
            e.dob, e.dob_nep, e.gender, e.full_address,
            d.name AS designation_name,
            l.name AS level_name,
            dep.name AS department_name,
            dep.sub_department_name,
            fy.fiscal_code
        FROM employee e
        LEFT JOIN designation d ON e.designation_id = d.id
        LEFT JOIN level l ON e.level_id = l.id
        LEFT JOIN department dep ON e.department_id = dep.id
        LEFT JOIN fiscal_years fy ON e.fiscal_year_id = fy.id
        WHERE e.deleted_date IS NULL
    ";
    
    $bind_params = [];
    
    // Apply same filters
    $search = trim($params['search'] ?? '');
    if ($search) {
        $sql .= " AND (
            e.code ILIKE :search OR 
            e.name ILIKE :search OR 
            e.name_eng ILIKE :search OR 
            e.name_nep ILIKE :search OR 
            e.email ILIKE :search OR 
            e.mobile_number ILIKE :search
        )";
        $bind_params[':search'] = "%$search%";
    }
    
    if (!empty($params['department_id']) && is_numeric($params['department_id'])) {
        $sql .= " AND e.department_id = :department_id";
        $bind_params[':department_id'] = (int)$params['department_id'];
    }
    
    if (!empty($params['designation_id']) && is_numeric($params['designation_id'])) {
        $sql .= " AND e.designation_id = :designation_id";
        $bind_params[':designation_id'] = (int)$params['designation_id'];
    }
    
    if (!empty($params['level_id']) && is_numeric($params['level_id'])) {
        $sql .= " AND e.level_id = :level_id";
        $bind_params[':level_id'] = (int)$params['level_id'];
    }
    
    if (!empty($params['emp_status'])) {
        $sql .= " AND e.emp_status = :emp_status";
        $bind_params[':emp_status'] = $params['emp_status'];
    }
    
    if (!empty($params['emp_type'])) {
        $sql .= " AND e.emp_type = :emp_type";
        $bind_params[':emp_type'] = $params['emp_type'];
    }
    
    if (isset($params['is_technical']) && $params['is_technical'] !== '') {
        $sql .= " AND e.is_technical = :is_technical";
        $bind_params[':is_technical'] = $params['is_technical'] === '1' ? true : false;
    }
    
    if (!empty($params['state'])) {
        $sql .= " AND e.state ILIKE :state";
        $bind_params[':state'] = "%{$params['state']}%";
    }
    
    if (!empty($params['fiscal_year_id']) && is_numeric($params['fiscal_year_id'])) {
        $sql .= " AND e.fiscal_year_id = :fiscal_year_id";
        $bind_params[':fiscal_year_id'] = (int)$params['fiscal_year_id'];
    }
    
    $sql .= " ORDER BY e.code, e.name";
    
    $stmt = $conn->prepare($sql);
    foreach ($bind_params as $key => $val) {
        $stmt->bindValue($key, $val);
    }
    $stmt->execute();
    $employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Route to appropriate export function
    switch ($format) {
        case 'excel':
            exportToExcel($employees);
            break;
        case 'pdf':
            exportToPDF($employees);
            break;
        case 'print':
            exportToPrint($employees);
            break;
        default:
            die('Invalid export format');
    }
}

function exportToExcel($employees) {
    $filename = 'employees_' . date('Y-m-d_His') . '.csv';
    
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    
    $output = fopen('php://output', 'w');
    
    // Add BOM for Excel UTF-8 compatibility
    fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
    
    // Headers
    fputcsv($output, [
        'Code', 'Name (English)', 'Name (Nepali)', 'Designation', 'Department',
        'Level', 'Type', 'Status', 'Technical', 'Mobile', 'Email',
        'PAN No', 'Bank Name', 'Bank Branch', 'Account No',
        'State', 'Local Body', 'Ward No', 'Card ID',
        'Join Date', 'Join Date (Nepali)', 'DOB', 'DOB (Nepali)',
        'Gender', 'Address', 'Fiscal Year'
    ]);
    
    // Data rows
    foreach ($employees as $emp) {
        fputcsv($output, [
            $emp['code'],
            $emp['name_eng'] ?? $emp['name'],
            $emp['name_nep'] ?? '',
            $emp['designation_name'] ?? '',
            ($emp['sub_department_name'] ? $emp['sub_department_name'] . ' / ' : '') . ($emp['department_name'] ?? ''),
            $emp['level_name'] ?? '',
            $emp['emp_type'],
            $emp['emp_status'],
            $emp['is_technical'] ? 'Yes' : 'No',
            $emp['mobile_number'] ?? '',
            $emp['email'] ?? '',
            $emp['pan_no'] ?? '',
            $emp['bank_name'] ?? '',
            $emp['bank_branch'] ?? '',
            $emp['bank_account_number'] ?? '',
            $emp['state'] ?? '',
            $emp['local_body'] ?? '',
            $emp['ward_no'] ?? '',
            $emp['card_id'] ?? '',
            $emp['join_date'] ?? '',
            $emp['join_date_nep'] ?? '',
            $emp['dob'] ?? '',
            $emp['dob_nep'] ?? '',
            $emp['gender'] ?? '',
            $emp['full_address'] ?? '',
            $emp['fiscal_code'] ?? ''
        ]);
    }
    
    fclose($output);
    exit;
}

function exportToPDF($employees) {
    // Simple HTML to PDF conversion
    $html = '
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Employee Report</title>
        <style>
            body { font-family: Arial, sans-serif; font-size: 9pt; }
            h2 { text-align: center; color: #333; margin-bottom: 5px; }
            .meta { text-align: center; color: #666; font-size: 8pt; margin-bottom: 20px; }
            table { width: 100%; border-collapse: collapse; font-size: 8pt; }
            th { background-color: #0d6efd; color: white; padding: 6px 4px; text-align: left; font-weight: bold; }
            td { padding: 5px 4px; border-bottom: 1px solid #ddd; }
            tr:nth-child(even) { background-color: #f8f9fa; }
            .footer { margin-top: 20px; text-align: center; font-size: 8pt; color: #666; }
            .status { padding: 2px 6px; border-radius: 3px; font-size: 7pt; font-weight: bold; }
            .status-ACTIVE { background: #28a745; color: white; }
            .status-INACTIVE { background: #6c757d; color: white; }
            .status-RETIRED { background: #17a2b8; color: white; }
            .status-DRAFT { background: #ffc107; color: black; }
        </style>
    </head>
    <body>
        <h2>Employee Directory Report</h2>
        <div class="meta">Generated on: ' . date('F d, Y h:i A') . ' | Total Records: ' . count($employees) . '</div>
        
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Designation</th>
                    <th>Department</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Contact</th>
                    <th>Location</th>
                </tr>
            </thead>
            <tbody>';
    
    foreach ($employees as $emp) {
        $html .= '<tr>
            <td>' . htmlspecialchars($emp['code']) . '</td>
            <td>' . htmlspecialchars($emp['name_eng'] ?? $emp['name']) . '</td>
            <td>' . htmlspecialchars($emp['designation_name'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($emp['department_name'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($emp['emp_type']) . '</td>
            <td><span class="status status-' . $emp['emp_status'] . '">' . htmlspecialchars($emp['emp_status']) . '</span></td>
            <td>' . htmlspecialchars($emp['mobile_number'] ?? 'N/A') . '</td>
            <td>' . htmlspecialchars($emp['state'] ?? 'N/A') . '</td>
        </tr>';
    }
    
    $html .= '</tbody>
        </table>
        
        <div class="footer">
            <p>© ' . date('Y') . ' HR Management System. All rights reserved.</p>
        </div>
    </body>
    </html>';
    
    // Output as PDF-ready HTML
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="employees_' . date('Y-m-d') . '.pdf"');
    
    echo $html;
    exit;
}

function exportToPrint($employees) {
    // Minimal design for efficient printing
    ?>
    <!DOCTYPE html>
    <html>
    <head>
        <meta charset="UTF-8">
        <title>Employee Report - Print</title>
        <style>
            @media print {
                @page { margin: 0.5cm; }
                body { margin: 0; }
            }
            body { 
                font-family: Arial, sans-serif; 
                font-size: 9pt;
                margin: 10px;
            }
            h2 { 
                text-align: center; 
                margin: 5px 0; 
                font-size: 14pt;
            }
            .meta { 
                text-align: center; 
                font-size: 8pt; 
                margin-bottom: 10px; 
            }
            table { 
                width: 100%; 
                border-collapse: collapse; 
                font-size: 8pt; 
            }
            th { 
                background-color: #333; 
                color: white; 
                padding: 4px; 
                text-align: left; 
                font-size: 7pt;
                font-weight: bold;
            }
            td { 
                padding: 3px 4px; 
                border-bottom: 1px solid #ddd; 
            }
            tr:nth-child(even) { background-color: #f9f9f9; }
            .footer { 
                margin-top: 10px; 
                text-align: center; 
                font-size: 7pt; 
            }
        </style>
    </head>
    <body onload="window.print();">
        <h2>Employee Directory</h2>
        <div class="meta">
            Generated: <?= date('F d, Y h:i A') ?> | 
            Total: <?= count($employees) ?> employees
        </div>
        
        <table>
            <thead>
                <tr>
                    <th>Code</th>
                    <th>Name</th>
                    <th>Designation</th>
                    <th>Dept</th>
                    <th>Type</th>
                    <th>Status</th>
                    <th>Mobile</th>
                    <th>Email</th>
                    <th>Location</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($employees as $emp): ?>
                    <tr>
                        <td><?= htmlspecialchars($emp['code']) ?></td>
                        <td><?= htmlspecialchars($emp['name_eng'] ?? $emp['name']) ?></td>
                        <td><?= htmlspecialchars($emp['designation_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars($emp['department_name'] ?? 'N/A') ?></td>
                        <td><?= htmlspecialchars(str_replace('_', ' ', $emp['emp_type'])) ?></td>
                        <td><?= htmlspecialchars($emp['emp_status']) ?></td>
                        <td><?= htmlspecialchars($emp['mobile_number'] ?? '') ?></td>
                        <td><?= htmlspecialchars($emp['email'] ?? '') ?></td>
                        <td><?= htmlspecialchars(($emp['local_body'] ?? '') . ', ' . ($emp['state'] ?? '')) ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
        
        <div class="footer">
            © <?= date('Y') ?> HR Management System
        </div>
    </body>
    </html>
    <?php
    exit;
}

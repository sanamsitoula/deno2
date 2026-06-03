<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$d2m_id = $_GET['id'] ?? null;

if (!$d2m_id) {
    die("D2M ID is required");
}

// Fetch D2M record with related data
$stmt = $conn->prepare("
    SELECT d.*, 
           fy.fiscal_code as fiscal_year_name,
           u_created.username as created_by_name,
           u_checked.username as checked_by_name,
           u_verified.username as verified_by_name,
           u_send.username as send_by_name
    FROM d2m d 
    LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
    LEFT JOIN users u_created ON d.created_by = u_created.id
    LEFT JOIN users u_checked ON d.checked_by = u_checked.id
    LEFT JOIN users u_verified ON d.verified_by = u_verified.id
    LEFT JOIN users u_send ON d.send_by = u_send.id
    WHERE d.id = :id AND d.deleted_at IS NULL
");
$stmt->execute([':id' => $d2m_id]);
$d2m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$d2m) {
    die("D2M record not found");
}

// Fetch D2M items with book details and DENO reference numbers
$items_stmt = $conn->prepare("
    SELECT di.*, 
           b.book_name, 
           b.class_level, 
           b.is_translated,
           (
               SELECT string_agg(deno.ref_no, ', ' ORDER BY deno.ref_no)
               FROM unnest(string_to_array(di.associated_deno_ids, ',')) AS deno_id
               JOIN deno ON deno.id = deno_id::integer
           ) as ref_numbers
    FROM d2m_items di
    JOIN books b ON di.book_code = b.book_code
    WHERE di.d2m_id = :d2m_id
    ORDER BY b.book_name
");
$items_stmt->execute([':d2m_id' => $d2m_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Calculate totals
$total_poka_qty = array_sum(array_column($items, 'total_poka_qty'));
$total_qty = array_sum(array_column($items, 'total_qty'));
$total_open_pcs = array_sum(array_column($items, 'open_pcs'));
$net_production = $total_qty + $total_open_pcs;

// Group items by book_code for remarks
$code_summary = [];
foreach ($items as $item) {
    $code = $item['book_code'];
    $type_suffix = $item['is_translated'] ? '-T' : '-NT';
    $code_key = $code . $type_suffix;
    
    if (!isset($code_summary[$code_key])) {
        $code_summary[$code_key] = 0;
    }
    $code_summary[$code_key] += $item['total_qty'];
}

$current_datetime = date('Y-m-d H:i:s');

// Try to get logo - check multiple possible locations
$logo_paths = [
    $_SERVER['DOCUMENT_ROOT'] . '/deno2/assets/images/janak-logo.png',
    $_SERVER['DOCUMENT_ROOT'] . '/assets/images/janak-logo.png',
    $_SERVER['DOCUMENT_ROOT'] . '/images/janak-logo.png',
    $_SERVER['DOCUMENT_ROOT'] . '/deno2/assets/janak-logo.png',
];

$logo_src = '';
foreach ($logo_paths as $path) {
    if (file_exists($path)) {
        $logo_src = str_replace($_SERVER['DOCUMENT_ROOT'], '', $path);
        break;
    }
}

// If no logo found, use a placeholder
if (empty($logo_src)) {
    $logo_src = 'data:image/svg+xml;base64,' . base64_encode('
    <svg width="80" height="80" xmlns="http://www.w3.org/2000/svg">
        <rect width="80" height="80" fill="#1976d2"/>
        <circle cx="40" cy="40" r="30" fill="white"/>
        <text x="40" y="50" font-family="Arial" font-size="24" fill="#1976d2" text-anchor="middle">J</text>
    </svg>
    ');
}
?>

<!DOCTYPE html>
<html lang="ne">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D2M Report - <?= htmlspecialchars($d2m['d2m_no']) ?></title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }
        
        @page {
            margin: 0.5in;
            size: A4 portrait;
        }
        
        body {
            font-family: Arial, sans-serif;
            padding: 15px;
            font-size: 12px;
            line-height: 1.4;
        }
        
        .header {
            text-align: center;
            margin-bottom: 15px;
            border-bottom: 2px solid #000;
            padding-bottom: 10px;
        }
        
        .logo-section {
            display: flex;
            align-items: center;
            justify-content: center;
            margin-bottom: 10px;
        }
        
        .logo {
            width: 70px;
            height: 70px;
            margin-right: 15px;
            flex-shrink: 0;
        }
        
        .company-info {
            text-align: center;
        }
        
        .company-name {
            font-size: 20px;
            font-weight: bold;
            margin-bottom: 3px;
            color: #000;
        }
        
        .company-name-english {
            font-size: 14px;
            font-weight: bold;
            color: #333;
            margin-bottom: 3px;
        }
        
        .company-address {
            font-size: 11px;
            margin-bottom: 5px;
            color: #000;
        }
        
        .report-title {
            font-size: 16px;
            font-weight: bold;
            margin: 8px 0;
            color: #000;
        }
        
        .report-title-nepali {
            font-size: 14px;
            margin-bottom: 3px;
            color: #000;
        }
        
        .report-info {
            margin: 12px 0;
            border: 2px solid #000;
            padding: 8px;
            background: #f9f9f9;
            font-size: 11px;
        }
        
        .info-row {
            display: flex;
            justify-content: space-between;
            margin: 4px 0;
        }
        
        .info-label {
            font-weight: bold;
            color: #000;
        }
        
        .report-description {
            margin: 10px 0;
            padding: 8px;
            background: #f0f8ff;
            border: 1px solid #2196F3;
            font-size: 11px;
            text-align: center;
            font-weight: 500;
            line-height: 1.5;
            color: #000;
        }
        
        .report-summary {
            margin: 10px 0;
            border: 1px solid #000;
            padding: 8px;
            background: #f0f8ff;
            font-size: 11px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin: 4px 0;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            margin-top: 12px;
            font-size: 10px;
        }
        
        th, td {
            border: 1px solid #000;
            padding: 4px;
            text-align: center;
            vertical-align: middle;
        }
        
        th {
            background-color: #e9ecef;
            font-weight: bold;
            font-size: 9px;
            padding: 5px 3px;
        }
        
        .book-name-col {
            text-align: left !important;
            padding-left: 5px !important;
            padding-right: 5px !important;
        }
        
        .total-row {
            background-color: #e8f4f8;
            font-weight: bold;
        }
        
        .total-row td {
            border-top: 2px solid #000;
            font-size: 11px;
            padding: 6px 4px;
        }
        
        .ref-numbers {
            font-size: 9px;
            text-align: left;
            padding-left: 4px !important;
        }
        
        .remarks-col {
            font-size: 9px;
            text-align: left;
            padding: 3px !important;
            line-height: 1.3;
        }
        
        .signature-section {
            margin-top: 25px;
            page-break-inside: avoid;
        }
        
        .signature-row {
            display: flex;
            justify-content: space-between;
            margin-top: 15px;
        }
        
        .signature-item {
            flex: 1;
            text-align: center;
            margin: 0 10px;
        }
        
        .signature-item p {
            margin: 6px 0;
            font-weight: bold;
            font-size: 10px;
        }
        
        .signature-line {
            border-bottom: 1px solid #000;
            height: 25px;
            margin: 12px 8px;
        }
        
        .signature-label {
            font-size: 9px;
            color: #666;
            font-weight: normal !important;
        }
        
        .signature-name {
            font-size: 10px;
            color: #333;
            margin-top: 4px;
        }
        
        .signature-date {
            font-size: 9px;
            color: #666;
            margin-top: 2px;
        }
        
        .note-section {
            margin-top: 20px;
            text-align: center;
            font-size: 10px;
            color: #666;
        }
        
        .print-button {
            position: fixed;
            top: 10px;
            right: 10px;
            padding: 10px 20px;
            background-color: #007bff;
            color: white;
            border: none;
            border-radius: 5px;
            cursor: pointer;
            font-size: 14px;
            box-shadow: 0 2px 5px rgba(0,0,0,0.3);
            z-index: 1000;
        }
        
        .print-button:hover {
            background-color: #0056b3;
        }
        
        @media print {
            .print-button {
                display: none !important;
            }
            
            body {
                padding: 0;
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
            
            @page {
                margin: 0.4in;
            }
            
            .header {
                page-break-after: avoid;
            }
            
            .signature-section {
                page-break-inside: avoid;
            }
        }
    </style>
</head>
<body>
    <button class="print-button" onclick="window.print()">🖨️ Print Report</button>
    
    <div class="header">
        <div class="logo-section">
            <img src="<?= $logo_src ?>" alt="Janak Logo" class="logo">
            <div class="company-info">
                <div class="company-name">जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
                <div class="company-name-english">Janak Education Materials Centre Ltd.</div>
            </div>
        </div>
        <div class="company-address">सानोठिमी, भक्तपुर - उत्पादन विभाग | Sanothimi, Bhaktapur - Production Department</div>
        <div class="report-title-nepali">डेनो देखि मार्केटिंग रिपोर्ट</div>
        <div class="report-title">Deno to Marketing (D2M) Report</div>
    </div>
    
    <div class="report-info">
        <div class="info-row">
            <span><span class="info-label">D2M Number:</span> <?= htmlspecialchars($d2m['d2m_no']) ?></span>
            <span><span class="info-label">Serial No:</span> <?= $d2m['serial_no'] ?></span>
        </div>
        <div class="info-row">
            <span><span class="info-label">Type (प्रकार):</span> 
                <?= $d2m['d2m_type'] == 'T' ? 'अनुवादित (Translated)' : 'गैर-अनुवादित (Non-Translated)' ?>
            </span>
            <span><span class="info-label">Status (स्थिति):</span> <?= htmlspecialchars($d2m['status']) ?></span>
        </div>
        <div class="info-row">
            <span><span class="info-label">Fiscal Year (आ.व.):</span> <?= htmlspecialchars($d2m['fiscal_year_name']) ?></span>
            <span><span class="info-label">Nepali Date (नेपाली मिति):</span> <?= htmlspecialchars($d2m['nep_date']) ?></span>
        </div>
        <div class="info-row">
            <span><span class="info-label">English Date (अंग्रेजी मिति):</span> <?= date('Y-m-d', strtotime($d2m['eng_date'])) ?></span>
            <span><span class="info-label">Created By (तयार गर्ने):</span> <?= htmlspecialchars($d2m['created_by_name']) ?></span>
        </div>
        <?php if ($d2m['remarks']): ?>
        <div class="info-row">
            <span><span class="info-label">Remarks (कैफियत):</span> <?= htmlspecialchars($d2m['remarks']) ?></span>
        </div>
        <?php endif; ?>
    </div>
    
    <!-- Description based on type -->
    <div class="report-description">
        <?php if ($d2m['d2m_type'] == 'T'): ?>
            यस उत्पादन विभागबाट उत्पादन भएका कक्षा १ देखि १० सम्मका <strong>अनुवादित पाठ्यपुस्तकहरु</strong> बजार व्यवस्था विभागको स्टोरमा दाखिला भएको हिसाव विवरण
        <?php else: ?>
            यस उत्पादन विभागबाट उत्पादित भएको कक्षा १ देखि १२ सम्मका <strong>पाठ्यपुस्तकहरु</strong> बजार व्यवस्था विभागको स्टोरमा दाखिला भएको हिसाव विवरण
        <?php endif; ?>
    </div>
    
    <div class="report-summary">
        <div class="summary-row">
            <span><strong>Total Items (कुल आइटम):</strong> <?= count($items) ?></span>
            <span><strong>Total Poka Qty (कुल पोका संख्या):</strong> <?= number_format($total_poka_qty) ?></span>
        </div>
        <div class="summary-row">
            <span><strong>Total Books (कुल पुस्तक):</strong> <?= number_format($total_qty) ?></span>
            <span><strong>Total Open Pcs (कुल खुद्रा):</strong> <?= number_format($total_open_pcs) ?></span>
        </div>
        <div class="summary-row">
            <span><strong>Net Production (शुद्ध उत्पादन):</strong> <?= number_format($net_production) ?></span>
            <span><strong>Report Generated (रिपोर्ट तयार):</strong> <?= date('Y-m-d h:i A') ?></span>
        </div>
    </div>
    
    <table>
        <thead>
            <tr>
                <th style="width: 3%;">सि.नं.<br>(SN)</th>
                <th style="width: 20%;">पुस्तकको नाम<br>(Book Name)</th>
                <th style="width: 5%;">कक्षा<br>(Class)</th>
                <th style="width: 7%;">कोडा<br>(Code)</th>
                <th style="width: 7%;">प्रति पोका<br>(Per Poka)</th>
                <th style="width: 7%;">पोका संख्या<br>(Poka Qty)</th>
                <th style="width: 8%;">जम्मा पुस्तक<br>(Total Qty)</th>
                <th style="width: 7%;">खुद्रा पुस्तक<br>(Open Pcs)</th>
                <th style="width: 12%;">कैफियत<br>(Remarks)</th>
                <th style="width: 15%;">DENO रेफरेन्स नं<br>(Ref Numbers)</th>
            </tr>
        </thead>
        <tbody>
            <?php 
            $sn = 1; 
            $current_code = null;
            foreach ($items as $item): 
                $type_suffix = $item['is_translated'] ? '-T' : '-NT';
                $code_key = $item['book_code'] . $type_suffix;
                $show_remark = ($current_code != $code_key);
                if ($show_remark) {
                    $current_code = $code_key;
                }
            ?>
            <tr>
                <td><?= $sn++ ?></td>
                <td class="book-name-col">
                    <?= htmlspecialchars($item['book_name']) ?>
                </td>
                <td><?= htmlspecialchars($item['class_level']) ?></td>
                <td><?= htmlspecialchars($item['book_code']) ?></td>
                <td><?= number_format($item['per_poka_qty']) ?></td>
                <td><?= number_format($item['total_poka_qty']) ?></td>
                <td><strong><?= number_format($item['total_qty']) ?></strong></td>
                <td><?= number_format($item['open_pcs']) ?></td>
                <td class="remarks-col">
                    <?php if ($show_remark): ?>
                        <?= $code_key ?>: <?= number_format($code_summary[$code_key]) ?>
                    <?php endif; ?>
                </td>
                <td class="ref-numbers"><?= htmlspecialchars($item['ref_numbers'] ?? 'N/A') ?></td>
            </tr>
            <?php endforeach; ?>
            
            <!-- Total Row -->
            <tr class="total-row">
                <td colspan="5"><strong>जम्मा (Total)</strong></td>
                <td><strong><?= number_format($total_poka_qty) ?></strong></td>
                <td><strong><?= number_format($total_qty) ?></strong></td>
                <td><strong><?= number_format($total_open_pcs) ?></strong></td>
                <td></td>
                <td></td>
            </tr>
        </tbody>
    </table>
    
    <div class="signature-section">
        <div class="signature-row">
            <div class="signature-item">
                <p><strong>प्रेशबाट बुझाउने</strong></p>
                <p style="font-size: 9px; font-weight: normal;">(Send By - Production Dept.)</p>
                <div class="signature-line"></div>
                <p class="signature-label">हस्ताक्षर (Signature)</p>
                <?php if (!empty($d2m['send_by_name'])): ?>
                <p class="signature-name"><?= htmlspecialchars($d2m['send_by_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($d2m['send_by_date'])): ?>
                <p class="signature-date">मिति: <?= htmlspecialchars($d2m['send_by_date']) ?></p>
                <?php endif; ?>
            </div>
            
            <div class="signature-item">
                <p><strong>स्टोर किपर</strong></p>
                <p style="font-size: 9px; font-weight: normal;">(Store Keeper - Marketing Dept.)</p>
                <div class="signature-line"></div>
                <p class="signature-label">हस्ताक्षर (Signature)</p>
                <?php if (!empty($d2m['checked_by_name'])): ?>
                <p class="signature-name"><?= htmlspecialchars($d2m['checked_by_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($d2m['checked_at'])): ?>
                <p class="signature-date">मिति: <?= date('Y-m-d', strtotime($d2m['checked_at'])) ?></p>
                <?php endif; ?>
            </div>
            
            <div class="signature-item">
                <p><strong>प्रमाणित गर्ने</strong></p>
                <p style="font-size: 9px; font-weight: normal;">(Verified By - Marketing Dept.)</p>
                <div class="signature-line"></div>
                <p class="signature-label">हस्ताक्षर (Signature)</p>
                <?php if (!empty($d2m['verified_by_name'])): ?>
                <p class="signature-name"><?= htmlspecialchars($d2m['verified_by_name']) ?></p>
                <?php endif; ?>
                <?php if (!empty($d2m['verified_at'])): ?>
                <p class="signature-date">मिति: <?= date('Y-m-d', strtotime($d2m['verified_at'])) ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <div class="note-section">
            <p><strong>नोट:</strong> यो रिपोर्ट प्रणालीबाट स्वचालित रूपमा उत्पन्न गरिएको हो।</p>
            <p>(This report is automatically generated by the system.)</p>
        </div>
    </div>
    
</body>
</html>
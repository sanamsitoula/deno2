<?php
// jobticket/print.php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/functions.php';

if (!isset($_GET['id'])) {
    header("Location: index.php");
    exit();
}
$id = intval($_GET['id']);

// Get job ticket with details using direct queries
$stmt = $conn->prepare("
    SELECT j.*, b.book_code, b.book_name, b.class_level, fy.fiscal_code
    FROM job_ticket j
    JOIN books b ON j.book_id = b.book_id
    JOIN fiscal_years fy ON j.fiscal_year_id = fy.id
    WHERE j.id = ?
");
$stmt->execute([$id]);
$ticket = $stmt->fetch();

if (!$ticket) {
    $_SESSION['error'] = "Job Ticket not found!";
    header("Location: index.php");
    exit();
}

// Get job ticket details
$stmt_details = $conn->prepare("
    SELECT d.*, f.name as forma_name
    FROM job_ticket_details d
    JOIN forma f ON d.forma_id = f.id
    WHERE d.job_ticket_id = ?
    ORDER BY d.order_no ASC
");
$stmt_details->execute([$id]);
$details = $stmt_details->fetchAll();

// Get created by and updated by user info
$createdUserStmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
$createdUserStmt->execute([$ticket['created_by']]);
$createdUser = $createdUserStmt->fetchColumn();

$updatedUser = '';
if ($ticket['updated_by']) {
    $updatedUserStmt = $conn->prepare("SELECT username FROM users WHERE id = ?");
    $updatedUserStmt->execute([$ticket['updated_by']]);
    $updatedUser = $updatedUserStmt->fetchColumn();
}

// Calculate totals
$total_print_qty = array_sum(array_column($details, 'print_qty'));
$total_pages = array_sum(array_column($details, 'page'));

// Get lot history for this book and fiscal year
$lotHistoryStmt = $conn->prepare("
    SELECT lot, print_qty, job_ticket_code 
    FROM job_ticket 
    WHERE book_id = ? AND fiscal_year_id = ? 
    ORDER BY CAST(lot AS INTEGER) ASC
");
$lotHistoryStmt->execute([$ticket['book_id'], $ticket['fiscal_year_id']]);
$lotHistory = $lotHistoryStmt->fetchAll();

// Format lot display with cumulative quantities
$lotDisplay = '';
foreach ($lotHistory as $lot) {
    $lotDisplay .= "Lot {$lot['lot']}: " . number_format($lot['print_qty']) . " pcs | ";
}
$lotDisplay = rtrim($lotDisplay, ' | ');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Job Ticket - <?= htmlspecialchars($ticket['job_ticket_code']) ?></title>
    <style>
        @page {
            size: A4;
            margin: 10mm;
        }
        body {
            font-family: 'Noto Sans Devanagari', Arial, sans-serif;
            font-size: 18px;
            line-height: 1.2;
            margin: 0;
            padding: 15px;
            border: 2px solid #000;
        }
        .header {
            text-align: center;
            margin-bottom: 10px;
            padding-bottom: 5px;
            border-bottom: 2px solid #000;
        }
        .company-name {
            font-size: 22px;
            font-weight: bold;
            margin-bottom: 2px;
        }
        .department {
            font-size: 18px;
            margin-bottom: 5px;
        }
        .ticket-title {
            font-size: 20px;
            text-align: center;
            margin: 10px 0;
            font-weight: bold;
        }
        .ticket-info {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 10px;
            margin-bottom: 15px;
        }
        .ticket-info-row {
            display: flex;
            margin-bottom: 5px;
        }
        .ticket-label {
            font-weight: bold;
            min-width: 140px;
        }
        .forma-table {
            width: 100%;
            border-collapse: collapse;
            margin: 10px 0;
        }
        .forma-table th, .forma-table td {
            border: 1px solid #000;
            padding: 5px;
            text-align: center;
        }
        .forma-table th {
            background-color: #f0f0f0;
        }
        .signature-section {
            display: flex;
            justify-content: flex-end;
            margin-top: 20px;
        }
        .signature-box {
            text-align: center;
            width: 200px;
            border-top: 1px solid #000;
            padding-top: 5px;
        }
        .total-row {
            font-weight: bold;
        }
        .notes {
            margin-top: 10px;
            font-size: 16px;
        }
        .lot-info {
            background-color: #f9f9f9;
            padding: 8px;
            margin-top: 5px;
            border: 1px solid #ddd;
            font-size: 14px;
        }
        @media print {
            .no-print {
                display: none;
            }
        }
    </style>
    <link href="https://fonts.googleapis.com/css2?family=Noto+Sans+Devanagari:wght@400;700&display=swap" rel="stylesheet">
</head>
<body>
    <div class="no-print" style="margin-bottom:10px;">
        <button onclick="window.print()" style="padding:5px 10px;font-size:16px;">Print</button>
        <button onclick="window.close()" style="padding:5px 10px;font-size:16px;">Close</button>
    </div>

    <div class="header">
        <div class="company-name">जनक शिक्षा सामग्री केन्द्र लि.</div>
        <div class="department">उत्पादन विभाग</div>
    </div>

    <h3 class="ticket-title" style="text-align:center;margin:10px 0;">पाठ्यपुस्तकहरूको छपाइ जब टिकट</h3>

    <div class="ticket-info">
        <div class="ticket-info-row">
            <span class="ticket-label">पाठ्यपुस्तक:</span>
            <span><?= htmlspecialchars($ticket['book_code']) ?> - <?= htmlspecialchars($ticket['book_name']) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">शैक्षिक सत्र:</span>
            <span><?= htmlspecialchars(intval($ticket['fiscal_code']) + 1) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">कक्षा:</span>
            <span><?= htmlspecialchars($ticket['class_level']) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">जब नं.:</span>
            <span><?= htmlspecialchars($ticket['job_ticket_code']) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">पेज संख्या:</span>
            <span><?= number_format($total_pages) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">मिति (नेपाली):</span>
            <span><?= htmlspecialchars($ticket['date_nep']) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">छपाइ संख्या:</span>
            <span><?= number_format($ticket['print_qty']) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">मिति (अंग्रेजी):</span>
            <span><?= htmlspecialchars($ticket['date_eng']) ?></span>
        </div>
        <div class="ticket-info-row">
            <span class="ticket-label">Lot:</span>
            <span><?= htmlspecialchars($ticket['lot']) ?></span>
        </div>
        <div class="ticket-info-row" style="grid-column: 1 / -1;">
            <div class="lot-info">
                <strong>Lot History:</strong> <?= $lotDisplay ?>
            </div>
        </div>
    </div>

    <table class="forma-table">
        <thead>
            <tr>
                <th style="width:5%">सि.नं.</th>
                <th style="width:25%">फर्मा</th>
                <th style="width:10%">पेज</th>
                <th style="width:15%">फर्मा बाँकी</th>
                <th style="width:15%">छापिने संख्या</th>
                <th style="width:15%">मेसिन</th>
                <th style="width:15%">कैफियत</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($details as $index => $detail): ?>
                <tr>
                    <td><?= $index + 1 ?></td>
                    <td><?= htmlspecialchars($detail['forma_name']) ?></td>
                    <td><?= $detail['page'] ?></td>
                    <td><?= number_format($detail['old_forma_qty']) ?></td>
                    <td><?= number_format($detail['print_qty']) ?></td>
                    <td><?= htmlspecialchars($detail['machine']) ?></td>
                    <td><?= htmlspecialchars($detail['description']) ?></td>
                </tr>
            <?php endforeach; ?>
            <tr class="total-row">
                <td colspan="2">जम्मा</td>
                <td><?= number_format($total_pages) ?></td>
                <td><?= number_format(array_sum(array_column($details, 'old_forma_qty'))) ?></td>
                <td><?= number_format($total_print_qty) ?></td>
                <td colspan="2"></td>
            </tr>
        </tbody>
    </table>

    <!-- Compact notes -->
    <div class="notes">
        <div><strong>कुल सहित जम्मा पेज:</strong> <?= number_format($total_pages) ?></div>
        <div style="margin-top:5px;">
            <div>१. श्रीमान प्रबन्ध संचालकज्यू</div>
            <div>२. श्रीमान बिभागीय प्रमुखज्यू</div>
            <div>३. नयाँ तथा पुरानोभवन अफसेट</div>
            <div>४. नयाँ सि.टि.पि</div>
            <div>५. बाइन्डिङ शाखा</div>
        </div>
    </div>
    
    <div class="signature-section">
        <div class="signature-box">इन्चार्ज</div>
    </div>
    
    <div style="text-align: center; margin-top: 10px; font-size: 20px;">
        <p><strong>कभर P.R.C अनुसार मुद्रण गर्ने</strong></p>
    </div>
    
    <div style="font-size: 14px; margin-top: 10px; color: #666; border-top: 1px solid #ddd; padding-top: 10px;">
        <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
            <div>
                <strong>Created:</strong><br>
                Date (Nepali): <?= htmlspecialchars($ticket['date_nep']) ?><br>
                Date (English): <?= date('Y-m-d', strtotime($ticket['created_date'])) ?><br>
                By: <?= htmlspecialchars($createdUser) ?>
            </div>
            <?php if ($ticket['updated_by'] && $updatedUser): ?>
            <div>
                <strong>Last Updated:</strong><br>
                Date: <?= date('Y-m-d', strtotime($ticket['updated_date'])) ?><br>
                By: <?= htmlspecialchars($updatedUser) ?>
            </div>
            <?php endif; ?>
        </div>
        <div style="margin-top: 10px;">
            <strong>Report Generated:</strong> <?= date('Y-m-d H:i') ?>
        </div>
    </div>
</body>
</html>
<?php exit(); ?>
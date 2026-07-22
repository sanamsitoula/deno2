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
           fy.fiscal_name as fiscal_year_name,
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

// Fetch D2M items
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
    ORDER BY 
        CASE WHEN b.class_level::text ~ '^[0-9]+$' THEN b.class_level::text::integer ELSE 999 END ASC,
        b.book_name ASC
");
$items_stmt->execute([':d2m_id' => $d2m_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Sort ref_numbers within each item numerically
foreach ($items as &$item) {
    if (!empty($item['ref_numbers'])) {
        $refs = array_map('trim', explode(',', $item['ref_numbers']));
        usort($refs, function($a, $b) {
            $an = (int)preg_replace('/\D/', '', $a);
            $bn = (int)preg_replace('/\D/', '', $b);
            return $an - $bn;
        });
        $item['ref_numbers'] = implode(', ', $refs);
    }
}
unset($item);

// Calculate totals
$total_poka_qty = array_sum(array_column($items, 'total_poka_qty'));
$total_qty      = array_sum(array_column($items, 'total_qty'));
$total_open_pcs = array_sum(array_column($items, 'open_pcs'));

// Group items by book_code for remarks
$code_summary = [];
foreach ($items as $item) {
    $code        = $item['book_code'];
    $type_suffix = $item['is_translated'] ? '-T' : '-NT';
    $code_key    = $code . $type_suffix;
    if (!isset($code_summary[$code_key])) {
        $code_summary[$code_key] = ['qty' => 0, 'open' => 0];
    }
    $code_summary[$code_key]['qty']  += $item['total_qty'];
    $code_summary[$code_key]['open'] += $item['open_pcs'];
}
?>
<!DOCTYPE html>
<html lang="ne">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>D2M Report - <?= htmlspecialchars($d2m['d2m_no']) ?></title>
    <style>
        /* ── Reset ── */
        *, *::before, *::after { margin: 0; padding: 0; box-sizing: border-box; }

        /* ── Page setup: 5mm left margin for gutter, tight top ── */
        @page {
            size: A4 portrait;
            margin: 2mm 5mm 8mm 5mm;
        }

        /* ════════════════════════════════════════════════
           PRINT MEDIA — hide entire Janak app shell
        ════════════════════════════════════════════════ */
        @media print {
            /* Nuclear option: hide everything not part of our report */
            body > *:not(.gutter-strip):not(.report-wrapper) {
                display: none !important;
                visibility: hidden !important;
            }

            /* Named selectors from header.php / layout */
            header, footer, nav,
            .navbar, .navbar-custom, .navbar-wrapper, .nav-wrapper,
            .sidebar, .sidebar-wrapper, .side-nav,
            .jpms-layout, .jpms-header, .jpms-nav,
            .app-header, .main-header, .top-header,
            .topbar, .header-bar, .page-header,
            #mobileMenuBtn, #sidebar, #navbar, #header,
            .header, .main-nav,
            .print-button {
                display: none !important;
                visibility: hidden !important;
            }

            html, body {
                margin: 0 !important;
                padding: 0 !important;
                background: #fff !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
            }

            /* Keep gutter strip visible on print */
            .gutter-strip {
                display: flex !important;
                visibility: visible !important;
                position: fixed;
                left: 0; top: 0; bottom: 0;
                width: 5mm;
                background: #1a237e !important;
                -webkit-print-color-adjust: exact !important;
                print-color-adjust: exact !important;
                z-index: 9999;
            }

            .report-wrapper {
                display: block !important;
                visibility: visible !important;
                margin-left: 6mm !important;
                padding: 0 2mm !important;
                box-shadow: none !important;
                background: #fff !important;
                max-width: 100% !important;
            }
        }

        /* ── Screen ── */
        html, body {
            font-family: Arial, sans-serif;
            font-size: 11px;
            line-height: 1.35;
            color: #000;
            background: #d8d8d8;
            -webkit-print-color-adjust: exact !important;
            print-color-adjust: exact !important;
        }

        /* ── Left gutter index strip ── */
        .gutter-strip {
            position: fixed;
            left: 0; top: 0; bottom: 0;
            width: 5mm;
            background: #1a237e;
            z-index: 8000;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .gutter-strip span {
            writing-mode: vertical-rl;
            transform: rotate(180deg);
            color: #fff;
            font-size: 7px;
            font-weight: bold;
            letter-spacing: 1.5px;
            text-transform: uppercase;
            white-space: nowrap;
            font-family: Arial, sans-serif;
        }

        /* ── Report wrapper ── */
        .report-wrapper {
            margin-left: 8mm;
            max-width: 760px;
            background: #fff;
            padding: 10px 14px 14px;
            box-shadow: 0 2px 12px rgba(0,0,0,.22);
            margin-top: 10px;
            margin-bottom: 14px;
        }

        /* ── Print button (screen only) ── */
        .print-button {
            position: fixed;
            top: 14px;
            right: 16px;
            padding: 10px 24px;
            background: #007bff;
            color: #fff;
            border: none;
            border-radius: 6px;
            cursor: pointer;
            font-size: 14px;
            font-weight: 700;
            box-shadow: 0 2px 8px rgba(0,0,0,.3);
            z-index: 9999;
            display: none; /* hidden — auto-print fires on load */
        }

        /* ── Report header ── */
        .report-header {
            text-align: center;
            border-bottom: 2px solid #000;
            padding-bottom: 5px;
            margin-bottom: 5px;
        }
        .company-name {
            font-size: 17px;
            font-weight: 900;
            color: #000;
        }
        .company-name-english {
            font-size: 13px;
            font-weight: 800;
            color: #000;
            margin-bottom: 2px;
        }
        .company-address {
            font-size: 10px;
            color: #111;
            margin-bottom: 3px;
        }
        .report-title-nepali {
            font-size: 11px;
            font-weight: 600;
        }
        .report-title {
            font-size: 13px;
            font-weight: bold;
        }

        /* ── Info box ── */
        .report-info {
            border: 1.5px solid #000;
            padding: 4px 7px;
            background: #f9f9f9;
            font-size: 10px;
            margin-bottom: 5px;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }
        .info-row {
            display: flex;
            justify-content: space-between;
            gap: 10px;
            margin: 2px 0;
        }
        .info-label { font-weight: bold; }

        /* ── Description banner ── */
        .report-description {
            margin-bottom: 5px;
            padding: 4px 7px;
            background: #e8f4ff;
            border: 1px solid #2196F3;
            font-size: 10px;
            text-align: center;
            font-weight: 500;
            line-height: 1.4;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ════════════════════════════════════════════════
           MAIN TABLE
        ════════════════════════════════════════════════ */
        table.main-table {
            width: 100%;
            border-collapse: collapse;
            font-size: 11px;       /* base — numeric cells inherit */
            table-layout: fixed;
        }

        table.main-table th,
        table.main-table td {
            border: 1px solid #000;
            padding: 2px 3px;
            text-align: center;
            vertical-align: top;
            word-wrap: break-word;
            overflow-wrap: break-word;
            white-space: normal;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        table.main-table th {
            background-color: #e0e0e0 !important;
            font-weight: bold;
            font-size: 10px;
            vertical-align: middle;
            line-height: 1.3;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* Column widths — set via HTML width="" attributes on <col> tags.
           CSS col selectors are unreliable with table-layout:fixed across
           browsers/print engines, so we use inline width attributes below. */

        /* ── Book name: 2× bigger font ── */
        .book-name-col {
            text-align: left !important;
            padding-left: 4px !important;
            font-size: 13px;        /* 2× the 11px table base ≈ doubled */
            font-weight: 600;
            word-break: break-word;
            line-height: 1.3;
            vertical-align: middle !important;
        }

        /* ── Numeric value cells: bigger + bold ── */
        .num-cell {
            font-size: 13px;
            font-weight: 600;
            text-align: center;
            vertical-align: middle !important;
        }

        /* ── Ref numbers: ZERO padding, full wrap, small font ── */
        .ref-col {
            text-align: left !important;
            padding: 1px 0 !important;
            margin: 0 !important;
            font-size: 8px;
            word-break: break-all;      /* break anywhere so it never overflows */
            overflow-wrap: break-word;
            white-space: normal;
            line-height: 1.35;
            vertical-align: top;
            max-width: 0;               /* forces table-layout:fixed to honour col width */
        }

        /* ── Remarks: left-aligned, wraps ── */
        .remarks-col {
            text-align: left !important;
            padding: 2px 3px !important;
            font-size: 10.5px;
            word-break: break-word;
            white-space: normal;
            line-height: 1.4;
        }

        /* ── Total row ── */
        .total-row td {
            background-color: #d8eef8 !important;
            font-weight: bold;
            border-top: 2px solid #000;
            font-size: 13px;
            vertical-align: middle;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
        }

        /* ── Signatures ── */
        .signature-section {
            margin-top: 12px;
            page-break-inside: avoid;
        }
        .signature-row {
            display: flex;
            justify-content: space-between;
            margin-top: 10px;
        }
        .signature-item {
            flex: 1;
            text-align: center;
            margin: 0 5px;
        }
        .signature-item p {
            margin: 2px 0;
            font-size: 10px;
        }
        .signature-line {
            border-bottom: 1px solid #000;
            height: 22px;
            margin: 9px 5px;
        }
        .signature-label { font-size: 9px; color: #555; }
        .signature-name  { font-size: 10px; color: #222; margin-top: 2px; }
        .signature-date  { font-size: 9px; color: #555; margin-top: 1px; }

        .note-section {
            margin-top: 10px;
            text-align: center;
            font-size: 9px;
            color: #555;
        }
    </style>
</head>
<body>

<!-- LEFT GUTTER STRIP (5mm, dark blue, D2M number printed vertically) -->
<div class="gutter-strip">
    <span>D2M &nbsp; <?= htmlspecialchars($d2m['d2m_no']) ?></span>
</div>

<!-- Fallback manual print button (hidden by default; visible if JS disabled) -->
<button class="print-button" onclick="window.print()">🖨️ Print</button>

<div class="report-wrapper">

    <!-- ═══ HEADER ═══ -->
    <div class="report-header">
        <div class="company-name">जनक शिक्षा सामग्री केन्द्र लिमिटेड</div>
        <div class="company-name-english">Janak Education Materials Centre Ltd.</div>
        <div class="company-address">सानोठिमी, भक्तपुर — उत्पादन विभाग &nbsp;|&nbsp; Sanothimi, Bhaktapur — Production Department</div>
        <div class="report-title-nepali">डेनो देखि मार्केटिंग रिपोर्ट</div>
        <div class="report-title">Deno to Marketing (D2M) Report</div>
    </div>

    <!-- ═══ INFO ═══ -->
    <div class="report-info">
        <div class="info-row">
            <span><span class="info-label">D2M No:</span> <?= htmlspecialchars($d2m['d2m_no']) ?></span>
            <span><span class="info-label">Serial No:</span> <?= $d2m['serial_no'] ?></span>
            <span><span class="info-label">Type:</span>
                <?= $d2m['d2m_type'] == 'T' ? 'Translated (T)' : 'Non-Translated (NT)' ?>
            </span>
            <span><span class="info-label">Status:</span> <?= htmlspecialchars($d2m['status']) ?></span>
        </div>
        <div class="info-row">
            <span><span class="info-label">Fiscal Year:</span> <?= htmlspecialchars($d2m['fiscal_year_name']) ?></span>
            <span><span class="info-label">Nepali Date:</span> <?= htmlspecialchars($d2m['nep_date']) ?></span>
            <span><span class="info-label">English Date:</span> <?= date('Y-m-d', strtotime($d2m['eng_date'])) ?></span>
            <span><span class="info-label">Created By:</span> <?= htmlspecialchars($d2m['created_by_name']) ?></span>
        </div>
        <?php if ($d2m['remarks']): ?>
        <div class="info-row">
            <span><span class="info-label">Remarks:</span> <?= htmlspecialchars($d2m['remarks']) ?></span>
        </div>
        <?php endif; ?>
    </div>

    <!-- ═══ DESCRIPTION ═══ -->
    <div class="report-description">
        <?php if ($d2m['d2m_type'] == 'T'): ?>
            यस उत्पादन विभागबाट उत्पादन भएका कक्षा १ देखि १० सम्मका <strong>अनुवादित पाठ्यपुस्तकहरु</strong> बजार व्यवस्था विभागको स्टोरमा दाखिला भएको हिसाव विवरण
        <?php else: ?>
            यस उत्पादन विभागबाट उत्पादित भएको कक्षा १ देखि १२ सम्मका <strong>पाठ्यपुस्तकहरु</strong> बजार व्यवस्था विभागको स्टोरमा दाखिला भएको हिसाव विवरण
        <?php endif; ?>
    </div>

    <!-- ═══ TABLE ═══ -->
    <table class="main-table">
        <colgroup>
            <col width="3%">   <!-- SN -->
            <col width="20%">  <!-- Book Name -->
            <col width="5%">   <!-- Class -->
            <col width="12%">   <!-- Code -->
            <col width="6%">   <!-- Per Poka -->
            <col width="7%">   <!-- Poka Qty -->
            <col width="17%">   <!-- Total Qty -->
            <col width="6%">   <!-- Open Pcs -->
            <col width="16%">  <!-- Remarks -->
            <col width="8%">  <!-- Ref Numbers — reduced & wraps -->
        </colgroup>
        <thead>
            <tr>
                <th>सि.नं.<br>(SN)</th>
                <th>पुस्तकको नाम<br>(Book Name)</th>
                <th>कक्षा<br>(Class)</th>
                <th>कोड<br>(Code)</th>
                <th>प्रति<br>पोका</th>
                <th>पोका<br>संख्या</th>
                <th>जम्मा<br>पुस्तक</th>
                <th>खुद्रा<br>पुस्तक</th>
                <th>कैफियत<br>(Remarks)</th>
                <th>DENO रेफरेन्स नं<br>(Ref Numbers)</th>
            </tr>
        </thead>
        <tbody>
        <?php
        $sn = 1;
        $current_code = null;
        foreach ($items as $item):
            $type_suffix = $item['is_translated'] ? '-T' : '-NT';
            $code_key    = $item['book_code'] . $type_suffix;
            $show_remark = ($current_code !== $code_key);
            if ($show_remark) $current_code = $code_key;
        ?>
        <tr>
            <td class="num-cell"><?= $sn++ ?></td>
            <td class="book-name-col"><?= htmlspecialchars($item['book_name']) ?></td>
            <td class="num-cell"><?= htmlspecialchars($item['class_level']) ?></td>
            <td style="font-size:11px; vertical-align:middle;"><?= htmlspecialchars($item['book_code']) ?></td>
            <td class="num-cell"><?= number_format($item['per_poka_qty']) ?></td>
            <td class="num-cell"><?= number_format($item['total_poka_qty']) ?></td>
            <td class="num-cell"><strong><?= number_format($item['total_qty']) ?></strong></td>
            <td class="num-cell"><?= number_format($item['open_pcs']) ?></td>
            <td class="remarks-col">
                <?php if ($show_remark): ?>
                    <strong><?= $code_key ?>: <?= number_format($code_summary[$code_key]['qty']) ?></strong>
                    <?php if ($code_summary[$code_key]['open'] > 0): ?>
                        +<?= number_format($code_summary[$code_key]['open']) ?>(Open)
                    <?php endif; ?>
                <?php endif; ?>
            </td>
            <td class="ref-col"><?= htmlspecialchars($item['ref_numbers'] ?? 'N/A') ?></td>
        </tr>
        <?php endforeach; ?>

        <!-- Total Row -->
        <tr class="total-row">
            <td colspan="5" style="text-align:left; padding-left:5px;"><strong>जम्मा (Total)</strong></td>
            <td><strong><?= number_format($total_poka_qty) ?></strong></td>
            <td><strong><?= number_format($total_qty) ?></strong></td>
            <td><strong><?= number_format($total_open_pcs) ?></strong></td>
            <td></td>
            <td></td>
        </tr>
        </tbody>
    </table>

    <!-- ═══ SIGNATURES ═══ -->
    <div class="signature-section">
        <div class="signature-row">

            <div class="signature-item">
                <p><strong>प्रेशबाट बुझाउने</strong></p>
                <p style="font-size:9px;">(Send By - Production Dept.)</p>
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
                <p style="font-size:9px;">(Store Keeper - Marketing Dept.)</p>
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
                <p style="font-size:9px;">(Verified By - Marketing Dept.)</p>
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
            <p><strong>नोट:</strong> यो रिपोर्ट प्रणालीबाट स्वचालित रूपमा उत्पन्न गरिएको हो।
            (This report is automatically generated by the system.)</p>
        </div>
    </div>

</div><!-- /report-wrapper -->

<script>
/**
 * Auto-trigger print dialog immediately when the page loads.
 * The Print button in index.php opens this page in a new tab (target="_blank"),
 * so this fires the browser print popup automatically.
 */
window.addEventListener('load', function () {
    setTimeout(function () {
        window.print();
    }, 350); // small delay ensures DOM + styles are fully painted
});
</script>
</body>
</html>
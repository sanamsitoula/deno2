<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

// For data modification actions (none here, but kept for consistency)
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
      if (!has_role('supervisor') && !has_role('incharge') && !has_role('admin')) {

        echo "<div class='alert alert-danger'>You don't have permission to perform this action.</div>";
        exit();
    }
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// ========================
// FILTERS
// ========================
$selected_month = $_GET['nep_month'] ?? date('Y.m'); // e.g., 2081.04
$fiscal_year_filter = $_GET['fiscal_year_id'] ?? '';
$book_code_filter = $_GET['book_code'] ?? '';
$packing_status_filter = $_GET['packing_status'] ?? 'all';
$supervisor_filter = $_GET['supervisor_id'] ?? '';
$class_level_filter = $_GET['class_level'] ?? '';

// Extract year and month for query
list($nep_year, $nep_month) = explode('.', $selected_month);
$nep_year = (int)$nep_year;
$nep_month = str_pad((int)$nep_month, 2, '0', STR_PAD_LEFT);

// Generate first and last date of the month (approximate)
$date_from = sprintf('%04d.%02d.01', $nep_year, $nep_month);
$date_to = sprintf('%04d.%02d.32', $nep_year, $nep_month); // Safe upper bound

// Build WHERE conditions
$where_conditions = ["bp.status = true"];
$params = [];

$where_conditions[] = "bp.date_nep >= :date_from";
$params[':date_from'] = $date_from;

$where_conditions[] = "bp.date_nep < :date_to_next_month";
$next_month = (int)$nep_month + 1;
$next_year = $nep_year;
if ($next_month > 12) {
    $next_month = 1;
    $next_year++;
}
$params[':date_to_next_month'] = sprintf('%04d.%02d.01', $next_year, $next_month);

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

// ========================
// MAIN QUERY
// ========================
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
    ORDER BY bp.book_code, bp.date_nep ASC
";

$stmt = $conn->prepare($query);
$stmt->execute($params);
$records = $stmt->fetchAll(PDO::FETCH_ASSOC);

// ========================
// GROUP DATA: book_code => [day1 => qty, day2 => qty, ..., job_tickets => unique]
// ========================
$data = [];
$days_in_month = range(1, 32);
$total_per_day = array_fill_keys($days_in_month, 0);
$total_per_book = [];

foreach ($records as $r) {
    $book_code = $r['book_code'];
    $date_parts = explode('.', $r['date_nep']);
    $day = (int)end($date_parts);

    if ($day < 1 || $day > 32) continue;

    if (!isset($data[$book_code])) {
        $data[$book_code] = [
            'book_name' => $r['book_name'],
            'class_level' => $r['class_level'],
            'is_translated' => $r['is_translated'],
            'job_tickets' => [],
            'daily_qty' => array_fill_keys($days_in_month, 0),
            'total_qty' => 0
        ];
    }

    // Add job ticket (compact)
    if (!in_array($r['job_ticket_code'], $data[$book_code]['job_tickets'])) {
        $data[$book_code]['job_tickets'][] = $r['job_ticket_code'];
    }

    // Add quantity
    $qty = (int)$r['p_qty'];
    $data[$book_code]['daily_qty'][$day] += $qty;
    $data[$book_code]['total_qty'] += $qty;

    // Update totals
    $total_per_day[$day] += $qty;
    $total_per_book[$book_code] = ($total_per_book[$book_code] ?? 0) + $qty;
}

// ========================
// FILTER OPTIONS
// ========================
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

// ========================
// FILTER DISPLAY TEXT
// ========================
$filter_display = [];
$filter_display[] = "महिना: {$selected_month}";

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
    const month = document.getElementById('nep_month').value;
    const status = document.getElementById('packing_status').value;
    const statusText = status === 'all' ? 'सबै' : 
                       status === 'active' ? 'सक्रिय' : 
                       status === 'completed' ? 'सम्पन्न' : 'बाँकी';

    let csv = "\uFEFF";
    csv += `"मासिक पुस्तक प्याकिङ दैनिक रिपोर्ट (Monthly Book Packing Daily Report)"
`;
    csv += `"महिना: ${month}"
`;
    csv += `"स्थिति: ${statusText}"
`;
    csv += "पुस्तक कोड,पुस्तकको नाम,कक्षा,प्रकार,जब टिकट," + 
           Array.from({length: 32}, (_, i) => `दिन ${i+1}`).join(",") + 
           ",जम्मा\n";

    document.querySelectorAll("#dailyReport tbody tr:not(.total-row)").forEach(tr => {
        const tds = tr.querySelectorAll("td");
        const rowData = Array.from(tds).slice(0, 5).map(td => `"${td.textContent.trim()}"`);
        const dailyData = Array.from(tds).slice(5, 37).map(td => td.textContent.trim() || "0");
        const total = tds[37].textContent.trim();
        csv += [...rowData, ...dailyData, `"${total}"`].join(",") + "\n";
    });

    // Total Row
    const totalTds = document.querySelector(".total-row").querySelectorAll("td");
    const totalData = Array.from(totalTds).slice(0, 5).map(() => "").concat(
        Array.from(totalTds).slice(5, 37).map(td => td.textContent.trim() || "0"),
        [totalTds[37].textContent.trim()]
    );
    csv += totalData.join(",") + "\n";

    const blob = new Blob([csv], { type: 'text/csv;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `monthly_packing_${month}.csv`;
    link.click();
    alert("CSV निर्यात गरियो!");
}
function exportToExcel() {
    const month = document.getElementById('nep_month').value;
    const status = document.getElementById('packing_status').value;
    const statusText = status === 'all' ? 'सबै' : 
                       status === 'active' ? 'सक्रिय' : 
                       status === 'completed' ? 'सम्पन्न' : 'बाँकी';

    let html = `<html xmlns:o="urn:schemas-microsoft-com:office:office" xmlns:x="urn:schemas-microsoft-com:office:excel" xmlns="http://www.w3.org/TR/REC-html40">
    <head><meta charset="UTF-8"><style>
        table { border-collapse: collapse; width: 100%; }
        th, td { border: 1px solid #000; padding: 8px; text-align: center; }
        th { background: #f2f2f2; }
        .translated { background: #e8f5e8; }
        .non-translated { background: #f0f8ff; }
    </style></head><body>
    <h2>मासिक पुस्तक प्याकिङ दैनिक रिपोर्ट</h2>
    <p><strong>महिना:</strong> ${month} | <strong>स्थिति:</strong> ${statusText}</p>
    <table><thead><tr>
        <th>पुस्तक कोड</th><th>पुस्तकको नाम</th><th>कक्षा</th><th>प्रकार</th><th>जब टिकटहरू</th>`;
    for (let i = 1; i <= 32; i++) html += `<th>${i}</th>`;
    html += `<th>जम्मा</th></tr></thead><tbody>`;

    document.querySelectorAll("#dailyReport tbody tr:not(.total-row)").forEach(tr => {
        const tds = tr.querySelectorAll("td");
        html += "<tr>";
        for (let i = 0; i < tds.length; i++) {
            const cell = tds[i];
            const cls = cell.classList.contains('translated') ? 'translated' : 
                        cell.classList.contains('non-translated') ? 'non-translated' : '';
            html += `<td class="${cls}">${cell.innerHTML}</td>`;
        }
        html += "</tr>";
    });

    html += `<tr class="total-row"><td colspan="5"><strong>जम्मा</strong></td>`;
    for (let i = 5; i <= 37; i++) {
        const val = document.querySelector(".total-row td:nth-child(" + (i+1) + ")").textContent;
        html += `<td><strong>${val}</strong></td>`;
    }
    html += `</tr></tbody></table></body></html>`;

    const blob = new Blob([html], { type: 'application/vnd.ms-excel;charset=utf-8;' });
    const link = document.createElement("a");
    link.href = URL.createObjectURL(blob);
    link.download = `monthly_packing_${month}.xls`;
    link.click();
    alert("Excel निर्यात गरियो!");
}
function printReport() {
    const month = document.getElementById('nep_month').value;
    const status = document.getElementById('packing_status').value;
    const statusText = status === 'all' ? 'सबै' : 
                       status === 'active' ? 'सक्रिय' : 
                       status === 'completed' ? 'सम्पन्न' : 'बाँकी';

    let printContent = `
    <html><head><title>मासिक पुस्तक प्याकिङ रिपोर्ट</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; }
        table { width: 100%; border-collapse: collapse; }
        th, td { border: 1px solid #000; padding: 6px; text-align: center; }
        th { background: #f0f0f0; }
        .signature { margin-top: 40px; }
    </style></head><body>
    <h2 style="text-align:center">जनक शिक्षा सामग्री केन्द्र लिमिटेड</h2>
    <h3 style="text-align:center">मासिक पुस्तक प्याकिङ दैनिक रिपोर्ट</h3>
    <p style="text-align:center"><strong>महिना:</strong> ${month} | <strong>स्थिति:</strong> ${statusText}</p>
    <table>
        <thead><tr>
            <th>कोड</th><th>नाम</th><th>कक्षा</th><th>प्रकार</th><th>जब टिकट</th>`;
    for (let i = 1; i <= 32; i++) printContent += `<th>${i}</th>`;
    printContent += `<th>जम्मा</th></tr></thead><tbody>`;

    document.querySelectorAll("#dailyReport tbody tr").forEach(tr => {
        printContent += `<tr>${tr.innerHTML}</tr>`;
    });

    printContent += `</tbody></table>
    <div class="signature" style="margin-top:40px;text-align:center">
        <p><strong>तयार गर्ने:</strong> _______________ &nbsp;&nbsp;&nbsp;
        <strong>जाँच गर्ने:</strong> _______________</p>
    </div>
    </body></html>`;

    const w = window.open();
    w.document.write(printContent);
    w.document.close();
    w.onload = () => w.print();
}
</script>

<h2>मासिक पुस्तक प्याकिङ दैनिक रिपोर्ट (Monthly Daily Packing Report)<?= $filter_text ?></h2>

<form method="get" class="report-filter">
    <div class="filter-row">
        <div class="filter-group">
            <label for="nep_month">नेपाली महिना (YYYY.MM):</label>
            <input type="month" name="nep_month" id="nep_month"
                   value="<?= htmlspecialchars($selected_month) ?>"
                   placeholder="2081.04">
        </div>
        <div class="filter-group">
            <label for="packing_status">प्याकिङ स्थिति:</label>
            <select name="packing_status" id="packing_status">
                <option value="all" <?= $packing_status_filter === 'all' ? 'selected' : '' ?>>सबै</option>
                <option value="active" <?= $packing_status_filter === 'active' ? 'selected' : '' ?>>सक्रिय</option>
                <option value="completed" <?= $packing_status_filter === 'completed' ? 'selected' : '' ?>>सम्पन्न</option>
                <option value="pending" <?= $packing_status_filter === 'pending' ? 'selected' : '' ?>>बाँकी</option>
            </select>
        </div>
        <div class="filter-group">
            <label for="book_code">पुस्तक कोड:</label>
            <select name="book_code" id="book_code">
                <option value="">सबै</option>
                <?php foreach ($book_codes as $b): ?>
                    <option value="<?= $b['book_code'] ?>" <?= $book_code_filter == $b['book_code'] ? 'selected' : '' ?>>
                        <?= $b['book_code'] ?> - <?= $b['book_name'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="filter-row">
        <div class="filter-group">
            <label for="fiscal_year_id">आर्थिक वर्ष:</label>
            <select name="fiscal_year_id" id="fiscal_year_id">
                <option value="">सबै</option>
                <?php foreach ($fiscal_years as $fy): ?>
                    <option value="<?= $fy['id'] ?>" <?= $fiscal_year_filter == $fy['id'] ? 'selected' : '' ?>>
                        <?= $fy['fiscal_code'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="supervisor_id">सुपरभाइजर:</label>
            <select name="supervisor_id" id="supervisor_id">
                <option value="">सबै</option>
                <?php foreach ($supervisors as $s): ?>
                    <option value="<?= $s['id'] ?>" <?= $supervisor_filter == $s['id'] ? 'selected' : '' ?>>
                        <?= $s['username'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="filter-group">
            <label for="class_level">कक्षा:</label>
            <select name="class_level" id="class_level">
                <option value="">सबै</option>
                <?php foreach ($class_levels as $cl): ?>
                    <option value="<?= $cl['class_level'] ?>" <?= $class_level_filter == $cl['class_level'] ? 'selected' : '' ?>>
                        <?= $cl['class_level'] ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>
    <div class="filter-row">
        <div class="filter-group">
            <button type="submit">रिपोर्ट तयार गर्नुहोस्</button>
        </div>
        <div class="filter-group">
            <a href="?" class="btn-clear">फिल्टर क्लियर गर्नुहोस्</a>
        </div>
    </div>
</form>

<div class="report-summary">
    <p><strong>छानिएको महिना:</strong> <?= htmlspecialchars($selected_month) ?></p>
    <p><strong>फिल्टर:</strong> <?= $filter_text ?></p>
    <p><strong>पुस्तकहरूको संख्या:</strong> <?= count($data) ?></p>
    <p><strong>जम्मा प्याक मात्रा:</strong> <?= number_format(array_sum($total_per_day)) ?></p>
</div>

<?php if (empty($data)): ?>
    <div class="no-data">
        <p>यस महिनाको लागि कुनै प्याकिङ डाटा फेला परेन।</p>
    </div>
<?php else: ?>
    <table id="dailyReport">
        <thead>
            <tr>
                <th>पुस्तक कोड</th>
                <th>पुस्तकको नाम</th>
                <th>कक्षा</th>
                <th>प्रकार</th>
                <th>जब टिकटहरू</th>
                <?php for ($d = 1; $d <= 32; $d++): ?>
                    <th><?= $d ?></th>
                <?php endfor; ?>
                <th>जम्मा</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($data as $code => $row): ?>
            <tr>
                <td><?= htmlspecialchars($code) ?></td>
                <td><?= htmlspecialchars($row['book_name']) ?></td>
                <td><?= htmlspecialchars($row['class_level']) ?></td>
                <td class="book-type <?= $row['is_translated'] ? 'translated' : 'non-translated' ?>">
                    <?= $row['is_translated'] ? 'अनुवादित' : 'गैर-अनुवादित' ?>
                </td>
                <td title="<?= htmlspecialchars(implode(', ', $row['job_tickets'])) ?>">
                    <?= count($row['job_tickets']) > 1 ? 
                       htmlspecialchars($row['job_tickets'][0] . ' + ' . (count($row['job_tickets']) - 1)) : 
                       (isset($row['job_tickets'][0]) ? htmlspecialchars($row['job_tickets'][0]) : '') ?>
                </td>
                <?php for ($d = 1; $d <= 32; $d++): ?>
                    <td><?= $row['daily_qty'][$d] > 0 ? number_format($row['daily_qty'][$d]) : '' ?></td>
                <?php endfor; ?>
                <td><strong><?= number_format($row['total_qty']) ?></strong></td>
            </tr>
            <?php endforeach; ?>

            <!-- Total Row -->
            <tr class="total-row">
                <td colspan="5"><strong>जम्मा (Total)</strong></td>
                <?php for ($d = 1; $d <= 32; $d++): ?>
                    <td><strong><?= $total_per_day[$d] > 0 ? number_format($total_per_day[$d]) : '' ?></strong></td>
                <?php endfor; ?>
                <td><strong><?= number_format(array_sum($total_per_day)) ?></strong></td>
            </tr>
        </tbody>
    </table>

    <div class="print-actions">
        <button onclick="printReport()" class="btn-print">🖨️ छाप्नुहोस्</button>
        <button onclick="exportToCSV()" class="btn-export">📊 CSV मा निर्यात गर्नुहोस्</button>
        <button onclick="exportToExcel()" class="btn-excel">📋 Excel मा निर्यात गर्नुहोस्</button>
        <a href="index.php" class="btn-back">🔙 फर्कनुहोस्</a>
    </div>
<?php endif; ?>

<style>
    /* Reuse original styles or add compact table styling */
    #dailyReport {
        font-size: 12px;
        table-layout: fixed;
        width: 100%;
        overflow: auto;
    }
    #dailyReport th, #dailyReport td {
        padding: 5px;
    }
    #dailyReport th:nth-child(n+6):nth-child(-n+37) {
        width: 25px;
    }
    .btn-print, .btn-export, .btn-excel, .btn-back {
        padding: 10px 16px;
        margin: 5px;
        border: none;
        border-radius: 5px;
        font-size: 14px;
    }
</style>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
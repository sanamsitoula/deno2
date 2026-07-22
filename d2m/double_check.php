<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$d2m_id = $_GET['id'] ?? null;

if (!$d2m_id) {
    header("Location: index.php");
    exit;
}

// Fetch D2M record
$stmt = $conn->prepare("
    SELECT d.*, 
           fy.fiscal_code as fiscal_year_name
    FROM d2m d 
    LEFT JOIN fiscal_years fy ON d.fiscal_year_id = fy.id
    WHERE d.id = :id AND d.deleted_at IS NULL
");
$stmt->execute([':id' => $d2m_id]);
$d2m = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$d2m) {
    header("Location: index.php");
    exit;
}

// Fetch D2M items with DENO verification
$items_stmt = $conn->prepare("
    SELECT 
        di.*,
        b.book_name,
        b.class_level,
        b.is_translated,
        di.associated_deno_ids,
        (
            SELECT COUNT(*)
            FROM unnest(string_to_array(di.associated_deno_ids, ',')) AS deno_id
            WHERE deno_id != ''
        ) as deno_count
    FROM d2m_items di
    JOIN books b ON di.book_code = b.book_code
    WHERE di.d2m_id = :d2m_id
    ORDER BY b.book_name
");
$items_stmt->execute([':d2m_id' => $d2m_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Verify each item against actual DENO records
$verification_results = [];
$discrepancies_found = false;

foreach ($items as $item) {
    $deno_ids = array_filter(explode(',', $item['associated_deno_ids'] ?? ''));
    
    if (empty($deno_ids)) {
        $verification_results[$item['id']] = [
            'status' => 'error',
            'message' => 'No DENO records associated',
            'deno_details' => []
        ];
        $discrepancies_found = true;
        continue;
    }

    // Fetch actual DENO records
    $placeholders = implode(',', array_fill(0, count($deno_ids), '?'));
    $deno_stmt = $conn->prepare("
        SELECT 
            d.id,
            d.ref_no,
            d.book_code,
            d.per_poka_qty,
            d.poka_qty,
            d.total_qty,
            d.quantity_openpcs,
            d.deno_date_nep,
            d.created_by as created_by_name
        FROM deno d
        WHERE d.id IN ($placeholders)
        ORDER BY d.ref_no
    ");
    $deno_stmt->execute($deno_ids);
    $deno_records = $deno_stmt->fetchAll(PDO::FETCH_ASSOC);

    // Calculate DENO totals
    $deno_total_poka = array_sum(array_column($deno_records, 'poka_qty'));
    $deno_total_qty = array_sum(array_column($deno_records, 'total_qty'));
    $deno_total_open = array_sum(array_column($deno_records, 'quantity_openpcs'));

    // Compare with D2M item
    $poka_match = ($item['total_poka_qty'] == $deno_total_poka);
    $qty_match = ($item['total_qty'] == $deno_total_qty);
    $open_match = ($item['open_pcs'] == $deno_total_open);

    $all_match = $poka_match && $qty_match && $open_match;

    $verification_results[$item['id']] = [
        'status' => $all_match ? 'success' : 'warning',
        'message' => $all_match ? 'All quantities match' : 'Discrepancy found',
        'deno_details' => $deno_records,
        'comparison' => [
            'poka' => [
                'd2m' => $item['total_poka_qty'],
                'deno' => $deno_total_poka,
                'match' => $poka_match
            ],
            'qty' => [
                'd2m' => $item['total_qty'],
                'deno' => $deno_total_qty,
                'match' => $qty_match
            ],
            'open' => [
                'd2m' => $item['open_pcs'],
                'deno' => $deno_total_open,
                'match' => $open_match
            ]
        ]
    ];

    if (!$all_match) {
        $discrepancies_found = true;
    }
}

// Calculate overall statistics
$total_items = count($items);
$verified_items = count(array_filter($verification_results, fn($r) => $r['status'] === 'success'));
$warning_items = count(array_filter($verification_results, fn($r) => $r['status'] === 'warning'));
$error_items = count(array_filter($verification_results, fn($r) => $r['status'] === 'error'));
?>

<style>
.check-container {
    max-width: 1600px;
    margin: 20px auto;
    background: white;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.check-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 10px 10px 0 0;
}

.check-header h2 {
    margin: 0 0 10px 0;
}

.summary-section {
    padding: 30px;
    background: #f8f9fa;
    border-bottom: 2px solid #e9ecef;
}

.summary-cards {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 20px;
}

.summary-card {
    background: white;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    border: 2px solid #e9ecef;
}

.summary-card.success {
    border-color: #28a745;
    background: #d4edda;
}

.summary-card.warning {
    border-color: #ffc107;
    background: #fff3cd;
}

.summary-card.error {
    border-color: #dc3545;
    background: #f8d7da;
}

.summary-card .value {
    font-size: 36px;
    font-weight: bold;
    margin: 10px 0;
}

.summary-card .label {
    font-size: 12px;
    text-transform: uppercase;
    color: #6c757d;
}

.overall-status {
    margin-top: 20px;
    padding: 20px;
    border-radius: 8px;
    text-align: center;
    font-size: 18px;
    font-weight: bold;
}

.overall-status.pass {
    background: #d4edda;
    color: #155724;
    border: 2px solid #28a745;
}

.overall-status.fail {
    background: #f8d7da;
    color: #721c24;
    border: 2px solid #dc3545;
}

.verification-section {
    padding: 30px;
}

.item-verification {
    margin-bottom: 30px;
    border: 2px solid #e9ecef;
    border-radius: 8px;
    overflow: hidden;
}

.item-header {
    padding: 15px 20px;
    background: #f8f9fa;
    display: flex;
    justify-content: space-between;
    align-items: center;
    cursor: pointer;
    transition: background 0.3s ease;
}

.item-header:hover {
    background: #e9ecef;
}

.item-header.success {
    background: #d4edda;
    border-left: 5px solid #28a745;
}

.item-header.warning {
    background: #fff3cd;
    border-left: 5px solid #ffc107;
}

.item-header.error {
    background: #f8d7da;
    border-left: 5px solid #dc3545;
}

.item-title {
    flex: 1;
}

.item-title h4 {
    margin: 0 0 5px 0;
    color: #333;
}

.item-title .meta {
    font-size: 13px;
    color: #6c757d;
}

.status-badge {
    padding: 8px 16px;
    border-radius: 20px;
    font-size: 12px;
    font-weight: bold;
    text-transform: uppercase;
}

.status-success {
    background: #28a745;
    color: white;
}

.status-warning {
    background: #ffc107;
    color: #212529;
}

.status-error {
    background: #dc3545;
    color: white;
}

.item-details {
    padding: 20px;
    background: white;
    display: none;
}

.item-details.active {
    display: block;
}

.comparison-table {
    width: 100%;
    border-collapse: collapse;
    margin-bottom: 20px;
}

.comparison-table th,
.comparison-table td {
    padding: 12px;
    text-align: left;
    border: 1px solid #dee2e6;
}

.comparison-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 13px;
}

.comparison-table .match {
    background: #d4edda;
}

.comparison-table .mismatch {
    background: #f8d7da;
    font-weight: bold;
}

.deno-records {
    margin-top: 20px;
}

.deno-record {
    background: #f8f9fa;
    padding: 15px;
    border-left: 4px solid #17a2b8;
    margin-bottom: 10px;
    border-radius: 4px;
}

.deno-record-header {
    display: flex;
    justify-content: space-between;
    margin-bottom: 10px;
}

.deno-badge {
    background: #17a2b8;
    color: white;
    padding: 4px 8px;
    border-radius: 4px;
    font-size: 11px;
    font-weight: bold;
}

.deno-values {
    display: grid;
    grid-template-columns: repeat(4, 1fr);
    gap: 10px;
    font-size: 13px;
}

.action-bar {
    display: flex;
    justify-content: space-between;
    padding: 20px 30px;
    background: #f8f9fa;
    border-top: 2px solid #e9ecef;
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 5px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-block;
    transition: all 0.3s ease;
    margin-right: 10px;
}

.btn-primary { background: #007bff; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-success { background: #28a745; color: white; }

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.expand-all-btn {
    background: #17a2b8;
    color: white;
}
</style>

<div class="check-container">
    <div class="check-header">
        <h2>🔍 D2M Double Check Verification</h2>
        <div style="font-size: 16px; opacity: 0.95;">
            D2M Number: <?= htmlspecialchars($d2m['d2m_no']) ?> | 
            Date: <?= htmlspecialchars($d2m['nep_date']) ?>
        </div>
    </div>

    <div class="summary-section">
        <h3 style="margin-bottom: 20px;">📊 Verification Summary</h3>
        
        <div class="summary-cards">
            <div class="summary-card">
                <div class="label">Total Items</div>
                <div class="value"><?= $total_items ?></div>
            </div>
            <div class="summary-card success">
                <div class="label">✓ Verified</div>
                <div class="value" style="color: #28a745;"><?= $verified_items ?></div>
            </div>
            <div class="summary-card warning">
                <div class="label">⚠ Discrepancies</div>
                <div class="value" style="color: #ffc107;"><?= $warning_items ?></div>
            </div>
            <div class="summary-card error">
                <div class="label">✗ Errors</div>
                <div class="value" style="color: #dc3545;"><?= $error_items ?></div>
            </div>
        </div>

        <div class="overall-status <?= $discrepancies_found ? 'fail' : 'pass' ?>">
            <?php if ($discrepancies_found): ?>
                ⚠️ DISCREPANCIES FOUND - Review items below
            <?php else: ?>
                ✅ ALL ITEMS VERIFIED - No discrepancies found
            <?php endif; ?>
        </div>
    </div>

    <div class="verification-section">
        <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
            <h3>📋 Item-by-Item Verification</h3>
            <button class="btn expand-all-btn" onclick="toggleAllItems()">
                <span id="toggleText">Expand All</span>
            </button>
        </div>

        <?php foreach ($items as $index => $item): 
            $result = $verification_results[$item['id']];
            $status_class = $result['status'];
        ?>
        <div class="item-verification">
            <div class="item-header <?= $status_class ?>" onclick="toggleItem(<?= $item['id'] ?>)">
                <div class="item-title">
                    <h4><?= ($index + 1) ?>. <?= htmlspecialchars($item['book_name']) ?></h4>
                    <div class="meta">
                        Code: <?= htmlspecialchars($item['book_code']) ?> | 
                        Class: <?= htmlspecialchars($item['class_level']) ?> | 
                        DENO Records: <?= $item['deno_count'] ?>
                    </div>
                </div>
                <div>
                    <span class="status-badge status-<?= $status_class ?>">
                        <?= $result['message'] ?>
                    </span>
                </div>
            </div>

            <div class="item-details" id="details-<?= $item['id'] ?>">
                <h5>Quantity Comparison</h5>
                <table class="comparison-table">
                    <thead>
                        <tr>
                            <th>Metric</th>
                            <th>D2M Record</th>
                            <th>DENO Total</th>
                            <th>Difference</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (isset($result['comparison'])): ?>
                        <tr class="<?= $result['comparison']['poka']['match'] ? 'match' : 'mismatch' ?>">
                            <td><strong>Poka Quantity</strong></td>
                            <td><?= number_format($result['comparison']['poka']['d2m']) ?></td>
                            <td><?= number_format($result['comparison']['poka']['deno']) ?></td>
                            <td><?= number_format($result['comparison']['poka']['d2m'] - $result['comparison']['poka']['deno']) ?></td>
                            <td><?= $result['comparison']['poka']['match'] ? '✓ Match' : '✗ Mismatch' ?></td>
                        </tr>
                        <tr class="<?= $result['comparison']['qty']['match'] ? 'match' : 'mismatch' ?>">
                            <td><strong>Total Quantity</strong></td>
                            <td><?= number_format($result['comparison']['qty']['d2m']) ?></td>
                            <td><?= number_format($result['comparison']['qty']['deno']) ?></td>
                            <td><?= number_format($result['comparison']['qty']['d2m'] - $result['comparison']['qty']['deno']) ?></td>
                            <td><?= $result['comparison']['qty']['match'] ? '✓ Match' : '✗ Mismatch' ?></td>
                        </tr>
                        <tr class="<?= $result['comparison']['open']['match'] ? 'match' : 'mismatch' ?>">
                            <td><strong>Open Pieces</strong></td>
                            <td><?= number_format($result['comparison']['open']['d2m']) ?></td>
                            <td><?= number_format($result['comparison']['open']['deno']) ?></td>
                            <td><?= number_format($result['comparison']['open']['d2m'] - $result['comparison']['open']['deno']) ?></td>
                            <td><?= $result['comparison']['open']['match'] ? '✓ Match' : '✗ Mismatch' ?></td>
                        </tr>
                        <?php endif; ?>
                    </tbody>
                </table>

                <?php if (!empty($result['deno_details'])): ?>
                <div class="deno-records">
                    <h5>Associated DENO Records (<?= count($result['deno_details']) ?>)</h5>
                    <?php foreach ($result['deno_details'] as $deno): ?>
                    <div class="deno-record">
                        <div class="deno-record-header">
                            <div>
                                <span class="deno-badge">DENO #<?= $deno['id'] ?></span>
                                <strong>Ref: <?= htmlspecialchars($deno['ref_no']) ?></strong>
                            </div>
                            <div style="font-size: 12px; color: #6c757d;">
                                Date: <?= htmlspecialchars($deno['deno_date_nep']) ?> | 
                                By: <?= htmlspecialchars($deno['created_by_name'] ?? 'N/A') ?>
                            </div>
                        </div>
                        <div class="deno-values">
                            <div>
                                <strong>Per Poka:</strong><br>
                                <?= number_format($deno['per_poka_qty']) ?>
                            </div>
                            <div>
                                <strong>Poka Qty:</strong><br>
                                <?= number_format($deno['poka_qty']) ?>
                            </div>
                            <div>
                                <strong>Total Qty:</strong><br>
                                <?= number_format($deno['total_qty']) ?>
                            </div>
                            <div>
                                <strong>Open Pcs:</strong><br>
                                <?= number_format($deno['quantity_openpcs']) ?>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endforeach; ?>
    </div>

    <div class="action-bar">
        <div>
            <a href="view.php?id=<?= $d2m_id ?>" class="btn btn-secondary">
                <i class="fas fa-arrow-left"></i> Back to View
            </a>
            <a href="index.php" class="btn btn-secondary">
                <i class="fas fa-list"></i> Back to List
            </a>
        </div>
        <div>
            <button onclick="window.print()" class="btn btn-primary">
                <i class="fas fa-print"></i> Print Verification
            </button>
            <?php if (!$discrepancies_found): ?>
            <button class="btn btn-success" onclick="alert('All items verified successfully!')">
                <i class="fas fa-check-circle"></i> Verification Complete
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
function toggleItem(itemId) {
    const details = document.getElementById('details-' + itemId);
    details.classList.toggle('active');
}

function toggleAllItems() {
    const allDetails = document.querySelectorAll('.item-details');
    const toggleText = document.getElementById('toggleText');
    const anyExpanded = Array.from(allDetails).some(d => d.classList.contains('active'));
    
    allDetails.forEach(detail => {
        if (anyExpanded) {
            detail.classList.remove('active');
        } else {
            detail.classList.add('active');
        }
    });
    
    toggleText.textContent = anyExpanded ? 'Expand All' : 'Collapse All';
}

// Auto-expand items with discrepancies
document.addEventListener('DOMContentLoaded', function() {
    const warningItems = document.querySelectorAll('.item-header.warning, .item-header.error');
    warningItems.forEach(item => {
        const itemId = item.parentElement.querySelector('.item-details').id.replace('details-', '');
        toggleItem(itemId);
    });
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
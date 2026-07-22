<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Check permissions
if (!has_role('editor') && !has_role('admin')) {
    header("Location: index.php");
    exit;
}

$d2m_id = $_GET['id'] ?? null;
$message = '';
$error = '';

if (!$d2m_id) {
    header("Location: index.php");
    exit;
}

// Fetch D2M record
$stmt = $conn->prepare("
    SELECT d.*, 
           fy.fiscal_code as fiscal_year_name,
           fy.id as fiscal_year_id
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

// Only allow editing DRAFT status
if ($d2m['status'] != 'DRAFT') {
    $_SESSION['error'] = "Only DRAFT D2M records can be edited.";
    header("Location: view.php?id=$d2m_id");
    exit;
}

// Fetch D2M items
$items_stmt = $conn->prepare("
    SELECT di.*, 
           b.book_name, 
           b.class_level, 
           b.is_translated
    FROM d2m_items di
    JOIN books b ON di.book_code = b.book_code
    WHERE di.d2m_id = :d2m_id
    ORDER BY b.book_name
");
$items_stmt->execute([':d2m_id' => $d2m_id]);
$items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();

        $nep_date = $_POST['nep_date'];
        $eng_date = $_POST['eng_date'];
        $remarks = $_POST['remarks'] ?? '';
        $user_id = $_SESSION['user_id'];

        // Update D2M record
        $update_stmt = $conn->prepare("
            UPDATE d2m 
            SET nep_date = :nep_date,
                eng_date = :eng_date,
                remarks = :remarks,
                updated_by = :user_id,
                updated_at = NOW()
            WHERE id = :id AND status = 'DRAFT'
        ");
        $update_stmt->execute([
            ':nep_date' => $nep_date,
            ':eng_date' => $eng_date,
            ':remarks' => $remarks,
            ':user_id' => $user_id,
            ':id' => $d2m_id
        ]);

        // Update D2M items
        if (isset($_POST['items']) && is_array($_POST['items'])) {
            $update_item_stmt = $conn->prepare("
                UPDATE d2m_items 
                SET per_poka_qty = :per_poka,
                    total_poka_qty = :total_poka,
                    total_qty = :total_qty,
                    open_pcs = :open_pcs
                WHERE id = :id AND d2m_id = :d2m_id
            ");

            foreach ($_POST['items'] as $item_id => $item_data) {
                $per_poka = (int)$item_data['per_poka_qty'];
                $total_poka = (int)$item_data['total_poka_qty'];
                $open_pcs = (int)$item_data['open_pcs'];
                $total_qty = ($per_poka * $total_poka);

                $update_item_stmt->execute([
                    ':per_poka' => $per_poka,
                    ':total_poka' => $total_poka,
                    ':total_qty' => $total_qty,
                    ':open_pcs' => $open_pcs,
                    ':id' => $item_id,
                    ':d2m_id' => $d2m_id
                ]);
            }
        }

        $conn->commit();
        $message = "D2M record updated successfully!";
        
        // Refresh data
        $stmt->execute([':id' => $d2m_id]);
        $d2m = $stmt->fetch(PDO::FETCH_ASSOC);
        $items_stmt->execute([':d2m_id' => $d2m_id]);
        $items = $items_stmt->fetchAll(PDO::FETCH_ASSOC);

    } catch (Exception $e) {
        $conn->rollBack();
        $error = "Error updating D2M: " . $e->getMessage();
    }
}
?>

<style>
.edit-container {
    max-width: 1400px;
    margin: 20px auto;
    background: white;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.edit-header {
    background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
    color: white;
    padding: 30px;
    border-radius: 10px 10px 0 0;
}

.edit-header h2 {
    margin: 0 0 10px 0;
}

.form-section {
    padding: 30px;
}

.section-title {
    font-size: 18px;
    font-weight: 600;
    color: #333;
    margin-bottom: 20px;
    padding-bottom: 10px;
    border-bottom: 2px solid #e9ecef;
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(300px, 1fr));
    gap: 20px;
    margin-bottom: 30px;
}

.form-group {
    margin-bottom: 0;
}

.form-label {
    font-weight: 600;
    color: #495057;
    margin-bottom: 8px;
    display: block;
    font-size: 14px;
}

.form-label .required {
    color: #dc3545;
}

.form-control {
    width: 100%;
    padding: 10px 12px;
    border: 2px solid #e9ecef;
    border-radius: 5px;
    font-size: 14px;
    transition: border-color 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #007bff;
    box-shadow: 0 0 0 3px rgba(0,123,255,.1);
}

.items-table-container {
    margin-top: 30px;
    border: 1px solid #dee2e6;
    border-radius: 8px;
    overflow: hidden;
}

.items-table {
    width: 100%;
    border-collapse: collapse;
}

.items-table th,
.items-table td {
    padding: 12px;
    text-align: left;
    border-bottom: 1px solid #dee2e6;
}

.items-table th {
    background: #f8f9fa;
    font-weight: 600;
    font-size: 13px;
    color: #495057;
}

.items-table tbody tr:hover {
    background: #f8f9fa;
}

.items-table input[type="number"] {
    width: 100px;
    padding: 6px 8px;
    border: 1px solid #ced4da;
    border-radius: 4px;
    font-size: 13px;
}

.items-table input[type="number"]:focus {
    outline: none;
    border-color: #007bff;
}

.calculated-value {
    background: #e8f4f8;
    padding: 6px 12px;
    border-radius: 4px;
    font-weight: 600;
    display: inline-block;
}

.totals-row {
    background: #e8f4f8 !important;
    font-weight: bold;
}

.totals-row td {
    border-top: 2px solid #007bff !important;
    padding: 15px 12px;
}

.action-buttons {
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
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-danger { background: #dc3545; color: white; }

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

.alert {
    padding: 15px 20px;
    border-radius: 5px;
    margin: 20px 30px;
    font-weight: 500;
}

.alert-success {
    background: #d4edda;
    border: 1px solid #c3e6cb;
    color: #155724;
}

.alert-danger {
    background: #f8d7da;
    border: 1px solid #f5c6cb;
    color: #721c24;
}

.info-badge {
    background: #d1ecf1;
    padding: 10px 15px;
    border-radius: 5px;
    font-size: 13px;
    color: #0c5460;
    margin-bottom: 20px;
}
</style>

<div class="edit-container">
    <div class="edit-header">
        <h2>✏️ Edit D2M Record</h2>
        <div style="font-size: 16px; opacity: 0.95;">
            D2M Number: <?= htmlspecialchars($d2m['d2m_no']) ?>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="alert alert-success">
            ✓ <?= $message ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="alert alert-danger">
            ⚠️ <?= $error ?>
        </div>
    <?php endif; ?>

    <form method="POST" id="editForm">
        <div class="form-section">
            <div class="section-title">📋 Basic Information</div>
            
            <div class="info-badge">
                <strong>Note:</strong> D2M Number, Serial, Type, and Fiscal Year cannot be changed after creation.
            </div>

            <div class="form-grid">
                <div class="form-group">
                    <label class="form-label">D2M Number</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($d2m['d2m_no']) ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Serial Number</label>
                    <input type="text" class="form-control" value="<?= $d2m['serial_no'] ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Type</label>
                    <input type="text" class="form-control" 
                           value="<?= $d2m['d2m_type'] == 'T' ? 'Translated' : 'Non-Translated' ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">Fiscal Year</label>
                    <input type="text" class="form-control" value="<?= htmlspecialchars($d2m['fiscal_year_name']) ?>" readonly>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        Nepali Date <span class="required">*</span>
                    </label>
                    <input type="text" 
                           name="nep_date" 
                           id="nep_date"
                           class="form-control" 
                           pattern="\d{4}\.\d{2}\.\d{2}"
                           placeholder="2082.09.03"
                           value="<?= htmlspecialchars($d2m['nep_date']) ?>"
                           required>
                    <small style="color: #6c757d;">Format: YYYY.MM.DD</small>
                </div>

                <div class="form-group">
                    <label class="form-label">
                        English Date <span class="required">*</span>
                    </label>
                    <input type="date" 
                           name="eng_date" 
                           class="form-control" 
                           value="<?= $d2m['eng_date'] ?>"
                           required>
                </div>
            </div>

            <div class="form-group">
                <label class="form-label">Remarks</label>
                <textarea name="remarks" 
                          class="form-control" 
                          rows="3" 
                          placeholder="Enter any remarks or notes..."><?= htmlspecialchars($d2m['remarks'] ?? '') ?></textarea>
            </div>
        </div>

        <div class="form-section" style="padding-top: 0;">
            <div class="section-title">📚 D2M Items (<?= count($items) ?> items)</div>
            
            <div class="info-badge">
                <strong>Instructions:</strong> Update quantities as needed. Total Qty is automatically calculated (Per Poka × Poka Qty).
            </div>

            <div class="items-table-container">
                <table class="items-table">
                    <thead>
                        <tr>
                            <th style="width: 4%;">S.N.</th>
                            <th style="width: 25%;">Book Name</th>
                            <th style="width: 8%;">Class</th>
                            <th style="width: 10%;">Book Code</th>
                            <th style="width: 12%;">Per Poka Qty</th>
                            <th style="width: 12%;">Poka Qty</th>
                            <th style="width: 12%;">Total Qty</th>
                            <th style="width: 12%;">Open Pcs</th>
                        </tr>
                    </thead>
                    <tbody id="itemsTableBody">
                        <?php 
                        $sn = 1;
                        foreach ($items as $item): 
                        ?>
                        <tr data-item-id="<?= $item['id'] ?>">
                            <td><?= $sn++ ?></td>
                            <td><strong><?= htmlspecialchars($item['book_name']) ?></strong></td>
                            <td><?= htmlspecialchars($item['class_level']) ?></td>
                            <td><?= htmlspecialchars($item['book_code']) ?></td>
                            <td>
                                <input type="number" 
                                       name="items[<?= $item['id'] ?>][per_poka_qty]"
                                       class="per-poka-input"
                                       value="<?= $item['per_poka_qty'] ?>"
                                       min="1"
                                       required>
                            </td>
                            <td>
                                <input type="number" 
                                       name="items[<?= $item['id'] ?>][total_poka_qty]"
                                       class="poka-qty-input"
                                       value="<?= $item['total_poka_qty'] ?>"
                                       min="0"
                                       required>
                            </td>
                            <td>
                                <span class="calculated-value total-qty-display">
                                    <?= number_format($item['total_qty']) ?>
                                </span>
                            </td>
                            <td>
                                <input type="number" 
                                       name="items[<?= $item['id'] ?>][open_pcs]"
                                       value="<?= $item['open_pcs'] ?>"
                                       min="0"
                                       required>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        
                        <tr class="totals-row">
                            <td colspan="5"><strong>GRAND TOTAL</strong></td>
                            <td><strong id="totalPoka">0</strong></td>
                            <td><strong id="totalQty">0</strong></td>
                            <td><strong id="totalOpenPcs">0</strong></td>
                        </tr>
                    </tbody>
                </table>
            </div>
        </div>

        <div class="action-buttons">
            <div>
                <a href="view.php?id=<?= $d2m_id ?>" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </a>
            </div>
            <div>
                <button type="submit" class="btn btn-success">
                    <i class="fas fa-save"></i> Save Changes
                </button>
            </div>
        </div>
    </form>
</div>

<script>
// Auto-calculate total quantities
function calculateTotals() {
    let totalPoka = 0;
    let totalQty = 0;
    let totalOpenPcs = 0;

    document.querySelectorAll('#itemsTableBody tr:not(.totals-row)').forEach(row => {
        const perPoka = parseFloat(row.querySelector('.per-poka-input').value) || 0;
        const pokaQty = parseFloat(row.querySelector('.poka-qty-input').value) || 0;
        const openPcs = parseFloat(row.querySelector('input[name*="open_pcs"]').value) || 0;
        
        const calculatedTotal = perPoka * pokaQty;
        row.querySelector('.total-qty-display').textContent = calculatedTotal.toLocaleString();
        
        totalPoka += pokaQty;
        totalQty += calculatedTotal;
        totalOpenPcs += openPcs;
    });

    document.getElementById('totalPoka').textContent = totalPoka.toLocaleString();
    document.getElementById('totalQty').textContent = totalQty.toLocaleString();
    document.getElementById('totalOpenPcs').textContent = totalOpenPcs.toLocaleString();
}

// Add event listeners to all input fields
document.addEventListener('DOMContentLoaded', function() {
    // Calculate totals on page load
    calculateTotals();

    // Add listeners to all quantity inputs
    document.querySelectorAll('.per-poka-input, .poka-qty-input, input[name*="open_pcs"]').forEach(input => {
        input.addEventListener('input', calculateTotals);
    });
});

// Date formatting for Nepali date
document.getElementById('nep_date').addEventListener('input', function() {
    let value = this.value.replace(/[^\d]/g, '');
    if (value.length >= 4) {
        value = value.substring(0, 4) + '.' + value.substring(4);
    }
    if (value.length >= 7) {
        value = value.substring(0, 7) + '.' + value.substring(7);
    }
    if (value.length > 10) {
        value = value.substring(0, 10);
    }
    this.value = value;
});

// Form validation
document.getElementById('editForm').addEventListener('submit', function(e) {
    const nepDate = document.getElementById('nep_date').value;
    
    if (!/^\d{4}\.\d{2}\.\d{2}$/.test(nepDate)) {
        e.preventDefault();
        alert('Please enter Nepali date in correct format (YYYY.MM.DD)');
        return false;
    }

    // Confirm submission
    if (!confirm('Are you sure you want to save these changes?')) {
        e.preventDefault();
        return false;
    }
});
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
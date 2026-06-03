<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

if (!isset($_SESSION['username'])) {
    header("Location: ../login.php");
    exit;
}

$message = "";
$error = "";
$preview_data = null;

/* ===============================
   PREVIEW DENO RECORDS
================================ */
if (isset($_GET['preview'])) {
    $nep_date = $_GET['nep_date'] ?? '';
    $type = $_GET['type'] ?? '';
    
    if ($nep_date && $type) {
        $translated = ($type === 'T') ? 'true' : 'false';
        
        $previewStmt = $conn->prepare("
            SELECT 
                d.id as deno_id,
                d.book_code,
                b.book_name,
                b.class_level,
                d.ref_no,
                d.per_poka_qty,
                d.poka_qty,
                d.total_qty,
                d.quantity_openpcs,
                d.created_by
            FROM deno d
            JOIN books b ON b.book_code = d.book_code
            WHERE d.deno_date_nep = :nep
              AND b.is_translated = :translated
            ORDER BY b.book_name, d.ref_no
        ");
        $previewStmt->execute([
            ':nep' => $nep_date,
            ':translated' => $translated
        ]);
        $preview_data = $previewStmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

/* ===============================
   HANDLE FORM SUBMIT
================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $nep_date = $_POST['nep_date'];
    $eng_date = $_POST['eng_date'];
    $type     = $_POST['type']; // T or NT
    $user_id  = $_SESSION['user_id'];
    $user     = $_SESSION['username'];

    try {
        $conn->beginTransaction();

        /* 1️⃣ Get Active Fiscal Year */
        $fyStmt = $conn->query("
           SELECT id FROM fiscal_years WHERE is_active = true LIMIT 1
        ");
        $fiscal_year = $fyStmt->fetchColumn();

        $fyStmt = $conn->query("
           SELECT fiscal_code FROM fiscal_years WHERE is_active = true LIMIT 1
        ");
        $fiscal_code = $fyStmt->fetchColumn();

        if (!$fiscal_year) {
            throw new Exception("Active fiscal year not found.");
        }

        if (!$fiscal_code) {
            throw new Exception("Active fiscal year code not found.");
        }

        /* 2️⃣ Check Existing D2M (same date + type) */
        $checkStmt = $conn->prepare("
            SELECT id, d2m_no 
            FROM d2m
            WHERE nep_date = :nep
              AND d2m_type = :type
              AND fiscal_year_id = :fy
              AND deleted_at IS NULL
            LIMIT 1
        ");
        $checkStmt->execute([
            ':nep'  => $nep_date,
            ':type' => $type,
            ':fy'   => $fiscal_year
        ]);

        if ($checkStmt->fetch()) {
            throw new Exception("D2M already created for this date and type.");
        }

        /* 3️⃣ Generate Serial */
        $serialStmt = $conn->prepare("
            SELECT COALESCE(MAX(serial_no),0)+1
            FROM d2m
            WHERE fiscal_year_id = :fy
              AND d2m_type = :type
        ");
        $serialStmt->execute([
            ':fy'   => $fiscal_year,
            ':type' => $type
        ]);
        $serial_no = $serialStmt->fetchColumn();

        $d2m_no = "{$serial_no}-D2M/{$fiscal_code}-{$type}-{$nep_date}";

        /* 4️⃣ Insert D2M */
        $insertD2M = $conn->prepare("
            INSERT INTO d2m
            (d2m_no, serial_no, d2m_type, fiscal_year_id, nep_date, eng_date, created_by)
            VALUES
            (:no, :serial, :type, :fy, :nep, :eng, :user)
            RETURNING id
        ");
        $insertD2M->execute([
            ':no'     => $d2m_no,
            ':serial' => $serial_no,
            ':type'   => $type,
            ':fy'     => $fiscal_year,
            ':nep'    => $nep_date,
            ':eng'    => $eng_date,
            ':user'   => $user_id
        ]);

        $d2m_id = $insertD2M->fetchColumn();

        /* 5️⃣ Insert EACH DENO as Separate D2M Item */
        $translated = ($type === 'T') ? 'true' : 'false';

        $denoStmt = $conn->prepare("
            SELECT 
                d.id as deno_id,
                d.book_code,
                d.per_poka_qty,
                d.poka_qty,
                d.total_qty,
                d.quantity_openpcs
            FROM deno d
            JOIN books b ON b.book_code = d.book_code
            WHERE d.deno_date_nep = :nep
              AND b.is_translated = :translated
            ORDER BY d.book_code, d.id
        ");
        $denoStmt->execute([
            ':nep' => $nep_date,
            ':translated' => $translated
        ]);
        $deno_records = $denoStmt->fetchAll(PDO::FETCH_ASSOC);

        if (empty($deno_records)) {
            throw new Exception("No deno records found for selected date and type.");
        }

        // Insert each DENO record as a separate D2M item
        $insertItemStmt = $conn->prepare("
            INSERT INTO d2m_items
            (d2m_id, book_code, per_poka_qty, total_poka_qty, total_qty, open_pcs, associated_deno_ids)
            VALUES
            (:d2m_id, :book_code, :per_poka, :total_poka, :total_qty, :open_pcs, :deno_ids)
        ");

        $items_inserted = 0;
        foreach ($deno_records as $record) {
            $insertItemStmt->execute([
                ':d2m_id' => $d2m_id,
                ':book_code' => $record['book_code'],
                ':per_poka' => $record['per_poka_qty'],
                ':total_poka' => $record['poka_qty'],
                ':total_qty' => $record['total_qty'],
                ':open_pcs' => $record['quantity_openpcs'],
                ':deno_ids' => (string)$record['deno_id'] // Store single DENO ID
            ]);
            $items_inserted++;
        }

        $conn->commit();
        $message = "D2M successfully created: <strong>$d2m_no</strong><br>";
        $message .= "Total items: <strong>$items_inserted</strong> (each DENO record as separate item)";

    } catch (Exception $e) {
        $conn->rollBack();
        $error = $e->getMessage();
    }
}
?>

<style>
.preview-section {
    margin-top: 30px;
    padding: 20px;
    background: #f8f9fa;
    border-radius: 8px;
    border: 1px solid #dee2e6;
}

.preview-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 15px;
    border-radius: 8px 8px 0 0;
    margin: -20px -20px 20px -20px;
}

.deno-item {
    background: white;
    padding: 15px;
    margin-bottom: 10px;
    border-radius: 5px;
    border-left: 4px solid #007bff;
    box-shadow: 0 1px 3px rgba(0,0,0,0.1);
}

.summary-box {
    background: #e8f4f8;
    padding: 15px;
    border-radius: 5px;
    margin-top: 20px;
}

.badge-custom {
    padding: 5px 10px;
    border-radius: 4px;
    font-size: 12px;
    font-weight: bold;
}

.badge-deno {
    background: #17a2b8;
    color: white;
}
</style>

<div class="container mt-4">
    <div class="card shadow">
        <div class="card-header bg-primary text-white">
            <h5 class="mb-0">📋 Create Deno to Marketing (D2M) - Each DENO Separate</h5>
        </div>

        <div class="card-body">
            <?php if ($message): ?>
                <div class="alert alert-success alert-dismissible fade show">
                    <?= $message ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <?php if ($error): ?>
                <div class="alert alert-danger alert-dismissible fade show">
                    <?= $error ?>
                    <button type="button" class="btn-close" data-bs-dismiss="alert"></button>
                </div>
            <?php endif; ?>

            <form method="POST" id="d2mForm">
                <div class="row mb-3">
                    <div class="col-md-4">
                        <label class="form-label fw-bold">Nepali Date <span class="text-danger">*</span></label>
                        <input type="text" 
                               name="nep_date" 
                               id="nep_date" 
                               class="form-control" 
                               placeholder="2082.09.03" 
                               pattern="\d{4}\.\d{2}\.\d{2}"
                               value="<?= htmlspecialchars($_POST['nep_date'] ?? '') ?>"
                               required>
                        <small class="text-muted">Format: YYYY.MM.DD</small>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">English Date <span class="text-danger">*</span></label>
                        <input type="date" 
                               name="eng_date" 
                               id="eng_date" 
                               class="form-control" 
                               value="<?= htmlspecialchars($_POST['eng_date'] ?? '') ?>"
                               required>
                    </div>

                    <div class="col-md-4">
                        <label class="form-label fw-bold">Type <span class="text-danger">*</span></label>
                        <select name="type" id="type" class="form-select" required>
                            <option value="">-- Select Type --</option>
                            <option value="NT" <?= (isset($_POST['type']) && $_POST['type'] == 'NT') ? 'selected' : '' ?>>
                                Non-Translated (NT)
                            </option>
                            <option value="T" <?= (isset($_POST['type']) && $_POST['type'] == 'T') ? 'selected' : '' ?>>
                                Translated (T)
                            </option>
                        </select>
                    </div>
                </div>

                <div class="d-flex justify-content-between">
                    <div>
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to List
                        </a>
                        <button type="button" id="previewBtn" class="btn btn-info">
                            <i class="fas fa-eye"></i> Preview DENO Records
                        </button>
                    </div>
                    <button type="submit" class="btn btn-success" id="submitBtn">
                        <i class="fas fa-check-circle"></i> Generate D2M
                    </button>
                </div>
            </form>

            <?php if (isset($preview_data) && !empty($preview_data)): ?>
            <div class="preview-section">
                <div class="preview-header">
                    <h5 class="mb-0">
                        <i class="fas fa-list-alt"></i> Preview: DENO Records for <?= htmlspecialchars($nep_date) ?>
                        (<?= $type == 'T' ? 'Translated' : 'Non-Translated' ?>)
                    </h5>
                </div>

                <div class="alert alert-info">
                    <strong>Total DENO Records:</strong> <?= count($preview_data) ?> records
                    <br><strong>Note:</strong> Each DENO will be a separate line item in the D2M report
                </div>

                <?php 
                $total_poka = 0;
                $total_qty = 0;
                $total_open = 0;
                
                foreach ($preview_data as $index => $item): 
                    $total_poka += $item['poka_qty'];
                    $total_qty += $item['total_qty'];
                    $total_open += $item['quantity_openpcs'];
                ?>
                <div class="deno-item">
                    <div class="d-flex justify-content-between align-items-start">
                        <div style="flex: 1;">
                            <div class="d-flex align-items-center gap-2 mb-2">
                                <span class="badge-custom badge-deno">DENO #<?= $item['deno_id'] ?></span>
                                <strong><?= htmlspecialchars($item['book_name']) ?></strong>
                            </div>
                            <div style="font-size: 13px; color: #666;">
                                <strong>Ref No:</strong> <?= htmlspecialchars($item['ref_no']) ?> | 
                                <strong>Code:</strong> <?= htmlspecialchars($item['book_code']) ?> | 
                                <strong>Class:</strong> <?= htmlspecialchars($item['class_level']) ?>
                            </div>
                        </div>
                        <div class="text-end" style="min-width: 300px;">
                            <div style="font-size: 13px;">
                                <strong>Per Poka:</strong> <?= number_format($item['per_poka_qty']) ?> | 
                                <strong>Poka Qty:</strong> <?= number_format($item['poka_qty']) ?>
                            </div>
                            <div style="font-size: 13px;">
                                <strong>Total Books:</strong> <?= number_format($item['total_qty']) ?> | 
                                <strong>Open Pcs:</strong> <?= number_format($item['quantity_openpcs']) ?>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>

                <div class="summary-box">
                    <h6><strong>Grand Totals:</strong></h6>
                    <div class="row">
                        <div class="col-md-3">
                            <strong>Total DENO Records:</strong> <?= count($preview_data) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Total Poka:</strong> <?= number_format($total_poka) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Total Books:</strong> <?= number_format($total_qty) ?>
                        </div>
                        <div class="col-md-3">
                            <strong>Total Open Pcs:</strong> <?= number_format($total_open) ?>
                        </div>
                    </div>
                </div>

                <div class="text-center mt-3">
                    <button type="button" class="btn btn-success btn-lg" onclick="document.getElementById('d2mForm').submit()">
                        <i class="fas fa-check-circle"></i> Confirm and Create D2M (<?= count($preview_data) ?> separate items)
                    </button>
                </div>
            </div>
            <?php elseif (isset($_GET['preview'])): ?>
            <div class="preview-section">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i> 
                    No DENO records found for the selected date and type.
                </div>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Date formatting
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

// Preview button
document.getElementById('previewBtn').addEventListener('click', function() {
    const nepDate = document.getElementById('nep_date').value;
    const type = document.getElementById('type').value;
    
    if (!nepDate || !type) {
        alert('Please select both Nepali Date and Type before previewing.');
        return;
    }
    
    // Validate date format
    if (!/^\d{4}\.\d{2}\.\d{2}$/.test(nepDate)) {
        alert('Please enter date in correct format (YYYY.MM.DD)');
        return;
    }
    
    // Redirect to preview
    window.location.href = `?preview=1&nep_date=${encodeURIComponent(nepDate)}&type=${encodeURIComponent(type)}`;
});

// Form validation
document.getElementById('d2mForm').addEventListener('submit', function(e) {
    const nepDate = document.getElementById('nep_date').value;
    
    if (!/^\d{4}\.\d{2}\.\d{2}$/.test(nepDate)) {
        e.preventDefault();
        alert('Please enter Nepali date in correct format (YYYY.MM.DD)');
        return false;
    }
    
    const submitBtn = document.getElementById('submitBtn');
    submitBtn.disabled = true;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Creating D2M...';
});
</script>

<?php include "../includes/footer.php"; ?>
<?php
ob_start();
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$error_message = null;
$success_message = null;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $conn->beginTransaction();
        
        $action = $_POST['action'] ?? 'create';
        
        if ($action === 'create') {
            $sql = "
                INSERT INTO holidays (
                    holiday_date_nep, holiday_date_eng, holiday_name,
                    holiday_type_id, fiscal_year, remarks, created_by
                ) VALUES (
                    :holiday_date_nep, :holiday_date_eng, :holiday_name,
                    :holiday_type_id, :fiscal_year, :remarks, :created_by
                )
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':holiday_date_nep' => $_POST['holiday_date_nep'],
                ':holiday_date_eng' => $_POST['holiday_date_eng'],
                ':holiday_name' => $_POST['holiday_name'],
                ':holiday_type_id' => $_POST['holiday_type_id'],
                ':fiscal_year' => $_POST['fiscal_year'],
                ':remarks' => $_POST['remarks'] ?? null,
                ':created_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $success_message = "Holiday created successfully!";
            
        } else if ($action === 'update') {
            $sql = "
                UPDATE holidays SET
                    holiday_date_nep = :holiday_date_nep,
                    holiday_date_eng = :holiday_date_eng,
                    holiday_name = :holiday_name,
                    holiday_type_id = :holiday_type_id,
                    fiscal_year = :fiscal_year,
                    remarks = :remarks,
                    updated_by = :updated_by,
                    updated_at = CURRENT_TIMESTAMP
                WHERE id = :id
            ";
            
            $stmt = $conn->prepare($sql);
            $stmt->execute([
                ':id' => $_POST['id'],
                ':holiday_date_nep' => $_POST['holiday_date_nep'],
                ':holiday_date_eng' => $_POST['holiday_date_eng'],
                ':holiday_name' => $_POST['holiday_name'],
                ':holiday_type_id' => $_POST['holiday_type_id'],
                ':fiscal_year' => $_POST['fiscal_year'],
                ':remarks' => $_POST['remarks'] ?? null,
                ':updated_by' => $_SESSION['user_id'] ?? 1
            ]);
            
            $success_message = "Holiday updated successfully!";
            
        } else if ($action === 'delete') {
            $stmt = $conn->prepare("DELETE FROM holidays WHERE id = :id");
            $stmt->execute([':id' => $_POST['id']]);
            $success_message = "Holiday deleted successfully!";
            
        } else if ($action === 'toggle_status') {
            $stmt = $conn->prepare("UPDATE holidays SET is_active = NOT is_active WHERE id = :id");
            $stmt->execute([':id' => $_POST['id']]);
            $success_message = "Holiday status updated!";
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_message = $e->getMessage();
    }
}

// Get holiday types
$holiday_types = $conn->query("SELECT * FROM holiday_types ORDER BY type_name")->fetchAll(PDO::FETCH_ASSOC);

// Get holidays
$fiscal_year_filter = $_GET['fiscal_year'] ?? '2082';
$holidays = $conn->query("
    SELECT h.*, ht.type_name, ht.color_code, ht.is_paid
    FROM holidays h
    LEFT JOIN holiday_types ht ON h.holiday_type_id = ht.id
    WHERE h.fiscal_year = '{$fiscal_year_filter}'
    ORDER BY h.holiday_date_eng
")->fetchAll(PDO::FETCH_ASSOC);

// Get record for editing
$edit_record = null;
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM holidays WHERE id = :id");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_record = $stmt->fetch(PDO::FETCH_ASSOC);
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
    margin: 0;
    padding: 0;
}

.container {
    max-width: 1400px;
    margin: 0 auto;
    padding: 20px;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.2);
}

.page-title {
    font-size: 32px;
    font-weight: 700;
    margin: 0 0 10px 0;
}

.form-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    margin-bottom: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.form-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
    gap: 20px;
}

.form-group {
    display: flex;
    flex-direction: column;
}

.form-group label {
    font-size: 14px;
    font-weight: 600;
    margin-bottom: 8px;
    color: #495057;
}

.form-group label .required {
    color: #dc3545;
}

.form-control {
    padding: 10px 14px;
    border: 2px solid #e9ecef;
    border-radius: 6px;
    font-size: 14px;
    transition: all 0.3s ease;
}

.form-control:focus {
    outline: none;
    border-color: #667eea;
    box-shadow: 0 0 0 3px rgba(102,126,234,0.1);
}

.btn {
    padding: 10px 20px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 14px;
    font-weight: 600;
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    gap: 8px;
    transition: all 0.2s ease;
}

.btn-primary { background: #667eea; color: white; }
.btn-success { background: #28a745; color: white; }
.btn-secondary { background: #6c757d; color: white; }
.btn-warning { background: #ffc107; color: #212529; }
.btn-danger { background: #dc3545; color: white; }
.btn-info { background: #17a2b8; color: white; }
.btn-sm { padding: 6px 12px; font-size: 12px; }

.btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.form-actions {
    display: flex;
    gap: 10px;
    justify-content: flex-end;
    margin-top: 30px;
    padding-top: 20px;
    border-top: 2px solid #e9ecef;
}

.alert {
    padding: 15px 20px;
    margin-bottom: 20px;
    border-radius: 8px;
    font-size: 14px;
}

.alert-success {
    background: #d4edda;
    color: #155724;
    border: 2px solid #c3e6cb;
}

.alert-danger {
    background: #f8d7da;
    color: #721c24;
    border: 2px solid #f5c6cb;
}

.calendar-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.calendar-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 20px;
}

.holiday-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 15px;
}

.holiday-card {
    border-radius: 8px;
    padding: 15px;
    border-left: 4px solid;
    box-shadow: 0 2px 4px rgba(0,0,0,0.08);
    transition: all 0.2s ease;
}

.holiday-card:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.15);
}

.holiday-date {
    font-size: 18px;
    font-weight: 700;
    margin-bottom: 8px;
    color: #333;
}

.holiday-name {
    font-size: 16px;
    font-weight: 600;
    margin-bottom: 5px;
    color: #495057;
}

.holiday-type {
    display: inline-block;
    padding: 3px 10px;
    border-radius: 12px;
    font-size: 11px;
    font-weight: 600;
    color: white;
    margin-top: 8px;
}

.holiday-actions {
    margin-top: 10px;
    display: flex;
    gap: 5px;
}

.inactive-badge {
    display: inline-block;
    padding: 3px 8px;
    background: #6c757d;
    color: white;
    border-radius: 4px;
    font-size: 10px;
    font-weight: 600;
    margin-left: 10px;
}
</style>

<div class="container">
    <div class="page-header">
        <h1 class="page-title">📅 Holiday Management System</h1>
        <p class="page-subtitle">Manage public holidays, festivals, and special occasions</p>
    </div>

    <?php if ($success_message): ?>
        <div class="alert alert-success">✅ <?= htmlspecialchars($success_message) ?></div>
    <?php endif; ?>

    <?php if ($error_message): ?>
        <div class="alert alert-danger">❌ <?= htmlspecialchars($error_message) ?></div>
    <?php endif; ?>

    <div class="form-container">
        <h3 style="margin-bottom: 20px;">
            <?= $edit_record ? '✏️ Edit Holiday' : '➕ Add New Holiday' ?>
        </h3>
        
        <form method="POST">
            <input type="hidden" name="action" value="<?= $edit_record ? 'update' : 'create' ?>">
            <?php if ($edit_record): ?>
                <input type="hidden" name="id" value="<?= $edit_record['id'] ?>">
            <?php endif; ?>

            <div class="form-grid">
                <div class="form-group">
                    <label for="holiday_name">Holiday Name <span class="required">*</span></label>
                    <input type="text" 
                           name="holiday_name" 
                           id="holiday_name" 
                           class="form-control"
                           value="<?= htmlspecialchars($edit_record['holiday_name'] ?? '') ?>" 
                           required>
                </div>

                <div class="form-group">
                    <label for="holiday_type_id">Holiday Type <span class="required">*</span></label>
                    <select name="holiday_type_id" id="holiday_type_id" class="form-control" required>
                        <option value="">Select Type</option>
                        <?php foreach ($holiday_types as $type): ?>
                            <option value="<?= $type['id'] ?>"
                                    <?= ($edit_record && $edit_record['holiday_type_id'] == $type['id']) ? 'selected' : '' ?>>
                                <?= htmlspecialchars($type['type_name']) ?>
                                <?= $type['is_paid'] ? '(Paid)' : '(Unpaid)' ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <div class="form-group">
                    <label for="holiday_date_nep">Nepali Date <span class="required">*</span></label>
                    <input type="text" 
                           name="holiday_date_nep" 
                           id="holiday_date_nep" 
                           class="form-control"
                           pattern="\d{4}\.\d{2}\.\d{2}" 
                           placeholder="YYYY.MM.DD"
                           value="<?= htmlspecialchars($edit_record['holiday_date_nep'] ?? '') ?>" 
                           required>
                </div>

                <div class="form-group">
                    <label for="holiday_date_eng">English Date <span class="required">*</span></label>
                    <input type="date" 
                           name="holiday_date_eng" 
                           id="holiday_date_eng" 
                           class="form-control"
                           value="<?= $edit_record['holiday_date_eng'] ?? '' ?>" 
                           required>
                </div>

                <div class="form-group">
                    <label for="fiscal_year">Fiscal Year <span class="required">*</span></label>
                    <select name="fiscal_year" id="fiscal_year" class="form-control" required>
                        <option value="2081" <?= ($edit_record && $edit_record['fiscal_year'] === '2081') ? 'selected' : '' ?>>2081</option>
                        <option value="2082" <?= (!$edit_record || $edit_record['fiscal_year'] === '2082') ? 'selected' : '' ?>>2082</option>
                        <option value="2083" <?= ($edit_record && $edit_record['fiscal_year'] === '2083') ? 'selected' : '' ?>>2083</option>
                        <option value="2084" <?= ($edit_record && $edit_record['fiscal_year'] === '2084') ? 'selected' : '' ?>>2084</option>
                    </select>
                </div>

                <div class="form-group" style="grid-column: 1 / -1;">
                    <label for="remarks">Remarks</label>
                    <textarea name="remarks" 
                              id="remarks" 
                              class="form-control" 
                              rows="2"><?= htmlspecialchars($edit_record['remarks'] ?? '') ?></textarea>
                </div>
            </div>

            <div class="form-actions">
                <?php if ($edit_record): ?>
                    <a href="<?= $_SERVER['PHP_SELF'] ?>" class="btn btn-secondary">Cancel</a>
                <?php endif; ?>
                <button type="submit" class="btn btn-primary">
                    <?= $edit_record ? '💾 Update Holiday' : '✅ Add Holiday' ?>
                </button>
            </div>
        </form>
    </div>

    <div class="calendar-container">
        <div class="calendar-header">
            <h3>📆 Holidays for Fiscal Year <?= $fiscal_year_filter ?></h3>
            <div>
                <select onchange="window.location='?fiscal_year=' + this.value" class="form-control" style="display: inline-block; width: auto;">
                    <option value="2081" <?= $fiscal_year_filter === '2081' ? 'selected' : '' ?>>FY 2081</option>
                    <option value="2082" <?= $fiscal_year_filter === '2082' ? 'selected' : '' ?>>FY 2082</option>
                    <option value="2083" <?= $fiscal_year_filter === '2083' ? 'selected' : '' ?>>FY 2083</option>
                    <option value="2084" <?= $fiscal_year_filter === '2084' ? 'selected' : '' ?>>FY 2084</option>
                </select>
            </div>
        </div>

        <div class="holiday-list">
            <?php if (empty($holidays)): ?>
                <div style="grid-column: 1 / -1; text-align: center; padding: 40px; color: #6c757d;">
                    No holidays found for fiscal year <?= $fiscal_year_filter ?>
                </div>
            <?php else: ?>
                <?php foreach ($holidays as $holiday): ?>
                    <div class="holiday-card" style="border-left-color: <?= $holiday['color_code'] ?>; background: <?= $holiday['color_code'] ?>15;">
                        <div class="holiday-date">
                            📅 <?= $holiday['holiday_date_nep'] ?>
                            <small style="color: #6c757d; font-size: 12px;">
                                (<?= date('d M Y', strtotime($holiday['holiday_date_eng'])) ?>)
                            </small>
                        </div>
                        <div class="holiday-name">
                            <?= htmlspecialchars($holiday['holiday_name']) ?>
                            <?php if (!$holiday['is_active']): ?>
                                <span class="inactive-badge">Inactive</span>
                            <?php endif; ?>
                        </div>
                        <span class="holiday-type" style="background: <?= $holiday['color_code'] ?>;">
                            <?= htmlspecialchars($holiday['type_name']) ?>
                        </span>
                        <?php if ($holiday['remarks']): ?>
                            <div style="margin-top: 8px; font-size: 12px; color: #6c757d;">
                                <?= htmlspecialchars($holiday['remarks']) ?>
                            </div>
                        <?php endif; ?>
                        <div class="holiday-actions">
                            <a href="?edit_id=<?= $holiday['id'] ?>&fiscal_year=<?= $fiscal_year_filter ?>" 
                               class="btn btn-warning btn-sm">Edit</a>
                            <form method="POST" style="display: inline;">
                                <input type="hidden" name="action" value="toggle_status">
                                <input type="hidden" name="id" value="<?= $holiday['id'] ?>">
                                <button type="submit" class="btn btn-info btn-sm">
                                    <?= $holiday['is_active'] ? 'Deactivate' : 'Activate' ?>
                                </button>
                            </form>
                            <form method="POST" style="display: inline;" 
                                  onsubmit="return confirm('Delete this holiday?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $holiday['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </div>
                    </div>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Nepali date validation
document.getElementById('holiday_date_nep').addEventListener('input', function() {
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
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

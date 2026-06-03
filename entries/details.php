<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

$id = $_GET['id'] ?? null;

if (!$id) {
    header('Location: index.php?error=No record ID provided');
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT d.*, b.book_name, b.class_level, b.is_translated 
        FROM deno d 
        LEFT JOIN books b ON d.book_code = b.book_code 
        WHERE d.id = :id
    ");
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->execute();
    $record = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$record) {
        header('Location: index.php?error=Record not found');
        exit();
    }
} catch (PDOException $e) {
    header('Location: index.php?error=Database error: ' . urlencode($e->getMessage()));
    exit();
}
?>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background-color: #f8f9fa;
    margin: 0;
    padding: 20px;
}

.container {
    max-width: 800px;
    margin: 0 auto;
    background: white;
    padding: 30px;
    border-radius: 10px;
    box-shadow: 0 4px 15px rgba(0,0,0,0.1);
}

.page-header {
    text-align: center;
    margin-bottom: 30px;
    padding-bottom: 20px;
    border-bottom: 3px solid #007bff;
}

.page-header h2 {
    color: #333;
    margin: 0;
    font-size: 28px;
}

.record-card {
    background: white;
    border-radius: 8px;
    padding: 25px;
    margin-bottom: 30px;
    box-shadow: 0 2px 10px rgba(0,0,0,0.1);
    border-left: 5px solid #007bff;
}

.record-detail {
    display: grid;
    grid-template-columns: 150px 1fr;
    gap: 15px;
    margin-bottom: 10px;
}

.record-detail strong {
    color: #495057;
    font-weight: 600;
}

.record-value {
    color: #212529;
}

.button-group {
    margin-top: 30px;
    display: flex;
    gap: 10px;
    flex-wrap: wrap;
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
    text-align: center;
    transition: all 0.3s ease;
}

.btn-primary { background-color: #007bff; color: white; }
.btn-secondary { background-color: #6c757d; color: white; }
.btn-warning { background-color: #ffc107; color: #212529; }
.btn-danger { background-color: #dc3545; color: white; }

.btn:hover {
    transform: translateY(-2px);
    box-shadow: 0 4px 8px rgba(0,0,0,0.2);
}

@media print {
    body { background: white; }
    .container { box-shadow: none; padding: 0; }
    .button-group { display: none; }
    .page-header { border-bottom: 2px solid #000; }
    .record-card { border-left: 2px solid #000; box-shadow: none; }
}
</style>

<div class="container">
    <div class="page-header">
        <h2>📋 Deno Record Details #<?= $record['id'] ?></h2>
        <p>Janak Education Materials Center</p>
    </div>

    <div class="record-card">
        <div class="record-detail">
            <strong>Book Name:</strong>
            <span class="record-value"><?= htmlspecialchars($record['book_name']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Book Code:</strong>
            <span class="record-value"><?= htmlspecialchars($record['book_code']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Class Level:</strong>
            <span class="record-value"><?= $record['class_level'] ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Translated:</strong>
            <span class="record-value">
                <?= $record['is_translated'] ? '✅ Yes' : '❌ No' ?>
            </span>
        </div>
        
        <div class="record-detail">
            <strong>Reference No:</strong>
            <span class="record-value"><?= htmlspecialchars($record['ref_no']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Nepali Date:</strong>
            <span class="record-value"><?= htmlspecialchars($record['deno_date_nep']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Per Poka Qty:</strong>
            <span class="record-value"><?= number_format($record['per_poka_qty']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Poka Qty:</strong>
            <span class="record-value"><?= number_format($record['poka_qty']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Total Qty:</strong>
            <span class="record-value"><?= number_format($record['total_qty']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Open Pcs:</strong>
            <span class="record-value"><?= number_format($record['quantity_openpcs']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Created By:</strong>
            <span class="record-value"><?= htmlspecialchars($record['created_by']) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Created At:</strong>
            <span class="record-value"><?= date('Y-m-d H:i', strtotime($record['created_at'])) ?></span>
        </div>
        
        <div class="record-detail">
            <strong>Last Updated:</strong>
            <span class="record-value">
                <?= $record['updated_at'] ? date('Y-m-d H:i', strtotime($record['updated_at'])) : 'Never' ?>
            </span>
        </div>
    </div>

    <div class="button-group">
        <a href="edit.php?id=<?= $record['id'] ?>" class="btn btn-warning">✏️ Edit Record</a>
        <a href="index.php" class="btn btn-secondary">⬅️ Back to List</a>
        <button onclick="window.print()" class="btn btn-primary">🖨️ Print</button>
        <a href="delete.php?id=<?= $record['id'] ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to delete this record?')">🗑️ Delete</a>
    </div>
</div>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>
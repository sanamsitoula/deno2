<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

redirect_if_not_logged_in();

$processed = 0;
$errors = 0;
$error_details = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['convert'])) {
    try {
        $conn->beginTransaction();
        
        // Get all records with Nepali dates but placeholder English dates
        $stmt = $conn->query("
            SELECT id, attendance_date_nep, attendance_date_eng 
            FROM attendance 
            WHERE attendance_date_eng = '2025-01-01' 
            OR attendance_date_eng IS NULL
            LIMIT 1000
        ");
        
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        foreach ($records as $record) {
            // This will be converted on client-side via AJAX
            $processed++;
        }
        
        $conn->commit();
        
    } catch (Exception $e) {
        $conn->rollBack();
        $error_details[] = $e->getMessage();
        $errors++;
    }
}
?>

<link href="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/css/nepali.datepicker.v5.0.6.min.css"
      rel="stylesheet" type="text/css"/>

<style>
body {
    font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
    background: #f5f7fa;
    padding: 20px;
}

.container {
    max-width: 900px;
    margin: 0 auto;
}

.page-header {
    background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
    color: white;
    padding: 30px;
    border-radius: 12px;
    margin-bottom: 30px;
}

.conversion-container {
    background: white;
    border-radius: 12px;
    padding: 30px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.08);
}

.btn {
    padding: 12px 24px;
    border: none;
    border-radius: 6px;
    cursor: pointer;
    font-size: 15px;
    font-weight: 600;
    background: #667eea;
    color: white;
}

.btn:hover {
    background: #5568d3;
}

.progress-bar {
    width: 100%;
    height: 30px;
    background: #e9ecef;
    border-radius: 15px;
    overflow: hidden;
    margin: 20px 0;
}

.progress-fill {
    height: 100%;
    background: linear-gradient(90deg, #667eea, #764ba2);
    width: 0%;
    transition: width 0.3s ease;
    display: flex;
    align-items: center;
    justify-content: center;
    color: white;
    font-weight: 600;
}

.log-container {
    background: #f8f9fa;
    border: 1px solid #dee2e6;
    border-radius: 6px;
    padding: 15px;
    max-height: 400px;
    overflow-y: auto;
    font-family: 'Courier New', monospace;
    font-size: 12px;
    margin-top: 20px;
}

.log-item {
    padding: 5px 0;
    border-bottom: 1px solid #e9ecef;
}

.log-success {
    color: #28a745;
}

.log-error {
    color: #dc3545;
}
</style>

<div class="container">
    <div class="page-header">
        <h1>🔄 Nepali to English Date Converter</h1>
        <p>Convert placeholder English dates using Nepali datepicker library</p>
    </div>

    <div class="conversion-container">
        <h3>Batch Date Conversion</h3>
        <p>This tool converts all attendance records that have placeholder English dates (2025-01-01) to proper dates based on their Nepali dates.</p>
        
        <button onclick="startConversion()" class="btn" id="convertBtn">
            🔄 Start Conversion
        </button>

        <div class="progress-bar" style="display: none;" id="progressBar">
            <div class="progress-fill" id="progressFill">0%</div>
        </div>

        <div class="log-container" id="logContainer" style="display: none;">
            <strong>Conversion Log:</strong>
            <div id="logContent"></div>
        </div>
    </div>
</div>

<script src="https://nepalidatepicker.sajanmaharjan.com.np/v5/nepali.datepicker/js/nepali.datepicker.v5.0.6.min.js"></script>

<script>
async function startConversion() {
    const btn = document.getElementById('convertBtn');
    const progressBar = document.getElementById('progressBar');
    const progressFill = document.getElementById('progressFill');
    const logContainer = document.getElementById('logContainer');
    const logContent = document.getElementById('logContent');
    
    btn.disabled = true;
    btn.textContent = '⏳ Converting...';
    progressBar.style.display = 'block';
    logContainer.style.display = 'block';
    logContent.innerHTML = '';
    
    try {
        // Fetch records to convert
        const response = await fetch('get_unconverted_dates.php');
        const records = await response.json();
        
        if (!records || records.length === 0) {
            addLog('✅ No records to convert!', 'success');
            btn.disabled = false;
            btn.textContent = '🔄 Start Conversion';
            return;
        }
        
        addLog(`Found ${records.length} records to convert`, 'info');
        
        let converted = 0;
        let errors = 0;
        
        for (let i = 0; i < records.length; i++) {
            const record = records[i];
            
            try {
                // Convert BS to AD using Nepali datepicker library
                const adDate = NepaliFunctions.BS2AD(
                    record.attendance_date_nep,
                    'YYYY.MM.DD',
                    'YYYY.MM.DD'
                );
                
                if (adDate) {
                    // Update via AJAX
                    await updateEnglishDate(record.id, adDate);
                    converted++;
                    
                    if (converted % 10 === 0) {
                        addLog(`Converted ${converted}/${records.length} records`, 'success');
                    }
                } else {
                    errors++;
                    addLog(`❌ Failed to convert: ${record.attendance_date_nep}`, 'error');
                }
                
            } catch (e) {
                errors++;
                addLog(`❌ Error converting ${record.attendance_date_nep}: ${e.message}`, 'error');
            }
            
            // Update progress
            const progress = Math.round(((i + 1) / records.length) * 100);
            progressFill.style.width = progress + '%';
            progressFill.textContent = progress + '%';
        }
        
        addLog(`\n✅ Conversion complete! Converted: ${converted}, Errors: ${errors}`, 'success');
        btn.disabled = false;
        btn.textContent = '🔄 Start Conversion';
        
    } catch (e) {
        addLog(`❌ Fatal error: ${e.message}`, 'error');
        btn.disabled = false;
        btn.textContent = '🔄 Start Conversion';
    }
}

async function updateEnglishDate(id, engDate) {
    const formData = new FormData();
    formData.append('id', id);
    formData.append('date_eng', engDate);
    
    const response = await fetch('update_english_date.php', {
        method: 'POST',
        body: formData
    });
    
    const result = await response.json();
    
    if (!result.success) {
        throw new Error(result.error || 'Update failed');
    }
}

function addLog(message, type = 'info') {
    const logContent = document.getElementById('logContent');
    const div = document.createElement('div');
    div.className = 'log-item log-' + type;
    div.textContent = new Date().toLocaleTimeString() + ' - ' + message;
    logContent.appendChild(div);
    logContent.scrollTop = logContent.scrollHeight;
}
</script>

<?php require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/footer.php'; ?>

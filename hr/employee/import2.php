<?php
// ============================================
// CSV IMPORT FIXER WITH DATE FORMAT CONVERSION
// ============================================

class CSVImportFixer {
    private $filePath;
    private $errors = [];
    private $fixedRows = [];
    private $originalRows = [];
    private $headers = [];
    
    public function __construct($filePath) {
        $this->filePath = $filePath;
    }
    
    /**
     * Convert date from MM/DD/YYYY to YYYY-MM-DD
     */
    private function convertDateFormat($dateString) {
        if (empty(trim($dateString))) {
            $this->errors[] = "Empty date found";
            return '';
        }
        
        // Check if already in correct format
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $dateString)) {
            return $dateString;
        }
        
        // Handle MM/DD/YYYY format
        if (preg_match('/^(\d{1,2})\/(\d{1,2})\/(\d{4})$/', $dateString, $matches)) {
            $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
            $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
            $year = $matches[3];
            
            // Validate date
            if (checkdate((int)$month, (int)$day, (int)$year)) {
                return sprintf('%04d-%02d-%02d', $year, $month, $day);
            } else {
                $this->errors[] = "Invalid date: $dateString";
                return $dateString; // Return original if invalid
            }
        }
        
        // Try other common formats
        $formats = [
            'd/m/Y' => '/(\d{1,2})\/(\d{1,2})\/(\d{4})/',
            'Y/m/d' => '/(\d{4})\/(\d{1,2})\/(\d{1,2})/',
            'm-d-Y' => '/(\d{1,2})-(\d{1,2})-(\d{4})/',
        ];
        
        foreach ($formats as $format => $pattern) {
            if (preg_match($pattern, $dateString, $matches)) {
                if ($format === 'd/m/Y') {
                    $day = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $year = $matches[3];
                } elseif ($format === 'Y/m/d') {
                    $year = $matches[1];
                    $month = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $day = str_pad($matches[3], 2, '0', STR_PAD_LEFT);
                } elseif ($format === 'm-d-Y') {
                    $month = str_pad($matches[1], 2, '0', STR_PAD_LEFT);
                    $day = str_pad($matches[2], 2, '0', STR_PAD_LEFT);
                    $year = $matches[3];
                }
                
                if (checkdate((int)$month, (int)$day, (int)$year)) {
                    return sprintf('%04d-%02d-%02d', $year, $month, $day);
                }
            }
        }
        
        $this->errors[] = "Unrecognized date format: $dateString";
        return $dateString;
    }
    
    /**
     * Fix bank account number (remove scientific notation)
     */
    private function fixBankAccountNumber($account) {
        if (empty($account)) return '';
        
        $account = trim($account);
        
        // Handle scientific notation like 1.23E+12
        if (preg_match('/^[0-9.]+E\+[0-9]+$/i', $account)) {
            $number = (float)$account;
            return number_format($number, 0, '.', '');
        }
        
        return $account;
    }
    
    /**
     * Clean CSV data and fix issues
     */
    public function process() {
        if (!file_exists($this->filePath)) {
            $this->errors[] = "File not found: " . $this->filePath;
            return false;
        }
        
        // Read the CSV file
        $handle = fopen($this->filePath, 'r');
        if ($handle === false) {
            $this->errors[] = "Unable to open file: " . $this->filePath;
            return false;
        }
        
        $lineNumber = 0;
        $this->originalRows = [];
        $this->fixedRows = [];
        
        while (($row = fgetcsv($handle)) !== false) {
            $lineNumber++;
            $this->originalRows[] = $row;
            
            // Skip header row (line 1)
            if ($lineNumber === 1) {
                $this->headers = array_map('trim', $row);
                
                // Check for Excel artifact rows (looks like menu/navigation)
                $firstCell = strtolower(trim($row[0] ?? ''));
                if (strpos($firstCell, 'menu') !== false || 
                    strpos($firstCell, 'file') !== false ||
                    strpos($firstCell, 'home') !== false ||
                    strpos($firstCell, 'insert') !== false) {
                    // This is likely an Excel artifact, skip this row and read next row as header
                    $this->errors[] = "Skipping Excel artifact row at line 1";
                    $row = fgetcsv($handle);
                    $lineNumber++;
                    if ($row !== false) {
                        $this->headers = array_map('trim', $row);
                        $this->originalRows[] = $row;
                    }
                }
                continue;
            }
            
            // Skip empty rows
            if (empty($row) || (count($row) === 1 && trim($row[0]) === '')) {
                continue;
            }
            
            // Combine headers with row data
            $rowData = [];
            foreach ($this->headers as $index => $header) {
                $rowData[$header] = $row[$index] ?? '';
            }
            
            // Fix the data
            $fixedData = $this->fixRowData($rowData, $lineNumber);
            
            if ($fixedData !== null) {
                $this->fixedRows[] = $fixedData;
            }
        }
        
        fclose($handle);
        return true;
    }
    
    /**
     * Fix individual row data
     */
    private function fixRowData($rowData, $lineNumber) {
        $fixedRow = [];
        
        foreach ($rowData as $key => $value) {
            $key = trim($key);
            $value = trim($value);
            
            // Apply fixes based on column
            switch ($key) {
                case 'join_date':
                case 'dob':
                    $fixedValue = $this->convertDateFormat($value);
                    if ($value !== $fixedValue && $fixedValue !== '') {
                        $this->errors[] = "Line $lineNumber: Fixed $key from '$value' to '$fixedValue'";
                    }
                    $fixedRow[$key] = $fixedValue;
                    break;
                    
                case 'bank_account_number':
                    $fixedRow[$key] = $this->fixBankAccountNumber($value);
                    break;
                    
                case 'email':
                    $fixedRow[$key] = strtolower($value);
                    break;
                    
                case 'name':
                case 'name_eng':
                case 'name_nep':
                    // Validate name field
                    if (empty($value) || is_numeric($value)) {
                        $this->errors[] = "Line $lineNumber: Invalid $key '$value' - should not be empty or numeric";
                    }
                    $fixedRow[$key] = $value;
                    break;
                    
                case 'mobile_number':
                    // Clean mobile number
                    $cleaned = preg_replace('/[^0-9]/', '', $value);
                    $fixedRow[$key] = $cleaned;
                    break;
                    
                default:
                    $fixedRow[$key] = $value;
                    break;
            }
        }
        
        return $fixedRow;
    }
    
    /**
     * Save fixed data to new CSV
     */
    public function saveFixedCSV($outputPath = null) {
        if (empty($this->fixedRows)) {
            $this->errors[] = "No data to save";
            return false;
        }
        
        if ($outputPath === null) {
            $timestamp = date('Ymd_His');
            $outputPath = 'fixed_' . basename($this->filePath, '.csv') . '_' . $timestamp . '.csv';
        }
        
        $handle = fopen($outputPath, 'w');
        if ($handle === false) {
            $this->errors[] = "Unable to create output file: $outputPath";
            return false;
        }
        
        // Write headers
        fputcsv($handle, $this->headers);
        
        // Write fixed rows
        foreach ($this->fixedRows as $row) {
            $csvRow = [];
            foreach ($this->headers as $header) {
                $csvRow[] = $row[$header] ?? '';
            }
            fputcsv($handle, $csvRow);
        }
        
        fclose($handle);
        return $outputPath;
    }
    
    /**
     * Get validation report
     */
    public function getReport() {
        $report = [];
        $report['total_rows'] = count($this->originalRows) - 1; // minus header
        $report['fixed_rows'] = count($this->fixedRows);
        $report['errors'] = $this->errors;
        $report['headers'] = $this->headers;
        
        // Sample of fixed data
        $report['sample'] = array_slice($this->fixedRows, 0, 3);
        
        return $report;
    }
    
    /**
     * Get errors
     */
    public function getErrors() {
        return $this->errors;
    }
    
    /**
     * Get fixed data
     */
    public function getFixedData() {
        return $this->fixedRows;
    }
}

// ============================================
// USAGE EXAMPLE - Web Interface
// ============================================

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['csv_file'])) {
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CSV Import Fixer - Results</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                line-height: 1.6;
                color: #333;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                padding: 20px;
            }
            
            .container {
                max-width: 1200px;
                margin: 0 auto;
                background: white;
                border-radius: 15px;
                box-shadow: 0 20px 40px rgba(0,0,0,0.1);
                overflow: hidden;
            }
            
            .header {
                background: linear-gradient(to right, #4CAF50, #45a049);
                color: white;
                padding: 30px;
                text-align: center;
            }
            
            .header h1 {
                font-size: 2.5em;
                margin-bottom: 10px;
                font-weight: 300;
            }
            
            .header p {
                opacity: 0.9;
                font-size: 1.1em;
            }
            
            .content {
                padding: 40px;
            }
            
            .card {
                background: #f8f9fa;
                border-radius: 10px;
                padding: 25px;
                margin-bottom: 30px;
                border-left: 5px solid #4CAF50;
            }
            
            .card h2 {
                color: #2c3e50;
                margin-bottom: 15px;
                font-size: 1.4em;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .card h2 i {
                color: #4CAF50;
            }
            
            .stats {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
                gap: 20px;
                margin-bottom: 30px;
            }
            
            .stat-box {
                background: white;
                padding: 25px;
                border-radius: 10px;
                text-align: center;
                box-shadow: 0 5px 15px rgba(0,0,0,0.05);
                transition: transform 0.3s ease;
            }
            
            .stat-box:hover {
                transform: translateY(-5px);
            }
            
            .stat-number {
                font-size: 2.5em;
                font-weight: bold;
                color: #4CAF50;
                margin-bottom: 10px;
            }
            
            .stat-label {
                color: #666;
                font-size: 0.9em;
                text-transform: uppercase;
                letter-spacing: 1px;
            }
            
            .errors {
                background: #fff5f5;
                border-left: 5px solid #e53e3e;
            }
            
            .errors h2 {
                color: #c53030;
            }
            
            .error-list {
                list-style: none;
            }
            
            .error-list li {
                padding: 12px 15px;
                margin-bottom: 8px;
                background: white;
                border-radius: 6px;
                border-left: 4px solid #e53e3e;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .error-list li:before {
                content: "⚠️";
            }
            
            .data-preview {
                overflow-x: auto;
            }
            
            .data-table {
                width: 100%;
                border-collapse: collapse;
                margin-top: 15px;
            }
            
            .data-table th {
                background: #2c3e50;
                color: white;
                padding: 15px;
                text-align: left;
                font-weight: 500;
            }
            
            .data-table td {
                padding: 12px 15px;
                border-bottom: 1px solid #eee;
            }
            
            .data-table tr:hover {
                background: #f8f9fa;
            }
            
            .actions {
                display: flex;
                gap: 15px;
                margin-top: 30px;
                flex-wrap: wrap;
            }
            
            .btn {
                padding: 15px 30px;
                border: none;
                border-radius: 8px;
                font-size: 1em;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.3s ease;
                text-decoration: none;
                display: inline-flex;
                align-items: center;
                gap: 10px;
            }
            
            .btn-primary {
                background: #4CAF50;
                color: white;
            }
            
            .btn-primary:hover {
                background: #45a049;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(76, 175, 80, 0.3);
            }
            
            .btn-secondary {
                background: #667eea;
                color: white;
            }
            
            .btn-secondary:hover {
                background: #5a67d8;
                transform: translateY(-2px);
                box-shadow: 0 5px 15px rgba(102, 126, 234, 0.3);
            }
            
            .success {
                color: #38a169;
                font-weight: 500;
            }
            
            .warning {
                color: #d69e2e;
                font-weight: 500;
            }
            
            .error {
                color: #e53e3e;
                font-weight: 500;
            }
        </style>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    </head>
    <body>
        <div class="container">
            <div class="header">
                <h1><i class="fas fa-check-circle"></i> CSV Processing Complete</h1>
                <p>Your employee import file has been analyzed and fixed</p>
            </div>
            
            <div class="content">
    <?php
    
    try {
        // Process uploaded file
        $uploadDir = 'uploads/';
        if (!is_dir($uploadDir)) {
            mkdir($uploadDir, 0777, true);
        }
        
        $fileName = basename($_FILES['csv_file']['name']);
        $uploadPath = $uploadDir . uniqid() . '_' . $fileName;
        
        if (move_uploaded_file($_FILES['csv_file']['tmp_name'], $uploadPath)) {
            $fixer = new CSVImportFixer($uploadPath);
            
            if ($fixer->process()) {
                $outputFile = $fixer->saveFixedCSV();
                $report = $fixer->getReport();
                
                // Statistics
                ?>
                <div class="stats">
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $report['total_rows']; ?></div>
                        <div class="stat-label">Total Rows</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo $report['fixed_rows']; ?></div>
                        <div class="stat-label">Successfully Fixed</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number"><?php echo count($report['errors']); ?></div>
                        <div class="stat-label">Issues Found</div>
                    </div>
                    <div class="stat-box">
                        <div class="stat-number">
                            <?php 
                            if ($report['total_rows'] > 0) {
                                echo round(($report['fixed_rows'] / $report['total_rows']) * 100) . '%';
                            } else {
                                echo '0%';
                            }
                            ?>
                        </div>
                        <div class="stat-label">Success Rate</div>
                    </div>
                </div>
                
                <!-- Success Message -->
                <div class="card">
                    <h2><i class="fas fa-check"></i> Processing Summary</h2>
                    <p class="success">
                        <i class="fas fa-check-circle"></i> 
                        File processed successfully! All dates have been converted to YYYY-MM-DD format.
                    </p>
                    <p><strong>Output File:</strong> <?php echo basename($outputFile); ?></p>
                    
                    <?php if ($report['fixed_rows'] > 0): ?>
                    <div class="data-preview">
                        <h3>Sample of Fixed Data (First 3 rows):</h3>
                        <table class="data-table">
                            <thead>
                                <tr>
                                    <?php foreach ($report['headers'] as $header): ?>
                                        <th><?php echo htmlspecialchars($header); ?></th>
                                    <?php endforeach; ?>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($report['sample'] as $row): ?>
                                    <tr>
                                        <?php foreach ($report['headers'] as $header): ?>
                                            <td><?php echo htmlspecialchars($row[$header] ?? ''); ?></td>
                                        <?php endforeach; ?>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </div>
                
                <!-- Errors/Warnings -->
                <?php if (!empty($report['errors'])): ?>
                <div class="card errors">
                    <h2><i class="fas fa-exclamation-triangle"></i> Issues Fixed</h2>
                    <p class="warning">
                        <i class="fas fa-info-circle"></i> 
                        The following issues were found and fixed during processing:
                    </p>
                    <ul class="error-list">
                        <?php foreach ($report['errors'] as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="actions">
                    <a href="<?php echo htmlspecialchars($outputFile); ?>" download class="btn btn-primary">
                        <i class="fas fa-download"></i> Download Fixed CSV
                    </a>
                    <a href="import_fix.php" class="btn btn-secondary">
                        <i class="fas fa-redo"></i> Process Another File
                    </a>
                </div>
                <?php
            } else {
                $errors = $fixer->getErrors();
                ?>
                <div class="card errors">
                    <h2><i class="fas fa-times-circle"></i> Processing Failed</h2>
                    <p class="error">The file could not be processed due to the following errors:</p>
                    <ul class="error-list">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
                <div class="actions">
                    <a href="import_fix.php" class="btn btn-primary">
                        <i class="fas fa-arrow-left"></i> Try Again
                    </a>
                </div>
                <?php
            }
        } else {
            throw new Exception("Failed to upload file. Please try again.");
        }
        
    } catch (Exception $e) {
        ?>
        <div class="card errors">
            <h2><i class="fas fa-exclamation-circle"></i> Error</h2>
            <p class="error"><?php echo htmlspecialchars($e->getMessage()); ?></p>
        </div>
        <div class="actions">
            <a href="import_fix.php" class="btn btn-primary">
                <i class="fas fa-arrow-left"></i> Go Back
            </a>
        </div>
        <?php
    }
    
    ?>
            </div>
        </div>
    </body>
    </html>
    <?php
    
} else {
    // Show upload form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <title>CSV Import Fixer - Employee Data</title>
        <style>
            * {
                margin: 0;
                padding: 0;
                box-sizing: border-box;
            }
            
            body {
                font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, Oxygen, Ubuntu, sans-serif;
                line-height: 1.6;
                color: #333;
                background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
                min-height: 100vh;
                display: flex;
                align-items: center;
                justify-content: center;
                padding: 20px;
            }
            
            .upload-container {
                width: 100%;
                max-width: 800px;
                background: white;
                border-radius: 20px;
                box-shadow: 0 20px 60px rgba(0,0,0,0.2);
                overflow: hidden;
            }
            
            .hero {
                background: linear-gradient(to right, #4CAF50, #45a049);
                color: white;
                padding: 50px 40px;
                text-align: center;
            }
            
            .hero h1 {
                font-size: 2.8em;
                margin-bottom: 15px;
                font-weight: 300;
            }
            
            .hero p {
                font-size: 1.2em;
                opacity: 0.9;
                max-width: 600px;
                margin: 0 auto 30px;
            }
            
            .upload-area {
                padding: 50px 40px;
            }
            
            .upload-box {
                border: 3px dashed #4CAF50;
                border-radius: 15px;
                padding: 60px 40px;
                text-align: center;
                background: #f8fff8;
                transition: all 0.3s ease;
                margin-bottom: 30px;
            }
            
            .upload-box:hover {
                background: #f0fff0;
                border-color: #45a049;
            }
            
            .upload-box.dragover {
                background: #e8f5e9;
                border-color: #2e7d32;
                transform: scale(1.02);
            }
            
            .upload-icon {
                font-size: 4em;
                color: #4CAF50;
                margin-bottom: 20px;
            }
            
            .upload-box h2 {
                color: #2c3e50;
                margin-bottom: 15px;
                font-size: 1.6em;
            }
            
            .upload-box p {
                color: #666;
                margin-bottom: 25px;
            }
            
            .file-input {
                display: none;
            }
            
            .file-label {
                display: inline-block;
                background: #4CAF50;
                color: white;
                padding: 15px 40px;
                border-radius: 50px;
                font-size: 1.1em;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.3s ease;
                border: 2px solid #4CAF50;
            }
            
            .file-label:hover {
                background: white;
                color: #4CAF50;
                transform: translateY(-2px);
                box-shadow: 0 10px 20px rgba(76, 175, 80, 0.2);
            }
            
            .selected-file {
                margin-top: 20px;
                padding: 15px;
                background: #e8f5e9;
                border-radius: 10px;
                display: none;
            }
            
            .selected-file.show {
                display: block;
            }
            
            .submit-btn {
                width: 100%;
                padding: 20px;
                background: #667eea;
                color: white;
                border: none;
                border-radius: 12px;
                font-size: 1.2em;
                font-weight: 500;
                cursor: pointer;
                transition: all 0.3s ease;
                display: flex;
                align-items: center;
                justify-content: center;
                gap: 15px;
            }
            
            .submit-btn:hover {
                background: #5a67d8;
                transform: translateY(-3px);
                box-shadow: 0 15px 30px rgba(102, 126, 234, 0.3);
            }
            
            .submit-btn:disabled {
                background: #ccc;
                cursor: not-allowed;
                transform: none;
                box-shadow: none;
            }
            
            .features {
                margin-top: 40px;
                padding-top: 30px;
                border-top: 2px solid #eee;
            }
            
            .features h3 {
                color: #2c3e50;
                margin-bottom: 20px;
                font-size: 1.4em;
                display: flex;
                align-items: center;
                gap: 10px;
            }
            
            .feature-grid {
                display: grid;
                grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
                gap: 20px;
            }
            
            .feature {
                background: #f8f9fa;
                padding: 20px;
                border-radius: 10px;
                border-left: 4px solid #4CAF50;
            }
            
            .feature h4 {
                color: #2c3e50;
                margin-bottom: 10px;
                font-size: 1.1em;
            }
            
            .feature p {
                color: #666;
                font-size: 0.95em;
            }
            
            .note {
                background: #fff3cd;
                border-left: 4px solid #ffc107;
                padding: 20px;
                border-radius: 10px;
                margin-top: 30px;
            }
            
            .note h4 {
                color: #856404;
                margin-bottom: 10px;
                display: flex;
                align-items: center;
                gap: 10px;
            }
        </style>
        <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    </head>
    <body>
        <div class="upload-container">
            <div class="hero">
                <h1><i class="fas fa-file-csv"></i> CSV Import Fixer</h1>
                <p>Fix date format issues in your employee import CSV files automatically</p>
            </div>
            
            <form method="POST" enctype="multipart/form-data" class="upload-area" id="uploadForm">
                <div class="upload-box" id="dropZone">
                    <div class="upload-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </div>
                    <h2>Upload Your CSV File</h2>
                    <p>Drag & drop your employee import CSV file here or click to browse</p>
                    <label for="csv_file" class="file-label">
                        <i class="fas fa-folder-open"></i> Choose File
                    </label>
                    <input type="file" name="csv_file" id="csv_file" accept=".csv" class="file-input" required>
                    <div class="selected-file" id="selectedFile">
                        <i class="fas fa-file-csv"></i> 
                        <span id="fileName"></span>
                        <span id="fileSize"></span>
                    </div>
                </div>
                
                <button type="submit" class="submit-btn" id="submitBtn" disabled>
                    <i class="fas fa-magic"></i> Process & Fix CSV File
                </button>
                
                <div class="features">
                    <h3><i class="fas fa-cogs"></i> What This Tool Fixes:</h3>
                    <div class="feature-grid">
                        <div class="feature">
                            <h4><i class="fas fa-calendar-alt"></i> Date Format Conversion</h4>
                            <p>Converts MM/DD/YYYY to YYYY-MM-DD format for join_date and dob</p>
                        </div>
                        <div class="feature">
                            <h4><i class="fas fa-calculator"></i> Bank Account Numbers</h4>
                            <p>Fixes scientific notation (1.23E+12) to regular numbers</p>
                        </div>
                        <div class="feature">
                            <h4><i class="fas fa-broom"></i> Data Cleaning</h4>
                            <p>Removes Excel artifacts, trims whitespace, validates data</p>
                        </div>
                        <div class="feature">
                            <h4><i class="fas fa-file-export"></i> Export Ready</h4>
                            <p>Generates a clean CSV ready for import into your system</p>
                        </div>
                    </div>
                </div>
                
                <div class="note">
                    <h4><i class="fas fa-info-circle"></i> Important Notes:</h4>
                    <p>• Your CSV should have columns: name, email, join_date, dob, bank_account_number</p>
                    <p>• Dates in MM/DD/YYYY format will be converted to YYYY-MM-DD</p>
                    <p>• Maximum file size: 10MB</p>
                    <p>• Original file is not modified - a new fixed file is generated</p>
                </div>
            </form>
        </div>
        
        <script>
            document.addEventListener('DOMContentLoaded', function() {
                const fileInput = document.getElementById('csv_file');
                const dropZone = document.getElementById('dropZone');
                const selectedFile = document.getElementById('selectedFile');
                const fileName = document.getElementById('fileName');
                const fileSize = document.getElementById('fileSize');
                const submitBtn = document.getElementById('submitBtn');
                
                // File input change handler
                fileInput.addEventListener('change', function(e) {
                    if (this.files.length > 0) {
                        const file = this.files[0];
                        updateFileInfo(file);
                    }
                });
                
                // Drag and drop functionality
                ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, preventDefaults, false);
                });
                
                function preventDefaults(e) {
                    e.preventDefault();
                    e.stopPropagation();
                }
                
                ['dragenter', 'dragover'].forEach(eventName => {
                    dropZone.addEventListener(eventName, highlight, false);
                });
                
                ['dragleave', 'drop'].forEach(eventName => {
                    dropZone.addEventListener(eventName, unhighlight, false);
                });
                
                function highlight() {
                    dropZone.classList.add('dragover');
                }
                
                function unhighlight() {
                    dropZone.classList.remove('dragover');
                }
                
                dropZone.addEventListener('drop', handleDrop, false);
                
                function handleDrop(e) {
                    const dt = e.dataTransfer;
                    const files = dt.files;
                    
                    if (files.length > 0) {
                        const file = files[0];
                        
                        // Check if it's a CSV file
                        if (!file.name.toLowerCase().endsWith('.csv')) {
                            alert('Please upload a CSV file.');
                            return;
                        }
                        
                        // Update file input
                        const dataTransfer = new DataTransfer();
                        dataTransfer.items.add(file);
                        fileInput.files = dataTransfer.files;
                        
                        updateFileInfo(file);
                    }
                }
                
                function updateFileInfo(file) {
                    fileName.textContent = file.name;
                    
                    // Format file size
                    const sizeInMB = (file.size / (1024 * 1024)).toFixed(2);
                    fileSize.textContent = ` (${sizeInMB} MB)`;
                    
                    selectedFile.classList.add('show');
                    submitBtn.disabled = false;
                }
                
                // Form submission validation
                document.getElementById('uploadForm').addEventListener('submit', function(e) {
                    if (!fileInput.files.length) {
                        e.preventDefault();
                        alert('Please select a CSV file first.');
                        return;
                    }
                    
                    const file = fileInput.files[0];
                    const maxSize = 10 * 1024 * 1024; // 10MB
                    
                    if (file.size > maxSize) {
                        e.preventDefault();
                        alert('File size exceeds 10MB limit. Please upload a smaller file.');
                        return;
                    }
                    
                    // Change button text to indicate processing
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> Processing...';
                    submitBtn.disabled = true;
                });
                
                // Click drop zone to trigger file input
                dropZone.addEventListener('click', function() {
                    fileInput.click();
                });
            });
        </script>
    </body>
    </html>
    <?php
}
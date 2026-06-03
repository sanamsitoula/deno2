
    <?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';
redirect_if_not_logged_in();

$currentUserId = $_SESSION['user_id'] ?? 0;

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/includes/header.php';

// Initialize variables
$departments = [];
$edit_department = null;
$success_message = '';
$error_message = '';

// Handle form submissions
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    
    try {
        switch ($action) {
            case 'create':
                $stmt = $conn->prepare("
                    INSERT INTO department (name, sub_department_name, status, remarks, display_order, is_technical) 
                    VALUES (:name, :sub_department_name, :status, :remarks, :display_order, :is_technical)
                ");
                
                $stmt->execute([
                    ':name' => $_POST['name'],
                    ':sub_department_name' => $_POST['sub_department_name'] ?? null,
                    ':status' => isset($_POST['status']) ? 1 : 0,
                    ':remarks' => $_POST['remarks'] ?? null,
                    ':display_order' => $_POST['display_order'] ?? 0,
                    ':is_technical' => isset($_POST['is_technical']) ? 1 : 0
                ]);
                
                $success_message = 'Department added successfully!';
                break;
                
            case 'update':
                $stmt = $conn->prepare("
                    UPDATE department SET 
                        name = :name, 
                        sub_department_name = :sub_department_name, 
                        status = :status, 
                        remarks = :remarks, 
                        display_order = :display_order, 
                        is_technical = :is_technical
                    WHERE id = :id
                ");
                
                $stmt->execute([
                    ':id' => $_POST['id'],
                    ':name' => $_POST['name'],
                    ':sub_department_name' => $_POST['sub_department_name'] ?? null,
                    ':status' => isset($_POST['status']) ? 1 : 0,
                    ':remarks' => $_POST['remarks'] ?? null,
                    ':display_order' => $_POST['display_order'] ?? 0,
                    ':is_technical' => isset($_POST['is_technical']) ? 1 : 0
                ]);
                
                $success_message = 'Department updated successfully!';
                break;
                
            case 'delete':
                $stmt = $conn->prepare("DELETE FROM department WHERE id = :id");
                $stmt->execute([':id' => $_POST['id']]);
                
                $success_message = 'Department deleted successfully!';
                break;
        }
    } catch (PDOException $e) {
        $error_message = 'Error: ' . $e->getMessage();
    }
}

// Check if we're editing a department
if (isset($_GET['edit_id'])) {
    $stmt = $conn->prepare("SELECT * FROM department WHERE id = :id");
    $stmt->execute([':id' => $_GET['edit_id']]);
    $edit_department = $stmt->fetch(PDO::FETCH_ASSOC);
}

// Fetch all departments
$stmt = $conn->query("SELECT * FROM department ORDER BY display_order, name");
$departments = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<style>
        body {
            font-size: 16px;
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            margin: 0;
            padding: 20px;
            background-color: #f5f5f5;
        }

        .container {
            max-width: 1200px;
            margin: 0 auto;
        }

        .page-title {
            color: #333;
            margin-bottom: 20px;
            padding-bottom: 10px;
            border-bottom: 2px solid #007bff;
        }

        .form-container {
            background: #f8f9fa;
            padding: 20px;
            border-radius: 8px;
            margin-bottom: 30px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
        }

        .form-row {
            display: flex;
            gap: 15px;
            margin-bottom: 15px;
            align-items: end;
            flex-wrap: wrap;
        }

        .form-group {
            flex: 1;
            min-width: 200px;
        }

        .form-group label {
            display: block;
            margin-bottom: 5px;
            font-weight: 600;
            color: #333;
            font-size: 15px;
        }

        .form-control {
            width: 100%;
            padding: 10px 14px;
            border: 1px solid #ddd;
            border-radius: 4px;
            font-size: 15px;
            box-sizing: border-box;
        }

        .form-control:focus {
            outline: none;
            border-color: #007bff;
            box-shadow: 0 0 0 2px rgba(0,123,255,.25);
        }

        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 4px;
            cursor: pointer;
            font-size: 15px;
            font-weight: 600;
            text-decoration: none;
            display: inline-block;
            text-align: center;
            margin-right: 8px;
            transition: all 0.3s;
        }

        .btn-primary {
            background-color: #007bff;
            color: white;
        }

        .btn-primary:hover {
            background-color: #0069d9;
        }

        .btn-secondary {
            background-color: #6c757d;
            color: white;
        }

        .btn-secondary:hover {
            background-color: #5a6268;
        }

        .btn-success {
            background-color: #28a745;
            color: white;
        }

        .btn-success:hover {
            background-color: #218838;
        }

        .btn-warning {
            background-color: #ffc107;
            color: #212529;
        }

        .btn-warning:hover {
            background-color: #e0a800;
        }

        .btn-danger {
            background-color: #dc3545;
            color: white;
        }

        .btn-danger:hover {
            background-color: #c82333;
        }

        .btn-info {
            background-color: #17a2b8;
            color: white;
        }

        .btn-info:hover {
            background-color: #138496;
        }

        .btn-sm {
            padding: 6px 12px;
            font-size: 13px;
            margin-right: 4px;
        }

        .action-buttons {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
            align-items: center;
            flex-wrap: wrap;
        }

        .table-container {
            background: white;
            border-radius: 8px;
            overflow: hidden;
            box-shadow: 0 2px 4px rgba(0,0,0,0.1);
            overflow-x: auto;
        }

        .table {
            width: 100%;
            border-collapse: collapse;
            font-size: 14px;
            min-width: 800px;
        }

        .table th,
        .table td {
            padding: 10px 8px;
            text-align: left;
            border-bottom: 1px solid #dee2e6;
            vertical-align: middle;
        }

        .table th {
            background-color: #f8f9fa;
            font-weight: 700;
            color: #495057;
            font-size: 13px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            position: sticky;
            top: 0;
        }

        .table tbody tr:hover {
            background-color: #f5f5f5;
        }

        .table tbody tr:nth-child(even) {
            background-color: #fafafa;
        }

        .alert {
            padding: 15px 20px;
            margin-bottom: 20px;
            border: 1px solid transparent;
            border-radius: 4px;
            font-size: 15px;
            font-weight: 500;
        }

        .alert-success {
            color: #155724;
            background-color: #d4edda;
            border-color: #c3e6cb;
        }

        .alert-danger {
            color: #721c24;
            background-color: #f8d7da;
            border-color: #f5c6cb;
        }

        .status-active {
            color: #28a745;
            font-weight: 600;
        }

        .status-inactive {
            color: #dc3545;
            font-weight: 600;
        }

        .search-container {
            margin-bottom: 20px;
            display: flex;
            gap: 10px;
        }

        .search-input {
            flex: 1;
            max-width: 300px;
        }

        /* Checkbox styling */
        .checkbox-group {
            display: flex;
            align-items: center;
            gap: 8px;
        }

        .checkbox-group input[type="checkbox"] {
            width: 18px;
            height: 18px;
        }

        /* Print Styles */
        @media print {
            body {
                font-size: 11px;
                line-height: 1.2;
                padding: 0;
            }
            
            .form-container,
            .action-buttons,
            .btn,
            .search-container {
                display: none !important;
            }
            
            .page-title {
                font-size: 18px;
                margin: 10px 0;
                border: none;
            }
            
            .table {
                font-size: 9px;
                width: 100%;
            }
            
            .table th,
            .table td {
                padding: 3px 2px;
                border: 1px solid #000;
                font-size: 8px;
            }
            
            .table th {
                background-color: #f0f0f0 !important;
                font-weight: bold;
            }
            
            .table-container {
                box-shadow: none;
                border: 1px solid #000;
            }
            
            .alert {
                display: none !important;
            }
            
            @page {
                margin: 0.5in;
                size: landscape;
            }
        }

        /* Responsive adjustments */
        @media (max-width: 768px) {
            .form-row {
                flex-direction: column;
                gap: 10px;
            }
            
            .form-group {
                width: 100%;
            }
            
            .action-buttons {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>

    <div class="container">
        <h1 class="page-title">Department Management</h1>
        
        <?php if (isset($success_message)): ?>
            <div class="alert alert-success"><?= $success_message ?></div>
        <?php endif; ?>
        
        <?php if (isset($error_message)): ?>
            <div class="alert alert-danger"><?= $error_message ?></div>
        <?php endif; ?>
        
        <div class="form-container">
            <h2><?= isset($edit_department) ? 'Edit Department' : 'Add New Department' ?></h2>
            <form method="post" id="departmentForm">
                <input type="hidden" name="action" value="<?= isset($edit_department) ? 'update' : 'create' ?>">
                <?php if (isset($edit_department)): ?>
                    <input type="hidden" name="id" value="<?= $edit_department['id'] ?>">
                <?php endif; ?>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="name">Department Name:</label>
                        <input type="text" name="name" id="name" class="form-control" 
                               value="<?= $edit_department['name'] ?? '' ?>" required>
                    </div>
                    
                    <div class="form-group">
                        <label for="sub_department_name">Sub-Department Name:</label>
                        <input type="text" name="sub_department_name" id="sub_department_name" class="form-control" 
                               value="<?= $edit_department['sub_department_name'] ?? '' ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label for="display_order">Display Order:</label>
                        <input type="number" name="display_order" id="display_order" class="form-control" 
                               value="<?= $edit_department['display_order'] ?? '0' ?>">
                    </div>
                    
                    <div class="form-group">
                        <label for="remarks">Remarks:</label>
                        <input type="text" name="remarks" id="remarks" class="form-control" 
                               value="<?= $edit_department['remarks'] ?? '' ?>">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="status" id="status" value="1" 
                            <?= (!isset($edit_department) || $edit_department['status']) ? 'checked' : '' ?>>
                        <label for="status">Active</label>
                    </div>
                    
                    <div class="form-group checkbox-group">
                        <input type="checkbox" name="is_technical" id="is_technical" value="1" 
                            <?= (isset($edit_department) && $edit_department['is_technical']) ? 'checked' : '' ?>>
                        <label for="is_technical">Technical Department</label>
                    </div>
                    
                    <div class="form-group">
                        <button type="submit" class="btn btn-primary">
                            <?= isset($edit_department) ? 'Update Department' : 'Add Department' ?>
                        </button>
                        <?php if (isset($edit_department)): ?>
                            <a href="index.php" class="btn btn-secondary">Cancel</a>
                        <?php endif; ?>
                    </div>
                </div>
            </form>
        </div>
        
        <div class="action-buttons">
            <button onclick="window.print()" class="btn btn-info">🖨️ Print</button>
            <button onclick="downloadCSV()" class="btn btn-success">📥 Download CSV</button>
            <div class="search-container">
                <input type="text" id="searchInput" class="form-control search-input" placeholder="Search departments...">
                <button onclick="filterTable()" class="btn btn-primary">Search</button>
                <button onclick="clearSearch()" class="btn btn-secondary">Clear</button>
            </div>
        </div>
        
        <div class="table-container">
            <table class="table" id="departmentTable">
                <thead>
                    <tr>
                        <th>ID</th>
                        <th>Department Name</th>
                        <th>Sub-Department</th>
                        <th>Status</th>
                        <th>Technical</th>
                        <th>Display Order</th>
                        <th>Remarks</th>
                        <th>Actions</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($departments as $dept): ?>
                    <tr>
                        <td><?= $dept['id'] ?></td>
                        <td><?= htmlspecialchars($dept['name']) ?></td>
                        <td><?= htmlspecialchars($dept['sub_department_name'] ?? '') ?></td>
                        <td>
                            <span class="<?= $dept['status'] ? 'status-active' : 'status-inactive' ?>">
                                <?= $dept['status'] ? 'Active' : 'Inactive' ?>
                            </span>
                        </td>
                        <td><?= $dept['is_technical'] ? 'Yes' : 'No' ?></td>
                        <td><?= $dept['display_order'] ?></td>
                        <td><?= htmlspecialchars($dept['remarks'] ?? '') ?></td>
                        <td>
                            <a href="index.php?edit_id=<?= $dept['id'] ?>" class="btn btn-warning btn-sm">Edit</a>
                            <form method="post" style="display: inline;" 
                                  onsubmit="return confirm('Are you sure you want to delete this department?')">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="id" value="<?= $dept['id'] ?>">
                                <button type="submit" class="btn btn-danger btn-sm">Delete</button>
                            </form>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>

    <script>
        // Search/filter functionality
        function filterTable() {
            const input = document.getElementById('searchInput');
            const filter = input.value.toLowerCase();
            const table = document.getElementById('departmentTable');
            const tr = table.getElementsByTagName('tr');
            
            for (let i = 1; i < tr.length; i++) {
                let td = tr[i].getElementsByTagName('td');
                let found = false;
                
                for (let j = 0; j < td.length; j++) {
                    if (td[j]) {
                        if (td[j].textContent.toLowerCase().indexOf(filter) > -1) {
                            found = true;
                            break;
                        }
                    }
                }
                
                tr[i].style.display = found ? '' : 'none';
            }
        }
        
        function clearSearch() {
            document.getElementById('searchInput').value = '';
            filterTable();
        }
        
        // CSV Download Function
        function downloadCSV() {
            const table = document.getElementById('departmentTable');
            const rows = Array.from(table.querySelectorAll('tr'));
            
            let csvContent = "data:text/csv;charset=utf-8,";
            
            rows.forEach(row => {
                const cols = Array.from(row.querySelectorAll('th, td'));
                const csvRow = cols.map(col => {
                    let cellData = col.textContent.trim();
                    // Remove action buttons from CSV
                    if (cellData.includes('Edit') && cellData.includes('Delete')) {
                        cellData = '';
                    }
                    // Escape quotes and wrap in quotes if contains comma
                    if (cellData.includes(',') || cellData.includes('"')) {
                        cellData = '"' + cellData.replace(/"/g, '""') + '"';
                    }
                    return cellData;
                }).join(',');
                csvContent += csvRow + "\r\n";
            });
            
            const encodedUri = encodeURI(csvContent);
            const link = document.createElement("a");
            link.setAttribute("href", encodedUri);
            link.setAttribute("download", "departments_" + new Date().toISOString().split('T')[0] + ".csv");
            document.body.appendChild(link);
            link.click();
            document.body.removeChild(link);
        }
        
        // Allow pressing Enter to search
        document.getElementById('searchInput').addEventListener('keypress', function(e) {
            if (e.key === 'Enter') {
                filterTable();
            }
        });
    </script>

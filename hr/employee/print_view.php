<?php
ob_clean();

// Re-run the same query as in employees.php
$search = $_GET['search'] ?? '';
$dept = $_GET['department_id'] ?? '';
$desig = $_GET['designation_id'] ?? '';
$level = $_GET['level_id'] ?? '';
$type = $_GET['emp_type'] ?? '';

$sql = "SELECT e.*, d.name as department_name, des.name as designation_name, l.name as level_name
        FROM employee e
        LEFT JOIN department d ON e.department_id = d.id
        LEFT JOIN designation des ON e.designation_id = des.id
        LEFT JOIN level l ON e.level_id = l.id
        WHERE e.emp_status = 'ACTIVE'";

if ($search) $sql .= " AND (e.name ILIKE :search OR e.code ILIKE :search OR e.email ILIKE :search)";
if ($dept) $sql .= " AND e.department_id = :department_id";
if ($desig) $sql .= " AND e.designation_id = :designation_id";
if ($level) $sql .= " AND e.level_id = :level_id";
if ($type) $sql .= " AND e.emp_type = :emp_type";
$sql .= " ORDER BY e.name";

$stmt = $conn->prepare($sql);
if ($search) $stmt->bindValue(':search', "%$search%", PDO::PARAM_STR);
if ($dept) $stmt->bindValue(':department_id', $dept, PDO::PARAM_INT);
if ($desig) $stmt->bindValue(':designation_id', $desig, PDO::PARAM_INT);
if ($level) $stmt->bindValue(':level_id', $level, PDO::PARAM_INT);
if ($type) $stmt->bindValue(':emp_type', $type, PDO::PARAM_STR);
$stmt->execute();
$employees = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html>
<head>
    <title>Employee Report</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        table { width: 100%; border-collapse: collapse; margin: 20px 0; }
        th, td { padding: 8px; border: 1px solid #ccc; text-align: left; }
        th { background-color: #f0f0f0; font-weight: bold; }
        .header { text-align: center; margin-bottom: 20px; }
        .header h2 { margin: 0; color: #333; }
        .footer { text-align: center; margin-top: 30px; color: #666; font-size: 0.9em; }
        .summary { margin-bottom: 20px; display: flex; justify-content: space-around; }
        .summary div { padding: 10px; border: 1px solid #ddd; border-radius: 5px; width: 30%; text-align: center; }
    </style>
</head>
<body>
    <div class="header">
        <h2>Employee Directory</h2>
        <p>Generated on: <?= date('F j, Y') ?></p>
    </div>

    <div class="summary">
        <div><strong>Total Employees:</strong> <?= count($employees) ?></div>
        <div><strong>Permanent:</strong> <?= count(array_filter($employees, fn($e) => $e['emp_type'] === 'PERMANENT')) ?></div>
        <div><strong>Contract:</strong> <?= count(array_filter($employees, fn($e) => $e['emp_type'] === 'CONTRACT')) ?></div>
    </div>

    <table>
        <thead>
            <tr>
                <th>Code</th>
                <th>Name</th>
                <th>Department</th>
                <th>Designation</th>
                <th>Level</th>
                <th>Type</th>
                <th>Email</th>
                <th>Mobile</th>
                <th>Join Date</th>
            </tr>
        </thead>
        <tbody>
            <?php foreach ($employees as $e): ?>
            <tr>
                <td><?= htmlspecialchars($e['code']) ?></td>
                <td><?= htmlspecialchars($e['name']) ?></td>
                <td><?= htmlspecialchars($e['department_name'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($e['designation_name'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($e['level_name'] ?? 'N/A') ?></td>
                <td><?= htmlspecialchars($e['emp_type']) ?></td>
                <td><?= htmlspecialchars($e['email'] ?? '') ?></td>
                <td><?= htmlspecialchars($e['mobile_number'] ?? '') ?></td>
                <td><?= htmlspecialchars($e['join_date'] ?? '') ?></td>
            </tr>
            <?php endforeach; ?>
        </tbody>
    </table>

    <div class="footer">
        <p>© <?= date('Y') ?> HR Management System. All rights reserved.</p>
    </div>
</body>
</html>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

header('Content-Type: application/json');

$date_nep = $_GET['date'] ?? null;

if (!$date_nep) {
    echo json_encode(['error' => 'Date parameter required']);
    exit();
}

try {
    $stmt = $conn->prepare("
        SELECT h.holiday_name, ht.type_name, ht.color_code, ht.is_paid
        FROM holidays h
        JOIN holiday_types ht ON h.holiday_type_id = ht.id
        WHERE h.holiday_date_nep = :date_nep 
        AND h.is_active = true
        LIMIT 1
    ");
    
    $stmt->execute([':date_nep' => $date_nep]);
    $holiday = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($holiday) {
        echo json_encode([
            'is_holiday' => true,
            'holiday_name' => $holiday['holiday_name'],
            'type_name' => $holiday['type_name'],
            'color_code' => $holiday['color_code'],
            'is_paid' => $holiday['is_paid']
        ]);
    } else {
        echo json_encode(['is_holiday' => false]);
    }
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
?>

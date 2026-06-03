<?php
// API to get current fuel price
// Usage: get_fuel_price.php?fuel=petrol&date=2024-08-30

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

try {
    $fuel_type = $_GET['fuel'] ?? null;
    $date = $_GET['date'] ?? date('Y-m-d');
    
    if (!$fuel_type) {
        echo json_encode(['error' => 'Fuel type is required']);
        exit;
    }
    
    // Validate fuel type
    $valid_types = ['petrol', 'diesel', 'mobil'];
    if (!in_array($fuel_type, $valid_types)) {
        echo json_encode(['error' => 'Invalid fuel type']);
        exit;
    }
    
    // Get current price from database
    $stmt = $conn->prepare("
        SELECT rate_per_liter, effective_from_date_nep, effective_from_date_eng
        FROM fuel_price_history
        WHERE fuel_type = :fuel_type
          AND effective_from_date_eng <= :date
          AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= :date)
          AND is_active = TRUE
          AND deleted_at IS NULL
        ORDER BY effective_from_date_eng DESC
        LIMIT 1
    ");
    
    $stmt->execute([
        ':fuel_type' => $fuel_type,
        ':date' => $date
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'success' => true,
            'fuel_type' => $fuel_type,
            'price' => (float)$result['rate_per_liter'],
            'effective_from_nep' => $result['effective_from_date_nep'],
            'effective_from_eng' => $result['effective_from_date_eng']
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'No price found for this date',
            'fuel_type' => $fuel_type,
            'price' => 0
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>

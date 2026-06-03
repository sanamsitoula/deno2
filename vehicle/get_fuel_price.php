<?php
// API to get fuel price for a given fuel type and date.
// Usage: get_fuel_price.php?fuel=petrol&date=2024-08-30
//
// FIX: Removed the `is_active = TRUE` filter.
// When a new price is added, the previous price row gets is_active=FALSE
// and its effective_to_date_eng is set to the new price's start date.
// If the requested date falls within the OLD price's range, the old row
// has is_active=FALSE — so filtering by is_active would miss it entirely.
// The correct approach is to match purely on the effective date range.

header('Content-Type: application/json');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

try {
    $fuel_type = $_GET['fuel'] ?? null;
    $date      = $_GET['date'] ?? date('Y-m-d');
    
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
    
    // Validate date format
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
        echo json_encode(['error' => 'Invalid date format. Use YYYY-MM-DD']);
        exit;
    }

    /*
     * Match price by date range only — do NOT filter by is_active.
     * Logic:
     *   effective_from_date_eng <= requested_date
     *   AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= requested_date)
     * Order by effective_from_date_eng DESC so the most-specific (latest-starting)
     * price that covers the date wins.
     */
    $stmt = $conn->prepare("
        SELECT rate_per_liter, effective_from_date_nep, effective_from_date_eng, is_active
        FROM fuel_price_history
        WHERE fuel_type = :fuel_type
          AND effective_from_date_eng <= :date
          AND (effective_to_date_eng IS NULL OR effective_to_date_eng >= :date)
          AND deleted_at IS NULL
        ORDER BY effective_from_date_eng DESC
        LIMIT 1
    ");
    
    $stmt->execute([
        ':fuel_type' => $fuel_type,
        ':date'      => $date
    ]);
    
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($result) {
        echo json_encode([
            'success'            => true,
            'fuel_type'          => $fuel_type,
            'price'              => (float)$result['rate_per_liter'],
            'effective_from_nep' => $result['effective_from_date_nep'],
            'effective_from_eng' => $result['effective_from_date_eng']
        ]);
    } else {
        echo json_encode([
            'success'   => false,
            'message'   => 'No price found for this date',
            'fuel_type' => $fuel_type,
            'price'     => 0
        ]);
    }
    
} catch (Exception $e) {
    echo json_encode([
        'error' => $e->getMessage()
    ]);
}
?>

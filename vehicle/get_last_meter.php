<?php
/**
 * AJAX endpoint: returns the most recent (non-deleted) end_meter for a
 * given vehicle, so the create/edit form can pull it live at the moment
 * a vehicle is selected instead of relying on a snapshot taken when the
 * page first loaded.
 *
 * GET /vehicle/get_last_meter.php?vehicle_id=43
 * -> {"vehicle_id":43,"end_meter":243530,"log_date_eng":"2083-04-01","found":true}
 * -> {"vehicle_id":43,"end_meter":null,"log_date_eng":null,"found":false}
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

redirect_if_not_logged_in();

header('Content-Type: application/json');

$vehicle_id = filter_input(INPUT_GET, 'vehicle_id', FILTER_VALIDATE_INT);
if (!$vehicle_id) {
    http_response_code(400);
    echo json_encode(['error' => 'vehicle_id is required and must be an integer']);
    exit;
}

$stmt = $conn->prepare("
    SELECT end_meter, log_date_eng, log_date_nep
    FROM vehicle_daily_logs
    WHERE vehicle_id = :vehicle_id AND deleted_at IS NULL
    ORDER BY log_date_eng DESC, log_id DESC
    LIMIT 1
");
$stmt->execute([':vehicle_id' => $vehicle_id]);
$row = $stmt->fetch(PDO::FETCH_ASSOC);

if ($row) {
    echo json_encode([
        'vehicle_id'   => $vehicle_id,
        'end_meter'    => (int)$row['end_meter'],
        'log_date_eng' => $row['log_date_eng'],
        'log_date_nep' => $row['log_date_nep'],
        'found'        => true,
    ]);
} else {
    echo json_encode([
        'vehicle_id'   => $vehicle_id,
        'end_meter'    => null,
        'log_date_eng' => null,
        'log_date_nep' => null,
        'found'        => false,
    ]);
}

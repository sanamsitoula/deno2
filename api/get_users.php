<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET');
header('Access-Control-Allow-Headers: Content-Type');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

try {
    // Get users with specific roles for dropdowns
    $stmt = $conn->prepare("
        SELECT id, username, role 
        FROM users 
        WHERE role IN ('operator', 'supervisor', 'incharge', 'marketing')
        ORDER BY username ASC
    ");
    
    $stmt->execute();
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize users by role
    $result = [
        'all_users' => $users,
        'operators_supervisors_incharge' => [],
        'marketing' => []
    ];
    
    foreach ($users as $user) {
        if (in_array($user['role'], ['operator', 'supervisor', 'incharge'])) {
            $result['operators_supervisors_incharge'][] = $user;
        }
        if ($user['role'] === 'marketing') {
            $result['marketing'][] = $user;
        }
    }
    
    echo json_encode([
        'success' => true,
        'data' => $result
    ]);
    
} catch (PDOException $e) {
    echo json_encode([
        'success' => false,
        'message' => 'Database error: ' . $e->getMessage()
    ]);
}
?>
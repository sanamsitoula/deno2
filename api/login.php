<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST');
header('Access-Control-Allow-Headers: Content-Type');

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit;
}

$username = isset($_POST['username']) ? trim($_POST['username']) : '';
$password = isset($_POST['password']) ? trim($_POST['password']) : '';

if (empty($username) || empty($password)) {
    echo json_encode(['success' => false, 'message' => 'Username and password are required']);
    exit;
}

try {
    // ✅ Cast role::text to handle PostgreSQL enum
    $stmt = $conn->prepare("SELECT id, username, password_hash, role::text FROM users WHERE username = :username");
    $stmt->execute([':username' => $username]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && password_verify($password, $user['password_hash'])) {
        // Update last login
        $updateStmt = $conn->prepare("UPDATE users SET last_login = CURRENT_TIMESTAMP WHERE id = :id");
        $updateStmt->execute([':id' => $user['id']]);
        
        // Remove sensitive data
        unset($user['password_hash']);
        
        echo json_encode([
            'success' => true,
            'message' => 'Login successful',
            'user' => $user  // Now includes: id, username, role (as string)
        ]);
    } else {
        echo json_encode([
            'success' => false,
            'message' => 'Invalid username or password'
        ]);
    }
} catch (PDOException $e) {
    error_log("Login Error: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => 'Authentication failed'
    ]);
}
?>
<?php
// create_admin.php - Run this once to create initial admin user
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

$username = 'Jitendra';
$password = 'Jitendra@321'; // Change this!
$role = 'admin';

$password_hash = hash_password($password);

try {
    $stmt = $conn->prepare("
        INSERT INTO users (username, password_hash, role) 
        VALUES (:username, :password_hash, :role)
    ");
    $stmt->execute([
        ':username' => $username,
        ':password_hash' => $password_hash,
        ':role' => $role
    ]);
    
    echo "Admin user created successfully!";
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
?>
<?php
/**
 * API Authentication and Base Configuration
 * File: /deno2/api/auth.php
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/database.php';


class APIAuth {
    private $conn;
    private $secret_key = "your_secret_key_here_change_this"; // Change this!
    
    public function __construct($db) {
        $this->conn = $db;
    }
    
    /**
     * Login and generate token
     */
    public function login($username, $password) {
        try {
            $stmt = $this->conn->prepare("
                SELECT id, username, password_hash, role 
                FROM users 
                WHERE username = :username
            ");
            $stmt->execute([':username' => $username]);
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user || !password_verify($password, $user['password_hash'])) {
                return [
                    'success' => false,
                    'message' => 'Invalid username or password'
                ];
            }
            
            // Check if user has required role
            $allowed_roles = ['incharge', 'supervisor', 'admin', 'operator'];
            if (!in_array($user['role'], $allowed_roles)) {
                return [
                    'success' => false,
                    'message' => 'Unauthorized role for this application'
                ];
            }
            
            // Update last login
            $updateStmt = $this->conn->prepare("
                UPDATE users 
                SET last_login = NOW() 
                WHERE id = :id
            ");
            $updateStmt->execute([':id' => $user['id']]);
            
            // Generate token
            $token = $this->generateToken($user['id'], $user['username'], $user['role']);
            
            return [
                'success' => true,
                'message' => 'Login successful',
                'data' => [
                    'user_id' => $user['id'],
                    'username' => $user['username'],
                    'role' => $user['role'],
                    'token' => $token
                ]
            ];
            
        } catch (Exception $e) {
            return [
                'success' => false,
                'message' => 'Login error: ' . $e->getMessage()
            ];
        }
    }
    
    /**
     * Generate JWT-like token
     */
    private function generateToken($userId, $username, $role) {
        $header = base64_encode(json_encode(['alg' => 'HS256', 'typ' => 'JWT']));
        
        $payload = base64_encode(json_encode([
            'user_id' => $userId,
            'username' => $username,
            'role' => $role,
            'exp' => time() + (7 * 24 * 60 * 60) // 7 days
        ]));
        
        $signature = base64_encode(hash_hmac('sha256', "$header.$payload", $this->secret_key, true));
        
        return "$header.$payload.$signature";
    }
    
    /**
     * Verify token and return user data
     */
    public function verifyToken($token) {
        if (!$token) {
            return null;
        }
        
        $parts = explode('.', $token);
        if (count($parts) !== 3) {
            return null;
        }
        
        list($header, $payload, $signature) = $parts;
        
        // Verify signature
        $validSignature = base64_encode(hash_hmac('sha256', "$header.$payload", $this->secret_key, true));
        if ($signature !== $validSignature) {
            return null;
        }
        
        // Decode payload
        $data = json_decode(base64_decode($payload), true);
        
        // Check expiration
        if ($data['exp'] < time()) {
            return null;
        }
        
        return $data;
    }
    
    /**
     * Get token from request headers
     */
    public function getTokenFromHeaders() {
        $headers = getallheaders();
        
        if (isset($headers['Authorization'])) {
            $auth = $headers['Authorization'];
            if (preg_match('/Bearer\s+(.*)$/i', $auth, $matches)) {
                return $matches[1];
            }
        }
        
        return null;
    }
    
    /**
     * Require authentication for API endpoint
     */
    public function requireAuth() {
        $token = $this->getTokenFromHeaders();
        $user = $this->verifyToken($token);
        
        if (!$user) {
            http_response_code(401);
            echo json_encode([
                'success' => false,
                'message' => 'Unauthorized. Invalid or expired token.'
            ]);
            exit();
        }
        
        return $user;
    }
    
    /**
     * Check if user has required role
     */
    public function hasRole($user, $allowedRoles) {
        if (!is_array($allowedRoles)) {
            $allowedRoles = [$allowedRoles];
        }
        
        return in_array($user['role'], $allowedRoles);
    }
    
    /**
     * Send JSON response
     */
    public static function sendResponse($data, $statusCode = 200) {
        http_response_code($statusCode);
        echo json_encode($data, JSON_PRETTY_PRINT);
        exit();
    }
    
    /**
     * Send error response
     */
    public static function sendError($message, $statusCode = 400) {
        self::sendResponse([
            'success' => false,
            'message' => $message
        ], $statusCode);
    }
    
    /**
     * Send success response
     */
    public static function sendSuccess($data, $message = 'Success') {
        self::sendResponse([
            'success' => true,
            'message' => $message,
            'data' => $data
        ]);
    }
}

// Handle login request
if ($_SERVER['REQUEST_METHOD'] === 'POST' && 
    $_SERVER['REQUEST_URI'] === '/deno2/api/auth.php') {
    
    $input = json_decode(file_get_contents('php://input'), true);
    
    if (!isset($input['username']) || !isset($input['password'])) {
        APIAuth::sendError('Username and password are required');
    }
    
    $auth = new APIAuth($conn);
    $result = $auth->login($input['username'], $input['password']);
    
    if ($result['success']) {
        APIAuth::sendSuccess($result['data'], $result['message']);
    } else {
        APIAuth::sendError($result['message'], 401);
    }
}
<?php
/**
 * Shared bootstrap for all /api/v1/ endpoints.
 * Include this as the first line of every versioned API file.
 *
 * Usage in an endpoint:
 *   require_once __DIR__ . '/_middleware.php';
 *   // $db is available as a PDO instance
 *   // Auth, Response, Validator classes are autoloaded
 */

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, PUT, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit();
}

require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/bootstrap.php';

use Administrator\Deno2\Core\{Database, Response};

$db = Database::getConnection();

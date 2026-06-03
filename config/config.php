<?php
// config/config.php
define('BASE_URL', 'http://localhost/deno2');
define('BASE_PATH', '/deno2');

// Function to generate absolute URLs
function url($path = '') {
    return BASE_URL . '/' . ltrim($path, '/');
}

// Function to generate absolute paths for includes
function path($path = '') {
    return $_SERVER['DOCUMENT_ROOT'] . BASE_PATH . '/' . ltrim($path, '/');
}
?>
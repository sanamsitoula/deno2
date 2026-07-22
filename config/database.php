<?php
// Polyfills for PHP 8.0+ string functions — this server runs PHP 7.4
if (!function_exists('str_contains')) {
    function str_contains(string $haystack, string $needle): bool {
        return $needle === '' || strpos($haystack, $needle) !== false;
    }
}
if (!function_exists('str_starts_with')) {
    function str_starts_with(string $haystack, string $needle): bool {
        return strncmp($haystack, $needle, strlen($needle)) === 0;
    }
}
if (!function_exists('str_ends_with')) {
    function str_ends_with(string $haystack, string $needle): bool {
        return $needle === '' || substr($haystack, -strlen($needle)) === $needle;
    }
}

$host = "localhost";
$port = "5432";
$dbname = "press_jemc";
$user = "postgres";
$password = "Nepal@123";

try {
    $conn = new PDO("pgsql:host=$host;port=$port;dbname=$dbname", $user, $password);
    $conn->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
      $db_status = true;
} catch (PDOException $e) {
     $db_status = false;
    $db_error = $e->getMessage();
    die("Connection failed: " . $e->getMessage());
}
?>


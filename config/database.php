<?php
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


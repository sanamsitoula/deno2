<?php

namespace Administrator\Deno2\Core;

use PDO;

class Database
{
    private static ?PDO $instance = null;

    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            // Reuse the $conn created by config/database.php if already set
            global $conn;
            if ($conn instanceof PDO) {
                self::$instance = $conn;
            } else {
                global $host, $port, $dbname, $user, $password;
                self::$instance = new PDO(
                    "pgsql:host=$host;port=$port;dbname=$dbname",
                    $user,
                    $password,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    ]
                );
            }
        }
        return self::$instance;
    }

    // Prevent instantiation
    private function __construct() {}
    private function __clone() {}
}

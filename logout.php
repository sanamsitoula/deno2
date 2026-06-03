<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

session_destroy();
header("Location: /deno2/login.php");
exit();
?>
<?php
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/auth.php';

session_destroy();
header("Location: " . detect_deno2_base_url() . "/login.php");
exit();
?>
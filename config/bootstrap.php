<?php
// Single entry point for all pages. Replace scattered require_once chains with:
//   require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/bootstrap.php';

$_docRoot = $_SERVER['DOCUMENT_ROOT'] ?? dirname(__DIR__, 2);

require_once $_docRoot . '/deno2/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

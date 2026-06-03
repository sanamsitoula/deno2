<?php
/**
 * Single entry point for all pages.
 * DOCUMENT_ROOT is fixed to D:/claude_project via .htaccess auto_prepend_file.
 *
 * Usage in any page:
 *   require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/config/bootstrap.php';
 */
require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/vendor/autoload.php';
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';
require_once __DIR__ . '/auth.php';

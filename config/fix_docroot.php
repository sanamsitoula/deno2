<?php
/**
 * Auto-prepended to every PHP request via .htaccess
 * Sets DOCUMENT_ROOT to D:/claude_project so all existing
 * require_once $_SERVER['DOCUMENT_ROOT'] . '/deno2/...' paths resolve correctly.
 */
$_SERVER['DOCUMENT_ROOT'] = 'D:/claude_project';

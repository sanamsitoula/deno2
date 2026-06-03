<?php
// Authentication configuration
session_start();

function is_logged_in() {
    return isset($_SESSION['user_id']);
}

function has_role($required_role) {
    return isset($_SESSION['user_role']) && $_SESSION['user_role'] === $required_role;
}

function redirect_if_not_logged_in() {
    if (!is_logged_in()) {
        header("Location: /deno2/login.php");
        exit();
    }
}

function redirect_if_not_authorized($required_role) {
    redirect_if_not_logged_in();
    if (!has_role($required_role)) {
        header("Location: /deno2/unauthorized.php");
        exit();
    }
}

function verify_password($password, $hash) {
    return password_verify($password, $hash);
}

function hash_password($password) {
    return password_hash($password, PASSWORD_DEFAULT);
}
?>
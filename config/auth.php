<?php
// Authentication configuration
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// ── Backward-compatible flat functions ─────────────────────────────────────
// These delegate to the Auth class when the autoloader is available,
// so existing pages continue to work unchanged.

function is_logged_in(): bool {
    return isset($_SESSION['user_id']);
}

/**
 * Accepts a single role string OR an array of roles.
 * Returns true if the current user has any of them.
 *
 * @param string|string[] $required_role
 */
function has_role($required_role): bool {
    if (class_exists(\Administrator\Deno2\Core\Auth::class)) {
        return \Administrator\Deno2\Core\Auth::hasRole($required_role);
    }
    // Fallback when autoloader not yet available
    $userRole = $_SESSION['user_role'] ?? '';
    $roles    = is_array($required_role) ? $required_role : [$required_role];
    return in_array($userRole, $roles, true);
}

function redirect_if_not_logged_in(): void {
    if (!is_logged_in()) {
        header("Location: /jemc/login.php");
        exit();
    }
}

function redirect_if_not_authorized($required_role): void {
    redirect_if_not_logged_in();
    if (!has_role($required_role)) {
        header("Location: /jemc/unauthorized.php");
        exit();
    }
}

/**
 * Redirect if user cannot access the given module slug.
 * Uses the module permission matrix in Auth.
 */
function require_module(string $module): void {
    if (class_exists(\Administrator\Deno2\Core\Auth::class)) {
        \Administrator\Deno2\Core\Auth::requireModule($module);
    } else {
        redirect_if_not_logged_in();
    }
}

function can_access_module(string $module): bool {
    if (class_exists(\Administrator\Deno2\Core\Auth::class)) {
        return \Administrator\Deno2\Core\Auth::canAccess($module);
    }
    return is_logged_in();
}

function verify_password(string $password, string $hash): bool {
    return password_verify($password, $hash);
}

function hash_password(string $password): string {
    return password_hash($password, PASSWORD_DEFAULT);
}
?>
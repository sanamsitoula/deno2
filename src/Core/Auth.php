<?php

namespace Administrator\Deno2\Core;

class Auth
{
    // Which roles can access each module slug.
    // 'admin' always has access — add it to every module.
    private const MODULE_PERMISSIONS = [
        'hr'         => ['admin', 'hr'],
        'employee'   => ['admin', 'hr'],
        'attendance' => ['admin', 'hr', 'supervisor'],
        'payroll'    => ['admin', 'finance'],
        'tax'        => ['admin', 'finance'],
        'vehicle'    => ['admin', 'fleet_manager'],
        'production' => ['admin', 'production_manager'],
        'jobticket'  => ['admin', 'production_manager', 'supervisor'],
        'reports'    => ['admin', 'hr', 'finance', 'production_manager'],
        'admin'      => ['admin'],
    ];

    public static function requireLogin(): void
    {
        if (!isset($_SESSION['user_id'])) {
            header('Location: /deno2/login.php');
            exit();
        }
    }

    /**
     * Halt with redirect if the current user cannot access the given module.
     */
    public static function requireModule(string $module): void
    {
        self::requireLogin();
        if (!self::canAccess($module)) {
            header('Location: /deno2/unauthorized.php');
            exit();
        }
    }

    /**
     * Check if the current user has any of the given role(s).
     *
     * @param string|string[] $roles
     */
    public static function hasRole($roles): bool
    {
        $userRole = $_SESSION['user_role'] ?? '';
        $roles    = is_array($roles) ? $roles : [$roles];
        return in_array($userRole, $roles, true);
    }

    /**
     * Check if the current user can access a module.
     */
    public static function canAccess(string $module): bool
    {
        $userRole = $_SESSION['user_role'] ?? '';
        $allowed  = self::MODULE_PERMISSIONS[$module] ?? [];
        return in_array($userRole, $allowed, true);
    }

    /**
     * Return all module slugs the current user can access (for nav rendering).
     */
    public static function accessibleModules(): array
    {
        $userRole = $_SESSION['user_role'] ?? '';
        $modules  = [];
        foreach (self::MODULE_PERMISSIONS as $module => $roles) {
            if (in_array($userRole, $roles, true)) {
                $modules[] = $module;
            }
        }
        return $modules;
    }

    // Prevent instantiation
    private function __construct() {}
}

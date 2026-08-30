<?php
/**
 * Permission helpers.
 *
 * Permissions are loaded from the database (role_permissions) once at
 * login and cached in the session — so adding/removing a permission for
 * a role takes effect the next time that user logs in, without needing
 * a code deploy. `can()` is the single check used everywhere in the app;
 * pages/API endpoints must never hardcode `if ($role === 'staff')` logic.
 */

declare(strict_types=1);

/**
 * Fetch all permission codes for a role directly from the DB.
 * Called once at login (see auth/login.php).
 */
function loadPermissionsForRole(int $roleId): array
{
    $stmt = db()->prepare(
        'SELECT p.code
         FROM role_permissions rp
         JOIN permissions p ON p.id = rp.permission_id
         WHERE rp.role_id = :role_id'
    );
    $stmt->execute(['role_id' => $roleId]);

    return $stmt->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Check whether the currently logged-in user has a given permission code.
 */
function can(string $permissionCode): bool
{
    return in_array($permissionCode, $_SESSION['permissions'] ?? [], true);
}

/**
 * Check whether the currently logged-in user has one of the given roles.
 */
function hasRole(string ...$roles): bool
{
    return isset($_SESSION['role']) && in_array($_SESSION['role'], $roles, true);
}

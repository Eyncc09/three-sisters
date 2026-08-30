<?php
/**
 * Route guards. Call at the top of any protected page/API file, after
 * session.php and config/permissions.php are loaded.
 */

declare(strict_types=1);

/** Redirect to login if not authenticated. Call on every protected page. */
function requireLogin(): void
{
    if (!isLoggedIn()) {
        header('Location: ' . BASE_URL . '/auth/login.php');
        exit;
    }
}

/**
 * Require the logged-in user to hold one of the given roles.
 * Renders a 403 page rather than silently redirecting, so misconfigured
 * links are obvious during testing.
 */
function requireRole(string ...$roles): void
{
    requireLogin();
    if (!hasRole(...$roles)) {
        renderForbidden();
    }
}

/** Require a specific permission code (see config/permissions.php). */
function requirePermission(string $permissionCode): void
{
    requireLogin();
    if (!can($permissionCode)) {
        renderForbidden();
    }
}

function renderForbidden(): void
{
    http_response_code(403);
    echo '<!DOCTYPE html><html><head><title>Access Denied</title>'
        . '<link rel="stylesheet" href="' . BASE_URL . '/assets/css/style.css"></head><body>'
        . '<div class="auth-shell"><div class="auth-card text-center">'
        . '<h1 class="h4 mb-3">Access Denied</h1>'
        . '<p class="text-muted">You do not have permission to view this page.</p>'
        . '<a class="btn btn-primary mt-2" href="' . BASE_URL . '/index.php">Back to Dashboard</a>'
        . '</div></div></body></html>';
    exit;
}

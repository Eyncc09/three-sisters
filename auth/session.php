<?php
/**
 * Secure session bootstrap.
 * Included at the very top of every protected page and every /auth page,
 * BEFORE any output — session cookie params must be set before session_start().
 */

declare(strict_types=1);

require_once __DIR__ . '/../config/constants.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME_SECONDS,
        'path'     => '/',
        'domain'   => '',
        'secure'   => (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off'),
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

// Idle timeout — logs the user out after SESSION_LIFETIME_SECONDS of inactivity,
// independent of the cookie's own expiry.
if (isset($_SESSION['last_activity']) && (time() - $_SESSION['last_activity']) > SESSION_LIFETIME_SECONDS) {
    $_SESSION = [];
    session_destroy();
    session_start();
}
$_SESSION['last_activity'] = time();

function isLoggedIn(): bool
{
    return !empty($_SESSION['user_id']);
}

/**
 * Generates (once per session) and returns the CSRF token for state-changing forms.
 */
function csrfToken(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/**
 * Validates a submitted CSRF token using a timing-safe comparison.
 */
function csrfValidate(?string $submittedToken): bool
{
    return is_string($submittedToken)
        && !empty($_SESSION['csrf_token'])
        && hash_equals($_SESSION['csrf_token'], $submittedToken);
}

/** Renders a hidden CSRF input — echo csrfField() inside every POST form. */
function csrfField(): string
{
    return '<input type="hidden" name="csrf_token" value="' . htmlspecialchars(csrfToken(), ENT_QUOTES) . '">';
}

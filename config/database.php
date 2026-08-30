<?php
/**
 * Database connection (PDO).
 * Reads credentials from environment variables when available, falling
 * back to local XAMPP defaults so the app runs out-of-the-box in dev.
 * NEVER hardcode production credentials here — set env vars on the host.
 */

declare(strict_types=1);

function db(): PDO
{
    static $pdo = null;

    if ($pdo instanceof PDO) {
        return $pdo;
    }

    $host = getenv('DB_HOST') ?: '127.0.0.1';
    $port = getenv('DB_PORT') ?: '3306';
    $name = getenv('DB_NAME') ?: 'three_sisters';
    $user = getenv('DB_USER') ?: 'root';
    $pass = getenv('DB_PASS') ?: '';
    $charset = 'utf8mb4';

    $dsn = "mysql:host={$host};port={$port};dbname={$name};charset={$charset}";

    $options = [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false, // real prepared statements — SQLi protection
    ];

    try {
        $pdo = new PDO($dsn, $user, $pass, $options);
    } catch (PDOException $e) {
        // Never leak DB details to the browser.
        error_log('[DB CONNECTION ERROR] ' . $e->getMessage());
        http_response_code(500);
        die('A system error occurred. Please try again later or contact the administrator.');
    }

    return $pdo;
}

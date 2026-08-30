<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../core/AuditLogger.php';

if (isLoggedIn()) {
    AuditLogger::log((int) $_SESSION['user_id'], 'logout', 'auth', 'users', (int) $_SESSION['user_id']);
}

$_SESSION = [];
if (ini_get('session.use_cookies')) {
    $params = session_get_cookie_params();
    setcookie('PHPSESSID', '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
}
session_destroy();

header('Location: ' . BASE_URL . '/auth/login.php');
exit;

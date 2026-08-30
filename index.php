<?php
declare(strict_types=1);

require_once __DIR__ . '/auth/session.php';
require_once __DIR__ . '/config/constants.php';

if (!isLoggedIn()) {
    header('Location: ' . BASE_URL . '/auth/login.php');
    exit;
}

header('Location: ' . BASE_URL . ROLE_DASHBOARDS[$_SESSION['role']]);
exit;

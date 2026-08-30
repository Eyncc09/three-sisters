<?php
/**
 * Shared layout header.
 * Expects (set by the including page before requiring this file):
 *   $pageTitle  — string, shown in <title> and topbar
 *   $activeNav  — string key matching an item in sidebar.php
 * Must be included AFTER auth/session.php + middleware guard have run.
 */
$pageTitle = $pageTitle ?? APP_NAME;
$initials = '';
foreach (explode(' ', $_SESSION['full_name'] ?? '') as $part) {
    if ($part !== '') $initials .= strtoupper($part[0]);
}
$initials = substr($initials, 0, 2) ?: '?';
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= htmlspecialchars($pageTitle) ?> — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= vendorOrCdn('bootstrap/bootstrap.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= vendorOrCdn('bootstrap-icons/bootstrap-icons.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body>
<div class="app-shell">
    <?php require __DIR__ . '/sidebar.php'; ?>

    <div class="app-main">
        <header class="app-topbar">
            <div class="d-flex align-items-center gap-2">
                <button class="btn btn-light sidebar-toggle-btn" id="sidebarToggleBtn" type="button" aria-label="Toggle menu">
                    <i class="bi bi-list fs-5"></i>
                </button>
                <div class="topbar-search d-none d-md-block">
                    <div class="input-group input-group-sm">
                        <span class="input-group-text bg-white border-end-0"><i class="bi bi-search"></i></span>
                        <input type="search" class="form-control border-start-0" placeholder="Search...">
                    </div>
                </div>
            </div>

            <div class="d-flex align-items-center gap-3">
                <a href="<?= BASE_URL ?>/notifications/index.php" class="text-dark position-relative" title="Notifications">
                    <i class="bi bi-bell fs-5"></i>
                </a>
                <span class="role-pill d-none d-sm-inline-block"><?= htmlspecialchars($_SESSION['role'] ?? '') ?></span>
                <div class="dropdown">
                    <button class="btn btn-link p-0 d-flex align-items-center gap-2 text-decoration-none" type="button" data-bs-toggle="dropdown">
                        <span class="avatar-circle"><?= htmlspecialchars($initials) ?></span>
                        <span class="d-none d-md-inline text-dark small fw-semibold"><?= htmlspecialchars($_SESSION['full_name'] ?? '') ?></span>
                    </button>
                    <ul class="dropdown-menu dropdown-menu-end">
                        <li><a class="dropdown-item" href="<?= BASE_URL ?>/settings/profile.php"><i class="bi bi-person me-2"></i>My Profile</a></li>
                        <li><hr class="dropdown-divider"></li>
                        <li><a class="dropdown-item text-danger" href="<?= BASE_URL ?>/auth/logout.php"><i class="bi bi-box-arrow-right me-2"></i>Logout</a></li>
                    </ul>
                </div>
            </div>
        </header>

        <main class="app-content">

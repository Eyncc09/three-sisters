<?php
declare(strict_types=1);

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../core/AuditLogger.php';

// Already logged in? Go straight to the dashboard.
if (isLoggedIn()) {
    header('Location: ' . BASE_URL . ROLE_DASHBOARDS[$_SESSION['role']]);
    exit;
}

$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } else {
        $identifier = trim((string) ($_POST['identifier'] ?? ''));
        $password   = (string) ($_POST['password'] ?? '');

        if ($identifier === '' || $password === '') {
            $error = 'Please enter your username/email and password.';
        } else {
            $stmt = db()->prepare(
                'SELECT u.id, u.role_id, u.username, u.email, u.password_hash, u.full_name,
                        u.status, u.distributor_id, r.name AS role_name
                 FROM users u
                 JOIN roles r ON r.id = u.role_id
                 WHERE (u.username = :identifier1 OR u.email = :identifier2)
                 LIMIT 1'
            );
            $stmt->execute(['identifier1' => $identifier, 'identifier2' => $identifier]);
            $user = $stmt->fetch();

            // Same generic message whether the account doesn't exist or the
            // password is wrong — never reveal which one it was.
            if (!$user || !password_verify($password, $user['password_hash'])) {
                $error = 'Invalid username/email or password.';
                AuditLogger::log(null, 'login.failed', 'auth', 'users', $user ? (int) $user['id'] : null, ['identifier' => $identifier]);
            } elseif ($user['status'] !== 'active') {
                $error = 'This account has been deactivated. Please contact the administrator.';
            } else {
                // Prevent session fixation.
                session_regenerate_id(true);

                $_SESSION['user_id']        = (int) $user['id'];
                $_SESSION['username']       = $user['username'];
                $_SESSION['full_name']      = $user['full_name'];
                $_SESSION['role']           = $user['role_name'];
                $_SESSION['role_id']        = (int) $user['role_id'];
                $_SESSION['distributor_id'] = $user['distributor_id'] !== null ? (int) $user['distributor_id'] : null;
                $_SESSION['permissions']    = loadPermissionsForRole((int) $user['role_id']);
                $_SESSION['last_activity']  = time();

                db()->prepare('UPDATE users SET last_login_at = NOW() WHERE id = :id')
                    ->execute(['id' => $user['id']]);

                AuditLogger::log((int) $user['id'], 'login.success', 'auth', 'users', (int) $user['id']);

                header('Location: ' . BASE_URL . ROLE_DASHBOARDS[$user['role_name']]);
                exit;
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login — <?= htmlspecialchars(APP_NAME) ?></title>
    <link rel="stylesheet" href="<?= vendorOrCdn('bootstrap/bootstrap.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/twitter-bootstrap/5.3.3/css/bootstrap.min.css') ?>">
    <link rel="stylesheet" href="<?= vendorOrCdn('bootstrap-icons/bootstrap-icons.min.css', 'https://cdnjs.cloudflare.com/ajax/libs/bootstrap-icons/1.11.3/font/bootstrap-icons.min.css') ?>">
    <link rel="stylesheet" href="<?= BASE_URL ?>/assets/css/style.css">
</head>
<body class="auth-body">
    <div class="auth-shell">
        <div class="auth-card">
            <div class="auth-brand">
                <div class="auth-logo"><i class="bi bi-flower2"></i></div>
                <h1 class="auth-title"><?= htmlspecialchars(APP_NAME) ?></h1>
                <p class="auth-subtitle"><?= htmlspecialchars(APP_TAGLINE) ?></p>
            </div>

            <?php if ($error): ?>
                <div class="alert alert-danger py-2" role="alert"><?= htmlspecialchars($error) ?></div>
            <?php endif; ?>

            <form method="POST" action="" novalidate>
                <?= csrfField() ?>

                <div class="mb-3">
                    <label for="identifier" class="form-label">Username or Email</label>
                    <input type="text" class="form-control" id="identifier" name="identifier" required autofocus
                           value="<?= htmlspecialchars($_POST['identifier'] ?? '') ?>">
                </div>

                <div class="mb-3">
                    <label for="password" class="form-label">Password</label>
                    <div class="input-group">
                        <input type="password" class="form-control" id="password" name="password" required>
                        <button class="btn btn-outline-secondary" type="button" id="togglePassword" tabindex="-1">
                            <i class="bi bi-eye" id="toggleIcon"></i>
                        </button>
                    </div>
                </div>

                <div class="form-check mb-3">
                    <input class="form-check-input" type="checkbox" id="remember" name="remember">
                    <label class="form-check-label" for="remember">Remember me</label>
                </div>

                <button type="submit" class="btn btn-primary w-100 auth-submit">Log In</button>
            </form>

            <p class="auth-footnote">© <?= date('Y') ?> <?= htmlspecialchars(APP_NAME) ?>. All rights reserved.</p>
        </div>
    </div>

    <script>
        document.getElementById('togglePassword').addEventListener('click', function () {
            const pw = document.getElementById('password');
            const icon = document.getElementById('toggleIcon');
            const isHidden = pw.type === 'password';
            pw.type = isHidden ? 'text' : 'password';
            icon.classList.toggle('bi-eye');
            icon.classList.toggle('bi-eye-slash');
        });
    </script>
</body>
</html>

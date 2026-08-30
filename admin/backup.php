<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('backup.manage');

$flash = null;
$backupDir = __DIR__ . '/../storage/backups';
$success = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'backup_now') {
    if (!csrfValidate($_POST['csrf_token'] ?? null)) {
        $flash = ['type' => 'danger', 'text' => 'Your session expired. Please try again.'];
    } else {
        if (!function_exists('shell_exec')) {
            header('Location: ' . BASE_URL . '/admin/backup.php?msg=noshell');
            exit;
        }

        if (!is_dir($backupDir)) mkdir($backupDir, 0750, true);

        $filename = 'three_sisters_backup_' . date('Y-m-d_His') . '.sql';
        $filepath = $backupDir . '/' . $filename;

        $host = getenv('DB_HOST') ?: '127.0.0.1';
        $port = getenv('DB_PORT') ?: '3306';
        $name = getenv('DB_NAME') ?: 'three_sisters';
        $user = getenv('DB_USER') ?: 'root';
        $pass = getenv('DB_PASS') ?: '';

        $passArg = $pass !== '' ? '-p' . escapeshellarg($pass) : '';
        $cmd = sprintf(
            'mysqldump -h %s -P %s -u %s %s %s > %s 2>&1',
            escapeshellarg($host), escapeshellarg($port), escapeshellarg($user), $passArg,
            escapeshellarg($name), escapeshellarg($filepath)
        );

        @shell_exec($cmd);
        $success = is_file($filepath) && filesize($filepath) > 0;
        $fileSize = $success ? filesize($filepath) : null;

        db()->prepare(
            'INSERT INTO backup_logs (filename, initiated_by, status, file_size, error_message) VALUES (:fn, :uid, :status, :size, :err)'
        )->execute([
            'fn' => $filename, 'uid' => (int) $_SESSION['user_id'],
            'status' => $success ? 'success' : 'failed', 'size' => $fileSize,
            'err' => $success ? null : 'mysqldump produced no output — verify mysqldump is on the server PATH and DB credentials are correct.',
        ]);

        AuditLogger::log((int) $_SESSION['user_id'], $success ? 'backup.created' : 'backup.failed', 'admin', 'backup_logs', null, ['filename' => $filename]);

        if (!$success && is_file($filepath)) {
            @unlink($filepath);
        }

        header('Location: ' . BASE_URL . '/admin/backup.php?msg=' . ($success ? 'ok' : 'fail'));
        exit;
    }
}
if (isset($_GET['msg'])) {
    $messages = [
        'ok' => ['type' => 'success', 'text' => 'Backup completed successfully.'],
        'fail' => ['type' => 'danger', 'text' => 'Backup failed. Ensure mysqldump is installed and on the PATH (on XAMPP: mysql/bin).'],
        'noshell' => ['type' => 'danger', 'text' => 'This server does not allow shell_exec — automatic backup cannot run here. Use phpMyAdmin\'s Export feature instead.'],
    ];
    $flash = $messages[$_GET['msg']] ?? null;
}

$history = db()->query(
    "SELECT b.*, u.full_name AS initiated_by_name FROM backup_logs b LEFT JOIN users u ON u.id = b.initiated_by ORDER BY b.created_at DESC LIMIT 20"
)->fetchAll();

$pageTitle = 'Backup & Restore';
$activeNav = 'backup';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">Backup &amp; Restore</h1>
    <p class="text-muted mb-0 small">Manual database backups. No scheduled/automatic backup — trigger one whenever needed.</p>
</div>

<?php if ($flash): ?>
    <div class="alert alert-<?= $flash['type'] ?> alert-dismissible fade show py-2"><?= htmlspecialchars($flash['text']) ?><button type="button" class="btn-close" data-bs-dismiss="alert"></button></div>
<?php endif; ?>

<div class="ts-card p-3 mb-3">
    <div class="d-flex justify-content-between align-items-center flex-wrap gap-2">
        <div>
            <h2 class="h6 mb-1">Create Backup</h2>
            <p class="text-muted small mb-0">Runs <code>mysqldump</code> on the server and stores the file outside the web root's reach (blocked by <code>.htaccess</code>).</p>
        </div>
        <form method="POST" onsubmit="return confirm('Create a new database backup now?');">
            <?= csrfField() ?>
            <input type="hidden" name="action" value="backup_now">
            <button type="submit" class="btn btn-primary btn-sm"><i class="bi bi-hdd-network me-1"></i>Backup Now</button>
        </form>
    </div>
</div>

<div class="ts-card">
    <div class="p-3 pb-0"><h2 class="h6 mb-0">Backup History</h2></div>
    <?php if (!$history): ?>
        <div class="empty-state"><i class="bi bi-hdd-network"></i><p class="mb-0">No backups have been created yet.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Filename</th><th>Initiated By</th><th>Status</th><th>Size</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($history as $b): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars($b['filename']) ?></td>
                        <td class="small"><?= htmlspecialchars($b['initiated_by_name'] ?? '—') ?></td>
                        <td><span class="badge-status <?= $b['status'] === 'success' ? 'badge-success' : 'badge-danger' ?>"><?= ucfirst($b['status']) ?></span></td>
                        <td class="small"><?= $b['file_size'] ? number_format($b['file_size'] / 1024, 1) . ' KB' : '—' ?></td>
                        <td class="small text-muted"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($b['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>

<p class="text-muted mt-3" style="font-size:0.75rem;">
    Restore is not implemented in this interface — restoring a backup is destructive and is intentionally left as a manual phpMyAdmin/command-line operation (<code>mysql -u root -p three_sisters &lt; backup_file.sql</code>) rather than a one-click web action, to avoid an accidental data-loss button in a student capstone environment.
</p>
<?php require __DIR__ . '/../components/footer.php'; ?>

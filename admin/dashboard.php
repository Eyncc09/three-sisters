<?php
declare(strict_types=1);
require_once __DIR__ . '/../auth/session.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../config/constants.php';
require_once __DIR__ . '/../config/permissions.php';
require_once __DIR__ . '/../auth/middleware.php';
requireRole(ROLE_ADMIN);

$pdo = db();
$userCount = (int) $pdo->query("SELECT COUNT(*) FROM users WHERE status = 'active'")->fetchColumn();
$lastBackup = $pdo->query("SELECT * FROM backup_logs ORDER BY created_at DESC LIMIT 1")->fetch();
$recentAudit = $pdo->query("SELECT a.*, u.full_name FROM audit_logs a LEFT JOIN users u ON u.id = a.user_id ORDER BY a.created_at DESC LIMIT 8")->fetchAll();

$pageTitle = 'Dashboard';
$activeNav = 'dashboard';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">System Overview</h1>
    <p class="text-muted mb-0 small">Administrator tools and system health.</p>
</div>

<div class="row g-3 mb-4">
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card d-flex align-items-center gap-3">
            <div class="stat-icon"><i class="bi bi-people"></i></div>
            <div><div class="stat-label">Active Users</div><div class="stat-value"><?= $userCount ?></div></div>
        </div>
    </div>
    <div class="col-6 col-lg-3">
        <div class="ts-card stat-card d-flex align-items-center gap-3">
            <div class="stat-icon"><i class="bi bi-hdd-network"></i></div>
            <div>
                <div class="stat-label">Last Backup</div>
                <div class="stat-value" style="font-size:1rem;"><?= $lastBackup ? htmlspecialchars(date('M j, g:ia', strtotime($lastBackup['created_at']))) : 'Never' ?></div>
            </div>
        </div>
    </div>
</div>

<div class="row g-3">
    <div class="col-lg-8">
        <div class="ts-card p-3">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h2 class="h6 mb-0">Recent Audit Activity</h2>
                <a href="<?= BASE_URL ?>/audit/index.php" class="small text-decoration-none">View Full Audit Trail →</a>
            </div>
            <?php if (!$recentAudit): ?>
                <div class="empty-state py-4"><i class="bi bi-journal-text"></i><p class="mb-0">No audit activity yet.</p></div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-sm align-middle mb-0">
                        <thead><tr><th>User</th><th>Action</th><th>Module</th><th>When</th></tr></thead>
                        <tbody>
                        <?php foreach ($recentAudit as $log): ?>
                            <tr>
                                <td><?= htmlspecialchars($log['full_name'] ?? 'System') ?></td>
                                <td><?= htmlspecialchars($log['action']) ?></td>
                                <td><span class="badge-status badge-neutral"><?= htmlspecialchars($log['module']) ?></span></td>
                                <td class="text-muted small"><?= htmlspecialchars(date('M j, g:ia', strtotime($log['created_at']))) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>
    <div class="col-lg-4">
        <div class="ts-card p-3">
            <h2 class="h6 mb-3">Quick Actions</h2>
            <div class="d-grid gap-2">
                <a href="<?= BASE_URL ?>/admin/users.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-person-gear me-2"></i>User Management</a>
                <a href="<?= BASE_URL ?>/admin/backup.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-hdd-network me-2"></i>Backup &amp; Restore</a>
                <a href="<?= BASE_URL ?>/settings/index.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-gear me-2"></i>Settings</a>
                <a href="<?= BASE_URL ?>/audit/index.php" class="btn btn-outline-primary btn-sm text-start"><i class="bi bi-journal-text me-2"></i>Audit Trail</a>
            </div>
        </div>
    </div>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>

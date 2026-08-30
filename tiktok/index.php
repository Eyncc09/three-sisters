<?php
require_once __DIR__ . '/../core/bootstrap.php';
requirePermission('tiktok.manage');

$connection = db()->query('SELECT * FROM tiktok_connections ORDER BY id DESC LIMIT 1')->fetch();
$syncLogs = db()->query(
    "SELECT sl.*, u.full_name AS run_by_name FROM tiktok_sync_logs sl LEFT JOIN users u ON u.id = sl.run_by ORDER BY sl.created_at DESC LIMIT 20"
)->fetchAll();
$orderCount = (int) db()->query('SELECT COUNT(*) FROM tiktok_orders')->fetchColumn();

$statusBadge = ['connected' => 'badge-success', 'not_connected' => 'badge-neutral', 'disconnected' => 'badge-neutral', 'expired' => 'badge-danger'];
$status = $connection['status'] ?? 'not_connected';

$pageTitle = 'TikTok Shop';
$activeNav = 'tiktok';
require __DIR__ . '/../components/header.php';
?>
<div class="page-header">
    <h1 class="page-title h4 mb-0">TikTok Shop</h1>
    <p class="text-muted mb-0 small">Integration status and sync history.</p>
</div>

<div class="ts-card p-4 mb-3">
    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
        <div>
            <h2 class="h6 mb-1">Connection Status</h2>
            <span class="badge-status <?= $statusBadge[$status] ?>"><?= str_replace('_', ' ', ucfirst($status)) ?></span>
        </div>
    </div>

    <?php if ($status !== 'connected'): ?>
        <div class="alert alert-info py-2 mt-3 mb-0 small">
            No live TikTok Shop API connection is configured. This is expected — official API access requires
            TikTok Shop business verification, which is outside this project's control. The system is architected
            to accept official API credentials later (<code>tiktok_connections</code> table) without any code
            changes to how orders flow through the rest of the system once connected.
        </div>
    <?php endif; ?>

    <hr>
    <h2 class="h6 mb-1">Manual Import (fallback path)</h2>
    <p class="text-muted small mb-0">
        Until live API access is available, TikTok Shop orders can be imported manually from a CSV/JSON export
        matching TikTok's order-export schema. This importer is not yet built in the interface — the database
        structure (<code>tiktok_orders</code>, <code>tiktok_order_items</code>, <code>tiktok_sync_logs</code>) is
        ready to receive it. <?= $orderCount ?> TikTok order(s) currently on file.
    </p>
</div>

<div class="ts-card">
    <div class="p-3 pb-0"><h2 class="h6 mb-0">Sync History</h2></div>
    <?php if (!$syncLogs): ?>
        <div class="empty-state"><i class="bi bi-arrow-repeat"></i><p class="mb-0">No sync attempts recorded yet.</p></div>
    <?php else: ?>
        <div class="table-responsive">
            <table class="table table-sm align-middle mb-0">
                <thead><tr><th>Type</th><th>Status</th><th>Imported</th><th>Failed</th><th>Run By</th><th>Date</th></tr></thead>
                <tbody>
                <?php foreach ($syncLogs as $log): ?>
                    <tr>
                        <td class="small"><?= htmlspecialchars(str_replace('_', ' ', ucfirst($log['sync_type']))) ?></td>
                        <td><span class="badge-status <?= $log['status'] === 'success' ? 'badge-success' : ($log['status'] === 'failed' ? 'badge-danger' : 'badge-warning') ?>"><?= ucfirst($log['status']) ?></span></td>
                        <td><?= (int) $log['records_imported'] ?></td>
                        <td><?= (int) $log['records_failed'] ?></td>
                        <td class="small"><?= htmlspecialchars($log['run_by_name'] ?? '—') ?></td>
                        <td class="small text-muted"><?= htmlspecialchars(date('M j, Y g:ia', strtotime($log['created_at']))) ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/../components/footer.php'; ?>
